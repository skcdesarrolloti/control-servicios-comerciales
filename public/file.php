<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

$files = \SCM\Support\StoredFileService::fromRuntime();
$name = (string) ($_GET['n'] ?? '');
if (!$files->isValidSignature($name, (string) ($_GET['s'] ?? '')) || !($path = $files->pathFor($name))) {
  http_response_code(404);
  exit('Archivo no disponible.');
}
session_write_close();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowed = ['image/jpeg', 'image/png', 'application/pdf', 'video/mp4'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(415);
  exit('Formato no disponible.');
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
  readfile($path);
}
