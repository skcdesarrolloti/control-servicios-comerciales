<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialPropertyEntryService;

final class CommercialPropertyEntryView
{
  public static function render(CommercialPropertyEntryService $service, int $taskId): string
  {
    $task = $service->task($taskId);
    $definition = $service::definition();
    $options = $service->options();
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $json = json_encode($definition, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    ob_start(); ?>
    <div class="commercial-entry-shell"><section class="csg cpe" data-commercial-property-entry data-property-definition="<?php echo $e($json); ?>">
      <div class="csg-form-heading"><div><span class="csg-eyebrow">Tarea #<?php echo $e($taskId); ?></span><h1>Ingresar inmueble</h1><p class="csg-help"><?php echo $e($task['asunto'] ?? ''); ?></p></div><button type="button" class="csg-secondary" data-commercial-return-case="<?php echo $e($taskId); ?>">Volver a la tarea</button></div>
      <form data-property-entry-form novalidate enctype="multipart/form-data">
        <input type="hidden" name="ticket_pk" value="<?php echo $e($taskId); ?>"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>">
        <fieldset class="csg-section"><legend>Modo de ingreso</legend><div class="csg-grid"><div><label for="cpe-mode">¿Cómo deseas ingresar el inmueble?</label><select id="cpe-mode" name="modo_ingreso"><option value="nuevo">Nuevo inmueble · generar código</option><option value="adelantado">Completar un código adelantado</option></select></div><div data-property-reserved hidden><label for="cpe-reserved">Código adelantado *</label><input id="cpe-reserved" name="codigo_adelantado" type="number" min="1" step="1" inputmode="numeric"><p class="csg-help">Debe existir y estar asignado a tu usuario. Solo se completan inmuebles en borrador.</p></div></div></fieldset>
        <?php $section = null; $number = 0; foreach ($definition['fields'] as $field):
          if ($field['section'] !== $section) {
            if ($section !== null) echo '</div></details>';
            $section = $field['section']; ++$number;
            echo '<details class="csg-section cpe-section"' . ($number === 1 ? ' open' : '') . '><summary>' . $e($number . '. ' . $section) . '</summary><div class="csg-grid">';
          }
          $name = $field['name']; $id = 'cpe-' . $name; $multiple = $service::multiple($field);
          $label = $field['label'] === $name ? ucfirst(str_replace('_', ' ', $name)) : $field['label'];
          $default = $name === 'id_ticket' ? (string) $taskId : ($name === 'id_propietario' ? (string) ($task['id_propietario'] ?? '') : ($name === 'ano_construccion' ? date('Y') : ''));
        ?><div data-property-field="<?php echo $e($name); ?>"<?php echo $field['type'] === 'textarea' ? ' class="csg-full"' : ''; ?>>
          <label for="<?php echo $e($id); ?>"><?php echo $e($label . ($field['required'] ? ' *' : '')); ?></label>
          <?php if ($field['source'] !== ''): ?>
            <select id="<?php echo $e($id); ?>" name="<?php echo $e($name . ($multiple ? '[]' : '')); ?>"<?php echo $multiple ? ' multiple size="4"' : ''; ?>>
              <?php if (!$multiple): ?><option value="">Selecciona una opción</option><?php endif; ?>
              <?php foreach ($options[$name] ?? [] as $choice): ?><option value="<?php echo $e($choice['value']); ?>"<?php echo $choice['value'] === $default ? ' selected' : ''; ?><?php foreach (['pais','departamento','ciudad','ruta_asignada','latitud','longitud','codigo_postal'] as $extra) if (isset($choice[$extra])) echo ' data-' . $extra . '="' . $e($choice[$extra]) . '"'; ?>><?php echo $e($choice['label'] . ($name === 'id_propietario' ? ' · #' . $choice['value'] : '')); ?></option><?php endforeach; ?>
            </select>
          <?php elseif ($field['type'] === 'textarea'): ?>
            <textarea id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" rows="3" maxlength="20000"></textarea>
          <?php elseif ($field['type'] === 'media'): ?>
            <input id="<?php echo $e($id); ?>" name="uso_suelo" type="file" accept="application/pdf"><p class="csg-help">Opcional. PDF de hasta 6 MB.</p>
          <?php else:
            $type = in_array($field['type'], ['number','calculated'], true) ? 'number' : (in_array($field['type'], ['date','time'], true) ? $field['type'] : 'text'); ?>
            <input id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" type="<?php echo $e($type); ?>" value="<?php echo $e($default); ?>"<?php echo $type === 'number' ? ' min="0" step="any"' : ''; ?><?php echo in_array($name, ['id_ticket','ruta','edad_inmueble'], true) ? ' readonly' : ''; ?>>
          <?php endif; ?>
        </div><?php endforeach; if ($section !== null) echo '</div></details>'; ?>
        <p class="csg-help">Paso 1 de 2: guarda los datos del inmueble; después podrás subir portada, galería y video. La información se guardará en borrador y esta tarea pasará a «Por publicar».</p><div class="csg-error" role="alert" data-property-error hidden></div>
        <div class="csg-footer"><span class="csg-help">Los campos con * son obligatorios.</span><button type="submit" class="csg-primary" data-property-submit>Guardar inmueble</button></div>
      </form>
      <div class="csg-result" role="status" data-property-result hidden></div>
    </section></div>
    <?php return (string) ob_get_clean();
  }
}
