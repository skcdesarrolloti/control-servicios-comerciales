<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Ediciones de la cronología de las tareas (CommercialTimelineEditor): en las respuestas (historial_del_ticket), los
// seguimientos (seguimiento_ticket) y las notas internas (notas_ticket) se guarda cuántas veces se editó cada entrada
// (veces_editada, máximo 2) y cuándo fue la última (fecha_edicion). Mientras no existan, el panel permite eliminar pero no
// editar. Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-timeline-edit-fields.php            (solo muestra lo que haría)
//      php bin/add-timeline-edit-fields.php --aplicar  (crea los campos; respalda antes en storage/data/cct-backups/)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);
$fields = [CctFieldInstaller::NO_TAB => [
  'veces_editada' => ['Veces que se editó desde el panel comercial', 'text'],
  'fecha_edicion' => ['Fecha de la última edición', 'fecha'],
]];

try {
  $installer = new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups');
  foreach (['historial_del_ticket', 'seguimiento_ticket', 'notas_ticket'] as $slug) {
    $result = $installer->run($slug, $fields, $apply);
    echo "== {$slug}\n", implode("\n", $result['lines']), "\n";
  }
  if (!$apply) {
    echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-timeline-edit-fields.php --aplicar\n";
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
