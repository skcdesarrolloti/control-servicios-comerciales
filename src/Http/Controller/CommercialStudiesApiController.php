<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Commercial\Studies\CommercialStudiesNotifier;
use SCM\Commercial\Studies\CommercialStudiesRepository;
use SCM\Commercial\Studies\CommercialStudiesService;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Support\StoredFileService;
use SCM\Views\CommercialStudiesView;

/**
 * Estudios de aseguradora: la ventana del popup de la tarea (valida que la persona pueda abrir esa tarea, como las
 * cotizaciones) y la pestaña Estudios / Mis estudios (valida la vista y el alcance). El servicio valida las acciones
 * estudio_crear, estudio_responder y estudio_desistir.
 */
final class CommercialStudiesApiController
{
  private CommercialAccessPolicy $policy;
  private CommercialStudiesService $service;
  private CommercialTicketsRepository $tickets;
  private Settings $settings;
  private Csrf $csrf;
  /** @var array<int|string,mixed> */
  private array $defaultCargos;
  private ?StoredFileService $files;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config, ?CommercialStudiesService $service = null, ?StoredFileService $files = null)
  {
    $this->files = $files;
    $this->csrf = $csrf;
    $this->settings = $settings;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->defaultCargos = is_array($config['commercial_employee_cargos'] ?? null) ? $config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->tickets = new CommercialTicketsRepository($db);
    $this->service = $service ?? new CommercialStudiesService(new CommercialStudiesRepository($db), $settings, $this->policy, new CommercialStudiesNotifier($db, $settings));
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
    if (!$this->policy->canAct('ver_ticket')) {
      throw new \RuntimeException('No tienes permiso para ver tareas.');
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

  /** @return array{html:string} La ventana de la tarea, recargada con el mensaje de la última acción. */
  private function panel(int $pk, string $flash = ''): array
  {
    return ['html' => CommercialStudiesView::taskPanel($this->service->formData($pk), $flash)];
  }

  /** @param array<string,mixed> $input */
  public function form(array $input): never
  {
    $this->guarded($input, fn(): array => $this->panel($this->taskPk($input)));
  }

  /** @param array<string,mixed> $input */
  public function create(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $result = $this->service->create($pk, $input);
      return ['message' => $result['message'], 'id' => $result['id']] + $this->panel($pk, $result['message']);
    });
  }

  /** @param array<string,mixed> $input */
  public function update(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $result = $this->withDocuments(fn(array $documents): array => $this->service->update((int) ($input['id'] ?? 0), $pk, $input, $documents));
      if ($result['removed'] !== []) {
        $this->files()->removeImages($result['removed']);
      }
      return ['message' => $result['message']] + $this->panel($pk, $result['message']);
    });
  }

  /** @param array<string,mixed> $input */
  public function respond(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $result = ($input['estado'] ?? '') === CommercialStudiesService::APPROVED
        ? $this->withDocuments(fn(array $documents): array => $this->service->respond((int) ($input['id'] ?? 0), $pk, $input, $documents))
        : $this->service->respond((int) ($input['id'] ?? 0), $pk, $input);
      return ['message' => $result['message']] + $this->panel($pk, $result['message']);
    });
  }

  private function files(): StoredFileService
  {
    return $this->files ??= StoredFileService::fromRuntime();
  }

  /**
   * Guarda los archivos de cada tipo de CommercialStudiesService::DOCUMENTS (campos docs_<tipo>[]) y se los pasa a $work;
   * si algo falla, borra los que alcanzó a guardar.
   * @template T
   * @param callable(array<string,list<array{nombre:string,url:string}>>):T $work
   * @return T
   */
  private function withDocuments(callable $work): mixed
  {
    $documents = [];
    try {
      foreach (CommercialStudiesService::DOCUMENTS as $type => [$title, $formats, $max]) {
        $documents[$type] = $this->files()->storeFiles('docs_' . $type, $formats, $max, $title);
      }
      return $work($documents);
    } catch (\Throwable $exception) {
      foreach ($documents as $files) {
        $this->files()->removeImages(array_column($files, 'url'));
      }
      throw $exception;
    }
  }

  /** @param array<string,mixed> $input */
  public function properties(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $this->taskPk($input);
      return ['html' => CommercialStudiesView::propertyResults($this->service->searchProperties(is_scalar($input['q'] ?? null) ? mb_substr(trim((string) $input['q']), 0, 80, 'UTF-8') : ''))];
    });
  }

  /**
   * Desistir el negocio: desde la tarea (con ticket_pk) o desde el listado (el estudio debe ser suyo o ver todo el equipo).
   * @param array<string,mixed> $input
   */
  public function desist(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = (int) ($input['ticket_pk'] ?? 0) > 0 ? $this->taskPk($input) : 0;
      $result = $this->service->desist((int) ($input['id'] ?? 0), $input, $pk);
      return ['message' => $result['message']] + ($pk > 0 ? $this->panel($pk, $result['message']) : []);
    });
  }

  /** @param array<string,mixed> $input Formulario de desistir para el popup del listado. */
  public function desistForm(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $id = (int) ($input['id'] ?? 0);
      $study = $this->service->studyFor($id);
      if (!$this->service->canDesist() || !CommercialStudiesService::desistable($study)) {
        throw new \RuntimeException('Este negocio no se puede desistir.');
      }
      return ['html' => CommercialStudiesView::desistForm($id, $this->service->desistReasons(), 0)];
    });
  }

  /** @param array<string,mixed> $input */
  public function list(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      if (!$this->policy->canView(CommercialStudiesService::VIEW)) {
        throw new \RuntimeException('No tienes permiso para ver los estudios de aseguradora.');
      }
      return ['html' => CommercialStudiesView::results($this->service->listing($input), $this->policy, $this->service->canDesist())];
    });
  }

  /** @param array<string,mixed> $input Con ticket_pk, un estudio de esa tarea (abierto desde el popup). */
  public function view(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = (int) ($input['ticket_pk'] ?? 0) > 0 ? $this->taskPk($input) : 0;
      $id = (int) ($input['id'] ?? 0);
      $this->service->studyFor($id, $pk);
      return ['html' => CommercialStudiesView::viewer($id)];
    });
  }
}
