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

  public function testMenuHasNoLinkToAViewThatDoesNotExist(): void
  {
    [, $policy] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderTabs(array_keys(CommercialAccessPolicy::VIEWS), 'inicio', [], [], 'http://x', [], $policy);
    preg_match_all('/property_subtab=([a-z_]+)/', $html, $links);
    self::assertSame([], array_values(array_diff(array_unique($links[1]), array_keys(CommercialAccessPolicy::SUBVIEWS['inmuebles']))));
  }
}
