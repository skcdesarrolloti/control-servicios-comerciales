<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerLock;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\VisitorHasher;
use SCM\Core\Database;
use SCM\Core\Settings;

require_once __DIR__ . '/PrecaptacionDatabase.php';
require_once __DIR__ . '/CommercialTrackerDatabase.php';

/**
 * Base de Promoción (Analítica web y Actividad de portales) para PHPUnit y el fixture del navegador, en SQLite. Cargos:
 * 13 acceso total; 6 Promoción con promocion_ver_ip; 7 Promoción sin ver IP; 8 solo Actividad de portales;
 * 10 sin configurar (no recibe Promoción por ser opt-in).
 *
 * seed() llena tracker_logs, tracker_camps, sesiones y accesos_portal con fechas relativas a $today y arma los
 * resúmenes con el TrackerRollup real.
 */
final class CommercialPromotionDatabase
{
  public const SECRET = 'secreto-de-prueba-de-cuarenta-caracteres';
  /** IP de una visita y de un acceso: solo debe verse con promocion_ver_ip. */
  public const VISIBLE_IP = '181.50.1.1';
  public const ACCESS_IP = '181.60.7.7';
  public const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

  public static function create(string $dsn = 'sqlite::memory:'): Database
  {
    $db = PrecaptacionDatabase::create($dsn);
    $pdo = $db->pdo();
    // cargoOptions() (configuración de accesos) usa CONCAT de MySQL.
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map('strval', $parts)));
    CommercialTrackerDatabase::addTrackerTables($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_sesiones (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, fecha INTEGER, nombre TEXT, cantidad_sesiones INTEGER, usuario TEXT, tipo_actor TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_accesos_portal (_ID INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT, nombre TEXT, tipo_actor TEXT, proyecto TEXT, ruta TEXT, menu TEXT, metodo TEXT, ip TEXT, user_agent TEXT, fecha INTEGER, creado_en TEXT)');
    if (!$db->getVar("SELECT 1 FROM wp_jet_cct_funcionarios WHERE id_empleado = '303'")) {
      $db->insert('wp_jet_cct_funcionarios', ['_ID' => 3, 'id_empleado' => '303', 'nombre' => 'Asistente de promoción', 'activo' => 'Si', 'id_cargo' => '7']);
      foreach ([6 => 'Coordinación de promoción', 7 => 'Asistente de promoción', 8 => 'Servicio al cliente', 10 => 'Sin configurar', 13 => 'Gerencia'] as $id => $name) {
        $db->insert('wp_jet_cct_cargos', ['_ID' => $id, 'nombre_cargo' => $name]);
      }
    }
    return $db;
  }

  public static function settings(Database $db): Settings
  {
    $settings = new Settings($db);
    $permissions = $settings->get('commercial_permissions', []);
    if (!isset($permissions['7'])) {
      $settings->set('commercial_admin_cargos', ['13']);
      $settings->set('commercial_permissions', (is_array($permissions) ? $permissions : []) + [
        '6' => ['views' => ['promocion'], 'actions' => ['promocion_ver_ip']],
        '7' => ['views' => ['promocion', 'herramientas'], 'actions' => []],
        '8' => ['views' => ['promocion'], 'actions' => [], 'subviews' => ['promocion' => ['actividad_portales']]],
      ]);
    }
    return $settings;
  }

  /**
   * Lista de Medios instalada (tablas, lista inicial y glosario 195) y unas llamadas con valores para Configuración ›
   * Medios en el navegador. Las tablas se crean con el DDL de MedioSchema (install() solo corre en la línea de comandos).
   */
  public static function medios(Database $db, Settings $settings): void
  {
    if (\SCM\Commercial\Medios\MedioSchema::isReady($settings)) {
      return;
    }
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_post_types (id INTEGER PRIMARY KEY, slug TEXT, status TEXT, labels TEXT, args TEXT, meta_fields TEXT)');
    $glossary = array_map(static fn(string $name): array => ['value' => $name, 'label' => $name, 'is_checked' => false], array_slice(array_keys(\SCM\Commercial\Medios\MedioSeed::ROWS), 0, \SCM\Commercial\Medios\MedioSeed::GLOSSARY_COUNT));
    $pdo->prepare('INSERT OR REPLACE INTO wp_jet_post_types (id, slug, status, labels, args, meta_fields) VALUES (?, ?, ?, ?, ?, ?)')->execute([\SCM\Commercial\Medios\MedioCatalog::GLOSSARY_ID, '', 'glossary', '', '', serialize($glossary)]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_cct_llamadas (_ID INTEGER PRIMARY KEY AUTOINCREMENT, medio TEXT, cct_author_id TEXT)');
    foreach (['Finca Raíz', 'Finca Raíz', 'Referido', 'Retencion de contrato', 'Valor raro', 'Valor raro', 'Volante feria'] as $value) {
      $db->insert('wp_jet_cct_cct_llamadas', ['medio' => $value, 'cct_author_id' => '101']);
    }
    foreach (\SCM\Commercial\Medios\MedioSchema::tables($db) as $query) {
      $pdo->exec($query);
    }
    $settings->set(\SCM\Commercial\Medios\MedioSchema::SETTING, \SCM\Commercial\Medios\MedioSchema::VERSION);
    (new \SCM\Commercial\Medios\MedioCatalog($db, $settings, '101'))->seed();
  }

  /**
   * Material POP: las tablas de dashboard-marketing (InventorySchema) y las del Club PPH, con dos productos propios, dos
   * del Club PPH sin importar todavía y una recompensa PPH. Cargos: 13 acceso total; 6 gestiona y anula (sin costos);
   * 7 solo ve.
   */
  public static function pop(Database $db, Settings $settings): void
  {
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_marketing_inventory_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, type TEXT NOT NULL DEFAULT "Souvenir", sku TEXT UNIQUE, description TEXT, image_url TEXT, quantity INTEGER NOT NULL DEFAULT 0, unit TEXT NOT NULL DEFAULT "unidad", minimum_stock INTEGER NOT NULL DEFAULT 0, price NUMERIC, points INTEGER, active INTEGER NOT NULL DEFAULT 1, sync_pph INTEGER NOT NULL DEFAULT 0, pph_product_id INTEGER UNIQUE, pph_quantity INTEGER, pph_hash TEXT, pph_modified_at TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_marketing_inventory_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, delivered_at TEXT NOT NULL, recipient_name TEXT NOT NULL, recipient_type TEXT, recipient_id TEXT, recipient_source TEXT, recipient_document TEXT, recipient_metadata TEXT, notes TEXT, status TEXT NOT NULL DEFAULT "delivered", source TEXT NOT NULL DEFAULT "dashboard", external_id TEXT, pph_reward_id INTEGER UNIQUE, created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancellation_reason TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (source, external_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_marketing_inventory_delivery_items (id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_id INTEGER NOT NULL, product_id INTEGER, product_name TEXT NOT NULL, product_type TEXT, quantity INTEGER NOT NULL, unit_price NUMERIC, points INTEGER, pph_product_id INTEGER, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_marketing_inventory_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, delivery_id INTEGER, movement_type TEXT NOT NULL, quantity_delta INTEGER NOT NULL, stock_before INTEGER NOT NULL, stock_after INTEGER NOT NULL, reason TEXT NOT NULL, source TEXT NOT NULL DEFAULT "dashboard", created_by INTEGER, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_marketing_inventory_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_id INTEGER, movement_id INTEGER, product_id INTEGER, evidence_type TEXT NOT NULL, storage_path TEXT, external_url TEXT, original_name TEXT, mime_type TEXT, file_size INTEGER, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_inventario_pph (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, cantidad TEXT, fecha INTEGER, precio TEXT, valor_punto TEXT, titulo TEXT, img TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wp_jet_cct_recompensas_pph (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, fecha INTEGER, cantidad TEXT, id_pph TEXT, nombre TEXT, tarjeta_bienvenida TEXT, id_producto TEXT, producto TEXT, puntos TEXT)');
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_marketing_inventory_products') > 0) {
      return;
    }
    $now = '2026-10-01 09:00:00';
    $db->insert('wp_marketing_inventory_products', ['name' => 'Llaveros', 'type' => 'Souvenir', 'sku' => 'LLA-1', 'quantity' => 12, 'unit' => 'unidad', 'minimum_stock' => 10, 'price' => '2500.00', 'active' => 1, 'created_by' => 1443, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('wp_marketing_inventory_products', ['name' => 'Gorras', 'type' => 'merchandising', 'quantity' => 40, 'unit' => 'unidad', 'minimum_stock' => 0, 'price' => '18000.00', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    foreach ([[1, 'opening', 12], [2, 'opening', 40]] as [$product, $type, $quantity]) {
      $db->insert('wp_marketing_inventory_movements', ['product_id' => $product, 'movement_type' => $type, 'quantity_delta' => $quantity, 'stock_before' => 0, 'stock_after' => $quantity, 'reason' => 'Saldo inicial del producto.', 'source' => 'dashboard', 'created_by' => 1443, 'created_at' => $now]);
    }
    $db->insert('wp_jet_cct_inventario_pph', ['cct_status' => 'publish', 'cct_author_id' => 1, 'cct_created' => $now, 'cct_modified' => $now, 'cantidad' => '3', 'fecha' => 0, 'precio' => '40000', 'valor_punto' => '40', 'titulo' => 'CORTADORA DE PELO', 'img' => 'https://sucasainmobiliaria.com.co/wp-content/uploads/cortadora.jpg']);
    $db->insert('wp_jet_cct_inventario_pph', ['cct_status' => 'draft', 'cct_author_id' => 1, 'cct_created' => $now, 'cct_modified' => $now, 'cantidad' => '0', 'fecha' => 0, 'precio' => '', 'valor_punto' => '20', 'titulo' => 'LÁPIZ LABIAL', 'img' => '']);
    $db->insert('wp_jet_cct_recompensas_pph', ['cct_status' => 'publish', 'cct_author_id' => 1443, 'cct_created' => '2026-09-20 10:00:00', 'fecha' => (int) strtotime('2026-09-20 10:00:00'), 'cantidad' => '1', 'id_pph' => 'PPH-001', 'nombre' => 'Aliado Uno', 'tarjeta_bienvenida' => 'T-55', 'id_producto' => '1', 'producto' => 'CORTADORA DE PELO', 'puntos' => '40']);
    $db->insert('wp_jet_cct_funcionarios', ['_ID' => 20, 'id_empleado' => '505', 'nombre' => 'Coordinadora de mercadeo', 'activo' => 'Si', 'correo' => 'mercadeo@example.test', 'id_cargo' => '6']);
    $db->insert('wp_jet_cct_funcionarios', ['_ID' => 21, 'id_empleado' => '506', 'nombre' => 'Retirado', 'activo' => 'No', 'correo' => 'retirado@example.test', 'id_cargo' => '6']);
    $permissions = $settings->get('commercial_permissions', []);
    $permissions['6']['actions'] = array_values(array_unique(array_merge($permissions['6']['actions'] ?? [], ['pop_gestionar', 'pop_anular'])));
    $settings->set('commercial_permissions', $permissions);
  }

  /**
   * Redes sociales: las tablas que llena la sincronización de dashboard-marketing (marketing_social_*, marketing_youtube_*
   * y marketing_tiktok_*) con datos de Instagram hasta el 28/09/2026, publicidad de 2025 con fotos acumuladas repetidas
   * de una campaña, YouTube y TikTok (con un token falso que nunca debe salir en pantalla).
   */
  public static function social(Database $db): void
  {
    if ((int) $db->getVar("SELECT COUNT(*) FROM sqlite_master WHERE name = 'wp_marketing_social_accounts'") > 0) {
      return;
    }
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE wp_marketing_social_accounts (_ID INTEGER PRIMARY KEY, platform TEXT, account_name TEXT, account_id TEXT, username TEXT, connected INTEGER, last_sync TEXT)');
    $pdo->exec('CREATE TABLE wp_marketing_social_daily_stats (_ID INTEGER PRIMARY KEY, metric_date TEXT, platform TEXT, followers INTEGER, reach INTEGER, impressions INTEGER, profile_views INTEGER, website_clicks INTEGER, likes INTEGER, comments INTEGER, shares INTEGER, saves INTEGER)');
    $pdo->exec('CREATE TABLE wp_marketing_social_posts (_ID INTEGER PRIMARY KEY, post_date TEXT, platform TEXT, content_type TEXT, title TEXT, permalink TEXT, reach INTEGER, impressions INTEGER, likes INTEGER, comments INTEGER, shares INTEGER, saves INTEGER)');
    $pdo->exec('CREATE TABLE wp_marketing_social_ads_stats (_ID INTEGER PRIMARY KEY, period_start TEXT, metric_date TEXT, level TEXT, external_id TEXT, name TEXT, status TEXT, objective TEXT, spend REAL, impressions INTEGER, reach INTEGER, clicks INTEGER, link_clicks INTEGER, ctr REAL, cpc REAL, cpm REAL)');
    $pdo->exec('CREATE TABLE wp_marketing_social_audience (_ID INTEGER PRIMARY KEY, snapshot_date TEXT, platform TEXT, breakdown TEXT, label TEXT, value INTEGER)');
    $pdo->exec('CREATE TABLE wp_marketing_youtube_connections (_ID INTEGER PRIMARY KEY, channel_title TEXT, channel_handle TEXT, subscriber_count INTEGER, view_count INTEGER, video_count INTEGER, access_token_enc TEXT, connected INTEGER, last_sync TEXT, last_error TEXT)');
    $pdo->exec('CREATE TABLE wp_marketing_youtube_daily_stats (_ID INTEGER PRIMARY KEY, metric_date TEXT, views INTEGER, watch_minutes REAL, likes INTEGER, comments INTEGER, shares INTEGER, subscribers_gained INTEGER, subscribers_lost INTEGER)');
    $pdo->exec('CREATE TABLE wp_marketing_youtube_videos (_ID INTEGER PRIMARY KEY, title TEXT, published_at TEXT, permalink TEXT, content_type TEXT, views INTEGER, watch_minutes REAL, likes INTEGER, comments INTEGER)');
    $pdo->exec('CREATE TABLE wp_marketing_tiktok_connections (_ID INTEGER PRIMARY KEY, display_name TEXT, username TEXT, follower_count INTEGER, likes_count INTEGER, video_count INTEGER, access_token_enc TEXT, connected INTEGER, last_sync TEXT, last_error TEXT)');
    $pdo->exec('CREATE TABLE wp_marketing_tiktok_videos (_ID INTEGER PRIMARY KEY, title TEXT, published_at TEXT, permalink TEXT, views INTEGER, likes INTEGER, comments INTEGER, shares INTEGER)');
    $db->insert('wp_marketing_social_accounts', ['platform' => 'instagram', 'account_name' => 'SK&C Sucasa', 'username' => 'scinmobiliaria', 'connected' => 1, 'last_sync' => '2026-09-28 11:15:19']);
    // Instagram: datos hasta el 28 de septiembre (la sincronización va atrasada).
    foreach (['2026-09-26' => [1700, 100], '2026-09-27' => [1720, 200], '2026-09-28' => [1751, 300], '2026-05-01' => [1500, 999]] as $day => [$followers, $reach]) {
      $db->insert('wp_marketing_social_daily_stats', ['metric_date' => $day, 'platform' => 'instagram', 'followers' => $followers, 'reach' => $reach, 'impressions' => 10, 'profile_views' => 5, 'website_clicks' => 2, 'likes' => 3, 'comments' => 1, 'shares' => 0, 'saves' => 1]);
    }
    $db->insert('wp_marketing_social_posts', ['post_date' => '2026-09-27', 'platform' => 'instagram', 'content_type' => 'REEL', 'title' => 'Apartamento <b>en</b> Laureles', 'permalink' => 'https://instagram.com/p/1', 'reach' => 500, 'likes' => 20, 'comments' => 2, 'shares' => 1, 'saves' => 3]);
    $db->insert('wp_marketing_social_posts', ['post_date' => '2026-09-26', 'platform' => 'instagram', 'title' => 'Enlace raro', 'permalink' => 'javascript:alert(1)', 'reach' => 50, 'likes' => 1, 'comments' => 0, 'shares' => 0, 'saves' => 0]);
    foreach ([['age', '25-34', 400], ['age', '35-44', 300], ['gender', 'F', 600], ['city', 'Medellín', 900]] as [$breakdown, $label, $value]) {
      $db->insert('wp_marketing_social_audience', ['snapshot_date' => '2026-09-28', 'platform' => 'instagram', 'breakdown' => $breakdown, 'label' => $label, 'value' => $value]);
    }
    $db->insert('wp_marketing_social_audience', ['snapshot_date' => '2026-08-01', 'platform' => 'instagram', 'breakdown' => 'age', 'label' => 'vieja', 'value' => 9999]);
    // Publicidad: actividad diaria en 2025 y fotos acumuladas repetidas de cada campaña.
    foreach (['2025-08-09' => [9135, 2244, 33], '2025-08-10' => [6254, 1500, 23]] as $day => [$spend, $impressions, $clicks]) {
      $db->insert('wp_marketing_social_ads_stats', ['period_start' => $day, 'metric_date' => $day, 'level' => 'account_daily', 'name' => 'SKC', 'spend' => $spend, 'impressions' => $impressions, 'reach' => $impressions - 100, 'clicks' => $clicks, 'link_clicks' => $clicks]);
    }
    foreach ([['2026-07-11', '2026-07-11', 100], ['2025-01-01', '2026-08-06', 9527], ['2026-08-06', '2026-08-06', 50]] as [$since, $date, $impressions]) {
      $db->insert('wp_marketing_social_ads_stats', ['period_start' => $since, 'metric_date' => $date, 'level' => 'campaign', 'external_id' => 'C1', 'name' => '20 años construyendo', 'status' => 'ACTIVE', 'objective' => 'ENGAGEMENT', 'spend' => 44867, 'impressions' => $impressions, 'clicks' => 202, 'ctr' => 2.12, 'cpc' => 222.1, 'cpm' => 4709.6]);
    }
    $db->insert('wp_marketing_youtube_connections', ['channel_title' => 'SKC SuCasa', 'channel_handle' => '@skcsucasa', 'subscriber_count' => 320, 'view_count' => 50000, 'video_count' => 90, 'access_token_enc' => 'SECRETO-YT', 'connected' => 1, 'last_sync' => '2026-08-05 22:00:00']);
    foreach (['2026-08-04' => 100, '2026-08-05' => 150] as $day => $views) {
      $db->insert('wp_marketing_youtube_daily_stats', ['metric_date' => $day, 'views' => $views, 'watch_minutes' => 120.5, 'likes' => 5, 'comments' => 1, 'shares' => 0, 'subscribers_gained' => 3, 'subscribers_lost' => 1]);
    }
    $db->insert('wp_marketing_youtube_videos', ['title' => 'Recorrido casa', 'published_at' => '2026-08-01 10:00:00', 'permalink' => 'https://youtube.com/watch?v=1', 'content_type' => 'short', 'views' => 900, 'watch_minutes' => 40, 'likes' => 9, 'comments' => 1]);
    $db->insert('wp_marketing_tiktok_connections', ['display_name' => 'SKC', 'username' => 'skc', 'follower_count' => 80, 'likes_count' => 500, 'video_count' => 37, 'access_token_enc' => 'SECRETO-TT', 'connected' => 1, 'last_sync' => '2026-09-23 08:00:00', 'last_error' => 'Token vencido']);
    $db->insert('wp_marketing_tiktok_videos', ['title' => 'Tip de arriendo', 'published_at' => '2026-09-20 09:00:00', 'permalink' => 'https://tiktok.com/@skc/video/1', 'views' => 700, 'likes' => 30, 'comments' => 4, 'shares' => 2]);
  }

  /** @param array<string,string> $session */
  public static function login(string $cargo, string $employee, string $name = 'Funcionario de prueba', array $session = []): void
  {
    $_SESSION = $session + ['scm_user_id' => 7, 'scm_employee_id' => $employee, 'scm_user_cargo' => $cargo, 'scm_user' => $name, 'scm_logged_in' => true];
  }

  public static function rollup(Database $db, Settings $settings, string $today, ?string $lockFile = null): TrackerRollup
  {
    return new TrackerRollup($db, $settings, new VisitorHasher(self::SECRET), new TrackerLock($db, $lockFile), static fn(): string => $today,
      // Reloj al final del día: las visitas de muestra de hoy ya cuentan como asentadas (TrackerRollup::SETTLE_SECONDS).
      static fn(): int => (int) strtotime($today . " 23:59:59") + TrackerRollup::SETTLE_SECONDS + 1);
  }

  /** Datos de muestra de los últimos 35 días (y algunos más viejos), con los resúmenes ya armados. */
  public static function seed(Database $db, Settings $settings, string $today, ?string $lockFile = null): void
  {
    if ((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tracker_logs') > 0) {
      return;
    }
    TrackerAnalyticsConfig::save($settings, ['internal_ips' => "10.0.0.0/8\n", 'own_domains' => 'sucasainmobiliaria.com.co', 'exclude_bots' => '1', 'exclude_internal' => '1'], 7, $today);
    $db->insert('wp_jet_cct_tracker_camps', ['slug' => 'promo-octubre', 'nombre' => 'Promoción de octubre', 'activo' => 'Si']);
    $db->insert('wp_jet_cct_tracker_camps', ['slug' => 'feria-vivienda', 'nombre' => 'Feria de vivienda', 'activo' => 'Si']);
    $campaigns = ['promo-octubre', 'feria-vivienda', ''];
    $channels = [['Facebook', 'Redes'], ['Instagram', 'Redes'], ['Google', 'cpc'], ['', '']];
    $pages = ['https://sucasainmobiliaria.com.co/', 'https://sucasainmobiliaria.com.co/inmueble/apto-101?fbclid=abc', 'https://www.sucasainmobiliaria.com.co/inmueble/casa-202?utm_source=fb', 'https://sucasainmobiliaria.com.co/arriendos'];
    $referrers = ['https://www.facebook.com/post/1', 'https://www.google.com/', '', 'https://sucasainmobiliaria.com.co/arriendos'];
    $places = [['Colombia', 'Bogotá'], ['Colombia', 'Medellín'], ['Estados Unidos', 'Miami']];
    $n = 0;
    for ($back = 34; $back >= 0; $back--) {
      $day = (new \DateTimeImmutable($today))->modify('-' . $back . ' days')->format('Y-m-d');
      for ($i = 0; $i < 6; $i++, $n++) {
        $page = $pages[$n % 4];
        $object = str_contains($page, 'apto-101') ? ['101', 'INM-101'] : (str_contains($page, 'casa-202') ? ['202', 'INM-202'] : ['0', '']);
        [$source, $medium] = $channels[$n % 4];
        [$country, $city] = $places[$n % 3];
        $ip = $n % 11 === 0 ? self::VISIBLE_IP : '181.50.' . ($n % 40) . '.' . (10 + $back);
        CommercialTrackerDatabase::hit($db, [
          'camp_slug' => $campaigns[$n % 3], 'utm_source' => $source, 'utm_medium' => $medium, 'page_url' => $page, 'referrer' => $referrers[$n % 4],
          'fecha_visita' => $day . sprintf(' %02d:%02d:00', 8 + $i * 2, $n % 60), 'country' => $country, 'city' => $city,
          'object_id' => $object[0], 'object_ref' => $object[1], 'user_id' => $n % 5 === 0 ? '12' : '0',
          // Una de cada 9 visitas es de un robot y una de cada 13 sale de la oficina.
          'user_agent' => $n % 9 === 4 ? 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' : self::BROWSER,
          'ip_address' => $n % 13 === 6 ? '10.0.0.5' : $ip,
        ]);
      }
    }
    self::rollup($db, $settings, $today, $lockFile)->run();

    $at = static fn(int $back, string $time): string => (new \DateTimeImmutable($today))->modify('-' . $back . ' days')->format('Y-m-d') . ' ' . $time;
    $sessions = [
      ['Club PPH', 'PPH-001', 'Aliado Uno', 14, 2], ['Cliente para arriendo', '1001', 'Laura Gómez', 9, 5], ['cliente para venta', '1002', 'Pedro Ruiz', 4, 10],
      ['Quiero vender mi inmueble', '1003', 'Marta Díaz', 3, 20], ['', '1004', 'Sin tipo', 2, 3], ['Club PPH', 'PPH-OLD', 'Aliado Antiguo', 40, 80],
    ];
    foreach ($sessions as [$type, $user, $name, $count, $back]) {
      $db->insert('wp_jet_cct_sesiones', ['fecha' => (int) strtotime($at($back, '00:00:00')), 'tipo_actor' => $type, 'usuario' => $user, 'nombre' => $name, 'cantidad_sesiones' => $count]);
    }
    $actors = [['Club PPH', 'club-pph', 'PPH-001', 'Aliado Uno'], ['Cliente para arriendo', 'cliente', '1001', 'Laura Gómez'], ['cliente para venta', 'cliente', '1002', 'Pedro Ruiz'], ['', 'cliente', '1004', 'Sin tipo']];
    $menus = ['club-pph' => [['Inicio', '/'], ['Puntos', '/puntos']], 'cliente' => [['Inicio', '/'], ['Estado de cuenta', '/estado-cuenta'], ['Documentos', '/documentos']]];
    for ($k = 0; $k < 60; $k++) {
      [$type, $project, $user, $name] = $actors[$k % 4];
      [$menu, $route] = $menus[$project][$k % count($menus[$project])];
      $created = $at($k % 28, sprintf('%02d:%02d:00', 7 + $k % 12, $k % 60));
      $db->insert('wp_jet_cct_accesos_portal', ['usuario' => $user, 'nombre' => $name, 'tipo_actor' => $type, 'proyecto' => $project, 'ruta' => $route, 'menu' => $menu, 'metodo' => 'GET',
        'ip' => $k === 0 ? self::ACCESS_IP : '181.60.0.' . $k, 'user_agent' => self::BROWSER, 'fecha' => (int) strtotime($created), 'creado_en' => $created]);
    }
    // Fuera del rango por defecto: no deben contar.
    $old = $at(45, '10:00:00');
    $db->insert('wp_jet_cct_accesos_portal', ['usuario' => 'PPH-OLD', 'nombre' => 'Aliado Antiguo', 'tipo_actor' => 'Club PPH', 'proyecto' => 'club-pph', 'ruta' => '/viejo', 'menu' => 'Menú antiguo', 'metodo' => 'GET',
      'ip' => '181.60.9.9', 'user_agent' => self::BROWSER, 'fecha' => (int) strtotime($old), 'creado_en' => $old]);
  }
}
