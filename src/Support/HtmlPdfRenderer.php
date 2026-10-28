<?php
declare(strict_types=1);

namespace SCM\Support;

/**
 * Convierte HTML a PDF con Chromium, igual que las actas del panel inmobiliario: primero el servicio compatible con
 * Gotenberg del VPS (SCM_GOTENBERG_URL, SCM_GOTENBERG_USERNAME, SCM_GOTENBERG_PASSWORD) y, si no está, un Chromium
 * local (SCM_CHROMIUM_BIN o rutas estándar). Sin ninguno devuelve null: no hay respaldo con otro diseño.
 */
final class HtmlPdfRenderer
{
  public static function render(string $content, string $title, array $options = []): ?string
  {
    $browser = self::browserPath();
    $remoteUrl = trim((string) getenv('SCM_GOTENBERG_URL'));
    $cssPaths = (array) ($options['stylesheets'] ?? []);
    if (($browser === null && $remoteUrl === '') || array_filter($cssPaths, static fn(string $path): bool => !is_readable($path))) {
      return null;
    }

    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/scm-pdf-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
      return null;
    }
    $htmlPath = $directory . '/document.html';
    $pdfPath = $directory . '/document.pdf';
    $base = htmlspecialchars(rtrim((string) SCM_BASE_URL, '/') . '/', ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $css = implode("\n", array_map(static fn(string $path): string => (string) file_get_contents($path), $cssPaths));
    // The hosted Chromium may have no system fonts. Send both body and signature
    // fonts with the document rather than relying on Arial or remote font requests.
    foreach (['noto-sans.ttf', 'caveat.ttf'] as $fontName) {
      $reference = '../fonts/' . $fontName;
      if (!str_contains($css, $reference)) continue;
      $fontPath = dirname(__DIR__, 2) . '/public/assets/fonts/' . $fontName;
      if (!is_readable($fontPath)) throw new \RuntimeException('Falta una fuente requerida para el PDF: ' . $fontName);
      $css = str_replace($reference, 'data:font/ttf;base64,' . base64_encode((string) file_get_contents($fontPath)), $css);
    }
    $bodyClass = htmlspecialchars((string) ($options['body_class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $wrapperClass = htmlspecialchars((string) ($options['wrapper_class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $containerClass = htmlspecialchars((string) ($options['container_class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><base href="' . $base . '"><title>' . $safeTitle . '</title>'
      . (($options['external_fonts'] ?? true) ? '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">' : '')
      . '<style>' . $css . '</style></head><body class="' . $bodyClass . '"><main class="' . $containerClass . '"><div class="' . $wrapperClass . '">' . $content . '</div></main></body></html>';

    try {
      if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
        return null;
      }
      if ($remoteUrl !== '') {
        // El servicio remoto responde 503 cuando su cola está llena: se reintenta antes de usar Chromium local.
        for ($attempt = 0; $attempt < 2; $attempt++) {
          $remotePdf = self::renderWithGotenberg($htmlPath, $remoteUrl, $remoteStatus);
          if ($remotePdf !== null) {
            return $remotePdf;
          }
          if ($remoteStatus !== 503) break;
          sleep(3);
        }
      }
      if ($browser === null || !function_exists('proc_open')) {
        return null;
      }
      // Un solo Chromium local a la vez por servidor: cada uno abre varios procesos y en el hosting
      // cuentan contra el límite de procesos. Los demás esperan su turno (hasta ~40 s).
      $lock = @fopen(sys_get_temp_dir() . '/scm-chromium-render.lock', 'c');
      $locked = false;
      if ($lock !== false) {
        for ($waited = 0; $waited < 40 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $waited++) sleep(1);
      }
      if (!$locked) {
        if ($lock !== false) fclose($lock);
        return null;
      }
      $command = [
        $browser, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run', '--no-zygote',
        '--disable-extensions', '--disable-background-networking', '--disable-breakpad', '--disable-crash-reporter',
        '--renderer-process-limit=1', '--disable-features=site-per-process,Translate,MediaRouter',
        '--allow-file-access-from-files', '--no-pdf-header-footer',
        '--virtual-time-budget=10000', '--user-data-dir=' . $directory . '/profile',
        '--print-to-pdf=' . $pdfPath, 'file:///' . str_replace('\\', '/', ltrim($htmlPath, '/')),
      ];
      $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (!is_resource($process)) {
        return null;
      }
      fclose($pipes[0]);
      stream_set_blocking($pipes[1], false);
      stream_set_blocking($pipes[2], false);
      $deadline = microtime(true) + 35;
      do {
        $status = proc_get_status($process);
        if (!$status['running']) {
          break;
        }
        usleep(100000);
      } while (microtime(true) < $deadline);
      if ($status['running']) {
        // SIGKILL: un SIGTERM puede dejar vivos a los procesos hijos de Chromium.
        proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
      }
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);
      $bytes = is_file($pdfPath) ? file_get_contents($pdfPath) : false;
      return is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : null;
    } finally {
      if (isset($lock) && $lock !== false) {
        if (!empty($locked)) flock($lock, LOCK_UN);
        fclose($lock);
      }
      if (is_dir($directory . '/profile')) {
        $files = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($directory . '/profile', \FilesystemIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
          $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
      }
      @unlink($htmlPath);
      @unlink($pdfPath);
      @rmdir($directory . '/profile');
      @rmdir($directory);
    }
  }

  private static function renderWithGotenberg(string $htmlPath, string $baseUrl, ?int &$status = null): ?string
  {
    $status = 0;
    if (!str_starts_with($baseUrl, 'https://') || !function_exists('curl_init')) {
      return null;
    }
    $url = rtrim($baseUrl, '/') . '/forms/chromium/convert/html';
    $curl = curl_init($url);
    if ($curl === false) {
      return null;
    }
    $username = trim((string) getenv('SCM_GOTENBERG_USERNAME'));
    $password = (string) getenv('SCM_GOTENBERG_PASSWORD');
    $options = [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => [
        'files' => new \CURLFile($htmlPath, 'text/html', 'index.html'),
        'preferCssPageSize' => 'true',
        'printBackground' => 'true',
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 75,
      CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
    ];
    if ($username !== '' && $password !== '') {
      $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
      $options[CURLOPT_USERPWD] = $username . ':' . $password;
    }
    curl_setopt_array($curl, $options);
    $bytes = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $status === 200 && is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : null;
  }

  private static function browserPath(): ?string
  {
    $configured = trim((string) getenv('SCM_CHROMIUM_BIN'));
    $paths = array_filter([
      $configured,
      'C:/Program Files/Google/Chrome/Application/chrome.exe',
      'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
      '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    ]);
    foreach ($paths as $path) {
      if (is_file($path) && is_executable($path)) {
        return $path;
      }
    }
    return null;
  }
}
