<?php

declare(strict_types=1);

namespace SCM\Commercial\Quotes;

use SCM\Views\CommercialDashboardView;

/**
 * Comparación de los inmuebles de una cotización: una fila por dato comparable (precio, área, precio por m², habitaciones,
 * características…) con el mejor marcado, lo que tiene cada uno y las ventajas de cada inmueble frente a los demás.
 * Solo trabaja con los datos ya normalizados: no consulta la base.
 */
final class QuoteComparison
{
  /** Grupos de características de jet_cct_inmuebles con su título para el cliente. */
  public const FEATURE_GROUPS = [
    'interiores' => 'Interiores',
    'exteriores' => 'Exteriores',
    'zonas_sociales' => 'Zonas sociales',
    'alrededores' => 'Alrededores',
  ];

  /**
   * Datos de una fila de jet_cct_inmuebles listos para comparar. $type es el tipo de la cotización (Arriendo o Venta):
   * define qué precio se compara.
   * @param array<string,mixed> $row
   * @return array<string,mixed>
   */
  public static function normalize(array $row, string $type): array
  {
    $rent = CommercialDashboardView::parsePrice($row['precio_arriendo'] ?? null);
    $sale = CommercialDashboardView::parsePrice($row['precio_venta'] ?? null);
    $isRent = mb_strtolower($type, 'UTF-8') === 'arriendo';
    $price = $isRent ? ($rent > 0 ? $rent : $sale) : ($sale > 0 ? $sale : $rent);
    $priceKind = $price <= 0 ? '' : ($price === $rent && ($isRent || $sale <= 0) ? 'Arriendo' : 'Venta');
    $area = 0.0;
    foreach (['area_construida', 'area_privada', 'area_terreno'] as $column) {
      $area = CommercialDashboardView::parsePrice($row[$column] ?? null);
      if ($area > 0) {
        break;
      }
    }
    $features = [];
    foreach (array_keys(self::FEATURE_GROUPS) as $group) {
      $features[$group] = array_values(array_unique(CommercialDashboardView::parseFeatureList($row[$group] ?? '')));
    }
    $age = trim((string) ($row['edad'] ?? ''));
    return [
      'codigo' => trim((string) ($row['codigo'] ?? $row['_ID'] ?? '')),
      'tipo_inmueble' => trim((string) ($row['tipo_inmueble'] ?? '')) ?: 'Inmueble',
      'tipo_negocio' => trim((string) ($row['tipo_negocio'] ?? '')),
      'barrio' => trim((string) ($row['barrio'] ?? '')),
      'ciudad' => trim((string) ($row['ciudad'] ?? '')),
      'precio' => $price,
      'precio_tipo' => $priceKind,
      'precio_arriendo' => $rent,
      'precio_venta' => $sale,
      'admin' => CommercialDashboardView::parsePrice($row['precio_admin'] ?? null),
      'area' => $area,
      'habitaciones' => (int) self::number($row['habitaciones'] ?? null),
      'banos' => (int) self::number($row['banos'] ?? null),
      'parqueaderos' => (int) self::number($row['parqueaderos'] ?? $row['parqueadero'] ?? null),
      'estrato' => (int) self::number($row['estrato'] ?? null),
      'edad' => $age !== '' && is_numeric($age) ? (int) $age : null,
      'features' => $features,
    ];
  }

  /**
   * @param list<array<string,mixed>> $items Salida de normalize(), en el orden de la cotización.
   * @return array{rows:list<array{key:string,label:string,values:list<string>,best:list<int>}>,features:list<array{group:string,label:string,items:list<array{name:string,has:list<bool>}>}>,advantages:list<list<string>>}
   */
  public static function compare(array $items, string $type): array
  {
    $count = count($items);
    $isRent = mb_strtolower($type, 'UTF-8') === 'arriendo';
    $money = static fn(float $value): string => $value > 0 ? self::money($value) : '—';
    $plain = static fn(float $value): string => $value > 0 ? number_format($value, 0, ',', '.') : '—';
    $metric = static fn(string $key): array => array_map(static fn(array $item): float => (float) ($item[$key] ?? 0), $items);
    $pricePerMeter = array_map(static fn(array $item): float => $item['precio'] > 0 && $item['area'] > 0 ? $item['precio'] / $item['area'] : 0.0, $items);
    $monthly = array_map(static fn(array $item): float => $item['precio'] > 0 ? $item['precio'] + max(0.0, (float) $item['admin']) : 0.0, $items);
    $ages = array_map(static fn(array $item): float => $item['edad'] === null ? 0.0 : (float) $item['edad'], $items);
    $featureCounts = [];
    foreach (array_keys(self::FEATURE_GROUPS) as $group) {
      $featureCounts[$group] = array_map(static fn(array $item): float => (float) count($item['features'][$group] ?? []), $items);
    }

    $format = static fn(array $values, callable $formatter): array => array_map($formatter, $values);
    $places = array_map(static fn(array $item): string => implode(', ', array_filter([$item['barrio'], $item['ciudad']])) ?: '—', $items);
    $kinds = array_map(static fn(array $item): string => (string) $item['tipo_inmueble'], $items);
    $ageTexts = array_map(static fn(array $item): string => $item['edad'] === null ? '—' : ($item['edad'] === 0 ? 'Nuevo' : (string) $item['edad']), $items);
    $ageKnown = array_map(static fn(array $item): bool => $item['edad'] !== null, $items);
    $none = array_fill(0, $count, 0.0);
    $areaText = static fn(float $value): string => $value > 0 ? self::area($value) : '—';

    // [clave, etiqueta, valores, textos, qué gana (low|high|none), ventaja del mejor, con dato (si 0 vale), ventaja con el dato]
    $definitions = [
      ['ubicacion', 'Ubicación', $none, $places, 'none', '', null, false],
      ['tipo', 'Tipo de inmueble', $none, $kinds, 'none', '', null, false],
      ['precio', $isRent ? 'Canon de arriendo' : 'Precio de venta', $metric('precio'), $format($metric('precio'), $money), 'low', $isRent ? 'El canon más bajo' : 'El precio más bajo', null, true],
      ['admin', 'Administración', $metric('admin'), $format($metric('admin'), $money), 'low', 'La administración más baja', null, true],
    ];
    if ($isRent) {
      $definitions[] = ['mensual', 'Pago mensual (canon + administración)', $monthly, $format($monthly, $money), 'low', 'El pago mensual más bajo', null, true];
    }
    array_push($definitions,
      ['area', 'Área', $metric('area'), $format($metric('area'), $areaText), 'high', 'La mayor área', null, true],
      ['m2', $isRent ? 'Canon por m²' : 'Precio por m²', $pricePerMeter, $format($pricePerMeter, $money), 'low', 'El mejor valor por m²', null, true],
      ['habitaciones', 'Habitaciones', $metric('habitaciones'), $format($metric('habitaciones'), $plain), 'high', 'Más habitaciones', null, true],
      ['banos', 'Baños', $metric('banos'), $format($metric('banos'), $plain), 'high', 'Más baños', null, true],
      ['parqueaderos', 'Parqueaderos', $metric('parqueaderos'), $format($metric('parqueaderos'), $plain), 'high', 'Más parqueaderos', null, true],
      ['estrato', 'Estrato', $metric('estrato'), $format($metric('estrato'), $plain), 'none', '', null, false],
      ['edad', 'Antigüedad (años)', $ages, $ageTexts, 'low', 'El más nuevo', $ageKnown, false],
    );
    foreach (self::FEATURE_GROUPS as $group => $label) {
      $texts = $format($featureCounts[$group], static fn(float $value): string => $value > 0 ? (string) (int) $value . ' ' . ($value === 1.0 ? 'característica' : 'características') : '—');
      $definitions[] = ['caracteristicas_' . $group, $label, $featureCounts[$group], $texts, 'high', 'Más ' . mb_strtolower($label, 'UTF-8'), null, true];
    }

    $rows = [];
    $advantages = array_fill(0, $count, []);
    foreach ($definitions as [$key, $label, $values, $texts, $goal, $advantage, $known, $withDetail]) {
      if (count(array_filter($texts, static fn(string $text): bool => $text !== '—')) === 0) {
        continue;
      }
      $best = $goal === 'none' ? [] : self::best($values, $goal, $known);
      foreach ($best as $index) {
        if ($advantage !== '') {
          $advantages[$index][] = $advantage . ($withDetail ? ' (' . $texts[$index] . ')' : '');
        }
      }
      $rows[] = ['key' => $key, 'label' => $label, 'values' => $texts, 'best' => $best];
    }

    $features = [];
    foreach (self::FEATURE_GROUPS as $group => $label) {
      $names = [];
      foreach ($items as $item) {
        foreach ($item['features'][$group] ?? [] as $name) {
          $names[mb_strtolower($name, 'UTF-8')] ??= $name;
        }
      }
      if ($names === []) {
        continue;
      }
      natcasesort($names);
      $list = [];
      foreach ($names as $lower => $name) {
        $has = array_map(static fn(array $item): bool => in_array($lower, array_map(static fn(string $value): string => mb_strtolower($value, 'UTF-8'), $item['features'][$group] ?? []), true), $items);
        $list[] = ['name' => $name, 'has' => $has];
        $owners = array_keys(array_filter($has));
        if ($count > 1 && count($owners) === 1) {
          $advantages[$owners[0]]['unicos'][] = $name;
        }
      }
      $features[] = ['group' => $group, 'label' => $label, 'items' => $list];
    }

    $out = [];
    foreach ($advantages as $list) {
      $unique = $list['unicos'] ?? [];
      unset($list['unicos']);
      $list = array_values($list);
      if ($unique !== []) {
        $shown = array_slice($unique, 0, 6);
        $list[] = 'Es el único con ' . self::join($shown) . (count($unique) > count($shown) ? ' y ' . (count($unique) - count($shown)) . ' más' : '');
      }
      $out[] = $list;
    }
    return ['rows' => $rows, 'features' => $features, 'advantages' => $count > 1 ? $out : array_fill(0, $count, [])];
  }

  /**
   * Índices del mejor valor. Sin datos (0) no compite; si menos de dos tienen dato o todos empatan, no hay mejor.
   * @param list<float> $values
   * @param list<bool>|null $known Para datos donde 0 es válido (antigüedad 0 = nuevo).
   * @return list<int>
   */
  private static function best(array $values, string $goal, ?array $known = null): array
  {
    $candidates = [];
    foreach ($values as $index => $value) {
      if ($known !== null ? $known[$index] : $value > 0) {
        $candidates[$index] = $value;
      }
    }
    if (count($candidates) < 2 || count(array_unique(array_map(static fn(float $value): string => (string) round($value, 4), $candidates))) === 1) {
      return [];
    }
    $target = $goal === 'low' ? min($candidates) : max($candidates);
    return array_keys(array_filter($candidates, static fn(float $value): bool => abs($value - $target) < 0.0001));
  }

  public static function money(float $value): string
  {
    return '$ ' . number_format($value, 0, ',', '.');
  }

  public static function area(float $value): string
  {
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . ' m²';
  }

  /** @param list<string> $items */
  private static function join(array $items): string
  {
    if (count($items) < 2) {
      return implode('', $items);
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' y ' . $last;
  }

  /** @param mixed $value */
  private static function number($value): float
  {
    if ($value === null || $value === '') {
      return 0.0;
    }
    $text = str_replace(',', '.', (string) preg_replace('/[^\d,.]/', '', (string) $value));
    return is_numeric($text) ? (float) $text : 0.0;
  }
}
