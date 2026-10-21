<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Core\Database;

require_once __DIR__ . '/PrecaptacionDatabase.php';

final class CommercialSignDatabase
{
  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = PrecaptacionDatabase::create($dsn);
    $tables = [
      'jet_cct_inmuebles'=>['codigo','direccion','barrio','ciudad','ruta','punto_referencia','tipo_inmueble','tipo_negocio','destinacion','ubicacion_google_maps','presenta_aviso','fecha_aviso','fecha_actualizacion','fecha_retoque','desea_aviso','fue_retocado','estado'],
      'jet_cct_retoques'=>['fecha','tipo_aviso','id_inmueble','id_ticket','ruta','barrio','punto_referencia','tipo_inmueble','categoria','cantidad','pego_aviso','razon','aviso_senuelo','creador','id_empleado','sucursal','fotos','promocionado_por','competencia'],
      'jet_cct_historial_del_inmueble'=>['fecha','id_inmueble','id_ticket','id_empleado','funcionario','tipo_reporte','observacion'],
      'jet_cct_historial_del_ticket'=>['fecha','id_ticket','id_aviso','id_inmueble','id_empleado','nombre','correo','celular','respuesta','fue_editada'],
      'jet_cct_reportes_avisos'=>['fecha','tipo_reporte','observacion','cantidad_avisos','valor','funcionario','fue_pagado','id_empleado','id_inmueble','id_aviso','id_ticket','sucursal','exportado'],
      'jet_cct_despublicaciones'=>['fecha','id_inmueble','estado','observacion_despublicacion','actualizaciones','id_empleado','empleado','id_empleado_dueno','tipo_publicacion','categoria_publicacion','destinacion_publicacion'],
    ];
    foreach ($tables as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT'], array_map(static fn(string $column): string => '`' . $column . '` TEXT', [...$columns,'cct_status','cct_author_id','cct_created','cct_modified']));
      $db->pdo()->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    foreach (['jet_cct_tickets'=>['id_ticket','fecha_actualizacion'], 'jet_cct_funcionarios'=>['id_sucursal']] as $table => $columns) {
      $existing = array_column($db->getResults('PRAGMA table_info(`wp_' . $table . '`)'), 'name');
      foreach ($columns as $column) if (!in_array($column, $existing, true)) $db->pdo()->exec('ALTER TABLE `wp_' . $table . '` ADD COLUMN `' . $column . '` TEXT');
    }
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id TEXT, meta_key TEXT, meta_value TEXT)');
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_skc_ubicacion_inmuebles_mapa (id INTEGER PRIMARY KEY AUTOINCREMENT, codigo_inmueble TEXT, barrio TEXT, ruta TEXT, latitud REAL, longitud REAL, precision_metros REAL, fuente TEXT, google_maps_url TEXT, page_url TEXT, user_agent TEXT, created_by TEXT, cct_created TEXT)');
    $queueFields = ['project_code','source_module','channel','provider','destination','destination_name','subject','message_html','message_text','payload_json','meta_json','status','priority','max_attempts','scheduled_at','created_at','updated_at','created_by','dedupe_key'];
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS skc_notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,' . implode(',', array_map(static fn(string $column): string => '`' . $column . '` TEXT', $queueFields)) . ', UNIQUE(dedupe_key))');
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_jet_post_types (id INTEGER PRIMARY KEY, status TEXT, labels TEXT, args TEXT, meta_fields TEXT)');
    // Mirror the real JetEngine storage, independently of the older wp_options fixture.
    if (!$db->getVar('SELECT id FROM wp_jet_post_types LIMIT 1')) {
      foreach ([
        '783'=>['Apartaestudio','Apartamento','Bodega','Casa','Consultorio','Edificio','Finca','Hotel','Local','Lote','Oficina','Proyecto'],
        '784'=>['Arriendo','Arriendo o venta','Venta'],
        '161'=>['Directo','Inmmobiliaria'],
        '940'=>['Arrendado','Vendido','No quiere promocion','Otra razon'],
      ] as $id => $values) {
        $db->insert('wp_jet_post_types', ['id'=>$id,'status'=>'glossary','meta_fields'=>serialize(array_map(static fn(string $value): array => ['value'=>$value,'label'=>$value,'is_checked'=>false], $values))]);
      }
    }
    if ($db->getVar('SELECT _ID FROM wp_jet_cct_inmuebles LIMIT 1')) return $db;
    $db->insert('wp_jet_cct_inmuebles', ['_ID'=>1,'codigo'=>'500','direccion'=>'Calle de prueba 10','barrio'=>'El Poblado','ciudad'=>'Medellín','ruta'=>'1','punto_referencia'=>'Frente al parque','tipo_inmueble'=>'Casa','tipo_negocio'=>'Arriendo','destinacion'=>'Vivienda','estado'=>'Publicado']);
    $db->insert('wp_jet_cct_inmuebles', ['_ID'=>2,'codigo'=>'600','barrio'=>'Otro barrio','tipo_inmueble'=>'Apartamento']);
    $db->insert('wp_jet_cct_tickets', ['_ID'=>100,'id_ticket'=>'100','id_inmueble'=>'500','id_empleado'=>'101','asunto'=>'Instalar aviso','estado'=>'Abierto','estado_comercial'=>'Solicitando aviso']);
    $db->insert('wp_jet_cct_tickets', ['_ID'=>200,'id_ticket'=>'200','id_inmueble'=>'600','id_empleado'=>'202','asunto'=>'Otra tarea']);
    $db->update('wp_jet_cct_funcionarios', ['id_sucursal'=>'3'], ['_ID'=>1]);
    $db->update('wp_jet_cct_barrios', ['ruta_asignada'=>'1'], ['_ID'=>1]);
    foreach (['valor_aviso'=>'3000','valor_transporte'=>'4000','factor_prestacional'=>'1.52'] as $key => $value) $db->insert('wp_jet_cct_confi_sistema', ['funcion'=>$key,'valor'=>$value]);
    return $db;
  }
}
