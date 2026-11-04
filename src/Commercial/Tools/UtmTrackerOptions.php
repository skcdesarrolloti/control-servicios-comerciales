<?php

declare(strict_types=1);

namespace SCM\Commercial\Tools;

use SCM\Core\Database;

/**
 * Copia de dashboard-marketing (app/AnalyticsRepository.php, filterOptions/options): si cambia la consulta, cambiarla en
 * ambos. Solo lectura: campañas que ya registró el tracker (jet_cct_tracker_logs) más el catálogo de campañas
 * (jet_cct_tracker_camps), para sugerirlas en el Generador UTM. Las tablas son del legado: si no existen o cambian, se
 * devuelve la lista vacía sin romper la herramienta. No envía IP, URL ni ningún dato de visitantes.
 *
 * Fuentes y medios ya no se sugieren desde el tracker: el generador usa la lista única de Medios (MedioCatalog).
 */
final class UtmTrackerOptions
{
  /** Segundos que se reutiliza el resultado en la sesión (el legado cacheaba 15 minutos). */
  private const CACHE_TTL = 900;
  private const CACHE_KEY = 'scm_utm_tracker_options_v3';

  /** @var array<string,bool> */
  private array $exists = [];

  public function __construct(private Database $db) {}

  /** @return array{campaigns:list<array{value:string,label:string}>} */
  public function options(bool $useCache = true): array
  {
    $cached = $useCache ? ($_SESSION[self::CACHE_KEY] ?? null) : null;
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() && is_array($cached['data'] ?? null)) {
      /** @var array{campaigns:list<array{value:string,label:string}>} $data */
      $data = $cached['data'];
      return $data;
    }
    $logs = $this->db->table('jet_cct_tracker_logs');
    $camps = $this->db->table('jet_cct_tracker_camps');
    $data = ['campaigns' => []];
    $campaigns = [];
    if ($this->exists($camps)) {
      try {
        foreach ($this->db->getResults("SELECT `slug`, `nombre` FROM `{$camps}` WHERE `slug` IS NOT NULL AND `slug` <> '' ORDER BY `slug` ASC LIMIT 300") as $row) {
          $slug = trim((string) ($row['slug'] ?? ''));
          $campaigns[$slug] = ['value' => $slug, 'label' => trim((string) ($row['nombre'] ?? '')) ?: $slug];
        }
      } catch (\Throwable $exception) {
        error_log('[commercial-tools-utm] ' . $exception->getMessage());
      }
    }
    foreach ($this->distinct($logs, 'camp_slug', 300) as $slug) {
      $campaigns[$slug] ??= ['value' => $slug, 'label' => $slug];
    }
    $data['campaigns'] = array_values(array_filter($campaigns, static fn(array $item): bool => !in_array(mb_strtolower($item['value'], 'UTF-8'), UtmLinkBuilder::RESERVED_CAMPAIGNS, true)));
    if ($useCache) {
      $_SESSION[self::CACHE_KEY] = ['expires' => time() + self::CACHE_TTL, 'data' => $data];
    }
    return $data;
  }

  /** @return list<string> */
  private function distinct(string $table, string $column, int $limit): array
  {
    if (!$this->exists($table)) {
      return [];
    }
    try {
      $values = $this->db->getCol("SELECT `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' GROUP BY `{$column}` ORDER BY `{$column}` ASC LIMIT " . max(1, $limit));
    } catch (\Throwable $exception) {
      error_log('[commercial-tools-utm] ' . $exception->getMessage());
      return [];
    }
    return array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string) $value), $values), static fn(string $value): bool => $value !== '')));
  }

  /** Las tablas del tracker son del legado: se prueban sin depender de information_schema. */
  private function exists(string $table): bool
  {
    if (!isset($this->exists[$table])) {
      try {
        $this->db->getVar("SELECT 1 FROM `{$table}` LIMIT 1");
        $this->exists[$table] = true;
      } catch (\Throwable) {
        $this->exists[$table] = false;
      }
    }
    return $this->exists[$table];
  }
}
