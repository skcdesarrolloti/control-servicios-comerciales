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
  var apiUrl = String(config.calendar_api_url || "https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=");
  var currentEmployeeId = String(config.calendar_current_employee_id || "").trim();
  var employees = Array.isArray(config.calendar_allowed_funcionarios) ? config.calendar_allowed_funcionarios : [];
  var openStatuses = Array.isArray(config.commercial_open_statuses) ? config.commercial_open_statuses : [];
  var categoriesPromise = null;
  var baseUrl = String(runtime.ajaxUrl || "").replace(/\/api\.php$/, "");

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

  function calendarApi(action, payload) {
    var options = { method: payload ? "POST" : "GET", credentials: "same-origin" };
    if (payload) {
      options.headers = { "Content-Type": "application/json" };
      options.body = JSON.stringify(payload);
    }
    return fetch(apiUrl + encodeURIComponent(action), options).then(function (response) {
      return response.text().then(function (body) {
        try {
          var json = JSON.parse(body);
          if (json && !json.success && json.data && json.data.error_detail) {
            json.message = (json.message || "Error del calendario.") + " Detalle: " + json.data.error_detail;
          }
          return json;
        } catch (error) {
          throw new Error("No se pudo confirmar la respuesta del calendario (HTTP " + response.status + "). Revisa el calendario antes de volver a guardar.");
        }
      });
    });
  }

  function rowsFrom(json) {
    if (!json || !json.success) return [];
    if (Array.isArray(json.data)) return json.data;
    if (json.data && Array.isArray(json.data.data)) return json.data.data;
    return [];
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
      administrativeStatus: String(data.estadoAdministrativo || ""),
    };
  }

  function defaultEmployeeId(task) {
    var ids = employees.map(function (row) { return String(row.id_empleado || ""); });
    if (task.employeeId && ids.indexOf(task.employeeId) !== -1) return task.employeeId;
    if (currentEmployeeId && ids.indexOf(currentEmployeeId) !== -1) return currentEmployeeId;
    return task.employeeId || currentEmployeeId || ids[0] || "";
  }

  // Solo se ofrecen las categorías habilitadas para comercial (configuración del calendario).
  function loadCategories() {
    if (!categoriesPromise) {
      categoriesPromise = calendarApi("listar_categorias").then(function (json) {
        var rows = rowsFrom(json);
        var allowed = Array.isArray(runtime.calendarCategoryIds) ? runtime.calendarCategoryIds.map(String) : [];
        return allowed.length ? rows.filter(function (row) { return allowed.indexOf(String(row.id || row._ID || "")) !== -1; }) : rows;
      }).catch(function (error) {
        categoriesPromise = null;
        throw error;
      });
    }
    return categoriesPromise;
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

  function waitForGoogle(employeeId, authWindow) {
    return new Promise(function (resolve, reject) {
      var attempts = 0;
      function check() {
        calendarApi("estado_google_oauth", { id_empleado: employeeId }).then(function (json) {
          if (json && json.success && json.data && json.data.connected) {
            resolve(true);
            return;
          }
          if (authWindow.closed) {
            reject(new Error("Se cerró la autorización de Google sin conectar la cuenta."));
            return;
          }
          if (++attempts >= 60) {
            reject(new Error("No se confirmó la conexión con Google Calendar. Autoriza la cuenta y vuelve a guardar."));
            return;
          }
          window.setTimeout(check, 2000);
        }).catch(reject);
      }
      check();
    });
  }

  function ensureGoogle(employeeId, requested) {
    if (!requested) return Promise.resolve(true);
    return calendarApi("estado_google_oauth", { id_empleado: employeeId }).then(function (json) {
      var data = (json && json.data) || {};
      if (!json || !json.success) throw new Error((json && json.message) || "No se pudo validar Google Calendar.");
      if (!data.configured) throw new Error("Google Calendar aún no está configurado en el servidor. Desmarca Google Calendar para guardar solo en el panel.");
      if (!data.sync_enabled) throw new Error("La sincronización con Google Calendar está desactivada. Desmarca Google Calendar para guardar solo en el panel.");
      if (data.connected) return true;
      if (!currentEmployeeId || employeeId !== currentEmployeeId) {
        throw new Error(employeeName(employeeId) + " debe conectar personalmente su cuenta de Google. Desmarca Google Calendar para guardar solo en el panel.");
      }
      var redirect = new URL(window.location.href);
      redirect.searchParams.set("scm_google_popup", "1");
      return calendarApi("iniciar_google_oauth", { id_empleado: employeeId, redirect_after: redirect.toString() }).then(function (authJson) {
        var authUrl = authJson && authJson.data ? authJson.data.auth_url : "";
        if (!authJson || !authJson.success || !authUrl) throw new Error((authJson && authJson.message) || "No se pudo iniciar la autorización de Google.");
        var authWindow = window.open(authUrl, "scm_google_calendar_auth", "popup=yes,width=560,height=720");
        if (!authWindow) throw new Error("El navegador bloqueó la ventana de Google. Permite ventanas emergentes y vuelve a guardar.");
        window.Swal.showValidationMessage("Autoriza Google Calendar en la ventana abierta. Guardaremos al confirmar la conexión.");
        return waitForGoogle(employeeId, authWindow).then(function () {
          try { authWindow.close(); } catch (error) { /* ventana ya cerrada */ }
          return true;
        });
      });
    });
  }

  function statusOptionsHtml(current) {
    var list = openStatuses.slice();
    if (current && list.indexOf(current) === -1) list.unshift(current);
    return list.map(function (status) {
      return '<option value="' + esc(status) + '"' + (status === current ? " selected" : "") + ">" + esc(status) + "</option>";
    }).join("");
  }

  function employeeOptionsHtml(selected) {
    return employees.map(function (row) {
      var id = String(row.id_empleado || "");
      return id ? '<option value="' + esc(id) + '"' + (id === selected ? " selected" : "") + ">" + esc(row.nombre || "Funcionario #" + id) + "</option>" : "";
    }).join("");
  }

  function scheduleFormHtml(task, categories) {
    var tomorrow = dateKey(addDays(new Date(), 1));
    var title = "Cita · Tarea #" + task.ticket + (task.topic ? " · " + task.topic : "");
    var description = "Cita de la tarea comercial #" + task.ticket + (task.subject ? " (" + task.subject + ")" : "") + "." +
      (task.requester ? "\nSolicitante: " + task.requester + "." : "") + (task.property ? "\nInmueble: #" + task.property + "." : "");
    var categoryOptions = categories.map(function (row) {
      var id = String(row.id || row._ID || "");
      return id ? '<option value="' + esc(id) + '">' + esc(row.nombre || row.categoria || "Categoría") + "</option>" : "";
    }).join("");
    return '<div class="scm-calendar-create-shell scm-calendar-create-shell--event">' +
      '<div class="scm-calendar-create-head"><div class="scm-calendar-create-icon" aria-hidden="true"><span class="material-symbols-outlined">event_available</span></div>' +
      '<div class="scm-calendar-create-title"><strong>Agendar cita de la tarea #' + esc(task.ticket) + '</strong><span>Queda en el calendario comercial, vinculada a la tarea y con aviso por WhatsApp.</span></div>' +
      '<span class="scm-calendar-create-badge">Cita</span></div>' +
      '<form class="scm-calendar-popup-form scm-calendar-create-form" data-commercial-schedule-form autocomplete="off"><div class="scm-calendar-create-main">' +
      '<div class="scm-calendar-create-row scm-calendar-create-row--top">' +
      '<label class="scm-seg-field"><span>Título <b>*</b></span><input class="scm-input" name="titulo" required value="' + esc(title) + '"></label>' +
      '<label class="scm-seg-field"><span>Categoría <b>*</b></span><select class="scm-select" name="id_categoria" required><option value="">Selecciona categoría</option>' + categoryOptions + "</select></label>" +
      "</div>" +
      '<label class="scm-seg-field scm-calendar-field-full"><span>Funcionario responsable <b>*</b></span><select class="scm-select" name="id_empleado" required>' + employeeOptionsHtml(defaultEmployeeId(task)) + "</select></label>" +
      '<div class="scm-calendar-create-row scm-calendar-date-row">' +
      '<label class="scm-seg-field"><span>Fecha</span><input class="scm-input" type="date" name="fecha" required value="' + esc(tomorrow) + '"></label>' +
      '<label class="scm-seg-field"><span>Hora inicio</span><input class="scm-input" type="time" name="hora_inicio" required value="09:00"></label>' +
      '<label class="scm-seg-field"><span>Hora fin</span><input class="scm-input" type="time" name="hora_fin" required value="10:00"></label>' +
      "</div>" +
      '<label class="scm-seg-field scm-calendar-field-full"><span>Ubicación <b>*</b></span><input class="scm-input" name="ubicacion" required value="' + esc(task.address) + '" placeholder="Dirección de la visita o lugar de la cita"></label>' +
      '<div class="scm-calendar-create-row">' +
      '<label class="scm-seg-field"><span>¿Es cita con el cliente?</span><select class="scm-select" name="es_cita"><option value="si" selected>Sí, notificar al solicitante</option><option value="no">No, actividad interna</option></select></label>' +
      '<label class="scm-seg-field"><span>Estado comercial de la tarea</span><select class="scm-select" name="estado_comercial">' + statusOptionsHtml(task.commercialStatus) + "</select></label>" +
      "</div>" +
      '<label class="scm-calendar-google-toggle scm-calendar-field-full"><input type="checkbox" name="sincronizar_google" value="1" checked><span><strong>Agregar a Google Calendar</strong><em>Se usa la cuenta conectada del funcionario. Cada funcionario autoriza su propia cuenta.</em></span></label>' +
      '<label class="scm-seg-field scm-calendar-field-full"><span>Descripción</span><textarea class="scm-textarea" name="descripcion" rows="3">' + esc(description) + "</textarea></label>" +
      "</div>" +
      '<aside class="scm-calendar-availability-panel">' +
      '<div class="scm-calendar-availability-head"><div><span>Disponibilidad en vivo</span><strong data-commercial-schedule-employee>' + esc(employeeName(defaultEmployeeId(task))) + "</strong></div></div>" +
      '<div class="scm-calendar-popup-agenda" data-commercial-schedule-agenda><p class="scm-calendar-popup-agenda-empty">Cargando agenda del funcionario...</p></div>' +
      "</aside></form></div>";
  }

  function renderDayAgenda(popup, task) {
    var form = popup.querySelector("[data-commercial-schedule-form]");
    var wrap = popup.querySelector("[data-commercial-schedule-agenda]");
    if (!form || !wrap) return;
    var employeeId = form.elements.id_empleado.value;
    var day = form.elements.fecha.value;
    if (!employeeId || !day) {
      wrap.innerHTML = '<p class="scm-calendar-popup-agenda-empty">Selecciona funcionario y fecha para ver su agenda.</p>';
      return;
    }
    var requestKey = employeeId + "|" + day;
    wrap.setAttribute("data-request", requestKey);
    var employeeLabel = popup.querySelector("[data-commercial-schedule-employee]");
    if (employeeLabel) employeeLabel.textContent = employeeName(employeeId);
    wrap.innerHTML = '<p class="scm-calendar-popup-agenda-empty">Cargando agenda de ' + esc(employeeName(employeeId)) + "...</p>";
    employeeEvents(employeeId, day, day, 60).then(function (rows) {
      if (wrap.getAttribute("data-request") !== requestKey) return;
      var start = form.elements.hora_inicio.value;
      var end = form.elements.hora_fin.value;
      var busy = rows.filter(function (row) {
        return !isDone(row) && timeLabel(row.fecha_inicio) < end && timeLabel(row.fecha_fin || row.fecha_inicio) > start;
      });
      var status = busy.length
        ? '<div class="scm-calendar-availability-status is-busy"><strong>Horario ocupado</strong><span>' + busy.length + " evento(s) se cruzan con " + esc(start) + " - " + esc(end) + ".</span></div>"
        : '<div class="scm-calendar-availability-status is-free"><strong>Horario disponible</strong><span>Sin eventos en esa franja.</span></div>';
      wrap.innerHTML = status + '<p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mt-3 mb-1">Agenda del ' + esc(dayLabel(day)) + "</p>" +
        (rows.length ? '<div class="space-y-1.5 max-h-72 overflow-y-auto">' + rows.map(function (row) { return eventLineHtml(row, task.pk); }).join("") + "</div>" : '<p class="scm-calendar-popup-agenda-empty">Sin eventos ese día.</p>');
    }).catch(function () {
      if (wrap.getAttribute("data-request") !== requestKey) return;
      wrap.innerHTML = '<p class="scm-calendar-popup-agenda-empty">No se pudo consultar la agenda del funcionario.</p>';
    });
  }

  function createEvent(payload) {
    return calendarApi("crear_evento", payload).then(function (json) {
      var blocked = json && !json.success && ((json.data && json.data.bloqueado_vencidos) || /vencidos sin realizar/i.test(String(json.message || "")));
      if (!blocked) return json;
      var ok = window.confirm(String(json.message || "El funcionario tiene eventos vencidos sin realizar.") + "\n\n¿Deseas crear la cita de todas formas? Quedará registrado el bloqueo.");
      return ok ? calendarApi("crear_evento", Object.assign({}, payload, { permitir_vencidos: "1" })) : json;
    });
  }

  function openSchedule(card) {
    if (!window.Swal) return;
    var task = taskData(card);
    if (!employees.length) {
      notify("error", "No hay funcionarios habilitados para el calendario comercial.");
      return;
    }
    loadCategories().then(function (categories) {
      if (!categories.length) throw new Error("No hay categorías habilitadas para el calendario comercial.");
      return window.Swal.fire({
        title: "",
        html: scheduleFormHtml(task, categories),
        width: 820,
        showCancelButton: true,
        confirmButtonText: "Agendar cita",
        cancelButtonText: "Cerrar",
        customClass: { popup: "scm-calendar-swal-popup scm-calendar-create-swal" },
        didOpen: function () {
          var popup = window.Swal.getPopup();
          var form = popup.querySelector("[data-commercial-schedule-form]");
          ["id_empleado", "fecha", "hora_inicio", "hora_fin"].forEach(function (name) {
            form.elements[name].addEventListener("change", function () { renderDayAgenda(popup, task); });
          });
          renderDayAgenda(popup, task);
        },
        preConfirm: function () {
          var popup = window.Swal.getPopup();
          var form = popup.querySelector("[data-commercial-schedule-form]");
          if (!form.reportValidity()) return false;
          var fd = new FormData(form);
          var start = String(fd.get("hora_inicio") || "");
          var end = String(fd.get("hora_fin") || "");
          if (end <= start) {
            window.Swal.showValidationMessage("La hora fin debe ser posterior a la hora de inicio.");
            return false;
          }
          var employeeId = String(fd.get("id_empleado") || "");
          var googleRequested = fd.get("sincronizar_google") === "1";
          var day = String(fd.get("fecha") || "");
          var categorySelect = form.elements.id_categoria;
          var payload = {
            titulo: String(fd.get("titulo") || "").trim(),
            descripcion: String(fd.get("descripcion") || ""),
            ubicacion: String(fd.get("ubicacion") || "").trim(),
            id_categoria: String(fd.get("id_categoria") || ""),
            fecha_inicio: day + " " + start + ":00",
            fecha_fin: day + " " + end + ":00",
            id_empleado: employeeId,
            creado_por: currentEmployeeId || "",
            id_ticket: task.pk,
            es_cita: String(fd.get("es_cita") || "si"),
            // El calendario actualiza ambos estados de la tarea: se envía el administrativo actual para no borrarlo.
            estado_comercial: String(fd.get("estado_comercial") || task.commercialStatus || ""),
            estado_administrativo: task.administrativeStatus,
            sincronizar_google: googleRequested ? "1" : "0",
          };
          if (googleRequested) {
            payload.google_calendar = "1";
            payload.meta = { google_calendar_requested: true, origen: "comercial", modulo: "tarea" };
          }
          window.Swal.resetValidationMessage();
          return ensureGoogle(employeeId, googleRequested).then(function () {
            window.Swal.showLoading();
            return createEvent(payload);
          }).then(function (json) {
            if (!json || !json.success) throw new Error((json && json.message) || "No se pudo agendar la cita.");
            json._appointment = Object.assign({}, payload, {
              categoria: categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].text : "",
            });
            json._googleRequested = googleRequested;
            return json;
          }).catch(function (error) {
            window.Swal.hideLoading();
            window.Swal.showValidationMessage((error && error.message) || "No se pudo agendar la cita.");
            return false;
          });
        },
      });
    }).then(function (result) {
      if (!result || !result.isConfirmed || !result.value) return;
      var json = result.value;
      notify("success", json.message || "Cita agendada en el calendario comercial.");
      if (json._googleRequested && json.data && json.data.google_pendiente) {
        notify("error", "La cita se guardó en el panel, pero quedó pendiente en Google Calendar.", "Google Calendar pendiente");
      }
      if (json._appointment.es_cita === "si" && typeof core.notifyCalendarAppointment === "function") {
        core.notifyCalendarAppointment(app, [json._appointment]).then(function (notification) {
          if (notification && Number(notification.queued || 0) > 0) {
            notify("success", notification.queued + " notificación(es) WhatsApp encolada(s).", "WhatsApp");
          } else if (notification && (notification.error || (notification.errors && notification.errors.length))) {
            notify("error", notification.error || notification.errors[0], "WhatsApp");
          }
        });
      }
      refreshCard(card);
      app.dispatchEvent(new CustomEvent("scm:task-updated", { bubbles: true }));
    }).catch(function (error) {
      notify("error", (error && error.message) || "No se pudo abrir el agendamiento.");
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
