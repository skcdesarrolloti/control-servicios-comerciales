<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\MandateDocument;

/**
 * Contrato de mandato (public/mandato.php) para revisarlo antes de la firma digital:
 * - el propietario (enlace firmado) lee el contrato por secciones, deja observaciones donde algo deba cambiar y, cuando
 *   todo está correcto, lo aprueba;
 * - el equipo (sesión del panel) ve lo mismo, más el enlace del propietario, el envío a revisión, las respuestas a las
 *   observaciones y el enlace al panel para editarlo o enviarlo a firma.
 * Estilos de public-study.css más public-mandate.css; sin JavaScript salvo el botón de copiar.
 */
final class PublicMandateView
{
  /** Mensajes después de una acción (?ok=). */
  public const FLASH = [
    'observacion' => 'Recibimos su observación. Su consultor/a la revisará y le responderá aquí.',
    'aprobado' => '¡Gracias! Aprobó el contrato. Le enviaremos la firma digital.',
    'enviado' => 'Se envió el mandato al propietario.',
    'respuesta' => 'Se guardó la respuesta.',
  ];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * @param array<string,mixed>|null $data CommercialMandatesService::viewData()
   * @param array{css:list<string>,js:string,logo:string} $assets
   * @param array{action:string,csrf:string,flash:string,error:string,draft:array<string,string>} $page
   */
  public static function render(?array $data, array $assets, array $page): string
  {
    $title = $data !== null ? 'Contrato de mandato #' . (int) $data['id'] : 'Contrato no disponible';
    $html = self::head($title, $assets);
    if ($data === null) {
      return $html . '<section class="ps-card ps-empty"><h1>' . self::e($title) . '</h1><p role="alert">' . self::e($page['error']) . '</p></section></main></body></html>';
    }
    $staff = is_array($data['interno']);
    $state = (string) $data['revision'];
    [$label, $tone] = CommercialMandatesService::REVIEW_STATES[$state] ?? ['', 'wait'];
    $names = $data['propietarios'];
    $html .= '<section class="ps-card ps-hero ps-hero--' . self::e($tone) . '"><p class="ps-eyebrow">Contrato de mandato #' . (int) $data['id'] . ' · ' . self::e($data['lugar']) . '</p>'
      . '<h1>' . ($staff ? 'Revisión del propietario' : 'Hola, ' . self::e($names !== [] ? implode(' y ', $names) : 'apreciado propietario')) . '</h1>'
      . '<p class="ps-state"><span class="ps-badge ps-badge--' . self::e($tone) . '">' . self::e($label) . '</span></p>'
      . '<p>' . self::e(self::stateText($data, $staff)) . '</p></section>';
    if ($page['flash'] !== '') {
      $html .= '<p class="pm-flash" role="status">' . self::e($page['flash']) . '</p>';
    }
    if ($page['error'] !== '') {
      $html .= '<p class="pm-error" role="alert">' . self::e($page['error']) . '</p>';
    }
    if ($staff) {
      $html .= self::internal($data, $page);
    }
    $html .= self::steps($data);

    $notes = [];
    foreach ($data['observaciones'] as $note) {
      $notes[$note['seccion']][] = $note;
    }
    $open = !$data['firmado'];
    foreach ($data['secciones'] as $section) {
      $html .= '<section class="ps-card pm-section" id="seccion-' . self::e($section['key']) . '"><h2>' . self::e($section['title']) . '</h2>' . self::sectionBody($section)
        . self::notes($notes[$section['key']] ?? [], $staff, $data, $page)
        . (!$staff && $open ? self::noteForm($section['key'], $section['title'], $names, $page) : '') . '</section>';
    }
    if (!$staff || ($notes['general'] ?? []) !== []) {
      $html .= '<section class="ps-card pm-section" id="seccion-general"><h2>Comentario general</h2>'
        . (!$staff ? '<p class="ps-hint">¿Algo que no esté en una sección o una pregunta para su consultor/a? Escríbalo aquí.</p>' : '')
        . self::notes($notes['general'] ?? [], $staff, $data, $page)
        . (!$staff && $open ? self::noteForm('general', 'Comentario general', $names, $page, true) : '') . '</section>';
    }
    if (!$staff && $open) {
      $html .= self::approval($data, $page);
    }
    $html .= self::advisor($data['consultor']);
    return $html . self::foot($assets['js']);
  }

  private static function head(string $title, array $assets): string
  {
    $css = '';
    foreach ($assets['css'] as $href) {
      $css .= '<link rel="stylesheet" href="' . self::e($href) . '">';
    }
    return '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . self::e($title) . ' · SKC SuCasa Inmobiliaria</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap">' . $css . '</head><body>'
      . '<header class="ps-top"><div class="ps-wrap ps-top-in">' . ($assets['logo'] !== '' ? '<img src="' . self::e((string) preg_replace('#^http://#i', 'https://', $assets['logo'])) . '" alt="" class="ps-logo">' : '') . '<span class="ps-brand">SKC SuCasa Inmobiliaria</span></div></header><main class="ps-wrap ps-main">';
  }

  private static function foot(string $js): string
  {
    return '</main><footer class="ps-foot"><div class="ps-wrap"><p class="ps-motto">Construimos confianza, protegemos su patrimonio.</p><p>SKC SuCasa Inmobiliaria</p></div></footer>'
      . ($js !== '' ? '<script src="' . self::e($js) . '" defer></script>' : '') . '</body></html>';
  }

  /** @param array<string,mixed> $data */
  private static function stateText(array $data, bool $staff): string
  {
    $state = (string) $data['revision'];
    if ($staff) {
      return match ($state) {
        'sin_enviar' => 'El propietario todavía no ha recibido el contrato para revisarlo. Envíaselo antes de mandarlo a firma.',
        'enviado' => 'Se le envió el contrato el ' . $data['enviado_at'] . '; aún no lo ha abierto.',
        'visto' => 'El propietario abrió el contrato pero todavía no lo ha aprobado ni ha dejado observaciones.',
        'observaciones' => 'El propietario pidió cambios: corrige el mandato, responde cada observación y márcala resuelta antes de enviarlo a firma.',
        'cambio' => 'El propietario lo aprobó (' . $data['aprobado_at'] . '), pero el mandato cambió después. Envíaselo otra vez para que revise la versión actual.',
        'aprobado' => $data['aprobado_por'] . ' aprobó el contrato el ' . $data['aprobado_at'] . '. Ya puede enviarse a firma.',
        default => 'El contrato ya fue firmado en eSignatures.',
      };
    }
    return match ($state) {
      'firmado' => 'Este contrato ya fue firmado. Si necesita algún cambio, comuníquese con su consultor/a.',
      'aprobado' => 'Usted aprobó este contrato el ' . $data['aprobado_at'] . '. Le enviaremos la firma digital a su correo y celular.',
      'cambio' => 'Su consultor/a actualizó el contrato después de su aprobación. Revíselo de nuevo y, si todo está correcto, apruébelo otra vez.',
      'observaciones' => 'Recibimos sus observaciones. Su consultor/a hará los ajustes y le responderá en cada sección.',
      default => 'Antes de enviarle el contrato para la firma digital, revise sus datos. Si algo debe cambiar, déjenos su observación en la sección que corresponda. Cuando todo esté correcto, apruébelo al final de la página.',
    };
  }

  /** Avance: revisión → observaciones → aprobado → firma digital. @param array<string,mixed> $data */
  private static function steps(array $data): string
  {
    $state = (string) $data['revision'];
    $pending = count(array_filter($data['observaciones'], static fn(array $note): bool => $note['estado'] === 'pendiente'));
    $approved = in_array($state, ['aprobado', 'firmado'], true);
    $steps = [
      ['Revisión del contrato', $data['enviado_at'] !== '' ? 'Enviado el ' . $data['enviado_at'] : '', $state === 'sin_enviar' ? 'todo' : 'done'],
      ['Observaciones', $pending > 0 ? $pending . ' pendiente(s)' : ($data['observaciones'] !== [] ? 'Resueltas' : 'Ninguna'), $pending > 0 ? 'current' : ($state === 'sin_enviar' ? 'todo' : 'done')],
      ['Aprobación del propietario', $approved && $data['aprobado_por'] !== '' ? $data['aprobado_por'] : '', $approved ? 'done' : ($pending > 0 || $state === 'sin_enviar' ? 'todo' : 'current')],
      ['Firma digital', $data['firmado'] ? 'Firmado' : ($data['estado'] === 'Enviado' ? 'Enviado a firma' : ''), $data['firmado'] ? 'done' : ($state === 'aprobado' ? 'current' : 'todo')],
    ];
    $html = '<ol class="ps-steps" aria-label="Avance del contrato">';
    foreach ($steps as [$label, $detail, $status]) {
      $html .= '<li class="ps-step ps-step--' . $status . '"' . ($status === 'current' ? ' aria-current="step"' : '') . '><span class="ps-dot" aria-hidden="true"></span><span><strong>' . self::e($label) . '</strong>' . ($detail !== '' ? '<small>' . self::e($detail) . '</small>' : '') . '</span></li>';
    }
    return $html . '</ol>';
  }

  /** @param array<string,mixed> $section */
  private static function sectionBody(array $section): string
  {
    $html = '';
    foreach ($section['owners'] ?? [] as $owner) {
      $html .= '<div class="pm-owner"><h3>' . self::e($owner['title']) . '</h3>' . self::facts($owner['items']) . '</div>';
    }
    if ($section['items'] !== []) {
      $html .= self::facts($section['items']);
    } elseif (($section['owners'] ?? []) === [] && !isset($section['table']) && !isset($section['text'])) {
      $html .= '<p class="ps-hint">No aplica para este contrato.</p>';
    }
    if (isset($section['table'])) {
      $html .= '<div class="pm-table-wrap"><table class="pm-table"><thead><tr>';
      foreach ($section['table']['head'] as $cell) {
        $html .= '<th scope="col">' . self::e($cell) . '</th>';
      }
      $html .= '</tr></thead><tbody>';
      foreach ($section['table']['rows'] as $row) {
        $html .= '<tr>' . implode('', array_map(static fn(string $cell): string => '<td>' . self::e($cell) . '</td>', $row)) . '</tr>';
      }
      $html .= '</tbody></table></div>';
    }
    foreach ($section['text'] ?? [] as [$label, $text]) {
      $html .= '<h3>' . self::e($label) . '</h3><p class="ps-pre">' . self::e($text) . '</p>';
    }
    return $html;
  }

  /** @param list<array{0:string,1:string}> $items */
  private static function facts(array $items): string
  {
    $html = '<dl class="ps-facts">';
    foreach ($items as [$label, $value]) {
      $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
    }
    return $html . '</dl>';
  }

  /**
   * Observaciones de una sección con la respuesta del equipo; el equipo puede responder y marcarlas resueltas.
   * @param list<array<string,mixed>> $notes
   * @param array<string,mixed> $data
   * @param array<string,mixed> $page
   */
  private static function notes(array $notes, bool $staff, array $data, array $page): string
  {
    if ($notes === []) {
      return '';
    }
    $html = '<div class="pm-notes"><h3>Observaciones</h3><ul>';
    foreach ($notes as $note) {
      $resolved = $note['estado'] === 'resuelta';
      $html .= '<li class="pm-note' . ($resolved ? ' pm-note--done' : '') . '"><p class="pm-note-head"><strong>' . self::e($note['nombre']) . '</strong> · ' . self::e($note['fecha'])
        . ' <span class="ps-badge ps-badge--' . ($resolved ? 'ok' : 'warn') . '">' . ($resolved ? 'Resuelta' : 'Pendiente') . '</span>'
        . ($note['anterior'] ? ' <span class="pm-old">sobre una versión anterior</span>' : '') . '</p>'
        . '<p class="ps-pre">' . self::e($note['mensaje']) . '</p>';
      if ($note['respuesta'] !== '') {
        $html .= '<div class="pm-answer"><p class="pm-note-head"><strong>' . self::e($note['respondido_por'] ?: 'Su consultor/a') . '</strong>' . ($note['respondido_at'] !== '' ? ' · ' . self::e($note['respondido_at']) : '') . '</p><p class="ps-pre">' . self::e($note['respuesta']) . '</p></div>';
      }
      if ($staff && !empty($data['interno']['puede_responder']) && !$resolved) {
        $html .= '<form method="post" action="' . self::e($page['action']) . '" class="pm-form pm-reply">' . $page['csrf']
          . '<input type="hidden" name="accion" value="responder"><input type="hidden" name="nota" value="' . (int) $note['id'] . '">'
          . '<label class="pm-field"><span>Respuesta para el propietario</span><textarea name="respuesta" rows="2" maxlength="' . CommercialMandatesService::MAX_NOTE . '" placeholder="Qué se corrigió o por qué se deja igual"></textarea></label>'
          . '<label class="pm-check"><input type="checkbox" name="resolver" value="1" checked> Marcar como resuelta</label>'
          . '<button type="submit" class="ps-button ps-button--light">Guardar respuesta</button></form>';
      }
      $html .= '</li>';
    }
    return $html . '</ul></div>';
  }

  /**
   * Formulario de observación de una sección (abre con un clic, sin JavaScript).
   * @param list<string> $names
   * @param array<string,mixed> $page
   */
  private static function noteForm(string $key, string $title, array $names, array $page, bool $open = false): string
  {
    $draft = ($page['draft']['seccion'] ?? '') === $key ? $page['draft'] : [];
    return '<details class="pm-ask"' . ($open || $draft !== [] ? ' open' : '') . '><summary>' . ($key === 'general' ? 'Escribir un comentario' : '¿Algo por cambiar en ' . self::e(mb_strtolower($title, 'UTF-8')) . '?') . '</summary>'
      . '<form method="post" action="' . self::e($page['action']) . '" class="pm-form"><input type="hidden" name="accion" value="observacion"><input type="hidden" name="seccion" value="' . self::e($key) . '">'
      . self::who($names, (string) ($draft['nombre'] ?? ''))
      . '<label class="pm-field"><span>' . ($key === 'general' ? 'Su comentario' : 'Qué se debe cambiar') . '</span><textarea name="mensaje" rows="3" maxlength="' . CommercialMandatesService::MAX_NOTE . '" required placeholder="Ej.: el número de cuenta correcto es…">' . self::e($draft['mensaje'] ?? '') . '</textarea></label>'
      . '<button type="submit" class="ps-button">Enviar observación</button></form></details>';
  }

  /** @param list<string> $names */
  private static function who(array $names, string $current): string
  {
    if (count($names) <= 1) {
      return '';
    }
    $html = '<label class="pm-field"><span>¿Quién escribe?</span><select name="nombre" required><option value="">Elija su nombre</option>';
    foreach ($names as $name) {
      $html .= '<option value="' . self::e($name) . '"' . ($name === $current ? ' selected' : '') . '>' . self::e($name) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param array<string,mixed> $data @param array<string,mixed> $page */
  private static function approval(array $data, array $page): string
  {
    $pending = array_filter($data['observaciones'], static fn(array $note): bool => $note['estado'] === 'pendiente');
    $html = '<section class="ps-card pm-approve" id="aprobar"><h2>Aprobar el contrato</h2>';
    if ($data['revision'] === 'aprobado') {
      return $html . '<p>Usted aprobó este contrato el <strong>' . self::e($data['aprobado_at']) . '</strong>. No tiene que hacer nada más: le enviaremos la firma digital.</p></section>';
    }
    if ($pending !== []) {
      return $html . '<p>Tiene <strong>' . count($pending) . '</strong> observación(es) pendiente(s). Cuando su consultor/a las resuelva, podrá aprobar el contrato aquí.</p></section>';
    }
    return $html . '<p>Si revisó todas las secciones y los datos están correctos, apruebe el contrato. Después le llegará la firma digital a su correo y celular.</p>'
      . '<form method="post" action="' . self::e($page['action']) . '" class="pm-form"><input type="hidden" name="accion" value="aprobar">'
      . self::who($data['propietarios'], '')
      . '<label class="pm-check"><input type="checkbox" name="confirmo" value="1" required> Revisé los datos del contrato y están correctos.</label>'
      . '<button type="submit" class="ps-button">Aprobar el contrato</button></form></section>';
  }

  /** Datos y acciones del equipo. @param array<string,mixed> $data @param array<string,mixed> $page */
  private static function internal(array $data, array $page): string
  {
    $internal = $data['interno'];
    $blockers = $data['firmado'] ? [] : (array) ($data['bloqueos'] ?? []);
    $notice = $data['firmado'] ? '' : (string) ($data['aviso_revision'] ?? '');
    $warning = ($blockers !== []
      ? '<div class="pm-warn"><p>Todavía no se puede enviar a firma:</p><ul>' . implode('', array_map(static fn($reason): string => '<li>' . self::e($reason) . '</li>', $blockers)) . '</ul></div>'
      : '') . ($notice !== '' ? '<p class="pm-review-note">' . self::e($notice) . '</p>' : '');
    $closing = (array) ($data['hoja'] ?? []);
    $closingState = (string) ($closing['estado'] ?? '');
    $html = '<section class="ps-card ps-internal"><h2>Datos internos <small>solo los ve el equipo</small></h2><dl class="ps-facts">'
      . '<div><dt>Estado del mandato</dt><dd>' . self::e($data['estado']) . '</dd></div>'
      . '<div><dt>Envíos al propietario</dt><dd>' . (int) $internal['envios'] . ($internal['enviado_por'] !== '' ? ' · último por ' . self::e($internal['enviado_por']) : '') . '</dd></div>'
      . ($internal['visto_at'] !== '' ? '<div><dt>Lo abrió por primera vez</dt><dd>' . self::e($internal['visto_at']) . '</dd></div>' : '')
      . '<div><dt>Hoja de cierre</dt><dd>' . ($closingState === '' ? 'Sin hoja de cierre' : self::e(((int) ($closing['id'] ?? 0) > 0 ? '#' . (int) $closing['id'] . ' · ' : '') . ($closingState === 'Realizada' ? 'Aprobada' : $closingState))) . '</dd></div>'
      . '</dl>'
      . '<label class="pm-field"><span>Enlace para el propietario</span><span class="pm-link"><input type="text" readonly value="' . self::e($internal['enlace']) . '" data-pm-link><button type="button" class="ps-button ps-button--light" data-pm-copy>Copiar</button></span></label>'
      . '<div class="pm-actions">';
    if ($internal['puede_enviar']) {
      $html .= '<form method="post" action="' . self::e($page['action']) . '">' . $page['csrf'] . '<input type="hidden" name="accion" value="enviar"><button type="submit" class="ps-button">'
        . ($internal['envios'] > 0 ? 'Enviar de nuevo al propietario' : 'Enviar al propietario por correo y WhatsApp') . '</button></form>';
    }
    if (!$data['firmado']) {
      $html .= '<a class="ps-button ps-button--light" href="' . self::e($internal['panel']) . '" target="_top">Editar o enviar a firma en el panel</a>';
    }
    if ($internal['pdf'] !== '') {
      $html .= '<a class="ps-button ps-button--light" href="' . self::e($internal['pdf']) . '" target="_blank" rel="noopener">Ver PDF firmado</a>';
    }
    return $html . '</div>' . $warning . '</section>';
  }

  /** @param array{nombre:string,correo:string,celular:string} $advisor */
  private static function advisor(array $advisor): string
  {
    if ($advisor['nombre'] === '') {
      return '';
    }
    $phone = CommercialCallsNotifier::phone($advisor['celular']);
    return '<aside class="ps-card ps-advisor"><p class="ps-eyebrow">Su consultor/a asignado es</p><h2>' . self::e($advisor['nombre']) . '</h2>'
      . ($advisor['celular'] !== '' ? '<p>Celular: ' . self::e($advisor['celular']) . '</p>' : '') . ($advisor['correo'] !== '' ? '<p>Correo: <a href="mailto:' . self::e($advisor['correo']) . '">' . self::e($advisor['correo']) . '</a></p>' : '')
      . ($phone !== '' ? '<p><a class="ps-button" href="https://wa.me/' . self::e(ltrim($phone, '+')) . '" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a></p>' : '') . '</aside>';
  }
}
