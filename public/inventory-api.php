<?php

declare(strict_types=1);

// API de Material POP para formularios y sistemas externos. Ver SCM\Http\Controller\PopPublicApiController.
//   GET  inventory-api.php/products     (o ?resource=products)
//   POST inventory-api.php/deliveries   (o ?resource=deliveries)
// Se activa y se genera el token en Configuración › Material POP.
define('SCM_NO_SESSION', true);
ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

$resource = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
if ($resource === '') {
  $resource = is_string($_GET['resource'] ?? null) ? trim($_GET['resource']) : '';
}
$headers = [];
foreach (['authorization' => ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'], 'x-inventory-token' => ['HTTP_X_INVENTORY_TOKEN']] as $name => $keys) {
  foreach ($keys as $key) {
    if (is_string($_SERVER[$key] ?? null) && $_SERVER[$key] !== '') {
      $headers[$name] = $_SERVER[$key];
      break;
    }
  }
}
$service = new \SCM\Commercial\Promotion\Pop\PopInventoryService($scmDb, $scmSettings);
(new \SCM\Http\Controller\PopPublicApiController($service, $scmSettings, new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits')))->handle(
  (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
  $resource,
  $_POST,
  (string) file_get_contents('php://input'),
  (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
  $headers,
  (string) ($_SERVER['REMOTE_ADDR'] ?? '')
);
