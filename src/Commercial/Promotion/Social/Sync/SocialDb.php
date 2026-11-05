<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use SCM\Core\Database;

/**
 * Adaptador para las clases copiadas de dashboard-marketing: el mismo Database:: estático de ese panel (rows, one, value,
 * execute, insert, update y table) y app_config(), sobre la conexión y la configuración del panel comercial.
 * Se inicia una vez con boot(). El parámetro $types de esas llamadas (formato de mysqli) se ignora: PDO no lo necesita.
 */
final class SocialDb
{
  private static ?Database $db = null;
  /** @var array<string,mixed> */
  private static array $config = [];

  /** @param array<string,mixed> $config Configuración del panel (claves meta, youtube y tiktok). */
  public static function boot(Database $db, array $config): void
  {
    self::$db = $db;
    self::$config = $config;
  }

  private static function db(): Database
  {
    if (self::$db === null) {
      throw new \LogicException('SocialDb no se ha iniciado.');
    }
    return self::$db;
  }

  /** app_config('youtube.client_id', ''): secciones meta, youtube y tiktok de config/app.php. @param mixed $default @return mixed */
  public static function config(string $key, $default = null)
  {
    [$section, $name] = array_pad(explode('.', $key, 2), 2, '');
    return self::$config[$section][$name] ?? $default;
  }

  public static function table(string $name): string
  {
    return self::db()->table($name);
  }

  /** @param list<mixed> $params @return list<array<string,mixed>> */
  public static function rows(string $sql, string $types = '', array $params = []): array
  {
    return self::db()->getResults($sql, $params);
  }

  /** @param list<mixed> $params @return array<string,mixed> */
  public static function one(string $sql, string $types = '', array $params = []): array
  {
    return self::db()->getRow($sql, $params) ?? [];
  }

  /** @param list<mixed> $params @return mixed */
  public static function value(string $sql, string $types = '', array $params = [])
  {
    return self::db()->getVar($sql, $params);
  }

  /** @param list<mixed> $params */
  public static function execute(string $sql, string $types = '', array $params = []): bool
  {
    return self::db()->pdo()->prepare($sql)->execute($params);
  }

  /** @param array<string,mixed> $data */
  public static function insert(string $table, array $data): int
  {
    if ($data === []) {
      return 0;
    }
    self::db()->insert($table, array_map(static fn($value): string => (string) $value, $data));
    return (int) self::db()->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public static function update(string $table, array $data, int $id): bool
  {
    self::db()->update($table, array_map(static fn($value): string => (string) $value, $data), ['_ID' => $id]);
    return true;
  }

}
