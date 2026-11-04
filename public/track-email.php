<?php

declare(strict_types=1);

// Píxel de apertura de los correos de campaña (Notificaciones › Campañas). Ver SCM\Commercial\Campaigns\EmailOpenTracker.
// Siempre responde el GIF, aunque el token no exista o la base falle: el correo no debe mostrar una imagen rota.
define('SCM_NO_SESSION', true);
ob_start();
try {
  require_once dirname(__DIR__) . '/bootstrap/app.php';
  $token = is_string($_GET['t'] ?? null) ? strtolower(trim($_GET['t'])) : '';
  (new \SCM\Commercial\Campaigns\EmailOpenTracker($scmDb))->record($token, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
} catch (\Throwable $exception) {
  error_log('[campaign-email-open] ' . $exception->getMessage());
}
while (ob_get_level() > 0) {
  ob_end_clean();
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
// El mismo GIF de EmailOpenTracker::PIXEL, escrito aquí por si el bootstrap falló.
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
