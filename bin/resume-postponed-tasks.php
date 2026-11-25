<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Tareas postergadas cuya fecha para retomar ya llegó: vuelven a Nuevo (estado y estado comercial, sin tocar el estado
// administrativo), queda una nota interna y su responsable recibe un correo. El panel también lo hace al abrirse, pero
// conviene el cron para que pase aunque nadie entre. Cron sugerido (cada hora, de 8 a. m. en adelante sirve igual):
//   0 * * * * php /ruta/dashboard-comercial/bin/resume-postponed-tasks.php

try {
  $count = (new \SCM\Commercial\CommercialPostponedResumer(\SCM\Core\App::db(), \SCM\Core\App::settings()))->run();
  echo '[' . date('Y-m-d H:i:s') . '] Tareas retomadas: ' . $count . PHP_EOL;
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
