<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social;

use SCM\Commercial\Promotion\Social\Sync\MetaSocialSyncService;
use SCM\Commercial\Promotion\Social\Sync\SocialDb;
use SCM\Commercial\Promotion\Social\Sync\TikTokApiService;
use SCM\Commercial\Promotion\Social\Sync\TikTokSyncService;
use SCM\Commercial\Promotion\Social\Sync\YouTubeApiService;
use SCM\Commercial\Promotion\Social\Sync\YouTubeSyncService;
use SCM\Core\Database;

/**
 * Sincroniza las redes (tarea programada bin/social-sync.php y el botón de Promoción › Redes sociales). Cada red corre
 * por separado: si una falla, las demás siguen y el resultado lo dice. Un archivo de bloqueo evita dos sincronizaciones al
 * tiempo. Solo informa si las credenciales están o no; nunca las devuelve.
 */
final class SocialSyncRunner
{
  /** Días hacia atrás por defecto: Meta solo entrega 30 días de métricas diarias; se deja un margen. */
  public const DEFAULT_DAYS = 35;
  /** Grupos que se sincronizan juntos. */
  public const GROUPS = ['instagram', 'facebook', 'publicidad', 'youtube', 'tiktok'];
  private const META_PLATFORM = ['instagram' => 'instagram', 'facebook' => 'facebook', 'publicidad' => 'ads'];

  /** @var array<string,mixed> */
  private array $config;
  private string $lockFile;
  /** @var array<string,\Closure(string,string):array<string,mixed>> */
  private array $syncers;

  /**
   * @param array<string,mixed> $config Configuración del panel (meta, youtube, tiktok).
   * @param array<string,\Closure(string,string):array<string,mixed>> $syncers Reemplazos por red (las pruebas no salen a internet).
   */
  public function __construct(Database $db, array $config, string $lockFile, array $syncers = [])
  {
    SocialDb::boot($db, $config);
    $this->config = $config;
    $this->lockFile = $lockFile;
    $this->syncers = $syncers;
  }

  /**
   * @param string $network '' = todas
   * @return array{ok:bool,results:array<string,array{ok:bool,message:string,warnings:list<string>,summary:array<string,mixed>}>}
   */
  public function run(string $network, string $from, string $to): array
  {
    $networks = $network === '' ? self::GROUPS : [$network];
    if (array_diff($networks, self::GROUPS) !== []) {
      throw new \InvalidArgumentException('Red no válida.');
    }
    $handle = @fopen($this->lockFile, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
      if (is_resource($handle)) {
        fclose($handle);
      }
      throw new \RuntimeException('Ya hay una sincronización en curso. Espera a que termine.');
    }
    try {
      $results = [];
      foreach ($networks as $item) {
        try {
          $summary = $this->sync($item, $from, $to);
          $warnings = array_values(array_map(static fn($warning): string => mb_substr(trim((string) $warning), 0, 500, 'UTF-8'), (array) ($summary['warnings'] ?? [])));
          unset($summary['warnings']);
          $results[$item] = ['ok' => true, 'message' => 'Sincronizada.', 'warnings' => $warnings, 'summary' => $summary];
        } catch (\Throwable $exception) {
          error_log('[commercial-social-sync] ' . $item . ': ' . $exception->getMessage());
          $results[$item] = ['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 500, 'UTF-8'), 'warnings' => [], 'summary' => []];
        }
      }
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
    return ['ok' => array_filter($results, static fn(array $result): bool => !$result['ok']) === [], 'results' => $results];
  }

  /** @return array<string,mixed> */
  private function sync(string $network, string $from, string $to): array
  {
    if (isset($this->syncers[$network])) {
      return ($this->syncers[$network])($from, $to);
    }
    return match ($network) {
      'youtube' => (new YouTubeSyncService())->sync($from, $to),
      'tiktok' => (new TikTokSyncService())->sync($from, $to),
      default => (new MetaSocialSyncService())->sync($from, $to, self::META_PLATFORM[$network]),
    };
  }

  /** @return array<string,bool> Si cada red tiene sus credenciales en el .env. */
  public function configured(): array
  {
    $has = fn(string $section, string ...$keys): bool => array_filter($keys, fn(string $key): bool => trim((string) ($this->config[$section][$key] ?? '')) === '') === [];
    $meta = $has('meta', 'access_token');
    return [
      'instagram' => $meta && $has('meta', 'instagram_user_id'),
      'facebook' => $has('meta', 'facebook_page_id') && ($meta || $has('meta', 'facebook_page_access_token')),
      'publicidad' => $meta,
      'youtube' => (new YouTubeApiService())->isConfigured(),
      'tiktok' => (new TikTokApiService())->isConfigured(),
    ];
  }

  public function connectionUrl(string $network): string
  {
    return $network === 'youtube' ? (new YouTubeApiService())->authorizationUrl() : (new TikTokApiService())->authorizationUrl();
  }

  /** Respuesta del proveedor al conectar. @return string Nombre de la cuenta conectada. */
  public function completeConnection(string $network, string $code, string $state): string
  {
    if ($network === 'youtube') {
      return (string) ((new YouTubeApiService())->completeAuthorization($code, $state)['channel_title'] ?? 'YouTube');
    }
    return (string) ((new TikTokApiService())->completeAuthorization($code, $state)['display_name'] ?? 'TikTok');
  }

  /** Quita la conexión; las estadísticas guardadas se conservan. */
  public function disconnect(string $network): void
  {
    if ($network === 'youtube') {
      (new YouTubeApiService())->disconnect();
    } elseif ($network === 'tiktok') {
      (new TikTokApiService())->disconnect();
    } else {
      throw new \InvalidArgumentException('Esta red no se conecta desde aquí.');
    }
  }
}
