<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Tarea comercial de donde se creó el contrato de mandato, en el CCT "contrato_mandato" (campo sin pestaña): id_ticket.
// El popup de la tarea lista sus mandatos por esta columna; mientras no exista, los encuentra por el estudio de
// aseguradora y por el inmueble de la tarea. Ver docs/cct-agregar-columnas.md y docs/mandatos.md.
//
// Uso: php bin/add-mandate-task-field.php            (solo muestra lo que haría)
//      php bin/add-mandate-task-field.php --aplicar  (crea el campo; respalda antes en storage/data/cct-backups/)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);

try {
  $result = (new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups'))->run('contrato_mandato', [CctFieldInstaller::NO_TAB => [
    'id_ticket' => ['Tarea comercial', 'text'],
  ]], $apply);
  echo implode("\n", $result['lines']), "\n";
  if (!$apply) {
    echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-mandate-task-field.php --aplicar\n";
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
