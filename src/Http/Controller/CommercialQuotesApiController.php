<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Commercial\Quotes\CommercialQuotesNotifier;
use SCM\Commercial\Quotes\CommercialQuotesRepository;
use SCM\Commercial\Quotes\CommercialQuotesService;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\CommercialQuotesView;

/**
 * Cotizaciones de inmuebles: el formulario del popup de la tarea (valida que la persona pueda abrir esa tarea) y la pestaña
 * Cotizaciones / Mis cotizaciones (valida la vista). El servicio valida la acción cotizacion_crear y el alcance.
 */
final class CommercialQuotesApiController
{
  private CommercialAccessPolicy $policy;
  private CommercialQuotesService $service;
  private CommercialTicketsRepository $tickets;
  private Settings $settings;
  private Csrf $csrf;
  /** @var array<int|string,mixed> */
  private array $defaultCargos;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->csrf = $csrf;
    $this->settings = $settings;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->defaultCargos = is_array($config['commercial_employee_cargos'] ?? null) ? $config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->tickets = new CommercialTicketsRepository($db);
    $this->service = new CommercialQuotesService(new CommercialQuotesRepository($db), $settings, $this->policy, new CommercialQuotesNotifier($db, $settings));
  }

  /** @param array<string,mixed> $input */
  private function guarded(array $input, callable $work): never
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    try {
      JsonResponse::success($work());
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\PDOException $exception) {
      throw $exception;
    } catch (\RuntimeException $exception) {
      JsonResponse::error($exception->getMessage(), 403);
    }
  }

  /**
   * La tarea debe ser de la persona, salvo que vea todo el equipo en alguna pestaña de tareas (como el popup).
   * @param array<string,mixed> $input
   */
  private function taskPk(array $input): int
  {
    $pk = (int) ($input['ticket_pk'] ?? 0);
    if ($pk <= 0) {
      throw new \InvalidArgumentException('Tarea inválida.');
    }
    if (!$this->policy->canSeeAllTasks()) {
      $raw = $this->settings->get('commercial_employee_cargos', null);
      $cargos = array_values(array_filter(array_map(static fn($id): string => trim((string) $id), is_array($raw) && $raw !== [] ? $raw : $this->defaultCargos)));
      if (!$this->tickets->ticketMatchesEmployeeFilter($pk, $this->tickets->currentEmployeeTicketFilter($cargos))) {
        throw new \RuntimeException('Esta tarea no está asignada a tu usuario.');
      }
    }
    return $pk;
  }

  private function requireView(): void
  {
    if (!$this->policy->canView(CommercialQuotesService::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver las cotizaciones.');
    }
  }

  /** @param array<string,mixed> $input */
  public function form(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialQuotesView::taskForm($this->service->formData($this->taskPk($input)))]);
  }

  /** @param array<string,mixed> $input */
  public function properties(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $ticketPk = $this->taskPk($input);
      $result = $this->service->searchProperties($input);
      return ['html' => CommercialQuotesView::propertyResults($result, $ticketPk), 'total' => $result['total']];
    });
  }

  /** @param array<string,mixed> $input */
  public function create(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $result = $this->service->create($pk, $input);
      return $result + ['html' => CommercialQuotesView::created($result, $pk)];
    });
  }

  /** @param array<string,mixed> $input */
  public function list(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $this->requireView();
      return ['html' => CommercialQuotesView::results($this->service->listing($input), $this->policy)];
    });
  }

  /** @param array<string,mixed> $input Con ticket_pk, una cotización de esa tarea (abierta desde el popup). */
  public function view(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $ticketPk = (int) ($input['ticket_pk'] ?? 0) > 0 ? $this->taskPk($input) : 0;
      $id = (int) ($input['id'] ?? 0);
      $url = $this->service->viewUrl($id, $ticketPk);
      return ['url' => $url, 'html' => CommercialQuotesView::viewer($id, $url)];
    });
  }
}
