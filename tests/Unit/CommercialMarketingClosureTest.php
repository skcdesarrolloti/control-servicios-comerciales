<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Commercial\Promotion\Pop\PopFiles;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\Campaigns\CampaignsView;
use SCM\Views\Promotion\AnalyticsView;
use Tests\Fixtures\CampaignFixture;
use Tests\Fixtures\CommercialPromotionDatabase;
use Tests\Fixtures\CommercialTrackerDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';
require_once dirname(__DIR__) . '/Fixtures/CampaignFixture.php';

/** Lo último que faltaba de dashboard-marketing: unificar campañas, cruces de Analítica, renombrar y eliminar campañas, banner y revista. */
final class CommercialMarketingClosureTest extends TestCase
{
  private const TODAY = '2026-11-03';

  private array $previousSession;
  private Database $db;
  private Settings $settings;
  private string $lockFile;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    if (!defined('SCM_APP_SECRET')) {
      define('SCM_APP_SECRET', CommercialPromotionDatabase::SECRET);
    }
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    $this->lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-closure-' . bin2hex(random_bytes(4)) . '.lock';
    CommercialPromotionDatabase::seed($this->db, $this->settings, self::TODAY, $this->lockFile);
    CommercialPromotionDatabase::login('13', '101', 'Gerente');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    @unlink($this->lockFile);
  }

  private function policy(): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function misspelled(): void
  {
    foreach ([['181.1.1.1', 'Medellín'], ['181.1.1.2', 'Bogotá']] as [$ip, $city]) {
      CommercialTrackerDatabase::hit($this->db, ['camp_slug' => 'promo-octubr', 'ip_address' => $ip, 'city' => $city, 'fecha_visita' => '2026-11-01 10:00:00', 'utm_source' => 'correo', 'utm_medium' => 'correo', 'object_id' => '55', 'object_ref' => 'REF-55']);
    }
    CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile)->run();
  }

  /** @return array{0:int,1:int,2:int} visitas, filas de dimensiones y visitantes de una campaña. */
  private function stored(string $campaign): array
  {
    return [
      (int) $this->db->getVar('SELECT COALESCE(SUM(hits), 0) FROM wp_scm_tracker_daily WHERE camp_slug = ?', [$campaign]),
      (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_tracker_daily_top WHERE camp_slug = ?', [$campaign]),
      (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_tracker_daily_visitors WHERE camp_slug = ?', [$campaign]),
    ];
  }

  public function testMergingCampaignsMovesTheVisitsTheSummariesAndTheCatalog(): void
  {
    $this->misspelled();
    $this->db->insert('wp_jet_cct_tracker_camps', ['slug' => 'promo-octubr', 'nombre' => 'Promo octubr']);
    $before = (int) $this->db->getVar('SELECT SUM(hits) FROM wp_scm_tracker_daily');
    [$wrongHits] = $this->stored('promo-octubr');
    [$rightHits] = $this->stored('promo-octubre');
    self::assertSame(2, $wrongHits);

    $service = new CommercialSettingsService($this->db, $this->settings, $this->policy(), []);
    self::assertContains('promo-octubr', array_column($service->load('analitica')['analitica']['campaigns'], 'slug'));
    self::assertNotContains('general', array_column($service->load('analitica')['analitica']['campaigns'], 'slug'));
    self::assertSame([], $service->load('medios')['analitica']['campaigns'], 'La lista solo se calcula al abrir la sección.');
    $message = $service->save('analitica', ['op' => 'merge_campaign', 'from' => 'promo-octubr', 'to' => 'promo-octubre']);
    self::assertSame('2 visitas pasaron de "promo-octubr" a "promo-octubre".', $message);

    self::assertSame([0, 0, 0], $this->stored('promo-octubr'));
    self::assertSame($rightHits + 2, $this->stored('promo-octubre')[0]);
    self::assertSame($before, (int) $this->db->getVar('SELECT SUM(hits) FROM wp_scm_tracker_daily'), 'Unificar no cambia el total.');
    self::assertSame(0, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_logs WHERE camp_slug = 'promo-octubr'"));
    self::assertSame(0, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_camps WHERE slug = 'promo-octubr'"));
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_camps WHERE slug = 'promo-octubre'"));

    // Lo unificado da lo mismo que recalcular desde las visitas crudas.
    $merged = $this->stored('promo-octubre');
    CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile)->rebuildFrom('2026-01-01');
    self::assertSame($merged, $this->stored('promo-octubre'));
  }

  public function testMergingIntoANewNameRenamesTheCatalogAndAcceptsCaseChanges(): void
  {
    $this->misspelled();
    $this->db->insert('wp_jet_cct_tracker_camps', ['slug' => 'promo-octubr', 'nombre' => 'Promo octubr']);
    $rollup = CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile);
    self::assertSame(2, $rollup->mergeCampaign('promo-octubr', '  Promo   Noviembre ')['updated']);
    self::assertSame(2, $this->stored('Promo Noviembre')[0]);
    self::assertSame('Promo Noviembre', $this->db->getVar("SELECT nombre FROM wp_jet_cct_tracker_camps WHERE slug = 'Promo Noviembre'"));
    self::assertSame(2, $rollup->mergeCampaign('Promo Noviembre', 'promo noviembre')['updated']);
  }

  /** @return array<string,array{0:string,1:string,2:string}> */
  public static function invalidMerges(): array
  {
    return [
      'sin origen' => ['', 'x', 'Elige la campaña'],
      'sin destino' => ['promo-octubr', ' ', 'Elige la campaña'],
      'misma' => ['promo-octubr', 'promo-octubr', 'son la misma'],
      'largo' => ['promo-octubr', str_repeat('a', 121), 'máximo 120'],
      'sin campaña' => ['general', 'otra', 'no es una campaña'],
      'vista de inmueble' => ['inmueble_view', 'otra', 'no es una campaña'],
      'no existe' => ['no-existe', 'otra', 'no tiene visitas'],
    ];
  }

  #[DataProvider('invalidMerges')]
  public function testInvalidMergesChangeNothing(string $from, string $to, string $message): void
  {
    $this->misspelled();
    $before = $this->stored('promo-octubr');
    try {
      CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile)->mergeCampaign($from, $to);
      self::fail('Debía rechazarse.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString($message, $exception->getMessage());
    }
    self::assertSame($before, $this->stored('promo-octubr'));
  }

  public function testOnlyTheManagerCanMergeCampaigns(): void
  {
    CommercialPromotionDatabase::login('6', '202');
    $this->expectException(\RuntimeException::class);
    (new CommercialSettingsService($this->db, $this->settings, $this->policy(), []))->save('analitica', ['op' => 'merge_campaign', 'from' => 'a', 'to' => 'b']);
  }

  public function testCampaignBreakdownsComeFromTheSummariesWithoutTheNormalVisits(): void
  {
    $this->misspelled();
    $repository = new TrackerAnalyticsRepository($this->db, $this->settings);
    $filters = TrackerAnalyticsFilters::fromInput(['from' => '2026-11-01', 'to' => '2026-11-01'], TrackerAnalyticsConfig::read($this->settings));
    $cross = $repository->campaignBreakdowns($filters);
    $row = array_values(array_filter($cross['channels'], static fn(array $item): bool => $item['campaign'] === 'promo-octubr'))[0];
    self::assertSame(['campaign' => 'promo-octubr', 'source' => 'correo', 'medium' => 'correo', 'hits' => 2, 'unique_visitors' => 2], $row);
    foreach ($cross as $rows) {
      self::assertNotContains('general', array_column($rows, 'campaign'));
      self::assertNotContains('inmueble_view', array_column($rows, 'campaign'));
    }
    $objects = array_values(array_filter($cross['objects'], static fn(array $item): bool => $item['campaign'] === 'promo-octubr'));
    self::assertSame([['55', 'REF-55', 2]], array_map(static fn(array $item): array => [$item['label'], $item['detail'], $item['hits']], $objects));
    $cities = array_column(array_filter($cross['locations'], static fn(array $item): bool => $item['campaign'] === 'promo-octubr'), 'label');
    sort($cities);
    self::assertSame(['Bogotá', 'Medellín'], $cities);

    $html = AnalyticsView::render(AnalyticsView::context(['pr_desde' => '2026-11-01', 'pr_hasta' => '2026-11-01'], $this->policy(), $this->db, $this->settings, null, self::TODAY), '/public');
    foreach (['Campaña y canal', 'Inmuebles por campaña', 'Ubicación por campaña', 'Composición de la audiencia', 'Calidad del tráfico', 'REF-55'] as $text) {
      self::assertStringContainsString($text, $html);
    }
  }

  public function testRenamingACampaignMovesItsHistoryThroughTheJsonColumn(): void
  {
    [$db, , $engine] = CampaignFixture::make(true);
    $engine->send($engine->spec(self::campaign()), '999', 'Administrador');
    $count = (int) $db->getVar("SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = 'Revista noviembre'");
    self::assertGreaterThan(0, $count);
    $engine->exclude('Revista noviembre', 'suscriptores', 202, 'Pidió salir', '999');
    self::assertSame('Revista diciembre', $engine->rename('Revista noviembre', '  Revista   diciembre '));

    self::assertSame(0, (int) $db->getVar("SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = 'Revista noviembre'"));
    self::assertSame($count, (int) $db->getVar("SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = 'Revista diciembre'"), 'La columna calculada sigue al JSON.');
    self::assertSame(1, (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_campaign_config WHERE campaign_tag = 'Revista diciembre'"));
    self::assertSame(1, (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_campaign_exclusions WHERE campaign_tag = 'Revista diciembre'"));
    self::assertSame($count, (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_email_tracking WHERE campaign_tag = 'Revista diciembre'"));
    self::assertSame('Revista diciembre', $engine->detail('Revista diciembre')['tag']);

    $engine->send($engine->spec(self::campaign(['name' => 'Otra'])), '999', 'Administrador');
    foreach ([['Otra', 'Revista diciembre', 'Ya existe'], ['Otra', 'Otra', 'ya tiene ese nombre'], ['Otra', ' ', 'nombre nuevo'], ['Otra', str_repeat('x', 121), 'nombre nuevo'], ['Nada', 'Algo', 'no existe']] as [$tag, $new, $message]) {
      try {
        $engine->rename($tag, $new);
        self::fail('Debía rechazarse: ' . $message);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
  }

  public function testDeletingACampaignNeedsItToBeFinished(): void
  {
    [$db, , $engine] = CampaignFixture::make(true);
    $tag = 'Revista noviembre';
    $engine->send($engine->spec(self::campaign()), '999', 'Administrador');
    $total = (int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = ?', [$tag]);
    try {
      $engine->delete($tag);
      self::fail('Con mensajes por salir no se puede eliminar.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Cancela lo pendiente', $exception->getMessage());
    }
    self::assertSame($total, (int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = ?', [$tag]));

    $engine->cancel($tag);
    self::assertSame($total, $engine->delete($tag));
    self::assertSame(0, (int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue WHERE gda_campaign_tag = ?', [$tag]));
    self::assertSame(0, (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_campaign_config WHERE campaign_tag = ?', [$tag]));
    self::assertNotContains($tag, array_column($engine->list(), 'tag'));
  }

  public function testDeleteHasItsOwnOptInPermission(): void
  {
    self::assertContains('campanas_eliminar', CommercialAccessPolicy::OPT_IN_ACTIONS);
    [$db, $admin, $engine] = CampaignFixture::make(true);
    $engine->send($engine->spec(self::campaign()), '999', 'Administrador');
    $html = CampaignsView::page('campanas', [], $admin, $db, new Settings($db, true, 'control_servicios_comerciales_config'), '/public');
    self::assertStringContainsString('data-can-delete="1"', $html);
    self::assertStringContainsString('data-camp-dialog="rename"', $html);
    self::assertStringContainsString('data-can-delete="1"', $html);
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/CampaignApiController.php');
    self::assertStringContainsString("canAct('campanas_eliminar')", $source);
    self::assertSame('settingsBanner', (new \ReflectionMethod(CommercialActionRouter::class, 'controllerMethod'))->invoke(null, 'commercial_settings_banner'));
  }

  public function testBannerAndMagazineUseTheSameRowsAndTheEmailsFollowThem(): void
  {
    $table = 'wp_jet_cct_confi_sistema';
    CommercialPromotionDatabase::systemConfigSchema($this->db);
    $this->db->insert($table, ['funcion' => 'banner', 'valor' => 'https://example.test/viejo.png', 'imagen' => 'https://example.test/viejo.png']);
    $service = new CommercialSettingsService($this->db, $this->settings, $this->policy(), []);
    self::assertContains('banner_revista', CommercialSettingsService::SECTIONS);
    self::assertSame(['banner' => 'https://example.test/viejo.png', 'link_revista' => ''], $service->load('banner_revista')['banner_revista']);

    $saved = $service->save('banner_revista', ['banner_url' => 'https://example.test/nuevo.png', 'link_revista' => 'https://example.test/revista']);
    self::assertSame('Banner y link de la revista guardados.', $saved);
    self::assertSame(['https://example.test/nuevo.png', 'https://example.test/nuevo.png'], array_values($this->db->getRow("SELECT valor, imagen FROM {$table} WHERE funcion = 'banner'") ?? []));
    self::assertSame('https://example.test/revista', $this->db->getVar("SELECT valor FROM {$table} WHERE funcion = 'link_revista'"));
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM {$table} WHERE funcion = 'banner'"), 'Actualiza la fila, no crea otra.');

    foreach ([['banner_url' => 'javascript:alert(1)'], ['banner_url' => 'http://example.test/a.png'], ['link_revista' => 'no es una url']] as $bad) {
      try {
        $service->save('banner_revista', $bad);
        self::fail('Debía rechazarse.');
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString('https', $exception->getMessage());
      }
    }

    $files = new class extends PopFiles {
      public function store(string $field, int $max): array
      {
        return $field === 'banner_image' && $max === 1 ? ['https://example.test/subido.jpg'] : [];
      }
    };
    self::assertSame('https://example.test/subido.jpg', $service->saveBannerUpload($files));
    self::assertSame('https://example.test/subido.jpg', $this->db->getVar("SELECT imagen FROM {$table} WHERE funcion = 'banner'"));
    $empty = new class extends PopFiles {
      public function store(string $field, int $max): array
      {
        return [];
      }
    };
    $this->expectException(\InvalidArgumentException::class);
    $service->saveBannerUpload($empty);
  }

  public function testTheEmailBannerFallsBackToTheEditableRow(): void
  {
    self::assertStringContainsString("system_image('banner_sitio_web', system_image('banner',", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/EmailTemplate.php'));
  }

  /** @param array<string,mixed> $overrides @return array<string,mixed> */
  private static function campaign(array $overrides = []): array
  {
    return $overrides + [
      'name' => 'Revista noviembre', 'channel' => 'email', 'category' => 'info', 'subject' => 'Hola {{nombre}}', 'message' => 'Novedades.', 'segments' => json_encode([['type' => 'clientes', 'q' => ''], ['type' => 'suscriptores', 'q' => '']]),
      'batch_size' => '2', 'batch_interval_minutes' => '15', 'max_per_day' => '100',
    ];
  }
}
