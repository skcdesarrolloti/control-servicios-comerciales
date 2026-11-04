<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Recibe las visitas del sitio web (public/tracker-hit.php, que llama public/assets/js/site-tracker.js). Copia de
 * dashboard-marketing app/TrackerRepository.php sobre la misma tabla jet_cct_tracker_logs, para que la Analítica web
 * (resúmenes de TrackerRollup) siga igual al apagar ese panel:
 * - campaña: slug o utm_campaign; sin campaña, "general" (o "inmueble_view" en la ficha de un inmueble);
 * - sin utm_source: Directo / Navegador (o Inmuebles / Pagina Web en un inmueble);
 * - país y ciudad con ip-api.com (con caché de una semana; si tarda más de 0,8 s se guarda sin ubicación);
 * - las campañas nuevas se agregan a jet_cct_tracker_camps.
 * Solo acepta llamadas desde el sitio (sucasainmobiliaria.com.co y los dominios propios de Configuración › Analítica
 * web), y la IP se toma con la regla del panel anterior (Cloudflare, X-Forwarded-For y REMOTE_ADDR).
 */
final class TrackerHitRecorder
{
  public const SITE_ORIGINS = ['https://sucasainmobiliaria.com.co', 'https://www.sucasainmobiliaria.com.co'];
  private const NO_CAMPAIGN = ['general', 'inmueble_view', 'event_click'];

  private Database $db;
  private Settings $settings;
  private string $cacheFile;
  /** @var \Closure(string):?array{country:string,city:string} */
  private \Closure $lookup;

  /** @param (\Closure(string):?array{country:string,city:string})|null $lookup País y ciudad de una IP (las pruebas no salen a internet). */
  public function __construct(Database $db, Settings $settings, string $cacheFile, ?\Closure $lookup = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->cacheFile = $cacheFile;
    $this->lookup = $lookup ?? static function (string $ip): ?array {
      $context = stream_context_create(['http' => ['timeout' => 0.8, 'ignore_errors' => true]]);
      $json = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city', false, $context);
      $decoded = is_string($json) ? json_decode($json, true) : null;
      return is_array($decoded) && ($decoded['status'] ?? '') === 'success'
        ? ['country' => (string) ($decoded['country'] ?? ''), 'city' => (string) ($decoded['city'] ?? '')] : null;
    };
  }

  /** Si el navegador que llama viene del sitio (sin Origin, como sendBeacon en algunos navegadores, se acepta). */
  public function allowsOrigin(string $origin): bool
  {
    $origin = strtolower(rtrim(trim($origin), '/'));
    if ($origin === '') {
      return true;
    }
    $allowed = self::SITE_ORIGINS;
    foreach (TrackerAnalyticsConfig::read($this->settings)['own_domains'] as $domain) {
      $allowed[] = 'https://' . strtolower($domain);
      $allowed[] = 'https://www.' . strtolower(preg_replace('/^www\./', '', $domain) ?? $domain);
    }
    return in_array($origin, $allowed, true);
  }

  /**
   * @param array<string,mixed> $payload slug, url, referrer, object_id, object_ref, user_id, event_type, event_label, utm_*
   * @param array<string,string> $server $_SERVER (IP y navegador)
   * @return int id de la visita
   */
  public function record(array $payload, array $server, ?string $now = null): int
  {
    $slug = self::text($payload['slug'] ?? $payload['camp_slug'] ?? '', 120);
    $campaign = self::text($payload['utm_campaign'] ?? '', 120);
    if ($slug === '' || $slug === 'general') {
      $slug = $campaign !== '' ? $campaign : ($slug === '' ? 'general' : $slug);
    }
    $source = self::text($payload['utm_source'] ?? '', 120);
    $medium = self::text($payload['utm_medium'] ?? '', 120);
    if ($source === '') {
      $source = $slug === 'inmueble_view' ? 'Inmuebles' : 'Directo';
      $medium = $medium !== '' ? $medium : ($slug === 'inmueble_view' ? 'Pagina Web' : 'Navegador');
    }
    $ip = self::clientIp($server);
    $geo = $this->geo($ip);
    $this->db->insert($this->db->table('jet_cct_tracker_logs'), [
      'camp_slug' => $slug, 'user_id' => max(0, (int) ($payload['user_id'] ?? 0)), 'ip_address' => $ip,
      'user_agent' => self::text($server['HTTP_USER_AGENT'] ?? '', 250), 'page_url' => self::url($payload['url'] ?? $payload['page_url'] ?? '', 800),
      'referrer' => self::url($payload['referrer'] ?? '', 800), 'country' => $geo['country'], 'city' => $geo['city'],
      'event_type' => self::text($payload['event_type'] ?? 'view', 40) ?: 'view', 'object_id' => max(0, (int) ($payload['object_id'] ?? 0)),
      'object_ref' => self::text($payload['object_ref'] ?? '', 160), 'event_label' => self::text($payload['event_label'] ?? '', 160),
      'utm_source' => $source, 'utm_medium' => $medium, 'fecha_visita' => $now ?? date('Y-m-d H:i:s'),
    ]);
    $id = (int) $this->db->lastInsertId();
    $this->ensureCampaign($slug);
    return $id;
  }

  private function ensureCampaign(string $slug): void
  {
    if (in_array($slug, self::NO_CAMPAIGN, true)) {
      return;
    }
    $table = $this->db->table('jet_cct_tracker_camps');
    try {
      if (!$this->db->getVar("SELECT _ID FROM `{$table}` WHERE slug = ? LIMIT 1", [$slug])) {
        $this->db->insert($table, ['slug' => $slug, 'nombre' => ucfirst(str_replace(['-', '_'], ' ', $slug))]);
      }
    } catch (\Throwable $exception) {
      error_log('[tracker-hit] campaña ' . $slug . ': ' . $exception->getMessage());
    }
  }

  /** @param array<string,string> $server */
  public static function clientIp(array $server): string
  {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
      foreach (explode(',', (string) ($server[$key] ?? '')) as $value) {
        $value = trim($value);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
          return $value;
        }
      }
    }
    return '';
  }

  /** @return array{country:string,city:string} */
  private function geo(string $ip): array
  {
    $empty = ['country' => '', 'city' => ''];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return $empty;
    }
    $key = sha1($ip);
    $cache = $this->readCache();
    if (isset($cache[$key]) && (int) ($cache[$key]['expires'] ?? 0) > time()) {
      return ['country' => (string) $cache[$key]['country'], 'city' => (string) $cache[$key]['city']];
    }
    $found = ($this->lookup)($ip);
    $result = $found !== null ? ['country' => self::text($found['country'], 80), 'city' => self::text($found['city'], 80)] : $empty;
    $cache[$key] = $result + ['expires' => time() + ($result['country'] !== '' ? 604800 : 3600)];
    $this->writeCache($cache);
    return $result;
  }

  /** @return array<string,array<string,mixed>> */
  private function readCache(): array
  {
    $decoded = is_readable($this->cacheFile) ? json_decode((string) file_get_contents($this->cacheFile), true) : null;
    return is_array($decoded) ? $decoded : [];
  }

  /** Guarda la caché sin las entradas vencidas y con máximo 20.000 IPs (las más nuevas). @param array<string,array<string,mixed>> $cache */
  private function writeCache(array $cache): void
  {
    $now = time();
    $cache = array_filter($cache, static fn(array $row): bool => (int) ($row['expires'] ?? 0) > $now);
    if (count($cache) > 20000) {
      $cache = array_slice($cache, -20000, null, true);
    }
    $dir = dirname($this->cacheFile);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    @file_put_contents($this->cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  /** @param mixed $value */
  private static function text($value, int $max): string
  {
    $text = trim(strip_tags(is_scalar($value) ? (string) $value : ''));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr($text, 0, $max, 'UTF-8');
  }

  /** @param mixed $value */
  private static function url($value, int $max): string
  {
    $url = self::text($value, $max);
    return preg_match('#^https?://#i', $url) === 1 ? $url : '';
  }
}
