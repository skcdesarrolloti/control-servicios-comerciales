<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Commercial\Medios\MedioCatalog;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Reglas del módulo Llamadas (Actividades comerciales).
 *
 * - El medio se elige al registrar la llamada y no se puede cambiar: las estadísticas cuentan por medio. Si la persona
 *   vuelve por otro medio es otra solicitud: una llamada nueva, con su medio y su tarea, que queda en el historial del contacto.
 * - Si viene del Club PPH se pide la tarjeta del aliado. Aliado y tarjeta quedan en la llamada y en la tarea, con
 *   `bandera` = No, que es como el proceso de puntos sabe que ese cliente dado todavía no sumó.
 * - La tarea hereda el medio de su llamada y una llamada solo crea una tarea.
 * El registro de cliente replica la acción "registro-cliente" del formulario anterior (Arriendo, Venta, Arriendo o venta).
 */
final class CommercialCallsService
{
  public const VIEW = 'llamadas';
  public const PER_PAGE = 24;
  public const PPH_MEDIO = 'Club PPH';

  private const CLIENT_TOPICS = ['Arriendo' => 'Cliente para arriendo', 'Venta' => 'Cliente para venta', 'Arriendo o venta' => 'Cliente para arriendo - venta'];
  private const LISTING_TOPICS = ['Captacion', 'Recaptacion'];
  private const DEFAULT_PRIORITY = 'Prioridad comercial';
  private const DUPLICATE_SECONDS = 60;

  private Database $db;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private CommercialCallsRepository $repository;
  private MedioCatalog $medios;
  /** @var array<string,mixed> */
  private array $config;
  private ?CommercialCallsNotifier $notifier;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, array $config, ?CommercialCallsNotifier $notifier = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->config = $config;
    $this->repository = new CommercialCallsRepository($db);
    $this->medios = new MedioCatalog($db, $settings);
    $this->notifier = $notifier;
  }

  /** El `id_empleado` real de la sesión; vacío si no se sabe quién es. */
  public function me(): string
  {
    $id = trim(Auth::employeeId());
    if ($id !== '' || Auth::userId() <= 0) {
      return $id;
    }
    return trim((string) $this->db->getVar('SELECT `id_empleado` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]));
  }

  public function seesTeam(): bool
  {
    return $this->policy->canSeeAll(self::VIEW);
  }

  public function pphMedio(): string
  {
    return $this->medios->canonical(self::PPH_MEDIO);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{filters:array<string,string>,page:int,per_page:int,total:int,counts:array<string,int>,rows:list<array<string,mixed>>}
   */
  public function listing(array $input): array
  {
    $filters = [];
    foreach (['q', 'medio', 'tema', 'estado', 'empleado', 'desde', 'hasta', 'tarjeta', 'comisionista', 'inmediato', 'mes'] as $key) {
      $value = $input['ll_' . $key] ?? '';
      $filters[$key] = is_scalar($value) ? mb_substr(trim((string) $value), 0, 120) : '';
    }
    $page = max(1, (int) ($input['ll_pag'] ?? 1));
    $team = $this->seesTeam();
    $result = $this->repository->search($filters, $team ? null : $this->me(), $page, self::PER_PAGE, $this->pphMedio());
    $tasks = $this->repository->taskStatuses(array_map(static fn(array $row): string => trim((string) ($row['id_ticket'] ?? '')), $result['rows']));
    $names = $this->repository->employeeNames();
    $me = $this->me();
    $rows = array_map(function (array $row) use ($tasks, $names, $me, $team): array {
      $task = self::taskId($row);
      return [
        'id' => (int) $row['_ID'],
        'fecha' => self::dateLabel($row['fecha'] ?? 0),
        'cliente' => (string) ($row['cliente'] ?? ''),
        'celular' => (string) ($row['celular'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'tema' => (string) ($row['tema_ayuda'] ?? ''),
        'tipo' => (string) ($row['tipo_inmueble'] ?? ''),
        'inmueble' => (string) ($row['id_inmueble'] ?? ''),
        'medio' => (string) ($row['medio'] ?? ''),
        'aliado' => (string) ($row['nombre_pph'] ?? ''),
        'tarjeta' => (string) ($row['tarjeta_pph'] ?? ''),
        'responsable' => $names[trim((string) ($row['id_empleado'] ?? ''))] ?? '',
        'merece' => (string) ($row['merece_ticket'] ?? ''),
        'comisionista' => trim((string) ($row['comisionista'] ?? '')) === 'Si',
        'mas_adelante' => trim((string) ($row['cliente_inmediato'] ?? '')) === 'No',
        'mes' => trim((string) ($row['mes_inmueble'] ?? '')),
        'tarea' => $task,
        'tarea_estado' => $task > 0 ? ($tasks[(string) $task] ?? '') : '',
        'editable' => $this->policy->canAct('llamadas_editar') && $this->canAccess($row, $me, $team),
      ];
    }, $result['rows']);
    return ['filters' => $filters, 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $result['total'], 'counts' => $result['counts'], 'rows' => $rows];
  }

  /** @return array<string,mixed> Listas de los formularios y filtros. */
  public function options(): array
  {
    $glossary = fn(int $id): array => array_column($this->repository->glossary($id), 'value');
    $topics = $glossary(816) ?: CommercialTicketsRepository::COMMERCIAL_TOPICS;
    return [
      'temas' => $topics,
      'tipos' => $glossary(783),
      'destinaciones' => $glossary(785),
      'usos' => $glossary(292),
      'observaciones' => $glossary(199),
      'factores' => $glossary(293),
      'meses' => $glossary(270),
      'prioridades' => $glossary(447) ?: [self::DEFAULT_PRIORITY],
      'paises' => $this->repository->countries(),
      'medios' => array_column($this->medios->active(), 'nombre'),
      'medios_filtro' => array_column($this->medios->all(), 'nombre'),
      'funcionarios' => (new CommercialTicketsRepository($this->db))->ticketEmployees($this->commercialCargos()),
      'pph_medio' => $this->pphMedio(),
    ];
  }

  /** @return array<int,string> */
  private function commercialCargos(): array
  {
    $raw = $this->settings->get('commercial_employee_cargos', null);
    $source = is_array($raw) && $raw !== [] ? $raw : (is_array($this->config['commercial_employee_cargos'] ?? null) ? $this->config['commercial_employee_cargos'] : ['1', '6', '9', '10', '11', '12', '13', '14', '17']);
    return array_values(array_filter(array_map(static fn($id): string => trim((string) $id), $source), static fn(string $id): bool => $id !== ''));
  }

  /**
   * Datos del formulario: llamada a editar, o una nueva con el contacto de una solicitud anterior (sin el medio).
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function formData(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    if ($id > 0) {
      $this->requireAct('llamadas_editar', 'No tienes permiso para editar llamadas.');
      $call = $this->accessible($id);
      return ['mode' => 'edit', 'call' => $call, 'factores' => [], 'can_repeat' => $this->policy->canAct('llamadas_crear')];
    }
    $this->requireAct('llamadas_crear', 'No tienes permiso para registrar llamadas.');
    $call = ['id_empleado' => $this->me(), 'fecha' => time(), 'indicativo' => '57'];
    $from = (int) ($input['desde_llamada'] ?? 0);
    if ($from > 0) {
      $source = $this->accessible($from);
      foreach (['cliente', 'email', 'celular', 'indicativo', 'celular_wsp', 'usuario_wsp'] as $field) {
        $call[$field] = $source[$field] ?? '';
      }
    }
    return ['mode' => $from > 0 ? 'repeat' : 'new', 'call' => $call, 'factores' => [], 'can_repeat' => false];
  }

  /**
   * Registra una llamada o corrige sus datos personales. Lo demás (medio, fecha, motivo, inmueble, asignación) es de la
   * solicitud y al editar nunca se lee de la petición: si cambia, es una solicitud nueva.
   * @param array<string,mixed> $input
   * @return array{id:int,next:string,message:string}
   */
  public function save(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    $this->requireAct($id > 0 ? 'llamadas_editar' : 'llamadas_crear', 'No tienes permiso para guardar esta llamada.');
    $me = $this->requireMe();
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $current = null;
      if ($id > 0) {
        $current = $this->accessible($id, true);
      }
      $data = $this->validated($input, $current);
      $now = date('Y-m-d H:i:s');
      $data['cct_modified'] = $now;
      if ($current === null) {
        $digits = CommercialCallsRepository::digits((string) $data['celular']);
        if ($this->repository->recentDuplicate($digits, (string) $data['tema_ayuda'], (string) $data['medio'], date('Y-m-d H:i:s', time() - self::DUPLICATE_SECONDS))) {
          throw new \InvalidArgumentException('Esta llamada ya se registró hace un momento. Revisa el listado antes de volver a guardarla.');
        }
        $data += ['cct_status' => 'publish', 'cct_author_id' => $me, 'cct_created' => $now, 'sucursal' => $this->branchOf($me)];
        $id = $this->repository->insert($data);
        if ($data['merece_ticket'] === 'Si' && isset(self::CLIENT_TOPICS[(string) $data['tema_ayuda']])) {
          $clientId = $this->ensureClient($id, $data, $me);
          $this->repository->update($id, ['id_cliente' => (string) $clientId]);
        }
      } else {
        $this->repository->update($id, $data);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    $next = $current === null && $data['merece_ticket'] === 'Si' && $this->policy->canAct('llamadas_tarea') ? 'task' : 'list';
    return ['id' => $id, 'next' => $next, 'message' => $current === null ? 'Llamada registrada.' : 'Llamada actualizada.'];
  }

  /**
   * @param array<string,mixed> $input
   * @param array<string,mixed>|null $current
   * @return array<string,mixed>
   */
  private function validated(array $input, ?array $current): array
  {
    $options = $this->options();
    $text = static function (string $key, int $max = 190) use ($input): string {
      return is_scalar($input[$key] ?? null) ? mb_substr(trim(strip_tags((string) $input[$key])), 0, $max) : '';
    };
    $choice = static function (string $key, array $allowed, string $label, bool $required = true) use ($text, $current): string {
      $value = $text($key);
      if ($value === '') {
        if ($required) {
          throw new \InvalidArgumentException('Selecciona ' . $label . '.');
        }
        return '';
      }
      // Un valor ya guardado sigue siendo válido aunque haya salido del glosario.
      if ($allowed !== [] && !in_array($value, $allowed, true) && $value !== trim((string) ($current[$key] ?? ''))) {
        throw new \InvalidArgumentException('El valor de ' . $label . ' no es válido.');
      }
      return $value;
    };
    $yesNo = static fn(string $key, string $label): string => $choice($key, ['Si', 'No'], $label);
    // Una llamada antigua sin correo o sin país se puede corregir sin exigirlos.
    $needed = static fn(string $key): bool => $current === null || trim((string) ($current[$key] ?? '')) !== '';

    $cliente = $text('cliente');
    if ($cliente === '') {
      throw new \InvalidArgumentException('Escribe el nombre del contacto.');
    }
    $email = $text('email');
    if ($email === '' ? $needed('email') : filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('Escribe un correo válido.');
    }
    $celular = CommercialCallsRepository::digits($text('celular'));
    if (strlen($celular) < 7 || strlen($celular) > 15) {
      throw new \InvalidArgumentException('Escribe el celular sin indicativo (solo números).');
    }
    $contact = [
      'cliente' => $cliente,
      'email' => $email,
      'celular' => $celular,
      'indicativo' => $choice('indicativo', array_column($options['paises'], 'value'), 'el país', $needed('indicativo')),
      'celular_wsp' => CommercialCallsRepository::digits($text('celular_wsp')),
      'usuario_wsp' => $text('usuario_wsp'),
    ];
    if ($current !== null) {
      return $contact + $this->originFields($input, $current, $options);
    }

    $immediate = $yesNo('cliente_inmediato', 'si el cliente es inmediato');
    $topic = $choice('tema_ayuda', $options['temas'], 'el motivo');
    $offer = isset(self::CLIENT_TOPICS[$topic]);
    $code = (int) $text('id_inmueble');
    $employee = $this->repository->employee($text('id_empleado'));
    if ($employee === null || ($employee['activo'] ?? '') !== 'Si') {
      throw new \InvalidArgumentException('Selecciona un funcionario activo para asignar la llamada.');
    }
    $factors = array_values(array_intersect((array) ($input['factores_importantes'] ?? []), $options['factores']));
    return $contact + [
      'tema_ayuda' => $topic,
      'comisionista' => $yesNo('comisionista', 'si es comisionista'),
      'cliente_inmediato' => $immediate,
      'mes_inmueble' => $immediate === 'No' ? $choice('mes_inmueble', $options['meses'], 'el mes en que necesita el inmueble') : '',
      'tipo_inmueble' => $choice('tipo_inmueble', $options['tipos'], 'el tipo de inmueble', false),
      'destinacion' => $choice('destinacion', $options['destinaciones'], 'la destinación', false),
      'uso' => $choice('uso', $options['usos'], 'el uso', false),
      'id_inmueble' => $offer && $code > 0 ? (string) $code : '',
      'presupuesto' => $offer ? self::amount($text('presupuesto')) : '',
      'precio' => in_array($topic, self::LISTING_TOPICS, true) ? self::amount($text('precio')) : '',
      'factores_importantes' => implode(', ', $factors),
      'observacion' => $choice('observacion', $options['observaciones'], 'la observación', false),
      'descripcion' => is_scalar($input['descripcion'] ?? null) ? trim(strip_tags((string) $input['descripcion'])) : '',
      'merece_ticket' => $yesNo('merece_ticket', 'si merece tarea'),
      'id_empleado' => trim((string) $employee['id_empleado']),
    ] + $this->originFields($input, null, $options);
  }

  /**
   * Medio, fecha y aliado. Al editar no cambian: solo se puede completar lo que una llamada antigua dejó vacío.
   * @param array<string,mixed> $input
   * @param array<string,mixed>|null $current
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  private function originFields(array $input, ?array $current, array $options): array
  {
    $out = [];
    $saved = trim((string) ($current['medio'] ?? ''));
    if ($current === null || $saved === '') {
      $medio = $this->medios->canonical(is_scalar($input['medio'] ?? null) ? trim((string) $input['medio']) : '');
      if ($medio === '') {
        if ($current === null) {
          throw new \InvalidArgumentException('Selecciona el medio por el que llegó el contacto: después no se puede cambiar.');
        }
      } elseif (!in_array($medio, $options['medios'], true)) {
        throw new \InvalidArgumentException('El medio seleccionado no está activo.');
      } else {
        $out['medio'] = $saved = $medio;
      }
    }
    if ($current === null) {
      $out['fecha'] = self::callTimestamp(is_scalar($input['fecha'] ?? null) ? (string) $input['fecha'] : '');
    }
    $hasPartner = !in_array(trim((string) ($current['id_pph'] ?? '')), ['', '0'], true);
    $card = is_scalar($input['tarjeta_pph'] ?? null) ? trim((string) $input['tarjeta_pph']) : '';
    // Una llamada antigua de Club PPH sin aliado se puede completar, pero no impide corregir los datos personales.
    if ($saved === $this->pphMedio() && !$hasPartner && ($current === null || isset($out['medio']) || $card !== '')) {
      $partner = $this->resolveCard($card);
      $out += ['id_pph' => $partner['id'], 'tarjeta_pph' => $partner['tarjeta'], 'nombre_pph' => $partner['nombre']];
    }
    return $out;
  }

  /**
   * Aliado activo del Club PPH con esa tarjeta.
   * @return array{id:string,nombre:string,tarjeta:string}
   */
  public function resolveCard(string $card): array
  {
    $card = trim($card);
    if ($card === '') {
      throw new \InvalidArgumentException('Escribe la tarjeta del aliado del Club PPH.');
    }
    $matches = $this->repository->cardMatches($card);
    if ($matches === []) {
      throw new \InvalidArgumentException('No encontramos un aliado del Club PPH con esa tarjeta.');
    }
    if (count($matches) > 1) {
      throw new \InvalidArgumentException('Esa tarjeta está asignada a varios aliados. Pide a administración que la corrija antes de registrar la llamada.');
    }
    if (trim((string) ($matches[0]['activo'] ?? '')) !== 'Si') {
      throw new \InvalidArgumentException('El aliado de esa tarjeta está inactivo en el Club PPH.');
    }
    return ['id' => (string) $matches[0]['_ID'], 'nombre' => trim((string) ($matches[0]['nombre'] ?? '')), 'tarjeta' => trim((string) ($matches[0]['tarjeta_bienvenida'] ?? ''))];
  }

  /**
   * Datos para el formulario de la tarea.
   * @return array<string,mixed>
   */
  public function taskData(int $id): array
  {
    $this->requireAct('llamadas_tarea', 'No tienes permiso para crear tareas desde llamadas.');
    $call = $this->accessible($id);
    $this->assertTaskPossible($call);
    return ['call' => $call, 'factores' => self::factors($call['factores_importantes'] ?? ''), 'responsable' => $this->repository->employeeNames()[trim((string) ($call['id_empleado'] ?? ''))] ?? ''];
  }

  /**
   * Crea la tarea de una llamada. El medio, el aliado y el responsable salen de la llamada guardada, nunca de la petición.
   * @param array<string,mixed> $input
   * @return array{id:int,open:bool,message:string}
   */
  public function createTask(array $input): array
  {
    $this->requireAct('llamadas_tarea', 'No tienes permiso para crear tareas desde llamadas.');
    $me = $this->requireMe();
    $id = (int) ($input['id'] ?? 0);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $call = $this->accessible($id, true);
      $this->assertTaskPossible($call);
      $employee = $this->repository->employee(trim((string) ($call['id_empleado'] ?? '')));
      if ($employee === null || ($employee['activo'] ?? '') !== 'Si') {
        throw new \InvalidArgumentException('La llamada está asignada a un funcionario inactivo. Edítala y asígnala a otra persona.');
      }
      $task = $this->taskRow($call, $input, $employee, $me);
      $taskId = $this->repository->insertTask($task);
      $this->repository->update($id, ['id_ticket' => (string) $taskId, 'cct_modified' => $task['cct_modified']]);
      if ((int) ($task['id_inmueble'] ?? 0) > 0) {
        $this->repository->insertPropertyHistory([
          'cct_status' => 'publish', 'cct_author_id' => $me, 'cct_created' => $task['cct_created'], 'cct_modified' => $task['cct_modified'],
          'reporte_realizado_por_his' => $task['id_empleado'], 'fecha_his' => $task['fecha'], 'id_del_inmueble_his' => $task['id_inmueble'],
          'observacion_his' => $task['asunto'], 'id_inmueble' => $task['id_inmueble'], 'funcionario' => $task['nombre_empleado'], 'fecha' => $task['fecha'],
          'observacion' => 'Se ha creado una tarea comercial.', 'id_empleado' => $task['id_empleado'], 'id_ticket' => (string) $taskId,
          'tipo_de_reporte_his' => 'Ticket', 'tipo_reporte' => 'Ticket',
        ]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    $this->notify($taskId, $task);
    $canOpen = $this->policy->canAct('ver_ticket') && ($task['id_empleado'] === $me || $this->policy->canSeeAllTasks());
    return ['id' => $taskId, 'open' => $canOpen, 'message' => 'Tarea #' . $taskId . ' creada.'];
  }

  /**
   * @param array<string,mixed> $call
   * @param array<string,mixed> $input
   * @param array<string,mixed> $employee
   * @return array<string,mixed>
   */
  private function taskRow(array $call, array $input, array $employee, string $me): array
  {
    $options = $this->options();
    $text = static fn(string $key, int $max = 190): string => is_scalar($input[$key] ?? null) ? mb_substr(trim(strip_tags((string) $input[$key])), 0, $max) : '';
    $medio = trim((string) ($call['medio'] ?? ''));
    if ($medio === '') {
      throw new \InvalidArgumentException('Completa el medio de la llamada antes de crear la tarea.');
    }
    $solicitante = $text('solicitante');
    $celular = CommercialCallsRepository::digits($text('celular_solicitante'));
    $asunto = $text('asunto');
    $descripcion = is_scalar($input['descripcion'] ?? null) ? trim(strip_tags((string) $input['descripcion'])) : '';
    if ($solicitante === '' || strlen($celular) < 7 || $asunto === '' || $descripcion === '') {
      throw new \InvalidArgumentException('Completa nombre, celular, asunto y resumen de la tarea.');
    }
    $correo = $text('correo_solicitante');
    if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('Escribe un correo válido.');
    }
    $topic = $text('tema_ayuda');
    if (!in_array($topic, $options['temas'], true) && $topic !== trim((string) ($call['tema_ayuda'] ?? ''))) {
      throw new \InvalidArgumentException('Selecciona el tema de la tarea.');
    }
    $offer = isset(self::CLIENT_TOPICS[$topic]);
    $priority = $text('prioridad');
    $priority = in_array($priority, $options['prioridades'], true) ? $priority : self::DEFAULT_PRIORITY;
    $factors = array_values(array_intersect((array) ($input['factores'] ?? []), array_merge($options['factores'], self::factors($call['factores_importantes'] ?? ''))));
    $now = date('Y-m-d H:i:s');
    $hasPartner = !in_array(trim((string) ($call['id_pph'] ?? '')), ['', '0'], true);
    $clientId = $this->repository->clientId($celular);
    $inmueble = (int) $text('id_inmueble');
    return [
      'cct_status' => 'publish', 'cct_author_id' => $me, 'cct_created' => $now, 'cct_modified' => $now,
      'id_creador' => $me,
      'empleado' => (string) ($employee['nombre'] ?? ''),
      'nombre_empleado' => (string) ($employee['nombre'] ?? ''),
      'correo_empleado' => (string) ($employee['correo'] ?? ''),
      'celular_empleado' => (string) ($employee['celular'] ?? ''),
      'id_empleado' => trim((string) ($employee['id_empleado'] ?? '')),
      'fecha' => time(),
      'solicitante' => $solicitante,
      'correo_solicitante' => $correo,
      'celular_solicitante' => $celular,
      'indicativo' => (string) ($call['indicativo'] ?? ''),
      'celular_wsp' => CommercialCallsRepository::digits($text('celular_wsp')),
      'usuario_wsp' => $text('usuario_wsp'),
      'prioridad' => $priority,
      'tema_ayuda' => $topic,
      'id_inmueble' => $inmueble > 0 && $offer ? (string) $inmueble : '',
      'tipo_inmueble' => $text('tipo_inmueble'),
      'destinacion' => $text('destinacion'),
      'uso' => $text('uso'),
      'medio' => $medio,
      'precio' => in_array($topic, self::LISTING_TOPICS, true) ? self::amount($text('precio')) : '',
      'presupuesto' => $offer ? self::amount($text('presupuesto')) : '',
      'factores' => implode(', ', $factors),
      'asunto' => $asunto,
      'descripcion' => $descripcion,
      'id_llamada' => (string) $call['_ID'],
      'sucursal' => $this->branchOf($me),
      'id_pph' => $hasPartner ? trim((string) $call['id_pph']) : '',
      'tarjeta_pph' => $hasPartner ? (string) ($call['tarjeta_pph'] ?? '') : '',
      'nombre_pph' => $hasPartner ? (string) ($call['nombre_pph'] ?? '') : '',
      'bandera' => $hasPartner ? 'No' : '',
      'id_solicitante' => $clientId > 0 ? (string) $clientId : '',
      'id_cliente' => $clientId > 0 ? (string) $clientId : '',
      'estado' => 'Nuevo',
      'estado_comercial' => 'Nuevo',
      'departamento' => 'Servicio al cliente',
      'tuvo_seguimiento' => 'No',
      'tuvo_reporte' => 'No',
      'creador_por' => 'Funcionario',
    ];
  }

  /** @param array<string,mixed> $task */
  private function notify(int $taskId, array $task): void
  {
    try {
      $notifier = $this->notifier ?? new CommercialCallsNotifier($this->db, $this->settings, $this->config);
      $notifier->taskCreated($taskId, $task, $this->repository->branch((string) ($task['sucursal'] ?? '')) ?? []);
    } catch (\Throwable $exception) {
      error_log('[llamadas:notificacion] ' . $exception->getMessage());
    }
  }

  /**
   * Historial del contacto. A quien solo ve lo suyo, de las solicitudes de otros funcionarios le llega lo justo para saber
   * que ya existen: fecha, medio, motivo, responsable y número de tarea.
   * @param array<string,mixed> $input
   * @return array{digits:string,contact:array<string,string>,items:list<array<string,mixed>>,last_id:int}
   */
  public function history(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    if ($id > 0) {
      $digits = CommercialCallsRepository::digits((string) ($this->accessible($id)['celular'] ?? ''));
    } else {
      $this->requireAct('llamadas_crear', 'No tienes permiso para consultar contactos.');
      $digits = CommercialCallsRepository::digits(is_scalar($input['celular'] ?? null) ? (string) $input['celular'] : '');
    }
    if (strlen($digits) < 7) {
      return ['digits' => '', 'contact' => [], 'items' => [], 'last_id' => 0];
    }
    $team = $this->seesTeam();
    $me = $this->me();
    $names = $this->repository->employeeNames();
    $calls = $this->repository->contactCalls($digits);
    $tasks = $this->repository->contactTasks($digits);
    $statuses = [];
    foreach ($tasks as $task) {
      $statuses[(string) $task['_ID']] = (string) ($task['estado_comercial'] ?? '');
    }
    $statuses += $this->repository->taskStatuses(array_map(static fn(array $row): string => trim((string) ($row['id_ticket'] ?? '')), $calls));
    $items = [];
    $linked = [];
    $contact = [];
    $lastId = 0;
    foreach ($calls as $call) {
      $task = self::taskId($call);
      if ($task > 0) {
        $linked[$task] = true;
      }
      $mine = $this->canAccess($call, $me, $team);
      if ($mine && $contact === []) {
        $lastId = (int) $call['_ID'];
        foreach (['cliente', 'email', 'indicativo', 'celular_wsp', 'usuario_wsp'] as $field) {
          $contact[$field] = (string) ($call[$field] ?? '');
        }
      }
      $items[] = [
        'tipo' => 'Llamada', 'id' => (int) $call['_ID'], 'ts' => (int) ($call['fecha'] ?? 0), 'fecha' => self::dateLabel($call['fecha'] ?? 0),
        'medio' => (string) ($call['medio'] ?? ''), 'tema' => (string) ($call['tema_ayuda'] ?? ''),
        'responsable' => $names[trim((string) ($call['id_empleado'] ?? ''))] ?? '',
        'tarea' => $task, 'tarea_estado' => $statuses[(string) $task] ?? '',
        'aliado' => $mine ? (string) ($call['nombre_pph'] ?? '') : '',
        'descripcion' => $mine ? (string) ($call['descripcion'] ?? '') : '',
        'propia' => $mine,
      ];
    }
    foreach ($tasks as $task) {
      if (!isset($linked[(int) $task['_ID']])) {
        $items[] = [
          'tipo' => 'Tarea sin llamada', 'id' => (int) $task['_ID'], 'ts' => (int) ($task['fecha'] ?? 0), 'fecha' => self::dateLabel($task['fecha'] ?? 0),
          'medio' => (string) ($task['medio'] ?? ''), 'tema' => (string) ($task['tema_ayuda'] ?? ''),
          'responsable' => $names[trim((string) ($task['id_empleado'] ?? ''))] ?? '',
          'tarea' => (int) $task['_ID'], 'tarea_estado' => (string) ($task['estado_comercial'] ?? ''), 'aliado' => '', 'descripcion' => '', 'propia' => false,
        ];
      }
    }
    usort($items, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
    return ['digits' => $digits, 'contact' => $contact, 'items' => $items, 'last_id' => $lastId];
  }

  /** @param array<string,mixed> $data */
  private function ensureClient(int $callId, array $data, string $me): int
  {
    $digits = CommercialCallsRepository::digits((string) $data['celular']);
    $existing = $this->repository->clientId($digits);
    if ($existing > 0) {
      return $existing;
    }
    $now = date('Y-m-d H:i:s');
    return $this->repository->insertClient([
      'cct_status' => 'publish', 'cct_author_id' => $me, 'cct_created' => $now, 'cct_modified' => $now,
      'nombre' => $data['cliente'], 'celular' => $digits, 'correo' => $data['email'], 'indicativo' => $data['indicativo'],
      'tipo_cliente' => self::CLIENT_TOPICS[(string) $data['tema_ayuda']], 'id_cliente' => (string) $callId,
    ]);
  }

  /** @param array<string,mixed> $call */
  private function assertTaskPossible(array $call): void
  {
    if (self::taskId($call) > 0) {
      throw new \InvalidArgumentException('Esta llamada ya tiene la tarea #' . self::taskId($call) . '. Si la persona hace otra solicitud, registra una llamada nueva.');
    }
    if (trim((string) ($call['merece_ticket'] ?? '')) !== 'Si') {
      throw new \InvalidArgumentException('Marca primero que la llamada merece tarea.');
    }
  }

  /** @return array<string,mixed> */
  private function accessible(int $id, bool $lock = false): array
  {
    $call = $id > 0 ? $this->repository->find($id, $lock) : null;
    if ($call === null) {
      throw new \InvalidArgumentException('La llamada no existe.');
    }
    if (!$this->canAccess($call, $this->me(), $this->seesTeam())) {
      throw new \RuntimeException('Esta llamada es de otro funcionario.');
    }
    return $call;
  }

  /** @param array<string,mixed> $call */
  private function canAccess(array $call, string $me, bool $team): bool
  {
    return $team || ($me !== '' && (trim((string) ($call['id_empleado'] ?? '')) === $me || trim((string) ($call['cct_author_id'] ?? '')) === $me));
  }

  private function requireAct(string $action, string $message): void
  {
    if (!$this->policy->canAct($action)) {
      throw new \RuntimeException($message);
    }
  }

  private function requireMe(): string
  {
    $me = $this->me();
    if ($me === '') {
      throw new \InvalidArgumentException('No pudimos identificar tu funcionario. Vuelve a iniciar sesión.');
    }
    return $me;
  }

  private function branchOf(string $employee): string
  {
    return trim((string) ($this->repository->employee($employee)['id_sucursal'] ?? ''));
  }

  /** @param array<string,mixed> $row */
  private static function taskId(array $row): int
  {
    return (int) trim((string) ($row['id_ticket'] ?? ''));
  }

  /** Monto en solo dígitos. Quita los decimales de valores antiguos ("1500000.00"), no los puntos de miles ("1.500.000"). */
  public static function amount(string $value): string
  {
    $value = trim($value);
    if (preg_match('/^(\d+)[.,]\d{1,2}$/', $value, $match) === 1) {
      $value = $match[1];
    }
    return substr((string) preg_replace('/\D+/', '', $value), 0, 15);
  }

  /** Monto para mostrar: 1500000 -> 1.500.000. */
  public static function money(string $value): string
  {
    $digits = self::amount($value);
    return $digits === '' ? '' : strrev(implode('.', str_split(strrev($digits), 3)));
  }

  /** Hoy conserva la hora real; un día anterior queda al mediodía de ese día para no cambiar de fecha por la zona horaria. */
  private static function callTimestamp(string $day): int
  {
    $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
    $day = trim($day);
    if ($day === '' || $day === $today) {
      return time();
    }
    $timestamp = CommercialCallsRepository::dayTimestamp($day, '12:00:00');
    if ($timestamp === null || $day > $today) {
      throw new \InvalidArgumentException('La fecha de la llamada no puede ser futura.');
    }
    return $timestamp;
  }

  /** @param mixed $timestamp */
  public static function dateLabel($timestamp): string
  {
    $value = (int) $timestamp;
    return $value > 86400 ? (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('America/Bogota'))->format('d/m/Y') : '';
  }

  /**
   * Factores guardados: texto separado por comas, o el arreglo serializado que dejaba el formulario anterior.
   * @param mixed $raw
   * @return list<string>
   */
  public static function factors($raw): array
  {
    $raw = is_scalar($raw) ? trim((string) $raw) : '';
    if ($raw === '') {
      return [];
    }
    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    $list = is_array($decoded) ? $decoded : explode(',', $raw);
    return array_values(array_filter(array_map(static fn($item): string => is_scalar($item) ? trim((string) $item) : '', $list), static fn(string $item): bool => $item !== ''));
  }
}
