<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Commercial\Studies\CommercialStudiesRepository;
use SCM\Commercial\Studies\CommercialStudiesService;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicStudyAccess;
use SCM\Support\StaffTaskLink;
use SCM\Views\PublicStudyView;

/**
 * public/estudio.php, la vista del estudio de aseguradora que reemplaza /estudio-aseguradora/ de WordPress:
 * - ?e=<enlace firmado>: el cliente, sin sesión (PublicStudyAccess), con límite por IP. Sin datos internos.
 * - ?d=<enlace firmado de documentos>: los documentos de la aprobación, para el caso de entrega (anexos_entrega).
 * - ?id=<estudio>: el funcionario con la sesión del panel; ve también llaves, caso de entrega, contrato y Drive si puede
 *   abrir el estudio (el suyo, el de una tarea que puede abrir o cualquiera si ve todo el equipo en Estudios).
 * El panel la muestra en un popup, por eso permite que la enmarque el mismo sitio.
 */
final class PublicStudyPageController
{
  private const INVALID = 'Este enlace no es válido o venció. Pide a tu consultor/a de SKC SuCasa Inmobiliaria un enlace nuevo.';

  private Database $db;
  private Settings $settings;
  private CommercialStudiesRepository $repository;
  private FileRateLimiter $limiter;
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, FileRateLimiter $limiter, array $config = [])
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->repository = new CommercialStudiesRepository($db);
    $this->limiter = $limiter;
    $this->config = $config;
  }

  /** @param array<string,mixed> $query */
  public function handle(array $query, string $method, string $ip): void
  {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
      $this->page(405, null, 'Método no permitido.');
      return;
    }
    if (!$this->limiter->consume('public-study:' . $ip, 120, 60)) {
      header('Retry-After: 60');
      $this->page(429, null, 'Espera un momento y vuelve a abrir el enlace.');
      return;
    }
    if (isset($query['id'])) {
      $this->staff((int) $query['id']);
      return;
    }
    if (isset($query['d'])) {
      // Documentos de la aprobación para el equipo de entregas (anexos_entrega del caso de entrega).
      $id = PublicStudyAccess::verifyDocuments(is_string($query['d']) ? $query['d'] : '');
      $documents = $id > 0 ? $this->service(new CommercialAccessPolicy($this->settings, $this->db, []))->documentsData($id) : null;
      $show = is_string($query['ver'] ?? null) ? $query['ver'] : '';
      $this->send($documents === null ? 403 : 200, PublicStudyView::documents($documents, $this->css(), $this->logo(), self::INVALID, $show, $this->asset('js/public-study-docs.js')));
      return;
    }
    $id = PublicStudyAccess::verify(is_string($query['e'] ?? null) ? $query['e'] : '');
    $study = $id > 0 ? $this->service(new CommercialAccessPolicy($this->settings, $this->db, []))->viewData($id) : null;
    $this->page($study === null ? 403 : 200, $study, $study === null ? self::INVALID : '');
  }

  private function staff(int $id): void
  {
    if (!Auth::isLoggedIn()) {
      header('Location: ' . StaffTaskLink::loginUrl(['tab' => CommercialStudiesService::VIEW]), true, 303);
      return;
    }
    $adminCargos = is_array($this->config['dashboard_admin_cargos'] ?? null) ? $this->config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $policy = new CommercialAccessPolicy($this->settings, $this->db, $adminCargos);
    $study = $this->repository->find($id);
    if ($study === null || !$this->canOpen($policy, $study)) {
      $this->page(403, null, $study === null ? 'El estudio no existe.' : 'No tienes permiso para ver este estudio.');
      return;
    }
    $this->page(200, $this->service($policy)->viewData($id, true), '');
  }

  /** @param array<string,mixed> $study */
  private function canOpen(CommercialAccessPolicy $policy, array $study): bool
  {
    $own = trim((string) ($study['id_empleado'] ?? '')) !== '' && trim((string) $study['id_empleado']) === trim(Auth::employeeId());
    if ($policy->canView(CommercialStudiesService::VIEW) && ($own || $policy->canSeeAll(CommercialStudiesService::VIEW))) {
      return true;
    }
    $task = (int) ($study['id_ticket'] ?? 0);
    if ($task <= 0 || !$policy->canAct('ver_ticket')) {
      return false;
    }
    if ($policy->canSeeAllTasks()) {
      return true;
    }
    $tickets = new CommercialTicketsRepository($this->db);
    $default = is_array($this->config['commercial_employee_cargos'] ?? null) ? $this->config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $raw = $this->settings->get('commercial_employee_cargos', null);
    $cargos = array_values(array_filter(array_map(static fn($id): string => trim((string) $id), is_array($raw) && $raw !== [] ? $raw : $default)));
    return $tickets->ticketMatchesEmployeeFilter($task, $tickets->currentEmployeeTicketFilter($cargos));
  }

  private function service(CommercialAccessPolicy $policy): CommercialStudiesService
  {
    return new CommercialStudiesService($this->repository, $this->settings, $policy);
  }

  /** @param array<string,mixed>|null $study */
  private function page(int $status, ?array $study, string $error): void
  {
    $this->send($status, PublicStudyView::render($study, $this->css(), $this->logo(), $error));
  }

  private function send(int $status, string $html): void
  {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' https:; frame-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    echo $html;
  }

  private function asset(string $path): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/assets/' . $path . '?v=' . substr((string) @hash_file('sha256', SCM_PUBLIC_PATH . '/assets/' . $path), 0, 12);
  }

  private function css(): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-study.css?v=' . substr((string) @hash_file('sha256', SCM_PUBLIC_PATH . '/assets/css/public-study.css'), 0, 12);
  }

  private function logo(): string
  {
    return function_exists('system_image') ? system_image('portal_logo_url') : '';
  }
}
