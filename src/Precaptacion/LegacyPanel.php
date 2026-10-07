<?php
/**
 * Adapted from the supplied Precaptaciones WordPress plugin.
 * Description: filtros, listados y resultado de precaptaciones de SuCasa Inmobiliaria.
 * Version: 1.0.0
 * Author: SuCasa Inmobiliaria
 */

namespace SCM\Precaptacion;

final class LegacyPanel
{
    private const VERSION = '1.0.0';

    /** @var array<string, array<int, string>> */
    private static $schema_cache = [];

    /** @var bool */
    private static $assets_printed = false;

    /**
     * Uso:
     * [precaptaciones modo="mis"]
     * [precaptaciones modo="control"]
     */
    public static function render_shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'modo' => 'mis',
                'por_pagina' => 10,
                'url_formulario' => '',
                'clase' => '',
            ],
            $atts,
            'precaptaciones'
        );

        return self::render_panel($atts);
    }

    public static function render_mis_shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'modo' => 'mis',
                'por_pagina' => 50,
                'url_formulario' => '/mi-cuenta/precaptacion/',
                'clase' => '',
            ],
            $atts,
            'mis_precaptaciones'
        );

        return self::render_panel($atts);
    }

    public static function render_control_shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'modo' => 'control',
                'por_pagina' => 50,
                'url_formulario' => '/mi-cuenta/precaptacion/',
                'clase' => '',
            ],
            $atts,
            'control_precaptaciones'
        );

        return self::render_panel($atts);
    }

    private static function render_panel(array $atts): string
    {
        if (!is_user_logged_in()) {
            return '<div class="precaptaciones-alerta">Debes iniciar sesion para ver las precaptaciones.</div>';
        }

        global $wpdb;

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            return '<div class="precaptaciones-alerta">No se encontro la tabla de precaptaciones.</div>';
        }

        $mode = sanitize_key((string) $atts['modo']);
        $is_control = in_array($mode, ['control', 'admin', 'administrador'], true);
        if ($is_control && !self::current_user_can_bulk_update()) {
            return '<div class="precaptaciones-alerta">No tienes permisos para ver el panel de control de precaptaciones.</div>';
        }

        $per_page = max(1, min(200, absint($atts['por_pagina'])));
        $filters = self::read_filters([]);

        ob_start();
        self::print_assets();
        ?>
        <link rel="stylesheet" href="<?php echo esc_url(SCM_BASE_URL . '/assets/css/precaptacion.css?v=' . filemtime(dirname(__DIR__, 2) . '/public/assets/css/precaptacion.css')); ?>">
        <section
            class="precaptaciones w-full text-slate-950 <?php echo esc_attr((string) $atts['clase']); ?>"
            data-precaptaciones-panel
            data-mode="<?php echo esc_attr($is_control ? 'control' : 'mis'); ?>"
            data-per-page="<?php echo esc_attr((string) $per_page); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_filtrar')); ?>"
        >
            <header class="precap-heading">
                <div class="precap-heading-copy">
                    <nav class="precap-breadcrumb" aria-label="Ruta de navegación">Gestión Inmobiliaria <span>›</span> Captaciones &amp; Oportunidades <span>›</span> <strong><?php echo $is_control ? 'Precaptaciones Admin' : 'Mis Precaptaciones'; ?></strong></nav>
                    <h1><?php echo $is_control ? 'Precaptaciones Admin' : 'Mis Precaptaciones'; ?></h1>
                    <p>Registra oportunidades de prospección en campo, valida avisos exteriores, depura duplicados y gestiona resultados para asignación de tareas comerciales.</p>
                </div>
                <div class="precap-heading-actions">
                    <button type="button" class="precap-export" data-precap-export>↓ Exportar CSV</button>
                    <?php if ($is_control) : ?>
                        <?php self::render_bulk_promocionado_por_button(self::count_promocionado_por_por_normalizar($table)); ?>
                        <?php self::render_bulk_competencia_button(self::count_competencia_por_normalizar($table)); ?>
                    <?php endif; ?>
                    <?php if (Module::policy()->canAct('precaptacion_crear')) : ?>
                        <button type="button" class="precap-register" data-precap-open="precap-create"><span aria-hidden="true">＋</span> Registrar precaptación</button>
                    <?php endif; ?>
                </div>
            </header>
            <div class="precap-metrics" data-precap-metrics><?php self::render_metrics($table, $filters, $is_control); ?></div>
            <?php self::render_filters($table, $filters, $is_control); ?>

            <div class="precaptaciones__estado" data-precaptaciones-status aria-live="polite"></div>
            <div data-precaptaciones-results>
                <?php self::render_results($table, $filters, $is_control, $per_page, 1); ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_results(string $table, array $filters, bool $is_control, int $per_page, int $page): void
    {
        $data = self::query_precaptaciones($table, $filters, $is_control, $per_page, $page);
        $rows = $data['rows'];
        $total = $data['total'];
        $page = $data['page'];
        ?>
        <div class="precap-results-card">
        <div class="precap-results-toolbar">
            <div class="precaptaciones__contador"><strong><?php echo esc_html(number_format_i18n($total)); ?> registros</strong><span> | Vista operativa de prospección</span></div>
            <label class="precap-page-size">Filas por página: <select data-precap-page-size aria-label="Filas por página"><?php foreach (array_unique([10, 25, 50, 100, $per_page]) as $size) : ?><option value="<?php echo (int) $size; ?>" <?php echo $size === $per_page ? 'selected' : ''; ?>><?php echo (int) $size; ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="precaptaciones__tabla-wrap">
            <table class="precaptaciones__tabla">
                <thead>
                    <?php self::render_table_head($is_control); ?>
                </thead>
                <tbody>
                    <?php if (!$rows) : ?>
                        <tr>
                            <td colspan="<?php echo esc_attr((string) self::table_colspan($is_control)); ?>" class="precaptaciones__vacio">
                                No hay precaptaciones para los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php self::render_row($table, $row, $is_control); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($rows) : ?>
            <div class="precaptaciones__modales">
                <?php foreach ($rows as $row) : ?>
                    <?php self::render_detail_modal($table, $row, $is_control); ?>
                    <?php if (Module::policy()->canAct('precaptacion_editar') && !self::row_actions_locked($table, $row)) : ?>
                        <?php self::render_edit_modal($table, $row); ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php self::render_pagination($total, $per_page, $page); ?>
        </div>
        <?php
    }

    private static function query_precaptaciones(string $table, array $filters, bool $is_control, int $per_page, int $page): array
    {
        global $wpdb;

        $page = max(1, $page);
        $offset = ($page - 1) * $per_page;
        [$where_sql, $where_values] = self::build_where($table, $filters, $is_control);

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = (int) $wpdb->get_var($where_values ? $wpdb->prepare($total_sql, $where_values) : $total_sql);
        $page = min($page, max(1, (int) ceil($total / $per_page)));
        $offset = ($page - 1) * $per_page;

        $order_col = self::first_existing_column($table, ['fecha', 'cct_created', '_created', '_ID']);
        $order_sql = $order_col ? "ORDER BY {$order_col} DESC" : '';

        $rows_sql = "SELECT * FROM {$table} {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
        $rows_values = array_merge($where_values, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $rows_values), ARRAY_A);

        return [
            'rows' => $rows ?: [],
            'total' => $total,
            'page' => $page,
        ];
    }

    private static function render_metrics(string $table, array $filters, bool $is_control): void
    {
        global $wpdb;
        [$where, $values] = self::build_where($table, $filters, $is_control);
        $reason_column = self::column_for($table, 'razones');
        $contact_column = self::column_for($table, 'contactado');
        $reason = $reason_column ? "LOWER(COALESCE(`{$reason_column}`, ''))" : "''";
        $contact = $contact_column ? "LOWER(TRIM(COALESCE(`{$contact_column}`, '')))" : "''";
        $ticket_conditions = ["{$reason} = 'ticket creado'"];
        foreach (['id_ticket_asignado', 'ticket_asignado', 'id_ticket', 'ticket', 'numero_de_ticket', 'url_ticket', 'url_ticket_precap'] as $candidate) {
            $column = self::first_existing_column($table, [$candidate]);
            if ($column) $ticket_conditions[] = "TRIM(COALESCE(`{$column}`, '')) <> ''";
        }
        $ticket_column = self::column_for($table, 'tiene_ticket');
        if ($ticket_column) $ticket_conditions[] = "LOWER(TRIM(COALESCE(`{$ticket_column}`, ''))) IN ('si', 'sí', '1', 'true')";
        $ticket = '(' . implode(' OR ', $ticket_conditions) . ')';
        $sql = "SELECT COUNT(*) AS total, SUM(CASE WHEN {$ticket} THEN 1 ELSE 0 END) AS tickets,
            SUM(CASE WHEN {$reason} LIKE %s THEN 1 ELSE 0 END) AS duplicates,
            SUM(CASE WHEN NOT {$ticket} AND ({$contact} IN ('', 'no', '0') OR {$reason} LIKE %s) THEN 1 ELSE 0 END) AS pending
            FROM {$table} {$where}";
        $data = $wpdb->get_row($wpdb->prepare($sql, array_merge(['%duplicad%', '%sin informaci%'], $values)), ARRAY_A) ?: [];
        $total = (int) ($data['total'] ?? 0);
        $pending = (int) ($data['pending'] ?? 0);
        $duplicates = (int) ($data['duplicates'] ?? 0);
        $tickets = (int) ($data['tickets'] ?? 0);
        $cards = [
            ['Total precaptaciones', number_format_i18n($total), 'registros', 'Listado actual', 'total'],
            ['Sin información / Pendientes', number_format_i18n($pending), 'por depurar', ($total ? round($pending * 100 / $total, 1) : 0) . '% del listado', 'pending'],
            ['Duplicadas identificadas', number_format_i18n($duplicates), 'registros marcados', $duplicates ? 'Revisar duplicadas' : 'Sin duplicadas', 'duplicates'],
            ['Conversión a tarea', number_format($total ? $tickets * 100 / $total : 0, 1) . '%', 'efectividad', number_format_i18n($tickets) . ' con tarea', 'conversion'],
        ];
        foreach ($cards as [$label, $value, $detail, $badge, $kind]) {
            echo '<article class="precap-metric precap-metric--' . esc_attr($kind) . '"><p>' . esc_html($label) . '</p><div><strong>' . esc_html($value) . '</strong><span>' . esc_html($detail) . '</span></div><small>' . esc_html($badge) . '</small></article>';
        }
    }

    private static function render_filters(string $table, array $filters, bool $is_control): void
    {
        $active_filters = self::active_filter_count($filters);
        ?>
        <form class="precaptaciones__filtros rounded-lg border border-slate-200 bg-white shadow-sm" method="post" data-precaptaciones-filters>
            <div class="precaptaciones__filtros-titulo flex flex-col gap-1 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="precaptaciones__filtros-eyebrow">Filtros</p>
                    <h2 class="precaptaciones__filtros-heading">Refina el listado de precaptaciones</h2>
                    <span class="precaptaciones__filtros-activos" data-precaptaciones-active-filters><?php echo esc_html(number_format_i18n($active_filters)); ?> activos</span>
                </div>
                <button type="button" class="precap-collapse" data-precap-collapse aria-expanded="true" aria-controls="precap-filter-fields">⌄ Colapsar panel</button>
            </div>

            <div id="precap-filter-fields" class="precaptaciones__filtros-grid grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                <label class="precaptaciones__campo precaptaciones__campo--wide min-w-0">
                    <span class="mb-2 block text-sm font-bold text-slate-800">Buscar</span>
                    <input class="precaptaciones__control" type="search" name="precaptaciones_buscar" value="<?php echo esc_attr($filters['buscar']); ?>" placeholder="Contacto, direccion, barrio, referencia o tarea">
                </label>

                <fieldset class="precaptaciones__grupo min-w-0">
                    <legend class="mb-2 text-sm font-bold text-slate-800">Fecha (Desde / Hasta)</legend>
                    <div class="precaptaciones__fecha grid grid-cols-2 gap-2">
                        <input class="precaptaciones__control" type="date" name="precaptaciones_fecha_desde" value="<?php echo esc_attr($filters['fecha_desde']); ?>" aria-label="Fecha desde">
                        <input class="precaptaciones__control" type="date" name="precaptaciones_fecha_hasta" value="<?php echo esc_attr($filters['fecha_hasta']); ?>" aria-label="Fecha hasta">
                    </div>
                </fieldset>

                <label class="precaptaciones__campo min-w-0">
                    <span class="mb-2 block text-sm font-bold text-slate-800">Celular</span>
                    <input class="precaptaciones__control" type="search" name="precaptaciones_celular" value="<?php echo esc_attr($filters['celular']); ?>" placeholder="Escribe un celular">
                </label>

                <label class="precaptaciones__campo min-w-0">
                    <span class="mb-2 block text-sm font-bold text-slate-800">Tarea</span>
                    <input class="precaptaciones__control" type="search" name="precaptaciones_ticket" value="<?php echo esc_attr($filters['ticket']); ?>" placeholder="Escribe un numero">
                </label>

                <?php if ($is_control) : ?>
                    <label class="precaptaciones__campo min-w-0">
                        <span class="mb-2 block text-sm font-bold text-slate-800">Funcionario</span>
                        <?php self::render_user_select($table, 'precaptaciones_funcionario', $filters['funcionario']); ?>
                    </label>
                <?php endif; ?>

                <?php self::render_distinct_select($table, 'contactado', 'Contactado?', 'precaptaciones_contactado', $filters['contactado'], 'Elige una opcion'); ?>
                <?php self::render_distinct_select($table, 'merece_ticket', '¿Merece tarea?', 'precaptaciones_merece_ticket', $filters['merece_ticket'], 'Elige una opcion'); ?>
                <?php self::render_distinct_select($table, 'tiene_ticket', '¿Tiene tarea?', 'precaptaciones_tiene_ticket', $filters['tiene_ticket'], 'Selecciona una opcion'); ?>

                <?php self::render_distinct_select($table, 'ruta', 'Ruta', 'precaptaciones_ruta', $filters['ruta'], 'Elige una opcion'); ?>
                <?php self::render_distinct_select($table, 'tipo_inmueble', 'Tipo de inmueble', 'precaptaciones_tipo_inmueble', $filters['tipo_inmueble'], 'Selecciona un tipo'); ?>
                <?php self::render_distinct_select($table, 'categoria', 'Categoria', 'precaptaciones_categoria', $filters['categoria'], 'Selecciona una categoria'); ?>
                <?php self::render_distinct_select($table, 'barrio', 'Barrio', 'precaptaciones_barrio', $filters['barrio'], 'Seleccione'); ?>

                <?php if ($is_control) : ?>
                    <?php self::render_distinct_select($table, 'promocionado_por', 'Promocionado por', 'precaptaciones_promocionado_por', $filters['promocionado_por'], 'Elige una opcion'); ?>
                    <?php self::render_distinct_select($table, 'competencia', 'Competencia', 'precaptaciones_competencia', $filters['competencia'], 'Elige una opcion'); ?>
                    <?php self::render_distinct_select($table, 'origen', 'Origen', 'precaptaciones_origen', $filters['origen'], 'Selecciona uno'); ?>
                    <?php self::render_distinct_select($table, 'razones', 'Razon', 'precaptaciones_razones', $filters['razones'], 'Elige una opcion'); ?>
                    <?php self::render_distinct_select($table, 'seguimiento', 'Tuvo seguimiento?', 'precaptaciones_seguimiento', $filters['seguimiento'], 'Elige una opcion'); ?>
                <?php endif; ?>
            </div>

            <div class="precaptaciones__acciones-filtro flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="precaptaciones__boton-filtrar inline-flex min-h-11 items-center justify-center rounded-lg bg-[#061d49] px-5 py-2 text-sm font-black text-white shadow-sm transition hover:bg-[#0a2b6f] focus:outline-none focus:ring-4 focus:ring-[#061d49]/20" type="submit">
                    Filtrar Resultados
                </button>
                <button class="precaptaciones__boton-limpiar inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2 text-sm font-bold text-slate-700 no-underline transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-slate-300/50" type="button" data-precaptaciones-clear-filters>
                    Limpiar Filtros
                </button>
                <span class="precap-filter-hint">Criterios activos aplicables instantáneamente</span>
            </div>
        </form>
        <?php
    }

    private static function render_table_head(bool $is_control): void
    {
        ?>
        <tr>
            <th>Acciones</th>
            <th>Evidencia</th>
            <?php if ($is_control) : ?>
                <th>Funcionario</th>
            <?php endif; ?>
            <th>Barrio</th>
            <th>Tipo de inmueble</th>
            <th>Categoria</th>
            <th>Resultado</th>
            <th>Razón / Detalle</th>
            <th>Tarea</th>
        </tr>
        <?php
    }

    private static function render_row(string $table, array $row, bool $is_control): void
    {
        $id = self::row_value($table, $row, ['_ID', 'id_precaptacion']);
        $employee_id = self::row_value($table, $row, ['id_empleado', 'cct_author_id', 'captador_precat']);
        if (!$employee_id) {
            $employee_id = get_current_user_id();
        }
        $actions_locked = !Module::policy()->canAct('precaptacion_editar') || self::row_actions_locked($table, $row);
        $has_ticket = self::row_has_ticket($table, $row);
        $reason_value = self::row_value($table, $row, ['razones', 'razones_precap', 'razon']);
        $merece_ticket_value = self::row_value($table, $row, ['merece_ticket', 'merece_ticket_precat', 'merece_ticket_precap', 'efectivo']);
        ?>
        <tr data-precaptaciones-row-id="<?php echo esc_attr((string) $id); ?>" data-precaptaciones-actions-locked="<?php echo $actions_locked ? '1' : '0'; ?>" data-precaptaciones-has-ticket="<?php echo $has_ticket ? '1' : '0'; ?>" data-precaptaciones-reason="<?php echo esc_attr($reason_value); ?>" data-precaptaciones-merece-ticket="<?php echo esc_attr($merece_ticket_value); ?>">
            <td class="precaptaciones__acciones">
                <button class="precaptaciones__detalle" type="button" data-precaptaciones-modal-open="precaptaciones-detalle-modal-<?php echo esc_attr((string) $id); ?>" title="Ver detalles de precaptacion" aria-label="Ver detalles de precaptacion">
                    Detalles
                </button>
                <?php if (!$actions_locked) : ?>
                    <button class="precaptaciones__editar inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#ffc23d] px-3 py-2 text-sm font-black text-[#061d49] no-underline shadow-sm transition hover:bg-[#ffd36d] focus:outline-none focus:ring-4 focus:ring-[#ffc23d]/40" type="button" data-precaptaciones-modal-open="precaptaciones-precap-modal-<?php echo esc_attr((string) $id); ?>" title="Editar resultado de precaptacion" aria-label="Editar resultado de precaptacion">
                        <svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none">
                            <path d="M12 20h9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                        Editar
                    </button>
                <?php endif; ?>
                <?php if ($is_control && !$actions_locked) : ?>
                    <button
                        class="precaptaciones__duplicada"
                        type="button"
                        data-precaptaciones-duplicada
                        data-id="<?php echo esc_attr((string) $id); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_marcar_duplicada')); ?>"
                        title="Marcar como precaptacion duplicada"
                        aria-label="Marcar como precaptacion duplicada"
                    >
                        Duplicada
                    </button>
                    <button
                        class="precaptaciones__sin-informacion"
                        type="button"
                        data-precaptaciones-sin-informacion
                        data-id="<?php echo esc_attr((string) $id); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_marcar_sin_informacion')); ?>"
                        title="Marcar como precaptacion sin informacion"
                        aria-label="Marcar como precaptacion sin informacion"
                    >
                        <span>Sin</span>
                        <span>informacion</span>
                    </button>
                <?php endif; ?>
            </td>
            <td><?php echo self::render_evidence($table, $row); ?></td>
            <?php if ($is_control) : ?>
                <td><div class="precap-employee"><span class="precap-avatar" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr(self::employee_name($employee_id), 0, 1))); ?></span><strong><?php echo esc_html(self::employee_name($employee_id)); ?></strong></div></td>
            <?php endif; ?>
            <td><?php echo esc_html(self::row_value($table, $row, ['barrio', 'sector'])); ?></td>
            <td><span class="precap-property-badge"><?php echo esc_html(self::row_value($table, $row, ['tipo_inmueble', 'tipo_de_inmueble'])); ?></span></td>
            <td><span class="precap-category-badge"><?php echo esc_html(self::row_value($table, $row, ['categoria', 'categoria_inmueble'])); ?></span></td>
            <td><?php echo esc_html(self::display_text(self::row_value($table, $row, ['resultado']))); ?></td>
            <td><?php echo esc_html(self::display_text($reason_value)); ?></td>
            <td><span class="precap-ticket-badge"><?php echo self::render_ticket($table, $row) ?: 'Sin tarea'; ?></span></td>
        </tr>
        <?php
    }

    private static function render_detail_modal(string $table, array $row, bool $is_control): void
    {
        $id = self::row_value($table, $row, ['_ID', 'id_precaptacion']);
        $employee_id = self::row_value($table, $row, ['id_empleado', 'cct_author_id', 'captador_precat']);
        if (!$employee_id) {
            $employee_id = get_current_user_id();
        }

        $modal_id = 'precaptaciones-detalle-modal-' . $id;
        $fields = [
            'Funcionario' => $is_control ? self::employee_name($employee_id) : '',
            'Ruta' => self::row_value($table, $row, ['ruta', 'numero_ruta']),
            'Barrio' => self::row_value($table, $row, ['barrio', 'sector']),
            'Direccion' => self::row_value($table, $row, ['direccion', 'direccion_inmueble']),
            'Referencia' => self::row_value($table, $row, ['referencia', 'referencia_inmueble']),
            'Tipo de inmueble' => self::row_value($table, $row, ['tipo_inmueble', 'tipo_de_inmueble']),
            'Categoria' => self::row_value($table, $row, ['categoria', 'categoria_inmueble']),
            'Contacto' => self::row_value($table, $row, ['contacto', 'nombre_contacto', 'nombre_del_contacto']),
            'Correo' => self::row_value($table, $row, ['correo', 'email']),
            'Celular' => self::row_value($table, $row, ['celular', 'celular_precat', 'telefono']),
            'Observaciones' => self::row_value($table, $row, ['observaciones', 'resultado_observaciones_precat']),
            'Resultado' => self::row_value($table, $row, ['resultado']),
            'Razon' => self::row_value($table, $row, ['razones', 'razones_precap', 'razon']),
            'Contactado' => self::row_value($table, $row, ['contactado']),
            'Merece tarea' => self::row_value($table, $row, ['merece_ticket']),
            'Tiene tarea' => self::row_value($table, $row, ['tiene_ticket']),
            'Tarea' => self::row_value($table, $row, ['ticket', 'numero_de_ticket']),
            'Origen' => self::row_value($table, $row, ['origen']),
            'Promocionado por' => self::serialized_choice_label(self::row_value($table, $row, ['promocionado_por', 'promocionado'])),
            'Competencia' => self::serialized_choice_label(self::row_value($table, $row, ['competencia', 'competencias', 'competencia_precat'])),
            'Seguimiento' => self::row_value($table, $row, ['seguimiento', 'tuvo_seguimiento']),
            'PPH' => self::pph_name(self::row_value($table, $row, ['id_pph'])),
            'Bandera' => self::row_value($table, $row, ['bandera']),
        ];
        ?>
        <div class="precaptaciones-precap-modal" id="<?php echo esc_attr($modal_id); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_id); ?>-title" hidden>
            <div class="precaptaciones-precap-modal__backdrop" data-precaptaciones-modal-close></div>
            <div class="precaptaciones-precap-modal__panel" role="document">
                <div class="precaptaciones-precap-modal__header">
                    <div>
                        <p class="precaptaciones-precap-modal__eyebrow">Precaptacion #<?php echo esc_html((string) $id); ?></p>
                        <h2 id="<?php echo esc_attr($modal_id); ?>-title">Detalles completos</h2>
                    </div>
                    <button class="precaptaciones-precap-modal__close" type="button" data-precaptaciones-modal-close aria-label="Cerrar detalles">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                            <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <dl class="precaptaciones-detalle">
                    <?php foreach ($fields as $label => $value) : ?>
                        <?php if (trim((string) $value) === '') : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="precaptaciones-detalle__item <?php echo strlen((string) $value) > 80 ? 'precaptaciones-detalle__item--wide' : ''; ?>">
                            <dt><?php echo esc_html($label); ?></dt>
                            <dd><?php echo esc_html(self::display_text((string) $value)); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <div class="precaptaciones-precap-modal__footer">
                    <button class="precaptaciones-precap-modal__secondary" type="button" data-precaptaciones-modal-close>Cerrar</button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_edit_modal(string $table, array $row): void
    {
        $id = self::row_value($table, $row, ['_ID', 'id_precaptacion']);
        $employee_id = self::row_value($table, $row, ['id_empleado', 'cct_author_id', 'captador_precat']);
        if (!$employee_id) {
            $employee_id = get_current_user_id();
        }

        $id_pph = self::row_value($table, $row, ['id_pph']);
        $origen = self::row_value($table, $row, ['origen']);
        $bandera = self::row_value($table, $row, ['bandera']);
        $is_pph = absint($id_pph) > 0 && strtolower(trim($origen)) === 'club pph';
        $contacto = self::row_value($table, $row, ['contacto', 'nombre_contacto', 'nombre_del_contacto']);
        $correo = self::row_value($table, $row, ['correo', 'email']);
        $celular = self::row_value($table, $row, ['celular', 'celular_precat', 'telefono']);
        $indicativo = self::row_value($table, $row, ['indicativo', 'codigo_pais', 'pais_indicativo']);
        $razones = self::row_value($table, $row, ['razones', 'razones_precap', 'razon']);
        $resultado = self::row_value($table, $row, ['resultado']);
        $merece_ticket = self::row_value($table, $row, ['merece_ticket']);
        $modal_id = 'precaptaciones-precap-modal-' . $id;
        ?>
        <div class="precaptaciones-precap-modal" id="<?php echo esc_attr($modal_id); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_id); ?>-title" hidden>
            <div class="precaptaciones-precap-modal__backdrop" data-precaptaciones-modal-close></div>
            <div class="precaptaciones-precap-modal__panel" role="document">
                <div class="precaptaciones-precap-modal__header">
                    <div>
                        <p class="precaptaciones-precap-modal__eyebrow">Precaptacion #<?php echo esc_html((string) $id); ?></p>
                        <h2 id="<?php echo esc_attr($modal_id); ?>-title">Editar resultado</h2>
                    </div>
                    <button class="precaptaciones-precap-modal__close" type="button" data-precaptaciones-modal-close aria-label="Cerrar popup">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                            <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <form class="precaptaciones-precap-modal__form" data-precaptaciones-precap-form>
                    <input type="hidden" name="action" value="precaptaciones_actualizar">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('precaptaciones_actualizar')); ?>">
                    <input type="hidden" name="id_precaptacion" value="<?php echo esc_attr((string) $id); ?>">
                    <input type="hidden" name="id_pph" value="<?php echo esc_attr($id_pph); ?>">
                    <input type="hidden" name="id_empleado" value="<?php echo esc_attr((string) $employee_id); ?>">
                    <input type="hidden" name="fecha" value="<?php echo esc_attr((string) time()); ?>">
                    <input type="hidden" name="origen" value="<?php echo esc_attr($origen); ?>">
                    <input type="hidden" name="bandera" value="<?php echo esc_attr($bandera); ?>">

                    <div class="precaptaciones-precap-modal__grid">
                        <label>
                            <span>Contacto</span>
                            <input class="precaptaciones__control" type="text" name="contacto" value="<?php echo esc_attr($contacto); ?>" autocomplete="name">
                        </label>

                        <label>
                            <span>Correo</span>
                            <input class="precaptaciones__control" type="email" name="correo" value="<?php echo esc_attr($correo); ?>" autocomplete="email">
                        </label>

                        <label>
                            <span>Celular</span>
                            <input class="precaptaciones__control" type="tel" name="celular" value="<?php echo esc_attr($celular); ?>" autocomplete="tel">
                        </label>

                        <label>
                            <span>Razones</span>
                            <select class="precaptaciones__control" name="razones">
                                <option value="">Elige una opcion</option>
                                <?php self::render_select_options(self::editable_reason_options($table), $razones, false); ?>
                            </select>
                        </label>

                        <label class="precaptaciones-precap-modal__wide">
                            <span>Resultado</span>
                            <textarea class="precaptaciones__control" name="resultado" rows="5" placeholder="Describe todas las observaciones encontradas."><?php echo esc_textarea($resultado); ?></textarea>
                        </label>

                        <label>
                            <span>¿Merece tarea o seguir llamando?</span>
                            <select class="precaptaciones__control" name="merece_ticket" data-precaptaciones-merece-ticket>
                                <option value="">Elige una opcion</option>
                                <?php self::render_select_options(Module::policy()->canAct('precaptacion_ticket') ? ['Si', 'No', 'Seguir llamando'] : ['No', 'Seguir llamando'], $merece_ticket); ?>
                            </select>
                        </label>

                        <?php if ($is_pph) : ?>
                            <div class="precaptaciones-precap-modal__pph-note precaptaciones-precap-modal__wide" data-precaptaciones-pph-note>
                                <strong>Club PPH:</strong> si ya hubo contacto pero debes continuar el seguimiento, selecciona <strong>Seguir llamando</strong>. Si seleccionas <strong>No</strong>, se registran puntos PPH como inmueble no efectivo.
                            </div>
                        <?php endif; ?>

                        <label class="precaptaciones-precap-modal__asignar" data-precaptaciones-asignar-ticket>
                            <span>Asignar a</span>
                            <?php self::render_ticket_employee_select('id_empleado_2', (string) $employee_id); ?>
                        </label>
                    </div>

                    <div class="precaptaciones-precap-modal__message" data-precaptaciones-form-message aria-live="polite"></div>

                    <div class="precaptaciones-precap-modal__footer">
                        <button class="precaptaciones-precap-modal__secondary" type="button" data-precaptaciones-modal-close>Cancelar</button>
                        <button class="precaptaciones-precap-modal__primary" type="submit">
                            Guardar respuesta
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_ticket_modal(string $table, array $row): void
    {
        $id = self::row_value($table, $row, ['_ID', 'id_precaptacion']);
        $employee_id = self::row_value($table, $row, ['id_empleado', 'cct_author_id', 'captador_precat']);
        if (!$employee_id) {
            $employee_id = get_current_user_id();
        }

        $contacto = self::row_value($table, $row, ['contacto', 'nombre_contacto', 'nombre_del_contacto']);
        $correo = self::row_value($table, $row, ['correo', 'email']);
        $celular = self::row_value($table, $row, ['celular', 'celular_precat', 'telefono']);
        $id_pph = self::row_value($table, $row, ['id_pph']);
        $origen = self::row_value($table, $row, ['origen']);
        $indicativo = self::row_value($table, $row, ['indicativo', 'pais_indicativo', 'codigo_pais']);
        $tipo_inmueble = self::row_value($table, $row, ['tipo_inmueble', 'tipo_de_inmueble']);
        $categoria = self::row_value($table, $row, ['categoria', 'categoria_inmueble']);
        $direccion = self::row_value($table, $row, ['direccion', 'direccion_inmueble']);
        $barrio = self::row_value($table, $row, ['barrio', 'sector']);
        $modal_id = 'precaptaciones-ticket-modal-' . $id;
        ?>
        <div class="precaptaciones-precap-modal" id="<?php echo esc_attr($modal_id); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_id); ?>-title" data-precaptaciones-ticket-modal hidden>
            <div class="precaptaciones-precap-modal__backdrop"></div>
            <div class="precaptaciones-precap-modal__panel" role="document">
                <div class="precaptaciones-precap-modal__header">
                    <div>
                        <p class="precaptaciones-precap-modal__eyebrow">Precaptacion #<?php echo esc_html((string) $id); ?></p>
                        <h2 id="<?php echo esc_attr($modal_id); ?>-title">Crear tarea comercial</h2>
                    </div>
                </div>

                <form class="precaptaciones-precap-modal__form" data-precaptaciones-ticket-form>
                    <input type="hidden" name="action" value="precaptaciones_crear_ticket">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('precaptaciones_crear_ticket')); ?>">
                    <input type="hidden" name="id_precaptacion" value="<?php echo esc_attr((string) $id); ?>">
                    <input type="hidden" name="id_pph" value="<?php echo esc_attr($id_pph); ?>">
                    <input type="hidden" name="id_empleado" value="<?php echo esc_attr((string) $employee_id); ?>">

                    <div class="precaptaciones-precap-modal__grid">
                        <label class="precaptaciones-precap-modal__wide">
                            <span>Asignar a</span>
                            <?php self::render_ticket_employee_select('asignado', (string) $employee_id); ?>
                        </label>

                        <label>
                            <span>Nombre propietario o solicitante</span>
                            <input class="precaptaciones__control" type="text" name="solicitante" value="<?php echo esc_attr($contacto); ?>" readonly>
                        </label>

                        <label>
                            <span>Correo propietario o solicitante</span>
                            <input class="precaptaciones__control" type="email" name="correo_solicitante" value="<?php echo esc_attr($correo); ?>" readonly>
                        </label>

                        <label>
                            <span>Celular propietario o solicitante</span>
                            <input class="precaptaciones__control" type="tel" name="celular_solicitante" value="<?php echo esc_attr($celular); ?>" readonly>
                        </label>

                        <label>
                            <span>Pais / indicativo</span>
                            <?php self::render_value_label_select('indicativo', self::country_indicativo_options(), $indicativo, 'Elige un pais'); ?>
                        </label>

                        <label>
                            <span>Prioridad</span>
                            <input class="precaptaciones__control" type="text" name="prioridad" value="Prioridad comercial" readonly>
                        </label>

                        <label>
                            <span>Tema de ayuda</span>
                            <input class="precaptaciones__control" type="text" name="tema_ayuda" value="Captacion" readonly>
                        </label>

                        <label>
                            <span>Tipo de inmueble</span>
                            <?php self::render_simple_select('tipo_inmueble', self::ticket_tipo_inmueble_options($table, $tipo_inmueble), $tipo_inmueble, 'Seleccionar tipo de inmueble'); ?>
                        </label>

                        <label>
                            <span>Destinacion</span>
                            <?php self::render_simple_select('destinacion', self::ticket_destinacion_options($categoria), $categoria, 'Seleccionar destinacion'); ?>
                        </label>

                        <label>
                            <span>Medio</span>
                            <input class="precaptaciones__control" type="text" name="medio" value="<?php echo esc_attr($origen); ?>" readonly>
                        </label>

                        <label class="precaptaciones-precap-modal__wide">
                            <span>Asunto</span>
                            <input class="precaptaciones__control" type="text" name="asunto" value="<?php echo esc_attr(trim('Precaptacion comercial ' . $tipo_inmueble . ' ' . $barrio)); ?>">
                        </label>

                        <label class="precaptaciones-precap-modal__wide">
                            <span>Resumen</span>
                            <textarea class="precaptaciones__control" name="descripcion" rows="5"><?php echo esc_textarea(trim('Direccion: ' . $direccion . "\nBarrio: " . $barrio . "\nOrigen: " . $origen)); ?></textarea>
                        </label>
                    </div>

                    <div class="precaptaciones-precap-modal__message" data-precaptaciones-ticket-message aria-live="polite"></div>

                    <div class="precaptaciones-precap-modal__footer">
                        <button class="precaptaciones-precap-modal__primary" type="submit">Crear tarea comercial</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function distinct_values_for_candidates(string $table, array $candidates): array
    {
        $column = self::first_existing_column($table, $candidates);
        if (!$column) {
            return [];
        }

        return self::distinct_values($table, $column);
    }

    private static function editable_reason_options(string $table): array
    {
        $options = array_merge(
            ['Precaptacion duplicada', 'Precaptacion sin informacion'],
            self::distinct_values_for_candidates($table, ['razones', 'razones_precap', 'razon'])
        );

        return array_values(array_filter($options, static function ($option): bool {
            return strtolower(remove_accents(trim((string) $option))) !== 'ticket creado';
        }));
    }

    private static function render_select_options(array $options, string $selected, bool $include_selected = true): void
    {
        $options = $include_selected ? array_merge([$selected], $options) : $options;
        $options = array_values(array_unique(array_filter($options, 'strlen')));
        foreach ($options as $option) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr((string) $option),
                selected($selected, (string) $option, false),
                esc_html(self::display_text((string) $option))
            );
        }
    }

    private static function render_simple_select(string $name, array $options, string $selected, string $placeholder): void
    {
        ?>
        <select class="precaptaciones__control" name="<?php echo esc_attr($name); ?>">
            <option value=""><?php echo esc_html($placeholder); ?></option>
            <?php self::render_select_options($options, $selected); ?>
        </select>
        <?php
    }

    private static function render_value_label_select(string $name, array $options, string $selected, string $placeholder): void
    {
        ?>
        <select class="precaptaciones__control" name="<?php echo esc_attr($name); ?>">
            <option value=""><?php echo esc_html($placeholder); ?></option>
            <?php foreach ($options as $option) : ?>
                <option value="<?php echo esc_attr((string) $option['value']); ?>" <?php selected((string) $selected, (string) $option['value']); ?>>
                    <?php echo esc_html(self::display_text($option['label'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function ticket_tipo_inmueble_options(string $table, string $selected): array
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            $cache[$table] = self::distinct_values_for_candidates($table, ['tipo_inmueble', 'tipo_de_inmueble']);
        }

        return array_merge([$selected], $cache[$table]);
    }

    private static function ticket_destinacion_options(string $selected): array
    {
        return array_merge([$selected], ['Arriendo', 'Venta', 'Arriendo o venta']);
    }

    private static function country_indicativo_options(): array
    {
        global $wpdb;

        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $table = self::paises_table();
        if (!$table) {
            return $cache = [];
        }

        $name_col = self::first_existing_column($table, ['nombre', 'pais', 'country']);
        $value_col = self::first_existing_column($table, ['indicativo', 'codigo', 'phone_code']);
        if (!$name_col || !$value_col) {
            return $cache = [];
        }

        $rows = $wpdb->get_results("SELECT {$name_col} AS label, {$value_col} AS value FROM {$table} WHERE {$name_col} IS NOT NULL AND TRIM({$name_col}) <> '' AND {$value_col} IS NOT NULL AND TRIM({$value_col}) <> '' ORDER BY {$name_col} ASC", ARRAY_A);
        $options = [];
        foreach ($rows ?: [] as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }

            $options[$value] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $cache = array_values($options);
    }

    private static function paises_table(): string
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        foreach (['jet_cct_paises', 'jet_cct_pais', 'paises', 'pais'] as $name) {
            $table = self::table_name($name);
            if (self::table_exists($table)) {
                return $cache = $table;
            }
        }

        return $cache = '';
    }

    private static function render_ticket_employee_select(string $name, string $selected): void
    {
        $employees = self::active_funcionarios();
        ?>
        <select class="precaptaciones__control" name="<?php echo esc_attr($name); ?>">
            <option value="">Elige una opcion</option>
            <?php foreach ($employees as $employee) : ?>
                <option value="<?php echo esc_attr((string) $employee['value']); ?>" <?php selected((string) $selected, (string) $employee['value']); ?>>
                    <?php echo esc_html($employee['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function active_funcionarios(): array
    {
        global $wpdb;

        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $table = self::funcionarios_table();
        if (!$table) {
            return $cache = [];
        }

        $id_col = self::first_existing_column($table, ['id_empleado']);
        $name_col = self::first_existing_column($table, ['nombre', 'display_name', 'nombre_funcionario', 'funcionario', 'nombre_empleado', 'empleado', 'nombres']);
        $active_col = self::first_existing_column($table, ['activo', 'funcionario_activo', 'funcionario-activo', 'empleado_activo', 'empleado-activo', 'estado', 'estado_funcionario', 'estatus']);
        if (!$id_col || !$name_col || !$active_col) {
            return $cache = [];
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE LOWER(TRIM({$active_col})) IN ('si', 'sí', '1', 'activo', 'active') ORDER BY {$name_col} ASC",
            ARRAY_A
        );

        $employees = [];
        foreach ($rows ?: [] as $row) {
            $value = (string) ($row[$id_col] ?? '');
            $label = trim((string) ($row[$name_col] ?? ''));
            if ($value === '' || $label === '') {
                continue;
            }

            $employees[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $cache = $employees;
    }

    private static function funcionario_data($id): array
    {
        global $wpdb;

        $id = trim((string) $id);
        $table = self::funcionarios_table();
        if ($id === '' || !$table) {
            return [];
        }

        $id_col = self::first_existing_column($table, ['id_empleado']);
        if (!$id_col) {
            return [];
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$id_col} = %s LIMIT 1", $id), ARRAY_A);
        if (!$row) {
            return [];
        }

        $name_col = self::first_existing_column($table, ['nombre', 'display_name', 'nombre_funcionario', 'funcionario', 'nombre_empleado', 'empleado', 'nombres']);
        $email_col = self::first_existing_column($table, ['user_email', 'correo', 'email', 'correo_empleado', 'correo_funcionario']);
        $phone_col = self::first_existing_column($table, ['celular', 'telefono', 'celular_empleado', 'celular_funcionario']);

        return [
            'celular' => $phone_col ? (string) ($row[$phone_col] ?? '') : '',
            'correo' => $email_col ? (string) ($row[$email_col] ?? '') : '',
            'nombre' => $name_col ? (string) ($row[$name_col] ?? '') : '',
            'user_id' => (string) ($row[$id_col] ?? $id),
        ];
    }

    private static function pph_name($id_pph): string
    {
        global $wpdb;

        $id_pph = absint($id_pph);
        if (!$id_pph) {
            return '';
        }

        static $cache = [];
        if (isset($cache[$id_pph])) {
            return $cache[$id_pph];
        }

        $table = self::table_name('jet_cct_club_pph');
        if (!self::table_exists($table)) {
            return $cache[$id_pph] = (string) $id_pph;
        }

        $name_col = self::first_existing_column($table, ['nombre', 'nombre_pph', 'solicitante']);
        if (!$name_col) {
            return $cache[$id_pph] = (string) $id_pph;
        }

        $name = trim((string) $wpdb->get_var($wpdb->prepare("SELECT {$name_col} FROM {$table} WHERE _ID = %d LIMIT 1", $id_pph)));

        return $cache[$id_pph] = ($name !== '' ? $name : (string) $id_pph);
    }

    private static function funcionarios_table(): string
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        foreach (['jet_cct_funcionarios', 'jet_cct_funcionario', 'jet_cct_empleados', 'jet_cct_empleado'] as $name) {
            $table = self::table_name($name);
            if (self::table_exists($table)) {
                return $cache = $table;
            }
        }

        return $cache = '';
    }

    private static function render_ticket(string $table, array $row): string
    {
        $ticket = self::row_value($table, $row, ['ticket', 'numero_de_ticket']);
        $url = self::row_value($table, $row, ['url_ticket', 'url_ticket_precap']);
        $assigned_ticket_id = self::row_value($table, $row, ['id_ticket_asignado', 'ticket_asignado', 'id_ticket']);
        $reason = self::row_value($table, $row, ['razones', 'razones_precap', 'razon']);
        if (!$assigned_ticket_id && $ticket && strtolower(remove_accents(trim($reason))) === 'ticket creado') {
            $assigned_ticket_id = $ticket;
        }

        if (!$ticket && !$url && !$assigned_ticket_id) {
            return '';
        }

        if ($assigned_ticket_id) {
            $ticket_url = add_query_arg(
                ['id_ticket' => (string) $assigned_ticket_id],
                'https://sucasainmobiliaria.com.co/ticket/'
            );

            return sprintf(
                '<a class="precaptaciones__ticket-boton" href="%s" target="_blank" rel="noopener">Ver tarea</a>',
                esc_url($ticket_url)
            );
        }

        if ($url) {
            return sprintf(
                '<a class="precaptaciones__ticket-boton" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url($url),
                esc_html($ticket ?: 'Ver')
            );
        }

        return esc_html($ticket);
    }

    private static function row_actions_locked(string $table, array $row): bool
    {
        $merece_ticket = self::normalize_choice(self::row_value($table, $row, ['merece_ticket', 'merece_ticket_precat', 'merece_ticket_precap', 'efectivo']));
        if ($merece_ticket === 'no') {
            return true;
        }

        return self::row_has_ticket($table, $row);
    }

    private static function row_has_ticket(string $table, array $row): bool
    {
        $reason = self::normalize_choice(self::row_value($table, $row, ['razones', 'razones_precap', 'razon']));
        if ($reason === 'ticket creado') {
            return true;
        }

        $tiene_ticket = self::normalize_choice(self::row_value($table, $row, ['tiene_ticket', 'ticket_creado', 'con_ticket']));
        if (in_array($tiene_ticket, ['si', '1', 'true'], true)) {
            return true;
        }

        $ticket = trim(self::row_value($table, $row, [
            'id_ticket_asignado',
            'id_ticket-asignado',
            'id_ticket asignado',
            'ticket_asignado',
            'ticket-asignado',
            'ticket asignado',
            'id_ticket',
            'id-ticket',
            'id ticket',
            'ticket',
            'numero_de_ticket',
            'numero-ticket',
            'numero ticket',
            'ticket_id',
            'id_del_ticket',
            'url_ticket',
            'url_ticket_precap',
        ]));

        return $ticket !== '';
    }

    private static function normalize_choice(string $value): string
    {
        return strtolower(remove_accents(trim($value)));
    }

    private static function render_evidence(string $table, array $row): string
    {
        $urls = self::row_media_urls($table, $row);
        if (!$urls) {
            return '<span class="precaptaciones__sin-evidencia">Sin evidencia</span>';
        }

        $count = count($urls);
        $json = wp_json_encode(array_values($urls));
        if (!$json) {
            return '';
        }

        return sprintf(
            '<button class="precaptaciones__evidencia-boton" type="button" data-precaptaciones-evidence="%1$s" aria-label="%2$s"><img class="precaptaciones__evidencia" src="%3$s" alt="Evidencia">%4$s</button>',
            esc_attr($json),
            esc_attr($count > 1 ? sprintf('Ver %d evidencias', $count) : 'Ver evidencia'),
            esc_url($urls[0]),
            $count > 1 ? '<span class="precaptaciones__evidencia-cuenta">+' . esc_html((string) ($count - 1)) . '</span>' : ''
        );
    }

    private static function row_media_urls(string $table, array $row): array
    {
        $urls = [];
        foreach (self::evidence_candidates() as $candidate) {
            $raw = self::row_value($table, $row, [$candidate]);
            if ($raw !== '') {
                self::collect_media_urls($raw, $urls);
            }
        }

        if ($urls) {
            return array_values(array_unique(array_filter($urls, 'strlen')));
        }

        foreach ($row as $column => $value) {
            if (!self::looks_like_media_column((string) $column) || !is_scalar($value)) {
                continue;
            }

            self::collect_media_urls((string) $value, $urls);
        }

        return array_values(array_unique(array_filter($urls, 'strlen')));
    }

    private static function evidence_candidates(): array
    {
        return [
            'evidencia',
            'evidencias',
            'evidencia_precat',
            'evidencia_precap',
            'evidencia_precaptacion',
            'evidencia_fotografica',
            'foto',
            'fotos',
            'fotografia',
            'fotografias',
            'imagen',
            'imagenes',
            'image',
            'images',
            'foto_inmueble',
            'imagen_inmueble',
            'foto_fachada',
            'fachada',
            'galeria',
            'galeria_fotos',
            'galeria_de_fotos',
            'media',
            'archivo',
            'archivos',
            'file',
            'files',
            'attachment',
            'attachments',
        ];
    }

    private static function looks_like_media_column(string $column): bool
    {
        $column = strtolower(remove_accents($column));
        foreach (['evidencia', 'foto', 'imagen', 'image', 'galeria', 'media', 'archivo', 'file', 'attachment', 'fachada'] as $needle) {
            if (strpos($column, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function extract_media_urls(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $urls = [];
        self::collect_media_urls($raw, $urls);

        return array_values(array_unique(array_filter($urls, 'strlen')));
    }

    private static function collect_media_urls($value, array &$urls): void
    {
        if (is_array($value)) {
            foreach (['url', 'guid', 'source_url', 'full', 'thumbnail'] as $key) {
                if (!empty($value[$key])) {
                    self::collect_media_urls($value[$key], $urls);
                }
            }
            foreach ($value as $item) {
                self::collect_media_urls($item, $urls);
            }
            return;
        }

        if (is_object($value)) {
            self::collect_media_urls((array) $value, $urls);
            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return;
        }
        $raw = html_entity_decode(stripslashes($raw), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');

        if (is_numeric($raw)) {
            $url = wp_get_attachment_image_url((int) $raw, 'large');
            if ($url) {
                $urls[] = $url;
            }
            return;
        }

        if (strpos($raw, '/wp-content/uploads/') === 0) {
            $urls[] = home_url($raw);
            return;
        }

        if (strpos($raw, 'wp-content/uploads/') === 0) {
            $urls[] = home_url('/' . $raw);
            return;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            $urls[] = $raw;
            return;
        }

        if (preg_match('/^\d+([\s,|]+\d+)+$/', $raw)) {
            foreach (preg_split('/[\s,|]+/', $raw) as $attachment_id) {
                self::collect_media_urls($attachment_id, $urls);
            }
            return;
        }

        if (function_exists(__NAMESPACE__ . '\\maybe_unserialize')) {
            $unserialized = maybe_unserialize($raw);
            if (is_array($unserialized) || is_object($unserialized)) {
                self::collect_media_urls($unserialized, $urls);
                return;
            }
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            self::collect_media_urls($decoded, $urls);
        }

        if (preg_match_all('/https?:\/\/[^\s"\',\]]+/i', $raw, $matches)) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim($match, '\\');
            }
        }

        if (preg_match_all('/(?:\/)?wp-content\/uploads\/[^\s"\',\]]+/i', $raw, $matches)) {
            foreach ($matches[0] as $match) {
                $match = '/' . ltrim(rtrim($match, '\\'), '/');
                $urls[] = home_url($match);
            }
        }
    }

    private static function render_pagination(int $total, int $per_page, int $current_page): void
    {
        $pages = max(1, (int) ceil($total / $per_page));
        $start = $total ? min($total, ($current_page - 1) * $per_page + 1) : 0;
        $end = min($total, $current_page * $per_page);
        echo '<footer class="precap-results-footer"><p>Mostrando <strong>' . number_format_i18n($start) . ' a ' . number_format_i18n($end) . '</strong> de <strong>' . number_format_i18n($total) . '</strong> precaptaciones</p>';
        echo '<nav class="precaptaciones__paginacion" aria-label="Paginación de precaptaciones">';
        echo '<button type="button" data-precaptaciones-page="' . max(1, $current_page - 1) . '"' . ($current_page <= 1 ? ' disabled' : '') . '>Anterior</button>';
        $visible = array_unique(array_merge([1, $pages], range(max(1, $current_page - 1), min($pages, $current_page + 1))));
        sort($visible);
        $previous = 0;
        foreach ($visible as $i) {
            if ($previous && $i > $previous + 1) echo '<span aria-hidden="true">…</span>';
            echo '<button type="button" data-precaptaciones-page="' . $i . '" aria-label="Página ' . $i . '"' . ($i === $current_page ? ' class="is-active" aria-current="page"' : '') . '>' . $i . '</button>';
            $previous = $i;
        }
        echo '<button type="button" data-precaptaciones-page="' . min($pages, $current_page + 1) . '"' . ($current_page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav></footer>';
    }

    private static function render_bulk_old_not_contacted_button(int $count): void
    {
        ?>
        <div class="precaptaciones__bulk">
            <button
                class="precaptaciones__bulk-button"
                type="button"
                data-precaptaciones-bulk-old-not-contacted
                data-count="<?php echo esc_attr((string) $count); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_marcar_antiguas_no_contactadas')); ?>"
                <?php disabled($count <= 0); ?>
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Marcar antiguas no contactadas
            </button>
            <span class="precaptaciones__bulk-count" data-precaptaciones-bulk-count>
                <?php echo esc_html(number_format_i18n($count)); ?> pendientes antes de 2026
            </span>
            <span class="precaptaciones__bulk-message" data-precaptaciones-bulk-message aria-live="polite"></span>
        </div>
        <?php
    }

    private static function render_bulk_promocionado_por_button(int $count): void
    {
        ?>
        <div class="precaptaciones__bulk">
            <button
                class="precaptaciones__bulk-button"
                type="button"
                data-precaptaciones-normalizar-promocionado
                data-count="<?php echo esc_attr((string) $count); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_normalizar_promocionado_por')); ?>"
                <?php disabled($count <= 0); ?>
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <path d="M4 7h10M4 12h16M4 17h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Normalizar promocionado por
            </button>
            <span class="precaptaciones__bulk-count" data-precaptaciones-bulk-count>
                <?php echo esc_html(number_format_i18n($count)); ?>
            </span>
            <span class="precaptaciones__bulk-message" data-precaptaciones-bulk-message aria-live="polite"></span>
        </div>
        <?php
    }

    private static function render_bulk_competencia_button(int $count): void
    {
        ?>
        <div class="precaptaciones__bulk">
            <button
                class="precaptaciones__bulk-button"
                type="button"
                data-precaptaciones-normalizar-competencia
                data-count="<?php echo esc_attr((string) $count); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('precaptaciones_normalizar_competencia')); ?>"
                <?php disabled($count <= 0); ?>
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <path d="M5 5h14M5 12h14M5 19h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Normalizar competencia
            </button>
            <span class="precaptaciones__bulk-count" data-precaptaciones-bulk-count>
                <?php echo esc_html(number_format_i18n($count)); ?>
            </span>
            <span class="precaptaciones__bulk-message" data-precaptaciones-bulk-message aria-live="polite"></span>
        </div>
        <?php
    }

    private static function count_precaptaciones_anteriores_no_contactadas(string $table): int
    {
        global $wpdb;

        $parts = self::old_not_contacted_query_parts($table);
        if (!$parts) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$parts['where']}",
            $parts['values']
        ));
    }

    private static function old_not_contacted_query_parts(string $table): array
    {
        $contactado_col = self::column_for($table, 'contactado');
        $date_col = self::first_existing_column($table, ['fecha', 'cct_created', '_created']);

        if (!$contactado_col || !$date_col) {
            return [];
        }

        $threshold_timestamp = strtotime('2026-01-01 00:00:00');
        $threshold_date = '2026-01-01';

        return [
            'contactado_col' => $contactado_col,
            'date_col' => $date_col,
            'where' => "LOWER(TRIM({$contactado_col})) = %s AND {$date_col} IS NOT NULL AND {$date_col} <> '' AND (({$date_col} REGEXP '^[0-9]+$' AND CAST({$date_col} AS UNSIGNED) < %d) OR ({$date_col} NOT REGEXP '^[0-9]+$' AND {$date_col} < %s))",
            'values' => ['no', $threshold_timestamp, $threshold_date],
        ];
    }

    private static function count_promocionado_por_por_normalizar(string $table): int
    {
        return self::count_serialized_choice_por_normalizar($table, 'promocionado_por');
    }

    private static function count_competencia_por_normalizar(string $table): int
    {
        return self::count_serialized_choice_por_normalizar($table, 'competencia');
    }

    private static function count_razon_creado_por_normalizar(string $table): int
    {
        global $wpdb;

        $column = self::column_for($table, 'razones');
        if (!$column) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE LOWER(TRIM({$column})) = %s",
            'creado'
        ));
    }

    private static function normalizar_razon_creado(string $table): array
    {
        global $wpdb;

        $column = self::column_for($table, 'razones');
        if (!$column) {
            return [
                'error' => 'No se encontro la columna de razon.',
                'remaining' => 0,
                'updated' => 0,
            ];
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET {$column} = %s WHERE LOWER(TRIM({$column})) = %s",
            'Ticket creado',
            'creado'
        ));

        if ($updated === false) {
            return [
                'error' => 'Error al actualizar razones: ' . $wpdb->last_error,
                'remaining' => self::count_razon_creado_por_normalizar($table),
                'updated' => 0,
            ];
        }

        return [
            'error' => '',
            'remaining' => self::count_razon_creado_por_normalizar($table),
            'updated' => (int) $updated,
        ];
    }

    private static function count_serialized_choice_por_normalizar(string $table, string $logical): int
    {
        global $wpdb;

        $column = self::column_for($table, $logical);
        if (!$column) {
            return 0;
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
            WHERE {$column} IS NOT NULL
                AND TRIM({$column}) <> ''
                AND (TRIM({$column}) NOT LIKE 'a:%' OR {$column} LIKE '%s:0:\"\";%')"
        );
    }

    private static function normalize_promocionado_por_values(string $table): array
    {
        return self::normalize_serialized_choice_values($table, 'promocionado_por', 'promocionado por');
    }

    private static function normalize_competencia_values(string $table): array
    {
        return self::normalize_serialized_choice_values($table, 'competencia', 'competencia');
    }

    private static function normalize_serialized_choice_values(string $table, string $logical, string $label): array
    {
        global $wpdb;

        $column = self::column_for($table, $logical);
        $id_col = self::first_existing_column($table, ['_ID', 'id_precaptacion', 'id']);
        if (!$column || !$id_col) {
            return [
                'error' => sprintf('Faltan columnas requeridas para %s o ID.', $label),
            ];
        }

        $rows = $wpdb->get_results("SELECT {$id_col} AS row_id, {$column} AS raw_value FROM {$table} WHERE {$column} IS NOT NULL AND TRIM({$column}) <> ''", ARRAY_A);
        $updated = 0;
        $scanned = 0;

        foreach ($rows ?: [] as $row) {
            $scanned++;
            $raw = trim((string) ($row['raw_value'] ?? ''));
            if ($raw === '') {
                continue;
            }

            $canonical = self::canonical_serialized_choice($raw);
            if ($canonical === $raw) {
                continue;
            }

            $row_id = (string) ($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }

            $result = $wpdb->update(
                $table,
                [$column => $canonical],
                [$id_col => $row_id],
                ['%s'],
                [ctype_digit($row_id) ? '%d' : '%s']
            );

            if ($result === false) {
                return [
                    'error' => sprintf('Error al normalizar %s: %s', $label, $wpdb->last_error),
                ];
            }

            $updated += (int) $result;
        }

        return [
            'error' => '',
            'remaining' => self::count_serialized_choice_por_normalizar($table, $logical),
            'scanned' => $scanned,
            'updated' => $updated,
        ];
    }

    private static function render_distinct_select(
        string $table,
        string $logical,
        string $label,
        string $name,
        string $selected,
        string $placeholder
    ): void {
        $column = self::column_for($table, $logical);
        if (!$column && $logical !== 'tiene_ticket') {
            return;
        }

        $options = $column ? self::distinct_values($table, $column) : ['Si', 'No'];
        if ($logical === 'tiene_ticket') {
            $options = array_values(array_unique(array_merge(['Si', 'No'], $options)));
        }
        if ($logical === 'razones') {
            $options = array_values(array_unique(array_merge(['Ticket creado', 'Precaptacion duplicada', 'Precaptacion sin informacion'], $options)));
        }
        $options = in_array($logical, ['promocionado_por', 'competencia'], true)
            ? self::serialized_choice_options($options, $selected)
            : array_map(static function ($option): array {
                return [
                    'label' => (string) $option,
                    'value' => (string) $option,
                ];
            }, $options);
        ?>
        <label class="precaptaciones__campo min-w-0">
            <span class="mb-2 block text-sm font-bold text-slate-800"><?php echo esc_html($label); ?></span>
            <select class="precaptaciones__control" name="<?php echo esc_attr($name); ?>">
                <option value=""><?php echo esc_html($placeholder); ?></option>
                <?php foreach ($options as $option) : ?>
                    <option value="<?php echo esc_attr($option['value']); ?>" <?php selected($selected, $option['value']); ?>>
                        <?php echo esc_html(self::display_text($option['label'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
    }

    private static function render_user_select(string $table, string $name, string $selected): void
    {
        global $wpdb;

        $employee_col = self::first_existing_column($table, ['id_empleado', 'cct_author_id', 'captador_precat']);
        if (!$employee_col) {
            return;
        }

        $ids = $wpdb->get_col("SELECT DISTINCT {$employee_col} FROM {$table} WHERE {$employee_col} IS NOT NULL AND {$employee_col} <> '' ORDER BY {$employee_col} ASC");
        ?>
        <select class="precaptaciones__control" name="<?php echo esc_attr($name); ?>">
            <option value="">Elige un funcionario</option>
            <?php foreach ($ids as $id) : ?>
                <option value="<?php echo esc_attr((string) $id); ?>" <?php selected((string) $selected, (string) $id); ?>>
                    <?php echo esc_html(self::employee_name($id)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function employee_name($user_id): string
    {
        if (!$user_id) {
            return '';
        }

        $funcionario = self::funcionario_data($user_id);
        if (!empty($funcionario['nombre'])) {
            return (string) $funcionario['nombre'];
        }

        $user = get_userdata((int) $user_id);
        if ($user) {
            return $user->display_name;
        }

        return (string) $user_id;
    }

    private static function read_filters(?array $source = null): array
    {
        $source = $source ?? $_GET;
        $keys = [
            'fecha_desde',
            'fecha_hasta',
            'buscar',
            'ticket',
            'celular',
            'funcionario',
            'contactado',
            'merece_ticket',
            'tiene_ticket',
            'seguimiento',
            'ruta',
            'tipo_inmueble',
            'categoria',
            'barrio',
            'promocionado_por',
            'competencia',
            'origen',
            'razones',
        ];

        $filters = [];
        foreach ($keys as $key) {
            $value = sanitize_text_field(wp_unslash($source['precaptaciones_' . $key] ?? ''));
            $filters[$key] = in_array($key, ['fecha_desde', 'fecha_hasta'], true) ? self::normalize_date_filter($value) : trim($value);
        }

        return $filters;
    }

    private static function build_where(string $table, array $filters, bool $is_control): array
    {
        $where = ['1=1'];
        $values = [];

        if (!$is_control) {
            $employee_col = self::first_existing_column($table, ['id_empleado', 'cct_author_id', 'captador_precat']);
            if ($employee_col) {
                $where[] = "{$employee_col} = %d";
                $values[] = get_current_user_id();
            }
        } elseif ($filters['funcionario'] !== '') {
            $employee_col = self::first_existing_column($table, ['id_empleado', 'cct_author_id', 'captador_precat']);
            if ($employee_col) {
                $where[] = "{$employee_col} = %s";
                $values[] = $filters['funcionario'];
            }
        }

        $date_col = self::first_existing_column($table, ['fecha', 'cct_created', '_created']);
        self::add_date_filter($where, $values, $date_col, '>=', $filters['fecha_desde'], '00:00:00');
        self::add_date_filter($where, $values, $date_col, '<=', $filters['fecha_hasta'], '23:59:59');

        self::add_search_filter($table, $where, $values, $filters['buscar']);
        self::add_like_filter($table, $where, $values, 'ticket', $filters['ticket']);
        self::add_like_filter($table, $where, $values, 'celular', $filters['celular']);
        self::add_exact_filter($table, $where, $values, 'contactado', $filters['contactado']);
        self::add_exact_filter($table, $where, $values, 'merece_ticket', $filters['merece_ticket']);
        self::add_ticket_status_filter($table, $where, $values, $filters['tiene_ticket']);
        self::add_exact_filter($table, $where, $values, 'seguimiento', $filters['seguimiento']);
        self::add_exact_filter($table, $where, $values, 'ruta', $filters['ruta']);
        self::add_exact_filter($table, $where, $values, 'tipo_inmueble', $filters['tipo_inmueble']);
        self::add_exact_filter($table, $where, $values, 'categoria', $filters['categoria']);
        self::add_exact_filter($table, $where, $values, 'barrio', $filters['barrio']);
        self::add_serialized_choice_filter($table, $where, $values, 'promocionado_por', $filters['promocionado_por']);
        self::add_serialized_choice_filter($table, $where, $values, 'competencia', $filters['competencia']);
        self::add_exact_filter($table, $where, $values, 'origen', $filters['origen']);
        self::add_exact_filter($table, $where, $values, 'razones', $filters['razones']);
        return ['WHERE ' . implode(' AND ', $where), $values];
    }

    private static function normalize_date_filter(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    private static function active_filter_count(array $filters): int
    {
        return count(array_filter($filters, static function ($value): bool {
            return trim((string) $value) !== '';
        }));
    }

    private static function add_date_filter(array &$where, array &$values, string $column, string $operator, string $date, string $time): void
    {
        if (!$column || $date === '') {
            return;
        }

        $timestamp = strtotime($date . ' ' . $time);
        if (!$timestamp) {
            return;
        }

        $datetime = $date . ' ' . $time;
        $text_boundary = $operator === '>=' ? $date : $datetime;
        $where[] = "(({$column} REGEXP '^[0-9]+$' AND CAST({$column} AS UNSIGNED) {$operator} %d) OR ({$column} NOT REGEXP '^[0-9]+$' AND {$column} {$operator} %s))";
        $values[] = $timestamp;
        $values[] = $text_boundary;
    }

    private static function add_search_filter(string $table, array &$where, array &$values, string $value): void
    {
        global $wpdb;

        if ($value === '') {
            return;
        }

        $columns = [];
        foreach (['ticket', 'celular', 'barrio', 'direccion', 'referencia', 'contacto', 'resultado', 'razones'] as $logical) {
            $column = self::column_for($table, $logical);
            if ($column) {
                $columns[$column] = $column;
            }
        }

        if (!$columns) {
            return;
        }

        $clauses = [];
        $needle = '%' . $wpdb->esc_like($value) . '%';
        foreach ($columns as $column) {
            $clauses[] = "{$column} LIKE %s";
            $values[] = $needle;
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    private static function add_like_filter(string $table, array &$where, array &$values, string $logical, string $value): void
    {
        global $wpdb;

        if ($value === '') {
            return;
        }

        $column = self::column_for($table, $logical);
        if (!$column) {
            return;
        }

        $where[] = "{$column} LIKE %s";
        $values[] = '%' . $wpdb->esc_like($value) . '%';
    }

    private static function add_exact_filter(string $table, array &$where, array &$values, string $logical, string $value): void
    {
        if ($value === '') {
            return;
        }

        $column = self::column_for($table, $logical);
        if (!$column) {
            return;
        }

        $where[] = "LOWER(TRIM({$column})) = LOWER(TRIM(%s))";
        $values[] = $value;
    }

    private static function add_serialized_choice_filter(string $table, array &$where, array &$values, string $logical, string $value): void
    {
        global $wpdb;

        if ($value === '') {
            return;
        }

        $column = self::column_for($table, $logical);
        if (!$column) {
            return;
        }

        $choices = self::serialized_choice_values($value);
        if (!$choices) {
            self::add_exact_filter($table, $where, $values, $logical, $value);
            return;
        }

        $clauses = [];
        foreach ($choices as $choice) {
            $variants = [
                $choice,
                self::canonical_serialized_choice($choice),
                maybe_serialize(['', $choice]),
            ];
            foreach (array_values(array_unique(array_filter(array_map('trim', $variants), 'strlen'))) as $variant) {
                $clauses[] = "LOWER(TRIM({$column})) = LOWER(TRIM(%s))";
                $values[] = $variant;
            }

            $clauses[] = "{$column} LIKE %s";
            $values[] = '%' . $wpdb->esc_like(':"' . $choice . '";') . '%';
        }

        if (!$clauses) {
            $clauses[] = "LOWER(TRIM({$column})) = LOWER(TRIM(%s))";
            $values[] = $value;
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    private static function add_ticket_status_filter(string $table, array &$where, array &$values, string $value): void
    {
        if ($value === '') {
            return;
        }

        $column = self::column_for($table, 'tiene_ticket');
        if ($column) {
            self::add_exact_filter($table, $where, $values, 'tiene_ticket', $value);
            return;
        }

        $ticket_col = self::column_for($table, 'ticket');
        if (!$ticket_col) {
            return;
        }

        if (in_array(self::normalize_yes_no($value), ['si', 'yes', '1'], true)) {
            $where[] = "{$ticket_col} IS NOT NULL AND TRIM({$ticket_col}) <> ''";
            return;
        }

        if (in_array(self::normalize_yes_no($value), ['no', '0'], true)) {
            $where[] = "({$ticket_col} IS NULL OR TRIM({$ticket_col}) = '')";
        }
    }

    private static function normalize_yes_no(string $value): string
    {
        $normalized = strtolower(remove_accents(trim($value)));

        return $normalized === 'sí' ? 'si' : $normalized;
    }

    private static function column_for(string $table, string $logical): string
    {
        $map = [
            'ticket' => ['ticket', 'numero_de_ticket'],
            'celular' => ['celular', 'celular_precat', 'telefono'],
            'contactado' => ['contactado'],
            'merece_ticket' => ['merece_ticket'],
            'tiene_ticket' => ['tiene_ticket'],
            'seguimiento' => ['seguimiento', 'tuvo_seguimiento'],
            'ruta' => ['ruta', 'numero_ruta'],
            'tipo_inmueble' => ['tipo_inmueble', 'tipo_de_inmueble'],
            'categoria' => ['categoria', 'categoria_inmueble'],
            'barrio' => ['barrio', 'sector'],
            'direccion' => ['direccion', 'direccion_inmueble'],
            'referencia' => ['referencia', 'referencia_inmueble'],
            'contacto' => ['contacto', 'nombre_contacto', 'nombre_del_contacto'],
            'resultado' => ['resultado', 'resultado_observaciones_precat'],
            'promocionado_por' => ['promocionado_por', 'promocionado'],
            'competencia' => ['competencia', 'competencias', 'competencia_precat'],
            'origen' => ['origen'],
            'razones' => ['razones', 'razones_precap', 'razon'],
        ];

        return self::first_existing_column($table, $map[$logical] ?? [$logical]);
    }

    private static function row_value(string $table, array $row, array $candidates): string
    {
        $column = self::first_existing_column($table, $candidates);
        if (!$column || !array_key_exists($column, $row)) {
            return '';
        }

        return is_scalar($row[$column]) ? (string) $row[$column] : '';
    }

    private static function serialized_choice_options(array $values, string $selected): array
    {
        if ($selected !== '') {
            $values[] = $selected;
        }

        $options = [];
        foreach ($values as $value) {
            $raw = trim((string) $value);
            if ($raw === '') {
                continue;
            }

            foreach (self::serialized_choice_values($raw) as $choice) {
                if ($choice === '') {
                    continue;
                }

                $key = strtolower(remove_accents($choice));
                $options[$key] = [
                    'label' => $choice,
                    'value' => $choice,
                ];
            }
        }

        uasort($options, static function (array $first, array $second): int {
            return strcasecmp($first['label'], $second['label']);
        });

        return array_values($options);
    }

    private static function serialized_choice_label(string $raw): string
    {
        $values = self::serialized_choice_values($raw);

        return $values ? implode(' / ', $values) : '';
    }

    private static function canonical_serialized_choice(string $raw): string
    {
        $values = self::serialized_choice_values($raw);

        return maybe_serialize($values ?: ['']);
    }

    private static function serialized_choice_values(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $value = is_serialized($raw) ? maybe_unserialize($raw) : $raw;
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[,|]+/', (string) $value) ?: [];
        }

        $values = [];
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $item = trim(sanitize_text_field((string) $item));
            if ($item === '') {
                continue;
            }

            $key = strtolower(remove_accents($item));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $item;
        }

        return $values;
    }

    private static function distinct_values(string $table, string $column): array
    {
        global $wpdb;

        $cache_key = md5($table . '|' . $column);
        static $cache = [];
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $values = $wpdb->get_col("SELECT DISTINCT TRIM({$column}) FROM {$table} WHERE {$column} IS NOT NULL AND TRIM({$column}) <> '' ORDER BY TRIM({$column}) ASC LIMIT 300");
        $values = array_map('strval', $values ?: []);
        $values = array_values(array_unique(array_filter(array_map('trim', $values), 'strlen')));
        natcasesort($values);
        $cache[$cache_key] = array_values($values);

        return $cache[$cache_key];
    }

    private static function first_existing_column(string $table, array $candidates): string
    {
        $columns = self::table_columns($table);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function table_columns(string $table): array
    {
        global $wpdb;

        if (!isset(self::$schema_cache[$table])) {
            self::$schema_cache[$table] = $wpdb->get_col("DESCRIBE {$table}", 0) ?: [];
        }

        return self::$schema_cache[$table];
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    private static function table_name(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . $name;
    }

    private static function table_colspan(bool $is_control): int
    {
        return $is_control ? 9 : 8;
    }

    private static function preserve_query_args(array $exclude = []): void
    {
        foreach ($_GET as $key => $value) {
            if (in_array((string) $key, $exclude, true) || strpos((string) $key, 'precaptaciones_') === 0) {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr((string) $key),
                esc_attr(sanitize_text_field(wp_unslash($value)))
            );
        }
    }

    public static function export_csv(array $request): never
    {
        global $wpdb;
        $is_control = ($request['mode'] ?? '') === 'control';
        if ($is_control && !self::current_user_can_bulk_update()) wp_send_json_error(['message' => 'No tienes permiso para exportar el panel administrativo.'], 403);
        $table = self::table_name('jet_cct_precaptaciones');
        [$where, $values] = self::build_where($table, self::read_filters($request), $is_control);
        $sql = "SELECT * FROM {$table} {$where} ORDER BY _ID DESC";
        $statement = Module::db()->pdo()->query($values ? $wpdb->prepare($sql, $values) : $sql);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Precaptación', 'Funcionario', 'Barrio', 'Tipo de inmueble', 'Categoría', 'Resultado', 'Razón', 'Tarea'], ';', '"', '');
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $cells = [
                self::row_value($table, $row, ['_ID', 'id_precaptacion']),
                self::employee_name(self::row_value($table, $row, ['id_empleado', 'cct_author_id', 'captador_precat'])),
                self::row_value($table, $row, ['barrio', 'sector']),
                self::row_value($table, $row, ['tipo_inmueble', 'tipo_de_inmueble']),
                self::row_value($table, $row, ['categoria', 'categoria_inmueble']),
                self::display_text(self::row_value($table, $row, ['resultado'])),
                self::display_text(self::row_value($table, $row, ['razones', 'razones_precap', 'razon'])),
                self::row_value($table, $row, ['id_ticket_asignado', 'ticket_asignado', 'id_ticket', 'ticket', 'numero_de_ticket']),
            ];
            $cells = array_map(static fn(string $cell): string => preg_match('/^[=+@\-\t\r]/', $cell) ? "'" . $cell : $cell, $cells);
            fputcsv($stream, $cells, ';', '"', '');
        }
        rewind($stream);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="precaptaciones.csv"');
        header('Cache-Control: no-store');
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    private static function display_text(string $text): string
    {
        return str_replace(['Ticket creado', 'ticket creado', 'Tickets', 'tickets', 'Ticket', 'ticket'], ['Tarea creada', 'tarea creada', 'Tareas', 'tareas', 'Tarea', 'tarea'], $text);
    }

    public static function ajax_filtrar_precaptaciones(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ver las precaptaciones.'], 403);
        }

        check_ajax_referer('precaptaciones_filtrar', 'nonce');

        $request = wp_unslash($_POST);
        $mode = sanitize_key((string) ($request['mode'] ?? 'mis'));
        $is_control = in_array($mode, ['control', 'admin', 'administrador'], true);
        if ($is_control && !self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para ver el panel de control de precaptaciones.'], 403);
        }

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $per_page = max(1, min(200, absint($request['per_page'] ?? 50)));
        $page = max(1, absint($request['page'] ?? 1));
        $filters = self::read_filters($request);

        ob_start();
        self::render_results($table, $filters, $is_control, $per_page, $page);
        $html = (string) ob_get_clean();
        ob_start();
        self::render_metrics($table, $filters, $is_control);
        $metrics = (string) ob_get_clean();

        wp_send_json_success([
            'active_filters' => self::active_filter_count($filters),
            'html' => $html,
            'metrics' => $metrics,
        ]);
    }

    public static function ajax_actualizar_precaptacion(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para editar la precaptacion.'], 403);
        }

        check_ajax_referer('precaptaciones_actualizar', 'nonce');

        $request = wp_unslash($_POST);

        ob_start();
        self::handle_resultado_precaptacion($request, null);
        $message = trim(wp_strip_all_tags((string) ob_get_clean()));

        if ($message === '') {
            $message = 'Precaptacion actualizada correctamente.';
        }

        if (stripos($message, 'error') !== false || stripos($message, 'no se recibio') !== false) {
            wp_send_json_error(['message' => $message], 400);
        }

        $ticket_modal_id = '';
        $ticket_modal_html = '';
        if (sanitize_text_field($request['merece_ticket'] ?? '') === 'Si') {
            $id_precaptacion = absint($request['id_precaptacion'] ?? 0);
            $ticket_modal_id = 'precaptaciones-ticket-modal-' . $id_precaptacion;
            $table = self::table_name('jet_cct_precaptaciones');
            if ($id_precaptacion && self::table_exists($table)) {
                global $wpdb;

                $id_col = self::first_existing_column($table, ['_ID', 'id_precaptacion', 'id']);
                if ($id_col) {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$id_col} = %d LIMIT 1", $id_precaptacion), ARRAY_A);
                    if ($row) {
                        ob_start();
                        self::render_ticket_modal($table, $row);
                        $ticket_modal_html = (string) ob_get_clean();
                    }
                }
            }
        }

        wp_send_json_success([
            'message' => $message,
            'ticket_modal_html' => $ticket_modal_html,
            'ticket_modal_id' => $ticket_modal_id,
        ]);
    }

    public static function ajax_crear_ticket(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para crear la tarea.'], 403);
        }

        check_ajax_referer('precaptaciones_crear_ticket', 'nonce');

        global $wpdb;

        $request = wp_unslash($_POST);
        $precaptaciones_table = self::table_name('jet_cct_precaptaciones');
        $tickets_table = self::table_name('jet_cct_tickets');
        if (!self::table_exists($precaptaciones_table) || !self::table_exists($tickets_table)) {
            wp_send_json_error(['message' => 'No se encontraron las tablas requeridas para crear la tarea.'], 404);
        }

        $id_precaptacion = absint($request['id_precaptacion'] ?? 0);
        if (!$id_precaptacion) {
            wp_send_json_error(['message' => 'No se recibio la precaptacion para crear la tarea.'], 400);
        }

        $asignado = sanitize_text_field($request['asignado'] ?? $request['id_empleado'] ?? get_current_user_id());
        $assigned_funcionario = self::funcionario_data($asignado);
        $creator = get_userdata(get_current_user_id());
        $fecha = current_time('timestamp');
        $fecha_texto = current_time('mysql');
        $solicitante = sanitize_text_field($request['solicitante'] ?? '');
        $correo_solicitante = sanitize_email($request['correo_solicitante'] ?? '');
        $celular_solicitante = sanitize_text_field($request['celular_solicitante'] ?? '');
        $tema_ayuda = sanitize_text_field($request['tema_ayuda'] ?? 'Captacion');
        $asunto = sanitize_text_field($request['asunto'] ?? 'Tarea comercial');
        $descripcion = sanitize_textarea_field($request['descripcion'] ?? '');
        $id_inmueble = absint($request['id_inmueble'] ?? 0);
        $id_pph = absint($request['id_pph'] ?? 0);
        $pph = $id_pph ? $wpdb->get_row($wpdb->prepare('SELECT tarjeta_bienvenida, nombre FROM ' . self::table_name('jet_cct_club_pph') . ' WHERE _ID = %d', $id_pph), ARRAY_A) : [];

        $ticket_data = [
            'id_creador' => get_current_user_id(),
            'id_asignado' => $asignado,
            'asignado' => $asignado,
            'empleado' => $assigned_funcionario['nombre'] ?? (string) $asignado,
            'nombre_empleado' => $assigned_funcionario['nombre'] ?? (string) $asignado,
            'correo_empleado' => $assigned_funcionario['correo'] ?? '',
            'celular_empleado' => $assigned_funcionario['celular'] ?? '',
            'fecha' => $fecha,
            'fecha_creacion_ticket' => $fecha_texto,
            'solicitante' => $solicitante,
            'correo_solicitante' => $correo_solicitante,
            'celular_solicitante' => $celular_solicitante,
            'prioridad' => sanitize_text_field($request['prioridad'] ?? 'Prioridad comercial'),
            'tema_ayuda' => $tema_ayuda,
            'id_inmueble' => $id_inmueble,
            'tipo_inmueble' => sanitize_text_field($request['tipo_inmueble'] ?? ''),
            'destinacion' => sanitize_text_field($request['destinacion'] ?? ''),
            'uso' => sanitize_text_field($request['uso'] ?? ''),
            'medio' => sanitize_text_field($request['medio'] ?? ''),
            'precio' => sanitize_text_field($request['precio'] ?? ''),
            'presupuesto' => sanitize_text_field($request['presupuesto'] ?? ''),
            'factores' => sanitize_text_field($request['factores'] ?? ''),
            'asunto' => $asunto,
            'descripcion' => $descripcion,
            'id_empleado' => sanitize_text_field($request['id_empleado'] ?? $asignado),
            'sucursal' => get_user_meta(get_current_user_id(), 'sucursal-user', true),
            'id_pph' => $id_pph,
            'tarjeta_pph' => is_array($pph) ? (string) ($pph['tarjeta_bienvenida'] ?? '') : '',
            'nombre_pph' => is_array($pph) ? (string) ($pph['nombre'] ?? '') : '',
            'indicativo' => sanitize_text_field($request['indicativo'] ?? ''),
            'estado' => 'Nuevo',
            'estado_comercial' => 'Nuevo',
            'departamento' => 'Servicio al cliente',
            'tuvo_seguimiento' => 'No',
            'tuvo_reporte' => 'No',
            'creador_por' => 'Funcionario',
        ];
        if ($id_inmueble <= 0) {
            unset($ticket_data['id_inmueble']);
        }

        $ticket_id = self::insert_cct_row($tickets_table, $ticket_data);
        if (!$ticket_id) {
            wp_send_json_error(['message' => 'No se pudo crear la tarea: ' . $wpdb->last_error], 500);
        }

        self::registrar_historial_ticket($ticket_id, $ticket_data);
        self::actualizar_precaptacion_con_ticket($precaptaciones_table, $id_precaptacion, $ticket_id);
        self::enviar_correos_ticket($ticket_id, $ticket_data, $creator);

        $ticket_url = add_query_arg(['id_ticket' => (string) $ticket_id], 'https://sucasainmobiliaria.com.co/ticket/');
        wp_send_json_success([
            'message' => sprintf('Tarea #%s creada con exito.', number_format_i18n($ticket_id)),
            'ticket_id' => $ticket_id,
            'ticket_url' => $ticket_url,
        ]);
    }

    private static function insert_cct_row(string $table, array $data): int
    {
        global $wpdb;

        $columns = self::table_columns($table);
        $data = array_intersect_key($data, array_flip($columns));
        if (!$data) {
            return 0;
        }

        $formats = array_fill(0, count($data), '%s');
        $inserted = $wpdb->insert($table, $data, $formats);
        if ($inserted === false) {
            return 0;
        }

        if ($wpdb->insert_id) {
            return (int) $wpdb->insert_id;
        }

        if (in_array('_ID', $columns, true)) {
            return (int) $wpdb->get_var("SELECT MAX(_ID) FROM {$table}");
        }

        return 0;
    }

    private static function update_cct_row(string $table, array $data, array $where): bool
    {
        global $wpdb;

        $columns = self::table_columns($table);
        $data = array_intersect_key($data, array_flip($columns));
        $where = array_intersect_key($where, array_flip($columns));
        if (!$data || !$where) {
            return false;
        }

        $data_formats = array_fill(0, count($data), '%s');
        $where_formats = array_fill(0, count($where), '%s');

        return $wpdb->update($table, $data, $where, $data_formats, $where_formats) !== false;
    }

    private static function registrar_historial_ticket(int $ticket_id, array $ticket_data): void
    {
        $table = self::table_name('jet_cct_historial_del_inmueble');
        if ((int) ($ticket_data['id_inmueble'] ?? 0) <= 0 || !self::table_exists($table)) {
            return;
        }

        self::insert_cct_row($table, [
            'reporte_realizado_por_his' => $ticket_data['id_asignado'] ?? '',
            'fecha_his' => $ticket_data['fecha'] ?? current_time('timestamp'),
            'id_del_inmueble_his' => $ticket_data['id_inmueble'] ?? '',
            'observacion_his' => $ticket_data['asunto'] ?: 'Se ha creado una tarea comercial.',
            'id_inmueble' => $ticket_data['id_inmueble'] ?? '',
            'funcionario' => $ticket_data['nombre_empleado'] ?? '',
            'fecha' => $ticket_data['fecha'] ?? current_time('timestamp'),
            'observacion' => 'Se ha creado una tarea comercial.',
            'id_empleado' => $ticket_data['id_empleado'] ?? '',
            'id_ticket' => $ticket_id,
            'tipo_de_reporte_his' => 'Ticket',
            'tipo_reporte' => 'Ticket',
        ]);
    }

    private static function actualizar_precaptacion_con_ticket(string $table, int $id_precaptacion, int $ticket_id): void
    {
        self::update_cct_row(
            $table,
            [
                'id_ticket_asignado' => $ticket_id,
                'ticket_asignado' => $ticket_id,
                'id_ticket' => $ticket_id,
                'ticket' => $ticket_id,
                'numero_de_ticket' => $ticket_id,
                'tiene_ticket' => 'Si',
                'merece_ticket' => 'Si',
                'contactado' => 'Si',
                'razones' => 'Ticket creado',
                'razones_precap' => 'Ticket creado',
                'razon' => 'Ticket creado',
            ],
            ['_ID' => $id_precaptacion]
        );
    }

    private static function enviar_correos_ticket(int $ticket_id, array $ticket_data, $creator): void
    {
        $context = self::ticket_email_context($ticket_id, $ticket_data, $creator);
        $headers = self::ticket_email_headers();

        if (!empty($ticket_data['correo_empleado']) && is_email($ticket_data['correo_empleado'])) {
            wp_mail(
                $ticket_data['correo_empleado'],
                self::ticket_email_replace('Nueva tarea #%inserted_cct_tickets% asignado', $context),
                self::ticket_email_template_funcionario($context),
                $headers
            );
        }

        if (!empty($ticket_data['correo_solicitante']) && is_email($ticket_data['correo_solicitante'])) {
            $solicitante_headers = self::ticket_email_headers($ticket_data['correo_empleado'] ?? '');
            wp_mail(
                $ticket_data['correo_solicitante'],
                self::ticket_email_replace('Ticket #%inserted_cct_tickets% agendado', $context),
                self::ticket_email_template_solicitante($context),
                $solicitante_headers
            );
        }

        foreach (['sucasacorreos@gmail.com', 'gcorrearivera@gmail.com', 'sucasacomercial@gmail.com'] as $email) {
            wp_mail(
                $email,
                self::ticket_email_replace('Tarea comercial #%inserted_cct_tickets% creada', $context),
                self::ticket_email_template_admin($context),
                $headers
            );
        }
    }

    private static function ticket_email_headers(string $reply_to = ''): array
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: SUCASA INMOBILIARIA <sucasainfo@sucasainmobiliaria.com.co>',
        ];

        if ($reply_to && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        return $headers;
    }

    private static function ticket_email_context(int $ticket_id, array $ticket_data, $creator): array
    {
        $ticket_url = add_query_arg(['id_ticket' => (string) $ticket_id], 'https://sucasainmobiliaria.com.co/ticket/');
        $banner = (string) apply_filters('precaptaciones_ticket_email_banner', '');
        $revista = (string) apply_filters('precaptaciones_ticket_email_revista_url', 'https://sucasainmobiliaria.com.co/revista/');
        $brochure = (string) apply_filters('precaptaciones_ticket_email_brochure_url', 'https://sucasainmobiliaria.com.co/brochure-skc-sucasa-inmobiliaria/');

        return [
            'inserted_cct_tickets' => (string) $ticket_id,
            'ticket_url' => $ticket_url,
            'banner' => $banner,
            'link_revista_correo' => $revista,
            'brochure_url' => $brochure,
            'nombre_empleado' => (string) ($ticket_data['nombre_empleado'] ?? $ticket_data['empleado'] ?? ''),
            'celular_empleado' => (string) ($ticket_data['celular_empleado'] ?? ''),
            'solicitante' => (string) ($ticket_data['solicitante'] ?? ''),
            'tema_ayuda' => (string) ($ticket_data['tema_ayuda'] ?? 'Captacion'),
            'asunto' => (string) ($ticket_data['asunto'] ?? 'Tarea comercial'),
            'medio' => (string) ($ticket_data['medio'] ?? ''),
            'nombre_comercial' => (string) apply_filters('precaptaciones_ticket_email_nombre_comercial', 'Equipo comercial'),
            'celular_comercial' => (string) apply_filters('precaptaciones_ticket_email_celular_comercial', ''),
            'nombre_creador' => $creator && !empty($creator->display_name) ? (string) $creator->display_name : '',
        ];
    }

    private static function ticket_email_replace(string $content, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['%' . $key . '%'] = (string) $value;
        }

        return strtr($content, $replace);
    }

    private static function ticket_email_banner(array $context): string
    {
        if (empty($context['banner'])) {
            return '';
        }

        return sprintf(
            '<tr><td><img src="%s" alt="Banner SKC" style="width:100%%; height:auto; display:block;"></td></tr>',
            esc_url($context['banner'])
        );
    }

    private static function ticket_email_button(string $url, string $label): string
    {
        return sprintf(
            '<a href="%s" style="background-color:#404041; padding:15px 20px; border-radius:5px; text-decoration:none; color:white; font-weight:700; display:inline-block; margin:15px 0;">%s</a>',
            esc_url($url),
            esc_html($label)
        );
    }

    private static function ticket_email_shell(string $title, string $content, array $context): string
    {
        return '<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>' . esc_html($title) . '</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family:Arial, sans-serif;">
  <table align="center" width="800" cellpadding="0" cellspacing="0" style="border:3px solid #ebecec; background:#ffffff;">
    ' . self::ticket_email_banner($context) . '
    ' . $content . '
    <tr>
      <td style="background:#f59120; text-align:center; font-weight:600; font-size:18px; padding:18px; color:white;">
        Una empresa para lograr sus suenos.
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    private static function ticket_email_links(array $context, bool $brochure_with_utm = false): string
    {
        $brochure_url = $context['brochure_url'];
        if ($brochure_with_utm) {
            $brochure_url = add_query_arg(
                [
                    'utm_source' => 'Notificaciones pagina web',
                    'utm_medium' => 'Email',
                ],
                $brochure_url
            );
        }

        return '<tr>
      <td style="padding:20px; text-align:center; color:#061d49;">
        <p style="font-weight:500; margin:10px 0;">Si deseas ver toda la informacion de la tarea, ingresa por este enlace:</p>
        ' . self::ticket_email_button($context['ticket_url'], 'Ver tarea') . '
        <p style="font-weight:500; margin:20px 0 10px;">Descubre quienes somos y todo lo que podemos hacer por ti.</p>
        ' . self::ticket_email_button($brochure_url, 'Ver brochure') . '
        <p style="font-weight:500; margin:20px 0 10px;">Descubre nuestra revista digital. Mantente al dia con las mejores oportunidades inmobiliarias, tips exclusivos y tendencias del mercado.</p>
        ' . self::ticket_email_button($context['link_revista_correo'], 'Ver revista') . '
      </td>
    </tr>';
    }

    private static function ticket_email_template_funcionario(array $context): string
    {
        $content = '<tr>
      <td style="padding:20px; text-align:center; color:#061d49;">
        <h3 style="font-size:16px; margin:0 0 20px;">Estimado/a ' . esc_html($context['nombre_empleado']) . '</h3>
        <p style="font-weight:500; margin:10px 0;">
          Se te ha asignado una nueva tarea comercial de <b>' . esc_html($context['tema_ayuda']) . '</b> que trata de <b>' . esc_html($context['asunto']) . '</b>
        </p>
      </td>
    </tr>' . self::ticket_email_links($context);

        return self::ticket_email_shell('Nueva tarea comercial', $content, $context);
    }

    private static function ticket_email_template_solicitante(array $context): string
    {
        $content = '<tr>
      <td style="padding:20px; text-align:center; color:#061d49;">
        <h3 style="font-size:16px; margin:0 0 20px;">Estimado/a ' . esc_html($context['solicitante']) . '</h3>
        <p style="font-weight:500; margin:10px 0;">
          Hemos registrado su solicitud de <b>' . esc_html($context['tema_ayuda']) . '</b>, asignandola a <b>' . esc_html($context['nombre_empleado'] . ' - ' . $context['celular_empleado']) . '</b>.
          Su labor sera evaluada para garantizar un servicio optimo. Si no cumple sus expectativas, puede responder por esta misma via.
        </p>
        <p style="font-weight:500; margin:10px 0;">
          Cordialmente:<br><br><b>' . esc_html($context['nombre_comercial']) . '</b> - <b>Coordinador(a) comercial</b>' . ($context['celular_comercial'] !== '' ? ' - <b>' . esc_html($context['celular_comercial']) . '</b>' : '') . '
        </p>
      </td>
    </tr>' . self::ticket_email_links($context, true);

        return self::ticket_email_shell('Nueva tarea agendada', $content, $context);
    }

    private static function ticket_email_template_admin(array $context): string
    {
        $content = '<tr>
      <td style="padding:20px; text-align:center; color:#061d49;">
        <h3 style="font-size:16px; margin:0 0 20px;">Estimado administrador</h3>
        <p style="font-weight:500; margin:10px 0;">
          Se ha agregado una nueva tarea comercial de <b>' . esc_html($context['tema_ayuda']) . '</b> desde el medio <b>' . esc_html($context['medio']) . '</b>.
          La cual fue asignada a <b>' . esc_html($context['nombre_empleado']) . '</b>.
        </p>
      </td>
    </tr>' . self::ticket_email_links($context);

        return self::ticket_email_shell('Nueva tarea comercial', $content, $context);
    }

    public static function ajax_marcar_duplicada(): void
    {
        check_ajax_referer('precaptaciones_marcar_duplicada', 'nonce');

        self::marcar_precaptacion_estado_admin(
            'Precaptacion duplicada',
            'revisando las fotografias y la informacion esta precaptacion ya fue ingresada anteriormente en el sistema',
            'Precaptacion marcada como duplicada.',
            'duplicada'
        );
    }

    public static function ajax_marcar_sin_informacion(): void
    {
        check_ajax_referer('precaptaciones_marcar_sin_informacion', 'nonce');

        self::marcar_precaptacion_estado_admin(
            'Precaptacion sin informacion',
            'No se cuenta con informacion suficiente para continuar la gestion de esta precaptacion.',
            'Precaptacion marcada como sin informacion.',
            'sin informacion'
        );
    }

    private static function marcar_precaptacion_estado_admin(string $razon, string $resultado, string $success_message, string $label): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ejecutar esta accion.'], 403);
        }

        if (!self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para marcar precaptaciones como ' . $label . '.'], 403);
        }

        global $wpdb;

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $id = absint($_POST['id_precaptacion'] ?? 0);
        $id_col = self::first_existing_column($table, ['_ID', 'id_precaptacion', 'id']);
        $contactado_col = self::column_for($table, 'contactado');
        $razones_col = self::column_for($table, 'razones');
        $resultado_col = self::column_for($table, 'resultado');

        if (!$id || !$id_col || !$contactado_col || !$razones_col || !$resultado_col) {
            wp_send_json_error(['message' => 'Faltan datos o columnas requeridas para marcar ' . $label . '.'], 400);
        }

        $updated = $wpdb->update(
            $table,
            [
                $contactado_col => 'Si',
                $razones_col => $razon,
                $resultado_col => $resultado,
            ],
            [$id_col => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'Error al marcar ' . $label . ': ' . $wpdb->last_error], 500);
        }

        wp_send_json_success([
            'message' => $success_message,
            'updated' => (int) $updated,
        ]);
    }

    public static function ajax_marcar_antiguas_no_contactadas(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ejecutar esta accion.'], 403);
        }

        if (!self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para ejecutar esta actualizacion masiva.'], 403);
        }

        check_ajax_referer('precaptaciones_marcar_antiguas_no_contactadas', 'nonce');

        global $wpdb;

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $parts = self::old_not_contacted_query_parts($table);
        $razones_col = self::first_existing_column($table, ['razones', 'razones_precap', 'razon']);
        $resultado_col = self::first_existing_column($table, ['resultado']);

        if (!$parts || !$razones_col || !$resultado_col) {
            wp_send_json_error([
                'message' => 'Faltan columnas requeridas: contactado, fecha, razones o resultado.',
            ], 400);
        }

        $reason = 'No contestó';
        $result = 'Se le deja mensaje de WhatsApp ofreciendole nuestros servicios';

        $before = self::count_precaptaciones_anteriores_no_contactadas($table);
        if ($before <= 0) {
            wp_send_json_success([
                'updated' => 0,
                'remaining' => 0,
                'message' => 'No hay precaptaciones antiguas pendientes por marcar.',
            ]);
        }

        $sql = "UPDATE {$table}
                SET {$parts['contactado_col']} = %s,
                    {$razones_col} = %s,
                    {$resultado_col} = %s
                WHERE {$parts['where']}";

        $updated = $wpdb->query($wpdb->prepare(
            $sql,
            array_merge(['Si', $reason, $result], $parts['values'])
        ));

        if ($updated === false) {
            wp_send_json_error([
                'message' => 'Error al actualizar precaptaciones: ' . $wpdb->last_error,
            ], 500);
        }

        $remaining = self::count_precaptaciones_anteriores_no_contactadas($table);

        wp_send_json_success([
            'updated' => (int) $updated,
            'remaining' => $remaining,
            'message' => sprintf(
                'Se actualizaron %s precaptaciones. Quedan %s pendientes.',
                number_format_i18n((int) $updated),
                number_format_i18n($remaining)
            ),
        ]);
    }

    public static function ajax_normalizar_promocionado_por(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ejecutar esta accion.'], 403);
        }

        if (!self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para ejecutar esta actualizacion masiva.'], 403);
        }

        check_ajax_referer('precaptaciones_normalizar_promocionado_por', 'nonce');

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $before = self::count_promocionado_por_por_normalizar($table);
        if ($before <= 0) {
            wp_send_json_success([
                'updated' => 0,
                'remaining' => 0,
                'message' => 'No hay valores de promocionado por pendientes por normalizar.',
            ]);
        }

        $result = self::normalize_promocionado_por_values($table);
        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 500);
        }

        wp_send_json_success([
            'updated' => (int) $result['updated'],
            'remaining' => (int) $result['remaining'],
            'message' => sprintf(
                'Se normalizaron %s valores de promocionado por. Quedan %s pendientes.',
                number_format_i18n((int) $result['updated']),
                number_format_i18n((int) $result['remaining'])
            ),
        ]);
    }

    public static function ajax_normalizar_competencia(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ejecutar esta accion.'], 403);
        }

        if (!self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para ejecutar esta actualizacion masiva.'], 403);
        }

        check_ajax_referer('precaptaciones_normalizar_competencia', 'nonce');

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $before = self::count_competencia_por_normalizar($table);
        if ($before <= 0) {
            wp_send_json_success([
                'updated' => 0,
                'remaining' => 0,
                'message' => 'No hay valores de competencia pendientes por normalizar.',
            ]);
        }

        $result = self::normalize_competencia_values($table);
        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 500);
        }

        wp_send_json_success([
            'updated' => (int) $result['updated'],
            'remaining' => (int) $result['remaining'],
            'message' => sprintf(
                'Se normalizaron %s valores de competencia. Quedan %s pendientes.',
                number_format_i18n((int) $result['updated']),
                number_format_i18n((int) $result['remaining'])
            ),
        ]);
    }

    public static function ajax_normalizar_razon_creado(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para ejecutar esta accion.'], 403);
        }

        if (!self::current_user_can_bulk_update()) {
            wp_send_json_error(['message' => 'No tienes permisos para ejecutar esta actualizacion masiva.'], 403);
        }

        check_ajax_referer('precaptaciones_normalizar_razon_creado', 'nonce');

        $table = self::table_name('jet_cct_precaptaciones');
        if (!self::table_exists($table)) {
            wp_send_json_error(['message' => 'No se encontro la tabla de precaptaciones.'], 404);
        }

        $before = self::count_razon_creado_por_normalizar($table);
        if ($before <= 0) {
            wp_send_json_success([
                'updated' => 0,
                'remaining' => 0,
                'message' => 'No hay razones Creado pendientes por cambiar.',
            ]);
        }

        $result = self::normalizar_razon_creado($table);
        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 500);
        }

        wp_send_json_success([
            'updated' => (int) $result['updated'],
            'remaining' => (int) $result['remaining'],
            'message' => sprintf(
                'Se cambiaron %s razones de Creado a Tarea creada. Quedan %s pendientes.',
                number_format_i18n((int) $result['updated']),
                number_format_i18n((int) $result['remaining'])
            ),
        ]);
    }

    private static function current_user_can_bulk_update(): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return false;
    }

    public static function handle_resultado_precaptacion($request, $handler): void
    {
        global $wpdb;

        $id_precaptacion = absint($request['id_precaptacion'] ?? 0);
        $id_pph = absint($request['id_pph'] ?? 0);
        $id_empleado = absint($request['id_empleado'] ?? get_current_user_id());
        $fecha = absint($request['fecha'] ?? time());
        $contacto = sanitize_text_field($request['contacto'] ?? '');
        $correo = sanitize_email($request['correo'] ?? '');
        $celular = sanitize_text_field($request['celular'] ?? '');
        $razones = sanitize_text_field($request['razones'] ?? '');
        $resultado = sanitize_textarea_field($request['resultado'] ?? '');
        $merece_ticket = sanitize_text_field($request['merece_ticket'] ?? '');
        $origen = sanitize_text_field($request['origen'] ?? '');
        $bandera = sanitize_text_field($request['bandera'] ?? '');
        $seguir_llamando = $merece_ticket === 'Seguir llamando';
        $bandera_final = $bandera;
        $tarjeta_pph = '';
        $puntos_registrados = 0;

        if (!$id_precaptacion) {
            echo 'No se recibio el ID de la precaptacion.';
            return;
        }

        if ($id_pph > 0) {
            $pph = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT total_puntos, tarjeta_bienvenida, nombre FROM ' . self::table_name('jet_cct_club_pph') . ' WHERE _ID = %d',
                    $id_pph
                )
            );
            $params = $wpdb->get_row('SELECT cantidad_inmueble_no_efectivo, porcentaje_plata, porcentaje_oro, porcentaje_platino, limite_bronce, limite_plata, limite_oro FROM ' . self::table_name('jet_cct_puntos_pph') . ' LIMIT 1');

            if ($pph) {
                $tarjeta_pph = (string) $pph->tarjeta_bienvenida;
            }

            if ($pph && $params && $origen === 'Club PPH' && $bandera === 'No' && $merece_ticket === 'No') {
                $total_puntos = is_numeric($pph->total_puntos) ? (float) $pph->total_puntos : 0.0;
                $membresia = self::determinar_membresia(
                    $total_puntos,
                    (float) $params->limite_bronce,
                    (float) $params->limite_plata,
                    (float) $params->limite_oro
                );
                $puntos = self::determinar_cantidad_puntos(
                    (float) $params->cantidad_inmueble_no_efectivo,
                    $membresia,
                    (float) $params->porcentaje_plata,
                    (float) $params->porcentaje_oro,
                    (float) $params->porcentaje_platino
                );

                $wpdb->update(
                    self::table_name('jet_cct_club_pph'),
                    [
                        'total_puntos' => $total_puntos + $puntos,
                        'membresia' => $membresia,
                        'fecha_actividad' => $fecha,
                    ],
                    ['_ID' => $id_pph]
                );

                $historial_insertado = $wpdb->insert(
                    self::table_name('jet_cct_historial_puntos_pph'),
                    [
                        'cct_author_id' => $id_empleado,
                        'fecha' => $fecha,
                        'tipo_punto' => 'Inmueble',
                        'cantidad' => $puntos,
                        'efectivo' => $merece_ticket,
                        'id_pph' => $id_pph,
                        'tarjeta_pph' => $tarjeta_pph,
                        'nombre' => (string) $pph->nombre,
                    ]
                );

                if ($historial_insertado !== false) {
                    $puntos_registrados = $puntos;
                }

                $bandera_final = 'Si';
            }
        }

        $updated = $wpdb->update(
            self::table_name('jet_cct_precaptaciones'),
            [
                'id_precaptacion' => $id_precaptacion,
                'contacto' => $contacto,
                'correo' => $correo,
                'celular' => $celular,
                'razones' => $razones,
                'resultado' => $resultado,
                'merece_ticket' => $merece_ticket,
                'tarjeta_pph' => $tarjeta_pph,
                'bandera' => $bandera_final,
                'contactado' => 'Si',
                'tiene_ticket' => 'No',
            ],
            ['_ID' => $id_precaptacion]
        );

        if ($updated === false) {
            echo 'Error al actualizar la precaptacion: ' . esc_html($wpdb->last_error);
            return;
        }

        if ($seguir_llamando) {
            echo 'Precaptacion guardada para seguir llamando. Se marco como contactada y no se registraron puntos PPH.';
            return;
        }

        if ($puntos_registrados > 0) {
            echo sprintf('Precaptacion actualizada correctamente. Se registraron %s puntos PPH.', number_format_i18n($puntos_registrados));
            return;
        }

        echo 'Precaptacion actualizada correctamente.';
    }

    private static function determinar_membresia(float $total_puntos, float $limite_bronce, float $limite_plata, float $limite_oro): string
    {
        if ($total_puntos < $limite_bronce) {
            return 'Bronce';
        }
        if ($total_puntos < $limite_plata) {
            return 'Plata';
        }
        if ($total_puntos < $limite_oro) {
            return 'Oro';
        }

        return 'Platino';
    }

    private static function determinar_cantidad_puntos(
        float $puntos_base,
        string $membresia,
        float $porcentaje_plata,
        float $porcentaje_oro,
        float $porcentaje_platino
    ): int {
        if ($membresia === 'Plata') {
            return (int) ceil($puntos_base * $porcentaje_plata);
        }
        if ($membresia === 'Oro') {
            return (int) ceil($puntos_base * $porcentaje_oro);
        }
        if ($membresia === 'Platino') {
            return (int) ceil($puntos_base * $porcentaje_platino);
        }

        return (int) ceil($puntos_base);
    }

    private static function print_assets(): void
    {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .precaptaciones {
                --precaptaciones-azul: #061d49;
                --precaptaciones-amarillo: #ffc23d;
                --precaptaciones-borde: #e2e8f0;
                --precaptaciones-superficie: #ffffff;
                --precaptaciones-fondo-suave: #f8fafc;
                color: #0f172a;
                font-family: inherit;
                width: 100%;
            }
            .precaptaciones-alerta {
                background: #fff3cd;
                border-left: 4px solid #ffc23d;
                color: #061d49;
                padding: 12px 14px;
            }
            .precaptaciones__filtros {
                background: var(--precaptaciones-superficie);
                border: 1px solid var(--precaptaciones-borde);
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
                margin: 0 0 28px;
                overflow: hidden;
                padding: 0;
            }
            .precaptaciones__filtros-titulo {
                background: linear-gradient(135deg, #ffffff 0%, #f8fafc 58%, #fff8df 100%);
                border-bottom: 1px solid var(--precaptaciones-borde);
                margin: 0;
                padding: 22px 28px;
            }
            .precaptaciones__filtros-eyebrow {
                color: var(--precaptaciones-amarillo);
                font-size: 13px;
                font-weight: 950;
                letter-spacing: 0;
                line-height: 1;
                margin: 0 0 8px;
                text-transform: uppercase;
            }
            .precaptaciones__filtros-heading {
                color: var(--precaptaciones-azul);
                font-size: 30px;
                font-weight: 950;
                line-height: 1.12;
                margin: 0;
            }
            .precaptaciones__filtros-subtitle {
                color: #475569;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.35;
                margin: 7px 0 0;
            }
            .precaptaciones__filtros-activos {
                align-items: center;
                background: #fff8df;
                border: 1px solid #ffe29a;
                border-radius: 999px;
                color: var(--precaptaciones-azul);
                display: inline-flex;
                font-size: 13px;
                font-weight: 900;
                line-height: 1;
                min-height: 32px;
                padding: 8px 12px;
                width: fit-content;
            }
            .precaptaciones__filtros-grid {
                display: grid;
                gap: 16px;
                grid-template-columns: repeat(4, minmax(170px, 1fr));
                padding: 20px;
            }
            .precaptaciones__campo--wide {
                grid-column: 1 / -1;
            }
            .precaptaciones__campo,
            .precaptaciones__grupo {
                border: 0;
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin: 0;
                min-width: 0;
                padding: 0;
            }
            .precaptaciones__campo span,
            .precaptaciones__grupo legend {
                color: #1e293b;
                font-size: 14px;
                font-weight: 700;
                line-height: 1.35;
                margin-bottom: 8px;
                padding: 0;
                text-align: left;
            }
            .precaptaciones__control,
            .precaptaciones input,
            .precaptaciones select {
                background: #fff;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                color: #0f172a;
                font-size: 15px;
                min-height: 44px;
                padding: 9px 12px;
                transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease;
                width: 100%;
            }
            .precaptaciones__control::placeholder,
            .precaptaciones input::placeholder {
                color: #94a3b8;
            }
            .precaptaciones__control:focus,
            .precaptaciones input:focus,
            .precaptaciones select:focus {
                border-color: var(--precaptaciones-azul);
                box-shadow: 0 0 0 4px rgba(6, 29, 73, .12);
                outline: none;
            }
            .precaptaciones__control[readonly],
            .precaptaciones input[readonly],
            .precaptaciones textarea[readonly] {
                background: #f8fafc;
                color: #475569;
                cursor: default;
            }
            .precaptaciones__fecha {
                display: grid;
                gap: 8px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .precaptaciones__acciones-filtro {
                align-items: center;
                background: var(--precaptaciones-fondo-suave);
                border-top: 1px solid var(--precaptaciones-borde);
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 0;
                padding: 16px 20px;
            }
            .precaptaciones__acciones-filtro button,
            .precaptaciones__acciones-filtro a {
                background: var(--precaptaciones-azul);
                border: 0;
                border-radius: 8px;
                color: #fff;
                cursor: pointer;
                display: inline-flex;
                font-size: 14px;
                font-weight: 800;
                justify-content: center;
                line-height: 1;
                min-height: 44px;
                padding: 12px 20px;
                text-decoration: none;
            }
            .precaptaciones__acciones-filtro a {
                background: #fff;
                border: 1px solid #cbd5e1;
                color: #334155;
            }
            .precaptaciones__acciones-filtro .precaptaciones__boton-limpiar {
                background: #ffffff;
                border: 1px solid #94a3b8;
                color: var(--precaptaciones-azul);
                font-weight: 900;
                min-width: 154px;
            }
            .precaptaciones__acciones-filtro .precaptaciones__boton-limpiar:hover,
            .precaptaciones__acciones-filtro .precaptaciones__boton-limpiar:focus {
                background: #fff8df;
                border-color: var(--precaptaciones-amarillo);
                color: var(--precaptaciones-azul);
            }
            .precaptaciones__contador {
                color: #0f172a;
            }
            .precaptaciones__estado {
                border-radius: 8px;
                display: none;
                font-size: 13px;
                font-weight: 800;
                margin: -12px 0 16px;
                padding: 10px 12px;
            }
            .precaptaciones__estado.is-loading,
            .precaptaciones__estado.is-error {
                display: block;
            }
            .precaptaciones__estado.is-loading {
                background: #eff6ff;
                color: #1d4ed8;
            }
            .precaptaciones__estado.is-error {
                background: #fef2f2;
                color: #b91c1c;
            }
            .precaptaciones.is-loading [data-precaptaciones-results] {
                opacity: .62;
                pointer-events: none;
            }
            .precaptaciones__bulk-group {
                align-items: flex-start;
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                justify-content: flex-end;
            }
            .precaptaciones__bulk {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .precaptaciones__bulk-button {
                align-items: center;
                background: #061d49;
                border: 0;
                border-radius: 8px;
                color: #fff;
                cursor: pointer;
                display: inline-flex;
                gap: 8px;
                font-size: 14px;
                font-weight: 900;
                min-height: 44px;
                padding: 10px 14px;
                transition: background-color .16s ease, box-shadow .16s ease, opacity .16s ease;
            }
            .precaptaciones__bulk-button:hover {
                background: #0a2b6f;
            }
            .precaptaciones__bulk-button:focus {
                box-shadow: 0 0 0 4px rgba(6, 29, 73, .18);
                outline: none;
            }
            .precaptaciones__bulk-button:disabled {
                cursor: not-allowed;
                opacity: .52;
            }
            .precaptaciones__bulk-button svg {
                height: 17px;
                width: 17px;
            }
            .precaptaciones__bulk-count {
                color: #475569;
                font-size: 13px;
                font-weight: 800;
            }
            .precaptaciones__bulk-message {
                border-radius: 8px;
                display: none;
                font-size: 13px;
                font-weight: 800;
                padding: 8px 10px;
            }
            .precaptaciones__bulk-message.is-success {
                background: #ecfdf5;
                color: #047857;
                display: inline-flex;
            }
            .precaptaciones__bulk-message.is-error {
                background: #fef2f2;
                color: #b91c1c;
                display: inline-flex;
            }
            .precaptaciones__tabla-wrap {
                border: 1px solid var(--precaptaciones-borde);
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
                overflow-x: auto;
                width: 100%;
            }
            .precaptaciones__tabla {
                border-collapse: collapse;
                color: #0f172a;
                font-size: 14px;
                min-width: 920px;
                table-layout: fixed;
                width: 100%;
            }
            .precaptaciones__tabla th {
                background: var(--precaptaciones-azul);
                color: #fff;
                font-weight: 800;
                padding: 13px 12px;
                text-align: left;
                vertical-align: middle;
            }
            .precaptaciones__tabla td {
                background: #fff;
                border-bottom: 1px solid var(--precaptaciones-borde);
                border-right: 1px solid #eef2f7;
                padding: 12px;
                text-align: center;
                vertical-align: middle;
                word-break: break-word;
            }
            .precaptaciones__tabla tbody tr:nth-child(even) td {
                background: #f8fafc;
            }
            .precaptaciones__tabla tbody tr:hover td {
                background: #fff8df;
            }
            .precaptaciones__tabla th:nth-child(1),
            .precaptaciones__tabla td:nth-child(1) {
                width: 138px;
            }
            .precaptaciones__tabla th:nth-child(2),
            .precaptaciones__tabla td:nth-child(2) {
                width: 130px;
            }
            .precaptaciones__acciones {
                align-items: center;
                display: flex;
                flex-direction: column;
                gap: 8px;
                justify-content: center;
                text-align: center;
            }
            .precaptaciones__detalle,
            .precaptaciones__editar,
            .precaptaciones__duplicada,
            .precaptaciones__sin-informacion {
                align-items: center;
                border: 0;
                border-radius: 8px;
                cursor: pointer;
                display: inline-flex;
                gap: 8px;
                font-size: 12px;
                font-weight: 800;
                min-height: 36px;
                justify-content: center;
                line-height: 1.12;
                max-width: 112px;
                padding: 8px 10px;
                text-decoration: none;
                transition: background-color .16s ease, box-shadow .16s ease;
                width: 100%;
                white-space: normal;
                word-break: normal;
            }
            .precaptaciones__detalle {
                background: #061d49;
                color: #fff;
            }
            .precaptaciones__detalle:hover {
                background: #0a2b6f;
            }
            .precaptaciones__detalle:focus {
                box-shadow: 0 0 0 4px rgba(6, 29, 73, .2);
                outline: none;
            }
            .precaptaciones__editar {
                background: var(--precaptaciones-amarillo);
                color: var(--precaptaciones-azul);
            }
            .precaptaciones__editar:hover {
                background: #ffd36d;
            }
            .precaptaciones__editar:focus {
                box-shadow: 0 0 0 4px rgba(255, 194, 61, .45);
                outline: none;
            }
            .precaptaciones__duplicada {
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
            }
            .precaptaciones__duplicada:hover {
                background: #fee2e2;
            }
            .precaptaciones__duplicada:focus {
                box-shadow: 0 0 0 4px rgba(220, 38, 38, .16);
                outline: none;
            }
            .precaptaciones__sin-informacion {
                background: #f8fafc;
                border: 1px solid #cbd5e1;
                color: #334155;
                flex-direction: column;
                gap: 1px;
                min-height: 42px;
            }
            .precaptaciones__sin-informacion:hover {
                background: #e2e8f0;
            }
            .precaptaciones__sin-informacion:focus {
                box-shadow: 0 0 0 4px rgba(100, 116, 139, .2);
                outline: none;
            }
            .precaptaciones__evidencia {
                border-radius: 8px;
                display: block;
                height: 86px;
                margin: 0 auto;
                object-fit: cover;
                width: 108px;
            }
            .precaptaciones__evidencia-boton {
                background: transparent;
                border: 0;
                cursor: zoom-in;
                display: inline-block;
                padding: 0;
                position: relative;
            }
            .precaptaciones__evidencia-boton:focus {
                border-radius: 8px;
                box-shadow: 0 0 0 4px rgba(6, 29, 73, .16);
                outline: none;
            }
            .precaptaciones__evidencia-cuenta {
                align-items: center;
                background: rgba(6, 29, 73, .92);
                border-radius: 999px;
                bottom: 8px;
                color: #fff;
                display: inline-flex;
                font-size: 13px;
                font-weight: 900;
                justify-content: center;
                min-height: 28px;
                min-width: 34px;
                padding: 5px 8px;
                position: absolute;
                right: 8px;
            }
            .precaptaciones__sin-evidencia {
                color: #64748b;
                display: inline-block;
                font-size: 12px;
                font-weight: 800;
                line-height: 1.2;
            }
            .precaptaciones__ticket-boton {
                align-items: center;
                background: #061d49;
                border-radius: 8px;
                color: #fff !important;
                display: inline-flex;
                font-size: 13px;
                font-weight: 900;
                justify-content: center;
                min-height: 36px;
                padding: 9px 12px;
                text-decoration: none !important;
                white-space: nowrap;
            }
            .precaptaciones__ticket-boton:hover,
            .precaptaciones__ticket-boton:focus {
                background: #0a2b6f;
                color: #fff !important;
            }
            .precaptaciones-lightbox[hidden] {
                display: none;
            }
            .precaptaciones-lightbox {
                align-items: center;
                background: rgba(15, 23, 42, .86);
                display: flex;
                inset: 0;
                justify-content: center;
                padding: 22px;
                position: fixed;
                z-index: 100000;
            }
            .precaptaciones-lightbox__panel {
                align-items: center;
                display: grid;
                gap: 14px;
                grid-template-columns: 48px minmax(0, 1fr) 48px;
                max-width: min(1100px, 100%);
                width: 100%;
            }
            .precaptaciones-lightbox__figure {
                margin: 0;
                min-width: 0;
                text-align: center;
            }
            .precaptaciones-lightbox__image {
                background: #0f172a;
                border-radius: 8px;
                box-shadow: 0 24px 70px rgba(0, 0, 0, .35);
                max-height: calc(100vh - 140px);
                max-width: 100%;
                object-fit: contain;
            }
            .precaptaciones-lightbox__count {
                color: #fff;
                font-size: 14px;
                font-weight: 800;
                margin-top: 10px;
            }
            .precaptaciones-lightbox__close,
            .precaptaciones-lightbox__nav {
                align-items: center;
                background: #fff;
                border: 0;
                border-radius: 8px;
                color: #061d49;
                cursor: pointer;
                display: inline-flex;
                font-size: 24px;
                font-weight: 900;
                height: 48px;
                justify-content: center;
                width: 48px;
            }
            .precaptaciones-lightbox__close {
                position: absolute;
                right: 18px;
                top: 18px;
            }
            .precaptaciones-lightbox__nav:disabled {
                cursor: default;
                opacity: .38;
            }
            .precaptaciones-detalle {
                display: grid;
                gap: 14px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                margin: 0;
                padding: 22px;
            }
            .precaptaciones-detalle__item {
                background: #f8fafc;
                border: 1px solid var(--precaptaciones-borde);
                border-radius: 8px;
                margin: 0;
                min-width: 0;
                padding: 12px;
            }
            .precaptaciones-detalle__item--wide {
                grid-column: 1 / -1;
            }
            .precaptaciones-detalle dt {
                color: #64748b;
                font-size: 12px;
                font-weight: 900;
                margin: 0 0 5px;
                text-transform: uppercase;
            }
            .precaptaciones-detalle dd {
                color: #0f172a;
                font-size: 14px;
                font-weight: 700;
                line-height: 1.45;
                margin: 0;
                overflow-wrap: anywhere;
            }
            .precaptaciones__vacio {
                font-weight: 700;
                padding: 28px;
            }
            .precaptaciones__paginacion {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                justify-content: center;
                margin-top: 20px;
            }
            .precaptaciones__paginacion button {
                background: #fff;
                border: 1px solid var(--precaptaciones-borde);
                border-radius: 8px;
                color: #334155;
                cursor: pointer;
                font-weight: 800;
                padding: 8px 11px;
                text-decoration: none;
            }
            .precaptaciones__paginacion button.is-active {
                background: var(--precaptaciones-azul);
                color: #fff;
            }
            .precaptaciones-precap-modal[hidden] {
                display: none;
            }
            .precaptaciones-precap-modal {
                align-items: center;
                display: flex;
                inset: 0;
                justify-content: center;
                padding: 18px;
                position: fixed;
                z-index: 99999;
            }
            body.precaptaciones-precap-modal-open {
                overflow: hidden;
            }
            .precaptaciones-precap-modal__backdrop {
                background: rgba(15, 23, 42, .62);
                inset: 0;
                position: absolute;
            }
            .precaptaciones-precap-modal__panel {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 24px 80px rgba(15, 23, 42, .28);
                max-height: min(760px, calc(100vh - 36px));
                max-width: 880px;
                overflow: auto;
                position: relative;
                width: min(880px, 100%);
            }
            .precaptaciones-precap-modal__header {
                align-items: flex-start;
                background: #f8fafc;
                border-bottom: 1px solid var(--precaptaciones-borde);
                display: flex;
                gap: 16px;
                justify-content: space-between;
                padding: 18px 22px;
            }
            .precaptaciones-precap-modal__eyebrow {
                color: #64748b;
                font-size: 13px;
                font-weight: 800;
                margin: 0 0 4px;
            }
            .precaptaciones-precap-modal__header h2 {
                color: var(--precaptaciones-azul);
                font-size: 24px;
                font-weight: 900;
                letter-spacing: 0;
                line-height: 1.2;
                margin: 0;
            }
            .precaptaciones-precap-modal__close {
                align-items: center;
                background: #fff;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                color: #334155;
                cursor: pointer;
                display: inline-flex;
                height: 44px;
                justify-content: center;
                padding: 0;
                width: 44px;
            }
            .precaptaciones-precap-modal__close svg {
                height: 20px;
                width: 20px;
            }
            .precaptaciones-precap-modal__form {
                padding: 22px;
            }
            .precaptaciones-precap-modal__grid {
                display: grid;
                gap: 16px;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .precaptaciones-precap-modal__grid label {
                color: #1e293b;
                display: flex;
                flex-direction: column;
                font-size: 14px;
                font-weight: 800;
                gap: 8px;
                min-width: 0;
            }
            .precaptaciones-precap-modal__wide {
                grid-column: 1 / -1;
            }
            .precaptaciones-precap-modal textarea.precaptaciones__control {
                line-height: 1.5;
                min-height: 132px;
                resize: vertical;
            }
            .precaptaciones-precap-modal__asignar {
                display: none !important;
            }
            .precaptaciones-precap-modal__asignar.is-visible {
                display: flex !important;
            }
            .precaptaciones-precap-modal__pph-note {
                background: #fff8df;
                border: 1px solid #ffe29a;
                border-radius: 8px;
                color: #442c00;
                font-size: 14px;
                font-weight: 700;
                line-height: 1.45;
                padding: 12px 14px;
            }
            .precaptaciones-precap-modal__message {
                border-radius: 8px;
                display: none;
                font-size: 14px;
                font-weight: 700;
                margin-top: 16px;
                padding: 12px 14px;
            }
            .precaptaciones-precap-modal__message.is-success {
                background: #ecfdf5;
                color: #047857;
                display: block;
            }
            .precaptaciones-precap-modal__message.is-error {
                background: #fef2f2;
                color: #b91c1c;
                display: block;
            }
            .precaptaciones-precap-modal__footer {
                align-items: center;
                border-top: 1px solid var(--precaptaciones-borde);
                display: flex;
                gap: 12px;
                justify-content: flex-end;
                margin: 22px -22px -22px;
                padding: 16px 22px;
            }
            .precaptaciones-precap-modal__primary,
            .precaptaciones-precap-modal__secondary {
                align-items: center;
                border-radius: 8px;
                cursor: pointer;
                display: inline-flex;
                font-size: 14px;
                font-weight: 900;
                justify-content: center;
                min-height: 44px;
                padding: 11px 18px;
            }
            .precaptaciones-precap-modal__primary {
                background: var(--precaptaciones-azul);
                border: 0;
                color: #fff;
            }
            .precaptaciones-precap-modal__primary[disabled] {
                cursor: wait;
                opacity: .72;
            }
            .precaptaciones-precap-modal__secondary {
                background: #fff;
                border: 1px solid #cbd5e1;
                color: #334155;
            }
            @media (max-width: 1024px) {
                .precaptaciones__filtros-grid {
                    grid-template-columns: repeat(2, minmax(170px, 1fr));
                }
                .precaptaciones-precap-modal__grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
            @media (max-width: 640px) {
                .precaptaciones__filtros-grid,
                .precaptaciones__fecha {
                    grid-template-columns: 1fr;
                }
                .precaptaciones__filtros-grid,
                .precaptaciones__filtros-titulo,
                .precaptaciones__acciones-filtro {
                    padding-left: 14px;
                    padding-right: 14px;
                }
                .precaptaciones__filtros-heading {
                    font-size: 24px;
                }
                .precaptaciones__acciones-filtro .precaptaciones__boton-filtrar,
                .precaptaciones__acciones-filtro .precaptaciones__boton-limpiar {
                    width: 100%;
                }
                .precaptaciones-precap-modal {
                    align-items: flex-end;
                    padding: 0;
                }
                .precaptaciones-precap-modal__panel {
                    border-radius: 14px 14px 0 0;
                    max-height: 92vh;
                }
                .precaptaciones-precap-modal__grid {
                    grid-template-columns: 1fr;
                }
                .precaptaciones-precap-modal__footer {
                    align-items: stretch;
                    flex-direction: column-reverse;
                }
                .precaptaciones-detalle {
                    grid-template-columns: 1fr;
                    padding: 16px;
                }
                .precaptaciones-precap-modal__primary,
                .precaptaciones-precap-modal__secondary {
                    width: 100%;
                }
            }
        </style>
        <script>
            window.Precaptaciones = window.Precaptaciones || {
                ajaxUrl: "<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            };
            (function () {
                const closeModal = (modal) => {
                    if (!modal) return;
                    if (modal.matches("[data-precaptaciones-ticket-modal]")) return;
                    modal.hidden = true;
                    document.body.classList.remove("precaptaciones-precap-modal-open");
                };
                const forceCloseModal = (modal) => {
                    if (!modal) return;
                    modal.hidden = true;
                    document.body.classList.remove("precaptaciones-precap-modal-open");
                };
                const openModal = (modal) => {
                    if (!modal) return;
                    modal.hidden = false;
                    document.body.classList.add("precaptaciones-precap-modal-open");
                    const firstInput = modal.querySelector("input:not([type='hidden']), select, textarea, button");
                    if (firstInput) firstInput.focus();
                    syncTicketField(modal);
                };
                const syncTicketField = (scope) => {
                    const select = scope.querySelector("[data-precaptaciones-merece-ticket]");
                    const assign = scope.querySelector("[data-precaptaciones-asignar-ticket]");
                    if (!select || !assign) return;
                    assign.classList.toggle("is-visible", select.value === "Si");
                };
                const showNotice = (type, title, text) => {
                    if (window.Swal && typeof window.Swal.fire === "function") {
                        return window.Swal.fire({
                            confirmButtonColor: "#061d49",
                            icon: type,
                            text: text,
                            title: title
                        });
                    }
                    return Promise.resolve({ isConfirmed: true });
                };
                const confirmPphPoints = (form) => {
                    const select = form.querySelector("[data-precaptaciones-merece-ticket]");
                    const isPph = Boolean(form.querySelector("[data-precaptaciones-pph-note]"));
                    if (!isPph || !select || select.value !== "No") {
                        return Promise.resolve(true);
                    }

                    const text = "Esta precaptacion es Club PPH. Si marcas No, se registraran puntos PPH como inmueble no efectivo. Si debes continuar el seguimiento, usa Seguir llamando.";
                    if (window.Swal && typeof window.Swal.fire === "function") {
                        return window.Swal.fire({
                            cancelButtonText: "Cancelar",
                            confirmButtonColor: "#061d49",
                            confirmButtonText: "Si, registrar No",
                            icon: "warning",
                            showCancelButton: true,
                            text: text,
                            title: "Confirmar puntos PPH"
                        }).then((result) => Boolean(result.isConfirmed));
                    }

                    return Promise.resolve(window.confirm(text));
                };
                const syncTicketModalFromEdit = (editForm, ticketModal) => {
                    if (!editForm || !ticketModal) return;
                    const pairs = {
                        contacto: "solicitante",
                        correo: "correo_solicitante",
                        celular: "celular_solicitante"
                    };
                    Object.keys(pairs).forEach((sourceName) => {
                        const source = editForm.querySelector('[name="' + sourceName + '"]');
                        const target = ticketModal.querySelector('[name="' + pairs[sourceName] + '"]');
                        if (source && target) target.value = source.value || "";
                    });
                    const assignedSource = editForm.querySelector('[name="id_empleado_2"]');
                    const assignedTarget = ticketModal.querySelector('[name="asignado"]');
                    if (assignedSource && assignedTarget && assignedSource.value) {
                        assignedTarget.value = assignedSource.value;
                    }
                };
                const lightbox = {
                    index: 0,
                    urls: []
                };
                const getLightbox = () => {
                    let box = document.querySelector("[data-precaptaciones-lightbox]");
                    if (box) return box;
                    box = document.createElement("div");
                    box.className = "precaptaciones-lightbox";
                    box.hidden = true;
                    box.dataset.precaptacionesLightbox = "";
                    box.innerHTML = [
                        '<button class="precaptaciones-lightbox__close" type="button" data-precaptaciones-lightbox-close aria-label="Cerrar evidencia">x</button>',
                        '<div class="precaptaciones-lightbox__panel" role="dialog" aria-modal="true" aria-label="Evidencia">',
                        '<button class="precaptaciones-lightbox__nav" type="button" data-precaptaciones-lightbox-prev aria-label="Evidencia anterior">&lsaquo;</button>',
                        '<figure class="precaptaciones-lightbox__figure">',
                        '<img class="precaptaciones-lightbox__image" data-precaptaciones-lightbox-image alt="Evidencia ampliada">',
                        '<figcaption class="precaptaciones-lightbox__count" data-precaptaciones-lightbox-count></figcaption>',
                        '</figure>',
                        '<button class="precaptaciones-lightbox__nav" type="button" data-precaptaciones-lightbox-next aria-label="Evidencia siguiente">&rsaquo;</button>',
                        '</div>'
                    ].join("");
                    document.body.appendChild(box);
                    return box;
                };
                const renderLightbox = () => {
                    const box = getLightbox();
                    const image = box.querySelector("[data-precaptaciones-lightbox-image]");
                    const count = box.querySelector("[data-precaptaciones-lightbox-count]");
                    const prev = box.querySelector("[data-precaptaciones-lightbox-prev]");
                    const next = box.querySelector("[data-precaptaciones-lightbox-next]");
                    if (!image || !lightbox.urls.length) return;
                    image.src = lightbox.urls[lightbox.index];
                    if (count) count.textContent = (lightbox.index + 1).toLocaleString("es-CO") + " de " + lightbox.urls.length.toLocaleString("es-CO");
                    if (prev) prev.disabled = lightbox.urls.length <= 1;
                    if (next) next.disabled = lightbox.urls.length <= 1;
                };
                const openLightbox = (urls, index) => {
                    lightbox.urls = urls;
                    lightbox.index = index || 0;
                    const box = getLightbox();
                    renderLightbox();
                    box.hidden = false;
                    document.body.classList.add("precaptaciones-precap-modal-open");
                    const closeButton = box.querySelector("[data-precaptaciones-lightbox-close]");
                    if (closeButton) closeButton.focus();
                };
                const closeLightbox = () => {
                    const box = document.querySelector("[data-precaptaciones-lightbox]");
                    if (!box || box.hidden) return false;
                    box.hidden = true;
                    document.body.classList.remove("precaptaciones-precap-modal-open");
                    return true;
                };
                const moveLightbox = (step) => {
                    if (!lightbox.urls.length) return;
                    lightbox.index = (lightbox.index + step + lightbox.urls.length) % lightbox.urls.length;
                    renderLightbox();
                };
                const getPanel = (node) => node ? node.closest("[data-precaptaciones-panel]") : null;
                const currentPage = (panel) => {
                    const active = panel ? panel.querySelector("[data-precaptaciones-page].is-active") : null;
                    return active ? Number(active.dataset.precaptacionesPage || "1") : 1;
                };
                const updateActiveFilters = (panel, count) => {
                    const badge = panel ? panel.querySelector("[data-precaptaciones-active-filters]") : null;
                    if (!badge) return;
                    badge.hidden = false;
                    badge.textContent = count.toLocaleString("es-CO") + " activos";
                };
                const localActiveFilterCount = (form) => {
                    if (!form) return 0;
                    return Array.from(form.elements).filter((field) => {
                        return field.name && field.name.indexOf("precaptaciones_") === 0 && String(field.value || "").trim() !== "";
                    }).length;
                };
                const enforceLockedActions = (scope) => {
                    const root = scope || document;
                    root.querySelectorAll("[data-precaptaciones-row-id]").forEach((row) => {
                        const reason = String(row.dataset.precaptacionesReason || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
                        const mereceTicket = String(row.dataset.precaptacionesMereceTicket || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
                        const shouldLock = row.dataset.precaptacionesActionsLocked === "1"
                            || row.dataset.precaptacionesHasTicket === "1"
                            || Boolean(row.querySelector(".precaptaciones__ticket-boton"))
                            || reason === "ticket creado"
                            || mereceTicket === "no";
                        if (!shouldLock) return;

                        row.querySelectorAll(".precaptaciones__editar, .precaptaciones__duplicada, .precaptaciones__sin-informacion").forEach((button) => button.remove());
                        const rowId = row.dataset.precaptacionesRowId || "";
                        if (rowId) {
                            const editModal = document.getElementById("precaptaciones-precap-modal-" + rowId);
                            if (editModal) editModal.remove();
                        }
                    });
                };
                const fetchResults = async (panel, page) => {
                    if (!panel) return;
                    const form = panel.querySelector("[data-precaptaciones-filters]");
                    const results = panel.querySelector("[data-precaptaciones-results]");
                    const status = panel.querySelector("[data-precaptaciones-status]");
                    if (!form || !results) return;

                    const data = new FormData(form);
                    data.append("action", "precaptaciones_filtrar");
                    data.append("nonce", panel.dataset.nonce || "");
                    data.append("mode", panel.dataset.mode || "mis");
                    data.append("per_page", panel.dataset.perPage || "50");
                    data.append("page", String(page || 1));

                    panel.classList.add("is-loading");
                    if (status) {
                        status.className = "precaptaciones__estado is-loading";
                        status.textContent = "Filtrando precaptaciones...";
                    }

                    try {
                        const response = await fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        });
                        const payload = await response.json();
                        if (!payload.success) {
                            throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo filtrar.");
                        }
                        results.innerHTML = payload.data.html || "";
                        const metrics = panel.querySelector("[data-precap-metrics]");
                        if (metrics && payload.data.metrics) metrics.innerHTML = payload.data.metrics;
                        enforceLockedActions(results);
                        updateActiveFilters(panel, Number(payload.data.active_filters || localActiveFilterCount(form)));
                        if (status) {
                            status.className = "precaptaciones__estado";
                            status.textContent = "";
                        }
                    } catch (error) {
                        if (status) {
                            status.className = "precaptaciones__estado is-error";
                            status.textContent = error.message || "No se pudo filtrar.";
                        }
                    } finally {
                        panel.classList.remove("is-loading");
                    }
                };
                const filterTimers = new WeakMap();
                const scheduleFetch = (panel) => {
                    if (!panel) return;
                    window.clearTimeout(filterTimers.get(panel));
                    filterTimers.set(panel, window.setTimeout(() => fetchResults(panel, 1), 350));
                };
                enforceLockedActions(document);

                document.addEventListener("click", async (event) => {
                    const evidenceButton = event.target.closest("[data-precaptaciones-evidence]");
                    if (evidenceButton) {
                        try {
                            const urls = JSON.parse(evidenceButton.dataset.precaptacionesEvidence || "[]");
                            if (Array.isArray(urls) && urls.length) {
                                openLightbox(urls, 0);
                            }
                        } catch (error) {
                            window.open(evidenceButton.querySelector("img")?.src || "", "_blank", "noopener");
                        }
                        return;
                    }

                    if (event.target.closest("[data-precaptaciones-lightbox-close]") || event.target.matches("[data-precaptaciones-lightbox]")) {
                        closeLightbox();
                        return;
                    }

                    if (event.target.closest("[data-precaptaciones-lightbox-prev]")) {
                        moveLightbox(-1);
                        return;
                    }

                    if (event.target.closest("[data-precaptaciones-lightbox-next]")) {
                        moveLightbox(1);
                        return;
                    }

                    const clearButton = event.target.closest("[data-precaptaciones-clear-filters]");
                    if (clearButton) {
                        const panel = getPanel(clearButton);
                        const form = panel ? panel.querySelector("[data-precaptaciones-filters]") : null;
                        if (form) {
                            form.reset();
                            updateActiveFilters(panel, 0);
                            fetchResults(panel, 1);
                        }
                        return;
                    }

                    const pageButton = event.target.closest("[data-precaptaciones-page]");
                    if (pageButton) {
                        fetchResults(getPanel(pageButton), Number(pageButton.dataset.precaptacionesPage || "1"));
                        return;
                    }

                    const normalizarPromocionadoButton = event.target.closest("[data-precaptaciones-normalizar-promocionado]");
                    if (normalizarPromocionadoButton) {
                        const count = Number(normalizarPromocionadoButton.dataset.count || "0");
                        const panel = getPanel(normalizarPromocionadoButton);
                        const wrapper = normalizarPromocionadoButton.closest(".precaptaciones__bulk");
                        const message = wrapper ? wrapper.querySelector("[data-precaptaciones-bulk-message]") : null;
                        if (count <= 0) return;

                        const confirmed = window.confirm(
                            "Se normalizaran " + count.toLocaleString("es-CO") + " valores de Promocionado por al formato serializado. ¿Continuar?"
                        );
                        if (!confirmed) return;

                        const originalText = normalizarPromocionadoButton.textContent;
                        normalizarPromocionadoButton.disabled = true;
                        normalizarPromocionadoButton.textContent = "Normalizando...";
                        if (message) {
                            message.className = "precaptaciones__bulk-message";
                            message.textContent = "";
                        }

                        const data = new FormData();
                        data.append("action", "precaptaciones_normalizar_promocionado_por");
                        data.append("nonce", normalizarPromocionadoButton.dataset.nonce || "");

                        fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        })
                            .then((response) => response.json())
                            .then((payload) => {
                                if (!payload.success) {
                                    throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo normalizar.");
                                }
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-success";
                                    message.textContent = payload.data.message || "Normalizacion completada.";
                                }
                                setTimeout(() => fetchResults(panel, currentPage(panel)), 650);
                            })
                            .catch((error) => {
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-error";
                                    message.textContent = error.message || "No se pudo normalizar.";
                                }
                                normalizarPromocionadoButton.disabled = false;
                                normalizarPromocionadoButton.textContent = originalText;
                            });
                        return;
                    }

                    const normalizarCompetenciaButton = event.target.closest("[data-precaptaciones-normalizar-competencia]");
                    if (normalizarCompetenciaButton) {
                        const count = Number(normalizarCompetenciaButton.dataset.count || "0");
                        const panel = getPanel(normalizarCompetenciaButton);
                        const wrapper = normalizarCompetenciaButton.closest(".precaptaciones__bulk");
                        const message = wrapper ? wrapper.querySelector("[data-precaptaciones-bulk-message]") : null;
                        if (count <= 0) return;

                        const confirmed = window.confirm(
                            "Se normalizaran " + count.toLocaleString("es-CO") + " valores de Competencia al formato serializado. ¿Continuar?"
                        );
                        if (!confirmed) return;

                        const originalText = normalizarCompetenciaButton.textContent;
                        normalizarCompetenciaButton.disabled = true;
                        normalizarCompetenciaButton.textContent = "Normalizando...";
                        if (message) {
                            message.className = "precaptaciones__bulk-message";
                            message.textContent = "";
                        }

                        const data = new FormData();
                        data.append("action", "precaptaciones_normalizar_competencia");
                        data.append("nonce", normalizarCompetenciaButton.dataset.nonce || "");

                        fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        })
                            .then((response) => response.json())
                            .then((payload) => {
                                if (!payload.success) {
                                    throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo normalizar.");
                                }
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-success";
                                    message.textContent = payload.data.message || "Normalizacion completada.";
                                }
                                setTimeout(() => fetchResults(panel, currentPage(panel)), 650);
                            })
                            .catch((error) => {
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-error";
                                    message.textContent = error.message || "No se pudo normalizar.";
                                }
                                normalizarCompetenciaButton.disabled = false;
                                normalizarCompetenciaButton.textContent = originalText;
                            });
                        return;
                    }

                    const normalizarRazonCreadoButton = event.target.closest("[data-precaptaciones-normalizar-razon-creado]");
                    if (normalizarRazonCreadoButton) {
                        const count = Number(normalizarRazonCreadoButton.dataset.count || "0");
                        const panel = getPanel(normalizarRazonCreadoButton);
                        const wrapper = normalizarRazonCreadoButton.closest(".precaptaciones__bulk");
                        const message = wrapper ? wrapper.querySelector("[data-precaptaciones-bulk-message]") : null;
                        if (count <= 0) return;

                        const confirmed = window.confirm(
                            "Se cambiaran " + count.toLocaleString("es-CO") + " razones de Creado a Tarea creada. ¿Continuar?"
                        );
                        if (!confirmed) return;

                        const originalText = normalizarRazonCreadoButton.textContent;
                        normalizarRazonCreadoButton.disabled = true;
                        normalizarRazonCreadoButton.textContent = "Actualizando...";
                        if (message) {
                            message.className = "precaptaciones__bulk-message";
                            message.textContent = "";
                        }

                        const data = new FormData();
                        data.append("action", "precaptaciones_normalizar_razon_creado");
                        data.append("nonce", normalizarRazonCreadoButton.dataset.nonce || "");

                        fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        })
                            .then((response) => response.json())
                            .then((payload) => {
                                if (!payload.success) {
                                    throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo actualizar.");
                                }
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-success";
                                    message.textContent = payload.data.message || "Actualizacion completada.";
                                }
                                setTimeout(() => fetchResults(panel, currentPage(panel)), 650);
                            })
                            .catch((error) => {
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-error";
                                    message.textContent = error.message || "No se pudo actualizar.";
                                }
                                normalizarRazonCreadoButton.disabled = false;
                                normalizarRazonCreadoButton.textContent = originalText;
                            });
                        return;
                    }

                    const estadoButton = event.target.closest("[data-precaptaciones-duplicada], [data-precaptaciones-sin-informacion]");
                    if (estadoButton) {
                        const isSinInformacion = Boolean(estadoButton.matches("[data-precaptaciones-sin-informacion]"));
                        const panel = getPanel(estadoButton);
                        const text = isSinInformacion
                            ? "Se marcara como sin informacion con contactado = Si, razon Precaptacion sin informacion y un resultado fijo de falta de datos."
                            : "Se marcara como duplicada con contactado = Si, razon Precaptacion duplicada y el resultado fijo de duplicidad.";
                        const title = isSinInformacion ? "Marcar sin informacion" : "Marcar duplicada";
                        const confirmText = isSinInformacion ? "Si, marcar sin informacion" : "Si, marcar duplicada";
                        const action = isSinInformacion ? "precaptaciones_marcar_sin_informacion" : "precaptaciones_marcar_duplicada";
                        const successTitle = isSinInformacion ? "Sin informacion" : "Duplicada";
                        const fallbackSuccess = isSinInformacion ? "Precaptacion marcada como sin informacion." : "Precaptacion marcada como duplicada.";
                        const fallbackError = isSinInformacion ? "No se pudo marcar sin informacion." : "No se pudo marcar duplicada.";
                        let confirmed = true;
                        if (window.Swal && typeof window.Swal.fire === "function") {
                            const result = await window.Swal.fire({
                                cancelButtonText: "Cancelar",
                                confirmButtonColor: "#991b1b",
                                confirmButtonText: confirmText,
                                icon: "warning",
                                showCancelButton: true,
                                text: text,
                                title: title
                            });
                            confirmed = Boolean(result.isConfirmed);
                        } else {
                            confirmed = window.confirm(text);
                        }
                        if (!confirmed) return;

                        const originalText = estadoButton.textContent;
                        estadoButton.disabled = true;
                        estadoButton.textContent = "Guardando...";

                        const data = new FormData();
                        data.append("action", action);
                        data.append("nonce", estadoButton.dataset.nonce || "");
                        data.append("id_precaptacion", estadoButton.dataset.id || "");

                        fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        })
                            .then((response) => response.json())
                            .then(async (payload) => {
                                if (!payload.success) {
                                    throw new Error(payload.data && payload.data.message ? payload.data.message : fallbackError);
                                }
                                await showNotice("success", successTitle, payload.data.message || fallbackSuccess);
                                fetchResults(panel, currentPage(panel));
                            })
                            .catch(async (error) => {
                                await showNotice("error", "No se pudo marcar", error.message || fallbackError);
                                estadoButton.disabled = false;
                                estadoButton.textContent = originalText;
                            });
                        return;
                    }

                    const bulkButton = event.target.closest("[data-precaptaciones-bulk-old-not-contacted]");
                    if (bulkButton) {
                        const count = Number(bulkButton.dataset.count || "0");
                        const panel = getPanel(bulkButton);
                        const wrapper = bulkButton.closest(".precaptaciones__bulk");
                        const message = wrapper ? wrapper.querySelector("[data-precaptaciones-bulk-message]") : null;
                        if (count <= 0) return;

                        const confirmed = window.confirm(
                            "Se marcaran " + count.toLocaleString("es-CO") + " precaptaciones anteriores a 2026 como contactadas. Razon: No contestó. ¿Continuar?"
                        );
                        if (!confirmed) return;

                        const originalText = bulkButton.textContent;
                        bulkButton.disabled = true;
                        bulkButton.textContent = "Actualizando...";
                        if (message) {
                            message.className = "precaptaciones__bulk-message";
                            message.textContent = "";
                        }

                        const data = new FormData();
                        data.append("action", "precaptaciones_marcar_antiguas_no_contactadas");
                        data.append("nonce", bulkButton.dataset.nonce || "");

                        fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: data
                        })
                            .then((response) => response.json())
                            .then((payload) => {
                                if (!payload.success) {
                                    throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo actualizar.");
                                }
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-success";
                                    message.textContent = payload.data.message || "Actualizacion completada.";
                                }
                                setTimeout(() => fetchResults(panel, currentPage(panel)), 650);
                            })
                            .catch((error) => {
                                if (message) {
                                    message.className = "precaptaciones__bulk-message is-error";
                                    message.textContent = error.message || "No se pudo actualizar.";
                                }
                                bulkButton.disabled = false;
                                bulkButton.textContent = originalText;
                            });
                        return;
                    }

                    const openTarget = event.target.closest("[data-precaptaciones-modal-open]");
                    if (openTarget) {
                        openModal(document.getElementById(openTarget.dataset.precaptacionesModalOpen));
                        return;
                    }

                    const closeTarget = event.target.closest("[data-precaptaciones-modal-close]");
                    if (closeTarget) {
                        closeModal(closeTarget.closest(".precaptaciones-precap-modal"));
                    }
                });

                document.addEventListener("keydown", (event) => {
                    if (event.key === "Escape") {
                        if (closeLightbox()) return;
                        closeModal(document.querySelector(".precaptaciones-precap-modal:not([hidden])"));
                    }
                    if (event.key === "ArrowLeft" && !getLightbox().hidden) {
                        moveLightbox(-1);
                    }
                    if (event.key === "ArrowRight" && !getLightbox().hidden) {
                        moveLightbox(1);
                    }
                });

                document.addEventListener("change", (event) => {
                    if (event.target.matches("[data-precaptaciones-merece-ticket]")) {
                        syncTicketField(event.target.closest(".precaptaciones-precap-modal"));
                    }
                    const filterForm = event.target.closest("[data-precaptaciones-filters]");
                    if (filterForm) {
                        fetchResults(getPanel(filterForm), 1);
                    }
                });

                document.addEventListener("input", (event) => {
                    const filterForm = event.target.closest("[data-precaptaciones-filters]");
                    if (filterForm) {
                        scheduleFetch(getPanel(filterForm));
                    }
                });

                document.addEventListener("submit", async (event) => {
                    const filterForm = event.target.closest("[data-precaptaciones-filters]");
                    if (filterForm) {
                        event.preventDefault();
                        fetchResults(getPanel(filterForm), 1);
                        return;
                    }

                    const ticketForm = event.target.closest("[data-precaptaciones-ticket-form]");
                    if (ticketForm) {
                        event.preventDefault();
                        const button = ticketForm.querySelector("button[type='submit']");
                        const message = ticketForm.querySelector("[data-precaptaciones-ticket-message]");
                        const originalText = button ? button.textContent : "";
                        let ticketWindow = null;
                        if (button) {
                            button.disabled = true;
                            button.textContent = "Creando tarea...";
                        }
                        if (message) {
                            message.className = "precaptaciones-precap-modal__message";
                            message.textContent = "";
                        }
                        try {
                            ticketWindow = window.open("about:blank", "_blank", "noopener");
                            if (ticketWindow && ticketWindow.document) {
                                ticketWindow.document.write("<p style=\"font-family:Arial,sans-serif;padding:24px;\">Creando tarea...</p>");
                                ticketWindow.document.close();
                            }
                        } catch (popupError) {
                            ticketWindow = null;
                        }

                        try {
                            const response = await fetch(window.Precaptaciones.ajaxUrl, {
                                method: "POST",
                                credentials: "same-origin",
                                body: new FormData(ticketForm)
                            });
                            const payload = await response.json();
                            if (!payload.success) {
                                throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo crear la tarea.");
                            }
                            if (message) {
                                message.className = "precaptaciones-precap-modal__message is-success";
                                message.textContent = payload.data.message || "Tarea creada con exito.";
                            }
                            if (payload.data && payload.data.ticket_url) {
                                if (ticketWindow && !ticketWindow.closed) {
                                    ticketWindow.location.href = payload.data.ticket_url;
                                } else {
                                    window.open(payload.data.ticket_url, "_blank", "noopener");
                                }
                                await showNotice("success", "Tarea creada", payload.data.message || "Tarea creada con exito.");
                                const panel = getPanel(ticketForm);
                                forceCloseModal(ticketForm.closest(".precaptaciones-precap-modal"));
                                fetchResults(panel, currentPage(panel));
                                return;
                            }
                            const panel = getPanel(ticketForm);
                            forceCloseModal(ticketForm.closest(".precaptaciones-precap-modal"));
                            fetchResults(panel, currentPage(panel));
                        } catch (error) {
                            if (ticketWindow && !ticketWindow.closed) {
                                ticketWindow.close();
                            }
                            if (message) {
                                message.className = "precaptaciones-precap-modal__message is-error";
                                message.textContent = error.message || "No se pudo crear la tarea.";
                            }
                            await showNotice("error", "No se pudo crear", error.message || "No se pudo crear la tarea.");
                            if (button) {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                        }
                        return;
                    }

                    const form = event.target.closest("[data-precaptaciones-precap-form]");
                    if (!form) return;

                    event.preventDefault();
                    const confirmedPph = await confirmPphPoints(form);
                    if (!confirmedPph) {
                        return;
                    }

                    const button = form.querySelector("button[type='submit']");
                    const message = form.querySelector("[data-precaptaciones-form-message]");
                    const originalText = button ? button.textContent : "";
                    if (button) {
                        button.disabled = true;
                        button.textContent = "Guardando...";
                    }
                    if (message) {
                        message.className = "precaptaciones-precap-modal__message";
                        message.textContent = "";
                    }

                    try {
                        const response = await fetch(window.Precaptaciones.ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: new FormData(form)
                        });
                        const payload = await response.json();
                        if (!payload.success) {
                            throw new Error(payload.data && payload.data.message ? payload.data.message : "No se pudo guardar.");
                        }
                        if (message) {
                            message.className = "precaptaciones-precap-modal__message is-success";
                            message.textContent = payload.data.message || "Precaptacion actualizada correctamente.";
                        }
                        await showNotice("success", "Guardado", payload.data.message || "Precaptacion actualizada correctamente.");
                        if (payload.data && payload.data.ticket_modal_id) {
                            let ticketModal = document.getElementById(payload.data.ticket_modal_id);
                            if (payload.data.ticket_modal_html) {
                                const oldTicketModal = document.getElementById(payload.data.ticket_modal_id);
                                if (oldTicketModal) oldTicketModal.remove();

                                const template = document.createElement("template");
                                template.innerHTML = String(payload.data.ticket_modal_html).trim();
                                ticketModal = template.content.firstElementChild;

                                const panelNode = form.closest("[data-precaptaciones-panel]");
                                const holder = (panelNode ? panelNode.querySelector(".precaptaciones__modales") : null) || document.body;
                                if (ticketModal) holder.appendChild(ticketModal);
                            }
                            if (!ticketModal) {
                                throw new Error("No se pudo abrir el formulario para crear la tarea.");
                            }
                            syncTicketModalFromEdit(form, ticketModal);
                            closeModal(form.closest(".precaptaciones-precap-modal"));
                            openModal(ticketModal);
                            return;
                        }
                        setTimeout(() => {
                            const panel = getPanel(form);
                            closeModal(form.closest(".precaptaciones-precap-modal"));
                            fetchResults(panel, currentPage(panel));
                        }, 700);
                    } catch (error) {
                        if (message) {
                            message.className = "precaptaciones-precap-modal__message is-error";
                            message.textContent = error.message || "No se pudo guardar.";
                        }
                        await showNotice("error", "No se pudo guardar", error.message || "No se pudo guardar.");
                        if (button) {
                            button.disabled = false;
                            button.textContent = originalText;
                        }
                    }
                });
            })();
        </script>
        <?php
    }
}
