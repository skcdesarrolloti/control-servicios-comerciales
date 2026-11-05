<?php

declare(strict_types=1);

// Archivo de una pieza del Banco de piezas: solo con sesión y permiso de la subpestaña. ?id=N, y &download=1 para descargar.
require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Pieces\BrandAssetFiles;
use SCM\Commercial\Promotion\Pieces\BrandAssetService;

\SCM\Core\Auth::requireLogin(SCM_BASE_URL . '/login.php');
$adminCargos = is_array($scmConfig['dashboard_admin_cargos'] ?? null) ? $scmConfig['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
$policy = new CommercialAccessPolicy($scmSettings, $scmDb, $adminCargos);
if (!$policy->canView('promocion') || !$policy->canSubview('promocion', 'banco_piezas')) {
  http_response_code(403);
  exit('No tienes permiso para ver este archivo.');
}
session_write_close();

$files = BrandAssetFiles::runtime();
$asset = (new BrandAssetService($scmDb, $files))->find(max(0, (int) ($_GET['id'] ?? 0)));
$path = $asset !== [] ? $files->path((string) ($asset['storage_path'] ?? '')) : null;
if ($path === null) {
  http_response_code(404);
  exit('Archivo no encontrado.');
}
@set_time_limit(0);
while (ob_get_level() > 0) {
  ob_end_clean();
}
foreach (BrandAssetFiles::headers($path, (string) ($asset['original_name'] ?? ''), !empty($_GET['download'])) as $header) {
  header($header);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
  readfile($path);
}
