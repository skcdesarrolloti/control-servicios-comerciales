<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Campos del formulario del mandato ↔ columnas de wp_jet_cct_contrato_mandato.
 *
 * Copia del mapeo de formularios/{crear,edit,enviar}-mandato/procesar_contrato.php ($cols) y de su relleno inverso
 * (edit-mandato/js/rellenar-datos.js): mantener iguales. Los nombres de los campos son los del formulario de WordPress
 * (los que usa comun/mandato_firma.php para llenar la plantilla de eSignatures), así el mismo arreglo sirve para
 * guardar, para editar y para enviar a firma.
 */
final class MandateFields
{
  public const MAX_OWNERS = 6;

  /** Campo del propietario N en el formulario => columna N del mandato. */
  public const OWNER = [
    'calidad' => 'calidad', 'tipo_persona' => 'tipo', 'nombre' => 'nombre', 'tipo_documento' => 'tipo_documento',
    'documento' => 'documento', 'expedicion' => 'lugar', 'nombre_juridico' => 'empresa', 'documento_juridico' => 'nit',
    'direccion' => 'direccion', 'correo' => 'correo', 'celular' => 'celular', 'fallecimiento' => 'fallecimiento',
    'tipo_giro' => 'tipo_giro', 'nombre_giro' => 'nombre_giro', 'titular' => 'titular_cuenta', 'documento_cuenta' => 'documento_cuenta',
    'tipo_cuenta' => 'tipo_cuenta', 'numero_cuenta' => 'numero_cuenta', 'banco' => 'banco_cuenta', 'correo_cuenta' => 'correo_cuenta',
    'id_propietario' => 'id_propietario_nuevo', 'indicativo' => 'indicativo',
  ];

  /** Campo del formulario => columna del mandato (sin propietarios ni servicios_publicos). */
  public const FIELDS = [
    'existe_simi' => 'existe_simi', 'codigo_simi' => 'codigo_simi', 'mismo_propietario' => 'mismo_propietario', 'codigo_inmueble' => 'codigo_inmueble',
    'escritura' => 'escritura', 'fecha_inmueble' => 'fecha_inmueble', 'circulo_notarial' => 'circulo_notarial', 'matricula_inmobiliaria' => 'matricula_inmobiliaria',
    'referencia_catastral' => 'referencia_catastral', 'barrio' => 'barrio', 'direccion' => 'direccion', 'direccion_fisica' => 'direccion_fisica',
    'area_construida' => 'area_construida', 'propiedad_horizontal' => 'propiedad_horizontal', 'estrato' => 'estrato', 'tiene_parqueadero' => 'tiene_parqueadero',
    'tipo_parqueadero' => 'tipo_parqueadero', 'parqueaderos' => 'parqueadero', 'numero_parqueadero' => 'numero_parqueadero', 'tiene_deposito' => 'tiene_deposito',
    'depositos' => 'depositos', 'tiene_vigilancia' => 'tiene_vigilancia', 'observaciones' => 'observaciones',
    // Copropiedad
    'responsable_admin' => 'cargo', 'copropiedad_id' => 'id_copropiedad', 'nombre_copropiedad' => 'copropiedad', 'administrador' => 'administrador_co',
    'nit_copropiedad' => 'nit_co', 'direccion_copropiedad' => 'direccion_co', 'telefono_copropiedad' => 'contacto_co', 'correo_copropiedad' => 'correo_co',
    'tipo_cuenta_copropiedad' => 'tipo_cuenta_co', 'numero_cuenta_copropiedad' => 'numero_cuenta_co', 'banco_copropiedad' => 'banco_cuenta_co',
    // Servicios
    'energia' => 'energia', 'agua' => 'agua', 'gas' => 'gas', 'tv' => 'tv', 'telefono' => 'telefono', 'internet' => 'internet', 'otros_servicios' => 'otros',
    // Valores y comisión
    'precio_canon' => 'precio', 'precio_administracion' => 'administracion', 'tiene_iva_canon' => 'incluye_iva', 'iva_canon' => 'iva_total_precio',
    'tasa_comision' => 'tasa_comision', 'comision_sin_iva' => 'comision', 'iva_comision' => 'iva_comision', 'total_comision' => 'total_comision',
    // Garantía inmobiliaria y de servicios
    'incluye_garantia' => 'incluye_garantia', 'aseguradora' => 'aseguradora', 'meses_cobertura_garantia' => 'meses_cobertura_garantia',
    'tasa_garantia' => 'tasa_garantia', 'valor_garantia' => 'precio_mes_garantia',
    'incluye_garantia_servicios' => 'incluye_garantia_servicios', 'valor_asegurado_garantia_servicios' => 'precio_garantia_sevicios',
    'cobertura_garantia_servicios' => 'cobertura_servicios', 'tasa_garantia_servicios' => 'tasa_servicios', 'forma_pago_garantia_servicios' => 'pago_servicios',
    'prima_mes_garantia_servicios' => 'precio_mes_servicios', 'prima_anual_garantia_servicios' => 'precio_anual_servicios',
    // Cláusula penal
    'incluye_clausula_penal' => 'incluye_clausula', 'tasa_clausula_penal' => 'tasa_clausula', 'amparo_clausula_penal' => 'valor_clausula', 'precio_clausula_penal' => 'precio_clausula',
    // Berkley
    'tasa_berkley' => 'tasa_berkley', 'prima_mes_berkley' => 'precio_mes_berkley', 'prima_anual_berkley' => 'precio_anual_berkley', 'prima_canon_berkley' => 'prima_canon_berkley',
    'incluye_servicios_berkley' => 'incluye_servicios_berkley', 'valor_asegurado_berkley' => 'valor_asegurado_berkley', 'prima_servicios_berkley' => 'prima_servicios_berkley',
    'expedicion_berkley' => 'gastos_expedicion_berkley', 'forma_pago_berkley' => 'pago_berkley',
    // Seguros mundial
    'tasa_seguros_mundial' => 'tasa_seguros_mundial', 'prima_mes_seguros_mundial' => 'prima_mes_seguros_mundial', 'total_prima_seguros_mundial' => 'total_prima_seguros_mundial',
    'prima_canon_seguros_mundial' => 'prima_canon_seguros_mundial', 'incluye_servicios_seguros_mundial' => 'incluye_servicios_seguros_mundial',
    'valor_asegurado_seguros_mundial' => 'valor_asegurado_seguros_mundial', 'expedicion_seguros_mundial' => 'expedicion_seguros_mundial', 'forma_pago_seguros_mundial' => 'forma_pago_seguros_mundial',
    // Acuerdos y condiciones
    'acuerdo' => 'acuerdo', 'anos' => 'anos', 'meses' => 'meses', 'dias_gracia' => 'dias_gracia', 'adendas' => 'adendas', 'nota_equipos' => 'nota_equipos',
    'equipos_entregados' => 'equipos_entregados', 'incremento_pactado' => 'incremento_pactado', 'duracion' => 'duracion', 'rete_fuente' => 'rete_fuente',
    'rete_ica' => 'rete_ica', 'rete_iva' => 'rete_iva', 'adulto_mayor' => 'adulto_mayor', 'pro_universitario' => 'pro_universitario',
    'recreacion_deporte' => 'recreacion_deporte', 'tipo_contrato' => 'tipo_contrato', 'clasificacion' => 'clasificacion',
  ];

  public const CONTRACT_TYPES = ['Por defecto', 'Berkley', 'Seguros mundial'];
  public const CLASSIFICATIONS = ['1- Sin fecha de entrega definida' => '1- Sin fecha de entrega definida', '2- Cinco (5) dias habiles' => '2- Cinco días hábiles', '3- Dos (2) dias habiles' => '3- Dos días hábiles', '4- Un (1) dia habil' => '4- Un (1) día hábil', '5- Entrega inmediata' => '5- Entrega inmediata'];
  public const DURATIONS = ['3 Meses', '6 Meses', '12 Meses', '14 Meses', '16 Meses', '18 Meses', '24 Meses', '32 Meses', '36 Meses', '48 Meses', '60 Meses'];
  public const SERVICES = ['Energia' => 'Energía', 'Agua' => 'Agua', 'Gas' => 'Gas'];

  /** @var array<string,array<string,bool>> */
  private static array $columns = [];

  /**
   * Solo las columnas que existen en la tabla (como filtrar_columnas de formularios/comun/propietarios.php): guardar no
   * falla mientras una columna nueva no se haya creado. Lee information_schema una vez por tabla.
   * @param array<string,mixed> $data
   * @return array<string,mixed>
   */
  public static function existing(\SCM\Core\Database $db, string $table, array $data): array
  {
    $key = spl_object_id($db) . ':' . $table;
    if (!isset(self::$columns[$key])) {
      self::$columns[$key] = array_fill_keys(array_map('strval', $db->getCol('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])), true);
    }
    return array_intersect_key($data, self::$columns[$key]);
  }

  /** Acuerdos: años (6 filas, año inicial, final e IPC) y meses (4 filas, mes inicial, final y valor). */
  public static function agreementFields(): array
  {
    $fields = [];
    for ($i = 1; $i <= 6; $i++) {
      foreach (['ano_inicial', 'ano_final', 'ipc'] as $name) {
        $fields[] = $name . '_' . $i;
      }
    }
    for ($i = 1; $i <= 4; $i++) {
      foreach (['mes_inicial', 'mes_final', 'valor'] as $name) {
        $fields[] = $name . '_' . $i;
      }
    }
    return $fields;
  }

  /**
   * Columnas del mandato con lo enviado en el formulario (sin los datos de creación ni el estado).
   * @param array<string,mixed> $post
   * @return array<string,string>
   */
  public static function toRow(array $post): array
  {
    $v = static fn(string $key): string => is_scalar($post[$key] ?? null) ? trim((string) $post[$key]) : '';
    $row = ['propietarios' => $v('cantidad_propietarios')];
    for ($i = 1; $i <= self::MAX_OWNERS; $i++) {
      foreach (self::OWNER as $field => $column) {
        $row[$column . '_' . $i] = $v($field . '_' . $i);
      }
    }
    foreach (self::FIELDS as $field => $column) {
      $row[$column] = $v($field);
    }
    // Como el formulario de WordPress: la vigilancia queda en las dos columnas.
    $row['vigilancia'] = $v('tiene_vigilancia');
    foreach (self::agreementFields() as $field) {
      $row[$field] = $v($field);
    }
    $services = array_values(array_filter(array_map(static fn($value): string => is_scalar($value) ? trim((string) $value) : '', (array) ($post['servicios_publicos'] ?? [])), static fn(string $value): bool => isset(self::SERVICES[$value])));
    $row['servicios_publicos'] = serialize($services);
    return $row;
  }

  /**
   * El formulario con los datos de un mandato guardado (para editarlo o enviarlo a firma).
   * @param array<string,mixed> $row
   * @return array<string,mixed>
   */
  public static function toForm(array $row): array
  {
    $v = static fn(string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
    $post = ['cantidad_propietarios' => (string) MandateDocument::ownerCount($row), 'id_mandato' => $v('_ID'), 'id_inmueble' => $v('id_inmueble')];
    for ($i = 1; $i <= self::MAX_OWNERS; $i++) {
      foreach (self::OWNER as $field => $column) {
        $post[$field . '_' . $i] = $v($column . '_' . $i);
      }
      $post['porcentaje_' . $i] = $v('porcentaje_' . $i);
    }
    foreach (self::FIELDS as $field => $column) {
      $post[$field] = $v($column);
    }
    if ($post['tiene_vigilancia'] === '') {
      $post['tiene_vigilancia'] = $v('vigilancia');
    }
    if ($post['iva_canon'] === '') {
      $post['iva_canon'] = $v('iva_precio');
    }
    foreach (self::agreementFields() as $field) {
      $post[$field] = $v($field);
    }
    $post['servicios_publicos'] = array_values(array_intersect(array_map(static fn(string $name): string => $name === 'Energía' ? 'Energia' : $name, MandateDocument::serviceList($row['servicios_publicos'] ?? '')), array_keys(self::SERVICES)));
    return $post;
  }
}
