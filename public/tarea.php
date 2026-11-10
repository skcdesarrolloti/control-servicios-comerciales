<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

(new \SCM\Http\Controller\PublicTaskPageController(
  $scmDb,
  $scmSettings,
  new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits')
))->handle($_GET, $_POST, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
