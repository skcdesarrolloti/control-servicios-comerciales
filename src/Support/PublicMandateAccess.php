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
 * - staffLink(): enlace individual firmado para un funcionario (WhatsApp internos): index.php?mandato=REF inicia su
 *   sesión y abre el mandato en el panel, como StaffTaskLink con las tareas. Solo en mensajes a una persona.
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

  /** Referencia firmada que ata el mandato al funcionario (id.vence.id_empleado.firma). */
  public static function staffReference(int $id, string $employeeId, ?int $expires = null): string
  {
    $employeeId = trim($employeeId);
    if ($id < 1 || preg_match('/^[0-9A-Za-z_-]{1,40}$/D', $employeeId) !== 1) {
      throw new \InvalidArgumentException('Mandato o funcionario inválido.');
    }
    $expires ??= time() + StaffTaskLink::TTL;
    return $id . '.' . $expires . '.' . $employeeId . '.' . self::staffSignature($id, $expires, $employeeId);
  }

  public static function staffLink(int $id, string $employeeId): string
  {
    return self::base() . '/index.php?mandato=' . self::staffReference($id, $employeeId);
  }

  /** @return array{id:int,employee:string}|null Mandato y funcionario si la firma es auténtica y está vigente. */
  public static function verifyStaff(string $reference): ?array
  {
    if (preg_match('/^([1-9][0-9]{0,17})\.([0-9]{10})\.([0-9A-Za-z_-]{1,40})\.([a-f0-9]{64})$/D', $reference, $parts) !== 1) {
      return null;
    }
    $id = (int) $parts[1];
    $expires = (int) $parts[2];
    return $expires > time() && hash_equals(self::staffSignature($id, $expires, $parts[3]), $parts[4]) ? ['id' => $id, 'employee' => $parts[3]] : null;
  }

  /**
   * A dónde mandar a quien abre ?mandato=REF (null si la dirección no lo trae): con sesión o con un enlace válido (que
   * inicia la sesión del funcionario), al panel con el mandato abierto; si venció, al login y de ahí al mandato.
   * @param array<string,mixed> $query
   */
  public static function staffRedirect(\SCM\Core\Auth $auth, array $query): ?string
  {
    $reference = is_string($query['mandato'] ?? null) ? trim($query['mandato']) : '';
    if (preg_match('/^([1-9][0-9]{0,17})\./', $reference, $match) !== 1) {
      return null;
    }
    $open = self::base() . '/index.php?tab=mandatos&abrir_mandato=' . (int) $match[1];
    if (\SCM\Core\Auth::isLoggedIn()) {
      return $open;
    }
    $link = self::verifyStaff($reference);
    if ($link !== null && $auth->loginByEmployeeId($link['employee'])) {
      return $open;
    }
    return StaffTaskLink::loginUrl(['tab' => 'mandatos', 'abrir_mandato' => $match[1]]);
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

  private static function staffSignature(int $id, int $expires, string $employeeId): string
  {
    return hash_hmac('sha256', 'staff_mandate_v1|' . $id . '|' . $expires . '|' . $employeeId, (string) SCM_APP_SECRET);
  }

  private static function signature(int $id, int $expires): string
  {
    return hash_hmac('sha256', 'public_mandate_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
