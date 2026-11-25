<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Tareas postergadas cuya fecha para retomar ya llegó: vuelven a Nuevo y su responsable recibe un correo
 * (CommercialTicketsRepository::resumeDuePostponed). Lo corre el cron (bin/resume-postponed-tasks.php) y, de respaldo,
 * el panel al abrirse (como mucho cada 10 minutos), para que no se acumulen en Postergados aunque falte el cron.
 */
final class CommercialPostponedResumer
{
  private const THROTTLE = 600;

  private Database $db;
  private Settings $settings;
  /** @var CommercialCallsNotifier|null */
  private ?CommercialCallsNotifier $notifier;

  public function __construct(Database $db, Settings $settings, ?CommercialCallsNotifier $notifier = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->notifier = $notifier;
  }

  /** @return int Tareas reactivadas. */
  public function run(?int $now = null): int
  {
    $resumed = (new CommercialTicketsRepository($this->db))->resumeDuePostponed($now);
    if ($resumed === []) {
      return 0;
    }
    $notifier = $this->notifier ?? new CommercialCallsNotifier($this->db, $this->settings);
    foreach ($resumed as $task) {
      try {
        $notifier->taskResumed((int) $task['_ID'], $task, (string) $task['scm_retomada']);
      } catch (\Throwable $exception) {
        error_log('[tareas:retomar:aviso] ' . $exception->getMessage());
      }
    }
    return count($resumed);
  }

  /** Desde el panel: como mucho una vez cada 10 minutos por servidor, y un fallo nunca impide abrirlo. */
  public function runThrottled(): void
  {
    $stamp = sys_get_temp_dir() . '/scm-commercial-resume-postponed.stamp';
    $last = is_file($stamp) ? (int) @filemtime($stamp) : 0;
    if ($last > time() - self::THROTTLE) {
      return;
    }
    @touch($stamp);
    try {
      $this->run();
    } catch (\Throwable $exception) {
      error_log('[tareas:retomar] ' . $exception->getMessage());
    }
  }
}
