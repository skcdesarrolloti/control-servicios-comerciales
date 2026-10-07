<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use PDO;
use SCM\Core\Database;

/** Database compatibility for the supplied precaptaciones panel. */
final class DatabaseAdapter
{
  public string $prefix;
  public string $last_error = '';
  public int $insert_id = 0;

  public function __construct(private Database $db)
  {
    $this->prefix = $db->prefix();
  }

  public function prepare(string $sql, ...$args): string
  {
    if (count($args) === 1 && is_array($args[0])) $args = $args[0];
    $sql = preg_replace("/(['\"])%s\\1/", '%s', $sql);
    $index = 0;
    return preg_replace_callback('/%%|%[sdf]/', function (array $match) use (&$index, $args): string {
      if ($match[0] === '%%') return '%';
      if (!array_key_exists($index, $args)) throw new \InvalidArgumentException('Faltan parámetros SQL.');
      $value = $args[$index++];
      return match ($match[0]) {
        '%d' => (string) (int) $value,
        '%f' => (string) (float) $value,
        default => $this->db->pdo()->quote((string) $value),
      };
    }, $sql);
  }

  public function get_results(string $sql, string $mode = 'OBJECT'): array
  {
    $rows = $this->db->getResults($sql);
    return $mode === 'ARRAY_A' ? $rows : array_map(static fn(array $row): object => (object) $row, $rows);
  }

  public function get_row(string $sql, string $mode = 'OBJECT')
  {
    $row = $this->db->getRow($sql);
    return $mode === 'ARRAY_A' || $row === null ? $row : (object) $row;
  }

  public function get_var(string $sql)
  {
    if (preg_match('/^SHOW TABLES LIKE (.+)$/i', $sql, $match)) {
      if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return $this->db->getVar('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = ' . $match[1]);
      }
    }
    return $this->db->getVar($sql);
  }

  public function get_col(string $sql, int $column = 0): array
  {
    if (preg_match('/^DESCRIBE ([\w]+)$/i', $sql, $match) && $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
      return array_column($this->db->getResults('PRAGMA table_info(`' . $match[1] . '`)'), 'name');
    }
    return $this->db->getCol($sql, [], $column);
  }

  public function esc_like(string $value): string { return $this->db->escapeLike($value); }

  public function insert(string $table, array $data, array $formats = [])
  {
    try {
      $this->db->insert($table, $data);
      $this->insert_id = (int) $this->db->lastInsertId();
      return 1;
    } catch (\Throwable $exception) {
      $this->last_error = 'No se pudo guardar el registro.';
      error_log($exception->getMessage());
      return false;
    }
  }

  public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = [])
  {
    try { return $this->db->update($table, $data, $where); }
    catch (\Throwable $exception) {
      $this->last_error = 'No se pudo actualizar el registro.';
      error_log($exception->getMessage());
      return false;
    }
  }

  public function query(string $sql)
  {
    try { return $this->db->pdo()->exec($sql); }
    catch (\Throwable $exception) {
      $this->last_error = 'No se pudo actualizar el registro.';
      error_log($exception->getMessage());
      return false;
    }
  }
}
