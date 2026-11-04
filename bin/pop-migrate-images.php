<?php

declare(strict_types=1);

// Fotos de Material POP subidas desde dashboard-marketing. Ver SCM\Commercial\Promotion\Pop\PopImageMigration.
//   php bin/pop-migrate-images.php                      Vista previa: solo lee, no cambia nada.
//   php bin/pop-migrate-images.php --apply              Copia las fotos al panel y actualiza cada producto (con respaldo).
//   php bin/pop-migrate-images.php --revert             Devuelve los productos a las fotos del panel de marketing.
//   --legacy-root=/ruta/a/dashboard-marketing           Carpeta del panel anterior (por defecto, junto a este panel).
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\Promotion\Pop\PopImageMigration;

@set_time_limit(0);
$arguments = array_values(array_slice($argv, 1));
$legacyRoot = dirname(SCM_ROOT) . '/dashboard-marketing';
foreach ($arguments as $argument) {
  if (str_starts_with($argument, '--legacy-root=')) {
    $legacyRoot = substr($argument, strlen('--legacy-root='));
  }
}
$db = \SCM\Core\App::db();
// Sin --apply ni --revert la configuración se abre en solo lectura.
$settings = new \SCM\Core\Settings($db, !PopImageMigration::writes($arguments), (string) ($scmConfig['settings_function_key'] ?? 'control_servicios_comerciales_config'));
$files = \SCM\Support\StoredFileService::fromRuntime();
exit((new PopImageMigration($db, $settings, SCM_UPLOAD_PATH, static fn(string $name): string => $files->urlFor($name), $legacyRoot, null, STDOUT))->run($arguments));
