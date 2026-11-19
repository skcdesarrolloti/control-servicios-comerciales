<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Enlaces de la cotización de inmuebles en el CCT "cotizacion_inmuebles" (pestaña Complementarios): url_cotizacion (la
// vista pública firmada, public/cotizacion.php) y url_pdf (su PDF). El panel los llena al crear cada cotización; así los
// pueden abrir el portal del cliente y los demás paneles. Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-quote-link-fields.php                        (solo muestra lo que haría)
//      php bin/add-quote-link-fields.php --aplicar              (crea los campos; respalda antes en storage/data/cct-backups/)
//      php bin/add-quote-link-fields.php --aplicar --rellenar   (además llena los enlaces de las cotizaciones que no los tienen)
//
// Los enlaces van firmados y vencen al año (PublicQuoteAccess::TTL); --rellenar se puede repetir y solo toca las vacías.

use SCM\Support\CctFieldInstaller;
use SCM\Support\PublicQuoteAccess;

$apply = in_array('--aplicar', $argv, true);
$fill = in_array('--rellenar', $argv, true);
$db = \SCM\Core\App::db();

try {
  $result = (new CctFieldInstaller($db, dirname(__DIR__) . '/storage/data/cct-backups'))->run('cotizacion_inmuebles', ['complementarios_tab' => [
    'url_cotizacion' => ['URL de la cotización'],
    'url_pdf' => ['URL del PDF de la cotización'],
  ]], $apply);
  echo implode("\n", $result['lines']), "\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}

$table = $db->table('jet_cct_cotizacion_inmuebles');
$columns = array_column($db->getResults("SHOW COLUMNS FROM `{$table}`"), 'Field');
if (!in_array('url_cotizacion', $columns, true) || !in_array('url_pdf', $columns, true)) {
  echo $apply ? '' : "\nSolo se mostró el plan. Para hacerlo: php bin/add-quote-link-fields.php --aplicar [--rellenar]\n";
  exit(0);
}
$empty = "COALESCE(`url_cotizacion`, '') = ''";
$pending = (int) $db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE {$empty}");
echo "Cotizaciones sin enlace: {$pending}\n";
if (!$fill || !$apply) {
  echo $pending > 0 ? "Para llenarlas: php bin/add-quote-link-fields.php --aplicar --rellenar\n" : '';
  exit(0);
}
// Por lotes y en una transacción por lote: fila por fila, la base remota tarda y corta la conexión.
$done = 0;
$pdo = $db->pdo();
$update = $pdo->prepare("UPDATE `{$table}` SET `url_cotizacion` = ?, `url_pdf` = ? WHERE `_ID` = ?");
do {
  $ids = $db->getResults("SELECT `_ID` FROM `{$table}` WHERE {$empty} ORDER BY `_ID` LIMIT 500");
  $pdo->beginTransaction();
  foreach ($ids as $row) {
    $id = (int) $row['_ID'];
    $url = PublicQuoteAccess::url($id);
    $update->execute([$url, $url . '&formato=pdf', $id]);
    $done++;
  }
  $pdo->commit();
} while ($ids !== []);
$left = (int) $db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE {$empty}");
echo "Enlaces llenados: {$done}\n" . ($left > 0 ? "Quedan {$left} sin enlace: vuelve a correr el comando.\n" : "Todas las cotizaciones tienen enlace.\n");
exit($left > 0 ? 1 : 0);
