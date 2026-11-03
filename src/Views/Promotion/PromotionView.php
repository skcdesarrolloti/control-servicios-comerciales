<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\MeetingService;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Pestaña Promoción (oculta por defecto: OPT_IN_VIEWS). Cada subpestaña de SUBVIEWS['promocion'] es su propia pestaña
 * (?tab=reuniones) y se pinta aquí. Sus estilos y su JS se piden solo cuando se abre esta pestaña.
 */
final class PromotionView
{
  public const ASSET_CSS = '/assets/css/commercial-promotion.css';
  public const ASSET_JS = '/assets/js/commercial-promotion.js';

  /**
   * Datos de la subpestaña ya filtrados por permiso y alcance: lo que el cargo no puede ver no llega a la vista.
   * @param array<string,mixed> $input Entrada normalizada (promocion_sub, reunion, modo, reunion_q, reunion_estado).
   * @return array<string,mixed>
   */
  public static function context(string $sub, array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings): array
  {
    if ($sub !== 'reuniones' || !$policy->canSubview('promocion', 'reuniones')) {
      return ['sub' => ''];
    }
    $service = new MeetingService($db, $settings, $policy);
    $manage = $policy->canAct('reuniones_gestionar');
    $context = [
      'sub' => 'reuniones',
      'can' => [
        'manage' => $manage,
        'reopen' => $manage && $policy->canAct('reuniones_reabrir'),
        'delete' => $manage && $policy->canAct('reuniones_eliminar'),
        'team' => $policy->canSeeAll('promocion'),
      ],
      'filters' => [
        'q' => mb_substr(trim(is_scalar($input['reunion_q'] ?? null) ? (string) $input['reunion_q'] : ''), 0, 120),
        'status' => trim(is_scalar($input['reunion_estado'] ?? null) ? (string) $input['reunion_estado'] : ''),
      ],
      'employees' => $manage ? $service->employees() : [],
    ];
    $id = max(0, (int) ($input['reunion'] ?? 0));
    if ($id > 0) {
      $meeting = $service->find($id);
      $context['screen'] = $meeting === [] ? 'missing' : ((string) ($input['modo'] ?? '') === 'guia' ? 'guide' : 'detail');
      $context['meeting'] = $meeting;
      return $context;
    }
    $context['screen'] = 'list';
    $context['dashboard'] = $service->dashboard($context['filters']);
    return $context;
  }

  /** Contexto y vista en un paso, para la carga inicial y la navegación por AJAX. @param array<string,mixed> $input */
  public static function page(array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, string $baseUrl): string
  {
    $sub = trim((string) ($input['promocion_sub'] ?? ''));
    return self::render($sub, self::context($sub, $input, $policy, $db, $settings), $baseUrl);
  }

  /** @param array<string,mixed> $context */
  public static function render(string $sub, array $context, string $baseUrl): string
  {
    $baseUrl = rtrim($baseUrl, '/');
    $body = ($context['sub'] ?? '') === 'reuniones' && $sub === 'reuniones'
      ? MeetingsView::render($context, $baseUrl)
      : '<p class="cpr-empty">Esta sección de Promoción no está disponible.</p>';
    $css = self::assetUrl($baseUrl, self::ASSET_CSS);
    $js = self::assetUrl($baseUrl, self::ASSET_JS);
    // El <link> carga los estilos también cuando el panel llega por AJAX; commercial-dashboard.js carga el script una vez.
    return '<link rel="stylesheet" href="' . self::e($css) . '" data-commercial-module-style="promocion">'
      . '<section class="cpr" data-commercial-promotion="' . self::e($sub) . '" data-commercial-module-script="' . self::e($js) . '">' . $body . '</section>';
  }

  public static function assetUrl(string $baseUrl, string $path): string
  {
    $file = (defined('SCM_PUBLIC_PATH') ? (string) SCM_PUBLIC_PATH : dirname(__DIR__, 3) . '/public') . $path;
    $version = is_file($file) ? substr((string) hash_file('sha256', $file), 0, 16) : 'dev';
    return rtrim($baseUrl, '/') . $path . '?v=' . $version;
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
