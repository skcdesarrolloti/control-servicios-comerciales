<?php

declare(strict_types=1);

// Solo CLI: CommercialStrategicPlanTest lo ejecuta en un proceso aparte porque JsonResponse termina con exit.
// Uso: php tests/Fixtures/strategy-endpoint.php <cargo> <id_empleado> <nombre> <acción> <json de entrada en base64>
// La salida trae la respuesta, una línea con el estado de la base (JSON) y al final el código HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/StrategicPlanDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');

use Tests\Fixtures\StrategicPlanDatabase;

$db = StrategicPlanDatabase::create();
$settings = StrategicPlanDatabase::settings($db);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
StrategicPlanDatabase::seed($db);

StrategicPlanDatabase::login((string) ($argv[1] ?? '9'), (string) ($argv[2] ?? '101'), (string) ($argv[3] ?? 'Funcionario de prueba'));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$input = json_decode((string) base64_decode((string) ($argv[5] ?? ''), true), true);
$input = (is_array($input) ? $input : []) + ['nonce' => 'fixture'];
$config = ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['13']];
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
register_shutdown_function(static function () use ($db): void {
  echo PHP_EOL . json_encode([
    'tareas' => $db->getResults('SELECT _ID, estado, historial_avances, cct_author_id FROM wp_jet_cct_tareas ORDER BY _ID'),
    'campanas' => $db->getResults('SELECT _ID, informes FROM wp_jet_cct_campanas ORDER BY _ID'),
    'metas' => (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_metas'),
  ], JSON_UNESCAPED_UNICODE);
  echo PHP_EOL . (int) http_response_code();
});
$router = new SCM\Http\Api\CommercialActionRouter(
  $controller,
  null,
  static fn(): SCM\Http\Controller\StrategicPlanApiController => new SCM\Http\Controller\StrategicPlanApiController($db, $settings, $csrf, $config)
);
if (!$router->dispatch((string) ($argv[4] ?? ''), $input)) {
  echo json_encode(['success' => false, 'data' => ['message' => 'Acción desconocida.']]);
}
