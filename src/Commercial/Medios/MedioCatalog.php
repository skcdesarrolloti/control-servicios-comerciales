<?php

declare(strict_types=1);

namespace SCM\Commercial\Medios;

use SCM\Commercial\Promotion\Analytics\TrackerSchema;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Lista única de Medios (cómo llegó el cliente o la visita), guardada en scm_medios y administrada en Configuración ›
 * Medios. La usan las columnas `medio` de los registros, el glosario "Medio de llamada" de JetEngine (lo reescribe aquí
 * para que liste exactamente los medios activos), el Generador UTM (un solo selector: utm_source = utm_medium = slug) y
 * la analítica web (dimensión `medio` de los resúmenes).
 *
 * Renombrar y unir reescriben el texto en todas las columnas `medio` (solo coincidencias exactas del nombre anterior),
 * dejan el nombre anterior como alias y guardan el valor anterior de cada registro en scm_medios_backup. Cada cambio queda
 * en scm_medios_log, y los cambios de cada operación van en una sola transacción. Un medio nunca se borra: se desactiva
 * (deja de ofrecerse, pero se sigue reconociendo). Unir sí retira el medio de origen, porque sus registros pasan al otro y
 * su nombre queda como alias.
 *
 * Mientras la tabla no exista, todo se lee de MedioSeed y las operaciones de escritura se rechazan.
 */
final class MedioCatalog
{
  /** Tablas de registros con su columna de medio (las que falten en la base se omiten). */
  public const RECORD_TABLES = [
    'jet_cct_cct_llamadas' => 'medio',
    'jet_cct_tickets' => 'medio',
    'jet_cct_cierres' => 'medio',
    'jet_cct_contratos_arrendamiento' => 'medio',
    'jet_cct_estudios_aseguradoras' => 'medio',
    'jet_cct_solicitudes_terminacion_contrato' => 'medio',
    'jet_cct_solicitudes_no_prorroga_contrato' => 'medio',
  ];

  /** Nombre visible de cada tabla en Configuración › Medios. */
  public const TABLE_LABELS = [
    'jet_cct_cct_llamadas' => 'Llamadas',
    'jet_cct_tickets' => 'Tareas',
    'jet_cct_cierres' => 'Cierres',
    'jet_cct_contratos_arrendamiento' => 'Contratos',
    'jet_cct_estudios_aseguradoras' => 'Estudios',
    'jet_cct_solicitudes_terminacion_contrato' => 'Terminaciones',
    'jet_cct_solicitudes_no_prorroga_contrato' => 'No prórrogas',
    'jet_cct_tracker_logs' => 'Visitas web',
  ];

  /** Glosario de JetEngine "Medio de llamada" (wp_jet_post_types, status 'glossary'). */
  public const GLOSSARY_ID = 195;

  public const MAX_NAME = 60;
  public const MAX_ALIAS = 120;
  public const MAX_ALIASES = 40;
  private const CHUNK = 500;

  private Database $db;
  private Settings $settings;
  private string $employee;
  /** @var list<array{id:int,nombre:string,slug:string,aliases:list<string>,activo:bool,orden:int}>|null */
  private ?array $rows = null;
  private ?MedioResolver $resolver = null;
  /** @var array<string,bool> */
  private array $exists = [];

  /** @param string $employee id_empleado de quien hace los cambios ('cli' desde la línea de comandos). */
  public function __construct(Database $db, Settings $settings, string $employee = '')
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->employee = mb_substr(trim($employee), 0, 40);
  }

  public function ready(): bool
  {
    return MedioSchema::isReady($this->settings);
  }

  /**
   * Todos los medios (activos e inactivos) en su orden.
   * @return list<array{id:int,nombre:string,slug:string,aliases:list<string>,activo:bool,orden:int}>
   */
  public function all(): array
  {
    if ($this->rows !== null) {
      return $this->rows;
    }
    if (!$this->ready()) {
      return $this->rows = MedioSeed::rows();
    }
    try {
      $rows = $this->db->getResults('SELECT `id`, `nombre`, `slug`, `aliases`, `activo`, `orden` FROM `' . MedioSchema::medios($this->db) . '` ORDER BY `orden` ASC, `id` ASC');
    } catch (\Throwable $exception) {
      error_log('[medios] ' . $exception->getMessage());
      return $this->rows = MedioSeed::rows();
    }
    return $this->rows = array_map(static fn(array $row): array => [
      'id' => (int) $row['id'],
      'nombre' => (string) $row['nombre'],
      'slug' => (string) $row['slug'],
      'aliases' => self::decodeAliases($row['aliases'] ?? ''),
      'activo' => (int) $row['activo'] === 1,
      'orden' => (int) $row['orden'],
    ], $rows);
  }

  /** @return list<array{id:int,nombre:string,slug:string,aliases:list<string>,activo:bool,orden:int}> */
  public function active(): array
  {
    return array_values(array_filter($this->all(), static fn(array $row): bool => $row['activo']));
  }

  /**
   * Opciones del Generador UTM: nombre visible y slug.
   * @return list<array{label:string,value:string}>
   */
  public function utmOptions(): array
  {
    return array_map(static fn(array $row): array => ['label' => $row['nombre'], 'value' => $row['slug']], $this->active());
  }

  public function resolver(): MedioResolver
  {
    return $this->resolver ??= new MedioResolver($this->all());
  }

  /** Nombre del medio para un texto crudo, o el mismo texto si no se reconoce. */
  public function canonical(?string $raw): string
  {
    return $this->resolver()->canonical($raw);
  }

  /**
   * Revisión de la lista: el último cambio del historial. Los resúmenes de la analítica guardan la revisión con que
   * clasificaron las visitas; si cambia, la siguiente corrida del cron vuelve a clasificar (TrackerRollup::remapMedios).
   */
  public function revision(): int
  {
    if (!$this->ready()) {
      return 0;
    }
    try {
      return (int) $this->db->getVar('SELECT MAX(`id`) FROM `' . MedioSchema::log($this->db) . '`');
    } catch (\Throwable) {
      return 0;
    }
  }

  // ── Lecturas para Configuración › Medios ─────────────────────────────

  /**
   * Valores exactos de `medio` en cada tabla de registros (sin vacíos), con su conteo.
   * @return array<string,array<string,int>> tabla => [valor => filas]
   */
  public function recordValues(): array
  {
    $out = [];
    foreach ($this->recordTables() as $table => $column) {
      $out[$table] = $this->columnValues($table, $column);
    }
    return $out;
  }

  /**
   * Valores exactos (sin vacíos) de una columna con su conteo.
   * @return array<string,int>
   */
  public function columnValues(string $table, string $column): array
  {
    if (!$this->columnExists($this->db->table($table), $column)) {
      return [];
    }
    // En MariaDB la columna compara sin mayúsculas ni tildes: BINARY separa "Portal Propietario" de "Portal propietario".
    $group = $this->mysql() ? 'BINARY `' . $column . '`' : '`' . $column . '`';
    $out = [];
    $rows = $this->db->getResults('SELECT `' . $column . '` AS value, COUNT(*) AS n FROM `' . $this->db->table($table) . '` WHERE `' . $column . "` IS NOT NULL AND `{$column}` <> '' GROUP BY " . $group);
    foreach ($rows as $row) {
      $value = (string) $row['value'];
      if (trim($value) !== '') {
        $out[$value] = ($out[$value] ?? 0) + (int) $row['n'];
      }
    }
    return $out;
  }

  /**
   * Pares (utm_source, utm_medium) de las visitas con su conteo: de los resúmenes diarios si existen (mucho más pequeños)
   * o de la tabla cruda del tracker.
   * @return list<array{source:string,medium:string,hits:int}>
   */
  public function trackerPairs(): array
  {
    $sql = null;
    if (TrackerSchema::isReady($this->settings)) {
      $sql = 'SELECT `utm_source` AS s, `utm_medium` AS m, SUM(`hits`) AS n FROM `' . TrackerSchema::daily($this->db) . '` GROUP BY `utm_source`, `utm_medium`';
    } elseif ($this->tableExists($this->db->table('jet_cct_tracker_logs'))) {
      $sql = 'SELECT `utm_source` AS s, `utm_medium` AS m, COUNT(*) AS n FROM `' . $this->db->table('jet_cct_tracker_logs') . '` GROUP BY `utm_source`, `utm_medium`';
    }
    if ($sql === null) {
      return [];
    }
    try {
      $rows = $this->db->getResults($sql);
    } catch (\Throwable $exception) {
      error_log('[medios] ' . $exception->getMessage());
      return [];
    }
    return array_map(static fn(array $row): array => ['source' => trim((string) $row['s']), 'medium' => trim((string) $row['m']), 'hits' => (int) $row['n']], $rows);
  }

  /**
   * Registros por medio (suma de las tablas), para la lista de Configuración › Medios.
   * @param array<string,array<string,int>>|null $values recordValues()
   * @return array<string,int> nombre => registros
   */
  public function usage(?array $values = null): array
  {
    $resolver = $this->resolver();
    $out = [];
    foreach ($values ?? $this->recordValues() as $counts) {
      foreach ($counts as $value => $count) {
        if ($resolver->isName((string) $value)) {
          $out[(string) $value] = ($out[(string) $value] ?? 0) + $count;
        }
      }
    }
    return $out;
  }

  /**
   * Valores que no se reconocen: en los registros (por tabla) y en las visitas (utm_source o utm_medium de los pares que
   * no se resuelven), con sus conteos. Lo que sí se reconoce pero está escrito distinto (mayúsculas, alias) aparece en
   * "variants": se corrige con php bin/medios-normalize.php o con "Reescribir registros".
   * @return array{records:list<array{value:string,total:int,tables:array<string,int>}>,variants:list<array{value:string,medio:string,total:int,tables:array<string,int>}>,visits:list<array{value:string,total:int,pairs:list<string>}>}
   */
  public function unresolved(): array
  {
    $resolver = $this->resolver();
    $records = [];
    $variants = [];
    foreach ($this->recordValues() as $table => $counts) {
      foreach ($counts as $value => $count) {
        $value = (string) $value;
        $name = $resolver->resolve($value);
        if ($name === $value) {
          continue;
        }
        if ($name === null) {
          $records[$value] ??= ['value' => $value, 'total' => 0, 'tables' => []];
          $records[$value]['total'] += $count;
          $records[$value]['tables'][$table] = $count;
        } else {
          $variants[$value] ??= ['value' => $value, 'medio' => $name, 'total' => 0, 'tables' => []];
          $variants[$value]['total'] += $count;
          $variants[$value]['tables'][$table] = $count;
        }
      }
    }
    $visits = [];
    foreach ($this->trackerPairs() as $pair) {
      [$label, $known] = $resolver->forVisit($pair['source'], $pair['medium']);
      if ($known || $label === '') {
        continue;
      }
      $visits[$label] ??= ['value' => $label, 'total' => 0, 'pairs' => []];
      $visits[$label]['total'] += $pair['hits'];
      $visits[$label]['pairs'][] = 'utm_source=' . ($pair['source'] !== '' ? $pair['source'] : '(vacío)') . ' · utm_medium=' . ($pair['medium'] !== '' ? $pair['medium'] : '(vacío)');
    }
    $sort = static fn(array $a, array $b): int => [$b['total'], $a['value']] <=> [$a['total'], $b['value']];
    $records = array_values($records);
    $variants = array_values($variants);
    $visits = array_values($visits);
    usort($records, $sort);
    usort($variants, $sort);
    usort($visits, $sort);
    return ['records' => $records, 'variants' => $variants, 'visits' => $visits];
  }

  /**
   * Cuántos registros tienen exactamente este texto, por tabla (lo que cambiaría al renombrar, unir o reescribir).
   * @return array<string,int>
   */
  public function preview(string $value): array
  {
    $out = [];
    foreach ($this->recordTables() as $table => $column) {
      $out[$table] = count($this->exactIds($table, $column, $value));
    }
    return $out;
  }

  /**
   * Últimos cambios del historial.
   * @return list<array{id:int,action:string,from:string,to:string,detail:array<string,mixed>,id_empleado:string,at:string}>
   */
  public function history(int $limit = 15): array
  {
    if (!$this->ready()) {
      return [];
    }
    $rows = $this->db->getResults('SELECT * FROM `' . MedioSchema::log($this->db) . '` ORDER BY `id` DESC LIMIT ' . max(1, min(200, $limit)));
    return array_map(static function (array $row): array {
      $detail = json_decode((string) ($row['detail'] ?? ''), true);
      return [
        'id' => (int) $row['id'],
        'action' => (string) $row['action'],
        'from' => (string) $row['from_value'],
        'to' => (string) $row['to_value'],
        'detail' => is_array($detail) ? $detail : [],
        'id_empleado' => (string) $row['id_empleado'],
        'at' => (string) $row['created_at'],
      ];
    }, $rows);
  }

  // ── Cambios ──────────────────────────────────────────────────────────

  /** Crea la lista con MedioSeed si está vacía (php bin/medios-normalize.php --apply). Devuelve cuántos agregó. */
  public function seed(): int
  {
    $this->requireReady();
    if ((int) $this->db->getVar('SELECT COUNT(*) FROM `' . MedioSchema::medios($this->db) . '`') > 0) {
      return 0;
    }
    return $this->transaction(function (): int {
      $count = 0;
      foreach (MedioSeed::rows() as $row) {
        $this->insertRow($row['nombre'], $row['slug'], $row['aliases'], true, $row['orden']);
        $count++;
      }
      $this->forget();
      $this->log('seed', '', '', ['medios' => $count, 'glossary' => $this->syncGlossary()]);
      return $count;
    });
  }

  /** @return array{id:int,nombre:string} */
  public function add(string $name): array
  {
    $this->requireReady();
    $name = $this->validName($name, null);
    return $this->transaction(function () use ($name): array {
      $orden = (int) $this->db->getVar('SELECT COALESCE(MAX(`orden`), 0) FROM `' . MedioSchema::medios($this->db) . '`') + 1;
      $id = $this->insertRow($name, $this->uniqueSlug($name, null), [], true, $orden);
      $this->forget();
      $this->log('add', '', $name, ['glossary' => $this->syncGlossary()]);
      return ['id' => $id, 'nombre' => $name];
    });
  }

  /**
   * Cambia el nombre: los registros con el nombre anterior pasan al nuevo, y el nombre y el slug anteriores quedan como
   * alias (los enlaces UTM ya publicados siguen sumando al medio).
   * @return array<string,int> Registros cambiados por tabla.
   */
  public function rename(int $id, string $name): array
  {
    $this->requireReady();
    $medio = $this->find($id);
    $name = $this->validName($name, $id);
    if ($name === $medio['nombre']) {
      throw new \InvalidArgumentException('Escribe un nombre distinto al actual.');
    }
    return $this->transaction(function () use ($medio, $name): array {
      $slug = $this->uniqueSlug($name, $medio['id']);
      $aliases = $this->mergeAliases($medio['aliases'], array_filter([$medio['nombre'], $medio['slug'] !== $slug ? $medio['slug'] : ''], static fn(string $value): bool => $value !== ''), $name);
      $this->updateRow($medio['id'], ['nombre' => $name, 'slug' => $slug, 'aliases' => self::encodeAliases($aliases)]);
      $logId = $this->log('rename', $medio['nombre'], $name, []);
      $counts = $this->rewriteRecords($medio['nombre'], $name, $logId);
      $this->forget();
      $this->updateLog($logId, ['records' => $counts, 'glossary' => $this->syncGlossary()]);
      return $counts;
    });
  }

  /** Activa o desactiva: un medio inactivo no se ofrece (glosario y Generador UTM) pero se sigue reconociendo. */
  public function setActive(int $id, bool $active): void
  {
    $this->requireReady();
    $medio = $this->find($id);
    if ($medio['activo'] === $active) {
      return;
    }
    if (!$active && count($this->active()) <= 1) {
      throw new \InvalidArgumentException('Deja al menos un medio activo.');
    }
    $this->transaction(function () use ($medio, $active): void {
      $this->updateRow($medio['id'], ['activo' => $active ? 1 : 0]);
      $this->forget();
      $this->log($active ? 'activate' : 'deactivate', $medio['nombre'], '', ['glossary' => $this->syncGlossary()]);
    });
  }

  /**
   * Une $fromId en $toId: sus registros pasan al destino, su nombre, slug y alias quedan como alias del destino, y el
   * medio de origen sale de la lista.
   * @return array<string,int> Registros cambiados por tabla.
   */
  public function merge(int $fromId, int $toId): array
  {
    $this->requireReady();
    if ($fromId === $toId) {
      throw new \InvalidArgumentException('Elige un medio distinto para unir.');
    }
    $from = $this->find($fromId);
    $to = $this->find($toId);
    return $this->transaction(function () use ($from, $to): array {
      $this->db->pdo()->prepare('DELETE FROM `' . MedioSchema::medios($this->db) . '` WHERE `id` = ?')->execute([$from['id']]);
      $aliases = $this->mergeAliases($to['aliases'], array_merge([$from['nombre'], $from['slug']], $from['aliases']), $to['nombre'], $to['slug']);
      $this->updateRow($to['id'], ['aliases' => self::encodeAliases($aliases)]);
      $logId = $this->log('merge', $from['nombre'], $to['nombre'], []);
      $counts = $this->rewriteRecords($from['nombre'], $to['nombre'], $logId);
      $this->forget();
      $this->updateLog($logId, ['records' => $counts, 'glossary' => $this->syncGlossary()]);
      return $counts;
    });
  }

  /**
   * Reemplaza los alias de un medio (uno por texto; no pueden ser el nombre, el slug ni un alias de otro medio).
   * @param list<string>|string $aliases
   * @return list<string> Los alias guardados.
   */
  public function setAliases(int $id, array|string $aliases): array
  {
    $this->requireReady();
    $medio = $this->find($id);
    $list = is_array($aliases) ? $aliases : preg_split('/\R/u', $aliases);
    $clean = [];
    $seen = [self::keyOf($medio['nombre']) => true, self::keyOf($medio['slug']) => true];
    foreach ($list ?: [] as $alias) {
      $alias = $this->validAlias((string) $alias, $medio['id']);
      if ($alias === '' || isset($seen[self::keyOf($alias)])) {
        continue;
      }
      $seen[self::keyOf($alias)] = true;
      $clean[] = $alias;
    }
    if (count($clean) > self::MAX_ALIASES) {
      throw new \InvalidArgumentException('Un medio admite hasta ' . self::MAX_ALIASES . ' alias.');
    }
    $this->transaction(function () use ($medio, $clean): void {
      $this->updateRow($medio['id'], ['aliases' => self::encodeAliases($clean)]);
      $this->forget();
      $this->log('aliases', $medio['nombre'], '', ['before' => $medio['aliases'], 'after' => $clean]);
    });
    return $clean;
  }

  /** Valor sin reconocer => alias de un medio (no cambia registros). */
  public function aliasRaw(string $raw, int $toId): void
  {
    $this->requireReady();
    $to = $this->find($toId);
    $raw = $this->validAlias($raw, $to['id']);
    if ($raw === '') {
      throw new \InvalidArgumentException('El valor está vacío.');
    }
    $this->transaction(function () use ($to, $raw): void {
      $this->updateRow($to['id'], ['aliases' => self::encodeAliases($this->mergeAliases($to['aliases'], [$raw], $to['nombre'], $to['slug']))]);
      $this->forget();
      $this->log('alias', $raw, $to['nombre'], []);
    });
  }

  /**
   * Valor de los registros => un medio: reescribe las coincidencias exactas y deja el valor como alias.
   * @return array<string,int>
   */
  public function rewriteRaw(string $raw, int $toId): array
  {
    $this->requireReady();
    $to = $this->find($toId);
    if (trim($raw) === '' || $raw === $to['nombre']) {
      throw new \InvalidArgumentException('Elige un valor distinto al nombre del medio.');
    }
    $owner = $this->resolver()->resolve($raw);
    if ($owner !== null && $owner !== $to['nombre']) {
      throw new \InvalidArgumentException('"' . $raw . '" ya corresponde a ' . $owner . ': únelo o renómbralo desde ese medio.');
    }
    return $this->transaction(function () use ($to, $raw): array {
      if ($this->resolver()->resolve($raw) === null) {
        $this->updateRow($to['id'], ['aliases' => self::encodeAliases($this->mergeAliases($to['aliases'], [mb_substr(trim($raw), 0, self::MAX_ALIAS)], $to['nombre'], $to['slug']))]);
      }
      $logId = $this->log('rewrite', $raw, $to['nombre'], []);
      $counts = $this->rewriteRecords($raw, $to['nombre'], $logId);
      $this->forget();
      $this->updateLog($logId, ['records' => $counts]);
      return $counts;
    });
  }

  /**
   * Deja el glosario de JetEngine con exactamente los medios activos, en su orden ({value, label, is_checked:false},
   * serializado como lo guarda JetEngine). Antes, cualquier valor del glosario que la lista no reconozca (agregado desde
   * WordPress) entra a la lista como medio nuevo, para no perderlo. Va dentro de la transacción del llamador.
   * @return bool ¿Cambió el glosario?
   */
  public function syncGlossary(): bool
  {
    $table = $this->db->table('jet_post_types');
    if (!$this->tableExists($table)) {
      return false;
    }
    $row = $this->db->getRow('SELECT `id`, `meta_fields` FROM `' . $table . "` WHERE `id` = ? AND `status` = 'glossary'", [self::GLOSSARY_ID]);
    if ($row === null) {
      return false;
    }
    $current = (string) $row['meta_fields'];
    $items = $current === '' ? [] : @unserialize($current, ['allowed_classes' => false]);
    if (!is_array($items)) {
      // No se reescribe un glosario que no se pudo leer.
      error_log('[medios] El glosario ' . self::GLOSSARY_ID . ' no se pudo leer; no se sincroniza.');
      return false;
    }
    $resolver = $this->resolver();
    $imported = [];
    foreach ($items as $item) {
      $value = is_array($item) && is_scalar($item['value'] ?? null) ? trim((string) $item['value']) : '';
      if ($value !== '' && $resolver->resolve($value) === null && MedioResolver::slug($value) !== '' && mb_strlen($value) <= self::MAX_NAME) {
        $orden = (int) $this->db->getVar('SELECT COALESCE(MAX(`orden`), 0) FROM `' . MedioSchema::medios($this->db) . '`') + 1;
        $this->insertRow($value, $this->uniqueSlug($value, null), [], true, $orden);
        $imported[] = $value;
        $this->forget();
        $resolver = $this->resolver();
      }
    }
    if ($imported !== []) {
      $this->log('import', '', implode(', ', $imported), ['glossary_values' => $imported]);
    }
    $next = serialize(self::glossaryItems($this->active()));
    if ($next === $current) {
      return false;
    }
    $this->db->pdo()->prepare('UPDATE `' . $table . '` SET `meta_fields` = ? WHERE `id` = ?')->execute([$next, self::GLOSSARY_ID]);
    return true;
  }

  /**
   * @param list<array{nombre:string}> $medios
   * @return list<array{value:string,label:string,is_checked:bool}>
   */
  public static function glossaryItems(array $medios): array
  {
    return array_map(static fn(array $row): array => ['value' => $row['nombre'], 'label' => $row['nombre'], 'is_checked' => false], $medios);
  }

  /**
   * Reescribe en las tablas de registros las coincidencias exactas de $old por $new, guardando el valor anterior de cada
   * fila en scm_medios_backup. Va dentro de la transacción del llamador.
   * @return array<string,int> Filas cambiadas por tabla.
   */
  public function rewriteRecords(string $old, string $new, int $logId): array
  {
    $out = [];
    foreach ($this->recordTables() as $table => $column) {
      $out[$table] = $this->rewriteColumn($table, $column, $old, $new, $logId);
    }
    return $out;
  }

  /**
   * Reescribe una columna (coincidencias exactas; la comparación de MariaDB no distingue mayúsculas ni tildes, así que el
   * filtro exacto se hace aquí) con respaldo. Va dentro de la transacción del llamador.
   */
  public function rewriteColumn(string $table, string $column, string $old, string $new, int $logId): int
  {
    $ids = $this->exactIds($table, $column, $old);
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $this->rewriteIds($table, $column, $chunk, $old, $new, $logId);
    }
    return count($ids);
  }

  /**
   * Respalda y reescribe unas filas ya elegidas (exactIds). Va dentro de la transacción del llamador.
   * @param list<int> $ids
   */
  public function rewriteIds(string $table, string $column, array $ids, string $old, string $new, int $logId): void
  {
    if ($ids === []) {
      return;
    }
    $backup = $this->db->pdo()->prepare('INSERT INTO `' . MedioSchema::backup($this->db) . '` (`log_id`, `table_name`, `row_id`, `column_name`, `old_value`, `new_value`) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($ids as $rowId) {
      $backup->execute([$logId, $table, $rowId, $column, $old, $new]);
    }
    $in = implode(', ', array_fill(0, count($ids), '?'));
    $this->db->pdo()->prepare('UPDATE `' . $this->db->table($table) . '` SET `' . $column . '` = ? WHERE `_ID` IN (' . $in . ')')->execute(array_merge([$new], $ids));
  }

  /**
   * _ID de las filas cuyo valor es exactamente $value.
   * @return list<int>
   */
  public function exactIds(string $table, string $column, string $value): array
  {
    $full = $this->db->table($table);
    if (!$this->columnExists($full, $column)) {
      return [];
    }
    $ids = [];
    foreach ($this->db->getResults('SELECT `_ID`, `' . $column . '` AS v FROM `' . $full . '` WHERE `' . $column . '` = ?', [$value]) as $row) {
      if ((string) $row['v'] === $value) {
        $ids[] = (int) $row['_ID'];
      }
    }
    return $ids;
  }

  /**
   * Guarda un cambio en el historial y devuelve su id (va dentro de la transacción del llamador).
   * @param array<string,mixed> $detail
   */
  public function log(string $action, string $from, string $to, array $detail): int
  {
    $this->db->pdo()->prepare('INSERT INTO `' . MedioSchema::log($this->db) . '` (`action`, `from_value`, `to_value`, `detail`, `id_empleado`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)')
      ->execute([$action, mb_substr($from, 0, 255), mb_substr($to, 0, 255), (string) json_encode($detail, JSON_UNESCAPED_UNICODE), $this->employee, date('Y-m-d H:i:s')]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $detail */
  public function updateLog(int $id, array $detail): void
  {
    $this->db->pdo()->prepare('UPDATE `' . MedioSchema::log($this->db) . '` SET `detail` = ? WHERE `id` = ?')->execute([(string) json_encode($detail, JSON_UNESCAPED_UNICODE), $id]);
  }

  /**
   * Tablas de registros que existen en esta base.
   * @return array<string,string>
   */
  public function recordTables(): array
  {
    return array_filter(self::RECORD_TABLES, fn(string $column, string $table): bool => $this->columnExists($this->db->table($table), $column), ARRAY_FILTER_USE_BOTH);
  }

  /** ¿Existen la tabla y la columna? (sin information_schema). */
  public function columnExists(string $table, string $column): bool
  {
    return $this->exists[$table . '.' . $column] ??= MedioSchema::exists($this->db, $table) && (function () use ($table, $column): bool {
      try {
        $this->db->getVar('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 1');
        return true;
      } catch (\Throwable) {
        return false;
      }
    })();
  }

  public function tableExists(string $table): bool
  {
    return $this->exists[$table] ??= MedioSchema::exists($this->db, $table);
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  public function transaction(callable $work)
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
    } catch (\Throwable $exception) {
      if ($owns) {
        try {
          $pdo->rollBack();
        } catch (\PDOException) {
          // La transacción ya había terminado (falló el commit).
        }
      }
      $this->forget();
      throw $exception;
    }
  }

  /** Olvida la lista leída (después de un cambio). */
  public function forget(): void
  {
    $this->rows = null;
    $this->resolver = null;
  }

  // ── Internos ─────────────────────────────────────────────────────────

  private function requireReady(): void
  {
    if (!$this->ready()) {
      throw new \RuntimeException('La lista de medios todavía no está instalada: pide a sistemas que ejecuten php bin/medios-normalize.php --apply.');
    }
  }

  private function mysql(): bool
  {
    return $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  /** @return array{id:int,nombre:string,slug:string,aliases:list<string>,activo:bool,orden:int} */
  private function find(int $id): array
  {
    foreach ($this->all() as $row) {
      if ($row['id'] === $id && $id > 0) {
        return $row;
      }
    }
    throw new \InvalidArgumentException('El medio ya no existe; vuelve a abrir la lista.');
  }

  /** Nombre limpio y único (sin contar el medio $selfId). */
  private function validName(string $name, ?int $selfId): string
  {
    $name = trim((string) preg_replace('/\s+/u', ' ', strip_tags($name)));
    if ($name === '') {
      throw new \InvalidArgumentException('Escribe el nombre del medio.');
    }
    if (mb_strlen($name) > self::MAX_NAME) {
      throw new \InvalidArgumentException('El nombre del medio puede tener hasta ' . self::MAX_NAME . ' caracteres.');
    }
    if (MedioResolver::slug($name) === '') {
      throw new \InvalidArgumentException('El nombre del medio debe tener letras o números.');
    }
    $key = self::keyOf($name);
    if ($key === self::keyOf(MedioResolver::DIRECT) || in_array($key, MedioResolver::TRACKER_DEFAULT_SOURCES, true) || in_array($key, MedioResolver::TRACKER_DEFAULT_MEDIUMS, true)) {
      throw new \InvalidArgumentException('"' . $name . '" lo usa la analítica para las visitas sin UTM: elige otro nombre.');
    }
    $this->assertFree($key, $selfId, $name);
    return $name;
  }

  /** Alias limpio ('' si viene vacío) que no choca con otro medio. */
  private function validAlias(string $alias, int $selfId): string
  {
    $alias = trim((string) preg_replace('/\s+/u', ' ', strip_tags($alias)));
    if ($alias === '') {
      return '';
    }
    if (mb_strlen($alias) > self::MAX_ALIAS) {
      throw new \InvalidArgumentException('Un alias puede tener hasta ' . self::MAX_ALIAS . ' caracteres.');
    }
    $key = self::keyOf($alias);
    if ($key === '') {
      throw new \InvalidArgumentException('El alias "' . $alias . '" debe tener letras o números.');
    }
    if (in_array($key, MedioResolver::TRACKER_DEFAULT_SOURCES, true) || in_array($key, MedioResolver::TRACKER_DEFAULT_MEDIUMS, true) || $key === self::keyOf(MedioResolver::DIRECT)) {
      throw new \InvalidArgumentException('"' . $alias . '" es lo que el tracker escribe en las visitas sin UTM: no puede ser alias de un medio.');
    }
    $this->assertFree($key, $selfId, $alias);
    return $alias;
  }

  private function assertFree(string $key, ?int $selfId, string $label): void
  {
    foreach ($this->all() as $row) {
      if ($selfId !== null && $row['id'] === $selfId) {
        continue;
      }
      $keys = array_map([self::class, 'keyOf'], array_merge([$row['nombre'], $row['slug']], $row['aliases']));
      if (in_array($key, $keys, true)) {
        throw new \InvalidArgumentException('"' . $label . '" ya corresponde al medio ' . $row['nombre'] . '.');
      }
    }
  }

  private function uniqueSlug(string $name, ?int $selfId): string
  {
    $base = MedioResolver::slug($name);
    $taken = [];
    foreach ($this->all() as $row) {
      if ($selfId === null || $row['id'] !== $selfId) {
        $taken[$row['slug']] = true;
      }
    }
    $slug = $base;
    for ($n = 2; isset($taken[$slug]); $n++) {
      $slug = substr($base, 0, 95) . '_' . $n;
    }
    return $slug;
  }

  /**
   * @param list<string> $current
   * @param list<string> $add
   * @return list<string>
   */
  private function mergeAliases(array $current, array $add, string ...$own): array
  {
    $seen = [];
    foreach ($own as $value) {
      $seen[self::keyOf($value)] = true;
    }
    $out = [];
    foreach (array_merge($current, $add) as $alias) {
      $alias = trim($alias);
      $key = self::keyOf($alias);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $alias;
    }
    return $out;
  }

  /** @param list<string> $aliases */
  private function insertRow(string $name, string $slug, array $aliases, bool $active, int $orden): int
  {
    $now = date('Y-m-d H:i:s');
    $this->db->pdo()->prepare('INSERT INTO `' . MedioSchema::medios($this->db) . '` (`nombre`, `slug`, `aliases`, `activo`, `orden`, `created_at`, `updated_at`, `updated_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$name, $slug, self::encodeAliases($aliases), $active ? 1 : 0, $orden, $now, $now, $this->employee]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,int|string> $fields */
  private function updateRow(int $id, array $fields): void
  {
    $fields += ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->employee];
    $sets = implode(', ', array_map(static fn(string $column): string => '`' . $column . '` = ?', array_keys($fields)));
    $this->db->pdo()->prepare('UPDATE `' . MedioSchema::medios($this->db) . '` SET ' . $sets . ' WHERE `id` = ?')->execute(array_merge(array_values($fields), [$id]));
  }

  private static function keyOf(string $value): string
  {
    return MedioResolver::key($value);
  }

  /** @return list<string> */
  private static function decodeAliases(mixed $raw): array
  {
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($decoded)) {
      return [];
    }
    return array_values(array_filter(array_map(static fn($value): string => is_scalar($value) ? trim((string) $value) : '', $decoded), static fn(string $value): bool => $value !== ''));
  }

  /** @param list<string> $aliases */
  private static function encodeAliases(array $aliases): string
  {
    return (string) json_encode($aliases, JSON_UNESCAPED_UNICODE);
  }
}
