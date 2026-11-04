<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Promotion\Analytics\BotDetector;
use SCM\Commercial\Promotion\Analytics\InternalIpMatcher;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Commercial\Promotion\Analytics\TrackerLock;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\TrackerRollupCommand;
use SCM\Commercial\Promotion\Analytics\TrackerSchema;
use SCM\Commercial\Promotion\Analytics\UrlNormalizer;
use SCM\Commercial\Promotion\Analytics\VisitorHasher;
use SCM\Core\Database;
use SCM\Core\Settings;
use Tests\Fixtures\CommercialTrackerDatabase;

final class CommercialTrackerAnalyticsTest extends TestCase
{
  private const TODAY = '2026-11-03';
  private const SECRET = 'secreto-de-prueba-de-cuarenta-caracteres';
  private const PAGE = 'https://sucasainmobiliaria.com.co/inmueble/abc';

  private Database $db;
  private Settings $settings;
  private string $lockFile;
  private array $previousSession;

  protected function setUp(): void
  {
    require_once dirname(__DIR__) . '/Fixtures/CommercialTrackerDatabase.php';
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->previousSession = $_SESSION ?? [];
    $this->db = CommercialTrackerDatabase::create();
    $this->settings = new Settings($this->db);
    $this->lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-tracker-test-' . bin2hex(random_bytes(6)) . '.lock';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    if (is_file($this->lockFile)) {
      @unlink($this->lockFile);
    }
  }

  /** Reloj de las pruebas (0 = hoy a mediodía): las visitas de octubre ya están asentadas. */
  private int $now = 0;

  private function rollup(): TrackerRollup
  {
    $now = $this->now > 0 ? $this->now : (int) strtotime(self::TODAY . ' 12:00:00');
    return new TrackerRollup($this->db, $this->settings, new VisitorHasher(self::SECRET), new TrackerLock($this->db, $this->lockFile), static fn(): string => self::TODAY, static fn(): int => $now);
  }

  private function repository(): TrackerAnalyticsRepository
  {
    return new TrackerAnalyticsRepository($this->db, $this->settings);
  }

  /** @param array<string,mixed> $input */
  private function filters(array $input = []): TrackerAnalyticsFilters
  {
    return TrackerAnalyticsFilters::fromInput($input + ['from' => '2026-10-01', 'to' => '2026-10-02'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY);
  }

  /** Visitas de dos días con robots, IP interna, vacíos y URLs con parámetros de seguimiento. @return array<string,int> */
  private function seed(): array
  {
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => "10.0.0.0/8\n", 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);
    $promo = ['camp_slug' => 'promo-octubre', 'utm_source' => 'Facebook', 'utm_medium' => 'Redes', 'ip_address' => '1.1.1.1'];
    $ids = [];
    $ids['r1'] = CommercialTrackerDatabase::hit($this->db, $promo + ['fecha_visita' => '2026-10-01 08:00:00', 'page_url' => self::PAGE . '?fbclid=XYZ&utm_source=fb',
      'referrer' => 'https://www.facebook.com/post/1', 'object_id' => '101', 'object_ref' => 'INM-101']);
    $ids['r2'] = CommercialTrackerDatabase::hit($this->db, $promo + ['fecha_visita' => '2026-10-01 09:00:00', 'page_url' => 'HTTPS://SUCASAINMOBILIARIA.COM.CO/inmueble/abc?gclid=1#fotos',
      'referrer' => 'https://m.facebook.com/x', 'user_id' => '5', 'object_id' => '101', 'object_ref' => 'INM-101']);
    $ids['r3'] = CommercialTrackerDatabase::hit($this->db, ['camp_slug' => '', 'utm_source' => '', 'utm_medium' => '', 'ip_address' => '2.2.2.2', 'user_agent' => '',
      'fecha_visita' => '2026-10-01 10:00:00', 'country' => '', 'city' => '']);
    $ids['r4'] = CommercialTrackerDatabase::hit($this->db, ['camp_slug' => '  ', 'utm_source' => '', 'utm_medium' => null, 'ip_address' => '10.0.0.5',
      'fecha_visita' => '2026-10-01 11:00:00', 'referrer' => 'https://sucasainmobiliaria.com.co/inmuebles']);
    $ids['r5'] = CommercialTrackerDatabase::hit($this->db, $promo + ['fecha_visita' => '2026-10-01 12:00:00', 'event_type' => 'event', 'event_label' => 'whatsapp']);
    $ids['r6'] = CommercialTrackerDatabase::hit($this->db, $promo + ['fecha_visita' => 'sin fecha']);
    $ids['r7'] = CommercialTrackerDatabase::hit($this->db, $promo + ['fecha_visita' => '2026-10-02 08:00:00', 'page_url' => self::PAGE . '?utm_campaign=x', 'object_id' => '101']);
    $ids['r8'] = CommercialTrackerDatabase::hit($this->db, ['camp_slug' => 'promo-octubre', 'utm_source' => 'Instagram', 'utm_medium' => 'Redes', 'ip_address' => '3.3.3.3',
      'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'fecha_visita' => '2026-10-02 09:00:00',
      'page_url' => 'https://sucasainmobiliaria.com.co/inmueble/def', 'object_id' => '102']);
    $this->db->insert('wp_jet_cct_tracker_camps', ['slug' => 'promo-octubre', 'nombre' => 'Promo de octubre']);
    return $ids;
  }

  /** @return array<string,int> "fecha|campaña|fuente|medio|registrado|robot|interna" => visitas */
  private function dailyRows(): array
  {
    $out = [];
    foreach ($this->db->getResults('SELECT * FROM wp_scm_tracker_daily ORDER BY fecha, camp_slug, utm_source, registered, bot, internal') as $row) {
      $out[implode('|', [$row['fecha'], $row['camp_slug'], $row['utm_source'], $row['utm_medium'], $row['registered'], $row['bot'], $row['internal']])] = (int) $row['hits'];
    }
    return $out;
  }

  public function testBotDetectorRecognizesCrawlersPreviewsAndEmptyAgents(): void
  {
    foreach (['', 'Googlebot/2.1', 'facebookexternalhit/1.1', 'WhatsApp/2.23.20.0', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 (compatible; AhrefsBot/7.0)',
      'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0)', 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0',
      'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'TelegramBot (like TwitterBot)', 'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
      'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Mozilla/5.0 (compatible; Baiduspider/2.0)'] as $agent) {
      self::assertTrue(BotDetector::isBot($agent), $agent);
    }
    // Navegadores reales que el patrón anterior tomaba por robots (celulares CUBOT, DuckDuckGo y el navegador de Telegram).
    foreach ([CommercialTrackerDatabase::CHROME, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Instagram 300.0',
      'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36 [FBAN/EMA;FBAV/400.0]',
      'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
      'Mozilla/5.0 (Linux; Android 13; SM-A135M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36 DuckDuckGo/5',
      'Mozilla/5.0 (Linux; Android 12; moto g22) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36 Telegram-Android/10.14.5 (Motorola moto g22; Android 12; SDK 31; AVERAGE)',
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0 (monitor 27 pulgadas; preview; uptime)'] as $agent) {
      self::assertFalse(BotDetector::isBot($agent), $agent);
    }
  }

  public function testInternalIpMatcherAcceptsIpsCidrAndPrefixes(): void
  {
    $list = InternalIpMatcher::normalizeList("190.85.10.4, 10.0.0.0/8\n181.49.*  2800:e2:4000::/40 2800:E2:1::1 181.49.");
    self::assertSame(['190.85.10.4', '10.0.0.0/8', '181.49.', '2800:e2:4000::/40', '2800:e2:1::1'], $list);
    $matcher = new InternalIpMatcher($list);
    self::assertTrue($matcher->matches('190.85.10.4'));
    self::assertFalse($matcher->matches('190.85.10.40'));
    self::assertTrue($matcher->matches('10.200.3.4'));
    self::assertTrue($matcher->matches('181.49.7.7'));
    self::assertFalse($matcher->matches('181.4.7.7'));
    self::assertTrue($matcher->matches('2800:e2:40ff::9'));
    self::assertFalse($matcher->matches('2800:e2:5000::9'));
    self::assertTrue($matcher->matches('2800:e2:1:0::1'));
    self::assertFalse($matcher->matches(''));
    self::assertFalse((new InternalIpMatcher([]))->matches('10.0.0.1'));
    // IPv4 mapeada en IPv6 (algunos servidores y proxys la registran así): es la misma IP para reglas, prefijos y únicos.
    self::assertSame('10.1.2.3', InternalIpMatcher::canonical('::FFFF:10.1.2.3'));
    self::assertTrue($matcher->matches('::ffff:10.1.2.3'));
    self::assertTrue($matcher->matches('::ffff:181.49.7.7'));
    self::assertTrue($matcher->matches('::ffff:190.85.10.4'));
    self::assertFalse($matcher->matches('::ffff:190.85.10.40'));
    $hasher = new VisitorHasher(self::SECRET);
    self::assertSame($hasher->hash('10.1.2.3'), $hasher->hash('::ffff:10.1.2.3'));
    self::assertSame($hasher->hash('2800:e2:1::1'), $hasher->hash('2800:E2:1:0::1'));

    foreach (['999.1.1.1', '10.0.0.0/40', '10.0.0.0/4', 'oficina', '10.0'] as $invalid) {
      try {
        InternalIpMatcher::normalizeList($invalid);
        self::fail('Debió rechazar ' . $invalid);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString('no son válidas', $exception->getMessage());
      }
    }
  }

  public function testUrlNormalizerGroupsTrackingVariants(): void
  {
    self::assertSame(self::PAGE, UrlNormalizer::page(self::PAGE . '?fbclid=1&utm_source=fb&UTM_MEDIUM=x#top'));
    self::assertSame(self::PAGE, UrlNormalizer::page('HTTPS://SucasaInmobiliaria.com.co/inmueble/abc?gclid=9'));
    self::assertSame('https://sucasainmobiliaria.com.co/buscar?ciudad=bogota&tipo=apto', UrlNormalizer::page('https://sucasainmobiliaria.com.co/buscar?tipo=apto&fbclid=2&ciudad=bogota'));
    self::assertSame('https://sucasainmobiliaria.com.co/', UrlNormalizer::page('https://sucasainmobiliaria.com.co'));
    self::assertSame('', UrlNormalizer::page('javascript:alert(1)'));
    self::assertSame('facebook.com', UrlNormalizer::host('https://www.Facebook.com/post'));
    self::assertSame(['sucasainmobiliaria.com.co', 'skc.com.co'], UrlNormalizer::normalizeDomains("https://www.sucasainmobiliaria.com.co/x\nskc.com.co"));
    self::assertTrue(UrlNormalizer::isOwnHost('blog.skc.com.co', ['skc.com.co']));
    self::assertFalse(UrlNormalizer::isOwnHost('notskc.com.co', ['skc.com.co']));
  }

  public function testRollupMatchesHandComputedTotals(): void
  {
    $ids = $this->seed();
    $result = $this->rollup()->run(null, 3);
    self::assertSame(8, $result['processed']);
    self::assertSame(6, $result['counted']);
    self::assertSame($ids['r8'], $result['last_id']);
    self::assertSame($ids['r8'], TrackerRollup::state($this->db)['last_id']);
    self::assertSame('2026-10-02 09:00:00', TrackerRollup::state($this->db)['last_fecha']);

    self::assertSame([
      '2026-10-01|general|Directo|Sin medio|0|0|1' => 1,
      '2026-10-01|general|Directo|Sin medio|0|1|0' => 1,
      '2026-10-01|promo-octubre|Facebook|Redes|0|0|0' => 1,
      '2026-10-01|promo-octubre|Facebook|Redes|1|0|0' => 1,
      '2026-10-02|promo-octubre|Facebook|Redes|0|0|0' => 1,
      '2026-10-02|promo-octubre|Instagram|Redes|0|1|0' => 1,
    ], $this->dailyRows());

    // La IP nunca queda en los resúmenes; un visitante por día y combinación.
    $visitors = $this->db->getResults('SELECT * FROM wp_scm_tracker_daily_visitors');
    self::assertCount(5, $visitors);
    $hash = (new VisitorHasher(self::SECRET))->hash('1.1.1.1');
    self::assertSame(2, (int) $this->db->getVar("SELECT hits FROM wp_scm_tracker_daily_visitors WHERE fecha = '2026-10-01' AND visitor_hash = ?", [$hash]));
    foreach ($this->db->getResults('SELECT * FROM wp_scm_tracker_daily_visitors') as $row) {
      self::assertSame(64, strlen((string) $row['visitor_hash']));
      self::assertStringNotContainsString('1.1.1.1', implode('|', $row));
    }

    // Las variantes con fbclid, gclid y utm_* son la misma página.
    self::assertSame(2, (int) $this->db->getVar("SELECT hits FROM wp_scm_tracker_daily_top WHERE dim = 'page' AND fecha = '2026-10-01' AND value = ? AND bot = 0 AND internal = 0", [self::PAGE]));

    $repository = $this->repository();
    $kpis = $repository->kpis($this->filters());
    self::assertSame(3, $kpis['visits']);
    self::assertSame(1, $kpis['unique_visitors'], 'La misma IP en dos días es un solo visitante.');
    self::assertSame(1, $kpis['registered_visits']);
    self::assertSame(1, $kpis['campaigns']);
    self::assertSame(['total' => 6, 'bots' => 2, 'internal' => 1, 'bots_pct' => 33.3, 'internal_pct' => 16.7, 'bots_excluded' => true, 'internal_excluded' => true], $kpis['excluded']);

    $all = $repository->kpis($this->filters(['exclude_bots' => '0', 'exclude_internal' => '0']));
    self::assertSame(6, $all['visits']);
    self::assertSame(4, $all['unique_visitors']);
    // 'general' (sin campaña) no es una campaña.
    self::assertSame(1, $all['campaigns']);

    self::assertSame([
      ['day' => '2026-10-01', 'visits' => 2, 'unique_visitors' => 1],
      ['day' => '2026-10-02', 'visits' => 1, 'unique_visitors' => 1],
    ], $repository->daily($this->filters()));
    $series = $repository->daily($this->filters(['from' => '2026-09-30', 'to' => '2026-10-03']));
    self::assertCount(4, $series);
    self::assertSame(['day' => '2026-09-30', 'visits' => 0, 'unique_visitors' => 0], $series[0]);

    self::assertSame([['value' => 'promo-octubre', 'label' => 'Promo de octubre', 'detail' => '', 'hits' => 3, 'unique_visitors' => 1]], $repository->top('campaigns', $this->filters()));
    self::assertSame([['value' => self::PAGE, 'label' => self::PAGE, 'detail' => '', 'hits' => 3, 'unique_visitors' => null]], $repository->top('pages', $this->filters()));
    self::assertSame(['Directo', 'facebook.com', 'm.facebook.com'], array_column($repository->top('referrers', $this->filters()), 'label'));
    self::assertSame([['value' => '101', 'label' => '101', 'detail' => 'INM-101', 'hits' => 3, 'unique_visitors' => null]], $repository->top('objects', $this->filters()));
    self::assertSame([['value' => 'Bogotá', 'label' => 'Bogotá', 'detail' => 'Colombia', 'hits' => 3, 'unique_visitors' => null]], $repository->top('cities', $this->filters()));

    $raw = $this->filters(['exclude_bots' => '0', 'exclude_internal' => '0']);
    self::assertContains(['value' => 'Navegación interna', 'label' => 'Navegación interna', 'detail' => '', 'hits' => 1, 'unique_visitors' => null], $repository->top('referrers', $raw));
    self::assertContains('Sin país', array_column($repository->top('countries', $raw), 'label'));
    self::assertSame(['Facebook', 'Directo', 'Instagram'], array_column($repository->top('sources', $raw), 'value'));
    self::assertSame(1, $repository->kpis($this->filters(['source' => 'Instagram', 'exclude_bots' => '0']))['visits']);
    self::assertSame(0, $repository->kpis($this->filters(['campaign' => 'general']))['visits']);
    self::assertSame(2, $repository->kpis($this->filters(['campaign' => 'general', 'exclude_bots' => '0', 'exclude_internal' => '0']))['visits']);

    $options = $repository->options(self::TODAY);
    self::assertSame([['value' => 'general', 'label' => 'Sin campaña'], ['value' => 'promo-octubre', 'label' => 'Promo de octubre']], $options['campaigns']);
    self::assertSame(['Directo', 'Facebook', 'Instagram'], $options['sources']);
    self::assertSame(['Redes', 'Sin medio'], $options['mediums']);
  }

  public function testRunningTwiceDoesNotDoubleCountAndPicksUpNewRows(): void
  {
    $this->seed();
    $rollup = $this->rollup();
    $rollup->run(null, 2);
    $first = $this->dailyRows();
    $second = $rollup->run(null, 2);
    self::assertSame(0, $second['processed']);
    self::assertSame($first, $this->dailyRows());
    // Otra instancia (como el cron) tampoco repite.
    $this->rollup()->run();
    self::assertSame($first, $this->dailyRows());

    $new = CommercialTrackerDatabase::hit($this->db, ['camp_slug' => 'promo-octubre', 'utm_source' => 'Facebook', 'utm_medium' => 'Redes', 'ip_address' => '1.1.1.1', 'fecha_visita' => '2026-10-02 18:00:00']);
    $third = $this->rollup()->run();
    self::assertSame(1, $third['processed']);
    self::assertSame($new, $third['last_id']);
    self::assertSame(2, $this->dailyRows()['2026-10-02|promo-octubre|Facebook|Redes|0|0|0']);
    self::assertSame(1, $this->repository()->kpis($this->filters())['unique_visitors']);
    self::assertSame(4, $this->repository()->kpis($this->filters())['visits']);
  }

  public function testCatchUpIsBoundedAndNeverWaitsForTheLock(): void
  {
    $this->seed();
    $first = $this->rollup()->catchUp(3);
    self::assertTrue($first['ran']);
    self::assertSame(3, $first['processed']);
    self::assertTrue($first['behind']);
    self::assertTrue(TrackerSchema::isReady($this->settings));

    $holder = new TrackerLock($this->db, $this->lockFile);
    self::assertTrue($holder->acquire());
    $busy = $this->rollup()->catchUp();
    self::assertFalse($busy['ran']);
    self::assertTrue($busy['busy']);
    self::assertSame(0, $busy['processed']);
    self::assertTrue($busy['behind']);
    $holder->release();

    $done = $this->rollup()->catchUp();
    self::assertSame(5, $done['processed']);
    self::assertFalse($done['behind']);
    self::assertSame(6, array_sum($this->dailyRows()));
  }

  public function testRebuildFromRecomputesOnlyThoseDaysWithTheNewInternalIps(): void
  {
    $this->seed();
    $this->rollup()->run();
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8 1.1.1.1', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, '2026-11-02');
    self::assertSame('2026-11-02', TrackerAnalyticsConfig::read($this->settings)['classification_changed_at']);
    $before = $this->dailyRows();

    $result = $this->rollup()->rebuildFrom('2026-10-02');
    self::assertSame('2026-10-02', $result['from']);
    self::assertSame(2, $result['counted']);
    $after = $this->dailyRows();
    self::assertSame(array_sum($before), array_sum($after));
    self::assertSame(1, $after['2026-10-01|promo-octubre|Facebook|Redes|0|0|0'], 'El día anterior conserva la clasificación vieja.');
    self::assertArrayNotHasKey('2026-10-02|promo-octubre|Facebook|Redes|0|0|0', $after);
    self::assertSame(1, $after['2026-10-02|promo-octubre|Facebook|Redes|0|0|1']);
    // Repetir el recálculo da lo mismo.
    $this->rollup()->rebuildFrom('2026-10-02');
    self::assertSame($after, $this->dailyRows());
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_scm_tracker_daily_visitors WHERE fecha = '2026-10-02' AND internal = 1"));

    $this->expectException(\InvalidArgumentException::class);
    $this->rollup()->rebuildFrom('2026-12-01');
  }

  public function testPurgeOnlyDeletesRolledUpViewsOlderThanTheRetention(): void
  {
    $ids = $this->seed();
    $recent = CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => '2026-10-20 10:00:00']);
    $this->rollup()->run(5);
    $count = static fn(Database $db): int => (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tracker_logs');

    // 0 días = nunca borrar.
    $off = $this->rollup()->purge();
    self::assertFalse($off['enabled']);
    self::assertSame(9, $count($this->db));

    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8', 'exclude_bots' => '1', 'exclude_internal' => '1', 'raw_retention_days' => '30'], 7, self::TODAY);
    $before = $this->dailyRows();
    $purge = $this->rollup()->purge(2);
    self::assertSame(['enabled' => true, 'days' => 30, 'cutoff' => '2026-10-04', 'deleted' => 4, 'first' => '2026-10-01 08:00:00', 'last' => '2026-10-01 11:00:00'], $purge);
    $left = array_map('intval', $this->db->getCol('SELECT _ID FROM wp_jet_cct_tracker_logs ORDER BY _ID'));
    // r5 (clic, no resumido), r6 (sin fecha), r7 y r8 (aún sin resumir) y la visita reciente se conservan.
    self::assertSame([$ids['r5'], $ids['r6'], $ids['r7'], $ids['r8'], $recent], $left);
    self::assertSame($before, $this->dailyRows(), 'Los resúmenes no cambian al purgar.');
    $state = TrackerRollup::state($this->db);
    self::assertSame('2026-10-04', $state['purged_before']);
    self::assertSame(4, $state['last_purge']['deleted']);
    self::assertSame('2026-10-01 08:00:00', $state['last_purge']['first']);

    // Recalcular desde antes del corte no borra los días cuyas crudas ya no existen.
    $this->rollup()->run();
    $full = $this->dailyRows();
    $rebuild = $this->rollup()->rebuildFrom('2026-09-01');
    self::assertSame('2026-10-04', $rebuild['from']);
    self::assertSame($full, $this->dailyRows());
  }

  public function testSettingsSectionValidatesAndStoresInPanelSettings(): void
  {
    $_SESSION = ['scm_user_cargo' => '13', 'scm_user_id' => 7, 'scm_employee_id' => '1007'];
    // load() también arma las demás secciones (cargos del calendario, calculadora de aseo).
    $pdo = $this->db->pdo();
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    $pdo->exec('CREATE TABLE wp_jet_cct_funcionarios (_ID INTEGER PRIMARY KEY, id_cargo TEXT, activo TEXT, nombre TEXT, id_empleado TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_cargos (_ID INTEGER PRIMARY KEY, nombre_cargo TEXT)');
    $pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
    $service = new CommercialSettingsService($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), []);
    self::assertContains('analitica', CommercialSettingsService::SECTIONS);
    $loaded = $service->load()['analitica'];
    self::assertSame([], $loaded['internal_ips']);
    self::assertTrue($loaded['exclude_bots']);
    self::assertTrue($loaded['exclude_internal']);
    self::assertSame(0, $loaded['raw_retention_days']);
    self::assertSame(0, $loaded['status']['last_id']);
    self::assertSame(0, $loaded['status']['pending']);

    foreach ([
      ['internal_ips' => '10.0.0.300'],
      ['own_domains' => 'no es dominio'],
      ['raw_retention_days' => '3651'],
      ['raw_retention_days' => '-1'],
      ['raw_retention_days' => '10'],
      ['raw_retention_days' => 'abc'],
    ] as $invalid) {
      try {
        $service->save('analitica', $invalid);
        self::fail('Debió rechazar ' . json_encode($invalid));
      } catch (\InvalidArgumentException) {
        self::assertSame(0, TrackerAnalyticsConfig::read($this->settings)['raw_retention_days']);
      }
    }

    $message = $service->save('analitica', ['internal_ips' => "190.85.10.4\n190.85.10.4\n10.0.0.0/8", 'exclude_bots' => '0', 'exclude_internal' => 'on', 'raw_retention_days' => '365', 'own_domains' => 'https://www.sucasainmobiliaria.com.co']);
    self::assertStringContainsString('pide a sistemas que recalculen', $message);
    self::assertStringNotContainsString('bin/', $message);
    $stored = $this->settings->get(TrackerAnalyticsConfig::SETTING);
    self::assertSame(['190.85.10.4', '10.0.0.0/8'], $stored['internal_ips']);
    self::assertFalse($stored['exclude_bots']);
    self::assertTrue($stored['exclude_internal']);
    self::assertSame(365, $stored['raw_retention_days']);
    self::assertSame(['sucasainmobiliaria.com.co'], $stored['own_domains']);

    CommercialTrackerDatabase::hit($this->db, []);
    self::assertSame(1, $service->load()['analitica']['status']['pending']);

    $_SESSION['scm_user_cargo'] = '9';
    $this->expectException(\RuntimeException::class);
    $service->save('analitica', ['raw_retention_days' => '0']);
  }

  public function testRawLogKeysetPaginationHidesNetworkDataWithoutPermission(): void
  {
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);
    $expected = [];
    for ($i = 0; $i < 70; $i++) {
      // Varias visitas por segundo, para que el desempate por _ID importe.
      $fecha = sprintf('2026-10-%02d 10:00:%02d', 10 + intdiv($i, 30), intdiv($i % 30, 3));
      $bot = $i % 7 === 0;
      $internal = $i % 11 === 0 && !$bot;
      $id = CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => $fecha, 'ip_address' => $internal ? '10.1.1.1' : '200.1.1.' . $i,
        'user_agent' => $bot ? 'Googlebot/2.1' : CommercialTrackerDatabase::CHROME, 'page_url' => self::PAGE . '?n=' . $i, 'camp_slug' => $i % 2 ? 'promo-octubre' : '']);
      if (!$bot && !$internal) {
        $expected[] = ['fecha' => $fecha, 'id' => $id];
      }
    }
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => '2026-07-01 10:00:00']);
    usort($expected, static fn(array $a, array $b): int => [$b['fecha'], $b['id']] <=> [$a['fecha'], $a['id']]);

    $repository = $this->repository();
    $filters = TrackerAnalyticsFilters::fromInput(['from' => '2026-01-01', 'to' => '2026-10-31'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY);
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
      $page = $repository->logs($filters, $cursor);
      self::assertLessThanOrEqual(TrackerAnalyticsRepository::LOG_PAGE_SIZE, count($page['rows']));
      foreach ($page['rows'] as $row) {
        self::assertArrayNotHasKey('ip', $row);
        self::assertArrayNotHasKey('user_agent', $row);
        self::assertFalse($row['bot']);
        $seen[] = ['fecha' => $row['fecha'], 'id' => $row['id']];
      }
      $cursor = $page['next_cursor'];
      $pages++;
    } while ($cursor !== null && $pages < 10);
    self::assertSame($expected, $seen);
    self::assertSame(3, $pages);
    self::assertSame('2026-08-03', $page['from'], 'El visor de registros se limita a 90 días.');
    self::assertTrue($page['clamped']);

    $withNetwork = $repository->logs($filters, null, true)['rows'][0];
    self::assertSame('200.1.1.' . (int) substr((string) $withNetwork['page_url'], -2), $withNetwork['ip']);
    self::assertSame(CommercialTrackerDatabase::CHROME, $withNetwork['user_agent']);

    $general = $repository->logs(TrackerAnalyticsFilters::fromInput(['from' => '2026-10-01', 'to' => '2026-10-31', 'campaign' => 'general', 'exclude_bots' => '0', 'exclude_internal' => '0'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY));
    self::assertCount(25, $general['rows']);
    self::assertSame(['general'], array_values(array_unique(array_column($general['rows'], 'campaign'))));
    // Un cursor mal formado vuelve a la primera página.
    self::assertSame($expected[0]['id'], $repository->logs($filters, 'cualquier-cosa')['rows'][0]['id']);
  }

  public function testCommandRunsIncrementalPurgeAndRejectsUnknownOptions(): void
  {
    $this->seed();
    $out = fopen('php://memory', 'w+');
    self::assertIsResource($out);
    $command = new TrackerRollupCommand($this->rollup(), $out);
    self::assertSame(2, $command->run(['--rebuild-from=ayer']));
    self::assertSame(2, $command->run(['--borrar-todo']));
    self::assertSame(0, $command->run(['--purge', '--batch=2']));
    rewind($out);
    $text = (string) stream_get_contents($out);
    self::assertStringContainsString('8 filas leídas, 6 vistas sumadas', $text);
    self::assertStringContainsString('Purga desactivada', $text);
    self::assertStringContainsString('pendientes 0', $text);
    self::assertSame(8, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tracker_logs'));
    self::assertSame(['backfill' => true, 'rebuild-from' => '2026-10-01', 'max' => '10'], TrackerRollupCommand::parse(['--backfill', '--rebuild-from=2026-10-01', '--max=10']));
  }

  /** Visitas de tres días (2026-10-01 a 03): una de cada cinco desde 1.1.1.x, que luego se vuelve IP interna. */
  private function seedThreeDays(int $count = 50): void
  {
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);
    for ($i = 0; $i < $count; $i++) {
      CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => sprintf('2026-10-%02d %02d:%02d:00', 1 + $i % 3, 8 + intdiv($i, 6), $i % 60),
        'ip_address' => $i % 5 === 0 ? '1.1.1.' . $i : '200.2.2.' . $i, 'camp_slug' => 'promo-octubre']);
    }
  }

  private function totalHits(): int
  {
    return (int) $this->db->getVar('SELECT COALESCE(SUM(hits), 0) FROM wp_scm_tracker_daily');
  }

  public function testInterruptedRebuildNeverLosesVisitsAndTheNextCronRunResumesIt(): void
  {
    $this->seedThreeDays();
    $this->rollup()->run();
    self::assertSame(50, $this->totalHits());
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8 1.1.1.0/24', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);

    // Se corta después del primer día (proceso detenido, conexión perdida...).
    try {
      $this->rollup()->rebuildFrom('2026-10-01', 10, static function (int $processed, string $day): void {
        throw new \RuntimeException('cortado en ' . $day);
      });
      self::fail('El recálculo debía cortarse.');
    } catch (\RuntimeException $exception) {
      self::assertSame('cortado en 2026-10-01', $exception->getMessage());
    }
    // Ningún día queda a medias: el 1 ya tiene la clasificación nueva, el 2 y el 3 la vieja, y el total no cambia.
    self::assertSame(50, $this->totalHits());
    $internal = fn(string $day): int => (int) $this->db->getVar('SELECT COALESCE(SUM(hits), 0) FROM wp_scm_tracker_daily WHERE fecha = ? AND internal = 1', [$day]);
    self::assertGreaterThan(0, $internal('2026-10-01'));
    self::assertSame(0, $internal('2026-10-02'));
    $state = TrackerRollup::state($this->db);
    self::assertSame(['from' => '2026-10-01', 'requested' => '2026-10-01', 'next' => '2026-10-02'], $state['rebuild']);
    self::assertSame($state['rebuild'], $this->rollup()->status()['rebuild']);
    self::assertSame($state['rebuild'], $this->rollup()->catchUp()['rebuild'], 'La página sabe que hay un recálculo en curso.');

    // La siguiente corrida del cron lo retoma desde el día que faltaba.
    $out = fopen('php://memory', 'w+');
    self::assertIsResource($out);
    self::assertSame(0, (new TrackerRollupCommand($this->rollup(), $out))->run([]));
    rewind($out);
    self::assertStringContainsString('Se retomó el recálculo pendiente desde 2026-10-01', (string) stream_get_contents($out));
    self::assertSame([], TrackerRollup::state($this->db)['rebuild']);
    self::assertSame(50, $this->totalHits());
    $expected = (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_logs WHERE ip_address LIKE '1.1.1.%'");
    self::assertSame($expected, (int) $this->db->getVar('SELECT SUM(hits) FROM wp_scm_tracker_daily WHERE internal = 1'));
    self::assertNull($this->rollup()->resumeRebuild());
  }

  public function testRecentVisitsWaitSoALateCommitWithALowerIdIsNotSkipped(): void
  {
    $this->now = (int) strtotime('2026-11-03 12:00:00');
    CommercialTrackerDatabase::hit($this->db, ['_ID' => 1, 'fecha_visita' => '2026-11-03 11:00:00']);
    // _ID 3 se confirmó; el 2 todavía no (InnoDB ya le dio el número).
    CommercialTrackerDatabase::hit($this->db, ['_ID' => 3, 'fecha_visita' => '2026-11-03 11:59:50']);
    $first = $this->rollup()->run();
    self::assertSame(1, $first['processed'], 'La visita de hace 10 segundos espera.');
    self::assertSame(1, $first['last_id']);
    self::assertFalse($this->rollup()->catchUp()['behind'], 'Lo que espera por reciente no cuenta como atraso.');

    CommercialTrackerDatabase::hit($this->db, ['_ID' => 2, 'fecha_visita' => '2026-11-03 11:59:40']);
    $this->now += 300;
    $second = $this->rollup()->run();
    self::assertSame(2, $second['processed']);
    self::assertSame(3, $second['last_id']);
    self::assertSame(3, $this->totalHits(), 'La visita confirmada tarde (con _ID menor) también cuenta.');

    // Una fecha absurda (más de un día en el futuro) no frena el rollup.
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => '2027-05-01 10:00:00']);
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => '2026-11-03 11:30:00']);
    self::assertSame(2, $this->rollup()->run()['processed']);
  }

  public function testRollupStateHasItsOwnTableAndNeverTouchesTheConfigAuthor(): void
  {
    $this->seed();
    TrackerSchema::ensure($this->db, $this->settings);
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8', 'exclude_bots' => '1', 'exclude_internal' => '1'], 101, self::TODAY);
    $row = static fn(Database $db): array => $db->getRow("SELECT cct_author_id, cct_modified, valor FROM wp_jet_cct_confi_sistema WHERE funcion = 'control_servicios_config'") ?? [];
    $before = $row($this->db);
    self::assertSame('101', (string) $before['cct_author_id']);

    $this->rollup()->catchUp();
    $this->rollup()->run();
    $this->rollup()->rebuildFrom('2026-10-01');
    self::assertSame($before, $row($this->db), 'Ni la marca de agua ni el recálculo reescriben la configuración del panel.');
    self::assertSame(8, (int) $this->db->getVar('SELECT last_id FROM wp_scm_tracker_state WHERE id = 1'));
    self::assertArrayNotHasKey(TrackerSchema::LEGACY_STATE_SETTING, $this->settings->all());

    // La única escritura del sistema en Settings (la versión del esquema) tampoco borra el autor.
    $this->settings->set(TrackerSchema::SETTING, 1, 0, true);
    self::assertSame('101', (string) $row($this->db)['cct_author_id']);
  }

  public function testSchemaVersionTwoKeysTopByDimensionAndMigratesVersionOneFromTheCommandLine(): void
  {
    TrackerSchema::ensure($this->db, $this->settings);
    $pk = static function (Database $db): array {
      $columns = [];
      foreach ($db->getResults('PRAGMA table_info(wp_scm_tracker_daily_top)') as $column) {
        if ((int) $column['pk'] > 0) {
          $columns[(int) $column['pk']] = (string) $column['name'];
        }
      }
      ksort($columns);
      return array_values($columns);
    };
    self::assertSame(['dim', 'fecha', 'camp_slug', 'utm_source', 'utm_medium', 'value_hash', 'bot', 'internal'], $pk($this->db));
    self::assertSame([], $this->db->getCol("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_scm_tracker_top_dim'"));

    // Una base con la versión 1 (llave por fecha, índice aparte y estado dentro de Settings).
    $db = CommercialTrackerDatabase::create();
    $settings = new Settings($db);
    $pdo = $db->pdo();
    $pdo->exec("CREATE TABLE wp_scm_tracker_daily_top (fecha TEXT NOT NULL, dim TEXT NOT NULL, camp_slug TEXT NOT NULL, utm_source TEXT NOT NULL, utm_medium TEXT NOT NULL,
      value_hash TEXT NOT NULL, bot INTEGER NOT NULL DEFAULT 0, internal INTEGER NOT NULL DEFAULT 0, value TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '',
      hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, dim, camp_slug, utm_source, utm_medium, value_hash, bot, internal))");
    $pdo->exec('CREATE INDEX idx_scm_tracker_top_dim ON wp_scm_tracker_daily_top (dim, fecha)');
    $pdo->exec("INSERT INTO wp_scm_tracker_daily_top VALUES ('2026-10-01', 'page', 'general', 'Directo', 'Sin medio', 'abc', 0, 0, 'https://x/', '', 7)");
    $settings->set(TrackerSchema::SETTING, 1);
    $settings->set(TrackerSchema::LEGACY_STATE_SETTING, ['last_id' => 42, 'last_fecha' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:05:00', 'purged_before' => '2026-09-01', 'last_purge' => ['deleted' => 3]]);
    self::assertFalse(TrackerSchema::isReady($settings));

    TrackerSchema::ensure($db, $settings);
    self::assertTrue(TrackerSchema::isReady($settings));
    self::assertSame(['dim', 'fecha', 'camp_slug', 'utm_source', 'utm_medium', 'value_hash', 'bot', 'internal'], $pk($db));
    self::assertSame(7, (int) $db->getVar("SELECT hits FROM wp_scm_tracker_daily_top WHERE dim = 'page' AND value_hash = 'abc'"));
    $state = TrackerRollup::state($db);
    self::assertSame([42, '2026-10-01 10:00:00', '2026-09-01', ['deleted' => 3]], [$state['last_id'], $state['last_fecha'], $state['purged_before'], $state['last_purge']]);
    self::assertNull($settings->get(TrackerSchema::LEGACY_STATE_SETTING));
  }

  public function testExclusionNoteAddsUpAndPseudoCampaignsAreNotCampaigns(): void
  {
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '10.0.0.0/8', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);
    $bot = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
    CommercialTrackerDatabase::hit($this->db, ['camp_slug' => 'inmueble_view', 'object_id' => '101']);
    CommercialTrackerDatabase::hit($this->db, ['camp_slug' => '', 'ip_address' => '10.0.0.1']);
    CommercialTrackerDatabase::hit($this->db, ['camp_slug' => '', 'ip_address' => '10.0.0.2', 'user_agent' => $bot]);
    CommercialTrackerDatabase::hit($this->db, ['camp_slug' => 'inmueble_view', 'user_agent' => $bot]);
    $this->rollup()->run();
    $repository = $this->repository();

    // Robots contados, internas excluidas: las dos internas (también la del robot) quedan por fuera.
    $kpis = $repository->kpis($this->filters(['exclude_bots' => '0', 'exclude_internal' => '1']));
    self::assertSame(2, $kpis['visits']);
    self::assertSame(4, $kpis['excluded']['total']);
    self::assertSame(2, $kpis['excluded']['internal']);
    self::assertSame($kpis['excluded']['total'] - $kpis['visits'], $kpis['excluded']['internal']);

    // Los dos excluidos: cada visita cuenta una vez (el robot interno va con los robots).
    $both = $repository->kpis($this->filters());
    self::assertSame(1, $both['visits']);
    self::assertSame([2, 1], [$both['excluded']['bots'], $both['excluded']['internal']]);
    self::assertSame($both['excluded']['total'] - $both['visits'], $both['excluded']['bots'] + $both['excluded']['internal']);

    // 'general' e 'inmueble_view' los pone el tracker: no son campañas, pero se listan con un nombre claro.
    $all = $this->filters(['exclude_bots' => '0', 'exclude_internal' => '0']);
    self::assertSame(0, $repository->kpis($all)['campaigns']);
    self::assertSame(['Sin campaña', 'Vista de inmueble'], array_column($repository->top('campaigns', $all), 'label'));
    self::assertSame(['general', 'inmueble_view'], array_column($repository->top('campaigns', $all), 'value'));
  }

  public function testRawLogCursorIsTheIdAndOnlyReadsTheDateRange(): void
  {
    TrackerAnalyticsConfig::save($this->settings, ['internal_ips' => '', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, self::TODAY);
    foreach (['2026-08-20', '2026-09-15', '2026-10-11', '2026-10-12'] as $day) {
      for ($i = 0; $i < 30; $i++) {
        CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => sprintf('%s %02d:%02d:00', $day, 8 + intdiv($i, 10), $i)]);
      }
    }
    $repository = $this->repository();
    $one = TrackerAnalyticsFilters::fromInput(['from' => '2026-10-11', 'to' => '2026-10-11'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY);
    $first = $repository->logs($one);
    self::assertCount(25, $first['rows']);
    self::assertMatchesRegularExpression('/^\d+$/', (string) $first['next_cursor']);
    self::assertSame((string) $first['rows'][24]['id'], $first['next_cursor']);
    $second = $repository->logs($one, $first['next_cursor']);
    self::assertCount(5, $second['rows']);
    self::assertNull($second['next_cursor']);
    $days = array_unique(array_map(static fn(array $row): string => substr((string) $row['fecha'], 0, 10), array_merge($first['rows'], $second['rows'])));
    self::assertSame(['2026-10-11'], array_values($days));
    $ids = array_column(array_merge($first['rows'], $second['rows']), 'id');
    $sorted = $ids;
    rsort($sorted);
    self::assertSame($sorted, $ids, 'De la más reciente a la más antigua.');
    // Un rango sin visitas no devuelve nada.
    self::assertSame([], $repository->logs(TrackerAnalyticsFilters::fromInput(['from' => '2026-10-01', 'to' => '2026-10-05'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY))['rows']);
  }
}
