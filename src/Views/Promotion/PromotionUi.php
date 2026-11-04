<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

/**
 * Piezas comunes de las pantallas de Promoción (mismo lenguaje visual de Herramientas: encabezado sin fondo, tarjetas
 * blancas, indicadores y tablas). Todo lo que se imprime pasa por e().
 */
final class PromotionUi
{
  public static function e(mixed $value): string
  {
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function number(int|float $value): string
  {
    return number_format((float) $value, 0, ',', '.');
  }

  public static function percent(float $value): string
  {
    return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . ' %';
  }

  /** URL del panel: base/index.php?tab=... sin parámetros vacíos. @param array<string,mixed> $params */
  public static function url(string $baseUrl, array $params): string
  {
    $params = array_filter($params, static fn($value): bool => $value !== '' && $value !== null && $value !== false);
    return rtrim($baseUrl, '/') . '/index.php' . ($params !== [] ? '?' . http_build_query($params) : '');
  }

  /** dd/mm/aaaa de una fecha AAAA-MM-DD (con hora opcional). */
  public static function day(string $value, bool $withTime = false): string
  {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $value, $match) !== 1) {
      return $value;
    }
    return $match[3] . '/' . $match[2] . '/' . $match[1] . ($withTime && isset($match[4]) ? ' ' . $match[4] . ':' . $match[5] : '');
  }

  public static function hero(string $icon, string $title, string $text): string
  {
    return '<header class="cpr-hero"><div class="cpr-hero__text"><span class="cpr-eyebrow"><span class="material-symbols-outlined" aria-hidden="true">' . self::e($icon) . '</span>Promoción</span>'
      . '<h1>' . self::e($title) . '</h1><p>' . $text . '</p></div></header>';
  }

  public static function stat(string $label, string $value, string $hint = '', string $icon = ''): string
  {
    return '<article class="cpr-stat">' . ($icon !== '' ? '<span class="cpr-stat__icon material-symbols-outlined" aria-hidden="true">' . self::e($icon) . '</span>' : '')
      . '<span class="cpr-stat__label">' . self::e($label) . '</span><strong class="cpr-stat__value">' . self::e($value) . '</strong>'
      . ($hint !== '' ? '<span class="cpr-stat__hint">' . self::e($hint) . '</span>' : '') . '</article>';
  }

  /** @param list<string> $options @param array<string,string> $labels */
  public static function select(string $name, string $label, array $options, string $selected, string $all, array $labels = []): string
  {
    $html = '<option value="">' . self::e($all) . '</option>';
    foreach ($options as $value) {
      $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e($labels[$value] ?? $value) . '</option>';
    }
    return '<label class="cpr-field"><span class="cpr-label">' . self::e($label) . '</span><select name="' . self::e($name) . '">' . $html . '</select></label>';
  }

  public static function dateField(string $name, string $label, string $value, string $min, string $max): string
  {
    return '<label class="cpr-field"><span class="cpr-label">' . self::e($label) . '</span><input type="date" name="' . self::e($name) . '" value="' . self::e($value) . '" min="' . self::e($min) . '" max="' . self::e($max) . '" required></label>';
  }

  /**
   * Gráfico de barras por día (una sola serie: el título la nombra). Cada barra lleva su valor en un tooltip y la tabla
   * con los mismos datos queda disponible en "Ver datos".
   * @param list<array{day:string,value:int}> $series
   */
  public static function dailyChart(string $title, string $unit, array $series): string
  {
    // El máximo real se imprime; el de las alturas nunca es 0 para no dividir por cero.
    $max = max(0, ...array_map(static fn(array $point): int => (int) $point['value'], $series ?: [['value' => 0]]));
    $scale = max(1, $max);
    $total = array_sum(array_map(static fn(array $point): int => (int) $point['value'], $series));
    $bars = '';
    $rows = '';
    $count = count($series);
    foreach ($series as $index => $point) {
      $value = (int) $point['value'];
      $height = $value > 0 ? max(2, (int) round($value * 100 / $scale)) : 0;
      $label = self::day($point['day']) . ': ' . self::number($value) . ' ' . $unit;
      // Etiqueta del eje solo en el primero, el último y cada semana, para no amontonar fechas; la semanal se omite si
      // queda a menos de 3 barras de la última.
      $tick = $count <= 14 || $index === 0 || $index === $count - 1 || ($index % 7 === 0 && $count - 1 - $index >= 3);
      $bars .= '<li class="cpr-chart__bar" tabindex="0" aria-label="' . self::e($label) . '" data-cpr-tip="' . self::e($label) . '">'
        . '<span class="cpr-chart__fill" style="height:' . $height . '%"></span>'
        . ($tick ? '<span class="cpr-chart__tick">' . self::e(substr(self::day($point['day']), 0, 5)) . '</span>' : '') . '</li>';
      $rows .= '<tr><td>' . self::e(self::day($point['day'])) . '</td><td class="is-num">' . self::e(self::number($value)) . '</td></tr>';
    }
    $head = '<section class="cpr-card cpr-chart" data-cpr-chart><div class="cpr-card__head"><div><h2>' . self::e($title) . '</h2><p>';
    if ($total === 0) {
      // Sin datos no hay gráfico ni un "máximo" inventado.
      return $head . 'Sin ' . self::e($unit) . ' en el rango</p></div></div><p class="cpr-empty" data-cpr-chart-empty>Sin ' . self::e($unit) . ' en el rango elegido.</p></section>';
    }
    return $head . self::e(self::number($total)) . ' ' . self::e($unit) . ' en el rango · máximo ' . self::e(self::number($max)) . ' en un día</p></div></div>'
      . '<div class="cpr-chart__plot"><span class="cpr-chart__max" aria-hidden="true">' . self::e(self::number($max)) . '</span><ol class="cpr-chart__bars" style="--cpr-bars:' . max(1, $count) . '">' . $bars . '</ol></div>'
      . '<details class="cpr-chart__table"><summary>Ver datos</summary><div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Día</th><th class="is-num">' . self::e(ucfirst($unit)) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details></section>';
  }

  /**
   * Tabla simple dentro de una tarjeta.
   * @param list<array{0:string,1?:bool}> $headers [texto, numérico]
   * @param list<list<string>> $rows Celdas ya escapadas.
   */
  public static function tableCard(string $title, string $subtitle, array $headers, array $rows, string $empty, string $attributes = ''): string
  {
    $head = '';
    foreach ($headers as $header) {
      $head .= '<th' . (!empty($header[1]) ? ' class="is-num"' : '') . '>' . self::e($header[0]) . '</th>';
    }
    $body = '';
    foreach ($rows as $row) {
      $body .= '<tr>';
      foreach ($row as $index => $cell) {
        $body .= '<td' . (!empty($headers[$index][1]) ? ' class="is-num"' : '') . '>' . $cell . '</td>';
      }
      $body .= '</tr>';
    }
    return '<section class="cpr-card"' . $attributes . '><div class="cpr-card__head"><div><h2>' . self::e($title) . '</h2>' . ($subtitle !== '' ? '<p>' . self::e($subtitle) . '</p>' : '') . '</div></div>'
      . ($rows === [] ? '<p class="cpr-empty">' . self::e($empty) . '</p>' : '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>')
      . '</section>';
  }
}
