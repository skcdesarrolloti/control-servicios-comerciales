<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Support\ColombiaBusinessCalendar;

/**
 * Reparte las tareas postergadas que no tienen fecha para retomar (las de antes de que Postergar la pidiera) en los días
 * hábiles entre dentro de un mes y dentro de 50 días (FROM_DAY a TO_DAY; no pasa de RESUME_FREE_DAYS para no gastar el cupo
 * de postergadas largas de nadie), por responsable y de la más antigua a la más
 * reciente, para que vuelvan a Nuevo de a pocas (CommercialPostponedResumer) y no todas el mismo día. También vuelve a
 * repartir las que el script ya había programado (desde el día siguiente) y siguen postergadas.
 * Uso de una sola vez: bin/schedule-postponed-tasks.php.
 */
final class CommercialPostponedScheduler
{
  /** Primer y último día (contados desde hoy) en que pueden volver: de dentro de un mes a dentro de 50 días. */
  public const FROM_DAY = 30;
  public const TO_DAY = 50;
  /** Texto de la nota del script: marca las tareas que programó él (y no un consultor/a). */
  public const NOTE_MARK = 'estaba postergada sin fecha';

  /**
   * Días hábiles (lunes a sábado sin festivos) entre FROM_DAY y TO_DAY, a las 8:00 a. m. de Colombia, como la fecha que
   * pide Postergar.
   * @return list<\DateTimeImmutable>
   */
  public static function days(int $now): array
  {
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE))->setTime(8, 0);
    $days = [];
    for ($i = self::FROM_DAY; $i <= self::TO_DAY; $i++) {
      $day = $today->modify('+' . $i . ' days');
      if (ColombiaBusinessCalendar::isBusinessDay($day)) {
        $days[] = $day;
      }
    }
    return $days;
  }

  /**
   * Cada responsable reparte sus tareas por igual entre los días: las más antiguas, primero.
   * @param list<array<string,mixed>> $rows Postergadas sin fecha, la más antigua primero.
   * @return array{assign:array<int,\DateTimeImmutable>,people:array<string,array{nombre:string,total:int,por_dia:int}>,days:int}
   */
  public static function plan(array $rows, int $now): array
  {
    $days = self::days($now);
    $groups = [];
    foreach ($rows as $row) {
      $groups[trim((string) ($row['id_empleado'] ?? ''))][] = $row;
    }
    $assign = [];
    $people = [];
    foreach ($groups as $employee => $tasks) {
      $total = count($tasks);
      $perDay = [];
      foreach ($tasks as $i => $task) {
        $index = intdiv($i * count($days), $total);
        $assign[(int) $task['_ID']] = $days[$index];
        $perDay[$index] = ($perDay[$index] ?? 0) + 1;
      }
      $people[(string) $employee] = ['nombre' => trim((string) ($tasks[0]['nombre_empleado'] ?? '')) ?: 'Sin responsable', 'total' => $total, 'por_dia' => max($perDay)];
    }
    uasort($people, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    return ['assign' => $assign, 'people' => $people, 'days' => count($days)];
  }
}
