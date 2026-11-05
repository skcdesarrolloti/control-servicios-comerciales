<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use RuntimeException;
use Throwable;

/**
 * Sincronización de Instagram, Facebook y publicidad con la Graph API de Meta.
 * Copia de dashboard-marketing app/MetaSocialSyncService.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class MetaSocialSyncService
{
  private SocialStatsWriter $repo;
  private string $version;
  private string $accessToken;
  private string $instagramUserId;
  private string $facebookPageId;
  private string $facebookPageAccessToken;

  public function __construct(?SocialStatsWriter $repo = null)
  {
    $this->repo = $repo ?? new SocialStatsWriter();
    $this->version = trim((string) SocialDb::config('meta.graph_version', 'v25.0')) ?: 'v25.0';
    $this->accessToken = trim((string) SocialDb::config('meta.access_token', ''));
    $this->instagramUserId = trim((string) SocialDb::config('meta.instagram_user_id', ''));
    $this->facebookPageId = trim((string) SocialDb::config('meta.facebook_page_id', ''));
    $this->facebookPageAccessToken = trim((string) SocialDb::config('meta.facebook_page_access_token', ''));
  }

  public function sync(string $from, string $to, string $platform = ''): array
  {
    $from = $this->date($from, date('Y-m-d', strtotime('-7 days')));
    $to = $this->date($to, date('Y-m-d'));
    if (strtotime($from) > strtotime($to)) {
      [$from, $to] = [$to, $from];
    }

    $platform = in_array($platform, ['', 'instagram', 'facebook', 'ads'], true) ? $platform : '';
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];

    if ($this->accessToken === '' && $this->facebookPageAccessToken === '') {
      throw new RuntimeException('Faltan credenciales de Meta. Configura META_ACCESS_TOKEN o META_FB_PAGE_ACCESS_TOKEN.');
    }

    if (($platform === '' || $platform === 'instagram') && $this->instagramUserId !== '' && $this->accessToken !== '') {
      $summary = $this->mergeSummary($summary, $this->syncInstagram($from, $to));
    } elseif ($platform === 'instagram') {
      $summary['warnings'][] = 'Instagram no se sincronizó porque falta META_IG_USER_ID o META_ACCESS_TOKEN.';
    }

    if (($platform === '' || $platform === 'facebook') && $this->facebookPageId !== '') {
      $summary = $this->mergeSummary($summary, $this->syncFacebook($from, $to));
    } elseif ($platform === 'facebook') {
      $summary['warnings'][] = 'Facebook no se sincronizó porque falta META_FB_PAGE_ID.';
    }

    if (($platform === '' || $platform === 'ads') && $this->accessToken !== '') {
      $summary = $this->mergeSummary($summary, $this->syncAds($from, $to));
    } elseif ($platform === 'ads') {
      $summary['warnings'][] = 'Ads no se sincronizó porque falta META_ACCESS_TOKEN.';
    }

    if ($platform === 'ads' && $summary['ads'] === 0) {
      $summary['warnings'][] = 'Meta Ads no devolvió campañas ni anuncios para ese periodo. La pantalla conserva el último periodo guardado.';
      return $summary;
    }

    if ($summary['accounts'] === 0 && $summary['daily'] === 0 && $summary['posts'] === 0 && $summary['ads'] === 0 && $summary['audience'] === 0 && $summary['leads'] === 0 && $summary['conversations'] === 0) {
      throw new RuntimeException('No hay cuentas Meta configuradas para sincronizar. Revisa los IDs y tokens en variables de entorno.');
    }

    return $summary;
  }

  private function syncInstagram(string $from, string $to): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    $profile = $this->graph($this->instagramUserId, [
      'fields' => 'id,username,name,biography,followers_count,follows_count,media_count,profile_picture_url,website',
    ], $this->accessToken);

    $accountId = (string) ($profile['id'] ?? $this->instagramUserId);
    $followers = (int) ($profile['followers_count'] ?? 0);
    $this->repo->upsertAccount([
      'platform' => 'instagram',
      'account_id' => $accountId,
      'account_name' => (string) ($profile['name'] ?? 'Instagram'),
      'username' => (string) ($profile['username'] ?? ''),
      'connected' => 1,
      'notes' => 'Sincronizado desde Instagram Graph API.',
    ]);
    $summary['accounts']++;

    $insightsFrom = date('Y-m-d', max((int) strtotime($from), (int) strtotime($to . ' -29 days')));
    $daily = $this->emptyDailyMap($insightsFrom, $to);
    if ($insightsFrom > $from) {
      $summary['warnings'][] = 'Instagram account insights solo entregó métricas diarias desde ' . $insightsFrom . '. Para fechas anteriores se conservan los snapshots que ya existan en la base.';
    }
    $accountMetrics = $this->safeInsights(
      $this->instagramUserId . '/insights',
      ['reach'],
      ['period' => 'day', 'since' => $this->timestamp($insightsFrom), 'until' => $this->timestamp($to . ' +1 day')],
      $this->accessToken,
      $summary
    );
    $this->applyInsightValues($daily, $accountMetrics);

    $totalMetrics = $this->safeInsights(
      $this->instagramUserId . '/insights',
      ['profile_views', 'website_clicks'],
      ['period' => 'day', 'metric_type' => 'total_value', 'since' => $this->timestamp($insightsFrom), 'until' => $this->timestamp($to . ' +1 day')],
      $this->accessToken,
      $summary
    );
    $this->applyTotalInsightValues($daily, $totalMetrics, $to);
    $summary = $this->mergeSummary($summary, $this->syncInstagramAudience($accountId));

    $postRows = [];
    foreach ($this->safeInstagramMedia($from, $summary) as $media) {
      $date = substr((string) ($media['timestamp'] ?? ''), 0, 10);
      if (!$this->inRange($date, $from, $to)) {
        continue;
      }

      $time = substr((string) ($media['timestamp'] ?? ''), 11, 5);
      $insights = is_array($media['insights'] ?? null) ? $media['insights'] : ['data' => []];
      if (empty($insights['data']) && !empty($media['id'])) {
        $insights = $this->safeInsights(
          (string) ($media['id'] ?? '') . '/insights',
          ['reach', 'saved', 'shares', 'total_interactions', 'views', 'reposts', 'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time'],
          ['period' => 'lifetime'],
          $this->accessToken,
          $summary
        );
      }
      $reach = $this->insightTotal($insights, 'reach');
      $views = $this->insightTotal($insights, 'views');
      $shares = $this->insightTotal($insights, 'shares');
      $saves = $this->insightTotal($insights, 'saved');
      $likes = (int) ($media['like_count'] ?? 0);
      $comments = (int) ($media['comments_count'] ?? 0);

      $postRows[] = [
        'platform' => 'instagram',
        'account_id' => $accountId,
        'external_id' => (string) ($media['id'] ?? ''),
        'post_date' => $date,
        'post_time' => $time,
        'content_type' => strtolower((string) ($media['media_type'] ?? 'post')),
        'title' => $this->excerpt((string) ($media['caption'] ?? 'Publicación de Instagram')),
        'permalink' => (string) ($media['permalink'] ?? ''),
        'thumbnail_url' => (string) ($media['thumbnail_url'] ?? ''),
        'reach' => $reach,
        'impressions' => $views,
        'likes' => $likes,
        'comments' => $comments,
        'shares' => $shares,
        'saves' => $saves,
        'metrics' => $this->flattenInsightMetrics($insights),
        'source' => 'meta',
      ];
      $summary['posts']++;
      if (!empty($insights['data'])) {
        $summary['insights']++;
      }
      if (isset($daily[$date])) {
        $daily[$date]['content_count']++;
        if ($daily[$date]['reach'] === 0) {
          $daily[$date]['reach'] = $reach;
        }
        $daily[$date]['impressions'] += $views;
        $daily[$date]['likes'] += $likes;
        $daily[$date]['comments'] += $comments;
        $daily[$date]['shares'] += $shares;
        $daily[$date]['saves'] += $saves;
      }
    }

    $this->repo->upsertPosts($postRows);
    $dailyRows = [];
    foreach ($daily as $date => $row) {
      $row['platform'] = 'instagram';
      $row['account_id'] = $accountId;
      $row['metric_date'] = $date;
      $row['followers'] = $followers;
      $row['source'] = 'meta';
      $dailyRows[] = $row;
      $summary['daily']++;
    }
    $this->repo->upsertDailyStats($dailyRows);

    return $summary;
  }

  private function safeInstagramMedia(string $from, array &$summary): array
  {
    $items = [];
    try {
      $page = $this->graph($this->instagramUserId . '/media', [
        'fields' => 'id,caption,media_type,timestamp,permalink,thumbnail_url,like_count,comments_count,insights.metric(reach,saved,shares,total_interactions,views,reposts,ig_reels_avg_watch_time,ig_reels_video_view_total_time).period(lifetime)',
        'limit' => 100,
      ], $this->accessToken);
      $seenPages = [];
      while (true) {
        $oldestDate = '';
        foreach (($page['data'] ?? []) as $row) {
          if (!is_array($row)) {
            continue;
          }
          $date = substr((string) ($row['timestamp'] ?? ''), 0, 10);
          if ($date !== '') {
            $oldestDate = $oldestDate === '' ? $date : min($oldestDate, $date);
          }
          $items[] = $row;
        }
        if ($oldestDate !== '' && $oldestDate < $from) {
          break;
        }
        $next = (string) ($page['paging']['next'] ?? '');
        if ($next === '' || isset($seenPages[$next])) {
          break;
        }
        $seenPages[$next] = true;
        try {
          $decoded = $this->graphUrl($next);
        } catch (Throwable $pageError) {
          $summary['warnings'][] = $this->instagramUserId . '/media · paginación: ' . $pageError->getMessage();
          break;
        }
        $page = $decoded;
      }
    } catch (Throwable $e) {
      $summary['warnings'][] = $this->instagramUserId . '/media: ' . $e->getMessage();
    }
    return $items;
  }

  private function syncFacebook(string $from, string $to): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    $token = $this->facebookPageAccessToken !== '' ? $this->facebookPageAccessToken : $this->accessToken;
    if ($token === '') {
      $summary['warnings'][] = 'Facebook no se sincronizó porque falta token.';
      return $summary;
    }

    $profile = $this->graph($this->facebookPageId, [
      'fields' => 'id,name,username,fan_count,followers_count,link',
    ], $token);
    $accountId = (string) ($profile['id'] ?? $this->facebookPageId);
    $followers = (int) ($profile['followers_count'] ?? $profile['fan_count'] ?? 0);
    $this->repo->upsertAccount([
      'platform' => 'facebook',
      'account_id' => $accountId,
      'account_name' => (string) ($profile['name'] ?? 'Facebook'),
      'username' => (string) ($profile['username'] ?? ''),
      'connected' => 1,
      'notes' => 'Sincronizado desde Facebook Page Insights.',
    ]);
    $summary['accounts']++;

    $daily = $this->emptyDailyMap($from, $to);
    $this->applyFacebookDailyInsights($daily, $from, $to, $token, $summary);
    $postRows = [];
    foreach ($this->safeFacebookPosts($from, $to, $token, $summary) as $post) {
      $date = substr((string) ($post['created_time'] ?? ''), 0, 10);
      if (!$this->inRange($date, $from, $to)) {
        continue;
      }

      $likes = (int) ($post['reactions']['summary']['total_count'] ?? 0);
      $comments = (int) ($post['comments']['summary']['total_count'] ?? 0);
      $shares = (int) ($post['shares']['count'] ?? 0);
      $insights = is_array($post['insights'] ?? null) ? $post['insights'] : ['data' => []];
      if (empty($insights['data']) && !empty($post['id'])) {
        $insights = $this->safeInsights(
          (string) $post['id'] . '/insights',
          ['post_media_view', 'post_clicks', 'post_video_views', 'post_video_views_unique'],
          ['period' => 'lifetime'],
          $token,
          $summary
        );
      }
      // Graph API v25 ya no ofrece alcance único orgánico por publicación.
      // post_media_view es el reemplazo disponible y representa visualizaciones.
      $reach = 0;
      $impressions = $this->insightTotal($insights, 'post_media_view');

      $postRows[] = [
        'platform' => 'facebook',
        'account_id' => $accountId,
        'external_id' => (string) ($post['id'] ?? ''),
        'post_date' => $date,
        'post_time' => substr((string) ($post['created_time'] ?? ''), 11, 5),
        'content_type' => 'post',
        'title' => $this->excerpt((string) ($post['message'] ?? 'Publicación de Facebook')),
        'permalink' => (string) ($post['permalink_url'] ?? ''),
        'thumbnail_url' => (string) ($post['full_picture'] ?? ''),
        'reach' => $reach,
        'impressions' => $impressions,
        'likes' => $likes,
        'comments' => $comments,
        'shares' => $shares,
        'saves' => 0,
        'metrics' => $this->flattenInsightMetrics($insights),
        'source' => 'meta',
      ];
      $summary['posts']++;
      if (!empty($insights['data'])) {
        $summary['insights']++;
      }
      $daily[$date]['content_count']++;
      $daily[$date]['reach'] += $reach;
      $daily[$date]['likes'] += $likes;
      $daily[$date]['comments'] += $comments;
      $daily[$date]['shares'] += $shares;
    }

    $this->repo->upsertPosts($postRows);
    $dailyRows = [];
    foreach ($daily as $date => $row) {
      $row['platform'] = 'facebook';
      $row['account_id'] = $accountId;
      $row['metric_date'] = $date;
      $row['followers'] = $followers;
      $row['source'] = 'meta';
      $dailyRows[] = $row;
      $summary['daily']++;
    }
    $this->repo->upsertDailyStats($dailyRows);

    return $summary;
  }

  private function safeFacebookPosts(string $from, string $to, string $token, array &$summary): array
  {
    $items = [];
    try {
      $params = [
        'since' => $this->timestamp($from),
        'until' => $this->timestamp($to . ' +1 day'),
        'limit' => 100,
      ];
      try {
        $page = $this->graph($this->facebookPageId . '/posts', [
          'fields' => 'id,message,created_time,permalink_url,full_picture,shares,comments.limit(0).summary(true),reactions.limit(0).summary(true),insights.metric(post_media_view,post_clicks,post_video_views,post_video_views_unique).period(lifetime)',
        ] + $params, $token);
      } catch (Throwable $metricsError) {
        $summary['warnings'][] = $this->facebookPageId . '/posts · interacciones: ' . $metricsError->getMessage();
        $page = $this->graph($this->facebookPageId . '/posts', [
          'fields' => 'id,message,created_time,permalink_url,full_picture',
        ] + $params, $token);
      }
      $seenPages = [];
      while (true) {
        $oldestDate = '';
        foreach (($page['data'] ?? []) as $row) {
          if (!is_array($row)) {
            continue;
          }
          $date = substr((string) ($row['created_time'] ?? ''), 0, 10);
          if ($date !== '') {
            $oldestDate = $oldestDate === '' ? $date : min($oldestDate, $date);
          }
          $items[] = $row;
        }
        if ($oldestDate !== '' && $oldestDate < $from) {
          break;
        }
        $next = (string) ($page['paging']['next'] ?? '');
        if ($next === '' || isset($seenPages[$next])) {
          break;
        }
        $seenPages[$next] = true;
        try {
          $decoded = $this->graphUrl($next);
        } catch (Throwable $pageError) {
          $summary['warnings'][] = $this->facebookPageId . '/posts · paginación: ' . $pageError->getMessage();
          break;
        }
        $page = $decoded;
      }
    } catch (Throwable $e) {
      $summary['warnings'][] = $this->facebookPageId . '/posts: ' . $e->getMessage();
    }
    return $items;
  }

  /**
  * Page Insights limits long daily ranges. Ninety-day chunks keep each request
  * small while covering the complete selected period without truncation.
  */
  private function applyFacebookDailyInsights(array &$daily, string $from, string $to, string $token, array &$summary): void
  {
    $cursor = new \DateTimeImmutable($from);
    $last = new \DateTimeImmutable($to);
    while ($cursor <= $last) {
      $chunkEnd = min($last, $cursor->modify('+89 days'));
      $response = $this->safeInsights(
        $this->facebookPageId . '/insights',
        ['page_media_view', 'page_views_total'],
        [
          'period' => 'day',
          'since' => $this->timestamp($cursor->format('Y-m-d')),
          'until' => $this->timestamp($chunkEnd->modify('+1 day')->format('Y-m-d')),
        ],
        $token,
        $summary
      );
      $this->applyInsightValues($daily, $response, [
        'page_media_view' => 'impressions',
        'page_views_total' => 'profile_views',
      ], -1);
      $cursor = $chunkEnd->modify('+1 day');
    }
  }

  private function syncInstagramAudience(string $accountId): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    if ($this->repo->hasAudienceSnapshot(date('Y-m-d'), 'instagram')) {
      return $summary;
    }
    foreach (['city', 'country', 'gender', 'age'] as $breakdown) {
      $response = $this->safeInsights(
        $this->instagramUserId . '/insights',
        ['follower_demographics'],
        ['period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $breakdown],
        $this->accessToken,
        $summary
      );
      foreach ($this->demographicRows($response) as $row) {
        $this->repo->upsertAudienceInsight([
          'snapshot_date' => date('Y-m-d'),
          'platform' => 'instagram',
          'account_id' => $accountId,
          'breakdown' => $breakdown,
          'label' => $row['label'],
          'value' => $row['value'],
        ]);
        $summary['audience']++;
      }
    }
    return $summary;
  }

  private function syncAds(string $from, string $to): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    $syncedRows = [];
    foreach ($this->safeGraphAll('/me/adaccounts', [
      'fields' => 'id,account_id,name,account_status,currency,timezone_name,amount_spent,balance',
      'limit' => 10,
    ], $this->accessToken, $summary) as $adAccount) {
      $adAccountId = (string) ($adAccount['id'] ?? '');
      if ($adAccountId === '') {
        continue;
      }

      $timeRange = json_encode(['since' => $from, 'until' => $to], JSON_UNESCAPED_SLASHES);
      $insightBase = [
        'time_range' => $timeRange,
        'limit' => 100,
      ];
      $this->upsertAdInsight($adAccountId, 'account', (string) ($adAccount['name'] ?? 'Cuenta publicitaria'), (string) ($adAccount['account_status'] ?? ''), '', $from, $to, [], '');
      $syncedRows['account:' . $adAccountId] = true;
      $accountInsights = $this->safeGraphAll($adAccountId . '/insights', [
        'fields' => 'spend,impressions,reach,clicks,inline_link_clicks,ctr,cpc,cpm,frequency,actions,cost_per_action_type',
        'level' => 'account',
      ] + $insightBase, $this->accessToken, $summary);
      foreach ($accountInsights as $insight) {
        $this->upsertAdInsight($adAccountId, 'account', (string) ($adAccount['name'] ?? 'Cuenta publicitaria'), (string) ($adAccount['account_status'] ?? ''), '', $from, $to, $insight, '');
      }

      foreach ($this->safeGraphAll($adAccountId . '/insights', [
        'fields' => 'spend,impressions,reach,clicks,inline_link_clicks',
        'level' => 'account',
        'time_increment' => 1,
      ] + $insightBase, $this->accessToken, $summary) as $dailyInsight) {
        $metricDate = substr((string) ($dailyInsight['date_start'] ?? ''), 0, 10);
        if ($metricDate === '') {
          continue;
        }
        $this->upsertAdInsight(
          $adAccountId,
          'account_daily',
          (string) ($adAccount['name'] ?? 'Cuenta publicitaria'),
          (string) ($adAccount['account_status'] ?? ''),
          '',
          $metricDate,
          $metricDate,
          $dailyInsight,
          ''
        );
      }

      $campaignMeta = [];
      foreach ($this->safeGraphAll($adAccountId . '/campaigns', [
        'fields' => 'id,name,status,effective_status,objective,created_time,stop_time',
        'limit' => 100,
      ], $this->accessToken, $summary) as $campaign) {
        $campaignId = (string) ($campaign['id'] ?? '');
        if ($campaignId === '') {
          continue;
        }
        $campaignMeta[$campaignId] = $campaign;
        if (!$this->createdInRange($campaign, $from, $to)) {
          continue;
        }
        $this->upsertAdInsight(
          $adAccountId,
          'campaign',
          (string) ($campaign['name'] ?? ''),
          (string) ($campaign['effective_status'] ?? $campaign['status'] ?? ''),
          (string) ($campaign['objective'] ?? ''),
          $from,
          $to,
          [],
          $campaignId
        );
        $syncedRows['campaign:' . $campaignId] = true;
      }

      foreach ($this->safeGraphAll($adAccountId . '/insights', [
        'fields' => 'campaign_id,campaign_name,spend,impressions,reach,clicks,inline_link_clicks,ctr,cpc,cpm,frequency,actions',
        'level' => 'campaign',
      ] + $insightBase, $this->accessToken, $summary) as $insight) {
        $campaignId = (string) ($insight['campaign_id'] ?? '');
        $campaign = $campaignMeta[$campaignId] ?? [];
        $this->upsertAdInsight(
          $adAccountId,
          'campaign',
          (string) ($insight['campaign_name'] ?? $campaign['name'] ?? ''),
          (string) ($campaign['effective_status'] ?? $campaign['status'] ?? ''),
          (string) ($campaign['objective'] ?? ''),
          $from,
          $to,
          $insight,
          $campaignId
        );
        if ($campaignId !== '') {
          $syncedRows['campaign:' . $campaignId] = true;
        }
      }

      $adMeta = [];
      foreach ($this->safeGraphAll($adAccountId . '/ads', [
        'fields' => 'id,name,status,effective_status,created_time,updated_time,creative{id,name,thumbnail_url}',
        'limit' => 100,
      ], $this->accessToken, $summary) as $ad) {
        $adId = (string) ($ad['id'] ?? '');
        if ($adId === '') {
          continue;
        }
        $adMeta[$adId] = $ad;
        if (!$this->createdInRange($ad, $from, $to)) {
          continue;
        }
        $this->upsertAdInsight(
          $adAccountId,
          'ad',
          (string) ($ad['name'] ?? ''),
          (string) ($ad['effective_status'] ?? $ad['status'] ?? ''),
          '',
          $from,
          $to,
          [],
          $adId,
          (string) ($ad['creative']['thumbnail_url'] ?? '')
        );
        $syncedRows['ad:' . $adId] = true;
      }

      foreach ($this->safeGraphAll($adAccountId . '/insights', [
        'fields' => 'ad_id,ad_name,spend,impressions,reach,clicks,inline_link_clicks,ctr,cpc,cpm,frequency,actions',
        'level' => 'ad',
      ] + $insightBase, $this->accessToken, $summary) as $insight) {
        $adId = (string) ($insight['ad_id'] ?? '');
        $ad = $adMeta[$adId] ?? [];
        $this->upsertAdInsight(
          $adAccountId,
          'ad',
          (string) ($insight['ad_name'] ?? $ad['name'] ?? ''),
          (string) ($ad['effective_status'] ?? $ad['status'] ?? ''),
          '',
          $from,
          $to,
          $insight,
          $adId,
          (string) ($ad['creative']['thumbnail_url'] ?? '')
        );
        if ($adId !== '') {
          $syncedRows['ad:' . $adId] = true;
        }
      }
    }
    $summary['ads'] = count($syncedRows);
    return $summary;
  }

  private function createdInRange(array $entity, string $from, string $to): bool
  {
    $created = substr((string) ($entity['created_time'] ?? ''), 0, 10);
    return $created !== '' && $this->inRange($created, $from, $to);
  }

  private function syncLeadForms(string $token): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    foreach ($this->safeGraphAll($this->facebookPageId . '/leadgen_forms', [
      'fields' => 'id,name,status,leads_count,created_time',
      'limit' => 50,
    ], $token, $summary) as $form) {
      $this->repo->upsertLeadForm([
        'external_id' => (string) ($form['id'] ?? ''),
        'name' => (string) ($form['name'] ?? ''),
        'status' => (string) ($form['status'] ?? ''),
        'leads_count' => (int) ($form['leads_count'] ?? 0),
        'created_time' => (string) ($form['created_time'] ?? ''),
      ]);
      $summary['leads']++;
    }
    return $summary;
  }

  private function syncConversations(string $token): array
  {
    $summary = ['accounts' => 0, 'daily' => 0, 'posts' => 0, 'insights' => 0, 'ads' => 0, 'audience' => 0, 'leads' => 0, 'conversations' => 0, 'warnings' => []];
    foreach ($this->safeGraphPage($this->facebookPageId . '/conversations', [
      'fields' => 'id,updated_time,message_count,unread_count,participants',
      'limit' => 25,
    ], $token, $summary) as $conversation) {
      $participants = [];
      foreach (($conversation['participants']['data'] ?? []) as $participant) {
        if (is_array($participant) && !empty($participant['name'])) {
          $participants[] = (string) $participant['name'];
        }
      }
      $this->repo->upsertConversation([
        'external_id' => (string) ($conversation['id'] ?? ''),
        'updated_time' => (string) ($conversation['updated_time'] ?? ''),
        'message_count' => (int) ($conversation['message_count'] ?? 0),
        'unread_count' => (int) ($conversation['unread_count'] ?? 0),
        'participants' => implode(', ', $participants),
      ]);
      $summary['conversations']++;
    }
    return $summary;
  }

  private function upsertAdInsight(string $accountId, string $level, string $name, string $status, string $objective, string $from, string $to, array $insight, string $externalId = '', string $thumbnailUrl = ''): void
  {
    $this->repo->upsertAdStat([
      'period_start' => $from,
      'metric_date' => $to,
      'level' => $level,
      'account_id' => $accountId,
      'external_id' => $externalId !== '' ? $externalId : $accountId,
      'name' => $name,
      'status' => $status,
      'objective' => $objective,
      'spend' => (float) ($insight['spend'] ?? 0),
      'impressions' => (int) ($insight['impressions'] ?? 0),
      'reach' => (int) ($insight['reach'] ?? 0),
      'clicks' => (int) ($insight['clicks'] ?? 0),
      'link_clicks' => (int) ($insight['inline_link_clicks'] ?? 0),
      'ctr' => (float) ($insight['ctr'] ?? 0),
      'cpc' => (float) ($insight['cpc'] ?? 0),
      'cpm' => (float) ($insight['cpm'] ?? 0),
      'frequency' => (float) ($insight['frequency'] ?? 0),
      'actions' => $insight['actions'] ?? [],
      'thumbnail_url' => $thumbnailUrl,
    ]);
  }

  private function graph(string $path, array $params, string $token): array
  {
    $params['access_token'] = $token;
    $url = 'https://graph.facebook.com/' . rawurlencode($this->version) . '/' . ltrim($path, '/');
    $url .= '?' . http_build_query($params);
    return $this->graphUrl($url);
  }

  private function graphUrl(string $url): array
  {
    $lastError = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $retryable = true;
      try {
        $decoded = json_decode($this->httpGet($url), true);
        if (!is_array($decoded)) {
          throw new RuntimeException('Meta respondió con JSON inválido.');
        }
        if (!isset($decoded['error'])) {
          return $decoded;
        }

        $error = is_array($decoded['error']) ? $decoded['error'] : [];
        $message = (string) ($error['message'] ?? 'Error desconocido de Meta.');
        $code = (int) ($error['code'] ?? 0);
        $transient = !empty($error['is_transient']) || in_array($code, [1, 2, 4, 17, 32, 613], true);
        $lastError = new RuntimeException($message);
        $retryable = $transient;
        if (!$transient || $attempt === 2) {
          throw $lastError;
        }
      } catch (Throwable $e) {
        $lastError = $e;
        if (!$retryable || $attempt === 2) {
          throw $e;
        }
      }
      usleep(250000 * ($attempt + 1));
    }

    throw $lastError ?? new RuntimeException('Meta no respondió correctamente.');
  }

  private function graphAll(string $path, array $params, string $token): array
  {
    $items = [];
    $page = $this->graph($path, $params, $token);
    while (true) {
      foreach (($page['data'] ?? []) as $row) {
        if (is_array($row)) {
          $items[] = $row;
        }
      }
      $next = (string) ($page['paging']['next'] ?? '');
      if ($next === '') {
        break;
      }
      $page = $this->graphUrl($next);
    }
    return $items;
  }

  private function safeInsights(string $path, array $metrics, array $params, string $token, array &$summary): array
  {
    $params['metric'] = implode(',', $metrics);
    try {
      return $this->graph($path, $params, $token);
    } catch (Throwable $e) {
      if (count($metrics) <= 1) {
        $summary['warnings'][] = $path . ': ' . $e->getMessage();
        return ['data' => []];
      }

      $data = [];
      foreach ($metrics as $metric) {
        $singleParams = $params;
        $singleParams['metric'] = $metric;
        try {
          $single = $this->graph($path, $singleParams, $token);
          foreach (($single['data'] ?? []) as $row) {
            $data[] = $row;
          }
        } catch (Throwable $singleError) {
          $summary['warnings'][] = $path . ' · ' . $metric . ': ' . $singleError->getMessage();
        }
      }
      return ['data' => $data];
    }
  }

  private function safeGraphAll(string $path, array $params, string $token, array &$summary): array
  {
    try {
      return $this->graphAll($path, $params, $token);
    } catch (Throwable $e) {
      $summary['warnings'][] = $path . ': ' . $e->getMessage();
      return [];
    }
  }

  private function safeGraphPage(string $path, array $params, string $token, array &$summary): array
  {
    try {
      $page = $this->graph($path, $params, $token);
      return array_values(array_filter($page['data'] ?? [], 'is_array'));
    } catch (Throwable $e) {
      $summary['warnings'][] = $path . ': ' . $e->getMessage();
      return [];
    }
  }

  private function httpGet(string $url): string
  {
    if (function_exists('curl_init')) {
      $curl = curl_init($url);
      curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'SKC Dashboard Marketing/1.0',
      ]);
      $body = curl_exec($curl);
      $error = curl_error($curl);
      $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      curl_close($curl);
      if ($body === false || $error !== '') {
        throw new RuntimeException('No fue posible conectar con Meta: ' . $error);
      }
      if ($status >= 500) {
        throw new RuntimeException('Meta no respondió correctamente. HTTP ' . $status);
      }
      return (string) $body;
    }

    $body = @file_get_contents($url);
    if ($body === false) {
      throw new RuntimeException('No fue posible conectar con Meta.');
    }
    return $body;
  }

  private function applyInsightValues(array &$daily, array $response, array $map = [], int $dayOffset = 0): void
  {
    foreach (($response['data'] ?? []) as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $name = (string) ($metric['name'] ?? '');
      $key = $map[$name] ?? $name;
      if (!isset($daily[array_key_first($daily)][$key]) && $key !== 'reach') {
        continue;
      }
      foreach (($metric['values'] ?? []) as $valueRow) {
        if (!is_array($valueRow)) {
          continue;
        }
        $date = substr((string) ($valueRow['end_time'] ?? ''), 0, 10);
        if ($dayOffset !== 0 && $date !== '') {
          $date = date('Y-m-d', strtotime($date . ' ' . ($dayOffset > 0 ? '+' : '') . $dayOffset . ' days'));
        }
        if (!isset($daily[$date])) {
          continue;
        }
        $daily[$date][$key] += $this->numericInsightValue($valueRow['value'] ?? 0);
      }
    }
  }

  private function applyTotalInsightValues(array &$daily, array $response, string $date): void
  {
    if (!isset($daily[$date])) {
      return;
    }

    foreach (($response['data'] ?? []) as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $name = (string) ($metric['name'] ?? '');
      if (!array_key_exists($name, $daily[$date])) {
        continue;
      }
      $daily[$date][$name] += $this->numericInsightValue($metric['total_value']['value'] ?? 0);
    }
  }

  private function insightTotal(array $response, string $metricName): int
  {
    $fallback = null;
    foreach (($response['data'] ?? []) as $metric) {
      if (($metric['name'] ?? '') !== $metricName) {
        continue;
      }
      $total = 0;
      foreach (($metric['values'] ?? []) as $valueRow) {
        if (is_array($valueRow)) {
          $total += $this->numericInsightValue($valueRow['value'] ?? 0);
        }
      }
      if (($metric['period'] ?? '') === 'lifetime') {
        return $total;
      }
      $fallback ??= $total;
    }
    return $fallback ?? 0;
  }

  private function flattenInsightMetrics(array $response): array
  {
    $metrics = [];
    foreach (($response['data'] ?? []) as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $name = trim((string) ($metric['name'] ?? ''));
      if ($name === '' || (isset($metrics[$name]) && ($metric['period'] ?? '') !== 'lifetime')) {
        continue;
      }
      $total = 0;
      foreach (($metric['values'] ?? []) as $valueRow) {
        if (is_array($valueRow)) {
          $total += $this->numericInsightValue($valueRow['value'] ?? 0);
        }
      }
      $metrics[$name] = $total;
    }
    return $metrics;
  }

  private function numericInsightValue(mixed $value): int
  {
    if (is_numeric($value)) {
      return max(0, (int) $value);
    }
    if (is_array($value)) {
      $sum = 0;
      foreach ($value as $nested) {
        $sum += $this->numericInsightValue($nested);
      }
      return $sum;
    }
    return 0;
  }

  private function emptyDailyMap(string $from, string $to): array
  {
    $days = [];
    $current = new \DateTimeImmutable($from);
    $end = new \DateTimeImmutable($to);
    while ($current <= $end) {
      $days[$current->format('Y-m-d')] = [
        'followers' => 0,
        'reach' => 0,
        'impressions' => 0,
        'profile_views' => 0,
        'website_clicks' => 0,
        'likes' => 0,
        'comments' => 0,
        'shares' => 0,
        'saves' => 0,
        'content_count' => 0,
      ];
      $current = $current->modify('+1 day');
    }
    return $days;
  }

  private function mergeSummary(array $base, array $addition): array
  {
    foreach (['accounts', 'daily', 'posts', 'insights', 'ads', 'audience', 'leads', 'conversations'] as $key) {
      $base[$key] += (int) ($addition[$key] ?? 0);
    }
    $base['warnings'] = array_values(array_merge($base['warnings'], $addition['warnings'] ?? []));
    return $base;
  }

  private function demographicRows(array $response): array
  {
    $rows = [];
    foreach (($response['data'] ?? []) as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      foreach (($metric['total_value']['breakdowns'] ?? []) as $breakdown) {
        if (!is_array($breakdown)) {
          continue;
        }
        foreach (($breakdown['results'] ?? []) as $result) {
          if (!is_array($result)) {
            continue;
          }
          $values = $result['dimension_values'] ?? [];
          $label = is_array($values) ? implode(' · ', array_map('strval', $values)) : (string) $values;
          $value = $this->numericInsightValue($result['value'] ?? 0);
          if ($label !== '' && $value > 0) {
            $rows[] = ['label' => $label, 'value' => $value];
          }
        }
      }
    }
    return $rows;
  }

  private function inRange(string $date, string $from, string $to): bool
  {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= $from && $date <= $to;
  }

  private function timestamp(string $dateExpression): int
  {
    return (int) strtotime($dateExpression);
  }

  private function date(string $value, string $fallback): string
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date ? $date->format('Y-m-d') : $fallback;
  }

  private function excerpt(string $text): string
  {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($text === '') {
      return 'Publicación';
    }
    return mb_substr($text, 0, 180);
  }
}
