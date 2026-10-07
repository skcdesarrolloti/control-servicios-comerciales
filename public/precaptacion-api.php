<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();
use SCM\Http\Response\JsonResponse;
use SCM\Precaptacion\Module;

if (!\SCM\Core\Auth::isLoggedIn()) JsonResponse::error('No autenticado.', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') JsonResponse::error('Método no permitido.', 405);
Module::init($scmDb, $scmSettings, $scmCsrf, $scmConfig);
try {
  Module::dispatch((string) ($_POST['action'] ?? ''), $_POST);
} catch (\InvalidArgumentException $exception) {
  Module::rollback();
  JsonResponse::error($exception->getMessage(), 422);
} catch (\Throwable $exception) {
  Module::rollback();
  error_log('[precaptacion] ' . $exception->getMessage());
  JsonResponse::error('No se pudo completar la operación. Intenta nuevamente.', 500);
}
