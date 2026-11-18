<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\PropertyUpdateState;
use SCM\Commercial\Quotes\CommercialQuotesNotifier;
use SCM\Commercial\Quotes\CommercialQuotesRepository;
use SCM\Commercial\Quotes\CommercialQuotesService;
use SCM\Commercial\Quotes\QuoteComparison;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Support\PublicQuoteAccess;
use SCM\Views\CommercialDashboardView;
use SCM\Views\CommercialQuotesView;
use SCM\Views\PublicQuoteView;
use Tests\Fixtures\CommercialQuotesDatabase;

final class CommercialQuotesTest extends TestCase
{
  private Database $db;
  private array $previousSession;
  /** @var list<array{to:mixed,subject:string,options:array<string,mixed>}> */
  private array $emails = [];
  /** @var list<array{phone:string,template:string,components:array<int,mixed>}> */
  private array $whatsapps = [];

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialQuotesDatabase.php';
    if (!defined('SCM_APP_SECRET')) define('SCM_APP_SECRET', 'secreto-de-prueba-de-al-menos-32-caracteres');
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://panel.test/public');
    $this->db = CommercialQuotesDatabase::create();
    $this->login('101', '9');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function login(string $employee, string $cargo): void
  {
    $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => (int) $employee, 'scm_employee_id' => $employee, 'scm_user_cargo' => $cargo, 'scm_user' => 'Prueba'];
  }

  private function service(): CommercialQuotesService
  {
    $settings = new Settings($this->db);
    $notifier = new CommercialQuotesNotifier($this->db, $settings,
      function ($to, string $subject, string $html, array $options): void { $this->emails[] = ['to' => $to, 'subject' => $subject, 'options' => $options]; },
      function (string $phone, string $template, array $components, array $options): void { $this->whatsapps[] = ['phone' => $phone, 'template' => $template, 'components' => $components]; }
    );
    return new CommercialQuotesService(new CommercialQuotesRepository($this->db), $settings, CommercialQuotesDatabase::policy($this->db), $notifier);
  }

  /** @param array<string,mixed> $override */
  private function create(array $override = [], int $task = 700): array
  {
    return $this->service()->create($task, $override + ['tipo' => 'Arriendo', 'cliente' => 'Laura Cliente', 'email' => 'laura@example.test', 'celular' => '3001234567', 'inmuebles' => ['501', '502']]);
  }

  // ── Registro en permisos, router, configuración y avisos ──

  public function testRegisteredInPermissionsRouterSettingsAndInternalNotifications(): void
  {
    self::assertArrayHasKey('cotizaciones', CommercialAccessPolicy::VIEWS);
    self::assertContains('cotizaciones', CommercialAccessPolicy::VIEW_GROUPS['Actividades comerciales']);
    self::assertArrayHasKey('cotizaciones', CommercialAccessPolicy::SCOPED_VIEWS);
    self::assertArrayHasKey('cotizacion_crear', CommercialAccessPolicy::ACTIONS);
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertContains('cotizacion_crear', array_merge(...array_values($groups)));
    foreach (['commercial_quote_form' => 'form', 'commercial_quote_properties' => 'properties', 'commercial_quote_create' => 'create', 'commercial_quotes_list' => 'list', 'commercial_quote_view' => 'view'] as $action => $method) {
      self::assertSame($method, CommercialActionRouter::QUOTE_ACTIONS[$action]);
    }
    self::assertContains('cotizaciones', CommercialSettingsService::SECTIONS);
    self::assertContains(CommercialQuotesNotifier::EVENT, CommercialInternalNotifications::events());
  }

  // ── Cálculo ─────────────────────────────────────────

  public function testComparisonMarksBestValuesAndAdvantages(): void
  {
    $rows = $this->db->getResults("SELECT * FROM wp_jet_cct_inmuebles WHERE codigo IN ('501','502') ORDER BY codigo");
    $items = array_map(static fn(array $row): array => QuoteComparison::normalize($row, 'Arriendo'), $rows);
    self::assertSame(2500000.0, $items[0]['precio']);
    self::assertSame(80.0, $items[0]['area']);
    self::assertSame(['Balcón', 'Cocina integral'], $items[0]['features']['interiores']);

    $comparison = QuoteComparison::compare($items, 'Arriendo');
    $byKey = array_column($comparison['rows'], null, 'key');
    self::assertSame([0], $byKey['precio']['best'], 'El canon más bajo es el 501.');
    self::assertSame([1], $byKey['area']['best'], 'La mayor área es la del 502.');
    self::assertSame([1], $byKey['m2']['best'], '3.200.000 / 140 m² sale mejor que 2.500.000 / 80 m².');
    self::assertSame([1], $byKey['habitaciones']['best']);
    self::assertSame([0], $byKey['mensual']['best'], 'Con la administración el 501 sigue siendo más barato (2.800.000).');
    self::assertSame([], $byKey['admin']['best'], 'Solo uno tiene administración: no hay contra quién comparar.');
    self::assertSame([], $byKey['estrato']['best'], 'El estrato se muestra pero no gana.');
    self::assertContains('El canon más bajo ($ 2.500.000)', $comparison['advantages'][0]);
    self::assertContains('Es el único con Balcón y Piscina', $comparison['advantages'][0]);
    self::assertContains('La mayor área (140 m²)', $comparison['advantages'][1]);
    self::assertContains('Es el único con Patio', $comparison['advantages'][1]);
    $interior = $comparison['features'][0];
    self::assertSame('interiores', $interior['group']);
    $patio = array_values(array_filter($interior['items'], static fn(array $item): bool => $item['name'] === 'Patio'))[0];
    self::assertSame([false, true], $patio['has']);
  }

  public function testSinglePropertyHasNoAdvantagesAndTiesHaveNoWinner(): void
  {
    $one = QuoteComparison::compare([QuoteComparison::normalize(['codigo' => '1', 'precio_arriendo' => '1000000', 'area_construida' => '50'], 'Arriendo')], 'Arriendo');
    self::assertSame([[]], $one['advantages']);
    $tie = QuoteComparison::compare([
      QuoteComparison::normalize(['codigo' => '1', 'precio_venta' => '100000000', 'habitaciones' => '2'], 'Venta'),
      QuoteComparison::normalize(['codigo' => '2', 'precio_venta' => '100000000', 'habitaciones' => '3'], 'Venta'),
    ], 'Venta');
    $byKey = array_column($tie['rows'], null, 'key');
    self::assertSame([], $byKey['precio']['best']);
    self::assertSame([1], $byKey['habitaciones']['best']);
    self::assertArrayNotHasKey('mensual', $byKey, 'El pago mensual solo aplica al arriendo.');
  }

  public function testUpdateStateUsesConfiguredLimitsByBusinessType(): void
  {
    $limits = ['arriendo' => ['alerta' => 80, 'vencido' => 90], 'venta' => ['alerta' => 110, 'vencido' => 120], 'arriendo_venta' => ['alerta' => 100, 'vencido' => 110]];
    $now = 1_800_000_000;
    self::assertSame('vencido', PropertyUpdateState::evaluate(['tipo_negocio' => 'Arriendo', 'fecha_actualizacion' => $now - 95 * 86400], $limits, $now)['status']);
    self::assertSame('ok', PropertyUpdateState::evaluate(['tipo_negocio' => 'Venta', 'fecha_actualizacion' => $now - 95 * 86400], $limits, $now)['status']);
    self::assertSame('alerta', PropertyUpdateState::evaluate(['tipo_negocio' => 'Arriendo y venta', 'fecha_actualizacion' => $now - 105 * 86400], $limits, $now)['status']);
    self::assertSame('vencido', PropertyUpdateState::evaluate(['tipo_negocio' => 'Arriendo', 'fecha_actualizacion' => '', 'fecha_publicacion' => date('Y-m-d', $now - 91 * 86400)], $limits, $now)['status'], 'Sin actualización cuenta desde la publicación.');
    self::assertStringContainsString('id_inmueble=503', PropertyUpdateState::updateUrl(['codigo' => '503', 'id_funcionario' => '101']));
  }

  public function testCodesReadEveryStoredFormat(): void
  {
    self::assertSame(['152469', '81187'], CommercialQuotesRepository::codes(serialize(['152469', '81187'])));
    self::assertSame(['1', '2'], CommercialQuotesRepository::codes('["1","2"]'));
    self::assertSame(['7', '8'], CommercialQuotesRepository::codes('7, 8'));
    self::assertSame([], CommercialQuotesRepository::codes(''));
  }

  // ── Buscador ────────────────────────────────────────

  public function testSearchShowsOnlyPublicPropertiesAndBlocksExpiredOnes(): void
  {
    $result = $this->service()->searchProperties(['tipo' => 'Arriendo']);
    $codes = array_column($result['items'], 'codigo');
    sort($codes);
    self::assertSame(['501', '502', '503'], $codes, 'Ni el arrendado ni el de venta salen en una cotización de arriendo.');
    $byCode = array_column($result['items'], null, 'codigo');
    self::assertFalse($byCode['503']['quotable']);
    self::assertTrue($byCode['501']['quotable']);
    self::assertSame('https://img.example.test/9001.jpg', str_replace('http://', 'https://', $byCode['501']['foto']));
    self::assertSame(1, $result['vencidos']);

    $hidden = $this->service()->searchProperties(['tipo' => 'Arriendo', 'ocultar_vencidos' => '1']);
    self::assertNotContains('503', array_column($hidden['items'], 'codigo'));
    $cheap = $this->service()->searchProperties(['tipo' => 'Arriendo', 'precio_max' => '$ 3.000.000']);
    self::assertSame(['501'], array_column($cheap['items'], 'codigo'));
    $rooms = $this->service()->searchProperties(['tipo' => 'Arriendo', 'habitaciones' => '4']);
    self::assertSame(['502'], array_column($rooms['items'], 'codigo'));
  }

  public function testSearchAndCreateRequireTheAction(): void
  {
    $this->login('707', '7');
    $this->expectException(\RuntimeException::class);
    $this->service()->searchProperties([]);
  }

  // ── Crear ───────────────────────────────────────────

  public function testCreateSavesQuoteOneHistoryPerPropertyAndNotifiesTheClient(): void
  {
    $result = $this->create();
    $quote = $this->db->getRow('SELECT * FROM wp_jet_cct_cotizacion_inmuebles WHERE _ID = ?', [$result['id']]);
    self::assertSame(serialize(['501', '502']), $quote['inmuebles'], 'Serializado como lo guardaba JetEngine: lo leen las otras apps.');
    self::assertSame('101', $quote['cct_author_id']);
    self::assertSame('101', $quote['id_empleado']);
    self::assertSame('700', $quote['id_ticket']);
    self::assertSame('Arriendo', $quote['tipo']);
    self::assertSame('55', $quote['id_cliente']);

    $history = $this->db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble ORDER BY _ID');
    self::assertCount(2, $history, 'Un registro por inmueble, no uno con todos.');
    self::assertSame(['501', '502'], array_column($history, 'id_inmueble'));
    self::assertSame(serialize(['501']), $history[0]['inmuebles_cotizados']);
    self::assertSame('Cotizacion', $history[0]['tipo_reporte']);
    self::assertSame('101', $history[0]['cct_author_id']);
    self::assertStringNotContainsString('Laura', $history[0]['observacion'], 'El historial del inmueble lo puede ver el propietario: sin datos del cliente.');

    $task = $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 700');
    self::assertSame((string) $result['id'], $task['id_cotizacion_comercial']);
    self::assertSame('Mostrando', $task['estado_comercial']);
    self::assertSame('Pendiente', $task['estado_administrativo'], 'Nunca se toca el estado administrativo.');
    $taskHistory = $this->db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_cotizacion_comercial = ?', [(string) $result['id']]);
    self::assertStringContainsString('/cotizacion.php?c=' . $result['id'] . '.', $taskHistory['respuesta']);
    self::assertSame('SI', $taskHistory['fue_editada']);

    self::assertSame(['correo' => true, 'whatsapp' => true], $result['enviado']);
    self::assertSame('laura@example.test', $this->emails[0]['to']);
    self::assertSame(['101@example.test'], $this->emails[1]['to'], 'Quien la hizo recibe la copia; sin correos fijos.');
    self::assertSame('+573001234567', $this->whatsapps[0]['phone']);
    self::assertSame(CommercialQuotesNotifier::TEMPLATE, $this->whatsapps[0]['template']);
    $components = array_column($this->whatsapps[0]['components'], null, 'type');
    self::assertSame('https://img.example.test/9001.jpg', $components['header']['parameters'][0]['image']['link'], 'El encabezado es la foto del primer inmueble en https.');
    self::assertSame(['Laura Cliente', (string) $result['id'], '2 inmuebles en arriendo', 'Consultora Uno · Correo: 101@example.test · Celular: 3000000101'], array_column($components['body']['parameters'], 'text'));
    self::assertSame($result['id'], PublicQuoteAccess::verify($components['button']['parameters'][0]['text']));
  }

  public function testCreateRejectsExpiredNonPublicAndEmptySelections(): void
  {
    foreach ([[['inmuebles' => ['501', '503']], 'actualización vencida'], [['inmuebles' => ['504']], 'no está público'], [['inmuebles' => []], 'al menos un inmueble'], [['inmuebles' => ['999']], 'No encontramos'], [['tipo' => ''], 'arriendo o de venta'], [['email' => '', 'celular' => ''], 'correo o el celular']] as [$override, $message]) {
      try {
        $this->create($override);
        self::fail('Debió rechazar: ' . $message);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_cotizacion_inmuebles'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble'));
  }

  public function testBlockedChannelIsNotUsed(): void
  {
    $this->db->insert('wp_jet_cct_clientes', ['nombre' => 'Laura Cliente', 'celular' => '3001234567', 'bloqueo_whatsapp' => '1', 'bloqueo_email' => '0']);
    $result = $this->create();
    self::assertSame(['correo' => true, 'whatsapp' => false], $result['enviado']);
    self::assertSame([], $this->whatsapps);
  }

  // ── Listado y alcance ───────────────────────────────

  public function testListingScopeMineAndTeam(): void
  {
    $own = $this->create();
    $this->login('202', '9');
    $other = $this->create([], 701);

    $mine = $this->service()->listing([]);
    self::assertSame([$other['id']], array_column($mine['rows'], 'id'), 'El cargo que no ve al equipo solo ve las suyas.');
    self::assertFalse($mine['team']);

    $this->login('303', '13');
    self::assertCount(2, $this->service()->listing([])['rows']);
    $portfolio = $this->service()->listing(['portafolio' => '1']);
    self::assertSame([], $portfolio['rows'], 'Mis cotizaciones del administrador: solo las suyas, aunque vea todo el equipo.');
    self::assertTrue($portfolio['mine']);
    self::assertSame([$own['id']], array_column($this->service()->listing(['cq_q' => '501', 'cq_empleado' => '101'])['rows'], 'id'));

    $_SESSION['scm_employee_id'] = '';
    $this->login('', '9');
    self::assertSame([], $this->service()->listing([])['rows'], 'Si no se sabe quién es, no se muestra nada.');
  }

  public function testViewUrlOnlyForOwnTeamOrTheTask(): void
  {
    $quote = $this->create();
    $this->login('202', '9');
    try {
      $this->service()->viewUrl($quote['id']);
      self::fail('No debe abrir la cotización de otra persona.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('permiso', $exception->getMessage());
    }
    self::assertStringContainsString('cotizacion.php?c=', $this->service()->viewUrl($quote['id'], 700), 'Desde la tarea ya validada sí.');
    $this->login('303', '13');
    self::assertStringContainsString('cotizacion.php?c=', $this->service()->viewUrl($quote['id']));
  }

  // ── Enlace público y vistas ─────────────────────────

  public function testPublicLinkIsSignedAndExpires(): void
  {
    $reference = PublicQuoteAccess::reference(42);
    self::assertSame(42, PublicQuoteAccess::verify($reference));
    self::assertSame(0, PublicQuoteAccess::verify(str_replace('42.', '43.', $reference)));
    self::assertSame(0, PublicQuoteAccess::verify(PublicQuoteAccess::reference(42, time() - 10)));
    self::assertSame(0, PublicQuoteAccess::verify('42'));
  }

  public function testPublicPageShowsPhotosComparisonAndNotesWithoutPrivateData(): void
  {
    $quote = $this->create(['observaciones' => 'Podemos visitar el sábado.']);
    $data = $this->service()->publicData($quote['id']);
    self::assertNotNull($data);
    self::assertSame(['https://img.example.test/9002.jpg'], array_values(array_diff(array_map(static fn(string $url): string => str_replace('http://', 'https://', $url), $data['inmuebles'][0]['fotos']), ['https://img.example.test/9001.jpg'])));
    self::assertSame(CommercialQuotesService::DEFAULT_RENT_NOTE, $data['notas'][0]);
    $html = PublicQuoteView::render($data, '/q.css', 'https://logo.test/l.png');
    self::assertStringContainsString('Comparación de los inmuebles', $html);
    self::assertStringContainsString('https://img.example.test/9001.jpg', $html, 'Las fotos http se muestran en https.');
    self::assertStringContainsString('Sus ventajas frente a las otras opciones', $html);
    self::assertStringContainsString('Podemos visitar el sábado.', $html);
    self::assertStringContainsString('wa.me/573000000101', $html);
    self::assertStringContainsString('Construimos confianza, protegemos su patrimonio.', $html);
    self::assertStringNotContainsString('Calle secreta', $html, 'La dirección exacta no es pública.');
    self::assertStringNotContainsString('Propietario reservado', $html);

    self::assertStringContainsString('no es válido', PublicQuoteView::render(null, '/q.css', '', 'Este enlace no es válido.'));
  }

  public function testSettingsAreEditableAndCanBeEmptied(): void
  {
    $settings = new Settings($this->db);
    CommercialQuotesService::saveSettings($settings, ['notas' => "Nota <b>uno</b>", 'nota_arriendo' => ''], 1);
    self::assertSame(['notas' => 'Nota uno', 'nota_arriendo' => ''], CommercialQuotesService::settingsFrom(new Settings($this->db)));
    $quote = $this->create();
    self::assertSame(['Nota uno'], $this->service()->publicData($quote['id'])['notas']);
  }

  public function testTaskFormAndResultsRender(): void
  {
    $data = $this->service()->formData(700);
    self::assertSame('Arriendo', $data['tipo']);
    self::assertSame('Laura Cliente', $data['cliente']);
    $form = CommercialQuotesView::taskForm($data);
    self::assertStringContainsString('data-cqt-task', $form);
    self::assertStringContainsString('data-commercial-return-case="700"', $form);
    $results = CommercialQuotesView::propertyResults($this->service()->searchProperties(['tipo' => 'Arriendo']));
    self::assertStringContainsString('Ir a actualizar', $results);
    self::assertSame(2, substr_count($results, 'data-cqt-toggle'), 'Solo los que se pueden cotizar tienen botón Agregar.');

    $this->create();
    $again = CommercialQuotesView::taskForm($this->service()->formData(700));
    self::assertStringContainsString('Cotizaciones de esta tarea', $again);
    $listing = CommercialQuotesView::page([], $this->service(), CommercialQuotesDatabase::policy($this->db));
    self::assertStringContainsString('Cotizaciones de inmuebles', $listing);
    self::assertStringContainsString('data-cqt-view=', $listing);
  }
}
