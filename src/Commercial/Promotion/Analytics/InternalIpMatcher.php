<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * IPs de la oficina y del equipo, para separar sus visitas de las de clientes.
 * Acepta IPs exactas (IPv4/IPv6), rangos CIDR (190.85.10.0/24, 2800:e2::/32) y prefijos IPv4 (190.85. o 190.85.*).
 */
final class InternalIpMatcher
{
  public const MAX_ENTRIES = 100;

  /** @var list<array{type:string,value:string,bits:int}> */
  private array $rules = [];

  /** @param list<string> $entries Entradas ya normalizadas con normalizeList(). */
  public function __construct(array $entries)
  {
    foreach ($entries as $entry) {
      $rule = self::rule($entry);
      if ($rule !== null) {
        $this->rules[] = $rule;
      }
    }
  }

  public function isEmpty(): bool
  {
    return $this->rules === [];
  }

  public function matches(?string $ip): bool
  {
    $ip = trim((string) $ip);
    if ($ip === '' || $this->rules === [] || filter_var($ip, FILTER_VALIDATE_IP) === false) {
      return false;
    }
    $binary = inet_pton($ip);
    if ($binary === false) {
      return false;
    }
    foreach ($this->rules as $rule) {
      if ($rule['type'] === 'prefix') {
        if (str_contains($ip, '.') && str_starts_with($ip, $rule['value'])) {
          return true;
        }
        continue;
      }
      $network = (string) inet_pton($rule['value']);
      if (strlen($network) !== strlen($binary)) {
        continue;
      }
      if ($rule['type'] === 'ip' ? $binary === $network : self::inRange($binary, $network, $rule['bits'])) {
        return true;
      }
    }
    return false;
  }

  /**
   * Limpia y valida la lista escrita por el administrador (una por línea, o separadas por comas o espacios).
   *
   * @param mixed $raw
   * @return list<string>
   * @throws \InvalidArgumentException Con las entradas que no son válidas.
   */
  public static function normalizeList($raw): array
  {
    if (is_string($raw)) {
      $raw = preg_split('/[\s,;]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
      return [];
    }
    $clean = [];
    $invalid = [];
    foreach ($raw as $entry) {
      $entry = strtolower(trim(is_scalar($entry) ? (string) $entry : ''));
      if ($entry === '') {
        continue;
      }
      $normalized = self::normalizeEntry($entry);
      if ($normalized === null) {
        $invalid[] = mb_substr($entry, 0, 60);
        continue;
      }
      $clean[$normalized] = $normalized;
    }
    if ($invalid !== []) {
      throw new \InvalidArgumentException('Estas IPs internas no son válidas: ' . implode(', ', array_slice($invalid, 0, 5)) . '. Usa una IP, un rango CIDR (190.85.10.0/24) o un prefijo (190.85.).');
    }
    if (count($clean) > self::MAX_ENTRIES) {
      throw new \InvalidArgumentException('La lista de IPs internas admite hasta ' . self::MAX_ENTRIES . ' entradas.');
    }
    return array_values($clean);
  }

  private static function normalizeEntry(string $entry): ?string
  {
    if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
      return (string) inet_ntop((string) inet_pton($entry));
    }
    if (str_contains($entry, '/')) {
      [$address, $bits] = explode('/', $entry, 2);
      if (filter_var($address, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
        return null;
      }
      $max = str_contains($address, ':') ? 128 : 32;
      $bits = (int) $bits;
      if ($bits < 8 || $bits > $max) {
        return null;
      }
      return inet_ntop((string) inet_pton($address)) . '/' . $bits;
    }
    // Prefijo IPv4: 1 a 3 octetos terminados en punto (o en .*).
    $prefix = preg_replace('/\.\*$/', '.', $entry) ?? $entry;
    if (preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){1,3}$/', $prefix) === 1) {
      return $prefix;
    }
    return null;
  }

  /** @return array{type:string,value:string,bits:int}|null */
  private static function rule(string $entry): ?array
  {
    if (str_contains($entry, '/')) {
      [$address, $bits] = explode('/', $entry, 2);
      return filter_var($address, FILTER_VALIDATE_IP) !== false ? ['type' => 'cidr', 'value' => $address, 'bits' => (int) $bits] : null;
    }
    if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
      return ['type' => 'ip', 'value' => $entry, 'bits' => 0];
    }
    return str_ends_with($entry, '.') ? ['type' => 'prefix', 'value' => $entry, 'bits' => 0] : null;
  }

  private static function inRange(string $binary, string $network, int $bits): bool
  {
    $bytes = intdiv($bits, 8);
    if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
      return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
      return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
  }
}
