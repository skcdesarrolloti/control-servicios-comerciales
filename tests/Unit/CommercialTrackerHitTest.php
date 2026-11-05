<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerHitRecorder;
use SCM\Core\Database;
use SCM\Core\Settings;
use Tests\Fixtures\CommercialTrackerDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialTrackerDatabase.php';

/** Receptor de visitas del sitio (public/tracker-hit.php) y el reemplazo de la carpeta de dashboard-marketing. */
final class CommercialTrackerHitTest extends TestCase
{
  private Database $db;
  private Settings $settings;
  private string $cache;
  /** @var list<string> */
  private array $lookups = [];

  protected function setUp(): void
  {
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialTrackerDatabase::create();
    $this->settings = new Settings($this->db);
    $this->cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-tracker-geo-' . bin2hex(random_bytes(4)) . '.json';
  }

  protected function tearDown(): void
  {
    @unlink($this->cache);
  }

  private function recorder(): TrackerHitRecorder
  {
    return new TrackerHitRecorder($this->db, $this->settings, $this->cache, function (string $ip): ?array {
      $this->lookups[] = $ip;
      return ['country' => 'Colombia', 'city' => 'Medellín'];
    });
  }

  /** @return array<string,mixed> */
  private function row(int $id): array
  {
    return $this->db->getRow('SELECT * FROM wp_jet_cct_tracker_logs WHERE _ID = ?', [$id]) ?? [];
  }

  public function testVisitsAreStoredWithTheLegacyRules(): void
  {
    $recorder = $this->recorder();
    $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'basura, 181.50.1.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0'];
    $direct = $this->row($recorder->record(['url' => 'https://sucasainmobiliaria.com.co/', 'referrer' => 'javascript:alert(1)'], $server, '2026-11-04 10:00:00'));
    self::assertSame(['general', 'Directo', 'Navegador', '181.50.1.7', 'Colombia', 'Medellín', '', 'view', '2026-11-04 10:00:00'],
      [$direct['camp_slug'], $direct['utm_source'], $direct['utm_medium'], $direct['ip_address'], $direct['country'], $direct['city'], $direct['referrer'], $direct['event_type'], $direct['fecha_visita']]);

    $property = $this->row($recorder->record(['slug' => 'inmueble_view', 'object_id' => '101', 'object_ref' => '<b>INM-101</b>'], $server));
    self::assertSame(['inmueble_view', 'Inmuebles', 'Pagina Web', '101', 'INM-101'], [$property['camp_slug'], $property['utm_source'], $property['utm_medium'], (string) $property['object_id'], $property['object_ref']]);

    $campaign = $this->row($recorder->record(['slug' => 'general', 'utm_campaign' => 'revista-nov', 'utm_source' => 'correo', 'utm_medium' => 'correo'], $server));
    self::assertSame(['revista-nov', 'correo', 'correo'], [$campaign['camp_slug'], $campaign['utm_source'], $campaign['utm_medium']]);
    self::assertSame(['revista-nov'], $this->db->getCol('SELECT slug FROM wp_jet_cct_tracker_camps'), 'Solo las campañas reales se agregan.');
    $recorder->record(['utm_campaign' => 'revista-nov'], $server);
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tracker_camps'));

    // La ubicación se pregunta una vez por IP y nunca para IPs privadas.
    self::assertSame(['181.50.1.7'], $this->lookups);
    $private = $this->row($recorder->record([], ['REMOTE_ADDR' => '192.168.1.5']));
    self::assertSame(['192.168.1.5', ''], [$private['ip_address'], $private['country']]);
    self::assertSame(['181.50.1.7'], $this->lookups);
    self::assertSame('181.60.0.1', TrackerHitRecorder::clientIp(['HTTP_CF_CONNECTING_IP' => '181.60.0.1', 'REMOTE_ADDR' => '10.0.0.1']));
  }

  public function testOnlyTheSiteAndItsOwnDomainsCanSendVisits(): void
  {
    $recorder = $this->recorder();
    self::assertTrue($recorder->allowsOrigin('https://sucasainmobiliaria.com.co'));
    self::assertTrue($recorder->allowsOrigin('https://www.sucasainmobiliaria.com.co/'));
    self::assertTrue($recorder->allowsOrigin(''), 'sendBeacon puede llegar sin Origin.');
    self::assertFalse($recorder->allowsOrigin('https://otro-sitio.com'));
    self::assertFalse($recorder->allowsOrigin('http://sucasainmobiliaria.com.co'));
    TrackerAnalyticsConfig::save($this->settings, ['own_domains' => "skcsucasa.com\n"], 7);
    self::assertTrue($recorder->allowsOrigin('https://skcsucasa.com'));
    self::assertTrue($recorder->allowsOrigin('https://www.skcsucasa.com'));
  }

  public function testSiteScriptAndLegacyFolderReplacementStayInSync(): void
  {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root . '/public/assets/js/site-tracker.js');
    self::assertSame($script, file_get_contents($root . '/legacy/dashboard-marketing/public/assets/tracker.js'), 'El tracker.js del reemplazo es una copia exacta.');
    self::assertStringContainsString("new URL('../../tracker-hit.php', scriptSrc)", $script);
    self::assertStringContainsString("new URL('../index.php?action=gda-register-hit', scriptSrc)", $script);
    $stub = (string) file_get_contents($root . '/legacy/dashboard-marketing/public/index.php');
    self::assertStringContainsString("require \$panel . '/tracker-hit.php';", $stub);
    self::assertStringContainsString("require \$panel . '/track-email.php';", $stub);
    // El fragmento de WPCode envía a /dashboard-marketing/?action=... y el píxel viejo trae la carpeta repetida.
    self::assertStringContainsString("require __DIR__ . '/public/index.php';", (string) file_get_contents($root . '/legacy/dashboard-marketing/index.php'));
    self::assertStringContainsString('RewriteRule ^ index.php [QSA,L]', (string) file_get_contents($root . '/legacy/dashboard-marketing/.htaccess'));
    // Los correos del panel anterior traen tokens de 32 caracteres hexadecimales.
    $this->db->pdo()->exec('CREATE TABLE wp_jet_cct_email_tracking (_ID INTEGER PRIMARY KEY, tracking_token TEXT, opened_at TEXT, last_opened_at TEXT, open_count INTEGER, last_ip TEXT, last_user_agent TEXT)');
    $this->db->insert('wp_jet_cct_email_tracking', ['tracking_token' => str_repeat('ab', 16), 'open_count' => 0]);
    self::assertTrue((new \SCM\Commercial\Campaigns\EmailOpenTracker($this->db))->record(str_repeat('ab', 16), '181.1.1.1', 'Mail'));
  }
}
