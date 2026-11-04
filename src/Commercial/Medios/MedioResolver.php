<?php

declare(strict_types=1);

namespace SCM\Commercial\Medios;

use SCM\Commercial\Tools\UtmLinkBuilder;

/**
 * Traduce un texto crudo (lo que quedó en un registro, en utm_source o en utm_medium) al Medio de la lista. Compara sin
 * mayúsculas, tildes, espacios ni signos ("Estado WhatsApp" = "Estado whatsapp", "Finca raiz" = "Finca Raíz"): primero
 * los nombres, después los valores UTM (slug) y por último los alias. Los medios inactivos también se resuelven, para que
 * lo histórico siga contando en el mismo medio.
 *
 * No toca la base: MedioCatalog lo arma con la tabla scm_medios (o con MedioSeed si aún no existe).
 */
final class MedioResolver
{
  /** Medio de las visitas que llegaron sin parámetros UTM (no es un medio de la lista). */
  public const DIRECT = 'Sin medio (directo)';

  /**
   * Lo que el tracker de dashboard-marketing (app/TrackerRepository.php) escribe cuando el enlace no traía UTM, más los
   * vacíos de los resúmenes (TrackerSchema::EMPTY_SOURCE / EMPTY_MEDIUM). Comparados con key().
   */
  public const TRACKER_DEFAULT_SOURCES = ['directo', 'inmuebles'];
  public const TRACKER_DEFAULT_MEDIUMS = ['navegador', 'paginaweb', 'sinmedio'];

  /** @var array<string,string> key => nombre */
  private array $map = [];
  /** @var array<string,true> */
  private array $names = [];

  /** @param list<array{nombre:string,slug:string,aliases:list<string>}> $medios */
  public function __construct(array $medios)
  {
    // Tres pasadas para que un nombre siempre le gane a un alias de otro medio.
    foreach ($medios as $medio) {
      $this->names[$medio['nombre']] = true;
      $this->map[self::key($medio['nombre'])] ??= $medio['nombre'];
    }
    foreach ($medios as $medio) {
      $this->map[self::key($medio['slug'])] ??= $medio['nombre'];
    }
    foreach ($medios as $medio) {
      foreach ($medio['aliases'] as $alias) {
        $key = self::key($alias);
        if ($key !== '') {
          $this->map[$key] ??= $medio['nombre'];
        }
      }
    }
    unset($this->map['']);
  }

  /** Llave de comparación: minúsculas, sin tildes y sin nada que no sea letra o número. */
  public static function key(string $value): string
  {
    $value = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $value) ?? $value;
    return (string) preg_replace('/[^a-z0-9]+/', '', str_replace(['-', '_'], '', UtmLinkBuilder::slug($value)));
  }

  /** Valor UTM de un medio (el que va en utm_source y utm_medium). */
  public static function slug(string $name): string
  {
    return UtmLinkBuilder::slug($name);
  }

  /** Nombre del medio, o null si el texto no corresponde a ninguno. */
  public function resolve(?string $raw): ?string
  {
    $raw = trim((string) $raw);
    if ($raw === '') {
      return null;
    }
    if (isset($this->names[$raw])) {
      return $raw;
    }
    return $this->map[self::key($raw)] ?? null;
  }

  /** El nombre del medio o, si no se reconoce, el mismo texto (sin espacios a los lados). */
  public function canonical(?string $raw): string
  {
    return $this->resolve($raw) ?? trim((string) $raw);
  }

  public function isName(string $value): bool
  {
    return isset($this->names[$value]);
  }

  /**
   * Medio de una visita del tracker:
   * 1. utm_source, si no es uno de los valores que pone el tracker solo (Directo, Inmuebles);
   * 2. utm_medium, si no es Navegador / Pagina Web: así las notificaciones ("Base de Datos Clientes" por "Correo
   *    Electrónico" o "SMS") cuentan en el canal por el que se enviaron;
   * 3. sin UTM: DIRECT;
   * 4. si nada se reconoce, el texto crudo (utm_source, o utm_medium si la fuente era la del tracker), para no esconder nada.
   * @return array{0:string,1:bool} [medio, reconocido]
   */
  public function forVisit(?string $source, ?string $medium): array
  {
    $source = trim((string) $source);
    $medium = trim((string) $medium);
    $sourceDefault = $source === '' || in_array(self::key($source), self::TRACKER_DEFAULT_SOURCES, true);
    $mediumDefault = $medium === '' || in_array(self::key($medium), self::TRACKER_DEFAULT_MEDIUMS, true);
    if (!$sourceDefault && ($name = $this->resolve($source)) !== null) {
      return [$name, true];
    }
    if (!$mediumDefault && ($name = $this->resolve($medium)) !== null) {
      return [$name, true];
    }
    if ($sourceDefault && $mediumDefault) {
      return [self::DIRECT, true];
    }
    return [mb_substr($sourceDefault ? $medium : $source, 0, 120), false];
  }
}
