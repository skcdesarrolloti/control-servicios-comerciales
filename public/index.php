<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

$wasLoggedIn = \SCM\Core\Auth::isLoggedIn();
$autoLoginOk = \SCM\Core\SignedAutoLogin::attempt($scmAuth, $_GET, $scmConfig);
if (!$wasLoggedIn && $autoLoginOk && isset($_GET['auto_signature'])) {
  header('Location: ' . SCM_BASE_URL . '/index.php', true, 303);
  exit;
}
\SCM\Core\Auth::requireLogin(SCM_BASE_URL . '/login.php');

$controller = new \SCM\Controllers\CommercialDashboardController($scmDb, $scmSettings, $scmCsrf, $scmConfig);
echo $controller->render($_GET);
