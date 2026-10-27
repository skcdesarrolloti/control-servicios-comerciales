<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Precaptacion\ManagementHistory;
use SCM\Precaptacion\Workflow;
use SCM\Support\ColombiaBusinessCalendar;

/**
 * Calendario comercial: configuración del panel (funcionarios, categorías visibles)
 * y vencimientos comerciales (tareas, seguimientos, inmuebles, avisos y precaptación).
 */
final class CommercialCalendarService
{
  public const CATEGORY_SETTING = 'commercial_calendar_categories';
  public const CARGOS_SETTING = 'commercial_calendar_cargos';

  public const DEFAULT_APP_URL = 'https://calendar-skc.netlify.app';
  public const DEFAULT_API_URL = 'https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=';

  /** Tipos de vencimiento comercial: etiqueta, grupo, color y vista requerida para verlos. */
  public const DUE_TYPES = [
    'tarea_atrasada' => ['label' => 'Tareas atrasadas', 'group' => 'Tareas comerciales', 'color' => '#dc2626', 'view' => ''],
    'tarea_por_vencer' => ['label' => 'Tareas por vencer', 'group' => 'Tareas comerciales', 'color' => '#f59e0b', 'view' => ''],
    'tarea_seguimiento' => ['label' => 'Seguimientos programados', 'group' => 'Seguimientos', 'color' => '#2563eb', 'view' => ''],
    'actualizacion_inmueble' => ['label' => 'Actualizaciones de inmuebles', 'group' => 'Actualizaciones', 'color' => '#7c3aed', 'view' => 'actualizaciones'],
    'aviso_retoque' => ['label' => 'Retoques de aviso', 'group' => 'Avisos en fachada', 'color' => '#0f766e', 'view' => 'avisos'],
    'aviso_nuevo' => ['label' => 'Avisos nuevos sin instalar', 'group' => 'Avisos en fachada', 'color' => '#0891b2', 'view' => 'avisos'],
    'precaptacion_llamada' => ['label' => 'Llamadas de precaptación', 'group' => 'Precaptación', 'color' => '#ea580c', 'view' => 'precaptacion'],
  ];

  private Database $db;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private CommercialTicketsRepository $tickets;
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->tickets = new CommercialTicketsRepository($db);
    $this->config = $config;
  }

  /** Cargos cuyos funcionarios aparecen en el calendario (configuración guardada o .env). @return array<int,string> */
  public function calendarCargos(): array
  {
    $raw = $this->settings->get(self::CARGOS_SETTING, null);
    $source = is_array($raw) && $raw !== []
      ? $raw
      : (is_array($this->config['calendar_allowed_cargos'] ?? null) ? $this->config['calendar_allowed_cargos'] : ['9', '10', '17']);
    return self::sanitizeIds($source);
  }

  /** Categorías del calendario habilitadas para el panel comercial (vacío = todas). @return array<int,string> */
  public function categoryIds(): array
  {
    $raw = $this->settings->get(self::CATEGORY_SETTING, []);
    return is_array($raw) ? self::sanitizeIds($raw) : [];
  }

  /**
   * @param array<int|string,mixed> $ids
   * @param array<int|string,mixed> $availableIds
   * @return array<int,string>
   */
  public function saveCategoryIds(array $ids, array $availableIds = []): array
  {
    if (!$this->policy->canManage()) {
      throw new \RuntimeException('No tienes permiso para configurar las categorías del calendario.');
    }
    $ids = self::sanitizeIds($ids);
    if ($ids === []) {
      throw new \InvalidArgumentException('Selecciona al menos una categoría para el calendario comercial.');
    }
    $available = self::sanitizeIds($availableIds);
    // Si se marcaron todas las categorías disponibles se guarda vacío: así las categorías nuevas también se ven.
    if ($available !== [] && array_diff($available, $ids) === []) {
      $ids = [];
    }
    $this->settings->set(self::CATEGORY_SETTING, $ids, Auth::userId());
    $this->settings->refresh();
    return $ids;
  }

  /** @return array{employees:array<int,array<string,string>>,config:array<string,mixed>} */
  public function panelConfig(string $subtab): array
  {
    $cargos = $this->calendarCargos();
    $employees = $this->tickets->activeEmployeesByCargos($cargos);
    $currentEmployeeId = $this->currentEmployeeId($employees, $cargos);

    return [
      'employees' => $employees,
      'config' => [
        'calendar_allowed_cargos' => $cargos,
        'calendar_allowed_employee_ids' => array_values(array_map(static fn(array $employee): string => (string) ($employee['id_empleado'] ?? ''), $employees)),
        'calendar_allowed_funcionarios' => array_values(array_map(static fn(array $employee): array => [
          'id_empleado' => (string) ($employee['id_empleado'] ?? ''),
          'nombre' => (string) ($employee['nombre'] ?? ''),
        ], $employees)),
        'commercial_open_statuses' => CommercialStatusCatalog::OPEN,
        'calendar_current_employee_id' => $currentEmployeeId,
        'calendar_app_url' => (string) ($this->config['calendar_app_url'] ?? self::DEFAULT_APP_URL),
        'calendar_api_url' => (string) ($this->config['calendar_api_url'] ?? self::DEFAULT_API_URL),
        'calendar_initial_subtab' => in_array($subtab, ['mine', 'team', 'due'], true) ? $subtab : 'mine',
        'calendar_can_configure' => $this->policy->canManage(),
        'calendar_due_types' => $this->visibleDueTypes(),
        'is_admin' => $this->policy->canManage(),
      ],
    ];
  }

  /** Acciones AJAX que usa el motor del calendario (mismos nombres de clave que en administrativo). @return array<string,string> */
  public static function runtimeActions(): array
  {
    return [
      'admin_due_calendar' => 'commercial_calendar_due',
      'calendar_categories_save' => 'commercial_calendar_categories_save',
      'calendar_cita_notify' => 'commercial_calendar_cita_notify',
    ];
  }

  /** @return array<string,array{label:string,group:string,color:string,view:string}> */
  public function visibleDueTypes(): array
  {
    return array_filter(self::DUE_TYPES, fn(array $type): bool => $type['view'] === '' || $this->policy->canView($type['view']));
  }

  /**
   * Vencimientos comerciales ubicados en el calendario del rango pedido. Los vencidos antes del rango
   * se acumulan en el día de hoy (igual que en administrativo) para que no se pierdan de vista.
   *
   * @return array{eventos:array<int,array<string,mixed>>,items:array<int,array<string,mixed>>,stats:array<string,int>,settings:array<string,mixed>,can_configure:bool,summary_groups:array<int,mixed>,failed_sources:array<int,string>,types:array<string,array<string,string>>}
   */
  public function dueCalendar(string $from, string $to, string $employeeId = ''): array
  {
    $zone = new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE);
    $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone) ?: new \DateTimeImmutable('first day of this month 00:00', $zone);
    $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone) ?: $fromDate->modify('last day of this month');
    if ($toDate < $fromDate) {
      $toDate = $fromDate;
    }
    $fromTs = $fromDate->getTimestamp();
    $toTs = $toDate->setTime(23, 59, 59)->getTimestamp();

    $canSeeAll = $this->policy->canSeeAllCommercialTickets();
    $employeeFilter = $canSeeAll ? trim($employeeId) : $this->tickets->currentEmployeeTicketFilter($this->commercialCargos());
    $types = $this->visibleDueTypes();

    $sources = [];
    if (isset($types['tarea_atrasada']) || isset($types['tarea_por_vencer']) || isset($types['tarea_seguimiento'])) {
      $sources['tareas'] = fn(): array => $this->ticketDueItems($employeeFilter, $fromTs, $toTs);
    }
    if (isset($types['actualizacion_inmueble'])) {
      $sources['actualizaciones'] = fn(): array => $this->propertyUpdateDueItems($employeeFilter, $canSeeAll, $fromTs, $toTs);
    }
    if (isset($types['aviso_retoque']) || isset($types['aviso_nuevo'])) {
      $sources['avisos'] = fn(): array => $this->signDueItems($employeeFilter, $canSeeAll, $fromTs, $toTs);
    }
    if (isset($types['precaptacion_llamada'])) {
      $sources['precaptacion'] = fn(): array => $this->precaptacionDueItems($employeeFilter, $fromTs, $toTs);
    }
    // Una fuente con error no debe dejar el calendario vacío: se registra y se muestran las demás.
    $items = [];
    $failed = [];
    foreach ($sources as $name => $source) {
      try {
        $items = array_merge($items, $source());
      } catch (\Throwable $exception) {
        $failed[] = $name;
        error_log('Calendario comercial: no se pudieron calcular vencimientos de ' . $name . ': ' . $exception->getMessage());
      }
    }
    $items = array_values(array_filter($items, static fn(array $item): bool => isset($types[$item['tipo_vencimiento']])));
    usort($items, static fn(array $a, array $b): int => [$a['fecha_inicio'], $a['titulo']] <=> [$b['fecha_inicio'], $b['titulo']]);

    $stats = ['total' => count($items), 'vencidos' => 0, 'hoy' => 0];
    $today = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
    foreach ($items as $item) {
      $stats[$item['tipo_vencimiento']] = ($stats[$item['tipo_vencimiento']] ?? 0) + 1;
      $stats['vencidos'] += $item['estado'] === 'Vencido' ? 1 : 0;
      $stats['hoy'] += $item['fecha_vencimiento'] === $today ? 1 : 0;
    }

    return [
      'eventos' => $items,
      'items' => $items,
      'stats' => $stats,
      'settings' => [],
      'can_configure' => false,
      'summary_groups' => [],
      'failed_sources' => $failed,
      'types' => array_map(static fn(array $type): array => ['label' => $type['label'], 'group' => $type['group'], 'color' => $type['color']], $types),
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function ticketDueItems(string $employeeFilter, int $fromTs, int $toTs): array
  {
    $filters = $employeeFilter !== '' ? ['id_empleado' => $employeeFilter] : [];
    $items = [];
    foreach ($this->tickets->calendarOpenTickets($filters) as $ticket) {
      $pk = (string) ($ticket['_ID'] ?? '');
      $number = trim((string) ($ticket['id_ticket'] ?? '')) ?: $pk;
      $topic = trim((string) ($ticket['tema_ayuda'] ?? '')) ?: 'Tarea comercial';
      $subject = trim((string) ($ticket['asunto'] ?? ''));
      $requester = trim((string) ($ticket['solicitante'] ?? ''));
      $owner = trim((string) ($ticket['nombre_empleado'] ?? ''));
      $case = ['ticket_pk' => $pk, 'ticket' => $number, 'empleado_id' => (string) ($ticket['id_empleado'] ?? '')];
      $details = array_values(array_filter([$subject, $requester !== '' ? 'Solicitante: ' . $requester : '', $owner !== '' ? 'Responsable: ' . $owner : '']));

      $since = (int) ($ticket['scm_attention_since'] ?? 0);
      $dueDays = (int) ($ticket['scm_sla_due_days'] ?? 0);
      $status = (string) ($ticket['scm_sla_status'] ?? '');
      if ($since > 0 && $dueDays > 0 && in_array($status, ['atrasado', 'al_dia'], true)) {
        $due = ColombiaBusinessCalendar::addBusinessDays($since, $dueDays);
        $type = $status === 'atrasado' ? 'tarea_atrasada' : 'tarea_por_vencer';
        $item = $due ? $this->dueItem([
          'id' => 'sla-' . $pk,
          'type' => $type,
          'title' => 'Tarea #' . $number . ' · ' . $topic,
          'description' => implode(' · ', array_merge($details, ['Plazo: ' . $dueDays . ' días hábiles desde la última gestión'])),
          'base_ts' => $since,
          'due_ts' => $due->getTimestamp(),
          'days_limit' => $dueDays,
          'employee_id' => (string) ($ticket['id_empleado'] ?? ''),
          'employee' => $owner,
          'case' => $case,
        ], $fromTs, $toTs) : null;
        if ($item) {
          $items[] = $item;
        }
      }

      $followUp = ColombiaBusinessCalendar::timestamp($ticket['fecha_seguimiento'] ?? null) ?? 0;
      if ($followUp > 0) {
        $item = $this->dueItem([
          'id' => 'seguimiento-' . $pk,
          'type' => 'tarea_seguimiento',
          'title' => 'Seguimiento tarea #' . $number . ' · ' . $topic,
          'description' => implode(' · ', $details) ?: 'Seguimiento programado de la tarea comercial.',
          'base_ts' => ColombiaBusinessCalendar::timestamp($ticket['cct_created'] ?? null) ?? 0,
          'due_ts' => $followUp,
          'keep_time' => true,
          'employee_id' => (string) ($ticket['id_empleado'] ?? ''),
          'employee' => $owner,
          'case' => $case,
        ], $fromTs, $toTs);
        if ($item) {
          $items[] = $item;
        }
      }
    }
    return $items;
  }

  /** @return array<int,array<string,mixed>> */
  private function propertyUpdateDueItems(string $employeeFilter, bool $canSeeAll, int $fromTs, int $toTs): array
  {
    $result = $this->tickets->propertyUpdateControl(['id_empleado' => $employeeFilter], $canSeeAll);
    $items = [];
    foreach ($result['rows'] as $row) {
      $item = $this->propertyDueItem($row, 'actualizacion_inmueble', 'Actualizar inmueble #', 'Actualización del inmueble', $fromTs, $toTs);
      if ($item) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /** @return array<int,array<string,mixed>> */
  private function signDueItems(string $employeeFilter, bool $canSeeAll, int $fromTs, int $toTs): array
  {
    $types = $this->visibleDueTypes();
    $items = [];
    foreach (['maintenance' => 'aviso_retoque', 'new' => 'aviso_nuevo'] as $mode => $type) {
      if (!isset($types[$type])) {
        continue;
      }
      $result = $this->tickets->signControl($mode, ['id_empleado' => $employeeFilter], $canSeeAll);
      foreach ($result['rows'] as $row) {
        $item = $type === 'aviso_retoque'
          ? $this->propertyDueItem($row, $type, 'Retoque de aviso · inmueble #', 'Retoque del aviso en fachada', $fromTs, $toTs)
          : $this->propertyDueItem($row, $type, 'Instalar aviso · inmueble #', 'Instalación del aviso nuevo', $fromTs, $toTs);
        if ($item) {
          $items[] = $item;
        }
      }
    }
    return $items;
  }

  /** @param array<string,mixed> $row @return array<string,mixed>|null */
  private function propertyDueItem(array $row, string $type, string $titlePrefix, string $label, int $fromTs, int $toTs): ?array
  {
    $days = (int) ($row['dias'] ?? 0);
    $max = (int) ($row['max'] ?? 0);
    if ($max <= 0) {
      return null;
    }
    $today = new \DateTimeImmutable('today', new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE));
    $base = $today->modify('-' . $days . ' days');
    $due = $base->modify('+' . $max . ' days');
    $code = (string) ($row['codigo'] ?? '');
    $place = trim(implode(', ', array_filter([(string) ($row['direccion'] ?? ''), (string) ($row['barrio'] ?? '')])));
    $details = array_values(array_filter([
      $label . ' (' . $days . ' de ' . $max . ' días)',
      (string) ($row['gestion'] ?? ''),
      $place,
      ($row['funcionario'] ?? '') !== '' ? 'Responsable: ' . $row['funcionario'] : '',
    ]));

    return $this->dueItem([
      'id' => $type . '-' . $code,
      'type' => $type,
      'title' => $titlePrefix . $code,
      'description' => implode(' · ', $details),
      'base_ts' => $base->getTimestamp(),
      'due_ts' => $due->getTimestamp(),
      'days_limit' => $max,
      'employee_id' => (string) ($row['id_funcionario'] ?? ''),
      'employee' => (string) ($row['funcionario'] ?? ''),
      'case' => [
        'property_code' => $code,
        'link_url' => (string) ($type === 'actualizacion_inmueble' ? ($row['url_actualizar'] ?? '') : ($row['maps_url'] ?? '')),
        'link_label' => $type === 'actualizacion_inmueble' ? 'Actualizar' : 'Ver ubicación',
        'property_url' => (string) ($row['property_url'] ?? ''),
      ],
    ], $fromTs, $toTs);
  }

  /** @return array<int,array<string,mixed>> */
  private function precaptacionDueItems(string $employeeFilter, int $fromTs, int $toTs): array
  {
    try {
      $workflow = new Workflow($this->db, new ManagementHistory($this->db));
      $rows = $workflow->contactAgenda();
    } catch (\Throwable $exception) {
      error_log('Calendario comercial: no se pudo leer la agenda de precaptación: ' . $exception->getMessage());
      return [];
    }

    $owners = $employeeFilter !== '' ? self::sanitizeIds(explode(',', $employeeFilter)) : [];
    $baseUrl = rtrim((string) (defined('SCM_BASE_URL') ? SCM_BASE_URL : ''), '/');
    $items = [];
    foreach ($rows as $row) {
      if ($owners !== [] && !in_array((string) ($row['owner_id'] ?? ''), $owners, true)) {
        continue;
      }
      $next = ColombiaBusinessCalendar::timestamp($row['next'] ?? null) ?? 0;
      if ($next <= 0) {
        continue;
      }
      $id = (int) ($row['id'] ?? 0);
      $contact = trim((string) ($row['contact'] ?? '')) ?: 'Contacto #' . $id;
      $item = $this->dueItem([
        'id' => 'precaptacion-' . $id,
        'type' => 'precaptacion_llamada',
        'title' => 'Llamar a ' . $contact,
        'description' => implode(' · ', array_values(array_filter([
          'Precaptación #' . $id,
          (string) ($row['status'] ?? ''),
          ($row['phone'] ?? '') !== '' ? 'Cel: ' . $row['phone'] : '',
          ($row['owner'] ?? '') !== '' ? 'Responsable: ' . $row['owner'] : '',
        ]))),
        'base_ts' => 0,
        'due_ts' => $next,
        'keep_time' => true,
        'employee_id' => (string) ($row['owner_id'] ?? ''),
        'employee' => (string) ($row['owner'] ?? ''),
        'case' => [
          'link_url' => $baseUrl . '/index.php?tab=precaptacion',
          'link_label' => 'Ir a precaptación',
        ],
      ], $fromTs, $toTs);
      if ($item) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Arma el vencimiento con la misma forma que usa el calendario administrativo.
   *
   * @param array<string,mixed> $data
   * @return array<string,mixed>|null
   */
  private function dueItem(array $data, int $fromTs, int $toTs): ?array
  {
    $zone = new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE);
    $dueTs = (int) ($data['due_ts'] ?? 0);
    if ($dueTs <= 0) {
      return null;
    }
    $today = new \DateTimeImmutable('today', $zone);
    $todayTs = $today->getTimestamp();
    $due = (new \DateTimeImmutable('@' . $dueTs))->setTimezone($zone);
    $dueDayTs = $due->setTime(0, 0)->getTimestamp();

    if ($dueTs >= $fromTs && $dueTs <= $toTs) {
      $calendar = $due;
    } elseif ($dueDayTs < $todayTs && $dueTs < $fromTs && $todayTs >= $fromTs && $todayTs <= $toTs) {
      $calendar = $today->setTime(8, 0);
    } else {
      return null;
    }

    $keepTime = !empty($data['keep_time']) && $calendar === $due && $due->format('H:i') !== '00:00';
    $start = $keepTime ? $calendar : $calendar->setTime(8, 0);
    $end = $keepTime ? $calendar->modify('+30 minutes') : $calendar->setTime(18, 0);
    $overdue = $dueDayTs < $todayTs;
    $baseTs = (int) ($data['base_ts'] ?? 0);
    $type = (string) $data['type'];
    $meta = self::DUE_TYPES[$type] ?? ['label' => 'Vencimiento', 'group' => 'Vencimientos', 'color' => '#f59e0b'];

    return [
      'id' => (string) ($data['id'] ?? ''),
      'titulo' => (string) ($data['title'] ?? 'Vencimiento'),
      'descripcion' => (string) ($data['description'] ?? ''),
      'grupo' => $meta['group'],
      'tipo_vencimiento' => $type,
      'estado' => $overdue ? 'Vencido' : 'Pendiente',
      'color' => $meta['color'],
      'fecha_base' => $baseTs > 0 ? (new \DateTimeImmutable('@' . $baseTs))->setTimezone($zone)->format('Y-m-d') : '',
      'fecha_vencimiento' => $due->format('Y-m-d'),
      'fecha_calendario' => $calendar->format('Y-m-d'),
      'fecha_inicio' => $start->format('Y-m-d H:i:s'),
      'fecha_fin' => $end->format('Y-m-d H:i:s'),
      'acumulado_vencido' => $calendar->format('Y-m-d') !== $due->format('Y-m-d') ? 1 : 0,
      'dias_limite' => (int) ($data['days_limit'] ?? 0),
      'dias_transcurridos' => $baseTs > 0 ? max(0, (int) floor(($todayTs - $baseTs) / 86400)) : 0,
      'dias_vencido' => $overdue ? max(0, (int) floor(($todayTs - $dueDayTs) / 86400)) : 0,
      'id_empleado' => (string) ($data['employee_id'] ?? ''),
      'funcionario' => (string) ($data['employee'] ?? ''),
      'case' => is_array($data['case'] ?? null) ? $data['case'] : [],
    ];
  }

  /** @param array<int,array<string,string>> $employees @param array<int,string> $cargos */
  private function currentEmployeeId(array $employees, array $cargos): string
  {
    $current = trim(Auth::employeeId());
    if ($current !== '') {
      return $current;
    }
    foreach ($employees as $employee) {
      if ((int) ($employee['_pk'] ?? 0) === Auth::userId() || (string) ($employee['id_empleado'] ?? '') === (string) Auth::userId()) {
        return (string) ($employee['id_empleado'] ?? '');
      }
    }
    $current = $this->tickets->currentEmployeeTicketFilter($cargos);
    if ($current === '' && $employees !== []) {
      $current = (string) ($employees[0]['id_empleado'] ?? '');
    }
    return $current;
  }

  /** @return array<int,string> */
  private function commercialCargos(): array
  {
    $raw = $this->settings->get('commercial_employee_cargos', null);
    $defaults = is_array($this->config['commercial_employee_cargos'] ?? null) ? $this->config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    return self::sanitizeIds(is_array($raw) && $raw !== [] ? $raw : $defaults);
  }

  /** @param array<int|string,mixed> $ids @return array<int,string> */
  private static function sanitizeIds(array $ids): array
  {
    return array_values(array_unique(array_filter(array_map(
      static fn($id): string => preg_replace('/[^0-9]/', '', trim((string) $id)) ?? '',
      $ids
    ), static fn(string $id): bool => $id !== '')));
  }
}
