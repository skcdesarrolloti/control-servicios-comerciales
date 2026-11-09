<?php

declare(strict_types=1);

namespace SCM\Commercial\Calendar;

use SCM\Core\Database;
use SCM\Support\ColombiaBusinessCalendar;

/**
 * Reporte comercial que se llena al cerrar un evento. Lógica traída de calendario/backend/models/ReporteComercial.php:
 * quién lo llena sale de la fila compartida `calendario_reportes_comerciales_cargos`.
 */
final class CalendarCommercialReport
{
  public const CARGOS_SETTING = 'calendario_reportes_comerciales_cargos';
  private const TRANSPORT_SETTING = 'valor_transporte';
  private const VISIT_CATEGORIES = [3, 40];
  private const REPORT_CATEGORIES = [3, 26, 40];
  private const PROPERTY_REQUIRED_CATEGORIES = [3, 26, 40];
  private const TYPES = ['Visita', 'Otras actividades'];
  private const COMMERCIAL_WORDS = ['comercial', 'promocion', 'captacion', 'avaluo', 'precaptador', 'precaptacion'];

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /** Si el cargo del responsable y la categoría del evento obligan a llenar el reporte. */
  public function isRequired(int $eventId): bool
  {
    $event = $this->event($eventId);
    if ($event === null) {
      throw new \RuntimeException('Evento no encontrado.');
    }
    return $this->requires($event);
  }

  /** @return array<string,mixed> */
  public function prepare(int $eventId): array
  {
    $event = $this->event($eventId);
    if ($event === null) {
      throw new \RuntimeException('Evento no encontrado.');
    }
    if (!$this->requires($event)) {
      return ['habilitado' => false];
    }
    $transport = $this->transportValue();
    $ticketId = (int) ($event['id_ticket'] ?? 0);
    return [
      'habilitado' => true,
      'evento' => [
        'id' => (int) $event['id'],
        'titulo' => (string) $event['titulo'],
        'categoria' => (string) $event['categoria'],
        'id_categoria' => (int) $event['id_categoria'],
        'id_ticket' => $ticketId > 0 ? (string) $ticketId : '',
      ],
      'tipo_predeterminado' => in_array((int) $event['id_categoria'], self::VISIT_CATEGORIES, true) ? 'Visita' : 'Otras actividades',
      'tipos_permitidos' => self::TYPES,
      'inmueble_obligatorio' => $this->propertyRequired((int) $event['id_categoria']),
      'valor_transporte' => $transport,
      'valor_maximo' => $transport * 2,
      'inmuebles' => $this->quotedProperties($ticketId),
    ];
  }

  /** @return array<int,array<string,string>> */
  public function searchProperties(int $eventId, string $term): array
  {
    $event = $this->event($eventId);
    if ($event === null) {
      throw new \RuntimeException('Evento no encontrado.');
    }
    if (!$this->requires($event)) {
      throw new \RuntimeException('El reporte comercial no está habilitado para este evento.');
    }
    $term = trim($term);
    if (mb_strlen($term) < 2) {
      throw new \InvalidArgumentException('Escribe al menos 2 caracteres para buscar.');
    }
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    $where = "(CAST(p.`ID` AS CHAR) LIKE ? ESCAPE '!' OR COALESCE(i.`codigo`, '') LIKE ? ESCAPE '!' OR p.`post_title` LIKE ? ESCAPE '!' OR COALESCE(i.`direccion`, '') LIKE ? ESCAPE '!')";
    $rows = $this->properties($where, [$like, $like, $like, $like], 'CASE WHEN CAST(p.`ID` AS CHAR) = ? OR i.`codigo` = ? THEN 0 ELSE 1 END, p.`post_title` ASC', [$term, $term], 20);
    $quotes = $this->quoteMap((int) ($event['id_ticket'] ?? 0));
    return array_map(static fn(array $row): array => self::normalizeProperty($row, $quotes[(string) $row['id']] ?? ''), $rows);
  }

  /**
   * Guarda el reporte y cierra el evento en una sola transacción.
   * @param array<string,mixed> $report
   * @return array{creado:bool,evento:array<string,mixed>,reporte:array<string,mixed>,inmueble:array<string,string>|null}
   */
  public function finish(int $eventId, string $observation, array $report): array
  {
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $event = $this->event($eventId);
      if ($event === null) {
        throw new \RuntimeException('Evento no encontrado.');
      }
      if (!$this->requires($event)) {
        throw new \RuntimeException('El reporte comercial no está habilitado para este evento.');
      }
      $existing = $this->reportFor($eventId);
      if ($existing !== null) {
        if ($event['estado'] !== 'Si') {
          throw new \RuntimeException('El evento ya tiene un reporte comercial y requiere revisión.');
        }
        $pdo->commit();
        return ['creado' => false, 'evento' => $event, 'reporte' => $existing, 'inmueble' => null];
      }
      if ($event['estado'] === 'Si') {
        throw new \RuntimeException('El evento ya fue finalizado.');
      }
      $observation = trim($observation);
      if ($observation === '') {
        throw new \InvalidArgumentException('La observación es requerida.');
      }
      $type = trim((string) ($report['tipo_reporte'] ?? ''));
      if (!in_array($type, self::TYPES, true)) {
        throw new \InvalidArgumentException('El tipo de reporte no es válido.');
      }
      $value = self::transportCharge((string) ($report['transporte'] ?? ''), $this->transportValue());
      $propertyId = trim((string) ($report['id_inmueble'] ?? ''));
      if (($type === 'Visita' || $this->propertyRequired((int) $event['id_categoria'])) && $propertyId === '') {
        throw new \InvalidArgumentException('Debes seleccionar un inmueble.');
      }
      $property = null;
      $quoteId = '';
      if ($propertyId !== '') {
        $found = ctype_digit($propertyId) ? $this->properties('p.`ID` = ?', [(int) $propertyId], 'p.`post_title` ASC', [], 1) : [];
        if ($found === []) {
          throw new \InvalidArgumentException('El inmueble seleccionado no existe.');
        }
        $property = self::normalizeProperty($found[0], '');
        $quoteId = $this->resolveQuote((int) ($event['id_ticket'] ?? 0), $propertyId, trim((string) ($report['id_cotizacion'] ?? '')));
      }

      $now = (new \DateTimeImmutable('now', new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE)))->format('Y-m-d H:i:s');
      $ts = time();
      $ticketValue = (int) ($event['id_ticket'] ?? 0) > 0 ? (string) $event['id_ticket'] : '';
      $this->db->insert($this->db->table('jet_cct_reportes_comerciales'), [
        'cct_status' => 'publish', 'id_inmueble' => $property['id'] ?? '', 'fecha' => $ts, 'tipo_reporte' => $type, 'observacion' => $observation, 'valor' => $value,
        'funcionario' => (string) $event['funcionario'], 'id_empleado' => (string) $event['id_empleado'], 'id_ticket' => $ticketValue, 'id_cotizacion' => $quoteId,
        'cct_author_id' => (string) $event['id_empleado'], 'cct_created' => $now, 'cct_modified' => $now, 'sucursal' => trim((string) ($event['id_sucursal'] ?? '')),
        'fecha_realizado' => $ts, 'fue_pagado' => 'No', 'id_contrato' => $property['id_contrato'] ?? '', 'exportado' => 'No', 'id_evento' => (string) $event['id'],
      ]);
      $reportId = (int) $this->db->lastInsertId();
      if ($property !== null) {
        $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), [
          'cct_status' => 'publish', 'cct_author_id' => (string) $event['id_empleado'], 'cct_created' => $now, 'cct_modified' => $now, 'inmuebles_cotizados' => '',
          'id_empleado' => (string) $event['id_empleado'], 'id_inmueble' => $property['id'], 'fecha' => $ts, 'tipo_reporte' => $type, 'observacion' => $observation,
          'funcionario' => (string) $event['funcionario'], 'id_ticket' => $ticketValue, 'id_inmueble_data' => '',
        ]);
      }
      $update = $pdo->prepare('UPDATE `' . CalendarStore::EVENTS . "` SET `estado` = 'Si', `observacion` = ? WHERE `id` = ? AND `estado` = 'No'");
      $update->execute([$observation, $eventId]);
      if ($update->rowCount() !== 1) {
        throw new \RuntimeException('El evento cambió mientras se guardaba el reporte.');
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    $event['estado'] = 'Si';
    $event['observacion'] = $observation;
    return [
      'creado' => true,
      'evento' => $event,
      'reporte' => ['_ID' => $reportId, 'tipo_reporte' => $type, 'valor' => $value, 'observacion' => $observation, 'id_inmueble' => $propertyId, 'id_cotizacion' => $quoteId, 'id_evento' => (string) $eventId],
      'inmueble' => $property,
    ];
  }

  /** Lo que se cobra sale de `valor_transporte`, la misma fila del panel inmobiliario; el navegador solo elige la opción. */
  private static function transportCharge(string $choice, int $base): int
  {
    if ($choice === 'no') {
      return 0;
    }
    if (!in_array($choice, ['ida', 'ida_vuelta'], true)) {
      throw new \InvalidArgumentException('Indica si deseas cobrar transporte.');
    }
    if ($base <= 0) {
      throw new \InvalidArgumentException('El valor de transporte no está configurado.');
    }
    return $choice === 'ida' ? $base : $base * 2;
  }

  /** @return array<string,mixed>|null */
  private function event(int $eventId): ?array
  {
    return $this->db->getRow(
      'SELECT e.*, c.`nombre` AS categoria, f.`nombre` AS funcionario, f.`id_cargo`, f.`id_sucursal`
         FROM `' . CalendarStore::EVENTS . '` e
         JOIN `' . CalendarStore::CATEGORIES . '` c ON c.`id` = e.`id_categoria`
         JOIN `' . $this->db->table('jet_cct_funcionarios') . '` f ON f.`id_empleado` = e.`id_empleado`
        WHERE e.`id` = ?',
      [$eventId]
    );
  }

  /** @return array<string,mixed>|null */
  private function reportFor(int $eventId): ?array
  {
    return $this->db->getRow(
      'SELECT `_ID`, `tipo_reporte`, `valor`, `observacion`, `id_inmueble`, `id_cotizacion`, `id_evento` FROM `' . $this->db->table('jet_cct_reportes_comerciales') . '` WHERE `id_evento` = ? ORDER BY `_ID` ASC LIMIT 1',
      [(string) $eventId]
    );
  }

  /** @param array<string,mixed> $event */
  private function requires(array $event): bool
  {
    return $this->cargoEnabled((int) ($event['id_cargo'] ?? 0)) && self::categoryEnabled((int) $event['id_categoria'], (string) $event['categoria']);
  }

  private static function categoryEnabled(int $id, string $name): bool
  {
    if (in_array($id, self::REPORT_CATEGORIES, true)) {
      return true;
    }
    $text = strtolower((string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
    foreach (self::COMMERCIAL_WORDS as $word) {
      if (strpos($text, $word) !== false) {
        return true;
      }
    }
    return false;
  }

  private function propertyRequired(int $categoryId): bool
  {
    return in_array($categoryId, self::PROPERTY_REQUIRED_CATEGORIES, true);
  }

  private function cargoEnabled(int $cargo): bool
  {
    $raw = $this->systemValue(self::CARGOS_SETTING);
    $cargos = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($cargos) && $cargo > 0 && in_array($cargo, array_map('intval', $cargos), true);
  }

  private function transportValue(): int
  {
    $value = filter_var($this->systemValue(self::TRANSPORT_SETTING), FILTER_VALIDATE_INT);
    if ($value === false || $value < 0) {
      throw new \RuntimeException('La configuración valor_transporte es inválida.');
    }
    return (int) $value;
  }

  private function systemValue(string $key): ?string
  {
    $value = $this->db->getVar(
      'SELECT `valor` FROM `' . $this->db->table('jet_cct_confi_sistema') . "` WHERE `funcion` = ? AND `cct_status` = 'publish' ORDER BY `_ID` DESC LIMIT 1",
      [$key]
    );
    return $value === null ? null : (string) $value;
  }

  /** @return array<int,array<string,string>> */
  private function quotedProperties(int $ticketId): array
  {
    $map = $this->quoteMap($ticketId);
    $ids = array_values(array_filter(array_map('strval', array_keys($map)), 'ctype_digit'));
    if ($ids === []) {
      return [];
    }
    $rows = $this->properties('p.`ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_map('intval', $ids), 'p.`post_title` ASC', [], 200);
    return array_map(static fn(array $row): array => self::normalizeProperty($row, $map[(string) $row['id']] ?? ''), $rows);
  }

  /** @return array<string,string> inmueble => cotización más reciente */
  private function quoteMap(int $ticketId): array
  {
    if ($ticketId <= 0) {
      return [];
    }
    $map = [];
    $rows = $this->db->getResults('SELECT `_ID`, `inmuebles` FROM `' . $this->db->table('jet_cct_cotizacion_inmuebles') . '` WHERE `id_ticket` = ? ORDER BY `_ID` DESC', [$ticketId]);
    foreach ($rows as $row) {
      foreach (self::propertyIds($row['inmuebles'] ?? '') as $id) {
        if (!isset($map[$id])) {
          $map[$id] = (string) $row['_ID'];
        }
      }
    }
    return $map;
  }

  /** @param mixed $value @return array<int,string> */
  private static function propertyIds($value): array
  {
    if (!is_string($value) || trim($value) === '') {
      return [];
    }
    $data = @unserialize($value, ['allowed_classes' => false]);
    if ($data === false && $value !== 'b:0;') {
      $data = json_decode($value, true);
    }
    if (!is_array($data)) {
      $data = preg_split('/[\s,;]+/', trim($value)) ?: [];
    }
    $ids = [];
    array_walk_recursive($data, static function ($id) use (&$ids): void {
      $id = trim((string) $id);
      if ($id !== '' && ctype_digit($id)) {
        $ids[$id] = $id;
      }
    });
    return array_values($ids);
  }

  private function resolveQuote(int $ticketId, string $propertyId, string $requested): string
  {
    $detected = $this->quoteMap($ticketId)[$propertyId] ?? '';
    if ($requested !== '' && ($detected === '' || !hash_equals($detected, $requested))) {
      throw new \InvalidArgumentException('La cotización no corresponde a la tarea y al inmueble seleccionados.');
    }
    return $requested !== '' ? $requested : $detected;
  }

  /**
   * @param array<int,mixed> $args
   * @param array<int,mixed> $orderArgs
   * @return array<int,array<string,mixed>>
   */
  private function properties(string $where, array $args, string $order, array $orderArgs, int $limit): array
  {
    $posts = $this->db->table('posts');
    $meta = $this->db->table('postmeta');
    return $this->db->getResults(
      "SELECT p.`ID` AS id, p.`post_title` AS titulo, COALESCE(i.`codigo`, CAST(p.`ID` AS CHAR)) AS codigo, COALESCE(i.`direccion`, '') AS direccion,
              COALESCE(i.`id_contrato_arrendamiento`, '') AS id_contrato, COALESCE(oe.`meta_value`, '') AS correo_propietario, COALESCE(onm.`meta_value`, '') AS propietario
         FROM `{$posts}` p
         LEFT JOIN `" . $this->db->table('jet_cct_inmuebles') . "` i ON i.`codigo` = CAST(p.`ID` AS CHAR)
         LEFT JOIN `{$meta}` oe ON oe.`post_id` = p.`ID` AND oe.`meta_key` = 'email-propietario'
         LEFT JOIN `{$meta}` onm ON onm.`post_id` = p.`ID` AND onm.`meta_key` = 'propietario'
        WHERE p.`post_type` = 'inmuebles' AND p.`post_status` <> 'trash' AND {$where}
        GROUP BY p.`ID`, p.`post_title`, i.`codigo`, i.`direccion`, i.`id_contrato_arrendamiento`, oe.`meta_value`, onm.`meta_value`
        ORDER BY {$order}
        LIMIT " . $limit,
      array_merge($args, $orderArgs)
    );
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private static function normalizeProperty(array $row, string $quoteId): array
  {
    return [
      'id' => (string) $row['id'],
      'codigo' => (string) $row['codigo'],
      'titulo' => (string) $row['titulo'],
      'direccion' => (string) $row['direccion'],
      'id_contrato' => (string) ($row['id_contrato'] ?? ''),
      'id_cotizacion' => $quoteId,
      'correo_propietario' => (string) ($row['correo_propietario'] ?? ''),
      'propietario' => (string) ($row['propietario'] ?? ''),
    ];
  }
}
