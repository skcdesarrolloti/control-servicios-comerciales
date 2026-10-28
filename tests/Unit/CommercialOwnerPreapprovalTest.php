<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
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
    // La póliza de servicios del mandato es mensual: se entrega como seguro de 12 meses.
    self::assertEquals([2000000, 250000, 40000, 180000, 0, 0], [$mandate['canon'], $mandate['comision'], $mandate['garantia'], $mandate['seguro'], $mandate['intereses'], $mandate['otros']]);
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
    $today = (int) strtotime('2026-01-01');
    $estimate = $this->service()->estimate(['owner'=>['name'=>' Ana  Propietaria ', 'document'=>'1143415938'], 'registered'=>true, 'amount'=>999, 'sources'=>[
      ['canon'=>'$2.000.000', 'comision'=>250000, 'garantia'=>40000, 'seguro'=>180000, 'intereses'=>12000, 'otros'=>0, 'fin'=>'2027-03-02', 'meta'=>'Contrato C-100'],
      ['canon'=>1000000, 'otros'=>150000, 'fin'=>'no-es-fecha'],
    ]], $today);
    self::assertSame('Ana Propietaria', $estimate['owner']['name']);
    // (2.000.000 − 250.000 − 40.000 − 192.000 / 12) + (1.000.000 − 150.000) = 2.544.000 × 3
    self::assertEquals(2544000, $estimate['net']);
    self::assertEquals(7632000, $estimate['amount']);
    self::assertSame(['2027-03-02', ''], array_column($estimate['sources'], 'fin'));
    self::assertSame(12, $estimate['term']); // 425 días = 14 meses de 30 días − 2 de margen

    $manual = $this->service()->estimate(['owner'=>['name'=>'Carlos'], 'sources'=>[['canon'=>1500000, 'comision'=>150000]]], $today);
    self::assertEquals(4050000, $manual['amount']);
    self::assertNull($manual['term']);
    self::assertFalse($manual['registered']);

    foreach ([[['owner'=>['name'=>''], 'sources'=>[['canon'=>1]]], 'nombre'], [['owner'=>['name'=>'X'], 'sources'=>[]], 'al menos un inmueble'], [['owner'=>['name'=>'X'], 'sources'=>[['canon'=>100, 'comision'=>200]]], '$0']] as [$input, $message]) {
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
    $estimate = $this->service()->estimate(['owner'=>['name'=>'Ana <b>Propietaria</b>', 'document'=>'1.143.415.938'], 'registered'=>true, 'sources'=>[
      ['canon'=>2000000, 'comision'=>250000, 'seguro'=>180000, 'intereses'=>12000, 'meta'=>'Contrato C-100'],
    ]]);
    $html = CommercialOwnerPreapprovalPdf::html($estimate, 'Funcionario de prueba');
    self::assertStringContainsString('scm-acta-receipt scm-services-document scm-preapproval-document', $html);
    self::assertStringContainsString('Ana &lt;b&gt;Propietaria&lt;/b&gt;', $html);
    self::assertStringContainsString('$5.202.000', $html);
    self::assertStringContainsString('<td class="is-unit">12 meses</td>', $html);
    self::assertStringContainsString('Permanece mientras la vigencia del contrato continúa.', $html);
    self::assertStringContainsString('Aplica si el seguro se difiere a 12 meses.', $html);
    self::assertStringNotContainsString('Garantía inmobiliaria', $html);
    self::assertStringContainsString(CommercialToolsView::DISCLAIMER, $html);
    self::assertSame('cupo-preaprobado-1-143-415-938-' . date('Y-m-d') . '.pdf', CommercialOwnerPreapprovalPdf::filename($estimate));
  }

  public function testCargosWithExplicitPermissionsNeedTheToolSubview(): void
  {
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config', 'valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['herramientas'], 'actions'=>[], 'subviews'=>['herramientas'=>['ganancia_ocasional']]]]], JSON_THROW_ON_ERROR)]);
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    self::assertTrue($policy->canSubview('herramientas', 'ganancia_ocasional'));
    self::assertSame('ganancia_ocasional', $policy->resolveNavigation('herramientas', [])['subtab']);
    $this->expectException(CommercialSignAccessDenied::class);
    $this->service($policy)->lookup('1143415938');
  }

  public function testViewsShowTheBossRequestedUnitsAndNotes(): void
  {
    $html = CommercialToolsView::render('preaprobado', $this->service(), 'https://example.test');
    self::assertStringContainsString('data-commercial-tool="preaprobado"', $html);
    self::assertStringContainsString('data-income-months="3"', $html);
    self::assertStringContainsString('Canon / valor de la prima</span><span class="ctl-input"><input data-pre-field="canon"', $html);
    self::assertStringContainsString('<em>/ mes</em>', $html);
    self::assertStringContainsString('<em>12 meses</em>', $html);
    self::assertStringContainsString('Permanece mientras la vigencia del contrato continúa.', $html);
    self::assertStringContainsString('Aplica si el seguro se difiere a 12 meses.', $html);
    self::assertStringContainsString('membrete-sucasa-header.jpg', $html);

    $gain = CommercialToolsView::render('ganancia_ocasional', null, 'https://example.test');
    self::assertStringContainsString('data-commercial-tool="ganancia_ocasional"', $gain);
    self::assertStringContainsString('<dialog class="ctl-dialog"', $gain);
    self::assertStringNotContainsString('bootstrap', $gain);
  }
}
