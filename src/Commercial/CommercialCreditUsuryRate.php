<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Settings;

/**
 * Tasa de usura del crédito a propietarios. Copia de control-servicios-inmobiliarios
 * src/Modules/OwnerCredits/OwnerCreditUsuryRate.php (mantener ambas iguales): "Tasa de interés bancario corriente - TIBC"
 * (Superfinanciera, datos.gov.co pare-7x5i), modalidad "Consumo y ordinario". Usura = 1,5 × TIBC (efectiva anual).
 * Se sincroniza como máximo una vez al día en la misma configuración de créditos del panel inmobiliario
 * (`owner_credit_settings` de `control_servicios_config`), solo con los campos de la usura; si la API no responde se
 * conserva la última tasa guardada. Si allá se desactivó la tasa automática, no se toca.
 */
final class CommercialCreditUsuryRate
{
  public const ENDPOINT = 'https://www.datos.gov.co/resource/pare-7x5i.json';
  public const MODALITY = 'CONSUMO Y ORDINARIO';
  public const KEY = 'owner_credit_settings';
  private const SYNC_EVERY = 86400;

  /**
   * Sincroniza si la tasa automática está vencida o tiene más de un día; devuelve la configuración guardada.
   * @return array<string,mixed>
   */
  public static function sync(Settings $settings, bool $force = false, ?callable $fetch = null): array
  {
    $config = $settings->get(self::KEY, []);
    $config = is_array($config) ? $config : [];
    // Mismos valores que acepta OwnerCreditSettings::normalize() del panel inmobiliario; sin guardar, es automática.
    $auto = $config['usury_auto'] ?? true;
    if (!in_array(strtolower(trim(is_bool($auto) ? ($auto ? '1' : '0') : (string) $auto)), ['1', 'true', 'si', 'sí', 'on', 'yes'], true)) return $config;
    $today = date('Y-m-d');
    $until = (string) ($config['usury_valid_until'] ?? '');
    $fresh = (time() - (int) ($config['usury_synced_at'] ?? 0)) < self::SYNC_EVERY && ($until === '' || $until >= $today);
    if ($fresh && !$force) return $config;
    try {
      $rows = $fetch ? $fetch() : self::fetch();
      $rate = self::fromRows($rows, $today);
      if ($rate === null) throw new \RuntimeException('La API no trajo una tasa vigente de consumo y ordinario.');
      $config = array_merge($config, $rate, ['usury_synced_at' => time(), 'usury_sync_error' => '']);
    } catch (\Throwable $error) {
      error_log('[commercial-credit-usury] ' . $error->getMessage());
      $config['usury_synced_at'] = time();
      $config['usury_sync_error'] = mb_substr($error->getMessage(), 0, 200);
    }
    // Se vuelve a leer justo antes de guardar para no pisar lo que haya cambiado el panel inmobiliario.
    $settings->refresh();
    $stored = $settings->get(self::KEY, []);
    $usury = array_intersect_key($config, array_flip(['usury_annual', 'usury_updated_at', 'usury_valid_until', 'usury_bank_rate', 'usury_resolution', 'usury_synced_at', 'usury_sync_error']));
    $merged = array_merge(is_array($stored) ? $stored : [], $usury);
    $settings->set(self::KEY, $merged, 0);
    return $merged;
  }

  /**
   * Fila vigente hoy (o la más reciente ya iniciada).
   * @param list<array<string,mixed>> $rows
   * @return array{usury_annual:float,usury_updated_at:string,usury_valid_until:string,usury_bank_rate:float,usury_resolution:string}|null
   */
  public static function fromRows(array $rows, string $today): ?array
  {
    $best = null;
    foreach ($rows as $row) {
      if (strtoupper(trim((string) ($row['modalidad'] ?? ''))) !== self::MODALITY) continue;
      $from = substr((string) ($row['vigencia_desde'] ?? ''), 0, 10);
      $rate = (float) str_replace([',', '%'], ['.', ''], (string) ($row['interes_bancario_corriente'] ?? ''));
      if ($from === '' || $from > $today || $rate <= 0) continue;
      if ($best === null || $from > $best['from']) $best = ['from' => $from, 'to' => substr((string) ($row['vigencia_hasta'] ?? ''), 0, 10), 'rate' => $rate / 100, 'resolution' => (string) ($row['resolucion'] ?? '')];
    }
    if ($best === null) return null;
    return [
      'usury_annual' => round($best['rate'] * 1.5, 6),
      'usury_updated_at' => $best['from'],
      'usury_valid_until' => $best['to'],
      'usury_bank_rate' => $best['rate'],
      'usury_resolution' => $best['resolution'],
    ];
  }

  /** @return list<array<string,mixed>> */
  private static function fetch(): array
  {
    $url = self::ENDPOINT . '?' . http_build_query([
      '$where' => "modalidad = '" . self::MODALITY . "'",
      '$order' => 'vigencia_desde DESC',
      '$limit' => 3,
    ]);
    if (function_exists('curl_init')) {
      $curl = curl_init($url);
      curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
      $body = curl_exec($curl);
      $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
      if ($status !== 200) $body = false;
    } else {
      $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'header' => "Accept: application/json\r\n"]]));
    }
    if (!is_string($body)) throw new \RuntimeException('No se pudo consultar datos.gov.co.');
    $rows = json_decode($body, true);
    if (!is_array($rows)) throw new \RuntimeException('datos.gov.co devolvió una respuesta no válida.');
    return $rows;
  }
}
