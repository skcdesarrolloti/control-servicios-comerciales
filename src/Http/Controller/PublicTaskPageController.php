<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Calls\PublicTaskConversation;
use SCM\Commercial\Calls\PublicTaskData;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicTaskAccess;
use SCM\Views\PublicTaskView;

/** public/tarea.php: sin sesión. El permiso es el enlace firmado y hay límites por IP y por tarea. */
final class PublicTaskPageController
{
  private const INVALID = 'Este enlace no es válido o venció. Pide a tu consultor/a de SKC SuCasa Inmobiliaria un enlace nuevo.';

  private Database $db;
  private PublicTaskConversation $conversation;
  private FileRateLimiter $limiter;
  private string $self;

  public function __construct(Database $db, Settings $settings, FileRateLimiter $limiter, string $self = '')
  {
    $this->db = $db;
    $this->conversation = new PublicTaskConversation($db, new CommercialCallsNotifier($db, $settings));
    $this->limiter = $limiter;
    $this->self = $self !== '' ? $self : rtrim((string) SCM_BASE_URL, '/') . '/tarea.php';
  }

  /**
   * @param array<string,mixed> $query
   * @param array<string,mixed> $post
   * @param array<string,mixed> $files $_FILES (imagenes[] y archivos[])
   */
  public function handle(array $query, array $post, string $method, string $ip, array $files = [], int $contentLength = 0): void
  {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' https: blob:; script-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

    $reference = is_string($query['t'] ?? null) ? $query['t'] : '';
    $id = PublicTaskAccess::verify($reference);
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
      $this->page(405, null, 'Método no permitido.');
      return;
    }
    if (!$this->limiter->consume('public-task:' . $ip, 90, 60)) {
      header('Retry-After: 60');
      $this->page(429, null, 'Espera un momento y vuelve a abrir el enlace.');
      return;
    }
    $task = $id > 0 ? $this->conversation->task($id) : null;
    if ($task === null) {
      $this->page(403, null, self::INVALID);
      return;
    }
    $action = $this->self . '?t=' . rawurlencode($reference);
    if ($method === 'POST') {
      $message = is_string($post['mensaje'] ?? null) ? mb_substr($post['mensaje'], 0, PublicTaskConversation::MAX_LENGTH + 1) : '';
      // Si el envío supera post_max_size, PHP llega sin $_POST ni $_FILES: se avisa en vez de pedir el mensaje.
      if ($contentLength > PublicTaskConversation::MAX_TOTAL_BYTES + 1048576 || ($contentLength > 0 && $post === [] && $files === [])) {
        $this->page(413, $task, 'Los adjuntos superan el tamaño permitido (máximo 20 MB en total).', false, $action, $message);
        return;
      }
      if (!$this->limiter->consume('public-task-reply-ip:' . $ip, 10, 600) || !$this->limiter->consume('public-task-reply:' . $id, 20, 3600)) {
        $this->page(429, $task, 'Has enviado varios mensajes seguidos. Espera unos minutos e intenta de nuevo.', false, $action, $message);
        return;
      }
      try {
        $this->conversation->clientReply($id, $message, $files);
      } catch (\InvalidArgumentException $exception) {
        $this->page(422, $task, $exception->getMessage(), false, $action, $message);
        return;
      } catch (\RuntimeException $exception) {
        error_log('[tarea-publica] No se guardó la respuesta de la tarea #' . $id . ': ' . $exception->getMessage());
        $this->page(500, $task, 'No se pudo enviar tu mensaje. Intenta nuevamente más tarde.', false, $action, $message);
        return;
      }
      header('Location: ' . $action . '&enviado=1', true, 303);
      return;
    }
    $this->page(200, $task, '', ($query['enviado'] ?? '') === '1', $action);
  }

  /** @param array<string,mixed>|null $task */
  private function page(int $status, ?array $task, string $error, bool $sent = false, string $action = '', string $draft = ''): void
  {
    http_response_code($status);
    $base = rtrim((string) SCM_BASE_URL, '/');
    $version = defined('SCM_VERSION') ? '?v=' . rawurlencode((string) SCM_VERSION) : '';
    $history = [];
    if ($task !== null) {
      try {
        $history = PublicTaskData::history($this->db, $task);
      } catch (\Throwable $exception) {
        error_log('[tarea-publica] No se pudo cargar el historial de la tarea #' . (int) $task['_ID']);
      }
    }
    echo PublicTaskView::render($task !== null ? PublicTaskData::fromTicket($task) : null, $history, $action, [
      'css' => $base . '/assets/css/public-task.css' . $version,
      'js' => $base . '/assets/js/public-task.js' . $version,
      'loader_css' => $base . '/assets/css/scm-loader.css' . $version,
      'loader_js' => $base . '/assets/js/scm-loader.js' . $version,
      'logo' => system_image('portal_logo_url'),
      'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
    ], $error, $sent, $draft, $task !== null && !PublicTaskConversation::isClosed($task));
  }
}
