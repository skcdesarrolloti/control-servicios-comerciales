<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTicketsRepository;
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
  private CommercialTicketsRepository $tickets;
  private CommercialAccessPolicy $policy;
  private Csrf $csrf;
  private SeguimientoService $workflow;
  private string $baseUrl;
  private string $ticketUrl;
  /** @var array<int,string> */
  private array $commercialCargos;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->tickets = new CommercialTicketsRepository($db);
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->commercialCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? array_values(array_map('strval', $config['calendar_allowed_cargos'])) : ['9', '10', '17'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->workflow = new SeguimientoService($db, new SchemaInspector($db));
    $this->workflow->setQueue(new EmailQueue($db));
    $this->baseUrl = rtrim((string) SCM_BASE_URL, '/');
    $this->ticketUrl = (string) ($config['ticket_url'] ?? '');
  }

  /** @param array<string,mixed> $input */
  public function filterTickets(array $input): never
  {
    $this->verify($input);
    $bucket = trim((string) ($input['tab'] ?? 'abiertos'));
    if (!array_key_exists($bucket, CommercialStatusCatalog::buckets()) || !$this->policy->canView($bucket)) {
      JsonResponse::error('No tienes permiso para consultar esta vista.', 403);
    }
    $filters = $this->ticketFilters($input);
    $filters['tab'] = $bucket;
    $result = $this->tickets->search($bucket, $filters);
    $visibleViews = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), fn(string $view): bool => $this->policy->canView($view)));
    $tabCounts = $this->tickets->bucketCounts($this->globalTicketFilters($filters));
    JsonResponse::success([
      'html' => CommercialDashboardView::renderTickets(
        $bucket,
        $result,
        $filters,
        $this->tickets->ticketEmployees(),
        $this->tickets->filterOptions(),
        $this->policy,
        $this->baseUrl
      ),
      'tabs_html' => CommercialDashboardView::renderTabs($visibleViews, $bucket, $filters, $tabCounts, $this->baseUrl),
      'global_filters_html' => CommercialDashboardView::renderGlobalFilters($filters, $this->tickets->ticketEmployees(), $this->baseUrl),
      'tab' => $bucket,
    ]);
  }

  /** @param array<string,mixed> $input */
  public function ticketDetail(array $input): never
  {
    $this->verify($input);
    $this->authorize('ver_ticket', 'No tienes permiso para ver este ticket.');
    try {
      $detail = $this->tickets->detail((int) ($input['ticket_pk'] ?? 0));
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 404);
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
  public function changeStatus(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canAct('cambiar_estado')) {
      JsonResponse::error('No tienes permiso para cambiar estados comerciales.', 403);
    }
    try {
      $this->tickets->changeStatus((int) ($input['ticket_pk'] ?? 0), trim((string) ($input['estado'] ?? '')));
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
      JsonResponse::error('No tienes permiso para reasignar tickets.', 403);
    }
    try {
      $this->tickets->reassign((int) ($input['ticket_pk'] ?? 0), trim((string) ($input['id_empleado'] ?? '')), $this->commercialCargos);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    }
    JsonResponse::success(['message' => 'El responsable comercial fue actualizado.']);
  }

  /** @param array<string,mixed> $input */
  public function savePermissions(array $input): never
  {
    $this->verify($input);
    if (!$this->policy->canManage()) {
      JsonResponse::error('No tienes permiso para cambiar esta configuración.', 403);
    }
    $permissions = $input['permissions'] ?? [];
    $this->policy->save(is_array($permissions) ? $permissions : []);
    JsonResponse::success(['message' => 'Configuración guardada.']);
  }

  /** @param array<string,mixed> $input */
  public function reply(array $input): never
  {
    $this->verify($input);
    $this->authorize('responder', 'No tienes permiso para responder tickets.');
    $message = trim(wp_kses_post(stripslashes((string) ($input['respuesta'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('La respuesta no puede estar vacía.', 422);
    }
    $result = $this->workflow->saveTicketResponse(
      (int) ($input['ticket_pk'] ?? 0),
      $message,
      '__keep__',
      false,
      $this->notifyTargets($input),
      $this->uploadedImages('evidencia'),
      $this->uploadedDocuments()
    );
    $this->workflowResponse($result, 'Respuesta guardada.');
  }

  /** @param array<string,mixed> $input */
  public function addNote(array $input): never
  {
    $this->verify($input);
    $this->authorize('agregar_nota', 'No tienes permiso para agregar notas.');
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('La nota no puede estar vacía.', 422);
    }
    $this->workflowResponse(
      $this->workflow->saveNote((int) ($input['ticket_pk'] ?? 0), $message),
      'Nota guardada.'
    );
  }

  /** @param array<string,mixed> $input */
  public function followUp(array $input): never
  {
    $this->verify($input);
    $this->authorize('seguimiento', 'No tienes permiso para registrar seguimientos.');
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    if ($message === '') {
      JsonResponse::error('El seguimiento no puede estar vacío.', 422);
    }
    $this->workflowResponse(
      $this->workflow->save(
        (int) ($input['ticket_pk'] ?? 0),
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
    $this->authorize('postergar', 'No tienes permiso para postergar tickets.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
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
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Ticket postergado.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function activate(array $input): never
  {
    $this->verify($input);
    $this->authorize('activar', 'No tienes permiso para activar tickets.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
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
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Ticket activado.'), 'refresh' => true]);
  }

  /** @param array<string,mixed> $input */
  public function close(array $input): never
  {
    $this->verify($input);
    $this->authorize('cerrar', 'No tienes permiso para cerrar tickets.');
    $ticketPk = (int) ($input['ticket_pk'] ?? 0);
    $message = trim(wp_kses_post(stripslashes((string) ($input['observacion'] ?? ''))));
    $status = trim((string) ($input['estado'] ?? 'Finalizado'));
    if ($message === '' || !in_array($status, CommercialStatusCatalog::CLOSED, true)) {
      JsonResponse::error('Selecciona un estado de cierre e indica el motivo.', 422);
    }
    $result = $this->workflow->closeTicket($ticketPk, $message);
    $this->ensureWorkflowSucceeded($result);
    $this->tickets->changeStatus($ticketPk, $status);
    JsonResponse::success(['message' => (string) ($result['message'] ?? 'Ticket cerrado.'), 'refresh' => true]);
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
}
