<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;

final class CommercialTaskAnalysisRepository
{
  private const MAX_ANALYSES_PER_TASK = 3;

  private Database $db;
  private string $table;
  private bool $tableReady = false;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->table = $db->table('scm_commercial_task_analyses');
  }

  /** @return array<int,array<string,mixed>> */
  public function listForTicket(int $ticketPk): array
  {
    if ($ticketPk <= 0) {
      return [];
    }
    $this->ensureTable();
    $rows = $this->db->getResults(
      "SELECT `id`, `ticket_pk`, `ticket_id`, `created_at`, `created_date`, `created_by_id`,
              `created_by_employee_id`, `created_by_name`, `model`, `summary`, `payload`
         FROM `{$this->table}`
        WHERE `ticket_pk` = ?
        ORDER BY `created_at` DESC, `id` DESC
        LIMIT " . self::MAX_ANALYSES_PER_TASK,
      [$ticketPk]
    );

    return array_values(array_map(fn(array $row): array => $this->normalizeRow($row), $rows));
  }

  /** @return array<string,mixed>|null */
  public function todayForTicket(int $ticketPk): ?array
  {
    if ($ticketPk <= 0) {
      return null;
    }
    $this->ensureTable();
    $row = $this->db->getRow(
      "SELECT `id`, `ticket_pk`, `ticket_id`, `created_at`, `created_date`, `created_by_id`,
              `created_by_employee_id`, `created_by_name`, `model`, `summary`, `payload`
         FROM `{$this->table}`
        WHERE `ticket_pk` = ? AND `created_date` = CURDATE()
        ORDER BY `created_at` DESC, `id` DESC
        LIMIT 1",
      [$ticketPk]
    );
    return is_array($row) ? $this->normalizeRow($row) : null;
  }

  public function countForTicket(int $ticketPk): int
  {
    if ($ticketPk <= 0) {
      return 0;
    }
    $this->ensureTable();
    return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->table}` WHERE `ticket_pk` = ?", [$ticketPk]);
  }

  /**
   * @param array<string,mixed> $ticket
   * @param array<string,mixed> $analysis
   * @return array<string,mixed>
   */
  public function save(array $ticket, array $analysis): array
  {
    $ticketPk = (int) ($ticket['_ID'] ?? 0);
    if ($ticketPk <= 0) {
      throw new \InvalidArgumentException('Tarea inválida para guardar análisis.');
    }

    $today = $this->todayForTicket($ticketPk);
    if (is_array($today)) {
      throw new \RuntimeException('Esta tarea ya tiene un análisis guardado hoy. Puedes verlo en el historial de análisis.');
    }
    if ($this->countForTicket($ticketPk) >= self::MAX_ANALYSES_PER_TASK) {
      throw new \RuntimeException('Esta tarea ya tiene 3 análisis guardados. Elimina uno anterior para generar otro.');
    }

    $payload = $this->compactAnalysis($analysis);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($payloadJson) || $payloadJson === '') {
      throw new \RuntimeException('No fue posible guardar el análisis.');
    }

    $createdAt = time();
    $this->db->insert($this->table, [
      'ticket_pk' => $ticketPk,
      'ticket_id' => mb_substr(trim((string) ($ticket['id_ticket'] ?? '')), 0, 64),
      'created_at' => $createdAt,
      'created_date' => date('Y-m-d', $createdAt),
      'created_by_id' => Auth::userId(),
      'created_by_employee_id' => mb_substr(Auth::employeeId(), 0, 64),
      'created_by_name' => mb_substr(Auth::user(), 0, 160),
      'model' => mb_substr((string) ($payload['model'] ?? ''), 0, 80),
      'summary' => mb_substr((string) ($payload['resumen'] ?? ''), 0, 700),
      'payload' => $payloadJson,
    ]);

    $id = (int) $this->db->lastInsertId();
    $row = $this->db->getRow(
      "SELECT `id`, `ticket_pk`, `ticket_id`, `created_at`, `created_date`, `created_by_id`,
              `created_by_employee_id`, `created_by_name`, `model`, `summary`, `payload`
         FROM `{$this->table}`
        WHERE `id` = ?
        LIMIT 1",
      [$id]
    );

    return $this->normalizeRow(is_array($row) ? $row : [
      'id' => $id,
      'ticket_pk' => $ticketPk,
      'ticket_id' => $ticket['id_ticket'] ?? '',
      'created_at' => $createdAt,
      'created_date' => date('Y-m-d', $createdAt),
      'created_by_id' => Auth::userId(),
      'created_by_employee_id' => Auth::employeeId(),
      'created_by_name' => Auth::user(),
      'model' => $payload['model'] ?? '',
      'summary' => $payload['resumen'] ?? '',
      'payload' => $payloadJson,
    ]);
  }

  public function delete(int $ticketPk, int $analysisId): bool
  {
    if ($ticketPk <= 0 || $analysisId <= 0) {
      return false;
    }
    $this->ensureTable();
    return $this->db->delete($this->table, ['id' => $analysisId, 'ticket_pk' => $ticketPk]) > 0;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function normalizeRow(array $row): array
  {
    $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
    if (!is_array($payload)) {
      $payload = [];
    }

    $createdAt = (int) ($row['created_at'] ?? 0);
    $payload['id'] = (int) ($row['id'] ?? 0);
    $payload['ticket_pk'] = (int) ($row['ticket_pk'] ?? 0);
    $payload['ticket_id'] = trim((string) ($row['ticket_id'] ?? ''));
    $payload['created_at'] = $createdAt;
    $payload['created_date'] = trim((string) ($row['created_date'] ?? ''));
    $payload['created_label'] = $createdAt > 0 ? date('d/m/Y h:i a', $createdAt) : 'Sin fecha';
    $payload['created_by'] = trim((string) ($row['created_by_name'] ?? '')) ?: 'Sistema';
    $payload['created_by_employee_id'] = trim((string) ($row['created_by_employee_id'] ?? ''));
    $payload['model'] = trim((string) ($row['model'] ?? $payload['model'] ?? ''));
    $payload['resumen'] = trim((string) ($payload['resumen'] ?? $row['summary'] ?? ''));

    return $payload;
  }

  /** @param array<string,mixed> $analysis @return array<string,mixed> */
  private function compactAnalysis(array $analysis): array
  {
    return [
      'resumen' => $this->text($analysis['resumen'] ?? '', 1200),
      'cliente' => $this->text($analysis['cliente'] ?? '', 700),
      'estado_actual' => $this->text($analysis['estado_actual'] ?? '', 700),
      'riesgos' => $this->list($analysis['riesgos'] ?? []),
      'oportunidades' => $this->list($analysis['oportunidades'] ?? []),
      'recomendaciones' => $this->list($analysis['recomendaciones'] ?? []),
      'proximos_pasos' => $this->list($analysis['proximos_pasos'] ?? []),
      'mensaje_sugerido' => $this->text($analysis['mensaje_sugerido'] ?? '', 1400),
      'datos_faltantes' => $this->list($analysis['datos_faltantes'] ?? []),
      'model' => $this->text($analysis['model'] ?? '', 80),
      'generated_at' => $this->text($analysis['generated_at'] ?? date('d/m/Y h:i a'), 40),
    ];
  }

  /** @param mixed $value */
  private function text($value, int $limit): string
  {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
  }

  /** @param mixed $value @return array<int,string> */
  private function list($value): array
  {
    if (!is_array($value)) {
      $value = [$value];
    }
    $items = [];
    foreach ($value as $item) {
      if (is_array($item)) {
        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $item = is_string($encoded) ? $encoded : '';
      }
      $text = $this->text($item, 240);
      if ($text !== '') {
        $items[] = $text;
      }
    }
    return array_slice(array_values(array_unique($items)), 0, 6);
  }

  private function ensureTable(): void
  {
    if ($this->tableReady) {
      return;
    }

    $this->db->pdo()->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->table}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ticket_pk` BIGINT UNSIGNED NOT NULL,
        `ticket_id` VARCHAR(64) NOT NULL DEFAULT '',
        `created_at` INT UNSIGNED NOT NULL,
        `created_date` DATE NOT NULL,
        `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_by_employee_id` VARCHAR(64) NOT NULL DEFAULT '',
        `created_by_name` VARCHAR(160) NOT NULL DEFAULT '',
        `model` VARCHAR(80) NOT NULL DEFAULT '',
        `summary` VARCHAR(700) NOT NULL DEFAULT '',
        `payload` MEDIUMTEXT NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ticket_day` (`ticket_pk`, `created_date`),
        KEY `ticket_created` (`ticket_pk`, `created_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $this->tableReady = true;
  }
}
