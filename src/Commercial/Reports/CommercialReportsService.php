<?php

declare(strict_types=1);

namespace SCM\Commercial\Reports;

use SCM\Core\Database;

/**
 * Reportes comerciales (wp_jet_cct_reportes_comerciales) y sus cuentas de cobro. Lógica traída de
 * control-servicios-inmobiliarios/src/Modules/AdministrativeReports/AdministrativeReportsService.php.
 *
 * Las cuentas se guardan en la misma tabla que las administrativas (wp_jet_cct_cuentas_cobro). Esa tabla solo guarda los
 * números de reporte y los consecutivos comerciales y administrativos se repiten, así que el prefijo CCO- del número de cuenta
 * dice a qué tabla pertenecen: cada panel debe trabajar únicamente con las suyas.
 * Los totales se calculan siempre aquí, desde la base de datos.
 */
final class CommercialReportsService
{
  public const ACCOUNT_PREFIX = 'CCO-';
  public const OWN_STATES = ['no_exportados', 'en_cobro', 'pagados'];
  public const CONSOLIDATED_STATES = ['sin_exportar', 'no_pagados', 'pagados'];
  public const PER_PAGE = 40;
  public const COMPANY_NAME = 'SOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S.';
  public const COMPANY_NIT = '900623242-4';

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  private function reports(): string
  {
    return $this->db->table('jet_cct_reportes_comerciales');
  }

  private function accountsTable(): string
  {
    return $this->db->table('jet_cct_cuentas_cobro');
  }

  public static function isYes(mixed $value): bool
  {
    return strcasecmp(trim((string) $value), 'Si') === 0;
  }

  public static function money(mixed $value): int
  {
    $raw = preg_replace('/[^\d]/', '', (string) $value) ?? '';
    return $raw === '' ? 0 : (int) $raw;
  }

  /** @param array<int,mixed> $ids @return array<int,int> */
  public static function cleanIds(array $ids): array
  {
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
  }

  /** @return array<int,int> */
  public static function parseIds(mixed $raw): array
  {
    if (is_array($raw)) {
      return self::cleanIds($raw);
    }
    preg_match_all('/\d+/', (string) $raw, $matches);
    return self::cleanIds($matches[0]);
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  public static function present(array $row): array
  {
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'fecha' => (int) ($row['fecha'] ?? 0),
      'tipo' => trim((string) ($row['tipo_reporte'] ?? '')),
      'valor' => max(0, (int) ($row['valor'] ?? 0)),
      'id_ticket' => trim((string) ($row['id_ticket'] ?? '')),
      'id_inmueble' => trim((string) ($row['id_inmueble'] ?? '')),
      'observacion' => (string) ($row['observacion'] ?? ''),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'funcionario' => trim((string) ($row['funcionario'] ?? '')),
      'exportado' => self::isYes($row['exportado'] ?? ''),
      'pagado' => self::isYes($row['fue_pagado'] ?? ''),
    ];
  }

  /** @param array<int,array<string,mixed>> $items @return array{count:int,total:int} */
  public static function summarize(array $items): array
  {
    return ['count' => count($items), 'total' => array_sum(array_column($items, 'valor'))];
  }

  /** @param array<string,mixed> $item @param array<string,string> $filters */
  private static function matches(array $item, array $filters): bool
  {
    foreach (['consecutivo' => 'id', 'tipo' => 'tipo', 'ticket' => 'id_ticket', 'inmueble' => 'id_inmueble'] as $filter => $field) {
      $needle = mb_strtolower(trim((string) ($filters[$filter] ?? '')), 'UTF-8');
      if ($needle !== '' && !str_contains(mb_strtolower((string) $item[$field], 'UTF-8'), $needle)) {
        return false;
      }
    }
    $employee = trim((string) ($filters['funcionario'] ?? ''));
    if ($employee !== '' && $employee !== $item['id_empleado']) {
      return false;
    }
    $day = $item['fecha'] > 0 ? date('Y-m-d', (int) $item['fecha']) : '';
    $from = trim((string) ($filters['desde'] ?? ''));
    $to = trim((string) ($filters['hasta'] ?? ''));
    return ($from === '' || $day >= $from) && ($to === '' || ($day !== '' && $day <= $to));
  }

  /** @return array{items:array<int,array<string,mixed>>,summary:array{count:int,total:int},counts:array<string,int>} */
  public function ownReports(string $employeeId, string $state, array $filters = []): array
  {
    $state = in_array($state, self::OWN_STATES, true) ? $state : 'no_exportados';
    $counts = array_fill_keys(self::OWN_STATES, 0);
    if ($employeeId === '') {
      return ['items' => [], 'summary' => self::summarize([]), 'counts' => $counts];
    }
    $items = [];
    foreach ($this->db->getResults('SELECT * FROM `' . $this->reports() . "` WHERE TRIM(COALESCE(`id_empleado`, '')) = ? ORDER BY `_ID` DESC", [$employeeId]) as $row) {
      $item = self::present($row);
      $itemState = $item['pagado'] ? 'pagados' : ($item['exportado'] ? 'en_cobro' : 'no_exportados');
      $counts[$itemState]++;
      if ($itemState === $state && self::matches($item, $filters)) {
        $items[] = $item;
      }
    }
    return ['items' => $items, 'summary' => self::summarize($items), 'counts' => $counts];
  }

  /** @return array<string,mixed> */
  public function consolidated(string $state, array $filters, int $page): array
  {
    $state = in_array($state, self::CONSOLIDATED_STATES, true) ? $state : 'sin_exportar';
    $counts = array_fill_keys(self::CONSOLIDATED_STATES, 0);
    $items = [];
    $employees = [];
    foreach ($this->db->getResults('SELECT * FROM `' . $this->reports() . '` ORDER BY `_ID` DESC') as $row) {
      $item = self::present($row);
      $itemState = $item['pagado'] ? 'pagados' : ($item['exportado'] ? 'no_pagados' : 'sin_exportar');
      $counts[$itemState]++;
      if ($item['id_empleado'] !== '') {
        $employees[$item['id_empleado']] = $item['funcionario'] !== '' ? $item['funcionario'] : $item['id_empleado'];
      }
      if ($itemState === $state && self::matches($item, $filters)) {
        $items[] = $item;
      }
    }
    asort($employees);
    $pages = max(1, (int) ceil(count($items) / self::PER_PAGE));
    $page = min(max(1, $page), $pages);
    return [
      'items' => array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
      'summary' => self::summarize($items),
      'counts' => $counts,
      'page' => $page,
      'pages' => $pages,
      'employees' => array_map(static fn($id, $name): array => ['id' => (string) $id, 'nombre' => (string) $name], array_keys($employees), $employees),
    ];
  }

  /** Marca pagados reportes que ya están en una cuenta de cobro. @param array<int,int> $ids */
  public function markReportsPaid(array $ids): int
  {
    $ids = self::cleanIds($ids);
    if ($ids === []) {
      return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $statement = $this->db->pdo()->prepare('UPDATE `' . $this->reports() . "` SET `fue_pagado` = 'Si' WHERE `_ID` IN ({$marks}) AND `exportado` = 'Si' AND COALESCE(`fue_pagado`, '') <> 'Si'");
    $statement->execute($ids);
    return $statement->rowCount();
  }

  /** Corrige el valor solo mientras el reporte no esté en una cuenta de cobro. */
  public function updateValue(int $id, int $value): void
  {
    $row = $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->reports() . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
    if ($row === null) {
      throw new \DomainException('Reporte no encontrado.');
    }
    if (self::isYes($row['exportado'] ?? '') || self::isYes($row['fue_pagado'] ?? '')) {
      throw new \DomainException('El reporte ya está en una cuenta de cobro o pagado; no se puede cambiar su valor.');
    }
    $this->db->update($this->reports(), ['valor' => $value, 'cct_modified' => date('Y-m-d H:i:s')], ['_ID' => $id]);
  }

  // ── Cuentas de cobro ───────────────────────────────

  private static function isCommercialNumber(string $number): bool
  {
    return str_starts_with($number, self::ACCOUNT_PREFIX);
  }

  public static function accountPaid(array $account): bool
  {
    return strcasecmp(trim((string) ($account['fue_pagada'] ?? '')), 'Pagada') === 0;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private static function presentAccount(array $row): array
  {
    $created = trim((string) ($row['fecha_creacion'] ?? ''));
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'numero' => trim((string) ($row['numero_cuenta'] ?? '')),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'funcionario' => trim((string) ($row['nombre_funcionario'] ?? '')),
      'documento' => trim((string) ($row['documento_funcionario'] ?? '')),
      'fecha_creacion' => $created,
      'fecha' => substr($created, 0, 10),
      'fecha_inicio' => trim((string) ($row['fecha_inicio'] ?? '')),
      'fecha_fin' => trim((string) ($row['fecha_fin'] ?? '')),
      'total' => (int) ($row['total_general'] ?? 0),
      'cantidad' => (int) ($row['cantidad_reportes'] ?? 0),
      'pagada' => self::accountPaid($row),
      'report_ids' => self::parseIds($row['reportes_incluidos'] ?? ''),
    ];
  }

  private function accountSelect(): string
  {
    $staff = $this->db->table('jet_cct_funcionarios');
    return "SELECT c.*, f.nombre AS nombre_funcionario, f.documento AS documento_funcionario
              FROM `{$this->accountsTable()}` c
              LEFT JOIN (SELECT TRIM(COALESCE(`id_empleado`, '')) AS emp, MAX(`nombre`) AS nombre, MAX(`documento`) AS documento FROM `{$staff}` GROUP BY TRIM(COALESCE(`id_empleado`, ''))) f
                ON f.emp = TRIM(COALESCE(c.`id_empleado`, ''))
             WHERE c.`numero_cuenta` LIKE ?";
  }

  /** @return array{items:array<int,array<string,mixed>>,counts:array<string,int>,totals:array<string,int>} */
  public function accounts(array $filters, string $employeeId = ''): array
  {
    $result = ['items' => [], 'counts' => ['total' => 0, 'pagadas' => 0, 'pendientes' => 0], 'totals' => ['pendiente' => 0, 'pagado' => 0]];
    $sql = $this->accountSelect();
    $args = [self::ACCOUNT_PREFIX . '%'];
    if ($employeeId !== '') {
      $sql .= " AND TRIM(COALESCE(c.`id_empleado`, '')) = ?";
      $args[] = $employeeId;
    }
    $number = mb_strtolower(trim((string) ($filters['numero'] ?? '')), 'UTF-8');
    $employee = mb_strtolower(trim((string) ($filters['funcionario'] ?? '')), 'UTF-8');
    $from = trim((string) ($filters['desde'] ?? ''));
    $to = trim((string) ($filters['hasta'] ?? ''));
    $state = trim((string) ($filters['estado'] ?? ''));
    foreach ($this->db->getResults($sql . ' ORDER BY c.`_ID` DESC', $args) as $row) {
      $account = self::presentAccount($row);
      $result['counts']['total']++;
      $result['counts'][$account['pagada'] ? 'pagadas' : 'pendientes']++;
      $result['totals'][$account['pagada'] ? 'pagado' : 'pendiente'] += $account['total'];
      if (($number !== '' && !str_contains(mb_strtolower($account['numero'], 'UTF-8'), $number))
        || ($employee !== '' && !str_contains(mb_strtolower($account['funcionario'], 'UTF-8'), $employee))
        || ($from !== '' && $account['fecha'] < $from) || ($to !== '' && $account['fecha'] > $to)
        || ($state === 'pagada' && !$account['pagada']) || ($state === 'pendiente' && $account['pagada'])) {
        continue;
      }
      $result['items'][] = $account;
    }
    return $result;
  }

  /** @return array<string,mixed>|null Solo cuentas comerciales. */
  public function account(int $id): ?array
  {
    if ($id <= 0) {
      return null;
    }
    $row = $this->db->getRow($this->accountSelect() . ' AND c.`_ID` = ? LIMIT 1', [self::ACCOUNT_PREFIX . '%', $id]);
    if ($row === null) {
      return null;
    }
    $account = self::presentAccount($row);
    $account['reportes'] = [];
    if ($account['report_ids'] !== []) {
      $marks = implode(',', array_fill(0, count($account['report_ids']), '?'));
      foreach ($this->db->getResults('SELECT * FROM `' . $this->reports() . "` WHERE `_ID` IN ({$marks}) ORDER BY `_ID` DESC", $account['report_ids']) as $report) {
        $account['reportes'][] = self::present($report);
      }
    }
    return $account;
  }

  /**
   * Crea la cuenta con reportes propios, sin exportar y sin pagar. La marca de exportado se hace primero y exige que cambien
   * todas las filas, así un mismo reporte no puede quedar en dos cuentas.
   * @param array<int,int> $ids
   * @return array{id:int,numero:string,summary:array{count:int,total:int}}
   */
  public function createAccount(string $employeeId, array $ids): array
  {
    $ids = self::cleanIds($ids);
    if ($employeeId === '') {
      throw new \DomainException('Tu usuario no tiene un id de empleado asociado.');
    }
    if ($ids === []) {
      throw new \DomainException('Selecciona al menos un reporte.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $claim = $pdo->prepare('UPDATE `' . $this->reports() . "` SET `exportado` = 'Si' WHERE `_ID` IN ({$marks}) AND TRIM(COALESCE(`id_empleado`, '')) = ? AND COALESCE(`exportado`, '') <> 'Si' AND COALESCE(`fue_pagado`, '') <> 'Si'");
      $claim->execute(array_merge($ids, [$employeeId]));
      if ($claim->rowCount() !== count($ids)) {
        throw new \DomainException('Algunos reportes ya están en otra cuenta de cobro o no te pertenecen. Recarga la lista e intenta de nuevo.');
      }
      $items = array_map([self::class, 'present'], $this->db->getResults('SELECT * FROM `' . $this->reports() . "` WHERE `_ID` IN ({$marks})", $ids));
      $summary = self::summarize($items);
      $dates = array_filter(array_column($items, 'fecha'));
      $now = date('Y-m-d H:i:s');
      $this->db->insert($this->accountsTable(), [
        'cct_status' => 'publish', 'cct_author_id' => $employeeId, 'cct_created' => $now, 'cct_modified' => $now,
        'numero_cuenta' => self::ACCOUNT_PREFIX, 'id_empleado' => $employeeId, 'fecha_creacion' => $now,
        'fecha_inicio' => date('Y-m-d', $dates !== [] ? min($dates) : time()), 'fecha_fin' => date('Y-m-d', $dates !== [] ? max($dates) : time()),
        'total_servicio' => (string) $summary['total'], 'total_transporte' => '0', 'total_general' => (string) $summary['total'],
        'cantidad_reportes' => (string) count($items), 'reportes_incluidos' => (string) json_encode(array_map('strval', array_column($items, 'id'))), 'fue_pagada' => 'No pagada',
      ]);
      $accountId = (int) $this->db->lastInsertId();
      $number = self::ACCOUNT_PREFIX . str_pad((string) $accountId, 6, '0', STR_PAD_LEFT);
      $this->db->update($this->accountsTable(), ['numero_cuenta' => $number, 'archivo_pdf' => 'cuenta_cobro_' . $number . '.pdf'], ['_ID' => $accountId]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['id' => $accountId, 'numero' => $number, 'summary' => $summary];
  }

  /**
   * Paga cuentas comerciales y sus reportes. Una cuenta administrativa se ignora aunque llegue su id.
   * @param array<int,int> $ids
   * @return array{accounts:int,reports:int}
   */
  public function markAccountsPaid(array $ids): array
  {
    $ids = self::cleanIds($ids);
    $result = ['accounts' => 0, 'reports' => 0];
    if ($ids === []) {
      return $result;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $reportIds = [];
      $accountIds = [];
      foreach ($this->db->getResults('SELECT `_ID`, `numero_cuenta`, `reportes_incluidos` FROM `' . $this->accountsTable() . "` WHERE `_ID` IN ({$marks})", $ids) as $row) {
        if (self::isCommercialNumber(trim((string) ($row['numero_cuenta'] ?? '')))) {
          $accountIds[] = (int) $row['_ID'];
          $reportIds = array_merge($reportIds, self::parseIds($row['reportes_incluidos'] ?? ''));
        }
      }
      if ($accountIds !== []) {
        $accountMarks = implode(',', array_fill(0, count($accountIds), '?'));
        $accounts = $pdo->prepare('UPDATE `' . $this->accountsTable() . "` SET `fue_pagada` = 'Pagada' WHERE `_ID` IN ({$accountMarks}) AND COALESCE(`fue_pagada`, '') <> 'Pagada'");
        $accounts->execute($accountIds);
        $result['accounts'] = $accounts->rowCount();
      }
      $reportIds = self::cleanIds($reportIds);
      if ($reportIds !== []) {
        $reportMarks = implode(',', array_fill(0, count($reportIds), '?'));
        $reports = $pdo->prepare('UPDATE `' . $this->reports() . "` SET `fue_pagado` = 'Si' WHERE `_ID` IN ({$reportMarks}) AND COALESCE(`fue_pagado`, '') <> 'Si'");
        $reports->execute($reportIds);
        $result['reports'] = $reports->rowCount();
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return $result;
  }

  /** @return array{nombre:string,documento:string,correo:string} */
  public function employee(string $employeeId): array
  {
    $row = $employeeId === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . "` WHERE TRIM(COALESCE(`id_empleado`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [$employeeId]);
    return [
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'documento' => trim((string) ($row['documento'] ?? '')),
      'correo' => trim((string) ($row['correo_dian'] ?? '')) ?: trim((string) ($row['correo'] ?? '')),
    ];
  }
}
