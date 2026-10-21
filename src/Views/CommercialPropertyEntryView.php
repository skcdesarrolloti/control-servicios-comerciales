<?php
declare(strict_types=1);
namespace SCM\Views;

use SCM\Commercial\CommercialPropertyEntryService;

final class CommercialPropertyEntryView
{
  public static function render(CommercialPropertyEntryService $service, int $taskId): string
  {
    $task = $service->task($taskId); $definition = $service::definition(); $options = $service->options();
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $json = json_encode($definition, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $steps = [['Datos','fa-house','Empecemos por lo esencial'],['Detalles','fa-sliders','Espacios, valores y condiciones'],['Ubicación','fa-location-dot','Encuentra su lugar en el mapa'],['Características','fa-list-check','Lo que hace especial al inmueble'],['Fotos y video','fa-camera','Muéstralo desde su mejor ángulo']];
    $panels = [[],[],[],[],[]];
    foreach ($definition['fields'] as $field) {
      $step = match ($field['section']) { 'Datos de ingreso','Describe el inmueble','Cuéntanos sobre el inmueble'=>0, 'Ubicación del inmueble'=>2, 'Algunos Detalles de tu inmueble','zonas sociales'=>3, default=>1 };
      $panels[$step][$field['section']][] = $field;
    }
    ob_start(); ?>
    <div class="commercial-entry-shell cpe-shell"><section class="csg cpe" data-commercial-property-entry data-property-definition="<?php echo $e($json); ?>">
      <header class="cpe-hero"><div><span class="csg-eyebrow">Portafolio comercial <span>/</span> Tarea #<?php echo $e($taskId); ?></span><h1>Ingresar inmueble</h1><p><?php echo $e($task['asunto'] ?? 'Todos los detalles de tu próxima publicación, en un solo lugar.'); ?></p></div><button type="button" class="csg-secondary" data-commercial-return-case="<?php echo $e($taskId); ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a la tarea</button></header>
      <nav class="cpe-steps" aria-label="Secciones del inmueble"><?php foreach ($steps as $index => [$title,$icon,$description]): ?><button type="button" class="cpe-step" data-entry-tab="<?php echo $index; ?>" aria-controls="cpe-panel-<?php echo $index; ?>" aria-current="<?php echo $index === 0 ? 'step' : 'false'; ?>"><span class="cpe-step-number"><?php echo $index + 1; ?></span><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><span><?php echo $e($title); ?></span><i class="fa-solid fa-check cpe-step-check" aria-hidden="true"></i></button><?php endforeach; ?></nav>
      <form data-property-entry-form novalidate enctype="multipart/form-data">
        <input type="hidden" name="ticket_pk" value="<?php echo $e($taskId); ?>"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>">
        <?php foreach ($steps as $index => [$title,$icon,$description]): ?>
          <section class="cpe-panel" id="cpe-panel-<?php echo $index; ?>" data-entry-panel="<?php echo $index; ?>"<?php echo $index ? ' hidden' : ''; ?> aria-label="<?php echo $e($title); ?>">
            <div class="cpe-panel-heading"><span class="cpe-icon"><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i></span><div><span class="cpe-kicker">Sección <?php echo $index + 1; ?> de 5</span><h2><?php echo $e($description); ?></h2></div></div>
            <?php if ($index === 0): ?><div class="cpe-mode csg-grid"><div><label for="cpe-mode">¿Cómo deseas ingresar el inmueble?</label><select id="cpe-mode" name="modo_ingreso"><option value="nuevo">Nuevo inmueble · generar código</option><option value="adelantado">Completar un código adelantado</option></select></div><div data-property-reserved hidden><label for="cpe-reserved">Código adelantado *</label><input id="cpe-reserved" name="codigo_adelantado" type="number" min="1" step="1" inputmode="numeric"><p class="csg-help">Completa un inmueble en borrador asignado a tu usuario.</p></div></div><?php endif; ?>
            <?php if ($index === 3): ?><div class="cpe-neighborhood" role="status" data-neighborhood-status><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>Elige un barrio en Ubicación para ver las características registradas en esa zona. Marca solo las que tenga este inmueble.</span></div><?php endif; ?>
            <?php foreach ($panels[$index] as $section => $fields): ?>
              <details class="cpe-section" open><summary><?php echo $e($section === 'zonas sociales' ? 'Zonas sociales' : $section); ?></summary><div class="csg-grid">
              <?php foreach ($fields as $field):
                $name = $field['name']; $id = 'cpe-' . $name; $multiple = $service::multiple($field);
                $feature = in_array($name, ['interiores','exteriores','alrededores','zonas_sociales'], true);
                $choices = $options[$name] ?? [];
                $cards = $feature || $name === 'tipo_inmueble' || ($field['source'] === 'glossary' && $field['glossary'] === 175);
                $label = ['interiores'=>'Características internas','exteriores'=>'Características externas','alrededores'=>'Alrededores','zonas_sociales'=>'Zonas sociales'][$name] ?? ($field['label'] === $name ? ucfirst(str_replace('_', ' ', $name)) : $field['label']);
                $default = $name === 'id_ticket' ? (string) $taskId : ($name === 'id_propietario' ? (string) ($task['id_propietario'] ?? '') : ($name === 'ano_construccion' ? date('Y') : ''));
              ?><div data-property-field="<?php echo $e($name); ?>" class="<?php echo $cards || $field['type'] === 'textarea' ? 'csg-full' : ''; ?>">
                <label for="<?php echo $e($id); ?>"><?php echo $e($label . ($field['required'] ? ' *' : '')); ?><?php if ($feature): ?><span class="cpe-selected-count" data-feature-count="<?php echo $e($name); ?>">0 seleccionadas</span><?php endif; ?></label>
                <?php if ($field['source'] !== ''): ?>
                  <select id="<?php echo $e($id); ?>" name="<?php echo $e($name . ($multiple ? '[]' : '')); ?>"<?php echo $multiple ? ' multiple size="4"' : ''; ?><?php echo $cards ? ' class="cpe-native-choice" tabindex="-1" aria-label="' . $e($label) . '"' : ''; ?>>
                    <?php if (!$multiple): ?><option value="">Selecciona una opción</option><?php endif; ?>
                    <?php foreach ($choices as $choice): ?><option value="<?php echo $e($choice['value']); ?>"<?php echo $choice['value'] === $default ? ' selected' : ''; ?><?php foreach (['pais','departamento','ciudad','ruta_asignada','latitud','longitud','codigo_postal'] as $extra) if (isset($choice[$extra])) echo ' data-' . $extra . '="' . $e($choice[$extra]) . '"'; ?>><?php echo $e($choice['label'] . ($name === 'id_propietario' ? ' · #' . $choice['value'] : '')); ?></option><?php endforeach; ?>
                  </select>
                  <?php if ($cards): ?>
                    <div class="cpe-choice-group" data-choice-group="<?php echo $e($name); ?>">
                      <?php if ($feature): ?><div class="cpe-feature-tools"><label class="cpe-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Buscar una característica…" aria-label="<?php echo $e('Buscar en ' . $label); ?>" data-feature-search></label><button type="button" class="csg-secondary" data-apply-suggestions hidden>Marcar sugeridas</button></div><?php endif; ?>
                      <div class="cpe-choices<?php echo $feature ? ' cpe-feature-choices' : ''; ?>" role="group" aria-label="<?php echo $e($label); ?>">
                        <?php foreach ($choices as $choice): ?><button type="button" class="cpe-choice" data-choice-value="<?php echo $e($choice['value']); ?>" aria-pressed="false"><i class="fa-solid <?php echo $e(CommercialPropertyIcons::name($choice['label'])); ?>" aria-hidden="true"></i><span><?php echo $e($choice['label']); ?></span><span class="cpe-choice-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span><small data-choice-suggestion hidden></small></button><?php endforeach; ?>
                      </div><p class="csg-help" data-feature-empty hidden>No hay características que coincidan con la búsqueda.</p>
                    </div>
                  <?php endif; ?>
                <?php elseif ($field['type'] === 'textarea'): ?><textarea id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" rows="4" maxlength="20000"></textarea>
                <?php elseif ($field['type'] === 'media'): ?><input id="<?php echo $e($id); ?>" name="uso_suelo" type="file" accept="application/pdf"><p class="csg-help">Opcional. PDF de hasta 6 MB.</p>
                <?php else: $type = in_array($field['type'], ['number','calculated'], true) ? 'number' : (in_array($field['type'], ['date','time'], true) ? $field['type'] : 'text'); ?><input id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" type="<?php echo $e($type); ?>" value="<?php echo $e($default); ?>"<?php echo $type === 'number' ? ' min="0" step="any"' : ''; ?><?php echo in_array($name, ['id_ticket','ruta','edad_inmueble'], true) ? ' readonly' : ''; ?>><?php endif; ?>
              </div><?php endforeach; ?></div></details>
            <?php endforeach; ?>
            <?php if ($index === 4) echo CommercialPropertyMediaView::embedded($taskId); ?>
          </section>
        <?php endforeach; ?>
        <div class="csg-error" role="alert" data-property-error hidden></div><p class="cpe-save-status" role="status" data-property-save-status></p>
        <footer class="cpe-footer"><div><span class="cpe-kicker">Registro del inmueble</span><strong data-entry-progress>Datos del inmueble</strong></div><div class="cpe-footer-actions"><button type="button" class="csg-secondary" data-entry-prev hidden>Anterior</button><button type="button" class="csg-primary" data-entry-next>Continuar <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button><button type="submit" class="csg-primary" data-property-submit hidden><i class="fa-solid fa-check" aria-hidden="true"></i> Guardar inmueble</button></div></footer>
      </form><div class="csg-result" role="status" data-property-result hidden></div>
    </section></div>
    <?php return (string) ob_get_clean();
  }
}
