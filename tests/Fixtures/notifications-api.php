<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require dirname(__DIR__, 2) . '/tests/Fixtures/CommercialNotificationsFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_UPLOAD_MAX_BYTES', 10485760);
define('SCM_UPLOAD_PATH', dirname(__DIR__, 2) . '/output/media');
define('SCM_APP_SECRET', str_repeat('x', 40));
define('SCM_VERSION', 'test');
session_name('scm_notifications_fixture');
session_start();
$actorPreviews = $_SESSION['scm_actor_previews'] ?? [];
[$db, $policy, $service] = isset($_GET['audit'])
  ? Tests\Fixtures\CommercialNotificationsFixture::makeWithAudit($_GET['audit'] === 'admin')
  : Tests\Fixtures\CommercialNotificationsFixture::make();
$_SESSION['scm_actor_previews'] = $actorPreviews;
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
if (($_POST['restricted'] ?? '') === '1') {
  $db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'control_servicios_comerciales_config', 'valor' => '{"commercial_permissions":{"9":{"views":["inicio"],"actions":[]}}}']);
}
$settings = new SCM\Core\Settings($db, true, 'control_servicios_comerciales_config');
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, ['dashboard_admin_cargos' => ['11']]);
$router = new SCM\Http\Api\CommercialActionRouter($controller);
header('Content-Type: application/json');
try { $router->dispatch((string) ($_POST['action'] ?? ''), $_POST); }
catch (Throwable $error) { http_response_code(500); echo json_encode(['success' => false, 'data' => ['message' => $error->getMessage()]]); }
