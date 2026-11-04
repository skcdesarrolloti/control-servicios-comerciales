<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\VisitorHasher;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Pestaña Promoción (oculta por defecto: OPT_IN_VIEWS). Cada subpestaña de SUBVIEWS['promocion'] es su propia pestaña
 * (?tab=analitica_web, ?tab=actividad_portales, ?tab=material_pop) y se pinta aquí. Sus estilos y su JS se piden solo cuando se abre esta
 * pestaña. Los datos que el cargo no puede ver (IP y navegador sin promocion_ver_ip) no llegan a la vista.
 */
final class PromotionView
{
  public const ASSET_CSS = '/assets/css/commercial-promotion.css';
  public const ASSET_JS = '/assets/js/commercial-promotion.js';

  /**
   * Contexto y vista en un paso, para la carga inicial y la navegación por AJAX.
   * @param array<string,mixed> $input Entrada normalizada (promocion_sub y los filtros pr_*).
   * @param TrackerRollup|false|null $rollup Puesta al día de Analítica web; null = la del panel (APP_SECRET), false = ninguna.
   * @param bool $catchUp false en la carga completa (index.php, con la sesión de PHP abierta): solo lee el estado y la
   *   pantalla pide la puesta al día por AJAX. true por AJAX (api.php ya cerró la sesión).
   */
  public static function page(array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, string $baseUrl, TrackerRollup|false|null $rollup = null, ?string $today = null, bool $catchUp = true): string
  {
    $sub = trim((string) ($input['promocion_sub'] ?? ''));
    $baseUrl = rtrim($baseUrl, '/');
    if ($sub === 'analitica_web' && $policy->canSubview('promocion', 'analitica_web')) {
      $body = AnalyticsView::render(AnalyticsView::context($input, $policy, $db, $settings, $rollup === null ? self::rollup($db, $settings) : ($rollup ?: null), $today, $catchUp), $baseUrl);
    } elseif ($sub === 'actividad_portales' && $policy->canSubview('promocion', 'actividad_portales')) {
      $body = PortalActivityView::render(PortalActivityView::context($input, $policy, $db, $today), $baseUrl);
    } elseif ($sub === 'redes_sociales' && $policy->canSubview('promocion', 'redes_sociales')) {
      $body = SocialView::render(SocialView::context($input, $policy, $db, $today), $baseUrl);
    } elseif ($sub === 'material_pop' && $policy->canSubview('promocion', 'material_pop')) {
      $body = MaterialPopView::render(MaterialPopView::context($input, $policy, $db, $settings), $baseUrl);
    } else {
      $sub = '';
      $body = '<p class="cpr-empty">Esta sección de Promoción no está disponible.</p>';
    }
    return self::wrap($sub, $body, $baseUrl);
  }

  /** Rollup del panel para la puesta al día acotada al abrir Analítica web; sin secreto válido no se resume nada. */
  private static function rollup(Database $db, Settings $settings): ?TrackerRollup
  {
    $secret = defined('SCM_APP_SECRET') ? (string) constant('SCM_APP_SECRET') : '';
    try {
      return new TrackerRollup($db, $settings, new VisitorHasher($secret));
    } catch (\InvalidArgumentException) {
      return null;
    }
  }

  public static function wrap(string $sub, string $body, string $baseUrl): string
  {
    $css = self::assetUrl($baseUrl, self::ASSET_CSS);
    $js = self::assetUrl($baseUrl, self::ASSET_JS);
    // El <link> carga los estilos también cuando el panel llega por AJAX; commercial-dashboard.js carga el script una vez.
    return '<link rel="stylesheet" href="' . PromotionUi::e($css) . '" data-commercial-module-style="promocion">'
      . '<section class="cpr" data-commercial-promotion="' . PromotionUi::e($sub) . '" data-commercial-module-script="' . PromotionUi::e($js) . '">' . $body . '</section>';
  }

  public static function assetUrl(string $baseUrl, string $path): string
  {
    $file = (defined('SCM_PUBLIC_PATH') ? (string) constant('SCM_PUBLIC_PATH') : dirname(__DIR__, 3) . '/public') . $path;
    $version = is_file($file) ? substr((string) hash_file('sha256', $file), 0, 16) : 'dev';
    return rtrim($baseUrl, '/') . $path . '?v=' . $version;
  }
}
