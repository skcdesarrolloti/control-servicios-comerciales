(function () {
  "use strict";
  // Herramientas › Plan estratégico. Adaptación de la interfaz del plugin de WordPress "Gestor Estratégico" V30.9
  // (shortcode [gestor_estrategia]: renderTableHTML, renderFilters, gpe_ver_hijos, gpe_abrir_modal, gpe_gestion_avances y
  // gpe_gestion_resultados) con el diseño de las herramientas. Si cambia el flujo en uno, cambiarlo en el otro.
  // "Ver" de cada fila abre la pestaña hija ya filtrada (en el plugin abría un modal con historial "Volver"). Los
  // catálogos se editan en Configuración › Plan estratégico. Los datos vienen de commercial_plan_* y el servidor vuelve a
  // validar cada permiso. La pestaña llega por AJAX: los eventos se delegan en document y las raíces se detectan con un
  // MutationObserver.
  if (window.__commercialStrategyLoaded) return;
  window.__commercialStrategyLoaded = true;

  var app = document.getElementById("scm-app");
  var runtime = {};
  try { runtime = JSON.parse((app && app.getAttribute("data-scm-runtime")) || "{}") || {}; } catch (_error) { runtime = {}; }

  var SINGULAR = { objetivos: "objetivo", metas: "meta", tareas: "tarea", campanas: "campaña" };
  var NO_GROUP = "Sin asignar";
  var GLOSSARY = [["gestiones", "Gestiones"], ["tipos_campana", "Tipos de campaña"], ["tipos_medio", "Tipos de medio"], ["tipos_mercadeo", "Tipos de mercadeo"], ["frecuencias", "Frecuencias"], ["actores", "Actores"], ["unidades", "Unidades"]];

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function icon(name) { return '<span class="material-symbols-outlined" aria-hidden="true">' + name + "</span>"; }

  async function request(action, fields) {
    var data = new FormData();
    Object.keys(fields || {}).forEach(function (key) { data.set(key, fields[key]); });
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var json = null;
    try { json = await response.json(); } catch (_error) { json = null; }
    if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
    return json.data || {};
  }

  /** AAAA-MM-DD → dd/mm/aaaa. */
  function day(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || "");
    return match ? match[3] + "/" + match[2] + "/" + match[1] : "";
  }

  function today() {
    var now = new Date();
    return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-" + String(now.getDate()).padStart(2, "0");
  }

  // ── Estado ────────────────────────────────────────────────────────────────

  function state(root) {
    if (root.__plan) return root.__plan;
    var options = {};
    try { options = JSON.parse(root.getAttribute("data-plan-options") || "{}") || {}; } catch (_error) { options = {}; }
    root.__plan = {
      tab: root.getAttribute("data-plan-start") || "objetivos",
      filters: {},
      // "Ver" de una fila: la pestaña hija queda filtrada por ese registro (context) y trail guarda de dónde se vino,
      // para el botón "Volver" (como el historial del modal del plugin).
      context: null,
      trail: [],
      search: "",
      rows: [],
      options: options,
      canEdit: root.getAttribute("data-plan-can-edit") === "1",
      user: root.getAttribute("data-plan-user") || "",
      seq: 0,
      timer: 0,
      form: null,
      log: null,
      confirm: null,
    };
    return root.__plan;
  }

  function catalog(plan, key) { return ((plan.options.catalogs || {})[key]) || []; }

  function status(root, message, error) {
    var node = root.querySelector("[data-plan-status]");
    if (!node) return;
    node.textContent = message || "";
    if (error) node.setAttribute("data-error", "true");
    else node.removeAttribute("data-error");
  }

  function updateSummary(root, summary) {
    if (!summary) return;
    root.querySelectorAll("[data-plan-stat]").forEach(function (node) {
      var key = node.getAttribute("data-plan-stat");
      if (summary[key] != null) node.textContent = String(summary[key]);
    });
    var metas = root.querySelector('[data-plan-hint="metas"]');
    if (metas) metas.textContent = summary.metas_realizadas + " realizadas · " + summary.metas_vencidas + " vencidas";
    var tareas = root.querySelector('[data-plan-hint="tareas"]');
    if (tareas) tareas.textContent = summary.tareas_completadas + " completadas · " + summary.tareas_en_proceso + " en proceso";
  }

  // ── Filtros (renderFilters del plugin) ────────────────────────────────────

  // Solo hay dos pestañas: Mis tareas y Gestiones. Objetivos, metas, tareas y campañas se abren con "Ver" desde su
  // padre (gestión → objetivo → meta → tarea → campaña), como el modal con historial del plugin; la ruta de arriba
  // (migas) permite volver a cualquier nivel. Los nombres de los padres no van en selectores: son muy largos.
  function filterDefs(plan) {
    var plain = function (values) { return values.map(function (value) { return { value: value, label: value }; }); };
    var people = plain(plan.options.funcionarios || []);
    var catalogNames = function (key) { return catalog(plan, key).map(function (row) { return { value: row.valor, label: row.nombre }; }); };
    switch (plan.tab) {
      case "objetivos": return [["responsable", "Responsable", people]];
      case "metas": return [["estado", "Estado", plain(plan.options.estados_meta || [])]];
      case "tareas": return [["estado", "Estado", plain(plan.options.estados_tarea || [])], ["responsable", "Responsable", people]];
      // En Mis tareas, la gestión (subiendo por meta → objetivo) ayuda a separar las propias.
      case "mis_tareas": return [["gestion", "Gestión", catalogNames("gestiones")], ["estado", "Estado", plain(plan.options.estados_tarea || [])]];
      case "campanas": return [["tipo", "Tipo de campaña", catalogNames("tipos_campana")], ["medio", "Medio", catalogNames("tipos_medio")], ["responsable", "Responsable", people]];
    }
    return [];
  }

  var TAB_LABELS = { gestiones: "Gestiones", mis_tareas: "Mis tareas" };

  /** Pestaña de la que partió el recorrido (la que queda resaltada). */
  function rootTab(plan) {
    return plan.trail.length ? plan.trail[0].tab : plan.tab;
  }

  function renderContext(root) {
    var plan = state(root);
    var box = root.querySelector("[data-plan-context]");
    if (!box) return;
    box.hidden = !plan.context;
    if (!plan.context) { box.innerHTML = ""; return; }
    // Migas: la pestaña de origen y cada registro por el que se bajó; el último es el padre de la lista actual.
    var crumbs = plan.trail.map(function (step, index) {
      var label = index === 0 ? TAB_LABELS[step.tab] || "Inicio" : step.context.label;
      return '<li><button type="button" class="cps-crumb" data-plan-crumb="' + index + '" title="' + esc(label) + '">' + esc(label) + "</button></li>";
    }).join("") + '<li aria-current="page"><span class="cps-crumb is-current" title="' + esc(plan.context.label) + '">' + esc(plan.context.label) + "</span></li>";
    box.innerHTML = '<button type="button" class="ctl-ghost cps-back" data-plan-back>' + icon("arrow_back") + "Volver</button>" +
      '<div class="cps-context__text"><nav aria-label="Ruta del plan"><ol class="cps-crumbs">' + crumbs + "</ol></nav>" +
      "<span>" + esc(plan.context.title) + "</span></div>";
  }

  function renderFilters(root) {
    var plan = state(root);
    var current = plan.filters[plan.tab] || {};
    var box = root.querySelector("[data-plan-filters]");
    if (!box) return;
    box.innerHTML = filterDefs(plan).map(function (def) {
      var options = '<option value="">Todos</option>' + def[2].map(function (option) {
        return '<option value="' + esc(option.value) + '"' + (String(current[def[0]] || "") === option.value ? " selected" : "") + ">" + esc(option.label) + "</option>";
      }).join("");
      return '<label class="ctl-field"><span class="ctl-label">' + esc(def[1]) + '</span><span class="ctl-input"><select data-plan-filter="' + def[0] + '">' + options + "</select></span></label>";
    }).join("");
    root.querySelectorAll("[data-plan-tab]").forEach(function (button) {
      button.setAttribute("aria-pressed", button.getAttribute("data-plan-tab") === rootTab(plan) ? "true" : "false");
    });
    var label = root.querySelector("[data-plan-new-label]");
    var create = root.querySelector("[data-plan-new]");
    var type = plan.tab === "mis_tareas" ? "tareas" : plan.tab;
    if (label && SINGULAR[type]) label.textContent = (type === "objetivos" ? "Nuevo " : "Nueva ") + SINGULAR[type];
    // Las gestiones se editan en Configuración › Plan estratégico.
    if (create) create.hidden = plan.tab === "mis_tareas" || plan.tab === "gestiones";
    renderContext(root);
  }

  // ── Listas (renderTableHTML del plugin) ───────────────────────────────────

  async function load(root) {
    var plan = state(root);
    var seq = ++plan.seq;
    var list = root.querySelector("[data-plan-list]");
    list.setAttribute("aria-busy", "true");
    try {
      var data = await request("commercial_plan_list", { lista: plan.tab, filters: JSON.stringify(Object.assign({ q: plan.search }, plan.filters[plan.tab] || {}, plan.context ? plan.context.filter : {})) });
      if (seq !== plan.seq) return;
      plan.rows = data.rows || [];
      updateSummary(root, data.summary);
      list.innerHTML = renderList(plan);
      status(root, "");
    } catch (error) {
      if (seq !== plan.seq) return;
      list.innerHTML = '<div class="ctl-empty">' + icon("error") + "<p>" + esc(error.message) + "</p></div>";
    } finally {
      if (seq === plan.seq) list.removeAttribute("aria-busy");
    }
  }

  function groupKey(tab, row) {
    if (tab === "objetivos") return row.perspectiva || NO_GROUP;
    if (tab === "metas") return row.objetivo || NO_GROUP;
    if (tab === "tareas" || tab === "mis_tareas") return (row.metas || []).join(" · ") || NO_GROUP;
    if (tab === "campanas") return (row.tareas || []).join(" · ") || NO_GROUP;
    return NO_GROUP;
  }

  function renderList(plan) {
    if (!plan.rows.length) {
      var empty = plan.tab === "mis_tareas" ? "No tienes tareas asignadas con estos filtros." : "Sin registros con estos filtros.";
      return '<div class="ctl-empty">' + icon("inbox") + "<p>" + esc(empty) + "</p></div>";
    }
    var groups = {};
    plan.rows.forEach(function (row) {
      var key = groupKey(plan.tab, row);
      (groups[key] = groups[key] || []).push(row);
    });
    // Dentro de un padre la lista es una sola tabla: la ruta de arriba ya dice a qué pertenece.
    if (plan.tab === "gestiones" || plan.context) groups = { "": plan.rows };
    var keys = Object.keys(groups).sort(function (a, b) {
      if (a === NO_GROUP) return 1;
      if (b === NO_GROUP) return -1;
      return a.localeCompare(b, "es");
    });
    var heads = {
      gestiones: ["Gestión", "Alcance", "Objetivos", ""],
      objetivos: ["Objetivo", "Metas", "Responsables", ""],
      metas: ["Meta (KPI)", "Tareas", "Fecha límite", ""],
      tareas: ["Tarea", "Estado", "Bitácora", "Responsables", ""],
      mis_tareas: ["Tarea", "Estado", "Bitácora", "Responsables", ""],
      campanas: ["Campaña", "Detalles", "Resultados", "Responsables", ""],
    }[plan.tab];
    return keys.map(function (key) {
      return '<section class="cps-group">' + (key !== "" ? '<h3 class="cps-group__title">' + icon("layers") + "<span>" + esc(key) + "</span><small>" + groups[key].length + "</small></h3>" : "") +
        '<div class="ctl-table-wrap cps-table-wrap"><table class="ctl-table ctl-table--data cps-table"><thead><tr>' +
        heads.map(function (head) { return "<th>" + esc(head) + "</th>"; }).join("") + "</tr></thead><tbody>" +
        groups[key].map(function (row) { return renderRow(plan, row); }).join("") + "</tbody></table></div></section>";
    }).join("");
  }

  function people(names) {
    if (!names || !names.length) return '<span class="cps-muted">—</span>';
    return '<span class="cps-people">' + names.map(function (name) { return '<span class="cps-person">' + esc(name) + "</span>"; }).join("") + "</span>";
  }

  function badge(text) {
    var kind = { Completado: "done", Realizado: "done", "En Proceso": "progress", Pendiente: "pending", "Sin realizar": "pending" }[text] || "pending";
    return '<span class="cps-badge" data-kind="' + kind + '">' + esc(text) + "</span>";
  }

  function bar(done, total, label) {
    var pct = total > 0 ? Math.round(done / total * 100) : 0;
    var tone = pct >= 80 ? "good" : pct >= 40 ? "mid" : "low";
    return '<div class="cps-progress"><div class="cps-progress__text"><span>' + esc(label) + "</span><b>" + done + "/" + total + '</b></div><div class="cps-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i data-tone="' + tone + '" style="width:' + pct + '%"></i></div></div>';
  }

  function action(kind, row, label, iconName, extra) {
    return '<button type="button" class="cps-action' + (extra || "") + '" data-plan-action="' + kind + '" data-id="' + row.id + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + icon(iconName) + "</button>";
  }

  function rowActions(plan, type, row, child) {
    var html = "";
    if (child) {
      html += '<button type="button" class="cps-link" data-plan-action="children" data-id="' + row.id + '">' + icon(child[1]) + "Ver " + esc(child[2]) + "</button>";
      if (plan.canEdit) html += action("create-child", row, "Crear " + SINGULAR[child[0]] + " aquí", "add", " is-add");
    }
    if (plan.canEdit && type !== "gestiones") {
      html += action("edit", row, "Editar", "edit");
      html += action("delete", row, "Eliminar", "delete", " is-danger");
    }
    return '<td class="cps-actions">' + html + "</td>";
  }

  function renderRow(plan, row) {
    var tab = plan.tab;
    if (tab === "gestiones") {
      return "<tr><td><strong class=\"cps-name\">" + esc(row.nombre) + "</strong>" + (row.definicion ? '<small class="cps-desc">' + esc(row.definicion) + "</small>" : "") + "</td>" +
        '<td class="cps-details">' + (row.alcance ? esc(row.alcance) : '<span class="cps-muted">—</span>') + "</td>" +
        '<td><span class="cps-count">' + row.objetivos_total + "</span></td>" +
        rowActions(plan, "gestiones", row, ["objetivos", "flag", "objetivos"]) + "</tr>";
    }
    if (tab === "objetivos") {
      var period = [row.periodo_tipo, row.periodo_valor].filter(Boolean).join(" ");
      return "<tr><td><strong class=\"cps-name\">" + esc(row.nombre) + "</strong><small>" +
        [row.valor_objetivo ? "Valor: " + esc(row.valor_objetivo) : "", period ? "Periodo: " + esc(period) : ""].filter(Boolean).join(" · ") + "</small></td>" +
        "<td>" + bar(row.metas_realizadas, row.metas_total, "Realizadas") + "</td><td>" + people(row.responsables) + "</td>" +
        rowActions(plan, "objetivos", row, ["metas", "target", "metas"]) + "</tr>";
    }
    if (tab === "metas") {
      var overdue = row.fecha_limite && row.fecha_limite < today() && row.estado !== "Realizado";
      var measure = "Actual " + esc(row.valor_actual) + " / Meta " + esc(row.valor_meta) + (row.unidad ? " " + esc(row.unidad) : "") + (row.cumplimiento != null ? " · " + row.cumplimiento + " %" : "");
      return "<tr><td><strong class=\"cps-name\">" + esc(row.nombre) + "</strong><span class=\"cps-line\">" + badge(row.estado) + "<small>" + measure + "</small></span></td>" +
        "<td>" + bar(row.tareas_completadas, row.tareas_total, "Completadas") + "</td>" +
        '<td><span class="cps-date' + (overdue ? " is-late" : "") + '">' + (row.fecha_limite ? icon(overdue ? "warning" : "event") + esc(day(row.fecha_limite)) : "—") + "</span></td>" +
        rowActions(plan, "metas", row, ["tareas", "checklist", "tareas"]) + "</tr>";
    }
    if (tab === "tareas" || tab === "mis_tareas") {
      var dates = row.fecha_inicio || row.fecha_fin ? icon("date_range") + esc(day(row.fecha_inicio) || "—") + " – " + esc(day(row.fecha_fin) || "—") : "";
      return "<tr><td><strong class=\"cps-name\">" + esc(row.nombre) + "</strong>" + (row.descripcion ? '<small class="cps-desc">' + esc(row.descripcion) + "</small>" : "") +
        (dates ? '<span class="cps-dates">' + dates + "</span>" : "") + "</td><td>" + badge(row.estado) + "</td>" +
        '<td><button type="button" class="cps-log-button" data-plan-action="log" data-id="' + row.id + '">' + icon("history_edu") + "Bitácora <b>" + (row.avances || []).length + "</b></button></td>" +
        "<td>" + people(row.responsables) + "</td>" + rowActions(plan, "tareas", row, ["campanas", "campaign", "campañas"]) + "</tr>";
    }
    if (tab === "campanas") {
      var details = [
        row.tipos_medio && row.tipos_medio.length ? "<b>Medios:</b> " + esc(row.tipos_medio.join(", ")) : "",
        row.tipos_mercadeo && row.tipos_mercadeo.length ? "<b>Mercadeo:</b> " + esc(row.tipos_mercadeo.join(", ")) : "",
        row.frecuencia ? "<b>Frecuencia:</b> " + esc(row.frecuencia) : "",
      ].filter(Boolean).join("<br>");
      return "<tr><td><strong class=\"cps-name\">" + esc(row.nombre) + "</strong>" + (row.tipo_campana ? '<span class="cps-tag">' + esc(row.tipo_campana) + "</span>" : "") +
        (row.pieza_actual ? '<small class="cps-desc">' + esc(row.pieza_actual) + "</small>" : "") + "</td>" +
        '<td class="cps-details">' + (details || '<span class="cps-muted">—</span>') + "</td>" +
        '<td><button type="button" class="cps-log-button" data-plan-action="log" data-id="' + row.id + '">' + icon("monitoring") + "Resultados <b>" + (row.resultados || []).length + "</b></button></td>" +
        "<td>" + people(row.responsables) + "</td>" + rowActions(plan, "campanas", row, null) + "</tr>";
    }
    return "";
  }

  function findRow(plan, id) {
    return plan.rows.filter(function (row) { return String(row.id) === String(id); })[0] || null;
  }

  /** context: {filter, title, label} al entrar con "Ver"; sin él (pestaña elegida a mano) se olvida el recorrido. */
  function switchTab(root, tab, context, keepTrail) {
    var plan = state(root);
    plan.tab = tab;
    plan.context = context || null;
    if (!context && !keepTrail) plan.trail = [];
    renderFilters(root);
    root.querySelector("[data-plan-list]").innerHTML = '<div class="ctl-empty">' + icon("hourglass_empty") + "<p>Cargando…</p></div>";
    return load(root);
  }

  // ── Formulario (gpe_abrir_modal del plugin) ───────────────────────────────

  function field(html, wide) { return '<div class="' + (wide ? "ctl-field ctl-full" : "ctl-field") + '">' + html + "</div>"; }
  function label(name, text, required) { return '<label class="ctl-label" for="cps-f-' + name + '">' + esc(text) + (required ? ' <b class="ctl-required" aria-hidden="true">*</b>' : "") + "</label>"; }

  function textInput(name, text, value, opts) {
    opts = opts || {};
    return field(label(name, text, opts.required) + '<span class="ctl-input"><input id="cps-f-' + name + '" name="' + name + '" type="' + (opts.type || "text") + '" value="' + esc(value) + '"' +
      (opts.required ? " required" : "") + (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : "") + (opts.inputmode ? ' inputmode="' + opts.inputmode + '"' : "") + ' maxlength="' + (opts.max || 2000) + '"></span>', opts.wide);
  }

  function textArea(name, text, value, opts) {
    opts = opts || {};
    return field(label(name, text, opts.required) + '<textarea class="cps-textarea" id="cps-f-' + name + '" name="' + name + '" rows="' + (opts.rows || 3) + '" maxlength="' + (opts.max || 8000) + '"' + (opts.required ? " required" : "") + ">" + esc(value) + "</textarea>", true);
  }

  /** options: [{value, label}]; si el valor guardado ya no está en la lista se agrega para no perderlo. */
  function select(name, text, options, value, opts) {
    opts = opts || {};
    value = value == null ? "" : String(value);
    var list = options.slice();
    if (value && !list.some(function (option) { return String(option.value) === value; })) list.push({ value: value, label: value + " (ya no está en la lista)" });
    return field(label(name, text, opts.required) + '<span class="ctl-input"><select id="cps-f-' + name + '" name="' + name + '"' + (opts.required ? " required" : "") + '><option value="">' + esc(opts.empty || "Selecciona…") + "</option>" +
      list.map(function (option) { return '<option value="' + esc(option.value) + '"' + (String(option.value) === value ? " selected" : "") + ">" + esc(option.label) + "</option>"; }).join("") + "</select></span>", opts.wide);
  }

  /** Selección múltiple con buscador (initMultiSelect del plugin): casillas, y "Todos" / "Ninguno". */
  function multi(name, text, options, values, opts) {
    opts = opts || {};
    values = (values || []).map(String);
    var list = options.slice();
    values.forEach(function (value) {
      if (!list.some(function (option) { return String(option.value) === value; })) list.push({ value: value, label: value });
    });
    return '<fieldset class="ctl-field ctl-full cps-multi" data-plan-multi="' + name + '"' + (opts.required ? ' data-required="1"' : "") + '><legend class="ctl-label">' + esc(text) + (opts.required ? ' <b class="ctl-required" aria-hidden="true">*</b>' : "") +
      ' <small data-plan-multi-count></small></legend><div class="cps-multi__tools"><input type="search" placeholder="Buscar…" data-plan-multi-search aria-label="Buscar en ' + esc(text) + '">' +
      '<button type="button" class="cps-link" data-plan-multi-all="1">Todos</button><button type="button" class="cps-link" data-plan-multi-all="0">Ninguno</button></div>' +
      '<div class="cps-multi__list">' + (list.length ? list.map(function (option) {
        return '<label class="cps-check"><input type="checkbox" name="' + name + '" value="' + esc(option.value) + '"' + (values.indexOf(String(option.value)) >= 0 ? " checked" : "") + "><span>" + esc(option.label) + "</span></label>";
      }).join("") : '<p class="ctl-note">No hay opciones en la lista.</p>') + "</div></fieldset>";
  }

  function formFields(plan, type, item) {
    var o = plan.options;
    var named = function (rows) { return (rows || []).map(function (row) { return { value: String(row.id), label: row.nombre }; }); };
    var plain = function (values) { return (values || []).map(function (value) { return { value: value, label: value }; }); };
    var names = function (key) { return catalog(plan, key).map(function (row) { return { value: row.valor, label: row.nombre }; }); };
    var ids = function (key) { return catalog(plan, key).map(function (row) { return { value: String(row.id), label: row.nombre }; }); };
    if (type === "objetivos") {
      return textArea("nombre_objetivo", "Objetivo", item.nombre, { required: true, rows: 2, max: 2000 }) +
        select("perspectiva", "Gestión (perspectiva)", names("gestiones"), item.perspectiva, { required: true }) +
        textInput("valor_objetivo", "Valor del objetivo", item.valor_objetivo, { max: 60, placeholder: "40%" }) +
        select("periodo_tipo", "Periodo", plain(o.periodos), item.periodo_tipo, { empty: "Sin periodo" }) +
        textInput("periodo_valor", "Valor del periodo", item.periodo_valor, { max: 40, placeholder: "2026" }) +
        multi("responsable_area", "Responsables", plain(o.funcionarios), item.responsables);
    }
    if (type === "metas") {
      var units = catalog(plan, "unidades").map(function (row) { return { value: row.valor, label: row.nombre + (row.valor && row.valor !== row.nombre ? " (" + row.valor + ")" : "") }; });
      return textArea("nombre_meta", "Meta (KPI)", item.nombre, { required: true, rows: 2, max: 2000 }) +
        select("id_objetivo", "Objetivo", named(o.objetivos), item.id_objetivo, { required: true, wide: true }) +
        textInput("valor_actual", "Valor actual", item.valor_actual, { inputmode: "decimal", max: 30, placeholder: "0" }) +
        textInput("valor_meta", "Valor de la meta", item.valor_meta, { inputmode: "decimal", max: 30, placeholder: "100" }) +
        select("unidad", "Unidad", units, item.unidad, { empty: "Sin unidad" }) +
        textInput("fecha_limite", "Fecha límite", item.fecha_limite, { type: "date" }) +
        select("estado", "Estado", plain(o.estados_meta), item.estado || "Sin realizar", { required: true });
    }
    if (type === "tareas") {
      return textArea("nombre_tarea", "Tarea", item.nombre, { required: true, rows: 2, max: 2000 }) +
        multi("id_meta", "Metas", named(o.metas), item.id_meta, { required: true }) +
        textArea("descripcion", "Descripción", item.descripcion, { rows: 3 }) +
        select("estado", "Estado", plain(o.estados_tarea), item.estado || "Pendiente", { required: true }) +
        textInput("fecha_inicio", "Fecha de inicio", item.fecha_inicio, { type: "date" }) +
        textInput("fecha_fin", "Fecha de fin", item.fecha_fin, { type: "date" }) +
        multi("responsable", "Responsables", plain(o.funcionarios), item.responsables) +
        textArea("recursos", "Recursos", item.recursos, { rows: 2 }) +
        multi("id_actor", "Actores", ids("actores"), item.id_actor);
    }
    if (type === "campanas") {
      return textInput("nombre_campana", "Campaña", item.nombre, { required: true, wide: true, max: 2000 }) +
        select("tipo_campana", "Tipo de campaña", names("tipos_campana"), item.tipo_campana, { required: true }) +
        select("frecuencia", "Frecuencia", names("frecuencias"), item.frecuencia, { empty: "Sin frecuencia" }) +
        multi("id_tarea", "Tareas", named(o.tareas), item.id_tarea) +
        multi("tipo_medio", "Tipos de medio", names("tipos_medio"), item.tipos_medio) +
        multi("tipo_mercadeo", "Tipos de mercadeo", names("tipos_mercadeo"), item.tipos_mercadeo) +
        multi("id_actor", "Actores", ids("actores"), item.id_actor) +
        multi("responsable", "Responsables", plain(o.funcionarios), item.responsables) +
        textArea("pieza_actual", "Pieza actual", item.pieza_actual, { rows: 3 });
    }
    return "";
  }

  function openForm(root, type, item, prefill) {
    var plan = state(root);
    var dialog = root.querySelector("[data-plan-form-dialog]");
    if (!dialog || !plan.canEdit) return;
    var editing = item && item.id;
    plan.form = { type: type, id: editing ? item.id : 0 };
    root.querySelector("[data-plan-form-title]").textContent = (editing ? "Editar " : "Crear ") + SINGULAR[type];
    root.querySelector("[data-plan-form-fields]").innerHTML = formFields(plan, type, Object.assign({}, item || {}, prefill || {}));
    root.querySelectorAll("[data-plan-multi]").forEach(countMulti);
    formError(root, "");
    dialog.showModal();
    var first = dialog.querySelector("textarea, input:not([type=search]), select");
    if (first) first.focus();
  }

  function formError(root, message) {
    var node = root.querySelector("[data-plan-form-error]");
    node.textContent = message || "";
    node.hidden = !message;
  }

  function collect(form) {
    var data = {};
    Array.prototype.forEach.call(form.querySelectorAll("input[name], select[name], textarea[name]"), function (input) {
      if (input.type === "checkbox") {
        if (!data[input.name]) data[input.name] = [];
        if (input.checked) data[input.name].push(input.value);
        return;
      }
      data[input.name] = input.value.trim();
    });
    return data;
  }

  function countMulti(box) {
    var count = box.querySelectorAll("input[type=checkbox]:checked").length;
    var node = box.querySelector("[data-plan-multi-count]");
    if (node) node.textContent = count ? "(" + count + ")" : "";
  }

  async function submitForm(root) {
    var plan = state(root);
    var form = root.querySelector("[data-plan-form]");
    if (!plan.form) return;
    for (var box of form.querySelectorAll('[data-plan-multi][data-required="1"]')) {
      if (!box.querySelector("input[type=checkbox]:checked")) {
        formError(root, "Elige al menos una opción en " + box.querySelector("legend").firstChild.textContent.trim() + ".");
        return;
      }
    }
    if (!form.reportValidity()) return;
    var submit = root.querySelector("[data-plan-form-submit]");
    submit.disabled = true;
    formError(root, "");
    try {
      var data = await request("commercial_plan_save", { tipo: plan.form.type, id: plan.form.id, data: JSON.stringify(collect(form)) });
      plan.options = data.options || plan.options;
      updateSummary(root, data.summary);
      root.querySelector("[data-plan-form-dialog]").close();
      renderFilters(root);
      status(root, "Se guardó " + (plan.form.type === "objetivos" ? "el " : "la ") + SINGULAR[plan.form.type] + ".");
      await load(root);
    } catch (error) {
      formError(root, error.message);
    } finally {
      submit.disabled = false;
    }
  }

  // ── Bitácoras (gpe_gestion_avances / gpe_gestion_resultados) ──────────────

  function openLog(root, row) {
    var plan = state(root);
    var isTask = plan.tab === "tareas" || plan.tab === "mis_tareas";
    var dialog = root.querySelector("[data-plan-log-dialog]");
    plan.log = { id: row.id, task: isTask };
    root.querySelector("[data-plan-log-title]").textContent = isTask ? "Bitácora de avances" : "Bitácora de resultados";
    root.querySelector("[data-plan-log-subject]").textContent = row.nombre;
    root.querySelector("[data-plan-log-label]").textContent = isTask ? "¿Qué se avanzó?" : "Resultado obtenido";
    var form = root.querySelector("[data-plan-log-form]");
    form.reset();
    form.hidden = !(plan.canEdit || row.mine);
    root.querySelector("[data-plan-log-finish]").hidden = !isTask || row.estado === "Completado";
    root.querySelector("[data-plan-log-author]").textContent = plan.user ? "Queda a nombre de " + plan.user + "." : "";
    logError(root, "");
    renderLog(root, isTask ? row.avances : row.resultados);
    dialog.showModal();
  }

  function renderLog(root, entries) {
    var list = root.querySelector("[data-plan-log-list]");
    entries = (entries || []).slice().reverse();
    list.innerHTML = entries.length ? entries.map(function (entry) {
      return '<li><div class="cps-log__meta"><span>' + esc(entry.fecha ? day(entry.fecha) + entry.fecha.slice(10, 16) : "Sin fecha") + "</span><b>" + esc(entry.funcionario || "") + "</b></div><p>" + esc(entry.descripcion) + "</p></li>";
    }).join("") : '<li class="cps-muted">Aún no hay registros.</li>';
  }

  function logError(root, message) {
    var node = root.querySelector("[data-plan-log-error]");
    node.textContent = message || "";
    node.hidden = !message;
  }

  async function submitLog(root) {
    var plan = state(root);
    var form = root.querySelector("[data-plan-log-form]");
    if (!plan.log || !form.reportValidity()) return;
    var button = form.querySelector("button[type=submit]");
    button.disabled = true;
    logError(root, "");
    try {
      var data = await request(plan.log.task ? "commercial_plan_progress" : "commercial_plan_result", {
        id: plan.log.id,
        descripcion: form.elements.descripcion.value.trim(),
        finalizar: plan.log.task && form.elements.finalizar.checked ? "1" : "0",
      });
      updateSummary(root, data.summary);
      await load(root);
      var row = findRow(plan, plan.log.id);
      form.reset();
      if (row) {
        renderLog(root, plan.log.task ? row.avances : row.resultados);
        root.querySelector("[data-plan-log-finish]").hidden = !plan.log.task || row.estado === "Completado";
      }
      status(root, data.message || "Registrado.");
    } catch (error) {
      logError(root, error.message);
    } finally {
      button.disabled = false;
    }
  }

  // ── Eliminar y glosario ───────────────────────────────────────────────────

  function openConfirm(root, type, row) {
    var plan = state(root);
    plan.confirm = { type: type, id: row.id };
    root.querySelector("[data-plan-confirm-text]").textContent = "¿Eliminar " + (type === "objetivos" ? "el " : "la ") + SINGULAR[type] + " «" + row.nombre + "»? Esta acción no se puede deshacer.";
    var error = root.querySelector("[data-plan-confirm-error]");
    error.hidden = true;
    root.querySelector("[data-plan-confirm-dialog]").showModal();
  }

  async function confirmDelete(root) {
    var plan = state(root);
    if (!plan.confirm) return;
    var button = root.querySelector("[data-plan-confirm-ok]");
    var error = root.querySelector("[data-plan-confirm-error]");
    button.disabled = true;
    try {
      var data = await request("commercial_plan_delete", { tipo: plan.confirm.type, id: plan.confirm.id });
      plan.options = data.options || plan.options;
      updateSummary(root, data.summary);
      root.querySelector("[data-plan-confirm-dialog]").close();
      renderFilters(root);
      status(root, data.message || "Eliminado.");
      await load(root);
    } catch (problem) {
      error.textContent = problem.message;
      error.hidden = false;
    } finally {
      button.disabled = false;
    }
  }

  function openGlossary(root) {
    var plan = state(root);
    root.querySelector("[data-plan-glossary-body]").innerHTML = GLOSSARY.map(function (entry) {
      var rows = catalog(plan, entry[0]);
      return "<h3>" + esc(entry[1]) + "</h3>" + (rows.length ? "<table><tbody>" + rows.map(function (row) {
        var text = row.definicion || (entry[0] === "unidades" ? "Símbolo: " + row.valor : "") || "Sin definición.";
        return "<tr><th>" + esc(row.nombre) + "</th><td>" + esc(text) + (row.alcance ? "<br><small><b>Alcance:</b> " + esc(row.alcance) + "</small>" : "") + "</td></tr>";
      }).join("") + "</tbody></table>" : '<p class="cps-muted">Sin elementos.</p>');
    }).join("");
    root.querySelector("[data-plan-glossary-dialog]").showModal();
  }

  // ── Eventos ───────────────────────────────────────────────────────────────

  document.addEventListener("click", function (event) {
    var root = event.target.closest && event.target.closest("[data-commercial-strategy]");
    if (!root) return;
    var plan = state(root);
    var close = event.target.closest("[data-plan-close]");
    if (close) {
      var dialog = close.closest("dialog");
      if (dialog) dialog.close();
      return;
    }
    var tab = event.target.closest("[data-plan-tab]");
    if (tab) {
      switchTab(root, tab.getAttribute("data-plan-tab"));
      return;
    }
    if (event.target.closest("[data-plan-back]")) {
      var previous = plan.trail.pop();
      if (previous) switchTab(root, previous.tab, previous.context, true);
      else switchTab(root, "gestiones");
      return;
    }
    var crumb = event.target.closest("[data-plan-crumb]");
    if (crumb) {
      var index = Number(crumb.getAttribute("data-plan-crumb"));
      var step = plan.trail[index];
      plan.trail = plan.trail.slice(0, index);
      if (step) switchTab(root, step.tab, step.context, true);
      return;
    }
    if (event.target.closest("[data-plan-new]")) {
      // Dentro de un padre ("Metas de …"), el registro nuevo ya queda en él.
      openForm(root, plan.tab === "mis_tareas" ? "tareas" : plan.tab, null, plan.context ? plan.context.prefill : null);
      return;
    }
    if (event.target.closest("[data-plan-glossary]")) {
      openGlossary(root);
      return;
    }
    var all = event.target.closest("[data-plan-multi-all]");
    if (all) {
      var box = all.closest("[data-plan-multi]");
      var on = all.getAttribute("data-plan-multi-all") === "1";
      box.querySelectorAll(".cps-check").forEach(function (item) {
        if (!item.hidden) item.querySelector("input").checked = on;
      });
      countMulti(box);
      return;
    }
    var button = event.target.closest("[data-plan-action]");
    if (!button) return;
    var row = findRow(plan, button.getAttribute("data-id"));
    if (!row) return;
    var kind = button.getAttribute("data-plan-action");
    var type = plan.tab === "mis_tareas" ? "tareas" : plan.tab;
    // [pestaña hija, filtro, valores del formulario nuevo, valor del filtro, título]
    var child = {
      gestiones: ["objetivos", "gestion", function (r) { return { perspectiva: r.nombre }; }, function (r) { return r.nombre; }, "Objetivos de la gestión"],
      objetivos: ["metas", "objetivo", function (r) { return { id_objetivo: String(r.id) }; }, function (r) { return String(r.id); }, "Metas del objetivo"],
      metas: ["tareas", "meta", function (r) { return { id_meta: [String(r.id)] }; }, function (r) { return String(r.id); }, "Tareas de la meta"],
      tareas: ["campanas", "tarea", function (r) { return { id_tarea: [String(r.id)] }; }, function (r) { return String(r.id); }, "Campañas de la tarea"],
    }[type];
    if (kind === "children" && child) {
      var filter = {};
      filter[child[1]] = child[3](row);
      plan.trail.push({ tab: plan.tab, context: plan.context });
      switchTab(root, child[0], { filter: filter, prefill: child[2](row), title: child[4], label: row.nombre });
    } else if (kind === "create-child" && child) {
      openForm(root, child[0], null, child[2](row));
    } else if (kind === "edit") {
      openForm(root, type, row, null);
    } else if (kind === "delete") {
      openConfirm(root, type, row);
    } else if (kind === "log") {
      openLog(root, row);
    }
  });

  document.addEventListener("change", function (event) {
    var root = event.target.closest && event.target.closest("[data-commercial-strategy]");
    if (!root) return;
    var filter = event.target.closest("[data-plan-filter]");
    if (filter) {
      var plan = state(root);
      plan.filters[plan.tab] = plan.filters[plan.tab] || {};
      plan.filters[plan.tab][filter.getAttribute("data-plan-filter")] = filter.value;
      load(root);
      return;
    }
    var box = event.target.closest("[data-plan-multi]");
    if (box) countMulti(box);
  });

  document.addEventListener("input", function (event) {
    var root = event.target.closest && event.target.closest("[data-commercial-strategy]");
    if (!root) return;
    if (event.target.matches("[data-plan-search]")) {
      var plan = state(root);
      plan.search = event.target.value.trim();
      window.clearTimeout(plan.timer);
      plan.timer = window.setTimeout(function () { load(root); }, 300);
      return;
    }
    if (event.target.matches("[data-plan-multi-search]")) {
      var text = event.target.value.trim().toLowerCase();
      event.target.closest("[data-plan-multi]").querySelectorAll(".cps-check").forEach(function (item) {
        item.hidden = text !== "" && item.textContent.toLowerCase().indexOf(text) < 0;
      });
    }
  });

  document.addEventListener("submit", function (event) {
    var root = event.target.closest && event.target.closest("[data-commercial-strategy]");
    if (!root) return;
    if (event.target.matches("[data-plan-form]")) {
      event.preventDefault();
      submitForm(root);
    } else if (event.target.matches("[data-plan-log-form]")) {
      event.preventDefault();
      submitLog(root);
    } else if (event.target.closest("[data-plan-confirm-dialog]")) {
      event.preventDefault();
      confirmDelete(root);
    }
  });

  function scan() {
    document.querySelectorAll("[data-commercial-strategy]").forEach(function (root) {
      if (root.__planReady) return;
      root.__planReady = true;
      renderFilters(root);
      load(root);
    });
  }

  scan();
  if (app) new MutationObserver(scan).observe(app, { childList: true, subtree: true });
})();
