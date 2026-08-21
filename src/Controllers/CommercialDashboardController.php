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
    $policy = new CommercialAccessPolicy($this->settings, $this->db, $adminCargos);

    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $requested = trim((string) ($input['tab'] ?? 'abiertos'));
    $bucket = $visibleViews === [] ? 'sin_acceso' : (in_array($requested, $visibleViews, true) ? $requested : $visibleViews[0]);
    $filters = [
      'estado' => trim((string) ($input['estado'] ?? '')),
      'busqueda' => trim((string) ($input['busqueda'] ?? '')),
      'id_empleado' => trim((string) ($input['id_empleado'] ?? '')),
      'page' => max(1, (int) ($input['page'] ?? 1)),
      'per_page' => 24,
    ];
    $result = in_array($bucket, ['calendario', 'sin_acceso'], true) ? ['rows' => [], 'counts' => [], 'pagination' => []] : $repository->search($bucket, $filters);
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
      'policy' => $policy,
      'visible_views' => $visibleViews,
      'ticket_employees' => $repository->ticketEmployees(),
      'calendar_employees' => $calendarEmployees,
      'runtime' => $runtime,
      'base_url' => (string) SCM_BASE_URL,
      'ticket_url' => (string) ($this->config['ticket_url'] ?? ''),
    ]);
  }
}
