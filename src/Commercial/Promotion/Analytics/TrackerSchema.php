<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Tablas propias del panel con los resúmenes diarios de `jet_cct_tracker_logs` (que sigue siendo de dashboard-marketing y
 * aquí solo se lee). Todo valor vacío llega ya normalizado ('general', 'Directo', 'Sin medio'...), así que las lecturas
 * filtran y agrupan por columnas simples, sin COALESCE(NULLIF()).
 *
 * El DDL no corre en cada petición: ensure() lo ejecuta una vez y deja la versión en Settings (TrackerSchema::SETTING).
 * `php bin/tracker-rollup.php` también lo prepara.
 */
final class TrackerSchema
{
  public const VERSION = 1;
  public const SETTING = 'promotion_tracker_schema';

  /** Valores con que se guardan los vacíos. */
  public const EMPTY_CAMPAIGN = 'general';
  public const EMPTY_SOURCE = 'Directo';
  public const EMPTY_MEDIUM = 'Sin medio';

  /** Dimensiones de scm_tracker_daily_top. */
  public const TOP_DIMS = ['page', 'referrer', 'country', 'city', 'object'];

  /** @var \WeakMap<Database,bool>|null */
  private static ?\WeakMap $checked = null;

  public static function daily(Database $db): string
  {
    return $db->table('scm_tracker_daily');
  }

  public static function top(Database $db): string
  {
    return $db->table('scm_tracker_daily_top');
  }

  public static function visitors(Database $db): string
  {
    return $db->table('scm_tracker_daily_visitors');
  }

  public static function raw(Database $db): string
  {
    return $db->table('jet_cct_tracker_logs');
  }

  public static function isReady(Settings $settings): bool
  {
    try {
      return (int) $settings->get(self::SETTING, 0) >= self::VERSION;
    } catch (\Throwable) {
      return false;
    }
  }

  public static function ensure(Database $db, Settings $settings, bool $force = false): void
  {
    self::$checked ??= new \WeakMap();
    if (!$force && (isset(self::$checked[$db]) || self::isReady($settings))) {
      self::$checked[$db] = true;
      return;
    }
    $sqlite = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    foreach ($sqlite ? self::sqliteTables($db) : self::mysqlTables($db) as $query) {
      $db->pdo()->exec($query);
    }
    if (!self::isReady($settings)) {
      $settings->set(self::SETTING, self::VERSION);
    }
    self::$checked[$db] = true;
  }

  /** @return list<string> */
  private static function mysqlTables(Database $db): array
  {
    $dims = 'camp_slug VARCHAR(120) NOT NULL, utm_source VARCHAR(120) NOT NULL, utm_medium VARCHAR(120) NOT NULL';
    $flags = 'bot TINYINT UNSIGNED NOT NULL DEFAULT 0, internal TINYINT UNSIGNED NOT NULL DEFAULT 0';
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
      'CREATE TABLE IF NOT EXISTS `' . self::daily($db) . "` (
        fecha DATE NOT NULL, {$dims}, registered TINYINT UNSIGNED NOT NULL DEFAULT 0, {$flags},
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, camp_slug, utm_source, utm_medium, registered, bot, internal)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::top($db) . "` (
        fecha DATE NOT NULL, dim VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$dims},
        value_hash CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$flags},
        value VARCHAR(255) NOT NULL DEFAULT '', detail VARCHAR(255) NOT NULL DEFAULT '',
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, dim, camp_slug, utm_source, utm_medium, value_hash, bot, internal),
        KEY idx_scm_tracker_top_dim (dim, fecha)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::visitors($db) . "` (
        fecha DATE NOT NULL, visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$dims}, {$flags},
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, visitor_hash, camp_slug, utm_source, utm_medium, bot, internal)
      ) {$engine}",
    ];
  }

  /** Las mismas tablas para las pruebas con SQLite. @return list<string> */
  private static function sqliteTables(Database $db): array
  {
    $dims = 'camp_slug TEXT NOT NULL, utm_source TEXT NOT NULL, utm_medium TEXT NOT NULL';
    $flags = 'bot INTEGER NOT NULL DEFAULT 0, internal INTEGER NOT NULL DEFAULT 0';
    return [
      'CREATE TABLE IF NOT EXISTS `' . self::daily($db) . "` (fecha TEXT NOT NULL, {$dims}, registered INTEGER NOT NULL DEFAULT 0, {$flags},
        hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, camp_slug, utm_source, utm_medium, registered, bot, internal))",
      'CREATE TABLE IF NOT EXISTS `' . self::top($db) . "` (fecha TEXT NOT NULL, dim TEXT NOT NULL, {$dims}, value_hash TEXT NOT NULL, {$flags},
        value TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', hits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, dim, camp_slug, utm_source, utm_medium, value_hash, bot, internal))",
      'CREATE INDEX IF NOT EXISTS idx_scm_tracker_top_dim ON `' . self::top($db) . '` (dim, fecha)',
      'CREATE TABLE IF NOT EXISTS `' . self::visitors($db) . "` (fecha TEXT NOT NULL, visitor_hash TEXT NOT NULL, {$dims}, {$flags},
        hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, visitor_hash, camp_slug, utm_source, utm_medium, bot, internal))",
    ];
  }
}
