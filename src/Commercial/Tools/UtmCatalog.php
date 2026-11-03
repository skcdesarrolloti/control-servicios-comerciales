<?php

declare(strict_types=1);

namespace SCM\Commercial\Tools;

use SCM\Core\Settings;

/**
 * Catálogo compartido del Generador UTM: medios, fuentes y campañas (alias con nombre visible). En dashboard-marketing
 * (public/assets/app.js, UTM_DEFAULTS y localStorage "dashboardMarketing.utm.*") cada navegador guardaba sus propias
 * opciones; aquí lo edita un administrador en Configuración › Generador UTM y se guarda en Settings
 * (control_servicios_comerciales_config), igual para todo el equipo.
 */
final class UtmCatalog
{
  public const SETTING = 'commercial_utm_catalog';

  /** Lista => [título, parámetro que llena]. */
  public const LISTS = [
    'medios' => ['Medios / canales', 'utm_medium'],
    'fuentes' => ['Fuentes / recursos', 'utm_source'],
    'campanas' => ['Campañas', 'utm_campaign'],
  ];

  public const MAX_ITEMS = 80;
  public const MAX_LABEL = 60;

  /** Slugs que el tracker usa para el tráfico sin campaña: no pueden ser el nombre de una campaña. */
  public const RESERVED_CAMPAIGNS = UtmLinkBuilder::RESERVED_CAMPAIGNS;

  /** Opciones por defecto de dashboard-marketing (UTM_DEFAULTS) mientras no se guarde el catálogo. */
  private const DEFAULTS = [
    'medios' => ['WhatsApp', 'Correo electrónico', 'Redes sociales', 'SMS', 'Publicidad pagada', 'QR Code', 'Email', 'Display', 'Orgánico', 'Referido'],
    'fuentes' => ['Facebook', 'Instagram', 'TikTok', 'Google', 'Base de datos', 'Estado WhatsApp', 'Landing page', 'Portal web', 'YouTube', 'LinkedIn'],
    'campanas' => [],
  ];

  /** @return array{medios:list<array{label:string,value:string}>,fuentes:list<array{label:string,value:string}>,campanas:list<array{label:string,value:string}>} */
  public static function defaults(): array
  {
    $out = [];
    foreach (self::DEFAULTS as $list => $labels) {
      $out[$list] = array_map(static fn(string $label): array => ['label' => $label, 'value' => UtmLinkBuilder::slug($label)], $labels);
    }
    /** @var array{medios:list<array{label:string,value:string}>,fuentes:list<array{label:string,value:string}>,campanas:list<array{label:string,value:string}>} $out */
    return $out;
  }

  /**
   * Catálogo vigente; lo guardado se vuelve a limpiar al leer (filas inválidas se omiten).
   * @return array{medios:list<array{label:string,value:string}>,fuentes:list<array{label:string,value:string}>,campanas:list<array{label:string,value:string}>}
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
   * Valida y guarda las tres listas.
   * @param array<string,mixed> $data
   * @throws \InvalidArgumentException Con el mensaje para el administrador.
   */
  public static function save(Settings $settings, array $data, int $userId): void
  {
    $clean = [];
    foreach (array_keys(self::LISTS) as $list) {
      $clean[$list] = self::sanitizeList($list, is_array($data[$list] ?? null) ? $data[$list] : [], true);
    }
    if ($clean['medios'] === [] || $clean['fuentes'] === []) {
      throw new \InvalidArgumentException('Deja al menos un medio y una fuente en el catálogo.');
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
