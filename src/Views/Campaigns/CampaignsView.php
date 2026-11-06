<?php

declare(strict_types=1);

namespace SCM\Views\Campaigns;

use SCM\Commercial\Campaigns\CampaignEngine;
use SCM\Commercial\Campaigns\CampaignRecipients;
use SCM\Commercial\Campaigns\CampaignTemplates;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialSmsMessage;
use SCM\Commercial\Tools\UtmCatalog;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\Promotion\PromotionUi;
use SCM\Views\Promotion\PromotionView;

/**
 * Notificaciones › Campañas y Plantillas de correo (subpestañas de SUBVIEWS['notificaciones']). Adaptación de
 * dashboard-marketing views/campaigns.php, templates.php y template-editor.php con el diseño de Promoción (tarjetas,
 * indicadores y ventanas internas). Enviar y la vista previa llaman a commercial_campaign_*; los botones solo salen con
 * campanas_enviar o plantillas_editar.
 */
final class CampaignsView
{
  public const ASSET_JS = '/assets/js/commercial-campaigns.js';

  /** HTML de la subpestaña, o '' si es la de enviar notificaciones (la pinta CommercialNotificationsView). @param array<string,mixed> $input */
  public static function page(string $sub, array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, string $baseUrl): string
  {
    if ($sub === 'campanas' && $policy->canSubview('notificaciones', 'campanas')) {
      return self::wrap('campanas', self::campaigns($policy, $db, $settings, $baseUrl), $baseUrl);
    }
    if ($sub === 'plantillas_correo' && $policy->canSubview('notificaciones', 'plantillas_correo')) {
      return self::wrap('plantillas_correo', EmailTemplatesView::render(new CampaignTemplates($db), $policy, $baseUrl, !empty($input['tpl_archivadas'])), $baseUrl);
    }
    return '';
  }

  private static function wrap(string $sub, string $body, string $baseUrl): string
  {
    // Mismos estilos que Promoción (commercial-promotion.css: .cpr, tarjetas y <dialog>); su propio script.
    return '<link rel="stylesheet" href="' . PromotionUi::e(PromotionView::assetUrl($baseUrl, PromotionView::ASSET_CSS)) . '" data-commercial-module-style="promocion">'
      . '<section class="cpr" data-commercial-campaigns="' . PromotionUi::e($sub) . '" data-commercial-module-script="' . PromotionUi::e(PromotionView::assetUrl($baseUrl, self::ASSET_JS)) . '">' . $body . '</section>';
  }

  private static function campaigns(CommercialAccessPolicy $policy, Database $db, Settings $settings, string $baseUrl): string
  {
    $recipients = new CampaignRecipients($db, $policy);
    $engine = new CampaignEngine($db, $recipients, $baseUrl);
    try {
      $campaigns = $engine->list();
      $error = '';
    } catch (\Throwable $exception) {
      error_log('[commercial-campaigns] ' . $exception->getMessage());
      $campaigns = [];
      $error = 'No se pudieron leer las campañas.';
    }
    $canSend = $policy->canAct('campanas_enviar');
    $e = static fn($value): string => PromotionUi::e($value);
    $html = PromotionUi::hero('campaign', 'Campañas', 'Envíos masivos por correo, SMS o WhatsApp a listas completas, programados por lotes, con su informe de entregas, aperturas y visitas al sitio.', 'Notificaciones');
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    if ($error !== '') {
      $html .= '<p class="cpr-alert is-error">' . $e($error) . '</p>';
    }
    $sum = static fn(string $key): int => array_sum(array_map(static fn(array $row): int => (int) $row[$key], $campaigns));
    $tracked = $sum('tracked');
    $html .= '<div class="cpr-stats">'
      . PromotionUi::stat('Campañas', PromotionUi::number(count($campaigns)), 'Incluye las del panel de marketing', 'campaign')
      . PromotionUi::stat('Mensajes enviados', PromotionUi::number($sum('sent')), PromotionUi::number($sum('failed')) . ' fallidos', 'send')
      . PromotionUi::stat('En cola', PromotionUi::number($sum('pending')), 'Pendientes o en pausa', 'schedule_send')
      . PromotionUi::stat('Correos abiertos', $tracked > 0 ? PromotionUi::percent($sum('opened') * 100 / $tracked) : '—', PromotionUi::number($sum('opened')) . ' de ' . PromotionUi::number($tracked), 'drafts')
      . '</div>';
    if ($canSend) {
      $html .= '<div class="cpr-toolbar"><span></span><div class="cpr-toolbar__actions"><button type="button" class="cpr-primary" data-camp-open="new"><span class="material-symbols-outlined" aria-hidden="true">add</span>Nueva campaña</button></div></div>';
    }
    $rows = '';
    $statuses = ['active' => ['ok', 'Activa'], 'paused' => ['low', 'En pausa'], 'cancelled' => ['out', 'Cancelada']];
    foreach ($campaigns as $row) {
      [$state, $label] = $statuses[$row['status']] ?? ['ok', 'Activa'];
      if ($row['pending'] === 0 && $row['status'] === 'active' && $row['total'] > 0) {
        [$state, $label] = ['archived', 'Terminada'];
      }
      $channels = implode('', array_map(static fn(string $channel): string => '<span class="cpr-chip">' . PromotionUi::e(CampaignEngine::CHANNELS[$channel] ?? $channel) . '</span>', $row['channels']));
      $rows .= '<tr data-camp-row="' . $e($row['tag']) . '"><td><span class="cpr-cell-main">' . $e($row['tag']) . '</span>' . $channels . ($row['legacy'] ? '<span class="cpr-chip is-warn">Panel anterior</span>' : '') . '</td>'
        . '<td><span class="cpr-chip is-' . $state . '">' . $label . '</span></td>'
        . '<td class="is-num">' . $e(PromotionUi::number($row['sent'])) . ' / ' . $e(PromotionUi::number($row['total'])) . '</td>'
        . '<td class="is-num">' . $e(PromotionUi::number($row['failed'])) . '</td><td class="is-num">' . $e(PromotionUi::number($row['pending'])) . '</td>'
        . '<td class="is-num">' . ($row['tracked'] > 0 ? $e(PromotionUi::percent($row['opened'] * 100 / $row['tracked'])) : '—') . '</td>'
        . '<td>' . $e(PromotionUi::day($row['last_at'], true)) . '</td>'
        . '<td><button type="button" class="cpr-ghost is-small" data-camp-detail="' . $e($row['tag']) . '">Ver informe</button></td></tr>';
    }
    $html .= '<section class="cpr-card" data-camp-list><div class="cpr-card__head"><div><h2>Campañas</h2><p>De la más reciente a la más antigua. Las horas son de envío programado en la cola compartida.</p></div></div>'
      . ($rows === '' ? '<p class="cpr-empty">Todavía no hay campañas.</p>' : '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Campaña</th><th>Estado</th><th class="is-num">Enviados</th><th class="is-num">Fallidos</th><th class="is-num">En cola</th><th class="is-num">Abiertos</th><th>Última actividad</th><th><span class="sr-only">Acción</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
      . '</section>';
    $html .= '<dialog class="cpr-dialog is-wide" data-camp-dialog="detail" data-can-send="' . ($canSend ? '1' : '0') . '" data-can-delete="' . ($canSend && $policy->canAct('campanas_eliminar') ? '1' : '0') . '"><div class="cpr-dialog__head"><h2 data-camp-detail-title>Informe</h2><button type="button" class="cpr-ghost is-small" data-camp-close>Cerrar</button></div><div class="cpr-camp-detail" data-camp-detail-body></div></dialog>';
    if ($canSend) {
      $html .= self::newCampaignDialog($recipients, $db, $settings, array_column($campaigns, 'tag'));
      $html .= '<dialog class="cpr-dialog" data-camp-dialog="rename"><form method="dialog" data-camp-rename-form><div class="cpr-dialog__head"><h2>Renombrar campaña</h2><button type="button" class="cpr-ghost is-small" data-camp-close>Cerrar</button></div>'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Nombre nuevo *</span><input name="new_tag" required maxlength="120" autocomplete="off"><span class="cpr-help">El historial y los mensajes en cola siguen con la campaña.</span></label>'
        . '<p class="cpr-alert is-error" data-camp-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-camp-close>Cancelar</button><button type="submit" class="cpr-primary">Guardar</button></div></form></dialog>';
      $html .= '<dialog class="cpr-dialog" data-camp-dialog="confirm"><form method="dialog" data-camp-confirm-form><div class="cpr-dialog__head"><h2 data-camp-confirm-title></h2><button type="button" class="cpr-ghost is-small" data-camp-close>Cerrar</button></div>'
        . '<p class="cpr-dialog__lead" data-camp-confirm-lead></p><label class="cpr-field is-wide" data-camp-confirm-reason hidden><span class="cpr-label">Motivo</span><input name="reason" maxlength="255"></label>'
        . '<label class="cpr-check is-wide"><input type="checkbox" name="confirm" value="1" required><span data-camp-confirm-text></span></label><p class="cpr-alert is-error" data-camp-error hidden></p>'
        . '<div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-camp-close>Cancelar</button><button type="submit" class="cpr-primary is-danger">Confirmar</button></div></form></dialog>';
    }
    return $html;
  }

  /** @param list<string> $tags */
  private static function newCampaignDialog(CampaignRecipients $recipients, Database $db, Settings $settings, array $tags): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    $options = static function (array $pairs, string $selected = ''): string {
      $html = '';
      foreach ($pairs as $value => $label) {
        $html .= '<option value="' . PromotionUi::e((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . PromotionUi::e($label) . '</option>';
      }
      return $html;
    };
    $templates = ['0' => 'Mensaje general (saludo, tu mensaje y firma)'];
    try {
      foreach ((new CampaignTemplates($db))->list() as $template) {
        $templates[(string) $template['id']] = $template['nombre'];
      }
    } catch (\Throwable) {
      // Sin la tabla de plantillas queda solo el mensaje general.
    }
    $utm = ['' => 'Sin campaña UTM'];
    foreach (UtmCatalog::read($settings)['campanas'] as $campaign) {
      $utm[$campaign['value']] = $campaign['label'];
    }
    $segments = '';
    foreach ($recipients->typeLabels() as $type => $label) {
      $segments .= '<div class="cpr-camp-segment" data-camp-segment="' . $e($type) . '"><label class="cpr-check"><input type="checkbox" data-camp-segment-on value="' . $e($type) . '"><span>' . $e($label) . '</span></label>'
        . '<input type="search" data-camp-segment-q maxlength="150" placeholder="Filtrar (opcional): ciudad, tipo, nombre..." aria-label="Filtro de ' . $e($label) . '" disabled></div>';
    }
    $datalist = implode('', array_map(static fn(string $tag): string => '<option value="' . PromotionUi::e($tag) . '"></option>', $tags));
    $pace = CampaignEngine::PACE_DEFAULTS;
    return '<dialog class="cpr-dialog is-wide" data-camp-dialog="new"><form method="dialog" data-camp-form novalidate>'
      . '<div class="cpr-dialog__head"><h2>Nueva campaña</h2><button type="button" class="cpr-ghost is-small" data-camp-close>Cerrar</button></div>'
      . '<p class="cpr-dialog__lead">Primero calcula los destinatarios: verás cuántos reciben, por qué se salta al resto y cómo se ve el mensaje. Si usas el nombre de una campaña existente, se suma a ella sin repetir a nadie.</p>'
      . '<div class="cpr-dialog__grid">'
      . '<label class="cpr-field is-wide"><span class="cpr-label">Nombre de la campaña *</span><input name="name" required maxlength="120" list="cpr-camp-tags" autocomplete="off" placeholder="Ej. Revista Esencia noviembre propietarios"></label>'
      . '<label class="cpr-field"><span class="cpr-label">Canal</span><select name="channel" data-camp-channel>' . $options(CampaignEngine::CHANNELS, 'email') . '</select></label>'
      . '<label class="cpr-field" data-camp-category><span class="cpr-label">Tipo de mensaje</span><select name="category">' . $options(CampaignEngine::CATEGORIES, 'info') . '</select><span class="cpr-help">Se respeta lo que cada persona aceptó recibir.</span></label>'
      . '<label class="cpr-field is-wide" data-camp-only="email"><span class="cpr-label">Contenido del correo</span><select name="template_id" data-camp-template>' . $options($templates, '0') . '</select><span class="cpr-help">Las plantillas diseñadas se administran en Notificaciones › Plantillas de correo.</span></label>'
      . '<label class="cpr-field is-wide" data-camp-only="email"><span class="cpr-label">Asunto *</span><input name="subject" maxlength="200" placeholder="Si eliges una plantilla, se usa su asunto"></label>'
      . '<label class="cpr-field is-wide"><span class="cpr-label" data-camp-message-label>Mensaje</span><textarea name="message" rows="5" maxlength="5000" data-camp-message></textarea>'
      . '<span class="cpr-help" data-camp-counter></span><span class="cpr-help">Variables: {{nombre}}, {{correo}}, {{celular}}, {{documento}}, {{ciudad}} y cualquier dato de la lista. En SMS se antepone "' . $e(trim(CommercialSmsMessage::PREFIX)) . '" (máximo 160 caracteres).</span></label>'
      . '<fieldset class="cpr-pop-lines is-wide"><legend>Destinatarios *</legend><div class="cpr-camp-segments">' . $segments . '</div></fieldset>'
      . '<label class="cpr-field"><span class="cpr-label">Mensajes por lote</span><input type="number" name="batch_size" min="1" max="1000" value="' . $pace['batch_size'] . '"></label>'
      . '<label class="cpr-field"><span class="cpr-label">Minutos entre lotes</span><input type="number" name="batch_interval_minutes" min="1" max="1440" value="' . $pace['batch_interval_minutes'] . '"></label>'
      . '<label class="cpr-field"><span class="cpr-label">Máximo por día</span><input type="number" name="max_per_day" min="1" max="20000" value="' . $pace['max_per_day'] . '"><span class="cpr-help">Solo de lunes a sábado, de 7:00 a 19:00.</span></label>'
      . '<label class="cpr-field" data-camp-only="email"><span class="cpr-label">Campaña UTM</span><select name="utm_campaign">' . $options($utm) . '</select><span class="cpr-help">Marca los enlaces al sitio para contar las visitas en la Analítica web.</span></label>'
      . '</div><datalist id="cpr-camp-tags">' . $datalist . '</datalist>'
      . '<div class="cpr-camp-preview" data-camp-preview hidden></div>'
      . '<label class="cpr-check is-wide" data-camp-confirm hidden><input type="checkbox" name="confirm" value="1"><span data-camp-confirm-label></span></label>'
      . '<p class="cpr-alert is-error" data-camp-error hidden></p>'
      . '<div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-camp-close>Cancelar</button>'
      . '<button type="button" class="cpr-ghost" data-camp-action="test" data-camp-only="email">Enviarme una prueba</button>'
      . '<button type="button" class="cpr-ghost" data-camp-action="preview"><span class="material-symbols-outlined" aria-hidden="true">group</span>Calcular destinatarios</button>'
      . '<button type="button" class="cpr-primary" data-camp-action="send" disabled>Programar campaña</button></div></form></dialog>';
  }
}
