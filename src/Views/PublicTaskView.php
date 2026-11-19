<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\PublicTaskConversation;

/**
 * Vista pública de una solicitud comercial (public/tarea.php): datos de la solicitud, su historial con adjuntos y el
 * formulario para responder con fotos y PDF. Copia adaptada de control-servicios-inmobiliarios/src/Views/PublicCaseView.php
 * (estilos en public-task.css y comportamiento en public-task.js, copias de public-case.css/.js): mantener ambas iguales.
 * Solo recibe los datos de PublicTaskData, nunca la fila completa de la tarea.
 */
final class PublicTaskView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * @param array{id:int,asunto:string,motivo:string,estado:string,inmueble:string,fecha:string,solicitante:string,consultor:string,celular_consultor:string,correo_consultor:string}|null $task
   * @param list<array{author:string,date:string,text:string,external:bool,images:list<string>,documents:list<array{url:string,name:string}>}> $history
   * @param array{css?:string,js?:string,loader_css?:string,loader_js?:string,logo?:string,favicon?:string} $assets
   */
  public static function render(?array $task, array $history, string $action, array $assets, string $error = '', bool $sent = false, string $draft = '', bool $canReply = true): string
  {
    $e = static fn($value): string => self::e($value);
    $title = $task !== null ? 'Solicitud #' . $task['id'] : 'Solicitud no disponible';
    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . $e($title) . ' · SKC SuCasa Inmobiliaria</title>'
      . (($assets['favicon'] ?? '') !== '' ? '<link rel="icon" href="' . $e($assets['favicon']) . '" sizes="32x32">' : '')
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">';
    foreach (['loader_css', 'css'] as $key) {
      if (($assets[$key] ?? '') !== '') {
        $html .= '<link rel="stylesheet" href="' . $e($assets[$key]) . '">';
      }
    }
    foreach (['loader_js', 'js'] as $key) {
      if (($assets[$key] ?? '') !== '') {
        $html .= '<script defer src="' . $e($assets[$key]) . '"></script>';
      }
    }
    $html .= '</head><body class="ptk"><main class="ptk-main"><article class="ptk-card">'
      . '<header class="ptk-head"><div class="ptk-head-brand">' . (($assets['logo'] ?? '') !== '' ? '<img src="' . $e($assets['logo']) . '" alt="SKC SuCasa Inmobiliaria" class="ptk-logo">' : '')
      . '<div><p class="ptk-brand">SKC SuCasa Inmobiliaria</p><h1>' . $e($title) . '</h1><p class="ptk-head-sub">Información para el cliente</p></div></div>'
      . ($task !== null ? '<div class="ptk-head-actions"><button type="button" data-print class="ptk-ghost">Imprimir</button></div>' : '')
      . '</header><div class="ptk-body">';
    if ($task === null) {
      return $html . '<p role="alert" class="ptk-alert is-error">' . $e($error) . '</p></div></article></main></body></html>';
    }

    if ($task['solicitante'] !== '') {
      $html .= '<p class="ptk-hello">Hola, <b>' . $e($task['solicitante']) . '</b>. Aquí puedes ver tu solicitud, su historial y respondernos.</p>';
    }
    $html .= '<section class="ptk-summary"><div class="ptk-summary-head"><h2>' . $e($task['asunto'] !== '' ? $task['asunto'] : 'Solicitud comercial') . '</h2>'
      . ($task['estado'] !== '' ? '<span class="ptk-badge">' . $e($task['estado']) . '</span>' : '') . '</div><dl class="ptk-details">';
    $consultor = $task['consultor'] . ($task['celular_consultor'] !== '' ? ' · ' . $task['celular_consultor'] : '');
    foreach (['Motivo' => $task['motivo'], 'Inmueble' => $task['inmueble'], 'Fecha de registro' => $task['fecha'], 'Su consultor/a asignado' => $task['consultor'] !== '' ? $consultor : ''] as $label => $value) {
      if (trim((string) $value) !== '') {
        $html .= '<div><dt>' . $label . '</dt><dd>' . $e($value) . '</dd></div>';
      }
    }
    $html .= '</dl></section>';

    $html .= '<section class="ptk-history" aria-labelledby="ptk-history-title"><h2 id="ptk-history-title" class="ptk-title">Historial de la solicitud</h2>';
    if ($history === []) {
      $html .= '<p class="ptk-muted">Aún no hay respuestas registradas en esta solicitud. Puedes escribirnos abajo.</p>';
    } else {
      $html .= '<ol class="ptk-timeline">';
      foreach ($history as $entry) {
        $html .= '<li class="ptk-entry' . ($entry['external'] ? ' is-external' : '') . '"><p class="ptk-entry-head"><b>' . $e($entry['author']) . '</b>'
          . ($entry['date'] !== '' ? '<span>' . $e($entry['date']) . '</span>' : '') . '</p>';
        if ($entry['text'] !== '') {
          $html .= '<p class="ptk-entry-text">' . $e($entry['text']) . '</p>';
        }
        if ($entry['images'] !== [] || $entry['documents'] !== []) {
          $html .= '<div class="ptk-entry-files">';
          foreach ($entry['images'] as $i => $url) {
            $html .= '<button type="button" data-preview="image" data-url="' . $e($url) . '" data-title="Adjunto ' . ($i + 1) . '"><img src="' . $e($url) . '" alt="Adjunto ' . ($i + 1) . '" loading="lazy"></button>';
          }
          foreach ($entry['documents'] as $document) {
            $html .= '<button type="button" data-preview="pdf" data-url="' . $e($document['url']) . '" data-title="' . $e($document['name']) . '" class="ptk-ghost ptk-doc">' . $e($document['name']) . '</button>';
          }
          $html .= '</div>';
        }
        $html .= '</li>';
      }
      $html .= '</ol>';
    }
    $html .= '</section>';

    if ($sent) {
      $html .= '<p role="status" class="ptk-alert is-success"><b>Recibimos tu mensaje.</b> Ya le avisamos a tu consultor/a asignado.</p>';
    }
    if ($canReply) {
      $html .= '<section class="ptk-reply" aria-labelledby="ptk-reply-title"><h2 id="ptk-reply-title" class="ptk-title">Responder la solicitud</h2>'
        . '<p class="ptk-hint">Tu mensaje queda registrado en la solicitud y le avisamos de inmediato a tu consultor/a asignado.</p>'
        . ($error !== '' ? '<p role="alert" class="ptk-alert is-error">' . $e($error) . '</p>' : '')
        . '<form method="post" action="' . $e($action) . '" enctype="multipart/form-data" data-reply-form class="ptk-reply-form">'
        . '<label class="ptk-field"><span>Tu mensaje</span><textarea name="mensaje" rows="5" required maxlength="' . PublicTaskConversation::MAX_LENGTH . '" placeholder="Escribe aquí tu mensaje o la información que te solicitaron.">' . $e($draft) . '</textarea></label>'
        . '<div class="ptk-field"><span>Adjuntos (opcional)</span><div class="ptk-media" data-media-drop><div class="ptk-media-native">'
        . '<label>Fotos (máximo ' . PublicTaskConversation::MAX_IMAGES . ')<input type="file" name="imagenes[]" accept="image/jpeg,image/png,image/webp" multiple data-media-input="image" data-max-files="' . PublicTaskConversation::MAX_IMAGES . '"></label>'
        . '<label>Documentos PDF (máximo ' . PublicTaskConversation::MAX_DOCUMENTS . ')<input type="file" name="archivos[]" accept="application/pdf,.pdf" multiple data-media-input="pdf" data-max-files="' . PublicTaskConversation::MAX_DOCUMENTS . '"></label>'
        . '</div><div class="ptk-media-actions" hidden>'
        . '<button type="button" class="ptk-media-button" data-media-pick="image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg>Agregar fotos</button>'
        . '<button type="button" class="ptk-media-button" data-media-pick="pdf"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>Agregar PDF</button>'
        . '</div><p class="ptk-media-help" hidden>Arrastra los archivos aquí o pega una captura con Ctrl+V. Hasta ' . PublicTaskConversation::MAX_IMAGES . ' fotos y ' . PublicTaskConversation::MAX_DOCUMENTS . ' PDF.</p>'
        . '<ul class="ptk-media-list" data-media-list hidden></ul></div></div>'
        . '<p data-reply-feedback class="ptk-hint" aria-live="polite">Cada archivo puede pesar hasta 10 MB y en total hasta 20 MB. Las fotos se comprimen al guardarse.</p>'
        . '<div><button type="submit" data-reply-submit class="ptk-submit">Enviar mensaje</button></div></form></section>';
    } else {
      $html .= ($error !== '' ? '<p role="alert" class="ptk-alert is-error">' . $e($error) . '</p>' : '')
        . '<p class="ptk-alert">Esta solicitud ya terminó. Si necesitas algo más, comunícate con SKC SuCasa Inmobiliaria.</p>';
    }

    if ($task['consultor'] !== '') {
      $contact = array_filter([$task['celular_consultor'] !== '' ? 'Celular: ' . $task['celular_consultor'] : '', $task['correo_consultor'] !== '' ? 'Correo: ' . $task['correo_consultor'] : '']);
      $html .= '<footer class="ptk-signoff"><p class="ptk-muted-xs">Su consultor/a asignado</p><p class="ptk-signoff-name">' . $e($task['consultor']) . '</p>'
        . ($contact !== [] ? '<p class="ptk-muted-xs">' . $e(implode(' · ', $contact)) . '</p>' : '')
        . '<p class="ptk-muted-xs">SKC SuCasa Inmobiliaria</p></footer>';
    }
    return $html . '</div></article></main>'
      . '<dialog data-evidence-dialog class="ptk-preview"><div class="ptk-preview-head"><h2 data-preview-title>Adjunto</h2><button type="button" data-close-preview class="ptk-ghost">Cerrar</button></div><div data-preview-body class="ptk-preview-body"></div></dialog>'
      . '</body></html>';
  }
}
