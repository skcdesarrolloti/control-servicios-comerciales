<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social;

use SCM\Core\Database;

/**
 * Consultas de Promoción › Redes sociales. Lee las tablas que llena la sincronización (Social\Sync, SocialSyncRunner):
 * marketing_social_* (Instagram, Facebook, publicidad de Meta y audiencia), marketing_youtube_* y marketing_tiktok_*.
 * Nunca lee los tokens de conexión.
 * La inversión en publicidad (gasto, CPC, CPM) solo sale con $withSpend (permiso redes_ver_inversion).
 */
final class SocialStatsRepository
{
  public const NETWORKS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'publicidad' => 'Publicidad en Meta', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];
  public const DEFAULT_DAYS = 90;
  public const MAX_DAYS = 731;

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /**
   * Red y rango pedidos. Sin fechas, los últimos 90 días hasta el último dato de esa red (la sincronización puede ir
   * atrasada y un rango vacío no sirve).
   * @param array<string,mixed> $input pr_red, pr_desde, pr_hasta
   * @return array{network:string,from:string,to:string,clamped:bool,latest:string}
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
    return ['network' => $network, 'from' => $from, 'to' => $to, 'clamped' => $clamped, 'latest' => $latest];
  }

  /** Último día con datos de la red ('' si no hay). */
  public function latestDate(string $network): string
  {
    $sql = match ($network) {
      'instagram', 'facebook' => ['SELECT MAX(metric_date) FROM `' . $this->table('marketing_social_daily_stats') . '` WHERE platform = ?', [$network]],
      'publicidad' => ['SELECT MAX(metric_date) FROM `' . $this->table('marketing_social_ads_stats') . "` WHERE level = 'account_daily'", []],
      'youtube' => ['SELECT MAX(metric_date) FROM `' . $this->table('marketing_youtube_daily_stats') . '`', []],
      default => ['SELECT MAX(published_at) FROM `' . $this->table('marketing_tiktok_videos') . '`', []],
    };
    try {
      return substr((string) ($this->db->getVar($sql[0], $sql[1]) ?? ''), 0, 10);
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

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function meta(array $filters): array
  {
    $platform = $filters['network'];
    $daily = $this->table('marketing_social_daily_stats');
    $rows = $this->db->getResults("SELECT metric_date, followers, reach, impressions, profile_views, website_clicks, likes, comments, shares, saves FROM `{$daily}` WHERE platform = ? AND metric_date BETWEEN ? AND ? ORDER BY metric_date", [$platform, $filters['from'], $filters['to']]);
    $sum = static fn(string $key): int => array_sum(array_map(static fn(array $row): int => (int) $row[$key], $rows));
    $followers = array_values(array_filter(array_map(static fn(array $row): int => (int) $row['followers'], $rows)));
    $account = $this->db->getRow('SELECT account_name, username, last_sync FROM `' . $this->table('marketing_social_accounts') . '` WHERE platform = ? LIMIT 1', [$platform]) ?? [];
    $posts = $this->db->getResults('SELECT post_date, content_type, title, permalink, reach, impressions, likes, comments, shares, saves FROM `' . $this->table('marketing_social_posts') . "` WHERE platform = ? AND post_date BETWEEN ? AND ? ORDER BY reach DESC, likes DESC LIMIT 12", [$platform, $filters['from'], $filters['to']]);
    $postCount = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->table('marketing_social_posts') . '` WHERE platform = ? AND post_date BETWEEN ? AND ?', [$platform, $filters['from'], $filters['to']]);
    return [
      'account' => ['name' => (string) ($account['account_name'] ?? ''), 'username' => (string) ($account['username'] ?? ''), 'last_sync' => (string) ($account['last_sync'] ?? '')],
      'kpis' => [
        'followers' => $followers !== [] ? end($followers) : 0, 'followers_change' => count($followers) > 1 ? end($followers) - $followers[0] : 0,
        'reach' => $sum('reach'), 'impressions' => $sum('impressions'), 'profile_views' => $sum('profile_views'), 'website_clicks' => $sum('website_clicks'),
        'interactions' => $sum('likes') + $sum('comments') + $sum('shares') + $sum('saves'), 'posts' => $postCount,
      ],
      'daily' => $this->series($filters, $rows, 'metric_date', 'reach'),
      'posts' => array_map(static fn(array $row): array => ['date' => (string) $row['post_date'], 'type' => (string) $row['content_type'], 'title' => (string) $row['title'], 'url' => (string) $row['permalink'],
        'reach' => (int) $row['reach'], 'interactions' => (int) $row['likes'] + (int) $row['comments'] + (int) $row['shares'] + (int) $row['saves']], $posts),
      'audience' => $this->audience($platform),
    ];
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

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function ads(array $filters, bool $withSpend): array
  {
    $table = $this->table('marketing_social_ads_stats');
    $daily = $this->db->getResults("SELECT metric_date, spend, impressions, reach, clicks, link_clicks FROM `{$table}` WHERE level = 'account_daily' AND metric_date BETWEEN ? AND ? ORDER BY metric_date", [$filters['from'], $filters['to']]);
    $sum = static fn(array $rows, string $key): float => array_sum(array_map(static fn(array $row): float => (float) $row[$key], $rows));
    // Cada sincronización guarda una foto acumulada de cada campaña: se toma la última, la del periodo más largo.
    $snapshots = $this->db->getResults("SELECT external_id, name, status, objective, spend, impressions, reach, clicks, link_clicks, ctr, cpc, cpm, metric_date, period_start FROM `{$table}` WHERE level = 'campaign' ORDER BY metric_date DESC, period_start ASC");
    $campaigns = [];
    foreach ($snapshots as $row) {
      $campaigns[(string) ($row['external_id'] ?: $row['name'])] ??= $row;
    }
    usort($campaigns, static fn(array $a, array $b): int => (int) $b['impressions'] <=> (int) $a['impressions']);
    $impressions = $sum($daily, 'impressions');
    $kpis = ['impressions' => (int) $impressions, 'reach' => (int) $sum($daily, 'reach'), 'clicks' => (int) $sum($daily, 'clicks'), 'link_clicks' => (int) $sum($daily, 'link_clicks')];
    if ($withSpend) {
      $kpis['spend'] = $sum($daily, 'spend');
      $kpis['cpc'] = $kpis['clicks'] > 0 ? $kpis['spend'] / $kpis['clicks'] : 0.0;
    }
    return [
      'kpis' => $kpis,
      'daily' => $this->series($filters, $daily, 'metric_date', 'impressions'),
      'campaigns' => array_map(static function (array $row) use ($withSpend): array {
        $out = ['name' => (string) $row['name'], 'status' => (string) $row['status'], 'objective' => (string) $row['objective'], 'impressions' => (int) $row['impressions'],
          'reach' => (int) $row['reach'], 'clicks' => (int) $row['clicks'], 'ctr' => (float) $row['ctr'], 'date' => (string) $row['metric_date'], 'since' => (string) $row['period_start']];
        return $withSpend ? $out + ['spend' => (float) $row['spend'], 'cpc' => (float) $row['cpc'], 'cpm' => (float) $row['cpm']] : $out;
      }, array_slice($campaigns, 0, 20)),
      'with_spend' => $withSpend,
    ];
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function youtube(array $filters): array
  {
    $channel = $this->db->getRow('SELECT channel_title, channel_handle, subscriber_count, view_count, video_count, last_sync, last_error FROM `' . $this->table('marketing_youtube_connections') . '` WHERE connected = 1 ORDER BY last_sync DESC LIMIT 1') ?? [];
    $rows = $this->db->getResults('SELECT metric_date, views, watch_minutes, likes, comments, shares, subscribers_gained, subscribers_lost FROM `' . $this->table('marketing_youtube_daily_stats') . '` WHERE metric_date BETWEEN ? AND ? ORDER BY metric_date', [$filters['from'], $filters['to']]);
    $sum = static fn(string $key): int => (int) array_sum(array_map(static fn(array $row): float => (float) $row[$key], $rows));
    $videos = $this->db->getResults('SELECT title, published_at, permalink, content_type, views, watch_minutes, likes, comments FROM `' . $this->table('marketing_youtube_videos') . '` WHERE published_at BETWEEN ? AND ? ORDER BY views DESC LIMIT 12', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
    return [
      'account' => ['name' => (string) ($channel['channel_title'] ?? ''), 'username' => (string) ($channel['channel_handle'] ?? ''), 'last_sync' => (string) ($channel['last_sync'] ?? ''),
        'subscribers' => (int) ($channel['subscriber_count'] ?? 0), 'total_views' => (int) ($channel['view_count'] ?? 0), 'videos' => (int) ($channel['video_count'] ?? 0), 'error' => (string) ($channel['last_error'] ?? '')],
      'kpis' => ['views' => $sum('views'), 'watch_minutes' => $sum('watch_minutes'), 'subscribers_net' => $sum('subscribers_gained') - $sum('subscribers_lost'), 'interactions' => $sum('likes') + $sum('comments') + $sum('shares')],
      'daily' => $this->series($filters, $rows, 'metric_date', 'views'),
      'videos' => array_map(static fn(array $row): array => ['title' => (string) $row['title'], 'date' => substr((string) $row['published_at'], 0, 10), 'url' => (string) $row['permalink'],
        'type' => (string) $row['content_type'], 'views' => (int) $row['views'], 'watch_minutes' => (int) round((float) $row['watch_minutes']), 'interactions' => (int) $row['likes'] + (int) $row['comments']], $videos),
    ];
  }

  /** @param array{network:string,from:string,to:string} $filters @return array<string,mixed> */
  private function tiktok(array $filters): array
  {
    $account = $this->db->getRow('SELECT display_name, username, follower_count, likes_count, video_count, last_sync, last_error FROM `' . $this->table('marketing_tiktok_connections') . '` WHERE connected = 1 ORDER BY last_sync DESC LIMIT 1') ?? [];
    $videos = $this->db->getResults('SELECT title, published_at, permalink, views, likes, comments, shares FROM `' . $this->table('marketing_tiktok_videos') . '` WHERE published_at BETWEEN ? AND ? ORDER BY views DESC', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']);
    $sum = static fn(string $key): int => array_sum(array_map(static fn(array $row): int => (int) $row[$key], $videos));
    return [
      'account' => ['name' => (string) ($account['display_name'] ?? ''), 'username' => (string) ($account['username'] ?? ''), 'last_sync' => (string) ($account['last_sync'] ?? ''),
        'followers' => (int) ($account['follower_count'] ?? 0), 'likes' => (int) ($account['likes_count'] ?? 0), 'videos' => (int) ($account['video_count'] ?? 0), 'error' => (string) ($account['last_error'] ?? '')],
      'kpis' => ['videos' => count($videos), 'views' => $sum('views'), 'interactions' => $sum('likes') + $sum('comments') + $sum('shares')],
      'videos' => array_map(static fn(array $row): array => ['title' => (string) $row['title'], 'date' => substr((string) $row['published_at'], 0, 10), 'url' => (string) $row['permalink'],
        'views' => (int) $row['views'], 'interactions' => (int) $row['likes'] + (int) $row['comments'] + (int) $row['shares']], array_slice($videos, 0, 12)),
    ];
  }

  /**
   * Un punto por día del rango (los días sin datos en 0).
   * @param array{from:string,to:string} $filters
   * @param list<array<string,mixed>> $rows
   * @return list<array{day:string,value:int}>
   */
  private function series(array $filters, array $rows, string $dateKey, string $valueKey): array
  {
    $byDay = [];
    foreach ($rows as $row) {
      $day = substr((string) $row[$dateKey], 0, 10);
      $byDay[$day] = ($byDay[$day] ?? 0) + (int) round((float) $row[$valueKey]);
    }
    $out = [];
    for ($day = new \DateTimeImmutable($filters['from']), $end = new \DateTimeImmutable($filters['to']); $day <= $end; $day = $day->modify('+1 day')) {
      $out[] = ['day' => $day->format('Y-m-d'), 'value' => $byDay[$day->format('Y-m-d')] ?? 0];
    }
    return $out;
  }

  private function table(string $name): string
  {
    return $this->db->table($name);
  }
}
