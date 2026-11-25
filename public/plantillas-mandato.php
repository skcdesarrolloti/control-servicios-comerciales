<?php

declare(strict_types=1);

// Editor de las plantillas del contrato de mandato (eSignatures), en su propia pestaña: Configuración › Actividades
// comerciales › Contratos de mandato › Abrir el editor. Solo administradores. Lee y guarda por api.php
// (commercial_settings_load / commercial_settings_save, sección plantillas_mandato → MandateTemplateEditor).
require_once dirname(__DIR__) . '/bootstrap/app.php';
\SCM\Core\Auth::requireLogin(SCM_BASE_URL . '/login.php');
$adminCargos = is_array($scmConfig['dashboard_admin_cargos'] ?? null) ? $scmConfig['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
$policy = new \SCM\Commercial\CommercialAccessPolicy($scmSettings, $scmDb, $adminCargos);
if (!$policy->canManage()) {
  http_response_code(403);
  echo \SCM\Views\CommercialDashboardView::renderAccessDenied(SCM_BASE_URL);
  exit;
}
// El mismo nonce del panel (no se genera otro para no invalidar el de la pestaña del panel).
$current = $_SESSION['scm_csrf']['commercial_nonce'] ?? null;
$nonce = is_string($current) && $current !== '' ? $current : $scmCsrf->token('commercial_nonce');
$asset = static fn(string $path): string => SCM_BASE_URL . '/assets/' . $path . '?v=' . substr((string) hash_file('sha256', SCM_PUBLIC_PATH . '/assets/' . $path), 0, 12);
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Plantillas del contrato de mandato · SKC SuCasa Inmobiliaria</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0">
  <link rel="stylesheet" href="<?php echo esc_url($asset('css/mandate-template-editor.css')); ?>">
</head>
<body>
  <div id="mte" data-api="<?php echo esc_url(SCM_BASE_URL . '/api.php'); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-panel="<?php echo esc_url(SCM_BASE_URL . '/index.php'); ?>">
    <header class="mte-top">
      <div class="mte-top-in">
        <span class="mte-brand"><span class="material-symbols-outlined" aria-hidden="true">gavel</span>SKC SuCasa Inmobiliaria</span>
        <a class="mte-back" href="<?php echo esc_url(SCM_BASE_URL . '/index.php'); ?>"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Volver al panel</a>
      </div>
    </header>
    <main class="mte-main">
      <section class="mte-head">
        <p class="mte-eyebrow">Configuración · Actividades comerciales</p>
        <h1>Plantillas del contrato de mandato</h1>
        <p class="mte-warn"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>Es el <strong>texto legal</strong> del contrato en eSignatures. Lo que guardes aplica a <strong>todos los contratos nuevos</strong> de ese tipo y a la página que revisa el propietario. Los ya enviados a firma no cambian. La versión anterior queda en el historial.</span></p>
      </section>
      <p class="mte-status" data-mte-status role="status" aria-live="polite">Cargando las plantillas de eSignatures…</p>
      <div class="mte-app" data-mte-app hidden></div>
    </main>
  </div>
  <script src="<?php echo esc_url($asset('js/mandate-template-editor.js')); ?>"></script>
</body>
</html>
