<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Trae al panel las fotos de productos que se subieron desde dashboard-marketing (public/uploads/inventory-products),
 * para poder apagar ese panel sin perderlas (php bin/pop-migrate-images.php).
 *
 * Por cada producto con una foto del panel anterior: toma el archivo de la carpeta de dashboard-marketing (o, si no
 * está en este servidor, lo descarga de su dirección pública), lo guarda en el almacenamiento del panel (JPG o PNG, como
 * los sirve file.php), cambia image_url por el enlace firmado y, si el producto se sincroniza con el Club PPH, también
 * su img en jet_cct_inventario_pph. Cada cambio queda en Settings (KEY) para revertirlo. Los archivos del panel anterior
 * no se tocan.
 */
final class PopImageMigration
{
  public const KEY = 'commercial_pop_image_migration';
  public const MARKER = '/uploads/inventory-products/';
  private const MAX_BYTES = 10485760;

  private Database $db;
  private Settings $settings;
  private string $uploadDir;
  /** @var \Closure(string):string Nombre del archivo guardado => enlace firmado. */
  private \Closure $urlFor;
  private string $legacyRoot;
  /** @var \Closure(string):?string Descarga una URL (null si falla). */
  private \Closure $fetch;
  /** @var resource */
  private $out;

  /**
   * @param \Closure(string):string $urlFor
   * @param \Closure(string):?string|null $fetch
   * @param resource $out
   */
  public function __construct(Database $db, Settings $settings, string $uploadDir, \Closure $urlFor, string $legacyRoot, ?\Closure $fetch, $out)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->uploadDir = rtrim($uploadDir, '/\\');
    $this->urlFor = $urlFor;
    $this->legacyRoot = rtrim($legacyRoot, '/\\');
    $this->fetch = $fetch ?? static function (string $url): ?string {
      $context = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1], 'ssl' => ['verify_peer' => true]]);
      $data = @file_get_contents($url, false, $context, 0, self::MAX_BYTES + 1);
      return is_string($data) && $data !== '' ? $data : null;
    };
    $this->out = $out;
  }

  /** @param list<string> $arguments --apply | --revert (sin nada: vista previa) */
  public static function writes(array $arguments): bool
  {
    return in_array('--apply', $arguments, true) || in_array('--revert', $arguments, true);
  }

  /** @param list<string> $arguments */
  public function run(array $arguments): int
  {
    if (in_array('--revert', $arguments, true)) {
      return $this->revert();
    }
    $apply = in_array('--apply', $arguments, true);
    $products = $this->db->table('marketing_inventory_products');
    $rows = $this->db->getResults("SELECT id, name, image_url, sync_pph, pph_product_id FROM `{$products}` WHERE image_url LIKE ? ORDER BY id", ['%' . self::MARKER . '%']);
    $this->line(($apply ? 'Migrando' : 'Vista previa (no cambia nada; usa --apply para migrar):') . ' ' . count($rows) . ' productos con foto del panel de marketing.');
    $log = $this->log();
    $migrated = 0;
    $failed = 0;
    foreach ($rows as $row) {
      $label = '#' . $row['id'] . ' ' . $row['name'];
      $data = $this->source((string) $row['image_url'], $origin);
      if ($data === null) {
        $failed++;
        $this->line('  ✗ ' . $label . ': no se encontró el archivo ni se pudo descargar (' . $row['image_url'] . ').');
        continue;
      }
      $image = self::normalize($data);
      if ($image === null) {
        $failed++;
        $this->line('  ✗ ' . $label . ': el archivo no es una imagen válida.');
        continue;
      }
      if (!$apply) {
        $migrated++;
        $this->line('  → ' . $label . ': se copiaría desde ' . $origin . ' (' . strtoupper($image['ext']) . ', ' . round(strlen($image['bytes']) / 1024) . ' KB).');
        continue;
      }
      $name = bin2hex(random_bytes(12)) . '_' . time() . '.' . $image['ext'];
      if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0750, true) && !is_dir($this->uploadDir)) {
        throw new \RuntimeException('No se pudo crear la carpeta de archivos del panel: ' . $this->uploadDir);
      }
      if (@file_put_contents($this->uploadDir . '/' . $name, $image['bytes']) === false) {
        throw new \RuntimeException('No se pudo guardar ' . $name . ' en ' . $this->uploadDir);
      }
      $url = ($this->urlFor)($name);
      $entry = ['id' => (int) $row['id'], 'old' => (string) $row['image_url'], 'new' => $url, 'file' => $name, 'pph_id' => null, 'pph_old' => null, 'at' => date('Y-m-d H:i:s')];
      $this->db->update($products, ['image_url' => $url], ['id' => (int) $row['id']]);
      if ((int) $row['sync_pph'] === 1 && (int) $row['pph_product_id'] > 0) {
        $entry = array_merge($entry, $this->updatePph((int) $row['id'], (int) $row['pph_product_id'], $url));
      }
      $log[] = $entry;
      // Se guarda después de cada producto: si algo falla a la mitad, lo ya migrado se puede revertir.
      $this->settings->set(self::KEY, $log);
      $migrated++;
      $this->line('  ✓ ' . $label . ': copiada desde ' . $origin . '.');
    }
    $this->line(($apply ? 'Migradas: ' : 'Se migrarían: ') . $migrated . '. Con problemas: ' . $failed . '.' . ($apply && $migrated > 0 ? ' Para deshacer: php bin/pop-migrate-images.php --revert' : ''));
    return $failed > 0 ? 1 : 0;
  }

  /** Devuelve cada producto a su foto anterior y borra las copias. */
  private function revert(): int
  {
    $log = $this->log();
    if ($log === []) {
      $this->line('No hay migraciones de fotos para revertir.');
      return 0;
    }
    $products = $this->db->table('marketing_inventory_products');
    foreach (array_reverse($log) as $entry) {
      $current = (string) $this->db->getVar("SELECT image_url FROM `{$products}` WHERE id = ?", [(int) $entry['id']]);
      if ($current !== (string) $entry['new']) {
        // Alguien cambió la foto después de migrarla: se respeta la nueva.
        $this->line('  · #' . $entry['id'] . ': la foto cambió después de migrarla; se deja como está.');
      } else {
        $this->db->update($products, ['image_url' => (string) $entry['old']], ['id' => (int) $entry['id']]);
        if ($entry['pph_id'] !== null) {
          $this->updatePph((int) $entry['id'], (int) $entry['pph_id'], (string) $entry['pph_old']);
        }
        @unlink($this->uploadDir . '/' . basename((string) $entry['file']));
        $this->line('  ↺ #' . $entry['id'] . ': vuelve a la foto del panel de marketing.');
      }
    }
    $this->settings->set(self::KEY, []);
    $this->line('Revertido.');
    return 0;
  }

  /** @return array{pph_id:int,pph_old:string} */
  private function updatePph(int $productId, int $pphId, string $url): array
  {
    $table = $this->db->table('jet_cct_inventario_pph');
    $old = (string) $this->db->getVar("SELECT img FROM `{$table}` WHERE _ID = ?", [$pphId]);
    $this->db->update($table, ['img' => $url], ['_ID' => $pphId]);
    $source = $this->db->getRow("SELECT * FROM `{$table}` WHERE _ID = ?", [$pphId]);
    if ($source !== null) {
      // Con el hash al día la sincronización no lo toma como un cambio hecho en PPH.
      $this->db->update($this->db->table('marketing_inventory_products'), ['pph_hash' => PopInventoryService::pphHash($source)], ['id' => $productId]);
    }
    return ['pph_id' => $pphId, 'pph_old' => $old];
  }

  /** Contenido de la foto: primero la carpeta de dashboard-marketing, si no, su dirección pública. */
  private function source(string $url, ?string &$origin): ?string
  {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $position = strpos($path, self::MARKER);
    $relative = $position === false ? '' : substr($path, $position + strlen(self::MARKER));
    if ($relative !== '' && preg_match('#^[0-9]{4}/[0-9]{2}/[A-Za-z0-9_.-]+$#', $relative) === 1) {
      $file = $this->legacyRoot . '/public/uploads/inventory-products/' . $relative;
      if (is_file($file) && filesize($file) <= self::MAX_BYTES) {
        $origin = 'la carpeta del panel de marketing';
        return (string) file_get_contents($file);
      }
    }
    if (!str_starts_with(strtolower($url), 'https://') && !str_starts_with(strtolower($url), 'http://')) {
      return null;
    }
    $origin = 'su dirección pública';
    $data = ($this->fetch)($url);
    return $data !== null && strlen($data) <= self::MAX_BYTES ? $data : null;
  }

  /**
   * JPG y PNG se guardan tal cual; WebP y otros formatos se convierten a JPG (file.php solo sirve JPG, PNG, PDF y MP4).
   * @return array{bytes:string,ext:string}|null
   */
  public static function normalize(string $data): ?array
  {
    $info = @getimagesizefromstring($data);
    if ($info === false) {
      return null;
    }
    if ($info['mime'] === 'image/jpeg') {
      return ['bytes' => $data, 'ext' => 'jpg'];
    }
    if ($info['mime'] === 'image/png') {
      return ['bytes' => $data, 'ext' => 'png'];
    }
    $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
    if ($image === false) {
      return null;
    }
    ob_start();
    imagejpeg($image, null, 85);
    imagedestroy($image);
    $jpg = (string) ob_get_clean();
    return $jpg !== '' ? ['bytes' => $jpg, 'ext' => 'jpg'] : null;
  }

  /** @return list<array<string,mixed>> */
  private function log(): array
  {
    $log = $this->settings->get(self::KEY, []);
    return is_array($log) ? array_values(array_filter($log, 'is_array')) : [];
  }

  private function line(string $text): void
  {
    fwrite($this->out, $text . PHP_EOL);
  }
}
