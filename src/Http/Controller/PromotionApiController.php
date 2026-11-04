<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\Promotion\AnalyticsView;

/**
 * Endpoints de la pestaña Promoción (CommercialActionRouter los despacha aquí para no seguir creciendo
 * CommercialApiController). Cada uno valida el nonce y la pestaña y subpestaña (canView/canSubview). IP y navegador solo
 * salen con promocion_ver_ip: sin el permiso, Registros los lee únicamente para reconocer robots y visitas internas y nunca
 * los devuelve (TrackerAnalyticsRepository::logRow); Actividad de portales ni siquiera los lee.
 */
final class PromotionApiController
{
  private Database $db;
  private Settings $settings;
  private Csrf $csrf;
  private CommercialAccessPolicy $policy;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
  }

  /**
   * Analítica web › Registros: una página de visitas crudas (hasta 90 días, 25 filas, paginación por llave).
   * @param array<string,mixed> $input pr_desde, pr_hasta, pr_campana, pr_medio (medio de la lista única), cursor.
   */
  public function analyticsLogs(array $input): never
  {
    $this->guard($input, 'analitica_web', 'Analítica web');
    $filters = TrackerAnalyticsFilters::fromInput(AnalyticsView::filterInput($input), TrackerAnalyticsConfig::read($this->settings));
    $cursor = is_scalar($input['cursor'] ?? null) ? trim((string) $input['cursor']) : '';
    try {
      $page = (new TrackerAnalyticsRepository($this->db, $this->settings))->logs($filters, $cursor !== '' ? $cursor : null, $this->policy->canAct('promocion_ver_ip'));
    } catch (\Throwable $exception) {
      error_log('[commercial-promotion-analytics] ' . $exception->getMessage());
      JsonResponse::error('No se pudieron consultar los registros de visitas. Intenta de nuevo.', 500);
    }
    JsonResponse::success($page + ['page_size' => TrackerAnalyticsRepository::LOG_PAGE_SIZE]);
  }

  /** @param array<string,mixed> $input */
  private function guard(array $input, string $sub, string $label): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canView('promocion') || !$this->policy->canSubview('promocion', $sub)) {
      JsonResponse::error('No tienes permiso para entrar a ' . $label . '.', 403);
    }
  }
}
