<?php

declare(strict_types=1);

// Vista local con datos ficticios. No requiere credenciales ni genera envíos reales.
require dirname(__DIR__) . '/tests/bootstrap.php';
require dirname(__DIR__) . '/src/Core/Helpers.php';
require dirname(__DIR__) . '/tests/Fixtures/CommercialNotificationsFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'preview');
define('SCM_UPLOAD_MAX_BYTES', 10485760);

[$db, $policy, $service] = Tests\Fixtures\CommercialNotificationsFixture::make();
$output = dirname(__DIR__) . '/output';
if (!is_dir($output)) { mkdir($output, 0750, true); }
$nav = SCM\Views\CommercialDashboardView::renderTabs(array_keys(SCM\Commercial\CommercialAccessPolicy::VIEWS), 'notificaciones', [], [], SCM_BASE_URL, [], $policy);
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../public/assets/css/tailwind.css"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet"><title>Notificaciones comerciales · Vista de prueba</title></head><body class="bg-background font-body-md text-on-surface"><div id="scm-app" data-scm-runtime="' . esc_attr(json_encode(['ajaxUrl' => '/tests/Fixtures/notifications-api.php', 'nonce' => 'fixture'])) . '"><header class="bg-inverse-surface px-8 py-4 text-white font-semibold">SKC SuCasa Inmobiliaria · Servicios Comerciales</header><div class="bg-white px-8 py-3">' . $nav . '</div>' . SCM\Views\CommercialNotificationsView::render($service, $policy) . '</div><script src="../public/assets/js/commercial-notifications.js"></script></body></html>';
file_put_contents($output . '/notifications-preview.html', $html);
echo $output . '/notifications-preview.html' . PHP_EOL;
