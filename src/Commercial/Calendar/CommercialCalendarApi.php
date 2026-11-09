<?php

declare(strict_types=1);

namespace SCM\Commercial\Calendar;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialCalendarService;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\ColombiaBusinessCalendar;

/**
 * API del calendario comercial. Atiende las mismas acciones y con la misma respuesta ({success,message,data}) que
 * calendario/backend/routes/api.php para que el motor del calendario no cambie, pero cada acción exige sesión y permisos:
 * - Leer: lo propio; "Calendario del equipo" da el equipo. De otro funcionario solo se entrega ocupado/libre para agendar.
 * - Modificar: evento propio, creado por la persona o con "Calendario del equipo".
 * - Las tareas nunca cambian su estado administrativo.
 */
final class CommercialCalendarApi
{
  private const MAX_BULK_EVENTS = 400;

  private CalendarStore $store;
  private CommercialAccessPolicy $policy;
  private CommercialCalendarService $calendar;
  private CommercialTicketsRepository $tickets;
  private CalendarMailer $mailer;
  private CalendarCommercialReport $reports;
  /** @var array<int,string>|null */
  private ?array $allowedIds = null;
  private ?string $me = null;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, array $config, ?CalendarMailer $mailer = null)
  {
    $this->store = new CalendarStore($db);
    $this->policy = $policy;
    $this->calendar = new CommercialCalendarService($db, $settings, $policy, $config);
    $this->tickets = new CommercialTicketsRepository($db);
    $this->mailer = $mailer ?? new CalendarMailer($this->store, $this->calendar->notificationSettings(), $config);
    $this->reports = new CalendarCommercialReport($db);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{success:bool,message:string,data:mixed,status?:int}
   */
  public function handle(string $action, array $input): array
  {
    if (!$this->policy->canView('calendario')) {
      return self::fail('No tienes permiso para usar el calendario comercial.', [], 403);
    }
    $handlers = [
      'listar_categorias' => 'listCategories',
      'listar_funcionarios' => 'listEmployees',
      'listar_items_calendario' => 'listItems',
      'filtrar_eventos_admin' => 'filterEventsAdmin',
      'filtrar_eventos' => 'filterEvents',
      'listar_pendientes_vencidos' => 'listOverdue',
      'listar_por_cerrar' => 'listToClose',
      'obtener_tickets_funcionario' => 'employeeTickets',
      'crear_evento' => 'createEvent',
      'crear_eventos' => 'createEvents',
      'crear_item_calendario' => 'createItem',
      'crear_recordatorio' => 'createReminder',
      'crear_recordatorios' => 'createReminders',
      'actualizar_evento' => 'updateEvent',
      'trasladar_evento' => 'moveEvent',
      'cambiar_estado' => 'completeEvent',
      'preparar_reporte_comercial' => 'prepareReport',
      'buscar_inmuebles_reporte' => 'searchReportProperties',
      'actualizar_recordatorio' => 'updateReminder',
      'actualizar_recordatorio_estado' => 'updateReminderStatus',
    ];
    if (!isset($handlers[$action])) {
      return self::fail('Acción no válida.', [], 400);
    }
    try {
      return $this->{$handlers[$action]}($input);
    } catch (\DomainException $exception) {
      return self::fail($exception->getMessage(), [], 403);
    } catch (\InvalidArgumentException $exception) {
      return self::fail($exception->getMessage());
    }
  }

  // ── Consultas ──────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listCategories(array $input): array
  {
    return self::ok('Categorías obtenidas', $this->store->categories());
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listEmployees(array $input): array
  {
    $rows = array_map(static fn(array $row): array => [
      'id_empleado' => (string) ($row['id_empleado'] ?? ''),
      'nombre' => (string) ($row['nombre'] ?? ''),
      'id_cargo' => (string) ($row['id_cargo'] ?? ''),
    ], $this->allowedEmployees());
    return self::ok('Funcionarios', $rows);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listItems(array $input): array
  {
    $this->finalizeExpiredReminders();
    $types = ['evento', 'recordatorio'];
    if (trim((string) ($input['tipo_item'] ?? '')) !== '') {
      $types = [self::itemType((string) $input['tipo_item'])];
    } elseif (is_array($input['tipos_item'] ?? null) && $input['tipos_item'] !== []) {
      $types = array_values(array_unique(array_map(static fn($type): string => self::itemType((string) $type), $input['tipos_item'])));
    }
    [$ids, $masked] = $this->readScope((string) ($input['id_empleado'] ?? ''));
    $filters = $this->readFilters($input, $masked);
    $items = [];
    if (in_array('evento', $types, true)) {
      $items = $this->store->events($ids, $filters)['rows'];
    }
    if (in_array('recordatorio', $types, true)) {
      $items = array_merge($items, $this->store->reminders($ids, $filters));
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['fecha_inicio'] ?? ''), (string) ($b['fecha_inicio'] ?? '')));
    $items = $masked ? array_map([self::class, 'mask'], $items) : $items;
    $limit = isset($input['limite']) ? max(1, min(3000, (int) $input['limite'])) : 3000;
    return self::ok('Items de calendario', array_slice($items, max(0, (int) ($input['offset'] ?? 0)), $limit));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function filterEventsAdmin(array $input): array
  {
    [$ids, $masked] = $this->readScope((string) ($input['id_empleado'] ?? ''));
    $page = max(1, (int) ($input['pagina'] ?? 1));
    $limit = max(1, min(3000, (int) ($input['limite'] ?? 50)));
    $result = $this->store->events($ids, $this->readFilters($input, $masked), $limit, ($page - 1) * $limit);
    return self::ok('Eventos filtrados', ['data' => $masked ? array_map([self::class, 'mask'], $result['rows']) : $result['rows'], 'total' => $result['total']]);
  }

  /** Eventos pendientes de una tarea (tarjeta de agenda) o de un funcionario. @param array<string,mixed> $input @return array<string,mixed> */
  private function filterEvents(array $input): array
  {
    $ticket = (int) ($input['id_ticket'] ?? 0);
    if ($ticket > 0) {
      if (!$this->canSeeTicket($ticket)) {
        return self::ok('Eventos filtrados', []);
      }
      return self::ok('Eventos filtrados', $this->store->events(null, ['id_ticket' => $ticket, 'estado' => 'No'])['rows']);
    }
    [$ids, $masked] = $this->readScope((string) ($input['id_empleado'] ?? ''));
    $rows = $this->store->events($ids, ['estado' => 'No'] + $this->readFilters($input, $masked))['rows'];
    return self::ok('Eventos filtrados', $masked ? array_map([self::class, 'mask'], $rows) : $rows);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listOverdue(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de empleado requerido.');
    }
    [$ids, $masked] = $this->readScope($employee);
    return self::ok('Eventos pendientes vencidos', $ids === [] || $masked ? [] : $this->store->overdueEvents($employee));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listToClose(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de empleado requerido.');
    }
    [$ids, $masked] = $this->readScope($employee);
    return self::ok('Eventos por marcar realizado', $ids === [] || $masked ? [] : $this->store->eventsToClose($employee));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function employeeTickets(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de funcionario requerido');
    }
    if ($employee !== $this->me() && !$this->policy->canSeeAllTasks()) {
      return self::ok('Tareas', []);
    }
    $rows = array_map(static fn(array $row): array => [
      '_ID' => (string) ($row['_ID'] ?? ''),
      'id_ticket' => (string) ($row['id_ticket'] ?? ''),
      'asunto' => (string) ($row['asunto'] ?? ''),
      'tema_ayuda' => (string) ($row['tema_ayuda'] ?? ''),
      'solicitante' => (string) ($row['solicitante'] ?? ''),
      'inmueble' => (string) ($row['inmueble'] ?? ''),
      'direccion' => (string) ($row['direccion'] ?? ''),
      'estado_comercial' => (string) ($row['estado_comercial'] ?? ''),
      'id_empleado' => (string) ($row['id_empleado'] ?? ''),
    ], $this->tickets->calendarOpenTickets(['id_empleado' => $employee]));
    return self::ok('Tareas', $rows);
  }

  // ── Crear ──────────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createEvent(array $input): array
  {
    foreach (['titulo', 'fecha_inicio', 'fecha_fin', 'id_empleado', 'id_categoria'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio.");
      }
    }
    [$start, $end] = self::dateRange((string) $input['fecha_inicio'], (string) $input['fecha_fin']);
    $employee = trim((string) $input['id_empleado']);
    $ticket = (int) ($input['id_ticket'] ?? 0);
    $this->assertCategory((int) $input['id_categoria']);
    $this->assertCanAssign([$employee], $ticket);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $id = $this->store->insertEvent($this->eventData($input, $employee, $start, $end));
    $this->mailer->newEvent(['id_empleado' => $employee, 'titulo' => (string) $input['titulo'], 'fecha_inicio' => $start, 'fecha_fin' => $end, 'ubicacion' => $input['ubicacion'] ?? '', 'id_categoria' => (int) $input['id_categoria'], 'creado_por' => $this->me(), 'notificar_funcionarios' => $input['notificar_funcionarios'] ?? []], $id);
    if ($ticket > 0) {
      $this->linkTicket($ticket, $id, $employee, $input, $start, $end);
    }
    return self::ok('Evento #' . $id . ' creado exitosamente.', ['id' => $id]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createEvents(array $input): array
  {
    $events = is_array($input['eventos'] ?? null) ? $input['eventos'] : null;
    $employees = is_array($input['empleados'] ?? null) ? array_values(array_unique(array_map(static fn($id): string => trim((string) $id), $input['empleados']))) : null;
    if ($events === null || $employees === null || $events === [] || $employees === []) {
      return self::fail('Formato incorrecto: se esperaba un array de eventos y empleados');
    }
    if (count($events) * count($employees) > self::MAX_BULK_EVENTS) {
      return self::fail('Son demasiadas fechas a la vez. El máximo es ' . self::MAX_BULK_EVENTS . ' eventos.');
    }
    $rows = [];
    $tickets = [];
    foreach ($events as $event) {
      foreach (['titulo', 'fecha_inicio', 'fecha_fin', 'id_categoria'] as $field) {
        if (empty($event[$field])) {
          return self::fail("El campo '{$field}' es obligatorio en los eventos.");
        }
      }
      [$start, $end] = self::dateRange((string) $event['fecha_inicio'], (string) $event['fecha_fin']);
      $this->assertCategory((int) $event['id_categoria']);
      $tickets[(int) ($event['id_ticket'] ?? 0)] = true;
      foreach ($employees as $employee) {
        $rows[] = [$event, $employee, $start, $end];
      }
    }
    foreach (array_keys($tickets) as $ticket) {
      $this->assertCanAssign($employees, (int) $ticket);
    }
    foreach ($employees as $employee) {
      $blocked = $this->blockResponse($employee, $input);
      if ($blocked !== null) {
        return $blocked;
      }
    }
    $byEmployee = [];
    foreach ($rows as [$event, $employee, $start, $end]) {
      $id = $this->store->insertEvent($this->eventData($event, $employee, $start, $end));
      $byEmployee[$employee][] = ['id' => $id, 'id_empleado' => $employee, 'titulo' => (string) $event['titulo'], 'fecha_inicio' => $start, 'fecha_fin' => $end, 'id_categoria' => (int) $event['id_categoria']];
    }
    foreach ($byEmployee as $employee => $list) {
      $this->mailer->bulkEvents((string) $employee, $list);
    }
    return self::ok('Se registraron ' . count($rows) . ' eventos exitosamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createItem(array $input): array
  {
    if (self::itemType((string) ($input['tipo_item'] ?? ($input['tipo'] ?? 'evento'))) === 'recordatorio') {
      return $this->createReminder($input);
    }
    foreach (['titulo', 'fecha_inicio', 'id_empleado', 'id_categoria'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio para eventos.");
      }
    }
    $start = self::parseDate((string) $input['fecha_inicio']);
    $end = !empty($input['fecha_fin']) ? self::parseDate((string) $input['fecha_fin']) : null;
    if ($start === null) {
      return self::fail('Fecha inválida.');
    }
    if ($end === null || strtotime($end) <= strtotime($start)) {
      $end = self::plusMinutes($start, (int) ($input['duracion_minutos'] ?? 30));
    }
    $employee = trim((string) $input['id_empleado']);
    $this->assertCategory((int) $input['id_categoria']);
    $this->assertCanAssign([$employee], 0);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $id = $this->store->insertEvent($this->eventData($input, $employee, $start, $end));
    $this->mailer->newEvent(['id_empleado' => $employee, 'titulo' => (string) $input['titulo'], 'fecha_inicio' => $start, 'fecha_fin' => $end, 'ubicacion' => $input['ubicacion'] ?? '', 'id_categoria' => (int) $input['id_categoria']], $id);
    return self::ok('Evento creado.', ['item' => $this->store->event($id)]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createReminder(array $input): array
  {
    foreach (['titulo', 'id_empleado'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio para recordatorios.");
      }
    }
    $at = self::parseDate((string) (($input['recordatorio_at'] ?? '') ?: ($input['fecha_inicio'] ?? '')));
    if ($at === null) {
      return self::fail('La fecha del recordatorio es obligatoria.');
    }
    $employee = trim((string) $input['id_empleado']);
    $this->assertCanAssign([$employee], 0);
    $categoryId = (int) ($input['id_categoria'] ?? 0);
    if ($categoryId > 0) {
      $this->assertCategory($categoryId);
    }
    $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
    $meta['aviso_minutos_antes'] = self::noticeMinutes($input['aviso_minutos_antes'] ?? ($meta['aviso_minutos_antes'] ?? 30));
    $id = $this->store->insertReminder([
      'titulo' => (string) $input['titulo'],
      'mensaje' => (string) (($input['mensaje'] ?? '') ?: ($input['descripcion'] ?? '')),
      'recordatorio_at' => $at,
      'recordatorio_canal' => (string) ($input['recordatorio_canal'] ?? 'email'),
      'id_empleado' => $employee,
      'creado_por' => $this->me(),
      'id_categoria' => $categoryId,
      'meta' => $meta,
    ]);
    $reminder = (array) $this->store->reminder($id);
    $queueId = $this->mailer->scheduleReminder($reminder);
    if ($queueId !== null) {
      $this->store->setReminderQueue($id, $queueId, 'programado');
    }
    $reminder = (array) $this->store->reminder($id);
    $reminder['aviso_programado'] = $queueId !== null;
    return self::ok('Recordatorio creado.', $reminder);
  }

  /** Recordatorio que se repite: uno por fecha, cada uno con su aviso. @param array<string,mixed> $input @return array<string,mixed> */
  private function createReminders(array $input): array
  {
    $dates = is_array($input['fechas'] ?? null) ? array_values($input['fechas']) : [];
    if ($dates === []) {
      return self::fail('Indica las fechas del recordatorio.');
    }
    if (count($dates) > self::MAX_BULK_EVENTS) {
      return self::fail('Son demasiadas fechas a la vez. El máximo es ' . self::MAX_BULK_EVENTS . ' recordatorios.');
    }
    $moments = [];
    foreach ($dates as $date) {
      $at = is_string($date) ? self::parseDate($date) : null;
      if ($at === null) {
        return self::fail('Hay una fecha de recordatorio inválida.');
      }
      $moments[] = $at;
    }
    $allScheduled = true;
    foreach ($moments as $at) {
      $created = $this->createReminder(['recordatorio_at' => $at] + $input);
      if (empty($created['success'])) {
        return $created;
      }
      $allScheduled = $allScheduled && !empty($created['data']['aviso_programado']);
    }
    return self::ok('Se crearon ' . count($moments) . ' recordatorios.', ['creados' => count($moments), 'aviso_programado' => $allScheduled]);
  }

  // ── Modificar ──────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateEvent(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    $event = $this->editableEvent($id);
    $title = trim((string) ($input['titulo'] ?? ''));
    $category = (int) ($input['id_categoria'] ?? 0);
    if ($title === '' || $category <= 0) {
      return self::fail('El título y la categoría son obligatorios.');
    }
    if ($category !== (int) $event['id_categoria']) {
      $this->assertCategory($category);
    }
    $this->store->updateEvent($id, $title, (string) ($input['descripcion'] ?? ''), trim((string) ($input['ubicacion'] ?? '')), $category);
    return self::ok('Evento actualizado correctamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function moveEvent(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    $observation = trim((string) ($input['observacion'] ?? ''));
    if ($id <= 0 || empty($input['fecha_inicio']) || empty($input['fecha_fin']) || $observation === '') {
      return self::fail('Datos incompletos para trasladar el evento.');
    }
    [$start, $end] = self::dateRange((string) $input['fecha_inicio'], (string) $input['fecha_fin']);
    $this->editableEvent($id);
    $this->store->rescheduleEvent($id, $start, $end, $observation);
    $event = (array) $this->store->event($id);
    $this->mailer->eventMoved($event, $id);
    $ticket = (int) ($event['id_ticket'] ?? 0);
    if ($ticket > 0) {
      try {
        $this->store->touchTicket($ticket);
        if (strtolower(trim((string) ($input['es_cita'] ?? 'no'))) === 'si') {
          $this->store->addTicketHistory($ticket, (string) $event['id_empleado'], $id, trim((string) ($input['descripcion'] ?? '')) ?: $observation);
          $this->mailer->requesterAppointment($event, true);
        }
      } catch (\Throwable $exception) {
        error_log('[commercial-calendar] tarea del traslado ' . $id . ': ' . $exception->getMessage());
      }
    }
    return self::ok('Evento trasladado correctamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function completeEvent(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    $observation = trim((string) ($input['observacion'] ?? ''));
    if ($id <= 0 || $observation === '') {
      return self::fail('El evento y la observación son obligatorios.');
    }
    $event = $this->editableEvent($id);
    if ($this->reports->isRequired($id)) {
      if (!is_array($input['reporte'] ?? null)) {
        return self::fail('Debes completar el reporte comercial antes de finalizar el evento.', ['requiere_reporte' => true]);
      }
      try {
        $result = $this->reports->finish($id, $observation, $input['reporte']);
      } catch (\RuntimeException $exception) {
        return self::fail($exception->getMessage());
      }
      if ($result['creado']) {
        $this->mailer->eventCompleted($result['evento'], $id);
        $this->mailer->commercialReport($result['evento'], $result['reporte'], $result['inmueble']);
      }
      return self::ok($result['creado'] ? 'Evento finalizado y reporte comercial guardado.' : 'El evento y su reporte ya estaban registrados.', [
        'id_reporte' => $result['reporte']['_ID'] ?? null,
      ]);
    }
    $this->store->completeEvent($id, $observation);
    $this->mailer->eventCompleted($event, $id);
    return self::ok('Evento marcado como realizado');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function prepareReport(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    if ($id <= 0) {
      return self::fail('ID de evento requerido.');
    }
    $this->editableEvent($id);
    try {
      return self::ok('Preparación del reporte comercial.', $this->reports->prepare($id));
    } catch (\RuntimeException $exception) {
      return self::fail($exception->getMessage());
    }
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function searchReportProperties(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    if ($id <= 0 || trim((string) ($input['termino'] ?? '')) === '') {
      return self::fail('Evento y término de búsqueda requeridos.');
    }
    $this->editableEvent($id);
    try {
      $rows = $this->reports->searchProperties($id, (string) $input['termino']);
    } catch (\RuntimeException $exception) {
      return self::fail($exception->getMessage());
    }
    // El correo del propietario se usa en el servidor al guardar; no hace falta en el navegador.
    return self::ok('Inmuebles encontrados.', array_map(static fn(array $row): array => array_diff_key($row, ['correo_propietario' => true]), $rows));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateReminder(array $input): array
  {
    $this->finalizeExpiredReminders();
    $id = (int) ($input['id_recordatorio'] ?? ($input['id'] ?? 0));
    $current = $this->editableReminder($id);
    if (!in_array($current['estado'], ['pendiente', 'programado'], true)) {
      return self::fail('Solo se pueden editar recordatorios pendientes.');
    }
    $title = trim((string) ($input['titulo'] ?? ''));
    $at = self::parseDate((string) ($input['recordatorio_at'] ?? ''));
    if ($title === '' || $at === null) {
      return self::fail('El título y la fecha del recordatorio son obligatorios.');
    }
    $meta = array_merge(is_array($current['meta'] ?? null) ? $current['meta'] : [], is_array($input['meta'] ?? null) ? $input['meta'] : []);
    $meta['aviso_minutos_antes'] = self::noticeMinutes($input['aviso_minutos_antes'] ?? ($meta['aviso_minutos_antes'] ?? 0));
    $this->store->updateReminder($id, ['titulo' => $title, 'mensaje' => (string) (($input['descripcion'] ?? '') ?: ($input['mensaje'] ?? '')), 'recordatorio_at' => $at, 'meta' => $meta]);
    $reminder = (array) $this->store->reminder($id);
    $queueId = $this->mailer->scheduleReminder($reminder);
    $this->store->setReminderQueue($id, $queueId, $queueId !== null ? 'programado' : 'pendiente');
    $reminder = (array) $this->store->reminder($id);
    return self::ok('Recordatorio actualizado.', ['item' => $reminder, 'aviso_programado' => $queueId !== null]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateReminderStatus(array $input): array
  {
    $this->finalizeExpiredReminders();
    $id = (int) ($input['id_recordatorio'] ?? ($input['id'] ?? 0));
    $status = strtolower(trim((string) ($input['estado'] ?? '')));
    if (!in_array($status, ['enviado', 'cancelado'], true)) {
      return self::fail('Estado de recordatorio no válido.');
    }
    $this->editableReminder($id);
    $this->store->setReminderStatus($id, $status);
    $reminder = (array) $this->store->reminder($id);
    $this->mailer->cancelReminder($reminder, $status === 'enviado' ? 'Cancelado porque el recordatorio fue marcado como enviado manualmente.' : 'Cancelado manualmente desde calendario.');
    return self::ok($status === 'enviado' ? 'Recordatorio marcado como enviado.' : 'Recordatorio cancelado.', $reminder);
  }

  // ── Permisos ───────────────────────────────────────

  private function me(): string
  {
    return $this->me ??= $this->store->currentEmployeeId();
  }

  private function canTeam(): bool
  {
    return $this->policy->canSubview('calendario', 'team');
  }

  /** @return array<int,array<string,string>> */
  private function allowedEmployees(): array
  {
    return $this->tickets->activeEmployeesByCargos($this->calendar->calendarCargos());
  }

  /** @return array<int,string> */
  private function allowedIds(): array
  {
    return $this->allowedIds ??= array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['id_empleado'] ?? ''), $this->allowedEmployees())));
  }

  /**
   * Qué agendas puede leer la persona: con el equipo, cualquiera de las habilitadas; sin él, la suya, o la de un compañero
   * solo como ocupado/libre. Si no se sabe quién es, nada.
   * @return array{0:array<int,string>,1:bool} [ids, enmascarar]
   */
  private function readScope(string $requested): array
  {
    $requested = trim($requested);
    $me = $this->me();
    if ($this->canTeam()) {
      if ($requested === '') {
        return [array_values(array_unique(array_filter(array_merge($this->allowedIds(), [$me])))), false];
      }
      return [$requested === $me || in_array($requested, $this->allowedIds(), true) ? [$requested] : [], false];
    }
    if ($requested === '' || $requested === $me) {
      return [$me !== '' ? [$me] : [], false];
    }
    return in_array($requested, $this->allowedIds(), true) ? [[$requested], true] : [[], false];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function readFilters(array $input, bool $masked): array
  {
    $dates = ['fecha_inicio' => $input['fecha_inicio'] ?? '', 'fecha_fin' => $input['fecha_fin'] ?? ''];
    if ($masked) {
      return $dates;
    }
    return $dates + array_intersect_key($input, array_flip(['id_categoria', 'estado', 'fue_trasladado', 'buscar']));
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private static function mask(array $row): array
  {
    return [
      'id' => $row['id'] ?? '',
      'tipo_item' => $row['tipo_item'] ?? 'evento',
      'titulo' => 'Ocupado',
      'fecha_inicio' => $row['fecha_inicio'] ?? '',
      'fecha_fin' => $row['fecha_fin'] ?? '',
      'id_empleado' => $row['id_empleado'] ?? '',
      'estado' => $row['estado'] ?? '',
      'color' => '#94a3b8',
    ];
  }

  private function canSeeTicket(int $ticket): bool
  {
    return $this->policy->canSeeAllTasks() || ($this->me() !== '' && $this->tickets->ticketMatchesEmployeeFilter($ticket, $this->me()));
  }

  /**
   * A sí mismo siempre. A otros, con "Calendario del equipo" o desde una tarea que la persona puede ver.
   * @param array<int,string> $employees
   */
  private function assertCanAssign(array $employees, int $ticket): void
  {
    if ($ticket > 0 && !$this->canSeeTicket($ticket)) {
      throw new \DomainException('Esta tarea no está asignada a tu usuario.');
    }
    foreach ($employees as $employee) {
      if ($employee === '') {
        throw new \InvalidArgumentException('Selecciona un funcionario.');
      }
      if ($employee === $this->me()) {
        continue;
      }
      if (!in_array($employee, $this->allowedIds(), true)) {
        throw new \DomainException('Ese funcionario no está habilitado en el calendario comercial.');
      }
      if (!$this->canTeam() && !($ticket > 0 && count($employees) === 1)) {
        throw new \DomainException('No tienes permiso para agendar en el calendario de otro funcionario.');
      }
    }
  }

  private function assertCategory(int $id): void
  {
    if ($this->store->category($id) === null) {
      throw new \InvalidArgumentException('La categoría seleccionada no existe.');
    }
    $enabled = $this->calendar->categoryIds();
    if ($enabled !== [] && !in_array((string) $id, $enabled, true)) {
      throw new \InvalidArgumentException('Esa categoría no está habilitada para el calendario comercial.');
    }
  }

  /** @return array<string,mixed> */
  private function editableEvent(int $id): array
  {
    $event = $id > 0 ? $this->store->event($id) : null;
    if ($event === null) {
      throw new \InvalidArgumentException('Evento no encontrado.');
    }
    if (!$this->canChange($event)) {
      throw new \DomainException('No tienes permiso para modificar este evento.');
    }
    return $event;
  }

  /** @return array<string,mixed> */
  private function editableReminder(int $id): array
  {
    $reminder = $id > 0 ? $this->store->reminder($id) : null;
    if ($reminder === null) {
      throw new \InvalidArgumentException('Recordatorio no encontrado.');
    }
    if (!$this->canChange($reminder)) {
      throw new \DomainException('No tienes permiso para modificar este recordatorio.');
    }
    return $reminder;
  }

  /** @param array<string,mixed> $row */
  private function canChange(array $row): bool
  {
    $me = $this->me();
    if ($this->canTeam()) {
      return true;
    }
    return $me !== '' && ((string) ($row['id_empleado'] ?? '') === $me || (string) ($row['creado_por'] ?? '') === $me);
  }

  // ── Apoyo ──────────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed>|null */
  private function blockResponse(string $employee, array $input): ?array
  {
    $block = $this->store->blockStatus($employee);
    if (!$block['bloqueado']) {
      return null;
    }
    $this->store->logBlock($employee, $block['cantidad']);
    $value = $input['permitir_vencidos'] ?? '';
    if ($value === true || in_array(strtolower(trim((string) $value)), ['1', 'si', 'true', 'yes'], true)) {
      return null;
    }
    return self::fail('Tienes ' . $block['cantidad'] . ' evento/s vencidos sin realizar. Finalízalos antes de crear otro.', ['bloqueado_vencidos' => true, 'cantidad' => $block['cantidad']]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function eventData(array $input, string $employee, string $start, string $end): array
  {
    return [
      'titulo' => (string) $input['titulo'],
      'descripcion' => (string) ($input['descripcion'] ?? ''),
      'ubicacion' => $input['ubicacion'] ?? null,
      'fecha_inicio' => $start,
      'fecha_fin' => $end,
      'id_empleado' => $employee,
      'creado_por' => $this->me(),
      'id_categoria' => (int) $input['id_categoria'],
      'id_ticket' => (int) ($input['id_ticket'] ?? 0),
      'meta' => is_array($input['meta'] ?? null) ? $input['meta'] : [],
    ];
  }

  /**
   * Actualiza la tarea vinculada sin borrar sus estados. Si esto falla el evento ya existe: se registra el error y no se
   * responde como fallo, para que nadie lo vuelva a crear.
   * @param array<string,mixed> $input
   */
  private function linkTicket(int $ticket, int $eventId, string $employee, array $input, string $start, string $end): void
  {
    try {
      $this->store->touchTicketForEvent($ticket, $this->commercialStatusChange($ticket, (string) ($input['estado_comercial'] ?? '')));
      if (strtolower(trim((string) ($input['es_cita'] ?? ''))) === 'si') {
        $this->store->addTicketHistory($ticket, $employee, $eventId, trim((string) ($input['descripcion'] ?? '')) ?: 'Cita agendada: ' . (string) $input['titulo']);
        $this->mailer->requesterAppointment(['id_ticket' => $ticket, 'id_empleado' => $employee, 'titulo' => (string) $input['titulo'], 'fecha_inicio' => $start, 'fecha_fin' => $end, 'ubicacion' => $input['ubicacion'] ?? '', 'id_categoria' => (int) $input['id_categoria']], false);
      }
    } catch (\Throwable $exception) {
      error_log('[commercial-calendar] tarea del evento ' . $eventId . ': ' . $exception->getMessage());
    }
  }

  /** Estado comercial a guardar: vacío si no cambia o si la persona no puede cambiarlo. */
  private function commercialStatusChange(int $ticket, string $requested): string
  {
    $requested = trim($requested);
    $current = trim((string) ($this->store->ticket($ticket)['estado_comercial'] ?? ''));
    if ($requested === '' || $requested === $current || !$this->policy->canAct('cambiar_estado') || !in_array($requested, CommercialStatusCatalog::OPEN, true)) {
      return '';
    }
    return $requested;
  }

  private function finalizeExpiredReminders(): void
  {
    $this->store->finalizeExpiredReminders();
  }

  private static function itemType(string $type): string
  {
    $type = strtolower(trim($type));
    if (in_array($type, ['tarea', 'tareas', 'task', 'tasks'], true)) {
      throw new \InvalidArgumentException('Las tareas fueron retiradas del calendario. Solo se permiten eventos y recordatorios.');
    }
    return $type === 'recordatorio' ? 'recordatorio' : 'evento';
  }

  /** @param mixed $value */
  private static function noticeMinutes($value): int
  {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 1440) {
      throw new \InvalidArgumentException('El aviso debe programarse entre 0 y 1440 minutos antes.');
    }
    return (int) $value;
  }

  private static function parseDate(string $value): ?string
  {
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') {
      return null;
    }
    $zone = new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
      if ($date !== false && $date->format($format) === $value) {
        return $date->format('Y-m-d H:i:s');
      }
    }
    return null;
  }

  /** @return array{0:string,1:string} */
  private static function dateRange(string $start, string $end): array
  {
    $from = self::parseDate($start);
    $to = self::parseDate($end);
    if ($from === null || $to === null) {
      throw new \InvalidArgumentException('Fecha inválida.');
    }
    if (strtotime($to) <= strtotime($from)) {
      throw new \InvalidArgumentException('La fecha de finalización debe ser posterior a la de inicio.');
    }
    return [$from, $to];
  }

  private static function plusMinutes(string $date, int $minutes): string
  {
    return (new \DateTimeImmutable($date, new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE)))->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
  }

  /** @param mixed $data @return array{success:bool,message:string,data:mixed} */
  private static function ok(string $message, $data = []): array
  {
    return ['success' => true, 'message' => $message, 'data' => $data];
  }

  /** @param mixed $data @return array{success:bool,message:string,data:mixed,status:int} */
  private static function fail(string $message, $data = [], int $status = 200): array
  {
    return ['success' => false, 'message' => $message, 'data' => $data, 'status' => $status];
  }
}
