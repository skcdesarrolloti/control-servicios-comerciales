<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Medios\MedioCatalog;
use SCM\Commercial\Medios\MedioResolver;
use SCM\Commercial\Medios\MedioSchema;
use SCM\Commercial\Medios\MedioSeed;
use SCM\Commercial\Medios\MediosNormalizeCommand;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsFilters;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsRepository;
use SCM\Commercial\Promotion\Analytics\TrackerLock;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\TrackerRollupCommand;
use SCM\Commercial\Promotion\Analytics\TrackerSchema;
use SCM\Commercial\Promotion\Analytics\VisitorHasher;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\Promotion\AnalyticsView;
use Tests\Fixtures\CommercialMediosDatabase;
use Tests\Fixtures\CommercialTrackerDatabase;

/** Lista única de Medios: tabla propia, glosario de JetEngine, registros, Generador UTM y analítica web. */
final class CommercialMediosTest extends TestCase
{
  private const TODAY = '2026-11-03';

  private Database $db;
  private Settings $settings;
  private array $previousSession;
  private string $lockFile;

  protected function setUp(): void
  {
    require_once dirname(__DIR__) . '/Fixtures/CommercialMediosDatabase.php';
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->previousSession = $_SESSION ?? [];
    $_SESSION = ['scm_user_cargo' => '13', 'scm_user_id' => 7, 'scm_employee_id' => '1007'];
    $this->db = CommercialMediosDatabase::create();
    $this->settings = new Settings($this->db);
    $this->lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scm-medios-test-' . bin2hex(random_bytes(6)) . '.lock';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
    if (is_file($this->lockFile)) {
      @unlink($this->lockFile);
    }
  }

  private function installed(): MedioCatalog
  {
    MedioSchema::install($this->db, $this->settings);
    $catalog = new MedioCatalog($this->db, $this->settings, '1007');
    $catalog->seed();
    $catalog->forget();
    return $catalog;
  }

  private function idOf(MedioCatalog $catalog, string $name): int
  {
    foreach ($catalog->all() as $row) {
      if ($row['nombre'] === $name) {
        return $row['id'];
      }
    }
    self::fail('No existe el medio ' . $name);
  }

  private function logCount(): int
  {
    return (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_medios_log');
  }

  public function testResolverMatchesNamesSlugsAndAliasesIgnoringCaseAndAccents(): void
  {
    $resolver = new MedioResolver(MedioSeed::rows());
    foreach ([
      'finca raiz' => 'Finca Raíz', 'FINCA-RAIZ' => 'Finca Raíz', 'finca_raiz' => 'Finca Raíz', 'Estado WhatsApp' => 'Estado whatsapp',
      'Retencion de contrato' => 'Retencion de contrato', 'Retención de contrato' => 'Retencion de contrato', 'Retencion' => 'Retencion', 'Ruta' => 'Por ruta', 'por_ruta' => 'Por ruta',
      'ig' => 'Instagram', 'Portal Propietario' => 'Portal propietario', 'Historia Instagram' => 'Historia instagram', ' Referido ' => 'Referido',
      'Correo Electrónico' => 'Correo', 'Email' => 'Correo', 'chatgpt.com' => 'ChatGPT / IA', 'chatgpt_ia' => 'ChatGPT / IA',
    ] as $raw => $name) {
      self::assertSame($name, $resolver->resolve($raw), $raw);
    }
    self::assertNull($resolver->resolve('Base de Datos Clientes'));
    self::assertNull($resolver->resolve(''));
    self::assertSame('Valor raro', $resolver->canonical(' Valor raro '));
    self::assertSame('finca_raiz', MedioResolver::slug('Finca Raíz'));
    self::assertSame(MedioSeed::GLOSSARY_COUNT, array_search('Portal arrendatario', array_keys(MedioSeed::ROWS), true));

    // Visitas: primero utm_source, luego utm_medium (canal de las notificaciones); sin UTM, "Sin medio (directo)".
    foreach ([
      [['Directo', 'Navegador'], [MedioResolver::DIRECT, true]],
      [['Inmuebles', 'Pagina Web'], [MedioResolver::DIRECT, true]],
      [['', ''], [MedioResolver::DIRECT, true]],
      [['Directo', 'Sin medio'], [MedioResolver::DIRECT, true]],
      [['Notificaciones pagina web', 'Email'], ['Correo', true]],
      [['Instagram', 'Publicidad Pagada (Ads)'], ['Instagram', true]],
      [['Base de Datos Clientes', 'Correo Electrónico'], ['Correo', true]],
      [['Base de Datos Propietarios', 'SMS'], ['SMS', true]],
      [['WhatsApp Clientes', 'WhatsApp'], ['WhatsApp', true]],
      [['Estado WhatsApp', 'WhatsApp'], ['Estado whatsapp', true]],
      [['chatgpt.com', ''], ['ChatGPT / IA', true]],
      [['perplexity', ''], ['ChatGPT / IA', true]],
      [['ig', 'social'], ['Instagram', true]],
      [['fb', 'paid'], ['Facebook', true]],
      // Lo que sale del Generador UTM: el mismo slug en los dos parámetros.
      [['por_ruta', 'por_ruta'], ['Por ruta', true]],
      // Sin reconocer: se ve con su texto, nunca se esconde.
      [['Base de Datos Propietarios', ''], ['Base de Datos Propietarios', false]],
      [['Cbff ef Ebgbf Cebcvfgbevbf', 'Dbeefb Fyfdgeavdb'], ['Cbff ef Ebgbf Cebcvfgbevbf', false]],
      [['Directo', 'Volante'], ['Volante', false]],
    ] as [[$source, $medium], $expected]) {
      self::assertSame($expected, $resolver->forVisit($source, $medium), $source . ' / ' . $medium);
    }
  }

  public function testInstallSeedsTheListAndSyncsTheGlossaryOnlyFromTheCommandLine(): void
  {
    $catalog = new MedioCatalog($this->db, $this->settings, '1007');
    self::assertFalse($catalog->ready());
    // Sin la tabla se resuelve con la lista inicial y no se puede editar.
    self::assertCount(count(MedioSeed::ROWS), $catalog->all());
    self::assertSame('Por ruta', $catalog->canonical('Ruta'));
    try {
      $catalog->add('Volante');
      self::fail('Sin instalar no se puede editar.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('medios-normalize.php --apply', $exception->getMessage());
    }

    $catalog = $this->installed();
    self::assertTrue($catalog->ready());
    self::assertSame(MedioSchema::VERSION, $this->settings->get(MedioSchema::SETTING));
    self::assertSame(0, $catalog->seed(), 'La lista solo se siembra una vez.');
    self::assertSame(array_keys(MedioSeed::ROWS), array_column($catalog->all(), 'nombre'));
    self::assertSame([], $catalog->all()[27]['aliases'], 'Retencion y Retencion de contrato son medios distintos.');
    // El glosario queda con exactamente los medios activos, en el formato de JetEngine.
    self::assertSame(array_keys(MedioSeed::ROWS), CommercialMediosDatabase::glossary($this->db));
    $items = unserialize((string) $this->db->getVar('SELECT meta_fields FROM wp_jet_post_types WHERE id = 195'), ['allowed_classes' => false]);
    self::assertSame(['value' => 'Aviso', 'label' => 'Aviso', 'is_checked' => false], $items[0]);
    self::assertSame(['Ruta'], CommercialMediosDatabase::glossary($this->db, 185), 'Los demás glosarios no se tocan.');
    $log = $this->db->getRow('SELECT * FROM wp_scm_medios_log ORDER BY id DESC LIMIT 1');
    self::assertSame('seed', $log['action']);
    self::assertSame('1007', $log['id_empleado']);
    self::assertTrue(json_decode((string) $log['detail'], true)['glossary']);
    // Instalar no cambia ningún registro.
    self::assertSame(CommercialMediosDatabase::RECORDS, CommercialMediosDatabase::values($this->db));
  }

  public function testRenameAndMergeRewriteEveryTableWithBackupLogAndGlossary(): void
  {
    $catalog = $this->installed();
    $counts = $catalog->rename($this->idOf($catalog, 'Por ruta'), 'Ruta comercial');
    self::assertSame(1, $counts['jet_cct_cct_llamadas']);
    self::assertSame(1, array_sum($counts), 'Solo cambian las coincidencias exactas del nombre anterior.');
    $values = CommercialMediosDatabase::values($this->db);
    self::assertSame('Ruta comercial', $values['jet_cct_cct_llamadas'][4]);
    self::assertSame('Ruta', $values['jet_cct_tickets'][3]);
    $renamed = $catalog->all()[14];
    self::assertSame(['Ruta comercial', 'ruta_comercial'], [$renamed['nombre'], $renamed['slug']]);
    self::assertSame(['Ruta', 'Por ruta'], $renamed['aliases']);
    self::assertSame('Ruta comercial', $catalog->canonical('Por ruta'));
    self::assertSame('Ruta comercial', $catalog->canonical('por_ruta'), 'Los enlaces UTM ya publicados siguen contando.');
    $glossary = CommercialMediosDatabase::glossary($this->db);
    self::assertContains('Ruta comercial', $glossary);
    self::assertNotContains('Por ruta', $glossary);
    $log = $this->db->getRow("SELECT * FROM wp_scm_medios_log WHERE action = 'rename'");
    self::assertSame(['Por ruta', 'Ruta comercial'], [$log['from_value'], $log['to_value']]);
    self::assertSame(1, json_decode((string) $log['detail'], true)['records']['jet_cct_cct_llamadas']);
    self::assertSame([['table_name' => 'jet_cct_cct_llamadas', 'row_id' => 5, 'column_name' => 'medio', 'old_value' => 'Por ruta', 'new_value' => 'Ruta comercial']],
      array_map(static fn(array $row): array => ['table_name' => $row['table_name'], 'row_id' => (int) $row['row_id'], 'column_name' => $row['column_name'], 'old_value' => $row['old_value'], 'new_value' => $row['new_value']],
        $this->db->getResults('SELECT * FROM wp_scm_medios_backup WHERE log_id = ?', [$log['id']])));

    // Unir: los registros pasan al destino y el origen queda como alias (sale de la lista y del glosario).
    $total = count($catalog->all());
    $counts = $catalog->merge($this->idOf($catalog, 'Panel administrativo'), $this->idOf($catalog, 'Otro'));
    self::assertSame(1, $counts['jet_cct_tickets']);
    self::assertSame('Otro', CommercialMediosDatabase::values($this->db)['jet_cct_tickets'][7]);
    self::assertCount($total - 1, $catalog->all());
    self::assertSame('Otro', $catalog->canonical('Panel administrativo'));
    self::assertSame(['Panel administrativo'], $this->aliasesOf($catalog, 'Otro'));
    self::assertNotContains('Panel administrativo', CommercialMediosDatabase::glossary($this->db));
    // El autor de los registros no cambia.
    self::assertSame(['55'], array_values(array_unique(array_map('strval', $this->db->getCol('SELECT cct_author_id FROM wp_jet_cct_tickets')))));
    try {
      $catalog->merge($this->idOf($catalog, 'Otro'), $this->idOf($catalog, 'Otro'));
      self::fail('No se une un medio consigo mismo.');
    } catch (\InvalidArgumentException) {
    }
  }

  /** @return list<string> */
  private function aliasesOf(MedioCatalog $catalog, string $name): array
  {
    foreach ($catalog->all() as $row) {
      if ($row['nombre'] === $name) {
        return $row['aliases'];
      }
    }
    return [];
  }

  public function testDeactivateAddAndAliasesAreValidatedAndRollBackOnError(): void
  {
    $catalog = $this->installed();
    $catalog->setActive($this->idOf($catalog, 'Prensa'), false);
    self::assertNotContains('Prensa', CommercialMediosDatabase::glossary($this->db));
    self::assertNotContains('prensa', array_column($catalog->utmOptions(), 'value'));
    self::assertSame('Prensa', $catalog->canonical('prensa'), 'Inactivo, pero se sigue reconociendo.');
    $catalog->setActive($this->idOf($catalog, 'Prensa'), true);
    self::assertContains('Prensa', CommercialMediosDatabase::glossary($this->db));

    $added = $catalog->add('  Volante  ');
    self::assertSame('Volante', $added['nombre']);
    self::assertSame('Volante', CommercialMediosDatabase::glossary($this->db)[count(MedioSeed::ROWS)]);
    self::assertSame(['Flyer', 'volantes'], $catalog->setAliases($added['id'], "Flyer\nvolantes\n\nFLYER"));
    self::assertSame('Volante', $catalog->canonical('flyer'));

    $glossary = CommercialMediosDatabase::glossary($this->db);
    $logs = $this->logCount();
    foreach ([
      'nombre repetido (otra forma)' => fn() => $catalog->add('instagram'),
      'alias de otro medio' => fn() => $catalog->add('IG'),
      'valor del tracker' => fn() => $catalog->add('Directo'),
      'sin letras' => fn() => $catalog->add('¡¡!!'),
      'muy largo' => fn() => $catalog->add(str_repeat('a', MedioCatalog::MAX_NAME + 1)),
      'alias ajeno' => fn() => $catalog->setAliases($added['id'], ['ig']),
      'alias del tracker' => fn() => $catalog->setAliases($added['id'], ['Navegador']),
      'renombrar a uno existente' => fn() => $catalog->rename($added['id'], 'Aviso'),
      'medio inexistente' => fn() => $catalog->rename(999, 'Nuevo'),
    ] as $case => $operation) {
      try {
        $operation();
        self::fail('Debía rechazar: ' . $case);
      } catch (\InvalidArgumentException) {
      }
    }
    self::assertSame($glossary, CommercialMediosDatabase::glossary($this->db));
    self::assertSame($logs, $this->logCount(), 'Nada rechazado queda en el historial.');
  }

  public function testUnresolvedValuesCanBeAliasedOrRewritten(): void
  {
    $catalog = $this->installed();
    CommercialTrackerDatabase::hit($this->db, ['utm_source' => 'Base de Datos Propietarios', 'utm_medium' => '']);
    CommercialTrackerDatabase::hit($this->db, ['utm_source' => 'ig', 'utm_medium' => 'social']);
    $unresolved = $catalog->unresolved();
    self::assertSame([['value' => 'Valor raro', 'total' => 2, 'tables' => ['jet_cct_tickets' => 1, 'jet_cct_estudios_aseguradoras' => 1]]], $unresolved['records']);
    $variants = array_column($unresolved['variants'], null, 'value');
    self::assertArrayNotHasKey('Retencion de contrato', $variants, 'Retencion de contrato es un medio propio: no se reescribe.');
    self::assertSame('Por ruta', $variants['Ruta']['medio']);
    self::assertSame('Portal propietario', $variants['Portal Propietario']['medio']);
    self::assertSame(['Base de Datos Propietarios'], array_column($unresolved['visits'], 'value'));
    self::assertSame(['jet_cct_cct_llamadas' => 0, 'jet_cct_tickets' => 1, 'jet_cct_cierres' => 0, 'jet_cct_contratos_arrendamiento' => 0, 'jet_cct_estudios_aseguradoras' => 1,
      'jet_cct_solicitudes_terminacion_contrato' => 0, 'jet_cct_solicitudes_no_prorroga_contrato' => 0], $catalog->preview('Valor raro'));

    // Alias: se reconoce sin cambiar registros.
    $catalog->aliasRaw('Base de Datos Propietarios', $this->idOf($catalog, 'Correo'));
    self::assertSame([], $catalog->unresolved()['visits']);
    $before = CommercialMediosDatabase::values($this->db);
    $catalog->aliasRaw('Valor raro', $this->idOf($catalog, 'Otro'));
    self::assertSame($before, CommercialMediosDatabase::values($this->db));
    self::assertSame([], $catalog->unresolved()['records']);

    // Reescribir: los registros pasan al medio, con respaldo.
    $counts = $catalog->rewriteRaw('Valor raro', $this->idOf($catalog, 'Otro'));
    self::assertSame(2, array_sum($counts));
    self::assertSame('Otro', CommercialMediosDatabase::values($this->db)['jet_cct_estudios_aseguradoras'][1]);
    self::assertSame(2, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_scm_medios_backup b JOIN wp_scm_medios_log l ON l.id = b.log_id WHERE l.action = 'rewrite'"));
    try {
      $catalog->rewriteRaw('Ruta', $this->idOf($catalog, 'Aviso'));
      self::fail('"Ruta" ya es de Por ruta.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Por ruta', $exception->getMessage());
    }
  }

  /** @return array<string,mixed> Todo lo que la vista previa no debe tocar. */
  private function snapshot(): array
  {
    return [
      'records' => CommercialMediosDatabase::values($this->db),
      'tracker' => $this->db->getResults('SELECT _ID, utm_source, utm_medium FROM wp_jet_cct_tracker_logs ORDER BY _ID'),
      'glossary' => $this->db->getResults('SELECT id, meta_fields FROM wp_jet_post_types ORDER BY id'),
      'config' => $this->db->getResults('SELECT * FROM wp_jet_cct_confi_sistema ORDER BY _ID'),
      'tables' => $this->db->getCol("SELECT name FROM sqlite_master WHERE type IN ('table', 'index') ORDER BY name"),
    ];
  }

  /** @return array{0:int,1:string} */
  private function normalize(array $argv, bool $writable): array
  {
    $out = fopen('php://memory', 'w+');
    self::assertIsResource($out);
    $settings = new Settings($this->db, !$writable);
    $code = (new MediosNormalizeCommand($this->db, $settings, $out, 'cli'))->run($argv);
    rewind($out);
    return [$code, (string) stream_get_contents($out)];
  }

  public function testNormalizeDryRunWritesNothingAndApplyRewritesWithBackupAndRevert(): void
  {
    foreach ([['ig', 'social'], ['Estado WhatsApp', 'WhatsApp'], ['Base de Datos Clientes', 'Correo Electrónico'], ['Directo', 'Navegador'], ['chatgpt.com', ''], ['Inmuebles', 'Pagina Web']] as [$source, $medium]) {
      CommercialTrackerDatabase::hit($this->db, ['utm_source' => $source, 'utm_medium' => $medium]);
    }
    $this->settings->set('otro_valor', ['x' => 1]);
    self::assertFalse(MediosNormalizeCommand::writes([]));
    self::assertFalse(MediosNormalizeCommand::writes(['--dry-run']));
    self::assertTrue(MediosNormalizeCommand::writes(['--apply']));
    self::assertTrue(MediosNormalizeCommand::writes(['--revert=3']));

    $before = $this->snapshot();
    foreach ([[], ['--dry-run']] as $argv) {
      [$code, $output] = $this->normalize($argv, false);
      self::assertSame(0, $code, $output);
      self::assertSame($before, $this->snapshot(), 'La vista previa no escribe nada.');
    }
    self::assertStringContainsString('No se escribe nada en la base.', $output);
    self::assertStringContainsString('la tabla wp_scm_medios no existe todavía', $output);
    self::assertStringContainsString('Se agregan: Portal arrendatario, Portal propietario, Portal copropiedad, Panel administrativo, Retencion de contrato, WhatsApp cliente, WhatsApp Guardian, Otro, SMS, WhatsApp, ChatGPT / IA.', $output);
    self::assertStringContainsString("  wp_jet_cct_tickets.medio\n    \"Portal Propietario\" -> \"Portal propietario\": 1 filas\n    \"Ruta\" -> \"Por ruta\": 1 filas", $output);
    self::assertStringContainsString("  wp_jet_cct_tracker_logs.utm_source\n    \"Estado WhatsApp\" -> \"Estado whatsapp\": 1 filas\n    \"chatgpt.com\" -> \"ChatGPT / IA\": 1 filas\n    \"ig\" -> \"Instagram\": 1 filas", $output);
    self::assertStringContainsString("  wp_jet_cct_tracker_logs.utm_medium\n    \"Correo Electrónico\" -> \"Correo\": 1 filas", $output);
    self::assertStringContainsString('Total: 3 filas en registros y 4 en visitas web.', $output);
    self::assertStringContainsString('registros "Valor raro": 2', $output);
    self::assertStringNotContainsString('"Directo"', $output);

    [$code, $output] = $this->normalize(['--apply'], true);
    self::assertSame(0, $code, $output);
    $values = CommercialMediosDatabase::values($this->db);
    self::assertSame(['Referido', 'Retencion de contrato', 'Retencion de contrato', 'Por ruta', 'Portal arrendatario', 'Portal propietario', 'Retencion', 'Panel administrativo', 'Valor raro', ''], $values['jet_cct_tickets']);
    self::assertSame(['WhatsApp Guardian', 'Por ruta'], $values['jet_cct_solicitudes_no_prorroga_contrato']);
    self::assertSame(CommercialMediosDatabase::RECORDS['jet_cct_cct_llamadas'], $values['jet_cct_cct_llamadas'], 'Vacíos, NULL y nombres exactos no cambian.');
    self::assertSame([['Instagram', 'social'], ['Estado whatsapp', 'WhatsApp'], ['Base de Datos Clientes', 'Correo'], ['Directo', 'Navegador'], ['ChatGPT / IA', ''], ['Inmuebles', 'Pagina Web']],
      array_map(static fn(array $row): array => [$row['utm_source'], $row['utm_medium']], $this->db->getResults('SELECT utm_source, utm_medium FROM wp_jet_cct_tracker_logs ORDER BY _ID')));
    self::assertSame(array_keys(MedioSeed::ROWS), CommercialMediosDatabase::glossary($this->db));
    $logId = (int) $this->db->getVar("SELECT id FROM wp_scm_medios_log WHERE action = 'normalize'");
    self::assertSame(7, (int) $this->db->getVar('SELECT COUNT(*) FROM wp_scm_medios_backup WHERE log_id = ?', [$logId]));
    self::assertStringContainsString('--revert=' . $logId, $output);

    [, $again] = $this->normalize(['--apply'], true);
    self::assertStringContainsString('No hay valores por normalizar.', $again);

    // Revertir devuelve cada fila a su valor anterior (solo las que siguen con el valor que les puso ese paso).
    $this->db->update('wp_jet_cct_tickets', ['medio' => 'Aviso'], ['_ID' => 4]);
    [$code, $output] = $this->normalize(['--revert=' . $logId], true);
    self::assertSame(0, $code, $output);
    $values = CommercialMediosDatabase::values($this->db);
    self::assertSame(['Referido', 'Retencion de contrato', 'Retencion de contrato', 'Aviso', 'Portal arrendatario', 'Portal Propietario', 'Retencion', 'Panel administrativo', 'Valor raro', ''], $values['jet_cct_tickets']);
    self::assertSame('ig', $this->db->getVar('SELECT utm_source FROM wp_jet_cct_tracker_logs WHERE _ID = 1'));
    self::assertStringContainsString('1 omitidas', $output);

    self::assertSame(2, $this->normalize(['--apply', '--dry-run'], true)[0]);
    self::assertSame(2, $this->normalize(['--borrar'], true)[0]);
  }

  private function rollup(): TrackerRollup
  {
    $now = (int) strtotime(self::TODAY . ' 12:00:00');
    return new TrackerRollup($this->db, $this->settings, new VisitorHasher(str_repeat('s', 40)), new TrackerLock($this->db, $this->lockFile), static fn(): string => self::TODAY, static fn(): int => $now);
  }

  private function filters(array $input = []): TrackerAnalyticsFilters
  {
    return TrackerAnalyticsFilters::fromInput($input + ['from' => '2026-10-01', 'to' => '2026-10-31', 'exclude_bots' => '0', 'exclude_internal' => '0'], ['exclude_bots' => true, 'exclude_internal' => true], self::TODAY);
  }

  /** @return array<string,int> */
  private function medios(TrackerAnalyticsRepository $repository, array $input = []): array
  {
    $out = array_column($repository->top('medios', $this->filters($input)), 'hits', 'value');
    ksort($out, SORT_STRING);
    return $out;
  }

  public function testRollupStoresTheMedioAndReclassifiesWhenTheListChanges(): void
  {
    foreach ([['ig', 'social', '1.1.1.1'], ['ig', 'paid', '1.1.1.2'], ['Base de Datos Clientes', 'SMS', '1.1.1.3'], ['Directo', 'Navegador', '1.1.1.4'], ['Volante X', '', '1.1.1.5']] as [$source, $medium, $ip]) {
      CommercialTrackerDatabase::hit($this->db, ['utm_source' => $source, 'utm_medium' => $medium, 'ip_address' => $ip]);
    }
    $rollup = $this->rollup();
    $rollup->run();
    $repository = new TrackerAnalyticsRepository($this->db, $this->settings);
    self::assertSame(['Instagram' => 2, 'SMS' => 1, 'Sin medio (directo)' => 1, 'Volante X' => 1], $this->medios($repository));
    $rows = array_column($repository->top('medios', $this->filters()), null, 'value');
    self::assertSame('Sin reconocer · se ajusta en Configuración › Medios', $rows['Volante X']['detail']);
    self::assertSame('', $rows['Instagram']['detail']);
    self::assertSame(2, $rows['Instagram']['unique_visitors']);
    self::assertSame(2, $repository->kpis($this->filters(['medio' => 'Instagram']))['visits']);
    self::assertSame(['Instagram', 'SMS', 'Sin medio (directo)', 'Volante X'], $repository->options(self::TODAY)['medios']);

    // Registros: el medio resuelto junto a utm_source / utm_medium crudos, y el filtro por medio.
    $logs = $repository->logs($this->filters(['medio' => 'Instagram']));
    self::assertSame([['Instagram', 'ig', 'paid'], ['Instagram', 'ig', 'social']], array_map(static fn(array $row): array => [$row['medio'], $row['source'], $row['medium']], $logs['rows']));

    // Un cambio en la lista (alias) deja pendiente la reclasificación; el cron la aplica sin leer la tabla cruda.
    $catalog = $this->installed();
    self::assertTrue($rollup->mediosPending());
    $catalog->aliasRaw('Volante X', $this->idOf($catalog, 'Publicidad'));
    $result = $rollup->remapMedios();
    self::assertTrue($result['ran']);
    self::assertSame(1, $result['changed']);
    self::assertSame(['Instagram' => 2, 'Publicidad' => 1, 'SMS' => 1, 'Sin medio (directo)' => 1], $this->medios($repository));
    self::assertNotContains('Volante X', $this->db->getCol('SELECT DISTINCT medio FROM wp_scm_tracker_daily_visitors'));
    self::assertNotContains('Volante X', $this->db->getCol('SELECT DISTINCT medio FROM wp_scm_tracker_daily_top'));
    self::assertFalse($rollup->mediosPending());
    self::assertFalse($rollup->remapMedios()['ran']);

    // Recalcular desde una fecha da lo mismo, y las visitas nuevas ya llegan con su medio.
    $rollup->rebuildFrom('2026-10-01');
    self::assertSame(['Instagram' => 2, 'Publicidad' => 1, 'SMS' => 1, 'Sin medio (directo)' => 1], $this->medios($repository));
    CommercialTrackerDatabase::hit($this->db, ['utm_source' => 'instagram', 'utm_medium' => 'instagram', 'ip_address' => '1.1.1.9', 'fecha_visita' => '2026-10-02 09:00:00']);
    $rollup->run();
    self::assertSame(3, $this->medios($repository)['Instagram']);

    // El cron reclasifica solo si la lista cambió.
    $catalog->rename($this->idOf($catalog, 'Publicidad'), 'Publicidad pagada');
    $out = fopen('php://memory', 'w+');
    self::assertIsResource($out);
    self::assertSame(0, (new TrackerRollupCommand($rollup, $out))->run([]));
    rewind($out);
    self::assertStringContainsString('Medios: 1 de', (string) stream_get_contents($out));
    self::assertSame(1, $this->medios($repository)['Publicidad pagada']);
  }

  public function testVersion2SummariesShowNotReadyUntilMigratedFromTheCommandLine(): void
  {
    $pdo = $this->db->pdo();
    // Resúmenes de la versión 2 (sin la columna medio), como los que ya existen en producción.
    $dims = 'camp_slug TEXT NOT NULL, utm_source TEXT NOT NULL, utm_medium TEXT NOT NULL';
    $flags = 'bot INTEGER NOT NULL DEFAULT 0, internal INTEGER NOT NULL DEFAULT 0';
    $pdo->exec("CREATE TABLE wp_scm_tracker_daily (fecha TEXT NOT NULL, {$dims}, registered INTEGER NOT NULL DEFAULT 0, {$flags}, hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, camp_slug, utm_source, utm_medium, registered, bot, internal))");
    $pdo->exec("CREATE TABLE wp_scm_tracker_daily_top (fecha TEXT NOT NULL, dim TEXT NOT NULL, {$dims}, value_hash TEXT NOT NULL, {$flags}, value TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (dim, fecha, camp_slug, utm_source, utm_medium, value_hash, bot, internal))");
    $pdo->exec("CREATE TABLE wp_scm_tracker_daily_visitors (fecha TEXT NOT NULL, visitor_hash TEXT NOT NULL, {$dims}, {$flags}, hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (fecha, visitor_hash, camp_slug, utm_source, utm_medium, bot, internal))");
    $pdo->exec("CREATE TABLE wp_scm_tracker_state (id INTEGER NOT NULL PRIMARY KEY, last_id INTEGER NOT NULL DEFAULT 0, last_fecha TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '', purged_before TEXT NOT NULL DEFAULT '', last_purge TEXT NOT NULL DEFAULT '', rebuild TEXT NOT NULL DEFAULT '')");
    $pdo->exec("INSERT INTO wp_scm_tracker_state (id, last_id) VALUES (1, 1)");
    $pdo->exec("INSERT INTO wp_scm_tracker_daily VALUES ('2026-10-01', 'general', 'ig', 'social', 0, 0, 0, 3)");
    $pdo->exec("INSERT INTO wp_scm_tracker_daily_visitors VALUES ('2026-10-01', 'abc', 'general', 'ig', 'social', 0, 0, 3)");
    $this->settings->set(TrackerSchema::SETTING, 2);

    self::assertTrue(TrackerSchema::needsMigration($this->settings));
    $repository = new TrackerAnalyticsRepository($this->db, $this->settings);
    self::assertFalse($repository->ready());
    self::assertSame([], $repository->top('medios', $this->filters()));
    $policy = new CommercialAccessPolicy($this->settings, $this->db, ['13']);
    $context = AnalyticsView::context(['pr_desde' => '2026-10-01', 'pr_hasta' => '2026-10-31'], $policy, $this->db, $this->settings, $this->rollup(), self::TODAY);
    self::assertSame('migrate', AnalyticsView::coverage($context));
    self::assertStringContainsString('data-cpr-sync="migrate"', AnalyticsView::render($context, '/public'));

    // php bin/tracker-rollup.php: agrega las columnas y clasifica lo resumido.
    $out = fopen('php://memory', 'w+');
    self::assertIsResource($out);
    self::assertSame(0, (new TrackerRollupCommand($this->rollup(), $out))->run([]));
    self::assertSame(TrackerSchema::VERSION, $this->settings->get(TrackerSchema::SETTING));
    self::assertFalse(TrackerSchema::needsMigration($this->settings));
    self::assertSame(['Instagram' => 3], $this->medios($repository));
    self::assertSame(['Instagram'], $this->db->getCol('SELECT DISTINCT medio FROM wp_scm_tracker_daily_visitors'));
  }

  private function service(string $cargo): CommercialSettingsService
  {
    $_SESSION['scm_user_cargo'] = $cargo;
    return new CommercialSettingsService($this->db, $this->settings, new CommercialAccessPolicy($this->settings, $this->db, ['13']), []);
  }

  public function testSettingsSectionIsForAdministratorsAndValidatesEveryOperation(): void
  {
    self::assertContains('medios', CommercialSettingsService::SECTIONS);
    // Sin instalar: se ve la lista inicial, pero no se puede cambiar.
    $notReady = $this->service('13')->loadMedios();
    self::assertFalse($notReady['ready']);
    self::assertSame('Aviso', $notReady['medios'][0]['nombre']);
    try {
      $this->service('13')->save('medios', ['op' => 'add', 'nombre' => 'Volante']);
      self::fail('Sin instalar no se puede agregar.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('medios-normalize.php --apply', $exception->getMessage());
    }

    $this->installed();
    foreach (['loadMedios' => fn(CommercialSettingsService $service) => $service->loadMedios(), 'save' => fn(CommercialSettingsService $service) => $service->save('medios', ['op' => 'add', 'nombre' => 'Volante'])] as $name => $call) {
      try {
        $call($this->service('9'));
        self::fail('Un cargo no administrador no puede usar ' . $name);
      } catch (\RuntimeException $exception) {
        self::assertNotInstanceOf(\InvalidArgumentException::class, $exception);
      }
    }
    self::assertNotContains('Volante', CommercialMediosDatabase::glossary($this->db));

    $service = $this->service('13');
    $detail = $service->loadMedios();
    self::assertTrue($detail['ready']);
    self::assertSame(3, $detail['usage']['Referido']);
    self::assertSame(3, $detail['usage']['Aviso'], 'Aviso: llamadas 1, cierres 1, contratos 1.');
    self::assertSame('Valor raro', $detail['unresolved']['records'][0]['value']);
    self::assertSame('seed', $detail['history'][0]['action']);
    self::assertSame(MedioResolver::DIRECT, $detail['direct']);
    self::assertFalse($detail['analytics_pending']);

    self::assertSame('', $service->save('medios', ['op' => 'preview', 'value' => 'Retencion de contrato']));
    self::assertSame(3, $service->lastResult()['total']);
    self::assertStringContainsString('agregado', $service->save('medios', ['op' => 'add', 'nombre' => 'Volante']));
    self::assertContains('Volante', CommercialMediosDatabase::glossary($this->db));
    self::assertSame('1007', $this->db->getVar("SELECT id_empleado FROM wp_scm_medios_log WHERE action = 'add'"));
    self::assertSame('1007', $this->db->getVar("SELECT updated_by FROM wp_scm_medios WHERE nombre = 'Volante'"));
    $catalog = new MedioCatalog($this->db, $this->settings);
    $id = $this->idOf($catalog, 'Volante');
    self::assertStringContainsString('Alias guardados (1)', $service->save('medios', ['op' => 'aliases', 'id' => $id, 'aliases' => ['Flyer']]));
    self::assertStringContainsString('desactivado', $service->save('medios', ['op' => 'toggle', 'id' => $id, 'active' => 0]));
    self::assertStringContainsString('3 registros', $service->save('medios', ['op' => 'merge', 'id' => $this->idOf($catalog, 'Retencion de contrato'), 'target' => $this->idOf($catalog, 'Cesion')]));
    foreach ([['op' => 'borrar'], ['op' => 'rename', 'id' => $id, 'nombre' => ''], ['op' => 'merge', 'id' => $id, 'target' => $id], ['op' => 'alias_raw', 'value' => '', 'target' => $id], ['op' => 'add', 'nombre' => 'Sin medio (directo)']] as $data) {
      try {
        $service->save('medios', $data);
        self::fail('Debía rechazar ' . json_encode($data));
      } catch (\InvalidArgumentException) {
      }
    }
  }
}
