<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$failures = [];

foreach ($iterator as $file) {
  if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
    continue;
  }
  $path = $file->getPathname();
  if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
    continue;
  }
  $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
  exec($command, $output, $code);
  if ($code !== 0) {
    $failures[] = $path . PHP_EOL . implode(PHP_EOL, $output);
  }
  $output = [];
}

if ($failures !== []) {
  fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
  exit(1);
}

fwrite(STDOUT, "PHP lint: OK\n");
