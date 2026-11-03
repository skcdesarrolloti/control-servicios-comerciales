<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Promoción › Reuniones: copia de dashboard-marketing (app/MeetingService.php) con las mismas reglas: logros, mejoras y
 * plan de acción (responsable y fecha límite obligatorios), arrastre una sola vez de las acciones abiertas de la última
 * reunión finalizada, ciclo borrador → en curso → finalizada (bloqueada) → reabrir, archivo e historial de estados.
 *
 * Cambios frente al original:
 * - Eliminar es un borrado lógico con rastro (quién, cuándo y en qué estado estaba), no un DELETE en cascada.
 * - El autor y los responsables siempre son el id_empleado real de wp_jet_cct_funcionarios (Auth::employeeId()).
 * - Alcance por cargo (SCOPED_VIEWS['promocion']): sin "Todo el equipo" solo se abren las reuniones propias.
 * - Reordenar renumera la sección completa dentro de una transacción.
 * Los permisos por acción (reuniones_gestionar, reuniones_reabrir, reuniones_eliminar) los valida el controlador.
 */
final class MeetingService
{
  public const MEETING_STATUSES = ['draft', 'in_progress', 'completed', 'archived'];
  public const ITEM_SECTIONS = ['achievement', 'improvement', 'action'];
  public const ACTION_STATUSES = ['pending', 'in_progress', 'completed', 'not_completed'];

  private Database $db;
  private MeetingRepository $repo;
  /** @var array{all:bool,employee:string} */
  private array $scope;

  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, ?MeetingRepository $repo = null)
  {
    MeetingSchema::ensure($db, $settings);
    $this->db = $db;
    $this->repo = $repo ?? new MeetingRepository($db);
    $this->scope = ['all' => $policy->canSeeAll('promocion'), 'employee' => Auth::employeeId()];
  }

  /** @param array{q?:string,status?:string} $filters @return array{meetings:array<int,array<string,mixed>>,summary:array<string,int>} */
  public function dashboard(array $filters): array
  {
    return $this->repo->dashboard($filters, $this->scope, date('Y-m-d'));
  }

  /** Reunión con sus puntos, historial y avance, o [] si no existe, se eliminó o queda fuera del alcance. @return array<string,mixed> */
  public function find(int $id): array
  {
    return $this->repo->find($id, $this->scope);
  }

  /** @return array<int,array{id:int,nombre:string}> */
  public function employees(): array
  {
    return $this->repo->employees();
  }

  /** @param array<string,mixed> $input */
  public function saveMeeting(array $input): int
  {
    $id = max(0, (int) ($input['id'] ?? 0));
    $existing = $id > 0 ? $this->requireMeeting($id) : [];
    if ($existing && in_array((string) $existing['status'], ['completed', 'archived'], true)) {
      throw new \RuntimeException('La reunión está cerrada. Hay que reabrirla antes de modificarla.');
    }
    $title = $this->text($input['title'] ?? '', 220);
    if ($title === '') {
      throw new \RuntimeException('Escribe un título para la reunión.');
    }
    $meetingDate = $this->date($input['meeting_date'] ?? '');
    if ($meetingDate === '') {
      throw new \RuntimeException('Selecciona una fecha válida para la reunión.');
    }
    $ids = $input['participant_ids'] ?? [];
    $participants = $this->participants(is_array($ids) ? $ids : [], (array) ($existing['participants'] ?? []));
    $user = $this->userSnapshot();
    $now = date('Y-m-d H:i:s');
    $data = [
      'title' => $title,
      'meeting_date' => $meetingDate,
      'objective' => $this->textArea($input['objective'] ?? '', 8000),
      'participants_json' => json_encode($participants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
      'conclusions' => $this->textArea($input['conclusions'] ?? '', 12000),
      'updated_by' => $user['id'],
      'updated_by_name' => $user['name'],
      'updated_at' => $now,
    ];
    if ($id > 0) {
      $this->repo->updateMeeting($id, $data);
      return $id;
    }

    $previous = $this->repo->previousCompleted($meetingDate, $this->scope);
    if ($previous && $this->repo->hasCarryTarget((int) $previous['id'])) {
      $previous = [];
    }
    $data += [
      'status' => 'draft',
      'carried_from_meeting_id' => !empty($previous['id']) ? (int) $previous['id'] : null,
      'created_by' => $user['id'],
      'created_by_name' => $user['name'],
      'created_at' => $now,
    ];
    return $this->transaction(function () use ($data, $previous, $user, $now): int {
      $id = $this->repo->insertMeeting($data);
      if ($id <= 0) {
        throw new \RuntimeException('No fue posible crear la reunión.');
      }
      if ($previous) {
        $this->carryActions((int) $previous['id'], $id, $user, $now);
      }
      return $id;
    });
  }

  /** @param array<string,mixed> $input */
  public function saveItem(array $input): int
  {
    $id = max(0, (int) ($input['id'] ?? 0));
    $meetingId = max(0, (int) ($input['meeting_id'] ?? 0));
    $this->requireEditableMeeting($meetingId);
    $existing = $id > 0 ? $this->repo->item($id) : [];
    if ($id > 0 && (!$existing || (int) $existing['meeting_id'] !== $meetingId || (int) ($existing['active'] ?? 0) !== 1)) {
      throw new \RuntimeException('El elemento seleccionado no pertenece a esta reunión.');
    }
    $section = $existing ? (string) $existing['section'] : trim((string) ($input['section'] ?? ''));
    if (!in_array($section, self::ITEM_SECTIONS, true)) {
      throw new \RuntimeException('Selecciona una sección válida.');
    }
    $title = $this->text($input['title'] ?? '', 300);
    if ($title === '') {
      throw new \RuntimeException($section === 'action' ? 'Describe la acción que debe realizarse.' : 'Escribe un título para el punto.');
    }
    $user = $this->userSnapshot();
    $now = date('Y-m-d H:i:s');
    $data = [
      'title' => $title,
      'content' => $this->textArea($input['content'] ?? '', 12000),
      'observation' => $section === 'achievement' ? '' : $this->textArea($input['observation'] ?? '', 12000),
      'updated_by' => $user['id'],
      'updated_by_name' => $user['name'],
      'updated_at' => $now,
    ];
    if ($section === 'action') {
      $responsibleId = max(0, (int) ($input['responsible_employee_id'] ?? 0));
      $employee = $responsibleId > 0 ? $this->repo->employee($responsibleId) : [];
      // Un responsable que ya no está en funcionarios se conserva con su nombre histórico.
      if (!$employee && $existing && $responsibleId === (int) ($existing['responsible_employee_id'] ?? 0)) {
        $employee = ['id' => $responsibleId, 'nombre' => (string) ($existing['responsible_name'] ?? '')];
      }
      if ($responsibleId <= 0 || !$employee) {
        throw new \RuntimeException('Selecciona un responsable para la acción.');
      }
      $dueDate = $this->date($input['due_date'] ?? '');
      if ($dueDate === '') {
        throw new \RuntimeException('Selecciona una fecha límite válida para la acción.');
      }
      $relatedId = max(0, (int) ($input['related_improvement_id'] ?? 0));
      if ($relatedId > 0) {
        $related = $this->repo->item($relatedId);
        if (!$related || (int) $related['meeting_id'] !== $meetingId || (string) $related['section'] !== 'improvement' || (int) ($related['active'] ?? 0) !== 1) {
          throw new \RuntimeException('El punto de mejora relacionado no es válido.');
        }
      }
      $data += [
        'related_improvement_id' => $relatedId ?: null,
        'responsible_employee_id' => $responsibleId,
        'responsible_name' => $this->text($employee['nombre'], 191),
        'due_date' => $dueDate,
      ];
    }
    if ($existing) {
      $this->repo->updateItem($id, $data);
      return $id;
    }
    $data += [
      'meeting_id' => $meetingId,
      'section' => $section,
      'status' => $section === 'action' ? 'pending' : 'recorded',
      'sort_order' => $this->repo->maxSort($meetingId, $section) + 10,
      'active' => 1,
      'created_by' => $user['id'],
      'created_by_name' => $user['name'],
      'created_at' => $now,
    ];
    $id = $this->repo->insertItem($data);
    if ($id <= 0) {
      throw new \RuntimeException('No fue posible guardar el punto de reunión.');
    }
    return $id;
  }

  /** @return array{item:array<string,mixed>,progress:array<string,int>} */
  public function updateActionStatus(int $itemId, string $status, string $reason = ''): array
  {
    $item = $this->repo->item($itemId);
    if (!$item || (string) ($item['section'] ?? '') !== 'action' || (int) ($item['active'] ?? 0) !== 1) {
      throw new \RuntimeException('La acción seleccionada ya no está disponible.');
    }
    $meetingId = (int) $item['meeting_id'];
    $this->requireEditableMeeting($meetingId);
    if (!in_array($status, self::ACTION_STATUSES, true)) {
      throw new \RuntimeException('Selecciona un estado válido.');
    }
    $reason = $this->textArea($reason, 12000);
    if ($status === 'not_completed' && $reason === '') {
      throw new \RuntimeException('Explica por qué la acción no se realizó.');
    }
    $from = (string) ($item['status'] ?? 'pending');
    if ($from === $status && ($status !== 'not_completed' || $reason === (string) ($item['failure_reason'] ?? ''))) {
      return ['item' => $item, 'progress' => $this->repo->progress($meetingId)];
    }
    $user = $this->userSnapshot();
    $now = date('Y-m-d H:i:s');
    $this->transaction(function () use ($itemId, $meetingId, $status, $reason, $from, $user, $now): void {
      $this->repo->updateItem($itemId, [
        'status' => $status,
        'failure_reason' => $status === 'not_completed' ? $reason : '',
        'completed_by' => $status === 'completed' ? $user['id'] : null,
        'completed_by_name' => $status === 'completed' ? $user['name'] : '',
        'completed_at' => $status === 'completed' ? $now : null,
        'updated_by' => $user['id'],
        'updated_by_name' => $user['name'],
        'updated_at' => $now,
      ]);
      if ($this->repo->insertHistory([
        'meeting_id' => $meetingId,
        'action_item_id' => $itemId,
        'from_status' => $from,
        'to_status' => $status,
        'reason' => $reason,
        'changed_by' => $user['id'],
        'changed_by_name' => $user['name'],
        'changed_at' => $now,
      ]) <= 0) {
        throw new \RuntimeException('No fue posible registrar el historial de la acción.');
      }
    });
    return ['item' => $this->repo->item($itemId), 'progress' => $this->repo->progress($meetingId)];
  }

  /** Sube o baja un punto dentro de su sección y deja la sección numerada 10, 20, 30… */
  public function moveItem(int $itemId, string $direction): int
  {
    $item = $this->repo->item($itemId);
    if (!$item || (int) ($item['active'] ?? 0) !== 1) {
      throw new \RuntimeException('El elemento ya no está disponible.');
    }
    $meetingId = (int) $item['meeting_id'];
    $this->requireEditableMeeting($meetingId);
    if (!in_array($direction, ['up', 'down'], true)) {
      throw new \RuntimeException('Movimiento inválido.');
    }
    $this->transaction(function () use ($item, $meetingId, $direction): void {
      $ids = array_map(static fn(array $row): int => (int) $row['id'], $this->repo->sectionItems($meetingId, (string) $item['section']));
      $index = array_search((int) $item['id'], $ids, true);
      $target = $direction === 'up' ? $index - 1 : $index + 1;
      if ($index === false || !isset($ids[$target])) {
        return;
      }
      [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
      foreach ($ids as $position => $id) {
        $this->repo->updateItem($id, ['sort_order' => ($position + 1) * 10]);
      }
    });
    return $meetingId;
  }

  public function archiveItem(int $itemId): int
  {
    $item = $this->repo->item($itemId);
    if (!$item || (int) ($item['active'] ?? 0) !== 1) {
      throw new \RuntimeException('El elemento ya no está disponible.');
    }
    $meetingId = (int) $item['meeting_id'];
    $this->requireEditableMeeting($meetingId);
    $user = $this->userSnapshot();
    $this->repo->updateItem($itemId, ['active' => 0, 'updated_by' => $user['id'], 'updated_by_name' => $user['name'], 'updated_at' => date('Y-m-d H:i:s')]);
    return $meetingId;
  }

  public function start(int $meetingId): void
  {
    $meeting = $this->requireMeeting($meetingId);
    if ((string) $meeting['status'] !== 'draft') {
      throw new \RuntimeException('Solo las reuniones en borrador pueden iniciarse.');
    }
    $this->transitionMeeting($meetingId, ['status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s')]);
  }

  /** Finalizar bloquea la reunión: después no admite cambios hasta que alguien con permiso la reabra. */
  public function complete(int $meetingId, string $conclusions = ''): void
  {
    $meeting = $this->requireMeeting($meetingId);
    if (!in_array((string) $meeting['status'], ['draft', 'in_progress'], true)) {
      throw new \RuntimeException('Esta reunión ya está cerrada.');
    }
    $this->transitionMeeting($meetingId, [
      'status' => 'completed',
      'conclusions' => $this->textArea($conclusions, 12000),
      'completed_at' => date('Y-m-d H:i:s'),
    ]);
  }

  public function reopen(int $meetingId): void
  {
    $meeting = $this->requireMeeting($meetingId);
    if ((string) $meeting['status'] !== 'completed') {
      throw new \RuntimeException('Solo una reunión finalizada puede reabrirse.');
    }
    $this->transitionMeeting($meetingId, ['status' => 'in_progress', 'completed_at' => null]);
  }

  public function archive(int $meetingId): void
  {
    $meeting = $this->requireMeeting($meetingId);
    if ((string) $meeting['status'] === 'archived') {
      throw new \RuntimeException('La reunión ya está archivada.');
    }
    $this->transitionMeeting($meetingId, ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s')]);
  }

  /** Borrado lógico: la reunión deja de verse, pero sus puntos, acciones e historial quedan con quién la eliminó y cuándo. */
  public function delete(int $meetingId): void
  {
    $this->requireMeeting($meetingId);
    if (!$this->repo->markDeleted($meetingId, $this->userSnapshot(), date('Y-m-d H:i:s'))) {
      throw new \RuntimeException('La reunión no pudo eliminarse.');
    }
  }

  /** @param array<string,mixed> $changes */
  private function transitionMeeting(int $meetingId, array $changes): void
  {
    $user = $this->userSnapshot();
    $changes += ['updated_by' => $user['id'], 'updated_by_name' => $user['name'], 'updated_at' => date('Y-m-d H:i:s')];
    $this->repo->updateMeeting($meetingId, $changes);
  }

  /** @param array{id:int|null,name:string} $user */
  private function carryActions(int $sourceMeetingId, int $targetMeetingId, array $user, string $now): void
  {
    $sort = 0;
    foreach ($this->repo->unresolvedActions($sourceMeetingId) as $source) {
      $sort += 10;
      $copyId = $this->repo->insertItem([
        'meeting_id' => $targetMeetingId,
        'section' => 'action',
        'title' => (string) $source['title'],
        'content' => (string) ($source['content'] ?? ''),
        'observation' => (string) ($source['observation'] ?? ''),
        'responsible_employee_id' => (int) ($source['responsible_employee_id'] ?? 0) ?: null,
        'responsible_name' => (string) ($source['responsible_name'] ?? ''),
        'due_date' => !empty($source['due_date']) ? (string) $source['due_date'] : null,
        'status' => 'pending',
        'failure_reason' => '',
        'carried_from_item_id' => (int) $source['id'],
        'sort_order' => $sort,
        'active' => 1,
        'created_by' => $user['id'],
        'created_by_name' => $user['name'],
        'updated_by' => $user['id'],
        'updated_by_name' => $user['name'],
        'created_at' => $now,
        'updated_at' => $now,
      ]);
      if ($copyId <= 0) {
        throw new \RuntimeException('No fue posible copiar una acción pendiente de la reunión anterior.');
      }
    }
  }

  /**
   * Participantes por id_empleado con su nombre guardado como histórico (si después cambia o se retira, se conserva).
   * @param array<int|string,mixed> $ids
   * @param array<int,mixed> $historical
   * @return array<int,array{id:int,name:string}>
   */
  private function participants(array $ids, array $historical = []): array
  {
    $historicalNames = [];
    foreach ($historical as $participant) {
      if (is_array($participant)) {
        $historicalNames[(int) ($participant['id'] ?? 0)] = (string) ($participant['name'] ?? '');
      }
    }
    $out = [];
    foreach (array_values(array_unique(array_filter(array_map(static fn($id): int => is_scalar($id) ? (int) $id : 0, $ids)))) as $id) {
      if ($id <= 0) {
        continue;
      }
      $employee = $this->repo->employee($id);
      $name = $employee ? (string) $employee['nombre'] : (string) ($historicalNames[$id] ?? '');
      if ($name !== '') {
        $out[] = ['id' => $id, 'name' => $this->text($name, 191)];
      }
    }
    return $out;
  }

  /** @return array<string,mixed> */
  private function requireMeeting(int $id): array
  {
    $meeting = $this->repo->find($id, $this->scope);
    if (!$meeting) {
      throw new MeetingNotFound('La reunión seleccionada no existe o no tienes acceso a ella.');
    }
    return $meeting;
  }

  /** @return array<string,mixed> */
  private function requireEditableMeeting(int $id): array
  {
    $meeting = $this->requireMeeting($id);
    if (in_array((string) $meeting['status'], ['completed', 'archived'], true)) {
      throw new \RuntimeException('La reunión está cerrada y no admite cambios.');
    }
    return $meeting;
  }

  /**
   * Autor de cada cambio: id_empleado real (no el _ID interno de la sesión) y su nombre.
   * @return array{id:int|null,name:string}
   */
  private function userSnapshot(): array
  {
    $employee = trim(Auth::employeeId());
    $name = $this->text(Auth::user(), 191);
    return [
      'id' => ctype_digit($employee) && (int) $employee > 0 ? (int) $employee : null,
      'name' => $name !== '' ? $name : 'Funcionario',
    ];
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function transaction(callable $work)
  {
    $pdo = $this->db->pdo();
    $owns = !$pdo->inTransaction();
    if ($owns) {
      $pdo->beginTransaction();
    }
    try {
      $result = $work();
      if ($owns) {
        $pdo->commit();
      }
      return $result;
    } catch (\Throwable $error) {
      if ($owns) {
        $this->rollBack($pdo);
      }
      throw $error;
    }
  }

  /** Si el commit falló, la transacción ya pudo cerrarse: solo se revierte si sigue abierta. */
  private function rollBack(\PDO $pdo): void
  {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
  }

  private function date(mixed $value): string
  {
    $value = trim(is_scalar($value) ? (string) $value : '');
    $date = \DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
  }

  private function text(mixed $value, int $max): string
  {
    $value = trim(strip_tags(is_scalar($value) ? (string) $value : ''));
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
  }

  private function textArea(mixed $value, int $max): string
  {
    $value = trim(strip_tags(is_scalar($value) ? (string) $value : ''));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
  }
}
