<?php

declare(strict_types=1);

// Estudios de aseguradora dentro del panel real, con base SQLite aislada. Servir el repositorio en 127.0.0.1:8776 y
// ejecutar tools/check-commercial-studies.cjs. ?as=101 entra como consultor; sin parámetro, como administrador.
// ?e=<enlace> o ?id=<estudio> sirven la vista real (public/estudio.php) sobre la base de prueba.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialStudiesDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8776/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_APP_SECRET', str_repeat('x', 40));
define('SCM_UPLOAD_PATH', sys_get_temp_dir() . '/scm-studies-uploads');
define('SCM_UPLOAD_MAX_BYTES', 10485760);

// Los documentos subidos se sirven como public/file.php, sobre la carpeta de prueba.
if (isset($_GET['n'], $_GET['s'])) {
  $files = SCM\Support\StoredFileService::fromRuntime();
  $path = $files->isValidSignature((string) $_GET['n'], (string) $_GET['s']) ? $files->pathFor((string) $_GET['n']) : null;
  if ($path === null) { http_response_code(404); exit('Archivo no disponible.'); }
  header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($path));
  readfile($path);
  exit;
}

session_start();
$view = isset($_GET['e']) || isset($_GET['id']) || isset($_GET['d']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !$view) {
  $employee = in_array($_GET['as'] ?? '', ['101', '202'], true) ? (string) $_GET['as'] : '303';
  $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => (int) $employee, 'scm_employee_id' => $employee, 'scm_user_cargo' => $employee === '303' ? '13' : '9', 'scm_user' => 'Prueba', 'db' => $_SESSION['db'] ?? bin2hex(random_bytes(6))];
}
$db = Tests\Fixtures\CommercialStudiesDatabase::create('sqlite:' . sys_get_temp_dir() . '/scm-studies-' . ($_SESSION['db'] ?? 'publico') . '.sqlite');
$db->pdo()->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
$settings = new SCM\Core\Settings($db);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$config = ['dashboard_admin_cargos' => ['13'], 'commercial_employee_cargos' => ['9', '13']];
// Los avisos quedan en un archivo en lugar de la cola real.
$log = sys_get_temp_dir() . '/scm-studies-notices-' . ($_SESSION['db'] ?? 'publico') . '.log';
$notifier = new SCM\Commercial\Studies\CommercialStudiesNotifier($db, $settings,
  static function ($to, string $subject) use ($log): void { file_put_contents($log, 'correo|' . (is_array($to) ? implode(',', $to) : $to) . '|' . $subject . "\n", FILE_APPEND); },
  static function (string $phone, string $template) use ($log): void { file_put_contents($log, 'whatsapp|' . $phone . '|' . $template . "\n", FILE_APPEND); }
);

if ($view) {
  session_write_close();
  (new SCM\Http\Controller\PublicStudyPageController($db, $settings, new SCM\Support\FileRateLimiter(sys_get_temp_dir() . '/scm-studies-limits-' . getmypid()), $config))->handle($_GET, 'GET', '127.0.0.1');
  exit;
}
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['13']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  session_write_close();
  $service = new SCM\Commercial\Studies\CommercialStudiesService(new SCM\Commercial\Studies\CommercialStudiesRepository($db), $settings, $policy, $notifier);
  $studies = static fn(): SCM\Http\Controller\CommercialStudiesApiController => new SCM\Http\Controller\CommercialStudiesApiController($db, $settings, $csrf, $config, $service);
  if (($_POST['action'] ?? '') === 'fixture_notices') {
    SCM\Http\Response\JsonResponse::success(['lines' => is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : []]);
  }
  (new SCM\Http\Api\CommercialActionRouter(new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config), null, null, null, null, null, null, $studies))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  exit;
}
$settings->set(SCM\Commercial\Studies\CommercialStudiesService::SETTING, ['responsable_entrega' => '404'], 1);
$html = SCM\Views\CommercialDashboardView::render([
  'bucket' => 'estudios',
  'policy' => $policy,
  'visible_views' => array_values(array_filter(array_keys(SCM\Commercial\CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view))),
  'studies_html' => SCM\Views\CommercialStudiesView::page($_GET, new SCM\Commercial\Studies\CommercialStudiesService(new SCM\Commercial\Studies\CommercialStudiesRepository($db), $settings, $policy), $policy),
  'base_url' => SCM_BASE_URL,
  'runtime' => ['ajaxUrl' => SCM_BASE_URL . '/api.php', 'nonce' => 'fixture', 'initialTab' => 'scm-panel-estudios', 'actions' => SCM\Commercial\CommercialCalendarService::runtimeActions(), 'config' => []],
]);
$self = 'http://127.0.0.1:8776/tests/Fixtures/commercial-studies.php';
echo str_replace(str_replace('/', '\/', SCM_BASE_URL . '/api.php'), str_replace('/', '\/', $self), $html);
