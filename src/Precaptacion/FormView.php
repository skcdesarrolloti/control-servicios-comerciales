<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

final class FormView
{
  public static function render(Repository $repository): string
  {
    $config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/precaptacion-form.json'), true);
    $options = $repository->options();
    $userCountry = (string) get_user_meta(get_current_user_id(), 'pais-user', true);
    $userCity = (string) get_user_meta(get_current_user_id(), 'ciudad-user', true);
    $steps = [
      ['title'=>'Inmueble','description'=>'Origen y características de la oportunidad.','fields'=>['origen','id_pph','tipo_inmueble','categoria']],
      ['title'=>'Ubicación','description'=>'Identifica dónde se encuentra el inmueble.','fields'=>['barrio','direccion','punto_referencia']],
      ['title'=>'Contacto','description'=>'Datos del contacto y quién promociona el inmueble.','fields'=>['tipo_contacto','contacto','telefono','indicativo','celular','promocionado_por','competencia']],
      ['title'=>'Evidencias','description'=>'Adjunta las fotografías y revisa antes de registrar.','fields'=>['fotos','observaciones']],
    ];
    ob_start();
    ?>
    <?php if (Module::policy()->canAct('precaptacion_crear')): ?>
    <dialog id="precap-create" class="rounded-3xl border border-brand-border bg-white shadow-modal" aria-labelledby="precap-create-title">
      <form id="precap-create-form" enctype="multipart/form-data" novalidate>
        <header class="precap-wizard-header"><div><span class="precap-eyebrow">ACTIVIDADES COMERCIALES</span><h2 id="precap-create-title">Registrar precaptación</h2><p>Una nueva oportunidad, paso a paso.</p></div><button type="button" data-precap-close aria-label="Cerrar formulario">×</button></header>
        <nav class="precap-steps" aria-label="Pasos del registro"><?php foreach ($steps as $index => $step): ?><button type="button" data-precap-step-go="<?php echo $index; ?>"<?php echo $index === 0 ? ' aria-current="step"' : ''; ?>><span><?php echo $index + 1; ?></span><?php echo esc_html($step['title']); ?></button><?php endforeach; ?></nav>
        <div class="precap-wizard-body">
        <?php foreach ($steps as $index => $step): ?>
        <section data-precap-step="<?php echo $index; ?>" aria-labelledby="precap-step-title-<?php echo $index; ?>"<?php echo $index ? ' hidden' : ''; ?>>
        <div class="precap-step-heading"><h3 id="precap-step-title-<?php echo $index; ?>"><?php echo esc_html($step['title']); ?></h3><p><?php echo esc_html($step['description']); ?> <span>Los campos con * son obligatorios.</span></p></div>
        <div class="precap-fields">
          <?php foreach ($config['fields'] as $field): ?>
            <?php if ($field['type'] === 'hidden-field' || !in_array($field['name'], $step['fields'], true)) continue;
              $name = $field['name'];
              $required = !empty($field['required']);
              $multiple = !empty($field['multiple']);
            ?>
            <div class="precap-field <?php echo in_array($name, ['observaciones','fotos'], true) ? 'precap-wide' : ''; ?>"<?php echo in_array($name, ['id_pph','competencia'], true) ? ' data-precap-conditional="' . esc_attr($name) . '" hidden' : ''; ?>>
              <label id="precap-label-<?php echo esc_attr($name); ?>" for="precap-<?php echo esc_attr($name); ?>"><?php echo esc_html(match ($name) { 'fotos' => 'Registro fotográfico', 'indicativo' => 'País / indicativo', default => ($field['label'] ?? $name) }); ?><?php echo $required ? ' *' : ''; ?></label>
              <?php if ($field['type'] === 'media-field'): ?>
                <label class="precap-upload" for="precap-fotos"><span aria-hidden="true">＋</span><strong>Agregar fotografías del inmueble</strong><small>Selecciona una o dos imágenes de tu dispositivo</small><input id="precap-fotos" name="fotos[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple required></label>
                <div class="precap-photo-preview" data-precap-photo-preview></div>
                <small>Una o dos fotografías, hasta 30 MB por archivo. Se comprimen automáticamente antes de subir.</small>
              <?php elseif ($field['type'] === 'textarea-field'): ?>
                <textarea id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" rows="3"></textarea>
              <?php elseif ($name === 'barrio'): ?>
                <select id="precap-barrio" name="barrio" data-precap-picker data-precap-add-dialog="precap-add-barrio"><option value="">Selecciona un barrio</option><?php foreach ($options['barrio'] as $option): ?><option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option><?php endforeach; ?></select>
              <?php elseif ($field['type'] === 'select-field' && (($options[$name] ?? []) !== [] || in_array($name, ['competencia','id_pph'], true))): ?>
                <select data-precap-picker<?php echo $name === 'competencia' ? ' data-precap-add-dialog="precap-add-inmobiliaria"' : ''; ?> id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name . ($multiple ? '[]' : '')); ?>"<?php echo $multiple ? ' multiple' : ''; ?><?php echo $required ? ' required' : ''; ?>>
                  <?php if (!$multiple): ?><option value="">Selecciona una opción</option><?php endif; ?>
                  <?php foreach ($options[$name] as $option): ?><option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option><?php endforeach; ?>
                </select>
                <?php if ($name === 'tipo_contacto'): ?><input class="precap-other-contact" id="precap-tipo_contacto_otro" name="tipo_contacto_otro" type="text" maxlength="80" placeholder="Escribe el tipo de contacto" data-precap-contact-other hidden disabled><?php endif; ?>
                <?php if ($name === 'indicativo'): ?><small class="precap-field-help">Este es el indicativo para mensajes por SMS o WS.</small><?php endif; ?>
                <?php if ($multiple): ?><small>Busca y marca una o varias opciones.</small><?php endif; ?>
              <?php else: ?>
                <input id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo in_array($name, ['telefono','celular'], true) ? 'tel' : 'text'; ?>"<?php echo $name === 'celular' ? ' inputmode="numeric" pattern="[0-9]{7,15}"' : ''; ?><?php echo $required ? ' required' : ''; ?>>
              <?php endif; ?>
              <?php if (in_array($name, ['barrio','competencia'], true) && Module::policy()->canAct('precaptacion_catalogos')): ?>
                <button type="button" class="precap-secondary" data-precap-open="precap-<?php echo $name === 'barrio' ? 'add-barrio' : 'add-inmobiliaria'; ?>">Añadir <?php echo $name === 'barrio' ? 'barrio' : 'inmobiliaria'; ?></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($index === 3): ?><div class="precap-review" data-precap-review></div><?php endif; ?>
        </section>
        <?php endforeach; ?>
        <p data-precap-message role="status" aria-live="polite"></p>
        </div>
        <footer class="precap-wizard-footer"><span data-precap-step-count>Paso 1 de 4</span><div><button type="button" class="precap-secondary" data-precap-back hidden>Anterior</button><button type="button" data-precap-next>Continuar →</button><button type="submit" hidden>Registrar precaptación</button></div></footer>
      </form>
    </dialog>
    <?php endif; ?>
    <dialog id="precap-notice" class="rounded-3xl border border-brand-border bg-white shadow-modal" aria-labelledby="precap-notice-title"><form method="dialog"><header><h2 id="precap-notice-title"></h2><button value="cancel" aria-label="Cerrar">×</button></header><p data-precap-notice-text></p><footer><button class="precap-secondary" value="cancel" data-precap-notice-cancel>Cancelar</button><button value="confirm" data-precap-notice-confirm>Continuar</button></footer></form></dialog>
    <?php if (Module::policy()->canAct('precaptacion_catalogos')): ?>
      <?php foreach (['barrio'=>'Barrio','inmobiliaria'=>'Inmobiliaria'] as $kind => $label): ?>
      <dialog id="precap-add-<?php echo $kind; ?>" aria-labelledby="precap-add-<?php echo $kind; ?>-title">
        <form data-precap-catalog="<?php echo $kind; ?>">
          <header><h2 id="precap-add-<?php echo $kind; ?>-title">Añadir <?php echo mb_strtolower($label); ?></h2><button type="button" data-precap-close aria-label="Cerrar">×</button></header>
          <p>Si ya existe, se seleccionará el registro existente.</p>
          <label><?php echo $label; ?> *<input name="<?php echo $kind; ?>" maxlength="180" required<?php echo $kind === 'barrio' ? ' data-precap-catalog-check' : ''; ?>></label>
          <?php if ($kind === 'barrio'): ?>
            <div class="precap-fields">
              <label class="precap-location-field">País *
                <span class="precap-location-control" data-precap-location-control="pais">
                  <select name="pais" required data-precap-location-select data-precap-catalog-check>
                    <option value="">Selecciona un país</option>
                    <?php
                    $countryFound = $userCountry === '';
                    foreach ($options['pais'] as $option):
                      $countryFound = $countryFound || $option['value'] === $userCountry;
                    ?>
                      <option value="<?php echo esc_attr($option['value']); ?>"<?php echo $option['value'] === $userCountry ? ' selected' : ''; ?>><?php echo esc_html($option['label']); ?></option>
                    <?php endforeach; ?>
                    <?php if (!$countryFound): ?><option value="<?php echo esc_attr($userCountry); ?>" selected><?php echo esc_html($userCountry); ?></option><?php endif; ?>
                  </select>
                  <input name="pais" maxlength="120" placeholder="Nuevo país" disabled hidden data-precap-location-input data-precap-catalog-check>
                  <button type="button" class="precap-inline-add" data-precap-location-add="pais" aria-expanded="false">Agregar país</button>
                </span>
              </label>
              <label class="precap-location-field">Ciudad *
                <span class="precap-location-control" data-precap-location-control="ciudad">
                  <select name="ciudad" required data-precap-location-select data-precap-city-select data-precap-catalog-check>
                    <option value="">Selecciona una ciudad</option>
                    <?php
                    $cityFound = $userCity === '';
                    foreach ($options['ciudad'] as $option):
                      $cityFound = $cityFound || ($option['value'] === $userCity && ($option['country'] ?? '') === $userCountry);
                    ?>
                      <option value="<?php echo esc_attr($option['value']); ?>" data-country="<?php echo esc_attr((string) ($option['country'] ?? '')); ?>"<?php echo $option['value'] === $userCity && ($option['country'] ?? '') === $userCountry ? ' selected' : ''; ?>><?php echo esc_html($option['label']); ?></option>
                    <?php endforeach; ?>
                    <?php if (!$cityFound): ?><option value="<?php echo esc_attr($userCity); ?>" data-country="<?php echo esc_attr($userCountry); ?>" selected><?php echo esc_html($userCity); ?></option><?php endif; ?>
                  </select>
                  <input name="ciudad" maxlength="120" placeholder="Nueva ciudad" disabled hidden data-precap-location-input data-precap-catalog-check>
                  <button type="button" class="precap-inline-add" data-precap-location-add="ciudad" aria-expanded="false">Agregar ciudad</button>
                </span>
              </label>
              <label>Latitud *<input name="latitud" type="number" step="any" min="-90" max="90" required></label>
              <label>Longitud *<input name="longitud" type="number" step="any" min="-180" max="180" required></label>
              <label>Código postal *<input name="codigo_postal" inputmode="numeric" pattern="[0-9]{4,10}" required></label>
            </div>
            <small data-precap-catalog-status="barrio" aria-live="polite"></small>
          <?php endif; ?>
          <p data-precap-message role="status" aria-live="polite"></p>
          <footer><button type="button" class="precap-secondary" data-precap-close>Cancelar</button><button type="submit">Guardar y seleccionar</button></footer>
        </form>
      </dialog>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
  }
}
