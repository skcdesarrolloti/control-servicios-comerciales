<?php

declare(strict_types=1);

namespace SCM\Commercial\Medios;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Lógica de `php bin/medios-normalize.php` (separada para probarla sin la base de producción).
 *
 *   php bin/medios-normalize.php                 (= --dry-run) Solo lee: muestra la lista, el glosario y, por tabla y columna,
 *                                                cada valor que se cambiaría por el nombre de su medio, con las filas.
 *   php bin/medios-normalize.php --apply         Crea las tablas de Medios (si faltan) con la lista inicial, sincroniza el
 *                                                glosario 195 y reescribe esos valores en lotes, con respaldo de cada fila.
 *   php bin/medios-normalize.php --revert=ID     Devuelve a su valor anterior las filas que cambió el paso ID del historial
 *                                                (solo las que siguen con el valor que les puso).
 *   Opciones: --batch=N (filas por transacción, 500).
 *
 * Qué se reescribe: en las columnas `medio` de los registros y en utm_source / utm_medium de las visitas
 * (jet_cct_tracker_logs), los valores que la lista reconoce pero no están escritos con el nombre exacto del medio
 * ("Retencion de contrato" -> "Retencion", "Ruta" -> "Por ruta", "Portal Propietario" -> "Portal propietario",
 * "ig" -> "Instagram", "Correo Electrónico" -> "Correo"...). Los vacíos, los valores sin reconocer y los que pone el
 * tracker en las visitas sin UTM (Directo, Inmuebles, Navegador, Pagina Web) no se tocan.
 *
 * --dry-run no escribe nada: en MariaDB la sesión queda en solo lectura (SET SESSION TRANSACTION READ ONLY, que también
 * impide el DDL), la configuración se abre en modo solo lectura y no se ejecuta ningún INSERT, UPDATE ni CREATE.
 */
final class MediosNormalizeCommand
{
  public const DEFAULT_BATCH = 500;
  /** Columnas de las visitas que se normalizan (la tabla es de dashboard-marketing). */
  public const TRACKER_TABLE = 'jet_cct_tracker_logs';
  public const TRACKER_COLUMNS = ['utm_source', 'utm_medium'];

  private Database $db;
  private Settings $settings;
  /** @var resource */
  private $out;
  private string $employee;

  /** @param resource $out */
  public function __construct(Database $db, Settings $settings, $out, string $employee = 'cli')
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->out = $out;
    $this->employee = $employee;
  }

  /** ¿Estos argumentos escriben en la base? (el script abre la configuración en solo lectura si no). */
  public static function writes(array $argv): bool
  {
    foreach ($argv as $arg) {
      if ($arg === '--apply' || str_starts_with((string) $arg, '--revert=')) {
        return true;
      }
    }
    return false;
  }

  /** @param list<string> $argv Argumentos sin el nombre del script. */
  public function run(array $argv): int
  {
    try {
      $options = self::parse($argv);
    } catch (\InvalidArgumentException $exception) {
      $this->say('ERROR: ' . $exception->getMessage());
      $this->say(self::usage());
      return 2;
    }
    if (isset($options['help'])) {
      $this->say(self::usage());
      return 0;
    }
    $batch = (int) ($options['batch'] ?? self::DEFAULT_BATCH);
    try {
      if (isset($options['revert'])) {
        return $this->revert((int) $options['revert'], $batch);
      }
      if (isset($options['apply'])) {
        return $this->apply($batch);
      }
      return $this->dryRun();
    } catch (\Throwable $exception) {
      $this->say('ERROR: ' . $exception->getMessage());
      return 1;
    }
  }

  /** @param list<string> $argv @return array<string,string|true> */
  public static function parse(array $argv): array
  {
    $options = [];
    foreach ($argv as $arg) {
      if (preg_match('/^--(dry-run|apply|help)$/', $arg, $match) === 1) {
        $options[$match[1]] = true;
        continue;
      }
      if (preg_match('/^--(revert|batch)=(\d+)$/', $arg, $match) === 1 && (int) $match[2] > 0) {
        $options[$match[1]] = $match[2];
        continue;
      }
      throw new \InvalidArgumentException('Opción no reconocida: ' . $arg);
    }
    if (count(array_intersect_key($options, ['dry-run' => 1, 'apply' => 1, 'revert' => 1])) > 1) {
      throw new \InvalidArgumentException('Usa solo una de --dry-run, --apply o --revert.');
    }
    return $options;
  }

  public static function usage(): string
  {
    return 'Uso: php bin/medios-normalize.php [--dry-run | --apply | --revert=ID] [--batch=N]';
  }

  /**
   * Cambios que haría la normalización con la lista vigente (o la inicial si la tabla aún no existe). Solo lee.
   * @return list<array{table:string,column:string,from:string,to:string,rows:int}>
   */
  public function plan(MedioCatalog $catalog): array
  {
    $resolver = $catalog->resolver();
    $plan = [];
    $targets = [];
    foreach ($catalog->recordTables() as $table => $column) {
      $targets[] = [$table, $column, false];
    }
    if ($catalog->tableExists($this->db->table(self::TRACKER_TABLE))) {
      foreach (self::TRACKER_COLUMNS as $column) {
        $targets[] = [self::TRACKER_TABLE, $column, true];
      }
    }
    foreach ($targets as [$table, $column, $tracker]) {
      $values = $catalog->columnValues($table, $column);
      ksort($values, SORT_STRING);
      foreach ($values as $value => $rows) {
        $value = (string) $value;
        if ($tracker && self::trackerDefault($column, $value)) {
          continue;
        }
        $target = $resolver->resolve($value);
        if ($target !== null && $target !== $value) {
          $plan[] = ['table' => $table, 'column' => $column, 'from' => $value, 'to' => $target, 'rows' => $rows];
        }
      }
    }
    return $plan;
  }

  private static function trackerDefault(string $column, string $value): bool
  {
    $defaults = $column === 'utm_source' ? MedioResolver::TRACKER_DEFAULT_SOURCES : MedioResolver::TRACKER_DEFAULT_MEDIUMS;
    return in_array(MedioResolver::key($value), $defaults, true);
  }

  private function dryRun(): int
  {
    if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
      // Garantía adicional: con la sesión en solo lectura, MariaDB rechaza cualquier INSERT, UPDATE, DELETE o DDL.
      $this->db->pdo()->exec('SET SESSION TRANSACTION READ ONLY');
    }
    $this->say('Medios: vista previa (--dry-run). No se escribe nada en la base.');
    $catalog = new MedioCatalog($this->db, $this->settings, $this->employee);
    $this->describeCatalog($catalog);
    $plan = $this->plan($catalog);
    $this->printPlan($plan);
    $this->printUnresolved($catalog);
    $this->say('Para aplicarlo: php bin/medios-normalize.php --apply   (después: php bin/tracker-rollup.php, que pasa los resúmenes de la analítica a la versión con medio).');
    return 0;
  }

  private function apply(int $batch): int
  {
    $this->say('Medios: aplicar (--apply).');
    if (!MedioSchema::isReady($this->settings)) {
      MedioSchema::install($this->db, $this->settings);
      $this->say('Tablas creadas: ' . MedioSchema::medios($this->db) . ', ' . MedioSchema::log($this->db) . ', ' . MedioSchema::backup($this->db) . '.');
    }
    $catalog = new MedioCatalog($this->db, $this->settings, $this->employee);
    $seeded = $catalog->seed();
    if ($seeded > 0) {
      $this->say("Lista creada con {$seeded} medios; glosario " . MedioCatalog::GLOSSARY_ID . ' sincronizado.');
    } else {
      $changed = $catalog->transaction(function () use ($catalog): bool {
        $changed = $catalog->syncGlossary();
        if ($changed) {
          $catalog->log('sync', '', '', ['glossary' => true]);
        }
        return $changed;
      });
      $this->say($changed ? 'Glosario ' . MedioCatalog::GLOSSARY_ID . ' sincronizado con los medios activos.' : 'El glosario ' . MedioCatalog::GLOSSARY_ID . ' ya lista los medios activos.');
    }
    $catalog->forget();
    $this->describeCatalog($catalog);
    $plan = $this->plan($catalog);
    $this->printPlan($plan);
    if ($plan === []) {
      $this->say('No hay valores por normalizar.');
      return 0;
    }
    $logId = $catalog->transaction(fn(): int => $catalog->log('normalize', '', '', ['status' => 'en curso']));
    $counts = [];
    foreach ($plan as $step) {
      $ids = $catalog->exactIds($step['table'], $step['column'], $step['from']);
      foreach (array_chunk($ids, max(1, min(5000, $batch))) as $chunk) {
        $catalog->transaction(fn() => $catalog->rewriteIds($step['table'], $step['column'], $chunk, $step['from'], $step['to'], $logId));
      }
      $key = $step['table'] . '.' . $step['column'];
      $counts[$key] = ($counts[$key] ?? 0) + count($ids);
      $this->say('  ' . $key . ': "' . $step['from'] . '" -> "' . $step['to'] . '": ' . count($ids) . ' filas.');
    }
    $catalog->transaction(fn() => $catalog->updateLog($logId, ['records' => $counts, 'steps' => count($plan)]));
    $this->say('Listo: ' . array_sum($counts) . " filas reescritas. Historial #{$logId}; para revertir: php bin/medios-normalize.php --revert={$logId}");
    $this->say('Siguiente paso: php bin/tracker-rollup.php (pasa los resúmenes de la analítica a la versión con medio y los clasifica).');
    return 0;
  }

  private function revert(int $logId, int $batch): int
  {
    if (!MedioSchema::isReady($this->settings)) {
      throw new \RuntimeException('La lista de medios no está instalada: no hay nada que revertir.');
    }
    $catalog = new MedioCatalog($this->db, $this->settings, $this->employee);
    $entry = $this->db->getRow('SELECT * FROM `' . MedioSchema::log($this->db) . '` WHERE `id` = ?', [$logId]);
    if ($entry === null) {
      throw new \RuntimeException("No existe el paso #{$logId} en el historial de medios.");
    }
    $rows = $this->db->getResults('SELECT * FROM `' . MedioSchema::backup($this->db) . '` WHERE `log_id` = ? ORDER BY `id`', [$logId]);
    $this->say("Revertir #{$logId} ({$entry['action']} {$entry['from_value']} -> {$entry['to_value']}): " . count($rows) . ' filas respaldadas.');
    $groups = [];
    foreach ($rows as $row) {
      $groups[$row['table_name'] . "\x1F" . $row['column_name'] . "\x1F" . $row['old_value'] . "\x1F" . $row['new_value']][] = (int) $row['row_id'];
    }
    $revertLog = $catalog->transaction(fn(): int => $catalog->log('revert', '#' . $logId, '', ['status' => 'en curso']));
    $counts = [];
    $skipped = 0;
    foreach ($groups as $key => $ids) {
      [$table, $column, $old, $new] = explode("\x1F", $key);
      if (!array_key_exists($table, MedioCatalog::RECORD_TABLES) && $table !== self::TRACKER_TABLE) {
        continue;
      }
      // Solo vuelven las filas que siguen con el valor que les puso ese paso.
      $current = array_flip($catalog->exactIds($table, $column, $new));
      $keep = array_values(array_filter($ids, static fn(int $id): bool => isset($current[$id])));
      $skipped += count($ids) - count($keep);
      foreach (array_chunk($keep, max(1, min(5000, $batch))) as $chunk) {
        $catalog->transaction(fn() => $catalog->rewriteIds($table, $column, $chunk, $new, $old, $revertLog));
      }
      $counts[$table . '.' . $column] = ($counts[$table . '.' . $column] ?? 0) + count($keep);
    }
    $catalog->transaction(fn() => $catalog->updateLog($revertLog, ['records' => $counts, 'skipped' => $skipped, 'reverted' => $logId]));
    $this->say('Listo: ' . array_sum($counts) . ' filas devueltas a su valor anterior' . ($skipped > 0 ? ", {$skipped} omitidas porque ya tenían otro valor" : '') . ". Historial #{$revertLog}.");
    $this->say('La lista de medios (nombres y alias) no se revierte: se ajusta en Configuración › Medios.');
    return 0;
  }

  private function describeCatalog(MedioCatalog $catalog): void
  {
    $all = $catalog->all();
    $active = $catalog->active();
    if ($catalog->ready()) {
      $this->say('Lista: tabla ' . MedioSchema::medios($this->db) . ' instalada, ' . count($all) . ' medios (' . count($active) . ' activos), revisión ' . $catalog->revision() . '.');
    } else {
      $this->say('Lista: la tabla ' . MedioSchema::medios($this->db) . ' no existe todavía. --apply la crearía con la lista inicial de ' . count($all) . ' medios:');
      foreach ($all as $row) {
        $this->say('  - ' . $row['nombre'] . ' [' . $row['slug'] . ']' . ($row['aliases'] !== [] ? ' alias: ' . implode(', ', $row['aliases']) : ''));
      }
    }
    $table = $this->db->table('jet_post_types');
    if (!$catalog->tableExists($table)) {
      $this->say('Glosario: no existe ' . $table . '.');
      return;
    }
    $row = $this->db->getRow('SELECT `meta_fields` FROM `' . $table . "` WHERE `id` = ? AND `status` = 'glossary'", [MedioCatalog::GLOSSARY_ID]);
    if ($row === null) {
      $this->say('Glosario ' . MedioCatalog::GLOSSARY_ID . ': no existe.');
      return;
    }
    $items = @unserialize((string) $row['meta_fields'], ['allowed_classes' => false]);
    if (!is_array($items)) {
      $this->say('Glosario ' . MedioCatalog::GLOSSARY_ID . ': no se pudo leer (no se modificaría).');
      return;
    }
    $current = array_values(array_filter(array_map(static fn($item): string => is_array($item) && is_scalar($item['value'] ?? null) ? (string) $item['value'] : '', $items), static fn(string $value): bool => $value !== ''));
    $next = array_column($active, 'nombre');
    $added = array_values(array_diff($next, $current));
    $removed = array_values(array_diff($current, $next));
    $this->say('Glosario ' . MedioCatalog::GLOSSARY_ID . ' "Medio de llamada": ' . count($current) . ' valores hoy; con la lista quedaría con ' . count($next) . '.'
      . ($added !== [] ? ' Se agregan: ' . implode(', ', $added) . '.' : '')
      . ($removed !== [] ? ' Se quitan: ' . implode(', ', $removed) . '.' : '')
      . ($added === [] && $removed === [] ? ($current === $next ? ' Sin cambios.' : ' Cambia solo el orden.') : ''));
  }

  /** @param list<array{table:string,column:string,from:string,to:string,rows:int}> $plan */
  private function printPlan(array $plan): void
  {
    $records = 0;
    $visits = 0;
    $last = '';
    $this->say('Valores que se reescriben (coincidencia exacta del valor de la izquierda):');
    foreach ($plan as $step) {
      $key = $this->db->table($step['table']) . '.' . $step['column'];
      if ($key !== $last) {
        $this->say('  ' . $key);
        $last = $key;
      }
      $this->say('    "' . $step['from'] . '" -> "' . $step['to'] . '": ' . $step['rows'] . ' filas');
      if ($step['table'] === self::TRACKER_TABLE) {
        $visits += $step['rows'];
      } else {
        $records += $step['rows'];
      }
    }
    if ($plan === []) {
      $this->say('  (ninguno)');
    }
    $this->say("Total: {$records} filas en registros y {$visits} en visitas web.");
  }

  private function printUnresolved(MedioCatalog $catalog): void
  {
    $unresolved = $catalog->unresolved();
    $this->say('Valores sin reconocer (no se tocan; se pueden mapear en Configuración › Medios):');
    $any = false;
    foreach ($unresolved['records'] as $item) {
      $any = true;
      $tables = [];
      foreach ($item['tables'] as $table => $count) {
        $tables[] = $this->db->table($table) . ' ' . $count;
      }
      $this->say('  registros "' . $item['value'] . '": ' . $item['total'] . ' (' . implode(', ', $tables) . ')');
    }
    foreach ($unresolved['visits'] as $item) {
      $any = true;
      $this->say('  visitas "' . $item['value'] . '": ' . $item['total'] . ' (' . implode(' | ', array_slice($item['pairs'], 0, 4)) . ')');
    }
    if (!$any) {
      $this->say('  (ninguno)');
    }
  }

  private function say(string $line): void
  {
    fwrite($this->out, $line . "\n");
  }
}
