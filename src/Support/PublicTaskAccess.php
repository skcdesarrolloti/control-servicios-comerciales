<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Enlace firmado de la vista pública de una tarea comercial (public/tarea.php): quien lo tiene puede ver su solicitud y
 * escribir. Misma idea de control-servicios-inmobiliarios src/Support/PublicCaseAccess.php, con su propio dominio de firma:
 * un enlace de un panel no sirve en el otro.
 */
final class PublicTaskAccess
{
  public const TTL = 90 * 86400;

  public static function reference(int $id, ?int $expires = null): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Tarea inválida.');
    }
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . self::signature($id, $expires);
  }

  public static function url(int $id): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/tarea.php?t=' . self::reference($id);
  }

  /** Número de la tarea si el enlace es auténtico y está vigente; 0 si no. */
  public static function verify(string $reference): int
  {
    if (preg_match('/^([1-9][0-9]{0,17})\.([0-9]{10})\.([a-f0-9]{64})$/D', $reference, $parts) !== 1) {
      return 0;
    }
    $id = (int) $parts[1];
    $expires = (int) $parts[2];
    return $expires > time() && hash_equals(self::signature($id, $expires), $parts[3]) ? $id : 0;
  }

  private static function signature(int $id, int $expires): string
  {
    return hash_hmac('sha256', 'public_task_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
