<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Identificador de visitante para contar únicos sin guardar la IP en los resúmenes: HMAC-SHA256(IP, APP_SECRET).
 * Si cambia APP_SECRET los visitantes anteriores y posteriores dejan de coincidir (solo afecta los únicos de un rango
 * que cruce el cambio).
 */
final class VisitorHasher
{
  private string $secret;

  public function __construct(string $secret)
  {
    if (strlen($secret) < 16) {
      throw new \InvalidArgumentException('El secreto para identificar visitantes es demasiado corto.');
    }
    $this->secret = $secret;
  }

  /**
   * 64 caracteres hexadecimales. Sin IP se usa el navegador, para no juntar a todos en un solo visitante. La IP va en su
   * forma única (InternalIpMatcher::canonical): ::ffff:1.2.3.4 y 1.2.3.4 son el mismo visitante.
   */
  public function hash(?string $ip, ?string $userAgent = ''): string
  {
    $ip = InternalIpMatcher::canonical($ip);
    $key = $ip !== '' ? 'ip|' . $ip : 'ua|' . trim((string) $userAgent);
    return hash_hmac('sha256', $key, $this->secret);
  }
}
