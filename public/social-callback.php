<?php

declare(strict_types=1);

// Conectar YouTube o TikTok desde Promoción › Redes sociales (necesita la sesión para guardar el "state" de OAuth, por
// eso no pasa por api.php, que la cierra antes).
//   ?network=youtube&start=1&nonce=...  Lleva a la autorización de Google o TikTok.
//   ?network=youtube&code=...&state=... Vuelve de la autorización; guarda la conexión y regresa a la pantalla.
// Pide sesión, la subpestaña de Redes sociales y el permiso redes_sincronizar.
require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Social\SocialSyncRunner;

$network = is_string($_GET['network'] ?? null) ? $_GET['network'] : '';
$back = rtrim((string) SCM_BASE_URL, '/') . '/index.php?tab=redes_sociales&pr_red=' . rawurlencode(in_array($network, ['youtube', 'tiktok'], true) ? $network : 'instagram');
$finish = static function (string $type, string $message) use ($back): never {
  $_SESSION['social_flash'] = ['type' => $type, 'message' => mb_substr($message, 0, 500, 'UTF-8')];
  header('Location: ' . $back, true, 302);
  exit;
};
if (!\SCM\Core\Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php', true, 302);
  exit;
}
$adminCargos = is_array($scmConfig['dashboard_admin_cargos'] ?? null) ? $scmConfig['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
$policy = new CommercialAccessPolicy($scmSettings, $scmDb, $adminCargos);
if (!in_array($network, ['youtube', 'tiktok'], true) || !$policy->canSubview('promocion', 'redes_sociales') || !$policy->canAct('redes_sincronizar')) {
  $finish('error', 'No tienes permiso para conectar esta red.');
}
$runner = new SocialSyncRunner($scmDb, $scmConfig, SCM_STORAGE_PATH . '/cache/social-sync.lock');
$label = $network === 'youtube' ? 'YouTube' : 'TikTok';
try {
  if (!empty($_GET['start'])) {
    if (!$scmCsrf->verify('commercial_nonce', is_string($_GET['nonce'] ?? null) ? $_GET['nonce'] : '', false)) {
      $finish('error', 'Verificación de seguridad fallida. Recarga la página.');
    }
    header('Location: ' . $runner->connectionUrl($network), true, 302);
    exit;
  }
  $denied = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
  if ($denied !== '') {
    $finish('error', $label . ' no autorizó la conexión: ' . $denied);
  }
  $name = $runner->completeConnection($network, trim((string) ($_GET['code'] ?? '')), trim((string) ($_GET['state'] ?? '')));
  $finish('success', 'Cuenta conectada: ' . $name . '. Ya puedes sincronizar.');
} catch (\RuntimeException $exception) {
  $finish('error', $exception->getMessage());
}
