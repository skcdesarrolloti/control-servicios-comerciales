<?php

declare(strict_types=1);

// Solo CLI: CommercialUtmGeneratorTest lo ejecuta en un proceso aparte porque JsonResponse termina con exit.
// Uso: php tests/Fixtures/utm-endpoint.php <cargo> [sin_tracker]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialOwnerContractsDatabase.php';
define('SCM_BASE_URL', 'http://127.0.0.1/public');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
define('SCM_VERSION', 'fixture');
define('SCM_APP_SECRET', str_repeat('x', 40));

$_SESSION = ['scm_user_id' => 1, 'scm_employee_id' => '101', 'scm_user_cargo' => (string) ($argv[1] ?? '9'), 'scm_user' => 'Funcionario de prueba', 'scm_logged_in' => true];
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
$db = Tests\Fixtures\CommercialOwnerContractsDatabase::create();
if (($argv[2] ?? '') !== 'sin_tracker') {
  $db->pdo()->exec('CREATE TABLE wp_jet_cct_tracker_logs (_ID INTEGER PRIMARY KEY AUTOINCREMENT, camp_slug TEXT, utm_source TEXT, utm_medium TEXT, ip_address TEXT, url TEXT)');
  $db->pdo()->exec('CREATE TABLE wp_jet_cct_tracker_camps (_ID INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, nombre TEXT)');
  foreach ([['feria_vivienda', 'Facebook', 'Redes Sociales'], ['general', 'Directo', 'Navegador'], ['event_click', 'Google', 'cpc'], ['Revista Esencia', 'instagram', 'whatsapp'], ['inmueble_view', 'Inmuebles', 'Pagina Web'], ['General', 'Revista Impresa', 'Volante']] as [$camp, $source, $medium]) {
    $db->insert('wp_jet_cct_tracker_logs', ['camp_slug' => $camp, 'utm_source' => $source, 'utm_medium' => $medium, 'ip_address' => '10.0.0.1', 'url' => 'https://x.test/?secreto=1']);
  }
  $db->insert('wp_jet_cct_tracker_camps', ['slug' => 'feria_vivienda', 'nombre' => 'Feria de vivienda']);
}
$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
// El cargo 8 ya tenía sus herramientas guardadas sin el generador; el 9 no está configurado (recibe todas).
$settings->set('commercial_permissions', ['8' => ['views' => ['herramientas'], 'subviews' => ['herramientas' => ['simulador_ingreso_neto']], 'actions' => []]]);
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$controller = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, ['base_url' => SCM_BASE_URL, 'dashboard_admin_cargos' => ['11']]);
(new SCM\Http\Api\CommercialActionRouter($controller))->dispatch('commercial_tools_utm_options', ['nonce' => 'fixture']);
