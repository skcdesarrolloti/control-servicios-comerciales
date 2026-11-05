<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Social\SocialStatsRepository;
use SCM\Core\Database;

/**
 * Promoción › Redes sociales. Adaptación de dashboard-marketing views/social-stats.php con el diseño de Promoción: una
 * pestaña por red, rango con botón Aplicar y atajos, indicadores contra el periodo anterior, evolución por día, semana o mes,
 * publicaciones o videos por mes con su detalle en una ventana interna, anuncios, audiencia y segmentos de YouTube.
 * Las dos medidas de cada red van en gráficos separados y no sobre dos escalas como en ese panel. Las pestañas LinkedIn y
 * Google de ese panel eran avisos sin datos y no se traen. La inversión solo sale con redes_ver_inversion; sincronizar y
 * conectar piden redes_sincronizar.
 */
final class SocialView
{
  private const AUDIENCE = ['age' => 'Edad', 'gender' => 'Género', 'city' => 'Ciudades', 'country' => 'Países'];
  private const GENDERS = ['F' => 'Mujeres', 'M' => 'Hombres', 'U' => 'Sin dato'];
  private const GRANULARITY = ['day' => ['día', 'Día', 'en un día'], 'week' => ['semana', 'Semana', 'en una semana'], 'month' => ['mes', 'Mes', 'en un mes']];
  private const YOUTUBE_LABELS = [
    'SHORTS' => 'Shorts', 'VIDEO_ON_DEMAND' => 'Videos', 'LIVE_STREAM' => 'En vivo', 'YT_SEARCH' => 'Búsqueda de YouTube', 'EXT_URL' => 'Sitios externos', 'RELATED_VIDEO' => 'Videos sugeridos',
    'SUBSCRIBER' => 'Suscripciones', 'BROWSE' => 'Inicio y exploración', 'END_SCREEN' => 'Pantallas finales', 'PLAYLIST' => 'Listas de reproducción', 'NOTIFICATION' => 'Notificaciones',
    'MOBILE' => 'Móvil', 'DESKTOP' => 'Computador', 'TV' => 'Televisor', 'TABLET' => 'Tableta', 'SUBSCRIBED' => 'Suscritos', 'UNSUBSCRIBED' => 'No suscritos',
  ];
  private const YOUTUBE_GROUPS = ['traffic_source' => 'Fuentes de tráfico', 'search_term' => 'Búsquedas en YouTube', 'country' => 'Países principales', 'device' => 'Dispositivos', 'subscribed_status' => 'Suscripción'];
  private const AD_ACTIONS = [
    'lead' => ['Leads', 'Clientes potenciales registrados desde el anuncio.'],
    'link_click' => ['Clics en enlace', 'Clics hacia el enlace, sitio, WhatsApp u otro destino del anuncio.'],
    'onsite_conversion.messaging_conversation_started_7d' => ['Conversaciones', 'Conversaciones iniciadas por el anuncio dentro de la ventana de atribución.'],
    'onsite_conversion.total_messaging_connection' => ['Conexiones por mensaje', 'Total de contactos por mensajería atribuidos al anuncio.'],
    'onsite_conversion.messaging_first_reply' => ['Primeras respuestas', 'Primeras respuestas en conversaciones iniciadas desde el anuncio.'],
    'onsite_conversion.post_save' => ['Guardados', 'Veces que las personas guardaron la publicación promocionada.'],
    'onsite_conversion.post_net_save' => ['Guardados netos', 'Guardados netos luego de ajustes de Meta.'],
    'onsite_conversion.post_net_like' => ['Me gusta netos', 'Me gusta netos luego de ajustes de Meta.'],
    'post_engagement' => ['Interacción post', 'Clics, reacciones, comentarios, guardados o compartidos sobre la publicación.'],
    'post_interaction_gross' => ['Interacciones brutas', 'Interacciones antes de ajustes de Meta.'],
    'post_interaction_net' => ['Interacciones netas', 'Interacciones después de ajustes de Meta.'],
    'post_reaction' => ['Reacciones', 'Reacciones en la publicación promocionada.'],
    'comment' => ['Comentarios', 'Comentarios atribuidos al anuncio.'],
    'like' => ['Me gusta', 'Me gusta atribuidos al anuncio.'],
    'page_engagement' => ['Interacción página', 'Interacciones con la página originadas por el anuncio.'],
    'video_view' => ['Reproducciones', 'Reproducciones de video atribuidas al anuncio.'],
  ];

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, ?string $today = null): array
  {
    $repository = new SocialStatsRepository($db);
    $filters = $repository->filters($input, $today ?? date('Y-m-d'));
    $spend = $policy->canAct('redes_ver_inversion');
    // Aviso que deja public/social-callback.php al volver de conectar una cuenta.
    $flash = is_array($_SESSION['social_flash'] ?? null) ? $_SESSION['social_flash'] : [];
    unset($_SESSION['social_flash']);
    return ['filters' => $filters, 'can_spend' => $spend, 'can_sync' => $policy->canAct('redes_sincronizar'), 'flash' => $flash, 'data' => $repository->summary($filters, $spend)];
  }

  /** Sincronizar el rango y, en YouTube y TikTok, conectar o desconectar la cuenta. @param array<string,mixed> $context */
  private static function syncActions(array $context, string $baseUrl): string
  {
    if (empty($context['can_sync'])) {
      return '';
    }
    $filters = $context['filters'];
    $network = $filters['network'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = '<button type="button" class="cpr-primary" data-social-sync="' . $e($network) . '" data-from="' . $e($filters['from']) . '" data-to="' . $e($filters['to']) . '"><span class="material-symbols-outlined" aria-hidden="true">sync</span>Sincronizar este rango</button>';
    if (in_array($network, ['youtube', 'tiktok'], true)) {
      $connected = ($context['data']['account']['name'] ?? '') !== '';
      $url = rtrim($baseUrl, '/') . '/social-callback.php?network=' . $network . '&start=1';
      $html = '<button type="button" class="cpr-ghost" data-social-connect="' . $e($url) . '">' . ($connected ? 'Reconectar' : 'Conectar cuenta') . '</button>'
        . ($connected ? '<button type="button" class="cpr-ghost is-danger" data-social-disconnect="' . $e($network) . '">Desconectar</button>' : '') . $html;
    }
    return '<div class="cpr-toolbar__actions">' . $html . '</div>';
  }

  private static function disconnectDialog(): string
  {
    return '<dialog class="cpr-dialog" data-social-dialog><form method="dialog" data-social-disconnect-form><div class="cpr-dialog__head"><h2>Desconectar cuenta</h2><button type="button" class="cpr-ghost is-small" data-social-close>Cerrar</button></div>'
      . '<input type="hidden" name="network"><label class="cpr-check is-wide"><input type="checkbox" name="confirm" value="1" required><span>Entiendo que esta red deja de sincronizarse hasta conectarla otra vez. Las estadísticas guardadas se conservan.</span></label>'
      . '<p class="cpr-alert is-error" data-social-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-social-close>Cancelar</button><button type="submit" class="cpr-primary is-danger">Desconectar</button></div></form></dialog>';
  }

  /** Ventana de detalle: la llena commercial-promotion.js con los datos del botón que la abre. */
  private static function detailDialog(): string
  {
    return '<dialog class="cpr-dialog is-wide" data-social-detail-dialog><div class="cpr-dialog__head"><h2 data-social-detail-title>Detalle</h2><button type="button" class="cpr-ghost is-small" data-social-close>Cerrar</button></div>'
      . '<div class="cpr-camp-detail"><div class="cpr-social-detail__top"><img src="" alt="" data-social-detail-image hidden><p class="cpr-cell-sub" data-social-detail-subtitle></p></div><p class="cpr-social-detail__text" data-social-detail-description hidden></p><p class="cpr-note" data-social-detail-notice hidden></p>'
      . '<dl class="cpr-camp-stats" data-social-detail-metrics></dl><div data-social-detail-actions-box hidden><h3>Acciones registradas</h3><dl class="cpr-camp-stats" data-social-detail-actions></dl></div>'
      . '<div class="cpr-dialog__foot"><a class="cpr-primary" data-social-detail-link href="#" target="_blank" rel="noopener noreferrer" hidden></a></div></div></dialog>';
  }

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    $filters = $context['filters'];
    $data = $context['data'];
    $network = $filters['network'];
    $html = PromotionUi::hero('share', 'Redes sociales', 'Seguidores, alcance, publicaciones y audiencia de Instagram, Facebook, YouTube y TikTok de SKC SuCasa Inmobiliaria, y los resultados de la publicidad en Meta.');
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    $tabs = '';
    foreach (SocialStatsRepository::NETWORKS as $key => $label) {
      $active = $key === $network;
      $tabs .= '<a class="cpr-tab' . ($active ? ' is-active' : '') . '" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales', 'pr_red' => $key])) . '"' . ($active ? ' aria-current="page"' : '') . '>' . PromotionUi::e($label) . '</a>';
    }
    if (!empty($context['flash']['message'])) {
      $html .= '<p class="cpr-alert' . (($context['flash']['type'] ?? '') === 'error' ? ' is-error' : '') . '">' . PromotionUi::e($context['flash']['message']) . '</p>';
    }
    $html .= '<div class="cpr-toolbar"><nav class="cpr-tabs" aria-label="Redes">' . $tabs . '</nav>' . self::syncActions($context, $baseUrl) . '</div>';
    if (!empty($context['can_sync'])) {
      $html .= self::disconnectDialog();
    }
    $end = new \DateTimeImmutable($filters['latest'] !== '' && $filters['latest'] < $filters['today'] ? $filters['latest'] : $filters['today']);
    $range = static fn(string $label, string $from): string => '<a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales', 'pr_red' => $network, 'pr_desde' => $from, 'pr_hasta' => $end->format('Y-m-d')])) . '">' . $label . '</a>';
    $html .= '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="redes_sociales" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales'])) . '"><input type="hidden" name="pr_red" value="' . PromotionUi::e($network) . '">'
      . '<div class="cpr-filters__grid is-wide">' . self::date('pr_desde', 'Desde', $filters['from']) . self::date('pr_hasta', 'Hasta', $filters['to']) . '</div>'
      . '<div class="cpr-filters__actions">' . $range('Últimos 30 días', $end->modify('-29 days')->format('Y-m-d')) . $range('Desde el año pasado', $end->modify('-1 year')->format('Y') . '-01-01')
      . '<a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales', 'pr_red' => $network])) . '">Últimos 90 días con datos</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">' . ($filters['latest'] !== '' ? 'Último dato de ' . PromotionUi::e(SocialStatsRepository::NETWORKS[$network]) . ': ' . PromotionUi::e(PromotionUi::day($filters['latest'])) . '.' : 'Todavía no hay datos de esta red.') . ' Hasta 2 años por consulta.</p></form>';
    if (empty($data['available'])) {
      return $html . '<p class="cpr-alert is-error">No se pudieron leer los datos de esta red (tablas marketing_social_*, marketing_youtube_* o marketing_tiktok_*).</p>';
    }
    if ($filters['clamped']) {
      $html .= '<p class="cpr-alert">El rango máximo es de 2 años: se muestran desde el ' . PromotionUi::e(PromotionUi::day($filters['from'])) . '.</p>';
    }
    $granularity = SocialStatsRepository::granularity($filters['from'], $filters['to']);
    return $html . match ($network) {
      'instagram', 'facebook' => self::meta($data, $network, $granularity),
      'publicidad' => self::ads($data, $granularity),
      'youtube' => self::youtube($data, $filters, $granularity),
      default => self::tiktok($data, $filters, $granularity),
    } . self::detailDialog();
  }

  private static function date(string $name, string $label, string $value): string
  {
    return '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input type="date" name="' . $name . '" value="' . PromotionUi::e($value) . '" required></label>';
  }

  /** @param array<string,mixed> $account */
  private static function account(array $account, string $extra = ''): string
  {
    if (($account['name'] ?? '') === '') {
      return '<p class="cpr-alert">Esta red no está conectada o todavía no se ha sincronizado.</p>';
    }
    $sync = (string) ($account['last_sync'] ?? '');
    return '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">account_circle</span><span><strong>' . PromotionUi::e($account['name']) . '</strong>'
      . (($account['username'] ?? '') !== '' ? ' · @' . PromotionUi::e(ltrim((string) $account['username'], '@')) : '') . $extra
      . ' · Última sincronización: ' . ($sync !== '' ? PromotionUi::e(PromotionUi::day($sync, true)) : 'nunca') . '</span></p>'
      . (($account['error'] ?? '') !== '' ? '<p class="cpr-alert is-error">La última sincronización falló: ' . PromotionUi::e($account['error']) . '</p>' : '');
  }

  private static function decimal(float $value, int $decimals = 1): string
  {
    return number_format($value, $decimals, ',', '.');
  }

  private static function percent(float $value, int $decimals = 2): string
  {
    return self::decimal($value, $decimals) . '%';
  }

  private static function money(float $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  private static function hours(float $minutes): string
  {
    return self::decimal($minutes / 60);
  }

  private static function duration(float $seconds): string
  {
    $total = max(0, (int) round($seconds));
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $rest = str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT);
    return $hours > 0 ? $hours . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ':' . $rest : $minutes . ':' . $rest;
  }

  /** @param array{ready:bool} $comparison */
  private static function change(float $current, float $past, array $comparison): string
  {
    if (!$comparison['ready']) {
      return 'Sin base anterior';
    }
    if ($past === 0.0) {
      return 'Sin datos previos';
    }
    $change = ($current - $past) * 100 / max(1.0, abs($past));
    return ($change > 0 ? '+' : '') . self::decimal(round($change, 1)) . '% vs anterior';
  }

  /** @param array<string,mixed> $comparison */
  private static function periodNote(array $comparison): string
  {
    $note = ($comparison['ready'] ? 'Comparado contra ' : 'Aún no hay base completa para comparar contra ') . '<strong>' . PromotionUi::e(PromotionUi::day($comparison['previous_from']) . ' a ' . PromotionUi::day($comparison['previous_to'])) . '</strong>. ';
    $note .= $comparison['covered'] > 0 ? 'Datos guardados del ' . PromotionUi::e(PromotionUi::day($comparison['first'])) . ' al ' . PromotionUi::e(PromotionUi::day($comparison['last'])) . ' (' . (int) $comparison['covered'] . ' de ' . (int) $comparison['requested'] . ' días).' : 'No hay datos guardados para este rango.';
    return '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">compare_arrows</span><span>' . $note . '</span></p>';
  }

  /**
   * Dos gráficos alineados por periodo: cada medida con su propia escala, nunca dos escalas en uno.
   * @param list<array<string,mixed>> $series
   * @param callable(array<string,mixed>):string|null $tip Datos extra del periodo para el recuadro de cada barra.
   */
  private static function charts(array $series, string $granularity, string $firstKey, string $firstTitle, string $firstUnit, string $secondKey, string $secondTitle, string $secondUnit, ?callable $tip = null): string
  {
    [$unit, $column, $peak] = self::GRANULARITY[$granularity];
    $points = static fn(string $key, string $label): array => array_map(static fn(array $row): array => ['label' => (string) $row['label'], 'short' => (string) $row['short'], 'value' => (int) round((float) $row[$key]), 'tip' => $tip !== null ? $tip($row) : ''], $series);
    return '<div class="cpr-social-charts">' . PromotionUi::periodChart($firstTitle . ' por ' . $unit, $firstUnit, $points($firstKey, $firstUnit), $column, $peak) . PromotionUi::periodChart($secondTitle . ' por ' . $unit, $secondUnit, $points($secondKey, $secondUnit), $column, $peak) . '</div>';
  }

  /** Botón que abre la ventana de detalle. @param array<string,mixed> $payload */
  private static function detailButton(array $payload, string $label = 'Detalles'): string
  {
    return '<button type="button" class="cpr-ghost is-small" data-social-detail="' . PromotionUi::e((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">' . $label . '</button>';
  }

  private static function safeUrl(string $url): string
  {
    return preg_match('#^https://#i', trim($url)) === 1 ? trim($url) : '';
  }

  /** Enlace "Ver" solo con direcciones https. */
  private static function viewLink(string $url): string
  {
    $safe = self::safeUrl($url);
    return $safe !== '' ? '<a class="cpr-ghost is-small" href="' . PromotionUi::e($safe) . '" target="_blank" rel="noopener noreferrer">Ver</a>' : '';
  }

  private static function thumb(string $url): string
  {
    $safe = self::safeUrl($url);
    return '<span class="cpr-social-row__thumb">' . ($safe !== '' ? '<img src="' . PromotionUi::e($safe) . '" alt="" loading="lazy" width="64" height="64">' : '') . '</span>';
  }

  /** @param list<array{0:string,1:string}> $numbers Rótulo y valor. */
  private static function row(string $thumbnail, string $title, string $sub, array $numbers, string $actions): string
  {
    $cells = '';
    foreach ($numbers as [$label, $value]) {
      $cells .= '<span class="cpr-social-row__metric"><small>' . PromotionUi::e($label) . '</small><strong>' . PromotionUi::e($value) . '</strong></span>';
    }
    return '<article class="cpr-social-row">' . self::thumb($thumbnail) . '<div class="cpr-social-row__text"><span class="cpr-cell-main">' . PromotionUi::e(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $title) ?? $title), 0, 120, '…', 'UTF-8')) . '</span><span class="cpr-cell-sub">' . PromotionUi::e($sub) . '</span></div>' . $cells . '<div class="cpr-pop-card__actions">' . $actions . '</div></article>';
  }

  /** @param list<array{label:string,items:list<array<string,mixed>>}> $months @param callable(array<string,mixed>):string $row @param callable(list<array<string,mixed>>):string $summary */
  private static function monthList(string $title, string $subtitle, array $months, callable $row, callable $summary, string $empty): string
  {
    $total = array_sum(array_map(static fn(array $month): int => count($month['items']), $months));
    $html = '';
    foreach ($months as $index => $month) {
      $html .= '<details class="cpr-social-month"' . ($index === 0 ? ' open' : '') . '><summary><strong>' . PromotionUi::e($month['label']) . '</strong><span class="cpr-cell-sub">' . PromotionUi::e($summary($month['items'])) . '</span></summary><div class="cpr-social-list">' . implode('', array_map($row, $month['items'])) . '</div></details>';
    }
    return '<section class="cpr-card" data-social-months><div class="cpr-card__head"><div><h2>' . PromotionUi::e($title) . '</h2><p>' . PromotionUi::e($subtitle . ' · ' . PromotionUi::number($total) . ' en el rango') . '</p></div></div>' . ($html !== '' ? $html : '<p class="cpr-empty">' . PromotionUi::e($empty) . '</p>') . '</section>';
  }

  /** @param array<string,mixed> $post @return array<string,mixed> */
  private static function postDetail(array $post): array
  {
    $facebook = $post['platform'] === 'facebook';
    $exposure = $facebook ? $post['impressions'] : $post['reach'];
    $name = $facebook ? 'visualizaciones' : 'alcance';
    $count = static fn(?int $value): string => $value !== null ? PromotionUi::number($value) : 'N/D';
    $available = static fn(int $value): string => $value > 0 ? PromotionUi::number($value) : 'N/D';
    return [
      'title' => 'Publicación de ' . ($facebook ? 'Facebook' : 'Instagram'), 'subtitle' => trim(PromotionUi::day($post['date']) . ($post['time'] !== '' ? ' · ' . substr($post['time'], 0, 5) : '') . ' · ' . ($post['type'] ?: 'post')),
      'description' => $post['title'] ?: 'Publicación sin texto disponible.', 'thumbnail' => self::safeUrl($post['thumbnail']), 'link' => self::safeUrl($post['url']), 'linkLabel' => 'Ver publicación',
      'notice' => $exposure <= 0 ? 'Meta no entregó ' . $name . ' para esta publicación; por eso la tasa de interacción sale como N/D.' : ($facebook ? 'Meta entrega visualizaciones de Facebook, pero ya no el alcance único por publicación.' : ''),
      'metrics' => [
        ['Interacciones', PromotionUi::number($post['interactions'])], ['Me gusta', PromotionUi::number($post['likes'])], ['Comentarios', PromotionUi::number($post['comments'])], ['Compartidos', PromotionUi::number($post['shares'])], ['Guardados', PromotionUi::number($post['saves'])],
        ['Alcance', $available($post['reach'])], [$facebook ? 'Visualizaciones' : 'Impresiones', $available($post['impressions'])], ['Clics', $count($post['clicks'])], ['Reproducciones', $count($post['plays'])],
        ['Tasa de interacción', $exposure > 0 ? self::percent($post['interactions'] * 100 / $exposure) : 'N/D'],
      ],
    ];
  }

  /** @param array<string,mixed> $data */
  private static function meta(array $data, string $network, string $granularity): string
  {
    $k = $data['kpis'];
    $p = $data['previous'];
    $c = $data['comparison'];
    $e = static fn($value): string => PromotionUi::e($value);
    $facebook = $data['exposure'] === 'impressions';
    $exposureLabel = $facebook ? 'Visualizaciones' : 'Alcance';
    $html = self::account($data['account']);
    $hasExposure = $k['exposure'] > 0;
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Seguidores', PromotionUi::number($k['followers']), self::change($k['followers'], $p['followers'], $c), 'group')
      . PromotionUi::stat($exposureLabel, $hasExposure ? PromotionUi::number($k['exposure']) : 'N/D', $hasExposure ? self::change($k['exposure'], $p['exposure'], $c) : 'Meta no entregó esta métrica', 'visibility')
      . PromotionUi::stat('Interacciones', PromotionUi::number($k['interactions']), self::change($k['interactions'], $p['interactions'], $c), 'favorite')
      . PromotionUi::stat($facebook ? 'Interacción / vistas' : 'Tasa de interacción', $hasExposure ? self::percent($k['rate']) : 'N/D', $hasExposure ? self::change($k['rate'], $p['rate'], $c) : 'No se puede calcular', 'percent')
      . PromotionUi::stat('Visitas al perfil', PromotionUi::number($k['profile_views']), self::change($k['profile_views'], $p['profile_views'], $c), 'person_search')
      . PromotionUi::stat('Publicaciones', PromotionUi::number($k['posts']), self::change($k['posts'], $p['posts'], $c), 'article')
      . PromotionUi::stat('Clics al sitio web', PromotionUi::number($k['website_clicks']), self::change($k['website_clicks'], $p['website_clicks'], $c), 'ads_click')
      . '</div>' . self::periodNote($c);
    $html .= self::charts($data['series'], $granularity, 'exposure', $exposureLabel, $facebook ? 'visualizaciones' : 'cuentas', 'interactions', 'Interacciones', 'interacciones', static fn(array $row): string => 'Seguidores al cierre: ' . PromotionUi::number((int) $row['followers']));
    $exposureOf = static fn(array $post): int => $facebook ? $post['impressions'] : $post['reach'];
    $top = '';
    foreach ($data['top'] as $post) {
      $top .= self::row($post['thumbnail'], $post['title'] ?: 'Publicación sin texto', PromotionUi::day($post['date']) . ($post['type'] !== '' ? ' · ' . $post['type'] : ''), [[$exposureLabel, $exposureOf($post) > 0 ? PromotionUi::number($exposureOf($post)) : 'N/D'], ['Interacciones', PromotionUi::number($post['interactions'])]], self::detailButton(self::postDetail($post)) . self::viewLink($post['url']));
    }
    $html .= '<section class="cpr-card" data-social-top><div class="cpr-card__head"><div><h2>Publicaciones destacadas</h2><p>Las ' . count($data['top']) . ' con más interacciones del periodo</p></div></div>' . ($top !== '' ? '<div class="cpr-social-list">' . $top . '</div>' : '<p class="cpr-empty">No hay publicaciones en el rango.</p>') . '</section>';
    $html .= self::monthList('Publicaciones realizadas', 'Contenido publicado en el rango con sus métricas de Meta', SocialStatsRepository::months($data['posts'], 'date'),
      static fn(array $post): string => self::row($post['thumbnail'], $post['title'] ?: 'Publicación sin texto', PromotionUi::day($post['date']) . ($post['time'] !== '' ? ' · ' . substr($post['time'], 0, 5) : '') . ' · ' . ($post['type'] ?: 'post') . ' · ' . PromotionUi::number($post['likes']) . ' me gusta · ' . PromotionUi::number($post['comments']) . ' comentarios',
        [[$exposureLabel, $exposureOf($post) > 0 ? PromotionUi::number($exposureOf($post)) : 'N/D'], ['Interacciones', PromotionUi::number($post['interactions'])]], self::detailButton(self::postDetail($post)) . self::viewLink($post['url'])),
      static fn(array $items): string => count($items) . ' publicaciones · ' . PromotionUi::number(array_sum(array_column($items, 'interactions'))) . ' interacciones', 'No hay publicaciones en el rango.');
    $audience = $data['audience'];
    $groups = '';
    foreach (self::AUDIENCE as $key => $label) {
      $items = $audience['groups'][$key] ?? [];
      if ($items === []) {
        continue;
      }
      $total = max(1, array_sum(array_column($items, 'value')));
      $rows = '';
      foreach ($items as $item) {
        $name = $key === 'gender' ? (self::GENDERS[$item['label']] ?? $item['label']) : $item['label'];
        $rows .= '<tr><td>' . $e($name) . '<span class="cpr-meter"><span style="width:' . round($item['value'] * 100 / $total, 1) . '%"></span></span></td><td class="is-num">' . $e(PromotionUi::number($item['value'])) . '</td></tr>';
      }
      $groups .= '<h3 class="cpr-social-group">' . $e($label) . '</h3><div class="cpr-table-wrap"><table class="cpr-table"><tbody>' . $rows . '</tbody></table></div>';
    }
    return $html . '<section class="cpr-card"><div class="cpr-card__head"><div><h2>Audiencia</h2><p>' . ($audience['snapshot'] !== '' ? 'Seguidores según ' . $e(SocialStatsRepository::NETWORKS[$network]) . ' al ' . $e(PromotionUi::day($audience['snapshot'])) : 'Sin datos de audiencia') . '</p></div></div>'
      . ($groups !== '' ? $groups : '<p class="cpr-empty">' . ($network === 'facebook' ? 'Facebook no entrega la audiencia de la página.' : 'Todavía no hay datos de audiencia.') . '</p>') . '</section>';
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private static function adDetail(array $row, string $kind, bool $spend): array
  {
    $metrics = [];
    if ($spend) {
      $metrics[] = ['Gasto', self::money($row['spend']), 'Dinero invertido durante el periodo guardado.'];
    }
    array_push($metrics, ['Impresiones', PromotionUi::number($row['impressions']), 'Veces que se mostró. Una persona puede verlo varias veces.'], ['Alcance', PromotionUi::number($row['reach']), 'Personas únicas que lo vieron al menos una vez.'], ['Clics', PromotionUi::number($row['clicks']), 'Todos los clics registrados por Meta.'],
      ['Clics en enlace', PromotionUi::number($row['link_clicks']), 'Clics hacia un enlace, WhatsApp, sitio o destino configurado.'], ['CTR', self::percent($row['ctr']), 'Porcentaje de impresiones que terminaron en clic.']);
    if ($spend) {
      array_push($metrics, ['CPC', self::money($row['cpc']), 'Costo promedio por clic.'], ['CPM', self::money($row['cpm']), 'Costo promedio por cada mil impresiones.']);
    }
    $metrics[] = ['Frecuencia', self::decimal($row['frequency'], 2), 'Promedio de veces que cada persona alcanzada lo vio.'];
    return [
      'title' => $kind . ': ' . ($row['name'] ?: 'Sin nombre'), 'subtitle' => 'Periodo ' . PromotionUi::day($row['since']) . ' a ' . PromotionUi::day($row['date']) . ($row['status'] !== '' ? ' · ' . $row['status'] : ''), 'thumbnail' => self::safeUrl($row['thumbnail']), 'metrics' => $metrics,
      'actions' => array_map(static fn(array $action): array => [self::AD_ACTIONS[$action['type']][0] ?? ucwords(str_replace(['onsite_conversion.', '_'], ['', ' '], $action['type'])), PromotionUi::number($action['value']), self::AD_ACTIONS[$action['type']][1] ?? 'Acción reportada por Meta Ads.'], $row['actions']),
    ];
  }

  /** @param array<string,mixed> $data */
  private static function ads(array $data, string $granularity): string
  {
    $k = $data['kpis'];
    $spend = (bool) $data['with_spend'];
    $period = $data['period'];
    $html = '';
    if ($period === null) {
      $html .= '<p class="cpr-alert">Todavía no hay campañas de publicidad con actividad guardadas.</p>';
    } else {
      $label = PromotionUi::day($period['from']) . ' a ' . PromotionUi::day($period['to']);
      $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">event</span><span>' . ($period['exact'] ? 'Periodo sincronizado: ' : 'No hay datos exactos para este rango. Se muestra el último periodo con actividad: ') . '<strong>' . PromotionUi::e($label) . '</strong>.</span></p>';
    }
    $html .= '<div class="cpr-stats" data-social-ads-kpis>'
      . ($spend ? PromotionUi::stat('Inversión', self::money((float) $k['spend']), 'Dinero invertido en el periodo', 'payments') : '')
      . PromotionUi::stat('Impresiones', PromotionUi::number($k['impressions']), 'Veces que se mostraron los anuncios', 'visibility')
      . PromotionUi::stat('Alcance', PromotionUi::number($k['reach']), 'Personas únicas alcanzadas', 'groups')
      . PromotionUi::stat('Clics', PromotionUi::number($k['clicks']), PromotionUi::number($k['link_clicks']) . ' en enlaces', 'ads_click')
      . PromotionUi::stat('CTR', self::percent($k['ctr']), 'Impresiones que terminaron en clic', 'percent')
      . ($spend ? PromotionUi::stat('CPC', self::money((float) $k['cpc']), 'Costo promedio por clic', 'sell') : '')
      . '</div>';
    if (!$spend) {
      $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">lock</span>La inversión, el costo por clic y el CPM solo los ve quien tiene el permiso.</p>';
    }
    $html .= self::charts($data['series'], $granularity, 'impressions', 'Impresiones', 'impresiones', 'link_clicks', 'Clics en enlace', 'clics', static fn(array $row): string => 'Alcance: ' . PromotionUi::number((int) $row['reach']) . (isset($row['spend']) ? ' · Inversión: ' . self::money((float) $row['spend']) : ''));
    $list = static function (array $rows, string $kind, string $fallback, string $empty) use ($spend): string {
      $html = '';
      foreach ($rows as $row) {
        $sub = trim(($row['status'] !== '' ? $row['status'] : 'Estado sin dato') . ($row['objective'] !== '' ? ' · ' . $row['objective'] : ''));
        $numbers = [['Impresiones', PromotionUi::number($row['impressions'])], ['Clics en enlace', PromotionUi::number($row['link_clicks'])]];
        if ($spend) {
          array_unshift($numbers, ['Inversión', self::money($row['spend'])]);
        }
        $html .= self::row($row['thumbnail'], $row['name'] ?: $fallback, $sub, $numbers, self::detailButton(self::adDetail($row, $kind, $spend), 'Detalle'));
      }
      return $html !== '' ? '<div class="cpr-social-list">' . $html . '</div>' : '<p class="cpr-empty">' . $empty . '</p>';
    };
    return $html . '<section class="cpr-card" data-social-campaigns><div class="cpr-card__head"><div><h2>Campañas</h2><p>' . count($data['campaigns']) . ' en el periodo</p></div></div>' . $list($data['campaigns'], 'Campaña', 'Campaña sin nombre', 'Sin campañas sincronizadas.') . '</section>'
      . '<section class="cpr-card" data-social-ads><div class="cpr-card__head"><div><h2>Anuncios</h2><p>' . count($data['ads']) . ' en el periodo</p></div></div>' . $list($data['ads'], 'Anuncio', 'Anuncio sin nombre', 'Sin anuncios sincronizados.') . '</section>';
  }

  /** @param array<string,mixed> $video @return array<string,mixed> */
  private static function youtubeDetail(array $video): array
  {
    $gained = (int) $video['subscribers_gained'];
    $lost = (int) $video['subscribers_lost'];
    return [
      'title' => $video['title'] ?: 'Video de YouTube', 'subtitle' => PromotionUi::day((string) $video['published_at'], true) . ' · ' . ($video['content_type'] === 'live' ? 'Transmisión' : 'Video'), 'thumbnail' => self::safeUrl((string) $video['thumbnail_url']), 'description' => mb_substr(trim((string) $video['description']), 0, 700, 'UTF-8'),
      'notice' => 'Las métricas de rendimiento corresponden al periodo ' . PromotionUi::day((string) $video['analytics_from']) . ' a ' . PromotionUi::day((string) $video['analytics_to']) . '. Las visualizaciones públicas son el acumulado actual del video.', 'link' => self::safeUrl((string) $video['permalink']), 'linkLabel' => 'Ver en YouTube',
      'metrics' => [
        ['Visualizaciones del periodo', PromotionUi::number((int) $video['views'])], ['Visualizaciones públicas', PromotionUi::number((int) $video['public_views'])], ['Vistas con interés', PromotionUi::number((int) $video['engaged_views'])], ['Horas vistas', self::hours((float) $video['watch_minutes'])],
        ['Duración promedio', self::duration((float) $video['average_view_duration'])], ['Porcentaje promedio', self::percent((float) $video['average_view_percentage'], 1)], ['Me gusta', PromotionUi::number((int) $video['likes'] ?: (int) $video['public_likes'])], ['Comentarios', PromotionUi::number((int) $video['comments'] ?: (int) $video['public_comments'])],
        ['Compartidos', PromotionUi::number((int) $video['shares'])], ['Suscriptores netos', PromotionUi::number($gained - $lost), PromotionUi::number($gained) . ' ganados y ' . PromotionUi::number($lost) . ' perdidos.'], ['Duración del video', self::duration((float) $video['duration_seconds'])],
      ],
    ];
  }

  private static function youtubeLabel(string $value): string
  {
    return self::YOUTUBE_LABELS[$value] ?? ucwords(strtolower(str_replace('_', ' ', $value)));
  }

  /** @param array<string,mixed> $data @param array<string,mixed> $filters */
  private static function youtube(array $data, array $filters, string $granularity): string
  {
    $k = $data['kpis'];
    $p = $data['previous'];
    $c = $data['comparison'];
    $a = $data['account'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = self::account($a, $a['name'] !== '' ? ' · ' . PromotionUi::number($a['subscribers']) . ' suscriptores · ' . PromotionUi::number($a['total_views']) . ' vistas del canal · ' . PromotionUi::number($a['videos']) . ' videos' : '');
    $change = static fn(string $key): string => self::change((float) $k[$key], (float) $p[$key], $c);
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Vistas', PromotionUi::number($k['views']), $change('views'), 'play_circle')
      . PromotionUi::stat('Vistas con interés', PromotionUi::number($k['engaged_views']), $change('engaged_views'), 'visibility')
      . PromotionUi::stat('Horas vistas', self::hours((float) $k['watch_minutes']), $change('watch_minutes'), 'schedule')
      . PromotionUi::stat('Duración promedio', self::duration((float) $k['average_view_duration']), $change('average_view_duration'), 'timer')
      . PromotionUi::stat('Porcentaje visto', self::percent((float) $k['average_view_percentage'], 1), $change('average_view_percentage'), 'percent')
      . PromotionUi::stat('Suscriptores netos', ($k['subscribers_net'] > 0 ? '+' : '') . PromotionUi::number($k['subscribers_net']), $change('subscribers_net'), 'person_add')
      . PromotionUi::stat('Interacciones', PromotionUi::number($k['interactions']), $change('interactions'), 'favorite')
      . PromotionUi::stat('Videos publicados', PromotionUi::number($k['videos_published']), $change('videos_published'), 'movie')
      . '</div>' . self::periodNote($c);
    $series = array_map(static fn(array $row): array => ['watch_hours' => (float) $row['watch_minutes'] / 60] + $row, $data['series']);
    $html .= self::charts($series, $granularity, 'views', 'Vistas', 'vistas', 'watch_hours', 'Horas vistas', 'horas', static fn(array $row): string => 'Suscriptores netos: ' . PromotionUi::number((int) $row['subscribers_gained'] - (int) $row['subscribers_lost']));
    $period = $data['breakdowns_period'];
    $note = $period === null ? 'Sin datos todavía' : ($period['exact'] ? 'Del periodo elegido' : 'Último periodo guardado: ' . PromotionUi::day($period['from']) . ' a ' . PromotionUi::day($period['to']));
    $formats = '';
    foreach ($data['breakdowns']['creator_content_type'] ?? [] as $row) {
      $formats .= '<tr><td>' . $e(self::youtubeLabel($row['label'])) . '</td><td class="is-num">' . $e(PromotionUi::number($row['value'])) . '</td><td class="is-num">' . $e(self::hours($row['watch_minutes'])) . '</td></tr>';
    }
    $html .= '<section class="cpr-card" data-social-formats><div class="cpr-card__head"><div><h2>Rendimiento por formato</h2><p>' . $e($note) . '</p></div></div>' . ($formats !== '' ? '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Formato</th><th class="is-num">Vistas</th><th class="is-num">Horas vistas</th></tr></thead><tbody>' . $formats . '</tbody></table></div>' : '<p class="cpr-empty">Sin distribución por formato.</p>') . '</section>';
    $groups = '';
    foreach (self::YOUTUBE_GROUPS as $type => $title) {
      $rows = array_slice($data['breakdowns'][$type] ?? [], 0, 10);
      $max = max(1.0, ...array_map(static fn(array $row): float => $row['value'], $rows ?: [['value' => 1.0]]));
      $body = '';
      foreach ($rows as $row) {
        $body .= '<div class="cpr-piece-bar"><span>' . $e(self::youtubeLabel($row['label'])) . '</span><strong>' . $e(PromotionUi::number($row['value'])) . '</strong><i style="--bar:' . (int) round($row['value'] * 100 / $max) . '%" aria-hidden="true"></i></div>';
      }
      $groups .= '<div><h3 class="cpr-social-group">' . $e($title) . '</h3>' . ($body !== '' ? $body : '<p class="cpr-empty">Sin datos suficientes.</p>') . '</div>';
    }
    $html .= '<section class="cpr-card" data-social-discovery><div class="cpr-card__head"><div><h2>Descubrimiento y audiencia</h2><p>Cómo encuentran el canal y desde dónde ven los videos. ' . $e($note) . '.</p></div></div><div class="cpr-social-groups">' . $groups . '</div></section>';
    $numbers = static fn(array $video): array => [['Vistas', PromotionUi::number($video['display_views'])], ['Horas', self::hours((float) $video['watch_minutes'])], ['Retención', self::percent((float) $video['average_view_percentage'], 1)]];
    $row = static fn(array $video): string => self::row((string) $video['thumbnail_url'], $video['title'] ?: 'Video sin título', PromotionUi::day((string) $video['published_at'], true) . ' · ' . ($video['content_type'] === 'live' ? 'Transmisión' : 'Video') . ' · ' . self::duration((float) $video['duration_seconds']) . ' · ' . PromotionUi::number((int) $video['likes'] ?: (int) $video['public_likes']) . ' me gusta', $numbers($video), self::detailButton(self::youtubeDetail($video)) . self::viewLink((string) $video['permalink']));
    $top = implode('', array_map($row, $data['top']));
    return $html . '<section class="cpr-card" data-social-top><div class="cpr-card__head"><div><h2>Videos destacados</h2><p>Ordenados por vistas dentro del periodo</p></div></div>' . ($top !== '' ? '<div class="cpr-social-list">' . $top . '</div>' : '<p class="cpr-empty">No hay videos publicados en el rango.</p>') . '</section>'
      . self::monthList('Videos publicados', 'Agrupados por mes con sus indicadores', SocialStatsRepository::months($data['videos'], 'published_at'), $row, static fn(array $items): string => count($items) . ' videos · ' . PromotionUi::number(array_sum(array_column($items, 'display_views'))) . ' vistas', 'No hay videos publicados en el rango.');
  }

  /** @param array<string,mixed> $video @return array<string,mixed> */
  private static function tiktokDetail(array $video): array
  {
    $views = (int) $video['views'];
    $interactions = (int) $video['likes'] + (int) $video['comments'] + (int) $video['shares'];
    return [
      'title' => $video['title'] ?: 'Video de TikTok', 'subtitle' => PromotionUi::day((string) $video['published_at'], true) . ' · Video', 'thumbnail' => self::safeUrl((string) $video['cover_url']), 'description' => mb_substr(trim((string) $video['description']), 0, 700, 'UTF-8'),
      'notice' => 'TikTok entrega acumulados actuales por video; no son métricas históricas por día.', 'link' => self::safeUrl((string) $video['permalink']), 'linkLabel' => 'Ver en TikTok',
      'metrics' => [['Vistas', PromotionUi::number($views)], ['Me gusta', PromotionUi::number((int) $video['likes'])], ['Comentarios', PromotionUi::number((int) $video['comments'])], ['Compartidos', PromotionUi::number((int) $video['shares'])], ['Interacciones', PromotionUi::number($interactions)], ['Interacción / vistas', $views > 0 ? self::percent($interactions * 100 / $views) : 'N/D'], ['Duración', self::duration((float) $video['duration_seconds'])]],
    ];
  }

  /** @param array<string,mixed> $data @param array<string,mixed> $filters */
  private static function tiktok(array $data, array $filters, string $granularity): string
  {
    $k = $data['kpis'];
    $p = $data['previous'];
    $c = $data['comparison'];
    $a = $data['account'];
    $html = self::account($a, $a['name'] !== '' ? ' · ' . PromotionUi::number($a['followers']) . ' seguidores · ' . PromotionUi::number($a['likes']) . ' me gusta · ' . PromotionUi::number($a['videos']) . ' videos en la cuenta' : '');
    $stat = static fn(string $label, string $key, string $icon): string => PromotionUi::stat($label, PromotionUi::number($k[$key]), self::change($k[$key], $p[$key], $c), $icon);
    $html .= '<div class="cpr-stats">' . $stat('Vistas', 'views', 'play_circle') . $stat('Me gusta', 'likes', 'thumb_up') . $stat('Comentarios', 'comments', 'chat') . $stat('Compartidos', 'shares', 'share') . $stat('Interacciones', 'interactions', 'favorite') . $stat('Videos publicados', 'videos', 'movie') . '</div>' . self::periodNote($c);
    $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>Las cifras por video son acumulados actuales de TikTok y se agrupan según la fecha de publicación.</p>';
    $html .= self::charts($data['series'], $granularity, 'views', 'Vistas', 'vistas', 'interactions', 'Interacciones', 'interacciones', static fn(array $row): string => 'Videos: ' . PromotionUi::number((int) $row['videos_published']));
    return $html . self::monthList('Videos publicados', 'Agrupados por mes con sus acumulados actuales', SocialStatsRepository::months($data['videos'], 'published_at'),
      static fn(array $video): string => self::row((string) $video['cover_url'], $video['title'] ?: 'Video sin título', PromotionUi::day((string) $video['published_at'], true) . ' · ' . self::duration((float) $video['duration_seconds']) . ' · ' . PromotionUi::number((int) $video['likes']) . ' me gusta · ' . PromotionUi::number((int) $video['comments']) . ' comentarios', [['Vistas', PromotionUi::number((int) $video['views'])], ['Interacciones', PromotionUi::number((int) $video['likes'] + (int) $video['comments'] + (int) $video['shares'])]], self::detailButton(self::tiktokDetail($video)) . self::viewLink((string) $video['permalink'])),
      static fn(array $items): string => count($items) . ' videos · ' . PromotionUi::number(array_sum(array_map(static fn(array $item): int => (int) $item['views'], $items))) . ' vistas', 'No hay videos publicados en el rango.');
  }
}
