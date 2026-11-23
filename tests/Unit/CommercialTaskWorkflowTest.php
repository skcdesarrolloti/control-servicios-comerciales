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
  }

  public function testResumeDateMustBeBetweenTomorrowAndOneYear(): void
  {
    $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
    self::assertNull(CommercialApiController::resumeDate($today->format('Y-m-d')), 'Hoy no: desde mañana.');
    self::assertNull(CommercialApiController::resumeDate($today->modify('+2 years')->format('Y-m-d')));
    self::assertNull(CommercialApiController::resumeDate('2026-02-30'));
    self::assertNull(CommercialApiController::resumeDate(''));
    self::assertSame('08:00', CommercialApiController::resumeDate($today->modify('+1 day')->format('Y-m-d'))?->format('H:i'));
    self::assertSame('lunes 7 de diciembre de 2026', CommercialApiController::longDate(new \DateTimeImmutable('2026-12-07')));
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
    self::assertMatchesRegularExpression('/name="fecha_retomar" required min="\d{4}-\d{2}-\d{2}" max="\d{4}-\d{2}-\d{2}"/', $postpone[0]);
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
    $views = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/CommercialDashboardView.php');
    self::assertSame(1, substr_count($views, 'inmuebles/inmueble-'), 'El portal solo se abre desde la ficha.');
  }
}
