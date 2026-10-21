<?php
declare(strict_types=1);
namespace SCM\Views;

final class CommercialPropertyIcons
{
  public static function name(string $label): string
  {
    static $map;
    $map ??= json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/forms/property-feature-icons.json'), true, 512, JSON_THROW_ON_ERROR);
    $key = strtr(mb_strtolower(trim(strip_tags($label))), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return $map[$key] ?? match ($key) { 'si','sí'=>'fa-check','no'=>'fa-minus','casa'=>'fa-house','apartamento','apartaestudio'=>'fa-building','bodega'=>'fa-warehouse','lote'=>'fa-map','oficina'=>'fa-briefcase','local'=>'fa-store',default=>'fa-circle-check' };
  }
}
