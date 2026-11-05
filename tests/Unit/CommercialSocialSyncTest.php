<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Social\SocialSyncRunner;
use SCM\Commercial\Promotion\Social\Sync\SocialDb;
use SCM\Commercial\Promotion\Social\Sync\TikTokApiService;
use SCM\Commercial\Promotion\Social\Sync\YouTubeApiService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Sincronización de Redes sociales traída de dashboard-marketing. Nunca sale a internet. */
final class CommercialSocialSyncTest extends TestCase
{
  private const CONFIG = [
    'meta' => ['access_token' => 'x', 'instagram_user_id' => '1', 'facebook_page_id' => ''],
    'youtube' => ['client_id' => 'id', 'client_secret' => 'secreto-google', 'redirect_uri' => 'https://sitio.test/youtube/callback'],
    'tiktok' => ['client_key' => '', 'client_secret' => 'secreto-tiktok', 'redirect_uri' => ''],
  ];

  private array $previousSession;
  private Database $db;
  private Settings $settings;
  private string $lock;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    $this->lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-social-lock-' . bin2hex(random_bytes(4));
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    @unlink($this->lock);
  }

  private function runner(array $config = self::CONFIG): SocialSyncRunner
  {
    return new SocialSyncRunner($this->db, $config, $this->lock, CommercialPromotionDatabase::fakeSocialSyncers());
  }

  public function testEachNetworkRunsAloneAndOneFailureDoesNotStopTheRest(): void
  {
    $result = $this->runner()->run('', '2026-10-01', '2026-11-04');
    self::assertFalse($result['ok']);
    self::assertSame(['instagram', 'facebook', 'publicidad', 'youtube', 'tiktok'], array_keys($result['results']));
    self::assertSame(['Instagram entregó solo 30 días.'], $result['results']['instagram']['warnings']);
    self::assertSame(['daily' => 3], $result['results']['instagram']['summary']);
    self::assertTrue($result['results']['youtube']['ok'], 'Sigue después de una red con aviso.');
    self::assertSame([false, 'Conecta primero la cuenta de TikTok.'], [$result['results']['tiktok']['ok'], $result['results']['tiktok']['message']]);
    self::assertTrue($this->runner()->run('youtube', '2026-10-01', '2026-11-04')['ok']);
    $this->expectException(\InvalidArgumentException::class);
    $this->runner()->run('linkedin', '2026-10-01', '2026-11-04');
  }

  public function testOnlyOneSyncAtATime(): void
  {
    $handle = fopen($this->lock, 'c');
    self::assertIsResource($handle);
    flock($handle, LOCK_EX);
    try {
      $this->runner()->run('youtube', '2026-10-01', '2026-11-04');
      self::fail('Debe rechazar la segunda sincronización.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('Ya hay una sincronización en curso', $exception->getMessage());
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  public function testConfiguredOnlySaysIfCredentialsExistWithoutShowingThem(): void
  {
    $configured = $this->runner()->configured();
    self::assertSame(['instagram' => true, 'facebook' => false, 'publicidad' => true, 'youtube' => true, 'tiktok' => false], $configured);
    self::assertStringNotContainsString('secreto', (string) json_encode($configured));
  }

  public function testDatabaseAdapterKeepsTheLegacyApi(): void
  {
    $this->runner();
    $this->db->pdo()->exec('CREATE TABLE wp_prueba (_ID INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, total TEXT)');
    self::assertSame('wp_prueba', SocialDb::table('prueba'));
    $id = SocialDb::insert('wp_prueba', ['nombre' => 'Uno', 'total' => 5]);
    self::assertSame(1, $id);
    self::assertTrue(SocialDb::update('wp_prueba', ['total' => 7], $id));
    self::assertSame('7', (string) SocialDb::value('SELECT total FROM wp_prueba WHERE _ID = ?', 'i', [$id]));
    self::assertSame('Uno', SocialDb::one('SELECT * FROM wp_prueba WHERE nombre = ?', 's', ['Uno'])['nombre']);
    self::assertSame([], SocialDb::one('SELECT * FROM wp_prueba WHERE nombre = ?', 's', ['Nadie']));
    self::assertCount(1, SocialDb::rows('SELECT * FROM wp_prueba'));
    self::assertTrue(SocialDb::execute('DELETE FROM wp_prueba WHERE _ID = ?', 'i', [$id]));
    self::assertSame('secreto-google', SocialDb::config('youtube.client_secret'));
    self::assertSame('v25.0', SocialDb::config('meta.graph_version', 'v25.0'));
  }

  /** Mismo formato de dashboard-marketing: AES-256-GCM con el hash de la llave; base64(iv + etiqueta + cifrado). */
  private static function legacyEncrypt(string $value, string $key): string
  {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = (string) openssl_encrypt($value, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
  }

  public function testTokensSavedByTheLegacyPanelStillWorkWithTheAppSecret(): void
  {
    $config = self::CONFIG;
    $config['tiktok']['client_key'] = 'clave';
    $config['tiktok']['redirect_uri'] = 'https://sitio.test/tiktok/callback';
    $this->runner($config);
    $future = date('Y-m-d H:i:s', time() + 3600);
    $pdo = $this->db->pdo();
    $pdo->exec('CREATE TABLE wp_marketing_youtube_connections (_ID INTEGER PRIMARY KEY, channel_id TEXT, channel_title TEXT, access_token_enc TEXT, refresh_token_enc TEXT, token_expires_at TEXT, connected INTEGER, updated_at TEXT)');
    $pdo->exec('CREATE TABLE wp_marketing_tiktok_connections (_ID INTEGER PRIMARY KEY, open_id TEXT, access_token_enc TEXT, refresh_token_enc TEXT, token_expires_at TEXT, connected INTEGER, updated_at TEXT)');
    $this->db->insert('wp_marketing_youtube_connections', ['channel_id' => 'UC1', 'access_token_enc' => self::legacyEncrypt('token-youtube', 'secreto-google'), 'token_expires_at' => $future, 'connected' => 1, 'updated_at' => $future]);
    $this->db->insert('wp_marketing_tiktok_connections', ['open_id' => 'o1', 'access_token_enc' => self::legacyEncrypt('token-tiktok', 'secreto-tiktok'), 'token_expires_at' => $future, 'connected' => 1, 'updated_at' => $future]);
    self::assertSame('token-youtube', (new YouTubeApiService())->accessToken());
    self::assertSame('token-tiktok', (new TikTokApiService())->accessToken());
  }

  public function testButtonsFollowThePermissionAndConnectionState(): void
  {
    CommercialPromotionDatabase::social($this->db);
    $permissions = $this->settings->get('commercial_permissions', []);
    $permissions['6']['actions'][] = 'redes_sincronizar';
    $this->settings->set('commercial_permissions', $permissions);
    $page = function (string $cargo, array $input): string {
      CommercialPromotionDatabase::login($cargo, '101');
      return PromotionView::page(['promocion_sub' => 'redes_sociales'] + $input, new CommercialAccessPolicy($this->settings, $this->db, ['13']), $this->db, $this->settings, '/public', false, '2026-11-05');
    };
    self::assertContains('redes_sincronizar', CommercialAccessPolicy::OPT_IN_ACTIONS);
    self::assertStringContainsString('data-social-sync="instagram"', $page('6', []));
    self::assertStringNotContainsString('data-social-connect', $page('6', []));
    $youtube = $page('6', ['pr_red' => 'youtube']);
    self::assertStringContainsString('social-callback.php?network=youtube&amp;start=1', $youtube);
    self::assertStringContainsString('Reconectar', $youtube);
    self::assertStringContainsString('data-social-disconnect="youtube"', $youtube);
    $this->db->pdo()->exec('UPDATE wp_marketing_youtube_connections SET connected = 0');
    $off = $page('6', ['pr_red' => 'youtube']);
    self::assertStringContainsString('Conectar cuenta', $off);
    self::assertStringNotContainsString('data-social-disconnect="youtube"', $off);
    $viewer = $page('7', ['pr_red' => 'youtube']);
    foreach (['data-social-sync', 'data-social-connect', 'data-social-disconnect', 'data-social-dialog'] as $action) {
      self::assertStringNotContainsString($action, $viewer);
    }

    $_SESSION['social_flash'] = ['type' => 'error', 'message' => 'Google no autorizó <la conexión>'];
    CommercialPromotionDatabase::login('6', '101', 'Coordinación', ['social_flash' => $_SESSION['social_flash']]);
    $policy = new CommercialAccessPolicy($this->settings, $this->db, ['13']);
    self::assertStringContainsString('Google no autorizó &lt;la conexión&gt;', PromotionView::page(['promocion_sub' => 'redes_sociales'], $policy, $this->db, $this->settings, '/public', false, '2026-11-05'));
    self::assertArrayNotHasKey('social_flash', $_SESSION, 'El aviso sale una sola vez.');
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

  public function testEndpointsCheckPermissionAndReportWarningsAndFailures(): void
  {
    self::assertSame('socialSync', CommercialActionRouter::PROMOTION_ACTIONS['commercial_social_sync']);
    $denied = self::endpoint('7', '303', 'commercial_social_sync', ['network' => 'instagram']);
    self::assertSame([403, 'No tienes permiso para sincronizar las redes sociales.'], [$denied['status'], $denied['json']['data']['message']]);
    self::assertSame(403, self::endpoint('8', '202', 'commercial_social_sync', ['network' => 'instagram'])['status'], 'Sin la subpestaña.');
    self::assertSame(403, self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'instagram', 'nonce' => 'otro'])['status']);

    $ok = self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'instagram']);
    self::assertSame([200, ['Instagram entregó solo 30 días.']], [$ok['status'], $ok['json']['data']['warnings']]);
    $failed = self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'tiktok']);
    self::assertSame([502, 'Conecta primero la cuenta de TikTok.'], [$failed['status'], $failed['json']['data']['message']]);
    self::assertSame(422, self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'linkedin'])['status']);
    self::assertSame(422, self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'instagram', 'from' => '2026-09-02', 'to' => '2026-09-01'])['status'], 'Rango al revés.');
    self::assertSame(422, self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'instagram', 'from' => '2020-01-01', 'to' => '2026-09-01'])['status'], 'Más de 2 años.');
    self::assertSame(200, self::endpoint('13', '101', 'commercial_social_sync', ['network' => 'instagram', 'from' => '2026-09-01', 'to' => '2026-09-30'])['status']);

    $unconfirmed = self::endpoint('13', '101', 'commercial_social_disconnect', ['network' => 'youtube']);
    self::assertSame([422, 'Confirma la acción marcando la casilla.'], [$unconfirmed['status'], $unconfirmed['json']['data']['message']]);
    self::assertSame(422, self::endpoint('13', '101', 'commercial_social_disconnect', ['network' => 'instagram', 'confirm' => '1'])['status']);
  }

  public function testLegacyFolderForwardsTheRegisteredCallbacks(): void
  {
    $stub = (string) file_get_contents(dirname(__DIR__, 2) . '/legacy/dashboard-marketing/public/index.php');
    self::assertStringContainsString('social-callback.php?network=', $stub);
    self::assertSame(1, preg_match('#/(youtube|tiktok)/callback/?$#', '/dashboard-marketing/youtube/callback'));
    self::assertSame('https://sucasainmobiliaria.com.co/dashboard-marketing/tiktok/callback', (static function (): string {
      $config = require dirname(__DIR__, 2) . '/config/app.php';
      return getenv('TIKTOK_REDIRECT_URI') ?: (string) $config['tiktok']['redirect_uri'];
    })());
  }
}
