<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Commercial\Medios\MedioCatalog;
use SCM\Commercial\Medios\MedioResolver;
use SCM\Commercial\Promotion\Analytics\TrackerAnalyticsConfig;
use SCM\Commercial\Promotion\Analytics\TrackerRollup;
use SCM\Commercial\Promotion\Analytics\TrackerSchema;
use SCM\Commercial\Strategy\StrategicPlanRepository;
use SCM\Commercial\Tools\CleaningSettings;
use SCM\Commercial\Tools\UtmCatalog;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\SchemaInspector;

/**
 * Menú "Configuración" del panel comercial: lee y guarda cada sección configurable.
 * Los valores que también usan otras aplicaciones (SLA de tareas, plazos y valores de avisos,
 * cupos de destacados, calculadora de aseo) se guardan en su fila de `jet_cct_confi_sistema` para mantener una sola fuente.
 * Lo propio del panel (plazos de actualización, catálogo del Generador UTM, Analítica web) va en Settings.
 * La lista única de Medios vive en su propia tabla (scm_medios, MedioCatalog): la sección "medios" la administra y, al
 * cambiarla, sincroniza el glosario "Medio de llamada" de JetEngine. Los catálogos del Plan estratégico (sección "plan")
 * son las mismas tablas CCT que usa el plugin Gestor Estratégico de WordPress.
 */
final class CommercialSettingsService
{
  public const PROPERTY_UPDATE_SETTING = 'commercial_property_update_limits';

  /** Plazos por defecto (días) para alerta y vencimiento de la actualización de inmuebles publicados. */
  public const PROPERTY_UPDATE_DEFAULTS = [
    'arriendo' => ['alerta' => 80, 'vencido' => 90],
    'venta' => ['alerta' => 110, 'vencido' => 120],
    'arriendo_venta' => ['alerta' => 100, 'vencido' => 110],
  ];

  /** Filas de `jet_cct_confi_sistema` del módulo de avisos: [etiqueta, valor por defecto, mínimo, máximo]. */
  public const SIGN_KEYS = [
    'dia-retoque-arriendo' => ['Días para retocar aviso de arriendo', 45, 1, 365],
    'dia-retoque-venta' => ['Días para retocar aviso de venta', 60, 1, 365],
    'dia-retoque-arriendo-venta' => ['Días para retocar aviso de arriendo y venta', 70, 1, 365],
    'dias-atraso-aviso-nuevo' => ['Días para instalar un aviso nuevo', 5, 1, 90],
    'valor_aviso' => ['Valor pagado por aviso instalado', 0, 0, 100000000],
    'valor_transporte' => ['Valor de transporte por aviso', 0, 0, 100000000],
    'factor_prestacional' => ['Factor prestacional', 0, 0, 100],
  ];

  public const SECTIONS = ['calendario', 'tiempos', 'avisos', 'actualizaciones', 'destacados', 'aseo', 'utm', 'medios', 'analitica', 'plan'];

  /** Operaciones de la sección "medios" (todas guardan, salvo preview, que solo cuenta). */
  public const MEDIOS_OPS = ['add', 'rename', 'toggle', 'merge', 'aliases', 'alias_raw', 'rewrite_raw', 'preview'];

  private Database $db;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private SchemaInspector $schema;
  /** @var array<string,mixed> */
  private array $config;
  /** @var array<string,mixed> Datos extra de la última operación (conteos de la sección Medios). */
  private array $lastResult = [];

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->schema = new SchemaInspector($db);
    $this->config = $config;
  }

  /**
   * @param string $section Con 'medios' se agrega el detalle de la lista (uso en registros, valores sin reconocer e
   *   historial), que es más costoso y solo hace falta al abrir esa sección.
   * @return array<string,mixed>
   */
  public function load(string $section = ''): array
  {
    $this->authorize();
    $calendar = new CommercialCalendarService($this->db, $this->settings, $this->policy, $this->config);
    $sla = new CommercialSlaService($this->db);

    $topics = [];
    foreach (CommercialTicketsRepository::COMMERCIAL_TOPICS as $topic) {
      $topics[] = ['topic' => $topic] + $sla->ruleFor($topic);
    }

    $signs = [];
    foreach (self::SIGN_KEYS as $key => [$label, $default, $min, $max]) {
      $value = $this->systemValue($key);
      $signs[] = ['key' => $key, 'label' => $label, 'value' => is_numeric($value) ? $value + 0 : $default, 'min' => $min, 'max' => $max];
    }

    $highlights = [];
    foreach (CommercialPropertiesRepository::PORTALS as $key => $portal) {
      $value = $this->systemValue($key);
      $highlights[] = ['key' => $key, 'label' => (string) $portal['label'], 'value' => is_numeric($value) ? max(0, (int) $value) : 0];
    }

    return [
      'calendario' => [
        'category_ids' => $calendar->categoryIds(),
        'cargo_ids' => $calendar->calendarCargos(),
        'cargo_options' => $this->policy->cargoOptions(),
      ],
      'tiempos' => ['topics' => $topics],
      'avisos' => ['values' => $signs],
      'actualizaciones' => ['limits' => self::propertyUpdateLimits($this->settings)],
      'destacados' => ['portals' => $highlights],
      'aseo' => $this->cleaningSettings(),
      // Solo las listas de medios, fuentes y campañas del Generador UTM.
      'utm' => UtmCatalog::read($this->settings),
      'medios' => $this->mediosSettings($section === 'medios'),
      'analitica' => $this->analyticsSettings(),
      // Catálogos del Plan estratégico con su uso: solo al abrir esa sección.
      'plan' => $section === 'plan' ? (new StrategicPlanRepository($this->db))->catalogSettings() : [],
    ];
  }

  /**
   * Solo la sección Medios con su detalle (lo mismo que load('medios')['medios']).
   * @return array<string,mixed>
   */
  public function loadMedios(): array
  {
    $this->authorize();
    return $this->mediosSettings(true);
  }

  /** @return array<string,mixed> */
  public function lastResult(): array
  {
    return $this->lastResult;
  }

  private function medios(): MedioCatalog
  {
    return new MedioCatalog($this->db, $this->settings, trim(Auth::employeeId()));
  }

  /**
   * Configuración › Medios: la lista y, con $detail, cuántos registros usa cada medio, los valores sin reconocer y el
   * historial.
   * @return array<string,mixed>
   */
  private function mediosSettings(bool $detail): array
  {
    $catalog = $this->medios();
    $out = [
      'ready' => $catalog->ready(),
      'direct' => MedioResolver::DIRECT,
      'tables' => MedioCatalog::TABLE_LABELS,
      'medios' => $catalog->all(),
    ];
    if (!$detail) {
      return $out;
    }
    try {
      $values = $catalog->recordValues();
      $out['usage'] = $catalog->usage($values);
      $out['unresolved'] = $catalog->unresolved();
    } catch (\Throwable $exception) {
      error_log('[medios] ' . $exception->getMessage());
      $out['usage'] = [];
      $out['unresolved'] = ['records' => [], 'variants' => [], 'visits' => []];
      $out['error'] = 'No se pudieron contar los registros por medio.';
    }
    $out['history'] = $catalog->history(12);
    // ¿La analítica web ya clasificó las visitas con la versión vigente de la lista?
    $out['analytics_pending'] = false;
    if (TrackerSchema::isReady($this->settings)) {
      $out['analytics_pending'] = TrackerRollup::state($this->db)['medios_rev'] !== $catalog->revision();
    }
    return $out;
  }

  /**
   * Operaciones de Configuración › Medios. Cada una valida en el servidor y va en una transacción con su historial.
   * @param array<string,mixed> $data op y sus datos (id, nombre, target, value, aliases, active).
   */
  private function saveMedios(array $data): string
  {
    $op = is_string($data['op'] ?? null) ? $data['op'] : '';
    if (!in_array($op, self::MEDIOS_OPS, true)) {
      throw new \InvalidArgumentException('Operación de medios no válida.');
    }
    $catalog = $this->medios();
    $id = (int) ($data['id'] ?? 0);
    $target = (int) ($data['target'] ?? 0);
    $text = static fn(string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    $analytics = ' La analítica web lo aplica en la siguiente corrida de la tarea programada (unos minutos).';
    $sum = static fn(array $counts): int => (int) array_sum($counts);
    switch ($op) {
      case 'preview':
        $counts = $catalog->preview($text('value'));
        $this->lastResult = ['preview' => $counts, 'total' => $sum($counts)];
        return '';
      case 'add':
        $added = $catalog->add($text('nombre'));
        $this->lastResult = $added;
        return 'Medio "' . $added['nombre'] . '" agregado. Ya aparece en el glosario de los formularios y en el Generador UTM.';
      case 'rename':
        $counts = $catalog->rename($id, $text('nombre'));
        $this->lastResult = ['records' => $counts];
        return 'Medio renombrado: ' . $sum($counts) . ' registros quedaron con el nombre nuevo y el anterior queda como alias.' . $analytics;
      case 'toggle':
        $active = !empty($data['active']) && $data['active'] !== 'false';
        $catalog->setActive($id, $active);
        return $active ? 'Medio activado: vuelve a ofrecerse en los formularios y en el Generador UTM.' : 'Medio desactivado: ya no se ofrece, pero sus registros y visitas se siguen reconociendo.';
      case 'merge':
        $counts = $catalog->merge($id, $target);
        $this->lastResult = ['records' => $counts];
        return 'Medios unidos: ' . $sum($counts) . ' registros pasaron al medio de destino.' . $analytics;
      case 'aliases':
        $aliases = $catalog->setAliases($id, is_array($data['aliases'] ?? null) ? array_values(array_map('strval', array_filter($data['aliases'], 'is_scalar'))) : $text('aliases'));
        $this->lastResult = ['aliases' => $aliases];
        return 'Alias guardados (' . count($aliases) . ').' . $analytics;
      case 'alias_raw':
        $catalog->aliasRaw($text('value'), $target);
        return 'Valor agregado como alias; los registros no cambian.' . $analytics;
      case 'rewrite_raw':
        $counts = $catalog->rewriteRaw($text('value'), $target);
        $this->lastResult = ['records' => $counts];
        return $sum($counts) . ' registros reescritos con el medio elegido (quedan en el historial y se pueden revertir).' . $analytics;
      default:
        throw new \InvalidArgumentException('Operación de medios no válida.');
    }
  }

  /**
   * Configuración › Analítica web y el estado de los resúmenes (solo lectura).
   * @return array<string,mixed>
   */
  private function analyticsSettings(): array
  {
    $config = TrackerAnalyticsConfig::read($this->settings);
    try {
      $status = TrackerRollup::readStatus($this->db, $this->settings);
    } catch (\Throwable) {
      // Sin la tabla del tracker (o sin acceso a ella) la sección se puede editar igual.
      $status = TrackerRollup::state($this->db) + ['pending' => null, 'schema_ready' => false];
    }
    return $config + ['max_retention_days' => TrackerAnalyticsConfig::MAX_RETENTION_DAYS, 'status' => $status];
  }

  /**
   * @param array<string,mixed> $data
   * @return string Mensaje de confirmación.
   */
  public function save(string $section, array $data): string
  {
    $this->authorize();
    switch ($section) {
      case 'calendario':
        return $this->saveCalendar($data);
      case 'tiempos':
        return $this->saveSla($data);
      case 'avisos':
        return $this->saveSigns($data);
      case 'actualizaciones':
        return $this->savePropertyUpdates($data);
      case 'destacados':
        return $this->saveHighlights($data);
      case 'aseo':
        // Mismas filas que la calculadora de aseo del panel inmobiliario y el plugin de WordPress.
        (new CleaningSettings($this->db))->save($data, trim(Auth::employeeId()));
        return 'Configuración de la calculadora de aseo guardada.';
      case 'utm':
        UtmCatalog::save($this->settings, $data, Auth::userId());
        return 'Campañas del generador UTM guardadas.';
      case 'medios':
        return $this->saveMedios($data);
      case 'plan':
        return $this->savePlanCatalogs($data);
      case 'analitica':
        $before = TrackerAnalyticsConfig::read($this->settings);
        $saved = TrackerAnalyticsConfig::save($this->settings, $data, Auth::userId());
        if ($saved['internal_ips'] !== $before['internal_ips'] || $saved['own_domains'] !== $before['own_domains']) {
          return 'Configuración de la analítica web guardada. Los cambios de IPs internas y de dominios propios se aplican a las visitas nuevas; para recalcular meses anteriores, pide a sistemas que recalculen desde una fecha.';
        }
        return 'Configuración de la analítica web guardada.';
    }
    throw new \InvalidArgumentException('Sección de configuración no válida.');
  }

  /** Configuración › Plan estratégico: los catálogos completos; eliminar exige confirm_delete. @param array<string,mixed> $data */
  private function savePlanCatalogs(array $data): string
  {
    $author = trim(Auth::employeeId()) !== '' ? trim(Auth::employeeId()) : (string) Auth::userId();
    $totals = (new StrategicPlanRepository($this->db))->saveCatalogs($data, $author);
    $this->lastResult = $totals;
    $parts = [];
    foreach (['added' => 'agregados', 'updated' => 'actualizados', 'deleted' => 'eliminados'] as $key => $label) {
      if ($totals[$key] > 0) $parts[] = $totals[$key] . ' ' . $label;
    }
    return 'Catálogos del plan estratégico guardados' . ($parts !== [] ? ': ' . implode(', ', $parts) : ' (sin cambios)') . '.'
      . ($totals['renamed'] > 0 ? ' ' . $totals['renamed'] . ' registros del plan tomaron el nombre nuevo.' : '');
  }

  /**
   * Plazos de actualización por tipo de negocio, con los valores por defecto si no hay configuración.
   *
   * @return array<string,array{alerta:int,vencido:int}>
   */
  public static function propertyUpdateLimits(?Settings $settings): array
  {
    $stored = [];
    try {
      $raw = $settings ? $settings->get(self::PROPERTY_UPDATE_SETTING, []) : [];
      $stored = is_array($raw) ? $raw : [];
    } catch (\Throwable) {
      $stored = [];
    }
    $limits = [];
    foreach (self::PROPERTY_UPDATE_DEFAULTS as $type => $default) {
      $row = is_array($stored[$type] ?? null) ? $stored[$type] : [];
      $due = max(1, (int) ($row['vencido'] ?? $default['vencido']));
      $warning = max(0, min($due, (int) ($row['alerta'] ?? $default['alerta'])));
      $limits[$type] = ['alerta' => $warning, 'vencido' => $due];
    }
    return $limits;
  }

  /**
   * Variables (los porcentajes en %, como se escriben) y tablas de la calculadora de aseo.
   * @return array{globals:list<array{key:string,label:string,percent:bool,value:float}>,insumos:array<int,mixed>,transporte:array<int,mixed>,tramos:array<int,mixed>}
   */
  private function cleaningSettings(): array
  {
    $config = (new CleaningSettings($this->db))->read();
    $globals = [];
    foreach (CleaningSettings::GLOBALS as $key => [$label, , $percent]) {
      $value = (float) ($config[$key] ?? 0);
      $globals[] = ['key' => $key, 'label' => $label, 'percent' => $percent, 'value' => $percent ? round($value * 100, 4) : $value];
    }
    return ['globals' => $globals, 'insumos' => $config['insumos'], 'transporte' => $config['transporte'], 'tramos' => $config['tramos']];
  }

  /** @param array<string,mixed> $data */
  private function saveCalendar(array $data): string
  {
    $cargoIds = $this->ids($data['cargo_ids'] ?? []);
    if ($cargoIds === []) {
      throw new \InvalidArgumentException('Selecciona al menos un cargo para el calendario comercial.');
    }
    $categoryIds = $this->ids($data['category_ids'] ?? []);
    $available = $this->ids($data['available_category_ids'] ?? []);
    $calendar = new CommercialCalendarService($this->db, $this->settings, $this->policy, $this->config);
    if ($categoryIds !== [] || $available !== []) {
      $calendar->saveCategoryIds($categoryIds, $available);
    }
    $this->settings->set(CommercialCalendarService::CARGOS_SETTING, $cargoIds, Auth::userId());
    $this->settings->refresh();
    return 'Configuración del calendario comercial guardada.';
  }

  /** @param array<string,mixed> $data */
  private function saveSla(array $data): string
  {
    $rules = is_array($data['topics'] ?? null) ? $data['topics'] : [];
    $sla = new CommercialSlaService($this->db);
    $raw = $this->systemValue(CommercialSlaService::CONFIG_KEY);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $stored = is_array($decoded) && is_array($decoded['topics'] ?? null) ? $decoded['topics'] : [];
    if ($stored === []) {
      // Primera vez: se parte de las reglas vigentes para no perder los temas que usan otras áreas.
      foreach (array_keys(CommercialSlaService::defaultConfig()['topics']) as $topic) {
        $stored[$topic] = $sla->ruleFor($topic);
      }
    }

    foreach (CommercialTicketsRepository::COMMERCIAL_TOPICS as $topic) {
      $rule = is_array($rules[$topic] ?? null) ? $rules[$topic] : null;
      if ($rule === null) {
        continue;
      }
      $due = (int) ($rule['overdue_after'] ?? 0);
      $warning = (int) ($rule['warning_from'] ?? 0);
      if ($due < 1 || $due > 120 || $warning < 0 || $warning > $due) {
        throw new \InvalidArgumentException('Revisa los días de "' . $topic . '": el vencimiento va de 1 a 120 días y la alerta no puede superarlo.');
      }
      // El tema se guarda en todas sus variantes (con y sin tilde) para que el cálculo use la nueva regla.
      $updated = false;
      foreach (array_keys($stored) as $existing) {
        if ($sla->topicKey((string) $existing) === $sla->topicKey($topic)) {
          $stored[$existing] = ['warning_from' => $warning, 'overdue_after' => $due];
          $updated = true;
        }
      }
      if (!$updated) {
        $stored[$topic] = ['warning_from' => $warning, 'overdue_after' => $due];
      }
    }

    $this->saveSystemValue(CommercialSlaService::CONFIG_KEY, (string) json_encode(['version' => 1, 'topics' => $stored], JSON_UNESCAPED_UNICODE));
    return 'Tiempos de atención de tareas guardados.';
  }

  /** @param array<string,mixed> $data */
  private function saveSigns(array $data): string
  {
    $values = is_array($data['values'] ?? null) ? $data['values'] : [];
    $clean = [];
    foreach (self::SIGN_KEYS as $key => [$label, , $min, $max]) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $raw = str_replace(',', '.', trim((string) $values[$key]));
      if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
        throw new \InvalidArgumentException('"' . $label . '" debe estar entre ' . $min . ' y ' . $max . '.');
      }
      $clean[$key] = $key === 'factor_prestacional' ? (string) round((float) $raw, 4) : (string) (int) round((float) $raw);
    }
    foreach ($clean as $key => $value) {
      $this->saveSystemValue($key, $value);
    }
    return 'Plazos y valores de avisos guardados.';
  }

  /** @param array<string,mixed> $data */
  private function savePropertyUpdates(array $data): string
  {
    $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
    $clean = [];
    $labels = ['arriendo' => 'Arriendo', 'venta' => 'Venta', 'arriendo_venta' => 'Arriendo y venta'];
    foreach (self::PROPERTY_UPDATE_DEFAULTS as $type => $default) {
      $row = is_array($limits[$type] ?? null) ? $limits[$type] : $default;
      $due = (int) ($row['vencido'] ?? 0);
      $warning = (int) ($row['alerta'] ?? 0);
      if ($due < 1 || $due > 730 || $warning < 0 || $warning > $due) {
        throw new \InvalidArgumentException('Revisa los días de ' . $labels[$type] . ': el vencimiento va de 1 a 730 días y la alerta no puede superarlo.');
      }
      $clean[$type] = ['alerta' => $warning, 'vencido' => $due];
    }
    $this->settings->set(self::PROPERTY_UPDATE_SETTING, $clean, Auth::userId());
    $this->settings->refresh();
    return 'Plazos de actualización de inmuebles guardados.';
  }

  /** @param array<string,mixed> $data */
  private function saveHighlights(array $data): string
  {
    $values = is_array($data['portals'] ?? null) ? $data['portals'] : [];
    foreach (array_keys(CommercialPropertiesRepository::PORTALS) as $key) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $value = (int) $values[$key];
      if ($value < 0 || $value > 10000) {
        throw new \InvalidArgumentException('Los cupos de destacados deben estar entre 0 y 10000.');
      }
      $this->saveSystemValue($key, (string) $value);
    }
    return 'Cupos de destacados guardados.';
  }

  private function authorize(): void
  {
    if (!$this->policy->canManage()) {
      throw new \RuntimeException('No tienes permiso para cambiar la configuración del panel comercial.');
    }
  }

  /** @return string|null */
  private function systemValue(string $key)
  {
    $table = $this->db->table('jet_cct_confi_sistema');
    $value = $this->db->getVar("SELECT `valor` FROM `{$table}` WHERE `funcion` = ? ORDER BY `_ID` DESC LIMIT 1", [$key]);
    return $value === null ? null : (string) $value;
  }

  /** Actualiza la fila más reciente de la función o la crea; registra al funcionario que hizo el cambio. */
  private function saveSystemValue(string $key, string $value): void
  {
    $table = $this->db->table('jet_cct_confi_sistema');
    $now = date('Y-m-d H:i:s');
    $author = trim(Auth::employeeId()) !== '' ? trim(Auth::employeeId()) : (string) Auth::userId();
    $id = (int) ($this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE `funcion` = ? ORDER BY `_ID` DESC LIMIT 1", [$key]) ?? 0);
    $data = $this->existingColumns($table, ['valor' => $value, 'cct_modified' => $now, 'cct_author_id' => $author]);
    if ($id > 0) {
      $this->db->update($table, $data, ['_ID' => $id]);
      return;
    }
    $this->db->insert($table, $this->existingColumns($table, [
      'funcion' => $key,
      'valor' => $value,
      'cct_status' => 'publish',
      'cct_created' => $now,
      'cct_modified' => $now,
      'cct_author_id' => $author,
    ]));
  }

  /** @param array<string,mixed> $data @return array<string,mixed> */
  private function existingColumns(string $table, array $data): array
  {
    return array_filter($data, fn(string $column): bool => in_array($column, ['funcion', 'valor'], true) || $this->schema->columnExists($table, $column), ARRAY_FILTER_USE_KEY);
  }

  /** @param mixed $raw @return array<int,string> */
  private function ids($raw): array
  {
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      $raw = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    if (!is_array($raw)) {
      return [];
    }
    return array_values(array_unique(array_filter(array_map(
      static fn($id): string => preg_replace('/[^0-9]/', '', (string) $id) ?? '',
      $raw
    ), static fn(string $id): bool => $id !== '')));
  }
}
