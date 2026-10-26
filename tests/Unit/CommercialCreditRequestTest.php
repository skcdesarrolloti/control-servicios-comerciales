<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialCreditRequestService;
use SCM\Commercial\CommercialSignAccessDenied;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialCreditRequestView;
use Tests\Fixtures\CommercialCreditDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialCreditDatabase.php';
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

final class CommercialCreditRequestTest extends TestCase
{
  private Database $db;
  private CommercialAccessPolicy $policy;
  private array $previousSession;

  protected function setUp(): void
  {
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://example.test');
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
    $this->db = CommercialCreditDatabase::create();
    $this->policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
  }

  protected function tearDown(): void { $_SESSION = $this->previousSession; }

  private function service(): CommercialCreditRequestService
  {
    return new CommercialCreditRequestService($this->db, $this->policy, ['owner_credits_url'=>'https://example.test/inmobiliario/index.php?tab=credito_propietario'], new Settings($this->db, true));
  }

  public function testSearchFindsOnlyActiveContractsAndResolvesTheOwnerByDocument(): void
  {
    $contracts = $this->service()->contractsByOwnerDocument('1.143.415.938');
    self::assertSame([1, 2], array_column($contracts, 'id'));
    self::assertSame('Ana Propietaria', $contracts[0]['owner']['name']);
    self::assertSame('mandato_1', $contracts[0]['owner']['key']);
    self::assertSame('573145106244', $contracts[0]['owner']['phone']);
    self::assertGreaterThanOrEqual(13, $contracts[0]['max_months']);
    self::assertSame('contrato', $contracts[1]['owner']['key']);

    $coOwner = $this->service()->contractsByOwnerDocument('7654321');
    self::assertSame([1], array_column($coOwner, 'id'));
    self::assertSame(['mandato_2', 'Luis Copropietario'], [$coOwner[0]['owner']['key'], $coOwner[0]['owner']['name']]);
  }

  public function testSearchRejectsShortDocuments(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->contractsByOwnerDocument('12');
  }

  public function testCreateRegistersAPendingRequestWithTheMandateIncomeAndHistories(): void
  {
    $result = $this->service()->create(['beneficiary_type'=>'propietario','contract_id'=>'1','owner_key'=>'mandato_2','amount'=>'$1.500.000','term'=>'12','purpose'=>'Educación','created_by_id'=>'999','status'=>'aprobado']);
    $credit = $this->db->getRow('SELECT * FROM wp_scm_owner_credits WHERE id = ?', [$result['credit_id']]);
    self::assertSame('pendiente', $credit['status']);
    self::assertSame(['C-100', '500', 'Luis Copropietario', '7654321', 'doc:7654321'], [$credit['contract_code'], $credit['property'], $credit['owner_name'], $credit['owner_document'], $credit['owner_id']]);
    self::assertEquals(1500000, $credit['requested_amount']);
    self::assertSame(['12', 'Educación', 'propietario', '101', 'Funcionario de prueba'], [(string) $credit['requested_term'], $credit['purpose'], $credit['beneficiary_type'], $credit['created_by_id'], $credit['created_by_name']]);
    self::assertNull($credit['quote_id']);
    self::assertEquals(2000000, $credit['gross_income']);
    self::assertSame(['comision'=>250000, 'garantia'=>40000, 'poliza_servicios'=>0, 'otros'=>0], json_decode($credit['deductions_json'], true));

    $event = $this->db->getRow('SELECT * FROM wp_scm_owner_credit_events WHERE credit_id = ?', [$result['credit_id']]);
    self::assertSame(['solicitado', '101'], [$event['action'], $event['actor_id']]);
    $contractHistory = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento');
    self::assertSame(['Solicitud de crédito', '101', '1'], [$contractHistory['tipo_reporte'], $contractHistory['cct_author_id'], $contractHistory['id_contrato']]);
    self::assertStringContainsString('Crédito #' . $result['credit_id'] . ' solicitado por el propietario Luis Copropietario', $contractHistory['observacion']);
    self::assertStringStartsWith('Contrato #C-100: ', (string) $this->db->getVar('SELECT observacion FROM wp_jet_cct_historial_del_inmueble'));
  }

  public function testWithoutMandateTheConfiguredCanonPercentageIsDeducted(): void
  {
    $this->db->update('wp_jet_cct_confi_sistema', ['valor'=>json_encode(['owner_credit_settings'=>['no_mandate_deduction'=>0.2]], JSON_THROW_ON_ERROR)], ['funcion'=>'control_servicios_config']);
    $result = $this->service()->create(['contract_id'=>'2','amount'=>'800000','term'=>'6','purpose'=>'Otra cosa']);
    $credit = $this->db->getRow('SELECT * FROM wp_scm_owner_credits WHERE id = ?', [$result['credit_id']]);
    self::assertEquals(200000, json_decode($credit['deductions_json'], true)['otros']);
    self::assertSame(CommercialCreditRequestService::PURPOSES[0], $credit['purpose']);
  }

  public function testCreateValidatesBeneficiaryContractOwnerAmountAndTerm(): void
  {
    $cases = [
      [['beneficiary_type'=>'arrendatario','contract_id'=>'1','owner_key'=>'mandato_1','amount'=>'1000','term'=>'3'], 'todavía no están disponibles'],
      [['contract_id'=>'3','amount'=>'1000','term'=>'3'], 'Entregado o Por entregar'],
      [['contract_id'=>'99','amount'=>'1000','term'=>'3'], 'elige el contrato'],
      [['contract_id'=>'1','owner_key'=>'otro','amount'=>'1000','term'=>'3'], 'Selecciona a quién'],
      [['contract_id'=>'1','owner_key'=>'mandato_1','amount'=>'0','term'=>'3'], 'valor solicitado'],
      [['contract_id'=>'1','owner_key'=>'mandato_1','amount'=>'1000','term'=>'61'], 'plazo'],
    ];
    foreach ($cases as [$input, $message]) {
      try {
        $this->service()->create($input);
        self::fail('Se esperaba un error para ' . json_encode($input));
      } catch (\InvalidArgumentException $error) {
        self::assertStringContainsString($message, $error->getMessage());
      }
    }
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credits'));
  }

  public function testCargosWithExplicitPermissionsNeedTheCreditAction(): void
  {
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config', 'valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['solicitud_credito'], 'actions'=>[]]]], JSON_THROW_ON_ERROR)]);
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    $service = new CommercialCreditRequestService($this->db, $policy, [], new Settings($this->db, true));
    self::assertStringContainsString('no tiene permiso', CommercialCreditRequestView::render($service, $policy));
    $this->expectException(CommercialSignAccessDenied::class);
    $service->contractsByOwnerDocument('1143415938');
  }

  public function testViewOnlyOffersTheRequestForm(): void
  {
    $html = CommercialCreditRequestView::render($this->service(), $this->policy);
    self::assertStringContainsString('data-credit-form', $html);
    self::assertStringContainsString('value="arrendatario" disabled', $html);
    self::assertStringNotContainsString('<table', $html);
  }
}
