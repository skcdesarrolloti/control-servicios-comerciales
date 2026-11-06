<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Commercial\Medios\MedioCatalog;
use SCM\Commercial\Medios\MedioResolver;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Lecturas de Analítica web. Adaptación de dashboard-marketing app/AnalyticsRepository.php (summary, generalBreakdowns,
 * options y la pestaña de registros): los indicadores, la serie diaria y las listas salen solo de los resúmenes diarios
 * (scm_tracker_*), nunca de la tabla cruda; el visor de registros es lo único que lee `jet_cct_tracker_logs`, a pedido,
 * con un rango de hasta 90 días y paginación por llave sobre _ID (el tracker guarda fecha_visita al insertar, así que el
 * orden de _ID es el de las fechas, y la llave primaria sí sirve para ordenar; fecha_visita es TEXT con índice de prefijo).
 *
 * Sin el permiso promocion_ver_ip el visor lee la IP y el navegador solo para reconocer robots y visitas internas, y nunca
 * los devuelve (ni siquiera en el JSON).
 *
 * Fuentes y medios UTM ya no se listan ni se filtran por separado: la dimensión es el Medio de la lista única (columna
 * `medio` de los resúmenes). En Registros se siguen viendo utm_source y utm_medium crudos, junto al medio que resultó.
 */
final class TrackerAnalyticsRepository
{
  /** Listas disponibles => [tabla de origen, columna o dimensión]. */
  public const LISTS = [
    'campaigns' => ['daily', 'camp_slug'],
    'medios' => ['daily', 'medio'],
    'pages' => ['top', 'page'],
    'referrers' => ['top', 'referrer'],
    'countries' => ['top', 'country'],
    'cities' => ['top', 'city'],
    'objects' => ['top', 'object'],
  ];

  public const LOG_PAGE_SIZE = 25;
  private const LOG_CHUNK = 100;
  private const LOG_MAX_CHUNKS = 5;
  /** _ID de holgura alrededor de los límites del rango (visitas que se confirmaron algo desordenadas). */
  private const LOG_ID_MARGIN = 500;

  private Database $db;
  private Settings $settings;
  /** @var array<string,string>|null */
  private ?array $campaignNames = null;
  private ?MedioCatalog $medios = null;

  public function __construct(Database $db, Settings $settings)
  {
    $this->db = $db;
    $this->settings = $settings;
  }

  /** ¿Ya existen los resúmenes? Antes de la primera puesta al día las lecturas devuelven vacío. */
  public function ready(): bool
  {
    return TrackerSchema::isReady($this->settings);
  }

  /**
   * @return array{visits:int,unique_visitors:int,registered_visits:int,campaigns:int,excluded:array{total:int,bots:int,internal:int,bots_pct:float,internal_pct:float,bots_excluded:bool,internal_excluded:bool}}
   */
  public function kpis(TrackerAnalyticsFilters $filters): array
  {
    $empty = ['total' => 0, 'bots' => 0, 'internal' => 0, 'bots_pct' => 0.0, 'internal_pct' => 0.0, 'bots_excluded' => $filters->excludeBots, 'internal_excluded' => $filters->excludeInternal];
    if (!$this->ready()) {
      return ['visits' => 0, 'unique_visitors' => 0, 'registered_visits' => 0, 'campaigns' => 0, 'excluded' => $empty];
    }
    $daily = TrackerSchema::daily($this->db);
    [$where, $args] = $this->where($filters, true);
    // 'general', 'inmueble_view' y 'event_click' los pone el tracker solo: no son campañas.
    $pseudo = array_keys(TrackerSchema::PSEUDO_CAMPAIGNS);
    $notPseudo = implode(', ', array_fill(0, count($pseudo), '?'));
    $row = $this->db->getRow(
      "SELECT COALESCE(SUM(`hits`), 0) AS visits, COALESCE(SUM(CASE WHEN `registered` = 1 THEN `hits` ELSE 0 END), 0) AS registered,
              COUNT(DISTINCT CASE WHEN `camp_slug` NOT IN ({$notPseudo}) THEN `camp_slug` END) AS campaigns
         FROM `{$daily}` WHERE {$where}",
      array_merge($pseudo, $args)
    ) ?? [];
    [$baseWhere, $baseArgs] = $this->where($filters, false);
    // Cada visita excluida cuenta una sola vez: con los robots excluidos, un robot interno ya está entre los robots; sin
    // excluirlos, las internas son todas las de la oficina. Así robots + internas = total - visitas.
    $internalCase = $filters->excludeBots ? '`bot` = 0 AND `internal` = 1' : '`internal` = 1';
    $base = $this->db->getRow(
      "SELECT COALESCE(SUM(`hits`), 0) AS total, COALESCE(SUM(CASE WHEN `bot` = 1 THEN `hits` ELSE 0 END), 0) AS bots,
              COALESCE(SUM(CASE WHEN {$internalCase} THEN `hits` ELSE 0 END), 0) AS internal
         FROM `{$daily}` WHERE {$baseWhere}",
      $baseArgs
    ) ?? [];
    [$visitorWhere, $visitorArgs] = $this->where($filters, true);
    $unique = (int) $this->db->getVar('SELECT COUNT(DISTINCT `visitor_hash`) FROM `' . TrackerSchema::visitors($this->db) . "` WHERE {$visitorWhere}", $visitorArgs);
    $total = (int) ($base['total'] ?? 0);
    return [
      'visits' => (int) ($row['visits'] ?? 0),
      'unique_visitors' => $unique,
      'registered_visits' => (int) ($row['registered'] ?? 0),
      'campaigns' => (int) ($row['campaigns'] ?? 0),
      'excluded' => [
        'total' => $total,
        'bots' => (int) ($base['bots'] ?? 0),
        'internal' => (int) ($base['internal'] ?? 0),
        'bots_pct' => $total > 0 ? round((int) ($base['bots'] ?? 0) * 100 / $total, 1) : 0.0,
        'internal_pct' => $total > 0 ? round((int) ($base['internal'] ?? 0) * 100 / $total, 1) : 0.0,
        'bots_excluded' => $filters->excludeBots,
        'internal_excluded' => $filters->excludeInternal,
      ],
    ];
  }

  /**
   * Visitas y visitantes únicos por día, con todos los días del rango (los vacíos en cero).
   * @return list<array{day:string,visits:int,unique_visitors:int}>
   */
  public function daily(TrackerAnalyticsFilters $filters): array
  {
    $series = [];
    for ($day = $filters->from; $day <= $filters->to; $day = TrackerAnalyticsFilters::shift($day, 1)) {
      $series[$day] = ['day' => $day, 'visits' => 0, 'unique_visitors' => 0];
    }
    if (!$this->ready()) {
      return array_values($series);
    }
    [$where, $args] = $this->where($filters, true);
    foreach ($this->db->getResults('SELECT `fecha`, SUM(`hits`) AS visits FROM `' . TrackerSchema::daily($this->db) . "` WHERE {$where} GROUP BY `fecha`", $args) as $row) {
      $day = substr((string) $row['fecha'], 0, 10);
      if (isset($series[$day])) {
        $series[$day]['visits'] = (int) $row['visits'];
      }
    }
    foreach ($this->db->getResults('SELECT `fecha`, COUNT(DISTINCT `visitor_hash`) AS visitors FROM `' . TrackerSchema::visitors($this->db) . "` WHERE {$where} GROUP BY `fecha`", $args) as $row) {
      $day = substr((string) $row['fecha'], 0, 10);
      if (isset($series[$day])) {
        $series[$day]['unique_visitors'] = (int) $row['visitors'];
      }
    }
    return array_values($series);
  }

  /**
   * Lista de los más visitados. Campañas y medios incluyen visitantes únicos. En los medios, el detalle marca los que no
   * están en la lista ("Sin reconocer"): se ajustan en Configuración › Medios.
   * @return list<array{value:string,label:string,detail:string,hits:int,unique_visitors:int|null}>
   */
  public function top(string $list, TrackerAnalyticsFilters $filters, int $limit = 10): array
  {
    if (!isset(self::LISTS[$list])) {
      throw new \InvalidArgumentException('Lista de analítica no válida.');
    }
    if (!$this->ready()) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    [$source, $column] = self::LISTS[$list];
    [$where, $args] = $this->where($filters, true);
    if ($source === 'daily') {
      $rows = $this->db->getResults(
        "SELECT `{$column}` AS label, SUM(`hits`) AS hits FROM `" . TrackerSchema::daily($this->db) . "` WHERE {$where}
          GROUP BY `{$column}` ORDER BY hits DESC, label ASC LIMIT {$limit}",
        $args
      );
      $uniques = [];
      $values = array_map(static fn(array $row): string => (string) $row['label'], $rows);
      if ($values !== []) {
        $in = implode(', ', array_fill(0, count($values), '?'));
        foreach ($this->db->getResults(
          "SELECT `{$column}` AS label, COUNT(DISTINCT `visitor_hash`) AS visitors FROM `" . TrackerSchema::visitors($this->db) . "` WHERE {$where} AND `{$column}` IN ({$in}) GROUP BY `{$column}`",
          array_merge($args, $values)
        ) as $row) {
          $uniques[mb_strtolower((string) $row['label'])] = (int) $row['visitors'];
        }
      }
      $names = $list === 'campaigns' ? $this->campaignNames() : [];
      $resolver = $list === 'medios' ? $this->medios()->resolver() : null;
      return array_map(static fn(array $row): array => [
        'value' => (string) $row['label'],
        'label' => $names[(string) $row['label']] ?? ((string) $row['label'] === '' && $resolver !== null ? 'Sin clasificar' : (string) $row['label']),
        'detail' => $resolver !== null ? self::medioDetail($resolver, (string) $row['label']) : '',
        'hits' => (int) $row['hits'],
        'unique_visitors' => $uniques[mb_strtolower((string) $row['label'])] ?? 0,
      ], $rows);
    }
    $rows = $this->db->getResults(
      "SELECT `value_hash`, MAX(`value`) AS label, COALESCE(MAX(CASE WHEN `detail` <> '' AND `detail` NOT LIKE 'http%' THEN `detail` END), MAX(`detail`)) AS detail, SUM(`hits`) AS hits FROM `" . TrackerSchema::top($this->db) . "`
        WHERE `dim` = ? AND {$where} GROUP BY `value_hash` ORDER BY hits DESC, label ASC LIMIT {$limit}",
      array_merge([$column], $args)
    );
    return array_map(static fn(array $row): array => [
      'value' => (string) $row['label'],
      'label' => (string) $row['label'],
      'detail' => (string) $row['detail'],
      'hits' => (int) $row['hits'],
      'unique_visitors' => null,
    ], $rows);
  }

  /**
   * Cruces por campaña de dashboard-marketing (Campaña + canal, Inmuebles por campaña y Ubicación por campaña), leídos de los
   * resúmenes. Sin "Sin campaña" ni "Vista de inmueble", que son las visitas normales del sitio y taparían las campañas.
   * @return array{channels:list<array<string,mixed>>,objects:list<array<string,mixed>>,locations:list<array<string,mixed>>}
   */
  public function campaignBreakdowns(TrackerAnalyticsFilters $filters, int $limit = 15): array
  {
    if (!$this->ready()) {
      return ['channels' => [], 'objects' => [], 'locations' => []];
    }
    [$where, $args] = $this->where($filters, true);
    $pseudo = array_keys(TrackerSchema::PSEUDO_CAMPAIGNS);
    $where .= ' AND `camp_slug` NOT IN (' . implode(', ', array_fill(0, count($pseudo), '?')) . ')';
    $args = array_merge($args, $pseudo);
    $names = $this->campaignNames();
    $name = static fn(string $slug): string => $names[$slug] ?? $slug;
    $uniques = [];
    foreach ($this->db->getResults('SELECT `camp_slug`, `utm_source`, `utm_medium`, COUNT(DISTINCT `visitor_hash`) AS visitors FROM `' . TrackerSchema::visitors($this->db) . "` WHERE {$where} GROUP BY `camp_slug`, `utm_source`, `utm_medium`", $args) as $row) {
      $uniques[$row['camp_slug'] . "\0" . $row['utm_source'] . "\0" . $row['utm_medium']] = (int) $row['visitors'];
    }
    $channels = array_map(static fn(array $row): array => ['campaign' => $name((string) $row['camp_slug']), 'source' => (string) $row['utm_source'], 'medium' => (string) $row['utm_medium'], 'hits' => (int) $row['hits'],
      'unique_visitors' => $uniques[$row['camp_slug'] . "\0" . $row['utm_source'] . "\0" . $row['utm_medium']] ?? 0],
      $this->db->getResults('SELECT `camp_slug`, `utm_source`, `utm_medium`, SUM(`hits`) AS hits FROM `' . TrackerSchema::daily($this->db) . "` WHERE {$where} GROUP BY `camp_slug`, `utm_source`, `utm_medium` ORDER BY hits DESC, `camp_slug` ASC LIMIT {$limit}", $args));
    $top = fn(string $dim): array => $this->db->getResults("SELECT `camp_slug`, MAX(`value`) AS label, COALESCE(MAX(CASE WHEN `detail` <> '' AND `detail` NOT LIKE 'http%' THEN `detail` END), MAX(`detail`)) AS detail, SUM(`hits`) AS hits FROM `" . TrackerSchema::top($this->db) . "`
      WHERE `dim` = ? AND {$where} GROUP BY `camp_slug`, `value_hash` ORDER BY hits DESC, label ASC LIMIT {$limit}", array_merge([$dim], $args));
    $map = static fn(array $row): array => ['campaign' => $name((string) $row['camp_slug']), 'label' => (string) $row['label'], 'detail' => (string) $row['detail'], 'hits' => (int) $row['hits']];
    return ['channels' => $channels, 'objects' => array_map($map, $top('object')), 'locations' => array_map($map, $top('city'))];
  }

  /** Detalle de una fila de la lista de medios: vacío si es de la lista o 'Sin medio (directo)'. */
  private static function medioDetail(MedioResolver $resolver, string $medio): string
  {
    if ($medio === '') {
      return 'Pendiente de clasificar (la tarea programada lo completa)';
    }
    return $medio === MedioResolver::DIRECT || $resolver->isName($medio) ? '' : 'Sin reconocer · se ajusta en Configuración › Medios';
  }

  private function medios(): MedioCatalog
  {
    return $this->medios ??= new MedioCatalog($this->db, $this->settings);
  }

  /**
   * Opciones de los filtros: lo que aparece en los resúmenes de los últimos 12 meses, con el nombre de cada campaña.
   * @return array{campaigns:list<array{value:string,label:string}>,medios:list<string>}
   */
  public function options(?string $today = null): array
  {
    $out = ['campaigns' => [], 'medios' => []];
    if (!$this->ready()) {
      return $out;
    }
    $since = TrackerAnalyticsFilters::shift($today ?? date('Y-m-d'), -(TrackerAnalyticsFilters::MAX_DAYS - 1));
    $daily = TrackerSchema::daily($this->db);
    $names = $this->campaignNames();
    foreach ($this->db->getCol("SELECT DISTINCT `camp_slug` FROM `{$daily}` WHERE `fecha` >= ? ORDER BY `camp_slug`", [$since]) as $slug) {
      $out['campaigns'][] = ['value' => (string) $slug, 'label' => $names[(string) $slug] ?? (string) $slug];
    }
    $out['medios'] = array_values(array_filter(array_map('strval', $this->db->getCol("SELECT DISTINCT `medio` FROM `{$daily}` WHERE `fecha` >= ? ORDER BY `medio`", [$since])), static fn(string $medio): bool => $medio !== ''));
    return $out;
  }

  /**
   * Nombres de campaña de `jet_cct_tracker_camps` (slug => nombre), con 'Sin campaña' y 'Vista de inmueble' para los slugs
   * que pone el tracker (TrackerSchema::PSEUDO_CAMPAIGNS) si el catálogo no les da nombre.
   * @return array<string,string>
   */
  public function campaignNames(): array
  {
    if ($this->campaignNames !== null) {
      return $this->campaignNames;
    }
    $this->campaignNames = TrackerSchema::PSEUDO_CAMPAIGNS;
    try {
      foreach ($this->db->getResults('SELECT `slug`, `nombre` FROM `' . $this->db->table('jet_cct_tracker_camps') . '`') as $row) {
        $slug = trim((string) ($row['slug'] ?? ''));
        $name = trim((string) ($row['nombre'] ?? ''));
        if ($slug !== '' && $name !== '') {
          $this->campaignNames[$slug] = $name;
        }
      }
    } catch (\Throwable) {
      // Sin catálogo de campañas se muestran los slugs.
    }
    return $this->campaignNames;
  }

  /**
   * Visor de registros crudos, a pedido. Hasta 90 días; 25 filas por página, de la más reciente a la más antigua.
   * Los robots y las visitas internas se reconocen aquí mismo (por eso se leen IP y navegador aunque no se devuelvan) y
   * se omiten si los filtros lo piden.
   *
   * La paginación va por _ID descendente con la llave primaria: el rango de fechas se traduce primero a un rango de _ID
   * (búsqueda binaria sobre la llave, unas 40 lecturas de una fila) y cada tramo lee a lo sumo LOG_CHUNK filas hacia
   * atrás, en vez de ordenar todo el rango por fecha_visita.
   *
   * @param string|null $cursor _ID de la última fila revisada en la página anterior.
   * @param bool $withNetwork Solo con el permiso promocion_ver_ip: agrega IP y navegador.
   * @return array{rows:list<array<string,mixed>>,next_cursor:string|null,from:string,to:string,clamped:bool}
   */
  public function logs(TrackerAnalyticsFilters $filters, ?string $cursor = null, bool $withNetwork = false): array
  {
    $filters = $filters->limitedTo(TrackerAnalyticsFilters::LOG_MAX_DAYS);
    $raw = TrackerSchema::raw($this->db);
    $start = $filters->from . ' 00:00:00';
    $end = TrackerAnalyticsFilters::shift($filters->to, 1) . ' 00:00:00';
    $where = ['`_ID` >= ?', '`_ID` < ?', '`fecha_visita` >= ?', '`fecha_visita` < ?'];
    [$low, $high] = $this->idRange($start, $end);
    $position = self::cursor($cursor);
    if ($position !== null) {
      $high = min($high, $position);
    }
    $args = [$low, $high, $start, $end];
    if ($filters->campaign !== '') {
      // En los resúmenes la campaña vacía quedó como 'general': aquí se buscan ambas.
      $where[] = $filters->campaign === TrackerSchema::EMPTY_CAMPAIGN ? "(`camp_slug` = ? OR `camp_slug` = '' OR `camp_slug` IS NULL)" : '`camp_slug` = ?';
      $args[] = $filters->campaign;
    }
    // El medio sale de utm_source y utm_medium con la lista vigente: se filtra aquí mismo, como los robots.
    $resolver = $this->medios()->resolver();
    $config = TrackerAnalyticsConfig::read($this->settings);
    $internal = new InternalIpMatcher($config['internal_ips']);
    $names = $this->campaignNames();
    $kept = [];
    $last = null;
    $more = $high > $low;
    for ($scanned = 0; $more && $scanned < self::LOG_MAX_CHUNKS; $scanned++) {
      $args[1] = $high;
      $rows = $this->db->getResults(
        'SELECT `_ID`, `fecha_visita`, `camp_slug`, `user_id`, `ip_address`, `user_agent`, `page_url`, `referrer`, `country`, `city`, `object_id`, `object_ref`, `utm_source`, `utm_medium`
           FROM `' . $raw . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY `_ID` DESC LIMIT ' . self::LOG_CHUNK,
        $args
      );
      foreach ($rows as $row) {
        $high = (int) $row['_ID'];
        $bot = BotDetector::isBot((string) ($row['user_agent'] ?? ''));
        $isInternal = $internal->matches((string) ($row['ip_address'] ?? ''));
        if (($filters->excludeBots && $bot) || ($filters->excludeInternal && $isInternal)) {
          continue;
        }
        $medio = $resolver->forVisit(
          TrackerAggregator::dimension($row['utm_source'] ?? '', TrackerSchema::EMPTY_SOURCE),
          TrackerAggregator::dimension($row['utm_medium'] ?? '', TrackerSchema::EMPTY_MEDIUM)
        )[0];
        if ($filters->medio !== '' && mb_strtolower($medio) !== mb_strtolower($filters->medio)) {
          continue;
        }
        $row['medio'] = $medio;
        if (count($kept) === self::LOG_PAGE_SIZE) {
          // Hay al menos una fila más: la página siguiente empieza después de la última mostrada.
          return ['rows' => $kept, 'next_cursor' => (string) $last, 'from' => $filters->from, 'to' => $filters->to, 'clamped' => $filters->clamped];
        }
        $kept[] = $this->logRow($row, $bot, $isInternal, $withNetwork, $names);
        $last = $high;
      }
      $more = count($rows) === self::LOG_CHUNK && $high > $low;
    }
    // Si se cortó por revisar muchas filas omitidas (robots o internas), se sigue desde la última revisada.
    return ['rows' => $kept, 'next_cursor' => $more ? (string) $high : null, 'from' => $filters->from, 'to' => $filters->to, 'clamped' => $filters->clamped];
  }

  /**
   * Rango de _ID [desde, hasta) que cubre las visitas con fecha_visita en [$start, $end), con LOG_ID_MARGIN de holgura a
   * cada lado. El filtro por fecha se aplica igual en la consulta: el rango solo acota lo que se lee.
   * @return array{0:int,1:int}
   */
  private function idRange(string $start, string $end): array
  {
    $raw = TrackerSchema::raw($this->db);
    $bounds = $this->db->getRow('SELECT MIN(`_ID`) AS low, MAX(`_ID`) AS high FROM `' . $raw . '`');
    if ($bounds === null || $bounds['low'] === null) {
      return [0, 0];
    }
    $min = (int) $bounds['low'];
    $max = (int) $bounds['high'];
    $low = $this->firstIdFrom($start, $min, $max);
    $high = $this->firstIdFrom($end, $low, $max);
    return [max(0, $low - self::LOG_ID_MARGIN), min($max + 1, $high + self::LOG_ID_MARGIN)];
  }

  /** Primer _ID (en [$min, $max + 1]) cuya visita es de $fecha o posterior, suponiendo _ID y fecha en el mismo orden. */
  private function firstIdFrom(string $fecha, int $min, int $max): int
  {
    $raw = TrackerSchema::raw($this->db);
    $low = $min;
    $high = $max + 1;
    while ($low < $high) {
      $mid = intdiv($low + $high, 2);
      $row = $this->db->getRow('SELECT `_ID`, `fecha_visita` FROM `' . $raw . '` WHERE `_ID` >= ? ORDER BY `_ID` ASC LIMIT 1', [$mid]);
      if ($row === null || (string) $row['fecha_visita'] >= $fecha) {
        $high = $mid;
      } else {
        $low = (int) $row['_ID'] + 1;
      }
    }
    return $low;
  }

  /**
   * @param array<string,mixed> $row
   * @param array<string,string> $names
   * @return array<string,mixed>
   */
  private function logRow(array $row, bool $bot, bool $internal, bool $withNetwork, array $names): array
  {
    $campaign = TrackerAggregator::dimension($row['camp_slug'] ?? '', TrackerSchema::EMPTY_CAMPAIGN);
    $out = [
      'id' => (int) $row['_ID'],
      'fecha' => (string) $row['fecha_visita'],
      'campaign' => $campaign,
      'campaign_name' => $names[$campaign] ?? $campaign,
      'source' => TrackerAggregator::dimension($row['utm_source'] ?? '', TrackerSchema::EMPTY_SOURCE),
      'medium' => TrackerAggregator::dimension($row['utm_medium'] ?? '', TrackerSchema::EMPTY_MEDIUM),
      'medio' => (string) ($row['medio'] ?? ''),
      'page_url' => (string) ($row['page_url'] ?? ''),
      'referrer' => (string) ($row['referrer'] ?? ''),
      'country' => (string) ($row['country'] ?? ''),
      'city' => (string) ($row['city'] ?? ''),
      'object_id' => (int) ($row['object_id'] ?? 0),
      'object_ref' => (string) ($row['object_ref'] ?? ''),
      'registered' => (int) ($row['user_id'] ?? 0) > 0,
      'bot' => $bot,
      'internal' => $internal,
    ];
    if ($withNetwork) {
      $out['ip'] = (string) ($row['ip_address'] ?? '');
      $out['user_agent'] = (string) ($row['user_agent'] ?? '');
    }
    return $out;
  }

  /** _ID del cursor; null si no hay o está mal formado (vuelve a la primera página). */
  private static function cursor(?string $cursor): ?int
  {
    if ($cursor === null || preg_match('/^\d{1,18}$/', trim($cursor)) !== 1) {
      return null;
    }
    return (int) trim($cursor);
  }

  /**
   * Condición sobre los resúmenes. Las tres tablas comparten fecha, campaña, medio, robot e interna.
   * @return array{0:string,1:list<string|int>}
   */
  private function where(TrackerAnalyticsFilters $filters, bool $applyExclusions): array
  {
    $where = ['`fecha` BETWEEN ? AND ?'];
    $args = [$filters->from, $filters->to];
    foreach (['camp_slug' => $filters->campaign, 'medio' => $filters->medio] as $column => $value) {
      if ($value !== '') {
        $where[] = "`{$column}` = ?";
        $args[] = $value;
      }
    }
    if ($applyExclusions && $filters->excludeBots) {
      $where[] = '`bot` = 0';
    }
    if ($applyExclusions && $filters->excludeInternal) {
      $where[] = '`internal` = 0';
    }
    return [implode(' AND ', $where), $args];
  }
}
