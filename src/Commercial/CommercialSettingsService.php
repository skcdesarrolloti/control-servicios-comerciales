<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\SchemaInspector;

/**
 * Menú "Configuración" del panel comercial: lee y guarda cada sección configurable.
 * Los valores que también usan otras aplicaciones (SLA de tareas, plazos y valores de avisos,
 * cupos de destacados) se guardan en su fila de `jet_cct_confi_sistema` para mantener una sola fuente.
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

  public const SECTIONS = ['calendario', 'tiempos', 'avisos', 'actualizaciones', 'destacados'];

  private Database $db;
  private Settings $settings;
  private CommercialAccessPolicy $policy;
  private SchemaInspector $schema;
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, CommercialAccessPolicy $policy, array $config)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->policy = $policy;
    $this->schema = new SchemaInspector($db);
    $this->config = $config;
  }

  /** @return array<string,mixed> */
  public function load(): array
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
    ];
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
    }
    throw new \InvalidArgumentException('Sección de configuración no válida.');
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
