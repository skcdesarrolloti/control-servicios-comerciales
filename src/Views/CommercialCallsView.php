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
      . '<header class="ccl-head"><span class="scm-head-icon" aria-hidden="true"><span class="material-symbols-outlined">call</span></span><div><p class="ccl-eyebrow">Actividades comerciales</p><h1>Llamadas</h1><p>' . ($team ? 'Las llamadas de todo el equipo.' : 'Tus llamadas: las asignadas a ti y las que registraste.') . ' Cada llamada es una solicitud con su propio medio.</p></div>'
      . ($policy->canAct('llamadas_crear') ? '<button type="button" class="ccl-primary" data-ccl-new><span class="material-symbols-outlined" aria-hidden="true">add_call</span>Registrar llamada</button>' : '')
      . '</header>'
      . '<form class="ccl-filters" data-ccl-filters aria-label="Filtros de llamadas">'
      . '<label class="ccl-field ccl-field-wide"><span>Buscar</span><input type="search" name="ll_q" value="' . self::e($f['q']) . '" placeholder="Nombre, celular, correo o número de llamada"></label>'
      . $select('ll_medio', 'Medio', $list($options['medios_filtro']), $f['medio'], 'Todos')
      . $select('ll_tema', 'Motivo', $list($options['temas']), $f['tema'], 'Todos')
      . $select('ll_estado', 'Estado', ['pendientes' => 'Pendientes de crear tarea', 'con_tarea' => 'Con tarea', 'no_merece' => 'No merecen tarea'], $f['estado'], 'Todos')
      . $select('ll_comisionista', '¿Comisionista?', ['Si' => 'Comisionistas', 'No' => 'No comisionistas'], $f['comisionista'], 'Todos')
      . $select('ll_inmediato', '¿Cliente inmediato?', ['Si' => 'Inmediatos', 'No' => 'Para más adelante'], $f['inmediato'], 'Todos')
      . $select('ll_mes', 'Mes que necesita', $list($options['meses']), $f['mes'], 'Todos')
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
    foreach ([['ll_comisionista', 'comisionista', 'Si', 'Comisionistas', $counts['comisionistas']], ['ll_inmediato', 'inmediato', 'No', 'Para más adelante', $counts['mas_adelante']]] as [$name, $filter, $wanted, $label, $value]) {
      $on = $listing['filters'][$filter] === $wanted;
      $html .= '<button type="button" class="ccl-chip' . ($on ? ' is-active' : '') . '" data-ccl-toggle="' . $name . '" data-value="' . $wanted . '" aria-pressed="' . ($on ? 'true' : 'false') . '"><strong>' . number_format($value, 0, ',', '.') . '</strong>' . $label . '</button>';
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
        // Sin tarea y sin marcar que la merece (o marcada que no): igual se puede crear; al crearla queda que sí la merecía.
        $label = '<span class="ccl-muted">' . ($row['merece'] === 'No' ? 'No merecía' : 'Sin definir') . '</span>';
        $task = $canTask && $row['editable'] ? '<button type="button" class="ccl-task-button" data-ccl-task="' . $id . '">Crear tarea</button>' . $label : $label;
      }
      $actions = '<button type="button" class="ccl-icon" data-ccl-history="' . $id . '" aria-label="Historial del contacto de ' . self::e($row['cliente']) . '" title="Historial del contacto"><span class="material-symbols-outlined" aria-hidden="true">history</span></button>';
      if ($row['editable']) {
        $actions .= '<button type="button" class="ccl-icon" data-ccl-edit="' . $id . '" aria-label="Editar llamada de ' . self::e($row['cliente']) . '" title="Editar llamada"><span class="material-symbols-outlined" aria-hidden="true">edit</span></button>';
      }
      if ($canCreate) {
        $actions .= '<button type="button" class="ccl-icon" data-ccl-repeat="' . $id . '" aria-label="Nueva solicitud de ' . self::e($row['cliente']) . '" title="Nueva solicitud de este contacto"><span class="material-symbols-outlined" aria-hidden="true">add_call</span></button>';
      }
      $tags = ($row['comisionista'] ? '<span class="ccl-tag">Comisionista</span>' : '')
        . ($row['mas_adelante'] ? '<span class="ccl-tag is-later">' . self::e($row['mes'] !== '' ? 'Para ' . $row['mes'] : 'Para más adelante') . '</span>' : '');
      $html .= '<tr data-ccl-row="' . $id . '">'
        . '<td data-label="Fecha">' . self::e($row['fecha'] ?: 'Sin fecha') . '</td>'
        . '<td data-label="Contacto"><strong>' . self::e($row['cliente'] ?: 'Sin nombre') . '</strong><small>' . self::e($row['celular']) . '</small><small>' . self::e($row['email']) . '</small>' . ($tags !== '' ? '<div class="ccl-tags">' . $tags . '</div>' : '')
          // Una fila por persona: si tiene más solicitudes, se ven en su historial.
          . ((int) ($row['solicitudes'] ?? 1) > 1 ? '<button type="button" class="ccl-requests" data-ccl-history="' . $id . '" title="Ver todas sus solicitudes"><span class="material-symbols-outlined" aria-hidden="true">history</span>' . (int) $row['solicitudes'] . ' solicitudes</button>' : '')
          . '</td>'
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

  private static function input(string $name, string $label, string $value, string $type = 'text', bool $required = false, string $extra = '', string $class = ''): string
  {
    return '<label class="ccl-field ' . $class . '"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><input type="' . $type . '" name="' . $name . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . ' ' . $extra . '></label>';
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
    $editing = $mode === 'edit';
    $savedMedio = $v('medio');
    $lockedMedio = $editing && $savedMedio !== '';
    $pph = (string) $options['pph_medio'];
    $hasPartner = !in_array($v('id_pph'), ['', '0'], true);
    $titles = ['new' => ['Registrar llamada', 'Registra el contacto y el medio por el que llegó.'], 'repeat' => ['Nueva solicitud', 'Ya conocemos a este contacto. Elige el medio por el que llegó esta vez.'], 'edit' => ['Editar llamada #' . (int) ($call['_ID'] ?? 0), 'Aquí solo se corrigen los datos personales del contacto.']];
    [$title, $subtitle] = $titles[$mode];
    $origin = '';
    if ($lockedMedio) {
      $origin = '<div class="ccl-locked"><span class="material-symbols-outlined" aria-hidden="true">lock</span><div><strong>Medio: ' . self::e($savedMedio) . '</strong><p>El medio, el motivo, el inmueble y la asignación son de esta solicitud. Si algo de eso cambia, usa <strong>Nueva solicitud</strong> y quedará en su historial.</p></div></div>';
    } else {
      $origin = self::selectField('medio', 'Medio', $options['medios'], '', !$editing) . '<p class="ccl-hint">Revísalo bien: una vez guardado no se puede cambiar.</p>';
    }
    if ($lockedMedio && $hasPartner) {
      $origin .= '<p class="ccl-partner-box"><span class="material-symbols-outlined" aria-hidden="true">loyalty</span>Aliado Club PPH: <strong>' . self::e(trim($v('nombre_pph') . ' · ' . $v('tarjeta_pph'), ' ·')) . '</strong></p>';
    } elseif (!$hasPartner) {
      $origin .= '<div class="ccl-card" data-ccl-pph' . ($savedMedio === $pph ? '' : ' hidden') . '><label class="ccl-field"><span>Tarjeta del aliado Club PPH' . ($editing ? '' : ' <em aria-hidden="true">*</em>') . '</span><input type="text" name="tarjeta_pph" value="" autocomplete="off"></label><p class="ccl-hint" data-ccl-card-result role="status">El aliado queda ligado a este cliente para poder sumarle los puntos.</p></div>';
    }
    $html = self::modalHead($title, $subtitle)
      . '<form class="ccl-form" data-ccl-form data-pph-medio="' . self::e($pph) . '" novalidate>'
      . '<input type="hidden" name="id" value="' . ($mode === 'edit' ? (int) $call['_ID'] : 0) . '">'
      . '<div class="ccl-modal-body">'
      . '<p class="ccl-error" data-ccl-error role="alert" hidden></p>'
      . ($editing ? '' : self::smartFill())
      . '<h3>Contacto</h3><div class="ccl-grid-6">'
      . self::input('celular', 'Celular', $v('celular'), 'tel', true, 'inputmode="numeric" data-ccl-phone placeholder="Sin indicativo"')
      . self::input('cliente', 'Nombre', $v('cliente'), 'text', true, '', 'ccl-wide')
      . self::input('email', 'Correo', $v('email'), 'email', !$editing || $v('email') !== '', '', 'ccl-wide')
      . self::pairField('indicativo', 'País', $options['paises'], $v('indicativo'), !$editing || $v('indicativo') !== '')
      . self::input('celular_wsp', 'Celular WhatsApp', $v('celular_wsp'), 'tel', false, 'inputmode="numeric"')
      . self::input('usuario_wsp', 'Usuario WhatsApp', $v('usuario_wsp'), 'text', false, '', 'ccl-wide')
      . '</div>'
      . '<div class="ccl-repeat" data-ccl-contact role="status" hidden></div>'
      . '<h3>Origen</h3><div class="ccl-origin">' . $origin . '</div>';
    if ($editing) {
      $repeat = !empty($data['can_repeat']) ? '<button type="button" class="ccl-secondary" data-ccl-repeat="' . (int) $call['_ID'] . '">Nueva solicitud</button>' : '';
      return $html . '</div><footer class="ccl-modal-foot"><button type="button" class="ccl-secondary" data-ccl-close>Cancelar</button>' . $repeat . '<button type="submit" class="ccl-primary">Guardar cambios</button></footer></form>';
    }
    $money = 'inputmode="numeric" autocomplete="off" data-ccl-money';
    $employees = array_map(static fn(array $employee): array => ['value' => (string) $employee['id'], 'label' => (string) $employee['name']], $options['funcionarios']);
    // Una solicitud nueva de un contacto que ya mereció tarea lleva tarea sin preguntar; si nunca la mereció, se pregunta.
    $merit = $mode === 'repeat' && !empty($data['deserved'])
      ? '<input type="hidden" name="merece_ticket" value="Si"><p class="ccl-hint ccl-wide">Al guardar se abre su tarea para la persona asignada.</p>'
      : self::selectField('merece_ticket', '¿Merece tarea?', $yesNo, '', true);
    return $html
      . '<h3>Solicitud</h3><div class="ccl-grid">'
      . self::input('fecha', 'Fecha', (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d'), 'date', true)
      . self::selectField('tema_ayuda', 'Motivo', $options['temas'], '', true)
      // En captación se pregunta para qué ofrece el inmueble; queda en la descripción (la llamada no tiene columna propia).
      . '<div data-ccl-topic-field="captacion" hidden>' . self::selectField('oferta', '¿Para qué ofrece el inmueble?', array_keys(CommercialCallsService::CLIENT_TOPICS), '', true) . '</div>'
      . '<div data-ccl-topic-field="codigo" hidden>' . self::selectField('tiene_codigo', '¿Pregunta por un inmueble con código?', $yesNo, '', false) . '</div>'
      . '<div data-ccl-code-field hidden>' . self::input('id_inmueble', 'Código del inmueble', '', 'text', false, 'inputmode="numeric" data-ccl-property-code') . '</div>'
      . '</div>'
      . '<p class="ccl-hint" data-ccl-property-result role="status" hidden></p>'
      . '<div data-ccl-property-fields><div class="ccl-grid">'
      . self::selectField('tipo_inmueble', 'Tipo de inmueble', $options['tipos'], '', false)
      // En captación solo se piden tipo, uso y barrio: destinación y factores se ocultan (data-ccl-topic-field="detalle").
      . '<div data-ccl-topic-field="detalle">' . self::selectField('destinacion', 'Destinación', $options['destinaciones'], '', false) . '</div>'
      . self::selectField('uso', 'Uso', $options['usos'], '', false)
      . self::neighborhoodField('', $options['barrios'] ?? [], false)
      . '<div data-ccl-topic-field="presupuesto" hidden>' . self::selectField('tiene_presupuesto', '¿El cliente cuenta con presupuesto?', $yesNo, '', false) . '</div>'
      . '<div data-ccl-budget-field hidden>' . self::input('presupuesto', 'Presupuesto', '', 'text', false, $money) . '</div>'
      . '</div>'
      . '<div data-ccl-topic-field="detalle">' . self::checks('factores_importantes', 'Factores importantes', $options['factores'], $data['factores']) . '</div>'
      . '</div>'
      // Tipo de cliente va abajo y solo para quien busca en arriendo o venta (commercial-calls.js lo muestra y lo vuelve obligatorio).
      . '<div data-ccl-topic-field="cliente" hidden><h3>Tipo de cliente</h3><div class="ccl-grid">'
      . self::selectField('comisionista', '¿Es comisionista?', $yesNo, '', true)
      . self::selectField('cliente_inmediato', '¿Cliente inmediato?', $yesNo, '', true)
      . '<div data-ccl-month hidden>' . self::selectField('mes_inmueble', 'Mes en que necesita el inmueble', $options['meses'], '', false) . '</div>'
      . '</div></div>'
      // La descripción se arma sola con los datos de arriba mientras nadie la escriba a mano (commercial-calls.js).
      . '<div class="ccl-field"><div class="ccl-desc-head"><label for="ccl-descripcion">Descripción</label>'
      . '<button type="button" class="ccl-desc-compose" data-ccl-compose><span class="material-symbols-outlined" aria-hidden="true">auto_fix_high</span>Armar con los datos</button></div>'
      . '<textarea id="ccl-descripcion" name="descripcion" rows="3" data-ccl-auto-desc="1"></textarea>'
      . '<p class="ccl-hint">Se arma sola con lo que eliges arriba; si la escribes tú, se respeta lo tuyo.</p></div>'
      . '<h3>Asignación</h3><div class="ccl-grid">'
      . $merit
      . self::pairField('id_empleado', 'Asignar a', $employees, $v('id_empleado'), true)
      . '</div></div><footer class="ccl-modal-foot"><button type="button" class="ccl-secondary" data-ccl-close>Cancelar</button><button type="submit" class="ccl-primary">Guardar llamada</button></footer></form>';
  }

  /**
   * Barrio: se escoge de la lista (o se escribe si no está); con código del inmueble se toma el suyo y queda fijo.
   * @param list<string> $barrios
   */
  private static function neighborhoodField(string $value, array $barrios, bool $fromProperty): string
  {
    $options = '';
    foreach ($barrios as $barrio) {
      $options .= '<option value="' . self::e((string) $barrio) . '"></option>';
    }
    return '<label class="ccl-field"><span>Barrio</span><input type="text" name="barrio" value="' . self::e($value) . '" list="ccl-barrios" autocomplete="off" maxlength="120" placeholder="Escoge o escribe el barrio" data-ccl-barrio' . ($fromProperty ? ' readonly title="Es el barrio del inmueble"' : '') . '></label>'
      . '<datalist id="ccl-barrios">' . $options . '</datalist>';
  }

  /** Llenado inteligente: dictar o pegar lo que dijo el cliente y que la IA complete el formulario (commercial-calls.js). */
  private static function smartFill(): string
  {
    return '<section class="ccl-smart" data-ccl-smart aria-labelledby="ccl-smart-title">'
      . '<div class="ccl-smart-head"><span class="scm-head-icon" aria-hidden="true"><span class="material-symbols-outlined">auto_awesome</span></span>'
      . '<div><h3 id="ccl-smart-title">Llenado inteligente</h3><p>Dicta o pega lo que dijo el cliente y la IA completa el formulario. Marca no comisionista, cliente inmediato y que merece tarea; revisa todo antes de guardar.</p></div></div>'
      . '<label class="ccl-field"><span>Lo que dijo el cliente</span><textarea rows="3" data-ccl-smart-text placeholder="Ej.: Laura Gómez, interesada en arriendo de apartamento para vivienda en Laureles. Celular 300 123 4567, correo laura arroba gmail punto com. Presupuesto 2.500.000. Nos encontró en Facebook."></textarea></label>'
      . '<div class="ccl-smart-actions"><button type="button" class="ccl-secondary" data-ccl-dictate aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">mic</span><span data-ccl-dictate-label>Dictar</span></button>'
      . '<button type="button" class="ccl-primary" data-ccl-smart-fill><span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>Completar formulario</button>'
      . '<p class="ccl-hint" data-ccl-smart-status role="status" aria-live="polite"></p></div></section>';
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
      . '<div class="ccl-locked"><span class="material-symbols-outlined" aria-hidden="true">lock</span><div><strong>Medio: ' . self::e($v('medio') ?: 'Sin medio') . '</strong><p>La tarea conserva el medio de su llamada. El responsable se puede cambiar en Asignar a.</p></div></div>'
      . ($partner ? '<p class="ccl-partner-box"><span class="material-symbols-outlined" aria-hidden="true">loyalty</span>Aliado Club PPH: <strong>' . self::e(trim($v('nombre_pph') . ' · ' . $v('tarjeta_pph'), ' ·')) . '</strong></p>' : '')
      . '<h3>Solicitante</h3><div class="ccl-grid-6">'
      . self::input('solicitante', 'Nombre', $v('cliente'), 'text', true, '', 'ccl-wide')
      . self::input('correo_solicitante', 'Correo', $v('email'), 'email', false, '', 'ccl-wide')
      . self::input('celular_solicitante', 'Celular', $v('celular'), 'tel', true, 'inputmode="numeric"')
      . self::input('celular_wsp', 'Celular WhatsApp', $v('celular_wsp'), 'tel', false, 'inputmode="numeric"')
      . self::input('usuario_wsp', 'Usuario WhatsApp', $v('usuario_wsp'), 'text', false, '', 'ccl-wide')
      . '</div><h3>Tarea</h3><div class="ccl-grid">'
      // El responsable de la llamada viene puesto; si se cambia, la llamada queda con el nuevo responsable.
      . self::pairField('id_empleado', 'Asignar a', array_map(static fn(array $employee): array => ['value' => (string) $employee['id'], 'label' => (string) $employee['name']], $options['funcionarios']), $v('id_empleado'), true)
      . self::selectField('prioridad', 'Prioridad', $options['prioridades'], 'Prioridad comercial', true)
      . self::selectField('tema_ayuda', 'Tema', $options['temas'], $topic, true)
      . '<div data-ccl-topic-field="codigo" hidden>' . self::input('id_inmueble', 'Código del inmueble', $v('id_inmueble'), 'text', false, 'inputmode="numeric" data-ccl-property-code') . '<p class="ccl-hint" data-ccl-property-result role="status" hidden></p></div>'
      . self::selectField('tipo_inmueble', 'Tipo de inmueble', $options['tipos'], $v('tipo_inmueble'), false)
      . self::selectField('destinacion', 'Destinación', $options['destinaciones'], $v('destinacion'), false)
      . self::selectField('uso', 'Uso', $options['usos'], $v('uso'), false)
      . self::neighborhoodField($v('barrio'), $options['barrios'] ?? [], (int) $v('id_inmueble') > 0 && $v('barrio') !== '')
      . '<div data-ccl-topic-field="precio" hidden>' . self::input('precio', 'Precio', CommercialCallsService::money($v('precio')), 'text', false, 'inputmode="numeric" autocomplete="off" data-ccl-money') . '</div>'
      . '<div data-ccl-topic-field="presupuesto" hidden>' . self::selectField('tiene_presupuesto', '¿El cliente cuenta con presupuesto?', ['Si', 'No'], (int) $v('presupuesto') > 0 ? 'Si' : (str_contains((string) ($call['descripcion'] ?? ''), CommercialCallsService::NO_BUDGET_NOTE) ? 'No' : ''), false) . '</div>'
      . '<div data-ccl-budget-field hidden>' . self::input('presupuesto', 'Presupuesto', CommercialCallsService::money($v('presupuesto')), 'text', false, 'inputmode="numeric" autocomplete="off" data-ccl-money') . '</div>'
      . '</div><div data-ccl-topic-field="factores" hidden>' . self::checks('factores', 'Factores importantes', $options['factores'], $data['factores']) . '</div>'
      // El asunto se arma solo y corto con tema, tipo, barrio y presupuesto mientras nadie lo escriba (commercial-calls.js).
      . '<label class="ccl-field"><span>Asunto <em aria-hidden="true">*</em></span><input type="text" name="asunto" required maxlength="190" data-ccl-auto-subject="1"></label>'
      // Con la descripción de la llamada se respeta; sin ella el resumen se arma solo con los datos (commercial-calls.js).
      . '<div class="ccl-field"><div class="ccl-desc-head"><label for="ccl-task-resumen">Resumen <em aria-hidden="true">*</em></label>'
      . '<button type="button" class="ccl-desc-compose" data-ccl-compose><span class="material-symbols-outlined" aria-hidden="true">auto_fix_high</span>Armar con los datos</button></div>'
      . '<textarea id="ccl-task-resumen" name="descripcion" rows="4" required data-ccl-auto-desc="' . ($v('descripcion') === '' ? '1' : '0') . '">' . self::e($v('descripcion')) . '</textarea></div>'
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
