<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\ColombiaBusinessCalendar;

/**
 * Tareas postergadas cuya fecha para retomar llegó: vuelven a Nuevo y su responsable recibe un correo
 * (CommercialTicketsRepository::resumeDuePostponed). Solo en días hábiles de Colombia (lunes a sábado sin festivos) desde
 * las 7:00 a. m. hora Colombia: ese día retoma las de hoy y las que cayeron en un domingo o festivo anterior. Lo corre el
 * cron diario (bin/resume-postponed-tasks.php) y, de respaldo, el panel al abrirse (como mucho cada 10 minutos).
 */
final class CommercialPostponedResumer
{
  public const HOUR = 7;
  private const THROTTLE = 600;

  private Database $db;
  private Settings $settings;
  private ?CommercialCallsNotifier $notifier;

  public function __construct(Database $db, Settings $settings, ?CommercialCallsNotifier $notifier = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->notifier = $notifier;
  }

  /** Toca retomar: día hábil en Colombia y ya son las 7:00 a. m. allá. */
  public static function isTime(int $now): bool
  {
    $local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE));
    return ColombiaBusinessCalendar::isBusinessDay($local) && (int) $local->format('G') >= self::HOUR;
  }

  /** @return int Tareas reactivadas (0 si no es día hábil o aún no son las 7:00 a. m. en Colombia). */
  public function run(?int $now = null): int
  {
    $now ??= time();
    if (!self::isTime($now)) {
      return 0;
    }
    $zone = new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE);
    $tomorrow = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0)->modify('+1 day')->getTimestamp();
    $resumed = (new CommercialTicketsRepository($this->db))->resumeDuePostponed($tomorrow, $now);
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
    if (!self::isTime(time())) {
      return;
    }
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
