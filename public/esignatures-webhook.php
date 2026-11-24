<?php

declare(strict_types=1);

// Aviso de eSignatures (contrato de mandato firmado o anulado). Webhook URL en el panel de eSignatures (sección API):
// https://sucasainmobiliaria.com.co/dashboard-comercial/public/esignatures-webhook.php
// Ver src/Commercial/Mandates/MandateSignatureWebhook.php y docs/mandatos.md.
define('SCM_NO_SESSION', true);
require_once dirname(__DIR__) . '/bootstrap/app.php';

$respond = static function (int $status, string $message): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=UTF-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  $respond(405, 'Método no permitido');
}
$signature = \SCM\Commercial\Mandates\MandateSignature::fromConfig($scmConfig);
if ($signature === null) {
  $respond(503, 'Firma digital sin configurar');
}
$body = (string) file_get_contents('php://input');
$notice = json_decode($body, true);
if (!is_array($notice)) {
  $respond(400, 'Aviso inválido');
}
// La firma del aviso solo se registra si no coincide: lo que importa se consulta a la API con el token.
if (!\SCM\Commercial\Mandates\MandateSignatureWebhook::validSignature($body, (string) ($_SERVER['HTTP_X_SIGNATURE_SHA256'] ?? ''), (string) ($scmConfig['esignatures_token'] ?? ''))) {
  error_log('[mandatos:webhook] Aviso con firma X-Signature-SHA256 que no coincide.');
}
try {
  $result = (new \SCM\Commercial\Mandates\MandateSignatureWebhook($scmDb, $signature, \SCM\Commercial\Mandates\MandateSignatureWebhook::downloader(\SCM\Support\StoredFileService::fromRuntime())))->process($notice);
  $respond(200, $result);
} catch (\Throwable $error) {
  error_log('[mandatos:webhook] ' . $error->getMessage());
  $respond(500, 'No se pudo procesar el aviso');
}
