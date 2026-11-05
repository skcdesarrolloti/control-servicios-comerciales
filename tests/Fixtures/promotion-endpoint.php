<?php

declare(strict_types=1);

// Solo CLI: CommercialPromotionTest lo ejecuta en un proceso aparte porque JsonResponse termina con exit.
// Uso: php tests/Fixtures/promotion-endpoint.php <cargo> <id_empleado> <acción> <json de entrada en base64>
// (en base64 porque escapeshellarg en Windows cambia las comillas dobles por espacios).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialPromotionDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');

use Tests\Fixtures\CommercialPromotionDatabase;

$db = CommercialPromotionDatabase::create();
$settings = CommercialPromotionDatabase::settings($db);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-promotion-endpoint-' . getmypid() . '.lock';
CommercialPromotionDatabase::seed($db, $settings, date('Y-m-d'), $lock);
@unlink($lock);
CommercialPromotionDatabase::pop($db, $settings);

CommercialPromotionDatabase::login((string) ($argv[1] ?? '10'), (string) ($argv[2] ?? '101'));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$input = json_decode((string) base64_decode((string) ($argv[4] ?? ''), true), true);
$input = (is_array($input) ? $input : []) + ['nonce' => 'fixture'];
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['13']];
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
// La última línea de la salida es el código HTTP que respondió el endpoint.
register_shutdown_function(static function (): void { echo PHP_EOL . (int) http_response_code(); });
$router = new SCM\Http\Api\CommercialActionRouter($controller, static fn(): SCM\Http\Controller\PromotionApiController => new SCM\Http\Controller\PromotionApiController($db, $settings, $csrf, $config, CommercialPromotionDatabase::fakeSocialSyncers()));
if (!$router->dispatch((string) ($argv[3] ?? ''), $input)) {
  echo json_encode(['success' => false, 'data' => ['message' => 'Acción desconocida.']]);
}
