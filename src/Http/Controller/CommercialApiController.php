<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertiesRepository;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTaskAnalysisRepository;
use SCM\Commercial\CommercialTaskAssistant;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Modules\ServiciosInmobiliarios\SeguimientoService;
use SCM\Support\EmailQueue;
use SCM\Support\SchemaInspector;
use SCM\Support\StoredFileService;
use SCM\Views\CommercialDashboardView;
use SCM\Views\CommercialTicketModalView;

final class CommercialApiController
{
  private Database $db;
  private CommercialTicketsRepository $tickets;
  private CommercialTaskAnalysisRepository $analyses;
  private CommercialAccessPolicy $policy;
  private Settings $settings;
  private Csrf $csrf;
  private SeguimientoService $workflow;
  private string $baseUrl;
  private string $ticketUrl;
  /** @var array<string,mixed> */
  private array $config;
  /** @var array<int,string> */
  private array $commercialCargos;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->db = $db;
    $this->tickets = new CommercialTicketsRepository($db);
    $this->analyses = new CommercialTaskAnalysisRepository($db);
    $this->settings = $settings;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $defaultCommercialCargos = is_array($config['commercial_employee_cargos'] ?? null) ? $config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $this->commercialCargos = $this->configuredCargoIds('commercial_employee_cargos', $defaultCommercialCargos);
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->workflow = new SeguimientoService($db, new SchemaInspector($db));
    $this->workflow->setQueue(new EmailQueue($db));
    $this->baseUrl = rtrim((string) SCM_BASE_URL, '/');
    $this->ticketUrl = (string) ($config['ticket_url'] ?? '');
    $this->config = $config;
  }

  /** @param array<string,mixed> $input */
  public function filterTickets(array $input): never
  {
    $this->verify($input);
    $bucket = trim((string) ($input['tab'] ?? 'inicio'));
    if (!array_key_exists($bucket, CommercialAccessPolicy::VIEWS) || !$this->policy->canView($bucket)) {
      JsonResponse::error('No tienes permiso para consultar esta vista.', 403);
    }
    $filters = $this->ticketFilters($input);
    $filters['tab'] = $bucket;
    $filters = $this->scopeTicketFilters($filters);
    $personalTaskScope = $bucket === 'mis_tickets';
    $ticketEmployees = $this->tickets->ticketEmployees($this->commercialCargos);
    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), fn(string $view): bool => $this->policy->canView($view)));
    $currentEmployeeFilter = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    $globalCountFilters = $personalTaskScope && $this->policy->canSeeAllCommercialTickets()
      ? []
      : $this->globalTicketFilters($filters);
    if ($bucket === 'inicio' && $this->policy->canSeeAllCommercialTickets()) {
      $globalCountFilters = [];
    }
    $tabCounts = $this->tickets->bucketCounts($globalCountFilters);
    $myTabCounts = $this->tickets->bucketCounts(['id_empleado' => $currentEmployeeFilter]);
    $tabCounts['mis_tickets'] = (int) ($myTabCounts['mis_tickets'] ?? 0);
    $html = '';
    if ($bucket === 'inicio') {
      $result = $this->tickets->search('abiertos', $filters);
      $html = CommercialDashboardView::renderHome(
        $this->tickets->homeDashboard($globalCountFilters),
        $filters,
        $this->policy,
        $this->baseUrl,
        $ticketEmployees,
        $this->tickets->filterOptions(),
        $result,
        $tabCounts
      );
    } elseif ($bucket === 'actualizaciones') {
      $html = CommercialDashboardView::renderPropertyUpdatesPage(
        $filters,
        $ticketEmployees,
        $this->tickets->filterOptions(),
        $this->policy
      );
    } elseif ($bucket === 'avisos') {
      $html = CommercialDashboardView::renderSignsPage(
        $filters,
        $ticketEmployees,
        $this->tickets->filterOptions(),
        $this->policy
      );
    } elseif ($bucket === 'calendario') {
      $calendarCargos = $this->configuredCargoIds('commercial_calendar_cargos', [9, 10, 17]);
      $calendarEmployees = $this->tickets->activeEmployeesByCargos($calendarCargos);
      $currentCalendarEmployeeId = trim(\SCM\Core\Auth::employeeId());
      if ($currentCalendarEmployeeId === '') {
        foreach ($calendarEmployees as $employee) {
          if ((int) ($employee['_pk'] ?? 0) === \SCM\Core\Auth::userId() || (string) ($employee['id_empleado'] ?? '') === (string) \SCM\Core\Auth::userId()) {
            $currentCalendarEmployeeId = (string) ($employee['id_empleado'] ?? '');
            break;
          }
        }
      }
      if ($currentCalendarEmployeeId === '') {
        $currentCalendarEmployeeId = $this->tickets->currentEmployeeTicketFilter($calendarCargos);
      }
      if ($currentCalendarEmployeeId === '' && !empty($calendarEmployees)) {
        $currentCalendarEmployeeId = (string) ($calendarEmployees[0]['id_empleado'] ?? '');
      }
      $subtab = trim((string) ($input['subtab'] ?? 'mine'));
      $calendarConfig = array_merge($this->config, [
        'calendar_allowed_cargos' => array_values(array_map('strval', $calendarCargos)),
        'calendar_allowed_employee_ids' => array_values(array_map(static fn(array $employee): string => (string) ($employee['id_empleado'] ?? ''), $calendarEmployees)),
        'calendar_current_employee_id' => $currentCalendarEmployeeId,
        'calendar_app_url' => (string) ($this->config['calendar_app_url'] ?? 'https://calendar-skc.netlify.app'),
        'calendar_api_url' => (string) ($this->config['calendar_api_url'] ?? 'https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action='),
        'calendar_initial_subtab' => $subtab,
        'is_admin' => $this->policy->canManage(),
      ]);
      $html = CommercialDashboardView::renderCalendarPage($calendarConfig, $calendarEmployees, $subtab, $this->policy, $this->baseUrl);
    } elseif ($bucket === 'inmuebles') {
      $propertiesRepo = new CommercialPropertiesRepository($this->db);
      $canSeeAll = $this->policy->canSeeAllCommercialTickets();
      $propFilters = $this->propertyFilters($input, !$canSeeAll);
      $propCounts = $propertiesRepo->summaryCounts($propFilters, $canSeeAll);
      $propResult = $propertiesRepo->search($propFilters, $canSeeAll, (int) ($propFilters['page'] ?? 1), 24);
      $propOptions = $propertiesRepo->filterOptions();
      $html = CommercialDashboardView::renderPropertiesPage(
        $propResult,
        $propFilters,
        $propCounts,
        $propOptions,
        $this->policy,
        $this->baseUrl
      );
    } else {
      $result = $this->tickets->search($bucket, $filters);
      $html = CommercialDashboardView::renderTickets(
        $bucket,
        $result,
        $filters,
        $ticketEmployees,
        $this->tickets->filterOptions(),
        $tabCounts,
        $this->policy,
        $this->baseUrl
      );
    }
    $topicHierarchy = $this->tickets->topicStatusHierarchy($globalCountFilters);
    JsonResponse::success([
      'html' => $html,
      'tabs_html' => CommercialDashboardView::renderTabs($visibleViews, $bucket, $filters, $tabCounts, $this->baseUrl, $topicHierarchy),
      'tab' => $bucket,
    ]);
  }

  /** @param array<string,mixed> $input */
  public function ticketDetail(array $input): never
  {
    $this->verify($input);
    $this->authorize('ver_ticket', 'No tienes permiso para ver esta tarea.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    try {
      $detail = $this->tickets->detail($ticketPk);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 404);
    }
    try {
      $detail['analyses'] = $this->analyses->listForTicket($ticketPk);
    } catch (\Throwable $exception) {
      error_log('[commercial-analysis:list] ' . $exception->getMessage());
      $detail['analyses'] = [];
    }
    JsonResponse::success([
      'html' => CommercialTicketModalView::render(
        $detail,
        $this->policy,
        $this->tickets->activeEmployeesByCargos($this->commercialCargos),
        $this->ticketUrl
      ),
    ]);
  }

  /** @param array<string,mixed> $input */
  public function analyzeTicket(array $input): never
  {
    $this->verify($input);
    $this->authorize('ver_ticket', 'No tienes permiso para analizar esta tarea.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    try {
      $detail = $this->tickets->detail($ticketPk);
      $todayAnalysis = $this->analyses->todayForTicket($ticketPk);
      if (is_array($todayAnalysis)) {
        JsonResponse::success([
          'analysis' => $todayAnalysis,
          'analyses' => $this->analyses->listForTicket($ticketPk),
          'reused' => true,
          'message' => 'Esta tarea ya tenía un análisis guardado hoy.',
        ]);
      }
      if ($this->analyses->countForTicket($ticketPk) >= 3) {
        JsonResponse::error('Esta tarea ya tiene 3 análisis guardados. Elimina uno anterior para generar otro.', 422);
      }
      $assistant = new CommercialTaskAssistant(
        (string) ($this->config['minimax_api_key'] ?? ''),
        (string) ($this->config['minimax_base_url'] ?? 'https://api.minimax.io/v1'),
        (string) ($this->config['minimax_model'] ?? 'MiniMax-M3'),
        (int) ($this->config['minimax_timeout'] ?? 45)
      );
      $analysis = $this->analyses->save($detail['ticket'], $assistant->analyze($detail));
      JsonResponse::success([
        'analysis' => $analysis,
        'analyses' => $this->analyses->listForTicket($ticketPk),
        'reused' => false,
        'message' => 'Análisis guardado.',
      ]);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  /** @param array<string,mixed> $input */
  public function deleteAnalysis(array $input): never
  {
    $this->verify($input);
    $this->authorize('ver_ticket', 'No tienes permiso para eliminar análisis de esta tarea.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $analysisId = (int) ($input['analysis_id'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    if (!$this->analyses->delete($ticketPk, $analysisId)) {
      JsonResponse::error('No se pudo eliminar el análisis seleccionado.', 404);
    }
    JsonResponse::success([
      'message' => 'Análisis eliminado.',
      'analyses' => $this->analyses->listForTicket($ticketPk),
    ]);
  }

  /** @param array<string,mixed> $input */
  public function changeStatus(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canAct('cambiar_estado')) {
      JsonResponse::error('No tienes permiso para cambiar estados comerciales.', 403);
    }
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    try {
      $this->tickets->changeStatus($ticketPk, trim((string) ($input['estado'] ?? '')));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'El estado comercial fue actualizado.']);
  }

  /** @param array<string,mixed> $input */
  public function reassign(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canAct('reasignar')) {
      JsonResponse::error('No tienes permiso para reasignar tareas.', 403);
    }
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $newEmployeeId = trim((string) ($input['id_empleado'] ?? ''));
    $observacion = trim((string) ($input['observacion'] ?? ''));
    $notifyWhatsapp = !isset($input['notificar_whatsapp']) || !empty($input['notificar_whatsapp']);
    $notifyEmail = !isset($input['notificar_correo']) || !empty($input['notificar_correo']);

    try {
      $reassignData = $this->tickets->reassign($ticketPk, $newEmployeeId, $this->commercialCargos, $observacion);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }

    $ticket = $reassignData['ticket'];
    $target = $reassignData['target'];
    $previousName = $reassignData['previous_name'];
    $previousEmail = $reassignData['previous_email'];

    $userName = \SCM\Core\Auth::user();
    if ($userName === '') {
      $userId = \SCM\Core\Auth::userId();
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }

    $newEmpNombre = (string) ($target['nombre'] ?? '');
    $newEmpCorreo = (string) ($target['correo'] ?? '');
    $newEmpCelular = $notifyWhatsapp ? (string) ($target['celular'] ?? '') : '';
    $newEmpId = (string) ($target['id'] ?? $newEmployeeId);

    $this->workflow->notifyTrasladoCaso(
      $ticket,
      $newEmpNombre,
      $newEmpCorreo,
      $previousName,
      $previousEmail,
      $userName,
      [],
      false,
      $notifyEmail,
      $newEmpCelular,
      $newEmpId
    );

    $hasWhatsapp = trim($newEmpCelular) !== '';
    $message = 'Caso reasignado a ' . ($newEmpNombre ?: 'nuevo responsable') . '.';
    if ($hasWhatsapp) {
      $message .= ' Notificación por WhatsApp encolada.';
    }

    JsonResponse::success([
      'message' => $message,
      'refresh' => true,
    ]);
  }

  /** @param array<string,mixed> $input */
  public function savePermissions(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canManage()) {
      JsonResponse::error('No tienes permiso para cambiar esta configuración.', 403);
    }
    $permissions = $input['permissions'] ?? [];
    $adminCargos = $input['admin_cargos'] ?? [];
    $employeeCargoIds = $input['employee_cargo_ids'] ?? [];
    $visibleCargoIds = $this->sanitizeCargoIds(is_array($employeeCargoIds) ? $employeeCargoIds : []);
    if ($visibleCargoIds === []) {
      JsonResponse::error('Selecciona al menos un cargo operativo visible.', 422);
    }
    try {
      $this->policy->save(is_array($permissions) ? $permissions : [], is_array($adminCargos) ? $adminCargos : []);
      $this->settings->set('commercial_employee_cargos', $visibleCargoIds, Auth::userId());
      $this->settings->refresh();
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'Permisos y cargos visibles guardados.']);
  }

  /** @param array<string,mixed> $input */
  public function propertyUpdates(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('actualizaciones') && !$this->policy->canView('inicio')) {
      JsonResponse::error('No tienes permiso para consultar actualizaciones de inmuebles.', 403);
    }
    $filters = $this->propertyControlFilters($input);
    if (!$this->policy->canSeeAllCommercialTickets()) {
      $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    }
    $result = $this->tickets->propertyUpdateControl($filters, $this->policy->canSeeAllCommercialTickets());
    JsonResponse::success([
      'html' => CommercialDashboardView::renderPropertyUpdateRows($result['rows'], $this->policy->canSeeAllCommercialTickets()),
      'total' => (int) ($result['total'] ?? 0),
      'stats' => $result['stats'] ?? [],
    ]);
  }

  /** @param array<string,mixed> $input */
  public function signsControl(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('avisos') && !$this->policy->canView('inicio')) {
      JsonResponse::error('No tienes permiso para consultar avisos.', 403);
    }
    $filters = $this->propertyControlFilters($input);
    if (!$this->policy->canSeeAllCommercialTickets()) {
      $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    }
    $mode = sanitize_key((string) ($input['mode'] ?? 'maintenance'));
    $result = $this->tickets->signControl($mode, $filters, $this->policy->canSeeAllCommercialTickets());
    JsonResponse::success([
      'html' => CommercialDashboardView::renderSignControlRows($result['rows'], (string) ($result['mode'] ?? $mode)),
      'total' => (int) ($result['total'] ?? 0),
      'mode' => (string) ($result['mode'] ?? $mode),
    ]);
  }

  /** @param array<string,mixed> $input */
  public function reply(array $input): never
  {
    $this->verify($input);
    $this->authorize('responder', 'No tienes permiso para responder tareas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['respuesta'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('La respuesta no puede estar vacía.', 422);
    }
    $result = $this->workflow->saveTicketResponse(
      $ticketPk,
      $message,
      '__keep__',
      false,
      $this->notifyTargets($input),
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->ensureWorkflowSucceeded($result);

    $newStatus = trim((string) ($input['estado'] ?? ''));
    if ($newStatus !== '' && $newStatus !== '__keep__') {
      try {
        $this->tickets->changeStatus($ticketPk, $newStatus);
      } catch (\InvalidArgumentException $exception) {
        // Status validation error ignored if response succeeded
      }
    }

    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Respuesta guardada.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function addNote(array $input): never
  {
    $this->verify($input);
    $this->authorize('agregar_nota', 'No tienes permiso para agregar notas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('La nota no puede estar vacía.', 422);
    }
    $this->workflowResponse(
      $this->workflow->saveNote($ticketPk, $message),
      'Nota guardada.'
    );
  }

  /** @param array<string,mixed> $input */
  public function followUp(array $input): never
  {
    $this->verify($input);
    $this->authorize('seguimiento', 'No tienes permiso para registrar seguimientos.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('El seguimiento no puede estar vacío.', 422);
    }
    $this->workflowResponse(
      $this->workflow->save(
        $ticketPk,
        $message,
        '__keep__',
        '__keep__',
        '__keep__',
        false,
        $this->notifyTargets($input),
        $this->uploadedImages('evidencia'),
        $this->uploadedDocuments()
      ),
      'Seguimiento guardado.'
    );
  }

  /** @param array<string,mixed> $input */
  public function postpone(array $input): never
  {
    $this->verify($input);
    $this->authorize('postergar', 'No tienes permiso para postergar tareas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('El motivo de postergación es obligatorio.', 422);
    }
    $result = $this->workflow->postponeTicket(
      $ticketPk,
      $message,
      $this->notifyTargets($input),
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, 'Postergado');
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Tarea postergada.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function activate(array $input): never
  {
    $this->verify($input);
    $this->authorize('activar', 'No tienes permiso para activar tareas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['motivo'] ?? ''))));
    $status = trim((string) ($input['estado'] ?? 'Nuevo'));
    if ($message === '' || !in_array($status, CommercialStatusCatalog::OPEN, true)) {
      JsonResponse::error('Selecciona un estado activo e indica el motivo.', 422);
    }
    $result = $this->workflow->activateTicket(
      $ticketPk,
      $message,
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, $status);
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Tarea activada.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function close(array $input): never
  {
    $this->verify($input);
    $this->authorize('cerrar', 'No tienes permiso para cerrar tareas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    $status = trim((string) ($input['estado'] ?? 'Finalizado'));
    if ($message === '' || !in_array($status, CommercialStatusCatalog::CLOSED, true)) {
      JsonResponse::error('Selecciona un estado de cierre e indica el motivo.', 422);
    }
    $result = $this->workflow->closeTicket($ticketPk, $message);
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, $status);
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Tarea cerrada.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  private function verify(array $input): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
  }

  private function authorize(string $action, string $message): void
  {
    if (!$this->policy->canAct($action)) {
      JsonResponse::error($message, 403);
    }
  }

  /** @param array<string,mixed> $filters @return array<string,mixed> */
  private function scopeTicketFilters(array $filters): array
  {
    if ($this->policy->canSeeAllCommercialTickets() && (string) ($filters['tab'] ?? '') !== 'mis_tickets') {
      return $filters;
    }

    $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    return $filters;
  }

  private function authorizeTicketScope(int $ticketPk): void
  {
    if ($this->policy->canSeeAllCommercialTickets()) {
      return;
    }

    $employeeFilter = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    if (!$this->tickets->ticketMatchesEmployeeFilter($ticketPk, $employeeFilter)) {
      JsonResponse::error('Esta tarea no está asignada a tu usuario.', 403);
    }
  }

  /** @param array<string,mixed> $input @return array<int,string> */
  private function notifyTargets(array $input): array
  {
    return !empty($input['notificar_solicitante']) ? ['solicitante'] : [];
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

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function propertyControlFilters(array $input): array
  {
    $clean = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    return [
      'codigo' => $clean('codigo'),
      'gestion' => $clean('gestion'),
      'tipo' => $clean('tipo'),
      'barrio' => $clean('barrio'),
      'ruta' => $clean('ruta'),
      'id_empleado' => $clean('id_empleado'),
      'estado_actualizacion' => $clean('estado_actualizacion'),
      'estado_aviso' => $clean('estado_aviso'),
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
    return $this->sanitizeCargoIds($source);
  }

  /** @param array<int|string,mixed> $ids @return array<int,string> */
  private function sanitizeCargoIds(array $ids): array
  {
    return array_values(array_unique(array_filter(array_map(
      static fn($id): string => trim((string) $id),
      $ids
    ), static fn(string $id): bool => $id !== '')));
  }

  /** @return array<int,string> */
  private function uploadedImages(string $fieldName): array
  {
    return StoredFileService::fromRuntime()->storeImages($fieldName, 10);
  }

  /** @return array<int,array{nombre_archivo:string,archivo:string}> */
  private function uploadedDocuments(): array
  {
    $titles = $_POST['documento_nombre'] ?? [];
    if (!is_array($titles)) {
      $titles = [];
    }

    return StoredFileService::fromRuntime()->storeDocuments('documento', array_values(array_map('strval', $titles)), 10);
  }

  /** @param array<string,string> $result */
  private function ensureWorkflowSucceeded(array $result): void
  {
    if (($result['ok'] ?? '0') !== '1') {
      JsonResponse::error((string) ($result['message'] ?? 'No se pudo completar la acción.'), 422);
    }
  }

  /** @param array<string,string> $result */
  private function workflowResponse(array $result, string $fallback): never
  {
    $this->ensureWorkflowSucceeded($result);
    JsonResponse::success(['message' => (string) ($result['message'] ?? $fallback), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function propertyDetail(array $input): never
  {
    $this->verify($input);
    $identifier = trim((string) ($input['codigo'] ?? $input['property_id'] ?? ''));
    if ($identifier === '') {
      JsonResponse::error('Identificador de inmueble inválido.', 400);
    }
    $repo = new CommercialPropertiesRepository($this->db);
    $property = $repo->propertyDetail($identifier);
    if (!is_array($property) || $property === []) {
      JsonResponse::error('Inmueble no encontrado.', 404);
    }
    $html = CommercialDashboardView::renderPropertyDetailModalContent($property);
    JsonResponse::success([
      'property' => $property,
      'html' => $html,
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
    if (!in_array($subtab, ['publicos', 'pendientes', 'no_publicos', 'mis_inmuebles'], true)) {
      $subtab = $isNonAdmin ? 'mis_inmuebles' : 'publicos';
    }

    return [
      'property_subtab' => $subtab,
      'busqueda' => $clean('busqueda'),
      'tipo_inmueble' => $clean('tipo_inmueble'),
      'tipo_negocio' => $clean('tipo_negocio'),
      'ciudad' => $clean('ciudad'),
      'barrio' => $clean('barrio'),
      'estado' => $clean('estado'),
      'id_funcionario' => $clean('id_funcionario'),
      'page' => max(1, (int) ($input['page'] ?? 1)),
      'per_page' => 24,
    ];
  }
}
