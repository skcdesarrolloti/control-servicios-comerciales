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

  var INPUT = "px-2 py-1.5 rounded-lg border border-slate-300 text-sm";
  var PRIMARY = "px-3 py-2 rounded-lg bg-[#1E3C76] hover:bg-[#061D49] text-white text-sm font-semibold cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed";
  var LINK = "text-sm font-semibold text-[#1E3C76] hover:underline cursor-pointer";
  var LABELS = {
    no_exportados: "Sin exportar", en_cobro: "En cobro", pagados: "Pagados", cuentas: "Mis cuentas",
    sin_exportar: "Sin exportar", no_pagados: "Pendientes de pago",
  };

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
      });
    }).catch(function (error) { notify("error", error.message); });
  }

  function confirmAction(title, text, confirmLabel) {
    return window.Swal.fire({ title: title, text: text, icon: "question", showCancelButton: true, confirmButtonText: confirmLabel, cancelButtonText: "Cancelar" }).then(function (result) { return result.isConfirmed; });
  }

  function statesHtml(states, counts, current) {
    return '<div class="flex flex-wrap gap-1 p-1 bg-slate-100 rounded-xl w-fit" role="tablist">' + states.map(function (state) {
      var active = state === current;
      return '<button type="button" role="tab" aria-selected="' + active + '" data-report-state="' + state + '" class="px-3 py-1.5 rounded-lg text-sm font-semibold cursor-pointer ' + (active ? "bg-white text-[#061D49] shadow-sm" : "text-slate-600") + '">' + LABELS[state] + (counts && counts[state] !== undefined ? " (" + counts[state] + ")" : "") + "</button>";
    }).join("") + "</div>";
  }

  function summaryHtml(summary) {
    return '<div class="flex flex-wrap gap-6"><div><span class="block text-xs text-slate-500">Reportes</span><strong class="text-xl text-[#061D49]">' + Number(summary.count || 0).toLocaleString("es-CO") + '</strong></div><div><span class="block text-xs text-slate-500">Total</span><strong class="text-xl text-[#061D49]">' + money(summary.total) + "</strong></div></div>";
  }

  function tableHtml(headers, rows, empty) {
    if (!rows.length) return '<p class="text-sm text-slate-500 py-8 text-center">' + esc(empty) + "</p>";
    return '<div class="overflow-x-auto border border-slate-200 rounded-xl bg-white"><table class="w-full text-sm text-left"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">' +
      headers.map(function (header) { return '<th class="py-2 px-3 whitespace-nowrap">' + header + "</th>"; }).join("") + '</tr></thead><tbody class="divide-y divide-slate-100">' + rows.join("") + "</tbody></table></div>";
  }

  function td(content, extra) {
    return '<td class="py-2 px-3 ' + (extra || "whitespace-nowrap") + '">' + content + "</td>";
  }

  function reportRow(item, options) {
    return '<tr data-report-row="' + item.id + '" data-valor="' + Number(item.valor) + '">' +
      (options.select ? td('<input type="checkbox" data-report-check value="' + item.id + '" aria-label="Seleccionar reporte ' + item.id + '">') : "") +
      td("#" + item.id) + td(esc(date(item.fecha))) + td(esc(item.tipo)) +
      (options.employee ? td(esc(item.funcionario)) : "") +
      td(item.id_ticket ? "#" + esc(item.id_ticket) : "") + td(esc(item.id_inmueble)) +
      td(options.edit ? '<input class="' + INPUT + ' w-28 text-right" inputmode="numeric" data-report-value value="' + Number(item.valor) + '" aria-label="Valor del reporte ' + item.id + '">' : money(item.valor)) +
      td(esc(item.observacion), "min-w-[220px]") + "</tr>";
  }

  function filtersHtml(fields) {
    return '<form data-report-filters class="flex flex-wrap items-end gap-2">' + fields.map(function (field) {
      var control = field.options
        ? '<select class="' + INPUT + '" name="' + field.name + '"><option value="">Todos</option>' + field.options.map(function (option) { return '<option value="' + esc(option.id) + '">' + esc(option.nombre) + "</option>"; }).join("") + "</select>"
        : '<input class="' + INPUT + ' w-36" name="' + field.name + '" type="' + (field.type || "text") + '">';
      return '<label class="text-xs font-semibold text-slate-600 flex flex-col gap-1">' + field.label + control + "</label>";
    }).join("") + '<button class="' + PRIMARY + '">Filtrar</button></form>';
  }

  function accountRows(items, select, employee) {
    return items.map(function (account) {
      return "<tr>" + (select ? td(account.pagada ? "" : '<input type="checkbox" data-account-check value="' + account.id + '" aria-label="Seleccionar cuenta ' + esc(account.numero) + '">') : "") +
        td(esc(account.numero)) + td(esc(account.fecha)) + (employee ? td(esc(account.funcionario)) : "") + td(esc(account.fecha_inicio) + " a " + esc(account.fecha_fin)) + td(String(account.cantidad)) + td(money(account.total)) +
        td(account.pagada ? '<span class="text-emerald-700 font-semibold">Pagada</span>' : '<span class="text-amber-700 font-semibold">Pendiente</span>') +
        td('<button type="button" class="' + LINK + '" data-account-open="' + account.id + '">Ver</button> · <button type="button" class="' + LINK + '" data-account-pdf="' + account.id + '" data-numero="' + esc(account.numero) + '">PDF</button> · <button type="button" class="' + LINK + '" data-account-excel="' + account.id + '" data-numero="' + esc(account.numero) + '">Excel</button>') + "</tr>";
    });
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
        content.innerHTML = statesHtml(["no_exportados", "en_cobro", "pagados", "cuentas"], data.counts, state) + tableHtml(ACCOUNT_HEADERS, accountRows(data.accounts, false, false), "Todavía no tienes cuentas de cobro.");
        return;
      }
      var canBill = state === "no_exportados" && data.billing_enabled;
      content.innerHTML = statesHtml(["no_exportados", "en_cobro", "pagados", "cuentas"], data.counts, state) + summaryHtml(data.summary) +
        filtersHtml([{ name: "consecutivo", label: "Consecutivo" }, { name: "tipo", label: "Tipo" }, { name: "ticket", label: "Tarea" }, { name: "inmueble", label: "Inmueble" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }]) +
        (canBill ? '<div class="flex items-center gap-3"><button type="button" class="' + PRIMARY + '" data-report-action="create" disabled>Crear cuenta de cobro</button><span class="text-sm text-slate-500" data-report-selection>Sin selección</span></div>' : "") +
        (state === "no_exportados" && !data.billing_enabled ? '<p class="text-sm text-amber-700">Las cuentas de cobro comerciales todavía no están habilitadas.</p>' : "") +
        tableHtml((canBill ? [""] : []).concat(["Consecutivo", "Fecha", "Tipo", "Tarea", "Inmueble", "Valor", "Observación"]), data.items.map(function (item) { return reportRow(item, { select: canBill }); }), "No hay reportes en este estado.");
      restoreFilters();
    }

    function renderConsolidated(data) {
      var canPay = state === "no_pagados" && data.can_pay;
      var canEdit = state === "sin_exportar" && data.can_edit;
      content.innerHTML = statesHtml(["sin_exportar", "no_pagados", "pagados"], data.counts, state) + summaryHtml(data.summary) +
        filtersHtml([{ name: "consecutivo", label: "Consecutivo" }, { name: "funcionario", label: "Funcionario", options: data.employees }, { name: "tipo", label: "Tipo" }, { name: "ticket", label: "Tarea" }, { name: "inmueble", label: "Inmueble" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }]) +
        (canPay ? '<div class="flex items-center gap-3"><button type="button" class="' + PRIMARY + '" data-report-action="pay" disabled>Marcar como pagados</button><span class="text-sm text-slate-500" data-report-selection>Sin selección</span></div>' : "") +
        tableHtml((canPay ? [""] : []).concat(["Consecutivo", "Fecha", "Tipo", "Funcionario", "Tarea", "Inmueble", "Valor", "Observación"]), data.items.map(function (item) { return reportRow(item, { select: canPay, edit: canEdit, employee: true }); }), "No hay reportes en este estado.") +
        (data.pages > 1 ? '<div class="flex items-center gap-3 justify-end"><button type="button" class="' + LINK + '" data-report-page="' + (data.page - 1) + '"' + (data.page <= 1 ? " disabled" : "") + '>Anterior</button><span class="text-sm text-slate-500">Página ' + data.page + " de " + data.pages + '</span><button type="button" class="' + LINK + '" data-report-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? " disabled" : "") + ">Siguiente</button></div>" : "");
      restoreFilters();
    }

    function renderAccounts(data) {
      content.innerHTML = '<div class="flex flex-wrap gap-6"><div><span class="block text-xs text-slate-500">Pendiente de pago</span><strong class="text-xl text-[#061D49]">' + money(data.totals.pendiente) + '</strong></div><div><span class="block text-xs text-slate-500">Pagado</span><strong class="text-xl text-[#061D49]">' + money(data.totals.pagado) + "</strong></div></div>" +
        filtersHtml([{ name: "numero", label: "N° cuenta" }, { name: "funcionario", label: "Funcionario" }, { name: "desde", label: "Desde", type: "date" }, { name: "hasta", label: "Hasta", type: "date" }, { name: "estado", label: "Estado", options: [{ id: "pendiente", nombre: "Pendiente" }, { id: "pagada", nombre: "Pagada" }] }]) +
        (data.can_pay ? '<div class="flex items-center gap-3"><button type="button" class="' + PRIMARY + '" data-report-action="pay-accounts" disabled>Marcar cuentas como pagadas</button><span class="text-sm text-slate-500" data-report-selection>Sin selección</span></div>' : "") +
        tableHtml((data.can_pay ? [""] : []).concat(["N° cuenta", "Fecha", "Funcionario", "Período", "Reportes", "Total", "Estado", "Acciones"]), accountRows(data.items, data.can_pay, true), "No hay cuentas de cobro comerciales.");
      restoreFilters();
    }

    function restoreFilters() {
      var form = content.querySelector("[data-report-filters]");
      if (!form) return;
      Object.keys(filters).forEach(function (name) { if (form.elements[name]) form.elements[name].value = filters[name]; });
    }

    function load() {
      content.setAttribute("aria-busy", "true");
      var action = sub === "mis_reportes" ? "commercial_reports_own" : (sub === "reportes_consolidado" ? "commercial_reports_consolidated" : "commercial_reports_accounts");
      var fields = Object.assign({}, filters, { state: state === "cuentas" ? "no_exportados" : state, page: page });
      return post(action, fields).then(function (data) {
        if (sub === "mis_reportes") renderOwn(data);
        else if (sub === "reportes_consolidado") renderConsolidated(data);
        else renderAccounts(data);
      }).catch(function (error) {
        content.innerHTML = '<p class="text-sm text-rose-700 py-6">' + esc(error.message) + "</p>";
      }).finally(function () { content.removeAttribute("aria-busy"); });
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
          html: '<div class="text-left space-y-3"><p class="text-sm text-slate-600">' + esc(account.funcionario) + " · " + esc(account.fecha_inicio) + " a " + esc(account.fecha_fin) + " · <strong>" + money(account.total) + "</strong></p>" +
            tableHtml(["Consecutivo", "Fecha", "Tipo", "Tarea", "Inmueble", "Valor", "Observación"], account.reportes.map(function (item) { return reportRow(item, {}); }), "Sin reportes.") + "</div>",
        });
      }).catch(function (error) { notify("error", error.message); });
    }

    function runAction(type) {
      var boxes = checked("[data-report-check], [data-account-check]");
      if (!boxes.length) return;
      var ids = JSON.stringify(boxes.map(function (box) { return box.value; }));
      var total = boxes.reduce(function (sum, box) { var row = box.closest("[data-report-row]"); return sum + (row ? Number(row.getAttribute("data-valor")) : 0); }, 0);
      var config = {
        create: ["Crear cuenta de cobro", "Se incluirán " + boxes.length + " reporte(s) por " + money(total) + ". Después no se podrán cambiar.", "Crear cuenta", "commercial_reports_account_create"],
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
      if (target.hasAttribute("data-report-state")) {
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
      if (event.target.matches("[data-report-check], [data-account-check]")) refreshSelection();
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
