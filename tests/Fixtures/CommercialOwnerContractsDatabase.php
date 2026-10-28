<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Core\Database;

require_once __DIR__ . '/PrecaptacionDatabase.php';

/** Contratos, mandatos y créditos que comparte el liquidador del panel inmobiliario. */
final class CommercialOwnerContractsDatabase
{
  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = PrecaptacionDatabase::create($dsn);
    $tables = [
      'jet_cct_contratos_arrendamiento'=>['contrato','inmueble','id_inmueble','id_inmueble_data','direccion','estado','arrendatario','propietario','correo_propietario','celular_propietario','indicativo_propietario','documento_propietario','id_propietario','id_contrato_mandato','id_carta_aumento_canon','valor_canon','inicio_contrato','fin_contrato'],
      'jet_cct_contrato_mandato'=>['propietarios','total_comision','incluye_garantia','precio_mes_garantia','incluye_garantia_servicios','precio_mes_servicios','tipo_1','nombre_1','empresa_1','documento_1','nit_1','correo_1','celular_1','indicativo_1','id_propietario_nuevo_1','tipo_2','nombre_2','empresa_2','documento_2','nit_2','correo_2','celular_2','indicativo_2','id_propietario_nuevo_2'],
      'jet_cct_propietarios'=>['id_propietario','documento','documento_juridico'],
      'jet_cct_cartas_aumento'=>['canon'],
    ];
    foreach ($tables as $name => $columns) {
      $fields = array_merge(['_ID INTEGER PRIMARY KEY AUTOINCREMENT'], array_map(static fn(string $column): string => '`' . $column . '` TEXT', [...$columns,'cct_status','cct_author_id','cct_created','cct_modified']));
      $db->pdo()->exec('CREATE TABLE IF NOT EXISTS `wp_' . $name . '` (' . implode(',', $fields) . ')');
    }
    $db->pdo()->exec("CREATE TABLE IF NOT EXISTS wp_scm_owner_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_document TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pendiente', approved_amount REAL NULL)");
    if ($db->getVar('SELECT _ID FROM wp_jet_cct_contratos_arrendamiento LIMIT 1')) return $db;
    $end = (string) strtotime('+14 months 00:00:00');
    // Contrato con mandato de dos propietarios: el documento del segundo debe resolverlo como beneficiario.
    $db->insert('wp_jet_cct_contrato_mandato', ['_ID'=>10,'propietarios'=>'2','total_comision'=>'250000','incluye_garantia'=>'Si','precio_mes_garantia'=>'40000','incluye_garantia_servicios'=>'Si','precio_mes_servicios'=>'15000','tipo_1'=>'Natural','nombre_1'=>'Ana Propietaria','documento_1'=>'1.143.415.938','correo_1'=>'ana@example.test','celular_1'=>'3145106244','indicativo_1'=>'+57','id_propietario_nuevo_1'=>'P-1','tipo_2'=>'Natural','nombre_2'=>'Luis Copropietario','documento_2'=>'7654321','correo_2'=>'luis@example.test','celular_2'=>'3001112233','indicativo_2'=>'57']);
    $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>1,'contrato'=>'C-100','inmueble'=>'500','id_inmueble'=>'500','direccion'=>'Calle 10 # 20-30','estado'=>'Entregado','arrendatario'=>'Pedro Arrendatario','propietario'=>'Ana Propietaria','id_propietario'=>'P-1','id_contrato_mandato'=>'10','valor_canon'=>'2000000','fin_contrato'=>$end]);
    // Sin mandato: se descuenta el porcentaje configurado del canon.
    $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>2,'contrato'=>'C-200','inmueble'=>'600','direccion'=>'Carrera 5','estado'=>'Por entregar','propietario'=>'Ana Propietaria','correo_propietario'=>'ana@example.test','celular_propietario'=>'3145106244','indicativo_propietario'=>'57','documento_propietario'=>'1143415938','id_propietario'=>'P-1','valor_canon'=>'1000000']);
    // Terminado: no debe aparecer.
    $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>3,'contrato'=>'C-300','inmueble'=>'700','estado'=>'Terminado','documento_propietario'=>'1143415938','valor_canon'=>'900000']);
    return $db;
  }
}
