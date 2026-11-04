<?php

declare(strict_types=1);

namespace SCM\Commercial\Tools;

use SCM\Core\Settings;

/**
 * Catálogo compartido del Generador UTM: las campañas (alias con nombre visible). En dashboard-marketing
 * (public/assets/app.js, UTM_DEFAULTS y localStorage "dashboardMarketing.utm.*") cada navegador guardaba sus propias
 * opciones; aquí lo edita un administrador en Configuración › Generador UTM y se guarda en Settings
 * (control_servicios_comerciales_config), igual para todo el equipo.
 *
 * Los medios y fuentes ya no son listas de este catálogo: el generador ofrece un solo selector con la lista única de
 * Medios (MedioCatalog, Configuración › Medios) y llena utm_source y utm_medium con el mismo slug. Las listas "medios" y
 * "fuentes" que hubiera guardadas se conservan sin cambios en Settings (no se leen ni se borran).
 */
final class UtmCatalog
{
  public const SETTING = 'commercial_utm_catalog';

  /** Lista => [título, parámetro que llena]. */
  public const LISTS = [
    'campanas' => ['Campañas', 'utm_campaign'],
  ];

  /** Listas de versiones anteriores que se conservan tal cual al guardar. */
  private const LEGACY_LISTS = ['medios', 'fuentes'];

  public const MAX_ITEMS = 80;
  public const MAX_LABEL = 60;

  /** Slugs que el tracker usa para el tráfico sin campaña: no pueden ser el nombre de una campaña. */
  public const RESERVED_CAMPAIGNS = UtmLinkBuilder::RESERVED_CAMPAIGNS;

  /** @return array{campanas:list<array{label:string,value:string}>} */
  public static function defaults(): array
  {
    return ['campanas' => []];
  }

  /**
   * Catálogo vigente; lo guardado se vuelve a limpiar al leer (filas inválidas se omiten).
   * @return array{campanas:list<array{label:string,value:string}>}
   */
  public static function read(Settings $settings): array
  {
    try {
      $raw = $settings->get(self::SETTING, null);
    } catch (\Throwable) {
      $raw = null;
    }
    if (!is_array($raw)) {
      return self::defaults();
    }
    $out = self::defaults();
    foreach (array_keys(self::LISTS) as $list) {
      if (is_array($raw[$list] ?? null)) {
        $out[$list] = self::sanitizeList($list, $raw[$list], false);
      }
    }
    return $out;
  }

  /**
   * Valida y guarda las campañas (las listas anteriores de medios y fuentes se conservan como estaban).
   * @param array<string,mixed> $data
   * @throws \InvalidArgumentException Con el mensaje para el administrador.
   */
  public static function save(Settings $settings, array $data, int $userId): void
  {
    $clean = [];
    foreach (array_keys(self::LISTS) as $list) {
      $clean[$list] = self::sanitizeList($list, is_array($data[$list] ?? null) ? $data[$list] : [], true);
    }
    try {
      $stored = $settings->get(self::SETTING, null);
    } catch (\Throwable) {
      $stored = null;
    }
    foreach (self::LEGACY_LISTS as $legacy) {
      if (is_array($stored) && array_key_exists($legacy, $stored)) {
        $clean[$legacy] = $stored[$legacy];
      }
    }
    $settings->set(self::SETTING, $clean, $userId);
    $settings->refresh();
  }

  /**
   * Cada fila es {label, value}: el valor es el slug que va en la URL (si viene vacío se toma del nombre) y el nombre
   * es lo que ve el funcionario. Se descartan filas vacías y valores repetidos (gana la primera).
   * @param array<int|string,mixed> $rows
   * @return list<array{label:string,value:string}>
   */
  public static function sanitizeList(string $list, array $rows, bool $strict): array
  {
    $title = self::LISTS[$list][0] ?? $list;
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      if (is_string($row)) {
        $row = ['label' => $row];
      }
      if (!is_array($row)) {
        continue;
      }
      $label = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_scalar($row['label'] ?? null) ? (string) $row['label'] : '')));
      $rawValue = trim(is_scalar($row['value'] ?? null) ? (string) $row['value'] : '');
      if ($label === '' && $rawValue === '') {
        continue;
      }
      try {
        if (mb_strlen($label) > self::MAX_LABEL) {
          throw new \InvalidArgumentException('En ' . $title . ', el nombre "' . mb_substr($label, 0, 30) . '…" supera ' . self::MAX_LABEL . ' caracteres.');
        }
        $source = $rawValue !== '' ? $rawValue : $label;
        if (mb_strlen($source) > UtmLinkBuilder::MAX_LENGTH) {
          throw new \InvalidArgumentException('En ' . $title . ', el valor de "' . mb_substr($source, 0, 30) . '…" supera ' . UtmLinkBuilder::MAX_LENGTH . ' caracteres.');
        }
        $value = UtmLinkBuilder::slug($source);
        if ($value === '') {
          throw new \InvalidArgumentException('En ' . $title . ', "' . $source . '" debe tener letras o números.');
        }
        if ($list === 'campanas' && in_array($value, self::RESERVED_CAMPAIGNS, true)) {
          throw new \InvalidArgumentException(UtmLinkBuilder::reservedMessage($value));
        }
      } catch (\InvalidArgumentException $exception) {
        if ($strict) {
          throw $exception;
        }
        continue;
      }
      if (isset($seen[$value])) {
        continue;
      }
      $seen[$value] = true;
      $out[] = ['label' => $label !== '' ? $label : $value, 'value' => $value];
    }
    if (count($out) > self::MAX_ITEMS) {
      if ($strict) {
        throw new \InvalidArgumentException('En ' . $title . ' caben hasta ' . self::MAX_ITEMS . ' opciones.');
      }
      $out = array_slice($out, 0, self::MAX_ITEMS);
    }
    return $out;
  }
}
