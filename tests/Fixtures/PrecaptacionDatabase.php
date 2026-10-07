<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PDO;
use SCM\Core\Database;

final class PrecaptacionDatabase
{
  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('regexp', static fn(string $pattern, $value): int => preg_match('/' . $pattern . '/', (string) $value) === 1 ? 1 : 0);
    $tables = [
      'jet_cct_confi_sistema'=>['funcion','valor'],
      'jet_cct_cargos'=>['nombre_cargo'],
      'jet_cct_funcionarios'=>['id_empleado','nombre','correo','celular','activo','id_cargo','sucursal','pais','ciudad','user_others_apss'],
      'jet_cct_precaptaciones'=>['id_precaptacion','fecha','creador','id_empleado','sucursal','origen','id_pph','tipo_inmueble','categoria','fotos','tipo_contacto','contacto','barrio','direccion','punto_referencia','telefono','indicativo','celular','promocionado_por','competencia','observaciones','contactado','tiene_ticket','tuvo_seguimiento','bandera','tarjeta_pph','ruta','razones','resultado','merece_ticket','correo','id_ticket_asignado'],
      'jet_cct_barrios'=>['barrio','pais','ciudad','latitud','longitud','codigo_postal','ruta_asignada'],
      'jet_cct_inmobiliarias'=>['inmobiliaria'],
      'jet_cct_club_pph'=>['tarjeta_bienvenida','nombre','total_puntos','membresia','fecha_actividad'],
      'jet_cct_paises'=>['nombre','pais','codigo','indicativo'],
      'options'=>['option_name','option_value'],
      'jet_cct_tickets'=>['id_creador','id_asignado','asignado','empleado','nombre_empleado','correo_empleado','celular_empleado','fecha','fecha_creacion_ticket','solicitante','correo_solicitante','celular_solicitante','prioridad','tema_ayuda','id_inmueble','tipo_inmueble','destinacion','uso','medio','precio','presupuesto','factores','asunto','descripcion','id_empleado','sucursal','id_pph','nombre_pph','indicativo','estado','estado_comercial','departamento','tuvo_seguimiento','tuvo_reporte','creador_por'],
    ];
    foreach ($tables as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT'], array_map(static fn(string $field): string => '`' . $field . '` TEXT', array_merge($columns, ['cct_status','cct_author_id','cct_created','cct_modified'])));
      $pdo->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    $db = new Database($pdo);
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_funcionarios') > 0) return $db;
    $db->insert('wp_jet_cct_funcionarios', ['_ID'=>1,'id_empleado'=>'101','nombre'=>'Funcionario de prueba','activo'=>'Si','correo'=>'test@example.test','id_cargo'=>'9','pais'=>'Colombia','ciudad'=>'Medellín','sucursal'=>'Principal']);
    $db->insert('wp_jet_cct_funcionarios', ['_ID'=>2,'id_empleado'=>'202','nombre'=>'Segundo consultor','activo'=>'Si','correo'=>'other@example.test','id_cargo'=>'9','pais'=>'Colombia','ciudad'=>'Medellín','sucursal'=>'Principal']);
    $db->insert('wp_jet_cct_cargos', ['_ID'=>9,'nombre_cargo'=>'Consultor de Arriendo']);
    $db->insert('wp_jet_cct_confi_sistema', ['funcion'=>'control_servicios_config','valor'=>json_encode(['commercial_admin_cargos'=>['13'],'commercial_permissions'=>['9'=>['views'=>['precaptacion'],'actions'=>['precaptacion_crear','precaptacion_editar','precaptacion_ticket','precaptacion_catalogos']]]], JSON_THROW_ON_ERROR)]);
    $db->insert('wp_jet_cct_barrios', ['barrio'=>'El Poblado','pais'=>'Colombia','ciudad'=>'Medellín','latitud'=>'6.21','longitud'=>'-75.56','codigo_postal'=>'050021','ruta_asignada'=>'Ruta 1']);
    $db->insert('wp_jet_cct_inmobiliarias', ['inmobiliaria'=>'Inmobiliaria de prueba']);
    $db->insert('wp_jet_cct_club_pph', ['tarjeta_bienvenida'=>'Aliado de prueba','nombre'=>'Aliado','total_puntos'=>'0']);
    $db->insert('wp_jet_cct_paises', ['nombre'=>'Colombia','pais'=>'Colombia','codigo'=>'57','indicativo'=>'57']);
    $glossaries = [];
    foreach (['185'=>['Recorrido','Club PPH'],'783'=>['Apartamento','Casa'],'160'=>['Propietario','Arrendatario'],'161'=>['Propietario','Inmmobiliaria']] as $id => $values) {
      $glossaries[] = ['id'=>(string) $id,'fields'=>array_map(static fn(string $value): array => ['value'=>$value,'label'=>$value], $values)];
    }
    $db->insert('wp_options', ['option_name'=>'jet_engine_glossaries','option_value'=>serialize($glossaries)]);
    $db->insert('wp_jet_cct_precaptaciones', ['_ID'=>1,'id_empleado'=>'101','creador'=>'Funcionario de prueba','origen'=>'Recorrido','tipo_inmueble'=>'Casa','categoria'=>'Arriendo','contacto'=>'Contacto de prueba','celular'=>'3001234567','barrio'=>'El Poblado','contactado'=>'No','tiene_ticket'=>'No','fecha'=>(string) time(),'promocionado_por'=>serialize(['Propietario']),'competencia'=>serialize([]),'bandera'=>'No']);
    $db->insert('wp_jet_cct_precaptaciones', ['_ID'=>2,'id_empleado'=>'202','origen'=>'Recorrido','contacto'=>'Registro de otro funcionario','contactado'=>'No','fecha'=>(string) time()]);
    return $db;
  }
}
