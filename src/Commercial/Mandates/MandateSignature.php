<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Envío del contrato de mandato a firma por la API de eSignatures.com.
 *
 * Copia de formularios/comun/mandato_firma.php (firma_valores, firma_tabla_propietarios, firma_tabla_acuerdos,
 * firma_firmantes, firma_contrato, firma_modificar_existente, firma_enviar_mandato) y comun/esignatures.php: mantener
 * iguales. Lo técnico (plantillas, títulos, roles) va fijo aquí; el token secreto en el .env (ESIGNATURES_TOKEN).
 *
 * - Plantilla según el tipo de contrato. Firmantes: 1) cada propietario (persona jurídica: la empresa), 2) quien creó el
 *   mandato con firma automática, 3) el representante legal.
 * - Reenvío: firmado por todos → error; enviado o borrador con la misma plantilla y cantidad de propietarios → se
 *   modifica el mismo contrato; si no se puede → se crea uno nuevo y se anula el anterior.
 */
final class MandateSignature
{
  public const TEMPLATES = [
    'Por defecto' => 'a831382a-5797-49fa-9501-e3d9caac9b49',
    'Berkley' => 'dfb25187-7be8-40e8-b0a4-3d6bad9519bb',
    'Seguros mundial' => 'd75dd25d-68f0-470c-b6ff-1f1773eb747b',
  ];
  /** Título en eSignatures, como lo enviaba Make: mandato e inmueble. */
  public const TITLE = 'CONTRATO DE MANDATO #%d PARA LA ADMINISTRACION DEL INMUEBLE #%s';
  public const REDIRECT_URL = 'https://sucasainmobiliaria.com.co/gracias-por-su-firma/';
  public const ROLE_OWNER = 'PROPIETARIO';
  public const ROLE_EMPLOYEE = 'QUIEN ELABORA EN REPRESENTACIÓN DEL ADMINISTRADOR';
  public const LEGAL_REPRESENTATIVE = ['nombre' => 'NASSIF ABUITA NASSAR', 'email' => 'skcgerencia@hotmail.com', 'mobile' => '+573114156735', 'rol' => 'REPRESENTANTE LEGAL'];
  public const API = 'https://esignatures.com/api';
  public const NOT_APPLICABLE = 'No aplica';

  private const OWNER_FIELDS = ['calidad', 'tipo_persona', 'nombre', 'documento', 'expedicion', 'nombre_juridico', 'documento_juridico', 'direccion', 'correo', 'celular', 'fallecimiento', 'porcentaje', 'tipo_giro', 'nombre_giro', 'titular', 'documento_cuenta', 'tipo_cuenta', 'numero_cuenta', 'banco', 'correo_cuenta'];
  private const COMMON_FIELDS = ['escritura', 'fecha_inmueble', 'circulo_notarial', 'matricula_inmobiliaria', 'referencia_catastral', 'barrio', 'direccion', 'direccion_fisica', 'area_construida', 'propiedad_horizontal', 'estrato', 'tiene_deposito', 'depositos', 'tiene_parqueadero', 'parqueadero', 'tipo_parqueadero', 'numero_parqueadero', 'tiene_vigilancia', 'equipos_entregados', 'observaciones', 'precio_administracion', 'responsable_admin', 'nombre_copropiedad', 'nit_copropiedad', 'administrador', 'telefono_copropiedad', 'correo_copropiedad', 'tipo_cuenta_copropiedad', 'numero_cuenta_copropiedad', 'banco_copropiedad', 'energia', 'agua', 'gas', 'tv', 'telefono', 'internet', 'otros_servicios', 'precio_canon', 'tiene_iva_canon', 'iva_canon', 'tasa_comision', 'comision_sin_iva', 'iva_comision', 'total_comision', 'nota_equipos', 'precio_canon_total', 'incremento_pactado', 'duracion', 'dias_gracia', 'rete_fuente', 'rete_ica', 'rete_iva', 'adulto_mayor', 'pro_universitario', 'recreacion_deporte', 'acuerdo', 'adendas', 'existe_simi', 'codigo_simi', 'mismo_propietario', 'codigo_inmueble', 'inmueble-pagina', 'clasificacion'];
  private const TYPE_FIELDS = [
    'Por defecto' => ['incluye_garantia', 'aseguradora', 'tasa_garantia', 'valor_garantia', 'meses_cobertura_garantia', 'incluye_garantia_servicios', 'valor_asegurado_garantia_servicios', 'cobertura_garantia_servicios', 'forma_pago_garantia_servicios', 'prima_mes_garantia_servicios', 'prima_anual_garantia_servicios', 'incluye_clausula_penal', 'tasa_clausula_penal', 'precio_clausula_penal'],
    'Berkley' => ['tasa_berkley', 'incluye_servicios_berkley', 'valor_asegurado_berkley', 'forma_pago_berkley', 'prima_mes_berkley', 'prima_anual_berkley'],
    'Seguros mundial' => ['tasa_seguros_mundial', 'incluye_servicios_seguros_mundial', 'valor_asegurado_seguros_mundial', 'forma_pago_seguros_mundial', 'prima_mes_seguros_mundial', 'total_prima_seguros_mundial'],
  ];
  private const MONEY = ['precio_canon', 'precio_administracion', 'precio_canon_total', 'iva_canon', 'comision_sin_iva', 'iva_comision', 'total_comision', 'valor_garantia', 'valor_asegurado_garantia_servicios', 'prima_mes_garantia_servicios', 'prima_anual_garantia_servicios', 'precio_clausula_penal', 'valor_asegurado_berkley', 'prima_mes_berkley', 'prima_anual_berkley', 'valor_asegurado_seguros_mundial', 'prima_mes_seguros_mundial', 'total_prima_seguros_mundial'];
  private const PERCENT = ['tasa_comision', 'tasa_garantia', 'tasa_clausula_penal', 'tasa_berkley', 'tasa_seguros_mundial', 'rete_fuente', 'rete_ica', 'rete_iva', 'adulto_mayor', 'pro_universitario', 'recreacion_deporte'];
  private const ONLY_IF = [
    // Sin propiedad horizontal no hay copropiedad (el formulario no la guarda; datos viejos del inmueble no deben salir).
    'propiedad_horizontal' => ['nombre_copropiedad', 'nit_copropiedad', 'administrador', 'telefono_copropiedad', 'correo_copropiedad', 'tipo_cuenta_copropiedad', 'numero_cuenta_copropiedad', 'banco_copropiedad', 'responsable_admin'],
    'incluye_garantia' => ['aseguradora', 'tasa_garantia', 'valor_garantia', 'meses_cobertura_garantia'],
    'incluye_garantia_servicios' => ['valor_asegurado_garantia_servicios', 'cobertura_garantia_servicios', 'forma_pago_garantia_servicios', 'prima_mes_garantia_servicios', 'prima_anual_garantia_servicios'],
    'incluye_clausula_penal' => ['tasa_clausula_penal', 'precio_clausula_penal'],
    'incluye_servicios_berkley' => ['valor_asegurado_berkley', 'forma_pago_berkley'],
    'incluye_servicios_seguros_mundial' => ['valor_asegurado_seguros_mundial', 'forma_pago_seguros_mundial'],
  ];

  /** @var callable(string,string,?array<string,mixed>):array<string,mixed> */
  private $http;
  private bool $test;

  /**
   * @param (callable(string,string,?array<string,mixed>):array<string,mixed>)|null $http Llamada a la API (pruebas); por defecto, curl con el token.
   */
  public function __construct(string $token, bool $test = false, ?callable $http = null)
  {
    $this->test = $test;
    $this->http = $http ?? static function (string $method, string $path, ?array $body) use ($token): array {
      return self::curl($token, $method, $path, $body);
    };
  }

  /** Desde la configuración (ESIGNATURES_TOKEN y ESIGNATURES_TEST); null si no hay token. @param array<string,mixed> $config */
  public static function fromConfig(array $config): ?self
  {
    $token = trim((string) ($config['esignatures_token'] ?? ''));
    return $token === '' ? null : new self($token, (bool) ($config['esignatures_test'] ?? false));
  }

  /**
   * Envía el mandato a firma y devuelve el id del contrato (el mismo si se pudo modificar, o uno nuevo).
   * @param array<string,mixed> $post Campos del formulario (MandateFields::toForm + quien elabora).
   * @param array<string,string>|null $credit Crédito recalculado en el servidor.
   */
  public function send(array $post, int $id, ?array $credit, string $previous): string
  {
    $body = $this->contract($post, $id, $credit);
    $previousActive = false;
    if ($previous !== '') {
      $contract = ($this->http)('GET', 'contracts/' . rawurlencode($previous), null)['data']['contract'] ?? [];
      $status = (string) ($contract['status'] ?? '');
      if ($status === 'signed') {
        throw new \InvalidArgumentException('Este mandato ya fue firmado por todos en eSignatures; no se puede modificar ni reenviar.');
      }
      $previousActive = in_array($status, ['sent', 'draft'], true);
      if ($previousActive) {
        try {
          if ($this->modify($previous, $contract, $body)) {
            return $previous;
          }
        } catch (\Throwable $error) {
          error_log('[mandatos:firma] Mandato ' . $id . ': no se pudo modificar el contrato ' . $previous . ', se envía uno nuevo: ' . $error->getMessage());
        }
      }
    }
    try {
      $response = ($this->http)('POST', 'contracts', $body);
    } catch (\RuntimeException $error) {
      // Si quien creó el mandato no es usuario de eSignatures, se envía sin dueño antes que dejar de enviarlo.
      if (($body['assigned_user_email'] ?? '') === '') {
        throw $error;
      }
      error_log('[mandatos:firma] Mandato ' . $id . ': no se pudo asignar a ' . $body['assigned_user_email'] . ', se envía sin dueño: ' . $error->getMessage());
      unset($body['assigned_user_email']);
      $response = ($this->http)('POST', 'contracts', $body);
    }
    $new = (string) ($response['data']['contract']['id'] ?? '');
    if ($new === '') {
      throw new \RuntimeException('eSignatures no devolvió el id del contrato.');
    }
    // Primero se crea el nuevo y después se anula el anterior: el mandato nunca queda sin contrato.
    if ($previousActive) {
      try {
        ($this->http)('POST', 'contracts/' . rawurlencode($previous) . '/withdraw', null);
      } catch (\Throwable $error) {
        error_log('[mandatos:firma] Mandato ' . $id . ': no se pudo anular el contrato anterior ' . $previous . ': ' . $error->getMessage());
      }
    }
    return $new;
  }

  public static function title(int $id, string $property): string
  {
    $property = self::text($property);
    return $property === '' ? 'CONTRATO DE MANDATO #' . $id : sprintf(self::TITLE, $id, $property);
  }

  /** Texto (Markdown) de la plantilla de eSignatures del tipo de contrato; '' si no tiene. */
  public function templateMarkdown(string $type): string
  {
    $template = self::TEMPLATES[$type] ?? '';
    return $template === '' ? '' : (string) (($this->http)('GET', 'templates/' . rawurlencode($template) . '/content', null)['data']['markdown'] ?? '');
  }

  /**
   * Reemplaza todo el texto de la plantilla en eSignatures (POST templates/{id}/content con find_markdown vacío) y
   * devuelve el texto que quedó.
   */
  public function replaceTemplateMarkdown(string $type, string $markdown): string
  {
    $template = self::TEMPLATES[$type] ?? '';
    if ($template === '') {
      throw new \InvalidArgumentException('No hay plantilla de eSignatures para «' . $type . '».');
    }
    $response = ($this->http)('POST', 'templates/' . rawurlencode($template) . '/content', ['dry_run' => 'no', 'edits' => [['find_markdown' => '', 'replace_with_markdown' => $markdown]]]);
    return (string) ($response['data']['markdown'] ?? $markdown);
  }

  /** Estado y PDF del contrato (para el aviso de firma). @return array<string,mixed> */
  public function contractInfo(string $contract): array
  {
    return ($this->http)('GET', 'contracts/' . rawurlencode($contract), null)['data']['contract'] ?? [];
  }

  /** @param array<string,mixed> $post @param array<string,string>|null $credit @return array<string,mixed> Cuerpo de POST /contracts. */
  public function contract(array $post, int $id, ?array $credit): array
  {
    $type = self::text($post['tipo_contrato'] ?? '');
    $template = self::TEMPLATES[$type] ?? '';
    if ($template === '') {
      throw new \RuntimeException('No hay plantilla de eSignatures para el tipo de contrato «' . $type . '».');
    }
    $fields = [];
    foreach (self::values($post, $credit) as $key => $value) {
      $fields[] = ['placeholder_key' => $key, 'replace_with_text' => $value];
    }
    $fields[] = ['placeholder_key' => 'tabla_propietarios', 'replace_with_markdown' => self::ownersTable($post)];
    $fields[] = ['placeholder_key' => 'tabla_acuerdos', 'replace_with_markdown' => self::agreementsTable($post)];
    return [
      'template_id' => $template,
      'title' => self::title($id, (string) ($post['id_inmueble'] ?? '')),
      'locale' => 'es',
      'test' => $this->test ? 'yes' : 'no',
      // La API no devuelve el template_id: queda aquí para saber si al reenviar se puede modificar este mismo contrato.
      'metadata' => 'mandato:' . $id . ';plantilla:' . $template,
      'labels' => ['Mandato', $type],
      'signers' => self::signers($post),
      // "Contract owner" en eSignatures: quien creó el mandato lo administra y recibe sus avisos (debe ser usuario de
      // la cuenta; si no lo es, send() lo envía sin dueño).
      'assigned_user_email' => self::text($post['correo_funcionario'] ?? ''),
      'placeholder_fields' => $fields,
    ];
  }

  /**
   * Valores de los {{…}} de la plantilla, como firma_valores.
   * @param array<string,mixed> $post
   * @param array<string,string>|null $credit
   * @return array<string,string>
   */
  public static function values(array $post, ?array $credit): array
  {
    $v = static fn(string $key): string => self::text($post[$key] ?? '');
    $type = $v('tipo_contrato');
    $count = max(1, min(6, (int) $v('cantidad_propietarios')));
    $post['parqueadero'] = $post['parqueaderos'] ?? '';
    $post['inmueble-pagina'] = $post['id_inmueble'] ?? '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v('fecha_inmueble'), $m) === 1) {
      $post['fecha_inmueble'] = $m[3] . '/' . $m[2] . '/' . $m[1];
    }
    if (self::text($post['precio_canon_total'] ?? '') === '') {
      $post['precio_canon_total'] = (string) round(MandateCalculator::money($v('precio_canon')) + MandateCalculator::money($v('precio_administracion')));
    }
    if ($credit !== null) {
      $keys = $type === 'Seguros mundial' ? ['prima_mes_seguros_mundial', 'total_prima_seguros_mundial'] : ['prima_mes_garantia_servicios', 'prima_anual_garantia_servicios'];
      $post[$keys[0]] = number_format(round((float) $credit['valor_mes']), 0, ',', '.');
      $post[$keys[1]] = number_format(round((float) $credit['valor']), 0, ',', '.');
    }
    $values = ['fecha' => self::longDate(time())];
    foreach (array_merge(self::COMMON_FIELDS, self::TYPE_FIELDS[$type] ?? []) as $field) {
      $text = self::text($post[$field] ?? '');
      if (in_array($field, self::MONEY, true)) {
        $text = self::money($text);
      } elseif (in_array($field, self::PERCENT, true)) {
        $text = MandateDocument::percent($text);
      }
      $values[$field] = $text;
    }
    foreach (self::ONLY_IF as $question => $fields) {
      if ($v($question) !== 'No') {
        continue;
      }
      foreach ($fields as $field) {
        if (isset($values[$field])) {
          $values[$field] = self::NOT_APPLICABLE;
        }
      }
    }
    foreach ($values as $field => $text) {
      if ($text === '') {
        $values[$field] = self::NOT_APPLICABLE;
      }
    }
    foreach (self::OWNER_FIELDS as $field) {
      $before = false;
      for ($i = 1; $i <= 6; $i++) {
        $text = $i <= $count ? self::text($post[$field . '_' . $i] ?? '') : '';
        if ($field === 'documento') {
          $text = MandateDocument::document((string) ($post['tipo_documento_' . $i] ?? ''), $text);
        } elseif ($field === 'porcentaje') {
          $text = MandateDocument::percent($text);
        }
        $values[$field . '_' . $i] = $text !== '' && $before ? ', ' . $text : $text;
        $before = $before || $text !== '';
      }
    }
    for ($i = 1; $i <= 6; $i++) {
      foreach (['ano_inicial', 'ano_final', 'ipc'] as $field) {
        $values[$field . '_' . $i] = $v($field . '_' . $i);
      }
    }
    for ($i = 1; $i <= 4; $i++) {
      $values['mes_inicial_' . $i] = $v('mes_inicial_' . $i);
      $values['mes_final_' . $i] = $v('mes_final_' . $i);
      $values['valor_' . $i] = self::money((string) ($post['valor_' . $i] ?? ''));
    }
    return $values;
  }

  /** {{tabla_propietarios}}: una tabla por propietario, como firma_tabla_propietarios. @param array<string,mixed> $post */
  public static function ownersTable(array $post): string
  {
    $rows = [
      'Calidad de propietario' => 'calidad', 'Tipo de persona' => 'tipo_persona', 'Nombre / Empresa' => 'nombre', 'Documento de identificación' => 'documento',
      'Lugar de expedición' => 'expedicion', 'Representante legal' => 'nombre_juridico', 'Documento del representante legal' => 'documento_juridico',
      'Dirección de correspondencia' => 'direccion', 'Correo electrónico' => 'correo', 'Celular' => 'celular', 'En caso de fallecimiento se designa a' => 'fallecimiento',
      '% de participación en el contrato' => 'porcentaje', 'Tipo de giro' => 'tipo_giro', 'A nombre de quien sale el giro' => 'nombre_giro', 'Titular de la cuenta' => 'titular',
      'No. de identificación del titular' => 'documento_cuenta', 'Tipo de cuenta' => 'tipo_cuenta', 'Número de cuenta' => 'numero_cuenta', 'Banco' => 'banco',
      'Correo para recibir facturas' => 'correo_cuenta',
    ];
    $cell = static fn($text): string => str_replace('|', '/', self::text($text));
    $md = [];
    $count = max(1, min(6, (int) ($post['cantidad_propietarios'] ?? 1)));
    for ($i = 1; $i <= $count; $i++) {
      $md[] = '**PROPIETARIO ' . $i . ': ' . $cell($post['nombre_' . $i] ?? '') . '**';
      $md[] = '';
      $md[] = '| Dato | Valor |';
      $md[] = '| --- | --- |';
      foreach ($rows as $title => $field) {
        $value = $cell($post[$field . '_' . $i] ?? '');
        if ($value === '' && in_array($field, ['nombre_juridico', 'documento_juridico'], true)) {
          continue;
        }
        if ($field === 'documento') {
          $value = $cell(MandateDocument::document((string) ($post['tipo_documento_' . $i] ?? ''), $value));
        } elseif ($field === 'porcentaje') {
          $value = MandateDocument::percent($value);
        }
        $md[] = '| **' . $title . '** | ' . ($value === '' ? self::NOT_APPLICABLE : $value) . ' |';
      }
      $md[] = '';
    }
    return implode("\n", $md);
  }

  /** {{tabla_acuerdos}}: la tabla del tipo elegido, solo con las filas llenas. @param array<string,mixed> $post */
  public static function agreementsTable(array $post): string
  {
    $v = static fn(string $key): string => str_replace('|', '/', self::text($post[$key] ?? ''));
    $type = $v('acuerdo');
    if ($type === 'Anual') {
      $head = '| Año inicial | Año final | IPC |';
      $row = static fn(int $i): array => [$v('ano_inicial_' . $i), $v('ano_final_' . $i), $v('ipc_' . $i)];
    } elseif ($type === 'Mensual') {
      $head = '| Mes inicial | Mes final | Valor del canon |';
      $row = static fn(int $i): array => [$v('mes_inicial_' . $i), $v('mes_final_' . $i), self::money((string) ($post['valor_' . $i] ?? ''))];
    } else {
      return self::NOT_APPLICABLE;
    }
    $md = [$head, '| --- | --- | --- |'];
    for ($i = 1; $i <= 6; $i++) {
      $cells = $row($i);
      if (implode('', $cells) === '') {
        continue;
      }
      $md[] = '| ' . implode(' | ', array_map(static fn(string $c): string => $c === '' ? self::NOT_APPLICABLE : $c, $cells)) . ' |';
    }
    return count($md) > 2 ? implode("\n", $md) : self::NOT_APPLICABLE;
  }

  /**
   * Firmantes en orden: propietarios (1), quien elabora con firma automática (2), representante legal (3).
   * @param array<string,mixed> $post
   * @return list<array<string,mixed>>
   */
  public static function signers(array $post): array
  {
    $v = static fn(string $key): string => self::text($post[$key] ?? '');
    $signers = [];
    $count = max(1, min(6, (int) $v('cantidad_propietarios')));
    for ($i = 1; $i <= $count; $i++) {
      $name = $v('nombre_' . $i);
      if ($name === '') {
        continue;
      }
      $email = $v('correo_' . $i);
      $mobile = self::mobile($v('indicativo_' . $i), $v('celular_' . $i));
      if ($email === '' && $mobile === '') {
        throw new \InvalidArgumentException('El propietario #' . $i . ' (' . $name . ') necesita correo o celular para poder firmar.');
      }
      $document = MandateDocument::document($v('tipo_documento_' . $i), $v('documento_' . $i));
      $signers[] = array_filter([
        'name' => $name . ($document !== '' ? ' - ' . $document : ''), 'email' => $email, 'mobile' => $mobile,
        'company_name' => mb_strtoupper($v('calidad_' . $i) ?: self::ROLE_OWNER, 'UTF-8'), 'signing_order' => 1, 'auto_sign' => 'no', 'redirect_url' => self::REDIRECT_URL,
      ], static fn($value): bool => $value !== '');
    }
    if ($signers === []) {
      throw new \InvalidArgumentException('Falta al menos un propietario con nombre para enviar a firma.');
    }
    if ($v('nombre_funcionario') === '' || $v('correo_funcionario') === '') {
      throw new \InvalidArgumentException('Falta el nombre o el correo del funcionario que creó el mandato (revisa su ficha en Funcionarios).');
    }
    $signers[] = array_filter(['name' => $v('nombre_funcionario'), 'email' => $v('correo_funcionario'), 'mobile' => self::mobile('57', $v('telefono_funcionario')), 'company_name' => self::ROLE_EMPLOYEE, 'signing_order' => 2, 'auto_sign' => 'yes'], static fn($value): bool => $value !== '');
    $legal = self::LEGAL_REPRESENTATIVE;
    $signers[] = ['name' => $legal['nombre'], 'email' => $legal['email'], 'mobile' => $legal['mobile'], 'company_name' => $legal['rol'], 'signing_order' => 3, 'auto_sign' => 'no'];
    return $signers;
  }

  /**
   * Modifica el contrato ya enviado si usa la misma plantilla y la misma cantidad de firmantes, como
   * firma_modificar_existente. @param array<string,mixed> $contract @param array<string,mixed> $body
   */
  private function modify(string $id, array $contract, array $body): bool
  {
    if (!str_contains((string) ($contract['metadata'] ?? ''), 'plantilla:' . $body['template_id'])) {
      return false;
    }
    $current = array_values($contract['signers'] ?? []);
    $next = $body['signers'];
    $n = count($next);
    if (count($current) !== $n || $n < 3) {
      return false;
    }
    foreach ($next as $k => $signer) {
      if (isset($current[$k]['signing_order']) && (int) $current[$k]['signing_order'] !== (int) $signer['signing_order']) {
        return false;
      }
    }
    $path = 'contracts/' . rawurlencode($id);
    ($this->http)('POST', $path . '/placeholder_fields', ['placeholder_fields' => $body['placeholder_fields']]);
    // Los campos de texto siguen vivos en el contrato ({{precio_canon}}) y se actualizan con lo anterior, pero las tablas
    // (tabla_propietarios, tabla_acuerdos) quedaron escritas en el texto al crearlo: se reemplazan ahí mismo. Si no se
    // puede, la excepción hace que send() cree un contrato nuevo y anule este.
    $this->refreshTables($id, $body);
    for ($k = 0; $k < $n; $k++) {
      $changed = false;
      foreach (['name', 'email', 'mobile', 'company_name'] as $field) {
        $changed = $changed || trim((string) ($current[$k][$field] ?? '')) !== trim((string) ($next[$k][$field] ?? ''));
      }
      if (!$changed) {
        continue;
      }
      $signerId = rawurlencode((string) ($current[$k]['id'] ?? ''));
      ($this->http)('POST', $path . '/signers/' . $signerId, array_intersect_key($next[$k], array_flip(['name', 'email', 'mobile', 'company_name', 'redirect_url'])));
      if ((int) $next[$k]['signing_order'] === 1 && self::contact($current[$k]) !== self::contact($next[$k])) {
        ($this->http)('POST', $path . '/signers/' . $signerId . '/send_contract', null);
      }
    }
    return true;
  }

  /**
   * Reemplaza en el texto del contrato enviado las tablas que cambiaron y comprueba que quedaron.
   * @param array<string,mixed> $body Cuerpo de POST /contracts (contract()).
   */
  private function refreshTables(string $id, array $body): void
  {
    $tables = [];
    foreach ($body['placeholder_fields'] as $field) {
      if (isset($field['replace_with_markdown'])) {
        $tables[(string) $field['placeholder_key']] = (string) $field['replace_with_markdown'];
      }
    }
    if ($tables === []) {
      return;
    }
    $template = (string) (($this->http)('GET', 'templates/' . rawurlencode((string) $body['template_id']) . '/content', null)['data']['markdown'] ?? '');
    $path = 'contracts/' . rawurlencode($id) . '/content';
    $content = (string) (($this->http)('GET', $path, null)['data']['markdown'] ?? '');
    $edits = [];
    foreach ($tables as $key => $markdown) {
      $edit = self::tableEdit($template, $content, $key, $markdown);
      if ($edit === null) {
        throw new \RuntimeException('No se encontró {{' . $key . '}} en el texto del contrato ' . $id . '.');
      }
      if ($edit !== []) {
        $edits[] = $edit;
      }
    }
    if ($edits === []) {
      return;
    }
    $after = (string) (($this->http)('POST', $path, ['dry_run' => 'no', 'edits' => $edits])['data']['markdown'] ?? '');
    if ($after === '') {
      $after = (string) (($this->http)('GET', $path, null)['data']['markdown'] ?? '');
    }
    $lines = array_map('trim', self::split($after));
    foreach ($tables as $key => $markdown) {
      foreach (self::lines($markdown) as $line) {
        if (!in_array($line, $lines, true)) {
          throw new \RuntimeException('El contrato ' . $id . ' no quedó con la tabla {{' . $key . '}} nueva.');
        }
      }
    }
  }

  /**
   * Cambio del texto del contrato para una tabla: lo que hay entre la línea anterior y la siguiente a {{key}} en la
   * plantilla (esas líneas no cambian: sus campos de texto siguen como {{…}}). [] si ya está igual; null si no se ubica.
   * @return array{find_markdown:string,replace_with_markdown:string}|array{}|null
   */
  public static function tableEdit(string $template, string $content, string $key, string $markdown): ?array
  {
    $template = self::split($template);
    $content = self::split($content);
    $at = array_search('{{' . $key . '}}', array_map('trim', $template), true);
    if ($at === false) {
      return null;
    }
    $before = '';
    for ($i = $at - 1; $i >= 0 && $before === ''; $i--) {
      $before = trim($template[$i]);
    }
    $next = '';
    for ($i = $at + 1; $i < count($template) && $next === ''; $i++) {
      $next = trim($template[$i]);
    }
    $trimmed = array_map('trim', $content);
    $starts = array_keys($trimmed, $before, true);
    if ($before === '' || $next === '' || count($starts) !== 1) {
      return null;
    }
    $end = array_search($next, array_slice($trimmed, $starts[0] + 1), true);
    if ($end === false) {
      return null;
    }
    $region = array_slice($content, $starts[0] + 1, (int) $end);
    while ($region !== [] && trim($region[0]) === '') {
      array_shift($region);
    }
    while ($region !== [] && trim($region[count($region) - 1]) === '') {
      array_pop($region);
    }
    if (self::lines(implode("\n", $region)) === self::lines($markdown)) {
      return [];
    }
    // Sin tabla escrita (quedó vacía): se agrega después de la línea anterior.
    return $region === []
      ? ['find_markdown' => $content[$starts[0]], 'replace_with_markdown' => $content[$starts[0]] . "\n" . $markdown]
      : ['find_markdown' => implode("\n", $region), 'replace_with_markdown' => $markdown];
  }

  /** @return list<string> */
  private static function split(string $markdown): array
  {
    return preg_split('/\r\n|\r|\n/', $markdown) ?: [];
  }

  /** Líneas con contenido, sin espacios a los lados (para comparar tablas sin importar las líneas en blanco). @return list<string> */
  private static function lines(string $markdown): array
  {
    return array_values(array_filter(array_map('trim', self::split($markdown)), static fn(string $line): bool => $line !== ''));
  }

  /** @param array<string,mixed> $signer */
  private static function contact(array $signer): string
  {
    return mb_strtolower(trim((string) ($signer['email'] ?? '')), 'UTF-8') . '|' . preg_replace('/\D/', '', (string) ($signer['mobile'] ?? ''));
  }

  public static function mobile(string $country, string $number): string
  {
    $digits = (string) preg_replace('/\D/', '', $number);
    if ($digits === '') {
      return '';
    }
    if (str_starts_with(trim($number), '+')) {
      return '+' . $digits;
    }
    return '+' . ((string) preg_replace('/\D/', '', $country) ?: '57') . $digits;
  }

  private static function text($value): string
  {
    return trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));
  }

  private static function money(string $value): string
  {
    return self::text($value) === '' ? '' : '$' . number_format(round(MandateCalculator::money($value)), 0, ',', '.');
  }

  private static function longDate(int $time): string
  {
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return date('j', $time) . ' de ' . $months[(int) date('n', $time) - 1] . ' de ' . date('Y', $time);
  }

  /**
   * Llamada a la API: token como usuario de Basic Auth (contraseña vacía).
   * @param array<string,mixed>|null $body
   * @return array<string,mixed>
   */
  private static function curl(string $token, string $method, string $path, ?array $body): array
  {
    $curl = curl_init(self::API . '/' . ltrim($path, '/'));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $token . ':', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40]);
    if ($body !== null) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) {
      throw new \RuntimeException('eSignatures no respondió: ' . $error);
    }
    $json = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
      throw new \RuntimeException('eSignatures respondió con error ' . $status . ': ' . mb_substr(trim((string) $response), 0, 400));
    }
    return $json;
  }
}
