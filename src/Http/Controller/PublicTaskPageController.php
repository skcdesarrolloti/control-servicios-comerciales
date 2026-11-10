<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Calls\PublicTaskConversation;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicTaskAccess;
use SCM\Views\PublicTaskView;

/** public/tarea.php: sin sesión. El permiso es el enlace firmado y hay límites por IP y por tarea. */
final class PublicTaskPageController
{
  private const INVALID = 'Este enlace no es válido o venció. Pide a tu asesor de SKC SuCasa Inmobiliaria un enlace nuevo.';

  private PublicTaskConversation $conversation;
  private FileRateLimiter $limiter;
  private string $self;

  public function __construct(Database $db, Settings $settings, FileRateLimiter $limiter, string $self = '')
  {
    $this->conversation = new PublicTaskConversation($db, new CommercialCallsNotifier($db, $settings));
    $this->limiter = $limiter;
    $this->self = $self !== '' ? $self : rtrim((string) SCM_BASE_URL, '/') . '/tarea.php';
  }

  /**
   * @param array<string,mixed> $query
   * @param array<string,mixed> $post
   */
  public function handle(array $query, array $post, string $method, string $ip): void
  {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' https:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

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
      $message = is_string($post['mensaje'] ?? null) ? $post['mensaje'] : '';
      if (!$this->limiter->consume('public-task-reply-ip:' . $ip, 10, 600) || !$this->limiter->consume('public-task-reply:' . $id, 20, 3600)) {
        $this->page(429, $task, 'Has enviado varios mensajes seguidos. Espera unos minutos e intenta de nuevo.', false, $action, $message);
        return;
      }
      try {
        $this->conversation->clientReply($id, $message);
      } catch (\InvalidArgumentException $exception) {
        $this->page(422, $task, $exception->getMessage(), false, $action, $message);
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
    $messages = $task !== null ? $this->conversation->messages((int) $task['_ID']) : [];
    echo PublicTaskView::render($task, $messages, $action, $base . '/assets/css/public-task.css', system_image('portal_logo_url'), $error, $sent, $draft);
  }
}
