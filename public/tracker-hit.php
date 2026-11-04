<?php

declare(strict_types=1);

// Visitas del sitio web para la Analítica web (lo llama assets/js/site-tracker.js). Reemplaza
// dashboard-marketing/public/index.php?action=gda-register-hit. Ver SCM\Commercial\Promotion\Analytics\TrackerHitRecorder.
define('SCM_NO_SESSION', true);
ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

$respond = static function (array $payload, int $status): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
};

$recorder = new \SCM\Commercial\Promotion\Analytics\TrackerHitRecorder($scmDb, $scmSettings, SCM_STORAGE_PATH . '/cache/tracker-geo.json');
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if (!$recorder->allowsOrigin($origin)) {
  $respond(['ok' => false, 'error' => 'Origen no permitido'], 403);
}
if ($origin !== '') {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}
if ($method !== 'POST') {
  $respond(['ok' => false, 'error' => 'Método no permitido'], 405);
}
// Una visita normal manda uno o dos registros por página: 300 cada 10 minutos por IP frena el abuso sin tocar a nadie.
$limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
if (!$limiter->consume('tracker-hit|' . \SCM\Commercial\Promotion\Analytics\TrackerHitRecorder::clientIp($_SERVER), 300, 600)) {
  $respond(['ok' => false, 'error' => 'Demasiadas solicitudes'], 429);
}
$payload = $_POST;
if ($payload === []) {
  $decoded = json_decode((string) file_get_contents('php://input'), true);
  $payload = is_array($decoded) ? $decoded : [];
}
try {
  $respond(['ok' => true, 'id' => $recorder->record($payload, $_SERVER)], 200);
} catch (\Throwable $exception) {
  error_log('[tracker-hit] ' . $exception->getMessage());
  $respond(['ok' => false, 'error' => 'No se pudo registrar la visita'], 500);
}
