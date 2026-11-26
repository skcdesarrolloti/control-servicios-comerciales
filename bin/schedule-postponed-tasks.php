<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// A las tareas postergadas sin fecha para retomar les pone una, repartida en los días hábiles entre dentro de un mes y
// dentro de 50 días, por responsable y de la más antigua a la más reciente. También vuelve a repartir las que este script
// ya había programado (su nota dice "estaba postergada sin fecha") y siguen postergadas, y vuelve a postergar las que
// por esa fecha regresaron a Nuevo desde hoy (o desde --desde=AAAA-MM-DD) sin que nadie las haya tocado. Las que puso
// un consultor/a no se tocan. Cada una queda con una nota interna y el cron bin/resume-postponed-tasks.php las devuelve a
// Nuevo de a pocas.
//
// Uso: php bin/schedule-postponed-tasks.php                        (solo muestra el plan)
//      php bin/schedule-postponed-tasks.php --aplicar              (pone las fechas)
//      php bin/schedule-postponed-tasks.php --desde=2026-11-25     (incluye las que volvieron a Nuevo desde ese día)

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
  $withoutDate = $repository->postponedWithoutResumeDate();
  $bySystem = $repository->postponedScheduledBySystem(CommercialPostponedScheduler::NOTE_MARK);
  $returned = $repository->resumedBySystemSince($since->getTimestamp(), CommercialPostponedScheduler::NOTE_MARK);
  $rows = array_merge($withoutDate, $bySystem, $returned);
  if ($rows === []) {
    echo "No hay tareas postergadas sin fecha, programadas por el script ni devueltas a Nuevo por él.\n";
    exit(0);
  }
  $back = array_fill_keys(array_map(static fn(array $row): int => (int) $row['_ID'], $returned), true);
  $replace = array_fill_keys(array_map(static fn(array $row): int => (int) $row['_ID'], $bySystem), true);
  // Juntas, la más antigua primero (como las ordena el repositorio): fecha de actualización o, si no hay, de creación.
  $age = static function (array $row): int {
    foreach (['fecha_actualizacion', 'fecha'] as $key) {
      $raw = trim((string) ($row[$key] ?? ''));
      $time = is_numeric($raw) ? (int) $raw : (int) strtotime($raw);
      if ($time > 0) {
        return $time;
      }
    }
    return 0;
  };
  usort($rows, static fn(array $a, array $b): int => [$age($a), (int) $a['_ID']] <=> [$age($b), (int) $b['_ID']]);
  $plan = CommercialPostponedScheduler::plan($rows, time());
  $days = CommercialPostponedScheduler::days(time());
  echo 'Sin fecha: ' . count($withoutDate) . ' · ya programadas por el script (se reprograman): ' . count($bySystem)
    . ' · volvieron a Nuevo desde el ' . $since->format('d/m/Y') . ' sin que nadie las tocara (se postergan de nuevo): ' . count($returned) . "\n";
  echo 'Total ' . count($rows) . ', repartidas en ' . $plan['days'] . ' días hábiles, del ' . $days[0]->format('d/m/Y') . ' al ' . end($days)->format('d/m/Y') . ".\n\n";
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
    if (isset($back[$id])) {
      $message = 'La tarea volvió a "Postergado": había regresado a Nuevo antes de tiempo. Se reprogramó retomar la tarea el ' . $date->format('d/m/Y') . ': ' . CommercialPostponedScheduler::NOTE_MARK . '. Ese día vuelve a "Nuevo".';
      $ok = $repository->repostpone($id, $date->getTimestamp(), $message);
    } else {
      $again = isset($replace[$id]);
      $message = ($again ? 'Se reprogramó' : 'Se programó') . ' retomar la tarea el ' . $date->format('d/m/Y') . ': ' . CommercialPostponedScheduler::NOTE_MARK . '. Ese día vuelve a "Nuevo".';
      $ok = $repository->scheduleResume($id, $date->getTimestamp(), $message, $again);
    }
    if ($ok) {
      $done++;
    }
  }
  echo "\nListo: " . $done . " tareas con fecha para retomar.\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
