<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Pieces\BrandAssetFileMigration;
use SCM\Commercial\Promotion\Pieces\BrandAssetFiles;
use SCM\Commercial\Promotion\Pieces\BrandAssetService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\Promotion\BrandAssetsView;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Promoción › Banco de piezas, traído de dashboard-marketing, y la barrera que deja solo public/ en internet. */
final class CommercialPiecesTest extends TestCase
{
  private array $previousSession;
  private Database $db;
  private Settings $settings;
  private string $root;
  private BrandAssetFiles $files;
  private BrandAssetService $service;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    CommercialPromotionDatabase::pieces($this->db);
    CommercialPromotionDatabase::login('13', '101', 'Gerente');
    $this->root = sys_get_temp_dir() . '/scm-pieces-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/storage/branding-assets/2026/08', 0777, true);
    file_put_contents($this->root . '/storage/branding-assets/2026/08/logo.png', 'png');
    file_put_contents($this->root . '/storage/branding-assets/2026/08/feria.psd', 'psd');
    $this->files = self::fakeFiles($this->root);
    $this->service = new BrandAssetService($this->db, $this->files);
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    self::removeTree($this->root);
  }

  private static function removeTree(string $path): void
  {
    if (is_dir($path)) {
      foreach ((array) scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
          self::removeTree($path . '/' . $name);
        }
      }
      rmdir($path);
    } elseif (is_file($path)) {
      unlink($path);
    }
  }

  /** is_uploaded_file() solo acepta subidas reales: la prueba copia el archivo temporal. */
  private static function fakeFiles(string $root): BrandAssetFiles
  {
    return new class($root) extends BrandAssetFiles {
      protected function received(string $temporary): bool
      {
        return is_file($temporary);
      }

      protected function move(string $temporary, string $destination): bool
      {
        return copy($temporary, $destination);
      }
    };
  }

  /** @return array<string,mixed> */
  private function upload(string $name, string $content = 'arte'): array
  {
    $temporary = $this->root . '/subida-' . bin2hex(random_bytes(4));
    file_put_contents($temporary, $content);
    return ['name' => $name, 'tmp_name' => $temporary, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
  }

  /** @return array<string,mixed> */
  private static function input(array $overrides = []): array
  {
    return $overrides + ['title' => 'Volante', 'category' => 'physical', 'status' => 'designed', 'channels' => ['print'], 'creation_date' => '2026-11-01'];
  }

  private function policy(string $cargo = '13', string $employee = '101'): CommercialAccessPolicy
  {
    CommercialPromotionDatabase::login($cargo, $employee);
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function render(CommercialAccessPolicy $policy, array $input = []): string
  {
    return BrandAssetsView::render(BrandAssetsView::context($input, $policy, $this->db, $this->service), '/public');
  }

  public function testTheSubtabIsWiredAndItsActionsAreOptIn(): void
  {
    self::assertSame('Banco de piezas', CommercialAccessPolicy::SUBVIEWS['promocion']['banco_piezas']);
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'banco_piezas'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'banco_piezas']));
    foreach (['piezas_gestionar', 'piezas_archivar'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
      self::assertContains($action, CommercialAccessPolicy::OPT_IN_ACTIONS);
    }
    self::assertArrayHasKey('commercial_piece_save', CommercialActionRouter::PROMOTION_ACTIONS);
    self::assertStringContainsString('"banco_piezas"', (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/commercial-dashboard.js'));
    $policy = $this->policy('10', '909');
    self::assertFalse($policy->canAct('piezas_gestionar'));
    self::assertFalse($policy->canView('promocion'));
  }

  public function testTheDashboardKeepsTheOriginalCalculations(): void
  {
    $data = $this->service->dashboard(['active' => '1']);
    self::assertSame(['total' => 2, 'approved' => 0, 'implemented' => 1, 'pending' => 1, 'consistency' => 80, 'progress' => 50], $data['summary']);
    self::assertSame(['Logo <nuevo>', 'Plantilla feria'], array_column($data['top'], 'title'));
    // Impacto: alcance 100 + 2 leads × 50.
    self::assertSame(200, (int) $data['top'][0]['impact_score']);
    self::assertSame(2, $data['channel_counts']['instagram']);
    self::assertSame(1, $data['category_counts']['branding']);
    self::assertSame(0, $data['missing_files']);
    $logo = array_values(array_filter($data['assets'], static fn(array $row): bool => (int) $row['id'] === 1))[0];
    // Las vistas e interacciones salen de metrics_json, y las publicaciones se suman a la pieza.
    self::assertCount(1, $logo['linked_posts']);
    self::assertSame(900, $logo['social_kpis']['views']);
    self::assertSame(7, $logo['social_kpis']['clicks']);
  }

  public function testFiltersOnlyUseKnownValues(): void
  {
    self::assertSame(['Plantilla feria'], array_column($this->service->dashboard(['q' => 'feria'])['assets'], 'title'));
    self::assertSame(['Logo <nuevo>'], array_column($this->service->dashboard(['category' => 'branding'])['assets'], 'title'));
    self::assertCount(2, $this->service->dashboard(['category' => "x' OR 1=1"])['assets']);
    self::assertSame(['Pieza vieja'], array_column($this->service->dashboard(['active' => '0'])['assets'], 'title'));
    self::assertCount(3, $this->service->dashboard(['active' => 'all'])['assets']);
    self::assertCount(2, $this->service->dashboard(['channel' => 'instagram'])['assets']);
    self::assertSame(['Logo <nuevo>'], array_column($this->service->dashboard(['to' => '2026-08-10'])['assets'], 'title'));
  }

  public function testCreatingAPieceStoresTheFileAndWhoUploadedIt(): void
  {
    $id = $this->service->save(self::input(['asset_code' => ' BR-9 ', 'brand_compliance' => '250']), $this->upload('arte final.PDF'), 101);
    $saved = $this->service->find($id);
    self::assertSame('BR-9', $saved['asset_code']);
    self::assertSame(['print'], $saved['channels']);
    self::assertSame(100, (int) $saved['brand_compliance']);
    self::assertSame(101, (int) $saved['created_by']);
    self::assertSame('application/pdf', $saved['mime_type']);
    self::assertSame('arte final.PDF', $saved['original_name']);
    self::assertMatchesRegularExpression('#^storage/branding-assets/\d{4}/\d{2}/[0-9a-f]{36}\.pdf$#', (string) $saved['storage_path']);
    self::assertNotNull($this->files->path((string) $saved['storage_path']));
  }

  /** @return array<string,array{0:array<string,mixed>,1:string}> */
  public static function invalidPieces(): array
  {
    return [
      'sin nombre' => [['title' => ' '], 'Escribe el nombre'],
      'categoría' => [['category' => 'x'], 'categoría válida'],
      'estado' => [['status' => 'x'], 'estado válido'],
      'sin canal' => [['channels' => ['x']], 'al menos un canal'],
      'fecha' => [['creation_date' => '2026-02-31'], 'fecha de creación'],
      'fecha opcional' => [['approval_date' => 'ayer'], 'fecha de aprobación'],
      'indicador' => [['views_count' => '-1'], 'números enteros'],
      'código repetido' => [['asset_code' => 'BR-001'], 'otra pieza con ese código'],
    ];
  }

  /** @param array<string,mixed> $change */
  #[\PHPUnit\Framework\Attributes\DataProvider('invalidPieces')]
  public function testInvalidPiecesAreRejectedAndStoreNothing(array $change, string $message): void
  {
    try {
      $this->service->save(self::input($change), $this->upload('a.png'), 101);
      self::fail('Debía rechazarse');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString($message, $exception->getMessage());
    }
    self::assertSame(3, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_brand_assets'));
    self::assertSame([], glob($this->root . '/storage/branding-assets/' . date('Y/m') . '/*.png') ?: []);
  }

  public function testANewPieceNeedsItsFileAndAnEditKeepsTheCurrentOne(): void
  {
    try {
      $this->service->save(self::input(), [], 101);
      self::fail('Debía pedir el archivo');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('arte final', $exception->getMessage());
    }
    $this->service->save(self::input(['id' => 1, 'title' => 'Logo final', 'category' => 'branding', 'status' => 'approved', 'asset_code' => 'BR-001']), [], 202);
    $saved = $this->service->find(1);
    self::assertSame('Logo final', $saved['title']);
    self::assertSame('storage/branding-assets/2026/08/logo.png', $saved['storage_path']);
    self::assertSame(202, (int) $saved['updated_by']);
  }

  public function testReplacingTheFileRemovesTheOldOne(): void
  {
    $old = $this->root . '/storage/branding-assets/2026/08/logo.png';
    $this->service->save(self::input(['id' => 1, 'asset_code' => 'BR-001']), $this->upload('logo2.webp'), 101);
    self::assertFileDoesNotExist($old);
    self::assertSame('image/webp', $this->service->find(1)['mime_type']);
  }

  public function testUploadsAreValidated(): void
  {
    foreach ([
      [$this->upload('virus.php'), 'Formato no permitido'],
      [$this->upload('doble.png.exe'), 'Formato no permitido'],
      [['error' => UPLOAD_ERR_INI_SIZE], 'pesar máximo'],
      [['error' => UPLOAD_ERR_PARTIAL], 'no se completó'],
      [['name' => 'a.png', 'tmp_name' => $this->root . '/no-existe', 'size' => 1, 'error' => UPLOAD_ERR_OK], 'no se completó'],
      [['size' => BrandAssetFiles::MAX_BYTES + 1, 'error' => UPLOAD_ERR_OK, 'name' => 'a.png'] + ['tmp_name' => $this->upload('a.png')['tmp_name']], 'pesar máximo'],
    ] as [$file, $message]) {
      try {
        $this->files->store($file);
        self::fail('Debía rechazarse: ' . $message);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    self::assertNull($this->files->store(['error' => UPLOAD_ERR_NO_FILE]));
    // La lectura también se limita a la carpeta de piezas.
    file_put_contents($this->root . '/storage/.env', 'secreto');
    self::assertNull($this->files->path('storage/branding-assets/../.env'));
    self::assertNull($this->files->path('storage/.env'));
    self::assertNull($this->files->path(''));
    self::assertNotNull($this->files->path('storage/branding-assets/2026/08/logo.png'));
  }

  public function testHeadersNeverLetAnUploadRunInTheBrowser(): void
  {
    $base = $this->root . '/storage/branding-assets/2026/08/';
    foreach (['a.svg' => '<svg/>', 'a.png' => 'x', 'a.pdf' => 'x', 'a.psd' => 'x'] as $name => $content) {
      file_put_contents($base . $name, $content);
    }
    $svg = BrandAssetFiles::headers($base . 'a.svg', "ma\"lo\r\nX-Inyectado: 1.svg", false);
    self::assertContains('Content-Type: image/svg+xml', $svg);
    self::assertContains("Content-Security-Policy: sandbox; default-src 'none'", $svg);
    self::assertStringStartsWith('Content-Disposition: attachment', (string) end($svg));
    self::assertStringNotContainsString("\n", implode('', $svg));
    self::assertContains('X-Content-Type-Options: nosniff', $svg);
    $png = BrandAssetFiles::headers($base . 'a.png', 'a.png', false);
    self::assertContains('Content-Security-Policy: sandbox', $png);
    self::assertStringStartsWith('Content-Disposition: inline', (string) end($png));
    $forced = BrandAssetFiles::headers($base . 'a.png', 'a.png', true);
    self::assertStringStartsWith('Content-Disposition: attachment', (string) end($forced));
    $pdf = BrandAssetFiles::headers($base . 'a.pdf', 'a.pdf', false);
    self::assertStringStartsWith('Content-Disposition: inline', (string) end($pdf));
    self::assertContains('Content-Type: application/octet-stream', BrandAssetFiles::headers($base . 'a.psd', 'a.psd', false));
  }

  public function testPostLinksReplaceTheListAndRespectOtherPieces(): void
  {
    $posts = $this->service->socialPosts();
    self::assertNotContains('Post demo', array_column($posts, 'title'));
    $feria = array_values(array_filter($posts, static fn(array $row): bool => $row['title'] === 'Feria de vivienda'))[0];
    $linked = (int) $posts[0]['_ID'];
    self::assertSame(1, (int) $posts[0]['linked_asset_id']);
    self::assertSame('', array_values(array_filter($posts, static fn(array $row): bool => $row['title'] === 'Enlace raro'))[0]['permalink']);

    self::assertSame(1, $this->service->replacePostLinks(2, [(int) $feria['_ID'], (string) $feria['_ID']], 101));
    foreach ([[$linked, 'ya está vinculada a “Logo <nuevo>”'], [3, 'ya no está disponible'], [99999, 'ya no está disponible']] as [$post, $message]) {
      try {
        $this->service->replacePostLinks(2, [$post], 101);
        self::fail('Debía rechazarse: ' . $message);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    // Un rechazo no toca lo que ya estaba vinculado.
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_brand_asset_posts WHERE asset_id = 2'));
    self::assertSame(0, $this->service->replacePostLinks(2, [], 101));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_brand_asset_posts WHERE asset_id = 2'));
  }

  public function testArchivingKeepsTheFileAndTheLinks(): void
  {
    $this->service->setActive(1, false, 101);
    self::assertSame(0, (int) $this->service->find(1)['active']);
    self::assertNotNull($this->files->path('storage/branding-assets/2026/08/logo.png'));
    self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_marketing_brand_asset_posts WHERE asset_id = 1'));
    $this->service->setActive(1, true, 101);
    self::assertSame(1, (int) $this->service->find(1)['active']);
    $this->expectException(\InvalidArgumentException::class);
    $this->service->setActive(999, true, 101);
  }

  public function testTheViewShowsOnlyWhatTheCargoCanDo(): void
  {
    $admin = $this->render($this->policy());
    self::assertStringContainsString('Logo &lt;nuevo&gt;', $admin);
    self::assertStringNotContainsString('Logo <nuevo>', $admin);
    self::assertStringContainsString('data-piece-dialog="form"', $admin);
    self::assertStringContainsString('data-piece-open="archive"', $admin);
    self::assertStringContainsString('/public/piece-file.php?id=1', $admin);
    self::assertStringNotContainsString('branding-assets/2026', $admin);

    $reader = $this->render($this->policy('7', '303'));
    self::assertStringContainsString('Logo &lt;nuevo&gt;', $reader);
    self::assertStringContainsString('data-piece-dialog="detail"', $reader);
    foreach (['data-piece-dialog="form"', 'data-piece-dialog="links"', 'data-piece-dialog="archive"', 'data-piece-open="form"', 'data-piece-open="archive"', 'data-piece="'] as $hidden) {
      self::assertStringNotContainsString($hidden, $reader);
    }
    // Quien solo gestiona no recibe el botón de archivar.
    $manager = $this->render($this->policy('6', '202'));
    self::assertStringContainsString('data-piece-dialog="form"', $manager);
    self::assertStringNotContainsString('data-piece-dialog="archive"', $manager);
  }

  public function testTheViewWarnsAboutFilesNotCopiedYet(): void
  {
    self::assertStringNotContainsString('data-piece-missing', $this->render($this->policy()));
    $html = $this->render($this->policy(), ['pr_visibilidad' => 'all']);
    self::assertStringContainsString('data-piece-missing', $html);
    self::assertStringContainsString('Archivo sin copiar', $html);
    self::assertStringNotContainsString('piece-file.php?id=3', $html);
  }

  public function testAnEmployeeWithoutTheSubtabDoesNotSeeIt(): void
  {
    CommercialPromotionDatabase::login('8', '202');
    $html = PromotionView::page(['promocion_sub' => 'banco_piezas'], new CommercialAccessPolicy($this->settings, $this->db, ['13']), $this->db, $this->settings, '/public');
    self::assertStringContainsString('no está disponible', $html);
    self::assertStringNotContainsString('Logo', $html);
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
    self::assertSame(403, self::endpoint('7', '303', 'commercial_piece_save', self::input())['status']);
    self::assertSame(403, self::endpoint('7', '303', 'commercial_piece_posts')['status']);
    self::assertSame(403, self::endpoint('7', '303', 'commercial_piece_links', ['id' => 1, 'post_ids' => '[]'])['status']);
    self::assertSame(403, self::endpoint('6', '202', 'commercial_piece_active', ['id' => 1, 'confirm' => '1'])['status']);
    self::assertSame(403, self::endpoint('8', '202', 'commercial_piece_posts')['status']);
    $posts = self::endpoint('6', '202', 'commercial_piece_posts');
    self::assertSame(200, $posts['status']);
    self::assertNotContains('Post demo', array_column($posts['json']['data']['posts'], 'title'));
    self::assertArrayNotHasKey('metrics_json', $posts['json']['data']['posts'][0]);
    $unconfirmed = self::endpoint('13', '101', 'commercial_piece_active', ['id' => 1, 'active' => '0']);
    self::assertSame(422, $unconfirmed['status']);
    self::assertSame(200, self::endpoint('13', '101', 'commercial_piece_active', ['id' => 1, 'active' => '0', 'confirm' => '1'])['status']);
    $missingFile = self::endpoint('13', '101', 'commercial_piece_save', self::input());
    self::assertSame(422, $missingFile['status']);
    self::assertStringContainsString('arte final', $missingFile['json']['data']['message']);
  }

  public function testTheMigrationCopiesWithoutTouchingTheBaseOrTheOldPanel(): void
  {
    $legacy = $this->root . '/legado';
    mkdir($legacy . '/storage/branding-assets/2026/08', 0777, true);
    file_put_contents($legacy . '/storage/branding-assets/2026/08/logo.png', 'png-original');
    file_put_contents($legacy . '/storage/branding-assets/2026/08/feria.psd', 'psd-original');
    file_put_contents($legacy . '/storage/branding-assets/2026/08/falta.pdf', 'pdf-original');
    $panel = $this->root . '/panel';
    mkdir($panel);
    $before = $this->db->getResults('SELECT id, storage_path FROM wp_marketing_brand_assets ORDER BY id');
    $run = function (array $arguments) use ($panel, $legacy): array {
      $output = fopen('php://memory', 'w+');
      $code = (new BrandAssetFileMigration($this->db, $panel, $legacy, $output))->run($arguments);
      rewind($output);
      return [$code, (string) stream_get_contents($output)];
    };

    [$code, $text] = $run([]);
    self::assertSame(0, $code);
    self::assertStringContainsString('Se copiarían: 3', $text);
    self::assertDirectoryDoesNotExist($panel . '/storage');

    [$code, $text] = $run(['--apply']);
    self::assertSame(0, $code);
    self::assertStringContainsString('Copiadas: 3', $text);
    self::assertSame('png-original', file_get_contents($panel . '/storage/branding-assets/2026/08/logo.png'));
    self::assertFileExists($legacy . '/storage/branding-assets/2026/08/logo.png');
    self::assertSame($before, $this->db->getResults('SELECT id, storage_path FROM wp_marketing_brand_assets ORDER BY id'));

    [$code, $text] = $run(['--apply']);
    self::assertSame(0, $code);
    self::assertStringContainsString('Copiadas: 0. Ya estaban: 3', $text);

    // Sin el panel anterior lo copiado cuenta como listo; un archivo que no está en ninguno se reporta.
    self::removeTree($legacy);
    unlink($panel . '/storage/branding-assets/2026/08/falta.pdf');
    [$code, $text] = $run(['--apply']);
    self::assertSame(1, $code);
    self::assertStringContainsString('#3 Pieza vieja: no está en el panel anterior', $text);
    self::assertStringContainsString('Ya estaban: 2. Con problemas: 1', $text);

    $this->db->update('wp_marketing_brand_assets', ['storage_path' => '../../.env'], ['id' => 3]);
    [$code, $text] = $run(['--apply']);
    self::assertSame(1, $code);
    self::assertStringContainsString('ruta no válida', $text);
  }

  public function testOnlyPublicIsServedFromTheInternet(): void
  {
    $root = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
    self::assertStringContainsString('RewriteRule ^(?!public(?:/|$)) - [F,L]', $root);
    self::assertStringContainsString('Options -Indexes', $root);
    self::assertStringContainsString('Require all denied', (string) file_get_contents(dirname(__DIR__, 2) . '/storage/.htaccess'));
  }
}
