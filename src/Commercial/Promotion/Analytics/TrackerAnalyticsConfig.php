<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Settings;

/**
 * Configuración › Analítica web. Es propia del panel: se guarda en Settings (control_servicios_comerciales_config).
 *
 * Las IPs internas y los dominios propios se aplican al resumir cada visita; si cambian, los días ya resumidos conservan
 * la clasificación anterior hasta correr `php bin/tracker-rollup.php --rebuild-from=AAAA-MM-DD`.
 */
final class TrackerAnalyticsConfig
{
  public const SETTING = 'promotion_analytics_config';
  public const MAX_RETENTION_DAYS = 3650;

  public const DEFAULTS = [
    'internal_ips' => [],
    'exclude_bots' => true,
    'exclude_internal' => true,
    'raw_retention_days' => 0,
    'own_domains' => [],
    'classification_changed_at' => '',
  ];

  /** @return array{internal_ips:list<string>,exclude_bots:bool,exclude_internal:bool,raw_retention_days:int,own_domains:list<string>,classification_changed_at:string} */
  public static function read(Settings $settings): array
  {
    try {
      $raw = $settings->get(self::SETTING, null);
    } catch (\Throwable) {
      $raw = null;
    }
    $raw = is_array($raw) ? $raw : [];
    try {
      $ips = InternalIpMatcher::normalizeList($raw['internal_ips'] ?? []);
    } catch (\InvalidArgumentException) {
      $ips = [];
    }
    try {
      $domains = UrlNormalizer::normalizeDomains($raw['own_domains'] ?? []);
    } catch (\InvalidArgumentException) {
      $domains = [];
    }
    $days = (int) ($raw['raw_retention_days'] ?? 0);
    $changed = (string) ($raw['classification_changed_at'] ?? '');
    return [
      'internal_ips' => $ips,
      'exclude_bots' => array_key_exists('exclude_bots', $raw) ? (bool) $raw['exclude_bots'] : true,
      'exclude_internal' => array_key_exists('exclude_internal', $raw) ? (bool) $raw['exclude_internal'] : true,
      'raw_retention_days' => max(0, min(self::MAX_RETENTION_DAYS, $days)),
      'own_domains' => $domains,
      'classification_changed_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $changed) === 1 ? $changed : '',
    ];
  }

  /**
   * Valida y guarda la sección.
   *
   * @param array<string,mixed> $data
   * @return array{internal_ips:list<string>,exclude_bots:bool,exclude_internal:bool,raw_retention_days:int,own_domains:list<string>,classification_changed_at:string}
   * @throws \InvalidArgumentException Con el mensaje para el administrador.
   */
  public static function save(Settings $settings, array $data, int $userId, ?string $today = null): array
  {
    $current = self::read($settings);
    $ips = InternalIpMatcher::normalizeList($data['internal_ips'] ?? []);
    $domains = UrlNormalizer::normalizeDomains($data['own_domains'] ?? []);
    $days = $data['raw_retention_days'] ?? 0;
    if (is_string($days)) {
      $days = trim($days) === '' ? '0' : trim($days);
    }
    if (!is_int($days) && !(is_string($days) && ctype_digit($days))) {
      throw new \InvalidArgumentException('Los días para conservar las visitas crudas deben ser un número entero entre 0 y ' . self::MAX_RETENTION_DAYS . '.');
    }
    $days = (int) $days;
    if ($days < 0 || $days > self::MAX_RETENTION_DAYS) {
      throw new \InvalidArgumentException('Los días para conservar las visitas crudas deben estar entre 0 (nunca borrar) y ' . self::MAX_RETENTION_DAYS . '.');
    }
    if ($days > 0 && $days < 30) {
      throw new \InvalidArgumentException('Conserva al menos 30 días de visitas crudas, o escribe 0 para no borrar nunca.');
    }
    $changed = $current['classification_changed_at'];
    if ($ips !== $current['internal_ips'] || $domains !== $current['own_domains']) {
      $changed = $today ?? date('Y-m-d');
    }
    $clean = [
      'internal_ips' => $ips,
      'exclude_bots' => self::flag($data['exclude_bots'] ?? false),
      'exclude_internal' => self::flag($data['exclude_internal'] ?? false),
      'raw_retention_days' => $days,
      'own_domains' => $domains,
      'classification_changed_at' => $changed,
    ];
    $settings->set(self::SETTING, $clean, $userId);
    $settings->refresh();
    return $clean;
  }

  /** @param mixed $value */
  private static function flag($value): bool
  {
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'si', 'sí', 'yes'], true);
    }
    return (bool) $value;
  }
}
