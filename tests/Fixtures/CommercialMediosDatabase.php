<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Commercial\Medios\MedioCatalog;
use SCM\Commercial\Medios\MedioSeed;
use SCM\Core\Database;

/**
 * Base SQLite con lo que toca la lista de Medios: las siete tablas de registros con su columna `medio`, el glosario
 * "Medio de llamada" de JetEngine (wp_jet_post_types id 195, serializado como lo guarda JetEngine) y las tablas del tracker.
 */
final class CommercialMediosDatabase
{
  /** Valores de los registros (tabla => lista de medios), parecidos a los de producción. */
  public const RECORDS = [
    'jet_cct_cct_llamadas' => ['Finca Raíz', 'Finca Raíz', 'Aviso', 'Referido', 'Por ruta', null, '', 'Otro', 'Estado whatsapp'],
    'jet_cct_tickets' => ['Referido', 'Retencion de contrato', 'Retencion de contrato', 'Ruta', 'Portal arrendatario', 'Portal Propietario', 'Retencion', 'Panel administrativo', 'Valor raro', ''],
    'jet_cct_cierres' => ['Referido', 'Aviso', null],
    'jet_cct_contratos_arrendamiento' => ['Aviso', 'Retencion de contrato', ''],
    'jet_cct_estudios_aseguradoras' => ['Finca Raíz', 'Valor raro'],
    'jet_cct_solicitudes_terminacion_contrato' => ['WhatsApp Guardian'],
    'jet_cct_solicitudes_no_prorroga_contrato' => ['WhatsApp Guardian', 'Ruta'],
  ];

  public static function create(): Database
  {
    require_once __DIR__ . '/CommercialTrackerDatabase.php';
    $db = CommercialTrackerDatabase::create();
    $pdo = $db->pdo();
    foreach (self::RECORDS as $table => $values) {
      $pdo->exec('CREATE TABLE wp_' . $table . ' (_ID INTEGER PRIMARY KEY AUTOINCREMENT, medio TEXT, cct_author_id TEXT)');
      foreach ($values as $value) {
        $db->insert('wp_' . $table, ['medio' => $value, 'cct_author_id' => '55']);
      }
    }
    $pdo->exec('CREATE TABLE wp_jet_post_types (id INTEGER PRIMARY KEY, slug TEXT, status TEXT, labels TEXT, args TEXT, meta_fields TEXT)');
    $glossary = array_map(static fn(string $name): array => ['value' => $name, 'label' => $name, 'is_checked' => false], array_slice(array_keys(MedioSeed::ROWS), 0, MedioSeed::GLOSSARY_COUNT));
    $db->insert('wp_jet_post_types', ['id' => MedioCatalog::GLOSSARY_ID, 'slug' => '', 'status' => 'glossary', 'labels' => 'a:1:{s:4:"name";s:16:"Medio de llamada";}', 'args' => '', 'meta_fields' => serialize($glossary)]);
    // Otro glosario que no se debe tocar.
    $db->insert('wp_jet_post_types', ['id' => 185, 'slug' => '', 'status' => 'glossary', 'labels' => '', 'args' => '', 'meta_fields' => serialize([['value' => 'Ruta', 'label' => 'Ruta', 'is_checked' => true]])]);
    return $db;
  }

  /** @return array<string,list<string|null>> Valores actuales por tabla, en orden de _ID. */
  public static function values(Database $db): array
  {
    $out = [];
    foreach (array_keys(self::RECORDS) as $table) {
      $out[$table] = array_map(static fn($value): ?string => $value === null ? null : (string) $value, $db->getCol('SELECT medio FROM wp_' . $table . ' ORDER BY _ID'));
    }
    return $out;
  }

  /** @return list<string> */
  public static function glossary(Database $db, int $id = MedioCatalog::GLOSSARY_ID): array
  {
    $items = unserialize((string) $db->getVar('SELECT meta_fields FROM wp_jet_post_types WHERE id = ?', [$id]), ['allowed_classes' => false]);
    return array_map(static fn(array $item): string => (string) $item['value'], is_array($items) ? $items : []);
  }
}
