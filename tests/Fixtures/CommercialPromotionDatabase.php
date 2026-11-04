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
