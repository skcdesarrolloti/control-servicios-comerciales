<?php

declare(strict_types=1);

// Calendario comercial con base SQLite aislada. Servir el repositorio en 127.0.0.1:8769;
// la API del calendario de actividades se simula desde el navegador (tools/check-commercial-calendar.cjs).
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialNotificationsFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');

[$db] = Tests\Fixtures\CommercialNotificationsFixture::make(true);
$pdo = $db->pdo();
// Funciones MySQL que usan los conteos del tablero.
$pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value = null): int => $value === null ? time() : (int) strtotime((string) $value));
$pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn($value): string => date('Y-m-d H:i:s', (int) $value));
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
$pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
$pdo->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
foreach (['cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'departamento'] as $column) {
  $pdo->exec("ALTER TABLE wp_jet_cct_confi_sistema ADD COLUMN `{$column}` TEXT");
}
$propertyColumns = ['estado', 'codigo', 'tipo_negocio', 'tipo_inmueble', 'barrio', 'direccion', 'ciudad', 'ruta', 'destinacion', 'fecha_actualizacion', 'fecha_publicacion', 'cct_created', 'cct_modified', 'desea_aviso', 'presenta_aviso', 'fecha_retoque', 'fecha_aviso', 'fecha_captacion'];
foreach ($propertyColumns as $column) {
  $pdo->exec("ALTER TABLE wp_jet_cct_inmuebles ADD COLUMN `{$column}` TEXT");
  $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', 'wp_jet_cct_inmuebles', $column]);
}
$pdo->exec("UPDATE wp_jet_cct_inmuebles SET estado = 'Publico', codigo = '4501', tipo_negocio = 'Arriendo', tipo_inmueble = 'Apartamento', barrio = 'Manga', direccion = 'Calle 25 #20-10', fecha_actualizacion = '" . date('Y-m-d', strtotime('-85 days')) . "', desea_aviso = 'Si', presenta_aviso = 'Si', fecha_retoque = '" . date('Y-m-d', strtotime('-40 days')) . "'");
$tickets = 'wp_jet_cct_tickets';
$pdo->exec("CREATE TABLE `{$tickets}` (_ID INTEGER PRIMARY KEY, id_ticket TEXT, estado TEXT, estado_comercial TEXT, estado_administrativo TEXT, asunto TEXT, descripcion TEXT,
  solicitante TEXT, correo_solicitante TEXT, celular_solicitante TEXT, id_empleado TEXT, nombre_empleado TEXT, correo_empleado TEXT, celular_empleado TEXT,
  inmueble TEXT, id_inmueble TEXT, direccion TEXT, barrio TEXT, tipo_inmueble TEXT, medio TEXT, prioridad TEXT, tema_ayuda TEXT, tuvo_seguimiento TEXT,
  fecha_seguimiento INTEGER, fecha INTEGER, fecha_actualizacion INTEGER, cct_created TEXT, cct_modified TEXT)");
$pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $tickets]);
foreach ($pdo->query("PRAGMA table_info(`{$tickets}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
  $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $tickets, $column['name']]);
}
$old = date('Y-m-d H:i:s', strtotime('-20 days'));
$recent = date('Y-m-d H:i:s', strtotime('-1 day'));
$followUp = strtotime(date('Y-m-d 15:00:00', strtotime('+2 days')));
$db->insert($tickets, ['_ID' => 1, 'id_ticket' => '1', 'estado' => 'En proceso', 'estado_comercial' => 'Contactado', 'estado_administrativo' => 'Sin novedad', 'asunto' => 'Captar apartamento en Manga', 'solicitante' => 'Laura Gómez', 'celular_solicitante' => '3001112233', 'id_empleado' => '999', 'nombre_empleado' => 'Administrador', 'direccion' => 'Calle 25 #20-10', 'barrio' => 'Manga', 'tema_ayuda' => 'Captacion', 'fecha_seguimiento' => $followUp, 'fecha' => strtotime($old), 'cct_created' => $old, 'cct_modified' => $old]);
$db->insert($tickets, ['_ID' => 2, 'id_ticket' => '2', 'estado' => 'Nuevo', 'estado_comercial' => 'Nuevo', 'asunto' => 'Avalúo casa Crespo', 'solicitante' => 'Pedro Ruiz', 'id_empleado' => '900', 'nombre_empleado' => 'Ana Pérez', 'tema_ayuda' => 'Avaluo', 'fecha' => strtotime($recent), 'cct_created' => $recent, 'cct_modified' => $recent]);

$settings = new SCM\Core\Settings($db, false, 'control_servicios_comerciales_config');
$csrf = new SCM\Core\Csrf(str_repeat('x', 40));
$_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
SCM\Core\App::init($db, new SCM\Core\Auth($db), $csrf, $settings);
$config = ['dashboard_admin_cargos' => ['11'], 'calendar_allowed_cargos' => ['9', '11']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $api = new SCM\Http\Controller\CommercialApiController($db, $settings, $csrf, $config);
  (new SCM\Http\Api\CommercialActionRouter($api))->dispatch((string) ($_POST['action'] ?? ''), $_POST);
  exit;
}
// Solo el calendario: el resto del tablero necesita el esquema completo de MySQL.
$tab = (string) ($_GET['tab'] ?? 'calendario');
$subtab = (string) ($_GET['subtab'] ?? 'mine');
$policy = new SCM\Commercial\CommercialAccessPolicy($settings, $db, ['11']);
$calendarService = new SCM\Commercial\CommercialCalendarService($db, $settings, $policy, $config);
$calendar = $calendarService->panelConfig($subtab);
$html = SCM\Views\CommercialDashboardView::render([
  'bucket' => $tab === 'calendario' ? 'calendario' : 'sin_acceso',
  'subtab' => $subtab,
  'policy' => $policy,
  'visible_views' => ['inicio', 'calendario', 'abiertos', 'mis_tickets'],
  'calendar_employees' => $calendar['employees'],
  'base_url' => SCM_BASE_URL,
  'runtime' => [
    'ajaxUrl' => SCM_BASE_URL . '/api.php',
    'nonce' => 'fixture',
    'initialTab' => 'scm-panel-actividades-administrativas',
    'actions' => SCM\Commercial\CommercialCalendarService::runtimeActions(),
    'calendarCategoryIds' => $calendarService->categoryIds(),
    'config' => array_merge($calendar['config'], ['commercial_statuses' => SCM\Commercial\CommercialStatusCatalog::all()]),
  ],
]);
if ($tab !== 'calendario') {
  // Tarjeta de agenda de una tarea, tal como la muestra el modal de la tarea.
  $detail = ['ticket' => $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 1'), 'timeline' => [], 'analyses' => []];
  $html = str_replace(SCM\Views\CommercialDashboardView::renderAccessDenied(SCM_BASE_URL), '<section class="p-8" data-fixture-task>' . SCM\Views\CommercialTicketModalView::render($detail, $policy, []) . '</section>', $html);
}
$self = 'http://127.0.0.1:8769/tests/Fixtures/commercial-calendar.php';
echo str_replace([str_replace('/', '\\/', SCM_BASE_URL . '/api.php'), SCM_BASE_URL . '/index.php'], [str_replace('/', '\\/', $self), $self], $html);
