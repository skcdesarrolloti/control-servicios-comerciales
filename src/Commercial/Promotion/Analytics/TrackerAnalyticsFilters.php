<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Filtros de Analítica web. A diferencia de dashboard-marketing siempre hay un rango: por defecto los últimos 30 días,
 * como máximo 12 meses sobre los resúmenes (y 90 días en el visor de registros crudos).
 */
final class TrackerAnalyticsFilters
{
  public const DEFAULT_DAYS = 30;
  public const MAX_DAYS = 366;
  public const LOG_MAX_DAYS = 90;

  public function __construct(
    public readonly string $from,
    public readonly string $to,
    public readonly string $campaign = '',
    public readonly string $source = '',
    public readonly string $medium = '',
    public readonly bool $excludeBots = true,
    public readonly bool $excludeInternal = true,
    public readonly bool $clamped = false,
  ) {
  }

  /**
   * @param array<string,mixed> $input from, to (AAAA-MM-DD), campaign, source, medium, exclude_bots, exclude_internal.
   * @param array{exclude_bots:bool,exclude_internal:bool} $config Valores por defecto de Configuración › Analítica web.
   */
  public static function fromInput(array $input, array $config, ?string $today = null, int $maxDays = self::MAX_DAYS): self
  {
    $today = $today ?? date('Y-m-d');
    $to = self::date($input['to'] ?? null) ?? $today;
    if ($to > $today) {
      $to = $today;
    }
    $from = self::date($input['from'] ?? null) ?? self::shift($to, -(self::DEFAULT_DAYS - 1));
    if ($from > $to) {
      [$from, $to] = [$to, $from];
    }
    $clamped = false;
    $earliest = self::shift($to, -(max(1, $maxDays) - 1));
    if ($from < $earliest) {
      $from = $earliest;
      $clamped = true;
    }
    return new self(
      $from,
      $to,
      self::text($input['campaign'] ?? ''),
      self::text($input['source'] ?? ''),
      self::text($input['medium'] ?? ''),
      array_key_exists('exclude_bots', $input) ? self::flag($input['exclude_bots']) : $config['exclude_bots'],
      array_key_exists('exclude_internal', $input) ? self::flag($input['exclude_internal']) : $config['exclude_internal'],
      $clamped,
    );
  }

  /** Los mismos filtros con el rango recortado a los últimos $maxDays días (visor de registros). */
  public function limitedTo(int $maxDays): self
  {
    $earliest = self::shift($this->to, -(max(1, $maxDays) - 1));
    if ($this->from >= $earliest) {
      return $this;
    }
    return new self($earliest, $this->to, $this->campaign, $this->source, $this->medium, $this->excludeBots, $this->excludeInternal, true);
  }

  public function days(): int
  {
    return (int) (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days + 1;
  }

  /** @return array<string,string|bool> */
  public function toArray(): array
  {
    return [
      'from' => $this->from, 'to' => $this->to, 'campaign' => $this->campaign, 'source' => $this->source, 'medium' => $this->medium,
      'exclude_bots' => $this->excludeBots, 'exclude_internal' => $this->excludeInternal, 'clamped' => $this->clamped,
    ];
  }

  public static function shift(string $day, int $days): string
  {
    return (new \DateTimeImmutable($day))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
  }

  private static function date(mixed $value): ?string
  {
    if (!is_string($value)) {
      return null;
    }
    $value = trim($value);
    return TrackerAggregator::day($value . ' 00:00') !== null && strlen($value) === 10 ? $value : null;
  }

  private static function text(mixed $value): string
  {
    return is_scalar($value) ? trim(mb_substr(trim((string) $value), 0, 120)) : '';
  }

  private static function flag(mixed $value): bool
  {
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'si', 'sí', 'yes'], true);
    }
    return (bool) $value;
  }
}
