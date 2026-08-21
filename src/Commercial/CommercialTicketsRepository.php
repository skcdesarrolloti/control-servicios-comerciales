<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;

final class CommercialTicketsRepository
{
  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /**
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,pagination:array<string,int>,counts:array<string,int>}
   */
  public function search(string $bucket, array $filters = []): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $statuses = CommercialStatusCatalog::statusesForBucket($bucket);
    $status = trim((string) ($filters['estado'] ?? ''));
    if ($status !== '' && in_array($status, $statuses, true)) {
      $statuses = [$status];
    }

    $where = ['TRIM(COALESCE(t.`estado_comercial`, \'\')) IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
    $args = $statuses;
    $search = trim((string) ($filters['busqueda'] ?? ''));
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = "(CAST(t.`_ID` AS CHAR) LIKE ? OR t.`id_ticket` LIKE ? OR t.`asunto` LIKE ? OR t.`descripcion` LIKE ? OR t.`solicitante` LIKE ? OR t.`nombre_empleado` LIKE ? OR t.`inmueble` LIKE ? OR t.`direccion` LIKE ?)";
      array_push($args, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $employee = trim((string) ($filters['id_empleado'] ?? ''));
    if ($employee !== '') {
      $where[] = 'TRIM(COALESCE(t.`id_empleado`, \'\')) = ?';
      $args[] = $employee;
    }

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(60, max(12, (int) ($filters['per_page'] ?? 24)));
    $whereSql = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` t WHERE {$whereSql}", $args);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = $this->db->getResults(
      "SELECT t.`_ID`, t.`id_ticket`, t.`estado_comercial`, t.`asunto`, t.`descripcion`,
              t.`solicitante`, t.`correo_solicitante`, t.`celular_solicitante`,
              t.`id_empleado`, t.`nombre_empleado`, t.`correo_empleado`, t.`celular_empleado`,
              t.`inmueble`, t.`id_inmueble`, t.`direccion`, t.`barrio`, t.`tipo_inmueble`,
              t.`medio`, t.`prioridad`, t.`fecha`, t.`fecha_actualizacion`, t.`cct_created`
         FROM `{$table}` t
        WHERE {$whereSql}
        ORDER BY COALESCE(NULLIF(t.`fecha_actualizacion`, 0), NULLIF(t.`fecha`, 0), UNIX_TIMESTAMP(t.`cct_created`)) DESC, t.`_ID` DESC
        LIMIT {$perPage} OFFSET {$offset}",
      $args
    );

    return [
      'rows' => $rows,
      'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
      ],
      'counts' => $this->statusCounts(),
    ];
  }

  /** @return array<string,int> */
  public function statusCounts(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $statuses = CommercialStatusCatalog::all();
    $rows = $this->db->getResults(
      'SELECT TRIM(COALESCE(`estado_comercial`, \'\')) AS estado, COUNT(*) AS total'
      . " FROM `{$table}` WHERE TRIM(COALESCE(`estado_comercial`, '')) IN ("
      . implode(',', array_fill(0, count($statuses), '?'))
      . ') GROUP BY TRIM(COALESCE(`estado_comercial`, \'\'))',
      $statuses
    );
    $counts = array_fill_keys($statuses, 0);
    foreach ($rows as $row) {
      $counts[(string) ($row['estado'] ?? '')] = (int) ($row['total'] ?? 0);
    }
    return $counts;
  }

  /** @return array<int,array{id:string,name:string}> */
  public function ticketEmployees(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $funcionarios = $this->db->table('jet_cct_funcionarios');
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(t.`id_empleado`, '')) AS id,
              COALESCE(NULLIF(MAX(TRIM(f.`nombre`)), ''), NULLIF(MAX(TRIM(t.`nombre_empleado`)), ''), CONCAT('ID ', t.`id_empleado`)) AS name
         FROM `{$table}` t
         LEFT JOIN `{$funcionarios}` f ON TRIM(COALESCE(f.`id_empleado`, '')) = TRIM(COALESCE(t.`id_empleado`, '')) AND f.`activo` = 'Si'
        WHERE TRIM(COALESCE(t.`estado_comercial`, '')) <> ''
          AND TRIM(COALESCE(t.`id_empleado`, '')) <> ''
        GROUP BY TRIM(t.`id_empleado`)
        ORDER BY name ASC"
    );
    return array_map(static fn(array $row): array => [
      'id' => (string) ($row['id'] ?? ''),
      'name' => (string) ($row['name'] ?? ''),
    ], $rows);
  }

  /** @param array<int,string> $cargoIds @return array<int,array<string,string>> */
  public function activeEmployeesByCargos(array $cargoIds): array
  {
    $cargoIds = array_values(array_filter(array_map('strval', $cargoIds)));
    if ($cargoIds === []) {
      return [];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    $rows = $this->db->getResults(
      "SELECT `_ID`, TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              TRIM(COALESCE(`nombre`, '')) AS nombre,
              TRIM(COALESCE(`correo`, '')) AS correo,
              TRIM(COALESCE(`celular`, '')) AS celular,
              TRIM(COALESCE(`id_cargo`, '')) AS id_cargo
         FROM `{$table}`
        WHERE `activo` = 'Si'
          AND TRIM(COALESCE(`id_cargo`, '')) IN (" . implode(',', array_fill(0, count($cargoIds), '?')) . ")
        ORDER BY `nombre` ASC",
      $cargoIds
    );

    return array_map(static function (array $row): array {
      $id = trim((string) ($row['id_empleado'] ?? ''));
      if ($id === '') {
        $id = (string) ($row['_ID'] ?? '');
      }
      return [
        '_pk' => (string) ($row['_ID'] ?? ''),
        'id' => $id,
        'id_empleado' => $id,
        'nombre' => (string) ($row['nombre'] ?? ''),
        'correo' => (string) ($row['correo'] ?? ''),
        'celular' => (string) ($row['celular'] ?? ''),
        'id_cargo' => (string) ($row['id_cargo'] ?? ''),
      ];
    }, $rows);
  }

  public function changeStatus(int $ticketPk, string $status): void
  {
    if ($ticketPk <= 0 || !CommercialStatusCatalog::isValid($status)) {
      throw new \InvalidArgumentException('Ticket o estado comercial inválido.');
    }
    $ticket = $this->ticket($ticketPk);
    $previous = trim((string) ($ticket['estado_comercial'] ?? ''));
    if ($previous === $status) {
      return;
    }

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->update($this->db->table('jet_cct_tickets'), [
        'estado_comercial' => $status,
        'fecha_actualizacion' => time(),
      ], ['_ID' => $ticketPk]);
      $this->insertHistory($ticketPk, sprintf('Estado comercial actualizado de "%s" a "%s".', $previous ?: 'Sin estado', $status));
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
  }

  /** @param array<int,string> $allowedCargos */
  public function reassign(int $ticketPk, string $employeeId, array $allowedCargos): void
  {
    if ($ticketPk <= 0 || trim($employeeId) === '') {
      throw new \InvalidArgumentException('Ticket o funcionario inválido.');
    }
    $employees = $this->activeEmployeesByCargos($allowedCargos);
    $target = null;
    foreach ($employees as $employee) {
      if ((string) ($employee['id'] ?? '') === trim($employeeId)) {
        $target = $employee;
        break;
      }
    }
    if (!is_array($target)) {
      throw new \InvalidArgumentException('El funcionario no pertenece a un cargo comercial permitido.');
    }

    $ticket = $this->ticket($ticketPk);
    $previous = trim((string) ($ticket['nombre_empleado'] ?? $ticket['id_empleado'] ?? 'Sin asignar'));
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->update($this->db->table('jet_cct_tickets'), [
        'id_empleado' => (string) $target['id'],
        'empleado' => (string) $target['nombre'],
        'nombre_empleado' => (string) $target['nombre'],
        'correo_empleado' => (string) $target['correo'],
        'celular_empleado' => (string) $target['celular'],
        'fecha_actualizacion' => time(),
      ], ['_ID' => $ticketPk]);
      $this->insertHistory($ticketPk, sprintf('Responsable comercial actualizado de "%s" a "%s".', $previous, (string) $target['nombre']));
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
  }

  /** @return array<string,mixed> */
  private function ticket(int $ticketPk): array
  {
    $row = $this->db->getRow(
      "SELECT `_ID`, `estado_comercial`, `id_empleado`, `nombre_empleado` FROM `{$this->db->table('jet_cct_tickets')}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($row)) {
      throw new \RuntimeException('El ticket comercial no existe.');
    }
    return $row;
  }

  private function insertHistory(int $ticketPk, string $message): void
  {
    $now = date('Y-m-d H:i:s');
    $this->db->insert($this->db->table('jet_cct_historial_del_ticket'), [
      'cct_status' => 'publish',
      'cct_author_id' => Auth::userId(),
      'cct_created' => $now,
      'cct_modified' => $now,
      'id_ticket' => $ticketPk,
      'fecha' => time(),
      'nombre' => Auth::user(),
      'id_empleado' => Auth::employeeId() !== '' ? Auth::employeeId() : (string) Auth::userId(),
      'respuesta' => $message,
    ]);
  }
}
