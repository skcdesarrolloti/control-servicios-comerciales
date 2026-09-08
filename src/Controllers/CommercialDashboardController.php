<?php

declare(strict_types=1);

namespace SCM\Controllers;

use SCM\Commercial\CommercialAccessPolicy;
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
    $commercialEmployeeCargos = is_array($this->config['commercial_employee_cargos'] ?? null) ? $this->config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $policy = new CommercialAccessPolicy($this->settings, $this->db, $adminCargos);

    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $requested = trim((string) ($input['tab'] ?? 'inicio'));
    $bucket = $visibleViews === [] ? 'sin_acceso' : (in_array($requested, $visibleViews, true) ? $requested : $visibleViews[0]);
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
    $tabCounts = $repository->bucketCounts($globalCountFilters);
    $myTabCounts = $repository->bucketCounts(['id_empleado' => $currentEmployeeFilter]);
    $tabCounts['mis_tickets'] = (int) ($myTabCounts['mis_tickets'] ?? 0);
    $result = in_array($bucket, ['inicio', 'calendario', 'sin_acceso'], true) ? ['rows' => [], 'counts' => $repository->statusCounts($filters), 'pagination' => []] : $repository->search($bucket, $filters);
    $homeDashboard = $bucket === 'sin_acceso' ? [] : $repository->homeDashboard($globalCountFilters);
    $calendarEmployees = $repository->activeEmployeesByCargos($calendarCargos);
    $currentCalendarEmployeeId = '';
    foreach ($calendarEmployees as $employee) {
      if ((int) ($employee['_pk'] ?? 0) === Auth::userId()) {
        $currentCalendarEmployeeId = (string) ($employee['id_empleado'] ?? '');
        break;
      }
    }

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
        'calendar_app_url' => (string) ($this->config['calendar_app_url'] ?? ''),
        'calendar_api_url' => (string) ($this->config['calendar_api_url'] ?? ''),
      ],
    ];

    return CommercialDashboardView::render([
      'bucket' => $bucket,
      'filters' => $filters,
      'result' => $result,
      'home_dashboard' => $homeDashboard,
      'tab_counts' => $tabCounts,
      'policy' => $policy,
      'visible_views' => $visibleViews,
      'ticket_employees' => $ticketEmployees,
      'employee_filter_locked' => $employeeFilterLocked,
      'personal_task_scope' => $personalTaskScope,
      'filter_options' => $repository->filterOptions(),
      'calendar_employees' => $calendarEmployees,
      'runtime' => $runtime,
      'base_url' => (string) SCM_BASE_URL,
      'ticket_url' => (string) ($this->config['ticket_url'] ?? ''),
    ]);
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
}
