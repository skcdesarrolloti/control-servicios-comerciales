<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use SCM\Core\Database;

/** Keep response dates separate from generic CCT modification timestamps. */
final class ResponseDates
{
  public function __construct(private Database $db) {}

  public function ensureSchema(): void
  {
    $table = $this->db->table('scm_precaptacion_responses');
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$table}` (precaptacion_id BIGINT PRIMARY KEY, responded_at VARCHAR(19) NOT NULL)");
  }

  public function record(int $id): void
  {
    $table = $this->db->table('scm_precaptacion_responses');
    $time = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
    if ($this->db->getVar("SELECT precaptacion_id FROM `{$table}` WHERE precaptacion_id = ?", [$id]) !== null) {
      $this->db->update($table, ['responded_at'=>$time], ['precaptacion_id'=>$id]);
    } else {
      $this->db->insert($table, ['precaptacion_id'=>$id,'responded_at'=>$time]);
    }
  }

  public function forIds(array $ids): array
  {
    if ($ids === []) return [];
    $table = $this->db->table('scm_precaptacion_responses');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->db->getResults("SELECT precaptacion_id, responded_at FROM `{$table}` WHERE precaptacion_id IN ({$placeholders})", $ids);
    return array_column($rows, 'responded_at', 'precaptacion_id');
  }
}
