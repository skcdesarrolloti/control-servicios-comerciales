<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Deshace el reparto de bin/schedule-postponed-tasks.php para ponerles fecha más adelante:
// - las que por la fecha que les puso el reparto volvieron a Nuevo desde hoy (o desde --desde=AAAA-MM-DD) y ningún
//   funcionario ha tocado, vuelven a Postergado (estado "En proceso", como Postergar) sin fecha para retomar;
// - las que el reparto programó y siguen postergadas quedan sin fecha.
// Las fechas que puso un consultor/a no se tocan, ni el estado administrativo. Cada una queda con una nota interna.
// Sin fecha no vuelven solas a Nuevo; luego se les pone con bin/schedule-postponed-tasks.php.
//
// Uso: php bin/unschedule-postponed-tasks.php                     (solo muestra cuántas)
//      php bin/unschedule-postponed-tasks.php --aplicar           (lo hace)
//      php bin/unschedule-postponed-tasks.php --desde=2026-11-25  (incluye las que volvieron a Nuevo desde ese día)

use SCM\Commercial\CommercialPostponedScheduler;
use SCM\Commercial\CommercialTicketsRepository;

$apply = in_array('--aplicar', $argv, true);
$zone = new DateTimeZone(\SCM\Support\ColombiaBusinessCalendar::TIMEZONE);
$since = new DateTimeImmutable('today', $zone);
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--desde=')) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($arg, 8), $zone);
    if ($parsed === false) {
      fwrite(STDERR, "Fecha inválida en --desde (use AAAA-MM-DD).\n");
      exit(1);
    }
    $since = $parsed;
  }
}

try {
  $repository = new CommercialTicketsRepository(\SCM\Core\App::db());
  $returned = $repository->resumedBySystemSince($since->getTimestamp(), CommercialPostponedScheduler::NOTE_MARK);
  $scheduled = $repository->postponedScheduledBySystem(CommercialPostponedScheduler::NOTE_MARK);
  echo 'Volvieron a Nuevo desde el ' . $since->format('d/m/Y') . ' por el reparto y nadie las ha tocado (vuelven a Postergado sin fecha): ' . count($returned) . "\n";
  echo 'Programadas por el reparto y aún postergadas (quedan sin fecha): ' . count($scheduled) . "\n";
  if (!$apply) {
    echo "\nSolo se mostró cuántas. Para hacerlo: php bin/unschedule-postponed-tasks.php --aplicar\n";
    exit(0);
  }
  $back = 0;
  foreach ($returned as $row) {
    if ($repository->repostpone((int) $row['_ID'], null, 'La tarea volvió a "Postergado" sin fecha para retomar: había regresado a Nuevo por el reparto de postergadas. La fecha se pondrá más adelante.')) {
      $back++;
    }
  }
  $cleared = 0;
  foreach ($scheduled as $row) {
    if ($repository->scheduleResume((int) $row['_ID'], null, 'Se quitó la fecha para retomar que le había puesto el reparto de postergadas. La fecha se pondrá más adelante.', true)) {
      $cleared++;
    }
  }
  echo "\nListo: " . $back . ' volvieron a Postergado y ' . $cleared . " quedaron sin fecha.\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
