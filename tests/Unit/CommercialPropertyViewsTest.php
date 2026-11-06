<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertiesRepository;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Cada subpestaña de Inmuebles es una vista propia, no la misma pantalla con otro filtro. */
final class CommercialPropertyViewsTest extends TestCase
{
  private const TITLES = [
    'publicos' => 'Inmuebles públicos',
    'pendientes' => 'Pendientes por publicar',
    'no_publicos' => 'Inmuebles no públicos',
    'destacados' => 'Inmuebles destacados',
    'mis_solicitudes' => 'Solicitudes por destacar',
    'mis_inmuebles' => 'Mis inmuebles',
  ];

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

  private function page(string $subtab, $policy, int $total = 2): string
  {
    $options = ['funcionarios' => [['id' => '900', 'name' => 'Ana Pérez']]];
    return CommercialDashboardView::renderPropertiesPage(['rows' => [], 'total' => $total, 'pagination' => []], ['property_subtab' => $subtab], $options, $policy, 'http://x');
  }

  public function testEveryPolicySubviewIsAView(): void
  {
    self::assertSame(array_keys(CommercialAccessPolicy::SUBVIEWS['inmuebles']), array_keys(self::TITLES));
  }

  public function testEachViewShowsOnlyItsOwnTitleAndCount(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    foreach (self::TITLES as $subtab => $title) {
      $html = $this->page($subtab, $policy);
      self::assertStringContainsString('data-commercial-property-view="' . $subtab . '"', $html);
      self::assertMatchesRegularExpression('#<h1[^>]*>' . preg_quote($title, '#') . '</h1>#u', $html);
      self::assertStringNotContainsString('Gestión de Portafolio', $html);
      self::assertStringNotContainsString('Total inventario', $html, 'Sin tarjetas globales.');
      self::assertStringNotContainsString('data-commercial-property-subtab=', $html, 'Sin la fila de pestañas: se navega desde el menú.');
      self::assertStringNotContainsString('Sub-pestaña actual', $html);
    }
    self::assertStringContainsString('2 inmuebles', $this->page('mis_inmuebles', $policy));
    self::assertStringNotContainsString(' inmuebles' . "\n", $this->page('mis_solicitudes', $policy), 'Solicitudes no cuenta inmuebles.');
  }

  public function testMyPropertiesHasNoEmployeeFilter(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    self::assertStringContainsString('name="id_funcionario"', $this->page('publicos', $policy));
    self::assertStringNotContainsString('name="id_funcionario"', $this->page('mis_inmuebles', $policy));
  }

  public function testAnEmployeeInTheUrlDoesNotEmptyMyProperties(): void
  {
    [$db] = CommercialNotificationsFixture::make(true);
    $where = new \ReflectionMethod(CommercialPropertiesRepository::class, 'buildWhereClauses');
    $repository = new CommercialPropertiesRepository($db);

    [, $mine] = $where->invoke($repository, ['property_subtab' => 'mis_inmuebles', 'id_funcionario' => '900'], true);
    [, $public] = $where->invoke($repository, ['property_subtab' => 'publicos', 'id_funcionario' => '900'], true);

    self::assertNotContains('900', $mine);
    self::assertContains('900', $public);
  }

  public function testFeaturedViewOnlyOffersPortalFilters(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $featured = $this->page('destacados', $policy);
    self::assertStringContainsString('Todos los portales', $featured);
    self::assertStringNotContainsString('value="no_destacados"', $featured);
    self::assertStringContainsString('value="no_destacados"', $this->page('publicos', $policy));
  }

  public function testPortalLinkOnlyBelongsToThePublicView(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    self::assertStringContainsString('Ver Portal Web', $this->page('publicos', $policy));
    self::assertStringNotContainsString('Ver Portal Web', $this->page('mis_inmuebles', $policy));
  }

  public function testMobileMenuListsTheViewsTheCargoCanOpen(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderTabs(['inicio', 'inmuebles'], 'inicio', [], [], 'http://x', [], $policy);
    $mobile = substr($html, (int) strpos($html, 'data-commercial-dropdown-menu="mobile"'));
    foreach (array_diff_key(self::TITLES, ['mis_inmuebles' => true]) as $title) {
      self::assertStringContainsString('>' . $title . '</a>', $mobile);
    }
    self::assertSame(1, substr_count($mobile, 'property_subtab=mis_inmuebles'), 'Mis inmuebles sale una vez, en Mi portafolio.');

    $_SESSION['scm_user_cargo'] = '9';
    $_SESSION['scm_employee_id'] = '900';
    $own = CommercialDashboardView::renderTabs(['inicio', 'inmuebles'], 'inicio', [], [], 'http://x', [], $policy);
    self::assertStringContainsString('>Mis inmuebles destacados</a>', substr($own, (int) strpos($own, 'data-commercial-dropdown-menu="mobile"')));
  }

  /** Inventario de dos funcionarios: 999 (administrador de la sesión) y 900 (consultora). */
  private function inventory(): array
  {
    [$db, $policy] = CommercialNotificationsFixture::make(true);
    $pdo = $db->pdo();
    foreach (['estado', 'codigo', 'promocion_premium', 'destacado', 'mercado_libre_destacado', 'ciencuadras_destacado', 'ciencuadras_ascendido', 'finca_raiz_silver', 'finca_raiz_gold', 'finca_raiz_black', 'proppit_promocionado'] as $column) {
      $pdo->exec("ALTER TABLE wp_jet_cct_inmuebles ADD COLUMN `{$column}` TEXT");
    }
    $pdo->exec("DELETE FROM wp_jet_cct_inmuebles");
    foreach ([['999', 'Publico', 'No'], ['999', 'En borrador', 'No'], ['999', 'Arrendado', 'Si'], ['900', 'Publico', 'Si'], ['900', 'Publico', 'No'], ['900', 'En borrador', 'No'], ['900', 'Arrendado', 'No']] as [$employee, $status, $featured]) {
      $db->insert('wp_jet_cct_inmuebles', ['id_funcionario' => $employee, 'estado' => $status, 'destacado' => $featured]);
    }
    $pdo->exec('CREATE TABLE wp_skc_destacado_solicitudes (solicitado_por_id TEXT, estado TEXT)');
    $db->insert('wp_skc_destacado_solicitudes', ['solicitado_por_id' => '999', 'estado' => 'pendiente']);
    $db->insert('wp_skc_destacado_solicitudes', ['solicitado_por_id' => '999', 'estado' => 'destacado']);
    $db->insert('wp_skc_destacado_solicitudes', ['solicitado_por_id' => '900', 'estado' => 'pendiente']);
    return [$db, $policy];
  }

  public function testEveryMenuCountIsWhatItsViewOpens(): void
  {
    [$db] = $this->inventory();
    $repository = new CommercialPropertiesRepository($db);
    $where = new \ReflectionMethod(CommercialPropertiesRepository::class, 'buildWhereClauses');
    $viewTotal = static function (string $subtab, bool $canSeeAll) use ($db, $repository, $where): int {
      [$clauses, $args] = $where->invoke($repository, ['property_subtab' => $subtab], $canSeeAll);
      return (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_inmuebles i' . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : ''), $args);
    };

    foreach ([['13', '999', true], ['9', '900', false]] as [$cargo, $employee, $canSeeAll]) {
      $_SESSION['scm_user_cargo'] = $cargo;
      $_SESSION['scm_employee_id'] = $employee;
      $counts = $repository->menuCounts($canSeeAll);
      foreach (['publicos', 'pendientes', 'no_publicos', 'destacados', 'mis_inmuebles'] as $subtab) {
        self::assertSame($viewTotal($subtab, $canSeeAll), $counts["inmuebles.{$subtab}"], "{$subtab} para el empleado {$employee}");
      }
    }
  }

  public function testMenuCountsRespectTheScopeOfTheCargo(): void
  {
    [$db] = $this->inventory();
    $repository = new CommercialPropertiesRepository($db);

    $admin = $repository->menuCounts(true);
    self::assertSame([3, 2, 2, 2, 3, 1], [$admin['inmuebles.publicos'], $admin['inmuebles.pendientes'], $admin['inmuebles.no_publicos'], $admin['inmuebles.destacados'], $admin['inmuebles.mis_inmuebles'], $admin['inmuebles.mis_solicitudes']]);

    $_SESSION['scm_employee_id'] = '900';
    $own = $repository->menuCounts(false);
    self::assertSame(1, $own['inmuebles.destacados'], 'Sin alcance de equipo solo cuenta sus destacados.');
    self::assertSame(4, $own['inmuebles.mis_inmuebles']);
    self::assertSame(3, $own['inmuebles.publicos'], 'Públicos es todo el inventario para cualquier cargo.');
  }

  public function testMenuShowsTheCountsOfEachView(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $counts = ['inmuebles.publicos' => 1940, 'inmuebles.pendientes' => 39, 'inmuebles.no_publicos' => 1896, 'inmuebles.destacados' => 107, 'inmuebles.mis_solicitudes' => 5, 'inmuebles.mis_inmuebles' => 2];
    $html = CommercialDashboardView::renderTabs(array_keys(CommercialAccessPolicy::VIEWS), 'inicio', [], $counts, 'http://x', [], $policy);
    $menu = substr($html, (int) strpos($html, 'data-commercial-dropdown-menu="inmuebles"'));
    foreach (['1,940', '39', '1,896', '107', '5', '2'] as $count) {
      self::assertStringContainsString('font-semibold">' . $count . '</span>', $menu);
    }
    self::assertStringNotContainsString('>Portal</span>', $menu);
    $portfolio = substr($html, (int) strpos($html, 'data-commercial-menu-section="portafolio"'), 3000);
    self::assertMatchesRegularExpression('#Mis inmuebles</span>\s*</span>\s*<span[^>]*>2</span>#u', $portfolio);
  }

  public function testMenuKeepsWorkingWhenTheCountsCannotBeCalculated(): void
  {
    [$db, $policy] = CommercialNotificationsFixture::make(true);
    self::assertSame([], @(new CommercialPropertiesRepository($db))->menuCounts(true), 'La tabla no tiene las columnas: no debe tumbar el panel.');

    $html = CommercialDashboardView::renderTabs(array_keys(CommercialAccessPolicy::VIEWS), 'inicio', [], [], 'http://x', [], $policy);
    self::assertStringContainsString('>Portal</span>', $html);
  }

  public function testMyStatusCountsAreOnlyMineFromPublicToClosed(): void
  {
    [$db] = $this->inventory();
    $db->insert('wp_jet_cct_inmuebles', ['id_funcionario' => '999', 'estado' => 'Publico', 'destacado' => 'No']);
    $db->insert('wp_jet_cct_inmuebles', ['id_funcionario' => '999', 'estado' => ' Vendido ', 'destacado' => 'No']);
    $repository = new CommercialPropertiesRepository($db);
    $expected = ['Publico' => 2, 'En borrador' => 1, 'Arrendado' => 1, 'Vendido' => 1];

    self::assertSame($expected, $repository->myStatusCounts([], true));
    self::assertSame($expected, $repository->myStatusCounts([], false), 'Es de la persona para cualquier cargo.');
    self::assertSame($expected, $repository->myStatusCounts(['estado' => 'Arrendado'], true), 'El estado elegido no cambia los conteos de las pestañas.');
  }

  public function testMyPropertiesAreSeparatedByStatus(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $counts = ['Publico' => 1, 'En cierre' => 1];
    $page = static fn(array $filters): string => CommercialDashboardView::renderPropertiesPage(['rows' => [], 'total' => 2, 'status_counts' => $counts, 'pagination' => []], ['property_subtab' => 'mis_inmuebles'] + $filters, [], $policy, 'http://x');

    $all = $page([]);
    self::assertStringContainsString('data-commercial-property-states', $all);
    foreach (['Todos', 'Público', 'En cierre'] as $label) {
      self::assertStringContainsString('<span>' . $label . '</span>', $all);
    }
    self::assertStringContainsString('estado=En+cierre', html_entity_decode($all));
    self::assertMatchesRegularExpression('#aria-current="true"[^>]*>\s*<span>Todos</span>#u', $all);
    self::assertStringNotContainsString('type="hidden" name="estado"', $all);
    $states = substr($all, (int) strpos($all, 'data-commercial-property-states'), 1500);
    self::assertStringNotContainsString('data-commercial-tab', $states, 'setActiveTab pisaría el aria-current de las pestañas de estado.');

    $closing = $page(['estado' => 'En cierre']);
    self::assertMatchesRegularExpression('#aria-current="true"[^>]*>\s*<span>En cierre</span>#u', $closing);
    self::assertStringContainsString('type="hidden" name="estado" value="En cierre"', $closing, 'Filtrar no pierde la pestaña de estado.');
  }

  public function testOnlyMyPropertiesAreSeparatedByStatus(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderPropertiesPage(['rows' => [], 'total' => 2, 'status_counts' => ['Publico' => 2], 'pagination' => []], ['property_subtab' => 'publicos'], [], $policy, 'http://x');
    self::assertStringNotContainsString('data-commercial-property-states', $html);
    self::assertStringNotContainsString('data-commercial-property-states', $this->page('mis_inmuebles', $policy), 'Sin inmuebles no hay pestañas vacías.');
  }

  public function testMenuHasNoLinkToAViewThatDoesNotExist(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderTabs(array_keys(CommercialAccessPolicy::VIEWS), 'inicio', [], [], 'http://x', [], $policy);
    preg_match_all('/property_subtab=([a-z_]+)/', $html, $links);
    self::assertSame([], array_values(array_diff(array_unique($links[1]), array_keys(CommercialAccessPolicy::SUBVIEWS['inmuebles']))));
  }
}
