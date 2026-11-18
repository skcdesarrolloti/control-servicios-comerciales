<?php

declare(strict_types=1);

namespace SCM\Commercial;

/**
 * Estado de actualización de un inmueble publicado (al día, en alerta o vencido) con los plazos de Configuración ›
 * Inmuebles › Actualizaciones. La usan el control de Actualizaciones y las cotizaciones (un inmueble vencido no se cotiza
 * hasta actualizarlo), para que las dos digan lo mismo.
 */
final class PropertyUpdateState
{
  /**
   * @param array<string,mixed> $row Fila de jet_cct_inmuebles (fecha_actualizacion, fecha_publicacion, cct_modified, cct_created, tipo_negocio).
   * @param array<string,array{alerta:int,vencido:int}> $limits CommercialSettingsService::propertyUpdateLimits().
   * @return array{status:string,label:string,days:int,max:int,min:int}
   */
  public static function evaluate(array $row, array $limits, ?int $now = null): array
  {
    $now ??= time();
    $reference = 0;
    foreach (['fecha_actualizacion', 'fecha_publicacion', 'cct_modified', 'cct_created'] as $column) {
      $reference = self::timestamp($row[$column] ?? null);
      if ($reference > 0) {
        break;
      }
    }
    [$min, $max] = self::limitsFor((string) ($row['tipo_negocio'] ?? ''), $limits);
    $days = $reference > 0 ? max(0, (int) floor(($now - $reference) / 86400)) : 0;
    if ($days >= $max) {
      return ['status' => 'vencido', 'label' => 'Actualización vencida', 'days' => $days, 'max' => $max, 'min' => $min];
    }
    if ($days >= $min) {
      return ['status' => 'alerta', 'label' => 'Actualización en alerta', 'days' => $days, 'max' => $max, 'min' => $min];
    }
    return ['status' => 'ok', 'label' => 'Actualización al día', 'days' => $days, 'max' => $max, 'min' => $min];
  }

  /**
   * @param array<string,array{alerta:int,vencido:int}> $limits
   * @return array{0:int,1:int} [alerta, vencido]
   */
  public static function limitsFor(string $businessType, array $limits): array
  {
    $type = mb_strtolower($businessType, 'UTF-8');
    $hasSale = str_contains($type, 'venta');
    $hasRent = str_contains($type, 'arriendo');
    $key = $hasSale ? ($hasRent ? 'arriendo_venta' : 'venta') : 'arriendo';
    $pair = $limits[$key] ?? ['alerta' => 80, 'vencido' => 90];
    return [(int) $pair['alerta'], (int) $pair['vencido']];
  }

  /**
   * Formulario de WordPress donde el funcionario actualiza el inmueble (el mismo del control de Actualizaciones).
   * @param array<string,mixed> $row Con codigo, id_funcionario, id_propietario y, si se sabe, func_sucursal.
   */
  public static function updateUrl(array $row): string
  {
    $code = trim((string) ($row['codigo'] ?? $row['_ID'] ?? ''));
    if ($code === '') {
      return '';
    }
    $params = [
      'id_inmueble' => $code,
      'id_empleado' => trim((string) ($row['id_funcionario'] ?? '')),
      'id_propietario' => trim((string) ($row['id_propietario'] ?? '')),
      'id_sucursal' => trim((string) ($row['func_sucursal'] ?? '')) ?: '1',
    ];
    return 'https://sucasainmobiliaria.com.co/mi-cuenta/actualizar-inmueble/?' . http_build_query(array_filter($params, static fn(string $value): bool => $value !== ''));
  }

  /** @param mixed $value */
  public static function timestamp($value): int
  {
    if (is_numeric($value)) {
      return (int) $value;
    }
    $value = trim((string) $value);
    if ($value === '' || str_contains($value, '0000-00-00')) {
      return 0;
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? $timestamp : 0;
  }
}
