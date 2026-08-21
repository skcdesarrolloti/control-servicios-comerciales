<?php

declare(strict_types=1);

define('SCM_BASE_URL', 'https://example.test');
define('SCM_VERSION', 'test');
define('SCM_UPLOAD_MAX_BYTES', 10485760);
define('SCM_BASE_PATH', dirname(__DIR__) . '/public');
define('SCM_STORAGE_PATH', dirname(__DIR__) . '/storage');

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src', true);

require_once dirname(__DIR__) . '/src/Core/Helpers.php';
