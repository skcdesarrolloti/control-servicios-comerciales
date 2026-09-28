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
    $subject = trim((string) ($ticket['asunto'] ?? '')) ?: 'Tarea comercial';
    $description = trim(wp_strip_all_tags((string) ($ticket['descripcion'] ?? ''), true));
    $requester = trim((string) ($ticket['solicitante'] ?? '')) ?: 'Sin solicitante';
    $assignee = trim((string) ($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '')) ?: 'Sin asignar';
    $external = $ticketUrl !== '' ? $ticketUrl . rawurlencode($logicalId) : '';
    $bucket = CommercialStatusCatalog::bucketForStatus($status);
    $timelineCounts = self::timelineCounts($timeline);

    ob_start();
?>
  <div class="flex flex-col gap-space-md pb-space-md border-b border-surface-container">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
      <div class="flex items-center gap-space-xs">
        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-surface-container text-on-surface font-label-md font-semibold">
          Tarea #<?php echo esc_html($logicalId); ?>
        </span>
        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-primary-container text-on-surface font-label-sm font-semibold">
          <?php echo esc_html($status); ?>
        </span>
      </div>
      <?php if ($external !== ''): ?>
        <a class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm font-semibold transition-colors" href="<?php echo esc_url($external); ?>" target="_blank" rel="noopener noreferrer">
          <span class="material-symbols-outlined text-[16px]">open_in_new</span>
          <span>Ver en portal</span>
        </a>
      <?php endif; ?>
    </div>
    <div>
      <h2 id="commercial-case-title" class="font-headline-md text-headline-md text-on-surface font-bold tracking-tight">
        <?php echo esc_html($subject); ?>
      </h2>
      <p class="font-body-md text-body-md text-on-surface-variant mt-1">
        <?php echo esc_html($description !== '' ? $description : 'Esta tarea no tiene una descripción detallada registrada.'); ?>
      </p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-lg pt-space-md">
    <!-- Columna Izquierda: Acciones y Resumen -->
    <aside class="space-y-space-md" aria-label="Resumen de la tarea">
      <!-- Acciones de Gestión -->
      <section class="bg-surface-container-low p-space-md rounded-2xl space-y-space-xs" aria-labelledby="commercial-case-actions-title">
        <div class="pb-1 border-b border-surface-container">
          <span class="font-label-sm text-secondary uppercase font-semibold">Gestión Operativa</span>
          <h3 id="commercial-case-actions-title" class="font-headline-sm text-[16px] text-on-surface font-semibold">Acciones de la tarea</h3>
        </div>
        <div class="grid grid-cols-2 gap-2 pt-1">
          <button type="button" class="col-span-2 flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl bg-primary-container hover:bg-primary-fixed-dim text-on-surface font-label-md font-semibold transition-all shadow-sm cursor-pointer" data-commercial-assistant data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
            <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
            <span>Analizar con IA</span>
          </button>
          <?php if ($policy->canAct('responder')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="reply">
              <span class="material-symbols-outlined text-[16px] text-secondary">reply</span>
              <span>Responder</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('agregar_nota')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="note">
              <span class="material-symbols-outlined text-[16px] text-secondary">note_alt</span>
              <span>Nota interna</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('seguimiento')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="follow_up">
              <span class="material-symbols-outlined text-[16px] text-secondary">checklist</span>
              <span>Seguimiento</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('postergar')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="postpone">
              <span class="material-symbols-outlined text-[16px] text-secondary">history</span>
              <span>Postergar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('activar')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="activate">
              <span class="material-symbols-outlined text-[16px] text-secondary">play_circle</span>
              <span>Activar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('cerrar')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-error-container hover:bg-error/20 text-on-error-container font-label-sm font-semibold transition-colors cursor-pointer" data-commercial-open-workflow="close">
              <span class="material-symbols-outlined text-[16px]">check_circle</span>
              <span>Cerrar</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('cambiar_estado')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="status">
              <span class="material-symbols-outlined text-[16px] text-secondary">sync_alt</span>
              <span>Estado</span>
            </button>
          <?php endif; ?>
          <?php if ($policy->canAct('reasignar')): ?>
            <button type="button" class="flex items-center gap-1.5 p-2 rounded-xl bg-surface-container-lowest hover:bg-surface-container text-on-surface font-label-sm font-semibold transition-colors cursor-pointer border border-surface-container" data-commercial-open-workflow="reassign">
              <span class="material-symbols-outlined text-[16px] text-secondary">manage_accounts</span>
              <span>Reasignar</span>
            </button>
          <?php endif; ?>
        </div>
      </section>

      <?php echo self::renderAnalysesList($pk, $analyses); ?>

      <!-- Resumen de Datos -->
      <section class="bg-surface-container-low p-space-md rounded-2xl space-y-space-xs">
        <h3 class="font-headline-sm text-[16px] text-on-surface font-semibold pb-1 border-b border-surface-container">Resumen</h3>
        <dl class="space-y-2 text-body-sm pt-1">
          <?php echo self::detailRow('Solicitante', $requester, 'person'); ?>
          <?php echo self::detailRow('Correo', trim((string) ($ticket['correo_solicitante'] ?? '')) ?: 'No registrado', 'mail'); ?>
          <?php echo self::detailRow('Celular', trim((string) ($ticket['celular_solicitante'] ?? '')) ?: 'No registrado', 'phone'); ?>
          <?php echo self::detailRow('Responsable', $assignee, 'badge'); ?>
          <?php echo self::propertyRow($ticket); ?>
          <?php echo self::detailRow('Prioridad', trim((string) ($ticket['prioridad'] ?? '')) ?: 'No definida', 'flag'); ?>
          <?php echo self::detailRow('Medio', trim((string) ($ticket['medio'] ?? '')) ?: 'No registrado', 'chat'); ?>
          <?php echo self::detailRow('Actualizado', self::formatDate($ticket['fecha_actualizacion'] ?? $ticket['fecha'] ?? 0), 'schedule'); ?>
        </dl>
      </section>
    </aside>

    <!-- Columna Derecha: Formularios Activos y Timeline -->
    <section class="lg:col-span-2 space-y-space-md">
      <!-- Contenedor de Formularios de Workflow -->
      <div class="commercial-workflow-stack space-y-space-md" data-commercial-workflow-stack hidden>
        <?php if ($policy->canAct('responder')): ?>
          <?php echo self::messageForm($pk, 'reply', 'Responder al solicitante', 'Escribe una respuesta clara para el cliente.', 'respuesta', 'Escribe la respuesta de la tarea…', 'Enviar respuesta', true, false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('agregar_nota')): ?>
          <?php echo self::messageForm($pk, 'note', 'Agregar nota interna', 'Sólo será visible para el equipo interno.', 'observacion', 'Escribe una nota interna…', 'Guardar nota'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('seguimiento')): ?>
          <?php echo self::messageForm($pk, 'follow_up', 'Registrar seguimiento', 'Deja constancia de la gestión comercial realizada.', 'observacion', 'Describe el seguimiento…', 'Guardar seguimiento', true, false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('postergar')): ?>
          <?php echo self::messageForm($pk, 'postpone', 'Postergar tarea', 'La tarea pasará al estado comercial Postergado.', 'observacion', 'Indica el motivo de la postergación…', 'Postergar tarea', true, true, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('activar')): ?>
          <?php echo self::statusMessageForm($pk, 'activate', 'Activar tarea', 'Selecciona el estado con el que retoma la gestión.', 'motivo', CommercialStatusCatalog::OPEN, 'Nuevo', 'Activar tarea', false, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cerrar')): ?>
          <?php echo self::statusMessageForm($pk, 'close', 'Cerrar tarea', 'Elige el resultado final y registra el motivo.', 'observacion', CommercialStatusCatalog::CLOSED, 'Finalizado', 'Cerrar tarea', true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cambiar_estado')): ?>
          <?php echo self::selectForm($pk, 'status', 'Cambiar estado comercial', 'estado', CommercialStatusCatalog::all(), $status, 'Guardar estado'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('reasignar')): ?>
          <?php echo self::employeeForm($pk, $employees, (string) ($ticket['id_empleado'] ?? '')); ?>
        <?php endif; ?>
      </div>

      <!-- Historial de la Tarea (Timeline) -->
      <section class="bg-surface-container-low p-space-lg rounded-2xl space-y-space-md" aria-labelledby="commercial-timeline-title">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-xs pb-space-sm border-b border-surface-container">
          <div>
            <span class="font-label-sm text-secondary uppercase font-semibold">Trazabilidad</span>
            <h3 id="commercial-timeline-title" class="font-headline-sm text-headline-sm text-on-surface">Historial de la tarea</h3>
          </div>
          <div class="flex items-center gap-1.5 font-label-sm text-label-sm">
            <span class="px-2.5 py-0.5 rounded-full bg-surface-container font-semibold"><?php echo esc_html((string) count($timeline)); ?> registros</span>
            <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest text-secondary"><?php echo esc_html((string) $timelineCounts['respuesta']); ?> respuestas</span>
            <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest text-secondary"><?php echo esc_html((string) $timelineCounts['seguimiento']); ?> seguimientos</span>
            <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest text-secondary"><?php echo esc_html((string) $timelineCounts['nota']); ?> notas</span>
          </div>
        </div>

        <?php if ($timeline === []): ?>
          <div class="py-12 text-center text-secondary">
            <span class="material-symbols-outlined text-[36px] text-secondary/40 block mb-1">chat</span>
            <p class="font-body-md text-on-surface">Aún no hay respuestas, seguimientos o notas registradas para esta tarea.</p>
          </div>
        <?php else: ?>
          <ol class="space-y-space-md">
            <?php foreach ($timeline as $item):
              $type = (string) ($item['type'] ?? 'respuesta');
              $labels = ['respuesta' => 'Respuesta al cliente', 'seguimiento' => 'Seguimiento', 'nota' => 'Nota interna'];
              $author = trim((string) ($item['nombre'] ?? '')) ?: 'Sistema';
              $actorId = trim((string) ($item['actor_id'] ?? ''));
              $actorEmail = trim((string) ($item['actor_email'] ?? ''));

              $icon = 'chat';
              $bubbleTone = 'bg-surface-container-lowest border border-surface-container';
              if ($type === 'respuesta') {
                $icon = 'reply';
                $bubbleTone = 'bg-primary-container/20 border border-primary-container/40';
              } elseif ($type === 'nota') {
                $icon = 'lock';
                $bubbleTone = 'bg-tertiary-fixed/30 border border-tertiary-fixed';
              }
            ?>
              <li class="p-space-md rounded-2xl <?php echo $bubbleTone; ?> space-y-space-xs">
                <header class="flex items-center justify-between font-label-md text-label-md">
                  <span class="font-semibold text-on-surface flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-secondary"><?php echo $icon; ?></span>
                    <?php echo esc_html(($labels[$type] ?? 'Actividad') . ' · ' . $author); ?>
                  </span>
                  <time class="text-secondary font-body-sm text-[12px]"><?php echo esc_html(self::formatDate($item['_timestamp'] ?? $item['fecha'] ?? 0)); ?></time>
                </header>
                <p class="font-body-md text-body-md text-on-surface whitespace-pre-wrap"><?php echo nl2br(esc_html(trim(wp_strip_all_tags((string) ($item['message'] ?? ''), true)))); ?></p>
                <?php echo self::timelineAttachments($item); ?>
                <div class="text-[11px] text-secondary font-medium pt-1 border-t border-surface-container/60"><?php echo esc_html(self::actorMeta($actorId, $actorEmail)); ?></div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>
    </section>
  </div>

  <!-- Modal Interno de Análisis IA -->
  <div class="fixed inset-0 z-50 items-center justify-center p-4 bg-inverse-surface/60 backdrop-blur-sm commercial-analysis-modal" data-commercial-analysis-modal role="dialog" aria-modal="true" aria-labelledby="commercial-analysis-title" hidden>
    <div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] overflow-hidden flex flex-col relative p-space-lg" role="document">
      <div data-commercial-analysis-modal-content></div>
    </div>
  </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $analyses */
  private static function renderAnalysesList(int $pk, array $analyses): string
  {
    ob_start();
?>
    <section class="bg-surface-container-low p-space-md rounded-2xl space-y-space-xs" data-commercial-saved-analyses data-ticket-pk="<?php echo esc_attr((string) $pk); ?>">
      <div class="flex items-center justify-between pb-1 border-b border-surface-container">
        <div>
          <span class="font-label-sm text-secondary uppercase font-semibold">Inteligencia</span>
          <h3 class="font-headline-sm text-[16px] text-on-surface font-semibold">Análisis guardados</h3>
        </div>
        <strong class="font-label-sm text-label-sm bg-surface-container px-2 py-0.5 rounded-full" data-commercial-analysis-count><?php echo esc_html((string) count($analyses)); ?>/3</strong>
      </div>
      <div class="space-y-1.5 pt-1" data-commercial-analysis-list>
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
      return '<div class="py-4 text-center text-secondary font-body-sm"><p>Aún no hay análisis guardados.</p></div>';
    }

    $html = '<div class="space-y-2">';
    foreach ($analyses as $analysis) {
      $id = (int) ($analysis['id'] ?? 0);
      $summary = trim((string) ($analysis['resumen'] ?? 'Análisis guardado'));
      $label = trim((string) ($analysis['created_label'] ?? $analysis['generated_at'] ?? 'Sin fecha'));
      $author = trim((string) ($analysis['created_by'] ?? 'Sistema'));
      $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

      $html .= '<div class="flex items-center justify-between p-2 bg-surface-container-lowest rounded-xl border border-surface-container text-body-sm">'
        . '<button type="button" class="flex-1 text-left cursor-pointer" data-commercial-open-analysis data-analysis-json="' . esc_attr(is_string($json) ? $json : '{}') . '">'
        . '<strong class="block font-label-md text-on-surface">' . esc_html($label) . '</strong>'
        . '<span class="block text-secondary text-[12px] line-clamp-1">' . esc_html(mb_strimwidth($summary, 0, 70, '…', 'UTF-8')) . '</span>'
        . '<small class="text-[11px] text-secondary">' . esc_html($author) . '</small>'
        . '</button>'
        . '<button type="button" class="p-1 text-secondary hover:text-error cursor-pointer" data-commercial-delete-analysis data-ticket-pk="' . esc_attr((string) $pk) . '" data-analysis-id="' . esc_attr((string) $id) . '" title="Eliminar análisis"><span class="material-symbols-outlined text-[18px]">delete</span></button>'
        . '</div>';
    }
    $html .= '</div>';
    return $html;
  }

  private static function detailRow(string $label, string $value, string $icon): string
  {
    return '<div class="flex items-start justify-between gap-2 py-1 border-b border-surface-container/40 last:border-none">'
      . '<dt class="text-secondary font-medium flex items-center gap-1.5"><span class="material-symbols-outlined text-[16px]">' . esc_attr($icon) . '</span><span>' . esc_html($label) . '</span></dt>'
      . '<dd class="text-on-surface font-semibold text-right">' . esc_html($value) . '</dd>'
      . '</div>';
  }

  /** @param array<string,mixed> $ticket */
  private static function propertyRow(array $ticket): string
  {
    $property = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    $code = trim((string) ($ticket['id_inmueble'] ?? $ticket['inmueble'] ?? $property['codigo'] ?? ''));
    if ($code === '') {
      $code = trim((string) ($property['codigo'] ?? ''));
    }
    $type = trim((string) ($ticket['tipo_inmueble'] ?? $property['tipo_inmueble'] ?? ''));
    $neighborhood = trim((string) ($ticket['barrio'] ?? $property['barrio'] ?? ''));
    $city = trim((string) ($property['ciudad'] ?? ''));
    $address = trim((string) ($ticket['direccion'] ?? $property['direccion'] ?? ''));
    $url = trim((string) ($ticket['_scm_inmueble_url'] ?? ''));

    $parts = array_filter([$type, $neighborhood, $city], static fn(string $value): bool => trim($value) !== '');
    if ($code === '' && $parts === [] && $address === '') {
      return self::detailRow('Inmueble', 'No registrado', 'apartment');
    }

    $mainText = $code !== '' ? ('Código ' . $code) : 'Inmueble registrado';
    $subText = implode(' · ', $parts);

    ob_start();
?>
    <div class="flex flex-col py-1 border-b border-surface-container/40">
      <dt class="text-secondary font-medium flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[16px]">apartment</span>
        <span>Inmueble</span>
      </dt>
      <dd class="mt-0.5">
        <span class="block font-semibold text-on-surface"><?php echo esc_html($mainText); ?></span>
        <?php if ($subText !== ''): ?><span class="block text-[12px] text-secondary"><?php echo esc_html($subText); ?></span><?php endif; ?>
        <?php if ($address !== ''): ?><span class="block text-[12px] text-secondary"><?php echo esc_html($address); ?></span><?php endif; ?>
        <?php if ($url !== ''): ?><a class="text-primary hover:underline text-[12px] font-semibold mt-0.5 inline-block" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">Abrir ficha técnica</a><?php endif; ?>
      </dd>
    </div>
<?php
    return (string) ob_get_clean();
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
    <form class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm border border-surface-container space-y-space-sm commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
        <div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface"><?php echo esc_html($title); ?></h3>
          <p class="font-body-sm text-body-sm text-on-surface-variant"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container text-secondary flex items-center justify-center cursor-pointer" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block font-label-sm text-secondary font-medium">Mensaje <em class="text-error">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="4" required placeholder="<?php echo esc_attr($placeholder); ?>" class="w-full p-space-sm bg-surface-container-low rounded-xl font-body-sm outline-none border border-transparent focus:border-outline-variant"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>
      <?php if ($notify): ?>
        <label class="flex items-center gap-2 cursor-pointer font-body-sm text-on-surface">
          <input type="checkbox" name="notificar_solicitante" value="1"<?php echo $action === 'reply' ? ' checked' : ''; ?> class="rounded text-primary">
          <span>Notificar por correo al solicitante</span>
        </label>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-space-sm pt-space-xs">
        <span class="font-body-sm text-error mr-auto" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-space-lg py-2 bg-inverse-surface hover:bg-secondary text-on-secondary rounded-xl font-label-md font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
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
    <form class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm border border-surface-container space-y-space-sm commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
        <div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface"><?php echo esc_html($title); ?></h3>
          <p class="font-body-sm text-body-sm text-on-surface-variant"><?php echo esc_html($help); ?></p>
        </div>
        <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container text-secondary flex items-center justify-center cursor-pointer" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block font-label-sm text-secondary font-medium">Estado comercial <em class="text-error">*</em></label>
        <select name="estado" required class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
          <?php foreach ($statuses as $st): ?>
            <option value="<?php echo esc_attr($st); ?>"<?php selected($selected, $st); ?>><?php echo esc_html($st); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="space-y-1">
        <label class="block font-label-sm text-secondary font-medium">Motivo <em class="text-error">*</em></label>
        <textarea name="<?php echo esc_attr($field); ?>" rows="3" required placeholder="Describe el motivo de esta acción…" class="w-full p-space-sm bg-surface-container-low rounded-xl font-body-sm outline-none border border-transparent focus:border-outline-variant"></textarea>
      </div>
      <?php if ($attachments): ?>
        <?php echo self::attachmentFields(); ?>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-space-sm pt-space-xs">
        <span class="font-body-sm text-error mr-auto" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-space-lg py-2 <?php echo $danger ? 'bg-error text-on-error' : 'bg-inverse-surface hover:bg-secondary text-on-secondary'; ?> rounded-xl font-label-md font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
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
    <div class="bg-surface-container-low p-space-sm rounded-xl space-y-2 border border-surface-container">
      <span class="block font-label-sm font-semibold text-secondary">Soportes y Evidencias Opcionales</span>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <label class="block space-y-1">
          <span class="block text-[11px] text-secondary">Subir archivo</span>
          <input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple class="block w-full text-[12px] text-secondary file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:bg-surface-container file:text-on-surface file:font-semibold">
        </label>
        <div class="p-2 bg-surface-container-lowest rounded-lg border border-dashed border-secondary/40 text-center cursor-pointer scm-paste-evidence" tabindex="0" role="button" data-scm-paste-evidence data-file-input-name="evidencia[]">
          <span class="block font-label-sm font-semibold text-primary">Pegar captura (Ctrl+V)</span>
          <span class="block text-[10px] text-secondary">Haz clic y presiona Ctrl+V</span>
          <ul data-scm-paste-list class="text-[11px] text-left"></ul>
        </div>
      </div>
      <div class="scm-ticket-documents-zone" data-ticket-documents-zone>
        <div class="scm-ticket-documents" data-ticket-documents></div>
        <button type="button" class="mt-1 px-3 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm font-semibold inline-flex items-center gap-1 cursor-pointer" data-add-ticket-document data-document-accept="<?php echo esc_attr($docAccept); ?>">
          <span class="material-symbols-outlined text-[16px]">attach_file</span>
          <span>Agregar documento</span>
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

    $html = '<div class="space-y-2 pt-1">';
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
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm font-semibold transition-colors"><span class="material-symbols-outlined text-[16px]">description</span>' . esc_html($label) . '</a>';
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
    <form class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm border border-surface-container space-y-space-sm commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
        <div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface"><?php echo esc_html($title); ?></h3>
          <p class="font-body-sm text-body-sm text-on-surface-variant">Actualiza la etapa del embudo comercial.</p>
        </div>
        <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container text-secondary flex items-center justify-center cursor-pointer" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block font-label-sm text-secondary font-medium">Nuevo estado <em class="text-error">*</em></label>
        <select name="<?php echo esc_attr($field); ?>" required class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
          <?php foreach ($options as $option): ?>
            <option value="<?php echo esc_attr($option); ?>"<?php selected($selected, $option); ?>><?php echo esc_html($option); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-center justify-end gap-space-sm pt-space-xs">
        <span class="font-body-sm text-error mr-auto" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-space-lg py-2 bg-inverse-surface hover:bg-secondary text-on-secondary rounded-xl font-label-md font-semibold transition-all shadow-sm cursor-pointer"><?php echo esc_html($submit); ?></button>
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
    <form class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm border border-surface-container space-y-space-sm commercial-workflow-form" data-commercial-workflow-form="reassign" hidden>
      <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
        <div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface">Reasignar responsable</h3>
          <p class="font-body-sm text-body-sm text-on-surface-variant">Selecciona un integrante habilitado del equipo comercial.</p>
        </div>
        <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container text-secondary flex items-center justify-center cursor-pointer" data-commercial-close-workflow aria-label="Cerrar formulario">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
      </div>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <div class="space-y-1">
        <label class="block font-label-sm text-secondary font-medium">Nuevo responsable <em class="text-error">*</em></label>
        <select name="id_empleado" required class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
          <option value="">Selecciona un funcionario</option>
          <?php foreach ($employees as $employee):
            $id = (string) ($employee['id'] ?? $employee['id_empleado'] ?? '');
          ?>
            <option value="<?php echo esc_attr($id); ?>"<?php selected($selected, $id); ?>><?php echo esc_html((string) ($employee['nombre'] ?? 'Funcionario')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-center justify-end gap-space-sm pt-space-xs">
        <span class="font-body-sm text-error mr-auto" data-commercial-form-message aria-live="polite"></span>
        <button type="button" class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer" data-commercial-close-workflow>Cancelar</button>
        <button type="submit" class="px-space-lg py-2 bg-inverse-surface hover:bg-secondary text-on-secondary rounded-xl font-label-md font-semibold transition-all shadow-sm cursor-pointer">Guardar responsable</button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
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
}
