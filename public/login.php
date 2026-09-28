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
  <script src="https://cdn.tailwindcss.com"></script>
  <script id="tailwind-config">
  tailwind.config = {
    "darkMode": "class",
    "theme": {
      "extend": {
        "colors": {
          "surface-container": "#e9edff",
          "tertiary": "#7d5700",
          "on-secondary": "#ffffff",
          "inverse-surface": "#0b2e67",
          "secondary": "#4b5d8c",
          "surface-container-lowest": "#ffffff",
          "on-tertiary-fixed-variant": "#5f4100",
          "tertiary-fixed": "#ffdea9",
          "error": "#ba1a1a",
          "secondary-fixed": "#dae2ff",
          "primary-container": "#f8cf4a",
          "surface-container-low": "#f2f3ff",
          "surface-bright": "#faf8ff",
          "error-container": "#ffdad6",
          "on-tertiary-container": "#785400",
          "surface-dim": "#cdd9ff",
          "on-secondary-fixed-variant": "#334573",
          "on-tertiary": "#ffffff",
          "primary": "#735c00",
          "on-primary": "#ffffff",
          "on-background": "#001944",
          "on-secondary-container": "#415381",
          "surface-container-high": "#e1e8ff",
          "surface-variant": "#d9e2ff",
          "on-error-container": "#93000a",
          "on-tertiary-fixed": "#271900",
          "on-primary-fixed-variant": "#574500",
          "on-error": "#ffffff",
          "surface-tint": "#735c00",
          "on-surface": "#001944",
          "on-primary-container": "#6f5800",
          "outline-variant": "#d0c6ae",
          "on-secondary-fixed": "#021945",
          "secondary-container": "#b6c8fe",
          "surface-container-highest": "#d9e2ff",
          "primary-fixed": "#ffe087",
          "tertiary-fixed-dim": "#fcbb3b",
          "primary-fixed-dim": "#eac23e",
          "inverse-primary": "#eac23e",
          "on-surface-variant": "#4d4635",
          "tertiary-container": "#ffcb6f",
          "secondary-fixed-dim": "#b3c5fb",
          "inverse-on-surface": "#edf0ff",
          "surface": "#faf8ff",
          "outline": "#7f7662",
          "on-primary-fixed": "#231a00",
          "background": "#faf8ff"
        },
        "borderRadius": {
          "DEFAULT": "0.25rem",
          "lg": "0.5rem",
          "xl": "0.75rem",
          "full": "9999px"
        },
        "spacing": {
          "space-xl": "2.5rem",
          "margin-mobile": "1rem",
          "space-lg": "1.5rem",
          "space-xs": "0.25rem",
          "gutter": "1.5rem",
          "space-sm": "0.5rem",
          "margin": "2rem",
          "gutter-mobile": "1rem",
          "space-md": "1rem"
        },
        "fontFamily": {
          "label-md": ["Poppins"],
          "headline-md": ["Poppins"],
          "label-lg": ["Poppins"],
          "headline-lg": ["Poppins"],
          "display-lg": ["Poppins"],
          "headline-xl": ["Poppins"],
          "body-lg": ["Poppins"],
          "label-sm": ["Poppins"],
          "body-sm": ["Poppins"],
          "title-md": ["Poppins"],
          "display-lg-mobile": ["Poppins"],
          "headline-sm": ["Poppins"],
          "headline-xl-mobile": ["Poppins"],
          "body-md": ["Poppins"]
        },
        "fontSize": {
          "label-md": ["12px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "500" }],
          "headline-md": ["22px", { "lineHeight": "30px", "letterSpacing": "-0.005em", "fontWeight": "600" }],
          "label-lg": ["14px", { "lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600" }],
          "headline-lg": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
          "display-lg": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
          "headline-xl": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }],
          "body-lg": ["16px", { "lineHeight": "26px", "fontWeight": "400" }],
          "label-sm": ["10px", { "lineHeight": "14px", "letterSpacing": "0.04em", "fontWeight": "600" }],
          "body-sm": ["12px", { "lineHeight": "18px", "fontWeight": "400" }],
          "title-md": ["16px", { "lineHeight": "24px", "fontWeight": "500" }],
          "display-lg-mobile": ["32px", { "lineHeight": "40px", "letterSpacing": "-0.01em", "fontWeight": "700" }],
          "headline-sm": ["18px", { "lineHeight": "26px", "fontWeight": "600" }],
          "headline-xl-mobile": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
          "body-md": ["14px", { "lineHeight": "22px", "fontWeight": "400" }]
        }
      }
    }
  };
  </script>
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
