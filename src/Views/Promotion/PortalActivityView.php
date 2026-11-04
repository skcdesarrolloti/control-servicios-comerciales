<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\PortalActivityRepository;
use SCM\Core\Database;

/**
 * Promoción › Actividad de portales. Copia/adaptación de dashboard-marketing views/activity.php con el diseño comercial:
 * indicadores, accesos por día, por portal y menú, por tipo de actor, usuarios y últimos accesos paginados. Los filtros
 * se aplican con un botón y navegan dentro del panel. IP y navegador solo con el permiso promocion_ver_ip.
 */
final class PortalActivityView
{
  /** @param array<string,mixed> $input @return array<string,mixed> */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, ?string $today = null): array
  {
    $today = $today ?? date('Y-m-d');
    $network = $policy->canAct('promocion_ver_ip');
    $filters = PortalActivityRepository::filters($input, $today);
    return ['today' => $today, 'can_network' => $network, 'data' => (new PortalActivityRepository($db))->summary($filters, $network)];
  }

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    $data = $context['data'];
    $filters = $data['filters'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = PromotionUi::hero('login', 'Actividad de portales', 'Ingresos y navegación de los clientes y aliados en los portales de SKC SuCasa Inmobiliaria: sesiones, menús más usados y últimos accesos.');
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    $html .= self::filtersForm($context, $baseUrl);
    if (!$data['access_available']) {
      $html .= '<p class="cpr-alert is-error">No se pudo leer la tabla de accesos de los portales (wp_jet_cct_accesos_portal).</p>';
    }
    if (!$data['sessions_available']) {
      $html .= '<p class="cpr-alert is-error">No se pudo leer la tabla de sesiones de los portales (wp_jet_cct_sesiones).</p>';
    }
    if ($filters['clamped']) {
      $html .= '<p class="cpr-alert">El rango máximo es de 12 meses: se muestran desde el ' . $e(PromotionUi::day($filters['from'])) . '.</p>';
    }
    $kpis = $data['kpis'];
    $html .= '<div class="cpr-stats" data-cpr-kpis>'
      . PromotionUi::stat('Accesos a menús', PromotionUi::number($kpis['accesos']), $kpis['ultimo_acceso'] !== '' ? 'Último: ' . PromotionUi::day($kpis['ultimo_acceso'], true) : 'Sin accesos en el rango', 'ads_click')
      . PromotionUi::stat('Usuarios únicos', PromotionUi::number($kpis['usuarios_unicos']), 'Con al menos un acceso', 'group')
      . PromotionUi::stat('Usuarios con ingreso', PromotionUi::number($kpis['usuarios_sesion']), 'Su último ingreso cae en el rango', 'login')
      . PromotionUi::stat('Sesiones acumuladas', PromotionUi::number($kpis['sesiones']), 'Total histórico de esos usuarios', 'history')
      . '</div>';
    if ($filters['proyecto'] !== '' || $filters['menu'] !== '') {
      $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>Las sesiones no se filtran por portal ni por menú: los portales las guardan por usuario.</p>';
    }
    $html .= PromotionUi::dailyChart('Accesos por día', 'accesos', $data['daily']);

    $html .= '<div class="cpr-grid">';
    $html .= PromotionUi::tableCard('Por portal y menú', 'Los 20 más usados', [['Portal'], ['Menú'], ['Accesos', true], ['Usuarios', true]], array_map(static fn(array $row): array => [
      $e($row['proyecto'] !== '' ? $row['proyecto'] : 'Sin portal'), $e($row['menu'] !== '' ? $row['menu'] : 'Sin menú'), $e(PromotionUi::number($row['accesos'])), $e(PromotionUi::number($row['usuarios_unicos'])),
    ], $data['menus']), 'Sin accesos en el rango.', ' data-cpr-menus');
    $html .= PromotionUi::tableCard('Por tipo de actor', 'Accesos y sesiones del rango', [['Tipo de actor'], ['Accesos', true], ['Usuarios', true], ['Sesiones', true]], array_map(static fn(array $row): array => [
      $e($row['tipo_actor']), $e(PromotionUi::number($row['accesos'])), $e(PromotionUi::number($row['usuarios'])), $e(PromotionUi::number($row['sesiones'])),
    ], $data['by_actor']), 'Sin actividad en el rango.', ' data-cpr-actors');
    $html .= PromotionUi::tableCard('Usuarios con más accesos', 'Los 12 primeros del rango', [['Usuario'], ['Tipo de actor'], ['Accesos', true], ['Último acceso']], array_map(static fn(array $row): array => [
      self::person($row['nombre'], $row['usuario']), $e($row['tipo_actor']), $e(PromotionUi::number($row['accesos'])), $e(PromotionUi::day($row['ultimo_acceso'], true)),
    ], $data['top_access_users']), 'Sin accesos en el rango.');
    $html .= PromotionUi::tableCard('Usuarios con más sesiones', 'Con ingreso en el rango · sesiones acumuladas', [['Usuario'], ['Tipo de actor'], ['Sesiones', true], ['Último ingreso']], array_map(static fn(array $row): array => [
      self::person($row['nombre'], $row['usuario']), $e($row['tipo_actor']), $e(PromotionUi::number($row['sesiones'])), $e(PromotionUi::day($row['ultimo_ingreso'])),
    ], $data['top_session_users']), 'Nadie ingresó en el rango.');
    $html .= '</div>';
    return $html . self::recent($context, $baseUrl);
  }

  private static function person(string $name, string $user): string
  {
    return '<span class="cpr-cell-main">' . PromotionUi::e($name !== '' ? $name : 'Sin nombre') . '</span><span class="cpr-cell-sub">' . PromotionUi::e($user) . '</span>';
  }

  /** @param array<string,mixed> $context */
  private static function filtersForm(array $context, string $baseUrl): string
  {
    $data = $context['data'];
    $filters = $data['filters'];
    $min = (new \DateTimeImmutable($context['today']))->modify('-' . (PortalActivityRepository::MAX_DAYS - 1) . ' days')->format('Y-m-d');
    return '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="actividad_portales" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'actividad_portales'])) . '">'
      . '<div class="cpr-filters__grid is-wide">'
      . PromotionUi::dateField('pr_desde', 'Desde', $filters['from'], $min, $context['today'])
      . PromotionUi::dateField('pr_hasta', 'Hasta', $filters['to'], $min, $context['today'])
      . PromotionUi::select('pr_actor', 'Tipo de actor', PortalActivityRepository::ACTOR_TYPES, $filters['actor'], 'Todos los actores')
      . PromotionUi::select('pr_proyecto', 'Portal', $data['options']['proyectos'], $filters['proyecto'], 'Todos los portales')
      . PromotionUi::select('pr_menu', 'Menú', $data['options']['menus'], $filters['menu'], 'Todos los menús')
      . '<label class="cpr-field"><span class="cpr-label">Usuario o nombre</span><input type="search" name="pr_q" value="' . PromotionUi::e($filters['q']) . '" maxlength="80" placeholder="Documento exacto o inicio del nombre"></label>'
      . '</div><div class="cpr-filters__actions"><a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'actividad_portales'])) . '">Últimos 30 días</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">Hasta 12 meses. Los cambios se aplican al pulsar Aplicar.</p></form>';
  }

  /** @param array<string,mixed> $context */
  private static function recent(array $context, string $baseUrl): string
  {
    $data = $context['data'];
    $filters = $data['filters'];
    $network = (bool) $context['can_network'];
    $e = static fn($value): string => PromotionUi::e($value);
    $rows = '';
    foreach ($data['recent'] as $row) {
      $rows .= '<tr><td>' . $e(PromotionUi::day($row['creado_en'], true)) . '</td><td>' . $e($row['proyecto']) . '</td><td>' . $e($row['tipo_actor']) . '</td><td>' . self::person($row['nombre'], $row['usuario']) . '</td>'
        . '<td><span class="cpr-cell-main">' . $e($row['menu']) . '</span><span class="cpr-cell-sub cpr-url" title="' . $e($row['ruta']) . '">' . $e($row['ruta']) . '</span></td>'
        . ($network ? '<td>' . $e($row['ip'] ?? '') . '</td><td><span class="cpr-ua" title="' . $e($row['user_agent'] ?? '') . '">' . $e($row['user_agent'] ?? '') . '</span></td>' : '') . '</tr>';
    }
    $params = ['tab' => 'actividad_portales', 'pr_desde' => $filters['from'], 'pr_hasta' => $filters['to'], 'pr_actor' => $filters['actor'], 'pr_proyecto' => $filters['proyecto'], 'pr_menu' => $filters['menu'], 'pr_q' => $filters['q']];
    $page = (int) $filters['page'];
    $pages = (int) $data['pages'];
    $pager = '<nav class="cpr-pager" aria-label="Páginas de accesos">'
      . ($page > 1 ? '<a class="cpr-ghost" data-cpr-nav data-cpr-page="prev" href="' . $e(PromotionUi::url($baseUrl, $params + ['pr_pag' => $page - 1])) . '">Anteriores</a>' : '<span class="cpr-ghost is-disabled" aria-disabled="true">Anteriores</span>')
      . '<span data-cpr-page-label>Página ' . $page . ' de ' . $pages . '</span>'
      . ($page < $pages ? '<a class="cpr-ghost" data-cpr-nav data-cpr-page="next" href="' . $e(PromotionUi::url($baseUrl, $params + ['pr_pag' => $page + 1])) . '">Siguientes</a>' : '<span class="cpr-ghost is-disabled" aria-disabled="true">Siguientes</span>')
      . '</nav>';
    return '<section class="cpr-card" data-cpr-recent><div class="cpr-card__head"><div><h2>Últimos accesos</h2><p>' . PortalActivityRepository::PAGE_SIZE . ' por página, del más reciente al más antiguo.'
      . ($network ? ' Incluye IP y navegador.' : ' La IP y el navegador solo los ve quien tiene el permiso.') . '</p></div></div>'
      . ($rows === '' ? '<p class="cpr-empty">Sin accesos en el rango.</p>' : '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Fecha</th><th>Portal</th><th>Tipo de actor</th><th>Usuario</th><th>Menú / ruta</th>'
        . ($network ? '<th>IP</th><th>Navegador</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager)
      . '</section>';
  }
}
