<?php

declare(strict_types=1);

// Archivos del Banco de piezas subidos en dashboard-marketing. Ver SCM\Commercial\Promotion\Pieces\BrandAssetFileMigration.
//   php bin/pieces-migrate-files.php                    Vista previa: solo lee, no cambia nada.
//   php bin/pieces-migrate-files.php --apply            Copia los archivos a este panel. No borra nada del panel anterior.
//   --legacy-root=/ruta/a/dashboard-marketing           Carpeta del panel anterior (por defecto, junto a este panel).
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\Promotion\Pieces\BrandAssetFileMigration;

@set_time_limit(0);
$arguments = array_values(array_slice($argv, 1));
$legacyRoot = dirname(SCM_ROOT) . '/dashboard-marketing';
foreach ($arguments as $argument) {
  if (str_starts_with($argument, '--legacy-root=')) {
    $legacyRoot = substr($argument, strlen('--legacy-root='));
  }
}
exit((new BrandAssetFileMigration(\SCM\Core\App::db(), SCM_ROOT, $legacyRoot, STDOUT))->run($arguments));
