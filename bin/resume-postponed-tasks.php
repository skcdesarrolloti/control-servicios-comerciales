<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Tareas postergadas cuya fecha para retomar llegó: vuelven a Nuevo (estado y estado comercial, sin tocar el estado
// administrativo), queda una nota interna y su responsable recibe un correo. Solo actúa en días hábiles de Colombia
// (lunes a sábado sin festivos) desde las 7:00 a. m. hora Colombia, sin importar la zona horaria del servidor: el lunes
// (o el día hábil siguiente) retoma también las que cayeron en domingo o festivo. Cron diario (servidor en UTC):
//   0 12 * * * /usr/bin/php /home/u350704768/public_html/dashboard-comercial/bin/resume-postponed-tasks.php

use SCM\Commercial\CommercialPostponedResumer;
use SCM\Support\ColombiaBusinessCalendar;

try {
  $now = time();
  $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(ColombiaBusinessCalendar::TIMEZONE))->format('Y-m-d H:i');
  if (!CommercialPostponedResumer::isTime($now)) {
    echo '[' . $local . ' Colombia] Domingo, festivo o antes de las 7:00 a. m.: no se retoma nada.' . PHP_EOL;
    exit(0);
  }
  $count = (new CommercialPostponedResumer(\SCM\Core\App::db(), \SCM\Core\App::settings()))->run($now);
  echo '[' . $local . ' Colombia] Tareas retomadas: ' . $count . PHP_EOL;
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
