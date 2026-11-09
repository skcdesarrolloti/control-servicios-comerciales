<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Reports\CommercialBillingDocument;
use SCM\Commercial\Reports\CommercialReportsService;
use SCM\Core\Database;
use SCM\Core\Settings;

final class CommercialReportsServiceTest extends TestCase
{
  private Database $db;
  private CommercialReportsService $service;
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE wp_jet_cct_reportes_comerciales (_ID INTEGER PRIMARY KEY, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, valor INTEGER, funcionario TEXT, id_empleado TEXT, id_ticket TEXT, id_inmueble TEXT, exportado TEXT, fue_pagado TEXT, cct_modified TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_reportes_administrativos (_ID INTEGER PRIMARY KEY, fue_pagado TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_cuentas_cobro (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, numero_cuenta TEXT, id_empleado TEXT, fecha_creacion TEXT, fecha_inicio TEXT, fecha_fin TEXT, total_servicio TEXT, total_transporte TEXT, total_general TEXT, cantidad_reportes TEXT, archivo_pdf TEXT, reportes_incluidos TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, fue_pagada TEXT)');
    $pdo->exec('CREATE TABLE wp_jet_cct_funcionarios (_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, documento TEXT, correo TEXT, correo_dian TEXT)');
    $this->db = new Database($pdo);
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 1, 'id_empleado' => '100', 'nombre' => 'Ana', 'documento' => '123', 'correo' => 'a@x.co']);
    foreach ([[1, '100', 8000, 'No', 'No'], [2, '100', 4000, 'No', 'No'], [3, '200', 8000, 'No', 'No'], [4, '100', 8000, 'Si', 'No'], [5, '100', 4000, 'Si', 'Si']] as [$id, $employee, $value, $exported, $paid]) {
      $this->db->insert('wp_jet_cct_reportes_comerciales', ['_ID' => $id, 'fecha' => strtotime('2026-10-0' . $id), 'tipo_reporte' => 'Visita', 'observacion' => 'x', 'valor' => $value, 'funcionario' => $employee === '100' ? 'Ana' : 'Luis', 'id_empleado' => $employee, 'id_ticket' => '7', 'id_inmueble' => '4501', 'exportado' => $exported, 'fue_pagado' => $paid]);
    }
    // Cuenta administrativa real: incluye números de reporte que también existen como comerciales.
    $this->db->insert('wp_jet_cct_cuentas_cobro', ['_ID' => 1, 'numero_cuenta' => 'CC-000001', 'id_empleado' => '100', 'fecha_creacion' => '2026-09-01 10:00:00', 'total_general' => '50000', 'reportes_incluidos' => '["1","2"]', 'fue_pagada' => 'No pagada']);
    $this->service = new CommercialReportsService($this->db);
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  public function testOwnReportsAreSeparatedByStateAndOnlyTheirs(): void
  {
    $result = $this->service->ownReports('100', 'no_exportados');
    self::assertSame([2, 1], array_column($result['items'], 'id'));
    self::assertSame(['no_exportados' => 2, 'en_cobro' => 1, 'pagados' => 1], $result['counts']);
    self::assertSame(12000, $result['summary']['total']);
    self::assertSame([], $this->service->ownReports('', 'no_exportados')['items']);
  }

  public function testAccountTotalsComeFromTheDatabaseAndUseCommercialNumber(): void
  {
    $created = $this->service->createAccount('100', [1, 2]);
    self::assertSame(12000, $created['summary']['total']);
    self::assertSame('CCO-000002', $created['numero']);
    self::assertSame('Si', $this->db->getVar('SELECT exportado FROM wp_jet_cct_reportes_comerciales WHERE _ID = 1'));
    $account = $this->service->account($created['id']);
    self::assertSame(12000, $account['total']);
    self::assertCount(2, $account['reportes']);
  }

  public function testCannotBillForeignOrAlreadyBilledReports(): void
  {
    foreach ([[3], [1, 4]] as $ids) {
      try {
        $this->service->createAccount('100', $ids);
        self::fail('Debe rechazar el reporte.');
      } catch (\DomainException) {
        self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_cuentas_cobro'));
      }
    }
    self::assertSame('No', $this->db->getVar('SELECT exportado FROM wp_jet_cct_reportes_comerciales WHERE _ID = 1'), 'La transacción deshace el marcado parcial.');
  }

  public function testAdministrativeAccountsAreInvisibleAndCannotBePaidHere(): void
  {
    self::assertNull($this->service->account(1));
    self::assertSame([], $this->service->accounts([])['items']);
    self::assertSame(['accounts' => 0, 'reports' => 0], $this->service->markAccountsPaid([1]));
    self::assertSame('No pagada', $this->db->getVar('SELECT fue_pagada FROM wp_jet_cct_cuentas_cobro WHERE _ID = 1'));
    self::assertSame('No', $this->db->getVar('SELECT fue_pagado FROM wp_jet_cct_reportes_comerciales WHERE _ID = 1'));
  }

  public function testPayingAccountPaysItsReports(): void
  {
    $created = $this->service->createAccount('100', [1, 2]);
    self::assertSame(['accounts' => 1, 'reports' => 2], $this->service->markAccountsPaid([$created['id']]));
    self::assertSame('Si', $this->db->getVar('SELECT fue_pagado FROM wp_jet_cct_reportes_comerciales WHERE _ID = 2'));
    self::assertTrue($this->service->accounts(['estado' => 'pagada'])['items'][0]['pagada']);
  }

  public function testOnlyExportedReportsCanBePaidAndValuesLockOnceBilled(): void
  {
    self::assertSame(1, $this->service->markReportsPaid([4, 1]));
    self::assertSame('No', $this->db->getVar('SELECT fue_pagado FROM wp_jet_cct_reportes_comerciales WHERE _ID = 1'));
    $this->service->updateValue(1, 7000);
    self::assertSame(7000, (int) $this->db->getVar('SELECT valor FROM wp_jet_cct_reportes_comerciales WHERE _ID = 1'));
    $this->expectException(\DomainException::class);
    $this->service->updateValue(4, 1);
  }

  public function testConsolidatedFiltersAndListsEmployees(): void
  {
    $result = $this->service->consolidated('sin_exportar', ['funcionario' => '200'], 1);
    self::assertSame([3], array_column($result['items'], 'id'));
    self::assertSame(['sin_exportar' => 3, 'no_pagados' => 1, 'pagados' => 1], $result['counts']);
    self::assertCount(2, $result['employees']);
  }

  public function testDeadlineIsTheNextConfiguredWeekday(): void
  {
    self::assertSame('2026-11-14', $this->service->deadlineFor('2026-11-09'), 'Lunes: el sábado de esa semana.');
    self::assertSame('2026-11-14', $this->service->deadlineFor('2026-11-14'), 'El mismo sábado todavía está a tiempo.');
    self::assertSame('2026-11-21', $this->service->deadlineFor('2026-11-15'), 'Domingo: cuenta para la semana siguiente.');
    self::assertSame('2026-11-13', (new CommercialReportsService($this->db, 5))->deadlineFor('2026-11-09'), 'El día se puede configurar.');
  }

  public function testLateReportsCanStillBeBilledButTheAccountIsMarked(): void
  {
    self::assertTrue($this->service->ownReports('100', 'no_exportados')['items'][0]['fuera_plazo']);
    self::assertTrue($this->service->createAccount('100', [1])['fuera_plazo']);
    $today = date('Y-m-d');
    $this->db->insert('wp_jet_cct_reportes_comerciales', ['_ID' => 9, 'fecha' => time(), 'tipo_reporte' => 'Visita', 'valor' => 4000, 'id_empleado' => '100', 'exportado' => 'No', 'fue_pagado' => 'No']);
    self::assertFalse($this->service->ownReports('100', 'no_exportados')['items'][0]['fuera_plazo']);
    self::assertSame($this->service->deadlineFor($today), $this->service->ownReports('100', 'no_exportados')['deadline']);
    self::assertFalse($this->service->createAccount('100', [9])['fuera_plazo']);
    $accounts = array_column($this->service->accounts([])['items'], 'fuera_plazo', 'id');
    self::assertSame([3 => false, 2 => true], $accounts);
  }

  public function testExcelNeutralizesFormulas(): void
  {
    $account = ['numero' => 'CCO-1', 'funcionario' => 'Ana', 'total' => 1, 'reportes' => [['id' => 1, 'fecha' => 0, 'tipo' => '=CMD()', 'id_ticket' => '', 'id_inmueble' => '', 'valor' => 1]]];
    self::assertStringContainsString("'=CMD()", html_entity_decode(CommercialBillingDocument::excel($account, ['nombre' => 'Ana', 'documento' => '', 'correo' => '']), ENT_QUOTES));
  }

  public function testPermissionsAreOptInAndSubviewsScoped(): void
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, funcion TEXT, valor TEXT)');
    $pdo->prepare('INSERT INTO wp_jet_cct_confi_sistema (funcion, valor) VALUES (?, ?)')->execute([Settings::FUNCTION_KEY, json_encode(['commercial_permissions' => ['9' => ['views' => ['reportes_comerciales'], 'actions' => [], 'subviews' => ['reportes_comerciales' => ['mis_reportes']]]], 'commercial_admin_cargos' => ['13']])]);
    $db = new Database($pdo);
    $policy = new CommercialAccessPolicy(new Settings($db), $db, ['13']);
    $_SESSION = ['scm_user_cargo' => '10'];
    self::assertFalse($policy->canView('reportes_comerciales'), 'Sin configurar no se recibe.');
    $_SESSION = ['scm_user_cargo' => '9'];
    self::assertTrue($policy->canSubview('reportes_comerciales', 'mis_reportes'));
    self::assertFalse($policy->canSubview('reportes_comerciales', 'cuentas_cobro'));
    self::assertFalse($policy->canAct('reportes_pagar'));
    $_SESSION = ['scm_user_cargo' => '13'];
    self::assertTrue($policy->canAct('reportes_pagar') && $policy->canSubview('reportes_comerciales', 'cuentas_cobro'));
    self::assertSame('reportes_comerciales', CommercialAccessPolicy::normalizeToolTab(['tab' => 'cuentas_cobro'])['tab']);
  }
}
