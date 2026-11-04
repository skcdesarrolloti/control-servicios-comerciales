<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Strategy\StrategicPlanRepository;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;

/**
 * Endpoints de Herramientas › Plan estratégico (CommercialActionRouter::STRATEGY_ACTIONS). Todos validan el nonce y la
 * subpestaña; crear, editar y eliminar exigen plan_estrategico_editar. Registrar un avance o un resultado lo puede hacer
 * también quien figura como responsable de la tarea o la campaña. El plan no tiene datos reservados: lo que se lista lo
 * puede ver cualquiera con la subpestaña.
 */
final class StrategicPlanApiController
{
  private Csrf $csrf;
  private CommercialAccessPolicy $policy;
  private StrategicPlanRepository $plan;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->plan = new StrategicPlanRepository($db);
  }

  /** @param array<string,mixed> $input lista (mis_tareas, objetivos, metas, tareas, campanas) y filtros en `filters` (JSON). */
  public function list(array $input): never
  {
    $this->guard($input);
    $type = self::scalar($input['lista'] ?? '');
    try {
      $rows = $this->plan->list($type, self::json($input['filters'] ?? ''), Auth::user());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['lista' => $type, 'rows' => $rows, 'summary' => $this->plan->summary(Auth::user())]);
  }

  /** @param array<string,mixed> $input tipo, id y los campos en `data` (JSON). */
  public function save(array $input): never
  {
    $this->guard($input);
    $this->requireEdit();
    $type = self::scalar($input['tipo'] ?? '');
    $id = (int) ($input['id'] ?? 0);
    try {
      $id = $this->plan->save($type, $id, self::json($input['data'] ?? ''), $this->author());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['id' => $id, 'message' => 'Guardado.', 'options' => $this->plan->options(), 'summary' => $this->plan->summary(Auth::user())]);
  }

  /** @param array<string,mixed> $input tipo e id. */
  public function delete(array $input): never
  {
    $this->guard($input);
    $this->requireEdit();
    $type = self::scalar($input['tipo'] ?? '');
    $id = (int) ($input['id'] ?? 0);
    try {
      $name = $this->plan->delete($type, $id);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    // El plan no tiene historial propio: queda en el log del servidor quién eliminó qué.
    error_log(sprintf('[plan-estrategico] %s eliminó %s #%d «%s»', $this->author(), $type, $id, $name));
    JsonResponse::success(['message' => 'Eliminado: ' . $name . '.', 'options' => $this->plan->options(), 'summary' => $this->plan->summary(Auth::user())]);
  }

  /** @param array<string,mixed> $input id de la tarea, descripcion y finalizar (1/0). */
  public function progress(array $input): never
  {
    $this->guard($input);
    $id = (int) ($input['id'] ?? 0);
    $this->requireOwnerOrEdit('tareas', $id, 'Solo los responsables de la tarea o quien edita el plan pueden registrar avances.');
    $finish = in_array(self::scalar($input['finalizar'] ?? ''), ['1', 'true', 'Si'], true);
    try {
      $this->plan->addProgress($id, self::scalar($input['descripcion'] ?? ''), $finish, Auth::user(), $this->author());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => $finish ? 'Avance registrado y tarea completada.' : 'Avance registrado.', 'summary' => $this->plan->summary(Auth::user())]);
  }

  /** @param array<string,mixed> $input id de la campaña y descripcion. */
  public function result(array $input): never
  {
    $this->guard($input);
    $id = (int) ($input['id'] ?? 0);
    $this->requireOwnerOrEdit('campanas', $id, 'Solo los responsables de la campaña o quien edita el plan pueden registrar resultados.');
    try {
      $this->plan->addResult($id, self::scalar($input['descripcion'] ?? ''), Auth::user(), $this->author());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'Resultado registrado.', 'summary' => $this->plan->summary(Auth::user())]);
  }

  /** @param array<string,mixed> $input */
  private function guard(array $input): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canSubview('herramientas', 'plan_estrategico')) {
      JsonResponse::error('No tienes permiso para entrar al Plan estratégico.', 403);
    }
  }

  private function requireEdit(): void
  {
    if (!$this->policy->canAct('plan_estrategico_editar')) {
      JsonResponse::error('No tienes permiso para crear, editar ni eliminar en el Plan estratégico.', 403);
    }
  }

  private function requireOwnerOrEdit(string $table, int $id, string $message): void
  {
    if ($this->policy->canAct('plan_estrategico_editar')) return;
    if (!$this->plan->isResponsible($table, $id, Auth::user())) {
      JsonResponse::error($message, 403);
    }
  }

  /** cct_author_id: el id_empleado real del funcionario (AGENTS.md); sin él, el _ID de la sesión. */
  private function author(): string
  {
    $employee = trim(Auth::employeeId());
    return $employee !== '' ? $employee : (string) Auth::userId();
  }

  private static function scalar(mixed $value): string
  {
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /** @return array<string,mixed> */
  private static function json(mixed $value): array
  {
    if (is_array($value)) return $value;
    $decoded = json_decode(self::scalar($value), true);
    return is_array($decoded) ? $decoded : [];
  }
}
