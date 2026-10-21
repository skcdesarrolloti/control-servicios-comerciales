<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialSignDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8771/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');
define('SCM_UPLOAD_PATH', dirname(__DIR__, 2) . '/output/sign-media');
define('SCM_APP_SECRET', str_repeat('x', 40));
define('SCM_UPLOAD_MAX_BYTES', 10485760);
session_name('commercial_sign_fixture');
session_start();
$_SESSION += ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba','scm_logged_in'=>true];
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$db = Tests\Fixtures\CommercialSignDatabase::create('sqlite:' . dirname(__DIR__, 2) . '/output/commercial-signs.sqlite');
$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$config = ['base_url'=>SCM_BASE_URL,'dashboard_admin_cargos'=>['11'],'sign_notification_emails'=>'admin@example.test'];
$service = new SCM\Commercial\CommercialSignService($db, $policy, $config);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    SCM\Http\Response\JsonResponse::success(['html'=>SCM\Views\CommercialSignView::render($service, $policy),'tab'=>'anadir_aviso']);
  }
  try {
    $controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
    (new SCM\Http\Api\CommercialActionRouter($controller))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  } catch (Throwable $exception) { http_response_code(500); echo json_encode(['success'=>false,'data'=>['message'=>$exception->getMessage()]]); }
  exit;
}
echo SCM\Views\CommercialDashboardView::render([
  'bucket'=>'anadir_aviso','policy'=>$policy,'visible_views'=>['anadir_aviso'],'sign_service'=>$service,'base_url'=>SCM_BASE_URL,
  'runtime'=>['ajaxUrl'=>'http://127.0.0.1:8771/tests/Fixtures/commercial-signs.php','nonce'=>'fixture','initialTab'=>'scm-panel-anadir_aviso'],
]);
