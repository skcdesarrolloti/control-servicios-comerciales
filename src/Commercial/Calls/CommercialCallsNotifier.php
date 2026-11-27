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
use SCM\Support\SharedButtonUrl;
use SCM\Support\SharedNotificationsBridge;
use SCM\Support\StaffTaskLink;

/**
 * Avisos de las tareas comerciales: tarea creada, traslado, mensaje del cliente y respuesta del funcionario. Todo va por
 * shared-notifications. Los canales (correo y WhatsApp al responsable y al cliente) siempre están activos y las plantillas
 * de WhatsApp son fijas en el código (docs/); las copias internas se eligen en Configuración › Notificaciones internas.
 * Quién bloqueó un canal en su ficha de cliente no lo recibe. Lo que va a un solo funcionario lleva su enlace individual
 * (StaffTaskLink::url: inicia su sesión y abre la tarea); los correos con copias, el que solo abre la tarea.
 */
final class CommercialCallsNotifier
{
  /** Configuración anterior de Llamadas: solo se sigue leyendo su lista de correos de copia (admin_emails). */
  public const SETTING = 'commercial_calls_notifications';
  public const TEMPLATE_ASSIGNED = 'scm_tarea_comercial_asignada_v1';
  public const TEMPLATE_REGISTERED = 'scm_tarea_comercial_registrada_v1';
  public const TEMPLATE_CLIENT_REPLY = 'scm_tarea_comercial_respuesta_cliente_v1';
  public const TEMPLATE_OFFICER_REPLY = 'scm_tarea_comercial_respuesta_funcionario_v1';
  public const TEMPLATE_POSTPONED = 'scm_tarea_comercial_postergada_v1';
  public const TEMPLATE_CLOSED = 'scm_tarea_comercial_cerrada_v1';
  public const TEMPLATE_FOLLOW_UP = 'scm_tarea_comercial_seguimiento_v1';
  public const EVENT_FOLLOW_UP = 'tarea_seguimiento_registrado';
  public const EVENT_OFFICER_REPLY = 'tarea_respuesta_funcionario';
  /** Al cliente cuando su tarea cerrada o postergada vuelve a gestión activa (Activar en el popup). */
  public const TEMPLATE_REOPENED = 'scm_tarea_comercial_reabierta_v1';
  /** A funcionarios cuando una tarea se posterga, se cierra o se reabre: una sola plantilla con el movimiento en una variable. */
  public const TEMPLATE_STAFF_MOVED = 'scm_tarea_comercial_movimiento_v1';
  public const EVENT_POSTPONED = 'tarea_postergada';
  public const EVENT_CLOSED = 'tarea_cerrada';
  public const EVENT_REOPENED = 'tarea_reabierta';
  /** Cierres que no se le cuentan al cliente: la gestión sigue en otra tarea o con otro responsable. */
  public const SILENT_CLOSURES = ['Duplicado', 'Trasladado'];
  /** Compartida con control-servicios-inmobiliarios: su botón es el dominio + {{1}} (SharedButtonUrl). */
  public const TEMPLATE_TRANSFERRED = 'pqr_trasladada_funcionario_v1';
  /** Al cliente cuando su tarea pasa a otro consultor/a; encabezado con la ficha del nuevo consultor/a. */
  public const TEMPLATE_CLIENT_TRANSFERRED = 'scm_tarea_comercial_trasladada_cliente_v1';
  public const TEMPLATE_LANGUAGE = 'es_CO';

  private const BROCHURE = 'https://sucasainmobiliaria.com.co/brochure-skc-sucasa-inmobiliaria/';

  private CommercialInternalNotifications $internal;
  private CommercialCallsRepository $repository;
  private StaffCardService $cards;
  /** @var callable(string|list<string>,string,string,array<string,mixed>):void */
  private $enqueue;
  /** @var callable(string,string,list<array<string,mixed>>,array<string,mixed>):void */
  private $whatsapp;
  /** @var list<string> Correos de copia de la configuración anterior, mientras no se marquen funcionarios. */
  private array $legacyCopies;

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
    $this->legacyCopies = self::legacyCopies($settings);
    $this->internal = new CommercialInternalNotifications($db, $settings);
    $this->repository = new CommercialCallsRepository($db);
    $this->cards = new StaffCardService($db);
  }

  /** @return list<string> */
  private static function legacyCopies(Settings $settings): array
  {
    $raw = $settings->get(self::SETTING, []);
    $raw = is_array($raw) ? $raw : [];
    if (array_key_exists('administradores', $raw) && !$raw['administradores']) {
      return [];
    }
    return array_values(array_filter(array_map('strval', (array) ($raw['admin_emails'] ?? [])), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
  }

  /**
   * @param array<string,mixed> $task Fila guardada de la tarea.
   * @param array<string,mixed> $branch Sucursal, para firmar el correo al solicitante.
   * @param string $event Evento de Notificaciones internas que recibe la copia (la tarea puede salir de una llamada o de una precaptación).
   */
  public function taskCreated(int $id, array $task, array $branch, string $event = 'llamada_tarea_creada'): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $topic = $e($task['tema_ayuda'] ?? '');
    $employee = $e($task['nombre_empleado'] ?? '');
    $staff = $this->staff($task, $event);
    $this->send($task['correo_empleado'] ?? '', 'Nueva tarea #' . $id . ' asignada', 'Nueva tarea comercial',
      '<p>Estimado/a <strong>' . $employee . '</strong>, se te asignó una nueva tarea comercial de <strong>' . $topic . '</strong> sobre <strong>' . $e($task['asunto'] ?? '') . '</strong>.</p>',
      $this->buttons(StaffTaskLink::url($id, (string) ($task['id_empleado'] ?? '')), 'Ver tarea'), 'llamadas:tarea:' . $id . ':asignado');
    $copies = $this->copies($staff);
    if ($copies === [] && $event === 'llamada_tarea_creada') {
      // Lista anterior de Configuración › Llamadas: sigue funcionando hasta que se marquen funcionarios en Notificaciones internas.
      $copies = $this->legacyCopies;
    }
    if ($copies !== []) {
      $this->send($copies, 'Tarea comercial #' . $id . ' creada', 'Nueva tarea comercial',
        '<p>Se creó una tarea comercial de <strong>' . $topic . '</strong> desde el medio <strong>' . $e($task['medio'] ?? '') . '</strong>, asignada a <strong>' . $employee . '</strong>.</p>',
        $this->buttons(self::panelUrl($id), 'Ver tarea'), 'llamadas:tarea:' . $id . ':admin');
    }
    foreach ($staff as $person) {
      $this->whatsappTo($person['celular'], '57', self::TEMPLATE_ASSIGNED,
        [$person['nombre'], (string) $id, $task['solicitante'] ?? '', $task['tema_ayuda'] ?? '', $task['medio'] ?? '', self::contactLine($task)], StaffTaskLink::reference($id, $person['id']),
        'llamadas:tarea:' . $id . ':whatsapp:' . $person['id']);
    }
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $manager = trim((string) ($branch['gerente_comercial'] ?? ''));
      $signature = $manager !== ''
        ? '<p>Cordialmente:<br><strong>' . $e($manager) . '</strong> · Coordinación comercial' . (trim((string) ($branch['celular_ge_comercial'] ?? '')) !== '' ? ' · ' . $e($branch['celular_ge_comercial']) : '') . '</p>'
        : '<p>Cordialmente,<br><strong>Equipo comercial</strong></p>';
      $this->send($task['correo_solicitante'] ?? '', 'Tarea #' . $id . ' agendada', 'Registramos tu tarea',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, registramos tu tarea de <strong>' . $topic . '</strong> y la asignamos a <strong>' . $employee
        . '</strong>' . (trim((string) ($task['celular_empleado'] ?? '')) !== '' ? ' (' . $e($task['celular_empleado']) . ')' : '') . '.</p><p>Desde el botón puedes ver tu tarea y escribirnos. Su atención será evaluada para garantizar un servicio óptimo.</p>' . $signature,
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), 'llamadas:tarea:' . $id . ':solicitante', (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_REGISTERED,
        [$task['solicitante'] ?? '', (string) $id, $task['tema_ayuda'] ?? '', self::contactLine($task)], PublicTaskAccess::reference($id),
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
    $assigned = trim((string) ($task['correo_empleado'] ?? ''));
    $this->send($assigned, 'El cliente respondió la tarea #' . $id, 'Respuesta del cliente', $body,
      $this->buttons(StaffTaskLink::url($id, (string) ($task['id_empleado'] ?? '')), 'Ver tarea'), $key);
    $copies = array_values(array_diff(array_unique($this->copies($staff)), [$assigned]));
    if ($copies !== []) {
      $this->send($copies, 'El cliente respondió la tarea #' . $id, 'Respuesta del cliente', $body, $this->buttons(self::panelUrl($id), 'Ver tarea'), $key . ':copias');
    }
    foreach ($staff as $person) {
      $this->whatsappTo($person['celular'], '57', self::TEMPLATE_CLIENT_REPLY,
        [$person['nombre'], $task['solicitante'] ?? '', (string) $id, $message], StaffTaskLink::reference($id, $person['id']), $key . ':whatsapp:' . $person['id']);
    }
  }

  /**
   * El funcionario respondió desde el panel: al cliente (salvo el canal que haya bloqueado) y, como movimiento de la tarea,
   * al responsable (si respondió otra persona) y a los marcados en Notificaciones internas › Respuesta enviada al cliente.
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
      $this->send($task['correo_solicitante'] ?? '', 'Respuesta a tu tarea #' . $id, 'Tienes una respuesta',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author) . '</strong> respondió tu tarea <strong>#' . $id . '</strong>:</p><blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($message)) . '</blockquote>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), $key, (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_OFFICER_REPLY,
        [$task['solicitante'] ?? '', (string) $id, self::contactLine(['nombre_empleado' => $author, 'correo_empleado' => $email, 'celular_empleado' => $phone])], PublicTaskAccess::reference($id), $key . ':whatsapp');
    }
    $this->taskMoved($id, $task, self::EVENT_OFFICER_REPLY, 'Respuesta enviada al cliente', $message, $author, $employee);
  }

  /**
   * Postergar en el popup: al cliente le llegan correo y WhatsApp con el motivo y la fecha en que se retoma (salvo que
   * haya bloqueado ese canal), con el enlace a la vista pública de su tarea. Su consultor/a es el responsable de la tarea.
   * @param array<string,mixed> $task
   */
  public function taskPostponed(int $id, array $task, string $reason, string $resumeLabel, string $author, string $employee = ''): void
  {
    $text = trim($reason);
    if ($text === '') {
      return;
    }
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $key = 'llamadas:tarea:' . $id . ':postergada:' . substr(hash('sha256', $text . '|' . $resumeLabel), 0, 16);
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $staff = $this->repository->employee($employee) ?? [];
      $this->send($task['correo_solicitante'] ?? '', 'Tu tarea #' . $id . ' quedó en pausa', 'Tarea en pausa',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author !== '' ? $author : ($task['nombre_empleado'] ?? '')) . '</strong> dejó en pausa tu tarea <strong>#' . $id . '</strong>'
        . ($resumeLabel !== '' ? ' hasta el <strong>' . $e($resumeLabel) . '</strong>' : '') . '.</p>'
        . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>'
        . '<p><strong>Su consultor/a asignado es</strong> ' . $e(self::contactLine($task)) . '.</p><p>Desde el botón puedes ver tu tarea y escribirnos.</p>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), $key, trim((string) ($staff['correo'] ?? '')) ?: (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_POSTPONED,
        [$task['solicitante'] ?? '', (string) $id, $resumeLabel !== '' ? $resumeLabel : 'nueva fecha', $text, self::contactLine($task)], PublicTaskAccess::reference($id), $key . ':whatsapp');
    }
  }

  /**
   * Cerrar en el popup: al cliente le llegan correo y WhatsApp con la observación del cierre, salvo los cierres de
   * SILENT_CLOSURES (duplicada o trasladada) o el canal que haya bloqueado. Ya no puede escribir en su enlace: solo verlo.
   * @param array<string,mixed> $task
   */
  public function taskClosed(int $id, array $task, string $status, string $reason, string $author, string $employee = ''): void
  {
    $text = trim($reason);
    if ($text === '' || in_array($status, self::SILENT_CLOSURES, true)) {
      return;
    }
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $key = 'llamadas:tarea:' . $id . ':cerrada:' . substr(hash('sha256', $status . '|' . $text), 0, 16);
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $staff = $this->repository->employee($employee) ?? [];
      $this->send($task['correo_solicitante'] ?? '', 'Cerramos tu tarea #' . $id, 'Tarea cerrada',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author !== '' ? $author : ($task['nombre_empleado'] ?? '')) . '</strong> cerró tu tarea <strong>#' . $id . '</strong>:</p>'
        . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>'
        . '<p><strong>Su consultor/a asignado es</strong> ' . $e(self::contactLine($task)) . '.</p><p>Gracias por confiar en nosotros. Desde el botón puedes ver el detalle de tu tarea.</p>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver tarea'), $key, trim((string) ($staff['correo'] ?? '')) ?: (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_CLOSED,
        [$task['solicitante'] ?? '', (string) $id, $text, self::contactLine($task)], PublicTaskAccess::reference($id), $key . ':whatsapp');
    }
  }

  /**
   * Activar en el popup (tarea postergada o cerrada que vuelve a gestión): al cliente le llegan correo y WhatsApp con el
   * motivo y el contacto de su consultor/a (salvo el canal que haya bloqueado); vuelve a poder escribir en su enlace.
   * @param array<string,mixed> $task
   */
  public function taskReopened(int $id, array $task, string $reason, string $author, string $employee = ''): void
  {
    $text = trim($reason);
    if ($text === '') {
      return;
    }
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $key = 'llamadas:tarea:' . $id . ':reabierta:' . substr(hash('sha256', $text . '|' . date('YmdHi')), 0, 16);
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $staff = $this->repository->employee($employee) ?? [];
      $this->send($task['correo_solicitante'] ?? '', 'Retomamos tu tarea #' . $id, 'Tarea retomada',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author !== '' ? $author : ($task['nombre_empleado'] ?? '')) . '</strong> retomó tu tarea <strong>#' . $id . '</strong>:</p>'
        . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>'
        . '<p><strong>Su consultor/a asignado es</strong> ' . $e(self::contactLine($task)) . '.</p><p>Desde el botón puedes ver tu tarea y escribirnos.</p>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), $key, trim((string) ($staff['correo'] ?? '')) ?: (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_REOPENED,
        [$task['solicitante'] ?? '', (string) $id, $text, self::contactLine($task)], PublicTaskAccess::reference($id), $key . ':whatsapp');
    }
  }

  /**
   * Postergar, cerrar o reabrir una tarea: aviso a funcionarios por correo y WhatsApp. El responsable lo recibe cuando lo
   * hizo otra persona (como un coordinador) con su enlace individual; los marcados en el evento de Notificaciones internas
   * (EVENT_POSTPONED, EVENT_CLOSED, EVENT_REOPENED), siempre. Quien lo hizo no recibe aviso de lo suyo.
   * @param array<string,mixed> $task La tarea ya con su nuevo estado.
   * @param string $movement Lo que pasó, en una línea ("Postergada hasta el …", "Cerrada como Ganado", "Reabierta en Contactado").
   */
  public function taskMoved(int $id, array $task, string $event, string $movement, string $detail, string $author, string $employee): void
  {
    $text = trim($detail) !== '' ? trim($detail) : 'Sin detalle';
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $employee = trim($employee);
    $author = trim($author) !== '' ? trim($author) : 'Un funcionario';
    $key = 'llamadas:tarea:' . $id . ':' . $event . ':' . substr(hash('sha256', $employee . '|' . $movement . '|' . $text . '|' . date('YmdHi')), 0, 16);
    $people = array_values(array_filter($this->staff($task, $event), static fn(array $person): bool => $person['id'] !== '' && $person['id'] !== $employee));
    $client = trim((string) ($task['solicitante'] ?? '')) ?: 'Sin nombre';
    $body = '<p><strong>' . $e($author) . '</strong> actualizó la tarea comercial <strong>#' . $id . '</strong> del cliente <strong>' . $e($client) . '</strong>'
      . (trim((string) ($task['nombre_empleado'] ?? '')) !== '' ? ', a cargo de <strong>' . $e($task['nombre_empleado']) . '</strong>' : '') . '.</p>'
      . '<p><strong>Movimiento:</strong> ' . $e($movement) . '</p>'
      . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>';
    $subject = $movement . ' · tarea #' . $id . ' · ' . $client;
    $assigned = '';
    foreach ($people as $person) {
      if (!$person['copia']) {
        $assigned = trim($person['correo']);
        $this->send($assigned, $subject, 'Movimiento en una tarea', $body, $this->buttons(StaffTaskLink::url($id, $person['id']), 'Ver tarea'), $key);
      }
    }
    $copies = array_values(array_diff(array_unique($this->copies($people)), [$assigned]));
    if ($copies !== []) {
      $this->send($copies, $subject, 'Movimiento en una tarea', $body, $this->buttons(self::panelUrl($id), 'Ver tarea'), $key . ':copias');
    }
    foreach ($people as $person) {
      $this->whatsappTo($person['celular'], '57', self::TEMPLATE_STAFF_MOVED,
        [$person['nombre'], $author, (string) $id, $client, $movement, $text], StaffTaskLink::reference($id, $person['id']), $key . ':whatsapp:' . $person['id']);
    }
  }

  /**
   * Seguimiento de la tarea: al cliente siempre le llega (sin casilla en el popup, salvo que haya bloqueado el correo) por
   * correo con el enlace a la vista pública de su tarea, nunca a la página de tickets anterior.
   * @param array<string,mixed> $task
   */
  public function clientUpdate(int $id, array $task, string $kind, string $message, string $author, string $employee = ''): void
  {
    $text = trim($message);
    if ($text === '' || $this->blocked($this->clientPreferences($task), 'email')) {
      return;
    }
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $staff = $this->repository->employee($employee) ?? [];
    $replyTo = trim((string) ($staff['correo'] ?? '')) ?: (string) ($task['correo_empleado'] ?? '');
    $postponed = $kind === 'postergada';
    $this->send($task['correo_solicitante'] ?? '', $postponed ? 'Tu tarea #' . $id . ' fue postergada' : 'Seguimiento de tu tarea #' . $id,
      $postponed ? 'Tarea postergada' : 'Seguimiento de tu tarea',
      '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, <strong>' . $e($author !== '' ? $author : ($task['nombre_empleado'] ?? '')) . '</strong> '
      . ($postponed ? 'postergó tu tarea <strong>#' . $id . '</strong>:' : 'registró un seguimiento en tu tarea <strong>#' . $id . '</strong>:') . '</p>'
      . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>'
      . '<p>Desde el botón puedes ver tu tarea y escribirnos.</p>',
      $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), 'llamadas:tarea:' . $id . ':' . $kind . ':' . substr(hash('sha256', $text), 0, 16), $replyTo);
  }

  /**
   * Seguimiento registrado en una tarea: al responsable (si lo registró otra persona, como un coordinador) con su enlace
   * individual, y a las copias de Notificaciones internas. Quien lo registró no recibe aviso de lo suyo.
   * @param array<string,mixed> $task
   */
  public function followUpRegistered(int $id, array $task, string $message, string $author, string $employee): void
  {
    $text = trim($message);
    if ($text === '') {
      return;
    }
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $employee = trim($employee);
    $author = trim($author) !== '' ? trim($author) : 'Un funcionario';
    $key = 'llamadas:tarea:' . $id . ':seguimiento:' . substr(hash('sha256', $employee . '|' . $text), 0, 16);
    $people = array_values(array_filter($this->staff($task, self::EVENT_FOLLOW_UP), static fn(array $person): bool => $person['id'] !== '' && $person['id'] !== $employee));
    $client = trim((string) ($task['solicitante'] ?? '')) ?: 'Sin nombre';
    $body = '<p><strong>' . $e($author) . '</strong> registró un seguimiento en la tarea comercial <strong>#' . $id . '</strong>'
      . ' del cliente <strong>' . $e($client) . '</strong>' . (trim((string) ($task['nombre_empleado'] ?? '')) !== '' ? ', a cargo de <strong>' . $e($task['nombre_empleado']) . '</strong>' : '') . ':</p>'
      . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #1e3c76;">' . nl2br($e($text)) . '</blockquote>';
    $subject = 'Seguimiento en la tarea #' . $id . ' · ' . $client;
    $assigned = '';
    foreach ($people as $person) {
      if (!$person['copia']) {
        $assigned = trim($person['correo']);
        $this->send($assigned, $subject, 'Seguimiento registrado', $body, $this->buttons(StaffTaskLink::url($id, $person['id']), 'Ver tarea'), $key);
      }
    }
    $copies = array_values(array_diff(array_unique($this->copies($people)), [$assigned]));
    if ($copies !== []) {
      $this->send($copies, $subject, 'Seguimiento registrado', $body, $this->buttons(self::panelUrl($id), 'Ver tarea'), $key . ':copias');
    }
    foreach ($people as $person) {
      $this->whatsappTo($person['celular'], '57', self::TEMPLATE_FOLLOW_UP,
        [$person['nombre'], $author, (string) $id, $client, $text], StaffTaskLink::reference($id, $person['id']), $key . ':whatsapp:' . $person['id']);
    }
  }

  /**
   * Llegó la fecha para retomar una tarea postergada y volvió sola a Nuevo: correo al responsable con su enlace individual.
   * @param array<string,mixed> $task La tarea ya reactivada.
   */
  public function taskResumed(int $id, array $task, string $date): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $this->send($task['correo_empleado'] ?? '', 'Retoma la tarea #' . $id . ' · ' . (trim((string) ($task['solicitante'] ?? '')) ?: 'cliente'), 'Tarea para retomar',
      '<p>Estimado/a <strong>' . $e($task['nombre_empleado'] ?? '') . '</strong>, llegó la fecha para retomar (' . $e($date) . ') la tarea comercial <strong>#' . $id . '</strong>'
      . (trim((string) ($task['tema_ayuda'] ?? '')) !== '' ? ' de <strong>' . $e($task['tema_ayuda']) . '</strong>' : '') . ' del cliente <strong>' . $e($task['solicitante'] ?? '') . '</strong>.</p>'
      . '<p>La tarea salió de Postergados y volvió a <strong>Nuevo</strong> para que la gestiones.</p>',
      $this->buttons(self::resumedTaskUrl($id, $task), 'Ver tarea'), 'llamadas:tarea:' . $id . ':retomar:' . $date);
  }

  /**
   * Botón del correo de tarea retomada. Mientras las tareas no estén habilitadas en el panel, TAREAS_WORDPRESS_URL (en el
   * .env, la página de la tarea en WordPress, terminada en "=") lo lleva allá con el número de la tarea; sin esa variable,
   * al enlace individual firmado del panel. Para pasar al panel basta con borrar la variable del .env.
   * @param array<string,mixed> $task
   */
  public static function resumedTaskUrl(int $id, array $task): string
  {
    $wordpress = trim((string) getenv('TAREAS_WORDPRESS_URL'));
    if ($wordpress !== '') {
      return $wordpress . rawurlencode(trim((string) ($task['id_ticket'] ?? '')) ?: (string) $id);
    }
    return StaffTaskLink::url($id, (string) ($task['id_empleado'] ?? ''));
  }

  /**
   * Nombre, correo y celular de un funcionario en una sola variable de la plantilla (como en el panel inmobiliario): la
   * plantilla no necesita una variable por dato. Lo que falte no se escribe.
   * @param array<string,mixed> $task
   */
  public static function contactLine(array $task): string
  {
    $parts = [trim((string) ($task['nombre_empleado'] ?? '')) ?: 'Equipo comercial'];
    if (trim((string) ($task['correo_empleado'] ?? '')) !== '') {
      $parts[] = 'Correo: ' . trim((string) $task['correo_empleado']);
    }
    if (trim((string) ($task['celular_empleado'] ?? '')) !== '') {
      $parts[] = 'Celular: ' . trim((string) $task['celular_empleado']);
    }
    return implode(' · ', $parts);
  }

  /** Abre el panel con la tarea en su ventana (sin iniciar sesión): para correos con copias y enlaces internos. */
  public static function panelUrl(int $id): string
  {
    return StaffTaskLink::openUrl($id);
  }

  /**
   * La tarea pasó a otro funcionario (Trasladar en el popup de la tarea). Al nuevo responsable le llegan correo y WhatsApp con su
   * enlace individual; el WhatsApp usa la plantilla de traslado que comparte con el panel inmobiliario. Al cliente, el
   * contacto y la ficha de su nuevo consultor/a (clientReassigned).
   * @param array<string,mixed> $task La tarea con el nuevo responsable.
   */
  public function taskReassigned(int $id, array $task, string $previous, string $reason, string $by, bool $email = true, bool $whatsapp = true): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $employee = trim((string) ($task['id_empleado'] ?? ''));
    $key = 'llamadas:tarea:' . $id . ':traslado:' . $employee . ':' . date('YmdHi');
    if ($email) {
      $this->send($task['correo_empleado'] ?? '', 'Tarea #' . $id . ' trasladada a tu gestión', 'Tarea comercial trasladada',
        '<p>Estimado/a <strong>' . $e($task['nombre_empleado'] ?? '') . '</strong>, <strong>' . $e($by) . '</strong> te trasladó la tarea comercial <strong>#' . $id . '</strong>'
        . (trim((string) ($task['tema_ayuda'] ?? '')) !== '' ? ' de <strong>' . $e($task['tema_ayuda']) . '</strong>' : '') . (trim((string) ($task['asunto'] ?? '')) !== '' ? ' sobre <strong>' . $e($task['asunto']) . '</strong>' : '') . '.</p>'
        . '<p><strong>Cliente:</strong> ' . $e($task['solicitante'] ?? '') . '<br><strong>Responsable anterior:</strong> ' . $e($previous !== '' ? $previous : 'Sin asignar') . '</p>'
        . (trim($reason) !== '' ? '<p><strong>Motivo:</strong> ' . $e($reason) . '</p>' : ''),
        $this->buttons(StaffTaskLink::url($id, $employee), 'Ver tarea'), $key);
    }
    if ($whatsapp) {
      $this->whatsappTo((string) ($task['celular_empleado'] ?? ''), '57', self::TEMPLATE_TRANSFERRED,
        [$task['nombre_empleado'] ?? '', 'Tarea #' . $id, trim((string) ($task['tema_ayuda'] ?? '')) ?: (trim((string) ($task['asunto'] ?? '')) ?: 'Tarea comercial'), $previous !== '' ? $previous : 'Sin asignar'],
        SharedButtonUrl::suffix(StaffTaskLink::url($id, $employee)), $key . ':whatsapp');
    }
    $this->clientReassigned($id, $task, $key . ':cliente');
  }

  /**
   * Al cliente de una tarea trasladada: correo y WhatsApp (salvo el canal que haya bloqueado) con el contacto del nuevo
   * consultor/a y el enlace a la vista pública de su tarea; el WhatsApp lleva de encabezado la ficha del nuevo consultor/a.
   * El motivo del traslado es interno y no se le cuenta.
   * @param array<string,mixed> $task La tarea con el nuevo responsable.
   */
  private function clientReassigned(int $id, array $task, string $key): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $topic = trim((string) ($task['tema_ayuda'] ?? '')) ?: (trim((string) ($task['asunto'] ?? '')) ?: 'Tarea comercial');
    $client = $this->clientPreferences($task);
    if (!$this->blocked($client, 'email')) {
      $this->send($task['correo_solicitante'] ?? '', 'Tu tarea #' . $id . ' tiene un nuevo consultor/a', 'Nuevo consultor/a asignado',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, tu tarea <strong>#' . $id . '</strong> de <strong>' . $e($topic) . '</strong> pasó a un nuevo consultor/a, que continuará con tu atención.</p>'
        . '<p><strong>Su consultor/a asignado es</strong> ' . $e(self::contactLine($task)) . '.</p><p>Desde el botón puedes ver tu tarea y escribirnos.</p>',
        $this->buttons(PublicTaskAccess::url($id), 'Ver y responder'), $key, (string) ($task['correo_empleado'] ?? ''));
    }
    if (!$this->blocked($client, 'whatsapp')) {
      $this->whatsappTo(self::clientPhone($task), (string) ($task['indicativo'] ?? ''), self::TEMPLATE_CLIENT_TRANSFERRED,
        [$task['solicitante'] ?? '', (string) $id, $topic, self::contactLine($task)], PublicTaskAccess::reference($id),
        $key . ':whatsapp', $this->cardImage((string) ($task['id_empleado'] ?? '')));
    }
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
      ($this->whatsapp)($phone, $template, $components, ['source_module' => 'llamadas-whatsapp', 'dedupe_key' => $dedupe, 'priority' => 100, 'template_language' => self::TEMPLATE_LANGUAGE]);
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
