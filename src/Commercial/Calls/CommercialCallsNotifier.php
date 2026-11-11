<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialInternalNotifications;
use SCM\Commercial\Promotion\StaffCardService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\PublicTaskAccess;
use SCM\Support\SharedNotificationsBridge;

/**
 * Avisos de las tareas comerciales: tarea creada, mensaje del cliente y respuesta del funcionario. Todo va por
 * shared-notifications. Los canales y las plantillas de WhatsApp se configuran en Configuración › Llamadas, y las copias
 * internas en Configuración › Notificaciones internas. Quién bloqueó un canal en su ficha de cliente no lo recibe.
 */
final class CommercialCallsNotifier
{
  public const SETTING = 'commercial_calls_notifications';
  public const TEMPLATES = [
    'plantilla_asignada' => 'scm_tarea_comercial_asignada_v1',
    'plantilla_registrada' => 'scm_tarea_comercial_registrada_v1',
    'plantilla_respuesta_cliente' => 'scm_tarea_comercial_respuesta_cliente_v1',
    'plantilla_respuesta_funcionario' => 'scm_tarea_comercial_respuesta_funcionario_v1',
  ];
  public const DEFAULTS = ['asignado' => true, 'solicitante' => true, 'administradores' => true, 'whatsapp_asignado' => true, 'whatsapp_solicitante' => true, 'idioma' => 'es_CO', 'admin_emails' => []] + self::TEMPLATES;

  private const BROCHURE = 'https://sucasainmobiliaria.com.co/brochure-skc-sucasa-inmobiliaria/';

  private CommercialInternalNotifications $internal;
  private CommercialCallsRepository $repository;
  private StaffCardService $cards;
  /** @var callable(string|list<string>,string,string,array<string,mixed>):void */
  private $enqueue;
  /** @var callable(string,string,list<array<string,mixed>>,array<string,mixed>):void */
  private $whatsapp;
  /** @var array<string,mixed> */
  private array $settings;

  /**
   * @param (callable(string|list<string>,string,string,array<string,mixed>):void)|null $enqueue Correo; por defecto, shared-notifications.
   * @param (callable(string,string,list<array<string,mixed>>,array<string,mixed>):void)|null $whatsapp Por defecto, shared-notifications.
   */
  public function __construct(Database $db, Settings $settings, ?callable $enqueue = null, ?callable $whatsapp = null)
  {
    $this->enqueue = $enqueue ?? [new EmailQueue($db), 'enqueue'];
    $this->whatsapp = $whatsapp ?? static function (string $phone, string $template, array $components, array $options) use ($db): void {
      $bridge = new SharedNotificationsBridge($db);
      $queue = $bridge->queue();
      if ($queue !== null) {
        $queue->enqueueWhatsAppOfficialTemplate($phone, $template, $components, $options + ['project_code' => $bridge->projectCode(), 'created_by' => 'control-servicios-comerciales']);
      }
    };
    $this->settings = self::settings($settings);
    $this->internal = new CommercialInternalNotifications($db, $settings);
    $this->repository = new CommercialCallsRepository($db);
    $this->cards = new StaffCardService($db);
  }

  /** @return array<string,mixed> */
  public static function settings(Settings $settings): array
  {
    $raw = $settings->get(self::SETTING, []);
    $raw = is_array($raw) ? $raw : [];
    $out = [];
    foreach (['asignado', 'solicitante', 'administradores', 'whatsapp_asignado', 'whatsapp_solicitante'] as $key) {
      $out[$key] = (bool) ($raw[$key] ?? self::DEFAULTS[$key]);
    }
    foreach (self::TEMPLATES as $key => $default) {
      $value = is_scalar($raw[$key] ?? null) ? trim((string) $raw[$key]) : '';
      $out[$key] = $value !== '' ? $value : $default;
    }
    $language = is_scalar($raw['idioma'] ?? null) ? trim((string) $raw['idioma']) : '';
    $out['idioma'] = $language !== '' ? $language : self::DEFAULTS['idioma'];
    $out['admin_emails'] = array_values(array_filter(array_map('strval', (array) ($raw['admin_emails'] ?? [])), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
    return $out;
  }

  /**
   * Valida lo que llega de Configuración › Llamadas (mismas reglas de PanelCaseNotifications::validateConfig del panel inmobiliario).
   * @param array<string,mixed> $data
   * @param array<string,mixed> $current
   * @return array<string,mixed>
   */
  public static function validated(array $data, array $current): array
  {
    $out = ['admin_emails' => $current['admin_emails'] ?? [], 'administradores' => (bool) ($current['administradores'] ?? true)];
    foreach (['asignado', 'solicitante', 'whatsapp_asignado', 'whatsapp_solicitante'] as $key) {
      $out[$key] = !empty($data[$key]);
    }
    foreach (self::TEMPLATES as $key => $default) {
      $value = is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
      $value = $value !== '' ? $value : $default;
      if (preg_match('/^[a-z0-9_]{1,512}$/D', $value) !== 1) {
        throw new \InvalidArgumentException('Usa nombres de plantillas con minúsculas, números y guiones bajos.');
      }
      $out[$key] = $value;
    }
    $language = is_scalar($data['idioma'] ?? null) ? trim((string) $data['idioma']) : '';
    $language = $language !== '' ? $language : self::DEFAULTS['idioma'];
    if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/D', $language) !== 1) {
      throw new \InvalidArgumentException('Indica el código exacto del idioma de las plantillas, por ejemplo es_CO.');
    }
    $out['idioma'] = $language;
    return $out;
  }

  /**
   * @param array<string,mixed> $task Fila guardada de la tarea.
   * @param array<string,mixed> $branch Sucursal, para firmar el correo al solicitante.
   */
  public function taskCreated(int $id, array $task, array $branch): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $topic = $e($task['tema_ayuda'] ?? '');
    $employee = $e($task['nombre_empleado'] ?? '');
    $staffButtons = $this->buttons(self::panelUrl($id), 'Ver tarea');
    $staff = $this->staff($task, 'llamada_tarea_creada');
    if ($this->settings['asignado']) {
      $this->send($task['correo_empleado'] ?? '', 'Nueva tarea #' . $id . ' asignada', 'Nueva tarea comercial',
        '<p>Estimado/a <strong>' . $employee . '</strong>, se te asignó una nueva tarea comercial de <strong>' . $topic . '</strong> sobre <strong>' . $e($task['asunto'] ?? '') . '</strong>.</p>',
        $staffButtons, 'llamadas:tarea:' . $id . ':asignado');
    }
    $copies = $this->copies($staff);
    if ($copies === [] && $this->settings['administradores']) {
      // Lista anterior de Configuración › Llamadas: sigue funcionando hasta que se marquen funcionarios en Notificaciones internas.
      $copies = $this->settings['admin_emails'];
    }
    if ($copies !== []) {
      $this->send($copies, 'Tarea comercial #' . $id . ' creada', 'Nueva tarea comercial',
        '<p>Se creó una tarea comercial de <strong>' . $topic . '</strong> desde el medio <strong>' . $e($task['medio'] ?? '') . '</strong>, asignada a <strong>' . $employee . '</strong>.</p>',
        $staffButtons, 'llamadas:tarea:' . $id . ':admin');
    }
    if ($this->settings['whatsapp_asignado']) {
      foreach ($staff as $person) {
        $this->whatsappTo($person['celular'], '57', (string) $this->settings['plantilla_asignada'],
          [$person['nombre'], (string) $id, $task['solicitante'] ?? '', $task['tema_ayuda'] ?? '', $task['medio'] ?? '', $task['nombre_empleado'] ?? '', $task['correo_empleado'] ?? '', $task['celular_empleado'] ?? ''], (string) $id,
          'llamadas:tarea:' . $id . ':whatsapp:' . $person['id']);
      }
    }
    $client = $this->clientPreferences($task);
    if ($this->settings['solicitante'] && !$this->blocked($client, 'email')) {
      $manager = trim((string) ($branch['gerente_comercial'] ?? ''));
      $signature = $manager !== ''
        ? '<p>Cordialmente:<br><strong>' . $e($manager) . '</strong> · Coordinación comercial' . (trim((string) ($branch['celular_ge_comercial'] ?? '')) !== '' ? ' · ' . $e($branch['celular_ge_comercial']) : '') . '</p>'
        : '<p>Cordialmente,<br><strong>Equipo comercial</strong></p>';
      $this->send($task['correo_solicitante'] ?? '', 'Solicitud #' . $id . ' agendada', 'Registramos tu solicitud',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, registramos tu solicitud de <strong>' . $topic . '</strong> y la asignamos a <strong>' . $employee
        . '</strong>' . (trim((string) ($task['celular_empleado'] ?? '')) !== '' ? ' (' . $e($task['celular_empleado']) . ')' : '') . '.</p><p>Desde el botón puedes ver tu solicitud y escribirnos. Su atención será evaluada para garantizar un servicio óptimo.</p>' . $signature,
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), 'llamadas:tarea:' . $id . ':solicitante', (string) ($task['correo_empleado'] ?? ''));
    }
    if ($this->settings['whatsapp_solicitante'] && !$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), (string) $this->settings['plantilla_registrada'],
        [$task['solicitante'] ?? '', (string) $id, $task['tema_ayuda'] ?? '', $task['nombre_empleado'] ?? '', $task['correo_empleado'] ?? '', $task['celular_empleado'] ?? ''], PublicTaskAccess::reference($id),
        'llamadas:tarea:' . $id . ':whatsapp:solicitante', $this->cardImage((string) ($task['id_empleado'] ?? '')));
    }
  }

  /**
   * El cliente escribió desde su enlace: lo reciben el responsable y las copias internas.
   * @param array<string,mixed> $task
   */
  public function clientReplied(int $id, array $task, string $message): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $key = 'llamadas:tarea:' . $id . ':cliente:' . substr(hash('sha256', $message), 0, 16);
    $staff = $this->staff($task, 'llamada_respuesta_cliente');
    $body = '<p>El cliente <strong>' . $e($task['solicitante'] ?? '') . '</strong> escribió en la tarea comercial <strong>#' . $id . '</strong>:</p><blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($message)) . '</blockquote>';
    $emails = array_values(array_unique(array_filter(array_merge($this->settings['asignado'] ? [(string) ($task['correo_empleado'] ?? '')] : [], $this->copies($staff)))));
    if ($emails !== []) {
      $this->send($emails, 'El cliente respondió la tarea #' . $id, 'Respuesta del cliente', $body, $this->buttons(self::panelUrl($id), 'Ver tarea'), $key);
    }
    if ($this->settings['whatsapp_asignado']) {
      foreach ($staff as $person) {
        $this->whatsappTo($person['celular'], '57', (string) $this->settings['plantilla_respuesta_cliente'],
          [$person['nombre'], $task['solicitante'] ?? '', (string) $id, $message], (string) $id, $key . ':whatsapp:' . $person['id']);
      }
    }
  }

  /**
   * El funcionario respondió desde el panel marcando avisar al cliente.
   * @param array<string,mixed> $task
   */
  public function officerReplied(int $id, array $task, string $message, string $author, string $employee = ''): void
  {
    // Contacto de quien respondió; si no se conoce, el del responsable de la tarea.
    $staff = $this->repository->employee($employee) ?? [];
    $email = trim((string) ($staff['correo'] ?? '')) ?: (string) ($task['correo_empleado'] ?? '');
    $phone = trim((string) ($staff['celular'] ?? '')) ?: (string) ($task['celular_empleado'] ?? '');
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $key = 'llamadas:tarea:' . $id . ':funcionario:' . substr(hash('sha256', $message), 0, 16);
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $this->send($task['correo_solicitante'] ?? '', 'Respuesta a tu solicitud #' . $id, 'Tienes una respuesta',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author) . '</strong> respondió tu solicitud <strong>#' . $id . '</strong>:</p><blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($message)) . '</blockquote>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), $key, (string) ($task['correo_empleado'] ?? ''));
    }
    if ($this->settings['whatsapp_solicitante'] && !$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), (string) $this->settings['plantilla_respuesta_funcionario'],
        [$task['solicitante'] ?? '', $author, (string) $id, $email, $phone], PublicTaskAccess::reference($id), $key . ':whatsapp');
    }
  }

  public static function panelUrl(int $id): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/index.php?tab=abiertos&ticket_id=' . $id;
  }

  /**
   * Celular en formato internacional. Copia de PanelCaseNotifications::phone (control-servicios-inmobiliarios src/Support).
   */
  public static function phone(string $raw, string $country = '57'): string
  {
    $digits = (string) preg_replace('/\D+/', '', $raw);
    if ($digits === '' || str_contains($raw, '@')) {
      return '';
    }
    if (strlen($digits) === 10 && !str_starts_with(trim($raw), '+')) {
      $digits = ((string) preg_replace('/\D+/', '', $country) ?: '57') . $digits;
    }
    return preg_match('/^[1-9][0-9]{10,14}$/D', $digits) === 1 ? '+' . $digits : '';
  }

  /** @param array<string,mixed> $task */
  private static function clientPhone(array $task): string
  {
    return trim((string) ($task['celular_wsp'] ?? '')) ?: trim((string) ($task['celular_solicitante'] ?? ''));
  }

  /**
   * El responsable y los funcionarios marcados en el evento, una vez cada uno.
   * @param array<string,mixed> $task
   * @return list<array{id:string,nombre:string,correo:string,celular:string,copia:bool}>
   */
  private function staff(array $task, string $event): array
  {
    $assigned = trim((string) ($task['id_empleado'] ?? ''));
    $people = [['id' => $assigned, 'nombre' => (string) ($task['nombre_empleado'] ?? ''), 'correo' => (string) ($task['correo_empleado'] ?? ''), 'celular' => (string) ($task['celular_empleado'] ?? ''), 'copia' => false]];
    foreach ($this->internal->recipients($event) as $recipient) {
      if ($recipient['id'] !== $assigned) {
        $people[] = $recipient + ['copia' => true];
      }
    }
    return $people;
  }

  /**
   * @param list<array{id:string,nombre:string,correo:string,celular:string,copia:bool}> $staff
   * @return list<string>
   */
  private function copies(array $staff): array
  {
    $emails = [];
    foreach ($staff as $person) {
      if ($person['copia'] && filter_var($person['correo'], FILTER_VALIDATE_EMAIL) !== false) {
        $emails[] = $person['correo'];
      }
    }
    return $emails;
  }

  /**
   * @param array<string,mixed> $task
   * @return array<string,mixed>
   */
  private function clientPreferences(array $task): array
  {
    return $this->repository->clientRow(CommercialCallsRepository::digits((string) ($task['celular_solicitante'] ?? '')));
  }

  /** @param array<string,mixed> $client */
  private function blocked(array $client, string $channel): bool
  {
    return (int) ($client['bloqueo_' . $channel] ?? 0) === 1;
  }

  /** @return list<array{url:string,label:string}> */
  private function buttons(string $url, string $label): array
  {
    return [
      ['url' => $url, 'label' => $label],
      ['url' => self::BROCHURE, 'label' => 'Ver brochure'],
      ['url' => function_exists('system_image') ? system_image('link_revista', 'https://sucasainmobiliaria.com.co/revista/') : 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'],
    ];
  }

  /**
   * @param list<mixed> $values Variables del cuerpo: Meta no acepta vacíos, saltos de línea ni textos muy largos.
   */
  /**
   * Imagen del encabezado de la plantilla registrada: la ficha del funcionario (Promoción › Fichas de funcionarios) o, si no
   * tiene, el banner de los correos. La plantilla tiene encabezado de imagen, así que nunca se envía sin ella.
   */
  private function cardImage(string $employee): string
  {
    $card = $this->cards->cardOf($employee);
    if ($card !== '') {
      return $card;
    }
    $logo = function_exists('system_image') ? system_image('portal_logo_url') : '';
    return function_exists('system_image') ? system_image('banner', $logo) : $logo;
  }

  private function whatsappTo(string $rawPhone, string $country, string $template, array $values, string $buttonValue, string $dedupe, string $headerImage = ''): void
  {
    $phone = self::phone($rawPhone, $country);
    if ($phone === '') {
      return;
    }
    $parameters = array_map(static function ($value): array {
      $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
      return ['type' => 'text', 'text' => $text !== '' ? mb_substr($text, 0, 180) : '-'];
    }, $values);
    $components = $headerImage !== '' ? [['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $headerImage]]]]] : [];
    array_push($components,
      ['type' => 'body', 'parameters' => $parameters],
      ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonValue]]]
    );
    try {
      ($this->whatsapp)($phone, $template, $components, ['source_module' => 'llamadas-whatsapp', 'dedupe_key' => $dedupe, 'priority' => 100, 'template_language' => (string) $this->settings['idioma']]);
    } catch (\Throwable $exception) {
      error_log('[llamadas:whatsapp] ' . $exception->getMessage());
    }
  }

  /**
   * @param mixed $to
   * @param list<array{url:string,label:string}> $buttons
   */
  private function send($to, string $subject, string $title, string $body, array $buttons, string $dedupe, string $replyTo = ''): void
  {
    if ($to === '' || $to === []) {
      return;
    }
    // La plantilla compartida firma con otro nombre; aquí va el nombre corporativo.
    $html = str_replace('Su Casa Inmobiliaria', 'SKC SuCasa Inmobiliaria', EmailTemplate::render($title, $body . '<div style="margin-top:20px;text-align:center;">' . EmailTemplate::buttons($buttons) . '</div>'));
    $headers = $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? ['Reply-To: ' . $replyTo] : [];
    try {
      ($this->enqueue)($to, $subject, $html, ['source_module' => 'llamadas', 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'meta' => ['headers' => $headers]]);
    } catch (\Throwable $exception) {
      error_log('[llamadas:correo] ' . $exception->getMessage());
    }
  }
}
