<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;
use SCM\Core\Database;
use SCM\Core\Settings;

final class CommercialNotificationsFixture
{
  /** Base en memoria: estas pruebas nunca usan la cola ni los contactos reales. */
  public static function make(bool $admin = false): array
  {
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
    $pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1);
    $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value);
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
    $tables = [
      'wp_jet_cct_confi_sistema' => '_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT',
      'wp_jet_cct_funcionarios' => '_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, rol TEXT, id_cargo TEXT, celular TEXT, correo TEXT, activo TEXT',
      'wp_jet_cct_cargos' => '_ID INTEGER PRIMARY KEY, nombre_cargo TEXT',
      'wp_jet_cct_propietarios' => '_ID INTEGER PRIMARY KEY, id_propietario TEXT, nombre TEXT, correo TEXT, celular TEXT, indicativo TEXT, cct_author_id TEXT, bloqueo_whatsapp INTEGER, permite_marketing_whatsapp INTEGER',
      'wp_jet_cct_arrendatarios' => '_ID INTEGER PRIMARY KEY, id_arrendatario TEXT, nombre TEXT, correo TEXT, celular TEXT, indicativo TEXT, cct_author_id TEXT',
      'wp_jet_cct_copropiedades' => '_ID INTEGER PRIMARY KEY, copropiedad TEXT, correo TEXT, contacto TEXT, indicativo TEXT, cct_author_id TEXT',
      'wp_jet_cct_club_pph' => '_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, telefono TEXT, indicativo TEXT, cct_author_id TEXT, id_empleado TEXT',
      'wp_jet_cct_inmuebles' => '_ID INTEGER PRIMARY KEY, id_funcionario TEXT, id_propietario TEXT, id_arrendatario TEXT, id_copropiedad TEXT',
      'wp_jet_cct_contratos_arrendamiento' => '_ID INTEGER PRIMARY KEY, id_empleado TEXT, id_propietario TEXT, id_arrendatario TEXT, id_copropiedad TEXT, estado TEXT',
      'skc_notification_queue' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, project_code TEXT, source_module TEXT, channel TEXT, provider TEXT, destination TEXT, destination_name TEXT, subject TEXT, message_text TEXT, message_html TEXT, template_name TEXT, template_language TEXT, payload_json TEXT, meta_json TEXT, status TEXT, priority INTEGER, max_attempts INTEGER, scheduled_at TEXT, created_at TEXT, updated_at TEXT, created_by TEXT, dedupe_key TEXT, attempts INTEGER DEFAULT 0, sent_at TEXT, last_error TEXT',
    ];
    foreach ($tables as $table => $definition) {
      $pdo->exec("CREATE TABLE `{$table}` ({$definition})");
      $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
      foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
        $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
      }
    }
    $pdo->exec("INSERT INTO wp_jet_cct_funcionarios VALUES (1,'900','Ana Pérez','Consultora','9','3001234567','ana@example.test','Si'),(2,'901','Otro usuario','Consultor','9','3007654321','otro@example.test','Si'),(3,'999','Administrador','Administrador','11','3005555555','admin@example.test','Si')");
    $pdo->exec("INSERT INTO wp_jet_cct_cargos VALUES (9,'Consultora de Arriendo'),(11,'Administrador')");
    $pdo->exec("INSERT INTO wp_jet_cct_inmuebles VALUES (1,'900','2011','2020','30')");
    $pdo->exec("INSERT INTO wp_jet_cct_contratos_arrendamiento VALUES
      (1,'900','2012','2021','31','Recibido'),
      (2,'901','2010','2022','','Entregado'),
      (3,'901','2011','2020','','Recibido'),
      (4,'901','2013','2023','','Entregado'),
      (5,'901','2014','','','Entregado'),
      (6,'901','2015','','','Entregado'),
      (7,'901','2010','2020','','Entregado')");
    $db = new Database($pdo);
    foreach ([10 => '900', 11 => '901', 12 => '901', 13 => '901', 14 => '900', 15 => '900'] as $id => $author) {
      $db->insert('wp_jet_cct_propietarios', ['_ID' => $id, 'id_propietario' => '20' . $id, 'nombre' => 'Propietario ' . $id, 'correo' => 'persona' . $id . '@example.test', 'celular' => '3001234567', 'indicativo' => '57', 'cct_author_id' => $author, 'bloqueo_whatsapp' => $id === 14 ? 1 : 0, 'permite_marketing_whatsapp' => $id === 15 ? 0 : 1]);
    }
    $db->update('wp_jet_cct_propietarios', ['correo' => ''], ['_ID' => 14]);
    $db->update('wp_jet_cct_propietarios', ['celular' => ''], ['_ID' => 15]);
    foreach ([20 => '901', 21 => '901', 22 => '900', 23 => '901'] as $id => $author) {
      $db->insert('wp_jet_cct_arrendatarios', ['_ID' => $id, 'id_arrendatario' => '20' . $id, 'nombre' => 'Arrendatario ' . $id, 'cct_author_id' => $author]);
    }
    foreach ([30 => '901', 31 => '901', 32 => '900', 33 => '901'] as $id => $author) {
      $db->insert('wp_jet_cct_copropiedades', ['_ID' => $id, 'copropiedad' => 'Copropiedad ' . $id, 'cct_author_id' => $author]);
    }
    foreach ([40 => ['900','901'], 41 => ['901','900'], 42 => ['901','901']] as $id => $values) {
      $db->insert('wp_jet_cct_club_pph', ['_ID' => $id, 'nombre' => 'Club PPH ' . $id, 'id_empleado' => $values[0], 'cct_author_id' => $values[1]]);
    }
    $_SESSION = ['scm_logged_in' => true, 'scm_user_id' => $admin ? 3 : 1, 'scm_employee_id' => $admin ? '999' : '900', 'scm_user' => $admin ? 'Administrador' : 'Ana Pérez', 'scm_user_cargo' => $admin ? '11' : '9', 'scm_user_rol' => 'Consultora'];
    $settings = new Settings($db, true, 'control_servicios_comerciales_config');
    $policy = new CommercialAccessPolicy($settings, $db, ['11']);
    return [$db, $policy, new CommercialNotificationsService($db, $policy)];
  }
}
