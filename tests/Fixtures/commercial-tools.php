<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialOwnerContractsDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8771/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');
define('SCM_APP_SECRET', str_repeat('x', 40));
session_name('commercial_tools_fixture');
session_start();
$_SESSION += ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba','scm_logged_in'=>true];
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$db = Tests\Fixtures\CommercialOwnerContractsDatabase::create('sqlite:' . dirname(__DIR__, 2) . '/output/commercial-tools.sqlite');
$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$config = ['base_url'=>SCM_BASE_URL,'dashboard_admin_cargos'=>['11']];
$service = new SCM\Commercial\CommercialOwnerPreapprovalService($db, $policy);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    SCM\Http\Response\JsonResponse::success(['html'=>SCM\Views\CommercialToolsView::render((string) ($_POST['subtab'] ?? 'preaprobado'), $service, SCM_BASE_URL),'tab'=>'herramientas']);
  }
  try {
    $controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
    (new SCM\Http\Api\CommercialActionRouter($controller))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  } catch (Throwable $exception) { http_response_code(500); echo json_encode(['success'=>false,'data'=>['message'=>$exception->getMessage()]]); }
  exit;
}
echo SCM\Views\CommercialDashboardView::render([
  'bucket'=>'herramientas','policy'=>$policy,'visible_views'=>['herramientas'],'tools_service'=>$service,'tools_view'=>(string) ($_GET['subtab'] ?? 'preaprobado'),'base_url'=>SCM_BASE_URL,
  'runtime'=>['ajaxUrl'=>'http://127.0.0.1:8771/tests/Fixtures/commercial-tools.php','nonce'=>'fixture','initialTab'=>'scm-panel-herramientas'],
]);
