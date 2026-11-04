<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Core\Settings;

/**
 * Configuración › Material POP (propia del panel, en Settings):
 * - categorías que se ofrecen al crear o editar un producto (dashboard-marketing las guardaba en
 *   storage/inventory-categories.json; aquí se administran desde el menú Configuración);
 * - alerta de stock bajo: si está activa, a qué funcionarios se les encola el correo (shared-notifications) cuando un
 *   producto llega a su stock mínimo o se agota.
 */
final class PopConfig
{
  public const KEY = 'commercial_pop_config';
  public const DEFAULT_CATEGORIES = ['PPH', 'Souvenir'];

  /** @return array{categories:list<string>,alert_enabled:bool,alert_employees:list<string>} */
  public static function read(Settings $settings): array
  {
    $raw = $settings->get(self::KEY, []);
    $raw = is_array($raw) ? $raw : [];
    $categories = self::categories($raw['categories'] ?? null);
    return [
      'categories' => $categories !== [] ? $categories : self::DEFAULT_CATEGORIES,
      'alert_enabled' => !empty($raw['alert_enabled']),
      'alert_employees' => self::employees($raw['alert_employees'] ?? []),
    ];
  }

  /**
   * @param array<string,mixed> $data categories (lista o texto con una por línea), alert_enabled, alert_employees.
   * @return array{categories:list<string>,alert_enabled:bool,alert_employees:list<string>}
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
    $config = ['categories' => $categories, 'alert_enabled' => $enabled, 'alert_employees' => $employees];
    $settings->set(self::KEY, $config, $userId);
    return $config;
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
