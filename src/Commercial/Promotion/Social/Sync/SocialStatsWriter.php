<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

/**
 * Guarda lo sincronizado de Meta en marketing_social_*.
 * Copia de dashboard-marketing app/SocialStatsRepository.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class SocialStatsWriter
{
  private string $accountsTable;
  private string $dailyTable;
  private string $postsTable;
  private string $adsTable;
  private string $audienceTable;
  private string $leadFormsTable;
  private string $conversationsTable;

  public function __construct()
  {
    $this->accountsTable = SocialDb::table('marketing_social_accounts');
    $this->dailyTable = SocialDb::table('marketing_social_daily_stats');
    $this->postsTable = SocialDb::table('marketing_social_posts');
    $this->adsTable = SocialDb::table('marketing_social_ads_stats');
    $this->audienceTable = SocialDb::table('marketing_social_audience');
    $this->leadFormsTable = SocialDb::table('marketing_social_lead_forms');
    $this->conversationsTable = SocialDb::table('marketing_social_conversations');
  }



  public function upsertAccount(array $data): void
  {
    $platform = $this->choice($data['platform'] ?? '', ['instagram', 'facebook'], 'instagram');
    $accountId = $this->text($data['account_id'] ?? '', 120);
    if ($accountId === '') {
      return;
    }

    $now = date('Y-m-d H:i:s');
    $payload = [
      'platform' => $platform,
      'account_name' => $this->text($data['account_name'] ?? '', 160),
      'account_id' => $accountId,
      'username' => $this->text($data['username'] ?? '', 120),
      'connected' => (int) ($data['connected'] ?? 1),
      'last_sync' => $now,
      'notes' => $this->textArea($data['notes'] ?? 'Sincronizado desde Meta Graph API.', 1000),
      'updated_at' => $now,
    ];

    $existing = SocialDb::one(
      "SELECT _ID FROM {$this->accountsTable} WHERE platform = ? AND account_id = ? LIMIT 1",
      'ss',
      [$platform, $accountId]
    );

    if ($existing) {
      SocialDb::update($this->accountsTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $now;
    SocialDb::insert($this->accountsTable, $payload);
  }

  public function upsertDailyStat(array $data): void
  {
    $payload = $this->dailyPayload($data);
    $platform = (string) $payload['platform'];
    $accountId = (string) $payload['account_id'];
    $date = (string) $payload['metric_date'];

    $existing = SocialDb::one(
      "SELECT _ID FROM {$this->dailyTable} WHERE metric_date = ? AND platform = ? AND account_id = ? LIMIT 1",
      'sss',
      [$date, $platform, $accountId]
    );

    if ($existing) {
      SocialDb::update($this->dailyTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $payload['updated_at'];
    SocialDb::insert($this->dailyTable, $payload);
  }

  public function upsertDailyStats(array $rows): void
  {
    $payloads = array_map(fn (array $row): array => $this->dailyPayload($row) + ['created_at' => date('Y-m-d H:i:s')], $rows);
    $this->bulkUpsert($this->dailyTable, $payloads, ['followers', 'reach', 'impressions', 'profile_views', 'website_clicks', 'likes', 'comments', 'shares', 'saves', 'content_count', 'source', 'updated_at']);
  }

  public function upsertPost(array $data): void
  {
    $payload = $this->postPayload($data);
    $platform = (string) $payload['platform'];
    $externalId = (string) $payload['external_id'];
    if ($externalId === '') {
      return;
    }

    $existing = SocialDb::one(
      "SELECT _ID FROM {$this->postsTable} WHERE platform = ? AND external_id = ? LIMIT 1",
      'ss',
      [$platform, $externalId]
    );

    if ($existing) {
      SocialDb::update($this->postsTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $payload['updated_at'];
    SocialDb::insert($this->postsTable, $payload);
  }

  public function upsertPosts(array $rows): void
  {
    $payloads = [];
    foreach ($rows as $row) {
      $payload = $this->postPayload($row);
      if ($payload['external_id'] === '') {
        continue;
      }
      $payload['created_at'] = $payload['updated_at'];
      $payloads[] = $payload;
    }
    $this->bulkUpsert($this->postsTable, $payloads, ['post_date', 'post_time', 'account_id', 'content_type', 'title', 'permalink', 'thumbnail_url', 'reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'metrics_json', 'source', 'updated_at']);
  }

  public function upsertAdStat(array $data): void
  {
    $externalId = $this->text($data['external_id'] ?? '', 160);
    if ($externalId === '') {
      return;
    }

    $now = date('Y-m-d H:i:s');
    $level = $this->choice($data['level'] ?? 'campaign', ['account', 'account_daily', 'campaign', 'ad'], 'campaign');
    $metricDate = $this->date($data['metric_date'] ?? date('Y-m-d'), date('Y-m-d'));
    $payload = [
      'period_start' => $this->date($data['period_start'] ?? $metricDate, $metricDate),
      'metric_date' => $metricDate,
      'level' => $level,
      'account_id' => $this->text($data['account_id'] ?? '', 120),
      'external_id' => $externalId,
      'name' => $this->text($data['name'] ?? '', 220),
      'status' => $this->text($data['status'] ?? '', 80),
      'objective' => $this->text($data['objective'] ?? '', 80),
      'spend' => max(0, (float) ($data['spend'] ?? 0)),
      'impressions' => max(0, (int) ($data['impressions'] ?? 0)),
      'reach' => max(0, (int) ($data['reach'] ?? 0)),
      'clicks' => max(0, (int) ($data['clicks'] ?? 0)),
      'link_clicks' => max(0, (int) ($data['link_clicks'] ?? 0)),
      'ctr' => max(0, (float) ($data['ctr'] ?? 0)),
      'cpc' => max(0, (float) ($data['cpc'] ?? 0)),
      'cpm' => max(0, (float) ($data['cpm'] ?? 0)),
      'frequency' => max(0, (float) ($data['frequency'] ?? 0)),
      'actions_json' => $this->jsonText($data['actions'] ?? []),
      'thumbnail_url' => $this->text($data['thumbnail_url'] ?? '', 800),
      'source' => 'meta',
      'updated_at' => $now,
    ];

    $existing = SocialDb::one(
      "SELECT _ID FROM {$this->adsTable} WHERE period_start = ? AND metric_date = ? AND level = ? AND external_id = ? LIMIT 1",
      'ssss',
      [$payload['period_start'], $metricDate, $level, $externalId]
    );

    if ($existing) {
      SocialDb::update($this->adsTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $now;
    SocialDb::insert($this->adsTable, $payload);
  }

  public function upsertAudienceInsight(array $data): void
  {
    $label = $this->text($data['label'] ?? '', 180);
    $breakdown = $this->choice($data['breakdown'] ?? '', ['city', 'country', 'gender', 'age', 'gender_age', 'media_product_type', 'follower_type'], 'city');
    if ($label === '') {
      return;
    }

    $now = date('Y-m-d H:i:s');
    $snapshotDate = $this->date($data['snapshot_date'] ?? date('Y-m-d'), date('Y-m-d'));
    $payload = [
      'snapshot_date' => $snapshotDate,
      'platform' => $this->choice($data['platform'] ?? 'instagram', ['instagram', 'facebook'], 'instagram'),
      'account_id' => $this->text($data['account_id'] ?? '', 120),
      'breakdown' => $breakdown,
      'label' => $label,
      'value' => max(0, (int) ($data['value'] ?? 0)),
      'source' => 'meta',
      'updated_at' => $now,
    ];

    $existing = SocialDb::one(
      "SELECT _ID FROM {$this->audienceTable} WHERE snapshot_date = ? AND platform = ? AND breakdown = ? AND label = ? LIMIT 1",
      'ssss',
      [$snapshotDate, $payload['platform'], $breakdown, $label]
    );

    if ($existing) {
      SocialDb::update($this->audienceTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $now;
    SocialDb::insert($this->audienceTable, $payload);
  }

  public function hasAudienceSnapshot(string $date, string $platform = 'instagram'): bool
  {
    $date = $this->date($date, date('Y-m-d'));
    $platform = $this->choice($platform, ['instagram', 'facebook'], 'instagram');
    return (int) SocialDb::value(
      "SELECT COUNT(*) FROM {$this->audienceTable} WHERE snapshot_date = ? AND platform = ?",
      'ss',
      [$date, $platform]
    ) > 0;
  }

  public function upsertLeadForm(array $data): void
  {
    $externalId = $this->text($data['external_id'] ?? '', 160);
    if ($externalId === '') {
      return;
    }

    $now = date('Y-m-d H:i:s');
    $payload = [
      'external_id' => $externalId,
      'name' => $this->text($data['name'] ?? '', 220),
      'status' => $this->text($data['status'] ?? '', 80),
      'leads_count' => max(0, (int) ($data['leads_count'] ?? 0)),
      'created_time' => $this->dateTime($data['created_time'] ?? ''),
      'source' => 'meta',
      'updated_at' => $now,
    ];

    $existing = SocialDb::one("SELECT _ID FROM {$this->leadFormsTable} WHERE external_id = ? LIMIT 1", 's', [$externalId]);
    if ($existing) {
      SocialDb::update($this->leadFormsTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $now;
    SocialDb::insert($this->leadFormsTable, $payload);
  }

  public function upsertConversation(array $data): void
  {
    $externalId = $this->text($data['external_id'] ?? '', 180);
    if ($externalId === '') {
      return;
    }

    $now = date('Y-m-d H:i:s');
    $payload = [
      'external_id' => $externalId,
      'updated_time' => $this->dateTime($data['updated_time'] ?? ''),
      'message_count' => max(0, (int) ($data['message_count'] ?? 0)),
      'unread_count' => max(0, (int) ($data['unread_count'] ?? 0)),
      'participants' => $this->textArea($data['participants'] ?? '', 1000),
      'source' => 'meta',
      'updated_at' => $now,
    ];

    $existing = SocialDb::one("SELECT _ID FROM {$this->conversationsTable} WHERE external_id = ? LIMIT 1", 's', [$externalId]);
    if ($existing) {
      SocialDb::update($this->conversationsTable, $payload, (int) $existing['_ID']);
      return;
    }

    $payload['created_at'] = $now;
    SocialDb::insert($this->conversationsTable, $payload);
  }

  private function dailyPayload(array $data): array
  {
    return [
      'metric_date' => $this->date($data['metric_date'] ?? '', date('Y-m-d')),
      'platform' => $this->choice($data['platform'] ?? '', ['instagram', 'facebook'], 'instagram'),
      'account_id' => $this->text($data['account_id'] ?? '', 120),
      'followers' => max(0, (int) ($data['followers'] ?? 0)),
      'reach' => max(0, (int) ($data['reach'] ?? 0)),
      'impressions' => max(0, (int) ($data['impressions'] ?? 0)),
      'profile_views' => max(0, (int) ($data['profile_views'] ?? 0)),
      'website_clicks' => max(0, (int) ($data['website_clicks'] ?? 0)),
      'likes' => max(0, (int) ($data['likes'] ?? 0)),
      'comments' => max(0, (int) ($data['comments'] ?? 0)),
      'shares' => max(0, (int) ($data['shares'] ?? 0)),
      'saves' => max(0, (int) ($data['saves'] ?? 0)),
      'content_count' => max(0, (int) ($data['content_count'] ?? 0)),
      'source' => $this->choice($data['source'] ?? 'meta', ['manual', 'meta'], 'meta'),
      'updated_at' => date('Y-m-d H:i:s'),
    ];
  }

  private function postPayload(array $data): array
  {
    return [
      'post_date' => $this->date($data['post_date'] ?? '', date('Y-m-d')),
      'post_time' => $this->time($data['post_time'] ?? ''),
      'platform' => $this->choice($data['platform'] ?? '', ['instagram', 'facebook'], 'instagram'),
      'account_id' => $this->text($data['account_id'] ?? '', 120),
      'external_id' => $this->text($data['external_id'] ?? '', 160),
      'content_type' => $this->text($data['content_type'] ?? 'post', 32),
      'title' => $this->text($data['title'] ?? '', 220),
      'permalink' => $this->text($data['permalink'] ?? '', 800),
      'thumbnail_url' => $this->text($data['thumbnail_url'] ?? '', 800),
      'reach' => max(0, (int) ($data['reach'] ?? 0)),
      'impressions' => max(0, (int) ($data['impressions'] ?? 0)),
      'likes' => max(0, (int) ($data['likes'] ?? 0)),
      'comments' => max(0, (int) ($data['comments'] ?? 0)),
      'shares' => max(0, (int) ($data['shares'] ?? 0)),
      'saves' => max(0, (int) ($data['saves'] ?? 0)),
      'metrics_json' => $this->jsonText($data['metrics'] ?? []),
      'source' => $this->choice($data['source'] ?? 'meta', ['manual', 'meta'], 'meta'),
      'updated_at' => date('Y-m-d H:i:s'),
    ];
  }

  private function bulkUpsert(string $table, array $rows, array $updateColumns): void
  {
    foreach (array_chunk($rows, 100) as $chunk) {
      if ($chunk === []) {
        continue;
      }
      $columns = array_keys($chunk[0]);
      $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
      $params = [];
      foreach ($chunk as $row) {
        foreach ($columns as $column) {
          $params[] = (string) ($row[$column] ?? '');
        }
      }
      $updates = array_map(static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)", $updateColumns);
      $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES '
        . implode(',', array_fill(0, count($chunk), $rowPlaceholder))
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
      if (!SocialDb::execute($sql, str_repeat('s', count($params)), $params)) {
        throw new \RuntimeException('No fue posible guardar el bloque sincronizado en la base de datos.');
      }
    }
  }

















  private function dateTime(mixed $value): ?string
  {
    $text = trim((string) $value);
    if ($text === '') {
      return null;
    }
    $time = strtotime($text);
    return $time ? date('Y-m-d H:i:s', $time) : null;
  }

  private function jsonText(mixed $value): string
  {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '[]';
  }

  private function date(mixed $value, string $fallback): string
  {
    $text = trim((string) $value);
    $date = \DateTime::createFromFormat('Y-m-d', $text);
    return $date && $date->format('Y-m-d') === $text ? $text : $fallback;
  }

  private function time(mixed $value): string
  {
    $text = trim((string) $value);
    return preg_match('/^\d{2}:\d{2}$/', $text) ? $text : '';
  }

  private function choice(mixed $value, array $allowed, string $fallback): string
  {
    $text = trim((string) $value);
    return in_array($text, $allowed, true) ? $text : $fallback;
  }

  private function text(mixed $value, int $max): string
  {
    $text = trim(strip_tags((string) $value));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr($text, 0, $max);
  }

  private function textArea(mixed $value, int $max): string
  {
    $text = trim(strip_tags((string) $value));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr($text, 0, $max);
  }
}
