<?php

declare(strict_types=1);

// Hoja de cierre del mandato (?mandato=ID) o una hoja por su número (?numero=ID, Hojas de cierre y Mis cierres): la abre la ventana de Mandatos, Mis mandatos y el popup de la tarea. Revisa
// que la persona pueda abrir ese mandato y la lleva a la página del panel inmobiliario que corresponde (crear o ver la
// hoja), que es la que guarda, avisa y aplica sus permisos y políticas (MandateClosingSheet).
require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\ClosingSheetsListing;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\MandateClosingSheet;
use SCM\Core\Auth;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');

$digits = static fn(string $key): int => preg_match('/^[1-9][0-9]{0,18}$/D', trim((string) ($_GET[$key] ?? ''))) === 1 ? (int) $_GET[$key] : 0;
$id = $digits('mandato');
$sheetId = $digits('numero');
Auth::requireLogin(SCM_BASE_URL . '/login.php');

$stop = static function (int $status, string $message): never {
  http_response_code($status);
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hoja de cierre</title>'
    . '<style>body{margin:0;font:15px/1.5 Poppins,system-ui,sans-serif;color:#061D49;background:#F5F7FB;display:grid;place-items:center;min-height:100vh}'
    . 'p{max-width:520px;margin:24px;padding:20px 24px;background:#fff;border:1px solid #E2E8F0;border-radius:14px}</style></head><body><p>'
    . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
  exit;
};

$adminCargos = is_array($scmConfig['dashboard_admin_cargos'] ?? null) ? $scmConfig['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
$policy = new CommercialAccessPolicy($scmSettings, $scmDb, $adminCargos);
$sheets = new MandateClosingSheet((string) ($scmConfig['services_url'] ?? ''));
// ?numero=<hoja>: desde Hojas de cierre / Mis cierres (la hoja debe ser de la persona, salvo que vea todo el equipo).
if ($sheetId > 0) {
  $listing = new ClosingSheetsListing($scmDb, $policy);
  $sheet = $listing->find($sheetId);
  if ($sheet === null) {
    $stop(404, 'La hoja de cierre no existe.');
  }
  if (!$listing->canOpen($sheet)) {
    $stop(403, 'Tu cargo no tiene permiso para abrir esta hoja de cierre.');
  }
  try {
    $url = $sheets->sheetUrl($sheetId);
  } catch (\RuntimeException $error) {
    $stop(503, $error->getMessage());
  }
  $_SESSION['scm_last_activity'] = time();
  header('Location: ' . $url, true, 302);
  exit;
}
$service = new CommercialMandatesService($scmDb, $policy);
$mandate = $id > 0 ? $service->repository()->find($id) : null;
if ($mandate === null) {
  $stop(404, 'El mandato no existe.');
}
if (!$service->canOpen($mandate) || !$policy->canAct(MandateClosingSheet::ACTION)) {
  $stop(403, 'Tu cargo no tiene permiso para abrir la hoja de cierre de este mandato.');
}
try {
  $url = $sheets->url($mandate, $service->repository()->closing($mandate));
} catch (\RuntimeException $error) {
  $stop(503, $error->getMessage());
}
// El panel inmobiliario cierra la sesión compartida tras horas sin actividad (scm_last_activity), que este panel no
// actualiza: se marca la actividad para que no se cierre justo al abrir la hoja.
$_SESSION['scm_last_activity'] = time();
header('Location: ' . $url, true, 302);
exit;
