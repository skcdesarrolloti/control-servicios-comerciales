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
    $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
    $policy = $data['policy'] ?? null;
    $runtime = is_array($data['runtime'] ?? null) ? $data['runtime'] : [];
    $runtimeJson = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $views = is_array($data['visible_views'] ?? null) ? $data['visible_views'] : [];
    $calendarEmployees = is_array($data['calendar_employees'] ?? null) ? $data['calendar_employees'] : [];
    $ticketEmployees = is_array($data['ticket_employees'] ?? null) ? $data['ticket_employees'] : [];
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
  <title>Control de Servicios Comerciales</title>
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
        <span class="commercial-brand-title">Control Servicios Comerciales</span>
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

    <section class="commercial-hero">
      <div>
        <span class="commercial-kicker">Gestión centralizada</span>
        <h1>Tickets comerciales</h1>
        <p>Consulta la operación por estado comercial, administra responsables y coordina la agenda del equipo.</p>
      </div>
    </section>

    <?php echo self::renderGlobalFilters($filters, $ticketEmployees, $baseUrl); ?>
    <?php echo self::renderTabs($views, $bucket, $filters, $tabCounts, $baseUrl); ?>

    <?php if ($bucket === 'sin_acceso'): ?>
      <section class="scm-tab-panel active" id="scm-panel-sin_acceso">
        <div class="commercial-empty"><i class="fas fa-lock" aria-hidden="true"></i><h2>Sin vistas habilitadas</h2><p>Tu cargo no tiene secciones visibles en este panel. Solicita acceso a un administrador.</p></div>
      </section>
    <?php else: ?>
      <section class="scm-tab-panel commercial-panel<?php echo $bucket !== 'calendario' ? ' active' : ''; ?>" id="commercial-tickets-panel" data-commercial-tickets-panel aria-live="polite">
        <?php if ($bucket !== 'calendario'): ?>
          <?php echo self::renderTickets($bucket, $result, $filters, $ticketEmployees, $filterOptions, $policy, $baseUrl); ?>
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
        <div class="commercial-case-content" data-commercial-case-content><div class="commercial-case-loading"><span></span><span></span><span></span><p>Cargando información del caso…</p></div></div>
      </div>
    </div>

    <?php echo CommercialGuideView::render(); ?>
    <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
      <?php echo self::renderPermissions($policy); ?>
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
    $icons = ['abiertos' => 'fa-inbox', 'postergados' => 'fa-clock-rotate-left', 'cerrados' => 'fa-circle-check', 'calendario' => 'fa-calendar-days'];
    $globalParams = self::globalFilterParams($filters);
    ob_start();
?>
    <nav class="commercial-tabs" data-commercial-tabs aria-label="Secciones del panel">
      <?php foreach (CommercialAccessPolicy::VIEWS as $viewKey => $label): ?>
        <?php if (!in_array($viewKey, $views, true)) continue; ?>
        <?php $count = (int) ($tabCounts[$viewKey] ?? 0); ?>
        <a class="commercial-tab<?php echo $viewKey === $bucket ? ' active' : ''; ?>" data-commercial-tab="<?php echo esc_attr($viewKey); ?>" href="<?php echo esc_url(self::url($baseUrl, ['tab' => $viewKey] + $globalParams)); ?>"<?php echo $viewKey === $bucket ? ' aria-current="page"' : ''; ?>>
          <i class="fas <?php echo esc_attr($icons[$viewKey]); ?>" aria-hidden="true"></i>
          <span><?php echo esc_html($label); ?></span>
          <?php if ($viewKey !== 'calendario'): ?><strong><?php echo esc_html((string) $count); ?></strong><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees */
  public static function renderGlobalFilters(array $filters, array $ticketEmployees, string $baseUrl): string
  {
    $selectedEmployee = trim((string) ($filters['id_empleado'] ?? ''));
    $tab = trim((string) ($filters['tab'] ?? 'abiertos'));
    if ($tab === '') {
      $tab = 'abiertos';
    }
    ob_start();
?>
    <form class="commercial-global-filter" data-commercial-global-filter-form method="get" action="<?php echo esc_url($baseUrl . '/index.php'); ?>">
      <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
      <div>
        <span class="commercial-kicker">Filtro general</span>
        <label for="commercial-global-employee">Funcionario responsable</label>
      </div>
      <select id="commercial-global-employee" name="id_empleado">
        <option value="">Todos los funcionarios</option>
        <?php foreach ($ticketEmployees as $employee): ?>
          <option value="<?php echo esc_attr($employee['id']); ?>"<?php selected($selectedEmployee, $employee['id']); ?>><?php echo esc_html($employee['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <button class="commercial-primary-btn" type="submit">Actualizar</button>
      <?php if ($selectedEmployee !== ''): ?><a class="commercial-secondary-btn" data-commercial-global-clear href="<?php echo esc_url(self::url($baseUrl, ['tab' => $tab])); ?>">Limpiar</a><?php endif; ?>
    </form>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $result @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions */
  public static function renderTickets(string $bucket, array $result, array $filters, array $ticketEmployees, array $filterOptions, $policy, string $baseUrl): string
  {
    $bucketDef = CommercialStatusCatalog::buckets()[$bucket] ?? CommercialStatusCatalog::buckets()['abiertos'];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
    $slaSummary = is_array($result['sla_summary'] ?? null) ? $result['sla_summary'] : [];
    $bucketTotal = array_sum(array_intersect_key($counts, array_flip($bucketDef['statuses'])));
    $baseFilterParams = self::filterParams($filters);
    ob_start();
?>
    <div class="commercial-section-head">
      <div><span class="commercial-kicker">Estado del embudo</span><h2><?php echo esc_html($bucketDef['label']); ?></h2><p><?php echo esc_html($bucketDef['description']); ?></p></div>
      <span class="commercial-total"><strong><?php echo esc_html((string) $bucketTotal); ?></strong> tickets</span>
    </div>

    <div class="commercial-status-strip" aria-label="Estados de <?php echo esc_attr(mb_strtolower($bucketDef['label'])); ?>">
      <a class="commercial-status-chip<?php echo empty($filters['estado']) ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>"><span>Todos</span><strong><?php echo esc_html((string) $bucketTotal); ?></strong></a>
      <?php foreach ($bucketDef['statuses'] as $status): ?>
        <a class="commercial-status-chip<?php echo (($filters['estado'] ?? '') === $status) ? ' active' : ''; ?>" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'estado' => $status] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>"><span><?php echo esc_html($status); ?></span><strong><?php echo esc_html((string) ($counts[$status] ?? 0)); ?></strong></a>
      <?php endforeach; ?>
    </div>

    <?php if ($bucket === 'abiertos'): ?>
      <?php echo self::renderSlaSummary($slaSummary, $filters); ?>
    <?php endif; ?>

    <form class="commercial-filter-card" data-commercial-filter-form method="get" action="<?php echo esc_url($baseUrl . '/index.php'); ?>">
      <input type="hidden" name="tab" value="<?php echo esc_attr($bucket); ?>">
      <?php if (!empty($filters['id_empleado'])): ?><input type="hidden" name="id_empleado" value="<?php echo esc_attr((string) $filters['id_empleado']); ?>"><?php endif; ?>
      <?php if (!empty($filters['estado'])): ?><input type="hidden" name="estado" value="<?php echo esc_attr((string) $filters['estado']); ?>"><?php endif; ?>
      <div class="commercial-field commercial-field--wide"><label for="commercial-search">Buscar</label><input id="commercial-search" type="search" name="busqueda" value="<?php echo esc_attr((string) ($filters['busqueda'] ?? '')); ?>" placeholder="Ticket, asunto, solicitante, inmueble…"></div>
      <div class="commercial-field"><label for="commercial-ticket-id">ID ticket</label><input id="commercial-ticket-id" type="text" name="ticket_id" value="<?php echo esc_attr((string) ($filters['ticket_id'] ?? '')); ?>" placeholder="Ej: 8604"></div>
      <?php if ($bucket === 'abiertos'): ?><div class="commercial-field"><label for="commercial-sla-filter">Tiempo de atención</label><select id="commercial-sla-filter" name="sla_filter"><option value="">Todos</option><option value="atrasado"<?php selected((string) ($filters['sla_filter'] ?? ''), 'atrasado'); ?>>Atrasados</option><option value="al_dia"<?php selected((string) ($filters['sla_filter'] ?? ''), 'al_dia'); ?>>Al día</option></select></div><?php endif; ?>
      <?php if (!empty($filters['estado'])): ?><div class="commercial-locked-filter"><span>Estado comercial</span><strong><?php echo esc_html((string) $filters['estado']); ?></strong><a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + array_diff_key($baseFilterParams, ['estado' => true, 'page' => true]))); ?>">Ver todos</a></div><?php endif; ?>
      <div class="commercial-field"><label for="commercial-requester">Solicitante</label><input id="commercial-requester" type="text" name="solicitante" value="<?php echo esc_attr((string) ($filters['solicitante'] ?? '')); ?>" placeholder="Nombre"></div>
      <div class="commercial-field"><label for="commercial-phone">Celular</label><input id="commercial-phone" type="text" name="celular" value="<?php echo esc_attr((string) ($filters['celular'] ?? '')); ?>" placeholder="Número"></div>
      <div class="commercial-field"><label for="commercial-email">Correo</label><input id="commercial-email" type="text" name="correo" value="<?php echo esc_attr((string) ($filters['correo'] ?? '')); ?>" placeholder="correo@dominio.com"></div>
      <div class="commercial-field"><label for="commercial-property">Inmueble</label><input id="commercial-property" type="text" name="inmueble" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>" placeholder="Código o dirección"></div>
      <div class="commercial-field"><label for="commercial-neighborhood">Barrio</label><?php echo self::selectFromOptions('commercial-neighborhood', 'barrio', $filterOptions['barrios'] ?? [], (string) ($filters['barrio'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-topic">Tema</label><?php echo self::selectFromOptions('commercial-topic', 'tema', $filterOptions['temas'] ?? [], (string) ($filters['tema'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-medium">Medio</label><?php echo self::selectFromOptions('commercial-medium', 'medio', $filterOptions['medios'] ?? [], (string) ($filters['medio'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-priority">Prioridad</label><?php echo self::selectFromOptions('commercial-priority', 'prioridad', $filterOptions['prioridades'] ?? [], (string) ($filters['prioridad'] ?? '')); ?></div>
      <div class="commercial-field"><label for="commercial-follow-up">Seguimiento</label><select id="commercial-follow-up" name="seguimiento"><option value="">Todos</option><option value="Si"<?php selected((string) ($filters['seguimiento'] ?? ''), 'Si'); ?>>Con seguimiento</option><option value="No"<?php selected((string) ($filters['seguimiento'] ?? ''), 'No'); ?>>Sin seguimiento</option></select></div>
      <div class="commercial-field"><label for="commercial-date-from">Fecha desde</label><input id="commercial-date-from" type="date" name="fecha_desde" value="<?php echo esc_attr((string) ($filters['fecha_desde'] ?? '')); ?>"></div>
      <div class="commercial-field"><label for="commercial-date-to">Fecha hasta</label><input id="commercial-date-to" type="date" name="fecha_hasta" value="<?php echo esc_attr((string) ($filters['fecha_hasta'] ?? '')); ?>"></div>
      <div class="commercial-filter-actions"><button class="commercial-primary-btn" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Filtrar</button><a class="commercial-secondary-btn" data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + self::globalFilterParams($filters))); ?>">Limpiar</a></div>
    </form>

    <?php if ($rows === []): ?>
      <div class="commercial-empty"><i class="far fa-folder-open" aria-hidden="true"></i><h3>Sin tickets en esta vista</h3><p>Prueba con otro estado o limpia los filtros actuales.</p></div>
    <?php else: ?>
      <div class="commercial-ticket-grid">
        <?php foreach ($rows as $row): echo self::renderTicketCard($row, $policy); endforeach; ?>
      </div>
      <?php echo self::renderPagination($bucket, $filters, $pagination, $baseUrl); ?>
    <?php endif; ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $row */
  private static function renderTicketCard(array $row, $policy): string
  {
    $pk = (int) ($row['_ID'] ?? 0);
    $logicalId = trim((string) ($row['id_ticket'] ?? '')) ?: (string) $pk;
    $status = trim((string) ($row['estado_comercial'] ?? 'Sin estado'));
    $subject = trim((string) ($row['asunto'] ?? '')) ?: 'Ticket comercial';
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
      <?php if ($canOpen): ?><footer><button class="commercial-primary-btn" type="button" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>"><span>Ver caso</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button></footer><?php endif; ?>
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
    <nav class="commercial-pagination" aria-label="Paginación de tickets">
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
    <section class="commercial-sla-summary" aria-label="Control de tickets abiertos atrasados">
      <div class="commercial-sla-chart" style="--commercial-sla-ok: <?php echo esc_attr((string) $okPct); ?>%; --commercial-sla-late: <?php echo esc_attr((string) $latePct); ?>%;">
        <span><?php echo esc_html((string) $okPct); ?>%</span>
      </div>
      <div class="commercial-sla-copy">
        <span class="commercial-kicker">Control de atrasados</span>
        <h3>Tiempo de atención de tickets abiertos</h3>
        <p><?php echo esc_html((string) $overdue); ?> atrasados de <?php echo esc_html((string) $total); ?> tickets abiertos<?php echo $activeSlaFilter !== '' ? ' · mostrando ' . esc_html((string) $visible) : ''; ?>.</p>
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

  /** @param array<string,mixed> $filters @return array<string,string> */
  private static function filterParams(array $filters): array
  {
    $keys = [
      'estado', 'busqueda', 'id_empleado', 'ticket_id', 'solicitante', 'celular', 'correo',
      'inmueble', 'barrio', 'medio', 'prioridad', 'tema', 'seguimiento', 'fecha_desde', 'fecha_hasta',
      'sla_filter', 'page',
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

  private static function renderPermissions(CommercialAccessPolicy $policy): string
  {
    $permissions = $policy->permissions();
    ob_start();
?>
    <div class="commercial-modal" id="commercial-permissions-modal" role="dialog" aria-modal="true" aria-labelledby="commercial-permissions-title" aria-hidden="true">
      <div class="commercial-modal-card commercial-permissions-card">
        <header><div><span class="commercial-kicker">Configuración</span><h2 id="commercial-permissions-title">Visibilidad y acciones por cargo</h2><p>Los permisos se validan tanto en la interfaz como en el servidor.</p></div><button type="button" class="commercial-modal-close" data-commercial-close-permissions aria-label="Cerrar">&times;</button></header>
        <form id="commercial-permissions-form">
          <div class="commercial-permissions-grid">
            <?php foreach ($policy->cargoOptions() as $cargo): $current = $permissions[$cargo['id']] ?? ['views' => array_keys(CommercialAccessPolicy::VIEWS), 'actions' => array_keys(CommercialAccessPolicy::ACTIONS)]; ?>
              <fieldset class="commercial-permission-card" data-cargo="<?php echo esc_attr($cargo['id']); ?>"><legend><?php echo esc_html($cargo['name']); ?> <small>ID <?php echo esc_html($cargo['id']); ?> · <?php echo esc_html((string) $cargo['total']); ?> activos</small></legend><input type="hidden" name="permissions[<?php echo esc_attr($cargo['id']); ?>][configured]" value="1"><div><strong>Vistas</strong><?php foreach (CommercialAccessPolicy::VIEWS as $key => $label): ?><label><input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][views][]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $current['views'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div><div><strong>Acciones</strong><?php foreach (CommercialAccessPolicy::ACTIONS as $key => $label): ?><label><input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][actions][]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $current['actions'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div></fieldset>
            <?php endforeach; ?>
          </div>
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
