<?php

declare(strict_types=1);

namespace SCM\Commercial\Studies;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTaskTools;
use SCM\Commercial\Quotes\QuoteComparison;
use SCM\Core\Auth;
use SCM\Core\Settings;
use SCM\Support\PublicStudyAccess;

/**
 * Estudios de aseguradora: se crean, se responden, se editan y se desisten desde el popup de la tarea (Gestión operativa ›
 * Estudio de aseguradora) y se listan en Actividades comerciales › Registros › Estudios (y Mi portafolio › Mis estudios).
 * Reemplaza los formularios JetFormBuilder "Agregar estudio de aseguradora" y "Respuesta estudio de aseguradora" y la
 * vista /estudio-aseguradora/ de WordPress (public/estudio.php).
 *
 * La aprobación hace lo mismo que el formulario (caso de entrega "Revisión antes de la ocupación", contrato "No asignado"
 * por entregar, inmueble "En proceso de cierre", historiales), con estas mejoras:
 * - todo en una transacción y una sola vez: un estudio ya respondido no se vuelve a aprobar (el formulario creaba otro
 *   caso y otro contrato cada vez que se enviaba);
 * - solo inmuebles públicos o en borrador y con propietario (sin propietario el formulario aprobaba sin crear nada);
 * - el responsable del caso de entrega se elige en Configuración (el formulario lo tenía fijo) y los avisos internos en
 *   Notificaciones internas (el formulario tenía correos fijos);
 * - quien respondió no reemplaza a quien hizo el estudio (id_empleado);
 * - un estudio aplazado deja la tarea abierta "En estudio" (pendiente de documentos), no postergada;
 * - el historial de la tarea cuenta qué pasó (el formulario lo dejaba vacío).
 *
 * Desistir el negocio de un estudio aprobado copia ClosingSheetDesist::desist (control-servicios-inmobiliarios
 * src/Modules/ClosingSheets/ClosingSheetDesist.php, el JetForm "Desistir negocio"): mantener ambos iguales.
 */
final class CommercialStudiesService
{
  public const VIEW = 'estudios';
  public const ACTION_CREATE = 'estudio_crear';
  public const ACTION_RESPOND = 'estudio_responder';
  public const ACTION_DESIST = 'estudio_desistir';
  public const SETTING = 'commercial_studies';
  public const LIST_PER_PAGE = 25;

  public const WAITING = 'Esperando respuesta';
  public const POSTPONED = 'Aplazado';
  public const APPROVED = 'Aprobado';
  public const REJECTED = 'Rechazado';
  public const DESISTED = 'Desistido';
  /** Estados del glosario 1143 "Estado de estudio". */
  public const STATES = [self::WAITING, self::POSTPONED, self::APPROVED, self::REJECTED, self::DESISTED];
  /** Respuestas posibles: desde "Aplazado" también se puede volver a "Esperando respuesta" (llegaron los documentos). */
  public const RESPONSES = [self::POSTPONED, self::APPROVED, self::REJECTED, self::DESISTED];
  /** Pestañas del listado: los aprobados se separan por lo que pasó con el negocio. */
  public const LIST_STATES = [self::WAITING, self::POSTPONED, self::APPROVED, 'Entregado', 'Negocio desistido', self::REJECTED, self::DESISTED];
  public const CHANGE_PROPERTY = 'Cambio de inmueble';
  public const CONTRACT_PENDING = 'Por entregar';

  /** Estado comercial y estado general de la tarea según el estudio (estado_administrativo no se toca). */
  private const TASK_STATUS = [
    self::WAITING => ['En estudio', 'En proceso'],
    self::POSTPONED => ['En estudio', 'En proceso'],
    self::APPROVED => ['Aprobado', 'En proceso'],
    self::REJECTED => ['Rechazado', 'Cerrado'],
    self::DESISTED => ['Desistido', 'Cerrado'],
  ];

  /** Lo que lee el cliente en su enlace sobre cada estado. */
  public const CLIENT_STATES = [
    self::WAITING => ['En estudio', 'La aseguradora está revisando sus documentos. Le avisaremos en cuanto responda.'],
    self::POSTPONED => ['Aplazado: faltan documentos', 'La aseguradora necesita información o documentos adicionales para continuar con el estudio.'],
    self::APPROVED => ['Aprobado', 'La aseguradora aprobó el estudio. Su consultor/a coordinará con usted la entrega del inmueble.'],
    'Entregado' => ['Inmueble entregado', 'El estudio fue aprobado y el inmueble ya fue entregado.'],
    'Negocio desistido' => ['Negocio desistido', 'El estudio fue aprobado, pero el negocio no continuó.'],
    self::REJECTED => ['No aprobado', 'La aseguradora no aprobó el estudio. Su consultor/a le indicará las alternativas.'],
    self::DESISTED => ['Desistido', 'El estudio se desistió y no continuará.'],
  ];

  private CommercialStudiesRepository $repository;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private ?CommercialStudiesNotifier $notifier;

  public function __construct(CommercialStudiesRepository $repository, Settings $settings, CommercialAccessPolicy $policy, ?CommercialStudiesNotifier $notifier = null)
  {
    $this->repository = $repository;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->notifier = $notifier;
  }

  // ── Configuración ───────────────────────────────────

  /** @return array{responsable_entrega:string} Lo que el negocio cambia en Configuración › Actividades comerciales › Estudios. */
  public static function settingsFrom(Settings $settings): array
  {
    $raw = $settings->get(self::SETTING, []);
    $raw = is_array($raw) ? $raw : [];
    return ['responsable_entrega' => is_scalar($raw['responsable_entrega'] ?? null) ? trim((string) $raw['responsable_entrega']) : ''];
  }

  /** @return array<string,mixed> Para la sección de Configuración: lo guardado, quién atiende hoy y los funcionarios activos. */
  public static function settingsView(Settings $settings, CommercialStudiesRepository $repository): array
  {
    $saved = self::settingsFrom($settings);
    $automatic = $repository->lastDeliveryResponsible();
    $employee = $automatic !== '' ? $repository->employee($automatic) : null;
    return $saved + [
      'automatico' => $employee !== null ? trim((string) ($employee['nombre'] ?? '')) : '',
      'empleados' => $repository->activeEmployees(),
    ];
  }

  /** @param array<string,mixed> $data */
  public static function saveSettings(Settings $settings, CommercialStudiesRepository $repository, array $data, int $userId): void
  {
    $id = is_scalar($data['responsable_entrega'] ?? null) ? trim((string) $data['responsable_entrega']) : '';
    if ($id !== '' && !in_array($id, array_column($repository->activeEmployees(), 'id'), true)) {
      throw new \InvalidArgumentException('Elige un funcionario activo como responsable de las entregas.');
    }
    $settings->set(self::SETTING, ['responsable_entrega' => $id], $userId);
    $settings->refresh();
  }

  /**
   * Funcionario que recibe el caso de entrega de un estudio aprobado: el de Configuración o, sin elegir, quien atendió
   * la última entrega de un estudio.
   * @return array<string,mixed>|null
   */
  public function deliveryResponsible(): ?array
  {
    $id = self::settingsFrom($this->settings)['responsable_entrega'] ?: $this->repository->lastDeliveryResponsible();
    $employee = $id !== '' ? $this->repository->employee($id) : null;
    return $employee !== null && trim((string) ($employee['activo'] ?? 'Si')) === 'Si' ? $employee : null;
  }

  // ── Permisos ────────────────────────────────────────

  public function canCreate(): bool
  {
    return $this->policy->canAct(self::ACTION_CREATE) && $this->policy->canAct('ver_ticket');
  }

  public function canRespond(): bool
  {
    return $this->policy->canAct(self::ACTION_RESPOND) && $this->policy->canAct('ver_ticket');
  }

  public function canDesist(): bool
  {
    return $this->policy->canAct(self::ACTION_DESIST);
  }

  /** Alguna de las acciones de la herramienta: con ella sale el botón en Gestión operativa. */
  public function canUseTool(): bool
  {
    return $this->canCreate() || $this->canRespond() || $this->canDesist();
  }

  private function require(bool $allowed, string $message): void
  {
    if (!$allowed) {
      throw new \RuntimeException($message);
    }
  }

  /**
   * La herramienta sale en las tareas de arriendo; en otra tarea solo si ya tiene estudios (para responderlos).
   * @param array<string,mixed> $task
   */
  public function taskAllows(array $task): bool
  {
    return CommercialTaskTools::allowsTask(CommercialTaskTools::STUDY, $task) || $this->repository->forTask((int) ($task['_ID'] ?? 0)) !== [];
  }

  /** @return array<string,mixed> */
  private function taskOrFail(int $ticketPk): array
  {
    $task = $this->repository->task($ticketPk);
    if ($task === null) {
      throw new \InvalidArgumentException('La tarea no existe.');
    }
    if (!$this->taskAllows($task)) {
      throw new \RuntimeException(CommercialTaskTools::deniedMessage(CommercialTaskTools::STUDY, $task));
    }
    return $task;
  }

  /**
   * El estudio, si la persona puede abrirlo: de una tarea que ya se le validó ($ticketPk > 0), el suyo, o cualquiera si ve
   * todo el equipo en Estudios.
   * @return array<string,mixed>
   */
  public function studyFor(int $id, int $ticketPk = 0): array
  {
    $study = $this->repository->find($id);
    if ($study === null) {
      throw new \InvalidArgumentException('El estudio no existe.');
    }
    if ($ticketPk > 0) {
      if ((int) ($study['id_ticket'] ?? 0) !== $ticketPk) {
        throw new \RuntimeException('Este estudio no es de la tarea abierta.');
      }
      return $study;
    }
    $own = trim((string) ($study['id_empleado'] ?? '')) !== '' && trim((string) $study['id_empleado']) === trim(Auth::employeeId());
    if (!$this->policy->canView(self::VIEW) || (!$own && !$this->policy->canSeeAll(self::VIEW))) {
      throw new \RuntimeException('No tienes permiso para ver este estudio.');
    }
    return $study;
  }

  // ── Popup de la tarea ───────────────────────────────

  /** @return array<string,mixed> */
  public function formData(int $ticketPk): array
  {
    $this->require($this->canUseTool(), 'No tienes permiso para gestionar estudios de aseguradora.');
    $task = $this->taskOrFail($ticketPk);
    $studies = array_map(fn(array $row): array => $this->listRow($row), $this->repository->forTask($ticketPk));
    return [
      'ticket_pk' => $ticketPk,
      'asunto' => trim((string) ($task['asunto'] ?? '')),
      'cliente' => trim((string) ($task['solicitante'] ?? '')),
      'email' => trim((string) ($task['correo_solicitante'] ?? '')),
      'celular' => trim((string) ($task['celular_wsp'] ?? '')) ?: trim((string) ($task['celular_solicitante'] ?? '')),
      'aseguradoras' => $this->repository->insurers(),
      'studies' => $studies,
      'motivos' => $this->repository->desistReasons(),
      'servicios' => $this->repository->utilities(),
      'can' => ['crear' => $this->canCreate(), 'responder' => $this->canRespond(), 'desistir' => $this->canDesist()],
      'responsable_entrega' => ($responsible = $this->deliveryResponsible()) !== null ? trim((string) ($responsible['nombre'] ?? '')) : '',
    ];
  }

  /**
   * Crea el estudio (Esperando respuesta), pasa la tarea a "En estudio" y avisa al cliente y al equipo.
   * @param array<string,mixed> $input
   * @return array{id:int,url:string,message:string,enviado:array{correo:bool,whatsapp:bool}}
   */
  public function create(int $ticketPk, array $input): array
  {
    $this->require($this->canCreate(), 'No tienes permiso para crear estudios de aseguradora.');
    $task = $this->taskOrFail($ticketPk);
    $data = $this->baseData($input);
    foreach ($this->repository->forTask($ticketPk) as $row) {
      if (mb_strtolower(trim((string) ($row['aseguradora'] ?? '')), 'UTF-8') === mb_strtolower($data['aseguradora'], 'UTF-8') && trim((string) ($row['num_solicitud'] ?? '')) === $data['num_solicitud']) {
        throw new \InvalidArgumentException('Esta tarea ya tiene el estudio #' . (int) $row['_ID'] . ' con la solicitud ' . $data['num_solicitud'] . ' de ' . $data['aseguradora'] . '.');
      }
    }
    $actor = $this->actor();
    $now = time();
    $common = $this->common($actor, $now);
    $study = $common + $data + [
      'fecha' => $now,
      'hora_estudio' => date('H:i', $now),
      'estado' => self::WAITING,
      'id_empleado' => $actor['id'],
      'creador' => $actor['nombre'],
      'email_creador' => $actor['correo'],
      'celular_creador' => $actor['celular'],
      'sucursal' => $actor['sucursal'],
      'id_ticket' => (string) $ticketPk,
      'id_cliente' => trim((string) ($task['id_solicitante'] ?? $task['id_cliente'] ?? '')),
      'medio' => trim((string) ($task['medio'] ?? '')),
    ];

    $pdo = $this->repository->database()->pdo();
    $pdo->beginTransaction();
    try {
      $id = $this->repository->insertStudy($study);
      if ($id <= 0) {
        throw new \RuntimeException('No se pudo guardar el estudio.');
      }
      $url = PublicStudyAccess::url($id);
      $this->repository->insertTaskHistory($common + [
        'id_ticket' => $ticketPk, 'fecha' => $now, 'nombre' => $actor['nombre'], 'correo' => $actor['correo'], 'celular' => $actor['celular'], 'id_empleado' => $actor['id'],
        'respuesta' => 'Cordial saludo, ' . $data['cliente'] . '. Sus documentos fueron enviados a la aseguradora ' . $data['aseguradora'] . ' para el estudio #' . $id
          . ' (solicitud ' . $data['num_solicitud'] . '). Le informaremos en cuanto tengamos respuesta. Puede consultarlo en: ' . $url,
        'fue_editada' => 'SI', 'id_estudio_aseguradora' => (string) $id,
      ]);
      $this->moveTask($task, self::WAITING, $common, $actor, $now);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    $sent = ['correo' => false, 'whatsapp' => false];
    if ($this->notifier !== null) {
      try {
        $sent = $this->notifier->studyCreated($id, $study, $task, $actor);
      } catch (\Throwable $exception) {
        error_log('[estudios:aviso] ' . $exception->getMessage());
      }
    }
    return ['id' => $id, 'url' => $url, 'enviado' => $sent, 'message' => 'Estudio #' . $id . ' creado. ' . self::sentMessage($sent)];
  }

  /**
   * Corrige los datos del estudio (aseguradora, solicitud, valor y datos del cliente; lo faltante si está aplazado). Si ya
   * tiene caso de entrega y contrato, también les corrige aseguradora y número de solicitud.
   * @param array<string,mixed> $input
   * @return array{id:int,message:string}
   */
  public function update(int $id, int $ticketPk, array $input): array
  {
    $this->require($this->canCreate(), 'No tienes permiso para editar estudios de aseguradora.');
    $this->taskOrFail($ticketPk);
    $study = $this->studyFor($id, $ticketPk);
    if (self::listState((string) ($study['estado'] ?? ''), (string) ($study['estado_contrato'] ?? '')) === 'Negocio desistido' || trim((string) ($study['estado'] ?? '')) === self::DESISTED) {
      throw new \InvalidArgumentException('El estudio #' . $id . ' está desistido: ya no se edita.');
    }
    $data = $this->baseData($input);
    if (trim((string) ($study['estado'] ?? '')) === self::POSTPONED) {
      $missing = self::text($input, 'faltante', 2000);
      if ($missing === '') {
        throw new \InvalidArgumentException('Escribe la información o documentación que falta.');
      }
      $data['faltante'] = $missing;
    }
    $labels = ['aseguradora' => 'aseguradora', 'num_solicitud' => 'número de solicitud', 'valor' => 'valor', 'cliente' => 'cliente', 'correo_cliente' => 'correo', 'celular_cliente' => 'celular', 'faltante' => 'información faltante'];
    $changes = [];
    foreach ($data as $key => $value) {
      if (trim((string) ($study[$key] ?? '')) !== (string) $value) {
        $changes[] = $labels[$key];
      }
    }
    if ($changes === []) {
      return ['id' => $id, 'message' => 'No hubo cambios en el estudio #' . $id . '.'];
    }
    $actor = $this->actor();
    $now = time();
    $common = $this->common($actor, $now);
    $pdo = $this->repository->database()->pdo();
    $pdo->beginTransaction();
    try {
      $this->repository->updateStudy($id, $data + ['cct_modified' => $common['cct_modified']]);
      $sync = ['aseguradora' => $data['aseguradora'], 'numero_solicitud' => $data['num_solicitud'], 'cct_modified' => $common['cct_modified']];
      $this->repository->updateContract((int) ($study['id_contrato_mandato_arrendamiento'] ?? 0), $sync);
      $this->repository->updateTicket((int) ($study['id_ticket_entrega'] ?? 0), ['numero_solicitud' => $data['num_solicitud'], 'cct_modified' => $common['cct_modified']]);
      $this->repository->insertTaskHistory($common + [
        'id_ticket' => $ticketPk, 'fecha' => $now, 'nombre' => $actor['nombre'], 'id_empleado' => $actor['id'], 'id_estudio_aseguradora' => (string) $id,
        'respuesta' => 'Se actualizó el estudio de aseguradora #' . $id . ' (' . implode(', ', $changes) . ').',
      ]);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    return ['id' => $id, 'message' => 'Estudio #' . $id . ' actualizado: ' . implode(', ', $changes) . '.'];
  }

  /**
   * Respuesta de la aseguradora. Aprobado crea el caso de entrega y el contrato "No asignado" y deja el inmueble "En
   * proceso de cierre" (como el formulario de WordPress); todas avisan al cliente y al equipo.
   * @param array<string,mixed> $input
   * @return array{id:int,estado:string,message:string,enviado:array{correo:bool,whatsapp:bool},caso_entrega:int,contrato:int}
   */
  public function respond(int $id, int $ticketPk, array $input): array
  {
    $this->require($this->canRespond(), 'No tienes permiso para responder estudios de aseguradora.');
    $task = $this->taskOrFail($ticketPk);
    $study = $this->studyFor($id, $ticketPk);
    $current = trim((string) ($study['estado'] ?? '')) ?: self::WAITING;
    if (!in_array($current, [self::WAITING, self::POSTPONED], true)) {
      throw new \InvalidArgumentException('El estudio #' . $id . ' ya fue respondido (' . $current . ').');
    }
    $state = self::text($input, 'estado', 40);
    $allowed = $current === self::POSTPONED ? array_merge([self::WAITING], self::RESPONSES) : self::RESPONSES;
    if (!in_array($state, $allowed, true)) {
      throw new \InvalidArgumentException('Elige la respuesta de la aseguradora.');
    }
    $actor = $this->actor();
    $now = time();
    $common = $this->common($actor, $now);
    $update = ['estado' => $state, 'fecha_respuesta' => $now, 'hora_respuesta' => date('H:i', $now), 'cct_modified' => $common['cct_modified']];
    $detail = '';
    $approval = null;
    if ($state === self::POSTPONED) {
      $update['faltante'] = self::text($input, 'faltante', 2000);
      if ($update['faltante'] === '') {
        throw new \InvalidArgumentException('Escribe la información o documentación que falta para obtener la respuesta.');
      }
      $detail = 'Necesitamos: ' . $update['faltante'];
    } elseif ($state === self::DESISTED) {
      $reason = self::text($input, 'motivo', 120);
      if (!in_array($reason, $this->repository->desistReasons(), true)) {
        throw new \InvalidArgumentException('Elige la razón por la que se desiste el estudio.');
      }
      $note = self::text($input, 'observacion', 2000);
      $update['razon_desistido'] = $reason . ($note !== '' ? ' · ' . $note : '');
      $detail = $update['razon_desistido'];
    } elseif ($state === self::REJECTED) {
      $detail = self::text($input, 'observacion', 2000) ?: 'Su consultor/a le indicará las alternativas.';
    } elseif ($state === self::WAITING) {
      $detail = 'Recibimos la información y la aseguradora continúa el estudio.';
    } else {
      $approval = $this->approvalData($input);
      $detail = 'Coordinaremos con usted la entrega del inmueble' . ($approval['fecha_posible_entrega'] > 0 ? ' (fecha posible: ' . date('d/m/Y', $approval['fecha_posible_entrega']) . ')' : '') . '.';
    }

    $ticketId = 0;
    $contractId = 0;
    $pdo = $this->repository->database()->pdo();
    $pdo->beginTransaction();
    try {
      if ($approval !== null) {
        [$ticketId, $contractId] = $this->approve($id, $study, $task, $approval, $actor, $common, $now);
        $update += [
          'id_inmueble' => $approval['property']['codigo'], 'id_inmueble_data' => (string) $approval['property']['_ID'],
          'ubicacion_llaves' => $approval['ubicacion_llaves'], 'persona_llaves' => $approval['persona_llaves'], 'contacto_llaves' => $approval['contacto_llaves'],
          'fecha_posible_entrega' => $approval['fecha_posible_entrega'], 'id_ticket_entrega' => (string) $ticketId,
          'id_contrato_mandato_arrendamiento' => $contractId > 0 ? (string) $contractId : '', 'estado_contrato' => self::CONTRACT_PENDING,
          'medio' => trim((string) ($study['medio'] ?? '')) ?: trim((string) ($task['medio'] ?? '')),
        ];
      }
      $this->repository->updateStudy($id, $update);
      $client = trim((string) ($study['cliente'] ?? '')) ?: trim((string) ($task['solicitante'] ?? ''));
      $this->repository->insertTaskHistory($common + [
        'id_ticket' => $ticketPk, 'fecha' => $now, 'nombre' => $actor['nombre'], 'correo' => $actor['correo'], 'celular' => $actor['celular'], 'id_empleado' => $actor['id'],
        'respuesta' => 'Cordial saludo, ' . $client . '. La aseguradora ' . trim((string) ($study['aseguradora'] ?? '')) . ' actualizó el estudio #' . $id . ': ' . self::CLIENT_STATES[$state][0] . '. ' . $detail
          . ' Puede consultarlo en: ' . PublicStudyAccess::url($id),
        'fue_editada' => 'SI', 'id_estudio_aseguradora' => (string) $id,
      ]);
      $this->moveTask($task, $state, $common, $actor, $now);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    $sent = ['correo' => false, 'whatsapp' => false];
    if ($this->notifier !== null) {
      try {
        $saved = $this->repository->find($id) ?? ($study + $update);
        $sent = $this->notifier->studyAnswered($id, $saved, $task, $actor, $state, $detail, $approval === null ? null : [
          'property' => $approval['property'], 'owner' => $approval['owner'], 'responsable' => $approval['responsable'], 'ticket' => $ticketId, 'contract' => $contractId,
        ]);
      } catch (\Throwable $exception) {
        error_log('[estudios:respuesta] ' . $exception->getMessage());
      }
    }
    $message = 'Estudio #' . $id . ': ' . mb_strtolower(self::CLIENT_STATES[$state][0], 'UTF-8') . '.'
      . ($ticketId > 0 ? ' Se creó el caso de entrega #' . $ticketId . ' para ' . trim((string) ($approval['responsable']['nombre'] ?? '')) . ($contractId > 0 ? ' y el contrato #' . $contractId . ' por entregar' : '') . '; el inmueble quedó "En proceso de cierre".' : '')
      . ' ' . self::sentMessage($sent);
    return ['id' => $id, 'estado' => $state, 'message' => $message, 'enviado' => $sent, 'caso_entrega' => $ticketId, 'contrato' => $contractId];
  }

  /**
   * Inmueble, llaves, fecha, servicios y enlace de Drive de la aprobación, validados.
   * @param array<string,mixed> $input
   * @return array{property:array<string,mixed>,owner:array<string,mixed>,responsable:array<string,mixed>,ubicacion_llaves:string,persona_llaves:string,contacto_llaves:string,fecha_posible_entrega:int,servicios:list<string>,link_drive:string}
   */
  private function approvalData(array $input): array
  {
    $code = self::text($input, 'id_inmueble', 40);
    $property = preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $code) === 1 ? $this->repository->propertyByCode($code) : null;
    if ($property === null) {
      throw new \InvalidArgumentException('Elige el inmueble que se va a entregar.');
    }
    if (!in_array(trim((string) ($property['estado'] ?? '')), CommercialStudiesRepository::DELIVERABLE_STATES, true)) {
      throw new \InvalidArgumentException('El inmueble ' . $code . ' está "' . trim((string) ($property['estado'] ?? '')) . '": solo se entregan inmuebles públicos o en borrador.');
    }
    $owner = $this->repository->owner(trim((string) ($property['id_propietario'] ?? '')));
    if ($owner === null) {
      throw new \InvalidArgumentException('El inmueble ' . $code . ' no tiene propietario registrado: complétalo antes de aprobar el estudio.');
    }
    $responsible = $this->deliveryResponsible();
    if ($responsible === null) {
      throw new \RuntimeException('Falta el responsable de las entregas: elígelo en Configuración › Actividades comerciales › Estudios.');
    }
    $out = [];
    foreach (['ubicacion_llaves' => 'Escribe dónde están las llaves.', 'persona_llaves' => 'Escribe quién tiene las llaves.', 'contacto_llaves' => 'Escribe el teléfono de quien tiene las llaves.'] as $key => $error) {
      $out[$key] = self::text($input, $key, 160);
      if ($out[$key] === '') {
        throw new \InvalidArgumentException($error);
      }
    }
    $out['contacto_llaves'] = (string) preg_replace('/[^0-9+ ]/', '', $out['contacto_llaves']);
    if (strlen((string) preg_replace('/\D/', '', $out['contacto_llaves'])) < 7) {
      throw new \InvalidArgumentException('El teléfono de quien tiene las llaves no es válido.');
    }
    $day = self::text($input, 'fecha_posible_entrega', 10);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1 ? strtotime($day . ' 00:00:00') : false;
    if ($date === false) {
      throw new \InvalidArgumentException('Elige la fecha posible de entrega.');
    }
    $link = self::text($input, 'link_drive', 500);
    if (filter_var($link, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $link) !== 1) {
      throw new \InvalidArgumentException('Pega el enlace de Drive con los recibos de servicios públicos y el asegurable en PDF.');
    }
    $raw = $input['servicios_publicos'] ?? [];
    $services = array_values(array_intersect($this->repository->utilities(), array_map(static fn($value): string => is_scalar($value) ? trim((string) $value) : '', is_array($raw) ? $raw : [])));
    return $out + ['property' => $property, 'owner' => $owner, 'responsable' => $responsible, 'fecha_posible_entrega' => $date, 'servicios' => $services, 'link_drive' => $link];
  }

  /**
   * Caso de entrega, contrato "No asignado", inmueble en proceso de cierre e historiales (dentro de la transacción).
   * @param array<string,mixed> $study
   * @param array<string,mixed> $task
   * @param array<string,mixed> $approval
   * @param array<string,string> $actor
   * @param array<string,mixed> $common
   * @return array{0:int,1:int} Caso de entrega y contrato.
   */
  private function approve(int $id, array $study, array $task, array $approval, array $actor, array $common, int $now): array
  {
    $property = $approval['property'];
    $owner = $approval['owner'];
    $responsible = $approval['responsable'];
    $code = trim((string) $property['codigo']);
    $propertyId = (int) $property['_ID'];
    $ownerId = (string) (int) $owner['_ID'];
    $ownerData = ['propietario' => trim((string) ($owner['nombre'] ?? '')), 'correo_propietario' => trim((string) ($owner['correo'] ?? '')), 'celular_propietario' => trim((string) ($owner['celular'] ?? ''))];
    $address = trim((string) ($property['direccion_fisica'] ?? '')) ?: trim((string) ($property['direccion'] ?? ''));
    $responsibleName = trim((string) ($responsible['nombre'] ?? ''));
    $services = serialize($approval['servicios']);
    $number = trim((string) ($study['num_solicitud'] ?? ''));
    $insurer = trim((string) ($study['aseguradora'] ?? ''));
    $medio = trim((string) ($study['medio'] ?? '')) ?: trim((string) ($task['medio'] ?? ''));

    $ticketId = $this->repository->insertTicket($common + $ownerData + [
      'id_estudio_aseguradora' => (string) $id, 'id_inmueble' => $code, 'id_inmueble_data' => (string) $propertyId, 'id_propietario' => $ownerId, 'id_solicitante' => $ownerId,
      'solicitante' => $ownerData['propietario'], 'correo_solicitante' => $ownerData['correo_propietario'], 'celular_solicitante' => $ownerData['celular_propietario'],
      'ubicacion_llaves' => $approval['ubicacion_llaves'], 'persona_llaves' => $approval['persona_llaves'], 'contacto_llaves' => $approval['contacto_llaves'],
      'sucursal' => $actor['sucursal'], 'id_empleado' => trim((string) $responsible['id_empleado']), 'empleado' => $responsibleName, 'nombre_empleado' => $responsibleName,
      'correo_empleado' => trim((string) ($responsible['correo'] ?? '')), 'celular_empleado' => trim((string) ($responsible['celular'] ?? '')),
      'barrio' => trim((string) ($property['barrio'] ?? '')), 'direccion' => $address, 'fecha' => $now,
      'prioridad' => 'Prioridad urgente', 'departamento' => 'Servicio al propietario', 'tema_ayuda' => CommercialStudiesRepository::DELIVERY_TOPIC,
      'inmueble' => 'No asignado', 'contrato' => 'No asignado', 'asunto' => 'REVISION ANTES DE LA OCUPACION',
      'descripcion' => '<p>Reciba un cordial saludo. Hemos generado este caso a fin de revisar el inmueble antes de la ocupación; ' . htmlspecialchars($responsibleName, ENT_QUOTES, 'UTF-8') . ' estará en contacto con usted para coordinar la cita.</p>',
      'numero_solicitud' => $number, 'consultor_entrega' => $actor['nombre'], 'consultor_entrega_correo' => $actor['correo'], 'consultor_entrega_celular' => $actor['celular'],
      'fecha_posible_entrega' => $approval['fecha_posible_entrega'], 'anexos_entrega' => $approval['link_drive'],
      'estado' => 'Nuevo', 'estado_administrativo' => 'Nuevo', 'estado_rev_entrega' => 'No', 'estado_inventario' => 'No', 'estado_acta_entrega' => 'No',
      'tuvo_seguimiento' => 'No', 'tuvo_reporte' => 'No', 'creador_por' => 'Funcionario',
    ]);
    if ($ticketId <= 0) {
      throw new \RuntimeException('No se pudo crear el caso de entrega.');
    }
    $contractId = $this->repository->insertContract($common + $ownerData + [
      'id_estudio_aseguradora' => (string) $id, 'id_inmueble' => $code, 'id_inmueble_data' => (string) $propertyId, 'sucursal' => $actor['sucursal'],
      'barrio' => trim((string) ($property['barrio'] ?? '')), 'tipo_inmueble' => trim((string) ($property['tipo_inmueble'] ?? '')),
      'tiene_parqueadero' => trim((string) ($property['parqueadero'] ?? '')), 'tipo_parqueadero' => trim((string) ($property['tipo_parqueadero'] ?? '')),
      'parqueaderos' => trim((string) ($property['parqueaderos'] ?? '')), 'numero_parqueadero' => trim((string) ($property['numero_parqueadero'] ?? '')),
      'tiene_deposito' => trim((string) ($property['deposito'] ?? '')), 'depositos' => trim((string) ($property['depositos'] ?? '')),
      'direccion' => $address, 'id_propietario' => $ownerId, 'inmueble' => 'No asignado', 'contrato' => 'No asignado', 'numero_solicitud' => $number, 'aseguradora' => $insurer,
      'id_ticket_entrega' => (string) $ticketId, 'id_ticket_cliente' => (string) (int) $task['_ID'], 'servicios_publicos' => $services, 'medio' => $medio,
      'estrato' => trim((string) ($property['estrato'] ?? '')), 'tipo' => 'Nuevo', 'estado' => self::CONTRACT_PENDING, 'registro_fotografico' => 'No asignado',
      'id_arrendatario' => 'No asignado', 'arrendatario' => 'No asignado', 'documento_arrendatario' => 'No asignado', 'correo_arrendatario' => 'No asignado', 'celular_arrendatario' => '0',
    ]);
    if ($contractId > 0) {
      $this->repository->updateTicket($ticketId, ['id_contrato' => (string) $contractId]);
    }
    $this->repository->insertTaskHistory($common + [
      'id_ticket' => $ticketId, 'fecha' => $now, 'nombre' => $actor['nombre'], 'correo' => $actor['correo'], 'celular' => $actor['celular'], 'id_empleado' => $actor['id'],
      'id_estudio_aseguradora' => (string) $id,
      'respuesta' => 'Caso creado al aprobar el estudio de aseguradora #' . $id . ' (' . $insurer . ', solicitud ' . $number . ') de la tarea comercial #' . (int) $task['_ID']
        . '. Revisión antes de la ocupación. Llaves: ' . $approval['ubicacion_llaves'] . ', ' . $approval['persona_llaves'] . ' (' . $approval['contacto_llaves'] . ').',
    ]);
    $this->repository->updateProperty($propertyId, ['estado' => 'En proceso de cierre', 'id_estudio_aseguradora' => (string) $id, 'id_contrato_arrendamiento' => $contractId > 0 ? (string) $contractId : '', 'servicios_publicos' => $services, 'cct_modified' => $common['cct_modified']]);
    if (ctype_digit($code)) {
      $this->repository->setPostMeta((int) $code, 'estado', 'En proceso de cierre');
      $this->repository->setPostMeta((int) $code, 'id_estudio_aseguradora', (string) $id);
      if ($contractId > 0) {
        $this->repository->setPostMeta((int) $code, 'id_contrato_arrendamiento', (string) $contractId);
      }
    }
    $this->repository->insertPropertyHistory($common + [
      'id_inmueble' => $code, 'id_inmueble_data' => (string) $propertyId, 'funcionario' => $actor['nombre'], 'fecha' => $now, 'id_empleado' => $actor['id'], 'id_ticket' => (string) $ticketId,
      'tipo_reporte' => 'Ticket', 'observacion' => 'Se aprobó el estudio de aseguradora #' . $id . ' y se creó el caso #' . $ticketId . ' para la revisión antes de la ocupación.',
    ]);
    return [$ticketId, $contractId];
  }

  /**
   * Desiste el negocio de un estudio aprobado que todavía no se entrega. Copia de ClosingSheetDesist::desist del panel
   * inmobiliario: contrato "No asignado" desistido, inmueble "En borrador", estudio aprobado con el contrato desistido (o de
   * nuevo "Esperando respuesta" si es cambio de inmueble) y caso de entrega cerrado como desistido. La tarea comercial se
   * cierra como Desistido, salvo en el cambio de inmueble, que vuelve a "En estudio".
   * @param array<string,mixed> $input
   * @return array{id:int,message:string}
   */
  public function desist(int $id, array $input, int $ticketPk = 0): array
  {
    $this->require($this->canDesist(), 'No tienes permiso para desistir negocios.');
    $study = $this->studyFor($id, $ticketPk);
    if (!self::desistable($study)) {
      throw new \InvalidArgumentException(self::listState((string) ($study['estado'] ?? ''), (string) ($study['estado_contrato'] ?? '')) === 'Negocio desistido'
        ? 'El negocio del estudio #' . $id . ' ya está desistido.'
        : 'Solo se desiste el negocio de un estudio aprobado que todavía no se entrega.');
    }
    if ((string) ($input['confirmar'] ?? '') !== '1') {
      throw new \InvalidArgumentException('Confirma que quieres desistir el negocio.');
    }
    $reason = self::text($input, 'motivo', 120);
    if (!in_array($reason, $this->repository->desistReasons(), true)) {
      throw new \InvalidArgumentException('Selecciona el motivo por el que se desiste el negocio.');
    }
    $note = self::text($input, 'observacion', 2000);
    $changeProperty = $reason === self::CHANGE_PROPERTY;
    $contractId = (int) ($study['id_contrato_mandato_arrendamiento'] ?? 0);
    $contract = $this->repository->contract($contractId);
    $number = mb_strtolower(trim((string) ($contract['contrato'] ?? '')), 'UTF-8');
    if ($contract !== null && $number !== '' && $number !== 'no asignado' && mb_strtolower(trim((string) ($contract['estado'] ?? '')), 'UTF-8') !== 'desistido') {
      throw new \InvalidArgumentException('El contrato #' . trim((string) $contract['contrato']) . ' ya se creó: desístelo desde Contratos de arrendamiento del panel inmobiliario con "Marcar desistido".');
    }
    $task = $this->repository->task((int) ($study['id_ticket'] ?? 0));
    $actor = $this->actor();
    $now = time();
    $common = $this->common($actor, $now);
    $summary = 'Negocio desistido desde el estudio de aseguradora #' . $id . '. Motivo: ' . $reason . '.' . ($note !== '' ? ' Observación: ' . $note : '');
    $code = trim((string) ($study['id_inmueble'] ?? ''));
    $propertyId = (int) ($study['id_inmueble_data'] ?? 0);
    $deliveryId = (int) ($study['id_ticket_entrega'] ?? 0);
    $delivery = $deliveryId > 0 ? $this->repository->task($deliveryId) : null;

    $pdo = $this->repository->database()->pdo();
    $pdo->beginTransaction();
    try {
      // 1. Contrato "No asignado" del estudio.
      if ($contract !== null && mb_strtolower(trim((string) ($contract['estado'] ?? '')), 'UTF-8') !== 'desistido') {
        $this->repository->updateContract($contractId, ['estado' => self::DESISTED, 'razon_desistido' => $reason, 'cct_modified' => $common['cct_modified']]);
        $this->repository->insertContractHistory($common + [
          'fecha' => (string) $now, 'id_empleado' => $actor['id'], 'funcionario' => $actor['nombre'], 'tipo_reporte' => 'Contrato desistido',
          'observacion' => $summary . ' Estado anterior: ' . (trim((string) ($contract['estado'] ?? '')) ?: 'sin estado') . '.',
          'id_inmueble' => (string) ($contract['id_inmueble'] ?? $code), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? $propertyId),
          'inmueble' => (string) ($contract['inmueble'] ?? ''), 'contrato' => (string) ($contract['contrato'] ?? ''), 'id_contrato' => (string) $contractId,
        ]);
      }
      // 2 y 3. El inmueble vuelve a borrador (post de WordPress y CCT).
      if (ctype_digit($code)) {
        $this->repository->setPostMeta((int) $code, 'estado', 'En borrador');
      }
      $this->repository->updateProperty($propertyId, ['estado' => 'En borrador', 'cct_modified' => $common['cct_modified']]);
      // 4. Estudio: aprobado con el contrato desistido, o de nuevo por responder si cambia de inmueble.
      $this->repository->updateStudy($id, [
        'estado' => $changeProperty ? self::WAITING : self::APPROVED, 'estado_contrato' => $changeProperty ? '' : self::DESISTED,
        'razon_desistido' => $reason . ($note !== '' ? ' · ' . $note : ''), 'cct_modified' => $common['cct_modified'],
      ]);
      // 5. Caso de entrega: cerrado como desistido (así lo hace el formulario de WordPress), con su historial.
      if ($delivery !== null) {
        $this->repository->updateTicket($deliveryId, ['estado' => 'Cerrado', 'estado_administrativo' => self::DESISTED, 'fecha_actualizacion' => $now, 'cct_modified' => $common['cct_modified']]);
        $this->repository->insertTaskHistory($common + [
          'id_ticket' => $deliveryId, 'id_empleado' => $actor['id'], 'nombre' => $actor['nombre'], 'correo' => $actor['correo'], 'fecha' => $now, 'id_estudio_aseguradora' => (string) $id,
          'respuesta' => '<p>' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>',
        ]);
      }
      // 6. La tarea comercial: desistida, o de nuevo en estudio para el cambio de inmueble.
      if ($task !== null) {
        $this->moveTask($task, $changeProperty ? self::WAITING : self::DESISTED, $common, $actor, $now, $summary . ($changeProperty ? ' El estudio volvió a "Esperando respuesta" para elegir otro inmueble.' : ''));
      }
      if ($code !== '') {
        $this->repository->insertPropertyHistory($common + [
          'id_inmueble' => $code, 'id_inmueble_data' => (string) $propertyId, 'id_empleado' => $actor['id'], 'funcionario' => $actor['nombre'], 'fecha' => $now,
          'tipo_reporte' => 'Contractual', 'observacion' => $summary . ' El inmueble volvió a "En borrador".',
        ]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    if ($this->notifier !== null) {
      try {
        $this->notifier->businessDesisted($id, $this->repository->find($id) ?? $study, $actor, $reason, $note, $changeProperty, $delivery);
      } catch (\Throwable $exception) {
        error_log('[estudios:desistido] ' . $exception->getMessage());
      }
    }
    return ['id' => $id, 'message' => ($contract !== null ? 'El contrato #' . $contractId . ' quedó desistido, el' : 'El') . ' inmueble volvió a "En borrador"'
      . ', el estudio quedó ' . ($changeProperty ? '"Esperando respuesta" para el cambio de inmueble' : 'aprobado con el negocio desistido')
      . ($delivery !== null ? ', el caso de entrega #' . $deliveryId . ' se cerró' : '') . ($task !== null ? ' y la tarea #' . (int) $task['_ID'] . ($changeProperty ? ' volvió a "En estudio"' : ' quedó desistida') : '') . '.'];
  }

  /** @param array<string,mixed> $study */
  public static function desistable(array $study): bool
  {
    return self::listState((string) ($study['estado'] ?? ''), (string) ($study['estado_contrato'] ?? '')) === self::APPROVED && (int) ($study['id_ticket_entrega'] ?? 0) > 0;
  }

  /** @param array<string,mixed> $study */
  public static function respondable(array $study): bool
  {
    return in_array(trim((string) ($study['estado'] ?? '')) ?: self::WAITING, [self::WAITING, self::POSTPONED], true);
  }

  /** @param array<string,mixed> $study */
  public static function editable(array $study): bool
  {
    $state = self::listState((string) ($study['estado'] ?? ''), (string) ($study['estado_contrato'] ?? ''));
    return !in_array($state, [self::DESISTED, 'Negocio desistido'], true);
  }

  // ── Listado ─────────────────────────────────────────

  /** @param array<string,mixed> $input */
  public function seesTeam(array $input): bool
  {
    return $this->policy->seesTeam(self::VIEW, $input);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{rows:list<array<string,mixed>>,total:int,estados:array<string,int>,page:int,per_page:int,filters:array<string,string>,team:bool,mine:bool}
   */
  public function listing(array $input): array
  {
    if (!$this->policy->canView(self::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver los estudios de aseguradora.');
    }
    $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? mb_substr(trim((string) $input[$key]), 0, 120, 'UTF-8') : '';
    $filters = [
      'q' => $text('es_q'), 'estado' => in_array($text('es_estado'), self::LIST_STATES, true) ? $text('es_estado') : '', 'aseguradora' => $text('es_aseguradora'),
      'empleado' => $text('es_empleado'), 'desde' => $text('es_desde'), 'hasta' => $text('es_hasta'),
    ];
    $team = $this->seesTeam($input);
    $mine = (string) ($input['portafolio'] ?? '') === '1';
    $page = max(1, (int) $text('es_pag'));
    $scope = $team ? null : trim(Auth::employeeId());
    if ($scope === '') {
      // Si no se sabe quién es, no se muestra nada.
      return ['rows' => [], 'total' => 0, 'estados' => [], 'page' => 1, 'per_page' => self::LIST_PER_PAGE, 'filters' => $filters, 'team' => $team, 'mine' => $mine];
    }
    if (!$team) {
      $filters['empleado'] = '';
    }
    $result = $this->repository->search($filters, $scope, $page, self::LIST_PER_PAGE);
    return [
      'rows' => array_map(fn(array $row): array => $this->listRow($row), $result['rows']),
      'total' => $result['total'],
      'estados' => $result['estados'],
      'page' => $page,
      'per_page' => self::LIST_PER_PAGE,
      'filters' => $filters,
      'team' => $team,
      'mine' => $mine,
    ];
  }

  /** @return array<string,string> */
  public function creators(): array
  {
    return $this->repository->creators();
  }

  /** @return list<string> */
  public function insurers(): array
  {
    return $this->repository->insurers();
  }

  /** @return list<string> */
  public function desistReasons(): array
  {
    return $this->repository->desistReasons();
  }

  /**
   * Inmuebles que se pueden entregar, para el buscador de la aprobación.
   * @return list<array{codigo:string,titulo:string,detalle:string,servicios:list<string>}>
   */
  public function searchProperties(string $q): array
  {
    $this->require($this->canRespond(), 'No tienes permiso para responder estudios de aseguradora.');
    $out = [];
    foreach ($this->repository->deliverableProperties($q) as $row) {
      $services = @unserialize((string) ($row['servicios_publicos'] ?? ''), ['allowed_classes' => false]);
      $out[] = [
        'codigo' => trim((string) $row['codigo']),
        'titulo' => trim((string) ($row['tipo_inmueble'] ?? 'Inmueble')) . ' · ' . (trim((string) ($row['direccion_fisica'] ?? '')) ?: trim((string) ($row['direccion'] ?? '')) ?: 'Sin dirección'),
        'detalle' => implode(' · ', array_filter([trim((string) ($row['barrio'] ?? '')), trim((string) ($row['estado'] ?? '')), trim((string) ($row['propietario'] ?? '')) !== '' ? 'Propietario: ' . trim((string) $row['propietario']) : 'Sin propietario'])),
        'servicios' => is_array($services) ? array_values(array_map('strval', $services)) : [],
      ];
    }
    return $out;
  }

  // ── Vista ───────────────────────────────────────────

  /**
   * Todo lo que muestra estudio.php. Para el cliente no incluye datos internos (llaves, propietario, Drive, casos);
   * con $internal (sesión del panel) sí.
   * @return array<string,mixed>|null
   */
  public function viewData(int $id, bool $internal = false): ?array
  {
    $study = $this->repository->find($id);
    if ($study === null) {
      return null;
    }
    $state = self::listState((string) ($study['estado'] ?? ''), (string) ($study['estado_contrato'] ?? ''));
    $task = $this->repository->task((int) ($study['id_ticket'] ?? 0)) ?? [];
    $creator = $this->repository->employee(trim((string) ($study['id_empleado'] ?? ''))) ?? [];
    $assigned = trim((string) ($task['nombre_empleado'] ?? '')) !== ''
      ? ['nombre' => trim((string) $task['nombre_empleado']), 'correo' => trim((string) ($task['correo_empleado'] ?? '')), 'celular' => trim((string) ($task['celular_empleado'] ?? ''))]
      : ['nombre' => trim((string) ($creator['nombre'] ?? '')) ?: trim((string) ($study['creador'] ?? '')) ?: 'Equipo comercial', 'correo' => trim((string) ($creator['correo'] ?? '')) ?: trim((string) ($study['email_creador'] ?? '')), 'celular' => trim((string) ($creator['celular'] ?? '')) ?: trim((string) ($study['celular_creador'] ?? ''))];
    $date = static fn($value): string => (int) $value > 0 ? date('d/m/Y', (int) $value) : '';
    $created = (int) ($study['fecha'] ?? 0) ?: (strtotime((string) ($study['cct_created'] ?? '')) ?: 0);
    $property = null;
    $code = trim((string) ($study['id_inmueble'] ?? ''));
    if ($code !== '' && in_array($state, [self::APPROVED, 'Entregado', 'Negocio desistido'], true)) {
      $row = $this->repository->propertyByCode($code) ?? [];
      $property = [
        'codigo' => $code,
        'tipo' => trim((string) ($row['tipo_inmueble'] ?? '')),
        'barrio' => trim((string) ($row['barrio'] ?? '')),
        'ciudad' => trim((string) ($row['ciudad'] ?? '')),
        'url' => 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-' . rawurlencode($code),
      ];
    }
    $data = [
      'id' => $id,
      'estado' => $state,
      'estado_label' => self::CLIENT_STATES[$state][0] ?? $state,
      'estado_texto' => self::CLIENT_STATES[$state][1] ?? '',
      'fecha' => $date($created),
      'fecha_respuesta' => $date($study['fecha_respuesta'] ?? 0),
      'cliente' => trim((string) ($study['cliente'] ?? '')),
      'aseguradora' => trim((string) ($study['aseguradora'] ?? '')),
      'num_solicitud' => trim((string) ($study['num_solicitud'] ?? '')),
      'valor' => self::amount((string) ($study['valor'] ?? '')) > 0 ? QuoteComparison::money(self::amount((string) $study['valor'])) : '',
      'faltante' => $state === self::POSTPONED ? trim((string) ($study['faltante'] ?? '')) : '',
      'razon' => in_array($state, [self::DESISTED, 'Negocio desistido'], true) ? trim((string) ($study['razon_desistido'] ?? '')) : '',
      'fecha_entrega' => $property !== null ? $date($study['fecha_posible_entrega'] ?? 0) : '',
      'inmueble' => $property,
      'consultor' => $assigned,
      'interno' => null,
    ];
    if ($internal) {
      $delivery = (int) ($study['id_ticket_entrega'] ?? 0) > 0 ? $this->repository->task((int) $study['id_ticket_entrega']) : null;
      $data['interno'] = [
        'tarea' => (int) ($study['id_ticket'] ?? 0),
        'creador' => trim((string) ($creator['nombre'] ?? '')) ?: trim((string) ($study['creador'] ?? '')),
        'hora_estudio' => trim((string) ($study['hora_estudio'] ?? '')),
        'correo_cliente' => trim((string) ($study['correo_cliente'] ?? '')),
        'celular_cliente' => trim((string) ($study['celular_cliente'] ?? '')),
        'medio' => trim((string) ($study['medio'] ?? '')),
        'llaves' => implode(' · ', array_filter([trim((string) ($study['ubicacion_llaves'] ?? '')), trim((string) ($study['persona_llaves'] ?? '')), trim((string) ($study['contacto_llaves'] ?? ''))])),
        'caso_entrega' => (int) ($study['id_ticket_entrega'] ?? 0),
        'responsable_entrega' => trim((string) ($delivery['nombre_empleado'] ?? '')),
        'estado_entrega' => trim((string) ($delivery['estado_administrativo'] ?? '')) ?: trim((string) ($delivery['estado'] ?? '')),
        'anexos' => trim((string) ($delivery['anexos_entrega'] ?? '')),
        'propietario' => trim((string) ($delivery['propietario'] ?? '')),
        'contrato' => (int) ($study['id_contrato_mandato_arrendamiento'] ?? 0),
        'mandato' => (int) ($study['id_contrato_mandato'] ?? 0),
        'estado_contrato' => trim((string) ($study['estado_contrato'] ?? '')),
        'enlace_cliente' => PublicStudyAccess::url($id),
      ];
    }
    return $data;
  }

  // ── Apoyo ───────────────────────────────────────────

  /** Estado del listado: un aprobado se ve como Entregado o Negocio desistido según el contrato. */
  public static function listState(string $state, string $contract): string
  {
    $state = trim($state) !== '' ? trim($state) : self::WAITING;
    if ($state === self::APPROVED) {
      $contract = mb_strtolower(trim($contract), 'UTF-8');
      if ($contract === 'entregado') {
        return 'Entregado';
      }
      if ($contract === 'desistido') {
        return 'Negocio desistido';
      }
    }
    return $state;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function listRow(array $row): array
  {
    $created = (int) ($row['fecha'] ?? 0) ?: (strtotime((string) ($row['cct_created'] ?? '')) ?: 0);
    $value = self::amount((string) ($row['valor'] ?? ''));
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'fecha' => $created > 0 ? date('d/m/Y', $created) : '',
      'estado' => self::listState((string) ($row['estado'] ?? ''), (string) ($row['estado_contrato'] ?? '')),
      'aseguradora' => trim((string) ($row['aseguradora'] ?? '')),
      'num_solicitud' => trim((string) ($row['num_solicitud'] ?? '')),
      'valor' => $value > 0 ? QuoteComparison::money($value) : '',
      'valor_raw' => $value > 0 ? (string) (int) $value : '',
      'cliente' => trim((string) ($row['cliente'] ?? '')),
      'correo' => trim((string) ($row['correo_cliente'] ?? '')),
      'celular' => trim((string) ($row['celular_cliente'] ?? '')),
      'faltante' => trim((string) ($row['faltante'] ?? '')),
      'tarea' => (int) ($row['id_ticket'] ?? 0),
      'inmueble' => trim((string) ($row['id_inmueble'] ?? '')),
      'caso_entrega' => (int) ($row['id_ticket_entrega'] ?? 0),
      'asesor' => trim((string) ($row['creador'] ?? '')),
      'propio' => trim((string) ($row['id_empleado'] ?? '')) !== '' && trim((string) $row['id_empleado']) === trim(Auth::employeeId()),
      'respondible' => self::respondable($row),
      'editable' => self::editable($row),
      'desistible' => self::desistable($row),
    ];
  }

  /**
   * Aseguradora, solicitud, valor y datos del cliente, validados.
   * @param array<string,mixed> $input
   * @return array{aseguradora:string,num_solicitud:string,valor:string,cliente:string,correo_cliente:string,celular_cliente:string}
   */
  private function baseData(array $input): array
  {
    $insurer = self::text($input, 'aseguradora', 120);
    $insurers = $this->repository->insurers();
    if ($insurer === '' || ($insurers !== [] && !in_array($insurer, $insurers, true))) {
      throw new \InvalidArgumentException('Elige la aseguradora en la que se hizo el estudio.');
    }
    $number = self::text($input, 'num_solicitud', 60);
    if ($number === '') {
      throw new \InvalidArgumentException('Escribe el número de solicitud que dio la aseguradora.');
    }
    $value = self::amount(self::text($input, 'valor', 30));
    if ($value <= 0) {
      throw new \InvalidArgumentException('Escribe el valor del canon por el que se hace el estudio.');
    }
    $client = self::text($input, 'cliente', 120);
    if ($client === '') {
      throw new \InvalidArgumentException('Escribe el nombre del cliente.');
    }
    $email = self::text($input, 'email', 160);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('El correo del cliente no es válido.');
    }
    $phone = (string) preg_replace('/[^0-9+]/', '', self::text($input, 'celular', 30));
    if ($email === '' && $phone === '') {
      throw new \InvalidArgumentException('Escribe el correo o el celular del cliente para avisarle.');
    }
    return ['aseguradora' => $insurer, 'num_solicitud' => $number, 'valor' => (string) (int) $value, 'cliente' => $client, 'correo_cliente' => $email, 'celular_cliente' => $phone];
  }

  /**
   * Estado comercial y general de la tarea según el estudio, con su historial. estado_administrativo no se toca.
   * @param array<string,mixed> $task
   * @param array<string,mixed> $common
   * @param array<string,string> $actor
   */
  private function moveTask(array $task, string $studyState, array $common, array $actor, int $now, string $note = ''): void
  {
    [$commercial, $general] = self::TASK_STATUS[$studyState];
    $id = (int) $task['_ID'];
    $previous = trim((string) ($task['estado_comercial'] ?? ''));
    $update = ['fecha_actualizacion' => $now];
    if ($previous !== $commercial || trim((string) ($task['estado'] ?? '')) !== $general) {
      $update += ['estado_comercial' => $commercial, 'estado' => $general];
    }
    $this->repository->updateTicket($id, $update);
    if ($previous !== $commercial || $note !== '') {
      $this->repository->insertTaskHistory($common + [
        'id_ticket' => $id, 'fecha' => $now, 'nombre' => $actor['nombre'], 'id_empleado' => $actor['id'],
        'respuesta' => trim($note . ($previous !== $commercial ? ' ' . sprintf('Estado comercial actualizado de "%s" a "%s".', $previous !== '' ? $previous : 'Sin estado', $commercial) : '')),
      ]);
    }
  }

  /** @return array{id:string,nombre:string,correo:string,celular:string,sucursal:string} */
  private function actor(): array
  {
    $id = trim(Auth::employeeId());
    $employee = $this->repository->employee($id);
    if ($employee === null) {
      throw new \RuntimeException('Tu usuario no está vinculado a un funcionario: no se puede guardar el estudio.');
    }
    return [
      'id' => $id,
      'nombre' => trim((string) ($employee['nombre'] ?? '')) ?: Auth::user(),
      'correo' => trim((string) ($employee['correo'] ?? '')),
      'celular' => trim((string) ($employee['celular'] ?? '')),
      'sucursal' => trim((string) ($employee['id_sucursal'] ?? '')),
    ];
  }

  /** @param array<string,string> $actor @return array<string,mixed> */
  private function common(array $actor, int $now): array
  {
    $stamp = date('Y-m-d H:i:s', $now);
    return ['cct_status' => 'publish', 'cct_author_id' => $actor['id'], 'cct_created' => $stamp, 'cct_modified' => $stamp];
  }

  /** @param array{correo:bool,whatsapp:bool} $sent */
  private static function sentMessage(array $sent): string
  {
    $channels = array_keys(array_filter($sent));
    return $channels === [] ? 'No se le pudo avisar al cliente: revisa su correo y su celular.' : 'Se le avisó al cliente por ' . implode(' y ', array_map(static fn(string $channel): string => $channel === 'correo' ? 'correo' : 'WhatsApp', $channels)) . '.';
  }

  /** @param array<string,mixed> $input */
  private static function text(array $input, string $key, int $max): string
  {
    return is_scalar($input[$key] ?? null) ? mb_substr(trim(strip_tags(str_replace("\r\n", "\n", (string) $input[$key]))), 0, $max, 'UTF-8') : '';
  }

  private static function amount(string $value): float
  {
    $digits = (string) preg_replace('/\D/', '', $value);
    return $digits === '' ? 0.0 : (float) $digits;
  }
}
