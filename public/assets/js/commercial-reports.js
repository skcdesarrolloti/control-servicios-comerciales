/*
 * Reportes comerciales: Mis reportes, Consolidado y Cuentas de cobro. Mismo flujo que Reportes administrativos del panel
 * inmobiliario (control-servicios-inmobiliarios/public/assets/js/admin-administrative-reports.js).
 */
(function () {
  "use strict";

  var app = document.getElementById("scm-app");
  if (!app) return;
  var core = window.SCMAdminCore || {};
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}") || {}; } catch (error) { runtime = {}; }

  // Mismo diseño que las vistas de Inmuebles (CommercialDashboardView::renderPropertiesPage): tarjetas, píldoras y tokens del panel.
  var CARD = "bg-surface-container-lowest rounded-2xl border border-surface-container shadow-xs";
  var INPUT = "w-full px-3 py-2 rounded-xl border border-outline-variant/40 bg-surface-container-low text-on-surface text-xs focus:bg-white focus:border-primary focus:ring-1 focus:ring-primary transition-all";
  var PRIMARY = "inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 text-xs font-semibold shadow-xs transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed";
  var GHOST = "inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface text-[11px] font-semibold border border-outline-variant/30 transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed";
  var CHECK = "w-4 h-4 rounded border-outline-variant accent-[#1E3C76] cursor-pointer";
  var LABELS = {
    no_exportados: "Sin exportar", en_cobro: "En cobro", pagados: "Pagados", cuentas: "Mis cuentas",
    sin_exportar: "Sin exportar", no_pagados: "Pendientes de pago",
  };
  var STATE_ICONS = {
    no_exportados: "pending_actions", en_cobro: "hourglass_top", pagados: "paid", cuentas: "request_quote",
    sin_exportar: "pending_actions", no_pagados: "hourglass_top",
  };

  // Clases escritas completas para que Tailwind las incluya al compilar.
  var ICON_SIZES = { 15: "text-[15px]", 16: "text-[16px]", 17: "text-[17px]", 18: "text-[18px]", 20: "text-[20px]", 22: "text-[22px]", 32: "text-[32px]" };
  function icon(name, size) {
    return '<span class="material-symbols-outlined ' + (ICON_SIZES[size] || ICON_SIZES[16]) + '" aria-hidden="true">' + name + "</span>";
  }

  function esc(value) {
    return typeof core.escHtml === "function" ? core.escHtml(value) : String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function notify(type, message) {
    if (typeof core.scmNotify === "function") core.scmNotify(type, message);
    else window.alert(message);
  }

  function money(value) {
    return "$" + Number(value || 0).toLocaleString("es-CO");
  }

  function date(ts) {
    return Number(ts) > 0 ? new Date(Number(ts) * 1000).toLocaleDateString("es-CO") : "";
  }

  function body(action, fields) {
    var form = new FormData();
    form.set("action", action);
    form.set("nonce", runtime.nonce || "");
    Object.keys(fields || {}).forEach(function (key) { form.set(key, fields[key]); });
    return form;
  }

  function post(action, fields) {
    return fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: body(action, fields), credentials: "same-origin" })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la solicitud.");
        return json.data || {};
      });
  }

  function download(action, id, fallbackName) {
    var loader = window.SCMLoader;
    var pdf = /\.pdf$/.test(fallbackName);
    if (loader) loader.show({ icon: pdf ? "pdf" : undefined, title: pdf ? "Generando PDF" : "Generando Excel", detail: "Preparando la cuenta de cobro." });
    fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: body(action, { id: id }), credentials: "same-origin" }).then(function (response) {
      if (!response.ok || /json/.test(response.headers.get("Content-Type") || "")) throw new Error("No se pudo generar el archivo.");
      var match = /filename="([^"]+)"/.exec(response.headers.get("Content-Disposition") || "");
      return response.blob().then(function (blob) {
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = match ? match[1] : fallbackName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 2000);
        if (loader) loader.hide();
      });
    }).catch(function (error) {
      if (loader) loader.fail(error.message);
      else notify("error", error.message);
    });
  }

  function confirmAction(title, text, confirmLabel) {
    return window.Swal.fire({ title: title, text: text, icon: "question", showCancelButton: true, confirmButtonText: confirmLabel, cancelButtonText: "Cancelar" }).then(function (result) { return result.isConfirmed; });
  }

  // Estados como "Mis inmuebles por estado": píldoras con su conteo.
  function statesHtml(states, counts, current) {
    return '<nav class="bg-surface-container-lowest p-space-sm rounded-2xl shadow-sm flex flex-wrap items-center gap-2" role="tablist" aria-label="Estado de los reportes">' + states.map(function (state) {
      var active = state === current;
      var count = counts && counts[state] !== undefined ? Number(counts[state]).toLocaleString("es-CO") : "";
      return '<button type="button" role="tab" aria-selected="' + active + '" data-report-state="' + state + '" class="flex items-center gap-2 px-space-md py-2 rounded-xl font-label-md text-label-md transition-all cursor-pointer ' + (active ? "bg-inverse-surface text-on-secondary shadow-sm" : "bg-surface-container hover:bg-surface-container-high text-on-surface") + '">' +
        icon(STATE_ICONS[state] || "label", 17) + "<span>" + LABELS[state] + "</span>" +
        (count !== "" ? '<span class="px-2 py-0.5 rounded-full text-label-sm ' + (active ? "bg-primary-container text-on-surface font-bold" : "bg-surface-container-lowest text-secondary") + '">' + count + "</span>" : "") + "</button>";
    }).join("") + "</nav>";
  }

  function metric(iconName, label, value, tone) {
    return '<div class="' + CARD + ' p-5 flex items-center gap-4"><div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 ' + (tone || "bg-primary/10 text-primary") + '">' + icon(iconName, 22) + '</div><div><span class="block text-xs font-medium text-secondary">' + label + '</span><strong class="block text-xl font-bold text-on-surface">' + value + "</strong></div></div>";
  }

  function summaryHtml(summary) {
    return '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' + metric("receipt_long", "Reportes", Number(summary.count || 0).toLocaleString("es-CO")) + metric("payments", "Total", money(summary.total), "bg-emerald-100 text-emerald-800") + "</div>";
  }

  function emptyHtml(message) {
    return '<div class="' + CARD + ' rounded-3xl p-12 text-center space-y-3"><div class="w-16 h-16 rounded-2xl bg-surface-container text-secondary flex items-center justify-center mx-auto">' + icon("receipt_long", 32) + '</div><h3 class="text-base font-bold text-on-surface">' + esc(message) + '</h3><p class="text-xs text-secondary max-w-md mx-auto">Cambia el estado o limpia los filtros para ver otros resultados.</p></div>';
  }

  function tableHtml(headers, rows, empty, noun) {
    if (!rows.length) return emptyHtml(empty);
    return '<div class="space-y-3">' + (noun ? '<div class="text-xs text-secondary font-medium px-1">Mostrando <strong>' + rows.length + "</strong> " + noun + "</div>" : "") +
      '<div class="' + CARD + ' overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-xs text-left text-on-surface"><thead class="bg-surface-container-low"><tr class="text-[11px] uppercase tracking-wider text-secondary">' +
      headers.map(function (header) { return '<th class="py-3 px-4 font-semibold whitespace-nowrap">' + header + "</th>"; }).join("") + '</tr></thead><tbody class="divide-y divide-surface-container">' + rows.join("") + "</tbody></table></div></div></div>";
  }

  var LATE_BADGE = '<span class="ml-1.5 px-2 py-0.5 rounded-full bg-error-container text-on-error-container text-[11px] font-semibold whitespace-nowrap">Fuera de plazo</span>';
  var DASH = '<span class="text-secondary">—</span>';

  function deadlineLabel(day) {
    return new Date(day + "T12:00:00").toLocaleDateString("es-CO", { weekday: "long", day: "numeric", month: "long" });
  }

  function td(content, extra) {
    return '<td class="py-3 px-4 align-middle ' + (extra || "whitespace-nowrap") + '">' + content + "</td>";
  }

  function checkAll(kind) {
    return '<input type="checkbox" class="' + CHECK + '" data-check-all="' + kind + '" aria-label="Seleccionar todos">';
  }

  function reportRow(item, options) {
    return '<tr class="hover:bg-surface-container-low/60 transition-colors" data-report-row="' + item.id + '" data-valor="' + Number(item.valor) + '" data-late="' + (item.fuera_plazo ? 1 : 0) + '">' +
      (options.select ? td('<input type="checkbox" class="' + CHECK + '" data-report-check value="' + item.id + '" aria-label="Seleccionar reporte ' + item.id + '">') : "") +
      td('<span class="font-semibold text-primary">#' + item.id + "</span>") + td(esc(date(item.fecha)) + (item.fuera_plazo ? LATE_BADGE : "")) +
      td('<span class="px-2 py-0.5 rounded-full bg-surface-container text-on-surface text-[11px] font-semibold">' + esc(item.tipo) + "</span>") +
      (options.employee ? td(esc(item.funcionario)) : "") +
      td(item.id_ticket ? "#" + esc(item.id_ticket) : DASH) + td(item.id_inmueble ? esc(item.id_inmueble) : DASH) +
      td(options.edit ? '<input class="' + INPUT.replace("w-full", "w-28") + ' text-right" inputmode="numeric" data-report-value value="' + Number(item.valor) + '" aria-label="Valor del reporte ' + item.id + '">' : '<strong class="font-semibold">' + money(item.valor) + "</strong>") +
      td('<span class="text-secondary">' + esc(item.observacion) + "</span>", "min-w-[220px]") + "</tr>";
  }

  // Filtros en tarjeta, como los de Inmuebles: Filtrar y Limpiar al final.
  function filtersHtml(fields) {
    return '<form data-report-filters class="' + CARD + ' p-5"><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 gap-3 items-end">' + fields.map(function (field) {
      var control = field.options
        ? '<select class="' + INPUT + '" name="' + field.name + '"><option value="">Todos</option>' + field.options.map(function (option) { return '<option value="' + esc(option.id) + '">' + esc(option.nombre) + "</option>"; }).join("") + "</select>"
        : '<input class="' + INPUT + '" name="' + field.name + '" type="' + (field.type || "text") + '"' + (field.type === "date" ? "" : ' placeholder="' + field.label + '"') + ">";
      return '<label class="flex flex-col gap-1"><span class="text-[11px] font-semibold text-secondary">' + field.label + "</span>" + control + "</label>";
    }).join("") +
      '<div class="flex items-center gap-2"><button type="submit" class="flex-1 ' + PRIMARY + '">' + icon("filter_list") + "<span>Filtrar</span></button>" +
      '<button type="button" data-report-clear class="px-3 py-2 rounded-xl bg-surface-container-low hover:bg-surface-container text-secondary text-xs font-semibold transition-all cursor-pointer border border-outline-variant/30" title="Limpiar filtros" aria-label="Limpiar filtros">' + icon("restart_alt") + "</button></div></div></form>";
  }

  function actionBar(action, label, iconName) {
    return '<div class="' + CARD + ' p-4 flex flex-wrap items-center justify-between gap-3"><span class="inline-flex items-center gap-2 text-xs text-secondary font-medium">' + icon("checklist", 18) + '<span data-report-selection>Sin selección</span></span>' +
      '<button type="button" class="' + PRIMARY + '" data-report-action="' + action + '" disabled>' + icon(iconName) + "<span>" + label + "</span></button></div>";
  }

  function statusBadge(paid) {
    return paid ? '<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-semibold">Pagada</span>' : '<span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[11px] font-semibold">Pendiente</span>';
  }

  function accountRows(items, select, employee) {
    return items.map(function (account) {
      return '<tr class="hover:bg-surface-container-low/60 transition-colors">' + (select ? td(account.pagada ? "" : '<input type="checkbox" class="' + CHECK + '" data-account-check value="' + account.id + '" aria-label="Seleccionar cuenta ' + esc(account.numero) + '">') : "") +
        td('<span class="font-semibold text-primary">' + esc(account.numero) + "</span>") + td(esc(account.fecha)) + (employee ? td(esc(account.funcionario)) : "") + td(esc(account.fecha_inicio) + " a " + esc(account.fecha_fin)) + td(String(account.cantidad)) + td('<strong class="font-semibold">' + money(account.total) + "</strong>") +
        td(statusBadge(account.pagada) + (account.fuera_plazo ? LATE_BADGE : "")) +
        td('<div class="flex items-center gap-1.5"><button type="button" class="' + GHOST + '" data-account-open="' + account.id + '">' + icon("visibility", 15) + 'Ver</button><button type="button" class="' + GHOST + '" data-account-pdf="' + account.id + '" data-numero="' + esc(account.numero) + '">' + icon("picture_as_pdf", 15) + 'PDF</button><button type="button" class="' + GHOST + '" data-account-excel="' + account.id + '" data-numero="' + esc(account.numero) + '">' + icon("table_view", 15) + "Excel</button></div>") + "</tr>";
    });
  }

  function pagerHtml(data) {
    if (!(data.pages > 1)) return "";
    return '<div class="flex items-center justify-end gap-2"><button type="button" class="' + GHOST + '" data-report-page="' + (data.page - 1) + '"' + (data.page <= 1 ? " disabled" : "") + ">" + icon("chevron_left", 15) + 'Anterior</button><span class="text-xs text-secondary font-medium px-2">Página ' + data.page + " de " + data.pages + '</span><button type="button" class="' + GHOST + '" data-report-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? " disabled" : "") + ">Siguiente" + icon("chevron_right", 15) + "</button></div>";
  }

  var ACCOUNT_HEADERS = ["N° cuenta", "Fecha", "Período", "Reportes", "Total", "Estado", "Acciones"];

  function boot(root) {
    if (root.getAttribute("data-report-ready") === "1") return;
    root.setAttribute("data-report-ready", "1");
    var sub = root.getAttribute("data-sub");
    var content = root.querySelector("[data-report-content]");
    var state = sub === "mis_reportes" ? "no_exportados" : "sin_exportar";
    var page = 1;
    var filters = {};

    function checked(selector) {
      return Array.prototype.slice.call(root.querySelectorAll(selector + ":checked"));
    }

    function setCount(total, noun) {
      var badge = root.querySelector("[data-report-count]");
      if (!badge) return;
      badge.textContent = Number(total || 0).toLocaleString("es-CO") + " " + noun;
      badge.hidden = false;
    }

    function refreshSelection() {
      var button = root.querySelector("[data-report-action]");
      var label = root.querySelector("[data-report-selection]");
      if (!button || !label) return;
      var boxes = checked("[data-report-check], [data-account-check]");
      var total = boxes.reduce(function (sum, box) { var row = box.closest("[data-report-row]"); return sum + (row ? Number(row.getAttribute("data-valor")) : 0); }, 0);
      button.disabled = !boxes.length;
      label.textContent = boxes.length ? boxes.length + " seleccionado(s)" + (total ? " · " + money(total) : "") : "Sin selección";
    }

    function renderOwn(data) {
      if (state === "cuentas") {
        content.innerHTML = statesHtml(["no_exportados", "en_cobro", "pagados", "cuentas"], data.counts, state) + tableHtml(ACCOUNT_HEADERS, accountRows(data.accounts, false, false), "Todavía no tienes cuentas de cobro.", "cuentas");
        setCount(data.accounts.length, "cuentas");
        return;
      }
      var canBill = state === "no_exportados";
      content.innerHTML = statesHtml(["no_exportados", "en_cobro", "pagados", "cuentas"], data.counts, state) + summaryHtml(data.summary) +
        filtersHtml([{ name: "consecutivo", label: "Consecutivo" }, { name: "tipo", label: "Tipo" }, { name: "ticket", label: "Tarea" }, { name: "inmueble", label: "Inmueble" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }]) +
        (state === "no_exportados" ? '<div class="flex items-start gap-3 p-4 rounded-2xl bg-tertiary-fixed/40 border border-tertiary-fixed text-sm text-on-surface" data-report-deadline>' + icon("event", 20) + "<p>Envía tu cuenta a gerencia a más tardar el <strong>" + esc(deadlineLabel(data.deadline)) + "</strong>." + (data.items.some(function (item) { return item.fuera_plazo; }) ? ' <span class="text-error font-medium">Tienes reportes fuera de plazo: puedes cobrarlos, pero la cuenta quedará marcada.</span>' : "") + "</p></div>" : "") +
        (canBill && data.items.length ? actionBar("create", "Crear cuenta de cobro", "request_quote") : "") +
        tableHtml((canBill ? [checkAll("report")] : []).concat(["Consecutivo", "Fecha", "Tipo", "Tarea", "Inmueble", "Valor", "Observación"]), data.items.map(function (item) { return reportRow(item, { select: canBill }); }), "No hay reportes en este estado.", "reportes");
      setCount(data.summary.count, "reportes");
      restoreFilters();
    }

    function renderConsolidated(data) {
      var canPay = state === "no_pagados" && data.can_pay;
      var canEdit = state === "sin_exportar" && data.can_edit;
      content.innerHTML = statesHtml(["sin_exportar", "no_pagados", "pagados"], data.counts, state) + summaryHtml(data.summary) +
        filtersHtml([{ name: "consecutivo", label: "Consecutivo" }, { name: "funcionario", label: "Funcionario", options: data.employees }, { name: "tipo", label: "Tipo" }, { name: "ticket", label: "Tarea" }, { name: "inmueble", label: "Inmueble" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }]) +
        (canPay && data.items.length ? actionBar("pay", "Marcar como pagados", "paid") : "") +
        tableHtml((canPay ? [checkAll("report")] : []).concat(["Consecutivo", "Fecha", "Tipo", "Funcionario", "Tarea", "Inmueble", "Valor", "Observación"]), data.items.map(function (item) { return reportRow(item, { select: canPay, edit: canEdit, employee: true }); }), "No hay reportes en este estado.", "reportes") +
        pagerHtml(data);
      setCount(data.summary.count, "reportes");
      restoreFilters();
    }

    function renderAccounts(data) {
      content.innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' + metric("hourglass_top", "Pendiente de pago", money(data.totals.pendiente), "bg-amber-100 text-amber-800") + metric("paid", "Pagado", money(data.totals.pagado), "bg-emerald-100 text-emerald-800") + "</div>" +
        filtersHtml([{ name: "numero", label: "N° cuenta" }, { name: "funcionario", label: "Funcionario" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }, { name: "estado", label: "Estado", options: [{ id: "pendiente", nombre: "Pendiente" }, { id: "pagada", nombre: "Pagada" }] }]) +
        (data.can_pay && data.items.length ? actionBar("pay-accounts", "Marcar cuentas como pagadas", "paid") : "") +
        tableHtml((data.can_pay ? [checkAll("account")] : []).concat(["N° cuenta", "Fecha", "Funcionario", "Período", "Reportes", "Total", "Estado", "Acciones"]), accountRows(data.items, data.can_pay, true), "No hay cuentas de cobro comerciales.", "cuentas");
      setCount(data.items.length, "cuentas");
      restoreFilters();
    }

    function restoreFilters() {
      var form = content.querySelector("[data-report-filters]");
      if (!form) return;
      Object.keys(filters).forEach(function (name) { if (form.elements[name]) form.elements[name].value = filters[name]; });
    }

    function load() {
      content.setAttribute("aria-busy", "true");
      content.classList.add("opacity-60");
      var action = sub === "mis_reportes" ? "commercial_reports_own" : (sub === "reportes_consolidado" ? "commercial_reports_consolidated" : "commercial_reports_accounts");
      var fields = Object.assign({}, filters, { state: state === "cuentas" ? "no_exportados" : state, page: page });
      return post(action, fields).then(function (data) {
        if (sub === "mis_reportes") renderOwn(data);
        else if (sub === "reportes_consolidado") renderConsolidated(data);
        else renderAccounts(data);
      }).catch(function (error) {
        content.innerHTML = '<div class="' + CARD + ' rounded-3xl p-10 text-center space-y-2"><span class="material-symbols-outlined text-[32px] text-error" aria-hidden="true">error</span><p class="text-sm font-semibold text-on-surface">' + esc(error.message) + "</p></div>";
      }).finally(function () { content.removeAttribute("aria-busy"); content.classList.remove("opacity-60"); });
    }

    function openAccount(id) {
      post("commercial_reports_account", { id: id }).then(function (data) {
        var account = data.account;
        window.Swal.fire({
          title: "Cuenta " + account.numero,
          width: 860,
          showConfirmButton: false,
          showCloseButton: true,
          customClass: { popup: "scm-calendar-swal-popup" },
          html: '<div class="text-left space-y-3"><p class="text-sm text-secondary">' + esc(account.funcionario) + " · " + esc(account.fecha_inicio) + " a " + esc(account.fecha_fin) + " · <strong>" + money(account.total) + "</strong></p>" +
            tableHtml(["Consecutivo", "Fecha", "Tipo", "Tarea", "Inmueble", "Valor", "Observación"], account.reportes.map(function (item) { return reportRow(item, {}); }), "Sin reportes.") + "</div>",
        });
      }).catch(function (error) { notify("error", error.message); });
    }

    function runAction(type) {
      var boxes = checked("[data-report-check], [data-account-check]");
      if (!boxes.length) return;
      var ids = JSON.stringify(boxes.map(function (box) { return box.value; }));
      var total = boxes.reduce(function (sum, box) { var row = box.closest("[data-report-row]"); return sum + (row ? Number(row.getAttribute("data-valor")) : 0); }, 0);
      var late = boxes.filter(function (box) { var row = box.closest("[data-report-row]"); return row && row.getAttribute("data-late") === "1"; }).length;
      var config = {
        create: ["Crear cuenta de cobro", "Se incluirán " + boxes.length + " reporte(s) por " + money(total) + ". Después no se podrán cambiar." + (late ? " " + late + " reporte(s) están fuera de plazo y la cuenta quedará marcada." : ""), "Crear cuenta", "commercial_reports_account_create"],
        pay: ["Marcar como pagados", "Se marcarán " + boxes.length + " reporte(s) como pagados por " + money(total) + ".", "Marcar pagados", "commercial_reports_pay_reports"],
        "pay-accounts": ["Pagar cuentas de cobro", "Se pagarán " + boxes.length + " cuenta(s) y todos sus reportes.", "Marcar pagadas", "commercial_reports_pay_accounts"],
      }[type];
      confirmAction(config[0], config[1], config[2]).then(function (ok) {
        if (!ok) return null;
        return post(config[3], { ids: ids }).then(function (data) {
          notify("success", data.message);
          return load();
        });
      }).catch(function (error) { notify("error", error.message); });
    }

    root.addEventListener("click", function (event) {
      var target = event.target.closest("button");
      if (!target) return;
      if (target.hasAttribute("data-report-clear")) {
        filters = {};
        page = 1;
        load();
      } else if (target.hasAttribute("data-report-state")) {
        state = target.getAttribute("data-report-state");
        page = 1;
        load();
      } else if (target.hasAttribute("data-report-page")) {
        page = Number(target.getAttribute("data-report-page"));
        load();
      } else if (target.hasAttribute("data-report-action")) {
        runAction(target.getAttribute("data-report-action"));
      } else if (target.hasAttribute("data-account-open")) {
        openAccount(target.getAttribute("data-account-open"));
      } else if (target.hasAttribute("data-account-pdf")) {
        download("commercial_reports_account_pdf", target.getAttribute("data-account-pdf"), "cuenta_cobro.pdf");
      } else if (target.hasAttribute("data-account-excel")) {
        download("commercial_reports_account_excel", target.getAttribute("data-account-excel"), "cuenta_cobro.xls");
      }
    });
    root.addEventListener("change", function (event) {
      if (event.target.matches("[data-check-all]")) {
        var selector = event.target.getAttribute("data-check-all") === "account" ? "[data-account-check]" : "[data-report-check]";
        root.querySelectorAll(selector).forEach(function (box) { box.checked = event.target.checked; });
      }
      if (event.target.matches("[data-report-check], [data-account-check], [data-check-all]")) refreshSelection();
      if (event.target.matches("[data-report-value]")) {
        var row = event.target.closest("[data-report-row]");
        post("commercial_reports_value", { id: row.getAttribute("data-report-row"), valor: event.target.value }).then(function (data) {
          row.setAttribute("data-valor", data.valor);
          event.target.value = data.valor;
          notify("success", data.message);
        }).catch(function (error) {
          event.target.value = row.getAttribute("data-valor");
          notify("error", error.message);
        });
      }
    });
    root.addEventListener("submit", function (event) {
      if (!event.target.matches("[data-report-filters]")) return;
      event.preventDefault();
      filters = {};
      new FormData(event.target).forEach(function (value, key) { if (value) filters[key] = value; });
      page = 1;
      load();
    });
    load();
  }

  function scan() {
    app.querySelectorAll("[data-commercial-reports]").forEach(boot);
  }

  new MutationObserver(scan).observe(app, { childList: true, subtree: true });
  scan();
})();
