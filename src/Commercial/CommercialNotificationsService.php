<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;

/** Reutiliza búsqueda, contratos y preferencias del módulo inmobiliario. */
final class CommercialNotificationsService extends AdministrativeNotificationsService
{
  public const PROJECT_CODE = 'control-servicios-comerciales';
  public const SOURCE_MODULE = 'commercial_notifications';
  public const TEMPLATE_BODY = "Hola {{1}}, recibe un cordial saludo de SKC SuCasa Inmobiliaria.\n\nTe compartimos la siguiente información:\n{{2}}\n\nSi tienes alguna inquietud, puedes comunicarte con nuestro equipo.\nAtentamente,\n{{3}}\n\nGracias por confiar en SKC SuCasa Inmobiliaria.";

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

  /** @return array<string,array{name:string,label:string,language:string,description:string,body:string,variables:array<int,string>,header_type:string}> */
  public function whatsappTemplates(): array
  {
    $out = [];
    foreach (['texto' => 'Texto', 'imagen' => 'Imagen', 'documento' => 'Documento PDF', 'video' => 'Video'] as $kind => $label) {
      $name = 'scm_comercial_generica_' . $kind . '_v1';
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
    // Un contacto puede participar en contratos de otros funcionarios: no exponer sus resúmenes.
    return $this->policy->canManage()
      ? parent::contractActivityInfo($type, $config, $recipient, $contractStatus, $inmuebleSimi, $contractNumber)
      : ['label' => '', 'summary' => ''];
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
    if ($channel === 'email') {
      $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
      $message = '<p>Hola <strong>' . $escape($name) . '</strong>, recibe un cordial saludo de SKC SuCasa Inmobiliaria.</p><div>'
        . nl2br($escape($this->messageText)) . '</div><p>Atentamente,<br><strong>' . $escape($signature) . '</strong></p>';
    }
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
    if ($channel === 'email' && $this->media !== []) {
      $message .= '<p><a href="' . htmlspecialchars($this->media['url'], ENT_QUOTES, 'UTF-8') . '">Ver archivo: ' . htmlspecialchars($this->media['name'], ENT_QUOTES, 'UTF-8') . '</a></p>';
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
      ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
    $where = '`project_code` = ? AND `source_module` = ?';
    $args = [self::PROJECT_CODE, self::SOURCE_MODULE];
    if (!$this->policy->canManage()) {
      $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(`meta_json`, '$.commercial_notifications.employee_id')) = ?";
      $args[] = $this->currentEmployeeId();
    }
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
    $rows = $this->db->getResults('SELECT `id`,`destination_name`,`destination`,`channel`,`template_name`,`status`,`attempts`,`created_at`,`sent_at`,`last_error` FROM `' . self::QUEUE_TABLE . "` WHERE {$where} ORDER BY `id` DESC LIMIT 20 OFFSET ?", [...$args, ($page - 1) * 20]);
    return ['rows' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
  }
}
