<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Tablas de Promoción › Reuniones. Son las mismas de dashboard-marketing (app/MeetingSchema.php, mismo DDL) porque las
 * dos aplicaciones conviven sobre la misma base: aquí solo se agregan columnas nuevas y siempre anulables.
 *
 * A diferencia del original, el DDL no corre en cada petición: ensure() lo ejecuta una vez y deja la versión en Settings
 * (MeetingSchema::SETTING). bin/migrate-promotion-meetings.php lo corre a mano.
 */
final class MeetingSchema
{
  public const VERSION = 2;
  public const SETTING = 'promotion_meetings_schema';

  /** Columnas agregadas por el panel comercial (borrado lógico con rastro). El legado no las conoce: todas anulables. */
  public const ADDED_COLUMNS = [
    'deleted_at' => 'DATETIME NULL DEFAULT NULL',
    'deleted_by' => 'BIGINT NULL DEFAULT NULL',
    'deleted_by_name' => 'VARCHAR(191) NULL DEFAULT NULL',
    'deleted_status' => 'VARCHAR(24) NULL DEFAULT NULL',
  ];

  /**
   * Columnas agregadas a los puntos (versión 2). Al eliminar una reunión sus puntos activos quedan con active = 0 para
   * que el legado deje de contarlos (su indicador de cumplimiento no cruza con la reunión); deleted_at distingue esos
   * puntos de los que ya se habían archivado uno a uno.
   */
  public const ADDED_ITEM_COLUMNS = [
    'deleted_at' => 'DATETIME NULL DEFAULT NULL',
  ];

  /** @var \WeakMap<Database,bool>|null Conexiones ya revisadas en este proceso. */
  private static ?\WeakMap $checked = null;

  public static function ensure(Database $db, Settings $settings, bool $force = false): void
  {
    self::$checked ??= new \WeakMap();
    if (!$force && (isset(self::$checked[$db]) || (int) $settings->get(self::SETTING, 0) >= self::VERSION)) {
      self::$checked[$db] = true;
      return;
    }
    $sqlite = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    foreach ($sqlite ? self::sqliteTables($db) : self::mysqlTables($db) as $query) {
      $db->pdo()->exec($query);
    }
    foreach ([[$db->table('marketing_meetings'), self::ADDED_COLUMNS], [$db->table('marketing_meeting_items'), self::ADDED_ITEM_COLUMNS]] as [$table, $columns]) {
      foreach ($columns as $column => $definition) {
        if (self::hasColumn($db, $table, $column)) {
          continue;
        }
        try {
          $db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` " . ($sqlite ? self::sqliteType($definition) : $definition));
        } catch (\PDOException $exception) {
          // Otra petición pudo agregar la misma columna al mismo tiempo.
          if (!self::hasColumn($db, $table, $column)) {
            throw $exception;
          }
        }
      }
    }
    if ((int) $settings->get(self::SETTING, 0) < self::VERSION) {
      $settings->set(self::SETTING, self::VERSION);
    }
    self::$checked[$db] = true;
  }

  /** @phpstan-impure */
  public static function hasColumn(Database $db, string $table, string $column): bool
  {
    if ($db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
      foreach ($db->getResults("PRAGMA table_info(`{$table}`)") as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
          return true;
        }
      }
      return false;
    }
    return (bool) $db->getVar(
      'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
      [$table, $column]
    );
  }

  private static function sqliteType(string $definition): string
  {
    return str_starts_with($definition, 'BIGINT') ? 'INTEGER NULL DEFAULT NULL' : 'TEXT NULL DEFAULT NULL';
  }

  /** Mismo DDL de dashboard-marketing/app/MeetingSchema.php. @return array<int,string> */
  private static function mysqlTables(Database $db): array
  {
    $meetings = $db->table('marketing_meetings');
    $items = $db->table('marketing_meeting_items');
    $history = $db->table('marketing_meeting_action_history');
    return [
      "CREATE TABLE IF NOT EXISTS `{$meetings}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(220) NOT NULL,
        meeting_date DATE NOT NULL,
        objective TEXT NULL,
        participants_json MEDIUMTEXT NULL,
        conclusions MEDIUMTEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'draft',
        carried_from_meeting_id BIGINT UNSIGNED NULL,
        created_by BIGINT NULL,
        created_by_name VARCHAR(191) NOT NULL DEFAULT '',
        updated_by BIGINT NULL,
        updated_by_name VARCHAR(191) NOT NULL DEFAULT '',
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        archived_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_meetings_date (meeting_date),
        KEY idx_meetings_status (status),
        UNIQUE KEY uq_meetings_previous (carried_from_meeting_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS `{$items}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_id BIGINT UNSIGNED NOT NULL,
        section VARCHAR(24) NOT NULL,
        title VARCHAR(300) NOT NULL,
        content MEDIUMTEXT NULL,
        observation MEDIUMTEXT NULL,
        related_improvement_id BIGINT UNSIGNED NULL,
        responsible_employee_id BIGINT NULL,
        responsible_name VARCHAR(191) NOT NULL DEFAULT '',
        due_date DATE NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        failure_reason MEDIUMTEXT NULL,
        carried_from_item_id BIGINT UNSIGNED NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT NULL,
        created_by_name VARCHAR(191) NOT NULL DEFAULT '',
        updated_by BIGINT NULL,
        updated_by_name VARCHAR(191) NOT NULL DEFAULT '',
        completed_by BIGINT NULL,
        completed_by_name VARCHAR(191) NOT NULL DEFAULT '',
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_meeting_carried_item (meeting_id, carried_from_item_id),
        KEY idx_meeting_items_meeting (meeting_id, active, section, sort_order),
        KEY idx_meeting_items_status (status),
        KEY idx_meeting_items_responsible (responsible_employee_id),
        KEY idx_meeting_items_due (due_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS `{$history}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_id BIGINT UNSIGNED NOT NULL,
        action_item_id BIGINT UNSIGNED NOT NULL,
        from_status VARCHAR(24) NOT NULL DEFAULT '',
        to_status VARCHAR(24) NOT NULL,
        reason MEDIUMTEXT NULL,
        changed_by BIGINT NULL,
        changed_by_name VARCHAR(191) NOT NULL DEFAULT '',
        changed_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_meeting_history_action (action_item_id, changed_at),
        KEY idx_meeting_history_meeting (meeting_id, changed_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
  }

  /** Las mismas tablas para las pruebas con SQLite. @return array<int,string> */
  private static function sqliteTables(Database $db): array
  {
    $meetings = $db->table('marketing_meetings');
    $items = $db->table('marketing_meeting_items');
    $history = $db->table('marketing_meeting_action_history');
    return [
      "CREATE TABLE IF NOT EXISTS `{$meetings}` (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, meeting_date TEXT NOT NULL, objective TEXT NULL,
        participants_json TEXT NULL, conclusions TEXT NULL, status TEXT NOT NULL DEFAULT 'draft', carried_from_meeting_id INTEGER NULL UNIQUE,
        created_by INTEGER NULL, created_by_name TEXT NOT NULL DEFAULT '', updated_by INTEGER NULL, updated_by_name TEXT NOT NULL DEFAULT '',
        started_at TEXT NULL, completed_at TEXT NULL, archived_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
      "CREATE TABLE IF NOT EXISTS `{$items}` (id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER NOT NULL, section TEXT NOT NULL, title TEXT NOT NULL,
        content TEXT NULL, observation TEXT NULL, related_improvement_id INTEGER NULL, responsible_employee_id INTEGER NULL,
        responsible_name TEXT NOT NULL DEFAULT '', due_date TEXT NULL, status TEXT NOT NULL DEFAULT 'pending', failure_reason TEXT NULL,
        carried_from_item_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL,
        created_by_name TEXT NOT NULL DEFAULT '', updated_by INTEGER NULL, updated_by_name TEXT NOT NULL DEFAULT '', completed_by INTEGER NULL,
        completed_by_name TEXT NOT NULL DEFAULT '', completed_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (meeting_id, carried_from_item_id))",
      "CREATE TABLE IF NOT EXISTS `{$history}` (id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER NOT NULL, action_item_id INTEGER NOT NULL,
        from_status TEXT NOT NULL DEFAULT '', to_status TEXT NOT NULL, reason TEXT NULL, changed_by INTEGER NULL, changed_by_name TEXT NOT NULL DEFAULT '',
        changed_at TEXT NOT NULL)",
    ];
  }
}
