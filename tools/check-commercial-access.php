<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';
require dirname(__DIR__) . '/src/Core/Helpers.php';
require dirname(__DIR__) . '/tests/Fixtures/CommercialNotificationsFixture.php';
require dirname(__DIR__) . '/tests/Fixtures/CommercialAccessFixture.php';
define('SCM_BASE_URL', 'http://127.0.0.1:8769/public');
define('SCM_VERSION', 'test');
define('SCM_PUBLIC_PATH', dirname(__DIR__) . '/public');
[$db, $settings, $csrf] = Tests\Fixtures\CommercialAccessFixture::make();
$controller = new SCM\Controllers\CommercialDashboardController($db, $settings, $csrf, ['dashboard_admin_cargos' => ['11']]);
foreach (['notificaciones', 'calendario', 'actualizaciones', 'avisos', 'abiertos', 'postergados', 'cerrados'] as $tab) {
  http_response_code(200);
  // No hay tabla de tareas en esta base: el bloqueo debe ocurrir antes de leerlas.
  $html = $controller->render(['tab' => $tab, 'ticket_id' => '999', 'id_empleado' => '901']);
  if (http_response_code() !== 403 || !str_contains($html, 'No tienes permiso para entrar a esta página') || !str_contains($html, 'data-commercial-denied-home href="' . SCM_BASE_URL . '/index.php"')) {
    throw new RuntimeException('La URL restringida no muestra el acceso denegado con salida al inicio: ' . $tab);
  }
  if (str_contains($html, 'data-commercial-notifications') || str_contains($html, 'Propietario 10') || str_contains($html, 'data-scm-calendar-panel')) {
    throw new RuntimeException('La página restringida contiene datos del módulo: ' . $tab);
  }
}
echo "Acceso comercial: URLs restringidas con HTTP 403, botón al inicio y bloqueo antes de consultar datos: OK\n";
