<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Convierte filas crudas de `jet_cct_tracker_logs` en filas de los tres resúmenes diarios, sin tocar la base.
 *
 * Reglas (las mismas para el cron, la puesta al día de la página y --rebuild-from):
 * - Solo cuentan las vistas (event_type vacío o 'view'). Hoy el tracker solo registra vistas; si algún día registra
 *   clics, se omiten aquí y hará falta un resumen propio para ellos.
 * - Filas sin fecha válida ('AAAA-MM-DD HH:MM:SS') se omiten.
 * - Campaña, fuente y medio vacíos quedan como 'general', 'Directo' y 'Sin medio' (como los mostraba dashboard-marketing).
 * - Robot: BotDetector sobre el user agent. Interna: la IP está en Configuración › Analítica web.
 * - Páginas sin parámetros de seguimiento (UrlNormalizer); sitios de referencia por dominio, y los dominios propios
 *   (o el mismo dominio de la página) como 'Navegación interna'.
 * - La IP nunca se guarda: los visitantes se identifican con VisitorHasher.
 */
final class TrackerAggregator
{
  public const NO_URL = '(sin URL)';
  public const DIRECT_REFERRER = 'Directo';
  public const INTERNAL_REFERRER = 'Navegación interna';
  public const NO_COUNTRY = 'Sin país';
  public const NO_CITY = 'Sin ciudad';

  private VisitorHasher $hasher;
  private InternalIpMatcher $internal;
  /** @var list<string> */
  private array $ownDomains;

  /** @var array<string,array<string,int|string>> */
  private array $daily = [];
  /** @var array<string,array<string,int|string>> */
  private array $top = [];
  /** @var array<string,array<string,int|string>> */
  private array $visitors = [];
  private int $counted = 0;

  /** @param list<string> $ownDomains */
  public function __construct(VisitorHasher $hasher, InternalIpMatcher $internal, array $ownDomains = [])
  {
    $this->hasher = $hasher;
    $this->internal = $internal;
    $this->ownDomains = $ownDomains;
  }

  /**
   * Suma una fila cruda. Devuelve false si no cuenta (no es vista, fecha inválida o anterior a $minDay).
   * @param array<string,mixed> $row
   */
  public function add(array $row, ?string $minDay = null): bool
  {
    $day = self::day($row['fecha_visita'] ?? null);
    if ($day === null || ($minDay !== null && $day < $minDay)) {
      return false;
    }
    $event = strtolower(trim((string) ($row['event_type'] ?? '')));
    if ($event !== '' && $event !== 'view') {
      return false;
    }

    $campaign = self::dimension($row['camp_slug'] ?? '', TrackerSchema::EMPTY_CAMPAIGN);
    $source = self::dimension($row['utm_source'] ?? '', TrackerSchema::EMPTY_SOURCE);
    $medium = self::dimension($row['utm_medium'] ?? '', TrackerSchema::EMPTY_MEDIUM);
    $ip = (string) ($row['ip_address'] ?? '');
    $userAgent = (string) ($row['user_agent'] ?? '');
    $bot = BotDetector::isBot($userAgent) ? 1 : 0;
    $internal = $this->internal->matches($ip) ? 1 : 0;
    $registered = (int) ($row['user_id'] ?? 0) > 0 ? 1 : 0;
    $base = ['fecha' => $day, 'camp_slug' => $campaign, 'utm_source' => $source, 'utm_medium' => $medium];
    $flags = ['bot' => $bot, 'internal' => $internal];

    $this->bump($this->daily, $base + ['registered' => $registered] + $flags);
    $this->bump($this->visitors, ['fecha' => $day, 'visitor_hash' => $this->hasher->hash($ip, $userAgent)] + array_slice($base, 1, 3, true) + $flags);

    $page = UrlNormalizer::page((string) ($row['page_url'] ?? ''));
    $this->topRow($base, $flags, 'page', $page !== '' ? $page : self::NO_URL);
    $this->topRow($base, $flags, 'referrer', $this->referrer((string) ($row['referrer'] ?? ''), (string) ($row['page_url'] ?? '')));
    $country = self::text($row['country'] ?? '', 120);
    $city = self::text($row['city'] ?? '', 120);
    $this->topRow($base, $flags, 'country', $country !== '' ? $country : self::NO_COUNTRY);
    $this->topRow($base, $flags, 'city', $city !== '' ? $city : self::NO_CITY, $country, $city . '|' . $country);
    $objectId = (int) ($row['object_id'] ?? 0);
    if ($objectId > 0) {
      $reference = self::text($row['object_ref'] ?? '', 255);
      $this->topRow($base, $flags, 'object', (string) $objectId, $reference !== '' ? $reference : $page);
    }
    $this->counted++;
    return true;
  }

  public function counted(): int
  {
    return $this->counted;
  }

  /** @return list<array<string,int|string>> */
  public function dailyRows(): array
  {
    return array_values($this->daily);
  }

  /** @return list<array<string,int|string>> */
  public function topRows(): array
  {
    return array_values($this->top);
  }

  /** @return list<array<string,int|string>> */
  public function visitorRows(): array
  {
    return array_values($this->visitors);
  }

  /** Día 'AAAA-MM-DD' de un fecha_visita válido. */
  public static function day(mixed $value): ?string
  {
    if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]\d{2}:\d{2}/', $value, $match) !== 1) {
      return null;
    }
    return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $match[1] . '-' . $match[2] . '-' . $match[3] : null;
  }

  /** Valor de campaña, fuente o medio tal como queda en los resúmenes (también lo usa el visor de registros). */
  public static function dimension(mixed $value, string $empty): string
  {
    $text = self::text($value, 120);
    return $text !== '' ? $text : $empty;
  }

  private static function text(mixed $value, int $max): string
  {
    $text = is_scalar($value) ? (string) $value : '';
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    return trim(mb_substr(trim($text), 0, $max));
  }

  private function referrer(string $referrer, string $pageUrl): string
  {
    $host = UrlNormalizer::host($referrer);
    if ($host === '') {
      return self::DIRECT_REFERRER;
    }
    if (UrlNormalizer::isOwnHost($host, $this->ownDomains) || $host === UrlNormalizer::host($pageUrl)) {
      return self::INTERNAL_REFERRER;
    }
    return $host;
  }

  /**
   * @param array{fecha:string,camp_slug:string,utm_source:string,utm_medium:string} $base
   * @param array{bot:int,internal:int} $flags
   */
  private function topRow(array $base, array $flags, string $dim, string $value, string $detail = '', ?string $hashKey = null): void
  {
    $this->bump($this->top, $base + ['dim' => $dim, 'value_hash' => md5($hashKey ?? $value)] + $flags, [
      'value' => self::text($value, 255),
      'detail' => self::text($detail, 255),
    ]);
  }

  /**
   * @param array<string,array<string,int|string>> $bucket
   * @param array<string,int|string> $key
   * @param array<string,string> $extra Columnas que no son llave (valor visible y detalle).
   */
  private function bump(array &$bucket, array $key, array $extra = []): void
  {
    $id = implode("\x1F", $key);
    if (!isset($bucket[$id])) {
      $bucket[$id] = $key + $extra + ['hits' => 0];
    }
    foreach ($extra as $column => $value) {
      // Igual que el upsert: gana el último valor no vacío, salvo que sea una URL y ya haya una referencia.
      $current = (string) $bucket[$id][$column];
      if ($value !== '' && ($current === '' || stripos($value, 'http') !== 0)) {
        $bucket[$id][$column] = $value;
      }
    }
    $bucket[$id]['hits'] = (int) $bucket[$id]['hits'] + 1;
  }
}
