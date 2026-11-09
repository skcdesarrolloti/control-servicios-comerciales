<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\Calendar\CalendarMailer;
use SCM\Commercial\Calendar\CalendarStore;
use SCM\Commercial\Calendar\CommercialCalendarApi;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\SharedNotificationsBridge;

final class CommercialCalendarApiTest extends TestCase
{
  private const MINE_ONLY = ['9' => ['views' => ['calendario', 'abiertos'], 'actions' => [], 'subviews' => ['calendario' => ['mine']]]];
  private const TEAM = ['9' => ['views' => ['calendario', 'abiertos'], 'actions' => [], 'subviews' => ['calendario' => ['mine', 'team']]]];

  private array $previousSession;
  private Database $db;
  private Settings $settings;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** @param array<string,mixed> $permissions @param array<string,mixed> $saved */
  private function api(string $cargo, string $employee, array $permissions = [], array $saved = []): CommercialCalendarApi
  {
    $_SESSION = ['scm_user_cargo' => $cargo, 'scm_user_id' => 7, 'scm_employee_id' => $employee, 'scm_user' => 'Prueba'];
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    $pdo->exec("ATTACH ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    $schemas = [
      'wp_jet_cct_confi_sistema' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT',
      'wp_jet_cct_funcionarios' => "_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, correo TEXT, correo_google TEXT, celular TEXT, id_cargo TEXT, id_sucursal TEXT, activo TEXT",
      'categorias_calendario' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, color TEXT, descripcion TEXT, roles_notificar TEXT',
      'calendario_actividades' => "id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT, descripcion TEXT, fecha_inicio TEXT, fecha_fin TEXT, id_empleado TEXT, creado_por TEXT, id_categoria INTEGER, id_ticket INTEGER, origen_app TEXT, external_ref TEXT, google_event_id TEXT, meta_json TEXT, estado TEXT DEFAULT 'No', observacion TEXT, ubicacion TEXT, fue_trasladado TEXT DEFAULT 'No'",
      'calendario_recordatorios' => "id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT, mensaje TEXT, recordatorio_at TEXT, canal TEXT, estado TEXT, id_empleado TEXT, creado_por TEXT, id_evento INTEGER, id_tarea INTEGER, id_ticket INTEGER, id_categoria INTEGER, notification_queue_id INTEGER, origen_app TEXT, external_ref TEXT, google_event_id TEXT, meta_json TEXT",
      'registro_bloqueos' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, id_empleado TEXT, cantidad_pendientes INTEGER, fecha_registro TEXT',
      'wp_jet_cct_tickets' => '_ID INTEGER PRIMARY KEY, id_ticket TEXT, estado TEXT, estado_comercial TEXT, estado_administrativo TEXT, solicitante TEXT, correo_solicitante TEXT, id_empleado TEXT, nombre_empleado TEXT, tema_ayuda TEXT, fecha_actualizacion INTEGER, cct_modified TEXT',
      'wp_jet_cct_historial_del_ticket' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_ticket INTEGER, fecha INTEGER, nombre TEXT, correo TEXT, celular TEXT, respuesta TEXT, id_empleado TEXT, id_evento INTEGER, fue_editada TEXT',
      'wp_jet_cct_reportes_comerciales' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, id_inmueble TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, valor INTEGER, funcionario TEXT, id_empleado TEXT, id_ticket TEXT, id_cotizacion TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, sucursal TEXT, fecha_realizado INTEGER, fue_pagado TEXT, id_contrato TEXT, exportado TEXT, id_evento TEXT',
      'wp_jet_cct_historial_del_inmueble' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, inmuebles_cotizados TEXT, id_empleado TEXT, id_inmueble TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, funcionario TEXT, id_ticket TEXT, id_inmueble_data TEXT',
      'wp_jet_cct_cotizacion_inmuebles' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, id_ticket INTEGER, inmuebles TEXT',
      'wp_jet_cct_inmuebles' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, codigo TEXT, direccion TEXT, id_contrato_arrendamiento TEXT',
      'wp_jet_cct_cargos' => '_ID INTEGER PRIMARY KEY, nombre_cargo TEXT',
      'wp_options' => 'option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT, autoload TEXT',
      'wp_posts' => 'ID INTEGER PRIMARY KEY, post_title TEXT, post_type TEXT, post_status TEXT',
      'wp_postmeta' => 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT',
    ];
    foreach ($schemas as $table => $schema) {
      $pdo->exec("CREATE TABLE `{$table}` ({$schema})");
      $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
      foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
      }
    }
    $this->db = new Database($pdo);
    $this->db->insert('wp_jet_cct_confi_sistema', ['funcion' => Settings::FUNCTION_KEY, 'valor' => json_encode(['commercial_permissions' => $permissions, 'commercial_admin_cargos' => ['13'], 'commercial_calendar_cargos' => ['9']] + $saved, JSON_THROW_ON_ERROR)]);
    foreach ([['calendario_reportes_comerciales_cargos', '[9]'], ['valor_transporte', '4000']] as [$key, $value]) {
      $this->db->insert('wp_jet_cct_confi_sistema', ['funcion' => $key, 'valor' => $value, 'cct_status' => 'publish']);
    }
    foreach ([[1, '100', 'Ana', 'ana@skc.test', '9'], [2, '200', 'Luis', 'luis@skc.test', '9'], [3, '300', 'Gerencia', 'g@skc.test', '20']] as [$pk, $id, $name, $mail, $cargo]) {
      $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => $pk, 'id_empleado' => $id, 'nombre' => $name, 'correo' => $mail, 'celular' => '300', 'id_cargo' => $cargo, 'id_sucursal' => '1', 'activo' => 'Si']);
    }
    $this->db->insert('categorias_calendario', ['id' => 3, 'nombre' => 'Cita con cliente', 'color' => '#D50B8B']);
    $this->db->insert('categorias_calendario', ['id' => 8, 'nombre' => 'Mantenimiento', 'color' => '#0D4715']);
    $this->db->insert('categorias_calendario', ['id' => 50, 'nombre' => 'Sin usar', 'color' => '#000000']);
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 5, 'id_ticket' => '777', 'estado' => 'Nuevo', 'estado_comercial' => 'Contactado', 'estado_administrativo' => 'Sin novedad', 'solicitante' => 'Laura', 'correo_solicitante' => 'l@test.co', 'id_empleado' => '100', 'nombre_empleado' => 'Ana', 'tema_ayuda' => 'Captacion']);
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 6, 'id_ticket' => '778', 'estado' => 'Nuevo', 'estado_comercial' => 'Contactado', 'estado_administrativo' => 'Por inspeccionar', 'solicitante' => 'Pedro', 'id_empleado' => '200', 'nombre_empleado' => 'Luis', 'tema_ayuda' => 'Avaluo']);
    $future = date('Y-m-d', strtotime('+3 days'));
    $this->db->insert('calendario_actividades', ['id' => 10, 'titulo' => 'Visita de Ana', 'descripcion' => 'Datos privados', 'ubicacion' => 'Manga', 'fecha_inicio' => $future . ' 09:00:00', 'fecha_fin' => $future . ' 10:00:00', 'id_empleado' => '100', 'id_categoria' => 3]);
    $this->db->insert('calendario_actividades', ['id' => 11, 'titulo' => 'Visita de Luis', 'descripcion' => 'Secreto de Luis', 'ubicacion' => 'Crespo', 'fecha_inicio' => $future . ' 11:00:00', 'fecha_fin' => $future . ' 12:00:00', 'id_empleado' => '200', 'id_categoria' => 3, 'id_ticket' => 6]);
    $this->db->insert('calendario_actividades', ['id' => 12, 'titulo' => 'Cita médica', 'fecha_inicio' => $future . ' 15:00:00', 'fecha_fin' => $future . ' 16:00:00', 'id_empleado' => '300', 'id_categoria' => 8]);
    $this->settings = new Settings($this->db);
    $store = new CalendarStore($this->db);
    $mailer = new CalendarMailer($store, [], [], new SharedNotificationsBridge($this->db, 'C:/no-existe'));
    return new CommercialCalendarApi($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), ['calendar_allowed_cargos' => ['9']], $mailer);
  }

  /** @return array<string,mixed> */
  private static function event(array $overrides = []): array
  {
    $day = date('Y-m-d', strtotime('+5 days'));
    return $overrides + ['titulo' => 'Visita', 'fecha_inicio' => $day . ' 09:00:00', 'fecha_fin' => $day . ' 10:00:00', 'id_empleado' => '100', 'id_categoria' => 3, 'ubicacion' => 'Manga'];
  }

  public function testGoogleCalendarIsNotAvailable(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    self::assertSame(400, $api->handle('iniciar_google_oauth', ['id_empleado' => '100'])['status']);
    self::assertSame(400, $api->handle('estado_google_oauth', ['id_empleado' => '100'])['status']);
    $created = $api->handle('crear_evento', self::event(['sincronizar_google' => '1']));
    self::assertSame(['id'], array_keys($created['data']));
  }

  public function testRequiresCalendarPermission(): void
  {
    $result = $this->api('9', '100', ['9' => ['views' => ['abiertos'], 'actions' => []]])->handle('listar_categorias', []);
    self::assertFalse($result['success']);
    self::assertSame(403, $result['status']);
  }

  public function testWithoutTeamOnlySeesOwnAgendaAndOthersAsBusy(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $own = $api->handle('listar_items_calendario', []);
    self::assertSame(['Visita de Ana'], array_column($own['data'], 'titulo'));

    $colleague = $api->handle('filtrar_eventos_admin', ['id_empleado' => '200']);
    self::assertSame('Ocupado', $colleague['data']['data'][0]['titulo']);
    self::assertArrayNotHasKey('descripcion', $colleague['data']['data'][0]);
    self::assertArrayNotHasKey('id_ticket', $colleague['data']['data'][0]);
    self::assertStringNotContainsString('Secreto', (string) json_encode($colleague));

    self::assertSame([], $api->handle('filtrar_eventos_admin', ['id_empleado' => '300'])['data']['data']);
    self::assertSame([], $api->handle('listar_pendientes_vencidos', ['id_empleado' => '200'])['data']);
  }

  public function testTeamSeesOnlyCalendarEmployees(): void
  {
    $titles = array_column($this->api('9', '100', self::TEAM)->handle('listar_items_calendario', [])['data'], 'titulo');
    self::assertSame(['Visita de Ana', 'Visita de Luis'], $titles);
  }

  public function testUnknownPersonSeesNothing(): void
  {
    $_SESSION['scm_user_id'] = 0;
    $api = $this->api('9', '', self::MINE_ONLY);
    $this->db->update('wp_jet_cct_funcionarios', ['id_empleado' => ''], ['_ID' => 1]);
    $_SESSION['scm_user_id'] = 999;
    self::assertSame([], $api->handle('listar_items_calendario', [])['data']);
  }

  public function testLinkingTaskKeepsStatusesAndLeavesHistoryWithRealEmployee(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $result = $api->handle('crear_evento', self::event(['id_ticket' => '5', 'es_cita' => 'si', 'estado_administrativo' => 'Por inspeccionar']));
    self::assertTrue($result['success'], $result['message']);
    $ticket = $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 5');
    self::assertSame('Sin novedad', $ticket['estado_administrativo']);
    self::assertSame('Contactado', $ticket['estado_comercial']);
    self::assertSame('777', $ticket['id_ticket']);
    self::assertSame('En proceso', $ticket['estado']);
    $history = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = 5');
    self::assertSame('100', $history['cct_author_id']);
    self::assertSame((int) $result['data']['id'], (int) $history['id_evento']);
    $event = $this->db->getRow('SELECT * FROM calendario_actividades WHERE id = ?', [$result['data']['id']]);
    self::assertSame('100', $event['creado_por']);
    self::assertSame('control-servicios-comerciales', $event['origen_app']);
  }

  public function testClosedTaskStaysClosedAndStatusNeedsPermission(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->update('wp_jet_cct_tickets', ['estado' => 'Cerrado'], ['_ID' => 5]);
    $api->handle('crear_evento', self::event(['id_ticket' => '5', 'estado_comercial' => 'Nuevo']));
    $ticket = $this->db->getRow('SELECT estado, estado_comercial FROM wp_jet_cct_tickets WHERE _ID = 5');
    self::assertSame('Cerrado', $ticket['estado']);
    self::assertSame('Contactado', $ticket['estado_comercial'], 'Sin permiso de cambiar estado no se modifica.');
  }

  public function testOnlyCommercialCategoriesCanBeUsed(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY, ['commercial_calendar_categories' => ['3']]);
    $denied = $api->handle('crear_evento', self::event(['id_categoria' => 8]));
    self::assertFalse($denied['success']);
    self::assertStringContainsString('no está habilitada', $denied['message']);
    self::assertTrue($api->handle('crear_evento', self::event())['success']);
  }

  public function testAssigningToColleagueNeedsTeamOrTheirVisibleTask(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    self::assertSame(403, $api->handle('crear_evento', self::event(['id_empleado' => '200']))['status']);
    self::assertTrue($api->handle('crear_evento', self::event(['id_empleado' => '200', 'id_ticket' => '5']))['success']);
    self::assertSame(403, $api->handle('crear_evento', self::event(['id_ticket' => '6']))['status'], 'La tarea es de otro funcionario.');
    self::assertSame(403, $api->handle('crear_evento', self::event(['id_empleado' => '300']))['status'], 'No está en los cargos del calendario.');
    self::assertSame(403, $api->handle('crear_eventos', ['eventos' => [self::event()], 'empleados' => ['100', '200']])['status']);
    self::assertTrue($this->api('9', '100', self::TEAM)->handle('crear_evento', self::event(['id_empleado' => '200']))['success']);
  }

  public function testOverdueEventsBlockUntilConfirmedAndAreLogged(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->insert('calendario_actividades', ['titulo' => 'Vieja', 'fecha_inicio' => '2026-01-01 09:00:00', 'fecha_fin' => '2026-01-01 10:00:00', 'id_empleado' => '100', 'id_categoria' => 3]);
    $blocked = $api->handle('crear_evento', self::event());
    self::assertFalse($blocked['success']);
    self::assertTrue($blocked['data']['bloqueado_vencidos']);
    self::assertSame(1, $blocked['data']['cantidad']);
    self::assertTrue($api->handle('crear_evento', self::event(['permitir_vencidos' => '1']))['success']);
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM registro_bloqueos'));
  }

  public function testToCloseListsOnlyEndedEventsStillInTime(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $at = static fn(string $modify): string => (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->modify($modify)->format('Y-m-d H:i:s');
    $add = fn(int $id, string $title, string $end, string $status = 'No', string $employee = '100') => $this->db->insert('calendario_actividades', ['id' => $id, 'titulo' => $title, 'fecha_inicio' => $end, 'fecha_fin' => $end, 'id_empleado' => $employee, 'id_categoria' => 3, 'estado' => $status]);
    $add(30, 'Termino hace una hora', $at('-1 hour'));
    $add(31, 'Vencido hace tres dias', $at('-3 days'));
    $add(32, 'Todavia no termina', $at('+1 hour'));
    $add(33, 'Ya realizado', $at('-1 hour'), 'Si');
    $add(34, 'De otro funcionario', $at('-1 hour'), 'No', '200');

    self::assertSame(['Termino hace una hora'], array_column($api->handle('listar_por_cerrar', ['id_empleado' => '100'])['data'], 'titulo'));
    self::assertSame(['Vencido hace tres dias'], array_column($api->handle('listar_pendientes_vencidos', ['id_empleado' => '100'])['data'], 'titulo'), 'Las dos listas no se mezclan.');
    self::assertSame([], $api->handle('listar_por_cerrar', ['id_empleado' => '200'])['data'], 'De un compañero solo se ve ocupado o libre.');

    $team = $this->api('9', '100', self::TEAM);
    $add(34, 'De otro funcionario', $at('-1 hour'), 'No', '200');
    self::assertSame(['De otro funcionario'], array_column($team->handle('listar_por_cerrar', ['id_empleado' => '200'])['data'], 'titulo'), 'Con permiso de equipo sí se ve.');
  }

  public function testCannotChangeSomeoneElsesEvent(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    self::assertSame(403, $api->handle('actualizar_evento', ['id' => 11, 'titulo' => 'x', 'id_categoria' => 3])['status']);
    self::assertSame(403, $api->handle('cambiar_estado', ['id_evento' => 11, 'observacion' => 'x'])['status']);
    self::assertSame(403, $api->handle('trasladar_evento', ['id_evento' => 11, 'fecha_inicio' => '2026-12-01 09:00', 'fecha_fin' => '2026-12-01 10:00', 'observacion' => 'x'])['status']);
    self::assertSame('Visita de Luis', $this->db->getVar('SELECT titulo FROM calendario_actividades WHERE id = 11'));
  }

  public function testMoveEventRequiresValidRange(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    self::assertFalse($api->handle('trasladar_evento', ['id_evento' => 10, 'fecha_inicio' => '2026-12-01 10:00', 'fecha_fin' => '2026-12-01 09:00', 'observacion' => 'x'])['success']);
    self::assertTrue($api->handle('trasladar_evento', ['id_evento' => 10, 'fecha_inicio' => '2026-12-01 09:00', 'fecha_fin' => '2026-12-01 10:00', 'observacion' => 'x'])['success']);
    self::assertSame('Si', $this->db->getVar('SELECT fue_trasladado FROM calendario_actividades WHERE id = 10'));
  }

  public function testCommercialReportIsRequiredAndClosesTheEvent(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->insert('wp_posts', ['ID' => 4501, 'post_title' => 'Apartamento Manga', 'post_type' => 'inmuebles', 'post_status' => 'publish']);

    $missing = $api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Listo']);
    self::assertFalse($missing['success']);
    self::assertTrue($missing['data']['requiere_reporte']);

    $prepared = $api->handle('preparar_reporte_comercial', ['id_evento' => 10]);
    self::assertTrue($prepared['data']['habilitado']);
    self::assertSame('Visita', $prepared['data']['tipo_predeterminado']);
    self::assertSame(8000, $prepared['data']['valor_maximo']);

    $unanswered = $api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Listo', 'reporte' => ['tipo_reporte' => 'Visita', 'id_inmueble' => '4501']]);
    self::assertStringContainsString('cobrar transporte', $unanswered['message']);
    self::assertFalse($api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Listo', 'reporte' => ['tipo_reporte' => 'Visita', 'transporte' => 'no', 'id_inmueble' => '']])['success'], 'Falta el inmueble');
    self::assertSame('No', $this->db->getVar('SELECT estado FROM calendario_actividades WHERE id = 10'));

    $found = $api->handle('buscar_inmuebles_reporte', ['id_evento' => 10, 'termino' => 'Manga']);
    self::assertSame('4501', $found['data'][0]['id']);
    self::assertArrayNotHasKey('correo_propietario', $found['data'][0]);

    $done = $api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Visita hecha', 'reporte' => ['tipo_reporte' => 'Visita', 'transporte' => 'ida_vuelta', 'valor' => 999999, 'id_inmueble' => '4501']]);
    self::assertTrue($done['success'], $done['message']);
    self::assertSame('Si', $this->db->getVar('SELECT estado FROM calendario_actividades WHERE id = 10'));
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_reportes_comerciales WHERE id_evento = '10'"));
    self::assertSame(8000, (int) $this->db->getVar("SELECT valor FROM wp_jet_cct_reportes_comerciales WHERE id_evento = '10'"), 'El valor sale de la configuración, no del navegador.');
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = 4501'));
  }

  /** @return array<int,array{0:string,1:int}> */
  public static function transportChoices(): array
  {
    return [['no', 0], ['ida', 4000], ['ida_vuelta', 8000]];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('transportChoices')]
  public function testTransportChargeComesFromSharedConfiguration(string $choice, int $expected): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->insert('wp_posts', ['ID' => 4501, 'post_title' => 'Apartamento', 'post_type' => 'inmuebles', 'post_status' => 'publish']);
    self::assertTrue($api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Hecho', 'reporte' => ['tipo_reporte' => 'Visita', 'transporte' => $choice, 'id_inmueble' => '4501']])['success']);
    self::assertSame($expected, (int) $this->db->getVar('SELECT valor FROM wp_jet_cct_reportes_comerciales'));
  }

  public function testTransportCannotBeChargedWithoutConfiguredValue(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->insert('wp_posts', ['ID' => 4501, 'post_title' => 'Apartamento', 'post_type' => 'inmuebles', 'post_status' => 'publish']);
    $this->db->update('wp_jet_cct_confi_sistema', ['valor' => '0'], ['funcion' => 'valor_transporte']);
    $result = $api->handle('cambiar_estado', ['id_evento' => 10, 'observacion' => 'Hecho', 'reporte' => ['tipo_reporte' => 'Visita', 'transporte' => 'ida', 'id_inmueble' => '4501']]);
    self::assertStringContainsString('no está configurado', $result['message']);
    self::assertSame('No', $this->db->getVar('SELECT estado FROM calendario_actividades WHERE id = 10'));
  }

  public function testEventWithoutReportClosesNormally(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $this->db->insert('calendario_actividades', ['id' => 20, 'titulo' => 'Mantenimiento', 'fecha_inicio' => '2026-12-01 09:00:00', 'fecha_fin' => '2026-12-01 10:00:00', 'id_empleado' => '100', 'id_categoria' => 8]);
    self::assertFalse($api->handle('preparar_reporte_comercial', ['id_evento' => 20])['data']['habilitado']);
    self::assertTrue($api->handle('cambiar_estado', ['id_evento' => 20, 'observacion' => 'Hecho'])['success']);
    self::assertSame('Hecho', $this->db->getVar('SELECT observacion FROM calendario_actividades WHERE id = 20'));
  }

  public function testReminderLifecycle(): void
  {
    $api = $this->api('9', '100', self::MINE_ONLY);
    $at = date('Y-m-d', strtotime('+2 days')) . ' 08:00:00';
    $created = $api->handle('crear_item_calendario', ['tipo_item' => 'recordatorio', 'titulo' => 'Llamar', 'recordatorio_at' => $at, 'id_empleado' => '100', 'recordatorio_canal' => 'whatsapp', 'aviso_minutos_antes' => 15]);
    self::assertTrue($created['success'], $created['message']);
    self::assertSame(15, $created['data']['meta']['aviso_minutos_antes']);
    self::assertSame('Recordatorio creado.', $created['message']);
    $id = (int) $created['data']['id'];
    self::assertTrue($api->handle('actualizar_recordatorio', ['id_recordatorio' => $id, 'titulo' => 'Llamar hoy', 'recordatorio_at' => $at])['success']);
    self::assertSame('Llamar hoy', $this->db->getVar('SELECT titulo FROM calendario_recordatorios WHERE id = ?', [$id]));
    self::assertTrue($api->handle('actualizar_recordatorio_estado', ['id_recordatorio' => $id, 'estado' => 'cancelado'])['success']);
    self::assertSame('cancelado', $this->db->getVar('SELECT estado FROM calendario_recordatorios WHERE id = ?', [$id]));
    self::assertFalse($api->handle('crear_item_calendario', ['tipo_item' => 'tarea', 'titulo' => 'x', 'id_empleado' => '100'])['success']);
  }

  public function testSettingsManageCategoriesAndNeverDeleteOnesWithEvents(): void
  {
    $this->api('13', '300');
    $service = new CommercialSettingsService($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), []);
    $base = ['cargo_ids' => ['9'], 'category_ids' => ['3'], 'available_category_ids' => ['3', '8', '50']];

    try {
      $service->save('calendario', $base + ['deleted_category_ids' => ['3'], 'categories' => []]);
      self::fail('Una categoría con eventos no se elimina.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('tiene eventos', $exception->getMessage());
    }
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM calendario_actividades WHERE id = 10'));

    $service->save('calendario', $base + [
      'deleted_category_ids' => ['50'],
      'categories' => [
        ['id' => '3', 'nombre' => 'Cita con cliente', 'color' => '#112233', 'comercial' => true],
        ['id' => '', 'nombre' => 'Feria', 'color' => '#445566', 'comercial' => true],
      ],
      'notifications' => ['funcionario_email' => true],
      'report_cargo_ids' => ['9', '13'],
      'cita_category_id' => '3',
    ]);
    self::assertSame('#112233', $this->db->getVar('SELECT color FROM categorias_calendario WHERE id = 3'));
    self::assertNull($this->db->getVar('SELECT id FROM categorias_calendario WHERE id = 50'));
    $feria = (string) $this->db->getVar("SELECT id FROM categorias_calendario WHERE nombre = 'Feria'");
    $loaded = $service->load('calendario')['calendario'];
    self::assertSame(['3', $feria], $loaded['category_ids']);
    self::assertFalse($loaded['notifications']['whatsapp_citas']);
    self::assertSame(['9', '13'], $loaded['report_cargo_ids']);
    self::assertSame('3', $loaded['cita_category_id']);

    $this->expectException(\InvalidArgumentException::class);
    $service->save('calendario', $base + ['categories' => [['id' => '', 'nombre' => 'feria', 'color' => '#000000']]]);
  }

  public function testNonAdminCannotConfigure(): void
  {
    $this->api('9', '100', self::TEAM);
    $service = new CommercialSettingsService($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), []);
    $this->expectException(\RuntimeException::class);
    $service->save('calendario', ['cargo_ids' => ['9'], 'categories' => []]);
  }
}
