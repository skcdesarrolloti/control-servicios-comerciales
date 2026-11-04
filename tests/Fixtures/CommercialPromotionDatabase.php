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
