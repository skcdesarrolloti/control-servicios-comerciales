<?php

declare(strict_types=1);

namespace SCM\Commercial\Calendar;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\ColombiaBusinessCalendar;

/**
 * Datos del calendario de actividades. Lógica traída de calendario/backend/models (Evento, Recordatorio, Categoria, Ticket
 * e HistorialTicket): usa las mismas tablas, que comparte el panel inmobiliario, para no duplicar agendas.
 */
final class CalendarStore
{
  public const EVENTS = 'calendario_actividades';
  public const CATEGORIES = 'categorias_calendario';
  public const REMINDERS = 'calendario_recordatorios';
  public const BLOCKS = 'registro_bloqueos';
  public const ORIGIN = 'control-servicios-comerciales';

  /** Días que un evento puede seguir sin cerrar antes de bloquear la creación de otros. */
  private const BLOCK_GRACE_DAYS = 2;
  /** Tope de filas de una consulta por rango: el mes de todo el equipo no debe quedar cortado. */
  private const MAX_ROWS = 3000;

  private Database $db;
  private string $employees;
  private string $tickets;
  private string $history;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->employees = $db->table('jet_cct_funcionarios');
    $this->tickets = $db->table('jet_cct_tickets');
    $this->history = $db->table('jet_cct_historial_del_ticket');
  }

  public function database(): Database
  {
    return $this->db;
  }

  // ── Categorías ─────────────────────────────────────

  /** @return array<int,array<string,mixed>> */
  public function categories(): array
  {
    return $this->db->getResults('SELECT `id`, `nombre`, `color`, `descripcion` FROM `' . self::CATEGORIES . '` ORDER BY `nombre` ASC');
  }

  /** @return array<int,array<string,mixed>> Categorías con la cantidad de eventos que las usan. */
  public function categoriesWithUsage(): array
  {
    return $this->db->getResults(
      'SELECT c.`id`, c.`nombre`, c.`color`, c.`descripcion`, COUNT(e.`id`) AS eventos
         FROM `' . self::CATEGORIES . '` c
         LEFT JOIN `' . self::EVENTS . '` e ON e.`id_categoria` = c.`id`
        GROUP BY c.`id`, c.`nombre`, c.`color`, c.`descripcion`
        ORDER BY c.`nombre` ASC'
    );
  }

  /** @return array<string,mixed>|null */
  public function category(int $id): ?array
  {
    return $this->db->getRow('SELECT `id`, `nombre`, `color`, `descripcion` FROM `' . self::CATEGORIES . '` WHERE `id` = ?', [$id]);
  }

  public function categoryEventCount(int $id): int
  {
    return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . self::EVENTS . '` WHERE `id_categoria` = ?', [$id]);
  }

  public function categoryNameTaken(string $name, int $exceptId = 0): bool
  {
    return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . self::CATEGORIES . '` WHERE LOWER(`nombre`) = LOWER(?) AND `id` <> ?', [$name, $exceptId]) > 0;
  }

  public function insertCategory(string $name, string $color, string $description): int
  {
    $this->db->insert(self::CATEGORIES, ['nombre' => $name, 'color' => $color, 'descripcion' => $description]);
    return (int) $this->db->lastInsertId();
  }

  public function updateCategory(int $id, string $name, string $color, string $description): void
  {
    $this->db->update(self::CATEGORIES, ['nombre' => $name, 'color' => $color, 'descripcion' => $description], ['id' => $id]);
  }

  public function deleteCategory(int $id): void
  {
    $this->db->delete(self::CATEGORIES, ['id' => $id]);
  }

  // ── Eventos ────────────────────────────────────────

  /**
   * @param array<int,string>|null $employeeIds null = todos; [] = ninguno.
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,total:int}
   */
  public function events(?array $employeeIds, array $filters, int $limit = self::MAX_ROWS, int $offset = 0): array
  {
    if ($employeeIds === []) {
      return ['rows' => [], 'total' => 0];
    }
    $where = ['1=1'];
    $args = [];
    if ($employeeIds !== null) {
      $where[] = 'e.`id_empleado` IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
      array_push($args, ...$employeeIds);
    }
    foreach (['id_categoria', 'id_ticket', 'estado', 'fue_trasladado', 'origen_app', 'external_ref'] as $column) {
      if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
        $where[] = "e.`{$column}` = ?";
        $args[] = $filters[$column];
      }
    }
    if (!empty($filters['fecha_inicio']) && !empty($filters['fecha_fin'])) {
      $where[] = 'e.`fecha_inicio` BETWEEN ? AND ?';
      $args[] = substr((string) $filters['fecha_inicio'], 0, 10) . ' 00:00:00';
      $args[] = substr((string) $filters['fecha_fin'], 0, 10) . ' 23:59:59';
    }
    if (trim((string) ($filters['buscar'] ?? '')) !== '') {
      $where[] = '(e.`titulo` LIKE ? OR e.`descripcion` LIKE ? OR e.`ubicacion` LIKE ?)';
      $like = '%' . trim((string) $filters['buscar']) . '%';
      array_push($args, $like, $like, $like);
    }
    $from = ' FROM `' . self::EVENTS . '` e
              JOIN `' . self::CATEGORIES . '` c ON e.`id_categoria` = c.`id`
              JOIN `' . $this->employees . '` f ON e.`id_empleado` = f.`id_empleado`
             WHERE ' . implode(' AND ', $where);
    $limit = max(1, min(self::MAX_ROWS, $limit));
    $rows = $this->db->getResults(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario' . $from
        . ' ORDER BY e.`fecha_inicio` ASC, e.`id` ASC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset),
      $args
    );
    return [
      'rows' => array_map([$this, 'mapEvent'], $rows),
      'total' => (int) $this->db->getVar('SELECT COUNT(*)' . $from, $args),
    ];
  }

  /** @return array<string,mixed>|null */
  public function event(int $id): ?array
  {
    $row = $this->db->getRow(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS nombre, f.`nombre` AS funcionario
         FROM `' . self::EVENTS . '` e
         JOIN `' . self::CATEGORIES . '` c ON e.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON e.`id_empleado` = f.`id_empleado`
        WHERE e.`id` = ?',
      [$id]
    );
    return $row === null ? null : $this->mapEvent($row);
  }

  /** @param array<string,mixed> $data */
  public function insertEvent(array $data): int
  {
    $this->db->insert(self::EVENTS, [
      'titulo' => (string) $data['titulo'],
      'descripcion' => (string) ($data['descripcion'] ?? ''),
      'ubicacion' => self::optional($data['ubicacion'] ?? null),
      'fecha_inicio' => (string) $data['fecha_inicio'],
      'fecha_fin' => (string) $data['fecha_fin'],
      'id_empleado' => (string) $data['id_empleado'],
      'creado_por' => self::optional($data['creado_por'] ?? null),
      'id_categoria' => (int) $data['id_categoria'],
      'id_ticket' => (int) ($data['id_ticket'] ?? 0) > 0 ? (int) $data['id_ticket'] : null,
      'origen_app' => self::optional($data['origen_app'] ?? self::ORIGIN),
      'external_ref' => self::optional($data['external_ref'] ?? null),
      'meta_json' => self::metaJson($data['meta'] ?? null),
    ]);
    return (int) $this->db->lastInsertId();
  }

  public function updateEvent(int $id, string $title, string $description, string $location, int $categoryId): void
  {
    $this->db->update(self::EVENTS, ['titulo' => $title, 'descripcion' => $description, 'ubicacion' => $location, 'id_categoria' => $categoryId], ['id' => $id]);
  }

  public function completeEvent(int $id, string $observation): void
  {
    $this->db->update(self::EVENTS, ['estado' => 'Si', 'observacion' => $observation], ['id' => $id]);
  }

  public function rescheduleEvent(int $id, string $start, string $end, string $observation): void
  {
    $this->db->update(self::EVENTS, ['fecha_inicio' => $start, 'fecha_fin' => $end, 'observacion' => $observation, 'estado' => 'No', 'fue_trasladado' => 'Si'], ['id' => $id]);
  }

  // ── Bloqueo por eventos vencidos ───────────────────

  /** @return array{bloqueado:bool,cantidad:int} */
  public function blockStatus(string $employeeId): array
  {
    $total = (int) $this->db->getVar(
      'SELECT COUNT(*) FROM `' . self::EVENTS . "` WHERE `id_empleado` = ? AND `estado` = 'No' AND `fecha_fin` < ?",
      [$employeeId, $this->blockLimit()]
    );
    return ['bloqueado' => $total > 0, 'cantidad' => $total];
  }

  public function logBlock(string $employeeId, int $pending): void
  {
    $this->db->insert(self::BLOCKS, ['id_empleado' => $employeeId, 'cantidad_pendientes' => $pending, 'fecha_registro' => $this->now()]);
  }

  /** @return array<int,array<string,mixed>> */
  public function overdueEvents(string $employeeId): array
  {
    return $this->db->getResults(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`
         FROM `' . self::EVENTS . '` e
         LEFT JOIN `' . self::CATEGORIES . "` c ON e.`id_categoria` = c.`id`
        WHERE e.`id_empleado` = ? AND e.`estado` = 'No' AND e.`fecha_fin` < ?
        ORDER BY e.`fecha_inicio` ASC",
      [$employeeId, $this->blockLimit()]
    );
  }

  /**
   * Eventos que ya pasaron su hora y faltan por marcar, todavía dentro del plazo: no son vencidos ni bloquean crear otros.
   * @return array<int,array<string,mixed>>
   */
  public function eventsToClose(string $employeeId): array
  {
    return $this->db->getResults(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`
         FROM `' . self::EVENTS . '` e
         LEFT JOIN `' . self::CATEGORIES . "` c ON e.`id_categoria` = c.`id`
        WHERE e.`id_empleado` = ? AND e.`estado` = 'No' AND e.`fecha_fin` < ? AND e.`fecha_fin` >= ?
        ORDER BY e.`fecha_inicio` ASC",
      [$employeeId, $this->now(), $this->blockLimit()]
    );
  }

  // ── Recordatorios ──────────────────────────────────

  /**
   * @param array<int,string>|null $employeeIds null = todos; [] = ninguno.
   * @param array<string,mixed> $filters
   * @return array<int,array<string,mixed>>
   */
  public function reminders(?array $employeeIds, array $filters): array
  {
    if ($employeeIds === []) {
      return [];
    }
    $where = ['1=1'];
    $args = [];
    if ($employeeIds !== null) {
      $where[] = 'r.`id_empleado` IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
      array_push($args, ...$employeeIds);
    }
    $status = (string) ($filters['estado'] ?? '');
    if ($status === 'No') {
      $where[] = "r.`estado` IN ('pendiente', 'programado')";
    } elseif ($status === 'Si') {
      $where[] = "r.`estado` IN ('finalizado', 'enviado')";
    } elseif ($status !== '') {
      $where[] = 'r.`estado` = ?';
      $args[] = self::reminderStatus($status);
    }
    foreach (['id_ticket', 'origen_app', 'external_ref'] as $column) {
      if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
        $where[] = "r.`{$column}` = ?";
        $args[] = $filters[$column];
      }
    }
    if (!empty($filters['fecha_inicio']) && !empty($filters['fecha_fin'])) {
      $where[] = 'r.`recordatorio_at` BETWEEN ? AND ?';
      $args[] = substr((string) $filters['fecha_inicio'], 0, 10) . ' 00:00:00';
      $args[] = substr((string) $filters['fecha_fin'], 0, 10) . ' 23:59:59';
    }
    $rows = $this->db->getResults(
      'SELECT r.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario
         FROM `' . self::REMINDERS . '` r
         LEFT JOIN `' . self::CATEGORIES . '` c ON r.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON r.`id_empleado` = f.`id_empleado`
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY r.`recordatorio_at` ASC, r.`id` ASC LIMIT ' . self::MAX_ROWS,
      $args
    );
    return array_map([$this, 'mapReminder'], $rows);
  }

  /** @return array<string,mixed>|null */
  public function reminder(int $id): ?array
  {
    $row = $this->db->getRow(
      'SELECT r.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario
         FROM `' . self::REMINDERS . '` r
         LEFT JOIN `' . self::CATEGORIES . '` c ON r.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON r.`id_empleado` = f.`id_empleado`
        WHERE r.`id` = ?',
      [$id]
    );
    return $row === null ? null : $this->mapReminder($row);
  }

  /** @param array<string,mixed> $data */
  public function insertReminder(array $data): int
  {
    $this->db->insert(self::REMINDERS, [
      'titulo' => trim((string) ($data['titulo'] ?? 'Recordatorio')),
      'mensaje' => (string) ($data['mensaje'] ?? ($data['descripcion'] ?? '')),
      'recordatorio_at' => (string) $data['recordatorio_at'],
      'canal' => self::reminderChannel((string) ($data['recordatorio_canal'] ?? ($data['canal'] ?? 'email'))),
      'estado' => 'pendiente',
      'id_empleado' => trim((string) $data['id_empleado']),
      'creado_por' => self::optional($data['creado_por'] ?? null),
      'id_evento' => self::optional($data['id_evento'] ?? null),
      'id_ticket' => (int) ($data['id_ticket'] ?? 0) > 0 ? (int) $data['id_ticket'] : null,
      'id_categoria' => (int) ($data['id_categoria'] ?? 0) > 0 ? (int) $data['id_categoria'] : null,
      'origen_app' => self::optional($data['origen_app'] ?? self::ORIGIN),
      'external_ref' => self::optional($data['external_ref'] ?? null),
      'meta_json' => self::metaJson($data['meta'] ?? null),
    ]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public function updateReminder(int $id, array $data): void
  {
    $this->db->pdo()->prepare(
      'UPDATE `' . self::REMINDERS . "` SET `titulo` = ?, `mensaje` = ?, `recordatorio_at` = ?, `meta_json` = ?
        WHERE `id` = ? AND `estado` IN ('pendiente', 'programado')"
    )->execute([trim((string) $data['titulo']), (string) ($data['mensaje'] ?? ''), (string) $data['recordatorio_at'], self::metaJson($data['meta'] ?? []), $id]);
  }

  public function setReminderQueue(int $id, ?int $queueId, string $status): void
  {
    $this->db->update(self::REMINDERS, ['notification_queue_id' => $queueId ?: null, 'estado' => self::reminderStatus($status)], ['id' => $id]);
  }

  public function setReminderStatus(int $id, string $status): void
  {
    $this->db->update(self::REMINDERS, ['estado' => self::reminderStatus($status)], ['id' => $id]);
  }

  /** Pasa a finalizado lo que ya llegó a su hora. */
  public function finalizeExpiredReminders(): void
  {
    $now = $this->now();
    $this->db->pdo()->prepare(
      'UPDATE `' . self::REMINDERS . "` SET `estado` = 'finalizado' WHERE `estado` IN ('pendiente', 'programado') AND `recordatorio_at` <= ?"
    )->execute([$now]);
  }

  // ── Tareas vinculadas ──────────────────────────────

  /** @return array<string,mixed>|null */
  public function ticket(int $pk): ?array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->tickets . '` WHERE `_ID` = ?', [$pk]);
  }

  /**
   * Vincula el evento a la tarea. El estado administrativo no se toca nunca (AGENTS.md) y el comercial solo si se eligió
   * uno; una tarea cerrada sigue cerrada.
   */
  public function touchTicketForEvent(int $pk, string $commercialStatus): void
  {
    $ticket = $this->ticket($pk);
    if ($ticket === null) {
      return;
    }
    $data = ['fecha_actualizacion' => time(), 'cct_modified' => $this->now()];
    if (strcasecmp(trim((string) ($ticket['estado'] ?? '')), 'Cerrado') !== 0) {
      $data['estado'] = 'En proceso';
    }
    if (trim($commercialStatus) !== '') {
      $data['estado_comercial'] = trim($commercialStatus);
    }
    $this->db->update($this->tickets, $data, ['_ID' => $pk]);
  }

  public function touchTicket(int $pk): void
  {
    $this->db->update($this->tickets, ['fecha_actualizacion' => time(), 'cct_modified' => $this->now()], ['_ID' => $pk]);
  }

  /** Deja constancia de la cita en el historial de la tarea, a nombre del funcionario asignado. */
  public function addTicketHistory(int $pk, string $employeeId, int $eventId, string $message): void
  {
    $employee = $this->employee($employeeId) ?? [];
    $now = $this->now();
    $author = trim(Auth::employeeId());
    $this->db->insert($this->history, [
      'cct_status' => 'publish',
      'cct_author_id' => $author !== '' ? $author : $employeeId,
      'cct_created' => $now,
      'cct_modified' => $now,
      'id_ticket' => $pk,
      'fecha' => time(),
      'nombre' => (string) ($employee['nombre'] ?? ''),
      'correo' => (string) ($employee['correo'] ?? ''),
      'celular' => (string) ($employee['celular'] ?? ''),
      'respuesta' => $message,
      'id_empleado' => $employeeId,
      'id_evento' => $eventId,
      'fue_editada' => 'Si',
    ]);
  }

  // ── Funcionarios ───────────────────────────────────

  /** @return array<string,mixed>|null Fila completa: es de uso interno y nunca se envía tal cual al navegador. */
  public function employee(string $employeeId): ?array
  {
    return trim($employeeId) === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->employees . '` WHERE `id_empleado` = ? LIMIT 1', [trim($employeeId)]);
  }

  /** El `id_empleado` real de quien tiene la sesión; vacío si no se sabe quién es. */
  public function currentEmployeeId(): string
  {
    $id = trim(Auth::employeeId());
    if ($id !== '' || Auth::userId() <= 0) {
      return $id;
    }
    return trim((string) $this->db->getVar('SELECT `id_empleado` FROM `' . $this->employees . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]));
  }

  public function now(): string
  {
    return (new \DateTimeImmutable('now', new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE)))->format('Y-m-d H:i:s');
  }

  private function blockLimit(): string
  {
    return (new \DateTimeImmutable('now', new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE)))->modify('-' . self::BLOCK_GRACE_DAYS . ' days')->format('Y-m-d H:i:s');
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function mapEvent(array $row): array
  {
    $row['tipo_item'] = 'evento';
    $row['source_table'] = self::EVENTS;
    $row['meta'] = self::decodeMeta($row['meta_json'] ?? null);
    unset($row['meta_json']);
    return $row;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function mapReminder(array $row): array
  {
    $row['tipo_item'] = 'recordatorio';
    $row['source_table'] = self::REMINDERS;
    $row['fecha_inicio'] = $row['recordatorio_at'];
    $row['fecha_fin'] = $row['recordatorio_at'];
    $row['descripcion'] = $row['mensaje'] ?? '';
    $row['recordatorio_canal'] = $row['canal'] ?? 'email';
    $row['recordatorio_estado'] = $row['estado'] ?? 'pendiente';
    $row['meta'] = self::decodeMeta($row['meta_json'] ?? null);
    unset($row['meta_json']);
    return $row;
  }

  /** @param mixed $value */
  private static function optional($value): ?string
  {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : null;
  }

  /** @param mixed $value */
  private static function metaJson($value): ?string
  {
    if (is_array($value)) {
      return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
      return null;
    }
    json_decode($text, true);
    return json_last_error() === JSON_ERROR_NONE ? $text : json_encode(['raw' => $text], JSON_UNESCAPED_UNICODE);
  }

  /** @param mixed $value @return array<string,mixed> */
  private static function decodeMeta($value): array
  {
    $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
    return is_array($decoded) ? $decoded : [];
  }

  public static function reminderChannel(string $channel): string
  {
    $channel = strtolower(trim($channel));
    return in_array($channel, ['email', 'whatsapp', 'sms'], true) ? $channel : 'email';
  }

  private static function reminderStatus(string $status): string
  {
    $status = strtolower(trim($status));
    return in_array($status, ['pendiente', 'programado', 'finalizado', 'enviado', 'cancelado'], true) ? $status : 'pendiente';
  }
}
