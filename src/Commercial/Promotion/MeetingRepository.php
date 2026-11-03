<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

use SCM\Core\Database;

/**
 * Promoción › Reuniones: copia de dashboard-marketing (app/MeetingRepository.php) sobre las mismas tablas
 * wp_marketing_meeting*. Si cambia la lógica en uno, revisar el otro mientras las dos aplicaciones convivan.
 *
 * Cambios frente al original:
 * - El borrado es lógico (deleted_at); toda consulta ignora las reuniones eliminadas.
 * - Alcance por funcionario: sin "Todo el equipo" solo se ven las reuniones que creó, donde participa o donde es
 *   responsable de una acción (siempre por id_empleado).
 * - SQL que corre igual en MySQL y en SQLite (las fechas de hoy llegan como parámetro).
 */
final class MeetingRepository
{
  public const LIST_LIMIT = 250;

  private Database $db;
  private string $meetings;
  private string $items;
  private string $history;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->meetings = $db->table('marketing_meetings');
    $this->items = $db->table('marketing_meeting_items');
    $this->history = $db->table('marketing_meeting_action_history');
  }

  /**
   * @param array{q?:string,status?:string} $filters
   * @param array{all:bool,employee:string} $scope
   * @return array{meetings:array<int,array<string,mixed>>,summary:array<string,int>}
   */
  public function dashboard(array $filters, array $scope, string $today): array
  {
    [$scopeSql, $scopeParams] = $this->scopeSql($scope, 'm');
    $where = ["m.status <> 'archived'"];
    $params = [];
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status === 'upcoming') {
      $where = ["m.status = 'draft'", 'm.meeting_date >= ?'];
      $params = [$today];
    } elseif ($status === 'history') {
      $where = ["m.status IN ('completed','archived')"];
    } elseif (in_array($status, MeetingService::MEETING_STATUSES, true)) {
      $where = ['m.status = ?'];
      $params = [$status];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      // escapeLike() usa la barra invertida, que es el escape por defecto de LIKE en MySQL.
      $where[] = '(m.title LIKE ? OR m.objective LIKE ? OR m.conclusions LIKE ?)';
      $like = '%' . $this->db->escapeLike($q) . '%';
      array_push($params, $like, $like, $like);
    }
    $rows = $this->db->getResults(
      "SELECT m.*,
              COUNT(i.id) AS action_total,
              SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) AS action_completed,
              SUM(CASE WHEN i.status = 'not_completed' THEN 1 ELSE 0 END) AS action_not_completed,
              SUM(CASE WHEN i.status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS action_open
         FROM `{$this->meetings}` m
         LEFT JOIN `{$this->items}` i ON i.meeting_id = m.id AND i.active = 1 AND i.section = 'action'
        WHERE m.deleted_at IS NULL AND " . implode(' AND ', $where) . $scopeSql . "
        GROUP BY m.id
        ORDER BY (CASE WHEN m.status = 'in_progress' THEN 1 ELSE 0 END) DESC, (CASE WHEN m.meeting_date >= ? THEN 1 ELSE 0 END) DESC, m.meeting_date DESC, m.id DESC
        LIMIT " . self::LIST_LIMIT,
      array_merge($params, $scopeParams, [$today])
    );
    $rows = array_map(fn(array $row): array => $this->decorateMeeting($row), $rows);

    $summary = $this->db->getRow(
      "SELECT COUNT(*) AS total,
              SUM(CASE WHEN m.status = 'draft' AND m.meeting_date >= ? THEN 1 ELSE 0 END) AS upcoming,
              SUM(CASE WHEN m.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
              SUM(CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM `{$this->meetings}` m
        WHERE m.deleted_at IS NULL AND m.status <> 'archived'" . $scopeSql,
      array_merge([$today], $scopeParams)
    ) ?? [];
    $actions = $this->db->getRow(
      "SELECT COUNT(*) AS reviewed, SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM `{$this->items}` i
         INNER JOIN `{$this->meetings}` m ON m.id = i.meeting_id
        WHERE i.active = 1 AND i.section = 'action' AND i.status IN ('completed','not_completed') AND m.deleted_at IS NULL" . $scopeSql,
      $scopeParams
    ) ?? [];
    $reviewed = (int) ($actions['reviewed'] ?? 0);
    return [
      'meetings' => $rows,
      'summary' => [
        'total' => (int) ($summary['total'] ?? 0),
        'upcoming' => (int) ($summary['upcoming'] ?? 0),
        'in_progress' => (int) ($summary['in_progress'] ?? 0),
        'completed' => (int) ($summary['completed'] ?? 0),
        'compliance' => $reviewed > 0 ? (int) round(((int) ($actions['completed'] ?? 0) / $reviewed) * 100) : 0,
      ],
    ];
  }

  /** @param array{all:bool,employee:string} $scope @return array<string,mixed> */
  public function find(int $id, array $scope): array
  {
    if ($id <= 0) {
      return [];
    }
    [$scopeSql, $scopeParams] = $this->scopeSql($scope, 'm');
    $meeting = $this->db->getRow(
      "SELECT m.* FROM `{$this->meetings}` m WHERE m.id = ? AND m.deleted_at IS NULL" . $scopeSql . ' LIMIT 1',
      array_merge([$id], $scopeParams)
    );
    if (!$meeting) {
      return [];
    }
    $meeting = $this->decorateMeeting($meeting);
    $meeting['items'] = ['achievement' => [], 'improvement' => [], 'action' => []];
    foreach ($this->db->getResults(
      "SELECT i.*, source.title AS carried_from_title, source.meeting_id AS source_meeting_id
         FROM `{$this->items}` i
         LEFT JOIN `{$this->items}` source ON source.id = i.carried_from_item_id
        WHERE i.meeting_id = ? AND i.active = 1
        ORDER BY i.section ASC, i.sort_order ASC, i.id ASC",
      [$id]
    ) as $item) {
      $section = (string) ($item['section'] ?? '');
      if (isset($meeting['items'][$section])) {
        $meeting['items'][$section][] = $item;
      }
    }
    $meeting['history'] = $this->db->getResults(
      "SELECT h.*, i.title AS action_title
         FROM `{$this->history}` h
         LEFT JOIN `{$this->items}` i ON i.id = h.action_item_id
        WHERE h.meeting_id = ?
        ORDER BY h.changed_at DESC, h.id DESC",
      [$id]
    );
    $meeting['progress'] = $this->progress($id);
    return $meeting;
  }

  /** Fila cruda, también si está eliminada (para el rastro y las pruebas). @return array<string,mixed> */
  public function raw(int $id): array
  {
    return $id > 0 ? ($this->db->getRow("SELECT * FROM `{$this->meetings}` WHERE id = ? LIMIT 1", [$id]) ?? []) : [];
  }

  /** @param array<string,mixed> $data */
  public function insertMeeting(array $data): int
  {
    if (empty($data['carried_from_meeting_id'])) {
      unset($data['carried_from_meeting_id']);
    }
    return $this->insert($this->meetings, $data);
  }

  /** @param array<string,mixed> $data */
  public function updateMeeting(int $id, array $data): void
  {
    if ($id <= 0 || $data === []) {
      throw new \RuntimeException('No fue posible actualizar la reunión.');
    }
    $this->db->update($this->meetings, $data, ['id' => $id]);
  }

  /** @param array<string,mixed> $data */
  public function insertItem(array $data): int
  {
    foreach (['related_improvement_id', 'responsible_employee_id', 'due_date', 'carried_from_item_id', 'completed_by', 'completed_at'] as $nullable) {
      if (!isset($data[$nullable]) || $data[$nullable] === '' || $data[$nullable] === 0) {
        unset($data[$nullable]);
      }
    }
    return $this->insert($this->items, $data);
  }

  /** @param array<string,mixed> $data */
  public function updateItem(int $id, array $data): void
  {
    if ($id <= 0 || $data === []) {
      throw new \RuntimeException('No fue posible actualizar el punto de reunión.');
    }
    $this->db->update($this->items, $data, ['id' => $id]);
  }

  /** @return array<string,mixed> */
  public function item(int $id): array
  {
    return $id > 0 ? ($this->db->getRow("SELECT * FROM `{$this->items}` WHERE id = ? LIMIT 1", [$id]) ?? []) : [];
  }

  /** Puntos activos de una sección en su orden actual. @return array<int,array<string,mixed>> */
  public function sectionItems(int $meetingId, string $section): array
  {
    return $this->db->getResults(
      "SELECT id, sort_order FROM `{$this->items}` WHERE meeting_id = ? AND section = ? AND active = 1 ORDER BY sort_order ASC, id ASC",
      [$meetingId, $section]
    );
  }

  /**
   * Última reunión finalizada (no eliminada) hasta esa fecha, dentro del alcance de quien crea la nueva.
   * @param array{all:bool,employee:string} $scope
   * @return array<string,mixed>
   */
  public function previousCompleted(string $beforeDate, array $scope, int $excludeId = 0): array
  {
    [$scopeSql, $scopeParams] = $this->scopeSql($scope, 'm');
    return $this->db->getRow(
      "SELECT m.* FROM `{$this->meetings}` m
        WHERE m.status = 'completed' AND m.deleted_at IS NULL AND m.meeting_date <= ? AND m.id <> ?" . $scopeSql . '
        ORDER BY m.meeting_date DESC, m.id DESC LIMIT 1',
      array_merge([$beforeDate, $excludeId], $scopeParams)
    ) ?? [];
  }

  /** Cuenta también las eliminadas: carried_from_meeting_id es único en la tabla. */
  public function hasCarryTarget(int $sourceMeetingId): bool
  {
    if ($sourceMeetingId <= 0) {
      return false;
    }
    return (int) $this->db->getVar(
      "SELECT COUNT(*) FROM `{$this->meetings}` WHERE carried_from_meeting_id = ?",
      [$sourceMeetingId]
    ) > 0;
  }

  /** @return array<int,array<string,mixed>> */
  public function unresolvedActions(int $meetingId): array
  {
    return $this->db->getResults(
      "SELECT * FROM `{$this->items}`
        WHERE meeting_id = ? AND active = 1 AND section = 'action' AND status IN ('pending','in_progress','not_completed')
        ORDER BY sort_order ASC, id ASC",
      [$meetingId]
    );
  }

  public function maxSort(int $meetingId, string $section): int
  {
    return (int) $this->db->getVar(
      "SELECT COALESCE(MAX(sort_order), 0) FROM `{$this->items}` WHERE meeting_id = ? AND section = ?",
      [$meetingId, $section]
    );
  }

  /** @param array<string,mixed> $data */
  public function insertHistory(array $data): int
  {
    return $this->insert($this->history, $data);
  }

  /**
   * Borrado lógico. La reunión queda archivada para que dashboard-marketing (que no conoce deleted_at) tampoco la
   * muestre entre las activas ni la use como reunión anterior; su estado previo queda en deleted_status. Se libera
   * carried_from_meeting_id (único) para que la siguiente reunión pueda volver a traer los pendientes, como hacía el
   * borrado definitivo del original; los puntos copiados conservan su carried_from_item_id.
   * @param array{id:int|null,name:string} $user
   */
  public function markDeleted(int $meetingId, array $user, string $now): bool
  {
    $meeting = $this->raw($meetingId);
    if ($meeting === [] || !empty($meeting['deleted_at'])) {
      return false;
    }
    return $this->db->update($this->meetings, [
      'deleted_at' => $now,
      'deleted_by' => $user['id'],
      'deleted_by_name' => $user['name'],
      'deleted_status' => (string) $meeting['status'],
      'status' => 'archived',
      'archived_at' => !empty($meeting['archived_at']) ? (string) $meeting['archived_at'] : $now,
      'carried_from_meeting_id' => null,
      'updated_by' => $user['id'],
      'updated_by_name' => $user['name'],
      'updated_at' => $now,
    ], ['id' => $meetingId]) > 0;
  }

  /** @return array{total:int,completed:int,not_completed:int,reviewed:int,review_percent:int,compliance_percent:int} */
  public function progress(int $meetingId): array
  {
    $row = $this->db->getRow(
      "SELECT COUNT(*) AS total,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
              SUM(CASE WHEN status = 'not_completed' THEN 1 ELSE 0 END) AS not_completed,
              SUM(CASE WHEN status IN ('completed','not_completed') THEN 1 ELSE 0 END) AS reviewed
         FROM `{$this->items}`
        WHERE meeting_id = ? AND active = 1 AND section = 'action'",
      [$meetingId]
    ) ?? [];
    $total = (int) ($row['total'] ?? 0);
    $reviewed = (int) ($row['reviewed'] ?? 0);
    return [
      'total' => $total,
      'completed' => (int) ($row['completed'] ?? 0),
      'not_completed' => (int) ($row['not_completed'] ?? 0),
      'reviewed' => $reviewed,
      'review_percent' => $total > 0 ? (int) round(($reviewed / $total) * 100) : 0,
      'compliance_percent' => $reviewed > 0 ? (int) round(((int) ($row['completed'] ?? 0) / $reviewed) * 100) : 0,
    ];
  }

  /** Funcionarios activos con su id_empleado real (solo id y nombre viajan al navegador). @return array<int,array{id:int,nombre:string}> */
  public function employees(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $rows = $this->db->getResults(
      "SELECT id_empleado AS id, nombre
         FROM `{$table}`
        WHERE COALESCE(NULLIF(LOWER(TRIM(activo)), ''), 'si') <> 'no'
          AND TRIM(COALESCE(id_empleado, '')) <> ''
          AND nombre IS NOT NULL AND TRIM(nombre) <> ''
        ORDER BY nombre ASC"
    );
    $out = [];
    foreach ($rows as $row) {
      $id = (int) $row['id'];
      if ($id > 0) {
        $out[] = ['id' => $id, 'nombre' => trim((string) $row['nombre'])];
      }
    }
    return $out;
  }

  /** @return array{id:int,nombre:string}|array{} */
  public function employee(int $id): array
  {
    if ($id <= 0) {
      return [];
    }
    $row = $this->db->getRow(
      'SELECT id_empleado AS id, nombre FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE id_empleado = ? LIMIT 1',
      [(string) $id]
    );
    return $row ? ['id' => (int) $row['id'], 'nombre' => trim((string) $row['nombre'])] : [];
  }

  /**
   * Condición de alcance: sin "Todo el equipo" el funcionario ve lo que creó, donde participa (participants_json guarda
   * [{"id":<id_empleado>,"name":...}]) o donde es responsable de una acción activa.
   * @param array{all:bool,employee:string} $scope
   * @return array{0:string,1:array<int,string>}
   */
  private function scopeSql(array $scope, string $alias): array
  {
    if ($scope['all']) {
      return ['', []];
    }
    $employee = trim($scope['employee']);
    if ($employee === '' || !ctype_digit($employee) || (int) $employee <= 0) {
      return [' AND 1 = 0', []];
    }
    $employee = (string) (int) $employee;
    return [
      " AND ({$alias}.created_by = ? OR {$alias}.participants_json LIKE ? OR {$alias}.participants_json LIKE ? OR {$alias}.participants_json LIKE ?"
        . " OR EXISTS (SELECT 1 FROM `{$this->items}` scope_item WHERE scope_item.meeting_id = {$alias}.id AND scope_item.active = 1"
        . " AND scope_item.section = 'action' AND scope_item.responsible_employee_id = ?))",
      [$employee, '%"id":' . $employee . ',%', '%"id":' . $employee . '}%', '%"id":"' . $employee . '"%', $employee],
    ];
  }

  /** @param array<string,mixed> $meeting @return array<string,mixed> */
  private function decorateMeeting(array $meeting): array
  {
    $participants = json_decode((string) ($meeting['participants_json'] ?? '[]'), true);
    $meeting['participants'] = is_array($participants) ? array_values(array_filter($participants, 'is_array')) : [];
    foreach (['action_total', 'action_completed', 'action_not_completed', 'action_open'] as $key) {
      if (array_key_exists($key, $meeting)) {
        $meeting[$key] = (int) $meeting[$key];
      }
    }
    $reviewed = (int) ($meeting['action_completed'] ?? 0) + (int) ($meeting['action_not_completed'] ?? 0);
    $meeting['compliance'] = $reviewed > 0 ? (int) round(((int) ($meeting['action_completed'] ?? 0) / $reviewed) * 100) : 0;
    return $meeting;
  }

  /** @param array<string,mixed> $data */
  private function insert(string $table, array $data): int
  {
    $this->db->insert($table, $data);
    return (int) $this->db->lastInsertId();
  }
}
