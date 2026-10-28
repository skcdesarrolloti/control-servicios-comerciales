<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialNetIncomeSimulator;
use SCM\Commercial\CommercialOwnerPreapprovalService;

/**
 * Herramientas del panel comercial: simulador de ingreso neto del propietario con su crédito preaprobado (y PDF para el
 * propietario) y calculadora de ganancia ocasional. Los cálculos en pantalla se hacen en el navegador
 * (commercial-tools.js); el servidor busca contratos, recalcula y genera el PDF (CommercialOwnerPreapprovalPdf).
 */
final class CommercialToolsView
{
  public const DISCLAIMER = 'Valores de referencia sujetos a las políticas de crédito y a los términos y condiciones de SKC SuCasa Inmobiliaria. No constituyen una aprobación ni un compromiso de desembolso: el crédito queda sujeto a estudio, verificación y aprobación.';
  /** UVT vigente por defecto en la calculadora de ganancia ocasional; el consultor la puede cambiar. */
  public const UVT = 52374;
  /** Íconos de las necesidades que resuelve el crédito preaprobado (CommercialOwnerPreapprovalService::USES). */
  private const USE_ICONS = ['servicios' => 'water_drop', 'administracion' => 'apartment', 'reparaciones' => 'construction', 'otros' => 'receipt_long'];

  public static function render(string $tool, ?CommercialOwnerPreapprovalService $service, string $baseUrl): string
  {
    if ($tool === 'ganancia_ocasional') return self::occasionalGain();
    return $service instanceof CommercialOwnerPreapprovalService ? self::netIncome($service) : '';
  }

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /** Encabezado común sin fondo: etiqueta, título y descripción. */
  private static function hero(string $icon, string $title, string $text, string $aside = ''): string
  {
    return '<header class="ctl-hero"><div class="ctl-hero__text"><span class="ctl-eyebrow"><span class="material-symbols-outlined" aria-hidden="true">' . self::e($icon) . '</span>Herramientas</span><h1>' . self::e($title) . '</h1><p>' . $text . '</p>' . $aside . '</div></header>';
  }

  /** Campo de dinero con su unidad visible (/ mes, 12 meses) y, si aplica, la aclaración debajo. */
  private static function moneyField(string $field, string $label, string $unit, string $note = '', string $attribute = 'data-pre-field', string $class = ''): string
  {
    return '<label class="ctl-field' . ($class !== '' ? ' ' . $class : '') . '"><span class="ctl-label">' . self::e($label) . '</span><span class="ctl-input"><input ' . $attribute . '="' . self::e($field) . '" data-tool-money inputmode="numeric" autocomplete="off" placeholder="$0">' . ($unit !== '' ? '<em>' . self::e($unit) . '</em>' : '') . '</span>'
      . ($note !== '' ? '<small class="ctl-note">' . self::e($note) . '</small>' : '') . '</label>';
  }

  private static function selectField(string $field, string $label, string $options, string $row = ''): string
  {
    return '<label class="ctl-field"' . ($row !== '' ? ' data-pre-sim-row="' . self::e($row) . '"' : '') . '><span class="ctl-label">' . self::e($label) . '</span><span class="ctl-input"><select data-pre-sim="' . self::e($field) . '">' . $options . '</select></span></label>';
  }

  private static function switchField(string $field, string $label, bool $checked = false, string $row = ''): string
  {
    return '<label class="ctl-switch"' . ($row !== '' ? ' data-pre-sim-row="' . self::e($row) . '"' : '') . '><input type="checkbox" data-pre-sim="' . self::e($field) . '"' . ($checked ? ' checked' : '') . '><span aria-hidden="true"></span>' . self::e($label) . '</label>';
  }

  private static function netIncome(CommercialOwnerPreapprovalService $service): string
  {
    $settings = $service->settings();
    $rates = $service->rates();
    $months = (int) $settings['income_months'];
    $e = static fn($value): string => self::e($value);
    $rateText = rtrim(rtrim(number_format((float) $rates['comision'], 4, ',', ''), '0'), ',');
    $modalities = '';
    foreach (CommercialNetIncomeSimulator::MODALITIES as $key => $label) $modalities .= '<option value="' . $e($key) . '">' . $e($label) . '</option>';
    $insurers = '';
    foreach (array_keys($rates['aseguradoras']) as $name) $insurers .= '<option value="' . $e($name) . '">' . $e($name) . '</option>';
    $installments = '';
    for ($i = 1; $i <= 12; $i++) $installments .= '<option value="' . $i . '">' . ($i === 1 ? '1 pago (contado)' : $i . ' cuotas') . '</option>';
    ob_start(); ?>
    <section class="ctl" data-commercial-tool="preaprobado" data-income-months="<?php echo $months; ?>" data-term-margin="<?php echo (int) $settings['term_margin_months']; ?>" data-rates="<?php echo $e(json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); ?>">
      <?php echo self::hero('savings', 'Simulador de ingreso neto del propietario', 'Calcula cuánto le queda al propietario cada mes después de la comisión, la garantía y los seguros, con las tarifas vigentes de SuCasa. Con ese ingreso accede a un <strong>crédito preaprobado de hasta ' . $months . ' veces su ingreso neto</strong> para ponerse al día con servicios públicos, administración o reparaciones.'); ?>
      <div class="ctl-layout">
        <div class="ctl-main">
          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">1</span><div><h2>Propietario</h2><p>Si ya tiene contratos con SuCasa, búscalo por cédula o NIT y se cargan sus inmuebles. Si aún no está registrado, escribe sus datos y simula sus inmuebles.</p></div></div>
            <div class="ctl-segment" role="group" aria-label="Tipo de propietario">
              <button type="button" data-pre-mode="registrado" aria-pressed="true"><span class="material-symbols-outlined" aria-hidden="true">person_search</span>Registrado en SuCasa</button>
              <button type="button" data-pre-mode="manual" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">person_add</span>Aún no registrado</button>
            </div>
            <div class="ctl-search" data-pre-search>
              <label class="ctl-field"><span class="ctl-label">Cédula o NIT del propietario</span><span class="ctl-input"><input data-pre-document inputmode="numeric" maxlength="24" autocomplete="off" placeholder="Ej. 1143415938"></span></label>
              <button type="button" class="ctl-secondary" data-pre-search-button><span class="material-symbols-outlined" aria-hidden="true">search</span>Buscar</button>
            </div>
            <p class="ctl-status" data-pre-status role="status" aria-live="polite"></p>
            <div class="ctl-grid ctl-owner">
              <label class="ctl-field"><span class="ctl-label">Nombre completo</span><span class="ctl-input"><input data-pre-owner="name" maxlength="120" autocomplete="off" placeholder="Nombre del propietario"></span></label>
              <label class="ctl-field"><span class="ctl-label">Cédula o NIT</span><span class="ctl-input"><input data-pre-owner="document" maxlength="24" autocomplete="off" placeholder="Documento"></span></label>
              <label class="ctl-field"><span class="ctl-label">Celular</span><span class="ctl-input"><input data-pre-owner="phone" maxlength="20" inputmode="tel" autocomplete="off" placeholder="Opcional"></span></label>
              <label class="ctl-field"><span class="ctl-label">Correo</span><span class="ctl-input"><input data-pre-owner="email" maxlength="120" inputmode="email" autocomplete="off" placeholder="Opcional"></span></label>
            </div>
            <p class="ctl-alert" data-pre-credits hidden></p>
          </section>

          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">2</span><div><h2>Ingreso neto por inmueble</h2><p>Simula cada inmueble con las tarifas de SuCasa (comisión con IVA, garantía según la aseguradora, Berkley o Seguros Mundial y seguro de servicios públicos) o usa los valores de su contrato. El seguro se reparte en los 12 meses.</p></div></div>
            <div class="ctl-sources" data-pre-sources>
              <div class="ctl-empty" data-pre-empty><span class="material-symbols-outlined" aria-hidden="true">home_work</span><p>Busca al propietario para cargar sus contratos vigentes, o agrega un inmueble para simularlo.</p></div>
            </div>
            <button type="button" class="ctl-add" data-pre-add><span class="material-symbols-outlined" aria-hidden="true">add</span>Agregar inmueble</button>
          </section>

          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">3</span><div><h2>Lo que puede resolver con su crédito preaprobado</h2><p>Pregúntale al propietario qué tiene pendiente y escribe cuánto necesita: así ve que su crédito lo cubre sin sacar dinero de su bolsillo. Es opcional y aparece en el PDF.</p></div></div>
            <div class="ctl-uses">
              <?php foreach (CommercialOwnerPreapprovalService::USES as $key => [$label, $text]): ?>
              <label class="ctl-use">
                <span class="ctl-use__icon material-symbols-outlined" aria-hidden="true"><?php echo $e(self::USE_ICONS[$key] ?? 'payments'); ?></span>
                <span class="ctl-use__text"><strong><?php echo $e($label); ?></strong><small><?php echo $e($text); ?></small></span>
                <span class="ctl-input"><input data-pre-use="<?php echo $e($key); ?>" data-tool-money inputmode="numeric" autocomplete="off" placeholder="$0" aria-label="<?php echo $e($label); ?>"></span>
              </label>
              <?php endforeach; ?>
            </div>
            <p class="ctl-uses__summary" data-pre-uses-summary data-state="empty">Escribe cuánto necesita para cada cosa y muéstrale que su crédito preaprobado lo cubre.</p>
            <label class="ctl-field ctl-notes"><span class="ctl-label">Observaciones para el propietario</span><textarea data-pre-notes rows="3" maxlength="600" placeholder="Opcional. Ej.: el crédito se descuenta en cuotas de su canon mensual."></textarea></label>
          </section>
        </div>

        <aside class="ctl-summary" aria-live="polite">
          <div class="ctl-summary__top">
            <span class="ctl-summary__label">Ingreso neto mensual del propietario</span>
            <strong class="ctl-summary__amount" data-pre-net>$0</strong>
            <span class="ctl-summary__formula" data-pre-formula>Canon $0 − descuentos $0 / mes</span>
          </div>
          <div class="ctl-credit">
            <span class="ctl-credit__label"><span class="material-symbols-outlined" aria-hidden="true">verified</span>Crédito preaprobado hasta</span>
            <strong class="ctl-credit__amount" data-pre-total>$0</strong>
            <span class="ctl-credit__formula">Su ingreso neto × <?php echo $months; ?> meses, para servicios, administración y reparaciones.</span>
          </div>
          <dl class="ctl-summary__rows">
            <div><dt>Inmuebles</dt><dd data-pre-count>0</dd></div>
            <div><dt>Canon mensual</dt><dd data-pre-gross>$0</dd></div>
            <div><dt>Descuentos promedio / mes</dt><dd data-pre-deductions>$0</dd></div>
            <div><dt>Plazo máximo estimado</dt><dd data-pre-term>Según vigencia</dd></div>
          </dl>
          <p class="ctl-error" data-pre-error role="alert" hidden></p>
          <button type="button" class="ctl-primary" data-pre-pdf><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Descargar PDF para el propietario</button>
          <button type="button" class="ctl-secondary ctl-wide" data-pre-copy><span class="material-symbols-outlined" aria-hidden="true">content_copy</span><span data-pre-copy-label>Copiar mensaje para WhatsApp</span></button>
          <p class="ctl-fineprint"><?php echo $e(self::DISCLAIMER); ?></p>
        </aside>
      </div>

      <template data-pre-source-template>
        <article class="ctl-source" data-pre-source data-calc="sim">
          <header class="ctl-source__head">
            <div><strong data-pre-source-title>Inmueble</strong><span data-pre-source-meta></span></div>
            <button type="button" class="ctl-icon" data-pre-remove aria-label="Quitar inmueble" title="Quitar inmueble"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
          </header>
          <div class="ctl-source__body">
            <div class="ctl-segment ctl-segment--sm" role="group" aria-label="Cómo calcular los descuentos">
              <button type="button" data-pre-calc="sim" aria-pressed="true"><span class="material-symbols-outlined" aria-hidden="true">calculate</span>Simular con tarifas SuCasa</button>
              <button type="button" data-pre-calc="fijo" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>Valores del contrato</button>
            </div>
            <div class="ctl-grid">
              <?php echo self::moneyField('canon', 'Canon mensual', '/ mes'); ?>
              <label class="ctl-field"><span class="ctl-label">Fin del contrato</span><span class="ctl-input"><input type="date" data-pre-field="fin"></span><small class="ctl-note">Opcional: con la fecha se estima el plazo máximo.</small></label>
            </div>
            <div class="ctl-grid" data-pre-panel="sim">
              <?php echo self::moneyField('administracion', 'Administración', '/ mes', 'La comisión se calcula sobre canon + administración.', 'data-pre-sim'); ?>
              <label class="ctl-field"><span class="ctl-label">Tasa de comisión</span><span class="ctl-input"><input data-pre-sim="tasa" inputmode="decimal" maxlength="6" autocomplete="off" value="<?php echo $e($rateText); ?>"><em>% + IVA</em></span></label>
              <?php echo self::selectField('modalidad', 'Modalidad / aseguradora principal', $modalities); ?>
              <?php echo self::selectField('aseguradora', 'Aseguradora', $insurers, 'defecto'); ?>
              <div class="ctl-switches ctl-full">
                <?php echo self::switchField('garantia', 'Aplicar garantía inmobiliaria', true, 'defecto'); ?>
                <?php echo self::switchField('servicios', 'Incluir seguro de servicios públicos'); ?>
                <?php echo self::switchField('iva', 'El canon lleva IVA'); ?>
              </div>
              <?php echo str_replace('<label class="ctl-field"', '<label class="ctl-field" data-pre-sim-row="valor"', self::moneyField('valor', 'Valor asegurado de servicios', '', '', 'data-pre-sim')); ?>
              <?php echo self::selectField('cuotas', 'Forma de pago del seguro', $installments, 'cuotas'); ?>
              <p class="ctl-note ctl-full ctl-rates" data-pre-sim-rates></p>
            </div>
            <div class="ctl-grid" data-pre-panel="fijo" hidden>
              <?php echo self::moneyField('comision', 'Comisión de administración (IVA incluido)', '/ mes'); ?>
              <?php echo self::moneyField('garantia', 'Garantía inmobiliaria', '/ mes'); ?>
              <?php echo self::moneyField('seguro', 'Seguro', '12 meses', 'Valor de los 12 meses. Permanece mientras la vigencia del contrato continúa.'); ?>
              <?php echo self::moneyField('intereses', 'Intereses', '12 meses', 'Aplica si el seguro se difiere a 12 meses.'); ?>
            </div>
            <div class="ctl-grid">
              <?php echo self::moneyField('otros', 'Otros descuentos', '/ mes'); ?>
            </div>
            <dl class="ctl-breakdown" data-pre-breakdown></dl>
            <p class="ctl-alert" data-pre-source-warning hidden></p>
          </div>
          <footer class="ctl-source__foot">
            <span>Ingreso neto mensual<small data-pre-source-detail></small></span>
            <strong data-pre-source-net>$0</strong>
          </footer>
        </article>
      </template>
    </section>
    <?php return (string) ob_get_clean();
  }

  private static function occasionalGain(): string
  {
    $uvt = self::UVT;
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $field = static fn(string $id, string $label, string $control, string $note = '', bool $full = false): string => '<label class="ctl-field' . ($full ? ' ctl-full' : '') . '" for="' . $id . '"><span class="ctl-label">' . self::e($label) . '</span>' . $control . ($note !== '' ? '<small class="ctl-note">' . $note . '</small>' : '') . '</label>';
    $moneyInput = static fn(string $id, string $placeholder = '$0'): string => '<span class="ctl-input"><input id="' . $id . '" data-go-field="' . $id . '" data-tool-money inputmode="numeric" autocomplete="off" placeholder="' . self::e($placeholder) . '"></span>';
    $switch = static fn(string $id, string $label): string => '<label class="ctl-switch"><input type="checkbox" data-go-field="' . $id . '"><span aria-hidden="true"></span>' . self::e($label) . '</label>';
    ob_start(); ?>
    <section class="ctl" data-commercial-tool="ganancia_ocasional">
      <?php echo self::hero('calculate', 'Calculadora de ganancia ocasional', 'Estima el impuesto por la venta de un inmueble en Colombia: ganancia ocasional (15 %) si fue activo fijo por 2 años o más, o renta ordinaria si no. <strong>Herramienta informativa: debe validarla el contador del vendedor.</strong>', '<button type="button" class="ctl-ghost" data-go-guide><span class="material-symbols-outlined" aria-hidden="true">menu_book</span>Ver definiciones y guía</button>'); ?>
      <div class="ctl-layout">
        <div class="ctl-main">
          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">1</span><div><h2>Vendedor y posesión</h2><p>La fecha de compra y la de venta definen si el inmueble se tuvo 2 años o más.</p></div></div>
            <div class="ctl-grid">
              <?php echo $field('go-tipo', 'Tipo de contribuyente', '<span class="ctl-input"><select id="go-tipo" data-go-field="tipo"><option value="pn" selected>Persona natural</option><option value="pj">Persona jurídica</option></select></span>'); ?>
              <?php echo $field('go-uvt', 'Valor UVT (año vigente)', '<span class="ctl-input"><input id="go-uvt" data-go-field="uvt" data-tool-money inputmode="numeric" value="' . self::e($money($uvt)) . '"></span>', 'Actualízala cada año según la resolución de la DIAN.'); ?>
              <?php echo $field('go-compra', 'Fecha de compra', '<span class="ctl-input"><input id="go-compra" type="date" data-go-field="compra"></span>'); ?>
              <?php echo $field('go-venta', 'Fecha de venta', '<span class="ctl-input"><input id="go-venta" type="date" data-go-field="venta"></span>'); ?>
            </div>
          </section>
          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">2</span><div><h2>Valores de la operación</h2><p>Utilidad = precio de venta − (costo de adquisición + mejoras + valorizaciones) − gastos de venta.</p></div></div>
            <div class="ctl-grid">
              <?php echo $field('precioVenta', 'Precio de venta', $moneyInput('precioVenta', 'Ej. $500.000.000'), '', true); ?>
              <?php echo $field('costoAdq', 'Costo de adquisición', $moneyInput('costoAdq', 'Valor de compra')); ?>
              <?php echo $field('mejoras', 'Mejoras / construcciones', $moneyInput('mejoras')); ?>
              <?php echo $field('valorizaciones', 'Contribuciones por valorización', $moneyInput('valorizaciones')); ?>
              <?php echo $field('gastosVenta', 'Gastos de venta', $moneyInput('gastosVenta'), 'Notaría, registro, comisión y otros comprobables.'); ?>
            </div>
          </section>
          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">3</span><div><h2>Condiciones del inmueble</h2><p>Definen si es ganancia ocasional o renta ordinaria, y si aplica la exención de vivienda.</p></div></div>
            <div class="ctl-switches">
              <?php echo $switch('giro', 'Hace parte del giro ordinario o inventario (constructora, compraventa habitual)'); ?>
              <?php echo $switch('vivienda', 'Es la vivienda de habitación del vendedor'); ?>
              <?php echo $switch('exencion', 'Cumple los requisitos de la exención del art. 311-1 E.T.'); ?>
              <small class="ctl-note">Exención: posesión de 2 años o más, depósito del total de la venta en cuenta AFC/AVC o abono a crédito hipotecario, y destinación a vivienda. Exime hasta 5.000 UVT de la utilidad.</small>
            </div>
            <div class="ctl-grid" data-go-rent hidden>
              <?php echo $field('go-tasa', 'Tasa estimada de renta', '<span class="ctl-input"><input id="go-tasa" type="number" min="0" max="99" step="0.1" value="0" data-go-field="tasa"><em>%</em></span>', '<span data-go-rate-help>PN: escribe la tasa efectiva estimada.</span>'); ?>
            </div>
          </section>
        </div>

        <aside class="ctl-summary" aria-live="polite">
          <div class="ctl-summary__top">
            <span class="ctl-summary__label">Impuesto estimado</span>
            <strong class="ctl-summary__amount" data-go-out="impuesto">$0</strong>
            <span class="ctl-badge" data-go-out="clasificacion">Escribe el precio de venta</span>
          </div>
          <dl class="ctl-summary__rows">
            <div><dt>Años de posesión</dt><dd data-go-out="anios">—</dd></div>
            <div><dt>Utilidad (venta − costos)</dt><dd data-go-out="utilidad">—</dd></div>
            <div data-go-row="exencion"><dt>Exención vivienda</dt><dd data-go-out="exencion">—</dd></div>
            <div data-go-row="gravable"><dt>Ganancia ocasional gravable</dt><dd data-go-out="gravable">—</dd></div>
            <div data-go-row="renta" hidden><dt>Base renta ordinaria</dt><dd data-go-out="renta">—</dd></div>
            <div><dt>Tarifa aplicada</dt><dd data-go-out="tarifa">—</dd></div>
          </dl>
          <p class="ctl-alert" data-go-out="alerta" hidden></p>
          <button type="button" class="ctl-secondary ctl-wide" data-go-reset><span class="material-symbols-outlined" aria-hidden="true">restart_alt</span>Limpiar</button>
          <p class="ctl-fineprint">Cálculo orientativo. Valídalo con el contador del vendedor.</p>
        </aside>
      </div>

      <dialog class="ctl-dialog" data-go-dialog aria-labelledby="go-guide-title">
        <header><h2 id="go-guide-title"><span class="material-symbols-outlined" aria-hidden="true">menu_book</span>Guía y definiciones · Ganancia ocasional</h2><button type="button" class="ctl-icon" data-go-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>
        <div class="ctl-dialog__body">
          <h3>1. ¿Qué es la ganancia ocasional?</h3>
          <p>Es un ingreso <strong>extraordinario (no habitual)</strong>, generado, entre otros casos, por la venta de activos fijos poseídos por 2 años o más (E.T. arts. 300 y 311).</p>
          <h3>2. Requisitos para que sea ganancia ocasional</h3>
          <ul><li>El inmueble fue del contribuyente durante 2 años o más.</li><li>Es <strong>activo fijo</strong> (no inventario ni giro ordinario).</li></ul>
          <p>Si se cumplen ambos, la utilidad es ganancia ocasional; si no, es renta ordinaria.</p>
          <h3>3. Cálculo</h3>
          <table><tbody>
            <tr><th>Fórmula</th><td>Ganancia ocasional = precio de venta − costo fiscal − gastos de venta</td></tr>
            <tr><th>Costo fiscal</th><td>Adquisición + mejoras + valorizaciones (− depreciaciones, si las hubo)</td></tr>
            <tr><th>Ejemplo</th><td>Venta $500.000.000 − (compra $300.000.000 + mejoras $20.000.000) − gastos $10.000.000 = <strong>$170.000.000</strong></td></tr>
          </tbody></table>
          <h3>4. Tarifa (art. 314 E.T., Ley 2277 de 2022)</h3>
          <table><tbody><tr><th>Persona natural</th><td>15 %</td></tr><tr><th>Persona jurídica</th><td>15 % (salvo regímenes especiales)</td></tr></tbody></table>
          <h3>5. ¿Ganancia ocasional o renta?</h3>
          <table><tbody><tr><th>Poseído 2 años o más</th><td>Ganancia ocasional (15 %)</td></tr><tr><th>Poseído menos de 2 años</th><td>Renta ordinaria</td></tr><tr><th>Hace parte del giro habitual</th><td>Renta ordinaria</td></tr></tbody></table>
          <h3>6. Exención de vivienda (art. 311-1 E.T.)</h3>
          <ul><li>Hasta <strong>5.000 UVT</strong> de la utilidad (<span data-go-cap><?php echo self::e($money(5000 * $uvt)); ?></span> con la UVT de la calculadora).</li><li>Debe ser la vivienda de habitación del vendedor.</li><li>El total de la venta se deposita en cuenta AFC/AVC o se abona a un crédito hipotecario, con destino a vivienda.</li></ul>
          <h3>Soporte normativo</h3>
          <ul class="ctl-small"><li>UVT: resolución anual de la DIAN (actualízala en la calculadora).</li><li>Tarifa de ganancia ocasional 15 % (arts. 313 y 314 E.T., Ley 2277 de 2022).</li><li>Exención de vivienda 5.000 UVT (art. 311-1 E.T., Decreto 1625 de 2016).</li></ul>
        </div>
        <footer><button type="button" class="ctl-secondary" data-go-close>Cerrar</button></footer>
      </dialog>
    </section>
    <?php return (string) ob_get_clean();
  }
}
