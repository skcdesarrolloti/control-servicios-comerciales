<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use RuntimeException;

/**
 * Sincronización del perfil y los videos de TikTok.
 * Copia de dashboard-marketing app/TikTokSyncService.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class TikTokSyncService
{
  private TikTokRepository $repo;
  private TikTokApiService $api;

  public function __construct(?TikTokRepository $repo = null, ?TikTokApiService $api = null)
  {
    $this->repo = $repo ?? new TikTokRepository();
    $this->api = $api ?? new TikTokApiService($this->repo);
  }

  public function sync(string $from, string $to): array
  {
    if (!$this->api->isConfigured()) {
      throw new RuntimeException('Faltan las credenciales de TikTok en el archivo .env.');
    }
    $connection = $this->repo->connection();
    if (!$connection || (int) ($connection['connected'] ?? 0) !== 1) {
      throw new RuntimeException('Conecta primero la cuenta de TikTok.');
    }

    $summary = ['provider' => 'tiktok', 'accounts' => 0, 'videos' => 0, 'pages' => 0, 'warnings' => []];
    $profile = $this->api->userInfo();
    $this->repo->updateProfile($profile);
    $summary['accounts'] = 1;

    $fromTimestamp = strtotime($from . ' 00:00:00') ?: 0;
    $toTimestamp = strtotime($to . ' 23:59:59') ?: PHP_INT_MAX;
    $cursor = null;
    $seenCursors = [];
    $videos = [];

    for ($page = 0; $page < 250; $page++) {
      $response = $this->api->listVideos($cursor);
      $summary['pages']++;
      $data = (array) ($response['data'] ?? []);
      $rows = (array) ($data['videos'] ?? []);
      $oldest = PHP_INT_MAX;
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $created = (int) ($row['create_time'] ?? 0);
        if ($created > 0) $oldest = min($oldest, $created);
        if ($created < $fromTimestamp || $created > $toTimestamp) continue;
        $videos[] = $this->mapVideo($row);
      }
      $hasMore = (bool) ($data['has_more'] ?? false);
      $nextCursor = (int) ($data['cursor'] ?? 0);
      if (!$hasMore || $rows === [] || ($oldest !== PHP_INT_MAX && $oldest < $fromTimestamp)) break;
      if ($nextCursor <= 0 || isset($seenCursors[$nextCursor])) {
        $summary['warnings'][] = 'TikTok repitió el cursor de paginación; la descarga se detuvo para evitar un ciclo.';
        break;
      }
      $seenCursors[$nextCursor] = true;
      $cursor = $nextCursor;
    }

    $summary['videos'] = $this->repo->upsertVideos((string) $connection['open_id'], $videos);
    $this->repo->markSynced();
    if ($summary['videos'] === 0) {
      $summary['warnings'][] = 'TikTok no devolvió videos públicos dentro del rango seleccionado.';
    }
    return $summary;
  }

  private function mapVideo(array $video): array
  {
    $description = trim((string) ($video['video_description'] ?? ''));
    $title = trim((string) ($video['title'] ?? '')) ?: $description;
    return [
      'video_id' => (string) ($video['id'] ?? ''),
      'title' => mb_substr($title !== '' ? $title : 'Video de TikTok', 0, 500),
      'description' => $description,
      'published_at' => !empty($video['create_time']) ? date('Y-m-d H:i:s', (int) $video['create_time']) : '',
      'cover_url' => (string) ($video['cover_image_url'] ?? ''),
      'permalink' => (string) ($video['share_url'] ?? ''),
      'duration_seconds' => (int) ($video['duration'] ?? 0),
      'views' => (int) ($video['view_count'] ?? 0),
      'likes' => (int) ($video['like_count'] ?? 0),
      'comments' => (int) ($video['comment_count'] ?? 0),
      'shares' => (int) ($video['share_count'] ?? 0),
      'metrics' => $video,
    ];
  }
}
