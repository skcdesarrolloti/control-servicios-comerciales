<?php

declare(strict_types=1);

// Reportes comerciales con base SQLite aislada; se recrea en cada petición. Servir el repositorio en 127.0.0.1:8769.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialNotificationsFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');

[$db] = Tests\Fixtures\CommercialNotificationsFixture::make(true);
$pdo = $db->pdo();
$pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value = null): int => $value === null ? time() : (int) strtotime((string) $value));
$pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn($value): string => date('Y-m-d H:i:s', (int) $value));
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
$pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
$pdo->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
foreach (['cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'departamento'] as $column) {
  $pdo->exec("ALTER TABLE wp_jet_cct_confi_sistema ADD COLUMN `{$column}` TEXT");
}
$pdo->exec('CREATE TABLE wp_jet_cct_reportes_comerciales (_ID INTEGER PRIMARY KEY, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, valor INTEGER, funcionario TEXT, id_empleado TEXT, id_ticket TEXT, id_inmueble TEXT, exportado TEXT, fue_pagado TEXT, cct_modified TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cuentas_cobro (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, numero_cuenta TEXT, id_empleado TEXT, fecha_creacion TEXT, fecha_inicio TEXT, fecha_fin TEXT, total_servicio TEXT, total_transporte TEXT, total_general TEXT, cantidad_reportes TEXT, archivo_pdf TEXT, reportes_incluidos TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, fue_pagada TEXT)');
foreach (['documento', 'correo_dian'] as $column) {
  try { $pdo->exec("ALTER TABLE wp_jet_cct_funcionarios ADD COLUMN `{$column}` TEXT"); } catch (\PDOException) { /* ya existe en el fixture base */ }
}
foreach ([[1, '999', 8000, 'No', 'No'], [2, '999', 4000, 'No', 'No'], [3, '900', 8000, 'No', 'No'], [4, '999', 8000, 'Si', 'No'], [5, '999', 4000, 'Si', 'Si']] as [$id, $employee, $value, $exported, $paid]) {
  $db->insert('wp_jet_cct_reportes_comerciales', ['_ID' => $id, 'fecha' => strtotime('2026-10-0' . $id . ' 12:00:00'), 'tipo_reporte' => 'Visita', 'observacion' => 'Visita <b>realizada</b>', 'valor' => $value, 'funcionario' => $employee === '999' ? 'Administrador' : 'Ana Pérez', 'id_empleado' => $employee, 'id_ticket' => '7', 'id_inmueble' => '4501', 'exportado' => $exported, 'fue_pagado' => $paid]);
}
$db->insert('wp_jet_cct_cuentas_cobro', ['_ID' => 30, 'numero_cuenta' => 'CC-000030', 'id_empleado' => '999', 'fecha_creacion' => '2026-09-01 10:00:00', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31', 'total_general' => '99000', 'cantidad_reportes' => '1', 'reportes_incluidos' => '["1"]', 'fue_pagada' => 'No pagada']);
$db->insert('wp_jet_cct_cuentas_cobro', ['_ID' => 31, 'numero_cuenta' => 'CCO-000031', 'id_empleado' => '999', 'fecha_creacion' => '2026-10-10 10:00:00', 'fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-10-04', 'total_general' => '8000', 'cantidad_reportes' => '1', 'reportes_incluidos' => '["4"]', 'fue_pagada' => 'No pagada']);

$db->insert('wp_jet_cct_cuentas_cobro', ['_ID' => 32, 'numero_cuenta' => 'CCO-000032', 'id_empleado' => '999', 'fecha_creacion' => '2026-10-20 10:00:00', 'fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-10-04', 'total_general' => '4000', 'cantidad_reportes' => '1', 'reportes_incluidos' => '["5"]', 'fue_pagada' => 'Pagada']);

$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$settings->set(SCM\Commercial\Reports\CommercialReportsService::SETTING, ['billing_enabled' => (string) ($_GET['billing'] ?? '1') === '1', 'deadline_weekday' => 6], 3);
$settings->refresh();
$config = ['dashboard_admin_cargos' => ['11'], 'calendar_allowed_cargos' => ['9', '11']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $api = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
  (new SCM\Http\Api\CommercialActionRouter($api, null, null, null, static fn() => new SCM\Http\Controller\CommercialReportsApiController($db, $settings, $csrf, $config)))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  exit;
}
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$calendar = (new SCM\Commercial\CommercialCalendarService($db, $settings, $policy, $config))->panelConfig('mine');
$sub = (string) ($_GET['sub'] ?? 'mis_reportes');
$html = SCM\Views\CommercialDashboardView::render([
  'bucket' => 'reportes_comerciales', 'report_sub' => $sub, 'subtab' => 'mine', 'policy' => $policy,
  'visible_views' => ['inicio', 'calendario', 'reportes_comerciales'],
  'calendar_employees' => $calendar['employees'], 'base_url' => SCM_BASE_URL,
  'runtime' => ['ajaxUrl' => SCM_BASE_URL . '/api.php', 'nonce' => 'fixture', 'initialTab' => 'scm-panel-reportes_comerciales', 'actions' => [], 'calendarCategoryIds' => [], 'config' => $calendar['config']],
]);
$self = 'http://127.0.0.1:8769/tests/Fixtures/commercial-reports.php' . ((string) ($_GET['billing'] ?? '1') === '1' ? '' : '?billing=0');
echo str_replace(str_replace('/', '\\/', SCM_BASE_URL . '/api.php'), str_replace('/', '\\/', $self), $html);
