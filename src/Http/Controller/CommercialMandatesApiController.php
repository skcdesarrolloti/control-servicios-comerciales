<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\CommercialMandatesView;

/**
 * Contratos de mandato en el panel: el listado (Mandatos / Mis mandatos) y el popup de un mandato. Valida la vista y el
 * alcance; las acciones (enviar al propietario y responder observaciones) se hacen en la vista interna de mandato.php.
 */
final class CommercialMandatesApiController
{
  private CommercialMandatesService $service;
  private Csrf $csrf;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config, ?CommercialMandatesService $service = null)
  {
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->service = $service ?? new CommercialMandatesService($db, new CommercialAccessPolicy($settings, $db, $adminCargos));
  }

  /** @param array<string,mixed> $input */
  private function guarded(array $input, callable $work): never
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    try {
      JsonResponse::success($work());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\PDOException $exception) {
      throw $exception;
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  /** @param array<string,mixed> $input */
  public function list(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialMandatesView::results($this->service->listing($input))]);
  }

  /** @param array<string,mixed> $input */
  public function view(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $id = (int) ($input['id'] ?? 0);
      $mandate = $this->service->repository()->find($id);
      if ($mandate === null) {
        throw new \InvalidArgumentException('El mandato no existe.');
      }
      if (!$this->service->canOpen($mandate)) {
        throw new \RuntimeException('No tienes permiso para ver este mandato.');
      }
      return ['html' => CommercialMandatesView::viewer($id)];
    });
  }
}
