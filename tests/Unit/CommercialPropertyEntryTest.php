<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertyEntryService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialPropertyEntryView;
use SCM\Views\CommercialTicketModalView;
use Tests\Fixtures\CommercialPropertyEntryDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPropertyEntryDatabase.php';
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

final class CommercialPropertyEntryTest extends TestCase
{
  private Database $db;
  private CommercialPropertyEntryService $service;
  private CommercialAccessPolicy $policy;
  private array $previous;

  protected function setUp(): void
  {
    $this->previous = $_SESSION ?? [];
    $_SESSION = ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
    $this->db = CommercialPropertyEntryDatabase::create();
    $this->policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    $this->service = new CommercialPropertyEntryService($this->db, $this->policy, ['property_entry_notification_emails'=>'admin@example.test']);
  }

  protected function tearDown(): void { $_SESSION = $this->previous; }

  private function input(array $changes = []): array
  {
    return $changes + ['request_id'=>str_repeat('b',32),'modo_ingreso'=>'nuevo','tipo_captacion'=>'Nueva','id_propietario'=>'10','titulo'=>'Casa de prueba','descripcion'=>'Descripción del inmueble','tipo_oferta'=>'117','tipo_inmueble'=>'106','destinacion'=>'121','pertenece_regimen'=>'No','area_construida'=>'100','area_terreno'=>'120','tiene_parqueadero'=>'No','tiene_deposito'=>'No','tiene_vigilancia'=>'No','habitaciones'=>'2','banos'=>'1','precio_arriendo'=>'1500000','antiguedad_inmueble'=>'1 a 8 años','ano_construccion'=>'2020','avaluo_catastral'=>'100000000','referencia_catastral'=>'CAT123','estrato'=>'3','procedencia'=>'Internet','amoblado'=>'No','desea_aviso'=>'Si','pais'=>'Colombia','departamento'=>'Antioquia','ciudad'=>'Medellín','barrio'=>'El Poblado','ruta'=>'1','direccion_completa'=>'Calle 10 #20-30','punto_referencia'=>'Frente al parque','direccion_fisica'=>'Calle 10 #20-30','tiene_rnt'=>'No'];
  }

  public function testCreatesPostCctRelationsMetadataTaskHistoryAndQueuedEmails(): void
  {
    $result = $this->service->save(100, $this->input(['id_empleado'=>'202','sucursal'=>'999','edad_inmueble'=>'99','ruta'=>'999']));
    $code = $result['id_inmueble'];
    $post = $this->db->getRow('SELECT * FROM wp_posts WHERE ID=?', [$code]);
    $property = $this->db->getRow('SELECT * FROM wp_jet_cct_inmuebles WHERE codigo=?', [$code]);
    self::assertSame('101', $post['post_author']);
    self::assertSame('Casa de prueba', $post['post_title']);
    self::assertSame('En borrador', $property['estado']);
    self::assertSame('101', $property['id_funcionario']);
    self::assertSame('Casa', $property['tipo_inmueble']);
    self::assertSame('Arriendo', $property['tipo_negocio']);
    self::assertSame('CAT123', $property['referencia_catastral']);
    self::assertSame('3', $property['estrato']);
    self::assertSame('Internet', $property['procedencia']);
    self::assertSame('1', $property['ruta']);
    self::assertSame((string) ((int) date('Y') - 2020), $property['edad']);
    self::assertSame('Por publicar', $this->db->getVar('SELECT estado_comercial FROM wp_jet_cct_tickets WHERE _ID=100'));
    self::assertSame((string) $code, $this->db->getVar('SELECT id_inmueble FROM wp_jet_cct_tickets WHERE _ID=100'));
    self::assertSame('10', $this->db->getVar('SELECT parent_object_id FROM wp_jet_rel_658 WHERE child_object_id=?', [$code]));
    self::assertSame('101', $this->db->getVar('SELECT parent_object_id FROM wp_jet_rel_941 WHERE child_object_id=?', [$code]));
    self::assertSame(3, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_term_relationships WHERE object_id=?', [$code]));
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_inmueble=?', [$code]));
    self::assertSame('101', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='id_empleado'", [$code]));
    self::assertSame(serialize(['Si']), $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='desea-aviso'", [$code]));
  }

  public function testCompletesReservedCodeWithoutCreatingAnotherPost(): void
  {
    $before = (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts');
    $result = $this->service->save(100, $this->input(['modo_ingreso'=>'adelantado','codigo_adelantado'=>'800','presenta-aviso'=>'Si']));
    self::assertSame(800, $result['id_inmueble']);
    self::assertSame($before, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts'));
    self::assertSame('Si', $this->db->getVar('SELECT presenta_aviso FROM wp_jet_cct_inmuebles WHERE codigo=800'));
  }

  public function testRetriedSubmissionDoesNotDuplicateBusinessRecords(): void
  {
    $first = $this->service->save(100, $this->input());
    $second = $this->service->save(100, $this->input());
    self::assertSame($first, $second);
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket'));
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
  }

  public function testPublishedAndOtherEmployeesReservedCodesCannotBeOverwritten(): void
  {
    foreach (['Publico','En borrador'] as $state) {
      $this->db->update('wp_postmeta', ['meta_value'=>$state], ['post_id'=>800,'meta_key'=>'estado']);
      $this->db->update('wp_postmeta', ['meta_value'=>'202'], ['post_id'=>800,'meta_key'=>'id_empleado']);
      try { $this->service->save(100, $this->input(['modo_ingreso'=>'adelantado','codigo_adelantado'=>'800','presenta-aviso'=>'No'])); self::fail('Se sobrescribió un código no permitido.'); }
      catch (\InvalidArgumentException | \SCM\Commercial\CommercialSignAccessDenied $exception) {}
    }
    self::assertSame('Código reservado', $this->db->getVar('SELECT post_title FROM wp_posts WHERE ID=800'));
  }

  public function testConditionalSaleAndRecaptureFieldsAreValidatedOnTheServer(): void
  {
    foreach ([['tipo_oferta'=>'119'],['tipo_captacion'=>'Recaptacion'],['pertenece_regimen'=>'Si'],['procedencia'=>'Club PPH']] as $change) {
      try { $this->service->validate(100, $this->input($change)); self::fail('Se aceptó información condicional incompleta.'); } catch (\InvalidArgumentException $exception) {}
    }
    $result = $this->service->save(100, $this->input(['tipo_captacion'=>'Recaptacion','codigo_anterior'=>'500']));
    self::assertSame((string) $result['id_inmueble'], $this->db->getVar('SELECT id_inmueble FROM wp_jet_cct_recaptaciones'));
  }

  public function testBadCatalogChoicesLocationAndTaskScopeAreRejected(): void
  {
    foreach ([['tipo_inmueble'=>'999'],['id_propietario'=>'999'],['ciudad'=>'Otra'],['latitud'=>'100','longitud'=>'-75']] as $change) {
      try { $this->service->validate(100, $this->input($change)); self::fail('Se aceptaron datos inválidos.'); } catch (\InvalidArgumentException $exception) {}
    }
    $this->expectException(\SCM\Commercial\CommercialSignAccessDenied::class);
    $this->service->save(200, $this->input());
  }

  public function testQueueFailureRollsBackThePostPropertyMetadataAndTaskChanges(): void
  {
    $this->db->pdo()->exec("CREATE TRIGGER property_queue_failure BEFORE INSERT ON skc_notification_queue BEGIN SELECT RAISE(ABORT, 'queue failure'); END");
    $before = (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts');
    try { $this->service->save(100, $this->input()); self::fail('La cola debía fallar.'); } catch (\PDOException $exception) {}
    self::assertSame($before, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts'));
    self::assertSame('500', $this->db->getVar('SELECT id_inmueble FROM wp_jet_cct_tickets WHERE _ID=100'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_rel_658'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_property_entry_requests'));
  }

  public function testTaskActionsAndFormUseTheOriginalConditionalFieldsAndRealCatalogs(): void
  {
    $task = $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=100');
    $html = CommercialTicketModalView::render(['ticket'=>$task,'timeline'=>[]], $this->policy, []);
    self::assertStringContainsString('data-commercial-task-form="sign"', $html);
    self::assertStringContainsString('data-commercial-task-form="property"', $html);
    $form = CommercialPropertyEntryView::render($this->service, 100);
    self::assertStringContainsString('Completar un código adelantado', $form);
    self::assertStringContainsString('value="117"', $form);
    self::assertStringContainsString('name="horario-mudanza-inicio-1"', $form);
    self::assertStringContainsString('name="tipo_maquinaria_per[]"', $form);
    self::assertStringContainsString('Propietario de prueba', $form);
  }

  public function testTaskActionsRespectPermissionsAndCannotAccessOtherTasks(): void
  {
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_comerciales_config','valor'=>json_encode(['commercial_permissions'=>['9'=>['views'=>['mis_tickets'],'actions'=>['ver_ticket']]]])]);
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    $task = $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=100');
    $html = CommercialTicketModalView::render(['ticket'=>$task,'timeline'=>[]], $policy, []);
    self::assertStringNotContainsString('data-commercial-task-form=', $html);
    $service = new CommercialPropertyEntryService($this->db, $policy);
    $this->expectException(\SCM\Commercial\CommercialSignAccessDenied::class);
    $service->save(100, $this->input());
  }

  public function testTaskSignContextPrefillsTheTaskAndPropertyWithUniqueFormIds(): void
  {
    $service = new \SCM\Commercial\CommercialSignService($this->db, $this->policy);
    $context = $service->taskContext(100);
    self::assertSame(['id_ticket'=>100,'id_inmueble'=>'500'], $context);
    $html = \SCM\Views\CommercialSignView::render($service, $this->policy, $context);
    self::assertStringContainsString('id="csg-task-100-form"', $html);
    self::assertStringNotContainsString('id="csg-form"', $html);
  }

  public function testPphCaptureCopiesTheAlliedMemberDetailsAndIgnoresHiddenFieldInjection(): void
  {
    $result = $this->service->save(100, $this->input(['procedencia'=>'Club PPH','id_pph'=>'1','rnt'=>'FORGED','tipo_maquinaria_per'=>['FORGED'],'ruta'=>'']));
    $code = $result['id_inmueble'];
    self::assertSame('Aliado', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='nombre-pph'", [$code]));
    self::assertSame('1', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='id_pph'", [$code]));
    self::assertSame('', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='rnt'", [$code]));
    self::assertSame(serialize([]), $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='tipo_maquinaria_per'", [$code]));
  }

  public function testCompletingAnExistingDraftKeepsItsCctIdentityAndDocument(): void
  {
    $this->db->insert('wp_jet_cct_inmuebles', ['codigo'=>'800','estado'=>'En borrador','id_funcionario'=>'101']);
    $propertyId = (int) $this->db->lastInsertId();
    $this->db->insert('wp_postmeta', ['post_id'=>800,'meta_key'=>'uso-suelo','meta_value'=>'https://example.test/old-document.pdf']);
    $this->service->save(100, $this->input(['modo_ingreso'=>'adelantado','codigo_adelantado'=>'800','presenta-aviso'=>'No']));
    self::assertSame($propertyId, (int) $this->db->getVar('SELECT _ID FROM wp_jet_cct_inmuebles WHERE codigo=800'));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_inmuebles WHERE codigo=800'));
    self::assertSame('https://example.test/old-document.pdf', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=800 AND meta_key='uso-suelo'"));
  }

  public function testDocumentFailureDoesNotLeaveAPropertyOrReceipt(): void
  {
    $before = (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts');
    try { $this->service->save(100, $this->input(), static function (): array { throw new \InvalidArgumentException('Documento inválido.'); }); self::fail('Se guardó con un documento inválido.'); } catch (\InvalidArgumentException $exception) {}
    self::assertSame($before, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_posts'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_property_entry_requests'));
  }

  public function testASeparateSubmissionCanUpdateTheSameDraftWithoutAnEmailKeyCollision(): void
  {
    $input = $this->input(['modo_ingreso'=>'adelantado','codigo_adelantado'=>'800','presenta-aviso'=>'No']);
    $this->service->save(100, $input);
    $input['request_id'] = str_repeat('c',32);
    $input['titulo'] = 'Información completada';
    $this->service->save(100, $input);
    self::assertSame('Información completada', $this->db->getVar('SELECT post_title FROM wp_posts WHERE ID=800'));
    self::assertSame(4, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_inmuebles WHERE codigo=800'));
  }
}
