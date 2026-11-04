<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;

/**
 * Candado sin espera para el rollup: el cron y la página nunca procesan a la vez y quien no lo obtiene sigue sin
 * bloquearse. En MySQL/MariaDB usa GET_LOCK (se libera solo si la conexión se cierra); en SQLite, un flock de archivo.
 */
final class TrackerLock
{
  private Database $db;
  private string $name;
  private string $file;
  private bool $held = false;
  /** @var resource|null */
  private $handle = null;

  public function __construct(Database $db, ?string $file = null)
  {
    $this->db = $db;
    $this->name = 'scm_tracker_rollup_' . substr(sha1($db->prefix()), 0, 12);
    $this->file = $file ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-tracker-rollup-' . substr(sha1($db->prefix() . '|' . __DIR__), 0, 12) . '.lock';
  }

  public function acquire(): bool
  {
    if ($this->held) {
      return true;
    }
    if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $this->held = (int) $this->db->getVar('SELECT GET_LOCK(LEFT(CONCAT(?, DATABASE()), 64), 0)', [$this->name . '_']) === 1;
      return $this->held;
    }
    $handle = @fopen($this->file, 'c');
    if ($handle === false) {
      return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return false;
    }
    $this->handle = $handle;
    $this->held = true;
    return true;
  }

  public function release(): void
  {
    if (!$this->held) {
      return;
    }
    $this->held = false;
    if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $this->db->getVar('SELECT RELEASE_LOCK(LEFT(CONCAT(?, DATABASE()), 64))', [$this->name . '_']);
      return;
    }
    if (is_resource($this->handle)) {
      flock($this->handle, LOCK_UN);
      fclose($this->handle);
    }
    $this->handle = null;
  }

  public function __destruct()
  {
    $this->release();
  }
}
