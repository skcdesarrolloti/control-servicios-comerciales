<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Social\SocialStatsRepository;
use SCM\Core\Database;

/**
 * Promoción › Redes sociales (solo consulta). Adaptación de dashboard-marketing views/social-stats.php con el diseño de
 * Promoción: una pestaña por red, rango de fechas con botón Aplicar, indicadores, gráfico diario, publicaciones o videos
 * destacados y audiencia. La inversión en publicidad solo sale con redes_ver_inversion.
 */
final class SocialView
{
  private const AUDIENCE = ['age' => 'Edad', 'gender' => 'Género', 'city' => 'Ciudades', 'country' => 'Países'];
  private const GENDERS = ['F' => 'Mujeres', 'M' => 'Hombres', 'U' => 'Sin dato'];

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, ?string $today = null): array
  {
    $repository = new SocialStatsRepository($db);
    $filters = $repository->filters($input, $today ?? date('Y-m-d'));
    $spend = $policy->canAct('redes_ver_inversion');
    return ['filters' => $filters, 'can_spend' => $spend, 'data' => $repository->summary($filters, $spend)];
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
    $html .= '<div class="cpr-toolbar"><nav class="cpr-tabs" aria-label="Redes">' . $tabs . '</nav></div>';
    $html .= '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="redes_sociales" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales'])) . '"><input type="hidden" name="pr_red" value="' . PromotionUi::e($network) . '">'
      . '<div class="cpr-filters__grid is-wide">' . self::date('pr_desde', 'Desde', $filters['from']) . self::date('pr_hasta', 'Hasta', $filters['to']) . '</div>'
      . '<div class="cpr-filters__actions"><a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'redes_sociales', 'pr_red' => $network])) . '">Últimos 90 días con datos</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">' . ($filters['latest'] !== '' ? 'Último dato de ' . PromotionUi::e(SocialStatsRepository::NETWORKS[$network]) . ': ' . PromotionUi::e(PromotionUi::day($filters['latest'])) . '.' : 'Todavía no hay datos de esta red.') . ' Hasta 2 años por consulta.</p></form>';
    if (empty($data['available'])) {
      return $html . '<p class="cpr-alert is-error">No se pudieron leer los datos de esta red (tablas marketing_social_*, marketing_youtube_* o marketing_tiktok_*).</p>';
    }
    if ($filters['clamped']) {
      $html .= '<p class="cpr-alert">El rango máximo es de 2 años: se muestran desde el ' . PromotionUi::e(PromotionUi::day($filters['from'])) . '.</p>';
    }
    return $html . match ($network) {
      'instagram', 'facebook' => self::meta($data, $network),
      'publicidad' => self::ads($data),
      'youtube' => self::youtube($data),
      default => self::tiktok($data),
    };
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

  private static function change(int $value): string
  {
    return ($value > 0 ? '+' : '') . PromotionUi::number($value) . ' en el rango';
  }

  /** @param array<string,mixed> $data */
  private static function meta(array $data, string $network): string
  {
    $k = $data['kpis'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = self::account($data['account']);
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Seguidores', PromotionUi::number($k['followers']), self::change($k['followers_change']), 'group')
      . PromotionUi::stat('Alcance', PromotionUi::number($k['reach']), 'Cuentas alcanzadas en el rango', 'visibility')
      . PromotionUi::stat('Interacciones', PromotionUi::number($k['interactions']), 'Me gusta, comentarios, compartidos y guardados', 'favorite')
      . PromotionUi::stat('Clics al sitio web', PromotionUi::number($k['website_clicks']), PromotionUi::number($k['profile_views']) . ' visitas al perfil', 'ads_click')
      . '</div>';
    $html .= PromotionUi::dailyChart('Alcance por día', 'cuentas', $data['daily']);
    $html .= '<div class="cpr-grid">';
    $html .= PromotionUi::tableCard('Publicaciones con más alcance', PromotionUi::number($k['posts']) . ' publicaciones en el rango', [['Publicación'], ['Alcance', true], ['Interacciones', true]], array_map(static fn(array $post): array => [
      self::link($post['title'] !== '' ? $post['title'] : 'Publicación sin texto', $post['url'], PromotionUi::day($post['date']) . ($post['type'] !== '' ? ' · ' . $post['type'] : '')),
      $e(PromotionUi::number($post['reach'])), $e(PromotionUi::number($post['interactions'])),
    ], $data['posts']), 'No hay publicaciones en el rango.');
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
    $html .= '<section class="cpr-card"><div class="cpr-card__head"><div><h2>Audiencia</h2><p>' . ($audience['snapshot'] !== '' ? 'Seguidores según ' . $e(SocialStatsRepository::NETWORKS[$network]) . ' al ' . $e(PromotionUi::day($audience['snapshot'])) : 'Sin datos de audiencia') . '</p></div></div>'
      . ($groups !== '' ? $groups : '<p class="cpr-empty">' . ($network === 'facebook' ? 'Facebook no entrega la audiencia de la página.' : 'Todavía no hay datos de audiencia.') . '</p>') . '</section>';
    return $html . '</div>';
  }

  /** @param array<string,mixed> $data */
  private static function ads(array $data): string
  {
    $k = $data['kpis'];
    $spend = (bool) $data['with_spend'];
    $e = static fn($value): string => PromotionUi::e($value);
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $html = '<div class="cpr-stats">'
      . ($spend ? PromotionUi::stat('Inversión', $money((float) $k['spend']), 'Costo por clic: ' . $money((float) $k['cpc']), 'payments') : '')
      . PromotionUi::stat('Impresiones', PromotionUi::number($k['impressions']), 'Veces que se mostraron los anuncios', 'visibility')
      . PromotionUi::stat('Alcance', PromotionUi::number($k['reach']), 'Suma diaria de personas alcanzadas', 'groups')
      . PromotionUi::stat('Clics', PromotionUi::number($k['clicks']), PromotionUi::number($k['link_clicks']) . ' en enlaces', 'ads_click')
      . '</div>';
    if (!$spend) {
      $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">lock</span>La inversión, el costo por clic y el CPM solo los ve quien tiene el permiso.</p>';
    }
    $html .= PromotionUi::dailyChart('Impresiones por día', 'impresiones', $data['daily']);
    $headers = [['Campaña'], ['Impresiones', true], ['Clics', true], ['CTR', true]];
    if ($spend) {
      array_push($headers, ['Inversión', true], ['CPC', true]);
    }
    return $html . PromotionUi::tableCard('Campañas de publicidad', 'Últimos datos de cada campaña en Meta', $headers, array_map(static function (array $row) use ($spend, $e, $money): array {
      $cells = ['<span class="cpr-cell-main">' . $e($row['name']) . '</span><span class="cpr-cell-sub">' . $e(trim($row['status'] . ' · ' . $row['objective'] . ' · Acumulado del ' . PromotionUi::day($row['since']) . ' al ' . PromotionUi::day($row['date']), ' ·')) . '</span>',
        $e(PromotionUi::number($row['impressions'])), $e(PromotionUi::number($row['clicks'])), $e(PromotionUi::percent((float) $row['ctr']))];
      if ($spend) {
        array_push($cells, $e($money((float) $row['spend'])), $e($money((float) $row['cpc'])));
      }
      return $cells;
    }, $data['campaigns']), 'Todavía no hay campañas de publicidad sincronizadas.');
  }

  /** @param array<string,mixed> $data */
  private static function youtube(array $data): string
  {
    $k = $data['kpis'];
    $a = $data['account'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = self::account($a, $a['name'] !== '' ? ' · ' . PromotionUi::number($a['subscribers']) . ' suscriptores · ' . PromotionUi::number($a['videos']) . ' videos' : '');
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Vistas', PromotionUi::number($k['views']), 'En el rango', 'play_circle')
      . PromotionUi::stat('Horas vistas', PromotionUi::number((int) round($k['watch_minutes'] / 60)), PromotionUi::number($k['watch_minutes']) . ' minutos', 'schedule')
      . PromotionUi::stat('Suscriptores nuevos', ($k['subscribers_net'] > 0 ? '+' : '') . PromotionUi::number($k['subscribers_net']), 'Ganados menos perdidos', 'person_add')
      . PromotionUi::stat('Interacciones', PromotionUi::number($k['interactions']), 'Me gusta, comentarios y compartidos', 'favorite')
      . '</div>';
    $html .= PromotionUi::dailyChart('Vistas por día', 'vistas', $data['daily']);
    return $html . PromotionUi::tableCard('Videos con más vistas', 'Publicados en el rango', [['Video'], ['Vistas', true], ['Minutos vistos', true], ['Interacciones', true]], array_map(static fn(array $video): array => [
      self::link($video['title'], $video['url'], PromotionUi::day($video['date']) . ($video['type'] !== '' ? ' · ' . $video['type'] : '')),
      $e(PromotionUi::number($video['views'])), $e(PromotionUi::number($video['watch_minutes'])), $e(PromotionUi::number($video['interactions'])),
    ], $data['videos']), 'No hay videos publicados en el rango.');
  }

  /** @param array<string,mixed> $data */
  private static function tiktok(array $data): string
  {
    $k = $data['kpis'];
    $a = $data['account'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = self::account($a);
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Seguidores', PromotionUi::number($a['followers'] ?? 0), PromotionUi::number($a['likes'] ?? 0) . ' me gusta en total', 'group')
      . PromotionUi::stat('Videos en el rango', PromotionUi::number($k['videos']), 'De ' . PromotionUi::number($a['videos'] ?? 0) . ' en la cuenta', 'movie')
      . PromotionUi::stat('Vistas', PromotionUi::number($k['views']), 'De los videos del rango', 'play_circle')
      . PromotionUi::stat('Interacciones', PromotionUi::number($k['interactions']), 'Me gusta, comentarios y compartidos', 'favorite')
      . '</div>';
    return $html . PromotionUi::tableCard('Videos con más vistas', 'Publicados en el rango', [['Video'], ['Vistas', true], ['Interacciones', true]], array_map(static fn(array $video): array => [
      self::link($video['title'] !== '' ? $video['title'] : 'Video sin título', $video['url'], PromotionUi::day($video['date'])),
      $e(PromotionUi::number($video['views'])), $e(PromotionUi::number($video['interactions'])),
    ], $data['videos']), 'No hay videos publicados en el rango.');
  }

  private static function link(string $title, string $url, string $sub): string
  {
    $title = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $title) ?? $title), 0, 90, '…', 'UTF-8');
    $safe = preg_match('#^https://#i', $url) === 1;
    return '<span class="cpr-cell-main">' . ($safe ? '<a href="' . PromotionUi::e($url) . '" target="_blank" rel="noopener noreferrer">' . PromotionUi::e($title) . '</a>' : PromotionUi::e($title)) . '</span>'
      . '<span class="cpr-cell-sub">' . PromotionUi::e($sub) . '</span>';
  }
}
