<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use PDO;
use SCM\Core\Auth;
use SCM\Core\Database;

final class Repository
{
  private DatabaseAdapter $adapter;
  private array $columns = [];

  public function __construct(private Database $db) { $this->adapter = new DatabaseAdapter($db); }

  public static function normalizeName(string $name): string
  {
    return mb_strtolower(remove_accents(trim(preg_replace('/\s+/u', ' ', $name))), 'UTF-8');
  }

  public function columns(string $name): array
  {
    $table = $this->db->table($name);
    if (!isset($this->columns[$name])) {
      $this->columns[$name] = $this->adapter->get_var($this->adapter->prepare('SHOW TABLES LIKE %s', $table))
        ? $this->adapter->get_col('DESCRIBE ' . $table) : [];
    }
    return $this->columns[$name];
  }

  public function rows(string $name): array
  {
    if ($this->columns($name) === []) return [];
    return $this->db->getResults('SELECT * FROM `' . $this->db->table($name) . '`');
  }

  public function employee(): array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]) ?? [];
  }

  public function glossary(string $id, string $field): array
  {
    $options = [];
    if ($this->columns('options') !== []) {
      $raw = $this->db->getVar('SELECT option_value FROM `' . $this->db->table('options') . '` WHERE option_name = ?', ['jet_engine_glossaries']);
      $glossaries = maybe_unserialize((string) $raw);
      if (is_string($glossaries)) $glossaries = json_decode($glossaries, true);
      foreach (is_array($glossaries) ? $glossaries : [] as $key => $glossary) {
        if (!is_array($glossary) || (string) ($glossary['id'] ?? $key) !== $id) continue;
        foreach (($glossary['fields'] ?? $glossary['items'] ?? []) as $item) {
          if (is_array($item)) $options[] = ['value' => (string) ($item['value'] ?? ''), 'label' => (string) ($item['label'] ?? $item['value'] ?? '')];
        }
      }
    }
    if ($options === [] && in_array($field, $this->columns('jet_cct_precaptaciones'), true)) {
      $values = $this->db->getCol('SELECT DISTINCT `' . $field . '` FROM `' . $this->db->table('jet_cct_precaptaciones') . '` WHERE `' . $field . '` IS NOT NULL LIMIT 300');
      foreach ($values as $value) {
        $decoded = maybe_unserialize((string) $value);
        foreach (is_array($decoded) ? $decoded : [$decoded] as $choice) {
          if (is_scalar($choice) && trim((string) $choice) !== '') $options[] = ['value' => (string) $choice, 'label' => (string) $choice];
        }
      }
    }
    return array_values(array_column($options, null, 'value'));
  }

  public function options(): array
  {
    $out = [];
    foreach (['origen'=>'185','tipo_inmueble'=>'783','tipo_contacto'=>'160','promocionado_por'=>'161'] as $field => $id) {
      $out[$field] = $this->glossary($id, $field);
    }
    $out['categoria'] = array_map(static fn(string $value): array => ['value'=>$value,'label'=>$value], ['Arriendo','Venta','Arriendo o venta']);
    foreach (['barrio'=>['jet_cct_barrios','barrio'], 'competencia'=>['jet_cct_inmobiliarias','inmobiliaria']] as $field => [$table, $column]) {
      $out[$field] = array_map(static fn(array $row): array => ['value'=>(string) ($row[$column] ?? ''),'label'=>(string) ($row[$column] ?? '')], $this->rows($table));
      usort($out[$field], static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    }
    $out['id_pph'] = array_map(static fn(array $row): array => ['value'=>(string) $row['_ID'],'label'=>(string) ($row['tarjeta_bienvenida'] ?? $row['nombre'] ?? $row['_ID'])], $this->rows('jet_cct_club_pph'));
    $out['indicativo'] = [];
    foreach ($this->rows('jet_cct_paises') as $row) {
      $out['indicativo'][] = ['value'=>(string) ($row['codigo'] ?? $row['indicativo'] ?? ''),'label'=>(string) ($row['pais'] ?? $row['nombre'] ?? '')];
    }
    return $out;
  }

  public function createCatalog(string $kind, array $input): array
  {
    [$tableName, $field] = match ($kind) {
      'barrio' => ['jet_cct_barrios','barrio'],
      'inmobiliaria' => ['jet_cct_inmobiliarias','inmobiliaria'],
      default => throw new \InvalidArgumentException('Catálogo inválido.'),
    };
    if (!in_array($field, $this->columns($tableName), true)) throw new \RuntimeException('El catálogo no tiene la estructura requerida.');
    if ($kind === 'barrio') {
      foreach (['pais','ciudad','latitud','longitud','codigo_postal'] as $column) {
        if (!in_array($column, $this->columns($tableName), true)) throw new \RuntimeException('El catálogo de barrios no contiene el campo requerido: ' . $column);
      }
    }
    $name = sanitize_text_field($input[$field] ?? '');
    if ($name === '' || mb_strlen($name) > 180) throw new \InvalidArgumentException('Escribe un nombre de hasta 180 caracteres.');
    $pdo = $this->db->pdo();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lock = 'scm_catalog_' . substr(hash('sha256', $this->db->table($tableName)), 0, 32);
    if ($mysql && (int) $this->db->getVar('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) throw new \RuntimeException('El catálogo está ocupado. Intenta nuevamente.');
    try {
      $pdo->beginTransaction();
      $city = sanitize_text_field($input['ciudad'] ?? '');
      $country = sanitize_text_field($input['pais'] ?? '');
      foreach ($this->rows($tableName) as $row) {
        if (self::normalizeName((string) $row[$field]) !== self::normalizeName($name)) continue;
        if ($kind === 'barrio' && (self::normalizeName((string) ($row['ciudad'] ?? '')) !== self::normalizeName($city) || self::normalizeName((string) ($row['pais'] ?? '')) !== self::normalizeName($country))) continue;
        $pdo->commit();
        return ['existing'=>true,'option'=>['id'=>(int) $row['_ID'],'value'=>(string) $row[$field],'label'=>(string) $row[$field]]];
      }
      $data = [$field=>$name];
      if ($kind === 'barrio') {
        if ($city === '' || $country === '') throw new \InvalidArgumentException('Completa el país y la ciudad del barrio.');
        $latitude = trim((string) ($input['latitud'] ?? ''));
        $longitude = trim((string) ($input['longitud'] ?? ''));
        $postal = trim((string) ($input['codigo_postal'] ?? ''));
        if (!is_numeric($latitude) || abs((float) $latitude) > 90 || !is_numeric($longitude) || abs((float) $longitude) > 180 || !preg_match('/^\d{4,10}$/', $postal)) throw new \InvalidArgumentException('Verifica las coordenadas y el código postal.');
        $data += ['ciudad'=>$city,'pais'=>$country,'latitud'=>$latitude,'longitud'=>$longitude,'codigo_postal'=>$postal];
      }
      $id = $this->insert($tableName, $data);
      $pdo->commit();
      return ['existing'=>false,'option'=>['id'=>$id,'value'=>$name,'label'=>$name]];
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $exception;
    } finally {
      if ($mysql) $this->db->getVar('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  public function validate(array $input): array
  {
    $data = [];
    foreach (['origen','tipo_inmueble','categoria','tipo_contacto','contacto','barrio','direccion','punto_referencia','telefono','indicativo','celular','observaciones'] as $field) {
      $data[$field] = $field === 'observaciones' ? sanitize_textarea_field($input[$field] ?? '') : sanitize_text_field($input[$field] ?? '');
    }
    foreach (['origen','tipo_inmueble','categoria','celular'] as $field) if ($data[$field] === '') throw new \InvalidArgumentException('Completa origen, tipo de inmueble, categoría y celular.');
    if (!preg_match('/^\d{7,15}$/', $data['celular'])) throw new \InvalidArgumentException('El celular debe tener entre 7 y 15 dígitos sin indicativo.');
    if (!in_array($data['categoria'], ['Arriendo','Venta','Arriendo o venta'], true)) throw new \InvalidArgumentException('Categoría inválida.');
    $data['id_pph'] = $data['origen'] === 'Club PPH' ? (int) ($input['id_pph'] ?? 0) : 0;
    if ($data['origen'] === 'Club PPH' && !$this->db->getVar('SELECT _ID FROM `' . $this->db->table('jet_cct_club_pph') . '` WHERE _ID = ?', [$data['id_pph']])) throw new \InvalidArgumentException('Selecciona un aliado PPH válido.');
    $promoters = array_values(array_unique(array_filter(array_map(__NAMESPACE__ . '\\sanitize_text_field', (array) ($input['promocionado_por'] ?? [])))));
    $competition = array_values(array_unique(array_filter(array_map(__NAMESPACE__ . '\\sanitize_text_field', (array) ($input['competencia'] ?? [])))));
    $isAgency = (bool) array_filter($promoters, static fn(string $value): bool => str_contains(self::normalizeName($value), 'mobiliaria'));
    if ($isAgency && $competition === []) throw new \InvalidArgumentException('Selecciona al menos una inmobiliaria en competencia.');
    if (!$isAgency) $competition = [];
    $data['promocionado_por'] = serialize($promoters);
    $data['competencia'] = serialize($competition);
    $barrios = array_column($this->rows('jet_cct_barrios'), null, 'barrio');
    if ($data['barrio'] !== '' && !isset($barrios[$data['barrio']])) throw new \InvalidArgumentException('Selecciona un barrio existente o créalo desde el formulario.');
    $agencies = array_column($this->rows('jet_cct_inmobiliarias'), null, 'inmobiliaria');
    foreach ($competition as $name) if (!isset($agencies[$name])) throw new \InvalidArgumentException('Selecciona una inmobiliaria existente o créala desde el formulario.');
    $data['ruta'] = (string) ($barrios[$data['barrio']]['ruta_asignada'] ?? $barrios[$data['barrio']]['ruta'] ?? '');
    return $data;
  }

  public function create(array $data, array $photos): int
  {
    if ($photos === [] || count($photos) > 2) throw new \InvalidArgumentException('Adjunta una o dos fotografías válidas.');
    if (Auth::employeeId() === '') throw new \RuntimeException('Tu usuario no tiene un identificador de empleado configurado.');
    $employee = $this->employee();
    $data = array_merge($data, ['fotos'=>serialize($photos),'id_empleado'=>Auth::employeeId(),'creador'=>Auth::user(),'sucursal'=>$employee['sucursal'] ?? '', 'fecha'=>time(),'tiene_ticket'=>'No','contactado'=>'No','tuvo_seguimiento'=>'No','bandera'=>'No']);
    foreach (['origen','tipo_inmueble','categoria','fotos','celular','id_empleado','contactado'] as $field) {
      if (!in_array($field, $this->columns('jet_cct_precaptaciones'), true)) throw new \RuntimeException('La tabla de precaptaciones no contiene el campo requerido: ' . $field);
    }
    return $this->insert('jet_cct_precaptaciones', $data);
  }

  private function insert(string $name, array $data): int
  {
    $now = current_time('mysql');
    $data += ['cct_status'=>'publish','cct_author_id'=>Auth::employeeId(),'cct_created'=>$now,'cct_modified'=>$now];
    $data = array_intersect_key($data, array_flip($this->columns($name)));
    $this->db->insert($this->db->table($name), $data);
    return (int) $this->db->lastInsertId();
  }
}
