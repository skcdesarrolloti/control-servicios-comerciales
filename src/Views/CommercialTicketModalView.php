<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialStatusCatalog;

final class CommercialTicketModalView
{
  /**
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>,analyses?:array<int,array<string,mixed>>} $detail
   * @param array<int,array<string,string>> $employees
   */
  public static function render(array $detail, CommercialAccessPolicy $policy, array $employees, string $ticketUrl = ''): string
  {
    $ticket = $detail['ticket'];
    $timeline = $detail['timeline'];
    $analyses = is_array($detail['analyses'] ?? null) ? $detail['analyses'] : [];
    $pk = (int) ($ticket['_ID'] ?? 0);
    $logicalId = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $pk;
    $status = trim((string) ($ticket['estado_comercial'] ?? '')) ?: 'Sin estado';
    $generalStatus = trim((string) ($ticket['estado'] ?? ''));
    $isPostponedOrClosed = CommercialStatusCatalog::isPostponed($status, $generalStatus)
      || CommercialStatusCatalog::isClosed($status, $generalStatus)
      || in_array(mb_strtolower($status, 'UTF-8'), ['postergado', 'aplazado', 'cerrado', 'finalizado'], true);
    $subject = trim((string) ($ticket['asunto'] ?? '')) ?: 'Tarea comercial';
    $description = trim(wp_strip_all_tags((string) ($ticket['descripcion'] ?? ''), true));
    $requester = trim((string) ($ticket['solicitante'] ?? '')) ?: 'Sin solicitante';
    $assignee = trim((string) ($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '')) ?: 'Sin asignar';
    $assigneeId = trim((string) ($ticket['id_empleado'] ?? ''));
    $external = $ticketUrl !== '' ? $ticketUrl . rawurlencode($logicalId) : '';
    $email = trim((string) ($ticket['correo_solicitante'] ?? $ticket['correo'] ?? ''));
    $phone = trim((string) ($ticket['celular_solicitante'] ?? $ticket['celular'] ?? ''));
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $theme = trim((string) ($ticket['tema'] ?? $ticket['tema_ayuda'] ?? ''));
    $priority = trim((string) ($ticket['prioridad'] ?? ''));
    $channel = trim((string) ($ticket['medio'] ?? ''));
    $createdTs = (int) ($ticket['fecha'] ?? 0);
    if ($createdTs <= 0 && !empty($ticket['cct_created'])) {
      $createdTs = strtotime((string) $ticket['cct_created']) ?: 0;
    }
    $updatedTs = (int) ($ticket['fecha_actualizacion'] ?? $ticket['fecha'] ?? 0);
    $elapsedDays = $createdTs > 0 ? max(0, (int) floor((time() - $createdTs) / 86400)) : 0;

    $property = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    $propertyCode = trim((string) ($ticket['id_inmueble'] ?? $ticket['inmueble'] ?? $property['codigo'] ?? ''));
    if ($propertyCode === '') {
      $propertyCode = trim((string) ($property['codigo'] ?? ''));
    }
    $propertyType = trim((string) ($ticket['tipo_inmueble'] ?? $property['tipo_inmueble'] ?? ''));
    $neighborhood = trim((string) ($ticket['barrio'] ?? $property['barrio'] ?? ''));
    $city = trim((string) ($property['ciudad'] ?? ''));
    $address = trim((string) ($ticket['direccion'] ?? $property['direccion'] ?? ''));
    $canon = trim((string) ($property['canon'] ?? $property['valor'] ?? ''));

    $requesterInitials = self::initials($requester);
    $assigneeInitials = self::initials($assignee);
    $timelineCounts = self::timelineCounts($timeline);

    ob_start();
?>
  <!-- BEGIN: ModalHeader -->
  <header class="bg-white border-b border-slate-100 px-6 sm:px-8 pt-6 pb-5 flex-shrink-0">
    <!-- Top Status Bar & Close Button -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <!-- Breadcrumb navigation & Primary Status Badges -->
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <nav class="flex items-center gap-1.5 text-slate-400 font-medium mr-2">
          <span>Tareas</span>
          <span>/</span>
          <span><?php echo esc_html($theme !== '' ? $theme : 'Comercial'); ?></span>
          <span>/</span>
          <span class="text-[#1E3C76] font-semibold">Tarea #<?php echo esc_html($logicalId); ?></span>
        </nav>
        <!-- Pill Badges -->
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-[#061D49] border border-slate-200">
          #<?php echo esc_html($logicalId); ?>
        </span>
        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-[#FEF3C7] text-[#92400E] border border-[#FDE68A]">
          <span class="w-2 h-2 rounded-full bg-[#F59E0B] animate-pulse"></span>
          <?php echo esc_html($status); ?>
        </span>
        <?php if ($priority !== ''): ?>
          <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
            <span class="material-symbols-outlined text-[14px] text-amber-500">flag</span>
            <span>Prioridad <?php echo esc_html($priority); ?></span>
          </span>
        <?php endif; ?>
        <?php if ($theme !== ''): ?>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
            <?php echo esc_html($theme); ?>
          </span>
        <?php endif; ?>
      </div>
      <!-- Top-right Action Buttons and Close Modal Button -->
      <div class="flex items-center gap-2">
        <?php if ($external !== ''): ?>
          <a class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-xs font-semibold text-slate-700 transition-all hover:text-[#061D49]" href="<?php echo esc_url($external); ?>" target="_blank" rel="noopener noreferrer">
            <span class="material-symbols-outlined text-[15px] text-slate-500">open_in_new</span>
            <span>Ver en portal</span>
          </a>
        <?php endif; ?>
        <button aria-label="Cerrar modal" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center transition-all ml-1 cursor-pointer" type="button" data-commercial-close-case>
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
      </div>
    </div>

    <!-- Case Title & Contextual Info -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-[#061D49] tracking-tight" id="commercial-case-title">
          <?php echo esc_html($subject); ?>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
          <?php echo esc_html($description !== '' ? $description : 'Solicitud comercial registrada en plataforma.'); ?>
          <?php if ($propertyCode !== ''): ?>
            · <span class="font-medium text-slate-700">Código Inmueble: #<?php echo esc_html($propertyCode); ?></span>
          <?php endif; ?>
        </p>
      </div>
      <!-- Quick Action Buttons -->
      <div class="flex flex-wrap items-center gap-2 self-start md:self-auto">
        <?php if ($propertyCode !== ''): ?>
          <a href="https://sucasainmobiliaria.com.co/inmueble/<?php echo esc_attr($propertyCode); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-[#EBF1FB] text-[#061D49] border border-slate-200 hover:border-[#1E3C76] text-xs font-medium transition-all shadow-sm cursor-pointer">
            <span class="material-symbols-outlined text-[16px] text-[#1E3C76]">apartment</span>
            <span>Ficha del inmueble</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Meta info pill row -->
    <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-y-2 gap-x-5 text-xs text-slate-500">
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[15px] text-slate-400">calendar_today</span>
        <span>Creación: <strong class="font-medium text-slate-700"><?php echo esc_html(self::formatDate($createdTs)); ?></strong></span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[15px] text-slate-400">update</span>
        <span>Última actividad: <strong class="font-medium text-slate-700"><?php echo esc_html(self::formatDate($updatedTs)); ?></strong></span>
      </div>
      <div class="flex items-center gap-1.5 text-indigo-700 font-medium bg-indigo-50 px-2.5 py-0.5 rounded-md border border-indigo-100">
        <span class="material-symbols-outlined text-[15px]">schedule</span>
        <span>Tiempo transcurrido: <?php echo esc_html((string) $elapsedDays); ?> días</span>
      </div>
      <?php if ($theme !== ''): ?>
        <div class="flex items-center gap-1.5 text-amber-700 font-medium bg-amber-50 px-2.5 py-0.5 rounded-md border border-amber-100">
          <span class="material-symbols-outlined text-[15px]">category</span>
          <span>Fase: <?php echo esc_html($theme); ?></span>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <!-- END: ModalHeader -->

  <!-- BEGIN: ModalBodyContent (Split View Layout: Left Panel 42% + Right Panel 58%) -->
  <main class="flex-1 overflow-y-auto bg-slate-50/70 p-5 sm:p-7 grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- BEGIN: LeftColumnManagement -->
    <section class="lg:col-span-5 flex flex-col gap-5">
      <!-- AI Insight Banner & Action Card -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-50 via-white to-amber-100/50 p-4 border border-amber-200/70 shadow-sm" data-purpose="ai-analysis-card">
        <div class="flex items-start justify-between gap-3 mb-2">
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-[#F8CF4A] text-[#061D49] flex items-center justify-center font-bold text-xs shadow-sm">
              ✨
            </span>
            <div>
              <h4 class="text-xs font-bold text-[#061D49] uppercase tracking-wider">Asistente Inteligente SuCasa</h4>
              <p class="text-[11px] text-slate-500"><?php echo !empty($analyses) ? 'Diagnóstico ejecutivo listo' : 'Listo para procesar tarea'; ?></p>
            </div>
          </div>
          <span class="text-[11px] font-semibold text-amber-700 bg-amber-100/80 px-2 py-0.5 rounded-full">IA Activa</span>
        </div>
        <p class="text-xs text-slate-600 leading-relaxed mb-3">
          <?php
            if (!empty($analyses[0]['resumen'])) {
              echo esc_html(mb_strimwidth((string) $analyses[0]['resumen'], 0, 180, '…', 'UTF-8'));
            } else {
              echo 'Analiza automáticamente el historial completo, cliente, inmueble, compromisos y genera un diagnóstico estratégico con recomendaciones.';
            }
          ?>
        </p>
        <button class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-[#F8CF4A] to-[#FFBE3E] hover:from-[#E5BD3B] hover:to-[#F8CF4A] text-[#061D49] font-semibold text-xs flex items-center justify-center gap-2 transition-all shadow-sm hover:shadow active:scale-[0.99] cursor-pointer" type="button" data-commercial-assistant data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
          <span class="material-symbols-outlined text-[16px]">auto_awesome</span>
          <span>Generar Resumen Ejecutivo con IA</span>
        </button>
      </div>

      <!-- Quick Action Buttons Grid -->
      <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card" data-purpose="case-action-grid">
        <div class="flex items-center justify-between mb-3.5 pb-2 border-b border-slate-100">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Gestión Operativa</span>
          <span class="text-[11px] text-[#1E3C76] font-medium"><?php echo count($timeline); ?> eventos registrados</span>
        </div>
        <div class="grid grid-cols-2 gap-2.5">
          <?php if ($policy->canAct('responder')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-50 hover:bg-[#EBF1FB] hover:text-[#1E3C76] text-slate-700 border border-slate-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="reply">
              <span class="material-symbols-outlined text-[16px] text-slate-500 group-hover:text-[#1E3C76]">reply</span>
              <span>Responder</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('agregar_nota')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="note">
              <span class="material-symbols-outlined text-[16px] text-slate-500 group-hover:text-[#061D49]">lock</span>
              <span>Nota interna</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('seguimiento')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="follow_up">
              <span class="material-symbols-outlined text-[16px] text-slate-500 group-hover:text-[#061D49]">checklist</span>
              <span>Seguimiento</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('postergar')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="postpone">
              <span class="material-symbols-outlined text-[16px] text-slate-500 group-hover:text-[#061D49]">schedule</span>
              <span>Postergar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('reasignar')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="reassign">
              <span class="material-symbols-outlined text-[16px] text-slate-500 group-hover:text-[#061D49]">manage_accounts</span>
              <span>Reasignar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('activar') && $isPostponedOrClosed): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="activate">
              <span class="material-symbols-outlined text-[16px] text-emerald-600">play_circle</span>
              <span>Activar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('cerrar')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-all cursor-pointer" data-commercial-open-workflow="close">
              <span class="material-symbols-outlined text-[16px] text-rose-600">check_circle</span>
              <span>Cerrar Tarea</span>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Customer and Property Details Card -->
      <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card flex flex-col gap-4" data-purpose="customer-property-summary">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Detalles &amp; Contacto</span>
          <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            Lead Calificado
          </span>
        </div>

        <!-- Solicitante Profile Block -->
        <div class="flex items-start gap-3 bg-slate-50/70 p-3 rounded-xl border border-slate-100">
          <div class="w-10 h-10 rounded-full bg-[#EBF1FB] text-[#061D49] font-bold flex items-center justify-center text-sm border border-[#1E3C76]/20 flex-shrink-0">
            <?php echo esc_html($requesterInitials); ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-semibold text-slate-400 uppercase">Solicitante</span>
              <?php if ($channel !== ''): ?>
                <span class="text-[11px] text-slate-400">Canal: <?php echo esc_html($channel); ?></span>
              <?php endif; ?>
            </div>
            <h3 class="text-xs font-bold text-slate-900 truncate"><?php echo esc_html($requester); ?></h3>
            <div class="mt-2 space-y-1">
              <?php if ($email !== ''): ?>
                <!-- Email with copy action -->
                <div class="flex items-center justify-between text-xs text-slate-600 bg-white px-2 py-1 rounded border border-slate-200">
                  <div class="flex items-center gap-1.5 truncate">
                    <span class="material-symbols-outlined text-[15px] text-slate-400 flex-shrink-0">mail</span>
                    <span class="truncate"><?php echo esc_html($email); ?></span>
                  </div>
                  <button type="button" class="text-slate-400 hover:text-[#1E3C76] ml-1 cursor-pointer" title="Copiar correo" data-commercial-copy="<?php echo esc_attr($email); ?>">
                    <span class="material-symbols-outlined text-[14px]">content_copy</span>
                  </button>
                </div>
              <?php endif; ?>
              <?php if ($phone !== ''): ?>
                <!-- Phone with WhatsApp action -->
                <div class="flex items-center justify-between text-xs text-slate-600 bg-white px-2 py-1 rounded border border-slate-200">
                  <div class="flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[15px] text-slate-400 flex-shrink-0">phone</span>
                    <span><?php echo esc_html($phone); ?></span>
                  </div>
                  <?php if ($cleanPhone !== ''): ?>
                    <a class="text-emerald-600 hover:text-emerald-700 font-medium flex items-center gap-1 text-[11px]" href="https://wa.me/57<?php echo esc_attr($cleanPhone); ?>" target="_blank" rel="noopener noreferrer">
                      <span>WhatsApp</span>
                      <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Inmueble Snapshot Block -->
        <?php if ($propertyCode !== '' || $address !== '' || $neighborhood !== ''): ?>
          <div class="bg-slate-50/70 p-3.5 rounded-xl border border-slate-100 flex flex-col gap-2">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-1.5 text-[#061D49] font-bold text-xs">
                <span class="material-symbols-outlined text-[16px] text-[#1E3C76]">apartment</span>
                <span>Inmueble: <?php echo $propertyCode !== '' ? ('Código #' . esc_html($propertyCode)) : 'Registrado'; ?></span>
              </div>
              <?php if ($canon !== ''): ?>
                <span class="text-[11px] font-semibold px-2 py-0.5 rounded bg-blue-100/70 text-[#1E3C76]">Canon: <?php echo esc_html($canon); ?></span>
              <?php endif; ?>
            </div>
            <?php if ($propertyType !== '' || $neighborhood !== ''): ?>
              <p class="text-xs text-slate-600 font-medium">
                <?php echo esc_html(implode(' · ', array_filter([$propertyType, $neighborhood, $city]))); ?>
              </p>
            <?php endif; ?>
            <?php if ($address !== ''): ?>
              <p class="text-[11px] text-slate-500">
                Dirección: <?php echo esc_html($address); ?>
              </p>
            <?php endif; ?>
            <?php if ($propertyCode !== ''): ?>
              <div class="pt-1 flex items-center justify-between">
                <a href="https://sucasainmobiliaria.com.co/inmueble/<?php echo esc_attr($propertyCode); ?>" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-[#1E3C76] hover:text-[#061D49] hover:underline flex items-center gap-1 cursor-pointer">
                  <span>Abrir ficha técnica detallada</span>
                  <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                </a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Asesor Asignado -->
        <div class="flex items-center justify-between pt-1 text-xs">
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-slate-200 flex items-center justify-center font-bold text-slate-700 text-xs">
              <?php echo esc_html($assigneeInitials); ?>
            </div>
            <div>
              <span class="text-[10px] text-slate-400 block leading-tight">Responsable asignado</span>
              <span class="font-semibold text-slate-800"><?php echo esc_html($assignee); ?></span>
            </div>
          </div>
          <?php if ($assigneeId !== ''): ?>
            <span class="text-[11px] text-slate-500 font-mono">ID: <?php echo esc_html($assigneeId); ?></span>
          <?php endif; ?>
        </div>

        <!-- Tiempos SLA -->
        <div class="pt-2 border-t border-slate-100">
          <div class="flex justify-between text-xs mb-1">
            <span class="text-slate-500 font-medium">Cumplimiento de Acuerdo (SLA)</span>
            <span class="font-bold text-slate-800">92%</span>
          </div>
          <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
            <div class="bg-[#F8CF4A] h-2 rounded-full w-[92%]"></div>
          </div>
        </div>
      </div>

      <!-- Saved Analyses -->
      <?php echo self::renderAnalysesList($pk, $analyses); ?>
    </section>
    <!-- END: LeftColumnManagement -->

    <!-- BEGIN: RightColumnTraceability -->
    <section class="lg:col-span-7 flex flex-col gap-5">
      <!-- Contenedor de Formularios de Workflow -->
      <div class="commercial-workflow-stack space-y-4" data-commercial-workflow-stack hidden>
        <?php if ($policy->canAct('responder')): ?>
          <?php echo self::replyForm($pk, $requester, $status, $propertyCode); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('agregar_nota')): ?>
          <?php echo self::messageForm($pk, 'note', 'Agregar nota interna privada', 'Esta nota sólo será visible para el equipo interno y auditoría.', 'observacion', 'Escribe los detalles internos confidenciales de la tarea…', 'Guardar nota interna'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('seguimiento')): ?>
          <?php echo self::messageForm($pk, 'follow_up', 'Registrar gestión de seguimiento', 'Deja constancia de llamadas, acuerdos o gestiones comerciales realizadas.', 'observacion', 'Describe detalladamente la gestión comercial efectuada…', 'Guardar seguimiento', true, false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('postergar')): ?>
          <?php echo self::messageForm($pk, 'postpone', 'Postergar tarea', 'La tarea se moverá al estado comercial Postergado hasta la fecha programada.', 'observacion', 'Indica el motivo o justificación de la postergación…', 'Postergar tarea', true, true, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('activar') && $isPostponedOrClosed): ?>
          <?php echo self::statusMessageForm($pk, 'activate', 'Activar tarea', 'Selecciona el estado con el que retoma la gestión activa.', 'motivo', CommercialStatusCatalog::OPEN, 'Nuevo', 'Activar tarea ahora', false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cerrar')): ?>
          <?php echo self::statusMessageForm($pk, 'close', 'Cerrar tarea', 'Selecciona el resultado final del negocio o servicio y registra las observaciones.', 'observacion', CommercialStatusCatalog::CLOSED, 'Finalizado', 'Confirmar cierre de tarea', true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('reasignar')): ?>
          <?php echo self::employeeForm($pk, $employees, (string) ($ticket['id_empleado'] ?? '')); ?>
        <?php endif; ?>
      </div>

      <!-- Traceability Feed Header with Counter Pills & Feed -->
      <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card flex flex-col flex-1">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4 pb-3 border-b border-slate-100">
          <div>
            <span class="text-xs font-bold uppercase tracking-wider text-[#061D49] block">Trazabilidad y Cronología</span>
            <span class="text-xs text-slate-400">Historial completo ordenado cronológicamente</span>
          </div>
          <!-- Metrics Badges -->
          <div class="flex items-center gap-1.5 flex-wrap">
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 text-slate-700">
              <?php echo esc_html((string) count($timeline)); ?> registros
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-100/70 text-amber-800">
              <?php echo esc_html((string) $timelineCounts['respuesta']); ?> respuestas
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-100/70 text-[#1E3C76]">
              <?php echo esc_html((string) $timelineCounts['seguimiento']); ?> seguimientos
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 text-slate-400">
              <?php echo esc_html((string) $timelineCounts['nota']); ?> notas
            </span>
          </div>
        </div>

        <!-- Messages Timeline / Feed -->
        <div class="space-y-4 overflow-y-auto max-h-[520px] pr-1" data-purpose="timeline-feed">
          <?php if ($timeline === []): ?>
            <div class="py-16 text-center text-slate-400">
              <span class="material-symbols-outlined text-[36px] opacity-40 block mb-1">chat</span>
              <p class="text-xs text-slate-500">Aún no hay respuestas, seguimientos o notas registradas para esta tarea.</p>
            </div>
          <?php else: ?>
            <?php foreach ($timeline as $index => $item):
              $type = (string) ($item['type'] ?? 'respuesta');
              $labels = ['respuesta' => 'Respuesta al cliente', 'seguimiento' => 'Seguimiento comercial', 'nota' => 'Nota interna privada'];
              $author = trim((string) ($item['nombre'] ?? '')) ?: 'Sistema';
              $actorId = trim((string) ($item['actor_id'] ?? ''));
              $actorEmail = trim((string) ($item['actor_email'] ?? ''));
              $time = self::formatDate($item['_timestamp'] ?? $item['fecha'] ?? 0);
              $msg = trim(wp_strip_all_tags((string) ($item['message'] ?? ''), true));

              $icon = '↩';
              $badgeBg = 'bg-amber-100 text-amber-900';
              $cardBg = $index === 0 ? 'bg-gradient-to-r from-amber-50/60 to-white border-amber-200/80 shadow-sm' : 'bg-white border-slate-200/90 shadow-sm hover:border-slate-300';
              if ($type === 'nota') {
                $icon = '🔒';
                $badgeBg = 'bg-slate-100 text-slate-700';
                $cardBg = 'bg-slate-50/80 border-slate-200';
              } elseif ($type === 'seguimiento') {
                $icon = '📅';
                $badgeBg = 'bg-blue-100 text-[#1E3C76]';
              }
            ?>
              <article class="p-4 rounded-xl border transition-all hover:shadow-sm <?php echo $cardBg; ?>">
                <div class="flex items-start justify-between gap-3 mb-2">
                  <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold <?php echo $badgeBg; ?>">
                      <?php echo $icon; ?>
                    </span>
                    <div>
                      <span class="text-xs font-bold text-slate-900"><?php echo esc_html($labels[$type] ?? 'Actividad'); ?></span>
                      <span class="text-xs text-slate-500">· <?php echo esc_html($author); ?></span>
                    </div>
                  </div>
                  <div class="text-right">
                    <span class="text-xs font-semibold text-slate-700"><?php echo esc_html($time); ?></span>
                  </div>
                </div>
                <div class="text-xs sm:text-[13px] text-slate-700 leading-relaxed font-normal pl-8">
                  <?php echo self::renderFormattedMessage($msg); ?>
                </div>
                <?php echo self::timelineAttachments($item); ?>
                <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400 pl-8">
                  <span><?php echo esc_html(self::actorMeta($actorId, $actorEmail)); ?></span>
                  <span class="text-amber-700 font-medium">Estado: <?php echo esc_html($status); ?></span>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <!-- END: RightColumnTraceability -->
  </main>
  <!-- END: ModalBodyContent -->

  <!-- BEGIN: ModalFooter -->
  <footer class="bg-white border-t border-slate-100 px-6 sm:px-8 py-3.5 flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
    <div class="flex items-center gap-2 text-xs text-slate-500">
      <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block animate-pulse"></span>
      <span>Sincronizado con SuCasa Inmobiliaria Core CRM v4.2</span>
    </div>
    <div class="flex items-center gap-3">
      <button class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 transition-colors cursor-pointer" type="button" data-commercial-close-case>
        Cerrar Ventana
      </button>
      <?php if ($policy->canAct('cerrar')): ?>
        <button class="px-5 py-2 rounded-xl text-xs font-bold text-[#061D49] bg-[#F8CF4A] hover:bg-[#E5BD3B] transition-all shadow-sm active:scale-95 flex items-center gap-2 cursor-pointer" type="button" data-commercial-open-workflow="close">
          <span class="material-symbols-outlined text-[16px]">task_alt</span>
          <span>Finalizar y Entregar Inmueble</span>
        </button>
      <?php endif; ?>
    </div>
  </footer>
  <!-- END: ModalFooter -->

  <!-- Modal Interno de Análisis IA -->
  <div class="fixed inset-0 z-[60] items-center justify-center p-3 sm:p-5 bg-[#061D49]/60 backdrop-blur-md commercial-analysis-modal overflow-y-auto" data-commercial-analysis-modal role="dialog" aria-modal="true" aria-labelledby="commercial-analysis-title" hidden>
    <div class="bg-white rounded-3xl shadow-modal w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col relative border border-slate-100" role="document" data-commercial-analysis-modal-content></div>
  </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $analyses */
  private static function renderAnalysesList(int $pk, array $analyses): string
  {
    ob_start();
?>
    <section class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-3" data-commercial-saved-analyses data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
      <div class="flex items-center justify-between pb-2 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-[16px] text-amber-500">auto_awesome</span>
          <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Análisis Guardados</h3>
        </div>
        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600" data-commercial-analysis-count><?php echo esc_html((string) count($analyses)); ?>/3</span>
      </div>
      <div class="space-y-2" data-commercial-analysis-list>
        <?php echo self::analysisListItems($pk, $analyses); ?>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $analyses */
  private static function analysisListItems(int $pk, array $analyses): string
  {
    if ($analyses === []) {
      return '<div class="py-4 text-center text-slate-400 text-xs"><p>Aún no hay análisis guardados con IA.</p></div>';
    }

    $html = '';
    foreach ($analyses as $analysis) {
      $id = (int) ($analysis['id'] ?? 0);
      $summary = trim((string) ($analysis['resumen'] ?? 'Análisis guardado'));
      $label = trim((string) ($analysis['created_label'] ?? $analysis['generated_at'] ?? 'Sin fecha'));
      $author = trim((string) ($analysis['created_by'] ?? 'Sistema'));
      $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

      $html .= '<div class="flex items-center justify-between p-3 bg-slate-50/80 hover:bg-[#EBF1FB]/60 rounded-xl border border-slate-200 transition-colors text-xs">'
        . '<button type="button" class="flex-1 text-left cursor-pointer" data-commercial-open-analysis data-analysis-json="' . esc_attr(is_string($json) ? $json : '{}') . '">'
        . '<strong class="block font-semibold text-[#061D49]">' . esc_html($label) . '</strong>'
        . '<span class="block text-slate-500 text-[11px] line-clamp-1 mt-0.5">' . esc_html(mb_strimwidth($summary, 0, 70, '…', 'UTF-8')) . '</span>'
        . '<small class="text-[10px] text-slate-400 mt-0.5 block">' . esc_html($author) . '</small>'
        . '</button>'
        . '<button type="button" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg transition-colors cursor-pointer" data-commercial-delete-analysis data-ticket-pk="' . esc_attr((string) $pk) . '" data-analysis-id="' . esc_attr((string) $id) . '" title="Eliminar análisis"><span class="material-symbols-outlined text-[16px]">delete</span></button>'
        . '</div>';
    }
    return $html;
  }

  /**
   * @param array<int,array<string,mixed>> $timeline
   * @return array{respuesta:int,seguimiento:int,nota:int}
   */
  private static function timelineCounts(array $timeline): array
  {
    $counts = ['respuesta' => 0, 'seguimiento' => 0, 'nota' => 0];
    foreach ($timeline as $item) {
      $type = (string) ($item['type'] ?? '');
      if (array_key_exists($type, $counts)) {
        $counts[$type]++;
      }
    }
    return $counts;
  }

  private static function actorMeta(string $actorId, string $actorEmail): string
  {
    $parts = [];
    if ($actorId !== '') {
      $parts[] = 'ID funcionario ' . $actorId;
    }
    if ($actorEmail !== '') {
      $parts[] = $actorEmail;
    }
    return $parts !== [] ? implode(' · ', $parts) : 'Autor registrado en historial';
  }

  private static function replyForm(int $pk, string $requester, string $currentStatus, string $propertyCode): string
  {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-4 commercial-workflow-form" data-commercial-workflow-form="reply" hidden>
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div>
          <h3 class="text-sm font-bold text-[#061D49] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px] text-[#1E3C76]">reply</span>
            <span>Responder al solicitante</span>
          </h3>
          <p class="text-xs text-slate-500 mt-0.5">Escribe la respuesta oficial para <?php echo esc_html($requester); ?>. Puedes actualizar el estado comercial y adjuntar enlaces o archivos.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">

      <!-- Selector de Nuevo Estado Comercial -->
      <div class="space-y-1.5 bg-slate-50/70 p-3 rounded-xl border border-slate-200/80">
        <div class="flex items-center justify-between">
          <label class="block text-xs font-bold text-slate-700 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[15px] text-[#1E3C76]">sync_alt</span>
            <span>Estado comercial tras la respuesta:</span>
          </label>
          <span class="text-[11px] font-normal text-slate-500">Actual: <strong class="text-[#061D49]"><?php echo esc_html($currentStatus); ?></strong></span>
        </div>
        <select name="estado" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-2.5 outline-none bg-white font-medium text-slate-800 transition">
          <option value="__keep__" selected>Mantener estado actual (<?php echo esc_html($currentStatus); ?>)</option>
          <optgroup label="Gestión Activa (Abiertos)">
            <?php foreach (CommercialStatusCatalog::OPEN as $st): ?>
              <?php if ($st !== $currentStatus): ?>
                <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Postergados">
            <?php foreach (CommercialStatusCatalog::POSTPONED as $st): ?>
              <?php if ($st !== $currentStatus): ?>
                <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Cerrados / Finalizados">
            <?php foreach (CommercialStatusCatalog::CLOSED as $st): ?>
              <?php if ($st !== $currentStatus): ?>
                <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>

      <!-- Barra de herramientas de enlaces y contenido enriquecido -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <label class="block text-xs font-semibold text-slate-700">
            Mensaje de respuesta <em class="text-rose-600">*</em>
          </label>
          <!-- Botones de inserción rápida de links -->
          <div class="flex items-center gap-1.5 flex-wrap">
            <?php if ($propertyCode !== ''): ?>
              <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-[#EBF1FB] hover:bg-[#d8e5f8] text-[#1E3C76] border border-[#1E3C76]/25 text-[11px] font-semibold transition-all shadow-xs cursor-pointer active:scale-95" data-commercial-insert-property="<?php echo esc_attr($propertyCode); ?>" title="Insertar enlace del inmueble de este caso en la respuesta">
                <span class="material-symbols-outlined text-[14px]">apartment</span>
                <span>Inmueble #<?php echo esc_html($propertyCode); ?></span>
              </button>
            <?php endif; ?>
            <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-[11px] font-medium transition-all cursor-pointer active:scale-95" data-commercial-insert-custom-property title="Insertar enlace a otro código de inmueble">
              <span class="material-symbols-outlined text-[14px] text-slate-500">add_home_work</span>
              <span>Otro inmueble</span>
            </button>
            <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-[11px] font-medium transition-all cursor-pointer active:scale-95" data-commercial-insert-link title="Insertar cualquier enlace web (URL)">
              <span class="material-symbols-outlined text-[14px] text-slate-500">link</span>
              <span>Enlace web</span>
            </button>
          </div>
        </div>
        <textarea name="respuesta" rows="5" required placeholder="Escribe aquí la respuesta oficial para <?php echo esc_attr($requester); ?>… (puedes usar los botones de arriba para adjuntar links de inmuebles o páginas web)" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-slate-400 outline-none transition font-sans"></textarea>
        <p class="text-[11px] text-slate-400 flex items-center gap-1">
          <span class="material-symbols-outlined text-[13px] text-slate-400">info</span>
          <span>Los links de inmuebles y URLs se convertirán automáticamente en accesos directos interactivos en la cronología.</span>
        </p>
      </div>

      <?php echo self::attachmentFields(); ?>

      <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-600 select-none pt-1">
        <input type="checkbox" name="notificar_solicitante" value="1" checked class="rounded border-slate-300 text-[#1E3C76] focus:ring-[#1E3C76]">
        <span>Notificar por correo electrónico al solicitante</span>
      </label>

      <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 bg-[#061D49] hover:bg-[#1E3C76] text-white rounded-xl text-xs font-semibold transition-all shadow-sm flex items-center gap-1.5 cursor-pointer">
          <span class="material-symbols-outlined text-[15px]">send</span>
          <span>Enviar respuesta</span>
        </button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  private static function messageForm(
    int $pk,
    string $action,
    string $title,
    string $help,
    string $field,
    string $placeholder,
    string $submit,
    bool $notify = false,
    bool $danger = false,
    bool $attachments = false
  ): string {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-slate-100">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-slate-500"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Mensaje <em class="text-rose-600">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="4" required placeholder="<?php echo esc_attr($placeholder); ?>" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-slate-400 outline-none transition"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>
      <?php if ($notify): ?>
        <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-600 select-none">
          <input type="checkbox" name="notificar_solicitante" value="1"<?php echo $action === 'reply' ? ' checked' : ''; ?> class="rounded border-slate-300 text-[#1E3C76] focus:ring-[#1E3C76]">
          <span>Notificar por correo electrónico al solicitante</span>
        </label>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 <?php echo $danger ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-[#061D49] hover:bg-[#1E3C76] text-white'; ?> rounded-xl text-xs font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $statuses */
  private static function statusMessageForm(
    int $pk,
    string $action,
    string $title,
    string $help,
    string $field,
    array $statuses,
    string $selected,
    string $submit,
    bool $danger = false,
    bool $attachments = false
  ): string {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-slate-100">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-slate-500"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Estado comercial <em class="text-rose-600">*</em></label>
        <select name="estado" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-slate-700">
          <?php foreach ($statuses as $st): ?>
            <option value="<?php echo esc_attr($st); ?>"<?php selected($selected, $st); ?>><?php echo esc_html($st); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Motivo / Observaciones <em class="text-rose-600">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="3" required placeholder="Describe el motivo de esta acción…" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-slate-400 outline-none transition"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 <?php echo $danger ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-[#061D49] hover:bg-[#1E3C76] text-white'; ?> rounded-xl text-xs font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  private static function attachmentFields(): string
  {
    $docAccept = 'image/jpeg,image/png,application/pdf,application/msword,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-rar-compressed,text/html,text/plain,text/csv';
    ob_start();
?>
    <div class="bg-slate-50 p-3.5 rounded-xl space-y-2 border border-slate-200">
      <span class="block text-xs font-semibold text-slate-700">Soportes y Evidencias Opcionales</span>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <label class="block space-y-1">
          <span class="block text-[11px] text-slate-500">Subir archivo</span>
          <input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple class="block w-full text-[12px] text-slate-600 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:bg-slate-200 file:text-slate-800 file:font-semibold">
        </label>
        <div class="p-2.5 bg-white rounded-lg border border-dashed border-slate-300 text-center cursor-pointer scm-paste-evidence hover:border-[#1E3C76] transition-colors" tabindex="0" role="button" data-scm-paste-evidence data-file-input-name="evidencia[]">
          <span class="block text-xs font-semibold text-[#1E3C76]">Pegar captura (Ctrl+V)</span>
          <span class="block text-[10px] text-slate-400">Haz clic aquí y presiona Ctrl+V</span>
          <ul data-scm-paste-list class="text-[11px] text-left mt-1 text-slate-700"></ul>
        </div>
      </div>
      <div class="scm-ticket-documents-zone pt-1" data-ticket-documents-zone>
        <div class="scm-ticket-documents space-y-1" data-ticket-documents></div>
        <button type="button" class="mt-1 px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold inline-flex items-center gap-1 cursor-pointer transition-colors" data-add-ticket-document data-document-accept="<?php echo esc_attr($docAccept); ?>">
          <span class="material-symbols-outlined text-[15px]">attach_file</span>
          <span>Agregar documento adicional</span>
        </button>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $item */
  private static function timelineAttachments(array $item): string
  {
    $images = self::extractAttachmentUrls($item['image'] ?? '');
    $documents = self::extractHistoryDocuments($item['documents'] ?? '');
    if ($images === [] && $documents === []) {
      return '';
    }

    $html = '<div class="space-y-2 pt-2 pl-8">';
    if ($images !== []) {
      $html .= '<div class="flex flex-wrap gap-2">';
      foreach ($images as $url) {
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="block w-20 h-20 rounded-xl overflow-hidden border border-slate-200 shadow-sm hover:opacity-90 transition-opacity">'
          . '<img src="' . esc_url($url) . '" alt="Evidencia adjunta" class="w-full h-full object-cover" loading="lazy">'
          . '</a>';
      }
      $html .= '</div>';
    }
    if ($documents !== []) {
      $html .= '<div class="flex flex-wrap gap-2">';
      foreach ($documents as $doc) {
        $url = trim((string) ($doc['archivo'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
          continue;
        }
        $label = trim((string) ($doc['nombre_archivo'] ?? ''));
        if ($label === '') {
          $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
        }
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition-colors"><span class="material-symbols-outlined text-[15px] text-slate-500">description</span>' . esc_html($label) . '</a>';
      }
      $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
  }

  /** @param mixed $raw @return array<int,string> */
  private static function extractAttachmentUrls($raw): array
  {
    if (is_array($raw)) {
      $items = $raw;
    } else {
      $value = trim((string) $raw);
      if ($value === '') {
        return [];
      }
      $items = [$value];
      if (preg_match('/^[aObis]:/', $value)) {
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($decoded)) {
          $items = $decoded;
        }
      }
    }

    $urls = [];
    foreach ($items as $item) {
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['archivo'] ?? '')) : trim((string) $item);
      $url = self::normalizeHistoryAttachmentUrl($url);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $urls[] = $url;
      }
    }

    return array_values(array_unique($urls));
  }

  private static function normalizeHistoryAttachmentUrl(string $url): string
  {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
      return $url;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '' || stripos($path, '/uploads/') === false) {
      return $url;
    }

    $fileName = basename($path);
    if (!self::isSafeLegacyAttachmentName($fileName)) {
      return $url;
    }

    return rtrim((string) SCM_BASE_URL, '/') . '/legacy-file.php?n=' . rawurlencode($fileName);
  }

  private static function isSafeLegacyAttachmentName(string $fileName): bool
  {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName)) {
      return false;
    }

    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], true);
  }

  /** @param mixed $raw @return array<int,array{nombre_archivo:string,archivo:string}> */
  private static function extractHistoryDocuments($raw): array
  {
    $value = trim((string) $raw);
    if ($value === '') {
      return [];
    }
    $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
    if (!is_array($decoded)) {
      $url = self::normalizeHistoryAttachmentUrl($value);
      return filter_var($url, FILTER_VALIDATE_URL) ? [['nombre_archivo' => '', 'archivo' => $url]] : [];
    }

    $documents = [];
    foreach ($decoded as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $url = self::normalizeHistoryAttachmentUrl(trim((string) ($doc['archivo'] ?? '')));
      if ($url === '') {
        continue;
      }
      $documents[] = [
        'nombre_archivo' => trim((string) ($doc['nombre_archivo'] ?? '')),
        'archivo' => $url,
      ];
    }

    return $documents;
  }

  /** @param array<int,string> $options */
  private static function selectForm(int $pk, string $action, string $title, string $field, array $options, string $selected, string $submit): string
  {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-slate-100">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-slate-500">Actualiza la etapa del embudo comercial.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Nuevo estado <em class="text-rose-600">*</em></label>
        <select name="<?php echo esc_attr($field); ?>" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-slate-700">
          <?php foreach ($options as $option): ?>
            <option value="<?php echo esc_attr($option); ?>"<?php selected($selected, $option); ?>><?php echo esc_html($option); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 bg-[#061D49] hover:bg-[#1E3C76] text-white rounded-xl text-xs font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,string>> $employees */
  private static function employeeForm(int $pk, array $employees, string $selected): string
  {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-card space-y-4 commercial-workflow-form" data-commercial-workflow-form="reassign" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-slate-100">
        <div>
          <h3 class="text-sm font-bold text-[#061D49] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px] text-[#1E3C76]">manage_accounts</span>
            <span>Reasignar responsable</span>
          </h3>
          <p class="text-xs text-slate-500 mt-0.5">Selecciona un integrante habilitado del equipo comercial para transferir la gestión de esta tarea.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Nuevo responsable <em class="text-rose-600">*</em></label>
        <select name="id_empleado" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-slate-700">
          <option value="">Selecciona un funcionario</option>
          <?php foreach ($employees as $employee):
            $id = (string) ($employee['id'] ?? $employee['id_empleado'] ?? '');
          ?>
            <option value="<?php echo esc_attr($id); ?>"<?php selected($selected, $id); ?>><?php echo esc_html((string) ($employee['nombre'] ?? 'Funcionario')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-slate-700">Motivo de la reasignación <span class="text-slate-400 font-normal">(Opcional)</span></label>
        <textarea name="observacion" rows="2" placeholder="Ej: Reasignación por turno, redistribución de carga comercial o rotación de zona…" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 focus:border-[#1E3C76] p-2.5 outline-none font-medium text-slate-700"></textarea>
      </div>
      <div class="space-y-1.5 pt-1 border-t border-slate-100">
        <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-700 select-none">
          <input type="checkbox" name="notificar_whatsapp" value="1" checked class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
          <span class="flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[16px] text-emerald-600">chat</span>
            <span>Notificar al nuevo responsable por <strong>WhatsApp</strong></span>
          </span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-700 select-none">
          <input type="checkbox" name="notificar_correo" value="1" checked class="rounded border-slate-300 text-[#1E3C76] focus:ring-[#1E3C76]">
          <span class="flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[16px] text-[#1E3C76]">mail</span>
            <span>Notificar al nuevo responsable por correo electrónico</span>
          </span>
        </label>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 bg-[#061D49] hover:bg-[#1E3C76] text-white rounded-xl text-xs font-semibold transition-all shadow-sm cursor-pointer flex items-center gap-1.5">
          <span class="material-symbols-outlined text-[15px]">send</span>
          <span>Reasignar y notificar</span>
        </button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  private static function initials(string $name): string
  {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    if (!empty($parts[0])) {
      $initials .= mb_substr($parts[0], 0, 1, 'UTF-8');
    }
    if (!empty($parts[1])) {
      $initials .= mb_substr($parts[1], 0, 1, 'UTF-8');
    }
    return mb_strtoupper($initials ?: 'SC', 'UTF-8');
  }

  /** @param mixed $value */
  private static function formatDate($value): string
  {
    if (is_numeric($value) && (int) $value > 0) {
      return date('d/m/Y · h:i a', (int) $value);
    }
    $parsed = strtotime((string) $value);
    return $parsed !== false ? date('d/m/Y · h:i a', $parsed) : 'Sin fecha';
  }

  private static function renderFormattedMessage(string $message): string
  {
    $escaped = esc_html($message);
    $pattern = '#https?://[^\s<>"\'\(\)]+#i';
    $formatted = preg_replace_callback($pattern, static function (array $matches): string {
      $url = $matches[0];
      $trailing = '';
      if (preg_match('/[.,;:!]+$/', $url, $punctMatches)) {
        $trailing = $punctMatches[0];
        $url = substr($url, 0, -strlen($trailing));
      }

      if (preg_match('#^https?://(?:www\.)?sucasainmobiliaria\.com\.co/inmueble/([a-zA-Z0-9_-]+)(?:[/?#].*)?$#i', $url, $codeMatches)) {
        $code = esc_html($codeMatches[1]);
        $safeUrl = esc_url($url);
        return sprintf(
          '<a href="%s" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-2.5 py-1 my-1 rounded-lg bg-[#EBF1FB] hover:bg-[#d5e3f7] text-[#1E3C76] font-semibold text-xs border border-[#1E3C76]/25 transition-all shadow-xs cursor-pointer"><span class="material-symbols-outlined text-[15px] text-[#1E3C76]">apartment</span><span>Ficha Inmueble #%s</span><span class="material-symbols-outlined text-[13px] opacity-60">open_in_new</span></a>%s',
          $safeUrl,
          $code,
          $trailing
        );
      }

      $safeUrl = esc_url($url);
      $displayUrl = strlen($url) > 45 ? esc_html(substr($url, 0, 42) . '…') : esc_html($url);
      return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-2 py-0.5 my-0.5 rounded-md bg-slate-100 hover:bg-slate-200 text-[#1E3C76] hover:underline font-medium text-xs border border-slate-200 transition-colors break-all cursor-pointer"><span class="material-symbols-outlined text-[14px]">link</span><span>%s</span><span class="material-symbols-outlined text-[12px] opacity-60">open_in_new</span></a>%s',
        $safeUrl,
        $displayUrl,
        $trailing
      );
    }, $escaped);

    return nl2br($formatted);
  }
}
