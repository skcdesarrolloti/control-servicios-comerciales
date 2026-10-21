<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$policy = new \SCM\Commercial\CommercialAccessPolicy($scmSettings, $scmDb, $scmConfig['dashboard_admin_cargos']);
$service = new \SCM\Commercial\CommercialSignService($scmDb, $policy, $scmConfig);
$service->ensureRequestsTable();
$pending = $scmDb->getCol('SELECT request_id FROM `' . $scmDb->table('scm_commercial_sign_requests') . '` WHERE portal_pending = 1 ORDER BY created_at LIMIT 100');
$failed = 0;
foreach ($pending as $id) if (!$service->syncPortal((string) $id)) ++$failed;
echo 'Despublicaciones sincronizadas: ' . (count($pending) - $failed) . '. Pendientes: ' . $failed . PHP_EOL;
exit($failed ? 1 : 0);
