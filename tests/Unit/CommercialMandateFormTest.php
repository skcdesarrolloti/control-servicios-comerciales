<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\CommercialMandateFormService;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\CommercialMandatesRepository;
use SCM\Commercial\Mandates\MandateDocument;
use SCM\Commercial\Mandates\MandateCalculator;
use SCM\Commercial\Mandates\MandateFields;
use SCM\Commercial\Mandates\MandateSignature;
use SCM\Commercial\Mandates\MandateSignatureWebhook;
use SCM\Core\Database;
use SCM\Views\CommercialMandateFormView;
use SCM\Views\CommercialMandatesView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Crear, editar y enviar a firma el contrato de mandato desde el panel: cálculo, guardado, firma, aviso y vistas. */
final class CommercialMandateFormTest extends TestCase
{
  private const CONFIG = [
    'iva' => '19', 'tasa_administracion_arriendo' => '10', 'tasa_libertador_seguro' => '2.45', 'tasa_libertador_servicios' => '14.65',
    'tasa_zurich_seguro' => '2', 'tasa_clausula_penal' => '15', 'tasa_berkley' => '3', 'gasto_expedicion_berkley' => '50.000',
    'tasa_seguros_mundial' => '2.5', 'gasto_expedicion_seguros_mundial' => '30000', 'tasa_prestamo_garantias_servicios' => '2',
  ];

  private Database $db;
  private CommercialAccessPolicy $policy;
  private array $previousSession;
  /** @var list<array{0:string,1:string,2:?array<string,mixed>}> */
  private array $calls = [];
  /** @var array<string,array<string,mixed>> */
  private array $contracts = [];

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
    if (!defined('SCM_APP_SECRET')) define('SCM_APP_SECRET', 'secreto-de-prueba-de-al-menos-32-caracteres');
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://panel.test/public');
    [$this->db, $this->policy] = CommercialNotificationsFixture::make(true);
    $mandate = ['estado', 'cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'fecha', 'sucursal', 'propietarios', 'id_inmueble', 'id_propietario', 'id_empleado', 'creador', 'email_creador', 'celular_creador', 'id_estudio_aseguradora', 'numero_solicitud', 'vigilancia', 'servicios_publicos', 'id_esignatures', 'pdf_firmado', 'fecha_firma', 'iva_precio', 'estado_hoja_cierre', 'id_hoja_cierre'];
    for ($i = 1; $i <= 6; $i++) {
      foreach (array_merge(array_values(MandateFields::OWNER), ['porcentaje']) as $column) {
        $mandate[] = $column . '_' . $i;
      }
    }
    $mandate = array_values(array_unique(array_merge($mandate, array_values(MandateFields::FIELDS), MandateFields::agreementFields())));
    $this->table('wp_jet_cct_contrato_mandato', $mandate);
    $this->table('wp_jet_cct_creditos', ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'fecha', 'solicitante', 'id_item_financiado', 'id_usuario', 'cuotas', 'valor', 'valor_mes', 'tasa', 'factor_tasa', 'intereses_generados', 'motivo']);
    $this->table('wp_jet_cct_copropiedades_x', ['x']);
    $this->table('wp_jet_cct_historial_del_inmueble', ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'id_inmueble', 'id_empleado', 'funcionario', 'fecha', 'tipo_reporte', 'observacion', 'id_ticket']);
    $this->table('wp_jet_cct_estudios_aseguradoras', ['id_ticket', 'estado', 'aseguradora', 'num_solicitud', 'id_inmueble', 'id_contrato_mandato']);
    $this->table('wp_jet_cct_inmuebles_m', ['x']);
    foreach (['codigo', 'barrio', 'direccion', 'direccion_fisica', 'area_construida', 'area_privada', 'precio_arriendo', 'precio_admin', 'referencia_catastral', 'propietario', 'pertenece_copropiedad', 'copropiedad', 'parqueadero', 'parqueaderos', 'tipo_parqueadero', 'numero_parqueadero', 'deposito', 'depositos', 'vigilancia', 'servicios_publicos', 'luz', 'agua', 'gas', 'matricula_inmobiliaria', 'estrato', 'id_contrato_mandato'] as $column) {
      $this->column('wp_jet_cct_inmuebles', $column);
    }
    foreach (['tipo_persona', 'tipo_documento', 'documento', 'expedicion', 'nombre_juridico', 'documento_juridico', 'direccion', 'pais', 'tipo_giro', 'nombre_giro', 'titular', 'documento_cuenta', 'tipo_cuenta', 'numero_cuenta', 'banco', 'correo_cuenta', 'cct_status', 'cct_created', 'cct_modified'] as $column) {
      $this->column('wp_jet_cct_propietarios', $column);
    }
    foreach (['nit', 'administrador', 'direccion', 'barrio', 'tipo_cuenta', 'numero_cuenta', 'banco', 'id_inmueble', 'cct_status', 'cct_created', 'cct_modified'] as $column) {
      $this->column('wp_jet_cct_copropiedades', $column);
    }
    $this->db->update('wp_jet_cct_propietarios', ['documento' => '21.235.713', 'tipo_documento' => 'Cédula de Ciudadania'], ['_ID' => 10]);
    $this->db->update('wp_jet_cct_inmuebles', ['codigo' => '28355', 'barrio' => 'Manga', 'direccion' => 'Cra 20', 'precio_arriendo' => '1.650.000', 'id_propietario' => '10', 'luz' => '7682378'], ['_ID' => 1]);
    $this->db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 621, 'id_ticket' => '700', 'estado' => 'Aprobado', 'aseguradora' => 'El libertador', 'num_solicitud' => '12462979', 'id_inmueble' => '28355']);
    foreach (self::CONFIG as $key => $value) {
      $this->db->insert('wp_jet_cct_confi_sistema', ['funcion' => $key, 'valor' => $value]);
    }
    $this->calls = [];
    $this->contracts = [];
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** Crea (o completa) una tabla y la registra en information_schema, como CommercialNotificationsFixture. @param list<string> $columns */
  private function table(string $table, array $columns): void
  {
    $exists = (bool) $this->db->getVar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME = ?', [$table]);
    if (!$exists) {
      $this->db->pdo()->exec("CREATE TABLE `{$table}` (_ID INTEGER PRIMARY KEY AUTOINCREMENT)");
      $this->db->pdo()->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
      $this->db->pdo()->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, '_ID']);
    }
    foreach ($columns as $column) {
      $this->column($table, $column);
    }
  }

  private function column(string $table, string $column): void
  {
    $existing = array_column($this->db->getResults("PRAGMA table_info(`{$table}`)"), 'name');
    if (!in_array($column, $existing, true)) {
      $this->db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` TEXT");
      $this->db->pdo()->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column]);
    }
  }

  private function signature(): MandateSignature
  {
    return new MandateSignature('token', true, function (string $method, string $path, ?array $body): array {
      $this->calls[] = [$method, $path, $body];
      if ($method === 'POST' && $path === 'contracts') {
        $id = 'c' . (count($this->contracts) + 1);
        $this->contracts[$id] = ['id' => $id, 'status' => 'sent', 'metadata' => $body['metadata'], 'signers' => array_map(static fn(array $s, int $k): array => $s + ['id' => 's' . $k], $body['signers'], array_keys($body['signers'])), 'contract_pdf_url' => 'https://esig.test/' . $id . '.pdf'];
        return ['data' => ['contract' => ['id' => $id]]];
      }
      if ($method === 'GET' && preg_match('#^contracts/(c\d+)$#', $path, $m) === 1) {
        return ['data' => ['contract' => $this->contracts[$m[1]]]];
      }
      return ['data' => []];
    });
  }

  /** Deja el mandato listo para la firma: el propietario aprueba la versión actual y la hoja de cierre está aprobada. */
  private function ready(int $id, string $closing = 'Aprobada'): void
  {
    $repository = new CommercialMandatesRepository($this->db);
    $repository->approve($id, 'Lilia Granados', MandateDocument::fingerprint($repository->find($id) ?? []));
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado_hoja_cierre' => $closing, 'id_hoja_cierre' => '440'], ['_ID' => $id]);
  }

  private function service(): CommercialMandateFormService
  {
    return new CommercialMandateFormService($this->db, $this->policy, new MandateCalculator($this->db), $this->signature());
  }

  /** @return array<string,mixed> */
  private function post(array $changes = []): array
  {
    return $changes + [
      'tipo_contrato' => 'Por defecto', 'clasificacion' => '5- Entrega inmediata', 'cantidad_propietarios' => '2', 'id_inmueble' => '28355',
      'calidad_1' => 'Propietario', 'tipo_persona_1' => 'Natural', 'nombre_1' => 'Lilia Granados', 'tipo_documento_1' => 'Cédula de Ciudadania', 'documento_1' => '21235713',
      'correo_1' => 'lilia@example.test', 'celular_1' => '3006681522', 'indicativo_1' => '+57', 'pais_1' => 'Colombia', 'porcentaje_1' => '60', 'banco_1' => 'Davivienda',
      'calidad_2' => 'Propietario', 'tipo_persona_2' => 'Natural', 'nombre_2' => 'Pedro Nuevo', 'tipo_documento_2' => 'Cédula de Ciudadania', 'documento_2' => '998877',
      'correo_2' => 'pedro@example.test', 'pais_2' => 'Colombia', 'porcentaje_2' => '40',
      'existe_simi' => 'No', 'mismo_propietario' => 'Si', 'codigo_inmueble' => '28355', 'escritura' => '891', 'fecha_inmueble' => '1998-03-20', 'circulo_notarial' => '2A',
      'matricula_inmobiliaria' => '060-1', 'referencia_catastral' => '0102', 'barrio' => 'Manga', 'direccion' => 'Cra 20 # 25-40', 'direccion_fisica' => 'Cra 20', 'area_construida' => '120',
      'estrato' => '4', 'propiedad_horizontal' => 'No', 'tiene_parqueadero' => 'No', 'tiene_deposito' => 'No', 'tiene_vigilancia' => 'Si', 'servicios_publicos' => ['Energia', 'Agua'],
      'energia' => '7682378', 'precio_canon' => '1.650.000', 'precio_administracion' => '250.000', 'tiene_iva_canon' => 'No', 'tasa_comision' => '10',
      'comision_sin_iva' => '190.000', 'iva_comision' => '36.100', 'total_comision' => '226.100',
      'incluye_garantia' => 'Si', 'aseguradora' => 'El libertador', 'tasa_garantia' => '2.45', 'valor_garantia' => '46.550',
      'incluye_garantia_servicios' => 'Si', 'valor_asegurado_garantia_servicios' => '2.000.000', 'forma_pago_garantia_servicios' => '6', 'incluye_clausula_penal' => 'No',
      'acuerdo' => 'Anual', 'anos' => '1', 'ano_inicial_1' => '2026', 'ano_final_1' => '2027', 'duracion' => '12 Meses', 'incremento_pactado' => 'IPC',
    ];
  }

  public function testFieldsGoAndComeBackFromTheMandateRow(): void
  {
    $row = MandateFields::toRow($this->post());
    self::assertSame(['Lilia Granados', 'Davivienda', '1.650.000', '250.000', 'Si', 'Si'], [$row['nombre_1'], $row['banco_cuenta_1'], $row['precio'], $row['administracion'], $row['tiene_vigilancia'], $row['vigilancia']]);
    self::assertSame(['Energia', 'Agua'], unserialize($row['servicios_publicos']));
    $form = MandateFields::toForm($row + ['_ID' => 5, 'id_inmueble' => '28355']);
    self::assertSame(['2', 'Lilia Granados', 'Davivienda', '1.650.000', '6', ['Energia', 'Agua']], [$form['cantidad_propietarios'], $form['nombre_1'], $form['banco_1'], $form['precio_canon'], $form['forma_pago_garantia_servicios'], $form['servicios_publicos']]);
  }

  public function testTheServerRecalculatesTheGuaranteeCredit(): void
  {
    $calculator = new MandateCalculator(null, self::CONFIG);
    self::assertNull($calculator->credit($this->post(['forma_pago_garantia_servicios' => '4'])), 'Hasta 4 cuotas no se financia.');
    $credit = $calculator->credit($this->post());
    $loan = MandateCalculator::fixedInstallment(293000.0, 6, 2.0);
    self::assertSame(['6', number_format($loan['total'], 2, '.', ''), 'Garantía servicios públicos (El libertador)'], [$credit['cuotas'], $credit['valor'], $credit['motivo']]);
    $world = $calculator->credit($this->post(['tipo_contrato' => 'Seguros mundial', 'forma_pago_seguros_mundial' => '3']));
    self::assertSame('Garantía servicios públicos (seguros mundial)', $world['motivo']);
    $premium = (1900000 * 12 * 0.025 + 30000);
    $expected = MandateCalculator::fixedInstallment(round($premium + round($premium * 0.19, 2), 2), 3, 2.0);
    self::assertSame(number_format($expected['cuota'], 2, '.', ''), $world['valor_mes']);
    $this->expectException(\InvalidArgumentException::class);
    $calculator->credit($this->post(['aseguradora' => 'Zurich']));
  }

  public function testParametersCarryTheInsurersRates(): void
  {
    $params = (new MandateCalculator(null, self::CONFIG))->parameters();
    self::assertSame(['El libertador', 'Zurich'], $params['aseguradoras']);
    self::assertSame([19.0, 10.0, 2.45, 14.65, 50000.0], [$params['iva'], $params['tasa_comision'], $params['tasas_seguro']['El libertador'], $params['tasas_servicios']['El libertador'], $params['expedicion_berkley']]);
  }

  public function testPercentagesMustAddUpTo100(): void
  {
    self::assertSame('60', CommercialMandateFormService::percentages($this->post())['porcentaje_1']);
    self::assertSame('100', CommercialMandateFormService::percentages(['cantidad_propietarios' => '1'])['porcentaje_1']);
    $this->expectException(\InvalidArgumentException::class);
    CommercialMandateFormService::percentages($this->post(['porcentaje_2' => '30']));
  }

  public function testCreatingSavesOwnersMandateCreditPropertyHistoryAndStudy(): void
  {
    $result = $this->service()->save($this->post(), null, ['ticket' => 700, 'estudio' => 621]);
    $mandate = $this->db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = ?', [$result['id']]);
    self::assertSame(['No enviado', '28355', '999', 'Administrador', 'admin@example.test', '621', '12462979', '60', '40', '2'], [$mandate['estado'], $mandate['id_inmueble'], $mandate['id_empleado'], $mandate['creador'], $mandate['email_creador'], $mandate['id_estudio_aseguradora'], $mandate['numero_solicitud'], $mandate['porcentaje_1'], $mandate['porcentaje_2'], $mandate['propietarios']]);
    self::assertSame('10', $mandate['id_propietario_nuevo_1'], 'El propietario #1 se enlaza por su documento (sin puntos), no se duplica.');
    $new = (int) $mandate['id_propietario_nuevo_2'];
    self::assertSame('Pedro Nuevo', $this->db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = ?', [$new]), 'El propietario nuevo se crea.');
    self::assertSame('Davivienda', $this->db->getVar('SELECT banco FROM wp_jet_cct_propietarios WHERE _ID = 10'), 'Se completa lo que la ficha tiene vacío.');
    self::assertSame('Propietario 10', $this->db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = 10'), 'Encontrado por documento: no se pisa lo que ya tiene.');
    self::assertSame(['6', 'Garantía servicios públicos (El libertador)', '10'], array_values($this->db->getRow('SELECT cuotas, motivo, id_usuario FROM wp_jet_cct_creditos WHERE id_item_financiado = ?', [(string) $result['id']])));
    self::assertSame([(string) $result['id'], 'Cra 20 # 25-40', '1.650.000'], array_values($this->db->getRow("SELECT id_contrato_mandato, direccion, precio_arriendo FROM wp_jet_cct_inmuebles WHERE codigo = '28355'")));
    self::assertSame(['Contractual', '700'], array_values($this->db->getRow('SELECT tipo_reporte, id_ticket FROM wp_jet_cct_historial_del_inmueble')));
    self::assertSame((string) $result['id'], $this->db->getVar('SELECT id_contrato_mandato FROM wp_jet_cct_estudios_aseguradoras WHERE _ID = 621'));
  }

  public function testEditingUpdatesTheMandateAndDropsTheCreditWhenItNoLongerApplies(): void
  {
    $service = $this->service();
    $id = $service->save($this->post())['id'];
    $service->save($this->post(['forma_pago_garantia_servicios' => '2', 'duracion' => '24 Meses']), $id);
    self::assertSame('24 Meses', $this->db->getVar('SELECT duracion FROM wp_jet_cct_contrato_mandato WHERE _ID = ?', [$id]));
    self::assertSame('0', (string) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_creditos'), 'En 2 cuotas ya no hay crédito.');
    self::assertSame('1', (string) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble'), 'Editar no vuelve a escribir el historial.');
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado' => 'Firmado'], ['_ID' => $id]);
    $this->expectException(\InvalidArgumentException::class);
    $service->save($this->post(), $id);
  }

  public function testInvalidFormsAreRejectedWithoutSavingAnything(): void
  {
    foreach ([['tipo_contrato' => ''], ['nombre_2' => ''], ['precio_canon' => ''], ['porcentaje_1' => '50'], ['id_inmueble' => ''], ['pais_2' => '']] as $change) {
      try {
        $this->service()->save($this->post($change));
        self::fail('Debía rechazar ' . json_encode($change));
      } catch (\InvalidArgumentException) {
      }
    }
    self::assertSame('0', (string) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_contrato_mandato'));
    self::assertSame('0', (string) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_propietarios WHERE nombre = 'Pedro Nuevo'"), 'Todo o nada: el propietario nuevo tampoco queda.');
  }

  public function testSendingToSignBuildsTheContractLikeTheWordPressForm(): void
  {
    $service = $this->service();
    $id = $service->save($this->post())['id'];
    $this->ready($id);
    $result = $service->sendToSign($id);
    self::assertSame(['Enviado', 'c1'], array_values($this->db->getRow('SELECT estado, id_esignatures FROM wp_jet_cct_contrato_mandato WHERE _ID = ?', [$id])));
    $body = $this->calls[0][2];
    self::assertSame([MandateSignature::TEMPLATES['Por defecto'], 'CONTRATO DE MANDATO #' . $id, 'yes', 'mandato:' . $id . ';plantilla:' . MandateSignature::TEMPLATES['Por defecto']], [$body['template_id'], $body['title'], $body['test'], $body['metadata']]);
    self::assertSame(['Lilia Granados - C.C. 21235713', '+573006681522', 1], [$body['signers'][0]['name'], $body['signers'][0]['mobile'], $body['signers'][0]['signing_order']]);
    self::assertSame(['Administrador', 'yes', 2], [$body['signers'][2]['name'], $body['signers'][2]['auto_sign'], $body['signers'][2]['signing_order']], 'Quien creó el mandato firma solo, de segundo.');
    self::assertSame(MandateSignature::LEGAL_REPRESENTATIVE['email'], $body['signers'][3]['email']);
    $fields = array_column($body['placeholder_fields'], 'replace_with_text', 'placeholder_key');
    self::assertSame(['$1.650.000', '10 %', '$1.900.000', 'No aplica', 'Manga'], [$fields['precio_canon'], $fields['tasa_comision'], $fields['precio_canon_total'], $fields['tasa_clausula_penal'], $fields['barrio']]);
    self::assertSame('Lilia Granados', $fields['nombre_1']);
    self::assertSame(', Pedro Nuevo', $fields['nombre_2'], 'Desde el segundo propietario van separados por comas.');
    self::assertStringContainsString('**PROPIETARIO 2: Pedro Nuevo**', array_column($body['placeholder_fields'], 'replace_with_markdown', 'placeholder_key')['tabla_propietarios']);
    self::assertSame('c1', $result['contrato']);

    // Reenviar con la misma plantilla y propietarios: se modifica el mismo contrato.
    $this->calls = [];
    $this->db->update('wp_jet_cct_contrato_mandato', ['correo_1' => 'otro@example.test'], ['_ID' => $id]);
    $this->ready($id);
    self::assertSame('c1', $service->sendToSign($id)['contrato']);
    $paths = array_map(static fn(array $call): string => $call[0] . ' ' . $call[1], $this->calls);
    self::assertContains('POST contracts/c1/placeholder_fields', $paths);
    self::assertContains('POST contracts/c1/signers/s0/send_contract', $paths, 'Al propietario con otro correo se le reenvía.');
    self::assertNotContains('POST contracts', $paths);

    $this->contracts['c1']['status'] = 'signed';
    $this->expectException(\InvalidArgumentException::class);
    $service->sendToSign($id);
  }

  public function testAnOwnerWithoutContactCannotBeSentToSign(): void
  {
    $id = $this->service()->save($this->post(['correo_2' => '', 'celular_2' => '']))['id'];
    $this->ready($id);
    $this->expectExceptionMessage('necesita correo o celular');
    $this->service()->sendToSign($id);
  }

  public function testTheWebhookMarksTheMandateSignedOrBackToNotSent(): void
  {
    $service = $this->service();
    $id = $service->save($this->post())['id'];
    $this->ready($id);
    $service->sendToSign($id);
    $stored = [];
    $webhook = new MandateSignatureWebhook($this->db, $this->signature(), static function (string $url) use (&$stored): string {
      $stored[] = $url;
      return 'https://panel.test/public/file.php?n=x.pdf';
    });
    self::assertStringContainsString('aún no está firmado', $webhook->process(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'c1']]]));
    $this->contracts['c1']['status'] = 'signed';
    $webhook->process(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'c1']]]);
    $row = $this->db->getRow('SELECT estado, pdf_firmado FROM wp_jet_cct_contrato_mandato WHERE _ID = ?', [$id]);
    self::assertSame(['Firmado', 'https://panel.test/public/file.php?n=x.pdf'], [$row['estado'], $row['pdf_firmado']]);
    self::assertSame(['https://esig.test/c1.pdf'], $stored);
    self::assertStringContainsString('ya estaba firmado', $webhook->process(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'c1']]]));
    self::assertStringContainsString('no es el vigente', $webhook->process(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'zz']]]));
    self::assertTrue(MandateSignatureWebhook::validSignature('{"a":1}', hash_hmac('sha256', '{"a":1}', 'token'), 'token'));
    self::assertFalse(MandateSignatureWebhook::validSignature('{"a":1}', 'otra', 'token'));
  }

  public function testOnlyWhoHasThePermissionCreatesOrSigns(): void
  {
    [$db, $consultant] = CommercialNotificationsFixture::make(false);
    $service = new CommercialMandateFormService($db, $consultant, new MandateCalculator(null, self::CONFIG), $this->signature());
    self::assertSame((new CommercialMandatesService($db, $consultant))->canSend(), true, 'Las acciones de revisión no son opcionales.');
    self::assertTrue($service->canEdit(), 'Crear y editar mandatos viene por defecto.');
    self::assertArrayHasKey(CommercialMandateFormService::ACTION_SIGN, CommercialAccessPolicy::ACTIONS);
    self::assertArrayHasKey(CommercialMandateFormService::ACTION_CREATE, CommercialAccessPolicy::ACTIONS);
  }

  public function testTheFormAndTheTaskWindowRender(): void
  {
    $service = $this->service();
    $values = $service->initialValues(null, '28355');
    self::assertSame(['28355', 'Manga', '1.650.000', '10', 'Propietario 10', '7682378'], [$values['id_inmueble'], $values['barrio'], $values['precio_canon'], $values['tasa_comision'], $values['nombre_1'], $values['energia']]);
    $html = CommercialMandateFormView::render($values, ['paises' => [['pais' => 'Colombia', 'codigo' => '+57']], 'bancos' => ['Davivienda'], 'barrios' => ['Manga']], $service->calculator()->parameters(), ['id' => 0, 'ticket' => 700, 'estudio' => 621, 'estudios' => [['id' => 621, 'label' => '#621 · El libertador · Aprobado']], 'estado' => '', 'volver' => '']);
    self::assertStringContainsString('data-cmf ', $html);
    self::assertSame(5, substr_count($html, 'data-cmf-step '), 'Cinco pasos, como el formulario de WordPress.');
    self::assertSame(6, substr_count($html, 'data-cmf-owner="'), 'Hasta seis propietarios.');
    self::assertStringContainsString('name="nombre_1" value="Propietario 10"', $html);
    self::assertStringContainsString('<option value="621" selected>', $html);
    self::assertStringContainsString('data-cmf-params="', $html);

    $id = $service->save($this->post(), null, ['ticket' => 700, 'estudio' => 621])['id'];
    $mandates = new CommercialMandatesService($this->db, $this->policy);
    $rows = $mandates->repository()->forTask(700, [621], '');
    $panel = CommercialMandatesView::taskPanel(['ticket' => 700, 'asunto' => 'Arriendo', 'rows' => array_map([$mandates, 'summary'], $rows), 'can_create' => true, 'can_sign' => true, 'flash' => '']);
    self::assertStringContainsString('Mandato #' . $id, $panel);
    self::assertStringContainsString('data-cma-sign="' . $id . '" data-blockers="[&quot;Falta la hoja de cierre', $panel);
    self::assertStringContainsString('Sin hoja de cierre', $panel);
    self::assertStringContainsString('Para enviar a firma falta:', $panel, 'Lo que falta se ve sin pulsar el botón.');
    self::assertStringContainsString('cma-blocked" aria-disabled="true"', $panel);
    self::assertStringContainsString('data-cma-form data-ticket-pk="700"', $panel);
  }

  public function testSigningNeedsAnApprovedClosingSheetAndTheOwnersReviewIsOptional(): void
  {
    $service = $this->service();
    $id = $service->save($this->post())['id'];
    try {
      $service->sendToSign($id);
      self::fail('Sin hoja de cierre aprobada no se envía a firma.');
    } catch (\InvalidArgumentException $error) {
      self::assertStringContainsString('Falta la hoja de cierre', $error->getMessage());
      self::assertStringNotContainsString('propietario', $error->getMessage(), 'La revisión del propietario no es obligatoria.');
    }
    self::assertSame([], $this->calls, 'Mientras falte la hoja no se llama a eSignatures.');
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado_hoja_cierre' => 'Esperando respuesta', 'id_hoja_cierre' => '440'], ['_ID' => $id]);
    self::assertSame(['La hoja de cierre #440 está "Esperando respuesta": debe estar aprobada.'], $service->signBlockers($id));
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado_hoja_cierre' => 'Realizada'], ['_ID' => $id]);
    self::assertSame([], $service->signBlockers($id), '"Realizada" es una hoja aprobada que ya tiene contrato.');
    self::assertSame('c1', $service->sendToSign($id)['contrato'], 'Sin revisión del propietario también se envía.');
    self::assertStringContainsString('no ha revisado', CommercialMandatesService::reviewNotice('sin_enviar'), 'Solo se advierte.');
    self::assertSame('', CommercialMandatesService::reviewNotice('aprobado'));
    self::assertSame([], CommercialMandatesService::signBlockers('firmado', ['id' => 0, 'estado' => '']), 'Firmado: no hay nada que pedir.');
  }

  public function testEditingASentMandateUpdatesESignaturesOnlyWithAnApprovedClosingSheet(): void
  {
    $service = $this->service();
    $id = $service->save($this->post())['id'];
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado_hoja_cierre' => 'Aprobada'], ['_ID' => $id]);
    $service->sendToSign($id);
    $this->calls = [];
    self::assertTrue($service->save($this->post(['duracion' => '24 Meses']), $id)['firma'], 'Con la hoja aprobada, guardar actualiza el contrato de eSignatures.');
    $this->db->update('wp_jet_cct_contrato_mandato', ['estado_hoja_cierre' => 'No aprobada'], ['_ID' => $id]);
    $this->calls = [];
    $result = $service->save($this->post(['duracion' => '36 Meses']), $id);
    self::assertSame([false, true], [$result['firma'], $result['reenviar']], 'Hoja no aprobada: queda para "Reenviar a firma".');
    self::assertSame([], $this->calls);
    self::assertSame('36 Meses', $this->db->getVar('SELECT duracion FROM wp_jet_cct_contrato_mandato WHERE _ID = ?', [$id]), 'Lo editado sí se guarda.');
  }

  public function testTheCountryIsMatchedIgnoringCaseOrDefaultsToColombia(): void
  {
    $countries = [['pais' => 'Colombia', 'codigo' => '+57'], ['pais' => 'Estados Unidos', 'codigo' => '+1'], ['pais' => 'España', 'codigo' => '+34']];
    self::assertSame('Estados Unidos', CommercialMandateFormView::country($countries, 'Estados unidos'));
    self::assertSame('España', CommercialMandateFormView::country($countries, 'espana'));
    self::assertSame('Colombia', CommercialMandateFormView::country($countries, ''), 'Sin país: Colombia.');
    self::assertSame('España', CommercialMandateFormView::country($countries, 'Reino de España', '+34'), 'Si el nombre no está, por el indicativo.');
    $html = CommercialMandateFormView::render(['cantidad_propietarios' => '1', 'pais_1' => 'Estados unidos', 'indicativo_1' => ''], ['paises' => $countries, 'bancos' => [], 'barrios' => []], [], ['id' => 0, 'ticket' => 0, 'estudio' => 0, 'estudios' => [], 'estado' => '', 'volver' => '']);
    self::assertStringContainsString('<option value="Estados Unidos" data-codigo="+1" selected>', $html);
    self::assertStringContainsString('name="indicativo_1" value="+1"', $html, 'Sin indicativo, el del país.');
    self::assertStringContainsString('<select name="pais_1" data-cmf-country="1" required>', $html);
    self::assertStringContainsString('<option value="Colombia" data-codigo="+57" selected>', $html, 'Los demás propietarios: Colombia.');
  }

  public function testOwnersAndCoOwnershipAskIfTheyAreAlreadyRegistered(): void
  {
    $empty = ['paises' => [['pais' => 'Colombia', 'codigo' => '+57']], 'bancos' => [], 'barrios' => []];
    $context = ['id' => 0, 'ticket' => 0, 'estudio' => 0, 'estudios' => [], 'estado' => '', 'volver' => ''];
    $html = CommercialMandateFormView::render(['cantidad_propietarios' => '1'], $empty, [], $context);
    self::assertSame(6, substr_count($html, '¿El propietario ya está registrado?'));
    self::assertStringContainsString('¿La copropiedad ya está registrada?', $html);
    self::assertStringContainsString('name="registrado_1" value="si" data-cmf-mode>', $html, 'Sin propietario: ninguna opción marcada.');
    self::assertMatchesRegularExpression('/data-cmf-entity-data hidden>/', $html, 'Los datos se ven al elegir la opción.');
    $known = CommercialMandateFormView::render(['cantidad_propietarios' => '1', 'id_propietario_1' => '10', 'nombre_1' => 'Propietario 10', 'documento_1' => '21235713'], $empty, [], $context);
    self::assertStringContainsString('name="registrado_1" value="si" data-cmf-mode checked>', $known, 'Con su ficha: "Sí" y el registro elegido.');
    self::assertStringContainsString('Propietario 10 · 21235713', $known);
    $typed = CommercialMandateFormView::render(['cantidad_propietarios' => '1', 'nombre_1' => 'Pedro Nuevo'], $empty, [], $context);
    self::assertStringContainsString('name="registrado_1" value="no" data-cmf-mode checked>', $typed, 'Con datos sin ficha: "No, es nuevo".');
  }
}
