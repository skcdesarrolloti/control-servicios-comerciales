<?php

declare(strict_types=1);

namespace SCM\Commercial\Medios;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Tablas propias de la lista de Medios:
 * - scm_medios: la lista (nombre visible único, slug único para los enlaces UTM, alias en JSON, activo, orden).
 * - scm_medios_log: historial de cada cambio (agregar, renombrar, unir, alias, normalizar...), con los conteos por tabla.
 * - scm_medios_backup: el valor anterior de cada registro que se reescribió, para poder revertirlo
 *   (php bin/medios-normalize.php --revert=<id del historial>).
 *
 * Igual que TrackerSchema, el DDL nunca corre en una petición web: lo crea `php bin/medios-normalize.php --apply`, que deja
 * la versión en Settings (MedioSchema::SETTING). Mientras no exista, el panel resuelve con MedioSeed y Configuración ›
 * Medios avisa que falta ese paso.
 */
final class MedioSchema
{
  public const VERSION = 1;
  public const SETTING = 'medios_schema';

  public static function medios(Database $db): string
  {
    return $db->table('scm_medios');
  }

  public static function log(Database $db): string
  {
    return $db->table('scm_medios_log');
  }

  public static function backup(Database $db): string
  {
    return $db->table('scm_medios_backup');
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
   * Crea las tablas y deja la versión en Settings. Solo desde la línea de comandos (o en las pruebas).
   * @throws \LogicException En una petición web.
   */
  public static function install(Database $db, Settings $settings): void
  {
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
      throw new \LogicException('Las tablas de Medios se crean con php bin/medios-normalize.php --apply.');
    }
    foreach (self::tables($db) as $query) {
      $db->pdo()->exec($query);
    }
    if (self::version($settings) < self::VERSION) {
      $settings->set(self::SETTING, self::VERSION, 0, true);
      $settings->refresh();
    }
  }

  /** ¿La tabla existe? (sin information_schema, igual que las tablas del tracker). */
  public static function exists(Database $db, string $table): bool
  {
    try {
      $db->getVar('SELECT 1 FROM `' . $table . '` LIMIT 1');
      return true;
    } catch (\Throwable) {
      return false;
    }
  }

  /** @return list<string> */
  public static function tables(Database $db): array
  {
    if ($db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
      return [
        'CREATE TABLE IF NOT EXISTS `' . self::medios($db) . "` (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL COLLATE NOCASE UNIQUE,
          slug TEXT NOT NULL UNIQUE, aliases TEXT NOT NULL DEFAULT '[]', activo INTEGER NOT NULL DEFAULT 1, orden INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '', updated_by TEXT NOT NULL DEFAULT '')",
        'CREATE TABLE IF NOT EXISTS `' . self::log($db) . "` (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, from_value TEXT NOT NULL DEFAULT '',
          to_value TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', id_empleado TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT '')",
        'CREATE TABLE IF NOT EXISTS `' . self::backup($db) . "` (id INTEGER PRIMARY KEY AUTOINCREMENT, log_id INTEGER NOT NULL, table_name TEXT NOT NULL,
          row_id INTEGER NOT NULL, column_name TEXT NOT NULL, old_value TEXT, new_value TEXT)",
      ];
    }
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
      'CREATE TABLE IF NOT EXISTS `' . self::medios($db) . "` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, nombre VARCHAR(60) NOT NULL, slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        aliases TEXT NOT NULL, activo TINYINT UNSIGNED NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL, updated_at DATETIME NULL, updated_by VARCHAR(40) NOT NULL DEFAULT '',
        PRIMARY KEY (id), UNIQUE KEY nombre (nombre), UNIQUE KEY slug (slug)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::log($db) . "` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, action VARCHAR(30) NOT NULL, from_value VARCHAR(255) NOT NULL DEFAULT '',
        to_value VARCHAR(255) NOT NULL DEFAULT '', detail TEXT NOT NULL, id_empleado VARCHAR(40) NOT NULL DEFAULT '', created_at DATETIME NULL,
        PRIMARY KEY (id)
      ) {$engine}",
      'CREATE TABLE IF NOT EXISTS `' . self::backup($db) . "` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, log_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL,
        row_id BIGINT UNSIGNED NOT NULL, column_name VARCHAR(64) NOT NULL, old_value TEXT NULL, new_value TEXT NULL,
        PRIMARY KEY (id), KEY log_id (log_id)
      ) {$engine}",
    ];
  }
}
