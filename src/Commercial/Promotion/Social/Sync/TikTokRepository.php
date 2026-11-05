<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use RuntimeException;

/**
 * Guarda lo sincronizado de TikTok en marketing_tiktok_*.
 * Copia de dashboard-marketing app/TikTokRepository.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class TikTokRepository
{
  private string $connectionsTable;
  private string $videosTable;

  public function __construct()
  {
    $this->connectionsTable = SocialDb::table('marketing_tiktok_connections');
    $this->videosTable = SocialDb::table('marketing_tiktok_videos');
  }

  public function connection(): array
  {
    return SocialDb::one("SELECT * FROM {$this->connectionsTable} ORDER BY connected DESC, updated_at DESC LIMIT 1");
  }

  public function saveConnection(array $data): array
  {
    $openId = $this->text($data['open_id'] ?? '', 120);
    if ($openId === '') {
      throw new RuntimeException('TikTok no devolvió un identificador de cuenta válido.');
    }

    $now = date('Y-m-d H:i:s');
    SocialDb::execute("UPDATE {$this->connectionsTable} SET connected = 0, updated_at = ?", 's', [$now]);
    $payload = $this->connectionPayload($data, $now);
    $payload['open_id'] = $openId;
    $payload['connected'] = 1;
    $payload['last_error'] = '';
    $existing = SocialDb::one("SELECT _ID, refresh_token_enc FROM {$this->connectionsTable} WHERE open_id = ? LIMIT 1", 's', [$openId]);
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

  public function updateTokens(string $openId, string $accessTokenEnc, string $refreshTokenEnc, string $expiresAt, string $refreshExpiresAt, string $scopes): void
  {
    $row = SocialDb::one("SELECT _ID, refresh_token_enc FROM {$this->connectionsTable} WHERE open_id = ? LIMIT 1", 's', [$openId]);
    if (!$row) {
      throw new RuntimeException('La conexión de TikTok ya no existe. Vuelve a conectar la cuenta.');
    }
    SocialDb::update($this->connectionsTable, [
      'access_token_enc' => $accessTokenEnc,
      'refresh_token_enc' => $refreshTokenEnc !== '' ? $refreshTokenEnc : (string) ($row['refresh_token_enc'] ?? ''),
      'token_expires_at' => $expiresAt,
      'refresh_expires_at' => $refreshExpiresAt,
      'scopes' => $this->textArea($scopes),
      'connected' => 1,
      'last_error' => '',
      'updated_at' => date('Y-m-d H:i:s'),
    ], (int) $row['_ID']);
  }

  public function updateProfile(array $profile): void
  {
    $openId = $this->text($profile['open_id'] ?? '', 120);
    $row = SocialDb::one("SELECT _ID FROM {$this->connectionsTable} WHERE open_id = ? LIMIT 1", 's', [$openId]);
    if (!$row) {
      return;
    }
    SocialDb::update($this->connectionsTable, [
      'union_id' => $this->text($profile['union_id'] ?? '', 120),
      'display_name' => $this->text($profile['display_name'] ?? '', 220),
      'username' => $this->text($profile['username'] ?? '', 180),
      'avatar_url' => $this->text($profile['avatar_url'] ?? '', 1000),
      'profile_url' => $this->text($profile['profile_url'] ?? '', 1000),
      'bio_description' => $this->textArea($profile['bio_description'] ?? '', 4000),
      'follower_count' => max(0, (int) ($profile['follower_count'] ?? 0)),
      'following_count' => max(0, (int) ($profile['following_count'] ?? 0)),
      'likes_count' => max(0, (int) ($profile['likes_count'] ?? 0)),
      'video_count' => max(0, (int) ($profile['video_count'] ?? 0)),
      'connected' => 1,
      'last_error' => '',
      'updated_at' => date('Y-m-d H:i:s'),
    ], (int) $row['_ID']);
  }

  public function upsertVideos(string $openId, array $videos): int
  {
    $now = date('Y-m-d H:i:s');
    $count = 0;
    foreach ($videos as $video) {
      $videoId = $this->text($video['video_id'] ?? '', 80);
      if ($videoId === '') {
        continue;
      }
      $payload = [
        'video_id' => $videoId,
        'open_id' => $this->text($openId, 120),
        'title' => $this->text($video['title'] ?? '', 500),
        'description' => $this->textArea($video['description'] ?? '', 12000),
        'published_at' => $this->dateTime($video['published_at'] ?? ''),
        'cover_url' => $this->text($video['cover_url'] ?? '', 1200),
        'permalink' => $this->text($video['permalink'] ?? '', 1000),
        'duration_seconds' => max(0, (int) ($video['duration_seconds'] ?? 0)),
        'views' => max(0, (int) ($video['views'] ?? 0)),
        'likes' => max(0, (int) ($video['likes'] ?? 0)),
        'comments' => max(0, (int) ($video['comments'] ?? 0)),
        'shares' => max(0, (int) ($video['shares'] ?? 0)),
        'metrics_json' => json_encode($video['metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'updated_at' => $now,
      ];
      $existing = SocialDb::one("SELECT _ID FROM {$this->videosTable} WHERE video_id = ? LIMIT 1", 's', [$videoId]);
      if ($existing) {
        SocialDb::update($this->videosTable, $payload, (int) $existing['_ID']);
      } else {
        $payload['created_at'] = $now;
        SocialDb::insert($this->videosTable, $payload);
      }
      $count++;
    }
    return $count;
  }

  public function markSynced(): void
  {
    SocialDb::execute(
      "UPDATE {$this->connectionsTable} SET last_sync = ?, last_error = '', updated_at = ? WHERE connected = 1",
      'ss',
      [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
    );
  }

  public function disconnect(): void
  {
    SocialDb::execute(
      "UPDATE {$this->connectionsTable} SET connected = 0, access_token_enc = '', refresh_token_enc = '', token_expires_at = NULL, refresh_expires_at = NULL, last_error = '', updated_at = ?",
      's',
      [date('Y-m-d H:i:s')]
    );
  }





  private function connectionPayload(array $data, string $now): array
  {
    return [
      'union_id' => $this->text($data['union_id'] ?? '', 120),
      'display_name' => $this->text($data['display_name'] ?? '', 220),
      'username' => $this->text($data['username'] ?? '', 180),
      'avatar_url' => $this->text($data['avatar_url'] ?? '', 1000),
      'profile_url' => $this->text($data['profile_url'] ?? '', 1000),
      'bio_description' => $this->textArea($data['bio_description'] ?? '', 4000),
      'follower_count' => max(0, (int) ($data['follower_count'] ?? 0)),
      'following_count' => max(0, (int) ($data['following_count'] ?? 0)),
      'likes_count' => max(0, (int) ($data['likes_count'] ?? 0)),
      'video_count' => max(0, (int) ($data['video_count'] ?? 0)),
      'access_token_enc' => $this->textArea($data['access_token_enc'] ?? ''),
      'refresh_token_enc' => $this->textArea($data['refresh_token_enc'] ?? ''),
      'token_expires_at' => $this->dateTime($data['token_expires_at'] ?? ''),
      'refresh_expires_at' => $this->dateTime($data['refresh_expires_at'] ?? ''),
      'scopes' => $this->textArea($data['scopes'] ?? ''),
      'updated_at' => $now,
    ];
  }


  private function text(mixed $value, int $length): string { return mb_substr(trim((string) $value), 0, $length); }
  private function textArea(mixed $value, int $length = 65535): string { return mb_substr(trim((string) $value), 0, $length); }
  private function dateTime(mixed $value): ?string
  {
    $value = trim((string) $value);
    if ($value === '') return null;
    $time = strtotime($value);
    return $time === false ? null : date('Y-m-d H:i:s', $time);
  }
}
