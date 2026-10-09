<?php

declare(strict_types=1);

// Vista local con datos ficticios. No requiere credenciales ni genera envíos reales.
require dirname(__DIR__) . '/tests/bootstrap.php';
require dirname(__DIR__) . '/src/Core/Helpers.php';
require dirname(__DIR__) . '/tests/Fixtures/CommercialNotificationsFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'preview');
define('SCM_UPLOAD_MAX_BYTES', 10485760);
define('SCM_PUBLIC_PATH', dirname(__DIR__) . '/public');

$audit = in_array('--audit', $argv, true);
$admin = in_array('--admin', $argv, true);
$shell = in_array('--shell', $argv, true);
[$db, $policy, $service] = $audit ? Tests\Fixtures\CommercialNotificationsFixture::makeWithAudit($admin) : Tests\Fixtures\CommercialNotificationsFixture::make();
$output = dirname(__DIR__) . '/output';
if (!is_dir($output)) { mkdir($output, 0750, true); }
$nav = SCM\Views\CommercialDashboardView::renderTabs(array_keys(SCM\Commercial\CommercialAccessPolicy::VIEWS), 'notificaciones', [], [], SCM_BASE_URL, [], $policy);
$apiUrl = '/tests/Fixtures/notifications-api.php' . ($audit ? '?audit=' . ($admin ? 'admin' : 'user') : '');
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../public/assets/css/tailwind.css"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet"><title>Notificaciones comerciales · Vista de prueba</title></head><body class="bg-background font-body-md text-on-surface"><div id="scm-app" data-scm-runtime="' . esc_attr(json_encode(['ajaxUrl' => $apiUrl, 'nonce' => 'fixture'])) . '"><header class="bg-inverse-surface px-8 py-4 text-white font-semibold">SKC SuCasa Inmobiliaria · Servicios Comerciales</header><div class="bg-white px-8 py-3">' . $nav . '</div>' . SCM\Views\CommercialNotificationsView::render($service, $policy) . '</div><script src="../public/assets/js/commercial-notifications.js"></script></body></html>';
$name = $audit ? 'notifications-audit-' . ($admin ? 'admin' : 'user') . '.html' : 'notifications-preview.html';
if ($shell) {
  $db->pdo()->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', $parts));
  SCM\Core\App::init($db, new SCM\Core\Auth($db), new SCM\Core\Csrf(str_repeat('x', 40)), new SCM\Core\Settings($db, true, 'control_servicios_comerciales_config'));
  $html = SCM\Views\CommercialDashboardView::render(['bucket' => 'notificaciones', 'notifications_service' => $service,
    'policy' => $policy, 'base_url' => SCM_BASE_URL, 'visible_views' => array_keys(SCM\Commercial\CommercialAccessPolicy::VIEWS),
    'runtime' => ['ajaxUrl' => $apiUrl, 'nonce' => 'fixture']]);
  $name = str_replace('.html', '-shell.html', $name);
}
file_put_contents($output . '/' . $name, $html);
echo $output . '/' . $name . PHP_EOL;
