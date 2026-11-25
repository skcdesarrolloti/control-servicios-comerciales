<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Mandates\CommercialMandatesRepository;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\MandateClosingSheet;
use SCM\Support\PublicMandateAccess;

/**
 * Contratos de mandato en el panel (Actividades comerciales › Registros › Mandatos y Mi portafolio › Mis mandatos): el
 * listado con el estado del mandato y de la revisión del propietario, y el popup con la vista interna de mandato.php
 * (enviar al propietario, responder observaciones, editar y enviar a firma). Usa las clases de cotizaciones y estudios.
 */
final class CommercialMandatesView
{
  private const STATE_TONES = ['No enviado' => 'wait', 'Enviado' => 'warn', 'Firmado' => 'done'];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  /** @param array<string,mixed> $input */
  public static function page(array $input, CommercialMandatesService $service): string
  {
    $listing = $service->listing($input);
    $f = $listing['filters'];
    $mine = $listing['mine'] || !$listing['team'];
    $html = '<section class="cqt cqt-page cst" data-cma-page>'
      . '<header class="cqt-head"><div><p class="cqt-eyebrow">Actividades comerciales · Registros</p><h1>' . ($listing['mine'] ? 'Mis mandatos' : 'Contratos de mandato') . '</h1><p>'
      . ($mine ? 'Los contratos de mandato que has creado.' : 'Los contratos de mandato de todo el equipo.')
      . ' Puedes enviarle el contrato al propietario para que lo revise (opcional); para enviarlo a firma, la hoja de cierre debe estar aprobada. Se crean desde la tarea (Gestión operativa › Contrato de mandato) o aquí con Nuevo mandato.</p></div>'
      . ($listing['can_create'] ? '<button type="button" class="cqt-primary" data-cma-form>' . self::icon('add') . 'Nuevo mandato</button>' : '') . '</header>'
      . '<form class="cqt-filters cqt-list-filters" data-cma-list-filters aria-label="Filtros de mandatos">'
      . ($listing['mine'] ? '<input type="hidden" name="portafolio" value="1">' : '')
      . '<input type="hidden" name="ma_estado" value="' . self::e($f['estado']) . '">'
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="ma_q" value="' . self::e($f['q']) . '" placeholder="Número de mandato, propietario, documento, inmueble o dirección"></label>';
    if ($listing['team'] && !$listing['mine']) {
      $html .= '<label class="cqt-field"><span>Consultor/a</span><select name="ma_empleado"><option value="">Todo el equipo</option>';
      foreach ($service->creators() as $id => $name) {
        $html .= '<option value="' . self::e($id) . '"' . ((string) $id === $f['empleado'] ? ' selected' : '') . '>' . self::e($name) . '</option>';
      }
      $html .= '</select></label>';
    }
    return $html . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">Filtrar</button><button type="button" class="cqt-secondary" data-cma-list-clear>Limpiar</button></div>'
      . '</form><div data-cma-list aria-live="polite">' . self::results($listing) . '</div></section>';
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing): string
  {
    $current = $listing['filters']['estado'];
    $html = '<div class="cst-pills" role="group" aria-label="Estado del mandato"><button type="button" class="cst-pill" data-cma-state="" aria-pressed="' . ($current === '' ? 'true' : 'false') . '">Todos <strong>' . number_format(array_sum($listing['estados']), 0, ',', '.') . '</strong></button>';
    foreach (CommercialMandatesRepository::STATES as $state) {
      $html .= '<button type="button" class="cst-pill cst-pill--' . self::STATE_TONES[$state] . '" data-cma-state="' . self::e($state) . '" aria-pressed="' . ($current === $state ? 'true' : 'false') . '">' . self::e($state === 'Enviado' ? 'Enviado a firma' : $state) . ' <strong>' . number_format((int) ($listing['estados'][$state] ?? 0), 0, ',', '.') . '</strong></button>';
    }
    $html .= '</div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="cqt-empty">No hay mandatos con estos filtros.</p>';
    }
    $html .= '<div class="cqt-table-wrap"><table class="cqt-table"><thead><tr><th>Mandato</th><th>Propietario</th><th>Inmueble</th><th>Revisión y hoja de cierre</th><th>Consultor/a</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $id = (int) $row['id'];
      [$label, $tone] = CommercialMandatesService::REVIEW_STATES[$row['revision']] ?? ['', 'wait'];
      $html .= '<tr>'
        . '<td data-label="Mandato"><strong>#' . $id . '</strong><small>' . self::e($row['fecha']) . ($row['tipo'] !== '' ? ' · ' . self::e($row['tipo']) : '') . '</small>'
        . '<span class="cst-state cst-state--' . (self::STATE_TONES[$row['estado']] ?? 'wait') . '">' . self::e($row['estado'] === 'Enviado' ? 'Enviado a firma' : $row['estado']) . '</span></td>'
        . '<td data-label="Propietario"><strong>' . self::e($row['propietarios'] !== [] ? implode(', ', $row['propietarios']) : 'Sin nombre') . '</strong><small>' . self::e($row['documento']) . '</small></td>'
        . '<td data-label="Inmueble">' . ($row['inmueble'] !== ''
          ? '<button type="button" class="cqt-link cst-property-link" data-commercial-property-detail="' . self::e($row['inmueble']) . '" title="Ver la ficha del inmueble">' . self::icon('home') . '#' . self::e($row['inmueble']) . '</button>'
          : '<span class="cqt-muted">Sin inmueble</span>') . '<small>' . self::e($row['direccion']) . '</small>' . ($row['canon'] !== '' ? '<small>Canon ' . self::e($row['canon']) . '</small>' : '') . '</td>'
        . '<td data-label="Revisión"><span class="cst-state cst-state--' . self::e($tone) . '">' . self::e($label) . '</span>'
        . ($row['pendientes'] > 0 ? '<small>' . $row['pendientes'] . ' observación(es) pendiente(s)</small>' : '') . ($row['envios'] > 1 ? '<small>Enviado ' . $row['envios'] . ' veces</small>' : '')
        . ($row['revision'] !== 'firmado' ? self::closingBadge($row['hoja'] ?? ['id' => 0, 'estado' => '']) : '') . self::missing($row) . '</td>'
        . '<td data-label="Consultor/a">' . self::e($row['consultor'] ?: 'Sin consultor/a') . '</td>'
        . '<td data-label="Acciones"><div class="cqt-row-actions cst-row-actions">'
        . self::actions($row, $listing['can_create'] ?? false, $listing['can_sign'] ?? false, 0, $listing['can_closing'] ?? false)
        . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de mandatos"><button type="button" class="cqt-secondary" data-cma-list-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="cqt-secondary" data-cma-list-page="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }

  /**
   * Ventana del popup de la tarea (Gestión operativa › Contrato de mandato): los mandatos de la tarea con su revisión y
   * sus acciones, y "Nuevo mandato".
   * @param array{ticket:int,asunto:string,rows:list<array<string,mixed>>,can_create:bool,can_sign:bool,can_closing?:bool,flash:string} $data
   */
  public static function taskPanel(array $data): string
  {
    $pk = (int) $data['ticket'];
    $html = '<section class="cqt cqt-task cst cma" data-cma-task data-ticket-pk="' . $pk . '">'
      . '<header class="cqt-task-head"><button type="button" class="cqt-back" data-commercial-return-case="' . $pk . '">' . self::icon('arrow_back') . 'Volver a la tarea</button>'
      . '<div><p class="cqt-eyebrow">Tarea #' . $pk . ($data['asunto'] !== '' ? ' · ' . self::e($data['asunto']) : '') . '</p><h1 tabindex="-1">Contrato de mandato</h1>'
      . '<p>Después del estudio aprobado: crea el mandato y, si quieres, envíaselo al propietario para que lo revise. Se envía a firma digital cuando la hoja de cierre está aprobada.</p></div>'
      . '<button type="button" class="cqt-icon" data-commercial-close-case aria-label="Cerrar">' . self::icon('close') . '</button></header>'
      . ($data['flash'] !== '' ? '<p class="cma-flash" role="status">' . self::e($data['flash']) . '</p>' : '')
      . ($data['can_create'] ? '<p><button type="button" class="cqt-primary" data-cma-form data-ticket-pk="' . $pk . '">' . self::icon('add') . 'Nuevo mandato</button></p>' : '');
    if ($data['rows'] === []) {
      return $html . '<p class="cqt-empty">Esta tarea todavía no tiene contrato de mandato.</p></section>';
    }
    $html .= '<div class="cma-cards">';
    foreach ($data['rows'] as $row) {
      $html .= '<article class="cst-card cma-card"><header><strong>Mandato #' . (int) $row['id'] . '</strong>' . self::badges($row) . '</header>'
        . '<p>' . self::e($row['propietarios'] !== [] ? implode(', ', $row['propietarios']) : 'Sin propietario') . ($row['inmueble'] !== '' ? ' · Inmueble #' . self::e($row['inmueble']) : '') . ($row['canon'] !== '' ? ' · Canon ' . self::e($row['canon']) : '') . '</p>'
        . self::missing($row)
        . '<div class="cst-actions">' . self::actions($row, $data['can_create'], $data['can_sign'], $pk, $data['can_closing'] ?? false) . '</div></article>';
    }
    return $html . '</div></section>';
  }

  /** @param array<string,mixed> $row */
  private static function badges(array $row): string
  {
    [$label, $tone] = CommercialMandatesService::REVIEW_STATES[$row['revision']] ?? ['', 'wait'];
    return '<span class="cst-state cst-state--' . (self::STATE_TONES[$row['estado']] ?? 'wait') . '">' . self::e($row['estado'] === 'Enviado' ? 'Enviado a firma' : $row['estado']) . '</span>'
      . ($row['revision'] !== 'firmado' ? '<span class="cst-state cst-state--' . self::e($tone) . '">' . self::e($label) . '</span>' . self::closingBadge($row['hoja'] ?? ['id' => 0, 'estado' => '']) : '');
  }

  /** Lo que falta para enviar a firma, a la vista (el botón y el servidor lo exigen). @param array<string,mixed> $row */
  private static function missing(array $row): string
  {
    $blockers = $row['estado'] === 'Firmado' ? [] : (array) ($row['bloqueos'] ?? []);
    if ($blockers === []) {
      $notice = (string) ($row['aviso_revision'] ?? '');
      return $row['estado'] === 'Firmado' ? '' : '<p class="cma-ready">' . self::icon('check_circle') . 'Listo para enviar a firma.</p>'
        . ($notice !== '' ? '<p class="cma-note">' . self::e($notice) . '</p>' : '');
    }
    return '<div class="cma-missing"><strong>' . self::icon('info') . 'Para enviar a firma falta:</strong><ul>'
      . implode('', array_map(static fn($reason): string => '<li>' . self::e($reason) . '</li>', $blockers)) . '</ul></div>';
  }

  /** Estado de la hoja de cierre (debe estar aprobada para enviar a firma). @param array{id:int,estado:string} $closing */
  private static function closingBadge(array $closing): string
  {
    $state = $closing['estado'];
    $tone = in_array($state, CommercialMandatesService::CLOSING_APPROVED, true) ? 'ok' : ($state === '' ? 'off' : 'warn');
    $label = $state === '' ? 'Sin hoja de cierre' : 'Hoja de cierre' . ($closing['id'] > 0 ? ' #' . $closing['id'] : '') . ': ' . ($state === 'Realizada' ? 'Aprobada' : $state);
    return '<span class="cst-state cst-state--' . $tone . '">' . self::e($label) . '</span>';
  }

  /** Acciones de un mandato (tarea y pestaña). @param array<string,mixed> $row */
  private static function actions(array $row, bool $canEdit, bool $canSign, int $ticket = 0, bool $canClosing = false): string
  {
    $id = (int) $row['id'];
    $open = $row['estado'] !== 'Firmado';
    $ticketAttr = $ticket > 0 ? ' data-ticket-pk="' . $ticket . '"' : '';
    return '<button type="button" class="' . ($row['revision'] === 'observaciones' ? 'cqt-primary' : 'cqt-secondary') . ' cqt-small" data-cma-view="' . $id . '">' . self::icon('rate_review') . 'Revisión del propietario</button>'
      . '<button type="button" class="cqt-secondary cqt-small" data-cma-view="' . $id . '" data-cma-mode="contrato">' . self::icon('description') . 'Ver contrato</button>'
      . '<button type="button" class="cqt-secondary cqt-small" data-cma-view="' . $id . '" data-cma-mode="propietario">' . self::icon('visibility') . 'Vista pública</button>'
      . ($canEdit && $open ? '<button type="button" class="cqt-secondary cqt-small" data-cma-form data-id="' . $id . '"' . $ticketAttr . '>' . self::icon('edit') . 'Editar</button>' : '')
      // Enviar a firma si no se ha enviado; Reenviar solo si hay cambios que no llegaron a eSignatures (al editar uno ya
      // enviado, el contrato se actualiza solo).
      . ($canSign && $open && ($row['estado'] !== 'Enviado' || !empty($row['firma_pendiente'])) ? '<button type="button" class="cqt-secondary cqt-small' . (($row['bloqueos'] ?? []) !== [] ? ' cma-blocked" aria-disabled="true" title="Falta lo que se indica para enviar a firma' : '') . '" data-review-notice="' . self::e((string) ($row['aviso_revision'] ?? '')) . '" data-cma-sign="' . $id . '" data-blockers="' . self::e((string) json_encode($row['bloqueos'] ?? [], JSON_UNESCAPED_UNICODE)) . '" data-resend="' . ($row['estado'] === 'Enviado' ? '1' : '0') . '"' . $ticketAttr . '>' . self::icon('draw') . ($row['estado'] === 'Enviado' ? 'Reenviar a firma' : 'Enviar a firma') . '</button>' : '')
      . ($canClosing ? self::closingButton($id, $row['hoja'] ?? ['id' => 0, 'estado' => ''], $ticket) : '')
      . ($row['pdf'] !== '' ? '<a class="cqt-secondary cqt-small" href="' . self::e($row['pdf']) . '" target="_blank" rel="noopener">' . self::icon('picture_as_pdf') . 'PDF firmado</a>' : '');
  }

  /**
   * Botón de la hoja de cierre: la crea o la abre en la ventana del panel (páginas del panel inmobiliario).
   * @param array{id:int,estado:string} $closing
   */
  public static function closingButton(int $mandate, array $closing, int $ticket = 0): string
  {
    $current = MandateClosingSheet::current($closing);
    $approved = in_array($closing['estado'], CommercialMandatesService::CLOSING_APPROVED, true);
    return '<button type="button" class="' . ($current === 0 || $approved ? 'cqt-secondary' : 'cqt-primary') . ' cqt-small" data-cma-closing="' . $mandate . '"'
      . ($ticket > 0 ? ' data-ticket-pk="' . $ticket . '"' : '') . ' data-cma-closing-title="' . self::e(MandateClosingSheet::label($closing) . ' · Mandato #' . $mandate) . '">'
      . self::icon($current > 0 ? 'handshake' : 'note_add') . self::e(MandateClosingSheet::label($closing)) . '</button>';
  }

  /** Formulario del mandato con su encabezado (en la tarea o en el popup de la pestaña). */
  public static function formPanel(string $form, int $id, int $ticket, string $subtitle): string
  {
    $back = $ticket > 0 ? '<button type="button" class="cqt-back" data-cma-task-open data-ticket-pk="' . $ticket . '">' . self::icon('arrow_back') . 'Volver a los mandatos</button>' : '<span></span>';
    $close = $ticket > 0 ? '<button type="button" class="cqt-icon" data-commercial-close-case aria-label="Cerrar">' . self::icon('close') . '</button>' : '<button type="button" class="cqt-icon" data-cma-viewer-close aria-label="Cerrar">' . self::icon('close') . '</button>';
    return '<section class="cqt cqt-task cst cma" data-cma-form-panel data-ticket-pk="' . ($ticket > 0 ? $ticket : '') . '">'
      . '<header class="cqt-task-head">' . $back . '<div><p class="cqt-eyebrow">' . self::e($subtitle) . '</p><h1 tabindex="-1">' . ($id > 0 ? 'Editar mandato #' . $id : 'Nuevo contrato de mandato') . '</h1>'
      . '<p>Completa cada paso; los valores de comisión, garantías y primas se calculan solos con las tasas de la configuración.</p></div>' . $close . '</header>'
      . $form . '</section>';
  }

  /** Popup con la vista interna de mandato.php (revisión del propietario y acciones del equipo). */
  public static function viewer(int $id, string $mode = ''): string
  {
    $staff = PublicMandateAccess::staffUrl($id);
    // Tres vistas en el mismo popup: la revisión (equipo), el contrato completo y lo que ve el propietario.
    $views = [
      '' => ['rate_review', 'Revisión', $staff . '&embed=1'],
      'contrato' => ['description', 'Contrato completo', $staff . '&embed=1&contrato=1#contrato'],
      'propietario' => ['visibility', 'Como el propietario', $staff . '&embed=1&como=propietario'],
    ];
    $mode = isset($views[$mode]) ? $mode : '';
    $tabs = '';
    foreach ($views as $key => [$icon, $label, $url]) {
      $tabs .= '<a class="cst-pill" href="' . self::e($url) . '" target="cma-frame-' . $id . '" data-cma-viewer-tab aria-pressed="' . ($key === $mode ? 'true' : 'false') . '">' . self::icon($icon) . self::e($label) . '</a>';
    }
    return '<div class="cqt-viewer-box" role="document"><header class="cqt-viewer-head"><h2 id="cma-viewer-title">Contrato de mandato #' . $id . '</h2>'
      . '<nav class="cst-pills cma-viewer-tabs" aria-label="Vistas del mandato">' . $tabs . '</nav><div class="cqt-viewer-actions">'
      . '<a class="cqt-secondary" href="' . self::e($staff) . '" target="_blank" rel="noopener">' . self::icon('open_in_new') . 'Abrir</a>'
      . '<button type="button" class="cqt-icon" data-cma-viewer-close aria-label="Cerrar">' . self::icon('close') . '</button></div></header>'
      . '<iframe class="cqt-frame" name="cma-frame-' . $id . '" src="' . self::e($views[$mode][2]) . '" title="Contrato de mandato #' . $id . '"></iframe></div>';
  }

}
