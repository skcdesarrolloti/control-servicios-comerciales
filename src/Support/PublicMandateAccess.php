<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Enlaces del contrato de mandato (public/mandato.php), para que el propietario lo revise antes de la firma:
 *
 * - url(): enlace firmado para el propietario (correo y WhatsApp); abre sin sesión y le deja dejar observaciones o
 *   aprobar. Su propio dominio de firma: el enlace de una tarea, una cotización o un estudio no abre un mandato.
 * - staffUrl(): para el equipo (panel y listado de mandatos de WordPress mientras se migra): `?id=` con la sesión del
 *   panel y los permisos del cargo.
 */
final class PublicMandateAccess
{
  public const TTL = 120 * 86400;

  public static function reference(int $id, ?int $expires = null): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Mandato inválido.');
    }
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . self::signature($id, $expires);
  }

  public static function url(int $id): string
  {
    return self::base() . '/mandato.php?m=' . self::reference($id);
  }

  public static function staffUrl(int $id): string
  {
    return self::base() . '/mandato.php?id=' . $id;
  }

  /** Número del mandato si el enlace es auténtico y está vigente; 0 si no. */
  public static function verify(string $reference): int
  {
    if (preg_match('/^([1-9][0-9]{0,17})\.([0-9]{10})\.([a-f0-9]{64})$/D', $reference, $parts) !== 1) {
      return 0;
    }
    $id = (int) $parts[1];
    $expires = (int) $parts[2];
    return $expires > time() && hash_equals(self::signature($id, $expires), $parts[3]) ? $id : 0;
  }

  private static function base(): string
  {
    return rtrim(defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : '', '/');
  }

  private static function signature(int $id, int $expires): string
  {
    return hash_hmac('sha256', 'public_mandate_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
