<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialPropertyEntryDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8771/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'task-fixture');
define('SCM_UPLOAD_PATH', dirname(__DIR__, 2) . '/output/task-media');
define('SCM_APP_SECRET', str_repeat('x', 40));
define('SCM_UPLOAD_MAX_BYTES', 10485760);
session_name('commercial_task_entry_fixture'); session_start();
$_SESSION += ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba','scm_logged_in'=>true];
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$db = Tests\Fixtures\CommercialPropertyEntryDatabase::create('sqlite:' . dirname(__DIR__, 2) . '/output/property-entry.sqlite');
$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$config = ['base_url'=>SCM_BASE_URL,'dashboard_admin_cargos'=>['11'],'sign_notification_emails'=>'admin@example.test','property_entry_notification_emails'=>'admin@example.test'];
$cards = '<section class="p-8"><h1 class="text-xl font-bold mb-4">Tareas comerciales</h1><button type="button" class="px-4 py-3 rounded-xl bg-primary text-white" data-commercial-open-case="100">Abrir tarea #100</button></section>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (!$csrf->verify('commercial_nonce', (string) ($_POST['nonce'] ?? ''), false)) SCM\Http\Response\JsonResponse::error('Verificación de seguridad fallida.',403);
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') SCM\Http\Response\JsonResponse::success(['html'=>$cards,'tab'=>'mis_tickets']);
  if (($_POST['action'] ?? '') === 'commercial_ticket_detail') {
    $id = (int) ($_POST['ticket_pk'] ?? 0);
    $task = (new SCM\Commercial\CommercialPropertyEntryService($db, $policy, $config))->task($id);
    $timeline = $db->getResults('SELECT _ID,fecha AS _timestamp,nombre AS actor_name,respuesta AS message,? AS type FROM wp_jet_cct_historial_del_ticket WHERE id_ticket=? ORDER BY _ID DESC', ['respuesta',$id]);
    SCM\Http\Response\JsonResponse::success(['html'=>SCM\Views\CommercialTicketModalView::render(['ticket'=>$task,'timeline'=>$timeline], $policy, [])]);
  }
  try { (new SCM\Http\Api\CommercialActionRouter(new SCM\Http\Controller\CommercialApiController($db,$settings,$csrf,$config)))->dispatch((string) ($_POST['action'] ?? ''),$_POST); }
  catch (Throwable $error) { http_response_code(500); echo json_encode(['success'=>false,'data'=>['message'=>$error->getMessage()]]); }
  exit;
}
$html = SCM\Views\CommercialDashboardView::render(['bucket'=>'sin_acceso','policy'=>$policy,'visible_views'=>['mis_tickets','anadir_aviso'],'base_url'=>SCM_BASE_URL,'runtime'=>['ajaxUrl'=>'http://127.0.0.1:8771/tests/Fixtures/commercial-property-entry.php','nonce'=>'fixture']]);
echo str_replace(SCM\Views\CommercialDashboardView::renderAccessDenied(SCM_BASE_URL), $cards, $html);
