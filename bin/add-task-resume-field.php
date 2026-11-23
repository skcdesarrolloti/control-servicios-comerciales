<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Fecha para retomar una tarea postergada en el CCT "tickets" (campo sin pestaña, como fecha guardada en timestamp):
// fecha_retomar. Postergar la pide en el popup de la tarea, el calendario de vencimientos la muestra el día que toca y
// Activar la borra. Mientras no exista, el panel posterga igual pero sin fecha. Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-task-resume-field.php            (solo muestra lo que haría)
//      php bin/add-task-resume-field.php --aplicar  (crea el campo; respalda antes en storage/data/cct-backups/)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);

try {
  $result = (new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups'))->run('tickets', [CctFieldInstaller::NO_TAB => [
    'fecha_retomar' => ['Fecha para retomar la tarea postergada', 'fecha'],
  ]], $apply);
  echo implode("\n", $result['lines']), "\n";
  if (!$apply) {
    echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-task-resume-field.php --aplicar\n";
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
