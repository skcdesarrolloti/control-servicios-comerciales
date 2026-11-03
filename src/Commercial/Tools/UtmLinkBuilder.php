<?php

declare(strict_types=1);

namespace SCM\Commercial\Tools;

/**
 * Copia de dashboard-marketing (views/utm.php y la sección "UTM" de public/assets/app.js): si cambia la lógica,
 * cambiarla en ambos. Herramientas › Generador UTM: arma el enlace con utm_source, utm_medium, utm_campaign y, si se
 * escriben, utm_content y utm_term.
 *
 * Diferencias con el original: los valores se normalizan como slug (minúsculas, sin tildes ni espacios) para que el
 * tracker no parta una misma campaña en variantes, y la URL se arma como texto para respetar la consulta y el #fragmento
 * que ya traiga. public/assets/js/commercial-utm.js repite este algoritmo; los dos se prueban con
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
    $value = strtolower(strtr($value, self::ACCENTS));
    $value = (string) preg_replace('/[^a-z0-9-]+/', '_', $value);
    $value = (string) preg_replace('/[-_]*-[-_]*/', '-', $value);
    $value = trim($value, '-_');
    return trim(substr($value, 0, self::MAX_LENGTH), '-_');
  }

  /**
   * @param array<string,string> $values Claves de PARAMS; se normalizan con slug().
   * @throws \InvalidArgumentException Con el mensaje para el funcionario.
   */
  public static function build(string $url, array $values): string
  {
    $url = trim($url);
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
      $raw = trim((string) ($values[$field] ?? ''));
      $slug = self::slug($raw);
      if ($raw !== '' && $slug === '') {
        throw new \InvalidArgumentException('El valor de ' . $param . ' debe tener letras o números.');
      }
      if ($slug === '' && isset(self::REQUIRED[$field])) {
        throw new \InvalidArgumentException('Falta ' . self::REQUIRED[$field] . ' del enlace.');
      }
      if ($slug !== '') {
        $params[$param] = $slug;
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
}
