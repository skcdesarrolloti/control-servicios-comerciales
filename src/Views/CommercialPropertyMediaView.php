<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialPropertyMediaService;

final class CommercialPropertyMediaView
{
  public static function render(CommercialPropertyMediaService $service, int $taskId, int $code = 0): string
  {
    $state = $service->context($taskId, $code);
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start(); ?>
    <div class="commercial-entry-shell"><section class="csg cpm" data-commercial-property-media data-media-state="<?php echo $e(json_encode($state, JSON_THROW_ON_ERROR)); ?>">
      <div class="csg-form-heading"><div><span class="csg-eyebrow">Inmueble #<?php echo $e($state['id_inmueble']); ?> · Tarea #<?php echo $e($taskId); ?></span><h1>Multimedia del inmueble</h1><p class="csg-help">Añade la portada, ordena las fotos y agrega un video.</p></div><button type="button" class="csg-secondary" data-commercial-return-case="<?php echo $e($taskId); ?>">Volver a la tarea</button></div>
      <p class="csg-help">JPG o PNG. Máximo 1 MB por imagen; de 500 × 500 a 1700 × 1200 píxeles. Las fotos grandes se ajustan al seleccionarlas. Evita usar la fachada como portada.</p>
      <form data-property-media-form>
        <fieldset class="csg-section"><legend>Portada *</legend><label for="cpm-cover">Seleccionar portada</label><input id="cpm-cover" type="file" accept="image/jpeg,image/png" data-media-input="cover"><div class="cpm-grid" data-media-cover></div></fieldset>
        <fieldset class="csg-section"><legend>Galería *</legend><label for="cpm-gallery">Añadir fotografías</label><input id="cpm-gallery" type="file" accept="image/jpeg,image/png" multiple data-media-input="gallery"><p class="csg-help" data-media-count></p><div class="cpm-grid" data-media-gallery></div></fieldset>
        <fieldset class="csg-section"><legend>Video del inmueble</legend><label for="cpm-video">Enlace de YouTube (opcional)</label><input id="cpm-video" type="url" name="video_del_inmueble" placeholder="https://www.youtube.com/watch?v=..."></fieldset>
        <p role="status" aria-live="polite" data-media-status></p><div class="csg-error" role="alert" data-media-error hidden></div>
        <div class="csg-footer"><span class="csg-help">La portada y al menos una foto de galería son obligatorias.</span><button type="submit" class="csg-primary" data-media-save>Guardar multimedia</button></div>
      </form>
    </section></div>
    <?php return (string) ob_get_clean();
  }
}
