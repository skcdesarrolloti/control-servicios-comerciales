<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Crear y editar el contrato de mandato desde el panel (popup de la tarea y pestaña Mandatos).
 *
 * Copia de formularios/crear-mandato/procesar_contrato.php, edit-mandato/procesar_contrato.php, crear-mandato/repos.php
 * (copropiedades_guardar, inmuebles_upsert_por_codigo, estudio_upsert_por_id, insert_reporte_inmueble) y
 * comun/propietarios.php (propietarios_guardar_todos, porcentajes_desde_post): mantener iguales.
 *
 * - Todo se guarda en una transacción: propietarios, copropiedad, mandato, crédito, inmueble, estudio e historial.
 * - Cada propietario queda en Propietarios: se enlaza por id o por documento (nunca se duplica un documento).
 * - El crédito de la garantía lo recalcula el servidor (MandateCalculator).
 * - Editar un mandato ya enviado a firma actualiza su contrato en eSignatures (MandateSignature); firmado no se edita.
 */
final class CommercialMandateFormService
{
  public const ACTION_CREATE = 'mandato_crear';
  public const ACTION_SIGN = 'mandato_enviar_firma';

  private Database $db;
  private CommercialAccessPolicy $policy;
  private SchemaInspector $schema;
  private MandateCalculator $calculator;
  private ?MandateSignature $signature;
  private CommercialMandatesRepository $repository;

  public function __construct(Database $db, CommercialAccessPolicy $policy, ?MandateCalculator $calculator = null, ?MandateSignature $signature = null)
  {
    $this->db = $db;
    $this->policy = $policy;
    $this->schema = new SchemaInspector($db);
    $this->calculator = $calculator ?? new MandateCalculator($db);
    $this->signature = $signature;
    $this->repository = new CommercialMandatesRepository($db);
  }

  public function canEdit(): bool
  {
    return $this->policy->canAct(self::ACTION_CREATE);
  }

  public function calculator(): MandateCalculator
  {
    return $this->calculator;
  }

  // ── Datos del formulario ──────────────────────────

  /**
   * Valores iniciales: los del mandato al editar; al crear, el inmueble (de la tarea o del estudio aprobado) y su
   * propietario.
   * @return array<string,mixed>
   */
  public function initialValues(?array $mandate, string $propertyCode = ''): array
  {
    if ($mandate !== null) {
      return MandateFields::toForm($mandate);
    }
    $params = $this->calculator->parameters();
    $post = [
      'cantidad_propietarios' => '1', 'id_inmueble' => $propertyCode, 'tasa_comision' => self::trimNumber($params['tasa_comision']),
      'meses_cobertura_garantia' => 'Durante la vigencia del contrato', 'cobertura_garantia_servicios' => 'Durante la vigencia del contrato',
      'existe_simi' => 'No', 'mismo_propietario' => 'Si', 'codigo_inmueble' => $propertyCode, 'servicios_publicos' => [],
    ];
    return array_merge($post, $propertyCode !== '' ? $this->propertyValues($propertyCode) : []);
  }

  /**
   * Datos del inmueble para el formulario (como autocompletarInmueble): legales, áreas, parqueadero, servicios, canon
   * y su propietario como propietario #1.
   * @return array<string,mixed>
   */
  public function propertyValues(string $code): array
  {
    $code = trim($code);
    $row = $code === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE `codigo` = ? LIMIT 1', [$code]);
    if (!is_array($row)) {
      return [];
    }
    $v = static fn(string $key): string => trim((string) ($row[$key] ?? ''));
    $siNo = static function (string $value): string {
      $key = strtolower(strtr($value, ['í' => 'i', 'Í' => 'i']));
      return in_array($key, ['si', '1', 'true', 'yes'], true) ? 'Si' : (in_array($key, ['no', '0', 'false'], true) ? 'No' : $value);
    };
    $values = [
      'id_inmueble' => $code, 'codigo_inmueble' => $code, 'matricula_inmobiliaria' => $v('matricula_inmobiliaria'), 'referencia_catastral' => $v('referencia_catastral'),
      'barrio' => $v('barrio'), 'direccion' => $v('direccion'), 'direccion_fisica' => $v('direccion_fisica'), 'area_construida' => $v('area_construida'),
      'area_privada' => $v('area_privada'), 'estrato' => $v('estrato'), 'propiedad_horizontal' => $siNo($v('pertenece_copropiedad') ?: $v('propiedad_horizontal')),
      'tiene_parqueadero' => $siNo($v('parqueadero')), 'tipo_parqueadero' => $v('tipo_parqueadero'), 'parqueaderos' => $v('parqueaderos'),
      'numero_parqueadero' => $v('numero_parqueadero'), 'tiene_deposito' => $siNo($v('deposito')), 'depositos' => $v('depositos'),
      'tiene_vigilancia' => $siNo($v('vigilancia')), 'energia' => $v('luz'), 'agua' => $v('agua'), 'gas' => $v('gas'),
      'precio_canon' => $v('precio_arriendo'), 'precio_administracion' => $v('precio_admin'),
      'servicios_publicos' => array_values(array_intersect(array_map(static fn(string $name): string => $name === 'Energía' ? 'Energia' : $name, MandateDocument::serviceList($row['servicios_publicos'] ?? '')), array_keys(MandateFields::SERVICES))),
    ];
    if ($v('id_copropiedad') !== '') {
      $values += $this->coOwnershipValues((int) $v('id_copropiedad'));
    }
    $owner = ctype_digit($v('id_propietario')) ? $this->owner((int) $v('id_propietario')) : null;
    if ($owner !== null) {
      $values += self::ownerValues(1, $owner);
    }
    return $values;
  }

  /** @return array<string,string> Campos de la copropiedad del formulario. */
  public function coOwnershipValues(int $id): array
  {
    $row = $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_copropiedades') . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
    if (!is_array($row)) {
      return [];
    }
    $v = static fn(string $key): string => trim((string) ($row[$key] ?? ''));
    return [
      'copropiedad_id' => (string) $id, 'nombre_copropiedad' => $v('copropiedad'), 'administrador' => $v('administrador'), 'nit_copropiedad' => $v('nit'),
      'direccion_copropiedad' => $v('direccion'), 'telefono_copropiedad' => $v('contacto'), 'correo_copropiedad' => $v('correo'),
      'tipo_cuenta_copropiedad' => $v('tipo_cuenta'), 'numero_cuenta_copropiedad' => $v('numero_cuenta'), 'banco_copropiedad' => $v('banco'),
      'indicativo_copropiedad' => $v('indicativo'),
    ];
  }

  /** @return array<string,mixed>|null */
  private function owner(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
  }

  /**
   * Campos del propietario N del formulario con su ficha (como autocompletarPropietario).
   * @param array<string,mixed> $owner
   * @return array<string,string>
   */
  public static function ownerValues(int $i, array $owner): array
  {
    $v = static fn(string $key): string => trim((string) ($owner[$key] ?? ''));
    $values = ['id_propietario' => (string) ($owner['_ID'] ?? ''), 'tipo_persona' => $v('tipo_persona'), 'nombre' => $v('nombre'), 'tipo_documento' => $v('tipo_documento'),
      'documento' => $v('documento'), 'expedicion' => $v('expedicion'), 'direccion' => $v('direccion'), 'correo' => $v('correo'), 'celular' => $v('celular'),
      'nombre_juridico' => $v('nombre_juridico'), 'documento_juridico' => $v('documento_juridico'), 'pais' => $v('pais'), 'indicativo' => $v('indicativo'),
      'tipo_giro' => $v('tipo_giro'), 'nombre_giro' => $v('nombre_giro'), 'titular' => $v('titular'), 'documento_cuenta' => $v('documento_cuenta'),
      'tipo_cuenta' => $v('tipo_cuenta'), 'numero_cuenta' => $v('numero_cuenta'), 'banco' => $v('banco'), 'correo_cuenta' => $v('correo_cuenta'),
      'fallecimiento' => $v('fallecimiento')];
    $out = [];
    foreach ($values as $key => $value) {
      $out[$key . '_' . $i] = $value;
    }
    return $out;
  }

  /**
   * Propietarios guardados por nombre o documento (para "¿Ya está registrado?").
   * @return list<array{id:string,label:string,campos:array<string,string>}>
   */
  public function searchOwners(string $query): array
  {
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 3) {
      return [];
    }
    $like = '%' . $query . '%';
    $rows = $this->db->getResults('SELECT * FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE nombre LIKE ? OR documento LIKE ? ORDER BY nombre ASC LIMIT 15', [$like, $like]);
    return array_values(array_map(static fn(array $row): array => ['id' => (string) $row['_ID'], 'label' => trim((string) ($row['nombre'] ?? '')) . (trim((string) ($row['documento'] ?? '')) !== '' ? ' · ' . trim((string) $row['documento']) : ''), 'campos' => self::ownerValues(0, $row)], $rows));
  }

  /** @return list<array{id:string,label:string,campos:array<string,string>}> Copropiedades por nombre o NIT. */
  public function searchCoOwnerships(string $query): array
  {
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 3) {
      return [];
    }
    $like = '%' . $query . '%';
    $rows = $this->db->getResults('SELECT `_ID`, copropiedad, nit FROM `' . $this->db->table('jet_cct_copropiedades') . '` WHERE copropiedad LIKE ? OR nit LIKE ? ORDER BY copropiedad ASC LIMIT 15', [$like, $like]);
    return array_map(fn(array $row): array => ['id' => (string) $row['_ID'], 'label' => trim((string) $row['copropiedad']) . (trim((string) ($row['nit'] ?? '')) !== '' ? ' · NIT ' . trim((string) $row['nit']) : ''), 'campos' => $this->coOwnershipValues((int) $row['_ID'])], $rows);
  }

  /** @return array{paises:list<array{pais:string,codigo:string}>,bancos:list<string>,barrios:list<string>} */
  public function options(): array
  {
    $list = function (string $table, string $column): array {
      $name = $this->db->table($table);
      if (!$this->schema->tableExists($name)) {
        return [];
      }
      return array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string) $value), $this->db->getCol("SELECT `{$column}` FROM `{$name}` ORDER BY `{$column}` ASC")))));
    };
    $countries = $this->schema->tableExists($this->db->table('jet_cct_paises')) ? $this->db->getResults('SELECT pais, codigo FROM `' . $this->db->table('jet_cct_paises') . '` ORDER BY pais ASC') : [];
    return [
      'paises' => array_map(static fn(array $row): array => ['pais' => trim((string) $row['pais']), 'codigo' => trim((string) $row['codigo'])], $countries),
      'bancos' => $list('jet_cct_bancos', 'banco'),
      'barrios' => $list('jet_cct_barrios', 'barrio'),
    ];
  }

  // ── Guardar ───────────────────────────────────────

  /**
   * Crea (sin $id) o edita el mandato con lo enviado en el formulario.
   * @param array<string,mixed> $post
   * @param array{ticket?:int,estudio?:int} $context Tarea y estudio de donde se crea.
   * @return array{id:int,firma:bool,reenviar:bool}
   */
  public function save(array $post, ?int $id = null, array $context = []): array
  {
    if (!$this->canEdit()) {
      throw new \RuntimeException('No tienes permiso para crear o editar mandatos.');
    }
    $mandate = null;
    if ($id !== null) {
      $mandate = $this->repository->find($id);
      if ($mandate === null) {
        throw new \InvalidArgumentException('El mandato no existe.');
      }
      if (!(new CommercialMandatesService($this->db, $this->policy))->canOpen($mandate)) {
        throw new \RuntimeException('No tienes permiso para este mandato.');
      }
      if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
        throw new \InvalidArgumentException('Este mandato ya está firmado: no se puede editar.');
      }
    }
    $post = self::clean($post);
    $this->validate($post);
    $employee = trim(Auth::employeeId());
    if ($employee === '') {
      throw new \RuntimeException('Tu sesión no tiene funcionario: vuelve a iniciar sesión.');
    }
    $property = trim((string) ($post['id_inmueble'] ?? ''));
    if ($id === null && $property === '') {
      throw new \InvalidArgumentException('Escribe el código del inmueble del mandato.');
    }

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->saveOwners($post, $employee);
      if (trim((string) ($post['id_propietario_1'] ?? '')) === '') {
        throw new \InvalidArgumentException('Falta el propietario #1: escribe su nombre y documento.');
      }
      $percentages = self::percentages($post);
      $credit = $this->calculator->credit($post);
      $post['copropiedad_id'] = ($post['propiedad_horizontal'] ?? '') === 'Si' ? (string) $this->saveCoOwnership($post) : '';

      $now = date('Y-m-d H:i:s');
      $columns = MandateFields::toRow($post) + $percentages;
      $columns['propietarios'] = (string) MandateDocument::ownerCount(['propietarios' => $post['cantidad_propietarios'] ?? '1']);
      $columns['id_propietario'] = (string) $post['id_propietario_1'];
      if ($id === null) {
        $creator = $this->repository->creator(['id_empleado' => $employee]);
        $study = (int) ($context['estudio'] ?? 0);
        $columns += [
          'sucursal' => '1', 'estado' => 'No enviado', 'cct_status' => 'publish', 'cct_author_id' => $employee, 'cct_created' => $now, 'cct_modified' => $now,
          'fecha' => (string) time(), 'id_inmueble' => $property, 'id_empleado' => $employee,
          'creador' => $creator['nombre'] ?: trim(Auth::user()), 'email_creador' => $creator['correo'], 'celular_creador' => $creator['celular'],
          'id_estudio_aseguradora' => $study > 0 ? (string) $study : '', 'numero_solicitud' => $study > 0 ? (string) ($this->study($study)['num_solicitud'] ?? '') : '',
          'id_ticket' => (int) ($context['ticket'] ?? 0) > 0 ? (string) $context['ticket'] : '',
        ];
        $this->db->insert($this->table(), MandateFields::existing($this->db, $this->table(), $columns));
        $id = (int) $this->db->lastInsertId();
        $this->db->insert($this->db->table('jet_cct_historial_del_inmueble'), MandateFields::existing($this->db, $this->db->table('jet_cct_historial_del_inmueble'), [
          'cct_status' => 'publish', 'cct_author_id' => $employee, 'cct_created' => $now, 'cct_modified' => $now,
          'id_inmueble' => $property, 'id_empleado' => $employee, 'funcionario' => $columns['creador'], 'fecha' => (string) time(),
          'tipo_reporte' => 'Contractual', 'observacion' => 'Se ha realizado contrato para administración de su inmueble.', 'id_ticket' => (string) ($context['ticket'] ?? ''),
        ]));
        if ($study > 0) {
          $this->db->update($this->db->table('jet_cct_estudios_aseguradoras'), ['id_contrato_mandato' => (string) $id], ['_ID' => $study]);
        }
      } else {
        $columns['cct_modified'] = $now;
        unset($columns['id_inmueble']);
        $this->db->update($this->table(), MandateFields::existing($this->db, $this->table(), $columns), ['_ID' => $id]);
        $property = trim((string) ($mandate['id_inmueble'] ?? ''));
      }
      $this->syncCredit($id, $credit, (string) $post['id_propietario_1'], $employee);
      $this->updateProperty($property, $post, $mandate === null ? $id : null);

      // Ya enviado a firma: el contrato de eSignatures se actualiza con lo editado (como edit-mandato), pero solo si
      // sigue cumpliendo lo necesario para la firma (el propietario aprobó esta versión y la hoja de cierre está
      // aprobada). Si el contenido cambió, el propietario debe aprobar los cambios y luego se usa "Reenviar a firma".
      // Antes del commit: si falla, no se guarda nada.
      $signed = false;
      $resend = false;
      if ($mandate !== null && trim((string) ($mandate['estado'] ?? '')) === 'Enviado' && $this->signature !== null && trim((string) ($mandate['id_esignatures'] ?? '')) !== '' && $this->signBlockers($id) !== []) {
        $resend = true;
      } elseif ($mandate !== null && trim((string) ($mandate['estado'] ?? '')) === 'Enviado' && $this->signature !== null && trim((string) ($mandate['id_esignatures'] ?? '')) !== '') {
        $fresh = $this->repository->find($id) ?? [];
        $contract = $this->signature->send(MandateFields::toForm($fresh) + $this->signerFields($fresh), $id, $credit, trim((string) $mandate['id_esignatures']));
        $this->db->update($this->table(), ['id_esignatures' => $contract], ['_ID' => $id]);
        $signed = true;
      }
      $pdo->commit();
      return ['id' => $id, 'firma' => $signed, 'reenviar' => $resend];
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
  }

  /**
   * Envía el mandato a firma (como enviar-mandato): firmado no se reenvía; si ya tenía contrato sin firmar, se modifica
   * o se reemplaza. El mandato queda "Enviado" con id_esignatures.
   * @return array{id:int,contrato:string}
   */
  public function sendToSign(int $id): array
  {
    if (!$this->policy->canAct(self::ACTION_SIGN)) {
      throw new \RuntimeException('No tienes permiso para enviar mandatos a firma.');
    }
    if ($this->signature === null) {
      throw new \RuntimeException('La firma digital no está configurada (ESIGNATURES_TOKEN).');
    }
    $mandate = $this->repository->find($id);
    if ($mandate === null || !(new CommercialMandatesService($this->db, $this->policy))->canOpen($mandate)) {
      throw new \RuntimeException('No tienes permiso para este mandato.');
    }
    if (trim((string) ($mandate['estado'] ?? '')) === 'Firmado') {
      throw new \InvalidArgumentException('Este mandato ya está firmado: no se puede volver a enviar.');
    }
    $blockers = $this->signBlockers($id);
    if ($blockers !== []) {
      throw new \InvalidArgumentException('Todavía no se puede enviar a firma: ' . implode(' ', $blockers));
    }
    $post = MandateFields::toForm($mandate) + $this->signerFields($mandate);
    $credit = $this->calculator->credit($post);
    $contract = $this->signature->send($post, $id, $credit, trim((string) ($mandate['id_esignatures'] ?? '')));
    $this->db->update($this->table(), MandateFields::existing($this->db, $this->table(), ['estado' => 'Enviado', 'id_esignatures' => $contract, 'cct_modified' => date('Y-m-d H:i:s')]), ['_ID' => $id]);
    return ['id' => $id, 'contrato' => $contract];
  }

  /** Lo que falta para enviar a firma (CommercialMandatesService::signBlockers). @return list<string> */
  public function signBlockers(int $id): array
  {
    $view = (new CommercialMandatesService($this->db, $this->policy, null, $this->repository))->viewData($id);
    return $view === null ? ['El mandato no existe.'] : $view['bloqueos'];
  }

  /** Quien firma como "quien elabora": el funcionario que creó el mandato. @param array<string,mixed> $mandate @return array<string,string> */
  private function signerFields(array $mandate): array
  {
    $creator = $this->repository->creator($mandate);
    return ['nombre_funcionario' => $creator['nombre'], 'correo_funcionario' => $creator['correo'], 'telefono_funcionario' => $creator['celular']];
  }

  private function table(): string
  {
    return $this->db->table('jet_cct_contrato_mandato');
  }

  /** @return array<string,mixed> */
  private function study(int $id): array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_estudios_aseguradoras') . '` WHERE `_ID` = ? LIMIT 1', [$id]) ?? [];
  }

  /** @param array<string,mixed> $post @return array<string,mixed> */
  private static function clean(array $post): array
  {
    $out = [];
    foreach ($post as $key => $value) {
      if (is_array($value)) {
        $out[$key] = array_values(array_map(static fn($item): string => mb_substr(trim((string) $item), 0, 200), array_filter($value, 'is_scalar')));
      } elseif (is_scalar($value)) {
        $out[$key] = mb_substr(trim(str_replace("\r", '', (string) $value)), 0, 5000);
      }
    }
    return $out;
  }

  /** Lo mínimo para que el contrato tenga sentido (el formulario valida el resto paso a paso). @param array<string,mixed> $post */
  private function validate(array $post): void
  {
    if (!in_array($post['tipo_contrato'] ?? '', MandateFields::CONTRACT_TYPES, true)) {
      throw new \InvalidArgumentException('Elige el tipo de contrato.');
    }
    $count = (int) ($post['cantidad_propietarios'] ?? 0);
    if ($count < 1 || $count > MandateFields::MAX_OWNERS) {
      throw new \InvalidArgumentException('Elige la cantidad de propietarios.');
    }
    for ($i = 1; $i <= $count; $i++) {
      if (trim((string) ($post['nombre_' . $i] ?? '')) === '' || trim((string) ($post['documento_' . $i] ?? '')) === '') {
        throw new \InvalidArgumentException('Falta el nombre o el documento del propietario #' . $i . '.');
      }
      if (trim((string) ($post['pais_' . $i] ?? '')) === '') {
        throw new \InvalidArgumentException('Elige el país del propietario #' . $i . '.');
      }
    }
    if (MandateCalculator::money($post['precio_canon'] ?? '') <= 0) {
      throw new \InvalidArgumentException('Escribe el canon de arrendamiento.');
    }
  }

  /**
   * Porcentajes de participación (porcentaje_1..6), como porcentajes_desde_post: cada uno mayor que 0, máximo 2
   * decimales y la suma 100. Un solo propietario: 100.
   * @param array<string,mixed> $post
   * @return array<string,string>
   */
  public static function percentages(array $post): array
  {
    $count = max(1, min(MandateFields::MAX_OWNERS, (int) ($post['cantidad_propietarios'] ?? 1)));
    $columns = [];
    if ($count === 1) {
      $columns['porcentaje_1'] = '100';
      for ($i = 2; $i <= MandateFields::MAX_OWNERS; $i++) {
        $columns['porcentaje_' . $i] = '';
      }
      return $columns;
    }
    $sum = 0.0;
    $values = [];
    for ($i = 1; $i <= $count; $i++) {
      $text = str_replace(',', '.', str_replace(['%', ' '], '', trim((string) ($post['porcentaje_' . $i] ?? ''))));
      if ($text === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1 || (float) $text <= 0) {
        throw new \InvalidArgumentException('Escribe el porcentaje de participación del propietario #' . $i . ': un número mayor que 0 con máximo 2 decimales.');
      }
      $values[$i] = $text;
      $sum += (float) $text;
    }
    if (abs($sum - 100) > 0.001) {
      throw new \InvalidArgumentException('Los porcentajes de participación suman ' . rtrim(rtrim(number_format($sum, 2, ',', '.'), '0'), ',') . ' %: deben sumar 100 %.');
    }
    for ($i = 1; $i <= MandateFields::MAX_OWNERS; $i++) {
      $columns['porcentaje_' . $i] = $i <= $count ? rtrim(rtrim(number_format((float) $values[$i], 2, '.', ''), '0'), '.') : '';
    }
    return $columns;
  }

  /** Documento comparable: sin espacios, puntos, comas ni guiones, y sin el dígito de verificación del NIT. */
  public static function normalizeDocument(string $value): string
  {
    $doc = strtoupper((string) preg_replace('/[\s.,]+/', '', trim($value)));
    $doc = (string) preg_replace('/-\d$/', '', $doc);
    return str_replace('-', '', $doc);
  }

  /**
   * Crea o actualiza en Propietarios a cada propietario del formulario y deja su id en id_propietario_N, como
   * propietarios_guardar_todos.
   * @param array<string,mixed> $post
   */
  private function saveOwners(array &$post, string $author): void
  {
    $table = $this->db->table('jet_cct_propietarios');
    $now = date('Y-m-d H:i:s');
    $count = max(1, min(MandateFields::MAX_OWNERS, (int) ($post['cantidad_propietarios'] ?? 1)));
    for ($i = 1; $i <= $count; $i++) {
      $v = static fn(string $field): string => trim((string) ($post[$field . '_' . $i] ?? ''));
      $name = $v('nombre');
      $document = $v('documento');
      if ($name === '' && $document === '') {
        continue;
      }
      $data = [
        'tipo_persona' => $v('tipo_persona'), 'nombre' => $name, 'tipo_documento' => $v('tipo_documento'), 'expedicion' => $v('expedicion'),
        'nombre_juridico' => $v('nombre_juridico'), 'documento_juridico' => $v('documento_juridico'), 'correo' => $v('correo'), 'celular' => $v('celular'),
        'indicativo' => $v('indicativo'), 'direccion' => $v('direccion'), 'pais' => $v('pais'), 'tipo_giro' => $v('tipo_giro'), 'nombre_giro' => $v('nombre_giro'),
        'titular' => $v('titular'), 'documento_cuenta' => $v('documento_cuenta'), 'tipo_cuenta' => $v('tipo_cuenta'), 'numero_cuenta' => $v('numero_cuenta'),
        'banco' => $v('banco'), 'correo_cuenta' => $v('correo_cuenta'),
      ];
      $found = $this->findOwner($v('id_propietario'), $document);
      if ($found !== null) {
        $changes = MandateFields::existing($this->db, $table, array_filter($data, static fn(string $value): bool => $value !== ''));
        if (!$found['by_id']) {
          // Encontrada por documento: el formulario puede traer datos viejos; solo se completa lo que la ficha tiene vacío.
          $current = $this->owner($found['id']) ?? [];
          $changes = array_filter($changes, static fn($value, $column): bool => trim((string) ($current[$column] ?? '')) === '', ARRAY_FILTER_USE_BOTH);
        }
        $existing = $this->owner($found['id']) ?? [];
        if ($document !== '' && trim((string) ($existing['documento'] ?? '')) === '') {
          $changes['documento'] = $document;
        }
        if ($changes !== []) {
          $this->db->update($table, $changes + MandateFields::existing($this->db, $table, ['cct_modified' => $now]), ['_ID' => $found['id']]);
        }
        $post['id_propietario_' . $i] = (string) $found['id'];
      } else {
        $this->db->insert($table, MandateFields::existing($this->db, $table, ['cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now, 'documento' => $document] + $data));
        $post['id_propietario_' . $i] = (string) $this->db->lastInsertId();
      }
    }
  }

  /**
   * Ficha del propietario: por su id si el documento coincide (o alguno está vacío); si no, por documento normalizado.
   * @return array{id:int,by_id:bool}|null
   */
  private function findOwner(string $id, string $document): ?array
  {
    $doc = self::normalizeDocument($document);
    if (ctype_digit($id) && (int) $id > 0) {
      $row = $this->owner((int) $id);
      if ($row !== null) {
        $stored = self::normalizeDocument((string) ($row['documento'] ?? ''));
        if ($doc === '' || $stored === '' || $stored === $doc) {
          return ['id' => (int) $id, 'by_id' => true];
        }
      }
    }
    if (strlen($doc) < 4) {
      return null;
    }
    // Los documentos guardados tienen puntos, guiones y dígito de verificación: se buscan por sus dígitos y se comparan normalizados.
    $digits = (string) preg_replace('/\D/', '', $doc);
    foreach ($this->db->getResults('SELECT `_ID`, documento FROM `' . $this->db->table('jet_cct_propietarios') . '` WHERE REPLACE(REPLACE(REPLACE(documento, \'.\', \'\'), \'-\', \'\'), \' \', \'\') LIKE ? ORDER BY `_ID`', ['%' . $digits . '%']) as $row) {
      if (self::normalizeDocument((string) $row['documento']) === $doc) {
        return ['id' => (int) $row['_ID'], 'by_id' => false];
      }
    }
    return null;
  }

  /** Crea o actualiza la copropiedad (por id o por NIT), como copropiedades_guardar. @param array<string,mixed> $post */
  private function saveCoOwnership(array $post): int
  {
    $table = $this->db->table('jet_cct_copropiedades');
    $v = static fn(string $key): string => trim((string) ($post[$key] ?? ''));
    $data = [
      'copropiedad' => $v('nombre_copropiedad'), 'administrador' => $v('administrador'), 'nit' => $v('nit_copropiedad'), 'direccion' => $v('direccion_copropiedad'),
      'barrio' => $v('barrio'), 'contacto' => $v('telefono_copropiedad'), 'correo' => $v('correo_copropiedad'), 'tipo_cuenta' => $v('tipo_cuenta_copropiedad'),
      'numero_cuenta' => $v('numero_cuenta_copropiedad'), 'banco' => $v('banco_copropiedad'), 'indicativo' => $v('indicativo_copropiedad'), 'id_inmueble' => $v('id_inmueble'),
    ];
    $found = 0;
    if (ctype_digit($v('copropiedad_id')) && (int) $v('copropiedad_id') > 0) {
      $found = (int) $this->db->getVar('SELECT `_ID` FROM `' . $table . '` WHERE `_ID` = ? LIMIT 1', [(int) $v('copropiedad_id')]);
    } elseif ($v('nit_copropiedad') !== '') {
      $found = (int) $this->db->getVar('SELECT `_ID` FROM `' . $table . '` WHERE nit = ? LIMIT 1', [$v('nit_copropiedad')]);
    }
    if ($found > 0) {
      $this->db->update($table, MandateFields::existing($this->db, $table, $data + ['cct_modified' => date('Y-m-d H:i:s')]), ['_ID' => $found]);
      return $found;
    }
    if ($data['copropiedad'] === '') {
      throw new \InvalidArgumentException('Escribe el nombre de la copropiedad.');
    }
    $this->db->insert($table, MandateFields::existing($this->db, $table, ['cct_status' => 'publish', 'cct_created' => date('Y-m-d H:i:s'), 'cct_modified' => date('Y-m-d H:i:s')] + $data));
    return (int) $this->db->lastInsertId();
  }

  /**
   * Un solo crédito por mandato (id_item_financiado), a nombre del propietario #1: se actualiza, se crea o se elimina,
   * como credito_sincronizar.
   * @param array<string,string>|null $credit
   */
  private function syncCredit(int $id, ?array $credit, string $owner, string $author): void
  {
    $table = $this->db->table('jet_cct_creditos');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $existing = array_map('intval', $this->db->getCol('SELECT `_ID` FROM `' . $table . "` WHERE id_item_financiado = ? AND solicitante = 'Propietario' AND motivo LIKE ? ORDER BY `_ID`", [(string) $id, MandateCalculator::CREDIT_REASON . '%']));
    if ($credit === null) {
      foreach ($existing as $creditId) {
        $this->db->delete($table, ['_ID' => $creditId]);
      }
      return;
    }
    $now = date('Y-m-d H:i:s');
    $data = $credit + ['id_usuario' => $owner];
    if ($existing !== []) {
      $this->db->update($table, MandateFields::existing($this->db, $table, $data + ['cct_modified' => $now]), ['_ID' => $existing[0]]);
      return;
    }
    $this->db->insert($table, MandateFields::existing($this->db, $table, $data + [
      'fecha' => (string) time(), 'solicitante' => 'Propietario', 'id_item_financiado' => (string) $id,
      'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now,
    ]));
  }

  /**
   * El inmueble queda con los datos del mandato (solo si existe), como inmuebles_upsert_por_codigo. Al crear, también
   * su id_contrato_mandato.
   * @param array<string,mixed> $post
   */
  private function updateProperty(string $code, array $post, ?int $newMandate): void
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if ($code === '' || !$this->db->getVar('SELECT codigo FROM `' . $table . '` WHERE codigo = ? LIMIT 1', [$code])) {
      return;
    }
    $v = static fn(string $key): string => trim((string) ($post[$key] ?? ''));
    $data = [
      'barrio' => $v('barrio'), 'direccion' => $v('direccion'), 'direccion_fisica' => $v('direccion_fisica'), 'area_construida' => $v('area_construida'),
      'area_privada' => $v('area_privada'), 'precio_arriendo' => $v('precio_canon'), 'precio_admin' => $v('precio_administracion'),
      'referencia_catastral' => $v('referencia_catastral'), 'id_propietario' => $v('id_propietario_1'), 'propietario' => $v('nombre_1'),
      'pertenece_copropiedad' => $v('propiedad_horizontal'), 'id_copropiedad' => $v('copropiedad_id'), 'copropiedad' => $v('nombre_copropiedad'),
      'parqueadero' => $v('tiene_parqueadero'), 'parqueaderos' => $v('parqueaderos'), 'tipo_parqueadero' => $v('tipo_parqueadero'),
      'numero_parqueadero' => $v('numero_parqueadero'), 'deposito' => $v('tiene_deposito'), 'depositos' => $v('depositos'), 'vigilancia' => $v('tiene_vigilancia'),
      'servicios_publicos' => MandateFields::toRow($post)['servicios_publicos'], 'luz' => $v('energia'), 'agua' => $v('agua'), 'gas' => $v('gas'),
      'matricula_inmobiliaria' => $v('matricula_inmobiliaria'),
    ];
    if ($newMandate !== null) {
      $data['id_contrato_mandato'] = (string) $newMandate;
    }
    $this->db->update($table, MandateFields::existing($this->db, $table, $data), ['codigo' => $code]);
  }

  private static function trimNumber(float $value): string
  {
    return $value == 0.0 ? '' : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
  }
}
