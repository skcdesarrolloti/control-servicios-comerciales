<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertiesRepository;
use SCM\Core\Database;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Cambiar el estado de un inmueble desde su tarjeta: CCT, post type e historial, con su permiso. */
final class CommercialPropertyStatusTest extends TestCase
{
  private Database $db;
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE wp_jet_cct_inmuebles (_ID INTEGER PRIMARY KEY, codigo TEXT, estado TEXT, id_funcionario TEXT, cct_modified TEXT)');
    $pdo->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble (_ID INTEGER PRIMARY KEY AUTOINCREMENT, fecha TEXT, id_inmueble TEXT, id_empleado TEXT, funcionario TEXT, tipo_reporte TEXT, observacion TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT)');
    $this->db = new Database($pdo);
    $this->db->insert('wp_jet_cct_inmuebles', ['_ID' => 1, 'codigo' => '96957', 'estado' => 'Publico', 'id_funcionario' => '900']);
    $this->db->insert('wp_jet_cct_inmuebles', ['_ID' => 2, 'codigo' => '84016', 'estado' => 'En cierre', 'id_funcionario' => '901']);
    $this->db->insert('wp_postmeta', ['post_id' => 96957, 'meta_key' => 'estado', 'meta_value' => 'Publico']);
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function repository(): CommercialPropertiesRepository
  {
    return new CommercialPropertiesRepository($this->db);
  }

  public function testChangesTheStateInTheCctAndThePostTypeWithHistory(): void
  {
    $result = $this->repository()->changeStatus('96957', 'En cierre', 'Cliente firmó promesa', '900', 'Ana Pérez');

    self::assertTrue($result['ok']);
    self::assertSame(['En cierre', 'Publico'], [$result['estado'], $result['anterior']]);
    self::assertSame('En cierre', $this->db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE _ID = 1'));
    self::assertNotEmpty($this->db->getVar('SELECT cct_modified FROM wp_jet_cct_inmuebles WHERE _ID = 1'));
    self::assertSame('En cierre', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 96957 AND meta_key = 'estado'"));
    self::assertSame('1', (string) $this->db->getVar("SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 96957 AND meta_key = 'estado'"), 'Actualiza el meta, no lo duplica.');

    $history = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_del_inmueble');
    self::assertSame(['96957', '900', 'Cambio de estado', '900'], [$history['id_inmueble'], $history['id_empleado'], $history['tipo_reporte'], $history['cct_author_id']]);
    self::assertStringContainsString('"Publico" → "En cierre"', $history['observacion']);
    self::assertStringContainsString('Cliente firmó promesa', $history['observacion']);
  }

  public function testCreatesThePostMetaWhenTheProperyHasNone(): void
  {
    self::assertTrue($this->repository()->changeStatus('84016', 'Arrendado', '', '999', 'Administrador')['ok']);
    self::assertSame('Arrendado', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 84016 AND meta_key = 'estado'"));
    self::assertSame('Arrendado', $this->db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE _ID = 2'));
  }

  public function testRejectsInvalidStatesRepeatedStatesAndOtherPeoplesProperties(): void
  {
    $repository = $this->repository();
    self::assertFalse($repository->changeStatus('96957', 'Inventado', '', '900', 'Ana')['ok']);
    self::assertFalse($repository->changeStatus('96957', 'Publico', '', '900', 'Ana')['ok']);
    self::assertFalse($repository->changeStatus('00000', 'Vendido', '', '900', 'Ana')['ok']);
    self::assertFalse($repository->changeStatus('84016', 'Vendido', '', '900', 'Ana', '900')['ok'], 'Sin ver todo el inventario, solo sus inmuebles.');
    self::assertFalse($repository->changeStatus('96957', 'Vendido', '', '', 'Ana', '')['ok'], 'Si no se sabe quién es, no cambia nada.');
    self::assertSame('En cierre', $this->db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE _ID = 2'));
    self::assertSame('0', (string) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble'));

    self::assertTrue($repository->changeStatus('96957', 'Vendido', '', '900', 'Ana', '900')['ok'], 'Su propio inmueble sí.');
  }

  public function testTheActionIsAnOptInPermissionWithItsEndpoint(): void
  {
    self::assertArrayHasKey('inmueble_cambiar_estado', CommercialAccessPolicy::ACTIONS);
    self::assertContains('inmueble_cambiar_estado', CommercialAccessPolicy::OPT_IN_ACTIONS);
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertContains('inmueble_cambiar_estado', $groups['Inmuebles y avisos']);
    $route = new \ReflectionMethod(CommercialActionRouter::class, 'controllerMethod');
    self::assertSame('propertyChangeStatus', $route->invoke(null, 'commercial_property_change_status'));
  }

  public function testTheCardShowsTheButtonOnlyToWhoCanChangeIt(): void
  {
    $rows = [
      ['_ID' => 1, 'codigo' => '96957', 'estado' => 'Publico', 'id_funcionario' => '900'],
      ['_ID' => 2, 'codigo' => '84016', 'estado' => 'En cierre', 'id_funcionario' => '901'],
    ];

    [, $admin] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderPropertyCards($rows, $admin);
    self::assertStringContainsString('data-commercial-property-status="96957"', $html);
    self::assertStringContainsString('data-commercial-property-status="84016"', $html);
    self::assertStringContainsString('data-current="En cierre"', $html);
    self::assertStringContainsString('&quot;No publicar&quot;', $html, 'Lleva los estados que se pueden elegir.');

    [, $consultant] = CommercialNotificationsFixture::make(false);
    self::assertStringNotContainsString('data-commercial-property-status', CommercialDashboardView::renderPropertyCards($rows, $consultant), 'Sin el permiso no hay botón.');
  }
}
