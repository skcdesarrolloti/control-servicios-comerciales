<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
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
   * @return array<string,mixed>
   */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, ?TrackerRollup $rollup = null, ?string $today = null): array
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
        $context['sync'] = $rollup->catchUp();
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
    $html = PromotionUi::hero('monitoring', 'Analítica web', 'Visitas al sitio de SKC SuCasa Inmobiliaria registradas por el tracker: campañas, fuentes, medios, páginas e inmuebles. Los datos salen de resúmenes diarios, así que la pantalla carga rápido aunque el registro crezca.');
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
    $html .= '<div class="cpr-stats" data-cpr-kpis>'
      . PromotionUi::stat('Visitas', PromotionUi::number($kpis['visits']), PromotionUi::day($filters->from) . ' a ' . PromotionUi::day($filters->to), 'visibility')
      . PromotionUi::stat('Visitantes únicos', PromotionUi::number($kpis['unique_visitors']), 'Por dispositivo (IP cifrada)', 'person')
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
    $html .= '<p class="cpr-note" data-cpr-excluded><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>'
      . ($notes !== [] ? 'No se cuentan ' . $e(implode(' ni ', $notes)) . ' de ' . $e(PromotionUi::number($excluded['total'])) . ' visitas registradas.' : 'Se cuentan todas las visitas, también robots e internas.')
      . ' Se ajusta en Configuración › Analítica web.</p>';
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

  /** @param array<string,mixed> $context */
  private static function syncNotice(array $context): string
  {
    $sync = $context['sync'];
    if (!is_array($sync)) {
      return '';
    }
    if (!empty($sync['error'])) {
      return '<p class="cpr-alert" data-cpr-sync="error">No se pudieron traer las visitas más recientes; se muestran los resúmenes que ya había.</p>';
    }
    $last = (string) ($sync['last_fecha'] ?? '');
    $until = $last !== '' ? ' Datos procesados hasta el ' . PromotionUi::day($last, true) . '.' : '';
    if (!empty($sync['behind']) || !empty($sync['busy'])) {
      return '<p class="cpr-alert is-sync" data-cpr-sync="behind"><span class="material-symbols-outlined" aria-hidden="true">sync</span>Actualizando datos: todavía hay visitas por resumir.' . PromotionUi::e($until)
        . ' <button type="button" class="cpr-link" data-cpr-reload>Volver a cargar</button></p>';
    }
    return '<p class="cpr-sync-ok" data-cpr-sync="ok">' . PromotionUi::e(trim($until) !== '' ? trim($until) : 'Sin visitas registradas todavía.') . '</p>';
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
