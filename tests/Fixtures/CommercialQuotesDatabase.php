<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PDO;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Base SQLite de las cotizaciones. Cargos: 13 acceso total; 9 crea cotizaciones y ve solo las suyas; 7 ve la pestaña pero
 * no crea. Funcionarios 101 (cargo 9), 202 (cargo 9), 303 (cargo 13) y 707 (cargo 7).
 * Inmuebles: 501 y 502 públicos en arriendo al día, 503 público vencido, 504 arrendado, 505 público en venta.
 * Tareas: 700 de 101 y 701 de 202.
 */
final class CommercialQuotesDatabase
{
  private const TABLES = [
    'jet_cct_confi_sistema' => ['funcion', 'valor', 'imagen', 'departamento'],
    'jet_cct_cargos' => ['nombre_cargo'],
    'jet_cct_funcionarios' => ['id_empleado', 'nombre', 'correo', 'celular', 'activo', 'id_cargo', 'id_sucursal', 'ficha', 'rol'],
    // Ficha del inmueble ("Ver ficha" del buscador de la cotización).
    'jet_cct_propietarios' => ['nombre', 'celular', 'correo', 'documento'],
    'jet_cct_caract_internas' => ['valor', 'etiqueta', 'icono'],
    'jet_cct_caract_externas' => ['valor', 'etiqueta', 'icono'],
    'jet_cct_zonas_sociales' => ['valor', 'etiqueta', 'icono'],
    'jet_cct_alrededores' => ['valor', 'etiqueta', 'icono'],
    'jet_cct_sucursales' => ['nombre', 'gerente_comercial', 'celular_ge_comercial'],
    'jet_cct_clientes' => ['nombre', 'celular', 'correo', 'indicativo', 'tipo_cliente', 'id_cliente', 'bloqueo_email', 'bloqueo_whatsapp'],
    'jet_cct_inmuebles' => ['codigo', 'estado', 'tipo_negocio', 'tipo_inmueble', 'barrio', 'ciudad', 'direccion', 'copropiedad', 'precio_arriendo', 'precio_venta', 'precio_admin', 'habitaciones', 'banos', 'parqueaderos', 'area_construida', 'area_privada', 'area_terreno', 'estrato', 'edad', 'destinacion', 'amoblado', 'acepta_permuta', 'tipo_permuta', 'deposito', 'vigilancia', 'negociable', 'interiores', 'exteriores', 'zonas_sociales', 'alrededores', 'descripcion', 'foto_portada', 'galeria', 'video', 'id_funcionario', 'id_propietario', 'funcionario', 'propietario', 'fecha_actualizacion', 'fecha_publicacion'],
    'jet_cct_cotizacion_inmuebles' => ['id_empleado', 'creador', 'inmuebles', 'destinatario', 'direccion', 'email', 'celular', 'observaciones', 'sucursal', 'id_ticket', 'email_creador', 'celular_creador', 'tipo', 'id_cliente', 'url_cotizacion', 'url_pdf'],
    'jet_cct_tickets' => ['id_empleado', 'nombre_empleado', 'correo_empleado', 'celular_empleado', 'asunto', 'tema_ayuda', 'estado', 'estado_comercial', 'estado_administrativo', 'solicitante', 'correo_solicitante', 'celular_solicitante', 'id_solicitante', 'indicativo', 'celular_wsp', 'id_cotizacion_comercial', 'fecha_actualizacion'],
    'jet_cct_historial_del_ticket' => ['id_ticket', 'nombre', 'correo', 'celular', 'respuesta', 'id_empleado', 'fue_editada', 'id_cotizacion_comercial'],
    'jet_cct_historial_del_inmueble' => ['id_inmueble', 'inmuebles_cotizados', 'funcionario', 'observacion', 'id_empleado', 'id_ticket', 'tipo_reporte'],
  ];

  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // MySQL trae REGEXP (los filtros de PropertySearchFilters); SQLite lo pide como función: "x REGEXP p" = regexp(p, x).
    $pdo->sqliteCreateFunction('regexp', static fn($pattern, $value): bool => preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', (string) $value) === 1, 2);
    foreach (self::TABLES as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT', 'fecha INTEGER'], array_map(static fn(string $field): string => '`' . $field . '` TEXT', array_merge($columns, ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'])));
      $pdo->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_posts (ID INTEGER PRIMARY KEY, guid TEXT, post_type TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    $db = new Database($pdo);
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_funcionarios') > 0) {
      return $db;
    }
    foreach ([[101, 'Consultora Uno', '9'], [202, 'Consultor Dos', '9'], [303, 'Coordinación', '13'], [707, 'Solo lectura', '7']] as [$id, $name, $cargo]) {
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => $id, 'id_empleado' => (string) $id, 'nombre' => $name, 'correo' => $id . '@example.test', 'celular' => '3000000' . $id, 'activo' => 'Si', 'id_cargo' => $cargo, 'id_sucursal' => '1']);
    }
    $db->insert('wp_jet_cct_sucursales', ['_ID' => 1, 'nombre' => 'Principal', 'gerente_comercial' => 'Gerencia Comercial', 'celular_ge_comercial' => '3009999999']);
    $db->insert('wp_posts', ['ID' => 9001, 'guid' => 'http://img.example.test/9001.jpg', 'post_type' => 'attachment']);
    $db->insert('wp_posts', ['ID' => 9002, 'guid' => 'https://img.example.test/9002.jpg', 'post_type' => 'attachment']);
    $day = 86400;
    $properties = [
      ['501', 'Publico', 'Arriendo', 'Apartamento', 'Manga', '2.500.000', '', '300.000', '3', '2', '1', '80', '4', serialize(['Balcón', 'Cocina integral']), serialize(['Piscina']), time() - 10 * $day, '9001', '9001,9002'],
      ['502', 'Publico', 'Arriendo', 'Casa', 'Crespo', '3.200.000', '', '', '4', '3', '2', '140', '5', serialize(['Cocina integral', 'Patio']), '', time() - 5 * $day, '', ''],
      ['503', 'Publico', 'Arriendo', 'Apartamento', 'Bocagrande', '4.000.000', '', '', '2', '2', '1', '70', '6', '', '', time() - 200 * $day, '', ''],
      ['504', 'Arrendado', 'Arriendo', 'Local', 'Centro', '5.000.000', '', '', '', '1', '', '60', '', '', '', time() - 2 * $day, '', ''],
      ['505', 'Publico', 'Venta', 'Apartamento', 'Castillogrande', '', '400.000.000', '', '3', '3', '2', '120', '6', '', '', time() - 3 * $day, '', ''],
    ];
    foreach ($properties as [$code, $state, $business, $type, $barrio, $rent, $sale, $admin, $rooms, $baths, $parking, $area, $estrato, $interior, $social, $updated, $cover, $gallery]) {
      $db->insert('wp_jet_cct_inmuebles', [
        'codigo' => $code, 'estado' => $state, 'tipo_negocio' => $business, 'tipo_inmueble' => $type, 'barrio' => $barrio, 'ciudad' => 'Cartagena', 'direccion' => 'Calle secreta ' . $code,
        'precio_arriendo' => $rent, 'precio_venta' => $sale, 'precio_admin' => $admin, 'habitaciones' => $rooms, 'banos' => $baths, 'parqueaderos' => $parking, 'area_construida' => $area,
        'estrato' => $estrato, 'interiores' => $interior, 'zonas_sociales' => $social, 'fecha_actualizacion' => (string) $updated, 'foto_portada' => $cover, 'galeria' => $gallery,
        'id_funcionario' => '101', 'propietario' => 'Propietario reservado', 'descripcion' => 'Inmueble ' . $code . ' bien ubicado.',
      ]);
    }
    foreach ([[700, '101', 'Consultora Uno'], [701, '202', 'Consultor Dos']] as [$id, $employee, $name]) {
      $db->insert('wp_jet_cct_tickets', [
        '_ID' => $id, 'id_empleado' => $employee, 'nombre_empleado' => $name, 'asunto' => 'Busca arriendo', 'tema_ayuda' => 'Arriendo', 'estado' => 'Abierto', 'estado_comercial' => 'Nuevo',
        'estado_administrativo' => 'Pendiente', 'solicitante' => 'Laura Cliente', 'correo_solicitante' => 'laura@example.test', 'celular_solicitante' => '3001234567', 'id_solicitante' => '55',
      ]);
    }
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => Settings::FUNCTION_KEY, 'valor' => (string) json_encode([
      'commercial_admin_cargos' => ['13'],
      'commercial_permissions' => [
        '9' => ['views' => ['cotizaciones'], 'actions' => ['cotizacion_crear', 'ver_ticket'], 'scope' => []],
        '7' => ['views' => ['cotizaciones'], 'actions' => ['ver_ticket'], 'scope' => []],
      ],
    ], JSON_THROW_ON_ERROR)]);
    return $db;
  }

  public static function policy(Database $db): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy(new Settings($db), $db, ['13']);
  }
}
