<?php

declare(strict_types=1);

namespace SCM\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ColombiaBusinessCalendar
{
  public const VERSION = 'co-national-mon-sat-v1';
  public const TIMEZONE = 'America/Bogota';

  /** @var array<int,array<string,bool>> */
  private static array $holidays = [];

  /** @var array<int,array<int,int>> */
  private static array $prefix = [];

  /** Fechas CCT sin zona horaria se interpretan en Bogota. */
  public static function parse($value): ?DateTimeImmutable
  {
    $zone = new DateTimeZone(self::TIMEZONE);
    if ($value instanceof DateTimeInterface) {
      return (new DateTimeImmutable('@' . $value->getTimestamp()))->setTimezone($zone);
    }

    if ($value === null || $value === false || $value === '') {
      return null;
    }

    $raw = trim((string) $value);
    try {
      if (preg_match('/^\d{1,13}$/D', $raw)) {
        $timestamp = (int) $raw;
        if ($timestamp >= 1000000000000) {
          $timestamp = intdiv($timestamp, 1000);
        }
        if ($timestamp <= 0) {
          return null;
        }
        $date = new DateTimeImmutable('@' . $timestamp);
      } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $raw)) {
          return null;
        }
        $date = new DateTimeImmutable($raw, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) {
          return null;
        }
      }

      $date = $date->setTimezone($zone);
      return (int) $date->format('Y') >= 1583 ? $date : null;
    } catch (\Throwable) {
      return null;
    }
  }

  public static function timestamp($value): ?int
  {
    $date = self::parse($value);
    return $date ? $date->getTimestamp() : null;
  }

  /** @return array<string,bool> */
  public static function holidays(int $year): array
  {
    if (isset(self::$holidays[$year])) {
      return self::$holidays[$year];
    }

    $zone = new DateTimeZone(self::TIMEZONE);
    $dates = [];
    foreach (['01-01', '05-01', '07-20', '08-07', '12-08', '12-25'] as $day) {
      $dates[sprintf('%04d-%s', $year, $day)] = true;
    }

    foreach (['01-06', '03-19', '06-29', '08-15', '10-12', '11-01', '11-11'] as $day) {
      $date = new DateTimeImmutable(sprintf('%04d-%s', $year, $day), $zone);
      $offset = (8 - (int) $date->format('N')) % 7;
      $dates[$date->modify('+' . $offset . ' days')->format('Y-m-d')] = true;
    }

    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = ($h + $l - 7 * $m + 114) % 31 + 1;
    $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $zone);
    foreach ([-3, -2, 43, 64, 71] as $offset) {
      $dates[$easter->modify(sprintf('%+d days', $offset))->format('Y-m-d')] = true;
    }

    ksort($dates);
    self::$holidays[$year] = $dates;
    return $dates;
  }

  public static function isBusinessDay($value): bool
  {
    $date = self::parse($value);
    if (!$date || $date->format('N') === '7') {
      return false;
    }
    return !isset(self::holidays((int) $date->format('Y'))[$date->format('Y-m-d')]);
  }

  /** Días completos de 24 horas útiles: lunes a sábado, sin domingos ni festivos nacionales. */
  public static function elapsedDays($from, $to = null): int
  {
    $start = self::parse($from);
    $end = $to === null ? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)) : self::parse($to);
    if (!$start || !$end || $end <= $start) {
      return 0;
    }

    $startDay = $start->setTime(0, 0);
    $endDay = $end->setTime(0, 0);
    if ($startDay == $endDay) {
      return self::isBusinessDay($startDay) ? intdiv($end->getTimestamp() - $start->getTimestamp(), 86400) : 0;
    }

    $nextDay = $startDay->modify('+1 day');
    $seconds = self::isBusinessDay($startDay) ? $nextDay->getTimestamp() - $start->getTimestamp() : 0;
    if (self::isBusinessDay($endDay)) {
      $seconds += $end->getTimestamp() - $endDay->getTimestamp();
    }
    $seconds += self::countFullDates($nextDay, $endDay) * 86400;

    return intdiv($seconds, 86400);
  }

  private static function countFullDates(DateTimeImmutable $start, DateTimeImmutable $end): int
  {
    $count = 0;
    for ($year = (int) $start->format('Y'); $year <= (int) $end->format('Y'); $year++) {
      if (!isset(self::$prefix[$year])) {
        $day = new DateTimeImmutable($year . '-01-01', new DateTimeZone(self::TIMEZONE));
        $prefix = [0];
        while ((int) $day->format('Y') === $year) {
          $prefix[] = end($prefix) + (self::isBusinessDay($day) ? 1 : 0);
          $day = $day->modify('+1 day');
        }
        self::$prefix[$year] = $prefix;
      }

      $prefix = self::$prefix[$year];
      $first = $year === (int) $start->format('Y') ? (int) $start->format('z') : 0;
      $last = $year === (int) $end->format('Y') ? (int) $end->format('z') : count($prefix) - 1;
      $count += $prefix[$last] - $prefix[$first];
    }

    return $count;
  }
}
