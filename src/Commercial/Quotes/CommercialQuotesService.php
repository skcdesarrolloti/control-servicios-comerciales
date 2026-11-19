<?php

declare(strict_types=1);

namespace SCM\Commercial\Quotes;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\PropertyUpdateState;
use SCM\Core\Auth;
use SCM\Core\Settings;
use SCM\Support\PublicQuoteAccess;

/**
 * Cotizaciones de inmuebles: se crean desde el popup de la tarea, eligiendo varios inmuebles públicos (los que tienen la
 * actualización vencida no se pueden cotizar hasta actualizarlos), se envían al cliente por correo y WhatsApp con un
 * enlace público y se listan en Actividades comerciales › Cotizaciones (y Mis cotizaciones). Reemplaza el formulario
 * JetFormBuilder "Crear cotización de inmuebles" y la página /cotizacion-de-inmuebles/ de WordPress, con una diferencia:
 * el historial del inmueble queda uno por inmueble, no uno con todos.
 */
final class CommercialQuotesService
{
  public const VIEW = 'cotizaciones';
  public const ACTION = 'cotizacion_crear';
  public const SETTING = 'commercial_quotes';
  public const TYPES = ['Arriendo', 'Venta'];
  public const MAX_PROPERTIES = 20;
  public const PER_PAGE = 24;
  public const LIST_PER_PAGE = 25;
  public const DEFAULT_NOTES = 'Esta cotización es informativa: los precios, las condiciones y la disponibilidad de los inmuebles pueden cambiar sin previo aviso hasta la firma del contrato. La información de cada inmueble la entrega su propietario y SKC SuCasa Inmobiliaria la presenta de buena fe. Nuestra gestión es de medio y no de resultado.';
  public const DEFAULT_RENT_NOTE = 'Al tomar en arriendo un inmueble con SKC SuCasa Inmobiliaria, el arrendatario paga un 20 % adicional sobre el valor del canon por derechos de contrato.';

  private CommercialQuotesRepository $repository;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private ?CommercialQuotesNotifier $notifier;
  private ?QuotePdf $pdfs;
  /** @var array<string,array{alerta:int,vencido:int}>|null */
  private ?array $limits = null;

  /** @param QuotePdf|null $pdfs PDF guardado de cada cotización; por defecto, en storage/uploads/cotizaciones. */
  public function __construct(CommercialQuotesRepository $repository, Settings $settings, CommercialAccessPolicy $policy, ?CommercialQuotesNotifier $notifier = null, ?QuotePdf $pdfs = null)
  {
    $this->repository = $repository;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->notifier = $notifier;
    $this->pdfs = $pdfs ?? QuotePdf::fromRuntime();
  }

  // ── Configuración ───────────────────────────────────

  /** @return array{notas:string,nota_arriendo:string} Textos que el negocio cambia en Configuración › Actividades comerciales › Cotizaciones. */
  public static function settingsFrom(Settings $settings): array
  {
    $raw = $settings->get(self::SETTING, []);
    $raw = is_array($raw) ? $raw : [];
    return [
      'notas' => array_key_exists('notas', $raw) ? trim((string) $raw['notas']) : self::DEFAULT_NOTES,
      'nota_arriendo' => array_key_exists('nota_arriendo', $raw) ? trim((string) $raw['nota_arriendo']) : self::DEFAULT_RENT_NOTE,
    ];
  }

  /** @param array<string,mixed> $data */
  public static function saveSettings(Settings $settings, array $data, int $userId): void
  {
    $clean = [];
    foreach (['notas' => 'Las notas de la cotización', 'nota_arriendo' => 'La nota de arriendo'] as $key => $label) {
      $text = trim(str_replace("\r\n", "\n", (string) ($data[$key] ?? '')));
      if (mb_strlen($text, 'UTF-8') > 3000) {
        throw new \InvalidArgumentException($label . ' no puede pasar de 3.000 caracteres.');
      }
      $clean[$key] = strip_tags($text);
    }
    $settings->set(self::SETTING, $clean, $userId);
    $settings->refresh();
  }

  // ── Formulario en la tarea ──────────────────────────

  public function authorizeCreate(): void
  {
    if (!$this->policy->canAct(self::ACTION) || !$this->policy->canAct('ver_ticket')) {
      throw new \RuntimeException('No tienes permiso para crear cotizaciones de inmuebles.');
    }
  }

  /** @return array<string,mixed> */
  public function formData(int $ticketPk): array
  {
    $this->authorizeCreate();
    $task = $this->repository->task($ticketPk);
    if ($task === null) {
      throw new \InvalidArgumentException('La tarea no existe.');
    }
    $topic = mb_strtolower(trim((string) ($task['tema_ayuda'] ?? '')), 'UTF-8');
    $previous = [];
    foreach ($this->repository->forTask($ticketPk) as $row) {
      $previous[] = $this->listRow($row);
    }
    return [
      'ticket_pk' => $ticketPk,
      'asunto' => trim((string) ($task['asunto'] ?? '')),
      'cliente' => trim((string) ($task['solicitante'] ?? '')),
      'email' => trim((string) ($task['correo_solicitante'] ?? '')),
      'celular' => trim((string) ($task['celular_wsp'] ?? '')) ?: trim((string) ($task['celular_solicitante'] ?? '')),
      'tipo' => str_contains($topic, 'venta') && !str_contains($topic, 'arriendo') ? 'Venta' : (str_contains($topic, 'arriendo') ? 'Arriendo' : ''),
      'options' => $this->propertyOptions(),
      'previous' => $previous,
      'max' => self::MAX_PROPERTIES,
    ];
  }

  /** @return array{tipos_inmueble:list<string>,barrios:list<string>,ciudades:list<string>} */
  public function propertyOptions(): array
  {
    return $this->repository->options();
  }

  /**
   * Inmuebles públicos para elegir, con su foto, precio y si se pueden cotizar.
   * @param array<string,mixed> $input
   * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,vencidos:int}
   */
  public function searchProperties(array $input): array
  {
    $this->authorizeCreate();
    $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    $type = in_array($text('tipo'), self::TYPES, true) ? $text('tipo') : '';
    $rows = $this->repository->candidates(['q' => $text('q'), 'tipo' => $type, 'tipo_inmueble' => $text('tipo_inmueble'), 'barrio' => $text('barrio'), 'ciudad' => $text('ciudad')]);
    $min = self::amount($text('precio_min'));
    $max = self::amount($text('precio_max'));
    $rooms = (int) $text('habitaciones');
    $baths = (int) $text('banos');
    $hideExpired = $text('ocultar_vencidos') === '1';
    $items = [];
    $expired = 0;
    foreach ($rows as $row) {
      $item = QuoteComparison::normalize($row, $type !== '' ? $type : 'Arriendo');
      if (($min > 0 && $item['precio'] < $min) || ($max > 0 && ($item['precio'] <= 0 || $item['precio'] > $max)) || ($rooms > 0 && $item['habitaciones'] < $rooms) || ($baths > 0 && $item['banos'] < $baths)) {
        continue;
      }
      $state = PropertyUpdateState::evaluate($row, $this->limits());
      if ($state['status'] === 'vencido') {
        $expired++;
        if ($hideExpired) {
          continue;
        }
      }
      $items[] = $item + ['row' => $row, 'estado_actualizacion' => $state, 'id_inmueble' => (int) ($row['_ID'] ?? 0)];
    }
    $sort = $text('orden');
    if ($sort !== '' && $sort !== 'recientes') {
      usort($items, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'area') {
          return $b['area'] <=> $a['area'];
        }
        $pa = $a['precio'] > 0 ? $a['precio'] : PHP_FLOAT_MAX;
        $pb = $b['precio'] > 0 ? $b['precio'] : PHP_FLOAT_MAX;
        return $sort === 'precio_desc' ? ($b['precio'] <=> $a['precio']) : ($pa <=> $pb);
      });
    }
    $total = count($items);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = min($pages, max(1, (int) $text('pagina')));
    $slice = array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
    $photos = $this->photos(array_map(static fn(array $item): array => $item['row'], $slice), false);
    $cards = [];
    foreach ($slice as $item) {
      $row = $item['row'];
      unset($item['row']);
      $cards[] = $item + [
        'foto' => $photos[$item['codigo']][0] ?? '',
        'quotable' => $item['estado_actualizacion']['status'] !== 'vencido',
        'url_actualizar' => PropertyUpdateState::updateUrl($row),
        'url_portal' => self::portalUrl($item['codigo']),
      ];
    }
    return ['items' => $cards, 'total' => $total, 'page' => $page, 'pages' => $pages, 'vencidos' => $expired];
  }

  /**
   * Crea la cotización, deja un historial por inmueble y el de la tarea, y la envía al cliente.
   * @param array<string,mixed> $input
   * @return array{id:int,url:string,message:string,enviado:array{correo:bool,whatsapp:bool}}
   */
  public function create(int $ticketPk, array $input): array
  {
    $this->authorizeCreate();
    $task = $this->repository->task($ticketPk);
    if ($task === null) {
      throw new \InvalidArgumentException('La tarea no existe.');
    }
    $text = static fn(string $key, int $max = 200): string => is_scalar($input[$key] ?? null) ? mb_substr(trim(strip_tags((string) $input[$key])), 0, $max, 'UTF-8') : '';
    $type = $text('tipo');
    if (!in_array($type, self::TYPES, true)) {
      throw new \InvalidArgumentException('Elige si la cotización es de arriendo o de venta.');
    }
    $client = $text('cliente', 120);
    if ($client === '') {
      throw new \InvalidArgumentException('Escribe el nombre del cliente.');
    }
    $email = $text('email', 160);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('El correo del cliente no es válido.');
    }
    $phone = (string) preg_replace('/[^0-9+]/', '', $text('celular', 30));
    if ($email === '' && $phone === '') {
      throw new \InvalidArgumentException('Escribe el correo o el celular del cliente para enviarle la cotización.');
    }
    $notes = is_scalar($input['observaciones'] ?? null) ? mb_substr(trim(strip_tags((string) $input['observaciones'])), 0, 2000, 'UTF-8') : '';

    $raw = $input['inmuebles'] ?? [];
    $codes = is_array($raw) ? array_map(static fn($code): string => is_scalar($code) ? trim((string) $code) : '', $raw) : CommercialQuotesRepository::codes((string) $raw);
    $codes = array_values(array_unique(array_filter($codes, static fn(string $code): bool => preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $code) === 1)));
    if ($codes === []) {
      throw new \InvalidArgumentException('Elige al menos un inmueble para cotizar.');
    }
    if (count($codes) > self::MAX_PROPERTIES) {
      throw new \InvalidArgumentException('Puedes cotizar hasta ' . self::MAX_PROPERTIES . ' inmuebles a la vez.');
    }
    $rows = $this->repository->propertiesByCodes($codes);
    $found = array_map(static fn(array $row): string => trim((string) $row['codigo']), $rows);
    $missing = array_diff($codes, $found);
    if ($missing !== []) {
      throw new \InvalidArgumentException('No encontramos el inmueble ' . implode(', ', $missing) . '.');
    }
    foreach ($rows as $row) {
      $code = trim((string) $row['codigo']);
      if (!in_array(trim((string) ($row['estado'] ?? '')), CommercialQuotesRepository::PUBLIC_STATES, true)) {
        throw new \InvalidArgumentException('El inmueble ' . $code . ' no está público y no se puede cotizar.');
      }
      if (PropertyUpdateState::evaluate($row, $this->limits())['status'] === 'vencido') {
        throw new \InvalidArgumentException('El inmueble ' . $code . ' tiene la actualización vencida: actualízalo antes de cotizarlo.');
      }
    }

    $employeeId = trim(Auth::employeeId());
    $employee = $this->repository->employee($employeeId);
    if ($employee === null) {
      throw new \RuntimeException('Tu usuario no está vinculado a un funcionario: no se puede crear la cotización.');
    }
    $advisor = ['id' => $employeeId, 'nombre' => trim((string) ($employee['nombre'] ?? '')) ?: Auth::user(), 'correo' => trim((string) ($employee['correo'] ?? '')), 'celular' => trim((string) ($employee['celular'] ?? ''))];
    $now = time();
    $stamp = date('Y-m-d H:i:s', $now);
    $common = ['cct_status' => 'publish', 'cct_author_id' => $employeeId, 'cct_created' => $stamp, 'cct_modified' => $stamp];
    $quote = $common + [
      'id_empleado' => $employeeId,
      'creador' => $advisor['nombre'],
      'inmuebles' => serialize($codes),
      'destinatario' => $client,
      'direccion' => '',
      'email' => $email,
      'celular' => $phone,
      'fecha' => $now,
      'observaciones' => $notes,
      'sucursal' => trim((string) ($employee['id_sucursal'] ?? '')),
      'id_ticket' => (string) $ticketPk,
      'email_creador' => $advisor['correo'],
      'celular_creador' => $advisor['celular'],
      'tipo' => $type,
      'id_cliente' => trim((string) ($task['id_solicitante'] ?? $task['id_cliente'] ?? '')),
    ];

    $pdo = $this->repository->database()->pdo();
    $pdo->beginTransaction();
    try {
      $id = $this->repository->insertQuote($quote);
      if ($id <= 0) {
        throw new \RuntimeException('No se pudo guardar la cotización.');
      }
      $url = PublicQuoteAccess::url($id);
      // Los enlaces quedan en la cotización para el portal del cliente y los demás paneles (bin/add-quote-link-fields.php).
      $this->repository->updateQuote($id, ['url_cotizacion' => $url, 'url_pdf' => $url . '&formato=pdf']);
      foreach ($codes as $code) {
        // Un registro por inmueble: cada uno queda en su propio historial.
        $this->repository->insertPropertyHistory($common + [
          'id_inmueble' => $code, 'inmuebles_cotizados' => serialize([$code]), 'fecha' => $now, 'tipo_reporte' => 'Cotizacion',
          'observacion' => 'Se ha realizado una cotización en su inmueble (cotización #' . $id . ').', 'funcionario' => $advisor['nombre'], 'id_empleado' => $employeeId,
          'id_ticket' => (string) $ticketPk, 'id_cotizacion' => (string) $id,
        ]);
      }
      $count = count($codes);
      $this->repository->insertTaskHistory($common + [
        'id_ticket' => $ticketPk, 'fecha' => $now, 'nombre' => $advisor['nombre'], 'correo' => $advisor['correo'], 'celular' => $advisor['celular'], 'id_empleado' => $employeeId,
        'respuesta' => 'Cordial saludo, ' . $client . '. Le enviamos la cotización de inmuebles #' . $id . ' con ' . CommercialQuotesNotifier::summary($count, $type)
          . ', con el fin de que la analicemos en forma conjunta y pueda tomar la mejor decisión. Puede verla en: ' . $url,
        'fue_editada' => 'SI', 'id_cotizacion_comercial' => (string) $id,
      ]);
      $update = ['id_cotizacion_comercial' => (string) $id, 'fecha_actualizacion' => $now];
      $previous = trim((string) ($task['estado_comercial'] ?? ''));
      $general = trim((string) ($task['estado'] ?? ''));
      // Como el formulario anterior: la tarea pasa a Mostrando, salvo que esté postergada o cerrada.
      if ($previous !== 'Mostrando' && !CommercialStatusCatalog::isPostponed($previous, $general) && !CommercialStatusCatalog::isClosed($previous, $general)) {
        $update['estado_comercial'] = 'Mostrando';
        $this->repository->insertTaskHistory($common + [
          'id_ticket' => $ticketPk, 'fecha' => $now, 'nombre' => $advisor['nombre'], 'id_empleado' => $employeeId,
          'respuesta' => sprintf('Estado comercial actualizado de "%s" a "Mostrando".', $previous !== '' ? $previous : 'Sin estado'),
        ]);
      }
      $this->repository->updateTask($ticketPk, $update);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    // El PDF se genera antes de avisar: va adjunto al correo y como documento en el WhatsApp. Si Chromium no responde,
    // el correo sale con el enlace y el WhatsApp pide el PDF a cotizacion.php, que lo intenta generar de nuevo.
    $pdf = $this->pdfPath($id);
    $sent = ['correo' => false, 'whatsapp' => false];
    if ($this->notifier !== null) {
      $properties = array_map(static fn(array $row): array => QuoteComparison::normalize($row, $type), $rows);
      try {
        $sent = $this->notifier->quoteCreated($id, $quote, $advisor, $properties, trim((string) ($task['indicativo'] ?? '')) ?: '57', $pdf);
      } catch (\Throwable $exception) {
        error_log('[cotizaciones:aviso] ' . $exception->getMessage());
      }
    }
    $channels = array_keys(array_filter($sent));
    return [
      'id' => $id,
      'url' => $url,
      'pdf' => $pdf !== null,
      'enviado' => $sent,
      'message' => 'Cotización #' . $id . ' creada. ' . ($channels === [] ? 'No se pudo enviar al cliente: revisa su correo y celular y comparte el enlace.' : 'Se envió al cliente por ' . implode(' y ', array_map(static fn(string $channel): string => $channel === 'correo' ? 'correo' : 'WhatsApp', $channels)) . ($pdf !== null ? ', con el PDF.' : '.'))
        . ($pdf === null && $this->pdfs !== null ? ' El PDF no se pudo generar ahora; el correo lleva el enlace y el PDF se generará cuando se abra.' : ''),
    ];
  }

  // ── Listado ─────────────────────────────────────────

  /** @param array<string,mixed> $input */
  public function seesTeam(array $input): bool
  {
    return $this->policy->seesTeam(self::VIEW, $input);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{rows:list<array<string,mixed>>,total:int,mes:int,page:int,per_page:int,filters:array<string,string>,team:bool,mine:bool}
   */
  public function listing(array $input): array
  {
    if (!$this->policy->canView(self::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver las cotizaciones.');
    }
    $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? mb_substr(trim((string) $input[$key]), 0, 120, 'UTF-8') : '';
    $filters = ['q' => $text('cq_q'), 'tipo' => in_array($text('cq_tipo'), self::TYPES, true) ? $text('cq_tipo') : '', 'empleado' => $text('cq_empleado'), 'desde' => $text('cq_desde'), 'hasta' => $text('cq_hasta')];
    $team = $this->seesTeam($input);
    $page = max(1, (int) $text('cq_pag'));
    $scope = $team ? null : trim(Auth::employeeId());
    if ($scope === '') {
      // Si no se sabe quién es, no se muestra nada.
      return ['rows' => [], 'total' => 0, 'mes' => 0, 'page' => 1, 'per_page' => self::LIST_PER_PAGE, 'filters' => $filters, 'team' => $team, 'mine' => (string) ($input['portafolio'] ?? '') === '1'];
    }
    if (!$team) {
      $filters['empleado'] = '';
    }
    $result = $this->repository->search($filters, $scope, $page, self::LIST_PER_PAGE);
    return [
      'rows' => array_map(fn(array $row): array => $this->listRow($row), $result['rows']),
      'total' => $result['total'],
      'mes' => $result['mes'],
      'page' => $page,
      'per_page' => self::LIST_PER_PAGE,
      'filters' => $filters,
      'team' => $team,
      'mine' => (string) ($input['portafolio'] ?? '') === '1',
    ];
  }

  /** @return array<string,string> */
  public function creators(): array
  {
    return $this->repository->creators();
  }

  /**
   * Enlace público de una cotización que la persona puede abrir: la suya, cualquiera si ve todo el equipo, o una de una
   * tarea que ya se le validó ($ticketPk > 0).
   */
  public function viewUrl(int $id, int $ticketPk = 0): string
  {
    $quote = $this->repository->find($id);
    if ($quote === null) {
      throw new \InvalidArgumentException('La cotización no existe.');
    }
    $own = trim((string) ($quote['id_empleado'] ?? '')) !== '' && trim((string) $quote['id_empleado']) === trim(Auth::employeeId());
    $ofTask = $ticketPk > 0 && trim((string) ($quote['id_ticket'] ?? '')) === (string) $ticketPk;
    $allowed = $ofTask || ($this->policy->canView(self::VIEW) && ($own || $this->policy->canSeeAll(self::VIEW)));
    if (!$allowed) {
      throw new \RuntimeException('No tienes permiso para ver esta cotización.');
    }
    return PublicQuoteAccess::url($id);
  }

  // ── Vista pública ───────────────────────────────────

  /**
   * Ruta del PDF de la cotización: el guardado o, si no existe (Chromium no respondió al crearla), uno nuevo con los
   * datos de hoy. null si no se puede generar.
   */
  public function pdfPath(int $id): ?string
  {
    if ($this->pdfs === null || $id < 1) {
      return null;
    }
    $existing = $this->pdfs->existing($id);
    if ($existing !== null) {
      return $existing;
    }
    $quote = $this->publicData($id);
    if ($quote === null) {
      return null;
    }
    try {
      return $this->pdfs->store($id, $quote, PublicQuoteAccess::url($id));
    } catch (\Throwable $exception) {
      error_log('[cotizaciones:pdf] ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Todo lo que ve el cliente en su enlace. No incluye datos internos (dirección exacta, propietario, llaves).
   * @return array<string,mixed>|null
   */
  public function publicData(int $id): ?array
  {
    $quote = $this->repository->find($id);
    if ($quote === null) {
      return null;
    }
    $type = in_array(trim((string) ($quote['tipo'] ?? '')), self::TYPES, true) ? trim((string) $quote['tipo']) : 'Arriendo';
    $rows = $this->repository->propertiesByCodes(CommercialQuotesRepository::codes($quote['inmuebles'] ?? ''));
    $photos = $this->photos($rows, true);
    $items = [];
    foreach ($rows as $row) {
      $code = trim((string) $row['codigo']);
      $items[] = QuoteComparison::normalize($row, $type) + [
        'fotos' => $photos[$code] ?? [],
        'descripcion' => trim(strip_tags((string) ($row['descripcion'] ?? ''))),
        'copropiedad' => trim((string) ($row['copropiedad'] ?? '')),
        'video' => self::safeUrl((string) ($row['video'] ?? '')),
        'disponible' => in_array(trim((string) ($row['estado'] ?? '')), CommercialQuotesRepository::PUBLIC_STATES, true),
        'url_portal' => self::portalUrl($code),
      ];
    }
    $comparison = QuoteComparison::compare($items, $type);
    foreach ($items as $index => $item) {
      $items[$index]['ventajas'] = $comparison['advantages'][$index] ?? [];
    }
    $employee = $this->repository->employee(trim((string) ($quote['id_empleado'] ?? ''))) ?? [];
    $branch = $this->repository->branch(trim((string) ($quote['sucursal'] ?? ''))) ?? [];
    $settings = self::settingsFrom($this->settings);
    $fecha = (int) ($quote['fecha'] ?? 0);
    return [
      'id' => $id,
      'tipo' => $type,
      'fecha' => $fecha > 0 ? date('d/m/Y', $fecha) : '',
      'cliente' => trim((string) ($quote['destinatario'] ?? '')),
      'observaciones' => trim((string) ($quote['observaciones'] ?? '')),
      'asesor' => [
        'nombre' => trim((string) ($employee['nombre'] ?? '')) ?: trim((string) ($quote['creador'] ?? '')) ?: 'Equipo comercial',
        'correo' => trim((string) ($employee['correo'] ?? '')) ?: trim((string) ($quote['email_creador'] ?? '')),
        'celular' => trim((string) ($employee['celular'] ?? '')) ?: trim((string) ($quote['celular_creador'] ?? '')),
      ],
      'gerente' => ['nombre' => trim((string) ($branch['gerente_comercial'] ?? '')), 'celular' => trim((string) ($branch['celular_ge_comercial'] ?? ''))],
      'inmuebles' => $items,
      'comparacion' => $comparison,
      'notas' => array_values(array_filter([$type === 'Arriendo' ? $settings['nota_arriendo'] : '', $settings['notas']], static fn(string $note): bool => $note !== '')),
    ];
  }

  // ── Apoyo ───────────────────────────────────────────

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function listRow(array $row): array
  {
    $id = (int) ($row['_ID'] ?? 0);
    $fecha = (int) ($row['fecha'] ?? 0);
    if ($fecha <= 0 && trim((string) ($row['cct_created'] ?? '')) !== '') {
      $fecha = strtotime((string) $row['cct_created']) ?: 0;
    }
    return [
      'id' => $id,
      'fecha' => $fecha > 0 ? date('d/m/Y H:i', $fecha) : '',
      'cliente' => trim((string) ($row['destinatario'] ?? '')),
      'email' => trim((string) ($row['email'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
      'tipo' => trim((string) ($row['tipo'] ?? '')),
      'codigos' => CommercialQuotesRepository::codes($row['inmuebles'] ?? ''),
      'tarea' => (int) ($row['id_ticket'] ?? 0),
      'asesor' => trim((string) ($row['creador'] ?? '')),
      'propia' => trim((string) ($row['id_empleado'] ?? '')) !== '' && trim((string) $row['id_empleado']) === trim(Auth::employeeId()),
    ];
  }

  /** @return array<string,array{alerta:int,vencido:int}> */
  private function limits(): array
  {
    return $this->limits ??= CommercialSettingsService::propertyUpdateLimits($this->settings);
  }

  /**
   * Fotos de cada inmueble por código: portada primero y, con $gallery, toda la galería.
   * @param list<array<string,mixed>> $rows
   * @return array<string,list<string>>
   */
  private function photos(array $rows, bool $gallery): array
  {
    $plan = [];
    $ids = [];
    foreach ($rows as $row) {
      $code = trim((string) ($row['codigo'] ?? ''));
      $parts = [trim((string) ($row['foto_portada'] ?? ''))];
      $galleryIds = array_map('trim', explode(',', (string) ($row['galeria'] ?? '')));
      $parts = array_merge($parts, $gallery ? $galleryIds : array_slice($galleryIds, 0, 1));
      $plan[$code] = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
      foreach ($plan[$code] as $part) {
        if (ctype_digit($part)) {
          $ids[] = (int) $part;
        }
      }
    }
    $map = $this->repository->mediaUrls($ids);
    $out = [];
    foreach ($plan as $code => $parts) {
      $urls = [];
      foreach ($parts as $part) {
        // El panel y la cotización son https: las fotos de WordPress guardadas con http se piden por https.
        $url = (string) preg_replace('#^http://#i', 'https://', ctype_digit($part) ? ($map[(int) $part] ?? '') : self::safeUrl($part));
        if ($url !== '' && !in_array($url, $urls, true)) {
          $urls[] = $url;
        }
      }
      $out[(string) $code] = $gallery ? $urls : array_slice($urls, 0, 1);
    }
    return $out;
  }

  public static function portalUrl(string $code): string
  {
    return 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-' . rawurlencode($code);
  }

  private static function safeUrl(string $url): string
  {
    $url = trim($url);
    return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1 ? $url : '';
  }

  private static function amount(string $value): float
  {
    $digits = (string) preg_replace('/\D/', '', $value);
    return $digits === '' ? 0.0 : (float) $digits;
  }
}
