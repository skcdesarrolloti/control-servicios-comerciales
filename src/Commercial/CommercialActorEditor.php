<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/** Edición acotada a cinco datos, con revisión vinculada a la sesión y guardado transaccional. */
final class CommercialActorEditor
{
  public const FIELDS = ['documento' => 'Documento / NIT', 'nombre' => 'Nombre', 'correo' => 'Correo', 'celular' => 'Celular', 'indicativo' => 'Indicativo'];
  private Database $db;
  private CommercialAccessPolicy $policy;
  private CommercialNotificationsService $notifications;
  private SchemaInspector $schema;

  public function __construct(Database $db, CommercialAccessPolicy $policy)
  {
    $this->db = $db;
    $this->policy = $policy;
    $this->notifications = new CommercialNotificationsService($db, $policy);
    $this->schema = new SchemaInspector($db);
  }

  private function actor(string $type, int $id): array
  {
    if (!$this->policy->canAct('editar_actor')) { throw new \RuntimeException('No tienes permiso para editar actores.'); }
    return $this->notifications->actorForEditing($type, $id);
  }

  private function fields(array $config, array $row): array
  {
    $table = $config['table'];
    $juridical = str_contains(mb_strtolower((string) ($row['tipo_persona'] ?? '')), 'jur') || (empty($row['nombre']) && !empty($row['nombre_juridico']));
    $candidates = [
      'documento' => $juridical ? ['documento_juridico', 'documento', 'nit'] : ['documento', 'nit', 'documento_juridico'],
      'nombre' => $juridical ? ['nombre_juridico', 'nombre'] : $config['name'],
      'correo' => $config['email'], 'celular' => $config['phone'], 'indicativo' => $config['indicator'],
    ];
    $out = [];
    foreach ($candidates as $field => $columns) {
      foreach ($columns as $column) {
        if ($this->schema->columnExists($table, $column)) { $out[$field] = $column; break; }
      }
    }
    return $out;
  }

  public function detail(string $type, int $id): array
  {
    ['config' => $config, 'row' => $row] = $this->actor($type, $id);
    $values = [];
    foreach ($this->fields($config, $row) as $field => $column) { $values[$field] = (string) ($row[$column] ?? ''); }
    $groups = ['inmuebles' => 'Inmuebles', 'mandatos' => 'Contratos de mandato', 'arrendamientos' => 'Contratos de arrendamiento', 'cierres' => 'Cierres'];
    $related = [];
    $relatedError = '';
    try {
      foreach ($this->related($type, $id, $row, [], array_keys($groups), true) as $item) {
        $record = $this->present($item);
        $record['values'] = array_map(static fn(array $field): array => ['label' => $field['label'], 'value' => $field['before']], $record['changes']);
        unset($record['changes']);
        $related[] = $record;
      }
    } catch (\PDOException $exception) { throw $exception; }
    catch (\RuntimeException $exception) { $relatedError = $exception->getMessage(); }
    return ['id' => $id, 'type' => $type, 'label' => $config['role'], 'values' => $values,
      'groups' => $groups, 'related' => $related, 'related_error' => $relatedError,
      'dialing_codes' => $this->dialingCodes(),
      'version' => $this->version($row)];
  }

  private function dialingCodes(): array
  {
    $fallback = [['code' => '+57', 'country' => 'Colombia']];
    $table = $this->db->table('jet_cct_paises');
    if (!$this->schema->tableExists($table)) { return $fallback; }
    $codeColumn = $countryColumn = null;
    foreach (['codigo', 'indicativo', 'phone_code'] as $column) {
      if ($this->schema->columnExists($table, $column)) { $codeColumn = $column; break; }
    }
    foreach (['pais', 'nombre', 'country'] as $column) {
      if ($this->schema->columnExists($table, $column)) { $countryColumn = $column; break; }
    }
    if ($codeColumn === null || $countryColumn === null) { return $fallback; }
    $countries = [];
    foreach ($this->db->getResults("SELECT `{$codeColumn}` AS code, `{$countryColumn}` AS country FROM `{$table}` ORDER BY `{$countryColumn}` LIMIT 500") as $row) {
      $code = trim((string) ($row['code'] ?? ''));
      $country = trim((string) ($row['country'] ?? ''));
      if ($country === '' || !preg_match('/^\+?[1-9][0-9]{0,3}$/', $code)) { continue; }
      $countries['+' . ltrim($code, '+')][] = $country;
    }
    $out = [];
    foreach ($countries as $code => $names) { $out[] = ['code' => $code, 'country' => implode(' / ', array_unique($names))]; }
    return $out ?: $fallback;
  }

  private function validate(string $field, string $value): string
  {
    if (mb_strlen($value) > ($field === 'nombre' || $field === 'correo' ? 254 : 40) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
      throw new \InvalidArgumentException('Revisa la longitud y el contenido de ' . self::FIELDS[$field] . '.');
    }
    if ($field === 'nombre' && $value === '') { throw new \InvalidArgumentException('El nombre es obligatorio.'); }
    if ($field === 'documento' && ($value === '' || !preg_match('/^[\p{L}\p{N} .-]+$/u', $value))) {
      throw new \InvalidArgumentException('El documento debe contener letras o números; puede incluir puntos y guiones.');
    }
    if ($field === 'correo' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('El correo no es válido.'); }
    if ($field === 'celular' && $value !== '') {
      if (!preg_match('/^[0-9 ()-]+$/', $value)) { throw new \InvalidArgumentException('Escribe el celular sin indicativo; usa el campo Indicativo para el código de país.'); }
      $value = preg_replace('/\D/', '', $value) ?? '';
      if (strlen($value) < 4 || strlen($value) > 15) { throw new \InvalidArgumentException('El celular debe tener entre 4 y 15 dígitos.'); }
    }
    if ($field === 'indicativo' && $value !== '') {
      if (!preg_match('/^\+?[1-9][0-9]{0,3}$/', $value)) { throw new \InvalidArgumentException('El indicativo debe ser un código de país, por ejemplo +57.'); }
      $value = '+' . ltrim($value, '+');
    }
    return $value;
  }

  private function assertUniqueDocument(string $table, int $id, string $document): void
  {
    $parts = [];
    $args = [$id];
    foreach (['documento', 'documento_juridico', 'nit'] as $column) {
      if (!$this->schema->columnExists($table, $column)) { continue; }
      $parts[] = "UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(`{$column}`,'')), ' ', ''), '.', ''), '-', '')) = ?";
      $args[] = mb_strtoupper(str_replace([' ', '.', '-'], '', $document));
    }
    if ($parts !== [] && $this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE `_ID` <> ? AND (" . implode(' OR ', $parts) . ') LIMIT 1', $args)) {
      throw new \InvalidArgumentException('Ya existe otro actor de este tipo con ese documento.');
    }
  }

  public function preview(string $type, int $id, array $input): array
  {
    ['config' => $config, 'row' => $row] = $this->actor($type, $id);
    if (!hash_equals($this->version($row), (string) ($input['version'] ?? ''))) { throw new \RuntimeException('El actor cambió. Cierra y abre de nuevo el editor.'); }
    $changes = [];
    $logical = [];
    foreach ($this->fields($config, $row) as $field => $column) {
      if (!array_key_exists($field, $input)) { continue; }
      $value = trim((string) $input[$field]);
      if ($value === (string) ($row[$column] ?? '')) { continue; }
      $value = $this->validate($field, $value);
      if ($value !== (string) ($row[$column] ?? '')) { $changes[$column] = $value; $logical[$field] = $value; }
    }
    if (isset($logical['documento'])) { $this->assertUniqueDocument($config['table'], $id, $logical['documento']); }
    $fields = $this->fields($config, $row);
    $phone = $logical['celular'] ?? (string) ($row[$fields['celular'] ?? ''] ?? '');
    $indicator = $logical['indicativo'] ?? (string) ($row[$fields['indicativo'] ?? ''] ?? '');
    if ((isset($logical['celular']) || isset($logical['indicativo'])) && strlen(preg_replace('/\D/', '', $phone . $indicator) ?? '') > 15) {
      throw new \InvalidArgumentException('El celular con indicativo no puede superar 15 dígitos.');
    }
    $comparison = [];
    foreach ($fields as $column) { $comparison[$column] = $changes[$column] ?? (string) ($row[$column] ?? ''); }
    $plan = [$this->item($config['table'], $row, $changes, $config['role'] . ' · registro principal', 'actor', 'Datos principales', $comparison)];
    $groups = array_intersect(['inmuebles', 'mandatos', 'arrendamientos', 'cierres'], (array) ($input['groups'] ?? []));
    $selected = isset($input['select_records']) ? array_map('strval', (array) ($input['records'] ?? [])) : null;
    foreach ($this->related($type, $id, $row, $logical, $groups, true) as $item) {
      if ($selected === null || in_array($item['key'], $selected, true)) { $plan[] = $item; }
    }
    if (count($plan) > 500) { throw new \RuntimeException('La edición afecta más de 500 registros. Selecciona menos grupos para revisar.'); }
    $total = count(array_filter($plan, static fn(array $item): bool => $item['data'] !== []));
    $result = ['token' => '', 'items' => array_map([$this, 'present'], $plan), 'total' => $total, 'records_total' => count($plan)];
    if ($total === 0) { return $result; }
    $this->assertTransactional(array_unique(array_column($plan, 'table')));
    $token = bin2hex(random_bytes(24));
    $_SESSION['scm_actor_previews'] = array_filter((array) ($_SESSION['scm_actor_previews'] ?? []), static fn(array $entry): bool => $entry['expires'] >= time());
    $_SESSION['scm_actor_previews'] = array_slice($_SESSION['scm_actor_previews'], -9, null, true);
    $_SESSION['scm_actor_previews'][$token] = ['user_id' => Auth::userId(), 'expires' => time() + 900, 'type' => $type, 'id' => $id, 'plan' => $plan, 'document' => $logical['documento'] ?? null];
    $result['token'] = $token;
    return $result;
  }

  private function item(string $table, array $row, array $data, string $label, string $group, string $context, ?array $comparison = null): array
  {
    $comparison ??= $data;
    return ['key' => $group . ':' . $row['_ID'], 'table' => $table, 'id' => (int) $row['_ID'], 'label' => $label,
      'group' => $group, 'context' => $context, 'version' => $this->version($row), 'before' => array_intersect_key($row, $comparison), 'data' => $data, 'comparison' => $comparison];
  }

  private function present(array $item): array
  {
    $changes = [];
    foreach ($item['comparison'] ?? $item['data'] as $column => $value) {
      $label = preg_match('/documento|^nit/', $column) ? 'Documento / NIT' : (preg_match('/correo|email/', $column) ? 'Correo' : (preg_match('/celular|telefono|contacto/', $column) ? 'Celular' : (str_contains($column, 'indicativo') ? 'Indicativo' : 'Nombre')));
      if (preg_match('/_(\d)$/', $column, $match)) { $label .= ' · titular ' . $match[1]; }
      $changes[] = ['field' => $column, 'label' => $label, 'before' => (string) ($item['before'][$column] ?? ''), 'after' => $value, 'changed' => array_key_exists($column, $item['data'])];
    }
    return ['key' => $item['key'], 'id' => $item['id'], 'label' => $item['label'], 'group' => $item['group'], 'context' => $item['context'], 'changes' => $changes, 'has_changes' => $item['data'] !== []];
  }

  private function version(array $row): string
  {
    ksort($row);
    return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
  }

  /** Solo relaciones por ID estable, nunca por nombre o correo. Los mandatos respetan cada titular. */
  private function related(string $type, int $id, array $actor, array $logical, array $groups, bool $listing = false): array
  {
    if ($groups === []) { return []; }
    $party = str_starts_with($type, 'propietarios') ? 'propietario' : (str_starts_with($type, 'arrendatarios') ? 'arrendatario' : ($type === 'copropiedades' ? 'copropiedad' : 'pph'));
    $link = $party === 'pph' ? 'id_pph' : 'id_' . $party;
    $refs = array_values(array_unique(array_filter([(string) $id, trim((string) ($actor[$link] ?? ''))], static fn(string $ref): bool => $ref !== '')));
    $actorTable = $this->notifications->types()[$type]['table'];
    foreach ($refs as $ref) {
      $hasAlias = $this->schema->columnExists($actorTable, $link);
      $condition = 'CAST(`_ID` AS CHAR) = ?' . ($hasAlias ? " OR TRIM(COALESCE(`{$link}`, '')) = ?" : '');
      if ($this->db->getVar("SELECT `_ID` FROM `{$actorTable}` WHERE `_ID` <> ? AND ({$condition}) LIMIT 1", $hasAlias ? [$id, $ref, $ref] : [$id, $ref])) {
        throw new \RuntimeException('Una referencia del actor coincide con otro registro. Revisa sus IDs antes de propagar; puedes editar solo sus datos principales.');
      }
    }
    $tables = ['inmuebles' => ['jet_cct_inmuebles', 'Inmueble'], 'mandatos' => ['jet_cct_contrato_mandato', 'Mandato'], 'arrendamientos' => ['jet_cct_contratos_arrendamiento', 'Arrendamiento'], 'cierres' => ['jet_cct_cierres', 'Cierre']];
    $out = [];
    foreach ($groups as $group) {
      [$suffix, $label] = $tables[$group];
      $table = $this->db->table($suffix);
      if (!$this->schema->tableExists($table)) { continue; }
      $linkColumns = [$link];
      if ($group === 'mandatos' && $party === 'propietario') { for ($slot = 1; $slot <= 6; $slot++) { $linkColumns[] = 'id_propietario_nuevo_' . $slot; } }
      $conditions = [];
      $args = [];
      foreach ($linkColumns as $column) {
        if (!$this->schema->columnExists($table, $column)) { continue; }
        $conditions[] = "TRIM(CAST(`{$column}` AS CHAR)) IN (" . implode(',', array_fill(0, count($refs), '?')) . ')';
        array_push($args, ...$refs);
      }
      if ($conditions === []) { continue; }
      $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE (" . implode(' OR ', $conditions) . ') ORDER BY `_ID` LIMIT 501', $args);
      if (count($rows) > 500) { throw new \RuntimeException('Demasiados registros relacionados en ' . $label . '.'); }
      foreach ($rows as $row) {
        $data = [];
        $comparison = [];
        $map = ['nombre' => [$party, 'nombre_' . $party], 'documento' => ['documento_' . $party], 'correo' => ['correo_' . $party, 'email_' . $party], 'celular' => ['celular_' . $party], 'indicativo' => ['indicativo_' . $party]];
        if ($party === 'copropiedad') { $map['documento'] = ['nit_copropiedad']; }
        if ($party === 'pph') { $map['nombre'] = ['nombre_pph']; }
        if ($group === 'mandatos' && $party === 'copropiedad') { $map = ['nombre' => ['copropiedad'], 'documento' => ['nit_co'], 'correo' => ['correo_co'], 'celular' => ['contacto_co'], 'indicativo' => ['indicativo_co']]; }
        if ($group === 'mandatos' && $party === 'propietario') {
          $map = array_fill_keys(array_keys(self::FIELDS), []);
          $hasSlots = false;
          for ($slot = 1; $slot <= 6; $slot++) {
            if (!in_array(trim((string) ($row['id_propietario_nuevo_' . $slot] ?? '')), $refs, true)) { continue; }
            $hasSlots = true;
            $juridical = str_contains(mb_strtolower((string) ($row['tipo_' . $slot] ?? '')), 'jur');
            foreach (['nombre' => ($juridical ? 'empresa_' : 'nombre_'), 'documento' => ($juridical ? 'nit_' : 'documento_'), 'correo' => 'correo_', 'celular' => 'celular_', 'indicativo' => 'indicativo_'] as $field => $prefix) { $map[$field][] = $prefix . $slot; }
          }
          // Mandatos antiguos de un solo titular usan id_propietario y el primer bloque.
          if (!$hasSlots && in_array(trim((string) ($row['id_propietario'] ?? '')), $refs, true) && (int) ($row['propietarios'] ?? 1) <= 1 && empty($row['id_propietario_nuevo_1'])) {
            $juridical = str_contains(mb_strtolower((string) ($row['tipo_1'] ?? '')), 'jur');
            foreach (['nombre' => $juridical ? 'empresa_1' : 'nombre_1', 'documento' => $juridical ? 'nit_1' : 'documento_1', 'correo' => 'correo_1', 'celular' => 'celular_1', 'indicativo' => 'indicativo_1'] as $field => $column) { $map[$field][] = $column; }
          }
        }
        foreach ($map as $field => $columns) {
          foreach ($columns as $column) {
            if (!$this->schema->columnExists($table, $column)) { continue; }
            $current = (string) ($row[$column] ?? '');
            $comparison[$column] = $logical[$field] ?? $current;
            if (array_key_exists($field, $logical) && $current !== $logical[$field]) { $data[$column] = $logical[$field]; }
          }
        }
        if ($comparison === [] || (!$listing && $data === [])) { continue; }
        $context = implode(' · ', array_filter([(string) ($row['estado'] ?? ''), (string) ($row['contrato'] ?? ''), (string) ($row['codigo'] ?? ''), (string) ($row['direccion'] ?? '')]));
        $out[] = $this->item($table, $row, $data, $label . ' #' . $row['_ID'], $group, $context, $comparison);
      }
    }
    return $out;
  }

  private function assertTransactional(array $tables): void
  {
    if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
    foreach ($tables as $table) {
      $engine = $this->db->getVar('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
      if (strtoupper((string) $engine) !== 'INNODB') { throw new \RuntimeException('La edición requiere tablas InnoDB para guardar todos los cambios de forma segura: ' . $table); }
    }
  }

  public function save(string $token, array $keys): array
  {
    $entry = $_SESSION['scm_actor_previews'][$token] ?? null;
    if (!is_array($entry) || $entry['expires'] < time() || $entry['user_id'] !== Auth::userId()) { throw new \RuntimeException('La revisión expiró o no corresponde a tu sesión. Revisa los cambios otra vez.'); }
    $this->actor($entry['type'], $entry['id']);
    $validKeys = array_column($entry['plan'], 'key');
    if (array_diff($keys, $validKeys) !== []) { throw new \InvalidArgumentException('La selección no corresponde a los registros revisados.'); }
    $plan = array_values(array_filter($entry['plan'], static fn(array $item): bool => $item['group'] === 'actor' || in_array($item['key'], $keys, true)));
    $updates = array_values(array_filter($plan, static fn(array $item): bool => $item['data'] !== []));
    if ($updates === []) { throw new \InvalidArgumentException('No hay cambios seleccionados para guardar.'); }
    $this->assertTransactional(array_unique(array_column($plan, 'table')));
    $auditTable = $this->ensureAuditTable();
    $this->assertTransactional([$auditTable]);
    usort($plan, static fn(array $a, array $b): int => [$a['table'], $a['id']] <=> [$b['table'], $b['id']]);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($plan as $item) {
        $current = $this->db->getRow('SELECT * FROM `' . $item['table'] . '` WHERE `_ID` = ?' . ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), [$item['id']]);
        if ($current === null || !hash_equals($item['version'], $this->version($current))) { throw new \RuntimeException('Cambió ' . $item['label'] . ' desde la revisión. No se guardó ningún cambio; revisa de nuevo.'); }
      }
      $actor = $this->actor($entry['type'], $entry['id']);
      if ($entry['document'] !== null) { $this->assertUniqueDocument($actor['config']['table'], $entry['id'], $entry['document']); }
      foreach ($updates as $item) {
        $data = $item['data'];
        if ($this->schema->columnExists($item['table'], 'cct_modified')) { $data['cct_modified'] = gmdate('Y-m-d H:i:s'); }
        if ($this->db->update($item['table'], $data, ['_ID' => $item['id']]) !== 1) { throw new \RuntimeException('No se pudo actualizar ' . $item['label'] . '.'); }
      }
      $this->db->insert($auditTable, ['actor_type' => $entry['type'], 'actor_id' => $entry['id'], 'changed_by' => Auth::userId(), 'employee_id' => Auth::employeeId(),
        'changed_by_name' => (string) $this->db->getVar('SELECT `nombre` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ?', [Auth::userId()]),
        'impact_json' => json_encode(array_map([$this, 'present'], $updates), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')]);
      $pdo->commit();
      unset($_SESSION['scm_actor_previews'][$token]);
      return ['updated' => count($updates), 'message' => 'Datos guardados en el actor y ' . (count($updates) - 1) . ' registros relacionados.'];
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      throw $exception;
    }
  }

  private function ensureAuditTable(): string
  {
    $table = $this->db->table('scm_commercial_actor_changes');
    if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$table}` (id INTEGER PRIMARY KEY, actor_type TEXT, actor_id INTEGER, changed_by INTEGER, employee_id TEXT, changed_by_name TEXT, impact_json TEXT, created_at TEXT)");
    } else {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, actor_type VARCHAR(60) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, changed_by BIGINT UNSIGNED NOT NULL, employee_id VARCHAR(64) NOT NULL, changed_by_name VARCHAR(254) NOT NULL, impact_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), KEY actor_lookup(actor_type, actor_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    return $table;
  }
}
