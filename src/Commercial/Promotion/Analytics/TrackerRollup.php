<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Mantiene los resúmenes diarios a partir de `jet_cct_tracker_logs`.
 *
 * Es incremental por _ID: el último _ID resumido (marca de agua) se guarda en scm_tracker_state junto con las sumas del
 * mismo lote, en una sola transacción, así que repetir una corrida nunca cuenta dos veces. Todo lo que escribe va bajo
 * TrackerLock (sin espera): el cron (bin/tracker-rollup.php) y la puesta al día de la página no se pisan.
 *
 * Solo se resumen visitas con más de SETTLE_SECONDS: InnoDB reparte el AUTO_INCREMENT al insertar, no al confirmar, así
 * que una visita con _ID menor puede aparecer después de otra con _ID mayor. Si la marca de agua pasara por encima de
 * ella, nunca se contaría.
 *
 * El recálculo (--rebuild-from) va un día por transacción (borra y vuelve a sumar ese día), con una marca de "recálculo
 * pendiente" en el estado: si se corta, cada día queda con el total viejo o con el nuevo, nunca a medias, y la siguiente
 * corrida del cron lo retoma.
 *
 * La tabla cruda es de dashboard-marketing, que la sigue escribiendo y leyendo: aquí solo se lee, salvo la purga opcional
 * de purge(), que solo corre desde la línea de comandos.
 */
final class TrackerRollup
{
  public const DEFAULT_BATCH = 1000;
  /** Máximo de filas que procesa la página al abrirse; el resto lo hace el cron. */
  public const CATCH_UP_ROWS = 5000;
  /** Antigüedad mínima de una visita para resumirla (ver la nota de la clase). */
  public const SETTLE_SECONDS = 120;
  private const UPSERT_CHUNK = 200;
  private const RAW_COLUMNS = '`_ID`, `camp_slug`, `user_id`, `ip_address`, `user_agent`, `page_url`, `referrer`, `fecha_visita`, `country`, `city`, `event_type`, `object_id`, `object_ref`, `utm_source`, `utm_medium`';

  private Database $db;
  private Settings $settings;
  private VisitorHasher $hasher;
  private TrackerLock $lock;
  private int $lockDepth = 0;
  /** @var \Closure():string */
  private \Closure $today;
  /** @var \Closure():int */
  private \Closure $now;

  /**
   * @param (\Closure():string)|null $today Día de hoy (AAAA-MM-DD), para las pruebas.
   * @param (\Closure():int)|null $now Hora actual (timestamp), para las pruebas.
   */
  public function __construct(Database $db, Settings $settings, VisitorHasher $hasher, ?TrackerLock $lock = null, ?\Closure $today = null, ?\Closure $now = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->hasher = $hasher;
    $this->lock = $lock ?? new TrackerLock($db);
    $this->today = $today ?? static fn(): string => date('Y-m-d');
    $this->now = $now ?? static fn(): int => time();
  }

  /**
   * Estado guardado del rollup (scm_tracker_state). Sin la tabla todavía, todo en cero.
   * @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>,rebuild:array<string,string>}
   */
  public static function state(Database $db): array
  {
    try {
      $row = $db->getRow('SELECT * FROM `' . TrackerSchema::state($db) . '` WHERE `id` = 1');
    } catch (\Throwable) {
      $row = null;
    }
    $row = is_array($row) ? $row : [];
    $json = static function (mixed $value): array {
      $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
      return is_array($decoded) ? $decoded : [];
    };
    $rebuild = array_map('strval', array_filter($json($row['rebuild'] ?? ''), 'is_scalar'));
    return [
      'last_id' => max(0, (int) ($row['last_id'] ?? 0)),
      'last_fecha' => (string) ($row['last_fecha'] ?? ''),
      'updated_at' => (string) ($row['updated_at'] ?? ''),
      'purged_before' => (string) ($row['purged_before'] ?? ''),
      'last_purge' => $json($row['last_purge'] ?? ''),
      'rebuild' => isset($rebuild['next'], $rebuild['from']) ? $rebuild : [],
    ];
  }

  /**
   * Estado para Configuración y para la página: último _ID y fecha procesados y filas pendientes. Solo lee.
   * @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>,rebuild:array<string,string>,pending:int,schema_ready:bool}
   */
  public function status(): array
  {
    return self::readStatus($this->db, $this->settings);
  }

  /** @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>,rebuild:array<string,string>,pending:int,schema_ready:bool} */
  public static function readStatus(Database $db, Settings $settings): array
  {
    $state = self::state($db);
    $pending = (int) $db->getVar('SELECT COUNT(*) FROM `' . TrackerSchema::raw($db) . '` WHERE `_ID` > ?', [$state['last_id']]);
    return $state + ['pending' => $pending, 'schema_ready' => TrackerSchema::isReady($settings)];
  }

  /**
   * Ejecuta $work con el candado tomado (reentrante dentro de esta instancia).
   * @template T
   * @param callable():T $work
   * @return T
   * @throws TrackerRollupBusy Si otro proceso ya está resumiendo.
   * @throws \InvalidArgumentException Si $work la lanza (p. ej. una fecha de --rebuild-from no válida).
   */
  public function locked(callable $work)
  {
    if ($this->lockDepth === 0) {
      if (!$this->lock->acquire()) {
        throw new TrackerRollupBusy('Otro proceso está actualizando los resúmenes de la analítica web.');
      }
      // La versión del esquema vive en Settings: se relee por si otro proceso la cambió.
      $this->settings->refresh();
    }
    $this->lockDepth++;
    try {
      return $work();
    } finally {
      $this->lockDepth--;
      if ($this->lockDepth === 0) {
        $this->lock->release();
      }
    }
  }

  /**
   * Puesta al día acotada al abrir la página: si otro proceso tiene el candado no espera.
   * @return array{ran:bool,busy:bool,deferred:bool,processed:int,behind:bool,last_id:int,last_fecha:string,updated_at:string,rebuild:array<string,string>}
   */
  public function catchUp(int $maxRows = self::CATCH_UP_ROWS): array
  {
    try {
      $result = $this->locked(function () use ($maxRows): array {
        TrackerSchema::ensure($this->db, $this->settings);
        return $this->run($maxRows);
      });
      $ran = true;
      $busy = false;
    } catch (TrackerRollupBusy) {
      $result = ['processed' => 0];
      $ran = false;
      $busy = true;
    }
    return $this->syncStatus($ran, $busy, false, (int) $result['processed']);
  }

  /**
   * Lo mismo que catchUp() pero sin resumir nada (solo lee): para la carga completa de la página, que no debe trabajar con
   * la sesión de PHP abierta. La pantalla pide la puesta al día después, por AJAX.
   * @return array{ran:bool,busy:bool,deferred:bool,processed:int,behind:bool,last_id:int,last_fecha:string,updated_at:string,rebuild:array<string,string>}
   */
  public function pendingStatus(): array
  {
    return $this->syncStatus(false, false, true, 0);
  }

  /** @return array{ran:bool,busy:bool,deferred:bool,processed:int,behind:bool,last_id:int,last_fecha:string,updated_at:string,rebuild:array<string,string>} */
  private function syncStatus(bool $ran, bool $busy, bool $deferred, int $processed): array
  {
    $state = self::state($this->db);
    $next = $this->db->getVar('SELECT `fecha_visita` FROM `' . TrackerSchema::raw($this->db) . '` WHERE `_ID` > ? ORDER BY `_ID` LIMIT 1', [$state['last_id']]);
    return [
      'ran' => $ran,
      'busy' => $busy,
      'deferred' => $deferred,
      'processed' => $processed,
      // Lo que llegó hace menos de SETTLE_SECONDS no cuenta como atraso: se resume en la siguiente pasada.
      'behind' => $next !== null && !$this->isRecent((string) $next),
      'last_id' => $state['last_id'],
      'last_fecha' => $state['last_fecha'],
      'updated_at' => $state['updated_at'],
      'rebuild' => $state['rebuild'],
    ];
  }

  /**
   * Procesa las filas nuevas (por encima de la marca de agua) en lotes, hasta la primera visita demasiado reciente.
   * @param int|null $maxRows Tope de filas crudas a leer; null = hasta ponerse al día.
   * @param (callable(int,int):void)|null $progress Recibe (filas leídas en total, último _ID).
   * @return array{processed:int,counted:int,batches:int,last_id:int}
   */
  public function run(?int $maxRows = null, int $batch = self::DEFAULT_BATCH, ?callable $progress = null): array
  {
    return $this->locked(function () use ($maxRows, $batch, $progress): array {
      TrackerSchema::ensure($this->db, $this->settings);
      $batch = max(1, min(10000, $batch));
      $processed = 0;
      $counted = 0;
      $batches = 0;
      while ($maxRows === null || $processed < $maxRows) {
        $limit = $maxRows === null ? $batch : min($batch, $maxRows - $processed);
        $state = self::state($this->db);
        $rows = $this->db->getResults(
          'SELECT ' . self::RAW_COLUMNS . ' FROM `' . TrackerSchema::raw($this->db) . '` WHERE `_ID` > ? ORDER BY `_ID` ASC LIMIT ' . (int) $limit,
          [$state['last_id']]
        );
        $read = count($rows);
        $rows = $this->settled($rows);
        if ($rows === []) {
          break;
        }
        $last = $rows[count($rows) - 1];
        $lastFecha = (string) ($last['fecha_visita'] ?? '');
        $counted += $this->transaction(function () use ($rows, $last, $lastFecha, $state): int {
          $counted = $this->store($rows, null);
          $this->writeState([
            'last_id' => (int) $last['_ID'],
            'last_fecha' => TrackerAggregator::day($lastFecha) !== null ? $lastFecha : $state['last_fecha'],
            'updated_at' => date('Y-m-d H:i:s', ($this->now)()),
          ]);
          return $counted;
        });
        $processed += count($rows);
        $batches++;
        if ($progress !== null) {
          $progress($processed, (int) $last['_ID']);
        }
        if (count($rows) < $read || $read < $limit) {
          break;
        }
      }
      return ['processed' => $processed, 'counted' => $counted, 'batches' => $batches, 'last_id' => self::state($this->db)['last_id']];
    });
  }

  /**
   * Recalcula los días desde $from (p. ej. después de cambiar las IPs internas): deja la marca de recálculo pendiente y
   * rehace un día por transacción. Si las crudas de un día ya se purgaron, empieza en el primer día completo.
   * @param (callable(int,string):void)|null $progress Recibe (filas crudas leídas en total, día recién terminado).
   * @return array{from:string,requested:string,deleted:int,processed:int,counted:int,days:int}
   */
  public function rebuildFrom(string $from, int $batch = self::DEFAULT_BATCH, ?callable $progress = null): array
  {
    if (TrackerAggregator::day($from . ' 00:00') === null) {
      throw new \InvalidArgumentException('La fecha para recalcular debe tener el formato AAAA-MM-DD.');
    }
    if ($from > ($this->today)()) {
      throw new \InvalidArgumentException('La fecha para recalcular no puede ser futura.');
    }
    return $this->locked(function () use ($from, $batch, $progress): array {
      TrackerSchema::ensure($this->db, $this->settings);
      $state = self::state($this->db);
      $raw = TrackerSchema::raw($this->db);
      $start = $from;
      $firstRaw = TrackerAggregator::day((string) $this->db->getVar("SELECT MIN(`fecha_visita`) FROM `{$raw}` WHERE `fecha_visita` >= '2000-01-01' AND `fecha_visita` < '9999'"));
      if ($firstRaw !== null && $firstRaw > $start) {
        $start = $firstRaw;
      }
      if ($state['purged_before'] !== '' && $state['purged_before'] > $start) {
        $start = $state['purged_before'];
      }
      if ($firstRaw === null) {
        return ['from' => $start, 'requested' => $from, 'deleted' => 0, 'processed' => 0, 'counted' => 0, 'days' => 0];
      }
      $this->transaction(fn() => $this->writeState(['rebuild' => ['from' => $start, 'requested' => $from, 'next' => $start]]));
      return $this->resumeRebuild($batch, $progress) ?? ['from' => $start, 'requested' => $from, 'deleted' => 0, 'processed' => 0, 'counted' => 0, 'days' => 0];
    });
  }

  /**
   * Sigue un recálculo pendiente (cortado o recién empezado). null si no hay ninguno.
   * @param (callable(int,string):void)|null $progress
   * @return array{from:string,requested:string,deleted:int,processed:int,counted:int,days:int}|null
   */
  public function resumeRebuild(int $batch = self::DEFAULT_BATCH, ?callable $progress = null): ?array
  {
    return $this->locked(function () use ($batch, $progress): ?array {
      TrackerSchema::ensure($this->db, $this->settings);
      $rebuild = self::state($this->db)['rebuild'];
      if ($rebuild === []) {
        return null;
      }
      $batch = max(1, min(10000, $batch));
      // Hasta mañana: una visita con la hora del servidor un poco adelantada también se recalcula.
      $end = TrackerAnalyticsFilters::shift(($this->today)(), 1);
      $totals = ['deleted' => 0, 'processed' => 0, 'counted' => 0, 'days' => 0];
      for ($day = $rebuild['next']; ; $day = TrackerAnalyticsFilters::shift($day, 1)) {
        $next = TrackerAnalyticsFilters::shift($day, 1);
        $done = $next > $end;
        [$deleted, $processed, $counted] = $this->transaction(function () use ($day, $next, $done, $rebuild, $batch): array {
          $result = $this->rebuildDay($day, $next, $batch);
          $this->writeState(['rebuild' => $done ? [] : array_replace($rebuild, ['next' => $next])]);
          return $result;
        });
        $totals['deleted'] += $deleted;
        $totals['processed'] += $processed;
        $totals['counted'] += $counted;
        $totals['days']++;
        if ($progress !== null) {
          $progress($totals['processed'], $day);
        }
        if ($done) {
          break;
        }
      }
      return ['from' => $rebuild['from'], 'requested' => $rebuild['requested'] ?? $rebuild['from']] + $totals;
    });
  }

  /**
   * Borra los resúmenes de un día y vuelve a sumar sus filas crudas ya procesadas (_ID <= marca de agua). Va dentro de la
   * transacción del llamador. Un día cuyas crudas ya se purgaron no se toca.
   * @return array{0:int,1:int,2:int} [filas de resumen borradas, filas crudas leídas, vistas sumadas]
   */
  private function rebuildDay(string $day, string $next, int $batch): array
  {
    $state = self::state($this->db);
    if ($state['purged_before'] !== '' && $day < $state['purged_before']) {
      return [0, 0, 0];
    }
    $deleted = 0;
    $dims = implode(', ', array_map(static fn(string $dim): string => "'" . $dim . "'", TrackerSchema::TOP_DIMS));
    foreach ([
      'DELETE FROM `' . TrackerSchema::daily($this->db) . '` WHERE `fecha` = ?',
      // La llave de top empieza por (dim, fecha): con el IN de las cinco dimensiones el borrado usa la llave.
      'DELETE FROM `' . TrackerSchema::top($this->db) . "` WHERE `dim` IN ({$dims}) AND `fecha` = ?",
      'DELETE FROM `' . TrackerSchema::visitors($this->db) . '` WHERE `fecha` = ?',
    ] as $sql) {
      $statement = $this->db->pdo()->prepare($sql);
      $statement->execute([$day]);
      $deleted += $statement->rowCount();
    }
    $processed = 0;
    $counted = 0;
    $cursor = 0;
    while (true) {
      $rows = $this->db->getResults(
        'SELECT ' . self::RAW_COLUMNS . ' FROM `' . TrackerSchema::raw($this->db) . '` WHERE `fecha_visita` >= ? AND `fecha_visita` < ? AND `_ID` > ? AND `_ID` <= ? ORDER BY `_ID` ASC LIMIT ' . $batch,
        [$day . ' 00:00:00', $next . ' 00:00:00', $cursor, $state['last_id']]
      );
      if ($rows === []) {
        break;
      }
      $counted += $this->store($rows, $day);
      $processed += count($rows);
      $cursor = (int) $rows[count($rows) - 1]['_ID'];
      if (count($rows) < $batch) {
        break;
      }
    }
    return [$deleted, $processed, $counted];
  }

  /**
   * Retención opcional de visitas crudas (Configuración › Analítica web, "Conservar visitas crudas N días"; 0 = nunca).
   * Solo borra vistas ya resumidas (_ID <= marca de agua) y anteriores al día de corte, en lotes, y deja el rastro en
   * scm_tracker_state. Solo desde la línea de comandos: dashboard-marketing pierde esas filas en su propia analítica.
   * @return array{enabled:bool,days:int,cutoff:string,deleted:int,first:string,last:string}
   */
  public function purge(int $batch = self::DEFAULT_BATCH): array
  {
    if (PHP_SAPI !== 'cli') {
      throw new \LogicException('La purga de visitas crudas solo se ejecuta desde la línea de comandos.');
    }
    return $this->locked(function () use ($batch): array {
      $days = TrackerAnalyticsConfig::read($this->settings)['raw_retention_days'];
      if ($days <= 0) {
        return ['enabled' => false, 'days' => 0, 'cutoff' => '', 'deleted' => 0, 'first' => '', 'last' => ''];
      }
      $cutoff = (new \DateTimeImmutable(($this->today)()))->modify('-' . $days . ' days')->format('Y-m-d');
      $state = self::state($this->db);
      $raw = TrackerSchema::raw($this->db);
      $batch = max(1, min(5000, $batch));
      $cursor = 0;
      $deleted = 0;
      $first = '';
      $last = '';
      while (true) {
        $rows = $this->db->getResults(
          "SELECT `_ID`, `fecha_visita` FROM `{$raw}` WHERE `_ID` > ? AND `_ID` <= ? AND `fecha_visita` >= '2000-01-01' AND `fecha_visita` < ?
              AND (`event_type` = 'view' OR `event_type` = '' OR `event_type` IS NULL) ORDER BY `_ID` ASC LIMIT " . $batch,
          [$cursor, $state['last_id'], $cutoff . ' 00:00:00']
        );
        if ($rows === []) {
          break;
        }
        $ids = array_map(static fn(array $row): int => (int) $row['_ID'], $rows);
        foreach ($rows as $row) {
          $fecha = (string) $row['fecha_visita'];
          $first = $first === '' || $fecha < $first ? $fecha : $first;
          $last = $fecha > $last ? $fecha : $last;
        }
        $deleted += $this->db->pdo()->exec("DELETE FROM `{$raw}` WHERE `_ID` IN (" . implode(',', $ids) . ')') ?: 0;
        $cursor = max($ids);
      }
      if ($deleted > 0) {
        $this->transaction(function () use ($cutoff, $deleted, $first, $last, $days): void {
          $state = self::state($this->db);
          $this->writeState([
            'purged_before' => max($state['purged_before'], $cutoff),
            'last_purge' => ['at' => date('Y-m-d H:i:s', ($this->now)()), 'days' => $days, 'cutoff' => $cutoff, 'deleted' => $deleted, 'first' => $first, 'last' => $last],
          ]);
        });
      }
      return ['enabled' => true, 'days' => $days, 'cutoff' => $cutoff, 'deleted' => $deleted, 'first' => $first, 'last' => $last];
    });
  }

  /**
   * Las filas del lote hasta la primera visita demasiado reciente (sin incluirla).
   * @param list<array<string,mixed>> $rows
   * @return list<array<string,mixed>>
   */
  private function settled(array $rows): array
  {
    foreach ($rows as $index => $row) {
      if ($this->isRecent((string) ($row['fecha_visita'] ?? ''))) {
        return array_slice($rows, 0, $index);
      }
    }
    return $rows;
  }

  /**
   * ¿La visita llegó hace menos de SETTLE_SECONDS? Una fecha inválida o de más de un día en el futuro (un dato raro) no
   * frena el rollup: se procesa como cualquier otra.
   */
  private function isRecent(string $fecha): bool
  {
    if (TrackerAggregator::day($fecha) === null) {
      return false;
    }
    $fecha = str_replace('T', ' ', substr($fecha, 0, 19));
    $now = ($this->now)();
    return $fecha >= date('Y-m-d H:i:s', $now - self::SETTLE_SECONDS) && $fecha <= date('Y-m-d H:i:s', $now + 86400);
  }

  /**
   * Actualiza columnas de la fila de estado (dentro de la transacción del llamador).
   * @param array<string,mixed> $fields
   */
  private function writeState(array $fields): void
  {
    $sets = [];
    $args = [];
    foreach ($fields as $column => $value) {
      $sets[] = '`' . $column . '` = ?';
      $args[] = is_array($value) ? ($value === [] ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE)) : $value;
    }
    $this->db->pdo()->prepare('UPDATE `' . TrackerSchema::state($this->db) . '` SET ' . implode(', ', $sets) . ' WHERE `id` = 1')->execute($args);
  }

  /**
   * Suma un lote y lo escribe con upsert. Devuelve cuántas filas contaron.
   * @param list<array<string,mixed>> $rows
   */
  private function store(array $rows, ?string $minDay): int
  {
    $config = TrackerAnalyticsConfig::read($this->settings);
    $aggregator = new TrackerAggregator($this->hasher, new InternalIpMatcher($config['internal_ips']), $config['own_domains']);
    foreach ($rows as $row) {
      $aggregator->add($row, $minDay);
    }
    $this->upsert(TrackerSchema::daily($this->db), ['fecha', 'camp_slug', 'utm_source', 'utm_medium', 'registered', 'bot', 'internal'], $aggregator->dailyRows());
    $this->upsert(TrackerSchema::top($this->db), ['dim', 'fecha', 'camp_slug', 'utm_source', 'utm_medium', 'value_hash', 'bot', 'internal'], $aggregator->topRows(), ['value', 'detail']);
    $this->upsert(TrackerSchema::visitors($this->db), ['fecha', 'visitor_hash', 'camp_slug', 'utm_source', 'utm_medium', 'bot', 'internal'], $aggregator->visitorRows());
    return $aggregator->counted();
  }

  /**
   * INSERT que suma `hits` si la fila ya existe (MariaDB: ON DUPLICATE KEY UPDATE; SQLite: ON CONFLICT).
   * @param list<string> $keys
   * @param list<array<string,int|string>> $rows
   * @param list<string> $latest Columnas que toman el último valor no vacío (una referencia le gana a una URL).
   */
  private function upsert(string $table, array $keys, array $rows, array $latest = []): void
  {
    if ($rows === []) {
      return;
    }
    $columns = array_merge($keys, $latest, ['hits']);
    $mysql = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
      $updates = ['`hits` = `hits` + VALUES(`hits`)'];
      foreach ($latest as $column) {
        $updates[] = "`{$column}` = IF(VALUES(`{$column}`) <> '' AND (`{$column}` = '' OR VALUES(`{$column}`) NOT LIKE 'http%'), VALUES(`{$column}`), `{$column}`)";
      }
      $suffix = ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    } else {
      $updates = ['`hits` = `hits` + excluded.`hits`'];
      foreach ($latest as $column) {
        $updates[] = "`{$column}` = CASE WHEN excluded.`{$column}` <> '' AND (`{$column}` = '' OR excluded.`{$column}` NOT LIKE 'http%') THEN excluded.`{$column}` ELSE `{$column}` END";
      }
      $suffix = ' ON CONFLICT (' . implode(', ', array_map(static fn(string $c): string => "`{$c}`", $keys)) . ') DO UPDATE SET ' . implode(', ', $updates);
    }
    $rowSql = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
      $args = [];
      foreach ($chunk as $row) {
        foreach ($columns as $column) {
          $args[] = $row[$column] ?? '';
        }
      }
      $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns)) . ') VALUES '
        . implode(', ', array_fill(0, count($chunk), $rowSql)) . $suffix;
      $this->db->pdo()->prepare($sql)->execute($args);
    }
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
    } catch (\Throwable $exception) {
      if ($owns) {
        try {
          $pdo->rollBack();
        } catch (\PDOException) {
          // La transacción ya había terminado (falló el commit).
        }
      }
      throw $exception;
    }
  }
}
