<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Strategy\StrategicPlanRepository;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialDashboardView;
use SCM\Views\CommercialToolsView;
use Tests\Fixtures\StrategicPlanDatabase;

require_once dirname(__DIR__) . '/Fixtures/StrategicPlanDatabase.php';

final class CommercialStrategicPlanTest extends TestCase
{
  private array $previousSession = [];
  private Database $db;
  private Settings $settings;
  /** @var array<string,int> */
  private array $ids;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = StrategicPlanDatabase::create();
    $this->settings = StrategicPlanDatabase::settings($this->db);
    $this->ids = StrategicPlanDatabase::seed($this->db);
    StrategicPlanDatabase::login('13', '101');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function policy(): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function plan(): StrategicPlanRepository
  {
    return new StrategicPlanRepository($this->db);
  }

  public function testPermissionsAreASubtabPlusAnOptInEditAction(): void
  {
    self::assertSame('Plan estratégico', CommercialAccessPolicy::SUBVIEWS['herramientas']['plan_estrategico']);
    self::assertContains('plan_estrategico_editar', CommercialAccessPolicy::OPT_IN_ACTIONS);
    self::assertSame('herramientas', CommercialAccessPolicy::tabGroupOf('plan_estrategico'));
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'plan_estrategico'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'plan_estrategico']));

    StrategicPlanDatabase::login('9', '101');
    self::assertTrue($this->policy()->canSubview('herramientas', 'plan_estrategico'), 'Un cargo sin configurar ve el plan.');
    self::assertFalse($this->policy()->canAct('plan_estrategico_editar'), 'Editar es opt-in.');
    StrategicPlanDatabase::login('7', '202', 'Segundo consultor');
    self::assertTrue($this->policy()->canAct('plan_estrategico_editar'));
    StrategicPlanDatabase::login('8', '202');
    self::assertFalse($this->policy()->canSubview('herramientas', 'plan_estrategico'));
    StrategicPlanDatabase::login('13', '101');
    self::assertTrue($this->policy()->canAct('plan_estrategico_editar'));

    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/CommercialDashboardView.php');
    self::assertMatchesRegularExpression("/'Herramientas' => \[[^\]]*'plan_estrategico_editar'/", $source);
    self::assertStringContainsString("canSubview('herramientas', 'plan_estrategico')", $source);
    self::assertStringContainsString("['plan', 'flag', 'Plan estratégico'", $source);
    self::assertStringContainsString('"generador_utm", "plan_estrategico"].indexOf(tab) >= 0) return "herramientas"', (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/commercial-dashboard.js'));
  }

  public function testListsEnrichRowsLikeThePluginAndFilter(): void
  {
    $plan = $this->plan();
    $goals = $plan->list('metas', [], 'Funcionario de prueba');
    $goal = $goals[array_search($this->ids['meta'], array_column($goals, 'id'), true)];
    self::assertSame('Aumentar cierres de arriendo', $goal['objetivo']);
    self::assertSame('2026-02-28', $goal['fecha_limite'], 'Medianoche de Bogotá (plugin) se lee como el mismo día.');
    self::assertSame(40, $goal['cumplimiento']);
    self::assertSame([1, 0], [$goal['tareas_total'], $goal['tareas_completadas']]);
    $other = $goals[array_search($this->ids['meta2'], array_column($goals, 'id'), true)];
    self::assertSame('2026-12-31', $other['fecha_limite'], 'Medianoche UTC (JetEngine) también.');
    self::assertSame(2, $other['tareas_total']);

    $tasks = $plan->list('tareas', ['meta' => (string) $this->ids['meta2']], 'Funcionario de prueba');
    self::assertCount(2, $tasks);
    $task = $tasks[array_search($this->ids['tarea'], array_column($tasks, 'id'), true)];
    self::assertSame(['Cerrar 30 arriendos', 'Estudios presentados'], $task['metas']);
    self::assertSame(['Propietarios'], $task['actores']);
    self::assertSame('Primer contacto', $task['avances'][0]['descripcion']);
    self::assertSame(1, $task['campanas_total']);
    self::assertTrue($task['mine']);

    // Filtro de gestión en todas las pestañas, subiendo por la cadena.
    self::assertSame([], $plan->list('metas', ['gestion' => 'Gestión de Mercadeo'], ''));
    self::assertCount(2, $plan->list('metas', ['gestion' => 'gestion comercial'], ''));
    self::assertCount(2, $plan->list('tareas', ['gestion' => 'Gestión Comercial'], ''));
    self::assertSame([$this->ids['campana']], array_column($plan->list('campanas', ['gestion' => 'Gestión Comercial'], ''), 'id'));
    self::assertSame([], $plan->list('campanas', ['gestion' => 'Gestión de Mercadeo'], ''));
    self::assertSame(['Gestión Comercial'], $task['gestiones']);

    $gestiones = $plan->list('gestiones', [], '');
    self::assertSame([1, 1], array_column($gestiones, 'objetivos_total'));
    self::assertSame('Ventas y arriendos', $gestiones[array_search('Gestión Comercial', array_column($gestiones, 'nombre'), true)]['definicion']);
    self::assertSame(['Gestión de Mercadeo'], array_column($plan->list('gestiones', ['q' => 'campañas'], ''), 'nombre'), 'Busca también en la definición.');

    $mine = $plan->list('mis_tareas', [], 'Funcionario de prueba');
    self::assertSame([$this->ids['tarea']], array_column($mine, 'id'));
    self::assertSame([], $plan->list('mis_tareas', ['estado' => 'Completado'], 'Funcionario de prueba'));
    self::assertSame([$this->ids['tarea2']], array_column($plan->list('tareas', ['q' => 'copropiedad'], ''), 'id'));
    self::assertSame([$this->ids['objetivo_vacio']], array_column($plan->list('objetivos', ['gestion' => 'Gestión de Mercadeo'], ''), 'id'));
    self::assertSame([$this->ids['campana']], array_column($plan->list('campanas', ['medio' => 'buscadores', 'tarea' => (string) $this->ids['tarea']], ''), 'id'));

    $summary = $plan->summary('Funcionario de prueba', (int) strtotime('2026-03-15 10:00:00'));
    self::assertSame(['objetivos' => 2, 'metas' => 2, 'metas_realizadas' => 0, 'metas_vencidas' => 1, 'tareas' => 2, 'tareas_completadas' => 0, 'tareas_en_proceso' => 1, 'campanas' => 1, 'mis_pendientes' => 1], $summary);
    self::assertSame(['Funcionario de prueba', 'Segundo consultor'], $plan->options()['funcionarios'], 'Solo funcionarios activos.');
  }

  public function testSaveValidatesAgainstTheCatalogsAndStoresJetEngineFormats(): void
  {
    $plan = $this->plan();
    $id = $plan->save('tareas', 0, [
      'nombre_tarea' => '  Nueva tarea ', 'id_meta' => [(string) $this->ids['meta'], '999'], 'estado' => 'Pendiente',
      'responsable' => ['Segundo consultor', 'Persona inventada', 'Retirado'], 'fecha_inicio' => '2026-03-01', 'fecha_fin' => '2026-03-31',
      'id_actor' => [(string) $this->ids['actor2']], 'descripcion' => 'Detalle',
    ], '202', (int) strtotime('2026-03-01 09:00:00'));
    $row = $this->db->getRow('SELECT * FROM wp_jet_cct_tareas WHERE _ID = ?', [$id]);
    self::assertSame('Nueva tarea', $row['nombre_tarea']);
    self::assertSame((string) $this->ids['meta'], $row['id_meta'], 'Las metas que no existen se descartan.');
    self::assertSame('Segundo consultor', $row['responsable'], 'Solo funcionarios activos.');
    self::assertSame((string) gmmktime(0, 0, 0, 3, 1, 2026), (string) $row['fecha_inicio']);
    self::assertSame(['202', 'publish'], [$row['cct_author_id'], $row['cct_status']]);

    // Al editar, los responsables que ya tenía (aunque estén retirados) se conservan.
    $plan->save('objetivos', $this->ids['objetivo'], ['nombre_objetivo' => 'Aumentar cierres', 'perspectiva' => 'Gestión Comercial', 'periodo_tipo' => 'Anual', 'periodo_valor' => '2026', 'responsable_area' => ['Retirado', 'Segundo consultor']], '101');
    self::assertSame('Retirado,Segundo consultor', $this->db->getVar('SELECT responsable_area FROM wp_jet_cct_objetivos WHERE _ID = ?', [$this->ids['objetivo']]));

    $plan->save('campanas', $this->ids['campana'], ['nombre_campana' => 'Despide el año', 'tipo_campana' => 'Posicionamiento', 'tipo_medio' => ['Buscadores', 'Televisión'], 'frecuencia' => 'Mensual', 'id_tarea' => [(string) $this->ids['tarea']]], '101');
    self::assertSame('Buscadores', $this->db->getVar('SELECT tipo_medio FROM wp_jet_cct_campanas WHERE _ID = ?', [$this->ids['campana']]));

    foreach ([
      ['tareas', ['nombre_tarea' => 'Sin meta', 'estado' => 'Pendiente'], 'Elige al menos una meta'],
      ['tareas', ['nombre_tarea' => 'Fechas', 'id_meta' => [(string) $this->ids['meta']], 'fecha_inicio' => '2026-05-02', 'fecha_fin' => '2026-05-01'], 'anterior a la de inicio'],
      ['metas', ['nombre_meta' => 'X', 'id_objetivo' => '999'], 'Elige el objetivo'],
      ['metas', ['nombre_meta' => 'X', 'id_objetivo' => (string) $this->ids['objetivo'], 'valor_meta' => 'mucho'], 'como número'],
      ['metas', ['nombre_meta' => 'X', 'id_objetivo' => (string) $this->ids['objetivo'], 'unidad' => 'km'], 'unidad de la lista'],
      ['objetivos', ['nombre_objetivo' => 'X', 'perspectiva' => 'Inventada'], 'gestión de la lista'],
      ['campanas', ['nombre_campana' => 'X'], 'Elige el tipo de campaña'],
      ['objetivos', ['nombre_objetivo' => ''], 'Escribe el objetivo'],
    ] as [$table, $input, $message]) {
      try {
        $plan->save($table, 0, $input, '101');
        self::fail('Debió rechazar: ' . $message);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    $metaId = $plan->save('metas', 0, ['nombre_meta' => 'Decimales', 'id_objetivo' => (string) $this->ids['objetivo'], 'valor_actual' => '12,50', 'valor_meta' => '', 'unidad' => '%', 'fecha_limite' => '2026-12-31', 'estado' => 'Sin realizar'], '101');
    self::assertSame(['12.5', '0'], array_values($this->db->getRow('SELECT valor_actual, valor_meta FROM wp_jet_cct_metas WHERE _ID = ?', [$metaId])));
  }

  public function testDeleteRefusesParentsWithChildrenAndProgressKeepsThePluginFormat(): void
  {
    $plan = $this->plan();
    foreach ([['objetivos', $this->ids['objetivo'], '2 metas asociadas'], ['metas', $this->ids['meta'], '1 tarea asociada'], ['tareas', $this->ids['tarea'], '1 campaña asociada']] as [$table, $id, $message]) {
      try {
        $plan->delete($table, $id);
        self::fail('Debió rechazar ' . $table);
      } catch (\InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
    self::assertSame('Posicionar la marca', $plan->delete('objetivos', $this->ids['objetivo_vacio']));
    self::assertSame('Despide el año', $plan->delete('campanas', $this->ids['campana']));
    self::assertSame('Referidos de propietarios', $plan->delete('tareas', $this->ids['tarea']));

    $plan->addProgress($this->ids['tarea2'], 'Visité dos edificios', false, 'Segundo consultor', '202', (int) strtotime('2026-03-02 10:30:00'));
    $row = $this->db->getRow('SELECT estado, historial_avances FROM wp_jet_cct_tareas WHERE _ID = ?', [$this->ids['tarea2']]);
    self::assertSame('En Proceso', $row['estado']);
    self::assertSame([['fecha' => '2026-03-02 10:30:00', 'funcionario' => 'Segundo consultor', 'id_empleado' => '202', 'descripcion' => 'Visité dos edificios']], json_decode($row['historial_avances'], true));
    $plan->addProgress($this->ids['tarea2'], 'Terminado', true, 'Segundo consultor', '202');
    self::assertSame('Completado', $this->db->getVar('SELECT estado FROM wp_jet_cct_tareas WHERE _ID = ?', [$this->ids['tarea2']]));
    self::assertCount(2, json_decode((string) $this->db->getVar('SELECT historial_avances FROM wp_jet_cct_tareas WHERE _ID = ?', [$this->ids['tarea2']]), true));

    // Un texto suelto que ya estaba en informes se conserva como primera entrada.
    $campaign = $this->db->insert('wp_jet_cct_campanas', ['nombre_campana' => 'Vieja', 'tipo_campana' => 'Posicionamiento', 'informes' => 'Informe escrito a mano']);
    $campaignId = (int) $this->db->lastInsertId();
    $plan = $this->plan();
    $plan->addResult($campaignId, '120 clics', 'Funcionario de prueba', '101');
    $entries = StrategicPlanRepository::entries((string) $this->db->getVar('SELECT informes FROM wp_jet_cct_campanas WHERE _ID = ?', [$campaignId]));
    self::assertSame(['Informe escrito a mano', '120 clics'], array_column($entries, 'descripcion'));
    $this->expectException(\InvalidArgumentException::class);
    $plan->addResult($campaignId, '   ', 'Funcionario de prueba', '101');
  }

  public function testCatalogSettingsPropagateRenamesAndProtectWhatIsInUse(): void
  {
    $service = new CommercialSettingsService($this->db, $this->settings, $this->policy(), []);
    $catalogs = $service->load('plan')['plan'];
    self::assertSame([], $service->load()['plan'], 'Sin abrir la sección no se consultan los catálogos.');
    self::assertContains('plan', CommercialSettingsService::SECTIONS);
    $medios = $catalogs['tipos_medio']['rows'];
    self::assertSame(1, $medios[array_search('Buscadores', array_column($medios, 'nombre'), true)]['uso']);
    $units = $catalogs['unidades']['rows'];

    $byName = static fn(array $rows, string $name): array => $rows[array_search($name, array_column($rows, 'nombre'), true)];
    $mediosPayload = array_map(static fn(array $row): array => ['id' => $row['id'], 'nombre' => $row['nombre'] === 'Buscadores' ? 'Google Ads' : $row['nombre'], 'definicion' => $row['definicion']], $medios);
    $mediosPayload[] = ['id' => 0, 'nombre' => 'Radio', 'definicion' => 'Cuñas'];
    $unitsPayload = array_map(static fn(array $row): array => ['id' => $row['id'], 'nombre' => $row['nombre'], 'simbolo' => $row['simbolo'] === '%' ? 'pct' : $row['simbolo']], $units);
    $message = $service->save('plan', ['tipos_medio' => $mediosPayload, 'unidades' => $unitsPayload]);
    self::assertStringContainsString('1 agregados, 2 actualizados', $message);
    self::assertSame('Redes sociales,Google Ads', $this->db->getVar('SELECT tipo_medio FROM wp_jet_cct_campanas WHERE _ID = ?', [$this->ids['campana']]));
    self::assertSame('pct', $this->db->getVar('SELECT unidad FROM wp_jet_cct_metas WHERE _ID = ?', [$this->ids['meta']]));
    self::assertSame('101', $this->db->getVar("SELECT cct_author_id FROM wp_jet_cct_tipos_medio WHERE nombre = 'Radio'"));

    // Quitar lo que está en uso falla; quitar lo libre pide confirmación y luego elimina.
    $inUse = array_values(array_filter($mediosPayload, static fn(array $row): bool => $row['nombre'] !== 'Redes sociales'));
    try {
      $service->save('plan', ['tipos_medio' => $inUse, 'confirm_delete' => true]);
      self::fail('No debe eliminar un tipo en uso.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('«Redes sociales» lo usan 1 registro(s)', $exception->getMessage());
    }
    $radio = $byName($service->load('plan')['plan']['tipos_medio']['rows'], 'Radio');
    $withoutRadio = array_values(array_filter(array_map(static fn(array $row): array => ['id' => $row['id'], 'nombre' => $row['nombre'], 'definicion' => $row['definicion']], $service->load('plan')['plan']['tipos_medio']['rows']), static fn(array $row): bool => $row['id'] !== $radio['id']));
    try {
      $service->save('plan', ['tipos_medio' => $withoutRadio]);
      self::fail('Eliminar exige confirmación.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Confirma la eliminación de 1', $exception->getMessage());
    }
    self::assertStringContainsString('1 eliminados', $service->save('plan', ['tipos_medio' => $withoutRadio, 'confirm_delete' => true]));
    self::assertSame(0, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tipos_medio WHERE nombre = 'Radio'"));

    try {
      $service->save('plan', ['gestiones' => [['id' => 0, 'nombre' => 'A'], ['id' => 0, 'nombre' => 'a']]]);
      self::fail('Nombres repetidos.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('está repetido', $exception->getMessage());
    }

    StrategicPlanDatabase::login('7', '202');
    $this->expectException(\RuntimeException::class);
    (new CommercialSettingsService($this->db, $this->settings, $this->policy(), []))->load('plan');
  }

  public function testViewShowsEditingOnlyWithThePermissionAndLoadsItsOwnAssets(): void
  {
    $html = CommercialToolsView::render('plan_estrategico', null, 'https://example.test/public', CommercialToolsView::context('plan_estrategico', $this->policy(), $this->db, $this->settings));
    self::assertStringContainsString('data-commercial-strategy', $html);
    self::assertStringContainsString('data-plan-can-edit="1"', $html);
    self::assertStringContainsString('data-plan-new', $html);
    self::assertStringContainsString('https://example.test/public/assets/js/commercial-strategy.js?v=', $html);
    self::assertStringContainsString('https://example.test/public/assets/css/commercial-strategy.css?v=', $html);
    self::assertStringContainsString('data-plan-start="mis_tareas"', $html, 'Con tareas pendientes abre en Mis tareas.');
    self::assertStringContainsString('Configuración › Plan estratégico', $html);
    $options = json_decode(html_entity_decode((string) preg_replace('/^.*data-plan-options="([^"]*)".*$/s', '$1', $html), ENT_QUOTES), true);
    self::assertSame(['Gestión Comercial', 'Gestión de Mercadeo'], array_column($options['catalogs']['gestiones'], 'nombre'));

    StrategicPlanDatabase::login('9', '202', 'Segundo consultor');
    $readOnly = CommercialToolsView::render('plan_estrategico', null, '', CommercialToolsView::context('plan_estrategico', $this->policy(), $this->db, $this->settings));
    self::assertStringContainsString('data-plan-can-edit="0"', $readOnly);
    self::assertStringNotContainsString('data-plan-new', $readOnly);
    self::assertStringNotContainsString('Configuración › Plan estratégico', $readOnly);

    StrategicPlanDatabase::login('8', '202');
    self::assertSame([], CommercialToolsView::context('plan_estrategico', $this->policy(), $this->db, $this->settings)['plan'], 'Sin la subpestaña no se consultan datos.');
    self::assertSame([], CommercialToolsView::context('generador_utm', $this->policy(), $this->db, $this->settings)['plan']);
  }

  public function testMenuLinksThePlanOnlyForCargosWithTheSubtab(): void
  {
    $html = CommercialDashboardView::renderTabs(['herramientas'], 'herramientas', [], [], '/public', [], $this->policy());
    self::assertStringContainsString('tab=plan_estrategico', $html);
    StrategicPlanDatabase::login('8', '202');
    $html = CommercialDashboardView::renderTabs(['herramientas'], 'herramientas', [], [], '/public', [], $this->policy());
    self::assertStringNotContainsString('tab=plan_estrategico', $html);
    self::assertStringContainsString('tab=generador_utm', $html);
  }

  /** @return array{status:int,json:array<string,mixed>,state:array<string,mixed>,raw:string} */
  private static function endpoint(string $cargo, string $employee, string $name, string $action, array $input = []): array
  {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/strategy-endpoint.php') . ' '
      . implode(' ', array_map('escapeshellarg', [$cargo, $employee, $name, $action, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))]));
    $lines = preg_split('/\R/', trim((string) shell_exec($command))) ?: [];
    $status = (int) array_pop($lines);
    $state = json_decode((string) array_pop($lines), true) ?: [];
    $raw = implode("\n", $lines);
    return ['status' => $status, 'json' => json_decode($raw, true) ?: [], 'state' => $state, 'raw' => $raw];
  }

  public function testEndpointsCheckTheSubtabTheEditActionAndResponsibility(): void
  {
    self::assertSame('progress', CommercialActionRouter::STRATEGY_ACTIONS['commercial_plan_progress']);
    $denied = self::endpoint('8', '202', 'Segundo consultor', 'commercial_plan_list', ['lista' => 'objetivos']);
    self::assertSame([403, 'No tienes permiso para entrar al Plan estratégico.'], [$denied['status'], $denied['json']['data']['message'] ?? '']);
    $nonce = self::endpoint('13', '101', 'Gerente', 'commercial_plan_list', ['nonce' => 'otro', 'lista' => 'objetivos']);
    self::assertSame(403, $nonce['status']);

    $list = self::endpoint('9', '101', 'Funcionario de prueba', 'commercial_plan_list', ['lista' => 'mis_tareas', 'filters' => json_encode(['estado' => 'Pendiente'])]);
    self::assertSame(200, $list['status']);
    self::assertSame(['Referidos de propietarios'], array_column($list['json']['data']['rows'], 'nombre'));
    self::assertSame(1, $list['json']['data']['summary']['mis_pendientes']);

    // Sin plan_estrategico_editar no se crea ni se elimina.
    $save = self::endpoint('9', '101', 'Funcionario de prueba', 'commercial_plan_save', ['tipo' => 'objetivos', 'data' => json_encode(['nombre_objetivo' => 'X', 'perspectiva' => 'Gestión Comercial'])]);
    self::assertSame(403, $save['status']);
    $delete = self::endpoint('9', '101', 'Funcionario de prueba', 'commercial_plan_delete', ['tipo' => 'campanas', 'id' => 1]);
    self::assertSame(403, $delete['status']);
    $saved = self::endpoint('7', '202', 'Segundo consultor', 'commercial_plan_save', ['tipo' => 'metas', 'data' => json_encode(['nombre_meta' => 'Nueva', 'id_objetivo' => '1', 'estado' => 'Sin realizar'])]);
    self::assertSame(200, $saved['status']);
    self::assertSame(3, $saved['state']['metas']);
    self::assertArrayHasKey('options', $saved['json']['data']);
    $invalid = self::endpoint('7', '202', 'Segundo consultor', 'commercial_plan_save', ['tipo' => 'metas', 'data' => json_encode(['nombre_meta' => 'Nueva', 'id_objetivo' => '99'])]);
    self::assertSame([422, 'Elige el objetivo al que pertenece la meta.'], [$invalid['status'], $invalid['json']['data']['message'] ?? '']);

    // Avances: el responsable sí (queda a su nombre y con su id_empleado), otro funcionario sin editar no.
    $own = self::endpoint('9', '101', 'Funcionario de prueba', 'commercial_plan_progress', ['id' => 1, 'descripcion' => 'Llamé a 10 propietarios', 'finalizar' => '1']);
    self::assertSame(200, $own['status']);
    $task = $own['state']['tareas'][0];
    self::assertSame(['Completado', '101'], [$task['estado'], $task['cct_author_id']]);
    $entries = json_decode($task['historial_avances'], true);
    self::assertSame(['Funcionario de prueba', '101', 'Llamé a 10 propietarios'], [$entries[1]['funcionario'], $entries[1]['id_empleado'], $entries[1]['descripcion']]);
    $foreign = self::endpoint('9', '202', 'Segundo consultor', 'commercial_plan_progress', ['id' => 1, 'descripcion' => 'No es mía']);
    self::assertSame(403, $foreign['status']);
    self::assertSame(1, count(json_decode($foreign['state']['tareas'][0]['historial_avances'], true)));

    $result = self::endpoint('9', '202', 'Segundo consultor', 'commercial_plan_result', ['id' => 1, 'descripcion' => '300 visitas']);
    self::assertSame(200, $result['status']);
    self::assertSame('300 visitas', json_decode($result['state']['campanas'][0]['informes'], true)[0]['descripcion']);
    self::assertSame(403, self::endpoint('9', '101', 'Funcionario de prueba', 'commercial_plan_result', ['id' => 1, 'descripcion' => 'x'])['status']);
  }
}
