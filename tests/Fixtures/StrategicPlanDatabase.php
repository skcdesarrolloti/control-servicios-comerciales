<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Core\Database;
use SCM\Core\Settings;

require_once __DIR__ . '/PrecaptacionDatabase.php';

/**
 * Base del Plan estratégico para PHPUnit y el fixture del endpoint, en SQLite, con las tablas CCT del plugin Gestor
 * Estratégico. Cargos: 13 acceso total; 7 Herramientas con plan_estrategico_editar; 9 sin configurar (ve el plan, no
 * edita); 8 Herramientas sin la subpestaña del plan. Funcionarios activos: "Funcionario de prueba" (101) y "Segundo
 * consultor" (202).
 */
final class StrategicPlanDatabase
{
  public const TABLES = [
    'gestiones' => ['nombre', 'definicion', 'alcance'],
    'unidades' => ['nombre', 'simbolo'],
    'tipos_campana' => ['nombre', 'definicion'],
    'tipos_medio' => ['nombre', 'definicion'],
    'tipos_mercadeo' => ['nombre', 'definicion'],
    'frecuencias' => ['nombre', 'definicion'],
    'actores' => ['nombre_actor', 'tipo', 'definicion'],
    'objetivos' => ['nombre_objetivo', 'perspectiva', 'responsable_area', 'periodo_tipo', 'periodo_valor', 'valor_objetivo'],
    'metas' => ['nombre_meta', 'id_objetivo', 'valor_actual', 'valor_meta', 'unidad', 'fecha_limite', 'estado'],
    'tareas' => ['nombre_tarea', 'id_meta', 'responsable', 'estado', 'fecha_inicio', 'fecha_fin', 'recursos', 'id_actor', 'descripcion', 'historial_avances'],
    'campanas' => ['tipo_campana', 'nombre_campana', 'id_actor', 'tipo_medio', 'tipo_mercadeo', 'frecuencia', 'responsable', 'pieza_actual', 'observacion', 'informes', 'id_tarea'],
  ];

  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = PrecaptacionDatabase::create($dsn);
    $pdo = $db->pdo();
    // cargoOptions() (Configuración) usa CONCAT de MySQL.
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    foreach (self::TABLES as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT'], array_map(static fn(string $field): string => '`' . $field . '` TEXT', array_merge($columns, ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'])));
      $pdo->exec('CREATE TABLE IF NOT EXISTS `wp_jet_cct_' . $name . '` (' . implode(',', $fields) . ')');
    }
    if ($db->getVar('SELECT 1 FROM wp_jet_cct_cargos WHERE _ID = 13')) return $db;
    foreach ([7 => 'Coordinación de mercadeo', 8 => 'Recepción', 13 => 'Gerencia'] as $id => $name) {
      $db->insert('wp_jet_cct_cargos', ['_ID' => $id, 'nombre_cargo' => $name]);
    }
    $db->insert('wp_jet_cct_funcionarios', ['_ID' => 3, 'id_empleado' => '303', 'nombre' => 'Retirado', 'activo' => 'No', 'id_cargo' => '9']);
    return $db;
  }

  public static function settings(Database $db): Settings
  {
    $settings = new Settings($db);
    $settings->set('commercial_admin_cargos', ['13']);
    $settings->set('commercial_permissions', [
      '7' => ['views' => ['herramientas'], 'actions' => ['plan_estrategico_editar']],
      '8' => ['views' => ['herramientas'], 'actions' => [], 'subviews' => ['herramientas' => ['generador_utm']]],
    ]);
    return $settings;
  }

  /**
   * Plan de muestra: 2 gestiones, unidades, catálogos de campaña, 2 actores, 2 objetivos, 2 metas, 2 tareas y 1 campaña.
   * @return array<string,int> ids por nombre corto.
   */
  public static function seed(Database $db): array
  {
    $row = static function (string $table, array $data) use ($db): int {
      $db->insert('wp_jet_cct_' . $table, $data + ['cct_status' => 'publish', 'cct_author_id' => '1', 'cct_created' => '2026-01-01 00:00:00', 'cct_modified' => '2026-01-01 00:00:00']);
      return (int) $db->lastInsertId();
    };
    $row('gestiones', ['nombre' => 'Gestión Comercial', 'definicion' => 'Ventas y arriendos', 'alcance' => 'Todo el equipo']);
    $row('gestiones', ['nombre' => 'Gestión de Mercadeo', 'definicion' => 'Marca y campañas', 'alcance' => '']);
    $row('unidades', ['nombre' => 'Porcentaje', 'simbolo' => '%']);
    $row('unidades', ['nombre' => 'Millones', 'simbolo' => 'Millones']);
    $row('tipos_campana', ['nombre' => 'Posicionamiento', 'definicion' => '']);
    $row('tipos_medio', ['nombre' => 'Redes sociales', 'definicion' => '']);
    $row('tipos_medio', ['nombre' => 'Buscadores', 'definicion' => '']);
    $row('tipos_mercadeo', ['nombre' => 'Inbound', 'definicion' => '']);
    $row('frecuencias', ['nombre' => 'Mensual', 'definicion' => '']);
    $ids = [];
    $ids['actor'] = $row('actores', ['nombre_actor' => 'Propietarios', 'tipo' => 'actores', 'definicion' => '']);
    $ids['actor2'] = $row('actores', ['nombre_actor' => 'Arrendatarios', 'tipo' => 'actores', 'definicion' => '']);
    $ids['objetivo'] = $row('objetivos', ['nombre_objetivo' => 'Aumentar cierres de arriendo', 'perspectiva' => 'Gestión Comercial', 'responsable_area' => 'Funcionario de prueba,Retirado', 'periodo_tipo' => 'Anual', 'periodo_valor' => '2026', 'valor_objetivo' => '40%']);
    $ids['objetivo_vacio'] = $row('objetivos', ['nombre_objetivo' => 'Posicionar la marca', 'perspectiva' => 'Gestión de Mercadeo', 'responsable_area' => '', 'periodo_tipo' => 'Anual', 'periodo_valor' => '2026', 'valor_objetivo' => '']);
    // fecha_limite: medianoche de Bogotá (como el plugin) para 2026-02-28; la otra, medianoche UTC (como JetEngine).
    $ids['meta'] = $row('metas', ['nombre_meta' => 'Cerrar 30 arriendos', 'id_objetivo' => (string) $ids['objetivo'], 'valor_actual' => '12', 'valor_meta' => '30', 'unidad' => '%', 'fecha_limite' => '1772254800', 'estado' => 'En Proceso']);
    $ids['meta2'] = $row('metas', ['nombre_meta' => 'Estudios presentados', 'id_objetivo' => (string) $ids['objetivo'], 'valor_actual' => '736', 'valor_meta' => '950', 'unidad' => 'Millones', 'fecha_limite' => '1798675200', 'estado' => 'Sin realizar']);
    $ids['tarea'] = $row('tareas', ['nombre_tarea' => 'Referidos de propietarios', 'id_meta' => $ids['meta'] . ',' . $ids['meta2'], 'responsable' => 'Funcionario de prueba', 'estado' => 'Pendiente', 'fecha_inicio' => '1768366800', 'fecha_fin' => '0', 'recursos' => '', 'id_actor' => (string) $ids['actor'], 'descripcion' => 'Llamar a la base', 'historial_avances' => json_encode([['fecha' => '2026-01-14 08:51:14', 'funcionario' => 'Funcionario de prueba', 'descripcion' => 'Primer contacto']])]);
    $ids['tarea2'] = $row('tareas', ['nombre_tarea' => 'Visitar copropiedades', 'id_meta' => (string) $ids['meta2'], 'responsable' => 'Segundo consultor', 'estado' => 'En Proceso', 'fecha_inicio' => '0', 'fecha_fin' => '0', 'recursos' => '', 'id_actor' => '', 'descripcion' => '', 'historial_avances' => null]);
    $ids['campana'] = $row('campanas', ['tipo_campana' => 'Posicionamiento', 'nombre_campana' => 'Despide el año', 'id_actor' => $ids['actor'] . ',' . $ids['actor2'], 'tipo_medio' => 'Redes sociales,Buscadores', 'tipo_mercadeo' => 'Inbound', 'frecuencia' => 'Mensual', 'responsable' => 'Segundo consultor', 'pieza_actual' => 'Estrena hogar', 'observacion' => null, 'informes' => null, 'id_tarea' => (string) $ids['tarea']]);
    return $ids;
  }

  /** @param array<string,mixed> $session */
  public static function login(string $cargo, string $employee, string $name = 'Funcionario de prueba', array $session = []): void
  {
    $_SESSION = $session + ['scm_user_id' => 7, 'scm_employee_id' => $employee, 'scm_user_cargo' => $cargo, 'scm_user' => $name, 'scm_logged_in' => true];
  }
}
