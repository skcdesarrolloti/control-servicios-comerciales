<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\MeetingNotFound;
use SCM\Commercial\Promotion\MeetingSchema;
use SCM\Commercial\Promotion\MeetingService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialDashboardView;
use SCM\Views\Promotion\MeetingsView;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Pestaña Promoción (oculta por defecto) con Reuniones, traída de dashboard-marketing (MeetingService/Repository/Schema). */
final class CommercialPromotionMeetingsTest extends TestCase
{
  private array $previousSession;
  private Database $db;
  private Settings $settings;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    CommercialPromotionDatabase::login('13', '101', 'Gerente');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function policy(): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function service(): MeetingService
  {
    return new MeetingService($this->db, $this->settings, $this->policy());
  }

  /** @return array<string,mixed> */
  private function row(string $table, int $id): array
  {
    return $this->db->getRow('SELECT * FROM `wp_' . $table . '` WHERE id = ?', [$id]) ?? [];
  }

  public function testPromotionIsHiddenByDefaultAndVisibleForAdministratorsAndCargosThatHaveIt(): void
  {
    self::assertContains('promocion', CommercialAccessPolicy::OPT_IN_VIEWS);
    self::assertNotContains('promocion', CommercialAccessPolicy::defaultViews());
    self::assertContains('herramientas', CommercialAccessPolicy::defaultViews());
    self::assertSame(['Promoción' => ['promocion']], array_slice(CommercialAccessPolicy::VIEW_GROUPS, -1, 1, true));
    self::assertSame(['reuniones' => 'Reuniones'], CommercialAccessPolicy::SUBVIEWS['promocion']);

    // Cargo sin configurar: ve las demás pestañas, no Promoción, aunque "gestionar" no sea opt-in.
    CommercialPromotionDatabase::login('10', '202');
    $policy = $this->policy();
    self::assertTrue($policy->canView('inicio'));
    self::assertTrue($policy->canSubview('herramientas', 'generador_utm'));
    self::assertFalse($policy->canView('promocion'));
    self::assertFalse($policy->canSubview('promocion', 'reuniones'));
    self::assertTrue($policy->canAct('reuniones_gestionar'));
    self::assertFalse($policy->canAct('reuniones_reabrir'));
    self::assertFalse($policy->canAct('reuniones_eliminar'));

    // Acceso total: la ve con todas las acciones.
    CommercialPromotionDatabase::login('13', '101');
    $policy = $this->policy();
    self::assertTrue($policy->canSubview('promocion', 'reuniones'));
    self::assertTrue($policy->canAct('reuniones_eliminar'));
    self::assertTrue($policy->canSeeAll('promocion'));

    // Cargo configurado con la pestaña: la ve; las acciones son las que tenga marcadas.
    CommercialPromotionDatabase::login('8', '202');
    $policy = $this->policy();
    self::assertTrue($policy->canSubview('promocion', 'reuniones'));
    self::assertFalse($policy->canAct('reuniones_gestionar'));
    self::assertSame('reuniones', $policy->resolveNavigation('promocion', [])['promocion_sub']);
    CommercialPromotionDatabase::login('7', '303');
    self::assertTrue($this->policy()->canAct('reuniones_gestionar'));
    self::assertFalse($this->policy()->canAct('reuniones_reabrir'));
    self::assertFalse($this->policy()->canSeeAll('promocion'));
  }

  public function testTabGroupsNormalizePromotionWithoutBreakingTools(): void
  {
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'reuniones'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'reuniones']));
    self::assertSame(['tab' => 'promocion', 'subtab' => 'reuniones', 'promocion_sub' => 'reuniones'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'promocion', 'subtab' => 'reuniones']));
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'generador_utm'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'generador_utm']));
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'calculadora_aseo'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'calculadora_aseo']));
    self::assertSame('simulador_ingreso_neto', CommercialAccessPolicy::normalizeToolTab(['tab' => 'preaprobado'])['herramienta']);
    self::assertSame('simulador_ingreso_neto', CommercialAccessPolicy::normalizeToolTab(['tab' => 'herramientas', 'subtab' => 'preaprobado'])['herramienta']);
    self::assertSame(['tab' => 'calendario', 'subtab' => 'due'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'calendario', 'subtab' => 'due']));
    self::assertSame('promocion', CommercialAccessPolicy::tabGroupOf('reuniones'));
    self::assertSame('herramientas', CommercialAccessPolicy::tabGroupOf('generador_utm'));

    // Las subpestañas de los grupos son pestañas propias: no pueden repetirse ni chocar con una vista.
    $tabs = [];
    foreach (array_keys(CommercialAccessPolicy::TAB_GROUPS) as $group) {
      $tabs = array_merge($tabs, array_keys(CommercialAccessPolicy::SUBVIEWS[$group]));
    }
    self::assertSame(count($tabs), count(array_unique($tabs)));
    self::assertSame([], array_intersect($tabs, array_keys(CommercialAccessPolicy::VIEWS)));

    // Con la subpestaña quitada, la navegación la rechaza; Herramientas sigue resolviendo la suya.
    $this->settings->set('commercial_permissions', ['9' => ['views' => ['promocion', 'herramientas'], 'subviews' => ['promocion' => [''], 'herramientas' => ['generador_utm']]]]);
    CommercialPromotionDatabase::login('9', '101');
    $policy = $this->policy();
    self::assertFalse($policy->canView('promocion'));
    self::assertSame('generador_utm', $policy->resolveNavigation('herramientas', [])['herramienta']);
    $this->expectException(\RuntimeException::class);
    $policy->resolveNavigation('promocion', ['promocion_sub' => 'reuniones']);
  }

  public function testMenusShowPromotionOnlyToCargosThatCanOpenIt(): void
  {
    CommercialPromotionDatabase::login('8', '202');
    $policy = $this->policy();
    $views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $html = CommercialDashboardView::renderTabs($views, 'promocion', [], [], '/public', [], $policy);
    self::assertStringContainsString('data-commercial-dropdown-menu="promocion"', $html);
    self::assertStringContainsString('href="/public/index.php?tab=reuniones"', $html);
    self::assertStringContainsString('Seguimiento, compromisos y modo guía', $html);
    self::assertStringContainsString('Promoción · Reuniones', $html);

    CommercialPromotionDatabase::login('10', '202');
    $policy = $this->policy();
    $views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    self::assertNotContains('promocion', $views);
    $html = CommercialDashboardView::renderTabs($views, 'inicio', [], [], '/public', [], $policy);
    self::assertStringNotContainsString('data-commercial-dropdown="promocion"', $html);
    self::assertStringNotContainsString('tab=reuniones', $html);
    // Herramientas sigue con su menú (incluido el generador UTM).
    self::assertStringContainsString('tab=generador_utm', $html);
    self::assertStringContainsString('Herramientas · Generador UTM', $html);
  }

  public function testAccessSettingsLeavePromotionUncheckedForUnconfiguredCargos(): void
  {
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 9, 'id_empleado' => '909', 'nombre' => 'Sin configurar', 'activo' => 'Si', 'id_cargo' => '10']);
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 10, 'id_empleado' => '913', 'nombre' => 'Gerente', 'activo' => 'Si', 'id_cargo' => '13']);
    $method = new \ReflectionMethod(CommercialDashboardView::class, 'renderPermissions');
    $html = (string) $method->invoke(null, $this->policy(), []);
    $block = static function (string $cargo) use ($html): string {
      self::assertSame(1, preg_match('/data-cargo="' . $cargo . '".*?(?=data-cargo="|data-perm-pane="filtros")/s', $html, $match));
      return $match[0];
    };
    self::assertMatchesRegularExpression('/value="promocion"(?![^>]*checked)[^>]*data-perm-view-input/', $block('10'));
    self::assertMatchesRegularExpression('/value="inicio" checked="checked"/', $block('10'));
    self::assertMatchesRegularExpression('/value="promocion" checked="checked"/', $block('13'));
    self::assertMatchesRegularExpression('/value="promocion" checked="checked"/', $block('7'));
    foreach (['reuniones_gestionar', 'reuniones_reabrir', 'reuniones_eliminar'] as $action) {
      self::assertStringContainsString('value="' . $action . '"', $html);
    }
    self::assertMatchesRegularExpression('/value="reuniones_eliminar"(?![^>]*checked)/', $block('10'));
  }

  public function testSchemaIsAdditiveAndRunsOnlyOnce(): void
  {
    $db = CommercialPromotionDatabase::create();
    $settings = CommercialPromotionDatabase::settings($db);
    // Tabla tal como la deja dashboard-marketing, con una reunión suya.
    $db->pdo()->exec("CREATE TABLE wp_marketing_meetings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, meeting_date TEXT NOT NULL, objective TEXT NULL,
      participants_json TEXT NULL, conclusions TEXT NULL, status TEXT NOT NULL DEFAULT 'draft', carried_from_meeting_id INTEGER NULL UNIQUE, created_by INTEGER NULL,
      created_by_name TEXT NOT NULL DEFAULT '', updated_by INTEGER NULL, updated_by_name TEXT NOT NULL DEFAULT '', started_at TEXT NULL, completed_at TEXT NULL,
      archived_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $db->insert('wp_marketing_meetings', ['title' => 'Del legado', 'meeting_date' => '2026-10-01', 'status' => 'completed', 'created_by' => 101, 'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00']);
    self::assertFalse(MeetingSchema::hasColumn($db, 'wp_marketing_meetings', 'deleted_at'));

    MeetingSchema::ensure($db, $settings);
    foreach (array_keys(MeetingSchema::ADDED_COLUMNS) as $column) {
      self::assertTrue(MeetingSchema::hasColumn($db, 'wp_marketing_meetings', $column), $column);
    }
    self::assertSame(MeetingSchema::VERSION, $settings->get(MeetingSchema::SETTING));
    $legacy = $db->getRow("SELECT * FROM wp_marketing_meetings WHERE title = 'Del legado'");
    self::assertSame('completed', $legacy['status']);
    self::assertNull($legacy['deleted_at']);
    // El legado sigue pudiendo insertar sin conocer las columnas nuevas.
    $db->insert('wp_marketing_meetings', ['title' => 'Otra del legado', 'meeting_date' => '2026-10-02', 'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:00:00']);

    // Con la versión guardada no vuelve a ejecutar DDL (otra conexión sobre la misma base).
    $db->pdo()->exec('DROP TABLE wp_marketing_meeting_action_history');
    $again = new Database($db->pdo());
    MeetingSchema::ensure($again, new Settings($again));
    self::assertSame(0, (int) $again->getVar("SELECT COUNT(*) FROM sqlite_master WHERE name = 'wp_marketing_meeting_action_history'"));
    MeetingSchema::ensure($again, new Settings($again), true);
    self::assertSame(1, (int) $again->getVar("SELECT COUNT(*) FROM sqlite_master WHERE name = 'wp_marketing_meeting_action_history'"));
  }

  public function testCarryOverCloseLockReopenAndLogicalDelete(): void
  {
    $service = $this->service();
    $first = $service->saveMeeting(['title' => 'Comité <b>octubre</b>', 'meeting_date' => '2026-10-05', 'objective' => 'Revisar campañas', 'participant_ids' => ['202', '303', '999', 'x']]);
    $meeting = $this->row('marketing_meetings', $first);
    // Autor con su id_empleado real (101), no con el _ID de la sesión (7).
    self::assertSame(101, (int) $meeting['created_by']);
    self::assertSame('Gerente', $meeting['created_by_name']);
    self::assertSame('Comité octubre', $meeting['title']);
    self::assertSame([['id' => 202, 'name' => 'Segundo consultor'], ['id' => 303, 'name' => 'Asistente de promoción']], json_decode((string) $meeting['participants_json'], true));
    self::assertSame('draft', $meeting['status']);

    $service->saveItem(['meeting_id' => $first, 'section' => 'achievement', 'title' => 'Se publicó la revista', 'observation' => 'no aplica']);
    $improvement = $service->saveItem(['meeting_id' => $first, 'section' => 'improvement', 'title' => 'Respuesta lenta', 'observation' => 'Medir cada semana']);
    try {
      $service->saveItem(['meeting_id' => $first, 'section' => 'action', 'title' => 'Sin responsable', 'due_date' => '2026-10-20']);
      self::fail('Una acción sin responsable no se guarda.');
    } catch (\RuntimeException $exception) {
      self::assertSame('Selecciona un responsable para la acción.', $exception->getMessage());
    }
    $pending = $service->saveItem(['meeting_id' => $first, 'section' => 'action', 'title' => 'Responder en 2 horas', 'responsible_employee_id' => '202', 'due_date' => '2026-10-20', 'related_improvement_id' => (string) $improvement]);
    $done = $service->saveItem(['meeting_id' => $first, 'section' => 'action', 'title' => 'Imprimir volantes', 'responsible_employee_id' => '303', 'due_date' => '2026-10-15']);
    $failed = $service->saveItem(['meeting_id' => $first, 'section' => 'action', 'title' => 'Pauta en radio', 'responsible_employee_id' => '303', 'due_date' => '2026-10-15']);
    self::assertSame(202, (int) $this->row('marketing_meeting_items', $pending)['responsible_employee_id']);
    self::assertSame('', (string) $this->row('marketing_meeting_items', $this->service()->find($first)['items']['achievement'][0]['id'])['observation']);

    $service->start($first);
    $service->updateActionStatus($done, 'completed');
    try {
      $service->updateActionStatus($failed, 'not_completed');
      self::fail('No realizada exige motivo.');
    } catch (\RuntimeException $exception) {
      self::assertSame('Explica por qué la acción no se realizó.', $exception->getMessage());
    }
    $result = $service->updateActionStatus($failed, 'not_completed', 'Sin presupuesto');
    self::assertSame(['total' => 3, 'completed' => 1, 'not_completed' => 1, 'reviewed' => 2, 'review_percent' => 67, 'compliance_percent' => 50], $result['progress']);
    $history = $this->db->getResults('SELECT from_status, to_status, reason, changed_by FROM wp_marketing_meeting_action_history ORDER BY id');
    self::assertSame(['from_status' => 'pending', 'to_status' => 'not_completed', 'reason' => 'Sin presupuesto', 'changed_by' => 101], array_merge($history[1], ['changed_by' => (int) $history[1]['changed_by']]));

    // Finalizar bloquea la reunión.
    $service->complete($first, 'Se mantiene la pauta digital');
    self::assertSame('completed', $this->row('marketing_meetings', $first)['status']);
    foreach ([
      fn() => $service->saveMeeting(['id' => $first, 'title' => 'Cambio', 'meeting_date' => '2026-10-05']),
      fn() => $service->saveItem(['meeting_id' => $first, 'section' => 'achievement', 'title' => 'Otro']),
      fn() => $service->updateActionStatus($pending, 'completed'),
      fn() => $service->complete($first),
    ] as $locked) {
      try {
        $locked();
        self::fail('La reunión finalizada no admite cambios.');
      } catch (\RuntimeException $exception) {
        self::assertMatchesRegularExpression('/cerrada/', $exception->getMessage());
      }
    }

    // La siguiente reunión trae una sola vez las acciones abiertas o no realizadas, sin las realizadas.
    $second = $service->saveMeeting(['title' => 'Comité noviembre', 'meeting_date' => '2026-11-05']);
    self::assertSame($first, (int) $this->row('marketing_meetings', $second)['carried_from_meeting_id']);
    $carried = $this->service()->find($second)['items']['action'];
    self::assertSame(['Responder en 2 horas', 'Pauta en radio'], array_column($carried, 'title'));
    self::assertSame(['pending', 'pending'], array_column($carried, 'status'));
    self::assertSame([(string) $pending, (string) $failed], array_map('strval', array_column($carried, 'carried_from_item_id')));
    self::assertSame(202, (int) $carried[0]['responsible_employee_id']);
    $third = $service->saveMeeting(['title' => 'Extra', 'meeting_date' => '2026-11-06']);
    self::assertNull($this->row('marketing_meetings', $third)['carried_from_meeting_id']);
    self::assertSame([], $this->service()->find($third)['items']['action']);

    // Reabrir solo una finalizada.
    $service->reopen($first);
    self::assertSame('in_progress', $this->row('marketing_meetings', $first)['status']);
    self::assertNull($this->row('marketing_meetings', $first)['completed_at']);
    try {
      $service->reopen($second);
      self::fail('Solo una finalizada se reabre.');
    } catch (\RuntimeException $exception) {
      self::assertSame('Solo una reunión finalizada puede reabrirse.', $exception->getMessage());
    }

    // Eliminar es lógico y deja el rastro; libera el arrastre para la siguiente reunión.
    $service->delete($second);
    self::assertSame([], $this->service()->find($second));
    $deleted = $this->row('marketing_meetings', $second);
    self::assertNotNull($deleted['deleted_at']);
    self::assertSame(101, (int) $deleted['deleted_by']);
    self::assertSame('Gerente', $deleted['deleted_by_name']);
    self::assertSame('draft', $deleted['deleted_status']);
    self::assertSame('archived', $deleted['status'], 'El legado no la muestra entre las activas.');
    self::assertNull($deleted['carried_from_meeting_id']);
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_meeting_items WHERE meeting_id = ?', [$second]), 'Sus puntos se conservan.');
    self::assertNotContains($second, array_map('intval', array_column($this->service()->dashboard(['status' => 'history'])['meetings'], 'id')));
    try {
      $service->delete($second);
      self::fail('Ya eliminada.');
    } catch (MeetingNotFound $exception) {
      self::assertSame('La reunión seleccionada no existe o no tienes acceso a ella.', $exception->getMessage());
    }
    $service->complete($first);
    $fourth = $service->saveMeeting(['title' => 'Comité de reposición', 'meeting_date' => '2026-11-10']);
    self::assertSame($first, (int) $this->row('marketing_meetings', $fourth)['carried_from_meeting_id']);

    // Archivar desde cualquier estado; archivada no se modifica.
    $service->archive($third);
    self::assertSame('archived', $this->row('marketing_meetings', $third)['status']);
    $this->expectExceptionMessage('La reunión ya está archivada.');
    $service->archive($third);
  }

  public function testMovingAnItemRenumbersItsSection(): void
  {
    $service = $this->service();
    $meeting = $service->saveMeeting(['title' => 'Orden', 'meeting_date' => '2026-10-05']);
    $ids = [];
    foreach (['Uno', 'Dos', 'Tres'] as $title) {
      $ids[] = $service->saveItem(['meeting_id' => $meeting, 'section' => 'achievement', 'title' => $title]);
    }
    $service->archiveItem($ids[0]);
    $service->moveItem($ids[2], 'up');
    $order = $this->db->getResults('SELECT title, sort_order FROM wp_marketing_meeting_items WHERE meeting_id = ? AND active = 1 ORDER BY sort_order', [$meeting]);
    self::assertSame([['title' => 'Tres', 'sort_order' => 10], ['title' => 'Dos', 'sort_order' => 20]], array_map(static fn(array $row): array => ['title' => $row['title'], 'sort_order' => (int) $row['sort_order']], $order));
    $service->moveItem($ids[2], 'up');
    self::assertSame(10, (int) $this->row('marketing_meeting_items', $ids[2])['sort_order']);
    try {
      $service->moveItem($ids[0], 'down');
      self::fail('Un punto archivado no se mueve.');
    } catch (\RuntimeException $exception) {
      self::assertSame('El elemento ya no está disponible.', $exception->getMessage());
    }
  }

  public function testScopeLimitsListDetailAndChangesToOwnMeetings(): void
  {
    $service = $this->service();
    $participates = $service->saveMeeting(['title' => 'Participa', 'meeting_date' => '2026-10-01', 'participant_ids' => ['303']]);
    $foreign = $service->saveMeeting(['title' => 'Ajena', 'meeting_date' => '2026-10-02', 'participant_ids' => ['202']]);
    $responsible = $service->saveMeeting(['title' => 'Responsable', 'meeting_date' => '2026-10-03']);
    $service->saveItem(['meeting_id' => $responsible, 'section' => 'action', 'title' => 'Tarea', 'responsible_employee_id' => '303', 'due_date' => '2026-10-10']);
    // Un id que contiene al 303 (3030) no lo hace participante.
    $this->db->update('wp_marketing_meetings', ['participants_json' => '[{"id":3030,"name":"Otro"}]'], ['id' => $foreign]);

    CommercialPromotionDatabase::login('7', '303', 'Asistente de promoción');
    $own = $this->service();
    $created = $own->saveMeeting(['title' => 'Mía', 'meeting_date' => '2026-10-04']);
    $titles = array_column($own->dashboard([])['meetings'], 'title');
    self::assertEqualsCanonicalizing(['Participa', 'Responsable', 'Mía'], $titles);
    self::assertSame(3, $own->dashboard([])['summary']['total']);
    self::assertSame([], $own->find($foreign));
    self::assertNotSame([], $own->find($participates));
    foreach ([fn() => $own->saveItem(['meeting_id' => $foreign, 'section' => 'achievement', 'title' => 'x']), fn() => $own->archive($foreign), fn() => $own->delete($foreign)] as $denied) {
      try {
        $denied();
        self::fail('Fuera de su alcance.');
      } catch (MeetingNotFound) {
        self::addToAssertionCount(1);
      }
    }
    self::assertSame(303, (int) $this->row('marketing_meetings', $created)['created_by']);

    // Sin id_empleado no ve ninguna.
    CommercialPromotionDatabase::login('7', '');
    self::assertSame([], $this->service()->dashboard([])['meetings']);

    // Con "Todo el equipo" ve todas.
    CommercialPromotionDatabase::login('6', '202');
    self::assertCount(4, $this->service()->dashboard([])['meetings']);
    self::assertSame(['Ajena'], array_column($this->service()->dashboard(['q' => 'Aje'])['meetings'], 'title'));
  }

  public function testViewsShowOnlyWhatTheCargoCanUse(): void
  {
    $service = $this->service();
    $id = $service->saveMeeting(['title' => '<script>alert(1)</script> Comité "A&B"', 'meeting_date' => '2026-10-05', 'participant_ids' => ['202']]);
    $service->saveItem(['meeting_id' => $id, 'section' => 'improvement', 'title' => 'Mejora uno']);
    $service->saveItem(['meeting_id' => $id, 'section' => 'action', 'title' => 'Acción uno', 'responsible_employee_id' => '404', 'due_date' => '2026-10-10']);
    $this->db->update('wp_jet_cct_funcionarios', ['activo' => 'No'], ['id_empleado' => '202']);

    $admin = PromotionView::page(['promocion_sub' => 'reuniones', 'reunion' => (string) $id], $this->policy(), $this->db, $this->settings, '/public');
    self::assertStringContainsString('data-commercial-module-script="/public/assets/js/commercial-promotion.js?v=', $admin);
    self::assertStringContainsString('href="/public/assets/css/commercial-promotion.css?v=', $admin);
    self::assertStringNotContainsString('<script>', $admin);
    self::assertStringContainsString('<h1>alert(1) Comité &quot;A&amp;B&quot;</h1>', $admin);
    foreach (['data-cpr-action="start"', 'data-cpr-open="item"', 'data-cpr-dialog="item"', 'data-cpr-action="delete"', 'data-cpr-action="archive"', 'data-cpr-status="not_completed"', 'data-cpr-dialog="confirm"'] as $control) {
      self::assertStringContainsString($control, $admin, $control);
    }
    // Responsable retirado: queda con su nombre; el selector solo ofrece activos y no ofrece "Sin asignar".
    self::assertStringContainsString('Funcionario retirado', $admin);
    self::assertStringNotContainsString('<option value="404">', $admin);
    self::assertStringNotContainsString('Sin asignar</option>', $admin);
    self::assertStringNotContainsString('correo', $admin);

    // Solo consulta: ni formularios ni botones de cambio.
    CommercialPromotionDatabase::login('8', '202');
    $this->settings->set('commercial_permissions', ['8' => ['views' => ['promocion'], 'actions' => [], 'scope' => ['promocion' => 'all']]] + $this->settings->get('commercial_permissions', []));
    $readOnly = PromotionView::page(['promocion_sub' => 'reuniones', 'reunion' => (string) $id], $this->policy(), $this->db, $this->settings, '/public');
    self::assertStringContainsString('Mejora uno', $readOnly);
    foreach (['data-cpr-action=', 'data-cpr-open=', 'data-cpr-status=', 'data-cpr-dialog="meeting"', 'data-cpr-dialog="item"'] as $control) {
      self::assertStringNotContainsString($control, $readOnly, $control);
    }
    $list = PromotionView::page(['promocion_sub' => 'reuniones', 'reunion_estado' => 'upcoming'], $this->policy(), $this->db, $this->settings, '/public');
    self::assertStringContainsString('data-cpr-list', $list);
    self::assertStringNotContainsString('Nueva reunión', $list);
    self::assertStringContainsString('<option value="upcoming" selected>', $list);

    // Gestionar sin eliminar ni reabrir.
    CommercialPromotionDatabase::login('7', '202');
    $manage = PromotionView::page(['promocion_sub' => 'reuniones', 'reunion' => (string) $id], $this->policy(), $this->db, $this->settings, '/public');
    self::assertStringContainsString('data-cpr-action="archive"', $manage);
    self::assertStringNotContainsString('data-cpr-action="delete"', $manage);

    // Modo guía: cinco pasos; reunión ajena o inexistente: aviso sin datos.
    CommercialPromotionDatabase::login('13', '101');
    $guide = PromotionView::page(['promocion_sub' => 'reuniones', 'reunion' => (string) $id, 'modo' => 'guia'], $this->policy(), $this->db, $this->settings, '/public');
    self::assertSame(5, substr_count($guide, 'data-cpr-step-panel='));
    self::assertStringContainsString('data-cpr-form="complete"', $guide);
    self::assertStringContainsString('Esta reunión no está disponible', PromotionView::page(['promocion_sub' => 'reuniones', 'reunion' => '999'], $this->policy(), $this->db, $this->settings, '/public'));
    self::assertSame('/public/index.php?tab=reuniones&reunion=5&modo=guia', MeetingsView::url('/public/', ['reunion' => 5, 'modo' => 'guia']));
  }

  /** @return array{status:int,json:array<string,mixed>} */
  private static function endpoint(string $cargo, string $employee, string $action, array $input = []): array
  {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/promotion-endpoint.php') . ' '
      . implode(' ', array_map('escapeshellarg', [$cargo, $employee, $action, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))]));
    $lines = preg_split('/\R/', trim((string) shell_exec($command))) ?: [];
    $status = (int) array_pop($lines);
    return ['status' => $status, 'json' => json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR)];
  }

  public function testEndpointsCheckViewActionScopeAndNonce(): void
  {
    self::assertSame('meetingDelete', CommercialActionRouter::PROMOTION_ACTIONS['commercial_promo_meeting_delete']);
    $create = ['title' => 'Nueva', 'meeting_date' => '2026-11-05'];
    self::assertSame(['status' => 403, 'message' => 'No tienes permiso para entrar a Reuniones.'], self::summary(self::endpoint('10', '909', 'commercial_promo_meeting_save', $create)));
    self::assertSame(['status' => 403, 'message' => 'No tienes permiso para modificar reuniones.'], self::summary(self::endpoint('8', '202', 'commercial_promo_meeting_save', $create)));
    self::assertSame(['status' => 403, 'message' => 'No tienes permiso para eliminar reuniones.'], self::summary(self::endpoint('7', '303', 'commercial_promo_meeting_delete', ['meeting_id' => 2])));
    self::assertSame(['status' => 403, 'message' => 'No tienes permiso para reabrir reuniones.'], self::summary(self::endpoint('7', '303', 'commercial_promo_meeting_reopen', ['meeting_id' => 2])));
    self::assertSame(['status' => 404, 'message' => 'La reunión seleccionada no existe o no tienes acceso a ella.'], self::summary(self::endpoint('7', '303', 'commercial_promo_meeting_start', ['meeting_id' => 1])));
    self::assertSame(['status' => 403, 'message' => 'Verificación de seguridad fallida.'], self::summary(self::endpoint('13', '101', 'commercial_promo_meeting_delete', ['meeting_id' => 1, 'nonce' => 'otro'])));
    self::assertSame(['status' => 422, 'message' => 'Selecciona una fecha válida para la reunión.'], self::summary(self::endpoint('7', '303', 'commercial_promo_meeting_save', ['title' => 'Sin fecha'])));

    $started = self::endpoint('7', '303', 'commercial_promo_meeting_start', ['meeting_id' => 2]);
    self::assertSame(200, $started['status']);
    self::assertSame('http://127.0.0.1/public/index.php?tab=reuniones&reunion=2&modo=guia', $started['json']['data']['url']);
    $status = self::endpoint('13', '101', 'commercial_promo_meeting_action_status', ['meeting_id' => 1, 'item_id' => 1, 'status' => 'completed']);
    self::assertSame(['id' => 1, 'status' => 'completed', 'failure_reason' => ''], $status['json']['data']['item']);
    self::assertSame(100, $status['json']['data']['progress']['compliance_percent']);
    $deleted = self::endpoint('6', '202', 'commercial_promo_meeting_delete', ['meeting_id' => 1]);
    self::assertSame(200, $deleted['status']);
    self::assertSame('http://127.0.0.1/public/index.php?tab=reuniones', $deleted['json']['data']['url']);
  }

  /** @param array{status:int,json:array<string,mixed>} $response @return array{status:int,message:string} */
  private static function summary(array $response): array
  {
    return ['status' => $response['status'], 'message' => (string) ($response['json']['data']['message'] ?? '')];
  }
}
