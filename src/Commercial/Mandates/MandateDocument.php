<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * El contrato de mandato como lo lee el propietario antes de firmar: las secciones del contrato con los datos guardados
 * en wp_jet_cct_contrato_mandato.
 *
 * Copia del formato de formularios/comun/mandato_firma.php (firma_valores, firma_tabla_propietarios y
 * firma_tabla_acuerdos), que llena la plantilla de eSignatures desde el formulario: aquí se lee la fila guardada, con
 * sus nombres de columna (calidad_N, lugar_N, empresa_N, precio, comision…). Mantener ambos iguales: montos con $
 * ($1.800.000), tasas con % (2,45 %), documentos con su tipo (C.C. 123) y, si una garantía o la cláusula penal está en
 * "No", sus datos no se muestran.
 */
final class MandateDocument
{
  public const SECTIONS = [
    'propietarios' => 'Propietarios',
    'inmueble' => 'Inmueble',
    'copropiedad' => 'Copropiedad',
    'servicios' => 'Servicios públicos',
    'canon' => 'Canon y comisión',
    'garantias' => 'Garantías y seguros',
    'condiciones' => 'Condiciones del contrato',
    'general' => 'Comentario general',
  ];

  public const MAX_OWNERS = 6;

  /**
   * @param array<string,mixed> $row Fila de wp_jet_cct_contrato_mandato.
   * @return list<array{key:string,title:string,items:list<array{0:string,1:string}>,owners?:list<array{title:string,items:list<array{0:string,1:string}>}>,table?:array{head:list<string>,rows:list<list<string>>},text?:list<array{0:string,1:string}>}>
   */
  public static function sections(array $row): array
  {
    $v = static fn(string $key): string => self::text($row[$key] ?? '');
    $sections = [];

    $owners = [];
    foreach (self::owners($row) as $i => $owner) {
      $owners[] = ['title' => 'Propietario ' . $i . ($owner['nombre'] !== '' ? ': ' . $owner['nombre'] : ''), 'items' => $owner['items']];
    }
    $sections[] = ['key' => 'propietarios', 'title' => self::SECTIONS['propietarios'], 'items' => [], 'owners' => $owners];

    $parking = $v('tiene_parqueadero') === 'Si'
      ? self::join([$v('tipo_parqueadero'), $v('parqueadero') !== '' ? 'Cantidad: ' . $v('parqueadero') : '', $v('numero_parqueadero') !== '' ? 'No. ' . $v('numero_parqueadero') : ''], ' · ') ?: 'Sí'
      : ($v('tiene_parqueadero') === 'No' ? 'No' : '');
    $storage = $v('tiene_deposito') === 'Si' ? ($v('depositos') !== '' ? 'Sí · ' . $v('depositos') : 'Sí') : $v('tiene_deposito');
    $sections[] = ['key' => 'inmueble', 'title' => self::SECTIONS['inmueble'], 'items' => self::items([
      'Código del inmueble' => $v('id_inmueble'),
      'Dirección' => $v('direccion'),
      'Dirección física' => $v('direccion_fisica'),
      'Barrio' => $v('barrio'),
      'Estrato' => $v('estrato'),
      'Área construida' => $v('area_construida') !== '' ? $v('area_construida') . ' m²' : '',
      'Escritura pública No.' => $v('escritura'),
      'Fecha de la escritura' => self::date($v('fecha_inmueble')),
      'Círculo notarial' => $v('circulo_notarial'),
      'Matrícula inmobiliaria' => $v('matricula_inmobiliaria'),
      'Referencia catastral' => $v('referencia_catastral'),
      'Propiedad horizontal' => $v('propiedad_horizontal'),
      'Parqueadero' => $parking,
      'Depósito' => $storage,
      'Vigilancia' => $v('tiene_vigilancia') !== '' ? $v('tiene_vigilancia') : $v('vigilancia'),
      'Equipos entregados' => $v('equipos_entregados'),
      'Nota sobre los equipos' => $v('nota_equipos'),
      'Observaciones' => $v('observaciones'),
      '¿Existe en SIMI?' => $v('existe_simi'),
      'Código SIMI' => $v('existe_simi') === 'Si' ? $v('codigo_simi') : '',
    ])];

    if ($v('propiedad_horizontal') === 'Si') {
      $sections[] = ['key' => 'copropiedad', 'title' => self::SECTIONS['copropiedad'], 'items' => self::items([
        'Copropiedad' => $v('copropiedad'),
        'NIT' => $v('nit_co'),
        'Administrador/a' => $v('administrador_co'),
        'Dirección' => $v('direccion_co'),
        'Teléfono' => $v('contacto_co'),
        'Correo' => $v('correo_co'),
        'Cuenta para el pago de la administración' => self::join([$v('tipo_cuenta_co'), $v('numero_cuenta_co'), $v('banco_cuenta_co')], ' · '),
      ])];
    }

    $included = self::serviceList($row['servicios_publicos'] ?? '');
    $sections[] = ['key' => 'servicios', 'title' => self::SECTIONS['servicios'], 'items' => self::items([
      'Servicios del inmueble' => implode(', ', $included),
      'Energía (No. de cuenta)' => $v('energia'),
      'Agua (No. de cuenta)' => $v('agua'),
      'Gas (No. de cuenta)' => $v('gas'),
      'Televisión' => $v('tv'),
      'Teléfono' => $v('telefono'),
      'Internet' => $v('internet'),
      'Otros' => $v('otros'),
    ])];

    $sections[] = ['key' => 'canon', 'title' => self::SECTIONS['canon'], 'items' => self::items([
      'Canon de arrendamiento' => self::money($v('precio')),
      '¿El canon incluye IVA?' => $v('incluye_iva'),
      'IVA del canon' => $v('incluye_iva') === 'Si' ? self::money($v('iva_total_precio') !== '' ? $v('iva_total_precio') : $v('iva_precio')) : '',
      'Valor de la administración' => self::money($v('administracion')),
      'Quién paga la administración' => $v('cargo'),
      'Comisión de administración' => self::percent($v('tasa_comision')),
      'Comisión sin IVA' => self::money($v('comision')),
      'IVA de la comisión' => self::money($v('iva_comision')),
      'Total de la comisión' => self::money($v('total_comision')),
    ])];

    $sections[] = ['key' => 'garantias', 'title' => self::SECTIONS['garantias'], 'items' => self::items(self::guarantees($row))];

    $agreement = self::agreement($row);
    $condition = ['key' => 'condiciones', 'title' => self::SECTIONS['condiciones'], 'items' => self::items([
      'Tipo de contrato' => $v('tipo_contrato'),
      'Duración' => $v('duracion'),
      'Incremento pactado' => $v('incremento_pactado'),
      'Días de gracia' => $v('dias_gracia'),
      'Retención en la fuente' => self::percent($v('rete_fuente')),
      'Retención de ICA' => self::percent($v('rete_ica')),
      'Retención de IVA' => self::percent($v('rete_iva')),
      'Estampilla adulto mayor' => self::percent($v('adulto_mayor')),
      'Estampilla pro universitario' => self::percent($v('pro_universitario')),
      'Estampilla recreación y deporte' => self::percent($v('recreacion_deporte')),
      'Acuerdo del canon' => $v('acuerdo'),
      'Clasificación' => $v('clasificacion'),
    ])];
    if ($agreement !== null) {
      $condition['table'] = $agreement;
    }
    if ($v('adendas') !== '') {
      $condition['text'] = [['Adendas', self::text($row['adendas'] ?? '', true)]];
    }
    $sections[] = $condition;
    return $sections;
  }

  /**
   * Datos de cada propietario del mandato (1..propietarios), como la tabla {{tabla_propietarios}}.
   * @param array<string,mixed> $row
   * @return array<int,array{nombre:string,correo:string,celular:string,indicativo:string,id:string,items:list<array{0:string,1:string}>}>
   */
  public static function owners(array $row): array
  {
    $count = self::ownerCount($row);
    $owners = [];
    for ($i = 1; $i <= $count; $i++) {
      $v = static fn(string $key): string => self::text($row[$key . '_' . $i] ?? '');
      $legal = mb_strtolower($v('tipo'), 'UTF-8') === 'juridica' || mb_strtolower($v('tipo'), 'UTF-8') === 'jurídica' || $v('empresa') !== '' || $v('nit') !== '';
      $percent = $v('porcentaje') !== '' ? self::percent($v('porcentaje')) : ($count === 1 ? '100 %' : '');
      $phone = $v('celular') !== '' ? trim(($v('indicativo') !== '' && !str_starts_with($v('celular'), '+') ? $v('indicativo') . ' ' : '') . $v('celular')) : '';
      $owners[$i] = [
        'nombre' => $v('nombre'),
        'correo' => $v('correo'),
        'celular' => $v('celular'),
        'indicativo' => $v('indicativo'),
        'id' => $v('id_propietario_nuevo'),
        'items' => self::items(array_merge([
          'Calidad' => $v('calidad'),
          'Tipo de persona' => $v('tipo'),
          'Nombre / Empresa' => $v('nombre'),
          'Documento de identificación' => self::document($v('tipo_documento'), $v('documento')),
          'Lugar de expedición' => $v('lugar'),
        ], $legal ? [
          'Representante legal' => $v('empresa'),
          'Documento del representante legal' => $v('nit'),
        ] : [], [
          'Dirección de correspondencia' => $v('direccion'),
          'Correo electrónico' => $v('correo'),
          'Celular' => $phone,
          'En caso de fallecimiento se designa a' => $v('fallecimiento'),
          '% de participación' => $percent,
          'Tipo de giro' => $v('tipo_giro'),
          'A nombre de quien sale el giro' => $v('nombre_giro'),
          'Titular de la cuenta' => $v('titular_cuenta'),
          'Identificación del titular' => $v('documento_cuenta'),
          'Tipo de cuenta' => $v('tipo_cuenta'),
          'Número de cuenta' => $v('numero_cuenta'),
          'Banco' => $v('banco_cuenta'),
          'Correo para recibir facturas' => $v('correo_cuenta'),
        ])),
      ];
    }
    return $owners;
  }

  /** @param array<string,mixed> $row */
  public static function ownerCount(array $row): int
  {
    $count = (int) self::text($row['propietarios'] ?? '');
    if ($count < 1) {
      $count = 1;
      for ($i = self::MAX_OWNERS; $i >= 1; $i--) {
        if (self::text($row['nombre_' . $i] ?? '') !== '') {
          $count = $i;
          break;
        }
      }
    }
    return max(1, min(self::MAX_OWNERS, $count));
  }

  /**
   * Huella de lo que lee el propietario: si el mandato cambia después de una aprobación u observación, la huella cambia.
   * @param array<string,mixed> $row
   */
  public static function fingerprint(array $row): string
  {
    return hash('sha256', (string) json_encode(self::sections($row), JSON_UNESCAPED_UNICODE));
  }

  /**
   * Sección 6 (garantías) según el tipo de contrato, como FIRMA_CAMPOS_TIPO: lo que está en "No" no muestra sus datos.
   * @param array<string,mixed> $row
   * @return array<string,string>
   */
  private static function guarantees(array $row): array
  {
    $v = static fn(string $key): string => self::text($row[$key] ?? '');
    $type = $v('tipo_contrato');
    if ($type === 'Berkley') {
      $services = $v('incluye_servicios_berkley') === 'Si';
      return [
        'Póliza' => 'Berkley',
        'Tasa Berkley' => self::percent($v('tasa_berkley')),
        'Prima del canon' => self::money($v('prima_canon_berkley')),
        '¿Incluye servicios públicos?' => $v('incluye_servicios_berkley'),
        'Valor asegurado de servicios' => $services ? self::money($v('valor_asegurado_berkley')) : '',
        'Prima de servicios' => $services ? self::money($v('prima_servicios_berkley')) : '',
        'Gastos de expedición' => self::money($v('gastos_expedicion_berkley')),
        'Forma de pago' => self::installments($v('pago_berkley')),
        'Prima mensual' => self::money($v('precio_mes_berkley')),
        'Prima anual' => self::money($v('precio_anual_berkley')),
      ];
    }
    if ($type === 'Seguros mundial') {
      $services = $v('incluye_servicios_seguros_mundial') === 'Si';
      return [
        'Póliza' => 'Seguros Mundial',
        'Tasa Seguros Mundial' => self::percent($v('tasa_seguros_mundial')),
        'Prima del canon' => self::money($v('prima_canon_seguros_mundial')),
        '¿Incluye servicios públicos?' => $v('incluye_servicios_seguros_mundial'),
        'Valor asegurado de servicios' => $services ? self::money($v('valor_asegurado_seguros_mundial')) : '',
        'Gastos de expedición' => self::money($v('expedicion_seguros_mundial')),
        'Forma de pago' => $services ? self::installments($v('forma_pago_seguros_mundial')) : '',
        'Prima mensual' => self::money($v('prima_mes_seguros_mundial')),
        'Prima total' => self::money($v('total_prima_seguros_mundial')),
      ];
    }
    $guarantee = $v('incluye_garantia') === 'Si';
    $services = $v('incluye_garantia_servicios') === 'Si';
    $penalty = $v('incluye_clausula') === 'Si';
    return [
      '¿Incluye garantía de arrendamiento?' => $v('incluye_garantia'),
      'Aseguradora' => $guarantee ? $v('aseguradora') : '',
      'Tasa de la garantía' => $guarantee ? self::percent($v('tasa_garantia')) : '',
      'Valor mensual de la garantía' => $guarantee ? self::money($v('precio_mes_garantia')) : '',
      'Cobertura de la garantía' => $guarantee ? $v('meses_cobertura_garantia') : '',
      '¿Incluye garantía de servicios públicos?' => $v('incluye_garantia_servicios'),
      'Valor asegurado de servicios' => $services ? self::money($v('precio_garantia_sevicios')) : '',
      'Cobertura de servicios' => $services ? $v('cobertura_servicios') : '',
      'Forma de pago de servicios' => $services ? self::installments($v('pago_servicios')) : '',
      'Prima mensual de servicios' => $services ? self::money($v('precio_mes_servicios')) : '',
      'Prima total de servicios' => $services ? self::money($v('precio_anual_servicios')) : '',
      '¿Incluye cláusula penal?' => $v('incluye_clausula'),
      'Tasa de la cláusula penal' => $penalty ? self::percent($v('tasa_clausula')) : '',
      'Valor de la cláusula penal' => $penalty ? self::money($v('precio_clausula')) : '',
    ];
  }

  /**
   * Tabla de acuerdos del tipo elegido y solo con las filas llenas, como firma_tabla_acuerdos.
   * @param array<string,mixed> $row
   * @return array{head:list<string>,rows:list<list<string>>}|null
   */
  private static function agreement(array $row): ?array
  {
    $v = static fn(string $key): string => self::text($row[$key] ?? '');
    $type = $v('acuerdo');
    if ($type === 'Anual') {
      $head = ['Año inicial', 'Año final', 'IPC'];
      $line = static fn(int $i): array => [$v('ano_inicial_' . $i), $v('ano_final_' . $i), $v('ipc_' . $i)];
    } elseif ($type === 'Mensual') {
      $head = ['Mes inicial', 'Mes final', 'Valor del canon'];
      $line = static fn(int $i): array => [$v('mes_inicial_' . $i), $v('mes_final_' . $i), self::money($v('valor_' . $i))];
    } else {
      return null;
    }
    $rows = [];
    for ($i = 1; $i <= 6; $i++) {
      $cells = $line($i);
      if (implode('', $cells) !== '') {
        $rows[] = array_map(static fn(string $cell): string => $cell === '' ? 'No aplica' : $cell, $cells);
      }
    }
    return $rows === [] ? null : ['head' => $head, 'rows' => $rows];
  }

  /**
   * Quita lo vacío: lo que no aplica no se muestra al propietario.
   * @param array<string,string> $items
   * @return list<array{0:string,1:string}>
   */
  private static function items(array $items): array
  {
    $out = [];
    foreach ($items as $label => $value) {
      if (trim($value) !== '') {
        $out[] = [(string) $label, $value];
      }
    }
    return $out;
  }

  public static function text($value, bool $lines = false): string
  {
    $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($lines) {
      return trim((string) preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $text)));
    }
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /** Monto guardado como en el formulario ("1.650.000", "52.661,669"): firma_dinero → "$1.650.000". */
  public static function money(string $value): string
  {
    $value = self::text($value);
    if ($value === '') {
      return '';
    }
    $number = str_replace(',', '.', (string) preg_replace('/[^\d,\-]/', '', str_replace('.', '', $value)));
    return is_numeric($number) ? '$' . number_format(round((float) $number), 0, ',', '.') : $value;
  }

  /** "2.45" o "2,45" → "2,45 %"; un texto que no es número (ej. "Exento") queda igual. Como firma_porcentaje. */
  public static function percent(string $value): string
  {
    $text = self::text($value);
    $number = str_replace(',', '.', trim(str_replace('%', '', $text)));
    if ($number === '' || !is_numeric($number)) {
      return $text;
    }
    return rtrim(rtrim(number_format((float) $number, 4, ',', '.'), '0'), ',') . ' %';
  }

  /** Documento con su tipo abreviado: "C.C. 1143415939", "NIT 900123456-7". Como firma_documento. */
  public static function document(string $type, string $number): string
  {
    $number = self::text($number);
    if ($number === '') {
      return '';
    }
    $type = self::text($type);
    $key = strtr(mb_strtolower($type, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $short = [
      'cedula de ciudadania' => 'C.C.', 'nit' => 'NIT', 'cedula extranjeria' => 'C.E.', 'cedula de extranjeria' => 'C.E.', 'pasaporte' => 'Pasaporte',
    ][$key] ?? $type;
    return trim($short . ' ' . $number);
  }

  private static function date(string $value): string
  {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] : $value;
  }

  private static function installments(string $value): string
  {
    return ctype_digit($value) ? ($value === '1' ? '1 cuota' : $value . ' cuotas') : $value;
  }

  /** @param list<string> $parts */
  private static function join(array $parts, string $glue): string
  {
    return implode($glue, array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== '')));
  }

  /**
   * servicios_publicos: lista PHP serializada, JSON o texto separado por comas ("Luz" es Energía), como
   * formularios/comun/js/servicios_publicos.js.
   * @return list<string>
   */
  public static function serviceList($raw): array
  {
    $raw = trim((string) $raw);
    if ($raw === '') {
      return [];
    }
    $list = null;
    if (preg_match('/^a:\d+:\{/', $raw) === 1) {
      $list = @unserialize($raw, ['allowed_classes' => false]);
    } elseif (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
      $list = json_decode($raw, true);
    }
    if (!is_array($list)) {
      $list = explode(',', $raw);
    }
    $out = [];
    foreach ($list as $key => $value) {
      $name = is_string($key) && in_array(strtolower((string) $value), ['true', '1', 'si', 'sí'], true) ? $key : (string) $value;
      $name = trim($name);
      if ($name === '' || in_array(strtolower($name), ['false', '0'], true)) {
        continue;
      }
      $out[] = strcasecmp($name, 'Luz') === 0 ? 'Energía' : (strcasecmp($name, 'Energia') === 0 ? 'Energía' : $name);
    }
    return array_values(array_unique($out));
  }
}
