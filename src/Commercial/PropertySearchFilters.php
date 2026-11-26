<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Database;
use SCM\Views\CommercialDashboardView;

/**
 * Filtros de las características de un inmueble (precio, área en m², habitaciones, baños, parqueaderos, estrato,
 * antigüedad, amoblado, permuta, depósito, vigilancia, negociable) que comparten las vistas de Inmuebles y el buscador
 * de la cotización. Trabaja sobre `jet_cct_inmuebles` con el alias `i`; los números se guardan como texto (el arriendo
 * a veces con puntos de miles), así que se convierten en SQL igual que CommercialDashboardView::parsePrice.
 */
final class PropertySearchFilters
{
  /** Rangos (valor mínimo o máximo). */
  public const RANGE_KEYS = ['precio_min', 'precio_max', 'area_min', 'area_max'];
  /** Mínimos: "N o más". */
  public const MIN_KEYS = ['habitaciones' => 'habitaciones', 'banos' => 'banos', 'parqueaderos' => 'parqueaderos'];
  /** Sí / No: columna => texto visible. Amoblado, depósito y vigilancia vienen serializados (a:1:{i:0;s:2:"Si";}). */
  public const YES_NO = [
    'amoblado' => 'Amoblado',
    'acepta_permuta' => 'Acepta permuta',
    'deposito' => 'Depósito',
    'vigilancia' => 'Vigilancia',
    'negociable' => 'Negociable',
  ];
  public const KEYS = ['precio_min', 'precio_max', 'area_min', 'area_max', 'habitaciones', 'banos', 'parqueaderos', 'estrato', 'edad_max', 'amoblado', 'acepta_permuta', 'tipo_permuta', 'deposito', 'vigilancia', 'negociable'];
  /** Opciones de "N o más". */
  public const MIN_OPTIONS = ['1' => '1 o más', '2' => '2 o más', '3' => '3 o más', '4' => '4 o más', '5' => '5 o más'];
  /** Antigüedad máxima en años. */
  public const AGE_OPTIONS = ['1' => 'Hasta 1 año', '5' => 'Hasta 5 años', '10' => 'Hasta 10 años', '20' => 'Hasta 20 años', '30' => 'Hasta 30 años'];

  /**
   * Valores limpios de la petición: los rangos en número (vacío si no son válidos) y los Sí/No solo como "Si" o "No".
   * @param array<string,mixed> $input
   * @return array<string,string>
   */
  public static function clean(array $input): array
  {
    $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    $out = [];
    foreach (self::RANGE_KEYS as $key) {
      $value = CommercialDashboardView::parsePrice($text($key));
      $out[$key] = $value > 0 ? (string) (float) $value : '';
      if ($out[$key] !== '' && floor($value) === $value) {
        $out[$key] = (string) (int) $value;
      }
    }
    foreach (array_keys(self::MIN_KEYS) as $key) {
      $value = (int) $text($key);
      $out[$key] = $value > 0 ? (string) min($value, 99) : '';
    }
    $age = (int) $text('edad_max');
    $out['edad_max'] = $age > 0 ? (string) min($age, 200) : '';
    $out['estrato'] = mb_substr($text('estrato'), 0, 40);
    $out['tipo_permuta'] = mb_substr($text('tipo_permuta'), 0, 60);
    foreach (array_keys(self::YES_NO) as $key) {
      $value = mb_strtolower($text($key), 'UTF-8');
      $out[$key] = in_array($value, ['si', 'sí'], true) ? 'Si' : ($value === 'no' ? 'No' : '');
    }
    return $out;
  }

  /** Cuántos de estos filtros están puestos (para abrir "Más filtros" y mostrar su conteo). @param array<string,mixed> $filters */
  public static function activeCount(array $filters): int
  {
    $count = 0;
    foreach (self::KEYS as $key) {
      if (trim((string) ($filters[$key] ?? '')) !== '') {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Condiciones SQL de los filtros puestos. $priceKind dice qué precio se compara: 'Arriendo', 'Venta' o '' (cualquiera
   * de los dos que esté en el rango). Sin $withPrice no filtra por precio (la cotización lo compara en PHP).
   * @param array<string,mixed> $filters Salida de clean().
   * @return array{0:list<string>,1:list<mixed>}
   */
  public static function sql(array $filters, string $priceKind = '', bool $withPrice = true): array
  {
    $where = [];
    $args = [];
    $value = static fn(string $key): string => trim((string) ($filters[$key] ?? ''));

    if ($withPrice) {
      $min = (float) $value('precio_min');
      $max = (float) $value('precio_max');
      if ($min > 0 || $max > 0) {
        $kind = mb_strtolower($priceKind, 'UTF-8');
        $columns = $kind === 'arriendo' ? ['precio_arriendo'] : ($kind === 'venta' ? ['precio_venta'] : ['precio_arriendo', 'precio_venta']);
        $parts = [];
        foreach ($columns as $column) {
          $parts[] = self::between(self::number("i.`{$column}`"), $min, $max);
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    $areaMin = (float) $value('area_min');
    $areaMax = (float) $value('area_max');
    if ($areaMin > 0 || $areaMax > 0) {
      $where[] = self::between(self::area(), $areaMin, $areaMax);
    }

    foreach (self::MIN_KEYS as $key => $column) {
      $min = (int) $value($key);
      if ($min > 0) {
        $where[] = self::number("i.`{$column}`") . ' >= ' . $min;
      }
    }

    $age = (int) $value('edad_max');
    if ($age > 0) {
      // Solo las edades escritas como número: "Buen estado" o "Más de 34 años" no se pueden comparar.
      $where[] = "(TRIM(COALESCE(i.`edad`, '')) REGEXP '^[0-9]+$' AND TRIM(i.`edad`) + 0 <= {$age})";
    }

    if ($value('estrato') !== '') {
      $where[] = 'TRIM(i.`estrato`) = ?';
      $args[] = $value('estrato');
    }

    if ($value('tipo_permuta') !== '') {
      $where[] = '(i.`tipo_permuta` LIKE ? OR TRIM(i.`tipo_permuta`) = ?)';
      $args[] = '%"' . self::escapeLike($value('tipo_permuta')) . '"%';
      $args[] = $value('tipo_permuta');
    }

    foreach (array_keys(self::YES_NO) as $column) {
      $answer = $value($column);
      if ($answer === 'Si') {
        $where[] = "(TRIM(COALESCE(i.`{$column}`, '')) = 'Si' OR i.`{$column}` LIKE '%\"Si\"%')";
      } elseif ($answer === 'No') {
        $where[] = "(TRIM(COALESCE(i.`{$column}`, '')) = 'No' OR (i.`{$column}` LIKE '%\"No\"%' AND i.`{$column}` NOT LIKE '%\"Si\"%'))";
      }
    }

    return [$where, $args];
  }

  /**
   * Opciones de los filtros que salen de los datos: estratos y tipos de permuta.
   * @param list<mixed> $args
   * @return array{estratos:list<string>,tipos_permuta:list<string>}
   */
  public static function options(Database $db, string $where = '1 = 1', array $args = []): array
  {
    $table = $db->table('jet_cct_inmuebles');
    $strata = array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $db->getCol(
      "SELECT DISTINCT TRIM(`estrato`) FROM `{$table}` WHERE ({$where}) AND TRIM(COALESCE(`estrato`, '')) <> ''",
      $args
    )), static fn(string $v): bool => $v !== ''));
    usort($strata, static fn(string $a, string $b): int => [!is_numeric($a), is_numeric($a) ? (int) $a : $a] <=> [!is_numeric($b), is_numeric($b) ? (int) $b : $b]);
    $swaps = [];
    foreach ($db->getCol("SELECT DISTINCT `tipo_permuta` FROM `{$table}` WHERE ({$where}) AND TRIM(COALESCE(`tipo_permuta`, '')) <> ''", $args) as $raw) {
      foreach (CommercialDashboardView::parseFeatureList($raw) as $swap) {
        $swaps[$swap] = true;
      }
    }
    $swaps = array_keys($swaps);
    natcasesort($swaps);
    return ['estratos' => array_values(array_unique($strata)), 'tipos_permuta' => array_values($swaps)];
  }

  /** Texto numérico de la columna como número: "1.000.000" → 1000000, "56" → 56, "12,5" → 12.5. */
  public static function number(string $column): string
  {
    $trimmed = "TRIM(COALESCE({$column}, ''))";
    return "(CASE WHEN {$trimmed} REGEXP '^[0-9]+([.,][0-9]{1,2})?$' THEN REPLACE({$trimmed}, ',', '.') + 0"
      . " WHEN {$trimmed} REGEXP '[0-9]' THEN REPLACE(REPLACE(REPLACE(REPLACE({$trimmed}, '\$', ''), '.', ''), ',', ''), ' ', '') + 0 ELSE 0 END)";
  }

  /** Área del inmueble como en QuoteComparison::normalize: la construida, si no la privada y si no la del terreno. */
  public static function area(): string
  {
    $built = self::number('i.`area_construida`');
    $private = self::number('i.`area_privada`');
    return "(CASE WHEN {$built} > 0 THEN {$built} WHEN {$private} > 0 THEN {$private} ELSE " . self::number('i.`area_terreno`') . ' END)';
  }

  /**
   * Rango sobre una expresión numérica. Los límites van escritos en el SQL (ya son números limpios): con parámetros,
   * SQLite los compara como texto.
   */
  private static function between(string $expression, float $min, float $max): string
  {
    $parts = ["{$expression} > 0"];
    if ($min > 0) {
      $parts[] = "{$expression} >= " . self::literal($min);
    }
    if ($max > 0) {
      $parts[] = "{$expression} <= " . self::literal($max);
    }
    return '(' . implode(' AND ', $parts) . ')';
  }

  private static function literal(float $value): string
  {
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
  }

  private static function escapeLike(string $value): string
  {
    return addcslashes($value, '\\%_');
  }
}
