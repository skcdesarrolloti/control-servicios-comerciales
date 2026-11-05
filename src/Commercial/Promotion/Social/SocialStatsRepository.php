<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social;

use SCM\Core\Database;

/**
 * Consultas de Promoción › Redes sociales. Lee las tablas que llena la sincronización (Social\Sync, SocialSyncRunner):
 * marketing_social_* (Instagram, Facebook, publicidad de Meta y audiencia), marketing_youtube_* y marketing_tiktok_*.
 * Copia de los cálculos de dashboard-marketing (app/SocialStatsRepository.php, YouTubeRepository::dashboard,
 * TikTokRepository::dashboard y views/social-stats.php): comparación contra el periodo anterior, agrupación por día, semana o
 * mes e Instagram medido desde sus publicaciones. Facebook mide visualizaciones porque Meta ya no entrega su alcance.
 * Diferencia con ese panel: la publicidad toma el último periodo con actividad y los segmentos de YouTube el último guardado,
 * porque la sincronización diaria guarda periodos vacíos o más cortos que dejarían la pantalla en cero.
 * Nunca lee los tokens de conexión. La inversión (gasto, CPC, CPM) solo sale con $withSpend (permiso redes_ver_inversion).
 */
final class SocialStatsRepository
{
  public const NETWORKS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'publicidad' => 'Publicidad en Meta', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];
  public const DEFAULT_DAYS = 90;
  public const MAX_DAYS = 731;
  private const TOP_LIMIT = 8;
  private const LIST_LIMIT = 1000;
  private const MONTHS = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
  private const SHORT_MONTHS = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /**
   * Red y rango pedidos. Sin fechas, los últimos 90 días hasta el último dato de esa red (la sincronización puede ir
   * atrasada y un rango vacío no sirve).
   * @param array<string,mixed> $input pr_red, pr_desde, pr_hasta
   * @return array{network:string,from:string,to:string,clamped:bool,latest:string,today:string}
   */
  public function filters(array $input, string $today): array
  {
    $network = (string) ($input['pr_red'] ?? '');
    $network = isset(self::NETWORKS[$network]) ? $network : 'instagram';
    $latest = $this->latestDate($network);
    $date = static function ($value): string {
      $value = is_scalar($value) ? trim((string) $value) : '';
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
      return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : '';
    };
    $to = $date($input['pr_hasta'] ?? '') ?: ($latest !== '' && $latest < $today ? $latest : $today);
    $from = $date($input['pr_desde'] ?? '') ?: (new \DateTimeImmutable($to))->modify('-' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d');
    if ($from > $to) {
      [$from, $to] = [$to, $from];
    }
    $clamped = false;
    $min = (new \DateTimeImmutable($to))->modify('-' . (self::MAX_DAYS - 1) . ' days')->format('Y-m-d');
    if ($from < $min) {
      $from = $min;
      $clamped = true;
    }
    return ['network' => $network, 'from' => $from, 'to' => $to, 'clamped' => $clamped, 'latest' => $latest, 'today' => $today];
  }

  /** Último día con datos de la red ('' si no hay). */
  public function latestDate(string $network): string
  {
    try {
      $value = match ($network) {
        'instagram', 'facebook' => $this->db->getVar('SELECT MAX(metric_date) FROM `' . $this->table('marketing_social_daily_stats') . '` WHERE platform = ?', [$network]),
        'publicidad' => $this->db->getVar('SELECT MAX(metric_date) FROM `' . $this->table('marketing_social_ads_stats') . "` WHERE level = 'account_daily'"),
        'youtube' => $this->db->getVar('SELECT MAX(metric_date) FROM `' . $this->table('marketing_youtube_daily_stats') . '` WHERE channel_id = ?', [$this->connectedId('marketing_youtube_connections', 'channel_id')]),
        default => $this->db->getVar('SELECT MAX(published_at) FROM `' . $this->table('marketing_tiktok_videos') . '` WHERE open_id = ?', [$this->connectedId('marketing_tiktok_connections', 'open_id')]),
      };
      return substr((string) ($value ?? ''), 0, 10);
    } catch (\Throwable) {
      return '';
    }
  }

  /**
   * Todo lo de una red para el rango.
   * @param array{network:string,from:string,to:string} $filters
   * @return array<string,mixed>
   */
  public function summary(array $filters, bool $withSpend): array
  {
    try {
      return match ($filters['network']) {
        'instagram', 'facebook' => $this->meta($filters),
        'publicidad' => $this->ads($filters, $withSpend),
        'youtube' => $this->youtube($filters),
        default => $this->tiktok($filters),
      } + ['available' => true];
    } catch (\Throwable $exception) {
      error_log('[commercial-social] ' . $exception->getMessage());
      return ['available' => false];
    }
  }

  /** Día hasta 45 días, semana hasta 550 y mes en adelante, igual que el panel anterior. */
  public static function granularity(string $from, string $to): string
  {
    $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    return $days > 550 ? 'month' : ($days > 45 ? 'week' : 'day');
  }

  /**
   * Agrupa filas diarias por periodo. Las de $last conservan el último valor (seguidores al cierre).
   * @param list<array<string,mixed>> $rows
   * @param list<string> $sum
   * @param list<string> $last
   * @return list<array<string,mixed>> Cada fila con label (completa) y short (eje).
   */
  public static function group(array $rows, string $dateKey, array $sum, array $last, string $granularity): array
  {
    /** @var array<string,array<string,mixed>> $buckets */
    $buckets = [];
    foreach ($rows as $row) {
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($row[$dateKey] ?? ''), 0, 10));
      if (!$date instanceof \DateTimeImmutable) {
        continue;
      }
      $month = static fn(\DateTimeImmutable $day): string => self::SHORT_MONTHS[(int) $day->format('n')];
      if ($granularity === 'month') {
        $start = $date->modify('first day of this month');
        $label = self::MONTHS[(int) $start->format('n')] . ' ' . $start->format('Y');
        $short = $month($start) . ' ' . $start->format('y');
      } elseif ($granularity === 'week') {
        $start = $date->modify('monday this week');
        $end = $start->modify('+6 days');
        $label = $start->format('d') . ' ' . $month($start) . ' – ' . $end->format('d') . ' ' . $month($end) . ' ' . $end->format('Y');
        $short = $start->format('d') . ' ' . $month($start);
      } else {
        $start = $date;
        $label = $date->format('d') . ' ' . $month($date) . ' ' . $date->format('Y');
        $short = $date->format('d') . ' ' . $month($date);
      }
      $key = $start->format('Y-m-d');
      $buckets[$key] ??= ['label' => $label, 'short' => $short] + array_fill_keys($sum, 0.0) + array_fill_keys($last, 0);
      foreach ($sum as $metric) {
        $buckets[$key][$metric] = (float) $buckets[$key][$metric] + (float) ($row[$metric] ?? 0);
      }
      foreach ($last as $metric) {
        $buckets[$key][$metric] = (int) ($row[$metric] ?? 0);
      }
    }
    ksort($buckets);
    return array_values($buckets);
  }

  /** @param list<array<string,mixed>> $rows @return list<array{label:string,items:list<array<string,mixed>>}> */
  public static function months(array $rows, string $dateKey): array
  {
    $months = [];
    foreach ($rows as $row) {
      $date = (string) ($row[$dateKey] ?? '');
      $key = preg_match('/^\d{4}-\d{2}/', $date) === 1 ? substr($date, 0, 7) : 'sin-fecha';
      $months[$key] ??= ['label' => $key === 'sin-fecha' ? 'Sin fecha' : self::MONTHS[(int) substr($key, 5, 2)] . ' ' . substr($key, 0, 4), 'items' => []];
      $months[$key]['items'][] = $row;
    }
    return array_values($months);
  }

  /** @return array{from:string,to:string,days:int} */
  private static function previousPeriod(string $from, string $to): array
  {
    $start = new \DateTimeImmutable($from);
    $days = (int) $start->diff(new \DateTimeImmutable($to))->days + 1;
    $end = $start->modify('-1 day');
    return ['from' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'days' => $days];
  }

  /**
   * @param list<string> $dates Días con datos del rango.
   * @return array{ready:bool,previous_from:string,previous_to:string,requested:int,covered:int,first:string,last:string}
   */
  private static function comparison(array $dates, array $previous, bool $ready): array
  {
    return ['ready' => $ready, 'previous_from' => $previous['from'], 'previous_to' => $previous['to'], 'requested' => $previous['days'], 'covered' => count($dates), 'first' => $dates !== [] ? min($dates) : '', 'last' => $dates !== [] ? max($dates) : ''];
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function meta(array $filters): array
  {
    $platform = $filters['network'];
    $period = self::previousPeriod($filters['from'], $filters['to']);
    $exposure = $platform === 'facebook' ? 'impressions' : 'reach';
    $rows = $this->dailyRows($platform, $filters['from'], $filters['to']);
    $before = $this->dailyRows($platform, $period['from'], $period['to']);
    $current = $this->metaSummary($platform, $rows, $filters['from'], $filters['to'], $exposure);
    $previous = $this->metaSummary($platform, $before, $period['from'], $period['to'], $exposure);
    $dates = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['metric_date'], $rows)));
    $previousDays = count(array_unique(array_map(static fn(array $row): string => (string) $row['metric_date'], $before)));
    $account = $this->db->getRow('SELECT account_name, username, connected, last_sync FROM `' . $this->table('marketing_social_accounts') . '` WHERE platform = ? ORDER BY connected DESC LIMIT 1', [$platform]) ?? [];
    $posts = array_map(fn(array $row): array => $this->post($row), $this->db->getResults('SELECT post_date, post_time, platform, content_type, title, permalink, thumbnail_url, reach, impressions, likes, comments, shares, saves, metrics_json FROM `' . $this->table('marketing_social_posts') . '` WHERE platform = ? AND post_date BETWEEN ? AND ? ORDER BY post_date DESC, post_time DESC, _ID DESC LIMIT ' . self::LIST_LIMIT, [$platform, $filters['from'], $filters['to']]));
    $top = $posts;
    usort($top, static fn(array $a, array $b): int => [$b['interactions'], $b['reach']] <=> [$a['interactions'], $a['reach']]);
    $series = array_map(static function (array $row) use ($exposure): array {
      $row['exposure'] = (int) $row[$exposure];
      $row['interactions'] = (int) ($row['likes'] + $row['comments'] + $row['shares'] + $row['saves']);
      return $row;
    }, self::group($rows, 'metric_date', ['reach', 'impressions', 'likes', 'comments', 'shares', 'saves'], ['followers'], self::granularity($filters['from'], $filters['to'])));
    return [
      'account' => ['name' => (string) ($account['account_name'] ?? ''), 'username' => (string) ($account['username'] ?? ''), 'last_sync' => (string) ($account['last_sync'] ?? ''), 'connected' => (int) ($account['connected'] ?? 0) === 1],
      'exposure' => $exposure, 'kpis' => $current, 'previous' => $previous, 'comparison' => self::comparison($dates, $period, $previousDays >= $period['days']),
      'series' => $series, 'posts' => $posts, 'top' => array_slice($top, 0, self::TOP_LIMIT), 'audience' => $this->audience($platform),
    ];
  }

  /** @return list<array<string,mixed>> */
  private function dailyRows(string $platform, string $from, string $to): array
  {
    return $this->db->getResults('SELECT metric_date, followers, reach, impressions, profile_views, website_clicks, likes, comments, shares, saves, content_count FROM `' . $this->table('marketing_social_daily_stats') . '` WHERE platform = ? AND metric_date BETWEEN ? AND ? ORDER BY metric_date', [$platform, $from, $to]);
  }

  /**
   * Instagram reemplaza sus sumas diarias por las de las publicaciones del periodo, como el panel anterior.
   * @param list<array<string,mixed>> $rows
   * @return array{followers:int,exposure:int,interactions:int,rate:float,profile_views:int,website_clicks:int,posts:int}
   */
  private function metaSummary(string $platform, array $rows, string $from, string $to, string $exposure): array
  {
    $sum = static fn(string $key): int => array_sum(array_map(static fn(array $row): int => (int) ($row[$key] ?? 0), $rows));
    $totals = ['reach' => $sum('reach'), 'impressions' => $sum('impressions'), 'likes' => $sum('likes'), 'comments' => $sum('comments'), 'shares' => $sum('shares'), 'saves' => $sum('saves'), 'posts' => $sum('content_count')];
    if ($platform === 'instagram') {
      $posts = $this->db->getRow('SELECT COUNT(*) AS posts, COALESCE(SUM(reach), 0) AS reach, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(likes), 0) AS likes, COALESCE(SUM(comments), 0) AS comments, COALESCE(SUM(shares), 0) AS shares, COALESCE(SUM(saves), 0) AS saves FROM `' . $this->table('marketing_social_posts') . '` WHERE platform = ? AND post_date BETWEEN ? AND ?', [$platform, $from, $to]) ?? [];
      $totals = array_map(static fn($value): int => (int) $value, $posts) + $totals;
    }
    $followers = array_values(array_filter(array_map(static fn(array $row): int => (int) $row['followers'], $rows)));
    $interactions = $totals['likes'] + $totals['comments'] + $totals['shares'] + $totals['saves'];
    return ['followers' => $followers !== [] ? (int) end($followers) : 0, 'exposure' => $totals[$exposure], 'interactions' => $interactions, 'rate' => $totals[$exposure] > 0 ? round($interactions * 100 / $totals[$exposure], 2) : 0.0,
      'profile_views' => $sum('profile_views'), 'website_clicks' => $sum('website_clicks'), 'posts' => $totals['posts']];
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function post(array $row): array
  {
    $metrics = json_decode((string) ($row['metrics_json'] ?? ''), true);
    $metrics = is_array($metrics) ? $metrics : [];
    return ['date' => (string) $row['post_date'], 'time' => (string) ($row['post_time'] ?? ''), 'platform' => (string) $row['platform'], 'type' => (string) ($row['content_type'] ?? ''), 'title' => (string) ($row['title'] ?? ''),
      'url' => (string) ($row['permalink'] ?? ''), 'thumbnail' => (string) ($row['thumbnail_url'] ?? ''), 'reach' => (int) $row['reach'], 'impressions' => (int) $row['impressions'], 'likes' => (int) $row['likes'],
      'comments' => (int) $row['comments'], 'shares' => (int) $row['shares'], 'saves' => (int) $row['saves'], 'interactions' => (int) $row['likes'] + (int) $row['comments'] + (int) $row['shares'] + (int) $row['saves'],
      'clicks' => isset($metrics['post_clicks']) && is_scalar($metrics['post_clicks']) ? (int) $metrics['post_clicks'] : null,
      'plays' => isset($metrics['post_video_views']) && is_scalar($metrics['post_video_views']) ? (int) $metrics['post_video_views'] : (isset($metrics['views']) && is_scalar($metrics['views']) ? (int) $metrics['views'] : null)];
  }

  /**
   * Última foto de la audiencia, por tipo (edad, género, ciudad y país; los 10 más grandes de cada uno).
   * @return array{snapshot:string,groups:array<string,list<array{label:string,value:int}>>}
   */
  private function audience(string $platform): array
  {
    $table = $this->table('marketing_social_audience');
    $snapshot = (string) ($this->db->getVar("SELECT MAX(snapshot_date) FROM `{$table}` WHERE platform = ?", [$platform]) ?? '');
    $groups = [];
    if ($snapshot !== '') {
      foreach ($this->db->getResults("SELECT breakdown, label, value FROM `{$table}` WHERE platform = ? AND snapshot_date = ? ORDER BY breakdown, value DESC", [$platform, $snapshot]) as $row) {
        $key = (string) $row['breakdown'];
        if (count($groups[$key] ?? []) < 10) {
          $groups[$key][] = ['label' => (string) $row['label'], 'value' => (int) $row['value']];
        }
      }
    }
    return ['snapshot' => $snapshot, 'groups' => $groups];
  }

  /**
   * Periodo exacto del filtro si se sincronizó; si no, el último guardado con actividad y el más largo de esa fecha.
   * @return array{from:string,to:string,exact:bool}|null
   */
  private function adsPeriod(string $from, string $to): ?array
  {
    $table = $this->table('marketing_social_ads_stats');
    $exact = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE period_start = ? AND metric_date = ? AND level IN ('account', 'campaign', 'ad') AND impressions > 0", [$from, $to]);
    if ($exact > 0) {
      return ['from' => $from, 'to' => $to, 'exact' => true];
    }
    $latest = $this->db->getRow("SELECT period_start, metric_date FROM `{$table}` WHERE level IN ('account', 'campaign') AND impressions > 0 ORDER BY metric_date DESC, period_start ASC LIMIT 1");
    return $latest !== null ? ['from' => (string) $latest['period_start'], 'to' => (string) $latest['metric_date'], 'exact' => false] : null;
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function ads(array $filters, bool $withSpend): array
  {
    $table = $this->table('marketing_social_ads_stats');
    $daily = $this->db->getResults("SELECT metric_date, spend, impressions, reach, clicks, link_clicks FROM `{$table}` WHERE level = 'account_daily' AND metric_date BETWEEN ? AND ? ORDER BY metric_date", [$filters['from'], $filters['to']]);
    $period = $this->adsPeriod($filters['from'], $filters['to']);
    $rows = $period === null ? [] : $this->db->getResults("SELECT level, name, status, objective, spend, impressions, reach, clicks, link_clicks, ctr, cpc, cpm, frequency, actions_json, thumbnail_url, period_start, metric_date FROM `{$table}` WHERE period_start = ? AND metric_date = ? AND level IN ('account', 'campaign', 'ad') ORDER BY spend DESC, impressions DESC, name ASC", [$period['from'], $period['to']]);
    $level = static fn(string $name): array => array_values(array_filter($rows, static fn(array $row): bool => $row['level'] === $name));
    $accounts = $level('account') ?: $level('campaign');
    $sum = static fn(string $key): float => array_sum(array_map(static fn(array $row): float => (float) $row[$key], $accounts));
    $impressions = $sum('impressions');
    $clicks = $sum('clicks');
    $kpis = ['impressions' => (int) $impressions, 'reach' => (int) $sum('reach'), 'clicks' => (int) $clicks, 'link_clicks' => (int) $sum('link_clicks'), 'ctr' => $impressions > 0 ? $clicks * 100 / $impressions : 0.0];
    if ($withSpend) {
      $spend = $sum('spend');
      $kpis += ['spend' => $spend, 'cpc' => $clicks > 0 ? $spend / $clicks : 0.0];
    }
    $map = fn(array $row): array => $this->adRow($row, $withSpend);
    $series = array_map(static fn(array $row): array => ['impressions' => (int) $row['impressions'], 'link_clicks' => (int) $row['link_clicks'], 'clicks' => (int) $row['clicks'], 'reach' => (int) $row['reach'], 'spend' => $withSpend ? $row['spend'] : null] + $row, self::group($daily, 'metric_date', ['spend', 'impressions', 'reach', 'clicks', 'link_clicks'], [], self::granularity($filters['from'], $filters['to'])));
    return ['kpis' => $kpis, 'period' => $period, 'series' => $withSpend ? $series : array_map(static fn(array $row): array => array_diff_key($row, ['spend' => true]), $series),
      'campaigns' => array_map($map, $level('campaign')), 'ads' => array_map($map, $level('ad')), 'with_spend' => $withSpend];
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function adRow(array $row, bool $withSpend): array
  {
    $actions = json_decode((string) ($row['actions_json'] ?? ''), true);
    $list = [];
    foreach (is_array($actions) ? $actions : [] as $action) {
      $type = is_array($action) ? (string) ($action['action_type'] ?? '') : '';
      $value = is_array($action) ? (int) ($action['value'] ?? 0) : 0;
      if ($type !== '' && $value > 0) {
        $list[] = ['type' => $type, 'value' => $value];
      }
    }
    usort($list, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
    $out = ['name' => (string) ($row['name'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'objective' => (string) ($row['objective'] ?? ''), 'impressions' => (int) $row['impressions'], 'reach' => (int) $row['reach'], 'clicks' => (int) $row['clicks'],
      'link_clicks' => (int) $row['link_clicks'], 'ctr' => (float) $row['ctr'], 'frequency' => (float) ($row['frequency'] ?? 0), 'date' => (string) $row['metric_date'], 'since' => (string) $row['period_start'], 'thumbnail' => (string) ($row['thumbnail_url'] ?? ''), 'actions' => array_slice($list, 0, 8)];
    return $withSpend ? $out + ['spend' => (float) $row['spend'], 'cpc' => (float) $row['cpc'], 'cpm' => (float) $row['cpm']] : $out;
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function youtube(array $filters): array
  {
    $channel = $this->db->getRow('SELECT channel_id, channel_title, channel_handle, thumbnail_url, subscriber_count, view_count, video_count, last_sync, last_error FROM `' . $this->table('marketing_youtube_connections') . '` WHERE connected = 1 ORDER BY last_sync DESC LIMIT 1') ?? [];
    $id = (string) ($channel['channel_id'] ?? '');
    $period = self::previousPeriod($filters['from'], $filters['to']);
    $dailyTable = $this->table('marketing_youtube_daily_stats');
    $rows = $this->db->getResults("SELECT metric_date, views, engaged_views, watch_minutes, likes, comments, shares, subscribers_gained, subscribers_lost, videos_published FROM `{$dailyTable}` WHERE channel_id = ? AND metric_date BETWEEN ? AND ? ORDER BY metric_date", [$id, $filters['from'], $filters['to']]);
    $previousDays = (int) $this->db->getVar("SELECT COUNT(DISTINCT metric_date) FROM `{$dailyTable}` WHERE channel_id = ? AND metric_date BETWEEN ? AND ?", [$id, $period['from'], $period['to']]);
    $videos = array_map(static function (array $row): array {
      $row['display_views'] = (int) $row['views'] ?: (int) $row['public_views'];
      return $row;
    }, $this->db->getResults('SELECT title, description, published_at, permalink, thumbnail_url, content_type, duration_seconds, public_views, public_likes, public_comments, views, engaged_views, watch_minutes, average_view_duration, average_view_percentage, likes, comments, shares, subscribers_gained, subscribers_lost, analytics_from, analytics_to FROM `' . $this->table('marketing_youtube_videos') . '` WHERE channel_id = ? AND published_at BETWEEN ? AND ? ORDER BY published_at DESC, _ID DESC LIMIT ' . self::LIST_LIMIT, [$id, $filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']));
    $top = $videos;
    usort($top, static fn(array $a, array $b): int => $b['display_views'] <=> $a['display_views']);
    return [
      'account' => ['name' => (string) ($channel['channel_title'] ?? ''), 'username' => (string) ($channel['channel_handle'] ?? ''), 'last_sync' => (string) ($channel['last_sync'] ?? ''), 'thumbnail' => (string) ($channel['thumbnail_url'] ?? ''),
        'subscribers' => (int) ($channel['subscriber_count'] ?? 0), 'total_views' => (int) ($channel['view_count'] ?? 0), 'videos' => (int) ($channel['video_count'] ?? 0), 'error' => (string) ($channel['last_error'] ?? '')],
      'kpis' => $this->youtubeSummary($id, $filters['from'], $filters['to']), 'previous' => $this->youtubeSummary($id, $period['from'], $period['to']),
      'comparison' => self::comparison(array_map(static fn(array $row): string => (string) $row['metric_date'], $rows), $period, $previousDays > 0),
      'series' => self::group($rows, 'metric_date', ['views', 'engaged_views', 'watch_minutes', 'likes', 'comments', 'shares', 'subscribers_gained', 'subscribers_lost', 'videos_published'], [], self::granularity($filters['from'], $filters['to'])),
      'videos' => $videos, 'top' => array_slice($top, 0, self::TOP_LIMIT),
    ] + $this->youtubeBreakdowns($id, $filters['from'], $filters['to']);
  }

  /** @return array<string,float|int> */
  private function youtubeSummary(string $channel, string $from, string $to): array
  {
    $row = $this->db->getRow('SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(engaged_views), 0) AS engaged_views, COALESCE(SUM(watch_minutes), 0) AS watch_minutes,
        COALESCE(SUM(average_view_duration * views) / NULLIF(SUM(views), 0), 0) AS average_view_duration, COALESCE(SUM(average_view_percentage * views) / NULLIF(SUM(views), 0), 0) AS average_view_percentage,
        COALESCE(SUM(likes), 0) AS likes, COALESCE(SUM(comments), 0) AS comments, COALESCE(SUM(shares), 0) AS shares, COALESCE(SUM(subscribers_gained), 0) AS gained, COALESCE(SUM(subscribers_lost), 0) AS lost, COALESCE(SUM(videos_published), 0) AS videos_published
      FROM `' . $this->table('marketing_youtube_daily_stats') . '` WHERE channel_id = ? AND metric_date BETWEEN ? AND ?', [$channel, $from, $to]) ?? [];
    return ['views' => (int) $row['views'], 'engaged_views' => (int) $row['engaged_views'], 'watch_minutes' => (int) round((float) $row['watch_minutes']), 'average_view_duration' => (float) $row['average_view_duration'], 'average_view_percentage' => (float) $row['average_view_percentage'],
      'subscribers_net' => (int) $row['gained'] - (int) $row['lost'], 'interactions' => (int) $row['likes'] + (int) $row['comments'] + (int) $row['shares'], 'videos_published' => (int) $row['videos_published']];
  }

  /**
   * Segmentos del periodo exacto o, si ese rango no se sincronizó, los del último periodo guardado.
   * @return array{breakdowns:array<string,list<array{label:string,value:float,watch_minutes:float}>>,breakdowns_period:array{from:string,to:string,exact:bool}|null}
   */
  private function youtubeBreakdowns(string $channel, string $from, string $to): array
  {
    $table = $this->table('marketing_youtube_breakdowns');
    $period = $this->db->getRow("SELECT period_from, period_to FROM `{$table}` WHERE channel_id = ? AND period_from = ? AND period_to = ? LIMIT 1", [$channel, $from, $to])
      ?? $this->db->getRow("SELECT period_from, period_to FROM `{$table}` WHERE channel_id = ? ORDER BY period_to DESC, period_from ASC LIMIT 1", [$channel]);
    if ($period === null) {
      return ['breakdowns' => [], 'breakdowns_period' => null];
    }
    $groups = [];
    foreach ($this->db->getResults("SELECT breakdown_type, label, value, watch_minutes FROM `{$table}` WHERE channel_id = ? AND period_from = ? AND period_to = ? ORDER BY breakdown_type, value DESC", [$channel, $period['period_from'], $period['period_to']]) as $row) {
      $groups[(string) $row['breakdown_type']][] = ['label' => (string) $row['label'], 'value' => (float) $row['value'], 'watch_minutes' => (float) $row['watch_minutes']];
    }
    return ['breakdowns' => $groups, 'breakdowns_period' => ['from' => (string) $period['period_from'], 'to' => (string) $period['period_to'], 'exact' => $period['period_from'] === $from && $period['period_to'] === $to]];
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function tiktok(array $filters): array
  {
    $account = $this->db->getRow('SELECT open_id, display_name, username, avatar_url, follower_count, likes_count, video_count, last_sync, last_error FROM `' . $this->table('marketing_tiktok_connections') . '` WHERE connected = 1 ORDER BY last_sync DESC LIMIT 1') ?? [];
    $id = (string) ($account['open_id'] ?? '');
    $period = self::previousPeriod($filters['from'], $filters['to']);
    $list = fn(string $from, string $to): array => $this->db->getResults('SELECT title, description, published_at, permalink, cover_url, duration_seconds, views, likes, comments, shares FROM `' . $this->table('marketing_tiktok_videos') . '` WHERE open_id = ? AND published_at BETWEEN ? AND ? ORDER BY published_at DESC, _ID DESC LIMIT ' . self::LIST_LIMIT, [$id, $from . ' 00:00:00', $to . ' 23:59:59']);
    $videos = $list($filters['from'], $filters['to']);
    $before = $list($period['from'], $period['to']);
    $summary = static function (array $rows): array {
      $sum = static fn(string $key): int => array_sum(array_map(static fn(array $row): int => (int) $row[$key], $rows));
      return ['videos' => count($rows), 'views' => $sum('views'), 'likes' => $sum('likes'), 'comments' => $sum('comments'), 'shares' => $sum('shares'), 'interactions' => $sum('likes') + $sum('comments') + $sum('shares')];
    };
    $rows = array_map(static fn(array $row): array => ['date' => substr((string) $row['published_at'], 0, 10), 'videos_published' => 1, 'interactions' => (int) $row['likes'] + (int) $row['comments'] + (int) $row['shares']] + $row, $videos);
    return [
      'account' => ['name' => (string) ($account['display_name'] ?? ''), 'username' => (string) ($account['username'] ?? ''), 'last_sync' => (string) ($account['last_sync'] ?? ''), 'thumbnail' => (string) ($account['avatar_url'] ?? ''),
        'followers' => (int) ($account['follower_count'] ?? 0), 'likes' => (int) ($account['likes_count'] ?? 0), 'videos' => (int) ($account['video_count'] ?? 0), 'error' => (string) ($account['last_error'] ?? '')],
      'kpis' => $summary($videos), 'previous' => $summary($before),
      'comparison' => self::comparison(array_values(array_unique(array_column($rows, 'date'))), $period, $before !== []),
      'series' => self::group($rows, 'date', ['views', 'interactions', 'videos_published'], [], self::granularity($filters['from'], $filters['to'])),
      'videos' => $videos,
    ];
  }

  /** channel_id u open_id de la cuenta conectada: el histórico puede tener otras cuentas que no se deben mezclar. */
  private function connectedId(string $table, string $column): string
  {
    return (string) ($this->db->getVar('SELECT `' . $column . '` FROM `' . $this->table($table) . '` WHERE connected = 1 ORDER BY last_sync DESC LIMIT 1') ?? '');
  }

  private function table(string $name): string
  {
    return $this->db->table($name);
  }
}
