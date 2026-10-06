<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;

final class CommercialNotificationsView
{
  public static function render(CommercialNotificationsService $service, CommercialAccessPolicy $policy): string
  {
    $types = $service->types();
    $templates = $service->whatsappTemplates();
    $sender = $service->senderProfile();
    $config = ['templates' => $templates, 'sender' => $sender, 'request_id' => bin2hex(random_bytes(16)), 'max_bytes' => (int) SCM_UPLOAD_MAX_BYTES];
    $field = 'w-full rounded-xl border border-slate-200 bg-surface-container-lowest px-3 py-2.5 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container';
    $secondary = 'inline-flex items-center justify-center gap-2 min-h-[44px] rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium hover:bg-surface-container-low focus-visible:ring-2 focus-visible:ring-primary-container disabled:opacity-50 disabled:cursor-not-allowed';
    ob_start();
?>
<section class="px-4 md:px-margin py-6 space-y-6 [&_[hidden]]:!hidden" data-commercial-notifications data-notification-config="<?php echo esc_attr(json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)); ?>">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 rounded-2xl bg-primary-container flex items-center justify-center text-on-surface"><span class="material-symbols-outlined" aria-hidden="true">campaign</span></div>
      <div><h2 class="text-headline-md font-semibold">Notificaciones</h2><p class="text-sm text-secondary"><?php echo $policy->canManage() ? 'Contacta a propietarios, arrendatarios, copropiedades y Club PPH.' : 'Contacta a las personas vinculadas a tus inmuebles, contratos y registros.'; ?></p></div>
    </div>
    <span class="self-start rounded-full bg-surface-container-low px-3 py-1.5 text-xs font-medium text-secondary flex items-center gap-1.5"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified_user</span><?php echo $policy->canManage() ? 'Acceso a todos los destinatarios' : 'Solo tus contactos vinculados'; ?></span>
  </div>
  <div class="inline-flex flex-wrap gap-2 rounded-2xl bg-surface-container-low p-1.5" role="tablist" aria-label="Vistas de notificaciones">
    <button type="button" role="tab" aria-selected="true" aria-controls="notif-recipients-panel" id="notif-recipients-tab" data-notif-view="recipients" class="<?php echo $secondary; ?> bg-white"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">groups</span>Destinatarios</button>
    <button type="button" role="tab" aria-selected="false" aria-controls="notif-queue-panel" id="notif-queue-tab" data-notif-view="queue" class="<?php echo $secondary; ?>"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">schedule_send</span>Cola de notificaciones</button>
  </div>
  <p data-notif-feedback role="status" aria-live="polite" class="text-sm font-medium text-secondary" hidden></p>
  <div id="notif-recipients-panel" role="tabpanel" aria-labelledby="notif-recipients-tab" data-notif-panel="recipients" class="space-y-5">
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
      <?php foreach ($types as $key => $type): ?>
      <button type="button" data-notif-type="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $key === 'propietarios' ? 'true' : 'false'; ?>" class="p-4 rounded-2xl bg-white border <?php echo $key === 'propietarios' ? 'border-primary-container ring-2 ring-primary-container' : 'border-slate-200'; ?> text-left hover:shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container transition-shadow">
        <span class="flex items-center justify-between gap-2 text-sm font-medium"><span><?php echo esc_html($type['label']); ?></span><span class="material-symbols-outlined text-secondary text-[20px]" aria-hidden="true"><?php echo ['propietarios' => 'home_work', 'arrendatarios' => 'key', 'copropiedades' => 'apartment', 'club_pph' => 'loyalty'][$key]; ?></span></span>
        <strong class="block text-2xl mt-2" data-notif-total="<?php echo esc_attr($key); ?>">—</strong><span class="block text-xs text-secondary mt-1" data-notif-contact="<?php echo esc_attr($key); ?>">Cargando contactos…</span>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
      <div class="xl:col-span-7 rounded-2xl bg-white border border-slate-200 shadow-card overflow-hidden">
        <div class="p-5 border-b border-slate-100 space-y-4">
          <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-title-md">1. Selecciona destinatarios</h3><span class="text-xs bg-primary-container/30 text-on-surface px-3 py-1.5 rounded-full" data-notif-selected>0 seleccionados</span></div>
          <form data-notif-search class="space-y-3">
            <div><label for="notif-search" class="block text-xs font-medium mb-1.5">Nombre, documento, correo o celular</label><input id="notif-search" name="q" type="search" maxlength="150" class="<?php echo $field; ?>" placeholder="Buscar contacto…"></div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" data-notif-contract-filters>
              <div><label for="notif-contract-status" class="block text-xs font-medium mb-1.5">Estado del contrato</label><select id="notif-contract-status" name="contract_status" class="<?php echo $field; ?>"><option value="">Todos</option value="activos">Activo</option><option value="no_activos">No activo</option></select></div>
              <div><label for="notif-property" class="block text-xs font-medium mb-1.5">Inmueble SIMI</label><input id="notif-property" name="inmueble_simi" maxlength="50" class="<?php echo $field; ?>" placeholder="Código del inmueble"></div>
              <div><label for="notif-contract" class="block text-xs font-medium mb-1.5">Contrato</label><input id="notif-contract" name="contract_number" maxlength="50" class="<?php echo $field; ?>" placeholder="Número de contrato"></div>
            </div>
            <div class="flex flex-wrap gap-2"><button type="submit" class="<?php echo $secondary; ?> bg-inverse-surface text-white hover:bg-secondary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">search</span>Buscar</button><button type="reset" class="<?php echo $secondary; ?>">Limpiar filtros</button></div>
          </form>
          <details data-notif-import-wrap class="text-sm rounded-xl bg-surface-container-low p-3">
            <summary class="cursor-pointer font-medium">Seleccionar desde Excel SIMI</summary>
            <p class="mt-2 text-xs text-secondary">Cruza contratos o inmuebles con los contactos de esta categoría. Solo se incluyen los que tienes disponibles.</p>
            <form data-notif-import class="flex flex-wrap gap-2 mt-3"><input name="file" type="file" accept=".xlsx,.csv" aria-label="Archivo Excel o CSV SIMI" required class="min-w-0 w-full text-xs"><button type="submit" class="<?php echo $secondary; ?>">Importar y seleccionar</button></form>
            <a href="<?php echo esc_url(SCM_BASE_URL . '/assets/examples/notificaciones-importacion-simi.xlsx'); ?>" download class="inline-block mt-2 text-xs text-secondary underline">Descargar ejemplo XLSX</a>
          </details>
        </div>
        <div class="px-5 py-3 flex flex-wrap items-center gap-3 border-b border-slate-100 text-xs"><label class="inline-flex items-center gap-2 min-h-[44px]"><input type="checkbox" data-notif-select-page class="accent-[#735c00] w-4 h-4">Seleccionar página</label><button type="button" data-notif-select-all class="underline text-secondary min-h-[44px]">Seleccionar todos los resultados</button><button type="button" data-notif-clear class="underline text-secondary min-h-[44px]">Quitar selección</button></div>
        <div data-notif-recipients aria-live="polite" aria-busy="true" class="divide-y divide-slate-100"><p class="p-8 text-center text-sm text-secondary">Cargando destinatarios…</p></div>
        <div class="flex items-center justify-between gap-2 p-4 border-t border-slate-100"><button type="button" data-notif-prev class="<?php echo $secondary; ?>" disabled>Anterior</button><span data-notif-pagination class="text-xs text-secondary">Página 1</span><button type="button" data-notif-next class="<?php echo $secondary; ?>" disabled>Siguiente</button></div>
      </div>
      <div class="xl:col-span-5 space-y-4">
        <form data-notif-compose class="p-5 rounded-2xl bg-white border border-slate-200 shadow-card space-y-4">
          <h3 class="font-semibold text-title-md">2. Prepara el mensaje</h3>
          <fieldset><legend class="text-xs font-medium mb-2">Canales de envío</legend><div class="flex flex-wrap gap-3 text-sm"><?php foreach (['whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS'] as $channel => $label): ?><label class="inline-flex gap-2 items-center min-h-[44px]"><input name="channels[]" value="<?php echo $channel; ?>" type="checkbox" <?php echo $channel === 'whatsapp' ? 'checked' : ''; ?> class="w-4 h-4 accent-[#735c00]"><?php echo $label; ?></label><?php endforeach; ?></div></fieldset>
          <div data-notif-whatsapp-fields><label for="notif-template" class="block text-xs font-medium mb-1.5">Plantilla de WhatsApp</label><select name="whatsapp_template" id="notif-template" class="<?php echo $field; ?>"><?php foreach ($templates as $template): ?><option value="<?php echo esc_attr($template['name']); ?>"><?php echo esc_html($template['label']); ?></option><?php endforeach; ?></select><p class="text-xs text-secondary mt-2">Utiliza una plantilla aprobada en Meta y contactos que autorizaron recibir tus mensajes.</p></div>
          <div data-notif-email-fields hidden><label for="notif-subject" class="block text-xs font-medium mb-1.5">Asunto del correo</label><input id="notif-subject" name="subject" maxlength="150" value="Información de SKC SuCasa Inmobiliaria" class="<?php echo $field; ?>"></div>
          <div data-notif-media-fields hidden><label for="notif-media" class="block text-xs font-medium mb-1.5">Archivo del encabezado</label><input type="file" name="media" id="notif-media" class="w-full text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-surface-container-low file:p-2 file:text-on-surface"><p data-notif-media-help class="text-xs text-secondary mt-2"></p></div>
          <div><label for="notif-message" class="block text-xs font-medium mb-1.5">Mensaje</label><textarea id="notif-message" name="message" rows="5" maxlength="700" required class="<?php echo $field; ?> resize-y" placeholder="Escribe la información que deseas compartir…"></textarea><div class="flex justify-between text-xs text-secondary mt-1"><span>El saludo y tu firma se agregan automáticamente.</span><span data-notif-length>0/700</span></div><p data-notif-sms-length class="text-xs text-secondary mt-1" hidden></p></div>
          <div class="rounded-xl bg-surface-container-low p-3 text-xs text-secondary"><span class="font-semibold text-on-surface block mb-1">Tu firma</span><?php echo esc_html($sender['name']); ?><br><?php echo esc_html($sender['cargo']); ?><br><?php echo esc_html($sender['phone'] !== '' ? 'Cel. ' . $sender['phone'] : 'Falta registrar tu celular para enviar.'); ?></div>
          <button type="submit" data-notif-send class="w-full flex items-center justify-center gap-2 min-h-[44px] rounded-xl bg-primary-container hover:bg-primary-fixed-dim px-4 py-3 font-semibold text-on-surface focus-visible:ring-2 focus-visible:ring-inverse-surface disabled:opacity-50 disabled:cursor-not-allowed" <?php echo !$policy->canAct('enviar_notificacion') || $sender['phone'] === '' ? 'disabled' : ''; ?>><span class="material-symbols-outlined text-[20px]" aria-hidden="true">send</span>Revisar y enviar</button>
          <?php if (!$policy->canAct('enviar_notificacion')): ?><p class="text-xs text-secondary">Tu cargo puede consultar, pero no tiene permiso para enviar notificaciones.</p><?php endif; ?>
        </form>
        <div class="rounded-2xl border border-slate-200 bg-surface-container-low p-4"><h3 class="text-xs font-semibold text-secondary mb-3 uppercase tracking-wide">Vista previa del mensaje</h3><div class="rounded-xl bg-white p-4 shadow-sm"><div data-notif-media-preview class="mb-3" hidden></div><p data-notif-preview class="whitespace-pre-wrap break-words text-sm leading-relaxed"></p></div><p class="text-xs text-secondary mt-2">Ejemplo con el nombre de un destinatario. Cada mensaje llevará el saludo personalizado.</p></div>
      </div>
    </div>
  </div>
  <div id="notif-queue-panel" role="tabpanel" aria-labelledby="notif-queue-tab" data-notif-panel="queue" hidden class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3" data-notif-queue-stats></div>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-card overflow-hidden">
      <div class="p-5 flex flex-wrap justify-between gap-3"><div><h3 class="font-semibold text-title-md">Estado de los envíos</h3><p class="text-xs text-secondary mt-1"><?php echo $policy->canManage() ? 'Todos los envíos del módulo comercial.' : 'Historial de tus notificaciones.'; ?></p></div><div class="flex gap-2"><select aria-label="Filtrar por estado" data-notif-queue-status class="<?php echo $field; ?>"><option value="">Todos los estados</option><option value="pending">Pendientes</option><option value="processing">Procesando</option><option value="sent">Enviados</option><option value="failed">Fallidos</option><option value="cancelled">Cancelados</option></select><button type="button" data-notif-queue-refresh class="<?php echo $secondary; ?>" aria-label="Actualizar cola"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">refresh</span></button></div></div>
      <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-surface-container-low text-left text-xs text-secondary"><tr><th class="px-5 py-3">Destinatario</th><th class="px-5 py-3">Canal</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3">Fecha</th><th class="px-5 py-3">Detalle</th></tr></thead><tbody data-notif-queue-rows class="divide-y divide-slate-100"></tbody></table></div>
      <div class="p-4 flex items-center justify-between gap-2"><button type="button" data-notif-queue-prev class="<?php echo $secondary; ?>" disabled>Anterior</button><span data-notif-queue-page class="text-xs text-secondary"></span><button type="button" data-notif-queue-next class="<?php echo $secondary; ?>" disabled>Siguiente</button></div>
    </div>
  </div>
  <details class="bg-white border border-slate-200 rounded-2xl p-5 text-sm"><summary class="font-semibold cursor-pointer">Cómo crear las plantillas genéricas en Meta</summary><div class="mt-4 space-y-3 text-secondary"><p>En WhatsApp Manager → Plantillas de mensajes → Crear plantilla, registra estas cuatro plantillas en Español (COL), código <strong>es_CO</strong>. Para mensajes genéricos de contenido variable, utiliza la categoría <strong>Marketing</strong>.</p><ul class="list-disc pl-5"><?php foreach ($templates as $template): ?><li><code class="text-xs break-all"><?php echo esc_html($template['name']); ?></code>: encabezado <?php echo esc_html($template['header_type'] ?: 'sin encabezado'); ?>.</li><?php endforeach; ?></ul><p>Copia el cuerpo exactamente igual en las cuatro. Usa parámetros numéricos: {{1}} nombre del destinatario; {{2}} mensaje; {{3}} nombre, cargo y celular del funcionario. Para imagen, PDF o video, agrega un archivo de muestra del mismo tipo.</p><pre class="whitespace-pre-wrap break-words rounded-xl bg-surface-container-low p-4 text-xs text-on-surface" data-notif-template-body><?php echo esc_html(CommercialNotificationsService::TEMPLATE_BODY); ?></pre><button type="button" data-notif-copy-template class="<?php echo $secondary; ?>">Copiar cuerpo</button><p>Ejemplos: {{1}} María; {{2}} Tu asesoría sobre el inmueble está agendada para mañana a las 10:00 a. m.; {{3}} Ana Pérez - Consultora de Arriendo - Cel. 3001234567.</p><p>Espera la aprobación antes de enviar. Meta puede solicitar ajustar el contenido o la categoría.</p><a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener noreferrer" class="underline">Abrir plantillas en WhatsApp Manager</a></div></details>
</section>
<?php
    return (string) ob_get_clean();
  }
}
