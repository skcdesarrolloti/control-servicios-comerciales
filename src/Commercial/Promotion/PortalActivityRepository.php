<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

use SCM\Core\Database;

/**
 * Promoción › Actividad de portales. Copia/adaptación de dashboard-marketing app/ActivityRepository.php (summary,
 * actorTabs, personFilterConditions, accessFilterConditions); mantener ambas iguales en lo que hacen.
 *
 * Correcciones frente al original:
 * - Toda consulta respeta el rango de fechas (por defecto los últimos 30 días): sesiones, accesos por actor, usuarios con
 *   más sesiones y las opciones de los filtros también.
 * - El tipo de actor se normaliza en PHP (lista fija ACTOR_TYPES); el WHERE compara valores exactos con IN, sin
 *   COALESCE(NULLIF()).
 * - Los filtros de lista (tipo de actor, portal, menú) son coincidencia exacta; la búsqueda de usuario es exacta por
 *   usuario o por el comienzo del nombre (nunca LIKE '%...%').
 * - IP y navegador solo salen con el permiso promocion_ver_ip ($withNetwork).
 *
 * Solo lee `jet_cct_accesos_portal` (lo escriben los portales de actores) y `jet_cct_sesiones` (un registro por usuario y
 * tipo de actor con el total acumulado de sesiones y la fecha del último ingreso).
 */
final class PortalActivityRepository
{
  public const PAGE_SIZE = 25;
  public const DEFAULT_DAYS = 30;
  public const MAX_DAYS = 366;
  public const UNCLASSIFIED = 'Sin clasificar';
  public const OTHER = 'Otros';

  /** Tipos de actor que muestran los filtros y las tablas, en este orden. */
  public const ACTOR_TYPES = [
    'Club PPH', 'Cliente', 'Cliente para arriendo', 'Cliente para venta', 'Cliente para arriendo y venta', 'Cliente para avalúo',
    'Cliente para captación', 'Propietario', 'Arrendatario', self::OTHER, self::UNCLASSIFIED,
  ];

  /** Valores que escriben los portales (minúsculas, sin tildes) => tipo de la lista. */
  private const ACTOR_ALIASES = [
    'club pph' => 'Club PPH',
    'cliente' => 'Cliente',
    'cliente para arriendo' => 'Cliente para arriendo',
    'quiero arrendar un inmueble' => 'Cliente para arriendo',
    'cliente para venta' => 'Cliente para venta',
    'quiero vender mi inmueble' => 'Cliente para venta',
    'cliente para arriendo - venta' => 'Cliente para arriendo y venta',
    'cliente para arriendo y venta' => 'Cliente para arriendo y venta',
    'cliente para avaluo' => 'Cliente para avalúo',
    'cliente para captacion' => 'Cliente para captación',
    'propietario' => 'Propietario',
    'propietarios' => 'Propietario',
    'arrendatario' => 'Arrendatario',
    'arrendatarios' => 'Arrendatario',
  ];

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /** Tipo de actor de la lista fija para un valor guardado por un portal. */
  public static function actorType(mixed $raw): string
  {
    $value = trim(is_scalar($raw) ? (string) $raw : '');
    if ($value === '') {
      return self::UNCLASSIFIED;
    }
    $key = strtr(mb_strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    return self::ACTOR_ALIASES[$key] ?? self::OTHER;
  }

  /**
   * Filtros desde la URL del panel (pr_desde, pr_hasta, pr_actor, pr_proyecto, pr_menu, pr_q, pr_pag).
   * @param array<string,mixed> $input
   * @return array{from:string,to:string,actor:string,proyecto:string,menu:string,q:string,page:int,clamped:bool}
   */
  public static function filters(array $input, ?string $today = null): array
  {
    $today = $today ?? date('Y-m-d');
    $to = self::date($input['pr_hasta'] ?? null) ?? $today;
    if ($to > $today) {
      $to = $today;
    }
    $from = self::date($input['pr_desde'] ?? null) ?? self::shift($to, -(self::DEFAULT_DAYS - 1));
    if ($from > $to) {
      [$from, $to] = [$to, $from];
    }
    $clamped = false;
    $earliest = self::shift($to, -(self::MAX_DAYS - 1));
    if ($from < $earliest) {
      $from = $earliest;
      $clamped = true;
    }
    $actor = self::text($input['pr_actor'] ?? '', 80);
    return [
      'from' => $from,
      'to' => $to,
      'actor' => in_array($actor, self::ACTOR_TYPES, true) ? $actor : '',
      'proyecto' => self::text($input['pr_proyecto'] ?? '', 80),
      'menu' => self::text($input['pr_menu'] ?? '', 160),
      'q' => self::text($input['pr_q'] ?? '', 80),
      'page' => max(1, min(10000, (int) (is_scalar($input['pr_pag'] ?? null) ? $input['pr_pag'] : 1))),
      'clamped' => $clamped,
    ];
  }

  /**
   * Resumen de la pantalla.
   * @param array{from:string,to:string,actor:string,proyecto:string,menu:string,q:string,page:int,clamped:bool} $filters
   * @return array<string,mixed>
   */
  public function summary(array $filters, bool $withNetwork = false): array
  {
    $access = $this->db->table('jet_cct_accesos_portal');
    $out = [
      'filters' => $filters,
      'access_available' => true,
      'sessions_available' => true,
      'kpis' => ['accesos' => 0, 'usuarios_unicos' => 0, 'ultimo_acceso' => '', 'usuarios_sesion' => 0, 'sesiones' => 0],
      'daily' => [],
      'menus' => [],
      'by_actor' => [],
      'top_access_users' => [],
      'top_session_users' => [],
      'recent' => [],
      'pages' => 1,
      'options' => ['proyectos' => [], 'menus' => []],
    ];
    for ($day = $filters['from']; $day <= $filters['to']; $day = self::shift($day, 1)) {
      $out['daily'][$day] = ['day' => $day, 'value' => 0];
    }
    $actors = [];
    $ensure = static function (string $type) use (&$actors): void {
      $actors[$type] ??= ['tipo_actor' => $type, 'accesos' => 0, 'usuarios' => [], 'sesiones' => 0, 'usuarios_sesion' => 0, 'ultimo_acceso' => ''];
    };

    try {
      [$rangeWhere, $rangeArgs] = $this->accessRange($filters);
      // Opciones de portal y menú: lo que hubo en el rango, sin los demás filtros.
      foreach ($this->db->getResults("SELECT `proyecto`, `menu`, COUNT(*) AS total FROM `{$access}` WHERE {$rangeWhere} GROUP BY `proyecto`, `menu`", $rangeArgs) as $row) {
        $proyecto = trim((string) ($row['proyecto'] ?? ''));
        $menu = trim((string) ($row['menu'] ?? ''));
        if ($proyecto !== '') {
          $out['options']['proyectos'][$proyecto] = $proyecto;
        }
        if ($menu !== '') {
          $out['options']['menus'][$menu] = $menu;
        }
      }
      [$where, $args] = $this->accessWhere($filters, $rangeWhere, $rangeArgs, $access);
      if ($where !== null) {
        // Una sola lectura agrupada por tipo de actor y usuario alimenta los indicadores, la tabla por actor y los usuarios.
        $users = [];
        foreach ($this->db->getResults(
          "SELECT `tipo_actor`, `usuario`, MAX(`nombre`) AS nombre, COUNT(*) AS accesos, MAX(`creado_en`) AS ultimo
             FROM `{$access}` WHERE {$where} GROUP BY `tipo_actor`, `usuario`",
          $args
        ) as $row) {
          $type = self::actorType($row['tipo_actor'] ?? '');
          $user = trim((string) ($row['usuario'] ?? ''));
          $count = (int) ($row['accesos'] ?? 0);
          $last = (string) ($row['ultimo'] ?? '');
          $ensure($type);
          $actors[$type]['accesos'] += $count;
          $actors[$type]['usuarios'][$user] = true;
          $actors[$type]['ultimo_acceso'] = max($actors[$type]['ultimo_acceso'], $last);
          $out['kpis']['accesos'] += $count;
          $out['kpis']['ultimo_acceso'] = max($out['kpis']['ultimo_acceso'], $last);
          $key = $type . '|' . $user;
          $users[$key] ??= ['tipo_actor' => $type, 'usuario' => $user, 'nombre' => '', 'accesos' => 0, 'ultimo_acceso' => ''];
          $users[$key]['accesos'] += $count;
          $users[$key]['nombre'] = $users[$key]['nombre'] !== '' ? $users[$key]['nombre'] : trim((string) ($row['nombre'] ?? ''));
          $users[$key]['ultimo_acceso'] = max($users[$key]['ultimo_acceso'], $last);
        }
        $out['kpis']['usuarios_unicos'] = count(array_unique(array_map(static fn(array $user): string => $user['usuario'], $users)));
        usort($users, static fn(array $a, array $b): int => ($b['accesos'] <=> $a['accesos']) ?: strcmp($b['ultimo_acceso'], $a['ultimo_acceso']));
        $out['top_access_users'] = array_slice($users, 0, 12);

        foreach ($this->db->getResults("SELECT DATE(`creado_en`) AS dia, COUNT(*) AS total FROM `{$access}` WHERE {$where} GROUP BY DATE(`creado_en`)", $args) as $row) {
          $day = substr((string) ($row['dia'] ?? ''), 0, 10);
          if (isset($out['daily'][$day])) {
            $out['daily'][$day]['value'] = (int) $row['total'];
          }
        }
        $out['menus'] = array_map(static fn(array $row): array => [
          'proyecto' => (string) ($row['proyecto'] ?? ''),
          'menu' => (string) ($row['menu'] ?? ''),
          'accesos' => (int) ($row['accesos'] ?? 0),
          'usuarios_unicos' => (int) ($row['usuarios_unicos'] ?? 0),
          'ultimo_acceso' => (string) ($row['ultimo_acceso'] ?? ''),
        ], $this->db->getResults(
          "SELECT `proyecto`, `menu`, COUNT(*) AS accesos, COUNT(DISTINCT `usuario`) AS usuarios_unicos, MAX(`creado_en`) AS ultimo_acceso
             FROM `{$access}` WHERE {$where} GROUP BY `proyecto`, `menu` ORDER BY accesos DESC, `proyecto`, `menu` LIMIT 20",
          $args
        ));
        $out['pages'] = max(1, (int) ceil($out['kpis']['accesos'] / self::PAGE_SIZE));
        $page = min($filters['page'], $out['pages']);
        $out['filters']['page'] = $page;
        $network = $withNetwork ? ', `ip`, `user_agent`' : '';
        foreach ($this->db->getResults(
          "SELECT `_ID`, `creado_en`, `proyecto`, `tipo_actor`, `usuario`, `nombre`, `menu`, `ruta`, `metodo`{$network}
             FROM `{$access}` WHERE {$where} ORDER BY `creado_en` DESC, `_ID` DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
          $args
        ) as $row) {
          $item = [
            'creado_en' => (string) ($row['creado_en'] ?? ''),
            'proyecto' => (string) ($row['proyecto'] ?? ''),
            'tipo_actor' => self::actorType($row['tipo_actor'] ?? ''),
            'usuario' => (string) ($row['usuario'] ?? ''),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'menu' => (string) ($row['menu'] ?? ''),
            'ruta' => (string) ($row['ruta'] ?? ''),
            'metodo' => (string) ($row['metodo'] ?? ''),
          ];
          if ($withNetwork) {
            $item['ip'] = (string) ($row['ip'] ?? '');
            $item['user_agent'] = (string) ($row['user_agent'] ?? '');
          }
          $out['recent'][] = $item;
        }
      }
    } catch (\Throwable $exception) {
      error_log('[commercial-promotion-activity] ' . $exception->getMessage());
      $out['access_available'] = false;
    }

    try {
      $this->sessions($filters, $out, $actors, $ensure);
    } catch (\Throwable $exception) {
      error_log('[commercial-promotion-activity] ' . $exception->getMessage());
      $out['sessions_available'] = false;
    }

    $order = array_flip(self::ACTOR_TYPES);
    $out['by_actor'] = array_values(array_map(static fn(array $row): array => ['usuarios' => count($row['usuarios'])] + $row, $actors));
    usort($out['by_actor'], static fn(array $a, array $b): int => (($b['accesos'] + $b['sesiones']) <=> ($a['accesos'] + $a['sesiones'])) ?: (($order[$a['tipo_actor']] ?? 99) <=> ($order[$b['tipo_actor']] ?? 99)));
    $out['daily'] = array_values($out['daily']);
    $out['options']['proyectos'] = self::sortedOptions($out['options']['proyectos'], $filters['proyecto']);
    $out['options']['menus'] = self::sortedOptions($out['options']['menus'], $filters['menu']);
    return $out;
  }

  /**
   * Sesiones de los usuarios cuyo último ingreso cae en el rango (la tabla guarda el acumulado por usuario). No tienen
   * portal ni menú, así que esos dos filtros no las afectan.
   * @param array<string,mixed> $filters
   * @param array<string,mixed> $out
   * @param array<string,array<string,mixed>> $actors
   */
  private function sessions(array $filters, array &$out, array &$actors, callable $ensure): void
  {
    $sessions = $this->db->table('jet_cct_sesiones');
    $where = ['`fecha` BETWEEN ? AND ?'];
    $args = [(int) strtotime($filters['from'] . ' 00:00:00'), (int) strtotime($filters['to'] . ' 23:59:59')];
    if ($filters['actor'] !== '') {
      $raw = $this->rawActorValues($sessions, $filters['actor'], '`fecha` BETWEEN ? AND ?', [$args[0], $args[1]]);
      if ($raw === null) {
        return;
      }
      $where[] = $raw[0];
      $args = array_merge($args, $raw[1]);
    }
    if ($filters['q'] !== '') {
      $where[] = "(`usuario` = ? OR `nombre` LIKE ? ESCAPE '!')";
      array_push($args, $filters['q'], self::prefix($filters['q']));
    }
    $users = [];
    foreach ($this->db->getResults('SELECT `tipo_actor`, `usuario`, `nombre`, `cantidad_sesiones`, `fecha` FROM `' . $sessions . '` WHERE ' . implode(' AND ', $where), $args) as $row) {
      $type = self::actorType($row['tipo_actor'] ?? '');
      $count = (int) ($row['cantidad_sesiones'] ?? 0);
      $ensure($type);
      $actors[$type]['sesiones'] += $count;
      $actors[$type]['usuarios_sesion']++;
      $out['kpis']['sesiones'] += $count;
      $out['kpis']['usuarios_sesion']++;
      $users[] = [
        'tipo_actor' => $type,
        'usuario' => (string) ($row['usuario'] ?? ''),
        'nombre' => (string) ($row['nombre'] ?? ''),
        'sesiones' => $count,
        'ultimo_ingreso' => (int) ($row['fecha'] ?? 0) > 0 ? date('Y-m-d', (int) $row['fecha']) : '',
      ];
    }
    usort($users, static fn(array $a, array $b): int => ($b['sesiones'] <=> $a['sesiones']) ?: strcmp($b['ultimo_ingreso'], $a['ultimo_ingreso']));
    $out['top_session_users'] = array_slice($users, 0, 12);
  }

  /**
   * Rango de fechas sobre `creado_en` (índice propio).
   * @param array<string,mixed> $filters
   * @return array{0:string,1:list<string>}
   */
  private function accessRange(array $filters): array
  {
    return ['`creado_en` BETWEEN ? AND ?', [$filters['from'] . ' 00:00:00', $filters['to'] . ' 23:59:59']];
  }

  /**
   * Rango + filtros exactos. Devuelve [null, []] si el tipo de actor pedido no tiene registros en el rango.
   * @param array<string,mixed> $filters
   * @param list<string> $rangeArgs
   * @return array{0:string|null,1:list<string>}
   */
  private function accessWhere(array $filters, string $rangeWhere, array $rangeArgs, string $table): array
  {
    $where = [$rangeWhere];
    $args = $rangeArgs;
    if ($filters['actor'] !== '') {
      $raw = $this->rawActorValues($table, $filters['actor'], $rangeWhere, $rangeArgs);
      if ($raw === null) {
        return [null, []];
      }
      $where[] = $raw[0];
      $args = array_merge($args, $raw[1]);
    }
    foreach (['proyecto', 'menu'] as $column) {
      if ($filters[$column] !== '') {
        $where[] = "`{$column}` = ?";
        $args[] = $filters[$column];
      }
    }
    if ($filters['q'] !== '') {
      $where[] = "(`usuario` = ? OR `nombre` LIKE ? ESCAPE '!')";
      array_push($args, $filters['q'], self::prefix($filters['q']));
    }
    return [implode(' AND ', $where), $args];
  }

  /**
   * Los valores guardados que corresponden a un tipo de la lista (en el rango), para filtrar con IN exacto.
   * @param list<int|string> $rangeArgs
   * @return array{0:string,1:list<string>}|null
   */
  private function rawActorValues(string $table, string $actor, string $rangeWhere, array $rangeArgs): ?array
  {
    $values = [];
    $empty = false;
    foreach ($this->db->getCol("SELECT DISTINCT `tipo_actor` FROM `{$table}` WHERE {$rangeWhere}", $rangeArgs) as $raw) {
      if (self::actorType($raw) !== $actor) {
        continue;
      }
      if ($raw === null || trim((string) $raw) === '') {
        $empty = true;
      } else {
        $values[] = (string) $raw;
      }
    }
    $parts = [];
    if ($values !== []) {
      $parts[] = '`tipo_actor` IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
    }
    if ($empty) {
      $parts[] = "`tipo_actor` = '' OR `tipo_actor` IS NULL";
    }
    return $parts === [] ? null : ['(' . implode(' OR ', $parts) . ')', $values];
  }

  /** @param array<string,string> $options @return list<string> */
  private static function sortedOptions(array $options, string $selected): array
  {
    if ($selected !== '') {
      $options[$selected] = $selected;
    }
    $list = array_values($options);
    usort($list, static fn(string $a, string $b): int => strcasecmp($a, $b));
    return $list;
  }

  private static function prefix(string $value): string
  {
    // '!' como escape: funciona igual en MariaDB y en SQLite (las pruebas).
    return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
  }

  private static function date(mixed $value): ?string
  {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
      return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date !== false && $date->format('Y-m-d') === trim($value) ? trim($value) : null;
  }

  private static function shift(string $day, int $days): string
  {
    return (new \DateTimeImmutable($day))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
  }

  private static function text(mixed $value, int $max): string
  {
    return is_scalar($value) ? trim(mb_substr(trim((string) $value), 0, $max)) : '';
  }
}
