<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\CommercialCalendarService;
use SCM\Commercial\CommercialReplyGuides;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Controller\CommercialApiController;
use SCM\Modules\ServiciosInmobiliarios\SeguimientoService;
use SCM\Support\PublicTaskAccess;
use SCM\Views\CommercialTicketModalView;
use Tests\Fixtures\CommercialCallsDatabase;

/**
 * Botones generales del popup de la tarea: guía de estados, respuestas guía, Postergar con fecha para retomar, Cerrar y
 * Reasignar con aviso, sin tocar estado_administrativo.
 */
final class CommercialTaskWorkflowTest extends TestCase
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
    foreach (['estado_administrativo', 'estado_admin', 'seguimientos', 'fecha_seguimiento', 'fecha_actualizacion', 'id_encargado_seguimiento', 'id_ticket', 'fecha_retomar'] as $column) {
      $this->db->pdo()->exec('ALTER TABLE wp_jet_cct_tickets ADD COLUMN `' . $column . '` TEXT');
    }
    $this->db->pdo()->exec('CREATE TABLE wp_jet_cct_respuesta_de_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, categoria TEXT, estado TEXT, situacion TEXT, respuesta TEXT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT)');
    // Cronología: seguimientos y notas, y los campos de edición (bin/add-timeline-edit-fields.php) en las tres tablas.
    $this->db->pdo()->exec('CREATE TABLE wp_jet_cct_seguimiento_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, id_ticket TEXT, fecha INTEGER, nombre TEXT, observacion TEXT, id_empleado TEXT, id_coordinador TEXT, evidencia TEXT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, veces_editada TEXT, fecha_edicion INTEGER)');
    $this->db->pdo()->exec('CREATE TABLE wp_jet_cct_notas_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, id_ticket TEXT, fecha INTEGER, nombre TEXT, observacion TEXT, id_empleado TEXT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, veces_editada TEXT, fecha_edicion INTEGER)');
    $this->db->pdo()->exec('ALTER TABLE wp_jet_cct_historial_del_ticket ADD COLUMN veces_editada TEXT');
    $this->db->pdo()->exec('ALTER TABLE wp_jet_cct_historial_del_ticket ADD COLUMN fecha_edicion INTEGER');
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 900, 'id_ticket' => '900', 'asunto' => 'Busca apartamento', 'tema_ayuda' => 'Arriendo', 'estado' => 'En proceso', 'estado_comercial' => 'Mostrando', 'estado_administrativo' => 'En gestión', 'id_empleado' => '101', 'nombre_empleado' => 'Consultora Uno', 'correo_empleado' => '101@example.test', 'celular_empleado' => '3000000101', 'solicitante' => 'Laura Prueba', 'correo_solicitante' => 'laura@example.test', 'celular_solicitante' => '3001234567', 'indicativo' => '57']);
    $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => 101, 'scm_employee_id' => '101', 'scm_user_cargo' => '13', 'scm_user' => 'Consultora Uno'];
    $this->mirrorSchema();
  }

  /** SchemaInspector consulta information_schema (MySQL): se simula con las tablas y columnas de la base SQLite. */
  private function mirrorSchema(): void
  {
    $pdo = $this->db->pdo();
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    foreach ($pdo->query("SELECT name FROM main.sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
      $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
      foreach ($pdo->query('PRAGMA main.table_info(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
        $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
      }
    }
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function task(): array
  {
    return $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 900') ?? [];
  }

  /** @return array{0:CommercialCallsNotifier,1:object} */
  private function recorder(): array
  {
    $log = new \stdClass();
    $log->mail = [];
    $log->whatsapp = [];
    $notifier = new CommercialCallsNotifier($this->db, new Settings($this->db),
      static function ($to, string $subject, string $html) use ($log): void { $log->mail[] = [$to, $subject, $html]; },
      static function (string $phone, string $template, array $components) use ($log): void { $log->whatsapp[] = [$phone, $template, $components]; });
    return [$notifier, $log];
  }

  public function testPostponeActivateAndCloseKeepTheAdministrativeStatus(): void
  {
    // El flujo compartido escribe con filterTableData (DESCRIBE de MySQL): aquí se prueba la parte que decide qué se escribe.
    $update = new \ReflectionMethod(SeguimientoService::class, 'administrativeUpdate');
    $row = ['estado' => 'En proceso', 'estado_administrativo' => 'Postergado', 'estado_admin_ticket' => 'Postergado', 'estado_admin' => 'Postergado'];
    self::assertSame(['estado' => 'En proceso'], $update->invoke(null, $row, true), 'El panel comercial no toca el estado administrativo.');
    self::assertSame($row, $update->invoke(null, $row, false), 'Sin la opción (panel inmobiliario heredado) sigue igual.');
    foreach (['postponeTicket' => 5, 'activateTicket' => 4, 'closeTicket' => 2] as $method => $position) {
      $parameter = (new \ReflectionMethod(SeguimientoService::class, $method))->getParameters()[$position];
      self::assertSame('keepAdministrative', $parameter->getName());
      self::assertFalse($parameter->getDefaultValue());
    }
    // El controlador comercial pide conservarlo en las tres acciones.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CommercialApiController.php');
    self::assertSame(1, preg_match('/postponeTicket\([^;]*?uploadedDocuments\(\),\s*true\s*\)/s', $source));
    self::assertSame(1, preg_match('/activateTicket\([^;]*?uploadedDocuments\(\),\s*true\s*\)/s', $source));
    self::assertStringContainsString('closeTicket($ticketPk, $message, true)', $source);
  }

  public function testFollowUpNotifiesTheAssignedConsultantOnlyWhenSomeoneElseRegistersItPlusInternalCopies(): void
  {
    self::assertContains(CommercialCallsNotifier::EVENT_FOLLOW_UP, \SCM\Commercial\CommercialInternalNotifications::events());
    (new \SCM\Commercial\CommercialInternalNotifications($this->db, new Settings($this->db)))->save([CommercialCallsNotifier::EVENT_FOLLOW_UP => ['404', '303']]);

    // Lo registra la coordinación (303): al responsable (101) con su enlace firmado y la copia a 404; a 303 nada.
    [$notifier, $log] = $this->recorder();
    $notifier->followUpRegistered(900, $this->task(), 'Llamé y confirmó la visita', 'Coordinación', '303');
    self::assertCount(2, $log->mail);
    self::assertSame('101@example.test', $log->mail[0][0]);
    self::assertStringContainsString('Seguimiento en la tarea #900', $log->mail[0][1]);
    self::assertStringContainsString('ticket_id=900.', $log->mail[0][2], 'El responsable recibe su enlace individual.');
    self::assertStringContainsString('Llamé y confirmó la visita', $log->mail[0][2]);
    self::assertSame(['404@example.test'], $log->mail[1][0]);
    self::assertStringContainsString('abrir_tarea=900', $log->mail[1][2]);
    self::assertSame(['+573000000101', '+573000000404'], array_column($log->whatsapp, 0));
    self::assertSame([CommercialCallsNotifier::TEMPLATE_FOLLOW_UP], array_values(array_unique(array_column($log->whatsapp, 1))));
    self::assertSame(['Consultora Uno', 'Coordinación', '900', 'Laura Prueba', 'Llamé y confirmó la visita'], array_column($log->whatsapp[0][2][0]['parameters'], 'text'));

    // Lo registra el mismo responsable: él no recibe nada; las copias sí.
    [$notifier, $log] = $this->recorder();
    $notifier->followUpRegistered(900, $this->task(), 'Envié fotos', 'Consultora Uno', '101');
    self::assertSame([['303@example.test', '404@example.test']], array_map(static fn(array $mail) => $mail[0], $log->mail));
    self::assertNotContains('+573000000101', array_column($log->whatsapp, 0));
  }

  public function testOfficerReplyCopiesTheAssignedConsultantAndTheConfiguredStaff(): void
  {
    self::assertContains(CommercialCallsNotifier::EVENT_OFFICER_REPLY, \SCM\Commercial\CommercialInternalNotifications::events());
    (new \SCM\Commercial\CommercialInternalNotifications($this->db, new Settings($this->db)))->save([CommercialCallsNotifier::EVENT_OFFICER_REPLY => ['404', '303']]);

    // Responde la coordinación (303): el cliente, el responsable (101) con su enlace firmado y la copia a 404; a 303 nada.
    [$notifier, $log] = $this->recorder();
    $notifier->officerReplied(900, $this->task(), 'Te comparto la ficha del inmueble', 'Coordinación', '303');
    self::assertSame(['laura@example.test', '101@example.test', ['404@example.test']], array_column($log->mail, 0));
    self::assertStringContainsString('Respuesta enviada al cliente · tarea #900', $log->mail[1][1]);
    self::assertStringContainsString('ticket_id=900.', $log->mail[1][2], 'El responsable recibe su enlace individual.');
    self::assertStringContainsString('Te comparto la ficha del inmueble', $log->mail[2][2]);
    self::assertSame(['+573001234567', '+573000000101', '+573000000404'], array_column($log->whatsapp, 0));
    self::assertSame([CommercialCallsNotifier::TEMPLATE_OFFICER_REPLY, CommercialCallsNotifier::TEMPLATE_STAFF_MOVED, CommercialCallsNotifier::TEMPLATE_STAFF_MOVED], array_column($log->whatsapp, 1));
    self::assertSame(['Consultora Uno', 'Coordinación', '900', 'Laura Prueba', 'Respuesta enviada al cliente', 'Te comparto la ficha del inmueble'], array_column($log->whatsapp[1][2][0]['parameters'], 'text'));

    // Responde el mismo responsable: él no recibe copia; los marcados sí.
    [$notifier, $log] = $this->recorder();
    $notifier->officerReplied(900, $this->task(), 'Confirmo la visita', 'Consultora Uno', '101');
    self::assertSame(['laura@example.test', ['303@example.test', '404@example.test']], array_column($log->mail, 0));
    self::assertNotContains('+573000000101', array_column($log->whatsapp, 0));
  }

  public function testPostponeCloseAndReopenNotifyTheConfiguredStaff(): void
  {
    $events = [CommercialCallsNotifier::EVENT_POSTPONED, CommercialCallsNotifier::EVENT_CLOSED, CommercialCallsNotifier::EVENT_REOPENED];
    foreach ($events as $event) {
      self::assertContains($event, \SCM\Commercial\CommercialInternalNotifications::events(), 'Se configura en Notificaciones internas: ' . $event);
    }
    (new \SCM\Commercial\CommercialInternalNotifications($this->db, new Settings($this->db)))->save([CommercialCallsNotifier::EVENT_CLOSED => ['404']]);

    // La cierra la coordinación (303): al responsable con su enlace firmado y la copia a 404, por correo y WhatsApp.
    [$notifier, $log] = $this->recorder();
    $notifier->taskMoved(900, $this->task(), CommercialCallsNotifier::EVENT_CLOSED, 'Cerrada como Trasladado', 'Va en la tarea 901', 'Coordinación', '303');
    self::assertSame(['101@example.test', ['404@example.test']], array_column($log->mail, 0), 'Duplicado o Trasladado también se avisa a funcionarios.');
    self::assertStringContainsString('ticket_id=900.', $log->mail[0][2]);
    self::assertStringContainsString('Cerrada como Trasladado', $log->mail[0][1]);
    self::assertSame(['+573000000101', '+573000000404'], array_column($log->whatsapp, 0));
    self::assertSame([CommercialCallsNotifier::TEMPLATE_STAFF_MOVED], array_values(array_unique(array_column($log->whatsapp, 1))));
    self::assertSame(['Consultora Uno', 'Coordinación', '900', 'Laura Prueba', 'Cerrada como Trasladado', 'Va en la tarea 901'], array_column($log->whatsapp[0][2][0]['parameters'], 'text'));

    // La posterga el mismo responsable sin nadie marcado: nadie recibe aviso.
    [$notifier, $log] = $this->recorder();
    $notifier->taskMoved(900, $this->task(), CommercialCallsNotifier::EVENT_POSTPONED, 'Postergada hasta el lunes 7 de diciembre de 2026', 'Viaja', 'Consultora Uno', '101');
    self::assertSame([[], []], [$log->mail, $log->whatsapp]);

    // Reabrir: el cliente recibe el motivo y el contacto de su consultor/a.
    [$notifier, $log] = $this->recorder();
    $notifier->taskReopened(900, $this->task(), 'El propietario regresó', 'Consultora Uno', '101');
    self::assertSame(['laura@example.test'], array_column($log->mail, 0));
    self::assertSame('Retomamos tu tarea #900', $log->mail[0][1]);
    self::assertStringContainsString('tarea.php?t=900.', $log->mail[0][2]);
    self::assertSame([CommercialCallsNotifier::TEMPLATE_REOPENED], array_column($log->whatsapp, 1));
    self::assertSame(['Laura Prueba', '900', 'El propietario regresó', 'Consultora Uno · Correo: 101@example.test · Celular: 3000000101'], array_column($log->whatsapp[0][2][0]['parameters'], 'text'));
    self::assertSame(900, PublicTaskAccess::verify(end($log->whatsapp[0][2])['parameters'][0]['text']));

    // Las tres acciones del controlador avisan a funcionarios con su evento.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CommercialApiController.php');
    foreach (['EVENT_POSTPONED', 'EVENT_CLOSED', 'EVENT_REOPENED'] as $constant) {
      self::assertStringContainsString('$this->staffNotice($ticketPk, \SCM\Commercial\Calls\CommercialCallsNotifier::' . $constant, $source);
    }
  }

  public function testStatusChangeIsAnInternalNoteNotAClientReply(): void
  {
    $before = (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket');
    (new CommercialTicketsRepository($this->db))->changeStatus(900, 'Contactado');
    self::assertSame($before, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket'), 'No suma una respuesta al cliente.');
    $note = $this->db->getRow('SELECT * FROM wp_jet_cct_notas_ticket WHERE id_ticket = ?', ['900']);
    self::assertSame('Estado comercial actualizado de "Mostrando" a "Contactado".', $note['observacion']);
    self::assertSame(['101', '101'], [(string) $note['id_empleado'], (string) $note['cct_author_id']]);
  }

  public function testResumeDateIsStoredShownInTheCalendarAndCleared(): void
  {
    $tickets = new CommercialTicketsRepository($this->db);
    $tickets->changeStatus(900, 'Postergado');
    $resume = CommercialApiController::resumeDate((new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota')))->modify('+3 days')->format('Y-m-d'));
    self::assertNotNull($resume);
    $tickets->setResumeDate(900, $resume->getTimestamp());
    self::assertSame((string) $resume->getTimestamp(), (string) $this->task()['fecha_retomar']);
    $rows = $tickets->calendarPostponedTickets(['id_empleado' => '101']);
    self::assertSame(['900'], array_map('strval', array_column($rows, '_ID')));

    $calendar = new CommercialCalendarService($this->db, new Settings($this->db), CommercialCallsDatabase::policy($this->db), []);
    $due = $calendar->dueCalendar($resume->modify('-1 day')->format('Y-m-d'), $resume->modify('+1 day')->format('Y-m-d'));
    $items = array_values(array_filter($due['items'], static fn(array $item): bool => $item['tipo_vencimiento'] === 'tarea_retomar'));
    self::assertCount(1, $items, 'La tarea postergada sale el día que se retoma.');
    self::assertSame($resume->format('Y-m-d'), $items[0]['fecha_vencimiento']);
    self::assertStringContainsString('Retomar tarea #900', $items[0]['titulo']);

    $tickets->setResumeDate(900, null);
    self::assertSame([], $tickets->calendarPostponedTickets());

    // Salir de Postergado por cualquier camino (como Responder con un estado activo) borra la fecha; entre estados postergados, no.
    $tickets->setResumeDate(900, $resume->getTimestamp());
    $tickets->changeStatus(900, 'Aplazado');
    self::assertSame((string) $resume->getTimestamp(), (string) $this->task()['fecha_retomar']);
    $tickets->changeStatus(900, 'Contactado');
    self::assertEmpty($this->task()['fecha_retomar'], 'Activada a mano, el script de retomar ya no la toca.');
  }

  public function testPostponedTaskReturnsToNewWhenItsResumeDateArrives(): void
  {
    $tickets = new CommercialTicketsRepository($this->db);
    $tickets->changeStatus(900, 'Postergado');
    $this->db->update('wp_jet_cct_tickets', ['estado_administrativo' => 'Postergado'], ['_ID' => 900]);
    $at = static fn(string $local): int => (new \DateTimeImmutable($local, new \DateTimeZone('America/Bogota')))->getTimestamp();
    $tickets->setResumeDate(900, $at('2026-11-29 08:00')); // domingo

    [$notifier, $log] = $this->recorder();
    $resumer = new \SCM\Commercial\CommercialPostponedResumer($this->db, new Settings($this->db), $notifier);
    self::assertSame(0, $resumer->run($at('2026-11-28 10:00')), 'Antes de la fecha sigue postergada.');
    self::assertSame(0, $resumer->run($at('2026-11-29 09:00')), 'Domingo: no se retoma.');
    self::assertSame(0, $resumer->run($at('2026-11-30 06:59')), 'Antes de las 7:00 a. m. de Colombia, no.');
    self::assertSame('Postergado', $this->task()['estado_comercial']);
    self::assertFalse(\SCM\Commercial\CommercialPostponedResumer::isTime($at('2026-12-08 09:00')), 'Festivo (Inmaculada Concepción): no.');

    self::assertSame(1, $resumer->run($at('2026-11-30 07:00')), 'Lunes 7:00 a. m.: retoma la del domingo.');
    $task = $this->task();
    self::assertSame(['Nuevo', 'Nuevo', 'Postergado'], [$task['estado'], $task['estado_comercial'], $task['estado_administrativo']], 'No toca el estado administrativo.');
    self::assertEmpty($task['fecha_retomar']);
    self::assertStringContainsString('volvió a "Nuevo" (antes "Postergado"): se cumplió la fecha para retomarla (29/11/2026)', (string) $this->db->getVar("SELECT observacion FROM wp_jet_cct_notas_ticket WHERE id_ticket = '900' ORDER BY _ID DESC LIMIT 1"));
    self::assertCount(1, $log->mail);
    self::assertSame('101@example.test', $log->mail[0][0]);
    self::assertStringContainsString('ticket_id=900.', $log->mail[0][2], 'El responsable recibe su enlace individual.');
    self::assertSame(0, $resumer->run($at('2026-11-30 08:00')), 'Una sola vez.');
  }

  public function testResumedTaskButtonCanPointToWordPressWhileTasksAreNotEnabledInThePanel(): void
  {
    $task = ['id_ticket' => 'T-900', 'id_empleado' => '101'];
    putenv('TAREAS_WORDPRESS_URL=https://wp.example.test/tarea/?id_ticket=');
    try {
      self::assertSame('https://wp.example.test/tarea/?id_ticket=T-900', \SCM\Commercial\Calls\CommercialCallsNotifier::resumedTaskUrl(900, $task));
      self::assertSame('https://wp.example.test/tarea/?id_ticket=900', \SCM\Commercial\Calls\CommercialCallsNotifier::resumedTaskUrl(900, ['id_empleado' => '101']), 'Sin id_ticket, el número interno.');
    } finally {
      putenv('TAREAS_WORDPRESS_URL');
    }
    self::assertStringContainsString('ticket_id=900.', \SCM\Commercial\Calls\CommercialCallsNotifier::resumedTaskUrl(900, $task), 'Sin la variable, el enlace individual del panel.');
  }

  public function testPostponedTasksWithoutDateAreSpreadFromAMonthAhead(): void
  {
    $now = (new \DateTimeImmutable('2026-11-25 10:00', new \DateTimeZone('America/Bogota')))->getTimestamp();
    $days = \SCM\Commercial\CommercialPostponedScheduler::days($now);
    self::assertSame('2026-12-26 08:00', $days[0]->format('Y-m-d H:i'), 'Desde dentro de un mes (el 25 de diciembre es festivo), a las 8:00 a. m.');
    self::assertSame('2027-01-14', end($days)->format('Y-m-d'), 'Hasta dentro de 50 días.');
    foreach ($days as $day) {
      self::assertNotSame('7', $day->format('N'), 'Sin domingos.');
      self::assertNotContains($day->format('Y-m-d'), ['2026-12-25', '2027-01-01', '2027-01-11'], 'Sin festivos.');
      self::assertLessThanOrEqual(\SCM\Commercial\CommercialPostponedScheduler::TO_DAY, (int) (new \DateTimeImmutable('2026-11-25'))->diff($day)->days);
    }

    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
      $rows[] = ['_ID' => $i, 'id_empleado' => $i <= 52 ? '101' : '202', 'nombre_empleado' => $i <= 52 ? 'Consultora Uno' : 'Consultor Dos'];
    }
    $plan = \SCM\Commercial\CommercialPostponedScheduler::plan($rows, $now);
    self::assertCount(60, $plan['assign']);
    self::assertSame(15, $plan['days'], '21 días sin 3 domingos ni 3 festivos.');
    self::assertSame(['total' => 52, 'por_dia' => (int) ceil(52 / 15)], array_intersect_key($plan['people']['101'], ['total' => 0, 'por_dia' => 0]), 'Parejo entre los días.');
    self::assertEquals($days[0], $plan['assign'][1], 'La más antigua, primero.');
    self::assertLessThanOrEqual($plan['assign'][52], $plan['assign'][51]);
    self::assertSame(1, $plan['people']['202']['por_dia'], 'Pocas: una por día.');

    // Poner la fecha: solo a las que no tienen, con nota interna.
    $tickets = new CommercialTicketsRepository($this->db);
    $tickets->changeStatus(900, 'Postergado');
    self::assertSame(['900'], array_map('strval', array_column($tickets->postponedWithoutResumeDate(), '_ID')));
    self::assertTrue($tickets->scheduleResume(900, $days[3]->getTimestamp(), 'Se programó retomar la tarea el ' . $days[3]->format('d/m/Y') . '.'));
    self::assertFalse($tickets->scheduleResume(900, $days[5]->getTimestamp(), 'otra'), 'Ya tenía fecha: no la cambia.');
    self::assertSame((string) $days[3]->getTimestamp(), (string) $this->task()['fecha_retomar']);
    self::assertSame([], $tickets->postponedWithoutResumeDate());

    // Las que programó el script (su nota lo dice) se pueden volver a repartir; las de un consultor/a, no.
    self::assertSame([], $tickets->postponedScheduledBySystem(\SCM\Commercial\CommercialPostponedScheduler::NOTE_MARK), 'La nota de esta no es del script.');
    $tickets->setResumeDate(900, null);
    self::assertTrue($tickets->scheduleResume(900, $days[0]->getTimestamp(), 'Se programó retomar la tarea el ' . $days[0]->format('d/m/Y') . ': ' . \SCM\Commercial\CommercialPostponedScheduler::NOTE_MARK . '.'));
    self::assertSame(['900'], array_map('strval', array_column($tickets->postponedScheduledBySystem(\SCM\Commercial\CommercialPostponedScheduler::NOTE_MARK), '_ID')));
    self::assertTrue($tickets->scheduleResume(900, $days[9]->getTimestamp(), 'Se reprogramó.', true), 'Reprogramar sí la cambia.');
    self::assertSame((string) $days[9]->getTimestamp(), (string) $this->task()['fecha_retomar']);
  }

  public function testPostponedRowShowsUntilWhenInsteadOfAttentionDays(): void
  {
    $policy = CommercialCallsDatabase::policy($this->db);
    $row = static fn(array $extra): string => (string) (new \ReflectionMethod(\SCM\Views\CommercialDashboardView::class, 'renderTicketRow'))->invoke(null, $extra + ['_ID' => 900, 'id_ticket' => '900', 'estado_comercial' => 'Postergado', 'estado' => 'En proceso', 'scm_sla_status' => 'postergado', 'scm_attention_days' => 0, 'scm_sla_due_days' => 30], $policy);
    $zone = new \DateTimeZone('America/Bogota');
    $resume = (new \DateTimeImmutable('today', $zone))->modify('+12 days')->setTime(8, 0);
    $html = $row(['fecha_retomar' => $resume->getTimestamp()]);
    self::assertStringContainsString('Hasta ' . $resume->format('d/m/Y'), $html);
    self::assertStringContainsString('Faltan 12 días', $html);
    self::assertStringNotContainsString('0 / 30 días', $html);
    self::assertStringContainsString('Sin fecha para retomar', $row([]));
    self::assertStringContainsString('0 / 30 días', $row(['estado_comercial' => 'Nuevo', 'estado' => 'Nuevo', 'scm_sla_status' => 'a_tiempo']), 'Las abiertas siguen con su semáforo.');
  }

  public function testResumeDateIsAnyDateFromTomorrow(): void
  {
    $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
    self::assertNull(CommercialApiController::resumeDate($today->format('Y-m-d')), 'Hoy no: desde mañana.');
    self::assertNull(CommercialApiController::resumeDate($today->modify('-3 days')->format('Y-m-d')));
    self::assertNotNull(CommercialApiController::resumeDate($today->modify('+90 days')->format('Y-m-d')), 'Febrero o más adelante, sí.');
    self::assertNotNull(CommercialApiController::resumeDate($today->modify('+2 years')->format('Y-m-d')), 'Sin tope.');
    self::assertNull(CommercialApiController::resumeDate('2026-02-30'));
    self::assertNull(CommercialApiController::resumeDate(''));
    self::assertSame('08:00', CommercialApiController::resumeDate($today->modify('+1 day')->format('Y-m-d'))?->format('H:i'));
    self::assertSame('lunes 7 de diciembre de 2026', CommercialApiController::longDate(new \DateTimeImmutable('2026-12-07')));
  }

  public function testTasksTheScriptBroughtBackToNewCanBePostponedAgainIfNobodyTouchedThem(): void
  {
    $mark = \SCM\Commercial\CommercialPostponedScheduler::NOTE_MARK;
    $at = static fn(string $local): int => (new \DateTimeImmutable($local, new \DateTimeZone('America/Bogota')))->getTimestamp();
    $tickets = new CommercialTicketsRepository($this->db);
    $tickets->changeStatus(900, 'Postergado');
    $this->db->update('wp_jet_cct_tickets', ['estado_administrativo' => 'Postergado'], ['_ID' => 900]);
    self::assertTrue($tickets->scheduleResume(900, $at('2026-11-30 08:00'), 'Se programó retomar la tarea el 30/11/2026: ' . $mark . '.'));
    [$notifier] = $this->recorder();
    self::assertSame(1, (new \SCM\Commercial\CommercialPostponedResumer($this->db, new Settings($this->db), $notifier))->run($at('2026-11-30 07:00')));

    self::assertSame([], $tickets->resumedBySystemSince($at('2026-12-01 00:00'), $mark), 'Volvió antes de --desde.');
    self::assertSame(['900'], array_map('strval', array_column($tickets->resumedBySystemSince($at('2026-11-30 00:00'), $mark), '_ID')));

    self::assertTrue($tickets->repostpone(900, $at('2027-01-04 08:00'), 'La tarea volvió a "Postergado": ' . $mark . '.'));
    $task = $this->task();
    self::assertSame(['En proceso', 'Postergado', 'Postergado'], [$task['estado'], $task['estado_comercial'], $task['estado_administrativo']], 'Como Postergar; no toca el estado administrativo.');
    self::assertSame((string) $at('2027-01-04 08:00'), (string) $task['fecha_retomar']);
    self::assertFalse($tickets->repostpone(900, $at('2027-01-05 08:00'), 'otra'), 'Ya no está en Nuevo.');
    self::assertSame([], $tickets->resumedBySystemSince($at('2026-11-30 00:00'), $mark), 'Ya está postergada.');

    // Si un funcionario ya la trabajó después de volver, no se toca.
    $this->db->update('wp_jet_cct_tickets', ['estado' => 'Nuevo', 'estado_comercial' => 'Nuevo', 'fecha_retomar' => null], ['_ID' => 900]);
    self::assertCount(1, $tickets->resumedBySystemSince($at('2026-11-30 00:00'), $mark));
    $this->db->insert('wp_jet_cct_notas_ticket', ['id_ticket' => '900', 'fecha' => $at('2026-11-30 09:00'), 'nombre' => 'Consultora Uno', 'observacion' => 'La llamé.']);
    self::assertSame([], $tickets->resumedBySystemSince($at('2026-11-30 00:00'), $mark), 'Ya la tocó un funcionario.');
  }

  public function testUnscheduleLeavesTheScriptTasksPostponedWithoutDateButNotTheConsultantOnes(): void
  {
    $mark = \SCM\Commercial\CommercialPostponedScheduler::NOTE_MARK;
    $tickets = new CommercialTicketsRepository($this->db);
    $tickets->changeStatus(900, 'Postergado');
    $later = time() + 86400 * 40;
    self::assertTrue($tickets->scheduleResume(900, $later, 'Se programó retomar la tarea: ' . $mark . '.'));
    self::assertSame(['900'], array_map('strval', array_column($tickets->postponedScheduledBySystem($mark), '_ID')));
    self::assertTrue($tickets->scheduleResume(900, null, 'Se quitó la fecha.', true), 'Quitar la fecha del reparto.');
    self::assertEmpty($this->task()['fecha_retomar']);
    self::assertSame('Postergado', $this->task()['estado_comercial']);
    self::assertSame(['900'], array_map('strval', array_column($tickets->postponedWithoutResumeDate(), '_ID')), 'Queda para ponerle fecha más adelante.');

    // Si después la postergó un consultor/a con su fecha, esa no se toca.
    self::assertTrue($tickets->scheduleResume(900, $later, 'Se programó retomar la tarea: ' . $mark . '.'));
    $this->db->insert('wp_jet_cct_notas_ticket', ['id_ticket' => '900', 'fecha' => time() + 60, 'nombre' => 'Consultora Uno', 'observacion' => 'Postergada: el cliente viaja.']);
    self::assertSame([], $tickets->postponedScheduledBySystem($mark));

    // Las que volvieron a Nuevo regresan a Postergado sin fecha.
    $this->db->update('wp_jet_cct_tickets', ['estado' => 'Nuevo', 'estado_comercial' => 'Nuevo', 'fecha_retomar' => null], ['_ID' => 900]);
    self::assertTrue($tickets->repostpone(900, null, 'La tarea volvió a "Postergado" sin fecha.'));
    $task = $this->task();
    self::assertSame(['En proceso', 'Postergado'], [$task['estado'], $task['estado_comercial']]);
    self::assertEmpty($task['fecha_retomar']);
  }

  public function testPostponedAndClosedClientNoticesUseTheirTemplates(): void
  {
    [$notifier, $log] = $this->recorder();
    $notifier->taskPostponed(900, $this->task(), "El propietario viaja\ny regresa en diciembre", 'lunes 7 de diciembre de 2026', 'Consultora Uno', '101');
    self::assertSame([CommercialCallsNotifier::TEMPLATE_POSTPONED], array_column($log->whatsapp, 1));
    self::assertSame('+573001234567', $log->whatsapp[0][0]);
    $body = array_column($log->whatsapp[0][2][0]['parameters'], 'text');
    self::assertSame(['Laura Prueba', '900', 'lunes 7 de diciembre de 2026', 'El propietario viaja y regresa en diciembre', 'Consultora Uno · Correo: 101@example.test · Celular: 3000000101'], $body, 'Sin saltos de línea y el contacto en una sola variable.');
    self::assertSame(900, PublicTaskAccess::verify(end($log->whatsapp[0][2])['parameters'][0]['text']));
    self::assertSame('laura@example.test', $log->mail[0][0]);
    self::assertStringContainsString('lunes 7 de diciembre de 2026', $log->mail[0][2]);
    self::assertStringContainsString('Su consultor/a asignado es', $log->mail[0][2]);

    [$notifier, $log] = $this->recorder();
    $notifier->taskClosed(900, $this->task(), 'Finalizado', 'Firmaste el contrato', 'Consultora Uno', '101');
    self::assertSame([CommercialCallsNotifier::TEMPLATE_CLOSED], array_column($log->whatsapp, 1));
    self::assertCount(4, $log->whatsapp[0][2][0]['parameters']);
    self::assertSame('Cerramos tu tarea #900', $log->mail[0][1]);

    foreach (CommercialCallsNotifier::SILENT_CLOSURES as $status) {
      [$notifier, $log] = $this->recorder();
      $notifier->taskClosed(900, $this->task(), $status, 'Va en otra tarea', 'Consultora Uno');
      self::assertSame([[], []], [$log->mail, $log->whatsapp], $status . ' no se le cuenta al cliente.');
    }

    $this->db->insert('wp_jet_cct_clientes', ['celular' => '3001234567', 'bloqueo_whatsapp' => '1', 'bloqueo_email' => '0']);
    [$notifier, $log] = $this->recorder();
    $notifier->taskPostponed(900, $this->task(), 'Pausa', 'martes 8 de diciembre de 2026', 'Consultora Uno');
    self::assertSame([], $log->whatsapp, 'Quien bloqueó WhatsApp no lo recibe.');
    self::assertCount(1, $log->mail);
  }

  public function testReplyGuidesOnlyTouchTheCommercialCategory(): void
  {
    $this->db->insert('wp_jet_cct_respuesta_de_ticket', ['categoria' => 'Cartera', 'estado' => 'Mora', 'situacion' => 'Cobro', 'respuesta' => 'Recuerde pagar']);
    $guides = new CommercialReplyGuides($this->db);
    $totals = $guides->save([
      ['estado' => 'Mostrando', 'situacion' => 'Después de la visita', 'respuesta' => 'Gracias por visitar el inmueble.'],
      ['estado' => '', 'situacion' => 'Saludo general', 'respuesta' => 'Hola, con gusto te ayudo.'],
      ['estado' => '', 'situacion' => '', 'respuesta' => ''],
    ], false, '101');
    self::assertSame(['added' => 2, 'updated' => 0, 'deleted' => 0], $totals);
    self::assertSame('101', $this->db->getVar("SELECT cct_author_id FROM wp_jet_cct_respuesta_de_ticket WHERE situacion = 'Saludo general'"), 'cct_author_id lleva el id_empleado.');

    $rows = $guides->forStatus('mostrando');
    self::assertSame(['Después de la visita', 'Saludo general'], array_column($rows, 'situacion'), 'Primero las del estado de la tarea.');
    self::assertSame([true, false], array_column($rows, 'match'));

    $all = $guides->all();
    try {
      $guides->save([$all[0]], false, '101');
      self::fail('Eliminar sin confirmar no se permite.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('confirmación', $exception->getMessage());
    }
    $kept = ['id' => $all[0]['id'], 'estado' => 'Mostrando', 'situacion' => 'Después de la visita', 'respuesta' => 'Texto nuevo'];
    self::assertSame(['added' => 0, 'updated' => 1, 'deleted' => 1], $guides->save([$kept], true, '101'));
    self::assertSame('Recuerde pagar', $this->db->getVar("SELECT respuesta FROM wp_jet_cct_respuesta_de_ticket WHERE categoria = 'Cartera'"), 'Las demás categorías no se tocan.');

    $this->expectException(\InvalidArgumentException::class);
    $guides->save([['estado' => 'Nuevo', 'situacion' => 'Sin texto', 'respuesta' => '']], true, '101');
  }

  public function testReplyGuidesAreEditedOneByOneFromTheTask(): void
  {
    $this->db->insert('wp_jet_cct_respuesta_de_ticket', ['categoria' => 'Cartera', 'estado' => 'Mora', 'situacion' => 'Cobro', 'respuesta' => 'Recuerde pagar']);
    $cartera = (int) $this->db->getVar("SELECT _ID FROM wp_jet_cct_respuesta_de_ticket WHERE categoria = 'Cartera'");
    $guides = new CommercialReplyGuides($this->db);

    $id = $guides->saveOne(['estado' => 'En cierre', 'situacion' => 'Solicitud aprobada', 'respuesta' => "Apreciado cliente,\r\n<b>Buenas noticias</b>"], '101');
    self::assertGreaterThan(0, $id);
    self::assertSame([['id' => $id, 'estado' => 'En cierre', 'situacion' => 'Solicitud aprobada', 'respuesta' => "Apreciado cliente,\nBuenas noticias"]], $guides->all());
    self::assertSame('Comercial', $this->db->getVar('SELECT categoria FROM wp_jet_cct_respuesta_de_ticket WHERE _ID = ?', [$id]));

    self::assertSame($id, $guides->saveOne(['id' => $id, 'estado' => 'Mostrando', 'situacion' => 'Solicitud aprobada', 'respuesta' => 'Texto nuevo'], '102'));
    self::assertSame(['Mostrando', 'Texto nuevo', '102'], array_values($this->db->getRow('SELECT estado, respuesta, cct_author_id FROM wp_jet_cct_respuesta_de_ticket WHERE _ID = ?', [$id]) ?? []));

    foreach ([fn() => $guides->saveOne(['id' => $cartera, 'situacion' => 'Cobro', 'respuesta' => 'Otro'], '101'), fn() => $guides->delete($cartera), fn() => $guides->saveOne(['situacion' => 'Sin texto', 'respuesta' => ' '], '101')] as $attempt) {
      try {
        $attempt();
        self::fail('Solo se edita la categoría Comercial y con situación y respuesta.');
      } catch (\InvalidArgumentException) {
      }
    }
    self::assertSame('Recuerde pagar', $this->db->getVar('SELECT respuesta FROM wp_jet_cct_respuesta_de_ticket WHERE _ID = ?', [$cartera]));

    self::assertSame('Solicitud aprobada', $guides->delete($id));
    self::assertSame([], $guides->all());
  }

  public function testReplyGuideEditingIsAnOptInPermissionWithItsEndpoints(): void
  {
    self::assertArrayHasKey('respuestas_guia_editar', \SCM\Commercial\CommercialAccessPolicy::ACTIONS);
    self::assertContains('respuestas_guia_editar', \SCM\Commercial\CommercialAccessPolicy::OPT_IN_ACTIONS);
    $groups = (new \ReflectionClassConstant(\SCM\Views\CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertContains('respuestas_guia_editar', $groups['Tareas']);
    $routes = (new \ReflectionMethod(\SCM\Http\Api\CommercialActionRouter::class, 'controllerMethod'));
    self::assertSame('replyGuideSave', $routes->invoke(null, 'commercial_reply_guide_save'));
    self::assertSame('replyGuideDelete', $routes->invoke(null, 'commercial_reply_guide_delete'));
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CommercialApiController.php');
    self::assertMatchesRegularExpression("/function replyGuidesForEditor\(\).*?authorize\('respuestas_guia_editar'/s", $source, 'El servidor valida el permiso.');

    self::assertTrue(CommercialCallsDatabase::policy($this->db)->canAct('respuestas_guia_editar'), 'El administrador edita.');
    $_SESSION['scm_user_cargo'] = '9';
    self::assertFalse(CommercialCallsDatabase::policy($this->db)->canAct('respuestas_guia_editar'), 'Hay que marcarlo en Permisos.');
  }

  public function testModalShowsStatusGuidesReplyGuidesResumeDateAndRequiredReason(): void
  {
    $guides = (new CommercialReplyGuides($this->db));
    $guides->save([['estado' => 'Mostrando', 'situacion' => 'Después de la visita', 'respuesta' => 'Gracias por visitar "el inmueble" & más.']], false, '101');
    $policy = CommercialCallsDatabase::policy($this->db);
    $html = CommercialTicketModalView::render(['ticket' => $this->task(), 'timeline' => [], 'reply_guides' => $guides->forStatus('Mostrando')], $policy, []);
    self::assertMatchesRegularExpression('/data-commercial-refresh-case>\s*<span[^>]*>refresh<\/span>\s*<\/button>\s*<button aria-label="Cerrar modal"/', $html, 'Botón para actualizar el popup junto a la X.');

    // Responder: solo mantener o un estado activo, con la guía de cada uno.
    preg_match('/data-commercial-workflow-form="reply".*?<\/form>/s', $html, $reply);
    foreach (array_merge(CommercialStatusCatalog::POSTPONED, CommercialStatusCatalog::CLOSED) as $status) {
      self::assertStringNotContainsString('<option value="' . $status . '"', $reply[0], 'Responder no posterga ni cierra: ' . $status);
    }
    self::assertStringContainsString('data-help="' . CommercialStatusCatalog::descriptions()['Contactado'] . '"', $reply[0]);
    // Solo el significado del estado elegido y un botón a la ventana de guías (sin la lista de todos los estados).
    self::assertStringContainsString('data-commercial-status-help-name>Mostrando</strong>: <span data-commercial-status-help-text>' . CommercialStatusCatalog::descriptions()['Mostrando'] . '</span>', $reply[0]);
    self::assertStringNotContainsString('Ver la guía de todos los estados', $html);
    self::assertStringContainsString('Guía de estados</button>', $reply[0]);
    self::assertSame(3, substr_count($reply[0] . (preg_match('/data-commercial-workflow-form="close".*?<\/form>/s', $html, $closeForm) ? $closeForm[0] : ''), 'data-commercial-open-guides'), 'Responder: guía de estados y respuestas guía; Cerrar: guía de estados.');
    self::assertStringContainsString('Ver respuestas guía</button>', $reply[0], 'Responder abre la ventana de guías.');
    self::assertStringContainsString('1 respuesta guía, 1 para este estado', $reply[0]);
    self::assertStringNotContainsString('data-commercial-guide ', $reply[0], 'Las respuestas guía ya no van dentro del formulario.');

    // Ventana de guías: botón propio, el estado de la tarea elegido con su significado y las respuestas editables.
    self::assertStringContainsString('data-commercial-open-guides>', $html);
    preg_match('/<div class="[^"]*" data-commercial-guides-modal.*?<\/ul>/s', $html, $modal);
    self::assertStringContainsString('data-current-status="mostrando"', $modal[0]);
    self::assertStringContainsString('<option value="mostrando" data-label="Mostrando" data-help="' . CommercialStatusCatalog::descriptions()['Mostrando'] . '" selected>Mostrando (estado actual)</option>', $modal[0]);
    self::assertStringContainsString('<option value="finalizado" data-label="Finalizado"', $modal[0], 'Se consultan todos los estados, también los de cierre.');
    self::assertStringContainsString('&quot;el inmueble&quot; &amp; más', $modal[0], 'El texto de la guía se escapa.');
    foreach (['data-commercial-use-guide', 'data-commercial-guide-edit', 'data-commercial-guide-delete', 'data-commercial-guide-new', 'data-commercial-guide-editor'] as $control) {
      self::assertStringContainsString($control, $modal[0], 'El administrador usa y edita: ' . $control);
    }

    // Sin responder ni respuestas_guia_editar: solo consulta.
    $_SESSION['scm_user_cargo'] = '9';
    $reader = CommercialTicketModalView::render(['ticket' => $this->task(), 'timeline' => [], 'reply_guides' => $guides->forStatus('Mostrando')], CommercialCallsDatabase::policy($this->db), []);
    self::assertStringContainsString('data-commercial-guides-modal', $reader);
    foreach (['data-commercial-use-guide', 'data-commercial-guide-edit', 'data-commercial-guide-delete', 'data-commercial-guide-new', 'data-commercial-guide-editor'] as $control) {
      self::assertStringNotContainsString($control, $reader, 'Solo consulta: ' . $control);
    }
    $_SESSION['scm_user_cargo'] = '13';
    $html = CommercialTicketModalView::render(['ticket' => $this->task(), 'timeline' => [], 'reply_guides' => []], $policy, []);

    preg_match('/data-commercial-workflow-form="postpone".*?<\/form>/s', $html, $postpone);
    self::assertMatchesRegularExpression('/name="fecha_retomar" required min="\d{4}-\d{2}-\d{2}" class=/', $postpone[0], 'Desde mañana, sin fecha máxima.');
    preg_match('/data-commercial-workflow-form="close".*?<\/form>/s', $html, $close);
    self::assertStringContainsString('data-commercial-status-help-text>' . CommercialStatusCatalog::descriptions()['Finalizado'], $close[0]);
    preg_match('/data-commercial-workflow-form="reassign".*?<\/form>/s', $html, $reassign);
    self::assertStringContainsString('name="observacion" rows="2" required', $reassign[0]);
  }

  public function testContactWhatsappUsesTheNoticeNumberAndTheFooterHasNoDuplicateClose(): void
  {
    $policy = CommercialCallsDatabase::policy($this->db);
    $render = fn (array $extra): string => CommercialTicketModalView::render(['ticket' => $extra + $this->task(), 'timeline' => [], 'reply_guides' => []], $policy, []);

    // Con indicativo: no se antepone otro 57; el de WhatsApp manda sobre el celular.
    self::assertStringContainsString('href="https://wa.me/573001112233"', $render(['celular_solicitante' => '+57 300 111 2233']));
    self::assertStringContainsString('href="https://wa.me/13055550100"', $render(['celular_solicitante' => '3001112233', 'celular_wsp' => '3055550100', 'indicativo' => '1']));
    self::assertStringNotContainsString('data-commercial-whatsapp', $render(['celular_solicitante' => '123']), 'Sin un número válido no hay botón.');

    $html = $render(['id_inmueble' => '345']);
    self::assertStringNotContainsString('Finalizar y Entregar Inmueble', $html, 'Cerrar la tarea va solo en Gestión Operativa.');
    self::assertStringNotContainsString('Core CRM', $html);
    self::assertStringContainsString('data-commercial-insert-property="345"', $html);
  }

  public function testPropertySheetOpensInsideThePanelAndTheSlaIsReal(): void
  {
    $policy = CommercialCallsDatabase::policy($this->db);
    $ticket = (new \SCM\Commercial\CommercialSlaService($this->db))->decorateTicket(['id_inmueble' => '345', 'fecha' => time()] + $this->task());
    self::assertNotSame('', (string) ($ticket['scm_sla_status'] ?? ''));
    $repository = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Commercial/CommercialTicketsRepository.php');
    self::assertStringContainsString('$ticket = $this->sla->decorateTicket($enrichedTickets[0] ?? $ticket);', $repository, 'El detalle de la tarea trae el SLA calculado.');
    $html = CommercialTicketModalView::render(['ticket' => $ticket, 'timeline' => [], 'reply_guides' => []], $policy, []);

    // La ficha del inmueble es la interna (con la tarea como contexto); el enlace al portal va dentro de la ficha.
    self::assertSame(2, substr_count($html, 'data-commercial-property-detail="345" data-ticket-pk="900"'));
    self::assertStringNotContainsString('href="https://sucasainmobiliaria.com.co/inmuebles/inmueble-', $html);
    self::assertStringNotContainsString('Lead Calificado', $html);
    self::assertStringNotContainsString('92%', $html);
    self::assertStringContainsString('data-commercial-sla="' . $ticket['scm_sla_status'] . '"', $html);
    $late = CommercialTicketModalView::render(['ticket' => ['scm_sla_status' => 'atrasado', 'scm_sla_is_overdue' => '1', 'scm_attention_days' => 7, 'scm_sla_due_days' => 5, 'scm_sla_percent' => 140] + $ticket, 'timeline' => [], 'reply_guides' => []], $policy, []);
    self::assertStringContainsString('>Atrasada</span>', $late);
    self::assertStringContainsString('7 días hábiles sin atender; el plazo es de 5.', $late);
    self::assertStringContainsString('style="width:100%"', $late);

    // Servidor: sin la vista Inmuebles, solo desde una tarea visible de ese inmueble o si el inmueble está a su cargo.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CommercialApiController.php');
    self::assertMatchesRegularExpression('/function canOpenPropertyFromContext.*?id_funcionario.*?authorizeTicketScope\(\$ticketPk\)/s', $source);
    self::assertStringContainsString('renderPropertyDetailModalContent($property, $highlights, $this->policy->canAct(\'destacados_gestionar\'))', $source);
    self::assertMatchesRegularExpression("/\\\$highlights = \\\$this->policy->canView\('inmuebles'\);/", $source);

    // Sin la vista Inmuebles la ficha no ofrece destacados (solicitar, cancelar ni liberar cupo).
    $sheet = ['codigo' => '345', 'tipo_inmueble' => 'Apartamento', 'estado' => 'Publico', 'active_portals' => ['fincaraiz']];
    $full = \SCM\Views\CommercialDashboardView::renderPropertyDetailModalContent($sheet);
    self::assertStringContainsString('Gestión de Destacados', $full);
    self::assertStringContainsString('data-commercial-highlight-form', $full);
    $limited = \SCM\Views\CommercialDashboardView::renderPropertyDetailModalContent($sheet, false);
    foreach (['Gestión de Destacados', 'data-commercial-highlight-form', 'data-commercial-release-highlight'] as $piece) {
      self::assertStringNotContainsString($piece, $limited);
    }
    self::assertStringContainsString('Ver en la página web', $limited);
    $views = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/CommercialDashboardView.php');
    self::assertSame(1, substr_count($views, 'inmuebles/inmueble-'), 'El portal solo se abre desde la ficha.');
  }

  public function testTimelineEntriesAreEditedTwiceAndDeletedWithATrace(): void
  {
    $editor = new \SCM\Commercial\CommercialTimelineEditor($this->db);
    $add = function (string $table, array $row): int {
      $this->db->insert($table, $row + ['id_ticket' => '900', 'fecha' => 1793000000, 'cct_status' => 'publish']);
      return (int) $this->db->lastInsertId();
    };
    $mine = $add('wp_jet_cct_historial_del_ticket', ['respuesta' => 'Texto con eror', 'id_empleado' => '101', 'nombre' => 'Consultora Uno']);
    $other = $add('wp_jet_cct_historial_del_ticket', ['respuesta' => 'De otra persona', 'id_empleado' => '202', 'nombre' => 'Consultor Dos']);
    $client = $add('wp_jet_cct_historial_del_ticket', ['respuesta' => 'Gracias', 'id_empleado' => 'cliente', 'nombre' => 'Laura Prueba']);
    $follow = $add('wp_jet_cct_seguimiento_ticket', ['observacion' => 'Llamé al cliente', 'id_coordinador' => '101', 'nombre' => 'Consultora Uno']);
    $foreign = $add('wp_jet_cct_historial_del_ticket', ['respuesta' => 'De otra tarea', 'id_empleado' => '101', 'id_ticket' => '901']);
    self::assertTrue($editor->editable('respuesta') && $editor->editable('seguimiento') && $editor->editable('nota'));

    // Su autor la edita dos veces; la tercera ya no. El cliente no recibe nada (no hay notificador).
    self::assertSame(1, $editor->edit(900, 'respuesta', $mine, "Texto con error\r\n<b>corregido</b>", '101', false)['edits']);
    $row = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE _ID = ?', [$mine]);
    self::assertSame("Texto con error\ncorregido", $row['respuesta']);
    self::assertGreaterThan(0, (int) $row['fecha_edicion']);
    self::assertStringContainsString('Ya no se puede editar de nuevo.', $editor->edit(900, 'respuesta', $mine, 'Segunda corrección', '101', false)['message']);
    try {
      $editor->edit(900, 'respuesta', $mine, 'Tercera', '101', false);
      self::fail('Solo dos ediciones.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('ya se editó 2 veces', $exception->getMessage());
    }

    // Solo el autor (id_empleado exacto, también id_coordinador en seguimientos) o un administrador.
    $refused = static function (callable $action): bool {
      try {
        $action();
      } catch (\InvalidArgumentException | \RuntimeException) {
        return true;
      }
      return false;
    };
    self::assertTrue($refused(fn() => $editor->edit(900, 'respuesta', $other, 'Cambio', '101', false)), 'No es suya.');
    self::assertSame(1, $editor->edit(900, 'respuesta', $other, 'Cambio del administrador', '303', true)['edits']);
    self::assertTrue($refused(fn() => $editor->edit(900, 'respuesta', $client, 'Cambio', '303', true)), 'El mensaje del cliente no se toca.');
    self::assertTrue($refused(fn() => $editor->delete(900, 'respuesta', $foreign, '101', false, 'Consultora Uno')), 'Otra tarea.');
    self::assertTrue($refused(fn() => $editor->edit(900, 'respuesta', $mine, '   ', '101', false)), 'Texto vacío.');

    // Eliminar deja una nota interna de constancia, que no se edita ni se elimina.
    $editor->delete(900, 'seguimiento', $follow, '101', false, 'Consultora Uno');
    self::assertNull($this->db->getRow('SELECT * FROM wp_jet_cct_seguimiento_ticket WHERE _ID = ?', [$follow]));
    $trace = $this->db->getRow('SELECT * FROM wp_jet_cct_notas_ticket ORDER BY _ID DESC LIMIT 1');
    self::assertStringStartsWith(\SCM\Commercial\CommercialTimelineEditor::TRACE_PREFIX . ' Consultora Uno eliminó el seguimiento del ', (string) $trace['observacion']);
    self::assertStringContainsString('Decía: «Llamé al cliente»', (string) $trace['observacion']);
    self::assertSame(['900', '101', '101'], [$trace['id_ticket'], $trace['id_empleado'], $trace['cct_author_id']]);
    self::assertTrue($refused(fn() => $editor->delete(900, 'nota', (int) $trace['_ID'], '303', true, 'Coordinación')));

    // Botones: los ve el autor o un administrador, con el permiso; editar mientras queden ediciones.
    $item = ['_ID' => $mine, 'type' => 'respuesta', 'actor_id' => '101', 'message' => 'x', 'veces_editada' => '1', 'edit_ready' => '1'];
    self::assertSame(['edit' => true, 'delete' => true, 'edits' => 1], \SCM\Commercial\CommercialTimelineEditor::permissions($item, '101', false, true));
    self::assertSame(['edit' => false, 'delete' => false, 'edits' => 1], \SCM\Commercial\CommercialTimelineEditor::permissions($item, '202', false, true));
    self::assertFalse(\SCM\Commercial\CommercialTimelineEditor::permissions($item, '101', false, false)['delete'], 'Sin el permiso.');
    self::assertFalse(\SCM\Commercial\CommercialTimelineEditor::permissions(['veces_editada' => '2'] + $item, '101', false, true)['edit']);
    self::assertFalse(\SCM\Commercial\CommercialTimelineEditor::permissions(['edit_ready' => '0'] + $item, '101', false, true)['edit'], 'Sin los campos todavía.');
    self::assertTrue(\SCM\Commercial\CommercialTimelineEditor::permissions(['actor_id' => '202'] + $item, '303', true, true)['edit']);

    // La tarjeta: botones, editor con el texto y la marca «Editada».
    $policy = CommercialCallsDatabase::policy($this->db);
    $timeline = [
      ['_ID' => $mine, 'type' => 'respuesta', 'actor_id' => '303', 'nombre' => 'Coordinación', 'message' => 'Mi "texto" & más', 'veces_editada' => '1', 'fecha_edicion' => 1793000500, 'edit_ready' => '1', '_timestamp' => 1793000000],
      ['_ID' => $client, 'type' => 'respuesta', 'actor_id' => 'cliente', 'nombre' => 'Laura', 'message' => 'Gracias, vi https://sucasainmobiliaria.com.co/inmuebles/inmueble-345. y https://www.sucasainmobiliaria.com.co/inmueble/77?x=1', 'veces_editada' => '0', 'edit_ready' => '1', '_timestamp' => 1792000000],
    ];
    $_SESSION['scm_employee_id'] = '303';
    $html = CommercialTicketModalView::render(['ticket' => $this->task(), 'timeline' => $timeline, 'reply_guides' => []], $policy, []);
    self::assertStringContainsString('data-purpose="timeline-feed" data-ticket-pk="900"', $html);
    self::assertSame(1, substr_count($html, 'data-commercial-timeline-edit '), 'Solo la propia; la del cliente no.');
    self::assertSame(1, substr_count($html, 'data-commercial-timeline-delete '));
    self::assertStringContainsString('data-commercial-timeline-edited>', $html);
    self::assertStringContainsString('Mi &quot;texto&quot; &amp; más</textarea>', $html);
    self::assertStringContainsString('Es la última edición. No se le reenvía al cliente.', $html);
    // Los enlaces de inmuebles del mensaje abren la ficha interna con la tarea de contexto.
    self::assertStringContainsString('<button type="button" data-commercial-property-detail="345" data-ticket-pk="900"', $html);
    self::assertStringContainsString('<span>Ficha Inmueble #345</span></button>.', $html);
    self::assertStringContainsString('data-commercial-property-detail="77"', $html);

    // Permiso y endpoints.
    self::assertArrayHasKey('historial_editar', \SCM\Commercial\CommercialAccessPolicy::ACTIONS);
    self::assertStringContainsString("'respuestas_guia_editar', 'historial_editar'],", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/CommercialDashboardView.php'), 'En el grupo Tareas de Permisos.');
    $router = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Api/CommercialActionRouter.php');
    self::assertStringContainsString("'commercial_timeline_edit' => 'timelineEdit'", $router);
    self::assertStringContainsString("'commercial_timeline_delete' => 'timelineDelete'", $router);
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CommercialApiController.php');
    self::assertMatchesRegularExpression("/function timelineEditor\(.*?authorize\('historial_editar'.*?authorizeTicketScope/s", $source);
    $repository = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Commercial/CommercialTicketsRepository.php');
    self::assertSame(3, substr_count($repository, 'Edits},'), 'La cronología trae las ediciones de las tres tablas.');
  }}
