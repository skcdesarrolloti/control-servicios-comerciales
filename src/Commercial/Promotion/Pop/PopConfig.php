<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Core\Settings;

/**
 * Configuración › Material POP (propia del panel, en Settings):
 * - categorías que se ofrecen al crear o editar un producto (dashboard-marketing las guardaba en
 *   storage/inventory-categories.json; aquí se administran desde el menú Configuración);
 * - alerta de stock bajo: si está activa, a qué funcionarios se les encola el correo (shared-notifications) cuando un
 *   producto llega a su stock mínimo o se agota;
 * - API de inventario para formularios y sistemas externos (public/inventory-api.php): si está activa y su token. Del
 *   token solo se guarda el hash SHA-256 y sus últimos 4 caracteres; el token completo se muestra una sola vez al
 *   generarlo. SKC_INVENTORY_API_TOKEN (el token del panel de marketing anterior) también se acepta, para que los
 *   clientes que ya lo tengan sigan funcionando.
 */
final class PopConfig
{
  public const KEY = 'commercial_pop_config';
  public const DEFAULT_CATEGORIES = ['PPH', 'Souvenir'];
  public const LEGACY_TOKEN_ENV = 'SKC_INVENTORY_API_TOKEN';

  /** @return array{categories:list<string>,alert_enabled:bool,alert_employees:list<string>,api_enabled:bool,api_token_set:bool,api_token_hint:string,api_token_created:string} */
  public static function read(Settings $settings): array
  {
    $raw = self::raw($settings);
    $categories = self::categories($raw['categories'] ?? null);
    return [
      'categories' => $categories !== [] ? $categories : self::DEFAULT_CATEGORIES,
      'alert_enabled' => !empty($raw['alert_enabled']),
      'alert_employees' => self::employees($raw['alert_employees'] ?? []),
      'api_enabled' => !empty($raw['api_enabled']),
      'api_token_set' => is_string($raw['api_token_hash'] ?? null) && strlen($raw['api_token_hash']) === 64,
      'api_token_hint' => is_string($raw['api_token_hint'] ?? null) ? $raw['api_token_hint'] : '',
      'api_token_created' => is_string($raw['api_token_created'] ?? null) ? $raw['api_token_created'] : '',
    ];
  }

  /**
   * @param array<string,mixed> $data categories (lista o texto con una por línea), alert_enabled, alert_employees, api_enabled.
   * @return array<string,mixed>
   */
  public static function save(Settings $settings, array $data, int $userId): array
  {
    $categories = self::categories($data['categories'] ?? null, true);
    if ($categories === []) {
      throw new \InvalidArgumentException('Deja al menos una categoría de productos.');
    }
    $employees = self::employees($data['alert_employees'] ?? []);
    $enabled = filter_var($data['alert_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($enabled && $employees === []) {
      throw new \InvalidArgumentException('Elige al menos un funcionario que reciba la alerta de stock bajo, o desactívala.');
    }
    $api = filter_var($data['api_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $raw = self::raw($settings);
    if ($api && !self::hasToken($raw)) {
      throw new \InvalidArgumentException('Genera el token de la API antes de activarla.');
    }
    // El token (hash, pista y fecha) se conserva: solo lo cambia rotateToken().
    $config = ['categories' => $categories, 'alert_enabled' => $enabled, 'alert_employees' => $employees, 'api_enabled' => $api]
      + array_intersect_key($raw, array_flip(['api_token_hash', 'api_token_hint', 'api_token_created']));
    $settings->set(self::KEY, $config, $userId);
    return $config;
  }

  /** Genera un token nuevo (el anterior deja de servir) y lo devuelve: es la única vez que se puede ver completo. */
  public static function rotateToken(Settings $settings, int $userId): string
  {
    $token = 'skc_pop_' . bin2hex(random_bytes(24));
    $raw = self::raw($settings);
    $raw['api_token_hash'] = hash('sha256', $token);
    $raw['api_token_hint'] = substr($token, -4);
    $raw['api_token_created'] = date('Y-m-d H:i:s');
    $settings->set(self::KEY, $raw, $userId);
    return $token;
  }

  /** Si el token recibido es el de Configuración o el del panel anterior (variable de entorno). */
  public static function validToken(Settings $settings, string $provided): bool
  {
    $provided = trim($provided);
    if ($provided === '') {
      return false;
    }
    $raw = self::raw($settings);
    if (self::hasToken($raw) && hash_equals((string) $raw['api_token_hash'], hash('sha256', $provided))) {
      return true;
    }
    $legacy = trim((string) getenv(self::LEGACY_TOKEN_ENV));
    return $legacy !== '' && hash_equals($legacy, $provided);
  }

  /** @return array<string,mixed> */
  private static function raw(Settings $settings): array
  {
    $raw = $settings->get(self::KEY, []);
    return is_array($raw) ? $raw : [];
  }

  /** @param array<string,mixed> $raw */
  private static function hasToken(array $raw): bool
  {
    return is_string($raw['api_token_hash'] ?? null) && strlen($raw['api_token_hash']) === 64;
  }

  /** Nombre de categoría limpio (2 a 60 caracteres, sin < > { } [ ] \), o '' si no sirve. */
  public static function categoryName(string $name, bool $strict = false): string
  {
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    $length = mb_strlen($name, 'UTF-8');
    $valid = $length >= 2 && $length <= 60 && preg_match('/[<>{}\[\]\\\\]/u', $name) !== 1;
    if (!$valid && $strict && $name !== '') {
      throw new \InvalidArgumentException('La categoría "' . $name . '" debe tener entre 2 y 60 caracteres y no puede llevar < > { } [ ] \\.');
    }
    return $valid ? $name : '';
  }

  /**
   * Lista sin repetidos (sin distinguir mayúsculas), en el orden en que se escribió.
   * @param mixed $raw
   * @return list<string>
   */
  public static function categories($raw, bool $strict = false): array
  {
    $items = is_array($raw) ? $raw : preg_split('/\R/u', (string) (is_scalar($raw) ? $raw : ''));
    $out = [];
    foreach ($items ?: [] as $item) {
      $name = self::categoryName(is_scalar($item) ? (string) $item : '', $strict);
      if ($name !== '') {
        $out[mb_strtolower($name, 'UTF-8')] ??= $name;
      }
    }
    return array_values($out);
  }

  /**
   * @param mixed $raw
   * @return list<string> id_empleado
   */
  private static function employees($raw): array
  {
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $id) {
      $id = preg_replace('/[^0-9A-Za-z_-]/', '', is_scalar($id) ? (string) $id : '') ?? '';
      if ($id !== '') {
        $out[$id] = $id;
      }
    }
    return array_values($out);
  }
}
