<?php

declare(strict_types=1);

namespace SCM\Commercial\Strategy;

use SCM\Core\Database;

/**
 * Herramientas › Plan estratégico. Adaptación del plugin de WordPress "Gestor Estratégico" V30.9 (shortcode
 * [gestor_estrategia], acciones gpe_leer, gpe_guardar, gpe_eliminar, gpe_guardar_avance y gpe_guardar_resultado): si
 * cambia la estructura de las tablas en uno, cambiarla en el otro. Usa las mismas tablas CCT de JetEngine:
 * objetivos → metas (id_objetivo) → tareas (id_meta, lista) → campañas (id_tarea, lista), y los catálogos de CATALOGS.
 *
 * Diferencias con el plugin: todo se valida aquí (ids que existen, valores de los catálogos, fechas), los avances y
 * resultados quedan a nombre del funcionario de la sesión (el plugin dejaba elegir a cualquiera), un registro con hijos
 * no se elimina (el plugin dejaba metas y tareas huérfanas) y los resultados de campaña se guardan en `informes` (la
 * columna `historial_resultados` que escribía el plugin no existe en la tabla, así que nunca se guardaron).
 *
 * Las tablas son pequeñas (decenas de filas): se leen completas y se filtran en PHP, igual en MySQL y en SQLite.
 */
final class StrategicPlanRepository
{
  public const TASK_STATES = ['Pendiente', 'En Proceso', 'Completado'];
  public const GOAL_STATES = ['Sin realizar', 'En Proceso', 'Realizado'];
  public const PERIODS = ['Anual', 'Semestral', 'Trimestral', 'Mensual'];
  public const LISTS = ['mis_tareas', 'objetivos', 'metas', 'tareas', 'campanas'];
  public const ENTITIES = ['objetivos', 'metas', 'tareas', 'campanas'];

  /**
   * Catálogos del plan (Configuración › Plan estratégico). key: la columna cuyo valor guardan los registros (el nombre,
   * el símbolo de la unidad o el _ID del actor); used_by: [tabla, columna, ¿lista separada por comas?] donde se usa, para
   * propagar los cambios de nombre y no eliminar lo que está en uso.
   */
  public const CATALOGS = [
    'gestiones' => ['label' => 'Gestiones', 'hint' => 'Perspectivas que agrupan los objetivos.', 'name' => 'nombre', 'key' => 'nombre', 'fields' => ['definicion' => 'Definición', 'alcance' => 'Alcance'], 'used_by' => [['objetivos', 'perspectiva', false]]],
    'unidades' => ['label' => 'Unidades', 'hint' => 'Unidad en que se mide cada meta; la meta guarda el símbolo.', 'name' => 'nombre', 'key' => 'simbolo', 'fields' => ['simbolo' => 'Símbolo'], 'used_by' => [['metas', 'unidad', false]]],
    'tipos_campana' => ['label' => 'Tipos de campaña', 'hint' => '', 'name' => 'nombre', 'key' => 'nombre', 'fields' => ['definicion' => 'Definición'], 'used_by' => [['campanas', 'tipo_campana', false]]],
    'tipos_medio' => ['label' => 'Tipos de medio', 'hint' => 'Canales de las campañas (no es la lista de Medios de los registros).', 'name' => 'nombre', 'key' => 'nombre', 'fields' => ['definicion' => 'Definición'], 'used_by' => [['campanas', 'tipo_medio', true]]],
    'tipos_mercadeo' => ['label' => 'Tipos de mercadeo', 'hint' => '', 'name' => 'nombre', 'key' => 'nombre', 'fields' => ['definicion' => 'Definición'], 'used_by' => [['campanas', 'tipo_mercadeo', true]]],
    'frecuencias' => ['label' => 'Frecuencias', 'hint' => '', 'name' => 'nombre', 'key' => 'nombre', 'fields' => ['definicion' => 'Definición'], 'used_by' => [['campanas', 'frecuencia', false]]],
    'actores' => ['label' => 'Actores', 'hint' => 'Públicos de tareas y campañas.', 'name' => 'nombre_actor', 'key' => '_ID', 'fields' => ['tipo' => 'Tipo', 'definicion' => 'Definición'], 'used_by' => [['tareas', 'id_actor', true], ['campanas', 'id_actor', true]]],
  ];

  /** Columna con el nombre visible de cada registro del plan. */
  private const NAMES = ['objetivos' => 'nombre_objetivo', 'metas' => 'nombre_meta', 'tareas' => 'nombre_tarea', 'campanas' => 'nombre_campana', 'actores' => 'nombre_actor'];
  private const MAX_TEXT = 2000;
  private const MAX_LONG = 8000;
  private const TIMEZONE = 'America/Bogota';

  private Database $db;
  /** @var array<string,list<array<string,mixed>>> */
  private array $cache = [];

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  // ── Lectura ──────────────────────────────────────────────────────────────

  /**
   * Una lista del plan con los datos para pintarla, ya filtrada.
   * @param array<string,mixed> $filters q, gestion, objetivo, meta, tarea, estado, responsable, tipo, medio.
   * @return list<array<string,mixed>>
   */
  public function list(string $type, array $filters, string $currentName): array
  {
    if (!in_array($type, self::LISTS, true)) {
      throw new \InvalidArgumentException('Lista del plan no válida.');
    }
    $table = $type === 'mis_tareas' ? 'tareas' : $type;
    $rows = array_map(fn(array $row): array => $this->present($table, $row, $currentName), $this->rows($table));
    if ($type === 'mis_tareas') {
      $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['mine']));
    }
    $text = self::fold(self::text($filters['q'] ?? ''));
    $match = static function (array $row, string $key, string $field, bool $list = false) use ($filters): bool {
      $wanted = self::text($filters[$key] ?? '');
      if ($wanted === '') return true;
      return $list ? in_array(self::fold($wanted), array_map([self::class, 'fold'], (array) $row[$field]), true) : self::fold((string) $row[$field]) === self::fold($wanted);
    };
    $rows = array_values(array_filter($rows, static function (array $row) use ($text, $match, $table): bool {
      if ($text !== '' && !str_contains(self::fold($row['nombre'] . ' ' . ($row['descripcion'] ?? '')), $text)) return false;
      return match ($table) {
        'objetivos' => $match($row, 'gestion', 'perspectiva') && $match($row, 'responsable', 'responsables', true),
        'metas' => $match($row, 'objetivo', 'id_objetivo') && $match($row, 'estado', 'estado') && $match($row, 'unidad', 'unidad'),
        'tareas' => $match($row, 'meta', 'id_meta', true) && $match($row, 'estado', 'estado') && $match($row, 'responsable', 'responsables', true),
        default => $match($row, 'tarea', 'id_tarea', true) && $match($row, 'tipo', 'tipo_campana') && $match($row, 'medio', 'tipos_medio', true) && $match($row, 'responsable', 'responsables', true),
      };
    }));
    return $rows;
  }

  /**
   * Indicadores del encabezado.
   * @return array{objetivos:int,metas:int,metas_realizadas:int,metas_vencidas:int,tareas:int,tareas_completadas:int,tareas_en_proceso:int,campanas:int,mis_pendientes:int}
   */
  public function summary(string $currentName, ?int $now = null): array
  {
    $today = self::dateOf($now ?? time());
    $metas = $this->rows('metas');
    $tareas = $this->rows('tareas');
    $mine = array_filter($tareas, static fn(array $row): bool => self::includesName((string) ($row['responsable'] ?? ''), $currentName) && ($row['estado'] ?? '') !== 'Completado');
    return [
      'objetivos' => count($this->rows('objetivos')),
      'metas' => count($metas),
      'metas_realizadas' => count(array_filter($metas, static fn(array $row): bool => ($row['estado'] ?? '') === 'Realizado')),
      'metas_vencidas' => count(array_filter($metas, static function (array $row) use ($today): bool {
        $limit = self::dateOf((int) ($row['fecha_limite'] ?? 0));
        return $limit !== '' && $limit < $today && ($row['estado'] ?? '') !== 'Realizado';
      })),
      'tareas' => count($tareas),
      'tareas_completadas' => count(array_filter($tareas, static fn(array $row): bool => ($row['estado'] ?? '') === 'Completado')),
      'tareas_en_proceso' => count(array_filter($tareas, static fn(array $row): bool => ($row['estado'] ?? '') === 'En Proceso')),
      'campanas' => count($this->rows('campanas')),
      'mis_pendientes' => count($mine),
    ];
  }

  /**
   * Opciones de los formularios y filtros: catálogos, funcionarios activos y los registros que pueden ser padres.
   * @return array<string,mixed>
   */
  public function options(): array
  {
    $catalogs = [];
    foreach (self::CATALOGS as $key => $definition) {
      $catalogs[$key] = array_map(static function (array $row) use ($definition): array {
        $item = ['id' => (int) $row['_ID'], 'nombre' => self::text($row[$definition['name']] ?? ''), 'valor' => self::text($row[$definition['key']] ?? '')];
        foreach (array_keys($definition['fields']) as $field) $item[$field] = self::text($row[$field] ?? '');
        return $item;
      }, $this->rows($key));
      usort($catalogs[$key], static fn(array $a, array $b): int => strcmp(self::fold($a['nombre']), self::fold($b['nombre'])));
    }
    $pick = fn(string $table): array => array_map(static fn(array $row): array => ['id' => (int) $row['_ID'], 'nombre' => self::text($row[self::NAMES[$table]] ?? '')], $this->rows($table));
    return [
      'catalogs' => $catalogs,
      'funcionarios' => $this->employees(),
      'objetivos' => $pick('objetivos'),
      'metas' => $pick('metas'),
      'tareas' => $pick('tareas'),
      'estados_tarea' => self::TASK_STATES,
      'estados_meta' => self::GOAL_STATES,
      'periodos' => self::PERIODS,
    ];
  }

  /** Nombres de los funcionarios activos (el plan guarda responsables por nombre, como el plugin). @return list<string> */
  public function employees(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $names = array_map(static fn($name): string => self::text($name), $this->db->getCol("SELECT `nombre` FROM `{$table}` WHERE `activo` = 'Si' ORDER BY `nombre` ASC"));
    return array_values(array_unique(array_filter($names, static fn(string $name): bool => $name !== '')));
  }

  /** Un registro crudo del plan o null. @return array<string,mixed>|null */
  public function find(string $table, int $id): ?array
  {
    foreach ($this->rows($table) as $row) {
      if ((int) $row['_ID'] === $id) return $row;
    }
    return null;
  }

  /** Si el funcionario figura entre los responsables de la tarea o la campaña. */
  public function isResponsible(string $table, int $id, string $name): bool
  {
    $row = $this->find($table, $id);
    return $row !== null && self::includesName((string) ($row['responsable'] ?? ''), $name);
  }

  // ── Escritura ────────────────────────────────────────────────────────────

  /**
   * Crea o actualiza un objetivo, meta, tarea o campaña. Devuelve el _ID.
   * @param array<string,mixed> $input
   */
  public function save(string $table, int $id, array $input, string $author, ?int $now = null): int
  {
    if (!in_array($table, self::ENTITIES, true)) {
      throw new \InvalidArgumentException('Tipo de registro no válido.');
    }
    $current = $id > 0 ? $this->find($table, $id) : null;
    if ($id > 0 && $current === null) {
      throw new \InvalidArgumentException('El registro ya no existe. Recarga la lista.');
    }
    $data = match ($table) {
      'objetivos' => $this->objectiveData($input, $current),
      'metas' => $this->goalData($input, $current),
      'tareas' => $this->taskData($input, $current),
      default => $this->campaignData($input, $current),
    };
    $stamp = date('Y-m-d H:i:s', $now ?? time());
    $data += ['cct_modified' => $stamp, 'cct_author_id' => $author];
    $name = $this->db->table('jet_cct_' . $table);
    if ($current !== null) {
      $this->db->update($name, $data, ['_ID' => $id]);
    } else {
      $this->db->insert($name, $data + ['cct_status' => 'publish', 'cct_created' => $stamp]);
      $id = (int) $this->db->lastInsertId();
    }
    unset($this->cache[$table]);
    return $id;
  }

  /** Elimina un registro sin hijos; con hijos explica qué hay que mover o eliminar primero. Devuelve su nombre. */
  public function delete(string $table, int $id): string
  {
    if (!in_array($table, self::ENTITIES, true)) {
      throw new \InvalidArgumentException('Tipo de registro no válido.');
    }
    $row = $this->find($table, $id);
    if ($row === null) {
      throw new \InvalidArgumentException('El registro ya no existe. Recarga la lista.');
    }
    $children = match ($table) {
      'objetivos' => ['metas', count(array_filter($this->rows('metas'), static fn(array $meta): bool => (int) ($meta['id_objetivo'] ?? 0) === $id))],
      'metas' => ['tareas', count(array_filter($this->rows('tareas'), static fn(array $task): bool => in_array($id, self::ids($task['id_meta'] ?? ''), true)))],
      'tareas' => ['campañas', count(array_filter($this->rows('campanas'), static fn(array $campaign): bool => in_array($id, self::ids($campaign['id_tarea'] ?? ''), true)))],
      default => ['', 0],
    };
    if ($children[1] > 0) {
      $one = $children[1] === 1;
      throw new \InvalidArgumentException('No se puede eliminar: tiene ' . $children[1] . ' ' . ($one ? rtrim($children[0], 's') . ' asociada' : $children[0] . ' asociadas') . '. ' . ($one ? 'Elimínala o asígnala' : 'Elimínalas o asígnalas') . ' a otro registro primero.');
    }
    $this->db->delete($this->db->table('jet_cct_' . $table), ['_ID' => $id]);
    unset($this->cache[$table]);
    return self::text($row[self::NAMES[$table]] ?? '');
  }

  /**
   * Agrega un avance a la bitácora de la tarea (historial_avances, mismo formato del plugin) y, si se pide, la marca
   * Completado.
   */
  public function addProgress(int $taskId, string $text, bool $finish, string $employee, string $author, ?int $now = null): void
  {
    $task = $this->find('tareas', $taskId);
    if ($task === null) throw new \InvalidArgumentException('La tarea ya no existe. Recarga la lista.');
    $data = ['historial_avances' => $this->appendEntry((string) ($task['historial_avances'] ?? ''), $text, $employee, $author, $now)];
    if ($finish) $data['estado'] = 'Completado';
    elseif (($task['estado'] ?? '') === 'Pendiente') $data['estado'] = 'En Proceso';
    $this->db->update($this->db->table('jet_cct_tareas'), $data + ['cct_modified' => date('Y-m-d H:i:s', $now ?? time()), 'cct_author_id' => $author], ['_ID' => $taskId]);
    unset($this->cache['tareas']);
  }

  /** Agrega un resultado a la bitácora de la campaña (columna informes, JSON como historial_avances). */
  public function addResult(int $campaignId, string $text, string $employee, string $author, ?int $now = null): void
  {
    $campaign = $this->find('campanas', $campaignId);
    if ($campaign === null) throw new \InvalidArgumentException('La campaña ya no existe. Recarga la lista.');
    $this->db->update($this->db->table('jet_cct_campanas'), [
      'informes' => $this->appendEntry((string) ($campaign['informes'] ?? ''), $text, $employee, $author, $now),
      'cct_modified' => date('Y-m-d H:i:s', $now ?? time()),
      'cct_author_id' => $author,
    ], ['_ID' => $campaignId]);
    unset($this->cache['campanas']);
  }

  // ── Catálogos (Configuración › Plan estratégico) ─────────────────────────

  /**
   * Los catálogos con cuántos registros usan cada elemento.
   * @return array<string,array{label:string,hint:string,fields:array<string,string>,rows:list<array<string,mixed>>}>
   */
  public function catalogSettings(): array
  {
    $out = [];
    foreach (self::CATALOGS as $key => $definition) {
      $rows = [];
      foreach (array_reverse($this->rows($key)) as $row) {
        $item = ['id' => (int) $row['_ID'], 'nombre' => self::text($row[$definition['name']] ?? '')];
        foreach (array_keys($definition['fields']) as $field) $item[$field] = self::text($row[$field] ?? '');
        $item['uso'] = $this->usage($key, self::text($row[$definition['key']] ?? ''));
        $rows[] = $item;
      }
      $out[$key] = ['label' => $definition['label'], 'hint' => $definition['hint'], 'fields' => $definition['fields'], 'rows' => $rows];
    }
    return $out;
  }

  /**
   * Guarda los catálogos enviados (cada uno es la lista completa): agrega, actualiza, propaga los cambios de nombre a
   * los registros que los usan y elimina los que faltan si no están en uso. Todo en una transacción.
   * @param array<string,mixed> $data catálogo => filas {id?, nombre, campos}; confirm_delete para eliminar.
   * @return array{added:int,updated:int,deleted:int,renamed:int}
   */
  public function saveCatalogs(array $data, string $author, ?int $now = null): array
  {
    $stamp = date('Y-m-d H:i:s', $now ?? time());
    $totals = ['added' => 0, 'updated' => 0, 'deleted' => 0, 'renamed' => 0];
    $plans = [];
    foreach (self::CATALOGS as $key => $definition) {
      if (!is_array($data[$key] ?? null)) continue;
      $plans[$key] = $this->catalogPlan($key, $data[$key]);
    }
    $deletions = array_sum(array_map(static fn(array $plan): int => count($plan['delete']), $plans));
    if ($deletions > 0 && empty($data['confirm_delete'])) {
      throw new \InvalidArgumentException('Confirma la eliminación de ' . $deletions . ' elemento(s) antes de guardar.');
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($plans as $key => $plan) {
        $definition = self::CATALOGS[$key];
        $table = $this->db->table('jet_cct_' . $key);
        foreach ($plan['update'] as $id => [$values, $from, $to]) {
          $this->db->update($table, $values + ['cct_modified' => $stamp, 'cct_author_id' => $author], ['_ID' => $id]);
          $totals['updated']++;
          if ($definition['key'] !== '_ID' && $from !== $to) {
            $totals['renamed'] += $this->propagate($key, $from, $to, $stamp);
          }
        }
        foreach ($plan['add'] as $values) {
          $this->db->insert($table, $values + ['cct_status' => 'publish', 'cct_created' => $stamp, 'cct_modified' => $stamp, 'cct_author_id' => $author]);
          $totals['added']++;
        }
        foreach ($plan['delete'] as $id) {
          $this->db->delete($table, ['_ID' => $id]);
          $totals['deleted']++;
        }
        unset($this->cache[$key]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    } finally {
      $this->cache = [];
    }
    return $totals;
  }

  /**
   * Valida un catálogo completo y separa qué se agrega, actualiza y elimina.
   * @param array<int|string,mixed> $rows
   * @return array{add:list<array<string,string>>,update:array<int,array{0:array<string,string>,1:string,2:string}>,delete:list<int>}
   */
  private function catalogPlan(string $key, array $rows): array
  {
    $definition = self::CATALOGS[$key];
    $existing = [];
    foreach ($this->rows($key) as $row) $existing[(int) $row['_ID']] = $row;
    $plan = ['add' => [], 'update' => [], 'delete' => []];
    $seen = [];
    $keys = [];
    $kept = [];
    foreach ($rows as $raw) {
      if (!is_array($raw)) continue;
      $values = [$definition['name'] => self::limit(self::text($raw['nombre'] ?? ''), 200)];
      foreach (array_keys($definition['fields']) as $field) $values[$field] = self::limit(self::text($raw[$field] ?? ''), self::MAX_TEXT);
      if ($values[$definition['name']] === '') {
        throw new \InvalidArgumentException($definition['label'] . ': cada fila necesita un nombre.');
      }
      $fold = self::fold($values[$definition['name']]);
      if (isset($seen[$fold])) throw new \InvalidArgumentException($definition['label'] . ': «' . $values[$definition['name']] . '» está repetido.');
      $seen[$fold] = true;
      if ($definition['key'] !== '_ID' && $definition['key'] !== $definition['name']) {
        if ($values[$definition['key']] === '') throw new \InvalidArgumentException($definition['label'] . ': «' . $values[$definition['name']] . '» necesita ' . strtolower($definition['fields'][$definition['key']]) . '.');
        $keyFold = self::fold($values[$definition['key']]);
        if (isset($keys[$keyFold])) throw new \InvalidArgumentException($definition['label'] . ': el valor «' . $values[$definition['key']] . '» está repetido.');
        $keys[$keyFold] = true;
      }
      $id = (int) ($raw['id'] ?? 0);
      if ($id > 0 && isset($existing[$id])) {
        $kept[$id] = true;
        $from = self::text($existing[$id][$definition['key']] ?? '');
        $to = $definition['key'] === '_ID' ? $from : $values[$definition['key']];
        $changed = array_filter($values, static fn(string $value, string $column): bool => self::text($existing[$id][$column] ?? '') !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) $plan['update'][$id] = [$values, $from, $to];
      } else {
        $plan['add'][] = $values;
      }
    }
    foreach ($existing as $id => $row) {
      if (isset($kept[$id])) continue;
      $value = self::text($row[$definition['key']] ?? '');
      $uses = $this->usage($key, $value);
      if ($uses > 0) {
        throw new \InvalidArgumentException($definition['label'] . ': «' . self::text($row[$definition['name']] ?? '') . '» lo usan ' . $uses . ' registro(s) del plan; cámbialos antes de eliminarlo.');
      }
      $plan['delete'][] = $id;
    }
    return $plan;
  }

  /** Cuántos registros del plan usan un valor del catálogo. */
  private function usage(string $catalog, string $value): int
  {
    if ($value === '') return 0;
    $count = 0;
    foreach (self::CATALOGS[$catalog]['used_by'] as [$table, $column, $isList]) {
      foreach ($this->rows($table) as $row) {
        $stored = (string) ($row[$column] ?? '');
        $count += ($isList ? in_array(self::fold($value), array_map([self::class, 'fold'], self::split($stored)), true) : self::fold($stored) === self::fold($value)) ? 1 : 0;
      }
    }
    return $count;
  }

  /** Reemplaza un valor renombrado del catálogo en los registros que lo guardan (texto exacto o dentro de la lista). */
  private function propagate(string $catalog, string $from, string $to, string $stamp): int
  {
    $changed = 0;
    foreach (self::CATALOGS[$catalog]['used_by'] as [$table, $column, $isList]) {
      unset($this->cache[$table]);
      foreach ($this->rows($table) as $row) {
        $stored = (string) ($row[$column] ?? '');
        if ($isList) {
          $items = self::split($stored);
          $next = array_map(static fn(string $item): string => self::fold($item) === self::fold($from) ? $to : $item, $items);
          if ($next === $items) continue;
          $value = implode(',', array_values(array_unique($next)));
        } else {
          if (self::fold($stored) !== self::fold($from)) continue;
          $value = $to;
        }
        $this->db->update($this->db->table('jet_cct_' . $table), [$column => $value, 'cct_modified' => $stamp], ['_ID' => (int) $row['_ID']]);
        $changed++;
      }
      unset($this->cache[$table]);
    }
    return $changed;
  }

  // ── Validación de cada tipo de registro ──────────────────────────────────

  /** @param array<string,mixed> $input @param array<string,mixed>|null $current @return array<string,string> */
  private function objectiveData(array $input, ?array $current): array
  {
    $name = self::required($input, 'nombre_objetivo', 'el objetivo');
    $perspective = self::text($input['perspectiva'] ?? '');
    $this->requireCatalogValue('gestiones', $perspective, 'la gestión', $current['perspectiva'] ?? '', true);
    $period = self::text($input['periodo_tipo'] ?? '');
    if ($period !== '' && !in_array($period, self::PERIODS, true) && $period !== self::text($current['periodo_tipo'] ?? '')) {
      throw new \InvalidArgumentException('Elige un periodo válido.');
    }
    return [
      'nombre_objetivo' => $name,
      'perspectiva' => $perspective,
      'periodo_tipo' => $period,
      'periodo_valor' => self::limit(self::text($input['periodo_valor'] ?? ''), 40),
      'valor_objetivo' => self::limit(self::text($input['valor_objetivo'] ?? ''), 60),
      'responsable_area' => $this->namesValue($input['responsable_area'] ?? [], (string) ($current['responsable_area'] ?? '')),
    ];
  }

  /** @param array<string,mixed> $input @param array<string,mixed>|null $current @return array<string,string|int> */
  private function goalData(array $input, ?array $current): array
  {
    $name = self::required($input, 'nombre_meta', 'la meta');
    $objective = (int) ($input['id_objetivo'] ?? 0);
    if ($this->find('objetivos', $objective) === null) throw new \InvalidArgumentException('Elige el objetivo al que pertenece la meta.');
    $unit = self::text($input['unidad'] ?? '');
    $this->requireCatalogValue('unidades', $unit, 'la unidad', $current['unidad'] ?? '', false);
    $state = self::text($input['estado'] ?? 'Sin realizar');
    if (!in_array($state, self::GOAL_STATES, true)) throw new \InvalidArgumentException('Elige un estado válido para la meta.');
    return [
      'nombre_meta' => $name,
      'id_objetivo' => (string) $objective,
      'valor_actual' => self::number($input['valor_actual'] ?? '', 'el valor actual'),
      'valor_meta' => self::number($input['valor_meta'] ?? '', 'el valor de la meta'),
      'unidad' => $unit,
      'fecha_limite' => self::timestamp($input['fecha_limite'] ?? '', 'la fecha límite'),
      'estado' => $state,
    ];
  }

  /** @param array<string,mixed> $input @param array<string,mixed>|null $current @return array<string,string|int> */
  private function taskData(array $input, ?array $current): array
  {
    $name = self::required($input, 'nombre_tarea', 'la tarea');
    $goals = $this->existingIds('metas', $input['id_meta'] ?? []);
    if ($goals === []) throw new \InvalidArgumentException('Elige al menos una meta para la tarea.');
    $state = self::text($input['estado'] ?? 'Pendiente');
    if (!in_array($state, self::TASK_STATES, true)) throw new \InvalidArgumentException('Elige un estado válido para la tarea.');
    $start = self::timestamp($input['fecha_inicio'] ?? '', 'la fecha de inicio');
    $end = self::timestamp($input['fecha_fin'] ?? '', 'la fecha de fin');
    if ($start > 0 && $end > 0 && $end < $start) throw new \InvalidArgumentException('La fecha de fin no puede ser anterior a la de inicio.');
    return [
      'nombre_tarea' => $name,
      'id_meta' => implode(',', $goals),
      'descripcion' => self::limit(self::text($input['descripcion'] ?? ''), self::MAX_LONG),
      'responsable' => $this->namesValue($input['responsable'] ?? [], (string) ($current['responsable'] ?? '')),
      'estado' => $state,
      'fecha_inicio' => $start,
      'fecha_fin' => $end,
      'recursos' => self::limit(self::text($input['recursos'] ?? ''), self::MAX_LONG),
      'id_actor' => implode(',', $this->existingIds('actores', $input['id_actor'] ?? [])),
    ];
  }

  /** @param array<string,mixed> $input @param array<string,mixed>|null $current @return array<string,string> */
  private function campaignData(array $input, ?array $current): array
  {
    $name = self::required($input, 'nombre_campana', 'la campaña');
    $type = self::text($input['tipo_campana'] ?? '');
    $this->requireCatalogValue('tipos_campana', $type, 'el tipo de campaña', $current['tipo_campana'] ?? '', true);
    $frequency = self::text($input['frecuencia'] ?? '');
    $this->requireCatalogValue('frecuencias', $frequency, 'la frecuencia', $current['frecuencia'] ?? '', false);
    return [
      'nombre_campana' => $name,
      'id_tarea' => implode(',', $this->existingIds('tareas', $input['id_tarea'] ?? [])),
      'tipo_campana' => $type,
      'id_actor' => implode(',', $this->existingIds('actores', $input['id_actor'] ?? [])),
      'tipo_medio' => $this->catalogList('tipos_medio', $input['tipo_medio'] ?? [], (string) ($current['tipo_medio'] ?? '')),
      'tipo_mercadeo' => $this->catalogList('tipos_mercadeo', $input['tipo_mercadeo'] ?? [], (string) ($current['tipo_mercadeo'] ?? '')),
      'frecuencia' => $frequency,
      'responsable' => $this->namesValue($input['responsable'] ?? [], (string) ($current['responsable'] ?? '')),
      'pieza_actual' => self::limit(self::text($input['pieza_actual'] ?? ''), self::MAX_LONG),
    ];
  }

  /** Un valor de catálogo debe existir; se acepta el que ya tenía el registro aunque ya no esté en el catálogo. */
  private function requireCatalogValue(string $catalog, string $value, string $label, mixed $current, bool $required): void
  {
    if ($value === '') {
      if ($required) throw new \InvalidArgumentException('Elige ' . $label . '.');
      return;
    }
    if ($value === self::text($current)) return;
    $key = self::CATALOGS[$catalog]['key'];
    foreach ($this->rows($catalog) as $row) {
      if (self::text($row[$key] ?? '') === $value) return;
    }
    throw new \InvalidArgumentException('Elige ' . $label . ' de la lista.');
  }

  /** Lista de valores de un catálogo (separada por comas), conservando los que ya tenía el registro. */
  private function catalogList(string $catalog, mixed $raw, string $current): string
  {
    $key = self::CATALOGS[$catalog]['key'];
    $allowed = array_map(static fn(array $row): string => self::text($row[$key] ?? ''), $this->rows($catalog));
    $kept = self::split($current);
    $values = array_values(array_unique(array_filter(self::values($raw), static fn(string $value): bool => in_array($value, $allowed, true) || in_array($value, $kept, true))));
    return implode(',', $values);
  }

  /** Responsables: funcionarios activos, o los que ya tenía el registro (personas retiradas siguen visibles). */
  private function namesValue(mixed $raw, string $current): string
  {
    $allowed = array_merge($this->employees(), self::split($current));
    return implode(',', array_values(array_unique(array_filter(self::values($raw), static fn(string $name): bool => in_array($name, $allowed, true)))));
  }

  /** Ids enviados que existen en la tabla, sin repetir y en el orden recibido. @return list<int> */
  private function existingIds(string $table, mixed $raw): array
  {
    $existing = array_map(static fn(array $row): int => (int) $row['_ID'], $this->rows($table));
    return array_values(array_unique(array_filter(array_map('intval', self::values($raw)), static fn(int $id): bool => in_array($id, $existing, true))));
  }

  // ── Presentación ─────────────────────────────────────────────────────────

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function present(string $table, array $row, string $currentName): array
  {
    $id = (int) $row['_ID'];
    $out = ['id' => $id, 'nombre' => self::text($row[self::NAMES[$table]] ?? '')];
    $names = fn(string $source, mixed $ids): array => array_values(array_filter(array_map(fn(int $item): string => self::text($this->find($source, $item)[self::NAMES[$source]] ?? ''), self::ids($ids))));
    switch ($table) {
      case 'objetivos':
        $goals = array_filter($this->rows('metas'), static fn(array $meta): bool => (int) ($meta['id_objetivo'] ?? 0) === $id);
        return $out + [
          'perspectiva' => self::text($row['perspectiva'] ?? ''),
          'periodo_tipo' => self::text($row['periodo_tipo'] ?? ''),
          'periodo_valor' => self::text($row['periodo_valor'] ?? ''),
          'valor_objetivo' => self::text($row['valor_objetivo'] ?? ''),
          'responsables' => self::split((string) ($row['responsable_area'] ?? '')),
          'metas_total' => count($goals),
          'metas_realizadas' => count(array_filter($goals, static fn(array $meta): bool => ($meta['estado'] ?? '') === 'Realizado')),
        ];
      case 'metas':
        $tasks = array_filter($this->rows('tareas'), static fn(array $task): bool => in_array($id, self::ids($task['id_meta'] ?? ''), true));
        $current = (float) self::numeric($row['valor_actual'] ?? 0);
        $target = (float) self::numeric($row['valor_meta'] ?? 0);
        $objective = $this->find('objetivos', (int) ($row['id_objetivo'] ?? 0));
        return $out + [
          'id_objetivo' => (string) (int) ($row['id_objetivo'] ?? 0),
          'objetivo' => self::text($objective['nombre_objetivo'] ?? ''),
          'valor_actual' => self::numeric($row['valor_actual'] ?? ''),
          'valor_meta' => self::numeric($row['valor_meta'] ?? ''),
          'unidad' => self::text($row['unidad'] ?? ''),
          'fecha_limite' => self::dateOf((int) ($row['fecha_limite'] ?? 0)),
          'estado' => self::text($row['estado'] ?? '') ?: 'Sin realizar',
          'cumplimiento' => $target > 0 ? (int) round(min(100, max(0, $current / $target * 100))) : null,
          'tareas_total' => count($tasks),
          'tareas_completadas' => count(array_filter($tasks, static fn(array $task): bool => ($task['estado'] ?? '') === 'Completado')),
        ];
      case 'tareas':
        $responsables = self::split((string) ($row['responsable'] ?? ''));
        return $out + [
          'descripcion' => self::text($row['descripcion'] ?? ''),
          'id_meta' => array_map('strval', self::ids($row['id_meta'] ?? '')),
          'metas' => $names('metas', $row['id_meta'] ?? ''),
          'responsables' => $responsables,
          'estado' => self::text($row['estado'] ?? '') ?: 'Pendiente',
          'fecha_inicio' => self::dateOf((int) ($row['fecha_inicio'] ?? 0)),
          'fecha_fin' => self::dateOf((int) ($row['fecha_fin'] ?? 0)),
          'recursos' => self::text($row['recursos'] ?? ''),
          'id_actor' => array_map('strval', self::ids($row['id_actor'] ?? '')),
          'actores' => $names('actores', $row['id_actor'] ?? ''),
          'avances' => self::entries((string) ($row['historial_avances'] ?? '')),
          'campanas_total' => count(array_filter($this->rows('campanas'), static fn(array $campaign): bool => in_array($id, self::ids($campaign['id_tarea'] ?? ''), true))),
          'mine' => self::includesName((string) ($row['responsable'] ?? ''), $currentName),
        ];
      case 'campanas':
        return $out + [
          'tipo_campana' => self::text($row['tipo_campana'] ?? ''),
          'id_tarea' => array_map('strval', self::ids($row['id_tarea'] ?? '')),
          'tareas' => $names('tareas', $row['id_tarea'] ?? ''),
          'id_actor' => array_map('strval', self::ids($row['id_actor'] ?? '')),
          'actores' => $names('actores', $row['id_actor'] ?? ''),
          'tipos_medio' => self::split((string) ($row['tipo_medio'] ?? '')),
          'tipos_mercadeo' => self::split((string) ($row['tipo_mercadeo'] ?? '')),
          'frecuencia' => self::text($row['frecuencia'] ?? ''),
          'responsables' => self::split((string) ($row['responsable'] ?? '')),
          'pieza_actual' => self::text($row['pieza_actual'] ?? ''),
          'resultados' => self::entries((string) ($row['informes'] ?? '')),
          'mine' => self::includesName((string) ($row['responsable'] ?? ''), $currentName),
        ];
    }
    return $out;
  }

  /** @return list<array<string,mixed>> */
  private function rows(string $table): array
  {
    if (!isset($this->cache[$table])) {
      $name = $this->db->table('jet_cct_' . $table);
      $this->cache[$table] = $this->db->getResults("SELECT * FROM `{$name}` ORDER BY `_ID` DESC");
    }
    return $this->cache[$table];
  }

  /** Agrega {fecha, funcionario, id_empleado, descripcion} a una bitácora JSON; un texto suelto anterior se conserva. */
  private function appendEntry(string $stored, string $text, string $employee, string $author, ?int $now): string
  {
    $text = self::limit(self::text($text), self::MAX_TEXT);
    if ($text === '') throw new \InvalidArgumentException('Escribe la descripción.');
    $entries = self::entries($stored);
    $entries[] = ['fecha' => date('Y-m-d H:i:s', $now ?? time()), 'funcionario' => $employee, 'id_empleado' => $author, 'descripcion' => $text];
    return (string) json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /** @return list<array{fecha:string,funcionario:string,descripcion:string}> */
  public static function entries(string $stored): array
  {
    $stored = trim($stored);
    if ($stored === '') return [];
    $decoded = json_decode($stored, true);
    if (!is_array($decoded)) {
      return [['fecha' => '', 'funcionario' => '', 'descripcion' => $stored]];
    }
    $out = [];
    foreach ($decoded as $entry) {
      if (!is_array($entry)) continue;
      $out[] = ['fecha' => self::text($entry['fecha'] ?? ''), 'funcionario' => self::text($entry['funcionario'] ?? ''), 'descripcion' => self::text($entry['descripcion'] ?? '')];
    }
    return $out;
  }

  // ── Utilidades ───────────────────────────────────────────────────────────

  /**
   * Fecha AAAA-MM-DD de un timestamp de JetEngine. Los guardados por JetEngine son medianoche UTC y los del plugin
   * medianoche de Bogotá: sumando 12 h y leyendo en Bogotá los dos dan el mismo día.
   */
  public static function dateOf(int $timestamp): string
  {
    if ($timestamp <= 0) return '';
    return (new \DateTimeImmutable('@' . ($timestamp + 43200)))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
  }

  /** Timestamp de medianoche UTC (como JetEngine) de una fecha AAAA-MM-DD; vacío = 0. */
  public static function timestamp(mixed $value, string $label = 'la fecha'): int
  {
    $value = self::text($value);
    if ($value === '') return 0;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) !== 1 || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
      throw new \InvalidArgumentException('Revisa ' . $label . ' (AAAA-MM-DD).');
    }
    return (int) gmmktime(0, 0, 0, (int) $match[2], (int) $match[3], (int) $match[1]);
  }

  public static function includesName(string $list, string $name): bool
  {
    $name = self::fold($name);
    return $name !== '' && in_array($name, array_map([self::class, 'fold'], self::split($list)), true);
  }

  /** @return list<string> */
  public static function split(string $value): array
  {
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
  }

  /** @return list<int> */
  private static function ids(mixed $value): array
  {
    return array_values(array_unique(array_filter(array_map('intval', self::split((string) $value)), static fn(int $id): bool => $id > 0)));
  }

  /** Valores de un campo múltiple: arreglo o texto separado por comas. @return list<string> */
  private static function values(mixed $raw): array
  {
    $items = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
    return array_values(array_filter(array_map(static fn($item): string => is_scalar($item) ? self::text($item) : '', $items), static fn(string $item): bool => $item !== ''));
  }

  /** @param array<string,mixed> $input */
  private static function required(array $input, string $field, string $label): string
  {
    $value = self::limit(self::text($input[$field] ?? ''), self::MAX_TEXT);
    if ($value === '') throw new \InvalidArgumentException('Escribe ' . $label . '.');
    return $value;
  }

  /** Número con punto decimal; acepta coma decimal. Vacío = '0'. */
  private static function number(mixed $value, string $label): string
  {
    $text = str_replace([' ', ','], ['', '.'], self::text($value));
    if ($text === '') return '0';
    if (!is_numeric($text)) throw new \InvalidArgumentException('Escribe ' . $label . ' como número.');
    return self::numeric($text);
  }

  /** Número guardado como texto, sin ceros de sobra ("736", "12.5"). */
  private static function numeric(mixed $value): string
  {
    $text = str_replace(',', '.', self::text($value));
    if (!is_numeric($text)) return '0';
    $number = (float) $text;
    return floor($number) === $number && abs($number) < 1e15 ? (string) (int) $number : rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
  }

  private static function text(mixed $value): string
  {
    return is_scalar($value) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '') : '';
  }

  private static function limit(string $value, int $max): string
  {
    return mb_substr($value, 0, $max);
  }

  /** Comparación sin mayúsculas ni tildes. */
  private static function fold(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
  }
}
