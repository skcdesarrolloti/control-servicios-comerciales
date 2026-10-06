<?php

/**
 * Login — Control de Servicios Comerciales
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

$wasLoggedIn = \SCM\Core\Auth::isLoggedIn();
$autoLoginOk = \SCM\Core\SignedAutoLogin::attempt($scmAuth, $_GET, $scmConfig);
if (!$wasLoggedIn && $autoLoginOk && isset($_GET['auto_signature'])) {
  header('Location: ' . SCM_BASE_URL . '/index.php', true, 303);
  exit;
}

// Ya autenticado → panel
if (\SCM\Core\Auth::isLoggedIn()) {
  header('Location: ' . SCM_BASE_URL . '/index.php');
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim(sanitize_text_field($_POST['username'] ?? ''));
  $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $rateLimiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
  $ipKey = 'login-v2-ip|' . $clientIp;
  $userKey = 'login-v2-user|' . $clientIp . '|' . strtolower($user);

  $ipRetryAfter = $rateLimiter->retryAfter($ipKey, 30, 900);
  $userRetryAfter = $rateLimiter->retryAfter($userKey, 10, 900);
  $retryAfter = max($ipRetryAfter, $userRetryAfter);
  $withinAttemptLimit = $retryAfter === 0;
  if (!$withinAttemptLimit) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    $minutes = max(1, (int) ceil($retryAfter / 60));
    $error = sprintf(
      'Demasiados intentos fallidos. Intenta nuevamente en %d %s.',
      $minutes,
      $minutes === 1 ? 'minuto' : 'minutos'
    );
  }

  $token  = $_POST['_csrf_token'] ?? '';
  $action = $_POST['_csrf_action'] ?? 'login';

  if ($withinAttemptLimit && !$scmCsrf->verify($action, $token, true)) {
    $error = 'Token de seguridad inválido. Recarga la página.';
  } elseif ($withinAttemptLimit) {
    $pass = $_POST['password'] ?? '';

    if ($scmAuth->attempt($user, $pass)) {
      $rateLimiter->clear($ipKey);
      $rateLimiter->clear($userKey);
      header('Location: ' . SCM_BASE_URL . '/index.php');
      exit;
    }

    $rateLimiter->consume($ipKey, 30, 900);
    $rateLimiter->consume($userKey, 10, 900);
    $error = 'Usuario o contraseña incorrectos.';
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <meta content="web_standard" name="shell-type">
  <title>Iniciar Sesión · SuCasa Inmobiliaria</title>
  <link rel="icon" href="<?php echo esc_url(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo esc_url(SCM_BASE_URL . '/assets/css/tailwind.css?v=' . filemtime(SCM_PUBLIC_PATH . '/assets/css/tailwind.css')); ?>">
</head>

<body class="bg-background min-h-screen flex items-center justify-center p-4 font-body-md text-on-surface antialiased">
  <div class="w-full max-w-md bg-surface-container-lowest p-space-xl rounded-2xl shadow-xl border border-surface-container space-y-space-md">
    <div class="flex items-center gap-space-sm">
      <div class="w-10 h-10 rounded-xl bg-primary-container flex items-center justify-center shadow-sm">
        <span class="material-symbols-outlined text-on-surface text-[24px]">domain</span>
      </div>
      <div>
        <span class="font-headline-sm text-headline-sm text-on-surface tracking-tight block">SuCasa Inmobiliaria</span>
        <span class="font-label-sm text-label-sm text-secondary uppercase font-semibold">Servicios Comerciales</span>
      </div>
    </div>

    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight font-bold">Iniciar sesión</h1>
      <p class="font-body-md text-body-md text-on-surface-variant mt-1">Ingresa con tus credenciales institucionales.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="flex items-center gap-2 p-space-sm rounded-xl bg-error-container text-on-error-container font-label-md text-label-md font-semibold">
        <span class="material-symbols-outlined text-[20px]">warning</span>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="" class="space-y-space-sm pt-space-xs">
      <?php echo $scmCsrf->field('login'); ?>
      <div class="space-y-1">
        <label for="username" class="block font-label-sm text-label-sm text-secondary font-medium">Usuario</label>
        <div class="relative">
          <span class="material-symbols-outlined absolute left-3 top-2.5 text-secondary text-[18px]">person</span>
          <input type="text" id="username" name="username" autocomplete="username" required class="w-full pl-9 pr-3 py-2.5 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none border border-transparent focus:border-outline-variant transition-all shadow-inner">
        </div>
      </div>
      <div class="space-y-1">
        <label for="password" class="block font-label-sm text-label-sm text-secondary font-medium">Contraseña</label>
        <div class="relative">
          <span class="material-symbols-outlined absolute left-3 top-2.5 text-secondary text-[18px]">lock</span>
          <input type="password" id="password" name="password" autocomplete="current-password" required class="w-full pl-9 pr-3 py-2.5 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none border border-transparent focus:border-outline-variant transition-all shadow-inner">
        </div>
      </div>
      <button type="submit" class="w-full mt-space-sm py-3 rounded-xl bg-inverse-surface hover:bg-secondary text-on-secondary font-label-md text-label-md font-semibold transition-all shadow-md cursor-pointer">
        Iniciar Sesión
      </button>
    </form>
  </div>
</body>

</html>
