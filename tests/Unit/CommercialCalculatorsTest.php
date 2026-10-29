<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Tools\CleaningCalculator;
use SCM\Commercial\Tools\CleaningQuotePdf;
use SCM\Commercial\Tools\CleaningSettings;
use SCM\Commercial\Tools\MarketStatsService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialToolsView;

/** Calculadoras de mercado y de aseo traídas del panel inmobiliario (mismas comprobaciones que sus tests/tools-*-check.php). */
final class CommercialCalculatorsTest extends TestCase
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

  /** @return array<string,mixed> */
  private function cleaningConfig(): array
  {
    return [
      'salario' => 1750905.0, 'auxilio_transporte' => 249095.0, 'prestaciones_sociales' => 0.4, 'factor_prestacional' => 1.52, 'valor_pasaje_trans' => 4000.0,
      'iva_servicios' => 0.19, 'margen_inmobiliaria' => 0.15, 'margen_contratista' => 0.1,
      'tramos' => [['desde' => 0.0, 'hasta' => 40.0, 'horas' => 2.0], ['desde' => 41.0, 'hasta' => 80.0, 'horas' => 3.5], ['desde' => 81.0, 'hasta' => 120.0, 'horas' => 4.5], ['desde' => 121.0, 'hasta' => 200.0, 'horas' => 6.0]],
      'transporte' => [['rango' => '0', 'valor' => 10000.0], ['rango' => '1-5', 'valor' => 30000.0], ['rango' => '10-19', 'valor' => 60000.0]],
      'insumos' => [['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => 8000.0, 'consumo' => 0.005], ['nombre' => 'Desengrasante', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.003], ['nombre' => 'Desinfectante', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.004], ['nombre' => 'Limpiavidrios', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.002], ['nombre' => 'Bolsas', 'unidad' => 'und', 'costo' => 1000.0, 'consumo' => 0.004]],
    ];
  }

  public function testCleaningFormulaMatchesThePlugin(): void
  {
    $config = $this->cleaningConfig();
    $f = CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'funcionario']);
    self::assertSame([3.5, 40000.0, 60000.0, 10000.0], [$f['horas'], $f['mano_obra'], $f['transporte'], $f['insumos']]);
    self::assertSame([143000.0, 27170.0, 170170.0], [$f['base'], $f['iva'], $f['total']]);
    self::assertEqualsWithDelta(40000 / 1.52, $f['real_mano_obra'], 1e-9);
    self::assertSame(4000.0, $f['real_transporte']);

    $c = CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'contratista']);
    self::assertSame(4000.0, $c['transporte']);
    self::assertSame($c['mano_obra'], $c['real_mano_obra']);

    $gap = CleaningCalculator::calculate($config, ['m2' => '40,5', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'funcionario']);
    self::assertSame(3.5, $gap['horas'], '40,5 m² usa el tramo 41–80');
    self::assertSame(7.0, CleaningCalculator::hours($config['tramos'], 250));
    foreach ([$f, $c, $gap] as $result) {
      self::assertSame($result['base'], $result['cliente']['servicio'] + $result['cliente']['transporte'] + $result['cliente']['insumos'], 'las líneas del cliente suman el subtotal');
    }
    self::assertTrue(CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '4', 'trabajador' => 'contratista'])['protocolo']);

    foreach ([[['m2' => '0'], 'metros'], [['m2' => '60', 'jornada' => 'Otra'], 'jornada'], [['m2' => '60', 'jornada' => 'Jornada Diurna', 'dificultad' => '7'], 'dificultad'], [['m2' => '60', 'jornada' => 'Jornada Diurna', 'dificultad' => '2', 'trabajador' => 'contratista', 'distancia' => '99'], 'distancia']] as [$input, $word]) {
      try {
        CleaningCalculator::calculate($config, $input);
        self::fail('Debía validar ' . $word);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsStringIgnoringCase($word, $exception->getMessage());
      }
    }
  }

  public function testClientPdfHidesInternalCosts(): void
  {
    $config = $this->cleaningConfig();
    $result = CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'funcionario']);
    $signer = ['name' => 'Funcionario QA', 'cargo' => 'Consultor comercial', 'phone' => '3000000000'];
    $client = CleaningQuotePdf::html('cliente', $result, $config, ['cliente' => 'Cliente QA'], $signer);
    $internal = CleaningQuotePdf::html('inmobiliaria', $result, $config, [], $signer);
    self::assertStringContainsString('Cliente QA', $client);
    self::assertStringContainsString('$170.170', $client);
    self::assertStringNotContainsString('Utilidad', $client);
    self::assertStringNotContainsString('Precio contratista', $client);
    self::assertStringContainsString('Utilidad inmobiliaria', $internal);
    self::assertStringContainsString('Consultor comercial · Cel. 3000000000', $internal);
    self::assertStringContainsString('SKC SuCasa Inmobiliaria', $internal);
  }

  public function testCleaningSettingsShareThePluginRows(): void
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, funcion TEXT UNIQUE, valor TEXT)');
    $pdo->exec("INSERT INTO wp_jet_cct_confi_sistema(funcion, valor) VALUES ('salario', '1750905'), ('iva_servicios', '0.19')");
    $pdo->exec('CREATE TABLE wp_options(option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
    $pdo->exec("INSERT INTO wp_options(option_name, option_value, autoload) VALUES ('aseo_transporte', '" . serialize([['rango' => '0', 'valor' => '10000']]) . "', 'yes')");
    $settings = new CleaningSettings(new Database($pdo));
    $read = $settings->read();
    self::assertSame(1750905.0, $read['salario']);
    self::assertSame(0.15, $read['margen_inmobiliaria']);
    self::assertSame([['rango' => '0', 'valor' => 10000.0]], $read['transporte']);
    self::assertCount(4, $read['tramos']);

    $saved = $settings->save([
      'salario' => '1423500', 'auxilio_transporte' => '200000', 'prestaciones_sociales' => '40', 'factor_prestacional' => '1,52', 'valor_pasaje_trans' => '4000',
      'iva_servicios' => '19', 'margen_inmobiliaria' => '15', 'margen_contratista' => '10,5',
      'tramos' => [['desde' => '41', 'hasta' => '80', 'horas' => '3,5'], ['desde' => '0', 'hasta' => '40', 'horas' => '2'], ['desde' => '', 'hasta' => '', 'horas' => '']],
      'transporte' => [['rango' => '0', 'valor' => '12000'], ['rango' => '1-5', 'valor' => '32000']],
      'insumos' => [['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => '8000', 'consumo' => '0,005']],
    ], '1077');
    self::assertSame([1423500.0, 0.105, 0.19, 1.52], [$saved['salario'], $saved['margen_contratista'], $saved['iva_servicios'], $saved['factor_prestacional']]);
    self::assertSame(40.0, $saved['tramos'][0]['hasta']);
    self::assertCount(2, $saved['tramos']);
    $row = $pdo->query("SELECT valor, cct_author_id FROM wp_jet_cct_confi_sistema WHERE funcion = 'margen_contratista'")->fetch(PDO::FETCH_ASSOC);
    self::assertSame(['0.105', 1077], [$row['valor'], (int) $row['cct_author_id']]);
    self::assertSame([['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => '8000', 'consumo' => '0.005']], unserialize((string) $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'aseo_insumos'")->fetchColumn()));
  }

  public function testMarketStatsFilterTheProperties(): void
  {
    self::assertSame(76.0, MarketStatsService::area(['tipo_inmueble' => 'Apartamento', 'area_privada' => '76', 'area_construida' => '80']));
    self::assertSame(51.22, MarketStatsService::area(['tipo_inmueble' => 'Apartamento', 'area_privada' => '', 'area_construida' => '51.22']));
    self::assertSame(300.0, MarketStatsService::area(['tipo_inmueble' => 'Casa lote', 'area_terreno' => '300', 'area_construida' => '90']));
    self::assertSame(0.0, MarketStatsService::area(['tipo_inmueble' => 'Parqueadero', 'area_construida' => '12']));
    self::assertSame([1500000.0, 51.22, 2300000.0, 0.0], [MarketStatsService::number('1.500.000'), MarketStatsService::number('51,22'), MarketStatsService::number('$ 2.300.000'), MarketStatsService::number('')]);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE wp_jet_cct_barrios(_ID INTEGER PRIMARY KEY, barrio TEXT, ruta_asignada TEXT)');
    $pdo->exec("INSERT INTO wp_jet_cct_barrios(barrio, ruta_asignada) VALUES ('Manga','8'),('Crespo','8'),('Bocagrande','1')");
    $pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY, cct_status TEXT, codigo TEXT, barrio TEXT, tipo_inmueble TEXT, tipo_negocio TEXT, destinacion TEXT, estrato TEXT, estado TEXT, precio_venta TEXT, precio_arriendo TEXT, area_construida TEXT, area_privada TEXT, area_terreno TEXT, habitaciones TEXT, edad TEXT, parqueadero TEXT, deposito TEXT, vigilancia TEXT)');
    $insert = $pdo->prepare('INSERT INTO wp_jet_cct_inmuebles VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $yes = 'a:1:{i:0;s:2:"Si";}';
    $no = 'a:1:{i:0;s:2:"No";}';
    foreach ([
      [1, 'publish', 'A1', 'Manga', 'Apartamento', 'Arriendo/Venta', 'Vivienda', '5', 'Publico', '400000000', '2000000', '80', '', '', '3', '8', $yes, $no, $yes],
      [2, 'publish', '', 'Crespo', 'Casa', 'Arriendo', 'Vivienda', '4', 'Arrendado', '', '3000000', '150', '', '', '3', '10', 'Si', $no, $no],
      [3, 'publish', 'C3', 'Manga', 'Lote', 'Venta', 'Comercio', 'Comercial', 'Publico', '900000000', '', '', '', '', '', '', $yes, $no, $no],
      [4, 'publish', 'D4', 'Manga', 'Apartamento', 'Arriendo', 'Vivienda', '5', 'Desistido', '', '9000000', '60', '60', '', '3', '2', $yes, $no, $yes],
      [5, 'publish', 'E5', 'Bocagrande', 'Apartamento', 'Arriendo', 'Vivienda', '6', 'Publico', '', '5000000', '100', '100', '', '3', '5', $yes, $no, $yes],
      [6, 'publish', 'F6', 'Manga', 'Apartamento', 'Arriendo', 'Vivienda', '5', 'Publico', '', '1800000', '70', '70', '', '3', '20', $no, $no, $yes],
    ] as $row) $insert->execute($row);
    $service = new MarketStatsService(new Database($pdo));

    $options = $service->options();
    self::assertEqualsCanonicalizing(['Manga', 'Crespo'], $options['rutas']['8']);
    self::assertSame(['Arrendado', 'Publico'], array_column(array_filter($options['estados'], static fn(array $s): bool => $s['default']), 'value'));

    $result = $service->analyze(['ruta' => '8', 'parqueadero' => 'Si', 'edad' => '10', 'habitaciones' => '3', 'estados' => ['Publico', 'Arrendado']]);
    $ids = array_column($result['items'], 'id');
    sort($ids);
    self::assertSame([1, 1, 2], $ids);
    self::assertSame([5000000.0], $result['venta']);
    $all = $service->analyze([]);
    self::assertSame(1, $all['without_area']);
    self::assertNotContains(4, array_column($all['items'], 'id'));
    self::assertSame([6], array_column($service->analyze(['categoria' => 'Arriendo', 'parqueadero' => 'No'])['items'], 'id'));
    self::assertSame([5], array_column($service->analyze(['barrio' => 'Bocagrande', 'ruta' => '8'])['items'], 'id'));
  }

  private function policy(?array $definition): CommercialAccessPolicy
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (funcion TEXT, valor TEXT)');
    $pdo->prepare('INSERT INTO wp_jet_cct_confi_sistema VALUES (?, ?)')->execute([Settings::FUNCTION_KEY, json_encode([
      'commercial_permissions' => $definition === null ? [] : ['9' => $definition],
      'commercial_admin_cargos' => ['13'],
    ], JSON_THROW_ON_ERROR)]);
    $db = new Database($pdo);
    return new CommercialAccessPolicy(new Settings($db), $db, ['13']);
  }

  public function testNewToolsAndSensitiveViewsNeedPermission(): void
  {
    // Un cargo sin configurar ve las herramientas, pero no la estadística completa ni los costos internos.
    $default = $this->policy(null);
    self::assertTrue($default->canSubview('herramientas', 'calculadora_mercado'));
    self::assertTrue($default->canSubview('herramientas', 'calculadora_aseo'));
    self::assertFalse($default->canAct('mercado_vista_completa'));
    self::assertFalse($default->canAct('aseo_vista_interna'));
    self::assertTrue($default->canAct('ver_ticket'));

    // Un cargo que ya tenía sus herramientas guardadas no recibe las nuevas hasta que se marquen.
    $saved = $this->policy(['views' => ['herramientas'], 'subviews' => ['herramientas' => ['simulador_ingreso_neto']], 'actions' => []]);
    self::assertFalse($saved->canSubview('herramientas', 'calculadora_mercado'));
    self::assertSame('calculadora_aseo', CommercialAccessPolicy::normalizeToolTab(['tab' => 'calculadora_aseo'])['herramienta']);

    $_SESSION = ['scm_user_cargo' => '13'];
    $admin = $this->policy(null);
    self::assertTrue($admin->canAct('mercado_vista_completa'));
    self::assertTrue($admin->canAct('aseo_vista_interna'));
  }

  public function testViewsShowInternalBlocksOnlyWithPermission(): void
  {
    $config = $this->cleaningConfig();
    $public = CommercialToolsView::render('calculadora_aseo', null, '', ['cleaning_internal' => false, 'cleaning_config' => $config]);
    $internal = CommercialToolsView::render('calculadora_aseo', null, '', ['cleaning_internal' => true, 'cleaning_config' => $config]);
    self::assertStringContainsString('data-commercial-tool="calculadora_aseo"', $public);
    self::assertStringContainsString('value="1-5"', $public);
    self::assertStringContainsString('data-cleaning-pdf="cliente"', $public);
    self::assertStringNotContainsString('data-cleaning-pdf="inmobiliaria"', $public);
    self::assertStringContainsString('data-cleaning-pdf="funcionario"', $internal);

    $market = CommercialToolsView::render('calculadora_mercado', null, '', ['market_admin' => true]);
    self::assertStringContainsString('data-commercial-tool="calculadora_mercado"', $market);
    self::assertStringContainsString('Vista completa de estadística', $market);
    self::assertStringNotContainsString('Vista completa de estadística', CommercialToolsView::render('calculadora_mercado', null, ''));
  }
}
