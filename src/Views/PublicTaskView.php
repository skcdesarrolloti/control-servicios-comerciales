<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\PublicTaskConversation;

/** Vista pública de una solicitud: el cliente ve su conversación y escribe. No muestra datos internos de la tarea. */
final class PublicTaskView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * @param array<string,mixed>|null $task
   * @param list<array{autor:string,nombre:string,mensaje:string,created_at:string}> $messages
   */
  public static function render(?array $task, array $messages, string $action, string $css, string $logo, string $error = '', bool $sent = false, string $draft = ''): string
  {
    $title = $task !== null ? 'Solicitud #' . (int) $task['_ID'] : 'Solicitud no disponible';
    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . self::e($title) . ' · SKC SuCasa Inmobiliaria</title><link rel="stylesheet" href="' . self::e($css) . '"></head><body><main class="ptk"><article class="ptk-card">'
      . '<header class="ptk-head"><img src="' . self::e($logo) . '" alt="SKC SuCasa Inmobiliaria" class="ptk-logo"><div><p class="ptk-brand">SKC SuCasa Inmobiliaria</p><h1>' . self::e($title) . '</h1></div></header>';
    if ($task === null) {
      return $html . '<div class="ptk-body"><p class="ptk-alert" role="alert">' . self::e($error) . '</p></div></article></main></body></html>';
    }
    $details = ['Motivo' => $task['tema_ayuda'] ?? '', 'Asunto' => $task['asunto'] ?? '', 'Te atiende' => trim((string) ($task['nombre_empleado'] ?? '')) . (trim((string) ($task['celular_empleado'] ?? '')) !== '' ? ' · ' . trim((string) $task['celular_empleado']) : '')];
    $html .= '<div class="ptk-body"><p class="ptk-hello">Hola, ' . self::e($task['solicitante'] ?? '') . '. Aquí puedes ver tu solicitud y escribirnos.</p><dl class="ptk-details">';
    foreach ($details as $label => $value) {
      if (trim((string) $value) !== '') {
        $html .= '<div><dt>' . $label . '</dt><dd>' . self::e($value) . '</dd></div>';
      }
    }
    $html .= '</dl>';
    if ($sent) {
      $html .= '<p class="ptk-ok" role="status">Recibimos tu mensaje. Tu asesor ya fue avisado.</p>';
    }
    if ($error !== '') {
      $html .= '<p class="ptk-alert" role="alert">' . self::e($error) . '</p>';
    }
    $html .= '<section aria-label="Conversación"><h2>Conversación</h2>';
    if ($messages === []) {
      $html .= '<p class="ptk-empty">Todavía no hay mensajes. Puedes escribirnos abajo.</p>';
    } else {
      $html .= '<ol class="ptk-thread">';
      foreach ($messages as $message) {
        $client = $message['autor'] === PublicTaskConversation::CLIENT_MARK;
        $html .= '<li class="' . ($client ? 'is-client' : 'is-staff') . '"><p class="ptk-author">' . ($client ? 'Tú' : self::e($message['nombre'])) . ' · ' . self::e(substr($message['created_at'], 0, 16)) . '</p><p class="ptk-text">' . self::e($message['mensaje']) . '</p></li>';
      }
      $html .= '</ol>';
    }
    $html .= '</section>';
    if (PublicTaskConversation::isClosed($task)) {
      $html .= '<p class="ptk-empty">Esta solicitud ya terminó. Si necesitas algo más, comunícate con nosotros.</p>';
    } else {
      $html .= '<form method="post" action="' . self::e($action) . '" class="ptk-form"><label for="ptk-message">Escribe tu mensaje</label><textarea id="ptk-message" name="mensaje" rows="4" maxlength="' . PublicTaskConversation::MAX_LENGTH . '" required>' . self::e($draft) . '</textarea><button type="submit">Enviar mensaje</button></form>';
    }
    return $html . '</div></article></main></body></html>';
  }
}
