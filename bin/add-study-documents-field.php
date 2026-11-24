<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Documentos de la aprobación del estudio de aseguradora en el CCT "estudios_aseguradoras" (campo sin pestaña, texto
// largo con la lista en JSON): documentos. Al aprobar, el consultor/a sube el asegurable, los recibos de servicios
// públicos y el documento del arrendatario en lugar del enlace de Drive. Mientras no exista, el panel no deja aprobar
// estudios y lo avisa. Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-study-documents-field.php            (solo muestra lo que haría)
//      php bin/add-study-documents-field.php --aplicar  (crea el campo; respalda antes en storage/data/cct-backups/)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);

try {
  $result = (new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups'))->run('estudios_aseguradoras', [CctFieldInstaller::NO_TAB => [
    'documentos' => ['Documentos de la aprobación (asegurable, servicios públicos y documento del arrendatario)', 'textarea'],
  ]], $apply);
  echo implode("\n", $result['lines']), "\n";
  if (!$apply) {
    echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-study-documents-field.php --aplicar\n";
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
