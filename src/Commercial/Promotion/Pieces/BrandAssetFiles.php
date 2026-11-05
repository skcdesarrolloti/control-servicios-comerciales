<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pieces;

/**
 * Archivos del Banco de piezas. Copia de dashboard-marketing app/BrandAssetService.php (storeUpload, streamFile y
 * removeStoredPath) para mantener las mismas reglas: lista cerrada de extensiones, 50 MB, nombre aleatorio y MIME fijado
 * por la extensión. Viven fuera de public/, en storage/branding-assets/AAAA/MM, y storage_path guarda la ruta relativa
 * a la raíz del proyecto (igual que en ese panel): solo salen por public/piece-file.php, que valida la sesión y el permiso.
 * Las pruebas reemplazan received() y move(), porque is_uploaded_file() solo acepta subidas reales.
 */
class BrandAssetFiles
{
  public const MAX_BYTES = 52428800;
  public const DIRECTORY = 'storage/branding-assets';
  public const ACCEPT = '.jpg,.jpeg,.png,.webp,.gif,.svg,.pdf,.mp4,.webm,.mov,.ppt,.pptx,.psd,.ai,.eps';
  private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'mp4', 'webm', 'mov', 'ppt', 'pptx', 'psd', 'ai', 'eps'];
  // Tipos que el navegador muestra en línea (MIME fijo por extensión). El resto se entrega como descarga.
  private const INLINE_MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
  ];
  private const RASTER = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
  // SVG puede llevar scripts: solo como descarga (las etiquetas <img> lo siguen mostrando).
  private const DOWNLOAD_MIME = ['svg' => 'image/svg+xml'];

  private string $root;

  public function __construct(string $root)
  {
    $this->root = rtrim(str_replace('\\', '/', $root), '/');
  }

  /** Los de este panel. SCM_PIECES_ROOT solo lo definen los fixtures, para no escribir en storage/. */
  public static function runtime(): self
  {
    return new self(defined('SCM_PIECES_ROOT') ? (string) constant('SCM_PIECES_ROOT') : (defined('SCM_ROOT') ? (string) constant('SCM_ROOT') : dirname(__DIR__, 4)));
  }

  /** El menor entre 50 MB y lo que acepta este servidor: si el archivo lo supera, PHP descarta toda la petición. */
  public static function maxBytes(): int
  {
    return min(self::MAX_BYTES, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
  }

  private static function iniBytes(string $name): int
  {
    $value = trim((string) ini_get($name));
    $number = (int) $value;
    if ($number <= 0) {
      return self::MAX_BYTES;
    }
    return $number * match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
  }

  public static function sizeLabel(int $bytes): string
  {
    if ($bytes >= 1048576) {
      return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return $bytes >= 1024 ? number_format($bytes / 1024, 0, ',', '.') . ' KB' : $bytes . ' B';
  }

  /**
   * Guarda el archivo del campo. null si no llegó ninguno.
   * @param array<string,mixed> $file Una entrada de $_FILES.
   * @return array{storage_path:string,original_name:string,mime_type:string,file_size:int}|null
   */
  public function store(array $file): ?array
  {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
      return null;
    }
    $limit = self::sizeLabel(self::maxBytes());
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
      throw new \InvalidArgumentException('El archivo debe pesar máximo ' . $limit . '.');
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $temporary === '' || !$this->received($temporary)) {
      throw new \InvalidArgumentException('La carga del archivo no se completó. Intenta nuevamente.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > self::MAX_BYTES) {
      throw new \InvalidArgumentException('El archivo debe pesar máximo ' . $limit . '.');
    }
    $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'pieza')));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, self::EXTENSIONS, true)) {
      throw new \InvalidArgumentException('Formato no permitido. Usa imagen, PDF, video o archivo de diseño compatible.');
    }
    $mime = self::INLINE_MIME[$extension] ?? self::DOWNLOAD_MIME[$extension] ?? ((new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: 'application/octet-stream');
    $relative = self::DIRECTORY . '/' . date('Y/m');
    $directory = $this->root . '/' . $relative;
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
      throw new \RuntimeException('No fue posible preparar la carpeta de archivos del Banco de piezas.');
    }
    $name = bin2hex(random_bytes(18)) . '.' . $extension;
    if (!$this->move($temporary, $directory . '/' . $name)) {
      throw new \RuntimeException('No fue posible guardar el archivo.');
    }
    return ['storage_path' => $relative . '/' . $name, 'original_name' => mb_substr($original, 0, 255, 'UTF-8'), 'mime_type' => substr($mime, 0, 120), 'file_size' => $size];
  }

  protected function received(string $temporary): bool
  {
    return is_uploaded_file($temporary);
  }

  protected function move(string $temporary, string $destination): bool
  {
    return move_uploaded_file($temporary, $destination);
  }

  /** Ruta del archivo solo si queda dentro de la carpeta de piezas. */
  public function path(string $stored): ?string
  {
    $root = realpath($this->root . '/' . self::DIRECTORY);
    $path = $stored !== '' ? realpath($this->root . '/' . ltrim(str_replace('\\', '/', $stored), '/')) : false;
    return $root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
  }

  public function remove(string $stored): void
  {
    $path = $this->path($stored);
    if ($path !== null) {
      @unlink($path);
    }
  }

  /**
   * Encabezados de la descarga. El MIME sale de la extensión con que se guardó, nunca de lo enviado al subirlo.
   * @return list<string>
   */
  public static function headers(string $path, string $originalName, bool $download): array
  {
    $name = str_replace(["\r", "\n", '"', '\\'], '', $originalName !== '' ? $originalName : 'pieza');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inlineMime = self::INLINE_MIME[$extension] ?? null;
    $inline = !$download && $inlineMime !== null;
    $headers = ['Content-Type: ' . ($inlineMime ?? self::DOWNLOAD_MIME[$extension] ?? 'application/octet-stream'), 'Content-Length: ' . (string) filesize($path), 'X-Content-Type-Options: nosniff', 'Cache-Control: private, max-age=3600'];
    if (!$inline) {
      $headers[] = "Content-Security-Policy: sandbox; default-src 'none'";
    } elseif (in_array($extension, self::RASTER, true)) {
      $headers[] = 'Content-Security-Policy: sandbox';
    }
    $headers[] = 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"';
    return $headers;
  }
}
