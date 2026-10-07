<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use SCM\Core\Auth;
use SCM\Core\Database;

final class Workflow
{
    private array $processes = [];
    public const LABELS = ['por_llamar'=>'Por llamar','no_contesto'=>'No contestó','contactado'=>'Contactado',
        'seguimiento'=>'En seguimiento','pendiente_tarea'=>'Pendiente de crear tarea','cerrada'=>'Cerrada','convertida'=>'Convertida a tarea'];
    public const REASONS = ['Sin contacto: 3 intentos agotados','No interesado','Teléfono inválido','Duplicada','Sin información','Otro'];

    public function __construct(private Database $db, private ManagementHistory $history)
    {
        $table = $this->table();
        $mysql = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
            precaptacion_id BIGINT PRIMARY KEY, responsible_id VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL, cycle INTEGER NOT NULL DEFAULT 1,
            attempts INTEGER NOT NULL DEFAULT 0, unanswered INTEGER NOT NULL DEFAULT 0,
            closure_reason TEXT NULL, task_id BIGINT NULL, updated_at VARCHAR(19) NOT NULL
        )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    }

    public function table(): string { return $this->db->table('scm_precaptacion_proceso'); }
    public function get(int $id): ?array
    {
        if (!array_key_exists($id, $this->processes)) $this->processes[$id] = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE precaptacion_id = ?', [$id]);
        return $this->processes[$id];
    }
    public function responsible(array $row): string { return (string) ($this->get((int) ($row['_ID'] ?? 0))['responsible_id'] ?? $row['id_empleado'] ?? $row['cct_author_id'] ?? ''); }
    public function expression(string $table, string $field): string
    {
        if (!in_array($field, ['status','responsible_id'], true)) throw new \InvalidArgumentException('Campo de proceso inválido.');
        return '(SELECT p.`' . $field . '` FROM `' . $this->table() . '` p WHERE p.precaptacion_id = `' . $table . '`._ID)';
    }

    public function ensure(array $row): array
    {
        $id = (int) $row['_ID'];
        $process = $this->get($id);
        if ($process) return $process;
        $process = ['precaptacion_id'=>$id,'responsible_id'=>(string) ($row['id_empleado'] ?? $row['cct_author_id'] ?? ''),
            'status'=>$this->status($row),'cycle'=>1,'attempts'=>0,'unanswered'=>0,'closure_reason'=>null,'task_id'=>null,'updated_at'=>date('Y-m-d H:i:s')];
        $this->db->insert($this->table(), $process);
        $this->processes[$id] = $process;
        return $process;
    }

    public function apply(array $row, array $input): array
    {
        $p = $this->ensure($row);
        if (in_array($p['status'], ['cerrada','convertida'], true)) throw new \InvalidArgumentException('El registro está cerrado. Un administrador debe reabrirlo antes de registrar llamadas.');
        $event = (string) ($input['tipo_gestion'] ?? '');
        if (!in_array($event, ['llamada','administrativa'], true)) throw new \InvalidArgumentException('Indica si realizaste una llamada o una gestión administrativa.');
        if ($event === 'administrativa' && ($input['resultado_contacto'] ?? '') !== 'por_llamar') throw new \InvalidArgumentException('Una gestión administrativa no puede confirmar una llamada.');
        $outcome = (string) ($input['resultado_contacto'] ?? '');
        if ($event === 'llamada' && $outcome === 'por_llamar') throw new \InvalidArgumentException('Selecciona el resultado de la llamada realizada.');
        if (trim((string) ($input['resultado'] ?? '')) === '') throw new \InvalidArgumentException('Describe el resultado de la gestión.');
        // Only actual calls consume attempts. The counter restarts after an answered call.
        $p['attempts'] = (int) $p['attempts'] + ($event === 'llamada' ? 1 : 0);
        $p['unanswered'] = $event === 'llamada' ? ($outcome === 'no_contesto' ? (int) $p['unanswered'] + 1 : 0) : (int) $p['unanswered'];
        $third = $event === 'llamada' && $outcome === 'no_contesto' && $p['unanswered'] >= 3;
        if ($third) {
            $input['merece_ticket'] = 'No'; $input['proximo_contacto'] = '';
        } elseif ($event === 'llamada' && $outcome === 'no_contesto') {
            $input['merece_ticket'] = 'Seguir llamando';
        }
        $data = $event === 'administrativa' ? ['outcome'=>'por_llamar','state'=>$p['status'],'merit'=>'','next_contact_at'=>null] : $this->history->validate($input);
        if ($event === 'administrativa') {
            // Editing contact information preserves the current call result and appointment.
            $latest = $this->history->latest((int) $row['_ID']);
            if ($latest) { $data['outcome'] = $latest['outcome']; $data['next_contact_at'] = $latest['next_contact_at']; $data['merit'] = $latest['merit']; }
            $data['state'] = $p['status'];
        } elseif ($third) {
            $data['state'] = 'cerrada'; $p['closure_reason'] = self::REASONS[0];
        } elseif ($data['merit'] === 'Si') {
            $data['state'] = 'pendiente_tarea';
        } elseif ($data['merit'] === 'No') {
            $reason = trim((string) ($input['motivo_cierre'] ?? ''));
            if (!in_array($reason, self::REASONS, true) || $reason === self::REASONS[0]) throw new \InvalidArgumentException('Selecciona el motivo de cierre.');
            $p['closure_reason'] = $reason; $data['state'] = 'cerrada';
        }
        $p['status'] = $data['state'];
        $this->save($p);
        $this->history->record((int) $row['_ID'], $data + ['event_type'=>$event,'cycle'=>(int) $p['cycle'],'attempt_number'=>(int) $p['unanswered']], $input);
        return $data;
    }

    public function administrative(array $row, string $action, string $reason, ?string $responsible = null): void
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('Indica el motivo de la acción.');
        $p = $this->ensure($row);
        if ($p['status'] === 'convertida') throw new \InvalidArgumentException('Gestiona el registro desde su tarea vinculada.');
        if ($action === 'reabrir') {
            if ($p['status'] !== 'cerrada') throw new \InvalidArgumentException('Solo puedes reabrir un registro cerrado.');
            $p['cycle'] = (int) $p['cycle'] + 1; $p['attempts'] = 0; $p['unanswered'] = 0;
            $p['status'] = 'por_llamar'; $p['closure_reason'] = null;
        } elseif ($action === 'asignar') {
            $p['responsible_id'] = $responsible;
        } else {
            $p['status'] = 'cerrada'; $p['closure_reason'] = $reason;
        }
        $latest = $this->history->latest((int) $row['_ID']);
        $this->save($p);
        $this->history->record((int) $row['_ID'], ['outcome'=>$action === 'reabrir' ? 'por_llamar' : ($latest['outcome'] ?? 'por_llamar'),
            'state'=>$p['status'],'merit'=>$action === 'reabrir' ? '' : ($latest['merit'] ?? ''),
            'next_contact_at'=>$action === 'asignar' && $p['status'] !== 'cerrada' ? ($latest['next_contact_at'] ?? null) : null,
            'event_type'=>$action,'cycle'=>(int) $p['cycle'],'attempt_number'=>(int) $p['unanswered']], ['resultado'=>$reason]);
    }

    public function converted(array $row, int $taskId): void
    {
        $p = $this->ensure($row); $p['status'] = 'convertida'; $p['task_id'] = $taskId;
        $this->save($p);
    }

    private function save(array $p): void
    {
        $id = $p['precaptacion_id']; unset($p['precaptacion_id']); $p['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update($this->table(), $p, ['precaptacion_id'=>$id]);
        unset($this->processes[$id]);
    }

    /** Historical responses classify the inbox but never manufacture attempt counts. */
    public function status(array $row): string
    {
        $normalize = static fn($value): string => strtr(mb_strtolower(trim((string) $value)), ['í'=>'i','ó'=>'o']);
        if (!empty($row['linked_task']) || in_array($normalize($row['tiene_ticket'] ?? ''), ['si','1','true'], true)
            || in_array($normalize($row['razones'] ?? ''), ['ticket creado','tarea creada'], true)) return 'convertida';
        foreach (['id_ticket_asignado','ticket_asignado','id_ticket','ticket','numero_de_ticket','url_ticket','url_ticket_precap'] as $field) {
            if (!in_array(trim((string) ($row[$field] ?? '')), ['', '0'], true)) return 'convertida';
        }
        if (!empty($row['process_status'])) return $row['process_status'];
        $history = array_key_exists('confirmed_state', $row) ? $row['confirmed_state'] : ($this->history->latest((int) ($row['_ID'] ?? 0))['state'] ?? null);
        if ($history) return $history === 'contactado' && $normalize($row['merece_ticket'] ?? '') === 'si' ? 'pendiente_tarea' : $history;
        foreach (['resultado','razones'] as $field) {
            if (preg_match('/^(no contest|no respon|buzon|sin respuesta)/', $normalize($row[$field] ?? ''))) return 'no_contesto';
        }
        $contacted = in_array($normalize($row['contactado'] ?? ''), ['si','1'], true);
        if (trim((string) ($row['resultado'] ?? '')) === '' && trim((string) ($row['razones'] ?? '')) === '' && !$contacted
            && preg_match('/^(no contest|no respon|buzon|sin respuesta)/', $normalize($row['observaciones'] ?? ''))) return 'no_contesto';
        if ($normalize($row['merece_ticket'] ?? '') === 'si') return 'pendiente_tarea';
        if ($normalize($row['merece_ticket'] ?? '') === 'seguir llamando') return 'seguimiento';
        if ($contacted) return 'contactado';
        foreach (['resultado','razones','seguimiento'] as $field) {
            if (!in_array($normalize($row[$field] ?? ''), ['', 'no','0'], true)) return 'seguimiento';
        }
        return 'por_llamar';
    }

    public function summary(string $employee = ''): array
    {
        $table = $this->db->table('jet_cct_precaptaciones');
        $adapter = new DatabaseAdapter($this->db);
        if (!$adapter->get_var($adapter->prepare('SHOW TABLES LIKE %s', $table))) return ['available'=>false];
        $scope = $employee !== '' ? ' WHERE COALESCE(NULLIF(w.responsible_id,\'\'), p.id_empleado) = ?' : '';
        $columns = $adapter->get_col('DESCRIBE ' . $table);
        $ids = [];
        foreach (['id_ticket_asignado','ticket_asignado','id_ticket','ticket','numero_de_ticket'] as $field) if (in_array($field, $columns, true)) $ids[] = "NULLIF(NULLIF(p.`{$field}`, ''), '0')";
        $taskId = 'COALESCE(' . implode(', ', array_merge(['w.task_id'], $ids, ['0'])) . ')';
        $rows = $this->db->getResults('SELECT p.*, w.status AS process_status, w.responsible_id, w.unanswered, g.state AS confirmed_state, g.next_contact_at, t._ID AS linked_task FROM `' . $table . '` p LEFT JOIN `' . $this->table() . '` w ON w.precaptacion_id = p._ID LEFT JOIN `' . $this->history->table() . '` g ON g.id = (SELECT MAX(h.id) FROM `' . $this->history->table() . '` h WHERE h.precaptacion_id = p._ID) LEFT JOIN `' . $this->db->table('jet_cct_tickets') . '` t ON t._ID = ' . $taskId . $scope, $employee !== '' ? [$employee] : []);
        $names = [];
        foreach ($this->db->getResults('SELECT id_empleado, nombre FROM `' . $this->db->table('jet_cct_funcionarios') . '`') as $actor) $names[(string) $actor['id_empleado']] = (string) $actor['nombre'];
        $s = array_fill_keys(['total','por_llamar','sin_respuesta','vencidas','hoy','por_vencer','seguimiento','pendiente_tarea','cerradas','convertidas'], 0);
        $s['available'] = true; $s['scope'] = $employee === '' ? 'global' : 'personal';
        $s['items'] = []; $s['advisors'] = []; $s['pendientes'] = 0;
        foreach ($rows as $row) {
            $s['total']++;
            $status = $this->status($row);
            if ($status === 'cerrada') { $s['cerradas']++; continue; }
            if ($status === 'convertida') { $s['convertidas']++; continue; }
            $kind = ['por_llamar'=>'por_llamar','no_contesto'=>'sin_respuesta','seguimiento'=>'seguimiento','pendiente_tarea'=>'pendiente_tarea'][$status] ?? '';
            if ($kind) $s[$kind]++;
            $next = (string) ($row['next_contact_at'] ?? '');
            $due = '';
            if ($next !== '') {
                if ($next <= date('Y-m-d H:i:s')) { $s['vencidas']++; $due = 'vencidas'; }
                else {
                    if (substr($next, 0, 10) === date('Y-m-d')) { $s['hoy']++; $due = 'hoy'; }
                    if (strtotime($next) <= time() + 86400) { $s['por_vencer']++; $due = $due ?: 'por_vencer'; }
                }
            }
            if ($kind || $next !== '') {
                $s['pendientes']++;
                $owner = (string) ($row['responsible_id'] ?: $row['id_empleado']);
                $name = $names[$owner] ?? $owner;
                $s['advisors'][$owner] ??= ['name'=>$name,'pending'=>0,'overdue'=>0];
                $s['advisors'][$owner]['pending']++; $s['advisors'][$owner]['overdue'] += $due === 'vencidas' ? 1 : 0;
                $s['items'][] = ['id'=>(int) $row['_ID'],'contact'=>(string) ($row['contacto'] ?? ''),'phone'=>(string) ($row['celular'] ?? ''),'status'=>self::LABELS[$status] ?? $status,'next'=>$next,'owner'=>$name,'overdue'=>$due === 'vencidas'];
            }
        }
        usort($s['items'], static fn(array $a, array $b): int => [$b['overdue'], $a['next'] ?: '9999'] <=> [$a['overdue'], $b['next'] ?: '9999']);
        $s['items'] = array_slice($s['items'], 0, 10);
        return $s;
    }

    public function task(array $row): ?array
    {
        $id = (int) ($this->get((int) $row['_ID'])['task_id'] ?? 0);
        foreach (['id_ticket_asignado','ticket_asignado','id_ticket','ticket','numero_de_ticket'] as $field) if (!$id) $id = (int) ($row[$field] ?? 0);
        return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE _ID = ?', [$id]) : null;
    }
}
