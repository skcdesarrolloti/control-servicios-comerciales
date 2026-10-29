<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;
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
use SCM\Views\CommercialNotificationsView;
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
    $input = CommercialAccessPolicy::normalizeToolTab($input);
    $bucket = trim((string) ($input['tab'] ?? 'inicio'));
    try {
      $input = $this->policy->resolveNavigation($bucket, $input);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403, [
        'page_denied' => true, 'html' => CommercialDashboardView::renderAccessDenied($this->baseUrl),
      ]);
    }
    if (!array_key_exists($bucket, CommercialAccessPolicy::VIEWS) || !$this->policy->canView($bucket)) {
      JsonResponse::error('No tienes permiso para entrar a esta página.', 403, [
        'page_denied' => true, 'html' => CommercialDashboardView::renderAccessDenied($this->baseUrl),
      ]);
    }
    $filters = $this->ticketFilters($input);
    $filters['tab'] = $bucket;
    $filters = $this->scopeTicketFilters($filters);
    $ticketEmployees = $this->tickets->ticketEmployees($this->commercialCargos);
    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), fn(string $view): bool => $this->policy->canView($view)));
    $currentEmployeeFilter = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    $navigation = $this->tickets->scopedNavigation($bucket, $filters, $currentEmployeeFilter, $this->policy);
    $tabCounts = $navigation['tabs'];
    $html = '';
    if ($bucket === 'anadir_aviso') {
      $html = \SCM\Views\CommercialSignView::render(new \SCM\Commercial\CommercialSignService($this->db, $this->policy, $this->config), $this->policy);
    } elseif ($bucket === 'herramientas') {
      $tool = (string) ($input['herramienta'] ?? '');
      $html = \SCM\Views\CommercialToolsView::render($tool, $this->preapprovalService(), $this->baseUrl, \SCM\Views\CommercialToolsView::context($tool, $this->policy, $this->db));
    } elseif ($bucket === 'precaptacion') {
      $html = CommercialDashboardView::renderPrecaptacion($this->baseUrl, $input);
    } elseif ($bucket === 'inicio') {
      $result = $this->tickets->search('abiertos', $filters);
      $html = CommercialDashboardView::renderHome(
        $this->tickets->homeDashboard($navigation['home'], $this->policy),
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
      $subtab = trim((string) ($input['subtab'] ?? 'mine'));
      $calendar = $this->calendarService()->panelConfig($subtab);
      $html = CommercialDashboardView::renderCalendarPage(array_merge($this->config, $calendar['config']), $calendar['employees'], $subtab, $this->policy, $this->baseUrl);
    } elseif ($bucket === 'notificaciones') {
      $html = CommercialNotificationsView::render(new CommercialNotificationsService($this->db, $this->policy), $this->policy);
    } elseif ($bucket === 'inmuebles') {
      $propertiesRepo = new CommercialPropertiesRepository($this->db);
      $canSeeAll = $this->policy->canSeeAll('inmuebles');
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
    $topicHierarchy = $this->tickets->topicStatusHierarchy($navigation['topics']);
    JsonResponse::success([
      'html' => $html,
      'tabs_html' => CommercialDashboardView::renderTabs($visibleViews, $bucket, $filters, $tabCounts, $this->baseUrl, $topicHierarchy, $this->policy),
      'tab' => $bucket,
    ]);
  }

  /** @param array<string,mixed> $input */
  public function calendarDue(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canSubview('calendario', 'due')) {
      JsonResponse::error('No tienes permiso para consultar los vencimientos comerciales.', 403);
    }
    $from = trim((string) ($input['fecha_inicio'] ?? ''));
    $to = trim((string) ($input['fecha_fin'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      JsonResponse::error('El rango de fechas del calendario no es válido.', 422);
    }
    JsonResponse::success($this->calendarService()->dueCalendar($from, $to, trim((string) ($input['id_empleado'] ?? ''))));
  }

  /** @param array<string,mixed> $input */
  public function calendarCitaNotify(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('calendario')) {
      JsonResponse::error('No tienes permiso para usar el calendario comercial.', 403);
    }
    $appointments = json_decode((string) ($input['appointments'] ?? '[]'), true);
    try {
      $result = (new \SCM\Commercial\CommercialCalendarNotifier($this->db))->notify(is_array($appointments) ? $appointments : []);
    } catch (\Throwable $exception) {
      error_log('control-servicios-comerciales: error preparando WhatsApp de cita: ' . $exception->getMessage());
      $result = ['message' => 'Evento creado, pero no se pudo preparar WhatsApp.', 'queued' => 0, 'skipped' => 0, 'errors' => ['Error preparando WhatsApp: ' . $exception->getMessage()]];
    }
    JsonResponse::success($result);
  }

  /** @param array<string,mixed> $input */
  public function settingsLoad(array $input): never
  {
    $this->verify($input);
    try {
      JsonResponse::success(['settings' => $this->settingsService()->load()]);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  /** @param array<string,mixed> $input */
  public function settingsSave(array $input): never
  {
    $this->verify($input);
    $payload = json_decode((string) ($input['payload'] ?? '{}'), true);
    try {
      $message = $this->settingsService()->save(trim((string) ($input['section'] ?? '')), is_array($payload) ? $payload : []);
      JsonResponse::success(['message' => $message, 'settings' => $this->settingsService()->load()]);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  private function settingsService(): \SCM\Commercial\CommercialSettingsService
  {
    return new \SCM\Commercial\CommercialSettingsService($this->db, $this->settings, $this->policy, $this->config);
  }

  private function calendarService(): \SCM\Commercial\CommercialCalendarService
  {
    return new \SCM\Commercial\CommercialCalendarService($this->db, $this->settings, $this->policy, $this->config);
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
    $canSeeAll = $this->policy->canSeeAll('actualizaciones');
    if (!$canSeeAll) {
      $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    }
    $result = $this->tickets->propertyUpdateControl($filters, $canSeeAll);
    JsonResponse::success([
      'html' => CommercialDashboardView::renderPropertyUpdateRows($result['rows'], $canSeeAll),
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
    $canSeeAll = $this->policy->canSeeAll('avisos');
    if (!$canSeeAll) {
      $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    }
    $mode = sanitize_key((string) ($input['mode'] ?? 'maintenance'));
    $result = $this->tickets->signControl($mode, $filters, $canSeeAll);
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
  private function notificationService(array $input, bool $sending = false): CommercialNotificationsService
  {
    $this->verify($input);
    if (!$this->policy->canView('notificaciones') || ($sending && !$this->policy->canAct('enviar_notificacion'))) {
      JsonResponse::error('No tienes permiso para usar esta función de Notificaciones.', 403);
    }
    return new CommercialNotificationsService($this->db, $this->policy);
  }

  public function notificationRecipients(array $input): never
  {
    $service = $this->notificationService($input);
    try {
      $result = $service->search(
        (string) ($input['type'] ?? 'propietarios_activos'), mb_substr(trim((string) ($input['q'] ?? '')), 0, 150),
        max(1, (int) ($input['page'] ?? 1)), 20, (string) ($input['contract_status'] ?? ''),
        mb_substr(trim((string) ($input['inmueble_simi'] ?? '')), 0, 50), mb_substr(trim((string) ($input['contract_number'] ?? '')), 0, 50)
      );
      JsonResponse::success($result);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function notificationQueue(array $input): never
  {
    $service = $this->notificationService($input);
    JsonResponse::success($service->notificationQueue($input));
  }

  public function notificationDetail(array $input): never
  {
    $service = $this->notificationService($input);
    $row = $service->notificationDetail((int) ($input['id'] ?? 0));
    if ($row === null) {
      JsonResponse::error('La notificación no está disponible.', 404);
    }
    JsonResponse::success($row);
  }

  public function deleteNotification(array $input): never
  {
    $service = $this->notificationService($input);
    $this->authorize('eliminar_notificacion', 'Solo los administradores pueden eliminar notificaciones.');
    try {
      $service->deleteNotification((int) ($input['id'] ?? 0));
      JsonResponse::success(['message' => 'Notificación eliminada de la cola. Se conserva en el informe de auditoría.']);
    } catch (\RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 409);
    }
  }

  public function notificationReport(array $input): never
  {
    $service = $this->notificationService($input);
    try {
      JsonResponse::success($service->notificationReport($input));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  private function actorEditor(array $input): \SCM\Commercial\CommercialActorEditor
  {
    $this->notificationService($input);
    $this->authorize('editar_actor', 'No tienes permiso para editar datos de actores.');
    return new \SCM\Commercial\CommercialActorEditor($this->db, $this->policy);
  }

  public function actorDetail(array $input): never
  {
    $editor = $this->actorEditor($input);
    try { JsonResponse::success($editor->detail((string) ($input['type'] ?? ''), (int) ($input['id'] ?? 0))); }
    catch (\RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 404);
    }
  }

  public function actorPreview(array $input): never
  {
    $editor = $this->actorEditor($input);
    try { JsonResponse::success($editor->preview((string) ($input['type'] ?? ''), (int) ($input['id'] ?? 0), $input)); }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function actorSave(array $input): never
  {
    $editor = $this->actorEditor($input);
    try { JsonResponse::success($editor->save((string) ($input['token'] ?? ''), (array) ($input['targets'] ?? []))); }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 409);
    }
  }

  public function sendNotifications(array $input): never
  {
    $service = $this->notificationService($input, true);
    try {
      $type = (string) ($input['type'] ?? 'propietarios_activos');
      $ids = (array) ($input['ids'] ?? []);
      if ((string) ($input['all_filtered'] ?? '') === '1') {
        $ids = $service->idsForFilter($type, (string) ($input['q'] ?? ''), 501, (string) ($input['contract_status'] ?? ''), (string) ($input['inmueble_simi'] ?? ''), (string) ($input['contract_number'] ?? ''));
        if (count($ids) > 500) {
          throw new \InvalidArgumentException('Ajusta los filtros a un máximo de 500 contactos.');
        }
        $ids = array_values(array_diff($ids, array_map('intval', (array) ($input['exclude_ids'] ?? []))));
      }
      if (count($ids) > 500) {
        throw new \InvalidArgumentException('Selecciona hasta 500 destinatarios por envío. Ajusta los filtros.');
      }
      $media = [];
      $file = (array) ($_FILES['media'] ?? []);
      if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $media = StoredFileService::fromRuntime()->storeNotificationMedia($file, (string) ($input['media_type'] ?? ''));
      }
      $service->prepareDelivery((string) ($input['request_id'] ?? ''), $media);
      $result = $service->enqueue($type, $ids, (array) ($input['channels'] ?? []), trim((string) ($input['subject'] ?? '')), trim((string) ($input['message'] ?? '')), (string) ($input['whatsapp_template'] ?? ''));
      JsonResponse::success($result + ['message' => sprintf('%d notificaciones en cola; %d sin contacto válido, %d bloqueadas por preferencias y %d con error.', $result['queued'], $result['invalid'], $result['filtered'], $result['failed'])]);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      if ($exception instanceof \PDOException) { throw $exception; }
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function signLookup(array $input): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialSignService($this->db, $this->policy, $this->config);
      JsonResponse::success($service->lookup((int) ($input['id_ticket'] ?? 0), trim((string) ($input['id_inmueble'] ?? ''))));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function signSave(array $input): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialSignService($this->db, $this->policy, $this->config);
      $service->authorize();
      JsonResponse::success($service->save($input, static fn(): array => StoredFileService::fromRuntime()->storeRequiredImages('fotos', 3)));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\PDOException $exception) {
      throw $exception;
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function taskSignForm(array $input): never
  {
    $this->verify($input);
    $this->authorize('ver_ticket', 'No tienes permiso para ver esta tarea.');
    $taskId = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($taskId);
    try {
      $service = new \SCM\Commercial\CommercialSignService($this->db, $this->policy, $this->config);
      JsonResponse::success(['html'=>'<div class="commercial-entry-shell">' . \SCM\Views\CommercialSignView::render($service, $this->policy, $service->taskContext($taskId)) . '</div>']);
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  private function preapprovalService(): \SCM\Commercial\CommercialOwnerPreapprovalService
  {
    return new \SCM\Commercial\CommercialOwnerPreapprovalService($this->db, $this->policy);
  }

  public function preapprovalLookup(array $input): never
  {
    $this->verify($input);
    try {
      JsonResponse::success($this->preapprovalService()->lookup(trim((string) ($input['document'] ?? ''))));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  /** PDF de la simulación de ingreso neto y crédito preaprobado para el propietario, con el diseño de las actas (Chromium). */
  public function preapprovalPdf(array $input): never
  {
    $this->verify($input);
    try {
      $payload = json_decode(is_string($input['payload'] ?? null) ? $input['payload'] : '', true);
      $service = $this->preapprovalService();
      $estimate = $service->estimate(is_array($payload) ? $payload : []);
      $bytes = \SCM\Commercial\CommercialOwnerPreapprovalPdf::bytes($estimate, $service->signer());
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      error_log('[commercial-preapproval-pdf] ' . $exception->getMessage());
      JsonResponse::error($exception->getMessage(), 503);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . \SCM\Commercial\CommercialOwnerPreapprovalPdf::filename($estimate) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
  }

  private function authorizeTool(string $tool, string $message): void
  {
    if (!$this->policy->canSubview('herramientas', $tool)) {
      JsonResponse::error($message, 403);
    }
  }

  /** Calculadora de mercado: rutas, barrios, tipos, destinaciones, estratos y estados reales de la tabla de inmuebles. */
  public function marketOptions(array $input): never
  {
    $this->verify($input);
    $this->authorizeTool('calculadora_mercado', 'No tienes permiso para usar la calculadora de mercado.');
    try {
      JsonResponse::success(['options' => (new \SCM\Commercial\Tools\MarketStatsService($this->db))->options(), 'admin' => $this->policy->canAct('mercado_vista_completa')]);
    } catch (\Throwable $exception) {
      error_log('[commercial-tools-market] ' . $exception->getMessage());
      JsonResponse::error('No se pudieron cargar los filtros de la calculadora de mercado.', 500);
    }
  }

  /** Valores por m²; el listado de inmuebles (códigos y barrios) solo viaja con la vista completa. */
  public function marketAnalyze(array $input): never
  {
    $this->verify($input);
    $this->authorizeTool('calculadora_mercado', 'No tienes permiso para usar la calculadora de mercado.');
    $filters = ['estados' => array_values(array_filter(array_map(static fn($value): string => trim((string) $value), is_array($input['estados'] ?? null) ? $input['estados'] : []), static fn(string $value): bool => $value !== ''))];
    foreach (['ruta', 'barrio', 'estrato', 'tipo_inmueble', 'categoria', 'destinacion', 'habitaciones', 'edad', 'parqueadero', 'deposito', 'vigilancia'] as $key) {
      $filters[$key] = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }
    if ($filters['estados'] === []) {
      JsonResponse::error('Selecciona al menos un estado del inmueble.', 422);
    }
    $result = ['venta' => [], 'arriendo' => [], 'items' => [], 'without_area' => 0];
    try {
      $result = (new \SCM\Commercial\Tools\MarketStatsService($this->db))->analyze($filters);
    } catch (\Throwable $exception) {
      error_log('[commercial-tools-market] ' . $exception->getMessage());
      JsonResponse::error('No se pudo calcular la estadística de mercado.', 500);
    }
    $admin = $this->policy->canAct('mercado_vista_completa');
    $round = static fn(array $values): array => array_map(static fn(float $value): float => round($value, 2), $values);
    JsonResponse::success([
      'admin' => $admin,
      'venta' => $round($result['venta']),
      'arriendo' => $round($result['arriendo']),
      'without_area' => $result['without_area'],
      'items' => $admin ? array_map(static fn(array $item): array => ['precio' => round($item['precio'], 2), 'area' => round($item['area'], 2), 'm2' => round($item['m2'], 2)] + $item, $result['items']) : [],
    ]);
  }

  /** @return array<string,string> */
  private function cleaningInput(array $input): array
  {
    $clean = [];
    foreach (['m2', 'jornada', 'distancia', 'dificultad', 'trabajador', 'cliente', 'direccion'] as $key) {
      $clean[$key] = is_scalar($input[$key] ?? null) ? trim(strip_tags((string) $input[$key])) : '';
    }
    return $clean;
  }

  /** Calculadora de aseo: costos, márgenes y utilidades solo viajan con la vista interna. */
  public function cleaningCalculate(array $input): never
  {
    $this->verify($input);
    $this->authorizeTool('calculadora_aseo', 'No tienes permiso para usar la calculadora de aseo.');
    $result = [];
    try {
      $result = \SCM\Commercial\Tools\CleaningCalculator::calculate((new \SCM\Commercial\Tools\CleaningSettings($this->db))->read(), $this->cleaningInput($input));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log('[commercial-tools-cleaning] ' . $exception->getMessage());
      JsonResponse::error('No se pudo calcular el servicio de aseo.', 500);
    }
    $internal = $this->policy->canAct('aseo_vista_interna');
    $public = ['input', 'horas', 'protocolo', 'base', 'iva', 'total', 'cliente'];
    JsonResponse::success(['internal' => $internal, 'result' => $internal ? $result : array_intersect_key($result, array_flip($public))]);
  }

  /** PDF de aseo para el cliente o, con la vista interna, para la inmobiliaria y el funcionario. */
  public function cleaningPdf(array $input): never
  {
    $this->verify($input);
    $this->authorizeTool('calculadora_aseo', 'No tienes permiso para usar la calculadora de aseo.');
    $mode = is_scalar($input['mode'] ?? null) ? trim((string) $input['mode']) : 'cliente';
    if (!isset(\SCM\Commercial\Tools\CleaningQuotePdf::MODES[$mode])) {
      JsonResponse::error('Tipo de PDF no válido.', 422);
    }
    if (in_array($mode, \SCM\Commercial\Tools\CleaningQuotePdf::INTERNAL_MODES, true) && !$this->policy->canAct('aseo_vista_interna')) {
      JsonResponse::error('Tu cargo no tiene permiso para los reportes internos de aseo.', 403);
    }
    $bytes = '';
    try {
      $config = (new \SCM\Commercial\Tools\CleaningSettings($this->db))->read();
      $data = $this->cleaningInput($input);
      $result = \SCM\Commercial\Tools\CleaningCalculator::calculate($config, $data);
      $bytes = \SCM\Commercial\Tools\CleaningQuotePdf::bytes($mode, $result, $config, ['cliente' => $data['cliente'], 'direccion' => $data['direccion']], $this->preapprovalService()->signer());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      error_log('[commercial-tools-cleaning-pdf] ' . $exception->getMessage());
      JsonResponse::error($exception->getMessage(), 503);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . \SCM\Commercial\Tools\CleaningQuotePdf::filename($mode) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
  }

  public function propertyEntryForm(array $input): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialPropertyEntryService($this->db, $this->policy, $this->config);
      JsonResponse::success(['html'=>\SCM\Views\CommercialPropertyEntryView::render($service, (int) ($input['ticket_pk'] ?? 0))]);
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function propertyEntrySave(array $input): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialPropertyEntryService($this->db, $this->policy, $this->config);
      $document = $_FILES['uso_suelo'] ?? null;
      $store = is_array($document) && (int) ($document['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ? static function () use ($document): array {
          $mime = is_file((string) ($document['tmp_name'] ?? '')) ? (new \finfo(FILEINFO_MIME_TYPE))->file($document['tmp_name']) : '';
          if ($mime !== 'application/pdf' || (int) ($document['size'] ?? 0) > 6 * 1024 * 1024) throw new \InvalidArgumentException('El uso del suelo debe ser un PDF de hasta 6 MB.');
          return StoredFileService::fromRuntime()->storeNotificationMedia($document, 'document');
        } : null;
      JsonResponse::success($service->save((int) ($input['ticket_pk'] ?? 0), $input, $store));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\PDOException $exception) {
      throw $exception;
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

  public function propertyNeighborhood(array $input): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialPropertyEntryService($this->db, $this->policy, $this->config);
      JsonResponse::success($service->neighborhoodSuggestions((int) ($input['ticket_pk'] ?? 0), $input));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) { JsonResponse::error($exception->getMessage(), 403); }
    catch (\InvalidArgumentException $exception) { JsonResponse::error($exception->getMessage(), 422); }
  }

  public function propertyMediaForm(array $input): never { $this->propertyMediaRequest($input, 'form'); }
  public function propertyMediaDraft(array $input): never { $this->propertyMediaRequest($input, 'draft'); }
  public function propertyMediaUpload(array $input): never { $this->propertyMediaRequest($input, 'upload'); }
  public function propertyMediaSave(array $input): never { $this->propertyMediaRequest($input, 'save'); }
  public function imageCleanupStatus(array $input): never { $this->imageCleanupRequest($input, false); }
  public function imageCleanup(array $input): never { $this->imageCleanupRequest($input, true); }
  public function propertyVideoConfig(array $input): never { $this->propertyVideoRequest($input, 'config'); }
  public function propertyVideoCreate(array $input): never { $this->propertyVideoRequest($input, 'create'); }
  public function propertyVideoStatus(array $input): never { $this->propertyVideoRequest($input, 'status'); }
  public function propertyVideoCancel(array $input): never { $this->propertyVideoRequest($input, 'cancel'); }
  public function propertyVideoDownload(array $input): never { $this->propertyVideoRequest($input, 'download'); }

  private function propertyVideoRequest(array $input, string $operation): never
  {
    $this->verify($input);
    try {
      $task = (int) ($input['ticket_pk'] ?? 0);
      (new \SCM\Commercial\CommercialPropertyEntryService($this->db,$this->policy,$this->config))->task($task);
      $service = new \SCM\Commercial\CommercialPropertyVideoService($this->config);
      if ($operation === 'config') JsonResponse::success(['available'=>$service->available()]);
      if ($operation === 'create') {
        $manifest = json_decode(is_string($input['manifest'] ?? null) ? $input['manifest'] : '',true);
        if (!is_array($manifest) || !is_array($manifest['sources'] ?? null) || count($manifest['sources']) < 2 || count($manifest['sources']) > 12) throw new \InvalidArgumentException('Selecciona entre 2 y 12 fotos para el video.');
        $ids = []; $photos = [];
        foreach ($manifest['sources'] as $source) {
          if (!is_array($source) || !is_int($source['attachment'] ?? $source['upload'] ?? null)) throw new \InvalidArgumentException('Fotografía seleccionada inválida.');
          if (isset($source['attachment'])) $ids[] = $source['attachment'];
        }
        $saved = $ids ? (new \SCM\Commercial\CommercialPropertyMediaService($this->db,$this->policy,$this->config))->videoPhotoPaths($task,(int) ($input['id_inmueble'] ?? 0),$ids) : [];
        foreach ($manifest['sources'] as $source) {
          if (isset($source['attachment'])) { $photos[] = $saved[$source['attachment']]; continue; }
          $index = $source['upload']; $file = $_FILES['photos'] ?? [];
          $path = $file['tmp_name'][$index] ?? '';
          if (($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) throw new \InvalidArgumentException('No se recibió una de las fotos. Reduce su tamaño e intenta de nuevo.');
          $photos[] = $path;
        }
        $audio = $_FILES['audio'] ?? null;
        if ($audio && !is_uploaded_file((string) ($audio['tmp_name'] ?? ''))) throw new \InvalidArgumentException('No se recibió el archivo de música.');
        JsonResponse::success($service->create($task,$manifest,$photos,$audio));
      }
      if ($operation === 'download') {
        $path = $service->download($task,$input);
        try {
          while (ob_get_level() > 0) ob_end_clean();
          header('Content-Type: video/mp4'); header('Content-Disposition: attachment; filename="inmueble-' . $task . '.mp4"');
          header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($path));
          readfile($path);
        } finally { @unlink($path); }
        exit;
      }
      JsonResponse::success($service->status($task,$input,$operation === 'cancel'));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) { JsonResponse::error($exception->getMessage(),403); }
    catch (\PDOException $exception) { throw $exception; }
    catch (\InvalidArgumentException | \RuntimeException $exception) { JsonResponse::error($exception->getMessage(),422); }
  }

  private function imageCleanupRequest(array $input, bool $process): never
  {
    $this->verify($input);
    try {
      (new \SCM\Commercial\CommercialPropertyEntryService($this->db,$this->policy,$this->config))->task((int) ($input['ticket_pk'] ?? 0));
      $service = new \SCM\Commercial\CommercialImageCleanupService($this->config);
      if (!$process) JsonResponse::success(['available'=>$service->available()]);
      foreach (['image_file','mask_file'] as $field) if ((int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($_FILES[$field]['tmp_name'] ?? ''))) throw new \InvalidArgumentException('Selecciona la fotografía y pinta la zona que deseas eliminar.');
      JsonResponse::success($service->cleanup($_FILES['image_file']['tmp_name'],$_FILES['mask_file']['tmp_name']));
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) { JsonResponse::error($exception->getMessage(),403); }
    catch (\PDOException $exception) { throw $exception; }
    catch (\InvalidArgumentException | \RuntimeException $exception) { JsonResponse::error($exception->getMessage(),422); }
  }

  private function propertyMediaRequest(array $input, string $operation): never
  {
    $this->verify($input);
    try {
      $service = new \SCM\Commercial\CommercialPropertyMediaService($this->db, $this->policy, $this->config);
      $task = (int) ($input['ticket_pk'] ?? 0); $code = (int) ($input['id_inmueble'] ?? 0);
      $result = match ($operation) {
        'form' => ['html'=>\SCM\Views\CommercialPropertyMediaView::render($service, $task, $code)],
        'draft' => $service->draftContext($task, $code),
        'upload' => $service->upload($task, $code, (array) ($_FILES['file'] ?? [])),
        default => $service->save($task, $code, $input),
      };
      JsonResponse::success($result);
    } catch (\SCM\Commercial\CommercialSignAccessDenied $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    } catch (\PDOException $exception) {
      throw $exception;
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
  }

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
    if ($this->policy->canSeeAll((string) ($filters['tab'] ?? ''))) {
      return $filters;
    }

    $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    return $filters;
  }

  private function authorizeTicketScope(int $ticketPk): void
  {
    if ($this->policy->canSeeAllTasks()) {
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
    // La tabla de control de la calculadora de mercado también abre la ficha del inmueble.
    if (!$this->policy->canView('inmuebles') && !($this->policy->canSubview('herramientas', 'calculadora_mercado') && $this->policy->canAct('mercado_vista_completa'))) {
      JsonResponse::error('No tienes permiso para consultar inmuebles.', 403);
    }
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

  /** @param array<string,mixed> $input */
  public function requestHighlight(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('inmuebles')) {
      JsonResponse::error('No tienes permiso para gestionar inmuebles.', 403);
    }
    $code = trim((string) ($input['codigo'] ?? ''));
    $portal = trim((string) ($input['portal'] ?? ''));
    $reason = trim((string) ($input['razon'] ?? $input['observacion_destacado'] ?? ''));
    $oportunidad = trim((string) ($input['oportunidad'] ?? 'No'));
    $negociable = trim((string) ($input['negociable'] ?? 'No'));

    $user = Auth::user();
    $employeeId = trim((string) ($user['id_empleado'] ?? ''));
    $employeeName = trim((string) ($user['nombre'] ?? 'Funcionario'));

    $repo = new CommercialPropertiesRepository($this->db);
    $res = $repo->requestHighlight($code, $portal, $reason, $oportunidad, $negociable, $employeeId, $employeeName);
    if (!($res['ok'] ?? false)) {
      JsonResponse::error($res['message'] ?? 'No se pudo procesar la solicitud.', 422);
    }
    JsonResponse::ok($res);
  }

  /** @param array<string,mixed> $input */
  public function cancelHighlightRequest(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('inmuebles')) {
      JsonResponse::error('No tienes permiso para gestionar inmuebles.', 403);
    }
    $requestId = (int) ($input['request_id'] ?? 0);
    if ($requestId <= 0) {
      JsonResponse::error('ID de solicitud no válido.', 400);
    }

    $employeeId = trim(Auth::employeeId());
    $repo = new CommercialPropertiesRepository($this->db);
    $res = $repo->cancelHighlightRequest($requestId, $employeeId);
    if (!($res['ok'] ?? false)) {
      JsonResponse::error($res['message'] ?? 'No se pudo cancelar la solicitud.', 422);
    }
    JsonResponse::ok($res);
  }

  /** @param array<string,mixed> $input */
  public function completeHighlight(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canManage()) {
      JsonResponse::error('No tienes permisos administrativos para completar solicitudes de destacados.', 403);
    }
    $requestId = (int) ($input['request_id'] ?? 0);
    if ($requestId <= 0) {
      JsonResponse::error('ID de solicitud inválido.', 400);
    }

    $user = Auth::user();
    $employeeId = trim((string) ($user['id_empleado'] ?? ''));
    $employeeName = trim((string) ($user['nombre'] ?? 'Administrador'));

    $repo = new CommercialPropertiesRepository($this->db);
    $res = $repo->completeHighlightRequest($requestId, $employeeId, $employeeName);
    if (!($res['ok'] ?? false)) {
      JsonResponse::error($res['message'] ?? 'No se pudo completar la solicitud.', 422);
    }
    JsonResponse::ok($res);
  }

  /** @param array<string,mixed> $input */
  public function releaseHighlight(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('inmuebles')) {
      JsonResponse::error('No tienes permiso para gestionar inmuebles.', 403);
    }
    $code = trim((string) ($input['codigo'] ?? ''));
    $portal = trim((string) ($input['portal'] ?? ''));

    $user = Auth::user();
    $employeeId = trim((string) ($user['id_empleado'] ?? ''));
    $employeeName = trim((string) ($user['nombre'] ?? 'Funcionario'));

    $repo = new CommercialPropertiesRepository($this->db);
    $res = $repo->releaseHighlight($code, $portal, $employeeId, $employeeName);
    if (!($res['ok'] ?? false)) {
      JsonResponse::error($res['message'] ?? 'No se pudo liberar el cupo.', 422);
    }
    JsonResponse::ok($res);
  }

  /** @param array<string,mixed> $input */
  public function togglePremium(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canManage()) {
      JsonResponse::error('Solo administradores pueden modificar el estado Premium.', 403);
    }
    $code = trim((string) ($input['codigo'] ?? ''));
    $value = trim((string) ($input['value'] ?? 'No'));

    $user = Auth::user();
    $employeeId = trim((string) ($user['id_empleado'] ?? ''));
    $employeeName = trim((string) ($user['nombre'] ?? 'Administrador'));

    $repo = new CommercialPropertiesRepository($this->db);
    $res = $repo->togglePremium($code, $value, $employeeId, $employeeName);
    if (!($res['ok'] ?? false)) {
      JsonResponse::error($res['message'] ?? 'No se pudo actualizar Premium.', 422);
    }
    JsonResponse::ok($res);
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
}
