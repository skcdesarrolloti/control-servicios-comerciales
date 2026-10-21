<?php

declare(strict_types=1);

namespace SCM\Commercial;

use PDO;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailTemplate;

final class CommercialPropertyEntryService
{
  private array $columns = [];
  private ?array $catalogs = null;

  public function __construct(private Database $db, private CommercialAccessPolicy $policy, private array $config = []) {}

  public static function definition(): array
  {
    static $definition;
    return $definition ??= json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/forms/property-entry.json'), true, 512, JSON_THROW_ON_ERROR);
  }

  public function task(int $id): array
  {
    if (!$this->policy->canAct('ver_ticket') || !$this->policy->canAct('ingresar_inmueble')) throw new CommercialSignAccessDenied('No tienes permiso para ingresar inmuebles desde las tareas.');
    $task = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE _ID = ?' . $this->lock(), [$id]);
    if (!$task) throw new \InvalidArgumentException('No se encontró la tarea.');
    $repository = new CommercialTicketsRepository($this->db);
    if (!$this->policy->canSeeAllCommercialTickets() && !$repository->ticketMatchesEmployeeFilter($id, $repository->currentEmployeeTicketFilter())) throw new CommercialSignAccessDenied('Esta tarea no está asignada a tu usuario.');
    return $task;
  }

  private function decode($raw): array
  {
    $value = @unserialize((string) $raw, ['allowed_classes'=>false]);
    return is_array($value) ? $value : [];
  }

  private function columns(string $name): array
  {
    if (!isset($this->columns[$name])) {
      $table = $this->db->table($name);
      $this->columns[$name] = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? array_column($this->db->getResults('PRAGMA table_info(`' . $table . '`)'), 'name')
        : $this->db->getCol('SHOW COLUMNS FROM `' . $table . '`');
    }
    return $this->columns[$name];
  }

  public function options(): array
  {
    if ($this->catalogs !== null) return $this->catalogs;
    $this->catalogs = [];
    $definitions = [];
    $ids = [];
    foreach (self::definition()['fields'] as $field) {
      if ($field['source'] === 'glossary') $ids[] = $field['glossary'];
      if ($field['source'] === 'generate') $ids[] = (int) explode('|', $field['query'])[0];
    }
    $ids = array_values(array_unique($ids));
    foreach ($this->db->getResults('SELECT id,status,args,meta_fields FROM `' . $this->db->table('jet_post_types') . '` WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) $definitions[(int) $row['id']] = $row;
    foreach (self::definition()['fields'] as $field) {
      $choices = [];
      if ($field['source'] === 'glossary') {
        $definition = $definitions[$field['glossary']] ?? [];
        if (($definition['status'] ?? '') === 'glossary') {
          foreach ($this->decode($definition['meta_fields']) as $item) if (is_array($item) && is_scalar($item['value'] ?? null) && is_scalar($item['label'] ?? $item['value'])) $choices[] = ['value'=>(string) $item['value'],'label'=>(string) ($item['label'] ?? $item['value'])];
        }
      } elseif ($field['source'] === 'terms') {
        $choices = $this->db->getResults('SELECT CAST(t.term_id AS CHAR) AS value,t.name AS label FROM `' . $this->db->table('terms') . '` t JOIN `' . $this->db->table('term_taxonomy') . '` tt ON tt.term_id=t.term_id WHERE tt.taxonomy=? ORDER BY t.name', [$field['taxonomy']]);
      } elseif ($field['source'] === 'generate') {
        [$query, $valueColumn, $labelColumn] = explode('|', $field['query']);
        $definition = $definitions[(int) $query] ?? [];
        $args = $this->decode($definition['args'] ?? '');
        $cct = $args['custom-content-type'] ?? [];
        $name = (string) ($cct['content_type'] ?? '');
        if (($definition['status'] ?? '') === 'query' && preg_match('/^[a-z_]+$/D', $name) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $valueColumn) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $labelColumn)) {
          $columns = $this->columns('jet_cct_' . $name);
          if (in_array($valueColumn, $columns, true) && in_array($labelColumn, $columns, true)) {
            $extra = in_array($field['name'], ['pais','departamento','ciudad','barrio'], true) ? array_values(array_intersect(['pais','departamento','ciudad','ruta_asignada','latitud','longitud','codigo_postal'], $columns)) : [];
            $select = '`' . $valueColumn . '` AS value, `' . $labelColumn . '` AS label' . ($extra ? ', `' . implode('`,`', $extra) . '`' : '');
            $where = []; $params = [];
            foreach ($cct['args'] ?? [] as $filter) {
              if (($filter['operator'] ?? '') !== '=' || !in_array($filter['field'] ?? '', $columns, true) || !is_scalar($filter['value'] ?? null) || str_contains((string) $filter['value'], '%')) continue;
              $where[] = '`' . $filter['field'] . '` = ?'; $params[] = $filter['value'];
            }
            $choices = $this->db->getResults('SELECT ' . $select . ' FROM `' . $this->db->table('jet_cct_' . $name) . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY `' . $labelColumn . '`', $params);
          }
        }
      }
      if ($field['source'] !== '') $this->catalogs[$field['name']] = array_map(static fn(array $choice): array => array_merge($choice, ['value'=>(string) $choice['value'], 'label'=>(string) $choice['label']]), $choices);
    }
    return $this->catalogs;
  }

  public static function multiple(array $field): bool
  {
    return $field['multiple'] && !($field['source'] === 'glossary' && $field['glossary'] === 175);
  }

  public static function matches(array $groups, array $data): bool
  {
    foreach ($groups as $clauses) {
      $match = true;
      foreach ($clauses as $clause) {
        $actual = array_map('strval', (array) ($data[$clause['field']] ?? ''));
        $expected = array_map('trim', explode(',', (string) ($clause['value'] ?? '')));
        $intersects = array_intersect($actual, $expected) !== [];
        $match = $match && (in_array($clause['operator'], ['not_one_of','not_equal'], true) ? !$intersects : $intersects);
      }
      if ($match) return true;
    }
    return false;
  }

  public static function visible(array $field, array $data): bool
  {
    if (($field['reserved_only'] ?? false) && ($data['modo_ingreso'] ?? '') !== 'adelantado') return false;
    foreach ($field['when'] as $groups) if (!self::matches($groups, $data)) return false;
    return true;
  }

  public static function derived(array $data): array
  {
    foreach (self::definition()['derived'] as $name => $groups) {
      $data[$name] = '';
      foreach ($groups as $group) if (self::matches($group['when'], $data)) { $data[$name] = $group['value']; break; }
    }
    $data['edad_inmueble'] = isset($data['ano_construccion']) && is_numeric($data['ano_construccion']) ? (int) date('Y') - (int) $data['ano_construccion'] : '';
    return $data;
  }

  public function validate(int $taskId, array $input): array
  {
    $this->task($taskId);
    $mode = $input['modo_ingreso'] ?? '';
    if (!in_array($mode, ['nuevo','adelantado'], true)) throw new \InvalidArgumentException('Elige cómo ingresar el inmueble.');
    $data = ['modo_ingreso'=>$mode];
    $options = $this->options();
    foreach (self::definition()['fields'] as $field) {
      $name = $field['name'];
      if ($name === 'id_ticket') { $data[$name] = $taskId; continue; }
      if ($field['type'] === 'media') continue;
      $value = $input[$name] ?? (self::multiple($field) ? [] : '');
      if (self::multiple($field)) {
        if (!is_array($value) || count($value) > 100) throw new \InvalidArgumentException('Selección inválida en ' . $field['label'] . '.');
        foreach ($value as $item) if (!is_scalar($item)) throw new \InvalidArgumentException('Selección inválida.');
        $data[$name] = array_values(array_unique(array_map(static fn($item): string => trim(strip_tags((string) $item)), $value)));
      } else {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Valor inválido en ' . $field['label'] . '.');
        $data[$name] = trim(strip_tags((string) $value));
        if (mb_strlen($data[$name]) > ($field['type'] === 'textarea' ? 20000 : 1000)) throw new \InvalidArgumentException('El campo ' . $field['label'] . ' es demasiado largo.');
      }
    }
    $data = self::derived($data);
    foreach ($options['barrio'] ?? [] as $choice) {
      if ($choice['value'] === $data['barrio'] && ($choice['ciudad'] ?? '') === $data['ciudad'] && ($choice['pais'] ?? '') === $data['pais']) { $data['ruta'] = (string) ($choice['ruta_asignada'] ?? ''); break; }
    }
    foreach (self::definition()['fields'] as $field) {
      $name = $field['name'];
      if (!self::visible($field, $data)) { $data[$name] = self::multiple($field) ? [] : ''; continue; }
      $value = $data[$name] ?? '';
      if ($field['required'] && $field['type'] !== 'media' && ($value === '' || $value === [])) throw new \InvalidArgumentException('Completa ' . $field['label'] . '.');
      if ($value === '' || $value === [] || $name === 'id_ticket') continue;
      if ($field['source'] !== '') foreach ((array) $value as $item) if (!in_array((string) $item, array_column($options[$name] ?? [], 'value'), true)) throw new \InvalidArgumentException('Selecciona una opción válida en ' . $field['label'] . '.');
      if (in_array($field['type'], ['number','calculated'], true) && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 1e16)) throw new \InvalidArgumentException('Escribe un número válido en ' . $field['label'] . '.');
      if ($field['type'] === 'time' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', (string) $value)) throw new \InvalidArgumentException('Hora inválida en ' . $field['label'] . '.');
      if ($field['type'] === 'date' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $value) || date('Y-m-d', strtotime((string) $value) ?: 0) !== $value)) throw new \InvalidArgumentException('Fecha inválida.');
    }
    if (filter_var($data['ano_construccion'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1800,'max_range'=>(int) date('Y')]]) === false) throw new \InvalidArgumentException('Revisa el año de construcción.');
    $neighborhood = null;
    foreach ($options['barrio'] ?? [] as $choice) if ($choice['value'] === $data['barrio'] && ($choice['ciudad'] ?? '') === $data['ciudad'] && ($choice['pais'] ?? '') === $data['pais']) { $neighborhood = $choice; break; }
    if (!$neighborhood) throw new \InvalidArgumentException('El barrio debe corresponder a la ciudad y país seleccionados.');
    foreach (['departamento'=>'pais','ciudad'=>'departamento'] as $field => $parent) {
      $valid = false;
      foreach ($options[$field] ?? [] as $choice) if ($choice['value'] === $data[$field] && ($choice[$parent] ?? '') === $data[$parent]) $valid = true;
      if (!$valid) throw new \InvalidArgumentException('Revisa la relación entre país, departamento y ciudad.');
    }
    $data['ruta'] = (string) ($neighborhood['ruta_asignada'] ?? '');
    if ($data['ruta'] === '') throw new \InvalidArgumentException('El barrio no tiene una ruta configurada.');
    if ($data['latitud'] !== '' || $data['longitud'] !== '') {
      foreach (['latitud'=>90,'longitud'=>180] as $name => $limit) if (!is_numeric($data[$name]) || !is_finite((float) $data[$name]) || abs((float) $data[$name]) > $limit) throw new \InvalidArgumentException('Coordenadas del inmueble inválidas.');
    }
    $data['codigo_adelantado'] = $mode === 'adelantado' ? filter_var($input['codigo_adelantado'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : 0;
    if ($mode === 'adelantado' && !$data['codigo_adelantado']) throw new \InvalidArgumentException('Escribe el código adelantado existente.');
    if ($mode !== 'adelantado') $data['presenta-aviso'] = 'No';
    return self::derived($data);
  }

  private function lock(): string
  {
    return $this->db->pdo()->inTransaction() && $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
  }

  private function insert(string $name, array $data): int
  {
    $this->db->insert($this->db->table($name), array_intersect_key($data, array_flip($this->columns($name))));
    return (int) $this->db->lastInsertId();
  }

  private function update(string $name, array $data, array $where): void
  {
    $this->db->update($this->db->table($name), array_intersect_key($data, array_flip($this->columns($name))), $where);
  }

  private function meta(int $id, string $key): string
  {
    return (string) $this->db->getVar('SELECT meta_value FROM `' . $this->db->table('postmeta') . '` WHERE post_id=? AND meta_key=? LIMIT 1' . $this->lock(), [$id,$key]);
  }

  private function setMeta(int $id, string $key, $value): void
  {
    $value = is_array($value) ? serialize($value) : (string) $value;
    $exists = $this->db->getVar('SELECT meta_id FROM `' . $this->db->table('postmeta') . '` WHERE post_id=? AND meta_key=? LIMIT 1' . $this->lock(), [$id,$key]);
    if ($exists) $this->db->update($this->db->table('postmeta'), ['meta_value'=>$value], ['post_id'=>$id,'meta_key'=>$key]);
    else $this->db->insert($this->db->table('postmeta'), ['post_id'=>$id,'meta_key'=>$key,'meta_value'=>$value]);
  }

  public function save(int $taskId, array $input, ?callable $storeDocument = null): array
  {
    $this->task($taskId);
    $request = $input['request_id'] ?? '';
    if (!is_string($request) || !preg_match('/^[a-f0-9]{32}$/D', $request)) throw new \InvalidArgumentException('Recarga el formulario antes de guardar.');
    $receipt = $this->db->table('scm_property_entry_requests');
    $mysql = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (!$mysql || !$this->db->getVar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$receipt])) $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $receipt . '` (request_id VARCHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,ticket_id BIGINT NOT NULL,property_id BIGINT NOT NULL DEFAULT 0,created_at VARCHAR(19) NOT NULL)' . ($mysql ? ' ENGINE=InnoDB' : ''));
    $pdo = $this->db->pdo();
    $document = [];
    $pdo->beginTransaction();
    try {
      $existing = $this->db->getRow('SELECT * FROM `' . $receipt . '` WHERE request_id=?', [$request]);
      if ($existing) {
        if ((int) $existing['user_id'] !== Auth::userId() || (int) $existing['ticket_id'] !== $taskId) throw new CommercialSignAccessDenied('La solicitud pertenece a otra tarea o usuario.');
        $pdo->rollBack(); return $this->result((int) $existing['property_id']);
      }
      $this->db->insert($receipt, ['request_id'=>$request,'user_id'=>Auth::userId(),'ticket_id'=>$taskId,'created_at'=>gmdate('Y-m-d H:i:s')]);
      $task = $this->task($taskId);
      $data = $this->validate($taskId, $input);
      $employee = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID=?', [Auth::userId()]);
      if (!$employee || (string) $employee['id_empleado'] === '') throw new \RuntimeException('No se pudo identificar al funcionario.');
      $owner = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE _ID=?', [$data['id_propietario']]);
      if (!$owner) throw new \InvalidArgumentException('No se encontró el propietario.');
      $branch = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_sucursales') . '` WHERE _ID=?', [$employee['id_sucursal'] ?? '']) ?? [];
      $now = time(); $date = date('Y-m-d H:i:s'); $gmt = gmdate('Y-m-d H:i:s');
      $common = ['cct_status'=>'publish','cct_author_id'=>$employee['id_empleado'],'cct_created'=>$date,'cct_modified'=>$date];
      $code = (int) $data['codigo_adelantado'];
      $property = null;
      if ($code) {
        $post = $this->db->getRow('SELECT * FROM `' . $this->db->table('posts') . '` WHERE ID=? AND post_type=?' . $this->lock(), [$code,'inmuebles']);
        if (!$post || ($post['post_status'] ?? '') === 'trash') throw new \InvalidArgumentException('El código adelantado no corresponde a un inmueble existente.');
        $property = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE codigo=?' . $this->lock(), [$code]);
        $state = trim($this->meta($code, 'estado')) ?: (string) ($property['estado'] ?? '');
        if (!in_array($state, ['','En borrador','Codigo adelantado','Código adelantado'], true)) throw new \InvalidArgumentException('El inmueble ya está gestionado. Solo puedes completar códigos adelantados o en borrador.');
        $assigned = $this->meta($code, 'id_empleado') ?: (string) ($property['id_funcionario'] ?? $post['post_author'] ?? '');
        if (!$this->policy->canManage() && $assigned !== (string) $employee['id_empleado']) throw new CommercialSignAccessDenied('El código adelantado está asignado a otro funcionario.');
        $this->update('posts', ['post_title'=>$data['titulo'],'post_modified'=>$date,'post_modified_gmt'=>$gmt], ['ID'=>$code]);
      } else {
        $code = $this->insert('posts', ['post_author'=>$employee['id_empleado'],'post_date'=>$date,'post_date_gmt'=>$gmt,'post_content'=>'','post_title'=>$data['titulo'],'post_excerpt'=>'','post_status'=>'publish','comment_status'=>'closed','ping_status'=>'closed','post_password'=>'','post_name'=>'','to_ping'=>'','pinged'=>'','post_modified'=>$date,'post_modified_gmt'=>$gmt,'post_content_filtered'=>'','post_parent'=>0,'guid'=>'','menu_order'=>0,'post_type'=>'inmuebles','post_mime_type'=>'','comment_count'=>0]);
        $this->update('posts', ['post_name'=>'inmueble-' . $code,'guid'=>'https://sucasainmobiliaria.com.co/?post_type=inmuebles&p=' . $code], ['ID'=>$code]);
      }
      $data += ['fecha'=>$now,'id_empleado'=>$employee['id_empleado'],'empleado'=>$employee['nombre'],'correo_empleado'=>$employee['correo'] ?? '', 'sucursal'=>$employee['id_sucursal'] ?? '', 'comercial_correo'=>$branch['correo_comercial'] ?? '', 'comercial_celular'=>$branch['celular_comercial'] ?? '', 'inserted_inmuebles'=>$code];
      if ($storeDocument) { $document = $storeDocument(); $data['uso-suelo'] = $document['url'] ?? ''; }
      $definition = self::definition();
      $checkboxFields = [];
      foreach ($definition['fields'] as $field) if ($field['type'] === 'checkbox') $checkboxFields[] = $field['name'];
      foreach ($definition['post_map'] as $field => $key) {
        if (in_array($key, ['post_title','ID'], true) || $key === '' || str_starts_with($key, 'jet_tax__')) continue;
        if ($field === 'uso-suelo' && !$document) continue;
        $value = $data[$field] ?? '';
        if (in_array($field, $checkboxFields, true) && !is_array($value)) $value = $value === '' ? [] : [$value];
        $this->setMeta($code, $key, $value);
      }
      foreach ($definition['post_defaults'] as $default) $this->setMeta($code, $default['key'], $default['value']);
      $this->setMeta($code, 'presenta-aviso', $data['presenta-aviso']);
      $this->setMeta($code, 'id_empleado', $employee['id_empleado']);
      $this->setMeta($code, 'empleado', $employee['nombre']);
      $this->setMeta($code, 'email-empleado', $employee['correo'] ?? '');
      $this->setMeta($code, 'celular-empleado', $employee['celular'] ?? '');
      foreach (['id_propietario'=>'_ID','propietario'=>'nombre','documento-propietario'=>'documento','email-propietario'=>'correo','celular-propietario'=>'celular'] as $meta => $column) $this->setMeta($code, $meta, $owner[$column] ?? '');
      if ($data['procedencia'] === 'Club PPH') {
        $pph = $this->db->getRow('SELECT nombre,tarjeta_bienvenida FROM `' . $this->db->table('jet_cct_club_pph') . '` WHERE _ID=?', [$data['id_pph']]);
        if (!$pph) throw new \InvalidArgumentException('No se encontró el aliado PPH.');
        foreach (['id_pph'=>$data['id_pph'],'tarjeta-pph'=>$pph['tarjeta_bienvenida'],'nombre-pph'=>$pph['nombre']] as $meta => $value) $this->setMeta($code, $meta, $value);
      }
      $cct = [];
      foreach ($definition['cct_map'] as $field => $column) if ($column !== '' && $column !== '_ID') $cct[$column] = is_array($data[$field] ?? '') ? serialize($data[$field]) : ($data[$field] ?? '');
      $cct = array_merge($common, $cct, $definition['cct_defaults'], ['codigo'=>$code,'propietario'=>$owner['nombre'],'id_ticket'=>$taskId,'presenta_aviso'=>$data['presenta-aviso'],'referencia_catastral'=>$data['referencia_catastral'],'estrato'=>$data['estrato'],'procedencia'=>$data['procedencia']]);
      if ($property) { $propertyId = (int) $property['_ID']; unset($cct['cct_created']); $this->update('jet_cct_inmuebles', $cct, ['_ID'=>$propertyId]); }
      else $propertyId = $this->insert('jet_cct_inmuebles', $cct);
      $this->setMeta($code, 'id_inmueble_data', $propertyId);
      $this->taxonomies($code, $data);
      foreach ([941=>$employee['id_empleado'],658=>$owner['_ID']] as $relation => $parent) {
        $table = $this->db->table('jet_rel_' . $relation);
        if (!$this->db->getVar('SELECT _ID FROM `' . $table . '` WHERE parent_object_id=? AND child_object_id=?', [$parent,$code])) $this->insert('jet_rel_' . $relation, ['created'=>$date,'rel_id'=>$relation,'parent_rel'=>0,'parent_object_id'=>$parent,'child_object_id'=>$code]);
      }
      if ($data['tipo_captacion'] === 'Recaptacion') $this->insert('jet_cct_recaptaciones', $common + ['fecha'=>$now,'id_inmueble'=>$code,'id_empleado'=>$employee['id_empleado'],'id_ticket'=>$taskId,'empleado'=>$employee['nombre']]);
      $this->update('jet_cct_tickets', ['fecha_actualizacion'=>$now,'estado'=>'En proceso','estado_comercial'=>'Por publicar','id_inmueble'=>$code,'id_inmueble_data'=>$propertyId,'nombre_empleado'=>$employee['nombre'],'correo_empleado'=>$employee['correo'] ?? ''], ['_ID'=>$taskId]);
      $this->insert('jet_cct_historial_del_ticket', $common + ['fecha'=>$now,'id_ticket'=>$taskId,'id_empleado'=>$employee['id_empleado'],'nombre'=>$employee['nombre'],'correo'=>$employee['correo'] ?? '', 'celular'=>$employee['celular'] ?? '', 'id_inmueble'=>$code,'respuesta'=>'Cordial saludo, Señor (a) ' . EmailTemplate::e((string) $owner['nombre']) . '. Se ha remitido la información del inmueble ' . $code . '. Una vez que la publicidad esté lista, se enviará el código de verificación correspondiente.','fue_editada'=>'Si']);
      $this->insert('jet_cct_historial_del_inmueble', $common + ['fecha'=>$now,'id_ticket'=>$taskId,'id_inmueble'=>$code,'id_empleado'=>$employee['id_empleado'],'funcionario'=>$employee['nombre'],'tipo_reporte'=>'Captación','observacion'=>'Información del inmueble ingresada desde la tarea #' . $taskId . '.']);
      $this->emails($code, $taskId, $employee, $request);
      $this->db->update($receipt, ['property_id'=>$code], ['request_id'=>$request]);
      $pdo->commit();
      return $this->result($code);
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($document) \SCM\Support\StoredFileService::fromRuntime()->removeImages([$document['url']]);
      $existing = $this->db->getRow('SELECT * FROM `' . $receipt . '` WHERE request_id=?', [$request]);
      if ($existing && (int) $existing['user_id'] === Auth::userId() && (int) $existing['ticket_id'] === $taskId && (int) $existing['property_id'] > 0) return $this->result((int) $existing['property_id']);
      throw $exception;
    }
  }

  private function taxonomies(int $code, array $data): void
  {
    foreach (self::definition()['fields'] as $field) {
      if ($field['source'] !== 'terms') continue;
      $tt = $this->db->table('term_taxonomy'); $relations = $this->db->table('term_relationships');
      $old = $this->db->getCol('SELECT r.term_taxonomy_id FROM `' . $relations . '` r JOIN `' . $tt . '` t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=? AND t.taxonomy=?', [$code,$field['taxonomy']]);
      foreach ($old as $id) $this->db->delete($relations, ['object_id'=>$code,'term_taxonomy_id'=>$id]);
      $id = (int) $this->db->getVar('SELECT term_taxonomy_id FROM `' . $tt . '` WHERE term_id=? AND taxonomy=?', [$data[$field['name']],$field['taxonomy']]);
      $this->db->insert($relations, ['object_id'=>$code,'term_taxonomy_id'=>$id,'term_order'=>0]);
      foreach (array_unique([...$old,$id]) as $termId) {
        $count = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $relations . '` r JOIN `' . $this->db->table('posts') . '` p ON p.ID=r.object_id WHERE r.term_taxonomy_id=? AND p.post_status=?', [$termId,'publish']);
        $this->db->update($tt, ['count'=>$count], ['term_taxonomy_id'=>$termId]);
      }
    }
  }

  private function emails(int $code, int $taskId, array $employee, string $request): void
  {
    $recipients = explode(',', (string) ($this->config['property_entry_notification_emails'] ?? 'sucasacomercial@gmail.com,gcorrearivera@gmail.com,sc.dircomercial@gmail.com'));
    $recipients[] = (string) ($employee['correo'] ?? '');
    $subject = 'Nuevo inmueble #' . $code . ' agregado';
    $html = EmailTemplate::render($subject, '<p>' . EmailTemplate::e((string) $employee['nombre']) . ' ingresó el inmueble #' . $code . ' desde la tarea #' . $taskId . '.</p>');
    foreach (array_unique(array_map(static fn(string $email): string => strtolower(trim($email)), $recipients)) as $email) {
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
      $now = gmdate('Y-m-d H:i:s');
      $this->db->insert('skc_notification_queue', ['project_code'=>'control-servicios-comerciales','source_module'=>'commercial_property_entries','channel'=>'email','provider'=>'email_smtp','destination'=>$email,'destination_name'=>'','subject'=>$subject,'message_html'=>$html,'message_text'=>strip_tags($html),'payload_json'=>'{}','meta_json'=>json_encode(['property_entry'=>['id_inmueble'=>$code,'ticket_id'=>$taskId,'request_id'=>$request,'user_id'=>Auth::userId(),'employee_id'=>$employee['id_empleado']]], JSON_THROW_ON_ERROR),'status'=>'pending','priority'=>100,'max_attempts'=>3,'scheduled_at'=>$now,'created_at'=>$now,'updated_at'=>$now,'created_by'=>'control-servicios-comerciales','dedupe_key'=>'property_entry:' . $request . ':' . $code . ':' . $taskId . ':' . $email]);
    }
  }

  private function result(int $code): array
  {
    return ['id_inmueble'=>$code,'message'=>'Inmueble #' . $code . ' ingresado correctamente. La tarea quedó por publicar.','photos_url'=>'https://sucasainmobiliaria.com.co/mi-cuenta/fotos-inmuebles/?id_inmueble=' . $code];
  }
}
