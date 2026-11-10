<?php

declare(strict_types=1);

// Pestaña Llamadas dentro del panel real, con base SQLite aislada. Servir el repositorio en 127.0.0.1:8774
// y ejecutar tools/check-commercial-calls.cjs. ?as=101 entra como consultor; sin parámetro, como administrador.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialCallsDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8774/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_APP_SECRET', str_repeat('x', 40));

session_start();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  $employee = in_array($_GET['as'] ?? '', ['101', '202'], true) ? (string) $_GET['as'] : '303';
  $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => (int) $employee, 'scm_employee_id' => $employee, 'scm_user_cargo' => $employee === '303' ? '13' : '9', 'scm_user' => 'Prueba', 'db' => $_SESSION['db'] ?? bin2hex(random_bytes(6))];
}
$db = Tests\Fixtures\CommercialCallsDatabase::create('sqlite:' . sys_get_temp_dir() . '/scm-calls-' . $_SESSION['db'] . '.sqlite');
// Función MySQL que usa la configuración de permisos del panel.
$db->pdo()->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
$settings = new SCM\Core\Settings($db);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$config = ['dashboard_admin_cargos' => ['13'], 'commercial_employee_cargos' => ['9', '13']];

if (isset($_GET['enlace_tarea'])) {
  echo SCM\Support\PublicTaskAccess::reference((int) $_GET['enlace_tarea']);
  exit;
}
if (isset($_GET['t'])) {
  // Vista pública real (public/tarea.php) sobre la base de prueba.
  session_write_close();
  $limiter = new SCM\Support\FileRateLimiter(sys_get_temp_dir() . '/scm-calls-limits-' . getmypid());
  (new SCM\Http\Controller\PublicTaskPageController($db, $settings, $limiter, 'http://127.0.0.1:8774/tests/Fixtures/commercial-calls.php'))->handle($_GET, $_POST, (string) $_SERVER['REQUEST_METHOD'], '127.0.0.1');
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  session_write_close();
  $calls = static fn(): SCM\Http\Controller\CommercialCallsApiController => new SCM\Http\Controller\CommercialCallsApiController($db, $settings, $csrf, $config);
  (new SCM\Http\Api\CommercialActionRouter(new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config), null, null, null, null, $calls))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  exit;
}
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['13']);
$html = SCM\Views\CommercialDashboardView::render([
  'bucket' => 'llamadas',
  'policy' => $policy,
  'visible_views' => array_values(array_filter(array_keys(SCM\Commercial\CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $view === 'llamadas' || $policy->canManage())),
  'calls_html' => SCM\Views\CommercialCallsView::page($_GET, new SCM\Commercial\Calls\CommercialCallsService($db, $settings, $policy, $config), $policy),
  'base_url' => SCM_BASE_URL,
  'runtime' => ['ajaxUrl' => SCM_BASE_URL . '/api.php', 'nonce' => 'fixture', 'initialTab' => 'scm-panel-llamadas', 'actions' => SCM\Commercial\CommercialCalendarService::runtimeActions(), 'config' => []],
]);
$self = 'http://127.0.0.1:8774/tests/Fixtures/commercial-calls.php';
echo str_replace(str_replace('/', '\\/', SCM_BASE_URL . '/api.php'), str_replace('/', '\\/', $self), $html);
