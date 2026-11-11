<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Calls\CommercialCallsService;
use SCM\Commercial\Calls\PublicTaskConversation;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Support\PublicTaskAccess;
use SCM\Views\PublicTaskView;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialCallsView;
use Tests\Fixtures\CommercialCallsDatabase;

final class CommercialCallsTest extends TestCase
{
  private Database $db;
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialCallsDatabase.php';
    if (!defined('SCM_APP_SECRET')) define('SCM_APP_SECRET', 'secreto-de-prueba-de-al-menos-32-caracteres');
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://panel.test/public');
    $this->db = CommercialCallsDatabase::create();
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

  private function service(?CommercialCallsNotifier $notifier = null): CommercialCallsService
  {
    return new CommercialCallsService($this->db, new Settings($this->db), CommercialCallsDatabase::policy($this->db), [], $notifier ?? new CommercialCallsNotifier($this->db, new Settings($this->db), static function (): void {}));
  }

  private function row(int $id): array
  {
    return $this->db->getRow('SELECT * FROM wp_jet_cct_cct_llamadas WHERE _ID = ?', [$id]) ?? [];
  }

  private function create(array $override = []): int
  {
    return $this->service()->save(CommercialCallsDatabase::validInput($override))['id'];
  }

  private function taskInput(int $id, array $override = []): array
  {
    return $override + ['id' => (string) $id, 'solicitante' => 'Laura Prueba', 'correo_solicitante' => 'laura@example.test', 'celular_solicitante' => '3001234567', 'prioridad' => 'Prioridad comercial', 'tema_ayuda' => 'Arriendo', 'asunto' => 'Busca arriendo', 'descripcion' => 'Detalle'];
  }

  public function testRegisteredInPermissionsAndRouter(): void
  {
    self::assertArrayHasKey('llamadas', CommercialAccessPolicy::VIEWS);
    self::assertContains('llamadas', CommercialAccessPolicy::VIEW_GROUPS['Actividades comerciales']);
    self::assertArrayHasKey('llamadas', CommercialAccessPolicy::SCOPED_VIEWS);
    foreach (['llamadas_crear', 'llamadas_editar', 'llamadas_tarea'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
    }
    self::assertSame('save', CommercialActionRouter::CALL_ACTIONS['commercial_calls_save']);
  }

  public function testCreateSavesMedioAndAuthorAsEmployeeId(): void
  {
    $call = $this->row($this->create());
    self::assertSame('Referido', $call['medio']);
    self::assertSame('101', $call['cct_author_id']);
    self::assertSame('3001234567', $call['celular']);
    self::assertSame('1', $call['sucursal']);
  }

  public function testCreateRequiresMedio(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('medio');
    $this->create(['medio' => '']);
  }

  public function testEditNeverChangesMedioNorDate(): void
  {
    $id = $this->create();
    $before = $this->row($id);
    $this->service()->save(CommercialCallsDatabase::validInput(['id' => (string) $id, 'medio' => 'Aviso', 'fecha' => '2020-01-01', 'cliente' => 'Laura Editada']));
    $after = $this->row($id);
    self::assertSame('Referido', $after['medio']);
    self::assertSame($before['fecha'], $after['fecha']);
    self::assertSame('Laura Editada', $after['cliente']);
  }

  public function testEditOnlyChangesPersonalData(): void
  {
    $id = $this->create(['tema_ayuda' => 'Arriendo', 'presupuesto' => '1.000.000', 'descripcion' => 'Original']);
    $before = $this->row($id);
    $result = $this->service()->save(CommercialCallsDatabase::validInput(['id' => (string) $id, 'tema_ayuda' => 'Venta', 'presupuesto' => '9', 'descripcion' => 'Cambio', 'merece_ticket' => 'No', 'id_empleado' => '202', 'comisionista' => 'Si', 'cliente' => 'Nombre Corregido', 'email' => 'nuevo@example.test', 'usuario_wsp' => 'nuevo']));
    $after = $this->row($id);
    foreach (['tema_ayuda', 'presupuesto', 'descripcion', 'merece_ticket', 'id_empleado', 'comisionista', 'medio'] as $field) {
      self::assertSame($before[$field], $after[$field], $field);
    }
    self::assertSame('Nombre Corregido', $after['cliente']);
    self::assertSame('nuevo@example.test', $after['email']);
    self::assertSame('nuevo', $after['usuario_wsp']);
    self::assertSame('list', $result['next'], 'Corregir datos personales no abre la tarea.');
  }

  public function testOldCallCanFixContactWithoutMissingEmailCountryOrPartner(): void
  {
    $this->db->insert('wp_jet_cct_cct_llamadas', ['_ID' => 60, 'id_empleado' => '101', 'cct_author_id' => '101', 'cliente' => 'Vieja', 'celular' => '3001110000', 'medio' => 'Club PPH', 'email' => '', 'indicativo' => '']);
    $this->service()->save(['id' => '60', 'cliente' => 'Vieja Corregida', 'celular' => '3001110000', 'email' => '', 'indicativo' => '']);
    self::assertSame('Vieja Corregida', $this->row(60)['cliente']);
  }

  public function testFieldsDependOnTopicAndAmountsAreDigits(): void
  {
    $rent = $this->row($this->create(['tema_ayuda' => 'Arriendo', 'presupuesto' => '1.500.000', 'precio' => '9.000', 'id_inmueble' => '45']));
    self::assertSame('1500000', $rent['presupuesto']);
    self::assertSame('', $rent['precio']);
    self::assertSame('45', $rent['id_inmueble']);
    $listing = $this->row($this->create(['tema_ayuda' => 'Captacion', 'celular' => '3003334444', 'presupuesto' => '1.000', 'precio' => '250.000.000', 'id_inmueble' => '45']));
    self::assertSame('250000000', $listing['precio']);
    self::assertSame('', $listing['presupuesto']);
    self::assertSame('', $listing['id_inmueble']);
    self::assertSame('1.500.000', CommercialCallsService::money('1500000.00'));
    self::assertSame('1.500', CommercialCallsService::money('1.500'));
  }

  public function testMotivesStopBeforeDeliveryAndSkipUpdate(): void
  {
    $values = ['Avaluo', 'Actualizacion', 'Captacion', 'Arriendo', 'Venta', 'Entrega de inmuebles', 'Mantenimiento', 'Retoque'];
    $this->db->update('wp_jet_post_types', ['meta_fields' => serialize(array_map(static fn(string $value): array => ['value' => $value, 'label' => $value, 'is_checked' => false], $values))], ['id' => 816]);
    self::assertSame(['Avaluo', 'Captacion', 'Arriendo', 'Venta'], $this->service()->options()['temas']);
    foreach (['Actualizacion', 'Entrega de inmuebles', 'Retoque'] as $removed) {
      try {
        $this->create(['tema_ayuda' => $removed, 'celular' => '3002220000']);
        self::fail('No debía aceptar ' . $removed);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString('motivo', $exception->getMessage());
      }
    }
    $this->db->insert('wp_jet_cct_cct_llamadas', ['_ID' => 70, 'id_empleado' => '101', 'cct_author_id' => '101', 'cliente' => 'Antigua', 'celular' => '3002221111', 'medio' => 'Aviso', 'merece_ticket' => 'Si', 'tema_ayuda' => 'Actualizacion']);
    $service = $this->service();
    self::assertStringContainsString('value="Actualizacion" selected', CommercialCallsView::taskForm($service->taskData(70), $service->options()));
  }

  public function testGroupsCommissionAgentsAndLaterClients(): void
  {
    $agent = $this->create(['comisionista' => 'Si', 'celular' => '3001000001']);
    $later = $this->create(['cliente_inmediato' => 'No', 'mes_inmueble' => 'Febrero', 'celular' => '3001000002']);
    $service = $this->service();
    $counts = $service->listing([])['counts'];
    self::assertSame(1, $counts['comisionistas']);
    self::assertSame(1, $counts['mas_adelante']);
    self::assertSame([$agent], array_column($service->listing(['ll_comisionista' => 'Si'])['rows'], 'id'));
    self::assertSame([$later], array_column($service->listing(['ll_inmediato' => 'No', 'll_mes' => 'Febrero'])['rows'], 'id'));
    $html = CommercialCallsView::results($service->listing([]), $service, CommercialCallsDatabase::policy($this->db));
    self::assertStringContainsString('Para Febrero', $html);
    self::assertStringContainsString('>Comisionista<', $html);
  }

  public function testOldCallWithoutMedioCanBeCompletedOnce(): void
  {
    $this->db->insert('wp_jet_cct_cct_llamadas', ['_ID' => 50, 'id_empleado' => '101', 'cct_author_id' => '101', 'cliente' => 'Antigua', 'celular' => '3005556666', 'medio' => '']);
    $this->service()->save(CommercialCallsDatabase::validInput(['id' => '50', 'celular' => '3005556666', 'medio' => 'Aviso']));
    self::assertSame('Aviso', $this->row(50)['medio']);
    $this->service()->save(CommercialCallsDatabase::validInput(['id' => '50', 'celular' => '3005556666', 'medio' => 'Referido']));
    self::assertSame('Aviso', $this->row(50)['medio']);
  }

  public function testClubPphRequiresActiveUniqueCardAndStoresPartner(): void
  {
    foreach (['' => 'tarjeta', 'NO-EXISTE' => 'No encontramos', 'TAR-2' => 'inactivo', 'TAR-3' => 'varios'] as $card => $message) {
      try {
        $this->create(['medio' => 'Club PPH', 'tarjeta_pph' => (string) $card]);
        self::fail('Debía rechazar la tarjeta ' . $card);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    $call = $this->row($this->create(['medio' => 'Club PPH', 'tarjeta_pph' => ' tar-1 ']));
    self::assertSame('1', $call['id_pph']);
    self::assertSame('TAR-1', $call['tarjeta_pph']);
    self::assertSame('Aliada Uno', $call['nombre_pph']);
  }

  public function testNewRequestKeepsOwnMedioAndContactHistoryGroupsThem(): void
  {
    $first = $this->create();
    $this->db->update('wp_jet_cct_cct_llamadas', ['cct_created' => '2020-01-01 00:00:00'], ['_ID' => $first]);
    $second = $this->create(['medio' => 'Aviso', 'tema_ayuda' => 'Venta', 'celular' => '+57 300 123 4567']);
    self::assertNotSame($first, $second);
    self::assertSame('Referido', $this->row($first)['medio']);
    self::assertSame('Aviso', $this->row($second)['medio']);
    $history = $this->service()->history(['celular' => '3001234567']);
    self::assertCount(2, $history['items']);
    self::assertEqualsCanonicalizing(['Referido', 'Aviso'], array_column($history['items'], 'medio'));
  }

  public function testRepeatFormBringsContactButNotMedio(): void
  {
    $id = $this->create();
    $data = $this->service()->formData(['desde_llamada' => (string) $id]);
    self::assertSame('repeat', $data['mode']);
    self::assertSame('Laura Prueba', $data['call']['cliente']);
    self::assertArrayNotHasKey('medio', $data['call']);
  }

  public function testDoubleClickDoesNotDuplicate(): void
  {
    $this->create();
    $this->expectException(\InvalidArgumentException::class);
    $this->create();
  }

  public function testTaskInheritsMedioAndPartnerAndCannotRepeat(): void
  {
    $id = $this->create(['medio' => 'Club PPH', 'tarjeta_pph' => 'TAR-1']);
    $result = $this->service()->createTask($this->taskInput($id, ['medio' => 'Aviso', 'id_pph' => '999']));
    $task = $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = ?', [$result['id']]);
    self::assertSame('Club PPH', $task['medio']);
    self::assertSame('1', $task['id_pph']);
    self::assertSame('TAR-1', $task['tarjeta_pph']);
    self::assertSame('Aliada Uno', $task['nombre_pph']);
    self::assertSame('No', $task['bandera'], 'Sin sumar todavía: así lo reconoce el proceso de puntos.');
    self::assertSame('Nuevo', $task['estado_comercial']);
    self::assertSame('101', $task['cct_author_id']);
    self::assertSame((string) $id, $task['id_llamada']);
    self::assertSame((string) $result['id'], $this->row($id)['id_ticket']);
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->createTask($this->taskInput($id));
  }

  public function testTaskRequiresCallThatDeservesIt(): void
  {
    $id = $this->create(['merece_ticket' => 'No']);
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->createTask($this->taskInput($id));
  }

  public function testSaveOffersTaskOnlyWhenItDeservesOne(): void
  {
    self::assertSame('task', $this->service()->save(CommercialCallsDatabase::validInput())['next']);
    self::assertSame('list', $this->service()->save(CommercialCallsDatabase::validInput(['merece_ticket' => 'No', 'celular' => '3007777777']))['next']);
  }

  public function testCreatesClientForArriendoVentaOnly(): void
  {
    $id = $this->create();
    $client = $this->db->getRow('SELECT * FROM wp_jet_cct_clientes');
    self::assertSame('Cliente para arriendo', $client['tipo_cliente']);
    self::assertSame((string) $client['_ID'], $this->row($id)['id_cliente']);
    $this->create(['tema_ayuda' => 'Captacion', 'celular' => '3008888888']);
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_clientes'));
  }

  public function testScopeOwnSeesOnlyAssignedOrRegistered(): void
  {
    $mine = $this->create();
    $this->login('202', '9');
    $other = $this->create(['id_empleado' => '202', 'celular' => '3002223333']);
    $registeredForFirst = $this->create(['id_empleado' => '101', 'celular' => '3004445555']);
    $rows = $this->service()->listing([]);
    self::assertEqualsCanonicalizing([$other, $registeredForFirst], array_column($rows['rows'], 'id'));
    try {
      $this->service()->formData(['id' => (string) $mine]);
      self::fail('No debía abrir una llamada ajena.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('otro funcionario', $exception->getMessage());
    }
  }

  public function testUnknownPersonSeesNothing(): void
  {
    $this->create();
    $this->login('', '9');
    $_SESSION['scm_user_id'] = 0;
    self::assertSame([], $this->service()->listing([])['rows']);
  }

  public function testTeamScopeSeesEverythingButNeedsActions(): void
  {
    $id = $this->create();
    $this->login('404', '6');
    self::assertCount(1, $this->service()->listing([])['rows']);
    self::assertFalse($this->service()->listing([])['rows'][0]['editable']);
    $this->expectException(\RuntimeException::class);
    $this->service()->save(CommercialCallsDatabase::validInput(['id' => (string) $id]));
  }

  public function testHistoryHidesDetailsOfOtherAdvisors(): void
  {
    $this->create(['descripcion' => 'Detalle reservado', 'cliente' => 'Nombre reservado']);
    $this->login('202', '9');
    $history = $this->service()->history(['celular' => '3001234567']);
    self::assertSame(1, count($history['items']));
    self::assertSame('Referido', $history['items'][0]['medio']);
    self::assertSame('', $history['items'][0]['descripcion']);
    self::assertSame([], $history['contact']);
    self::assertStringNotContainsString('reservado', CommercialCallsView::history($history, CommercialCallsDatabase::policy($this->db)));
  }

  public function testHistoryIncludesTasksWithoutCall(): void
  {
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 77, 'celular_solicitante' => '573001234567', 'tema_ayuda' => 'Venta', 'medio' => 'Portal cliente', 'estado_comercial' => 'Nuevo', 'fecha' => time()]);
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 78, 'celular_solicitante' => '3001234567', 'tema_ayuda' => 'Plomería', 'medio' => 'Otro', 'fecha' => time()]);
    $history = $this->service()->history(['celular' => '3001234567']);
    self::assertSame([77], array_column($history['items'], 'id'));
    self::assertSame('Tarea sin llamada', $history['items'][0]['tipo']);
  }

  public function testFilters(): void
  {
    $this->create();
    $partner = $this->create(['medio' => 'Club PPH', 'tarjeta_pph' => 'TAR-1', 'celular' => '3006667777', 'cliente' => 'Otra Persona']);
    $service = $this->service();
    self::assertSame([$partner], array_column($service->listing(['ll_tarjeta' => 'TAR-1'])['rows'], 'id'));
    self::assertSame([$partner], array_column($service->listing(['ll_medio' => 'Club PPH'])['rows'], 'id'));
    self::assertSame([$partner], array_column($service->listing(['ll_q' => 'Otra'])['rows'], 'id'));
    self::assertSame(2, $service->listing([])['counts']['pendientes']);
    self::assertSame(1, $service->listing([])['counts']['pph']);
    self::assertSame([], $service->listing(['ll_hasta' => '2020-01-01'])['rows']);
  }

  public function testEditFormLocksMedioAndNewFormRequiresIt(): void
  {
    $service = $this->service();
    $id = $this->create();
    $html = CommercialCallsView::form($service->formData(['id' => (string) $id]), $service->options());
    self::assertStringContainsString('Medio: Referido', $html);
    self::assertStringNotContainsString('name="medio"', $html);
    self::assertStringNotContainsString('name="tema_ayuda"', $html);
    self::assertStringNotContainsString('name="id_empleado"', $html);
    self::assertStringContainsString('data-ccl-repeat', $html);
    $new = CommercialCallsView::form($service->formData([]), $service->options());
    self::assertStringContainsString('name="medio"', $new);
    self::assertStringContainsString('data-ccl-pph hidden', $new);
    self::assertStringNotContainsString('name="observacion"', $new);
    self::assertSame('', (string) ($this->row($this->create(['observacion' => 'Interesado', 'celular' => '3009990000']))['observacion'] ?? ''));
  }

  public function testPageEscapesValuesAndShowsFilters(): void
  {
    $this->db->insert('wp_jet_cct_cct_llamadas', ['id_empleado' => '101', 'cct_author_id' => '101', 'cliente' => '<script>x</script>', 'celular' => '3001112222', 'medio' => 'Aviso', 'fecha' => time()]);
    $html = CommercialCallsView::page([], $this->service(), CommercialCallsDatabase::policy($this->db));
    self::assertStringNotContainsString('<script>x', $html);
    self::assertStringContainsString('&lt;script&gt;', $html);
    self::assertStringContainsString('data-ccl-filters', $html);
    self::assertStringContainsString('data-ccl-new', $html);
  }

  /** @return array{0:CommercialCallsNotifier,1:object} */
  private function recorder(array $settings = []): array
  {
    $log = new \stdClass();
    $log->mail = [];
    $log->whatsapp = [];
    if ($settings !== []) {
      (new Settings($this->db))->set(CommercialCallsNotifier::SETTING, $settings, 1);
    }
    $notifier = new CommercialCallsNotifier($this->db, new Settings($this->db),
      static function ($to, string $subject, string $html) use ($log): void { $log->mail[] = [$to, $subject, $html]; },
      static function (string $phone, string $template, array $components) use ($log): void { $log->whatsapp[] = [$phone, $template, $components]; });
    return [$notifier, $log];
  }

  private function taskFromCall(?CommercialCallsNotifier $notifier = null): int
  {
    return $this->service($notifier)->createTask($this->taskInput($this->create()))['id'];
  }

  private function setInternal(string $event, array $ids): void
  {
    $internal = new CommercialInternalNotifications($this->db, new Settings($this->db));
    $internal->save([$event => $ids]);
  }

  public function testWhatsappIsOnByDefaultAndClientGetsSignedLink(): void
  {
    [$notifier, $log] = $this->recorder();
    $this->taskFromCall($notifier);
    self::assertSame(['scm_tarea_comercial_asignada_v1', 'scm_tarea_comercial_registrada_v1'], array_column($log->whatsapp, 1), 'El WhatsApp viene activo por defecto.');
    [$notifier, $log] = $this->recorder(['whatsapp_asignado' => false, 'whatsapp_solicitante' => false]);
    $this->service($notifier)->createTask($this->taskInput($this->create(['celular' => '3007770000'])));
    self::assertSame([], $log->whatsapp, 'Apagado en Configuración no sale.');
    $client = array_values(array_filter($log->mail, static fn(array $mail): bool => $mail[0] === 'laura@example.test'))[0];
    self::assertStringContainsString('tarea.php?t=', $client[2]);

    [$notifier, $log] = $this->recorder(['whatsapp_asignado' => true, 'whatsapp_solicitante' => true]);
    $id = $this->service($notifier)->createTask($this->taskInput($this->create(['celular' => '3005551212']), ['celular_solicitante' => '3005551212']))['id'];
    self::assertSame(['+573000000101', '+573005551212'], [$this->phoneOf($log->whatsapp[0]), $this->phoneOf($log->whatsapp[1])]);
    self::assertSame('scm_tarea_comercial_asignada_v1', $log->whatsapp[0][1]);
    self::assertSame('scm_tarea_comercial_registrada_v1', $log->whatsapp[1][1]);
    self::assertSame($id, PublicTaskAccess::verify(end($log->whatsapp[1][2])['parameters'][0]['text']));
  }

  private function phoneOf(array $whatsapp): string
  {
    return $whatsapp[0];
  }

  public function testClientWhatsappHeaderUsesTheAssigneeCard(): void
  {
    $this->db->update('wp_jet_cct_funcionarios', ['ficha' => 'https://panel.test/public/file.php?n=ficha.jpg&s=abc'], ['id_empleado' => '101']);
    [$notifier, $log] = $this->recorder();
    $this->taskFromCall($notifier);
    $client = array_values(array_filter($log->whatsapp, static fn(array $item): bool => $item[1] === 'scm_tarea_comercial_registrada_v1'))[0];
    self::assertSame(['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://panel.test/public/file.php?n=ficha.jpg&s=abc']]]], $client[2][0]);
    $staff = array_values(array_filter($log->whatsapp, static fn(array $item): bool => $item[1] === 'scm_tarea_comercial_asignada_v1'))[0];
    self::assertSame('body', $staff[2][0]['type'], 'La plantilla del funcionario no lleva imagen.');
  }

  public function testClientWhatsappHeaderFallsBackWhenNoCard(): void
  {
    [$notifier, $log] = $this->recorder();
    $this->taskFromCall($notifier);
    $client = array_values(array_filter($log->whatsapp, static fn(array $item): bool => $item[1] === 'scm_tarea_comercial_registrada_v1'))[0];
    self::assertSame('header', $client[2][0]['type']);
    self::assertNotSame('', $client[2][0]['parameters'][0]['image']['link'], 'Sin ficha va una imagen de respaldo.');
  }

  public function testStaffCardsSaveRemoveAndPermissions(): void
  {
    self::assertSame('Fichas de funcionarios', CommercialAccessPolicy::SUBVIEWS['promocion']['fichas_funcionarios']);
    self::assertContains('fichas_gestionar', CommercialAccessPolicy::OPT_IN_ACTIONS);
    self::assertSame('staffCardSave', CommercialActionRouter::PROMOTION_ACTIONS['commercial_staff_card_save']);
    $files = new class extends \SCM\Commercial\Promotion\Pop\PopFiles {
      public function count(string $field): int { return 1; }
      public function store(string $field, int $max): array { return ['https://panel.test/public/file.php?n=nueva.jpg&s=1']; }
    };
    $service = new \SCM\Commercial\Promotion\StaffCardService($this->db, $files);
    self::assertTrue($service->available());
    $service->save('101');
    self::assertSame('https://panel.test/public/file.php?n=nueva.jpg&s=1', $service->cardOf('101'));
    $service->remove('101');
    self::assertSame('', $service->cardOf('101'));
    $this->db->update('wp_jet_cct_funcionarios', ['activo' => 'No'], ['id_empleado' => '202']);
    $this->expectException(\InvalidArgumentException::class);
    $service->save('202');
  }

  public function testStaffCardsViewHidesManagementWithoutPermission(): void
  {
    $this->db->update('wp_jet_cct_funcionarios', ['ficha' => 'https://panel.test/f.jpg'], ['id_empleado' => '101']);
    $html = \SCM\Views\Promotion\StaffCardsView::render(\SCM\Views\Promotion\StaffCardsView::context(CommercialCallsDatabase::policy($this->db), $this->db), 'https://panel.test/public');
    self::assertStringContainsString('Ficha de Consultora Uno', $html);
    self::assertStringNotContainsString('data-staff-card-form', $html, 'El cargo 9 no tiene fichas_gestionar.');
    $this->login('303', '13');
    $html = \SCM\Views\Promotion\StaffCardsView::render(\SCM\Views\Promotion\StaffCardsView::context(CommercialCallsDatabase::policy($this->db), $this->db), 'https://panel.test/public');
    self::assertStringContainsString('data-staff-card-form', $html);
    self::assertStringContainsString('data-staff-card-remove="101"', $html);
  }

  public function testClientBlockedChannelsAreRespected(): void
  {
    $this->db->insert('wp_jet_cct_clientes', ['nombre' => 'Laura', 'celular' => '3001234567', 'bloqueo_whatsapp' => '1', 'bloqueo_email' => '1']);
    [$notifier, $log] = $this->recorder(['whatsapp_solicitante' => true]);
    $this->taskFromCall($notifier);
    self::assertSame(['+573000000101'], array_column($log->whatsapp, 0), 'Al cliente que bloqueó WhatsApp no le llega; al funcionario sí.');
    self::assertSame([], array_values(array_filter($log->mail, static fn(array $mail): bool => $mail[0] === 'laura@example.test')));
  }

  public function testPublicLinkRejectsTamperingAndExpiry(): void
  {
    $reference = PublicTaskAccess::reference(7);
    self::assertSame(7, PublicTaskAccess::verify($reference));
    self::assertSame(0, PublicTaskAccess::verify('8' . substr($reference, 1)));
    self::assertSame(0, PublicTaskAccess::verify(PublicTaskAccess::reference(7, time() - 10)));
    self::assertSame(0, PublicTaskAccess::verify('7'));
  }

  public function testClientReplyReachesAssigneeCopiesAndTaskHistory(): void
  {
    [$notifier, $log] = $this->recorder(['whatsapp_asignado' => true]);
    $id = $this->taskFromCall($notifier);
    $this->setInternal('llamada_respuesta_cliente', ['303', '101']);
    $log->mail = [];
    $log->whatsapp = [];
    [$notifier, $log] = $this->recorder();
    $conversation = new PublicTaskConversation($this->db, $notifier);
    $conversation->clientReply($id, "Quisiera <b>visitar</b>\r\nel sábado");
    self::assertSame(['101@example.test', '303@example.test'], $log->mail[0][0], 'Responsable y copia, sin repetir al responsable.');
    self::assertStringContainsString('visitar', $log->mail[0][2]);
    $history = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = ?', [$id]);
    self::assertSame('cliente', $history['id_empleado']);
    self::assertSame("Quisiera visitar\nel sábado", $history['respuesta']);
    $conversation->clientReply($id, "Quisiera <b>visitar</b>\r\nel sábado");
    self::assertCount(1, $conversation->messages($id), 'Un doble envío no se repite.');
    self::assertCount(1, $log->mail);
  }

  public function testClientCannotReplyToClosedOrEmpty(): void
  {
    [$notifier] = $this->recorder();
    $id = $this->taskFromCall($notifier);
    $conversation = new PublicTaskConversation($this->db, $notifier);
    foreach (['   ', str_repeat('a', 2001)] as $bad) {
      try {
        $conversation->clientReply($id, $bad);
        self::fail('Debía rechazar el mensaje.');
      } catch (\InvalidArgumentException) {
        self::assertSame([], $conversation->messages($id));
      }
    }
    $closed = CommercialStatusCatalog::statusesForBucket('cerrados')[0];
    $this->db->update('wp_jet_cct_tickets', ['estado_comercial' => $closed], ['_ID' => $id]);
    $this->expectException(\InvalidArgumentException::class);
    $conversation->clientReply($id, 'Hola');
  }

  public function testPublicViewShowsOnlySharedConversationEscaped(): void
  {
    [$notifier, $log] = $this->recorder(['whatsapp_solicitante' => true]);
    $id = $this->taskFromCall($notifier);
    $conversation = new PublicTaskConversation($this->db, $notifier);
    $this->db->update('wp_jet_cct_tickets', ['descripcion' => 'Dato interno reservado'], ['_ID' => $id]);
    $log->whatsapp = [];
    $conversation->officerReply($id, 'Te espero <script>alert(1)</script> el lunes', 'Consultora Uno', '101');
    self::assertSame('scm_tarea_comercial_respuesta_funcionario_v1', $log->whatsapp[0][1]);
    $html = PublicTaskView::render($conversation->task($id), $conversation->messages($id), 'tarea.php?t=x', 'a.css', 'logo.png');
    self::assertStringContainsString('Te espero', $html);
    self::assertStringNotContainsString('<script>', $html);
    self::assertStringNotContainsString('Dato interno reservado', $html);
    self::assertStringContainsString('name="mensaje"', $html);
    $this->db->update('wp_jet_cct_tickets', ['estado_comercial' => CommercialStatusCatalog::statusesForBucket('cerrados')[0]], ['_ID' => $id]);
    self::assertStringNotContainsString('name="mensaje"', PublicTaskView::render($conversation->task($id), [], 'x', 'a.css', 'logo.png'));
  }

  public function testInternalNotificationsKeepOnlyKnownEventsAndActiveStaff(): void
  {
    $this->db->update('wp_jet_cct_funcionarios', ['activo' => 'No'], ['id_empleado' => '202']);
    $internal = new CommercialInternalNotifications($this->db, new Settings($this->db));
    $internal->save(['llamada_tarea_creada' => ['303', '202', '303', 'x'], 'evento_inventado' => ['101']]);
    self::assertSame(['llamada_tarea_creada' => ['303', '202']], $internal->settings());
    self::assertSame(['303@example.test'], $internal->emails('llamada_tarea_creada'), 'Un funcionario inactivo no recibe.');
    self::assertSame([], $internal->emails('precaptacion_creada'));
  }

  public function testTemplateSettingsAreValidated(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    CommercialCallsNotifier::validated(['plantilla_asignada' => 'Plantilla Mala'], []);
  }

  public function testNotifierUsesConfiguredRecipients(): void
  {
    $sent = [];
    $queue = static function ($to, string $subject) use (&$sent): void { $sent[] = [$to, $subject]; };
    (new Settings($this->db))->set(CommercialCallsNotifier::SETTING, ['asignado' => true, 'solicitante' => false, 'administradores' => true, 'admin_emails' => ['jefe@example.test']], 1);
    $id = $this->create();
    $this->service(new CommercialCallsNotifier($this->db, new Settings($this->db), $queue))->createTask($this->taskInput($id));
    self::assertSame(['101@example.test', ['jefe@example.test']], array_column($sent, 0), 'La lista anterior sigue activa hasta marcar funcionarios.');
    $this->setInternal('llamada_tarea_creada', ['303']);
    $sent = [];
    $id = $this->create(['celular' => '3001010101', 'tema_ayuda' => 'Venta']);
    $this->service(new CommercialCallsNotifier($this->db, new Settings($this->db), $queue))->createTask($this->taskInput($id, ['tema_ayuda' => 'Venta']));
    self::assertSame(['303@example.test'], $sent[1][0]);
    self::assertStringNotContainsString('ticket', strtolower($sent[0][1]));
  }
}
