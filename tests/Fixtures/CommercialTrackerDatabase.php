<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PDO;
use SCM\Core\Database;

/**
 * Base SQLite en memoria con las tablas del tracker de dashboard-marketing (todas TEXT, como los CCT de JetEngine)
 * y la fila de configuración del panel.
 */
final class CommercialTrackerDatabase
{
  public const COLUMNS = ['cct_status', 'camp_slug', 'user_id', 'ip_address', 'user_agent', 'page_url', 'referrer', 'fecha_visita', 'cct_author_id',
    'cct_created', 'cct_modified', 'country', 'city', 'event_type', 'object_id', 'object_ref', 'event_label', 'utm_source', 'utm_medium'];

  public const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

  public static function create(): Database
  {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
    self::addTrackerTables($pdo);
    return new Database($pdo);
  }

  /** Tablas del tracker de dashboard-marketing (también las usa la base de Promoción). */
  public static function addTrackerTables(PDO $pdo): void
  {
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_tracker_logs (_ID INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', array_map(static fn(string $c): string => $c . ' TEXT', self::COLUMNS)) . ')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_tracker_camps (_ID INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, nombre TEXT, descripcion TEXT, fecha_creacion TEXT, activo TEXT)');
  }

  /** @param array<string,string|int|null> $row Columnas de tracker_logs; las omitidas quedan como en el tracker real. */
  public static function hit(Database $db, array $row): int
  {
    $row += [
      'cct_status' => 'publish', 'camp_slug' => 'general', 'user_id' => '0', 'ip_address' => '200.1.1.1', 'user_agent' => self::CHROME,
      'page_url' => 'https://sucasainmobiliaria.com.co/', 'referrer' => '', 'fecha_visita' => '2026-10-01 10:00:00', 'country' => 'Colombia',
      'city' => 'Bogotá', 'event_type' => 'view', 'object_id' => '0', 'object_ref' => '', 'event_label' => '', 'utm_source' => 'Directo', 'utm_medium' => 'Navegador',
    ];
    $db->insert('wp_jet_cct_tracker_logs', array_map(static fn($value): ?string => $value === null ? null : (string) $value, $row));
    return (int) $db->lastInsertId();
  }
}
