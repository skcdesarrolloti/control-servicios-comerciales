<?php

declare(strict_types=1);

// Solo CLI: CommercialCampaignsTest lo ejecuta en un proceso aparte porque JsonResponse termina con exit.
// Uso: php tests/Fixtures/campaign-endpoint.php <1 admin | 0 sin permisos> <acción> <json de entrada en base64>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CampaignFixture.php';
define('SCM_BASE_URL', 'https://panel.test/public');

use Tests\Fixtures\CampaignFixture;

[$db] = CampaignFixture::make(($argv[1] ?? '0') === '1');
$settings = new SCM\Core\Settings($db, true, 'control_servicios_comerciales_config');
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$input = json_decode((string) base64_decode((string) ($argv[3] ?? ''), true), true);
$input = (is_array($input) ? $input : []) + ['nonce' => 'fixture'];
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['11']];
register_shutdown_function(static function (): void { echo PHP_EOL . (int) http_response_code(); });
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable(CampaignFixture::NOW, new DateTimeZone('America/Bogota'));
$router = new SCM\Http\Api\CommercialActionRouter($controller, null, null, static fn(): SCM\Http\Controller\CampaignApiController => new SCM\Http\Controller\CampaignApiController($db, $settings, $csrf, $config, $clock));
if (!$router->dispatch((string) ($argv[2] ?? ''), $input)) {
  echo json_encode(['success' => false, 'data' => ['message' => 'Acción desconocida.']]);
}
