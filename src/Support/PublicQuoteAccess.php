<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Enlace firmado de la cotización de inmuebles pública (public/cotizacion.php): quien lo tiene ve la cotización que le
 * enviaron por correo o WhatsApp. Misma idea de PublicTaskAccess, con su propio dominio de firma: el enlace de una tarea
 * no abre una cotización con el mismo número ni al revés.
 */
final class PublicQuoteAccess
{
  public const TTL = 365 * 86400;

  public static function reference(int $id, ?int $expires = null): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Cotización inválida.');
    }
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . self::signature($id, $expires);
  }

  public static function url(int $id): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/cotizacion.php?c=' . self::reference($id);
  }

  /** El PDF de la cotización con el mismo enlace firmado: lo descarga Meta para el WhatsApp y el cliente desde la vista. */
  public static function pdfUrl(int $id): string
  {
    return self::url($id) . '&formato=pdf';
  }

  /** Número de la cotización si el enlace es auténtico y está vigente; 0 si no. */
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
    return hash_hmac('sha256', 'public_quote_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
