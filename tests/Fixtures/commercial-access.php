<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialNotificationsFixture.php';
require __DIR__ . '/CommercialAccessFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
[$db, $settings, $csrf] = Tests\Fixtures\CommercialAccessFixture::make();
$config = ['dashboard_admin_cargos' => ['11']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $api = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
  (new SCM\Http\Api\CommercialActionRouter($api))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  exit;
}
if (($_GET['tab'] ?? 'inicio') === 'inicio') {
  // Inicio de prueba sin consultas ni contactos reales; mantiene la navegación del dashboard.
  $policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
  $html = SCM\Views\CommercialDashboardView::render([
    'bucket' => 'sin_acceso', 'policy' => $policy, 'visible_views' => ['inicio', 'inmuebles', 'mis_tickets'],
    'base_url' => SCM_BASE_URL, 'runtime' => ['ajaxUrl' => 'http://127.0.0.1:8769/tests/Fixtures/commercial-access.php', 'nonce' => 'fixture'],
  ]);
  $html = str_replace(SCM\Views\CommercialDashboardView::renderAccessDenied(SCM_BASE_URL), '<section class="p-8" data-fixture-home><h2>Inicio de prueba</h2></section>', $html);
} else {
  $html = (new SCM\Controllers\CommercialDashboardController($db, $settings, $csrf, $config))->render($_GET);
}
echo str_replace([str_replace('/', '\\/', SCM_BASE_URL . '/api.php'), SCM_BASE_URL . '/index.php'], [str_replace('/', '\\/', 'http://127.0.0.1:8769/tests/Fixtures/commercial-access.php'), 'http://127.0.0.1:8769/tests/Fixtures/commercial-access.php'], $html);
