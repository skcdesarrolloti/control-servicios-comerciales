<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
  $autoload = $root . '/.vendor-test-junction/autoload.php';
}
if (is_readable($autoload)) {
  require_once $autoload;
}
require_once $root . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register($root . '/src', true);
require_once dirname(__DIR__) . '/src/Commercial/CommercialStatusCatalog.php';
