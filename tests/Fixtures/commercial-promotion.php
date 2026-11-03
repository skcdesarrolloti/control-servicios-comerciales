<?php

declare(strict_types=1);

// Fixture local de Promoción › Reuniones para tools/check-commercial-promotion.cjs: php -S 127.0.0.1:8773 -t .
// ?cargo=13 acceso total (id_empleado 101), 7 gestiona solo lo suyo (303), 8 solo consulta (202), 10 sin configurar.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialPromotionDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8773/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');
define('SCM_APP_SECRET', str_repeat('x', 40));

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Views\CommercialDashboardView;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

session_name('commercial_promotion_fixture');
session_start();
$employees = ['13' => ['101', 'Gerente de prueba'], '7' => ['303', 'Asistente de promoción'], '8' => ['202', 'Segundo consultor'], '10' => ['909', 'Sin configurar']];
if (isset($_GET['cargo']) || !isset($_SESSION['scm_user_cargo'])) {
  $cargo = preg_replace('/\D/', '', (string) ($_GET['cargo'] ?? '13'));
  [$employee, $name] = $employees[$cargo] ?? ['909', 'Funcionario'];
  CommercialPromotionDatabase::login($cargo, $employee, $name);
}
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';

$db = CommercialPromotionDatabase::create('sqlite:' . dirname(__DIR__, 2) . '/output/commercial-promotion.sqlite');
$settings = CommercialPromotionDatabase::settings($db);
$csrf = new SCM\Core\Csrf(SCM_APP_SECRET);
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$policy = new CommercialAccessPolicy($settings, $db, ['13']);
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['13']];
$views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    // Igual que CommercialApiController::filterTickets para la pestaña Promoción.
    $input = CommercialAccessPolicy::normalizeToolTab($_POST);
    $bucket = (string) ($input['tab'] ?? '');
    try {
      $input = $policy->resolveNavigation($bucket, $input);
    } catch (RuntimeException $exception) {
      SCM\Http\Response\JsonResponse::error($exception->getMessage(), 403, ['page_denied' => true, 'html' => CommercialDashboardView::renderAccessDenied(SCM_BASE_URL)]);
    }
    if ($bucket !== 'promocion' || !$policy->canView($bucket)) {
      SCM\Http\Response\JsonResponse::error('No tienes permiso para entrar a esta página.', 403, ['page_denied' => true, 'html' => CommercialDashboardView::renderAccessDenied(SCM_BASE_URL)]);
    }
    SCM\Http\Response\JsonResponse::success([
      'html' => PromotionView::page($input, $policy, $db, $settings, SCM_BASE_URL),
      'tabs_html' => CommercialDashboardView::renderTabs($views, $bucket, [], [], SCM_BASE_URL, [], $policy),
      'tab' => $bucket,
    ]);
  }
  $controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
  $router = new SCM\Http\Api\CommercialActionRouter($controller, static fn(): SCM\Http\Controller\PromotionApiController => new SCM\Http\Controller\PromotionApiController($db, $settings, $csrf, $config));
  if (!$router->dispatch((string) ($_POST['action'] ?? ''), $_POST)) {
    SCM\Http\Response\JsonResponse::error('Acción desconocida.', 400);
  }
  exit;
}

// ?tab=espera abre el panel sin una pestaña cargada para probar la llegada de Promoción por AJAX.
$input = CommercialAccessPolicy::normalizeToolTab($_GET + ['tab' => 'reuniones']);
$bucket = (string) $input['tab'];
$html = '';
if ($bucket === 'promocion' && $policy->canView('promocion')) {
  $input = $policy->resolveNavigation('promocion', $input);
  $html = PromotionView::page($input, $policy, $db, $settings, SCM_BASE_URL);
} else {
  $bucket = 'sin_acceso';
}
echo CommercialDashboardView::render([
  'bucket' => $bucket, 'policy' => $policy, 'visible_views' => $views, 'promotion_html' => $html, 'base_url' => SCM_BASE_URL,
  'runtime' => ['ajaxUrl' => 'http://127.0.0.1:8773/tests/Fixtures/commercial-promotion.php', 'nonce' => 'fixture', 'initialTab' => 'scm-panel-' . $bucket],
]);
