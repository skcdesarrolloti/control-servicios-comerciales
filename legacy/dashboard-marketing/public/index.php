<?php

declare(strict_types=1);

// Reemplazo de dashboard-marketing ya apagado. Al apagar ese panel, deja en su carpeta del servidor SOLO el contenido de
// legacy/dashboard-marketing (.htaccess, index.php, public/index.php y public/assets/tracker.js):
// - el sitio web que todavía cargue .../dashboard-marketing/public/assets/tracker.js sigue enviando visitas (las recibe
//   control-servicios-comerciales/public/tracker-hit.php), también con data-endpoint=/dashboard-marketing/?action=...;
// - los correos de campañas ya enviados siguen contando aperturas (?action=track-email&token=...), incluida su dirección
//   /dashboard-marketing/dashboard-marketing/ (la resuelve el .htaccess);
// - cualquier otra dirección lleva al panel comercial.
// assets/tracker.js es una copia exacta de control-servicios-comerciales/public/assets/js/site-tracker.js.
$panel = dirname(__DIR__, 2) . '/control-servicios-comerciales/public';
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
if ($action === 'gda-register-hit') {
  require $panel . '/tracker-hit.php';
  exit;
}
if ($action === 'track-email') {
  $_GET['t'] = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
  require $panel . '/track-email.php';
  exit;
}
header('Location: /control-servicios-comerciales/public/index.php', true, 301);
