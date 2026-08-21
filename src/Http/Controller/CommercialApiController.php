<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;

final class CommercialApiController
{
  private CommercialTicketsRepository $tickets;
  private CommercialAccessPolicy $policy;
  private Csrf $csrf;
  /** @var array<int,string> */
  private array $commercialCargos;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->tickets = new CommercialTicketsRepository($db);
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->commercialCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? array_values(array_map('strval', $config['calendar_allowed_cargos'])) : ['9', '10', '17'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
  }

  /** @param array<string,mixed> $input */
  public function changeStatus(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canAct('cambiar_estado')) {
      JsonResponse::error('No tienes permiso para cambiar estados comerciales.', 403);
    }
    try {
      $this->tickets->changeStatus((int) ($input['ticket_pk'] ?? 0), trim((string) ($input['estado'] ?? '')));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'El estado comercial fue actualizado.']);
  }

  /** @param array<string,mixed> $input */
  public function reassign(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canAct('reasignar')) {
      JsonResponse::error('No tienes permiso para reasignar tickets.', 403);
    }
    try {
      $this->tickets->reassign((int) ($input['ticket_pk'] ?? 0), trim((string) ($input['id_empleado'] ?? '')), $this->commercialCargos);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'El responsable comercial fue actualizado.']);
  }

  /** @param array<string,mixed> $input */
  public function savePermissions(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canManage()) {
      JsonResponse::error('No tienes permiso para cambiar esta configuración.', 403);
    }
    $permissions = $input['permissions'] ?? [];
    $this->policy->save(is_array($permissions) ? $permissions : []);
    JsonResponse::success(['message' => 'Configuración guardada.']);
  }

  /** @param array<string,mixed> $input */
  private function verify(array $input): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
  }
}
