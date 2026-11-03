<?php

declare(strict_types=1);

namespace SCM\Commercial\Tools;

/**
 * Copia de dashboard-marketing (views/utm.php y la sección "UTM" de public/assets/app.js): si cambia la lógica,
 * cambiarla en ambos. Herramientas › Generador UTM: arma el enlace con utm_source, utm_medium, utm_campaign y, si se
 * escriben, utm_content y utm_term.
 *
 * Diferencias con el original: los valores se normalizan como slug (minúsculas, sin tildes ni espacios) para que el
 * tracker no parta una misma campaña en variantes (salvo los que ya registró el tracker, que van tal cual como en el
 * original), las campañas reservadas del tracker se rechazan, y la URL se arma como texto para respetar la consulta y el
 * #fragmento que ya traiga. public/assets/js/commercial-utm.js repite este algoritmo; los dos se prueban con
 * tests/Fixtures/utm-cases.json (PHPUnit y tools/check-commercial-tools.cjs).
 */
final class UtmLinkBuilder
{
  /** Campo del formulario => parámetro de la URL, en el orden en que se agregan. */
  public const PARAMS = [
    'source' => 'utm_source',
    'medium' => 'utm_medium',
    'campaign' => 'utm_campaign',
    'content' => 'utm_content',
    'term' => 'utm_term',
  ];

  /** Los que exige el enlace: sin fuente, medio y campaña el tracker no puede atribuir la visita. */
  public const REQUIRED = ['source' => 'la fuente', 'medium' => 'el medio', 'campaign' => 'la campaña'];

  /** Largo máximo de un valor normalizado (el tracker guarda hasta 120 caracteres). */
  public const MAX_LENGTH = 100;

  /** Bytes (UTF-8) máximos de la URL de destino. */
  public const MAX_URL_LENGTH = 2000;

  /**
   * Campañas que el tracker de dashboard-marketing (app/TrackerRepository.php, ensureCampaign) usa para el tráfico sin
   * campaña: un enlace con una de ellas pierde su atribución.
   */
  public const RESERVED_CAMPAIGNS = ['general', 'inmueble_view', 'event_click'];

  /** Caracteres que guarda el tracker por valor (TrackerRepository::text): un valor suyo se envía tal cual hasta ese largo. */
  public const MAX_RAW_LENGTH = 120;

  /** Los mismos espacios que quita String.prototype.trim() en JS, para que PHP y commercial-utm.js recorten igual. */
  private const SPACES = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

  /** Letras con tilde que se cambian por su letra base; el resto de caracteres no alfanuméricos separa palabras. */
  private const ACCENTS = [
    'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a',
    'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
    'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
    'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
    'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
  ];

  /**
   * Minúsculas sin tildes; cualquier otro carácter (espacios, signos) pasa a "_" y los guiones se conservan, para que
   * los slugs que ya existen en el tracker (esencia-inmobiliaria-edicion-junio-2026) no cambien.
   */
  public static function slug(string $value): string
  {
    // Las tildes que llegan separadas de su letra (texto en NFD, copiado de macOS o de un PDF) se quitan antes.
    $value = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $value) ?? $value;
    $value = strtolower(strtr($value, self::ACCENTS));
    $value = (string) preg_replace('/[^a-z0-9-]+/', '_', $value);
    $value = (string) preg_replace('/[-_]*-[-_]*/', '-', $value);
    $value = trim($value, '-_');
    return trim(substr($value, 0, self::MAX_LENGTH), '-_');
  }

  /** Quita los espacios del inicio y del final, incluidos los Unicode (espacio duro, U+3000, BOM). */
  public static function trimText(string $value): string
  {
    return preg_replace('/^[' . self::SPACES . ']+|[' . self::SPACES . ']+$/u', '', $value) ?? trim($value);
  }

  public static function reservedMessage(string $value): string
  {
    return '"' . $value . '" lo usa el tracker para las visitas sin campaña: elige otro nombre de campaña.';
  }

  /**
   * @param array<string,string> $values Claves de PARAMS; se normalizan con slug().
   * @param list<string> $verbatim Campos (claves de PARAMS) que se envían tal cual porque ya los registró el tracker:
   *   así el enlace suma a la misma campaña, fuente o medio en lugar de crear una variante normalizada.
   * @throws \InvalidArgumentException Con el mensaje para el funcionario.
   */
  public static function build(string $url, array $values, array $verbatim = []): string
  {
    $url = self::trimText($url);
    if ($url === '') {
      throw new \InvalidArgumentException('Escribe la URL de destino.');
    }
    if (strlen($url) > self::MAX_URL_LENGTH) {
      throw new \InvalidArgumentException('La URL de destino es demasiado larga.');
    }
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
      throw new \InvalidArgumentException('La URL de destino no puede tener espacios.');
    }
    if (!preg_match('/^https?:\/\//i', $url)) {
      $url = 'https://' . $url;
    }
    if (!preg_match('/^https?:\/\/(localhost|([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,})(:\d{1,5})?([\/?#]|$)/i', $url)) {
      throw new \InvalidArgumentException('La URL de destino no es válida: debe ser una página web (https://…).');
    }

    $params = [];
    foreach (self::PARAMS as $field => $param) {
      $raw = self::trimText((string) ($values[$field] ?? ''));
      if (in_array($field, $verbatim, true)) {
        $value = self::verbatim($param, $raw);
      } else {
        $value = self::slug($raw);
        if ($raw !== '' && $value === '') {
          throw new \InvalidArgumentException('El valor de ' . $param . ' debe tener letras o números.');
        }
      }
      if ($value === '' && isset(self::REQUIRED[$field])) {
        throw new \InvalidArgumentException('Falta ' . self::REQUIRED[$field] . ' del enlace.');
      }
      // El tracker compara sin distinguir mayúsculas (collation de MySQL): "General" también es tráfico sin campaña.
      if ($field === 'campaign' && in_array(strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), self::RESERVED_CAMPAIGNS, true)) {
        throw new \InvalidArgumentException(self::reservedMessage($value));
      }
      if ($value !== '') {
        $params[$param] = $value;
      }
    }

    $hash = strpos($url, '#');
    $fragment = $hash === false ? '' : substr($url, $hash);
    $rest = $hash === false ? $url : substr($url, 0, $hash);
    $mark = strpos($rest, '?');
    $path = $mark === false ? $rest : substr($rest, 0, $mark);
    $query = $mark === false ? '' : substr($rest, $mark + 1);
    if (preg_match('/^https?:\/\/[^\/]+$/i', $path)) {
      $path .= '/';
    }

    // Se conservan los parámetros que ya traía la URL; los utm_* que se van a escribir se reemplazan.
    $pairs = [];
    foreach (explode('&', $query) as $pair) {
      if ($pair === '') {
        continue;
      }
      $equal = strpos($pair, '=');
      $key = rawurldecode(str_replace('+', ' ', $equal === false ? $pair : substr($pair, 0, $equal)));
      if (!isset($params[$key])) {
        $pairs[] = $pair;
      }
    }
    foreach ($params as $param => $value) {
      $pairs[] = $param . '=' . rawurlencode($value);
    }
    return $path . '?' . implode('&', $pairs) . $fragment;
  }

  /** Un valor del tracker va sin normalizar; solo se rechaza lo que el tracker tampoco guardaría igual. */
  private static function verbatim(string $param, string $value): string
  {
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
      throw new \InvalidArgumentException('El valor de ' . $param . ' no puede tener saltos de línea ni caracteres de control.');
    }
    if (mb_strlen($value, 'UTF-8') > self::MAX_RAW_LENGTH) {
      throw new \InvalidArgumentException('El valor de ' . $param . ' supera ' . self::MAX_RAW_LENGTH . ' caracteres.');
    }
    return $value;
  }
}
