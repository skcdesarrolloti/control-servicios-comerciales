<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\MeetingNotFound;
use SCM\Commercial\Promotion\MeetingService;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\Promotion\MeetingsView;

/**
 * Endpoints de la pestaña Promoción (CommercialActionRouter los despacha aquí para no seguir creciendo
 * CommercialApiController). Cada uno valida el nonce, la pestaña y la subpestaña (canView/canSubview) y la acción
 * (canAct); el alcance por funcionario lo aplica MeetingService al buscar la reunión.
 */
final class PromotionApiController
{
  private Database $db;
  private Settings $settings;
  private Csrf $csrf;
  private CommercialAccessPolicy $policy;
  private string $baseUrl;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->baseUrl = rtrim(defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : (string) ($config['base_url'] ?? ''), '/');
  }

  /** @param array<string,mixed> $input */
  public function meetingSave(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', function (MeetingService $service) use ($input): array {
      $isNew = (int) ($input['id'] ?? 0) <= 0;
      $id = $service->saveMeeting($input);
      return [
        'message' => $isNew ? 'La reunión quedó creada.' : 'Los datos de la reunión se actualizaron.',
        'url' => MeetingsView::url($this->baseUrl, ['reunion' => $id]),
        'id' => $id,
      ];
    });
  }

  /** @param array<string,mixed> $input */
  public function itemSave(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', static function (MeetingService $service) use ($input): array {
      $service->saveItem($input);
      return ['message' => 'El punto quedó guardado.'];
    });
  }

  /** @param array<string,mixed> $input */
  public function itemMove(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', static function (MeetingService $service) use ($input): array {
      $service->moveItem((int) ($input['item_id'] ?? 0), trim((string) ($input['direction'] ?? '')));
      return ['message' => 'El orden quedó guardado.'];
    });
  }

  /** @param array<string,mixed> $input */
  public function itemArchive(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', static function (MeetingService $service) use ($input): array {
      $service->archiveItem((int) ($input['item_id'] ?? 0));
      return ['message' => 'El punto fue archivado sin borrar su historial.'];
    });
  }

  /** Cambia el estado de una acción sin recargar: devuelve solo el estado, el motivo y el avance. @param array<string,mixed> $input */
  public function actionStatus(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', static function (MeetingService $service) use ($input): array {
      $result = $service->updateActionStatus((int) ($input['item_id'] ?? 0), trim((string) ($input['status'] ?? '')), (string) ($input['reason'] ?? ''));
      $item = $result['item'];
      return [
        'message' => 'Acción actualizada: ' . (MeetingsView::ACTION_STATUSES[(string) ($item['status'] ?? '')] ?? '') . '.',
        'item' => ['id' => (int) ($item['id'] ?? 0), 'status' => (string) ($item['status'] ?? ''), 'failure_reason' => (string) ($item['failure_reason'] ?? '')],
        'progress' => $result['progress'],
      ];
    });
  }

  /** @param array<string,mixed> $input */
  public function meetingStart(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', function (MeetingService $service) use ($input): array {
      $id = (int) ($input['meeting_id'] ?? 0);
      $service->start($id);
      return ['message' => 'La reunión está en curso.', 'url' => MeetingsView::url($this->baseUrl, ['reunion' => $id, 'modo' => 'guia'])];
    });
  }

  /** @param array<string,mixed> $input */
  public function meetingComplete(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', function (MeetingService $service) use ($input): array {
      $id = (int) ($input['meeting_id'] ?? 0);
      $service->complete($id, (string) ($input['conclusions'] ?? ''));
      return ['message' => 'La reunión fue finalizada y quedó bloqueada.', 'url' => MeetingsView::url($this->baseUrl, ['reunion' => $id])];
    });
  }

  /** @param array<string,mixed> $input */
  public function meetingReopen(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_reabrir', static function (MeetingService $service) use ($input): array {
      $service->reopen((int) ($input['meeting_id'] ?? 0));
      return ['message' => 'La reunión fue reabierta.'];
    });
  }

  /** @param array<string,mixed> $input */
  public function meetingArchive(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_gestionar', function (MeetingService $service) use ($input): array {
      $service->archive((int) ($input['meeting_id'] ?? 0));
      return ['message' => 'La reunión fue archivada; su historial se conserva.', 'url' => MeetingsView::url($this->baseUrl, [])];
    });
  }

  /** Borrado lógico con rastro (deleted_at, deleted_by con el id_empleado y el estado anterior). @param array<string,mixed> $input */
  public function meetingDelete(array $input): never
  {
    $this->meetingRequest($input, 'reuniones_eliminar', function (MeetingService $service) use ($input): array {
      $service->delete((int) ($input['meeting_id'] ?? 0));
      return ['message' => 'La reunión fue eliminada. Quedó registrado quién la eliminó y cuándo.', 'url' => MeetingsView::url($this->baseUrl, [])];
    });
  }

  /**
   * @param array<string,mixed> $input
   * @param callable(MeetingService):array<string,mixed> $work
   */
  private function meetingRequest(array $input, string $action, callable $work): never
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canSubview('promocion', 'reuniones')) {
      JsonResponse::error('No tienes permiso para entrar a Reuniones.', 403);
    }
    // Reabrir y eliminar exigen además gestionar reuniones, como en el original.
    if (!$this->policy->canAct('reuniones_gestionar') || !$this->policy->canAct($action)) {
      JsonResponse::error($action === 'reuniones_reabrir'
        ? 'No tienes permiso para reabrir reuniones.'
        : ($action === 'reuniones_eliminar' ? 'No tienes permiso para eliminar reuniones.' : 'No tienes permiso para modificar reuniones.'), 403);
    }
    try {
      $result = $work(new MeetingService($this->db, $this->settings, $this->policy));
    } catch (MeetingNotFound $exception) {
      JsonResponse::error($exception->getMessage(), 404);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      // Los errores de base de datos no se muestran: se registran y responden 500.
      if ($exception instanceof \PDOException || str_starts_with($exception->getMessage(), 'SQL ')) {
        $this->fail($exception);
      }
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      $this->fail($exception);
    }
    JsonResponse::success($result);
  }

  private function fail(\Throwable $exception): never
  {
    error_log('[commercial-promotion-meetings] ' . $exception->getMessage());
    JsonResponse::error('No se pudo completar la acción en Reuniones. Intenta de nuevo.', 500);
  }
}
