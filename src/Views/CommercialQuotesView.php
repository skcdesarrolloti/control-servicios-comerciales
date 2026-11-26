<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\PropertySearchFilters;
use SCM\Commercial\Quotes\CommercialQuotesService;
use SCM\Commercial\Quotes\QuoteComparison;
use SCM\Commercial\Quotes\QuotePdf;

/**
 * Cotizaciones de inmuebles en el panel: formulario del popup de la tarea (datos del cliente, buscador de inmuebles y
 * seleccionados), confirmación, visor de la cotización y la pestaña Cotizaciones / Mis cotizaciones.
 */
final class CommercialQuotesView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  /** @param array<int|string,string> $values valor => texto */
  private static function select(string $name, string $label, array $values, string $current, string $empty, bool $required = false, string $extra = ''): string
  {
    $html = '<label class="cqt-field"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><select name="' . $name . '"' . ($required ? ' required' : '') . $extra . '><option value="">' . $empty . '</option>';
    foreach ($values as $value => $text) {
      $html .= '<option value="' . self::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param list<string> $values @return array<int|string,string> */
  private static function pairs(array $values): array
  {
    return $values === [] ? [] : array_combine($values, $values);
  }

  // ── Popup de la tarea ───────────────────────────────

  /** @param array<string,mixed> $data CommercialQuotesService::formData() */
  public static function taskForm(array $data): string
  {
    $pk = (int) $data['ticket_pk'];
    $formId = 'cqt-create-' . $pk;
    $options = $data['options'];
    $types = self::pairs(CommercialQuotesService::TYPES);
    $yesNo = ['Si' => 'Sí', 'No' => 'No'];
    $html = '<section class="cqt cqt-task" data-cqt-task data-ticket-pk="' . $pk . '" data-max="' . (int) $data['max'] . '">'
      . '<header class="cqt-task-head"><button type="button" class="cqt-back" data-commercial-return-case="' . $pk . '">' . self::icon('arrow_back') . 'Volver a la tarea</button>'
      . '<div><p class="cqt-eyebrow">Tarea #' . $pk . ($data['asunto'] !== '' ? ' · ' . self::e($data['asunto']) : '') . '</p><h1 tabindex="-1">Cotización de inmuebles</h1>'
      . '<p>Elige los inmuebles, revisa los datos del cliente y envíala: le llega por correo y WhatsApp con fotos y la comparación entre ellos.</p></div>'
      . '<button type="button" class="cqt-icon" data-commercial-close-case aria-label="Cerrar">' . self::icon('close') . '</button></header>'
      . '<div class="cqt-task-body"><div class="cqt-main">'
      . '<form class="cqt-panel" id="' . $formId . '" data-cqt-create novalidate><input type="hidden" name="ticket_pk" value="' . $pk . '">'
      . '<h2><span class="cqt-step">1</span>Datos del cliente</h2><div class="cqt-grid">'
      . self::select('tipo', 'Tipo de cotización', $types, (string) $data['tipo'], 'Elige una opción', true, ' data-cqt-type')
      . '<label class="cqt-field"><span>Cliente <em aria-hidden="true">*</em></span><input name="cliente" value="' . self::e($data['cliente']) . '" maxlength="120" required autocomplete="off"></label>'
      . '<label class="cqt-field"><span>Correo</span><input type="email" name="email" value="' . self::e($data['email']) . '" maxlength="160" autocomplete="off"></label>'
      . '<label class="cqt-field"><span>Celular (WhatsApp)</span><input type="tel" name="celular" value="' . self::e($data['celular']) . '" maxlength="30" autocomplete="off"></label>'
      . '<label class="cqt-field cqt-span"><span>Observaciones para el cliente</span><textarea name="observaciones" rows="2" maxlength="2000" placeholder="Opcional: aparece en la cotización"></textarea></label>'
      . '</div></form>'
      . '<section class="cqt-panel" aria-labelledby="cqt-search-title-' . $pk . '"><h2 id="cqt-search-title-' . $pk . '"><span class="cqt-step">2</span>Elige los inmuebles</h2>'
      . '<p class="cqt-hint">Solo salen inmuebles públicos. Los que tienen la actualización vencida no se pueden cotizar hasta actualizarlos.</p>'
      . '<form class="cqt-filters" data-cqt-search role="search">'
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="q" placeholder="Código, barrio, conjunto o tipo"></label>'
      . self::select('tipo_inmueble', 'Tipo de inmueble', self::pairs($options['tipos_inmueble']), '', 'Todos')
      . self::select('barrio', 'Barrio', self::pairs($options['barrios']), '', 'Todos')
      . self::select('ciudad', 'Ciudad', self::pairs($options['ciudades']), '', 'Todas')
      . '<label class="cqt-field"><span>Precio desde</span><input name="precio_min" inputmode="numeric" data-cqt-money placeholder="$"></label>'
      . '<label class="cqt-field"><span>Precio hasta</span><input name="precio_max" inputmode="numeric" data-cqt-money placeholder="$"></label>'
      . '<label class="cqt-field"><span>Área desde (m²)</span><input name="area_min" inputmode="numeric" placeholder="m²"></label>'
      . '<label class="cqt-field"><span>Área hasta (m²)</span><input name="area_max" inputmode="numeric" placeholder="m²"></label>'
      . self::select('habitaciones', 'Habitaciones', PropertySearchFilters::MIN_OPTIONS, '', 'Cualquiera')
      . self::select('banos', 'Baños', PropertySearchFilters::MIN_OPTIONS, '', 'Cualquiera')
      . self::select('parqueaderos', 'Parqueaderos', PropertySearchFilters::MIN_OPTIONS, '', 'Cualquiera')
      . self::select('estrato', 'Estrato', self::pairs($options['estratos'] ?? []), '', 'Todos')
      . self::select('destinacion', 'Destinación', self::pairs($options['destinaciones'] ?? []), '', 'Todas')
      . self::select('edad_max', 'Antigüedad', PropertySearchFilters::AGE_OPTIONS, '', 'Cualquiera')
      . self::select('amoblado', 'Amoblado', $yesNo, '', 'Todos')
      . self::select('acepta_permuta', 'Acepta permuta', $yesNo, '', 'Todos')
      . self::select('tipo_permuta', 'Qué recibe en permuta', self::pairs($options['tipos_permuta'] ?? []), '', 'Cualquiera')
      . self::select('deposito', 'Depósito', $yesNo, '', 'Todos')
      . self::select('vigilancia', 'Vigilancia', $yesNo, '', 'Todos')
      . self::select('negociable', 'Negociable', $yesNo, '', 'Todos')
      . self::select('orden', 'Ordenar', ['precio_asc' => 'Menor precio', 'precio_desc' => 'Mayor precio', 'area' => 'Mayor área'], '', 'Más recientes')
      . '<label class="cqt-check"><input type="checkbox" name="ocultar_vencidos" value="1"> Ocultar los vencidos</label>'
      . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">' . self::icon('search') . 'Buscar</button><button type="reset" class="cqt-secondary">Limpiar</button></div>'
      . '</form><div class="cqt-results" data-cqt-results aria-live="polite"><p class="cqt-empty">Cargando inmuebles…</p></div></section>'
      . '</div>'
      . '<aside class="cqt-tray" aria-labelledby="cqt-tray-title-' . $pk . '"><div class="cqt-tray-in">'
      . '<h2 id="cqt-tray-title-' . $pk . '">Seleccionados <span data-cqt-count>0</span>/' . (int) $data['max'] . '</h2>'
      . '<p class="cqt-empty cqt-tray-empty" data-cqt-tray-empty>Agrega inmuebles desde los resultados. Puedes cotizar varios a la vez.</p>'
      . '<ol class="cqt-selected" data-cqt-selected></ol>'
      . '<p class="cqt-error" data-cqt-error role="alert" hidden></p>'
      . '<button type="submit" form="' . $formId . '" class="cqt-primary cqt-send" data-cqt-send disabled>' . self::icon('send') . 'Crear y enviar cotización</button>'
      . '<p class="cqt-hint">Se envía al cliente por correo y WhatsApp, queda en el historial de la tarea y en el de cada inmueble.</p>';
    if ($data['previous'] !== []) {
      $html .= '<div class="cqt-previous"><h3>Cotizaciones de esta tarea</h3><ul>';
      foreach ($data['previous'] as $row) {
        $html .= '<li><span><strong>#' . (int) $row['id'] . '</strong> · ' . self::e($row['fecha']) . '<small>' . count($row['codigos']) . ' ' . (count($row['codigos']) === 1 ? 'inmueble' : 'inmuebles') . ($row['tipo'] !== '' ? ' en ' . self::e(mb_strtolower($row['tipo'], 'UTF-8')) : '') . '</small></span>'
          . '<button type="button" class="cqt-link" data-cqt-view="' . (int) $row['id'] . '" data-ticket-pk="' . $pk . '">Ver</button></li>';
      }
      $html .= '</ul></div>';
    }
    return $html . '</div></aside></div></section>';
  }

  /** @param array{items:list<array<string,mixed>>,total:int,page:int,pages:int,vencidos:int} $result */
  public static function propertyResults(array $result): string
  {
    if ($result['items'] === []) {
      return '<p class="cqt-empty">No hay inmuebles públicos con estos filtros.</p>';
    }
    $html = '<p class="cqt-summary">' . number_format($result['total'], 0, ',', '.') . ' ' . ($result['total'] === 1 ? 'inmueble' : 'inmuebles')
      . ($result['vencidos'] > 0 ? ' · <span class="cqt-warn">' . number_format($result['vencidos'], 0, ',', '.') . ' con la actualización vencida</span>' : '') . '</p><div class="cqt-cards">';
    foreach ($result['items'] as $item) {
      $code = (string) $item['codigo'];
      $place = implode(', ', array_filter([$item['barrio'], $item['ciudad']]));
      $label = $item['tipo_inmueble'] . ($place !== '' ? ' en ' . $place : '');
      $price = $item['precio'] > 0 ? QuoteComparison::money((float) $item['precio']) : 'Precio a consultar';
      $specs = array_filter([
        $item['area'] > 0 ? QuoteComparison::area((float) $item['area']) : '',
        $item['habitaciones'] > 0 ? $item['habitaciones'] . ' hab.' : '',
        $item['banos'] > 0 ? $item['banos'] . ' baños' : '',
        $item['parqueaderos'] > 0 ? $item['parqueaderos'] . ' parq.' : '',
        $item['estrato'] > 0 ? 'Estrato ' . $item['estrato'] : '',
      ]);
      $state = $item['estado_actualizacion'];
      $html .= '<article class="cqt-prop' . ($item['quotable'] ? '' : ' is-blocked') . '" data-cqt-prop data-code="' . self::e($code) . '" data-label="' . self::e($label) . '" data-price="' . self::e($price) . '" data-photo="' . self::e($item['foto']) . '">'
        . '<div class="cqt-photo">' . ($item['foto'] !== '' ? '<img src="' . self::e($item['foto']) . '" alt="" loading="lazy">' : self::icon('apartment'))
        . '<span class="cqt-code">#' . self::e($code) . '</span>'
        . ($item['quotable'] && $state['status'] === 'alerta' ? '<span class="cqt-badge is-alert" title="' . self::e($state['days'] . ' días sin actualizar') . '">Por vencer</span>' : '') . '</div>'
        . '<div class="cqt-prop-body"><p class="cqt-price">' . self::e($price) . ($item['precio_tipo'] !== '' ? ' <small>' . self::e(mb_strtolower($item['precio_tipo'], 'UTF-8')) . '</small>' : '') . '</p>'
        . '<p class="cqt-prop-title">' . self::e($label) . '</p>'
        . ($specs !== [] ? '<p class="cqt-specs">' . self::e(implode(' · ', $specs)) . '</p>' : '');
      if ($item['quotable']) {
        $html .= '<div class="cqt-prop-actions"><button type="button" class="cqt-add" data-cqt-toggle aria-pressed="false" aria-label="Agregar ' . self::e($label . ', código ' . $code) . '">' . self::icon('add') . '<span>Agregar</span></button>'
          . '<a class="cqt-link" href="' . self::e($item['url_portal']) . '" target="_blank" rel="noopener noreferrer">Ver ficha</a></div>';
      } else {
        $html .= '<div class="cqt-blocked" role="note">' . self::icon('warning') . '<div><strong>Actualización vencida</strong><span>' . (int) $state['days'] . ' días sin actualizar. No se puede cotizar hasta actualizarlo.</span>'
          . ($item['url_actualizar'] !== '' ? '<a href="' . self::e($item['url_actualizar']) . '" target="_blank" rel="noopener noreferrer">Ir a actualizar</a>' : '') . '</div></div>';
      }
      $html .= '</div></article>';
    }
    $html .= '</div>';
    if ($result['pages'] > 1) {
      $page = $result['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de inmuebles"><button type="button" class="cqt-secondary" data-cqt-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $result['pages'] . '</span><button type="button" class="cqt-secondary" data-cqt-page="' . ($page + 1) . '"' . ($page >= $result['pages'] ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }

  /** @param array{id:int,url:string,message:string,enviado:array{correo:bool,whatsapp:bool}} $result */
  public static function created(array $result, int $ticketPk): string
  {
    $id = (int) $result['id'];
    $channels = [];
    foreach (['correo' => ['mail', 'Correo'], 'whatsapp' => ['chat', 'WhatsApp']] as $key => [$icon, $label]) {
      $channels[] = '<li class="' . ($result['enviado'][$key] ? 'is-ok' : 'is-off') . '">' . self::icon($result['enviado'][$key] ? 'check_circle' : 'cancel') . $label . ($result['enviado'][$key] ? ' en camino' : ' sin enviar') . '</li>';
    }
    return '<section class="cqt cqt-task cqt-done" data-cqt-done><div class="cqt-done-card">'
      . '<span class="cqt-done-icon">' . self::icon('task_alt') . '</span><h1 tabindex="-1">Cotización #' . $id . ' creada</h1><p>' . self::e($result['message']) . '</p>'
      . '<ul class="cqt-channels">' . implode('', $channels) . '</ul>'
      . '<div class="cqt-done-actions"><button type="button" class="cqt-primary" data-cqt-view="' . $id . '" data-ticket-pk="' . $ticketPk . '">' . self::icon('visibility') . 'Ver cotización</button>'
      . '<button type="button" class="cqt-secondary" data-cqt-copy="' . self::e($result['url']) . '">' . self::icon('content_copy') . 'Copiar enlace</button>'
      . '<a class="cqt-secondary" href="' . self::e($result['url'] . '&formato=pdf') . '" download="' . self::e(QuotePdf::filename($id)) . '" data-scm-loader-download="' . self::e(QuotePdf::filename($id)) . '" data-scm-loader-title="Generando el PDF" data-scm-loader-detail="Estamos preparando la cotización con las fotos de cada inmueble. Puede tardar unos segundos.">' . self::icon('picture_as_pdf') . 'Descargar PDF</a>'
      . '<button type="button" class="cqt-secondary" data-commercial-return-case="' . $ticketPk . '">' . self::icon('arrow_back') . 'Volver a la tarea</button></div>'
      . '</div></section>';
  }

  /** Popup con la cotización tal como la ve el cliente. */
  public static function viewer(int $id, string $url): string
  {
    return '<div class="cqt-viewer-box" role="document"><header class="cqt-viewer-head"><h2 id="cqt-viewer-title">Cotización #' . $id . '</h2><div class="cqt-viewer-actions">'
      . '<button type="button" class="cqt-secondary" data-cqt-copy="' . self::e($url) . '">' . self::icon('content_copy') . 'Copiar enlace</button>'
      . '<a class="cqt-secondary" href="' . self::e($url . '&formato=pdf') . '" download="' . self::e(QuotePdf::filename($id)) . '" data-scm-loader-download="' . self::e(QuotePdf::filename($id)) . '" data-scm-loader-title="Generando el PDF" data-scm-loader-detail="Estamos preparando la cotización con las fotos de cada inmueble. Puede tardar unos segundos.">' . self::icon('picture_as_pdf') . 'PDF</a>'
      . '<a class="cqt-secondary" href="' . self::e($url) . '" target="_blank" rel="noopener">' . self::icon('open_in_new') . 'Abrir</a>'
      . '<button type="button" class="cqt-icon" data-cqt-viewer-close aria-label="Cerrar">' . self::icon('close') . '</button></div></header>'
      . '<iframe class="cqt-frame" src="' . self::e($url) . '" title="Cotización #' . $id . ' como la ve el cliente"></iframe></div>';
  }

  // ── Pestaña Cotizaciones ─────────────────────────────

  /** @param array<string,mixed> $input */
  public static function page(array $input, CommercialQuotesService $service, CommercialAccessPolicy $policy): string
  {
    $listing = $service->listing($input);
    $f = $listing['filters'];
    $mine = $listing['mine'] || !$listing['team'];
    $title = $listing['mine'] ? 'Mis cotizaciones' : 'Cotizaciones de inmuebles';
    $html = '<section class="cqt cqt-page" data-cqt-page>'
      . '<header class="cqt-head"><span class="scm-head-icon" aria-hidden="true"><span class="material-symbols-outlined">request_quote</span></span><div><p class="cqt-eyebrow">Actividades comerciales</p><h1>' . $title . '</h1><p>'
      . ($mine ? 'Las cotizaciones que has enviado a tus clientes.' : 'Las cotizaciones que el equipo ha enviado a los clientes.')
      . ' Se crean desde el popup de cada tarea con el botón Cotizar inmuebles.</p></div></header>'
      . '<form class="cqt-filters cqt-list-filters" data-cqt-list-filters aria-label="Filtros de cotizaciones">'
      . ($listing['mine'] ? '<input type="hidden" name="portafolio" value="1">' : '')
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="cq_q" value="' . self::e($f['q']) . '" placeholder="Cliente, celular, correo, número, tarea o código"></label>'
      . self::select('cq_tipo', 'Tipo', self::pairs(CommercialQuotesService::TYPES), $f['tipo'], 'Todos')
      . ($listing['team'] && !$listing['mine'] ? self::select('cq_empleado', 'Consultor/a', $service->creators(), $f['empleado'], 'Todo el equipo') : '')
      . '<label class="cqt-field"><span>Desde</span><input type="date" name="cq_desde" value="' . self::e($f['desde']) . '"></label>'
      . '<label class="cqt-field"><span>Hasta</span><input type="date" name="cq_hasta" value="' . self::e($f['hasta']) . '"></label>'
      . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">Filtrar</button><button type="button" class="cqt-secondary" data-cqt-list-clear>Limpiar</button></div>'
      . '</form><div data-cqt-list aria-live="polite">' . self::results($listing, $policy) . '</div></section>';
    return $html;
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing, CommercialAccessPolicy $policy): string
  {
    $html = '<div class="cqt-chips"><span class="cqt-chip"><strong>' . number_format($listing['total'], 0, ',', '.') . '</strong>' . ($listing['total'] === 1 ? 'cotización' : 'cotizaciones') . '</span>'
      . '<span class="cqt-chip"><strong>' . number_format($listing['mes'], 0, ',', '.') . '</strong>este mes</span></div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="cqt-empty">No hay cotizaciones con estos filtros.</p>';
    }
    $canOpenTask = $policy->canAct('ver_ticket');
    $html .= '<div class="cqt-table-wrap"><table class="cqt-table"><thead><tr><th>Cotización</th><th>Cliente</th><th>Inmuebles</th><th>Tarea</th><th>Consultor/a</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $codes = $row['codigos'];
      $shown = array_slice($codes, 0, 4);
      $task = $row['tarea'] > 0 ? ($canOpenTask ? '<button type="button" class="cqt-link" data-commercial-open-case="' . $row['tarea'] . '">#' . $row['tarea'] . '</button>' : '#' . $row['tarea']) : '<span class="cqt-muted">Sin tarea</span>';
      $html .= '<tr>'
        . '<td data-label="Cotización"><strong>#' . (int) $row['id'] . '</strong><small>' . self::e($row['fecha']) . '</small>' . ($row['tipo'] !== '' ? '<span class="cqt-tag">' . self::e($row['tipo']) . '</span>' : '') . '</td>'
        . '<td data-label="Cliente"><strong>' . self::e($row['cliente'] ?: 'Sin nombre') . '</strong><small>' . self::e($row['celular']) . '</small><small>' . self::e($row['email']) . '</small></td>'
        . '<td data-label="Inmuebles"><span class="cqt-count">' . count($codes) . '</span> ' . self::e(implode(', ', $shown)) . (count($codes) > count($shown) ? ' y ' . (count($codes) - count($shown)) . ' más' : '') . '</td>'
        . '<td data-label="Tarea">' . $task . '</td>'
        . '<td data-label="Consultor/a">' . self::e($row['asesor'] ?: 'Sin consultor/a') . '</td>'
        . '<td data-label="Acciones"><div class="cqt-row-actions"><button type="button" class="cqt-secondary cqt-small" data-cqt-view="' . (int) $row['id'] . '">' . self::icon('visibility') . 'Ver</button></div></td>'
        . '</tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de cotizaciones"><button type="button" class="cqt-secondary" data-cqt-list-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="cqt-secondary" data-cqt-list-page="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }
}
