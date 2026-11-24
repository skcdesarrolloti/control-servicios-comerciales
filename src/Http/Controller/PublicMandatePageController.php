<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\CommercialMandatesNotifier;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicMandateAccess;
use SCM\Support\StaffTaskLink;
use SCM\Views\PublicMandateView;

/**
 * public/mandato.php, la revisión del contrato de mandato antes de la firma:
 * - ?m=<enlace firmado>: el propietario, sin sesión (PublicMandateAccess), con límites por IP y por mandato. Puede dejar
 *   observaciones por sección y aprobar el contrato.
 * - ?id=<mandato>: el equipo con la sesión del panel, si puede abrir el mandato (el suyo o todo el equipo en Mandatos).
 *   Envía el enlace al propietario y responde las observaciones (con token CSRF). El panel lo muestra en un popup.
 */
final class PublicMandatePageController
{
  private const INVALID = 'Este enlace no es válido o venció. Pida a su consultor/a de SKC SuCasa Inmobiliaria un enlace nuevo.';
  private const CSRF = 'mandate_staff';

  private Database $db;
  private Settings $settings;
  private FileRateLimiter $limiter;
  private Csrf $csrf;
  /** @var array<string,mixed> */
  private array $config;
  private ?CommercialMandatesNotifier $notifier;
  private string $self;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, FileRateLimiter $limiter, Csrf $csrf, array $config = [], ?CommercialMandatesNotifier $notifier = null, string $self = '')
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->limiter = $limiter;
    $this->csrf = $csrf;
    $this->config = $config;
    $this->notifier = $notifier ?? new CommercialMandatesNotifier($db, $settings);
    $this->self = $self !== '' ? $self : rtrim((string) SCM_BASE_URL, '/') . '/mandato.php';
  }

  /**
   * @param array<string,mixed> $query
   * @param array<string,mixed> $post
   */
  public function handle(array $query, array $post, string $method, string $ip): void
  {
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
      $this->page(405, null, [], 'Método no permitido.');
      return;
    }
    if (!$this->limiter->consume('public-mandate:' . $ip, 120, 60)) {
      header('Retry-After: 60');
      $this->page(429, null, [], 'Espere un momento y vuelva a abrir el enlace.');
      return;
    }
    if (isset($query['id'])) {
      $this->staff((int) $query['id'], $query, $post, $method);
      return;
    }
    $reference = is_string($query['m'] ?? null) ? $query['m'] : '';
    $id = PublicMandateAccess::verify($reference);
    $service = $this->service(new CommercialAccessPolicy($this->settings, $this->db, []));
    if ($id <= 0 || $service->repository()->find($id) === null) {
      $this->page(403, null, [], self::INVALID);
      return;
    }
    $action = $this->self . '?m=' . rawurlencode($reference);
    if ($method === 'POST') {
      if (!$this->limiter->consume('public-mandate-post-ip:' . $ip, 15, 600) || !$this->limiter->consume('public-mandate-post:' . $id, 30, 3600)) {
        $this->page(429, $service->viewData($id), ['action' => $action], 'Envió varias observaciones seguidas. Espere unos minutos e intente de nuevo.');
        return;
      }
      $draft = ['seccion' => self::str($post, 'seccion', 30), 'nombre' => self::str($post, 'nombre', 190), 'mensaje' => self::str($post, 'mensaje', CommercialMandatesService::MAX_NOTE + 1)];
      try {
        if (self::str($post, 'accion', 20) === 'aprobar') {
          $service->ownerApprove($id, $draft['nombre'], self::str($post, 'confirmo', 2) === '1');
          $done = 'aprobado';
        } else {
          $service->ownerNote($id, $draft['seccion'], $draft['nombre'], $draft['mensaje']);
          $done = 'observacion';
        }
      } catch (\InvalidArgumentException $exception) {
        $this->page(422, $service->viewData($id), ['action' => $action, 'draft' => $draft], $exception->getMessage());
        return;
      }
      header('Location: ' . $action . '&ok=' . $done . '#' . ($done === 'aprobado' ? 'aprobar' : 'seccion-' . rawurlencode($draft['seccion'] ?: 'general')), true, 303);
      return;
    }
    $service->ownerSeen($id);
    $this->page(200, $service->viewData($id), ['action' => $action, 'flash' => self::flash($query)], '');
  }

  /**
   * @param array<string,mixed> $query
   * @param array<string,mixed> $post
   */
  private function staff(int $id, array $query, array $post, string $method): void
  {
    if (!Auth::isLoggedIn()) {
      header('Location: ' . StaffTaskLink::loginUrl(['tab' => CommercialMandatesService::VIEW, 'abrir_mandato' => (string) $id]), true, 303);
      return;
    }
    $adminCargos = is_array($this->config['dashboard_admin_cargos'] ?? null) ? $this->config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $service = $this->service(new CommercialAccessPolicy($this->settings, $this->db, $adminCargos));
    $mandate = $service->repository()->find($id);
    if ($mandate === null || !$service->canOpen($mandate)) {
      $this->page(403, null, [], $mandate === null ? 'El mandato no existe.' : 'No tienes permiso para ver este mandato.');
      return;
    }
    $action = $this->self . '?id=' . $id . (($query['embed'] ?? '') === '1' ? '&embed=1' : '');
    if ($method === 'POST') {
      if (!$this->csrf->verify(self::CSRF, self::str($post, '_csrf_token', 200), false)) {
        $this->page(403, $service->viewData($id, true), ['action' => $action], 'La página venció. Vuelve a cargarla e intenta de nuevo.');
        return;
      }
      try {
        if (self::str($post, 'accion', 20) === 'enviar') {
          $result = $service->sendToOwners($id);
          $done = 'enviado';
          $_SESSION['scm_mandate_flash'] = $result['message'];
        } else {
          $service->answerNote((int) ($post['nota'] ?? 0), self::str($post, 'respuesta', CommercialMandatesService::MAX_NOTE + 1), self::str($post, 'resolver', 2) === '1');
          $done = 'respuesta';
        }
      } catch (\InvalidArgumentException | \RuntimeException $exception) {
        $this->page(422, $service->viewData($id, true), ['action' => $action], $exception->getMessage());
        return;
      }
      header('Location: ' . $action . '&ok=' . $done, true, 303);
      return;
    }
    $flash = self::flash($query);
    if (($query['ok'] ?? '') === 'enviado' && is_string($_SESSION['scm_mandate_flash'] ?? null)) {
      $flash = $_SESSION['scm_mandate_flash'];
      unset($_SESSION['scm_mandate_flash']);
    }
    $this->page(200, $service->viewData($id, true), ['action' => $action, 'flash' => $flash], '');
  }

  private function service(CommercialAccessPolicy $policy): CommercialMandatesService
  {
    return new CommercialMandatesService($this->db, $policy, $this->notifier);
  }

  /**
   * @param array<string,mixed>|null $data
   * @param array<string,mixed> $page
   */
  private function page(int $status, ?array $data, array $page, string $error): void
  {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' https:; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
    $staff = is_array($data['interno'] ?? null);
    echo PublicMandateView::render($data, [
      'css' => [$this->asset('css/public-study.css'), $this->asset('css/public-mandate.css')],
      'js' => $staff ? $this->asset('js/public-mandate.js') : '',
      'logo' => function_exists('system_image') ? system_image('portal_logo_url') : '',
    ], [
      'action' => (string) ($page['action'] ?? ''),
      'csrf' => $staff ? '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">' : '',
      'flash' => (string) ($page['flash'] ?? ''),
      'error' => $error,
      'draft' => is_array($page['draft'] ?? null) ? $page['draft'] : [],
    ]);
  }

  /** Un token por sesión para todos los formularios de la página (no se gasta al usarlo). */
  private function token(): string
  {
    $current = $_SESSION['scm_csrf'][self::CSRF] ?? null;
    return is_string($current) && $current !== '' ? $current : $this->csrf->token(self::CSRF);
  }

  private function asset(string $path): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/assets/' . $path . '?v=' . substr((string) @hash_file('sha256', SCM_PUBLIC_PATH . '/assets/' . $path), 0, 12);
  }

  /** @param array<string,mixed> $query */
  private static function flash(array $query): string
  {
    return PublicMandateView::FLASH[is_string($query['ok'] ?? null) ? $query['ok'] : ''] ?? '';
  }

  /** @param array<string,mixed> $source */
  private static function str(array $source, string $key, int $max): string
  {
    return is_string($source[$key] ?? null) ? mb_substr(trim($source[$key]), 0, $max) : '';
  }
}
