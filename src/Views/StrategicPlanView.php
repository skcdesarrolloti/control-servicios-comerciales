<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Strategy\StrategicPlanRepository;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Views\Promotion\PromotionView;

/**
 * Herramientas › Plan estratégico (?tab=plan_estrategico). Adaptación del panel del plugin "Gestor Estratégico" V30.9 de
 * WordPress con el diseño de las herramientas: indicadores, pestañas Mis tareas / Objetivos / Metas / Tareas / Campañas
 * con sus filtros, y popups internos para crear, editar, eliminar y llevar las bitácoras. Las listas las pinta
 * commercial-strategy.js con commercial_plan_list; sus estilos y su script se piden solo al abrir esta herramienta.
 * Los catálogos (gestiones, unidades, tipos, frecuencias y actores) se editan en Configuración › Plan estratégico.
 */
final class StrategicPlanView
{
  public const ASSET_CSS = '/assets/css/commercial-strategy.css';
  public const ASSET_JS = '/assets/js/commercial-strategy.js';

  /**
   * @return array{can_edit:bool,user:string,options:array<string,mixed>,summary:array<string,int>}
   */
  public static function context(CommercialAccessPolicy $policy, Database $db): array
  {
    $repository = new StrategicPlanRepository($db);
    return [
      'can_edit' => $policy->canAct('plan_estrategico_editar'),
      'user' => Auth::user(),
      'options' => $repository->options(),
      'summary' => $repository->summary(Auth::user()),
    ];
  }

  /** @param array{can_edit?:bool,user?:string,options?:array<string,mixed>,summary?:array<string,int>} $context */
  public static function render(array $context, string $baseUrl): string
  {
    $canEdit = !empty($context['can_edit']);
    $summary = ($context['summary'] ?? []) + array_fill_keys(['objetivos', 'metas', 'metas_realizadas', 'metas_vencidas', 'tareas', 'tareas_completadas', 'tareas_en_proceso', 'campanas', 'mis_pendientes'], 0);
    $options = json_encode($context['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    $start = $summary['mis_pendientes'] > 0 ? 'mis_tareas' : 'objetivos';
    $tabs = '';
    foreach (['mis_tareas' => ['person_check', 'Mis tareas'], 'objetivos' => ['flag', 'Objetivos'], 'metas' => ['target', 'Metas'], 'tareas' => ['checklist', 'Tareas'], 'campanas' => ['campaign', 'Campañas']] as $key => [$icon, $label]) {
      $tabs .= '<button type="button" data-plan-tab="' . $key . '" aria-pressed="' . ($key === $start ? 'true' : 'false') . '"><span class="material-symbols-outlined" aria-hidden="true">' . $icon . '</span>' . $label . '</button>';
    }
    $stat = static fn(string $key, string $label, string $hint = '', string $class = ''): string => '<div class="ctl-stat' . ($class !== '' ? ' ' . $class : '') . '"><span>' . self::e($label) . '</span><strong data-plan-stat="' . $key . '">' . (int) $summary[$key] . '</strong>'
      . ($hint !== '' ? '<small data-plan-hint="' . $key . '">' . self::e($hint) . '</small>' : '') . '</div>';
    $close = '<button type="button" class="ctl-icon" data-plan-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>';

    return '<link rel="stylesheet" href="' . self::e(PromotionView::assetUrl($baseUrl, self::ASSET_CSS)) . '" data-commercial-module-style="plan_estrategico">'
      . '<section class="ctl cpe" data-commercial-tool="plan_estrategico" data-commercial-strategy data-commercial-module-script="' . self::e(PromotionView::assetUrl($baseUrl, self::ASSET_JS)) . '"'
      . ' data-plan-start="' . $start . '" data-plan-can-edit="' . ($canEdit ? '1' : '0') . '" data-plan-user="' . self::e($context['user'] ?? '') . '" data-plan-options="' . self::e($options) . '">'
      . '<header class="ctl-hero"><div class="ctl-hero__text"><span class="ctl-eyebrow"><span class="material-symbols-outlined" aria-hidden="true">flag</span>Herramientas</span><h1>Plan estratégico</h1>'
      . '<p>Objetivos de cada gestión, sus metas medibles, las tareas que las cumplen y las campañas de cada tarea. Registra los avances de tus tareas y los resultados de tus campañas.'
      . ($canEdit ? ' Los catálogos (gestiones, unidades, tipos y actores) se ajustan en <strong>Configuración › Plan estratégico</strong>.' : '') . '</p></div></header>'
      . '<div class="ctl-stats cpe-stats">'
      . $stat('mis_pendientes', 'Mis tareas pendientes', 'Asignadas a ti y sin completar', 'is-total')
      . $stat('objetivos', 'Objetivos')
      . $stat('metas', 'Metas', $summary['metas_realizadas'] . ' realizadas · ' . $summary['metas_vencidas'] . ' vencidas')
      . $stat('tareas', 'Tareas', $summary['tareas_completadas'] . ' completadas · ' . $summary['tareas_en_proceso'] . ' en proceso')
      . $stat('campanas', 'Campañas')
      . '</div>'
      . '<section class="ctl-card cpe-board">'
      . '<div class="ctl-segment cpe-tabs" role="group" aria-label="Secciones del plan">' . $tabs . '</div>'
      . '<div class="cpe-toolbar">'
      . '<label class="ctl-field cpe-search"><span class="ctl-label">Buscar</span><span class="ctl-input"><input type="search" data-plan-search placeholder="Nombre o descripción" autocomplete="off"></span></label>'
      . '<div class="cpe-filters" data-plan-filters></div>'
      . '<div class="cpe-toolbar__actions">'
      . '<button type="button" class="ctl-ghost" data-plan-glossary><span class="material-symbols-outlined" aria-hidden="true">menu_book</span>Glosario</button>'
      . ($canEdit ? '<button type="button" class="ctl-primary cpe-new" data-plan-new><span class="material-symbols-outlined" aria-hidden="true">add</span><span data-plan-new-label>Nuevo</span></button>' : '')
      . '</div></div>'
      . '<p class="ctl-status" role="status" aria-live="polite" data-plan-status></p>'
      . '<div class="cpe-list" data-plan-list><div class="ctl-empty"><span class="material-symbols-outlined" aria-hidden="true">hourglass_empty</span><p>Cargando el plan…</p></div></div>'
      . '</section>'
      // Popups internos: formulario, bitácora (avances o resultados), glosario y confirmación de eliminación.
      . '<dialog class="ctl-dialog cpe-dialog" data-plan-form-dialog aria-labelledby="cpe-form-title"><form method="dialog" data-plan-form novalidate>'
      . '<header><h2 id="cpe-form-title" data-plan-form-title>Registro</h2>' . $close . '</header>'
      . '<div class="ctl-dialog__body"><div class="ctl-grid cpe-form-grid" data-plan-form-fields></div><p class="ctl-error" data-plan-form-error hidden></p></div>'
      . '<footer><button type="button" class="ctl-ghost" data-plan-close>Cancelar</button><button type="submit" class="ctl-primary" data-plan-form-submit><span class="material-symbols-outlined" aria-hidden="true">save</span>Guardar</button></footer>'
      . '</form></dialog>'
      . '<dialog class="ctl-dialog cpe-dialog" data-plan-log-dialog aria-labelledby="cpe-log-title">'
      . '<header><h2 id="cpe-log-title" data-plan-log-title>Bitácora</h2>' . $close . '</header>'
      . '<div class="ctl-dialog__body"><p class="cpe-log-subject" data-plan-log-subject></p>'
      . '<form class="cpe-log-form" data-plan-log-form novalidate hidden>'
      . '<label class="ctl-field"><span class="ctl-label" data-plan-log-label>Descripción</span><textarea class="cpe-textarea" name="descripcion" rows="3" maxlength="2000" required></textarea></label>'
      . '<label class="ctl-switch" data-plan-log-finish><input type="checkbox" name="finalizar" value="1"><span aria-hidden="true"></span>Marcar la tarea como completada</label>'
      . '<p class="ctl-error" data-plan-log-error hidden></p>'
      . '<div class="ctl-actions"><button type="submit" class="ctl-primary cpe-inline">Registrar</button><span class="ctl-note" data-plan-log-author></span></div>'
      . '</form>'
      . '<h3>Historial</h3><ol class="cpe-log" data-plan-log-list></ol></div>'
      . '</dialog>'
      . '<dialog class="ctl-dialog cpe-dialog" data-plan-glossary-dialog aria-labelledby="cpe-glossary-title">'
      . '<header><h2 id="cpe-glossary-title"><span class="material-symbols-outlined" aria-hidden="true">menu_book</span>Glosario del plan</h2>' . $close . '</header>'
      . '<div class="ctl-dialog__body" data-plan-glossary-body></div>'
      . '</dialog>'
      . '<dialog class="ctl-dialog cpe-dialog cpe-dialog--confirm" data-plan-confirm-dialog aria-labelledby="cpe-confirm-title"><form method="dialog">'
      . '<header><h2 id="cpe-confirm-title"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Eliminar</h2>' . $close . '</header>'
      . '<div class="ctl-dialog__body"><p data-plan-confirm-text></p><p class="ctl-error" data-plan-confirm-error hidden></p></div>'
      . '<footer><button type="button" class="ctl-ghost" data-plan-close>Cancelar</button><button type="submit" class="ctl-primary cpe-danger" data-plan-confirm-ok>Eliminar</button></footer>'
      . '</form></dialog>'
      . '</section>';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
