<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Uso de una sola vez: a las tareas postergadas sin fecha para retomar les pone una, repartida en los días hábiles de los
// próximos 30 días, por responsable y de la más antigua a la más reciente. Cada una queda con una nota interna y el cron
// bin/resume-postponed-tasks.php las devuelve a Nuevo de a pocas (con su correo al responsable).
//
// Uso: php bin/schedule-postponed-tasks.php            (solo muestra el plan)
//      php bin/schedule-postponed-tasks.php --aplicar  (pone las fechas)

use SCM\Commercial\CommercialPostponedScheduler;
use SCM\Commercial\CommercialTicketsRepository;

$apply = in_array('--aplicar', $argv, true);

try {
  $repository = new CommercialTicketsRepository(\SCM\Core\App::db());
  $rows = $repository->postponedWithoutResumeDate();
  if ($rows === []) {
    echo "No hay tareas postergadas sin fecha para retomar.\n";
    exit(0);
  }
  $plan = CommercialPostponedScheduler::plan($rows, time());
  $days = CommercialPostponedScheduler::days(time());
  echo 'Postergadas sin fecha: ' . count($rows) . ' · repartidas en ' . $plan['days'] . ' días hábiles, del ' . $days[0]->format('d/m/Y') . ' al ' . end($days)->format('d/m/Y') . ".\n\n";
  printf("%-40s %8s %12s\n", 'Responsable', 'Tareas', 'Máx. por día');
  foreach ($plan['people'] as $person) {
    printf("%-40s %8d %12d\n", mb_strimwidth($person['nombre'], 0, 40, '…', 'UTF-8'), $person['total'], $person['por_dia']);
  }
  $perDay = [];
  foreach ($plan['assign'] as $date) {
    $perDay[$date->format('Y-m-d')] = ($perDay[$date->format('Y-m-d')] ?? 0) + 1;
  }
  echo "\nTareas que vuelven a Nuevo cada día (7:00 a. m.):\n";
  foreach ($perDay as $day => $count) {
    echo '  ' . $day . ': ' . $count . "\n";
  }
  if (!$apply) {
    echo "\nSolo se mostró el plan. Para hacerlo: php bin/schedule-postponed-tasks.php --aplicar\n";
    exit(0);
  }
  $done = 0;
  foreach ($plan['assign'] as $id => $date) {
    if ($repository->scheduleResume($id, $date->getTimestamp(), 'Se programó retomar la tarea el ' . $date->format('d/m/Y') . ': estaba postergada sin fecha. Ese día vuelve a "Nuevo".')) {
      $done++;
    }
  }
  echo "\nListo: " . $done . " tareas con fecha para retomar.\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
