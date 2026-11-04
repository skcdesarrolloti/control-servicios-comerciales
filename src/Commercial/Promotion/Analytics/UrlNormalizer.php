<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Normaliza las URLs del tracker para agrupar: el 26 % de las páginas traen parámetros de seguimiento
 * (fbclid, gclid, utm_*...) que convertían la misma página en miles de filas distintas en dashboard-marketing.
 */
final class UrlNormalizer
{
  /** Parámetros de seguimiento que se quitan (además de todo lo que empiece por utm_). */
  public const TRACKING_PARAMS = [
    'fbclid', 'gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'ttclid', 'twclid', 'yclid', 'igshid', 'igsh',
    'mc_cid', 'mc_eid', '_ga', '_gl', '_hsenc', '_hsmi', 'li_fat_id', 'srsltid', 'epik', 'sck', 'mkt_tok', 'ref_src', 's_cid',
  ];

  /** URL de página sin fragmento ni parámetros de seguimiento; esquema y dominio en minúscula; parámetros ordenados. */
  public static function page(?string $url): string
  {
    $parts = self::parse($url);
    if ($parts === null) {
      return '';
    }
    $path = (string) ($parts['path'] ?? '');
    $path = $path === '' ? '/' : $path;
    $query = '';
    if (isset($parts['query']) && $parts['query'] !== '') {
      $kept = [];
      foreach (explode('&', $parts['query']) as $pair) {
        if ($pair === '') {
          continue;
        }
        $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
        if ($name === '' || str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMS, true)) {
          continue;
        }
        $kept[] = $pair;
      }
      sort($kept, SORT_STRING);
      $query = $kept === [] ? '' : '?' . implode('&', $kept);
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port . $path . $query;
  }

  /** Dominio del sitio de referencia, en minúscula y sin "www."; vacío si no es una URL http(s). */
  public static function host(?string $url): string
  {
    $parts = self::parse($url);
    if ($parts === null) {
      return '';
    }
    $host = strtolower(rtrim((string) $parts['host'], '.'));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
  }

  /**
   * Dominios propios escritos por el administrador ("sucasainmobiliaria.com.co" o una URL completa).
   * @param mixed $raw
   * @return list<string>
   * @throws \InvalidArgumentException
   */
  public static function normalizeDomains($raw): array
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
      $host = self::host(preg_match('#^https?://#', $entry) === 1 ? $entry : 'https://' . $entry);
      if ($host === '' || strlen($host) > 190 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host) !== 1) {
        $invalid[] = mb_substr($entry, 0, 60);
        continue;
      }
      $clean[$host] = $host;
    }
    if ($invalid !== []) {
      throw new \InvalidArgumentException('Estos dominios no son válidos: ' . implode(', ', array_slice($invalid, 0, 5)) . '.');
    }
    if (count($clean) > 30) {
      throw new \InvalidArgumentException('Puedes registrar hasta 30 dominios propios.');
    }
    return array_values($clean);
  }

  /** ¿El dominio es uno de los propios o un subdominio de ellos? @param list<string> $domains */
  public static function isOwnHost(string $host, array $domains): bool
  {
    if ($host === '') {
      return false;
    }
    foreach ($domains as $domain) {
      if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        return true;
      }
    }
    return false;
  }

  /** @return array{scheme:string,host:string,port?:int,path?:string,query?:string}|null */
  private static function parse(?string $url): ?array
  {
    $url = trim((string) $url);
    if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
      return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
      return null;
    }
    /** @var array{scheme:string,host:string,port?:int,path?:string,query?:string} $parts */
    return $parts;
  }
}
