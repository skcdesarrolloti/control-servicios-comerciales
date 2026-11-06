<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\Campaigns\CampaignEngine;
use SCM\Commercial\Campaigns\CampaignRecipients;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;

require_once __DIR__ . '/CommercialNotificationsFixture.php';

/**
 * Notificaciones › Campañas sobre la base en memoria de CommercialNotificationsFixture (con su information_schema
 * simulado), más las listas que agregan las campañas, las tablas jet_cct_campaign_*, jet_cct_email_tracking, las
 * plantillas y las columnas gda_* de la cola. Cargo 11 acceso total (id_empleado 999); 9 sin permisos de campañas (900).
 *
 * Clientes: 100 ok, 101 bloqueó el correo, 102 no acepta información por correo, 103 sin correo, 104 mismo correo que
 * 100, 105 con <b> en el nombre. Suscriptores: 200 suscrita, 201 se dio de baja, 202 suscrito sin columnas permite_*.
 */
final class CampaignFixture
{
  public const NOW = '2026-11-07 18:40:00'; // sábado, 18:40 en Bogotá

  /** @return array{0:Database,1:CommercialAccessPolicy,2:CampaignEngine} */
  public static function make(bool $admin = true): array
  {
    [$db, $policy] = CommercialNotificationsFixture::make($admin);
    self::extend($db);
    return [$db, $policy, self::engine($db, $policy)];
  }

  public static function engine(Database $db, CommercialAccessPolicy $policy, string $now = self::NOW): CampaignEngine
  {
    return new CampaignEngine($db, new CampaignRecipients($db, $policy), 'https://panel.test/public', static fn(): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('America/Bogota')));
  }

  public static function extend(Database $db): void
  {
    $permits = 'bloqueo_email INTEGER, bloqueo_sms INTEGER, permite_info_email INTEGER, permite_info_sms INTEGER, permite_promo_email INTEGER, permite_marketing_whatsapp INTEGER';
    $tables = [
      'wp_jet_cct_clientes' => "_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, celular TEXT, indicativo TEXT, tipo_cliente TEXT, ciudad TEXT, cct_status TEXT, {$permits}",
      'wp_jet_cct_newsletter' => '_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, celular TEXT, ciudad TEXT, suscrito TEXT, cct_status TEXT',
      'wp_jet_cct_codeudores' => "_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, celular TEXT, cct_status TEXT, {$permits}",
      'wp_jet_cct_contactos' => "_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, celular TEXT, tipo_contacto TEXT, cct_status TEXT, {$permits}",
      'wp_jet_cct_campaign_config' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, campaign_tag TEXT, batch_size INTEGER, batch_interval_minutes INTEGER, max_per_day INTEGER, auto_start INTEGER, status TEXT, id_funcionario INTEGER, cct_created TEXT, cct_modified TEXT, config_key TEXT, config_value TEXT, created_at TEXT, updated_at TEXT',
      'wp_jet_cct_campaign_batches' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, campaign_tag TEXT, batch_number INTEGER, actor_ids TEXT, status TEXT, batch_size INTEGER, total_actors INTEGER, sent_count INTEGER, failed_count INTEGER, channel TEXT, id_funcionario INTEGER, cct_created TEXT, cct_modified TEXT',
      'wp_jet_cct_campaign_exclusions' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, campaign_tag TEXT, id_actor INTEGER, tipo_actor TEXT, reason TEXT, created_by INTEGER, created_at TEXT',
      'wp_jet_cct_email_tracking' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, tracking_token TEXT, queue_id INTEGER, id_actor INTEGER, id_funcionario INTEGER, tipo_actor TEXT, campaign_tag TEXT, destination TEXT, subject TEXT, opened_at TEXT, last_opened_at TEXT, open_count INTEGER, last_ip TEXT, last_user_agent TEXT, created_at TEXT',
      'wp_jet_cct_plantillas' => '_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, nombre TEXT, tipo TEXT, asunto TEXT, contenido TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT',
    ];
    $pdo = $db->pdo();
    // La configuración de accesos del encabezado (cargoOptions) usa CONCAT de MySQL.
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    foreach ($tables as $table => $definition) {
      $pdo->exec("CREATE TABLE `{$table}` ({$definition})");
      self::register($db, $table);
    }
    foreach (["gda_campaign_tag TEXT GENERATED ALWAYS AS (json_extract(meta_json, '$.gda.campaign_tag')) VIRTUAL", "gda_tipo_actor TEXT GENERATED ALWAYS AS (json_extract(meta_json, '$.gda.tipo_actor')) VIRTUAL", "gda_id_actor INTEGER GENERATED ALWAYS AS (json_extract(meta_json, '$.gda.id_actor')) VIRTUAL", "gda_id_funcionario INTEGER GENERATED ALWAYS AS (json_extract(meta_json, '$.gda.id_funcionario')) VIRTUAL", 'next_attempt_at TEXT', 'locked_at TEXT', 'locked_by TEXT'] as $column) {
      $pdo->exec("ALTER TABLE skc_notification_queue ADD COLUMN {$column}");
    }
    self::register($db, 'skc_notification_queue');
    $ok = ['bloqueo_email' => 0, 'bloqueo_sms' => 0, 'permite_info_email' => 1, 'permite_info_sms' => 1, 'permite_promo_email' => 1, 'permite_marketing_whatsapp' => 1];
    $clients = [
      100 => ['Laura Gómez', 'laura@example.test', '3001110000', []],
      101 => ['Bloqueó correo', 'bloqueo@example.test', '3001110001', ['bloqueo_email' => 1]],
      102 => ['Sin info', 'sininfo@example.test', '3001110002', ['permite_info_email' => 0]],
      103 => ['Sin correo', '', '3001110003', []],
      104 => ['Laura repetida', 'LAURA@example.test', '3001110004', []],
      105 => ['Pedro <b>Ruiz</b>', 'pedro@example.test', '3001110005', []],
    ];
    foreach ($clients as $id => [$name, $email, $phone, $override]) {
      $db->insert('wp_jet_cct_clientes', ['_ID' => $id, 'nombre' => $name, 'correo' => $email, 'celular' => $phone, 'indicativo' => '57', 'tipo_cliente' => 'Arriendo', 'ciudad' => 'Medellín', 'cct_status' => 'publish'] + $override + $ok);
    }
    $db->insert('wp_jet_cct_clientes', ['_ID' => 106, 'nombre' => 'En papelera', 'correo' => 'papelera@example.test', 'cct_status' => 'trash'] + $ok);
    foreach ([200 => ['Suscrita', 'Si'], 201 => ['Se dio de baja', 'No'], 202 => ['Suscrito dos', 'Si']] as $id => [$name, $subscribed]) {
      $db->insert('wp_jet_cct_newsletter', ['_ID' => $id, 'nombre' => $name, 'correo' => 'news' . $id . '@example.test', 'celular' => '30022200' . ($id - 200), 'ciudad' => 'Bogotá', 'suscrito' => $subscribed, 'cct_status' => 'publish']);
    }
    $db->insert('wp_jet_cct_plantillas', ['nombre' => 'Revista Esencia', 'tipo' => 'email', 'cct_status' => 'publish', 'asunto' => 'Nueva edición para {{nombre}}',
      'contenido' => '<!doctype html><html><body><h1>Hola {{nombre}}</h1><p>{{mensaje}}</p><a href="https://sucasainmobiliaria.com.co/revista?x=1#inicio">Leer</a><a href="https://otro.test/">Otro</a><script>alert(1)</script></body></html>']);
    $db->insert('wp_jet_cct_plantillas', ['nombre' => 'WhatsApp Marketing - Texto', 'tipo' => 'whatsapp', 'cct_status' => 'publish', 'asunto' => 'skc_marketing_texto_generico_v1', 'contenido' => '']);
  }

  /** Registra la tabla y sus columnas en el information_schema simulado. */
  private static function register(Database $db, string $table): void
  {
    $pdo = $db->pdo();
    $pdo->prepare('DELETE FROM information_schema.COLUMNS WHERE TABLE_NAME = ?')->execute([$table]);
    if (!$db->getVar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME = ?', [$table])) {
      $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
    }
    foreach ($pdo->query("PRAGMA table_xinfo(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
      $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
    }
  }
}
