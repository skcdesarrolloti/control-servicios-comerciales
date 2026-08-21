<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\HistoryLinkMap;

final class CommercialTicketsRepository
{
  private Database $db;
  private CommercialSlaService $sla;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->sla = new CommercialSlaService($db);
  }

  /**
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,pagination:array<string,int>,counts:array<string,int>,sla_summary?:array<string,int>}
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
    [$filterWhere, $filterArgs] = $this->ticketFilterClauses($filters);
    $where = array_merge($where, $filterWhere);
    $args = array_merge($args, $filterArgs);

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(60, max(12, (int) ($filters['per_page'] ?? 24)));
    $whereSql = implode(' AND ', $where);

    $selectSql = "SELECT t.`_ID`, t.`id_ticket`, t.`estado_comercial`, t.`asunto`, t.`descripcion`,
              t.`solicitante`, t.`correo_solicitante`, t.`celular_solicitante`,
              t.`id_empleado`, t.`nombre_empleado`, t.`correo_empleado`, t.`celular_empleado`,
              t.`inmueble`, t.`id_inmueble`, t.`direccion`, t.`barrio`, t.`tipo_inmueble`,
              t.`medio`, t.`prioridad`, t.`tema_ayuda`, t.`tuvo_seguimiento`, t.`fecha_seguimiento`,
              t.`fecha`, t.`fecha_actualizacion`, t.`cct_created`, t.`cct_modified`
         FROM `{$table}` t
        WHERE {$whereSql}
        ORDER BY COALESCE(NULLIF(t.`fecha_actualizacion`, 0), NULLIF(t.`fecha`, 0), UNIX_TIMESTAMP(t.`cct_created`)) DESC, t.`_ID` DESC";
    $summary = null;

    if ($bucket === 'abiertos') {
      $allRows = $this->decorateCommercialSla($this->enrichPropertyData($this->db->getResults($selectSql, $args)));
      $summary = $this->sla->summary($allRows);
      $slaFilter = trim((string) ($filters['sla_filter'] ?? ''));
      if (in_array($slaFilter, ['atrasado', 'al_dia'], true)) {
        $allRows = array_values(array_filter($allRows, static function (array $row) use ($slaFilter): bool {
          return (string) ($row['scm_sla_status'] ?? '') === $slaFilter;
        }));
      }
      usort($allRows, static function (array $a, array $b): int {
        $priority = (int) ($b['scm_sla_priority'] ?? 0) <=> (int) ($a['scm_sla_priority'] ?? 0);
        if ($priority !== 0) {
          return $priority;
        }
        $days = (int) ($b['scm_attention_days'] ?? 0) <=> (int) ($a['scm_attention_days'] ?? 0);
        if ($days !== 0) {
          return $days;
        }
        return (int) ($b['_ID'] ?? 0) <=> (int) ($a['_ID'] ?? 0);
      });
      $total = count($allRows);
      $totalPages = max(1, (int) ceil($total / $perPage));
      $page = min($page, $totalPages);
      $offset = ($page - 1) * $perPage;
      $rows = array_slice($allRows, $offset, $perPage);
      $summary['visible_total'] = $total;
    } else {
      $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` t WHERE {$whereSql}", $args);
      $totalPages = max(1, (int) ceil($total / $perPage));
      $page = min($page, $totalPages);
      $offset = ($page - 1) * $perPage;
      $rows = $this->enrichPropertyData($this->db->getResults($selectSql . " LIMIT {$perPage} OFFSET {$offset}", $args));
    }

    $payload = [
      'rows' => $rows,
      'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
      ],
      'counts' => $this->statusCounts($filters),
    ];
    if (is_array($summary)) {
      $payload['sla_summary'] = $summary;
    }

    return $payload;
  }

  /**
   * @return array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>}
   */
  public function detail(int $ticketPk): array
  {
    if ($ticketPk <= 0) {
      throw new \InvalidArgumentException('Ticket inválido.');
    }

    $ticket = $this->db->getRow(
      "SELECT * FROM `{$this->db->table('jet_cct_tickets')}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($ticket)) {
      throw new \RuntimeException('El ticket comercial no existe.');
    }
    $enrichedTickets = $this->enrichPropertyData([$ticket]);
    $ticket = $enrichedTickets[0] ?? $ticket;

    $keys = $this->ticketLookupKeys($ticket, $ticketPk);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    $followUpTable = $this->db->table('jet_cct_seguimiento_ticket');
    $notesTable = $this->db->table('jet_cct_notas_ticket');
    $employeesTable = $this->db->table('jet_cct_funcionarios');
    $timeline = $this->db->getResults(
      "SELECT activity.*
         FROM (
           SELECT h.`_ID`, h.`id_ticket`, h.`fecha`, h.`cct_created`,
                  COALESCE(NULLIF(TRIM(h.`nombre`), ''), NULLIF(TRIM(f.`nombre`), ''), 'Sistema') AS `nombre`,
                  COALESCE(NULLIF(TRIM(h.`id_empleado`), ''), NULLIF(TRIM(h.`cct_author_id`), '')) AS `actor_id`,
                  COALESCE(NULLIF(TRIM(h.`correo`), ''), NULLIF(TRIM(f.`correo`), '')) AS `actor_email`,
                  h.`respuesta` AS `message`, 'respuesta' AS `type`,
                  h.`imagen` AS `image`, h.`archivos` AS `documents`,
                  COALESCE(NULLIF(h.`fecha`, 0), UNIX_TIMESTAMP(h.`cct_created`), 0) AS `_timestamp`
             FROM `{$historyTable}` h
             LEFT JOIN `{$employeesTable}` f
               ON TRIM(f.`id_empleado`) = COALESCE(NULLIF(TRIM(h.`id_empleado`), ''), NULLIF(TRIM(h.`cct_author_id`), ''))
            WHERE CAST(h.`id_ticket` AS CHAR) IN ({$placeholders}) AND TRIM(COALESCE(h.`respuesta`, '')) <> ''
           UNION ALL
           SELECT s.`_ID`, s.`id_ticket`, s.`fecha`, s.`cct_created`,
                  COALESCE(NULLIF(TRIM(s.`nombre`), ''), NULLIF(TRIM(f.`nombre`), ''), 'Sistema') AS `nombre`,
                  COALESCE(NULLIF(TRIM(s.`id_empleado`), ''), NULLIF(TRIM(s.`id_coordinador`), ''), NULLIF(TRIM(s.`cct_author_id`), '')) AS `actor_id`,
                  NULLIF(TRIM(f.`correo`), '') AS `actor_email`,
                  s.`observacion` AS `message`, 'seguimiento' AS `type`,
                  s.`evidencia` AS `image`, '' AS `documents`,
                  COALESCE(NULLIF(s.`fecha`, 0), UNIX_TIMESTAMP(s.`cct_created`), 0) AS `_timestamp`
             FROM `{$followUpTable}` s
             LEFT JOIN `{$employeesTable}` f
               ON TRIM(f.`id_empleado`) = COALESCE(NULLIF(TRIM(s.`id_empleado`), ''), NULLIF(TRIM(s.`id_coordinador`), ''), NULLIF(TRIM(s.`cct_author_id`), ''))
            WHERE CAST(s.`id_ticket` AS CHAR) IN ({$placeholders}) AND TRIM(COALESCE(s.`observacion`, '')) <> ''
           UNION ALL
           SELECT n.`_ID`, n.`id_ticket`, n.`fecha`, n.`cct_created`,
                  COALESCE(NULLIF(TRIM(n.`nombre`), ''), NULLIF(TRIM(f.`nombre`), ''), 'Sistema') AS `nombre`,
                  COALESCE(NULLIF(TRIM(n.`id_empleado`), ''), NULLIF(TRIM(n.`cct_author_id`), '')) AS `actor_id`,
                  NULLIF(TRIM(f.`correo`), '') AS `actor_email`,
                  n.`observacion` AS `message`, 'nota' AS `type`,
                  '' AS `image`, '' AS `documents`,
                  COALESCE(NULLIF(n.`fecha`, 0), UNIX_TIMESTAMP(n.`cct_created`), 0) AS `_timestamp`
             FROM `{$notesTable}` n
             LEFT JOIN `{$employeesTable}` f
               ON TRIM(f.`id_empleado`) = COALESCE(NULLIF(TRIM(n.`id_empleado`), ''), NULLIF(TRIM(n.`cct_author_id`), ''))
            WHERE CAST(n.`id_ticket` AS CHAR) IN ({$placeholders}) AND TRIM(COALESCE(n.`observacion`, '')) <> ''
         ) activity
        ORDER BY activity.`_timestamp` DESC, activity.`_ID` DESC
        LIMIT 100",
      array_merge($keys, $keys, $keys)
    );

    return [
      'ticket' => $ticket,
      'timeline' => $timeline,
    ];
  }

  /** @param array<string,mixed> $filters @return array<string,int> */
  public function statusCounts(array $filters = []): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $statuses = CommercialStatusCatalog::all();
    $where = [
      'TRIM(COALESCE(t.`estado_comercial`, \'\')) IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')',
    ];
    $args = $statuses;
    [$filterWhere, $filterArgs] = $this->ticketFilterClauses($filters);
    $where = array_merge($where, $filterWhere);
    $args = array_merge($args, $filterArgs);

    $rows = $this->db->getResults(
      'SELECT TRIM(COALESCE(t.`estado_comercial`, \'\')) AS estado, COUNT(*) AS total'
      . " FROM `{$table}` t WHERE " . implode(' AND ', $where)
      . ' GROUP BY TRIM(COALESCE(t.`estado_comercial`, \'\'))',
      $args
    );
    $counts = array_fill_keys($statuses, 0);
    foreach ($rows as $row) {
      $counts[(string) ($row['estado'] ?? '')] = (int) ($row['total'] ?? 0);
    }
    return $counts;
  }

  /** @param array<string,mixed> $filters @return array<string,int> */
  public function bucketCounts(array $filters = []): array
  {
    $statusCounts = $this->statusCounts($filters);
    $bucketCounts = [];
    foreach (CommercialStatusCatalog::buckets() as $bucket => $definition) {
      $bucketCounts[$bucket] = array_sum(array_intersect_key($statusCounts, array_flip($definition['statuses'])));
    }
    return $bucketCounts;
  }

  /** @param array<int,string> $cargoIds @return array<int,array{id:string,name:string}> */
  public function ticketEmployees(array $cargoIds = []): array
  {
    $cargoIds = array_values(array_filter(array_map('trim', array_map('strval', $cargoIds)), static fn(string $id): bool => $id !== ''));
    $funcionarios = $this->db->table('jet_cct_funcionarios');
    $whereCargo = '';
    $args = [];
    if ($cargoIds !== []) {
      $whereCargo = ' AND TRIM(COALESCE(f.`id_cargo`, \'\')) IN (' . implode(',', array_fill(0, count($cargoIds), '?')) . ')';
      $args = $cargoIds;
    }

    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(f.`id_empleado`, '')) AS id,
              TRIM(COALESCE(f.`nombre`, '')) AS name
         FROM `{$funcionarios}` f
        WHERE f.`activo` = 'Si'
          AND TRIM(COALESCE(f.`id_empleado`, '')) <> ''
          AND TRIM(COALESCE(f.`nombre`, '')) <> ''
          {$whereCargo}
        ORDER BY TRIM(f.`nombre`) ASC",
      $args
    );

    $grouped = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $name = trim((string) ($row['name'] ?? ''));
      if ($name === '') {
        $name = 'ID ' . $id;
      }
      $key = $this->normalizeEmployeeLabel($name);
      if ($key === '') {
        $key = 'id:' . $id;
      }
      if (!isset($grouped[$key])) {
        $grouped[$key] = ['name' => $name, 'ids' => []];
      }
      $grouped[$key]['ids'][$id] = true;
    }

    if ($grouped !== []) {
      $ticketsTable = $this->db->table('jet_cct_tickets');
      $ticketRows = $this->db->getResults(
        "SELECT TRIM(COALESCE(t.`id_empleado`, '')) AS id,
                TRIM(COALESCE(t.`nombre_empleado`, '')) AS name
           FROM `{$ticketsTable}` t
          WHERE TRIM(COALESCE(t.`estado_comercial`, '')) <> ''
            AND TRIM(COALESCE(t.`id_empleado`, '')) <> ''
            AND TRIM(COALESCE(t.`nombre_empleado`, '')) <> ''"
      );
      foreach ($ticketRows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        $key = $this->normalizeEmployeeLabel((string) ($row['name'] ?? ''));
        if ($id !== '' && isset($grouped[$key])) {
          $grouped[$key]['ids'][$id] = true;
        }
      }
    }

    $employees = [];
    foreach ($grouped as $employee) {
      $ids = array_keys($employee['ids']);
      sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
      $employees[] = [
        'id' => implode(',', array_map('strval', $ids)),
        'name' => (string) $employee['name'],
      ];
    }
    usort($employees, static fn(array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
    return $employees;
  }

  /** @return array{medios:array<int,string>,prioridades:array<int,string>,temas:array<int,string>,barrios:array<int,string>} */
  public function filterOptions(): array
  {
    return [
      'medios' => $this->distinctTicketValues('medio'),
      'prioridades' => $this->distinctTicketValues('prioridad'),
      'temas' => $this->distinctTicketValues('tema_ayuda'),
      'barrios' => $this->neighborhoodOptions(),
    ];
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

  /** @return array<int,string> */
  private function distinctTicketValues(string $column): array
  {
    if (!in_array($column, ['medio', 'prioridad', 'tema_ayuda'], true)) {
      return [];
    }
    $table = $this->db->table('jet_cct_tickets');
    $rows = $this->db->getCol(
      "SELECT DISTINCT TRIM(COALESCE(`{$column}`, '')) AS value
         FROM `{$table}`
        WHERE TRIM(COALESCE(`{$column}`, '')) <> ''
        ORDER BY value ASC"
    );
    return array_values(array_filter(array_map('strval', $rows), static fn(string $value): bool => trim($value) !== ''));
  }

  /**
   * @param array<string,mixed> $filters
   * @return array{0:array<int,string>,1:array<int,mixed>}
   */
  private function ticketFilterClauses(array $filters): array
  {
    $where = [];
    $args = [];

    $search = trim((string) ($filters['busqueda'] ?? ''));
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = "(CAST(t.`_ID` AS CHAR) LIKE ? OR t.`id_ticket` LIKE ? OR t.`asunto` LIKE ? OR t.`descripcion` LIKE ? OR t.`solicitante` LIKE ? OR t.`correo_solicitante` LIKE ? OR t.`celular_solicitante` LIKE ? OR t.`nombre_empleado` LIKE ? OR t.`inmueble` LIKE ? OR t.`id_inmueble` LIKE ? OR t.`direccion` LIKE ? OR t.`barrio` LIKE ? OR t.`tema_ayuda` LIKE ? OR t.`medio` LIKE ? OR t.`prioridad` LIKE ?)";
      array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $employee = trim((string) ($filters['id_empleado'] ?? ''));
    if ($employee !== '') {
      $employeeIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $employee)), static fn(string $id): bool => $id !== '')));
      if (count($employeeIds) > 1) {
        $where[] = 'TRIM(COALESCE(t.`id_empleado`, \'\')) IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
        array_push($args, ...$employeeIds);
      } else {
        $where[] = 'TRIM(COALESCE(t.`id_empleado`, \'\')) = ?';
        $args[] = $employeeIds[0] ?? $employee;
      }
    }

    $ticketId = trim((string) ($filters['ticket_id'] ?? ''));
    if ($ticketId !== '') {
      $like = '%' . $this->db->escapeLike($ticketId) . '%';
      $where[] = '(CAST(t.`_ID` AS CHAR) = ? OR t.`id_ticket` LIKE ?)';
      array_push($args, $ticketId, $like);
    }

    foreach ([
      'solicitante' => 'solicitante',
      'celular' => 'celular_solicitante',
      'correo' => 'correo_solicitante',
    ] as $filterKey => $column) {
      $value = trim((string) ($filters[$filterKey] ?? ''));
      if ($value === '') {
        continue;
      }
      $where[] = "t.`{$column}` LIKE ?";
      $args[] = '%' . $this->db->escapeLike($value) . '%';
    }

    $property = trim((string) ($filters['inmueble'] ?? ''));
    if ($property !== '') {
      $like = '%' . $this->db->escapeLike($property) . '%';
      $where[] = "(t.`inmueble` LIKE ? OR t.`id_inmueble` LIKE ? OR t.`direccion` LIKE ?)";
      array_push($args, $like, $like, $like);
    }

    $neighborhood = trim((string) ($filters['barrio'] ?? ''));
    if ($neighborhood !== '') {
      $propertiesTable = $this->db->table('jet_cct_inmuebles');
      $where[] = "(TRIM(COALESCE(t.`barrio`, '')) = ? OR EXISTS (
        SELECT 1 FROM `{$propertiesTable}` ip
         WHERE TRIM(COALESCE(ip.`barrio`, '')) = ?
           AND (
             CAST(ip.`codigo` AS CHAR) = TRIM(COALESCE(t.`id_inmueble`, ''))
             OR CAST(ip.`_ID` AS CHAR) = TRIM(COALESCE(t.`id_inmueble`, ''))
             OR CAST(ip.`codigo` AS CHAR) = TRIM(COALESCE(t.`id_inmueble_data`, ''))
             OR CAST(ip.`_ID` AS CHAR) = TRIM(COALESCE(t.`id_inmueble_data`, ''))
             OR CAST(ip.`codigo` AS CHAR) = TRIM(COALESCE(t.`inmueble`, ''))
             OR CAST(ip.`_ID` AS CHAR) = TRIM(COALESCE(t.`inmueble`, ''))
           )
      ))";
      array_push($args, $neighborhood, $neighborhood);
    }

    foreach ([
      'medio' => 'medio',
      'prioridad' => 'prioridad',
      'tema' => 'tema_ayuda',
    ] as $filterKey => $column) {
      $value = trim((string) ($filters[$filterKey] ?? ''));
      if ($value === '') {
        continue;
      }
      $where[] = "TRIM(COALESCE(t.`{$column}`, '')) = ?";
      $args[] = $value;
    }

    $followUp = mb_strtolower(trim((string) ($filters['seguimiento'] ?? '')), 'UTF-8');
    if (in_array($followUp, ['si', 'no'], true)) {
      if ($followUp === 'si') {
        $where[] = "LOWER(TRIM(COALESCE(t.`tuvo_seguimiento`, ''))) = 'si'";
      } else {
        $where[] = "(t.`tuvo_seguimiento` IS NULL OR LOWER(TRIM(t.`tuvo_seguimiento`)) <> 'si')";
      }
    }

    foreach ([['fecha_desde', false, '>='], ['fecha_hasta', true, '<=']] as [$filterKey, $endOfDay, $operator]) {
      $timestamp = $this->dateFilterTimestamp(trim((string) ($filters[$filterKey] ?? '')), (bool) $endOfDay);
      if ($timestamp <= 0) {
        continue;
      }
      $where[] = "COALESCE(NULLIF(t.`fecha`, 0), UNIX_TIMESTAMP(t.`cct_created`), 0) {$operator} ?";
      $args[] = $timestamp;
    }

    return [$where, $args];
  }

  /** @return array<int,string> */
  private function neighborhoodOptions(): array
  {
    $barriosTable = $this->db->table('jet_cct_barrios');
    try {
      $rows = $this->db->getCol(
        "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio
           FROM `{$barriosTable}`
          WHERE TRIM(COALESCE(`barrio`, '')) <> ''
          ORDER BY barrio ASC
          LIMIT 700"
      );
      $barrios = array_values(array_filter(array_map('strval', $rows), static fn(string $value): bool => trim($value) !== ''));
      if ($barrios !== []) {
        return $barrios;
      }
    } catch (\Throwable $exception) {
      // Si la tabla no existe en algún entorno, usamos las fuentes operativas del panel.
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    $propertiesTable = $this->db->table('jet_cct_inmuebles');
    $barrios = [];
    foreach ([$ticketsTable, $propertiesTable] as $table) {
      try {
        foreach ($this->db->getCol(
          "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio
             FROM `{$table}`
            WHERE TRIM(COALESCE(`barrio`, '')) <> ''
            ORDER BY barrio ASC
            LIMIT 700"
        ) as $value) {
          $value = trim((string) $value);
          if ($value !== '') {
            $barrios[$value] = true;
          }
        }
      } catch (\Throwable $exception) {
        continue;
      }
    }

    $values = array_keys($barrios);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return array_map('strval', $values);
  }

  private function dateFilterTimestamp(string $date, bool $endOfDay): int
  {
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      return 0;
    }
    $time = $endOfDay ? '23:59:59' : '00:00:00';
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, new \DateTimeZone('America/Bogota'));
    return $dt instanceof \DateTimeImmutable ? $dt->getTimestamp() : 0;
  }

  private function normalizeEmployeeLabel(string $name): string
  {
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $name);
    return preg_replace('/\s+/', ' ', $name) ?? '';
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function decorateCommercialSla(array $rows): array
  {
    return array_map(fn(array $row): array => $this->sla->decorateTicket($row), $rows);
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

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrichPropertyData(array $rows): array
  {
    if ($rows === []) {
      return $rows;
    }

    $propertyKeys = [];
    foreach ($rows as $row) {
      foreach ($this->propertyLookupKeys($row) as $key) {
        $propertyKeys[$key] = true;
      }
    }
    $keys = array_map('strval', array_keys($propertyKeys));
    if ($keys === []) {
      return $rows;
    }

    $propertiesTable = $this->db->table('jet_cct_inmuebles');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $properties = $this->db->getResults(
      "SELECT `_ID`, `codigo`, `barrio`, `ciudad`, `direccion`, `tipo_inmueble`, `estado`
         FROM `{$propertiesTable}`
        WHERE CAST(`codigo` AS CHAR) IN ({$placeholders})
           OR CAST(`_ID` AS CHAR) IN ({$placeholders})",
      array_merge($keys, $keys)
    );
    $propertyByKey = [];
    foreach ($properties as $property) {
      foreach ([(string) ($property['codigo'] ?? ''), (string) ($property['_ID'] ?? '')] as $key) {
        $key = trim($key);
        if ($key !== '' && !isset($propertyByKey[$key])) {
          $propertyByKey[$key] = $property;
        }
      }
    }

    $propertyBaseUrl = (string) (HistoryLinkMap::idButtons()['id_inmueble']['base'] ?? '');
    foreach ($rows as &$row) {
      $property = null;
      $rowPropertyKeys = $this->propertyLookupKeys($row);
      $fallbackPropertyId = $rowPropertyKeys[0] ?? '';
      if ($fallbackPropertyId !== '' && trim((string) ($row['inmueble'] ?? '')) === '') {
        $row['inmueble'] = $fallbackPropertyId;
      }
      if ($propertyBaseUrl !== '' && $fallbackPropertyId !== '') {
        $row['_scm_inmueble_url'] = $propertyBaseUrl . rawurlencode($fallbackPropertyId);
      }
      foreach ($rowPropertyKeys as $key) {
        if (isset($propertyByKey[$key])) {
          $property = $propertyByKey[$key];
          break;
        }
      }
      if (!is_array($property)) {
        continue;
      }

      $row['_scm_inmueble_data'] = $property;
      $propertyCode = trim((string) ($property['codigo'] ?? ''));
      if (trim((string) ($row['inmueble'] ?? '')) === '' && $propertyCode !== '') {
        $row['inmueble'] = $propertyCode;
      }
      foreach (['barrio', 'direccion', 'tipo_inmueble'] as $column) {
        $value = trim((string) ($property[$column] ?? ''));
        if (trim((string) ($row[$column] ?? '')) === '' && $value !== '') {
          $row[$column] = $value;
        }
      }
      if ($propertyBaseUrl !== '' && $propertyCode !== '') {
        $row['_scm_inmueble_url'] = $propertyBaseUrl . rawurlencode($propertyCode);
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<string,mixed> $row
   * @return array<int,string>
   */
  private function propertyLookupKeys(array $row): array
  {
    $keys = [];
    foreach (['id_inmueble', 'id_inmueble_data', 'inmueble'] as $column) {
      foreach (preg_split('/[,;|]+/', (string) ($row[$column] ?? '')) ?: [] as $value) {
        $value = trim($value);
        if ($value !== '') {
          $keys[$value] = true;
        }
      }
    }
    return array_map('strval', array_keys($keys));
  }

  /**
   * @param array<string,mixed> $ticket
   * @return array<int,string>
   */
  private function ticketLookupKeys(array $ticket, int $ticketPk): array
  {
    $keys = [];
    foreach ([(string) $ticketPk, (string) ($ticket['id_ticket'] ?? '')] as $key) {
      $key = trim($key);
      if ($key !== '') {
        $keys[$key] = true;
      }
    }
    return array_map('strval', array_keys($keys)) ?: [(string) $ticketPk];
  }

}
