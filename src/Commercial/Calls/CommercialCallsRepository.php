<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Database;
use SCM\Precaptacion\Repository as ColumnsReader;

/**
 * Acceso a `jet_cct_cct_llamadas` y a las tablas que toca el módulo de llamadas (mismas que usaban los formularios
 * JetFormBuilder "Añadir/Actualizar control de llamada" y "Crear ticket comercial con llamadas").
 * Cada llamada es una solicitud: un contacto puede tener varias, cada una con su medio, y se agrupan por celular.
 */
final class CommercialCallsRepository
{
  private const HAS_TASK = "TRIM(COALESCE(`id_ticket`, '')) NOT IN ('', '0')";

  private Database $db;
  private ColumnsReader $columns;
  /** @var array<string,string>|null */
  private ?array $names = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->columns = new ColumnsReader($db);
  }

  public function database(): Database
  {
    return $this->db;
  }

  private function calls(): string
  {
    return $this->db->table('jet_cct_cct_llamadas');
  }

  /**
   * Una página de llamadas con sus conteos. $scope: null = todo el equipo; id_empleado = solo lo suyo; '' = no se sabe
   * quién es y no se muestra nada.
   * @param array<string,string> $filters
   * @return array{rows:list<array<string,mixed>>,total:int,counts:array{total:int,pendientes:int,con_tarea:int,pph:int,comisionistas:int,mas_adelante:int}}
   */
  public function search(array $filters, ?string $scope, int $page, int $perPage, string $pphMedio): array
  {
    $empty = ['rows' => [], 'total' => 0, 'counts' => ['total' => 0, 'pendientes' => 0, 'con_tarea' => 0, 'pph' => 0, 'comisionistas' => 0, 'mas_adelante' => 0]];
    if ($scope === '') {
      return $empty;
    }
    [$where, $args] = $this->where($filters, $scope, false);
    $counts = $this->db->getRow(
      'SELECT COUNT(*) AS total,'
      . " SUM(CASE WHEN `merece_ticket` = 'Si' AND NOT (" . self::HAS_TASK . ') THEN 1 ELSE 0 END) AS pendientes,'
      . ' SUM(CASE WHEN ' . self::HAS_TASK . ' THEN 1 ELSE 0 END) AS con_tarea,'
      . " SUM(CASE WHEN `comisionista` = 'Si' THEN 1 ELSE 0 END) AS comisionistas,"
      . " SUM(CASE WHEN `cliente_inmediato` = 'No' THEN 1 ELSE 0 END) AS mas_adelante,"
      . ' SUM(CASE WHEN `medio` = ? THEN 1 ELSE 0 END) AS pph'
      . ' FROM `' . $this->calls() . '`' . $where,
      array_merge([$pphMedio], $args)
    ) ?? [];
    [$where, $args] = $this->where($filters, $scope, true);
    $total = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->calls() . '`' . $where, $args);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = $this->db->getResults('SELECT * FROM `' . $this->calls() . '`' . $where . ' ORDER BY `_ID` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset, $args);
    return [
      'rows' => $rows,
      'total' => $total,
      'counts' => [
        'total' => (int) ($counts['total'] ?? 0),
        'pendientes' => (int) ($counts['pendientes'] ?? 0),
        'con_tarea' => (int) ($counts['con_tarea'] ?? 0),
        'pph' => (int) ($counts['pph'] ?? 0),
        'comisionistas' => (int) ($counts['comisionistas'] ?? 0),
        'mas_adelante' => (int) ($counts['mas_adelante'] ?? 0),
      ],
    ];
  }

  /**
   * @param array<string,string> $f
   * @return array{0:string,1:list<string|int>}
   */
  private function where(array $f, ?string $scope, bool $withState): array
  {
    $clauses = [];
    $args = [];
    if ($scope !== null) {
      $clauses[] = "(TRIM(COALESCE(`id_empleado`, '')) = ? OR CAST(`cct_author_id` AS CHAR) = ?)";
      array_push($args, $scope, $scope);
    }
    $q = trim($f['q'] ?? '');
    if ($q !== '') {
      $like = '%' . $this->db->escapeLike($q) . '%';
      $clauses[] = '(`cliente` LIKE ? OR `celular` LIKE ? OR `email` LIKE ? OR `celular_wsp` LIKE ? OR CAST(`_ID` AS CHAR) = ?)';
      array_push($args, $like, $like, $like, $like, $q);
    }
    foreach (['medio' => 'medio', 'tema' => 'tema_ayuda', 'comisionista' => 'comisionista', 'inmediato' => 'cliente_inmediato', 'mes' => 'mes_inmueble'] as $key => $column) {
      if (trim($f[$key] ?? '') !== '') {
        $clauses[] = '`' . $column . '` = ?';
        $args[] = trim($f[$key]);
      }
    }
    if ($scope === null && trim($f['empleado'] ?? '') !== '') {
      $clauses[] = "TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = trim($f['empleado']);
    }
    $card = trim($f['tarjeta'] ?? '');
    if ($card !== '') {
      $like = '%' . $this->db->escapeLike($card) . '%';
      $clauses[] = '(`tarjeta_pph` LIKE ? OR `nombre_pph` LIKE ? OR `id_pph` IN (SELECT CAST(`_ID` AS CHAR) FROM `' . $this->db->table('jet_cct_club_pph') . '` WHERE `tarjeta_bienvenida` LIKE ? OR `nombre` LIKE ?))';
      array_push($args, $like, $like, $like, $like);
    }
    foreach (['desde' => ['>=', '00:00:00'], 'hasta' => ['<=', '23:59:59']] as $key => [$operator, $time]) {
      $timestamp = self::dayTimestamp($f[$key] ?? '', $time);
      if ($timestamp !== null) {
        $clauses[] = '`fecha` ' . $operator . ' ?';
        $args[] = $timestamp;
      }
    }
    if ($withState) {
      $state = [
        'pendientes' => "`merece_ticket` = 'Si' AND NOT (" . self::HAS_TASK . ')',
        'con_tarea' => self::HAS_TASK,
        'no_merece' => "`merece_ticket` = 'No'",
      ][$f['estado'] ?? ''] ?? '';
      if ($state !== '') {
        $clauses[] = '(' . $state . ')';
      }
    }
    return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $args];
  }

  /** Timestamp de un día AAAA-MM-DD a la hora dada (Colombia), o null si la fecha no es válida. */
  public static function dayTimestamp(string $day, string $time): ?int
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($day) . ' ' . $time, new \DateTimeZone('America/Bogota'));
    return $date !== false && $date->format('Y-m-d') === trim($day) ? $date->getTimestamp() : null;
  }

  /** @return array<string,mixed>|null */
  public function find(int $id, bool $lock = false): ?array
  {
    $suffix = $lock && $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    return $this->db->getRow('SELECT * FROM `' . $this->calls() . '` WHERE `_ID` = ?' . $suffix, [$id]);
  }

  /** @param array<string,mixed> $data */
  public function insert(array $data): int
  {
    $this->db->insert($this->calls(), $this->existing('jet_cct_cct_llamadas', $data));
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public function update(int $id, array $data): void
  {
    $this->db->update($this->calls(), $this->existing('jet_cct_cct_llamadas', $data), ['_ID' => $id]);
  }

  /** Solo dígitos: así se compara un contacto aunque el celular se haya escrito con espacios o guiones. */
  public static function digits(string $phone): string
  {
    return (string) preg_replace('/\D+/', '', $phone);
  }

  /**
   * Comparación de celular: los últimos 10 dígitos si los hay (con o sin indicativo), o el número exacto.
   * @return array{0:string,1:string}
   */
  private function phoneClause(string $column, string $digits): array
  {
    $expression = 'REPLACE(REPLACE(REPLACE(COALESCE(`' . $column . "`, ''), ' ', ''), '-', ''), '+', '')";
    return strlen($digits) >= 10 ? [$expression . ' LIKE ?', '%' . substr($digits, -10)] : [$expression . ' = ?', $digits];
  }

  /** @return list<array<string,mixed>> Todas las solicitudes de ese celular, de la más reciente a la más antigua. */
  public function contactCalls(string $digits): array
  {
    if ($digits === '') {
      return [];
    }
    [$clause, $value] = $this->phoneClause('celular', $digits);
    return $this->db->getResults('SELECT * FROM `' . $this->calls() . '` WHERE ' . $clause . ' ORDER BY `_ID` DESC LIMIT 100', [$value]);
  }

  /** @return list<array<string,mixed>> Tareas comerciales del celular (también las que no salieron de una llamada). */
  public function contactTasks(string $digits): array
  {
    if ($digits === '') {
      return [];
    }
    [$clause, $value] = $this->phoneClause('celular_solicitante', $digits);
    $topics = CommercialTicketsRepository::COMMERCIAL_TOPICS;
    return $this->db->getResults(
      'SELECT `_ID`, `fecha`, `tema_ayuda`, `medio`, `estado_comercial`, `id_llamada`, `id_empleado` FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE ' . $clause
      . ' AND `tema_ayuda` IN (' . implode(',', array_fill(0, count($topics), '?')) . ') ORDER BY `_ID` DESC LIMIT 100',
      array_merge([$value], $topics)
    );
  }

  /**
   * Estado comercial de las tareas de una lista de llamadas.
   * @param list<string> $ids
   * @return array<int|string,string> id => estado
   */
  public function taskStatuses(array $ids): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
      return [];
    }
    $out = [];
    foreach ($this->db->getResults('SELECT `_ID`, `estado_comercial` FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` IN (' . implode(',', $ids) . ')') as $row) {
      $out[(string) $row['_ID']] = (string) ($row['estado_comercial'] ?? '');
    }
    return $out;
  }

  /** @return array<string,string> id_empleado => nombre */
  public function employeeNames(): array
  {
    if ($this->names === null) {
      $this->names = [];
      foreach ($this->db->getResults('SELECT `id_empleado`, `nombre` FROM `' . $this->db->table('jet_cct_funcionarios') . '`') as $row) {
        $this->names[trim((string) $row['id_empleado'])] = trim((string) $row['nombre']);
      }
    }
    return $this->names;
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

  /** @return list<array<string,mixed>> Aliados del Club PPH con esa tarjeta de bienvenida. */
  public function cardMatches(string $card): array
  {
    return $this->db->getResults(
      'SELECT `_ID`, `nombre`, `tarjeta_bienvenida`, `activo` FROM `' . $this->db->table('jet_cct_club_pph') . '` WHERE LOWER(TRIM(`tarjeta_bienvenida`)) = LOWER(?)',
      [trim($card)]
    );
  }

  /** @return list<array{value:string,label:string}> Valores de un glosario de JetEngine (wp_jet_post_types). */
  public function glossary(int $id): array
  {
    if ($this->columns->columns('jet_post_types') === []) {
      return [];
    }
    $raw = $this->db->getVar('SELECT `meta_fields` FROM `' . $this->db->table('jet_post_types') . "` WHERE `id` = ? AND `status` = 'glossary'", [$id]);
    $items = is_string($raw) && $raw !== '' ? @unserialize($raw, ['allowed_classes' => false]) : [];
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
      $value = is_array($item) && is_scalar($item['value'] ?? null) ? trim((string) $item['value']) : '';
      if ($value !== '') {
        $out[] = ['value' => $value, 'label' => trim((string) ($item['label'] ?? $value)) ?: $value];
      }
    }
    return $out;
  }

  /** @return list<array{value:string,label:string}> Países: el valor es el indicativo, como en el formulario anterior. */
  public function countries(): array
  {
    $out = [];
    foreach ($this->db->getResults('SELECT `codigo`, `pais` FROM `' . $this->db->table('jet_cct_paises') . '` ORDER BY `pais` ASC') as $row) {
      $code = trim((string) ($row['codigo'] ?? ''));
      if ($code !== '') {
        $out[] = ['value' => $code, 'label' => trim((string) ($row['pais'] ?? $code)) . ' (' . $code . ')'];
      }
    }
    return $out;
  }

  /** ¿Se acaba de guardar esta misma llamada? Evita duplicados por doble clic. */
  public function recentDuplicate(string $digits, string $topic, string $medio, string $since): bool
  {
    [$clause, $value] = $this->phoneClause('celular', $digits);
    return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->calls() . '` WHERE ' . $clause . ' AND `tema_ayuda` = ? AND `medio` = ? AND `cct_created` >= ?', [$value, $topic, $medio, $since]) > 0;
  }

  /** _ID del cliente con ese celular, o 0. */
  public function clientId(string $digits): int
  {
    if ($digits === '' || $this->columns->columns('jet_cct_clientes') === []) {
      return 0;
    }
    [$clause, $value] = $this->phoneClause('celular', $digits);
    return (int) $this->db->getVar('SELECT `_ID` FROM `' . $this->db->table('jet_cct_clientes') . '` WHERE ' . $clause . ' ORDER BY `_ID` DESC LIMIT 1', [$value]);
  }

  /** @param array<string,mixed> $data */
  public function insertClient(array $data): int
  {
    $this->db->insert($this->db->table('jet_cct_clientes'), $this->existing('jet_cct_clientes', $data));
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public function insertTask(array $data): int
  {
    $this->db->insert($this->db->table('jet_cct_tickets'), $this->existing('jet_cct_tickets', $data));
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public function insertPropertyHistory(array $data): void
  {
    if ($this->columns->columns('jet_cct_historial_del_inmueble') !== []) {
      $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), $this->existing('jet_cct_historial_del_inmueble', $data));
    }
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
