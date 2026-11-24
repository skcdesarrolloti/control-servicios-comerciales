<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PDO;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Base SQLite de los estudios de aseguradora. Cargos: 13 acceso total; 9 crea, responde y desiste y ve solo los suyos;
 * 7 ve la pestaña pero no tiene las acciones. Funcionarios 101 y 202 (cargo 9), 303 (cargo 13), 404 (entregas, cargo 8),
 * 505 (inactivo) y 707 (cargo 7).
 * Inmuebles: 9001 público con propietario 1, 9002 en borrador con propietario 1, 9003 arrendado, 9004 público sin propietario.
 * Tareas: 700 (arriendo) de 101, 701 (arriendo) de 202, 702 (venta) de 101.
 */
final class CommercialStudiesDatabase
{
  private const TABLES = [
    'jet_cct_confi_sistema' => ['funcion', 'valor', 'imagen', 'departamento'],
    'jet_cct_cargos' => ['nombre_cargo'],
    'jet_cct_funcionarios' => ['id_empleado', 'nombre', 'correo', 'celular', 'activo', 'id_cargo', 'id_sucursal'],
    'jet_cct_sucursales' => ['nombre', 'nombre_contractual', 'celular_contractual'],
    'jet_cct_clientes' => ['nombre', 'celular', 'correo', 'indicativo', 'tipo_cliente', 'id_cliente', 'bloqueo_email', 'bloqueo_whatsapp'],
    'jet_cct_aseguradoras' => ['nombre'],
    'jet_cct_propietarios' => ['nombre', 'correo', 'celular', 'documento', 'bloqueo_email'],
    'jet_cct_inmuebles' => ['codigo', 'estado', 'tipo_negocio', 'tipo_inmueble', 'barrio', 'ciudad', 'direccion', 'direccion_fisica', 'copropiedad', 'propietario', 'id_propietario', 'precio_arriendo', 'parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'deposito', 'depositos', 'estrato', 'servicios_publicos', 'id_estudio_aseguradora', 'id_contrato_arrendamiento'],
    'jet_cct_estudios_aseguradoras' => ['estado', 'id_empleado', 'creador', 'aseguradora', 'hora_estudio', 'hora_respuesta', 'valor', 'id_ticket', 'num_solicitud', 'cliente', 'correo_cliente', 'celular_cliente', 'sucursal', 'email_creador', 'celular_creador', 'fecha_respuesta', 'faltante', 'id_cliente', 'razon_desistido', 'ubicacion_llaves', 'persona_llaves', 'contacto_llaves', 'id_inmueble', 'id_inmueble_data', 'id_ticket_entrega', 'id_contrato_mandato', 'id_contrato_mandato_arrendamiento', 'fecha_posible_entrega', 'estado_contrato', 'medio'],
    'jet_cct_tickets' => ['id_empleado', 'empleado', 'nombre_empleado', 'correo_empleado', 'celular_empleado', 'asunto', 'descripcion', 'tema_ayuda', 'departamento', 'prioridad', 'estado', 'estado_comercial', 'estado_administrativo', 'solicitante', 'correo_solicitante', 'celular_solicitante', 'id_solicitante', 'indicativo', 'celular_wsp', 'medio', 'fecha_actualizacion', 'id_estudio_aseguradora', 'id_inmueble', 'id_inmueble_data', 'id_propietario', 'propietario', 'correo_propietario', 'celular_propietario', 'id_contrato', 'contrato', 'inmueble', 'direccion', 'barrio', 'sucursal', 'numero_solicitud', 'consultor_entrega', 'consultor_entrega_correo', 'consultor_entrega_celular', 'fecha_posible_entrega', 'anexos_entrega', 'ubicacion_llaves', 'persona_llaves', 'contacto_llaves', 'estado_rev_entrega', 'estado_inventario', 'estado_acta_entrega', 'tuvo_seguimiento', 'tuvo_reporte', 'creador_por'],
    'jet_cct_contratos_arrendamiento' => ['tipo', 'estado', 'contrato', 'direccion', 'id_inmueble', 'id_inmueble_data', 'propietario', 'correo_propietario', 'celular_propietario', 'id_propietario', 'arrendatario', 'id_arrendatario', 'documento_arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'inmueble', 'sucursal', 'servicios_publicos', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'tipo_inmueble', 'id_ticket_entrega', 'barrio', 'id_ticket_cliente', 'medio', 'id_estudio_aseguradora', 'numero_solicitud', 'aseguradora', 'estrato', 'registro_fotografico', 'razon_desistido'],
    'jet_cct_historial_contrato_arrendamiento' => ['id_inmueble', 'id_inmueble_data', 'tipo_reporte', 'observacion', 'funcionario', 'id_empleado', 'id_ticket', 'inmueble', 'contrato', 'id_contrato'],
    'jet_cct_historial_del_ticket' => ['id_ticket', 'nombre', 'correo', 'celular', 'respuesta', 'id_empleado', 'fue_editada', 'id_estudio_aseguradora'],
    'jet_cct_historial_del_inmueble' => ['id_inmueble', 'id_inmueble_data', 'funcionario', 'observacion', 'id_empleado', 'id_ticket', 'tipo_reporte'],
  ];

  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (self::TABLES as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT', 'fecha INTEGER'], array_map(static fn(string $field): string => '`' . $field . '` TEXT', array_merge($columns, ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'])));
      $pdo->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    $db = new Database($pdo);
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_funcionarios') > 0) {
      return $db;
    }
    foreach ([[101, 'Consultora Uno', '9', 'Si'], [202, 'Consultor Dos', '9', 'Si'], [303, 'Coordinación', '13', 'Si'], [404, 'Libia Entregas', '8', 'Si'], [505, 'Retirado', '8', 'No'], [707, 'Solo lectura', '7', 'Si']] as [$id, $name, $cargo, $active]) {
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => $id, 'id_empleado' => (string) $id, 'nombre' => $name, 'correo' => $id . '@example.test', 'celular' => '3000000' . $id, 'activo' => $active, 'id_cargo' => $cargo, 'id_sucursal' => '1']);
    }
    $db->insert('wp_jet_cct_sucursales', ['_ID' => 1, 'nombre' => 'Principal']);
    foreach (['El libertador', 'Sura', 'Sin seguro'] as $insurer) {
      $db->insert('wp_jet_cct_aseguradoras', ['nombre' => $insurer]);
    }
    $db->insert('wp_jet_cct_propietarios', ['_ID' => 1, 'nombre' => 'Ana Propietaria', 'correo' => 'ana@example.test', 'celular' => '3135550000', 'documento' => '123']);
    $services = serialize(['Agua', 'Energia']);
    foreach ([['9001', 'Publico', '1'], ['9002', 'En borrador', '1'], ['9003', 'Arrendado', '1'], ['9004', 'Publico', '']] as $index => [$code, $state, $owner]) {
      $db->insert('wp_jet_cct_inmuebles', [
        '_ID' => 13000 + $index, 'codigo' => $code, 'estado' => $state, 'tipo_negocio' => 'Arriendo', 'tipo_inmueble' => 'Apartamento', 'barrio' => 'Manga', 'ciudad' => 'Cartagena',
        'direccion' => 'Calle ' . $code, 'direccion_fisica' => 'Calle ' . $code . ' apto 1', 'propietario' => $owner !== '' ? 'Ana Propietaria' : '', 'id_propietario' => $owner,
        'parqueadero' => 'Si', 'parqueaderos' => '1', 'deposito' => 'No', 'estrato' => '4', 'servicios_publicos' => $services,
      ]);
      $db->insert('wp_postmeta', ['post_id' => (int) $code, 'meta_key' => 'estado', 'meta_value' => $state]);
    }
    foreach ([[700, '101', 'Consultora Uno', 'Arriendo'], [701, '202', 'Consultor Dos', 'Arriendo'], [702, '101', 'Consultora Uno', 'Venta']] as [$id, $employee, $name, $topic]) {
      $db->insert('wp_jet_cct_tickets', [
        '_ID' => $id, 'id_empleado' => $employee, 'nombre_empleado' => $name, 'correo_empleado' => $employee . '@example.test', 'celular_empleado' => '3000000' . $employee,
        'asunto' => 'Busca arriendo', 'tema_ayuda' => $topic, 'estado' => 'Abierto', 'estado_comercial' => 'Mostrando', 'estado_administrativo' => 'Pendiente', 'medio' => 'Finca Raíz',
        'solicitante' => 'Laura Cliente', 'correo_solicitante' => 'laura@example.test', 'celular_solicitante' => '3001234567', 'id_solicitante' => '55', 'indicativo' => '+57',
      ]);
    }
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => Settings::FUNCTION_KEY, 'valor' => (string) json_encode([
      'commercial_admin_cargos' => ['13'],
      'commercial_permissions' => [
        '9' => ['views' => ['estudios'], 'actions' => ['estudio_crear', 'estudio_responder', 'estudio_desistir', 'ver_ticket'], 'scope' => []],
        '7' => ['views' => ['estudios'], 'actions' => ['ver_ticket'], 'scope' => []],
      ],
    ], JSON_THROW_ON_ERROR)]);
    return $db;
  }

  public static function policy(Database $db): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy(new Settings($db), $db, ['13']);
  }
}
