<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Lógica de `php bin/tracker-rollup.php` (separada para probarla sin la base de producción).
 *
 *   php bin/tracker-rollup.php                         Resume las visitas nuevas (hasta --max, 50000 por defecto). Para el cron.
 *   php bin/tracker-rollup.php --backfill              Resume todo lo pendiente, en lotes (primera carga).
 *   php bin/tracker-rollup.php --rebuild-from=AAAA-MM-DD   Recalcula esos días (p. ej. tras cambiar las IPs internas).
 *   php bin/tracker-rollup.php --purge                 Borra visitas crudas ya resumidas y más viejas que lo configurado.
 *   php bin/tracker-rollup.php --status                Solo muestra el estado.
 *   Opciones: --batch=N (filas por lote, 1000), --max=N.
 */
final class TrackerRollupCommand
{
  public const DEFAULT_MAX = 50000;

  private TrackerRollup $rollup;
  /** @var resource */
  private $out;
  private ?string $logFile;

  /** @param resource $out */
  public function __construct(TrackerRollup $rollup, $out, ?string $logFile = null)
  {
    $this->rollup = $rollup;
    $this->out = $out;
    $this->logFile = $logFile;
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
    if (isset($options['status'])) {
      $this->printStatus();
      return 0;
    }
    $batch = (int) ($options['batch'] ?? TrackerRollup::DEFAULT_BATCH);
    $max = isset($options['backfill']) ? null : (int) ($options['max'] ?? self::DEFAULT_MAX);
    try {
      $this->rollup->locked(function () use ($options, $batch, $max): void {
        $result = $this->rollup->run($max, $batch, function (int $processed, int $lastId): void {
          if ($processed % 20000 < TrackerRollup::DEFAULT_BATCH) {
            $this->say("  ... {$processed} filas leídas (último _ID {$lastId})");
          }
        });
        $this->say("Visitas nuevas: {$result['processed']} filas leídas, {$result['counted']} vistas sumadas en {$result['batches']} lotes. Último _ID: {$result['last_id']}.");
        if (isset($options['rebuild-from'])) {
          $rebuild = $this->rollup->rebuildFrom((string) $options['rebuild-from'], $batch);
          if ($rebuild['from'] !== $rebuild['requested']) {
            $this->say("Las visitas crudas empiezan el {$rebuild['from']}: se recalcula desde ese día (los anteriores se conservan).");
          }
          $this->say("Recalculado desde {$rebuild['from']}: {$rebuild['deleted']} filas de resumen reemplazadas, {$rebuild['processed']} filas crudas leídas, {$rebuild['counted']} vistas sumadas.");
        }
        if (isset($options['purge'])) {
          $purge = $this->rollup->purge($batch);
          if (!$purge['enabled']) {
            $this->say('Purga desactivada: "Conservar visitas crudas" está en 0 días (nunca borrar).');
          } else {
            $message = $purge['deleted'] > 0
              ? "Purga: {$purge['deleted']} visitas crudas borradas (del {$purge['first']} al {$purge['last']}), anteriores al {$purge['cutoff']} y ya resumidas. Conservar {$purge['days']} días."
              : "Purga: no hay visitas crudas resumidas anteriores al {$purge['cutoff']}.";
            $this->say($message);
            $this->log($message);
          }
        }
      });
    } catch (TrackerRollupBusy $busy) {
      $this->say($busy->getMessage() . ' Se omite esta corrida.');
      return 0;
    } catch (\InvalidArgumentException $exception) {
      $this->say('ERROR: ' . $exception->getMessage());
      return 2;
    }
    $this->printStatus();
    return 0;
  }

  /** @param list<string> $argv @return array<string,string|true> */
  public static function parse(array $argv): array
  {
    $options = [];
    foreach ($argv as $arg) {
      if (preg_match('/^--(backfill|purge|status|help)$/', $arg, $match) === 1) {
        $options[$match[1]] = true;
        continue;
      }
      if (preg_match('/^--(rebuild-from|batch|max)=(.+)$/', $arg, $match) === 1) {
        $options[$match[1]] = $match[2];
        continue;
      }
      throw new \InvalidArgumentException('Opción no reconocida: ' . $arg);
    }
    if (isset($options['rebuild-from']) && TrackerAggregator::day($options['rebuild-from'] . ' 00:00') === null) {
      throw new \InvalidArgumentException('--rebuild-from necesita una fecha AAAA-MM-DD.');
    }
    foreach (['batch', 'max'] as $number) {
      if (isset($options[$number]) && (!ctype_digit((string) $options[$number]) || (int) $options[$number] < 1)) {
        throw new \InvalidArgumentException("--{$number} necesita un número mayor que cero.");
      }
    }
    return $options;
  }

  public static function usage(): string
  {
    return "Uso: php bin/tracker-rollup.php [--backfill] [--rebuild-from=AAAA-MM-DD] [--purge] [--status] [--batch=N] [--max=N]";
  }

  private function printStatus(): void
  {
    try {
      $status = $this->rollup->status();
    } catch (\Throwable $exception) {
      $this->say('No se pudo leer el estado: ' . $exception->getMessage());
      return;
    }
    $this->say('Estado: último _ID ' . $status['last_id'] . ($status['last_fecha'] !== '' ? " (visita del {$status['last_fecha']})" : '')
      . ', pendientes ' . $status['pending'] . ($status['updated_at'] !== '' ? ", actualizado {$status['updated_at']}" : '') . '.');
    if ($status['purged_before'] !== '') {
      $this->say("Visitas crudas purgadas antes del {$status['purged_before']}.");
    }
  }

  private function say(string $line): void
  {
    fwrite($this->out, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL);
  }

  private function log(string $line): void
  {
    if ($this->logFile === null) {
      return;
    }
    $dir = dirname($this->logFile);
    if (is_dir($dir) && is_writable($dir)) {
      @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
  }
}
