<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Commercial\Mandates\CommercialMandateFormService;
use SCM\Commercial\Mandates\CommercialMandatesRepository;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\MandateSignature;
use SCM\Commercial\Studies\CommercialStudiesRepository;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Views\CommercialMandateFormView;
use SCM\Views\CommercialMandatesView;

/**
 * Contratos de mandato en el panel: la pestaña (listado y popup), la ventana del popup de la tarea (Gestión operativa ›
 * Contrato de mandato), el formulario de crear y editar, el envío a firma y los buscadores del formulario.
 * Valida la vista y su alcance, que la tarea sea de la persona (como estudios) y las acciones mandato_crear y
 * mandato_enviar_firma (también en el servicio).
 */
final class CommercialMandatesApiController
{
  private Database $db;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private CommercialMandatesService $service;
  private CommercialMandateFormService $form;
  private CommercialTicketsRepository $tickets;
  private Csrf $csrf;
  /** @var array<int|string,mixed> */
  private array $defaultCargos;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config, ?CommercialMandatesService $service = null, ?CommercialMandateFormService $form = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->defaultCargos = is_array($config['commercial_employee_cargos'] ?? null) ? $config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->service = $service ?? new CommercialMandatesService($db, $this->policy);
    $this->form = $form ?? new CommercialMandateFormService($db, $this->policy, null, MandateSignature::fromConfig($config));
    $this->tickets = new CommercialTicketsRepository($db);
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

  // ── Pestaña Mandatos ───────────────────────────────

  /** @param array<string,mixed> $input */
  public function list(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialMandatesView::results($this->service->listing($input))]);
  }

  /** @param array<string,mixed> $input */
  public function view(array $input): never
  {
    $this->guarded($input, fn(): array => ['html' => CommercialMandatesView::viewer($this->openable((int) ($input['id'] ?? 0)), (string) ($input['vista'] ?? ''))]);
  }

  /** Mandato que la persona puede abrir. */
  private function openable(int $id): int
  {
    $mandate = $this->service->repository()->find($id);
    if ($mandate === null) {
      throw new \InvalidArgumentException('El mandato no existe.');
    }
    if (!$this->service->canOpen($mandate)) {
      throw new \RuntimeException('No tienes permiso para ver este mandato.');
    }
    return $id;
  }

  // ── Popup de la tarea ──────────────────────────────

  /**
   * La tarea debe ser de la persona, salvo que vea todo el equipo en alguna pestaña de tareas (como estudios).
   * @param array<string,mixed> $input
   */
  private function taskPk(array $input): int
  {
    $pk = (int) ($input['ticket_pk'] ?? 0);
    if ($pk <= 0) {
      return 0;
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

  /** @return array{task:array<string,mixed>,studies:list<array<string,mixed>>,property:string} */
  private function taskContext(int $pk): array
  {
    $task = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$pk]);
    if (!is_array($task)) {
      throw new \InvalidArgumentException('La tarea no existe.');
    }
    $studies = (new CommercialStudiesRepository($this->db))->forTask($pk);
    return ['task' => $task, 'studies' => $studies, 'property' => CommercialMandatesRepository::taskProperty($task, $studies)];
  }

  /** @return array{html:string} */
  private function taskPanel(int $pk, string $flash = ''): array
  {
    if (!$this->policy->canView(CommercialMandatesService::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver los contratos de mandato.');
    }
    $context = $this->taskContext($pk);
    $rows = $this->service->repository()->forTask($pk, array_map(static fn(array $study): int => (int) $study['_ID'], $context['studies']), $context['property']);
    return ['html' => CommercialMandatesView::taskPanel([
      'ticket' => $pk, 'asunto' => trim((string) ($context['task']['asunto'] ?? '')),
      'rows' => array_map(fn(array $row): array => $this->service->summary($row), $rows),
      'can_create' => $this->form->canEdit(), 'can_sign' => $this->policy->canAct(CommercialMandateFormService::ACTION_SIGN), 'can_closing' => $this->service->canClosing(), 'flash' => $flash,
    ])];
  }

  /** @param array<string,mixed> $input */
  public function task(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      if ($pk <= 0) {
        throw new \InvalidArgumentException('Tarea inválida.');
      }
      return $this->taskPanel($pk);
    });
  }

  // ── Formulario ─────────────────────────────────────

  /** @param array<string,mixed> $input */
  public function form(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      if (!$this->form->canEdit()) {
        throw new \RuntimeException('No tienes permiso para crear o editar mandatos.');
      }
      $pk = $this->taskPk($input);
      $id = (int) ($input['id'] ?? 0);
      $mandate = $id > 0 ? $this->service->repository()->find($this->openable($id)) : null;
      if ($mandate !== null && trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
        throw new \InvalidArgumentException('Este mandato ya está firmado: no se puede editar.');
      }
      $studies = [];
      $study = 0;
      $property = '';
      $subtitle = 'Contratos de mandato';
      if ($pk > 0) {
        $context = $this->taskContext($pk);
        $property = $context['property'];
        $subtitle = 'Tarea #' . $pk . (trim((string) ($context['task']['asunto'] ?? '')) !== '' ? ' · ' . trim((string) $context['task']['asunto']) : '');
        foreach ($context['studies'] as $row) {
          $studies[] = ['id' => (int) $row['_ID'], 'label' => '#' . (int) $row['_ID'] . ' · ' . trim((string) ($row['aseguradora'] ?? '')) . ' · ' . trim((string) ($row['estado'] ?? ''))];
          if ($study === 0 && trim((string) ($row['estado'] ?? '')) === 'Aprobado') {
            $study = (int) $row['_ID'];
          }
        }
      }
      $values = $this->form->initialValues($mandate, $property);
      $html = CommercialMandateFormView::render($values, $this->form->options(), $this->form->calculator()->parameters(), [
        'id' => $id, 'ticket' => $pk, 'estudio' => $study, 'estudios' => $studies, 'estado' => (string) ($mandate['estado'] ?? ''), 'volver' => '',
      ]);
      return ['html' => CommercialMandatesView::formPanel($html, $id, $pk, $subtitle)];
    });
  }

  /** @param array<string,mixed> $input */
  public function save(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $id = (int) ($input['id'] ?? 0);
      $result = $this->form->save($input, $id > 0 ? $id : null, ['ticket' => $pk, 'estudio' => (int) ($input['id_estudio'] ?? 0)]);
      $message = $id > 0
        ? 'Se guardaron los cambios del mandato #' . $result['id'] . ($result['firma'] ? ' y se actualizó su contrato en eSignatures.' : ($result['reenviar']
          ? '. Ya estaba en firma: el contrato de eSignatures no se actualizó porque la hoja de cierre no está aprobada. Cuando esté listo, usa "Reenviar a firma".'
          : '.'))
        : 'Se creó el mandato #' . $result['id'] . '. Envíaselo al propietario para que lo revise antes de la firma.';
      return ['message' => $message, 'id' => $result['id']] + ($pk > 0 ? $this->taskPanel($pk, $message) : []);
    });
  }

  /** @param array<string,mixed> $input */
  public function sign(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      $pk = $this->taskPk($input);
      $id = $this->openable((int) ($input['id'] ?? 0));
      $mandate = $this->service->repository()->find($id) ?? [];
      // sendToSign exige que la hoja de cierre esté aprobada (la revisión del propietario es opcional).
      $result = $this->form->sendToSign($id);
      $message = 'El mandato #' . $id . ' se envió a firma digital' . (trim((string) ($mandate['id_esignatures'] ?? '')) !== '' && $result['contrato'] === trim((string) $mandate['id_esignatures']) ? ' (se actualizó el mismo contrato).' : '.');
      return ['message' => $message] + ($pk > 0 ? $this->taskPanel($pk, $message) : []);
    });
  }

  /** @param array<string,mixed> $input */
  public function search(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      if (!$this->form->canEdit()) {
        throw new \RuntimeException('No tienes permiso para crear o editar mandatos.');
      }
      $query = mb_substr(trim((string) ($input['q'] ?? '')), 0, 80);
      return ['items' => (string) ($input['tipo'] ?? '') === 'copropiedad' ? $this->form->searchCoOwnerships($query) : $this->form->searchOwners($query)];
    });
  }

  /** @param array<string,mixed> $input */
  public function property(array $input): never
  {
    $this->guarded($input, function () use ($input): array {
      if (!$this->form->canEdit()) {
        throw new \RuntimeException('No tienes permiso para crear o editar mandatos.');
      }
      $values = $this->form->propertyValues(mb_substr(trim((string) ($input['codigo'] ?? '')), 0, 40));
      if ($values === []) {
        throw new \InvalidArgumentException('No encontramos ese inmueble. Revisa el código.');
      }
      return ['campos' => $values];
    });
  }
}
