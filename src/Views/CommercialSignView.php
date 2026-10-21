<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSignService;

final class CommercialSignView
{
  public static function render(CommercialSignService $service, CommercialAccessPolicy $policy, array $context = []): string
  {
    if (!$policy->canAct('registrar_aviso')) return '<section class="csg"><h1>Añadir aviso</h1><p>Tu cargo puede consultar esta página, pero no tiene permiso para registrar avisos.</p></section>';
    $options = $service->options();
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $select = static function (string $name, string $label, array $values, bool $multiple = false) use ($e): string {
      $html = '<label for="csg-' . $name . '">' . $label . '</label><select id="csg-' . $name . '" name="' . $name . ($multiple ? '[]' : '') . '"' . ($multiple ? ' multiple size="4"' : ' required') . '>';
      if (!$multiple) $html .= '<option value="">Selecciona una opción</option>';
      foreach ($values as $item) $html .= '<option value="' . $e($item['value']) . '">' . $e($item['label']) . '</option>';
      return $html . '</select>';
    };
    ob_start(); ?>
    <section class="csg" data-commercial-signs<?php if ($context): ?> data-sign-task="<?php echo $e($context['id_ticket']); ?>" data-sign-property="<?php echo $e($context['id_inmueble'] ?? ''); ?>"<?php endif; ?>>
      <?php if ($context): ?><button type="button" class="csg-secondary" data-commercial-return-case="<?php echo $e($context['id_ticket']); ?>">Volver a la tarea</button><?php endif; ?>
      <div class="csg-heading"><span class="csg-eyebrow">Actividades comerciales</span><h1>Añadir aviso</h1><p>Registra la instalación o el retoque de un aviso y deja constancia de tu visita al inmueble.</p></div>
      <div class="csg-choices" data-sign-choices>
        <button type="button" class="csg-choice" data-sign-type="Nuevo" aria-controls="csg-form" aria-expanded="false">
          <span class="csg-choice-icon material-symbols-outlined" aria-hidden="true">add_photo_alternate</span>
          <span class="csg-choice-tag">Primera instalación</span><strong>Aviso nuevo</strong><span>Registra un aviso nuevo con sus fotografías y la ubicación del inmueble.</span><span class="csg-choice-action">Registrar aviso <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></span>
        </button>
        <button type="button" class="csg-choice" data-sign-type="Retoque" aria-controls="csg-form" aria-expanded="false">
          <span class="csg-choice-icon material-symbols-outlined" aria-hidden="true">brush</span>
          <span class="csg-choice-tag">Seguimiento en campo</span><strong>Retoque de aviso</strong><span>Registra el retoque de un aviso existente o el motivo por el que no pudiste colocarlo.</span><span class="csg-choice-action">Registrar retoque <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></span>
        </button>
      </div>
      <div class="csg-result" data-sign-result role="status" hidden></div>
      <form id="csg-form" data-sign-form hidden enctype="multipart/form-data">
        <input type="hidden" name="tipo_aviso"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>">
        <input type="hidden" name="mapa_latitud"><input type="hidden" name="mapa_longitud"><input type="hidden" name="mapa_precision">
        <div class="csg-form-heading"><div><span class="csg-eyebrow">Registro de visita</span><h2 tabindex="-1" data-sign-title>Aviso nuevo</h2></div><button type="button" class="csg-secondary" data-sign-back><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Cambiar tipo</button></div>
        <fieldset class="csg-section"><legend><span>1</span> Tarea e inmueble</legend><p class="csg-help">Escribe la tarea asignada y el código del inmueble para consultar sus datos.</p>
          <div class="csg-grid"><div><label for="csg-ticket">Número de tarea *</label><input id="csg-ticket" name="id_ticket" type="number" min="1" step="1" required inputmode="numeric" placeholder="Ej. 12345"></div><div><label for="csg-property">Código del inmueble *</label><input id="csg-property" name="id_inmueble" type="number" min="1" step="1" required inputmode="numeric" placeholder="Ej. 67890"></div></div>
          <button type="button" class="csg-secondary csg-lookup" data-sign-lookup><span class="material-symbols-outlined" aria-hidden="true">search</span>Consultar inmueble</button><p class="csg-status" data-sign-property-status role="status">Los datos del inmueble aparecerán al consultar.</p>
          <div class="csg-grid"><div><label for="csg-barrio">Barrio *</label><select id="csg-barrio" name="barrio_id" required><option value="">Selecciona un barrio</option><?php foreach ($options['barrio'] as $row): ?><option value="<?php echo $e($row['_ID']); ?>" data-route="<?php echo $e($row['ruta_asignada']); ?>" data-name="<?php echo $e($row['barrio']); ?>"><?php echo $e($row['barrio'] . ($row['ciudad'] ? ' · ' . $row['ciudad'] : '')); ?></option><?php endforeach; ?></select><input type="text" data-sign-retouch-barrio readonly hidden aria-label="Barrio del inmueble"></div><div><label for="csg-ruta">Ruta *</label><input id="csg-ruta" name="ruta" readonly placeholder="Se completa automáticamente"></div><div><?php echo $select('tipo_inmueble', 'Tipo de inmueble *', $options['tipo_inmueble']); ?></div><div><?php echo $select('categoria', 'Categoría *', $options['categoria']); ?></div><div class="csg-full"><label for="csg-reference">Punto de referencia *</label><textarea id="csg-reference" name="punto_referencia" rows="2" maxlength="1000" required placeholder="Describe cómo identificar el inmueble"></textarea></div></div>
        </fieldset>
        <fieldset class="csg-section"><legend><span>2</span> Resultado de la visita</legend><div class="csg-grid">
          <div data-sign-retouch hidden><label for="csg-pego">¿Pegó aviso? *</label><select id="csg-pego" name="pego_aviso" required><option value="Si">Sí</option><option value="No">No</option></select></div>
          <div data-sign-reason hidden><?php echo $select('razon', 'Motivo por el que no pegó aviso *', $options['razon']); ?></div>
          <div><label for="csg-decoy">¿Pegó aviso señuelo? *</label><select id="csg-decoy" name="aviso_senuelo" required><option value="">Selecciona una opción</option><option value="Si">Sí</option><option value="No">No</option></select></div>
          <div><label for="csg-quantity">Cantidad de avisos *</label><input id="csg-quantity" name="cantidad" type="number" min="1" max="1000" step="1" value="1" required inputmode="numeric"></div>
          <div><?php echo $select('promocionado_por', 'Promocionado por', $options['promocionado_por'], true); ?><p class="csg-help">Puedes seleccionar varias opciones.</p></div>
          <div data-sign-competition hidden><?php echo $select('competencia', 'Inmobiliarias presentes', array_map(static fn(array $row): array => ['value'=>$row['inmobiliaria'],'label'=>$row['inmobiliaria']], $options['competencia']), true); ?></div>
        </div><p class="csg-warning" data-sign-unpublish hidden>Este motivo despublicará el inmueble y registrará el cambio en su historial.</p></fieldset>
        <fieldset class="csg-section"><legend><span>3</span> Evidencia y ubicación</legend><label for="csg-photos">Fotografías de la visita *</label><input id="csg-photos" name="fotos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/tiff,image/avif,image/heic,image/heif" multiple required aria-describedby="csg-photo-help"><p id="csg-photo-help" class="csg-help">Entre 1 y 3 fotografías. Máximo <?php echo $e(round(min(defined('SCM_UPLOAD_MAX_BYTES') ? SCM_UPLOAD_MAX_BYTES : 10485760, 10485760) / 1048576, 1)); ?> MB por foto.</p><div class="csg-previews" data-sign-previews></div>
          <div class="csg-location"><span class="material-symbols-outlined" aria-hidden="true">my_location</span><div><strong>Ubicación actual del inmueble</strong><p class="csg-help">Captúrala durante la visita y permite el acceso a la ubicación de tu dispositivo.</p><p class="csg-status" data-sign-location-status role="status">Ubicación pendiente de capturar.</p><a data-sign-map target="_blank" rel="noopener noreferrer" hidden>Ver ubicación en Google Maps</a></div><button type="button" class="csg-secondary" data-sign-capture>Capturar ubicación</button></div>
        </fieldset>
        <div class="csg-error" role="alert" data-sign-error hidden></div><div class="csg-footer"><p>Los campos con * son obligatorios.</p><button type="submit" class="csg-primary" data-sign-submit>Guardar aviso</button></div>
      </form>
    </section>
    <?php
    $html = (string) ob_get_clean();
    if ($context) {
      $prefix = 'csg-task-' . (int) $context['id_ticket'] . '-';
      $html = str_replace(['id="csg-','for="csg-','aria-controls="csg-','aria-describedby="csg-'], ['id="' . $prefix,'for="' . $prefix,'aria-controls="' . $prefix,'aria-describedby="' . $prefix], $html);
    }
    return $html;
  }
}
