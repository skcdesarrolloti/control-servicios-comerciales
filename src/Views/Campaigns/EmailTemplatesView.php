<?php

declare(strict_types=1);

namespace SCM\Views\Campaigns;

use SCM\Commercial\Campaigns\CampaignTemplates;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Views\Promotion\PromotionUi;

/**
 * Notificaciones › Plantillas de correo: lista de los correos diseñados con vista previa (iframe aislado, sin scripts),
 * editor de HTML con vista previa en vivo, duplicar y archivar (se puede reactivar). Los botones salen con
 * plantillas_editar.
 */
final class EmailTemplatesView
{
  public static function render(CampaignTemplates $templates, CommercialAccessPolicy $policy, string $baseUrl, bool $archived): string
  {
    $canEdit = $policy->canAct('plantillas_editar');
    $e = static fn($value): string => PromotionUi::e($value);
    try {
      $list = $templates->list($archived);
      $error = '';
    } catch (\Throwable $exception) {
      error_log('[commercial-email-templates] ' . $exception->getMessage());
      $list = [];
      $error = 'No se pudo leer la tabla de plantillas (wp_jet_cct_plantillas).';
    }
    $html = PromotionUi::hero('draft', 'Plantillas de correo', 'Correos diseñados para las campañas: la revista Esencia Inmobiliaria, anuncios y boletines. Se envían tal cual, con el nombre de cada persona en {{nombre}}; si llevan {{mensaje}}, ahí va el texto que escribas en la campaña.', 'Notificaciones');
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    if ($error !== '') {
      $html .= '<p class="cpr-alert is-error">' . $e($error) . '</p>';
    }
    $toggle = '<a class="cpr-ghost" data-camp-nav href="' . $e(PromotionUi::url($baseUrl, ['tab' => 'plantillas_correo', 'tpl_archivadas' => $archived ? '' : '1'])) . '">' . ($archived ? 'Ocultar archivadas' : 'Ver también las archivadas') . '</a>';
    $html .= '<div class="cpr-toolbar">' . $toggle . ($canEdit ? '<div class="cpr-toolbar__actions"><button type="button" class="cpr-primary" data-tpl-open="new"><span class="material-symbols-outlined" aria-hidden="true">add</span>Nueva plantilla</button></div>' : '') . '</div>';
    $cards = '';
    foreach ($list as $template) {
      $isArchived = $template['estado'] === 'archivada';
      $actions = '<button type="button" class="cpr-ghost is-small" data-tpl-open="view" data-tpl-id="' . $template['id'] . '">Ver</button>';
      if ($canEdit) {
        $actions .= $isArchived
          ? '<button type="button" class="cpr-ghost is-small" data-tpl-op="restore" data-tpl-id="' . $template['id'] . '">Reactivar</button>'
          : '<button type="button" class="cpr-ghost is-small" data-tpl-open="edit" data-tpl-id="' . $template['id'] . '">Editar</button>'
            . '<button type="button" class="cpr-ghost is-small" data-tpl-op="duplicate" data-tpl-id="' . $template['id'] . '">Duplicar</button>'
            . '<button type="button" class="cpr-ghost is-small is-danger" data-tpl-open="archive" data-tpl-id="' . $template['id'] . '" data-tpl-name="' . $e($template['nombre']) . '">Archivar</button>';
      }
      $cards .= '<article class="cpr-pop-card' . ($isArchived ? ' is-archived' : '') . '" data-tpl-card="' . $template['id'] . '"><div class="cpr-pop-card__body">'
        . '<div class="cpr-pop-card__title"><span class="cpr-pop-card__type">' . ($template['documento'] ? 'Correo diseñado' : 'Fragmento HTML') . '</span><h3>' . $e($template['nombre']) . '</h3></div>'
        . ($isArchived ? '<span class="cpr-chip is-archived">Archivada</span>' : '')
        . '<dl class="cpr-pop-meta"><div class="is-wide"><dt>Asunto</dt><dd>' . $e($template['asunto'] !== '' ? $template['asunto'] : 'Sin asunto') . '</dd></div>'
        . '<div><dt>Modificada</dt><dd>' . $e($template['modificada'] !== '' ? PromotionUi::day($template['modificada']) : '—') . '</dd></div>'
        . '<div><dt>Tamaño</dt><dd>' . $e(PromotionUi::number((int) ceil($template['tamano'] / 1024))) . ' KB</dd></div></dl>'
        . '<div class="cpr-pop-card__actions">' . $actions . '</div></div></article>';
    }
    $html .= '<section class="cpr-card" data-tpl-list><div class="cpr-card__head"><div><h2>Plantillas</h2><p>' . count($list) . ($archived ? ' plantillas, también las archivadas' : ' plantillas activas') . '</p></div></div>'
      . ($cards === '' ? '<p class="cpr-empty">No hay plantillas de correo.</p>' : '<div class="cpr-pop-grid">' . $cards . '</div>') . '</section>';
    $html .= '<dialog class="cpr-dialog is-wide" data-tpl-dialog="view"><div class="cpr-dialog__head"><h2 data-tpl-view-title>Vista previa</h2><button type="button" class="cpr-ghost is-small" data-tpl-close>Cerrar</button></div>'
      . '<p class="cpr-dialog__lead" data-tpl-view-subject></p><iframe class="cpr-camp-frame" sandbox="" title="Vista previa del correo" data-tpl-view-frame></iframe></dialog>';
    if ($canEdit) {
      $html .= '<dialog class="cpr-dialog is-full" data-tpl-dialog="edit"><form method="dialog" data-tpl-form novalidate><div class="cpr-dialog__head"><h2 data-tpl-edit-title>Nueva plantilla</h2><button type="button" class="cpr-ghost is-small" data-tpl-close>Cerrar</button></div>'
        . '<input type="hidden" name="id" value="0"><div class="cpr-tpl-editor"><div class="cpr-tpl-editor__fields">'
        . '<label class="cpr-field"><span class="cpr-label">Nombre *</span><input name="nombre" required maxlength="150"></label>'
        . '<label class="cpr-field"><span class="cpr-label">Asunto *</span><input name="asunto" required maxlength="200"></label>'
        . '<label class="cpr-field"><span class="cpr-label">HTML del correo *</span><textarea name="contenido" required rows="18" spellcheck="false" class="cpr-code" data-tpl-html></textarea>'
        . '<span class="cpr-help">Pega el HTML completo (desde &lt;!doctype html&gt;) o un fragmento. Variables: {{nombre}}, {{correo}}, {{ciudad}}... y {{mensaje}} si quieres un espacio para el texto de la campaña. Los scripts y formularios se quitan al guardar.</span></label>'
        . '</div><div class="cpr-tpl-editor__preview"><span class="cpr-label">Vista previa</span><iframe class="cpr-camp-frame" sandbox="" title="Vista previa en vivo" data-tpl-live></iframe></div></div>'
        . '<p class="cpr-alert is-error" data-tpl-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-tpl-close>Cancelar</button><button type="submit" class="cpr-primary">Guardar plantilla</button></div></form></dialog>';
      $html .= '<dialog class="cpr-dialog" data-tpl-dialog="archive"><form method="dialog" data-tpl-archive-form><div class="cpr-dialog__head"><h2>Archivar plantilla</h2><button type="button" class="cpr-ghost is-small" data-tpl-close>Cerrar</button></div>'
        . '<input type="hidden" name="id"><p class="cpr-dialog__lead" data-tpl-archive-name></p><label class="cpr-check is-wide"><input type="checkbox" name="confirm" value="1" required><span>Entiendo que deja de ofrecerse en las campañas. Las campañas ya enviadas no cambian y la puedo reactivar.</span></label>'
        . '<p class="cpr-alert is-error" data-tpl-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-tpl-close>Cancelar</button><button type="submit" class="cpr-primary is-danger">Archivar</button></div></form></dialog>';
    }
    return $html;
  }
}
