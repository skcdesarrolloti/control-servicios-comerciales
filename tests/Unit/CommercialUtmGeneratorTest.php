<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Tools\UtmCatalog;
use SCM\Commercial\Tools\UtmLinkBuilder;
use SCM\Commercial\Tools\UtmTrackerOptions;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\CommercialToolsView;

/** Herramientas › Generador UTM traído de dashboard-marketing (views/utm.php) con su catálogo en Configuración. */
final class CommercialUtmGeneratorTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_cargo' => '9', 'scm_user_id' => 7, 'scm_employee_id' => '1007'];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** @return array{slug:list<array{0:string,1:string}>,build:list<array<string,mixed>>} */
  private static function cases(): array
  {
    return json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/utm-cases.json'), true, 512, JSON_THROW_ON_ERROR);
  }

  /** Los mismos casos los corre commercial-utm.js en tools/check-commercial-tools.cjs: PHP y JS arman igual el enlace. */
  public function testSlugAndLinkMatchTheSharedCases(): void
  {
    $cases = self::cases();
    foreach ($cases['slug'] as [$input, $expected]) {
      self::assertSame($expected, UtmLinkBuilder::slug($input), $input);
      self::assertSame($expected, UtmLinkBuilder::slug($expected), 'slug() debe ser idempotente');
    }
    foreach ($cases['build'] as $case) {
      $verbatim = $case['verbatim'] ?? [];
      if (isset($case['error'])) {
        try {
          UtmLinkBuilder::build($case['url'], $case['values'], $verbatim);
          self::fail('Debía rechazar ' . $case['url'] . ': ' . $case['error']);
        } catch (\InvalidArgumentException $exception) {
          self::assertSame($case['error'], $exception->getMessage());
        }
        continue;
      }
      self::assertSame($case['expected'], UtmLinkBuilder::build($case['url'], $case['values'], $verbatim));
    }
    self::assertSame("Medell\u{00ED}n", UtmLinkBuilder::trimText("\u{FEFF}\u{00A0}Medell\u{00ED}n\u{3000}\u{2029}"));
    self::assertSame(UtmLinkBuilder::MAX_LENGTH, strlen(UtmLinkBuilder::slug(str_repeat('ab ', 80))));
  }

  private ?Database $db = null;

  private function settings(): Settings
  {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
    $this->db = new Database($pdo);
    return new Settings($this->db);
  }

  public function testCatalogKeepsOnlyCampaignsAndPreservesTheOldLists(): void
  {
    $settings = $this->settings();
    self::assertSame(['campanas' => []], UtmCatalog::read($settings));
    self::assertSame(['campanas'], array_keys(UtmCatalog::LISTS));

    // Un catálogo guardado con la versión anterior (medios y fuentes) se lee solo con sus campañas y, al guardar, las
    // listas viejas se conservan tal cual en Settings (no se borra nada).
    $legacy = ['medios' => [['label' => 'WhatsApp', 'value' => 'whatsapp']], 'fuentes' => [['label' => 'Facebook', 'value' => 'facebook']], 'campanas' => [['label' => 'Feria', 'value' => 'feria']]];
    $settings->set(UtmCatalog::SETTING, $legacy, 7);
    self::assertSame(['campanas' => [['label' => 'Feria', 'value' => 'feria']]], UtmCatalog::read($settings));

    UtmCatalog::save($settings, [
      'campanas' => [['label' => 'Revista Esencia junio', 'value' => 'esencia-inmobiliaria-edicion-junio-2026'], ['label' => 'Feria Vivienda', 'value' => ''], ['label' => '<b>Feria</b> vivienda', 'value' => 'FERIA_VIVIENDA'], ['label' => '', 'value' => '']],
    ], 7);
    $saved = UtmCatalog::read($settings);
    // Se quitan etiquetas HTML, el valor sale del nombre y el repetido (feria_vivienda) se descarta.
    self::assertSame(['esencia-inmobiliaria-edicion-junio-2026', 'feria_vivienda'], array_column($saved['campanas'], 'value'));
    $stored = $settings->get(UtmCatalog::SETTING);
    self::assertSame($legacy['medios'], $stored['medios']);
    self::assertSame($legacy['fuentes'], $stored['fuentes']);

    // Sin campañas también se puede guardar: el medio ya no sale de este catálogo.
    UtmCatalog::save($settings, ['campanas' => []], 7);
    self::assertSame(['campanas' => []], UtmCatalog::read($settings));

    $invalid = [
      'nombre largo' => ['campanas' => [['label' => str_repeat('a', UtmCatalog::MAX_LABEL + 1)]]],
      'valor largo' => ['campanas' => [['label' => 'Feria', 'value' => str_repeat('a', UtmLinkBuilder::MAX_LENGTH + 1)]]],
      'sin letras' => ['campanas' => [['label' => '¡¡!!']]],
      'campaña reservada' => ['campanas' => [['label' => 'General']]],
      'demasiadas' => ['campanas' => array_map(static fn(int $i): array => ['label' => 'campaña ' . $i], range(1, UtmCatalog::MAX_ITEMS + 1))],
    ];
    foreach ($invalid as $name => $data) {
      try {
        UtmCatalog::save($settings, $data, 7);
        self::fail('Debía rechazar: ' . $name);
      } catch (\InvalidArgumentException) {
        self::assertSame(['campanas' => []], UtmCatalog::read($settings), 'No debe cambiar el catálogo: ' . $name);
      }
    }
  }

  public function testSettingsMenuSectionLoadsAndSavesOnlyForAdministrators(): void
  {
    $settings = $this->settings();
    $db = $this->db;
    self::assertInstanceOf(Database::class, $db);
    $policy = new CommercialAccessPolicy($settings, $db, ['13']);
    $service = new CommercialSettingsService($db, $settings, $policy, []);
    self::assertContains('utm', CommercialSettingsService::SECTIONS);
    try {
      $service->save('utm', ['campanas' => [['label' => 'Feria']]]);
      self::fail('Un cargo no administrador no puede cambiar el catálogo.');
    } catch (\RuntimeException $exception) {
      self::assertNotInstanceOf(\InvalidArgumentException::class, $exception);
    }

    $_SESSION['scm_user_cargo'] = '13';
    self::assertSame('Campañas del generador UTM guardadas.', $service->save('utm', ['campanas' => [['label' => 'Google Ads']]]));
    self::assertSame([['label' => 'Google Ads', 'value' => 'google_ads']], UtmCatalog::read($settings)['campanas']);
    $this->expectException(\InvalidArgumentException::class);
    $service->save('utm', ['campanas' => [['label' => 'General']]]);
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


  public function testTheGeneratorIsItsOwnHerramientasTabWithItsPermission(): void
  {
    self::assertSame('Generador UTM', CommercialAccessPolicy::SUBVIEWS['herramientas']['generador_utm']);
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'generador_utm'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'generador_utm']));
    // Un cargo sin configurar la recibe con el resto de herramientas.
    self::assertTrue($this->policy(null)->canSubview('herramientas', 'generador_utm'));

    // Un cargo con sus herramientas guardadas sin el generador no puede abrirlo: la navegación lo rechaza.
    $saved = $this->policy(['views' => ['herramientas'], 'subviews' => ['herramientas' => ['simulador_ingreso_neto']], 'actions' => []]);
    self::assertFalse($saved->canSubview('herramientas', 'generador_utm'));
    try {
      $saved->resolveNavigation('herramientas', ['herramienta' => 'generador_utm']);
      self::fail('Debía rechazar la subpestaña.');
    } catch (\RuntimeException $exception) {
      self::assertSame('No tienes permiso para entrar a esta subpestaña.', $exception->getMessage());
    }
    $granted = $this->policy(['views' => ['herramientas'], 'subviews' => ['herramientas' => ['generador_utm']], 'actions' => []]);
    self::assertSame('generador_utm', $granted->resolveNavigation('herramientas', ['herramienta' => 'generador_utm'])['herramienta']);
  }

  /** @return array{success:bool,data:array<string,mixed>} */
  private static function endpoint(string ...$args): array
  {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/utm-endpoint.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $output = (string) shell_exec($command);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
  }

  public function testTheTrackerEndpointChecksTheSubtabAndSendsOnlyCampaignNames(): void
  {
    $denied = self::endpoint('8');
    self::assertFalse($denied['success']);
    self::assertSame('No tienes permiso para usar el generador UTM.', $denied['data']['message']);

    $allowed = self::endpoint('9');
    self::assertTrue($allowed['success']);
    // Fuentes y medios ya no salen del tracker: el generador usa la lista única de Medios.
    self::assertSame(['campaigns'], array_keys($allowed['data']));
    self::assertSame([['value' => 'feria_vivienda', 'label' => 'Feria de vivienda'], ['value' => 'Revista Esencia', 'label' => 'Revista Esencia']], $allowed['data']['campaigns']);
    $json = json_encode($allowed, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('10.0.0.1', $json);
    self::assertStringNotContainsString('secreto', $json);
    self::assertStringNotContainsString('Revista Impresa', $json);

    // Sin las tablas del tracker la herramienta sigue funcionando con la lista vacía.
    self::assertSame(['campaigns' => []], self::endpoint('9', 'sin_tracker')['data']);
    $pdo = new PDO('sqlite::memory:');
    self::assertSame(['campaigns' => []], (new UtmTrackerOptions(new Database($pdo)))->options(false));
  }

  public function testTheViewOffersOneMedioSelectFromTheSingleList(): void
  {
    $catalog = UtmCatalog::defaults();
    $catalog['campanas'] = [['label' => 'Feria de vivienda', 'value' => 'feria_vivienda']];
    $medios = [['label' => 'Instagram', 'value' => 'instagram'], ['label' => 'Estado whatsapp', 'value' => 'estado_whatsapp']];
    $html = CommercialToolsView::render('generador_utm', null, '', ['utm_catalog' => $catalog, 'utm_medios' => $medios]);
    self::assertStringContainsString('data-commercial-tool="generador_utm"', $html);
    self::assertStringContainsString('<select id="ctl-utm-medio" name="medio" data-utm-field="medio" required><option value="">Selecciona el medio</option><option value="instagram">Instagram</option><option value="estado_whatsapp">Estado whatsapp</option></select>', $html);
    // Un solo selector: ya no hay campos de fuente y medio por separado ni "Otro (escribir)".
    self::assertStringNotContainsString('data-utm-field="source"', $html);
    self::assertStringNotContainsString('data-utm-field="medium"', $html);
    self::assertStringNotContainsString('__otro__', $html);
    self::assertStringContainsString('data-utm-pick="feria_vivienda"', $html);
    self::assertStringContainsString('<option value="feria_vivienda">Feria de vivienda</option>', $html);
    self::assertStringContainsString('Configuración › Medios', $html);
    foreach (['url', 'medio', 'campaign', 'content', 'term'] as $field) {
      self::assertStringContainsString('data-utm-field="' . $field . '"', $html);
    }
    // Sin la lista en el contexto se usa la lista inicial de Medios.
    self::assertStringContainsString('<option value="por_ruta">Por ruta</option>', CommercialToolsView::render('generador_utm', null, ''));

    // El contexto lee las campañas y los medios activos solo para el generador.
    $settings = $this->settings();
    $db = $this->db;
    self::assertInstanceOf(Database::class, $db);
    $policy = new CommercialAccessPolicy($settings, $db, ['13']);
    $context = CommercialToolsView::context('generador_utm', $policy, $db, $settings);
    self::assertSame(UtmCatalog::defaults(), $context['utm_catalog']);
    self::assertSame(['label' => 'Aviso', 'value' => 'aviso'], $context['utm_medios'][0]);
    self::assertContains(['label' => 'ChatGPT', 'value' => 'chatgpt'], $context['utm_medios']);
    self::assertSame([], CommercialToolsView::context('ganancia_ocasional', $policy, $db, $settings)['utm_medios']);
  }
}
