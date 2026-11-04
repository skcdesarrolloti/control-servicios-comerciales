<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Support\StoredFileService;

/**
 * Fotos de Material POP (imagen del producto y evidencias de entregas y ajustes) con el almacenamiento del panel:
 * StoredFileService las reduce a JPG y las sirve por file.php con enlace firmado. Se guardan en las tablas compartidas
 * como URL (evidence_type = 'url'), así dashboard-marketing también las muestra mientras siga en uso.
 * Las pruebas la reemplazan por una que no toca $_FILES.
 */
class PopFiles
{
  public const MAX_EVIDENCE = 5;

  /** Archivos que llegaron en el campo (sin contar los vacíos). */
  public function count(string $field): int
  {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !isset($file['error'])) {
      return 0;
    }
    $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
    return count(array_filter($errors, static fn($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE));
  }

  /**
   * Guarda todas las fotos del campo o ninguna.
   * @return list<string> URLs firmadas.
   */
  public function store(string $field, int $max): array
  {
    $expected = $this->count($field);
    if ($expected === 0) {
      return [];
    }
    if ($expected > $max) {
      throw new \InvalidArgumentException($max === 1 ? 'Adjunta una sola imagen.' : 'Puedes adjuntar máximo ' . $max . ' fotografías.');
    }
    $service = StoredFileService::fromRuntime();
    $urls = array_values($service->storeImages($field, $max));
    if (count($urls) !== $expected) {
      $service->removeImages($urls);
      throw new \InvalidArgumentException('Una de las fotografías no es válida o pesa demasiado. Usa JPG, PNG o WebP.');
    }
    return $urls;
  }

  /** @param list<string> $urls */
  public function remove(array $urls): void
  {
    if ($urls !== []) {
      StoredFileService::fromRuntime()->removeImages($urls);
    }
  }
}
