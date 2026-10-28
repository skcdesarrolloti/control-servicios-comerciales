<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNetIncomeSimulator;
use SCM\Commercial\CommercialOwnerPreapprovalPdf;
use SCM\Commercial\CommercialOwnerPreapprovalService;
use SCM\Commercial\CommercialSignAccessDenied;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialToolsView;
use Tests\Fixtures\CommercialOwnerContractsDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialOwnerContractsDatabase.php';
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

final class CommercialOwnerPreapprovalTest extends TestCase
{
  /** Datos obligatorios del propietario para el PDF. */
  private const OWNER = ['document'=>'1143415938', 'phone'=>'3145106244', 'email'=>'ana@example.test'];

  private Database $db;
  private CommercialAccessPolicy $policy;
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
    $this->db = CommercialOwnerContractsDatabase::create();
    $this->policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
  }

  protected function tearDown(): void { $_SESSION = $this->previousSession; }

  private function service(?CommercialAccessPolicy $policy = null): CommercialOwnerPreapprovalService
  {
    return new CommercialOwnerPreapprovalService($this->db, $policy ?? $this->policy, new Settings($this->db, true));
  }

  public function testLookupLoadsActiveContractsWithTheLiquidatorIncome(): void
  {
    $result = $this->service()->lookup('1.143.415.938');
    self::assertSame(['name'=>'Ana Propietaria', 'document'=>'1.143.415.938', 'email'=>'ana@example.test', 'phone'=>'573145106244'], $result['owner']);
    self::assertSame([1, 2], array_column($result['contracts'], 'id'));
    $mandate = $result['contracts'][0];
    // Póliza de servicios: 4 cuotas de $15.000 desde el inicio (hace 65 días): lleva 2 y aún se descuenta la prima.
    self::assertEquals([2000000, 250000, 40000, 60000, 0, 0], [$mandate['canon'], $mandate['comision'], $mandate['garantia'], $mandate['seguro'], $mandate['intereses'], $mandate['otros']]);
    self::assertEquals(['estado'=>'pendiente', 'cuotas'=>4, 'pagadas'=>2, 'valor'=>3000000, 'prima'=>60000, 'inicio'=>date('Y-m-d', strtotime('-65 days'))], $mandate['servicios']);
    self::assertNull($result['contracts'][1]['servicios']);
    // Pasadas las 4 cuotas desde el inicio, la póliza está pagada y no se descuenta.
    $this->db->update('wp_jet_cct_contratos_arrendamiento', ['inicio_contrato'=>(string) strtotime('-5 months')], ['_ID'=>1]);
    $paid = $this->service()->lookup('1143415938')['contracts'][0];
    self::assertSame(['pagada', 4, 0.0], [$paid['servicios']['estado'], $paid['servicios']['pagadas'], $paid['seguro']]);
    // Sin fecha de inicio no se puede saber: se deja la prima y se avisa.
    $this->db->update('wp_jet_cct_contratos_arrendamiento', ['inicio_contrato'=>''], ['_ID'=>1]);
    self::assertSame('sin_inicio', $this->service()->lookup('1143415938')['contracts'][0]['servicios']['estado']);
    self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $mandate['fin']);
    self::assertGreaterThanOrEqual(13, $mandate['max_months']);
    // Sin mandato se descuenta el 15 % del canon por defecto.
    self::assertEquals([1000000, 0, 150000], [$result['contracts'][1]['canon'], $result['contracts'][1]['comision'], $result['contracts'][1]['otros']]);
    self::assertSame(0, $result['active_credits']);
  }

  public function testCoOwnerIsResolvedAndApprovedCreditsAreCounted(): void
  {
    $this->db->insert('wp_scm_owner_credits', ['owner_document'=>'7654321', 'status'=>'aprobado']);
    $this->db->insert('wp_scm_owner_credits', ['owner_document'=>'7654321', 'status'=>'pagado']);
    $result = $this->service()->lookup('7654321');
    self::assertSame('Luis Copropietario', $result['owner']['name']);
    self::assertSame([1], array_column($result['contracts'], 'id'));
    self::assertSame(1, $result['active_credits']);
  }

  public function testUnknownDocumentReturnsNoContractsAndShortDocumentsAreRejected(): void
  {
    self::assertSame(['owner'=>null, 'contracts'=>[], 'active_credits'=>0], $this->service()->lookup('99999999'));
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->lookup('12');
  }

  public function testSettingsComeFromTheSharedCreditConfiguration(): void
  {
    self::assertSame(['income_months'=>3, 'term_margin_months'=>2, 'no_mandate_deduction'=>0.15], $this->service()->settings());
    $this->db->update('wp_jet_cct_confi_sistema', ['valor'=>json_encode(['owner_credit_settings'=>['income_months'=>4, 'term_margin_months'=>1, 'no_mandate_deduction'=>'20']], JSON_THROW_ON_ERROR)], ['funcion'=>'control_servicios_config']);
    $service = $this->service();
    self::assertSame(['income_months'=>4, 'term_margin_months'=>1, 'no_mandate_deduction'=>0.2], $service->settings());
    self::assertEquals(200000, $service->lookup('1143415938')['contracts'][1]['otros']);
  }

  public function testEstimateRecalculatesTheCupoOnTheServer(): void
  {
    $estimate = $this->service()->estimate(['owner'=>['name'=>' Ana  Propietaria '] + self::OWNER, 'registered'=>true, 'amount'=>999, 'sources'=>[
      ['canon'=>'$2.000.000', 'comision'=>250000, 'garantia'=>40000, 'seguro'=>180000, 'intereses'=>12000, 'otros'=>0, 'duracion'=>14, 'meta'=>'Contrato C-100'],
      ['canon'=>1000000, 'otros'=>150000, 'duracion'=>'doce'],
    ]]);
    self::assertSame('Ana Propietaria', $estimate['owner']['name']);
    // (2.000.000 − 250.000 − 40.000 − 192.000 / 12) + (1.000.000 − 150.000) = 2.544.000 × 3
    self::assertEquals(2544000, $estimate['net']);
    self::assertEquals(7632000, $estimate['amount']);
    self::assertSame([14, 0], array_column($estimate['sources'], 'duracion'));
    // Contratos del propietario registrado: descuento mínimo del 15 % del canon; lo que falte va como ajuste.
    $contracts = $this->service()->estimate(['owner'=>['name'=>'Ana'] + self::OWNER, 'registered'=>true, 'sources'=>[
      ['calc'=>'sim', 'contrato'=>true, 'canon'=>1000000, 'garantia'=>true], // 119.000 + 25.000 = 144.000 < 150.000
      ['calc'=>'sim', 'contrato'=>true, 'canon'=>2000000, 'tasa_comision'=>'12', 'garantia'=>true], // 285.600 + 50.000 ≥ 300.000
      ['calc'=>'sim', 'canon'=>1000000, 'garantia'=>true], // inmueble agregado a mano: sin mínimo
    ]]);
    self::assertEquals([6000, 0, 0], array_column($contracts['sources'], 'ajuste'));
    self::assertEquals([850000, 1664400, 856000], array_column($contracts['sources'], 'net'));
    self::assertStringContainsString('Ajuste al descuento mínimo', CommercialOwnerPreapprovalPdf::html($contracts, 'Prueba'));
    self::assertSame(12, $estimate['term']); // contrato más largo de 14 meses − 2 de margen

    $manual = $this->service()->estimate(['owner'=>['name'=>'Carlos'] + self::OWNER, 'sources'=>[['canon'=>1500000, 'comision'=>150000]]]);
    self::assertEquals(4050000, $manual['amount']);
    self::assertNull($manual['term']);
    self::assertFalse($manual['registered']);

    // Documento, celular y correo son obligatorios, igual que el nombre.
    $cases = [
      [['owner'=>['name'=>''] + self::OWNER, 'sources'=>[['canon'=>1]]], 'el nombre'],
      [['owner'=>['name'=>'X', 'document'=>''] + self::OWNER, 'sources'=>[['canon'=>1]]], 'la cédula o NIT'],
      [['owner'=>['name'=>'X', 'phone'=>''] + self::OWNER, 'sources'=>[['canon'=>1]]], 'el celular'],
      [['owner'=>['name'=>'X', 'email'=>''] + self::OWNER, 'sources'=>[['canon'=>1]]], 'el correo'],
      [['owner'=>['name'=>'X', 'email'=>'sin-arroba'] + self::OWNER, 'sources'=>[['canon'=>1]]], 'no es válido'],
      [['owner'=>['name'=>'X'] + self::OWNER, 'sources'=>[]], 'al menos un inmueble'],
      [['owner'=>['name'=>'X'] + self::OWNER, 'sources'=>[['canon'=>100, 'comision'=>200]]], '$0'],
    ];
    foreach ($cases as [$input, $message]) {
      try {
        $this->service()->estimate($input);
        self::fail('Se esperaba un error para ' . json_encode($input));
      } catch (\InvalidArgumentException $error) {
        self::assertStringContainsString($message, $error->getMessage());
      }
    }
  }

  public function testPdfDocumentUsesTheActaDesignWithUnitsAndOnlyNonZeroDeductions(): void
  {
    $estimate = $this->service()->estimate(['owner'=>['name'=>'Ana <b>Propietaria</b>', 'document'=>'1.143.415.938'] + self::OWNER, 'registered'=>true, 'sources'=>[
      ['canon'=>2000000, 'comision'=>250000, 'seguro'=>180000, 'intereses'=>12000, 'meta'=>'Contrato C-100'],
    ]]);
    $html = CommercialOwnerPreapprovalPdf::html($estimate, 'Funcionario de prueba');
    self::assertStringContainsString('scm-acta-receipt scm-services-document scm-preapproval-document', $html);
    self::assertStringContainsString('Ana &lt;b&gt;Propietaria&lt;/b&gt;', $html);
    self::assertStringContainsString('$5.202.000', $html);
    self::assertStringContainsString('<td class="is-unit">12 meses</td>', $html);
    self::assertStringContainsString('Permanece mientras la vigencia del contrato continúa.', $html);
    self::assertStringContainsString('Aplica si el seguro se difiere en cuotas.', $html);
    self::assertStringNotContainsString('Garantía inmobiliaria', $html);
    self::assertStringContainsString(CommercialToolsView::DISCLAIMER, $html);
    // Sin necesidades del propietario no sale la sección; las condiciones del crédito siempre salen, enumeradas.
    self::assertStringNotContainsString('Lo que puede resolver con su crédito', $html);
    self::assertStringContainsString('<h2>4. Condiciones para la aprobación del crédito</h2>', $html);
    foreach (CommercialOwnerPreapprovalService::CONDITIONS as $condition) self::assertStringContainsString('<li>' . $condition . '</li>', $html);
    self::assertSame('simulacion-ingreso-neto-1-143-415-938-' . date('Y-m-d') . '.pdf', CommercialOwnerPreapprovalPdf::filename($estimate));
  }

  public function testRatesComeFromTheSystemConfigurationLikeThePlugin(): void
  {
    $rates = $this->service()->rates();
    self::assertEquals(19, $rates['iva']);
    self::assertEquals(10, $rates['comision']);
    self::assertEquals(1.5, $rates['prestamo']);
    self::assertEquals(['seguro'=>2.5, 'servicios'=>2], $rates['aseguradoras']['El Libertador']);
    self::assertEquals(['seguro'=>2.2, 'servicios'=>0], $rates['aseguradoras']['Suramericana']);
    self::assertEquals(['tasa'=>2, 'expedicion'=>15000], $rates['berkley']);
    self::assertEquals(['tasa'=>2.2, 'expedicion'=>20000], $rates['mundial']);
    self::assertSame(1234.5, CommercialNetIncomeSimulator::rate('1.234,5'));
    self::assertSame(0.8, CommercialNetIncomeSimulator::rate('0.8 %'));
  }

  public function testSimulatorReplicatesTheCommissionCalculatorPlugin(): void
  {
    $rates = $this->service()->rates();
    // Con aseguradora (El Libertador): comisión 10 % + IVA solo sobre el canon, garantía 2,5 % sobre canon + administración y servicios en 2 cuotas.
    $base = ['canon'=>'$2.000.000', 'administracion'=>300000, 'tasa_comision'=>'10', 'modalidad'=>'defecto', 'aseguradora'=>'El Libertador', 'garantia'=>true, 'servicios'=>true, 'valor_asegurado'=>3000000, 'cuotas'=>2];
    $sim = CommercialNetIncomeSimulator::simulate($base, $rates);
    self::assertEquals([238000, 57500, 60000, 0, 30000, 2], [$sim['comision'], $sim['garantia'], $sim['seguro'], $sim['intereses'], $sim['cuota'], $sim['cuotas']]);
    // Desde 5 cuotas la prima se financia con la tasa de préstamo de garantías (1,5 % mensual).
    $financed = CommercialNetIncomeSimulator::simulate(['cuotas'=>6] + $base, $rates);
    self::assertEquals(CommercialNetIncomeSimulator::loan(60000, 6, 1.5), ['cuota'=>$financed['cuota'], 'intereses'=>$financed['intereses']]);
    self::assertEqualsWithDelta(10531.5, $financed['cuota'], 0.02);
    // Sin tasa de servicios en la aseguradora, se avisa y no se descuenta.
    $without = CommercialNetIncomeSimulator::simulate(['aseguradora'=>'Suramericana'] + $base, $rates);
    self::assertEquals([50600, 0], [$without['garantia'], $without['seguro']]);
    self::assertStringContainsString('no tiene tasa de servicios', $without['warnings'][0]);
    // Berkley: 2 % del canon anual + expedición, hasta 4 cuotas; la garantía es la prima del canon / 12.
    $berkley = CommercialNetIncomeSimulator::simulate(['canon'=>2000000, 'modalidad'=>'berkley', 'cuotas'=>9], $rates);
    self::assertEquals([238000, 40000, 495000, 123750, 4], [$berkley['comision'], $berkley['garantia'], $berkley['seguro'], $berkley['cuota'], $berkley['cuotas']]);
    // Seguros Mundial con servicios: (2,2 % del canon anual + expedición) + IVA, financiado.
    $mundial = CommercialNetIncomeSimulator::simulate(['canon'=>1000000, 'modalidad'=>'mundial', 'servicios'=>true, 'cuotas'=>1], $rates);
    self::assertEquals([119000, 22000, 337960], [$mundial['comision'], $mundial['garantia'], $mundial['seguro']]);
    self::assertEqualsWithDelta(5069.4, $mundial['intereses'], 0.01);
  }

  public function testEstimateSimulatesSourcesAndKeepsTheOwnerNeeds(): void
  {
    $ignored = $this->service()->estimate(['owner'=>['name'=>'Carlos Nuevo'] + self::OWNER, 'uses'=>['servicios'=>800000], 'sources'=>[['canon'=>1000000]]]);
    self::assertSame([], $ignored['uses']); // sin "needs", lo escrito no se usa
    $estimate = $this->service()->estimate(['owner'=>['name'=>'Carlos Nuevo'] + self::OWNER, 'needs'=>true, 'notes'=>"Primera línea\r\nSegunda", 'uses'=>['servicios'=>'$800.000', 'reparaciones'=>1200000, 'otros'=>0, 'desconocido'=>5], 'sources'=>[
      // El navegador no puede imponer los descuentos de una simulación: se recalculan con las tarifas.
      ['calc'=>'sim', 'canon'=>2000000, 'administracion'=>300000, 'comision'=>1, 'garantia'=>true, 'servicios'=>true, 'valor_asegurado'=>3000000, 'cuotas'=>2, 'aseguradora'=>'El Libertador'],
    ]]);
    // 2.000.000 − 238.000 − 57.500 − 60.000 / 12 = 1.699.500 × 3
    self::assertEquals(1699500, $estimate['net']);
    self::assertEquals(5098500, $estimate['amount']);
    self::assertEquals(2000000, $estimate['gross']);
    self::assertSame(['servicios'=>800000.0, 'reparaciones'=>1200000.0], $estimate['uses']);
    self::assertSame("Primera línea\nSegunda", $estimate['notes']);
    $html = CommercialOwnerPreapprovalPdf::html($estimate, 'Funcionario de prueba');
    self::assertStringContainsString(CommercialOwnerPreapprovalPdf::TITLE, $html);
    self::assertStringContainsString('Simulación con El Libertador', $html);
    self::assertStringContainsString('Se paga en 2 cuotas de $30.000', $html);
    self::assertStringContainsString('Administración (la paga el arrendatario)', $html);
    self::assertStringContainsString('$5.098.500', $html);
    self::assertStringContainsString('<h2>4. Lo que puede resolver con su crédito</h2>', $html);
    self::assertStringContainsString('<h2>5. Condiciones para la aprobación del crédito</h2>', $html);
    self::assertStringContainsString('<h2>6. Observaciones</h2>', $html);
    self::assertStringContainsString('cubre los $2.000.000 que necesita y le quedan $3.098.500 disponibles', $html);
    self::assertStringNotContainsString('Cuotas de administración', $html);
    self::assertStringContainsString('Primera línea<br />', $html);
  }

  public function testCargosWithExplicitPermissionsNeedTheToolSubview(): void
  {
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config', 'valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['herramientas'], 'actions'=>[], 'subviews'=>['herramientas'=>['ganancia_ocasional']]]]], JSON_THROW_ON_ERROR)]);
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    self::assertTrue($policy->canSubview('herramientas', 'ganancia_ocasional'));
    self::assertSame('ganancia_ocasional', $policy->resolveNavigation('herramientas', [])['herramienta']);
    $this->expectException(CommercialSignAccessDenied::class);
    $this->service($policy)->lookup('1143415938');
  }

  public function testEachToolIsItsOwnTabAndOldLinksAndPermissionsStillWork(): void
  {
    self::assertSame(['tab'=>'herramientas', 'herramienta'=>'simulador_ingreso_neto'], CommercialAccessPolicy::normalizeToolTab(['tab'=>'simulador_ingreso_neto']));
    self::assertSame(['tab'=>'herramientas', 'herramienta'=>'ganancia_ocasional'], CommercialAccessPolicy::normalizeToolTab(['tab'=>'ganancia_ocasional']));
    self::assertSame('simulador_ingreso_neto', CommercialAccessPolicy::normalizeToolTab(['tab'=>'herramientas', 'subtab'=>'preaprobado'])['herramienta']);
    self::assertSame(['tab'=>'calendario', 'subtab'=>'due'], CommercialAccessPolicy::normalizeToolTab(['tab'=>'calendario', 'subtab'=>'due']));
    // Un cargo que tenía permiso con la clave anterior "preaprobado" conserva el acceso al simulador.
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config', 'valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['herramientas'], 'actions'=>[], 'subviews'=>['herramientas'=>['preaprobado']]]]], JSON_THROW_ON_ERROR)]);
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    self::assertTrue($policy->canSubview('herramientas', 'simulador_ingreso_neto'));
    self::assertFalse($policy->canSubview('herramientas', 'ganancia_ocasional'));
    self::assertSame('Ana Propietaria', $this->service($policy)->lookup('1143415938')['owner']['name']);
  }

  public function testViewsShowTheBossRequestedUnitsAndNotes(): void
  {
    $html = CommercialToolsView::render('simulador_ingreso_neto', $this->service(), 'https://example.test');
    self::assertStringContainsString('data-commercial-tool="simulador_ingreso_neto"', $html);
    self::assertStringContainsString('data-income-months="3"', $html);
    self::assertStringContainsString('Canon mensual</span><span class="ctl-input"><input data-pre-field="canon"', $html);
    self::assertStringContainsString('<em>/ mes</em>', $html);
    // Un solo cálculo: la simulación con las tarifas de SuCasa (sin la opción "Valores del contrato").
    self::assertStringNotContainsString('data-pre-calc', $html);
    self::assertStringNotContainsString('Valores del contrato', $html);
    self::assertStringNotContainsString('ctl-logo', $html);
    self::assertStringContainsString('Simulador de ingreso neto del propietario', $html);
    self::assertStringContainsString('crédito preaprobado de hasta 3 veces su ingreso neto', $html);
    self::assertStringContainsString('data-rates="{&quot;iva&quot;:19', $html);
    self::assertStringContainsString('<option value="El Libertador">', $html);
    self::assertStringContainsString('data-pre-sim="tasa" inputmode="decimal" maxlength="8" autocomplete="off" value="10"', $html);
    self::assertStringContainsString('data-pre-use="reparaciones"', $html);
    // Datos del propietario obligatorios, duración del contrato en meses, pregunta antes de las necesidades y vista previa del PDF.
    self::assertSame(4, substr_count($html, 'class="ctl-required"'));
    self::assertStringContainsString('<select data-pre-field="duracion"><option value="6">6 meses</option><option value="12" selected>', $html);
    self::assertStringNotContainsString('data-pre-field="fin"', $html);
    self::assertStringContainsString('<div data-pre-needs-panel hidden>', $html);
    self::assertStringContainsString('data-pre-viewer-frame', $html);
    self::assertStringContainsString('Tipo de garantía', $html);
    self::assertStringNotContainsString('Por defecto', $html);
    self::assertStringNotContainsString('canon + administración', $html);

    $gain = CommercialToolsView::render('ganancia_ocasional', null, 'https://example.test');
    self::assertStringContainsString('data-commercial-tool="ganancia_ocasional"', $gain);
    self::assertStringContainsString('<dialog class="ctl-dialog"', $gain);
    self::assertStringNotContainsString('bootstrap', $gain);
  }
}
