<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;
use SCM\Support\EmailTemplate;

/** Reutiliza búsqueda, contratos y preferencias del módulo inmobiliario. */
final class CommercialNotificationsService extends AdministrativeNotificationsService
{
  public const PROJECT_CODE = 'control-servicios-comerciales';
  public const SOURCE_MODULE = 'commercial_notifications';
  public const TEMPLATE_BODY = "Hola {{1}}, recibe un cordial saludo de SKC SuCasa Inmobiliaria.\n\nTe compartimos la siguiente información:\n{{2}}\n\nAtentamente,\n{{3}}\n\nGracias por confiar en SKC SuCasa Inmobiliaria.";

  private CommercialAccessPolicy $policy;
  /** @var array<string,string> */
  private array $media = [];
  private string $requestId = '';
  private bool $locked = false;
  private ?string $employeeId = null;
  private string $messageText = '';

  private function currentEmployeeId(): string
  {
    if ($this->employeeId === null) {
      $this->employeeId = trim(Auth::employeeId());
      if ($this->employeeId === '') {
        $this->employeeId = trim((string) $this->db->getVar('SELECT `id_empleado` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ?', [Auth::userId()]));
      }
    }
    return $this->employeeId;
  }

  protected function isBlockedByPreference(array $recipient, string $channel, string $type): bool
  {
    if (parent::isBlockedByPreference($recipient, $channel, $type)) {
      return true;
    }
    $config = $this->types()[$type];
    $table = (string) $config['table'];
    // Los mensajes genéricos de WhatsApp se registran como Marketing en Meta.
    $columns = ['permite_notif_' . $channel];
    if ($channel === 'whatsapp') {
      $columns[] = 'permite_marketing_whatsapp';
    }
    foreach ($columns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $value = $this->db->getVar("SELECT `{$column}` FROM `{$table}` WHERE `_ID` = ?", [(int) $recipient['_ID']]);
        if ($value !== null && trim((string) $value) !== '' && in_array(strtolower(trim((string) $value)), ['0', 'no', 'false'], true)) {
          return true;
        }
      }
    }
    return false;
  }

  public function __construct(Database $db, CommercialAccessPolicy $policy)
  {
    parent::__construct($db);
    $this->policy = $policy;
    $this->schema->preloadColumns(array_map(fn(string $table): string => $db->table($table), [
      'jet_cct_propietarios', 'jet_cct_arrendatarios', 'jet_cct_copropiedades', 'jet_cct_club_pph',
      'jet_cct_inmuebles', 'jet_cct_contratos_arrendamiento', 'jet_cct_funcionarios', 'jet_cct_cargos',
    ]));
  }

  public function types(): array
  {
    $original = parent::types();
    $types = [];
    foreach (['propietarios_activos', 'propietarios_no_activos', 'arrendatarios_activos', 'arrendatarios_no_activos'] as $key) {
      $types[$key] = $original[$key];
    }
    $types['copropiedades'] = $original['copropiedades_activas'];
    unset($types['copropiedades']['contract_status_fixed']);
    $types['copropiedades']['label'] = 'Copropiedades';
    $types['club_pph'] = [
      'label' => 'Club PPH', 'role' => 'Club PPH', 'table' => $this->db->table('jet_cct_club_pph'),
      'name' => ['nombre'], 'email' => ['correo'], 'phone' => ['telefono'], 'indicator' => ['indicativo'],
      'search' => ['nombre', 'documento', 'correo', 'telefono', 'nombre_copropiedad', 'municipio', 'tarjeta_bienvenida'],
    ];
    return $types;
  }

  /** Acceso al actor con el mismo alcance de los destinatarios; los IDs internos no se editan. */
  public function actorForEditing(string $type, int $id): array
  {
    $config = $this->types()[$type] ?? null;
    if ($config === null || !$this->policy->canView('notificaciones')) {
      throw new \RuntimeException('El actor no está disponible.');
    }
    $table = (string) $config['table'];
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE (" . $this->baseWhere($config) . ') AND `_ID` = ?', [$id]);
    if ($row === null) { throw new \RuntimeException('El actor no está disponible dentro de tu alcance.'); }
    return ['config' => $config, 'row' => $row];
  }

  /** @return array<string,array{name:string,label:string,language:string,description:string,body:string,variables:array<int,string>,header_type:string}> */
  public function whatsappTemplates(): array
  {
    $out = [];
    foreach (['texto' => 'Texto', 'imagen' => 'Imagen', 'documento' => 'Documento PDF', 'video' => 'Video'] as $kind => $label) {
      $name = 'scm_marketing_generica_' . $kind . '_v1';
      $out[$name] = [
        'name' => $name, 'label' => $label, 'language' => 'es_CO', 'body' => self::TEMPLATE_BODY,
        'description' => 'Mensaje comercial genérico con saludo y firma del funcionario.',
        'header_type' => ['texto' => '', 'imagen' => 'image', 'documento' => 'document', 'video' => 'video'][$kind],
        'parameter_mode' => 'name_message_signature', 'actors' => [],
        'variables' => ['Nombre del destinatario', 'Mensaje', 'Nombre del funcionario - Cargo - Celular'],
      ];
    }
    return $out;
  }

  public function emailTemplates(): array
  {
    return [self::DEFAULT_EMAIL_TEMPLATE => [
      'name' => self::DEFAULT_EMAIL_TEMPLATE, 'label' => 'Mensaje general', 'subject' => 'Información de SKC SuCasa Inmobiliaria',
      'body' => '<p>Hola <strong>{{nombre}}</strong>, recibe un cordial saludo de SKC SuCasa Inmobiliaria.</p><div>{{mensaje}}</div><p>Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
      'message_only' => false, 'editable_message' => '', 'source' => 'commercial',
      'description' => 'Mensaje con saludo y firma personalizada del funcionario.',
    ]];
  }

  public function search(string $type, string $query, int $page = 1, int $perPage = 20, string $contractStatus = '', string $inmuebleSimi = '', string $contractNumber = ''): array
  {
    $result = parent::search($type, $query, $page, $perPage, $contractStatus, $inmuebleSimi, $contractNumber);
    foreach ($result['rows'] as &$row) {
      // La interfaz y el envío usan la misma validación; no requiere consultas adicionales.
      $row['available_channels'] = array_values(array_filter(['whatsapp', 'email', 'sms'], fn(string $channel): bool => $this->destination($row, $channel) !== ''));
    }
    unset($row);
    return $result;
  }

  /** El envío y la vista previa comparten el mismo documento y banner de correo. @param array<string,string> $media */
  public function emailDocument(string $name, string $subject, string $message, array $media = []): string
  {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content = '<p>Hola <strong>' . $escape($name) . '</strong>, recibe un cordial saludo de SKC SuCasa Inmobiliaria.</p><div>'
      . nl2br($escape($message)) . '</div><p>Atentamente,<br><strong>' . $escape($this->senderProfile()['signature_line']) . '</strong></p>';
    if ($media !== []) {
      $content .= '<p><a href="' . $escape($media['url']) . '">Ver archivo: ' . $escape($media['name']) . '</a></p>';
    }
    return EmailTemplate::render($subject, $content);
  }

  /** Todos los accesos (búsqueda, estadísticas, selección total y envío) usan esta misma regla. */
  protected function baseWhere(array $config): string
  {
    $where = parent::baseWhere($config);
    if ($this->policy->canManage()) {
      return $where;
    }
    $employee = $this->currentEmployeeId();
    if ($employee === '') {
      return '1=0';
    }
    $id = $this->db->pdo()->quote($employee);
    $table = (string) $config['table'];
    $links = [];
    // cct_author_id representa id_empleado, nunca el _ID interno de la sesión.
    if ($this->schema->columnExists($table, 'cct_author_id')) {
      $links[] = "CAST(`{$table}`.`cct_author_id` AS CHAR) = {$id}";
    }
    if ($table === $this->db->table('jet_cct_club_pph')) {
      if ($this->schema->columnExists($table, 'id_empleado')) {
        $links[] = "TRIM(`{$table}`.`id_empleado`) = {$id}";
      }
    } else {
      $actorColumn = (string) ($config['contract_actor_column'] ?? '');
      foreach (['jet_cct_inmuebles' => 'id_funcionario', 'jet_cct_contratos_arrendamiento' => 'id_empleado'] as $entity => $employeeColumn) {
        $linkedTable = $this->db->table($entity);
        if (!$this->schema->columnExists($linkedTable, $actorColumn) || !$this->schema->columnExists($linkedTable, $employeeColumn)) {
          continue;
        }
        $refs = ["CAST(`{$table}`.`_ID` AS CHAR)"];
        if ($this->schema->columnExists($table, $actorColumn)) {
          $refs[] = "NULLIF(TRIM(`{$table}`.`{$actorColumn}`), '')";
        }
        $links[] = "EXISTS (SELECT 1 FROM `{$linkedTable}` nlink WHERE TRIM(nlink.`{$employeeColumn}`) = {$id} AND TRIM(nlink.`{$actorColumn}`) <> '' AND TRIM(nlink.`{$actorColumn}`) IN (" . implode(',', $refs) . '))';
      }
    }
    return $where . ' AND (' . ($links === [] ? '1=0' : implode(' OR ', $links)) . ')';
  }

  protected function contractActivityInfo(string $type, array $config, array $recipient, string $contractStatus = '', string $inmuebleSimi = '', string $contractNumber = ''): array
  {
    // La lista necesita el estado de la categoría; evita una consulta de contratos por cada contacto.
    $status = (string) ($config['contract_status_fixed'] ?? '');
    return ['label' => ['activos' => 'Activo', 'no_activos' => 'No activo'][$status] ?? '', 'summary' => ''];
  }

  /** @param array<string,string> $media */
  public function prepareDelivery(string $requestId, array $media): void
  {
    if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
      throw new \InvalidArgumentException('Identificador de envío inválido. Recarga el módulo.');
    }
    $this->requestId = $requestId;
    $this->media = $media;
  }

  public function enqueue(string $type, array $ids, array $channels, string $subject, string $message, string $whatsappTemplate = '', string $emailTemplate = '', array $recipientMetaMap = []): array
  {
    if ($this->requestId === '') {
      throw new \InvalidArgumentException('Falta el identificador del envío.');
    }
    $sender = $this->senderProfile();
    if ($this->currentEmployeeId() === '' || $sender['phone'] === '' || $sender['name'] === '' || $sender['cargo'] === '') {
      throw new \InvalidArgumentException('Completa nombre, cargo y celular de tu funcionario antes de enviar.');
    }
    if (mb_strlen(trim($message)) > 700) {
      throw new \InvalidArgumentException('El mensaje admite hasta 700 caracteres.');
    }
    if (in_array('sms', $channels, true) && mb_strlen(CommercialSmsMessage::PREFIX . trim($message), 'UTF-8') > CommercialSmsMessage::MAX_CHARACTERS) {
      throw new \InvalidArgumentException('El SMS supera 160 caracteres, incluido el prefijo SKC SuCasa Inmobiliaria. Acorta el mensaje o desmarca SMS.');
    }
    $template = $this->whatsappTemplates()[$whatsappTemplate] ?? [];
    if (in_array('whatsapp', $channels, true) && !empty($template['header_type']) && ($this->media['type'] ?? '') !== $template['header_type']) {
      throw new \InvalidArgumentException('Adjunta el archivo requerido por la plantilla de WhatsApp.');
    }
    $this->messageText = trim($message);
    // La cola compartida tiene un índice de dedupe no único. El bloqueo serializa reintentos del mismo envío.
    $lock = 'scm-notif:' . substr(hash('sha256', $this->currentEmployeeId() . ':' . $this->requestId), 0, 40);
    if ((int) $this->db->getVar('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
      throw new \RuntimeException('Este envío está en proceso. Espera y vuelve a consultar.');
    }
    $this->locked = true;
    try {
      return parent::enqueue($type, $ids, $channels, $subject, htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $whatsappTemplate, self::DEFAULT_EMAIL_TEMPLATE);
    } finally {
      $this->locked = false;
      $this->db->getVar('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  protected function insertQueueRow(string $channel, string $destination, array $recipient, string $subject, string $message, string $batchId, array $whatsappTemplateConfig = [], array $emailTemplateConfig = []): bool
  {
    if (!$this->locked) {
      throw new \LogicException('El envío necesita un bloqueo de deduplicación.');
    }
    $sender = $this->senderProfile();
    $name = trim((string) $recipient['nombre']);
    $signature = $sender['signature_line'];
    $components = [];
    if ($channel === 'whatsapp' && !empty($whatsappTemplateConfig['header_type'])) {
      $mediaType = (string) $whatsappTemplateConfig['header_type'];
      $headerMedia = ['link' => $this->media['url']];
      if ($mediaType === 'document') {
        $headerMedia['filename'] = $this->media['name'];
      }
      $components[] = ['type' => 'header', 'parameters' => [['type' => $mediaType, $mediaType => $headerMedia]]];
    }
    $plain = html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8');
    if ($channel === 'whatsapp') {
      $components[] = ['type' => 'body', 'parameters' => array_map(static fn(string $text): array => [
        'type' => 'text', 'text' => trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
      ], [$name, $plain, $signature])];
    }
    $templateName = $channel === 'whatsapp' ? (string) $whatsappTemplateConfig['name'] : '';
    $payload = $channel === 'whatsapp' ? ['type' => 'template', 'template_name' => $templateName, 'template_language' => 'es_CO', 'components' => $components] : [];
    $dedupe = implode(':', [self::PROJECT_CODE, $this->requestId, $this->currentEmployeeId(), $channel, $recipient['tipo_actor'], $recipient['_ID']]);
    if ($this->db->getVar('SELECT `id` FROM `' . self::QUEUE_TABLE . '` WHERE `dedupe_key` = ? LIMIT 1', [$dedupe])) {
      return true;
    }
    $text = $channel === 'whatsapp' ? strtr(self::TEMPLATE_BODY, ['{{1}}' => $name, '{{2}}' => $plain, '{{3}}' => $signature]) : $plain;
    if ($channel === 'whatsapp' && mb_strlen($text) > 1024) {
      error_log('[commercial-notifications:enqueue] El mensaje personalizado supera 1024 caracteres.');
      return false;
    }
    if ($channel === 'email') {
      $text = "Hola {$name}, recibe un cordial saludo de SKC SuCasa Inmobiliaria.\n\n{$this->messageText}\n\nAtentamente,\n{$signature}";
      $message = $this->emailDocument($name, $subject, $this->messageText, $this->media);
    }
    $now = gmdate('Y-m-d H:i:s');
    $data = [
      'project_code' => self::PROJECT_CODE, 'source_module' => self::SOURCE_MODULE,
      'channel' => $channel, 'provider' => ['email' => 'email_smtp', 'sms' => 'sms_onurix', 'whatsapp' => 'whatsapp_official'][$channel],
      'destination' => $destination, 'destination_name' => $name, 'subject' => $channel === 'email' ? $subject : '',
      'message_text' => $text, 'message_html' => $channel === 'email' ? $message : '',
      'template_name' => $templateName, 'template_language' => $channel === 'whatsapp' ? 'es_CO' : '',
      'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'meta_json' => json_encode(['commercial_notifications' => [
        'batch_id' => $this->requestId, 'id_actor' => (int) $recipient['_ID'], 'tipo_actor' => $recipient['tipo_actor'],
        'employee_id' => $this->currentEmployeeId(), 'nombre_funcionario' => $sender['name'], 'cargo' => $sender['cargo'], 'celular' => $sender['phone'],
        'user_id' => Auth::userId(), 'media' => $this->media,
      ], 'sms' => $channel === 'sms' ? CommercialSmsMessage::metrics($text) : null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      'status' => 'pending', 'priority' => 100, 'max_attempts' => 3, 'scheduled_at' => $now,
      'created_at' => $now, 'updated_at' => $now, 'created_by' => self::PROJECT_CODE, 'dedupe_key' => $dedupe,
    ];
    try {
      return $this->db->insert(self::QUEUE_TABLE, $data);
    } catch (\Throwable $exception) {
      error_log('[commercial-notifications:enqueue] ' . $exception->getMessage());
      return false;
    }
  }

  /** Historial limitado al proyecto, módulo y funcionario que realizó el envío. */
  public function notificationQueue(array $filters = []): array
  {
    [$where, $args] = $this->historyScope();
    $where .= ' AND ' . $this->metadataField('deleted_at') . ' IS NULL';
    $status = (string) ($filters['status'] ?? '');
    $statuses = ['pending', 'processing', 'sent', 'failed', 'cancelled'];
    $counts = array_fill_keys($statuses, 0);
    foreach ($this->db->getResults('SELECT `status`,COUNT(*) AS n FROM `' . self::QUEUE_TABLE . "` WHERE {$where} GROUP BY `status`", $args) as $row) {
      if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['n'];
      }
    }
    if (in_array($status, $statuses, true)) {
      $where .= ' AND `status` = ?';
      $args[] = $status;
    }
    $total = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . self::QUEUE_TABLE . "` WHERE {$where}", $args);
    $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil($total / 20)));
    $rows = $this->db->getResults('SELECT `id`,`destination_name`,`destination`,`channel`,`template_name`,`status`,`attempts`,`created_at`,`sent_at`,`last_error`,' . $this->creatorColumns() . ' FROM `' . self::QUEUE_TABLE . "` WHERE {$where} ORDER BY `id` DESC LIMIT 20 OFFSET ?", [...$args, ($page - 1) * 20]);
    return ['rows' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
  }

  /** La identidad es una captura del funcionario al crear el mensaje, no del worker. */
  private function metadataField(string $field): string
  {
    return "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`meta_json`) THEN `meta_json` ELSE '{}' END, '$.commercial_notifications.{$field}'))";
  }

  private function creatorColumns(): string
  {
    return $this->metadataField('employee_id') . ' AS employee_id,'
      . $this->metadataField('user_id') . ' AS creator_user_id,'
      . "COALESCE(NULLIF(" . $this->metadataField('nombre_funcionario') . ", ''), 'Sin autor registrado') AS creator_name,"
      . $this->metadataField('cargo') . ' AS creator_cargo';
  }

  /** @return array{0:string,1:array<int,mixed>} */
  private function historyScope(): array
  {
    $where = '`project_code` = ? AND `source_module` = ?';
    $args = [self::PROJECT_CODE, self::SOURCE_MODULE];
    if (!$this->policy->canManage()) {
      $where .= ' AND ' . $this->metadataField('employee_id') . ' = ?';
      $args[] = $this->currentEmployeeId();
      if ($this->currentEmployeeId() === '') {
        $where .= ' AND 1=0';
      }
    }
    return [$where, $args];
  }

  /** @return array<string,mixed>|null */
  public function notificationDetail(int $id): ?array
  {
    [$where, $args] = $this->historyScope();
    $row = $this->db->getRow('SELECT `id`,`destination_name`,`destination`,`channel`,`subject`,`message_text`,`message_html`,`template_name`,`payload_json`,`meta_json`,`status`,`attempts`,`created_at`,`sent_at`,`last_error`,'
      . $this->creatorColumns() . ' FROM `' . self::QUEUE_TABLE . "` WHERE {$where} AND `id` = ? AND " . $this->metadataField('deleted_at') . ' IS NULL', [...$args, $id]);
    if ($row === null) {
      return null;
    }
    $meta = json_decode((string) $row['meta_json'], true);
    $payload = json_decode((string) $row['payload_json'], true);
    $media = $meta['commercial_notifications']['media'] ?? [];
    // Los registros anteriores guardan el encabezado de WhatsApp en el payload.
    if (!$media) {
      foreach (($payload['components'] ?? []) as $component) {
        if (($component['type'] ?? '') !== 'header') { continue; }
        $parameter = $component['parameters'][0] ?? [];
        $type = (string) ($parameter['type'] ?? '');
        $media = ['type' => $type, 'url' => $parameter[$type]['link'] ?? '', 'name' => $parameter[$type]['filename'] ?? 'Archivo adjunto'];
        break;
      }
    }
    $url = is_array($media) ? (string) ($media['url'] ?? '') : '';
    $row['media'] = preg_match('~^https?://~i', $url) ? $media : [];
    unset($row['meta_json'], $row['payload_json']);
    return $row;
  }

  /** Eliminación lógica: conserva auditoría/dedupe y cancela pendientes de forma atómica con el claim del worker. */
  public function deleteNotification(int $id): void
  {
    if (!$this->policy->canView('notificaciones') || !$this->policy->canAct('eliminar_notificacion')) {
      throw new \RuntimeException('Solo los administradores pueden eliminar notificaciones.');
    }
    $sender = $this->senderProfile();
    $now = gmdate('Y-m-d H:i:s');
    [$where, $args] = $this->historyScope();
    $row = $this->db->getRow('SELECT `status`,`meta_json` FROM `' . self::QUEUE_TABLE . "` WHERE {$where} AND `id` = ? AND " . $this->metadataField('deleted_at') . ' IS NULL', [...$args, $id]);
    $unavailable = 'No se pudo eliminar: el mensaje está procesándose, ya fue eliminado o no está disponible. Actualiza la cola.';
    if ($row === null || !in_array($row['status'], ['pending', 'sent', 'failed', 'cancelled'], true)) {
      throw new \RuntimeException($unavailable);
    }
    $meta = json_decode((string) $row['meta_json'], true);
    $meta = is_array($meta) ? $meta : [];
    $audit = $meta['commercial_notifications'] ?? [];
    $meta['commercial_notifications'] = array_replace(is_array($audit) ? $audit : [], [
      'deleted_at' => $now, 'deleted_by_user_id' => Auth::userId(), 'deleted_by_employee_id' => $this->currentEmployeeId(),
      'deleted_by_name' => $sender['name'], 'status_before_deletion' => $row['status'],
    ]);
    // Comparar estado y metadatos evita pisar un claim o un cambio del worker posterior a la lectura.
    $sql = 'UPDATE `' . self::QUEUE_TABLE . '` SET `meta_json` = ?, `status` = ?, `updated_at` = ?'
      . " WHERE {$where} AND `id` = ? AND `status` = ? AND COALESCE(`meta_json`, '') = ? AND " . $this->metadataField('deleted_at') . ' IS NULL';
    $statement = $this->db->pdo()->prepare($sql);
    $statement->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['status'] === 'pending' ? 'cancelled' : $row['status'], $now, ...$args, $id, $row['status'], (string) $row['meta_json']]);
    if ($statement->rowCount() !== 1) {
      throw new \RuntimeException($unavailable);
    }
  }

  /** Informe completo por autor y canal, incluidos los registros retirados de la cola. */
  public function notificationReport(array $filters = []): array
  {
    [$where, $args] = $this->historyScope();
    $dates = [];
    foreach (['date_from', 'date_to'] as $key) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value === '') { continue; }
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('America/Bogota'));
      if (!$date || $date->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException('Selecciona fechas válidas para el informe.');
      }
      $dates[$key] = $date;
    }
    if (isset($dates['date_from'], $dates['date_to']) && $dates['date_from'] > $dates['date_to']) {
      throw new \InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
    }
    foreach ($dates as $key => $date) {
      $where .= $key === 'date_from' ? ' AND `created_at` >= ?' : ' AND `created_at` < ?';
      $args[] = ($key === 'date_to' ? $date->modify('+1 day') : $date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    $employee = trim((string) ($filters['employee_id'] ?? ''));
    if ($employee !== '') {
      $where .= ' AND ' . $this->metadataField('employee_id') . ' = ?';
      $args[] = $employee;
    }
    $columns = $this->metadataField('employee_id') . ' AS employee_id,'
      . "COALESCE(NULLIF(" . $this->metadataField('nombre_funcionario') . ", ''), 'Sin autor registrado') AS creator_name,"
      . $this->metadataField('cargo') . ' AS creator_cargo, `channel`, COUNT(*) AS total';
    foreach (['pending', 'processing', 'sent', 'failed', 'cancelled'] as $status) {
      $columns .= ", SUM(CASE WHEN `status` = '{$status}' THEN 1 ELSE 0 END) AS `{$status}`";
    }
    $columns .= ', SUM(CASE WHEN ' . $this->metadataField('deleted_at') . ' IS NOT NULL THEN 1 ELSE 0 END) AS deleted, MAX(`created_at`) AS last_created_at';
    $rows = $this->db->getResults('SELECT ' . $columns . ' FROM `' . self::QUEUE_TABLE . "` WHERE {$where} GROUP BY employee_id, creator_name, creator_cargo, `channel` ORDER BY creator_name, `channel`", $args);
    $totals = array_fill_keys(['total', 'pending', 'processing', 'sent', 'failed', 'cancelled', 'deleted'], 0);
    foreach ($rows as &$row) {
      foreach ($totals as $key => $_value) {
        $row[$key] = (int) $row[$key];
        $totals[$key] += $row[$key];
      }
    }
    unset($row);
    $grouped = [];
    foreach ($rows as $row) {
      $key = $row['employee_id'] !== null && $row['employee_id'] !== '' ? 'employee:' . $row['employee_id'] : 'name:' . $row['creator_name'];
      if (!isset($grouped[$key])) {
        $grouped[$key] = array_merge($row, array_fill_keys(array_keys($totals), 0), ['whatsapp' => 0, 'email' => 0, 'sms' => 0]);
        unset($grouped[$key]['channel']);
      }
      foreach ($totals as $metric => $_value) { $grouped[$key][$metric] += $row[$metric]; }
      if (in_array($row['channel'], ['whatsapp', 'email', 'sms'], true)) { $grouped[$key][$row['channel']] += $row['total']; }
      if ($row['last_created_at'] >= $grouped[$key]['last_created_at']) {
        foreach (['creator_name', 'creator_cargo', 'last_created_at'] as $field) { $grouped[$key][$field] = $row[$field]; }
      }
    }
    return ['rows' => array_values($grouped), 'totals' => $totals];
  }
}
