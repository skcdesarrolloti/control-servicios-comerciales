<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

use SCM\Commercial\Promotion\Pop\PopFiles;
use SCM\Core\Database;
use SCM\Precaptacion\Repository as ColumnsReader;

/**
 * Promoción › Fichas de funcionarios: la imagen de presentación de cada funcionario, guardada como dirección en la columna
 * `ficha` de wp_jet_cct_funcionarios. Va en el encabezado del WhatsApp con el que el cliente conoce a su asesor
 * (plantilla scm_tarea_comercial_registrada_v1). Las imágenes se guardan con el almacenamiento del panel (enlace firmado
 * sin vencimiento, que Meta puede descargar). Al reemplazar o quitar no se borra el archivo anterior: un WhatsApp que aún
 * está en cola lo sigue necesitando.
 */
final class StaffCardService
{
  public const COLUMN = 'ficha';
  public const FIELD = 'ficha';

  private Database $db;
  private PopFiles $files;

  public function __construct(Database $db, ?PopFiles $files = null)
  {
    $this->db = $db;
    $this->files = $files ?? new PopFiles();
  }

  private function table(): string
  {
    return $this->db->table('jet_cct_funcionarios');
  }

  /** ¿Existe la columna ficha? */
  public function available(): bool
  {
    return in_array(self::COLUMN, (new ColumnsReader($this->db))->columns('jet_cct_funcionarios'), true);
  }

  /** @return list<array{id:string,nombre:string,correo:string,ficha:string}> Funcionarios activos. */
  public function employees(): array
  {
    $column = $this->available() ? '`' . self::COLUMN . '`' : "''";
    $out = [];
    foreach ($this->db->getResults('SELECT `id_empleado`, `nombre`, `correo`, ' . $column . " AS ficha FROM `" . $this->table() . "` WHERE `activo` = 'Si' ORDER BY `nombre` ASC") as $row) {
      $id = trim((string) ($row['id_empleado'] ?? ''));
      if ($id !== '') {
        $out[] = ['id' => $id, 'nombre' => trim((string) ($row['nombre'] ?? '')), 'correo' => trim((string) ($row['correo'] ?? '')), 'ficha' => trim((string) ($row['ficha'] ?? ''))];
      }
    }
    return $out;
  }

  /** Guarda la imagen que llegó en el campo `ficha` como ficha del funcionario y devuelve su dirección. */
  public function save(string $employee): string
  {
    $this->requireEmployee($employee);
    if ($this->files->count(self::FIELD) !== 1) {
      throw new \InvalidArgumentException('Elige la imagen de la ficha (JPG, PNG o WebP).');
    }
    $urls = $this->files->store(self::FIELD, 1);
    $this->db->update($this->table(), [self::COLUMN => $urls[0], 'cct_modified' => date('Y-m-d H:i:s')], ['id_empleado' => $employee]);
    return $urls[0];
  }

  public function remove(string $employee): void
  {
    $this->requireEmployee($employee);
    $this->db->update($this->table(), [self::COLUMN => '', 'cct_modified' => date('Y-m-d H:i:s')], ['id_empleado' => $employee]);
  }

  /** Dirección de la ficha de un funcionario, o vacío si no tiene o la columna no existe. */
  public function cardOf(string $employee): string
  {
    if ($employee === '' || !$this->available()) {
      return '';
    }
    return trim((string) $this->db->getVar('SELECT `' . self::COLUMN . '` FROM `' . $this->table() . '` WHERE `id_empleado` = ? LIMIT 1', [$employee]));
  }

  private function requireEmployee(string $employee): void
  {
    if (!$this->available()) {
      throw new \InvalidArgumentException('Falta la columna ficha en la tabla de funcionarios.');
    }
    $active = $this->db->getVar("SELECT `activo` FROM `" . $this->table() . "` WHERE `id_empleado` = ? LIMIT 1", [$employee]);
    if (trim($employee) === '' || $active !== 'Si') {
      throw new \InvalidArgumentException('Selecciona un funcionario activo.');
    }
  }
}
