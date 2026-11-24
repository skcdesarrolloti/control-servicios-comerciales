<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Core\Database;

/**
 * Contratos de mandato (wp_jet_cct_contrato_mandato, los crean y envían a firma los formularios de WordPress mientras se
 * migran) y su revisión por el propietario antes de la firma, en dos tablas propias del panel:
 * - scm_mandato_revision: un registro por mandato (envíos al propietario, visto, aprobación con la huella del contenido);
 * - scm_mandato_observaciones: lo que el propietario pide cambiar, por sección, y la respuesta del equipo.
 */
final class CommercialMandatesRepository
{
  public const STATES = ['No enviado', 'Enviado', 'Firmado'];

  private Database $db;
  private bool $mysql;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->mysql = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $this->install();
  }

  private function install(): void
  {
    $pk = $this->mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engine = $this->mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $this->reviewTable() . "` (mandato_id BIGINT NOT NULL PRIMARY KEY, envios INT NOT NULL DEFAULT 0, enviado_at VARCHAR(19) NOT NULL DEFAULT '', enviado_por VARCHAR(40) NOT NULL DEFAULT '', enviado_por_nombre VARCHAR(190) NOT NULL DEFAULT '', huella_envio CHAR(64) NOT NULL DEFAULT '', visto_at VARCHAR(19) NOT NULL DEFAULT '', aprobado_at VARCHAR(19) NOT NULL DEFAULT '', aprobado_por VARCHAR(190) NOT NULL DEFAULT '', huella_aprobado CHAR(64) NOT NULL DEFAULT '')" . $engine);
    $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $this->notesTable() . "` (id {$pk}, mandato_id BIGINT NOT NULL, seccion VARCHAR(30) NOT NULL, nombre VARCHAR(190) NOT NULL, mensaje TEXT NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'pendiente', respuesta TEXT NULL, respondido_por VARCHAR(190) NOT NULL DEFAULT '', respondido_id_empleado VARCHAR(40) NOT NULL DEFAULT '', respondido_at VARCHAR(19) NOT NULL DEFAULT '', huella CHAR(64) NOT NULL DEFAULT '', created_at VARCHAR(19) NOT NULL"
      . ($this->mysql ? ', KEY mandato_id (mandato_id))' : ')') . $engine);
  }

  private function reviewTable(): string
  {
    return $this->db->table('scm_mandato_revision');
  }

  private function notesTable(): string
  {
    return $this->db->table('scm_mandato_observaciones');
  }

  private function mandates(): string
  {
    return $this->db->table('jet_cct_contrato_mandato');
  }

  /** @return array<string,mixed>|null */
  public function find(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->mandates() . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /** @return array<string,mixed> */
  public function review(int $id): array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->reviewTable() . '` WHERE mandato_id = ? LIMIT 1', [$id]) ?? [];
  }

  /** @param array<string,mixed> $data */
  private function saveReview(int $id, array $data): void
  {
    if ($this->review($id) === []) {
      $this->db->insert($this->reviewTable(), ['mandato_id' => $id] + $data);
    } else {
      $this->db->update($this->reviewTable(), $data, ['mandato_id' => $id]);
    }
  }

  public function markSent(int $id, string $employeeId, string $employeeName, string $fingerprint): void
  {
    $this->saveReview($id, [
      'envios' => (int) ($this->review($id)['envios'] ?? 0) + 1,
      'enviado_at' => date('Y-m-d H:i:s'), 'enviado_por' => $employeeId, 'enviado_por_nombre' => $employeeName, 'huella_envio' => $fingerprint,
    ]);
  }

  public function markSeen(int $id): void
  {
    $review = $this->review($id);
    if ($review !== [] && trim((string) ($review['visto_at'] ?? '')) === '') {
      $this->db->update($this->reviewTable(), ['visto_at' => date('Y-m-d H:i:s')], ['mandato_id' => $id]);
    }
  }

  public function approve(int $id, string $name, string $fingerprint): void
  {
    $this->saveReview($id, ['aprobado_at' => date('Y-m-d H:i:s'), 'aprobado_por' => $name, 'huella_aprobado' => $fingerprint]);
  }

  /** @return list<array<string,mixed>> */
  public function notes(int $id): array
  {
    return $this->db->getResults('SELECT * FROM `' . $this->notesTable() . '` WHERE mandato_id = ? ORDER BY id ASC', [$id]);
  }

  /** @return array<string,mixed>|null */
  public function note(int $noteId): ?array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->notesTable() . '` WHERE id = ? LIMIT 1', [$noteId]);
  }

  public function addNote(int $id, string $section, string $name, string $message, string $fingerprint): int
  {
    $this->db->insert($this->notesTable(), [
      'mandato_id' => $id, 'seccion' => $section, 'nombre' => $name, 'mensaje' => $message, 'estado' => 'pendiente',
      'respuesta' => '', 'huella' => $fingerprint, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    return (int) $this->db->lastInsertId();
  }

  public function answerNote(int $noteId, string $answer, bool $resolve, string $employeeId, string $employeeName): void
  {
    $data = ['respuesta' => $answer, 'respondido_por' => $employeeName, 'respondido_id_empleado' => $employeeId, 'respondido_at' => date('Y-m-d H:i:s')];
    if ($resolve) {
      $data['estado'] = 'resuelta';
    }
    $this->db->update($this->notesTable(), $data, ['id' => $noteId]);
  }

  /**
   * Mandatos para la pestaña Mandatos / Mis mandatos, con su revisión y cuántas observaciones siguen pendientes.
   * @param array{q:string,estado:string,empleado:string} $filters
   * @return array{rows:list<array<string,mixed>>,total:int}
   */
  public function search(array $filters, ?string $employeeId, int $page, int $perPage): array
  {
    [$where, $args] = $this->where($filters, $employeeId);
    $sql = ' FROM `' . $this->mandates() . '` m LEFT JOIN `' . $this->reviewTable() . '` r ON r.mandato_id = m.`_ID` WHERE ' . implode(' AND ', $where);
    $total = (int) $this->db->getVar('SELECT COUNT(*)' . $sql, $args);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = $this->db->getResults('SELECT m.*, r.envios AS rev_envios, r.enviado_at AS rev_enviado_at, r.visto_at AS rev_visto_at, r.aprobado_at AS rev_aprobado_at, r.aprobado_por AS rev_aprobado_por, r.huella_aprobado AS rev_huella_aprobado,'
      . ' (SELECT COUNT(*) FROM `' . $this->notesTable() . "` o WHERE o.mandato_id = m.`_ID` AND o.estado = 'pendiente') AS rev_pendientes"
      . $sql . ' ORDER BY m.`_ID` DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $args);
    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * Conteo por estado del mandato con los mismos filtros, salvo el estado.
   * @param array{q:string,estado:string,empleado:string} $filters
   * @return array<string,int>
   */
  public function stateCounts(array $filters, ?string $employeeId): array
  {
    $filters['estado'] = '';
    [$where, $args] = $this->where($filters, $employeeId);
    $counts = [];
    foreach ($this->db->getResults('SELECT TRIM(COALESCE(m.estado, \'\')) AS estado, COUNT(*) AS total FROM `' . $this->mandates() . '` m WHERE ' . implode(' AND ', $where) . ' GROUP BY TRIM(COALESCE(m.estado, \'\'))', $args) as $row) {
      $counts[(string) $row['estado']] = (int) $row['total'];
    }
    return $counts;
  }

  /**
   * @param array{q:string,estado:string,empleado:string} $filters
   * @return array{0:list<string>,1:list<string>}
   */
  private function where(array $filters, ?string $employeeId): array
  {
    $where = ['1 = 1'];
    $args = [];
    if ($employeeId !== null) {
      // Lo de una persona: exacto por su id_empleado; si no se sabe quién es, nada.
      $where[] = $employeeId === '' ? '1 = 0' : 'TRIM(m.id_empleado) = ?';
      if ($employeeId !== '') {
        $args[] = $employeeId;
      }
    } elseif ($filters['empleado'] !== '') {
      $where[] = 'TRIM(m.id_empleado) = ?';
      $args[] = $filters['empleado'];
    }
    if ($filters['estado'] !== '') {
      $where[] = 'TRIM(m.estado) = ?';
      $args[] = $filters['estado'];
    }
    $q = trim($filters['q']);
    if ($q !== '') {
      $like = '%' . $q . '%';
      $parts = ['CAST(m.`_ID` AS CHAR) = ?', 'm.id_inmueble = ?'];
      $args[] = ltrim($q, '#');
      $args[] = ltrim($q, '#');
      foreach (['nombre_1', 'nombre_2', 'documento_1', 'documento_2', 'direccion', 'barrio', 'creador'] as $column) {
        $parts[] = 'm.' . $column . ' LIKE ?';
        $args[] = $like;
      }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    return [$where, $args];
  }

  /**
   * Mandatos de una tarea: los creados desde ella (id_ticket, si la columna existe), los de sus estudios de aseguradora
   * y los de su inmueble. El más reciente primero.
   * @param list<int> $studies
   * @return list<array<string,mixed>>
   */
  public function forTask(int $ticket, array $studies, string $property): array
  {
    $where = [];
    $args = [];
    if ($ticket > 0 && (new \SCM\Support\SchemaInspector($this->db))->columnExists($this->mandates(), 'id_ticket')) {
      $where[] = 'CAST(m.id_ticket AS CHAR) = ?';
      $args[] = (string) $ticket;
    }
    $studies = array_values(array_filter(array_map('intval', $studies), static fn(int $id): bool => $id > 0));
    if ($studies !== []) {
      $where[] = 'CAST(m.id_estudio_aseguradora AS CHAR) IN (' . implode(',', array_fill(0, count($studies), '?')) . ')';
      array_push($args, ...array_map('strval', $studies));
    }
    if (trim($property) !== '') {
      $where[] = 'TRIM(m.id_inmueble) = ?';
      $args[] = trim($property);
    }
    if ($where === []) {
      return [];
    }
    return $this->db->getResults('SELECT m.*, r.envios AS rev_envios, r.visto_at AS rev_visto_at, r.aprobado_at AS rev_aprobado_at, r.huella_aprobado AS rev_huella_aprobado,'
      . ' (SELECT COUNT(*) FROM `' . $this->notesTable() . "` o WHERE o.mandato_id = m.`_ID` AND o.estado = 'pendiente') AS rev_pendientes"
      . ' FROM `' . $this->mandates() . '` m LEFT JOIN `' . $this->reviewTable() . '` r ON r.mandato_id = m.`_ID` WHERE ' . implode(' OR ', $where) . ' ORDER BY m.`_ID` DESC LIMIT 20', $args);
  }

  /** @return array<string,string> id_empleado => nombre de quien creó mandatos */
  public function creators(): array
  {
    $out = [];
    foreach ($this->db->getResults("SELECT TRIM(id_empleado) AS id, MAX(TRIM(creador)) AS nombre FROM `" . $this->mandates() . "` WHERE TRIM(COALESCE(id_empleado, '')) <> '' GROUP BY TRIM(id_empleado) ORDER BY nombre ASC") as $row) {
      $out[(string) $row['id']] = trim((string) $row['nombre']) !== '' ? (string) $row['nombre'] : 'Funcionario #' . $row['id'];
    }
    return $out;
  }

  /**
   * Preferencias de contacto del propietario (bloqueos de correo y WhatsApp de su ficha).
   * @return array<string,mixed>
   */
  public function owner(string $ownerId): array
  {
    return ctype_digit($ownerId) && (int) $ownerId > 0 ? ($this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE `_ID` = ? LIMIT 1', [(int) $ownerId]) ?? []) : [];
  }

  /** @return array{nombre:string,correo:string,celular:string} Quien creó el mandato (como mandato_funcionario_creador). */
  public function creator(array $mandate): array
  {
    $out = ['nombre' => trim((string) ($mandate['creador'] ?? '')), 'correo' => trim((string) ($mandate['email_creador'] ?? '')), 'celular' => trim((string) ($mandate['celular_creador'] ?? ''))];
    $employeeId = trim((string) ($mandate['id_empleado'] ?? ''));
    if ($employeeId !== '' && ($out['nombre'] === '' || $out['correo'] === '' || $out['celular'] === '')) {
      $row = $this->db->getRow('SELECT nombre, correo, celular FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE TRIM(id_empleado) = ? LIMIT 1', [$employeeId]);
      foreach (['nombre', 'correo', 'celular'] as $key) {
        if ($out[$key] === '' && is_array($row)) {
          $out[$key] = trim((string) ($row[$key] ?? ''));
        }
      }
    }
    return $out;
  }
}
