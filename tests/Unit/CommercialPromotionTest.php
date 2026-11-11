<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Analytics\TrackerLock;
use SCM\Commercial\Promotion\PortalActivityRepository;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialDashboardView;
use SCM\Views\Promotion\AnalyticsView;
use SCM\Views\Promotion\PortalActivityView;
use SCM\Views\Promotion\PromotionUi;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;
use Tests\Fixtures\CommercialTrackerDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Pestaña Promoción (oculta por defecto) con Analítica web y Actividad de portales, traídas de dashboard-marketing. */
final class CommercialPromotionTest extends TestCase
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
    $this->lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-promotion-test-' . bin2hex(random_bytes(6)) . '.lock';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    CommercialPromotionDatabase::login('13', '101', 'Gerente');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    if (is_file($this->lockFile)) {
      @unlink($this->lockFile);
    }
  }

  private function policy(): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function seed(): void
  {
    CommercialPromotionDatabase::seed($this->db, $this->settings, self::TODAY, $this->lockFile);
  }

  /** @param array<string,mixed> $input */
  private function analytics(array $input = [], bool $catchUp = false): array
  {
    $rollup = $catchUp ? CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile) : null;
    return AnalyticsView::context($input, $this->policy(), $this->db, $this->settings, $rollup, self::TODAY);
  }

  /** @param array<string,mixed> $input */
  private function activity(array $input = [], bool $network = false): array
  {
    return (new PortalActivityRepository($this->db))->summary(PortalActivityRepository::filters($input, self::TODAY), $network);
  }

  public function testPromotionIsOptInWithPermissionedSubtabsAndIpAction(): void
  {
    self::assertContains('promocion', CommercialAccessPolicy::OPT_IN_VIEWS);
    self::assertNotContains('promocion', CommercialAccessPolicy::defaultViews());
    self::assertContains('herramientas', CommercialAccessPolicy::defaultViews());
    self::assertSame(['promocion'], CommercialAccessPolicy::VIEW_GROUPS['Promoción']);
    // En el encabezado va después de Inmuebles y antes de Notificaciones y Herramientas.
    self::assertSame(['Inventario', 'Promoción', 'Comunicación y herramientas'], array_slice(array_keys(CommercialAccessPolicy::VIEW_GROUPS), 2, 3));
    self::assertSame(['analitica_web' => 'Analítica web', 'actividad_portales' => 'Actividad de portales', 'material_pop' => 'Material POP', 'redes_sociales' => 'Redes sociales', 'banco_piezas' => 'Banco de piezas', 'fichas_funcionarios' => 'Fichas de funcionarios'], CommercialAccessPolicy::SUBVIEWS['promocion']);
    self::assertArrayNotHasKey('promocion', CommercialAccessPolicy::SCOPED_VIEWS);
    self::assertContains('promocion_ver_ip', CommercialAccessPolicy::OPT_IN_ACTIONS);
    self::assertSame('Promoción: ver IP y navegador en logs y actividad de portales', CommercialAccessPolicy::ACTIONS['promocion_ver_ip']);
    foreach (array_keys(CommercialAccessPolicy::ACTIONS) as $action) {
      self::assertStringNotContainsString('reuniones', $action);
    }

    // Cargo sin configurar: ve las demás pestañas, no Promoción ni la IP.
    CommercialPromotionDatabase::login('10', '909');
    $policy = $this->policy();
    self::assertTrue($policy->canView('inicio'));
    self::assertTrue($policy->canSubview('herramientas', 'generador_utm'));
    self::assertFalse($policy->canView('promocion'));
    self::assertFalse($policy->canSubview('promocion', 'analitica_web'));
    self::assertFalse($policy->canAct('promocion_ver_ip'));

    // Acceso total: la ve siempre, con la IP.
    CommercialPromotionDatabase::login('13', '101');
    $policy = $this->policy();
    self::assertTrue($policy->canSubview('promocion', 'analitica_web'));
    self::assertTrue($policy->canSubview('promocion', 'actividad_portales'));
    self::assertTrue($policy->canAct('promocion_ver_ip'));

    // Cargos configurados: las subpestañas y la IP son las que tengan marcadas.
    CommercialPromotionDatabase::login('7', '303');
    $policy = $this->policy();
    self::assertTrue($policy->canSubview('promocion', 'analitica_web'));
    self::assertFalse($policy->canAct('promocion_ver_ip'));
    self::assertSame('analitica_web', $policy->resolveNavigation('promocion', [])['promocion_sub']);
    CommercialPromotionDatabase::login('6', '202');
    self::assertTrue($this->policy()->canAct('promocion_ver_ip'));
    CommercialPromotionDatabase::login('8', '202');
    $policy = $this->policy();
    self::assertFalse($policy->canSubview('promocion', 'analitica_web'));
    self::assertTrue($policy->canSubview('promocion', 'actividad_portales'));
    self::assertSame('actividad_portales', $policy->resolveNavigation('promocion', [])['promocion_sub']);
    $this->expectException(\RuntimeException::class);
    $policy->resolveNavigation('promocion', ['promocion_sub' => 'analitica_web']);
  }

  public function testTabGroupsNormalizePromotionWithoutBreakingTools(): void
  {
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'analitica_web'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'analitica_web']));
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'actividad_portales'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'actividad_portales']));
    self::assertSame(['tab' => 'promocion', 'subtab' => 'analitica_web', 'promocion_sub' => 'analitica_web'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'promocion', 'subtab' => 'analitica_web']));
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'generador_utm'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'generador_utm']));
    self::assertSame(['tab' => 'herramientas', 'herramienta' => 'calculadora_aseo'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'calculadora_aseo']));
    self::assertSame('simulador_ingreso_neto', CommercialAccessPolicy::normalizeToolTab(['tab' => 'preaprobado'])['herramienta']);
    self::assertSame('simulador_ingreso_neto', CommercialAccessPolicy::normalizeToolTab(['tab' => 'herramientas', 'subtab' => 'preaprobado'])['herramienta']);
    self::assertSame(['tab' => 'calendario', 'subtab' => 'due'], CommercialAccessPolicy::normalizeToolTab(['tab' => 'calendario', 'subtab' => 'due']));
    self::assertSame('promocion', CommercialAccessPolicy::tabGroupOf('analitica_web'));
    self::assertSame('herramientas', CommercialAccessPolicy::tabGroupOf('generador_utm'));
    self::assertSame('', CommercialAccessPolicy::tabGroupOf('reuniones'));

    // Las subpestañas de los grupos son pestañas propias: no pueden repetirse ni chocar con una vista.
    $tabs = [];
    foreach (array_keys(CommercialAccessPolicy::TAB_GROUPS) as $group) {
      $tabs = array_merge($tabs, array_keys(CommercialAccessPolicy::SUBVIEWS[$group]));
    }
    self::assertSame(count($tabs), count(array_unique($tabs)));
    self::assertSame([], array_intersect($tabs, array_keys(CommercialAccessPolicy::VIEWS)));

    // Sin ninguna subpestaña, Promoción desaparece; Herramientas sigue resolviendo la suya.
    $this->settings->set('commercial_permissions', ['9' => ['views' => ['promocion', 'herramientas'], 'subviews' => ['promocion' => [''], 'herramientas' => ['generador_utm']]]]);
    CommercialPromotionDatabase::login('9', '101');
    $policy = $this->policy();
    self::assertFalse($policy->canView('promocion'));
    self::assertSame('generador_utm', $policy->resolveNavigation('herramientas', [])['herramienta']);
  }

  public function testMenusShowOnlyTheSubtabsTheCargoCanOpen(): void
  {
    CommercialPromotionDatabase::login('8', '202');
    $policy = $this->policy();
    $views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $html = CommercialDashboardView::renderTabs($views, 'promocion', [], [], '/public', [], $policy);
    self::assertStringContainsString('data-commercial-dropdown-menu="promocion"', $html);
    self::assertStringContainsString('href="/public/index.php?tab=actividad_portales"', $html);
    self::assertStringNotContainsString('tab=analitica_web', $html);
    self::assertStringContainsString('Ingresos y menús de los portales de clientes', $html);
    self::assertStringContainsString('Promoción · Actividad de portales', $html);

    CommercialPromotionDatabase::login('7', '303');
    $policy = $this->policy();
    $views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    $html = CommercialDashboardView::renderTabs($views, 'promocion', [], [], '/public', [], $policy);
    self::assertStringContainsString('href="/public/index.php?tab=analitica_web"', $html);
    self::assertStringContainsString('Promoción · Analítica web', $html);

    CommercialPromotionDatabase::login('10', '909');
    $policy = $this->policy();
    $views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
    self::assertNotContains('promocion', $views);
    $html = CommercialDashboardView::renderTabs($views, 'inicio', [], [], '/public', [], $policy);
    self::assertStringNotContainsString('data-commercial-dropdown="promocion"', $html);
    self::assertStringNotContainsString('tab=analitica_web', $html);
    // Herramientas sigue con su menú (incluido el generador UTM).
    self::assertStringContainsString('tab=generador_utm', $html);
    self::assertStringContainsString('Herramientas · Generador UTM', $html);
  }

  public function testAccessSettingsLeavePromotionAndIpUncheckedForUnconfiguredCargos(): void
  {
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 9, 'id_empleado' => '909', 'nombre' => 'Sin configurar', 'activo' => 'Si', 'id_cargo' => '10']);
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 10, 'id_empleado' => '913', 'nombre' => 'Gerente', 'activo' => 'Si', 'id_cargo' => '13']);
    $this->db->insert('wp_jet_cct_funcionarios', ['_ID' => 11, 'id_empleado' => '606', 'nombre' => 'Coordinadora', 'activo' => 'Si', 'id_cargo' => '6']);
    $method = new \ReflectionMethod(CommercialDashboardView::class, 'renderPermissions');
    $html = (string) $method->invoke(null, $this->policy(), []);
    $block = static function (string $cargo) use ($html): string {
      self::assertSame(1, preg_match('/data-cargo="' . $cargo . '".*?(?=data-cargo="|data-perm-pane="filtros")/s', $html, $match));
      return $match[0];
    };
    self::assertMatchesRegularExpression('/value="promocion"(?![^>]*checked)[^>]*data-perm-view-input/', $block('10'));
    self::assertMatchesRegularExpression('/value="inicio" checked="checked"/', $block('10'));
    self::assertMatchesRegularExpression('/value="promocion" checked="checked"/', $block('13'));
    self::assertMatchesRegularExpression('/value="promocion" checked="checked"/', $block('7'));
    self::assertStringContainsString('value="promocion_ver_ip"', $html);
    self::assertMatchesRegularExpression('/value="promocion_ver_ip"(?![^>]*checked)/', $block('10'));
    self::assertMatchesRegularExpression('/value="promocion_ver_ip"(?![^>]*checked)/', $block('7'));
    self::assertMatchesRegularExpression('/value="promocion_ver_ip" checked="checked"/', $block('6'));
  }

  public function testAnalyticsRendersFromTheRollupsWithoutReadingTheRawTable(): void
  {
    $this->seed();
    $from = '2026-10-05';
    $expected = (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_logs WHERE fecha_visita >= ? AND user_agent NOT LIKE '%Googlebot%' AND ip_address <> '10.0.0.5'", [$from . ' 00:00:00']);
    $bots = (int) $this->db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tracker_logs WHERE fecha_visita >= ? AND user_agent LIKE '%Googlebot%'", [$from . ' 00:00:00']);
    // Sin la tabla cruda la pantalla sigue completa: todo sale de los resúmenes.
    $this->db->pdo()->exec('ALTER TABLE wp_jet_cct_tracker_logs RENAME TO tracker_logs_hidden');

    CommercialPromotionDatabase::login('7', '303');
    $context = $this->analytics();
    self::assertSame('', $context['error']);
    self::assertSame($from, $context['filters']->from);
    self::assertSame(self::TODAY, $context['filters']->to);
    self::assertSame($expected, $context['kpis']['visits']);
    self::assertSame($bots, $context['kpis']['excluded']['bots']);
    self::assertCount(30, $context['daily']);
    self::assertSame($expected, array_sum(array_column($context['daily'], 'value')));
    self::assertFalse($context['can_network']);

    $html = PromotionView::wrap('analitica_web', AnalyticsView::render($context, '/public'), '/public');
    self::assertStringContainsString('data-commercial-module-script="/public/assets/js/commercial-promotion.js?v=', $html);
    self::assertStringContainsString('href="/public/assets/css/commercial-promotion.css?v=', $html);
    self::assertStringContainsString('value="' . $from . '"', $html);
    self::assertStringContainsString('Promoción de octubre', $html);
    self::assertStringContainsString('Visitas por día', $html);
    foreach (array_keys(AnalyticsView::LISTS) as $list) {
      self::assertStringContainsString('data-cpr-list="' . $list . '"', $html);
    }
    self::assertMatchesRegularExpression('/data-cpr-list="objects".*?INM-101/s', $html);
    self::assertMatchesRegularExpression('/data-cpr-list="pages".*?sucasainmobiliaria\.com\.co\/inmueble\/apto-101</s', $html);
    self::assertStringNotContainsString('fbclid', $html);
    self::assertStringContainsString('El seguimiento de clics no se está registrando', $html);
    self::assertStringContainsString('data-cpr-logs data-network="0"', $html);
    self::assertStringContainsString('SKC SuCasa Inmobiliaria', $html);
    self::assertStringNotContainsString(CommercialPromotionDatabase::VISIBLE_IP, $html);
    self::assertStringNotContainsString('data-cpr-log-row', $html);
    self::assertStringNotContainsString('Eventos', $html);

    // Filtro por campaña y medio, desde los resúmenes.
    $filtered = $this->analytics(['pr_campana' => 'promo-octubre', 'pr_medio' => 'Instagram']);
    self::assertGreaterThan(0, $filtered['kpis']['visits']);
    self::assertLessThan($expected, $filtered['kpis']['visits']);
    self::assertSame(['promo-octubre'], array_column($filtered['lists']['campaigns'], 'value'));
    self::assertSame(['Instagram'], array_column($filtered['lists']['medios'], 'value'));
    self::assertArrayNotHasKey('sources', $filtered['lists']);

    CommercialPromotionDatabase::login('6', '202');
    self::assertTrue($this->analytics()['can_network']);
  }

  public function testCatchUpWhenOpeningIsNonBlockingAndShowsTheUpdatingNotice(): void
  {
    $this->seed();
    $before = $this->analytics()['kpis']['visits'];
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => self::TODAY . ' 23:00:00', 'ip_address' => '200.9.9.9', 'camp_slug' => 'feria-vivienda']);

    // Si el cron tiene el candado, la página no espera: muestra lo que hay y avisa que está actualizando.
    $cron = new TrackerLock($this->db, $this->lockFile);
    self::assertTrue($cron->acquire());
    $busy = $this->analytics([], true);
    self::assertTrue($busy['sync']['busy']);
    self::assertTrue($busy['sync']['behind']);
    self::assertSame($before, $busy['kpis']['visits']);
    $html = AnalyticsView::render($busy, '/public');
    self::assertStringContainsString('data-cpr-sync="behind"', $html);
    self::assertStringContainsString('Actualizando datos', $html);
    $cron->release();

    $fresh = $this->analytics([], true);
    self::assertSame(1, $fresh['sync']['processed']);
    self::assertFalse($fresh['sync']['behind']);
    self::assertSame($before + 1, $fresh['kpis']['visits']);
    self::assertStringContainsString('data-cpr-sync="ok"', AnalyticsView::render($fresh, '/public'));
  }

  public function testFullPageLoadDoesNotSummarizeAndAsksForTheCatchUpByAjax(): void
  {
    $this->seed();
    $before = $this->analytics()['kpis']['visits'];
    // Visitas de hoy sin resumir (el rango por defecto termina hoy).
    for ($i = 0; $i < 3; $i++) {
      CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => self::TODAY . ' 20:0' . $i . ':00', 'ip_address' => '200.9.9.' . $i]);
    }
    $rollup = CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile);

    // Carga completa (index.php, sesión abierta): solo lee el estado.
    $html = PromotionView::page(['promocion_sub' => 'analitica_web'], $this->policy(), $this->db, $this->settings, '/public', $rollup, self::TODAY, false);
    $last = (int) $this->db->getVar('SELECT last_id FROM wp_scm_tracker_state WHERE id = 1');
    self::assertSame($last, (int) $this->db->getVar('SELECT MAX(_ID) FROM wp_jet_cct_tracker_logs') - 3, 'No resumió nada.');
    self::assertStringContainsString('data-cpr-sync="behind" data-cpr-sync-auto', $html);
    self::assertStringContainsString('las cifras pueden estar incompletas', $html);
    self::assertStringContainsString('class="cpr-stats is-partial" data-cpr-kpis data-cpr-partial', $html);

    // Por AJAX (api.php ya cerró la sesión) sí resume, y el aviso ya no pide otra recarga.
    $ajax = AnalyticsView::context([], $this->policy(), $this->db, $this->settings, $rollup, self::TODAY, true);
    self::assertSame(3, $ajax['sync']['processed']);
    self::assertSame($before + 3, $ajax['kpis']['visits']);
    $html = AnalyticsView::render($ajax, '/public');
    self::assertStringNotContainsString('data-cpr-sync-auto', $html);
    self::assertStringContainsString('data-cpr-sync="ok"', $html);
    self::assertStringNotContainsString('is-partial', $html);

    // Pendientes posteriores al rango elegido: el rango ya está completo.
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => self::TODAY . ' 21:00:00']);
    $past = AnalyticsView::context(['pr_desde' => '2026-10-10', 'pr_hasta' => '2026-10-20'], $this->policy(), $this->db, $this->settings, $rollup, self::TODAY, false);
    self::assertSame('recent', AnalyticsView::coverage($past));
    self::assertStringContainsString('el rango elegido ya está completo', AnalyticsView::render($past, '/public'));

    // Un recálculo en curso se anuncia.
    $this->db->pdo()->exec("UPDATE wp_scm_tracker_state SET rebuild = '{\"from\":\"2026-10-01\",\"requested\":\"2026-10-01\",\"next\":\"2026-10-15\"}' WHERE id = 1");
    $rebuilding = AnalyticsView::render(AnalyticsView::context([], $this->policy(), $this->db, $this->settings, $rollup, self::TODAY, false), '/public');
    self::assertStringContainsString('Recalculando las cifras desde el 01/10/2026: va en el 15/10/2026', $rebuilding);
  }

  public function testAnalyticsBeforeTheFirstRollupExplainsTheEmptyNumbers(): void
  {
    CommercialTrackerDatabase::hit($this->db, ['fecha_visita' => '2026-10-20 10:00:00']);
    $rollup = CommercialPromotionDatabase::rollup($this->db, $this->settings, self::TODAY, $this->lockFile);
    $context = AnalyticsView::context([], $this->policy(), $this->db, $this->settings, $rollup, self::TODAY, false);
    self::assertFalse($context['ready']);
    self::assertSame('pending', AnalyticsView::coverage($context));
    $html = AnalyticsView::render($context, '/public');
    self::assertStringContainsString('data-cpr-sync="pending" data-cpr-sync-auto', $html);
    self::assertStringContainsString('la analítica web se está preparando', $html);
    self::assertStringContainsString('is-partial', $html);
    // Sin visitas en el rango: ni nota de exclusiones con ceros ni un "máximo 1" inventado.
    self::assertStringNotContainsString('data-cpr-excluded', $html);
    self::assertStringNotContainsString('máximo', $html);
    self::assertStringContainsString('data-cpr-chart-empty', $html);
  }

  public function testAnalyticsCopyChartTicksAndExclusionNote(): void
  {
    $this->seed();
    $html = AnalyticsView::render($this->analytics(), '/public');
    self::assertStringContainsString('Visitas al sitio de SKC SuCasa Inmobiliaria: campañas, medios, páginas e inmuebles.', $html);
    self::assertStringNotContainsString('registradas por el tracker', $html);
    self::assertStringContainsString('Por dirección IP (se guarda cifrada)', $html);
    self::assertMatchesRegularExpression('/De [\d.]+ visitas registradas no se cuentan [\d.]+ de robots \([\d,]+ %\) ni [\d.]+ internas de la oficina \([\d,]+ %\)\./', $html);
    self::assertStringContainsString('Sin campaña', $html);

    // 30 barras: primera, cada semana y última, sin una semanal pegada a la última (índice 28 junto al 29).
    preg_match_all('/<li class="cpr-chart__bar"[^>]*aria-label="([^"]+)"[^>]*>(.*?)<\/li>/s', $html, $bars, PREG_SET_ORDER);
    self::assertCount(30, $bars);
    $ticks = [];
    foreach ($bars as $index => $bar) {
      if (str_contains($bar[2], 'cpr-chart__tick')) {
        $ticks[] = $index;
      }
    }
    self::assertSame([0, 7, 14, 21, 29], $ticks);
    self::assertStringContainsString('máximo', $html);

    $empty = PromotionUi::dailyChart('Accesos por día', 'accesos', [['day' => '2026-01-01', 'value' => 0], ['day' => '2026-01-02', 'value' => 0]]);
    self::assertStringContainsString('Sin accesos en el rango', $empty);
    self::assertStringNotContainsString('máximo', $empty);
  }

  public function testActivityRespectsTheDateRangeAndExactFilters(): void
  {
    $this->seed();
    $all = $this->activity();
    self::assertSame('2026-10-05', $all['filters']['from']);
    self::assertSame(60, $all['kpis']['accesos']);
    self::assertSame(4, $all['kpis']['usuarios_unicos']);
    // PPH-OLD ingresó hace 80 días: fuera del rango no cuenta (en el original sí).
    self::assertSame(5, $all['kpis']['usuarios_sesion']);
    self::assertSame(14 + 9 + 4 + 3 + 2, $all['kpis']['sesiones']);
    self::assertNotContains('Menú antiguo', $all['options']['menus']);
    self::assertSame(['cliente', 'club-pph'], $all['options']['proyectos']);
    self::assertNotContains('PPH-OLD', array_column($all['top_session_users'], 'usuario'));
    self::assertSame(60, array_sum(array_column($all['daily'], 'value')));
    self::assertCount(30, $all['daily']);
    $types = array_column($all['by_actor'], null, 'tipo_actor');
    // "cliente para venta" y "Quiero vender mi inmueble" son el mismo tipo; el vacío es "Sin clasificar".
    self::assertSame(15, $types['Cliente para venta']['accesos']);
    self::assertSame(2, $types['Cliente para venta']['usuarios_sesion']);
    self::assertSame(7, $types['Cliente para venta']['sesiones']);
    self::assertSame(15, $types['Sin clasificar']['accesos']);
    self::assertSame(['Club PPH', 'Cliente para arriendo', 'Cliente para venta', 'Sin clasificar'], array_values(array_intersect(PortalActivityRepository::ACTOR_TYPES, array_keys($types))));
    self::assertSame('Otros', PortalActivityRepository::actorType('Algo nuevo'));
    self::assertSame('Cliente para avalúo', PortalActivityRepository::actorType(' Cliente para AVALÚO '));

    // Paginación de los últimos accesos: 25 por página, del más reciente al más antiguo.
    self::assertSame(3, $all['pages']);
    self::assertCount(25, $all['recent']);
    self::assertGreaterThanOrEqual($all['recent'][1]['creado_en'], $all['recent'][0]['creado_en']);
    self::assertCount(10, $this->activity(['pr_pag' => '3'])['recent']);
    self::assertCount(10, $this->activity(['pr_pag' => '99'])['recent']);
    self::assertArrayNotHasKey('ip', $all['recent'][0]);
    self::assertArrayNotHasKey('user_agent', $all['recent'][0]);
    self::assertArrayHasKey('ip', $this->activity([], true)['recent'][0]);

    // Filtros exactos.
    self::assertSame(15, $this->activity(['pr_actor' => 'Cliente para venta'])['kpis']['accesos']);
    self::assertSame(2, $this->activity(['pr_actor' => 'Cliente para venta'])['kpis']['usuarios_sesion']);
    self::assertSame(15, $this->activity(['pr_actor' => 'Sin clasificar'])['kpis']['accesos']);
    self::assertSame(0, $this->activity(['pr_actor' => 'Propietario'])['kpis']['accesos']);
    self::assertSame('', $this->activity(['pr_actor' => 'inventado'])['filters']['actor']);
    self::assertSame(15, $this->activity(['pr_proyecto' => 'club-pph'])['kpis']['accesos']);
    self::assertSame(0, $this->activity(['pr_proyecto' => 'club'])['kpis']['accesos']);
    $inicio = $this->activity(['pr_menu' => 'Inicio'])['kpis']['accesos'];
    self::assertGreaterThan(0, $inicio);
    self::assertSame(0, $this->activity(['pr_menu' => 'Inici'])['kpis']['accesos']);
    self::assertSame(15, $this->activity(['pr_q' => '1001'])['kpis']['accesos']);
    self::assertSame(15, $this->activity(['pr_q' => 'Laura'])['kpis']['accesos']);
    self::assertSame(0, $this->activity(['pr_q' => 'aura'])['kpis']['accesos']);
    self::assertSame(0, $this->activity(['pr_q' => '%'])['kpis']['accesos']);

    // Rango de un día: solo los accesos de hoy; el día 45 entra con un rango que lo cubre.
    $today = $this->activity(['pr_desde' => self::TODAY, 'pr_hasta' => self::TODAY]);
    self::assertSame(3, $today['kpis']['accesos']);
    self::assertCount(1, $today['daily']);
    $wide = $this->activity(['pr_desde' => '2026-09-01', 'pr_hasta' => self::TODAY]);
    self::assertSame(61, $wide['kpis']['accesos']);
    self::assertContains('Menú antiguo', $wide['options']['menus']);
    // El rango tiene tope de 12 meses y las fechas al revés se ordenan.
    self::assertTrue(PortalActivityRepository::filters(['pr_desde' => '2020-01-01'], self::TODAY)['clamped']);
    self::assertSame('2026-10-01', PortalActivityRepository::filters(['pr_desde' => '2026-10-10', 'pr_hasta' => '2026-10-01'], self::TODAY)['from']);
  }

  public function testActivityViewShowsIpOnlyWithThePermission(): void
  {
    $this->seed();
    CommercialPromotionDatabase::login('8', '202');
    $html = PromotionView::page(['promocion_sub' => 'actividad_portales'], $this->policy(), $this->db, $this->settings, '/public', false, self::TODAY);
    self::assertStringContainsString('data-commercial-promotion="actividad_portales"', $html);
    self::assertStringContainsString('Últimos accesos', $html);
    self::assertStringContainsString('Página 1 de 3', $html);
    self::assertStringContainsString('pr_pag=2', $html);
    self::assertStringNotContainsString('181.60.', $html);
    self::assertStringNotContainsString('<th>IP</th>', $html);
    self::assertStringNotContainsString('Chrome/130', $html);
    // Sin la subpestaña de Analítica web, pedirla no muestra nada.
    self::assertStringContainsString('no está disponible', PromotionView::page(['promocion_sub' => 'analitica_web'], $this->policy(), $this->db, $this->settings, '/public', false, self::TODAY));

    CommercialPromotionDatabase::login('6', '202');
    $context = PortalActivityView::context(['pr_actor' => 'Club PPH'], $this->policy(), $this->db, self::TODAY);
    $html = PortalActivityView::render($context, '/public');
    self::assertStringContainsString('<th>IP</th>', $html);
    self::assertStringContainsString(CommercialPromotionDatabase::ACCESS_IP, $html);
    self::assertStringContainsString('<option value="Club PPH" selected>', $html);
    self::assertSame(15, $context['data']['kpis']['accesos']);
  }

  /** @return array{status:int,json:array<string,mixed>,raw:string} */
  private static function endpoint(string $cargo, string $employee, string $action, array $input = []): array
  {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/promotion-endpoint.php') . ' '
      . implode(' ', array_map('escapeshellarg', [$cargo, $employee, $action, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))]));
    $lines = preg_split('/\R/', trim((string) shell_exec($command))) ?: [];
    $status = (int) array_pop($lines);
    $raw = implode("\n", $lines);
    return ['status' => $status, 'json' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'raw' => $raw];
  }

  public function testLogsEndpointChecksPermissionsPaginatesAndHidesTheIp(): void
  {
    self::assertSame('analyticsLogs', CommercialActionRouter::PROMOTION_ACTIONS['commercial_promo_analytics_logs']);
    $denied = self::endpoint('10', '909', 'commercial_promo_analytics_logs');
    self::assertSame([403, 'No tienes permiso para entrar a Analítica web.'], [$denied['status'], $denied['json']['data']['message']]);
    $sub = self::endpoint('8', '202', 'commercial_promo_analytics_logs');
    self::assertSame(403, $sub['status']);
    $nonce = self::endpoint('13', '101', 'commercial_promo_analytics_logs', ['nonce' => 'otro']);
    self::assertSame([403, 'Verificación de seguridad fallida.'], [$nonce['status'], $nonce['json']['data']['message']]);

    $plain = self::endpoint('7', '303', 'commercial_promo_analytics_logs');
    self::assertSame(200, $plain['status']);
    $rows = $plain['json']['data']['rows'];
    self::assertCount(25, $rows);
    foreach ($rows as $row) {
      self::assertArrayNotHasKey('ip', $row);
      self::assertArrayNotHasKey('user_agent', $row);
      self::assertFalse($row['bot']);
      self::assertFalse($row['internal']);
    }
    self::assertStringNotContainsString('181.50.', $plain['raw']);
    self::assertStringNotContainsString('Chrome', $plain['raw']);
    self::assertNotNull($plain['json']['data']['next_cursor']);
    self::assertGreaterThanOrEqual($rows[24]['fecha'], $rows[0]['fecha']);

    $second = self::endpoint('7', '303', 'commercial_promo_analytics_logs', ['cursor' => $plain['json']['data']['next_cursor']]);
    self::assertSame([], array_intersect(array_column($rows, 'id'), array_column($second['json']['data']['rows'], 'id')));
    self::assertLessThanOrEqual($rows[24]['fecha'], $second['json']['data']['rows'][0]['fecha']);

    $network = self::endpoint('6', '202', 'commercial_promo_analytics_logs', ['pr_campana' => 'promo-octubre']);
    self::assertSame(200, $network['status']);
    self::assertArrayHasKey('ip', $network['json']['data']['rows'][0]);
    self::assertStringContainsString('181.50.', $network['raw']);
    self::assertSame(['promo-octubre'], array_values(array_unique(array_column($network['json']['data']['rows'], 'campaign'))));
  }
}
