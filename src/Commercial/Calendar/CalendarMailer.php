<?php

declare(strict_types=1);

namespace SCM\Commercial\Calendar;

use SCM\Support\EmailTemplate;
use SCM\Support\SharedNotificationsBridge;

/**
 * Correos y recordatorios del calendario, siempre por shared-notifications. Lógica traída de
 * calendario/backend/helpers/email.php: conserva proyecto y `dedupe_key` para poder cancelar lo que la otra app dejó programado.
 * Cada aviso se puede apagar en Configuración › Calendario comercial.
 */
class CalendarMailer
{
  public const SETTING = 'commercial_calendar_notifications';
  public const DEFAULTS = ['funcionario_email' => true, 'solicitante_email' => true, 'propietario_email' => true, 'whatsapp_citas' => true];
  private const PROJECT = 'calendario-actividades';
  private const ORANGE_LINK = 'color:#1E3C76;font-weight:bold;';

  private CalendarStore $store;
  private SharedNotificationsBridge $bridge;
  /** @var array<string,bool> */
  private array $settings;
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,bool> $settings @param array<string,mixed> $config */
  public function __construct(CalendarStore $store, array $settings, array $config, ?SharedNotificationsBridge $bridge = null)
  {
    $this->store = $store;
    $this->settings = $settings + self::DEFAULTS;
    $this->config = $config;
    $this->bridge = $bridge ?? new SharedNotificationsBridge($store->database());
  }

  /** @param array<string,mixed> $event */
  public function newEvent(array $event, int $eventId): void
  {
    if (!$this->settings['funcionario_email']) {
      return;
    }
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail($this->withExtras((string) ($employee['correo'] ?? ''), $event), 'Nuevo evento #' . $eventId . ' de ' . $this->categoryName($event) . ' agregado', 'Nuevo evento agendado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, se te agendó el siguiente evento:') . $this->eventDetails($event),
      'nuevo-evento-email', 'calendario:nuevo-evento:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado'], 'creado_por' => $event['creado_por'] ?? null]);
  }

  /** @param array<int,array<string,mixed>> $events Eventos de un mismo funcionario. */
  public function bulkEvents(string $employeeId, array $events): void
  {
    $employee = $this->store->employee($employeeId);
    if ($employee === null || $events === [] || !$this->settings['funcionario_email']) {
      return;
    }
    $list = '<ul style="padding-left:20px;color:#404041;">';
    foreach ($events as $event) {
      $list .= '<li style="margin-bottom:5px;"><strong>' . self::e(self::humanDate((string) $event['fecha_inicio'])) . '</strong> a ' . self::e(self::humanTime((string) $event['fecha_fin'])) . '</li>';
    }
    $list .= '</ul>';
    $ids = array_values(array_filter(array_column($events, 'id')));
    $this->sendEmail($this->withExtras((string) ($employee['correo'] ?? ''), $events[0]), 'Se han creado ' . count($events) . ' eventos de ' . $this->categoryName($events[0]), 'Eventos agendados',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, se agendaron ' . count($events) . ' fechas de <strong>' . self::e((string) $events[0]['titulo']) . '</strong>:') . $list,
      'eventos-masivos-email', 'calendario:eventos-masivos:' . $employeeId . ':' . md5(implode(',', $ids)), ['id_empleado' => $employeeId, 'id_eventos' => $ids, 'cantidad' => count($events)]);
  }

  /** @param array<string,mixed> $event */
  public function eventCompleted(array $event, int $eventId): void
  {
    if (!$this->settings['funcionario_email']) {
      return;
    }
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail([(string) ($employee['correo'] ?? '')], 'Evento #' . $eventId . ' de ' . $this->categoryName($event) . ' finalizado', 'Evento finalizado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, el evento <strong>#' . $eventId . '</strong> quedó marcado como realizado.'),
      'evento-realizado-email', 'calendario:evento-realizado:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado']]);
  }

  /** @param array<string,mixed> $event */
  public function eventMoved(array $event, int $eventId): void
  {
    if (!$this->settings['funcionario_email']) {
      return;
    }
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail([(string) ($employee['correo'] ?? '')], 'Evento #' . $eventId . ' de ' . $this->categoryName($event) . ' trasladado', 'Evento trasladado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, el evento quedó con esta nueva fecha:') . $this->eventDetails($event),
      'evento-trasladado-email', 'calendario:evento-trasladado:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado']]);
  }

  /** @param array<string,mixed> $event */
  public function requesterAppointment(array $event, bool $moved): void
  {
    if (!$this->settings['solicitante_email']) {
      return;
    }
    $pk = (int) ($event['id_ticket'] ?? 0);
    $ticket = $pk > 0 ? $this->store->ticket($pk) : null;
    if ($ticket === null || trim((string) ($ticket['correo_solicitante'] ?? '')) === '') {
      return;
    }
    $employee = $this->store->employee((string) $event['id_empleado']) ?? [];
    $intro = 'Apreciado/a <strong>' . self::e((string) ($ticket['solicitante'] ?? '')) . '</strong>, nuestro funcionario <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong> '
      . ($moved ? 'reprogramó su cita.' : 'le ha agendado una cita.');
    $link = trim((string) ($this->config['ticket_url'] ?? ''));
    $body = $this->paragraph($intro) . $this->eventDetails($event);
    if ($link !== '') {
      $body .= $this->paragraph('<a style="' . self::ORANGE_LINK . '" href="' . self::e($link . (strpos($link, '?') === false ? '?' : '&') . 'id_ticket=' . $pk) . '">Ver el detalle de su solicitud</a>');
    }
    $this->sendEmail([(string) $ticket['correo_solicitante']], ($moved ? 'Evento reprogramado de ' : 'Tienes evento programado de ') . $this->categoryName($event), $moved ? 'Cita reprogramada' : 'Cita agendada', $body,
      $moved ? 'cita-trasladada-solicitante-email' : 'cita-solicitante-email',
      'calendario:' . ($moved ? 'cita-trasladada-solicitante:' : 'cita-solicitante:') . $pk . ':' . (string) $event['fecha_inicio'], ['id_ticket' => $pk, 'id_empleado' => $event['id_empleado']]);
  }

  /**
   * @param array<string,mixed> $event
   * @param array<string,mixed> $report
   * @param array<string,string>|null $property
   */
  public function commercialReport(array $event, array $report, ?array $property): void
  {
    $eventId = (string) $event['id'];
    $detail = $property !== null ? trim('#' . ($property['codigo'] !== '' ? $property['codigo'] : $property['id']) . ' - ' . $property['titulo'] . ' - ' . $property['direccion']) : 'Sin inmueble asociado';
    $employee = $this->store->employee((string) $event['id_empleado']) ?? [];
    $lines = $this->row('Tipo', (string) $report['tipo_reporte']) . $this->row('Inmueble', $detail) . '<p style="margin:10px 0;"><strong>Resultado:</strong><br>' . nl2br(self::e((string) $report['observacion'])) . '</p>';
    if ($this->settings['funcionario_email'] && trim((string) ($employee['correo'] ?? '')) !== '') {
      $this->sendEmail([(string) $employee['correo']], 'Reporte comercial #' . (string) ($report['_ID'] ?? '') . ' registrado desde el evento #' . $eventId, 'Reporte comercial registrado',
        $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, se registró tu reporte del evento <strong>#' . self::e($eventId) . '</strong>.') . $lines . $this->row('Valor', '$' . number_format((int) $report['valor'], 0, ',', '.')),
        'reportes-comerciales-email', 'reporte-comercial:evento:' . $eventId . ':funcionario', []);
    }
    $owner = trim((string) ($property['correo_propietario'] ?? ''));
    if ($this->settings['propietario_email'] && filter_var($owner, FILTER_VALIDATE_EMAIL)) {
      $name = trim((string) ($property['propietario'] ?? ''));
      $this->sendEmail([$owner], 'Reporte de actividad de su inmueble', 'Actividad comercial de su inmueble',
        $this->paragraph('Apreciado/a <strong>' . self::e($name !== '' ? $name : 'Propietario/a') . '</strong>, se registró una actividad comercial relacionada con su inmueble.') . $lines,
        'reportes-comerciales-email', 'reporte-comercial:evento:' . $eventId . ':propietario', []);
    }
  }

  /** Programa el aviso del recordatorio en la cola; devuelve el id o null si no se pudo. @param array<string,mixed> $item */
  public function scheduleReminder(array $item): ?int
  {
    $at = trim((string) ($item['recordatorio_at'] ?? ''));
    $employeeId = trim((string) ($item['id_empleado'] ?? ''));
    $id = trim((string) ($item['id'] ?? ''));
    $queue = $this->bridge->queue();
    $employee = $this->store->employee($employeeId);
    if ($at === '' || $id === '' || $queue === null || $employee === null) {
      return null;
    }
    $channel = CalendarStore::reminderChannel((string) ($item['recordatorio_canal'] ?? 'email'));
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $minutes = max(0, min(1440, (int) ($meta['aviso_minutos_antes'] ?? 0)));
    $zone = new \DateTimeZone('America/Bogota');
    $scheduled = (new \DateTimeImmutable($at, $zone))->modify('-' . $minutes . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('c');
    $title = trim((string) ($item['titulo'] ?? 'Recordatorio'));
    $when = self::humanDate($at);
    $location = trim((string) ($item['ubicacion'] ?? ''));
    $text = 'Recordatorio: ' . $title . "\nFecha: " . $when . ($location !== '' ? "\nUbicación: " . $location : '');
    $dedupe = self::reminderKey($item, $channel);
    $options = [
      'project_code' => self::PROJECT, 'source_module' => 'recordatorio-calendario-' . $channel, 'created_by' => CalendarStore::ORIGIN, 'scheduled_at' => $scheduled, 'dedupe_key' => $dedupe, 'priority' => 150,
      'meta' => ['item_id' => $id, 'tipo_item' => 'recordatorio', 'id_empleado' => $employeeId, 'origen_app' => $item['origen_app'] ?? CalendarStore::ORIGIN, 'source_table' => CalendarStore::REMINDERS, 'aviso_minutos_antes' => $minutes],
    ];
    try {
      $queue->cancelByDedupeKey($dedupe, 'Cancelado por un recordatorio de calendario mas reciente.');
      if ($channel === 'email') {
        if (trim((string) ($employee['correo'] ?? '')) === '') {
          return null;
        }
        $ids = $queue->enqueueEmail([(string) $employee['correo']], 'Recordatorio de calendario: ' . $title, EmailTemplate::render('Recordatorio', $this->paragraph(nl2br(self::e($text)))), $options + ['provider' => 'email_smtp']);
        return isset($ids[0]) ? (int) $ids[0] : null;
      }
      $phone = trim((string) ($employee['celular'] ?? ''));
      if ($phone === '') {
        return null;
      }
      if ($channel === 'sms') {
        return $queue->enqueueSms($phone, $text, $options + ['provider' => 'sms_onurix']);
      }
      $template = trim((string) ($this->config['reminder_template'] ?? '')) ?: 'recordatorio_calendario';
      $language = trim((string) ($this->config['reminder_template_lang'] ?? '')) ?: 'es_CO';
      return $queue->enqueueWhatsAppOfficialTemplate($phone, $template, [[
        'type' => 'body',
        'parameters' => [
          ['type' => 'text', 'text' => (string) ($employee['nombre'] ?? 'Funcionario')],
          ['type' => 'text', 'text' => $title . ' • recordatorio' . ($location !== '' ? ' • ' . $location : '')],
          ['type' => 'text', 'text' => $when],
        ],
      ]], $options + ['provider' => 'whatsapp_official', 'template_language' => $language, 'payload' => ['text_fallback' => $text]]);
    } catch (\Throwable $exception) {
      error_log('[commercial-calendar] recordatorio ' . $id . ': ' . $exception->getMessage());
      return null;
    }
  }

  /** @param array<string,mixed> $item */
  public function cancelReminder(array $item, string $reason): void
  {
    $queue = $this->bridge->queue();
    if ($queue === null) {
      return;
    }
    try {
      $queue->cancelByDedupeKey(self::reminderKey($item, CalendarStore::reminderChannel((string) ($item['recordatorio_canal'] ?? ($item['canal'] ?? 'email')))), $reason);
    } catch (\Throwable $exception) {
      error_log('[commercial-calendar] cancelar recordatorio: ' . $exception->getMessage());
    }
  }

  /** @param array<string,mixed> $item */
  private static function reminderKey(array $item, string $channel): string
  {
    return 'calendario:' . CalendarStore::REMINDERS . ':' . trim((string) ($item['id'] ?? '')) . ':recordatorio:' . $channel;
  }

  /**
   * @param array<int,string> $destinations
   * @param array<string,mixed> $meta
   */
  protected function sendEmail(array $destinations, string $subject, string $title, string $body, string $module, string $dedupe, array $meta): void
  {
    $destinations = array_values(array_unique(array_filter(array_map('trim', $destinations), static fn(string $mail): bool => (bool) filter_var($mail, FILTER_VALIDATE_EMAIL))));
    $queue = $this->bridge->queue();
    if ($destinations === [] || $queue === null) {
      return;
    }
    try {
      $queue->cancelByDedupeKey($dedupe, 'Cancelado por una notificacion mas reciente del calendario.');
      $queue->enqueueEmail($destinations, $subject, EmailTemplate::render($title, $body), [
        'project_code' => self::PROJECT, 'source_module' => $module, 'created_by' => CalendarStore::ORIGIN, 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'meta' => $meta,
      ]);
    } catch (\Throwable $exception) {
      error_log('[commercial-calendar] correo ' . $module . ': ' . $exception->getMessage());
    }
  }

  /** @param array<string,mixed> $event @return array<int,string> */
  private function withExtras(string $main, array $event): array
  {
    $emails = [$main];
    foreach (is_array($event['notificar_funcionarios'] ?? null) ? $event['notificar_funcionarios'] : [] as $id) {
      $emails[] = (string) ($this->store->employee((string) $id)['correo'] ?? '');
    }
    return $emails;
  }

  /** @param array<string,mixed> $event */
  private function categoryName(array $event): string
  {
    return (string) ($this->store->category((int) ($event['id_categoria'] ?? 0))['nombre'] ?? 'calendario');
  }

  /** @param array<string,mixed> $event */
  private function eventDetails(array $event): string
  {
    $location = trim((string) ($event['ubicacion'] ?? ''));
    return $this->row('Evento', (string) $event['titulo'])
      . $this->row('Inicio', self::humanDate((string) $event['fecha_inicio']))
      . $this->row('Fin', self::humanDate((string) $event['fecha_fin']))
      . ($location !== '' ? $this->row('Lugar', $location) : '');
  }

  private function row(string $label, string $value): string
  {
    return '<p style="margin:10px 0;"><strong>' . self::e($label) . ':</strong> ' . self::e($value) . '</p>';
  }

  private function paragraph(string $html): string
  {
    return '<p style="margin:10px 0;">' . $html . '</p>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  private static function humanDate(string $date): string
  {
    $ts = strtotime($date);
    return $ts > 0 ? str_replace(['am', 'pm'], ['a. m.', 'p. m.'], date('d/m/Y g:i a', $ts)) : $date;
  }

  private static function humanTime(string $date): string
  {
    $ts = strtotime($date);
    return $ts > 0 ? str_replace(['am', 'pm'], ['a. m.', 'p. m.'], date('g:i a', $ts)) : $date;
  }
}
