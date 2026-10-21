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
      <form data-property-media-form>
        <?php echo self::fields('cpm'); ?>
        <div class="csg-footer"><span class="csg-help">La portada y al menos una foto de galería son obligatorias.</span><button type="submit" class="csg-primary" data-media-save>Guardar multimedia</button></div>
      </form>
    </section></div>
    <?php return (string) ob_get_clean();
  }

  public static function embedded(int $taskId): string
  {
    $state = ['ticket_pk'=>$taskId,'id_inmueble'=>0,'portada'=>null,'galeria'=>[],'video_del_inmueble'=>'','max_gallery'=>12];
    return '<section class="cpm" data-commercial-property-media data-media-embedded data-media-state="' . htmlspecialchars(json_encode($state, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') . '">' . self::fields('cpe-media') . '</section>';
  }

  private static function fields(string $id): string
  {
    ob_start(); ?>
      <div class="cpm-intro"><span class="cpe-icon"><i class="fa-solid fa-camera" aria-hidden="true"></i></span><div><h2>Las mejores fotos cuentan su historia</h2><p>Portada, galería y video, en el mismo registro. Puedes editar cada imagen antes de guardar.</p></div></div>
      <div class="cpm-upload-layout">
        <fieldset class="cpm-upload-section"><legend>Foto de portada <span>*</span></legend><label class="cpm-dropzone" for="<?php echo $id; ?>-cover" data-media-drop="cover"><i class="fa-solid fa-image" aria-hidden="true"></i><strong>Seleccionar portada</strong><span>Arrastra una imagen o pulsa para elegir</span><input id="<?php echo $id; ?>-cover" type="file" accept="image/jpeg,image/png" data-media-input="cover"></label><p class="csg-help">Elige una estancia luminosa. Evita usar la fachada.</p><div class="cpm-grid" data-media-cover></div></fieldset>
        <fieldset class="cpm-upload-section"><legend>Galería del inmueble <span>*</span></legend><label class="cpm-dropzone" for="<?php echo $id; ?>-gallery" data-media-drop="gallery"><i class="fa-solid fa-images" aria-hidden="true"></i><strong>Añadir fotografías</strong><span>Selecciona varias fotos o arrástralas aquí</span><input id="<?php echo $id; ?>-gallery" type="file" accept="image/jpeg,image/png" multiple data-media-input="gallery"></label><p class="csg-help" data-media-count></p><div class="cpm-grid" data-media-gallery></div></fieldset>
      </div>
      <p class="cpm-rules"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> JPG o PNG · hasta 1 MB por foto · de 500 × 500 a 1700 × 1200 px. Ajustamos las fotos grandes automáticamente.</p>
      <div class="cpm-video"><label for="<?php echo $id; ?>-video"><i class="fa-brands fa-youtube" aria-hidden="true"></i> Video del inmueble <span>(opcional)</span></label><input id="<?php echo $id; ?>-video" type="url" name="video_del_inmueble" placeholder="https://www.youtube.com/watch?v=..."><p class="csg-help">Comparte un recorrido de YouTube para conocer mejor los espacios.</p></div>
      <p role="status" aria-live="polite" data-media-status></p><div class="csg-error" role="alert" data-media-error hidden></div>
    <?php return (string) ob_get_clean();
  }
}
