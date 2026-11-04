<?php

declare(strict_types=1);

// Fixture local de Notificaciones › Campañas y Plantillas de correo para tools/check-commercial-campaigns.cjs:
//   php -S 127.0.0.1:8774 -t .
// La base se arma en memoria en cada petición (CampaignFixture) con una campaña ya enviada, así que lo que se envía
// desde el navegador se valida y responde pero no queda guardado. ?cargo=11 acceso total (999), 9 sin permisos (900).
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CampaignFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8774/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Views\Campaigns\CampaignsView;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CampaignFixture;

session_name('commercial_campaigns_fixture');
session_start();
if (isset($_GET['cargo'])) {
  $_SESSION['fixture_admin'] = ($_GET['cargo'] ?? '11') === '11';
}
$admin = (bool) ($_SESSION['fixture_admin'] ?? true);
$fixtureSession = $_SESSION;
[$db, $policy, $engine] = CampaignFixture::make($admin);
// CommercialNotificationsFixture::make reemplaza la sesión con el usuario del cargo; se conservan las demás claves.
$_SESSION = $_SESSION + $fixtureSession;
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$engine->send($engine->spec([
  'name' => 'Revista octubre', 'channel' => 'email', 'category' => 'info', 'subject' => 'Revista para {{nombre}}', 'message' => 'Novedades del mes.',
  'segments' => json_encode([['type' => 'clientes', 'q' => '']]), 'batch_size' => '200', 'batch_interval_minutes' => '15', 'max_per_day' => '1000',
]), '999', 'Administrador');
$db->update('skc_notification_queue', ['status' => 'sent'], ['gda_id_actor' => 100]);
$db->update('skc_notification_queue', ['status' => 'failed', 'last_error' => 'Buzón lleno'], ['gda_id_actor' => 105]);
$settings = new SCM\Core\Settings($db, true, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['11']];
$views = array_values(array_filter(array_keys(CommercialAccessPolicy::VIEWS), static fn(string $view): bool => $policy->canView($view)));
$self = 'http://127.0.0.1:8774/tests/Fixtures/commercial-campaigns.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (($_POST['action'] ?? '') === 'commercial_tickets_filter') {
    $input = CommercialAccessPolicy::normalizeToolTab($_POST);
    $input = $policy->resolveNavigation('notificaciones', $input);
    SCM\Http\Response\JsonResponse::success([
      'html' => CampaignsView::page((string) ($input['notif_sub'] ?? ''), $input, $policy, $db, $settings, SCM_BASE_URL),
      'tabs_html' => CommercialDashboardView::renderTabs($views, 'notificaciones', [], [], SCM_BASE_URL, [], $policy),
      'tab' => 'notificaciones',
    ]);
  }
  $clock = static fn(): DateTimeImmutable => new DateTimeImmutable(CampaignFixture::NOW, new DateTimeZone('America/Bogota'));
  $router = new SCM\Http\Api\CommercialActionRouter(new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config), null, null,
    static fn(): SCM\Http\Controller\CampaignApiController => new SCM\Http\Controller\CampaignApiController($db, $settings, $csrf, $config, $clock));
  if (!$router->dispatch((string) ($_POST['action'] ?? ''), $_POST)) {
    SCM\Http\Response\JsonResponse::error('Acción desconocida.', 400);
  }
  exit;
}

$input = $policy->resolveNavigation('notificaciones', CommercialAccessPolicy::normalizeToolTab($_GET + ['tab' => 'campanas']));
echo CommercialDashboardView::render([
  'bucket' => 'notificaciones', 'policy' => $policy, 'visible_views' => $views, 'base_url' => SCM_BASE_URL,
  'campaigns_html' => CampaignsView::page((string) ($input['notif_sub'] ?? ''), $input, $policy, $db, $settings, SCM_BASE_URL),
  'runtime' => ['ajaxUrl' => $self, 'nonce' => 'fixture', 'initialTab' => 'scm-panel-notificaciones'],
]);
