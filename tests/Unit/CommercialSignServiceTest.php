<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSignService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialSignView;
use Tests\Fixtures\CommercialSignDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialSignDatabase.php';
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

final class CommercialSignServiceTest extends TestCase
{
  private Database $db;
  private CommercialSignService $service;
  private CommercialAccessPolicy $policy;
  private array $previousSession;

  protected function setUp(): void
  {
    foreach (['SCM_BASE_URL'=>'https://example.test','SCM_UPLOAD_PATH'=>sys_get_temp_dir() . '/scm-sign-tests','SCM_APP_SECRET'=>str_repeat('x', 40),'SCM_UPLOAD_MAX_BYTES'=>10485760] as $key => $value) if (!defined($key)) define($key, $value);
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
    $this->db = CommercialSignDatabase::create();
    $this->policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    $this->service = new CommercialSignService($this->db, $this->policy, ['base_url'=>'https://example.test', 'sign_notification_emails'=>'admin@example.test']);
  }

  protected function tearDown(): void { $_SESSION = $this->previousSession; }

  private function input(array $overrides = []): array
  {
    return $overrides + ['request_id'=>str_repeat('a', 32),'tipo_aviso'=>'Nuevo','id_ticket'=>'100','id_inmueble'=>'500','barrio_id'=>'1','tipo_inmueble'=>'Casa','categoria'=>'Arriendo','aviso_senuelo'=>'No','pego_aviso'=>'Si','punto_referencia'=>'Frente al parque','cantidad'=>'2','mapa_latitud'=>'6.2','mapa_longitud'=>'-75.5','mapa_precision'=>'12'];
  }

  private function save(array $overrides = []): array { return $this->service->save($this->input($overrides), static fn(): array => ['https://example.test/evidence.jpg']); }

  public function testNewSignWritesTheExistingTablesAndQueuesEmailAtomically(): void
  {
    $result = $this->save(['id_empleado'=>'202','creador'=>'Impostor','fecha'=>'1','ruta'=>'999','sucursal'=>'999']);
    $sign = $this->db->getRow('SELECT * FROM wp_jet_cct_retoques');
    self::assertSame('101', $sign['id_empleado']);
    self::assertSame('Funcionario de prueba', $sign['creador']);
    self::assertSame('3', $sign['sucursal']);
    self::assertSame('1', $sign['ruta']);
    self::assertSame('2', $sign['cantidad']);
    self::assertSame('https://example.test/evidence.jpg', $sign['fotos']);
    self::assertSame('Si', $this->db->getVar('SELECT presenta_aviso FROM wp_jet_cct_inmuebles WHERE codigo = 500'));
    self::assertSame('Si', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE meta_key = 'presenta-aviso'"));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble'));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_aviso = ?', [$result['id_aviso']]));
    self::assertSame(2, (int) $this->db->getVar("SELECT COUNT(*) FROM skc_notification_queue WHERE status='pending' AND source_module='commercial_signs' AND project_code='control-servicios-comerciales'"));
    self::assertSame('101', $this->db->getVar('SELECT created_by FROM wp_skc_ubicacion_inmuebles_mapa'));
    self::assertEquals(6.2, $this->db->getVar('SELECT latitud FROM wp_skc_ubicacion_inmuebles_mapa'));
    self::assertArrayNotHasKey('warning', $result);
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_reportes_avisos'));
  }

  public function testRetouchUsesServerPropertyAndPaymentConfiguration(): void
  {
    $this->save(['tipo_aviso'=>'Retoque','barrio'=>'Falso','ruta'=>'999','tipo_inmueble'=>'Falso','categoria'=>'Falso','valor_transporte'=>'999999']);
    $sign = $this->db->getRow('SELECT * FROM wp_jet_cct_retoques');
    self::assertSame('El Poblado', $sign['barrio']);
    self::assertSame('Casa', $sign['tipo_inmueble']);
    self::assertSame('1', $sign['ruta']);
    self::assertEqualsWithDelta(2000 / 1.52, (float) $this->db->getVar('SELECT valor FROM wp_jet_cct_reportes_avisos'), 0.001);
    self::assertSame('1', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE meta_key = 'avisos'"));
    self::assertSame('Retocando', $this->db->getVar('SELECT estado_comercial FROM wp_jet_cct_tickets WHERE _ID=100'));
  }

  public function testSpecialEmployeeKeepsTheOriginalPaymentRule(): void
  {
    $_SESSION['scm_employee_id'] = '2038';
    $this->db->update('wp_jet_cct_funcionarios', ['id_empleado'=>'2038'], ['_ID'=>1]);
    $this->db->update('wp_jet_cct_tickets', ['id_empleado'=>'2038'], ['_ID'=>100]);
    $this->save(['tipo_aviso'=>'Retoque']);
    self::assertEquals(3000, $this->db->getVar('SELECT valor FROM wp_jet_cct_reportes_avisos'));
  }

  public function testRetryDoesNotDuplicateEvidenceHistoriesCountersOrEmails(): void
  {
    $first = $this->save(['tipo_aviso'=>'Retoque']);
    $second = $this->service->save($this->input(['tipo_aviso'=>'Retoque']), static function (): array { throw new \LogicException('No debe guardar archivos otra vez.'); });
    self::assertSame($first['id_aviso'], $second['id_aviso']);
    foreach (['retoques','historial_del_inmueble','historial_del_ticket','reportes_avisos'] as $table) self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_' . $table));
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
    self::assertSame('1', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE meta_key = 'avisos'"));
  }

  public function testUnsuccessfulRetouchUnpublishesInTheCurrentDatabaseWithoutExternalSync(): void
  {
    foreach (['Arrendado'=>'Arrendado','Vendido'=>'Vendido','No quiere promocion'=>'Desistido'] as $reason => $state) {
      $result = $this->save(['request_id'=>bin2hex(random_bytes(16)),'tipo_aviso'=>'Retoque','pego_aviso'=>'No','razon'=>$reason,'cantidad'=>'0']);
      self::assertSame($state, $this->db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE codigo=500'));
      self::assertArrayNotHasKey('portal_pending', $result);
      self::assertArrayNotHasKey('warning', $result);
      self::assertSame($state, $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=500 AND meta_key='estado'"));
    }
    self::assertSame(3, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_despublicaciones'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_reportes_avisos'));
  }

  public function testOtherReasonDoesNotUnpublishOrGeneratePayment(): void
  {
    $result = $this->save(['tipo_aviso'=>'Retoque','pego_aviso'=>'No','razon'=>'Otra razon','cantidad'=>'0']);
    self::assertArrayNotHasKey('warning', $result);
    self::assertSame('Publicado', $this->db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE codigo=500'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_reportes_avisos'));
  }

  public function testExistingReceiptsWithRetiredSyncColumnsRemainCompatible(): void
  {
    $this->service->ensureRequestsTable();
    $this->db->pdo()->exec('ALTER TABLE wp_scm_commercial_sign_requests ADD COLUMN portal_pending INTEGER NOT NULL DEFAULT 1');
    $this->db->pdo()->exec('ALTER TABLE wp_scm_commercial_sign_requests ADD COLUMN last_error TEXT');
    $first = $this->save(['tipo_aviso'=>'Retoque','pego_aviso'=>'No','razon'=>'Arrendado','cantidad'=>'0']);
    $retry = $this->save(['tipo_aviso'=>'Retoque','pego_aviso'=>'No','razon'=>'Arrendado','cantidad'=>'0']);
    self::assertSame($first['id_aviso'], $retry['id_aviso']);
    self::assertArrayNotHasKey('warning', $retry);
    self::assertArrayNotHasKey('portal_pending', $retry);
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_retoques'));
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
  }

  public function testInvalidCatalogChoicesAndMissingReasonAreRejected(): void
  {
    foreach ([['barrio_id'=>'999'], ['tipo_inmueble'=>'Falso'], ['categoria'=>'Falso'], ['tipo_aviso'=>'Retoque','pego_aviso'=>'No','razon'=>'']] as $change) {
      try { $this->save($change); self::fail('Se aceptó una selección inválida.'); } catch (\InvalidArgumentException $exception) {}
    }
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_retoques'));
  }

  public function testMissingGpsAndInvalidCoordinatesCannotCreateRecords(): void
  {
    foreach (['','abc','NaN','91','INF'] as $latitude) {
      try { $this->save(['mapa_latitud'=>$latitude]); self::fail('Se aceptaron coordenadas inválidas.'); }
      catch (\InvalidArgumentException $exception) { self::assertStringContainsString('GPS', $exception->getMessage()); }
    }
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_retoques'));
  }

  public function testLocationAtZeroIsValid(): void
  {
    $this->save(['mapa_latitud'=>'0','mapa_longitud'=>'0','mapa_precision'=>'0']);
    self::assertSame(0.0, $this->db->getVar('SELECT latitud FROM wp_skc_ubicacion_inmuebles_mapa'));
  }

  public function testAnotherEmployeesTaskIsDeniedOnLookupAndSave(): void
  {
    $this->expectException(\RuntimeException::class);
    $this->service->lookup(200, '600');
  }

  public function testMismatchedPropertyIsDeniedEvenForTheAssignedTask(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->save(['id_inmueble'=>'600']);
  }

  public function testPermissionsAreRequiredOnTheServer(): void
  {
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config','valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['inicio'],'actions'=>[]]]])]);
    $settings = new Settings($this->db, false, 'control_servicios_comerciales_config');
    $service = new CommercialSignService($this->db, new CommercialAccessPolicy($settings, $this->db, ['11']));
    $this->expectException(\RuntimeException::class);
    $service->save($this->input(), static fn(): array => []);
  }

  public function testQueueFailureRollsBackAllLocalBusinessWrites(): void
  {
    $this->db->pdo()->exec("CREATE TRIGGER queue_failure BEFORE INSERT ON skc_notification_queue BEGIN SELECT RAISE(ABORT, 'simulated queue failure'); END");
    try { $this->save(); self::fail('La cola debía fallar.'); } catch (\PDOException $exception) { self::assertStringContainsString('simulated', $exception->getMessage()); }
    foreach (['wp_jet_cct_retoques','wp_skc_ubicacion_inmuebles_mapa','wp_postmeta','wp_jet_cct_historial_del_inmueble','wp_jet_cct_historial_del_ticket','wp_scm_commercial_sign_requests'] as $table) self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM ' . $table));
    self::assertNull($this->db->getVar('SELECT presenta_aviso FROM wp_jet_cct_inmuebles WHERE codigo=500'));
  }

  public function testFailedPhotoUploadRollsBackTheReceiptAndCanBeRetried(): void
  {
    try { $this->service->save($this->input(), static fn(): array => []); self::fail('Se aceptó un registro sin evidencia.'); } catch (\InvalidArgumentException $exception) {}
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_commercial_sign_requests'));
    self::assertGreaterThan(0, $this->save()['id_aviso']);
  }

  public function testInvalidPaymentConfigurationDoesNotSaveTheRetouch(): void
  {
    $this->db->update('wp_jet_cct_confi_sistema', ['valor'=>'0'], ['funcion'=>'factor_prestacional']);
    try { $this->save(['tipo_aviso'=>'Retoque']); self::fail('Se aceptó un factor cero.'); } catch (\RuntimeException $exception) {}
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_retoques'));
  }

  public function testViewShowsTwoChoicesAndKeepsTheFormInitiallyHidden(): void
  {
    $html = CommercialSignView::render($this->service, $this->policy);
    self::assertStringContainsString('data-sign-type="Nuevo"', $html);
    self::assertStringContainsString('data-sign-type="Retoque"', $html);
    self::assertStringContainsString('data-sign-form hidden', $html);
    self::assertStringContainsString('name="mapa_latitud"', $html);
    self::assertStringContainsString('name="fotos[]"', $html);
  }
}
