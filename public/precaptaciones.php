<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
\SCM\Core\Auth::requireLogin(SCM_BASE_URL . '/login.php');
\SCM\Precaptacion\Module::init($scmDb, $scmSettings, $scmCsrf, $scmConfig);
$policy = \SCM\Precaptacion\Module::policy();
if (!$policy->canView('precaptacion') || (!$policy->canManage() && \SCM\Core\Auth::employeeId() === '')) {
  http_response_code(403);
  echo \SCM\Views\CommercialDashboardView::renderAccessDenied(SCM_BASE_URL);
  exit;
}
$nonce = \SCM\Precaptacion\Module::nonce();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Precaptación · SuCasa Inmobiliaria</title>
  <link rel="stylesheet" href="<?php echo esc_url(SCM_BASE_URL . '/assets/css/tailwind.css'); ?>">
  <link rel="stylesheet" href="<?php echo esc_url(SCM_BASE_URL . '/assets/css/precaptacion.css?v=' . filemtime(__DIR__ . '/assets/css/precaptacion.css')); ?>">
</head>
<body data-precap-api="<?php echo esc_url(SCM_BASE_URL . '/precaptacion-api.php'); ?>" data-precap-nonce="<?php echo esc_attr($nonce); ?>">
  <?php echo \SCM\Precaptacion\FormView::render(new \SCM\Precaptacion\Repository($scmDb)); ?>
  <?php echo \SCM\Precaptacion\LegacyPanel::render_shortcode(['modo'=>$policy->canManage() ? 'control' : 'mis']); ?>
  <script src="<?php echo esc_url(SCM_BASE_URL . '/assets/js/precaptacion.js?v=' . filemtime(__DIR__ . '/assets/js/precaptacion.js')); ?>"></script>
</body>
</html>
