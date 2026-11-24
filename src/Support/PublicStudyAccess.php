<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Enlaces del estudio de aseguradora (public/estudio.php), que reemplaza la página /estudio-aseguradora/ de WordPress:
 *
 * - url(): enlace firmado para el cliente (correo y WhatsApp) y las copias internas; abre sin sesión. Misma idea de
 *   PublicQuoteAccess, con su propio dominio de firma: el enlace de una tarea o una cotización no abre un estudio.
 * - staffUrl(): para el panel (cronología, listados): `?id=` con la sesión del panel y los permisos del cargo.
 */
final class PublicStudyAccess
{
  public const TTL = 365 * 86400;

  public static function reference(int $id, ?int $expires = null): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Estudio inválido.');
    }
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . self::signature($id, $expires);
  }

  public static function url(int $id): string
  {
    return self::base() . '/estudio.php?e=' . self::reference($id);
  }

  public static function staffUrl(int $id): string
  {
    return self::base() . '/estudio.php?id=' . $id;
  }

  /**
   * Documentos del estudio (asegurable, servicios públicos y documento del arrendatario) para el equipo de entregas: va en
   * `anexos_entrega` del caso de entrega, donde el panel inmobiliario lo abre con "Ver documentos". No vence, como el
   * enlace de Drive que reemplaza, y tiene su propia firma: no abre la vista del estudio.
   */
  public static function documentsUrl(int $id): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Estudio inválido.');
    }
    return self::base() . '/estudio.php?d=' . $id . '.' . self::documentsSignature($id);
  }

  public static function verifyDocuments(string $reference): int
  {
    if (preg_match('/^([1-9][0-9]{0,17})\.([a-f0-9]{64})$/D', $reference, $parts) !== 1) {
      return 0;
    }
    return hash_equals(self::documentsSignature((int) $parts[1]), $parts[2]) ? (int) $parts[1] : 0;
  }

  private static function documentsSignature(int $id): string
  {
    return hash_hmac('sha256', 'public_study_documents_v1|' . $id, (string) SCM_APP_SECRET);
  }

  /** Número del estudio si el enlace es auténtico y está vigente; 0 si no. */
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
    return hash_hmac('sha256', 'public_study_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
