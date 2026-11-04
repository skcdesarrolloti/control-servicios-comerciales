<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Analytics\TrackerAggregator;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Promoción › Analítica web. Adaptación de dashboard-marketing views/analytics.php (pestañas general, campañas, inmuebles y
 * logs) al diseño comercial: todo sale de los resúmenes diarios (scm_tracker_*), con un rango por defecto de 30 días y
 * los filtros aplicados con un botón. La tabla cruda `jet_cct_tracker_logs` solo se toca en la puesta al día acotada al
 * abrir la pantalla y en "Registros", que se pide aparte (commercial_promo_analytics_logs).
 *
 * No hay sección "Eventos": el tracker solo guarda vistas de página (event_type siempre 'view').
 */
final class AnalyticsView
{
  /** Listas de la pantalla => [título, columna del valor, con visitantes únicos]. */
  public const LISTS = [
    'campaigns' => ['Campañas', 'Campaña', true],
    'sources' => ['Fuentes', 'Fuente', true],
    'mediums' => ['Medios', 'Medio', true],
    'pages' => ['Páginas más vistas', 'Página', false],
    'referrers' => ['Sitios de referencia', 'Sitio', false],
    'countries' => ['Países', 'País', false],
    'cities' => ['Ciudades', 'Ciudad', false],
    'objects' => ['Inmuebles más vistos', 'Inmueble', false],
  ];

  /**
   * @param array<string,mixed> $input pr_desde, pr_hasta, pr_campana, pr_fuente, pr_medio.
   * @param bool $catchUp false en la carga completa de la página: solo se lee el estado y la puesta al día la pide la
   *   pantalla por AJAX (api.php cierra la sesión antes; index.php no, y otra petición del mismo usuario esperaría).
   * @return array<string,mixed>
   */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, ?TrackerRollup $rollup = null, ?string $today = null, bool $catchUp = true): array
  {
    $today = $today ?? date('Y-m-d');
    $config = TrackerAnalyticsConfig::read($settings);
    $filters = TrackerAnalyticsFilters::fromInput(self::filterInput($input), $config, $today);
    $context = [
      'filters' => $filters,
      'today' => $today,
      'can_network' => $policy->canAct('promocion_ver_ip'),
      'sync' => null,
      'ready' => false,
      'error' => '',
      'kpis' => null,
      'daily' => [],
      'lists' => [],
      'options' => ['campaigns' => [], 'sources' => [], 'mediums' => []],
    ];
    if ($rollup !== null) {
      try {
        // Acotada (5.000 filas) y sin esperar: si el cron tiene el candado, la pantalla muestra lo que ya hay.
        $context['sync'] = $catchUp ? $rollup->catchUp() : $rollup->pendingStatus();
      } catch (\Throwable $exception) {
        error_log('[commercial-promotion-analytics] ' . $exception->getMessage());
        $context['sync'] = ['error' => true];
      }
    }
    $repository = new TrackerAnalyticsRepository($db, $settings);
    try {
      $context['ready'] = $repository->ready();
      $context['kpis'] = $repository->kpis($filters);
      $context['daily'] = array_map(static fn(array $row): array => ['day' => $row['day'], 'value' => $row['visits']], $repository->daily($filters));
      foreach (array_keys(self::LISTS) as $list) {
        $context['lists'][$list] = $repository->top($list, $filters, 10);
      }
      $context['options'] = $repository->options($today);
    } catch (\Throwable $exception) {
      error_log('[commercial-promotion-analytics] ' . $exception->getMessage());
      $context['error'] = 'No se pudieron leer los resúmenes de la analítica web. Intenta de nuevo en unos minutos.';
    }
    return $context;
  }

  /** Filtros de la URL del panel en el formato de TrackerAnalyticsFilters. @param array<string,mixed> $input @return array<string,mixed> */
  public static function filterInput(array $input): array
  {
    return [
      'from' => $input['pr_desde'] ?? null,
      'to' => $input['pr_hasta'] ?? null,
      'campaign' => $input['pr_campana'] ?? '',
      'source' => $input['pr_fuente'] ?? '',
      'medium' => $input['pr_medio'] ?? '',
    ];
  }

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    /** @var TrackerAnalyticsFilters $filters */
    $filters = $context['filters'];
    $e = static fn($value): string => PromotionUi::e($value);
    $applied = ['pr_desde' => $filters->from, 'pr_hasta' => $filters->to, 'pr_campana' => $filters->campaign, 'pr_fuente' => $filters->source, 'pr_medio' => $filters->medium];
    $html = PromotionUi::hero('monitoring', 'Analítica web', 'Visitas al sitio de SKC SuCasa Inmobiliaria: campañas, fuentes, medios, páginas e inmuebles.');
    $html .= self::syncNotice($context);
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    $html .= self::filtersForm($context, $baseUrl);
    if ($context['error'] !== '') {
      return $html . '<p class="cpr-alert is-error">' . $e($context['error']) . '</p>';
    }
    if ($filters->clamped) {
      $html .= '<p class="cpr-alert">El rango máximo es de 12 meses: se muestran desde el ' . $e(PromotionUi::day($filters->from)) . '.</p>';
    }
    $kpis = $context['kpis'];
    $excluded = $kpis['excluded'];
    // Mientras el rango no esté procesado del todo, los indicadores se atenúan (el aviso de arriba dice por qué).
    $partial = in_array(self::coverage($context), ['partial', 'pending'], true);
    $html .= '<div class="cpr-stats' . ($partial ? ' is-partial' : '') . '" data-cpr-kpis' . ($partial ? ' data-cpr-partial' : '') . '>'
      . PromotionUi::stat('Visitas', PromotionUi::number($kpis['visits']), PromotionUi::day($filters->from) . ' a ' . PromotionUi::day($filters->to), 'visibility')
      . PromotionUi::stat('Visitantes únicos', PromotionUi::number($kpis['unique_visitors']), 'Por dirección IP (se guarda cifrada)', 'person')
      . PromotionUi::stat('Visitas de usuarios registrados', PromotionUi::number($kpis['registered_visits']), $kpis['visits'] > 0 ? PromotionUi::percent($kpis['registered_visits'] * 100 / $kpis['visits']) . ' de las visitas' : '', 'how_to_reg')
      . PromotionUi::stat('Campañas con visitas', PromotionUi::number($kpis['campaigns']), 'En el rango y los filtros', 'campaign')
      . '</div>';
    $notes = [];
    if ($excluded['bots_excluded']) {
      $notes[] = PromotionUi::number($excluded['bots']) . ' de robots (' . PromotionUi::percent($excluded['bots_pct']) . ')';
    }
    if ($excluded['internal_excluded']) {
      $notes[] = PromotionUi::number($excluded['internal']) . ' internas de la oficina (' . PromotionUi::percent($excluded['internal_pct']) . ')';
    }
    if ($excluded['total'] > 0) {
      $html .= '<p class="cpr-note" data-cpr-excluded><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>'
        . ($notes !== [] ? 'De ' . $e(PromotionUi::number($excluded['total'])) . ' visitas registradas no se cuentan ' . $e(implode(' ni ', $notes)) . '.' : 'Se cuentan todas las visitas, también robots e internas.')
        . ' Se ajusta en Configuración › Analítica web.</p>';
    }
    $html .= PromotionUi::dailyChart('Visitas por día', 'visitas', $context['daily']);
    $html .= '<div class="cpr-grid" data-cpr-lists>';
    foreach (self::LISTS as $list => [$title, $column, $withUnique]) {
      $html .= self::listCard($list, $title, $column, $withUnique, $context['lists'][$list] ?? []);
    }
    $html .= '</div>';
    $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>El seguimiento de clics no se está registrando: el tracker del sitio solo guarda vistas de página, por eso no hay sección de eventos.</p>';
    $html .= self::logsSection($context, $applied);
    return $html;
  }

  /**
   * ¿Qué tan completos están los datos del rango elegido?
   *  'error'   no se pudo poner al día;
   *  'pending' los resúmenes todavía no existen;
   *  'partial' hay visitas sin resumir y lo procesado no llega al final del rango (las cifras pueden estar incompletas);
   *  'recent'  solo faltan las visitas más recientes, posteriores al rango;
   *  'ok'      todo procesado.
   * @param array<string,mixed> $context
   */
  public static function coverage(array $context): string
  {
    $sync = $context['sync'];
    if (is_array($sync) && !empty($sync['error'])) {
      return 'error';
    }
    if (empty($context['ready'])) {
      return 'pending';
    }
    if (!is_array($sync) || empty($sync['behind'])) {
      return 'ok';
    }
    $last = TrackerAggregator::day((string) ($sync['last_fecha'] ?? ''));
    /** @var TrackerAnalyticsFilters $filters */
    $filters = $context['filters'];
    // Las visitas pendientes son posteriores a la última procesada: si esa es del último día del rango o anterior, al
    // rango le pueden faltar visitas.
    return $last === null || $last <= $filters->to ? 'partial' : 'recent';
  }

  /** @param array<string,mixed> $context */
  private static function syncNotice(array $context): string
  {
    $sync = is_array($context['sync']) ? $context['sync'] : [];
    $coverage = self::coverage($context);
    if ($coverage === 'error') {
      return '<p class="cpr-alert" data-cpr-sync="error">No se pudieron traer las visitas más recientes; se muestran los resúmenes que ya había.</p>';
    }
    $last = (string) ($sync['last_fecha'] ?? '');
    $until = $last !== '' ? ' (procesado hasta el ' . PromotionUi::day($last, true) . ')' : '';
    // En la carga completa la puesta al día no corre: la pantalla la pide sola por AJAX (data-cpr-sync-auto).
    $auto = !empty($sync['deferred']) && (!empty($sync['behind']) || $coverage === 'pending') ? ' data-cpr-sync-auto' : '';
    $reload = ' <button type="button" class="cpr-link" data-cpr-reload>Volver a cargar</button>';
    $html = '';
    $rebuild = is_array($sync['rebuild'] ?? null) ? $sync['rebuild'] : [];
    if (isset($rebuild['from'], $rebuild['next'])) {
      $html .= '<p class="cpr-alert is-sync" data-cpr-sync-rebuild><span class="material-symbols-outlined" aria-hidden="true">autorenew</span>Recalculando las cifras desde el '
        . PromotionUi::e(PromotionUi::day((string) $rebuild['from'])) . ': va en el ' . PromotionUi::e(PromotionUi::day((string) $rebuild['next']))
        . '. Los días que faltan todavía muestran la clasificación anterior de robots y visitas internas.</p>';
    }
    return $html . match ($coverage) {
      'pending' => '<p class="cpr-alert is-sync" data-cpr-sync="pending"' . $auto . '><span class="material-symbols-outlined" aria-hidden="true">sync</span>Actualizando datos: la analítica web se está preparando y las cifras aparecen a medida que se procesan las visitas.' . $reload . '</p>',
      'partial' => '<p class="cpr-alert is-sync" data-cpr-sync="behind"' . $auto . '><span class="material-symbols-outlined" aria-hidden="true">sync</span>Actualizando datos: las visitas de este rango aún se están procesando' . PromotionUi::e($until) . ', así que las cifras pueden estar incompletas.' . $reload . '</p>',
      'recent' => '<p class="cpr-alert is-sync" data-cpr-sync="behind"' . $auto . '><span class="material-symbols-outlined" aria-hidden="true">sync</span>Actualizando datos: faltan las visitas más recientes' . PromotionUi::e($until) . '; el rango elegido ya está completo.' . $reload . '</p>',
      // Sin puesta al día (sin secreto para cifrar visitantes) no hay nada que contar sobre el estado.
      default => $sync === [] ? '' : '<p class="cpr-sync-ok" data-cpr-sync="ok">' . PromotionUi::e($last !== '' ? 'Datos procesados hasta el ' . PromotionUi::day($last, true) . '.' : 'Sin visitas registradas todavía.') . '</p>',
    };
  }
  /** @param array<string,mixed> $context */
  private static function filtersForm(array $context, string $baseUrl): string
  {
    /** @var TrackerAnalyticsFilters $filters */
    $filters = $context['filters'];
    $options = $context['options'];
    $min = TrackerAnalyticsFilters::shift($context['today'], -(TrackerAnalyticsFilters::MAX_DAYS - 1));
    $campaigns = [];
    $labels = [];
    foreach ($options['campaigns'] as $option) {
      $campaigns[] = $option['value'];
      $labels[$option['value']] = $option['label'] !== $option['value'] ? $option['label'] . ' (' . $option['value'] . ')' : $option['value'];
    }
    // Un valor elegido que ya no aparece en los últimos 12 meses sigue en su lista para no perder el filtro.
    $keep = static fn(array $list, string $value): array => $value !== '' && !in_array($value, $list, true) ? array_merge($list, [$value]) : $list;
    $campaigns = $keep($campaigns, $filters->campaign);
    $options['sources'] = $keep($options['sources'], $filters->source);
    $options['mediums'] = $keep($options['mediums'], $filters->medium);
    return '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="analitica_web" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'analitica_web'])) . '">'
      . '<div class="cpr-filters__grid">'
      . PromotionUi::dateField('pr_desde', 'Desde', $filters->from, $min, $context['today'])
      . PromotionUi::dateField('pr_hasta', 'Hasta', $filters->to, $min, $context['today'])
      . PromotionUi::select('pr_campana', 'Campaña', $campaigns, $filters->campaign, 'Todas las campañas', $labels)
      . PromotionUi::select('pr_fuente', 'Fuente', $options['sources'], $filters->source, 'Todas las fuentes')
      . PromotionUi::select('pr_medio', 'Medio', $options['mediums'], $filters->medium, 'Todos los medios')
      . '</div><div class="cpr-filters__actions"><a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'analitica_web'])) . '">Últimos 30 días</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">Hasta 12 meses. Los cambios se aplican al pulsar Aplicar.</p></form>';
  }

  /** @param list<array{value:string,label:string,detail:string,hits:int,unique_visitors:int|null}> $rows */
  private static function listCard(string $list, string $title, string $column, bool $withUnique, array $rows): string
  {
    $total = array_sum(array_map(static fn(array $row): int => (int) $row['hits'], $rows));
    $max = max(1, ...array_map(static fn(array $row): int => (int) $row['hits'], $rows ?: [['hits' => 0]]));
    $headers = [[$column], ['Visitas', true]];
    if ($withUnique) {
      $headers[] = ['Únicos', true];
    }
    $out = [];
    foreach ($rows as $row) {
      $out[] = array_merge([self::listLabel($list, $row) . '<span class="cpr-meter" aria-hidden="true"><span style="width:' . max(2, (int) round($row['hits'] * 100 / $max)) . '%"></span></span>'],
        [PromotionUi::e(PromotionUi::number($row['hits']))],
        $withUnique ? [PromotionUi::e(PromotionUi::number((int) $row['unique_visitors']))] : []);
    }
    return PromotionUi::tableCard($title, $rows === [] ? '' : 'Los 10 primeros · ' . PromotionUi::number($total) . ' visitas', $headers, $out, 'Sin visitas en el rango.', ' data-cpr-list="' . PromotionUi::e($list) . '"');
  }

  /** @param array{value:string,label:string,detail:string,hits:int,unique_visitors:int|null} $row */
  private static function listLabel(string $list, array $row): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    return match ($list) {
      'campaigns' => '<span class="cpr-cell-main">' . $e($row['label']) . '</span>' . ($row['label'] !== $row['value'] ? '<span class="cpr-cell-sub">' . $e($row['value']) . '</span>' : ''),
      'pages' => '<span class="cpr-cell-main cpr-url" title="' . $e($row['label']) . '">' . $e(self::shortUrl($row['label'])) . '</span>',
      'cities' => '<span class="cpr-cell-main">' . $e($row['label']) . '</span>' . ($row['detail'] !== '' ? '<span class="cpr-cell-sub">' . $e($row['detail']) . '</span>' : ''),
      'objects' => '<span class="cpr-cell-main' . (str_starts_with($row['detail'], 'http') ? ' cpr-url' : '') . '" title="' . $e($row['detail']) . '">' . $e($row['detail'] !== '' ? self::shortUrl($row['detail']) : 'Inmueble ' . $row['label']) . '</span><span class="cpr-cell-sub">ID ' . $e($row['label']) . '</span>',
      default => '<span class="cpr-cell-main">' . $e($row['label']) . '</span>',
    };
  }

  /** La URL sin esquema ni "www.", para que quepa en la tabla (el título conserva la completa). */
  public static function shortUrl(string $url): string
  {
    return (string) preg_replace('#^https?://(www\.)?#i', '', $url);
  }

  /**
   * Registros uno por uno: no se consultan al abrir la pantalla, solo al pulsar "Cargar registros".
   * @param array<string,mixed> $context
   * @param array<string,string> $applied
   */
  private static function logsSection(array $context, array $applied): string
  {
    $network = (bool) $context['can_network'];
    return '<section class="cpr-card cpr-logs" data-cpr-logs data-network="' . ($network ? '1' : '0') . '" data-filters="' . PromotionUi::e((string) json_encode($applied, JSON_UNESCAPED_UNICODE)) . '">'
      . '<div class="cpr-card__head cpr-card__head--split"><div><h2>Registros</h2><p>Cada visita del rango, de la más reciente a la más antigua: hasta 90 días y ' . TrackerAnalyticsRepository::LOG_PAGE_SIZE . ' por página.'
      . ($network ? ' Incluye IP y navegador.' : ' La IP y el navegador solo los ve quien tiene el permiso.') . ' Se consultan solo cuando los pides.</p></div>'
      . '<button type="button" class="cpr-ghost" data-cpr-logs-load><span class="material-symbols-outlined" aria-hidden="true">list_alt</span>Cargar registros</button></div>'
      . '<div data-cpr-logs-body hidden><p class="cpr-alert" data-cpr-logs-note hidden></p><div class="cpr-table-wrap"><table class="cpr-table cpr-logs__table"><thead><tr><th>Fecha</th><th>Campaña</th><th>Fuente / medio</th><th>Página</th><th>Referencia</th><th>Ubicación</th>'
      . ($network ? '<th>IP</th><th>Navegador</th>' : '') . '</tr></thead><tbody data-cpr-logs-rows></tbody></table></div>'
      . '<div class="cpr-pager"><button type="button" class="cpr-ghost" data-cpr-logs-prev disabled>Anteriores</button><span data-cpr-logs-page>Página 1</span><button type="button" class="cpr-ghost" data-cpr-logs-next disabled>Siguientes</button></div></div>'
      . '</section>';
  }
}
