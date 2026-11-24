/*
 * Agenda comercial dentro de la tarea: agendar citas vinculadas a la tarea en el calendario
 * de actividades (en la agenda del asignado), ver la semana del calendario propio o, con permiso
 * de equipo, la del asignado, y los eventos pendientes por cerrar del asignado.
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
  // Con "Calendario del equipo" se puede leer la agenda de otro funcionario; sin él, solo la propia.
  var canSeeTeam = !!config.calendar_can_assign_others;
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

  // La agenda, los pendientes y las citas de la tarea son siempre del funcionario asignado.
  function assigneeId(task) {
    return task.employeeId || "";
  }

  function assigneeName(task) {
    return task.employee || employeeName(task.employeeId);
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

  function openSchedule(card) {
    var task = taskData(card);
    form.open({
      kind: "cita",
      task: task,
      description: "Cita de la tarea comercial #" + task.ticket + (task.subject ? " (" + task.subject + ")" : "") + "." + (task.requester ? "\nSolicitante: " + task.requester + "." : "") + (task.property ? "\nInmueble: #" + task.property + "." : ""),
      date: dateKey(addDays(new Date(), 1)),
      employeeId: assigneeId(task),
      onSaved: function () {
        refreshCard(card);
        app.dispatchEvent(new CustomEvent("scm:task-updated", { bubbles: true }));
      },
    });
  }

  var WEEKDAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];

  function weekStart(date) {
    var copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    copy.setDate(copy.getDate() - ((copy.getDay() + 6) % 7));
    return copy;
  }

  function weekLabel(start) {
    var end = addDays(start, 6);
    var month = function (date) { return date.toLocaleDateString("es-CO", { month: "short" }).replace(".", ""); };
    return start.getDate() + (start.getMonth() !== end.getMonth() ? " " + month(start) : "") + " – " + end.getDate() + " " + month(end) + " " + end.getFullYear();
  }

  /** Calendarios que la persona puede ver desde la tarea: el propio y, con permiso de equipo, el del asignado. */
  function agendaOwners(task) {
    var owners = [];
    if (currentEmployeeId) owners.push({ id: currentEmployeeId, label: "Mi calendario", name: employeeName(currentEmployeeId) });
    var assignee = assigneeId(task);
    if (canSeeTeam && assignee && assignee !== currentEmployeeId) {
      owners.push({ id: assignee, label: assigneeName(task) + " · asignado", name: assigneeName(task) });
    }
    return owners;
  }

  function agendaChipHtml(row, ticketPk) {
    var linked = ticketPk && String(row.id_ticket || "") === String(ticketPk);
    var reminder = String(row.tipo_item || "") === "recordatorio";
    var start = timeLabel(row.fecha_inicio);
    var end = reminder ? "" : timeLabel(row.fecha_fin);
    var time = start + (end && end !== start ? " – " + end : "");
    var meta = [row.categoria, linked ? "Esta tarea" : "", isDone(row) ? "Realizado" : ""].filter(Boolean).join(" · ");
    return '<button type="button" class="scm-task-agenda-chip' + (linked ? " is-task" : "") + (isDone(row) ? " is-done" : "") + '" style="--chip:' + esc(row.color || "#1E3C76") + '" data-agenda-event="' + esc(row.id) + '" aria-label="Ver detalle de ' + esc(row.titulo || "Evento") + '">' +
      '<span class="scm-task-agenda-chip-time">' + esc(time) + (reminder ? " · Recordatorio" : "") + "</span>" +
      "<strong>" + esc(row.titulo || "Evento") + "</strong>" +
      (meta ? "<small>" + esc(meta) + "</small>" : "") +
      "</button>";
  }

  function longDate(value) {
    var parts = String(value || "").slice(0, 10).split("-");
    if (parts.length !== 3) return "";
    return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).toLocaleDateString("es-CO", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
  }

  function eventStatus(row) {
    if (isDone(row)) return { label: "Realizado", tone: "is-done" };
    var end = String(row.fecha_fin || row.fecha_inicio || "").replace(" ", "T");
    return end && new Date(end) < new Date() ? { label: "Vencido sin cerrar", tone: "is-late" } : { label: "Pendiente", tone: "is-open" };
  }

  /** Detalle de un evento dentro de la misma ventana, para no perder la semana ni la tarea. */
  function agendaDetailHtml(row, ticketPk, ticketNumber) {
    var reminder = String(row.tipo_item || "") === "recordatorio";
    var start = timeLabel(row.fecha_inicio);
    var end = reminder ? "" : timeLabel(row.fecha_fin);
    var linked = ticketPk && String(row.id_ticket || "") === String(ticketPk);
    var status = eventStatus(row);
    var item = function (icon, label, value) {
      return value ? '<div class="scm-task-agenda-detail-row"><span class="material-symbols-outlined" aria-hidden="true">' + icon + "</span><div><small>" + label + "</small><p>" + value + "</p></div></div>" : "";
    };
    return '<button type="button" class="scm-task-agenda-back" data-agenda-back><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Volver a la semana</button>' +
      '<article class="scm-task-agenda-detail" style="--chip:' + esc(row.color || "#1E3C76") + '">' +
      '<header><span class="scm-task-agenda-detail-cat">' + esc(reminder ? "Recordatorio" : row.categoria || "Evento") + "</span>" +
      "<h4>" + esc(row.titulo || "Evento") + "</h4>" +
      '<span class="scm-task-agenda-detail-status ' + status.tone + '">' + esc(status.label) + "</span>" +
      (linked ? '<span class="scm-task-agenda-detail-status is-task">Esta tarea</span>' : "") + "</header>" +
      '<div class="scm-task-agenda-detail-grid">' +
      item("calendar_today", "Fecha", esc(longDate(row.fecha_inicio))) +
      item("schedule", "Hora", esc(start + (end && end !== start ? " – " + end : ""))) +
      item("location_on", "Ubicación", esc(row.ubicacion || "")) +
      item("person", "Funcionario", esc(row.funcionario || employeeName(row.id_empleado))) +
      // id_ticket del evento es el _ID interno: solo se muestra el número visible cuando es esta tarea.
      item("link", "Tarea relacionada", linked ? "Tarea #" + esc(ticketNumber) + " (esta tarea)" : (row.id_ticket && String(row.id_ticket) !== "0" ? "Otra tarea comercial" : "")) +
      "</div>" +
      (String(row.descripcion || "").trim() ? '<div class="scm-task-agenda-detail-desc"><small>Descripción</small><p>' + esc(String(row.descripcion).trim()) + "</p></div>" : "") +
      "</article>";
  }

  function agendaWeekHtml(start, rows, ticketPk) {
    var today = dateKey(new Date());
    var byDay = {};
    rows.forEach(function (row) {
      var key = String(row.fecha_inicio || "").slice(0, 10);
      (byDay[key] = byDay[key] || []).push(row);
    });
    var days = [];
    for (var i = 0; i < 7; i++) {
      var date = addDays(start, i);
      var key = dateKey(date);
      var dayRows = (byDay[key] || []).sort(function (a, b) { return String(a.fecha_inicio).localeCompare(String(b.fecha_inicio)); });
      days.push('<section class="scm-task-agenda-day' + (key === today ? " is-today" : "") + (key < today ? " is-past" : "") + '">' +
        "<header><span>" + WEEKDAYS[i] + "</span><b>" + date.getDate() + "</b></header>" +
        '<div class="scm-task-agenda-day-body">' + (dayRows.length ? dayRows.map(function (row) { return agendaChipHtml(row, ticketPk); }).join("") : '<p class="scm-task-agenda-free">Libre</p>') + "</div></section>");
    }
    return days.join("");
  }

  // Calendario semanal: el propio y, para quien ve el equipo, también el del funcionario asignado a la tarea.
  function openAgenda(card) {
    if (!window.Swal) return;
    var task = taskData(card);
    var owners = agendaOwners(task);
    if (!owners.length) {
      notify("warning", "Tu usuario no tiene un funcionario vinculado en el calendario comercial.", "Agenda");
      return;
    }
    var state = { owner: owners[0], start: weekStart(new Date()), request: 0 };
    var tabs = owners.length > 1
      ? '<div class="scm-task-agenda-tabs" role="tablist">' + owners.map(function (owner, index) {
        return '<button type="button" role="tab" aria-selected="' + (index === 0) + '" data-agenda-owner="' + esc(owner.id) + '">' + esc(owner.label) + "</button>";
      }).join("") + "</div>"
      : "";
    window.Swal.fire({
      title: "",
      html: '<div class="scm-task-agenda">' +
        '<div class="scm-task-agenda-head"><div><span class="scm-task-agenda-kicker">Agenda · Tarea #' + esc(task.ticket) + "</span>" +
        "<h3 data-agenda-title></h3></div>" + tabs + "</div>" +
        '<div class="scm-task-agenda-toolbar"><div class="scm-task-agenda-nav">' +
        '<button type="button" data-agenda-move="-1" aria-label="Semana anterior"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button>' +
        '<button type="button" data-agenda-today>Hoy</button>' +
        '<button type="button" data-agenda-move="1" aria-label="Semana siguiente"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>' +
        '</div><strong data-agenda-range></strong><span class="scm-task-agenda-count" data-agenda-count></span></div>' +
        '<div class="scm-task-agenda-week" data-agenda-week aria-live="polite"></div>' +
        '<div class="scm-task-agenda-detail-wrap" data-agenda-detail hidden></div>' +
        '<div class="scm-task-agenda-foot"><span><i></i>Citas de esta tarea</span>' +
        '<a href="#" target="_blank" rel="noopener" data-agenda-link>Abrir calendario comercial</a></div></div>',
      width: 1080,
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: "scm-calendar-swal-popup scm-task-agenda-swal" },
      didOpen: function () {
        var popup = window.Swal.getPopup();
        var week = popup.querySelector("[data-agenda-week]");
        var count = popup.querySelector("[data-agenda-count]");
        var toolbar = popup.querySelector(".scm-task-agenda-toolbar");
        var detail = popup.querySelector("[data-agenda-detail]");
        var rows = [];
        function showWeek() {
          detail.hidden = true;
          detail.innerHTML = "";
          week.hidden = false;
          toolbar.hidden = false;
        }
        function showDetail(id) {
          var row = rows.find(function (item) { return String(item.id) === String(id); });
          if (!row) return;
          state.lastEvent = String(id);
          state.weekScroll = week.scrollTop;
          detail.innerHTML = agendaDetailHtml(row, task.pk, task.ticket);
          week.hidden = true;
          toolbar.hidden = true;
          detail.hidden = false;
          var back = detail.querySelector("[data-agenda-back]");
          if (back) back.focus();
        }
        function load() {
          var request = ++state.request;
          showWeek();
          rows = [];
          var owner = state.owner;
          popup.querySelector("[data-agenda-title]").textContent = owner.id === currentEmployeeId ? "Mi calendario" : "Calendario de " + owner.name;
          popup.querySelector("[data-agenda-range]").textContent = weekLabel(state.start);
          popup.querySelector("[data-agenda-link]").href = baseUrl + "/index.php?tab=calendario&subtab=" + (owner.id === currentEmployeeId ? "mine" : "team");
          popup.querySelectorAll("[data-agenda-owner]").forEach(function (button) {
            button.setAttribute("aria-selected", String(button.getAttribute("data-agenda-owner") === owner.id));
          });
          count.textContent = "Cargando…";
          week.classList.add("is-loading");
          week.innerHTML = agendaWeekHtml(state.start, [], "");
          employeeEvents(owner.id, dateKey(state.start), dateKey(addDays(state.start, 6)), 300).then(function (loaded) {
            if (request !== state.request) return;
            rows = loaded;
            week.classList.remove("is-loading");
            week.innerHTML = agendaWeekHtml(state.start, rows, task.pk);
            // En pantallas angostas los días van uno debajo del otro: se abre en el de hoy.
            var today = week.querySelector(".scm-task-agenda-day.is-today");
            if (today && window.matchMedia && window.matchMedia("(max-width: 900px)").matches) week.scrollTop = today.offsetTop - week.offsetTop;
            count.textContent = rows.length ? rows.length + (rows.length === 1 ? " evento" : " eventos") : "Semana libre";
          }).catch(function () {
            if (request !== state.request) return;
            week.classList.remove("is-loading");
            count.textContent = "";
            week.innerHTML = '<p class="scm-calendar-popup-agenda-empty">No se pudo consultar la agenda.</p>';
          });
        }
        popup.addEventListener("click", function (event) {
          var target = event.target.closest("[data-agenda-owner],[data-agenda-move],[data-agenda-today],[data-agenda-event],[data-agenda-back]");
          if (!target) return;
          if (target.hasAttribute("data-agenda-event")) {
            showDetail(target.getAttribute("data-agenda-event"));
            return;
          }
          if (target.hasAttribute("data-agenda-back")) {
            showWeek();
            week.scrollTop = state.weekScroll || 0;
            var chip = Array.prototype.find.call(week.querySelectorAll("[data-agenda-event]"), function (node) {
              return node.getAttribute("data-agenda-event") === state.lastEvent;
            });
            if (chip) chip.focus({ preventScroll: true });
            return;
          }
          if (target.hasAttribute("data-agenda-owner")) {
            var id = target.getAttribute("data-agenda-owner");
            state.owner = owners.find(function (owner) { return owner.id === id; }) || owners[0];
          } else if (target.hasAttribute("data-agenda-move")) {
            state.start = addDays(state.start, 7 * Number(target.getAttribute("data-agenda-move")));
          } else {
            state.start = weekStart(new Date());
          }
          load();
        });
        load();
      },
    });
  }

  function openPending(card) {
    if (!window.Swal) return;
    var task = taskData(card);
    var employeeId = assigneeId(task);
    if (!employeeId) {
      notify("warning", "La tarea no tiene un funcionario asignado.", "Pendientes");
      return;
    }
    window.Swal.fire({
      title: "Pendientes de " + assigneeName(task),
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
    var employeeId = assigneeId(task);
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
