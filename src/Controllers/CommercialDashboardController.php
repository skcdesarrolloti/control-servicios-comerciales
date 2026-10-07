<?php

declare(strict_types=1);

namespace SCM\Controllers;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;
use SCM\Commercial\CommercialPropertiesRepository;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialDashboardView;

final class CommercialDashboardController
{
  private Database $db;
  private Settings $settings;
  private Csrf $csrf;
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->csrf = $csrf;
    $this->config = $config;
  }

  /** @param array<string,mixed> $input */
  public function render(array $input): string
  {
    $repository = new CommercialTicketsRepository($this->db);
    $adminCargos = is_array($this->config['dashboard_admin_cargos'] ?? null) ? $this->config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $calendarCargos = is_array($this->config['calendar_allowed_cargos'] ?? null) ? $this->config['calendar_allowed_cargos'] : ['9', '10', '17'];
    $defaultCommercialEmployeeCargos = is_array($this->config['commercial_employee_cargos'] ?? null) ? $this->config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $commercialEmployeeCargos = $this->configuredCargoIds('commercial_employee_cargos', $defaultCommercialEmployeeCargos);
    $policy = new CommercialAccessPolicy($this->settings, $this->db, $adminCargos);

    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $requested = trim((string) ($input['tab'] ?? 'inicio'));
    $bucket = in_array($requested, $visibleViews, true) ? $requested : ($visibleViews[0] ?? 'sin_acceso');
    $subviewDenied = false;
    try {
      $input = $policy->resolveNavigation($bucket, $input);
    } catch (\RuntimeException $exception) {
      $subviewDenied = true;
    }
    if ($subviewDenied || $visibleViews === [] || (isset($input['tab']) && $requested !== '' && !in_array($requested, $visibleViews, true))) {
      http_response_code(403);
      return CommercialDashboardView::render([
        'bucket' => 'sin_acceso', 'policy' => $policy, 'visible_views' => $visibleViews,
        'base_url' => (string) SCM_BASE_URL,
        'runtime' => ['ajaxUrl' => rtrim((string) SCM_BASE_URL, '/') . '/api.php', 'nonce' => $this->csrf->token('commercial_nonce'), 'initialTab' => 'scm-panel-sin_acceso'],
      ]);
    }
    $ticketEmployees = $repository->ticketEmployees($commercialEmployeeCargos);
    $filters = $this->ticketFilters($input);
    $filters['tab'] = $bucket;
    $currentEmployeeFilter = $repository->currentEmployeeTicketFilter($commercialEmployeeCargos);
    $personalTaskScope = $bucket === 'mis_tickets';
    $employeeFilterLocked = !$policy->canSeeAllCommercialTickets();
    if ($personalTaskScope || $employeeFilterLocked) {
      $filters['id_empleado'] = $currentEmployeeFilter;
    }
    $globalCountFilters = $personalTaskScope && $policy->canSeeAllCommercialTickets()
      ? []
      : $this->globalTicketFilters($filters);
    if ($bucket === 'inicio' && $policy->canSeeAllCommercialTickets()) {
      $globalCountFilters = [];
    }
    $tabCounts = $repository->bucketCounts($globalCountFilters);
    $myTabCounts = $repository->bucketCounts(['id_empleado' => $currentEmployeeFilter]);
    $tabCounts['mis_tickets'] = (int) ($myTabCounts['mis_tickets'] ?? 0);
    $result = in_array($bucket, ['actualizaciones', 'avisos', 'calendario', 'inmuebles', 'notificaciones'], true) ? ['rows' => [], 'counts' => $repository->statusCounts($filters), 'pagination' => []] : $repository->search($bucket === 'inicio' ? 'abiertos' : $bucket, $filters);
    $homeDashboard = $repository->homeDashboard($globalCountFilters);
    $canSeeAll = $policy->canSeeAllCommercialTickets();
    $propertiesRepository = new CommercialPropertiesRepository($this->db);
    $propertyFilters = $this->propertyFilters($input, !$canSeeAll);
    $propertySummaryCounts = $propertiesRepository->summaryCounts($propertyFilters, $canSeeAll);
    $propertyResult = $bucket === 'inmuebles'
      ? $propertiesRepository->search($propertyFilters, $canSeeAll, (int) ($propertyFilters['page'] ?? 1), 24)
      : ['rows' => [], 'total' => 0, 'pagination' => []];
    $propertyOptions = $propertiesRepository->filterOptions();
    $calendarEmployees = $repository->activeEmployeesByCargos($calendarCargos);
    $currentCalendarEmployeeId = trim(Auth::employeeId());
    if ($currentCalendarEmployeeId === '') {
      foreach ($calendarEmployees as $employee) {
        if ((int) ($employee['_pk'] ?? 0) === Auth::userId() || (string) ($employee['id_empleado'] ?? '') === (string) Auth::userId()) {
          $currentCalendarEmployeeId = (string) ($employee['id_empleado'] ?? '');
          break;
        }
      }
    }
    if ($currentCalendarEmployeeId === '') {
      $currentCalendarEmployeeId = $repository->currentEmployeeTicketFilter($calendarCargos);
    }
    if ($currentCalendarEmployeeId === '' && !empty($calendarEmployees)) {
      $currentCalendarEmployeeId = (string) ($calendarEmployees[0]['id_empleado'] ?? '');
    }

    $subtab = trim((string) ($input['subtab'] ?? 'mine'));
    if (!in_array($subtab, ['mine', 'team', 'due'], true)) {
      $subtab = 'mine';
    }
    $isNonAdmin = !$policy->canSeeAllCommercialTickets();
    $recentTickets = $repository->latestCreatedTickets($isNonAdmin, $currentEmployeeFilter, 12);
    $recentCount = count($recentTickets);
    $topicHierarchy = $repository->topicStatusHierarchy($globalCountFilters);

    $panelId = $bucket === 'calendario' ? 'scm-panel-actividades-administrativas' : 'scm-panel-' . $bucket;
    $runtime = [
      'ajaxUrl' => rtrim((string) SCM_BASE_URL, '/') . '/api.php',
      'nonce' => $this->csrf->token('commercial_nonce'),
      'initialTab' => $panelId,
      'actions' => [],
      'config' => [
        'commercial_statuses' => CommercialStatusCatalog::all(),
        'calendar_allowed_cargos' => array_values(array_map('strval', $calendarCargos)),
        'calendar_allowed_employee_ids' => array_values(array_map(static fn(array $employee): string => (string) ($employee['id_empleado'] ?? ''), $calendarEmployees)),
        'calendar_current_employee_id' => $currentCalendarEmployeeId,
        'calendar_app_url' => (string) ($this->config['calendar_app_url'] ?? 'https://calendar-skc.netlify.app'),
        'calendar_api_url' => (string) ($this->config['calendar_api_url'] ?? 'https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action='),
        'calendar_initial_subtab' => $subtab,
        'is_admin' => $policy->canManage(),
      ],
    ];

    return CommercialDashboardView::render([
      'bucket' => $bucket,
      'subtab' => $subtab,
      'filters' => $filters,
      'result' => $result,
      'home_dashboard' => $homeDashboard,
      'tab_counts' => $tabCounts,
      'policy' => $policy,
      'notifications_service' => $bucket === 'notificaciones' ? new CommercialNotificationsService($this->db, $policy) : null,
      'visible_views' => $visibleViews,
      'ticket_employees' => $ticketEmployees,
      'commercial_employee_cargos' => $commercialEmployeeCargos,
      'employee_filter_locked' => $employeeFilterLocked,
      'personal_task_scope' => $personalTaskScope,
      'filter_options' => $repository->filterOptions(),
      'calendar_employees' => $calendarEmployees,
      'topic_hierarchy' => $topicHierarchy,
      'recent_tickets' => $recentTickets,
      'recent_count' => $recentCount,
      'property_result' => $propertyResult,
      'property_filters' => $propertyFilters,
      'property_summary_counts' => $propertySummaryCounts,
      'property_options' => $propertyOptions,
      'runtime' => $runtime,
      'base_url' => (string) SCM_BASE_URL,
      'ticket_url' => (string) ($this->config['ticket_url'] ?? ''),
    ]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function propertyFilters(array $input, bool $isNonAdmin): array
  {
    $clean = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    $subtab = $clean('property_subtab');
    if ($subtab === '' && isset($input['subtab'])) {
      $subtab = $clean('subtab');
    }
    if (!in_array($subtab, ['publicos', 'pendientes', 'no_publicos', 'destacados', 'mis_solicitudes', 'mis_inmuebles'], true)) {
      $subtab = $isNonAdmin ? 'mis_inmuebles' : 'publicos';
    }

    return [
      'property_subtab' => $subtab,
      'busqueda' => $clean('busqueda'),
      'tipo_inmueble' => $clean('tipo_inmueble'),
      'tipo_negocio' => $clean('tipo_negocio'),
      'ciudad' => $clean('ciudad'),
      'barrio' => $clean('barrio'),
      'destinacion' => $clean('destinacion'),
      'destacado' => $clean('destacado'),
      'estado' => $clean('estado'),
      'id_funcionario' => $clean('id_funcionario'),
      'page' => max(1, (int) ($input['page'] ?? 1)),
      'per_page' => 24,
    ];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function ticketFilters(array $input): array
  {
    $clean = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    return [
      'estado' => $clean('estado'),
      'mis_bucket' => $clean('mis_bucket'),
      'busqueda' => $clean('busqueda'),
      'id_empleado' => $clean('id_empleado'),
      'ticket_id' => $clean('ticket_id'),
      'solicitante' => $clean('solicitante'),
      'celular' => $clean('celular'),
      'correo' => $clean('correo'),
      'inmueble' => $clean('inmueble'),
      'barrio' => $clean('barrio'),
      'medio' => $clean('medio'),
      'prioridad' => $clean('prioridad'),
      'tema' => $clean('tema'),
      'seguimiento' => $clean('seguimiento'),
      'encargado_seguimiento' => $clean('encargado_seguimiento'),
      'fecha_seguimiento_desde' => $clean('fecha_seguimiento_desde'),
      'fecha_seguimiento_hasta' => $clean('fecha_seguimiento_hasta'),
      'sin_actualizar' => $clean('sin_actualizar'),
      'estado_administrativo' => $clean('estado_administrativo'),
      'codigo' => $clean('codigo'),
      'gestion' => $clean('gestion'),
      'tipo' => $clean('tipo'),
      'ruta' => $clean('ruta'),
      'estado_actualizacion' => $clean('estado_actualizacion'),
      'estado_aviso' => $clean('estado_aviso'),
      'fecha_desde' => $clean('fecha_desde'),
      'fecha_hasta' => $clean('fecha_hasta'),
      'sla_filter' => $clean('sla_filter'),
      'page' => max(1, (int) ($input['page'] ?? 1)),
      'per_page' => 24,
    ];
  }

  /** @param array<string,mixed> $filters @return array<string,mixed> */
  private function globalTicketFilters(array $filters): array
  {
    return [
      'id_empleado' => trim((string) ($filters['id_empleado'] ?? '')),
    ];
  }

  /** @param array<int,string|int> $defaults @return array<int,string> */
  private function configuredCargoIds(string $key, array $defaults): array
  {
    $raw = $this->settings->get($key, null);
    $source = is_array($raw) && $raw !== [] ? $raw : $defaults;
    return array_values(array_unique(array_filter(array_map(
      static fn($id): string => trim((string) $id),
      $source
    ), static fn(string $id): bool => $id !== '')));
  }
}
