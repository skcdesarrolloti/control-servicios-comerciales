<?php

declare(strict_types=1);

namespace SCM\Commercial\Campaigns;

use SCM\Core\Database;

/**
 * Notificaciones › Plantillas de correo: los correos diseñados de las campañas en jet_cct_plantillas (la tabla del
 * gestor de plantillas de dashboard-marketing, app/TemplateRepository.php). Solo las de tipo email; las filas de
 * WhatsApp son los nombres de plantillas aprobadas en Meta y no se editan aquí.
 * Archivar deja cct_status = 'trash' (Notificaciones ya las ignora) y se puede deshacer; nada se borra.
 * cct_author_id guarda el id_empleado de quien la creó o la editó por última vez.
 */
final class CampaignTemplates
{
  public const MAX_HTML = 300000;

  private Database $db;
  private string $table;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->table = $db->table('jet_cct_plantillas');
  }

  /** @return list<array{id:int,nombre:string,asunto:string,estado:string,modificada:string,documento:bool,tamano:int}> */
  public function list(bool $withArchived = false): array
  {
    $rows = $this->db->getResults("SELECT _ID, nombre, asunto, cct_status, cct_modified, contenido FROM `{$this->table}`
      WHERE LOWER(TRIM(COALESCE(tipo, 'email'))) = 'email'" . ($withArchived ? '' : " AND LOWER(COALESCE(cct_status, 'publish')) <> 'trash'") . ' ORDER BY _ID DESC');
    return array_map(static fn(array $row): array => [
      'id' => (int) $row['_ID'], 'nombre' => (string) $row['nombre'], 'asunto' => (string) ($row['asunto'] ?? ''),
      'estado' => strtolower((string) ($row['cct_status'] ?? '')) === 'trash' ? 'archivada' : 'activa', 'modificada' => (string) ($row['cct_modified'] ?? ''),
      'documento' => CampaignMessage::isDocument((string) ($row['contenido'] ?? '')), 'tamano' => strlen((string) ($row['contenido'] ?? '')),
    ], $rows);
  }

  /** @return array{id:int,nombre:string,asunto:string,contenido:string,estado:string}|null */
  public function find(int $id): ?array
  {
    $row = $this->db->getRow("SELECT _ID, nombre, asunto, contenido, cct_status, tipo FROM `{$this->table}` WHERE _ID = ? LIMIT 1", [$id]);
    if ($row === null || strtolower(trim((string) ($row['tipo'] ?? 'email'))) !== 'email') {
      return null;
    }
    return ['id' => (int) $row['_ID'], 'nombre' => (string) $row['nombre'], 'asunto' => (string) ($row['asunto'] ?? ''), 'contenido' => (string) ($row['contenido'] ?? ''),
      'estado' => strtolower((string) ($row['cct_status'] ?? '')) === 'trash' ? 'archivada' : 'activa'];
  }

  /** @param array<string,mixed> $input id, nombre, asunto, contenido */
  public function save(array $input, string $employee): int
  {
    $id = max(0, (int) ($input['id'] ?? 0));
    $name = preg_replace('/\s+/u', ' ', trim((string) ($input['nombre'] ?? ''))) ?? '';
    $subject = trim((string) ($input['asunto'] ?? ''));
    $html = trim((string) ($input['contenido'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
      throw new \InvalidArgumentException('Escribe el nombre de la plantilla (máximo 150 caracteres).');
    }
    if ($subject === '' || mb_strlen($subject, 'UTF-8') > 200) {
      throw new \InvalidArgumentException('Escribe el asunto (máximo 200 caracteres).');
    }
    if ($html === '' || strlen($html) > self::MAX_HTML) {
      throw new \InvalidArgumentException('Pega el HTML del correo (máximo 300 KB).');
    }
    $html = CampaignMessage::sanitize($html);
    $duplicate = $this->db->getVar("SELECT _ID FROM `{$this->table}` WHERE LOWER(nombre) = LOWER(?) AND _ID <> ? AND LOWER(TRIM(COALESCE(tipo, 'email'))) = 'email' LIMIT 1", [$name, $id]);
    if ($duplicate) {
      throw new \InvalidArgumentException('Ya existe otra plantilla con ese nombre.');
    }
    $now = date('Y-m-d H:i:s');
    $data = ['nombre' => $name, 'asunto' => $subject, 'contenido' => $html, 'cct_author_id' => (int) $employee, 'cct_modified' => $now];
    if ($id > 0) {
      if ($this->find($id) === null) {
        throw new \InvalidArgumentException('La plantilla no existe.');
      }
      $this->db->update($this->table, $data, ['_ID' => $id]);
      return $id;
    }
    $this->db->insert($this->table, $data + ['tipo' => 'email', 'cct_status' => 'publish', 'cct_created' => $now]);
    return (int) $this->db->lastInsertId();
  }

  public function duplicate(int $id, string $employee): int
  {
    $template = $this->find($id);
    if ($template === null) {
      throw new \InvalidArgumentException('La plantilla no existe.');
    }
    $base = mb_substr($template['nombre'], 0, 130, 'UTF-8') . ' (copia)';
    $name = $base;
    for ($n = 2; $this->db->getVar("SELECT 1 FROM `{$this->table}` WHERE LOWER(nombre) = LOWER(?) LIMIT 1", [$name]); $n++) {
      $name = $base . ' ' . $n;
    }
    return $this->save(['nombre' => $name, 'asunto' => $template['asunto'], 'contenido' => $template['contenido']], $employee);
  }

  public function setArchived(int $id, bool $archived, string $employee): void
  {
    if ($this->find($id) === null) {
      throw new \InvalidArgumentException('La plantilla no existe.');
    }
    $this->db->update($this->table, ['cct_status' => $archived ? 'trash' : 'publish', 'cct_author_id' => (int) $employee, 'cct_modified' => date('Y-m-d H:i:s')], ['_ID' => $id]);
  }
}
