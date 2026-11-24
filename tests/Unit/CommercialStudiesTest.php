<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\CommercialTaskTools;
use SCM\Commercial\Studies\CommercialStudiesNotifier;
use SCM\Commercial\Studies\CommercialStudiesRepository;
use SCM\Commercial\Studies\CommercialStudiesService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\PublicStudyAccess;
use SCM\Views\CommercialDashboardView;
use SCM\Views\CommercialStudiesView;
use SCM\Views\PublicStudyView;
use Tests\Fixtures\CommercialStudiesDatabase;

final class CommercialStudiesTest extends TestCase
{
  private Database $db;
  private array $previousSession;
  /** @var list<array{to:mixed,subject:string,html:string}> */
  private array $emails = [];
  /** @var list<array{phone:string,template:string,components:array<int,mixed>}> */
  private array $whatsapps = [];

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialStudiesDatabase.php';
    if (!defined('SCM_APP_SECRET')) define('SCM_APP_SECRET', 'secreto-de-prueba-de-al-menos-32-caracteres');
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://panel.test/public');
    $this->db = CommercialStudiesDatabase::create();
    $this->login('101', '9');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function login(string $employee, string $cargo): void
  {
    $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => (int) $employee, 'scm_employee_id' => $employee, 'scm_user_cargo' => $cargo, 'scm_user' => 'Prueba'];
  }

  private function service(): CommercialStudiesService
  {
    $settings = new Settings($this->db);
    $notifier = new CommercialStudiesNotifier($this->db, $settings,
      function ($to, string $subject, string $html, array $options): void { $this->emails[] = ['to' => $to, 'subject' => $subject, 'html' => $html]; },
      function (string $phone, string $template, array $components, array $options): void { $this->whatsapps[] = ['phone' => $phone, 'template' => $template, 'components' => $components]; }
    );
    return new CommercialStudiesService(new CommercialStudiesRepository($this->db), $settings, CommercialStudiesDatabase::policy($this->db), $notifier);
  }

  /** @param array<string,mixed> $override */
  private function create(array $override = [], int $task = 700): array
  {
    return $this->service()->create($task, $override + ['aseguradora' => 'El libertador', 'num_solicitud' => '12548532', 'valor' => '$ 1.500.000', 'cliente' => 'Laura Cliente', 'email' => 'laura@example.test', 'celular' => '3001234567']);
  }

  /** @param array<string,mixed> $override */
  private function approve(int $id, array $override = []): array
  {
    (new Settings($this->db))->set(CommercialStudiesService::SETTING, ['responsable_entrega' => '404'], 1);
    return $this->service()->respond($id, 700, $override + [
      'estado' => 'Aprobado', 'id_inmueble' => '9001', 'ubicacion_llaves' => 'Portería', 'persona_llaves' => 'Recepción', 'contacto_llaves' => '321 699 9529',
      'fecha_posible_entrega' => '2026-12-15', 'link_drive' => 'https://drive.google.com/drive/folders/abc', 'servicios_publicos' => ['Agua', 'Gas', 'Inventado'],
    ]);
  }

  /** @return array<string,mixed> */
  private function row(string $table, int $id): array
  {
    return $this->db->getRow('SELECT * FROM wp_' . $table . ' WHERE _ID = ?', [$id]) ?? [];
  }

  // ── Registro en permisos, router, configuración y avisos ──

  public function testRegisteredInPermissionsRouterSettingsMenuAndInternalNotifications(): void
  {
    self::assertArrayHasKey('estudios', CommercialAccessPolicy::VIEWS);
    self::assertContains('estudios', CommercialAccessPolicy::VIEW_GROUPS['Actividades comerciales']);
    self::assertArrayHasKey('estudios', CommercialAccessPolicy::SCOPED_VIEWS);
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    foreach (['estudio_crear', 'estudio_responder', 'estudio_desistir'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
      self::assertContains($action, array_merge(...array_values($groups)));
    }
    foreach (['commercial_study_form' => 'form', 'commercial_study_create' => 'create', 'commercial_study_update' => 'update', 'commercial_study_respond' => 'respond', 'commercial_study_properties' => 'properties', 'commercial_study_desist' => 'desist', 'commercial_study_desist_form' => 'desistForm', 'commercial_studies_list' => 'list', 'commercial_study_view' => 'view'] as $action => $method) {
      self::assertSame($method, CommercialActionRouter::STUDY_ACTIONS[$action]);
    }
    self::assertContains('estudios', CommercialSettingsService::SECTIONS);
    foreach ([CommercialStudiesNotifier::EVENT_CREATED, CommercialStudiesNotifier::EVENT_ANSWERED, CommercialStudiesNotifier::EVENT_APPROVED, CommercialStudiesNotifier::EVENT_DESISTED] as $event) {
      self::assertContains($event, CommercialInternalNotifications::events());
    }
    self::assertTrue(CommercialTaskTools::allows(CommercialTaskTools::STUDY, 'Arriendo'));
    self::assertTrue(CommercialTaskTools::allows(CommercialTaskTools::STUDY, 'Arriendo y venta'));
    self::assertFalse(CommercialTaskTools::allows(CommercialTaskTools::STUDY, 'Venta'));

    $links = (new \ReflectionMethod(CommercialDashboardView::class, 'portfolioLinks'))->invoke(null, ['mis_tickets', 'estudios'], CommercialStudiesDatabase::policy($this->db));
    self::assertContains('Mis estudios', array_column($links, 'label'));
    self::assertSame(['portafolio' => '1'], $links[array_search('Mis estudios', array_column($links, 'label'), true)]['params']);
    self::assertSame('https://panel.test/public/estudio.php?id=', HistoryLinkMap::idButtons()['id_estudio_aseguradora']['base'], 'La cronología ya no abre la página de WordPress.');
  }

  // ── Crear ───────────────────────────────────────────

  public function testCreateSavesStudyMovesTaskAndNotifiesClientAndTeam(): void
  {
    (new Settings($this->db))->set(CommercialInternalNotifications::SETTING, ['estudio_creado' => ['303']], 1);
    $result = $this->create();
    $study = $this->row('jet_cct_estudios_aseguradoras', $result['id']);
    self::assertSame('Esperando respuesta', $study['estado']);
    self::assertSame('1500000', $study['valor']);
    self::assertSame('101', $study['id_empleado']);
    self::assertSame('101', $study['cct_author_id'], 'El autor es el id_empleado real.');
    self::assertSame('700', $study['id_ticket']);
    self::assertSame('55', $study['id_cliente']);
    self::assertSame('Finca Raíz', $study['medio'], 'El estudio hereda el medio de la tarea.');
    $task = $this->row('jet_cct_tickets', 700);
    self::assertSame('En estudio', $task['estado_comercial']);
    self::assertSame('En proceso', $task['estado']);
    self::assertSame('Pendiente', $task['estado_administrativo'], 'estado_administrativo no se toca.');
    $history = $this->db->getCol("SELECT respuesta FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = '700' ORDER BY _ID");
    self::assertStringContainsString('estudio #' . $result['id'], $history[0]);
    self::assertStringContainsString('estudio.php?e=', $history[0]);
    self::assertStringContainsString('"Mostrando" a "En estudio"', $history[1]);

    self::assertSame(['correo' => true, 'whatsapp' => true], $result['enviado']);
    self::assertSame('laura@example.test', $this->emails[0]['to']);
    self::assertStringContainsString('Su consultor/a asignado es', $this->emails[0]['html']);
    self::assertSame(['101@example.test', '303@example.test'], $this->emails[1]['to'], 'Quien lo hizo y Notificaciones internas.');
    $whatsapp = $this->whatsapps[0];
    self::assertSame(CommercialStudiesNotifier::TEMPLATE_CREATED, $whatsapp['template']);
    self::assertSame('+573001234567', $whatsapp['phone']);
    $values = array_column($whatsapp['components'][0]['parameters'], 'text');
    self::assertSame(['Laura Cliente', 'El libertador', (string) $result['id']], array_slice($values, 0, 3));
    self::assertSame('Consultora Uno · Correo: 101@example.test · Celular: 3000000101', $values[3]);
    self::assertSame($result['id'], PublicStudyAccess::verify($whatsapp['components'][1]['parameters'][0]['text']));
  }

  public function testCreateValidatesTopicPermissionInsurerAndDuplicates(): void
  {
    try {
      $this->create([], 702);
      self::fail('Una tarea de venta no tiene estudios.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('no aplica a las tareas de Venta', $exception->getMessage());
    }
    foreach ([['aseguradora' => 'Inventada'], ['num_solicitud' => ''], ['valor' => '0'], ['email' => '', 'celular' => '']] as $bad) {
      try {
        $this->create($bad);
        self::fail('Debió rechazar ' . json_encode($bad));
      } catch (\InvalidArgumentException) {
        self::assertTrue(true);
      }
    }
    $this->create();
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('ya tiene el estudio');
    $this->create();
  }

  public function testCargoWithoutActionsCannotCreate(): void
  {
    $this->login('707', '7');
    $this->expectException(\RuntimeException::class);
    $this->create();
  }

  // ── Responder ───────────────────────────────────────

  public function testPostponedKeepsTaskOpenAndRequiresMissingInfo(): void
  {
    $id = $this->create()['id'];
    try {
      $this->service()->respond($id, 700, ['estado' => 'Aplazado']);
      self::fail('Aplazado pide lo que falta.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('falta', $exception->getMessage());
    }
    $this->whatsapps = [];
    $this->service()->respond($id, 700, ['estado' => 'Aplazado', 'faltante' => 'Certificado laboral']);
    self::assertSame('Aplazado', $this->row('jet_cct_estudios_aseguradoras', $id)['estado']);
    self::assertSame('En estudio', $this->row('jet_cct_tickets', 700)['estado_comercial'], 'La tarea sigue abierta, no postergada.');
    $values = array_column($this->whatsapps[0]['components'][0]['parameters'], 'text');
    self::assertSame(CommercialStudiesNotifier::TEMPLATE_ANSWERED, $this->whatsapps[0]['template']);
    self::assertSame('Aplazado: faltan documentos', $values[3]);
    self::assertSame('Necesitamos: Certificado laboral', $values[4]);

    // Desde Aplazado se puede volver a "Esperando respuesta" cuando llegan los documentos.
    $this->service()->respond($id, 700, ['estado' => 'Esperando respuesta']);
    self::assertSame('Esperando respuesta', $this->row('jet_cct_estudios_aseguradoras', $id)['estado']);
  }

  public function testRejectedAndDesistedCloseTheTask(): void
  {
    $id = $this->create()['id'];
    $this->service()->respond($id, 700, ['estado' => 'Rechazado', 'observacion' => 'Ingresos insuficientes']);
    $task = $this->row('jet_cct_tickets', 700);
    self::assertSame(['Rechazado', 'Cerrado'], [$task['estado_comercial'], $task['estado']]);

    $other = $this->create(['num_solicitud' => '999'])['id'];
    try {
      $this->service()->respond($other, 700, ['estado' => 'Desistido', 'motivo' => 'Inventado']);
      self::fail('El motivo debe ser del glosario.');
    } catch (\InvalidArgumentException) {
      self::assertTrue(true);
    }
    $this->service()->respond($other, 700, ['estado' => 'Desistido', 'motivo' => 'Arrendatario desistio', 'observacion' => 'Consiguió otro']);
    self::assertSame('Arrendatario desistio · Consiguió otro', $this->row('jet_cct_estudios_aseguradoras', $other)['razon_desistido']);
    self::assertSame('Desistido', $this->row('jet_cct_tickets', 700)['estado_comercial']);
  }

  public function testApprovalCreatesDeliveryCaseContractAndClosesProperty(): void
  {
    (new Settings($this->db))->set(CommercialInternalNotifications::SETTING, ['estudio_aprobado' => ['303']], 1);
    $id = $this->create()['id'];
    $result = $this->approve($id);
    self::assertGreaterThan(0, $result['caso_entrega']);
    self::assertGreaterThan(0, $result['contrato']);

    $study = $this->row('jet_cct_estudios_aseguradoras', $id);
    self::assertSame(['Aprobado', 'Por entregar', '9001', '13000'], [$study['estado'], $study['estado_contrato'], $study['id_inmueble'], $study['id_inmueble_data']]);
    self::assertSame((string) $result['caso_entrega'], $study['id_ticket_entrega']);
    self::assertSame((string) $result['contrato'], $study['id_contrato_mandato_arrendamiento']);
    self::assertSame('101', $study['id_empleado'], 'Quien responde no reemplaza a quien hizo el estudio.');
    self::assertSame('321 699 9529', $study['contacto_llaves']);

    $delivery = $this->row('jet_cct_tickets', $result['caso_entrega']);
    self::assertSame('404', $delivery['id_empleado'], 'El responsable de las entregas de Configuración.');
    self::assertSame('Entrega de inmuebles', $delivery['tema_ayuda']);
    self::assertSame('REVISION ANTES DE LA OCUPACION', $delivery['asunto']);
    self::assertSame(['Nuevo', 'Nuevo'], [$delivery['estado'], $delivery['estado_administrativo']]);
    self::assertSame('Ana Propietaria', $delivery['solicitante']);
    self::assertSame('Consultora Uno', $delivery['consultor_entrega']);
    self::assertSame((string) $result['contrato'], $delivery['id_contrato']);
    self::assertSame('https://drive.google.com/drive/folders/abc', $delivery['anexos_entrega']);
    self::assertStringContainsString('Libia Entregas', $delivery['descripcion']);

    $contract = $this->row('jet_cct_contratos_arrendamiento', $result['contrato']);
    self::assertSame(['Por entregar', 'No asignado', 'El libertador', '12548532', '700', 'Finca Raíz'], [$contract['estado'], $contract['contrato'], $contract['aseguradora'], $contract['numero_solicitud'], $contract['id_ticket_cliente'], $contract['medio']]);
    self::assertSame(serialize(['Agua', 'Gas']), $contract['servicios_publicos'], 'Solo servicios del glosario.');

    $property = $this->row('jet_cct_inmuebles', 13000);
    self::assertSame(['En proceso de cierre', (string) $id, (string) $result['contrato']], [$property['estado'], $property['id_estudio_aseguradora'], $property['id_contrato_arrendamiento']]);
    self::assertSame('En proceso de cierre', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 9001 AND meta_key = 'estado'"));
    self::assertSame((string) $id, $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 9001 AND meta_key = 'id_estudio_aseguradora'"));
    self::assertSame('Aprobado', $this->row('jet_cct_tickets', 700)['estado_comercial']);

    $subjects = array_column($this->emails, 'subject');
    self::assertNotEmpty(array_filter($subjects, static fn(string $subject): bool => str_contains($subject, 'caso de entrega #' . $result['caso_entrega'])));
    $staff = $this->emails[array_key_first(array_filter($subjects, static fn(string $subject): bool => str_contains($subject, 'caso de entrega')))]['to'];
    self::assertSame(['404@example.test', '303@example.test'], $staff);
    self::assertContains('ana@example.test', array_column($this->emails, 'to'), 'El propietario recibe la revisión antes de la ocupación.');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('ya fue respondido');
    $this->approve($id);
  }

  public function testApprovalRejectsWrongPropertyAndMissingResponsible(): void
  {
    $id = $this->create()['id'];
    foreach (['9003' => 'solo se entregan', '9004' => 'no tiene propietario'] as $code => $message) {
      try {
        $this->approve($id, ['id_inmueble' => $code]);
        self::fail('Debió rechazar el inmueble ' . $code);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    self::assertSame(0, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tickets WHERE tema_ayuda = 'Entrega de inmuebles'"), 'Nada se crea si falla.');
    (new Settings($this->db))->set(CommercialStudiesService::SETTING, ['responsable_entrega' => ''], 1);
    try {
      $this->service()->respond($id, 700, ['estado' => 'Aprobado', 'id_inmueble' => '9001', 'ubicacion_llaves' => 'a', 'persona_llaves' => 'b', 'contacto_llaves' => '3001234567', 'fecha_posible_entrega' => '2026-12-15', 'link_drive' => 'https://drive.google.com/x']);
      self::fail('Sin responsable de entregas no se aprueba.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('Configuración', $exception->getMessage());
    }
    // Sin elegir, el responsable es quien atendió la última entrega de un estudio.
    $this->db->insert('wp_jet_cct_tickets', ['tema_ayuda' => 'Entrega de inmuebles', 'id_estudio_aseguradora' => '1', 'id_empleado' => '404']);
    self::assertSame('404', (string) ($this->service()->deliveryResponsible()['id_empleado'] ?? ''));
  }

  // ── Editar ──────────────────────────────────────────

  public function testUpdateCorrectsDataAndSyncsDeliveryCaseAndContract(): void
  {
    $id = $this->create()['id'];
    $approved = $this->approve($id);
    $result = $this->service()->update($id, 700, ['aseguradora' => 'Sura', 'num_solicitud' => 'NUEVA-1', 'valor' => '1500000', 'cliente' => 'Laura Cliente', 'email' => 'laura@example.test', 'celular' => '3001234567']);
    self::assertStringContainsString('aseguradora, número de solicitud', $result['message']);
    self::assertSame('Sura', $this->row('jet_cct_estudios_aseguradoras', $id)['aseguradora']);
    self::assertSame(['Sura', 'NUEVA-1'], [$this->row('jet_cct_contratos_arrendamiento', $approved['contrato'])['aseguradora'], $this->row('jet_cct_contratos_arrendamiento', $approved['contrato'])['numero_solicitud']]);
    self::assertSame('NUEVA-1', $this->row('jet_cct_tickets', $approved['caso_entrega'])['numero_solicitud']);
    self::assertStringContainsString('No hubo cambios', $this->service()->update($id, 700, ['aseguradora' => 'Sura', 'num_solicitud' => 'NUEVA-1', 'valor' => '1500000', 'cliente' => 'Laura Cliente', 'email' => 'laura@example.test', 'celular' => '3001234567'])['message']);
  }

  // ── Desistir ────────────────────────────────────────

  public function testDesistReplicatesClosingSheetDesist(): void
  {
    (new Settings($this->db))->set(CommercialInternalNotifications::SETTING, ['estudio_negocio_desistido' => ['303']], 1);
    $id = $this->create()['id'];
    $approved = $this->approve($id);
    $this->emails = [];
    try {
      $this->service()->desist($id, ['motivo' => 'Arrendatario desistio']);
      self::fail('Pide confirmación.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Confirma', $exception->getMessage());
    }
    $this->service()->desist($id, ['motivo' => 'Arrendatario desistio', 'observacion' => 'Se fue del país', 'confirmar' => '1']);

    $contract = $this->row('jet_cct_contratos_arrendamiento', $approved['contrato']);
    self::assertSame(['Desistido', 'Arrendatario desistio'], [$contract['estado'], $contract['razon_desistido']]);
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Contrato desistido'"));
    self::assertSame('En borrador', $this->row('jet_cct_inmuebles', 13000)['estado']);
    self::assertSame('En borrador', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 9001 AND meta_key = 'estado'"));
    $study = $this->row('jet_cct_estudios_aseguradoras', $id);
    self::assertSame(['Aprobado', 'Desistido'], [$study['estado'], $study['estado_contrato']]);
    $delivery = $this->row('jet_cct_tickets', $approved['caso_entrega']);
    self::assertSame(['Cerrado', 'Desistido'], [$delivery['estado'], $delivery['estado_administrativo']]);
    $task = $this->row('jet_cct_tickets', 700);
    self::assertSame(['Desistido', 'Cerrado', 'Pendiente'], [$task['estado_comercial'], $task['estado'], $task['estado_administrativo']]);
    self::assertSame(['101@example.test', '404@example.test', '303@example.test'], $this->emails[0]['to'], 'Quien hizo el estudio, el responsable de la entrega y Notificaciones internas.');
    self::assertSame('Negocio desistido', CommercialStudiesService::listState($study['estado'], $study['estado_contrato']));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('ya está desistido');
    $this->service()->desist($id, ['motivo' => 'Arrendatario desistio', 'confirmar' => '1']);
  }

  public function testChangeOfPropertyReopensStudyAndContractWithNumberBlocksDesist(): void
  {
    $id = $this->create()['id'];
    $approved = $this->approve($id);
    $this->service()->desist($id, ['motivo' => 'Cambio de inmueble', 'confirmar' => '1']);
    $study = $this->row('jet_cct_estudios_aseguradoras', $id);
    self::assertSame(['Esperando respuesta', ''], [$study['estado'], $study['estado_contrato']]);
    self::assertSame('En estudio', $this->row('jet_cct_tickets', 700)['estado_comercial']);
    // Se aprueba con otro inmueble.
    $again = $this->approve($id, ['id_inmueble' => '9002']);
    self::assertNotSame($approved['caso_entrega'], $again['caso_entrega']);
    $this->db->update('wp_jet_cct_contratos_arrendamiento', ['contrato' => '1450'], ['_ID' => $again['contrato']]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Marcar desistido');
    $this->service()->desist($id, ['motivo' => 'Arrendatario desistio', 'confirmar' => '1']);
  }

  // ── Listado y alcance ──────────────────────────────

  public function testListingScopeStateTabsAndStudyAccess(): void
  {
    $own = $this->create()['id'];
    $this->login('202', '9');
    $other = $this->service()->create(701, ['aseguradora' => 'Sura', 'num_solicitud' => '1', 'valor' => '900000', 'cliente' => 'Pedro', 'celular' => '3009998888']);
    $listing = $this->service()->listing([]);
    self::assertSame([$other['id']], array_column($listing['rows'], 'id'), 'El cargo 9 ve solo los suyos.');
    try {
      $this->service()->studyFor($own);
      self::fail('No puede abrir el estudio de otro sin la tarea.');
    } catch (\RuntimeException) {
      self::assertTrue(true);
    }
    $this->login('303', '13');
    $team = $this->service()->listing([]);
    self::assertSame(2, $team['total']);
    self::assertSame(['Esperando respuesta' => 2], $team['estados']);
    self::assertSame(1, $this->service()->listing(['es_aseguradora' => 'Sura'])['total']);
    $mine = $this->service()->listing(['portafolio' => '1']);
    self::assertSame(0, $mine['total'], 'Mis estudios es solo lo de la persona, también para administradores.');
    self::assertTrue($mine['mine']);
    self::assertSame(0, $this->service()->listing(['es_estado' => 'Aprobado'])['total']);
  }

  // ── Vistas ──────────────────────────────────────────

  public function testPublicViewHidesInternalDataAndStaffViewShowsIt(): void
  {
    $id = $this->create()['id'];
    $approved = $this->approve($id);
    $public = $this->service()->viewData($id);
    self::assertNull($public['interno']);
    self::assertSame('Aprobado', $public['estado_label']);
    self::assertSame('15/12/2026', $public['fecha_entrega']);
    $html = PublicStudyView::render($public, 'public-study.css', '');
    self::assertStringContainsString('Su consultor/a asignado es', $html);
    self::assertStringContainsString('Construimos confianza, protegemos su patrimonio.', $html);
    self::assertStringNotContainsString('Portería', $html, 'El cliente no ve las llaves.');
    self::assertStringNotContainsString('Ana Propietaria', $html);
    $staff = PublicStudyView::render($this->service()->viewData($id, true), 'public-study.css', '');
    self::assertStringContainsString('Portería', $staff);
    self::assertStringContainsString('#' . $approved['caso_entrega'], $staff);
    self::assertStringContainsString('drive.google.com', $staff);
    self::assertStringContainsString('no es válido', PublicStudyView::render(null, 'x.css', '', 'Este enlace no es válido o venció.'));
    self::assertSame(0, PublicStudyAccess::verify($id . '.9999999999.' . str_repeat('a', 64)));
  }

  public function testTaskPanelShowsActionsByPermissionAndState(): void
  {
    $id = $this->create()['id'];
    (new Settings($this->db))->set(CommercialStudiesService::SETTING, ['responsable_entrega' => '404'], 1);
    $html = CommercialStudiesView::taskPanel($this->service()->formData(700));
    self::assertStringContainsString('Libia Entregas', $html, 'La respuesta dice quién recibe el caso de entrega.');
    self::assertStringContainsString('data-cst-toggle="respond-' . $id . '"', $html);
    self::assertStringContainsString('data-cst-toggle="edit-' . $id . '"', $html);
    self::assertStringNotContainsString('data-cst-toggle="desist-' . $id . '"', $html, 'Solo se desiste un estudio aprobado.');
    self::assertStringContainsString('data-cst-form="create"', $html);
    $this->approve($id);
    $html = CommercialStudiesView::taskPanel($this->service()->formData(700));
    self::assertStringNotContainsString('data-cst-toggle="respond-' . $id . '"', $html);
    self::assertStringContainsString('data-cst-toggle="desist-' . $id . '"', $html);

    $this->login('707', '7');
    $this->expectException(\RuntimeException::class);
    $this->service()->formData(700);
  }

  public function testSettingsAcceptOnlyActiveEmployees(): void
  {
    $settings = new Settings($this->db);
    $repository = new CommercialStudiesRepository($this->db);
    CommercialStudiesService::saveSettings($settings, $repository, ['responsable_entrega' => '404'], 1);
    self::assertSame('404', CommercialStudiesService::settingsFrom($settings)['responsable_entrega']);
    $this->expectException(\InvalidArgumentException::class);
    CommercialStudiesService::saveSettings($settings, $repository, ['responsable_entrega' => '505'], 1);
  }
}
