<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialCreditRequestService;

/** "Solicitud de crédito": el consultor registra lo que pide el propietario; se evalúa en el liquidador. */
final class CommercialCreditRequestView
{
  public static function render(CommercialCreditRequestService $service, CommercialAccessPolicy $policy): string
  {
    if (!$policy->canAct('solicitar_credito')) return '<section class="csg"><h1>Solicitud de crédito</h1><p>Tu cargo puede consultar esta página, pero no tiene permiso para registrar solicitudes de crédito.</p></section>';
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start(); ?>
    <section class="csg ccr" data-commercial-credit>
      <div class="csg-heading"><span class="csg-eyebrow">Actividades comerciales</span><h1>Nueva solicitud de crédito</h1><p>Registra lo que pide el propietario. Todavía no se aprueba nada: el analista lo evalúa en el liquidador de créditos y ahí se aprueba o rechaza.</p></div>
      <div class="csg-result" data-credit-result role="status" hidden></div>
      <form data-credit-form autocomplete="off" novalidate>
        <input type="hidden" name="contract_id" value=""><input type="hidden" name="owner_key" value="">
        <fieldset class="csg-section"><legend><span>1</span> ¿Para quién es el crédito?</legend>
          <p class="csg-help">Por ahora solo está habilitado el crédito a propietarios; los demás tipos se habilitarán con su propia evaluación.</p>
          <div class="ccr-types" role="radiogroup" aria-label="Para quién es el crédito">
            <?php foreach (CommercialCreditRequestService::BENEFICIARY_TYPES as $key => $label): $available = in_array($key, CommercialCreditRequestService::AVAILABLE_BENEFICIARY_TYPES, true); ?>
            <label class="ccr-type<?php echo $available ? '' : ' is-disabled'; ?>"><input type="radio" name="beneficiary_type" value="<?php echo $e($key); ?>"<?php echo $key === 'propietario' ? ' checked' : ''; ?><?php echo $available ? '' : ' disabled'; ?>><span><?php echo $e($label); ?></span><?php if (!$available): ?><small>Próximamente</small><?php endif; ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset class="csg-section"><legend><span>2</span> Documento del propietario</legend>
          <p class="csg-help">Escribe la cédula o el NIT de quien pide el crédito, con o sin puntos. Se buscan los contratos Entregado o Por entregar donde esa persona es propietaria; las cuotas se descuentan del canon del contrato que elijas.</p>
          <div class="ccr-search"><div><label for="ccr-document">Cédula o NIT *</label><input id="ccr-document" data-credit-document inputmode="numeric" maxlength="24" placeholder="Ej. 1143415938"></div><button type="button" class="csg-secondary csg-lookup" data-credit-search><span class="material-symbols-outlined" aria-hidden="true">search</span>Buscar</button></div>
          <p class="csg-status" data-credit-search-status role="status" aria-live="polite"></p>
          <div class="ccr-person" data-credit-person hidden></div>
          <div class="ccr-contracts" data-credit-contracts role="radiogroup" aria-label="Contrato del que se descuenta"></div>
        </fieldset>
        <fieldset class="csg-section" data-credit-request hidden><legend><span>3</span> Datos de la solicitud</legend>
          <p class="csg-help">El plazo son los meses que pidió el propietario. Si superan la vigencia del contrato, regístralo igual: el analista revisa la fecha fin en el liquidador.</p>
          <div class="csg-grid ccr-grid">
            <div><label for="ccr-amount">Valor solicitado *</label><input id="ccr-amount" name="amount" inputmode="numeric" data-credit-money required placeholder="$0"></div>
            <div><label for="ccr-term">Plazo solicitado (meses) *</label><input id="ccr-term" name="term" type="number" min="1" max="60" step="1" required placeholder="Escribe los meses"></div>
            <div class="csg-full"><label for="ccr-purpose">Destinación</label><select id="ccr-purpose" name="purpose"><?php foreach (CommercialCreditRequestService::PURPOSES as $purpose): ?><option><?php echo $e($purpose); ?></option><?php endforeach; ?></select></div>
          </div>
          <p class="csg-help ccr-hint" data-credit-term-hint></p>
        </fieldset>
        <div class="csg-error" role="alert" data-credit-error hidden></div>
        <div class="csg-footer"><p>Los campos con * son obligatorios.</p><button type="submit" class="csg-primary" data-credit-submit>Registrar solicitud</button></div>
      </form>
    </section>
    <?php return (string) ob_get_clean();
  }
}
