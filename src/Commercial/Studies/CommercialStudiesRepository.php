<?php

declare(strict_types=1);

namespace SCM\Commercial\Studies;

use SCM\Core\Database;
use SCM\Precaptacion\Repository as ColumnsReader;

/**
 * Tablas del estudio de aseguradora: `jet_cct_estudios_aseguradoras` (la misma de los formularios JetFormBuilder "Agregar
 * estudio de aseguradora" y "Respuesta estudio de aseguradora", que leen el panel inmobiliario, la hoja de cierre y los
 * reportes), las aseguradoras, el caso de entrega y el contrato "No asignado" que crea la aprobación, los inmuebles y
 * los historiales. Solo se escriben las columnas que existen.
 */
final class CommercialStudiesRepository
{
  public const TABLE = 'jet_cct_estudios_aseguradoras';
  /** Inmuebles que se pueden entregar con un estudio aprobado (consulta 1401 del formulario de WordPress). */
  public const DELIVERABLE_STATES = ['Publico', 'En borrador'];
  /** Glosario JetEngine "Razón de desistir": los mismos motivos del panel inmobiliario (LeaseAnnexesService::desistReasons). */
  public const DESIST_GLOSSARY = 1463;
  /** Glosario JetEngine "Servicios públicos" del inmueble. */
  public const UTILITIES_GLOSSARY = 1003;
  public const DESIST_FALLBACK = ['Arrendatario desistio', 'Propietario desistio', 'No aprobado por aseguradora', 'Documentacion incompleta', 'No pago de valores iniciales', 'No hubo acuerdo económico', 'No hubo acuerdo en condiciones del contrato', 'Inmueble no apto para entrega', 'Falta de respuesta del cliente', 'Falta de acuerdo en reparaciones', 'Demora operativa', 'Error operativo / registro duplicado', 'Motivo personal del cliente', 'Cambio de inmueble'];
  public const UTILITIES_FALLBACK = ['Agua', 'Gas', 'Energia'];
  public const DELIVERY_TOPIC = 'Entrega de inmuebles';

  private Database $db;
  private ColumnsReader $columns;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->columns = new ColumnsReader($db);
  }

  public function database(): Database
  {
    return $this->db;
  }

  /** @return array<string,mixed>|null */
  public function find(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table(self::TABLE) . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /** @return list<array<string,mixed>> Estudios de una tarea, el más reciente primero. */
  public function forTask(int $ticketId): array
  {
    return $ticketId > 0 ? $this->db->getResults('SELECT * FROM `' . $this->db->table(self::TABLE) . '` WHERE CAST(`id_ticket` AS CHAR) = ? ORDER BY `_ID` DESC LIMIT 30', [(string) $ticketId]) : [];
  }

  /**
   * Una página del listado. $scope: null = todo el equipo; id_empleado = solo los de la persona.
   * @param array<string,string> $filters
   * @return array{rows:list<array<string,mixed>>,total:int,estados:array<string,int>}
   */
  public function search(array $filters, ?string $scope, int $page, int $perPage): array
  {
    $table = $this->db->table(self::TABLE);
    $where = ['1=1'];
    $args = [];
    if ($scope !== null) {
      $where[] = "TRIM(COALESCE(e.`id_empleado`, '')) = ?";
      $args[] = $scope;
    }
    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
      $like = '%' . $q . '%';
      $where[] = '(CAST(e.`_ID` AS CHAR) = ? OR CAST(e.`id_ticket` AS CHAR) = ? OR e.`num_solicitud` LIKE ? OR e.`cliente` LIKE ? OR e.`correo_cliente` LIKE ? OR e.`celular_cliente` LIKE ? OR CAST(e.`id_inmueble` AS CHAR) = ?)';
      array_push($args, $q, $q, $like, $like, $like, $like, $q);
    }
    if (trim($filters['aseguradora'] ?? '') !== '') {
      $where[] = 'TRIM(e.`aseguradora`) = ?';
      $args[] = trim($filters['aseguradora']);
    }
    if ($scope === null && trim($filters['empleado'] ?? '') !== '') {
      $where[] = "TRIM(COALESCE(e.`id_empleado`, '')) = ?";
      $args[] = trim($filters['empleado']);
    }
    foreach (['desde' => '>=', 'hasta' => '<'] as $key => $operator) {
      $day = trim($filters[$key] ?? '');
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1) {
        $ts = strtotime($day . ' 00:00:00' . ($key === 'hasta' ? ' +1 day' : ''));
        if ($ts !== false) {
          $where[] = "CAST(COALESCE(e.`fecha`, 0) AS SIGNED) {$operator} ?";
          $args[] = $ts;
        }
      }
    }
    // Conteo por estado con los demás filtros: así las pestañas muestran cuántos hay en cada uno.
    $states = [];
    foreach ($this->db->getResults('SELECT TRIM(COALESCE(e.`estado`, \'\')) AS estado, TRIM(COALESCE(e.`estado_contrato`, \'\')) AS contrato, COUNT(*) AS total FROM `' . $table . '` e WHERE ' . implode(' AND ', $where) . ' GROUP BY 1, 2', $args) as $row) {
      $key = CommercialStudiesService::listState((string) $row['estado'], (string) $row['contrato']);
      $states[$key] = ($states[$key] ?? 0) + (int) $row['total'];
    }
    $state = trim($filters['estado'] ?? '');
    if ($state !== '') {
      [$sql, $stateArgs] = self::stateCondition($state);
      $where[] = $sql;
      array_push($args, ...$stateArgs);
    }
    $whereSql = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` e WHERE {$whereSql}", $args);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = $this->db->getResults("SELECT e.* FROM `{$table}` e WHERE {$whereSql} ORDER BY e.`_ID` DESC LIMIT {$perPage} OFFSET {$offset}", $args);
    return ['rows' => $rows, 'total' => $total, 'estados' => $states];
  }

  /**
   * Filtro de la pestaña de estado del listado (CommercialStudiesService::LIST_STATES): los aprobados se separan por lo que
   * pasó con el negocio (por entregar, entregado o desistido).
   * @return array{0:string,1:list<string>}
   */
  private static function stateCondition(string $state): array
  {
    $contract = "LOWER(TRIM(COALESCE(e.`estado_contrato`, '')))";
    return match ($state) {
      'Aprobado' => ["TRIM(e.`estado`) = 'Aprobado' AND {$contract} NOT IN ('entregado', 'desistido')", []],
      'Entregado' => ["TRIM(e.`estado`) = 'Aprobado' AND {$contract} = 'entregado'", []],
      'Negocio desistido' => ["TRIM(e.`estado`) = 'Aprobado' AND {$contract} = 'desistido'", []],
      default => ['TRIM(e.`estado`) = ?', [$state]],
    };
  }

  /** @return array<string,string> id_empleado => nombre de quienes han hecho estudios. */
  public function creators(): array
  {
    $out = [];
    foreach ($this->db->getResults('SELECT DISTINCT TRIM(e.`id_empleado`) AS id, COALESCE(NULLIF(TRIM(f.`nombre`), \'\'), NULLIF(TRIM(e.`creador`), \'\'), TRIM(e.`id_empleado`)) AS nombre FROM `' . $this->db->table(self::TABLE) . '` e LEFT JOIN `' . $this->db->table('jet_cct_funcionarios') . "` f ON TRIM(f.`id_empleado`) = TRIM(e.`id_empleado`) WHERE TRIM(COALESCE(e.`id_empleado`, '')) <> '' ORDER BY nombre ASC LIMIT 300") as $row) {
      $out[(string) $row['id']] = (string) $row['nombre'];
    }
    return $out;
  }

  /** @return list<string> Nombres de las aseguradoras (CCT aseguradoras, consulta 1141 del formulario). */
  public function insurers(): array
  {
    if ($this->columns->columns('jet_cct_aseguradoras') === []) {
      return [];
    }
    $names = array_values(array_filter(array_map(static fn($name): string => trim((string) $name), $this->db->getCol('SELECT `nombre` FROM `' . $this->db->table('jet_cct_aseguradoras') . '` ORDER BY `_ID` ASC')), static fn(string $name): bool => $name !== ''));
    return array_values(array_unique($names));
  }

  /** @return array<string,mixed>|null */
  public function task(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /** @return array<string,mixed>|null */
  public function employee(string $id): ?array
  {
    return $id === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE TRIM(`id_empleado`) = ? LIMIT 1', [$id]);
  }

  /** @return list<array{id:string,nombre:string}> Funcionarios activos (para elegir el responsable de las entregas). */
  public function activeEmployees(): array
  {
    $out = [];
    foreach ($this->db->getResults("SELECT `id_empleado`, `nombre` FROM `" . $this->db->table('jet_cct_funcionarios') . "` WHERE `activo` = 'Si' ORDER BY `nombre` ASC") as $row) {
      if (trim((string) $row['id_empleado']) !== '') {
        $out[] = ['id' => trim((string) $row['id_empleado']), 'nombre' => trim((string) $row['nombre'])];
      }
    }
    return $out;
  }

  /** @return array<string,mixed>|null */
  public function branch(string $id): ?array
  {
    return $id === '' || $this->columns->columns('jet_cct_sucursales') === [] ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_sucursales') . '` WHERE `_ID` = ? LIMIT 1', [$id]);
  }

  /** @return array<string,mixed>|null */
  public function owner(string $id): ?array
  {
    return $id === '' || !ctype_digit($id) || $this->columns->columns('jet_cct_propietarios') === [] ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE `_ID` = ? LIMIT 1', [(int) $id]);
  }

  /** @return array<string,mixed>|null Inmueble por código (el ID de su post de WordPress). */
  public function propertyByCode(string $code): ?array
  {
    return $code === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE CAST(`codigo` AS CHAR) = ? ORDER BY `_ID` DESC LIMIT 1', [$code]);
  }

  /**
   * Inmuebles que se pueden entregar (públicos o en borrador), por código, dirección, barrio o conjunto.
   * @return list<array<string,mixed>>
   */
  public function deliverableProperties(string $q, int $limit = 15): array
  {
    $in = implode(',', array_fill(0, count(self::DELIVERABLE_STATES), '?'));
    $args = self::DELIVERABLE_STATES;
    $where = "TRIM(`estado`) IN ({$in})";
    $q = trim($q);
    if ($q !== '') {
      $like = '%' . $q . '%';
      $where .= ' AND (CAST(`codigo` AS CHAR) = ? OR `direccion_fisica` LIKE ? OR `direccion` LIKE ? OR `barrio` LIKE ? OR `copropiedad` LIKE ? OR `propietario` LIKE ?)';
      array_push($args, $q, $like, $like, $like, $like, $like);
    }
    $columns = $this->columns->columns('jet_cct_inmuebles');
    $select = array_values(array_intersect(['_ID', 'codigo', 'estado', 'tipo_inmueble', 'tipo_negocio', 'barrio', 'ciudad', 'direccion', 'direccion_fisica', 'copropiedad', 'propietario', 'precio_arriendo', 'servicios_publicos'], $columns));
    return $this->db->getResults('SELECT `' . implode('`, `', $select) . '` FROM `' . $this->db->table('jet_cct_inmuebles') . "` WHERE {$where} ORDER BY (CAST(`codigo` AS CHAR) = ?) DESC, `_ID` DESC LIMIT " . max(1, min(50, $limit)), array_merge($args, [$q]));
  }

  /** @return array<string,mixed>|null */
  public function contract(int $id): ?array
  {
    return $id > 0 && $this->columns->columns('jet_cct_contratos_arrendamiento') !== [] ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_contratos_arrendamiento') . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /**
   * Quien atendió el último caso de entrega creado desde un estudio: responsable de las entregas mientras no se elija
   * otro en Configuración (así no queda un funcionario fijo en el código).
   */
  public function lastDeliveryResponsible(): string
  {
    return trim((string) $this->db->getVar("SELECT `id_empleado` FROM `" . $this->db->table('jet_cct_tickets') . "` WHERE TRIM(`tema_ayuda`) = ? AND TRIM(COALESCE(`id_estudio_aseguradora`, '')) <> '' AND TRIM(COALESCE(`id_empleado`, '')) <> '' ORDER BY `_ID` DESC LIMIT 1", [self::DELIVERY_TOPIC]));
  }

  /** @return list<string> */
  public function desistReasons(): array
  {
    return $this->glossary(self::DESIST_GLOSSARY) ?: self::DESIST_FALLBACK;
  }

  /** @return list<string> */
  public function utilities(): array
  {
    return $this->glossary(self::UTILITIES_GLOSSARY) ?: self::UTILITIES_FALLBACK;
  }

  /** @return list<string> */
  private function glossary(int $id): array
  {
    if ($this->columns->columns('jet_post_types') === []) {
      return [];
    }
    $raw = (string) ($this->db->getVar("SELECT `meta_fields` FROM `" . $this->db->table('jet_post_types') . "` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [$id]) ?? '');
    $items = @unserialize($raw, ['allowed_classes' => false]);
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
      $value = is_array($item) ? trim((string) ($item['value'] ?? '')) : '';
      if ($value !== '') {
        $out[$value] = $value;
      }
    }
    return array_values($out);
  }

  // ── Escritura ───────────────────────────────────────

  /** @param array<string,mixed> $data */
  public function insertStudy(array $data): int
  {
    return $this->insert(self::TABLE, $data);
  }

  /** @param array<string,mixed> $data */
  public function updateStudy(int $id, array $data): void
  {
    $this->update(self::TABLE, $id, $data);
  }

  /** @param array<string,mixed> $data */
  public function insertTicket(array $data): int
  {
    return $this->insert('jet_cct_tickets', $data);
  }

  /** @param array<string,mixed> $data */
  public function updateTicket(int $id, array $data): void
  {
    $this->update('jet_cct_tickets', $id, $data);
  }

  /** @param array<string,mixed> $data */
  public function insertContract(array $data): int
  {
    return $this->columns->columns('jet_cct_contratos_arrendamiento') === [] ? 0 : $this->insert('jet_cct_contratos_arrendamiento', $data);
  }

  /** @param array<string,mixed> $data */
  public function updateContract(int $id, array $data): void
  {
    if ($this->columns->columns('jet_cct_contratos_arrendamiento') !== []) {
      $this->update('jet_cct_contratos_arrendamiento', $id, $data);
    }
  }

  /** @param array<string,mixed> $data */
  public function insertContractHistory(array $data): void
  {
    if ($this->columns->columns('jet_cct_historial_contrato_arrendamiento') !== []) {
      $this->insert('jet_cct_historial_contrato_arrendamiento', $data);
    }
  }

  /** @param array<string,mixed> $data */
  public function updateProperty(int $id, array $data): void
  {
    $this->update('jet_cct_inmuebles', $id, $data);
  }

  /** @param array<string,mixed> $data */
  public function insertTaskHistory(array $data): void
  {
    $this->insert('jet_cct_historial_del_ticket', $data);
  }

  /** @param array<string,mixed> $data */
  public function insertPropertyHistory(array $data): void
  {
    if ($this->columns->columns('jet_cct_historial_del_inmueble') !== []) {
      $this->insert('jet_cct_historial_del_inmueble', $data);
    }
  }

  /** Meta del post de WordPress del inmueble (el código es su ID), como el insert_post del formulario. */
  public function setPostMeta(int $postId, string $key, string $value): void
  {
    if ($postId <= 0 || $this->columns->columns('postmeta') === []) {
      return;
    }
    $table = $this->db->table('postmeta');
    $metaId = (int) $this->db->getVar("SELECT `meta_id` FROM `{$table}` WHERE `post_id` = ? AND `meta_key` = ? LIMIT 1", [$postId, $key]);
    if ($metaId > 0) {
      $this->db->update($table, ['meta_value' => $value], ['meta_id' => $metaId]);
    } else {
      $this->db->insert($table, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
    }
  }

  /** @param array<string,mixed> $data */
  private function insert(string $name, array $data): int
  {
    $this->db->insert($this->db->table($name), $this->existing($name, $data));
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  private function update(string $name, int $id, array $data): void
  {
    $data = $this->existing($name, $data);
    if ($id > 0 && $data !== []) {
      $this->db->update($this->db->table($name), $data, ['_ID' => $id]);
    }
  }

  /**
   * Solo las columnas que existen en la tabla.
   * @param array<string,mixed> $data
   * @return array<string,mixed>
   */
  private function existing(string $name, array $data): array
  {
    return array_intersect_key($data, array_flip($this->columns->columns($name)));
  }
}
