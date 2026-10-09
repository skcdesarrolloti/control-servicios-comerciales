<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;
use SCM\Core\Database;
use SCM\Core\Settings;

final class CommercialNotificationsFixture
{
  /** Historial ficticio para revisar vista previa, permisos e informes sin envíos reales. */
  public static function makeWithAudit(bool $admin = false): array
  {
    [$db, $policy, $service] = self::make();
    self::addActorRelations($db);
    $service->prepareDelivery(md5('audit-ui'), []);
    $service->enqueue('propietarios_activos', [10], ['email', 'sms', 'whatsapp'], 'Información del inmueble', 'Mensaje de prueba para el informe', 'scm_marketing_generica_texto_v1');
    foreach ([1 => 'sent', 2 => 'pending', 3 => 'failed'] as $id => $status) {
      $db->update('skc_notification_queue', ['status' => $status, 'created_at' => '2026-10-08 17:30:00', 'sent_at' => $status === 'sent' ? '2026-10-08 17:31:00' : null], ['id' => $id]);
    }
    $row = $db->getRow('SELECT * FROM skc_notification_queue WHERE id = 3');
    unset($row['id']);
    $db->insert('skc_notification_queue', array_replace($row, ['status' => 'processing', 'dedupe_key' => 'processing-fixture']));
    $row = $db->getRow('SELECT * FROM skc_notification_queue WHERE id = 1');
    unset($row['id']);
    $db->insert('skc_notification_queue', array_replace($row, ['dedupe_key' => 'other-user-fixture', 'meta_json' => '{"commercial_notifications":{"employee_id":"901","nombre_funcionario":"Otro usuario","cargo":"Consultor","user_id":2}}']));
    $db->insert('skc_notification_queue', array_replace($row, ['dedupe_key' => 'legacy-fixture', 'meta_json' => '{}']));
    $db->insert('skc_notification_queue', array_replace($row, ['dedupe_key' => 'other-module-fixture', 'source_module' => 'otro_modulo']));
    $db->insert('skc_notification_queue', array_replace($row, ['dedupe_key' => 'other-project-fixture', 'project_code' => 'otro_proyecto']));
    if ($admin) {
      $_SESSION['scm_user_id'] = 3;
      $_SESSION['scm_employee_id'] = '999';
      $_SESSION['scm_user_cargo'] = '11';
      $_SESSION['scm_user'] = 'Administrador';
    }
    return [$db, $policy, new CommercialNotificationsService($db, $policy)];
  }

  public static function makeForActorEditing(bool $admin = true): array
  {
    [$db, $policy] = self::make($admin);
    self::addActorRelations($db);
    return [$db, $policy, new \SCM\Commercial\CommercialActorEditor($db, $policy)];
  }

  private static function addActorRelations(Database $db): void
  {
    $tables = [
      'wp_jet_cct_propietarios' => ['documento', 'nombre_juridico', 'documento_juridico', 'tipo_persona', 'cct_modified'],
      'wp_jet_cct_arrendatarios' => ['documento', 'nombre_juridico', 'documento_juridico', 'tipo_persona', 'cct_modified'],
      'wp_jet_cct_copropiedades' => ['nit', 'cct_modified'],
      'wp_jet_cct_club_pph' => ['documento', 'indicativo', 'cct_modified'],
      'wp_jet_cct_inmuebles' => ['propietario', 'arrendatario', 'copropiedad', 'codigo', 'cct_modified'],
      'wp_jet_cct_contratos_arrendamiento' => ['cct_modified', 'contrato', 'propietario', 'documento_propietario', 'correo_propietario', 'celular_propietario', 'indicativo_propietario', 'arrendatario', 'documento_arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'indicativo_arrendatario', 'copropiedad', 'nit_copropiedad', 'correo_copropiedad', 'celular_copropiedad', 'id_pph', 'nombre_pph'],
      'wp_jet_cct_contrato_mandato' => ['cct_modified', 'id_propietario', 'id_copropiedad', 'propietarios', 'estado', 'copropiedad', 'nit_co', 'correo_co', 'contacto_co'],
      'wp_jet_cct_cierres' => ['cct_modified', 'id_propietario', 'id_arrendatario', 'id_copropiedad', 'id_pph', 'propietario', 'documento_propietario', 'correo_propietario', 'celular_propietario', 'arrendatario', 'documento_arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'copropiedad', 'nit_copropiedad', 'correo_copropiedad', 'celular_copropiedad', 'nombre_pph', 'estado'],
    ];
    for ($slot = 1; $slot <= 6; $slot++) {
      foreach (['id_propietario_nuevo_', 'nombre_', 'documento_', 'correo_', 'celular_', 'indicativo_', 'tipo_', 'empresa_', 'nit_'] as $prefix) { $tables['wp_jet_cct_contrato_mandato'][] = $prefix . $slot; }
    }
    foreach ($tables as $table => $columns) {
      $exists = $db->getVar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME = ?', [$table]);
      if (!$exists) {
        $db->pdo()->exec("CREATE TABLE `{$table}` (_ID INTEGER PRIMARY KEY)");
        $db->pdo()->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
        $db->pdo()->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, '_ID']);
      }
      $existing = array_column($db->getResults("PRAGMA table_info(`{$table}`)"), 'name');
      foreach ($columns as $column) {
        if (in_array($column, $existing, true)) { continue; }
        $db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` TEXT");
        $db->pdo()->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column]);
      }
    }
    $db->update('wp_jet_cct_propietarios', ['documento' => '123456', 'cct_modified' => '2026-10-08 10:00:00'], ['_ID' => 10]);
    $db->update('wp_jet_cct_propietarios', ['documento' => '654321'], ['_ID' => 13]);
    $db->insert('wp_jet_cct_inmuebles', ['_ID' => 2, 'id_propietario' => '10', 'id_arrendatario' => '22', 'id_copropiedad' => '32', 'propietario' => 'Propietario original', 'codigo' => 'SIMI-100']);
    foreach ([2, 7] as $id) { $db->update('wp_jet_cct_contratos_arrendamiento', ['propietario' => 'Propietario original', 'documento_propietario' => '123456', 'correo_propietario' => 'original@example.test', 'contrato' => 'C-' . $id], ['_ID' => $id]); }
    $db->update('wp_jet_cct_contratos_arrendamiento', ['id_pph' => '40', 'nombre_pph' => 'Club PPH original'], ['_ID' => 7]);
    $db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 1, 'id_propietario' => '10', 'propietarios' => '2', 'id_propietario_nuevo_1' => '10', 'nombre_1' => 'Propietario original', 'documento_1' => '123456', 'id_propietario_nuevo_2' => '13', 'nombre_2' => 'Otro titular', 'documento_2' => '654321', 'id_copropiedad' => '32', 'estado' => 'Vigente']);
    $db->insert('wp_jet_cct_cierres', ['_ID' => 1, 'id_propietario' => '2010', 'propietario' => 'Propietario original', 'documento_propietario' => '123456', 'id_arrendatario' => '22', 'id_copropiedad' => '32', 'id_pph' => '40', 'nombre_pph' => 'Club PPH original', 'estado' => 'Cerrado']);
  }

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
