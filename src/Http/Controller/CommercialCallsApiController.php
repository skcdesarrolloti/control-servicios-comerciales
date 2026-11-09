<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\Calls\CommercialCallsService;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\CommercialCallsView;

/** Actividades comerciales › Llamadas: cada endpoint valida el nonce y la pestaña; el servicio valida acción y alcance. */
final class CommercialCallsApiController
{
  private CommercialAccessPolicy $policy;
  private CommercialCallsService $service;
  private Csrf $csrf;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->service = new CommercialCallsService($db, $settings, $this->policy, $config);
  }

  /** @param array<string,mixed> $input */
  private function guarded(array $input, callable $work): never
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canView(CommercialCallsService::VIEW)) {
      JsonResponse::error('No tienes permiso para entrar a Llamadas.', 403);
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
    $this->guarded($input, fn(): array => ['html' => CommercialCallsView::results($this->service->listing($input), $this->service, $this->policy)]);
  }

  /** @param array<string,mixed> $input */
  public function form(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialCallsView::form($this->service->formData($input), $this->service->options())]);
  }

  /** @param array<string,mixed> $input */
  public function save(array $input): never
  {
    $this->guarded($input, fn(): array => $this->service->save($input));
  }

  /** @param array<string,mixed> $input */
  public function taskForm(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialCallsView::taskForm($this->service->taskData((int) ($input['id'] ?? 0)), $this->service->options())]);
  }

  /** @param array<string,mixed> $input */
  public function taskSave(array $input): never
  {
    $this->guarded($input, fn(): array => $this->service->createTask($input));
  }

  /** @param array<string,mixed> $input */
  public function history(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $history = $this->service->history($input);
      return ['total' => count($history['items']), 'contact' => $history['contact'], 'html' => CommercialCallsView::history($history, $this->policy)];
    });
  }

  /** @param array<string,mixed> $input */
  public function card(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      if (!$this->policy->canAct('llamadas_crear') && !$this->policy->canAct('llamadas_editar')) {
        throw new \RuntimeException('No tienes permiso para consultar aliados.');
      }
      return $this->service->resolveCard(is_scalar($input['tarjeta'] ?? null) ? (string) $input['tarjeta'] : '');
    });
  }
}
