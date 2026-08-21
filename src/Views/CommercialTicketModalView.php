<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialStatusCatalog;

final class CommercialTicketModalView
{
  /**
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>} $detail
   * @param array<int,array<string,string>> $employees
   */
  public static function render(array $detail, CommercialAccessPolicy $policy, array $employees, string $ticketUrl = ''): string
  {
    $ticket = $detail['ticket'];
    $timeline = $detail['timeline'];
    $pk = (int) ($ticket['_ID'] ?? 0);
    $logicalId = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $pk;
    $status = trim((string) ($ticket['estado_comercial'] ?? '')) ?: 'Sin estado';
    $subject = trim((string) ($ticket['asunto'] ?? '')) ?: 'Ticket comercial';
    $description = trim(wp_strip_all_tags((string) ($ticket['descripcion'] ?? ''), true));
    $requester = trim((string) ($ticket['solicitante'] ?? '')) ?: 'Sin solicitante';
    $assignee = trim((string) ($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '')) ?: 'Sin asignar';
    $external = $ticketUrl !== '' ? $ticketUrl . rawurlencode($logicalId) : '';
    $bucket = CommercialStatusCatalog::bucketForStatus($status);
    $timelineCounts = self::timelineCounts($timeline);

    ob_start();
?>
  <div class="commercial-case-headline">
    <div>
      <div class="commercial-case-eyebrow"><span>Ticket #<?php echo esc_html($logicalId); ?></span><span class="commercial-status-badge commercial-status-badge--<?php echo esc_attr($bucket); ?>"><?php echo esc_html($status); ?></span></div>
      <h2 id="commercial-case-title"><?php echo esc_html($subject); ?></h2>
      <p><?php echo esc_html($description !== '' ? $description : 'Este ticket no tiene una descripción registrada.'); ?></p>
    </div>
    <?php if ($external !== ''): ?><a class="commercial-secondary-btn commercial-external-link" href="<?php echo esc_url($external); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Ver en portal</a><?php endif; ?>
  </div>

  <div class="commercial-case-layout">
    <aside class="commercial-case-sidebar" aria-label="Resumen del ticket">
      <section class="commercial-case-actions" aria-labelledby="commercial-case-actions-title">
        <div class="commercial-case-section-title"><div><span>Gestión</span><h3 id="commercial-case-actions-title">Acciones del caso</h3></div></div>
        <div class="commercial-case-action-grid">
          <?php if ($policy->canAct('responder')): ?><button type="button" data-commercial-open-workflow="reply"><i class="fas fa-reply" aria-hidden="true"></i><span>Responder</span></button><?php endif; ?>
          <?php if ($policy->canAct('agregar_nota')): ?><button type="button" data-commercial-open-workflow="note"><i class="fas fa-note-sticky" aria-hidden="true"></i><span>Nota interna</span></button><?php endif; ?>
          <?php if ($policy->canAct('seguimiento')): ?><button type="button" data-commercial-open-workflow="follow_up"><i class="fas fa-list-check" aria-hidden="true"></i><span>Seguimiento</span></button><?php endif; ?>
          <?php if ($policy->canAct('postergar')): ?><button type="button" data-commercial-open-workflow="postpone"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Postergar</span></button><?php endif; ?>
          <?php if ($policy->canAct('activar')): ?><button type="button" data-commercial-open-workflow="activate"><i class="fas fa-circle-play" aria-hidden="true"></i><span>Activar</span></button><?php endif; ?>
          <?php if ($policy->canAct('cerrar')): ?><button type="button" class="commercial-case-action--danger" data-commercial-open-workflow="close"><i class="fas fa-circle-check" aria-hidden="true"></i><span>Cerrar</span></button><?php endif; ?>
          <?php if ($policy->canAct('cambiar_estado')): ?><button type="button" data-commercial-open-workflow="status"><i class="fas fa-arrow-right-arrow-left" aria-hidden="true"></i><span>Cambiar estado</span></button><?php endif; ?>
          <?php if ($policy->canAct('reasignar')): ?><button type="button" data-commercial-open-workflow="reassign"><i class="fas fa-user-pen" aria-hidden="true"></i><span>Reasignar</span></button><?php endif; ?>
        </div>
      </section>

      <section class="commercial-case-summary">
        <h3>Resumen</h3>
        <dl>
          <?php echo self::detailRow('Solicitante', $requester, 'fa-user'); ?>
          <?php echo self::detailRow('Correo', trim((string) ($ticket['correo_solicitante'] ?? '')) ?: 'No registrado', 'fa-envelope'); ?>
          <?php echo self::detailRow('Celular', trim((string) ($ticket['celular_solicitante'] ?? '')) ?: 'No registrado', 'fa-phone'); ?>
          <?php echo self::detailRow('Responsable', $assignee, 'fa-user-tie'); ?>
          <?php echo self::propertyRow($ticket); ?>
          <?php echo self::detailRow('Prioridad', trim((string) ($ticket['prioridad'] ?? '')) ?: 'No definida', 'fa-flag'); ?>
          <?php echo self::detailRow('Medio', trim((string) ($ticket['medio'] ?? '')) ?: 'No registrado', 'fa-message'); ?>
          <?php echo self::detailRow('Actualizado', self::formatDate($ticket['fecha_actualizacion'] ?? $ticket['fecha'] ?? 0), 'fa-clock'); ?>
        </dl>
      </section>
    </aside>

    <section class="commercial-case-main">
      <div class="commercial-workflow-stack" data-commercial-workflow-stack hidden>
        <?php if ($policy->canAct('responder')): ?>
          <?php echo self::messageForm($pk, 'reply', 'Responder al solicitante', 'Escribe una respuesta clara para el cliente.', 'respuesta', 'Escribe la respuesta del ticket…', 'Enviar respuesta', true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('agregar_nota')): ?>
          <?php echo self::messageForm($pk, 'note', 'Agregar nota interna', 'Sólo será visible para el equipo.', 'observacion', 'Escribe una nota interna…', 'Guardar nota'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('seguimiento')): ?>
          <?php echo self::messageForm($pk, 'follow_up', 'Registrar seguimiento', 'Deja constancia de la gestión realizada.', 'observacion', 'Describe el seguimiento…', 'Guardar seguimiento', true, false); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('postergar')): ?>
          <?php echo self::messageForm($pk, 'postpone', 'Postergar ticket', 'El caso pasará al estado comercial Postergado.', 'observacion', 'Indica el motivo de la postergación…', 'Postergar ticket', true, true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('activar')): ?>
          <?php echo self::statusMessageForm($pk, 'activate', 'Activar ticket', 'Selecciona el estado con el que retoma la gestión.', 'motivo', CommercialStatusCatalog::OPEN, 'Nuevo', 'Activar ticket'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cerrar')): ?>
          <?php echo self::statusMessageForm($pk, 'close', 'Cerrar ticket', 'Elige el resultado final y registra el motivo.', 'observacion', CommercialStatusCatalog::CLOSED, 'Finalizado', 'Cerrar ticket', true); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('cambiar_estado')): ?>
          <?php echo self::selectForm($pk, 'status', 'Cambiar estado comercial', 'estado', CommercialStatusCatalog::all(), $status, 'Guardar estado'); ?>
        <?php endif; ?>
        <?php if ($policy->canAct('reasignar')): ?>
          <?php echo self::employeeForm($pk, $employees, (string) ($ticket['id_empleado'] ?? '')); ?>
        <?php endif; ?>
      </div>

      <section class="commercial-timeline" aria-labelledby="commercial-timeline-title">
        <div class="commercial-case-section-title commercial-case-section-title--stacked">
          <div><span>Actividad</span><h3 id="commercial-timeline-title">Historial del caso</h3></div>
          <div class="commercial-timeline-counts" aria-label="Resumen del historial">
            <strong><?php echo esc_html((string) count($timeline)); ?> registros</strong>
            <small><?php echo esc_html((string) $timelineCounts['respuesta']); ?> respuestas</small>
            <small><?php echo esc_html((string) $timelineCounts['seguimiento']); ?> seguimientos</small>
            <small><?php echo esc_html((string) $timelineCounts['nota']); ?> notas</small>
          </div>
        </div>
        <?php if ($timeline === []): ?>
          <div class="commercial-timeline-empty"><i class="far fa-comments" aria-hidden="true"></i><p>Aún no hay respuestas, seguimientos o notas para este ticket.</p></div>
        <?php else: ?>
          <ol>
            <?php foreach ($timeline as $item):
              $type = (string) ($item['type'] ?? 'respuesta');
              $labels = ['respuesta' => 'Respuesta', 'seguimiento' => 'Seguimiento', 'nota' => 'Nota interna'];
              $author = trim((string) ($item['nombre'] ?? '')) ?: 'Sistema';
              $actorId = trim((string) ($item['actor_id'] ?? ''));
              $actorEmail = trim((string) ($item['actor_email'] ?? ''));
            ?>
              <li class="commercial-timeline-item commercial-timeline-item--<?php echo esc_attr($type); ?>">
                <span class="commercial-timeline-icon" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr($labels[$type] ?? 'A', 0, 1))); ?></span>
                <div>
                  <header>
                    <span><?php echo esc_html(($labels[$type] ?? 'Actividad') . ' · ' . $author); ?></span>
                    <time><?php echo esc_html(self::formatDate($item['_timestamp'] ?? $item['fecha'] ?? 0)); ?></time>
                  </header>
                  <p><?php echo nl2br(esc_html(trim(wp_strip_all_tags((string) ($item['message'] ?? ''), true)))); ?></p>
                  <small><?php echo esc_html(self::actorMeta($actorId, $actorEmail)); ?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>
    </section>
  </div>
<?php
    return (string) ob_get_clean();
  }

  private static function detailRow(string $label, string $value, string $icon): string
  {
    return '<div><dt><i class="fas ' . esc_attr($icon) . '" aria-hidden="true"></i>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
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
    $html = '<div class="commercial-property-row"><dt><i class="fas fa-location-dot" aria-hidden="true"></i>Inmueble</dt><dd>';
    if ($code === '' && $parts === [] && $address === '') {
      return $html . 'No registrado</dd></div>';
    }

    $html .= '<span class="commercial-property-main">' . esc_html($code !== '' ? ('Código ' . $code) : 'Inmueble registrado') . '</span>';
    if ($parts !== []) {
      $html .= '<span class="commercial-property-meta">' . esc_html(implode(' · ', $parts)) . '</span>';
    }
    if ($address !== '') {
      $html .= '<span class="commercial-property-address">' . esc_html($address) . '</span>';
    }
    if ($url !== '') {
      $html .= '<a class="commercial-property-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Ver inmueble</a>';
    }
    return $html . '</dd></div>';
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

  private static function messageForm(int $pk, string $action, string $title, string $help, string $field, string $placeholder, string $submit, bool $notify = false, bool $danger = false): string
  {
    ob_start();
?>
    <form class="commercial-workflow-form<?php echo $danger ? ' commercial-workflow-form--warning' : ''; ?>" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <header><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($help); ?></p></div><button type="button" data-commercial-close-workflow aria-label="Cerrar formulario">&times;</button></header>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <label><span>Mensaje <em>*</em></span><textarea name="<?php echo esc_attr($field); ?>" rows="5" required placeholder="<?php echo esc_attr($placeholder); ?>"></textarea></label>
      <?php if ($notify): ?><label class="commercial-checkbox"><input type="checkbox" name="notificar_solicitante" value="1"<?php echo $action === 'reply' ? ' checked' : ''; ?>><span>Notificar por correo al solicitante</span></label><?php endif; ?>
      <footer><span class="commercial-form-message" aria-live="polite"></span><button type="button" class="commercial-secondary-btn" data-commercial-close-workflow>Cancelar</button><button type="submit" class="commercial-primary-btn"><?php echo esc_html($submit); ?></button></footer>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $statuses */
  private static function statusMessageForm(int $pk, string $action, string $title, string $help, string $field, array $statuses, string $selected, string $submit, bool $danger = false): string
  {
    ob_start();
?>
    <form class="commercial-workflow-form<?php echo $danger ? ' commercial-workflow-form--danger' : ''; ?>" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <header><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($help); ?></p></div><button type="button" data-commercial-close-workflow aria-label="Cerrar formulario">&times;</button></header>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <label><span>Estado comercial <em>*</em></span><select name="estado" required><?php foreach ($statuses as $status): ?><option value="<?php echo esc_attr($status); ?>"<?php selected($selected, $status); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label>
      <label><span>Motivo <em>*</em></span><textarea name="<?php echo esc_attr($field); ?>" rows="4" required placeholder="Describe el motivo de esta acción…"></textarea></label>
      <footer><span class="commercial-form-message" aria-live="polite"></span><button type="button" class="commercial-secondary-btn" data-commercial-close-workflow>Cancelar</button><button type="submit" class="<?php echo $danger ? 'commercial-danger-btn' : 'commercial-primary-btn'; ?>"><?php echo esc_html($submit); ?></button></footer>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $options */
  private static function selectForm(int $pk, string $action, string $title, string $field, array $options, string $selected, string $submit): string
  {
    ob_start();
?>
    <form class="commercial-workflow-form" data-commercial-workflow-form="<?php echo esc_attr($action); ?>" hidden>
      <header><div><h3><?php echo esc_html($title); ?></h3><p>Actualiza la etapa del embudo comercial.</p></div><button type="button" data-commercial-close-workflow aria-label="Cerrar formulario">&times;</button></header>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <label><span>Nuevo estado <em>*</em></span><select name="<?php echo esc_attr($field); ?>" required><?php foreach ($options as $option): ?><option value="<?php echo esc_attr($option); ?>"<?php selected($selected, $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?></select></label>
      <footer><span class="commercial-form-message" aria-live="polite"></span><button type="button" class="commercial-secondary-btn" data-commercial-close-workflow>Cancelar</button><button type="submit" class="commercial-primary-btn"><?php echo esc_html($submit); ?></button></footer>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,string>> $employees */
  private static function employeeForm(int $pk, array $employees, string $selected): string
  {
    ob_start();
?>
    <form class="commercial-workflow-form" data-commercial-workflow-form="reassign" hidden>
      <header><div><h3>Reasignar responsable</h3><p>Selecciona un integrante habilitado del equipo comercial.</p></div><button type="button" data-commercial-close-workflow aria-label="Cerrar formulario">&times;</button></header>
      <input type="hidden" name="ticket_pk" value="<?php echo esc_attr((string) $pk); ?>">
      <label><span>Nuevo responsable <em>*</em></span><select name="id_empleado" required><option value="">Selecciona un funcionario</option><?php foreach ($employees as $employee): $id = (string) ($employee['id'] ?? $employee['id_empleado'] ?? ''); ?><option value="<?php echo esc_attr($id); ?>"<?php selected($selected, $id); ?>><?php echo esc_html((string) ($employee['nombre'] ?? 'Funcionario')); ?></option><?php endforeach; ?></select></label>
      <footer><span class="commercial-form-message" aria-live="polite"></span><button type="button" class="commercial-secondary-btn" data-commercial-close-workflow>Cancelar</button><button type="submit" class="commercial-primary-btn">Guardar responsable</button></footer>
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
