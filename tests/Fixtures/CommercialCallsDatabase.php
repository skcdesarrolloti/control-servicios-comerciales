<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PDO;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Base SQLite del módulo de llamadas. Cargos: 13 acceso total; 9 solo lo suyo; 6 ve todo el equipo. Funcionarios 101 y 202.
 * Aliados del Club PPH: TAR-1 activa, TAR-2 inactiva, TAR-3 repetida en dos aliados.
 */
final class CommercialCallsDatabase
{
  private const TABLES = [
    'jet_cct_confi_sistema' => ['funcion', 'valor', 'imagen', 'departamento'],
    'jet_cct_cargos' => ['nombre_cargo'],
    'jet_cct_funcionarios' => ['id_empleado', 'nombre', 'correo', 'celular', 'activo', 'id_cargo', 'id_sucursal'],
    'jet_cct_sucursales' => ['nombre', 'gerente_comercial', 'celular_ge_comercial'],
    'jet_cct_paises' => ['pais', 'codigo'],
    'jet_cct_club_pph' => ['nombre', 'tarjeta_bienvenida', 'activo', 'total_puntos'],
    'jet_cct_clientes' => ['nombre', 'celular', 'correo', 'indicativo', 'tipo_cliente', 'id_cliente', 'bloqueo_email', 'bloqueo_whatsapp'],
    'jet_cct_cct_llamadas' => ['id_ticket', 'id_inmueble', 'id_empleado', 'sucursal', 'merece_ticket', 'cliente', 'celular', 'email', 'tipo_inmueble', 'medio', 'presupuesto', 'destinacion', 'uso', 'factores_importantes', 'precio', 'tema_ayuda', 'observacion', 'descripcion', 'id_pph', 'tarjeta_pph', 'nombre_pph', 'celular_wsp', 'id_cliente', 'indicativo', 'cliente_inmediato', 'mes_inmueble', 'comisionista', 'usuario_wsp'],
    'jet_cct_tickets' => ['id_creador', 'id_cliente', 'id_inmueble', 'nombre_empleado', 'correo_empleado', 'celular_empleado', 'asunto', 'descripcion', 'empleado', 'prioridad', 'tema_ayuda', 'departamento', 'estado', 'estado_comercial', 'medio', 'tipo_inmueble', 'destinacion', 'uso', 'factores', 'presupuesto', 'precio', 'solicitante', 'correo_solicitante', 'celular_solicitante', 'sucursal', 'id_empleado', 'id_llamada', 'id_solicitante', 'tuvo_seguimiento', 'tuvo_reporte', 'id_pph', 'tarjeta_pph', 'nombre_pph', 'bandera', 'celular_wsp', 'indicativo', 'creador_por', 'usuario_wsp'],
    'jet_cct_historial_del_ticket' => ['id_ticket', 'nombre', 'correo', 'celular', 'respuesta', 'id_empleado', 'fue_editada', 'imagen', 'archivos'],
    'jet_cct_historial_del_inmueble' => ['reporte_realizado_por_his', 'fecha_his', 'id_del_inmueble_his', 'observacion_his', 'id_inmueble', 'funcionario', 'observacion', 'id_empleado', 'id_ticket', 'tipo_de_reporte_his', 'tipo_reporte'],
  ];

  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (self::TABLES as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT', 'fecha INTEGER'], array_map(static fn(string $field): string => '`' . $field . '` TEXT', array_merge($columns, ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'])));
      $pdo->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_post_types (id INTEGER PRIMARY KEY, status TEXT, meta_fields TEXT)');
    $db = new Database($pdo);
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_funcionarios') > 0) {
      return $db;
    }
    foreach ([[101, 'Consultora Uno', '9'], [202, 'Consultor Dos', '9'], [303, 'Coordinación', '13'], [404, 'Supervisión', '6']] as [$id, $name, $cargo]) {
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => $id, 'id_empleado' => (string) $id, 'nombre' => $name, 'correo' => $id . '@example.test', 'celular' => '3000000' . $id, 'activo' => 'Si', 'id_cargo' => $cargo, 'id_sucursal' => '1']);
    }
    $db->insert('wp_jet_cct_sucursales', ['_ID' => 1, 'nombre' => 'Principal', 'gerente_comercial' => 'Gerencia Comercial', 'celular_ge_comercial' => '3009999999']);
    $db->insert('wp_jet_cct_cargos', ['_ID' => 9, 'nombre_cargo' => 'Consultor']);
    $db->insert('wp_jet_cct_paises', ['pais' => 'Colombia', 'codigo' => '57']);
    foreach ([['Aliada Uno', 'TAR-1', 'Si'], ['Aliado Inactivo', 'TAR-2', 'No'], ['Repetido A', 'TAR-3', 'Si'], ['Repetido B', 'TAR-3', 'Si']] as [$name, $card, $active]) {
      $db->insert('wp_jet_cct_club_pph', ['nombre' => $name, 'tarjeta_bienvenida' => $card, 'activo' => $active, 'total_puntos' => '0']);
    }
    foreach ([816 => ['Arriendo', 'Venta', 'Captacion'], 783 => ['Apartamento', 'Casa'], 785 => ['Vivienda'], 292 => ['Habitar'], 199 => ['Interesado'], 293 => ['Espacio', 'Ubicación'], 270 => ['Enero', 'Febrero'], 447 => ['Prioridad comercial', 'Prioridad normal']] as $id => $values) {
      $db->insert('wp_jet_post_types', ['id' => $id, 'status' => 'glossary', 'meta_fields' => serialize(array_map(static fn(string $value): array => ['value' => $value, 'label' => $value, 'is_checked' => false], $values))]);
    }
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => Settings::FUNCTION_KEY, 'valor' => (string) json_encode([
      'commercial_admin_cargos' => ['13'],
      'commercial_permissions' => [
        '9' => ['views' => ['llamadas'], 'actions' => ['llamadas_crear', 'llamadas_editar', 'llamadas_tarea', 'ver_ticket'], 'scope' => []],
        '6' => ['views' => ['llamadas'], 'actions' => [], 'scope' => ['llamadas' => 'all']],
      ],
    ], JSON_THROW_ON_ERROR)]);
    return $db;
  }

  /** @return array<string,string> */
  public static function validInput(array $override = []): array
  {
    return $override + [
      'id' => '0', 'fecha' => '', 'tema_ayuda' => 'Arriendo', 'comisionista' => 'No', 'cliente_inmediato' => 'Si', 'merece_ticket' => 'Si', 'id_empleado' => '101',
      'cliente' => 'Laura Prueba', 'email' => 'laura@example.test', 'indicativo' => '57', 'celular' => '300 123-4567', 'medio' => 'Referido', 'descripcion' => 'Busca apartamento',
    ];
  }

  public static function policy(Database $db): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy(new Settings($db), $db, ['13']);
  }
}
