<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Lecturas de Analítica web. Adaptación de dashboard-marketing app/AnalyticsRepository.php (summary, generalBreakdowns,
 * options y la pestaña de registros): los indicadores, la serie diaria y las listas salen solo de los resúmenes diarios
 * (scm_tracker_*), nunca de la tabla cruda; el visor de registros es lo único que lee `jet_cct_tracker_logs`, a pedido,
 * con un rango de hasta 90 días y paginación por llave (fecha_visita, _ID).
 *
 * Sin el permiso promocion_ver_ip el visor no devuelve IP ni navegador (ni siquiera en el JSON).
 */
final class TrackerAnalyticsRepository
{
  /** Listas disponibles => [tabla de origen, columna o dimensión]. */
  public const LISTS = [
    'campaigns' => ['daily', 'camp_slug'],
    'sources' => ['daily', 'utm_source'],
    'mediums' => ['daily', 'utm_medium'],
    'pages' => ['top', 'page'],
    'referrers' => ['top', 'referrer'],
    'countries' => ['top', 'country'],
    'cities' => ['top', 'city'],
    'objects' => ['top', 'object'],
  ];

  public const LOG_PAGE_SIZE = 25;
  private const LOG_CHUNK = 100;
  private const LOG_MAX_CHUNKS = 5;

  private Database $db;
  private Settings $settings;
  /** @var array<string,string>|null */
  private ?array $campaignNames = null;

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
    $row = $this->db->getRow(
      "SELECT COALESCE(SUM(`hits`), 0) AS visits, COALESCE(SUM(CASE WHEN `registered` = 1 THEN `hits` ELSE 0 END), 0) AS registered,
              COUNT(DISTINCT `camp_slug`) AS campaigns
         FROM `{$daily}` WHERE {$where}",
      $args
    ) ?? [];
    [$baseWhere, $baseArgs] = $this->where($filters, false);
    $base = $this->db->getRow(
      "SELECT COALESCE(SUM(`hits`), 0) AS total, COALESCE(SUM(CASE WHEN `bot` = 1 THEN `hits` ELSE 0 END), 0) AS bots,
              COALESCE(SUM(CASE WHEN `bot` = 0 AND `internal` = 1 THEN `hits` ELSE 0 END), 0) AS internal
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
   * Lista de los más visitados. Campañas, fuentes y medios incluyen visitantes únicos.
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
      return array_map(static fn(array $row): array => [
        'value' => (string) $row['label'],
        'label' => $names[(string) $row['label']] ?? (string) $row['label'],
        'detail' => '',
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
   * Opciones de los filtros: lo que aparece en los resúmenes de los últimos 12 meses, con el nombre de cada campaña.
   * @return array{campaigns:list<array{value:string,label:string}>,sources:list<string>,mediums:list<string>}
   */
  public function options(?string $today = null): array
  {
    $out = ['campaigns' => [], 'sources' => [], 'mediums' => []];
    if (!$this->ready()) {
      return $out;
    }
    $since = TrackerAnalyticsFilters::shift($today ?? date('Y-m-d'), -(TrackerAnalyticsFilters::MAX_DAYS - 1));
    $daily = TrackerSchema::daily($this->db);
    $names = $this->campaignNames();
    foreach ($this->db->getCol("SELECT DISTINCT `camp_slug` FROM `{$daily}` WHERE `fecha` >= ? ORDER BY `camp_slug`", [$since]) as $slug) {
      $out['campaigns'][] = ['value' => (string) $slug, 'label' => $names[(string) $slug] ?? (string) $slug];
    }
    $out['sources'] = array_map('strval', $this->db->getCol("SELECT DISTINCT `utm_source` FROM `{$daily}` WHERE `fecha` >= ? ORDER BY `utm_source`", [$since]));
    $out['mediums'] = array_map('strval', $this->db->getCol("SELECT DISTINCT `utm_medium` FROM `{$daily}` WHERE `fecha` >= ? ORDER BY `utm_medium`", [$since]));
    return $out;
  }

  /** Nombres de campaña de `jet_cct_tracker_camps` (slug => nombre). @return array<string,string> */
  public function campaignNames(): array
  {
    if ($this->campaignNames !== null) {
      return $this->campaignNames;
    }
    $this->campaignNames = [];
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
   * Visor de registros crudos, a pedido. Hasta 90 días; 25 filas por página ordenadas por (fecha_visita, _ID) descendente.
   * Los robots y las visitas internas se reconocen aquí mismo y se omiten si los filtros lo piden.
   *
   * @param string|null $cursor 'AAAA-MM-DD HH:MM:SS|_ID' de la última fila de la página anterior.
   * @param bool $withNetwork Solo con el permiso promocion_ver_ip: agrega IP y navegador.
   * @return array{rows:list<array<string,mixed>>,next_cursor:string|null,from:string,to:string,clamped:bool}
   */
  public function logs(TrackerAnalyticsFilters $filters, ?string $cursor = null, bool $withNetwork = false): array
  {
    $filters = $filters->limitedTo(TrackerAnalyticsFilters::LOG_MAX_DAYS);
    $raw = TrackerSchema::raw($this->db);
    $where = ['`fecha_visita` >= ?', '`fecha_visita` < ?'];
    $args = [$filters->from . ' 00:00:00', TrackerAnalyticsFilters::shift($filters->to, 1) . ' 00:00:00'];
    foreach (['camp_slug' => [$filters->campaign, TrackerSchema::EMPTY_CAMPAIGN], 'utm_source' => [$filters->source, TrackerSchema::EMPTY_SOURCE], 'utm_medium' => [$filters->medium, TrackerSchema::EMPTY_MEDIUM]] as $column => [$value, $empty]) {
      if ($value === '') {
        continue;
      }
      // En los resúmenes los vacíos quedaron con su nombre ('general', 'Directo', 'Sin medio'): aquí se buscan ambos.
      $where[] = $value === $empty ? "(`{$column}` = ? OR `{$column}` = '' OR `{$column}` IS NULL)" : "`{$column}` = ?";
      $args[] = $value;
    }
    $position = self::cursor($cursor);
    $config = TrackerAnalyticsConfig::read($this->settings);
    $internal = new InternalIpMatcher($config['internal_ips']);
    $names = $this->campaignNames();
    $kept = [];
    $nextCursor = null;
    $scanned = 0;
    while ($scanned < self::LOG_MAX_CHUNKS) {
      $pageWhere = $where;
      $pageArgs = $args;
      if ($position !== null) {
        $pageWhere[] = '(`fecha_visita` < ? OR (`fecha_visita` = ? AND `_ID` < ?))';
        array_push($pageArgs, $position[0], $position[0], $position[1]);
      }
      $rows = $this->db->getResults(
        'SELECT `_ID`, `fecha_visita`, `camp_slug`, `user_id`, `ip_address`, `user_agent`, `page_url`, `referrer`, `country`, `city`, `object_id`, `object_ref`, `utm_source`, `utm_medium`
           FROM `' . $raw . '` WHERE ' . implode(' AND ', $pageWhere) . ' ORDER BY `fecha_visita` DESC, `_ID` DESC LIMIT ' . self::LOG_CHUNK,
        $pageArgs
      );
      $scanned++;
      foreach ($rows as $row) {
        $position = [(string) $row['fecha_visita'], (int) $row['_ID']];
        $bot = BotDetector::isBot((string) ($row['user_agent'] ?? ''));
        $isInternal = $internal->matches((string) ($row['ip_address'] ?? ''));
        if (($filters->excludeBots && $bot) || ($filters->excludeInternal && $isInternal)) {
          continue;
        }
        if (count($kept) === self::LOG_PAGE_SIZE) {
          // Hay al menos una fila más: la página siguiente empieza después de la última mostrada.
          $last = $kept[self::LOG_PAGE_SIZE - 1];
          return ['rows' => $kept, 'next_cursor' => $last['fecha'] . '|' . $last['id'], 'from' => $filters->from, 'to' => $filters->to, 'clamped' => $filters->clamped];
        }
        $kept[] = $this->logRow($row, $bot, $isInternal, $withNetwork, $names);
      }
      if (count($rows) < self::LOG_CHUNK) {
        $position = null;
        break;
      }
    }
    if ($position !== null) {
      // Se revisaron muchas filas omitidas (robots o internas): se sigue desde la última revisada.
      $nextCursor = $position[0] . '|' . $position[1];
    }
    return ['rows' => $kept, 'next_cursor' => $nextCursor, 'from' => $filters->from, 'to' => $filters->to, 'clamped' => $filters->clamped];
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

  /** @return array{0:string,1:int}|null */
  private static function cursor(?string $cursor): ?array
  {
    if ($cursor === null || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,20})$/', trim($cursor), $match) !== 1) {
      return null;
    }
    return [$match[1], (int) $match[2]];
  }

  /**
   * Condición sobre los resúmenes. Las tres tablas comparten fecha, campaña, fuente, medio, robot e interna.
   * @return array{0:string,1:list<string|int>}
   */
  private function where(TrackerAnalyticsFilters $filters, bool $applyExclusions): array
  {
    $where = ['`fecha` BETWEEN ? AND ?'];
    $args = [$filters->from, $filters->to];
    foreach (['camp_slug' => $filters->campaign, 'utm_source' => $filters->source, 'utm_medium' => $filters->medium] as $column => $value) {
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
