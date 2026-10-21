<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\CommercialPropertyEntryService;
use SCM\Core\Database;

require_once __DIR__ . '/CommercialSignDatabase.php';

final class CommercialPropertyEntryDatabase
{
  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = CommercialSignDatabase::create($dsn);
    $definitions = json_decode((string) file_get_contents(__DIR__ . '/property-entry-catalogs.json'), true, 512, JSON_THROW_ON_ERROR);
    $tables = [
      'jet_cct_propietarios'=>['nombre','documento','celular','correo'],
      'jet_cct_sucursales'=>['correo_comercial','celular_comercial'],
      'jet_cct_recaptaciones'=>['fecha','id_inmueble','id_empleado','id_ticket','empleado'],
      'jet_rel_941'=>['created','rel_id','parent_rel','parent_object_id','child_object_id'],
      'jet_rel_658'=>['created','rel_id','parent_rel','parent_object_id','child_object_id'],
      'jet_cct_inmuebles'=>array_merge(array_values(array_filter(CommercialPropertyEntryService::definition()['cct_map'])), ['foto_portada','galeria','video']),
      'jet_cct_tickets'=>['id_inmueble_data','id_propietario'],
    ];
    foreach ($definitions as $definition) {
      $existing = $db->getVar('SELECT id FROM wp_jet_post_types WHERE id=?', [$definition['id']]);
      $record = array_intersect_key($definition, array_flip(['id','status','labels','args','meta_fields']));
      if (!$existing) $db->insert('wp_jet_post_types', $record);
      if ($definition['status'] !== 'query') continue;
      $args = unserialize($definition['args'], ['allowed_classes'=>false]);
      $cct = $args['custom-content-type'] ?? [];
      $name = 'jet_cct_' . ($cct['content_type'] ?? '');
      $tables[$name] = array_unique(array_merge($tables[$name] ?? [], ['valor','etiqueta','pais','departamento','ciudad','barrio','tarjeta_bienvenida','nombre','tipo_publicacion','categoria_campo'], array_column($cct['args'] ?? [], 'field')));
    }
    foreach ($tables as $name => $columns) {
      $db->pdo()->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (_ID INTEGER PRIMARY KEY AUTOINCREMENT)');
      $existing = array_column($db->getResults('PRAGMA table_info(`wp_' . $name . '`)'), 'name');
      foreach (array_unique([...$columns,'cct_status','cct_author_id','cct_created','cct_modified']) as $column) if (!in_array($column, $existing, true)) $db->pdo()->exec('ALTER TABLE `wp_' . $name . '` ADD COLUMN `' . $column . '` TEXT');
    }
    $postFields = ['post_author','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt','post_content_filtered','post_parent','guid','menu_order','post_type','post_mime_type','comment_count'];
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT,' . implode(',', array_map(static fn(string $column): string => '`' . $column . '` TEXT', $postFields)) . ')');
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_terms (term_id INTEGER PRIMARY KEY,name TEXT)');
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY,term_id INTEGER,taxonomy TEXT,count INTEGER DEFAULT 0)');
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS wp_term_relationships (object_id INTEGER,term_taxonomy_id INTEGER,term_order INTEGER DEFAULT 0,PRIMARY KEY(object_id,term_taxonomy_id))');
    foreach (['caract_internas'=>['Sala','Cocina Integral','Balcon','Baño'],'caract_externas'=>['Jardin','Vigilancia'],'alrededores'=>['Parques cercanos','Cerca a colegio'],'zonas_sociales'=>['Piscina','Gimnasio']] as $table=>$values) {
      foreach ($values as $value) if (!$db->getVar('SELECT _ID FROM `wp_jet_cct_' . $table . '` WHERE valor=?', [$value])) $db->insert('wp_jet_cct_' . $table, ['valor'=>$value,'etiqueta'=>$value]);
    }
    if (!$db->getVar('SELECT interiores FROM wp_jet_cct_inmuebles WHERE codigo=500')) $db->update('wp_jet_cct_inmuebles', ['interiores'=>serialize(['Sala','Cocina Integral']),'exteriores'=>serialize(['Jardin']),'alrededores'=>serialize([json_encode(['Parques cercanos','Cerca a colegio'])])], ['codigo'=>'500']);
    if ($db->getVar('SELECT ID FROM wp_posts LIMIT 1')) return $db;
    foreach ([103=>['Apartaestudio','tipo-de-inmueble'],104=>['Apartamento','tipo-de-inmueble'],106=>['Casa','tipo-de-inmueble'],113=>['Lote','tipo-de-inmueble'],117=>['Arriendo','gestion'],118=>['Arriendo/Venta','gestion'],119=>['Venta','gestion'],121=>['Vivienda','destinacion'],122=>['Comercio','destinacion']] as $id => [$label,$taxonomy]) {
      $db->insert('wp_terms', ['term_id'=>$id,'name'=>$label]); $db->insert('wp_term_taxonomy', ['term_taxonomy_id'=>$id,'term_id'=>$id,'taxonomy'=>$taxonomy]);
    }
    $db->insert('wp_jet_cct_propietarios', ['_ID'=>10,'nombre'=>'Propietario de prueba','documento'=>'123456','celular'=>'3000000000','correo'=>'owner@example.test']);
    $db->insert('wp_jet_cct_sucursales', ['_ID'=>3,'correo_comercial'=>'branch@example.test','celular_comercial'=>'3001111111']);
    $db->insert('wp_jet_cct_departamentos', ['departamento'=>'Antioquia','pais'=>'Colombia']);
    $db->insert('wp_jet_cct_ciudades', ['ciudad'=>'Medellín','departamento'=>'Antioquia','pais'=>'Colombia']);
    $db->update('wp_jet_cct_club_pph', ['activo'=>'Si'], ['_ID'=>1]);
    $db->update('wp_jet_cct_tickets', ['id_propietario'=>'10'], ['_ID'=>100]);
    $db->insert('wp_posts', ['ID'=>800,'post_type'=>'inmuebles','post_status'=>'publish','post_author'=>'101','post_title'=>'Código reservado']);
    $db->insert('wp_postmeta', ['post_id'=>800,'meta_key'=>'estado','meta_value'=>'En borrador']);
    $db->insert('wp_postmeta', ['post_id'=>800,'meta_key'=>'id_empleado','meta_value'=>'101']);
    return $db;
  }
}
