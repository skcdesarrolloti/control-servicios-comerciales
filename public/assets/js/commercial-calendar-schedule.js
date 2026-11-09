/*
 * Agenda comercial dentro de la tarea: agendar citas vinculadas a la tarea en el calendario
 * de actividades, ver la agenda del responsable y sus eventos pendientes por cerrar.
 * Usa la misma API del calendario que el módulo "Calendario" (Mi calendario / equipo).
 */
(function () {
  "use strict";

  var app = document.getElementById("scm-app");
  var core = window.SCMAdminCore || {};
  if (!app) return;

  var runtime = {};
  try {
    runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}") || {};
  } catch (error) {
    runtime = {};
  }
  var config = runtime.config || {};
  var currentEmployeeId = String(config.calendar_current_employee_id || "").trim();
  var employees = Array.isArray(config.calendar_allowed_funcionarios) ? config.calendar_allowed_funcionarios : [];
  var baseUrl = String(runtime.ajaxUrl || "").replace(/\/api\.php$/, "");
  var form = window.SCMCommercialCalendarForm || {};
  var calendarApi = form.api;
  var rowsFrom = form.rowsFrom;

  function esc(value) {
    if (typeof core.escHtml === "function") return core.escHtml(value);
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function notify(type, message, title) {
    if (typeof core.scmNotify === "function") {
      core.scmNotify(type, message, title);
      return;
    }
    window.alert(message);
  }

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function dateKey(date) {
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
  }

  function addDays(date, days) {
    var copy = new Date(date.getTime());
    copy.setDate(copy.getDate() + days);
    return copy;
  }

  function timeLabel(value) {
    var match = String(value || "").match(/(\d{2}):(\d{2})/);
    return match ? match[1] + ":" + match[2] : "";
  }

  function dayLabel(key) {
    var parts = String(key || "").slice(0, 10).split("-");
    if (parts.length !== 3) return key;
    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return date.toLocaleDateString("es-CO", { weekday: "long", day: "numeric", month: "long" });
  }

  function employeeName(id) {
    id = String(id || "");
    var found = employees.find(function (row) { return String(row.id_empleado || "") === id; });
    return found ? String(found.nombre || "Funcionario") : "Funcionario";
  }

  function taskData(card) {
    var data = card ? card.dataset : {};
    return {
      pk: String(data.ticketPk || ""),
      ticket: String(data.ticket || data.ticketPk || ""),
      subject: String(data.asunto || ""),
      topic: String(data.tema || ""),
      requester: String(data.solicitante || ""),
      address: String(data.direccion || ""),
      property: String(data.inmueble || ""),
      employeeId: String(data.empleadoId || ""),
      employee: String(data.empleado || ""),
      commercialStatus: String(data.estadoComercial || ""),
    };
  }

  function defaultEmployeeId(task) {
    var ids = employees.map(function (row) { return String(row.id_empleado || ""); });
    if (task.employeeId && ids.indexOf(task.employeeId) !== -1) return task.employeeId;
    if (currentEmployeeId && ids.indexOf(currentEmployeeId) !== -1) return currentEmployeeId;
    return task.employeeId || currentEmployeeId || ids[0] || "";
  }

  function employeeEvents(employeeId, from, to, limit) {
    return calendarApi("filtrar_eventos_admin", {
      id_empleado: employeeId,
      fecha_inicio: from,
      fecha_fin: to,
      pagina: 1,
      limite: limit || 120,
    }).then(rowsFrom);
  }

  function isDone(row) {
    return String((row && row.estado) || "").toLowerCase() === "si";
  }

  function eventLineHtml(row, highlightTicket) {
    var linked = highlightTicket && String(row.id_ticket || "") === String(highlightTicket);
    return '<div class="flex items-start gap-2 px-2.5 py-2 rounded-xl border ' + (linked ? "border-amber-300 bg-amber-50" : "border-slate-200 bg-white") + ' text-left">' +
      '<i class="mt-1 inline-block w-2.5 h-2.5 rounded-full shrink-0" style="background:' + esc(row.color || "#1E3C76") + '"></i>' +
      '<div class="min-w-0"><strong class="block text-xs text-slate-800 truncate">' + esc(row.titulo || "Evento") + '</strong>' +
      '<span class="block text-[11px] text-slate-500">' + esc(timeLabel(row.fecha_inicio)) + (row.fecha_fin ? " - " + esc(timeLabel(row.fecha_fin)) : "") +
      (row.categoria ? " · " + esc(row.categoria) : "") + (isDone(row) ? " · Realizado" : "") + (linked ? " · Esta tarea" : "") + "</span></div></div>";
  }

  function employeeOptionsHtml(selected) {
    return employees.map(function (row) {
      var id = String(row.id_empleado || "");
      return id ? '<option value="' + esc(id) + '"' + (id === selected ? " selected" : "") + ">" + esc(row.nombre || "Funcionario #" + id) + "</option>" : "";
    }).join("");
  }

  function openSchedule(card) {
    var task = taskData(card);
    form.open({
      kind: "cita",
      task: task,
      title: "Cita · Tarea #" + task.ticket + (task.topic ? " · " + task.topic : ""),
      description: "Cita de la tarea comercial #" + task.ticket + (task.subject ? " (" + task.subject + ")" : "") + "." + (task.requester ? "\nSolicitante: " + task.requester + "." : "") + (task.property ? "\nInmueble: #" + task.property + "." : ""),
      date: dateKey(addDays(new Date(), 1)),
      employeeId: defaultEmployeeId(task),
      onSaved: function () {
        refreshCard(card);
        app.dispatchEvent(new CustomEvent("scm:task-updated", { bubbles: true }));
      },
    });
  }

  function openAgenda(card) {
    if (!window.Swal) return;
    var task = taskData(card);
    var employeeId = defaultEmployeeId(task);
    var from = dateKey(new Date());
    var to = dateKey(addDays(new Date(), 14));
    var calendarUrl = baseUrl + "/index.php?tab=calendario&subtab=" + (employeeId === currentEmployeeId ? "mine" : "team");
    window.Swal.fire({
      title: "",
      html: '<div class="text-left"><div class="flex flex-wrap items-end justify-between gap-3 mb-3">' +
        '<div><span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Agenda de los próximos 14 días</span>' +
        '<h3 class="text-lg font-bold text-[#061D49]">Calendario del funcionario</h3></div>' +
        '<label class="scm-seg-field min-w-[220px]"><span>Funcionario</span><select class="scm-select" data-commercial-agenda-employee>' + employeeOptionsHtml(employeeId) + "</select></label></div>" +
        '<div data-commercial-agenda-body class="space-y-3 max-h-[55vh] overflow-y-auto"><p class="scm-calendar-popup-agenda-empty">Cargando agenda...</p></div>' +
        '<a class="inline-flex items-center gap-1.5 mt-3 text-sm font-semibold text-[#1E3C76] hover:underline" href="' + esc(calendarUrl) + '" data-commercial-agenda-link>Abrir calendario comercial</a></div>',
      width: 760,
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: "scm-calendar-swal-popup" },
      didOpen: function () {
        var popup = window.Swal.getPopup();
        var select = popup.querySelector("[data-commercial-agenda-employee]");
        var body = popup.querySelector("[data-commercial-agenda-body]");
        var link = popup.querySelector("[data-commercial-agenda-link]");
        function load() {
          var selected = select.value;
          link.href = baseUrl + "/index.php?tab=calendario&subtab=" + (selected === currentEmployeeId ? "mine" : "team");
          body.innerHTML = '<p class="scm-calendar-popup-agenda-empty">Cargando agenda de ' + esc(employeeName(selected)) + "...</p>";
          employeeEvents(selected, from, to, 200).then(function (rows) {
            if (select.value !== selected) return;
            if (!rows.length) {
              body.innerHTML = '<p class="scm-calendar-popup-agenda-empty">Sin eventos programados en los próximos 14 días.</p>';
              return;
            }
            var groups = {};
            rows.forEach(function (row) {
              var key = String(row.fecha_inicio || "").slice(0, 10);
              (groups[key] = groups[key] || []).push(row);
            });
            body.innerHTML = Object.keys(groups).sort().map(function (key) {
              var dayRows = groups[key].sort(function (a, b) { return String(a.fecha_inicio).localeCompare(String(b.fecha_inicio)); });
              return '<section><p class="text-xs font-bold text-slate-600 capitalize mb-1">' + esc(dayLabel(key)) + " · " + dayRows.length + " evento(s)</p>" +
                '<div class="space-y-1.5">' + dayRows.map(function (row) { return eventLineHtml(row, task.pk); }).join("") + "</div></section>";
            }).join("");
          }).catch(function () {
            body.innerHTML = '<p class="scm-calendar-popup-agenda-empty">No se pudo consultar la agenda.</p>';
          });
        }
        select.addEventListener("change", load);
        load();
      },
    });
  }

  function openPending(card) {
    if (!window.Swal) return;
    var task = taskData(card);
    var employeeId = defaultEmployeeId(task);
    window.Swal.fire({
      title: "Pendientes de " + employeeName(employeeId),
      html: '<div data-commercial-pending-body class="text-left space-y-1.5 max-h-[55vh] overflow-y-auto"><p class="scm-calendar-popup-agenda-empty">Cargando eventos vencidos...</p></div>' +
        '<p class="text-xs text-slate-500 mt-3 text-left">Los eventos vencidos se cierran desde el calendario comercial (Mi calendario → Pendientes).</p>',
      width: 680,
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: "scm-calendar-swal-popup" },
      didOpen: function () {
        var body = window.Swal.getPopup().querySelector("[data-commercial-pending-body]");
        calendarApi("listar_pendientes_vencidos", { id_empleado: employeeId }).then(function (json) {
          var rows = rowsFrom(json);
          body.innerHTML = rows.length
            ? rows.map(function (row) {
              return '<div class="px-2.5 py-2 rounded-xl border border-rose-200 bg-rose-50"><strong class="block text-xs text-slate-800">' + esc(row.titulo || "Evento") + "</strong>" +
                '<span class="text-[11px] text-slate-600">' + esc(String(row.fecha_inicio || "").slice(0, 16)) + (row.categoria ? " · " + esc(row.categoria) : "") + (row.id_ticket ? " · Tarea #" + esc(row.id_ticket) : "") + "</span></div>";
            }).join("")
            : '<p class="scm-calendar-popup-agenda-empty">No tiene eventos vencidos sin cerrar.</p>';
        }).catch(function () {
          body.innerHTML = '<p class="scm-calendar-popup-agenda-empty">No se pudieron consultar los pendientes.</p>';
        });
      },
    });
  }

  // Resumen dentro de la tarjeta: próximas citas de esta tarea y pendientes vencidos del responsable.
  function refreshCard(card) {
    var task = taskData(card);
    var employeeId = defaultEmployeeId(task);
    var eventsWrap = card.querySelector("[data-commercial-calendar-task-events]");
    var countEl = card.querySelector("[data-commercial-calendar-pending-count]");
    if (task.pk && eventsWrap) {
      calendarApi("filtrar_eventos", { id_ticket: task.pk }).then(function (json) {
        var rows = rowsFrom(json).sort(function (a, b) { return String(a.fecha_inicio).localeCompare(String(b.fecha_inicio)); });
        eventsWrap.innerHTML = rows.length
          ? '<p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Citas agendadas de esta tarea</p>' + rows.slice(0, 4).map(function (row) {
            return '<div class="flex items-center justify-between gap-2 px-2.5 py-2 rounded-xl bg-amber-50 border border-amber-200 text-xs"><span class="font-semibold text-slate-800 truncate">' + esc(row.titulo || "Cita") + '</span><span class="shrink-0 text-slate-600">' + esc(String(row.fecha_inicio || "").slice(0, 16)) + "</span></div>";
          }).join("")
          : '<p class="text-[11px] text-slate-400">Esta tarea no tiene citas pendientes en el calendario.</p>';
      }).catch(function () {
        eventsWrap.innerHTML = "";
      });
    }
    if (employeeId && countEl) {
      calendarApi("listar_pendientes_vencidos", { id_empleado: employeeId }).then(function (json) {
        var total = rowsFrom(json).length;
        countEl.textContent = String(total);
        countEl.hidden = total <= 0;
      }).catch(function () {
        countEl.hidden = true;
      });
    }
  }

  function initCards(scope) {
    (scope || document).querySelectorAll("[data-commercial-calendar-task]").forEach(function (card) {
      if (card.getAttribute("data-commercial-calendar-ready") === "1") return;
      card.setAttribute("data-commercial-calendar-ready", "1");
      refreshCard(card);
    });
  }

  document.addEventListener("click", function (event) {
    var target = event.target && event.target.closest ? event.target : null;
    if (!target) return;
    var card = target.closest("[data-commercial-calendar-task]");
    if (!card) return;
    if (target.closest("[data-commercial-calendar-schedule]")) {
      event.preventDefault();
      openSchedule(card);
    } else if (target.closest("[data-commercial-calendar-agenda]")) {
      event.preventDefault();
      openAgenda(card);
    } else if (target.closest("[data-commercial-calendar-pending]")) {
      event.preventDefault();
      openPending(card);
    }
  });

  var caseModal = document.getElementById("commercial-case-modal");
  if (caseModal && typeof MutationObserver === "function") {
    new MutationObserver(function () { initCards(caseModal); }).observe(caseModal, { childList: true, subtree: true });
  }
  initCards(document);
})();
