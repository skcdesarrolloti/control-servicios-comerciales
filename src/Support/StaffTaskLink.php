<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Auth;

/**
 * Enlaces del panel a una tarea comercial para funcionarios (correos y WhatsApp internos).
 *
 * - openUrl(): abre el panel con la tarea en su ventana (?abrir_tarea=), sin iniciar sesión.
 * - url($id, $idEmpleado): enlace individual firmado que además inicia la sesión de ese funcionario. Misma idea de
 *   control-servicios-inmobiliarios src/Support/PublicCaseAccess.php (staffUrl), con su propio dominio de firma.
 *   Enviarlo solo en mensajes a una persona, nunca en correos con copias.
 *
 * El enlace firmado va en ?ticket_id= para que las plantillas de WhatsApp ya aprobadas (que terminan en ticket_id={{1}})
 * lo lleven sin cambios: public/index.php lo reconoce con redirectFor() y lo cambia por openUrl().
 */
final class StaffTaskLink
{
  public const TTL = 30 * 86400;
  private const EMPLOYEE = '[0-9A-Za-z_-]{1,40}';
  /** Lo único que se conserva al pasar por el login (?next=): nunca una dirección completa. */
  private const NEXT_KEYS = ['tab', 'ticket_id', 'abrir_tarea', 'abrir_mandato'];

  /** Referencia firmada que ata la tarea al funcionario; sin id_empleado válido queda el número de la tarea. */
  public static function reference(int $id, string $employeeId, ?int $expires = null): string
  {
    if ($id < 1) {
      throw new \InvalidArgumentException('Tarea inválida.');
    }
    $employeeId = trim($employeeId);
    if (preg_match('/^' . self::EMPLOYEE . '$/D', $employeeId) !== 1) {
      return (string) $id;
    }
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . $employeeId . '.' . self::signature($id, $expires, $employeeId);
  }

  /** Enlace individual con inicio de sesión; sin funcionario, el que solo abre la tarea. */
  public static function url(int $id, string $employeeId = ''): string
  {
    $reference = self::reference($id, $employeeId);
    return $reference === (string) $id ? self::openUrl($id) : self::base() . '/index.php?tab=abiertos&ticket_id=' . $reference;
  }

  /**
   * Base a la que se agrega el número de la tarea para abrirla en el panel (código heredado del panel inmobiliario que arma
   * el enlace por partes). Reemplaza la página de tickets anterior, que ya no existe.
   */
  public static function openBase(): string
  {
    return self::base() . '/index.php?tab=abiertos&abrir_tarea=';
  }

  public static function openUrl(int $id): string
  {
    return self::base() . '/index.php?tab=abiertos&ticket_id=' . $id . '&abrir_tarea=' . $id;
  }

  /** @return array{id:int,employee:string}|null Tarea y funcionario si la firma es auténtica y está vigente. */
  public static function verify(string $reference): ?array
  {
    $parts = self::parse($reference);
    if ($parts === null || $parts['expires'] <= time() || !hash_equals(self::signature($parts['id'], $parts['expires'], $parts['employee']), $parts['signature'])) {
      return null;
    }
    return ['id' => $parts['id'], 'employee' => $parts['employee']];
  }

  /**
   * A dónde mandar a quien abre un enlace firmado (?ticket_id=REF), o null si la dirección no trae uno.
   * Con sesión abre la tarea; sin sesión, un enlace válido inicia la del funcionario y uno vencido pasa por el login.
   * @param array<string,mixed> $query
   */
  public static function redirectFor(Auth $auth, array $query): ?string
  {
    $parts = self::parse(is_string($query['ticket_id'] ?? null) ? $query['ticket_id'] : '');
    if ($parts === null) {
      return null;
    }
    if (Auth::isLoggedIn()) {
      return self::openUrl($parts['id']);
    }
    $link = self::verify((string) $query['ticket_id']);
    if ($link !== null && $auth->loginByEmployeeId($link['employee'])) {
      return self::openUrl($link['id']);
    }
    // Por qué no entró: queda en el log del servidor y el login lo dice (sin datos de la firma).
    $reason = $parts['expires'] <= time() ? 'vencido' : ($link === null ? 'firma' : 'funcionario');
    error_log(sprintf('[staff-task-link] tarea %d, funcionario %s: no se inició sesión (%s)', $parts['id'], $parts['employee'], $reason));
    return self::loginUrl(['tab' => 'abiertos', 'ticket_id' => (string) $parts['id'], 'abrir_tarea' => (string) $parts['id']]) . '&enlace=' . $reason;
  }

  /** Mensaje del login cuando un enlace de aviso no pudo iniciar la sesión (?enlace=). */
  public static function failureMessage(string $reason): string
  {
    return [
      'vencido' => 'El enlace de la tarea venció. Ingresa y se abrirá la tarea.',
      'firma' => 'El enlace de la tarea no es válido (la clave APP_SECRET del panel cambió después de enviarlo). Ingresa y se abrirá la tarea.',
      'funcionario' => 'No se pudo iniciar tu sesión con el enlace: tu usuario no aparece activo en Funcionarios. Ingresa con tu usuario y contraseña.',
    ][$reason] ?? '';
  }

  /**
   * Login que, al entrar, vuelve a la misma tarea o pestaña.
   * @param array<string,mixed> $query
   */
  public static function loginUrl(array $query): string
  {
    $next = self::nextQuery($query);
    return self::base() . '/login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
  }

  /** Panel al que vuelve el login: solo pestaña y tarea, con valores simples; cualquier otra cosa lleva al inicio. */
  public static function nextUrl(string $next): string
  {
    parse_str(ltrim($next, '?'), $query);
    $clean = self::nextQuery($query);
    return self::base() . '/index.php' . ($clean !== '' ? '?' . $clean : '');
  }

  /** @param array<mixed> $query */
  private static function nextQuery(array $query): string
  {
    $keep = [];
    foreach (self::NEXT_KEYS as $key) {
      $value = $query[$key] ?? null;
      $pattern = $key === 'tab' ? '/^[a-z_]{1,40}$/D' : '/^[1-9][0-9]{0,17}$/D';
      if (is_string($value) && preg_match($pattern, $value) === 1) {
        $keep[$key] = $value;
      }
    }
    return http_build_query($keep, '', '&', PHP_QUERY_RFC3986);
  }

  /** @return array{id:int,expires:int,employee:string,signature:string}|null */
  private static function parse(string $reference): ?array
  {
    if (preg_match('/^([1-9][0-9]{0,17})\.([0-9]{10})\.(' . self::EMPLOYEE . ')\.([a-f0-9]{64})$/D', $reference, $parts) !== 1) {
      return null;
    }
    return ['id' => (int) $parts[1], 'expires' => (int) $parts[2], 'employee' => $parts[3], 'signature' => $parts[4]];
  }

  private static function base(): string
  {
    return rtrim((string) SCM_BASE_URL, '/');
  }

  private static function signature(int $id, int $expires, string $employeeId): string
  {
    return hash_hmac('sha256', 'commercial_task_login_v1|' . $id . '|' . $expires . '|' . $employeeId, (string) SCM_APP_SECRET);
  }
}
