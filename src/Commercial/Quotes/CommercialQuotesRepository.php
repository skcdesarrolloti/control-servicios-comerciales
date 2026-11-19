<?php

declare(strict_types=1);

namespace SCM\Commercial\Quotes;

use SCM\Core\Database;
use SCM\Precaptacion\Repository as ColumnsReader;

/**
 * Tablas de las cotizaciones de inmuebles: `jet_cct_cotizacion_inmuebles` (la misma del formulario JetFormBuilder
 * "Crear cotización de inmuebles", que leen el portal del cliente, reportes, destacados y el calendario), los inmuebles y
 * los historiales. `inmuebles` guarda los códigos serializados como lo hacía JetEngine: el código del inmueble es el ID
 * de su post de WordPress, así que las cotizaciones viejas y nuevas se leen igual.
 */
final class CommercialQuotesRepository
{
  public const TABLE = 'jet_cct_cotizacion_inmuebles';
  /** Estados con los que un inmueble se puede cotizar. */
  public const PUBLIC_STATES = ['Publico', 'Publicado'];
  /** Inmuebles que se leen para elegir: los públicos más recientes. */
  private const CANDIDATE_LIMIT = 1500;

  private Database $db;
  private ColumnsReader $columns;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->columns = new ColumnsReader($db);
  }

  public function database(): Database
  {
    return $this->db;
  }

  /**
   * Inmuebles públicos para elegir, con los filtros de texto en SQL; precio, habitaciones y estado de actualización se
   * filtran después en el servicio.
   * @param array<string,string> $filters
   * @return list<array<string,mixed>>
   */
  public function candidates(array $filters): array
  {
    $in = implode(',', array_fill(0, count(self::PUBLIC_STATES), '?'));
    $where = ["TRIM(i.`estado`) IN ({$in})"];
    $args = self::PUBLIC_STATES;
    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
      $like = '%' . $q . '%';
      $where[] = '(CAST(i.`codigo` AS CHAR) = ? OR i.`barrio` LIKE ? OR i.`ciudad` LIKE ? OR i.`copropiedad` LIKE ? OR i.`tipo_inmueble` LIKE ?)';
      array_push($args, $q, $like, $like, $like, $like);
    }
    $business = trim($filters['tipo'] ?? '');
    if ($business !== '') {
      $where[] = 'LOWER(i.`tipo_negocio`) LIKE ?';
      $args[] = '%' . mb_strtolower($business, 'UTF-8') . '%';
    }
    foreach (['tipo_inmueble', 'barrio', 'ciudad'] as $column) {
      $value = trim($filters[$column] ?? '');
      if ($value !== '') {
        $where[] = "TRIM(i.`{$column}`) = ?";
        $args[] = $value;
      }
    }
    return $this->db->getResults(
      'SELECT i.*, f.`id_sucursal` AS func_sucursal FROM `' . $this->db->table('jet_cct_inmuebles') . '` i
         LEFT JOIN `' . $this->db->table('jet_cct_funcionarios') . "` f ON (TRIM(COALESCE(i.`id_funcionario`, '')) <> '' AND TRIM(f.`id_empleado`) = TRIM(i.`id_funcionario`))
        WHERE " . implode(' AND ', $where) . '
        ORDER BY i.`_ID` DESC
        LIMIT ' . self::CANDIDATE_LIMIT,
      $args
    );
  }

  /**
   * Valores para los filtros del buscador, solo de inmuebles públicos.
   * @return array{tipos_inmueble:list<string>,barrios:list<string>,ciudades:list<string>}
   */
  public function options(): array
  {
    $in = implode(',', array_fill(0, count(self::PUBLIC_STATES), '?'));
    $table = $this->db->table('jet_cct_inmuebles');
    $out = [];
    foreach (['tipo_inmueble' => 'tipos_inmueble', 'barrio' => 'barrios', 'ciudad' => 'ciudades'] as $column => $key) {
      $values = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $this->db->getCol(
        "SELECT DISTINCT TRIM(`{$column}`) FROM `{$table}` WHERE TRIM(`estado`) IN ({$in}) AND TRIM(COALESCE(`{$column}`, '')) <> ''",
        self::PUBLIC_STATES
      )), static fn(string $value): bool => $value !== ''));
      natcasesort($values);
      $out[$key] = array_values(array_unique($values));
    }
    return $out;
  }

  /**
   * Inmuebles por código (cualquier estado), en el orden pedido.
   * @param list<string> $codes
   * @return list<array<string,mixed>>
   */
  public function propertiesByCodes(array $codes): array
  {
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static fn(string $code): bool => $code !== '')));
    if ($codes === []) {
      return [];
    }
    $rows = $this->db->getResults(
      'SELECT i.*, f.`id_sucursal` AS func_sucursal FROM `' . $this->db->table('jet_cct_inmuebles') . '` i
         LEFT JOIN `' . $this->db->table('jet_cct_funcionarios') . "` f ON (TRIM(COALESCE(i.`id_funcionario`, '')) <> '' AND TRIM(f.`id_empleado`) = TRIM(i.`id_funcionario`))
        WHERE CAST(i.`codigo` AS CHAR) IN (" . implode(',', array_fill(0, count($codes), '?')) . ')',
      $codes
    );
    $byCode = [];
    foreach ($rows as $row) {
      $byCode[trim((string) $row['codigo'])] = $row;
    }
    $out = [];
    foreach ($codes as $code) {
      if (isset($byCode[$code])) {
        $out[] = $byCode[$code];
      }
    }
    return $out;
  }

  /**
   * URL de los adjuntos de WordPress (foto de portada y galería).
   * @param list<int> $ids
   * @return array<int,string>
   */
  public function mediaUrls(array $ids): array
  {
    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
      return [];
    }
    $map = [];
    foreach ($this->db->getResults('SELECT `ID`, `guid` FROM `' . $this->db->table('posts') . '` WHERE `ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
      $map[(int) $row['ID']] = (string) $row['guid'];
    }
    return $map;
  }

  /** @return array<string,mixed>|null */
  public function find(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table(self::TABLE) . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /** @return list<array<string,mixed>> Cotizaciones de una tarea, la más reciente primero. */
  public function forTask(int $ticketId): array
  {
    return $ticketId > 0 ? $this->db->getResults('SELECT * FROM `' . $this->db->table(self::TABLE) . '` WHERE CAST(`id_ticket` AS CHAR) = ? ORDER BY `_ID` DESC LIMIT 30', [(string) $ticketId]) : [];
  }

  /**
   * Una página del listado. $scope: null = todo el equipo; id_empleado = solo las de la persona.
   * @param array<string,string> $filters
   * @return array{rows:list<array<string,mixed>>,total:int,mes:int}
   */
  public function search(array $filters, ?string $scope, int $page, int $perPage): array
  {
    $table = $this->db->table(self::TABLE);
    $where = ['1=1'];
    $args = [];
    if ($scope !== null) {
      $where[] = 'TRIM(COALESCE(q.`id_empleado`, \'\')) = ?';
      $args[] = $scope;
    }
    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
      $like = '%' . $q . '%';
      $where[] = '(CAST(q.`_ID` AS CHAR) = ? OR CAST(q.`id_ticket` AS CHAR) = ? OR q.`destinatario` LIKE ? OR q.`email` LIKE ? OR q.`celular` LIKE ? OR q.`inmuebles` LIKE ?)';
      array_push($args, $q, $q, $like, $like, $like, '%"' . $q . '"%');
    }
    if (trim($filters['tipo'] ?? '') !== '') {
      $where[] = 'TRIM(q.`tipo`) = ?';
      $args[] = trim($filters['tipo']);
    }
    if ($scope === null && trim($filters['empleado'] ?? '') !== '') {
      $where[] = 'TRIM(COALESCE(q.`id_empleado`, \'\')) = ?';
      $args[] = trim($filters['empleado']);
    }
    foreach (['desde' => '>=', 'hasta' => '<'] as $key => $operator) {
      $day = trim($filters[$key] ?? '');
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1) {
        $ts = strtotime($day . ' 00:00:00' . ($key === 'hasta' ? ' +1 day' : ''));
        if ($ts !== false) {
          $where[] = "CAST(COALESCE(q.`fecha`, 0) AS SIGNED) {$operator} ?";
          $args[] = $ts;
        }
      }
    }
    $whereSql = implode(' AND ', $where);
    $monthStart = strtotime(date('Y-m-01 00:00:00')) ?: 0;
    $totals = $this->db->getRow("SELECT COUNT(*) AS total, SUM(CASE WHEN CAST(COALESCE(q.`fecha`, 0) AS SIGNED) >= ? THEN 1 ELSE 0 END) AS mes FROM `{$table}` q WHERE {$whereSql}", array_merge([$monthStart], $args)) ?? [];
    $offset = max(0, ($page - 1) * $perPage);
    $rows = $this->db->getResults("SELECT q.* FROM `{$table}` q WHERE {$whereSql} ORDER BY q.`_ID` DESC LIMIT {$perPage} OFFSET {$offset}", $args);
    return ['rows' => $rows, 'total' => (int) ($totals['total'] ?? 0), 'mes' => (int) ($totals['mes'] ?? 0)];
  }

  /** @return array<string,mixed>|null */
  public function task(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /** @return array<string,mixed>|null */
  public function employee(string $id): ?array
  {
    return $id === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `id_empleado` = ? LIMIT 1', [$id]);
  }

  /** @return array<string,mixed>|null */
  public function branch(string $id): ?array
  {
    return $id === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_sucursales') . '` WHERE `_ID` = ? LIMIT 1', [$id]);
  }

  /** @return array<string,string> id_empleado => nombre de quienes han hecho cotizaciones. */
  public function creators(): array
  {
    $out = [];
    foreach ($this->db->getResults('SELECT DISTINCT TRIM(q.`id_empleado`) AS id, COALESCE(NULLIF(TRIM(f.`nombre`), \'\'), NULLIF(TRIM(q.`creador`), \'\'), TRIM(q.`id_empleado`)) AS nombre FROM `' . $this->db->table(self::TABLE) . '` q LEFT JOIN `' . $this->db->table('jet_cct_funcionarios') . "` f ON TRIM(f.`id_empleado`) = TRIM(q.`id_empleado`) WHERE TRIM(COALESCE(q.`id_empleado`, '')) <> '' ORDER BY nombre ASC LIMIT 300") as $row) {
      $out[(string) $row['id']] = (string) $row['nombre'];
    }
    return $out;
  }

  /** @param array<string,mixed> $data */
  public function insertQuote(array $data): int
  {
    $this->db->insert($this->db->table(self::TABLE), $this->existing(self::TABLE, $data));
    return (int) $this->db->lastInsertId();
  }

  /** Solo las columnas que existen: url_cotizacion y url_pdf se crean con bin/add-quote-link-fields.php. @param array<string,mixed> $data */
  public function updateQuote(int $id, array $data): void
  {
    $data = $this->existing(self::TABLE, $data);
    if ($data !== []) {
      $this->db->update($this->db->table(self::TABLE), $data, ['_ID' => $id]);
    }
  }

  /** @param array<string,mixed> $data */
  public function insertPropertyHistory(array $data): void
  {
    if ($this->columns->columns('jet_cct_historial_del_inmueble') !== []) {
      $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), $this->existing('jet_cct_historial_del_inmueble', $data));
    }
  }

  /** @param array<string,mixed> $data */
  public function insertTaskHistory(array $data): void
  {
    $this->db->insert($this->db->table('jet_cct_historial_del_ticket'), $this->existing('jet_cct_historial_del_ticket', $data));
  }

  /** @param array<string,mixed> $data */
  public function updateTask(int $id, array $data): void
  {
    $data = $this->existing('jet_cct_tickets', $data);
    if ($data !== []) {
      $this->db->update($this->db->table('jet_cct_tickets'), $data, ['_ID' => $id]);
    }
  }

  /**
   * Códigos de inmueble guardados en `inmuebles` (serializado por JetEngine, JSON o separado por comas).
   * @param mixed $value
   * @return list<string>
   */
  public static function codes($value): array
  {
    if (!is_string($value) || trim($value) === '') {
      return [];
    }
    $data = @unserialize($value, ['allowed_classes' => false]);
    if ($data === false && $value !== 'b:0;') {
      $data = json_decode($value, true);
    }
    if (!is_array($data)) {
      $data = preg_split('/[\s,;]+/', trim($value)) ?: [];
    }
    $codes = [];
    array_walk_recursive($data, static function ($code) use (&$codes): void {
      $code = trim((string) $code);
      if ($code !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $code) === 1) {
        $codes[$code] = $code;
      }
    });
    return array_values($codes);
  }

  /**
   * Solo las columnas que existen en la tabla.
   * @param array<string,mixed> $data
   * @return array<string,mixed>
   */
  private function existing(string $name, array $data): array
  {
    return array_intersect_key($data, array_flip($this->columns->columns($name)));
  }
}
