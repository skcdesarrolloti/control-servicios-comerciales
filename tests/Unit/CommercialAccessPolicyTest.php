<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialDashboardView;

final class CommercialAccessPolicyTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_cargo' => '9'];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function policy(array $definition): CommercialAccessPolicy
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (funcion TEXT, valor TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_cargos (_ID INTEGER, nombre_cargo TEXT)');
    $pdo->exec("INSERT INTO wp_jet_cct_cargos VALUES (9, 'Consultor de Arriendo')");
    $statement = $pdo->prepare('INSERT INTO wp_jet_cct_confi_sistema VALUES (?, ?)');
    $statement->execute([Settings::FUNCTION_KEY, json_encode([
      'commercial_permissions' => ['9' => $definition],
      'commercial_admin_cargos' => ['13'],
    ], JSON_THROW_ON_ERROR)]);
    $db = new Database($pdo);
    return new CommercialAccessPolicy(new Settings($db), $db, ['13']);
  }

  public function testDisabledPropertiesAndMyTasksAreDenied(): void
  {
    $policy = $this->policy(['views' => ['inicio', 'notificaciones'], 'actions' => []]);
    self::assertTrue($policy->canView('inicio'));
    self::assertFalse($policy->canView('inmuebles'));
    self::assertFalse($policy->canView('mis_tickets'));
    self::assertFalse($policy->canSubview('inmuebles', 'publicos'));
    self::assertFalse($policy->canAct('ver_ticket'));
  }

  public function testParentPermissionIsRequiredEvenWhenSubviewIsSelected(): void
  {
    $policy = $this->policy(['views' => [], 'subviews' => ['inmuebles' => ['publicos']]]);
    $this->expectException(\RuntimeException::class);
    $policy->resolveNavigation('inmuebles', ['property_subtab' => 'publicos']);
  }

  public function testDefaultNavigationUsesAnAllowedSubview(): void
  {
    $policy = $this->policy([
      'views' => ['inmuebles', 'calendario'],
      'subviews' => ['inmuebles' => ['pendientes'], 'calendario' => ['team']],
    ]);
    self::assertSame('pendientes', $policy->resolveNavigation('inmuebles', [])['property_subtab']);
    self::assertSame('team', $policy->resolveNavigation('calendario', [])['subtab']);
    self::assertFalse($policy->canSubview('inmuebles', 'publicos'));
    $this->expectException(\RuntimeException::class);
    $policy->resolveNavigation('inmuebles', ['subtab' => 'publicos']);
  }

  public function testUncheckingEverySubviewDeniesTheParent(): void
  {
    $policy = $this->policy(['views' => ['inmuebles'], 'subviews' => ['inmuebles' => ['']]]);
    self::assertFalse($policy->canView('inmuebles'));
  }

  public function testExistingPermissionsInheritSubviewsAndAdminsRetainFullAccess(): void
  {
    $policy = $this->policy(['views' => ['inmuebles'], 'actions' => []]);
    self::assertTrue($policy->canSubview('inmuebles', 'publicos'));
    self::assertFalse($policy->canView('calendario'));
    $_SESSION['scm_user_cargo'] = '13';
    self::assertTrue($policy->canView('mis_tickets'));
    self::assertTrue($policy->canSubview('calendario', 'due'));
    self::assertFalse($policy->canView('unknown'));
  }

  public function testMenusHideDeniedSubviewsAndTaskTopicLinks(): void
  {
    $policy = $this->policy([
      'views' => ['inmuebles', 'calendario', 'mis_tickets'],
      'subviews' => ['inmuebles' => ['pendientes'], 'calendario' => ['team']],
    ]);
    $html = CommercialDashboardView::renderTabs(
      ['inmuebles', 'calendario', 'mis_tickets'], 'inmuebles', [], [], '',
      [['topic' => 'Captación', 'total' => 1, 'statuses' => []]], $policy
    );
    self::assertStringContainsString('property_subtab=pendientes', $html);
    self::assertStringNotContainsString('property_subtab=publicos', $html);
    self::assertStringContainsString('subtab=team', $html);
    self::assertStringNotContainsString('subtab=mine', $html);
    self::assertStringNotContainsString('data-commercial-tab="abiertos"', $html);
  }

  public function testHeaderCargoIsResolvedFromCargoCatalog(): void
  {
    $_SESSION['scm_user_rol'] = 'Servicio al cliente';
    self::assertSame('Consultor de Arriendo', $this->policy([])->userCargoName());
  }

  public function testPropertiesPageHidesDeniedTabsAndSummaryLinks(): void
  {
    $policy = $this->policy(['views' => ['inmuebles'], 'subviews' => ['inmuebles' => ['pendientes']]]);
    $html = CommercialDashboardView::renderPropertiesPage(
      ['rows' => [], 'total' => 0, 'pagination' => []], ['property_subtab' => 'pendientes'], [], $policy, ''
    );
    self::assertStringContainsString('property_subtab=pendientes', $html);
    self::assertStringNotContainsString('property_subtab=publicos', $html);
    self::assertStringNotContainsString('property_subtab=mis_inmuebles', $html);
    self::assertStringNotContainsString('data-commercial-property-subtab="publicos"', $html);
  }

  public function testTaskMenuIsHiddenWhenEveryTaskViewIsDenied(): void
  {
    $policy = $this->policy(['views' => ['inicio']]);
    $html = CommercialDashboardView::renderTabs(['inicio'], 'inicio', [], [], '', [], $policy);
    self::assertStringNotContainsString('data-commercial-menu-section="tareas"', $html);
  }

  public function testViewGroupsListEveryViewOnceInMenuOrder(): void
  {
    $grouped = array_merge(...array_values(CommercialAccessPolicy::VIEW_GROUPS));
    self::assertSame(count($grouped), count(array_unique($grouped)));
    self::assertEqualsCanonicalizing(array_keys(CommercialAccessPolicy::VIEWS), $grouped);
  }

  public function testScopeIsPersonalUntilTheCargoIsGivenTheWholeTeam(): void
  {
    $policy = $this->policy(['views' => ['abiertos', 'cerrados', 'mis_tickets', 'avisos']]);
    self::assertFalse($policy->canSeeAll('abiertos'));
    self::assertFalse($policy->canSeeAllTasks());

    $policy = $this->policy([
      'views' => ['abiertos', 'cerrados', 'mis_tickets', 'avisos'],
      'scope' => ['abiertos' => 'all', 'cerrados' => 'own', 'mis_tickets' => 'all', 'precaptacion' => 'all'],
    ]);
    self::assertTrue($policy->canSeeAll('abiertos'));
    self::assertFalse($policy->canSeeAll('cerrados'));
    self::assertFalse($policy->canSeeAll('avisos'));
    self::assertFalse($policy->canSeeAll('mis_tickets'), 'Mis tareas siempre es personal.');
    self::assertFalse($policy->canSeeAll('precaptacion'), 'Sin la pestaña no hay alcance de equipo.');
    self::assertTrue($policy->canSeeAllTasks());
    self::assertSame(['precaptacion', 'abiertos'], $policy->permissions()['9']['scope_all']);
  }

  public function testAdministratorsSeeTheWholeTeamEverywhere(): void
  {
    $_SESSION = ['scm_user_cargo' => '13'];
    $policy = $this->policy(['views' => []]);
    self::assertTrue($policy->canSeeAll('abiertos'));
    self::assertTrue($policy->canSeeAll('notificaciones'));
    self::assertTrue($policy->canSeeAllTasks());
  }

  public function testEveryScopedViewIsAPanelView(): void
  {
    self::assertSame([], array_diff(array_keys(CommercialAccessPolicy::SCOPED_VIEWS), array_keys(CommercialAccessPolicy::VIEWS)));
    self::assertArrayNotHasKey('mis_tickets', CommercialAccessPolicy::SCOPED_VIEWS);
  }

  public function testCargosSavedBeforeTheSplitKeepWhatTheyCouldDo(): void
  {
    $policy = $this->policy(['views' => ['abiertos', 'calendario', 'inmuebles'], 'actions' => ['ver_ticket', 'ingresar_inmueble']]);
    foreach (['tarea_agendar', 'tarea_analisis', 'tarea_exportar', 'inmueble_multimedia', 'destacados_gestionar'] as $action) {
      self::assertTrue($policy->canAct($action), $action . ' se hereda de lo que ya podía hacer.');
    }

    $policy = $this->policy(['views' => ['inicio'], 'actions' => []]);
    foreach (['tarea_agendar', 'tarea_analisis', 'tarea_exportar', 'inmueble_multimedia', 'destacados_gestionar'] as $action) {
      self::assertFalse($policy->canAct($action), $action . ' no se concede sin la acción o la vista de origen.');
    }
  }

  public function testSavedChoicesAreNotInheritedAgain(): void
  {
    $policy = $this->policy([
      'views' => ['abiertos', 'calendario', 'inmuebles'],
      'actions' => ['ver_ticket', 'ingresar_inmueble'],
      'actions_known' => array_keys(CommercialAccessPolicy::ACTIONS),
    ]);
    self::assertTrue($policy->canAct('ver_ticket'));
    foreach (['tarea_agendar', 'tarea_analisis', 'tarea_exportar', 'inmueble_multimedia', 'destacados_gestionar'] as $action) {
      self::assertFalse($policy->canAct($action), $action . ' desmarcada al guardar queda negada.');
    }
  }

  public function testSavingMarksTheWholeCatalogAsDecided(): void
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
    $db = new Database($pdo);
    $policy = new CommercialAccessPolicy(new Settings($db), $db, ['13']);
    $_SESSION = ['scm_user_cargo' => '13'];
    $policy->save(['9' => ['configured' => '1', 'views' => ['abiertos', 'calendario'], 'actions' => ['ver_ticket']]], ['13']);
    $saved = $policy->permissions()['9'];
    self::assertSame(['ver_ticket'], $saved['actions'], 'Lo desmarcado al guardar no se hereda.');
    self::assertSame(array_keys(CommercialAccessPolicy::ACTIONS), $saved['actions_known']);
  }

  public function testEveryActionIsInThePermissionsModal(): void
  {
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertSame([], array_diff(array_keys(CommercialAccessPolicy::ACTIONS), array_merge(...array_values($groups))));
  }

  public function testTaskWithoutTheNewActionsHidesAnalysisSchedulingAndExport(): void
  {
    $ticket = ['_ID' => 5, 'id_ticket' => '5', 'asunto' => 'Prueba', 'estado_comercial' => 'Nuevo', 'tema_ayuda' => 'Arriendo'];
    $allowed = $this->policy(['views' => ['abiertos', 'calendario'], 'actions' => ['ver_ticket']]);
    $html = \SCM\Views\CommercialTicketModalView::render(['ticket' => $ticket, 'timeline' => [], 'analyses' => [['id' => 1, 'resumen' => 'R']]], $allowed, []);
    self::assertStringContainsString('data-commercial-calendar-schedule', $html);
    self::assertStringContainsString('Generar Resumen Ejecutivo con IA', $html);
    self::assertStringContainsString('data-commercial-delete-analysis', $html);

    $denied = $this->policy(['views' => ['abiertos', 'calendario'], 'actions' => ['ver_ticket'], 'actions_known' => array_keys(CommercialAccessPolicy::ACTIONS)]);
    $html = \SCM\Views\CommercialTicketModalView::render(['ticket' => $ticket, 'timeline' => [], 'analyses' => [['id' => 1, 'resumen' => 'R']]], $denied, []);
    self::assertStringContainsString('data-commercial-calendar-agenda', $html, 'Ver agenda sigue con la pestaña Calendario.');
    self::assertStringNotContainsString('data-commercial-calendar-schedule', $html);
    self::assertStringNotContainsString('Generar Resumen Ejecutivo con IA', $html);
    self::assertStringNotContainsString('data-commercial-delete-analysis', $html);
    self::assertStringContainsString('data-commercial-open-analysis', $html, 'Los análisis guardados se siguen viendo.');

    $table = CommercialDashboardView::renderTickets('abiertos', [], [], [], [], [], $denied, 'http://x');
    self::assertStringNotContainsString('btn-export-csv', $table);
    self::assertStringNotContainsString('btn-export-report', $table);
    self::assertStringContainsString('btn-export-csv', CommercialDashboardView::renderTickets('abiertos', [], [], [], [], [], $allowed, 'http://x'));
  }

  public function testPropertyDetailHidesHighlightActionsWithoutPermission(): void
  {
    $property = ['codigo' => '100', 'estado' => 'Publico', 'active_portals' => [['key' => 'fincaraiz', 'label' => 'Finca Raíz']]];
    $with = CommercialDashboardView::renderPropertyDetailModalContent($property, true, true);
    $without = CommercialDashboardView::renderPropertyDetailModalContent($property, true, false);
    self::assertStringContainsString('Liberar cupo', $with);
    self::assertStringContainsString('Solicitar Nuevo Destacado', $with);
    self::assertStringNotContainsString('Liberar cupo', $without);
    self::assertStringNotContainsString('Solicitar Nuevo Destacado', $without);
    self::assertStringContainsString('Finca Raíz', $without, 'Los portales activos se siguen mostrando.');
  }

  public function testPermissionsModalWarnsWhenTaskListsCannotBeOpened(): void
  {
    $render = function (array $actions): string {
      $policy = $this->policy(['views' => ['abiertos'], 'actions' => $actions]);
      $pdo = (new \ReflectionProperty(CommercialAccessPolicy::class, 'db'))->getValue($policy)->pdo();
      $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
      $pdo->exec('CREATE TABLE wp_jet_cct_funcionarios (id_empleado TEXT, id_cargo TEXT, activo TEXT)');
      $pdo->exec("INSERT INTO wp_jet_cct_funcionarios VALUES ('901', '9', 'Si')");
      $method = new \ReflectionMethod(CommercialDashboardView::class, 'renderPermissions');
      return (string) $method->invoke(null, $policy, []);
    };
    self::assertMatchesRegularExpression('/data-perm-ticket-warning data-task-views="[^"]*"(?! hidden)>/', $render([]));
    self::assertMatchesRegularExpression('/data-perm-ticket-warning data-task-views="[^"]*" hidden>/', $render(['ver_ticket']));
  }
}
