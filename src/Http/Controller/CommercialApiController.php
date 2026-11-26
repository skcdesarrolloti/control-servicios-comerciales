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
  /**
   * El flujo heredado del panel inmobiliario (SeguimientoService) manda sus propios correos con la página de tickets
   * anterior, y con la lista vacía avisa a todos. En el panel comercial no manda ninguno: el cliente recibe solo los
   * avisos de CommercialCallsNotifier, con la vista pública de su tarea.
   */
  private const NO_LEGACY_EMAILS = ['none'];

  private Database $db;
  private CommercialTicketsRepository $tickets;
  private CommercialTaskAnalysisRepository $analyses;
  private CommercialAccessPolicy $policy;
  private Settings $settings;
  private Csrf $csrf;
  private SeguimientoService $workflow;
  private string $baseUrl;
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
    $tabCounts += $this->policy->canView('inmuebles') ? (new CommercialPropertiesRepository($this->db))->menuCounts($this->policy->canSeeAll('inmuebles')) : [];
    $html = '';
    if ($bucket === 'anadir_aviso') {
      $html = \SCM\Views\CommercialSignView::render(new \SCM\Commercial\CommercialSignService($this->db, $this->policy, $this->config), $this->policy);
    } elseif ($bucket === 'herramientas') {
      $tool = (string) ($input['herramienta'] ?? '');
      $preapproval = $this->preapprovalService();
      if (!in_array($tool, ['ganancia_ocasional', 'calculadora_mercado', 'calculadora_aseo', 'generador_utm', 'plan_estrategico'], true) && $this->policy->canSubview('herramientas', 'simulador_ingreso_neto')) $preapproval->syncUsury();
      $html = \SCM\Views\CommercialToolsView::render($tool, $preapproval,$this->baseUrl, \SCM\Views\CommercialToolsView::context($tool, $this->policy, $this->db, $this->settings));
    } elseif ($bucket === 'reportes_comerciales') {
      $html = CommercialDashboardView::renderReportsPage((string) ($input['reporte_sub'] ?? ''), $this->policy);
    } elseif ($bucket === 'promocion') {
      $html = \SCM\Views\Promotion\PromotionView::page($input, $this->policy, $this->db, $this->settings, $this->baseUrl);
    } elseif ($bucket === 'llamadas') {
      $html = \SCM\Views\CommercialCallsView::page($input, new \SCM\Commercial\Calls\CommercialCallsService($this->db, $this->settings, $this->policy, $this->config), $this->policy);
    } elseif ($bucket === 'cotizaciones') {
      $html = \SCM\Views\CommercialQuotesView::page($input, new \SCM\Commercial\Quotes\CommercialQuotesService(new \SCM\Commercial\Quotes\CommercialQuotesRepository($this->db), $this->settings, $this->policy), $this->policy);
    } elseif ($bucket === 'estudios') {
      $html = \SCM\Views\CommercialStudiesView::page($input, new \SCM\Commercial\Studies\CommercialStudiesService(new \SCM\Commercial\Studies\CommercialStudiesRepository($this->db), $this->settings, $this->policy), $this->policy);
    } elseif ($bucket === 'cierres') {
      $html = \SCM\Views\CommercialClosingSheetsView::page($input, new \SCM\Commercial\Mandates\ClosingSheetsListing($this->db, $this->policy));
    } elseif ($bucket === 'mandatos') {
      $html = \SCM\Views\CommercialMandatesView::page($input, new \SCM\Commercial\Mandates\CommercialMandatesService($this->db, $this->policy));
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
      $html = \SCM\Views\Campaigns\CampaignsView::page((string) ($input['notif_sub'] ?? ''), $input, $this->policy, $this->db, $this->settings, $this->baseUrl);
      if ($html === '') {
        $html = CommercialNotificationsView::render(new CommercialNotificationsService($this->db, $this->policy), $this->policy);
      }
    } elseif ($bucket === 'inmuebles') {
      $propertiesRepo = new CommercialPropertiesRepository($this->db);
      $canSeeAll = $this->policy->canSeeAll('inmuebles');
      $propFilters = $this->propertyFilters($input, !$canSeeAll);
      $propResult = $propertiesRepo->search($propFilters, $canSeeAll, (int) ($propFilters['page'] ?? 1), 24);
      $propOptions = $propertiesRepo->filterOptions();
      $html = CommercialDashboardView::renderPropertiesPage(
        $propResult,
        $propFilters,
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
      // section=medios agrega el detalle de la lista (uso, valores sin reconocer, historial).
      JsonResponse::success(['settings' => $this->settingsService()->load(trim((string) ($input['section'] ?? '')))]);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  /** @param array<string,mixed> $input */
  public function settingsSave(array $input): never
  {
    $this->verify($input);
    $payload = json_decode((string) ($input['payload'] ?? '{}'), true);
    $section = trim((string) ($input['section'] ?? ''));
    try {
      $service = $this->settingsService();
      $message = $service->save($section, is_array($payload) ? $payload : []);
      // "Medios" es una lista que se administra en el mismo popup: se devuelve su detalle actualizado y los conteos.
      // Una vista previa (conteos antes de confirmar) no cambia nada: no se recarga la configuración.
      $preview = ($section === 'medios' && is_array($payload) && ($payload['op'] ?? '') === 'preview')
        || (in_array($section, ['mandatos', 'plantillas_mandato'], true) && is_array($payload) && in_array($payload['accion'] ?? '', ['vista_previa', 'version'], true));
      JsonResponse::success(['message' => $message, 'result' => $service->lastResult(), 'settings' => $preview ? [] : $this->settingsService()->load($section)]);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  /** Configuración › Banner y revista: la imagen llega en banner_image. @param array<string,mixed> $input */
  public function settingsBanner(array $input): never
  {
    $this->verify($input);
    try {
      JsonResponse::success(['message' => 'Banner guardado.', 'url' => $this->settingsService()->saveBannerUpload(new \SCM\Commercial\Promotion\Pop\PopFiles())]);
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
    // Ventana de guías de la tarea: la consulta quien ve la tarea; usar y editar dependen de sus permisos.
    $detail['reply_guides'] = [];
    try {
      $detail['reply_guides'] = (new \SCM\Commercial\CommercialReplyGuides($this->db))->forStatus((string) ($detail['ticket']['estado_comercial'] ?? ''));
    } catch (\Throwable $exception) {
      error_log('[tareas:respuestas-guia] ' . $exception->getMessage());
    }
    // Gestión operativa en orden: Estudio de aseguradora sale si la tarea tiene cotización (o ya tiene estudios), Contrato
    // de mandato si tiene un estudio aprobado (o ya tiene mandatos) y Hoja de cierre si ya tiene mandato.
    $progress = $this->taskProgress($ticketPk, $detail['ticket']);
    $detail['quotes_count'] = count($progress['quotes']);
    $detail['studies_count'] = count($progress['studies']);
    $detail['studies_approved'] = count(array_filter($progress['studies'], static fn(array $study): bool => self::approvedStudy($study)));
    $detail['mandates_count'] = count($progress['mandates']);
    $detail['closing_mandate'] = $progress['closing'];
    JsonResponse::success([
      'html' => CommercialTicketModalView::render(
        $detail,
        $this->policy,
        $this->tickets->activeEmployeesByCargos($this->commercialCargos)
      ),
    ]);
  }
  /**
   * Cotizaciones, estudios de aseguradora y mandatos de la tarea (con la hoja de cierre del más reciente): los usan los
   * botones de Gestión operativa y el asistente. Los mandatos solo si el cargo los puede abrir; la hoja, si puede verla.
   * @param array<string,mixed> $ticket
   * @return array{quotes:list<array<string,mixed>>,studies:list<array<string,mixed>>,mandates:list<array<string,mixed>>,closing:?array<string,mixed>}
   */
  private function taskProgress(int $ticketPk, array $ticket): array
  {
    $progress = ['quotes' => [], 'studies' => [], 'mandates' => [], 'closing' => null];
    try {
      $progress['quotes'] = (new \SCM\Commercial\Quotes\CommercialQuotesRepository($this->db))->forTask($ticketPk);
    } catch (\Throwable $exception) {
      error_log('[tareas:cotizaciones] ' . $exception->getMessage());
    }
    try {
      $progress['studies'] = (new \SCM\Commercial\Studies\CommercialStudiesRepository($this->db))->forTask($ticketPk);
    } catch (\Throwable $exception) {
      error_log('[tareas:estudios] ' . $exception->getMessage());
    }
    try {
      $mandates = new \SCM\Commercial\Mandates\CommercialMandatesService($this->db, $this->policy);
      if ($this->policy->canView('mandatos')) {
        $studies = $progress['studies'];
        $rows = $mandates->repository()->forTask($ticketPk, array_map(static fn(array $study): int => (int) $study['_ID'], $studies), \SCM\Commercial\Mandates\CommercialMandatesRepository::taskProperty($ticket, $studies));
        $progress['mandates'] = array_values(array_filter($rows, static fn(array $row): bool => $mandates->canOpen($row)));
        // Hoja de cierre: la del mandato más reciente.
        if ($mandates->canClosing() && $progress['mandates'] !== []) {
          $first = $progress['mandates'][0];
          $progress['closing'] = ['id' => (int) $first['_ID'], 'hoja' => $mandates->repository()->closing($first), 'total' => count($progress['mandates'])];
        }
      }
    } catch (\Throwable $exception) {
      error_log('[tareas:mandatos] ' . $exception->getMessage());
    }
    return $progress;
  }

  /** Estudio aprobado que sigue en curso (no desistido): habilita el contrato de mandato. @param array<string,mixed> $study */
  private static function approvedStudy(array $study): bool
  {
    return trim((string) ($study['estado'] ?? '')) === 'Aprobado' && mb_strtolower(trim((string) ($study['estado_contrato'] ?? '')), 'UTF-8') !== 'desistido';
  }


  /**
   * Crea o edita una respuesta guía comercial desde la ventana de guías de la tarea y devuelve las tarjetas actualizadas.
   * @param array<string,mixed> $input
   */
  public function replyGuideSave(array $input): never
  {
    $this->verify($input);
    $guides = $this->replyGuidesForEditor();
    try {
      $id = $guides->saveOne([
        'id' => $input['id'] ?? 0,
        'estado' => $input['estado'] ?? '',
        'situacion' => $input['situacion'] ?? '',
        'respuesta' => $input['respuesta'] ?? '',
      ], \SCM\Core\Auth::employeeId());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 409);
    }
    $this->replyGuidesResponse($guides, $input, (int) ($input['id'] ?? 0) > 0 ? 'Respuesta guía actualizada.' : 'Respuesta guía creada.', $id);
  }

  /**
   * Elimina una respuesta guía comercial (la ventana pide confirmarlo) y deja constancia en el log del servidor.
   * @param array<string,mixed> $input
   */
  public function replyGuideDelete(array $input): never
  {
    $this->verify($input);
    $guides = $this->replyGuidesForEditor();
    $id = (int) ($input['id'] ?? 0);
    try {
      $situation = $guides->delete($id);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 409);
    }
    error_log('[tareas:respuestas-guia] Eliminada #' . $id . ' "' . $situation . '" por el funcionario ' . \SCM\Core\Auth::employeeId());
    $this->replyGuidesResponse($guides, $input, 'Respuesta guía eliminada.', 0);
  }

  /**
   * Edita una respuesta, seguimiento o nota de la cronología de la tarea (CommercialTimelineEditor): su autor o un
   * administrador, hasta MAX_EDITS veces y sin reenviarla al cliente.
   * @param array<string,mixed> $input
   */
  public function timelineEdit(array $input): never
  {
    $this->verify($input);
    [$editor, $ticketPk] = $this->timelineEditor($input);
    try {
      $result = $editor->edit($ticketPk, (string) ($input['tipo'] ?? ''), (int) ($input['id'] ?? 0), (string) ($input['texto'] ?? ''), (string) \SCM\Core\Auth::employeeId(), $this->policy->canManage());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 409);
    }
    JsonResponse::success(['message' => $result['message'], 'refresh' => true]);
  }

  /**
   * Elimina una entrada de la cronología (la ventana pide confirmarlo) y deja una nota interna de constancia.
   * @param array<string,mixed> $input
   */
  public function timelineDelete(array $input): never
  {
    $this->verify($input);
    [$editor, $ticketPk] = $this->timelineEditor($input);
    $type = (string) ($input['tipo'] ?? '');
    $id = (int) ($input['id'] ?? 0);
    try {
      $message = $editor->delete($ticketPk, $type, $id, (string) \SCM\Core\Auth::employeeId(), $this->policy->canManage(), \SCM\Core\Auth::user());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 409);
    }
    error_log('[tareas:historial] Eliminada ' . $type . ' #' . $id . ' de la tarea #' . $ticketPk . ' por el funcionario ' . \SCM\Core\Auth::employeeId());
    JsonResponse::success(['message' => $message, 'refresh' => true]);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{0:\SCM\Commercial\CommercialTimelineEditor,1:int}
   */
  private function timelineEditor(array $input): array
  {
    $this->authorize('ver_ticket', 'No tienes permiso para ver esta tarea.');
    $this->authorize('historial_editar', 'No tienes permiso para editar el historial de las tareas.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $this->authorizeTicketScope($ticketPk);
    return [new \SCM\Commercial\CommercialTimelineEditor($this->db), $ticketPk];
  }

  private function replyGuidesForEditor(): \SCM\Commercial\CommercialReplyGuides
  {
    $this->authorize('ver_ticket', 'No tienes permiso para ver las guías.');
    $this->authorize('respuestas_guia_editar', 'No tienes permiso para editar las respuestas guía.');
    return new \SCM\Commercial\CommercialReplyGuides($this->db);
  }

  /** @param array<string,mixed> $input */
  private function replyGuidesResponse(\SCM\Commercial\CommercialReplyGuides $guides, array $input, string $message, int $id): never
  {
    $rows = $guides->forStatus((string) ($input['estado_actual'] ?? ''));
    JsonResponse::success([
      'message' => $message,
      'id' => $id,
      'html' => CommercialTicketModalView::guideCards($rows, $this->policy->canAct('responder'), true),
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
      // El asistente ve la gestión operativa de la tarea, pero solo lo que el cargo puede ver en el panel.
      $progress = $this->taskProgress($ticketPk, $detail['ticket']);
      $detail['gestion'] = [
        'cotizaciones' => $this->policy->canView('cotizaciones') ? $progress['quotes'] : [],
        'estudios' => $this->policy->canView('estudios') ? $progress['studies'] : [],
        'mandatos' => $progress['mandates'],
        'hoja_cierre' => $progress['closing']['hoja'] ?? null,
        'ocultos' => array_values(array_filter([
          $this->policy->canView('cotizaciones') ? '' : 'cotizaciones',
          $this->policy->canView('estudios') ? '' : 'estudios de aseguradora',
          $this->policy->canView('mandatos') ? '' : 'contratos de mandato',
        ])),
      ];
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
    if ($observacion === '') {
      JsonResponse::error('Escribe el motivo de la reasignación: queda en el historial y le llega al nuevo responsable.', 422);
    }

    try {
      $reassignData = $this->tickets->reassign($ticketPk, $newEmployeeId, $this->commercialCargos, $observacion);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }

    $ticket = $reassignData['ticket'];
    $target = $reassignData['target'];
    $previousName = $reassignData['previous_name'];

    $userName = \SCM\Core\Auth::user();
    if ($userName === '') {
      $userId = \SCM\Core\Auth::userId();
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }

    $newEmpNombre = (string) ($target['nombre'] ?? '');
    $newEmpCelular = (string) ($target['celular'] ?? '');
    // Aviso del panel comercial, siempre por correo y WhatsApp: enlace individual del nuevo responsable que abre la tarea.
    try {
      (new \SCM\Commercial\Calls\CommercialCallsNotifier($this->db, $this->settings))->taskReassigned($ticketPk, array_merge($ticket, [
        'id_empleado' => (string) ($target['id'] ?? $newEmployeeId),
        'nombre_empleado' => $newEmpNombre,
        'correo_empleado' => (string) ($target['correo'] ?? ''),
        'celular_empleado' => (string) ($target['celular'] ?? ''),
      ]), $previousName, wp_strip_all_tags($observacion), $userName, true, true);
    } catch (\Throwable $exception) {
      error_log('[tareas:traslado] ' . $exception->getMessage());
    }

    $hasWhatsapp = trim($newEmpCelular) !== '';
    $message = 'Tarea reasignada a ' . ($newEmpNombre ?: 'nuevo responsable') . '.';
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
    $canSeeAll = $this->policy->seesTeam('actualizaciones', $input);
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
    $canSeeAll = $this->policy->seesTeam('avisos', $input);
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
    // Responder solo mueve la tarea entre estados abiertos: postergar y cerrar tienen su propio formulario.
    $newStatus = trim((string) ($input['estado'] ?? '__keep__'));
    if ($newStatus !== '__keep__' && $newStatus !== '' && !in_array($newStatus, CommercialStatusCatalog::OPEN, true)) {
      JsonResponse::error('Desde Responder solo se elige un estado activo. Para postergar o cerrar usa su botón.', 422);
    }
    $result = $this->workflow->saveTicketResponse(
      $ticketPk,
      $message,
      '__keep__',
      false,
      self::NO_LEGACY_EMAILS,
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->ensureWorkflowSucceeded($result);
    // El cliente siempre recibe la respuesta (correo y WhatsApp) por la conversación pública, con su enlace para responder.
    try {
      $conversation = new \SCM\Commercial\Calls\PublicTaskConversation($this->db, new \SCM\Commercial\Calls\CommercialCallsNotifier($this->db, $this->settings));
      $conversation->officerReply($ticketPk, wp_strip_all_tags($message, true), \SCM\Core\Auth::user(), \SCM\Core\Auth::employeeId());
      $result['message'] = 'Respuesta guardada. Se avisó al cliente.';
    } catch (\Throwable $exception) {
      error_log('[llamadas:respuesta] ' . $exception->getMessage());
      $result['message'] = 'Respuesta guardada, pero no se pudo avisar al cliente.';
    }

    if ($newStatus !== '' && $newStatus !== '__keep__') {
      try {
        $this->tickets->changeStatus($ticketPk, $newStatus);
      } catch (\Throwable $exception) {
        error_log('[tareas:estado] ' . $exception->getMessage());
        $result['message'] .= ' El estado comercial no se pudo cambiar: vuelve a intentarlo.';
      }
    }

    JsonResponse::success(['message' => $result['message'], 'refresh' => true]);
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
    $result = $this->workflow->save(
      $ticketPk,
      $message,
      '__keep__',
      '__keep__',
      '__keep__',
      false,
      self::NO_LEGACY_EMAILS,
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->ensureWorkflowSucceeded($result);
    $notice = $this->clientUpdate($ticketPk, 'seguimiento', $message);
    // Al responsable (si lo registró otra persona) y a las copias de Configuración › Notificaciones internas.
    try {
      $task = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$ticketPk]);
      if ($task !== null) {
        (new \SCM\Commercial\Calls\CommercialCallsNotifier($this->db, $this->settings))->followUpRegistered($ticketPk, $task, wp_strip_all_tags($message, true), \SCM\Core\Auth::user(), \SCM\Core\Auth::employeeId());
      }
    } catch (\Throwable $exception) {
      error_log('[tareas:seguimiento-interno] ' . $exception->getMessage());
    }
    JsonResponse::success(['message' => 'Seguimiento guardado.' . $notice, 'refresh' => true]);
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
    $resume = self::resumeDate((string) ($input['fecha_retomar'] ?? ''));
    if ($resume === null) {
      JsonResponse::error('Elige la fecha para retomar la tarea: desde mañana en adelante.', 422);
    }
    $result = $this->workflow->postponeTicket(
      $ticketPk,
      $message,
      self::NO_LEGACY_EMAILS,
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments(),
      true
    );
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, 'Postergado');
    $this->tickets->setResumeDate($ticketPk, $resume->getTimestamp());
    $label = self::longDate($resume);
    $notice = $this->clientNotice($ticketPk, static fn(\SCM\Commercial\Calls\CommercialCallsNotifier $notifier, array $task) => $notifier->taskPostponed($ticketPk, $task, wp_strip_all_tags($message, true), $label, \SCM\Core\Auth::user(), \SCM\Core\Auth::employeeId()));
    JsonResponse::success(['message' => 'Tarea postergada hasta el ' . $label . '.' . $notice, 'refresh' => true]);
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
      $this->uploadedDocuments(),
      true
    );
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, $status);
    $this->tickets->setResumeDate($ticketPk, null);
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
    $result = $this->workflow->closeTicket($ticketPk, $message, true);
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, $status);
    $this->tickets->setResumeDate($ticketPk, null);
    $notice = in_array($status, \SCM\Commercial\Calls\CommercialCallsNotifier::SILENT_CLOSURES, true)
      ? ''
      : $this->clientNotice($ticketPk, static fn(\SCM\Commercial\Calls\CommercialCallsNotifier $notifier, array $task) => $notifier->taskClosed($ticketPk, $task, $status, wp_strip_all_tags($message, true), \SCM\Core\Auth::user(), \SCM\Core\Auth::employeeId()));
    JsonResponse::success(['message' => 'Tarea cerrada como ' . $status . '.' . $notice, 'refresh' => true]);
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
      $this->authorizeTaskTool(\SCM\Commercial\CommercialTaskTools::SIGN, $taskId);
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
      $service->authorize();
      $service->syncUsury();
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

  /**
   * Generador UTM: fuentes, medios y campañas que ya registró el tracker de dashboard-marketing (solo lectura). No envía
   * datos de visitantes; el catálogo del equipo llega con la vista.
   */
  public function utmOptions(array $input): never
  {
    $this->verify($input);
    $this->authorizeTool('generador_utm', 'No tienes permiso para usar el generador UTM.');
    $options = ['campaigns' => []];
    try {
      $options = (new \SCM\Commercial\Tools\UtmTrackerOptions($this->db))->options();
    } catch (\Throwable $exception) {
      error_log('[commercial-tools-utm] ' . $exception->getMessage());
      JsonResponse::error('No se pudieron cargar las campañas del tracker.', 500);
    }
    JsonResponse::success($options);
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
      $this->authorizeTaskTool(\SCM\Commercial\CommercialTaskTools::PROPERTY, (int) ($input['ticket_pk'] ?? 0));
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
      $this->authorizeTaskTool(\SCM\Commercial\CommercialTaskTools::PROPERTY, (int) ($input['ticket_pk'] ?? 0));
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
      $this->authorizeTaskTool(\SCM\Commercial\CommercialTaskTools::MEDIA, $task);
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
    if ($this->policy->seesTeam((string) ($filters['tab'] ?? ''), $filters)) {
      return $filters;
    }

    $filters['id_empleado'] = $this->tickets->currentEmployeeTicketFilter($this->commercialCargos);
    return $filters;
  }

  /** Aviso nuevo, Ingresar inmueble y Multimedia solo se abren desde tareas de su tema (CommercialTaskTools). */
  private function authorizeTaskTool(string $tool, int $taskId): void
  {
    if ($taskId <= 0) {
      return;
    }
    $task = $this->db->getRow('SELECT `tema_ayuda` FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$taskId]);
    if (is_array($task) && !\SCM\Commercial\CommercialTaskTools::allowsTask($tool, $task)) {
      throw new \SCM\Commercial\CommercialSignAccessDenied(\SCM\Commercial\CommercialTaskTools::deniedMessage($tool, $task));
    }
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

  /**
   * Aviso al cliente de un seguimiento o una postergación, con el enlace a la vista pública de su tarea.
   * @return string Lo que se agrega al mensaje de la acción.
   */
  private function clientUpdate(int $ticketPk, string $kind, string $message): string
  {
    return $this->clientNotice($ticketPk, static fn(\SCM\Commercial\Calls\CommercialCallsNotifier $notifier, array $task) => $notifier->clientUpdate($ticketPk, $task, $kind, wp_strip_all_tags($message, true), \SCM\Core\Auth::user(), \SCM\Core\Auth::employeeId()));
  }

  /**
   * Avisa al cliente con la tarea ya guardada; un fallo del aviso no deshace la acción.
   * @param callable(\SCM\Commercial\Calls\CommercialCallsNotifier,array<string,mixed>):void $send
   */
  private function clientNotice(int $ticketPk, callable $send): string
  {
    try {
      $task = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$ticketPk]);
      if ($task === null) {
        return '';
      }
      $send(new \SCM\Commercial\Calls\CommercialCallsNotifier($this->db, $this->settings), $task);
      return ' Se avisó al cliente.';
    } catch (\Throwable $exception) {
      error_log('[tareas:aviso-cliente] ' . $exception->getMessage());
      return ' No se pudo avisar al cliente.';
    }
  }

  /** Fecha para retomar una tarea postergada (8:00 a. m. de Colombia): cualquiera desde mañana, sin tope. */
  public static function resumeDate(string $value): ?\DateTimeImmutable
  {
    $zone = new \DateTimeZone(\SCM\Support\ColombiaBusinessCalendar::TIMEZONE);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $zone);
    if ($date === false || $date->format('Y-m-d') !== trim($value)) {
      return null;
    }
    $today = new \DateTimeImmutable('today', $zone);
    if ($date <= $today) {
      return null;
    }
    return $date->setTime(8, 0);
  }

  /** "lunes 30 de noviembre de 2026", para el cliente. */
  public static function longDate(\DateTimeInterface $date): string
  {
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $days[(int) $date->format('w')] . ' ' . $date->format('j') . ' de ' . $months[(int) $date->format('n') - 1] . ' de ' . $date->format('Y');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function ticketFilters(array $input): array
  {
    $clean = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    return [
      'estado' => $clean('estado'),
      'mis_bucket' => $clean('mis_bucket'),
      'portafolio' => $clean('portafolio'),
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
    $identifier = trim((string) ($input['codigo'] ?? $input['property_id'] ?? ''));
    if ($identifier === '') {
      JsonResponse::error('Identificador de inmueble inválido.', 400);
    }
    // La tabla de control de la calculadora de mercado también abre la ficha del inmueble.
    $general = $this->policy->canView('inmuebles') || ($this->policy->canSubview('herramientas', 'calculadora_mercado') && $this->policy->canAct('mercado_vista_completa'));
    // Destacados y portales solo para quien tiene la vista Inmuebles: sus acciones la piden en el servidor.
    $highlights = $this->policy->canView('inmuebles');
    $repo = new CommercialPropertiesRepository($this->db);
    $property = $repo->propertyDetail($identifier, $highlights);
    $full = $general || (is_array($property) && ($this->canOpenPropertyFromStudy($property, (int) ($input['study_id'] ?? 0), (int) ($input['ticket_pk'] ?? 0)) || $this->canOpenPropertyFromContext($property, (int) ($input['ticket_pk'] ?? 0))));
    // También desde el buscador de la cotización ("Ver ficha"), con todos sus datos.
    $fromQuote = !$full && is_array($property) && $this->canOpenPropertyFromQuote($property, $input);
    if (!$full && !$fromQuote) {
      JsonResponse::error('No tienes permiso para consultar inmuebles.', 403);
    }
    if (!is_array($property) || $property === []) {
      JsonResponse::error('Inmueble no encontrado.', 404);
    }
    $html = CommercialDashboardView::renderPropertyDetailModalContent($property, $highlights);
    if (!$highlights) {
      unset($property['active_portals'], $property['user_quotas'], $property['pending_requests'], $property['highlight_history']);
    }
    JsonResponse::success([
      'property' => $property,
      'html' => $html,
    ]);
  }

  /**
   * Desde Estudios / Mis estudios o la ventana de estudios de la tarea: la ficha del inmueble del estudio, si la persona
   * puede abrir ese estudio (el suyo, el de una tarea que ve o cualquiera si ve todo el equipo en Estudios).
   * @param array<string,mixed> $property
   */
  private function canOpenPropertyFromStudy(array $property, int $studyId, int $ticketPk): bool
  {
    if ($studyId <= 0) {
      return false;
    }
    if ($ticketPk > 0) {
      if (!$this->policy->canAct('ver_ticket')) {
        return false;
      }
      $this->authorizeTicketScope($ticketPk);
    }
    $service = new \SCM\Commercial\Studies\CommercialStudiesService(new \SCM\Commercial\Studies\CommercialStudiesRepository($this->db), $this->settings, $this->policy);
    try {
      $study = $service->studyFor($studyId, $ticketPk);
    } catch (\InvalidArgumentException | \RuntimeException) {
      return false;
    }
    $code = trim((string) ($study['id_inmueble'] ?? ''));
    return $code !== '' && $code === trim((string) ($property['codigo'] ?? ''));
  }

  /**
   * Desde el buscador de la cotización de una tarea ("Ver ficha"): quien puede crear cotizaciones ve la ficha de los
   * inmuebles que se pueden cotizar, los mismos que le muestra el buscador.
   * @param array<string,mixed> $property
   * @param array<string,mixed> $input
   */
  private function canOpenPropertyFromQuote(array $property, array $input): bool
  {
    if (($input['origen'] ?? '') !== 'cotizacion' || !$this->policy->canAct(\SCM\Commercial\Quotes\CommercialQuotesService::ACTION) || !$this->policy->canAct('ver_ticket')) {
      return false;
    }
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    if ($ticketPk > 0) {
      $this->authorizeTicketScope($ticketPk);
    }
    return \SCM\Commercial\Quotes\CommercialQuotesRepository::isQuotable($property);
  }

  /**
   * Sin la vista Inmuebles, la ficha se abre desde una tarea que la persona ve y que es de ese inmueble,
   * o si el inmueble está a su cargo (avisos y actualizaciones del calendario), por su id_empleado exacto.
   * @param array<string,mixed> $property
   */
  private function canOpenPropertyFromContext(array $property, int $ticketPk): bool
  {
    $code = trim((string) ($property['codigo'] ?? ''));
    $me = trim((string) \SCM\Core\Auth::employeeId());
    if ($me !== '' && trim((string) ($property['id_funcionario'] ?? '')) === $me) {
      return true;
    }
    if ($ticketPk <= 0 || !$this->policy->canAct('ver_ticket')) {
      return false;
    }
    $this->authorizeTicketScope($ticketPk);
    try {
      $ticket = $this->tickets->detail($ticketPk)['ticket'];
    } catch (\InvalidArgumentException | \RuntimeException) {
      return false;
    }
    $data = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    if ($data !== [] && trim((string) ($data['_ID'] ?? '')) !== '' && trim((string) ($data['_ID'] ?? '')) === trim((string) ($property['_ID'] ?? ''))) {
      return true;
    }
    return $code !== '' && in_array($code, array_map(static fn($value): string => trim((string) $value), [$ticket['id_inmueble'] ?? '', $ticket['inmueble'] ?? '', $data['codigo'] ?? '']), true);
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

  /**
   * Cambia el estado del inmueble (CCT y post type). Quien no ve todo el inventario solo cambia sus inmuebles.
   *
   * @param array<string,mixed> $input
   */
  public function propertyChangeStatus(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canView('inmuebles') || !$this->policy->canAct('inmueble_cambiar_estado')) {
      JsonResponse::error('No tienes permiso para cambiar el estado de los inmuebles.', 403);
    }
    $code = trim((string) ($input['codigo'] ?? ''));
    $state = trim((string) ($input['estado'] ?? ''));
    $observation = mb_substr(trim((string) ($input['observacion'] ?? '')), 0, 1000);

    $employeeId = trim(Auth::employeeId());
    $employeeName = trim(Auth::user()) !== '' ? trim(Auth::user()) : 'Funcionario';
    $owner = $this->policy->canSeeAll('inmuebles') ? null : $employeeId;

    try {
      $res = (new CommercialPropertiesRepository($this->db))->changeStatus($code, $state, $observation, $employeeId, $employeeName, $owner);
    } catch (\Throwable $error) {
      error_log('[commercial-properties] No se pudo cambiar el estado del inmueble ' . $code . ': ' . $error->getMessage());
      JsonResponse::error('No se pudo cambiar el estado del inmueble.', 500);
    }
    if (!$res['ok']) {
      JsonResponse::error($res['message'], 422);
    }
    JsonResponse::ok($res);
  }

  /**
   * Las acciones sobre un inmueble desde su tarjeta: vista Inmuebles, el permiso de la acción y, quien no ve todo el
   * inventario, solo sus inmuebles (id_funcionario = su id_empleado).
   */
  private function authorizePropertyAction(string $action, string $code, string $message): void
  {
    if (!$this->policy->canView('inmuebles') || !$this->policy->canAct($action)) {
      JsonResponse::error($message, 403);
    }
    if ($this->policy->canSeeAll('inmuebles')) {
      return;
    }
    $employeeId = trim(Auth::employeeId());
    $property = (new \SCM\Commercial\CommercialPropertyHistory($this->db))->property($code);
    if ($employeeId === '' || $property === null || trim((string) ($property['id_funcionario'] ?? '')) !== $employeeId) {
      JsonResponse::error('Solo puedes hacerlo con tus inmuebles.', 403);
    }
  }

  /** @param array<string,mixed> $input */
  public function propertyHistory(array $input): never
  {
    $this->verify($input);
    $code = \SCM\Commercial\CommercialPropertyHistory::cleanCode((string) ($input['codigo'] ?? ''));
    $this->authorizePropertyAction('inmueble_ver_historial', $code, 'No tienes permiso para ver el historial de los inmuebles.');
    $history = new \SCM\Commercial\CommercialPropertyHistory($this->db);
    try {
      $payload = $history->payload($code);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 404);
    }
    JsonResponse::ok(['html' => $history->render($payload), 'codigo' => (string) $payload['property']['codigo']]);
  }

  /** @param array<string,mixed> $input */
  public function propertyHistoryPdf(array $input): never
  {
    $this->verify($input);
    $code = \SCM\Commercial\CommercialPropertyHistory::cleanCode((string) ($input['codigo'] ?? ''));
    $this->authorizePropertyAction('inmueble_ver_historial', $code, 'No tienes permiso para generar este PDF.');
    $history = new \SCM\Commercial\CommercialPropertyHistory($this->db);
    try {
      $payload = $history->payload($code);
      $bytes = $history->pdf($payload);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 404);
    } catch (\RuntimeException $exception) {
      error_log('[commercial-property-history-pdf] ' . $exception->getMessage());
      JsonResponse::error($exception->getMessage(), 503);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . \SCM\Commercial\CommercialPropertyHistory::filename($payload) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
  }

  private function propertyTransferService(): \SCM\Commercial\CommercialPropertyTransfer
  {
    return new \SCM\Commercial\CommercialPropertyTransfer($this->db, $this->commercialCargos);
  }

  /** @param array<string,mixed> $input */
  public function propertyTransferOptions(array $input): never
  {
    $this->verify($input);
    $code = trim((string) ($input['codigo'] ?? ''));
    $this->authorizePropertyAction('inmueble_transferir', $code, 'No tienes permiso para transferir inmuebles.');
    JsonResponse::ok(['funcionarios' => $this->propertyTransferService()->employees()]);
  }

  /** @param array<string,mixed> $input */
  public function propertyTransfer(array $input): never
  {
    $this->verify($input);
    $code = trim((string) ($input['codigo'] ?? ''));
    $this->authorizePropertyAction('inmueble_transferir', $code, 'No tienes permiso para transferir inmuebles.');
    $employeeId = trim(Auth::employeeId());
    try {
      $res = $this->propertyTransferService()->transfer($code, trim((string) ($input['id_empleado'] ?? '')), $employeeId, $this->policy->canSeeAll('inmuebles') ? null : $employeeId);
    } catch (\Throwable $error) {
      error_log('[commercial-property-transfer] No se pudo transferir el inmueble ' . $code . ': ' . $error->getMessage());
      JsonResponse::error('No se pudo transferir el inmueble.', 500);
    }
    if (!$res['ok']) {
      JsonResponse::error($res['message'], 422);
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
    ] + \SCM\Commercial\PropertySearchFilters::clean($input);
  }
}
