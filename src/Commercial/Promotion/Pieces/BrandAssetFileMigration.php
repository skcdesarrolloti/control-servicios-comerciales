<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pieces;

use SCM\Core\Database;

/**
 * Copia los archivos del Banco de piezas de dashboard-marketing/storage/branding-assets a la misma ruta de este panel.
 * storage_path es relativo a la raíz del proyecto, así que la base no cambia. Solo copia: nunca borra ni modifica el
 * panel anterior, y volver a correrla omite lo que ya está igual. Cada copia se compara con el original.
 */
final class BrandAssetFileMigration
{
  private Database $db;
  private string $root;
  private string $legacyRoot;
  /** @var resource|null */
  private $output;

  /** @param resource|null $output */
  public function __construct(Database $db, string $root, string $legacyRoot, $output = null)
  {
    $this->db = $db;
    $this->root = rtrim(str_replace('\\', '/', $root), '/');
    $this->legacyRoot = rtrim(str_replace('\\', '/', $legacyRoot), '/');
    $this->output = $output;
  }

  /** @param list<string> $arguments --apply copia; sin él solo muestra qué haría. @return int 1 si algo no se pudo copiar. */
  public function run(array $arguments): int
  {
    $apply = in_array('--apply', $arguments, true);
    $table = $this->db->table('marketing_brand_assets');
    $rows = $this->db->getResults("SELECT id, title, storage_path FROM `{$table}` WHERE storage_path IS NOT NULL AND storage_path <> '' ORDER BY id");
    $this->line(($apply ? 'Copiando' : 'Vista previa (no cambia nada; usa --apply para copiar):') . ' ' . count($rows) . ' piezas desde ' . $this->legacyRoot);
    $copied = 0;
    $ready = 0;
    $failed = 0;
    foreach ($rows as $row) {
      $label = '#' . (int) $row['id'] . ' ' . (string) $row['title'];
      $relative = ltrim(str_replace('\\', '/', (string) $row['storage_path']), '/');
      if (!str_starts_with($relative, BrandAssetFiles::DIRECTORY . '/') || str_contains($relative, '..')) {
        $failed++;
        $this->line('  ✗ ' . $label . ': ruta no válida (' . $relative . ').');
        continue;
      }
      $source = $this->legacyRoot . '/' . $relative;
      $destination = $this->root . '/' . $relative;
      if (!is_file($source)) {
        if (is_file($destination)) {
          $ready++;
          continue;
        }
        $failed++;
        $this->line('  ✗ ' . $label . ': no está en el panel anterior (' . $relative . ').');
        continue;
      }
      if (is_file($destination) && filesize($destination) === filesize($source) && hash_file('sha256', $destination) === hash_file('sha256', $source)) {
        $ready++;
        continue;
      }
      if (!$apply) {
        $copied++;
        $this->line('  → ' . $label . ': se copiaría (' . BrandAssetFiles::sizeLabel((int) filesize($source)) . ').');
        continue;
      }
      if (!$this->copy($source, $destination)) {
        $failed++;
        $this->line('  ✗ ' . $label . ': no se pudo copiar a ' . $destination . '.');
        continue;
      }
      $copied++;
      $this->line('  ✓ ' . $label . '.');
    }
    $this->line(($apply ? 'Copiadas: ' : 'Se copiarían: ') . $copied . '. Ya estaban: ' . $ready . '. Con problemas: ' . $failed . '.');
    return $failed > 0 ? 1 : 0;
  }

  private function copy(string $source, string $destination): bool
  {
    $directory = dirname($destination);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
      return false;
    }
    $temporary = $destination . '.tmp';
    if (!@copy($source, $temporary) || hash_file('sha256', $temporary) !== hash_file('sha256', $source)) {
      @unlink($temporary);
      return false;
    }
    return @rename($temporary, $destination);
  }

  private function line(string $text): void
  {
    if ($this->output !== null) {
      fwrite($this->output, $text . PHP_EOL);
    }
  }
}
