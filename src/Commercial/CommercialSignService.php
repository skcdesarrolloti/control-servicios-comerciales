<?php

declare(strict_types=1);

namespace SCM\Commercial;

use PDO;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailTemplate;

/** Native counterpart of the two JetFormBuilder forms and their business hooks. */
final class CommercialSignService
{
  public function __construct(private Database $db, private CommercialAccessPolicy $policy, private array $config = []) {}

  public function authorize(): void
  {
    if (!$this->policy->canView('anadir_aviso') || !$this->policy->canAct('registrar_aviso')) {
      throw new CommercialSignAccessDenied('No tienes permiso para registrar avisos.');
    }
  }

  public function options(): array
  {
    $out = [];
    $fields = ['tipo_inmueble'=>783, 'categoria'=>784, 'promocionado_por'=>161, 'razon'=>940];
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $rows = $this->db->getResults('SELECT id, meta_fields FROM `' . $this->db->table('jet_post_types') . '` WHERE status = ? AND id IN (' . $placeholders . ')', ['glossary', ...array_values($fields)]);
    $glossaries = [];
    foreach ($rows as $row) {
      // JetEngine stores glossary choices as a serialized PHP array in meta_fields.
      $items = @unserialize((string) $row['meta_fields'], ['allowed_classes' => false]);
      if (is_array($items)) $glossaries[(int) $row['id']] = $items;
    }
    foreach ($fields as $field => $id) {
      $out[$field] = [];
      foreach ($glossaries[$id] ?? [] as $item) {
        if (!is_array($item) || !is_scalar($item['value'] ?? null) || (string) $item['value'] === '') continue;
        $value = (string) $item['value'];
        $label = $item['label'] ?? $value;
        if (!is_scalar($label)) continue;
        $out[$field][] = ['value'=>$value, 'label'=>(string) $label];
      }
    }
    $out['barrio'] = $this->db->getResults('SELECT _ID, barrio, ciudad, ruta_asignada FROM `' . $this->db->table('jet_cct_barrios') . '` ORDER BY barrio, ciudad');
    $out['competencia'] = $this->db->getResults('SELECT inmobiliaria FROM `' . $this->db->table('jet_cct_inmobiliarias') . '` ORDER BY inmobiliaria');
    return $out;
  }

  public function lookup(int $ticketId, string $code): array
  {
    $this->authorize();
    $ticket = $this->ticket($ticketId);
    if (!preg_match('/^[1-9][0-9]*$/D', $code)) throw new \InvalidArgumentException('Escribe un código de inmueble válido.');
    $property = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE codigo = ? LIMIT 1' . $this->writeLock(), [$code]);
    if (!$property) throw new \InvalidArgumentException('No se encontró el inmueble.');
    $linked = trim((string) ($ticket['id_inmueble'] ?? ''), " \t\n\r\0\x0B\"[]");
    if (ctype_digit($linked) && (int) $linked > 0 && $linked !== $code && $linked !== (string) $property['_ID']) {
      throw new \InvalidArgumentException('El inmueble no corresponde a la tarea seleccionada.');
    }
    return ['ticket'=>['_ID'=>$ticket['_ID'], 'asunto'=>$ticket['asunto'] ?? ''], 'property'=>array_intersect_key($property, array_flip(['codigo','direccion','barrio','ciudad','ruta','punto_referencia','tipo_inmueble','tipo_negocio','destinacion','ubicacion_google_maps']))];
  }

  public function taskContext(int $ticketId): array
  {
    $this->authorize();
    if (!$this->policy->canAct('ver_ticket')) throw new CommercialSignAccessDenied('No tienes permiso para ver esta tarea.');
    $ticket = $this->ticket($ticketId);
    $code = trim((string) ($ticket['id_inmueble'] ?? ''), " \t\n\r\0\x0B\"[]");
    if ((int) ($ticket['id_inmueble_data'] ?? 0) > 0) {
      $code = (string) ($this->db->getVar('SELECT codigo FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE _ID=?', [(int) $ticket['id_inmueble_data']]) ?? $code);
    }
    return ['id_ticket'=>$ticketId,'id_inmueble'=>ctype_digit($code) ? $code : ''];
  }

  private function ticket(int $id): array
  {
    if ($id < 1) throw new \InvalidArgumentException('Escribe el número de la tarea asignada.');
    $ticket = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE _ID = ? LIMIT 1' . $this->writeLock(), [$id]);
    if (!$ticket) throw new \InvalidArgumentException('No se encontró la tarea.');
    if (!$this->policy->canSeeAllTasks()) {
      $tickets = new CommercialTicketsRepository($this->db);
      if (!$tickets->ticketMatchesEmployeeFilter($id, $tickets->currentEmployeeTicketFilter())) {
        throw new CommercialSignAccessDenied('Esta tarea no está asignada a tu usuario.');
      }
    }
    return $ticket;
  }

  public function validate(array $input): array
  {
    $this->authorize();
    $data = [];
    foreach (['tipo_aviso','id_inmueble','punto_referencia','aviso_senuelo','pego_aviso','razon','barrio','barrio_id','tipo_inmueble','categoria','request_id'] as $key) {
      if (isset($input[$key]) && !is_scalar($input[$key])) throw new \InvalidArgumentException('El campo ' . $key . ' no es válido.');
      $data[$key] = trim(strip_tags((string) ($input[$key] ?? '')));
      if (mb_strlen($data[$key]) > ($key === 'punto_referencia' ? 1000 : 200)) throw new \InvalidArgumentException('El campo ' . $key . ' es demasiado largo.');
    }
    if (!in_array($data['tipo_aviso'], ['Nuevo','Retoque'], true)) throw new \InvalidArgumentException('Selecciona aviso nuevo o retoque.');
    if (!preg_match('/^[a-f0-9]{32}$/D', $data['request_id'])) throw new \InvalidArgumentException('Recarga el formulario antes de enviarlo.');
    $data['id_ticket'] = filter_var($input['id_ticket'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$data['id_ticket']) throw new \InvalidArgumentException('Escribe un número de tarea válido.');
    $lookup = $this->lookup($data['id_ticket'], $data['id_inmueble']);
    $property = $lookup['property'];
    $data['pego_aviso'] = $data['tipo_aviso'] === 'Nuevo' ? 'Si' : $data['pego_aviso'];
    foreach (['pego_aviso','aviso_senuelo'] as $field) {
      if (!in_array($data[$field], ['Si','No'], true)) throw new \InvalidArgumentException('Responde si pegaste aviso y aviso señuelo.');
    }
    $options = $this->options();
    if ($data['tipo_aviso'] === 'Retoque') {
      foreach (['barrio'=>'barrio','tipo_inmueble'=>'tipo_inmueble','categoria'=>'tipo_negocio'] as $field => $column) $data[$field] = trim((string) ($property[$column] ?? ''));
      $data['ruta'] = trim((string) ($property['ruta'] ?? ''));
    } else {
      $neighborhood = null;
      foreach ($options['barrio'] as $row) {
        if ((string) $row['_ID'] === $data['barrio_id']) { $neighborhood = $row; break; }
      }
      if (!$neighborhood) throw new \InvalidArgumentException('Selecciona un barrio del catálogo.');
      $data['barrio'] = (string) $neighborhood['barrio'];
      $data['ruta'] = trim((string) ($neighborhood['ruta_asignada'] ?? ''));
      foreach (['tipo_inmueble','categoria'] as $field) $this->choice($data[$field], $options[$field], $field);
    }
    foreach (['barrio','ruta','tipo_inmueble','categoria','punto_referencia'] as $field) {
      if ($data[$field] === '') throw new \InvalidArgumentException('Falta completar ' . str_replace('_', ' ', $field) . '.');
    }
    if ($data['pego_aviso'] === 'No') $this->choice($data['razon'], $options['razon'], 'motivo');
    else $data['razon'] = '';
    $data['cantidad'] = filter_var($input['cantidad'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>$data['pego_aviso'] === 'Si' ? 1 : 0, 'max_range'=>1000]]);
    if ($data['cantidad'] === false) throw new \InvalidArgumentException('Escribe una cantidad de avisos válida.');
    if ($data['pego_aviso'] === 'No') $data['cantidad'] = 0;
    foreach (['promocionado_por','competencia'] as $field) {
      $values = $input[$field] ?? [];
      if (!is_array($values) || count($values) > 100) throw new \InvalidArgumentException('Selección inválida en ' . $field . '.');
      foreach ($values as $value) if (!is_scalar($value)) throw new \InvalidArgumentException('Selección inválida.');
      $data[$field] = array_values(array_unique(array_map('strval', $values)));
    }
    foreach ($data['promocionado_por'] as $value) $this->choice($value, $options['promocionado_por'], 'promocionado por');
    if (array_intersect($data['promocionado_por'], ['Inmmobiliaria','Inmobiliaria']) === []) $data['competencia'] = [];
    foreach ($data['competencia'] as $value) {
      if (!in_array($value, array_column($options['competencia'], 'inmobiliaria'), true)) throw new \InvalidArgumentException('Selecciona una inmobiliaria del catálogo.');
    }
    foreach (['mapa_latitud'=>90, 'mapa_longitud'=>180, 'mapa_precision'=>100000] as $field => $limit) {
      $value = $input[$field] ?? '';
      if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > $limit || ($field === 'mapa_precision' && (float) $value < 0)) {
        throw new \InvalidArgumentException('Debes capturar una ubicación GPS válida antes de guardar.');
      }
      $data[$field] = (float) $value;
    }
    $data['unpublish'] = $data['tipo_aviso'] === 'Retoque' && $data['pego_aviso'] === 'No' && in_array($data['razon'], ['Arrendado','Vendido','No quiere promocion'], true);
    return $data;
  }

  private function choice(string $value, array $options, string $field): void
  {
    if (!in_array($value, array_column($options, 'value'), true)) throw new \InvalidArgumentException('Selecciona una opción válida en ' . $field . '.');
  }

  public function ensureRequestsTable(): void
  {
    if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $this->db->getVar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->db->table('scm_commercial_sign_requests')])) return;
    $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $this->db->table('scm_commercial_sign_requests') . '` (
      request_id VARCHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, id_aviso BIGINT NOT NULL DEFAULT 0,
      property_code VARCHAR(100) NOT NULL, created_at VARCHAR(19) NOT NULL
    )' . ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : ''));
  }

  /** All local business writes and queue entries commit together; retries reuse the receipt. */
  public function save(array $input, callable $storePhotos): array
  {
    $data = $this->validate($input);
    $this->ensureRequestsTable();
    $pdo = $this->db->pdo();
    $photos = [];
    $pdo->beginTransaction();
    try {
      $existing = $this->db->getRow('SELECT * FROM `' . $this->db->table('scm_commercial_sign_requests') . '` WHERE request_id = ?', [$data['request_id']]);
      if ($existing) {
        if ((int) $existing['user_id'] !== Auth::userId() || $existing['property_code'] !== $data['id_inmueble']) throw new \RuntimeException('La solicitud ya fue utilizada.');
        $pdo->rollBack();
        return $this->result((int) $existing['id_aviso']);
      }
      $this->db->insert($this->db->table('scm_commercial_sign_requests'), ['request_id'=>$data['request_id'],'user_id'=>Auth::userId(),'property_code'=>$data['id_inmueble'],'created_at'=>gmdate('Y-m-d H:i:s')]);
      // Lock the property before incrementing counters or synchronizing metadata.
      $property = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE codigo = ?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), [$data['id_inmueble']]);
      if (!$property) throw new \InvalidArgumentException('El inmueble ya no está disponible.');
      $data = $this->validate($input);
      $employee = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
      if (!$employee || trim((string) $employee['id_empleado']) === '') throw new \RuntimeException('No se pudo identificar al funcionario.');
      $author = (string) $employee['id_empleado'];
      $now = time();
      $common = ['cct_status'=>'publish','cct_author_id'=>$author,'cct_created'=>date('Y-m-d H:i:s'),'cct_modified'=>date('Y-m-d H:i:s')];
      $reportValue = $data['tipo_aviso'] === 'Retoque' && $data['pego_aviso'] === 'Si' ? $this->reportValue($author) : 0;
      $photos = $storePhotos();
      if (!is_array($photos) || count($photos) < 1 || count($photos) > 3) throw new \InvalidArgumentException('Adjunta entre una y tres fotografías de evidencia.');
      $record = array_intersect_key($data, array_flip(['tipo_aviso','id_inmueble','id_ticket','ruta','barrio','punto_referencia','tipo_inmueble','categoria','cantidad','pego_aviso','razon','aviso_senuelo']));
      $record += ['fecha'=>$now,'creador'=>(string) $employee['nombre'],'id_empleado'=>$author,'sucursal'=>(string) ($employee['id_sucursal'] ?? ''),'fotos'=>implode(',', $photos),'promocionado_por'=>serialize($data['promocionado_por']),'competencia'=>serialize($data['competencia'])];
      $this->db->insert($this->db->table('jet_cct_retoques'), $record + $common);
      $id = (int) $this->db->lastInsertId();
      $meta = ['punto-referencia'=>$data['punto_referencia'],'presenta-aviso'=>$data['pego_aviso']];
      $update = ['punto_referencia'=>$data['punto_referencia'],'presenta_aviso'=>$data['pego_aviso']];
      if ($data['tipo_aviso'] === 'Nuevo') {
        $meta['fecha-aviso'] = $now;
        $update['fecha_aviso'] = $now;
      } else {
        $meta += ['avisos'=>(int) $this->postMeta($data['id_inmueble'], 'avisos') + 1,'desea-aviso'=>$data['pego_aviso'],'retocado'=>$data['pego_aviso'],'fecha-retoque'=>$now];
        $update += ['fecha_actualizacion'=>$now,'fecha_retoque'=>$now,'desea_aviso'=>$data['pego_aviso'],'fue_retocado'=>$data['pego_aviso']];
      }
      $maps = 'https://www.google.com/maps?q=' . $data['mapa_latitud'] . ',' . $data['mapa_longitud'];
      $update['ubicacion_google_maps'] = $maps;
      $this->db->insert($this->db->table('skc_ubicacion_inmuebles_mapa'), [
        'codigo_inmueble'=>$data['id_inmueble'],'barrio'=>$data['barrio'],'ruta'=>$data['ruta'],
        'latitud'=>$data['mapa_latitud'],'longitud'=>$data['mapa_longitud'],'precision_metros'=>$data['mapa_precision'],
        'fuente'=>'gps','google_maps_url'=>$maps,'page_url'=>rtrim((string) ($this->config['base_url'] ?? ''), '/') . '/index.php?tab=anadir_aviso',
        'user_agent'=>substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),'created_by'=>$author,'cct_created'=>date('Y-m-d H:i:s'),
      ]);
      if ($data['unpublish']) {
        [$state, $reason] = match ($data['razon']) {
          'Arrendado'=>['Arrendado','Arrendo de forma directa'], 'Vendido'=>['Vendido','Vendio de forma directa'], default=>['Desistido','No quiere promoción'],
        };
        $meta += ['fecha-despublicado'=>$now,'observacion-despublicacion'=>$reason,'estado'=>$state];
        $update['estado'] = $state;
        $this->db->insert($this->db->table('jet_cct_despublicaciones'), $common + ['fecha'=>$now,'id_inmueble'=>$data['id_inmueble'],'estado'=>$state,'observacion_despublicacion'=>$reason,'actualizaciones'=>$this->postMeta($data['id_inmueble'], 'actualizaciones'),'id_empleado'=>$author,'empleado'=>$employee['nombre'],'id_empleado_dueno'=>$this->postMeta($data['id_inmueble'], 'id_empleado'),'tipo_publicacion'=>$property['tipo_inmueble'],'categoria_publicacion'=>$property['tipo_negocio'],'destinacion_publicacion'=>$property['destinacion']]);
        $this->history($common, $data, $employee, $now, 'Despublicado', 'El inmueble ha sido despublicado.');
      }
      foreach ($meta as $key => $value) $this->setPostMeta($data['id_inmueble'], $key, (string) $value);
      $this->db->update($this->db->table('jet_cct_inmuebles'), $update, ['codigo'=>$data['id_inmueble']]);
      $message = $data['tipo_aviso'] === 'Nuevo' ? 'Hemos colocado el aviso con el fin de promocionar el inmueble y agilizar la gestión del mismo.' : ($data['pego_aviso'] === 'Si' ? 'Hemos retocado el aviso promocional con el fin de continuar con el proceso.' : 'No se pegó aviso. Motivo: ' . $data['razon'] . '.');
      $ticketMessage = $data['tipo_aviso'] === 'Nuevo' ? 'Estimado Propietario nos permitimos comunicarle que hemos colocado el aviso con el fin de promocionar el inmueble y agilizar la gestión del mismo.' : ($data['pego_aviso'] === 'Si' ? 'Se ha agregado retoque de aviso en la ruta que me fue asignada.' : $message);
      $this->history($common, $data, $employee, $now, $data['tipo_aviso'] === 'Nuevo' ? 'Aviso' : 'Retoque', $message);
      $ticketUpdate = ['fecha_actualizacion'=>$now];
      if ($data['tipo_aviso'] === 'Retoque') $ticketUpdate += ['estado'=>'En proceso','estado_comercial'=>'Retocando'];
      $this->db->update($this->db->table('jet_cct_tickets'), $ticketUpdate, ['_ID'=>$data['id_ticket']]);
      $this->db->insert($this->db->table('jet_cct_historial_del_ticket'), $common + ['fecha'=>$now,'id_ticket'=>$data['id_ticket'],'id_aviso'=>$id,'id_inmueble'=>$data['id_inmueble'],'id_empleado'=>$author,'nombre'=>$employee['nombre'],'correo'=>$employee['correo'] ?? '', 'celular'=>$employee['celular'] ?? '', 'respuesta'=>$ticketMessage,'fue_editada'=>'No']);
      if ($data['tipo_aviso'] === 'Retoque' && $data['pego_aviso'] === 'Si') {
        $this->db->insert($this->db->table('jet_cct_reportes_avisos'), $common + ['fecha'=>$now,'tipo_reporte'=>'Retoque','observacion'=>'Se ha agregado un retoque de aviso al inmueble <b>' . $data['id_inmueble'] . '</b> con evidencia <a href="https://sucasainmobiliaria.com.co/mi-cuenta/aviso/?id_aviso=' . $id . '" class="link" target="_blank" rel="noopener noreferrer">Ver aviso</a>','cantidad_avisos'=>1,'valor'=>$reportValue,'funcionario'=>$employee['nombre'],'fue_pagado'=>'No','id_empleado'=>$author,'id_inmueble'=>$data['id_inmueble'],'id_aviso'=>$id,'id_ticket'=>$data['id_ticket'],'sucursal'=>$record['sucursal'],'exportado'=>'No']);
      }
      $this->enqueueEmails($id, $data, $employee);
      $this->db->update($this->db->table('scm_commercial_sign_requests'), ['id_aviso'=>$id], ['request_id'=>$data['request_id']]);
      $pdo->commit();
      return $this->result($id);
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // A concurrent retry may have won the unique receipt insert.
      $existing = $this->db->getRow('SELECT * FROM `' . $this->db->table('scm_commercial_sign_requests') . '` WHERE request_id = ?', [$data['request_id']]);
      if ($existing && (int) $existing['user_id'] === Auth::userId() && $existing['property_code'] === $data['id_inmueble'] && (int) $existing['id_aviso'] > 0) return $this->result((int) $existing['id_aviso']);
      if ($photos) \SCM\Support\StoredFileService::fromRuntime()->removeImages($photos);
      throw $exception;
    }
  }

  private function reportValue(string $employee): float
  {
    $values = [];
    foreach ($this->db->getResults('SELECT funcion, valor FROM `' . $this->db->table('jet_cct_confi_sistema') . '` WHERE funcion IN (?,?,?)', ['valor_aviso','valor_transporte','factor_prestacional']) as $row) $values[$row['funcion']] = $row['valor'];
    foreach ($employee === '2038' ? ['valor_aviso'] : ['valor_transporte','factor_prestacional'] as $key) {
      if (!is_numeric($values[$key] ?? null) || !is_finite((float) $values[$key]) || (float) $values[$key] < 0) throw new \RuntimeException('Revisa la configuración de ' . $key . ' antes de registrar el retoque.');
    }
    if ($employee === '2038') return (float) $values['valor_aviso'];
    if ((float) $values['factor_prestacional'] <= 0) throw new \RuntimeException('El factor prestacional debe ser mayor que cero.');
    return ((float) $values['valor_transporte'] / 2) / (float) $values['factor_prestacional'];
  }

  private function postMeta(string $code, string $key): string
  {
    return (string) $this->db->getVar('SELECT meta_value FROM `' . $this->db->table('postmeta') . '` WHERE post_id = ? AND meta_key = ? LIMIT 1' . $this->writeLock(), [$code, $key]);
  }

  private function writeLock(): string
  {
    return $this->db->pdo()->inTransaction() && $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
  }

  private function setPostMeta(string $code, string $key, string $value): void
  {
    $table = $this->db->table('postmeta');
    if ($this->db->getVar("SELECT meta_id FROM `{$table}` WHERE post_id = ? AND meta_key = ? LIMIT 1" . $this->writeLock(), [$code,$key])) $this->db->update($table, ['meta_value'=>$value], ['post_id'=>$code,'meta_key'=>$key]);
    else $this->db->insert($table, ['post_id'=>$code,'meta_key'=>$key,'meta_value'=>$value]);
  }

  private function history(array $common, array $data, array $employee, int $now, string $type, string $message): void
  {
    $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), $common + ['fecha'=>$now,'id_inmueble'=>$data['id_inmueble'],'id_ticket'=>$data['id_ticket'],'id_empleado'=>$employee['id_empleado'],'funcionario'=>$employee['nombre'],'tipo_reporte'=>$type,'observacion'=>$message]);
  }

  private function enqueueEmails(int $id, array $data, array $employee): void
  {
    $recipients = explode(',', (string) ($this->config['sign_notification_emails'] ?? 'gcorrearivera@gmail.com,sc.dircomercial@gmail.com,sucasacomercial@gmail.com,sucasacorreos@gmail.com,nabuita1@gmail.com'));
    $recipients[] = (string) ($employee['correo'] ?? '');
    $subject = 'Aviso ' . $data['tipo_aviso'] . ' #' . $id . ' agregado';
    $url = 'https://sucasainmobiliaria.com.co/mi-cuenta/aviso/?id_aviso=' . $id;
    $html = EmailTemplate::render($subject, '<p>El funcionario <strong>' . EmailTemplate::e((string) $employee['nombre']) . '</strong> registró un aviso ' . EmailTemplate::e($data['tipo_aviso']) . ' para el inmueble <strong>#' . EmailTemplate::e($data['id_inmueble']) . '</strong>.</p><p>¿Pegó aviso? ' . EmailTemplate::e($data['pego_aviso']) . ($data['razon'] ? '. Motivo: ' . EmailTemplate::e($data['razon']) : '') . '</p><p><a href="' . EmailTemplate::e($url) . '">Ver aviso y evidencia</a></p>');
    foreach (array_unique(array_map(static fn(string $email): string => strtolower(trim($email)), $recipients)) as $email) {
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
      $now = gmdate('Y-m-d H:i:s');
      $this->db->insert('skc_notification_queue', [
        'project_code'=>'control-servicios-comerciales','source_module'=>'commercial_signs','channel'=>'email','provider'=>'email_smtp',
        'destination'=>$email,'destination_name'=>'','subject'=>$subject,'message_html'=>$html,'message_text'=>strip_tags($html),
        'payload_json'=>json_encode(['reply_to'=>(string) ($employee['correo'] ?? '')], JSON_THROW_ON_ERROR),'meta_json'=>json_encode(['commercial_signs'=>['id_aviso'=>$id,'id_inmueble'=>$data['id_inmueble'],'user_id'=>Auth::userId(),'employee_id'=>$employee['id_empleado']]], JSON_THROW_ON_ERROR),
        'status'=>'pending','priority'=>100,'max_attempts'=>3,'scheduled_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
        'created_by'=>'control-servicios-comerciales','dedupe_key'=>'commercial_sign:' . $id . ':' . $email,
      ]);
    }
  }

  private function result(int $id): array
  {
    return ['id_aviso'=>$id,'message'=>'Aviso #' . $id . ' registrado correctamente.'];
  }
}
