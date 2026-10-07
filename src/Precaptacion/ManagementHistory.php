<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use SCM\Core\Auth;
use SCM\Core\Database;

/** Explicit outcomes are kept separately from the shared JetEngine CCT. */
final class ManagementHistory
{
    private array $latest = [];
    public function __construct(private Database $db)
    {
        $table = $this->table();
        $sqlite = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $identity = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $index = $sqlite ? '' : ', INDEX precap_latest (precaptacion_id, id)';
        $db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
            id {$identity}, precaptacion_id BIGINT NOT NULL, employee_id VARCHAR(80) NOT NULL,
            outcome VARCHAR(30) NOT NULL, state VARCHAR(30) NOT NULL, merit VARCHAR(30) NOT NULL,
            reason TEXT NOT NULL, result TEXT NOT NULL, recorded_at VARCHAR(19) NOT NULL,
            next_contact_at VARCHAR(19) NULL {$index}
        )" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
        if ($sqlite) $db->pdo()->exec("CREATE INDEX IF NOT EXISTS precap_latest ON `{$table}` (precaptacion_id, id)");
        $columns = (new DatabaseAdapter($db))->get_col('DESCRIBE ' . $table);
        foreach (['event_type'=>"VARCHAR(30) NOT NULL DEFAULT 'historica'",'cycle'=>'INTEGER NOT NULL DEFAULT 0','attempt_number'=>'INTEGER NOT NULL DEFAULT 0'] as $column => $type) {
            if (!in_array($column, $columns, true)) {
                try { $db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$type}"); }
                catch (\PDOException $exception) {
                    // Another request may have completed the same additive migration.
                    if (!in_array($column, (new DatabaseAdapter($db))->get_col('DESCRIBE ' . $table), true)) throw $exception;
                }
            }
        }
    }

    public function table(): string { return $this->db->table('scm_precaptacion_gestiones'); }

    public function latest(int $id): ?array
    {
        if (!array_key_exists($id, $this->latest)) $this->latest[$id] = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE precaptacion_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        return $this->latest[$id];
    }

    public function entries(int $id): array
    {
        return $this->db->getResults('SELECT * FROM `' . $this->table() . '` WHERE precaptacion_id = ? ORDER BY id DESC', [$id]);
    }

    public function expression(string $sourceTable, string $field): string
    {
        if (!in_array($field, ['state','next_contact_at'], true)) throw new \InvalidArgumentException('Campo de gestión inválido.');
        return '(SELECT g.`' . $field . '` FROM `' . $this->table() . '` g WHERE g.precaptacion_id = `' . $sourceTable . '`._ID ORDER BY g.id DESC LIMIT 1)';
    }

    public function validate(array $input): array
    {
        $outcome = (string) ($input['resultado_contacto'] ?? '');
        if (!in_array($outcome, ['contactado','no_contesto','por_llamar'], true)) throw new \InvalidArgumentException('Selecciona el resultado real del contacto.');
        $merit = (string) ($input['merece_ticket'] ?? '');
        if ($merit === 'Si' && $outcome !== 'contactado') throw new \InvalidArgumentException('Confirma el contacto antes de crear una tarea.');
        $next = trim((string) ($input['proximo_contacto'] ?? ''));
        if ($merit === 'Seguir llamando' && $next === '') throw new \InvalidArgumentException('Programa la próxima llamada para continuar el seguimiento.');
        if ($next !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $next);
            if (!$date || $date->format('Y-m-d\TH:i') !== $next || $date <= new \DateTimeImmutable()) throw new \InvalidArgumentException('La próxima llamada debe tener una fecha y hora futuras.');
            $next = $date->format('Y-m-d H:i:s');
        }
        return ['outcome'=>$outcome,'state'=>$outcome === 'contactado' && $merit === 'Seguir llamando' ? 'seguimiento' : $outcome,
            'merit'=>$merit,'next_contact_at'=>$merit === 'Seguir llamando' ? $next : null];
    }

    public function record(int $id, array $data, array $input): void
    {
        $this->db->insert($this->table(), $data + ['precaptacion_id'=>$id,'employee_id'=>Auth::employeeId(),
            'reason'=>(string) ($input['razones'] ?? ''),'result'=>(string) ($input['resultado'] ?? ''),
            'recorded_at'=>date('Y-m-d H:i:s')]);
        unset($this->latest[$id]);
    }
}
