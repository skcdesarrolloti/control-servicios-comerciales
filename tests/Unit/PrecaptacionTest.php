<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Core\Csrf;
use SCM\Core\Settings;
use SCM\Precaptacion\DatabaseAdapter;
use SCM\Precaptacion\FormView;
use SCM\Precaptacion\Module;
use SCM\Precaptacion\LegacyPanel;
use SCM\Precaptacion\Repository;
use Tests\Fixtures\PrecaptacionDatabase;

require_once dirname(__DIR__) . '/Fixtures/PrecaptacionDatabase.php';

final class PrecaptacionTest extends TestCase
{
  private $db;
  private Repository $repository;
  private array $previousSession;

  protected function setUp(): void
  {
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://example.test');
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_logged_in'=>true,'scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
    $this->db = PrecaptacionDatabase::create();
    Module::init($this->db, new Settings($this->db), new Csrf('fixture-secret'), []);
    $this->repository = new Repository($this->db);
  }

  protected function tearDown(): void { $_SESSION = $this->previousSession; Module::rollback(); }

  public function testAgencyNamesAreReusedIgnoringCaseSpacesAndAccents(): void
  {
    $first = $this->repository->createCatalog('inmobiliaria', ['inmobiliaria'=>'Águila   Inmobiliaria']);
    $second = $this->repository->createCatalog('inmobiliaria', ['inmobiliaria'=>'  AGUILA inmobiliaria ']);
    self::assertFalse($first['existing']);
    self::assertTrue($second['existing']);
    self::assertSame($first['option']['id'], $second['option']['id']);
    self::assertSame(2, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_inmobiliarias'));
  }

  public function testNeighborhoodDeduplicationIncludesCountryAndCity(): void
  {
    $data = ['barrio'=>' el  poblado ','pais'=>'Colombia','ciudad'=>'medellin','latitud'=>'6.2','longitud'=>'-75.5','codigo_postal'=>'050021'];
    self::assertTrue($this->repository->createCatalog('barrio', $data)['existing']);
    $data['ciudad'] = 'Otra ciudad';
    self::assertFalse($this->repository->createCatalog('barrio', $data)['existing']);
  }

  public function testInvalidCoordinatesDoNotInsertNeighborhoods(): void
  {
    try {
      $this->repository->createCatalog('barrio', ['barrio'=>'Nuevo','pais'=>'Colombia','ciudad'=>'Medellín','latitud'=>'200','longitud'=>'-75','codigo_postal'=>'050021']);
      self::fail('No se rechazaron las coordenadas inválidas.');
    } catch (\InvalidArgumentException $exception) {
      self::assertSame(1, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_barrios'));
      self::assertFalse($this->db->pdo()->inTransaction());
    }
  }

  public function testPrecaptacionIdentityAndRouteComeFromTheServer(): void
  {
    $data = $this->repository->validate(['origen'=>'Recorrido','tipo_inmueble'=>'Casa','categoria'=>'Arriendo','celular'=>'3001234567','barrio'=>'El Poblado','id_empleado'=>'202','creador'=>'Otro','bandera'=>'Si']);
    $id = $this->repository->create($data, ['https://example.test/evidence.png']);
    $row = $this->db->getRow('SELECT * FROM wp_jet_cct_precaptaciones WHERE _ID = ?', [$id]);
    self::assertSame('101', $row['id_empleado']);
    self::assertSame('Funcionario de prueba', $row['creador']);
    self::assertSame('No', $row['contactado']);
    self::assertSame('No', $row['bandera']);
    self::assertSame('Ruta 1', $row['ruta']);
  }

  public function testAgencyConditionRequiresAnExistingAgency(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->repository->validate(['origen'=>'Recorrido','tipo_inmueble'=>'Casa','categoria'=>'Venta','celular'=>'3001234567','promocionado_por'=>['Inmmobiliaria'],'competencia'=>['No existe']]);
  }

  public function testOtherEmployeesRecordsCannotBeModified(): void
  {
    $this->expectException(\RuntimeException::class);
    Module::authorizeRecord(['id_empleado'=>'202']);
  }

  public function testOriginalPanelIsScopedToTheCurrentEmployee(): void
  {
    $html = LegacyPanel::render_shortcode(['modo'=>'mis']);
    self::assertStringContainsString('Contacto de prueba', $html);
    self::assertStringNotContainsString('Registro de otro funcionario', $html);
    self::assertStringContainsString('precaptacion-api.php', $html);
    self::assertStringContainsString('data-precaptaciones-precap-form', $html);
  }

  public function testFormPreservesFieldsAndConditionalCatalogControls(): void
  {
    $html = FormView::render($this->repository);
    self::assertStringContainsString('value="Club PPH"', $html);
    self::assertStringContainsString('data-precap-conditional="id_pph" hidden', $html);
    self::assertStringContainsString('data-precap-catalog="inmobiliaria"', $html);
    self::assertStringContainsString('name="codigo_postal"', $html);
    self::assertStringContainsString('name="competencia[]"', $html);
  }

  public function testMetricsCountScopedPendingAndConvertedRecords(): void
  {
    $html = LegacyPanel::render_shortcode(['modo'=>'mis']);
    self::assertMatchesRegularExpression('/precap-metric--total.*?<strong>1<\/strong>/s', $html);
    $_SESSION['scm_user_cargo'] = '13';
    $html = LegacyPanel::render_shortcode(['modo'=>'control']);
    self::assertMatchesRegularExpression('/precap-metric--pending.*?<strong>2<\/strong>/s', $html);
    $this->db->update('wp_jet_cct_precaptaciones', ['id_ticket_asignado'=>'10','razones'=>'Ticket creado'], ['_ID'=>1]);
    $html = LegacyPanel::render_shortcode(['modo'=>'control']);
    self::assertMatchesRegularExpression('/precap-metric--pending.*?<strong>1<\/strong>/s', $html);
    self::assertMatchesRegularExpression('/precap-metric--conversion.*?<strong>50\.0%<\/strong>/s', $html);
    self::assertMatchesRegularExpression('/Tarea creada\s*<\/option>/', $html);
  }

  public function testHistoricalNoAnswerIsSeparateFromUncalledAndContacted(): void
  {
    $this->db->update('wp_jet_cct_precaptaciones', ['observaciones'=>'No contestó','contactado'=>'No'], ['_ID'=>1]);
    $html = LegacyPanel::render_shortcode(['modo'=>'mis']);
    self::assertStringContainsString('precap-contact-badge--no_contesto', $html);
    self::assertStringContainsString('name="precaptaciones_estado_contacto"', $html);
    $this->db->update('wp_jet_cct_precaptaciones', ['observaciones'=>'','resultado'=>'Hablamos con el propietario','contactado'=>'Si'], ['_ID'=>1]);
    $html = LegacyPanel::render_shortcode(['modo'=>'mis']);
    self::assertStringContainsString('precap-contact-badge--contactado', $html);
    self::assertMatchesRegularExpression('/precap-metric--pending.*?<strong>0<\/strong>/s', $html);
  }

  public function testNormalizationLeavesEmptyChoicesCanonicalAndNoPendingCounts(): void
  {
    $this->db->update('wp_jet_cct_precaptaciones', ['competencia'=>serialize(['']),'promocionado_por'=>'Directo'], ['_ID'=>1]);
    $table = 'wp_jet_cct_precaptaciones';
    $normalize = new \ReflectionMethod(LegacyPanel::class, 'normalize_serialized_choice_values');
    foreach (['promocionado_por','competencia'] as $column) {
      $result = $normalize->invoke(null, $table, $column, $column);
      self::assertSame('', $result['error']);
      self::assertSame(1, $result['updated']);
      self::assertSame(0, $result['remaining']);
      self::assertSame(0, $normalize->invoke(null, $table, $column, $column)['updated']);
    }
    self::assertSame('a:0:{}', $this->db->getVar('SELECT competencia FROM wp_jet_cct_precaptaciones WHERE _ID = 1'));
  }

  public function testResponseDatesAreTransactionalAndDoNotReplaceRegistrationDate(): void
  {
    $dates = new \SCM\Precaptacion\ResponseDates($this->db);
    $registered = $this->db->getVar('SELECT fecha FROM wp_jet_cct_precaptaciones WHERE _ID = 1');
    $this->db->pdo()->beginTransaction(); $dates->record(1); $this->db->pdo()->rollBack();
    self::assertSame([], $dates->forIds([1]));
    $dates->record(1); $dates->record(1);
    self::assertCount(1, $dates->forIds([1]));
    $html = LegacyPanel::render_shortcode(['modo'=>'mis']);
    self::assertStringContainsString('Última respuesta', $html);
    self::assertStringContainsString('Fecha de registro', $html);
    self::assertSame($registered, $this->db->getVar('SELECT fecha FROM wp_jet_cct_precaptaciones WHERE _ID = 1'));
  }

  public function testPreparedQueriesHandleQuotedPlaceholdersAndUntrustedNames(): void
  {
    $adapter = new DatabaseAdapter($this->db);
    $sql = $adapter->prepare("SELECT * FROM wp_jet_cct_inmobiliarias WHERE inmobiliaria = '%s'", "x' OR 1=1 --");
    self::assertSame([], $adapter->get_results($sql, 'ARRAY_A'));
  }
}
