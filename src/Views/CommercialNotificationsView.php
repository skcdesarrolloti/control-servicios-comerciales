<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialNotificationsService;
use SCM\Commercial\CommercialSmsMessage;

final class CommercialNotificationsView
{
  public static function render(CommercialNotificationsService $service, CommercialAccessPolicy $policy): string
  {
    $types = $service->types();
    $templates = $service->whatsappTemplates();
    $sender = $service->senderProfile();
    $config = ['templates' => $templates, 'sender' => $sender, 'request_id' => bin2hex(random_bytes(16)), 'max_bytes' => (int) SCM_UPLOAD_MAX_BYTES,
      'can_send' => $policy->canAct('enviar_notificacion') && $sender['phone'] !== '',
      'can_delete' => $policy->canAct('eliminar_notificacion'),
      'can_edit_actor' => $policy->canAct('editar_actor'),
      'email_document' => $service->emailDocument('__SCM_NAME__', '__SCM_SUBJECT__', '__SCM_MESSAGE__'),
      'sms' => ['prefix' => CommercialSmsMessage::PREFIX, 'max' => CommercialSmsMessage::MAX_CHARACTERS, 'basic' => CommercialSmsMessage::GSM_BASIC, 'extended' => CommercialSmsMessage::GSM_EXTENDED]];
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
    <button type="button" role="tab" aria-selected="false" aria-controls="notif-report-panel" id="notif-report-tab" data-notif-view="report" class="<?php echo $secondary; ?>"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">assessment</span>Informe por funcionario</button>
  </div>
  <p data-notif-feedback role="status" aria-live="polite" class="text-sm font-medium text-secondary" hidden></p>
  <div id="notif-recipients-panel" role="tabpanel" aria-labelledby="notif-recipients-tab" data-notif-panel="recipients" class="space-y-5">
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
      <?php foreach ($types as $key => $type): ?>
      <button type="button" data-notif-type="<?php echo esc_attr($key); ?>" aria-pressed="false" class="p-4 rounded-2xl bg-white border border-slate-200 text-left hover:shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container transition-shadow">
        <span class="flex min-h-[40px] items-start justify-between gap-2 text-sm font-medium"><span><?php echo esc_html($type['label']); ?></span><span class="material-symbols-outlined shrink-0 text-secondary text-[20px]" aria-hidden="true"><?php echo ['Propietario' => 'home_work', 'Arrendatario' => 'key', 'Copropiedad' => 'apartment', 'Club PPH' => 'loyalty'][$type['role']]; ?></span></span>
        <strong class="block text-2xl mt-2" data-notif-total="<?php echo esc_attr($key); ?>">—</strong><span class="block text-xs text-secondary mt-1" data-notif-contact="<?php echo esc_attr($key); ?>">Abrir para consultar</span>
      </button>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-secondary">Activos: con contrato Entregado. No activos: con contrato Recibido y sin contratos Entregados.</p>
    <div class="rounded-2xl bg-white border border-slate-200 shadow-card overflow-hidden">
        <div class="p-5 border-b border-slate-100 space-y-4">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold text-title-md" data-notif-actor-title>Selecciona una categoría</h3>
            <div class="flex flex-wrap gap-2 items-center">
              <span class="shrink-0 text-xs bg-primary-container/30 text-on-surface px-3 py-1.5 rounded-full" data-notif-selected>0 seleccionados</span>
              <div class="flex flex-wrap gap-2" aria-label="Enviar a los destinatarios seleccionados">
                <?php foreach (['whatsapp' => 'WhatsApp', 'email' => 'Correo', 'sms' => 'SMS', 'all' => 'Todos los canales'] as $channel => $label): ?>
                <button type="button" data-notif-open-channel="<?php echo $channel; ?>" disabled class="<?php echo $secondary; ?> <?php echo $channel === 'all' ? 'bg-primary-container' : 'bg-white'; ?>"><span class="material-symbols-outlined text-[18px]" aria-hidden="true"><?php echo ['whatsapp' => 'chat', 'email' => 'mail', 'sms' => 'sms', 'all' => 'send'][$channel]; ?></span><?php echo $label; ?></button>
                <?php endforeach; ?>
              </div>
              <button type="button" data-notif-recipient-refresh disabled class="<?php echo $secondary; ?>" aria-label="Actualizar destinatarios"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">refresh</span></button>
            </div>
          </div>
          <form data-notif-search class="space-y-3">
            <fieldset disabled data-notif-search-controls class="space-y-3">
            <div><label for="notif-search" class="block text-xs font-medium mb-1.5">Nombre, documento, correo o celular</label><input id="notif-search" name="q" type="search" maxlength="150" class="<?php echo $field; ?>" placeholder="Buscar contacto…"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-notif-contract-filters>
              <div data-notif-contract-status-wrap hidden><label for="notif-contract-status" class="block text-xs font-medium mb-1.5">Estado del contrato</label><select id="notif-contract-status" name="contract_status" class="<?php echo $field; ?>"><option value="">Todos</option><option value="activos">Activo</option><option value="no_activos">No activo</option></select></div>
              <div><label for="notif-property" class="block text-xs font-medium mb-1.5">Inmueble SIMI</label><input id="notif-property" name="inmueble_simi" maxlength="50" class="<?php echo $field; ?>" placeholder="Código del inmueble"></div>
              <div><label for="notif-contract" class="block text-xs font-medium mb-1.5">Contrato</label><input id="notif-contract" name="contract_number" maxlength="50" class="<?php echo $field; ?>" placeholder="Número de contrato"></div>
            </div>
            <div class="flex flex-wrap gap-2"><button type="submit" class="<?php echo $secondary; ?> bg-inverse-surface text-white hover:bg-secondary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">search</span>Buscar</button><button type="reset" class="<?php echo $secondary; ?>">Limpiar filtros</button></div>
            </fieldset>
          </form>
        </div>
        <div class="px-5 py-3 flex flex-wrap items-center gap-3 border-b border-slate-100 text-xs"><label class="inline-flex items-center gap-2 min-h-[44px]"><input type="checkbox" data-notif-select-page class="accent-[#735c00] w-4 h-4">Seleccionar página</label><button type="button" data-notif-select-all class="underline text-secondary min-h-[44px]">Seleccionar todos los resultados</button><button type="button" data-notif-clear class="underline text-secondary min-h-[44px]">Quitar selección</button></div>
        <div data-notif-recipients aria-live="polite" aria-busy="false" class="divide-y divide-slate-100"><p class="p-8 text-center text-sm text-secondary">Abre una categoría para consultar sus destinatarios.</p></div>
        <div class="flex items-center justify-between gap-2 p-4 border-t border-slate-100"><button type="button" data-notif-prev class="<?php echo $secondary; ?>" disabled>Anterior</button><span data-notif-pagination class="text-xs text-secondary">Página 1</span><button type="button" data-notif-next class="<?php echo $secondary; ?>" disabled>Siguiente</button></div>
    </div>
  </div>
  <dialog data-notif-modal aria-labelledby="notif-modal-title" class="m-auto w-[calc(100%-2rem)] max-w-6xl max-h-[90dvh] p-0 rounded-2xl border-0 bg-background text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <div class="sticky top-0 z-10 bg-white border-b border-slate-200 px-5 py-4 flex justify-between items-start gap-4"><div><h3 id="notif-modal-title" class="text-title-lg font-semibold">Enviar notificación</h3><p data-notif-modal-target class="text-sm text-secondary mt-1"></p></div><button type="button" data-notif-close class="<?php echo $secondary; ?>" aria-label="Cerrar editor"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <p data-notif-modal-feedback role="status" class="px-5 pt-3 text-sm text-error" hidden></p>
    <div class="p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
      <div>
        <form data-notif-compose class="p-5 rounded-2xl bg-white border border-slate-200 shadow-card space-y-4">
          <h3 class="font-semibold text-title-md">Prepara el mensaje</h3>
          <fieldset><legend class="text-xs font-medium mb-2">Canales de envío</legend><div class="flex flex-wrap gap-3 text-sm"><?php foreach (['whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS'] as $channel => $label): ?><label class="inline-flex gap-2 items-center min-h-[44px]"><input name="channels[]" value="<?php echo $channel; ?>" type="checkbox" <?php echo $channel === 'whatsapp' ? 'checked' : ''; ?> class="w-4 h-4 accent-[#735c00]"><?php echo $label; ?></label><?php endforeach; ?></div><p data-notif-channel-help class="mt-1 text-xs text-secondary"></p></fieldset>
          <div data-notif-whatsapp-fields><label for="notif-template" class="block text-xs font-medium mb-1.5">Plantilla de WhatsApp</label><select name="whatsapp_template" id="notif-template" class="<?php echo $field; ?>"><?php foreach ($templates as $template): ?><option value="<?php echo esc_attr($template['name']); ?>"><?php echo esc_html($template['label']); ?></option><?php endforeach; ?></select><p class="text-xs text-secondary mt-2">Utiliza una plantilla aprobada en Meta y contactos que autorizaron recibir tus mensajes.</p></div>
          <div data-notif-email-fields hidden><label for="notif-subject" class="block text-xs font-medium mb-1.5">Asunto del correo</label><input id="notif-subject" name="subject" maxlength="150" value="Información de SKC SuCasa Inmobiliaria" class="<?php echo $field; ?>"></div>
          <div data-notif-media-fields hidden><label for="notif-media" class="block text-xs font-medium mb-1.5">Archivo del encabezado</label><input type="file" name="media" id="notif-media" class="w-full text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-surface-container-low file:p-2 file:text-on-surface"><p data-notif-media-help class="text-xs text-secondary mt-2"></p></div>
          <div><label for="notif-message" class="block text-xs font-medium mb-1.5">Mensaje</label><textarea id="notif-message" name="message" rows="5" maxlength="700" required class="<?php echo $field; ?> resize-y" placeholder="Escribe la información que deseas compartir…"></textarea><div class="flex justify-between text-xs text-secondary mt-1"><span data-notif-message-help>El saludo y tu firma se agregan automáticamente.</span><span data-notif-length>0/700</span></div><p data-notif-sms-length class="text-xs text-secondary mt-1" hidden></p></div>
          <div class="rounded-xl bg-surface-container-low p-3 text-xs text-secondary"><span class="font-semibold text-on-surface block mb-1">Tu firma</span><?php echo esc_html($sender['name']); ?><br><?php echo esc_html($sender['cargo']); ?><br><?php echo esc_html($sender['phone'] !== '' ? 'Cel. ' . $sender['phone'] : 'Falta registrar tu celular para enviar.'); ?></div>
          <button type="submit" data-notif-send class="w-full flex items-center justify-center gap-2 min-h-[44px] rounded-xl bg-primary-container hover:bg-primary-fixed-dim px-4 py-3 font-semibold text-on-surface focus-visible:ring-2 focus-visible:ring-inverse-surface disabled:opacity-50 disabled:cursor-not-allowed" <?php echo !$policy->canAct('enviar_notificacion') || $sender['phone'] === '' ? 'disabled' : ''; ?>><span class="material-symbols-outlined text-[20px]" aria-hidden="true">send</span>Revisar y enviar</button>
          <?php if (!$policy->canAct('enviar_notificacion')): ?><p class="text-xs text-secondary">Tu cargo puede consultar, pero no tiene permiso para enviar notificaciones.</p><?php endif; ?>
        </form>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-surface-container-low p-4 space-y-3"><h3 class="text-xs font-semibold text-secondary uppercase tracking-wide">Vista previa del mensaje</h3><div class="flex flex-wrap gap-2" aria-label="Canal de vista previa"><?php foreach (['whatsapp' => 'WhatsApp', 'email' => 'Correo', 'sms' => 'SMS'] as $channel => $label): ?><button type="button" data-notif-preview-channel="<?php echo $channel; ?>" class="<?php echo $secondary; ?> bg-white"><?php echo $label; ?></button><?php endforeach; ?></div><div data-notif-text-preview class="rounded-xl bg-white p-4 shadow-sm"><div data-notif-media-preview class="mb-3" hidden></div><p data-notif-preview class="whitespace-pre-wrap break-words text-sm leading-relaxed"></p></div><iframe data-notif-email-preview title="Vista previa del correo con banner" sandbox="" referrerpolicy="no-referrer" class="w-full h-[560px] rounded-xl border border-slate-200 bg-white" hidden></iframe><p class="text-xs text-secondary">Cada destinatario recibe su mensaje personalizado. SMS utiliza el prefijo de la empresa y el texto escrito, dentro de 160 caracteres.</p></div>
    </div>
  </dialog>
  <dialog data-notif-confirm-modal aria-labelledby="notif-confirm-title" aria-describedby="notif-confirm-description" class="m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90dvh] p-6 sm:p-7 rounded-2xl border-0 bg-white text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <div class="w-12 h-12 rounded-2xl bg-primary-container flex items-center justify-center mb-4"><span class="material-symbols-outlined" aria-hidden="true">schedule_send</span></div>
    <h3 id="notif-confirm-title" class="text-headline-md font-semibold">Confirma el envío</h3>
    <p id="notif-confirm-description" class="mt-2 text-sm text-secondary">Revisa los destinatarios y los canales antes de continuar.</p>
    <dl class="mt-5 rounded-xl bg-surface-container-low p-4 space-y-3 text-sm"><div><dt class="text-xs text-secondary">Destinatarios</dt><dd data-notif-confirm-target class="font-semibold mt-1 break-words"></dd></div><div><dt class="text-xs text-secondary">Canales</dt><dd data-notif-confirm-channels class="font-semibold mt-1"></dd></div></dl>
    <p class="mt-4 text-xs text-secondary">Se personalizarán el saludo y la firma de WhatsApp y correo. Los contactos sin datos válidos o bloqueados por preferencias se omiten por canal.</p>
    <p data-notif-confirm-progress role="status" aria-live="polite" class="mt-4 flex items-center gap-2 text-sm font-medium" hidden><span class="material-symbols-outlined motion-safe:animate-spin" aria-hidden="true">progress_activity</span>Encolando mensajes…</p>
    <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2"><button type="button" data-notif-confirm-cancel autofocus class="<?php echo $secondary; ?>">Volver al mensaje</button><button type="button" data-notif-confirm-send class="<?php echo $secondary; ?> bg-primary-container hover:bg-primary-fixed-dim"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">send</span>Confirmar y encolar</button></div>
  </dialog>
  <dialog data-notif-result-modal aria-labelledby="notif-result-title" aria-describedby="notif-result-description" class="m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90dvh] p-6 sm:p-7 rounded-2xl border-0 bg-white text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <div class="w-12 h-12 rounded-2xl bg-surface-container-low flex items-center justify-center mb-4"><span data-notif-result-icon class="material-symbols-outlined text-secondary" aria-hidden="true">task_alt</span></div>
    <h3 id="notif-result-title" data-notif-result-title class="text-headline-md font-semibold"></h3>
    <p id="notif-result-description" data-notif-result-description class="mt-2 text-sm text-secondary break-words"></p>
    <dl data-notif-result-counts class="mt-5 grid grid-cols-2 gap-3">
      <?php foreach (['queued' => 'Encolados', 'invalid' => 'Sin datos válidos', 'filtered' => 'Por preferencias', 'failed' => 'Con error'] as $metric => $label): ?>
      <div class="rounded-xl bg-surface-container-low p-3"><dt class="text-xs text-secondary"><?php echo $label; ?></dt><dd data-notif-result-count="<?php echo $metric; ?>" class="mt-1 text-2xl font-semibold">0</dd></div>
      <?php endforeach; ?>
    </dl>
    <p data-notif-result-help class="mt-4 text-xs text-secondary">Las cantidades corresponden a mensajes por canal. Encolado significa pendiente de envío; consulta su estado en la cola.</p>
    <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2"><button type="button" data-notif-result-close autofocus class="<?php echo $secondary; ?>">Cerrar</button><button type="button" data-notif-result-queue class="<?php echo $secondary; ?> bg-primary-container hover:bg-primary-fixed-dim"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">schedule_send</span>Ver cola</button></div>
  </dialog>
  <div id="notif-queue-panel" role="tabpanel" aria-labelledby="notif-queue-tab" data-notif-panel="queue" hidden class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3" data-notif-queue-stats></div>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-card overflow-hidden">
      <div class="p-5 flex flex-wrap justify-between gap-3"><div><h3 class="font-semibold text-title-md">Estado de los envíos</h3><p class="text-xs text-secondary mt-1"><?php echo $policy->canManage() ? 'Todos los envíos del módulo comercial.' : 'Historial de tus notificaciones.'; ?></p></div><div class="flex gap-2"><select aria-label="Filtrar por estado" data-notif-queue-status class="<?php echo $field; ?>"><option value="">Todos los estados</option><option value="pending">Pendientes</option><option value="processing">Procesando</option><option value="sent">Enviados</option><option value="failed">Fallidos</option><option value="cancelled">Cancelados</option></select><button type="button" data-notif-queue-refresh class="<?php echo $secondary; ?>" aria-label="Actualizar cola"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">refresh</span></button></div></div>
      <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-surface-container-low text-left text-xs text-secondary"><tr><th class="px-5 py-3">Destinatario</th><th class="px-5 py-3">Creada por</th><th class="px-5 py-3">Canal</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3">Fecha de creación</th><th class="px-5 py-3">Detalle</th><th class="px-5 py-3">Acciones</th></tr></thead><tbody data-notif-queue-rows class="divide-y divide-slate-100"></tbody></table></div>
      <div class="p-4 flex items-center justify-between gap-2"><button type="button" data-notif-queue-prev class="<?php echo $secondary; ?>" disabled>Anterior</button><span data-notif-queue-page class="text-xs text-secondary"></span><button type="button" data-notif-queue-next class="<?php echo $secondary; ?>" disabled>Siguiente</button></div>
    </div>
  </div>
  <dialog data-notif-history-modal aria-labelledby="notif-history-title" class="m-auto w-[calc(100%-2rem)] max-w-3xl max-h-[90dvh] overflow-y-auto p-5 sm:p-7 rounded-2xl border-0 bg-white text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <div class="flex items-center justify-between gap-3"><h3 id="notif-history-title" class="text-headline-md font-semibold">Vista previa del mensaje</h3><button type="button" data-notif-history-close class="<?php echo $secondary; ?>" aria-label="Cerrar vista previa"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <p data-notif-history-loading role="status" class="mt-4 text-sm text-secondary">Cargando mensaje…</p>
    <div data-notif-history-content class="mt-4 space-y-4" hidden>
      <dl data-notif-history-info class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm rounded-xl bg-surface-container-low p-4"></dl>
      <p data-notif-history-text class="whitespace-pre-wrap break-words rounded-xl border border-slate-200 p-4 text-sm leading-relaxed"></p>
      <iframe data-notif-history-email title="Correo guardado en la cola" sandbox="" referrerpolicy="no-referrer" class="w-full h-[560px] rounded-xl border border-slate-200 bg-white" hidden></iframe>
      <div data-notif-history-media class="space-y-2" hidden></div>
      <p class="text-xs text-secondary">Contenido guardado al crear el mensaje. Enviado indica el resultado registrado por el proveedor; no confirma lectura.</p>
    </div>
  </dialog>
  <dialog data-notif-delete-modal aria-labelledby="notif-delete-title" aria-describedby="notif-delete-description" class="m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90dvh] overflow-y-auto p-6 rounded-2xl border-0 bg-white text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <h3 id="notif-delete-title" class="text-headline-md font-semibold">Eliminar notificación</h3>
    <p data-notif-delete-target class="mt-3 text-sm font-semibold break-words"></p>
    <p id="notif-delete-description" class="mt-2 text-sm text-secondary">Se retirará de la cola. Si está pendiente, se cancelará su envío. Los mensajes enviados no se pueden retirar del destinatario. Se conservarán el contenido, el autor y el registro de eliminación para auditoría.</p>
    <p data-notif-delete-feedback role="status" class="mt-3 text-sm text-error" hidden></p>
    <div class="mt-5 flex flex-col-reverse sm:flex-row sm:justify-end gap-2"><button type="button" data-notif-delete-cancel autofocus class="<?php echo $secondary; ?>">Cancelar</button><button type="button" data-notif-delete-confirm class="<?php echo $secondary; ?> text-error">Eliminar notificación</button></div>
  </dialog>
  <div id="notif-report-panel" role="tabpanel" aria-labelledby="notif-report-tab" data-notif-panel="report" hidden class="space-y-4">
    <div class="bg-white border border-slate-200 rounded-2xl shadow-card overflow-hidden">
      <div class="p-5 space-y-4">
        <div><h3 class="font-semibold text-title-md">Informe de notificaciones por funcionario</h3><p class="text-xs text-secondary mt-1"><?php echo $policy->canManage() ? 'Todos los autores del módulo comercial.' : 'Informe de tus notificaciones.'; ?> Una fila por funcionario, con cantidades de WhatsApp, correo y SMS. Incluye eliminados para conservar la trazabilidad.</p></div>
        <form data-notif-report-form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
          <label class="text-xs text-secondary">Desde<input type="date" name="date_from" class="<?php echo $field; ?> mt-1"></label>
          <label class="text-xs text-secondary">Hasta<input type="date" name="date_to" class="<?php echo $field; ?> mt-1"></label>
          <label class="text-xs text-secondary">ID del funcionario<input type="text" name="employee_id" maxlength="50" placeholder="Todos los permitidos" class="<?php echo $field; ?> mt-1"></label>
          <button type="submit" class="<?php echo $secondary; ?> bg-primary-container">Consultar informe</button>
          <button type="button" data-notif-report-export class="<?php echo $secondary; ?>" disabled><span class="material-symbols-outlined text-[18px]" aria-hidden="true">download</span>Descargar CSV</button>
        </form>
        <p data-notif-report-summary role="status" class="text-sm text-secondary"></p>
        <p class="text-xs text-secondary">Fechas de creación en hora de Colombia. «Eliminados» es parte del total y puede coincidir con cualquier estado. El autor es quien creó y solicitó el envío; la entrega la realiza el servicio automático.</p>
      </div>
      <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-surface-container-low text-left text-xs text-secondary"><tr><?php foreach (['Funcionario', 'Cargo', 'WhatsApp', 'Correo', 'SMS', 'Total', 'Pendientes', 'Procesando', 'Enviados', 'Fallidos', 'Cancelados', 'Eliminados', 'Última creación'] as $heading): ?><th class="px-4 py-3 whitespace-nowrap"><?php echo $heading; ?></th><?php endforeach; ?></tr></thead><tbody data-notif-report-rows class="divide-y divide-slate-100"></tbody></table></div>
    </div>
  </div>
  <?php if ($policy->canAct('editar_actor')): ?>
  <dialog data-notif-actor-modal aria-labelledby="notif-actor-title" class="m-auto w-[calc(100%-2rem)] max-w-5xl max-h-[90dvh] overflow-y-auto p-5 sm:p-7 rounded-2xl border-0 bg-white text-on-surface shadow-modal backdrop:bg-slate-900/50">
    <div class="flex items-center justify-between gap-3"><h3 id="notif-actor-title" class="text-headline-md font-semibold">Editar datos del actor</h3><button type="button" data-notif-actor-close class="<?php echo $secondary; ?>" aria-label="Cerrar editor"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <p data-notif-actor-feedback role="status" class="mt-3 text-sm text-secondary"></p>
    <p data-notif-actor-step class="mt-2 text-xs font-semibold text-secondary">Paso 1 de 2 · Editar y seleccionar registros</p>
    <form data-notif-actor-form class="mt-4 space-y-4" hidden>
      <p data-notif-actor-identity class="text-sm font-semibold"></p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><?php foreach (\SCM\Commercial\CommercialActorEditor::FIELDS as $key => $label): ?><label class="text-xs text-secondary"><?php echo $key === 'indicativo' ? 'País / indicativo' : $label; ?><?php if ($key === 'indicativo'): ?><select name="indicativo" data-notif-actor-country class="<?php echo $field; ?> mt-1"><option value="">Sin indicativo</option></select><?php else: ?><input name="<?php echo $key; ?>" type="<?php echo $key === 'correo' ? 'email' : 'text'; ?>" maxlength="<?php echo in_array($key, ['nombre', 'correo'], true) ? '254' : '40'; ?>" <?php echo $key === 'nombre' ? 'required' : ''; ?> class="<?php echo $field; ?> mt-1"><?php endif; ?></label><?php endforeach; ?></div>
      <fieldset class="rounded-xl bg-surface-container-low p-4 space-y-3"><legend class="text-sm font-semibold">Registros relacionados donde aplicar los cambios</legend><p class="text-xs text-secondary">Estos son los registros vinculados a este actor. Marca los que deseas actualizar; después verás los valores actuales y cómo quedarán. Solo se guardarán los registros seleccionados que tengan cambios.</p><div data-notif-actor-groups class="flex flex-wrap gap-4"></div><p data-notif-actor-related-summary role="status" class="text-xs font-semibold"></p><div data-notif-actor-related class="space-y-3"></div><p class="text-xs text-secondary">Incluye registros históricos y cerrados. Los documentos PDF ya emitidos conservan su contenido.</p></fieldset>
    </form>
    <div data-notif-actor-review-panel class="mt-4 space-y-4" hidden>
      <h4 data-notif-actor-comparison-title tabindex="-1" class="text-sm font-semibold">Comparación antes de guardar</h4><p class="text-xs text-secondary">Revisa el valor actual y cómo quedará cada campo de los registros seleccionados. Se resaltan los campos que cambiarán. Nada se guarda hasta confirmar.</p>
      <div data-notif-actor-changes class="space-y-3"></div>
    </div>
    <div data-notif-actor-actions class="sticky bottom-0 bg-white border-t border-slate-200 mt-4 pt-4 pb-2 space-y-3">
      <p data-notif-actor-save-hint role="status" class="text-xs text-secondary">Primero abre la comparación para habilitar el guardado.</p>
      <label data-notif-actor-confirm-wrap class="flex items-start gap-2 text-sm" hidden><input type="checkbox" data-notif-actor-confirm class="mt-1 w-4 h-4 shrink-0 accent-[#735c00]"><span>He revisado los valores actuales y finales y confirmo actualizar los registros seleccionados.</span></label>
      <div class="flex flex-col sm:flex-row sm:justify-end gap-2"><button type="button" data-notif-actor-back class="<?php echo $secondary; ?>" hidden>Volver a editar</button><button type="button" data-notif-actor-review class="<?php echo $secondary; ?> bg-primary-container">Ver cómo quedarán los datos</button><button type="button" data-notif-actor-save disabled class="<?php echo $secondary; ?> bg-primary-container">Guardar cambios</button></div>
    </div>
  </dialog>
  <?php endif; ?>
</section>
<?php
    return (string) ob_get_clean();
  }
}
