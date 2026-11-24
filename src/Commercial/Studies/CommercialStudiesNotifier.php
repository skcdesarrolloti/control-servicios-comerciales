<?php

declare(strict_types=1);

namespace SCM\Commercial\Studies;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Calls\CommercialCallsRepository;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\PublicStudyAccess;
use SCM\Support\SharedNotificationsBridge;

/**
 * Avisos del estudio de aseguradora. Reemplaza los correos fijos de los formularios de WordPress:
 * - al cliente, por correo y WhatsApp (plantillas fijas en el código, siempre activas), al enviar el estudio y con cada
 *   respuesta, con el enlace a su estudio (public/estudio.php);
 * - a quien hizo el estudio y a los funcionarios de Configuración › Notificaciones internas (estudio_creado,
 *   estudio_respondido, estudio_aprobado y estudio_negocio_desistido);
 * - al aprobar, al responsable de las entregas (su caso nuevo) y al propietario (revisión antes de la ocupación).
 * Quien bloqueó un canal en su ficha no lo recibe. Plantillas en docs/plantillas-whatsapp-tareas-comerciales.md.
 */
final class CommercialStudiesNotifier
{
  public const TEMPLATE_CREATED = 'scm_estudio_aseguradora_enviado_v1';
  public const TEMPLATE_ANSWERED = 'scm_estudio_aseguradora_respuesta_v1';
  public const EVENT_CREATED = 'estudio_creado';
  public const EVENT_ANSWERED = 'estudio_respondido';
  public const EVENT_APPROVED = 'estudio_aprobado';
  public const EVENT_DESISTED = 'estudio_negocio_desistido';

  private CommercialInternalNotifications $internal;
  private CommercialCallsRepository $clients;
  /** @var callable(string|list<string>,string,string,array<string,mixed>):void */
  private $enqueue;
  /** @var callable(string,string,list<array<string,mixed>>,array<string,mixed>):void */
  private $whatsapp;

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
    $this->internal = new CommercialInternalNotifications($db, $settings);
    $this->clients = new CommercialCallsRepository($db);
  }

  /**
   * @param array<string,mixed> $study
   * @param array<string,mixed> $task
   * @param array{id:string,nombre:string,correo:string,celular:string} $actor
   * @return array{correo:bool,whatsapp:bool}
   */
  public function studyCreated(int $id, array $study, array $task, array $actor): array
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $client = trim((string) ($study['cliente'] ?? ''));
    $insurer = trim((string) ($study['aseguradora'] ?? ''));
    $url = PublicStudyAccess::url($id);
    $contact = self::contact($task, $actor);
    $sent = $this->toClient($id, $study, $task, 'creado',
      $client . ', enviamos tu estudio #' . $id . ' a ' . $insurer . ' - SKC SuCasa Inmobiliaria', 'Tu estudio de aseguradora',
      '<p>Apreciado/a <strong>' . $e($client) . '</strong>, le informamos que sus documentos fueron remitidos a la aseguradora <strong>' . $e($insurer) . '</strong> para su estudio <strong>#' . $id . '</strong>'
      . (trim((string) ($study['num_solicitud'] ?? '')) !== '' ? ' (solicitud ' . $e($study['num_solicitud']) . ')' : '') . '.</p>'
      . '<p>En cuanto recibamos la respuesta le avisaremos de inmediato. En el enlace puede consultar el estado de su estudio cuando quiera.</p>'
      . '<p><strong>Su consultor/a asignado es</strong> ' . $e($contact) . '.</p>',
      self::TEMPLATE_CREATED, [$client, $insurer, (string) $id, $contact], $actor['correo']);

    $this->toStaff(array_merge([$actor['correo']], $this->internal->emails(self::EVENT_CREATED)), 'Estudio de aseguradora #' . $id . ' enviado · tarea #' . (int) ($study['id_ticket'] ?? 0),
      'Estudio de aseguradora enviado',
      '<p><strong>' . $e($actor['nombre']) . '</strong> envió el estudio <strong>#' . $id . '</strong> a <strong>' . $e($insurer) . '</strong> (solicitud ' . $e($study['num_solicitud'] ?? '') . ') para <strong>' . $e($client) . '</strong>'
      . ' en la tarea <strong>#' . (int) ($study['id_ticket'] ?? 0) . '</strong>.</p>' . self::channels($sent),
      $id, (int) ($study['id_ticket'] ?? 0), 'estudios:' . $id . ':creado:equipo');
    return $sent;
  }

  /**
   * @param array<string,mixed> $study Ya guardado con la respuesta.
   * @param array<string,mixed> $task
   * @param array{id:string,nombre:string,correo:string,celular:string} $actor
   * @param array{property:array<string,mixed>,owner:array<string,mixed>,responsable:array<string,mixed>,ticket:int,contract:int}|null $approval
   * @return array{correo:bool,whatsapp:bool}
   */
  public function studyAnswered(int $id, array $study, array $task, array $actor, string $state, string $detail, ?array $approval = null): array
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $client = trim((string) ($study['cliente'] ?? ''));
    $insurer = trim((string) ($study['aseguradora'] ?? ''));
    $label = CommercialStudiesService::CLIENT_STATES[$state][0] ?? $state;
    $contact = self::contact($task, $actor);
    $ticket = (int) ($study['id_ticket'] ?? 0);
    $place = '';
    if ($approval !== null) {
      $property = $approval['property'];
      $place = trim((string) ($property['tipo_inmueble'] ?? 'Inmueble')) . ' en ' . (trim((string) ($property['barrio'] ?? '')) ?: 'Cartagena') . ' (código ' . trim((string) $property['codigo']) . ')';
    }
    $sent = $this->toClient($id, $study, $task, 'respuesta:' . $state,
      $client . ', respuesta de tu estudio de aseguradora #' . $id . ' - SKC SuCasa Inmobiliaria', 'Respuesta de tu estudio de aseguradora',
      '<p>Apreciado/a <strong>' . $e($client) . '</strong>, la aseguradora <strong>' . $e($insurer) . '</strong> actualizó su estudio <strong>#' . $id . '</strong>: <strong>' . $e($label) . '</strong>.</p>'
      . '<p>' . nl2br($e($detail)) . '</p>'
      . ($place !== '' ? '<p><strong>Inmueble:</strong> ' . $e($place) . '</p>' : '')
      . '<p><strong>Su consultor/a asignado es</strong> ' . $e($contact) . '.</p>',
      self::TEMPLATE_ANSWERED, [$client, $insurer, (string) $id, $label, $detail, $contact], $actor['correo']);

    $creator = trim((string) ($study['email_creador'] ?? ''));
    $events = $this->internal->emails(self::EVENT_ANSWERED);
    $this->toStaff(array_merge([$actor['correo'], $creator], $events), 'Estudio #' . $id . ' ' . mb_strtolower($label, 'UTF-8') . ' · ' . $insurer, 'Respuesta de un estudio de aseguradora',
      '<p><strong>' . $e($actor['nombre']) . '</strong> registró la respuesta de <strong>' . $e($insurer) . '</strong> al estudio <strong>#' . $id . '</strong> de <strong>' . $e($client) . '</strong> (tarea <strong>#' . $ticket . '</strong>): <strong>' . $e($label) . '</strong>.</p>'
      . '<p>' . nl2br($e($detail)) . '</p>' . self::channels($sent),
      $id, $ticket, 'estudios:' . $id . ':respuesta:' . $state . ':equipo');

    if ($approval !== null) {
      $this->approved($id, $study, $actor, $approval, $place);
    }
    return $sent;
  }

  /**
   * Caso de entrega nuevo: al responsable de las entregas y a Notificaciones internas › Estudio aprobado; al propietario,
   * que se revisará el inmueble antes de la ocupación.
   * @param array<string,mixed> $study
   * @param array{id:string,nombre:string,correo:string,celular:string} $actor
   * @param array{property:array<string,mixed>,owner:array<string,mixed>,responsable:array<string,mixed>,ticket:int,contract:int} $approval
   */
  private function approved(int $id, array $study, array $actor, array $approval, string $place): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $responsible = $approval['responsable'];
    $owner = $approval['owner'];
    $deliveryDate = (int) ($study['fecha_posible_entrega'] ?? 0);
    $this->toStaff(array_merge([trim((string) ($responsible['correo'] ?? ''))], $this->internal->emails(self::EVENT_APPROVED)),
      'Estudio #' . $id . ' aprobado · caso de entrega #' . $approval['ticket'], 'Estudio aprobado: revisión antes de la ocupación',
      '<p>El estudio <strong>#' . $id . '</strong> de <strong>' . $e($study['cliente'] ?? '') . '</strong> con <strong>' . $e($study['aseguradora'] ?? '') . '</strong> (solicitud ' . $e($study['num_solicitud'] ?? '') . ') fue <strong>aprobado</strong>.'
      . ' Se creó el caso de entrega <strong>#' . $approval['ticket'] . '</strong> asignado a <strong>' . $e($responsible['nombre'] ?? '') . '</strong>'
      . ($approval['contract'] > 0 ? ' y el contrato <strong>#' . $approval['contract'] . '</strong> por entregar' : '') . '.</p>'
      . '<p><strong>Inmueble:</strong> ' . $e($place) . '<br><strong>Propietario:</strong> ' . $e($owner['nombre'] ?? '')
      . '<br><strong>Llaves:</strong> ' . $e(implode(' · ', array_filter([(string) ($study['ubicacion_llaves'] ?? ''), (string) ($study['persona_llaves'] ?? ''), (string) ($study['contacto_llaves'] ?? '')])))
      . ($deliveryDate > 0 ? '<br><strong>Fecha posible de entrega:</strong> ' . date('d/m/Y', $deliveryDate) : '')
      . '<br><strong>Consultor/a:</strong> ' . $e($actor['nombre']) . '</p>',
      $id, (int) ($study['id_ticket'] ?? 0), 'estudios:' . $id . ':aprobado:' . $approval['ticket']);

    $email = trim((string) ($owner['correo'] ?? ''));
    if ((int) ($owner['bloqueo_email'] ?? 0) !== 1 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
      $this->send($email, 'Revisión de su inmueble antes de la ocupación - SKC SuCasa Inmobiliaria', 'Revisión antes de la ocupación',
        '<p>Estimado/a <strong>' . $e($owner['nombre'] ?? '') . '</strong>, le informamos que el futuro arrendatario de su inmueble <strong>' . $e($place) . '</strong> fue aprobado por la aseguradora.</p>'
        . '<p>Registramos el caso <strong>#' . $approval['ticket'] . '</strong> para revisar el inmueble antes de la ocupación. <strong>' . $e($responsible['nombre'] ?? '') . '</strong>'
        . (trim((string) ($responsible['celular'] ?? '')) !== '' ? ' (' . $e($responsible['celular']) . ')' : '') . ' estará en contacto con usted para coordinar la cita.</p>'
        . '<p>Si tiene alguna inquietud, puede responder este correo.</p>',
        [], 'estudios:' . $id . ':propietario:' . $approval['ticket'], trim((string) ($responsible['correo'] ?? '')));
    }
  }

  /**
   * Negocio desistido: a Notificaciones internas › Negocio desistido, a quien hizo el estudio y al responsable del caso de
   * entrega (como ClosingSheetDesist::notify del panel inmobiliario).
   * @param array<string,mixed> $study
   * @param array{id:string,nombre:string,correo:string,celular:string} $actor
   * @param array<string,mixed>|null $delivery
   */
  public function businessDesisted(int $id, array $study, array $actor, string $reason, string $note, bool $changeProperty, ?array $delivery): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $code = trim((string) ($study['id_inmueble'] ?? ''));
    $this->toStaff(array_merge([trim((string) ($study['email_creador'] ?? '')), trim((string) ($delivery['correo_empleado'] ?? ''))], $this->internal->emails(self::EVENT_DESISTED)),
      'Negocio desistido · estudio de aseguradora #' . $id . ($code !== '' ? ' · inmueble #' . $code : ''), 'Negocio desistido',
      '<p><strong>' . $e($actor['nombre']) . '</strong> desistió el negocio del estudio <strong>#' . $id . '</strong> de <strong>' . $e($study['cliente'] ?? '') . '</strong> con <strong>' . $e($study['aseguradora'] ?? '') . '</strong>.</p>'
      . '<p>Motivo: <strong>' . $e($reason) . '</strong>.' . ($note !== '' ? '<br>Observación: ' . nl2br($e($note)) : '') . '</p>'
      . '<p>El inmueble' . ($code !== '' ? ' <strong>#' . $e($code) . '</strong>' : '') . ' quedó <strong>En borrador</strong>: revísalo, actualízalo y publícalo de nuevo si requiere promoción.'
      . ($delivery !== null ? ' El caso de entrega <strong>#' . (int) $delivery['_ID'] . '</strong> se cerró como desistido.' : '')
      . ($changeProperty ? ' Como es un cambio de inmueble, el estudio volvió a <strong>Esperando respuesta</strong> para aprobarlo con el otro inmueble.' : '') . '</p>',
      $id, (int) ($study['id_ticket'] ?? 0), 'estudios:' . $id . ':desistido:' . date('YmdHi'));
  }

  /**
   * Correo y WhatsApp al cliente del estudio.
   * @param array<string,mixed> $study
   * @param array<string,mixed> $task
   * @param list<string> $values
   * @return array{correo:bool,whatsapp:bool}
   */
  private function toClient(int $id, array $study, array $task, string $kind, string $subject, string $title, string $body, string $template, array $values, string $replyTo): array
  {
    $url = PublicStudyAccess::url($id);
    $preferences = $this->clients->clientRow(CommercialCallsRepository::digits((string) ($study['celular_cliente'] ?? '')));
    $sent = ['correo' => false, 'whatsapp' => false];
    $email = trim((string) ($study['correo_cliente'] ?? ''));
    if ((int) ($preferences['bloqueo_email'] ?? 0) !== 1 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
      $sent['correo'] = $this->send($email, $subject, $title, $body, [['url' => $url, 'label' => 'Ver mi estudio']], 'estudios:' . $id . ':' . $kind . ':cliente', $replyTo);
    }
    if ((int) ($preferences['bloqueo_whatsapp'] ?? 0) !== 1) {
      $sent['whatsapp'] = $this->whatsappTo((string) ($study['celular_cliente'] ?? ''), trim((string) ($task['indicativo'] ?? '')) ?: '57', $template, $values, PublicStudyAccess::reference($id), 'estudios:' . $id . ':' . $kind . ':whatsapp');
    }
    return $sent;
  }

  /** @param list<string> $emails Se quitan repetidos y vacíos; cada uno recibe el enlace al estudio y a la tarea. */
  private function toStaff(array $emails, string $subject, string $title, string $body, int $id, int $ticket, string $dedupe): void
  {
    $to = [];
    foreach ($emails as $email) {
      $email = trim((string) $email);
      if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        $to[strtolower($email)] = $email;
      }
    }
    if ($to === []) {
      return;
    }
    $buttons = [['url' => PublicStudyAccess::url($id), 'label' => 'Ver estudio']];
    if ($ticket > 0) {
      $buttons[] = ['url' => CommercialCallsNotifier::panelUrl($ticket), 'label' => 'Ver tarea'];
    }
    $this->send(array_values($to), $subject, $title, $body, $buttons, $dedupe);
  }

  /** @param array{correo:bool,whatsapp:bool} $sent */
  private static function channels(array $sent): string
  {
    $channels = array_keys(array_filter($sent));
    return '<p>' . ($channels === [] ? 'No se le pudo avisar al cliente: revisa su correo y su celular.' : 'Se le avisó al cliente por ' . implode(' y ', array_map(static fn(string $channel): string => $channel === 'correo' ? 'correo' : 'WhatsApp', $channels)) . '.') . '</p>';
  }

  /**
   * Consultor/a asignado a la tarea (nombre · correo · celular); si la tarea no tiene, quien hizo el estudio.
   * @param array<string,mixed> $task
   * @param array{nombre:string,correo:string,celular:string} $actor
   */
  private static function contact(array $task, array $actor): string
  {
    return CommercialCallsNotifier::contactLine(trim((string) ($task['nombre_empleado'] ?? '')) !== '' ? $task : ['nombre_empleado' => $actor['nombre'], 'correo_empleado' => $actor['correo'], 'celular_empleado' => $actor['celular']]);
  }

  /** @param list<mixed> $values Variables del cuerpo: Meta no acepta vacíos, saltos de línea ni textos muy largos. */
  private function whatsappTo(string $rawPhone, string $country, string $template, array $values, string $buttonValue, string $dedupe): bool
  {
    $phone = CommercialCallsNotifier::phone($rawPhone, $country);
    if ($phone === '') {
      return false;
    }
    $parameters = array_map(static function ($value): array {
      $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
      return ['type' => 'text', 'text' => $text !== '' ? mb_substr(rtrim($text, '.'), 0, 180) : '-'];
    }, $values);
    $components = [
      ['type' => 'body', 'parameters' => $parameters],
      ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonValue]]],
    ];
    try {
      ($this->whatsapp)($phone, $template, $components, ['source_module' => 'estudios-whatsapp', 'dedupe_key' => $dedupe, 'priority' => 100, 'template_language' => CommercialCallsNotifier::TEMPLATE_LANGUAGE]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[estudios:whatsapp] ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * @param string|list<string> $to
   * @param list<array{url:string,label:string}> $buttons
   */
  private function send($to, string $subject, string $title, string $body, array $buttons, string $dedupe, string $replyTo = ''): bool
  {
    if ($to === '' || $to === []) {
      return false;
    }
    $buttons[] = ['url' => function_exists('system_image') ? system_image('link_revista', 'https://sucasainmobiliaria.com.co/revista/') : 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'];
    $html = str_replace('Su Casa Inmobiliaria', 'SKC SuCasa Inmobiliaria', EmailTemplate::render($title, $body . '<div style="margin-top:20px;text-align:center;">' . EmailTemplate::buttons($buttons) . '</div>'));
    $payload = array_filter(['reply_to' => $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : '']);
    try {
      ($this->enqueue)($to, $subject, $html, ['source_module' => 'estudios', 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'payload' => $payload]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[estudios:correo] ' . $exception->getMessage());
      return false;
    }
  }
}
