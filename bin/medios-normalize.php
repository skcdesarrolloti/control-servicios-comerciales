<?php

declare(strict_types=1);

// Lista única de Medios (Configuración › Medios). Ver SCM\Commercial\Medios\MediosNormalizeCommand.
//   php bin/medios-normalize.php              Vista previa (= --dry-run): solo lee, no escribe nada.
//   php bin/medios-normalize.php --apply      Crea las tablas scm_medios* con la lista inicial, sincroniza el glosario
//                                             "Medio de llamada" (wp_jet_post_types id 195) y normaliza los valores de
//                                             los registros y de las visitas, con respaldo de cada fila.
//   php bin/medios-normalize.php --revert=ID  Revierte las filas que cambió el paso ID del historial.
// Después de --apply: php bin/tracker-rollup.php (migra los resúmenes de la analítica a la versión 3, con medio).
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\Medios\MediosNormalizeCommand;

@set_time_limit(0);
$arguments = array_values(array_slice($argv, 1));
$db = \SCM\Core\App::db();
// Sin --apply ni --revert la configuración se abre en solo lectura: cualquier intento de guardarla falla.
$settings = new \SCM\Core\Settings($db, !MediosNormalizeCommand::writes($arguments), (string) ($scmConfig['settings_function_key'] ?? 'control_servicios_comerciales_config'));
exit((new MediosNormalizeCommand($db, $settings, STDOUT))->run($arguments));
