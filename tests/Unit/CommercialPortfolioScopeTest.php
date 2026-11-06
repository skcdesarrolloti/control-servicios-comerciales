<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialTicketsRepository;
use SCM\Core\Database;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Mis tareas y Mi portafolio: lo de la persona es de la persona, también para quien ve todo el equipo. */
final class CommercialPortfolioScopeTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** @return array{0:Database,1:CommercialAccessPolicy} administrador 999 con 3 tareas abiertas y 1 cerrada, y 5 ajenas */
  private function adminWithTasks(): array
  {
    [$db, $policy] = CommercialNotificationsFixture::make(true);
    $pdo = $db->pdo();
    $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value = null): int => $value === null ? time() : (int) strtotime((string) $value));
    $table = 'wp_jet_cct_tickets';
    $pdo->exec("CREATE TABLE `{$table}` (_ID INTEGER PRIMARY KEY, id_ticket TEXT, estado TEXT, estado_comercial TEXT, estado_administrativo TEXT, asunto TEXT, descripcion TEXT,
      solicitante TEXT, correo_solicitante TEXT, celular_solicitante TEXT, id_empleado TEXT, nombre_empleado TEXT, correo_empleado TEXT, celular_empleado TEXT,
      inmueble TEXT, id_inmueble TEXT, direccion TEXT, barrio TEXT, tipo_inmueble TEXT, medio TEXT, prioridad TEXT, tema_ayuda TEXT, tuvo_seguimiento TEXT,
      fecha_seguimiento INTEGER, fecha INTEGER, fecha_actualizacion INTEGER, cct_created TEXT, cct_modified TEXT)");
    $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
    foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
      $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
    }
    $add = static function (string $employee, string $status, string $general) use ($db, $table): void {
      $now = date('Y-m-d H:i:s');
      $db->insert($table, ['estado' => $general, 'estado_comercial' => $status, 'id_empleado' => $employee, 'asunto' => 'Tarea', 'tema_ayuda' => 'Venta', 'fecha' => time(), 'cct_created' => $now, 'cct_modified' => $now]);
    };
    foreach ([1, 2, 3] as $_) {
      $add('999', 'Nuevo', 'Nuevo');
    }
    $add('999', 'Ganado', 'Cerrado');
    foreach ([1, 2, 3, 4, 5] as $_) {
      $add('900', 'Nuevo', 'Nuevo');
    }
    return [$db, $policy];
  }

  public function testMyTasksArePersonalEvenForAdministrators(): void
  {
    [, $policy] = $this->adminWithTasks();
    self::assertTrue($policy->canManage());
    self::assertFalse($policy->canSeeAll('mis_tickets'));
    self::assertFalse($policy->seesTeam('mis_tickets', []), 'Sin el alcance de equipo, el panel fija el filtro al funcionario actual.');
    self::assertTrue($policy->canSeeAll('abiertos'), 'El administrador sigue viendo todo el equipo en las demás pestañas.');
  }

  public function testTheMenuNumberIsWhatThePersonFindsWhenEntering(): void
  {
    [$db, $policy] = $this->adminWithTasks();
    $repository = new CommercialTicketsRepository($db);
    $filters = ['tab' => 'mis_tickets', 'id_empleado' => '999'];

    $navigation = $repository->scopedNavigation('mis_tickets', $filters, '999', $policy);
    $result = $repository->search('mis_tickets', $filters);

    self::assertSame(3, $navigation['tabs']['mis_tickets']);
    self::assertSame(3, $result['pagination']['total']);
    self::assertSame(['999'], array_values(array_unique(array_column($result['rows'], 'id_empleado'))));
    self::assertSame(8, $navigation['tabs']['abiertos'], 'Tareas abiertas conserva el total del equipo.');
  }

  public function testPortfolioOnlyNarrowsTheScope(): void
  {
    [, $policy] = $this->adminWithTasks();
    self::assertTrue($policy->seesTeam('actualizaciones', []));
    self::assertFalse($policy->seesTeam('actualizaciones', ['portafolio' => '1']));
    self::assertTrue($policy->seesTeam('actualizaciones', ['portafolio' => '0']));

    $_SESSION['scm_user_cargo'] = '9';
    self::assertFalse($policy->seesTeam('actualizaciones', []));
    self::assertFalse($policy->seesTeam('actualizaciones', ['portafolio' => '0']), 'El parámetro no da alcance de equipo.');
  }

  public function testPortfolioCountsDoNotReplaceTheTeamCountsOfTheMenu(): void
  {
    [$db, $policy] = $this->adminWithTasks();
    $repository = new CommercialTicketsRepository($db);
    $navigation = $repository->scopedNavigation('actualizaciones', ['tab' => 'actualizaciones', 'portafolio' => '1', 'id_empleado' => '999'], '999', $policy);
    self::assertSame(8, $navigation['tabs']['abiertos']);
  }

  public function testMenuGroupsThePersonalEntriesInMyPortfolio(): void
  {
    [, $policy] = $this->adminWithTasks();
    $views = array_keys(CommercialAccessPolicy::VIEWS);
    $html = CommercialDashboardView::renderTabs($views, 'inicio', [], ['mis_tickets' => 3], 'http://x', [], $policy);

    self::assertStringContainsString('data-commercial-menu-section="portafolio"', $html);
    self::assertStringContainsString('Mi portafolio', $html);
    foreach (['Mis tareas', 'Mis inmuebles', 'Mis actualizaciones', 'Mis avisos en fachada', 'Mis precaptaciones'] as $label) {
      self::assertStringContainsString($label, $html);
    }
    foreach (['tab=actualizaciones&amp;portafolio=1', 'tab=avisos&amp;portafolio=1', 'tab=precaptacion&amp;portafolio=1', 'tab=inmuebles&amp;property_subtab=mis_inmuebles'] as $url) {
      self::assertStringContainsString($url, htmlspecialchars(html_entity_decode($html)));
    }
    self::assertStringNotContainsString('<span>Mis Tareas</span>', $html, 'Mis tareas ya no se repite en Gestión de tareas.');
  }

  public function testMenuOnlyOffersThePortfolioEntriesTheCargoCanOpen(): void
  {
    [, $policy] = $this->adminWithTasks();
    $html = CommercialDashboardView::renderTabs(['inicio', 'mis_tickets'], 'inicio', [], [], 'http://x', [], $policy);
    self::assertStringContainsString('Mis tareas', $html);
    self::assertStringNotContainsString('Mis actualizaciones', $html);
    self::assertStringNotContainsString('Mis precaptaciones', $html);
    self::assertStringNotContainsString('data-commercial-menu-section="tareas"', $html);
  }

  public function testMyTasksPageNeverShowsTheTeamAudit(): void
  {
    [, $policy] = $this->adminWithTasks();
    $sla = ['atrasados' => 2, 'porcentaje_cumplimiento' => 50];
    $home = ['sla_summary' => ['atrasados' => 163], 'overdue_advisors' => [['id' => '900', 'name' => 'Otra persona', 'count' => 9]]];

    $mine = CommercialDashboardView::renderTickets('mis_tickets', ['sla_summary' => $sla, 'effective_bucket' => 'abiertos'], [], [], [], [], $policy, 'http://x', $home);
    self::assertStringContainsString('Ver mis atrasadas (2)', $mine);
    self::assertStringContainsString('tab=mis_tickets&amp;sla_filter=atrasado', htmlspecialchars(html_entity_decode($mine)));
    self::assertStringNotContainsString('btn-open-drawer', $mine);
    self::assertStringNotContainsString('Otra persona', $mine);

    $postponed = CommercialDashboardView::renderTickets('mis_tickets', ['effective_bucket' => 'postergados'], [], [], [], [], $policy, 'http://x', $home);
    self::assertStringNotContainsString('(163)', $postponed, 'Sin indicadores propios no se usan los del equipo.');

    $team = CommercialDashboardView::renderTickets('abiertos', ['sla_summary' => $sla], [], [], [], [], $policy, 'http://x', $home);
    self::assertStringContainsString('btn-open-drawer', $team);
  }

  public function testPortfolioUpdatesAndSignsHideTheTeamControls(): void
  {
    [, $policy] = $this->adminWithTasks();
    foreach (['renderPropertyUpdatesPage' => 'Mis actualizaciones', 'renderSignsPage' => 'Mis avisos en fachada'] as $method => $title) {
      $mine = CommercialDashboardView::$method(['portafolio' => '1'], [['id' => '900', 'name' => 'Ana']], [], $policy);
      self::assertStringContainsString($title, $mine);
      self::assertStringContainsString('name="portafolio" value="1"', $mine, 'El filtro de la pantalla conserva el alcance personal.');
      self::assertStringNotContainsString('name="id_empleado"', $mine);

      $team = CommercialDashboardView::$method([], [['id' => '900', 'name' => 'Ana']], [], $policy);
      self::assertStringNotContainsString($title, $team);
      self::assertStringNotContainsString('name="portafolio"', $team);
    }
  }

  public function testMyPrecaptacionesAskThePanelForThePersonalMode(): void
  {
    self::assertStringContainsString('portafolio=1', CommercialDashboardView::renderPrecaptacion('http://x', ['portafolio' => '1']));
    self::assertStringNotContainsString('portafolio', CommercialDashboardView::renderPrecaptacion('http://x', []));
  }
}
