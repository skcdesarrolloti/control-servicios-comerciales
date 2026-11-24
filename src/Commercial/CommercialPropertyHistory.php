<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Historial del inmueble: el mismo informe consolidado del panel inmobiliario (todas las fuentes del inmueble en una
 * línea de tiempo), abierto desde la tarjeta del inmueble.
 *
 * Lógica copiada de control-servicios-inmobiliarios/src/App/Concerns/HandlesPropertyHistoryActions.php
 * (property_history_payload y sus ayudantes): mantener ambas iguales. Aquí se consulta solo por código de inmueble,
 * que es lo que da la tarjeta; el HTML lo arma SCM\Support\PropertyHistoryReport, copia del mismo componente.
 */
final class CommercialPropertyHistory
{
  public const LOGO = 'https://sucasainmobiliaria.com.co/wp-content/uploads/2022/05/logo-white-skc-e1781617234571.png';

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db, ?SchemaInspector $schema = null)
  {
    $this->db = $db;
    $this->schema = $schema ?? new SchemaInspector($db);
  }

  /** Código limpio como en el panel inmobiliario (solo letras, números, guion y guion bajo). */
  public static function cleanCode(string $value): string
  {
    return preg_replace('/[^0-9A-Za-z_-]+/', '', trim($value)) ?: '';
  }

  /**
   * Fila del inmueble por _ID, código o id_inmueble, o null si no existe.
   *
   * @return array<string,mixed>|null
   */
  public function property(string $code): ?array
  {
    $found = $this->findProperty([self::cleanCode($code)]);
    return $found === [] ? null : $found;
  }

  /** @return array<string,mixed> */
  public function payload(string $propertyCode): array
  {
    $propertyCode = self::cleanCode($propertyCode);
    if ($propertyCode === '') {
      throw new \InvalidArgumentException('Inmueble no válido.');
    }
    $property = $this->findProperty([$propertyCode]);
    $tokens = [$propertyCode];
    if ($property !== []) {
      $tokens = array_merge($tokens, $this->tokensFromRow($property));
    } else {
      // Como en el panel inmobiliario: si no está en el CCT, la última tarea con ese inmueble da sus otros códigos.
      $ticketTable = $this->db->table('jet_cct_tickets');
      $where = [];
      $args = [];
      foreach (['id_inmueble', 'id_inmueble_data', 'inmueble'] as $column) {
        if ($this->schema->columnExists($ticketTable, $column)) {
          $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) = ?";
          $args[] = $propertyCode;
        }
      }
      if ($where !== []) {
        $row = $this->db->getRow("SELECT * FROM `{$ticketTable}` WHERE " . implode(' OR ', $where) . ' ORDER BY `_ID` DESC LIMIT 1', $args);
        if (is_array($row)) {
          $tokens = array_merge($tokens, $this->tokensFromRow($row));
          $property = $this->findProperty($tokens);
        }
      }
    }
    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens), static fn(string $value): bool => trim($value) !== '')));

    $sections = [];
    $counts = [];
    $contractHistory = [];
    foreach (self::sources() as $key => $source) {
      $rows = $this->rows($source['table'], $tokens, $source['order']);
      if (in_array($key, ['contratos_arrendamiento', 'contratos_mandato'], true)) {
        foreach ($rows as $row) {
          $contractHistory[] = $this->contractSummaryRow($key, $row);
        }
      }
      $items = array_map(fn(array $row): array => $this->summarizeRow($key, $row), $rows);
      $counts[$key] = count($items);
      $sections[] = [
        'key' => $key,
        'label' => (string) $source['label'],
        'description' => (string) $source['description'],
        'count' => count($items),
        'items' => array_slice($items, 0, 80),
      ];
    }

    $timeline = [];
    foreach ($sections as $section) {
      foreach ($section['items'] as $item) {
        $timeline[] = [
          'source' => $section['label'],
          'source_key' => $section['key'],
          'date' => (string) $item['date'],
          'date_ts' => (int) $item['date_ts'],
          'title' => (string) $item['title'],
          'detail' => (string) $item['detail'],
          'reference' => (string) $item['reference'],
        ];
      }
    }
    usort($timeline, static fn(array $a, array $b): int => $b['date_ts'] <=> $a['date_ts']);
    usort($contractHistory, static fn(array $a, array $b): int => (int) $b['date_ts'] <=> (int) $a['date_ts']);
    if (array_sum($counts) <= 0 && $property === []) {
      throw new \InvalidArgumentException('No se encontró el inmueble ' . $propertyCode . '.');
    }

    return [
      'query' => 'Codigo ' . $propertyCode,
      'contract_number' => '',
      'property_code' => $propertyCode,
      'generated_at' => date('d/m/Y H:i'),
      'resolved_by' => 'codigo',
      'property' => $this->propertyInfo($propertyCode, $property),
      'contracts' => array_slice($contractHistory, 0, 120),
      'counts' => $counts,
      'sources' => array_map(static fn(array $section): array => ['key' => $section['key'], 'label' => $section['label'], 'description' => $section['description'], 'count' => $section['count']], $sections),
      'sections' => $sections,
      'timeline' => array_slice($timeline, 0, 300),
    ];
  }

  /** @param array<string,mixed> $payload */
  public function render(array $payload, bool $pdf = false): string
  {
    $actor = $this->signature();
    return \SCM\Support\PropertyHistoryReport::render($payload, [
      'pdf' => $pdf,
      'logo' => self::LOGO,
      'generated_by' => ['name' => $actor['name'], 'details' => $actor['details']],
    ]);
  }

  /** PDF con Chromium del mismo informe que se ve en el panel. */
  public function pdf(array $payload): string
  {
    $root = dirname(__DIR__, 2);
    $bytes = \SCM\Support\HtmlPdfRenderer::render($this->render($payload, true), 'Historial del inmueble ' . (string) ($payload['property']['codigo'] ?? ''), [
      'stylesheets' => [$root . '/public/assets/css/property-history-report.css'],
      'external_fonts' => false, 'body_class' => 'scm-property-history-pdf',
      'wrapper_class' => 'scm-property-history-pdf-root', 'container_class' => 'scm-property-history-pdf-main', 'allow_fallback' => false,
    ]);
    if (!is_string($bytes) || $bytes === '') {
      throw new \RuntimeException('No pudimos generar el PDF en este momento. Intenta de nuevo en unos minutos.');
    }
    return $bytes;
  }

  public static function filename(array $payload): string
  {
    $token = preg_replace('/[^0-9A-Za-z_-]+/', '-', (string) ($payload['property']['codigo'] ?? $payload['property_code'] ?? '')) ?: 'inmueble';
    return 'historial-inmueble-' . $token . '.pdf';
  }

  /** @return array<string,array{table:string,label:string,description:string,order:array<int,string>}> */
  public static function sources(): array
  {
    return [
      'historial' => ['table' => 'jet_cct_historial_del_inmueble', 'label' => 'Historial del inmueble', 'description' => 'Linea de tiempo general registrada para el inmueble.', 'order' => ['fecha', 'cct_created', '_ID']],
      'gestiones_cobro' => ['table' => 'jet_cct_gestiones_cobro', 'label' => 'Gestiones de cobro', 'description' => 'Mensajes, recordatorios y acciones de cartera.', 'order' => ['fecha', 'cct_created', '_ID']],
      'tickets' => ['table' => 'jet_cct_tickets', 'label' => 'Casos', 'description' => 'Casos administrativos y solicitudes relacionadas.', 'order' => ['fecha', 'cct_created', '_ID']],
      'contratos_arrendamiento' => ['table' => 'jet_cct_contratos_arrendamiento', 'label' => 'Contratos de arrendamiento', 'description' => 'Contratos vinculados al inmueble.', 'order' => ['fecha', 'cct_created', '_ID']],
      'contratos_mandato' => ['table' => 'jet_cct_contrato_mandato', 'label' => 'Contratos de administracion', 'description' => 'Mandatos o administracion del inmueble.', 'order' => ['fecha', 'cct_created', '_ID']],
      'cierres' => ['table' => 'jet_cct_cierres', 'label' => 'Cierres', 'description' => 'Cierres comerciales o documentales.', 'order' => ['fecha', 'cct_created', '_ID']],
      'revisiones_servicios' => ['table' => 'jet_cct_revisiones_servicios', 'label' => 'Servicios publicos', 'description' => 'Revisiones de servicios publicos registradas.', 'order' => ['fecha', 'cct_created', '_ID']],
      'revision_preventiva' => ['table' => 'jet_cct_revision_preventiva', 'label' => 'Revisiones preventivas', 'description' => 'Inspecciones preventivas y hallazgos.', 'order' => ['fecha', 'fecha_revision', 'cct_created', '_ID']],
      'revision_correctiva' => ['table' => 'jet_cct_revision_correctiva', 'label' => 'Revisiones correctivas', 'description' => 'Revisiones por danos o reparaciones.', 'order' => ['fecha', 'cct_created', '_ID']],
      'cotizaciones_mantenimiento' => ['table' => 'jet_cct_cotizacion_mantenimiento', 'label' => 'Cotizaciones de mantenimiento', 'description' => 'Cotizaciones y presupuestos de reparacion.', 'order' => ['fecha', 'fecha_envio', 'cct_created', '_ID']],
      'estudios_aseguradoras' => ['table' => 'jet_cct_estudios_aseguradoras', 'label' => 'Estudios de aseguradora', 'description' => 'Solicitudes y respuestas de aseguradoras.', 'order' => ['fecha', 'cct_created', '_ID']],
      'actualizaciones' => ['table' => 'jet_cct_actualizaciones', 'label' => 'Actualizaciones', 'description' => 'Cambios de informacion del inmueble.', 'order' => ['fecha', 'cct_created', '_ID']],
      'llamadas' => ['table' => 'jet_cct_cct_llamadas', 'label' => 'Llamadas o interesados', 'description' => 'Contactos e interesados relacionados.', 'order' => ['fecha', 'cct_created', '_ID']],
      'recaptaciones' => ['table' => 'jet_cct_recaptaciones', 'label' => 'Recaptaciones', 'description' => 'Recaptacion o reactivacion comercial.', 'order' => ['fecha', 'cct_created', '_ID']],
    ];
  }

  /** @param string[] $tokens @return array<string,mixed> */
  private function findProperty(array $tokens): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens), static fn(string $value): bool => trim($value) !== '')));
    if ($tokens === [] || !$this->schema->tableExists($table)) {
      return [];
    }
    $where = [];
    $args = [];
    foreach (['_ID', 'codigo', 'id_inmueble', 'id_inmueble_data'] as $column) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }
      $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) IN (" . implode(',', array_fill(0, count($tokens), '?')) . ')';
      array_push($args, ...$tokens);
    }
    if ($where === []) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . ' LIMIT 1', $args);
    return is_array($row) ? $row : [];
  }

  /** @param string[] $tokens @param string[] $orderColumns @return array<int,array<string,mixed>> */
  private function rows(string $suffix, array $tokens, array $orderColumns): array
  {
    $table = $this->db->table($suffix);
    if ($tokens === [] || !$this->schema->tableExists($table)) {
      return [];
    }
    $where = [];
    $args = [];
    foreach (['id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo', 'codigo_inmueble', 'id_inmueble_web'] as $column) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }
      $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) IN (" . implode(',', array_fill(0, count($tokens), '?')) . ')';
      array_push($args, ...$tokens);
    }
    if ($where === []) {
      return [];
    }
    $order = $this->schema->columnExists($table, '_ID') ? 'ORDER BY `_ID` DESC' : '';
    foreach ($orderColumns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $order = "ORDER BY `{$column}` DESC";
        break;
      }
    }
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " {$order} LIMIT 600", $args);
  }

  /** @return string[] */
  private function tokensFromRow(array $row): array
  {
    $tokens = [];
    foreach (['_ID', 'codigo', 'id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo_inmueble', 'id_inmueble_web'] as $field) {
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        $tokens[] = $value;
      }
    }
    return array_values(array_unique($tokens));
  }

  /** @param array<string,mixed> $property @return array<string,string> */
  private function propertyInfo(string $query, array $property): array
  {
    return [
      'codigo' => trim((string) ($property['codigo'] ?? $property['_ID'] ?? $query)),
      'id_interno' => trim((string) ($property['_ID'] ?? '')),
      'direccion' => trim((string) ($property['direccion'] ?? $property['direccion_fisica'] ?? '')),
      'barrio' => trim((string) ($property['barrio'] ?? '')),
      'ciudad' => trim((string) ($property['ciudad'] ?? '')),
      'propietario' => trim((string) ($property['propietario'] ?? '')),
      'tipo' => self::join([$property['tipo_inmueble'] ?? '', $property['tipo_negocio'] ?? '', $property['destinacion'] ?? ''], ' / '),
      'matricula' => trim((string) ($property['matricula_inmobiliaria'] ?? $property['matricula'] ?? '')),
      'referencia_catastral' => trim((string) ($property['referencia_catastral'] ?? $property['referencia'] ?? '')),
    ];
  }

  /** @return array<string,mixed> */
  private function contractSummaryRow(string $key, array $row): array
  {
    $number = self::first($row, ['contrato', 'id_contrato', '_ID']);
    $dateRaw = self::first($row, ['fecha_inicio', 'fecha', 'fecha_contrato', 'cct_created', 'cct_modified']);
    $endDate = self::first($row, ['fecha_fin', 'fecha_finalizacion', 'fecha_terminacion', 'fecha_cierre']);
    $tenant = self::first($row, ['arrendatario', 'inquilino', 'cliente']);
    $canonRaw = self::first($row, ['valor_canon', 'canon', 'canon_arrendamiento', 'precio_arriendo']);
    $type = $key === 'contratos_mandato' ? 'Administracion' : 'Arrendamiento';
    // "600.000" usa punto de miles; sin normalizar se leía como 600.
    $canon = preg_match('/^\$?\s*\d{1,3}(\.\d{3})+$/', trim($canonRaw)) === 1 ? (string) preg_replace('/\D/', '', $canonRaw) : $canonRaw;
    return [
      'type' => $type,
      'number' => $number,
      'title' => 'Contrato ' . strtolower($type) . ' #' . ($number !== '' ? $number : '-'),
      'date' => self::dateLabel($dateRaw),
      'date_ts' => self::timestamp($dateRaw),
      'end_date' => $endDate !== '' ? self::dateLabel($endDate) : '',
      'status' => self::first($row, ['estado', 'estado_contrato', 'estado_administrativo']),
      'tenant' => $tenant,
      'canon' => self::cop($canon),
      'reference' => self::join([
        $tenant !== '' ? 'Arrendatario: ' . $tenant : '',
        self::first($row, ['propietario']) !== '' ? 'Propietario: ' . self::first($row, ['propietario']) : '',
        $endDate !== '' ? 'Finaliza: ' . self::dateLabel($endDate) : '',
      ], ' · '),
    ];
  }

  /** @return array{id:string,date:string,date_ts:int,title:string,detail:string,reference:string,ticket:string,contract:string,property:string,status:string} */
  private function summarizeRow(string $key, array $row): array
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $dateRaw = self::first($row, ['fecha', 'fecha_revision', 'fecha_envio', 'fecha_satisfaccion', 'cct_created', 'cct_modified']);
    $ticket = self::first($row, ['id_ticket', 'ticket', 'ticket_pk']);
    $contract = self::first($row, ['contrato', 'id_contrato', 'contrato_arrendamiento']);
    $property = self::first($row, ['id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo']);
    $status = self::first($row, ['estado', 'estado_administrativo', 'estatus']);
    $detail = self::first($row, ['observacion', 'descripcion', 'reportes', 'respuesta', 'seguimiento_mantenimiento', 'recomendaciones', 'asunto', 'tema_ayuda', 'detalle']);
    return [
      'id' => $id,
      'date' => self::dateLabel($dateRaw),
      'date_ts' => self::timestamp($dateRaw),
      'title' => self::cleanText(self::title($key, $row, $id)),
      'detail' => self::cleanText($detail),
      'reference' => self::join([
        $ticket !== '' ? 'Caso #' . $ticket : '',
        $contract !== '' ? 'Contrato #' . $contract : '',
        $property !== '' ? 'Inmueble ' . $property : '',
        $status !== '' ? 'Estado: ' . $status : '',
      ], ' · '),
      'ticket' => $ticket,
      'contract' => $contract,
      'property' => $property,
      'status' => $status,
    ];
  }

  private static function title(string $key, array $row, string $id): string
  {
    if ($key === 'tickets') {
      $ticket = self::first($row, ['id_ticket', '_ID']);
      return 'Caso #' . ($ticket !== '' ? $ticket : $id) . ' - ' . self::first($row, ['asunto', 'tema_ayuda', 'categoria']);
    }
    if ($key === 'historial') {
      $type = self::first($row, ['tipo_reporte', 'tipo', 'titulo']);
      return strcasecmp($type, 'Ticket') === 0 ? 'Caso' : ($type ?: 'Movimiento de historial #' . $id);
    }
    if ($key === 'contratos_arrendamiento' || $key === 'contratos_mandato') {
      return 'Contrato #' . (self::first($row, ['contrato', 'id_contrato', '_ID']) ?: $id);
    }
    if ($key === 'cotizaciones_mantenimiento') {
      return 'Cotizacion #' . ($id !== '' ? $id : self::first($row, ['id_cotizacion']));
    }
    if ($key === 'revision_preventiva') {
      return 'Revision preventiva #' . ($id !== '' ? $id : '-');
    }
    if ($key === 'revision_correctiva') {
      return 'Revision correctiva #' . ($id !== '' ? $id : '-');
    }
    if ($key === 'revisiones_servicios') {
      return 'Revision servicios publicos #' . ($id !== '' ? $id : '-');
    }
    return ($id !== '' ? '#' . $id . ' ' : '') . ucfirst(str_replace('_', ' ', $key));
  }

  /** @param string[] $fields */
  private static function first(array $row, array $fields): string
  {
    foreach ($fields as $field) {
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @param array<int,mixed> $items */
  private static function join(array $items, string $separator): string
  {
    return implode($separator, array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $items), static fn(string $value): bool => $value !== '' && $value !== '-')));
  }

  private static function timestamp(string $value): int
  {
    $value = trim($value);
    if ($value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      return (int) $value > 1000000000 ? (int) $value : 0;
    }
    $time = strtotime($value);
    return $time ? $time : 0;
  }

  private static function dateLabel(string $value): string
  {
    $time = self::timestamp($value);
    return $time > 0 ? date('d/m/Y H:i', $time) : ($value !== '' ? $value : '-');
  }

  private static function cleanText(string $value): string
  {
    $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
  }

  private static function cop(string $value): string
  {
    $text = trim(str_replace(['$', ' '], '', $value));
    if ($text === '') {
      return '';
    }
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $text) === 1) {
      $text = str_replace(['.', ','], ['', '.'], $text);
    } elseif (str_contains($text, ',')) {
      $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? '$' . number_format((float) $text, 0, ',', '.') : $value;
  }

  /** Quien genera el informe: su nombre, cargo, correo y celular. @return array{name:string,details:string} */
  private function signature(): array
  {
    $name = trim(Auth::user());
    $employeeId = trim(Auth::employeeId());
    $row = $employeeId === '' ? null : $this->db->getRow('SELECT nombre, rol, correo, celular FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE TRIM(id_empleado) = ? LIMIT 1', [$employeeId]);
    if (!is_array($row)) {
      return ['name' => $name !== '' ? $name : 'SKC SuCasa Inmobiliaria', 'details' => 'Panel comercial'];
    }
    $details = array_filter([
      trim((string) ($row['rol'] ?? '')),
      trim((string) ($row['correo'] ?? '')) !== '' ? 'Email: ' . trim((string) $row['correo']) : '',
      trim((string) ($row['celular'] ?? '')) !== '' ? 'Cel. ' . trim((string) $row['celular']) : '',
    ]);
    return ['name' => trim((string) ($row['nombre'] ?? '')) ?: ($name !== '' ? $name : 'SKC SuCasa Inmobiliaria'), 'details' => $details !== [] ? implode(' · ', $details) : 'Panel comercial'];
  }
}
