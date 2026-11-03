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
// ?cargo=8 entra con un cargo que tiene sus herramientas guardadas sin el Generador UTM.
if (isset($_GET['cargo'])) $_SESSION['scm_user_cargo'] = preg_replace('/\D/', '', (string) $_GET['cargo']);
$db = Tests\Fixtures\CommercialOwnerContractsDatabase::create('sqlite:' . dirname(__DIR__, 2) . '/output/commercial-tools.sqlite');
$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
// Tracker de dashboard-marketing (solo lectura) y catálogo del Generador UTM con una campaña.
$db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_tracker_logs (_ID INTEGER PRIMARY KEY AUTOINCREMENT, camp_slug TEXT, utm_source TEXT, utm_medium TEXT, ip_address TEXT)');
$db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_tracker_camps (_ID INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, nombre TEXT)');
if (!$db->getVar('SELECT _ID FROM wp_jet_cct_tracker_logs LIMIT 1')) {
  foreach ([['feria_vivienda', 'instagram', 'whatsapp'], ['Revista Esencia', 'Directo', 'Navegador'], ['general', 'Google', 'cpc']] as [$camp, $source, $medium]) $db->insert('wp_jet_cct_tracker_logs', ['camp_slug'=>$camp,'utm_source'=>$source,'utm_medium'=>$medium,'ip_address'=>'10.0.0.1']);
  $db->insert('wp_jet_cct_tracker_camps', ['slug'=>'feria_vivienda','nombre'=>'Feria de vivienda']);
}
if ($settings->get(SCM\Commercial\Tools\UtmCatalog::SETTING, null) === null) {
  SCM\Commercial\Tools\UtmCatalog::save($settings, ['campanas'=>[['label'=>'Revista Esencia junio','value'=>'esencia-inmobiliaria-edicion-junio-2026']]] + SCM\Commercial\Tools\UtmCatalog::defaults(), 1);
  $settings->set('commercial_permissions', ['8'=>['views'=>['herramientas'],'subviews'=>['herramientas'=>['simulador_ingreso_neto','ganancia_ocasional','calculadora_mercado','calculadora_aseo']],'actions'=>[]]]);
}
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$config = ['base_url'=>SCM_BASE_URL,'dashboard_admin_cargos'=>['11']];
$service = new SCM\Commercial\CommercialOwnerPreapprovalService($db, $policy);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    SCM\Http\Response\JsonResponse::success(['html'=>SCM\Views\CommercialToolsView::render($tool = (string) (SCM\Commercial\CommercialAccessPolicy::normalizeToolTab($_POST)['herramienta'] ?? ''), $service, SCM_BASE_URL, SCM\Views\CommercialToolsView::context($tool, $policy, $db, $settings)),'tab'=>'herramientas']);
  }
  try {
    $controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
    (new SCM\Http\Api\CommercialActionRouter($controller))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  } catch (Throwable $exception) { http_response_code(500); echo json_encode(['success'=>false,'data'=>['message'=>$exception->getMessage()]]); }
  exit;
}
$tool = (string) (SCM\Commercial\CommercialAccessPolicy::normalizeToolTab($_GET + ['tab'=>'simulador_ingreso_neto'])['herramienta'] ?? '');
echo SCM\Views\CommercialDashboardView::render([
  'bucket'=>'herramientas','policy'=>$policy,'visible_views'=>['herramientas'],'tools_service'=>$service,'tools_view'=>$tool,'tools_context'=>SCM\Views\CommercialToolsView::context($tool, $policy, $db, $settings),'base_url'=>SCM_BASE_URL,
  'runtime'=>['ajaxUrl'=>'http://127.0.0.1:8771/tests/Fixtures/commercial-tools.php','nonce'=>'fixture','initialTab'=>'scm-panel-herramientas'],
]);
