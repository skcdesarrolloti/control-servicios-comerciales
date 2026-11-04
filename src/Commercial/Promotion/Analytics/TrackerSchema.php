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
 * `php bin/tracker-rollup.php` también lo prepara. El estado del rollup (marca de agua, recálculo pendiente, última purga)
 * vive en su propia tabla de una fila, scm_tracker_state, y se escribe en la misma transacción que las sumas: así no se
 * reescribe la fila de configuración del panel (ni su cct_author_id) en cada lote.
 *
 * Versión 2: la llave de scm_tracker_daily_top empieza por (dim, fecha), que es como se lee (WHERE dim = ? AND fecha
 * BETWEEN), sin índice secundario. La 1 nunca se publicó; si existiera, migrateFromV1() la convierte desde la línea de
 * comandos (nunca desde una petición web).
 */
final class TrackerSchema
{
  public const VERSION = 2;
  public const SETTING = 'promotion_tracker_schema';
  /** Donde la versión 1 guardaba el estado del rollup (dentro de la configuración del panel). */
  public const LEGACY_STATE_SETTING = 'promotion_tracker_rollup';

  /** Valores con que se guardan los vacíos. */
  public const EMPTY_CAMPAIGN = 'general';
  public const EMPTY_SOURCE = 'Directo';
  public const EMPTY_MEDIUM = 'Sin medio';

  /**
   * Campañas que no son campañas: el tracker de dashboard-marketing (tracker.js, TrackerRepository) las asigna solo.
   * No cuentan en "Campañas con visitas" y en las listas se muestran con este nombre.
   */
  public const PSEUDO_CAMPAIGNS = [
    self::EMPTY_CAMPAIGN => 'Sin campaña',
    'inmueble_view' => 'Vista de inmueble',
    'event_click' => 'Clic registrado',
  ];

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

  public static function state(Database $db): string
  {
    return $db->table('scm_tracker_state');
  }

  public static function raw(Database $db): string
  {
    return $db->table('jet_cct_tracker_logs');
  }

  public static function version(Settings $settings): int
  {
    try {
      return (int) $settings->get(self::SETTING, 0);
    } catch (\Throwable) {
      return 0;
    }
  }

  public static function isReady(Settings $settings): bool
  {
    return self::version($settings) >= self::VERSION;
  }

  /**
   * Crea las tablas (una vez) y deja la versión en Settings.
   * @throws \RuntimeException Si hay que migrar la versión 1 y no se está en la línea de comandos.
   */
  public static function ensure(Database $db, Settings $settings, bool $force = false): void
  {
    self::$checked ??= new \WeakMap();
    if (!$force && (isset(self::$checked[$db]) || self::isReady($settings))) {
      self::$checked[$db] = true;
      return;
    }
    $current = self::version($settings);
    if ($current === 1) {
      if (PHP_SAPI !== 'cli') {
        throw new \RuntimeException('Los resúmenes de la analítica web necesitan una actualización: se hace con php bin/tracker-rollup.php.');
      }
      self::migrateFromV1($db, $settings);
    }
    foreach (self::tables($db, self::top($db)) as $query) {
      $db->pdo()->exec($query);
    }
    if ((int) $db->getVar('SELECT COUNT(*) FROM `' . self::state($db) . '` WHERE `id` = 1') === 0) {
      $db->pdo()->exec('INSERT INTO `' . self::state($db) . '` (`id`) VALUES (1)');
    }
    if ($current < self::VERSION) {
      $settings->set(self::SETTING, self::VERSION, 0, true);
    }
    self::$checked[$db] = true;
  }

  /**
   * Versión 1 -> 2: rehace scm_tracker_daily_top con la llave (dim, fecha, ...) copiando sus filas, y pasa el estado del
   * rollup de Settings a scm_tracker_state. Solo desde la línea de comandos.
   */
  private static function migrateFromV1(Database $db, Settings $settings): void
  {
    $pdo = $db->pdo();
    $top = self::top($db);
    $next = $db->table('scm_tracker_daily_top_v2');
    $pdo->exec('DROP TABLE IF EXISTS `' . $next . '`');
    foreach (self::tables($db, $next) as $query) {
      $pdo->exec($query);
    }
    $exists = true;
    try {
      $db->getVar('SELECT 1 FROM `' . $top . '` LIMIT 1');
    } catch (\Throwable) {
      $exists = false;
    }
    if ($exists) {
      $columns = '`fecha`, `dim`, `camp_slug`, `utm_source`, `utm_medium`, `value_hash`, `bot`, `internal`, `value`, `detail`, `hits`';
      $pdo->exec('INSERT INTO `' . $next . '` (' . $columns . ') SELECT ' . $columns . ' FROM `' . $top . '`');
      $pdo->exec('DROP TABLE `' . $top . '`');
    }
    $pdo->exec('ALTER TABLE `' . $next . '` RENAME TO `' . $top . '`');

    try {
      $legacy = $settings->get(self::LEGACY_STATE_SETTING, null);
    } catch (\Throwable) {
      $legacy = null;
    }
    if (is_array($legacy)) {
      $state = self::state($db);
      if ((int) $db->getVar('SELECT COUNT(*) FROM `' . $state . '` WHERE `id` = 1') === 0) {
        $pdo->exec('INSERT INTO `' . $state . '` (`id`) VALUES (1)');
      }
      $db->pdo()->prepare('UPDATE `' . $state . '` SET `last_id` = ?, `last_fecha` = ?, `updated_at` = ?, `purged_before` = ?, `last_purge` = ? WHERE `id` = 1')->execute([
        max(0, (int) ($legacy['last_id'] ?? 0)),
        (string) ($legacy['last_fecha'] ?? ''),
        (string) ($legacy['updated_at'] ?? ''),
        (string) ($legacy['purged_before'] ?? ''),
        is_array($legacy['last_purge'] ?? null) ? (string) json_encode($legacy['last_purge'], JSON_UNESCAPED_UNICODE) : '',
      ]);
      $settings->set(self::LEGACY_STATE_SETTING, null, 0, true);
    }
  }

  /** @return list<string> */
  private static function tables(Database $db, string $top): array
  {
    return $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? self::sqliteTables($db, $top) : self::mysqlTables($db, $top);
  }

  /** @return list<string> */
  private static function mysqlTables(Database $db, string $top): array
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
      'CREATE TABLE IF NOT EXISTS `' . $top . "` (
        fecha DATE NOT NULL, dim VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$dims},
        value_hash CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$flags},
        value VARCHAR(255) NOT NULL DEFAULT '', detail VARCHAR(255) NOT NULL DEFAULT '',
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (dim, fecha, camp_slug, utm_source, utm_medium, value_hash, bot, internal)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::visitors($db) . "` (
        fecha DATE NOT NULL, visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, {$dims}, {$flags},
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, visitor_hash, camp_slug, utm_source, utm_medium, bot, internal)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::state($db) . "` (
        id TINYINT UNSIGNED NOT NULL, last_id BIGINT UNSIGNED NOT NULL DEFAULT 0, last_fecha VARCHAR(19) NOT NULL DEFAULT '',
        updated_at VARCHAR(19) NOT NULL DEFAULT '', purged_before VARCHAR(10) NOT NULL DEFAULT '',
        last_purge VARCHAR(1000) NOT NULL DEFAULT '', rebuild VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (id)
      ) {$engine}",
    ];
  }

  /** Las mismas tablas para las pruebas con SQLite. @return list<string> */
  private static function sqliteTables(Database $db, string $top): array
  {
    $dims = 'camp_slug TEXT NOT NULL, utm_source TEXT NOT NULL, utm_medium TEXT NOT NULL';
    $flags = 'bot INTEGER NOT NULL DEFAULT 0, internal INTEGER NOT NULL DEFAULT 0';
    return [
      'CREATE TABLE IF NOT EXISTS `' . self::daily($db) . "` (fecha TEXT NOT NULL, {$dims}, registered INTEGER NOT NULL DEFAULT 0, {$flags},
        hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, camp_slug, utm_source, utm_medium, registered, bot, internal))",
      'CREATE TABLE IF NOT EXISTS `' . $top . "` (fecha TEXT NOT NULL, dim TEXT NOT NULL, {$dims}, value_hash TEXT NOT NULL, {$flags},
        value TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', hits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (dim, fecha, camp_slug, utm_source, utm_medium, value_hash, bot, internal))",
      'CREATE TABLE IF NOT EXISTS `' . self::visitors($db) . "` (fecha TEXT NOT NULL, visitor_hash TEXT NOT NULL, {$dims}, {$flags},
        hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, visitor_hash, camp_slug, utm_source, utm_medium, bot, internal))",
      'CREATE TABLE IF NOT EXISTS `' . self::state($db) . "` (id INTEGER NOT NULL PRIMARY KEY, last_id INTEGER NOT NULL DEFAULT 0,
        last_fecha TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '', purged_before TEXT NOT NULL DEFAULT '',
        last_purge TEXT NOT NULL DEFAULT '', rebuild TEXT NOT NULL DEFAULT '')",
    ];
  }
}
