<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Commercial\Promotion\Pieces\BrandAssetFiles;
use SCM\Commercial\Promotion\Pieces\BrandAssetService;
use SCM\Commercial\Promotion\Pop\PopInventoryService;
use SCM\Commercial\Promotion\Social\SocialStatsRepository;
use SCM\Commercial\Promotion\Social\SocialSyncRunner;
use SCM\Core\Auth;
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
 * Material POP: crear, editar, ajustar, entregar y sincronizar piden pop_gestionar; archivar, reactivar y anular piden
 * pop_anular; el precio solo se guarda con pop_ver_costos. Banco de piezas: subir, editar y vincular piden piezas_gestionar;
 * archivar y restaurar, piezas_archivar.
 */
final class PromotionApiController
{
  private Database $db;
  private Settings $settings;
  private Csrf $csrf;
  private CommercialAccessPolicy $policy;
  /** @var array<string,mixed> */
  private array $config;
  /** @var array<string,\Closure(string,string):array<string,mixed>> */
  private array $socialSyncers;

  /**
   * @param array<string,mixed> $config
   * @param array<string,\Closure(string,string):array<string,mixed>> $socialSyncers Solo para las pruebas: no salen a internet.
   */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config, array $socialSyncers = [])
  {
    $this->config = $config;
    $this->socialSyncers = $socialSyncers;
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
  public function popProductSave(array $input): never
  {
    $this->popGuard($input, 'pop_gestionar');
    $this->pop(function (PopInventoryService $service) use ($input): string {
      $service->saveProduct($input, $this->employee(), $this->policy->canAct('pop_ver_costos'));
      return (int) ($input['id'] ?? 0) > 0 ? 'Producto actualizado.' : 'Producto creado.';
    });
  }

  /** @param array<string,mixed> $input */
  public function popStockAdjust(array $input): never
  {
    $this->popGuard($input, 'pop_gestionar');
    $this->pop(function (PopInventoryService $service) use ($input): string {
      $service->adjustStock((int) ($input['product_id'] ?? 0), (int) ($input['delta'] ?? 0), (string) ($input['reason'] ?? ''), $this->employee());
      return 'Existencias ajustadas.';
    });
  }

  /** Archivar (active=0) o reactivar (active=1) con motivo y confirmación. @param array<string,mixed> $input */
  public function popProductActive(array $input): never
  {
    $this->popGuard($input, 'pop_anular');
    $active = filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $this->confirmed($input);
    $this->pop(function (PopInventoryService $service) use ($input, $active): string {
      $service->setActive((int) ($input['product_id'] ?? 0), $active, (string) ($input['reason'] ?? ''), $this->employee());
      return $active ? 'Producto reactivado.' : 'Producto archivado. Su historial se conserva.';
    });
  }

  /** @param array<string,mixed> $input */
  public function popDeliveryCreate(array $input): never
  {
    $this->popGuard($input, 'pop_gestionar');
    $this->pop(function (PopInventoryService $service) use ($input): string {
      $service->createDelivery($input, $this->employee());
      return 'Entrega registrada y existencias descontadas.';
    });
  }

  /** @param array<string,mixed> $input */
  public function popDeliveryCancel(array $input): never
  {
    $this->popGuard($input, 'pop_anular');
    $this->confirmed($input);
    $this->pop(function (PopInventoryService $service) use ($input): string {
      $service->cancelDelivery((int) ($input['delivery_id'] ?? 0), (string) ($input['reason'] ?? ''), $this->employee());
      return 'Entrega anulada y productos repuestos.';
    });
  }

  /** @param array<string,mixed> $input */
  public function popSync(array $input): never
  {
    $this->popGuard($input, 'pop_gestionar');
    $this->pop(function (PopInventoryService $service): string {
      $summary = $service->syncFromPph($this->employee());
      return array_sum($summary) === 0 ? 'El Club PPH ya estaba al día.'
        : 'Club PPH sincronizado: ' . $summary['products_created'] . ' productos nuevos, ' . $summary['products_updated'] . ' actualizados y ' . $summary['deliveries_imported'] . ' entregas importadas.';
    });
  }

  /** Trae el rango que se está viendo (o los últimos días si no llega) de la red de la pestaña. @param array<string,mixed> $input */
  public function socialSync(array $input): never
  {
    $this->socialGuard($input);
    $network = (string) ($input['network'] ?? '');
    @set_time_limit(280);
    try {
      [$from, $to] = $this->socialRange($input);
      $result = $this->socialRunner()->run($network, $from, $to);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 409);
    }
    $item = $result['results'][$network];
    if (!$item['ok']) {
      JsonResponse::error($item['message'], 502);
    }
    JsonResponse::success(['message' => 'Sincronización terminada.', 'warnings' => $item['warnings']]);
  }

  /** @param array<string,mixed> $input */
  public function socialDisconnect(array $input): never
  {
    $this->socialGuard($input);
    $this->confirmed($input);
    try {
      $this->socialRunner()->disconnect((string) ($input['network'] ?? ''));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'Cuenta desconectada. Las estadísticas guardadas se conservan.']);
  }

  /** Crear o editar una pieza; el arte final llega en asset_file. @param array<string,mixed> $input */
  public function pieceSave(array $input): never
  {
    $this->pieceGuard($input, 'piezas_gestionar');
    $file = is_array($_FILES['asset_file'] ?? null) ? $_FILES['asset_file'] : [];
    $this->piece(function (BrandAssetService $service) use ($input, $file): array {
      $service->save($input, $file, (int) $this->employee());
      return ['message' => (int) ($input['id'] ?? 0) > 0 ? 'Pieza actualizada.' : 'Pieza guardada en el banco.'];
    });
  }

  /** Archivar (active=0) o restaurar (active=1) con confirmación: el archivo y sus vínculos se conservan. @param array<string,mixed> $input */
  public function pieceActive(array $input): never
  {
    $this->pieceGuard($input, 'piezas_archivar');
    $this->confirmed($input);
    $active = filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $this->piece(function (BrandAssetService $service) use ($input, $active): array {
      $service->setActive((int) ($input['id'] ?? 0), $active, (int) $this->employee());
      return ['message' => $active ? 'Pieza restaurada.' : 'Pieza archivada. Puedes restaurarla después.'];
    });
  }

  /** Publicaciones de Meta para la ventana de vincular. @param array<string,mixed> $input */
  public function piecePosts(array $input): never
  {
    $this->pieceGuard($input, 'piezas_gestionar');
    $this->piece(static fn(BrandAssetService $service): array => ['posts' => $service->socialPosts()]);
  }

  /** post_ids: lista JSON con todas las publicaciones de la pieza; reemplaza las anteriores. @param array<string,mixed> $input */
  public function pieceLinks(array $input): never
  {
    $this->pieceGuard($input, 'piezas_gestionar');
    $ids = json_decode((string) ($input['post_ids'] ?? '[]'), true);
    $this->piece(function (BrandAssetService $service) use ($input, $ids): array {
      $count = $service->replacePostLinks((int) ($input['id'] ?? 0), is_array($ids) ? array_values($ids) : [], (int) $this->employee());
      return ['message' => $count === 0 ? 'La pieza quedó sin publicaciones vinculadas.' : ($count === 1 ? '1 publicación vinculada.' : $count . ' publicaciones vinculadas.')];
    });
  }

  /** @param array<string,mixed> $input */
  private function pieceGuard(array $input, string $action): void
  {
    $this->guard($input, 'banco_piezas', 'Banco de piezas');
    if (!$this->policy->canAct($action)) {
      JsonResponse::error('No tienes permiso para esta acción del Banco de piezas.', 403);
    }
  }

  /** @param callable(BrandAssetService):array<string,mixed> $operation */
  private function piece(callable $operation): never
  {
    $service = new BrandAssetService($this->db, BrandAssetFiles::runtime());
    if (!$service->available()) {
      JsonResponse::error('Las tablas del Banco de piezas no están disponibles.', 409);
    }
    try {
      $data = $operation($service);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log('[commercial-pieces] ' . $exception->getMessage());
      JsonResponse::error('No se pudo guardar el cambio en el Banco de piezas. Intenta de nuevo.', 500);
    }
    JsonResponse::success($data);
  }

  /** @param array<string,mixed> $input @return array{0:string,1:string} */
  private function socialRange(array $input): array
  {
    $date = static function ($value): string {
      $text = is_scalar($value) ? trim((string) $value) : '';
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
      return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $text ? $text : '';
    };
    $from = $date($input['from'] ?? '');
    $to = $date($input['to'] ?? '');
    if ($from === '' || $to === '') {
      return [date('Y-m-d', strtotime('-' . SocialSyncRunner::DEFAULT_DAYS . ' days')), date('Y-m-d')];
    }
    if ($from > $to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days >= SocialStatsRepository::MAX_DAYS) {
      throw new \InvalidArgumentException('Elige un rango válido de máximo 2 años para sincronizar.');
    }
    return [$from, $to];
  }

  /** @param array<string,mixed> $input */
  private function socialGuard(array $input): void
  {
    $this->guard($input, 'redes_sociales', 'Redes sociales');
    if (!$this->policy->canAct('redes_sincronizar')) {
      JsonResponse::error('No tienes permiso para sincronizar las redes sociales.', 403);
    }
  }

  private function socialRunner(): SocialSyncRunner
  {
    $directory = defined('SCM_STORAGE_PATH') ? (string) constant('SCM_STORAGE_PATH') . '/cache' : sys_get_temp_dir();
    return new SocialSyncRunner($this->db, $this->config, $directory . '/social-sync.lock', $this->socialSyncers);
  }

  /** @param callable(PopInventoryService):string $operation */
  private function pop(callable $operation): never
  {
    $service = new PopInventoryService($this->db, $this->settings);
    if (!$service->available()) {
      JsonResponse::error('Las tablas del inventario no están disponibles.', 409);
    }
    try {
      $message = $operation($service);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log('[commercial-material-pop] ' . $exception->getMessage());
      JsonResponse::error('No se pudo guardar el cambio en el inventario. Intenta de nuevo.', 500);
    }
    JsonResponse::success(['message' => $message]);
  }

  /** @param array<string,mixed> $input */
  private function popGuard(array $input, string $action): void
  {
    $this->guard($input, 'material_pop', 'Material POP');
    if (!$this->policy->canAct($action)) {
      JsonResponse::error('No tienes permiso para esta acción de Material POP.', 403);
    }
  }

  /** Las acciones destructivas llegan con la casilla de confirmación marcada. @param array<string,mixed> $input */
  private function confirmed(array $input): void
  {
    if (!filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
      JsonResponse::error('Confirma la acción marcando la casilla.', 422);
    }
  }

  /** id_empleado real del funcionario (created_by, cct_author_id). */
  private function employee(): string
  {
    $employee = trim(Auth::employeeId());
    return $employee !== '' ? $employee : (string) Auth::userId();
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
