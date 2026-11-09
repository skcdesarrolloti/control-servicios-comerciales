<?php

declare(strict_types=1);

// API del calendario comercial (reemplaza calendario-actividades): cuerpo JSON, respuesta {success,message,data}.

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

use SCM\Commercial\Calendar\CommercialCalendarApi;
use SCM\Commercial\CommercialAccessPolicy;

$adminCargos = is_array($scmConfig['dashboard_admin_cargos'] ?? null) ? $scmConfig['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
$api = new CommercialCalendarApi($scmDb, $scmSettings, new CommercialAccessPolicy($scmSettings, $scmDb, $adminCargos), $scmConfig);
$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? '')) ?? '';

$respond = static function (array $payload): void {
  $status = (int) ($payload['status'] ?? 200);
  unset($payload['status']);
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
};
$failure = static fn(string $message, int $status): array => ['success' => false, 'message' => $message, 'data' => [], 'status' => $status];

// Google vuelve aquí en la ventana emergente; el `state` de un solo uso reemplaza a la sesión.
if ($action === 'google_oauth_callback') {
  $page = static function (string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Google Calendar</title></head><body style="font-family:Arial,sans-serif;padding:32px;color:#0f172a">' . $body . '</body></html>';
    exit;
  };
  try {
    $code = trim((string) ($_GET['code'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    if ($code === '' || $state === '') {
      throw new \RuntimeException('Google no devolvió el código de autorización.');
    }
    $result = $api->completeGoogle($code, $state);
    $query = [];
    parse_str((string) parse_url($result['redirect'], PHP_URL_QUERY), $query);
    $parts = parse_url($result['redirect']);
    if (($query['scm_google_popup'] ?? '') === '1' && is_array($parts) && !empty($parts['host'])) {
      $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
      $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
      $page('<p>Google Calendar conectado. Puedes cerrar esta ventana.</p><script>(function(){try{if(window.opener&&!window.opener.closed){window.opener.postMessage(' . json_encode(['type' => 'scm-google-calendar-connected', 'id_empleado' => $result['employee']], $flags) . ',' . json_encode($origin, $flags) . ');window.setTimeout(function(){window.close()},100)}}catch(e){}})();</script>');
    }
    $page('<h1>Google conectado</h1><p>La cuenta quedó conectada al calendario de SKC SuCasa Inmobiliaria. Puedes cerrar esta ventana.</p>');
  } catch (\Throwable $exception) {
    error_log('[commercial-calendar-google] callback: ' . $exception->getMessage());
    $page('<h1>No se pudo conectar Google</h1><p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>');
  }
}

if (!\SCM\Core\Auth::isLoggedIn()) {
  $respond($failure('Tu sesión terminó. Vuelve a iniciar sesión.', 401));
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method === 'GET' && $action !== 'listar_categorias') {
  $respond($failure('Método no permitido.', 405));
}
if ($method === 'POST' && !$scmCsrf->verify('commercial_nonce', (string) ($_SERVER['HTTP_X_SCM_NONCE'] ?? ''), false)) {
  $respond($failure('Verificación de seguridad fallida. Recarga la página.', 403));
}
if (!in_array($method, ['GET', 'POST'], true)) {
  $respond($failure('Método no permitido.', 405));
}
$input = json_decode((string) file_get_contents('php://input'), true);
session_write_close();
set_time_limit(60);

try {
  $respond($api->handle($action, is_array($input) ? $input : []));
} catch (\Throwable $exception) {
  $reference = bin2hex(random_bytes(6));
  error_log(sprintf('[commercial-calendar:%s] %s in %s:%d', $reference, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
  $respond(['success' => false, 'message' => 'No se pudo completar la petición del calendario. Referencia: ' . $reference, 'data' => ['error_code' => 'calendar_api_failed', 'error_id' => $reference], 'status' => 500]);
}
