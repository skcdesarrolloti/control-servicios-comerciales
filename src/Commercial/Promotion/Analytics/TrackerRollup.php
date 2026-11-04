<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Mantiene los resúmenes diarios a partir de `jet_cct_tracker_logs`.
 *
 * Es incremental por _ID: el último _ID resumido (marca de agua) se guarda en Settings junto con las sumas del mismo
 * lote, en una sola transacción, así que repetir una corrida nunca cuenta dos veces. Todo lo que escribe va bajo
 * TrackerLock (sin espera): el cron (bin/tracker-rollup.php) y la puesta al día de la página no se pisan.
 *
 * La tabla cruda es de dashboard-marketing, que la sigue escribiendo y leyendo: aquí solo se lee, salvo la purga opcional
 * de purge(), que solo corre desde la línea de comandos.
 */
final class TrackerRollup
{
  public const STATE_SETTING = 'promotion_tracker_rollup';
  public const DEFAULT_BATCH = 1000;
  /** Máximo de filas que procesa la página al abrirse; el resto lo hace el cron. */
  public const CATCH_UP_ROWS = 5000;
  private const UPSERT_CHUNK = 200;
  private const RAW_COLUMNS = '`_ID`, `camp_slug`, `user_id`, `ip_address`, `user_agent`, `page_url`, `referrer`, `fecha_visita`, `country`, `city`, `event_type`, `object_id`, `object_ref`, `utm_source`, `utm_medium`';

  private Database $db;
  private Settings $settings;
  private VisitorHasher $hasher;
  private TrackerLock $lock;
  private int $lockDepth = 0;
  /** @var \Closure():string */
  private \Closure $today;

  public function __construct(Database $db, Settings $settings, VisitorHasher $hasher, ?TrackerLock $lock = null, ?\Closure $today = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->hasher = $hasher;
    $this->lock = $lock ?? new TrackerLock($db);
    $this->today = $today ?? static fn(): string => date('Y-m-d');
  }

  /**
   * Estado guardado del rollup.
   * @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>}
   */
  public static function state(Settings $settings): array
  {
    try {
      $raw = $settings->get(self::STATE_SETTING, null);
    } catch (\Throwable) {
      $raw = null;
    }
    $raw = is_array($raw) ? $raw : [];
    return [
      'last_id' => max(0, (int) ($raw['last_id'] ?? 0)),
      'last_fecha' => (string) ($raw['last_fecha'] ?? ''),
      'updated_at' => (string) ($raw['updated_at'] ?? ''),
      'purged_before' => (string) ($raw['purged_before'] ?? ''),
      'last_purge' => is_array($raw['last_purge'] ?? null) ? $raw['last_purge'] : [],
    ];
  }

  /**
   * Estado para Configuración y para la página: último _ID y fecha procesados y filas pendientes. Solo lee.
   * @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>,pending:int,schema_ready:bool}
   */
  public function status(): array
  {
    return self::readStatus($this->db, $this->settings);
  }

  /** @return array{last_id:int,last_fecha:string,updated_at:string,purged_before:string,last_purge:array<string,mixed>,pending:int,schema_ready:bool} */
  public static function readStatus(Database $db, Settings $settings): array
  {
    $state = self::state($settings);
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
      // Con el candado tomado nadie más mueve la marca de agua: se relee para no partir de un valor viejo.
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
   * @return array{ran:bool,busy:bool,processed:int,behind:bool,last_id:int,last_fecha:string,updated_at:string}
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
    $state = self::state($this->settings);
    $behind = $this->db->getVar('SELECT `_ID` FROM `' . TrackerSchema::raw($this->db) . '` WHERE `_ID` > ? ORDER BY `_ID` LIMIT 1', [$state['last_id']]) !== null;
    return [
      'ran' => $ran,
      'busy' => $busy,
      'processed' => (int) $result['processed'],
      'behind' => $behind,
      'last_id' => $state['last_id'],
      'last_fecha' => $state['last_fecha'],
      'updated_at' => $state['updated_at'],
    ];
  }

  /**
   * Procesa las filas nuevas (por encima de la marca de agua) en lotes.
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
        $state = self::state($this->settings);
        $rows = $this->db->getResults(
          'SELECT ' . self::RAW_COLUMNS . ' FROM `' . TrackerSchema::raw($this->db) . '` WHERE `_ID` > ? ORDER BY `_ID` ASC LIMIT ' . (int) $limit,
          [$state['last_id']]
        );
        if ($rows === []) {
          break;
        }
        $last = $rows[count($rows) - 1];
        $counted += $this->transaction(function () use ($rows, $state, $last): int {
          $counted = $this->store($rows, null);
          $this->settings->set(self::STATE_SETTING, [
            'last_id' => (int) $last['_ID'],
            'last_fecha' => (string) ($last['fecha_visita'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
          ] + array_intersect_key($state, ['purged_before' => true, 'last_purge' => true]));
          return $counted;
        });
        $processed += count($rows);
        $batches++;
        if ($progress !== null) {
          $progress($processed, (int) $last['_ID']);
        }
        if (count($rows) < $limit) {
          break;
        }
      }
      return ['processed' => $processed, 'counted' => $counted, 'batches' => $batches, 'last_id' => self::state($this->settings)['last_id']];
    });
  }

  /**
   * Recalcula los días desde $from (p. ej. después de cambiar las IPs internas): borra sus resúmenes y vuelve a sumar
   * las filas crudas ya procesadas de esos días. Si las crudas de un día ya se purgaron, empieza en el primer día completo.
   * @param (callable(int,int):void)|null $progress
   * @return array{from:string,requested:string,deleted:int,processed:int,counted:int}
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
      $state = self::state($this->settings);
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
        return ['from' => $start, 'requested' => $from, 'deleted' => 0, 'processed' => 0, 'counted' => 0];
      }
      $deleted = $this->transaction(function () use ($start): int {
        $count = 0;
        foreach ([TrackerSchema::daily($this->db), TrackerSchema::top($this->db), TrackerSchema::visitors($this->db)] as $table) {
          $count += $this->db->pdo()->exec('DELETE FROM `' . $table . '` WHERE `fecha` >= ' . $this->db->pdo()->quote($start)) ?: 0;
        }
        return $count;
      });
      $firstId = $this->db->getVar("SELECT MIN(`_ID`) FROM `{$raw}` WHERE `fecha_visita` >= ?", [$start . ' 00:00:00']);
      $cursor = $firstId === null ? $state['last_id'] : (int) $firstId - 1;
      $batch = max(1, min(10000, $batch));
      $processed = 0;
      $counted = 0;
      while ($cursor < $state['last_id']) {
        $rows = $this->db->getResults(
          'SELECT ' . self::RAW_COLUMNS . " FROM `{$raw}` WHERE `_ID` > ? AND `_ID` <= ? ORDER BY `_ID` ASC LIMIT " . $batch,
          [$cursor, $state['last_id']]
        );
        if ($rows === []) {
          break;
        }
        $counted += $this->transaction(fn(): int => $this->store($rows, $start));
        $cursor = (int) $rows[count($rows) - 1]['_ID'];
        $processed += count($rows);
        if ($progress !== null) {
          $progress($processed, $cursor);
        }
      }
      return ['from' => $start, 'requested' => $from, 'deleted' => $deleted, 'processed' => $processed, 'counted' => $counted];
    });
  }

  /**
   * Retención opcional de visitas crudas (Configuración › Analítica web, "Conservar visitas crudas N días"; 0 = nunca).
   * Solo borra vistas ya resumidas (_ID <= marca de agua) y anteriores al día de corte, en lotes, y deja el rastro en
   * Settings. Solo desde la línea de comandos: dashboard-marketing pierde esas filas en su propia analítica.
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
      $state = self::state($this->settings);
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
          $state = self::state($this->settings);
          $state['purged_before'] = max($state['purged_before'], $cutoff);
          $state['last_purge'] = ['at' => date('Y-m-d H:i:s'), 'days' => $days, 'cutoff' => $cutoff, 'deleted' => $deleted, 'first' => $first, 'last' => $last];
          $this->settings->set(self::STATE_SETTING, $state);
        });
      }
      return ['enabled' => true, 'days' => $days, 'cutoff' => $cutoff, 'deleted' => $deleted, 'first' => $first, 'last' => $last];
    });
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
    $this->upsert(TrackerSchema::top($this->db), ['fecha', 'dim', 'camp_slug', 'utm_source', 'utm_medium', 'value_hash', 'bot', 'internal'], $aggregator->topRows(), ['value', 'detail']);
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
      // Settings guardó en memoria una marca de agua que no llegó a la base.
      $this->settings->refresh();
      throw $exception;
    }
  }
}
