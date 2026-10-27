<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\CommercialSlaService;
use SCM\Core\Database;
use SCM\Core\Settings;

final class CommercialSettingsServiceTest extends TestCase
{
  private array $previousSession;
  private Database $db;
  private Settings $settings;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function service(string $cargo = '13'): CommercialSettingsService
  {
    $_SESSION = ['scm_user_cargo' => $cargo, 'scm_user_id' => 7, 'scm_employee_id' => '1007'];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
    $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('fixture', 'wp_jet_cct_confi_sistema')");
    foreach (['_ID', 'cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'funcion', 'valor', 'departamento'] as $column) {
      $pdo->exec("INSERT INTO information_schema.COLUMNS VALUES ('fixture', 'wp_jet_cct_confi_sistema', '{$column}')");
    }
    $pdo->exec("INSERT INTO wp_jet_cct_confi_sistema (funcion, valor) VALUES ('dia-retoque-venta', '60')");
    $this->db = new Database($pdo);
    $this->settings = new Settings($this->db);
    return new CommercialSettingsService($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), []);
  }

  private function systemValue(string $key): ?string
  {
    $value = $this->db->getVar('SELECT valor FROM wp_jet_cct_confi_sistema WHERE funcion = ? ORDER BY _ID DESC LIMIT 1', [$key]);
    return $value === null ? null : (string) $value;
  }

  public function testOnlyAdminsCanReadOrChangeSettings(): void
  {
    $this->expectException(\RuntimeException::class);
    $this->service('9')->load();
  }

  public function testSlaUpdatesEveryTopicVariantAndKeepsOtherAreas(): void
  {
    $service = $this->service();
    $service->save('tiempos', ['topics' => ['Captacion' => ['warning_from' => 3, 'overdue_after' => 4]]]);
    $stored = json_decode((string) $this->systemValue(CommercialSlaService::CONFIG_KEY), true);
    self::assertSame(['warning_from' => 3, 'overdue_after' => 4], $stored['topics']['Captacion']);
    self::assertSame(['warning_from' => 3, 'overdue_after' => 4], $stored['topics']['Captación']);
    self::assertSame(['warning_from' => 8, 'overdue_after' => 10], $stored['topics']['Revision preventiva']);
    self::assertSame(['warning_from' => 3, 'overdue_after' => 4], (new CommercialSlaService($this->db))->ruleFor('Captación'));

    $this->expectException(\InvalidArgumentException::class);
    $service->save('tiempos', ['topics' => ['Avaluo' => ['warning_from' => 9, 'overdue_after' => 5]]]);
  }

  public function testSignValuesUpdateExistingRowsAndCreateMissingOnes(): void
  {
    $service = $this->service();
    $service->save('avisos', ['values' => ['dia-retoque-venta' => '75', 'valor_aviso' => '35000', 'otra-clave' => '1']]);
    self::assertSame('75', $this->systemValue('dia-retoque-venta'));
    self::assertSame('35000', $this->systemValue('valor_aviso'));
    self::assertNull($this->systemValue('otra-clave'));
    self::assertSame('1007', (string) $this->db->getVar("SELECT cct_author_id FROM wp_jet_cct_confi_sistema WHERE funcion = 'valor_aviso'"));
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_confi_sistema WHERE funcion = 'dia-retoque-venta'"));
  }

  public function testPropertyUpdateLimitsAreStoredAndUsedWithDefaults(): void
  {
    self::assertSame(['alerta' => 80, 'vencido' => 90], CommercialSettingsService::propertyUpdateLimits(null)['arriendo']);
    $service = $this->service();
    $service->save('actualizaciones', ['limits' => [
      'arriendo' => ['alerta' => 50, 'vencido' => 60],
      'venta' => ['alerta' => 110, 'vencido' => 120],
      'arriendo_venta' => ['alerta' => 100, 'vencido' => 110],
    ]]);
    self::assertSame(['alerta' => 50, 'vencido' => 60], CommercialSettingsService::propertyUpdateLimits($this->settings)['arriendo']);
  }

  public function testHighlightQuotasAndUnknownSection(): void
  {
    $service = $this->service();
    $service->save('destacados', ['portals' => ['proppit_promocionados' => 12]]);
    self::assertSame('12', $this->systemValue('proppit_promocionados'));
    $this->expectException(\InvalidArgumentException::class);
    $service->save('inexistente', []);
  }
}
