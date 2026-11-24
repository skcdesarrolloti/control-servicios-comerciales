<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertyHistory;
use SCM\Commercial\CommercialPropertyTransfer;
use SCM\Core\Database;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Historial del inmueble y Transferir inmueble desde la tarjeta: cálculo, permisos y vista. */
final class CommercialPropertyHistoryTransferTest extends TestCase
{
  private Database $db;
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    $tables = [
      'wp_jet_cct_inmuebles' => '_ID INTEGER PRIMARY KEY, codigo TEXT, estado TEXT, id_funcionario TEXT, funcionario TEXT, direccion TEXT, barrio TEXT, ciudad TEXT, propietario TEXT, tipo_inmueble TEXT, tipo_negocio TEXT, destinacion TEXT, cct_modified TEXT',
      'wp_postmeta' => 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT',
      'wp_jet_cct_historial_del_inmueble' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, fecha TEXT, id_inmueble TEXT, id_ticket TEXT, id_empleado TEXT, funcionario TEXT, tipo_reporte TEXT, observacion TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT',
      'wp_jet_cct_tickets' => '_ID INTEGER PRIMARY KEY, id_inmueble TEXT, asunto TEXT, estado TEXT, fecha TEXT, cct_created TEXT',
      'wp_jet_cct_contratos_arrendamiento' => '_ID INTEGER PRIMARY KEY, contrato TEXT, id_inmueble TEXT, arrendatario TEXT, estado TEXT, canon TEXT, fecha_inicio TEXT, cct_created TEXT',
      'wp_jet_cct_funcionarios' => '_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, rol TEXT, correo TEXT, celular TEXT, activo TEXT',
      'wp_jet_rel_941' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, created TEXT, rel_id INTEGER, parent_rel INTEGER, parent_object_id TEXT, child_object_id TEXT',
    ];
    foreach ($tables as $table => $definition) {
      $pdo->exec("CREATE TABLE `{$table}` ({$definition})");
      $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
      foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
        $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
      }
    }
    $this->db = new Database($pdo);
    $this->db->insert('wp_jet_cct_inmuebles', ['_ID' => 1, 'codigo' => '96957', 'estado' => 'Publico', 'id_funcionario' => '900', 'funcionario' => 'Ana Pérez', 'direccion' => 'Barrio La Granja, Manzana L', 'barrio' => 'Turbaco', 'ciudad' => 'Cartagena', 'tipo_inmueble' => 'Apartamento', 'tipo_negocio' => 'Venta']);
    $this->db->insert('wp_jet_cct_historial_del_inmueble', ['fecha' => (string) strtotime('2026-10-01 10:00:00'), 'id_inmueble' => '96957', 'tipo_reporte' => 'Captación', 'observacion' => '<b>Información</b> del inmueble ingresada.']);
    $this->db->insert('wp_jet_cct_tickets', ['_ID' => 55, 'id_inmueble' => '96957', 'asunto' => 'Visita de interesado', 'estado' => 'Abierto', 'fecha' => (string) strtotime('2026-11-02 09:00:00')]);
    $this->db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 7, 'contrato' => '1201', 'id_inmueble' => '96957', 'arrendatario' => 'Laura Gómez', 'estado' => 'Entregado', 'canon' => '1.800.000', 'fecha_inicio' => '2026-01-01']);
    $this->db->insert('wp_jet_cct_historial_del_inmueble', ['fecha' => '1700000000', 'id_inmueble' => '84016', 'tipo_reporte' => 'Otro inmueble', 'observacion' => 'No debe salir']);
    foreach ([['900', 'Ana Pérez', 'Si'], ['901', 'Royner Guardo', 'Si'], ['902', 'Retirado', 'No']] as $i => [$id, $name, $active]) {
      $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => $i + 1, 'id_empleado' => $id, 'nombre' => $name, 'correo' => 'f' . $id . '@example.test', 'celular' => '300000' . $id, 'activo' => $active]);
    }
    $this->db->insert('wp_postmeta', ['post_id' => 96957, 'meta_key' => 'id_empleado', 'meta_value' => '900']);
    $this->db->insert('wp_jet_rel_941', ['created' => '2026-01-01', 'rel_id' => 941, 'parent_rel' => 0, 'parent_object_id' => '900', 'child_object_id' => '96957']);
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  public function testTheHistoryJoinsEverySourceOfTheProperty(): void
  {
    $history = new CommercialPropertyHistory($this->db);
    $payload = $history->payload('96957');

    self::assertSame('96957', $payload['property']['codigo']);
    self::assertSame('Apartamento / Venta', $payload['property']['tipo']);
    self::assertSame(['historial' => 1, 'tickets' => 1, 'contratos_arrendamiento' => 1], array_filter($payload['counts']));
    self::assertSame('Caso #55 - Visita de interesado', $payload['timeline'][0]['title'], 'La cronología va de lo más reciente a lo más antiguo.');
    self::assertSame('$1.800.000', $payload['contracts'][0]['canon']);
    self::assertSame('Información del inmueble ingresada.', $payload['sections'][0]['items'][0]['detail'], 'Sin etiquetas HTML.');

    $html = $history->render($payload);
    self::assertStringContainsString('data-phr', $html);
    self::assertStringContainsString('Historial del inmueble <span>#96957</span>', $html);
    self::assertStringContainsString('Laura Gómez', $html);
    self::assertStringNotContainsString('No debe salir', $html);
    self::assertSame('historial-inmueble-96957.pdf', CommercialPropertyHistory::filename($payload));
  }

  public function testAnUnknownPropertyHasNoHistory(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    (new CommercialPropertyHistory($this->db))->payload('00000');
  }

  public function testTransferUpdatesThePostTypeTheCctTheRelationAndTheHistory(): void
  {
    $result = (new CommercialPropertyTransfer($this->db))->transfer('96957', '901', '999');

    self::assertTrue($result['ok']);
    self::assertSame('Royner Guardo', $result['funcionario']);
    $meta = array_column($this->db->getResults("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 96957"), 'meta_value', 'meta_key');
    self::assertSame(['id_empleado' => '901', 'empleado' => 'Royner Guardo', 'email-empleado' => 'f901@example.test', 'celular-empleado' => '300000901'], $meta);
    self::assertSame('1', (string) $this->db->getVar("SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 96957 AND meta_key = 'id_empleado'"), 'Actualiza el meta, no lo duplica.');
    $property = $this->db->getRow('SELECT id_funcionario, funcionario FROM wp_jet_cct_inmuebles WHERE _ID = 1');
    self::assertSame(['id_funcionario' => '901', 'funcionario' => 'Royner Guardo'], $property);
    self::assertSame(['900', '901'], array_column($this->db->getResults('SELECT parent_object_id FROM wp_jet_rel_941 WHERE child_object_id = ? ORDER BY _ID', ['96957']), 'parent_object_id'), 'La relación 941 se agrega (append), como el formulario.');
    $history = $this->db->getRow("SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Traslado'");
    self::assertSame(['901', '96957', 'Su inmueble ha sido trasladado a Royner Guardo', 'Royner Guardo', '999', 'publish'], [$history['id_empleado'], $history['id_inmueble'], $history['observacion'], $history['funcionario'], $history['cct_author_id'], $history['cct_status']]);
  }

  public function testTransferRejectsTheSamePersonUnknownPeopleAndOtherPeoplesProperties(): void
  {
    $transfer = new CommercialPropertyTransfer($this->db);
    self::assertFalse($transfer->transfer('96957', '900', '999')['ok']);
    self::assertFalse($transfer->transfer('96957', '777', '999')['ok']);
    self::assertFalse($transfer->transfer('96957', '', '999')['ok']);
    self::assertFalse($transfer->transfer('00000', '901', '999')['ok']);
    self::assertFalse($transfer->transfer('96957', '901', '901', '901')['ok'], 'Sin ver todo el inventario, solo sus inmuebles.');
    self::assertSame('900', $this->db->getVar('SELECT id_funcionario FROM wp_jet_cct_inmuebles WHERE _ID = 1'));
    self::assertSame('0', (string) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Traslado'"));
  }


  public function testTheTransferListOnlyHasActiveEmployees(): void
  {
    self::assertSame([['id' => '900', 'name' => 'Ana Pérez'], ['id' => '901', 'name' => 'Royner Guardo']], (new CommercialPropertyTransfer($this->db))->employees());
  }

  public function testActionsArePermissionsWithTheirEndpoints(): void
  {
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    $route = new \ReflectionMethod(CommercialActionRouter::class, 'controllerMethod');
    foreach (['inmueble_ver_historial', 'inmueble_transferir'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
      self::assertContains($action, CommercialAccessPolicy::OPT_IN_ACTIONS);
      self::assertContains($action, $groups['Inmuebles y avisos']);
    }
    foreach (['commercial_property_history' => 'propertyHistory', 'commercial_property_history_pdf' => 'propertyHistoryPdf', 'commercial_property_transfer_options' => 'propertyTransferOptions', 'commercial_property_transfer' => 'propertyTransfer'] as $action => $method) {
      self::assertSame($method, $route->invoke(null, $action));
    }
  }

  public function testTheCardShowsHistoryAndTransferOnlyWithThePermission(): void
  {
    $rows = [['_ID' => 1, 'codigo' => '96957', 'estado' => 'Publico', 'id_funcionario' => '900', 'funcionario_nombre' => 'Ana Pérez']];

    [, $admin] = CommercialNotificationsFixture::make(true);
    $html = CommercialDashboardView::renderPropertyCards($rows, $admin);
    self::assertStringContainsString('data-commercial-property-history="96957"', $html);
    self::assertStringContainsString('data-commercial-property-transfer="96957"', $html);
    self::assertStringContainsString('data-current-name="Ana Pérez"', $html);

    [, $consultant] = CommercialNotificationsFixture::make(false);
    $html = CommercialDashboardView::renderPropertyCards($rows, $consultant);
    self::assertStringNotContainsString('data-commercial-property-history', $html);
    self::assertStringNotContainsString('data-commercial-property-transfer', $html);
  }
}
