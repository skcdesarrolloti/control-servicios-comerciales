<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialStatusCatalog;
use SCM\Core\Auth;

final class CommercialDashboardView
{
  /** @param array<string,mixed> $data */
  public static function render(array $data): string
  {
    $bucket = (string) ($data['bucket'] ?? 'abiertos');
    $result = is_array($data['result'] ?? null) ? $data['result'] : [];
    $homeDashboard = is_array($data['home_dashboard'] ?? null) ? $data['home_dashboard'] : [];
    $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
    $policy = $data['policy'] ?? null;
    $runtime = is_array($data['runtime'] ?? null) ? $data['runtime'] : [];
    $runtimeJson = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $views = is_array($data['visible_views'] ?? null) ? $data['visible_views'] : [];
    $calendarEmployees = is_array($data['calendar_employees'] ?? null) ? $data['calendar_employees'] : [];
    $ticketEmployees = is_array($data['ticket_employees'] ?? null) ? $data['ticket_employees'] : [];
    $commercialEmployeeCargos = is_array($data['commercial_employee_cargos'] ?? null) ? array_values(array_map('strval', $data['commercial_employee_cargos'])) : [];
    $filterOptions = is_array($data['filter_options'] ?? null) ? $data['filter_options'] : [];
    $tabCounts = is_array($data['tab_counts'] ?? null) ? $data['tab_counts'] : [];
    $baseUrl = rtrim((string) ($data['base_url'] ?? ''), '/');

    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de Control - Servicios Comerciales</title>
  <link rel="icon" href="<?php echo esc_url(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="<?php echo esc_url($baseUrl . '/assets/css/dashboard-shell.css?v=' . SCM_VERSION); ?>">
  <link rel="stylesheet" href="<?php echo esc_url($baseUrl . '/assets/css/scm-admin.css?v=' . SCM_VERSION); ?>">
  <link rel="stylesheet" href="<?php echo esc_url($baseUrl . '/assets/css/admin/05-guide-contracts.css?v=' . SCM_VERSION); ?>">
  <link rel="stylesheet" href="<?php echo esc_url($baseUrl . '/assets/css/commercial-dashboard.css?v=' . SCM_VERSION); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
</head>
<body class="commercial-body">
  <header class="commercial-topbar">
    <div class="commercial-topbar-inner">
      <a class="commercial-brand" href="<?php echo esc_url($baseUrl . '/index.php'); ?>">
        <span class="commercial-logo"><img src="<?php echo esc_url(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria"></span>
        <span class="commercial-brand-title">Panel de Control - Servicios Comerciales</span>
      </a>
      <div class="commercial-session">
        <form method="post" action="<?php echo esc_url($baseUrl . '/logout.php'); ?>">
          <?php echo \SCM\Core\App::csrf()->field('logout'); ?>
          <button type="submit" class="commercial-logout-link" aria-label="Cerrar sesión" title="Cerrar sesión">Cerrar sesión</button>
        </form>
        <span><strong><?php echo esc_html(Auth::user()); ?></strong><small><?php echo esc_html(Auth::userRol()); ?></small></span>
      </div>
    </div>
  </header>

  <main id="scm-app" class="scm-wrap scm-daisy commercial-app" data-theme="scm-daisy" data-scm-runtime="<?php echo esc_attr((string) $runtimeJson); ?>">
    <section class="commercial-actionbar" aria-label="Acciones del panel">
      <div class="scm-guide-bar commercial-tools">
        <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
          <button class="scm-guide-btn scm-guide-btn--primary" type="button" id="commercial-open-permissions"><i class="fas fa-sliders" aria-hidden="true"></i> Configurar permisos</button>
        <?php endif; ?>
        <button class="scm-guide-btn" type="button" id="scm-open-guide"><i class="fas fa-book-open" aria-hidden="true"></i> Ver guías</button>
      </div>
    </section>

    <?php echo self::renderTabs($views, $bucket, $filters, $tabCounts, $baseUrl); ?>

    <?php if ($bucket === 'sin_acceso'): ?>
      <section class="scm-tab-panel active" id="scm-panel-sin_acceso">
        <div class="commercial-empty"><i class="fas fa-lock" aria-hidden="true"></i><h2>Sin vistas habilitadas</h2><p>Tu cargo no tiene secciones visibles en este panel. Solicita acceso a un administrador.</p></div>
      </section>
    <?php else: ?>
      <section class="scm-tab-panel commercial-panel<?php echo $bucket !== 'calendario' ? ' active' : ''; ?>" id="commercial-tickets-panel" data-commercial-tickets-panel aria-live="polite">
        <?php if ($bucket !== 'calendario'): ?>
          <?php
            if ($bucket === 'inicio') {
              echo self::renderHome($homeDashboard, $filters, $policy, $baseUrl, $ticketEmployees, $filterOptions);
            } elseif ($bucket === 'actualizaciones') {
              echo self::renderPropertyUpdatesPage($filters, $ticketEmployees, $filterOptions, $policy);
            } elseif ($bucket === 'avisos') {
              echo self::renderSignsPage($filters, $ticketEmployees, $filterOptions, $policy);
            } else {
              echo self::renderTickets($bucket, $result, $filters, $ticketEmployees, $filterOptions, $tabCounts, $policy, $baseUrl);
            }
          ?>
        <?php endif; ?>
      </section>
      <section class="scm-tab-panel commercial-panel<?php echo $bucket === 'calendario' ? ' active' : ''; ?>" id="scm-panel-actividades-administrativas" data-commercial-calendar-panel>
        <div class="scm-admin-activities">
          <div class="scm-admin-activity-panel active" id="scm-panel-calendario-actividades" data-admin-activity-panel="calendario_actividades">
            <?php echo self::renderCalendar($runtime['config'] ?? [], $calendarEmployees); ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <div class="commercial-modal commercial-case-modal" id="commercial-case-modal" role="dialog" aria-modal="true" aria-labelledby="commercial-case-title" aria-hidden="true">
      <div class="commercial-modal-card commercial-case-card" role="document">
        <button type="button" class="commercial-modal-close commercial-case-close" data-commercial-close-case aria-label="Cerrar detalle">&times;</button>
        <div class="commercial-case-content" data-commercial-case-content><div class="commercial-case-loading"><span></span><span></span><span></span><p>Cargando información de la tarea…</p></div></div>
      </div>
    </div>

    <?php echo CommercialGuideView::render(); ?>
    <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
      <?php echo self::renderPermissions($policy, $commercialEmployeeCargos); ?>
    <?php endif; ?>
  </main>

  <script src="<?php echo esc_url($baseUrl . '/assets/js/scm-admin.js?v=' . SCM_VERSION); ?>"></script>
  <script src="<?php echo esc_url($baseUrl . '/assets/js/admin-dashboard-runtime.js?v=' . SCM_VERSION); ?>"></script>
  <script src="<?php echo esc_url($baseUrl . '/assets/js/commercial-dashboard.js?v=' . SCM_VERSION); ?>"></script>
</body>
</html>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $views @param array<string,mixed> $filters @param array<string,int> $tabCounts */
  public static function renderTabs(array $views, string $bucket, array $filters, array $tabCounts, string $baseUrl): string
  {
    $taskViews = ['abiertos', 'postergados', 'cerrados', 'mis_tickets'];
    $visibleTaskViews = array_values(array_filter($taskViews, static fn(string $view): bool => in_array($view, $views, true)));
    $activeTop = in_array($bucket, $taskViews, true) ? 'tareas' : $bucket;
    $items = [];
    if (in_array('inicio', $views, true)) {
      $items[] = ['key' => 'inicio', 'label' => 'Inicio', 'tab' => 'inicio', 'icon' => 'fa-chart-pie'];
    }
    if (in_array('actualizaciones', $views, true)) {
      $items[] = ['key' => 'actualizaciones', 'label' => 'Actualizaciones', 'tab' => 'actualizaciones', 'icon' => 'fa-rotate'];
    }
    if (in_array('avisos', $views, true)) {
      $items[] = ['key' => 'avisos', 'label' => 'Avisos', 'tab' => 'avisos', 'icon' => 'fa-sign-hanging'];
    }
    if ($visibleTaskViews !== []) {
      $items[] = ['key' => 'tareas', 'label' => 'Tareas', 'tab' => $visibleTaskViews[0], 'icon' => 'fa-list-check'];
    }
    if (in_array('calendario', $views, true)) {
      $items[] = ['key' => 'calendario', 'label' => 'Calendario', 'tab' => 'calendario', 'icon' => 'fa-calendar-days'];
    }
    ob_start();
?>
    <nav class="commercial-tabs" data-commercial-tabs aria-label="Secciones del panel">
      <?php foreach ($items as $item): ?>
        <?php $isActive = $activeTop === $item['key']; ?>
        <a class="commercial-tab<?php echo $isActive ? ' active' : ''; ?>" data-commercial-tab="<?php echo esc_attr((string) $item['key']); ?>" href="<?php echo esc_url(self::url($baseUrl, ['tab' => $item['tab']])); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
          <i class="fas <?php echo esc_attr((string) $item['icon']); ?>" aria-hidden="true"></i>
          <span><?php echo esc_html((string) $item['label']); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees */
  public static function renderGlobalFilters(array $filters, array $ticketEmployees, string $baseUrl, bool $lockedToCurrentEmployee = false, bool $personalTaskScope = false): string
  {
    $selectedEmployee = trim((string) ($filters['id_empleado'] ?? ''));
    $tab = trim((string) ($filters['tab'] ?? 'abiertos'));
    if ($tab === '') {
      $tab = 'abiertos';
    }
    $selectedEmployeeLabel = trim(Auth::user());
    foreach ($ticketEmployees as $employee) {
      $employeeValue = (string) ($employee['id'] ?? '');
      $employeeIds = array_values(array_filter(array_map('trim', explode(',', $employeeValue)), static fn(string $id): bool => $id !== ''));
      if ($selectedEmployee === $employeeValue || in_array($selectedEmployee, $employeeIds, true)) {
        $selectedEmployeeLabel = (string) ($employee['name'] ?? $selectedEmployeeLabel);
        break;
      }
    }
    if ($selectedEmployeeLabel === '') {
      $selectedEmployeeLabel = 'Mis tareas';
    }
    ob_start();
?>
    <form class="commercial-global-filter<?php echo $lockedToCurrentEmployee ? ' commercial-global-filter--locked' : ''; ?><?php echo $personalTaskScope ? ' commercial-global-filter--personal' : ''; ?>" data-commercial-global-filter-form method="get" action="<?php echo esc_url($baseUrl . '/index.php'); ?>">
      <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
      <div>
        <span class="commercial-kicker"><?php echo $personalTaskScope ? 'Vista personal' : 'Filtro de tareas'; ?></span>
        <label for="commercial-global-employee"><?php echo $personalTaskScope ? 'Mis tareas se filtran con tu id_empleado' : 'Funcionario responsable'; ?></label>
      </div>
      <?php if ($personalTaskScope): ?>
        <input type="hidden" id="commercial-global-employee" name="id_empleado" value="<?php echo esc_attr($selectedEmployee); ?>">
        <div class="commercial-locked-employee" aria-live="polite">
          <span>Sesión actual</span>
          <strong><?php echo esc_html($selectedEmployeeLabel); ?></strong>
          <?php if ($selectedEmployee !== ''): ?><small>ID empleado: <?php echo esc_html($selectedEmployee); ?></small><?php endif; ?>
        </div>
      <?php elseif ($lockedToCurrentEmployee): ?>
        <input type="hidden" id="commercial-global-employee" name="id_empleado" value="<?php echo esc_attr($selectedEmployee); ?>">
        <div class="commercial-locked-employee" aria-live="polite">
          <span>Mostrando únicamente</span>
          <strong><?php echo esc_html($selectedEmployeeLabel); ?></strong>
        </div>
      <?php else: ?>
        <select id="commercial-global-employee" name="id_empleado">
          <option value="">Todos los funcionarios</option>
          <?php foreach ($ticketEmployees as $employee): ?>
            <?php $employeeValue = (string) ($employee['id'] ?? ''); $employeeIds = array_values(array_filter(array_map('trim', explode(',', $employeeValue)), static fn(string $id): bool => $id !== '')); ?>
            <option value="<?php echo esc_attr($employeeValue); ?>"<?php echo ($selectedEmployee === $employeeValue || in_array($selectedEmployee, $employeeIds, true)) ? ' selected' : ''; ?>><?php echo esc_html((string) ($employee['name'] ?? '')); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="commercial-primary-btn" type="submit">Actualizar</button>
        <?php if ($selectedEmployee !== ''): ?><a class="commercial-secondary-btn" data-commercial-global-clear href="<?php echo esc_url(self::url($baseUrl, ['tab' => $tab])); ?>">Limpiar</a><?php endif; ?>
      <?php endif; ?>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $dashboard @param array<string,mixed> $filters */
  public static function renderHome(array $dashboard, array $filters, $policy, string $baseUrl, array $ticketEmployees = [], array $filterOptions = []): string
  {
    $update = is_array($dashboard['update_health'] ?? null) ? $dashboard['update_health'] : [];
    $slaSummary = is_array($dashboard['sla_summary'] ?? null) ? $dashboard['sla_summary'] : [];
    $properties = is_array($dashboard['properties'] ?? null) ? $dashboard['properties'] : [];
    $signs = is_array($dashboard['signs'] ?? null) ? $dashboard['signs'] : [];
    $propertyPublic = (int) ($properties['publicos'] ?? 0);
    $propertyOk = (int) ($properties['actualizacion_ok'] ?? 0);
    $propertyAlert = (int) ($properties['actualizacion_alerta'] ?? 0);
    $propertyExpired = (int) ($properties['actualizacion_vencida'] ?? 0);
    $propertyPending = $propertyAlert + $propertyExpired;
    $propertyPct = self::percent($propertyOk, $propertyPublic);
    $taskUpdatePct = max(0, min(100, (int) ($slaSummary['porcentaje_cumplimiento'] ?? 0)));
    $taskOverdue = (int) ($slaSummary['atrasados'] ?? 0);
    $taskOnTime = (int) ($slaSummary['al_dia'] ?? 0);
    $taskTotal = (int) ($slaSummary['total'] ?? 0);
    $signRetouchOk = (int) ($signs['retoque_ok'] ?? 0);
    $signRetouchAlert = (int) ($signs['retoque_alerta'] ?? 0);
    $signRetouchExpired = (int) ($signs['retoque_vencido'] ?? 0);
    $signRetouchTotal = $signRetouchOk + $signRetouchAlert + $signRetouchExpired;
    $signRetouchPct = self::percent($signRetouchOk, $signRetouchTotal);
    $newSignPending = (int) ($signs['instalacion_pendiente'] ?? 0);
    $newSignLate = (int) ($signs['instalacion_atrasada'] ?? 0);
    $newSignOnTime = max(0, $newSignPending - $newSignLate);
    $newSignPct = self::percent($newSignOnTime, $newSignPending);
    $scopeLabel = trim((string) ($filters['id_empleado'] ?? '')) !== '' ? 'Filtrado por funcionario' : 'Equipo comercial completo';
    $today = date('d/m/Y');
    ob_start();
?>
    <section class="commercial-home" data-commercial-home>
      <div class="commercial-section-head commercial-home-head">
        <div>
          <span class="commercial-kicker">Inicio operativo</span>
          <h2>Control rápido de alertas</h2>
          <p><?php echo esc_html($scopeLabel); ?> · <?php echo esc_html($today); ?> · solo indicadores de actualización, retoque de avisos y avisos nuevos pendientes.</p>
        </div>
      </div>

      <div class="commercial-home-control-dashboard">
        <article class="commercial-home-control-card commercial-home-control-card--primary">
          <div class="commercial-sla-chart commercial-home-ring" style="--commercial-sla-ok: <?php echo esc_attr((string) $taskUpdatePct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) max(0, 100 - $taskUpdatePct)); ?>%;">
            <span><?php echo esc_html((string) $taskUpdatePct); ?>%</span>
          </div>
          <div>
            <span class="commercial-kicker">Actualización</span>
            <h3>Tareas atrasadas</h3>
            <p><?php echo esc_html((string) $taskOverdue); ?> atrasadas de <?php echo esc_html((string) $taskTotal); ?> tareas abiertas.</p>
            <div class="commercial-home-actions">
              <button class="commercial-primary-btn" type="button" data-commercial-open-advisory="task_updates">Ver popup</button>
              <a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'sla_filter' => 'atrasado'] + self::globalFilterParams($filters))); ?>">Ver tareas</a>
            </div>
          </div>
          <footer>
            <?php echo self::renderHomeStatus('Al día', $taskOnTime, 'success'); ?>
            <?php echo self::renderHomeStatus('Atrasadas', $taskOverdue, 'danger'); ?>
          </footer>
        </article>

        <article class="commercial-home-control-card">
          <div class="commercial-sla-chart commercial-home-ring" style="--commercial-sla-ok: <?php echo esc_attr((string) $propertyPct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) max(0, 100 - $propertyPct)); ?>%;">
            <span><?php echo esc_html((string) $propertyPct); ?>%</span>
          </div>
          <div>
            <span class="commercial-kicker">Actualizaciones</span>
            <h3>Inmuebles publicados</h3>
            <p><?php echo esc_html((string) $propertyPending); ?> inmuebles requieren revisión de actualización.</p>
            <div class="commercial-home-actions">
              <button class="commercial-primary-btn" type="button" data-commercial-open-advisory="property_updates">Ver popup</button>
              <a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones'] + self::globalFilterParams($filters))); ?>">Abrir módulo</a>
            </div>
          </div>
          <footer>
            <?php echo self::renderHomeStatus('Al día', $propertyOk, 'success'); ?>
            <?php echo self::renderHomeStatus('Alerta', $propertyAlert, 'warning'); ?>
            <?php echo self::renderHomeStatus('Vencidos', $propertyExpired, 'danger'); ?>
          </footer>
        </article>

        <article class="commercial-home-control-card">
          <div class="commercial-sla-chart commercial-home-ring" style="--commercial-sla-ok: <?php echo esc_attr((string) $signRetouchPct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) max(0, 100 - $signRetouchPct)); ?>%;">
            <span><?php echo esc_html((string) $signRetouchPct); ?>%</span>
          </div>
          <div>
            <span class="commercial-kicker">Avisos</span>
            <h3>Retoque de avisos</h3>
            <p><?php echo esc_html((string) ($signRetouchAlert + $signRetouchExpired)); ?> avisos con retoque en alerta o vencido.</p>
            <div class="commercial-home-actions">
              <button class="commercial-primary-btn" type="button" data-commercial-open-advisory="sign_retouch">Ver popup</button>
              <a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos', 'estado_aviso' => 'Vencido'] + self::globalFilterParams($filters))); ?>">Ver vencidos</a>
            </div>
          </div>
          <footer>
            <?php echo self::renderHomeStatus('Al día', $signRetouchOk, 'success'); ?>
            <?php echo self::renderHomeStatus('Por vencer', $signRetouchAlert, 'warning'); ?>
            <?php echo self::renderHomeStatus('Vencidos', $signRetouchExpired, 'danger'); ?>
          </footer>
        </article>

        <article class="commercial-home-control-card commercial-home-control-card--warning">
          <div class="commercial-sla-chart commercial-home-ring" style="--commercial-sla-ok: <?php echo esc_attr((string) $newSignPct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) max(0, 100 - $newSignPct)); ?>%;">
            <span><?php echo esc_html((string) $newSignPct); ?>%</span>
          </div>
          <div>
            <span class="commercial-kicker">Avisos nuevos</span>
            <h3>Pendientes por instalar</h3>
            <p><?php echo esc_html((string) $newSignPending); ?> inmuebles públicos piden aviso y aún están en control de instalación.</p>
            <div class="commercial-home-actions">
              <button class="commercial-primary-btn" type="button" data-commercial-open-advisory="sign_new">Ver popup</button>
              <a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos'] + self::globalFilterParams($filters))); ?>">Abrir avisos</a>
            </div>
          </div>
          <footer>
            <?php echo self::renderHomeStatus('Pendientes', $newSignPending, 'warning'); ?>
            <?php echo self::renderHomeStatus('Atrasados', $newSignLate, 'danger'); ?>
          </footer>
        </article>
      </div>

      <?php echo self::renderHomeAdvisoryModal([], $signs, $properties, $filters, $baseUrl, $update, $slaSummary); ?>
    </section>
<?php
    return (string) ob_get_clean();
  }

  private static function renderHomeKpi(string $label, int $value, string $description, string $tone = 'neutral'): string
  {
    return '<div class="commercial-home-kpi commercial-home-kpi--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong><small>' . esc_html($description) . '</small></div>';
  }

  private static function percent(int $part, int $total): int
  {
    if ($total <= 0) {
      return 0;
    }
    return max(0, min(100, (int) round(($part / $total) * 100)));
  }

  private static function renderHomeMini(string $label, int $value, bool $available = true): string
  {
    return '<div class="commercial-home-mini' . (!$available ? ' is-muted' : '') . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($available ? (string) $value : '—') . '</strong></div>';
  }

  private static function renderHomeStatus(string $label, int $value, string $tone): string
  {
    return '<div class="commercial-home-status commercial-home-status--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
  }

  private static function renderHomeMeter(string $label, int $percent, string $caption): string
  {
    return '<div class="commercial-home-meter"><div><span>' . esc_html($label) . '</span><strong>' . esc_html((string) max(0, min(100, $percent))) . '%</strong></div><progress max="100" value="' . esc_attr((string) max(0, min(100, $percent))) . '"></progress><small>' . esc_html($caption) . '</small></div>';
  }

  /** @param array<string,mixed> $row */
  private static function renderHomePriorityTask(array $row, $policy): string
  {
    $pk = (int) ($row['_ID'] ?? 0);
    $logicalId = trim((string) ($row['id_ticket'] ?? '')) ?: (string) $pk;
    $subject = trim((string) ($row['asunto'] ?? '')) ?: 'Tarea comercial';
    $status = trim((string) ($row['estado_comercial'] ?? 'Sin estado'));
    $slaLabel = trim((string) ($row['scm_sla_label'] ?? ''));
    $days = (int) ($row['scm_attention_days'] ?? 0);
    $canOpen = $policy instanceof CommercialAccessPolicy && $policy->canAct('ver_ticket');
    ob_start();
?>
    <div class="commercial-home-priority-item<?php echo (string) ($row['scm_sla_status'] ?? '') === 'atrasado' ? ' is-overdue' : ''; ?>">
      <div>
        <span>#<?php echo esc_html($logicalId); ?> · <?php echo esc_html($status); ?><?php echo $slaLabel !== '' ? ' · ' . esc_html($slaLabel) : ''; ?></span>
        <strong><?php echo esc_html(mb_strimwidth($subject, 0, 88, '…', 'UTF-8')); ?></strong>
        <small><?php echo esc_html((string) $days); ?> días en atención</small>
      </div>
      <?php if ($canOpen): ?><button class="commercial-secondary-btn" type="button" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>">Ver tarea</button><?php endif; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $alerts @param array<string,mixed> $signs @param array<string,mixed> $properties @param array<string,mixed> $filters */
  private static function renderHomeAdvisoryModal(array $alerts, array $signs, array $properties, array $filters, string $baseUrl, array $update = [], array $slaSummary = []): string
  {
    unset($alerts, $update);
    $globalParams = self::globalFilterParams($filters);
    $taskTotal = (int) ($slaSummary['total'] ?? 0);
    $taskOnTime = (int) ($slaSummary['al_dia'] ?? 0);
    $taskOverdue = (int) ($slaSummary['atrasados'] ?? 0);
    $taskCompliance = max(0, min(100, (int) ($slaSummary['porcentaje_cumplimiento'] ?? 0)));
    $taskLatePct = max(0, min(100, (int) ($slaSummary['porcentaje_atraso'] ?? 0)));
    $taskStats = [
      ['label' => 'Al día', 'value' => $taskOnTime, 'tone' => 'success'],
      ['label' => 'Atraso', 'value' => $taskLatePct . '%', 'tone' => 'danger'],
      ['label' => 'Atrasadas', 'value' => $taskOverdue, 'tone' => 'danger'],
    ];
    $propertyPending = (int) ($properties['actualizacion_alerta'] ?? 0) + (int) ($properties['actualizacion_vencida'] ?? 0);
    $retouchPending = (int) ($signs['retoque_alerta'] ?? 0) + (int) ($signs['retoque_vencido'] ?? 0);
    $newSignPending = (int) ($signs['instalacion_pendiente'] ?? 0);

    $renderTaskAlert = static function (array $stats, int $overdue, int $total, int $compliance): string {
      ob_start();
?>
      <div class="commercial-advisory-ticket-alert">
        <aside>
          <div class="commercial-advisory-warning-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M10.27 3a2 2 0 0 1 3.46 0l8.66 15a2 2 0 0 1-1.73 3H3.34a2 2 0 0 1-1.73-3L10.27 3Z"/><path d="M12 8v5" stroke="#dc2626" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="17" r="1.2" fill="#dc2626"/></svg>
          </div>
          <h3>¡Atención!</h3>
          <p>No estás cumpliendo con la política de gestión de tareas.</p>
        </aside>
        <section>
          <h3>Resumen de pendientes</h3>
          <p>Tienes <strong><?php echo esc_html((string) $overdue); ?> <?php echo $overdue === 1 ? 'tarea atrasada' : 'tareas atrasadas'; ?></strong>.</p>
          <div class="commercial-advisory-progress-box">
            <div><span>Cumplimiento actual</span><strong><?php echo esc_html((string) $compliance); ?>%</strong></div>
            <div class="commercial-advisory-progress" aria-hidden="true"><span style="width: <?php echo esc_attr((string) $compliance); ?>%;"></span></div>
            <?php echo self::renderAdvisoryStats($stats); ?>
          </div>
          <small>Total base analizada: <?php echo esc_html((string) $total); ?> tareas abiertas.</small>
        </section>
      </div>
<?php
      return (string) ob_get_clean();
    };

    $taskBody = $renderTaskAlert($taskStats, $taskOverdue, $taskTotal, $taskCompliance);
    $propertyBody = self::renderAdvisorySingleCount($propertyPending, 'inmuebles', 'Requieren actualización.', 'warning');
    $retouchBody = self::renderAdvisorySingleCount($retouchPending, 'avisos', 'Necesitan retoque.', 'amber');
    $newSignBody = self::renderAdvisorySingleCount($newSignPending, 'avisos', 'Pendientes por instalar.', 'blue');

    ob_start();
?>
    <?php echo self::renderAdvisoryModalShell('task_updates', 'Política de atención', 'Tareas atrasadas', 'Resumen de cumplimiento del tiempo de atención configurado.', $taskBody, '<a class="commercial-primary-btn" data-commercial-filter-link href="' . esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'sla_filter' => 'atrasado'] + $globalParams)) . '">Gestionar ahora</a>', 'danger', $taskOverdue > 0); ?>
    <?php echo self::renderAdvisoryModalShell('property_updates', 'Atención requerida', 'Inmuebles pendientes por actualización', 'Tienes inmuebles publicados que entraron en alerta o vencimiento de actualización.', $propertyBody, '<a class="commercial-primary-btn" data-commercial-filter-link href="' . esc_url(self::url($baseUrl, ['tab' => 'actualizaciones'] + $globalParams)) . '">Gestionar</a>', 'warning', $propertyPending > 0); ?>
    <?php echo self::renderAdvisoryModalShell('sign_retouch', 'Estado de avisos', 'Retoques de avisos pendientes', 'Avisos instalados que ya requieren revisión o están entrando en ventana de retoque.', $retouchBody, '<a class="commercial-primary-btn" data-commercial-filter-link href="' . esc_url(self::url($baseUrl, ['tab' => 'avisos'] + $globalParams)) . '">Gestionar</a>', 'amber', $retouchPending > 0); ?>
    <?php echo self::renderAdvisoryModalShell('sign_new', 'Avisos nuevos', 'Pendientes por instalar', 'Inmuebles públicos que pidieron aviso y todavía no aparecen como colocados.', $newSignBody, '<a class="commercial-primary-btn" data-commercial-filter-link href="' . esc_url(self::url($baseUrl, ['tab' => 'avisos'] + $globalParams)) . '">Gestionar</a>', 'blue', $newSignPending > 0); ?>
<?php
    return (string) ob_get_clean();
  }

  private static function renderAdvisorySingleCount(int $count, string $unit, string $caption, string $tone): string
  {
    ob_start();
?>
    <div class="commercial-advisory-single-count commercial-advisory-single-count--<?php echo esc_attr($tone); ?>">
      <span>Cantidad</span>
      <strong><?php echo esc_html((string) $count); ?></strong>
      <small><?php echo esc_html($unit); ?></small>
      <p><?php echo esc_html($caption); ?></p>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array{label:string,value:mixed,tone:string}> $stats */
  private static function renderAdvisoryStats(array $stats): string
  {
    ob_start();
?>
    <div class="commercial-advisory-mini-stats">
      <?php foreach ($stats as $stat): ?>
        <div class="commercial-advisory-mini-stat commercial-advisory-mini-stat--<?php echo esc_attr((string) ($stat['tone'] ?? 'neutral')); ?>">
          <span><?php echo esc_html((string) ($stat['label'] ?? 'Indicador')); ?></span>
          <strong><?php echo esc_html((string) ($stat['value'] ?? 0)); ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private static function renderAdvisoryModalShell(string $key, string $kicker, string $title, string $description, string $body, string $footerActions, string $tone = 'warning', bool $autoOpen = false): string
  {
    $titleId = 'commercial-advisory-title-' . preg_replace('/[^a-z0-9_-]/i', '', $key);
    ob_start();
?>
    <div class="commercial-modal commercial-advisory-modal" id="commercial-advisory-modal-<?php echo esc_attr($key); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($titleId); ?>" aria-hidden="true" data-commercial-advisory-modal="<?php echo esc_attr($key); ?>" data-auto-open="<?php echo $autoOpen ? '1' : '0'; ?>">
      <div class="commercial-modal-card commercial-advisory-card commercial-advisory-card--<?php echo esc_attr($tone); ?>" role="document">
        <header>
          <div><span class="commercial-kicker"><?php echo esc_html($kicker); ?></span><h2 id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($title); ?></h2><p><?php echo esc_html($description); ?></p></div>
          <button type="button" class="commercial-modal-close" data-commercial-close-advisory aria-label="Cerrar popup">&times;</button>
        </header>
        <div class="commercial-advisory-body"><?php echo $body; ?></div>
        <footer>
          <button type="button" class="commercial-secondary-btn" data-commercial-close-advisory>Cerrar</button>
          <?php echo $footerActions; ?>
        </footer>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions */
  public static function renderPropertyUpdatesPage(array $filters, array $ticketEmployees, array $filterOptions, $policy): string
  {
    return self::renderOperationalControls($filters, $ticketEmployees, $filterOptions, $policy, 'updates');
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions */
  public static function renderSignsPage(array $filters, array $ticketEmployees, array $filterOptions, $policy): string
  {
    return self::renderOperationalControls($filters, $ticketEmployees, $filterOptions, $policy, 'signs');
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions */
  private static function renderOperationalControls(array $filters, array $ticketEmployees, array $filterOptions, $policy, string $activePanel): string
  {
    $canSeeAll = $policy instanceof CommercialAccessPolicy && $policy->canSeeAllCommercialTickets();
    $selectedEmployee = trim((string) ($filters['id_empleado'] ?? ''));
    $barrios = is_array($filterOptions['barrios'] ?? null) ? $filterOptions['barrios'] : [];
    $tipos = is_array($filterOptions['tipos_inmueble'] ?? null) ? $filterOptions['tipos_inmueble'] : [];
    $rutas = is_array($filterOptions['rutas_avisos'] ?? null) ? $filterOptions['rutas_avisos'] : [];
    $selectedCode = trim((string) ($filters['codigo'] ?? ''));
    $selectedBusiness = trim((string) ($filters['gestion'] ?? ''));
    $selectedType = trim((string) ($filters['tipo'] ?? ''));
    $selectedNeighborhood = trim((string) ($filters['barrio'] ?? ''));
    $selectedRoute = trim((string) ($filters['ruta'] ?? ''));
    $selectedUpdateState = trim((string) ($filters['estado_actualizacion'] ?? ''));
    $selectedSignState = trim((string) ($filters['estado_aviso'] ?? ''));
    $isUpdates = $activePanel === 'updates';
    $signMode = in_array($selectedSignState, ['Atrasado', 'A Tiempo'], true) ? 'new' : 'maintenance';
    $pageTitle = $isUpdates ? 'Actualizaciones de inmuebles' : 'Avisos en fachada';
    $pageDescription = $isUpdates
      ? 'Control completo de inmuebles públicos que están al día, en alerta o vencidos por actualización.'
      : 'Control completo de avisos nuevos, retoques y rutas operativas por barrio o ruta.';
    ob_start();
?>
    <section class="commercial-home-control-shell" data-commercial-home-controls>
      <header>
        <div>
          <span class="commercial-kicker">Módulo operativo</span>
          <h2><?php echo esc_html($pageTitle); ?></h2>
          <p><?php echo esc_html($pageDescription); ?></p>
        </div>
      </header>

      <article class="commercial-home-control-panel<?php echo $isUpdates ? ' active' : ''; ?>" id="commercial-property-updates-panel" data-commercial-home-control-panel="updates">
        <div class="commercial-control-title">
          <div><h3>Panel para actualizar inmuebles</h3><p>Filtra inmuebles públicos por vencimiento de actualización y abre la ficha técnica sin salir del dashboard.</p></div>
          <span data-commercial-property-total>0 inmuebles</span>
        </div>
        <form class="commercial-control-filters" data-commercial-property-control>
          <label><span>Código</span><input type="text" name="codigo" placeholder="Ej: 90480" value="<?php echo esc_attr($selectedCode); ?>"></label>
          <label><span>Gestión</span><?php echo self::selectBusinessOptions('commercial-property-business', 'gestion', $selectedBusiness); ?></label>
          <?php if ($canSeeAll): ?><label><span>Funcionario</span><?php echo self::selectEmployeeOptions('commercial-property-employee', 'id_empleado', $ticketEmployees, $selectedEmployee); ?></label><?php endif; ?>
          <label><span>Estado</span><select name="estado_actualizacion"><option value="">Todos</option><option value="Vencido"<?php selected($selectedUpdateState, 'Vencido'); ?>>Vencidos</option><option value="Alerta"<?php selected($selectedUpdateState, 'Alerta'); ?>>Alerta</option><option value="OK"<?php selected($selectedUpdateState, 'OK'); ?>>Al día</option></select></label>
          <div><button class="commercial-primary-btn" type="submit">Filtrar</button><button class="commercial-secondary-btn" type="button" data-commercial-control-clear>Limpiar</button></div>
        </form>
        <div class="commercial-control-statline" data-commercial-property-stats>Al día: 0 · Alerta: 0 · Vencidos: 0</div>
        <div class="commercial-control-table-wrap">
          <table class="commercial-control-table">
            <thead><tr><th>Código</th><?php if ($canSeeAll): ?><th>Funcionario</th><?php endif; ?><th>Gestión</th><th>Estado</th><th>Tiempo</th><th>Acciones</th></tr></thead>
            <tbody data-commercial-property-rows><tr><td colspan="<?php echo $canSeeAll ? '6' : '5'; ?>">Cargando inmuebles…</td></tr></tbody>
          </table>
        </div>
      </article>

      <article class="commercial-home-control-panel<?php echo !$isUpdates ? ' active' : ''; ?>" id="commercial-signs-panel" data-commercial-home-control-panel="signs">
        <div class="commercial-control-title">
          <div><h3>Panel de avisos en fachada</h3><p>Controla retoques, avisos nuevos y rutas por barrio o ruta.</p></div>
          <span data-commercial-sign-total>0 avisos</span>
        </div>
        <div class="commercial-sign-mode-tabs" role="tablist" aria-label="Modos de avisos">
          <button class="<?php echo $signMode === 'maintenance' ? 'active' : ''; ?>" type="button" data-commercial-sign-tab="maintenance">Retoque de avisos</button>
          <button class="<?php echo $signMode === 'new' ? 'active' : ''; ?>" type="button" data-commercial-sign-tab="new">Avisos nuevos</button>
          <?php if ($canSeeAll): ?><button type="button" data-commercial-sign-tab="routes_neighborhood">Rutas x barrio</button><button type="button" data-commercial-sign-tab="routes_route">Rutas x ruta</button><?php endif; ?>
        </div>
        <form class="commercial-control-filters" data-commercial-sign-control data-mode="<?php echo esc_attr($signMode); ?>">
          <label><span>Código</span><input type="text" name="codigo" placeholder="Ej: 90480" value="<?php echo esc_attr($selectedCode); ?>"></label>
          <label><span>Gestión</span><?php echo self::selectBusinessOptions('commercial-sign-business', 'gestion', $selectedBusiness); ?></label>
          <label><span>Tipo</span><?php echo self::selectFromOptions('commercial-sign-type', 'tipo', $tipos, $selectedType); ?></label>
          <label><span>Barrio</span><?php echo self::selectFromOptions('commercial-sign-neighborhood', 'barrio', $barrios, $selectedNeighborhood); ?></label>
          <label><span>Ruta</span><?php echo self::selectFromOptions('commercial-sign-route', 'ruta', $rutas, $selectedRoute); ?></label>
          <?php if ($canSeeAll): ?><label><span>Funcionario</span><?php echo self::selectEmployeeOptions('commercial-sign-employee', 'id_empleado', $ticketEmployees, $selectedEmployee); ?></label><?php endif; ?>
          <label><span>Estado</span><select name="estado_aviso"><option value="">Todos</option><option value="Vencido"<?php selected($selectedSignState, 'Vencido'); ?>>Vencido</option><option value="Alerta"<?php selected($selectedSignState, 'Alerta'); ?>>Alerta</option><option value="OK"<?php selected($selectedSignState, 'OK'); ?>>Al día</option><option value="Atrasado"<?php selected($selectedSignState, 'Atrasado'); ?>>Nuevo atrasado</option><option value="A Tiempo"<?php selected($selectedSignState, 'A Tiempo'); ?>>Nuevo al día</option></select></label>
          <div><button class="commercial-primary-btn" type="submit">Filtrar</button><button class="commercial-secondary-btn" type="button" data-commercial-control-clear>Limpiar</button></div>
        </form>
        <div class="commercial-control-table-wrap">
          <div data-commercial-sign-rows class="commercial-control-table-state">Cargando avisos…</div>
        </div>
      </article>
    </section>

    <div class="commercial-modal commercial-property-modal" id="commercial-property-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="commercial-modal-card commercial-property-card" role="document">
        <button type="button" class="commercial-modal-close" data-commercial-close-property aria-label="Cerrar ficha">&times;</button>
        <div data-commercial-property-modal-content></div>
      </div>
    </div>
    <div class="commercial-modal commercial-sign-detail-modal" id="commercial-sign-detail-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="commercial-modal-card commercial-sign-detail-card" role="document">
        <button type="button" class="commercial-modal-close" data-commercial-close-sign-detail aria-label="Cerrar detalle">&times;</button>
        <div data-commercial-sign-detail-content></div>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public static function renderPropertyUpdateRows(array $rows, bool $showEmployee): string
  {
    if ($rows === []) {
      return '<tr><td colspan="' . ($showEmployee ? '6' : '5') . '" class="commercial-control-empty-cell">Sin inmuebles para este filtro.</td></tr>';
    }
    $html = '';
    foreach ($rows as $row) {
      $status = (string) ($row['estado'] ?? 'OK');
      $tone = self::controlTone($status);
      $detail = esc_attr((string) json_encode($row['detalle'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
      $html .= '<tr><td><strong>' . esc_html((string) ($row['codigo'] ?? '')) . '</strong></td>';
      if ($showEmployee) {
        $html .= '<td>' . esc_html((string) ($row['funcionario'] ?? '')) . '</td>';
      }
      $html .= '<td>' . esc_html((string) ($row['gestion'] ?? '')) . '</td>';
      $html .= '<td><span class="commercial-control-badge commercial-control-badge--' . esc_attr($tone) . '">' . esc_html($status) . '</span></td>';
      $html .= '<td><strong>' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . '</strong> días</td>';
      $html .= '<td><div class="commercial-control-actions">';
      if (trim((string) ($row['url_actualizar'] ?? '')) !== '') {
        $html .= '<a class="commercial-control-btn commercial-control-btn--primary" href="' . esc_url((string) $row['url_actualizar']) . '" target="_blank" rel="noopener">Actualizar</a>';
      }
      $html .= '<button class="commercial-control-btn" type="button" data-commercial-property-info="' . $detail . '">Ficha técnica</button>';
      if ($showEmployee && trim((string) ($row['url_despublicar'] ?? '')) !== '') {
        $html .= '<a class="commercial-control-btn commercial-control-btn--danger" href="' . esc_url((string) $row['url_despublicar']) . '" target="_blank" rel="noopener">Despublicar</a>';
      }
      $html .= '</div></td></tr>';
    }
    return $html;
  }

  /** @param array<int,array<string,mixed>> $rows */
  public static function renderSignControlRows(array $rows, string $mode): string
  {
    if ($rows === []) {
      return '<div class="commercial-control-empty-cell">Sin avisos para este filtro.</div>';
    }
    if (in_array($mode, ['routes_neighborhood', 'routes_route'], true)) {
      $groupKey = $mode === 'routes_route' ? 'ruta' : 'barrio';
      $groups = [];
      foreach ($rows as $row) {
        $key = mb_strtoupper(trim((string) ($row[$groupKey] ?? '')), 'UTF-8') ?: ($mode === 'routes_route' ? 'SIN RUTA' : 'SIN BARRIO');
        $groups[$key][] = $row;
      }
      ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
      $html = '<div class="commercial-route-groups">';
      foreach ($groups as $group => $items) {
        $tableId = 'commercial-route-' . md5((string) $group);
        $html .= '<details class="commercial-route-group"><summary><strong>' . esc_html((string) $group) . '</strong><span>' . count($items) . ' avisos</span><button type="button" data-commercial-copy-table="' . esc_attr($tableId) . '">Copiar tabla</button></summary>';
        $html .= '<div class="commercial-control-table-wrap"><table id="' . esc_attr($tableId) . '" class="commercial-control-table"><thead><tr><th>' . ($mode === 'routes_route' ? 'Barrio' : 'Ruta') . '</th><th>Dirección</th><th>Punto ref.</th><th>Cód.</th><th>Funcionario</th><th>Celular</th><th>Tipo</th><th>Maps</th></tr></thead><tbody>';
        foreach ($items as $row) {
          $html .= self::renderSignRouteRow($row, $mode);
        }
        $html .= '</tbody></table></div></details>';
      }
      return $html . '</div>';
    }

    $headers = $mode === 'new'
      ? '<tr><th>Código</th><th>Funcionario</th><th>Barrio</th><th>Captación</th><th>Gestión</th><th>Días / Max</th><th>Estado</th><th>Maps</th><th>Acción</th></tr>'
      : '<tr><th>Código</th><th>Funcionario</th><th>Barrio</th><th>Gestión</th><th>Estado</th><th>Días</th><th>Maps</th><th>Acción</th></tr>';
    $html = '<table class="commercial-control-table"><thead>' . $headers . '</thead><tbody>';
    foreach ($rows as $row) {
      $status = (string) ($row['estado'] ?? 'OK');
      $tone = self::controlTone($status);
      $maps = trim((string) ($row['maps_url'] ?? '')) !== '' ? '<a href="' . esc_url((string) $row['maps_url']) . '" target="_blank" rel="noopener">Ver mapa</a>' : '—';
      $detail = esc_attr((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
      if ($mode === 'new') {
        $html .= '<tr><td><strong>' . esc_html((string) ($row['codigo'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td>' . esc_html((string) ($row['barrio'] ?? '')) . '</td><td>' . esc_html((string) ($row['fecha'] ?? '')) . '</td><td>' . esc_html((string) ($row['gestion'] ?? '')) . '</td><td><strong>' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . '</strong> días</td><td><span class="commercial-control-badge commercial-control-badge--' . esc_attr($tone) . '">' . esc_html($status) . '</span></td><td>' . $maps . '</td><td><button class="commercial-control-btn" type="button" data-commercial-sign-detail="' . $detail . '">Ver detalle</button></td></tr>';
      } else {
        $html .= '<tr><td><strong>' . esc_html((string) ($row['codigo'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td>' . esc_html((string) ($row['barrio'] ?? '')) . '</td><td>' . esc_html((string) ($row['gestion'] ?? '')) . '</td><td><span class="commercial-control-badge commercial-control-badge--' . esc_attr($tone) . '">' . esc_html($status) . '</span></td><td><strong>' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . '</strong></td><td>' . $maps . '</td><td><button class="commercial-control-btn" type="button" data-commercial-sign-detail="' . $detail . '">Ver detalle</button></td></tr>';
      }
    }
    return $html . '</tbody></table>';
  }

  /** @param array<string,mixed> $row */
  private static function renderSignRouteRow(array $row, string $mode): string
  {
    $first = $mode === 'routes_route' ? (string) ($row['barrio'] ?? '') : (string) ($row['ruta'] ?? '');
    $maps = trim((string) ($row['maps_url'] ?? '')) !== '' ? '<a href="' . esc_url((string) $row['maps_url']) . '" target="_blank" rel="noopener">Ver mapa</a>' : '—';
    return '<tr><td>' . esc_html($first) . '</td><td>' . esc_html((string) ($row['direccion'] ?? '')) . '</td><td>' . esc_html((string) ($row['punto_referencia'] ?? '')) . '</td><td><strong>' . esc_html((string) ($row['codigo'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td>' . esc_html((string) ($row['celular_funcionario'] ?? '')) . '</td><td>' . esc_html((string) ($row['tipo'] ?? '')) . '</td><td>' . $maps . '</td></tr>';
  }

  private static function controlTone(string $status): string
  {
    $normalized = mb_strtolower($status, 'UTF-8');
    if (str_contains($normalized, 'venc') || str_contains($normalized, 'atras')) {
      return 'danger';
    }
    if (str_contains($normalized, 'alert')) {
      return 'warning';
    }
    return 'success';
  }

  /** @param array<string,mixed> $result @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions @param array<string,int> $tabCounts */
  public static function renderTickets(string $bucket, array $result, array $filters, array $ticketEmployees, array $filterOptions, array $tabCounts, $policy, string $baseUrl): string
  {
    $isMyTasks = $bucket === 'mis_tickets';
    $effectiveBucket = $isMyTasks ? self::myTasksBucket($filters, (string) ($result['effective_bucket'] ?? '')) : $bucket;
    $bucketDef = CommercialStatusCatalog::buckets()[$effectiveBucket] ?? CommercialStatusCatalog::buckets()['abiertos'];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
    $bucketCounts = is_array($result['bucket_counts'] ?? null) ? $result['bucket_counts'] : [];
    $topicCounts = is_array($result['topic_counts'] ?? null) ? $result['topic_counts'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
    $slaSummary = is_array($result['sla_summary'] ?? null) ? $result['sla_summary'] : [];
    $bucketTotal = (int) ($result['bucket_total'] ?? array_sum(array_intersect_key($counts, array_flip($bucketDef['statuses']))));
    $visibleTotal = isset($pagination['total']) ? (int) $pagination['total'] : $bucketTotal;
    $baseFilterParams = self::filterParams($filters);
    $topicOptions = array_keys($topicCounts);
    $sectionLabel = $isMyTasks ? 'Mis tareas ' . self::myTaskStageLabel($effectiveBucket) : (string) $bucketDef['label'];
    $sectionDescription = $isMyTasks
      ? 'Tus tareas asignadas, separadas por etapa y tema de ayuda.'
      : (string) $bucketDef['description'];
    $canSeeAll = $policy instanceof CommercialAccessPolicy && $policy->canSeeAllCommercialTickets();
    ob_start();
?>
    <?php echo self::renderTaskTabs($bucket, $tabCounts, $filters, $policy, $baseUrl); ?>
    <?php echo self::renderGlobalFilters($filters, $ticketEmployees, $baseUrl, !$canSeeAll, $isMyTasks); ?>

    <div class="commercial-section-head">
      <div><span class="commercial-kicker"><?php echo $isMyTasks ? 'Mis tareas por etapa' : 'Estado del embudo'; ?></span><h2><?php echo esc_html($sectionLabel); ?></h2><p><?php echo esc_html($sectionDescription); ?></p></div>
      <span class="commercial-total"><strong><?php echo esc_html((string) $visibleTotal); ?></strong> tareas</span>
    </div>

    <?php if ($isMyTasks): ?>
      <?php echo self::renderMyTaskSubtabs($effectiveBucket, $bucketCounts, $filters, $baseUrl); ?>
      <?php echo self::renderTopicStrip($bucket, $effectiveBucket, $filters, $topicCounts, $bucketTotal, $baseUrl); ?>
    <?php else: ?>
      <div class="commercial-status-strip" aria-label="Estados de <?php echo esc_attr(mb_strtolower($bucketDef['label'], 'UTF-8')); ?>">
        <a class="commercial-status-chip<?php echo empty($filters['estado']) ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>"><span>Todos</span><strong><?php echo esc_html((string) $bucketTotal); ?></strong></a>
        <?php foreach ($bucketDef['statuses'] as $status): ?>
          <?php $statusCount = (int) ($counts[$status] ?? 0); $statusActive = (($filters['estado'] ?? '') === $status); if ($statusCount <= 0 && !$statusActive) continue; ?>
          <a class="commercial-status-chip<?php echo $statusActive ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'estado' => $status] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>"><span><?php echo esc_html($status); ?></span><strong><?php echo esc_html((string) $statusCount); ?></strong></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($effectiveBucket === 'abiertos'): ?>
      <?php echo self::renderSlaSummary($slaSummary, $filters); ?>
    <?php endif; ?>

    <form class="commercial-filter-card" data-commercial-filter-form method="get" action="<?php echo esc_url($baseUrl . '/index.php'); ?>">
      <input type="hidden" name="tab" value="<?php echo esc_attr($bucket); ?>">
      <?php if ($isMyTasks): ?><input type="hidden" name="mis_bucket" value="<?php echo esc_attr($effectiveBucket); ?>"><?php endif; ?>
      <?php if (!empty($filters['id_empleado'])): ?><input type="hidden" name="id_empleado" value="<?php echo esc_attr((string) $filters['id_empleado']); ?>"><?php endif; ?>
      <?php if (!$isMyTasks && !empty($filters['estado'])): ?><input type="hidden" name="estado" value="<?php echo esc_attr((string) $filters['estado']); ?>"><?php endif; ?>
      <?php if ($isMyTasks && !empty($filters['tema'])): ?><input type="hidden" name="tema" value="<?php echo esc_attr((string) $filters['tema']); ?>"><?php endif; ?>
      <div class="commercial-field commercial-field--wide"><label for="commercial-search">Buscar</label><input id="commercial-search" type="search" name="busqueda" value="<?php echo esc_attr((string) ($filters['busqueda'] ?? '')); ?>" placeholder="Tarea, asunto, solicitante, inmueble…"></div>
      <div class="commercial-field"><label for="commercial-ticket-id">ID tarea</label><input id="commercial-ticket-id" type="text" name="ticket_id" value="<?php echo esc_attr((string) ($filters['ticket_id'] ?? '')); ?>" placeholder="Ej: 8604"></div>
      <?php if ($effectiveBucket === 'abiertos'): ?><div class="commercial-field"><label for="commercial-sla-filter">Tiempo de atención</label><select id="commercial-sla-filter" name="sla_filter"><option value="">Todos</option><option value="atrasado"<?php selected((string) ($filters['sla_filter'] ?? ''), 'atrasado'); ?>>Atrasados</option><option value="al_dia"<?php selected((string) ($filters['sla_filter'] ?? ''), 'al_dia'); ?>>Al día</option></select></div><?php endif; ?>
      <?php if (!$isMyTasks && !empty($filters['estado'])): ?><div class="commercial-locked-filter"><span>Estado comercial</span><strong><?php echo esc_html((string) $filters['estado']); ?></strong><a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>">Ver todos</a></div><?php endif; ?>
      <?php if ($isMyTasks && !empty($filters['tema'])): ?><div class="commercial-locked-filter"><span>Tema de ayuda</span><strong><?php echo esc_html((string) $filters['tema']); ?></strong><a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'mis_bucket' => $effectiveBucket] + array_diff_key($baseFilterParams, ['tema' => true, 'estado' => true, 'page' => true]))); ?>">Ver todos</a></div><?php endif; ?>
      <div class="commercial-field"><label for="commercial-requester">Solicitante</label><input id="commercial-requester" type="text" name="solicitante" value="<?php echo esc_attr((string) ($filters['solicitante'] ?? '')); ?>" placeholder="Nombre"></div>
      <div class="commercial-field"><label for="commercial-phone">Celular</label><input id="commercial-phone" type="text" name="celular" value="<?php echo esc_attr((string) ($filters['celular'] ?? '')); ?>" placeholder="Número"></div>
      <div class="commercial-field"><label for="commercial-email">Correo</label><input id="commercial-email" type="text" name="correo" value="<?php echo esc_attr((string) ($filters['correo'] ?? '')); ?>" placeholder="correo@dominio.com"></div>
      <div class="commercial-field"><label for="commercial-property">Inmueble</label><input id="commercial-property" type="text" name="inmueble" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>" placeholder="Código o dirección"></div>
      <div class="commercial-field"><label for="commercial-neighborhood">Barrio</label><?php echo self::selectFromOptions('commercial-neighborhood', 'barrio', $filterOptions['barrios'] ?? [], (string) ($filters['barrio'] ?? '')); ?></div>
      <?php if (!$isMyTasks): ?><div class="commercial-field"><label for="commercial-topic">Tema</label><?php echo self::selectFromOptions('commercial-topic', 'tema', $topicOptions, (string) ($filters['tema'] ?? '')); ?></div><?php endif; ?>
      <div class="commercial-field"><label for="commercial-medium">Medio</label><?php echo self::selectFromOptions('commercial-medium', 'medio', $filterOptions['medios'] ?? [], (string) ($filters['medio'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-priority">Prioridad</label><?php echo self::selectFromOptions('commercial-priority', 'prioridad', $filterOptions['prioridades'] ?? [], (string) ($filters['prioridad'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-follow-up">Seguimiento</label><select id="commercial-follow-up" name="seguimiento"><option value="">Todos</option><option value="Si"<?php selected((string) ($filters['seguimiento'] ?? ''), 'Si'); ?>>Con seguimiento</option><option value="No"<?php selected((string) ($filters['seguimiento'] ?? ''), 'No'); ?>>Sin seguimiento</option></select></div>
      <?php if (!empty($filterOptions['encargados_seguimiento'])): ?><div class="commercial-field"><label for="commercial-follow-up-owner">Encargado seguimiento</label><?php echo self::selectEmployeeOptions('commercial-follow-up-owner', 'encargado_seguimiento', $filterOptions['encargados_seguimiento'], (string) ($filters['encargado_seguimiento'] ?? '')); ?></div><?php endif; ?>
      <div class="commercial-field"><label for="commercial-stale">Sin actualizar desde gestión</label><select id="commercial-stale" name="sin_actualizar"><option value="">Todos</option><option value="3"<?php selected((string) ($filters['sin_actualizar'] ?? ''), '3'); ?>>+3 días</option><option value="7"<?php selected((string) ($filters['sin_actualizar'] ?? ''), '7'); ?>>+7 días</option><option value="15"<?php selected((string) ($filters['sin_actualizar'] ?? ''), '15'); ?>>+15 días</option><option value="30"<?php selected((string) ($filters['sin_actualizar'] ?? ''), '30'); ?>>+30 días</option></select></div>
      <?php if (!empty($filterOptions['estados_administrativos'])): ?><div class="commercial-field"><label for="commercial-admin-status">Estado administrativo</label><?php echo self::selectFromOptions('commercial-admin-status', 'estado_administrativo', $filterOptions['estados_administrativos'], (string) ($filters['estado_administrativo'] ?? '')); ?></div><?php endif; ?>
      <div class="commercial-field"><label for="commercial-follow-up-from">Seg. desde</label><input id="commercial-follow-up-from" type="date" name="fecha_seguimiento_desde" value="<?php echo esc_attr((string) ($filters['fecha_seguimiento_desde'] ?? '')); ?>"></div>
      <div class="commercial-field"><label for="commercial-follow-up-to">Seg. hasta</label><input id="commercial-follow-up-to" type="date" name="fecha_seguimiento_hasta" value="<?php echo esc_attr((string) ($filters['fecha_seguimiento_hasta'] ?? '')); ?>"></div>
      <div class="commercial-field"><label for="commercial-date-from">Fecha desde</label><input id="commercial-date-from" type="date" name="fecha_desde" value="<?php echo esc_attr((string) ($filters['fecha_desde'] ?? '')); ?>"></div>
      <div class="commercial-field"><label for="commercial-date-to">Fecha hasta</label><input id="commercial-date-to" type="date" name="fecha_hasta" value="<?php echo esc_attr((string) ($filters['fecha_hasta'] ?? '')); ?>"></div>
      <?php $clearParams = ['tab' => $bucket] + self::globalFilterParams($filters); if ($isMyTasks) { $clearParams['mis_bucket'] = $effectiveBucket; } ?>
      <div class="commercial-filter-actions"><button class="commercial-primary-btn" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Filtrar</button><a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $clearParams)); ?>">Limpiar</a></div>
    </form>

    <?php if ($rows === []): ?>
      <div class="commercial-empty"><i class="far fa-folder-open" aria-hidden="true"></i><h3>Sin tareas en esta vista</h3><p>Prueba con otro estado o limpia los filtros actuales.</p></div>
    <?php else: ?>
      <div class="commercial-ticket-grid">
        <?php foreach ($rows as $row): echo self::renderTicketCard($row, $policy); endforeach; ?>
      </div>
      <?php echo self::renderPagination($bucket, $filters, $pagination, $baseUrl); ?>
    <?php endif; ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,int> $tabCounts @param array<string,mixed> $filters */
  private static function renderTaskTabs(string $activeBucket, array $tabCounts, array $filters, $policy, string $baseUrl): string
  {
    $taskViews = [
      'abiertos' => 'Abiertas',
      'postergados' => 'Postergadas',
      'cerrados' => 'Cerradas',
      'mis_tickets' => 'Mis tareas',
    ];
    $canView = static function (string $view) use ($policy): bool {
      return !$policy instanceof CommercialAccessPolicy || $policy->canView($view);
    };
    $globalParams = $activeBucket === 'mis_tickets' ? [] : self::globalFilterParams($filters);
    ob_start();
?>
    <nav class="commercial-my-subtabs commercial-task-tabs" aria-label="Vistas de tareas comerciales">
      <?php foreach ($taskViews as $viewKey => $label): ?>
        <?php if (!$canView($viewKey)) continue; ?>
        <?php $params = $viewKey === 'mis_tickets' ? ['tab' => $viewKey] : ['tab' => $viewKey] + $globalParams; ?>
        <a class="commercial-my-subtab commercial-task-tab<?php echo $activeBucket === $viewKey ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $params)); ?>"<?php echo $activeBucket === $viewKey ? ' aria-current="page"' : ''; ?>>
          <span><?php echo esc_html($label); ?></span>
          <strong><?php echo esc_html((string) ((int) ($tabCounts[$viewKey] ?? 0))); ?></strong>
        </a>
      <?php endforeach; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,int> $bucketCounts @param array<string,mixed> $filters */
  private static function renderMyTaskSubtabs(string $activeBucket, array $bucketCounts, array $filters, string $baseUrl): string
  {
    $baseParams = array_diff_key(self::filterParams($filters), [
      'estado' => true,
      'mis_bucket' => true,
      'page' => true,
      'tema' => true,
      'sla_filter' => true,
    ]);
    $labels = [
      'abiertos' => 'Abiertas',
      'postergados' => 'Postergadas',
      'cerrados' => 'Cerradas',
    ];
    ob_start();
?>
    <nav class="commercial-my-subtabs" aria-label="Etapas de mis tareas">
      <?php foreach ($labels as $subBucket => $label): ?>
        <?php $count = (int) ($bucketCounts[$subBucket] ?? 0); ?>
        <a class="commercial-my-subtab<?php echo $activeBucket === $subBucket ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'mis_tickets', 'mis_bucket' => $subBucket] + $baseParams)); ?>"<?php echo $activeBucket === $subBucket ? ' aria-current="page"' : ''; ?>>
          <span><?php echo esc_html($label); ?></span>
          <strong><?php echo esc_html((string) $count); ?></strong>
        </a>
      <?php endforeach; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<string,int> $topicCounts */
  private static function renderTopicStrip(string $bucket, string $effectiveBucket, array $filters, array $topicCounts, int $total, string $baseUrl): string
  {
    $activeTopic = trim((string) ($filters['tema'] ?? ''));
    if ($topicCounts === [] && $activeTopic === '') {
      return '';
    }
    $baseParams = array_diff_key(self::filterParams($filters), [
      'estado' => true,
      'mis_bucket' => true,
      'page' => true,
      'tema' => true,
    ]);
    ob_start();
?>
    <div class="commercial-status-strip commercial-topic-strip" aria-label="Temas de ayuda de mis tareas">
      <a class="commercial-status-chip commercial-topic-chip<?php echo $activeTopic === '' ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'mis_bucket' => $effectiveBucket] + $baseParams)); ?>"><span>Todos los temas</span><strong><?php echo esc_html((string) $total); ?></strong></a>
      <?php foreach ($topicCounts as $topic => $count): ?>
        <?php $topic = trim((string) $topic); $count = (int) $count; if ($topic === '' || $count <= 0) continue; ?>
        <a class="commercial-status-chip commercial-topic-chip<?php echo $activeTopic === $topic ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'mis_bucket' => $effectiveBucket, 'tema' => $topic] + $baseParams)); ?>"><span><?php echo esc_html($topic); ?></span><strong><?php echo esc_html((string) $count); ?></strong></a>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $row */
  private static function renderTicketCard(array $row, $policy): string
  {
    $pk = (int) ($row['_ID'] ?? 0);
    $logicalId = trim((string) ($row['id_ticket'] ?? '')) ?: (string) $pk;
    $status = trim((string) ($row['estado_comercial'] ?? 'Sin estado'));
    $subject = trim((string) ($row['asunto'] ?? '')) ?: 'Tarea comercial';
    $description = trim(wp_strip_all_tags((string) ($row['descripcion'] ?? ''), true));
    $assigned = trim((string) ($row['nombre_empleado'] ?? '')) ?: 'Sin asignar';
    $requester = trim((string) ($row['solicitante'] ?? '')) ?: 'Sin solicitante';
    $location = trim(implode(' · ', array_filter([(string) ($row['inmueble'] ?? ''), (string) ($row['barrio'] ?? ''), (string) ($row['direccion'] ?? '')])));
    $timestamp = (int) ($row['fecha_actualizacion'] ?? $row['fecha'] ?? 0);
    $canOpen = $policy instanceof CommercialAccessPolicy && $policy->canAct('ver_ticket');
    $slaLabel = trim((string) ($row['scm_sla_label'] ?? ''));
    $slaStatus = trim((string) ($row['scm_sla_status'] ?? ''));
    $attentionDays = (int) ($row['scm_attention_days'] ?? 0);
    $dueDays = (int) ($row['scm_sla_due_days'] ?? 0);
    ob_start();
?>
    <article class="commercial-ticket-card<?php echo $slaStatus === 'atrasado' ? ' commercial-ticket-card--overdue' : ''; ?>" data-commercial-ticket="<?php echo esc_attr((string) $pk); ?>">
      <header>
        <span class="commercial-ticket-id">#<?php echo esc_html($logicalId); ?></span>
        <span class="commercial-card-badges">
          <?php if ($slaLabel !== ''): ?><span class="commercial-sla-badge commercial-sla-badge--<?php echo esc_attr($slaStatus); ?>"><?php echo esc_html($slaLabel); ?></span><?php endif; ?>
          <span class="commercial-status-badge commercial-status-badge--<?php echo esc_attr(CommercialStatusCatalog::bucketForStatus($status)); ?>"><?php echo esc_html($status); ?></span>
        </span>
      </header>
      <h3><?php echo esc_html($subject); ?></h3>
      <?php if ($description !== ''): ?><p class="commercial-ticket-description"><?php echo esc_html(mb_strimwidth($description, 0, 190, '…', 'UTF-8')); ?></p><?php endif; ?>
      <dl class="commercial-ticket-meta">
        <div><dt><i class="far fa-user" aria-hidden="true"></i> Solicitante</dt><dd><?php echo esc_html($requester); ?></dd></div>
        <div><dt><i class="fas fa-user-tie" aria-hidden="true"></i> Responsable</dt><dd data-commercial-assignee-label><?php echo esc_html($assigned); ?></dd></div>
        <?php if ($location !== ''): ?><div><dt><i class="fas fa-location-dot" aria-hidden="true"></i> Inmueble</dt><dd><?php echo esc_html($location); ?></dd></div><?php endif; ?>
        <div><dt><i class="far fa-clock" aria-hidden="true"></i> Actualizado</dt><dd><?php echo esc_html(self::formatTimestamp($timestamp)); ?></dd></div>
        <?php if ($slaLabel !== ''): ?><div><dt><i class="fas fa-hourglass-half" aria-hidden="true"></i> Atención</dt><dd><?php echo esc_html((string) $attentionDays); ?> días<?php echo $dueDays > 0 ? ' / límite ' . esc_html((string) $dueDays) . ' días' : ''; ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($canOpen): ?><footer><button class="commercial-primary-btn" type="button" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>"><span>Ver tarea</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button></footer><?php endif; ?>
    </article>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<string,int> $pagination */
  private static function renderPagination(string $bucket, array $filters, array $pagination, string $baseUrl): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    if ($totalPages <= 1) return '';
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    $common = ['tab' => $bucket] + array_diff_key(self::filterParams($filters), ['page' => true]);
    ob_start();
?>
    <nav class="commercial-pagination" aria-label="Paginación de tareas">
      <?php if ($page > 1): ?><a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $page - 1])); ?>" aria-label="Página anterior">&lsaquo;</a><?php endif; ?>
      <?php for ($index = $start; $index <= $end; $index++): ?><a data-commercial-filter-link class="<?php echo $index === $page ? 'active' : ''; ?>" href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $index])); ?>"<?php echo $index === $page ? ' aria-current="page"' : ''; ?>><?php echo $index; ?></a><?php endfor; ?>
      <?php if ($page < $totalPages): ?><a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $page + 1])); ?>" aria-label="Página siguiente">&rsaquo;</a><?php endif; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $summary @param array<string,mixed> $filters */
  private static function renderSlaSummary(array $summary, array $filters): string
  {
    if ($summary === []) {
      return '';
    }
    $total = (int) ($summary['total'] ?? 0);
    $visible = (int) ($summary['visible_total'] ?? $total);
    $onTime = (int) ($summary['al_dia'] ?? 0);
    $overdue = (int) ($summary['atrasados'] ?? 0);
    $latePct = (int) ($summary['porcentaje_atraso'] ?? 0);
    $okPct = (int) ($summary['porcentaje_cumplimiento'] ?? 0);
    $activeSlaFilter = trim((string) ($filters['sla_filter'] ?? ''));
    ob_start();
?>
    <section class="commercial-sla-summary" aria-label="Control de tareas abiertas atrasadas">
      <div class="commercial-sla-chart" style="--commercial-sla-ok: <?php echo esc_attr((string) $okPct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) $latePct); ?>%;">
        <span><?php echo esc_html((string) $okPct); ?>%</span>
      </div>
      <div class="commercial-sla-copy">
        <span class="commercial-kicker">Control de atrasados</span>
        <h3>Tiempo de atención de tareas abiertas</h3>
        <p><?php echo esc_html((string) $overdue); ?> atrasadas de <?php echo esc_html((string) $total); ?> tareas abiertas<?php echo $activeSlaFilter !== '' ? ' · mostrando ' . esc_html((string) $visible) : ''; ?>.</p>
      </div>
      <div class="commercial-sla-kpis">
        <div><span>Al día</span><strong><?php echo esc_html((string) $onTime); ?></strong></div>
        <div class="is-late"><span>Atrasados</span><strong><?php echo esc_html((string) $overdue); ?></strong></div>
        <div><span>% atraso</span><strong><?php echo esc_html((string) $latePct); ?>%</strong></div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,mixed> $options */
  private static function selectFromOptions(string $id, string $name, array $options, string $selected): string
  {
    $html = '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="">Todos</option>';
    foreach ($options as $option) {
      $value = trim((string) $option);
      if ($value === '') {
        continue;
      }
      $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($value) . '</option>';
    }
    return $html . '</select>';
  }

  private static function selectBusinessOptions(string $id, string $name, string $selected): string
  {
    $options = ['Arriendo', 'Venta', 'Arriendo/Venta'];
    $html = '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="">Todas</option>';
    foreach ($options as $value) {
      $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($value) . '</option>';
    }
    return $html . '</select>';
  }

  /** @param array<int,array<string,string>> $options */
  private static function selectEmployeeOptions(string $id, string $name, array $options, string $selected): string
  {
    $html = '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="">Todos</option>';
    foreach ($options as $option) {
      $value = trim((string) ($option['id'] ?? ''));
      $label = trim((string) ($option['name'] ?? $value));
      if ($value === '' || $label === '') {
        continue;
      }
      $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
    }
    return $html . '</select>';
  }

  /** @param array<string,mixed> $filters @return array<string,string> */
  private static function filterParams(array $filters): array
  {
    $keys = [
      'estado', 'mis_bucket', 'busqueda', 'id_empleado', 'ticket_id', 'solicitante', 'celular', 'correo',
      'inmueble', 'barrio', 'medio', 'prioridad', 'tema', 'seguimiento', 'fecha_desde', 'fecha_hasta',
      'encargado_seguimiento', 'fecha_seguimiento_desde', 'fecha_seguimiento_hasta', 'sin_actualizar',
      'estado_administrativo', 'sla_filter', 'page',
    ];
    $params = [];
    foreach ($keys as $key) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value !== '') {
        $params[$key] = $value;
      }
    }
    return $params;
  }

  /** @param array<string,mixed> $filters */
  private static function myTasksBucket(array $filters, string $fallback = ''): string
  {
    $bucket = trim($fallback) !== '' ? trim($fallback) : trim((string) ($filters['mis_bucket'] ?? 'abiertos'));
    return in_array($bucket, ['abiertos', 'postergados', 'cerrados'], true) ? $bucket : 'abiertos';
  }

  private static function myTaskStageLabel(string $bucket): string
  {
    return [
      'abiertos' => 'abiertas',
      'postergados' => 'postergadas',
      'cerrados' => 'cerradas',
    ][$bucket] ?? 'abiertas';
  }

  /** @param array<string,mixed> $filters @return array<string,string> */
  private static function globalFilterParams(array $filters): array
  {
    $employee = trim((string) ($filters['id_empleado'] ?? ''));
    return $employee !== '' ? ['id_empleado' => $employee] : [];
  }

  /** @param array<string,mixed> $config @param array<int,array<string,string>> $employees */
  private static function renderCalendar(array $config, array $employees): string
  {
    $employeeJson = json_encode($employees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $allowedCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? $config['calendar_allowed_cargos'] : ['9', '10', '17'];
    ob_start();
?>
    <div class="scm-calendar-panel" data-scm-calendar-panel
      data-calendar-app-url="<?php echo esc_attr((string) ($config['calendar_app_url'] ?? '')); ?>"
      data-calendar-api-url="<?php echo esc_attr((string) ($config['calendar_api_url'] ?? '')); ?>"
      data-calendar-current-employee-id="<?php echo esc_attr((string) ($config['calendar_current_employee_id'] ?? '')); ?>"
      data-calendar-allowed-cargos="<?php echo esc_attr(implode(',', $allowedCargos)); ?>"
      data-calendar-employees-json="<?php echo esc_attr($employeeJson ?: '[]'); ?>">
      <div class="scm-status-topic-head scm-calendar-head"><div><span class="commercial-kicker">Equipo comercial</span><h2>Calendario</h2><p>Agenda y consulta actividades de Consultores de Arriendo, Consultores de Venta y Proveedores de Consultoría Comercial Inmobiliaria.</p></div><div class="scm-calendar-head-actions"><span class="scm-status-count"><strong data-scm-calendar-total>0</strong> eventos</span><button type="button" class="scm-btn-primary btn btn-primary" data-scm-calendar-open-create data-calendar-mode="single">Crear evento</button><button type="button" class="scm-case-work-btn" data-scm-calendar-open-create data-calendar-mode="multiple">Evento múltiple</button><button type="button" class="scm-case-work-btn" data-scm-calendar-open-pending>Eventos pendientes</button><button type="button" class="scm-case-work-btn scm-calendar-report-btn" data-scm-calendar-open-report>Informe del día</button></div></div>
      <div class="scm-calendar-kpis"><div class="scm-kpi"><div class="scm-kpi-label">Pendientes</div><div class="scm-kpi-value" data-scm-calendar-pending>0</div></div><div class="scm-kpi"><div class="scm-kpi-label">Realizados</div><div class="scm-kpi-value" data-scm-calendar-done>0</div></div><div class="scm-kpi"><div class="scm-kpi-label">Hoy</div><div class="scm-kpi-value" data-scm-calendar-today>0</div></div><div class="scm-kpi"><div class="scm-kpi-label">Mes visible</div><div class="scm-kpi-value scm-calendar-range-value" data-scm-calendar-range><?php echo esc_html(date('Y-m-d')); ?></div></div></div>
      <section class="scm-calendar-card scm-calendar-filter-card"><form class="scm-calendar-filter-form" data-scm-calendar-filters autocomplete="off"><div class="scm-grid"><div class="scm-field"><label>Funcionario</label><select name="id_empleado" data-scm-calendar-filter-employees><option value="">Selecciona funcionario</option></select></div><div class="scm-field"><label>Categoría</label><select name="id_categoria" data-scm-calendar-filter-categories><option value="">Todas</option></select></div><div class="scm-field"><label>Estado</label><select name="estado"><option value="">Todos</option><option value="No" selected>Pendientes</option><option value="Si">Realizados</option></select></div></div><div class="scm-actions"><button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button><button class="scm-btn-secondary btn btn-outline" type="button" data-scm-calendar-clear>Limpiar</button><span class="scm-spinner" data-scm-calendar-spinner aria-label="Cargando"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span></div></form></section>
      <div class="scm-calendar-layout"><section class="scm-calendar-card scm-calendar-board-card"><div class="scm-calendar-card-head"><div><span class="scm-calendar-action-kicker">Vista mensual</span><h4 data-scm-calendar-title>Calendario</h4><p>Selecciona un día para revisar la agenda.</p></div><div class="scm-calendar-month-actions"><button type="button" class="scm-case-work-btn" data-scm-calendar-prev aria-label="Mes anterior">&lsaquo;</button><button type="button" class="scm-case-work-btn" data-scm-calendar-today-btn>Hoy</button><button type="button" class="scm-case-work-btn" data-scm-calendar-next aria-label="Mes siguiente">&rsaquo;</button></div></div><div class="scm-calendar-weekdays" aria-hidden="true"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div><div class="scm-calendar-month-grid" data-scm-calendar-grid aria-live="polite"><div class="scm-calendar-loading">Cargando calendario…</div></div></section><section class="scm-calendar-card scm-calendar-day-card"><div class="scm-calendar-card-head"><div><span class="scm-calendar-action-kicker">Agenda del día</span><h4 data-scm-calendar-day-title>Selecciona un día</h4><p data-scm-calendar-day-subtitle>Los eventos respetan los filtros seleccionados.</p></div><button type="button" class="scm-case-work-btn" data-scm-calendar-refresh>Actualizar</button></div><div class="scm-calendar-events" data-scm-calendar-events><div class="scm-empty scm-empty-cards">Selecciona un día del calendario.</div></div><button type="button" class="scm-btn-primary btn btn-primary scm-calendar-day-create" data-scm-calendar-open-create data-calendar-mode="single">Crear evento para este día</button></section></div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $employeeCargoIds */
  private static function renderPermissions(CommercialAccessPolicy $policy, array $employeeCargoIds = []): string
  {
    $permissions = $policy->permissions();
    $cargos = $policy->cargoOptions();
    $adminCargoIds = array_flip($policy->adminCargoIds());
    $employeeCargoSet = array_flip(array_values(array_unique(array_filter(array_map(
      static fn($id): string => trim((string) $id),
      $employeeCargoIds
    ), static fn(string $id): bool => $id !== ''))));
    ob_start();
?>
    <div class="commercial-modal" id="commercial-permissions-modal" role="dialog" aria-modal="true" aria-labelledby="commercial-permissions-title" aria-hidden="true">
      <div class="commercial-modal-card commercial-permissions-card">
        <header><div><span class="commercial-kicker">Configuración</span><h2 id="commercial-permissions-title">Visibilidad y acciones por cargo</h2><p>Los permisos se validan tanto en la interfaz como en el servidor.</p></div><button type="button" class="commercial-modal-close" data-commercial-close-permissions aria-label="Cerrar">&times;</button></header>
        <form id="commercial-permissions-form">
          <div class="commercial-permission-intro">
            <div>
              <strong>Acceso total</strong>
              <span>Marca aquí los cargos que pueden ver todo el panel, administrar permisos y operar todas las tareas.</span>
            </div>
            <div>
              <strong>Funcionarios operativos</strong>
              <span>Define qué cargos aparecen en filtros, reasignación y búsquedas de responsables comerciales.</span>
            </div>
          </div>
          <div class="commercial-permissions-grid">
            <?php foreach ($cargos as $cargo): $current = $permissions[$cargo['id']] ?? ['views' => array_keys(CommercialAccessPolicy::VIEWS), 'actions' => array_keys(CommercialAccessPolicy::ACTIONS)]; ?>
              <fieldset class="commercial-permission-card" data-cargo="<?php echo esc_attr($cargo['id']); ?>">
                <legend><?php echo esc_html($cargo['name']); ?> <small>ID <?php echo esc_html($cargo['id']); ?> · <?php echo esc_html((string) $cargo['total']); ?> activos</small></legend>
                <label class="commercial-permission-master<?php echo isset($adminCargoIds[$cargo['id']]) ? ' is-checked' : ''; ?>">
                  <input type="checkbox" name="admin_cargos[]" value="<?php echo esc_attr($cargo['id']); ?>"<?php checked(isset($adminCargoIds[$cargo['id']])); ?>>
                  <span><strong>Acceso total</strong><small>Puede administrar permisos y ver todas las tareas del equipo.</small></span>
                </label>
                <input type="hidden" name="permissions[<?php echo esc_attr($cargo['id']); ?>][configured]" value="1">
                <div><strong>Vistas</strong><?php foreach (CommercialAccessPolicy::VIEWS as $key => $label): ?><label><input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][views][]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $current['views'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div>
                <div><strong>Acciones</strong><?php foreach (CommercialAccessPolicy::ACTIONS as $key => $label): ?><label><input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][actions][]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $current['actions'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div>
              </fieldset>
            <?php endforeach; ?>
          </div>
          <section class="commercial-permission-wide">
            <div class="commercial-permission-wide-head">
              <span class="commercial-kicker">Funcionarios operativos</span>
              <h3>Cargos visibles en filtros y reasignación</h3>
              <p>Solo los funcionarios activos con estos cargos aparecen como responsables comerciales. Esto también ayuda a enlazar correctamente la sesión con el id_empleado.</p>
            </div>
            <div class="commercial-permission-cargo-grid">
              <?php foreach ($cargos as $cargo): ?>
                <label>
                  <input type="checkbox" name="employee_cargo_ids[]" value="<?php echo esc_attr($cargo['id']); ?>"<?php checked(isset($employeeCargoSet[$cargo['id']])); ?>>
                  <span><?php echo esc_html($cargo['name']); ?><small>ID <?php echo esc_html($cargo['id']); ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>
          <footer><span data-commercial-permissions-message aria-live="polite"></span><button type="button" class="commercial-secondary-btn" data-commercial-close-permissions>Cancelar</button><button type="submit" class="commercial-primary-btn">Guardar configuración</button></footer>
        </form>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $params */
  private static function url(string $baseUrl, array $params): string
  {
    $params = array_filter($params, static fn($value): bool => $value !== '' && $value !== null);
    return $baseUrl . '/index.php' . ($params ? '?' . http_build_query($params) : '');
  }

  private static function formatTimestamp(int $timestamp): string
  {
    return $timestamp > 0 ? date('d/m/Y h:i a', $timestamp) : 'Sin fecha';
  }
}
