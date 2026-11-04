<?php

declare(strict_types=1);

// Resúmenes diarios de la analítica web (Promoción › Analítica web) a partir de wp_jet_cct_tracker_logs.
// Cron sugerido, cada 10 minutos:   php bin/tracker-rollup.php
// Primera carga:                    php bin/tracker-rollup.php --backfill
// Tras cambiar las IPs internas:    php bin/tracker-rollup.php --rebuild-from=AAAA-MM-DD
// Retención (opcional, una vez al día, solo si "Conservar visitas crudas" es mayor que 0):
//                                   php bin/tracker-rollup.php --purge
// La purga borra visitas crudas que dashboard-marketing también lee: su analítica pierde esas filas.
// Ver SCM\Commercial\Promotion\Analytics\TrackerRollupCommand.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\TrackerRollupCommand;
use SCM\Commercial\Promotion\Analytics\VisitorHasher;

@set_time_limit(0);
$rollup = new TrackerRollup(\SCM\Core\App::db(), \SCM\Core\App::settings(), new VisitorHasher(SCM_APP_SECRET));
$command = new TrackerRollupCommand($rollup, STDOUT, SCM_STORAGE_PATH . '/logs/tracker-rollup.log');
exit($command->run(array_values(array_slice($argv, 1))));
