<?php

declare(strict_types=1);

// This fixture uses only a disposable SQLite database and accepts local requests.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/PrecaptacionDatabase.php';
session_start();
define('SCM_BASE_URL', 'http://127.0.0.1:8770/public');
define('SCM_UPLOAD_PATH', sys_get_temp_dir() . '/scm-precaptacion-fixture-uploads');
define('SCM_APP_SECRET', 'fixture-secret');
define('SCM_UPLOAD_MAX_BYTES', 10485760);
$_SESSION += ['scm_logged_in'=>true,'scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9','scm_user'=>'Funcionario de prueba'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['admin'])) $_SESSION['scm_user_cargo'] = $_GET['admin'] === '1' ? '13' : '9';
$db = \Tests\Fixtures\PrecaptacionDatabase::create('sqlite:' . sys_get_temp_dir() . '/scm-precap-' . session_id() . '.sqlite');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['normalize'] ?? '') === '1' && ($_SESSION['scm_user_cargo'] ?? '') === '13') {
  $db->update('wp_jet_cct_precaptaciones', ['promocionado_por'=>'Directo','competencia'=>serialize([''])], ['_ID'=>1]);
}
\SCM\Precaptacion\Module::init($db, new \SCM\Core\Settings($db), new \SCM\Core\Csrf('fixture-secret'), []);
if (isset($_GET['employee']) && in_array($_GET['employee'], ['101','202'], true)) $_SESSION['scm_employee_id'] = $_GET['employee'];
if (isset($_GET['inspect'])) {
  $row = $db->getRow('SELECT * FROM wp_jet_cct_precaptaciones WHERE _ID = ?', [(int) $_GET['inspect']]) ?? [];
  \SCM\Precaptacion\Module::authorizeRecord($row);
  header('Content-Type: application/json');
  echo json_encode(['row'=>$row,'process'=>\SCM\Precaptacion\Module::workflow()->get((int) $row['_ID']),
    'history'=>\SCM\Precaptacion\Module::history()->entries((int) $row['_ID']),
    'task'=>\SCM\Precaptacion\Module::workflow()->task($row)], JSON_THROW_ON_ERROR); exit;
}
if (isset($_GET['home'])) {
  if (isset($_GET['seed_home'])) {
    $row = $db->getRow('SELECT * FROM wp_jet_cct_precaptaciones WHERE _ID = 1');
    if (!\SCM\Precaptacion\Module::history()->latest(1)) {
      \SCM\Precaptacion\Module::workflow()->apply($row, ['tipo_gestion'=>'llamada','resultado_contacto'=>'no_contesto','merece_ticket'=>'Seguir llamando','resultado'=>'No contestó','proximo_contacto'=>date('Y-m-d\TH:i', time()+86400)]);
      $db->update(\SCM\Precaptacion\Module::history()->table(), ['next_contact_at'=>date('Y-m-d H:i:s', time()-3600)], ['precaptacion_id'=>1]);
    }
  }
  $summary = \SCM\Precaptacion\Module::workflow()->summary(\SCM\Precaptacion\Module::policy()->canManage() ? '' : (string) $_SESSION['scm_employee_id']);
  ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../../public/assets/css/tailwind.css"><link rel="stylesheet" href="../../public/assets/css/precaptacion.css"><style>.commercial-modal{display:none}.commercial-modal.open{display:flex}.commercial-modal-open{overflow:hidden}body{background:#faf8ff}</style></head><body><div id="scm-app" data-scm-runtime='{"ajaxUrl":"precaptacion.php","initialTab":"scm-panel-inicio"}'><div data-commercial-tickets-panel><?php echo \SCM\Precaptacion\HomeView::render($summary, SCM_BASE_URL); ?></div></div><script src="../../public/assets/js/commercial-dashboard.js"></script></body></html><?php exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try { \SCM\Precaptacion\Module::dispatch((string) ($_POST['action'] ?? ''), $_POST); }
  catch (\InvalidArgumentException $exception) { \SCM\Precaptacion\Module::rollback(); \SCM\Http\Response\JsonResponse::error($exception->getMessage(), 422); }
  catch (\Throwable $exception) { \SCM\Precaptacion\Module::rollback(); \SCM\Http\Response\JsonResponse::error($exception->getMessage(), 500); }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Precaptación: prueba local</title><link rel="stylesheet" href="../../public/assets/css/tailwind.css"><link rel="stylesheet" href="../../public/assets/css/precaptacion.css"></head>
<body data-precap-api="../../public/precaptacion-api.php" data-precap-nonce="<?php echo esc_attr(\SCM\Precaptacion\Module::nonce()); ?>">
<?php echo \SCM\Precaptacion\FormView::render(new \SCM\Precaptacion\Repository($db)); ?>
<?php echo \SCM\Precaptacion\LegacyPanel::render_shortcode(['modo'=>\SCM\Precaptacion\Module::policy()->canManage() ? 'control' : 'mis']); ?>
<script src="../../public/assets/js/precaptacion.js"></script></body></html>
