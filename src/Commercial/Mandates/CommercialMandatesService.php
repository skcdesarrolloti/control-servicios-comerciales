<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\PublicMandateAccess;

/**
 * Contratos de mandato en el panel: la pestaña Mandatos / Mis mandatos y la revisión del propietario antes de la firma.
 *
 * Flujo: el mandato se crea en el panel (tarea o pestaña Mandatos) → el equipo lo envía al propietario
 * (correo y WhatsApp con el enlace de public/mandato.php) → el propietario deja observaciones por sección o lo aprueba
 * → el equipo corrige, responde y lo envía a firma (eSignatures) desde el panel. La revisión es opcional; para la firma
 * es obligatorio que la hoja de cierre esté aprobada (signBlockers).
 * La aprobación guarda la huella del contenido: si el mandato cambia después, vuelve a pedir revisión.
 */
final class CommercialMandatesService
{
  public const VIEW = 'mandatos';
  public const ACTION_SEND = 'mandato_enviar_revision';
  public const ACTION_ANSWER = 'mandato_responder_observaciones';
  public const PER_PAGE = 25;
  public const MAX_NOTE = 2000;
  /** Estados de la hoja de cierre que permiten enviar a firma ("Realizada": aprobada y ya con contrato de arrendamiento). */
  public const CLOSING_APPROVED = ['Aprobada', 'Realizada'];

  /** Estado de la revisión del propietario: etiqueta y tono. */
  public const REVIEW_STATES = [
    'sin_enviar' => ['Sin enviar al propietario', 'off'],
    'enviado' => ['Enviado al propietario', 'wait'],
    'visto' => ['Visto por el propietario', 'wait'],
    'observaciones' => ['Con observaciones', 'warn'],
    'cambio' => ['Cambió después de aprobar', 'warn'],
    'aprobado' => ['Aprobado por el propietario', 'ok'],
    'firmado' => ['Firmado', 'done'],
  ];


  private Database $db;
  private CommercialMandatesRepository $repository;
  private CommercialAccessPolicy $policy;
  private ?CommercialMandatesNotifier $notifier;

  public function __construct(Database $db, CommercialAccessPolicy $policy, ?CommercialMandatesNotifier $notifier = null, ?CommercialMandatesRepository $repository = null)
  {
    $this->db = $db;
    $this->repository = $repository ?? new CommercialMandatesRepository($db);
    $this->policy = $policy;
    $this->notifier = $notifier;
  }

  public function repository(): CommercialMandatesRepository
  {
    return $this->repository;
  }

  /** Puede abrir el mandato: la vista Mandatos y que sea suyo (id_empleado), o ver todo el equipo. @param array<string,mixed> $mandate */
  public function canOpen(array $mandate): bool
  {
    if (!$this->policy->canView(self::VIEW)) {
      return false;
    }
    if ($this->policy->canSeeAll(self::VIEW)) {
      return true;
    }
    $me = trim(Auth::employeeId());
    return $me !== '' && trim((string) ($mandate['id_empleado'] ?? '')) === $me;
  }

  public function canSend(): bool
  {
    return $this->policy->canAct(self::ACTION_SEND);
  }

  public function canAnswer(): bool
  {
    return $this->policy->canAct(self::ACTION_ANSWER);
  }

  /**
   * Estado de la revisión del propietario.
   * @param array<string,mixed> $mandate
   * @param array<string,mixed> $review
   */
  public static function reviewState(array $mandate, array $review, int $pending, ?string $fingerprint = null): string
  {
    if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
      return 'firmado';
    }
    if ($pending > 0) {
      return 'observaciones';
    }
    if (trim((string) ($review['aprobado_at'] ?? '')) !== '') {
      return hash_equals((string) ($review['huella_aprobado'] ?? ''), $fingerprint ?? MandateDocument::fingerprint($mandate)) ? 'aprobado' : 'cambio';
    }
    if ((int) ($review['envios'] ?? 0) <= 0) {
      return 'sin_enviar';
    }
    return trim((string) ($review['visto_at'] ?? '')) !== '' ? 'visto' : 'enviado';
  }

  /**
   * Por qué todavía no se puede enviar a firma (vacío = se puede): la hoja de cierre del negocio debe estar aprobada.
   * La revisión del propietario es opcional (reviewNotice solo lo advierte).
   * @param array{id:int,estado:string} $closing CommercialMandatesRepository::closing()
   * @return list<string>
   */
  public static function signBlockers(string $review, array $closing): array
  {
    if ($review === 'firmado' || in_array($closing['estado'], self::CLOSING_APPROVED, true)) {
      return [];
    }
    return [$closing['estado'] === ''
      ? 'Falta la hoja de cierre del negocio, y debe estar aprobada.'
      : 'La hoja de cierre' . ($closing['id'] > 0 ? ' #' . $closing['id'] : '') . ' está "' . $closing['estado'] . '": debe estar aprobada.'];
  }

  /** Aviso (no obligatorio) de la revisión del propietario antes de enviar a firma; vacío si la aprobó o ya está firmado. */
  public static function reviewNotice(string $review): string
  {
    return [
      'sin_enviar' => 'El propietario no ha revisado el contrato (es opcional: puedes enviárselo con "Revisión del propietario").',
      'enviado' => 'El propietario todavía no ha aprobado el contrato en su revisión.',
      'visto' => 'El propietario abrió el contrato pero todavía no lo ha aprobado.',
      'observaciones' => 'Hay observaciones del propietario sin resolver.',
      'cambio' => 'El mandato cambió después de que el propietario lo aprobó.',
    ][$review] ?? '';
  }

  /**
   * Pestaña Mandatos (todo el equipo si el cargo lo ve) o Mis mandatos (?portafolio=1, siempre los de la persona).
   * @param array<string,mixed> $input
   * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,filters:array{q:string,estado:string,empleado:string},estados:array<string,int>,mine:bool,team:bool,can_create:bool,can_sign:bool}
   */
  public function listing(array $input): array
  {
    if (!$this->policy->canView(self::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver los contratos de mandato.');
    }
    $team = $this->policy->canSeeAll(self::VIEW);
    $mine = (string) ($input['portafolio'] ?? '') === '1' || !$team;
    $clean = static fn(string $key): string => mb_substr(trim((string) ($input[$key] ?? '')), 0, 120);
    $filters = ['q' => $clean('ma_q'), 'estado' => in_array($clean('ma_estado'), CommercialMandatesRepository::STATES, true) ? $clean('ma_estado') : '', 'empleado' => $mine ? '' : $clean('ma_empleado')];
    $employee = $mine ? trim(Auth::employeeId()) : null;
    $page = max(1, (int) ($input['ma_pag'] ?? 1));
    $result = $this->repository->search($filters, $employee, $page, self::PER_PAGE);
    $rows = array_map(fn(array $row): array => $this->summary($row), $result['rows']);
    return [
      'rows' => $rows, 'total' => $result['total'], 'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
      'estados' => $this->repository->stateCounts($filters, $employee), 'mine' => $mine, 'team' => $team,
      'can_create' => $this->policy->canAct(CommercialMandateFormService::ACTION_CREATE), 'can_sign' => $this->policy->canAct(CommercialMandateFormService::ACTION_SIGN),
    ];
  }

  /** Resumen de un mandato para los listados (pestaña y tarea). @param array<string,mixed> $row @return array<string,mixed> */
  public function summary(array $row): array
  {
    $id = (int) $row['_ID'];
    $owners = MandateDocument::owners($row);
    $names = array_values(array_filter(array_map(static fn(array $owner): string => $owner['nombre'], $owners)));
    $review = ['envios' => $row['rev_envios'] ?? 0, 'visto_at' => $row['rev_visto_at'] ?? '', 'aprobado_at' => $row['rev_aprobado_at'] ?? '', 'huella_aprobado' => $row['rev_huella_aprobado'] ?? ''];
    $state = self::reviewState($row, $review, (int) ($row['rev_pendientes'] ?? 0));
    $closing = $this->repository->closing($row);
    $created = trim((string) ($row['cct_created'] ?? ''));
    return [
      'id' => $id,
      'fecha' => $created !== '' && strtotime($created) !== false ? date('d/m/Y', (int) strtotime($created)) : '',
      'estado' => trim((string) ($row['estado'] ?? '')) ?: 'No enviado',
      'tipo' => trim((string) ($row['tipo_contrato'] ?? '')),
      'propietarios' => $names,
      'documento' => MandateDocument::document((string) ($row['tipo_documento_1'] ?? ''), (string) ($row['documento_1'] ?? '')),
      'inmueble' => trim((string) ($row['id_inmueble'] ?? '')),
      'direccion' => MandateDocument::text($row['direccion'] ?? '') ?: MandateDocument::text($row['barrio'] ?? ''),
      'canon' => MandateDocument::money((string) ($row['precio'] ?? '')),
      'consultor' => trim((string) ($row['creador'] ?? '')),
      'revision' => $state,
      'hoja' => $closing,
      'bloqueos' => self::signBlockers($state, $closing),
      'aviso_revision' => self::reviewNotice($state),
      'pendientes' => (int) ($row['rev_pendientes'] ?? 0),
      'envios' => (int) ($row['rev_envios'] ?? 0),
      'pdf' => trim((string) ($row['pdf_firmado'] ?? '')),
      'enlace' => PublicMandateAccess::url($id),
    ];
  }

  /** @return array<string,string> */
  public function creators(): array
  {
    return $this->repository->creators();
  }

  /**
   * Lo que muestra public/mandato.php: el contrato por secciones, la revisión y las observaciones. Con $staff, además
   * el enlace del propietario, los formularios de WordPress y si se puede enviar o responder.
   * @return array<string,mixed>|null
   */
  public function viewData(int $id, bool $staff = false): ?array
  {
    $mandate = $this->repository->find($id);
    if ($mandate === null) {
      return null;
    }
    $review = $this->repository->review($id);
    $notes = $this->repository->notes($id);
    $fingerprint = MandateDocument::fingerprint($mandate);
    $pending = count(array_filter($notes, static fn(array $note): bool => $note['estado'] === 'pendiente'));
    $creator = $this->repository->creator($mandate);
    $owners = array_values(array_filter(array_map(static fn(array $owner): string => $owner['nombre'], MandateDocument::owners($mandate))));
    $revision = self::reviewState($mandate, $review, $pending, $fingerprint);
    $closing = $this->repository->closing($mandate);
    $data = [
      'id' => $id,
      'estado' => trim((string) ($mandate['estado'] ?? '')) ?: 'No enviado',
      'firmado' => trim((string) ($mandate['estado'] ?? '')) === 'Firmado',
      'revision' => $revision,
      'hoja' => $closing,
      'bloqueos' => self::signBlockers($revision, $closing),
      'aviso_revision' => self::reviewNotice($revision),
      'aprobado_por' => trim((string) ($review['aprobado_por'] ?? '')),
      'aprobado_at' => self::when((string) ($review['aprobado_at'] ?? '')),
      'enviado_at' => self::when((string) ($review['enviado_at'] ?? '')),
      'propietarios' => $owners,
      'lugar' => CommercialMandatesNotifier::place($mandate),
      'secciones' => MandateDocument::sections($mandate),
      // El contrato completo (cláusulas y anexos) con los valores que recibirá eSignatures.
      'contrato' => MandateContractText::html($mandate, new MandateCalculator($this->db)),
      'observaciones' => array_map(static fn(array $note): array => [
        'id' => (int) $note['id'], 'seccion' => (string) $note['seccion'], 'nombre' => (string) $note['nombre'], 'mensaje' => (string) $note['mensaje'],
        'estado' => (string) $note['estado'], 'respuesta' => (string) ($note['respuesta'] ?? ''), 'respondido_por' => (string) $note['respondido_por'],
        'fecha' => self::when((string) $note['created_at']), 'respondido_at' => self::when((string) $note['respondido_at']),
        'anterior' => (string) $note['huella'] !== '' && !hash_equals((string) $note['huella'], $fingerprint),
      ], $notes),
      'consultor' => $creator,
      'interno' => null,
    ];
    if ($staff) {
      $data['interno'] = [
        'enlace' => PublicMandateAccess::url($id),
        'envios' => (int) ($review['envios'] ?? 0),
        'enviado_por' => trim((string) ($review['enviado_por_nombre'] ?? '')),
        'visto_at' => self::when((string) ($review['visto_at'] ?? '')),
        'panel' => rtrim(defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : '', '/') . '/index.php?tab=mandatos&abrir_mandato=' . $id,
        'pdf' => trim((string) ($mandate['pdf_firmado'] ?? '')),
        'puede_enviar' => $this->canSend() && trim((string) ($mandate['estado'] ?? '')) !== 'Firmado',
        'puede_responder' => $this->canAnswer(),
        'pendientes' => $pending,
      ];
    }
    return $data;
  }


  /**
   * Envía el enlace de revisión a los propietarios (correo y WhatsApp).
   * @return array{message:string,resultados:list<array{nombre:string,correo:bool,whatsapp:bool}>}
   */
  public function sendToOwners(int $id): array
  {
    $mandate = $this->repository->find($id);
    if ($mandate === null || !$this->canOpen($mandate)) {
      throw new \RuntimeException('No tienes permiso para este mandato.');
    }
    if (!$this->canSend()) {
      throw new \RuntimeException('No tienes permiso para enviar mandatos al propietario.');
    }
    if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
      throw new \InvalidArgumentException('Este mandato ya está firmado: no se envía a revisión.');
    }
    $actor = $this->actor();
    $round = (int) ($this->repository->review($id)['envios'] ?? 0) + 1;
    $results = $this->notifier !== null ? $this->notifier->sendToOwners($id, $mandate, $actor, $round) : [];
    $this->repository->markSent($id, $actor['id'], $actor['nombre'], MandateDocument::fingerprint($mandate));
    $reached = array_filter($results, static fn(array $result): bool => $result['correo'] || $result['whatsapp']);
    return [
      'message' => $results === [] ? 'Se registró el envío.' : ($reached === [] ? 'No se le pudo avisar a ningún propietario: revisa sus correos y celulares en el mandato. Copia el enlace y envíaselo.' : 'Se envió el mandato a revisión del propietario.'),
      'resultados' => $results,
    ];
  }

  /** El propietario abrió el enlace. */
  public function ownerSeen(int $id): void
  {
    $this->repository->markSeen($id);
  }

  /** Observación del propietario (public/mandato.php). */
  public function ownerNote(int $id, string $section, string $name, string $message): void
  {
    $mandate = $this->repository->find($id);
    if ($mandate === null) {
      throw new \InvalidArgumentException('El mandato no existe.');
    }
    if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
      throw new \InvalidArgumentException('Este contrato ya está firmado. Para cualquier cambio, comuníquese con su consultor/a.');
    }
    if (!isset(MandateDocument::SECTIONS[$section])) {
      $section = 'general';
    }
    $name = $this->ownerName($mandate, $name);
    $message = trim(str_replace("\r", '', $message));
    if ($message === '') {
      throw new \InvalidArgumentException('Escriba qué se debe cambiar.');
    }
    if (mb_strlen($message, 'UTF-8') > self::MAX_NOTE) {
      throw new \InvalidArgumentException('La observación es muy larga (máximo ' . self::MAX_NOTE . ' caracteres).');
    }
    $this->repository->addNote($id, $section, $name, $message, MandateDocument::fingerprint($mandate));
    $this->notifier?->ownerNote($id, $mandate, $name, $section, $message);
  }

  /** El propietario aprueba el contrato tal como está ahora. */
  public function ownerApprove(int $id, string $name, bool $confirmed): void
  {
    $mandate = $this->repository->find($id);
    if ($mandate === null) {
      throw new \InvalidArgumentException('El mandato no existe.');
    }
    if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
      throw new \InvalidArgumentException('Este contrato ya está firmado.');
    }
    if (!$confirmed) {
      throw new \InvalidArgumentException('Marque la casilla para confirmar que revisó el contrato.');
    }
    $pending = array_filter($this->repository->notes($id), static fn(array $note): bool => $note['estado'] === 'pendiente');
    if ($pending !== []) {
      throw new \InvalidArgumentException('Tiene observaciones pendientes: su consultor/a debe resolverlas antes de que pueda aprobar el contrato.');
    }
    $name = $this->ownerName($mandate, $name);
    $this->repository->approve($id, $name, MandateDocument::fingerprint($mandate));
    $this->notifier?->ownerApproved($id, $mandate, $name);
  }

  /** Respuesta del equipo a una observación (y, si se pide, la marca resuelta). */
  public function answerNote(int $noteId, string $answer, bool $resolve): int
  {
    $note = $this->repository->note($noteId);
    $mandate = $note !== null ? $this->repository->find((int) $note['mandato_id']) : null;
    if ($note === null || $mandate === null || !$this->canOpen($mandate)) {
      throw new \RuntimeException('No tienes permiso para esta observación.');
    }
    if (!$this->canAnswer()) {
      throw new \RuntimeException('No tienes permiso para responder observaciones de mandatos.');
    }
    $answer = trim(str_replace("\r", '', $answer));
    if ($answer === '' && !$resolve) {
      throw new \InvalidArgumentException('Escribe la respuesta o marca la observación como resuelta.');
    }
    if (mb_strlen($answer, 'UTF-8') > self::MAX_NOTE) {
      throw new \InvalidArgumentException('La respuesta es muy larga (máximo ' . self::MAX_NOTE . ' caracteres).');
    }
    $actor = $this->actor();
    $this->repository->answerNote($noteId, $answer !== '' ? $answer : (string) ($note['respuesta'] ?? ''), $resolve, $actor['id'], $actor['nombre']);
    return (int) $note['mandato_id'];
  }

  /** Quien escribe debe ser uno de los propietarios del mandato. @param array<string,mixed> $mandate */
  private function ownerName(array $mandate, string $name): string
  {
    $owners = array_values(array_filter(array_map(static fn(array $owner): string => $owner['nombre'], MandateDocument::owners($mandate))));
    $name = trim($name);
    if ($owners === []) {
      return $name !== '' ? mb_substr($name, 0, 190) : 'Propietario';
    }
    if (count($owners) === 1) {
      return $owners[0];
    }
    if (!in_array($name, $owners, true)) {
      throw new \InvalidArgumentException('Elija quién escribe.');
    }
    return $name;
  }

  /** @return array{id:string,nombre:string,correo:string,celular:string} */
  private function actor(): array
  {
    $id = trim(Auth::employeeId());
    // Nombre, correo y celular de su ficha de funcionario (id_empleado).
    $card = $this->repository->creator(['id_empleado' => $id]);
    return ['id' => $id, 'nombre' => $card['nombre'] ?: (trim(Auth::user()) ?: 'Equipo comercial'), 'correo' => $card['correo'], 'celular' => $card['celular']];
  }

  private static function when(string $value): string
  {
    $value = trim($value);
    return $value !== '' && strtotime($value) !== false ? date('d/m/Y g:i a', (int) strtotime($value)) : '';
  }
}
