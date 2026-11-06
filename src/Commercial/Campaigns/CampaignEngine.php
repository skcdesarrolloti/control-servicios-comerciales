<?php

declare(strict_types=1);

namespace SCM\Commercial\Campaigns;

use SCM\Commercial\CommercialSmsMessage;
use SCM\Commercial\Promotion\Analytics\TrackerSchema;
use SCM\Commercial\Tools\UtmLinkBuilder;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Notificaciones › Campañas: envíos masivos por lotes con su informe. Copia/adaptación de dashboard-marketing
 * app/CampaignEngine.php, CampaignRepository.php y CampaignService.php sobre las mismas tablas, para conservar el
 * historial: jet_cct_campaign_config (una fila por campaña: ritmo, estado y, en config_value, lo que se envió),
 * jet_cct_campaign_batches (cada envío), jet_cct_campaign_exclusions, jet_cct_email_tracking (aperturas) y la cola
 * skc_notification_queue (gda_campaign_tag, gda_tipo_actor, gda_id_actor, gda_id_funcionario).
 *
 * Reglas que se conservan: una persona recibe una sola vez cada campaña por canal (también si ya la recibió desde el
 * panel anterior), los excluidos y quienes no aceptan esa categoría se saltan, y los mensajes se programan por lotes
 * (por defecto 200 cada 15 minutos, máximo 1.000 al día) solo de lunes a sábado entre 7:00 y 19:00 (hora de Colombia).
 * Cambios: un envío puede juntar varias listas, se calcula cuántos recibirán antes de enviar, los enlaces del correo a
 * sucasainmobiliaria.com.co llevan la campaña UTM para contar las visitas, y cancelar deja los mensajes pendientes como
 * cancelados en lugar de borrarlos.
 */
final class CampaignEngine
{
  public const PROJECT_CODE = 'control-servicios-comerciales';
  public const SOURCE_MODULE = 'commercial_campaigns';
  /** Proyecto con que dashboard-marketing encolaba sus campañas (historial). */
  public const LEGACY_PROJECT = 'gestor-actores';
  public const QUEUE = 'skc_notification_queue';
  public const CHANNELS = ['email' => 'Correo', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
  public const CATEGORIES = ['info' => 'Informativa', 'promo' => 'Promocional', 'marketing' => 'Marketing', 'notif' => 'Notificación'];
  public const PACE_DEFAULTS = ['batch_size' => 200, 'batch_interval_minutes' => 15, 'max_per_day' => 1000];
  public const OWN_DOMAIN = 'sucasainmobiliaria.com.co';
  private const TIMEZONE = 'America/Bogota';

  private Database $db;
  private CampaignRecipients $recipients;
  private SchemaInspector $schema;
  private string $baseUrl;
  /** @var \Closure():\DateTimeImmutable */
  private \Closure $clock;
  private string $config;
  private string $batches;
  private string $exclusions;
  private string $tracking;

  /** @param (\Closure():\DateTimeImmutable)|null $clock Hora actual (las pruebas la fijan). */
  public function __construct(Database $db, CampaignRecipients $recipients, string $baseUrl, ?\Closure $clock = null)
  {
    $this->db = $db;
    $this->recipients = $recipients;
    $this->schema = new SchemaInspector($db);
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    $this->config = $db->table('jet_cct_campaign_config');
    $this->batches = $db->table('jet_cct_campaign_batches');
    $this->exclusions = $db->table('jet_cct_campaign_exclusions');
    $this->tracking = $db->table('jet_cct_email_tracking');
  }

  // ── Datos de entrada ────────────────────────────────────────────────────

  /**
   * Campaña validada a partir de lo que llega del formulario.
   * @param array<string,mixed> $input name, channel, category, subject, message, template_id, segments (JSON o lista de
   *   {type, q}), batch_size, batch_interval_minutes, max_per_day, utm_campaign
   * @return array<string,mixed>
   */
  public function spec(array $input, ?CampaignTemplates $templates = null): array
  {
    // Un envío pertenece a una campaña: sin nombre propio se llama como ella, y así sus listas no reciben el mensaje dos veces.
    $label = preg_replace('/\s+/u', ' ', trim(strip_tags((string) ($input['campaign_label'] ?? '')))) ?? '';
    if (mb_strlen($label, 'UTF-8') > 60) {
      throw new \InvalidArgumentException('El nombre de la campaña admite hasta 60 caracteres.');
    }
    $name = preg_replace('/\s+/u', ' ', trim((string) ($input['name'] ?? ''))) ?? '';
    $name = $name !== '' ? $name : $label;
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
      throw new \InvalidArgumentException('Elige la campaña del envío (máximo 120 caracteres).');
    }
    $channel = (string) ($input['channel'] ?? '');
    if (!isset(self::CHANNELS[$channel])) {
      throw new \InvalidArgumentException('Elige el canal: correo, SMS o WhatsApp.');
    }
    $category = (string) ($input['category'] ?? 'info');
    if (!isset(self::CATEGORIES[$category])) {
      throw new \InvalidArgumentException('Elige el tipo de mensaje.');
    }
    // Meta registra la plantilla de WhatsApp como Marketing: se aplica esa preferencia.
    if ($channel === 'whatsapp') {
      $category = 'marketing';
    }
    $message = trim((string) ($input['message'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $template = null;
    $templateId = (int) ($input['template_id'] ?? 0);
    if ($channel === 'email' && $templateId > 0) {
      $template = ($templates ?? new CampaignTemplates($this->db))->find($templateId);
      if ($template === null || (string) $template['estado'] !== 'activa') {
        throw new \InvalidArgumentException('La plantilla de correo elegida no existe o está archivada.');
      }
      if ($subject === '') {
        $subject = trim((string) $template['asunto']);
      }
    }
    if ($channel === 'email') {
      if ($subject === '' || mb_strlen($subject, 'UTF-8') > 200) {
        throw new \InvalidArgumentException('Escribe el asunto del correo (máximo 200 caracteres).');
      }
      if ($template === null && $message === '') {
        throw new \InvalidArgumentException('Escribe el mensaje o elige una plantilla de correo.');
      }
      if (mb_strlen($message, 'UTF-8') > 5000) {
        throw new \InvalidArgumentException('El mensaje admite hasta 5.000 caracteres.');
      }
    } elseif ($message === '') {
      throw new \InvalidArgumentException('Escribe el mensaje.');
    } elseif ($channel === 'sms' && mb_strlen(CommercialSmsMessage::PREFIX . $message, 'UTF-8') > CommercialSmsMessage::MAX_CHARACTERS) {
      throw new \InvalidArgumentException('El SMS supera 160 caracteres con el prefijo "SKC SuCasa Inmobiliaria". Acórtalo.');
    } elseif ($channel === 'whatsapp' && mb_strlen($message, 'UTF-8') > 700) {
      throw new \InvalidArgumentException('El mensaje de WhatsApp admite hasta 700 caracteres.');
    }
    $segments = $input['segments'] ?? [];
    if (is_string($segments)) {
      $segments = json_decode($segments, true);
    }
    $labels = $this->recipients->typeLabels();
    $clean = [];
    foreach (is_array($segments) ? $segments : [] as $segment) {
      $type = is_array($segment) ? (string) ($segment['type'] ?? '') : '';
      if (!isset($labels[$type])) {
        throw new \InvalidArgumentException('Una de las listas de destinatarios no es válida.');
      }
      $query = mb_substr(trim((string) ($segment['q'] ?? '')), 0, 150, 'UTF-8');
      $clean[$type . '|' . $query] = ['type' => $type, 'q' => $query, 'label' => $labels[$type] . ($query !== '' ? ' · "' . $query . '"' : '')];
    }
    if ($clean === []) {
      throw new \InvalidArgumentException('Elige al menos una lista de destinatarios.');
    }
    $pace = [];
    foreach (['batch_size' => [1, 1000], 'batch_interval_minutes' => [1, 1440], 'max_per_day' => [1, 20000]] as $key => [$min, $max]) {
      $value = (int) ($input[$key] ?? self::PACE_DEFAULTS[$key]);
      if ($value < $min || $value > $max) {
        throw new \InvalidArgumentException('El ritmo de envío no es válido: lotes de 1 a 1.000, cada 1 a 1.440 minutos y hasta 20.000 al día.');
      }
      $pace[$key] = $value;
    }
    $utm = self::campaignSlug((string) ($input['utm_campaign'] ?? ''), $label);
    return [
      'name' => $name, 'channel' => $channel, 'category' => $category, 'subject' => $subject, 'message' => $message,
      'template' => $template, 'template_id' => $template !== null ? (int) $template['id'] : 0, 'segments' => array_values($clean),
      'pace' => $pace, 'utm_campaign' => $utm, 'campaign_label' => $label,
    ];
  }

  public static function slugFor(string $slug, string $label): string
  {
    return self::campaignSlug($slug, $label);
  }

  /** Nombre de la campaña en los enlaces: el elegido o el que sale de su nombre, siempre con el mismo formato. */
  private static function campaignSlug(string $slug, string $label): string
  {
    $slug = strtolower(trim($slug));
    $slug = $slug !== '' ? $slug : substr(UtmLinkBuilder::slug($label), 0, 80);
    if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $slug) !== 1) {
      throw new \InvalidArgumentException('La campaña no es válida.');
    }
    if (in_array($slug, UtmLinkBuilder::RESERVED_CAMPAIGNS, true)) {
      throw new \InvalidArgumentException('Ese nombre lo usa el sistema: elige otro para la campaña.');
    }
    return $slug;
  }

  // ── Vista previa y envío ────────────────────────────────────────────────

  /**
   * Cuántos recibirían la campaña y por qué se salta al resto, por lista. No escribe nada.
   * @param array<string,mixed> $spec
   * @return array{segments:list<array<string,mixed>>,totals:array<string,int>,sample:array<string,mixed>|null,unknown_variables:list<string>}
   */
  public function preview(array $spec): array
  {
    $plan = $this->plan($spec);
    $sample = null;
    $unknown = [];
    foreach ($plan['send'] as $item) {
      $sample = ['nombre' => (string) $item['recipient']['nombre'], 'destino' => $item['destination'], 'tipo' => (string) $item['recipient']['tipo_label'],
        'subject' => $item['message']['subject'], 'html' => $item['message']['html'], 'text' => $item['message']['text']];
      $source = (string) ($spec['template']['contenido'] ?? '') . ' ' . $spec['subject'] . ' ' . $spec['message'];
      $unknown = CampaignMessage::unknownVariables($source, $item['recipient']);
      break;
    }
    $send = (int) $plan['totals']['send'];
    $schedule = $send > 0 ? ['first_at' => $this->scheduledAt($spec['pace'], 0), 'last_at' => $this->scheduledAt($spec['pace'], $send - 1)] : null;
    return ['segments' => $plan['segments'], 'totals' => $plan['totals'], 'sample' => $sample, 'unknown_variables' => $unknown, 'schedule' => $schedule];
  }

  /**
   * Encola la campaña por lotes. Si la campaña ya existe se suma a ella (sin repetir a quien ya la recibió).
   * @param array<string,mixed> $spec
   * @return array{tag:string,queued:int,totals:array<string,int>,first_at:string,last_at:string}
   */
  public function send(array $spec, string $employee, string $employeeName): array
  {
    $this->requireQueue();
    $lock = 'scm-campaign:' . substr(hash('sha256', $spec['name']), 0, 40);
    if ((int) $this->db->getVar('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
      throw new \RuntimeException('Esta campaña se está enviando en este momento. Espera un momento y revisa su informe.');
    }
    try {
      $config = $this->saveConfig($spec, $employee);
      $plan = $this->plan($spec);
      $paused = (string) $config['status'] === 'paused';
      $position = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` = ? AND `channel` = ? AND `status` IN ('pending', 'processing', 'paused')", [$spec['name'], $spec['channel']]);
      $queued = 0;
      $first = '';
      $last = '';
      $actorIds = [];
      $now = gmdate('Y-m-d H:i:s');
      foreach ($plan['send'] as $item) {
        $recipient = $item['recipient'];
        $token = bin2hex(random_bytes(16));
        $message = $item['message'];
        if ($spec['channel'] === 'email') {
          // El píxel lleva un token propio por mensaje; se vuelve a armar con él.
          $message = CampaignMessage::build($spec + ['template' => $spec['template']], $recipient, $this->baseUrl . '/track-email.php?t=' . $token) ?? $message;
          $message['html'] = self::tagLinks($message['html'], $spec['utm_campaign']);
        }
        $scheduled = $this->scheduledAt($spec['pace'], $position + $queued);
        $row = [
          'project_code' => self::PROJECT_CODE, 'source_module' => self::SOURCE_MODULE, 'channel' => $spec['channel'],
          'provider' => ['email' => 'email_smtp', 'sms' => 'sms_onurix', 'whatsapp' => 'whatsapp_official'][$spec['channel']],
          'destination' => $item['destination'], 'destination_name' => (string) $recipient['nombre'], 'subject' => $message['subject'],
          'message_text' => $message['text'], 'message_html' => $message['html'],
          'template_name' => $spec['channel'] === 'whatsapp' ? CampaignMessage::WHATSAPP_TEMPLATE : ($spec['template'] !== null ? (string) $spec['template']['nombre'] : ''),
          'template_language' => $spec['channel'] === 'whatsapp' ? 'es_CO' : '',
          'payload_json' => json_encode($message['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
          'meta_json' => json_encode(['gda' => [
            'campaign_tag' => $spec['name'], 'tipo_actor' => $recipient['tipo_actor'], 'id_actor' => (int) $recipient['_ID'], 'id_funcionario' => $employee,
            'nombre_funcionario' => $employeeName, 'categoria' => $spec['category'], 'categoria_mensaje' => $spec['category'],
            'template_id' => $spec['template_id'], 'tracking_token' => $spec['channel'] === 'email' ? $token : '', 'source_module' => self::SOURCE_MODULE,
          ], 'sms' => $spec['channel'] === 'sms' ? CommercialSmsMessage::metrics($message['text']) : null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
          'status' => $paused ? 'paused' : 'pending', 'priority' => 120, 'max_attempts' => 3, 'scheduled_at' => $scheduled,
          'created_at' => $now, 'updated_at' => $now, 'created_by' => self::PROJECT_CODE,
          'dedupe_key' => implode(':', [self::PROJECT_CODE, 'campaign', md5($spec['name']), $spec['channel'], $recipient['tipo_actor'], (int) $recipient['_ID']]),
        ];
        $this->db->insert(self::QUEUE, $row);
        $queueId = (int) $this->db->lastInsertId();
        if ($spec['channel'] === 'email' && $this->schema->tableExists($this->tracking)) {
          $this->db->insert($this->tracking, [
            'tracking_token' => $token, 'queue_id' => $queueId, 'id_actor' => (int) $recipient['_ID'], 'id_funcionario' => (int) $employee,
            'tipo_actor' => $recipient['tipo_actor'], 'campaign_tag' => $spec['name'], 'destination' => $item['destination'],
            'subject' => mb_substr($message['subject'], 0, 255, 'UTF-8'), 'open_count' => 0, 'created_at' => $now,
          ]);
        }
        $actorIds[$recipient['tipo_actor']][] = (int) $recipient['_ID'];
        $first = $first === '' ? $scheduled : $first;
        $last = $scheduled;
        $queued++;
      }
      foreach ($actorIds as $type => $ids) {
        $this->recordBatch($spec['name'], $spec['channel'], $type, $ids, $employee);
      }
      return ['tag' => $spec['name'], 'queued' => $queued, 'totals' => $plan['totals'], 'first_at' => $first, 'last_at' => $last];
    } finally {
      $this->db->getVar('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  /**
   * Destinatarios que reciben y los que se saltan, con la razón.
   * @param array<string,mixed> $spec
   * @return array{segments:list<array<string,mixed>>,totals:array<string,int>,send:list<array{recipient:array<string,mixed>,destination:string,message:array<string,mixed>}>}
   */
  private function plan(array $spec): array
  {
    $already = $this->alreadyQueued($spec['name'], $spec['channel']);
    $excluded = $this->excluded($spec['name']);
    $seen = [];
    $send = [];
    $segments = [];
    $totals = ['total' => 0, 'send' => 0, 'already' => 0, 'blocked' => 0, 'no_destination' => 0, 'excluded' => 0, 'invalid' => 0, 'repeated' => 0];
    foreach ($spec['segments'] as $segment) {
      $counts = ['total' => 0, 'send' => 0, 'already' => 0, 'blocked' => 0, 'no_destination' => 0, 'excluded' => 0, 'invalid' => 0, 'repeated' => 0];
      $ids = $this->recipients->segmentIds($segment['type'], $segment['q']);
      foreach ($this->recipients->rows($segment['type'], $ids) as $recipient) {
        $counts['total']++;
        $key = $recipient['tipo_actor'] . ':' . (int) $recipient['_ID'];
        $destination = $this->recipients->destinationFor($recipient, $spec['channel']);
        $reason = match (true) {
          isset($seen[$key]) => 'repeated',
          isset($already[$key]) => 'already',
          isset($excluded[$key]) => 'excluded',
          !CampaignRecipients::allows($recipient, $spec['category'], $spec['channel']) => 'blocked',
          $destination === '' => 'no_destination',
          default => '',
        };
        // El mismo celular o correo recibe un solo mensaje: en este envío (repetido) o en uno anterior (ya la recibió).
        if ($reason === '' && isset($already['to:' . strtolower($destination)])) {
          $reason = 'already';
        } elseif ($reason === '' && isset($seen['to:' . strtolower($destination)])) {
          $reason = 'repeated';
        }
        $message = $reason === '' ? CampaignMessage::build($spec, $recipient) : null;
        if ($reason === '' && $message === null) {
          $reason = 'invalid';
        }
        if ($reason !== '') {
          $counts[$reason]++;
          continue;
        }
        $seen[$key] = true;
        $seen['to:' . strtolower($destination)] = true;
        $counts['send']++;
        $send[] = ['recipient' => $recipient, 'destination' => $destination, 'message' => $message];
      }
      foreach ($counts as $name => $value) {
        $totals[$name] += $value;
      }
      $segments[] = ['type' => $segment['type'], 'q' => $segment['q'], 'label' => $segment['label'], 'counts' => $counts];
    }
    return ['segments' => $segments, 'totals' => $totals, 'send' => $send];
  }

  /**
   * tipo:id y to:destino de quien ya tiene esta campaña por este canal (en cualquier estado salvo cancelado): el mismo
   * correo o celular no recibe dos veces aunque venga de otra persona u otra lista.
   * @return array<string,true>
   */
  private function alreadyQueued(string $tag, string $channel): array
  {
    if (!$this->schema->columnExists(self::QUEUE, 'gda_campaign_tag')) {
      return [];
    }
    $out = [];
    foreach ($this->db->getResults('SELECT `gda_tipo_actor` AS t, `gda_id_actor` AS i, `destination` AS d FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` = ? AND `channel` = ? AND `status` <> 'cancelled'", [$tag, $channel]) as $row) {
      $out[$row['t'] . ':' . (int) $row['i']] = true;
      if (trim((string) $row['d']) !== '') {
        $out['to:' . strtolower(trim((string) $row['d']))] = true;
      }
      // Las campañas del panel anterior guardaban el tipo sin "_activos" (propietarios, arrendatarios...).
      foreach (['_todos', '_activos', '_no_activos', '_todas', '_activas', '_no_activas'] as $suffix) {
        $out[$row['t'] . $suffix . ':' . (int) $row['i']] = true;
      }
    }
    return $out;
  }

  /** @return array<string,true> */
  private function excluded(string $tag): array
  {
    if (!$this->schema->tableExists($this->exclusions)) {
      return [];
    }
    $out = [];
    foreach ($this->db->getResults("SELECT tipo_actor, id_actor FROM `{$this->exclusions}` WHERE campaign_tag = ?", [$tag]) as $row) {
      $out[$row['tipo_actor'] . ':' . (int) $row['id_actor']] = true;
    }
    return $out;
  }

  /**
   * Hora (UTC) de la posición $position en la cola de la campaña: lotes de batch_size cada batch_interval_minutes, hasta
   * max_per_day al día, de lunes a sábado entre 7:00 y 19:00 de Colombia (como dashboard-marketing).
   * @param array<string,int> $pace
   */
  public function scheduledAt(array $pace, int $position): string
  {
    $start = ($this->clock)()->setTimezone(new \DateTimeZone(self::TIMEZONE));
    $start = self::workingTime($start);
    $day = intdiv($position, $pace['max_per_day']);
    $withinDay = $position % $pace['max_per_day'];
    $at = $start;
    for ($i = 0; $i < $day; $i++) {
      $at = self::workingTime($at->modify('+1 day')->setTime(7, 0));
    }
    $at = $at->modify('+' . (intdiv($withinDay, $pace['batch_size']) * $pace['batch_interval_minutes']) . ' minutes');
    $at = self::workingTime($at);
    return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  /** La misma hora si cae en horario de envío; si no, las 7:00 del siguiente día hábil (domingo no). */
  private static function workingTime(\DateTimeImmutable $at): \DateTimeImmutable
  {
    if ((int) $at->format('H') >= 19) {
      $at = $at->modify('+1 day')->setTime(7, 0);
    } elseif ((int) $at->format('H') < 7) {
      $at = $at->setTime(7, 0);
    }
    if ((int) $at->format('N') === 7) {
      $at = $at->modify('+1 day')->setTime(7, 0);
    }
    return $at;
  }

  /** Enlaces del correo al sitio propio con utm_source=correo, utm_medium=correo y la campaña, para la Analítica web. */
  public static function tagLinks(string $html, string $campaign): string
  {
    if ($campaign === '') {
      return $html;
    }
    return (string) preg_replace_callback('~href=(["\'])(https?://(?:[a-z0-9-]+\.)*' . preg_quote(self::OWN_DOMAIN, '~') . '(?:[/?#][^"\']*)?)\1~i', static function (array $match) use ($campaign): string {
      $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
      if (preg_match('/[?&]utm_campaign=/i', $url) === 1) {
        return $match[0];
      }
      $fragment = '';
      if (($hash = strpos($url, '#')) !== false) {
        $fragment = substr($url, $hash);
        $url = substr($url, 0, $hash);
      }
      $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['utm_source' => 'correo', 'utm_medium' => 'correo', 'utm_campaign' => $campaign]);
      return 'href=' . $match[1] . htmlspecialchars($url . $fragment, ENT_QUOTES, 'UTF-8') . $match[1];
    }, $html);
  }

  // ── Control de la campaña ───────────────────────────────────────────────

  /** Pausa o reanuda: los mensajes pendientes quedan en pausa (o vuelven a pendientes). @return int mensajes cambiados */
  public function setPaused(string $tag, bool $paused): int
  {
    $this->requireCampaign($tag);
    $this->db->update($this->config, ['status' => $paused ? 'paused' : 'active', 'cct_modified' => $this->localNow(), 'updated_at' => gmdate('Y-m-d H:i:s')], ['campaign_tag' => $tag]);
    [$from, $to] = $paused ? ['pending', 'paused'] : ['paused', 'pending'];
    return $this->updateQueue($tag, "`status` = '{$from}'", ['status' => $to]);
  }

  /** Vuelve a intentar los fallidos. @return int */
  public function retryFailed(string $tag): int
  {
    $this->requireCampaign($tag);
    $paused = (string) $this->db->getVar("SELECT status FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$tag]) === 'paused';
    return $this->updateQueue($tag, "`status` = 'failed'", ['status' => $paused ? 'paused' : 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'locked_at' => null, 'locked_by' => null, 'last_error' => null]);
  }

  /** Cancela lo que falta por enviar (los mensajes quedan como cancelados en la cola, con su historial). @return int */
  public function cancel(string $tag): int
  {
    $this->requireCampaign($tag);
    $this->db->update($this->config, ['status' => 'cancelled', 'cct_modified' => $this->localNow(), 'updated_at' => gmdate('Y-m-d H:i:s')], ['campaign_tag' => $tag]);
    return $this->updateQueue($tag, "`status` IN ('pending', 'paused')", ['status' => 'cancelled', 'last_error' => 'Campaña cancelada desde el panel comercial.']);
  }

  /**
   * Cambia el nombre en todas partes, como dashboard-marketing CampaignRepository::rename. gda_campaign_tag es una columna
   * calculada de meta_json: lo que se escribe es el JSON. El historial y los mensajes en cola siguen con la campaña.
   */
  public function rename(string $tag, string $new): string
  {
    $this->requireCampaign($tag);
    $new = preg_replace('/\s+/u', ' ', trim($new)) ?? '';
    if ($new === '' || mb_strlen($new, 'UTF-8') > 120) {
      throw new \InvalidArgumentException('Escribe el nombre nuevo (máximo 120 caracteres).');
    }
    if ($new === $tag) {
      throw new \InvalidArgumentException('La campaña ya tiene ese nombre.');
    }
    if ($this->db->getVar("SELECT 1 FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$new]) || $this->db->getVar('SELECT 1 FROM `' . self::QUEUE . '` WHERE `gda_campaign_tag` = ? LIMIT 1', [$new])) {
      throw new \InvalidArgumentException('Ya existe una campaña con ese nombre.');
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ([$this->config, $this->db->table('jet_cct_campaign_batches'), $this->db->table('jet_cct_campaign_exclusions'), $this->db->table('jet_cct_email_tracking')] as $table) {
        if ($this->schema->tableExists($table)) {
          $this->db->update($table, ['campaign_tag' => $new], ['campaign_tag' => $tag]);
        }
      }
      $pdo->prepare('UPDATE `' . self::QUEUE . "` SET `meta_json` = JSON_SET(`meta_json`, '$.gda.campaign_tag', ?) WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?)")->execute([$new, $tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    }
    return $new;
  }

  /**
   * Elimina la campaña y sus mensajes, como dashboard-marketing CampaignEngine::delete. Con mensajes por salir no se puede: hay
   * que cancelarla primero, para que un borrado nunca corte un envío en curso. @return int mensajes borrados
   */
  public function delete(string $tag): int
  {
    $this->requireCampaign($tag);
    $active = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?) AND `status` NOT IN ('sent', 'failed', 'cancelled')", [$tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
    if ($active > 0) {
      throw new \InvalidArgumentException('La campaña tiene mensajes por salir. Cancela lo pendiente antes de eliminarla.');
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $statement = $pdo->prepare('DELETE FROM `' . self::QUEUE . '` WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?)');
      $statement->execute([$tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
      foreach ([$this->config, $this->db->table('jet_cct_campaign_batches'), $this->db->table('jet_cct_campaign_exclusions')] as $table) {
        if ($this->schema->tableExists($table)) {
          $this->db->delete($table, ['campaign_tag' => $tag]);
        }
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    }
    return $statement->rowCount();
  }

  /** Excluye a una persona de la campaña (y cancela su mensaje si aún no salió). */
  public function exclude(string $tag, string $type, int $actorId, string $reason, string $employee): void
  {
    $this->requireCampaign($tag);
    $reason = mb_substr(trim($reason) !== '' ? trim($reason) : 'Exclusión manual', 0, 255, 'UTF-8');
    if (!$this->db->getVar("SELECT 1 FROM `{$this->exclusions}` WHERE campaign_tag = ? AND id_actor = ? AND tipo_actor = ?", [$tag, $actorId, $type])) {
      $this->db->insert($this->exclusions, ['campaign_tag' => $tag, 'id_actor' => $actorId, 'tipo_actor' => $type, 'reason' => $reason, 'created_by' => (int) $employee, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
    $this->updateQueue($tag, "`status` IN ('pending', 'paused') AND `gda_tipo_actor` = ? AND `gda_id_actor` = ?", ['status' => 'cancelled', 'last_error' => 'Excluido de la campaña: ' . $reason], [$type, $actorId]);
  }

  /** @param array<string,mixed> $set @param list<mixed> $args */
  private function updateQueue(string $tag, string $condition, array $set, array $args = []): int
  {
    $set['updated_at'] = gmdate('Y-m-d H:i:s');
    $columns = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", array_keys($set)));
    $statement = $this->db->pdo()->prepare('UPDATE `' . self::QUEUE . "` SET {$columns} WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?) AND {$condition}");
    $statement->execute([...array_values($set), $tag, self::PROJECT_CODE, self::LEGACY_PROJECT, ...$args]);
    return $statement->rowCount();
  }

  // ── Informe ─────────────────────────────────────────────────────────────

  /**
   * Todas las campañas (las del panel anterior también), de la más reciente a la más antigua.
   * @return list<array<string,mixed>>
   */
  public function list(): array
  {
    if (!$this->schema->columnExists(self::QUEUE, 'gda_campaign_tag')) {
      return [];
    }
    $rows = $this->db->getResults('SELECT `gda_campaign_tag` AS tag, COUNT(*) AS total,
        SUM(CASE WHEN `status` = \'sent\' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN `status` = \'failed\' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN `status` IN (\'pending\', \'processing\', \'paused\') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN `status` = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled, MIN(`created_at`) AS first_at, MAX(`created_at`) AS last_at,
        GROUP_CONCAT(DISTINCT `channel`) AS channels
      FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` IS NOT NULL AND `gda_campaign_tag` <> '' AND `project_code` IN (?, ?) GROUP BY `gda_campaign_tag`", [self::PROJECT_CODE, self::LEGACY_PROJECT]);
    $byTag = [];
    foreach ($rows as $row) {
      $byTag[(string) $row['tag']] = $row;
    }
    $configs = $this->schema->tableExists($this->config) ? $this->db->getResults("SELECT * FROM `{$this->config}`") : [];
    foreach ($configs as $config) {
      $byTag[(string) $config['campaign_tag']] ??= ['tag' => (string) $config['campaign_tag'], 'total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0, 'first_at' => (string) ($config['created_at'] ?? ''), 'last_at' => (string) ($config['created_at'] ?? ''), 'channels' => ''];
      $byTag[(string) $config['campaign_tag']]['config'] = $config;
    }
    $opens = $this->opensByTag(array_keys($byTag));
    $out = [];
    foreach ($byTag as $tag => $row) {
      $meta = self::meta($row['config'] ?? []);
      $out[] = [
        'tag' => (string) $tag, 'status' => (string) ($row['config']['status'] ?? 'active'),
        'channels' => array_values(array_filter(explode(',', (string) $row['channels']))),
        'total' => (int) $row['total'], 'sent' => (int) $row['sent'], 'failed' => (int) $row['failed'], 'pending' => (int) $row['pending'], 'cancelled' => (int) $row['cancelled'],
        'first_at' => (string) $row['first_at'], 'last_at' => (string) $row['last_at'], 'opened' => $opens[$tag]['opened'] ?? 0, 'tracked' => $opens[$tag]['tracked'] ?? 0,
        'legacy' => !isset($meta['channel']), 'meta' => $meta,
      ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($b['last_at'], $a['last_at']));
    return $out;
  }

  /**
   * Informe de una campaña: por canal y estado, por lista, aperturas, visitas al sitio, últimos fallidos y lotes.
   * @return array<string,mixed>
   */
  public function detail(string $tag): array
  {
    $summary = null;
    foreach ($this->list() as $row) {
      if ($row['tag'] === $tag) {
        $summary = $row;
        break;
      }
    }
    if ($summary === null) {
      throw new \InvalidArgumentException('La campaña no existe.');
    }
    $byType = $this->db->getResults('SELECT `gda_tipo_actor` AS type, `channel`, `status`, COUNT(*) AS total FROM `' . self::QUEUE . '` WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?) GROUP BY `gda_tipo_actor`, `channel`, `status`', [$tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
    $failures = $this->db->getResults('SELECT `id`, `destination_name`, `destination`, `channel`, `gda_tipo_actor` AS type, `gda_id_actor` AS actor, `last_error`, `attempts`, `updated_at` FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?) AND `status` = 'failed' ORDER BY `id` DESC LIMIT 25", [$tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
    $next = $this->db->getVar('SELECT MIN(`scheduled_at`) FROM `' . self::QUEUE . "` WHERE `gda_campaign_tag` = ? AND `project_code` IN (?, ?) AND `status` = 'pending'", [$tag, self::PROJECT_CODE, self::LEGACY_PROJECT]);
    $batches = $this->schema->tableExists($this->batches) ? $this->db->getResults("SELECT batch_number, channel, total_actors, id_funcionario, cct_created FROM `{$this->batches}` WHERE campaign_tag = ? ORDER BY _ID DESC LIMIT 20", [$tag]) : [];
    $visits = $this->visits((string) ($summary['meta']['utm_campaign'] ?? ''));
    // Nombres de las listas; las campañas del panel anterior usaban propietarios, arrendatarios, copropiedades...
    $labels = $this->recipients->typeLabels() + ['propietarios' => 'Propietarios', 'arrendatarios' => 'Arrendatarios', 'copropiedades' => 'Copropiedades',
      'contactos_funcionarios' => 'Contactos de funcionarios'];
    foreach ($byType as &$row) {
      $row['label'] = $labels[(string) $row['type']] ?? ucfirst(str_replace('_', ' ', (string) $row['type']));
    }
    unset($row);
    return $summary + ['by_type' => $byType, 'failures' => $failures, 'next_at' => (string) ($next ?? ''), 'batches' => $batches, 'visits' => $visits,
      'exclusions' => $this->schema->tableExists($this->exclusions) ? (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->exclusions}` WHERE campaign_tag = ?", [$tag]) : 0];
  }

  /** @param list<string> $tags @return array<string,array{opened:int,tracked:int}> */
  private function opensByTag(array $tags): array
  {
    if ($tags === [] || !$this->schema->tableExists($this->tracking)) {
      return [];
    }
    $out = [];
    $rows = $this->db->getResults("SELECT campaign_tag, COUNT(*) AS tracked, SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) AS opened FROM `{$this->tracking}` WHERE campaign_tag IN (" . implode(',', array_fill(0, count($tags), '?')) . ') GROUP BY campaign_tag', $tags);
    foreach ($rows as $row) {
      $out[(string) $row['campaign_tag']] = ['opened' => (int) $row['opened'], 'tracked' => (int) $row['tracked']];
    }
    return $out;
  }

  /** Visitas y visitantes al sitio con esa campaña UTM (resúmenes de la Analítica web; sin robots ni visitas internas). @return array{campaign:string,hits:int,visitors:int}|null */
  private function visits(string $campaign): ?array
  {
    if ($campaign === '') {
      return null;
    }
    try {
      $hits = (int) $this->db->getVar('SELECT COALESCE(SUM(hits), 0) FROM `' . TrackerSchema::daily($this->db) . '` WHERE camp_slug = ? AND bot = 0 AND internal = 0', [$campaign]);
      $visitors = (int) $this->db->getVar('SELECT COUNT(DISTINCT visitor_hash) FROM `' . TrackerSchema::visitors($this->db) . '` WHERE camp_slug = ? AND bot = 0 AND internal = 0', [$campaign]);
    } catch (\Throwable) {
      return null;
    }
    return ['campaign' => $campaign, 'hits' => $hits, 'visitors' => $visitors];
  }

  // ── Campañas: agrupan envíos ────────────────────────────────────────

  /** Nombre visible de una campaña a partir de su slug. */
  private static function prettyCampaign(string $slug): string
  {
    return ucfirst(str_replace(['-', '_'], ' ', $slug));
  }

  /**
   * Campañas que se pueden elegir: las del Generador UTM, las que ya tienen envíos y las que traen visitas al sitio.
   * @param list<array{label:string,value:string}> $catalog
   * @return array<string,string> slug => nombre
   */
  public function campaignOptions(array $catalog): array
  {
    $options = [];
    foreach ($catalog as $row) {
      $options[$row['value']] = $row['label'];
    }
    foreach ($this->list() as $row) {
      $slug = (string) ($row['meta']['utm_campaign'] ?? '');
      if ($slug !== '') {
        $options[$slug] ??= (string) (($row['meta']['campaign_label'] ?? '') ?: self::prettyCampaign($slug));
      }
    }
    try {
      foreach ($this->db->getResults('SELECT `slug`, `nombre` FROM `' . $this->db->table('jet_cct_tracker_camps') . '`') as $row) {
        $slug = (string) $row['slug'];
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $slug) === 1 && preg_match('/[a-z]/', $slug) === 1 && !in_array($slug, UtmLinkBuilder::RESERVED_CAMPAIGNS, true)) {
          $options[$slug] ??= trim((string) $row['nombre']) !== '' ? str_replace(['-', '_'], ' ', (string) $row['nombre']) : self::prettyCampaign($slug);
        }
      }
    } catch (\Throwable) {
      // Sin el catálogo de visitas quedan las demás.
    }
    uasort($options, static fn(string $a, string $b): int => strcasecmp($a, $b));
    return $options;
  }

  /**
   * Envíos agrupados por campaña. Los que no tienen campaña se cuentan aparte: se asignan desde su informe.
   * @return array{groups:list<array<string,mixed>>,unassigned:int}
   */
  public function campaigns(): array
  {
    $groups = [];
    $unassigned = 0;
    foreach ($this->list() as $row) {
      $slug = (string) ($row['meta']['utm_campaign'] ?? '');
      if ($slug === '') {
        $unassigned++;
        continue;
      }
      $groups[$slug] ??= ['slug' => $slug, 'label' => (string) (($row['meta']['campaign_label'] ?? '') ?: self::prettyCampaign($slug)), 'sends' => 0, 'total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'opened' => 0, 'tracked' => 0, 'last_at' => ''];
      $groups[$slug]['sends']++;
      foreach (['total', 'sent', 'failed', 'pending', 'opened', 'tracked'] as $key) {
        $groups[$slug][$key] += (int) $row[$key];
      }
      $groups[$slug]['last_at'] = max($groups[$slug]['last_at'], (string) $row['last_at']);
    }
    $list = array_values($groups);
    usort($list, static fn(array $a, array $b): int => strcmp($b['last_at'], $a['last_at']));
    return ['groups' => $list, 'unassigned' => $unassigned];
  }

  /**
   * Informe único de una campaña: suma sus envíos y agrega las visitas al sitio y los inmuebles vistos desde sus enlaces.
   * @return array<string,mixed>
   */
  public function campaignReport(string $slug): array
  {
    foreach ($this->campaigns()['groups'] as $group) {
      if ($group['slug'] === $slug) {
        $sends = array_values(array_filter($this->list(), static fn(array $row): bool => (string) ($row['meta']['utm_campaign'] ?? '') === $slug));
        $sends = array_map(static fn(array $row): array => array_diff_key($row, ['meta' => true]), $sends);
        return $group + ['sends_list' => $sends, 'visits' => $this->visits($slug), 'properties' => $this->campaignProperties($slug)];
      }
    }
    throw new \InvalidArgumentException('La campaña no existe.');
  }

  /** Inmuebles más vistos desde esa campaña (resúmenes de la Analítica web). @return list<array{ref:string,hits:int}> */
  private function campaignProperties(string $slug): array
  {
    try {
      $rows = $this->db->getResults("SELECT MAX(`value`) AS id, COALESCE(MAX(CASE WHEN `detail` <> '' AND `detail` NOT LIKE 'http%' THEN `detail` END), MAX(`detail`)) AS detail, SUM(`hits`) AS hits FROM `" . TrackerSchema::top($this->db) . "`
        WHERE `dim` = 'object' AND `camp_slug` = ? AND `bot` = 0 AND `internal` = 0 GROUP BY `value_hash` ORDER BY hits DESC LIMIT 5", [$slug]);
    } catch (\Throwable) {
      return [];
    }
    return array_map(static fn(array $row): array => ['ref' => (string) ($row['detail'] ?: 'ID ' . $row['id']), 'hits' => (int) $row['hits']], $rows);
  }

  /**
   * Asigna un envío ya hecho a una campaña, o lo saca de ella. Solo cambia la configuración del envío: los mensajes ya
   * enviados no se tocan y conservan sus enlaces.
   */
  public function assignCampaign(string $tag, string $slug, string $label): void
  {
    $this->requireCampaign($tag);
    $label = preg_replace('/\s+/u', ' ', trim(strip_tags($label))) ?? '';
    if (mb_strlen($label, 'UTF-8') > 60) {
      throw new \InvalidArgumentException('El nombre de la campaña admite hasta 60 caracteres.');
    }
    $slug = self::campaignSlug($slug, $label);
    if ($slug === '') {
      throw new \InvalidArgumentException('Elige la campaña.');
    }
    $config = $this->db->getRow("SELECT * FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$tag]) ?? [];
    $meta = ['utm_campaign' => $slug, 'campaign_label' => $label !== '' ? $label : self::prettyCampaign($slug)] + self::meta($config);
    $this->db->update($this->config, ['config_key' => 'campaign', 'config_value' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'cct_modified' => $this->localNow(), 'updated_at' => gmdate('Y-m-d H:i:s')], ['campaign_tag' => $tag]);
  }

  // ── Internos ────────────────────────────────────────────────────────────

  /**
   * Crea la campaña o actualiza su ritmo y lo que se envió (en config_value, JSON).
   * @param array<string,mixed> $spec
   * @return array<string,mixed>
   */
  private function saveConfig(array $spec, string $employee): array
  {
    $meta = ['channel' => $spec['channel'], 'category' => $spec['category'], 'subject' => $spec['subject'], 'message' => $spec['message'],
      'template_id' => $spec['template_id'], 'segments' => $spec['segments'], 'utm_campaign' => $spec['utm_campaign'], 'campaign_label' => $spec['campaign_label']];
    $row = $this->db->getRow("SELECT * FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$spec['name']]);
    $data = $spec['pace'] + ['config_key' => 'campaign', 'config_value' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'cct_modified' => $this->localNow(), 'updated_at' => gmdate('Y-m-d H:i:s')];
    if ($row === null) {
      $this->db->insert($this->config, $data + ['campaign_tag' => $spec['name'], 'auto_start' => 1, 'status' => 'active', 'id_funcionario' => (int) $employee,
        'cct_created' => $this->localNow(), 'created_at' => gmdate('Y-m-d H:i:s')]);
      return ['status' => 'active'];
    }
    if ((string) $row['status'] === 'cancelled') {
      $data['status'] = 'active';
    }
    $this->db->update($this->config, $data, ['campaign_tag' => $spec['name']]);
    return ['status' => (string) ($data['status'] ?? $row['status'])];
  }

  /** @param list<int> $ids */
  private function recordBatch(string $tag, string $channel, string $type, array $ids, string $employee): void
  {
    if (!$this->schema->tableExists($this->batches)) {
      return;
    }
    $next = (int) $this->db->getVar("SELECT COALESCE(MAX(batch_number), 0) + 1 FROM `{$this->batches}` WHERE campaign_tag = ? AND channel = ?", [$tag, $channel]);
    $this->db->insert($this->batches, [
      'campaign_tag' => $tag, 'batch_number' => max(1, $next), 'actor_ids' => json_encode(['tipo_actor' => $type, 'ids' => $ids], JSON_THROW_ON_ERROR),
      'status' => 'pending', 'batch_size' => count($ids), 'total_actors' => count($ids), 'sent_count' => 0, 'failed_count' => 0, 'channel' => $channel,
      'id_funcionario' => (int) $employee, 'cct_created' => $this->localNow(), 'cct_modified' => $this->localNow(),
    ]);
  }

  /** @param array<string,mixed> $config @return array<string,mixed> */
  private static function meta(array $config): array
  {
    if (($config['config_key'] ?? '') !== 'campaign') {
      return [];
    }
    $meta = json_decode((string) ($config['config_value'] ?? ''), true);
    return is_array($meta) ? $meta : [];
  }

  private function requireCampaign(string $tag): void
  {
    $this->requireQueue();
    if (trim($tag) === '' || (!$this->db->getVar("SELECT 1 FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$tag])
      && !$this->db->getVar('SELECT 1 FROM `' . self::QUEUE . '` WHERE `gda_campaign_tag` = ? LIMIT 1', [$tag]))) {
      throw new \InvalidArgumentException('La campaña no existe.');
    }
    if (!$this->db->getVar("SELECT 1 FROM `{$this->config}` WHERE campaign_tag = ? LIMIT 1", [$tag])) {
      // Campaña del panel anterior sin configuración: se crea para poder pausarla o cancelarla.
      $this->db->insert($this->config, ['campaign_tag' => $tag, 'status' => 'active', 'auto_start' => 1, 'cct_created' => $this->localNow(), 'cct_modified' => $this->localNow(),
        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')] + self::PACE_DEFAULTS);
    }
  }

  private function requireQueue(): void
  {
    if (!$this->schema->tableExists(self::QUEUE) || !$this->schema->tableExists($this->config)) {
      throw new \RuntimeException('Las tablas de campañas no están disponibles.');
    }
  }

  private function localNow(): string
  {
    return ($this->clock)()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
  }
}
