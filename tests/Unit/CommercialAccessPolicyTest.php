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
      ['rows' => [], 'total' => 0, 'pagination' => []], ['property_subtab' => 'pendientes'], [], [], $policy, ''
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
    self::assertStringNotContainsString('data-commercial-dropdown="tareas"', $html);
  }
}
