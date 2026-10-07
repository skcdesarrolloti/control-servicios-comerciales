<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

final class FormView
{
  public static function render(Repository $repository): string
  {
    $config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/precaptacion-form.json'), true);
    $options = $repository->options();
    ob_start();
    ?>
    <?php if (Module::policy()->canAct('precaptacion_crear')): ?>
    <dialog id="precap-create" aria-labelledby="precap-create-title">
      <form id="precap-create-form" enctype="multipart/form-data">
        <header><h2 id="precap-create-title">Registrar precaptación</h2><button type="button" data-precap-close aria-label="Cerrar formulario">×</button></header>
        <p>Completa los datos de la oportunidad. Los campos con * son obligatorios.</p>
        <div class="precap-fields">
          <?php foreach ($config['fields'] as $field): ?>
            <?php if ($field['type'] === 'hidden-field') continue;
              $name = $field['name'];
              $required = !empty($field['required']);
              $multiple = !empty($field['multiple']);
            ?>
            <div class="precap-field <?php echo $name === 'observaciones' ? 'precap-wide' : ''; ?>"<?php echo in_array($name, ['id_pph','competencia'], true) ? ' data-precap-conditional="' . esc_attr($name) . '" hidden' : ''; ?>>
              <label for="precap-<?php echo esc_attr($name); ?>"><?php echo esc_html($name === 'fotos' ? 'Registro fotográfico' : ($field['label'] ?? $name)); ?><?php echo $required ? ' *' : ''; ?></label>
              <?php if ($field['type'] === 'media-field'): ?>
                <input id="precap-fotos" name="fotos[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple required>
                <small>Una o dos fotografías, hasta 10 MB por archivo.</small>
              <?php elseif ($field['type'] === 'textarea-field'): ?>
                <textarea id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" rows="3"></textarea>
              <?php elseif ($name === 'barrio'): ?>
                <input id="precap-barrio" name="barrio" list="precap-barrios" autocomplete="off" placeholder="Busca un barrio">
                <datalist id="precap-barrios"><?php foreach ($options['barrio'] as $option): ?><option value="<?php echo esc_attr($option['value']); ?>"><?php endforeach; ?></datalist>
              <?php elseif ($field['type'] === 'select-field' && (($options[$name] ?? []) !== [] || in_array($name, ['competencia','id_pph'], true))): ?>
                <?php if ($multiple): ?><input type="search" data-precap-search="precap-<?php echo esc_attr($name); ?>" aria-label="Buscar en <?php echo esc_attr($field['label']); ?>" placeholder="Buscar opción…"><?php endif; ?>
                <select id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name . ($multiple ? '[]' : '')); ?>"<?php echo $multiple ? ' multiple size="4"' : ''; ?><?php echo $required ? ' required' : ''; ?>>
                  <?php if (!$multiple): ?><option value="">Selecciona una opción</option><?php endif; ?>
                  <?php foreach ($options[$name] as $option): ?><option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option><?php endforeach; ?>
                </select>
                <?php if ($multiple): ?><small>Puedes seleccionar varias opciones con Ctrl o Cmd.</small><?php endif; ?>
              <?php else: ?>
                <input id="precap-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo in_array($name, ['telefono','celular'], true) ? 'tel' : 'text'; ?>"<?php echo $name === 'celular' ? ' inputmode="numeric" pattern="[0-9]{7,15}"' : ''; ?><?php echo $required ? ' required' : ''; ?>>
              <?php endif; ?>
              <?php if (in_array($name, ['barrio','competencia'], true) && Module::policy()->canAct('precaptacion_catalogos')): ?>
                <button type="button" class="precap-secondary" data-precap-open="precap-<?php echo $name === 'barrio' ? 'add-barrio' : 'add-inmobiliaria'; ?>">Añadir <?php echo $name === 'barrio' ? 'barrio' : 'inmobiliaria'; ?></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p data-precap-message role="status" aria-live="polite"></p>
        <footer><button type="button" class="precap-secondary" data-precap-close>Cancelar</button><button type="submit">Registrar precaptación</button></footer>
      </form>
    </dialog>
    <?php endif; ?>
    <?php if (Module::policy()->canAct('precaptacion_catalogos')): ?>
      <?php foreach (['barrio'=>'Barrio','inmobiliaria'=>'Inmobiliaria'] as $kind => $label): ?>
      <dialog id="precap-add-<?php echo $kind; ?>" aria-labelledby="precap-add-<?php echo $kind; ?>-title">
        <form data-precap-catalog="<?php echo $kind; ?>">
          <header><h2 id="precap-add-<?php echo $kind; ?>-title">Añadir <?php echo mb_strtolower($label); ?></h2><button type="button" data-precap-close aria-label="Cerrar">×</button></header>
          <p>Si ya existe, se seleccionará el registro existente.</p>
          <label><?php echo $label; ?> *<input name="<?php echo $kind; ?>" maxlength="180" required></label>
          <?php if ($kind === 'barrio'): ?>
            <div class="precap-fields">
              <label>País *<input name="pais" value="<?php echo esc_attr((string) get_user_meta(get_current_user_id(), 'pais-user', true)); ?>" required></label>
              <label>Ciudad *<input name="ciudad" value="<?php echo esc_attr((string) get_user_meta(get_current_user_id(), 'ciudad-user', true)); ?>" required></label>
              <label>Latitud *<input name="latitud" type="number" step="any" min="-90" max="90" required></label>
              <label>Longitud *<input name="longitud" type="number" step="any" min="-180" max="180" required></label>
              <label>Código postal *<input name="codigo_postal" inputmode="numeric" pattern="[0-9]{4,10}" required></label>
            </div>
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
