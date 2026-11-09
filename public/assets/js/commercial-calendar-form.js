/*
 * Formulario único del calendario comercial: cita con cliente, evento o recordatorio, desde el calendario o desde una tarea.
 * También el cierre de eventos con reporte comercial y el cliente de la API del calendario (public/calendar-api.php).
 */
(function () {
  "use strict";

  var app = document.getElementById("scm-app");
  var core = window.SCMAdminCore || {};
  if (!app) return;

  var DURATIONS = [15, 30, 45, 60, 90, 120, 180, 240];
  var OFFICES = ["Oficina Corredor", "Oficina Manga"];
  var TITLES = { cita: "Nueva cita con cliente", evento: "Nuevo evento", recordatorio: "Nuevo recordatorio" };
  var SUBTITLES = {
    cita: "Queda en la agenda del responsable y avisa al cliente si está vinculada a una tarea.",
    evento: "Visitas, captaciones, reuniones y actividades del equipo comercial.",
    recordatorio: "Aviso por WhatsApp a la persona responsable.",
  };

  function runtime() {
    try {
      return JSON.parse(app.getAttribute("data-scm-runtime") || "{}") || {};
    } catch (error) {
      return {};
    }
  }

  function config() {
    return runtime().config || {};
  }

  function esc(value) {
    if (typeof core.escHtml === "function") return core.escHtml(value);
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function notify(type, message, title) {
    if (typeof core.scmNotify === "function") core.scmNotify(type, message, title);
    else window.alert(message);
  }

  function api(action, payload) {
    var current = runtime();
    var options = { method: payload ? "POST" : "GET", credentials: "same-origin", headers: { "X-SCM-Nonce": current.nonce || "" } };
    if (payload) {
      options.headers["Content-Type"] = "application/json";
      options.body = JSON.stringify(payload);
    }
    return fetch(String(config().calendar_api_url || "") + encodeURIComponent(action), options).then(function (response) {
      return response.text().then(function (body) {
        try {
          return JSON.parse(body);
        } catch (error) {
          throw new Error("No se pudo confirmar la respuesta del calendario (HTTP " + response.status + "). Revisa el calendario antes de volver a guardar.");
        }
      });
    });
  }

  function rowsFrom(json) {
    if (!json || !json.success) return [];
    if (Array.isArray(json.data)) return json.data;
    return json.data && Array.isArray(json.data.data) ? json.data.data : [];
  }

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function dateKey(date) {
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
  }

  function minutesOf(time) {
    var parts = String(time || "").split(":");
    return parts.length >= 2 ? Number(parts[0]) * 60 + Number(parts[1]) : NaN;
  }

  function timeOf(minutes) {
    return pad(Math.floor(minutes / 60)) + ":" + pad(minutes % 60);
  }

  function timeLabel(value) {
    var match = String(value || "").match(/(\d{2}):(\d{2})/);
    return match ? match[1] + ":" + match[2] : "";
  }

  function durationLabel(minutes) {
    if (minutes < 60) return minutes + " min";
    var hours = Math.floor(minutes / 60);
    return hours + (hours === 1 ? " hora" : " horas") + (minutes % 60 ? " " + (minutes % 60) + " min" : "");
  }

  function employees() {
    return Array.isArray(config().calendar_allowed_funcionarios) ? config().calendar_allowed_funcionarios : [];
  }

  function employeeName(id) {
    var found = employees().find(function (row) { return String(row.id_empleado || "") === String(id); });
    return found ? String(found.nombre || "Funcionario") : "Funcionario";
  }

  function currentEmployeeId() {
    return String(config().calendar_current_employee_id || "").trim();
  }

  function loadCategories() {
    return api("listar_categorias").then(function (json) {
      var allowed = Array.isArray(runtime().calendarCategoryIds) ? runtime().calendarCategoryIds.map(String) : [];
      var rows = rowsFrom(json);
      return allowed.length ? rows.filter(function (row) { return allowed.indexOf(String(row.id)) !== -1; }) : rows;
    });
  }

  function citaCategoryId(categories) {
    var configured = String(config().calendar_cita_category_id || "");
    if (configured && categories.some(function (row) { return String(row.id) === configured; })) return configured;
    var match = categories.find(function (row) { return /cita con cliente/i.test(String(row.nombre || "")); });
    return match ? String(match.id) : "";
  }

  function ticketLabel(ticket) {
    return "#" + (ticket.id_ticket || ticket._ID) + (ticket.solicitante ? " · " + ticket.solicitante : "") + (ticket.inmueble ? " · Inmueble " + ticket.inmueble : "");
  }

  function field(label, control, extra) {
    return '<label class="scm-seg-field ' + (extra || "") + '"><span>' + label + "</span>" + control + "</label>";
  }

  function kindSelectorHtml(kinds, selected) {
    if (kinds.length < 2) return "";
    var labels = { cita: "Cita con cliente", evento: "Evento", recordatorio: "Recordatorio" };
    return '<div class="scm-cal-kinds" role="radiogroup" aria-label="Tipo">' + kinds.map(function (kind) {
      return '<label><input type="radio" name="kind" value="' + kind + '"' + (kind === selected ? " checked" : "") + "><span>" + labels[kind] + "</span></label>";
    }).join("") + "</div>";
  }

  function employeeControlHtml(options, selected) {
    if (options.multiple) {
      return '<fieldset class="scm-seg-field"><span>Funcionarios <b>*</b></span><div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-40 overflow-y-auto">' + employees().map(function (row) {
        var id = String(row.id_empleado || "");
        return id ? '<label class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-800 cursor-pointer"><input type="checkbox" name="empleados" value="' + esc(id) + '"' + (id === selected ? " checked" : "") + "><span>" + esc(row.nombre || id) + "</span></label>" : "";
      }).join("") + "</div></fieldset>";
    }
    if (options.lockEmployee) {
      return '<input type="hidden" name="id_empleado" value="' + esc(selected) + '">';
    }
    return field("Responsable <b>*</b>", '<select class="scm-select" name="id_empleado" required><option value="">Selecciona</option>' + employees().map(function (row) {
      var id = String(row.id_empleado || "");
      return id ? '<option value="' + esc(id) + '"' + (id === selected ? " selected" : "") + ">" + esc(row.nombre || id) + "</option>" : "";
    }).join("") + "</select>");
  }

  function durationOptionsHtml(current) {
    var values = DURATIONS.slice();
    if (values.indexOf(current) === -1) values.push(current);
    return values.sort(function (a, b) { return a - b; }).map(function (value) {
      return '<option value="' + value + '"' + (value === current ? " selected" : "") + ">" + durationLabel(value) + "</option>";
    }).join("");
  }

  function statusOptionsHtml(current) {
    var list = (Array.isArray(config().commercial_open_statuses) ? config().commercial_open_statuses : []).slice();
    if (current && list.indexOf(current) === -1) list.unshift(current);
    return list.map(function (status) {
      return '<option value="' + esc(status) + '"' + (status === current ? " selected" : "") + ">" + esc(status) + "</option>";
    }).join("");
  }

  function formHtml(options, categories, state) {
    var task = options.task;
    var start = options.start || "09:00";
    var duration = options.end && minutesOf(options.end) > minutesOf(start) ? minutesOf(options.end) - minutesOf(start) : 60;
    var taskRow = task
      ? '<p class="scm-cal-task"><span class="material-symbols-outlined" aria-hidden="true">link</span>Tarea #' + esc(task.ticket) + (task.requester ? " · " + esc(task.requester) : "") + "</p>"
      : '<div data-form-ticket>' + field("Tarea relacionada", '<select class="scm-select" name="id_ticket" data-form-ticket-select><option value="">Sin tarea</option></select>') + '<small class="scm-cal-hint" data-form-ticket-hint></small></div>';
    return '<div class="scm-calendar-create-shell scm-cal-shell">' +
      '<div class="scm-cal-head"><strong data-form-title>' + esc(TITLES[state.kind]) + '</strong><span data-form-subtitle>' + esc(SUBTITLES[state.kind]) + "</span></div>" +
      '<form class="scm-calendar-popup-form scm-calendar-create-form scm-cal-form" data-commercial-schedule-form autocomplete="off">' +
      kindSelectorHtml(state.kinds, state.kind) +
      field("Título <b>*</b>", '<input class="scm-input" name="titulo" required maxlength="255" value="' + esc(options.title || "") + '" placeholder="Se completa con la categoría">') +
      '<div class="scm-cal-grid">' +
      field("Categoría <b>*</b>", '<select class="scm-select" name="id_categoria" required><option value="">Selecciona categoría</option>' + categories.map(function (row) {
        return '<option value="' + esc(row.id) + '">' + esc(row.nombre) + "</option>";
      }).join("") + "</select>") +
      employeeControlHtml(options, state.employee) +
      "</div>" +
      '<div class="scm-cal-grid scm-cal-grid--3">' +
      field("Fecha", '<input class="scm-input" type="date" name="fecha" required value="' + esc(options.date || dateKey(new Date())) + '">') +
      field("Hora", '<input class="scm-input" type="time" name="hora_inicio" required value="' + esc(start) + '">') +
      field("Duración", '<select class="scm-select" name="duracion">' + durationOptionsHtml(duration) + "</select>", "scm-cal-only-event") +
      field("Avisar", '<select class="scm-select" name="aviso_minutos_antes"><option value="0">A la hora</option><option value="15">15 min antes</option><option value="30" selected>30 min antes</option><option value="60">1 hora antes</option></select>', "scm-cal-only-reminder") +
      "</div>" +
      '<div data-commercial-schedule-agenda aria-live="polite"></div>' +
      '<div class="scm-cal-only-event">' +
      field("Ubicación <b>*</b>", '<input class="scm-input" name="ubicacion" value="' + esc(task ? task.address : "") + '" placeholder="Dirección de la visita o lugar">') +
      '<div class="scm-cal-chips" data-form-places>' + OFFICES.map(function (place) { return '<button type="button" data-form-place="' + esc(place) + '">' + esc(place) + "</button>"; }).join("") + "</div>" +
      "</div>" +
      '<div class="scm-cal-only-event">' + taskRow + "</div>" +
      '<details class="scm-cal-more"><summary>Más opciones</summary><div class="scm-cal-more-body">' +
      field("Descripción", '<textarea class="scm-textarea" name="descripcion" rows="3">' + esc(options.description || "") + "</textarea>") +
      '<div data-form-status hidden>' + field("Estado comercial de la tarea", '<select class="scm-select" name="estado_comercial">' + statusOptionsHtml(task ? task.commercialStatus : "") + "</select>") + "</div>" +
      '<div class="scm-cal-only-repeat"><div class="scm-cal-grid">' +
      field("Repetir", '<select class="scm-select" name="repetir"><option value="">No se repite</option><option value="diario">Todos los días</option><option value="semanal">Algunos días de la semana</option></select>') +
      '<div data-form-repeat-end hidden>' + field("Hasta", '<input class="scm-input" type="date" name="repetir_hasta">') + "</div></div>" +
      '<div class="scm-cal-chips scm-cal-days" data-form-repeat-days hidden>' + [["1", "Lun"], ["2", "Mar"], ["3", "Mié"], ["4", "Jue"], ["5", "Vie"], ["6", "Sáb"], ["0", "Dom"]].map(function (day) {
        return '<label><input type="checkbox" name="dias" value="' + day[0] + '"><span>' + day[1] + "</span></label>";
      }).join("") + "</div></div>" +
      "</div></details></form></div>";
  }

  function selectedEmployees(form, options) {
    if (options.multiple) {
      return Array.prototype.slice.call(form.querySelectorAll('[name="empleados"]:checked')).map(function (input) { return input.value; });
    }
    var value = String(form.elements.id_empleado.value || "");
    return value ? [value] : [];
  }

  function currentKind(form, fallback) {
    var checked = form.querySelector('[name="kind"]:checked');
    return checked ? checked.value : fallback;
  }

  function dayEvents(employeeId, day) {
    return api("filtrar_eventos_admin", { id_empleado: employeeId, fecha_inicio: day, fecha_fin: day, pagina: 1, limite: 60 }).then(rowsFrom);
  }

  function isDone(row) {
    return String((row && row.estado) || "").toLowerCase() === "si";
  }

  function repeatDates(day, fd) {
    var mode = String(fd.get("repetir") || "");
    if (!mode) return [day];
    var until = String(fd.get("repetir_hasta") || "");
    if (!until || until < day) throw new Error("Indica hasta qué fecha se repite.");
    var weekdays = fd.getAll("dias").map(Number);
    if (mode === "semanal" && !weekdays.length) throw new Error("Selecciona al menos un día de la semana.");
    var dates = [];
    var current = new Date(day + "T00:00:00");
    var last = new Date(until + "T00:00:00");
    while (current <= last) {
      if (mode === "diario" || weekdays.indexOf(current.getDay()) !== -1) dates.push(dateKey(current));
      current.setDate(current.getDate() + 1);
    }
    if (!dates.length) throw new Error("Con esa configuración no queda ninguna fecha.");
    return dates;
  }

  function save(popup, options, state) {
    var form = popup.querySelector("[data-commercial-schedule-form]");
    if (!form.reportValidity()) return Promise.resolve(false);
    var fail = function (message) {
      window.Swal.showValidationMessage(message);
      return Promise.resolve(false);
    };
    var kind = currentKind(form, state.kind);
    var fd = new FormData(form);
    var selected = selectedEmployees(form, options);
    if (!selected.length) return fail("Selecciona quién es el responsable.");
    var day = String(fd.get("fecha") || "");
    var startMinutes = minutesOf(fd.get("hora_inicio"));
    var endMinutes = startMinutes + Number(fd.get("duracion") || 60);
    var location = String(fd.get("ubicacion") || "").trim();
    if (kind !== "recordatorio" && !location) return fail("Escribe la ubicación.");
    if (kind !== "recordatorio" && endMinutes >= 24 * 60) return fail("El evento debe terminar el mismo día.");
    var dates;
    try {
      dates = kind === "recordatorio" || state.ticketId() ? [day] : repeatDates(day, fd);
    } catch (error) {
      return fail(error.message);
    }
    var ticketId = kind === "recordatorio" ? "" : state.ticketId();
    var isCita = kind === "cita" && !!ticketId;
    var startText = timeOf(startMinutes) + ":00";
    var endText = timeOf(endMinutes) + ":00";
    var base = {
      titulo: String(fd.get("titulo") || "").trim(),
      descripcion: String(fd.get("descripcion") || ""),
      id_categoria: String(fd.get("id_categoria") || ""),
      meta: { origen: "comercial", modulo: options.task ? "tarea" : "calendario", tipo: kind },
    };
    var category = form.elements.id_categoria.options[form.elements.id_categoria.selectedIndex].text;
    var request;
    window.Swal.resetValidationMessage();
    return Promise.resolve().then(function () {
      window.Swal.showLoading();
      if (kind === "recordatorio") {
        return api("crear_item_calendario", Object.assign({}, base, {
          tipo_item: "recordatorio",
          recordatorio_at: day + " " + startText,
          fecha_inicio: day + " " + startText,
          recordatorio_canal: "whatsapp",
          aviso_minutos_antes: Number(fd.get("aviso_minutos_antes") || 0),
          id_empleado: selected[0],
        }));
      }
      var eventBase = Object.assign({}, base, {
        ubicacion: location,
        id_ticket: ticketId,
        es_cita: isCita ? "si" : "no",
        estado_comercial: ticketId ? String(fd.get("estado_comercial") || "") : "",
      });
      var send = function (extra) {
        if (selected.length > 1 || dates.length > 1) {
          return api("crear_eventos", Object.assign({
            eventos: dates.map(function (date) { return Object.assign({}, eventBase, { fecha_inicio: date + " " + startText, fecha_fin: date + " " + endText }); }),
            empleados: selected,
          }, extra));
        }
        return api("crear_evento", Object.assign({}, eventBase, { fecha_inicio: day + " " + startText, fecha_fin: day + " " + endText, id_empleado: selected[0] }, extra));
      };
      request = send({});
      return request.then(function (json) {
        var blocked = json && !json.success && json.data && json.data.bloqueado_vencidos;
        if (!blocked) return json;
        return window.confirm(String(json.message) + "\n\n¿Deseas crearlo de todas formas? Quedará registrado el bloqueo.") ? send({ permitir_vencidos: "1" }) : json;
      });
    }).then(function (json) {
      if (!json || !json.success) throw new Error((json && json.message) || "No se pudo guardar.");
      json._appointments = isCita ? [{ id_ticket: ticketId, id_empleado: selected[0], categoria: category, titulo: base.titulo, ubicacion: location, fecha_inicio: day + " " + startText, fecha_fin: day + " " + endText, es_cita: "si" }] : [];
      return json;
    }).catch(function (error) {
      window.Swal.hideLoading();
      window.Swal.showValidationMessage((error && error.message) || "No se pudo guardar.");
      return false;
    });
  }

  function afterSave(json, options) {
    notify("success", json.message || "Guardado en el calendario comercial.");
    var data = json.data || {};
    if (data.aviso_programado === false) {
      notify("warning", "El recordatorio se guardó, pero no se pudo programar el aviso.", "Aviso pendiente");
    }
    if (json._appointments.length && typeof core.notifyCalendarAppointment === "function") {
      core.notifyCalendarAppointment(app, json._appointments).then(function (result) {
        if (result && Number(result.queued || 0) > 0) notify("success", result.queued + " notificación(es) WhatsApp encolada(s).", "WhatsApp");
        else if (result && (result.error || (result.errors && result.errors.length))) notify("error", result.error || result.errors[0], "WhatsApp");
      });
    }
    if (typeof options.onSaved === "function") options.onSaved(json);
  }

  function bindForm(popup, options, categories, state) {
    var form = popup.querySelector("[data-commercial-schedule-form]");
    var els = form.elements;
    var titleTouched = !!options.title;
    var agendaRequest = 0;
    var tickets = [];

    state.ticketId = function () {
      if (options.task) return options.task.pk;
      return els.id_ticket ? String(els.id_ticket.value || "") : "";
    };

    function selectedTicket() {
      var id = state.ticketId();
      return tickets.find(function (row) { return String(row._ID) === id; }) || null;
    }

    function autoTitle() {
      if (titleTouched) return;
      var kind = currentKind(form, state.kind);
      var category = els.id_categoria.selectedIndex > 0 ? els.id_categoria.options[els.id_categoria.selectedIndex].text : "";
      var ticket = options.task ? { number: options.task.ticket, requester: options.task.requester } : (selectedTicket() ? { number: selectedTicket().id_ticket || selectedTicket()._ID, requester: selectedTicket().solicitante } : null);
      if (kind === "recordatorio") return;
      els.titulo.value = ticket
        ? (kind === "cita" ? "Cita" : category || "Evento") + " · Tarea #" + ticket.number + (ticket.requester ? " · " + ticket.requester : "")
        : category;
    }

    function applyKind(isInitial) {
      var kind = currentKind(form, state.kind);
      popup.querySelector("[data-form-title]").textContent = TITLES[kind];
      popup.querySelector("[data-form-subtitle]").textContent = SUBTITLES[kind];
      form.classList.toggle("is-reminder", kind === "recordatorio");
      form.classList.toggle("no-repeat", kind === "cita" || !!state.ticketId());
      if (els.ubicacion) els.ubicacion.required = kind !== "recordatorio";
      popup.querySelector("[data-form-status]").hidden = !state.ticketId();
      var hint = popup.querySelector("[data-form-ticket-hint]");
      if (hint) hint.textContent = kind === "cita" && !state.ticketId() ? "Sin tarea no se avisa al cliente." : "";
      if (kind === "cita" && (!els.id_categoria.value || !isInitial)) {
        var cita = citaCategoryId(categories);
        if (cita) els.id_categoria.value = cita;
      }
      var confirm = window.Swal.getConfirmButton();
      if (confirm) confirm.textContent = kind === "recordatorio" ? "Crear recordatorio" : (kind === "cita" ? "Agendar cita" : "Crear evento");
      autoTitle();
    }

    function refreshAgenda() {
      var wrap = form.querySelector("[data-commercial-schedule-agenda]");
      var ids = selectedEmployees(form, options);
      var day = els.fecha.value;
      var kind = currentKind(form, state.kind);
      var request = ++agendaRequest;
      if (!ids.length || !day || kind === "recordatorio") {
        wrap.innerHTML = "";
        return;
      }
      var start = els.hora_inicio.value;
      var end = timeOf(Math.min(minutesOf(start) + Number(els.duracion.value || 60), 23 * 60 + 59));
      Promise.all(ids.map(function (id) { return dayEvents(id, day); })).then(function (groups) {
        if (request !== agendaRequest) return;
        var rows = [].concat.apply([], groups);
        var busy = rows.filter(function (row) {
          return !isDone(row) && timeLabel(row.fecha_inicio) < end && timeLabel(row.fecha_fin || row.fecha_inicio) > start;
        });
        var status = busy.length
          ? '<div class="scm-calendar-availability-status is-busy"><strong>Horario ocupado</strong><span>' + busy.length + " evento(s) se cruzan con " + esc(start) + " - " + esc(end) + ".</span></div>"
          : '<div class="scm-calendar-availability-status is-free"><strong>Horario disponible</strong><span>Sin eventos entre ' + esc(start) + " y " + esc(end) + ".</span></div>";
        var list = rows.slice().sort(function (a, b) { return String(a.fecha_inicio).localeCompare(String(b.fecha_inicio)); }).map(function (row) {
          return '<li><b>' + esc(timeLabel(row.fecha_inicio)) + "</b> " + esc(row.titulo || "Evento") + (ids.length > 1 ? ' <em>' + esc(employeeName(row.id_empleado)) + "</em>" : "") + "</li>";
        }).join("");
        wrap.innerHTML = status + (rows.length ? '<details class="scm-cal-day"><summary>Ver agenda del día (' + rows.length + ")</summary><ul>" + list + "</ul></details>" : "");
      }).catch(function () {
        if (request === agendaRequest) wrap.innerHTML = '<p class="scm-cal-hint">No se pudo consultar la agenda.</p>';
      });
    }

    function refreshTickets() {
      var select = els.id_ticket;
      if (!select) return;
      var ids = selectedEmployees(form, options);
      var wrap = popup.querySelector("[data-form-ticket]");
      wrap.hidden = options.multiple || ids.length !== 1;
      if (wrap.hidden) return;
      api("obtener_tickets_funcionario", { id_empleado: ids[0] }).then(function (json) {
        tickets = rowsFrom(json);
        select.innerHTML = '<option value="">Sin tarea</option>' + tickets.map(function (row) {
          return '<option value="' + esc(row._ID) + '">' + esc(ticketLabel(row)) + "</option>";
        }).join("");
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
          window.jQuery(select).select2({ width: "100%", dropdownParent: window.jQuery(popup), placeholder: "Sin tarea", allowClear: true }).on("change", onTicket);
        }
      }).catch(function () { tickets = []; });
    }

    function onTicket() {
      var ticket = selectedTicket();
      if (ticket) {
        if (ticket.direccion && !els.ubicacion.value) els.ubicacion.value = ticket.direccion;
        els.estado_comercial.innerHTML = statusOptionsHtml(ticket.estado_comercial);
      }
      applyKind(true);
    }

    form.addEventListener("change", function (event) {
      var name = event.target.name;
      if (name === "kind") { applyKind(false); refreshAgenda(); }
      if (name === "id_categoria") autoTitle();
      if (name === "id_empleado" || name === "empleados") { refreshTickets(); refreshAgenda(); }
      if (name === "fecha" || name === "hora_inicio" || name === "duracion") refreshAgenda();
      if (name === "id_ticket") onTicket();
      if (name === "repetir") {
        popup.querySelector("[data-form-repeat-end]").hidden = !event.target.value;
        popup.querySelector("[data-form-repeat-days]").hidden = event.target.value !== "semanal";
      }
    });
    els.titulo.addEventListener("input", function () { titleTouched = els.titulo.value.trim() !== ""; });
    form.addEventListener("click", function (event) {
      var place = event.target.closest("[data-form-place]");
      if (place) els.ubicacion.value = place.getAttribute("data-form-place");
    });
    applyKind(true);
    refreshTickets();
    refreshAgenda();
  }

  /**
   * opts: kind (cita|evento|recordatorio), task, date, start, end, title, description, employeeId, lockEmployee, multiple, onSaved.
   */
  function open(options) {
    options = options || {};
    if (!window.Swal) return;
    if (!employees().length && !options.lockEmployee) {
      notify("error", "No hay funcionarios habilitados para el calendario comercial.");
      return;
    }
    var canAssign = !!config().calendar_can_assign_others;
    var me = currentEmployeeId();
    var ids = employees().map(function (row) { return String(row.id_empleado || ""); });
    var task = options.task || null;
    var employee = String(options.employeeId || (task && ids.indexOf(task.employeeId) !== -1 ? task.employeeId : "") || (ids.indexOf(me) !== -1 ? me : "") || "");
    if (!task && !canAssign) {
      options.lockEmployee = true;
      options.multiple = false;
      employee = me;
    }
    if (options.lockEmployee && !employee) employee = me;
    var kinds = options.multiple ? ["evento"] : (task ? ["cita", "evento"] : ["cita", "evento", "recordatorio"]);
    var state = { kinds: kinds, kind: kinds.indexOf(options.kind) !== -1 ? options.kind : kinds[0], employee: employee, ticketId: function () { return ""; } };
    loadCategories().then(function (categories) {
      if (!categories.length) throw new Error("No hay categorías habilitadas para el calendario comercial. Revisa Configuración › Calendario comercial.");
      return window.Swal.fire({
        title: "",
        html: formHtml(options, categories, state),
        width: 640,
        showCloseButton: true,
        showCancelButton: true,
        confirmButtonText: "Guardar",
        cancelButtonText: "Cancelar",
        focusConfirm: false,
        customClass: { popup: "scm-calendar-swal-popup scm-calendar-create-swal scm-cal-swal" },
        didOpen: function () { bindForm(window.Swal.getPopup(), options, categories, state); },
        preConfirm: function () { return save(window.Swal.getPopup(), options, state); },
      });
    }).then(function (result) {
      if (result && result.isConfirmed && result.value) afterSave(result.value, options);
    }).catch(function (error) {
      notify("error", (error && error.message) || "No se pudo abrir el formulario.");
    });
  }

  // ── Reporte comercial al cerrar ──

  function prepareReport(eventId) {
    return api("preparar_reporte_comercial", { id_evento: eventId }).then(function (json) {
      return json && json.success ? json.data : { habilitado: false };
    });
  }

  function propertyLabel(row) {
    return "#" + (row.codigo || row.id) + " · " + (row.titulo || "Inmueble") + (row.direccion ? " · " + row.direccion : "");
  }

  function transportQuestionHtml(base) {
    var money = function (value) { return "$" + value.toLocaleString("es-CO"); };
    var options = [{ value: "no", label: "No" }];
    if (base > 0) {
      options.push({ value: "ida", label: "Sí, un trayecto · " + money(base) });
      options.push({ value: "ida_vuelta", label: "Sí, ida y vuelta · " + money(base * 2) });
    }
    return '<fieldset class="scm-seg-field"><span>¿Deseas cobrar transporte? <b>*</b></span><div class="scm-cal-chips scm-cal-days">' + options.map(function (option) {
      return '<label><input type="radio" name="transporte" value="' + option.value + '"><span>' + esc(option.label) + "</span></label>";
    }).join("") + "</div></fieldset>";
  }

  function openReport(options) {
    var report = options.report || {};
    var known = {};
    (report.inmuebles || []).forEach(function (row) { known[String(row.id)] = row; });
    function optionsHtml() {
      return '<option value="">Selecciona un inmueble</option>' + Object.keys(known).map(function (id) {
        return '<option value="' + esc(id) + '">' + esc(propertyLabel(known[id])) + "</option>";
      }).join("");
    }
    window.Swal.fire({
      title: "",
      width: 640,
      showCloseButton: true,
      showCancelButton: true,
      confirmButtonText: "Guardar reporte y finalizar",
      cancelButtonText: "Cancelar",
      focusConfirm: false,
      customClass: { popup: "scm-calendar-swal-popup scm-calendar-create-swal scm-cal-swal" },
      html: '<div class="scm-calendar-create-shell scm-cal-shell"><div class="scm-cal-head"><strong>Finalizar evento #' + esc(options.eventId) + "</strong><span>" + esc(options.title || "") + " · Este evento requiere reporte comercial. Si cancelas, sigue pendiente.</span></div>" +
        '<form class="scm-cal-form" data-commercial-report-form autocomplete="off">' +
        field("Tipo de reporte", '<select class="scm-select" name="tipo_reporte">' + (report.tipos_permitidos || []).map(function (type) {
          return '<option value="' + esc(type) + '"' + (type === report.tipo_predeterminado ? " selected" : "") + ">" + esc(type) + "</option>";
        }).join("") + "</select>") +
        field("Buscar inmueble", '<input class="scm-input" data-report-search placeholder="Código, título o dirección">') +
        '<small class="scm-cal-hint" data-report-status>Primero aparecen los inmuebles cotizados en la tarea.</small>' +
        field("Inmueble", '<select class="scm-select" name="id_inmueble">' + optionsHtml() + "</select>") +
        transportQuestionHtml(Number(report.valor_transporte || 0)) +
        field("Resultado de la actividad <b>*</b>", '<textarea class="scm-textarea" name="observacion" rows="3" required>Realizado</textarea>') +
        "</form></div>",
      didOpen: function () {
        var popup = window.Swal.getPopup();
        var search = popup.querySelector("[data-report-search]");
        var status = popup.querySelector("[data-report-status]");
        var select = popup.querySelector('[name="id_inmueble"]');
        var timer = 0;
        search.addEventListener("input", function () {
          window.clearTimeout(timer);
          var term = search.value.trim();
          if (term.length < 2) return;
          timer = window.setTimeout(function () {
            status.textContent = "Buscando...";
            api("buscar_inmuebles_reporte", { id_evento: options.eventId, termino: term }).then(function (json) {
              var rows = rowsFrom(json);
              var current = select.value;
              rows.forEach(function (row) { known[String(row.id)] = row; });
              select.innerHTML = optionsHtml();
              select.value = current || (rows[0] ? String(rows[0].id) : "");
              status.textContent = rows.length ? rows.length + " inmueble(s) encontrado(s)." : "No se encontraron inmuebles.";
            }).catch(function () { status.textContent = "No se pudo buscar."; });
          }, 300);
        });
      },
      preConfirm: function () {
        var form = window.Swal.getPopup().querySelector("[data-commercial-report-form]");
        if (!form.reportValidity()) return false;
        var fd = new FormData(form);
        var propertyId = String(fd.get("id_inmueble") || "");
        if (!fd.get("transporte")) {
          window.Swal.showValidationMessage("Indica si deseas cobrar transporte.");
          return false;
        }
        if ((fd.get("tipo_reporte") === "Visita" || report.inmueble_obligatorio) && !propertyId) {
          window.Swal.showValidationMessage("Debes seleccionar un inmueble.");
          return false;
        }
        window.Swal.showLoading();
        return api("cambiar_estado", {
          id_evento: options.eventId,
          observacion: String(fd.get("observacion") || "").trim(),
          reporte: {
            tipo_reporte: String(fd.get("tipo_reporte") || ""),
            transporte: String(fd.get("transporte")),
            id_inmueble: propertyId,
            id_cotizacion: known[propertyId] ? known[propertyId].id_cotizacion : "",
          },
        }).then(function (json) {
          if (!json || !json.success) throw new Error((json && json.message) || "No se pudo finalizar el evento.");
          return json;
        }).catch(function (error) {
          window.Swal.hideLoading();
          window.Swal.showValidationMessage(error.message);
          return false;
        });
      },
    }).then(function (result) {
      if (!result.isConfirmed || !result.value) {
        if (typeof options.returnTo === "function") window.setTimeout(options.returnTo, 80);
        return;
      }
      notify("success", result.value.message || "Evento finalizado.");
      if (typeof options.onSaved === "function") options.onSaved();
    });
  }

  window.SCMCommercialCalendarForm = { open: open, api: api, rowsFrom: rowsFrom, prepareReport: prepareReport, openReport: openReport };
})();
