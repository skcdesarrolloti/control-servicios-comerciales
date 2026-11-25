<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Mandates\MandateFields;

/**
 * Formulario del contrato de mandato en el panel (crear y editar), en el popup de la tarea o en un popup de la pestaña
 * Mandatos. Copia de formularios/crear-mandato/formulario.php con sus cinco pasos (contrato y propietarios, inmueble,
 * valores, garantías y condiciones) y los mismos nombres de campo; los cálculos están en commercial-mandate-form.js.
 * Clases: las de cotizaciones/estudios (cqt-*, cst-*) y las propias en commercial-mandates.css (cmf-*).
 */
final class CommercialMandateFormView
{
  private const YES_NO = ['Si' => 'Sí', 'No' => 'No'];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  /** @param array<string,mixed> $values */
  private static function val(array $values, string $name): string
  {
    return is_scalar($values[$name] ?? null) ? (string) $values[$name] : '';
  }

  /** @param array<string,mixed> $values */
  private static function input(array $values, string $name, string $label, array $opts = []): string
  {
    $type = $opts['type'] ?? 'text';
    $attrs = ($opts['required'] ?? false ? ' required' : '') . (($opts['calc'] ?? false) ? ' readonly data-cmf-calc tabindex="-1"' : '') . (isset($opts['money']) ? ' data-cmf-money inputmode="numeric"' : '') . ($opts['attrs'] ?? '');
    $control = $type === 'textarea'
      ? '<textarea name="' . self::e($name) . '" rows="' . (int) ($opts['rows'] ?? 2) . '" maxlength="4000"' . $attrs . '>' . self::e(self::val($values, $name)) . '</textarea>'
      : '<input type="' . self::e($type) . '" name="' . self::e($name) . '" value="' . self::e(self::val($values, $name)) . '" autocomplete="off"' . (isset($opts['list']) ? ' list="' . self::e($opts['list']) . '"' : '') . $attrs . '>';
    return '<label class="cqt-field' . (($opts['wide'] ?? false) ? ' cmf-wide' : '') . '"><span>' . self::e($label) . (($opts['required'] ?? false) ? ' <em aria-hidden="true">*</em>' : '') . '</span>' . $control
      . (isset($opts['hint']) ? '<small class="cqt-hint">' . self::e($opts['hint']) . '</small>' : '') . '</label>';
  }

  /**
   * @param array<string,mixed> $values
   * @param array<int|string,string> $options valor => texto
   */
  private static function select(array $values, string $name, string $label, array $options, bool $required = true, string $attrs = ''): string
  {
    $current = self::val($values, $name);
    $html = '<label class="cqt-field"><span>' . self::e($label) . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><select name="' . self::e($name) . '"' . ($required ? ' required' : '') . $attrs . '><option value="">Elige una opción</option>';
    foreach ($options as $value => $text) {
      $html .= '<option value="' . self::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param list<string> $values @return array<string,string> */
  private static function pairs(array $values): array
  {
    return $values === [] ? [] : array_combine($values, $values);
  }

  private static function when(string $field, string $values, string $content, string $class = 'cst-grid'): string
  {
    return '<div class="' . $class . '" data-cmf-when="' . self::e($field) . '" data-cmf-values="' . self::e($values) . '" hidden>' . $content . '</div>';
  }

  /**
   * @param array<string,mixed> $values MandateFields::toForm() o los iniciales.
   * @param array{paises:list<array{pais:string,codigo:string}>,bancos:list<string>,barrios:list<string>} $options
   * @param array<string,mixed> $params MandateCalculator::parameters()
   * @param array{id:int,ticket:int,estudio:int,estudios:list<array{id:int,label:string}>,estado:string,volver:string} $context
   */
  public static function render(array $values, array $options, array $params, array $context): string
  {
    $id = (int) $context['id'];
    $html = '<form class="cmf" data-cmf novalidate data-cmf-params="' . self::e((string) json_encode($params, JSON_UNESCAPED_UNICODE)) . '">'
      . '<input type="hidden" name="id" value="' . ($id > 0 ? $id : '') . '"><input type="hidden" name="ticket_pk" value="' . ((int) $context['ticket'] > 0 ? (int) $context['ticket'] : '') . '">'
      . '<datalist id="cmf-bancos">' . implode('', array_map(static fn(string $bank): string => '<option value="' . self::e($bank) . '">', $options['bancos'])) . '</datalist>'
      . '<datalist id="cmf-barrios">' . implode('', array_map(static fn(string $name): string => '<option value="' . self::e($name) . '">', $options['barrios'])) . '</datalist>'
      . '<ol class="cmf-steps" data-cmf-steps aria-label="Pasos del mandato"></ol>';

    // Paso 1: contrato y propietarios
    $html .= '<section class="cmf-step" data-cmf-step data-title="Contrato y propietarios"><h2>Contrato y propietarios</h2><div class="cst-grid">'
      . self::select($values, 'tipo_contrato', 'Tipo de contrato', self::pairs(MandateFields::CONTRACT_TYPES))
      . self::select($values, 'clasificacion', 'Clasificación del inmueble', MandateFields::CLASSIFICATIONS)
      . self::select($values, 'cantidad_propietarios', 'Cantidad de propietarios', array_combine(range(1, 6), array_map('strval', range(1, 6))), true, ' data-cmf-owner-count');
    if ($id <= 0) {
      $html .= self::input($values, 'id_inmueble', 'Código del inmueble', ['required' => true, 'attrs' => ' data-cmf-property-code', 'hint' => 'Al escribirlo se cargan los datos del inmueble y su propietario.']);
      if ($context['estudios'] !== []) {
        $studies = [];
        foreach ($context['estudios'] as $study) {
          $studies[(string) $study['id']] = $study['label'];
        }
        $html .= self::select(['id_estudio' => (string) $context['estudio']], 'id_estudio', 'Estudio de aseguradora', $studies, false);
      }
    } else {
      $html .= '<input type="hidden" name="id_inmueble" value="' . self::e(self::val($values, 'id_inmueble')) . '">';
    }
    $html .= '</div>';
    for ($i = 1; $i <= MandateFields::MAX_OWNERS; $i++) {
      $html .= self::owner($values, $i, $options);
    }
    $html .= '</section>';

    // Paso 2: inmueble
    $html .= '<section class="cmf-step" data-cmf-step data-title="Inmueble" hidden><h2>Datos del inmueble</h2><div class="cst-grid">'
      . self::select($values, 'existe_simi', '¿Existe en el sistema SIMI?', self::YES_NO)
      . self::when('existe_simi', 'Si', self::input($values, 'codigo_simi', 'Código SIMI', ['required' => true]), 'cmf-inline')
      . self::select($values, 'mismo_propietario', '¿Pertenece al mismo propietario?', self::YES_NO)
      . self::when('mismo_propietario', 'Si', self::input($values, 'codigo_inmueble', 'Código inmueble', ['required' => true]), 'cmf-inline')
      . self::input($values, 'escritura', 'Escritura pública', ['required' => true])
      . self::input($values, 'fecha_inmueble', 'Fecha de la escritura', ['required' => true, 'type' => 'date'])
      . self::input($values, 'circulo_notarial', 'Círculo notarial', ['required' => true])
      . self::input($values, 'matricula_inmobiliaria', 'Matrícula inmobiliaria', ['required' => true])
      . self::input($values, 'referencia_catastral', 'Referencia catastral', ['required' => true])
      . self::input($values, 'barrio', 'Barrio', ['list' => 'cmf-barrios'])
      . self::input($values, 'direccion', 'Dirección', ['required' => true])
      . self::input($values, 'direccion_fisica', 'Dirección física', ['required' => true])
      . self::input($values, 'area_construida', 'Área construida (m²)', ['required' => true])
      . self::input($values, 'area_privada', 'Área privada (m²)')
      . self::input($values, 'estrato', 'Estrato', ['required' => true])
      . self::select($values, 'propiedad_horizontal', '¿Pertenece a régimen de propiedad horizontal?', self::YES_NO)
      . self::select($values, 'tiene_parqueadero', '¿Tiene parqueadero?', self::YES_NO)
      . self::when('tiene_parqueadero', 'Si', self::select($values, 'tipo_parqueadero', 'Tipo de parqueadero', self::pairs(['Asignado', 'Compartido', 'Privado', 'Visitante'])) . self::input($values, 'parqueaderos', 'Parqueaderos disponibles', ['required' => true]) . self::input($values, 'numero_parqueadero', 'Números de parqueaderos', ['required' => true]), 'cmf-inline')
      . self::select($values, 'tiene_deposito', '¿Tiene depósito?', self::YES_NO)
      . self::when('tiene_deposito', 'Si', self::input($values, 'depositos', 'Depósitos disponibles', ['required' => true]), 'cmf-inline')
      . self::select($values, 'tiene_vigilancia', '¿Tiene vigilancia?', self::YES_NO)
      . self::input($values, 'observaciones', 'Observaciones', ['wide' => true])
      . '</div>';
    $coId = self::val($values, 'copropiedad_id');
    $coName = self::val($values, 'nombre_copropiedad');
    $coPicked = $coName . (self::val($values, 'nit_copropiedad') !== '' ? ' · NIT ' . self::val($values, 'nit_copropiedad') : '');
    $coKnown = $coId !== '' || $coName !== '';
    $html .= self::when('propiedad_horizontal', 'Si', '<h3>Copropiedad</h3><div class="cmf-entity" data-cmf-entity="copropiedad">'
      . '<input type="hidden" name="copropiedad_id" value="' . self::e($coId) . '" data-cmf-entity-id><input type="hidden" name="indicativo_copropiedad" value="' . self::e(self::val($values, 'indicativo_copropiedad')) . '">'
      . self::registered(['question' => '¿La copropiedad ya está registrada?', 'yes' => 'Sí, buscarla', 'no' => 'No, es nueva', 'search' => 'Buscar copropiedad', 'placeholder' => 'Nombre o NIT (mínimo 3 letras)', 'type' => 'copropiedad', 'index' => ''], $coId, 'registrada_copropiedad', $coKnown ? $coPicked : '')
      . '<div class="cst-grid cmf-entity-data" data-cmf-entity-data' . ($coKnown ? '' : ' hidden') . '>'
      . self::select($values, 'responsable_admin', '¿Responsable de la administración?', self::pairs(['No aplica', 'Propietario', 'Inmobiliaria']))
      . self::input($values, 'nombre_copropiedad', 'Nombre de la copropiedad', ['required' => true])
      . self::input($values, 'administrador', 'Administrador/a')
      . self::input($values, 'nit_copropiedad', 'NIT')
      . self::input($values, 'direccion_copropiedad', 'Dirección')
      . self::input($values, 'telefono_copropiedad', 'Teléfono')
      . self::input($values, 'correo_copropiedad', 'Correo', ['type' => 'email'])
      . self::select($values, 'tipo_cuenta_copropiedad', 'Tipo de cuenta', self::pairs(['Ahorros', 'Corriente']), false)
      . self::input($values, 'numero_cuenta_copropiedad', 'Número de cuenta')
      . self::input($values, 'banco_copropiedad', 'Banco', ['list' => 'cmf-bancos'])
      . '</div></div>', 'cmf-block');
    $services = is_array($values['servicios_publicos'] ?? null) ? $values['servicios_publicos'] : [];
    $html .= '<h3>Servicios del inmueble</h3><fieldset class="cst-services"><legend>Servicios disponibles</legend>';
    foreach (MandateFields::SERVICES as $value => $label) {
      $html .= '<label class="cqt-check"><input type="checkbox" name="servicios_publicos[]" value="' . self::e($value) . '"' . (in_array($value, $services, true) ? ' checked' : '') . '> ' . self::e($label) . '</label>';
    }
    $html .= '</fieldset><div class="cst-grid">'
      . self::input($values, 'energia', 'Energía (No. de cuenta)') . self::input($values, 'agua', 'Agua (No. de cuenta)') . self::input($values, 'gas', 'Gas (No. de cuenta)')
      . self::input($values, 'tv', 'TV') . self::input($values, 'telefono', 'Teléfono') . self::input($values, 'internet', 'Internet')
      . self::input($values, 'otros_servicios', 'Otros', ['type' => 'textarea', 'wide' => true])
      . '</div></section>';

    // Paso 3: valores
    $html .= '<section class="cmf-step" data-cmf-step data-title="Valores y comisión" hidden><h2>Valores y comisión</h2><div class="cst-grid">'
      . self::input($values, 'precio_canon', 'Canon mensual', ['required' => true, 'money' => true])
      . self::input($values, 'precio_administracion', 'Valor de la administración', ['money' => true])
      . self::input($values, 'precio_canon_total', 'Canon total (canon + administración)', ['calc' => true])
      . self::select($values, 'tiene_iva_canon', '¿El canon incluye IVA?', self::YES_NO)
      . self::input($values, 'iva_canon', 'IVA del canon', ['calc' => true])
      . self::input($values, 'tasa_comision', 'Tasa de comisión (%)', ['required' => true, 'attrs' => ' inputmode="decimal"'])
      . self::input($values, 'comision_sin_iva', 'Comisión sin IVA', ['calc' => true])
      . self::input($values, 'iva_comision', 'IVA de la comisión', ['calc' => true])
      . self::input($values, 'total_comision', 'Comisión total con IVA', ['calc' => true])
      . '</div></section>';

    // Paso 4: garantías
    $insurers = self::pairs(array_map('strval', (array) ($params['aseguradoras'] ?? [])));
    $installments = array_combine(range(1, 12), array_map('strval', range(1, 12)));
    $html .= '<section class="cmf-step" data-cmf-step data-title="Garantías y seguros" hidden><h2>Garantías y seguros</h2>'
      . self::when('tipo_contrato', 'Por defecto', self::select($values, 'incluye_garantia', '¿Incluye garantía inmobiliaria?', self::YES_NO)
        . self::select($values, 'incluye_garantia_servicios', '¿Incluye garantía de servicios públicos?', self::YES_NO)
        . self::select($values, 'incluye_clausula_penal', '¿Incluye cláusula penal?', self::YES_NO))
      . self::when('tipo_contrato', 'Por defecto', self::when('incluye_garantia', 'Si', '<h3>Garantía inmobiliaria</h3><div class="cst-grid">'
        . self::select($values, 'aseguradora', 'Aseguradora', $insurers, true, ' data-cmf-insurer')
        . self::input($values, 'meses_cobertura_garantia', 'Meses de cobertura')
        . self::input($values, 'tasa_garantia', 'Tasa (%)', ['calc' => true])
        . self::input($values, 'valor_garantia', 'Valor de la garantía (mes)', ['calc' => true]) . '</div>', 'cmf-block')
        . self::when('incluye_garantia_servicios', 'Si', '<h3>Garantía de servicios públicos</h3><p class="cqt-hint">Usa la tasa de servicios de la aseguradora elegida en la garantía inmobiliaria. En más de 4 cuotas se financia como préstamo.</p><div class="cst-grid">'
          . self::input($values, 'valor_asegurado_garantia_servicios', 'Valor asegurado', ['required' => true, 'money' => true])
          . self::input($values, 'cobertura_garantia_servicios', 'Cobertura de la garantía')
          . self::input($values, 'tasa_garantia_servicios', 'Tasa (%)', ['calc' => true])
          . self::select($values, 'forma_pago_garantia_servicios', 'Forma de pago (cuotas)', $installments)
          . self::input($values, 'prima_mes_garantia_servicios', 'Prima mes', ['calc' => true])
          . self::input($values, 'prima_anual_garantia_servicios', 'Prima anual', ['calc' => true]) . '</div>', 'cmf-block')
        . self::when('incluye_clausula_penal', 'Si', '<h3>Cláusula penal</h3><div class="cst-grid">'
          . self::input($values, 'tasa_clausula_penal', 'Tasa (%)', ['calc' => true])
          . self::input($values, 'amparo_clausula_penal', 'Amparo cláusula penal', ['calc' => true])
          . self::input($values, 'precio_clausula_penal', 'Precio de la cláusula', ['calc' => true]) . '</div>', 'cmf-block'), 'cmf-block')
      . self::when('tipo_contrato', 'Berkley', '<h3>Berkley</h3><div class="cst-grid">'
        . self::input($values, 'tasa_berkley', 'Tasa (%)', ['calc' => true])
        . self::input($values, 'canon_mes_berkley', 'Canon (mes)', ['calc' => true])
        . self::input($values, 'canon_anual_berkley', 'Canon (año)', ['calc' => true])
        . self::input($values, 'prima_canon_berkley', 'Prima del canon', ['calc' => true])
        . self::select($values, 'incluye_servicios_berkley', '¿Incluye cobertura de servicios públicos?', self::YES_NO)
        . '</div>' . self::when('incluye_servicios_berkley', 'Si', self::input($values, 'valor_asegurado_berkley', 'Valor asegurado', ['required' => true, 'money' => true])
          . self::input($values, 'prima_servicios_berkley', 'Prima de servicios públicos', ['calc' => true])) . '<div class="cst-grid">'
        . self::input($values, 'expedicion_berkley', 'Gastos de expedición', ['calc' => true])
        . self::select($values, 'forma_pago_berkley', 'Forma de pago (cuotas)', array_combine(range(1, 4), array_map('strval', range(1, 4))))
        . self::input($values, 'prima_mes_berkley', 'Prima mes', ['calc' => true])
        . self::input($values, 'prima_anual_berkley', 'Prima anual', ['calc' => true]) . '</div>', 'cmf-block')
      . self::when('tipo_contrato', 'Seguros mundial', '<h3>Seguros Mundial</h3><p class="cqt-hint">Siempre se cobra como préstamo en las cuotas elegidas.</p><div class="cst-grid">'
        . self::input($values, 'tasa_seguros_mundial', 'Tasa (%)', ['calc' => true])
        . self::input($values, 'canon_mes_seguros_mundial', 'Canon (mes)', ['calc' => true])
        . self::input($values, 'canon_anual_seguros_mundial', 'Canon con IVA (año)', ['calc' => true])
        . self::input($values, 'prima_canon_seguros_mundial', 'Prima del canon', ['calc' => true])
        . self::select($values, 'incluye_servicios_seguros_mundial', '¿Incluye cobertura de servicios públicos?', self::YES_NO)
        . '</div>' . self::when('incluye_servicios_seguros_mundial', 'Si', self::input($values, 'valor_asegurado_seguros_mundial', 'Valor asegurado', ['required' => true, 'money' => true])) . '<div class="cst-grid">'
        . self::input($values, 'expedicion_seguros_mundial', 'Gastos de expedición', ['calc' => true])
        . self::select($values, 'forma_pago_seguros_mundial', 'Forma de pago (cuotas)', $installments)
        . self::input($values, 'iva_prima_seguros_mundial', 'IVA de la prima', ['calc' => true])
        . self::input($values, 'total_prima_seguros_mundial', 'Total prima a cobrar', ['calc' => true])
        . self::input($values, 'prima_mes_seguros_mundial', 'Prima mes', ['calc' => true]) . '</div>', 'cmf-block')
      . '</section>';

    // Paso 5: condiciones
    $ipc = [];
    for ($k = 0; $k <= 10; $k++) {
      $ipc['IPC + ' . $k] = 'IPC + ' . $k;
    }
    $years = '';
    for ($i = 1; $i <= 6; $i++) {
      $years .= '<div class="cst-grid cmf-agreement" data-cmf-agreement="anual" data-index="' . $i . '" hidden>' . self::input($values, 'ano_inicial_' . $i, 'Año inicial ' . $i) . self::input($values, 'ano_final_' . $i, 'Año final ' . $i) . self::select($values, 'ipc_' . $i, 'IPC ' . $i, $ipc, false) . '</div>';
    }
    $months = '';
    for ($i = 1; $i <= 4; $i++) {
      $months .= '<div class="cst-grid cmf-agreement" data-cmf-agreement="mensual" data-index="' . $i . '" hidden>' . self::input($values, 'mes_inicial_' . $i, 'Mes inicial ' . $i) . self::input($values, 'mes_final_' . $i, 'Mes final ' . $i) . self::input($values, 'valor_' . $i, 'Valor del canon ' . $i, ['money' => true]) . '</div>';
    }
    $html .= '<section class="cmf-step" data-cmf-step data-title="Condiciones" hidden><h2>Condiciones del contrato</h2><div class="cst-grid">'
      . self::input($values, 'nota_equipos', 'Equipos incorporados al inmueble', ['type' => 'textarea', 'wide' => true])
      . self::input($values, 'equipos_entregados', 'Relación de equipos entregados', ['type' => 'textarea', 'wide' => true])
      . '</div><h3>Retenciones practicadas</h3><div class="cst-grid">'
      . self::input($values, 'rete_fuente', 'Rete fuente (%)') . self::input($values, 'rete_ica', 'Rete ICA (%)') . self::input($values, 'rete_iva', 'Rete IVA (%)')
      . self::input($values, 'adulto_mayor', 'Estampilla adulto mayor (%)') . self::input($values, 'pro_universitario', 'Estampilla pro universitario (%)') . self::input($values, 'recreacion_deporte', 'Estampilla recreación y deporte (%)')
      . '</div><h3>Acuerdos del contrato</h3><div class="cst-grid">'
      . self::select($values, 'acuerdo', 'Tipo de acuerdo', ['Mensual' => 'Mensual', 'Anual' => 'Anual'])
      . self::when('acuerdo', 'Anual', self::select($values, 'anos', 'Años', array_combine(range(1, 6), array_map('strval', range(1, 6))), true, ' data-cmf-agreement-count="anual"'), 'cmf-inline')
      . self::when('acuerdo', 'Mensual', self::select($values, 'meses', 'Vigencia (meses)', array_combine(range(1, 4), array_map('strval', range(1, 4))), true, ' data-cmf-agreement-count="mensual"'), 'cmf-inline')
      . '</div>' . self::when('acuerdo', 'Anual', $years, 'cmf-block') . self::when('acuerdo', 'Mensual', $months, 'cmf-block')
      . '<h3>Datos del contrato</h3><div class="cst-grid">'
      . self::input($values, 'incremento_pactado', 'Porcentaje de incremento pactado', ['hint' => 'Ejemplo: IPC + 2'])
      . self::select($values, 'duracion', 'Duración del contrato', self::pairs(MandateFields::DURATIONS))
      . self::input($values, 'dias_gracia', 'Días de gracia', ['hint' => 'Escribe si tiene o no días de gracia.'])
      . self::input($values, 'adendas', 'Observaciones o adendas', ['type' => 'textarea', 'wide' => true, 'rows' => 3])
      . '</div></section>';

    return $html . '<p class="cqt-error" data-cmf-error role="alert" hidden></p>'
      . '<div class="cmf-nav"><button type="button" class="cqt-secondary" data-cmf-prev>' . self::icon('arrow_back') . 'Atrás</button>'
      . '<button type="button" class="cqt-primary" data-cmf-next>Siguiente' . self::icon('arrow_forward') . '</button>'
      . '<button type="submit" class="cqt-primary" data-cmf-submit hidden>' . self::icon('save') . ($id > 0 ? 'Guardar cambios' : 'Crear mandato') . '</button></div></form>';
  }

  /**
   * "¿Ya está registrado?" (como formularios/comun/js/propietario_registrado.js): "Sí, buscarlo" muestra el buscador y,
   * al elegir, carga su ficha; "No, es nuevo" deja los datos en blanco y se registra al guardar (si el documento ya
   * existe, avisa y ofrece usar ese). Los datos se ven cuando ya se eligió la opción (y, con "Sí", el registro).
   * @param array{question:string,yes:string,no:string,search:string,placeholder:string,type:string,index:string} $text
   */
  private static function registered(array $text, string $id, string $name, string $picked): string
  {
    $mode = $id !== '' ? 'si' : ($picked !== '' ? 'no' : '');
    $radio = static fn(string $value, string $icon, string $title, string $hint): string => '<label class="cmf-option"><input type="radio" name="' . self::e($name) . '" value="' . $value . '" data-cmf-mode' . ($mode === $value ? ' checked' : '') . '>'
      . self::icon($icon) . '<span><strong>' . self::e($title) . '</strong><small>' . self::e($hint) . '</small></span></label>';
    return '<fieldset class="cmf-choice"><legend>' . self::e($text['question']) . ' <em aria-hidden="true">*</em></legend><div class="cmf-options">'
      . $radio('si', 'person_search', $text['yes'], 'Elegirlo de los ya guardados')
      . $radio('no', 'person_add', $text['no'], 'Escribir sus datos; se registra al guardar')
      . '</div></fieldset>'
      . '<div class="cmf-search" data-cmf-search="' . self::e($text['type']) . '"' . ($text['index'] !== '' ? ' data-index="' . self::e($text['index']) . '"' : '') . ($mode === 'si' ? '' : ' hidden') . ' data-cmf-search-box>'
      . '<label class="cqt-field"><span>' . self::e($text['search']) . '</span><input type="search" data-cmf-search-input placeholder="' . self::e($text['placeholder']) . '" autocomplete="off"></label>'
      . '<ul class="cst-results" data-cmf-search-results aria-live="polite"></ul>'
      . '<p class="cmf-picked" data-cmf-picked' . ($id !== '' ? '' : ' hidden') . '>' . self::icon('check_circle') . '<span data-cmf-picked-label>' . self::e($picked) . '</span></p></div>';
  }

  /**
   * País (obligatorio) con su indicativo. Coincide sin importar mayúsculas ni tildes ("Estados unidos" = "Estados
   * Unidos") o, si el nombre no está en la lista, por el indicativo; sin país, Colombia.
   * @param list<array{pais:string,codigo:string}> $countries
   */
  public static function country(array $countries, string $value, string $code = ''): string
  {
    $key = static fn(string $text): string => mb_strtolower(trim(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n'])), 'UTF-8');
    if ($value !== '') {
      foreach ($countries as $item) {
        if ($key($item['pais']) === $key($value)) {
          return $item['pais'];
        }
      }
      foreach ($countries as $item) {
        if ($code !== '' && $item['codigo'] === trim($code)) {
          return $item['pais'];
        }
      }
    }
    foreach ($countries as $item) {
      if ($key($item['pais']) === 'colombia') {
        return $item['pais'];
      }
    }
    return $value;
  }

  /**
   * Bloque del propietario N, con "¿Ya está registrado?" (buscador) y su porcentaje de participación.
   * @param array<string,mixed> $values
   * @param array{paises:list<array{pais:string,codigo:string}>,bancos:list<string>,barrios:list<string>} $options
   */
  private static function owner(array $values, int $i, array $options): string
  {
    $o = static fn(string $name): string => $name . '_' . $i;
    $country = self::country($options['paises'], self::val($values, $o('pais')), self::val($values, $o('indicativo')));
    $code = self::val($values, $o('indicativo'));
    $countries = '<label class="cqt-field"><span>País <em aria-hidden="true">*</em></span><select name="' . $o('pais') . '" data-cmf-country="' . $i . '" required><option value="">Elige un país</option>';
    foreach ($options['paises'] as $item) {
      $countries .= '<option value="' . self::e($item['pais']) . '" data-codigo="' . self::e($item['codigo']) . '"' . ($item['pais'] === $country ? ' selected' : '') . '>' . self::e($item['pais'] . ($item['codigo'] !== '' ? ' (' . $item['codigo'] . ')' : '')) . '</option>';
      if ($item['pais'] === $country && preg_match('/^\+\d+$/', $code) !== 1) {
        $code = $item['codigo'];
      }
    }
    $countries .= '</select></label>';
    $id = self::val($values, $o('id_propietario'));
    $name = self::val($values, $o('nombre'));
    $picked = $name . (self::val($values, $o('documento')) !== '' ? ' · ' . self::val($values, $o('documento')) : '');
    $known = $id !== '' || $name !== '';
    return '<fieldset class="cmf-owner" data-cmf-owner="' . $i . '" data-cmf-entity="' . $i . '"' . ($i > 1 ? ' hidden' : '') . '><legend>Propietario #' . $i . '</legend>'
      . '<input type="hidden" name="' . $o('id_propietario') . '" value="' . self::e($id) . '" data-cmf-entity-id>'
      . '<input type="hidden" name="' . $o('indicativo') . '" value="' . self::e($code) . '">'
      . self::registered(['question' => '¿El propietario ya está registrado?', 'yes' => 'Sí, buscarlo', 'no' => 'No, es nuevo', 'search' => 'Buscar propietario', 'placeholder' => 'Nombre o documento (mínimo 3 letras)', 'type' => 'propietario', 'index' => (string) $i], $id, 'registrado_' . $i, $known ? $picked : '')
      . '<div class="cmf-entity-data" data-cmf-entity-data' . ($known ? '' : ' hidden') . '>'
      . '<h4 class="cmf-subtitle">Identificación</h4><div class="cst-grid">'
      . self::select($values, $o('calidad'), 'Calidad', self::pairs(['Propietario', 'Poseedor', 'Tenedor', 'Usufructuario']))
      . self::select($values, $o('tipo_persona'), 'Tipo de persona', ['Natural' => 'Natural', 'Juridica' => 'Jurídica'])
      . self::input($values, $o('nombre'), 'Nombre / Empresa', ['required' => true])
      . self::select($values, $o('tipo_documento'), 'Tipo de documento', ['Cédula de Ciudadania' => 'Cédula de ciudadanía', 'Nit' => 'NIT', 'Cédula Extranjería' => 'Cédula de extranjería', 'Pasaporte' => 'Pasaporte'])
      . self::input($values, $o('documento'), 'Documento / NIT', ['required' => true, 'attrs' => ' data-cmf-document="' . $i . '"'])
      . self::input($values, $o('porcentaje'), '% de participación', ['attrs' => ' inputmode="decimal" data-cmf-percent'])
      . '<p class="cmf-duplicate cmf-wide" data-cmf-duplicate hidden></p>'
      . '</div>'
      . self::when($o('tipo_persona'), 'Juridica', self::input($values, $o('nombre_juridico'), 'Representante legal') . self::input($values, $o('documento_juridico'), 'Documento del representante'))
      . '<h4 class="cmf-subtitle">Contacto</h4><div class="cst-grid">' . $countries
      . self::input($values, $o('expedicion'), 'Lugar de expedición')
      . self::input($values, $o('direccion'), 'Dirección')
      . self::input($values, $o('correo'), 'Correo', ['type' => 'email'])
      . self::input($values, $o('celular'), 'Celular', ['type' => 'tel'])
      . self::input($values, $o('fallecimiento'), 'En caso de fallecimiento se designa a', ['type' => 'textarea', 'wide' => true])
      . '</div><h4 class="cmf-subtitle">Pago del canon</h4><div class="cst-grid">'
      . self::select($values, $o('tipo_giro'), 'Tipo de giro', self::pairs(['Transferencia electronica', 'Cheque']), false)
      . self::input($values, $o('nombre_giro'), 'A nombre del giro')
      . self::input($values, $o('titular'), 'Titular de la cuenta')
      . self::input($values, $o('documento_cuenta'), 'Documento del titular')
      . self::select($values, $o('tipo_cuenta'), 'Tipo de cuenta', self::pairs(['Ahorros', 'Corriente']), false)
      . self::input($values, $o('numero_cuenta'), 'Número de cuenta')
      . self::input($values, $o('banco'), 'Banco', ['list' => 'cmf-bancos'])
      . self::input($values, $o('correo_cuenta'), 'Correo de facturación', ['type' => 'email'])
      . '</div></div></fieldset>';
  }
}
