<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\CommercialCallsService;
use SCM\Commercial\CommercialAccessPolicy;

/** Actividades comerciales › Llamadas: listado con filtros, formulario de la llamada, tarea e historial del contacto. */
final class CommercialCallsView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /** @param array<string,mixed> $input */
  public static function page(array $input, CommercialCallsService $service, CommercialAccessPolicy $policy): string
  {
    $listing = $service->listing($input);
    $options = $service->options();
    $f = $listing['filters'];
    $team = $service->seesTeam();
    $select = static function (string $name, string $label, array $values, string $current, string $empty): string {
      $html = '<label class="ccl-field"><span>' . $label . '</span><select name="' . $name . '"><option value="">' . $empty . '</option>';
      foreach ($values as $value => $text) {
        $html .= '<option value="' . self::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
      }
      return $html . '</select></label>';
    };
    $list = static fn(array $values): array => array_combine($values, $values) ?: [];
    $employees = [];
    foreach ($options['funcionarios'] as $employee) {
      $employees[(string) $employee['id']] = (string) $employee['name'];
    }
    $html = '<section class="ccl" data-ccl-page>'
      . '<header class="ccl-head"><div><p class="ccl-eyebrow">Actividades comerciales</p><h1>Llamadas</h1><p>' . ($team ? 'Las llamadas de todo el equipo.' : 'Tus llamadas: las asignadas a ti y las que registraste.') . ' Cada llamada es una solicitud con su propio medio.</p></div>'
      . ($policy->canAct('llamadas_crear') ? '<button type="button" class="ccl-primary" data-ccl-new><span class="material-symbols-outlined" aria-hidden="true">add_call</span>Registrar llamada</button>' : '')
      . '</header>'
      . '<form class="ccl-filters" data-ccl-filters aria-label="Filtros de llamadas">'
      . '<label class="ccl-field ccl-field-wide"><span>Buscar</span><input type="search" name="ll_q" value="' . self::e($f['q']) . '" placeholder="Nombre, celular, correo o número de llamada"></label>'
      . $select('ll_medio', 'Medio', $list($options['medios_filtro']), $f['medio'], 'Todos')
      . $select('ll_tema', 'Motivo', $list($options['temas']), $f['tema'], 'Todos')
      . $select('ll_estado', 'Estado', ['pendientes' => 'Pendientes de crear tarea', 'con_tarea' => 'Con tarea', 'no_merece' => 'No merecen tarea'], $f['estado'], 'Todos')
      . ($team ? $select('ll_empleado', 'Responsable', $employees, $f['empleado'], 'Todo el equipo') : '')
      . '<label class="ccl-field"><span>Desde</span><input type="date" name="ll_desde" value="' . self::e($f['desde']) . '"></label>'
      . '<label class="ccl-field"><span>Hasta</span><input type="date" name="ll_hasta" value="' . self::e($f['hasta']) . '"></label>'
      . '<label class="ccl-field"><span>Aliado Club PPH</span><input type="search" name="ll_tarjeta" value="' . self::e($f['tarjeta']) . '" placeholder="Tarjeta o nombre"></label>'
      . '<div class="ccl-filter-actions"><button type="submit" class="ccl-primary">Filtrar</button><button type="button" class="ccl-secondary" data-ccl-clear>Limpiar</button></div>'
      . '</form>'
      . '<div data-ccl-results aria-live="polite">' . self::results($listing, $service, $policy) . '</div>'
      . '<div class="fixed inset-0 z-50 items-center justify-center p-3 sm:p-5 lg:p-7 bg-[#061D49]/50 backdrop-blur-md commercial-modal overflow-y-auto" id="commercial-call-modal" role="dialog" aria-modal="true" aria-labelledby="ccl-modal-title"><div class="ccl-modal" role="document" data-ccl-modal></div></div>'
      . '</section>';
    return $html;
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing, CommercialCallsService $service, CommercialAccessPolicy $policy): string
  {
    $counts = $listing['counts'];
    $current = $listing['filters']['estado'];
    $chips = [['', 'Llamadas', $counts['total']], ['pendientes', 'Pendientes de crear tarea', $counts['pendientes']], ['con_tarea', 'Con tarea', $counts['con_tarea']]];
    $html = '<div class="ccl-chips">';
    foreach ($chips as [$key, $label, $value]) {
      $html .= '<button type="button" class="ccl-chip' . ($current === $key ? ' is-active' : '') . '" data-ccl-state="' . $key . '" aria-pressed="' . ($current === $key ? 'true' : 'false') . '"><strong>' . number_format($value, 0, ',', '.') . '</strong>' . $label . '</button>';
    }
    $html .= '<span class="ccl-chip is-static"><strong>' . number_format($counts['pph'], 0, ',', '.') . '</strong>Clientes dados por Club PPH</span></div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="ccl-empty">No hay llamadas con estos filtros.</p>';
    }
    $canTask = $policy->canAct('llamadas_tarea');
    $canCreate = $policy->canAct('llamadas_crear');
    $canOpenTask = $policy->canAct('ver_ticket');
    $html .= '<div class="ccl-table-wrap"><table class="ccl-table"><thead><tr><th>Fecha</th><th>Contacto</th><th>Motivo</th><th>Medio</th><th>Responsable</th><th>Tarea</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $id = (int) $row['id'];
      $partner = $row['tarjeta'] !== '' || $row['aliado'] !== '' ? '<small class="ccl-partner">' . self::e(trim($row['aliado'] . ' · ' . $row['tarjeta'], ' ·')) . '</small>' : '';
      if ($row['tarea'] > 0) {
        $status = '<span class="ccl-status">' . self::e($row['tarea_estado'] ?: 'Sin estado') . '</span>';
        $task = ($canOpenTask ? '<button type="button" class="ccl-link" data-commercial-open-case="' . (int) $row['tarea'] . '">#' . (int) $row['tarea'] . '</button>' : '<span>#' . (int) $row['tarea'] . '</span>') . $status;
      } elseif ($row['merece'] === 'Si') {
        $task = $canTask && $row['editable'] ? '<button type="button" class="ccl-task-button" data-ccl-task="' . $id . '">Crear tarea</button>' : '<span class="ccl-status is-warning">Pendiente</span>';
      } else {
        $task = '<span class="ccl-muted">' . ($row['merece'] === 'No' ? 'No merece' : 'Sin definir') . '</span>';
      }
      $actions = '<button type="button" class="ccl-icon" data-ccl-history="' . $id . '" aria-label="Historial del contacto de ' . self::e($row['cliente']) . '" title="Historial del contacto"><span class="material-symbols-outlined" aria-hidden="true">history</span></button>';
      if ($row['editable']) {
        $actions .= '<button type="button" class="ccl-icon" data-ccl-edit="' . $id . '" aria-label="Editar llamada de ' . self::e($row['cliente']) . '" title="Editar llamada"><span class="material-symbols-outlined" aria-hidden="true">edit</span></button>';
      }
      if ($canCreate) {
        $actions .= '<button type="button" class="ccl-icon" data-ccl-repeat="' . $id . '" aria-label="Nueva solicitud de ' . self::e($row['cliente']) . '" title="Nueva solicitud de este contacto"><span class="material-symbols-outlined" aria-hidden="true">add_call</span></button>';
      }
      $html .= '<tr data-ccl-row="' . $id . '">'
        . '<td data-label="Fecha">' . self::e($row['fecha'] ?: 'Sin fecha') . '</td>'
        . '<td data-label="Contacto"><strong>' . self::e($row['cliente'] ?: 'Sin nombre') . '</strong><small>' . self::e($row['celular']) . '</small><small>' . self::e($row['email']) . '</small></td>'
        . '<td data-label="Motivo">' . self::e($row['tema']) . ($row['tipo'] !== '' || $row['inmueble'] !== '' ? '<small>' . self::e(trim($row['tipo'] . ($row['inmueble'] !== '' ? ' · #' . $row['inmueble'] : ''), ' ·')) . '</small>' : '') . '</td>'
        . '<td data-label="Medio">' . self::e($row['medio'] ?: 'Sin medio') . $partner . '</td>'
        . '<td data-label="Responsable">' . self::e($row['responsable'] ?: 'Sin asignar') . '</td>'
        . '<td data-label="Tarea"><div class="ccl-task">' . $task . '</div></td>'
        . '<td data-label="Acciones"><div class="ccl-actions">' . $actions . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="ccl-pager" aria-label="Páginas de llamadas"><button type="button" class="ccl-secondary" data-ccl-page-to="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="ccl-secondary" data-ccl-page-to="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }

  /** @param list<string> $values */
  private static function selectField(string $name, string $label, array $values, string $current, bool $required, string $class = ''): string
  {
    if ($current !== '' && !in_array($current, $values, true)) {
      $values[] = $current;
    }
    $html = '<label class="ccl-field ' . $class . '"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><select name="' . $name . '"' . ($required ? ' required' : '') . '><option value="">Elige una opción</option>';
    foreach ($values as $value) {
      $html .= '<option value="' . self::e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . self::e($value) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param list<array{value:string,label:string}> $pairs */
  private static function pairField(string $name, string $label, array $pairs, string $current, bool $required): string
  {
    $html = '<label class="ccl-field"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><select name="' . $name . '"' . ($required ? ' required' : '') . '><option value="">Elige una opción</option>';
    foreach ($pairs as $pair) {
      $html .= '<option value="' . self::e($pair['value']) . '"' . ($pair['value'] === $current ? ' selected' : '') . '>' . self::e($pair['label']) . '</option>';
    }
    return $html . '</select></label>';
  }

  private static function input(string $name, string $label, string $value, string $type = 'text', bool $required = false, string $extra = ''): string
  {
    return '<label class="ccl-field"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><input type="' . $type . '" name="' . $name . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . ' ' . $extra . '></label>';
  }

  /**
   * @param list<string> $values
   * @param list<string> $checked
   */
  private static function checks(string $name, string $label, array $values, array $checked): string
  {
    if ($values === [] && $checked === []) {
      return '';
    }
    $html = '<fieldset class="ccl-checks"><legend>' . $label . '</legend>';
    foreach (array_values(array_unique(array_merge($values, $checked))) as $value) {
      $html .= '<label><input type="checkbox" name="' . $name . '[]" value="' . self::e($value) . '"' . (in_array($value, $checked, true) ? ' checked' : '') . '><span>' . self::e($value) . '</span></label>';
    }
    return $html . '</fieldset>';
  }

  private static function modalHead(string $title, string $subtitle): string
  {
    return '<header class="ccl-modal-head"><div><h2 id="ccl-modal-title">' . self::e($title) . '</h2><p>' . self::e($subtitle) . '</p></div><button type="button" class="ccl-icon" data-ccl-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>';
  }

  /**
   * @param array<string,mixed> $data
   * @param array<string,mixed> $options
   */
  public static function form(array $data, array $options): string
  {
    $call = $data['call'];
    $mode = $data['mode'];
    $v = static fn(string $key): string => trim((string) ($call[$key] ?? ''));
    $yesNo = ['Si', 'No'];
    $hasTask = (bool) $data['has_task'];
    $savedMedio = $v('medio');
    $lockedMedio = $mode === 'edit' && $savedMedio !== '';
    $pph = (string) $options['pph_medio'];
    $hasPartner = !in_array($v('id_pph'), ['', '0'], true);
    $titles = ['new' => ['Registrar llamada', 'Registra el contacto y el medio por el que llegó.'], 'repeat' => ['Nueva solicitud', 'Ya conocemos a este contacto. Elige el medio por el que llegó esta vez.'], 'edit' => ['Editar llamada #' . (int) ($call['_ID'] ?? 0), 'El medio no se cambia para no alterar las estadísticas.']];
    [$title, $subtitle] = $titles[$mode];
    $origin = '';
    if ($lockedMedio) {
      $origin = '<div class="ccl-locked"><span class="material-symbols-outlined" aria-hidden="true">lock</span><div><strong>Medio: ' . self::e($savedMedio) . '</strong><p>Si esta persona llegó por otro medio, es otra solicitud: usa <strong>Nueva solicitud</strong> y quedará en su historial.</p></div></div>';
    } else {
      $origin = self::selectField('medio', 'Medio', $options['medios'], '', true) . '<p class="ccl-hint">Revísalo bien: una vez guardado no se puede cambiar.</p>';
    }
    if ($lockedMedio && $hasPartner) {
      $origin .= '<p class="ccl-partner-box"><span class="material-symbols-outlined" aria-hidden="true">loyalty</span>Aliado Club PPH: <strong>' . self::e(trim($v('nombre_pph') . ' · ' . $v('tarjeta_pph'), ' ·')) . '</strong></p>';
    } elseif (!$hasPartner) {
      $origin .= '<div class="ccl-card" data-ccl-pph' . ($savedMedio === $pph ? '' : ' hidden') . '><label class="ccl-field"><span>Tarjeta del aliado Club PPH <em aria-hidden="true">*</em></span><input type="text" name="tarjeta_pph" value="" autocomplete="off"></label><p class="ccl-hint" data-ccl-card-result role="status">El aliado queda ligado a este cliente para poder sumarle los puntos.</p></div>';
    }
    $html = self::modalHead($title, $subtitle)
      . '<form class="ccl-form" data-ccl-form data-pph-medio="' . self::e($pph) . '" novalidate>'
      . '<input type="hidden" name="id" value="' . ($mode === 'edit' ? (int) $call['_ID'] : 0) . '">'
      . '<div class="ccl-modal-body">'
      . '<p class="ccl-error" data-ccl-error role="alert" hidden></p>'
      . '<h3>Contacto</h3><div class="ccl-grid">'
      . self::input('celular', 'Celular', $v('celular'), 'tel', true, 'inputmode="numeric" data-ccl-phone placeholder="Sin indicativo"')
      . self::input('cliente', 'Nombre', $v('cliente'), 'text', true)
      . self::input('email', 'Correo', $v('email'), 'email', true)
      . self::pairField('indicativo', 'País', $options['paises'], $v('indicativo'), true)
      . self::input('celular_wsp', 'Celular WhatsApp', $v('celular_wsp'), 'tel', false, 'inputmode="numeric"')
      . self::input('usuario_wsp', 'Usuario WhatsApp', $v('usuario_wsp'))
      . '</div>'
      . '<div class="ccl-repeat" data-ccl-contact role="status" hidden></div>'
      . '<h3>Origen</h3><div class="ccl-origin">' . $origin . '</div>'
      . '<h3>Solicitud</h3><div class="ccl-grid">'
      . ($mode === 'edit' ? '' : self::input('fecha', 'Fecha', (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d'), 'date', true))
      . self::selectField('tema_ayuda', 'Motivo', $options['temas'], $v('tema_ayuda'), true)
      . self::selectField('comisionista', '¿Es comisionista?', $yesNo, $v('comisionista'), true)
      . self::selectField('cliente_inmediato', '¿Cliente inmediato?', $yesNo, $v('cliente_inmediato'), true)
      . '<div data-ccl-month' . ($v('cliente_inmediato') === 'No' ? '' : ' hidden') . '>' . self::selectField('mes_inmueble', 'Mes en que necesita el inmueble', $options['meses'], $v('mes_inmueble'), false) . '</div>'
      . self::selectField('tipo_inmueble', 'Tipo de inmueble', $options['tipos'], $v('tipo_inmueble'), false)
      . self::selectField('destinacion', 'Destinación', $options['destinaciones'], $v('destinacion'), false)
      . self::selectField('uso', 'Uso', $options['usos'], $v('uso'), false)
      . self::input('id_inmueble', 'Código del inmueble', $v('id_inmueble'), 'text', false, 'inputmode="numeric"')
      . self::input('presupuesto', 'Presupuesto', $v('presupuesto'), 'text', false, 'inputmode="numeric"')
      . self::input('precio', 'Precio', $v('precio'), 'text', false, 'inputmode="numeric"')
      . self::selectField('observacion', 'Observación', $options['observaciones'], $v('observacion'), false)
      . '</div>'
      . self::checks('factores_importantes', 'Factores importantes', $options['factores'], $data['factores'])
      . '<label class="ccl-field"><span>Descripción</span><textarea name="descripcion" rows="3">' . self::e($v('descripcion')) . '</textarea></label>'
      . '<h3>Asignación</h3><div class="ccl-grid">';
    if ($hasTask) {
      $html .= '<p class="ccl-hint ccl-span">La llamada ya tiene tarea: el responsable y si merece tarea se gestionan desde la tarea.</p>';
    } else {
      $html .= self::selectField('merece_ticket', '¿Merece tarea?', $yesNo, $v('merece_ticket'), true);
      $employees = array_map(static fn(array $employee): array => ['value' => (string) $employee['id'], 'label' => (string) $employee['name']], $options['funcionarios']);
      $html .= self::pairField('id_empleado', 'Asignar a', $employees, $v('id_empleado'), true);
    }
    return $html . '</div></div><footer class="ccl-modal-foot"><button type="button" class="ccl-secondary" data-ccl-close>Cancelar</button><button type="submit" class="ccl-primary">' . ($mode === 'edit' ? 'Guardar cambios' : 'Guardar llamada') . '</button></footer></form>';
  }

  /**
   * @param array<string,mixed> $data
   * @param array<string,mixed> $options
   */
  public static function taskForm(array $data, array $options): string
  {
    $call = $data['call'];
    $v = static fn(string $key): string => trim((string) ($call[$key] ?? ''));
    $partner = !in_array($v('id_pph'), ['', '0'], true);
    $topic = $v('tema_ayuda');
    return self::modalHead('Crear tarea', 'Llamada #' . (int) $call['_ID'] . ' · ' . $v('cliente'))
      . '<form class="ccl-form" data-ccl-task-form novalidate><input type="hidden" name="id" value="' . (int) $call['_ID'] . '">'
      . '<div class="ccl-modal-body"><p class="ccl-error" data-ccl-error role="alert" hidden></p>'
      . '<div class="ccl-locked"><span class="material-symbols-outlined" aria-hidden="true">lock</span><div><strong>Medio: ' . self::e($v('medio') ?: 'Sin medio') . '</strong><p>Asignada a ' . self::e($data['responsable'] ?: 'sin responsable') . '. La tarea conserva el medio de su llamada.</p></div></div>'
      . ($partner ? '<p class="ccl-partner-box"><span class="material-symbols-outlined" aria-hidden="true">loyalty</span>Aliado Club PPH: <strong>' . self::e(trim($v('nombre_pph') . ' · ' . $v('tarjeta_pph'), ' ·')) . '</strong></p>' : '')
      . '<h3>Solicitante</h3><div class="ccl-grid">'
      . self::input('solicitante', 'Nombre', $v('cliente'), 'text', true)
      . self::input('correo_solicitante', 'Correo', $v('email'), 'email')
      . self::input('celular_solicitante', 'Celular', $v('celular'), 'tel', true, 'inputmode="numeric"')
      . self::input('celular_wsp', 'Celular WhatsApp', $v('celular_wsp'), 'tel', false, 'inputmode="numeric"')
      . self::input('usuario_wsp', 'Usuario WhatsApp', $v('usuario_wsp'))
      . '</div><h3>Tarea</h3><div class="ccl-grid">'
      . self::selectField('prioridad', 'Prioridad', $options['prioridades'], 'Prioridad comercial', true)
      . self::selectField('tema_ayuda', 'Tema', $options['temas'], $topic, true)
      . '<div data-ccl-task-field="codigo">' . self::input('id_inmueble', 'Código del inmueble', $v('id_inmueble'), 'text', false, 'inputmode="numeric"') . '</div>'
      . self::selectField('tipo_inmueble', 'Tipo de inmueble', $options['tipos'], $v('tipo_inmueble'), false)
      . self::selectField('destinacion', 'Destinación', $options['destinaciones'], $v('destinacion'), false)
      . self::selectField('uso', 'Uso', $options['usos'], $v('uso'), false)
      . '<div data-ccl-task-field="precio" hidden>' . self::input('precio', 'Precio', $v('precio'), 'text', false, 'inputmode="numeric"') . '</div>'
      . '<div data-ccl-task-field="presupuesto" hidden>' . self::input('presupuesto', 'Presupuesto', $v('presupuesto'), 'text', false, 'inputmode="numeric"') . '</div>'
      . '</div><div data-ccl-task-field="factores" hidden>' . self::checks('factores', 'Factores importantes', $options['factores'], $data['factores']) . '</div>'
      . '<label class="ccl-field"><span>Asunto <em aria-hidden="true">*</em></span><input type="text" name="asunto" required maxlength="190"></label>'
      . '<label class="ccl-field"><span>Resumen <em aria-hidden="true">*</em></span><textarea name="descripcion" rows="4" required>' . self::e($v('descripcion')) . '</textarea></label>'
      . '</div><footer class="ccl-modal-foot"><button type="button" class="ccl-secondary" data-ccl-close>Crear más tarde</button><button type="submit" class="ccl-primary">Crear tarea</button></footer></form>';
  }

  /** @param array<string,mixed> $history */
  public static function history(array $history, CommercialAccessPolicy $policy): string
  {
    $total = count($history['items']);
    $html = self::modalHead('Historial del contacto', ($history['contact']['cliente'] ?? '') . ' · ' . $total . ($total === 1 ? ' solicitud' : ' solicitudes'))
      . '<div class="ccl-modal-body">';
    if ($total === 0) {
      return $html . '<p class="ccl-empty">Este contacto no tiene solicitudes registradas.</p></div>';
    }
    $html .= '<ol class="ccl-history">';
    foreach ($history['items'] as $item) {
      $task = $item['tarea'] > 0
        ? ($policy->canAct('ver_ticket') && $item['propia'] ? '<button type="button" class="ccl-link" data-commercial-open-case="' . (int) $item['tarea'] . '">Tarea #' . (int) $item['tarea'] . '</button>' : '<span>Tarea #' . (int) $item['tarea'] . '</span>') . ($item['tarea_estado'] !== '' ? ' <span class="ccl-status">' . self::e($item['tarea_estado']) . '</span>' : '')
        : '<span class="ccl-muted">Sin tarea</span>';
      $html .= '<li><div class="ccl-history-top"><span class="ccl-history-date">' . self::e($item['fecha'] ?: 'Sin fecha') . '</span><strong>' . self::e($item['tema'] ?: $item['tipo']) . '</strong><span class="ccl-medio">' . self::e($item['medio'] ?: 'Sin medio') . '</span></div>'
        . '<p class="ccl-history-meta">' . self::e($item['tipo']) . ' · ' . self::e($item['responsable'] ?: 'Sin asignar') . ($item['aliado'] !== '' ? ' · Aliado: ' . self::e($item['aliado']) : '') . '</p>'
        . ($item['descripcion'] !== '' ? '<p class="ccl-history-text">' . self::e($item['descripcion']) . '</p>' : '')
        . '<div class="ccl-task">' . $task . '</div></li>';
    }
    $html .= '</ol></div>';
    if ($policy->canAct('llamadas_crear') && $history['last_id'] > 0) {
      $html .= '<footer class="ccl-modal-foot"><button type="button" class="ccl-primary" data-ccl-repeat="' . (int) $history['last_id'] . '">Nueva solicitud</button></footer>';
    }
    return $html;
  }
}
