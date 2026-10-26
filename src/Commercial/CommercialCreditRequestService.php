<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SmsQueue;

/**
 * "Nueva solicitud de crédito" desde el panel comercial. Registra la solicitud en la misma tabla que usa el
 * liquidador de créditos de Control de Servicios Inmobiliarios (`scm_owner_credits`): aquí solo se registra lo
 * que pide el propietario; la evaluación, la aprobación y el rechazo siguen en el liquidador.
 *
 * La configuración de créditos (`owner_credit_settings`) y los destinatarios del evento interno
 * `credito_propietario_solicitado` (`internal_admin_notifications`) se administran en el panel inmobiliario,
 * por eso se leen, sin escribir, de su configuración (`control_servicios_config`).
 */
final class CommercialCreditRequestService
{
  public const PURPOSES = [
    'Pago de Impuestos',
    'Para Mejoras y/o Reparaciones al Inmueble',
    'Mejoras y/o Reparaciones a otro Inmueble',
    'Educación',
    'Libre Destinación',
    'Pago Expensas',
    'Compra de Electrodomésticos',
    'Equipos de Oficina',
  ];
  public const BENEFICIARY_TYPES = [
    'propietario' => 'Propietario',
    'arrendatario' => 'Arrendatario',
    'codeudor' => 'Codeudor',
    'externo' => 'Externo',
    'funcionario' => 'Funcionario',
  ];
  /** Tipos que ya tienen flujo en el liquidador; los demás se muestran como "Próximamente". */
  public const AVAILABLE_BENEFICIARY_TYPES = ['propietario'];
  public const EVENT_REQUESTED = 'credito_propietario_solicitado';
  private const DEDUCTIONS = ['comision', 'garantia', 'poliza_servicios', 'otros'];
  private const MAX_AMOUNT = 9999999999.0;

  private Database $db;
  private CommercialAccessPolicy $policy;
  private Settings $sharedSettings;
  /** @var array<string,mixed> */
  private array $config;
  /** @var array<string,array<int,string>> */
  private array $columns = [];

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, CommercialAccessPolicy $policy, array $config = [], ?Settings $sharedSettings = null)
  {
    $this->db = $db;
    $this->policy = $policy;
    $this->config = $config;
    $this->sharedSettings = $sharedSettings ?? new Settings($db, true, Settings::FUNCTION_KEY);
  }

  public function authorize(): void
  {
    if (!$this->policy->canView('solicitud_credito') || !$this->policy->canAct('solicitar_credito')) {
      throw new CommercialSignAccessDenied('Tu cargo no tiene permiso para registrar solicitudes de crédito.');
    }
  }

  private function table(): string { return $this->db->table('scm_owner_credits'); }
  private function eventsTable(): string { return $this->db->table('scm_owner_credit_events'); }

  /** Mismas tablas que crea el liquidador; solo se crean aquí si el panel comercial registra la primera solicitud. */
  public function ensureSchema(): void
  {
    if ($this->columnsOf($this->table()) === []) {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quote_id BIGINT UNSIGNED NULL, ticket_id VARCHAR(40) NOT NULL DEFAULT '',
        contract_id BIGINT UNSIGNED NOT NULL DEFAULT 0, contract_code VARCHAR(60) NOT NULL DEFAULT '',
        property VARCHAR(60) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '',
        owner_id VARCHAR(60) NOT NULL DEFAULT '', owner_name VARCHAR(190) NOT NULL DEFAULT '',
        owner_email VARCHAR(190) NOT NULL DEFAULT '', owner_phone VARCHAR(40) NOT NULL DEFAULT '', owner_document VARCHAR(40) NOT NULL DEFAULT '',
        beneficiary_type VARCHAR(20) NOT NULL DEFAULT 'propietario',
        purpose VARCHAR(120) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        requested_amount DECIMAL(14,2) NOT NULL DEFAULT 0, requested_term INT NOT NULL DEFAULT 0,
        vacancy VARCHAR(10) NOT NULL DEFAULT 'Baja', gross_income DECIMAL(14,2) NOT NULL DEFAULT 0,
        deductions_json TEXT NULL, net_income DECIMAL(14,2) NOT NULL DEFAULT 0,
        max_amount DECIMAL(14,2) NOT NULL DEFAULT 0, max_months INT NOT NULL DEFAULT 0,
        approved_amount DECIMAL(14,2) NULL, approved_term INT NULL, approved_rate DECIMAL(10,6) NULL,
        monthly_payment DECIMAL(14,2) NULL, total_interest DECIMAL(14,2) NULL, first_payment_ts BIGINT NULL,
        policy_json MEDIUMTEXT NULL, evaluation_json MEDIUMTEXT NULL, policy_override TINYINT NOT NULL DEFAULT 0,
        decision_note TEXT NULL, decided_by_id VARCHAR(40) NOT NULL DEFAULT '', decided_by_name VARCHAR(190) NOT NULL DEFAULT '',
        decided_at BIGINT NULL, closed_at BIGINT NULL,
        created_by_id VARCHAR(40) NOT NULL DEFAULT '', created_by_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        UNIQUE KEY uniq_quote (quote_id), KEY idx_status (status), KEY idx_owner (owner_id), KEY idx_contract (contract_id), KEY idx_beneficiary_type (beneficiary_type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      unset($this->columns[$this->table()]);
    }
    if ($this->columnsOf($this->eventsTable()) === []) {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->eventsTable()}` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credit_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(40) NOT NULL, actor_id VARCHAR(40) NOT NULL DEFAULT '', actor_name VARCHAR(190) NOT NULL DEFAULT '',
        details_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, KEY idx_credit (credit_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      unset($this->columns[$this->eventsTable()]);
    }
  }

  /**
   * Contratos Entregado o Por entregar donde el documento (cédula o NIT) es de un propietario, con esa persona
   * ya resuelta como beneficiaria. Misma búsqueda que "Nueva solicitud" del liquidador.
   * @return list<array<string,mixed>>
   */
  public function contractsByOwnerDocument(string $document): array
  {
    $this->authorize();
    $document = self::document($document);
    if (preg_match('/^[0-9A-Z-]{4,20}$/', $document) !== 1) throw new \InvalidArgumentException('Escribe el documento o NIT del propietario (mínimo 4 caracteres).');
    $clean = static fn(string $column): string => "UPPER(REPLACE(REPLACE({$column}, '.', ''), ' ', '')) = ?";
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = [];
    $args = [];
    if ($this->hasColumn($contracts, 'documento_propietario')) {
      $where[] = $clean('`documento_propietario`');
      $args[] = $document;
    }
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $owners = $this->db->table('jet_cct_propietarios');
    foreach ([['id_contrato_mandato', $mandates, ['documento_1', 'nit_1', 'documento_2', 'nit_2', 'documento_3', 'nit_3', 'documento_4', 'nit_4', 'documento_5', 'nit_5', 'documento_6', 'nit_6'], ['_ID']], ['id_propietario', $owners, ['documento', 'documento_juridico'], ['_ID', 'id_propietario']]] as [$link, $source, $documentColumns, $idColumns]) {
      if (!$this->hasColumn($contracts, $link)) continue;
      $documentColumns = array_values(array_filter($documentColumns, fn(string $column): bool => $this->hasColumn($source, $column)));
      $idColumns = array_values(array_filter($idColumns, fn(string $column): bool => $this->hasColumn($source, $column)));
      if ($documentColumns === [] || $idColumns === []) continue;
      $ids = [];
      $rows = $this->db->getResults('SELECT `' . implode('`, `', $idColumns) . "` FROM `{$source}` WHERE " . implode(' OR ', array_map(static fn(string $column): string => $clean("`{$column}`"), $documentColumns)) . ' LIMIT 100', array_fill(0, count($documentColumns), $document));
      foreach ($rows as $row) foreach ($idColumns as $column) if (trim((string) ($row[$column] ?? '')) !== '') $ids[trim((string) $row[$column])] = true;
      if ($ids === []) continue;
      $where[] = "`{$link}` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
      array_push($args, ...array_map('strval', array_keys($ids)));
    }
    if ($where === []) return [];
    $results = [];
    // Solo contratos vigentes o por iniciar: de los demás no hay canon del cual descontar.
    foreach ($this->db->getResults("SELECT * FROM `{$contracts}` WHERE (" . implode(' OR ', $where) . ") AND LOWER(TRIM(`estado`)) IN ('entregado', 'por entregar') ORDER BY (LOWER(TRIM(`estado`)) = 'entregado') DESC, `_ID` DESC LIMIT 30", $args) as $contract) {
      $candidates = $this->contractOwners((int) $contract['_ID']);
      $owner = null;
      foreach ($candidates as $candidate) if (self::document($candidate['document']) === $document) $owner = $candidate;
      // El documento estaba en el contrato o en la ficha del propietario: es el propietario principal.
      if ($owner === null) $owner = $candidates[0] ?? null;
      if ($owner === null) continue;
      if ($owner['document'] === '') $owner['document'] = $document;
      $end = self::timestamp($contract['fin_contrato'] ?? '');
      $results[] = [
        'id' => (int) ($contract['_ID'] ?? 0), 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
        'direccion' => (string) ($contract['direccion'] ?? ''), 'estado' => (string) ($contract['estado'] ?? ''), 'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
        'fin' => $end > 0 ? date('d/m/Y', $end) : '', 'max_months' => self::monthsUntil(time(), $end),
        'owner' => array_intersect_key($owner, array_flip(['key', 'name', 'document', 'email', 'phone'])),
      ];
    }
    return $results;
  }

  /**
   * Registra la solicitud como pendiente y avisa al evento interno. El plazo frente a la fecha fin no se valida:
   * el consultor no conoce esa fecha; si la supera, el analista la revisa en el liquidador.
   * @param array<string,mixed> $input
   * @return array{credit_id:int,message:string}
   */
  public function create(array $input): array
  {
    $this->authorize();
    $type = trim((string) ($input['beneficiary_type'] ?? 'propietario'));
    if (!isset(self::BENEFICIARY_TYPES[$type])) throw new \InvalidArgumentException('Indica para quién es el crédito.');
    if (!in_array($type, self::AVAILABLE_BENEFICIARY_TYPES, true)) throw new \InvalidArgumentException('Los créditos a ' . mb_strtolower(self::BENEFICIARY_TYPES[$type]) . ' todavía no están disponibles.');
    $contract = $this->contractById((int) ($input['contract_id'] ?? 0));
    if ($contract === null) throw new \InvalidArgumentException('Busca al propietario por documento y elige el contrato.');
    if (!in_array(mb_strtolower(trim((string) ($contract['estado'] ?? ''))), ['entregado', 'por entregar'], true)) throw new \InvalidArgumentException('El contrato debe estar Entregado o Por entregar.');
    $owners = $this->contractOwners((int) $contract['_ID']);
    $ownerKey = trim((string) ($input['owner_key'] ?? ''));
    $owner = null;
    foreach ($owners as $candidate) if ($candidate['key'] === $ownerKey) $owner = $candidate;
    if ($owner === null) {
      if (count($owners) > 1) throw new \InvalidArgumentException('Selecciona a quién se le hace el crédito.');
      $owner = $owners[0] ?? null;
    }
    if ($owner === null) throw new \InvalidArgumentException('El contrato no tiene propietario registrado.');
    $amount = round(self::money($input['amount'] ?? ''), 2);
    if ($amount <= 0) throw new \InvalidArgumentException('Indica el valor solicitado.');
    if ($amount > self::MAX_AMOUNT) throw new \InvalidArgumentException('El valor solicitado no es válido.');
    $term = (int) ($input['term'] ?? 0);
    if ($term <= 0 || $term > 60) throw new \InvalidArgumentException('Indica el plazo solicitado en meses (entre 1 y 60).');
    $purpose = trim((string) ($input['purpose'] ?? ''));
    if (!in_array($purpose, self::PURPOSES, true)) $purpose = self::PURPOSES[0];
    $actor = $this->actor();

    $this->ensureSchema();
    $income = $this->defaultIncome($contract);
    $now = time();
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->insert($this->table(), $this->filter($this->table(), [
        'quote_id' => null, 'ticket_id' => '',
        'contract_id' => (int) $contract['_ID'], 'contract_code' => (string) ($contract['contrato'] ?? ''),
        'property' => (string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''), 'address' => mb_substr((string) ($contract['direccion'] ?? ''), 0, 255),
        'owner_id' => $owner['owner_id'], 'owner_name' => mb_substr($owner['name'], 0, 190), 'owner_email' => mb_substr($owner['email'], 0, 190),
        'owner_phone' => mb_substr($owner['phone'], 0, 40), 'owner_document' => mb_substr($owner['document'], 0, 40),
        'beneficiary_type' => $type, 'purpose' => $purpose, 'status' => 'pendiente', 'requested_amount' => $amount, 'requested_term' => $term,
        'vacancy' => 'Baja', 'gross_income' => $income['gross'], 'deductions_json' => json_encode($income['deductions'], JSON_THROW_ON_ERROR),
        'created_by_id' => $actor['employee_id'], 'created_by_name' => $actor['name'], 'created_at' => $now, 'updated_at' => $now,
      ]));
      $id = (int) $this->db->lastInsertId();
      if ($id <= 0) throw new \RuntimeException('No se pudo registrar la solicitud de crédito.');
      $this->db->insert($this->eventsTable(), [
        'credit_id' => $id, 'action' => 'solicitado', 'actor_id' => $actor['employee_id'], 'actor_name' => $actor['name'],
        'details_json' => json_encode(['cotizacion' => null, 'valor' => $amount, 'origen' => 'Servicios comerciales'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => $now,
      ]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }

    $credit = $this->find($id) ?? [];
    $money = '$' . number_format($amount, 0, ',', '.');
    $this->recordHistories($contract, 'Solicitud de crédito', 'Crédito #' . $id . ' solicitado por el propietario ' . $owner['name'] . ': ' . $money . ' a ' . $term . ' meses (' . $purpose . '). Registrado desde Servicios comerciales; pendiente de evaluar en el liquidador.', $actor);
    $this->notifyRequested($credit);
    return ['credit_id' => $id, 'message' => 'Solicitud de crédito #' . $id . ' registrada para ' . $owner['name'] . ' por ' . $money . '. El analista la evaluará en el liquidador de créditos.'];
  }

  /** @return array<string,mixed>|null */
  private function find(int $id): ?array
  {
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1", [$id]);
    return is_array($row) ? $row : null;
  }

  /** El autor de los registros es el id_empleado real del funcionario, no el _ID de la sesión. @return array{employee_id:string,name:string} */
  private function actor(): array
  {
    $employee = $this->db->getRow('SELECT `id_empleado`, `nombre` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]);
    $id = trim((string) ($employee['id_empleado'] ?? ''));
    if ($id === '') $id = trim(Auth::employeeId());
    if ($id === '') throw new \RuntimeException('No se pudo identificar al funcionario.');
    $name = trim((string) ($employee['nombre'] ?? ''));
    return ['employee_id' => $id, 'name' => $name !== '' ? $name : Auth::user()];
  }

  /**
   * Ingreso del propietario con el que el liquidador abre la solicitud: canon (o el de la última carta de aumento)
   * y descuentos del contrato de mandato; sin mandato, el porcentaje configurado del canon.
   * @return array{gross:float,deductions:array<string,float>}
   */
  private function defaultIncome(array $contract): array
  {
    $canon = self::money($contract['valor_canon'] ?? '');
    $letterId = (int) ($contract['id_carta_aumento_canon'] ?? 0);
    $letters = $this->db->table('jet_cct_cartas_aumento');
    if ($letterId > 0 && $this->hasColumn($letters, 'canon')) $canon = max($canon, self::money($this->db->getVar("SELECT `canon` FROM `{$letters}` WHERE `_ID` = ? LIMIT 1", [$letterId]) ?? ''));
    $deductions = array_fill_keys(self::DEDUCTIONS, 0.0);
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $mandate = $mandateId > 0 && $this->columnsOf($mandates) !== [] ? $this->db->getRow("SELECT * FROM `{$mandates}` WHERE `_ID` = ? LIMIT 1", [$mandateId]) : null;
    if (is_array($mandate) && self::money($mandate['total_comision'] ?? '') > 0) {
      $deductions['comision'] = self::money($mandate['total_comision'] ?? '');
      $deductions['garantia'] = self::isYes($mandate['incluye_garantia'] ?? '') ? self::money($mandate['precio_mes_garantia'] ?? '') : 0.0;
      $deductions['poliza_servicios'] = self::isYes($mandate['incluye_garantia_servicios'] ?? '') ? self::money($mandate['precio_mes_servicios'] ?? '') : 0.0;
      return ['gross' => $canon, 'deductions' => $deductions];
    }
    $deductions['otros'] = round($canon * $this->noMandateDeduction());
    return ['gross' => $canon, 'deductions' => $deductions];
  }

  /** Porcentaje del canon que el liquidador descuenta sin mandato (configuración de créditos, 15 % por defecto). */
  private function noMandateDeduction(): float
  {
    $stored = $this->sharedSetting('owner_credit_settings');
    $text = str_replace(',', '.', trim((string) ($stored['no_mandate_deduction'] ?? '')));
    if ($text === '' || !is_numeric(rtrim($text, '%'))) return 0.15;
    $number = (float) rtrim($text, '%');
    if (str_ends_with($text, '%') || $number >= 1) $number /= 100;
    return max(0.0, min(1.0, $number));
  }

  /**
   * Propietarios del contrato de mandato (1 a 6) o, sin mandato, el propietario del contrato de arrendamiento.
   * @return list<array{key:string,name:string,document:string,email:string,phone:string,owner_id:string}>
   */
  private function contractOwners(int $contractId): array
  {
    $contract = $this->contractById($contractId);
    if ($contract === null) return [];
    $owners = [];
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $mandate = $mandateId > 0 && $this->columnsOf($mandates) !== [] ? $this->db->getRow("SELECT * FROM `{$mandates}` WHERE `_ID` = ? LIMIT 1", [$mandateId]) : null;
    if (is_array($mandate)) {
      $count = max(1, min(6, (int) ($mandate['propietarios'] ?? 1)));
      for ($i = 1; $i <= $count; $i++) {
        $company = strcasecmp(trim((string) ($mandate['tipo_' . $i] ?? '')), 'Natural') !== 0 && trim((string) ($mandate['empresa_' . $i] ?? '')) !== '';
        $name = trim((string) ($company ? $mandate['empresa_' . $i] : ($mandate['nombre_' . $i] ?? '')));
        if ($name === '') continue;
        $document = trim((string) ($company ? ($mandate['nit_' . $i] ?? '') : ($mandate['documento_' . $i] ?? '')));
        $ownerId = trim((string) ($mandate['id_propietario_nuevo_' . $i] ?? ''));
        if ($ownerId === '') $ownerId = $i === 1 ? (string) ($contract['id_propietario'] ?? '') : 'doc:' . $document;
        $owners[] = [
          'key' => 'mandato_' . $i, 'name' => $name, 'document' => $document,
          'email' => trim((string) ($mandate['correo_' . $i] ?? '')),
          'phone' => self::phone((string) ($mandate['celular_' . $i] ?? ''), (string) ($mandate['indicativo_' . $i] ?? '')),
          'owner_id' => $ownerId,
        ];
      }
    }
    if ($owners === []) {
      $owners[] = [
        'key' => 'contrato', 'name' => trim((string) ($contract['propietario'] ?? '')), 'document' => '',
        'email' => trim((string) ($contract['correo_propietario'] ?? '')),
        'phone' => self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? '')),
        'owner_id' => (string) ($contract['id_propietario'] ?? ''),
      ];
    }
    return $owners;
  }

  /** La solicitud queda en el historial del contrato y del inmueble (indicando el contrato). */
  private function recordHistories(array $contract, string $type, string $message, array $actor): void
  {
    $now = date('Y-m-d H:i:s');
    $common = [
      'cct_status' => 'publish', 'cct_author_id' => $actor['employee_id'], 'cct_created' => $now, 'cct_modified' => $now,
      'id_empleado' => $actor['employee_id'], 'funcionario' => $actor['name'],
      'id_contrato' => (string) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
      'fecha' => time(), 'tipo_reporte' => $type,
    ];
    try {
      $contractTable = $this->db->table('jet_cct_historial_contrato_arrendamiento');
      $row = $this->filter($contractTable, $common + ['observacion' => $message]);
      if ($row !== []) $this->db->insert($contractTable, $row);
      $propertyTable = $this->db->table('jet_cct_historial_del_inmueble');
      $note = 'Contrato #' . trim((string) ($contract['contrato'] ?? '')) . ': ' . $message;
      $row = $this->filter($propertyTable, $common + ['nombre_empleado' => $actor['name'], 'tipo_de_reporte_his' => $type, 'observacion' => $note, 'observacion_his' => $note]);
      if ($row !== []) $this->db->insert($propertyTable, $row);
    } catch (\Throwable $error) {
      error_log('[commercial-credit-history] ' . $error->getMessage());
    }
  }

  /** Correo y WhatsApp a los funcionarios del evento interno, igual que una solicitud creada en el liquidador. */
  private function notifyRequested(array $credit): void
  {
    if ($credit === []) return;
    $id = (int) $credit['id'];
    $money = '$' . number_format((float) $credit['requested_amount'], 0, ',', '.');
    $contacts = $this->internalContacts(self::EVENT_REQUESTED);
    $url = trim((string) ($this->config['owner_credits_url'] ?? ''));
    try {
      $emails = array_values(array_unique(array_filter(array_map(static fn(array $contact): string => strtolower($contact['email']), $contacts), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
      if ($emails !== []) {
        $title = 'Solicitud de crédito de propietario #' . $id;
        $details = 'El propietario ' . $credit['owner_name'] . ' solicitó financiar ' . $money . ' (contrato ' . $credit['contract_code'] . ', inmueble ' . $credit['property'] . '). Registró: ' . $credit['created_by_name'] . ' desde Servicios comerciales. Revísalo en el liquidador de créditos.';
        (new EmailQueue($this->db))->enqueue($emails, $title, EmailTemplate::render($title, '<p>' . EmailTemplate::e($details) . '</p>', $url !== '' ? ['buttons' => [['url' => $url, 'label' => 'Abrir créditos']]] : []), [
          'source_module' => self::EVENT_REQUESTED, 'internal_action' => self::EVENT_REQUESTED, 'internal_cc' => $emails,
          'dedupe_key' => 'internal-admin:' . self::EVENT_REQUESTED . ':solicitado:' . $id, 'meta' => ['event' => self::EVENT_REQUESTED, 'credit_id' => $id],
        ]);
      }
    } catch (\Throwable $error) {
      error_log('[commercial-credit-internal] ' . $error->getMessage());
    }
    $this->notifyStaffWhatsapp($credit, $contacts, $url, 'Solicita ' . $money . '. Revísala en el liquidador');
  }

  /** Plantilla scm_credito_propietario_funcionario_v2; su botón necesita la ruta del liquidador (OWNER_CREDITS_URL). */
  private function notifyStaffWhatsapp(array $credit, array $contacts, string $url, string $detail): void
  {
    $settings = $this->sharedSetting('owner_credit_settings');
    if ($url === '' || !in_array(strtolower(trim((string) ($settings['notify_staff_whatsapp'] ?? ''))), ['1', 'true', 'si', 'sí', 'on', 'yes'], true)) return;
    try {
      $parts = parse_url($url);
      $suffix = ltrim((string) ($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
      $clean = static fn($value, int $limit): string => mb_substr(preg_replace('/\s+/u', ' ', trim((string) $value)) ?: 'Sin dato', 0, $limit);
      $sms = new SmsQueue($this->db);
      $sent = [];
      foreach ($contacts as $contact) {
        $phone = preg_replace('/\D+/', '', $contact['phone']) ?? '';
        if ($phone === '' || isset($sent[$phone])) continue;
        $values = [$clean($contact['name'] ?: 'Funcionario', 80), 'Nueva solicitud de crédito', (string) (int) $credit['id'], $clean($credit['contract_code'], 40), $clean($credit['owner_name'], 120), $clean($detail, 400)];
        $ok = $sms->enqueue($contact['phone'], $contact['name'], 'Nueva solicitud de crédito #' . $credit['id'] . ': ' . $detail, [
          'source_module' => self::EVENT_REQUESTED, 'dedupe_key' => 'owner-credit-staff-wa:' . self::EVENT_REQUESTED . ':' . $credit['id'] . ':pendiente:' . $phone,
          'template_name' => 'scm_credito_propietario_funcionario_v2', 'template_language' => 'es_CO',
          'template_components' => [
            ['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $values)],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
          ],
          'credit_id' => (int) $credit['id'],
        ]);
        if ($ok) $sent[$phone] = true;
      }
    } catch (\Throwable $error) {
      error_log('[commercial-credit-staff-wa] ' . $error->getMessage());
    }
  }

  /** Funcionarios activos elegidos en Notificaciones internas del panel inmobiliario. @return list<array{name:string,email:string,phone:string}> */
  private function internalContacts(string $action): array
  {
    try {
      $ids = array_values(array_filter(array_map('intval', (array) ($this->sharedSetting('internal_admin_notifications')[$action] ?? [])), static fn(int $id): bool => $id > 0));
      if ($ids === []) return [];
      $rows = $this->db->getResults('SELECT * FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
      $contacts = [];
      foreach ($rows as $row) {
        if (array_key_exists('activo', $row) && !in_array(mb_strtolower(trim((string) $row['activo'])), ['si', 'sí', '1', 'true', 'activo', 'active', 'publish'], true)) continue;
        $contacts[] = ['name' => trim((string) ($row['nombre'] ?? '')), 'email' => trim((string) ($row['correo'] ?? '')), 'phone' => trim((string) ($row['celular'] ?? ''))];
      }
      return $contacts;
    } catch (\Throwable $error) {
      error_log('[commercial-credit-recipients] ' . $error->getMessage());
      return [];
    }
  }

  /** @return array<string,mixed> */
  private function sharedSetting(string $key): array
  {
    try {
      $value = $this->sharedSettings->get($key, []);
      return is_array($value) ? $value : [];
    } catch (\Throwable $error) {
      error_log('[commercial-credit-settings] ' . $error->getMessage());
      return [];
    }
  }

  /** @return array<string,mixed>|null */
  private function contractById(int $id): ?array
  {
    if ($id <= 0) return null;
    $row = $this->db->getRow("SELECT * FROM `{$this->db->table('jet_cct_contratos_arrendamiento')}` WHERE `_ID` = ? LIMIT 1", [$id]);
    return is_array($row) ? $row : null;
  }

  /** Columnas de una tabla (vacío si no existe); funciona en MySQL y en la base SQLite de las pruebas. @return array<int,string> */
  private function columnsOf(string $table): array
  {
    if (isset($this->columns[$table])) return $this->columns[$table];
    $columns = [];
    try {
      $statement = $this->db->pdo()->query("SELECT * FROM `{$table}` LIMIT 0");
      if ($statement !== false) {
        for ($i = 0; $i < $statement->columnCount(); $i++) {
          $meta = $statement->getColumnMeta($i);
          if ($meta !== false) $columns[] = $meta['name'];
        }
      }
    } catch (\PDOException $error) {
      $columns = [];
    }
    return $this->columns[$table] = $columns;
  }

  private function hasColumn(string $table, string $column): bool
  {
    return in_array($column, $this->columnsOf($table), true);
  }

  /** @param array<string,mixed> $data @return array<string,mixed> */
  private function filter(string $table, array $data): array
  {
    return array_intersect_key($data, array_flip($this->columnsOf($table)));
  }

  /** Meses (de 30 días) entre la solicitud y el fin del contrato, con la misma regla del liquidador. */
  private static function monthsUntil(int $from, int $to): int
  {
    if ($from <= 0 || $to <= 0) return 0;
    $days = (int) floor(((int) strtotime(date('Y-m-d', $to)) - (int) strtotime(date('Y-m-d', $from))) / 86400);
    return max(0, (int) floor($days / 30));
  }

  private static function document(string $value): string
  {
    return strtoupper(preg_replace('/[\s.]+/', '', trim($value)) ?? '');
  }

  /** Celular con indicativo en dígitos (+57 314 5106244 → 573145106244). */
  private static function phone(string $phone, string $prefix): string
  {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $prefixDigits = preg_replace('/\D/', '', $prefix) ?? '';
    if ($digits === '') return '';
    if (str_starts_with(trim($phone), '+') || $prefixDigits === '' || str_starts_with($digits, $prefixDigits)) return $digits;
    return $prefixDigits . $digits;
  }

  public static function money($value): float
  {
    if (is_int($value) || is_float($value)) return max(0.0, (float) $value);
    $text = preg_replace('/[^\d,.\-]/', '', trim((string) $value)) ?? '';
    if ($text === '') return 0.0;
    if (str_contains($text, ',') && str_contains($text, '.')) {
      $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
    } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $text) === 1) {
      $text = str_replace('.', '', $text);
    } elseif (str_contains($text, ',')) {
      $text = preg_match('/^\d{1,3}(?:,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    }
    return is_numeric($text) ? max(0.0, (float) $text) : 0.0;
  }

  private static function timestamp($value): int
  {
    $text = trim((string) $value);
    if ($text === '') return 0;
    if (ctype_digit($text)) {
      $number = (int) $text;
      return $number > 9999999999 ? intdiv($number, 1000) : $number;
    }
    return (int) (strtotime($text) ?: 0);
  }

  private static function isYes($value): bool
  {
    return in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['si', 'sí', '1', 'yes'], true);
  }
}
