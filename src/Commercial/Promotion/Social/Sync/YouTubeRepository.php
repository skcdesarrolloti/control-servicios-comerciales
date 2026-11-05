<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

/**
 * Guarda lo sincronizado de YouTube en marketing_youtube_*.
 * Copia de dashboard-marketing app/YouTubeRepository.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class YouTubeRepository
{
  private string $connectionsTable;
  private string $dailyTable;
  private string $videosTable;
  private string $breakdownsTable;

  public function __construct()
  {
    $this->connectionsTable = SocialDb::table('marketing_youtube_connections');
    $this->dailyTable = SocialDb::table('marketing_youtube_daily_stats');
    $this->videosTable = SocialDb::table('marketing_youtube_videos');
    $this->breakdownsTable = SocialDb::table('marketing_youtube_breakdowns');
  }

  public function connection(): array
  {
    return SocialDb::one(
      "SELECT * FROM {$this->connectionsTable} ORDER BY connected DESC, updated_at DESC LIMIT 1"
    );
  }

  public function saveConnection(array $data): array
  {
    $channelId = $this->text($data['channel_id'] ?? '', 120);
    if ($channelId === '') {
      throw new \RuntimeException('Google no devolvió un canal de YouTube válido.');
    }

    $now = date('Y-m-d H:i:s');
    SocialDb::execute("UPDATE {$this->connectionsTable} SET connected = 0, updated_at = ?", 's', [$now]);
    $payload = [
      'channel_id' => $channelId,
      'channel_title' => $this->text($data['channel_title'] ?? '', 220),
      'channel_handle' => $this->text($data['channel_handle'] ?? '', 180),
      'thumbnail_url' => $this->text($data['thumbnail_url'] ?? '', 800),
      'uploads_playlist_id' => $this->text($data['uploads_playlist_id'] ?? '', 120),
      'subscriber_count' => max(0, (int) ($data['subscriber_count'] ?? 0)),
      'view_count' => max(0, (int) ($data['view_count'] ?? 0)),
      'video_count' => max(0, (int) ($data['video_count'] ?? 0)),
      'access_token_enc' => $this->textArea($data['access_token_enc'] ?? ''),
      'refresh_token_enc' => $this->textArea($data['refresh_token_enc'] ?? ''),
      'token_expires_at' => $this->dateTime($data['token_expires_at'] ?? ''),
      'scopes' => $this->textArea($data['scopes'] ?? ''),
      'connected' => 1,
      'last_error' => '',
      'updated_at' => $now,
    ];

    $existing = SocialDb::one(
      "SELECT _ID, refresh_token_enc FROM {$this->connectionsTable} WHERE channel_id = ? LIMIT 1",
      's',
      [$channelId]
    );
    if ($existing) {
      if ($payload['refresh_token_enc'] === '') {
        $payload['refresh_token_enc'] = (string) ($existing['refresh_token_enc'] ?? '');
      }
      SocialDb::update($this->connectionsTable, $payload, (int) $existing['_ID']);
    } else {
      $payload['created_at'] = $now;
      SocialDb::insert($this->connectionsTable, $payload);
    }

    return $this->connection();
  }

  public function updateTokens(string $channelId, string $accessTokenEnc, string $refreshTokenEnc, string $expiresAt): void
  {
    $row = SocialDb::one(
      "SELECT _ID, refresh_token_enc FROM {$this->connectionsTable} WHERE channel_id = ? LIMIT 1",
      's',
      [$channelId]
    );
    if (!$row) {
      throw new \RuntimeException('La conexión de YouTube ya no existe. Vuelve a conectar el canal.');
    }

    SocialDb::update($this->connectionsTable, [
      'access_token_enc' => $accessTokenEnc,
      'refresh_token_enc' => $refreshTokenEnc !== '' ? $refreshTokenEnc : (string) ($row['refresh_token_enc'] ?? ''),
      'token_expires_at' => $expiresAt,
      'connected' => 1,
      'last_error' => '',
      'updated_at' => date('Y-m-d H:i:s'),
    ], (int) $row['_ID']);
  }

  public function updateChannel(array $data): void
  {
    $channelId = $this->text($data['channel_id'] ?? '', 120);
    $row = SocialDb::one("SELECT _ID FROM {$this->connectionsTable} WHERE channel_id = ? LIMIT 1", 's', [$channelId]);
    if (!$row) {
      return;
    }

    SocialDb::update($this->connectionsTable, [
      'channel_title' => $this->text($data['channel_title'] ?? '', 220),
      'channel_handle' => $this->text($data['channel_handle'] ?? '', 180),
      'thumbnail_url' => $this->text($data['thumbnail_url'] ?? '', 800),
      'uploads_playlist_id' => $this->text($data['uploads_playlist_id'] ?? '', 120),
      'subscriber_count' => max(0, (int) ($data['subscriber_count'] ?? 0)),
      'view_count' => max(0, (int) ($data['view_count'] ?? 0)),
      'video_count' => max(0, (int) ($data['video_count'] ?? 0)),
      'connected' => 1,
      'last_error' => '',
      'updated_at' => date('Y-m-d H:i:s'),
    ], (int) $row['_ID']);
  }

  public function recordSync(string $channelId, string $error = ''): void
  {
    $row = SocialDb::one("SELECT _ID FROM {$this->connectionsTable} WHERE channel_id = ? LIMIT 1", 's', [$channelId]);
    if (!$row) {
      return;
    }

    $payload = [
      'last_error' => $this->textArea($error, 2000),
      'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($error === '') {
      $payload['last_sync'] = date('Y-m-d H:i:s');
    }
    SocialDb::update($this->connectionsTable, $payload, (int) $row['_ID']);
  }

  public function disconnect(): void
  {
    $now = date('Y-m-d H:i:s');
    SocialDb::execute(
      "UPDATE {$this->connectionsTable}
        SET connected = 0,
          access_token_enc = '',
          refresh_token_enc = '',
          token_expires_at = NULL,
          last_error = '',
          updated_at = ?",
      's',
      [$now]
    );
  }

  public function upsertDaily(array $rows): int
  {
    $payloads = [];
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
      $channelId = $this->text($row['channel_id'] ?? '', 120);
      $date = $this->date($row['metric_date'] ?? '');
      if ($channelId === '' || $date === '') {
        continue;
      }
      $payloads[] = [
        'metric_date' => $date,
        'channel_id' => $channelId,
        'views' => max(0, (int) ($row['views'] ?? 0)),
        'engaged_views' => max(0, (int) ($row['engaged_views'] ?? 0)),
        'watch_minutes' => max(0, (int) round((float) ($row['watch_minutes'] ?? 0))),
        'average_view_duration' => max(0, (float) ($row['average_view_duration'] ?? 0)),
        'average_view_percentage' => max(0, (float) ($row['average_view_percentage'] ?? 0)),
        'likes' => max(0, (int) ($row['likes'] ?? 0)),
        'comments' => max(0, (int) ($row['comments'] ?? 0)),
        'shares' => max(0, (int) ($row['shares'] ?? 0)),
        'subscribers_gained' => max(0, (int) ($row['subscribers_gained'] ?? 0)),
        'subscribers_lost' => max(0, (int) ($row['subscribers_lost'] ?? 0)),
        'videos_published' => max(0, (int) ($row['videos_published'] ?? 0)),
        'created_at' => $now,
        'updated_at' => $now,
      ];
    }

    $this->bulkUpsert($this->dailyTable, $payloads, [
      'views', 'engaged_views', 'watch_minutes', 'average_view_duration', 'average_view_percentage',
      'likes', 'comments', 'shares', 'subscribers_gained', 'subscribers_lost', 'videos_published', 'updated_at',
    ]);

    return count($payloads);
  }

  public function upsertVideos(array $rows): int
  {
    $payloads = [];
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
      $videoId = $this->text($row['video_id'] ?? '', 64);
      $channelId = $this->text($row['channel_id'] ?? '', 120);
      if ($videoId === '' || $channelId === '') {
        continue;
      }
      $payloads[] = [
        'video_id' => $videoId,
        'channel_id' => $channelId,
        'title' => $this->text($row['title'] ?? '', 300),
        'description' => $this->textArea($row['description'] ?? '', 12000),
        'published_at' => $this->dateTime($row['published_at'] ?? ''),
        'thumbnail_url' => $this->text($row['thumbnail_url'] ?? '', 800),
        'permalink' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
        'duration_seconds' => max(0, (int) ($row['duration_seconds'] ?? 0)),
        'content_type' => $this->text($row['content_type'] ?? 'video', 40),
        'privacy_status' => $this->text($row['privacy_status'] ?? '', 40),
        'public_views' => max(0, (int) ($row['public_views'] ?? 0)),
        'public_likes' => max(0, (int) ($row['public_likes'] ?? 0)),
        'public_comments' => max(0, (int) ($row['public_comments'] ?? 0)),
        'views' => max(0, (int) ($row['views'] ?? 0)),
        'engaged_views' => max(0, (int) ($row['engaged_views'] ?? 0)),
        'watch_minutes' => max(0, (int) round((float) ($row['watch_minutes'] ?? 0))),
        'average_view_duration' => max(0, (float) ($row['average_view_duration'] ?? 0)),
        'average_view_percentage' => max(0, (float) ($row['average_view_percentage'] ?? 0)),
        'likes' => max(0, (int) ($row['likes'] ?? 0)),
        'comments' => max(0, (int) ($row['comments'] ?? 0)),
        'shares' => max(0, (int) ($row['shares'] ?? 0)),
        'subscribers_gained' => max(0, (int) ($row['subscribers_gained'] ?? 0)),
        'subscribers_lost' => max(0, (int) ($row['subscribers_lost'] ?? 0)),
        'analytics_from' => $this->date($row['analytics_from'] ?? ''),
        'analytics_to' => $this->date($row['analytics_to'] ?? ''),
        'metrics_json' => $this->json($row['metrics'] ?? []),
        'created_at' => $now,
        'updated_at' => $now,
      ];
    }

    $this->bulkUpsert($this->videosTable, $payloads, [
      'channel_id', 'title', 'description', 'published_at', 'thumbnail_url', 'permalink', 'duration_seconds',
      'content_type', 'privacy_status', 'public_views', 'public_likes', 'public_comments', 'views', 'engaged_views',
      'watch_minutes', 'average_view_duration', 'average_view_percentage', 'likes', 'comments', 'shares',
      'subscribers_gained', 'subscribers_lost', 'analytics_from', 'analytics_to', 'metrics_json', 'updated_at',
    ]);

    return count($payloads);
  }

  public function replaceBreakdown(string $channelId, string $from, string $to, string $type, array $rows): int
  {
    SocialDb::execute(
      "DELETE FROM {$this->breakdownsTable} WHERE channel_id = ? AND period_from = ? AND period_to = ? AND breakdown_type = ?",
      'ssss',
      [$channelId, $from, $to, $type]
    );

    $payloads = [];
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
      $label = $this->text($row['label'] ?? '', 240);
      if ($label === '') {
        continue;
      }
      $payloads[] = [
        'channel_id' => $channelId,
        'period_from' => $from,
        'period_to' => $to,
        'breakdown_type' => $this->text($type, 64),
        'label' => $label,
        'secondary_label' => $this->text($row['secondary_label'] ?? '', 240),
        'value' => max(0, (float) ($row['value'] ?? 0)),
        'watch_minutes' => max(0, (float) ($row['watch_minutes'] ?? 0)),
        'metrics_json' => $this->json($row['metrics'] ?? []),
        'created_at' => $now,
        'updated_at' => $now,
      ];
    }
    $this->bulkUpsert($this->breakdownsTable, $payloads, ['secondary_label', 'value', 'watch_minutes', 'metrics_json', 'updated_at']);

    return count($payloads);
  }





  private function bulkUpsert(string $table, array $rows, array $updateColumns): void
  {
    foreach (array_chunk($rows, 100) as $chunk) {
      if ($chunk === []) {
        continue;
      }
      $columns = array_keys($chunk[0]);
      $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
      $params = [];
      foreach ($chunk as $row) {
        foreach ($columns as $column) {
          $params[] = (string) ($row[$column] ?? '');
        }
      }
      $updates = array_map(static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)", $updateColumns);
      $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES '
        . implode(',', array_fill(0, count($chunk), $placeholder))
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
      if (!SocialDb::execute($sql, str_repeat('s', count($params)), $params)) {
        throw new \RuntimeException('No fue posible guardar los datos sincronizados de YouTube.');
      }
    }
  }

  private function text(mixed $value, int $length): string
  {
    return mb_substr(trim((string) $value), 0, $length);
  }

  private function textArea(mixed $value, int $length = 65535): string
  {
    return mb_substr(trim((string) $value), 0, $length);
  }

  private function date(mixed $value): string
  {
    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('Y-m-d') : '';
  }

  private function dateTime(mixed $value): ?string
  {
    $value = trim((string) $value);
    if ($value === '') {
      return null;
    }
    try {
      return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (\Throwable) {
      return null;
    }
  }

  private function json(mixed $value): string
  {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }
}
