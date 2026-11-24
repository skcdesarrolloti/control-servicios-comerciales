<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Transferir un inmueble a otro funcionario: lo mismo que el formulario JetForm "Transferir inmueble" y su hook
 * transferir-codigo-inmueble (snippet de WordPress), pero desde el panel:
 *  - meta del post del inmueble: id_empleado, empleado, email-empleado y celular-empleado;
 *  - CCT de inmuebles: id_funcionario y funcionario;
 *  - relación JetEngine 941 (funcionario → inmueble) en modo "append", como el formulario;
 *  - historial del inmueble "Traslado".
 * La actualización de panel_inmueble (base panelapi) del hook ya no se usa y no se hace.
 */
final class CommercialPropertyTransfer
{
  public const RELATION = 941;

  private Database $db;
  private SchemaInspector $schema;
  public function __construct(Database $db, ?SchemaInspector $schema = null)
  {
    $this->db = $db;
    $this->schema = $schema ?? new SchemaInspector($db);
  }

  /**
   * Funcionarios activos a quienes se puede transferir, como la lista del formulario (id_empleado | nombre).
   *
   * @return array<int,array{id:string,name:string}>
   */
  public function employees(): array
  {
    $rows = $this->db->getResults("SELECT TRIM(id_empleado) AS id, TRIM(nombre) AS name FROM `" . $this->db->table('jet_cct_funcionarios') . "` WHERE TRIM(COALESCE(id_empleado, '')) <> '' AND TRIM(COALESCE(nombre, '')) <> '' AND COALESCE(activo, 'Si') = 'Si' ORDER BY nombre ASC");
    $seen = [];
    $list = [];
    foreach ($rows as $row) {
      $id = (string) $row['id'];
      if (!isset($seen[$id])) {
        $seen[$id] = true;
        $list[] = ['id' => $id, 'name' => (string) $row['name']];
      }
    }
    return $list;
  }

  /**
   * @param string|null $ownerEmployeeId Si no es null, solo se transfieren los inmuebles de esa persona.
   * @return array{ok:bool,message:string,funcionario?:string}
   */
  public function transfer(string $code, string $targetEmployeeId, string $actorEmployeeId, ?string $ownerEmployeeId = null): array
  {
    $code = trim($code);
    $targetEmployeeId = trim($targetEmployeeId);
    if ($code === '' || $targetEmployeeId === '') {
      return ['ok' => false, 'message' => 'Elige a quién transferir el inmueble.'];
    }
    $inmTable = $this->db->table('jet_cct_inmuebles');
    $property = $this->db->getRow("SELECT `_ID`, `codigo`, `id_funcionario`, `funcionario` FROM `{$inmTable}` WHERE `codigo` = ? OR `_ID` = ? LIMIT 1", [$code, ctype_digit($code) ? (int) $code : 0]);
    if (!is_array($property)) {
      return ['ok' => false, 'message' => 'Inmueble no encontrado.'];
    }
    $current = trim((string) ($property['id_funcionario'] ?? ''));
    if ($ownerEmployeeId !== null && (trim($ownerEmployeeId) === '' || $current !== trim($ownerEmployeeId))) {
      return ['ok' => false, 'message' => 'Solo puedes transferir tus inmuebles.'];
    }
    if ($current === $targetEmployeeId) {
      return ['ok' => false, 'message' => 'El inmueble ya está asignado a esa persona.'];
    }
    $employee = $this->db->getRow('SELECT id_empleado, nombre, correo, celular FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE TRIM(id_empleado) = ? LIMIT 1', [$targetEmployeeId]);
    if (!is_array($employee)) {
      return ['ok' => false, 'message' => 'No se encontró el funcionario elegido.'];
    }
    $name = trim((string) ($employee['nombre'] ?? ''));
    $propertyCode = trim((string) ($property['codigo'] ?? '')) !== '' ? trim((string) $property['codigo']) : (string) $property['_ID'];
    $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
    $nowSql = $now->format('Y-m-d H:i:s');

    $pdo = $this->db->pdo();
    $own = !$pdo->inTransaction();
    if ($own) {
      $pdo->beginTransaction();
    }
    try {
      if (ctype_digit($propertyCode)) {
        foreach (['id_empleado' => $targetEmployeeId, 'empleado' => $name, 'email-empleado' => trim((string) ($employee['correo'] ?? '')), 'celular-empleado' => trim((string) ($employee['celular'] ?? ''))] as $key => $value) {
          $this->setPostMeta((int) $propertyCode, $key, $value);
        }
      }
      $this->db->update($inmTable, ['id_funcionario' => $targetEmployeeId, 'funcionario' => $name, 'cct_modified' => $nowSql], ['_ID' => (int) $property['_ID']]);

      $relation = $this->db->table('jet_rel_' . self::RELATION);
      if ($this->schema->tableExists($relation) && !$this->db->getVar("SELECT _ID FROM `{$relation}` WHERE parent_object_id = ? AND child_object_id = ? LIMIT 1", [$targetEmployeeId, $propertyCode])) {
        $this->db->insert($relation, ['created' => $nowSql, 'rel_id' => self::RELATION, 'parent_rel' => 0, 'parent_object_id' => $targetEmployeeId, 'child_object_id' => $propertyCode]);
      }

      $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), [
        'cct_status' => 'publish',
        'cct_author_id' => $actorEmployeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_empleado' => $targetEmployeeId,
        'id_inmueble' => $propertyCode,
        'fecha' => $now->getTimestamp(),
        'tipo_reporte' => 'Traslado',
        'observacion' => 'Su inmueble ha sido trasladado a ' . $name,
        'funcionario' => $name,
      ]);
      if ($own) {
        $pdo->commit();
      }
    } catch (\Throwable $error) {
      if ($own) {
        $pdo->rollBack();
      }
      throw $error;
    }

    return ['ok' => true, 'message' => 'El inmueble #' . $propertyCode . ' quedó asignado a ' . $name . '.', 'funcionario' => $name];
  }

  private function setPostMeta(int $postId, string $key, string $value): void
  {
    $table = $this->db->table('postmeta');
    $metaId = (int) $this->db->getVar("SELECT meta_id FROM `{$table}` WHERE post_id = ? AND meta_key = ? LIMIT 1", [$postId, $key]);
    if ($metaId > 0) {
      $this->db->update($table, ['meta_value' => $value], ['meta_id' => $metaId]);
    } else {
      $this->db->insert($table, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
    }
  }

}
