<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

$wasLoggedIn = \SCM\Core\Auth::isLoggedIn();
$autoLoginOk = \SCM\Core\SignedAutoLogin::attempt($scmAuth, $_GET, $scmConfig);
if (!$wasLoggedIn && $autoLoginOk && isset($_GET['auto_signature'])) {
  header('Location: ' . SCM_BASE_URL . '/index.php', true, 303);
  exit;
}
// Enlace individual de un aviso (?ticket_id=<firma>): inicia la sesión del funcionario y abre la tarea.
$taskRedirect = \SCM\Support\StaffTaskLink::redirectFor($scmAuth, $_GET);
if ($taskRedirect !== null) {
  header('Location: ' . $taskRedirect, true, 303);
  exit;
}
// Enlace individual de un aviso de mandato (?mandato=<firma>, WhatsApp al consultor/a): igual, abre el mandato.
$mandateRedirect = \SCM\Support\PublicMandateAccess::staffRedirect($scmAuth, $_GET);
if ($mandateRedirect !== null) {
  header('Location: ' . $mandateRedirect, true, 303);
  exit;
}
// Sin sesión, el login vuelve a la misma tarea o pestaña.
\SCM\Core\Auth::requireLogin(\SCM\Support\StaffTaskLink::loginUrl($_GET));

$controller = new \SCM\Controllers\CommercialDashboardController($scmDb, $scmSettings, $scmCsrf, $scmConfig);
echo $controller->render($_GET);
