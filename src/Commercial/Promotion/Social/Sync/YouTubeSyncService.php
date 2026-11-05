<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use DateInterval;
use RuntimeException;
use Throwable;

/**
 * Sincronización de estadísticas, videos y desgloses de YouTube.
 * Copia de dashboard-marketing app/YouTubeSyncService.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class YouTubeSyncService
{
  private YouTubeRepository $repo;
  private YouTubeApiService $api;

  public function __construct(?YouTubeRepository $repo = null, ?YouTubeApiService $api = null)
  {
    $this->repo = $repo ?? new YouTubeRepository();
    $this->api = $api ?? new YouTubeApiService($this->repo);
  }

  public function sync(string $from, string $to): array
  {
    $from = $this->date($from, date('Y-01-01', strtotime('-1 year')));
    $to = $this->date($to, date('Y-m-d'));
    if ($from > $to) {
      [$from, $to] = [$to, $from];
    }

    $summary = [
      'provider' => 'youtube',
      'channels' => 0,
      'daily' => 0,
      'videos' => 0,
      'analytics' => 0,
      'breakdowns' => 0,
      'warnings' => [],
    ];
    $connection = $this->repo->connection();
    $channelId = (string) ($connection['channel_id'] ?? '');
    if ($channelId === '' || (int) ($connection['connected'] ?? 0) !== 1) {
      throw new RuntimeException('Conecta primero el canal de YouTube.');
    }

    try {
      $channelsResponse = $this->api->data('channels', [
        'part' => 'snippet,contentDetails,statistics',
        'mine' => 'true',
        'maxResults' => 50,
      ]);
      $channel = [];
      foreach ((array) ($channelsResponse['items'] ?? []) as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $channelId) {
          $channel = (array) $candidate;
          break;
        }
      }
      if ($channel === []) {
        throw new RuntimeException('La cuenta autorizada ya no administra el canal de YouTube conectado.');
      }
      $channelData = $this->api->mapChannel($channel);
      $this->repo->updateChannel($channelData);
      $summary['channels'] = 1;

      $videoRows = $this->videosForRange($channelData, $from, $to, $summary);
      $dailyRows = $this->dailyAnalytics($channelId, $from, $to, $summary);
      $videoAnalytics = $this->videoAnalytics(array_column($videoRows, 'video_id'), $from, $to, $summary);

      $videoPublishCounts = [];
      foreach ($videoRows as &$video) {
        $videoId = (string) $video['video_id'];
        $video = array_merge($video, $videoAnalytics[$videoId] ?? []);
        $video['analytics_from'] = $from;
        $video['analytics_to'] = $to;
        $publishedDate = substr((string) ($video['published_at'] ?? ''), 0, 10);
        if ($publishedDate !== '') {
          $videoPublishCounts[$publishedDate] = ($videoPublishCounts[$publishedDate] ?? 0) + 1;
        }
      }
      unset($video);

      foreach ($videoPublishCounts as $date => $count) {
        if (!isset($dailyRows[$date])) {
          $dailyRows[$date] = $this->emptyDaily($channelId, $date);
        }
        $dailyRows[$date]['videos_published'] = $count;
      }
      ksort($dailyRows);

      $summary['videos'] = $this->repo->upsertVideos($videoRows);
      $summary['daily'] = $this->repo->upsertDaily(array_values($dailyRows));
      $summary['analytics'] += count($dailyRows) + count($videoAnalytics);
      $summary['breakdowns'] = $this->syncBreakdowns($channelId, $from, $to, $summary);
      $this->repo->recordSync($channelId);
    } catch (Throwable $error) {
      $this->repo->recordSync($channelId, $error->getMessage());
      throw $error;
    }

    return $summary;
  }

  private function videosForRange(array $channel, string $from, string $to, array &$summary): array
  {
    $playlistId = (string) ($channel['uploads_playlist_id'] ?? '');
    if ($playlistId === '') {
      $summary['warnings'][] = 'YouTube no devolvió la lista de videos subidos del canal.';
      return [];
    }

    $ids = [];
    $pageToken = '';
    $seenTokens = [];
    do {
      $params = [
        'part' => 'snippet,contentDetails,status',
        'playlistId' => $playlistId,
        'maxResults' => 50,
      ];
      if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
      }
      $response = $this->api->data('playlistItems', $params);
      $pageHasRecent = false;
      foreach ((array) ($response['items'] ?? []) as $item) {
        $publishedAt = (string) (($item['contentDetails']['videoPublishedAt'] ?? null) ?: ($item['snippet']['publishedAt'] ?? ''));
        $publishedDate = substr($publishedAt, 0, 10);
        if ($publishedDate >= $from) {
          $pageHasRecent = true;
        }
        if ($publishedDate < $from || $publishedDate > $to) {
          continue;
        }
        $videoId = (string) (($item['contentDetails']['videoId'] ?? null) ?: ($item['snippet']['resourceId']['videoId'] ?? ''));
        if ($videoId !== '') {
          $ids[$videoId] = true;
        }
      }

      $next = trim((string) ($response['nextPageToken'] ?? ''));
      if (!$pageHasRecent || $next === '' || isset($seenTokens[$next])) {
        break;
      }
      $seenTokens[$next] = true;
      $pageToken = $next;
    } while (count($seenTokens) < 100);

    $rows = [];
    foreach (array_chunk(array_keys($ids), 50) as $chunk) {
      $response = $this->api->data('videos', [
        'part' => 'snippet,contentDetails,statistics,status,liveStreamingDetails',
        'id' => implode(',', $chunk),
        'maxResults' => 50,
      ]);
      foreach ((array) ($response['items'] ?? []) as $video) {
        $snippet = (array) ($video['snippet'] ?? []);
        $statistics = (array) ($video['statistics'] ?? []);
        $contentDetails = (array) ($video['contentDetails'] ?? []);
        $status = (array) ($video['status'] ?? []);
        $thumbnails = (array) ($snippet['thumbnails'] ?? []);
        $duration = $this->durationSeconds((string) ($contentDetails['duration'] ?? ''));
        $isLive = !empty($video['liveStreamingDetails']) || in_array((string) ($snippet['liveBroadcastContent'] ?? ''), ['live', 'upcoming'], true);
        $rows[] = [
          'video_id' => (string) ($video['id'] ?? ''),
          'channel_id' => (string) ($channel['channel_id'] ?? ''),
          'title' => (string) ($snippet['title'] ?? 'Video de YouTube'),
          'description' => (string) ($snippet['description'] ?? ''),
          'published_at' => (string) ($snippet['publishedAt'] ?? ''),
          'thumbnail_url' => (string) (($thumbnails['maxres']['url'] ?? null) ?: ($thumbnails['high']['url'] ?? null) ?: ($thumbnails['medium']['url'] ?? null) ?: ($thumbnails['default']['url'] ?? '')),
          'duration_seconds' => $duration,
          'content_type' => $isLive ? 'live' : 'video',
          'privacy_status' => (string) ($status['privacyStatus'] ?? ''),
          'public_views' => (int) ($statistics['viewCount'] ?? 0),
          'public_likes' => (int) ($statistics['likeCount'] ?? 0),
          'public_comments' => (int) ($statistics['commentCount'] ?? 0),
          'metrics' => ['duration_iso' => (string) ($contentDetails['duration'] ?? '')],
        ];
      }
    }

    return $rows;
  }

  private function dailyAnalytics(string $channelId, string $from, string $to, array &$summary): array
  {
    $metrics = [
      'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
      'likes', 'comments', 'shares', 'subscribersGained', 'subscribersLost',
    ];
    try {
      $rows = $this->report($from, $to, ['day'], $metrics, ['sort' => 'day']);
    } catch (Throwable $error) {
      $summary['warnings'][] = 'YouTube no entregó engagedViews; se conservaron las demás métricas diarias. ' . $error->getMessage();
      $metrics = array_values(array_filter($metrics, static fn (string $metric): bool => $metric !== 'engagedViews'));
      $rows = $this->report($from, $to, ['day'], $metrics, ['sort' => 'day']);
    }

    $daily = [];
    foreach ($rows as $row) {
      $date = (string) ($row['day'] ?? '');
      if ($date === '') {
        continue;
      }
      $daily[$date] = [
        'channel_id' => $channelId,
        'metric_date' => $date,
        'views' => $this->int($row['views'] ?? 0),
        'engaged_views' => $this->int($row['engagedViews'] ?? 0),
        'watch_minutes' => $this->number($row['estimatedMinutesWatched'] ?? 0),
        'average_view_duration' => $this->number($row['averageViewDuration'] ?? 0),
        'average_view_percentage' => $this->number($row['averageViewPercentage'] ?? 0),
        'likes' => $this->int($row['likes'] ?? 0),
        'comments' => $this->int($row['comments'] ?? 0),
        'shares' => $this->int($row['shares'] ?? 0),
        'subscribers_gained' => $this->int($row['subscribersGained'] ?? 0),
        'subscribers_lost' => $this->int($row['subscribersLost'] ?? 0),
        'videos_published' => 0,
      ];
    }

    return $daily;
  }

  private function videoAnalytics(array $videoIds, string $from, string $to, array &$summary): array
  {
    $analytics = [];
    if ($videoIds === []) {
      return $analytics;
    }
    $metrics = [
      'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
      'likes', 'comments', 'shares', 'subscribersGained', 'subscribersLost',
    ];
    foreach (array_chunk($videoIds, 200) as $chunk) {
      try {
        $rows = $this->report($from, $to, ['video'], $metrics, [
          'filters' => 'video==' . implode(',', $chunk),
          'maxResults' => 200,
        ]);
      } catch (Throwable $error) {
        $summary['warnings'][] = 'YouTube no entregó engagedViews por video; se conservaron las demás métricas. ' . $error->getMessage();
        $fallback = array_values(array_filter($metrics, static fn (string $metric): bool => $metric !== 'engagedViews'));
        $rows = $this->report($from, $to, ['video'], $fallback, [
          'filters' => 'video==' . implode(',', $chunk),
          'maxResults' => 200,
        ]);
      }
      foreach ($rows as $row) {
        $videoId = (string) ($row['video'] ?? '');
        if ($videoId === '') {
          continue;
        }
        $analytics[$videoId] = [
          'views' => $this->int($row['views'] ?? 0),
          'engaged_views' => $this->int($row['engagedViews'] ?? 0),
          'watch_minutes' => $this->number($row['estimatedMinutesWatched'] ?? 0),
          'average_view_duration' => $this->number($row['averageViewDuration'] ?? 0),
          'average_view_percentage' => $this->number($row['averageViewPercentage'] ?? 0),
          'likes' => $this->int($row['likes'] ?? 0),
          'comments' => $this->int($row['comments'] ?? 0),
          'shares' => $this->int($row['shares'] ?? 0),
          'subscribers_gained' => $this->int($row['subscribersGained'] ?? 0),
          'subscribers_lost' => $this->int($row['subscribersLost'] ?? 0),
          'metrics' => $row,
        ];
      }
    }

    return $analytics;
  }

  private function syncBreakdowns(string $channelId, string $from, string $to, array &$summary): int
  {
    $total = 0;
    $reports = [
      'creator_content_type' => [['creatorContentType'], ['views', 'estimatedMinutesWatched']],
      'traffic_source' => [['insightTrafficSourceType'], ['views', 'estimatedMinutesWatched']],
      'search_term' => [['insightTrafficSourceDetail'], ['views', 'estimatedMinutesWatched'], ['filters' => 'insightTrafficSourceType==YT_SEARCH', 'sort' => '-views', 'maxResults' => 20]],
      'country' => [['country'], ['views', 'estimatedMinutesWatched'], ['sort' => '-views', 'maxResults' => 15]],
      'device' => [['deviceType'], ['views', 'estimatedMinutesWatched'], ['sort' => '-views']],
      'subscribed_status' => [['subscribedStatus'], ['views', 'estimatedMinutesWatched'], ['sort' => '-views']],
    ];

    foreach ($reports as $type => $settings) {
      try {
        $rows = $this->report($from, $to, $settings[0], $settings[1], $settings[2] ?? ['sort' => '-views']);
        $payload = [];
        foreach ($rows as $row) {
          $dimension = $settings[0][0];
          $label = trim((string) ($row[$dimension] ?? ''));
          if ($label === '') {
            continue;
          }
          $payload[] = [
            'label' => $label,
            'value' => $this->number($row['views'] ?? 0),
            'watch_minutes' => $this->number($row['estimatedMinutesWatched'] ?? 0),
            'metrics' => $row,
          ];
        }
        $total += $this->repo->replaceBreakdown($channelId, $from, $to, $type, $payload);
      } catch (Throwable $error) {
        $summary['warnings'][] = 'Reporte ' . str_replace('_', ' ', $type) . ': ' . $error->getMessage();
      }
    }

    return $total;
  }

  private function report(string $from, string $to, array $dimensions, array $metrics, array $extra = []): array
  {
    $params = array_merge([
      'ids' => 'channel==MINE',
      'startDate' => $from,
      'endDate' => $to,
      'metrics' => implode(',', $metrics),
    ], $extra);
    if ($dimensions !== []) {
      $params['dimensions'] = implode(',', $dimensions);
    }
    $response = $this->api->analytics($params);
    $headers = array_map(static fn (array $header): string => (string) ($header['name'] ?? ''), (array) ($response['columnHeaders'] ?? []));
    $rows = [];
    foreach ((array) ($response['rows'] ?? []) as $values) {
      $row = [];
      foreach ($headers as $index => $name) {
        if ($name !== '') {
          $row[$name] = $values[$index] ?? null;
        }
      }
      $rows[] = $row;
    }

    return $rows;
  }

  private function emptyDaily(string $channelId, string $date): array
  {
    return [
      'channel_id' => $channelId,
      'metric_date' => $date,
      'views' => 0,
      'engaged_views' => 0,
      'watch_minutes' => 0,
      'average_view_duration' => 0,
      'average_view_percentage' => 0,
      'likes' => 0,
      'comments' => 0,
      'shares' => 0,
      'subscribers_gained' => 0,
      'subscribers_lost' => 0,
      'videos_published' => 0,
    ];
  }

  private function durationSeconds(string $duration): int
  {
    if ($duration === '') {
      return 0;
    }
    try {
      $interval = new DateInterval($duration);
      return max(0, ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s);
    } catch (Throwable) {
      return 0;
    }
  }

  private function int(mixed $value): int
  {
    return max(0, (int) round((float) $value));
  }

  private function number(mixed $value): float
  {
    return max(0, (float) $value);
  }

  private function date(string $value, string $fallback): string
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date ? $date->format('Y-m-d') : $fallback;
  }
}
