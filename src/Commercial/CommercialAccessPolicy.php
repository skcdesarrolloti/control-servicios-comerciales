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
    'actualizaciones' => 'Actualizaciones de inmuebles',
    'avisos' => 'Avisos en fachada',
    'abiertos' => 'Tareas abiertas',
    'postergados' => 'Tareas postergadas',
    'cerrados' => 'Tareas cerradas',
    'mis_tickets' => 'Mis tareas',
    'calendario' => 'Calendario comercial',
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
    if ($view === 'mis_tickets') {
      return true;
    }
    return in_array($view, $this->allowed('views', array_keys(self::VIEWS)), true);
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

  /** @return array<string,array{views:array<int,string>,actions:array<int,string>}> */
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

  /** @param array<string,mixed> $raw @return array<string,array{views:array<int,string>,actions:array<int,string>}> */
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
      ];
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
