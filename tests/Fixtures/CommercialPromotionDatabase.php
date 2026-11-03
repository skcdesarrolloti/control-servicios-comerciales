<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Core\Database;
use SCM\Core\Settings;

require_once __DIR__ . '/PrecaptacionDatabase.php';

/**
 * Base de Promoción › Reuniones para PHPUnit y el fixture del navegador. Cargos:
 * 13 acceso total; 6 Promoción con todas las acciones y todo el equipo; 7 Promoción con gestionar y solo lo suyo;
 * 8 Promoción solo consulta; 10 sin configurar (no recibe Promoción por ser opt-in).
 * Funcionarios (id_empleado): 101, 202, 303 activos y 404 retirado.
 */
final class CommercialPromotionDatabase
{
  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = PrecaptacionDatabase::create($dsn);
    // cargoOptions() (configuración de accesos) usa CONCAT de MySQL.
    $db->pdo()->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    if (!$db->getVar("SELECT 1 FROM wp_jet_cct_funcionarios WHERE id_empleado = '303'")) {
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => 3, 'id_empleado' => '303', 'nombre' => 'Asistente de promoción', 'activo' => 'Si', 'id_cargo' => '7']);
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => 4, 'id_empleado' => '404', 'nombre' => 'Funcionario retirado', 'activo' => 'No', 'id_cargo' => '7']);
      foreach ([6 => 'Coordinación de promoción', 7 => 'Asistente de promoción', 8 => 'Diseño', 10 => 'Sin configurar', 13 => 'Gerencia'] as $id => $name) {
        $db->insert('wp_jet_cct_cargos', ['_ID' => $id, 'nombre_cargo' => $name]);
      }
    }
    return $db;
  }

  public static function settings(Database $db): Settings
  {
    $settings = new Settings($db);
    $permissions = $settings->get('commercial_permissions', []);
    if (!isset($permissions['7'])) {
      $settings->set('commercial_admin_cargos', ['13']);
      $settings->set('commercial_permissions', (is_array($permissions) ? $permissions : []) + [
        '6' => ['views' => ['promocion'], 'actions' => ['reuniones_gestionar', 'reuniones_reabrir', 'reuniones_eliminar'], 'scope' => ['promocion' => 'all']],
        '7' => ['views' => ['promocion', 'herramientas'], 'actions' => ['reuniones_gestionar'], 'scope' => ['promocion' => 'own']],
        '8' => ['views' => ['promocion'], 'actions' => []],
      ]);
    }
    return $settings;
  }

  /** @param array<string,string> $session */
  public static function login(string $cargo, string $employee, string $name = 'Funcionario de prueba', array $session = []): void
  {
    $_SESSION = $session + ['scm_user_id' => 7, 'scm_employee_id' => $employee, 'scm_user_cargo' => $cargo, 'scm_user' => $name, 'scm_logged_in' => true];
  }
}
