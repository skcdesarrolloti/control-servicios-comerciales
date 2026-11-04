<?php

declare(strict_types=1);

// Fixture local del Plan estratégico para tools/check-commercial-strategy.cjs: php -S 127.0.0.1:8772 -t .
// ?cargo=9&nombre=Segundo+consultor entra con otro cargo y otro funcionario (la sesión los recuerda).
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/StrategicPlanDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8772/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');
define('SCM_APP_SECRET', str_repeat('x', 40));
session_name('commercial_strategy_fixture');
session_start();
$_SESSION += ['scm_user_id' => 1, 'scm_employee_id' => '101', 'scm_user_cargo' => '13', 'scm_user' => 'Funcionario de prueba', 'scm_logged_in' => true];
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
if (isset($_GET['cargo'])) $_SESSION['scm_user_cargo'] = preg_replace('/\D/', '', (string) $_GET['cargo']);
if (isset($_GET['nombre'])) {
  $_SESSION['scm_user'] = (string) $_GET['nombre'];
  $_SESSION['scm_employee_id'] = $_GET['nombre'] === 'Segundo consultor' ? '202' : '101';
}
$file = dirname(__DIR__, 2) . '/output/commercial-strategy.sqlite';
$fresh = !is_file($file);
$db = Tests\Fixtures\StrategicPlanDatabase::create('sqlite:' . $file);
$settings = $fresh ? Tests\Fixtures\StrategicPlanDatabase::settings($db) : new SCM\Core\Settings($db);
if ($fresh) Tests\Fixtures\StrategicPlanDatabase::seed($db);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['13']);
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['13']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    $tool = (string) (SCM\Commercial\CommercialAccessPolicy::normalizeToolTab($_POST)['herramienta'] ?? '');
    SCM\Http\Response\JsonResponse::success(['html' => SCM\Views\CommercialToolsView::render($tool, null, SCM_BASE_URL, SCM\Views\CommercialToolsView::context($tool, $policy, $db, $settings)), 'tab' => 'herramientas']);
  }
  try {
    $router = new SCM\Http\Api\CommercialActionRouter(
      new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config),
      null,
      static fn(): SCM\Http\Controller\StrategicPlanApiController => new SCM\Http\Controller\StrategicPlanApiController($db, $settings, $csrf, $config)
    );
    $router->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  } catch (Throwable $exception) { http_response_code(500); echo json_encode(['success' => false, 'data' => ['message' => $exception->getMessage()]]); }
  exit;
}
$tool = 'plan_estrategico';
echo SCM\Views\CommercialDashboardView::render([
  'bucket' => 'herramientas', 'policy' => $policy, 'visible_views' => ['herramientas'], 'tools_view' => $tool,
  'tools_context' => SCM\Views\CommercialToolsView::context($tool, $policy, $db, $settings), 'base_url' => SCM_BASE_URL,
  'runtime' => ['ajaxUrl' => 'http://127.0.0.1:8772/tests/Fixtures/commercial-strategy.php', 'nonce' => 'fixture', 'initialTab' => 'scm-panel-herramientas'],
]);
