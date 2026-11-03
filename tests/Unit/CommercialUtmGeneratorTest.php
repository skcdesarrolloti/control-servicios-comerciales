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

  public function testCatalogStartsWithTheLegacyDefaultsAndValidatesWhatIsSaved(): void
  {
    $settings = $this->settings();
    $defaults = UtmCatalog::read($settings);
    self::assertSame(['label' => 'WhatsApp', 'value' => 'whatsapp'], $defaults['medios'][0]);
    self::assertContains(['label' => 'Correo electrónico', 'value' => 'correo_electronico'], $defaults['medios']);
    self::assertContains('estado_whatsapp', array_column($defaults['fuentes'], 'value'));
    self::assertSame([], $defaults['campanas']);

    UtmCatalog::save($settings, [
      'medios' => [['label' => '  WhatsApp  ', 'value' => ''], ['label' => 'Whats App', 'value' => 'WHATSAPP'], ['label' => '<b>Correo</b>', 'value' => ''], ['label' => '', 'value' => '']],
      'fuentes' => [['label' => 'Base de Datos', 'value' => '']],
      'campanas' => [['label' => 'Revista Esencia junio', 'value' => 'esencia-inmobiliaria-edicion-junio-2026'], ['label' => 'Feria Vivienda', 'value' => '']],
    ], 7);
    $saved = UtmCatalog::read($settings);
    // Se recorta, se quitan etiquetas HTML, el valor sale del nombre y el repetido (whatsapp) se descarta.
    self::assertSame([['label' => 'WhatsApp', 'value' => 'whatsapp'], ['label' => 'Correo', 'value' => 'correo']], $saved['medios']);
    self::assertSame([['label' => 'Base de Datos', 'value' => 'base_de_datos']], $saved['fuentes']);
    self::assertSame(['esencia-inmobiliaria-edicion-junio-2026', 'feria_vivienda'], array_column($saved['campanas'], 'value'));

    $invalid = [
      'sin medios' => ['medios' => [], 'fuentes' => [['label' => 'Facebook']]],
      'nombre largo' => ['medios' => [['label' => str_repeat('a', UtmCatalog::MAX_LABEL + 1)]], 'fuentes' => [['label' => 'Facebook']]],
      'valor largo' => ['medios' => [['label' => 'WhatsApp', 'value' => str_repeat('a', UtmLinkBuilder::MAX_LENGTH + 1)]], 'fuentes' => [['label' => 'Facebook']]],
      'sin letras' => ['medios' => [['label' => '¡¡!!']], 'fuentes' => [['label' => 'Facebook']]],
      'campaña reservada' => ['medios' => [['label' => 'SMS']], 'fuentes' => [['label' => 'Facebook']], 'campanas' => [['label' => 'General']]],
      'demasiadas' => ['medios' => array_map(static fn(int $i): array => ['label' => 'medio ' . $i], range(1, UtmCatalog::MAX_ITEMS + 1)), 'fuentes' => [['label' => 'Facebook']]],
    ];
    foreach ($invalid as $name => $data) {
      try {
        UtmCatalog::save($settings, $data, 7);
        self::fail('Debía rechazar: ' . $name);
      } catch (\InvalidArgumentException) {
        self::assertSame($saved, UtmCatalog::read($settings), 'No debe cambiar el catálogo: ' . $name);
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
      $service->save('utm', ['medios' => [['label' => 'SMS']], 'fuentes' => [['label' => 'Google']]]);
      self::fail('Un cargo no administrador no puede cambiar el catálogo.');
    } catch (\RuntimeException $exception) {
      self::assertNotInstanceOf(\InvalidArgumentException::class, $exception);
    }

    $_SESSION['scm_user_cargo'] = '13';
    self::assertSame('Catálogo del generador UTM guardado.', $service->save('utm', ['medios' => [['label' => 'SMS']], 'fuentes' => [['label' => 'Google Ads']], 'campanas' => []]));
    self::assertSame([['label' => 'Google Ads', 'value' => 'google_ads']], UtmCatalog::read($settings)['fuentes']);
    $this->expectException(\InvalidArgumentException::class);
    $service->save('utm', ['medios' => [['label' => 'SMS']], 'fuentes' => []]);
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
    self::assertSame(['sources', 'mediums', 'campaigns'], array_keys($allowed['data']));
    self::assertSame([['value' => 'feria_vivienda', 'label' => 'Feria de vivienda'], ['value' => 'Revista Esencia', 'label' => 'Revista Esencia']], $allowed['data']['campaigns']);
    self::assertContains('Facebook', $allowed['data']['sources']);
    // Lo que el tracker escribe en las visitas sin UTM (y "General" en cualquier forma) no se ofrece.
    self::assertSame(['Facebook', 'Google', 'Revista Impresa', 'instagram'], $allowed['data']['sources']);
    self::assertSame(['Redes Sociales', 'Volante', 'cpc', 'whatsapp'], $allowed['data']['mediums']);
    $json = json_encode($allowed, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('10.0.0.1', $json);
    self::assertStringNotContainsString('secreto', $json);

    // Sin las tablas del tracker la herramienta sigue funcionando con listas vacías.
    self::assertSame(['sources' => [], 'mediums' => [], 'campaigns' => []], self::endpoint('9', 'sin_tracker')['data']);
    $pdo = new PDO('sqlite::memory:');
    self::assertSame(['sources' => [], 'mediums' => [], 'campaigns' => []], (new UtmTrackerOptions(new Database($pdo)))->options(false));
  }

  public function testTheViewShowsTheSharedCatalog(): void
  {
    $catalog = UtmCatalog::defaults();
    $catalog['campanas'] = [['label' => 'Feria de vivienda', 'value' => 'feria_vivienda']];
    $html = CommercialToolsView::render('generador_utm', null, '', ['utm_catalog' => $catalog]);
    self::assertStringContainsString('data-commercial-tool="generador_utm"', $html);
    self::assertStringContainsString('<option value="whatsapp">WhatsApp</option>', $html);
    self::assertStringContainsString('<option value="estado_whatsapp">Estado WhatsApp</option>', $html);
    self::assertStringContainsString('data-utm-pick="feria_vivienda"', $html);
    self::assertStringContainsString('<option value="feria_vivienda">Feria de vivienda</option>', $html);
    self::assertStringContainsString('Configuración › Generador UTM', $html);
    foreach (['url', 'source', 'medium', 'campaign', 'content', 'term'] as $field) {
      self::assertStringContainsString('data-utm-field="' . $field . '"', $html);
    }
    // Sin catálogo guardado la vista usa los valores por defecto.
    self::assertStringContainsString('<option value="facebook">Facebook</option>', CommercialToolsView::render('generador_utm', null, ''));

    // El contexto solo lee el catálogo para el generador.
    $settings = $this->settings();
    $db = $this->db;
    self::assertInstanceOf(Database::class, $db);
    $policy = new CommercialAccessPolicy($settings, $db, ['13']);
    self::assertSame(UtmCatalog::defaults(), CommercialToolsView::context('generador_utm', $policy, $db, $settings)['utm_catalog']);
    self::assertSame([], CommercialToolsView::context('ganancia_ocasional', $policy, $db, $settings)['utm_catalog']);
  }
}
