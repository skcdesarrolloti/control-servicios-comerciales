<?php

declare(strict_types=1);

// Sincroniza las redes sociales de Promoción › Redes sociales. Ver SCM\Commercial\Promotion\Social\SocialSyncRunner.
//   php bin/social-sync.php                     Todas las redes, últimos 35 días (para la tarea programada).
//   php bin/social-sync.php --status            Solo muestra qué credenciales faltan. No llama a las redes ni escribe.
//   php bin/social-sync.php --network=youtube   Una red: instagram, facebook, publicidad, youtube o tiktok.
//   php bin/social-sync.php --from=AAAA-MM-DD --to=AAAA-MM-DD
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Commercial\Promotion\Social\SocialSyncRunner;

@set_time_limit(0);
$options = [];
foreach (array_slice($argv, 1) as $argument) {
  [$key, $value] = array_pad(explode('=', ltrim($argument, '-'), 2), 2, '1');
  $options[$key] = $value;
}
$runner = new SocialSyncRunner(\SCM\Core\App::db(), $scmConfig, SCM_STORAGE_PATH . '/cache/social-sync.lock');
if (isset($options['status'])) {
  foreach ($runner->configured() as $network => $ready) {
    echo str_pad($network, 12), $ready ? 'con credenciales' : 'FALTAN CREDENCIALES', PHP_EOL;
  }
  exit(0);
}
$from = (string) ($options['from'] ?? date('Y-m-d', strtotime('-' . SocialSyncRunner::DEFAULT_DAYS . ' days')));
$to = (string) ($options['to'] ?? date('Y-m-d'));
try {
  $result = $runner->run((string) ($options['network'] ?? ''), $from, $to);
} catch (\Throwable $exception) {
  fwrite(STDERR, $exception->getMessage() . PHP_EOL);
  exit(1);
}
foreach ($result['results'] as $network => $item) {
  echo str_pad($network, 12), $item['ok'] ? 'ok ' . json_encode($item['summary'], JSON_UNESCAPED_UNICODE) : 'ERROR: ' . $item['message'], PHP_EOL;
  foreach ($item['warnings'] as $warning) {
    echo '  aviso: ', $warning, PHP_EOL;
  }
}
exit($result['ok'] ? 0 : 1);
