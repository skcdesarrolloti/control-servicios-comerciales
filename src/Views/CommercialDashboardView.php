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
    $bucket = (string) ($data['bucket'] ?? 'inicio');
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
    $subtab = (string) ($data['subtab'] ?? 'mine');
    $topicHierarchy = is_array($data['topic_hierarchy'] ?? null) ? $data['topic_hierarchy'] : [];
    $recentTickets = is_array($data['recent_tickets'] ?? null) ? $data['recent_tickets'] : [];
    $recentCount = (int) ($data['recent_count'] ?? count($recentTickets));
    $baseUrl = rtrim((string) ($data['base_url'] ?? ''), '/');
    $overdueCount = (int) ($homeDashboard['sla_summary']['atrasados'] ?? 0);

    $userName = trim(Auth::user());
    if ($userName === '') {
      $userName = 'Usuario';
    }
    $userRole = trim(Auth::userRol());
    if ($userRole === '') {
      $userRole = 'Gestor Operativo';
    }
    $userInitials = self::initials($userName);

    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <meta content="web_standard" name="shell-type">
  <title>Control Operativo de Tareas Comerciales · SuCasa Inmobiliaria</title>
  <link rel="icon" href="<?php echo esc_url(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <style>
    @layer base {
      html, body { margin: 0; padding: 0; }
      body { overscroll-behavior: none; }
      main > :first-child { margin-top: 0 !important; }
      main > :last-child { margin-bottom: 0 !important; }
    }
    ::-webkit-scrollbar { display: none; }
    .commercial-modal,
    .commercial-guide,
    #scm-guide-modal {
      display: none;
    }
    .commercial-modal.open,
    .commercial-modal.active,
    .commercial-analysis-modal.open,
    .commercial-analysis-modal.active,
    .commercial-guide.open,
    .commercial-guide.active,
    #scm-guide-modal.open,
    #scm-guide-modal.active {
      display: flex !important;
    }
    .commercial-analysis-modal {
      display: none;
    }
    .commercial-modal-open {
      overflow: hidden;
    }
    /* Dropdowns y Submenús de Navegación */
    [data-commercial-dropdown-menu] {
      display: none;
    }
    [data-commercial-dropdown-menu].is-open,
    .group\/nav:hover > [data-commercial-dropdown-menu],
    [data-commercial-dropdown]:hover > [data-commercial-dropdown-menu] {
      display: block !important;
    }
    [data-commercial-subflyout] {
      display: none;
    }
    [data-commercial-subflyout].is-open,
    .group\/sub:hover > [data-commercial-subflyout],
    [data-commercial-subgroup]:hover > [data-commercial-subflyout],
    [data-commercial-subgroup].is-open > [data-commercial-subflyout] {
      display: block !important;
    }
    .group\/nav:hover [data-commercial-dropdown-trigger] .material-symbols-outlined,
    [data-commercial-dropdown].is-open [data-commercial-dropdown-trigger] .material-symbols-outlined {
      transform: rotate(180deg);
    }
    [data-commercial-subgroup]:hover [data-commercial-subflyout-trigger] .material-symbols-outlined,
    [data-commercial-subgroup].is-open [data-commercial-subflyout-trigger] .material-symbols-outlined {
      transform: translateX(2px);
      color: #735c00;
    }
    .sicv-ai-icon-rotate {
      animation: sicv-guardian-search 1.15s ease-in-out infinite;
    }
    .sicv-search-modal__progress {
      display: flex;
      justify-content: center;
      gap: 8px;
    }
    .sicv-search-modal__progress span {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #F59E0B;
      animation: sicv-search-pulse 1s ease-in-out infinite;
    }
    .sicv-search-modal__progress span:nth-child(2) {
      animation-delay: 0.14s;
    }
    .sicv-search-modal__progress span:nth-child(3) {
      animation-delay: 0.28s;
    }
    @keyframes sicv-guardian-search {
      0%, 100% { transform: rotate(-8deg) scale(0.96); }
      50% { transform: rotate(8deg) scale(1.04); }
    }
    @keyframes sicv-search-pulse {
      0%, 100% { opacity: 0.35; transform: scale(0.78); }
      50% { opacity: 1; transform: scale(1); }
    }
    /* Estilos para Disponibilidad, Franja Horaria y Popups del Calendario */
    .swal2-popup .scm-calendar-availability-status {
      border-radius: 12px;
      display: grid;
      gap: 4px;
      padding: 12px !important;
      text-align: left;
    }
    .swal2-popup .scm-calendar-availability-status strong {
      font-size: 13px;
      font-weight: 700;
    }
    .swal2-popup .scm-calendar-availability-status span {
      font-size: 11px;
      font-weight: 500;
      line-height: 1.35;
    }
    .swal2-popup .scm-calendar-availability-status.is-free {
      background: #ecfdf5 !important;
      border: 1px solid #a7f3d0 !important;
      color: #047857 !important;
    }
    .swal2-popup .scm-calendar-availability-status.is-busy {
      background: #fef2f2 !important;
      border: 1px solid #fecaca !important;
      color: #b91c1c !important;
    }
    .swal2-popup .scm-calendar-full-agenda-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 600;
      color: #334155;
      cursor: pointer;
      margin-top: 8px;
    }
    .swal2-popup .scm-calendar-google-toggle {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 10px 14px;
      cursor: pointer;
      text-align: left;
    }
    .swal2-popup .scm-calendar-google-toggle input {
      margin-top: 3px;
    }
    .swal2-popup .scm-calendar-create-shell {
      text-align: left;
    }
    /* Grilla de Tiempo para Vistas Semanal y Diaria del Calendario */
    .scm-calendar-time-grid {
      display: grid;
      grid-template-columns: 56px repeat(7, minmax(110px, 1fr));
      grid-auto-rows: 24px;
      position: relative;
      background-color: #ffffff;
      border-radius: 1rem;
      border: 1px solid #e2e8f0;
      overflow-x: auto;
      max-height: 600px;
    }
    .scm-calendar-time-grid--day {
      grid-template-columns: 56px minmax(260px, 1fr);
    }
    .scm-calendar-time-gutter {
      font-size: 11px;
      font-weight: 500;
      color: #64748b;
      border-bottom: 1px solid #f1f5f9;
      border-right: 1px solid #e2e8f0;
      padding: 2px 6px;
      text-align: right;
      user-select: none;
      background-color: #f8fafc;
      line-height: 20px;
    }
    .scm-calendar-time-gutter--head {
      position: sticky;
      top: 0;
      z-index: 20;
      height: 48px;
      line-height: 44px;
      background-color: #f1f5f9;
      font-weight: 700;
    }
    .scm-calendar-week-head {
      position: sticky;
      top: 0;
      z-index: 20;
      height: 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background-color: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      border-right: 1px solid #f1f5f9;
      font-size: 12px;
      color: #475569;
      cursor: pointer;
      transition: background 0.15s;
    }
    .scm-calendar-week-head:hover {
      background-color: #f1f5f9;
    }
    .scm-calendar-week-head.is-today {
      color: #1e3c76;
      font-weight: 700;
    }
    .scm-calendar-week-head.is-today strong {
      background-color: #1e3c76;
      color: #ffffff;
      border-radius: 9999px;
      width: 22px;
      height: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .scm-calendar-week-head.is-selected {
      background-color: #ebf1fb;
      border-bottom: 2px solid #1e3c76;
    }
    .scm-calendar-time-slot {
      border-bottom: 1px dashed #f1f5f9;
      border-right: 1px solid #f1f5f9;
      background: transparent;
      cursor: pointer;
      transition: background 0.1s;
      padding: 0;
    }
    .scm-calendar-time-slot:hover {
      background-color: rgba(30, 60, 118, 0.06);
    }
    .scm-calendar-time-slot.is-selected-day {
      background-color: rgba(30, 60, 118, 0.02);
    }
    .scm-calendar-time-slot.is-today {
      background-color: rgba(30, 60, 118, 0.03);
    }
    .scm-calendar-time-slot.is-selecting {
      background-color: rgba(30, 60, 118, 0.15) !important;
    }
    .scm-calendar-time-event {
      grid-column-start: calc(var(--event-day, 0) + 2);
      grid-column-end: span 1;
      grid-row-start: calc(var(--event-start-slot, 0) + 2);
      grid-row-end: span var(--event-slot-span, 2);
      z-index: 10;
      margin: 1px 3px;
      padding: 3px 6px;
      border-radius: 8px;
      background-color: #ffffff;
      border-left: 3px solid var(--event-color, #1e3c76);
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      font-size: 11px;
      line-height: 1.25;
      text-align: left;
      overflow: hidden;
      cursor: pointer;
      transition: transform 0.1s, box-shadow 0.1s;
      display: flex;
      flex-direction: column;
    }
    .scm-calendar-time-event:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.12);
      z-index: 15;
    }
    .scm-calendar-time-event strong {
      font-weight: 600;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .scm-calendar-time-event em {
      font-style: normal;
      font-size: 10px;
      color: #64748b;
      margin-top: 1px;
    }
  </style>
  <script src="https://cdn.tailwindcss.com"></script>
  <script id="tailwind-config">
  tailwind.config = {
    "darkMode": "class",
    "theme": {
      "extend": {
        "boxShadow": {
          "modal": "0 25px 60px -15px rgba(6, 29, 73, 0.35)",
          "card": "0 2px 10px rgba(6, 29, 73, 0.04)"
        },
        "colors": {
          "brand": {
            "navy": "#061D49",
            "blue": "#1E3C76",
            "lightBlue": "#EBF1FB",
            "gold": "#F8CF4A",
            "goldHover": "#E5BD3B",
            "goldSoft": "#FEF9E7",
            "grayBg": "#F6F8FC",
            "border": "#E2E8F0"
          },
          "surface-container": "#e9edff",
          "tertiary": "#7d5700",
          "on-secondary": "#ffffff",
          "inverse-surface": "#0b2e67",
          "secondary": "#4b5d8c",
          "surface-container-lowest": "#ffffff",
          "on-tertiary-fixed-variant": "#5f4100",
          "tertiary-fixed": "#ffdea9",
          "error": "#ba1a1a",
          "secondary-fixed": "#dae2ff",
          "primary-container": "#f8cf4a",
          "surface-container-low": "#f2f3ff",
          "surface-bright": "#faf8ff",
          "error-container": "#ffdad6",
          "on-tertiary-container": "#785400",
          "surface-dim": "#cdd9ff",
          "on-secondary-fixed-variant": "#334573",
          "on-tertiary": "#ffffff",
          "primary": "#735c00",
          "on-primary": "#ffffff",
          "on-background": "#001944",
          "on-secondary-container": "#415381",
          "surface-container-high": "#e1e8ff",
          "surface-variant": "#d9e2ff",
          "on-error-container": "#93000a",
          "on-tertiary-fixed": "#271900",
          "on-primary-fixed-variant": "#574500",
          "on-error": "#ffffff",
          "surface-tint": "#735c00",
          "on-surface": "#001944",
          "on-primary-container": "#6f5800",
          "outline-variant": "#d0c6ae",
          "on-secondary-fixed": "#021945",
          "secondary-container": "#b6c8fe",
          "surface-container-highest": "#d9e2ff",
          "primary-fixed": "#ffe087",
          "tertiary-fixed-dim": "#fcbb3b",
          "primary-fixed-dim": "#eac23e",
          "inverse-primary": "#eac23e",
          "on-surface-variant": "#4d4635",
          "tertiary-container": "#ffcb6f",
          "secondary-fixed-dim": "#b3c5fb",
          "inverse-on-surface": "#edf0ff",
          "surface": "#faf8ff",
          "outline": "#7f7662",
          "on-primary-fixed": "#231a00",
          "background": "#faf8ff"
        },
        "borderRadius": {
          "DEFAULT": "0.25rem",
          "lg": "0.5rem",
          "xl": "0.75rem",
          "full": "9999px"
        },
        "spacing": {
          "space-xl": "2.5rem",
          "margin-mobile": "1rem",
          "space-lg": "1.5rem",
          "space-xs": "0.25rem",
          "gutter": "1.5rem",
          "space-sm": "0.5rem",
          "margin": "2rem",
          "gutter-mobile": "1rem",
          "space-md": "1rem"
        },
        "fontFamily": {
          "label-md": ["Poppins"],
          "headline-md": ["Poppins"],
          "label-lg": ["Poppins"],
          "headline-lg": ["Poppins"],
          "display-lg": ["Poppins"],
          "headline-xl": ["Poppins"],
          "body-lg": ["Poppins"],
          "label-sm": ["Poppins"],
          "body-sm": ["Poppins"],
          "title-md": ["Poppins"],
          "display-lg-mobile": ["Poppins"],
          "headline-sm": ["Poppins"],
          "headline-xl-mobile": ["Poppins"],
          "body-md": ["Poppins"]
        },
        "fontSize": {
          "label-md": ["12px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "500" }],
          "headline-md": ["22px", { "lineHeight": "30px", "letterSpacing": "-0.005em", "fontWeight": "600" }],
          "label-lg": ["14px", { "lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600" }],
          "headline-lg": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
          "display-lg": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
          "headline-xl": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }],
          "body-lg": ["16px", { "lineHeight": "26px", "fontWeight": "400" }],
          "label-sm": ["10px", { "lineHeight": "14px", "letterSpacing": "0.04em", "fontWeight": "600" }],
          "body-sm": ["12px", { "lineHeight": "18px", "fontWeight": "400" }],
          "title-md": ["16px", { "lineHeight": "24px", "fontWeight": "500" }],
          "display-lg-mobile": ["32px", { "lineHeight": "40px", "letterSpacing": "-0.01em", "fontWeight": "700" }],
          "headline-sm": ["18px", { "lineHeight": "26px", "fontWeight": "600" }],
          "headline-xl-mobile": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
          "body-md": ["14px", { "lineHeight": "22px", "fontWeight": "400" }]
        }
      }
    }
  };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
</head>
<body class="bg-background font-body-md text-on-surface antialiased">
  <div id="scm-app" class="scm-wrap w-full bg-background min-h-screen flex flex-col" data-scm-runtime="<?php echo esc_attr((string) $runtimeJson); ?>">
  <!-- Header Principal -->
  <header class="fixed top-0 left-0 right-0 z-50 bg-inverse-surface shadow-[0_1px_8px_rgba(0,0,0,0.06)] overflow-visible">
    <div class="h-28 w-full overflow-visible">
      <!-- Top Row: Identidad, Búsqueda y Acciones Rápidas -->
      <div class="h-16 px-margin flex items-center justify-between">
        <div class="flex items-center gap-space-md">
          <a class="flex items-center gap-space-sm hover:opacity-95 transition-opacity" href="<?php echo esc_url($baseUrl . '/index.php'); ?>">
            <div class="w-9 h-9 rounded-xl bg-primary-container flex items-center justify-center shadow-sm">
              <span class="material-symbols-outlined text-on-surface text-[22px]">domain</span>
            </div>
            <div>
              <span class="font-headline-sm text-headline-sm text-on-primary tracking-tight">SuCasa Inmobiliaria</span>
              <span class="hidden xl:inline ml-space-xs font-label-sm text-label-sm text-secondary-fixed opacity-80 uppercase">Servicios Comerciales</span>
            </div>
          </a>
          <div class="hidden md:flex items-center ml-space-lg bg-surface-container-lowest/10 rounded-xl px-space-md py-space-xs focus-within:bg-surface-container-lowest/20 transition-all border border-white/5 focus-within:border-white/20">
            <button type="button" id="quick-search-btn" class="flex items-center text-secondary-fixed hover:text-white transition-colors mr-space-xs cursor-pointer" title="Buscar">
              <span class="material-symbols-outlined text-[18px]">search</span>
            </button>
            <input type="text" id="quick-search-nav" value="<?php echo esc_attr((string) ($filters['busqueda'] ?? '')); ?>" placeholder="Buscar tarea, cliente, correo o celular..." class="bg-transparent border-none outline-none font-body-sm text-body-sm text-on-primary placeholder:text-secondary-fixed/70 mr-space-xs w-48 sm:w-64 lg:w-72 focus:w-80 transition-all">
            <kbd class="font-label-sm text-label-sm bg-surface-container-lowest/15 text-secondary-fixed px-space-xs py-0.5 rounded font-mono text-[11px] border border-white/10" id="quick-search-kbd">Ctrl+K</kbd>
          </div>
        </div>

        <div class="flex items-center gap-space-md">
          <button type="button" class="hidden lg:flex items-center gap-space-xs font-label-md text-label-md text-secondary-fixed hover:text-on-primary transition-colors cursor-pointer" id="scm-open-guide">
            <span class="material-symbols-outlined text-[18px]">menu_book</span>
            <span>Ver guías</span>
          </button>
          <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
            <button type="button" class="hidden lg:flex items-center gap-space-xs font-label-md text-label-md text-on-primary bg-surface-container-lowest/15 hover:bg-surface-container-lowest/25 px-space-md py-space-xs rounded-lg transition-colors cursor-pointer" id="commercial-open-permissions">
              <span class="material-symbols-outlined text-[18px]">shield_person</span>
              <span>Configurar permisos</span>
            </button>
          <?php endif; ?>
          <!-- Campana de Notificaciones (Últimas tareas creadas) -->
          <div class="relative" data-commercial-dropdown="notifications">
            <button type="button" class="relative flex items-center justify-center w-9 h-9 rounded-xl bg-surface-container-lowest/10 text-secondary-fixed hover:text-on-primary cursor-pointer transition-colors" id="btn-notifications-bell" aria-expanded="false" aria-haspopup="true" title="Últimas tareas creadas">
              <span class="material-symbols-outlined text-[20px]">notifications</span>
              <?php if ($recentCount > 0): ?>
                <span class="absolute -top-1 -right-1 px-1.5 py-0.2 bg-[#fbbf24] text-[#1e293b] font-bold text-[10px] rounded-full flex items-center justify-center shadow-sm leading-tight min-w-[18px]"><?php echo esc_html((string) $recentCount); ?></span>
              <?php endif; ?>
            </button>
            <div id="scm-notifications-dropdown" class="absolute right-0 top-full mt-2 hidden bg-surface-container-lowest shadow-[0_16px_36px_rgba(0,0,0,0.18)] rounded-2xl w-80 sm:w-96 max-w-[92vw] z-50 border border-outline-variant/30 overflow-hidden" role="menu">
              <div class="p-4 bg-surface-container-low border-b border-surface-container flex items-center justify-between">
                <div>
                  <div class="flex items-center gap-2">
                    <span class="font-headline-sm text-[16px] font-semibold text-on-surface">Últimas Tareas Creadas</span>
                    <span class="font-label-sm text-[10px] bg-primary-container text-on-surface px-1.5 py-0.5 rounded font-bold"><?php echo esc_html((string) count($recentTickets)); ?></span>
                  </div>
                  <p class="font-body-sm text-[11px] text-on-surface-variant mt-0.5">
                    <?php echo ($policy instanceof CommercialAccessPolicy && $policy->canManage()) ? 'Últimas gestiones del equipo comercial' : 'Tus gestiones comerciales asignadas'; ?>
                  </p>
                </div>
                <button type="button" class="text-secondary hover:text-on-surface p-1 rounded-lg cursor-pointer" data-commercial-close-notifications aria-label="Cerrar">
                  <span class="material-symbols-outlined text-[18px]">close</span>
                </button>
              </div>

              <div class="max-h-[360px] overflow-y-auto divide-y divide-surface-container p-1">
                <?php if (empty($recentTickets)): ?>
                  <div class="py-10 text-center text-secondary font-body-sm text-[13px]">
                    <span class="material-symbols-outlined text-[32px] opacity-40 block mb-1">inbox</span>
                    <span>No hay tareas recientes para mostrar</span>
                  </div>
                <?php else: ?>
                  <?php foreach ($recentTickets as $ticket): ?>
                    <?php
                      $ticketId = (string) ($ticket['_ID'] ?? '');
                      $ticketCode = (string) ($ticket['id_ticket'] ?? $ticketId);
                      $asunto = (string) ($ticket['asunto'] ?? 'Sin asunto');
                      $solicitante = (string) ($ticket['solicitante'] ?? $ticket['inmueble'] ?? 'Sin solicitante');
                      $asesor = (string) ($ticket['nombre_empleado'] ?? '');
                      $estado = (string) ($ticket['estado_comercial'] ?? 'Nuevo');
                      $createdTs = (int) ($ticket['fecha'] ?? 0);
                      if ($createdTs <= 0 && !empty($ticket['cct_created'])) {
                        $createdTs = strtotime((string) $ticket['cct_created']) ?: 0;
                      }
                      $timeAgo = $createdTs > 0 ? self::timeAgo($createdTs) : '';
                    ?>
                    <div class="p-3 hover:bg-surface-container-low transition-colors rounded-xl cursor-pointer" data-commercial-open-case="<?php echo esc_attr($ticketId); ?>">
                      <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-label-md text-[11px] font-bold text-primary">#<?php echo esc_html($ticketCode ?: $ticketId); ?></span>
                        <span class="font-label-sm text-[10px] px-2 py-0.5 rounded-full font-semibold bg-surface-container text-on-surface border border-outline-variant/30"><?php echo esc_html($estado); ?></span>
                      </div>
                      <p class="font-label-md text-[13px] font-semibold text-on-surface line-clamp-1 mb-0.5"><?php echo esc_html($asunto); ?></p>
                      <div class="flex items-center justify-between text-[11px] text-on-surface-variant font-body-sm mt-1">
                        <span class="truncate max-w-[180px]"><?php echo esc_html($solicitante); ?></span>
                        <?php if ($timeAgo !== ''): ?>
                          <span class="text-secondary shrink-0 ml-1"><?php echo esc_html($timeAgo); ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ($asesor !== '' && ($policy instanceof CommercialAccessPolicy && $policy->canManage())): ?>
                        <div class="text-[10px] text-secondary mt-1 flex items-center gap-1">
                          <span class="material-symbols-outlined text-[12px]">person</span>
                          <span class="truncate"><?php echo esc_html($asesor); ?></span>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <div class="p-2.5 bg-surface-container-low border-t border-surface-container text-center">
                <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => ($policy instanceof CommercialAccessPolicy && $policy->canManage()) ? 'abiertos' : 'mis_tickets'])); ?>" class="font-label-md text-[12px] text-primary hover:underline font-semibold flex items-center justify-center gap-1">
                  <span>Ver todas las tareas</span>
                  <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                </a>
              </div>
            </div>
          </div>
          <div class="h-6 w-px bg-surface-container-lowest/20 hidden sm:block"></div>
          <div class="flex items-center gap-space-sm pl-space-xs">
            <div class="text-right hidden sm:block">
              <span class="block font-label-md text-label-md text-on-primary font-semibold leading-tight"><?php echo esc_html($userName); ?></span>
              <span class="block font-label-sm text-label-sm text-secondary-fixed opacity-70"><?php echo esc_html($userRole); ?></span>
            </div>
            <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center font-semibold text-on-primary font-label-sm text-label-sm">
              <?php echo esc_html($userInitials); ?>
            </div>
            <form method="post" action="<?php echo esc_url($baseUrl . '/logout.php'); ?>" class="inline-flex items-center ml-1">
              <?php echo \SCM\Core\App::csrf()->field('logout'); ?>
              <button type="submit" class="text-secondary-fixed hover:text-error transition-colors p-1.5 rounded-lg hover:bg-surface-container-lowest/10 cursor-pointer" aria-label="Cerrar sesión" title="Cerrar sesión">
                <span class="material-symbols-outlined text-[20px]">logout</span>
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Second Row: Navegación de Pestañas con Dropdowns -->
      <div class="h-12 bg-surface-container-lowest shadow-[0_1px_8px_rgba(0,0,0,0.04)] px-margin flex items-center justify-between overflow-visible relative z-30">
        <?php echo self::renderTabs($views, $bucket, $filters, $tabCounts, $baseUrl, $topicHierarchy); ?>
        <div class="hidden md:flex items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant">
          <span class="w-2 h-2 rounded-full bg-primary-container animate-pulse"></span>
          <span>Red Operativa Online</span>
        </div>
      </div>
    </div>
  </header>

  <!-- Contenedor Principal -->
  <main class="w-full pt-28 bg-background min-h-screen flex-1">
    <?php if ($bucket === 'sin_acceso'): ?>
      <section class="w-full px-margin py-16 flex flex-col items-center justify-center text-center space-y-space-md" id="scm-panel-sin_acceso">
        <div class="w-16 h-16 rounded-2xl bg-error-container text-on-error-container flex items-center justify-center">
          <span class="material-symbols-outlined text-[32px]">lock</span>
        </div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Sin vistas habilitadas</h2>
        <p class="font-body-lg text-body-lg text-on-surface-variant max-w-md">Tu cargo no tiene secciones visibles en este panel. Solicita acceso a un administrador.</p>
      </section>
    <?php else: ?>
      <div class="scm-tab-panel commercial-panel active" id="commercial-tickets-panel" data-commercial-tickets-panel aria-live="polite">
        <?php
          if ($bucket === 'inicio') {
            echo self::renderHome($homeDashboard, $filters, $policy, $baseUrl, $ticketEmployees, $filterOptions, $result, $tabCounts);
          } elseif ($bucket === 'actualizaciones') {
            echo self::renderPropertyUpdatesPage($filters, $ticketEmployees, $filterOptions, $policy);
          } elseif ($bucket === 'avisos') {
            echo self::renderSignsPage($filters, $ticketEmployees, $filterOptions, $policy);
          } elseif ($bucket === 'calendario') {
            echo self::renderCalendarPage($runtime['config'] ?? [], $calendarEmployees, $subtab, $policy, $baseUrl);
          } else {
            echo self::renderTickets($bucket, $result, $filters, $ticketEmployees, $filterOptions, $tabCounts, $policy, $baseUrl, $homeDashboard);
          }
        ?>
      </div>
    <?php endif; ?>

    <!-- Modal de Caso / Tarea -->
    <div class="fixed inset-0 z-50 items-center justify-center p-3 sm:p-5 lg:p-7 bg-[#061D49]/50 backdrop-blur-md commercial-modal overflow-y-auto" id="commercial-case-modal" role="dialog" aria-modal="true" aria-labelledby="commercial-case-title" aria-hidden="true">
      <div class="bg-white w-full max-w-[1400px] max-h-[94vh] rounded-3xl shadow-modal border border-slate-100 flex flex-col overflow-hidden relative" role="document" data-commercial-case-content>
        <div class="flex items-center justify-center py-28 text-slate-500 gap-3">
          <span class="w-3 h-3 rounded-full bg-[#1E3C76] animate-ping"></span>
          <p class="font-body-md text-sm font-medium">Cargando información de la tarea…</p>
        </div>
      </div>
    </div>

    <!-- Guía y Permisos -->
    <?php echo CommercialGuideView::render(); ?>
    <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
      <?php echo self::renderPermissions($policy, $commercialEmployeeCargos); ?>
    <?php endif; ?>
  </main>

  <!-- Footer -->
  <footer class="w-full bg-surface-container-low py-space-lg shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
    <div class="w-full px-margin flex flex-col md:flex-row items-center justify-between gap-space-md">
      <div class="flex items-center gap-space-sm">
        <div class="w-6 h-6 rounded-lg bg-primary-container flex items-center justify-center">
          <span class="material-symbols-outlined text-on-surface text-[14px]">domain</span>
        </div>
        <span class="font-label-md text-label-md text-on-surface font-semibold">SuCasa Inmobiliaria</span>
        <span class="text-on-surface-variant font-body-sm text-body-sm">— Plataforma de Gestión y Servicios Comerciales</span>
      </div>
      <div class="flex items-center gap-space-lg font-label-sm text-label-sm text-on-surface-variant">
        <button type="button" class="hover:text-on-surface transition-colors cursor-pointer" id="scm-footer-guide">Soporte Operativo</button>
        <?php if ($policy instanceof CommercialAccessPolicy && $policy->canManage()): ?>
          <button type="button" class="hover:text-on-surface transition-colors cursor-pointer" id="scm-footer-permissions">Auditoría &amp; Accesos</button>
        <?php endif; ?>
        <span>© <?php echo esc_html(date('Y')); ?> SuCasa Inmobiliaria. Todos los derechos reservados.</span>
      </div>
    </div>
  </footer>
  </div>

  <script src="<?php echo esc_url($baseUrl . '/assets/js/scm-admin.js?v=' . SCM_VERSION); ?>"></script>
  <script src="<?php echo esc_url($baseUrl . '/assets/js/admin-dashboard-runtime.js?v=' . SCM_VERSION); ?>"></script>
  <script src="<?php echo esc_url($baseUrl . '/assets/js/commercial-dashboard.js?v=' . SCM_VERSION); ?>"></script>
</body>
</html>
<?php
    return (string) ob_get_clean();
  }

  /**
   * @param array<int,string> $views
   * @param array<string,mixed> $filters
   * @param array<string,int> $tabCounts
   * @param array<int,array<string,mixed>> $topicHierarchy
   */
  public static function renderTabs(array $views, string $bucket, array $filters, array $tabCounts, string $baseUrl, array $topicHierarchy = []): string
  {
    $taskViews = ['abiertos', 'mis_tickets', 'postergados', 'cerrados'];
    $isTaskActive = in_array($bucket, $taskViews, true);
    $activeClasses = 'bg-primary-container text-on-surface font-semibold rounded-lg shadow-sm';
    $inactiveClasses = 'text-on-surface-variant hover:text-on-surface rounded-lg transition-colors whitespace-nowrap';

    $openCount = (int) ($tabCounts['abiertos'] ?? 0);
    $myCount = (int) ($tabCounts['mis_tickets'] ?? 0);
    $postponedCount = (int) ($tabCounts['postergados'] ?? 0);
    $closedCount = (int) ($tabCounts['cerrados'] ?? 0);

    ob_start();
?>
    <nav class="flex items-center gap-space-xs lg:gap-space-sm overflow-visible py-space-xs" data-commercial-tabs data-active-classes="<?php echo esc_attr($activeClasses); ?>" aria-label="Navegación principal">
      <!-- Pestaña Inicio -->
      <?php if (in_array('inicio', $views, true)): ?>
        <a class="px-space-md py-space-xs transition-colors whitespace-nowrap font-label-md text-label-md <?php echo $bucket === 'inicio' ? $activeClasses : $inactiveClasses; ?>" data-commercial-tab="inicio" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'inicio'])); ?>"<?php echo $bucket === 'inicio' ? ' aria-current="page"' : ''; ?>>
          Inicio
        </a>
      <?php endif; ?>

      <!-- Dropdown Gestión de Tareas (Con despliegue por tema y por estado comercial) -->
      <div class="relative group/nav" data-commercial-dropdown="tareas">
        <button type="button" class="flex items-center gap-space-xs px-space-md py-space-xs font-label-md text-label-md <?php echo $isTaskActive ? ($activeClasses . ' active') : $inactiveClasses; ?> cursor-pointer select-none" data-commercial-tab="tareas" data-commercial-dropdown-trigger="tareas" aria-expanded="false">
          <span>Gestión de Tareas</span>
          <span class="material-symbols-outlined text-[16px] group-hover/nav:rotate-180 transition-transform">expand_more</span>
        </button>
        <div class="absolute left-0 top-full hidden group-hover/nav:block bg-surface-container-lowest shadow-[0_12px_32px_rgba(0,0,0,0.14)] rounded-2xl py-space-xs min-w-[280px] max-w-[340px] z-50 border border-outline-variant/30 text-on-surface" data-commercial-dropdown-menu="tareas">
          <!-- Vistas Rápidas -->
          <div class="p-1 space-y-0.5">
            <?php if (in_array('abiertos', $views, true)): ?>
              <a class="flex items-center justify-between px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="abiertos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos'])); ?>">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-primary">inbox</span>
                  <span>Tareas Abiertas</span>
                </span>
                <span class="font-label-sm text-label-sm bg-surface-container px-space-xs py-0.5 rounded text-on-surface font-semibold"><?php echo esc_html(number_format($openCount)); ?></span>
              </a>
            <?php endif; ?>
            <?php if (in_array('mis_tickets', $views, true)): ?>
              <a class="flex items-center justify-between px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="mis_tickets" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'mis_tickets'])); ?>">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-tertiary">assignment_ind</span>
                  <span>Mis Tareas</span>
                </span>
                <span class="font-label-sm text-label-sm bg-primary-container px-space-xs py-0.5 rounded text-on-surface font-semibold"><?php echo esc_html(number_format($myCount)); ?></span>
              </a>
            <?php endif; ?>
            <?php if (in_array('postergados', $views, true)): ?>
              <a class="flex items-center justify-between px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="postergados" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'postergados'])); ?>">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-secondary">hourglass_empty</span>
                  <span>Tareas Postergadas</span>
                </span>
                <span class="font-label-sm text-label-sm bg-surface-container px-space-xs py-0.5 rounded text-on-surface font-semibold"><?php echo esc_html(number_format($postponedCount)); ?></span>
              </a>
            <?php endif; ?>
            <?php if (in_array('cerrados', $views, true)): ?>
              <a class="flex items-center justify-between px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="cerrados" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'cerrados'])); ?>">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-outline">task_alt</span>
                  <span>Tareas Cerradas</span>
                </span>
                <span class="font-label-sm text-label-sm bg-surface-container px-space-xs py-0.5 rounded text-on-surface font-semibold"><?php echo esc_html(number_format($closedCount)); ?></span>
              </a>
            <?php endif; ?>
          </div>

          <!-- Desglose por Tema de Ayuda y Estado Comercial -->
          <?php if (!empty($topicHierarchy)): ?>
            <div class="my-1 border-t border-surface-container"></div>
            <div class="px-3 py-1 font-label-sm uppercase font-semibold text-secondary flex items-center justify-between">
              <span>Por Tema de Ayuda</span>
              <span class="text-[10px] text-secondary/70">Estados &rsaquo;</span>
            </div>
            <div class="p-1 space-y-0.5 overflow-visible">
              <?php foreach ($topicHierarchy as $th): ?>
                <?php
                  $topicName = (string) ($th['topic'] ?? '');
                  $topicTotal = (int) ($th['total'] ?? 0);
                  $statuses = (array) ($th['statuses'] ?? []);
                ?>
                <div class="relative group/sub" data-commercial-subgroup>
                  <div class="flex items-center justify-between px-3 py-1.5 rounded-xl text-body-sm hover:bg-surface-container-low transition-colors">
                    <a class="flex-1 font-medium text-on-surface hover:text-primary transition-colors flex items-center justify-between" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'tema' => $topicName])); ?>" data-commercial-tab="abiertos">
                      <span class="truncate max-w-[170px]" title="<?php echo esc_attr($topicName); ?>"><?php echo esc_html($topicName); ?></span>
                      <span class="font-label-sm text-[11px] bg-surface-container px-1.5 py-0.5 rounded text-on-surface font-semibold ml-1"><?php echo esc_html((string) $topicTotal); ?></span>
                    </a>
                    <?php if (!empty($statuses)): ?>
                      <button type="button" class="ml-1 p-1 -mr-1 text-secondary hover:text-primary rounded-lg transition-colors cursor-pointer flex items-center justify-center" data-commercial-subflyout-trigger aria-label="Ver estados comerciales de <?php echo esc_attr($topicName); ?>" title="Ver estados">
                        <span class="material-symbols-outlined text-[16px] transition-transform group-hover/sub:translate-x-0.5" aria-hidden="true">chevron_right</span>
                      </button>
                    <?php endif; ?>
                  </div>

                  <!-- Flyout Submenu a la derecha con Estados Comerciales del Tema -->
                  <?php if (!empty($statuses)): ?>
                    <div class="absolute left-full top-0 pl-1.5 hidden group-hover/sub:block z-50 pointer-events-auto" data-commercial-subflyout>
                      <div class="bg-surface-container-lowest shadow-[0_12px_32px_rgba(0,0,0,0.18)] rounded-2xl py-2 min-w-[220px] max-w-[280px] border border-outline-variant/30 p-1.5 space-y-1">
                        <div class="px-3 py-1.5 border-b border-surface-container pb-1.5 mb-1 bg-surface-container-low/50 rounded-xl">
                          <div class="flex items-center justify-between">
                            <span class="font-label-sm uppercase font-bold text-on-surface text-[11px] block truncate"><?php echo esc_html($topicName); ?></span>
                            <span class="font-label-sm text-[10px] bg-primary-container text-on-surface px-1.5 py-0.2 rounded font-bold"><?php echo esc_html((string) $topicTotal); ?></span>
                          </div>
                          <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'tema' => $topicName])); ?>" class="text-[11px] text-primary hover:underline font-semibold flex items-center gap-1 mt-1" data-commercial-tab="abiertos">
                            <span>Ver todas las tareas</span>
                            <span class="material-symbols-outlined text-[12px]">arrow_forward</span>
                          </a>
                        </div>
                        <div class="max-h-[260px] overflow-y-auto space-y-0.5 pr-1">
                          <?php foreach ($statuses as $st): ?>
                            <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'tema' => $topicName, 'estado' => $st['status']])); ?>" class="flex items-center justify-between px-2.5 py-1.5 rounded-xl text-[12px] text-on-surface hover:bg-surface-container hover:text-primary transition-colors font-medium" data-commercial-tab="abiertos">
                              <span class="truncate"><?php echo esc_html($st['status']); ?></span>
                              <span class="font-label-sm text-secondary bg-surface-container-high px-1.5 py-0.5 rounded text-[10px] ml-1 font-semibold"><?php echo esc_html((string) $st['total']); ?></span>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Dropdown Calendario Comercial (Mi calendario, Calendario equipo, Vencimientos) -->
      <?php if (in_array('calendario', $views, true)): ?>
        <div class="relative group/nav" data-commercial-dropdown="calendario">
          <button type="button" class="flex items-center gap-space-xs px-space-md py-space-xs font-label-md text-label-md <?php echo $bucket === 'calendario' ? ($activeClasses . ' active') : $inactiveClasses; ?> cursor-pointer select-none" data-commercial-tab="calendario" data-commercial-dropdown-trigger="calendario" aria-expanded="false">
            <span class="material-symbols-outlined text-[16px]">calendar_month</span>
            <span>Calendario</span>
            <span class="material-symbols-outlined text-[16px] group-hover/nav:rotate-180 transition-transform">expand_more</span>
          </button>
          <div class="absolute left-0 top-full hidden group-hover/nav:block bg-surface-container-lowest shadow-[0_12px_32px_rgba(0,0,0,0.14)] rounded-2xl py-space-xs min-w-[240px] z-50 border border-outline-variant/30 text-on-surface" data-commercial-dropdown-menu="calendario">
            <a class="flex items-center gap-2.5 px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="calendario" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'mine'])); ?>">
              <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>
              <div>
                <span class="block font-semibold text-on-surface">Mi calendario</span>
                <span class="block text-[11px] text-secondary">Agenda personal operativa</span>
              </div>
            </a>
            <a class="flex items-center gap-2.5 px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="calendario" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'team'])); ?>">
              <span class="material-symbols-outlined text-[18px] text-secondary">groups</span>
              <div>
                <span class="block font-semibold text-on-surface">Calendario equipo</span>
                <span class="block text-[11px] text-secondary">Disponibilidad de consultores</span>
              </div>
            </a>
            <a class="flex items-center gap-2.5 px-3 py-2 rounded-xl font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="calendario" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'due'])); ?>">
              <span class="material-symbols-outlined text-[18px] text-error">schedule</span>
              <div>
                <span class="block font-semibold text-on-surface">Vencimientos</span>
                <span class="block text-[11px] text-secondary">Control mensual de atrasos</span>
              </div>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Dropdown Actualizaciones de Inmuebles -->
      <?php if (in_array('actualizaciones', $views, true)): ?>
        <div class="relative group/nav" data-commercial-dropdown="actualizaciones">
          <button type="button" class="flex items-center gap-space-xs px-space-md py-space-xs font-label-md text-label-md <?php echo $bucket === 'actualizaciones' ? ($activeClasses . ' active') : $inactiveClasses; ?> cursor-pointer select-none" data-commercial-tab="actualizaciones" data-commercial-dropdown-trigger="actualizaciones" aria-expanded="false">
            <span>Actualizaciones de Inmuebles</span>
            <span class="material-symbols-outlined text-[16px] group-hover/nav:rotate-180 transition-transform">expand_more</span>
          </button>
          <div class="absolute left-0 top-full hidden group-hover/nav:block bg-surface-container-lowest shadow-[0_8px_24px_rgba(0,0,0,0.12)] rounded-xl py-space-xs min-w-[230px] z-50 border border-outline-variant/30 text-on-surface" data-commercial-dropdown-menu="actualizaciones">
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="actualizaciones" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones', 'estado_actualizacion' => 'OK'])); ?>">
              <span>Al Día</span>
              <span class="font-label-sm text-label-sm bg-surface-container px-space-xs py-0.5 rounded text-on-surface">Activo</span>
            </a>
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="actualizaciones" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones', 'estado_actualizacion' => 'Alerta'])); ?>">
              <span>En Alerta</span>
              <span class="font-label-sm text-label-sm bg-tertiary-fixed text-on-tertiary-fixed px-space-xs py-0.5 rounded font-semibold">Alerta</span>
            </a>
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="actualizaciones" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones', 'estado_actualizacion' => 'Vencido'])); ?>">
              <span>Vencidos</span>
              <span class="font-label-sm text-label-sm bg-error-container text-on-error-container px-space-xs py-0.5 rounded font-semibold">Vencido</span>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Dropdown Avisos en Fachada -->
      <?php if (in_array('avisos', $views, true)): ?>
        <div class="relative group/nav" data-commercial-dropdown="avisos">
          <button type="button" class="flex items-center gap-space-xs px-space-md py-space-xs font-label-md text-label-md <?php echo $bucket === 'avisos' ? ($activeClasses . ' active') : $inactiveClasses; ?> cursor-pointer select-none" data-commercial-tab="avisos" data-commercial-dropdown-trigger="avisos" aria-expanded="false">
            <span>Avisos en Fachada</span>
            <span class="material-symbols-outlined text-[16px] group-hover/nav:rotate-180 transition-transform">expand_more</span>
          </button>
          <div class="absolute left-0 top-full hidden group-hover/nav:block bg-surface-container-lowest shadow-[0_8px_24px_rgba(0,0,0,0.12)] rounded-xl py-space-xs min-w-[240px] z-50 border border-outline-variant/30 text-on-surface" data-commercial-dropdown-menu="avisos">
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos', 'estado_aviso' => 'Vencido'])); ?>">
              <span>Retoques Vencidos</span>
              <span class="font-label-sm text-label-sm bg-error-container text-on-error-container px-space-xs py-0.5 rounded font-semibold">Vencidos</span>
            </a>
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos', 'estado_aviso' => 'Alerta'])); ?>">
              <span>Avisos por Vencer</span>
              <span class="font-label-sm text-label-sm bg-tertiary-fixed text-on-tertiary-fixed px-space-xs py-0.5 rounded font-semibold">Alerta</span>
            </a>
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos', 'estado_aviso' => 'Atrasado'])); ?>">
              <span>Nuevos sin Colocar</span>
              <span class="font-label-sm text-label-sm bg-secondary-fixed text-on-secondary-fixed px-space-xs py-0.5 rounded font-semibold">Pendientes</span>
            </a>
            <a class="flex items-center justify-between px-space-md py-space-sm font-label-md text-label-md text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos'])); ?>">
              <span>Rutas Operativas</span>
              <span class="material-symbols-outlined text-secondary text-[16px]">near_me</span>
            </a>
          </div>
        </div>
      <?php endif; ?>
    </nav>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $dashboard @param array<string,mixed> $filters */
  public static function renderHome(
    array $dashboard,
    array $filters,
    $policy,
    string $baseUrl,
    array $ticketEmployees = [],
    array $filterOptions = [],
    array $result = [],
    array $tabCounts = []
  ): string {
    $slaSummary = is_array($dashboard['sla_summary'] ?? null) ? $dashboard['sla_summary'] : [];
    $properties = is_array($dashboard['properties'] ?? null) ? $dashboard['properties'] : [];
    $signs = is_array($dashboard['signs'] ?? null) ? $dashboard['signs'] : [];
    $overdueAdvisors = is_array($dashboard['overdue_advisors'] ?? null) ? $dashboard['overdue_advisors'] : [];

    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : (is_array($dashboard['status_counts'] ?? null) ? $dashboard['status_counts'] : []);
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];

    $taskTotal = (int) ($slaSummary['total'] ?? 0);
    $taskOverdue = (int) ($slaSummary['atrasados'] ?? 0);
    $taskCompliancePct = (int) ($slaSummary['porcentaje_cumplimiento'] ?? 0);
    $visibleTotal = isset($pagination['total']) ? (int) $pagination['total'] : ($taskTotal ?: count($rows));

    ob_start();
?>
    <div class="flex flex-col w-full">
      <!-- Sub-header Operativo y Acciones Clave -->
      <section class="w-full px-margin py-space-md">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm">
          <div class="space-y-space-xs">
            <div class="flex items-center gap-space-xs">
              <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-error animate-ping"></span>
              <span class="font-label-sm text-label-sm uppercase tracking-wider text-error font-semibold">Monitor Operativo en Vivo</span>
              <span class="text-outline-variant">•</span>
              <span class="font-label-sm text-label-sm text-secondary flex items-center gap-1">
                <span class="material-symbols-outlined text-[15px]">calendar_today</span>
                <?php echo esc_html(self::currentDateFormatted()); ?>
              </span>
            </div>
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">Control Operativo de Tareas Comerciales</h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-3xl">
              Monitoreo en tiempo real de embudos de captación, auditoría de vencimientos de inmuebles y trazabilidad de avisos en fachada.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-space-sm pt-space-xs lg:pt-0">
            <button type="button" class="flex items-center gap-space-xs bg-surface-container-low hover:bg-surface-container text-on-surface px-space-md py-2.5 rounded-xl font-label-md text-label-md transition-all shadow-sm cursor-pointer" id="btn-export-report">
              <span class="material-symbols-outlined text-[18px] text-secondary">download</span>
              <span>Exportar Reporte Diario</span>
            </button>
            <button type="button" class="flex items-center gap-space-xs bg-error-container hover:bg-error/20 text-on-error-container px-space-md py-2.5 rounded-xl font-label-md text-label-md transition-all shadow-sm cursor-pointer" id="btn-open-drawer">
              <span class="material-symbols-outlined text-[18px]">warning</span>
              <span>Auditar Atrasados (<?php echo esc_html((string) $taskOverdue); ?>)</span>
            </button>
            <button type="button" class="flex items-center gap-space-xs bg-primary-container hover:bg-primary-fixed-dim text-on-surface px-space-lg py-2.5 rounded-xl font-label-md text-label-md font-semibold transition-all shadow-md transform hover:-translate-y-0.5 cursor-pointer" id="btn-new-task" onclick="window.location.href='<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos'])); ?>'">
              <span class="material-symbols-outlined text-[20px]">add_circle</span>
              <span>+ Nueva Tarea</span>
            </button>
          </div>
        </div>
      </section>

      <!-- Tarjetas de Alertas & KPIs Circulares -->
      <?php echo self::renderHomeCards($slaSummary, $properties, $signs, $baseUrl, $filters); ?>

      <!-- Embudo de Estados (Píldoras interactivas con badges numéricos) -->
      <?php echo self::renderFunnelPills('abiertos', $counts, $taskTotal, $filters, $baseUrl); ?>

      <!-- Filtros Avanzados Multi-Criterio -->
      <?php echo self::renderFiltersSection('inicio', $filters, $ticketEmployees, $filterOptions, $baseUrl); ?>

      <!-- Tabla de Casos y Tareas en Curso -->
      <?php echo self::renderTicketsTableSection('inicio', $rows, $pagination, $visibleTotal, $policy, $baseUrl, $filters); ?>

      <!-- Lateral Drawer / Modal de Detalle Crítico -->
      <?php echo self::renderSideDrawer($slaSummary, $overdueAdvisors, $baseUrl, $filters); ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $slaSummary @param array<string,mixed> $properties @param array<string,mixed> $signs @param array<string,mixed> $filters */
  private static function renderHomeCards(array $slaSummary, array $properties, array $signs, string $baseUrl, array $filters): string
  {
    $taskTotal = (int) ($slaSummary['total'] ?? 0);
    $taskOnTime = (int) ($slaSummary['al_dia'] ?? 0);
    $taskOverdue = (int) ($slaSummary['atrasados'] ?? 0);
    $taskCompliancePct = max(0, min(100, (int) ($slaSummary['porcentaje_cumplimiento'] ?? 0)));
    $taskLatePct = max(0, min(100, (int) ($slaSummary['porcentaje_atraso'] ?? 0)));

    $propertyOk = (int) ($properties['actualizacion_ok'] ?? 0);
    $propertyAlert = (int) ($properties['actualizacion_alerta'] ?? 0);
    $propertyExpired = (int) ($properties['actualizacion_vencida'] ?? 0);
    $propertyTotal = (int) ($properties['publicos'] ?? ($propertyOk + $propertyAlert + $propertyExpired));
    $propertyPct = self::percent($propertyOk, $propertyTotal);

    $retouchExpired = (int) ($signs['retoque_vencido'] ?? 0);
    $retouchAlert = (int) ($signs['retoque_alerta'] ?? 0);
    $newSignLate = (int) ($signs['instalacion_atrasada'] ?? 0);
    $signOk = (int) ($signs['ok'] ?? 0);
    $signTotal = (int) ($signs['total'] ?? ($signOk + $retouchExpired + $retouchAlert + $newSignLate));
    $signPct = self::percent($signOk, $signTotal);

    ob_start();
?>
    <section class="w-full px-margin pb-space-lg">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-space-md">
        <!-- Card 1: Alerta Crítica Tareas Atrasadas -->
        <div class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm flex flex-col justify-between relative overflow-hidden group hover:shadow-md transition-all">
          <div class="absolute -right-6 -top-6 w-28 h-28 bg-error/5 rounded-full blur-xl pointer-events-none"></div>
          <div>
            <div class="flex items-center justify-between pb-space-md">
              <div class="flex items-center gap-space-xs">
                <span class="p-2 rounded-xl bg-error-container text-on-error-container">
                  <span class="material-symbols-outlined text-[20px]">notification_important</span>
                </span>
                <div>
                  <span class="font-label-sm text-label-sm uppercase font-semibold text-error">Alerta Crítica</span>
                  <h3 class="font-headline-sm text-headline-sm text-on-surface">Tareas Atrasadas</h3>
                </div>
              </div>
              <span class="font-label-sm text-label-sm px-2 py-0.5 rounded-full bg-error-container text-on-error-container font-semibold"><?php echo esc_html((string) $taskLatePct); ?>% Atraso</span>
            </div>
            <div class="flex items-center gap-space-lg py-space-sm">
              <!-- Radial SVG Progress -->
              <div class="relative w-24 h-24 flex-shrink-0">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                  <path class="text-error-container" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-width="3.5"></path>
                  <path class="text-error" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-dasharray="<?php echo esc_attr((string) $taskCompliancePct); ?>, 100" stroke-linecap="round" stroke-width="3.5"></path>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                  <span class="font-headline-sm text-headline-sm font-bold text-on-surface leading-none"><?php echo esc_html((string) $taskCompliancePct); ?>%</span>
                  <span class="font-label-sm text-[9px] text-secondary font-medium uppercase mt-0.5">Al Día</span>
                </div>
              </div>
              <div class="flex-1 space-y-space-xs">
                <div class="flex items-baseline gap-1">
                  <span class="font-display-lg text-[34px] leading-tight font-bold text-error"><?php echo esc_html((string) $taskOverdue); ?></span>
                  <span class="font-body-sm text-body-sm text-secondary">/ <?php echo esc_html((string) $taskTotal); ?> abiertas</span>
                </div>
                <p class="font-body-sm text-body-sm text-on-surface-variant">
                  <?php echo esc_html((string) $taskOnTime); ?> casos al día. Se requiere reasignación prioritaria de cartera comercial.
                </p>
              </div>
            </div>
          </div>
          <div class="pt-space-md mt-space-sm flex items-center justify-between">
            <button type="button" class="font-label-md text-label-md text-error font-semibold flex items-center gap-1 hover:underline cursor-pointer" onclick="document.getElementById('side-drawer').classList.remove('translate-x-full')">
              <span>Ver desglose por asesor</span>
              <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
            </button>
            <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'sla_filter' => 'atrasado'])); ?>" data-commercial-filter-link class="bg-error text-on-error px-space-md py-2 rounded-xl font-label-md text-label-md font-semibold hover:opacity-95 shadow-sm transition-all cursor-pointer">
              Gestionar Ahora
            </a>
          </div>
        </div>

        <!-- Card 2: Actualización de Inmuebles -->
        <div class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm flex flex-col justify-between relative overflow-hidden group hover:shadow-md transition-all">
          <div class="absolute -right-6 -top-6 w-28 h-28 bg-primary/5 rounded-full blur-xl pointer-events-none"></div>
          <div>
            <div class="flex items-center justify-between pb-space-md">
              <div class="flex items-center gap-space-xs">
                <span class="p-2 rounded-xl bg-surface-container text-secondary">
                  <span class="material-symbols-outlined text-[20px]">apartment</span>
                </span>
                <div>
                  <span class="font-label-sm text-label-sm uppercase font-semibold text-secondary">Vigencia de Inventario</span>
                  <h3 class="font-headline-sm text-headline-sm text-on-surface">Actualización Inmuebles</h3>
                </div>
              </div>
              <span class="font-label-sm text-label-sm px-2 py-0.5 rounded-full bg-surface-container text-on-surface font-semibold"><?php echo esc_html((string) $propertyTotal); ?> Fichas</span>
            </div>
            <div class="flex items-center gap-space-lg py-space-sm">
              <div class="relative w-24 h-24 flex-shrink-0">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                  <path class="text-surface-container-high" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-width="3.5"></path>
                  <path class="text-primary-container" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-dasharray="<?php echo esc_attr((string) $propertyPct); ?>, 100" stroke-linecap="round" stroke-width="3.5"></path>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                  <span class="font-headline-sm text-headline-sm font-bold text-on-surface leading-none"><?php echo esc_html((string) $propertyPct); ?>%</span>
                  <span class="font-label-sm text-[9px] text-secondary font-medium uppercase mt-0.5">Vigentes</span>
                </div>
              </div>
              <div class="flex-1 space-y-2">
                <div class="flex flex-wrap gap-1.5">
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-surface-container text-on-surface font-label-sm text-label-sm">
                    <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
                    <strong><?php echo esc_html((string) $propertyOk); ?></strong> Al Día
                  </span>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-tertiary-fixed text-on-tertiary-fixed font-label-sm text-label-sm font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span>
                    <strong><?php echo esc_html((string) $propertyAlert); ?></strong> En Alerta
                  </span>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-error-container text-on-error-container font-label-sm text-label-sm font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-error"></span>
                    <strong><?php echo esc_html((string) $propertyExpired); ?></strong> Vencidos
                  </span>
                </div>
                <p class="font-body-sm text-body-sm text-on-surface-variant">
                  <?php echo esc_html((string) $propertyExpired); ?> inmuebles superaron los 60 días sin contacto de propietario verificado.
                </p>
              </div>
            </div>
          </div>
          <div class="pt-space-md mt-space-sm flex items-center justify-between">
            <a class="font-label-md text-label-md text-secondary font-semibold flex items-center gap-1 hover:underline cursor-pointer" data-commercial-tab="actualizaciones" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones', 'estado_actualizacion' => 'Alerta'])); ?>">
              <span>Filtrar críticos</span>
              <span class="material-symbols-outlined text-[16px]">open_in_new</span>
            </a>
            <a class="bg-surface-container hover:bg-surface-container-high text-on-surface px-space-md py-2 rounded-xl font-label-md text-label-md font-semibold transition-all cursor-pointer" data-commercial-tab="actualizaciones" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'actualizaciones'])); ?>">
              Auditar Inmuebles
            </a>
          </div>
        </div>

        <!-- Card 3: Avisos en Fachada -->
        <div class="bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm flex flex-col justify-between relative overflow-hidden group hover:shadow-md transition-all">
          <div class="absolute -right-6 -top-6 w-28 h-28 bg-secondary/5 rounded-full blur-xl pointer-events-none"></div>
          <div>
            <div class="flex items-center justify-between pb-space-md">
              <div class="flex items-center gap-space-xs">
                <span class="p-2 rounded-xl bg-surface-container-high text-secondary">
                  <span class="material-symbols-outlined text-[20px]">signpost</span>
                </span>
                <div>
                  <span class="font-label-sm text-label-sm uppercase font-semibold text-secondary">Publicidad Exterior</span>
                  <h3 class="font-headline-sm text-headline-sm text-on-surface">Avisos en Fachada</h3>
                </div>
              </div>
              <span class="font-label-sm text-label-sm px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed font-semibold">Ruta Activa</span>
            </div>
            <div class="flex items-center gap-space-lg py-space-sm">
              <div class="relative w-24 h-24 flex-shrink-0">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                  <path class="text-surface-container-high" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-width="3.5"></path>
                  <path class="text-secondary" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="currentColor" stroke-dasharray="<?php echo esc_attr((string) $signPct); ?>, 100" stroke-linecap="round" stroke-width="3.5"></path>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                  <span class="font-headline-sm text-headline-sm font-bold text-on-surface leading-none"><?php echo esc_html((string) $signPct); ?>%</span>
                  <span class="font-label-sm text-[9px] text-secondary font-medium uppercase mt-0.5">Óptimos</span>
                </div>
              </div>
              <div class="flex-1 space-y-2">
                <div class="space-y-1">
                  <div class="flex items-center justify-between text-body-sm font-body-sm">
                    <span class="text-error font-medium flex items-center gap-1">
                      <span class="w-2 h-2 rounded-full bg-error"></span>Retoques vencidos:
                    </span>
                    <span class="font-semibold text-error"><?php echo esc_html((string) $retouchExpired); ?></span>
                  </div>
                  <div class="flex items-center justify-between text-body-sm font-body-sm">
                    <span class="text-tertiary font-medium flex items-center gap-1">
                      <span class="w-2 h-2 rounded-full bg-tertiary"></span>Avisos por vencer:
                    </span>
                    <span class="font-semibold text-tertiary"><?php echo esc_html((string) $retouchAlert); ?></span>
                  </div>
                  <div class="flex items-center justify-between text-body-sm font-body-sm">
                    <span class="text-secondary font-medium flex items-center gap-1">
                      <span class="w-2 h-2 rounded-full bg-secondary"></span>Nuevos sin colocar:
                    </span>
                    <span class="font-semibold text-secondary"><?php echo esc_html((string) $newSignLate); ?></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="pt-space-md mt-space-sm flex items-center justify-between">
            <a class="font-label-md text-label-md text-secondary font-semibold flex items-center gap-1 hover:underline cursor-pointer" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos'])); ?>">
              <span>Logística motorizada</span>
              <span class="material-symbols-outlined text-[16px]">navigation</span>
            </a>
            <a class="bg-surface-container hover:bg-surface-container-high text-on-surface px-space-md py-2 rounded-xl font-label-md text-label-md font-semibold transition-all cursor-pointer" data-commercial-tab="avisos" href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'avisos'])); ?>">
              Ver Rutas Operativas
            </a>
          </div>
        </div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,int> $counts @param array<string,mixed> $filters */
  private static function renderFunnelPills(string $bucket, array $counts, int $bucketTotal, array $filters, string $baseUrl, string $effectiveBucket = ''): string
  {
    $bucketKey = $effectiveBucket !== '' ? $effectiveBucket : ($bucket === 'inicio' ? 'abiertos' : $bucket);
    $bucketDef = CommercialStatusCatalog::buckets()[$bucketKey] ?? CommercialStatusCatalog::buckets()['abiertos'];
    $selectedStatus = trim((string) ($filters['estado'] ?? ''));
    $baseParams = self::filterParams($filters);

    ob_start();
?>
    <section class="w-full px-margin pb-space-md">
      <div class="bg-surface-container-lowest p-space-md rounded-2xl shadow-sm">
        <div class="flex items-center justify-between mb-space-sm px-space-xs">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-secondary text-[20px]">filter_list</span>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Embudo de Tareas por Estado</span>
          </div>
          <span class="font-body-sm text-body-sm text-secondary">Total: <?php echo esc_html(number_format($bucketTotal)); ?> transacciones activas</span>
        </div>
        <div class="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none" id="funnel-pills">
          <?php $isAllActive = $selectedStatus === ''; ?>
          <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + array_diff_key($baseParams, ['estado' => true, 'page' => true]))); ?>" class="funnel-tab flex items-center gap-2 px-space-md py-2 rounded-xl font-label-md text-label-md transition-all whitespace-nowrap cursor-pointer <?php echo $isAllActive ? 'active bg-inverse-surface text-on-secondary shadow-sm' : 'bg-surface-container hover:bg-surface-container-high text-on-surface'; ?>">
            <span>Todos</span>
            <span class="px-2 py-0.5 rounded-full <?php echo $isAllActive ? 'bg-primary-container text-on-surface font-bold' : 'bg-surface-container-lowest text-secondary font-semibold'; ?> text-label-sm"><?php echo esc_html(number_format($bucketTotal)); ?></span>
          </a>
          <?php foreach ($bucketDef['statuses'] as $status): ?>
            <?php
              $statusCount = (int) ($counts[$status] ?? 0);
              $isActive = ($selectedStatus === $status);
              if ($statusCount <= 0 && !$isActive) {
                continue;
              }
            ?>
            <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket, 'estado' => $status] + array_diff_key($baseParams, ['estado' => true, 'page' => true]))); ?>" class="funnel-tab flex items-center gap-2 px-space-md py-2 rounded-xl font-label-md text-label-md transition-all whitespace-nowrap cursor-pointer <?php echo $isActive ? 'active bg-inverse-surface text-on-secondary shadow-sm' : 'bg-surface-container hover:bg-surface-container-high text-on-surface'; ?>">
              <span><?php echo esc_html($status); ?></span>
              <span class="px-2 py-0.5 rounded-full <?php echo $isActive ? 'bg-primary-container text-on-surface font-bold' : 'bg-surface-container-lowest text-secondary font-semibold'; ?> text-label-sm"><?php echo esc_html(number_format($statusCount)); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions */
  private static function renderFiltersSection(
    string $bucket,
    array $filters,
    array $ticketEmployees,
    array $filterOptions,
    string $baseUrl,
    bool $isMyTasks = false,
    string $effectiveBucket = ''
  ): string {
    $activeFilterKeys = array_filter(
      ['busqueda', 'ticket_id', 'id_empleado', 'medio', 'barrio', 'sla_filter', 'solicitante', 'celular', 'correo', 'fecha_desde', 'fecha_hasta'],
      static fn(string $k): bool => trim((string) ($filters[$k] ?? '')) !== ''
    );
    $activeCount = count($activeFilterKeys);

    ob_start();
?>
    <section class="w-full px-margin pb-space-md">
      <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg">
        <div class="flex items-center justify-between pb-space-md cursor-pointer" id="filter-header" onclick="document.getElementById('advanced-filters-panel').classList.toggle('hidden'); document.getElementById('advanced-filters-panel').classList.toggle('grid');">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-secondary text-[22px]">tune</span>
            <h2 class="font-headline-sm text-headline-sm text-on-surface">Filtros Operativos de Tareas</h2>
            <?php if ($activeCount > 0): ?>
              <span class="font-label-sm text-label-sm bg-primary-container text-on-surface px-2 py-0.5 rounded-full font-semibold ml-2">Filtros Activos: <?php echo esc_html((string) $activeCount); ?></span>
            <?php endif; ?>
          </div>
          <button type="button" class="flex items-center gap-space-xs text-secondary hover:text-on-surface cursor-pointer">
            <span class="font-label-sm text-label-sm font-semibold uppercase">Opciones Avanzadas</span>
            <span class="material-symbols-outlined text-[18px]">unfold_more</span>
          </button>
        </div>

        <form class="commercial-filter-card" data-commercial-filter-form method="get" action="<?php echo esc_url($baseUrl . '/index.php'); ?>">
          <input type="hidden" name="tab" value="<?php echo esc_attr($bucket); ?>">
          <?php if (!empty($filters['estado'])): ?>
            <input type="hidden" name="estado" value="<?php echo esc_attr((string) $filters['estado']); ?>">
          <?php endif; ?>
          <?php if ($isMyTasks && $effectiveBucket !== ''): ?>
            <input type="hidden" name="mis_bucket" value="<?php echo esc_attr($effectiveBucket); ?>">
          <?php endif; ?>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-space-sm pt-space-xs">
            <!-- Input Búsqueda Rápida -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Búsqueda rápida</label>
              <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-2.5 text-secondary text-[18px]">search</span>
                <input class="w-full pl-9 pr-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface placeholder:text-secondary/60 outline-none transition-all shadow-inner border border-transparent focus:border-outline-variant" placeholder="Título, cliente o dirección..." type="text" name="busqueda" value="<?php echo esc_attr((string) ($filters['busqueda'] ?? '')); ?>">
              </div>
            </div>

            <!-- ID Tarea -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">ID Tarea</label>
              <input class="w-full px-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface placeholder:text-secondary/60 outline-none transition-all shadow-inner border border-transparent focus:border-outline-variant" placeholder="Ej: 31797" type="text" name="ticket_id" value="<?php echo esc_attr((string) ($filters['ticket_id'] ?? '')); ?>">
            </div>

            <!-- Funcionario / Responsable -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Responsable</label>
              <select name="id_empleado" class="w-full px-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none transition-all cursor-pointer border border-transparent focus:border-outline-variant">
                <option value="">Todos los asesores</option>
                <?php foreach ($ticketEmployees as $employee): ?>
                  <?php $empVal = (string) ($employee['id'] ?? ''); ?>
                  <option value="<?php echo esc_attr($empVal); ?>"<?php selected((string) ($filters['id_empleado'] ?? ''), $empVal); ?>><?php echo esc_html((string) ($employee['name'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Tipo Gestión / Medio -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Tipo Gestión</label>
              <select name="medio" class="w-full px-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none transition-all cursor-pointer border border-transparent focus:border-outline-variant">
                <option value="">Cualquier tipo</option>
                <?php foreach (($filterOptions['medios'] ?? []) as $med): ?>
                  <option value="<?php echo esc_attr($med); ?>"<?php selected((string) ($filters['medio'] ?? ''), $med); ?>><?php echo esc_html($med); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Barrio / Ubicación -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Zona / Barrio</label>
              <select name="barrio" class="w-full px-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none transition-all cursor-pointer border border-transparent focus:border-outline-variant">
                <option value="">Todas las zonas</option>
                <?php foreach (($filterOptions['barrios'] ?? []) as $barr): ?>
                  <option value="<?php echo esc_attr($barr); ?>"<?php selected((string) ($filters['barrio'] ?? ''), $barr); ?>><?php echo esc_html($barr); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Tiempo de atención / SLA -->
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Semáforo SLA</label>
              <select name="sla_filter" class="w-full px-3 py-2 bg-surface-container-low focus:bg-surface-container-lowest rounded-xl font-body-sm text-body-sm text-on-surface outline-none transition-all cursor-pointer border border-transparent focus:border-outline-variant">
                <option value="">Todos los tiempos</option>
                <option value="atrasado"<?php selected((string) ($filters['sla_filter'] ?? ''), 'atrasado'); ?>>Atrasados</option>
                <option value="al_dia"<?php selected((string) ($filters['sla_filter'] ?? ''), 'al_dia'); ?>>Al día</option>
              </select>
            </div>
          </div>

          <!-- Campos Avanzados (Desplegables) -->
          <div id="advanced-filters-panel" class="hidden grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-space-sm pt-space-md border-t border-surface-container mt-space-md">
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Solicitante</label>
              <input class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm text-body-sm text-on-surface outline-none" placeholder="Nombre cliente" type="text" name="solicitante" value="<?php echo esc_attr((string) ($filters['solicitante'] ?? '')); ?>">
            </div>
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Correo</label>
              <input class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm text-body-sm text-on-surface outline-none" placeholder="correo@ejemplo.com" type="text" name="correo" value="<?php echo esc_attr((string) ($filters['correo'] ?? '')); ?>">
            </div>
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Celular</label>
              <input class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm text-body-sm text-on-surface outline-none" placeholder="Número móvil" type="text" name="celular" value="<?php echo esc_attr((string) ($filters['celular'] ?? '')); ?>">
            </div>
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Fecha Desde</label>
              <input class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm text-body-sm text-on-surface outline-none" type="date" name="fecha_desde" value="<?php echo esc_attr((string) ($filters['fecha_desde'] ?? '')); ?>">
            </div>
            <div class="space-y-1">
              <label class="block font-label-sm text-label-sm text-secondary font-medium">Fecha Hasta</label>
              <input class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm text-body-sm text-on-surface outline-none" type="date" name="fecha_hasta" value="<?php echo esc_attr((string) ($filters['fecha_hasta'] ?? '')); ?>">
            </div>
          </div>

          <!-- Acciones de Filtrado -->
          <div class="flex items-center justify-end gap-space-sm pt-space-md mt-space-sm">
            <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => $bucket] + self::globalFilterParams($filters))); ?>" data-commercial-filter-link class="px-space-md py-2 rounded-xl text-secondary hover:text-on-surface hover:bg-surface-container font-label-md text-label-md transition-colors cursor-pointer">
              Restablecer
            </a>
            <button type="submit" class="flex items-center gap-space-xs bg-inverse-surface hover:bg-secondary text-on-secondary px-space-lg py-2 rounded-xl font-label-md text-label-md font-semibold transition-all shadow-sm cursor-pointer">
              <span class="material-symbols-outlined text-[18px]">filter_alt</span>
              <span>Aplicar Filtros</span>
            </button>
          </div>
        </form>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows @param array<string,int> $pagination @param array<string,mixed> $filters */
  private static function renderTicketsTableSection(
    string $bucket,
    array $rows,
    array $pagination,
    int $visibleTotal,
    $policy,
    string $baseUrl,
    array $filters
  ): string {
    ob_start();
?>
    <section class="w-full px-margin pb-space-xl">
      <div class="bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden">
        <!-- Toolbar Superior de Tabla -->
        <div class="px-space-lg py-space-md flex flex-col sm:flex-row items-center justify-between gap-space-sm bg-surface-container-low/50">
          <div class="flex items-center gap-space-sm">
            <span class="font-headline-sm text-headline-sm text-on-surface">Tareas Operativas Activas</span>
            <span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm font-semibold">Mostrando <?php echo esc_html((string) count($rows)); ?> de <?php echo esc_html((string) $visibleTotal); ?></span>
          </div>
          <div class="flex items-center gap-space-xs">
            <button type="button" class="p-2 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" title="Actualizar vista" onclick="window.location.reload()">
              <span class="material-symbols-outlined text-[18px]">sync</span>
            </button>
            <button type="button" class="p-2 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" id="btn-export-csv" title="Exportar CSV">
              <span class="material-symbols-outlined text-[18px]">file_download</span>
            </button>
          </div>
        </div>

        <!-- Tabla Responsive -->
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse" id="commercial-tickets-table">
            <thead>
              <tr class="bg-surface-container text-secondary font-label-sm text-label-sm uppercase tracking-wider">
                <th class="py-3 px-space-md font-semibold">ID / Código</th>
                <th class="py-3 px-space-md font-semibold">Tarea &amp; Solicitante</th>
                <th class="py-3 px-space-md font-semibold">Responsable</th>
                <th class="py-3 px-space-md font-semibold">Inmueble / Zona</th>
                <th class="py-3 px-space-md font-semibold">Tipo</th>
                <th class="py-3 px-space-md font-semibold">Semáforo de Tiempo</th>
                <th class="py-3 px-space-md font-semibold text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y-0 text-on-surface font-body-sm text-body-sm">
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="7" class="py-16 text-center text-secondary">
                    <span class="material-symbols-outlined text-[48px] text-secondary/40 block mb-2">folder_open</span>
                    <p class="font-headline-sm text-headline-sm">Sin tareas en esta vista</p>
                    <p class="font-body-sm text-body-sm text-secondary">Prueba con otro estado o restablece los filtros actuales.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php echo self::renderTicketRow($row, $policy); ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginador de Tabla -->
        <?php echo self::renderTablePagination($bucket, $filters, $pagination, $baseUrl); ?>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $row */
  private static function renderTicketRow(array $row, $policy): string
  {
    $pk = (int) ($row['_ID'] ?? 0);
    $logicalId = trim((string) ($row['id_ticket'] ?? '')) ?: (string) $pk;
    $subject = trim((string) ($row['asunto'] ?? '')) ?: 'Tarea comercial';
    $status = trim((string) ($row['estado_comercial'] ?? 'Sin estado'));
    $requester = trim((string) ($row['solicitante'] ?? '')) ?: 'Sin solicitante';
    $requesterPhone = trim((string) ($row['celular_solicitante'] ?? $row['celular'] ?? ''));
    $assigned = trim((string) ($row['nombre_empleado'] ?? 'Sin asignar')) ?: 'Sin asignar';
    $assignedCargo = trim((string) ($row['cargo_empleado'] ?? 'Asesor Comercial'));

    $propertyTitle = trim((string) ($row['inmueble'] ?? ''));
    if ($propertyTitle === '') {
      $idInm = trim((string) ($row['id_inmueble'] ?? ''));
      $propertyTitle = $idInm !== '' ? ('Inmueble #' . $idInm) : 'Inmueble comercial';
    }
    $locationZone = trim(implode(', ', array_filter([trim((string) ($row['barrio'] ?? '')), trim((string) ($row['direccion'] ?? ''))])));

    $priority = trim((string) ($row['prioridad'] ?? ''));
    $priorityLabel = '';
    $priorityColor = 'text-secondary';
    if (stripos($priority, 'alta') !== false || stripos($priority, 'urgente') !== false) {
      $priorityLabel = 'Prioridad Alta';
      $priorityColor = 'text-error';
    } elseif (stripos($priority, 'media') !== false) {
      $priorityLabel = 'Prioridad Media';
      $priorityColor = 'text-tertiary';
    } elseif ($priority !== '') {
      $priorityLabel = $priority;
    }

    $managementType = trim((string) ($row['tipo_inmueble'] ?? $row['medio'] ?? $row['tipo'] ?? 'Comercial'));
    if ($managementType === '') {
      $managementType = 'Venta / Arriendo';
    }

    $slaStatus = trim((string) ($row['scm_sla_status'] ?? ''));
    $attentionDays = (int) ($row['scm_attention_days'] ?? 0);
    $dueDays = (int) ($row['scm_sla_due_days'] ?? 0);
    if ($dueDays <= 0) {
      $dueDays = 30;
    }

    $generalEstado = (string) ($row['estado'] ?? '');
    $isTicketClosed = ($slaStatus === 'cerrado' || CommercialStatusCatalog::isClosed($status, $generalEstado));
    $isTicketPostponed = (!$isTicketClosed && ($slaStatus === 'postergado' || CommercialStatusCatalog::isPostponed($status, $generalEstado)));

    $isOverdue = (!$isTicketClosed && !$isTicketPostponed && $slaStatus === 'atrasado');
    $isWarning = (!$isTicketClosed && !$isTicketPostponed && !$isOverdue && $attentionDays >= (int) ($dueDays * 0.8));

    if ($isTicketClosed) {
      $slaBadgeLabel = 'Cerrado';
      $slaBadgeBg = 'bg-surface-container';
      $slaBadgeText = 'text-on-surface-variant';
      $slaBarBg = 'bg-surface-container';
      $slaBarFill = 'bg-outline-variant';
      $slaBarPct = 100;
      $slaDaysColor = 'text-on-surface-variant';
    } elseif ($isTicketPostponed) {
      $slaBadgeLabel = 'Postergado';
      $slaBadgeBg = 'bg-amber-100';
      $slaBadgeText = 'text-amber-800';
      $slaBarBg = 'bg-amber-50';
      $slaBarFill = 'bg-amber-500';
      $slaBarPct = 50;
      $slaDaysColor = 'text-amber-700';
    } elseif ($isOverdue) {
      $slaBadgeLabel = 'Vencido';
      $slaBadgeBg = 'bg-error-container';
      $slaBadgeText = 'text-on-error-container';
      $slaBarBg = 'bg-error-container';
      $slaBarFill = 'bg-error';
      $slaBarPct = 100;
      $slaDaysColor = 'text-error';
    } elseif ($isWarning) {
      $slaBadgeLabel = 'Por Vencer';
      $slaBadgeBg = 'bg-tertiary-fixed';
      $slaBadgeText = 'text-on-tertiary-fixed';
      $slaBarBg = 'bg-tertiary-fixed-dim/40';
      $slaBarFill = 'bg-tertiary';
      $slaBarPct = min(100, (int) round(($attentionDays / $dueDays) * 100));
      $slaDaysColor = 'text-tertiary';
    } else {
      $slaBadgeLabel = 'OK';
      $slaBadgeBg = 'bg-surface-container';
      $slaBadgeText = 'text-on-surface';
      $slaBarBg = 'bg-surface-container';
      $slaBarFill = 'bg-secondary';
      $slaBarPct = min(100, max(5, (int) round(($attentionDays / $dueDays) * 100)));
      $slaDaysColor = 'text-on-surface';
    }

    $badgeTone = 'bg-surface-container text-on-surface';
    if (stripos($status, 'aprob') !== false || stripos($status, 'cierre') !== false || stripos($status, 'nuevo') !== false) {
      $badgeTone = 'bg-primary-container text-on-surface';
    } elseif (stripos($status, 'contact') !== false || stripos($status, 'muestra') !== false || stripos($status, 'inmueble') !== false) {
      $badgeTone = 'bg-surface-container-high text-on-secondary-container';
    } elseif (stripos($status, 'estudio') !== false || stripos($status, 'prospect') !== false) {
      $badgeTone = 'bg-tertiary-fixed text-on-tertiary-fixed';
    }

    $canOpen = !$policy instanceof CommercialAccessPolicy || $policy->canAct('ver_ticket');
    $empInitials = self::initials($assigned);

    ob_start();
?>
    <tr class="hover:bg-surface-container-low transition-colors group">
      <td class="py-space-md px-space-md whitespace-nowrap">
        <span class="inline-flex items-center gap-1 font-label-md text-label-md font-semibold px-2.5 py-1 rounded-lg bg-surface-container text-on-surface">
          #<?php echo esc_html($logicalId); ?>
        </span>
        <?php if ($priorityLabel !== ''): ?>
          <span class="block text-[10px] <?php echo esc_attr($priorityColor); ?> font-semibold mt-0.5"><?php echo esc_html($priorityLabel); ?></span>
        <?php endif; ?>
      </td>
      <td class="py-space-md px-space-md">
        <?php if ($canOpen): ?>
          <button type="button" class="text-left font-title-md text-title-md font-semibold text-on-surface group-hover:text-primary transition-colors cursor-pointer" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>">
            <?php echo esc_html($subject); ?>
          </button>
        <?php else: ?>
          <div class="font-title-md text-title-md font-semibold text-on-surface">
            <?php echo esc_html($subject); ?>
          </div>
        <?php endif; ?>
        <div class="text-secondary font-body-sm text-body-sm flex items-center gap-1.5 mt-0.5">
          <span class="material-symbols-outlined text-[14px]">person</span>
          <span><?php echo esc_html($requester); ?></span>
          <?php if ($requesterPhone !== ''): ?>
            <span class="text-outline-variant">•</span>
            <span class="text-secondary font-mono text-[11px]"><?php echo esc_html($requesterPhone); ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td class="py-space-md px-space-md whitespace-nowrap">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center font-semibold text-label-sm">
            <?php echo esc_html($empInitials); ?>
          </div>
          <div>
            <span class="block font-label-md text-label-md font-semibold text-on-surface"><?php echo esc_html($assigned); ?></span>
            <span class="block text-[11px] text-secondary"><?php echo esc_html($assignedCargo); ?></span>
          </div>
        </div>
      </td>
      <td class="py-space-md px-space-md">
        <div class="font-label-md text-label-md font-medium text-on-surface"><?php echo esc_html($propertyTitle); ?></div>
        <?php if ($locationZone !== ''): ?>
          <div class="text-secondary flex items-center gap-1 text-[11px] mt-0.5">
            <span class="material-symbols-outlined text-[13px] text-tertiary">location_on</span>
            <span><?php echo esc_html($locationZone); ?></span>
          </div>
        <?php endif; ?>
      </td>
      <td class="py-space-md px-space-md whitespace-nowrap">
        <span class="inline-block px-2.5 py-1 rounded-full <?php echo esc_attr($badgeTone); ?> font-label-sm text-label-sm font-semibold">
          <?php echo esc_html($status); ?>
        </span>
      </td>
      <td class="py-space-md px-space-md whitespace-nowrap">
        <div class="space-y-1 w-36">
          <div class="flex items-center justify-between text-[11px]">
            <span class="font-semibold <?php echo esc_attr($slaDaysColor); ?>"><?php echo esc_html((string) $attentionDays); ?> / <?php echo esc_html((string) $dueDays); ?> días</span>
            <span class="px-1.5 py-0.2 rounded <?php echo esc_attr($slaBadgeBg . ' ' . $slaBadgeText); ?> font-bold text-[10px]"><?php echo esc_html($slaBadgeLabel); ?></span>
          </div>
          <div class="w-full h-1.5 rounded-full <?php echo esc_attr($slaBarBg); ?> overflow-hidden">
            <div class="<?php echo esc_attr($slaBarFill); ?> h-full rounded-full" style="width: <?php echo esc_attr((string) $slaBarPct); ?>%;"></div>
          </div>
        </div>
      </td>
      <td class="py-space-md px-space-md text-right whitespace-nowrap">
        <div class="flex items-center justify-end gap-1">
          <?php if ($requesterPhone !== ''): ?>
            <?php $phoneClean = preg_replace('/[^0-9]/', '', $requesterPhone); ?>
            <a href="https://wa.me/<?php echo esc_attr($phoneClean); ?>" target="_blank" rel="noopener" class="p-1.5 rounded-lg hover:bg-surface-container text-secondary hover:text-on-surface transition-colors inline-flex" title="Contactar por WhatsApp">
              <span class="material-symbols-outlined text-[18px]">phone_forwarded</span>
            </a>
          <?php endif; ?>
          <?php if ($canOpen): ?>
            <button type="button" class="p-1.5 rounded-lg hover:bg-surface-container text-secondary hover:text-on-surface transition-colors cursor-pointer inline-flex" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>" title="Ver ficha completa">
              <span class="material-symbols-outlined text-[18px]">visibility</span>
            </button>
            <button type="button" class="p-1.5 rounded-lg hover:bg-surface-container text-secondary hover:text-on-surface transition-colors cursor-pointer inline-flex" data-commercial-open-case="<?php echo esc_attr((string) $pk); ?>" title="Más opciones">
              <span class="material-symbols-outlined text-[18px]">more_vert</span>
            </button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters @param array<string,int> $pagination */
  private static function renderTablePagination(string $bucket, array $filters, array $pagination, string $baseUrl): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? 0);
    $common = ['tab' => $bucket] + array_diff_key(self::filterParams($filters), ['page' => true]);

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    ob_start();
?>
    <div class="px-space-lg py-space-md flex flex-col sm:flex-row items-center justify-between gap-space-sm bg-surface-container-lowest border-t border-surface-container">
      <span class="font-body-sm text-body-sm text-secondary">
        Página <strong><?php echo esc_html((string) $page); ?></strong> de <strong><?php echo esc_html((string) $totalPages); ?></strong> (<?php echo esc_html(number_format($total)); ?> registros totales)
      </span>
      <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
          <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $page - 1])); ?>" class="px-3 py-1.5 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm text-label-sm transition-colors cursor-pointer">
            Anterior
          </a>
        <?php else: ?>
          <button class="px-3 py-1.5 rounded-lg bg-surface-container text-on-surface font-label-sm text-label-sm opacity-40 cursor-not-allowed" disabled>
            Anterior
          </button>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="w-8 h-8 rounded-lg bg-inverse-surface text-on-secondary font-label-sm text-label-sm font-semibold flex items-center justify-center">
              <?php echo $i; ?>
            </span>
          <?php else: ?>
            <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $i])); ?>" class="w-8 h-8 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm text-label-sm flex items-center justify-center transition-colors cursor-pointer">
              <?php echo $i; ?>
            </a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
          <span class="text-secondary px-1">...</span>
          <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $totalPages])); ?>" class="w-8 h-8 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm text-label-sm flex items-center justify-center transition-colors cursor-pointer">
            <?php echo $totalPages; ?>
          </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a data-commercial-filter-link href="<?php echo esc_url(self::url($baseUrl, $common + ['page' => $page + 1])); ?>" class="px-3 py-1.5 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface font-label-sm text-label-sm transition-colors cursor-pointer">
            Siguiente
          </a>
        <?php else: ?>
          <button class="px-3 py-1.5 rounded-lg bg-surface-container text-on-surface font-label-sm text-label-sm opacity-40 cursor-not-allowed" disabled>
            Siguiente
          </button>
        <?php endif; ?>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $slaSummary @param array<int,array<string,mixed>> $overdueAdvisors @param array<string,mixed> $filters */
  private static function renderSideDrawer(array $slaSummary, array $overdueAdvisors, string $baseUrl, array $filters): string
  {
    $taskCompliancePct = max(0, min(100, (int) ($slaSummary['porcentaje_cumplimiento'] ?? 0)));
    $taskOverdue = (int) ($slaSummary['atrasados'] ?? 0);
    $gap = max(0, 85 - $taskCompliancePct);

    ob_start();
?>
    <div class="fixed inset-y-0 right-0 z-50 w-full sm:w-[480px] bg-surface-container-lowest shadow-2xl transform translate-x-full transition-transform duration-300 ease-in-out flex flex-col justify-between border-l border-outline-variant/30" id="side-drawer">
      <!-- Header Drawer -->
      <div class="p-space-lg bg-surface-container-low flex items-center justify-between">
        <div class="flex items-center gap-space-xs">
          <span class="p-2 rounded-xl bg-error-container text-on-error-container">
            <span class="material-symbols-outlined text-[20px]">warning</span>
          </span>
          <div>
            <span class="font-label-sm text-label-sm uppercase font-semibold text-error">Protocolo de Escalabilidad</span>
            <h2 class="font-headline-sm text-headline-sm text-on-surface">Auditoría: Tareas Atrasadas</h2>
          </div>
        </div>
        <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container-high text-secondary flex items-center justify-center cursor-pointer transition-colors" onclick="document.getElementById('side-drawer').classList.add('translate-x-full')">
          <span class="material-symbols-outlined text-[20px]">close</span>
        </button>
      </div>

      <!-- Contenido Drawer -->
      <div class="p-space-lg overflow-y-auto space-y-space-md flex-1">
        <div class="bg-error-container/40 p-space-md rounded-2xl space-y-1">
          <div class="flex items-center justify-between font-label-md text-label-md text-on-error-container font-semibold">
            <span>Meta de Cumplimiento: 85%</span>
            <span class="text-error font-bold">Actual: <?php echo esc_html((string) $taskCompliancePct); ?>%</span>
          </div>
          <p class="font-body-sm text-body-sm text-on-surface-variant">
            El indicador general presenta una desviación de <?php echo esc_html((string) $gap); ?> puntos porcentuales. <?php echo esc_html((string) $taskOverdue); ?> clientes esperan respuesta hace más de 48 horas laborales.
          </p>
        </div>

        <div class="space-y-space-xs">
          <h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary font-semibold">
            Distribución por Asesor Comercial
          </h4>
          <div class="space-y-2 pt-1">
            <?php if (empty($overdueAdvisors)): ?>
              <div class="p-space-md rounded-xl bg-surface-container-low text-center text-secondary font-body-sm">
                No hay asesores con tareas atrasadas actualmente.
              </div>
            <?php else: ?>
              <?php foreach ($overdueAdvisors as $advisor): ?>
                <div class="bg-surface-container-low p-space-sm rounded-xl flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-error text-on-error flex items-center justify-center font-semibold text-label-sm">
                      <?php echo esc_html((string) ($advisor['initials'] ?? 'KY')); ?>
                    </div>
                    <div>
                      <span class="block font-label-md text-label-md font-semibold text-on-surface"><?php echo esc_html((string) ($advisor['name'] ?? 'Asesor')); ?></span>
                      <span class="block font-body-sm text-body-sm text-secondary"><?php echo esc_html((string) ($advisor['count'] ?? 0)); ?> tareas atrasadas</span>
                    </div>
                  </div>
                  <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'id_empleado' => $advisor['id'], 'sla_filter' => 'atrasado'])); ?>" data-commercial-filter-link class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm text-label-sm font-semibold transition-colors cursor-pointer" onclick="document.getElementById('side-drawer').classList.add('translate-x-full')">
                    Ver cartera
                  </a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="p-space-md rounded-2xl bg-surface-container space-y-2">
          <span class="font-label-md text-label-md font-semibold text-on-surface flex items-center gap-1">
            <span class="material-symbols-outlined text-[18px] text-tertiary">lightbulb</span>
            Acción Operativa Sugerida
          </span>
          <p class="font-body-sm text-body-sm text-on-surface-variant">
            Activar la cuadrilla de soporte comercial para descongestionar el embudo de atención y transferir leads fríos a automatización WhatsApp.
          </p>
        </div>
      </div>

      <!-- Footer Drawer -->
      <div class="p-space-lg bg-surface-container-low flex items-center gap-space-sm">
        <button type="button" class="flex-1 py-2.5 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface font-label-md text-label-md font-semibold transition-colors cursor-pointer" onclick="document.getElementById('side-drawer').classList.add('translate-x-full')">
          Cerrar
        </button>
        <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'abiertos', 'sla_filter' => 'atrasado'])); ?>" data-commercial-filter-link class="flex-1 text-center py-2.5 rounded-xl bg-inverse-surface hover:bg-secondary text-on-secondary font-label-md text-label-md font-semibold transition-all shadow-md cursor-pointer" onclick="document.getElementById('side-drawer').classList.add('translate-x-full')">
          Gestionar Todo
        </a>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $result @param array<string,mixed> $filters @param array<int,array<string,string>> $ticketEmployees @param array<string,mixed> $filterOptions @param array<string,int> $tabCounts */
  public static function renderTickets(
    string $bucket,
    array $result,
    array $filters,
    array $ticketEmployees,
    array $filterOptions,
    array $tabCounts,
    $policy,
    string $baseUrl,
    array $homeDashboard = []
  ): string {
    $isMyTasks = ($bucket === 'mis_tickets');
    $effectiveBucket = $isMyTasks ? self::myTasksBucket($filters, (string) ($result['effective_bucket'] ?? '')) : $bucket;
    $bucketDef = CommercialStatusCatalog::buckets()[$effectiveBucket] ?? CommercialStatusCatalog::buckets()['abiertos'];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
    $bucketCounts = is_array($result['bucket_counts'] ?? null) ? $result['bucket_counts'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
    $slaSummary = is_array($result['sla_summary'] ?? null) ? $result['sla_summary'] : (is_array($homeDashboard['sla_summary'] ?? null) ? $homeDashboard['sla_summary'] : []);
    $overdueAdvisors = is_array($homeDashboard['overdue_advisors'] ?? null) ? $homeDashboard['overdue_advisors'] : [];

    $bucketTotal = (int) ($result['bucket_total'] ?? array_sum(array_intersect_key($counts, array_flip($bucketDef['statuses']))));
    $visibleTotal = isset($pagination['total']) ? (int) $pagination['total'] : $bucketTotal;

    $titles = [
      'abiertos' => 'Tareas Comerciales Abiertas',
      'mis_tickets' => 'Mis Tareas Asignadas',
      'postergados' => 'Tareas Comerciales Postergadas',
      'cerrados' => 'Histórico de Tareas Cerradas',
    ];
    $pageTitle = $titles[$bucket] ?? $bucketDef['label'];

    ob_start();
?>
    <div class="flex flex-col w-full">
      <!-- Sub-header Operativo -->
      <section class="w-full px-margin py-space-md">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm">
          <div class="space-y-space-xs">
            <div class="flex items-center gap-space-xs">
              <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-primary-container"></span>
              <span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary font-semibold"><?php echo esc_html($bucketDef['label']); ?></span>
              <span class="text-outline-variant">•</span>
              <span class="font-label-sm text-label-sm text-secondary flex items-center gap-1">
                <span class="material-symbols-outlined text-[15px]">calendar_today</span>
                <?php echo esc_html(self::currentDateFormatted()); ?>
              </span>
            </div>
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight"><?php echo esc_html($pageTitle); ?></h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-3xl">
              <?php echo esc_html($bucketDef['description']); ?>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-space-sm pt-space-xs lg:pt-0">
            <button type="button" class="flex items-center gap-space-xs bg-surface-container-low hover:bg-surface-container text-on-surface px-space-md py-2.5 rounded-xl font-label-md text-label-md transition-all shadow-sm cursor-pointer" id="btn-export-report">
              <span class="material-symbols-outlined text-[18px] text-secondary">download</span>
              <span>Exportar Vista</span>
            </button>
            <?php if (!empty($slaSummary['atrasados'])): ?>
              <button type="button" class="flex items-center gap-space-xs bg-error-container hover:bg-error/20 text-on-error-container px-space-md py-2.5 rounded-xl font-label-md text-label-md transition-all shadow-sm cursor-pointer" id="btn-open-drawer">
                <span class="material-symbols-outlined text-[18px]">warning</span>
                <span>Auditar Atrasados (<?php echo esc_html((string) $slaSummary['atrasados']); ?>)</span>
              </button>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php if ($isMyTasks): ?>
        <!-- Sub-pestañas para Mis Tareas -->
        <section class="w-full px-margin pb-space-xs">
          <div class="bg-surface-container-lowest p-space-sm rounded-2xl shadow-sm flex items-center gap-2">
            <?php
              $subStages = [
                'abiertos' => ['label' => 'Abiertas', 'count' => (int) ($bucketCounts['abiertos'] ?? 0)],
                'postergados' => ['label' => 'Postergadas', 'count' => (int) ($bucketCounts['postergados'] ?? 0)],
                'cerrados' => ['label' => 'Cerradas', 'count' => (int) ($bucketCounts['cerrados'] ?? 0)],
              ];
            ?>
            <?php foreach ($subStages as $subKey => $st): ?>
              <?php $isSubActive = ($effectiveBucket === $subKey); ?>
              <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'mis_tickets', 'mis_bucket' => $subKey])); ?>" data-commercial-filter-link class="flex items-center gap-2 px-space-md py-2 rounded-xl font-label-md text-label-md transition-all cursor-pointer <?php echo $isSubActive ? 'bg-inverse-surface text-on-secondary shadow-sm' : 'bg-surface-container hover:bg-surface-container-high text-on-surface'; ?>">
                <span><?php echo esc_html($st['label']); ?></span>
                <span class="px-2 py-0.5 rounded-full text-label-sm <?php echo $isSubActive ? 'bg-primary-container text-on-surface font-bold' : 'bg-surface-container-lowest text-secondary'; ?>"><?php echo esc_html(number_format($st['count'])); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- Embudo de Estados -->
      <?php echo self::renderFunnelPills($bucket, $counts, $bucketTotal, $filters, $baseUrl, $effectiveBucket); ?>

      <!-- Filtros Operativos -->
      <?php echo self::renderFiltersSection($bucket, $filters, $ticketEmployees, $filterOptions, $baseUrl, $isMyTasks, $effectiveBucket); ?>

      <!-- Tabla de Tareas -->
      <?php echo self::renderTicketsTableSection($bucket, $rows, $pagination, $visibleTotal, $policy, $baseUrl, $filters); ?>

      <!-- Drawer Auditoría -->
      <?php if (!empty($slaSummary)): ?>
        <?php echo self::renderSideDrawer($slaSummary, $overdueAdvisors, $baseUrl, $filters); ?>
      <?php endif; ?>
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
    $isUpdates = ($activePanel === 'updates');
    $signMode = in_array($selectedSignState, ['Atrasado', 'A Tiempo'], true) ? 'new' : 'maintenance';
    $pageTitle = $isUpdates ? 'Actualizaciones de Inmuebles' : 'Avisos en Fachada';
    $pageDescription = $isUpdates
      ? 'Control completo de inmuebles públicos que están al día, en alerta o vencidos por actualización.'
      : 'Control completo de avisos nuevos, retoques y rutas operativas por barrio o ruta.';

    ob_start();
?>
    <div class="flex flex-col w-full space-y-space-md py-space-md" data-commercial-home-controls>
      <section class="w-full px-margin">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md bg-surface-container-lowest p-space-lg rounded-2xl shadow-sm">
          <div class="space-y-space-xs">
            <div class="flex items-center gap-space-xs">
              <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-secondary"></span>
              <span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary font-semibold">Módulo Operativo</span>
              <span class="text-outline-variant">•</span>
              <span class="font-label-sm text-label-sm text-secondary"><?php echo esc_html(self::currentDateFormatted()); ?></span>
            </div>
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight"><?php echo esc_html($pageTitle); ?></h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-3xl"><?php echo esc_html($pageDescription); ?></p>
          </div>
        </div>
      </section>

      <?php if ($isUpdates): ?>
        <section class="w-full px-margin active" id="commercial-property-updates-panel" data-commercial-home-control-panel="updates">
          <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg space-y-space-md">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm pb-space-sm border-b border-surface-container">
              <div>
                <h3 class="font-headline-sm text-headline-sm text-on-surface">Panel para actualizar inmuebles</h3>
                <p class="font-body-sm text-body-sm text-on-surface-variant">Filtra inmuebles públicos por vigencia de actualización y abre la ficha técnica.</p>
              </div>
              <span class="px-3 py-1 rounded-full bg-surface-container font-label-md text-label-md text-secondary font-semibold" data-commercial-property-total>0 inmuebles</span>
            </div>

            <form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-space-sm" data-commercial-property-control>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Código</label>
                <input type="text" name="codigo" placeholder="Ej: 90480" value="<?php echo esc_attr($selectedCode); ?>" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none border border-transparent focus:border-outline-variant">
              </div>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Gestión</label>
                <select name="gestion" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                  <option value="">Todas</option>
                  <option value="Arriendo"<?php selected($selectedBusiness, 'Arriendo'); ?>>Arriendo</option>
                  <option value="Venta"<?php selected($selectedBusiness, 'Venta'); ?>>Venta</option>
                </select>
              </div>
              <?php if ($canSeeAll): ?>
                <div class="space-y-1">
                  <label class="block font-label-sm text-label-sm text-secondary font-medium">Funcionario</label>
                  <select name="id_empleado" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                    <option value="">Todos</option>
                    <?php foreach ($ticketEmployees as $emp): ?>
                      <option value="<?php echo esc_attr((string) ($emp['id'] ?? '')); ?>"<?php selected($selectedEmployee, (string) ($emp['id'] ?? '')); ?>><?php echo esc_html((string) ($emp['name'] ?? '')); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Estado</label>
                <select name="estado_actualizacion" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                  <option value="">Todos</option>
                  <option value="Vencido"<?php selected($selectedUpdateState, 'Vencido'); ?>>Vencidos</option>
                  <option value="Alerta"<?php selected($selectedUpdateState, 'Alerta'); ?>>Alerta</option>
                  <option value="OK"<?php selected($selectedUpdateState, 'OK'); ?>>Al día</option>
                </select>
              </div>
              <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 bg-inverse-surface hover:bg-secondary text-on-secondary py-2 rounded-xl font-label-md font-semibold transition-colors cursor-pointer">Filtrar</button>
                <button type="button" data-commercial-control-clear class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer">Limpiar</button>
              </div>
            </form>

            <div class="font-label-sm text-label-sm text-secondary font-medium pt-1" data-commercial-property-stats>Al día: 0 · Alerta: 0 · Vencidos: 0</div>

            <div class="overflow-x-auto rounded-xl border border-surface-container">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="bg-surface-container text-secondary font-label-sm text-label-sm uppercase">
                    <th class="py-3 px-space-md">Código</th>
                    <?php if ($canSeeAll): ?><th class="py-3 px-space-md">Funcionario</th><?php endif; ?>
                    <th class="py-3 px-space-md">Gestión</th>
                    <th class="py-3 px-space-md">Estado</th>
                    <th class="py-3 px-space-md">Tiempo</th>
                    <th class="py-3 px-space-md text-right">Acciones</th>
                  </tr>
                </thead>
                <tbody data-commercial-property-rows class="divide-y divide-surface-container font-body-sm">
                  <tr><td colspan="<?php echo $canSeeAll ? '6' : '5'; ?>" class="py-8 text-center text-secondary">Cargando inmuebles…</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      <?php else: ?>
        <section class="w-full px-margin active" id="commercial-signs-panel" data-commercial-home-control-panel="signs">
          <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg space-y-space-md">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm pb-space-sm border-b border-surface-container">
              <div>
                <h3 class="font-headline-sm text-headline-sm text-on-surface">Panel de avisos en fachada</h3>
                <p class="font-body-sm text-body-sm text-on-surface-variant">Controla retoques, avisos nuevos y rutas por barrio o ruta.</p>
              </div>
              <span class="px-3 py-1 rounded-full bg-surface-container font-label-md text-label-md text-secondary font-semibold" data-commercial-sign-total>0 avisos</span>
            </div>

            <div class="flex flex-wrap gap-2" role="tablist">
              <button class="px-space-md py-2 rounded-xl font-label-md text-label-md transition-all cursor-pointer <?php echo $signMode === 'maintenance' ? 'bg-inverse-surface text-on-secondary shadow-sm' : 'bg-surface-container hover:bg-surface-container-high text-on-surface'; ?>" type="button" data-commercial-sign-tab="maintenance">Retoque de avisos</button>
              <button class="px-space-md py-2 rounded-xl font-label-md text-label-md transition-all cursor-pointer <?php echo $signMode === 'new' ? 'bg-inverse-surface text-on-secondary shadow-sm' : 'bg-surface-container hover:bg-surface-container-high text-on-surface'; ?>" type="button" data-commercial-sign-tab="new">Avisos nuevos</button>
              <?php if ($canSeeAll): ?>
                <button class="px-space-md py-2 rounded-xl font-label-md text-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-all cursor-pointer" type="button" data-commercial-sign-tab="routes_neighborhood">Rutas x barrio</button>
                <button class="px-space-md py-2 rounded-xl font-label-md text-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-all cursor-pointer" type="button" data-commercial-sign-tab="routes_route">Rutas x ruta</button>
              <?php endif; ?>
            </div>

            <form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm pt-2" data-commercial-sign-control data-mode="<?php echo esc_attr($signMode); ?>">
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Código</label>
                <input type="text" name="codigo" placeholder="Ej: 90480" value="<?php echo esc_attr($selectedCode); ?>" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
              </div>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Gestión</label>
                <select name="gestion" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                  <option value="">Todas</option>
                  <option value="Arriendo"<?php selected($selectedBusiness, 'Arriendo'); ?>>Arriendo</option>
                  <option value="Venta"<?php selected($selectedBusiness, 'Venta'); ?>>Venta</option>
                </select>
              </div>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Barrio</label>
                <select name="barrio" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                  <option value="">Todos</option>
                  <?php foreach ($barrios as $bar): ?><option value="<?php echo esc_attr($bar); ?>"<?php selected($selectedNeighborhood, $bar); ?>><?php echo esc_html($bar); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="space-y-1">
                <label class="block font-label-sm text-label-sm text-secondary font-medium">Estado</label>
                <select name="estado_aviso" class="w-full px-3 py-2 bg-surface-container-low rounded-xl font-body-sm outline-none">
                  <option value="">Todos</option>
                  <option value="Vencido"<?php selected($selectedSignState, 'Vencido'); ?>>Vencido</option>
                  <option value="Alerta"<?php selected($selectedSignState, 'Alerta'); ?>>Alerta</option>
                  <option value="OK"<?php selected($selectedSignState, 'OK'); ?>>Al día</option>
                  <option value="Atrasado"<?php selected($selectedSignState, 'Atrasado'); ?>>Nuevo atrasado</option>
                  <option value="A Tiempo"<?php selected($selectedSignState, 'A Tiempo'); ?>>Nuevo al día</option>
                </select>
              </div>
              <div class="sm:col-span-2 lg:col-span-4 flex items-center justify-end gap-2 pt-2">
                <button type="button" data-commercial-control-clear class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer">Limpiar</button>
                <button type="submit" class="bg-inverse-surface hover:bg-secondary text-on-secondary px-space-lg py-2 rounded-xl font-label-md font-semibold transition-colors cursor-pointer">Filtrar Avisos</button>
              </div>
            </form>

            <div class="overflow-x-auto rounded-xl border border-surface-container mt-space-md">
              <div data-commercial-sign-rows class="py-8 text-center text-secondary font-body-sm">Cargando avisos…</div>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <!-- Modals Ficha Inmueble y Detalle Aviso -->
    <div class="fixed inset-0 z-50 items-center justify-center p-4 bg-inverse-surface/60 backdrop-blur-sm commercial-modal" id="commercial-property-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-xl max-h-[85vh] overflow-hidden flex flex-col relative" role="document">
        <button type="button" class="absolute top-4 right-4 z-10 w-8 h-8 rounded-full bg-surface-container hover:bg-surface-container-high text-on-surface flex items-center justify-center cursor-pointer" data-commercial-close-property aria-label="Cerrar ficha">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
        <div class="p-space-lg overflow-y-auto" data-commercial-property-modal-content></div>
      </div>
    </div>

    <div class="fixed inset-0 z-50 items-center justify-center p-4 bg-inverse-surface/60 backdrop-blur-sm commercial-modal" id="commercial-sign-detail-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-xl max-h-[85vh] overflow-hidden flex flex-col relative" role="document">
        <button type="button" class="absolute top-4 right-4 z-10 w-8 h-8 rounded-full bg-surface-container hover:bg-surface-container-high text-on-surface flex items-center justify-center cursor-pointer" data-commercial-close-sign-detail aria-label="Cerrar detalle">
          <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
        <div class="p-space-lg overflow-y-auto" data-commercial-sign-detail-content></div>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public static function renderPropertyUpdateRows(array $rows, bool $showEmployee): string
  {
    if ($rows === []) {
      return '<tr><td colspan="' . ($showEmployee ? '6' : '5') . '" class="py-8 text-center text-secondary">Sin inmuebles para este filtro.</td></tr>';
    }
    $html = '';
    foreach ($rows as $row) {
      $status = (string) ($row['estado'] ?? 'OK');
      $tone = self::controlTone($status);
      $detail = esc_attr((string) json_encode($row['detalle'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));

      $badgeClass = 'bg-surface-container text-on-surface';
      if ($tone === 'danger') {
        $badgeClass = 'bg-error-container text-on-error-container';
      } elseif ($tone === 'warning') {
        $badgeClass = 'bg-tertiary-fixed text-on-tertiary-fixed';
      }

      $html .= '<tr class="hover:bg-surface-container-low transition-colors">';
      $html .= '<td class="py-3 px-space-md font-semibold text-on-surface">#' . esc_html((string) ($row['codigo'] ?? '')) . '</td>';
      if ($showEmployee) {
        $html .= '<td class="py-3 px-space-md text-on-surface">' . esc_html((string) ($row['funcionario'] ?? '')) . '</td>';
      }
      $html .= '<td class="py-3 px-space-md text-secondary">' . esc_html((string) ($row['gestion'] ?? '')) . '</td>';
      $html .= '<td class="py-3 px-space-md"><span class="inline-block px-2.5 py-0.5 rounded-full font-label-sm font-semibold ' . $badgeClass . '">' . esc_html($status) . '</span></td>';
      $html .= '<td class="py-3 px-space-md text-on-surface font-medium">' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . ' días</td>';
      $html .= '<td class="py-3 px-space-md text-right whitespace-nowrap"><div class="flex items-center justify-end gap-1.5">';
      if (trim((string) ($row['url_actualizar'] ?? '')) !== '') {
        $html .= '<a class="px-2.5 py-1 bg-inverse-surface hover:bg-secondary text-on-secondary rounded-lg font-label-sm font-semibold transition-colors" href="' . esc_url((string) $row['url_actualizar']) . '" target="_blank" rel="noopener">Actualizar</a>';
      }
      $html .= '<button class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm font-semibold transition-colors cursor-pointer" type="button" data-commercial-property-info="' . $detail . '">Ficha</button>';
      if ($showEmployee && trim((string) ($row['url_despublicar'] ?? '')) !== '') {
        $html .= '<a class="px-2.5 py-1 bg-error text-on-error rounded-lg font-label-sm font-semibold transition-colors" href="' . esc_url((string) $row['url_despublicar']) . '" target="_blank" rel="noopener">Despublicar</a>';
      }
      $html .= '</div></td></tr>';
    }
    return $html;
  }

  /** @param array<int,array<string,mixed>> $rows */
  public static function renderSignControlRows(array $rows, string $mode): string
  {
    if ($rows === []) {
      return '<div class="py-8 text-center text-secondary">Sin avisos para este filtro.</div>';
    }
    if (in_array($mode, ['routes_neighborhood', 'routes_route'], true)) {
      $groupKey = ($mode === 'routes_route' ? 'ruta' : 'barrio');
      $groups = [];
      foreach ($rows as $row) {
        $key = mb_strtoupper(trim((string) ($row[$groupKey] ?? '')), 'UTF-8') ?: ($mode === 'routes_route' ? 'SIN RUTA' : 'SIN BARRIO');
        $groups[$key][] = $row;
      }
      ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
      $html = '<div class="space-y-4 p-space-md">';
      foreach ($groups as $group => $items) {
        $tableId = 'commercial-route-' . md5((string) $group);
        $html .= '<details class="bg-surface-container-low rounded-xl p-space-md border border-surface-container"><summary class="font-headline-sm text-headline-sm text-on-surface cursor-pointer flex items-center justify-between"><span>' . esc_html((string) $group) . ' <small class="text-secondary font-body-sm font-normal">(' . count($items) . ' avisos)</small></span><button type="button" class="px-3 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm" data-commercial-copy-table="' . esc_attr($tableId) . '">Copiar tabla</button></summary>';
        $html .= '<div class="overflow-x-auto mt-space-md"><table id="' . esc_attr($tableId) . '" class="w-full text-left border-collapse text-body-sm"><thead><tr class="bg-surface-container text-secondary font-label-sm uppercase"><th class="py-2 px-3">' . ($mode === 'routes_route' ? 'Barrio' : 'Ruta') . '</th><th class="py-2 px-3">Dirección</th><th class="py-2 px-3">Punto ref.</th><th class="py-2 px-3">Cód.</th><th class="py-2 px-3">Funcionario</th><th class="py-2 px-3">Celular</th><th class="py-2 px-3">Tipo</th><th class="py-2 px-3">Maps</th></tr></thead><tbody class="divide-y divide-surface-container">';
        foreach ($items as $row) {
          $html .= self::renderSignRouteRow($row, $mode);
        }
        $html .= '</tbody></table></div></details>';
      }
      return $html . '</div>';
    }

    $headers = $mode === 'new'
      ? '<tr class="bg-surface-container text-secondary font-label-sm uppercase"><th class="py-3 px-space-md">Código</th><th class="py-3 px-space-md">Funcionario</th><th class="py-3 px-space-md">Barrio</th><th class="py-3 px-space-md">Captación</th><th class="py-3 px-space-md">Gestión</th><th class="py-3 px-space-md">Días / Max</th><th class="py-3 px-space-md">Estado</th><th class="py-3 px-space-md">Maps</th><th class="py-3 px-space-md text-right">Acción</th></tr>'
      : '<tr class="bg-surface-container text-secondary font-label-sm uppercase"><th class="py-3 px-space-md">Código</th><th class="py-3 px-space-md">Funcionario</th><th class="py-3 px-space-md">Barrio</th><th class="py-3 px-space-md">Gestión</th><th class="py-3 px-space-md">Estado</th><th class="py-3 px-space-md">Días</th><th class="py-3 px-space-md">Maps</th><th class="py-3 px-space-md text-right">Acción</th></tr>';

    $html = '<table class="w-full text-left border-collapse text-body-sm"><thead>' . $headers . '</thead><tbody class="divide-y divide-surface-container">';
    foreach ($rows as $row) {
      $status = (string) ($row['estado'] ?? 'OK');
      $tone = self::controlTone($status);
      $badgeClass = 'bg-surface-container text-on-surface';
      if ($tone === 'danger') {
        $badgeClass = 'bg-error-container text-on-error-container font-semibold';
      } elseif ($tone === 'warning') {
        $badgeClass = 'bg-tertiary-fixed text-on-tertiary-fixed font-semibold';
      }

      $maps = trim((string) ($row['maps_url'] ?? '')) !== '' ? '<a href="' . esc_url((string) $row['maps_url']) . '" target="_blank" rel="noopener" class="text-secondary hover:text-primary font-semibold">Ver mapa</a>' : '—';
      $detail = esc_attr((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));

      if ($mode === 'new') {
        $html .= '<tr class="hover:bg-surface-container-low transition-colors"><td class="py-3 px-space-md font-semibold text-on-surface">#' . esc_html((string) ($row['codigo'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['barrio'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['fecha'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['gestion'] ?? '')) . '</td><td class="py-3 px-space-md font-medium">' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . ' días</td><td class="py-3 px-space-md"><span class="px-2 py-0.5 rounded-full font-label-sm ' . $badgeClass . '">' . esc_html($status) . '</span></td><td class="py-3 px-space-md">' . $maps . '</td><td class="py-3 px-space-md text-right"><button class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm font-semibold transition-colors cursor-pointer" type="button" data-commercial-sign-detail="' . $detail . '">Detalle</button></td></tr>';
      } else {
        $html .= '<tr class="hover:bg-surface-container-low transition-colors"><td class="py-3 px-space-md font-semibold text-on-surface">#' . esc_html((string) ($row['codigo'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['barrio'] ?? '')) . '</td><td class="py-3 px-space-md">' . esc_html((string) ($row['gestion'] ?? '')) . '</td><td class="py-3 px-space-md"><span class="px-2 py-0.5 rounded-full font-label-sm ' . $badgeClass . '">' . esc_html($status) . '</span></td><td class="py-3 px-space-md font-medium">' . esc_html((string) ($row['dias'] ?? 0)) . ' / ' . esc_html((string) ($row['max'] ?? 0)) . '</td><td class="py-3 px-space-md">' . $maps . '</td><td class="py-3 px-space-md text-right"><button class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm font-semibold transition-colors cursor-pointer" type="button" data-commercial-sign-detail="' . $detail . '">Detalle</button></td></tr>';
      }
    }
    return $html . '</tbody></table>';
  }

  /** @param array<string,mixed> $row */
  private static function renderSignRouteRow(array $row, string $mode): string
  {
    $first = $mode === 'routes_route' ? (string) ($row['barrio'] ?? '') : (string) ($row['ruta'] ?? '');
    $maps = trim((string) ($row['maps_url'] ?? '')) !== '' ? '<a href="' . esc_url((string) $row['maps_url']) . '" target="_blank" rel="noopener" class="text-secondary hover:text-primary font-semibold">Mapa</a>' : '—';
    return '<tr class="hover:bg-surface-container-low transition-colors"><td class="py-2 px-3 font-medium">' . esc_html($first) . '</td><td class="py-2 px-3">' . esc_html((string) ($row['direccion'] ?? '')) . '</td><td class="py-2 px-3 text-secondary">' . esc_html((string) ($row['punto_referencia'] ?? '')) . '</td><td class="py-2 px-3 font-semibold">#' . esc_html((string) ($row['codigo'] ?? '')) . '</td><td class="py-2 px-3">' . esc_html((string) ($row['funcionario'] ?? '')) . '</td><td class="py-2 px-3 font-mono text-[11px]">' . esc_html((string) ($row['celular_funcionario'] ?? '')) . '</td><td class="py-2 px-3">' . esc_html((string) ($row['tipo'] ?? '')) . '</td><td class="py-2 px-3">' . $maps . '</td></tr>';
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

  private static function timeAgo(int $timestamp): string
  {
    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
      return 'Hace un momento';
    }
    if ($diff < 3600) {
      $mins = (int) floor($diff / 60);
      return "Hace {$mins}m";
    }
    if ($diff < 86400) {
      $hours = (int) floor($diff / 3600);
      return "Hace {$hours}h";
    }
    $days = (int) floor($diff / 86400);
    if ($days < 7) {
      return "Hace {$days}d";
    }
    return date('d/m/Y', $timestamp);
  }

  /**
   * @param array<string,mixed> $config
   * @param array<int,array<string,string>> $employees
   */
  public static function renderCalendarPage(array $config, array $employees, string $subtab = 'mine', $policy = null, string $baseUrl = ''): string
  {
    $employeeJson = json_encode($employees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $allowedCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? $config['calendar_allowed_cargos'] : ['9', '10', '17'];
    $currentEmployeeId = trim((string) ($config['calendar_current_employee_id'] ?? ''));

    $subtab = in_array($subtab, ['mine', 'team', 'due'], true) ? $subtab : 'mine';
    $mode = $subtab === 'mine' ? 'personal' : ($subtab === 'due' ? 'due' : 'team');
    $view = $subtab === 'due' ? 'pending' : 'month';

    if ($subtab === 'mine') {
      $title = 'Mi Calendario Operativo';
      $kicker = 'Agenda Personal';
      $description = 'Gestiona tus actividades, citas, tareas y recordatorios comerciales con sincronización en Google Calendar.';
      $showCreateActions = true;
      $showPendingAction = true;
      $showReportAction = true;
      $showEmployeeFilter = empty($currentEmployeeId);
    } elseif ($subtab === 'due') {
      $title = 'Control de Vencimientos';
      $kicker = 'Seguimiento y Plazos';
      $description = 'Monitorea vencimientos agrupados de preventivas, cotizaciones y compromisos comerciales.';
      $showCreateActions = false;
      $showPendingAction = false;
      $showReportAction = false;
      $showEmployeeFilter = true;
    } else {
      $title = 'Calendario del Equipo Comercial';
      $kicker = 'Disponibilidad y Equipo';
      $description = 'Consulta y programa citas y actividades de los consultores comerciales en tiempo real.';
      $showCreateActions = true;
      $showPendingAction = true;
      $showReportAction = true;
      $showEmployeeFilter = true;
    }

    ob_start();
?>
    <div class="space-y-space-md">
      <!-- Subtab bar superior del calendario -->
      <div class="flex items-center justify-between flex-wrap gap-space-sm pb-space-xs border-b border-surface-container">
        <div class="flex items-center gap-1.5 p-1 bg-surface-container-low rounded-2xl border border-surface-container/60">
          <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'mine'])); ?>"
             class="flex items-center gap-2 px-space-md py-1.5 rounded-xl font-label-md transition-all <?php echo $subtab === 'mine' ? 'bg-surface-container-lowest text-on-surface font-semibold shadow-sm' : 'text-secondary hover:text-on-surface'; ?>"
             data-commercial-tab="calendario" data-subtab="mine">
            <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>
            <span>Mi calendario</span>
          </a>
          <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'team'])); ?>"
             class="flex items-center gap-2 px-space-md py-1.5 rounded-xl font-label-md transition-all <?php echo $subtab === 'team' ? 'bg-surface-container-lowest text-on-surface font-semibold shadow-sm' : 'text-secondary hover:text-on-surface'; ?>"
             data-commercial-tab="calendario" data-subtab="team">
            <span class="material-symbols-outlined text-[18px] text-secondary">groups</span>
            <span>Calendario equipo</span>
          </a>
          <a href="<?php echo esc_url(self::url($baseUrl, ['tab' => 'calendario', 'subtab' => 'due'])); ?>"
             class="flex items-center gap-2 px-space-md py-1.5 rounded-xl font-label-md transition-all <?php echo $subtab === 'due' ? 'bg-surface-container-lowest text-on-surface font-semibold shadow-sm' : 'text-secondary hover:text-on-surface'; ?>"
             data-commercial-tab="calendario" data-subtab="due">
            <span class="material-symbols-outlined text-[18px] text-error">schedule</span>
            <span>Vencimientos</span>
          </a>
        </div>

        <div class="flex items-center gap-2">
          <span class="font-label-sm text-secondary">Franja horaria y sincronización activa</span>
        </div>
      </div>

      <!-- Panel Principal del Calendario -->
      <div class="scm-calendar-panel space-y-space-md" data-scm-calendar-panel
        data-calendar-mode="<?php echo esc_attr($mode); ?>"
        data-calendar-view="<?php echo esc_attr($view); ?>"
        data-calendar-lock-current="<?php echo $mode === 'personal' ? '1' : '0'; ?>"
        data-calendar-app-url="<?php echo esc_attr((string) ($config['calendar_app_url'] ?? '')); ?>"
        data-calendar-api-url="<?php echo esc_attr((string) ($config['calendar_api_url'] ?? '')); ?>"
        data-calendar-can-configure="1"
        data-calendar-current-employee-id="<?php echo esc_attr($currentEmployeeId); ?>"
        data-calendar-allowed-cargos="<?php echo esc_attr(implode(',', $allowedCargos)); ?>"
        data-calendar-employees-json="<?php echo esc_attr($employeeJson ?: '[]'); ?>">

        <!-- Header del calendario -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm pb-space-sm border-b border-surface-container">
          <div>
            <span class="font-label-sm uppercase font-semibold text-secondary"><?php echo esc_html($kicker); ?></span>
            <div class="flex items-center gap-3">
              <h2 class="font-headline-sm text-headline-sm text-on-surface"><?php echo esc_html($title); ?></h2>
              <span class="px-3 py-1 bg-surface-container rounded-full font-label-md text-on-surface font-semibold text-xs"><strong data-scm-calendar-total>0</strong> items</span>
            </div>
            <p class="font-body-sm text-on-surface-variant"><?php echo esc_html($description); ?></p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <?php if ($showPendingAction): ?>
              <button type="button" class="flex items-center gap-1.5 px-space-md py-2 rounded-xl font-label-md bg-surface-container hover:bg-surface-container-high text-error font-medium transition-colors cursor-pointer" data-scm-calendar-open-pending>
                <span class="material-symbols-outlined text-[18px]">warning</span>
                <span>Pendientes</span>
                <em class="not-italic bg-error/15 text-error px-1.5 py-0.5 rounded-full text-xs font-bold ml-1" data-scm-calendar-pending-action-count>0</em>
              </button>
            <?php endif; ?>
            <?php if ($showCreateActions): ?>
              <?php if ($mode === 'personal'): ?>
                <button type="button" class="flex items-center gap-1.5 px-space-md py-2 rounded-xl font-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" data-scm-calendar-open-create data-calendar-mode="single" data-calendar-kind="reminder">
                  <span class="material-symbols-outlined text-[18px] text-tertiary">notifications_active</span>
                  <span>Recordatorio</span>
                </button>
                <button type="button" class="flex items-center gap-1.5 px-space-md py-2 rounded-xl font-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" data-scm-calendar-open-create data-calendar-mode="single" data-calendar-kind="task">
                  <span class="material-symbols-outlined text-[18px] text-primary">task_alt</span>
                  <span>Tarea</span>
                </button>
              <?php endif; ?>
              <button type="button" class="flex items-center gap-1.5 px-space-md py-2 rounded-xl font-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" data-scm-calendar-open-create data-calendar-mode="multiple">
                <span class="material-symbols-outlined text-[18px] text-secondary">inventory_2</span>
                <span>Múltiple</span>
              </button>
              <button type="button" class="flex items-center gap-1.5 px-space-lg py-2 rounded-xl font-label-md font-semibold bg-primary-container hover:bg-primary-fixed-dim text-on-surface shadow-sm transition-all cursor-pointer" data-scm-calendar-open-create data-calendar-mode="single">
                <span class="material-symbols-outlined text-[18px]">add</span>
                <span>Crear evento</span>
              </button>
            <?php endif; ?>
            <?php if ($showReportAction): ?>
              <button type="button" class="flex items-center gap-1.5 px-space-md py-2 rounded-xl font-label-md bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors cursor-pointer" data-scm-calendar-open-report>
                <span class="material-symbols-outlined text-[18px]">description</span>
                <span>Informe del día</span>
              </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tarjetas KPIs -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
          <div class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container text-center">
            <div class="font-label-sm text-secondary font-medium"><?php echo $view === 'pending' ? 'Total vencimientos' : 'Pendientes'; ?></div>
            <div class="font-display-lg text-[28px] font-bold text-error leading-tight mt-1" data-scm-calendar-pending>0</div>
          </div>
          <div class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container text-center">
            <div class="font-label-sm text-secondary font-medium"><?php echo $view === 'pending' ? 'Vencidos' : 'Realizados'; ?></div>
            <div class="font-display-lg text-[28px] font-bold text-on-surface leading-tight mt-1" data-scm-calendar-done>0</div>
          </div>
          <div class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container text-center">
            <div class="font-label-sm text-secondary font-medium"><?php echo $view === 'pending' ? 'Vencen hoy' : 'Hoy'; ?></div>
            <div class="font-display-lg text-[28px] font-bold text-primary leading-tight mt-1" data-scm-calendar-today>0</div>
          </div>
          <div class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container text-center">
            <div class="font-label-sm text-secondary font-medium">Mes visible</div>
            <div class="font-title-md font-semibold text-on-surface leading-tight mt-2" data-scm-calendar-range><?php echo esc_html(date('Y-m-d')); ?></div>
          </div>
        </div>

        <?php if ($view !== 'pending'): ?>
          <!-- Filtros de búsqueda y capas para eventos -->
          <form class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container space-y-space-sm" data-scm-calendar-filters autocomplete="off">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
              <?php if ($showEmployeeFilter): ?>
                <div class="space-y-1">
                  <label class="block font-label-sm text-secondary font-medium">Funcionario</label>
                  <select name="id_empleado" data-scm-calendar-filter-employees class="w-full px-3 py-2 bg-surface-container-lowest rounded-xl font-body-sm outline-none border border-surface-container">
                    <option value=""><?php echo $mode === 'personal' ? 'Mi calendario' : 'Selecciona funcionario'; ?></option>
                    <?php foreach ($employees as $emp): ?>
                      <?php
                        $empId = (string) ($emp['id_empleado'] ?? $emp['id'] ?? '');
                        $empName = trim((string) ($emp['nombre'] ?? $emp['display_name'] ?? ''));
                        if ($empId === '') continue;
                        $isSelected = ($empId === $currentEmployeeId);
                      ?>
                      <option value="<?php echo esc_attr($empId); ?>" <?php echo $isSelected ? 'selected' : ''; ?>><?php echo esc_html($empName ?: "Funcionario #$empId"); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php else: ?>
                <input type="hidden" name="id_empleado" value="<?php echo esc_attr($currentEmployeeId); ?>">
              <?php endif; ?>
              <div class="space-y-1">
                <label class="block font-label-sm text-secondary font-medium">Categoría</label>
                <select name="id_categoria" data-scm-calendar-filter-categories class="w-full px-3 py-2 bg-surface-container-lowest rounded-xl font-body-sm outline-none border border-surface-container">
                  <option value="">Todas las categorías</option>
                </select>
              </div>
              <div class="flex items-end gap-2 <?php echo !$showEmployeeFilter ? 'sm:col-span-2' : ''; ?>">
                <button type="submit" class="flex-1 bg-inverse-surface hover:bg-secondary text-on-secondary py-2 rounded-xl font-label-md font-semibold transition-colors cursor-pointer flex items-center justify-center gap-1.5">
                  <span class="material-symbols-outlined text-[16px]">filter_alt</span>
                  <span>Filtrar</span>
                </button>
                <button type="button" data-scm-calendar-clear class="px-space-md py-2 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-xl font-label-md transition-colors cursor-pointer">
                  Limpiar
                </button>
                <span class="scm-spinner hidden" data-scm-calendar-spinner><span class="scm-spinner-dot"></span></span>
              </div>
            </div>

            <!-- Filtros de Capas & Alcance -->
            <div class="flex flex-wrap items-center justify-between gap-space-sm pt-2 border-t border-surface-container" data-scm-calendar-layer-filters aria-label="Filtros de capas">
              <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-secondary uppercase mr-1">Alcance:</span>
                <label class="flex items-center gap-1.5 px-3 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="radio" name="calendar_scope" value="mine" <?php echo $mode === 'personal' ? 'checked' : ''; ?> class="accent-primary">
                  <span class="material-symbols-outlined text-[16px] text-primary">calendar_month</span>
                  <span>Mi calendario</span>
                </label>
                <?php if ($mode !== 'personal'): ?>
                  <label class="flex items-center gap-1.5 px-3 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                    <input type="radio" name="calendar_scope" value="team" checked class="accent-primary">
                    <span class="material-symbols-outlined text-[16px] text-secondary">groups</span>
                    <span>Equipo</span>
                  </label>
                <?php endif; ?>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-secondary uppercase mr-1">Capas:</span>
                <label class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="checkbox" name="item_type" value="evento" checked class="accent-primary rounded">
                  <span class="material-symbols-outlined text-[15px] text-secondary">event</span>
                  <span>Eventos</span>
                </label>
                <label class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="checkbox" name="item_type" value="tarea" checked class="accent-primary rounded">
                  <span class="material-symbols-outlined text-[15px] text-tertiary">task_alt</span>
                  <span>Tareas</span>
                </label>
                <label class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="checkbox" name="item_type" value="recordatorio" checked class="accent-primary rounded">
                  <span class="material-symbols-outlined text-[15px] text-secondary">notifications_active</span>
                  <span>Recordatorios</span>
                </label>
                <label class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="checkbox" name="item_status" value="pending" checked class="accent-error rounded">
                  <span class="material-symbols-outlined text-[15px] text-error">pending_actions</span>
                  <span>Pendientes</span>
                </label>
                <label class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                  <input type="checkbox" name="item_status" value="completed" checked class="accent-primary rounded">
                  <span class="material-symbols-outlined text-[15px] text-primary">check_circle</span>
                  <span>Realizados</span>
                </label>
              </div>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($view === 'pending'): ?>
          <!-- Filtro de tipo de vencimiento -->
          <section class="bg-surface-container-low p-space-md rounded-2xl border border-surface-container space-y-space-xs">
            <div class="pb-1">
              <span class="font-label-sm uppercase font-semibold text-secondary">Filtro de control</span>
              <h4 class="font-title-md font-semibold text-on-surface">Tipo de vencimiento comercial</h4>
              <p class="font-body-sm text-secondary">Filtra el calendario por el control o gestión que necesitas revisar.</p>
            </div>
            <form class="flex flex-wrap items-center gap-2 pt-2" data-scm-calendar-due-type-filter autocomplete="off">
              <label class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                <input type="checkbox" name="due_type" value="preventiva_sin_enviar" checked class="accent-primary rounded">
                <span class="material-symbols-outlined text-[16px] text-secondary">task_alt</span>
                <span>Preventivas sin enviar</span>
              </label>
              <label class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                <input type="checkbox" name="due_type" value="ticket_preventiva_sin_cita" checked class="accent-primary rounded">
                <span class="material-symbols-outlined text-[16px] text-warning">event_busy</span>
                <span>Tickets sin cita</span>
              </label>
              <label class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                <input type="checkbox" name="due_type" value="preventiva_cita_sin_realizar" checked class="accent-primary rounded">
                <span class="material-symbols-outlined text-[16px] text-error">pending_actions</span>
                <span>Citas sin realizar</span>
              </label>
              <label class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                <input type="checkbox" name="due_type" value="cotizacion_sin_enviar" checked class="accent-primary rounded">
                <span class="material-symbols-outlined text-[16px] text-tertiary">receipt_long</span>
                <span>Cotizaciones sin enviar</span>
              </label>
              <label class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-container-lowest border border-surface-container rounded-xl text-body-sm font-medium cursor-pointer hover:bg-surface-container transition-colors">
                <input type="checkbox" name="due_type" value="cotizacion_enviada_sin_respuesta" checked class="accent-primary rounded">
                <span class="material-symbols-outlined text-[16px] text-secondary">mark_email_unread</span>
                <span>Cotizaciones sin respuesta</span>
              </label>
            </form>
          </section>
        <?php endif; ?>

        <!-- Cuadrícula y Agenda Lateral -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-md">
          <!-- Calendario Board (2 columnas) -->
          <section class="lg:col-span-2 bg-surface-container-lowest rounded-2xl p-space-md border border-surface-container shadow-sm space-y-space-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm pb-space-xs border-b border-surface-container">
              <div>
                <span class="font-label-sm text-secondary uppercase font-semibold" data-scm-calendar-board-kicker>Vista de calendario</span>
                <h4 class="font-headline-sm text-headline-sm text-on-surface" data-scm-calendar-title>Calendario</h4>
                <p class="font-body-sm text-secondary"><?php echo $view === 'pending' ? 'Haz clic en un día para revisar los casos con vencimiento agrupado.' : 'Haz clic en un día para ver sus eventos o crear uno nuevo.'; ?></p>
              </div>
              <div class="flex items-center gap-2">
                <div class="flex items-center bg-surface-container-low rounded-xl p-1 gap-1 border border-surface-container">
                  <button type="button" class="w-8 h-8 rounded-lg hover:bg-surface-container-high text-on-surface flex items-center justify-center cursor-pointer font-bold" data-scm-calendar-prev aria-label="Anterior">&lsaquo;</button>
                  <button type="button" class="px-3 py-1 hover:bg-surface-container-high text-on-surface rounded-lg font-label-sm font-semibold cursor-pointer" data-scm-calendar-today-btn>Hoy</button>
                  <button type="button" class="w-8 h-8 rounded-lg hover:bg-surface-container-high text-on-surface flex items-center justify-center cursor-pointer font-bold" data-scm-calendar-next aria-label="Siguiente">&rsaquo;</button>
                </div>
                <div class="flex items-center bg-surface-container-low rounded-xl p-1 border border-surface-container" aria-label="Modo de vista">
                  <button type="button" class="px-3 py-1 rounded-lg font-label-sm font-semibold transition-colors cursor-pointer bg-primary-container text-on-surface" data-scm-calendar-view-mode="month" aria-pressed="true">Mes</button>
                  <button type="button" class="px-3 py-1 rounded-lg font-label-sm font-semibold transition-colors cursor-pointer text-secondary hover:text-on-surface" data-scm-calendar-view-mode="week" aria-pressed="false">Semana</button>
                  <button type="button" class="px-3 py-1 rounded-lg font-label-sm font-semibold transition-colors cursor-pointer text-secondary hover:text-on-surface" data-scm-calendar-view-mode="day" aria-pressed="false">Día</button>
                </div>
              </div>
            </div>
            <div class="grid grid-cols-7 text-center font-label-sm text-secondary font-semibold py-2.5 bg-surface-container-low/60 rounded-xl border border-surface-container" aria-hidden="true" data-scm-calendar-weekdays-header>
              <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
            </div>
            <div class="scm-calendar-month-grid min-h-[400px] rounded-xl" data-scm-calendar-grid aria-live="polite">
              <div class="py-20 text-center text-secondary font-body-sm flex flex-col items-center justify-center gap-2">
                <span class="w-4 h-4 rounded-full bg-primary animate-ping"></span>
                <span>Cargando calendario…</span>
              </div>
            </div>
          </section>

          <!-- Aside Lateral (Agenda del día + Próximos) -->
          <aside class="space-y-space-md">
            <!-- Agenda del día -->
            <section class="bg-surface-container-lowest rounded-2xl p-space-md border border-surface-container shadow-sm flex flex-col justify-between min-h-[380px]">
              <div class="space-y-space-sm">
                <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
                  <div>
                    <span class="font-label-sm text-secondary uppercase font-semibold">Agenda del día</span>
                    <h4 class="font-title-md font-semibold text-on-surface" data-scm-calendar-day-title>Selecciona un día</h4>
                    <p class="font-body-sm text-secondary text-xs" data-scm-calendar-day-subtitle><?php echo $view === 'pending' ? 'Vencimientos agrupados por tipo.' : 'Eventos y citas según funcionario y estado.'; ?></p>
                  </div>
                  <button type="button" class="p-2 rounded-xl hover:bg-surface-container text-secondary hover:text-on-surface transition-colors cursor-pointer" data-scm-calendar-refresh title="Actualizar agenda">
                    <span class="material-symbols-outlined text-[20px]">sync</span>
                  </button>
                </div>
                <div class="py-space-xs space-y-2 overflow-y-auto max-h-[340px]" data-scm-calendar-events>
                  <div class="py-12 text-center text-secondary font-body-sm">Selecciona un día del calendario para revisar la agenda.</div>
                </div>
              </div>
              <?php if ($view !== 'pending'): ?>
                <button type="button" class="w-full mt-space-sm py-2.5 bg-primary-container hover:bg-primary-fixed-dim text-on-surface font-label-md font-semibold rounded-xl shadow-sm transition-all cursor-pointer flex items-center justify-center gap-1.5" data-scm-calendar-open-create data-calendar-mode="single">
                  <span class="material-symbols-outlined text-[18px]">add_circle</span>
                  <span>Crear evento para este día</span>
                </button>
              <?php endif; ?>
            </section>

            <!-- Próximos en el mes -->
            <section class="bg-surface-container-lowest rounded-2xl p-space-md border border-surface-container shadow-sm space-y-space-sm">
              <div class="flex items-center justify-between pb-space-xs border-b border-surface-container">
                <div class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-primary">event_upcoming</span>
                  <span class="font-title-sm font-semibold text-on-surface">Próximos en el mes</span>
                </div>
                <button type="button" class="text-xs text-primary font-semibold hover:underline cursor-pointer" data-scm-calendar-upcoming-all>Ver todos</button>
              </div>
              <div class="space-y-2 overflow-y-auto max-h-[260px]" data-scm-calendar-upcoming>
                <div class="py-6 text-center text-secondary font-body-sm text-xs">Sin próximos eventos.</div>
              </div>
            </section>
          </aside>
        </div>
      </div>
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
    <div class="fixed inset-0 z-50 items-center justify-center p-4 bg-inverse-surface/60 backdrop-blur-sm commercial-modal" id="commercial-permissions-modal" role="dialog" aria-modal="true" aria-labelledby="commercial-permissions-title" aria-hidden="true">
      <div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-4xl max-h-[88vh] overflow-hidden flex flex-col relative" role="document">
        <div class="p-space-lg bg-surface-container-low flex items-center justify-between border-b border-surface-container">
          <div>
            <span class="font-label-sm text-secondary uppercase font-semibold">Configuración de Accesos</span>
            <h2 class="font-headline-sm text-headline-sm text-on-surface" id="commercial-permissions-title">Visibilidad y Acciones por Cargo</h2>
          </div>
          <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container-high text-secondary flex items-center justify-center cursor-pointer" data-commercial-close-permissions aria-label="Cerrar">
            <span class="material-symbols-outlined text-[20px]">close</span>
          </button>
        </div>

        <form id="commercial-permissions-form" class="overflow-y-auto p-space-lg space-y-space-md flex-1">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm bg-surface-container-low p-space-md rounded-xl">
            <div>
              <strong class="block font-label-md text-on-surface font-semibold">Acceso total administrativo</strong>
              <span class="font-body-sm text-secondary">Cargos que pueden ver todo el panel, administrar permisos y operar todas las tareas.</span>
            </div>
            <div>
              <strong class="block font-label-md text-on-surface font-semibold">Funcionarios operativos</strong>
              <span class="font-body-sm text-secondary">Define qué cargos aparecen en filtros, reasignación y búsquedas de responsables.</span>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
            <?php foreach ($cargos as $cargo): ?>
              <?php $current = $permissions[$cargo['id']] ?? ['views' => array_keys(CommercialAccessPolicy::VIEWS), 'actions' => array_keys(CommercialAccessPolicy::ACTIONS)]; ?>
              <div class="bg-surface-container-low rounded-xl p-space-md border border-surface-container space-y-space-xs" data-cargo="<?php echo esc_attr($cargo['id']); ?>">
                <div class="flex items-center justify-between pb-1 border-b border-surface-container">
                  <span class="font-label-md font-semibold text-on-surface"><?php echo esc_html($cargo['name']); ?></span>
                  <span class="font-label-sm text-secondary">ID <?php echo esc_html($cargo['id']); ?> (<?php echo esc_html((string) $cargo['total']); ?> activos)</span>
                </div>
                <label class="flex items-center gap-2 py-1 cursor-pointer">
                  <input type="checkbox" name="admin_cargos[]" value="<?php echo esc_attr($cargo['id']); ?>"<?php checked(isset($adminCargoIds[$cargo['id']])); ?> class="rounded text-primary focus:ring-primary">
                  <span class="font-label-sm font-semibold text-primary">Acceso Total Administrativo</span>
                </label>
                <input type="hidden" name="permissions[<?php echo esc_attr($cargo['id']); ?>][configured]" value="1">
                <div class="pt-1">
                  <span class="block font-label-sm text-secondary font-semibold">Vistas Permitidas:</span>
                  <div class="grid grid-cols-2 gap-1 pt-1">
                    <?php foreach (CommercialAccessPolicy::VIEWS as $k => $label): ?>
                      <label class="flex items-center gap-1.5 text-[11px] text-on-surface cursor-pointer">
                        <input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][views][]" value="<?php echo esc_attr($k); ?>"<?php checked(in_array($k, $current['views'], true)); ?> class="rounded text-primary">
                        <span><?php echo esc_html($label); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="pt-1">
                  <span class="block font-label-sm text-secondary font-semibold">Acciones Operativas:</span>
                  <div class="grid grid-cols-2 gap-1 pt-1">
                    <?php foreach (CommercialAccessPolicy::ACTIONS as $k => $label): ?>
                      <label class="flex items-center gap-1.5 text-[11px] text-on-surface cursor-pointer">
                        <input type="checkbox" name="permissions[<?php echo esc_attr($cargo['id']); ?>][actions][]" value="<?php echo esc_attr($k); ?>"<?php checked(in_array($k, $current['actions'], true)); ?> class="rounded text-primary">
                        <span><?php echo esc_html($label); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="bg-surface-container-low rounded-xl p-space-md border border-surface-container space-y-space-xs">
            <span class="font-label-md font-semibold text-on-surface block">Cargos Visibles en Filtros y Reasignación</span>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1">
              <?php foreach ($cargos as $cargo): ?>
                <label class="flex items-center gap-2 p-2 bg-surface-container-lowest rounded-lg text-body-sm cursor-pointer border border-transparent hover:border-surface-container">
                  <input type="checkbox" name="employee_cargo_ids[]" value="<?php echo esc_attr($cargo['id']); ?>"<?php checked(isset($employeeCargoSet[$cargo['id']])); ?> class="rounded text-primary">
                  <span><?php echo esc_html($cargo['name']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="p-space-md bg-surface-container-low flex items-center justify-between rounded-xl">
            <span data-commercial-permissions-message aria-live="polite" class="font-body-sm font-semibold"></span>
            <div class="flex items-center gap-space-sm">
              <button type="button" class="px-space-md py-2 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface font-label-md font-semibold transition-colors cursor-pointer" data-commercial-close-permissions>Cancelar</button>
              <button type="submit" class="px-space-lg py-2 rounded-xl bg-inverse-surface hover:bg-secondary text-on-secondary font-label-md font-semibold transition-all shadow-md cursor-pointer">Guardar Configuración</button>
            </div>
          </div>
        </form>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private static function currentDateFormatted(): string
  {
    $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $dayName = $days[(int) date('w')];
    $dayNum = date('j');
    $monthName = $months[(int) date('n')];
    $year = date('Y');
    $shift = (int) date('G') < 13 ? 'Turno AM' : 'Turno PM';
    return "{$dayName}, {$dayNum} {$monthName} {$year} • {$shift}";
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

  private static function percent(int $part, int $total): int
  {
    if ($total <= 0) {
      return 0;
    }
    return max(0, min(100, (int) round(($part / $total) * 100)));
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

  /** @param array<string,mixed> $filters @return array<string,string> */
  private static function globalFilterParams(array $filters): array
  {
    $employee = trim((string) ($filters['id_empleado'] ?? ''));
    return $employee !== '' ? ['id_empleado' => $employee] : [];
  }

  /** @param array<string,mixed> $params */
  private static function url(string $baseUrl, array $params): string
  {
    $params = array_filter($params, static fn($value): bool => $value !== '' && $value !== null);
    return $baseUrl . '/index.php' . ($params ? '?' . http_build_query($params) : '');
  }
}
