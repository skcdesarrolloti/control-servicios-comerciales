<?php

declare(strict_types=1);

// Prepara las tablas de Promoción › Reuniones (las mismas de dashboard-marketing) y agrega las columnas del borrado
// lógico. Solo agrega: no borra ni cambia columnas existentes. El panel también lo hace solo la primera vez que se usa.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

\SCM\Commercial\Promotion\MeetingSchema::ensure(\SCM\Core\App::db(), \SCM\Core\App::settings(), true);
fwrite(STDOUT, 'Reuniones: esquema versión ' . \SCM\Commercial\Promotion\MeetingSchema::VERSION . " listo.\n");
