<?php

declare(strict_types=1);

// Solo CLI: CommercialPromotionMeetingsTest lo ejecuta en un proceso aparte porque JsonResponse termina con exit.
// Uso: php tests/Fixtures/promotion-endpoint.php <cargo> <id_empleado> <acción> <json de entrada en base64>
// (en base64 porque escapeshellarg en Windows cambia las comillas dobles por espacios).
// Reunión 1: la crea 101 con 202 como participante. Reunión 2: la crea 303.
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
$policy = static fn(): SCM\Commercial\CommercialAccessPolicy => new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['13']);

CommercialPromotionDatabase::login('13', '101', 'Gerente');
$service = new SCM\Commercial\Promotion\MeetingService($db, $settings, $policy());
$first = $service->saveMeeting(['title' => 'Comité de promoción', 'meeting_date' => '2026-11-02', 'participant_ids' => ['202']]);
$service->saveItem(['meeting_id' => $first, 'section' => 'action', 'title' => 'Publicar la pieza', 'responsible_employee_id' => '202', 'due_date' => '2026-11-10']);
CommercialPromotionDatabase::login('7', '303', 'Asistente de promoción');
(new SCM\Commercial\Promotion\MeetingService($db, $settings, $policy()))->saveMeeting(['title' => 'Revisión de piezas', 'meeting_date' => '2026-11-03']);

CommercialPromotionDatabase::login((string) ($argv[1] ?? '10'), (string) ($argv[2] ?? '101'));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$input = json_decode((string) base64_decode((string) ($argv[4] ?? ''), true), true);
$input = (is_array($input) ? $input : []) + ['nonce' => 'fixture'];
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['13']]);
// La última línea de la salida es el código HTTP que respondió el endpoint.
register_shutdown_function(static function (): void { echo PHP_EOL . (int) http_response_code(); });
$router = new SCM\Http\Api\CommercialActionRouter($controller, static fn(): SCM\Http\Controller\PromotionApiController => new SCM\Http\Controller\PromotionApiController($db, $settings, $csrf, ['dashboard_admin_cargos' => ['13']]));
if (!$router->dispatch((string) ($argv[3] ?? ''), $input)) {
  echo json_encode(['success' => false, 'data' => ['message' => 'Acción desconocida.']]);
}
