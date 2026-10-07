<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;

final class CommercialAccessPolicy
{
  public const VIEWS = [
    'inicio' => 'Inicio',
    'calendario' => 'Calendario comercial',
    'inmuebles' => 'Inmuebles',
    'abiertos' => 'Tareas abiertas',
    'postergados' => 'Tareas postergadas',
    'cerrados' => 'Tareas cerradas',
    'mis_tickets' => 'Mis tareas',
    'actualizaciones' => 'Actualizaciones de inmuebles',
    'avisos' => 'Avisos en fachada',
    'notificaciones' => 'Notificaciones comerciales',
  ];

  public const ACTIONS = [
    'ver_ticket' => 'Ver detalle de la tarea',
    'responder' => 'Responder al solicitante',
    'agregar_nota' => 'Agregar notas internas',
    'seguimiento' => 'Registrar seguimientos',
    'postergar' => 'Postergar tareas',
    'activar' => 'Activar tareas',
    'cerrar' => 'Cerrar tareas',
    'cambiar_estado' => 'Cambiar estado comercial',
    'reasignar' => 'Reasignar responsable',
    'enviar_notificacion' => 'Enviar notificaciones comerciales',
  ];

  public const SUBVIEWS = [
    'inmuebles' => [
      'publicos' => 'Inmuebles públicos',
      'pendientes' => 'Pendientes por publicar',
      'no_publicos' => 'Inmuebles no públicos',
      'destacados' => 'Inmuebles destacados',
      'mis_solicitudes' => 'Solicitudes por destacar',
      'mis_inmuebles' => 'Mis inmuebles',
    ],
    'calendario' => [
      'mine' => 'Mi calendario',
      'team' => 'Calendario del equipo',
      'due' => 'Vencimientos',
    ],
  ];

  private Settings $settings;
  private Database $db;
  /** @var array<int,string> */
  private array $defaultAdminCargos;

  /** @param array<int,string> $adminCargos */
  public function __construct(Settings $settings, Database $db, array $adminCargos)
  {
    $this->settings = $settings;
    $this->db = $db;
    $this->defaultAdminCargos = $this->sanitizeCargoIds($adminCargos);
  }

  public function canManage(): bool
  {
    return in_array(Auth::userCargo(), $this->adminCargoIds(), true);
  }

  public function canSeeAllCommercialTickets(): bool
  {
    return $this->canManage();
  }

  public function canView(string $view): bool
  {
    if (!array_key_exists($view, self::VIEWS)) {
      return false;
    }
    if ($this->canManage()) {
      return true;
    }
    if (!in_array($view, $this->allowed('views', array_keys(self::VIEWS)), true)) {
      return false;
    }
    return !isset(self::SUBVIEWS[$view]) || $this->allowedSubviews($view) !== [];
  }

  /** @return array<int,string> */
  public function allowedSubviews(string $view): array
  {
    $defaults = array_keys(self::SUBVIEWS[$view] ?? []);
    if ($this->canManage()) {
      return $defaults;
    }
    $permissions = $this->permissions();
    return $permissions[Auth::userCargo()]['subviews'][$view] ?? $defaults;
  }

  public function canSubview(string $view, string $subview): bool
  {
    return $this->canView($view) && in_array($subview, $this->allowedSubviews($view), true);
  }

  /** Resolve the default subtab and reject explicitly denied subtabs before querying data.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function resolveNavigation(string $view, array $input): array
  {
    if (!isset(self::SUBVIEWS[$view])) {
      return $input;
    }
    $key = $view === 'inmuebles' ? 'property_subtab' : 'subtab';
    $requested = trim((string) ($input[$key] ?? ($view === 'inmuebles' ? ($input['subtab'] ?? '') : '')));
    $allowed = $this->allowedSubviews($view);
    if ($requested === '') {
      $preferred = $view === 'inmuebles' ? ($this->canManage() ? 'publicos' : 'mis_inmuebles') : 'mine';
      $requested = in_array($preferred, $allowed, true) ? $preferred : ($allowed[0] ?? '');
    }
    if (!$this->canSubview($view, $requested)) {
      throw new \RuntimeException('No tienes permiso para entrar a esta subpestaña.');
    }
    $input[$key] = $requested;
    return $input;
  }

  public function userCargoName(): string
  {
    $table = $this->db->table('jet_cct_cargos');
    return trim((string) $this->db->getVar(
      "SELECT `nombre_cargo` FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [Auth::userCargo()]
    ));
  }

  public function canAct(string $action): bool
  {
    if (!array_key_exists($action, self::ACTIONS)) {
      return false;
    }
    if ($this->canManage()) {
      return true;
    }
    return in_array($action, $this->allowed('actions', array_keys(self::ACTIONS)), true);
  }

  /** @return array<string,array{views:array<int,string>,actions:array<int,string>,subviews:array<string,array<int,string>>}> */
  public function permissions(): array
  {
    $raw = $this->settings->get('commercial_permissions', []);
    return is_array($raw) ? $this->sanitize($raw) : [];
  }

  /** @return array<int,string> */
  public function adminCargoIds(): array
  {
    $raw = $this->settings->get('commercial_admin_cargos', null);
    if (is_array($raw)) {
      $ids = $this->sanitizeCargoIds($raw);
      if ($ids !== []) {
        return $ids;
      }
    }
    return $this->defaultAdminCargos;
  }

  /** @param array<string,mixed> $raw @param array<int|string,mixed> $adminCargos */
  public function save(array $raw, array $adminCargos): void
  {
    if (!$this->canManage()) {
      throw new \RuntimeException('No tienes permisos para cambiar esta configuración.');
    }
    $adminCargoIds = $this->sanitizeCargoIds($adminCargos);
    if ($adminCargoIds === []) {
      throw new \InvalidArgumentException('Selecciona al menos un cargo con acceso total para no dejar el panel sin administradores.');
    }
    $this->settings->set('commercial_permissions', $this->sanitize($raw), Auth::userId());
    $this->settings->set('commercial_admin_cargos', $adminCargoIds, Auth::userId());
    $this->settings->refresh();
  }

  /** @return array<int,array{id:string,name:string,total:int}> */
  public function cargoOptions(): array
  {
    $funcionarios = $this->db->table('jet_cct_funcionarios');
    $cargos = $this->db->table('jet_cct_cargos');
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(f.`id_cargo`, '')) AS id,
              COALESCE(NULLIF(TRIM(c.`nombre_cargo`), ''), CONCAT('Cargo ', f.`id_cargo`)) AS name,
              COUNT(*) AS total
         FROM `{$funcionarios}` f
         LEFT JOIN `{$cargos}` c ON CAST(c.`_ID` AS CHAR) = TRIM(f.`id_cargo`)
        WHERE f.`activo` = 'Si' AND TRIM(COALESCE(f.`id_cargo`, '')) <> ''
        GROUP BY TRIM(f.`id_cargo`), c.`nombre_cargo`
        ORDER BY name ASC"
    );

    return array_map(static fn(array $row): array => [
      'id' => (string) ($row['id'] ?? ''),
      'name' => (string) ($row['name'] ?? ''),
      'total' => (int) ($row['total'] ?? 0),
    ], $rows);
  }

  /** @return array<int,string> */
  private function allowed(string $type, array $defaults): array
  {
    $cargo = Auth::userCargo();
    $permissions = $this->permissions();
    if ($cargo === '' || !isset($permissions[$cargo])) {
      return $defaults;
    }
    $values = $permissions[$cargo][$type] ?? [];
    return array_values($values);
  }

  /** @param array<string,mixed> $raw @return array<string,array{views:array<int,string>,actions:array<int,string>,subviews:array<string,array<int,string>>}> */
  private function sanitize(array $raw): array
  {
    $out = [];
    foreach ($raw as $cargo => $definition) {
      $cargo = trim((string) $cargo);
      if ($cargo === '' || !is_array($definition)) {
        continue;
      }
      $out[$cargo] = [
        'views' => array_values(array_intersect(array_keys(self::VIEWS), array_map('strval', (array) ($definition['views'] ?? [])))),
        'actions' => array_values(array_intersect(array_keys(self::ACTIONS), array_map('strval', (array) ($definition['actions'] ?? [])))),
        'subviews' => [],
      ];
      foreach (self::SUBVIEWS as $view => $subviews) {
        // Existing configurations inherit their parent permission until subviews are saved.
        $configured = is_array($definition['subviews'] ?? null) && array_key_exists($view, $definition['subviews']);
        $out[$cargo]['subviews'][$view] = $configured
          ? array_values(array_intersect(array_keys($subviews), array_map('strval', (array) $definition['subviews'][$view])))
          : array_keys($subviews);
      }
    }
    return $out;
  }

  /** @param array<int|string,mixed> $ids @return array<int,string> */
  private function sanitizeCargoIds(array $ids): array
  {
    return array_values(array_unique(array_filter(array_map(
      static fn($id): string => trim((string) $id),
      $ids
    ), static fn(string $id): bool => $id !== '')));
  }

}
