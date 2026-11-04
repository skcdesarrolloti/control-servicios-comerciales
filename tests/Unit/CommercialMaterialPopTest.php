<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Promotion\Pop\PopConfig;
use SCM\Commercial\Promotion\Pop\PopInventoryService;
use SCM\Commercial\Promotion\Pop\PopStockAlert;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\Promotion\MaterialPopView;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Promoción › Material POP, traído de dashboard-marketing (inventario de souvenirs con el Club PPH). */
final class CommercialMaterialPopTest extends TestCase
{
  private array $previousSession;
  private Database $db;
  private Settings $settings;
  /** @var list<array{emails:list<string>,subject:string,options:array<string,mixed>}> */
  public array $sent = [];

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    CommercialPromotionDatabase::pop($this->db, $this->settings);
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

  private function service(): PopInventoryService
  {
    $test = $this;
    $alert = new class ($this->db, $this->settings, $test) extends PopStockAlert {
      private CommercialMaterialPopTest $test;

      public function __construct(Database $db, Settings $settings, CommercialMaterialPopTest $test)
      {
        parent::__construct($db, $settings);
        $this->test = $test;
      }

      protected function send(array $emails, string $subject, string $html, array $options): void
      {
        $this->test->sent[] = ['emails' => $emails, 'subject' => $subject, 'options' => $options];
      }
    };
    return new PopInventoryService($this->db, $this->settings, null, $alert);
  }

  /** @return array<string,mixed> */
  private function product(string $name): array
  {
    return $this->db->getRow('SELECT * FROM wp_marketing_inventory_products WHERE name = ?', [$name]) ?? [];
  }

  public function testMaterialPopIsAPromotionSubtabWithOptInActions(): void
  {
    self::assertSame('Material POP', CommercialAccessPolicy::SUBVIEWS['promocion']['material_pop']);
    foreach (['pop_gestionar', 'pop_anular', 'pop_ver_costos'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
      self::assertContains($action, CommercialAccessPolicy::OPT_IN_ACTIONS);
    }
    $groups = (new \ReflectionClassConstant(\SCM\Views\CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertSame(['promocion_ver_ip', 'pop_gestionar', 'pop_anular', 'pop_ver_costos'], $groups['Promoción']);
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'material_pop'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'material_pop']));
    foreach (['commercial_pop_product_save', 'commercial_pop_stock_adjust', 'commercial_pop_product_active', 'commercial_pop_delivery_create', 'commercial_pop_delivery_cancel', 'commercial_pop_sync'] as $action) {
      self::assertArrayHasKey($action, CommercialActionRouter::PROMOTION_ACTIONS);
    }
    self::assertContains('material_pop', CommercialSettingsService::SECTIONS);

    // 7 ve la subpestaña (Promoción sin subpestañas configuradas) pero no opera; 6 gestiona y anula sin ver costos.
    CommercialPromotionDatabase::login('7', '303');
    $policy = $this->policy();
    self::assertTrue($policy->canSubview('promocion', 'material_pop'));
    self::assertFalse($policy->canAct('pop_gestionar'));
    CommercialPromotionDatabase::login('6', '202');
    $policy = $this->policy();
    self::assertTrue($policy->canAct('pop_gestionar'));
    self::assertTrue($policy->canAct('pop_anular'));
    self::assertFalse($policy->canAct('pop_ver_costos'));
    CommercialPromotionDatabase::login('8', '202');
    self::assertFalse($this->policy()->canSubview('promocion', 'material_pop'));
    CommercialPromotionDatabase::login('10', '909');
    self::assertFalse($this->policy()->canAct('pop_gestionar'));
  }

  public function testPphSyncImportsOnceAndOnlyWritesWhenSomethingChanged(): void
  {
    $service = $this->service();
    self::assertSame(['products_created' => 2, 'products_updated' => 0, 'deliveries_imported' => 1], $service->syncFromPph());
    $cortadora = $this->product('CORTADORA DE PELO');
    self::assertSame(['PPH', 3, 1, 1, '40000.00', 40], [$cortadora['type'], (int) $cortadora['quantity'], (int) $cortadora['active'], (int) $cortadora['sync_pph'], number_format((float) $cortadora['price'], 2, '.', ''), (int) $cortadora['points']]);
    self::assertSame(0, (int) $this->product('LÁPIZ LABIAL')['active'], 'Un producto en borrador de PPH llega archivado.');
    $reward = $this->db->getRow("SELECT * FROM wp_marketing_inventory_deliveries WHERE source = 'pph'");
    self::assertSame(['Aliado Uno', 'Club PPH', 'pph-reward-1'], [$reward['recipient_name'], $reward['recipient_type'], $reward['external_id']]);

    // Sin cambios no abre transacción ni escribe nada.
    $movements = (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_inventory_movements');
    self::assertSame(['products_created' => 0, 'products_updated' => 0, 'deliveries_imported' => 0], $service->syncFromPph());
    self::assertSame($movements, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_inventory_movements'));

    // Cambian las existencias en PPH: se concilian con un movimiento.
    $this->db->update('wp_jet_cct_inventario_pph', ['cantidad' => '5'], ['_ID' => 1]);
    self::assertSame(1, $service->syncFromPph('101')['products_updated']);
    $last = $this->db->getRow('SELECT * FROM wp_marketing_inventory_movements ORDER BY id DESC LIMIT 1');
    self::assertSame(['pph_reconciliation', 2, 3, 5, 'pph'], [$last['movement_type'], (int) $last['quantity_delta'], (int) $last['stock_before'], (int) $last['stock_after'], $last['source']]);
  }

  public function testDeliveryDiscountsAllOrNothingAndCancelRestoresWithTrace(): void
  {
    $service = $this->service();
    $service->syncFromPph();
    $llaveros = (int) $this->product('Llaveros')['id'];
    $gorras = (int) $this->product('Gorras')['id'];
    $cortadora = $this->product('CORTADORA DE PELO');

    try {
      $service->createDelivery(['recipient_name' => 'Laura Gómez', 'delivered_at' => '2026-11-03 10:00', 'items' => json_encode([['product_id' => $llaveros, 'quantity' => 2], ['product_id' => $gorras, 'quantity' => 99]])], '202');
      self::fail('Debe rechazar la entrega sin existencias suficientes.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('No hay existencias suficientes de "Gorras": hay 40.', $exception->getMessage());
    }
    self::assertSame(12, (int) $this->product('Llaveros')['quantity'], 'Si un producto no alcanza no se descuenta ninguno.');
    self::assertSame(0, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_marketing_inventory_deliveries WHERE source = 'comercial'"));

    $id = $service->createDelivery([
      'recipient_name' => 'Laura Gómez', 'recipient_type' => 'Cliente', 'recipient_document' => '1001', 'delivered_at' => '2026-11-03T10:00',
      'items' => [['product_id' => $llaveros, 'quantity' => 2], ['product_id' => $gorras, 'quantity' => 5], ['product_id' => $llaveros, 'quantity' => 1], ['product_id' => (int) $cortadora['id'], 'quantity' => 1]],
    ], '202');
    self::assertSame([9, 35, 2], [(int) $this->product('Llaveros')['quantity'], (int) $this->product('Gorras')['quantity'], (int) $this->product('CORTADORA DE PELO')['quantity']]);
    self::assertSame('2', (string) $this->db->getVar('SELECT cantidad FROM wp_jet_cct_inventario_pph WHERE _ID = 1'), 'Las salidas de productos sincronizados se reflejan en PPH.');
    $delivery = $this->db->getRow('SELECT * FROM wp_marketing_inventory_deliveries WHERE id = ?', [$id]);
    self::assertSame(['comercial', 202, '2026-11-03 10:00:00', 'Cliente'], [$delivery['source'], (int) $delivery['created_by'], $delivery['delivered_at'], $delivery['recipient_type']]);
    self::assertSame(3, (int) $this->db->getVar('SELECT quantity FROM wp_marketing_inventory_delivery_items WHERE delivery_id = ? AND product_id = ?', [$id, $llaveros]));

    try {
      $service->cancelDelivery($id, '  ', '202');
      self::fail('Anular exige motivo.');
    } catch (\InvalidArgumentException) {
    }
    $service->cancelDelivery($id, 'Se registró dos veces', '202');
    self::assertSame([12, 40, 3], [(int) $this->product('Llaveros')['quantity'], (int) $this->product('Gorras')['quantity'], (int) $this->product('CORTADORA DE PELO')['quantity']]);
    $delivery = $this->db->getRow('SELECT * FROM wp_marketing_inventory_deliveries WHERE id = ?', [$id]);
    self::assertSame(['cancelled', 'Se registró dos veces', 202], [$delivery['status'], $delivery['cancellation_reason'], (int) $delivery['cancelled_by']]);
    self::assertSame(3, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_marketing_inventory_movements WHERE delivery_id = ? AND movement_type = 'reversal'", [$id]));

    $this->expectExceptionMessage('Las entregas importadas de recompensas PPH no se anulan desde aquí.');
    $service->cancelDelivery((int) $this->db->getVar("SELECT id FROM wp_marketing_inventory_deliveries WHERE source = 'pph'"), 'Prueba', '202');
  }

  public function testProductsAdjustmentsArchiveAndPphPublishing(): void
  {
    $service = $this->service();
    $llaveros = (int) $this->product('Llaveros')['id'];
    $service->adjustStock($llaveros, -3, 'Se dañaron en bodega', '202');
    $last = $this->db->getRow('SELECT * FROM wp_marketing_inventory_movements ORDER BY id DESC LIMIT 1');
    self::assertSame(['adjustment_out', -3, 12, 9, 'Se dañaron en bodega', 'comercial', 202], [$last['movement_type'], (int) $last['quantity_delta'], (int) $last['stock_before'], (int) $last['stock_after'], $last['reason'], $last['source'], (int) $last['created_by']]);
    try {
      $service->adjustStock($llaveros, -50, 'Error', '202');
      self::fail('No puede quedar en negativo.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('hay 9 unidad', $exception->getMessage());
    }

    // Sin el permiso de costos el precio no cambia, ni al editar ni al crear.
    $service->saveProduct(['id' => $llaveros, 'name' => 'Llaveros SKC', 'type' => 'Souvenir', 'unit' => 'unidad', 'minimum_stock' => '8', 'price' => '99999'], '202', false);
    self::assertSame(['Llaveros SKC', '2500.00', 8], [$this->product('Llaveros SKC')['name'], number_format((float) $this->product('Llaveros SKC')['price'], 2, '.', ''), (int) $this->product('Llaveros SKC')['minimum_stock']]);
    try {
      $service->saveProduct(['name' => 'Termos', 'type' => 'Categoría inventada'], '101', true);
      self::fail('La categoría debe estar en la configuración.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Configuración › Material POP', $exception->getMessage());
    }
    $id = $service->saveProduct(['name' => 'Termos', 'type' => 'merchandising', 'sku' => 'ter-1', 'initial_quantity' => '20', 'price' => '40.000', 'points' => '35', 'sync_pph' => '1'], '101', true);
    $termos = $this->db->getRow('SELECT * FROM wp_marketing_inventory_products WHERE id = ?', [$id]);
    self::assertSame(['TER-1', 20, '40000.00', 1], [$termos['sku'], (int) $termos['quantity'], number_format((float) $termos['price'], 2, '.', ''), (int) $termos['sync_pph']]);
    $pph = $this->db->getRow('SELECT * FROM wp_jet_cct_inventario_pph WHERE _ID = ?', [(int) $termos['pph_product_id']]);
    self::assertSame(['Termos', '20', 'publish', 101], [$pph['titulo'], (string) $pph['cantidad'], $pph['cct_status'], (int) $pph['cct_author_id']]);
    self::assertSame('opening', $this->db->getVar('SELECT movement_type FROM wp_marketing_inventory_movements WHERE product_id = ?', [$id]));
    try {
      $service->saveProduct(['name' => 'Otro', 'type' => 'Souvenir', 'sku' => 'TER-1'], '101', true);
      self::fail('El SKU es único.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('TER-1 ya está asignado', $exception->getMessage());
    }

    $service->setActive($id, false, 'Ya no se compra', '101');
    self::assertSame('draft', $this->db->getVar('SELECT cct_status FROM wp_jet_cct_inventario_pph WHERE _ID = ?', [(int) $termos['pph_product_id']]));
    self::assertSame(['archive', 0], array_values(array_map(static fn($value) => is_numeric($value) ? (int) $value : $value, $this->db->getRow('SELECT movement_type, quantity_delta FROM wp_marketing_inventory_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$id]) ?? [])));
    // La sincronización no deshace el archivado (el hash quedó al día).
    self::assertSame(0, $service->syncFromPph()['products_updated']);
    try {
      $service->adjustStock($id, 1, 'Compra', '101');
      self::fail('Un producto archivado no se ajusta.');
    } catch (\InvalidArgumentException) {
    }
    $service->setActive($id, true, 'Volvió a comprarse', '101');
    self::assertSame(1, (int) $this->db->getVar('SELECT active FROM wp_marketing_inventory_products WHERE id = ?', [$id]));
  }

  public function testStockAlertIsSentOncePerCrossingToActiveConfiguredEmployees(): void
  {
    self::assertTrue(PopStockAlert::crosses(12, 10, 10));
    self::assertFalse(PopStockAlert::crosses(10, 9, 10), 'Ya estaba en el mínimo.');
    self::assertTrue(PopStockAlert::crosses(3, 0, 0), 'Agotarse siempre avisa.');
    self::assertFalse(PopStockAlert::crosses(5, 8, 10));

    $service = $this->service();
    $llaveros = (int) $this->product('Llaveros')['id'];
    $service->adjustStock($llaveros, -2, 'Sin alerta configurada', '101');
    self::assertSame([], $this->sent);

    PopConfig::save($this->settings, ['categories' => "Souvenir\nmerchandising\nPPH", 'alert_enabled' => '1', 'alert_employees' => ['505', '506']], 7);
    $service->adjustStock($llaveros, 5, 'Compra', '101');
    $service->createDelivery(['recipient_name' => 'Pedro', 'delivered_at' => '2026-11-03 09:00', 'items' => [['product_id' => $llaveros, 'quantity' => 6]]], '101');
    self::assertCount(1, $this->sent);
    self::assertSame(['mercadeo@example.test'], $this->sent[0]['emails'], 'Solo funcionarios activos con correo.');
    self::assertSame('Material POP: Llaveros llegó a su stock mínimo', $this->sent[0]['subject']);
    self::assertStringStartsWith('material-pop-stock:' . $llaveros . ':', (string) $this->sent[0]['options']['dedupe_key']);

    $this->expectExceptionMessage('Elige al menos un funcionario');
    PopConfig::save($this->settings, ['categories' => 'Souvenir', 'alert_enabled' => '1', 'alert_employees' => []], 7);
  }

  public function testViewShowsActionsAndPricesOnlyWithTheirPermissions(): void
  {
    $render = function (string $cargo, string $employee, array $input = []): string {
      CommercialPromotionDatabase::login($cargo, $employee);
      return PromotionView::page(['promocion_sub' => 'material_pop'] + $input, $this->policy(), $this->db, $this->settings, '/public', false);
    };
    $admin = $render('13', '101');
    self::assertStringContainsString('data-commercial-promotion="material_pop"', $admin);
    self::assertStringContainsString('Valor del inventario', $admin);
    self::assertStringContainsString('$870.000', $admin, '12 × 2.500 + 40 × 18.000 + 3 × 40.000 (Club PPH).');
    self::assertStringContainsString('name="price"', $admin);
    self::assertStringContainsString('data-pop-dialog="delivery"', $admin);
    self::assertStringContainsString('Club PPH al día: 2 productos nuevos', $admin);

    $manager = $render('6', '202');
    self::assertStringContainsString('data-pop-open="adjust"', $manager);
    self::assertStringContainsString('data-pop-open="archive"', $manager);
    self::assertStringNotContainsString('Valor del inventario', $manager);
    self::assertStringNotContainsString('name="price"', $manager);
    self::assertStringNotContainsString('2500', $manager, 'El precio no llega al HTML sin pop_ver_costos.');
    self::assertStringNotContainsString('18.000', $manager);
    self::assertStringContainsString('<option value="Coordinadora de mercadeo" data-id="505">', $manager);
    self::assertStringNotContainsString('Retirado', $manager);

    $viewer = $render('7', '303');
    self::assertStringContainsString('Llaveros', $viewer);
    self::assertStringNotContainsString('data-pop-open=', $viewer);
    self::assertStringNotContainsString('data-pop-dialog="delivery"', $viewer);
    self::assertStringNotContainsString('Nueva entrega', $viewer);

    $service = $this->service();
    $service->createDelivery(['recipient_name' => 'Laura <b>Gómez</b>', 'delivered_at' => '2026-11-03 10:00', 'items' => [['product_id' => (int) $this->product('Gorras')['id'], 'quantity' => 1]]], '101');
    $history = $render('6', '202', ['pr_vista' => 'entregas']);
    self::assertStringContainsString('Laura &lt;b&gt;Gómez&lt;/b&gt;', $history);
    self::assertStringContainsString('data-pop-open="cancel"', $history);
    self::assertSame(1, substr_count($history, 'data-pop-open="cancel"'), 'La entrega importada de PPH no se puede anular.');
    $moves = $render('7', '303', ['pr_vista' => 'movimientos', 'pr_producto' => (string) $this->product('Gorras')['id']]);
    self::assertStringContainsString('Entrega a Laura &lt;b&gt;Gómez&lt;/b&gt;.', $moves);
    self::assertStringNotContainsString('CORTADORA DE PELO</span>', $moves);
    self::assertSame('existencias', MaterialPopView::context(['pr_vista' => 'otra'], $this->policy(), $this->db, $this->settings)['section']);
  }

  public function testSettingsSectionListsCategoriesInUseAndAlertRecipients(): void
  {
    $service = new CommercialSettingsService($this->db, $this->settings, $this->policy(), []);
    $section = $service->load('material_pop')['material_pop'];
    self::assertSame(['PPH', 'Souvenir'], $section['categories']);
    self::assertContains(['name' => 'merchandising', 'products' => 1, 'listed' => false], $section['category_usage']);
    self::assertContains(['id' => '505', 'name' => 'Coordinadora de mercadeo', 'email' => 'mercadeo@example.test'], $section['employees']);
    self::assertNotContains('retirado@example.test', array_column($section['employees'], 'email'));
    self::assertSame('Configuración de Material POP guardada.', $service->save('material_pop', ['categories' => ['Souvenir', 'souvenir', ' Merchandising '], 'alert_enabled' => false]));
    self::assertSame(['Souvenir', 'Merchandising'], PopConfig::read($this->settings)['categories']);
    $this->expectException(\InvalidArgumentException::class);
    $service->save('material_pop', ['categories' => ['<b>']]);
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

  public function testEndpointsCheckTheSubtabTheActionAndTheConfirmation(): void
  {
    $delivery = ['recipient_name' => 'Laura', 'delivered_at' => '2026-11-03 10:00', 'items' => json_encode([['product_id' => 1, 'quantity' => 2]])];
    $denied = self::endpoint('7', '303', 'commercial_pop_delivery_create', $delivery);
    self::assertSame([403, 'No tienes permiso para esta acción de Material POP.'], [$denied['status'], $denied['json']['data']['message']]);
    self::assertSame(403, self::endpoint('8', '202', 'commercial_pop_sync')['status'], 'Sin la subpestaña.');
    self::assertSame(403, self::endpoint('13', '101', 'commercial_pop_sync', ['nonce' => 'otro'])['status']);

    $created = self::endpoint('6', '202', 'commercial_pop_delivery_create', $delivery);
    self::assertSame([200, 'Entrega registrada y existencias descontadas.'], [$created['status'], $created['json']['data']['message']]);
    $tooMany = self::endpoint('6', '202', 'commercial_pop_delivery_create', ['items' => json_encode([['product_id' => 1, 'quantity' => 500]])] + $delivery);
    self::assertSame(422, $tooMany['status']);
    self::assertStringContainsString('No hay existencias suficientes', $tooMany['json']['data']['message']);

    $unconfirmed = self::endpoint('6', '202', 'commercial_pop_product_active', ['product_id' => 1, 'active' => '0', 'reason' => 'Prueba']);
    self::assertSame([422, 'Confirma la acción marcando la casilla.'], [$unconfirmed['status'], $unconfirmed['json']['data']['message']]);
    $archived = self::endpoint('6', '202', 'commercial_pop_product_active', ['product_id' => 1, 'active' => '0', 'reason' => 'Prueba', 'confirm' => '1']);
    self::assertSame(200, $archived['status']);
    self::assertSame(200, self::endpoint('6', '202', 'commercial_pop_sync')['status']);
  }
}
