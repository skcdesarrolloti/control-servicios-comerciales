<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialReplyGuides;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Commercial\CommercialTaskTools;
use SCM\Commercial\CommercialTimelineEditor;

final class CommercialTicketModalView
{
  /**
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>,analyses?:array<int,array<string,mixed>>,reply_guides?:array<int,array<string,mixed>>,quotes_count?:int,studies_count?:int,studies_approved?:int,mandates_count?:int,closing_mandate?:array{id:int,hoja:array{id:int,estado:string},total:int}|null} $detail
   * @param array<int,array<string,string>> $employees
   */
  public static function render(array $detail, CommercialAccessPolicy $policy, array $employees): string
  {
    $ticket = $detail['ticket'];
    $timeline = $detail['timeline'];
    $analyses = is_array($detail['analyses'] ?? null) ? $detail['analyses'] : [];
    $guides = is_array($detail['reply_guides'] ?? null) ? $detail['reply_guides'] : [];
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
    $email = trim((string) ($ticket['correo_solicitante'] ?? $ticket['correo'] ?? ''));
    $phone = trim((string) ($ticket['celular_solicitante'] ?? $ticket['celular'] ?? ''));
    // El mismo número al que salen los avisos por WhatsApp: el de WhatsApp si lo dio, con su indicativo.
    $whatsappPhone = CommercialCallsNotifier::phone(trim((string) ($ticket['celular_wsp'] ?? '')) ?: $phone, (string) ($ticket['indicativo'] ?? ''));
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
  <header class="bg-white border-b border-surface-container px-6 sm:px-8 pt-6 pb-5 flex-shrink-0">
    <!-- Top Status Bar & Close Button -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <!-- Breadcrumb navigation & Primary Status Badges -->
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <nav class="flex items-center gap-1.5 text-secondary/70 font-medium mr-2">
          <span>Tareas</span>
          <span>/</span>
          <span><?php echo esc_html($theme !== '' ? $theme : 'Comercial'); ?></span>
          <span>/</span>
          <span class="text-[#1E3C76] font-semibold">Tarea #<?php echo esc_html($logicalId); ?></span>
        </nav>
        <!-- Pill Badges -->
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-surface-container text-[#061D49] border border-surface-container">
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
        <button aria-label="Cerrar modal" class="w-8 h-8 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center transition-all ml-1 cursor-pointer" type="button" data-commercial-close-case>
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
        <p class="text-xs sm:text-sm text-secondary mt-1">
          <?php echo esc_html($description !== '' ? $description : 'Solicitud comercial registrada en plataforma.'); ?>
          <?php if ($propertyCode !== ''): ?>
            · <span class="font-medium text-on-surface">Código Inmueble: #<?php echo esc_html($propertyCode); ?></span>
          <?php endif; ?>
        </p>
      </div>
      <!-- Quick Action Buttons -->
      <div class="flex flex-wrap items-center gap-2 self-start md:self-auto">
        <?php if ($propertyCode !== ''): ?>
          <button type="button" data-commercial-property-detail="<?php echo esc_attr($propertyCode); ?>" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-[#EBF1FB] text-[#061D49] border border-surface-container hover:border-[#1E3C76] text-xs font-medium transition-all shadow-sm cursor-pointer">
            <span class="material-symbols-outlined text-[16px] text-[#1E3C76]">apartment</span>
            <span>Ficha del inmueble</span>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Meta info pill row -->
    <div class="mt-4 pt-3 border-t border-surface-container flex flex-wrap items-center gap-y-2 gap-x-5 text-xs text-secondary">
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[15px] text-secondary/70">calendar_today</span>
        <span>Creación: <strong class="font-medium text-on-surface"><?php echo esc_html(self::formatDate($createdTs)); ?></strong></span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[15px] text-secondary/70">update</span>
        <span>Última actividad: <strong class="font-medium text-on-surface"><?php echo esc_html(self::formatDate($updatedTs)); ?></strong></span>
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
  <main class="flex-1 overflow-y-auto bg-surface-container-low/70 p-5 sm:p-7 grid grid-cols-1 lg:grid-cols-12 gap-6">
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
              <p class="text-[11px] text-secondary"><?php echo !empty($analyses) ? 'Diagnóstico ejecutivo listo' : 'Listo para procesar tarea'; ?></p>
            </div>
          </div>
          <span class="text-[11px] font-semibold text-amber-700 bg-amber-100/80 px-2 py-0.5 rounded-full">IA Activa</span>
        </div>
        <p class="text-xs text-secondary leading-relaxed mb-3">
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
      <div class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card" data-purpose="case-action-grid">
        <div class="flex items-center justify-between mb-3.5 pb-2 border-b border-surface-container">
          <span class="text-xs font-bold uppercase tracking-wider text-secondary/70">Gestión Operativa</span>
          <span class="text-[11px] text-[#1E3C76] font-medium"><?php echo count($timeline); ?> eventos registrados</span>
        </div>
        <div class="grid grid-cols-2 gap-2.5">
          <?php /* Las herramientas de inmueble y cotización solo salen en los temas donde se usan (CommercialTaskTools). */ ?>
          <?php if ($policy->canView('anadir_aviso') && $policy->canAct('registrar_aviso') && CommercialTaskTools::allowsTask(CommercialTaskTools::SIGN, $ticket)): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-task-form="sign" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">add_photo_alternate</span><span>Aviso nuevo</span></button>
          <?php endif; ?>
          <?php if ($policy->canAct('ingresar_inmueble') && CommercialTaskTools::allowsTask(CommercialTaskTools::PROPERTY, $ticket)): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-task-form="property" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">add_home_work</span><span>Ingresar inmueble</span></button>
          <?php endif; ?>
          <?php if ($policy->canAct('ingresar_inmueble') && CommercialTaskTools::allowsTask(CommercialTaskTools::MEDIA, $ticket) && ($propertyCode !== '' || (int) ($ticket['id_inmueble_data'] ?? 0) > 0)): ?>
            <button type="button" class="csg-secondary" data-commercial-task-form="media" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">photo_library</span><span>Multimedia del inmueble</span></button>
          <?php endif; ?>
          <?php if ($policy->canAct('cotizacion_crear') && CommercialTaskTools::allowsTask(CommercialTaskTools::QUOTE, $ticket)): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-task-form="quote" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">request_quote</span><span>Cotizar inmuebles</span></button>
          <?php endif; ?>
          <?php /* Estudio de aseguradora: en los temas de arriendo, cuando la tarea ya tiene cotización (o si ya tiene estudios). */ ?>
          <?php $studiesCount = (int) ($detail['studies_count'] ?? 0); ?>
          <?php if (($policy->canAct('estudio_crear') || $policy->canAct('estudio_responder') || $policy->canAct('estudio_desistir')) && ((CommercialTaskTools::allowsTask(CommercialTaskTools::STUDY, $ticket) && (int) ($detail['quotes_count'] ?? 0) > 0) || $studiesCount > 0)): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-task-form="study" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">verified_user</span><span>Estudio de aseguradora</span><?php if ($studiesCount > 0): ?><span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-[#F8CF4A] text-[#061D49] text-[11px] font-bold inline-flex items-center justify-center" aria-label="<?php echo esc_attr($studiesCount . ($studiesCount === 1 ? ' estudio' : ' estudios')); ?>"><?php echo esc_html((string) $studiesCount); ?></span><?php endif; ?></button>
          <?php endif; ?>
          <?php /* Contrato de mandato: cuando la tarea tiene un estudio aprobado (o si ya tiene mandatos). */ ?>
          <?php if ($policy->canView('mandatos') && ((int) ($detail['studies_approved'] ?? 0) > 0 || (int) ($detail['mandates_count'] ?? 0) > 0)): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-task-form="mandate" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"><span class="material-symbols-outlined text-[16px]">contract_edit</span><span>Contrato de mandato</span></button>
          <?php endif; ?>
          <?php /* Hoja de cierre: solo si la tarea ya tiene contrato de mandato; se hace en las páginas del panel inmobiliario. */ ?>
          <?php $closingMandate = $detail['closing_mandate'] ?? null; ?>
          <?php if (is_array($closingMandate)): ?>
            <?php $closingId = \SCM\Commercial\Mandates\MandateClosingSheet::current($closingMandate['hoja']); ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-cma-closing="<?php echo esc_attr((string) $closingMandate['id']); ?>" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>" data-cma-closing-title="<?php echo esc_attr(\SCM\Commercial\Mandates\MandateClosingSheet::label($closingMandate['hoja']) . ' · Mandato #' . $closingMandate['id']); ?>"><span class="material-symbols-outlined text-[16px]">handshake</span><span><?php echo esc_html($closingId > 0 ? 'Hoja de cierre #' . $closingId : 'Hoja de cierre'); ?></span><?php if ($closingMandate['hoja']['estado'] !== ''): ?><span class="ml-auto text-[11px] font-semibold text-secondary"><?php echo esc_html($closingMandate['hoja']['estado']); ?></span><?php endif; ?></button>
          <?php endif; ?>
          <?php if ($policy->canAct('responder')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] hover:text-[#1E3C76] text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="reply">
              <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#1E3C76]">reply</span>
              <span>Responder</span>
            </button>
          <?php endif; ?>
          <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] hover:text-[#1E3C76] text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-guides>
            <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#1E3C76]">menu_book</span>
            <span>Guías</span>
          </button>
          <?php if ($policy->canAct('agregar_nota')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="note">
              <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#061D49]">lock</span>
              <span>Nota interna</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('seguimiento')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="follow_up">
              <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#061D49]">checklist</span>
              <span>Seguimiento</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('postergar')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="postpone">
              <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#061D49]">schedule</span>
              <span>Postergar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('reasignar')): ?>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface border border-surface-container text-xs font-semibold transition-all group cursor-pointer" data-commercial-open-workflow="reassign">
              <span class="material-symbols-outlined text-[16px] text-secondary group-hover:text-[#061D49]">manage_accounts</span>
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

      <?php if ($policy->canView('calendario')): ?>
        <!-- Agenda comercial: agendar citas de la tarea en el calendario y consultar la agenda del responsable -->
        <div class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card" data-purpose="case-calendar"
          data-commercial-calendar-task
          data-ticket-pk="<?php echo esc_attr((string) $pk); ?>"
          data-ticket="<?php echo esc_attr($logicalId); ?>"
          data-asunto="<?php echo esc_attr($subject); ?>"
          data-tema="<?php echo esc_attr($theme); ?>"
          data-solicitante="<?php echo esc_attr($requester); ?>"
          data-direccion="<?php echo esc_attr(trim(implode(', ', array_filter([$address, $neighborhood, $city])))); ?>"
          data-inmueble="<?php echo esc_attr($propertyCode); ?>"
          data-empleado-id="<?php echo esc_attr($assigneeId); ?>"
          data-empleado="<?php echo esc_attr($assignee); ?>"
          data-estado-comercial="<?php echo esc_attr(trim((string) ($ticket['estado_comercial'] ?? ''))); ?>"
          data-estado-administrativo="<?php echo esc_attr(trim((string) ($ticket['estado_administrativo'] ?? ''))); ?>">
          <div class="flex items-center justify-between mb-3.5 pb-2 border-b border-surface-container">
            <span class="text-xs font-bold uppercase tracking-wider text-secondary/70">Agenda comercial</span>
            <span class="text-[11px] text-[#1E3C76] font-medium" data-commercial-calendar-task-status>Calendario de <?php echo esc_html($assignee); ?></span>
          </div>
          <div class="grid grid-cols-2 gap-2.5">
            <button type="button" class="col-span-2 flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl bg-[#F8CF4A] hover:bg-[#E5BD3B] text-[#061D49] text-xs font-bold transition-all cursor-pointer" data-commercial-calendar-schedule>
              <span class="material-symbols-outlined text-[16px]">event_available</span>
              <span>Agendar cita de la tarea</span>
            </button>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-[#EBF1FB] text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-calendar-agenda>
              <span class="material-symbols-outlined text-[16px]">calendar_month</span>
              <span>Ver agenda</span>
            </button>
            <button type="button" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface border border-surface-container text-xs font-semibold transition-all cursor-pointer" data-commercial-calendar-pending>
              <span class="material-symbols-outlined text-[16px] text-rose-600">warning</span>
              <span>Pendientes</span>
              <em class="not-italic ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-rose-100 text-rose-700" data-commercial-calendar-pending-count hidden>0</em>
            </button>
          </div>
          <div class="mt-3 space-y-1.5" data-commercial-calendar-task-events aria-live="polite"></div>
        </div>
      <?php endif; ?>

      <!-- Customer and Property Details Card -->
      <div class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card flex flex-col gap-4" data-purpose="customer-property-summary">
        <div class="border-b border-surface-container pb-3">
          <span class="text-xs font-bold uppercase tracking-wider text-secondary/70">Detalles &amp; Contacto</span>
        </div>

        <!-- Solicitante Profile Block -->
        <div class="flex items-start gap-3 bg-surface-container-low/70 p-3 rounded-xl border border-surface-container">
          <div class="w-10 h-10 rounded-full bg-[#EBF1FB] text-[#061D49] font-bold flex items-center justify-center text-sm border border-[#1E3C76]/20 flex-shrink-0">
            <?php echo esc_html($requesterInitials); ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-semibold text-secondary/70 uppercase">Solicitante</span>
              <?php if ($channel !== ''): ?>
                <span class="text-[11px] text-secondary/70">Canal: <?php echo esc_html($channel); ?></span>
              <?php endif; ?>
            </div>
            <h3 class="text-xs font-bold text-on-surface truncate"><?php echo esc_html($requester); ?></h3>
            <div class="mt-2 space-y-1">
              <?php if ($email !== ''): ?>
                <!-- Email with copy action -->
                <div class="flex items-center justify-between text-xs text-secondary bg-white px-2 py-1 rounded border border-surface-container">
                  <div class="flex items-center gap-1.5 truncate">
                    <span class="material-symbols-outlined text-[15px] text-secondary/70 flex-shrink-0">mail</span>
                    <span class="truncate"><?php echo esc_html($email); ?></span>
                  </div>
                  <button type="button" class="text-secondary/70 hover:text-[#1E3C76] ml-1 cursor-pointer" title="Copiar correo" data-commercial-copy="<?php echo esc_attr($email); ?>">
                    <span class="material-symbols-outlined text-[14px]">content_copy</span>
                  </button>
                </div>
              <?php endif; ?>
              <?php if ($phone !== ''): ?>
                <!-- Phone with WhatsApp action -->
                <div class="flex items-center justify-between gap-2 text-xs text-secondary bg-white pl-2 pr-1 py-1 rounded border border-surface-container">
                  <div class="flex items-center gap-1.5 min-w-0">
                    <span class="material-symbols-outlined text-[15px] text-secondary/70 flex-shrink-0">phone</span>
                    <span class="truncate"><?php echo esc_html($phone); ?></span>
                    <button type="button" class="text-secondary/70 hover:text-[#1E3C76] cursor-pointer flex-shrink-0" title="Copiar celular" data-commercial-copy="<?php echo esc_attr($phone); ?>">
                      <span class="material-symbols-outlined text-[14px]">content_copy</span>
                    </button>
                  </div>
                  <?php if ($whatsappPhone !== ''): ?>
                    <a class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-[#25D366] hover:bg-[#1DA851] text-white text-[11px] font-semibold shadow-sm transition-colors flex-shrink-0" href="https://wa.me/<?php echo esc_attr(ltrim($whatsappPhone, '+')); ?>" target="_blank" rel="noopener noreferrer" title="Abrir el chat de WhatsApp con <?php echo esc_attr($requester); ?> (<?php echo esc_attr($whatsappPhone); ?>)" data-commercial-whatsapp>
                      <span class="material-symbols-outlined text-[14px]">chat</span>
                      <span>WhatsApp</span>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Inmueble Snapshot Block -->
        <?php if ($propertyCode !== '' || $address !== '' || $neighborhood !== ''): ?>
          <div class="bg-surface-container-low/70 p-3.5 rounded-xl border border-surface-container flex flex-col gap-2">
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
              <p class="text-xs text-secondary font-medium">
                <?php echo esc_html(implode(' · ', array_filter([$propertyType, $neighborhood, $city]))); ?>
              </p>
            <?php endif; ?>
            <?php if ($address !== ''): ?>
              <p class="text-[11px] text-secondary">
                Dirección: <?php echo esc_html($address); ?>
              </p>
            <?php endif; ?>
            <?php if ($propertyCode !== ''): ?>
              <div class="pt-1 flex items-center justify-between">
                <button type="button" data-commercial-property-detail="<?php echo esc_attr($propertyCode); ?>" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>" class="text-xs font-semibold text-[#1E3C76] hover:text-[#061D49] hover:underline flex items-center gap-1 cursor-pointer">
                  <span>Abrir ficha técnica detallada</span>
                  <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                </button>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Consultor/a asignado -->
        <div class="flex items-center justify-between pt-1 text-xs">
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-surface-container-high flex items-center justify-center font-bold text-on-surface text-xs">
              <?php echo esc_html($assigneeInitials); ?>
            </div>
            <div>
              <span class="text-[10px] text-secondary/70 block leading-tight">Responsable asignado</span>
              <span class="font-semibold text-on-surface"><?php echo esc_html($assignee); ?></span>
            </div>
          </div>
          <?php if ($assigneeId !== ''): ?>
            <span class="text-[11px] text-secondary font-mono">ID: <?php echo esc_html($assigneeId); ?></span>
          <?php endif; ?>
        </div>

        <?php echo self::slaBar($ticket); ?>
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
          <?php echo self::replyForm($pk, $requester, $status, $propertyCode, $guides); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('agregar_nota')): ?>
          <?php echo self::messageForm($pk, 'note', 'Agregar nota interna privada', 'Esta nota sólo será visible para el equipo interno y auditoría.', 'observacion', 'Escribe los detalles internos confidenciales de la tarea…', 'Guardar nota interna'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('seguimiento')): ?>
          <?php echo self::messageForm($pk, 'follow_up', 'Registrar gestión de seguimiento', 'Deja constancia de llamadas, acuerdos o gestiones comerciales realizadas. El cliente lo recibe por correo.', 'observacion', 'Describe detalladamente la gestión comercial efectuada…', 'Guardar seguimiento', false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('postergar')): ?>
          <?php echo self::messageForm($pk, 'postpone', 'Postergar tarea', 'La tarea pasa a Postergado hasta la fecha que elijas: ese día aparece en el calendario para retomarla. El cliente recibe el motivo y la fecha por correo y WhatsApp.', 'observacion', 'Indica el motivo o justificación de la postergación…', 'Postergar tarea', true, true, self::resumeField()); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('activar') && $isPostponedOrClosed): ?>
          <?php echo self::statusMessageForm($pk, 'activate', 'Activar tarea', 'Selecciona el estado con el que retoma la gestión activa.', 'motivo', CommercialStatusCatalog::OPEN, 'Nuevo', 'Activar tarea ahora', false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cerrar')): ?>
          <?php echo self::statusMessageForm($pk, 'close', 'Cerrar tarea', 'Selecciona el resultado final y registra las observaciones. El cliente las recibe por correo y WhatsApp, salvo en Duplicado y Trasladado.', 'observacion', CommercialStatusCatalog::CLOSED, 'Finalizado', 'Confirmar cierre de tarea', true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('reasignar')): ?>
          <?php echo self::employeeForm($pk, $employees, (string) ($ticket['id_empleado'] ?? '')); ?>
        <?php endif; ?>
      </div>

      <!-- Traceability Feed Header with Counter Pills & Feed -->
      <div class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card flex flex-col flex-1">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4 pb-3 border-b border-surface-container">
          <div>
            <span class="text-xs font-bold uppercase tracking-wider text-[#061D49] block">Trazabilidad y Cronología</span>
            <span class="text-xs text-secondary/70">Historial completo ordenado cronológicamente</span>
          </div>
          <!-- Metrics Badges -->
          <div class="flex items-center gap-1.5 flex-wrap">
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-surface-container text-on-surface">
              <?php echo esc_html((string) count($timeline)); ?> registros
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-100/70 text-amber-800">
              <?php echo esc_html((string) $timelineCounts['respuesta']); ?> respuestas
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-100/70 text-[#1E3C76]">
              <?php echo esc_html((string) $timelineCounts['seguimiento']); ?> seguimientos
            </span>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-surface-container text-secondary/70">
              <?php echo esc_html((string) $timelineCounts['nota']); ?> notas
            </span>
          </div>
        </div>

        <!-- Messages Timeline / Feed -->
        <?php
          // Editar y eliminar entradas: su autor (id_empleado exacto) o un administrador, con el permiso historial_editar.
          $me = trim((string) \SCM\Core\Auth::employeeId());
          $isAdmin = $policy->canManage();
          $canEditTimeline = $policy->canAct('historial_editar');
        ?>
        <div class="space-y-4 overflow-y-auto max-h-[520px] pr-1" data-purpose="timeline-feed" data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
          <?php if ($timeline === []): ?>
            <div class="py-16 text-center text-secondary/70">
              <span class="material-symbols-outlined text-[36px] opacity-40 block mb-1">chat</span>
              <p class="text-xs text-secondary">Aún no hay respuestas, seguimientos o notas registradas para esta tarea.</p>
            </div>
          <?php else: ?>
            <?php foreach ($timeline as $index => $item):
              $type = (string) ($item['type'] ?? 'respuesta');
              $labels = ['respuesta' => 'Respuesta al cliente', 'seguimiento' => 'Seguimiento comercial', 'nota' => 'Nota interna privada'];
              $author = trim((string) ($item['nombre'] ?? '')) ?: 'Sistema';
              $actorId = trim((string) ($item['actor_id'] ?? ''));
              $actorEmail = trim((string) ($item['actor_email'] ?? ''));
              // Mensaje que el cliente escribió desde su enlace público.
              $fromClient = $type === 'respuesta' && $actorId === \SCM\Commercial\Calls\PublicTaskConversation::CLIENT_MARK;
              if ($fromClient) {
                $actorId = '';
              }
              $time = self::formatDate($item['_timestamp'] ?? $item['fecha'] ?? 0);
              $msg = trim(wp_strip_all_tags((string) ($item['message'] ?? ''), true));
              $entryId = (int) ($item['_ID'] ?? 0);
              $can = CommercialTimelineEditor::permissions($item, $me, $isAdmin, $canEditTimeline);
              $editsLeft = CommercialTimelineEditor::MAX_EDITS - $can['edits'];
              $editedTs = (int) ($item['fecha_edicion'] ?? 0);

              $icon = '↩';
              $badgeBg = 'bg-amber-100 text-amber-900';
              $cardBg = $index === 0 ? 'bg-gradient-to-r from-amber-50/60 to-white border-amber-200/80 shadow-sm' : 'bg-white border-surface-container/90 shadow-sm hover:border-outline-variant/40';
              if ($type === 'nota') {
                $icon = '🔒';
                $badgeBg = 'bg-surface-container text-on-surface';
                $cardBg = 'bg-surface-container-low/80 border-surface-container';
              } elseif ($type === 'seguimiento') {
                $icon = '📅';
                $badgeBg = 'bg-blue-100 text-[#1E3C76]';
              }
            ?>
              <article class="p-4 rounded-xl border transition-all hover:shadow-sm <?php echo $cardBg; ?>" data-commercial-timeline-entry data-type="<?php echo esc_attr($type); ?>" data-id="<?php echo esc_attr((string) $entryId); ?>">
                <div class="flex items-start justify-between gap-3 mb-2">
                  <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold <?php echo $badgeBg; ?>">
                      <?php echo $icon; ?>
                    </span>
                    <div>
                      <span class="text-xs font-bold text-on-surface"><?php echo esc_html($fromClient ? 'Mensaje del cliente' : ($labels[$type] ?? 'Actividad')); ?></span>
                      <span class="text-xs text-secondary">· <?php echo esc_html($author); ?></span>
                      <?php if ($can['edits'] > 0): ?>
                        <span class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md bg-surface-container text-secondary text-[10px] font-semibold" title="<?php echo esc_attr('Editada ' . $can['edits'] . ($can['edits'] === 1 ? ' vez' : ' veces') . ($editedTs > 0 ? ', la última el ' . self::formatDate($editedTs) : '')); ?>" data-commercial-timeline-edited>
                          <span class="material-symbols-outlined text-[12px]">edit</span>Editada
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <span class="text-xs font-semibold text-on-surface"><?php echo esc_html($time); ?></span>
                    <?php if ($can['edit']): ?>
                      <button type="button" class="w-7 h-7 rounded-lg text-secondary/70 hover:text-[#1E3C76] hover:bg-[#EBF1FB] flex items-center justify-center cursor-pointer transition-colors" data-commercial-timeline-edit title="<?php echo esc_attr('Editar (' . ($editsLeft === 1 ? 'queda 1 edición' : 'quedan ' . $editsLeft . ' ediciones') . ')'); ?>" aria-label="Editar entrada">
                        <span class="material-symbols-outlined text-[16px]">edit</span>
                      </button>
                    <?php endif; ?>
                    <?php if ($can['delete']): ?>
                      <button type="button" class="w-7 h-7 rounded-lg text-secondary/70 hover:text-rose-600 hover:bg-rose-50 flex items-center justify-center cursor-pointer transition-colors" data-commercial-timeline-delete title="Eliminar (queda una nota interna de constancia)" aria-label="Eliminar entrada">
                        <span class="material-symbols-outlined text-[16px]">delete</span>
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="text-xs sm:text-[13px] text-on-surface leading-relaxed font-normal pl-8" data-commercial-timeline-text>
                  <?php echo self::renderFormattedMessage($msg, $pk); ?>
                </div>
                <?php if ($can['edit']): ?>
                  <form class="pl-8 mt-1 space-y-2" data-commercial-timeline-editor hidden>
                    <textarea name="texto" rows="5" required maxlength="<?php echo esc_attr((string) CommercialTimelineEditor::MAX_TEXT); ?>" class="w-full text-xs sm:text-[13px] rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 outline-none transition bg-white"><?php echo esc_html($msg); ?></textarea>
                    <div class="flex items-center justify-end gap-2 flex-wrap">
                      <span class="text-[11px] text-secondary mr-auto"><?php echo esc_html(($editsLeft === 1 ? 'Es la última edición' : 'Puedes editarla ' . $editsLeft . ' veces') . ($type !== 'nota' ? '. No se le reenvía al cliente.' : '.')); ?></span>
                      <span class="text-xs font-medium text-rose-600 w-full text-right" data-commercial-timeline-message aria-live="polite"></span>
                      <button type="button" class="px-3.5 py-1.5 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold transition-colors cursor-pointer" data-commercial-timeline-cancel>Cancelar</button>
                      <button type="submit" class="px-4 py-1.5 rounded-xl bg-[#061D49] hover:bg-[#1E3C76] text-white text-xs font-semibold shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer">
                        <span class="material-symbols-outlined text-[15px]">save</span><span>Guardar cambios</span>
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
                <?php echo self::timelineAttachments($item); ?>
                <div class="mt-2.5 pt-2 border-t border-surface-container flex items-center justify-between text-[11px] text-secondary/70 pl-8">
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
  <footer class="bg-white border-t border-surface-container px-6 sm:px-8 py-3.5 flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
    <div class="flex items-center gap-2 text-xs text-secondary">
      <span class="material-symbols-outlined text-[15px] text-secondary/70">info</span>
      <span>Tarea #<?php echo esc_html($logicalId); ?> · Responder, postergar, cerrar y las demás acciones están en Gestión Operativa.</span>
    </div>
    <button class="px-4 py-2 rounded-xl text-xs font-semibold text-secondary hover:text-on-surface bg-surface-container hover:bg-surface-container-high transition-colors cursor-pointer" type="button" data-commercial-close-case>
      Cerrar ventana
    </button>
  </footer>
  <!-- END: ModalFooter -->

  <?php echo self::guidesModal($guides, $status, $policy->canAct('responder'), $policy->canAct('respuestas_guia_editar')); ?>

  <!-- Modal Interno de Análisis IA -->
  <div class="fixed inset-0 z-[60] items-center justify-center p-3 sm:p-5 bg-[#061D49]/60 backdrop-blur-md commercial-analysis-modal overflow-y-auto" data-commercial-analysis-modal role="dialog" aria-modal="true" aria-labelledby="commercial-analysis-title" hidden>
    <div class="bg-white rounded-3xl shadow-modal w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col relative border border-surface-container" role="document" data-commercial-analysis-modal-content></div>
  </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $analyses */
  private static function renderAnalysesList(int $pk, array $analyses): string
  {
    ob_start();
?>
    <section class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-3" data-commercial-saved-analyses data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
      <div class="flex items-center justify-between pb-2 border-b border-surface-container">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-[16px] text-amber-500">auto_awesome</span>
          <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface">Análisis Guardados</h3>
        </div>
        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-container text-secondary" data-commercial-analysis-count><?php echo esc_html((string) count($analyses)); ?>/3</span>
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
      return '<div class="py-4 text-center text-secondary/70 text-xs"><p>Aún no hay análisis guardados con IA.</p></div>';
    }

    $html = '';
    foreach ($analyses as $analysis) {
      $id = (int) ($analysis['id'] ?? 0);
      $summary = trim((string) ($analysis['resumen'] ?? 'Análisis guardado'));
      $label = trim((string) ($analysis['created_label'] ?? $analysis['generated_at'] ?? 'Sin fecha'));
      $author = trim((string) ($analysis['created_by'] ?? 'Sistema'));
      $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

      $html .= '<div class="flex items-center justify-between p-3 bg-surface-container-low/80 hover:bg-[#EBF1FB]/60 rounded-xl border border-surface-container transition-colors text-xs">'
        . '<button type="button" class="flex-1 text-left cursor-pointer" data-commercial-open-analysis data-analysis-json="' . esc_attr(is_string($json) ? $json : '{}') . '">'
        . '<strong class="block font-semibold text-[#061D49]">' . esc_html($label) . '</strong>'
        . '<span class="block text-secondary text-[11px] line-clamp-1 mt-0.5">' . esc_html(mb_strimwidth($summary, 0, 70, '…', 'UTF-8')) . '</span>'
        . '<small class="text-[10px] text-secondary/70 mt-0.5 block">' . esc_html($author) . '</small>'
        . '</button>'
        . '<button type="button" class="p-1.5 text-secondary/70 hover:text-rose-600 rounded-lg transition-colors cursor-pointer" data-commercial-delete-analysis data-ticket-pk="' . esc_attr((string) $pk) . '" data-analysis-id="' . esc_attr((string) $id) . '" title="Eliminar análisis"><span class="material-symbols-outlined text-[16px]">delete</span></button>'
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

  /**
   * Plazo de atención real de la tarea (CommercialSlaService::decorateTicket, SLA del tema en Configuración).
   * @param array<string,mixed> $ticket
   */
  private static function slaBar(array $ticket): string
  {
    $status = (string) ($ticket['scm_sla_status'] ?? '');
    if ($status === '') {
      return '';
    }
    $days = (int) ($ticket['scm_attention_days'] ?? 0);
    $due = max(1, (int) ($ticket['scm_sla_due_days'] ?? 1));
    $warning = (int) ($ticket['scm_sla_warning_from'] ?? 0);
    $overdue = (string) ($ticket['scm_sla_is_overdue'] ?? '0') === '1';
    if ($status === 'cerrado' || $status === 'postergado') {
      $label = $status === 'cerrado' ? 'Cerrada' : 'Postergada';
      $detail = $status === 'cerrado' ? 'La tarea ya no cuenta plazo.' : 'El plazo no corre mientras esté postergada.';
      $tone = ['text-secondary', 'bg-surface-container-highest'];
      $width = $status === 'cerrado' ? 100 : 0;
    } else {
      $label = $overdue ? 'Atrasada' : ($warning > 0 && $days >= $warning ? 'Por vencer' : 'Al día');
      $detail = $overdue
        ? sprintf('%d %s hábiles sin atender; el plazo es de %d.', $days, $days === 1 ? 'día' : 'días', $due)
        : sprintf('Día %d de %d hábiles para atenderla.', $days, $due);
      $tone = $overdue ? ['text-rose-700', 'bg-rose-500'] : ($label === 'Por vencer' ? ['text-amber-700', 'bg-[#F8CF4A]'] : ['text-emerald-700', 'bg-emerald-500']);
      $width = min(100, max(4, (int) ($ticket['scm_sla_percent'] ?? 0)));
    }
    return '<div class="pt-2 border-t border-surface-container" data-commercial-sla="' . esc_attr($status) . '">'
      . '<div class="flex justify-between gap-2 text-xs mb-1">'
      . '<span class="text-secondary font-medium">Tiempo de atención (SLA)</span>'
      . '<span class="font-bold ' . $tone[0] . '">' . esc_html($label) . '</span>'
      . '</div>'
      . '<div class="w-full bg-surface-container rounded-full h-2 overflow-hidden"><div class="h-2 rounded-full ' . $tone[1] . '" style="width:' . $width . '%"></div></div>'
      . '<p class="text-[11px] text-secondary mt-1">' . esc_html($detail) . '</p>'
      . '</div>';
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

  /** @param array<int,array<string,mixed>> $guides */
  private static function replyForm(int $pk, string $requester, string $currentStatus, string $propertyCode, array $guides = []): string
  {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-4 commercial-workflow-form" data-commercial-workflow-form="reply" hidden>
      <div class="flex items-center justify-between pb-3 border-b border-surface-container">
        <div>
          <h3 class="text-sm font-bold text-[#061D49] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px] text-[#1E3C76]">reply</span>
            <span>Responder al solicitante</span>
          </h3>
          <p class="text-xs text-secondary mt-0.5">Escribe la respuesta oficial para <?php echo esc_html($requester); ?>. Le llega por correo y WhatsApp. Puedes actualizar el estado comercial y adjuntar enlaces o archivos.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">

      <!-- Selector de Nuevo Estado Comercial -->
      <div class="space-y-1.5 bg-surface-container-low/70 p-3 rounded-xl border border-surface-container/80">
        <div class="flex items-center justify-between">
          <label class="block text-xs font-bold text-on-surface flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[15px] text-[#1E3C76]">sync_alt</span>
            <span>Estado comercial tras la respuesta:</span>
          </label>
          <span class="text-[11px] font-normal text-secondary">Actual: <strong class="text-[#061D49]"><?php echo esc_html($currentStatus); ?></strong></span>
        </div>
        <?php $descriptions = CommercialStatusCatalog::descriptions(); ?>
        <select name="estado" data-commercial-status-select class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-2.5 outline-none bg-white font-medium text-on-surface transition">
          <option value="__keep__" selected data-label="<?php echo esc_attr($currentStatus); ?>" data-help="<?php echo esc_attr($descriptions[$currentStatus] ?? ''); ?>">Mantener estado actual (<?php echo esc_html($currentStatus); ?>)</option>
          <optgroup label="Gestión activa">
            <?php foreach (CommercialStatusCatalog::OPEN as $st): ?>
              <?php if ($st !== $currentStatus): ?>
                <option value="<?php echo esc_attr($st); ?>" data-label="<?php echo esc_attr($st); ?>" data-help="<?php echo esc_attr($descriptions[$st] ?? ''); ?>"><?php echo esc_html($st); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </optgroup>
        </select>
        <?php echo self::statusGuide($currentStatus, $descriptions[$currentStatus] ?? ''); ?>
        <p class="text-[11px] text-secondary">Para postergar o cerrar la tarea usa su botón: piden la fecha o el resultado y le avisan al cliente.</p>
      </div>

      <?php echo self::guidesLauncher($guides); ?>

      <!-- Barra de herramientas de enlaces y contenido enriquecido -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <label class="block text-xs font-semibold text-on-surface">
            Mensaje de respuesta <em class="text-rose-600">*</em>
          </label>
          <!-- Botones de inserción rápida de links -->
          <div class="flex items-center gap-1.5 flex-wrap">
            <?php if ($propertyCode !== ''): ?>
              <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-[#EBF1FB] hover:bg-[#d8e5f8] text-[#1E3C76] border border-[#1E3C76]/25 text-[11px] font-semibold transition-all shadow-xs cursor-pointer active:scale-95" data-commercial-insert-property="<?php echo esc_attr($propertyCode); ?>" title="Agrega a la respuesta el enlace de este inmueble en la página web">
                <span class="material-symbols-outlined text-[14px]">apartment</span>
                <span>Inmueble #<?php echo esc_html($propertyCode); ?></span>
              </button>
            <?php endif; ?>
            <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface border border-surface-container text-[11px] font-medium transition-all cursor-pointer active:scale-95" data-commercial-insert-custom-property title="Pide el código de otro inmueble y agrega a la respuesta el enlace de su ficha en la página web">
              <span class="material-symbols-outlined text-[14px] text-secondary">add_home_work</span>
              <span>Otro inmueble</span>
            </button>
            <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface border border-surface-container text-[11px] font-medium transition-all cursor-pointer active:scale-95" data-commercial-insert-link title="Pide una dirección web y la agrega a la respuesta">
              <span class="material-symbols-outlined text-[14px] text-secondary">link</span>
              <span>Enlace web</span>
            </button>
          </div>
        </div>
        <textarea name="respuesta" rows="5" required placeholder="Escribe aquí la respuesta oficial para <?php echo esc_attr($requester); ?>… (puedes usar los botones de arriba para adjuntar links de inmuebles o páginas web)" class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-secondary/60 outline-none transition font-sans"></textarea>
        <p class="text-[11px] text-secondary/70 flex items-center gap-1">
          <span class="material-symbols-outlined text-[13px] text-secondary/70">info</span>
          <span>Los links de inmuebles y URLs se convertirán automáticamente en accesos directos interactivos en la cronología.</span>
        </p>
      </div>

      <?php echo self::attachmentFields(); ?>

      <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-container">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
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
    bool $danger = false,
    bool $attachments = false,
    string $extra = ''
  ): string {
    ob_start();
?>
    <form class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-surface-container">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-secondary"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <?php echo $extra; ?>
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Mensaje <em class="text-rose-600">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="4" required placeholder="<?php echo esc_attr($placeholder); ?>" class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-secondary/60 outline-none transition"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>

      <div class="flex items-center justify-end gap-2 pt-2 border-t border-surface-container">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
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
    <form class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-surface-container">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-secondary"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Estado comercial <em class="text-rose-600">*</em></label>
        <?php $descriptions = CommercialStatusCatalog::descriptions(); ?>
        <select name="estado" required data-commercial-status-select class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-on-surface">
          <?php foreach ($statuses as $st): ?>
            <option value="<?php echo esc_attr($st); ?>" data-label="<?php echo esc_attr($st); ?>" data-help="<?php echo esc_attr($descriptions[$st] ?? ''); ?>"<?php selected($selected, $st); ?>><?php echo esc_html($st); ?></option>
          <?php endforeach; ?>
        </select>
        <?php echo self::statusGuide($selected, $descriptions[$selected] ?? ''); ?>
      </div>
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Motivo / Observaciones <em class="text-rose-600">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="3" required placeholder="Describe el motivo de esta acción…" class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-3 placeholder-secondary/60 outline-none transition"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-surface-container">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-5 py-2 <?php echo $danger ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-[#061D49] hover:bg-[#1E3C76] text-white'; ?> rounded-xl text-xs font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** Fecha para retomar la tarea postergada: desde mañana y hasta un año (lo valida también el servidor). */
  private static function resumeField(): string
  {
    $zone = new \DateTimeZone(\SCM\Support\ColombiaBusinessCalendar::TIMEZONE);
    $today = new \DateTimeImmutable('today', $zone);
    return '<div class="space-y-1"><label class="block text-xs font-semibold text-on-surface" for="commercial-resume-date">Retomar la tarea el <em class="text-rose-600">*</em></label>'
      . '<input type="date" id="commercial-resume-date" name="fecha_retomar" required min="' . $today->modify('+1 day')->format('Y-m-d') . '" max="' . $today->modify('+1 year')->format('Y-m-d') . '" class="w-full sm:w-60 text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring focus:ring-[#1E3C76]/20 p-2.5 outline-none bg-white text-on-surface">'
      . '<p class="text-[11px] text-secondary">Ese día la tarea sale en el calendario de vencimientos para retomarla.</p></div>';
  }

  /**
   * Significado del estado elegido bajo la lista (lo actualiza el JS al cambiar) y botón que abre la ventana de guías
   * en ese estado.
   */
  private static function statusGuide(string $status, string $meaning): string
  {
    return '<div class="flex items-start justify-between gap-3 rounded-xl border border-[#1E3C76]/15 bg-[#EBF1FB]/70 px-3 py-2.5" data-commercial-status-help aria-live="polite">'
      . '<p class="flex items-start gap-1.5 text-xs text-on-surface min-w-0"><span class="material-symbols-outlined text-[16px] text-[#1E3C76] shrink-0" aria-hidden="true">info</span>'
      . '<span><strong class="text-[#061D49]" data-commercial-status-help-name>' . esc_html($status) . '</strong>: <span data-commercial-status-help-text>' . esc_html($meaning) . '</span></span></p>'
      . '<button type="button" class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white hover:bg-[#061D49] hover:text-white text-[#1E3C76] border border-[#1E3C76]/25 text-[11px] font-semibold transition-colors cursor-pointer" data-commercial-open-guides><span class="material-symbols-outlined text-[14px]" aria-hidden="true">menu_book</span>Guía de estados</button></div>';
  }

  /**
   * Acceso a las respuestas guía dentro de Responder: abre la ventana de guías con el estado elegido en la lista.
   * @param array<int,array<string,mixed>> $guides
   */
  private static function guidesLauncher(array $guides): string
  {
    $matches = count(array_filter($guides, static fn(array $guide): bool => !empty($guide['match'])));
    $count = $guides === [] ? 'Aún no hay respuestas guía' : (count($guides) === 1 ? '1 respuesta guía' : count($guides) . ' respuestas guía') . ($matches > 0 ? ', ' . $matches . ' para este estado' : '');
    return '<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-surface-container bg-white px-3.5 py-3">'
      . '<div class="flex items-center gap-3 min-w-0"><span class="w-9 h-9 rounded-xl bg-[#F8CF4A]/30 text-[#061D49] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[19px]" aria-hidden="true">tips_and_updates</span></span>'
      . '<div class="min-w-0"><p class="text-xs font-bold text-[#061D49]">¿Qué responder?</p><p class="text-[11px] text-secondary">' . esc_html($count) . '</p></div></div>'
      . '<button type="button" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-[#061D49] hover:bg-[#1E3C76] text-white text-xs font-semibold transition-colors cursor-pointer" data-commercial-open-guides><span class="material-symbols-outlined text-[16px]" aria-hidden="true">menu_book</span>Ver respuestas guía</button></div>';
  }

  /**
   * Ventana de guías de la tarea (botón Guías, Responder y la guía de cada lista de estado): al elegir un estado muestra
   * qué significa y sus respuestas guía. Con responder, "Usar" pone el texto en la respuesta; con respuestas_guia_editar
   * se crean, editan y eliminan (solo la categoría Comercial, CommercialReplyGuides). El JS la pasa al <body> al abrirla
   * para que quede encima de la tarea y centrada.
   * @param array<int,array<string,mixed>> $guides
   */
  private static function guidesModal(array $guides, string $status, bool $canUse, bool $canEdit): string
  {
    $descriptions = CommercialStatusCatalog::descriptions();
    $groups = ['Gestión activa' => CommercialStatusCatalog::OPEN, 'Postergada' => CommercialStatusCatalog::POSTPONED, 'Cerrada' => CommercialStatusCatalog::CLOSED];
    $known = [];
    foreach (CommercialStatusCatalog::all() as $knownStatus) {
      $known[CommercialReplyGuides::key($knownStatus)] = true;
    }
    $others = [];
    foreach ($guides as $guide) {
      $label = trim((string) ($guide['estado'] ?? ''));
      $key = CommercialReplyGuides::key($label);
      if ($key !== '' && !isset($known[$key])) {
        $others[$key] = $label;
      }
    }
    if ($others !== []) {
      $groups['Otros estados de las respuestas'] = array_values($others);
    }
    $current = CommercialReplyGuides::key($status);
    $options = '';
    foreach ($groups as $label => $statuses) {
      $options .= '<optgroup label="' . esc_attr($label) . '">';
      foreach ($statuses as $item) {
        $key = CommercialReplyGuides::key($item);
        $help = $descriptions[$item] ?? 'Estado usado en las respuestas guía; no está en la lista de estados comerciales del panel.';
        $options .= '<option value="' . esc_attr($key) . '" data-label="' . esc_attr($item) . '" data-help="' . esc_attr($help) . '"' . ($key === $current ? ' selected' : '') . '>' . esc_html($item . ($key === $current ? ' (estado actual)' : '')) . '</option>';
      }
      $options .= '</optgroup>';
    }
    $field = 'w-full text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring-2 focus:ring-[#1E3C76]/15 px-3 py-2.5 outline-none bg-white text-on-surface transition';
    ob_start();
?>
  <!-- Ventana de guías: estados y respuestas guía -->
  <div class="fixed inset-0 z-[70] hidden items-center justify-center p-3 sm:p-6 bg-[#061D49]/55 backdrop-blur-sm" data-commercial-guides-modal data-current-status="<?php echo esc_attr($current); ?>" role="dialog" aria-modal="true" aria-labelledby="commercial-guides-title" hidden>
    <div class="bg-white w-full max-w-2xl max-h-[90vh] rounded-3xl shadow-2xl border border-surface-container flex flex-col overflow-hidden" role="document">
      <header class="px-6 pt-5 pb-4 flex items-start justify-between gap-4 border-b border-surface-container">
        <div class="flex items-start gap-3">
          <span class="w-11 h-11 rounded-2xl bg-[#EBF1FB] text-[#1E3C76] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]" aria-hidden="true">menu_book</span></span>
          <div>
            <span class="block text-[11px] uppercase tracking-wider font-semibold text-secondary/70">Guías de la tarea</span>
            <h2 class="text-lg font-bold text-[#061D49] leading-tight" id="commercial-guides-title">Estados y respuestas guía</h2>
            <p class="text-xs text-secondary mt-0.5">Elige un estado para ver qué significa y qué responderle al cliente.</p>
          </div>
        </div>
        <button type="button" class="w-9 h-9 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center shrink-0 transition-colors cursor-pointer" data-commercial-close-guides aria-label="Cerrar guías">
          <span class="material-symbols-outlined text-[20px]">close</span>
        </button>
      </header>

      <section class="px-6 py-4 bg-surface-container-low/80 border-b border-surface-container space-y-3">
        <label class="block">
          <span class="sr-only">Estado comercial</span>
          <select data-commercial-guides-state class="<?php echo esc_attr($field); ?> font-semibold">
            <option value="" data-label="Todos los estados" data-help="Todas las respuestas guía comerciales, de todos los estados.">Todos los estados</option>
            <?php echo $options; ?>
          </select>
        </label>
        <div class="flex items-start gap-3 rounded-2xl bg-white border border-surface-container p-3.5" data-commercial-guides-meaning aria-live="polite">
          <span class="w-8 h-8 rounded-full bg-[#F8CF4A]/35 text-[#061D49] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">lightbulb</span></span>
          <div class="min-w-0">
            <p class="text-sm font-bold text-[#061D49]" data-commercial-guides-meaning-title></p>
            <p class="text-[13px] text-secondary leading-relaxed" data-commercial-guides-meaning-text></p>
          </div>
        </div>
      </section>

      <div class="flex-1 overflow-y-auto px-6 py-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <label class="relative flex-1 min-w-[12rem]">
            <span class="material-symbols-outlined text-[18px] text-secondary/70 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true">search</span>
            <input type="search" data-commercial-guide-search placeholder="Buscar por situación o texto" aria-label="Buscar respuestas guía" class="w-full text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] focus:ring-2 focus:ring-[#1E3C76]/15 pl-9 pr-3 py-2.5 outline-none bg-white transition">
          </label>
          <?php if ($canEdit): ?>
            <button type="button" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-white hover:bg-[#EBF1FB] text-[#1E3C76] border border-[#1E3C76]/30 text-xs font-semibold transition-colors cursor-pointer" data-commercial-guide-new><span class="material-symbols-outlined text-[17px]" aria-hidden="true">add</span>Nueva respuesta</button>
          <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
          <form class="rounded-2xl border border-[#1E3C76]/25 bg-[#F7FAFF] p-4 space-y-3" data-commercial-guide-editor hidden>
            <p class="text-sm font-bold text-[#061D49] flex items-center gap-1.5"><span class="material-symbols-outlined text-[18px] text-[#1E3C76]" aria-hidden="true">edit_note</span><span data-commercial-guide-editor-title>Nueva respuesta guía</span></p>
            <input type="hidden" name="id" value="">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="block space-y-1">
                <span class="block text-xs font-semibold text-on-surface">Estado</span>
                <select name="estado" class="<?php echo esc_attr($field); ?>">
                  <option value="">Sin estado (sirve para todos)</option>
                  <?php foreach ($groups as $label => $statuses): ?>
                    <optgroup label="<?php echo esc_attr($label); ?>">
                      <?php foreach ($statuses as $item): ?>
                        <option value="<?php echo esc_attr($item); ?>" data-key="<?php echo esc_attr(CommercialReplyGuides::key($item)); ?>"><?php echo esc_html($item); ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="block space-y-1">
                <span class="block text-xs font-semibold text-on-surface">Situación <em class="text-rose-600">*</em></span>
                <input type="text" name="situacion" required maxlength="255" placeholder="Ej.: Solicitud de documentos" class="<?php echo esc_attr($field); ?>">
              </label>
            </div>
            <label class="block space-y-1">
              <span class="block text-xs font-semibold text-on-surface">Respuesta <em class="text-rose-600">*</em></span>
              <textarea name="respuesta" rows="6" required maxlength="<?php echo (int) CommercialReplyGuides::MAX_TEXT; ?>" placeholder="Texto que el consultor/a envía al cliente en esta situación" class="<?php echo esc_attr($field); ?>"></textarea>
            </label>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-guide-editor-message aria-live="polite"></span>
              <button type="button" class="px-4 py-2 bg-white hover:bg-surface-container text-on-surface border border-surface-container rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-guide-cancel>Cancelar</button>
              <button type="submit" class="px-5 py-2 bg-[#061D49] hover:bg-[#1E3C76] text-white rounded-xl text-xs font-semibold transition-colors cursor-pointer">Guardar respuesta</button>
            </div>
          </form>
        <?php endif; ?>
        <p class="text-[11px] uppercase tracking-wider font-semibold text-secondary/70 pt-1" data-commercial-guides-count></p>
        <ul class="space-y-2.5" data-commercial-guides-list><?php echo self::guideCards($guides, $canUse, $canEdit); ?></ul>
        <div class="text-center py-8 rounded-2xl border border-dashed border-surface-container bg-surface-container-low/60" data-commercial-guides-empty hidden>
          <span class="material-symbols-outlined text-[28px] text-secondary/50" aria-hidden="true">forum</span>
          <p class="text-xs text-secondary mt-1">No hay respuestas guía para este estado<?php echo $canEdit ? '. Crea una con «Nueva respuesta».' : '.'; ?></p>
        </div>
      </div>

      <footer class="px-6 py-3 border-t border-surface-container flex items-center justify-between gap-3 bg-white">
        <p class="text-[11px] text-secondary/70"><?php echo $canUse ? '«Usar» lleva el texto a Responder para que lo ajustes antes de enviar.' : 'Consulta qué significa cada estado y qué responder.'; ?></p>
        <button type="button" class="px-4 py-2 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-guides>Cerrar</button>
      </footer>
    </div>
  </div>
<?php
    return (string) ob_get_clean();
  }

  /**
   * Tarjetas de la ventana de guías (también las devuelve el servidor al guardar o eliminar una).
   * @param array<int,array<string,mixed>> $guides
   */
  public static function guideCards(array $guides, bool $canUse, bool $canEdit): string
  {
    $icon = 'w-8 h-8 rounded-lg flex items-center justify-center transition-colors cursor-pointer ';
    $cards = '';
    foreach ($guides as $guide) {
      $state = trim((string) ($guide['estado'] ?? ''));
      $situation = (string) ($guide['situacion'] ?? '');
      $text = (string) ($guide['respuesta'] ?? '');
      $match = !empty($guide['match']);
      $long = mb_strlen($text, 'UTF-8') > 200 || substr_count($text, "\n") > 2;
      $cards .= '<li class="rounded-2xl border border-surface-container bg-white p-4 hover:border-[#1E3C76]/35 hover:shadow-sm transition' . ($match ? ' border-l-4 border-l-[#1E3C76]' : '') . '" data-commercial-guide data-id="' . (int) ($guide['id'] ?? 0) . '" data-estado="' . esc_attr(CommercialReplyGuides::key($state)) . '" data-estado-label="' . esc_attr($state) . '" data-situacion="' . esc_attr($situation) . '">'
        . '<div class="flex items-start justify-between gap-3"><strong class="text-sm font-semibold text-[#061D49]">' . esc_html($situation) . '</strong>'
        . '<span class="shrink-0 text-[10px] font-semibold px-2 py-0.5 rounded-full ' . ($match ? 'bg-[#EBF1FB] text-[#1E3C76]' : 'bg-surface-container text-secondary') . '">' . esc_html($state !== '' ? $state : 'Todos los estados') . '</span></div>'
        . '<p class="mt-1.5 text-[13px] text-secondary leading-relaxed whitespace-pre-line' . ($long ? ' line-clamp-3' : '') . '" data-commercial-guide-text>' . esc_html($text) . '</p>'
        . '<div class="mt-3 flex items-center justify-between gap-2">'
        . ($long ? '<button type="button" class="text-[11px] font-semibold text-[#1E3C76] hover:underline cursor-pointer" data-commercial-guide-more aria-expanded="false">Ver más</button>' : '<span></span>')
        . '<div class="flex items-center gap-1">'
        . ($canEdit ? '<button type="button" class="' . $icon . 'text-secondary hover:bg-surface-container hover:text-[#061D49]" data-commercial-guide-edit title="Editar" aria-label="Editar ' . esc_attr($situation) . '"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span></button>'
          . '<button type="button" class="' . $icon . 'text-secondary/70 hover:bg-rose-50 hover:text-rose-600" data-commercial-guide-delete title="Eliminar" aria-label="Eliminar ' . esc_attr($situation) . '"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span></button>' : '')
        . ($canUse ? '<button type="button" class="ml-1 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#061D49] hover:bg-[#1E3C76] text-white text-xs font-semibold transition-colors cursor-pointer" data-commercial-use-guide><span class="material-symbols-outlined text-[15px]" aria-hidden="true">reply</span>Usar</button>' : '')
        . '</div></div></li>';
    }
    return $cards;
  }

  private static function attachmentFields(): string
  {
    $docAccept = 'image/jpeg,image/png,application/pdf,application/msword,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-rar-compressed,text/html,text/plain,text/csv';
    ob_start();
?>
    <div class="bg-surface-container-low p-3.5 rounded-xl space-y-2 border border-surface-container">
      <span class="block text-xs font-semibold text-on-surface">Soportes y Evidencias Opcionales</span>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <label class="block space-y-1">
          <span class="block text-[11px] text-secondary">Subir archivo</span>
          <input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple class="block w-full text-[12px] text-secondary file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:bg-surface-container-high file:text-on-surface file:font-semibold">
        </label>
        <div class="p-2.5 bg-white rounded-lg border border-dashed border-outline-variant/40 text-center cursor-pointer scm-paste-evidence hover:border-[#1E3C76] transition-colors" tabindex="0" role="button" data-scm-paste-evidence data-file-input-name="evidencia[]">
          <span class="block text-xs font-semibold text-[#1E3C76]">Pegar captura (Ctrl+V)</span>
          <span class="block text-[10px] text-secondary/70">Haz clic aquí y presiona Ctrl+V</span>
          <ul data-scm-paste-list class="text-[11px] text-left mt-1 text-on-surface"></ul>
        </div>
      </div>
      <div class="scm-ticket-documents-zone pt-1" data-ticket-documents-zone>
        <div class="scm-ticket-documents space-y-1" data-ticket-documents></div>
        <button type="button" class="mt-1 px-3 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-semibold inline-flex items-center gap-1 cursor-pointer transition-colors" data-add-ticket-document data-document-accept="<?php echo esc_attr($docAccept); ?>">
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
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="block w-20 h-20 rounded-xl overflow-hidden border border-surface-container shadow-sm hover:opacity-90 transition-opacity">'
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
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold transition-colors"><span class="material-symbols-outlined text-[15px] text-secondary">description</span>' . esc_html($label) . '</a>';
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
    <form class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-3.5 commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-surface-container">
        <div>
          <h3 class="text-sm font-bold text-[#061D49]"><?php echo esc_html($title); ?></h3>
          <p class="text-xs text-secondary">Actualiza la etapa del embudo comercial.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Nuevo estado <em class="text-rose-600">*</em></label>
        <select name="<?php echo esc_attr($field); ?>" required class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-on-surface">
          <?php foreach ($options as $option): ?>
            <option value="<?php echo esc_attr($option); ?>"<?php selected($selected, $option); ?>><?php echo esc_html($option); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-surface-container">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
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
    <form class="bg-white rounded-2xl p-5 border border-surface-container/80 shadow-card space-y-4 commercial-workflow-form" data-commercial-workflow-form="reassign" hidden>
      <div class="flex items-center justify-between pb-2 border-b border-surface-container">
        <div>
          <h3 class="text-sm font-bold text-[#061D49] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px] text-[#1E3C76]">manage_accounts</span>
            <span>Reasignar responsable</span>
          </h3>
          <p class="text-xs text-secondary mt-0.5">Selecciona un integrante habilitado del equipo comercial para transferir la gestión de esta tarea. Se le avisa por WhatsApp y correo.</p>
        </div>
        <button type="button" class="w-7 h-7 rounded-full bg-surface-container hover:bg-surface-container-high text-secondary hover:text-on-surface flex items-center justify-center cursor-pointer transition-colors" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Nuevo responsable <em class="text-rose-600">*</em></label>
        <select name="id_empleado" required class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] p-2.5 outline-none bg-white font-medium text-on-surface">
          <option value="">Selecciona un funcionario</option>
          <?php foreach ($employees as $employee):
            $id = (string) ($employee['id'] ?? $employee['id_empleado'] ?? '');
          ?>
            <option value="<?php echo esc_attr($id); ?>"<?php selected($selected, $id); ?>><?php echo esc_html((string) ($employee['nombre'] ?? 'Funcionario')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-on-surface">Motivo de la reasignación <em class="text-rose-600">*</em></label>
        <textarea name="observacion" rows="2" required placeholder="Ej: Reasignación por turno, redistribución de carga comercial o rotación de zona…" class="w-full text-xs sm:text-sm rounded-xl border border-surface-container focus:border-[#1E3C76] p-2.5 outline-none font-medium text-on-surface"></textarea>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-surface-container">
        <span class="text-xs text-rose-600 mr-auto font-medium" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
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

  /** Enlaces del mensaje: los de un inmueble abren su ficha interna (con la tarea de contexto); los demás, la página. */
  private static function renderFormattedMessage(string $message, int $ticketPk = 0): string
  {
    $escaped = esc_html($message);
    $pattern = '#https?://[^\s<>"\'\(\)]+#i';
    $formatted = preg_replace_callback($pattern, static function (array $matches) use ($ticketPk): string {
      $url = $matches[0];
      $trailing = '';
      if (preg_match('/[.,;:!]+$/', $url, $punctMatches)) {
        $trailing = $punctMatches[0];
        $url = substr($url, 0, -strlen($trailing));
      }

      // Ficha del inmueble: /inmuebles/inmueble-<código> (o el formato anterior /inmueble/<código>).
      if (preg_match('~^https?://(?:www\.)?sucasainmobiliaria\.com\.co/(?:inmuebles/inmueble-|inmueble/)([a-zA-Z0-9_-]+)/?(?:[?#].*)?$~i', $url, $codeMatches)) {
        $code = esc_attr($codeMatches[1]);
        return sprintf(
          '<button type="button" data-commercial-property-detail="%s" data-ticket-pk="%s" class="inline-flex items-center gap-1.5 px-2.5 py-1 my-1 rounded-lg bg-[#EBF1FB] hover:bg-[#d5e3f7] text-[#1E3C76] font-semibold text-xs border border-[#1E3C76]/25 transition-all shadow-xs cursor-pointer"><span class="material-symbols-outlined text-[15px] text-[#1E3C76]">apartment</span><span>Ficha Inmueble #%s</span></button>%s',
          $code,
          esc_attr((string) $ticketPk),
          esc_html($codeMatches[1]),
          $trailing
        );
      }

      $safeUrl = esc_url($url);
      $displayUrl = strlen($url) > 45 ? esc_html(substr($url, 0, 42) . '…') : esc_html($url);
      return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-2 py-0.5 my-0.5 rounded-md bg-surface-container hover:bg-surface-container-high text-[#1E3C76] hover:underline font-medium text-xs border border-surface-container transition-colors break-all cursor-pointer"><span class="material-symbols-outlined text-[14px]">link</span><span>%s</span><span class="material-symbols-outlined text-[12px] opacity-60">open_in_new</span></a>%s',
        $safeUrl,
        $displayUrl,
        $trailing
      );
    }, $escaped);

    return nl2br($formatted);
  }
}
