<?php

declare(strict_types=1);

// Solo CLI: CommercialMaterialPopTest lo ejecuta en un proceso aparte porque la API termina con exit.
// Uso: php tests/Fixtures/inventory-api-endpoint.php <json en base64>
//   {method, resource, post, body, content_type, headers, token: "ninguno"|"activo"|"inactivo", ip, sqlite}
// Imprime la respuesta y en la última línea el código HTTP. El token generado queda en Settings (pop_test_token).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require __DIR__ . '/CommercialPromotionDatabase.php';

use SCM\Commercial\Promotion\Pop\PopConfig;
use SCM\Commercial\Promotion\Pop\PopInventoryService;
use Tests\Fixtures\CommercialPromotionDatabase;

$request = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true) ?: [];
$db = CommercialPromotionDatabase::create('sqlite:' . (string) ($request['sqlite'] ?? ':memory:'));
$settings = CommercialPromotionDatabase::settings($db);
CommercialPromotionDatabase::pop($db, $settings);
$token = '';
if (($request['token'] ?? 'ninguno') !== 'ninguno' && !PopConfig::read($settings)['api_token_set']) {
  $token = PopConfig::rotateToken($settings, 7);
  $settings->set('pop_test_token', $token);
}
$token = $token !== '' ? $token : (string) $settings->get('pop_test_token', '');
if (($request['token'] ?? 'ninguno') !== 'ninguno') {
  PopConfig::save($settings, ['categories' => PopConfig::read($settings)['categories'], 'api_enabled' => $request['token'] === 'activo'], 7);
}
register_shutdown_function(static function (): void { echo PHP_EOL . (int) http_response_code(); });
$headers = [];
foreach ((array) ($request['headers'] ?? []) as $name => $value) {
  $headers[strtolower((string) $name)] = str_replace('{token}', $token, (string) $value);
}
$post = (array) ($request['post'] ?? []);
array_walk($post, static function (&$value) use ($token): void { $value = is_string($value) ? str_replace('{token}', $token, $value) : $value; });
$limits = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-pop-api-limits-' . (string) ($request['limits'] ?? getmypid());
(new SCM\Http\Controller\PopPublicApiController(new PopInventoryService($db, $settings), $settings, new SCM\Support\FileRateLimiter($limits)))->handle(
  (string) ($request['method'] ?? 'GET'),
  (string) ($request['resource'] ?? 'products'),
  $post,
  str_replace('{token}', $token, (string) ($request['body'] ?? '')),
  (string) ($request['content_type'] ?? ''),
  $headers,
  (string) ($request['ip'] ?? '127.0.0.1')
);
