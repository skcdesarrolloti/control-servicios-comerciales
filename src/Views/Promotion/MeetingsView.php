<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

/**
 * Promoción › Reuniones: copia de dashboard-marketing (views/meetings.php) con el diseño del panel comercial. Listado
 * con indicadores y filtros, detalle con logros, mejoras y plan de acción, y modo guía de cinco pasos. Los formularios y
 * las confirmaciones son popups internos (<dialog>) y la navegación queda dentro del panel (?tab=reuniones&reunion=ID).
 * Solo se pintan los botones que el cargo puede usar; el servidor vuelve a validar cada acción.
 */
final class MeetingsView
{
  public const MEETING_STATUSES = ['draft' => 'Borrador', 'in_progress' => 'En curso', 'completed' => 'Finalizada', 'archived' => 'Archivada'];
  public const ACTION_STATUSES = ['pending' => 'Pendiente', 'in_progress' => 'En proceso', 'completed' => 'Realizada', 'not_completed' => 'No realizada'];
  private const FILTERS = ['upcoming' => 'Próximas reuniones', 'history' => 'Historial completo'];
  private const MONTHS = ['01' => 'ENE', '02' => 'FEB', '03' => 'MAR', '04' => 'ABR', '05' => 'MAY', '06' => 'JUN', '07' => 'JUL', '08' => 'AGO', '09' => 'SEP', '10' => 'OCT', '11' => 'NOV', '12' => 'DIC'];
  private const SECTIONS = [
    'achievement' => ['title' => 'Logros y trabajo realizado', 'short' => 'logro', 'help' => 'Registra lo que se ejecutó y el resultado alcanzado.', 'icon' => 'emoji_events'],
    'improvement' => ['title' => 'Puntos de mejora', 'short' => 'mejora', 'help' => 'Documenta el diagnóstico y la oportunidad de optimización.', 'icon' => 'trending_up'],
    'action' => ['title' => 'Plan de acción', 'short' => 'acción', 'help' => 'Define responsables, fechas y compromisos verificables.', 'icon' => 'task_alt'],
  ];

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    $can = (array) ($context['can'] ?? []);
    $screen = (string) ($context['screen'] ?? 'list');
    $meeting = (array) ($context['meeting'] ?? []);
    $html = '<div class="cpr-meetings" data-cpr-meetings data-meeting-id="' . self::e((int) ($meeting['id'] ?? 0)) . '" data-list-url="' . self::e(self::url($baseUrl, [])) . '">'
      . '<div class="cpr-live" role="status" aria-live="polite" data-cpr-live></div>';
    if ($screen === 'missing') {
      $html .= self::missing($baseUrl);
    } elseif ($screen === 'guide' && $meeting !== []) {
      $html .= self::guide($meeting, $can, $baseUrl);
    } elseif ($screen === 'detail' && $meeting !== []) {
      $html .= self::detail($meeting, $can, (array) ($context['employees'] ?? []), $baseUrl);
    } else {
      $html .= self::listing((array) ($context['dashboard'] ?? []), (array) ($context['filters'] ?? []), $can, $baseUrl);
    }
    if (!empty($can['manage'])) {
      $html .= self::meetingDialog((array) ($context['employees'] ?? []));
    }
    $html .= self::confirmDialog() . '</div>';
    return $html;
  }

  /** @param array<string,mixed> $params */
  public static function url(string $baseUrl, array $params): string
  {
    $params = array_filter(['tab' => 'reuniones'] + $params, static fn($value): bool => $value !== '' && $value !== null && $value !== 0);
    return rtrim($baseUrl, '/') . '/index.php?' . http_build_query($params);
  }

  /** @param array<string,mixed> $dashboard @param array<string,mixed> $filters @param array<string,mixed> $can */
  private static function listing(array $dashboard, array $filters, array $can, string $baseUrl): string
  {
    $summary = (array) ($dashboard['summary'] ?? []);
    $meetings = (array) ($dashboard['meetings'] ?? []);
    $html = self::hero('groups', 'Reuniones', 'Prepara los temas, conduce la conversación y deja compromisos verificables para el siguiente encuentro.'
      . (empty($can['team']) ? ' Ves las reuniones que creaste, donde participas o donde tienes una acción.' : ''),
      !empty($can['manage']) ? '<button type="button" class="cpr-primary" data-cpr-open="meeting"><span class="material-symbols-outlined" aria-hidden="true">add</span>Nueva reunión</button>' : '');
    $cards = [
      ['Total activas', (int) ($summary['total'] ?? 0), 'Sin incluir archivadas', ''],
      ['Próximas', (int) ($summary['upcoming'] ?? 0), 'En borrador con fecha vigente', ''],
      ['En curso', (int) ($summary['in_progress'] ?? 0), 'Reuniones abiertas', ''],
      ['Finalizadas', (int) ($summary['completed'] ?? 0), 'Historial bloqueado', ''],
      ['Cumplimiento', (int) ($summary['compliance'] ?? 0) . '%', 'Acciones realizadas / evaluadas', ' is-accent'],
    ];
    $html .= '<div class="cpr-stats" data-cpr-summary>';
    foreach ($cards as [$label, $value, $note, $class]) {
      $html .= '<article class="cpr-stat' . $class . '"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong><small>' . self::e($note) . '</small></article>';
    }
    $html .= '</div>';

    $status = (string) ($filters['status'] ?? '');
    $options = '<option value="">Activas y finalizadas</option>';
    foreach (self::FILTERS + self::MEETING_STATUSES as $value => $label) {
      $options .= '<option value="' . self::e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
    }
    $html .= '<form class="cpr-filters" method="get" action="' . self::e(self::url($baseUrl, [])) . '" data-commercial-filter-form data-cpr-filters>'
      . '<label class="cpr-field"><span>Buscar</span><input type="search" name="reunion_q" maxlength="120" value="' . self::e((string) ($filters['q'] ?? '')) . '" placeholder="Título, objetivo o conclusión"></label>'
      . '<label class="cpr-field"><span>Vista</span><select name="reunion_estado">' . $options . '</select></label>'
      . '<div class="cpr-filters__actions"><button type="submit" class="cpr-primary">Aplicar filtros</button>'
      . (($filters['q'] ?? '') !== '' || $status !== '' ? '<a class="cpr-ghost" data-commercial-filter-link href="' . self::e(self::url($baseUrl, [])) . '">Limpiar</a>' : '')
      . '</div></form>';

    $html .= '<div class="cpr-list" data-cpr-list>';
    foreach ($meetings as $row) {
      $row = (array) $row;
      $rowStatus = (string) ($row['status'] ?? 'draft');
      $date = (string) ($row['meeting_date'] ?? '');
      $time = strtotime($date) ?: time();
      $link = self::url($baseUrl, ['reunion' => (int) ($row['id'] ?? 0)]);
      $html .= '<article class="cpr-row" data-cpr-meeting-row="' . self::e((int) ($row['id'] ?? 0)) . '">'
        . '<div class="cpr-date"><strong>' . self::e(date('d', $time)) . '</strong><span>' . self::e(self::MONTHS[date('m', $time)]) . '</span><small>' . self::e(date('Y', $time)) . '</small></div>'
        . '<div class="cpr-row__copy"><div class="cpr-topline">' . self::statusChip($rowStatus, self::MEETING_STATUSES)
        . (!empty($row['carried_from_meeting_id']) ? '<span class="cpr-chip is-carried">Con seguimiento anterior</span>' : '') . '</div>'
        . '<h2><a data-commercial-filter-link href="' . self::e($link) . '">' . self::e((string) ($row['title'] ?? '')) . '</a></h2>'
        . '<p>' . self::e((string) (($row['objective'] ?? '') ?: 'Sin objetivo registrado.')) . '</p>'
        . '<small>' . self::e(self::plural(count((array) ($row['participants'] ?? [])), 'participante', 'participantes')) . ' · Creada por ' . self::e((string) (($row['created_by_name'] ?? '') ?: 'Funcionario')) . '</small></div>'
        . '<div class="cpr-row__metrics"><div><span>Acciones</span><strong>' . self::e((int) ($row['action_total'] ?? 0)) . '</strong></div>'
        . '<div><span>Abiertas</span><strong>' . self::e((int) ($row['action_open'] ?? 0)) . '</strong></div>'
        . '<div><span>Cumplimiento</span><strong>' . self::e((int) ($row['compliance'] ?? 0)) . '%</strong></div>'
        . '<a class="cpr-ghost" data-commercial-filter-link href="' . self::e($link) . '">Ver reunión</a></div></article>';
    }
    if ($meetings === []) {
      $html .= self::emptyState('No encontramos reuniones', !empty($can['manage']) ? 'Crea la primera reunión o ajusta los filtros.' : 'No hay reuniones disponibles para consultar.');
    }
    return $html . '</div>';
  }

  /** @param array<string,mixed> $meeting @param array<string,mixed> $can @param array<int,mixed> $employees */
  private static function detail(array $meeting, array $can, array $employees, string $baseUrl): string
  {
    $id = (int) $meeting['id'];
    $status = (string) ($meeting['status'] ?? 'draft');
    $editable = !empty($can['manage']) && in_array($status, ['draft', 'in_progress'], true);
    $items = (array) ($meeting['items'] ?? []);
    $progress = (array) ($meeting['progress'] ?? []);
    $participants = (array) ($meeting['participants'] ?? []);

    $actions = '';
    if ($editable && $status === 'draft') {
      $actions .= '<button type="button" class="cpr-primary" data-cpr-action="start">Iniciar modo guía</button>';
    }
    if ($status === 'in_progress') {
      $actions .= '<a class="cpr-primary" data-commercial-filter-link href="' . self::e(self::url($baseUrl, ['reunion' => $id, 'modo' => 'guia'])) . '">Continuar modo guía</a>';
    }
    if ($status === 'completed' && !empty($can['reopen'])) {
      $actions .= '<button type="button" class="cpr-ghost" data-cpr-action="reopen" data-cpr-confirm="¿Reabrir esta reunión? Volverá a quedar en curso y se podrá modificar." data-cpr-confirm-label="Reabrir">Reabrir</button>';
    }
    if ($editable) {
      $actions .= '<button type="button" class="cpr-ghost" data-cpr-open="meeting" data-meeting="' . self::e(self::json([
        'id' => $id, 'title' => (string) ($meeting['title'] ?? ''), 'meeting_date' => (string) ($meeting['meeting_date'] ?? ''),
        'objective' => (string) ($meeting['objective'] ?? ''), 'conclusions' => (string) ($meeting['conclusions'] ?? ''),
        'participants' => array_map(static fn($p): array => ['id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? '')], array_filter($participants, 'is_array')),
      ])) . '">Editar datos</button>';
    }

    $html = '<header class="cpr-detail-hero">'
      . '<a class="cpr-back" data-commercial-filter-link href="' . self::e(self::url($baseUrl, [])) . '"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Todas las reuniones</a>'
      . '<div class="cpr-detail-hero__main"><div><div class="cpr-topline">' . self::statusChip($status, self::MEETING_STATUSES) . '<span>' . self::e(self::formatDate((string) ($meeting['meeting_date'] ?? ''))) . '</span></div>'
      . '<h1>' . self::e((string) ($meeting['title'] ?? 'Reunión')) . '</h1>'
      . '<p>' . self::e((string) (($meeting['objective'] ?? '') ?: 'Sin objetivo registrado.')) . '</p></div>'
      . ($actions !== '' ? '<div class="cpr-detail-hero__actions">' . $actions . '</div>' : '') . '</div></header>';

    $html .= '<div class="cpr-stats is-three">'
      . '<article class="cpr-stat"><span>Participantes</span><strong>' . self::e(count($participants)) . '</strong><small>' . self::e(implode(', ', array_map(static fn($p): string => (string) ($p['name'] ?? ''), array_filter($participants, 'is_array'))) ?: 'Sin participantes') . '</small></article>'
      . '<article class="cpr-stat"><span>Acciones revisadas</span><strong data-cpr-progress-reviewed>' . self::e((int) ($progress['reviewed'] ?? 0)) . '/' . self::e((int) ($progress['total'] ?? 0)) . '</strong><div class="cpr-progress"><span data-cpr-progress-bar style="width:' . self::e((int) ($progress['review_percent'] ?? 0)) . '%"></span></div></article>'
      . '<article class="cpr-stat is-accent"><span>Cumplimiento</span><strong data-cpr-progress-compliance>' . self::e((int) ($progress['compliance_percent'] ?? 0)) . '%</strong><small>Solo acciones evaluadas</small></article>'
      . '</div>';

    $number = 0;
    foreach (self::SECTIONS as $section => $meta) {
      $number++;
      $sectionItems = (array) ($items[$section] ?? []);
      $html .= '<section class="cpr-section" data-cpr-section="' . self::e($section) . '"><header><div><span class="cpr-section__number">0' . $number . '</span><h2>' . self::e($meta['title']) . '</h2><p>' . self::e($meta['help']) . '</p></div>'
        . ($editable ? '<button type="button" class="cpr-ghost" data-cpr-open="item" data-section="' . self::e($section) . '"><span class="material-symbols-outlined" aria-hidden="true">add</span>Agregar ' . self::e($meta['short']) . '</button>' : '')
        . '</header><div class="cpr-items">';
      foreach (array_values($sectionItems) as $position => $item) {
        $html .= self::itemCard((array) $item, $section, $position + 1, count($sectionItems), $editable);
      }
      if ($sectionItems === []) {
        $html .= self::emptyState('Aún no hay ' . mb_strtolower($meta['title']), $editable ? 'Usa el botón Agregar para preparar este bloque.' : 'No se registró contenido en este bloque.', true);
      }
      $html .= '</div></section>';
    }

    if ($status === 'completed' || !empty($meeting['conclusions'])) {
      $html .= '<section class="cpr-card cpr-conclusions"><span class="cpr-kicker">Cierre</span><h2>Conclusiones</h2><p>' . nl2br(self::e((string) (($meeting['conclusions'] ?? '') ?: 'Sin conclusiones registradas.'))) . '</p></section>';
    }
    $history = (array) ($meeting['history'] ?? []);
    if ($history !== []) {
      $html .= '<details class="cpr-card cpr-history"><summary>Historial de estados (' . self::e(count($history)) . ')</summary><div>';
      foreach ($history as $entry) {
        $entry = (array) $entry;
        $html .= '<p><strong>' . self::e((string) (($entry['action_title'] ?? '') ?: 'Acción')) . '</strong> cambió de ' . self::e(self::ACTION_STATUSES[(string) ($entry['from_status'] ?? '')] ?? (string) ($entry['from_status'] ?? ''))
          . ' a ' . self::e(self::ACTION_STATUSES[(string) ($entry['to_status'] ?? '')] ?? (string) ($entry['to_status'] ?? '')) . ' · ' . self::e((string) (($entry['changed_by_name'] ?? '') ?: 'Funcionario'))
          . ' · ' . self::e(self::formatDate((string) ($entry['changed_at'] ?? ''), true))
          . (!empty($entry['reason']) ? '<br><span>Motivo: ' . self::e((string) $entry['reason']) . '</span>' : '') . '</p>';
      }
      $html .= '</div></details>';
    }

    $archive = !empty($can['manage']) && $status !== 'archived';
    if ($archive || !empty($can['delete'])) {
      $html .= '<div class="cpr-danger"><div><strong>Zona administrativa</strong><small>Archivar conserva la reunión en el historial. Eliminar la quita de las listas; su contenido y quién la eliminó quedan registrados.</small></div><div class="cpr-danger__actions">'
        . ($archive ? '<button type="button" class="cpr-ghost is-danger" data-cpr-action="archive" data-cpr-confirm="¿Archivar esta reunión? No se elimina: queda en el historial de reuniones y ya no se podrá modificar." data-cpr-confirm-label="Archivar reunión">Archivar reunión</button>' : '')
        . (!empty($can['delete']) ? '<button type="button" class="cpr-danger-button" data-cpr-action="delete" data-cpr-confirm="¿Eliminar la reunión «' . self::e((string) ($meeting['title'] ?? '')) . '»? Dejará de aparecer en Reuniones con sus logros, mejoras y acciones. Quedará registrado quién la eliminó y cuándo." data-cpr-confirm-label="Eliminar reunión" data-cpr-confirm-danger>Eliminar reunión</button>' : '')
        . '</div></div>';
    }
    if ($editable) {
      $html .= self::itemDialog((array) ($items['improvement'] ?? []), $employees);
    }
    return $html;
  }

  /** @param array<string,mixed> $item */
  private static function itemCard(array $item, string $section, int $position, int $total, bool $editable): string
  {
    $itemId = (int) ($item['id'] ?? 0);
    $itemStatus = (string) ($item['status'] ?? 'recorded');
    $isAction = $section === 'action';
    $html = '<article class="cpr-item' . ($isAction ? ' status-' . self::statusClass($itemStatus) : '') . '" data-cpr-item="' . self::e($itemId) . '"' . ($isAction ? ' data-cpr-action-card' : '') . '>'
      . '<div class="cpr-item__order">' . self::e($position) . '</div><div class="cpr-item__body"><div class="cpr-topline">'
      . ($isAction ? '<span class="cpr-chip status-' . self::statusClass($itemStatus) . '" data-cpr-action-label>' . self::e(self::ACTION_STATUSES[$itemStatus] ?? $itemStatus) . '</span>' : '')
      . ((int) ($item['carried_from_item_id'] ?? 0) > 0 ? '<span class="cpr-chip is-carried">Seguimiento anterior</span>' : '')
      . '</div><h3>' . self::e((string) ($item['title'] ?? '')) . '</h3>'
      . (!empty($item['content']) ? '<p>' . nl2br(self::e((string) $item['content'])) . '</p>' : '');
    if ($section === 'improvement' && !empty($item['observation'])) {
      $html .= '<small class="cpr-note">Observación: ' . self::e((string) $item['observation']) . '</small>';
    }
    if ($isAction) {
      $html .= self::actionFacts($item);
      if ($editable) {
        $html .= '<div class="cpr-status-buttons" aria-label="Estado de ' . self::e((string) ($item['title'] ?? '')) . '">';
        foreach (['pending' => 'Pendiente', 'in_progress' => 'En proceso', 'completed' => 'Hecho', 'not_completed' => 'No realizado'] as $value => $label) {
          $html .= '<button type="button" data-cpr-status="' . self::e($value) . '"' . ($value === $itemStatus ? ' aria-pressed="true"' : ' aria-pressed="false"') . '>' . self::e($label) . '</button>';
        }
        $html .= '</div>';
      }
    }
    $html .= '</div>';
    if ($editable) {
      $html .= '<div class="cpr-item__tools">'
        . '<button type="button" data-cpr-action="item-move" data-direction="up" data-item-id="' . self::e($itemId) . '" aria-label="Subir punto"' . ($position <= 1 ? ' disabled' : '') . '><span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span></button>'
        . '<button type="button" data-cpr-action="item-move" data-direction="down" data-item-id="' . self::e($itemId) . '" aria-label="Bajar punto"' . ($position >= $total ? ' disabled' : '') . '><span class="material-symbols-outlined" aria-hidden="true">arrow_downward</span></button>'
        . '<button type="button" data-cpr-open="item" data-section="' . self::e($section) . '" data-item="' . self::e(self::json(self::itemPayload($item))) . '">Editar</button>'
        . '<button type="button" class="is-danger" data-cpr-action="item-archive" data-item-id="' . self::e($itemId) . '" data-cpr-confirm="¿Archivar «' . self::e((string) ($item['title'] ?? '')) . '»? Sale de la reunión y su historial se conserva." data-cpr-confirm-label="Archivar punto">Archivar</button>'
        . '</div>';
    }
    return $html . '</article>';
  }

  /** @param array<string,mixed> $item */
  private static function actionFacts(array $item): string
  {
    return '<dl class="cpr-facts"><div><dt>Responsable</dt><dd>' . self::e((string) (($item['responsible_name'] ?? '') ?: 'Sin asignar')) . '</dd></div>'
      . '<div><dt>Fecha límite</dt><dd>' . self::e(self::formatDate((string) ($item['due_date'] ?? ''))) . '</dd></div>'
      . (!empty($item['observation']) ? '<div><dt>Observación</dt><dd>' . self::e((string) $item['observation']) . '</dd></div>' : '') . '</dl>'
      . '<p class="cpr-failure" data-cpr-failure' . (empty($item['failure_reason']) ? ' hidden' : '') . '>Motivo: <span>' . self::e((string) ($item['failure_reason'] ?? '')) . '</span></p>';
  }

  /** Solo lo que necesita el formulario de edición. @param array<string,mixed> $item @return array<string,mixed> */
  private static function itemPayload(array $item): array
  {
    return [
      'id' => (int) ($item['id'] ?? 0),
      'section' => (string) ($item['section'] ?? ''),
      'title' => (string) ($item['title'] ?? ''),
      'content' => (string) ($item['content'] ?? ''),
      'observation' => (string) ($item['observation'] ?? ''),
      'related_improvement_id' => (int) ($item['related_improvement_id'] ?? 0),
      'responsible_employee_id' => (int) ($item['responsible_employee_id'] ?? 0),
      'responsible_name' => (string) ($item['responsible_name'] ?? ''),
      'due_date' => (string) ($item['due_date'] ?? ''),
    ];
  }

  /** @param array<string,mixed> $meeting @param array<string,mixed> $can */
  private static function guide(array $meeting, array $can, string $baseUrl): string
  {
    $id = (int) $meeting['id'];
    $status = (string) ($meeting['status'] ?? 'draft');
    $editable = !empty($can['manage']) && in_array($status, ['draft', 'in_progress'], true);
    $items = (array) ($meeting['items'] ?? []);
    $progress = (array) ($meeting['progress'] ?? []);
    $actions = array_values((array) ($items['action'] ?? []));
    $previous = array_values(array_filter($actions, static fn($item): bool => (int) ($item['carried_from_item_id'] ?? 0) > 0));
    $new = array_values(array_filter($actions, static fn($item): bool => (int) ($item['carried_from_item_id'] ?? 0) === 0));
    $steps = ['Logros', 'Mejoras', 'Seguimiento anterior', 'Plan nuevo', 'Conclusiones'];

    $html = '<header class="cpr-detail-hero is-guide"><a class="cpr-back" data-commercial-filter-link href="' . self::e(self::url($baseUrl, ['reunion' => $id])) . '"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Volver al detalle</a>'
      . '<div class="cpr-detail-hero__main"><div><span class="cpr-kicker">Modo guía · ' . self::e(self::formatDate((string) ($meeting['meeting_date'] ?? ''))) . '</span>'
      . '<h1>' . self::e((string) ($meeting['title'] ?? 'Reunión')) . '</h1><p>' . self::e((string) (($meeting['objective'] ?? '') ?: 'Conduce la reunión paso a paso y registra los acuerdos.')) . '</p></div>'
      . '<div class="cpr-stat is-compact"><span>Avance de revisión</span><strong data-cpr-progress-reviewed>' . self::e((int) ($progress['reviewed'] ?? 0)) . '/' . self::e((int) ($progress['total'] ?? 0)) . '</strong><div class="cpr-progress"><span data-cpr-progress-bar style="width:' . self::e((int) ($progress['review_percent'] ?? 0)) . '%"></span></div></div>'
      . '</div></header>';

    $html .= '<div data-cpr-guide><nav class="cpr-steps" aria-label="Pasos de la reunión">';
    foreach ($steps as $index => $label) {
      $html .= '<button type="button" data-cpr-step-jump="' . $index . '"' . ($index === 0 ? ' class="is-active" aria-current="step"' : '') . '><span>' . ($index + 1) . '</span>' . self::e($label) . '</button>';
    }
    $html .= '</nav>';

    $points = static function (array $list, bool $withObservation): string {
      $out = '';
      foreach (array_values($list) as $position => $item) {
        $out .= '<section class="cpr-point"><span>' . ($position + 1) . '</span><div><h3>' . self::e((string) ($item['title'] ?? '')) . '</h3><p>' . nl2br(self::e((string) ($item['content'] ?? ''))) . '</p>'
          . ($withObservation && !empty($item['observation']) ? '<small>Observación: ' . self::e((string) $item['observation']) . '</small>' : '') . '</div></section>';
      }
      return $out;
    };
    $html .= self::step(0, 'Logros y trabajo realizado', 'Empieza reconociendo qué se ejecutó y qué resultado produjo.',
      $points((array) ($items['achievement'] ?? []), false) ?: self::emptyState('Sin logros registrados', 'Puedes continuar y agregarlos después si la reunión sigue abierta.', true));
    $html .= self::step(1, 'Puntos de mejora', 'Revisa el diagnóstico antes de convertirlo en compromisos.',
      $points((array) ($items['improvement'] ?? []), true) ?: self::emptyState('Sin puntos de mejora', 'No hay diagnósticos registrados para esta reunión.', true));
    foreach ([2 => [$previous, 'Seguimiento de la reunión anterior', 'Evalúa los compromisos heredados antes de definir el plan nuevo.'], 3 => [$new, 'Plan de acción nuevo', 'Confirma responsables y registra el estado de cada compromiso.']] as $index => [$list, $title, $lead]) {
      $body = '';
      foreach ($list as $item) {
        $item = (array) $item;
        $itemStatus = (string) ($item['status'] ?? 'pending');
        $body .= '<section class="cpr-item is-guide status-' . self::statusClass($itemStatus) . '" data-cpr-item="' . self::e((int) ($item['id'] ?? 0)) . '" data-cpr-action-card><div class="cpr-item__body">'
          . '<div class="cpr-topline"><span class="cpr-chip status-' . self::statusClass($itemStatus) . '" data-cpr-action-label>' . self::e(self::ACTION_STATUSES[$itemStatus] ?? $itemStatus) . '</span>' . ($index === 2 ? '<span class="cpr-chip is-carried">Arrastre anterior</span>' : '') . '</div>'
          . '<h3>' . self::e((string) ($item['title'] ?? '')) . '</h3>' . (!empty($item['content']) ? '<p>' . nl2br(self::e((string) $item['content'])) . '</p>' : '') . self::actionFacts($item);
        if ($editable) {
          $body .= '<div class="cpr-status-buttons is-large">';
          foreach (['in_progress' => 'En proceso', 'completed' => 'Hecho', 'not_completed' => 'No realizado'] as $value => $label) {
            $body .= '<button type="button" data-cpr-status="' . self::e($value) . '" aria-pressed="' . ($value === $itemStatus ? 'true' : 'false') . '">' . self::e($label) . '</button>';
          }
          $body .= '</div>';
        }
        $body .= '</div></section>';
      }
      $html .= self::step($index, $title, $lead, $body ?: self::emptyState('No hay acciones en este bloque', 'Continúa al siguiente paso de la reunión.', true));
    }
    $closing = $editable
      ? '<form class="cpr-card cpr-close" data-cpr-form="complete"><label class="cpr-field"><span>Conclusiones</span><textarea name="conclusions" rows="8" maxlength="12000" placeholder="Decisiones, acuerdos y asuntos que deben quedar registrados">' . self::e((string) ($meeting['conclusions'] ?? '')) . '</textarea></label>'
        . '<div class="cpr-close__summary"><span>Acciones revisadas <strong data-cpr-progress-reviewed>' . self::e((int) ($progress['reviewed'] ?? 0)) . '/' . self::e((int) ($progress['total'] ?? 0)) . '</strong></span><span>Cumplimiento <strong data-cpr-progress-compliance>' . self::e((int) ($progress['compliance_percent'] ?? 0)) . '%</strong></span></div>'
        . '<button type="submit" class="cpr-primary" data-cpr-confirm="¿Finalizar la reunión? Quedará bloqueada y solo alguien con permiso para reabrir podrá volver a modificarla." data-cpr-confirm-label="Finalizar y bloquear">Finalizar y bloquear reunión</button></form>'
      : '<div class="cpr-card"><p>' . nl2br(self::e((string) (($meeting['conclusions'] ?? '') ?: 'No se registraron conclusiones.'))) . '</p></div>';
    $html .= self::step(4, 'Conclusiones y cierre', 'Resume las decisiones tomadas. Al finalizar, la reunión quedará bloqueada.', $closing);
    $html .= '<footer class="cpr-guide-controls"><button type="button" class="cpr-ghost" data-cpr-step="prev" disabled>Anterior</button><span><strong data-cpr-step-current>1</strong> de 5</span><button type="button" class="cpr-primary" data-cpr-step="next">Siguiente</button></footer></div>';
    return $html;
  }

  private static function step(int $index, string $title, string $lead, string $body): string
  {
    return '<article class="cpr-step" data-cpr-step-panel="' . $index . '" tabindex="-1"' . ($index === 0 ? '' : ' hidden') . '><span class="cpr-kicker">Paso ' . ($index + 1) . ' de 5</span><h2>' . self::e($title) . '</h2><p class="cpr-lead">' . self::e($lead) . '</p><div class="cpr-step__body">' . $body . '</div></article>';
  }

  private static function missing(string $baseUrl): string
  {
    return self::hero('groups', 'Reuniones', 'Prepara los temas, conduce la conversación y deja compromisos verificables.')
      . self::emptyState('Esta reunión no está disponible', 'No existe, fue eliminada o no tienes acceso a ella.')
      . '<p class="cpr-center"><a class="cpr-ghost" data-commercial-filter-link href="' . self::e(self::url($baseUrl, [])) . '">Ver todas las reuniones</a></p>';
  }

  /** Popup de crear y editar reunión, con buscador de participantes. @param array<int,mixed> $employees */
  private static function meetingDialog(array $employees): string
  {
    $options = '';
    foreach ($employees as $employee) {
      $employee = (array) $employee;
      $id = (int) ($employee['id'] ?? 0);
      $name = (string) ($employee['nombre'] ?? '');
      if ($id > 0 && $name !== '') {
        $options .= '<label class="cpr-option" data-cpr-option data-search="' . self::e(self::searchKey($name)) . '"><input type="checkbox" name="participant_ids[]" value="' . self::e($id) . '" data-name="' . self::e($name) . '"><span>' . self::e($name) . '</span></label>';
      }
    }
    return '<dialog class="cpr-dialog" data-cpr-dialog="meeting" aria-labelledby="cpr-meeting-title"><form method="dialog" class="cpr-dialog__form" data-cpr-form="meeting">'
      . '<input type="hidden" name="id" value="">'
      . '<header><div><span class="cpr-kicker" data-cpr-meeting-kicker>Nueva reunión</span><h2 id="cpr-meeting-title" data-cpr-meeting-heading>Preparar encuentro</h2><p data-cpr-meeting-help>Al guardar se copian una sola vez las acciones abiertas de la reunión anterior.</p></div><button type="button" class="cpr-close-button" data-cpr-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>'
      . '<div class="cpr-grid">'
      . '<label class="cpr-field"><span>Título</span><input name="title" maxlength="220" required placeholder="Ej. Seguimiento de promoción · agosto"></label>'
      . '<label class="cpr-field"><span>Fecha</span><input type="date" name="meeting_date" required value="' . self::e(date('Y-m-d')) . '"></label>'
      . '<label class="cpr-field is-wide"><span>Objetivo</span><textarea name="objective" rows="3" maxlength="8000" placeholder="Qué debe resolverse o definirse en esta reunión"></textarea></label>'
      . '<div class="cpr-field is-wide" data-cpr-participants><span>Participantes</span><div class="cpr-chips" data-cpr-participant-chips aria-live="polite"><small>Ningún participante seleccionado</small></div>'
      . '<input type="search" data-cpr-participant-search autocomplete="off" placeholder="Buscar funcionario por nombre…" aria-label="Buscar participante">'
      . '<div class="cpr-options" data-cpr-participant-options>' . $options . '<p class="cpr-muted" data-cpr-participant-empty hidden>No encontramos funcionarios con ese nombre.</p></div>'
      . '<small class="cpr-muted">Se guarda el nombre de cada participante aunque después cambie o se retire.</small></div>'
      . '<label class="cpr-field is-wide" data-cpr-meeting-conclusions hidden><span>Conclusiones provisionales</span><textarea name="conclusions" rows="3" maxlength="12000"></textarea></label>'
      . '</div><p class="cpr-form-error" data-cpr-form-error role="alert" hidden></p>'
      . '<footer><button type="button" class="cpr-ghost" data-cpr-close>Cancelar</button><button type="submit" class="cpr-primary" data-cpr-meeting-submit>Crear reunión</button></footer>'
      . '</form></dialog>';
  }

  /** @param array<int,mixed> $improvements @param array<int,mixed> $employees */
  private static function itemDialog(array $improvements, array $employees): string
  {
    $related = '<option value="">Sin relación</option>';
    foreach ($improvements as $improvement) {
      $related .= '<option value="' . self::e((int) ($improvement['id'] ?? 0)) . '">' . self::e((string) ($improvement['title'] ?? '')) . '</option>';
    }
    $responsible = '<option value="">Selecciona un responsable</option>';
    foreach ($employees as $employee) {
      $responsible .= '<option value="' . self::e((int) ($employee['id'] ?? 0)) . '">' . self::e((string) ($employee['nombre'] ?? '')) . '</option>';
    }
    return '<dialog class="cpr-dialog" data-cpr-dialog="item" aria-labelledby="cpr-item-title"><form method="dialog" class="cpr-dialog__form" data-cpr-form="item">'
      . '<input type="hidden" name="id" value=""><input type="hidden" name="section" value="achievement">'
      . '<header><div><span class="cpr-kicker" data-cpr-item-kicker>Nuevo punto</span><h2 id="cpr-item-title" data-cpr-item-heading>Agregar contenido</h2><p data-cpr-item-help>Completa la información que servirá como guía.</p></div><button type="button" class="cpr-close-button" data-cpr-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>'
      . '<div class="cpr-grid">'
      . '<label class="cpr-field is-wide"><span data-cpr-label-title>Título</span><input name="title" maxlength="300" required></label>'
      . '<label class="cpr-field is-wide"><span data-cpr-label-content>Resultado o diagnóstico</span><textarea name="content" rows="4" maxlength="12000"></textarea></label>'
      . '<label class="cpr-field is-wide" data-cpr-observation><span>Observación</span><textarea name="observation" rows="3" maxlength="12000"></textarea></label>'
      . '<label class="cpr-field" data-cpr-action-field><span>Mejora relacionada</span><select name="related_improvement_id">' . $related . '</select></label>'
      . '<label class="cpr-field" data-cpr-action-field><span>Responsable</span><select name="responsible_employee_id">' . $responsible . '</select></label>'
      . '<label class="cpr-field" data-cpr-action-field><span>Fecha límite</span><input type="date" name="due_date"></label>'
      . '</div><p class="cpr-form-error" data-cpr-form-error role="alert" hidden></p>'
      . '<footer><button type="button" class="cpr-ghost" data-cpr-close>Cancelar</button><button type="submit" class="cpr-primary">Guardar punto</button></footer>'
      . '</form></dialog>';
  }

  /** Confirmación interna para lo que cierra, archiva o elimina, y el motivo obligatorio de "No realizado". */
  private static function confirmDialog(): string
  {
    return '<dialog class="cpr-dialog is-small" data-cpr-dialog="confirm" aria-labelledby="cpr-confirm-title"><form method="dialog" class="cpr-dialog__form" data-cpr-form="confirm">'
      . '<header><div><span class="cpr-kicker">Confirmar</span><h2 id="cpr-confirm-title" data-cpr-confirm-text>¿Confirmar esta acción?</h2></div></header>'
      . '<label class="cpr-field" data-cpr-confirm-reason hidden><span>Motivo obligatorio</span><textarea name="reason" rows="3" maxlength="12000" placeholder="Explica qué impidió completar la acción"></textarea></label>'
      . '<p class="cpr-form-error" data-cpr-form-error role="alert" hidden></p>'
      . '<footer><button type="button" class="cpr-ghost" data-cpr-close>Cancelar</button><button type="submit" class="cpr-primary" data-cpr-confirm-accept>Confirmar</button></footer>'
      . '</form></dialog>';
  }

  private static function hero(string $icon, string $title, string $text, string $aside = ''): string
  {
    return '<header class="cpr-hero"><div class="cpr-hero__text"><span class="cpr-eyebrow"><span class="material-symbols-outlined" aria-hidden="true">' . self::e($icon) . '</span>Promoción</span><h1>' . self::e($title) . '</h1><p>' . self::e($text) . '</p></div>'
      . ($aside !== '' ? '<div class="cpr-hero__aside">' . $aside . '</div>' : '') . '</header>';
  }

  private static function emptyState(string $title, string $text, bool $compact = false): string
  {
    return '<div class="cpr-empty' . ($compact ? ' is-compact' : '') . '"><strong>' . self::e($title) . '</strong><p>' . self::e($text) . '</p></div>';
  }

  /** @param array<string,string> $labels */
  private static function statusChip(string $status, array $labels): string
  {
    return '<span class="cpr-chip status-' . self::statusClass($status) . '">' . self::e($labels[$status] ?? $status) . '</span>';
  }

  private static function statusClass(string $status): string
  {
    return preg_replace('/[^a-z_]/', '', $status) ?: 'draft';
  }

  private static function formatDate(string $date, bool $withTime = false): string
  {
    if ($date === '') {
      return 'Sin fecha';
    }
    $time = strtotime($date);
    return $time ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $time) : $date;
  }

  private static function plural(int $count, string $one, string $many): string
  {
    return $count . ' ' . ($count === 1 ? $one : $many);
  }

  private static function searchKey(string $value): string
  {
    $value = mb_strtolower($value, 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
  }

  /** @param array<string,mixed> $value */
  private static function json(array $value): string
  {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
