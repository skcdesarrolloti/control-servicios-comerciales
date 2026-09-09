(function () {
  "use strict";

  var root = document.getElementById("scm-app");
  if (!root) return;

  var runtime = {};
  try {
    runtime = JSON.parse(root.getAttribute("data-scm-runtime") || "{}");
  } catch (_error) {}

  var apiUrl = runtime.ajaxUrl || "api.php";
  var nonce = runtime.nonce || "";
  var ticketsPanel = root.querySelector("[data-commercial-tickets-panel]");
  var calendarPanel = root.querySelector("[data-commercial-calendar-panel]");
  var tabsNav = root.querySelector("[data-commercial-tabs]");
  var globalFilter = root.querySelector("[data-commercial-global-filter-form]");
  var caseModal = document.getElementById("commercial-case-modal");
  var caseContent = caseModal && caseModal.querySelector("[data-commercial-case-content]");
  var advisoryModal = document.getElementById("commercial-advisory-modal");
  var currentCasePk = "";
  var lastFocused = null;
  var listRequest = null;
  var detailRequest = null;
  var actionMap = {
    reply: "commercial_ticket_reply",
    note: "commercial_ticket_note",
    follow_up: "commercial_ticket_follow_up",
    postpone: "commercial_ticket_postpone",
    activate: "commercial_ticket_activate",
    close: "commercial_ticket_close",
    status: "commercial_ticket_status",
    reassign: "commercial_ticket_reassign",
  };

  function loadingMarkup() {
    return '<div class="commercial-case-loading"><span></span><span></span><span></span><p>Cargando información de la tarea…</p></div>';
  }

  function notify(type, message) {
    if (window.Swal && typeof window.Swal.fire === "function") {
      window.Swal.fire({
        toast: true,
        position: "top-end",
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: type === "success" ? 2600 : 5000,
        timerProgressBar: true,
      });
      return;
    }
    window.alert(message);
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function assistantList(items) {
    if (!Array.isArray(items) || !items.length) return '<p class="commercial-assistant-muted">Sin hallazgos registrados.</p>';
    return "<ul>" + items.map(function (item) { return "<li>" + escapeHtml(item) + "</li>"; }).join("") + "</ul>";
  }

  function assistantText(value) {
    if (value === null || typeof value === "undefined") return "";
    if (Array.isArray(value)) {
      return value.map(assistantText).filter(Boolean).join(" · ");
    }
    if (typeof value === "object") {
      return Object.keys(value).map(function (key) {
        var text = assistantText(value[key]);
        return text ? key + ": " + text : "";
      }).filter(Boolean).join(" · ");
    }
    var text = String(value || "").trim();
    return text.toLowerCase() === "array" ? "" : text;
  }

  function assistantBlock(title, content) {
    return (
      '<section class="commercial-assistant-block">' +
      "<h4>" + escapeHtml(title) + "</h4>" +
      content +
      "</section>"
    );
  }

  function assistantLoadingMarkup() {
    return (
      '<div class="commercial-assistant-loading">' +
      '<span class="commercial-assistant-spinner" aria-hidden="true"></span>' +
      '<div><h3>Analizando tarea con MiniMax…</h3><p>Estoy revisando datos de la tarea, inmueble, historial, respuestas, seguimientos y notas.</p></div>' +
      "</div>"
    );
  }

  function assistantErrorMarkup(message) {
    return (
      '<div class="commercial-assistant-error">' +
      '<div><h3>No se pudo analizar la tarea</h3><p>' + escapeHtml(message || "Inténtalo nuevamente.") + "</p></div>" +
      "</div>"
    );
  }

  function assistantAnalysisMarkup(analysis) {
    analysis = analysis || {};
    var summary = escapeHtml(analysis.resumen || "Sin resumen generado.");
    var clientText = assistantText(analysis.cliente);
    var statusText = assistantText(analysis.estado_actual);
    var client = clientText ? '<p>' + escapeHtml(clientText) + "</p>" : "";
    var currentStatus = statusText ? '<p>' + escapeHtml(statusText) + "</p>" : "";
    var suggested = analysis.mensaje_sugerido ? '<div class="commercial-assistant-suggested"><div class="commercial-assistant-suggested-actions"><button type="button" class="commercial-secondary-btn" data-commercial-use-assistant-message>Usar en respuesta</button><button type="button" class="commercial-secondary-btn" data-commercial-copy-assistant-message>Copiar mensaje</button></div><p>' + escapeHtml(analysis.mensaje_sugerido) + "</p></div>" : '<p class="commercial-assistant-muted">Sin mensaje sugerido.</p>';
    return (
      '<header class="commercial-assistant-result-head">' +
      '<div><span>Asistente comercial</span><h3 id="commercial-analysis-title">Análisis de la tarea</h3><p>' + escapeHtml(analysis.created_by ? ("Guardado por " + analysis.created_by) : "Generado con " + (analysis.model || "MiniMax")) + (analysis.created_label ? " · " + escapeHtml(analysis.created_label) : (analysis.generated_at ? " · " + escapeHtml(analysis.generated_at) : "")) + "</p></div>" +
      '<button type="button" class="commercial-modal-close commercial-assistant-close" data-commercial-close-assistant aria-label="Cerrar análisis">&times;</button>' +
      "</header>" +
      '<div class="commercial-assistant-summary"><strong>IA</strong><p>' + summary + "</p></div>" +
      '<div class="commercial-assistant-grid">' +
      (client ? assistantBlock("Cliente", client) : "") +
      (currentStatus ? assistantBlock("Estado actual", currentStatus) : "") +
      assistantBlock("Riesgos", assistantList(analysis.riesgos)) +
      assistantBlock("Oportunidades", assistantList(analysis.oportunidades)) +
      assistantBlock("Recomendaciones", assistantList(analysis.recomendaciones)) +
      assistantBlock("Próximos pasos", assistantList(analysis.proximos_pasos)) +
      assistantBlock("Datos faltantes", assistantList(analysis.datos_faltantes)) +
      assistantBlock("Mensaje sugerido para el cliente", suggested) +
      "</div>"
    );
  }

  function analysisListMarkup(ticketPk, analyses) {
    analyses = Array.isArray(analyses) ? analyses : [];
    if (!analyses.length) {
      return '<div class="commercial-analysis-empty"><p>Aún no hay análisis guardados.</p></div>';
    }
    return "<ol>" + analyses.map(function (analysis) {
      var json = escapeHtml(JSON.stringify(analysis || {}));
      var summary = String((analysis && analysis.resumen) || "Análisis guardado");
      var shortSummary = summary.length > 96 ? summary.slice(0, 95) + "…" : summary;
      return (
        "<li>" +
        '<button type="button" class="commercial-analysis-open" data-commercial-open-analysis data-analysis-json="' + json + '">' +
        "<span><strong>" + escapeHtml((analysis && (analysis.created_label || analysis.generated_at)) || "Sin fecha") + "</strong>" +
        "<small>" + escapeHtml(shortSummary) + "</small>" +
        "<em>" + escapeHtml((analysis && analysis.created_by) || "Sistema") + "</em></span>" +
        "</button>" +
        '<button type="button" class="commercial-analysis-delete" data-commercial-delete-analysis data-ticket-pk="' + escapeHtml(ticketPk || currentCasePk || "") + '" data-analysis-id="' + escapeHtml((analysis && analysis.id) || "") + '" aria-label="Eliminar análisis">Eliminar</button>' +
        "</li>"
      );
    }).join("") + "</ol>";
  }

  function updateAnalysisList(analyses) {
    if (!caseContent) return;
    var wrap = caseContent.querySelector("[data-commercial-saved-analyses]");
    var list = wrap && wrap.querySelector("[data-commercial-analysis-list]");
    var count = wrap && wrap.querySelector("[data-commercial-analysis-count]");
    if (!wrap || !list) return;
    var ticketPk = wrap.getAttribute("data-ticket-pk") || currentCasePk || "";
    analyses = Array.isArray(analyses) ? analyses : [];
    list.innerHTML = analysisListMarkup(ticketPk, analyses);
    if (count) count.textContent = analyses.length + "/3";
  }

  function analysisModal() {
    return caseContent ? caseContent.querySelector("[data-commercial-analysis-modal]") : null;
  }

  function setAnalysisModal(open, html) {
    var modal = analysisModal();
    if (!modal) return;
    var content = modal.querySelector("[data-commercial-analysis-modal-content]");
    if (typeof html === "string" && content) content.innerHTML = html;
    modal.hidden = !open;
    modal.classList.toggle("open", open);
    if (open) {
      var close = modal.querySelector("[data-commercial-close-assistant]");
      if (close) close.focus({ preventScroll: true });
    }
  }

  function parseAnalysisButton(button) {
    try {
      return JSON.parse(button.getAttribute("data-analysis-json") || "{}");
    } catch (_error) {
      return {};
    }
  }

  function confirmAnalysisDelete() {
    if (window.Swal && typeof window.Swal.fire === "function") {
      return window.Swal.fire({
        icon: "warning",
        title: "¿Eliminar análisis?",
        text: "Se quitará de esta tarea y podrás generar otro si no supera el límite diario.",
        showCancelButton: true,
        confirmButtonText: "Eliminar",
        cancelButtonText: "Cancelar",
        confirmButtonColor: "#b4232c",
      }).then(function (result) { return !!result.isConfirmed; });
    }
    return Promise.resolve(window.confirm("¿Eliminar este análisis?"));
  }

  function documentRowMarkup(accept) {
    return (
      '<div class="commercial-ticket-document-row scm-ticket-document-row">' +
      '<label><span>Título del documento</span><input type="text" name="documento_nombre[]" placeholder="Ej: soporte, cédula, autorización…"></label>' +
      '<label><span>Documento</span><input type="file" name="documento[]" accept="' + escapeHtml(accept || "image/jpeg,image/png,application/pdf,application/msword,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-rar-compressed,text/html,text/plain,text/csv") + '"></label>' +
      '<button type="button" class="commercial-secondary-btn commercial-remove-ticket-document" data-remove-ticket-document>Quitar</button>' +
      "</div>"
    );
  }

  function renderPastedFiles(zone, input) {
    var list = zone.querySelector("[data-scm-paste-list]");
    if (!list) return;
    list.innerHTML = "";
    Array.prototype.forEach.call(input.files || [], function (file) {
      var item = document.createElement("li");
      item.textContent = file.name;
      list.appendChild(item);
    });
  }

  function handlePasteEvidence(event) {
    if (event.defaultPrevented) return;
    var zone = event.target && event.target.closest ? event.target.closest("[data-scm-paste-evidence]") : null;
    if (!zone || !caseModal || !caseModal.contains(zone)) return;
    var clipboard = event.clipboardData || window.clipboardData;
    var items = clipboard && clipboard.items ? clipboard.items : [];
    var files = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i] && /^image\//i.test(items[i].type || "")) {
        var pasted = items[i].getAsFile();
        if (pasted) {
          var ext = (pasted.type || "image/png").split("/").pop() || "png";
          files.push(new File([pasted], "captura-pegada-" + Date.now() + "-" + i + "." + ext, { type: pasted.type || "image/png" }));
        }
      }
    }
    if (!files.length) {
      zone.classList.add("is-error");
      var empty = zone.querySelector("[data-scm-paste-list]");
      if (empty) empty.innerHTML = "<li>No se encontró una imagen en el portapapeles.</li>";
      return;
    }
    var form = zone.closest("form");
    var inputName = zone.getAttribute("data-file-input-name") || "evidencia[]";
    var input = form ? form.querySelector('input[type="file"][name="' + inputName + '"]') : null;
    if (!input || typeof DataTransfer === "undefined") {
      zone.classList.add("is-error");
      var unsupported = zone.querySelector("[data-scm-paste-list]");
      if (unsupported) unsupported.innerHTML = "<li>Tu navegador no permitió adjuntar la captura pegada.</li>";
      return;
    }
    var transfer = new DataTransfer();
    Array.prototype.forEach.call(input.files || [], function (file) { transfer.items.add(file); });
    files.forEach(function (file) { transfer.items.add(file); });
    input.files = transfer.files;
    zone.classList.remove("is-error");
    zone.classList.add("has-files");
    renderPastedFiles(zone, input);
    event.preventDefault();
  }

  function request(action, input, signal) {
    var body = input instanceof FormData ? input : new FormData();
    if (!(input instanceof FormData)) {
      Object.keys(input || {}).forEach(function (key) { body.append(key, input[key]); });
    }
    body.set("action", action);
    body.set("nonce", nonce);
    return fetch(apiUrl, {
      method: "POST",
      credentials: "same-origin",
      body: body,
      signal: signal,
      headers: { "X-Requested-With": "XMLHttpRequest" },
    }).then(function (response) {
      return response.text().then(function (text) {
        var json = null;
        try {
          json = text ? JSON.parse(text) : null;
        } catch (_error) {
          var clean = String(text || "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
          throw new Error(clean ? clean.slice(0, 220) : "El servidor devolvió una respuesta vacía.");
        }
        if (!response.ok || !json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "La operación no pudo completarse.");
        }
        return json.data || {};
      });
    });
  }

  function activeTab() {
    var active = root.querySelector("[data-commercial-tab].active");
    return active ? active.getAttribute("data-commercial-tab") || "abiertos" : "abiertos";
  }

  function topTabFor(tab) {
    return ["abiertos", "postergados", "cerrados", "mis_tickets"].indexOf(tab) >= 0 ? "tareas" : tab;
  }

  function setActiveTab(tab) {
    var topTab = topTabFor(tab);
    root.querySelectorAll("[data-commercial-tab]").forEach(function (link) {
      var isActive = link.getAttribute("data-commercial-tab") === topTab;
      link.classList.toggle("active", isActive);
      if (isActive) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  function setVisiblePanel(tab) {
    var isCalendar = tab === "calendario";
    if (ticketsPanel) ticketsPanel.classList.toggle("active", !isCalendar);
    if (calendarPanel) calendarPanel.classList.toggle("active", isCalendar);
    setActiveTab(tab);
    if (isCalendar) root.dispatchEvent(new CustomEvent("scm:refresh-active-tab"));
  }

  function refreshAdvisoryModalRef() {
    advisoryModal = document.getElementById("commercial-advisory-modal");
    return advisoryModal;
  }

  function showAdvisoryModal(open, trigger) {
    var modal = refreshAdvisoryModalRef();
    if (!modal) return;
    modal.classList.toggle("open", open);
    modal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (open) {
      lastFocused = trigger || document.activeElement;
      var close = modal.querySelector("[data-commercial-close-advisory]");
      if (close) close.focus({ preventScroll: true });
    } else if (lastFocused && typeof lastFocused.focus === "function" && document.contains(lastFocused)) {
      lastFocused.focus({ preventScroll: true });
    }
  }

  function maybeAutoOpenAdvisory() {
    var modal = refreshAdvisoryModalRef();
    if (!modal || modal.getAttribute("data-auto-open") !== "1") return;
    var scope = modal.getAttribute("data-scope") || "all";
    var key = "commercial-advisory-seen:" + scope;
    try {
      if (window.sessionStorage && window.sessionStorage.getItem(key) === "1") return;
      if (window.sessionStorage) window.sessionStorage.setItem(key, "1");
    } catch (_error) {}
    window.setTimeout(function () { showAdvisoryModal(true); }, 450);
  }

  function normalizedUrl(value) {
    return new URL(value || window.location.href, window.location.href);
  }

  function updateHistory(url, replace) {
    var next = url.pathname + url.search;
    if (replace) window.history.replaceState({ commercial: true }, "", next);
    else window.history.pushState({ commercial: true }, "", next);
  }

  function loadTickets(url, options) {
    options = options || {};
    var nextUrl = normalizedUrl(url);
    var tab = nextUrl.searchParams.get("tab") || "abiertos";
    if (tab === "calendario") {
      setVisiblePanel(tab);
      if (options.history !== false) updateHistory(nextUrl, !!options.replace);
      return Promise.resolve();
    }
    if (!ticketsPanel) return Promise.resolve();
    if (listRequest) listRequest.abort();
    listRequest = new AbortController();
    ticketsPanel.classList.add("is-loading");
    ticketsPanel.setAttribute("aria-busy", "true");
    setVisiblePanel(tab);

    var data = new FormData();
    [
      "tab",
      "estado",
      "mis_bucket",
      "busqueda",
      "id_empleado",
      "ticket_id",
      "solicitante",
      "celular",
      "correo",
      "inmueble",
      "barrio",
      "medio",
      "prioridad",
      "tema",
      "seguimiento",
      "encargado_seguimiento",
      "fecha_seguimiento_desde",
      "fecha_seguimiento_hasta",
      "sin_actualizar",
      "estado_administrativo",
      "codigo",
      "gestion",
      "tipo",
      "ruta",
      "estado_actualizacion",
      "estado_aviso",
      "fecha_desde",
      "fecha_hasta",
      "sla_filter",
      "page",
    ].forEach(function (key) {
      data.append(key, nextUrl.searchParams.get(key) || "");
    });
    return request("commercial_tickets_filter", data, listRequest.signal)
      .then(function (response) {
        ticketsPanel.innerHTML = response.html || "";
        globalFilter = root.querySelector("[data-commercial-global-filter-form]");
        if (response.tabs_html) {
          if (tabsNav) tabsNav.outerHTML = response.tabs_html;
          tabsNav = root.querySelector("[data-commercial-tabs]");
        }
        globalFilter = root.querySelector("[data-commercial-global-filter-form]");
        setVisiblePanel(tab);
        if (options.history !== false) updateHistory(nextUrl, !!options.replace);
        maybeAutoOpenAdvisory();
        initHomeControls();
        if (options.focus) {
          var heading = ticketsPanel.querySelector("h2");
          if (heading) {
            heading.setAttribute("tabindex", "-1");
            heading.focus({ preventScroll: true });
          }
        }
      })
      .catch(function (error) {
        if (error.name !== "AbortError") notify("error", error.message);
      })
      .finally(function () {
        ticketsPanel.classList.remove("is-loading");
        ticketsPanel.removeAttribute("aria-busy");
      });
  }

  function showCaseModal(open) {
    if (!caseModal) return;
    caseModal.classList.toggle("open", open);
    caseModal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (!open) {
      currentCasePk = "";
      if (detailRequest) detailRequest.abort();
      if (lastFocused && typeof lastFocused.focus === "function") lastFocused.focus();
    }
  }

  function loadCase(ticketPk, preserveFocus) {
    if (!caseContent || !ticketPk) return Promise.resolve();
    if (detailRequest) detailRequest.abort();
    detailRequest = new AbortController();
    if (!preserveFocus) caseContent.innerHTML = loadingMarkup();
    caseContent.setAttribute("aria-busy", "true");
    return request("commercial_ticket_detail", { ticket_pk: ticketPk }, detailRequest.signal)
      .then(function (response) {
        caseContent.innerHTML = response.html || "";
        caseContent.removeAttribute("aria-busy");
        var firstAction = caseContent.querySelector("[data-commercial-open-workflow]");
        if (!preserveFocus && firstAction) firstAction.focus({ preventScroll: true });
      })
      .catch(function (error) {
        if (error.name === "AbortError") return;
        caseContent.removeAttribute("aria-busy");
        caseContent.innerHTML = '<div class="commercial-case-error"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><h2>No pudimos abrir la tarea</h2><p></p><button type="button" class="commercial-primary-btn" data-commercial-retry-case>Reintentar</button></div>';
        var paragraph = caseContent.querySelector("p");
        if (paragraph) paragraph.textContent = error.message;
      });
  }

  function openCase(button) {
    if (!caseModal) return;
    currentCasePk = button.getAttribute("data-commercial-open-case") || "";
    if (!currentCasePk) return;
    lastFocused = button;
    showCaseModal(true);
    loadCase(currentCasePk, false);
  }

  function closeWorkflows() {
    if (!caseContent) return;
    var stack = caseContent.querySelector("[data-commercial-workflow-stack]");
    caseContent.querySelectorAll("[data-commercial-workflow-form]").forEach(function (form) { form.hidden = true; });
    caseContent.querySelectorAll("[data-commercial-open-workflow]").forEach(function (button) {
      button.classList.remove("active");
      button.removeAttribute("aria-pressed");
    });
    if (stack) stack.hidden = true;
  }

  function openWorkflow(button) {
    if (!caseContent) return;
    var key = button.getAttribute("data-commercial-open-workflow") || "";
    var form = caseContent.querySelector('[data-commercial-workflow-form="' + key + '"]');
    var stack = caseContent.querySelector("[data-commercial-workflow-stack]");
    if (!form || !stack) return;
    closeWorkflows();
    stack.hidden = false;
    form.hidden = false;
    button.classList.add("active");
    button.setAttribute("aria-pressed", "true");
    var field = form.querySelector("textarea, select, input:not([type=hidden])");
    if (field) field.focus({ preventScroll: true });
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function submitWorkflow(form) {
    var key = form.getAttribute("data-commercial-workflow-form") || "";
    var action = actionMap[key];
    if (!action) return;
    var submit = form.querySelector('button[type="submit"]');
    var message = form.querySelector(".commercial-form-message");
    var originalText = submit ? submit.textContent : "";
    if (submit) {
      submit.disabled = true;
      submit.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Guardando…';
    }
    if (message) message.textContent = "Procesando la acción…";
    request(action, new FormData(form))
      .then(function (response) {
        notify("success", response.message || "Acción guardada.");
        var movesTicket = ["postpone", "activate", "close", "status"].indexOf(key) !== -1;
        var refreshUrl = window.location.href;
        if (movesTicket) {
          showCaseModal(false);
          return loadTickets(refreshUrl, { history: false });
        }
        return Promise.all([loadCase(currentCasePk, true), loadTickets(refreshUrl, { history: false })]);
      })
      .catch(function (error) {
        if (message) message.textContent = error.message;
        notify("error", error.message);
      })
      .finally(function () {
        if (submit && document.contains(submit)) {
          submit.disabled = false;
          submit.textContent = originalText;
        }
      });
  }

  function homeControlPanel(name) {
    return root.querySelector('[data-commercial-home-control-panel="' + name + '"]');
  }

  function setStandaloneModal(modal, open) {
    if (!modal) return;
    modal.classList.toggle("open", open);
    modal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
  }

  function safeJsonFromAttr(element, attr) {
    try {
      return JSON.parse(element.getAttribute(attr) || "{}");
    } catch (_error) {
      return {};
    }
  }

  function propertyInfoMarkup(info) {
    info = info || {};
    function row(label, value) {
      return "<div><span>" + escapeHtml(label) + "</span><strong>" + escapeHtml(value || "-") + "</strong></div>";
    }
    return (
      '<header class="commercial-property-modal-head"><span>Ficha técnica</span><h2>Inmueble ' + escapeHtml(info.codigo || "") + "</h2></header>" +
      '<div class="commercial-property-modal-grid">' +
      '<section><h3>Resumen del inmueble</h3>' +
      row("Arriendo", info.val_arr) + row("Venta", info.val_ven) + row("Administración", info.val_adm) +
      row("Tipo", info.tipo_inmueble) + row("Barrio", info.barrio) + row("Dirección", info.dir_full) +
      row("Área privada", (info.area_priv || "-") + " m²") + row("Área construida", (info.area_cons || "-") + " m²") + row("Hab / Baños", (info.habs || "-") + " / " + (info.banos || "-")) +
      "</section>" +
      '<section><h3>Propietario</h3>' +
      row("Nombre", info.prop_nombre) + row("Email", info.prop_email) + row("Celular", info.prop_cel) +
      "</section>" +
      '<section><h3>Gestión de llaves</h3>' +
      row("Ubicación", info.llaves_ubi) + row("En dónde", info.llaves_donde) + row("Contacto", info.llaves_contacto) + row("Teléfono", info.llaves_tel) +
      "</section>" +
      "</div>"
    );
  }

  function signDetailMarkup(row) {
    row = row || {};
    var maps = row.maps_url ? '<a class="commercial-control-btn commercial-control-btn--primary" target="_blank" rel="noopener" href="' + escapeHtml(row.maps_url) + '">Ver en Google Maps</a>' : "";
    return (
      '<header class="commercial-property-modal-head"><span>Detalle del aviso</span><h2>Inmueble ' + escapeHtml(row.codigo || "") + "</h2></header>" +
      '<div class="commercial-sign-detail-grid">' +
      "<div><span>Funcionario</span><strong>" + escapeHtml(row.funcionario || "-") + "</strong></div>" +
      "<div><span>Tipo</span><strong>" + escapeHtml(row.tipo || "-") + "</strong></div>" +
      "<div><span>Gestión</span><strong>" + escapeHtml(row.gestion || "-") + "</strong></div>" +
      "<div><span>Estado</span><strong>" + escapeHtml(row.estado || "-") + "</strong></div>" +
      "<div><span>Días</span><strong>" + escapeHtml((row.dias || 0) + " / " + (row.max || 0)) + "</strong></div>" +
      "<div><span>Fecha base</span><strong>" + escapeHtml(row.fecha || "-") + "</strong></div>" +
      "<div><span>Barrio</span><strong>" + escapeHtml(row.barrio || "-") + "</strong></div>" +
      "<div><span>Ruta</span><strong>" + escapeHtml(row.ruta || "-") + "</strong></div>" +
      "</div>" +
      (maps ? '<footer class="commercial-control-actions">' + maps + "</footer>" : "")
    );
  }

  function loadPropertyUpdates() {
    var form = root.querySelector("[data-commercial-property-control]");
    var rows = root.querySelector("[data-commercial-property-rows]");
    var total = root.querySelector("[data-commercial-property-total]");
    var stats = root.querySelector("[data-commercial-property-stats]");
    if (!form || !rows) return;
    rows.innerHTML = '<tr><td colspan="6" class="commercial-control-empty-cell">Cargando inmuebles…</td></tr>';
    request("commercial_property_updates", new FormData(form))
      .then(function (response) {
        rows.innerHTML = response.html || "";
        if (total) total.textContent = (response.total || 0) + " inmuebles";
        if (stats) {
          var s = response.stats || {};
          stats.textContent = "Al día: " + (s.ok || 0) + " · Alerta: " + (s.alerta || 0) + " · Vencidos: " + (s.vencido || 0);
        }
      })
      .catch(function (error) {
        rows.innerHTML = '<tr><td colspan="6" class="commercial-control-empty-cell">' + escapeHtml(error.message) + "</td></tr>";
      });
  }

  function loadSignsControl() {
    var form = root.querySelector("[data-commercial-sign-control]");
    var rows = root.querySelector("[data-commercial-sign-rows]");
    var total = root.querySelector("[data-commercial-sign-total]");
    if (!form || !rows) return;
    var data = new FormData(form);
    data.set("mode", form.getAttribute("data-mode") || "maintenance");
    rows.innerHTML = '<div class="commercial-control-table-state">Cargando avisos…</div>';
    request("commercial_signs_control", data)
      .then(function (response) {
        rows.innerHTML = response.html || "";
        if (total) total.textContent = (response.total || 0) + " avisos";
      })
      .catch(function (error) {
        rows.innerHTML = '<div class="commercial-control-empty-cell">' + escapeHtml(error.message) + "</div>";
      });
  }

  function initHomeControls() {
    if (!root.querySelector("[data-commercial-home-controls]")) return;
    if (homeControlPanel("updates") && homeControlPanel("updates").classList.contains("active")) loadPropertyUpdates();
    if (homeControlPanel("signs") && homeControlPanel("signs").classList.contains("active")) loadSignsControl();
  }

  function analyzeCase(button) {
    if (!caseContent) return;
    var ticketPk = button.getAttribute("data-ticket-pk") || currentCasePk || "";
    if (!ticketPk) return;
    var originalText = button.textContent;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><span>Analizando…</span>';
    setAnalysisModal(true, assistantLoadingMarkup());
    request("commercial_ticket_analyze", { ticket_pk: ticketPk })
      .then(function (response) {
        updateAnalysisList(response.analyses || []);
        setAnalysisModal(true, assistantAnalysisMarkup(response.analysis || {}));
        notify("success", response.message || "Análisis guardado.");
      })
      .catch(function (error) {
        setAnalysisModal(true, assistantErrorMarkup(error.message));
        notify("error", error.message);
      })
      .finally(function () {
        if (document.contains(button)) {
          button.disabled = false;
          button.innerHTML = '<i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span>' + escapeHtml(originalText || "Analizar con asistente") + "</span>";
        }
      });
  }

  function assistantSuggestedText(button) {
    var suggested = button.closest(".commercial-assistant-suggested");
    return suggested ? (suggested.querySelector("p") || {}).textContent || "" : "";
  }

  function useAssistantMessage(button) {
    if (!caseContent) return;
    var text = assistantSuggestedText(button);
    if (!text) return;
    var replyButton = caseContent.querySelector('[data-commercial-open-workflow="reply"]');
    var replyForm = caseContent.querySelector('[data-commercial-workflow-form="reply"]');
    if (!replyButton || !replyForm) {
      notify("error", "No tienes disponible la acción Responder para esta tarea.");
      return;
    }
    openWorkflow(replyButton);
    var textarea = replyForm.querySelector('textarea[name="respuesta"]');
    if (textarea) {
      textarea.value = text;
      textarea.dispatchEvent(new Event("input", { bubbles: true }));
      textarea.focus({ preventScroll: true });
    }
    setAnalysisModal(false);
    notify("success", "Mensaje sugerido cargado en Responder.");
  }

  function deleteAnalysis(button) {
    var ticketPk = button.getAttribute("data-ticket-pk") || currentCasePk || "";
    var analysisId = button.getAttribute("data-analysis-id") || "";
    if (!ticketPk || !analysisId) return;
    confirmAnalysisDelete().then(function (confirmed) {
      if (!confirmed) return;
      button.disabled = true;
      request("commercial_ticket_analysis_delete", { ticket_pk: ticketPk, analysis_id: analysisId })
        .then(function (response) {
          updateAnalysisList(response.analyses || []);
          setAnalysisModal(false);
          notify("success", response.message || "Análisis eliminado.");
        })
        .catch(function (error) {
          notify("error", error.message);
        })
        .finally(function () {
          if (document.contains(button)) button.disabled = false;
        });
    });
  }

  root.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-commercial-tab]");
    var filterLink = event.target.closest("[data-commercial-filter-link]");
    var globalClear = event.target.closest("[data-commercial-global-clear]");
    var openButton = event.target.closest("[data-commercial-open-case]");
    var openAdvisory = event.target.closest("[data-commercial-open-advisory]");
    var controlTab = event.target.closest("[data-commercial-home-control-tab]");
    var signTab = event.target.closest("[data-commercial-sign-tab]");
    var controlClear = event.target.closest("[data-commercial-control-clear]");
    var propertyInfo = event.target.closest("[data-commercial-property-info]");
    var signDetail = event.target.closest("[data-commercial-sign-detail]");
    var closeProperty = event.target.closest("[data-commercial-close-property]");
    var closeSignDetail = event.target.closest("[data-commercial-close-sign-detail]");
    var copyTable = event.target.closest("[data-commercial-copy-table]");
    if (event.target && event.target.matches && event.target.matches("#commercial-property-modal")) {
      setStandaloneModal(event.target, false);
      return;
    }
    if (event.target && event.target.matches && event.target.matches("#commercial-sign-detail-modal")) {
      setStandaloneModal(event.target, false);
      return;
    }
    if (controlTab) {
      event.preventDefault();
      var panelName = controlTab.getAttribute("data-commercial-home-control-tab") || "updates";
      root.querySelectorAll("[data-commercial-home-control-tab]").forEach(function (button) { button.classList.toggle("active", button === controlTab); });
      root.querySelectorAll("[data-commercial-home-control-panel]").forEach(function (panel) { panel.classList.toggle("active", panel.getAttribute("data-commercial-home-control-panel") === panelName); });
      if (panelName === "updates") loadPropertyUpdates();
      if (panelName === "signs") loadSignsControl();
      return;
    }
    if (signTab) {
      event.preventDefault();
      var mode = signTab.getAttribute("data-commercial-sign-tab") || "maintenance";
      root.querySelectorAll("[data-commercial-sign-tab]").forEach(function (button) { button.classList.toggle("active", button === signTab); });
      var signForm = root.querySelector("[data-commercial-sign-control]");
      if (signForm) signForm.setAttribute("data-mode", mode);
      loadSignsControl();
      return;
    }
    if (controlClear) {
      event.preventDefault();
      var controlForm = controlClear.closest("form");
      if (controlForm) {
        Array.prototype.forEach.call(controlForm.elements, function (field) {
          if (!field.name) return;
          if (field.tagName === "SELECT") field.selectedIndex = 0;
          else if (field.type !== "hidden") field.value = "";
        });
        if (controlForm.matches("[data-commercial-property-control]")) loadPropertyUpdates();
        if (controlForm.matches("[data-commercial-sign-control]")) loadSignsControl();
      }
      return;
    }
    if (propertyInfo) {
      event.preventDefault();
      var propertyModal = document.getElementById("commercial-property-modal");
      var propertyContent = propertyModal && propertyModal.querySelector("[data-commercial-property-modal-content]");
      if (propertyContent) propertyContent.innerHTML = propertyInfoMarkup(safeJsonFromAttr(propertyInfo, "data-commercial-property-info"));
      setStandaloneModal(propertyModal, true);
      return;
    }
    if (signDetail) {
      event.preventDefault();
      var signModal = document.getElementById("commercial-sign-detail-modal");
      var signContent = signModal && signModal.querySelector("[data-commercial-sign-detail-content]");
      if (signContent) signContent.innerHTML = signDetailMarkup(safeJsonFromAttr(signDetail, "data-commercial-sign-detail"));
      setStandaloneModal(signModal, true);
      return;
    }
    if (closeProperty) {
      event.preventDefault();
      setStandaloneModal(document.getElementById("commercial-property-modal"), false);
      return;
    }
    if (closeSignDetail) {
      event.preventDefault();
      setStandaloneModal(document.getElementById("commercial-sign-detail-modal"), false);
      return;
    }
    if (copyTable) {
      event.preventDefault();
      var table = document.getElementById(copyTable.getAttribute("data-commercial-copy-table") || "");
      if (table && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(table.innerText || "").then(function () { notify("success", "Tabla copiada."); });
      }
      return;
    }
    if (tab) {
      event.preventDefault();
      var key = tab.getAttribute("data-commercial-tab") || "abiertos";
      if (key === "calendario") {
        setVisiblePanel(key);
        updateHistory(normalizedUrl(tab.href), false);
      } else loadTickets(tab.href, { focus: true });
      return;
    }
    if (filterLink) {
      event.preventDefault();
      if (filterLink.closest("[data-commercial-advisory-modal]")) showAdvisoryModal(false);
      var rawHref = filterLink.getAttribute("href") || "";
      if (rawHref.charAt(0) === "#") {
        var target = document.querySelector(rawHref);
        if (rawHref === "#commercial-signs-panel") {
          var signsTab = root.querySelector('[data-commercial-home-control-tab="signs"]');
          if (signsTab) signsTab.click();
        } else if (rawHref === "#commercial-property-updates-panel") {
          var updatesTab = root.querySelector('[data-commercial-home-control-tab="updates"]');
          if (updatesTab) updatesTab.click();
        }
        target = document.querySelector(rawHref);
        if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }
      loadTickets(filterLink.href, { focus: false });
      return;
    }
    if (globalClear) {
      event.preventDefault();
      loadTickets(globalClear.href, { focus: false });
      return;
    }
    if (openButton) {
      event.preventDefault();
      openCase(openButton);
      return;
    }
    if (openAdvisory) {
      event.preventDefault();
      showAdvisoryModal(true, openAdvisory);
    }
  });

  root.addEventListener("submit", function (event) {
    var propertyControlForm = event.target.closest("[data-commercial-property-control]");
    if (propertyControlForm) {
      event.preventDefault();
      loadPropertyUpdates();
      return;
    }
    var signControlForm = event.target.closest("[data-commercial-sign-control]");
    if (signControlForm) {
      event.preventDefault();
      loadSignsControl();
      return;
    }
    var globalForm = event.target.closest("[data-commercial-global-filter-form]");
    if (globalForm) {
      event.preventDefault();
      var globalUrl = normalizedUrl(globalForm.action);
      var currentTab = (globalForm.querySelector('[name="tab"]') || {}).value || activeTab();
      globalUrl.searchParams.set("tab", currentTab === "calendario" ? "abiertos" : currentTab);
      new FormData(globalForm).forEach(function (value, key) {
        if (key === "tab") return;
        if (String(value).trim() === "") globalUrl.searchParams.delete(key);
        else globalUrl.searchParams.set(key, String(value));
      });
      globalUrl.searchParams.delete("estado");
      globalUrl.searchParams.delete("page");
      loadTickets(globalUrl.href, { focus: false });
      return;
    }
    var filterForm = event.target.closest("[data-commercial-filter-form]");
    if (!filterForm) return;
    event.preventDefault();
    var url = normalizedUrl(filterForm.action);
    new FormData(filterForm).forEach(function (value, key) {
      if (String(value).trim() === "") url.searchParams.delete(key);
      else url.searchParams.set(key, String(value));
    });
    url.searchParams.delete("page");
    loadTickets(url.href, { focus: false });
  });

  root.addEventListener("change", function (event) {
    var form = event.target.closest("[data-commercial-global-filter-form]");
    if (form && event.target.matches('select[name="id_empleado"]')) {
      form.requestSubmit();
    }
  });

  if (caseModal) {
    caseModal.addEventListener("click", function (event) {
      if (event.target && event.target.matches && event.target.matches("[data-commercial-analysis-modal]")) {
        setAnalysisModal(false);
        return;
      }
      if (event.target === caseModal || event.target.closest("[data-commercial-close-case]")) {
        showCaseModal(false);
        return;
      }
      var workflowButton = event.target.closest("[data-commercial-open-workflow]");
      if (workflowButton) {
        openWorkflow(workflowButton);
        return;
      }
      var assistantButton = event.target.closest("[data-commercial-assistant]");
      if (assistantButton) {
        event.preventDefault();
        analyzeCase(assistantButton);
        return;
      }
      var openAnalysis = event.target.closest("[data-commercial-open-analysis]");
      if (openAnalysis) {
        event.preventDefault();
        setAnalysisModal(true, assistantAnalysisMarkup(parseAnalysisButton(openAnalysis)));
        return;
      }
      var deleteAnalysisButton = event.target.closest("[data-commercial-delete-analysis]");
      if (deleteAnalysisButton) {
        event.preventDefault();
        deleteAnalysis(deleteAnalysisButton);
        return;
      }
      if (event.target.closest("[data-commercial-close-assistant]")) {
        setAnalysisModal(false);
        return;
      }
      var copyAssistant = event.target.closest("[data-commercial-copy-assistant-message]");
      if (copyAssistant) {
        event.preventDefault();
        var text = assistantSuggestedText(copyAssistant);
        if (text && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { notify("success", "Mensaje sugerido copiado."); });
        }
        return;
      }
      var useAssistant = event.target.closest("[data-commercial-use-assistant-message]");
      if (useAssistant) {
        event.preventDefault();
        useAssistantMessage(useAssistant);
        return;
      }
      if (event.target.closest("[data-commercial-close-workflow]")) {
        closeWorkflows();
        return;
      }
      var addDocument = event.target.closest("[data-add-ticket-document]");
      if (addDocument) {
        event.preventDefault();
        var form = addDocument.closest("form");
        var docsWrap = form ? form.querySelector("[data-ticket-documents]") : null;
        if (docsWrap) docsWrap.insertAdjacentHTML("beforeend", documentRowMarkup(addDocument.getAttribute("data-document-accept") || ""));
        return;
      }
      var removeDocument = event.target.closest("[data-remove-ticket-document]");
      if (removeDocument) {
        event.preventDefault();
        var row = removeDocument.closest(".commercial-ticket-document-row, .scm-ticket-document-row");
        if (row) row.remove();
        return;
      }
      if (event.target.closest("[data-commercial-retry-case]")) loadCase(currentCasePk, false);
    });
    caseModal.addEventListener("submit", function (event) {
      var form = event.target.closest("[data-commercial-workflow-form]");
      if (!form) return;
      event.preventDefault();
      submitWorkflow(form);
    });
  }

  document.addEventListener("paste", handlePasteEvidence);

  var permissionModal = document.getElementById("commercial-permissions-modal");
  var permissionOpen = document.getElementById("commercial-open-permissions");
  var permissionForm = document.getElementById("commercial-permissions-form");
  function setPermissionModal(open) {
    if (!permissionModal) return;
    permissionModal.classList.toggle("open", open);
    permissionModal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (open) {
      lastFocused = document.activeElement;
      var close = permissionModal.querySelector("[data-commercial-close-permissions]");
      if (close) close.focus();
    } else if (lastFocused && typeof lastFocused.focus === "function") lastFocused.focus();
  }
  if (permissionOpen) permissionOpen.addEventListener("click", function () { setPermissionModal(true); });
  if (permissionModal) {
    permissionModal.addEventListener("click", function (event) {
      if (event.target === permissionModal || event.target.closest("[data-commercial-close-permissions]")) setPermissionModal(false);
    });
  }
  if (permissionForm) {
    permissionForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var submit = permissionForm.querySelector('button[type="submit"]');
      var message = permissionForm.querySelector("[data-commercial-permissions-message]");
      if (submit) submit.disabled = true;
      if (message) message.textContent = "Guardando configuración…";
      request("commercial_permissions_save", new FormData(permissionForm))
        .then(function (response) {
          if (message) message.textContent = response.message || "Configuración guardada.";
          notify("success", "Visibilidad y acciones actualizadas.");
        })
        .catch(function (error) {
          if (message) message.textContent = error.message;
          notify("error", error.message);
        })
        .finally(function () { if (submit) submit.disabled = false; });
    });
  }

  root.addEventListener("click", function (event) {
    var modal = refreshAdvisoryModalRef();
    if (!modal) return;
    if (event.target === modal || event.target.closest("[data-commercial-close-advisory]")) {
      event.preventDefault();
      showAdvisoryModal(false);
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var modal = analysisModal();
    if (modal && modal.classList.contains("open")) setAnalysisModal(false);
    else if (document.getElementById("commercial-property-modal") && document.getElementById("commercial-property-modal").classList.contains("open")) setStandaloneModal(document.getElementById("commercial-property-modal"), false);
    else if (document.getElementById("commercial-sign-detail-modal") && document.getElementById("commercial-sign-detail-modal").classList.contains("open")) setStandaloneModal(document.getElementById("commercial-sign-detail-modal"), false);
    else if (caseModal && caseModal.classList.contains("open")) showCaseModal(false);
    else if (refreshAdvisoryModalRef() && advisoryModal.classList.contains("open")) showAdvisoryModal(false);
    else if (permissionModal && permissionModal.classList.contains("open")) setPermissionModal(false);
  });
  window.addEventListener("popstate", function () {
    var url = normalizedUrl(window.location.href);
    var tab = url.searchParams.get("tab") || "abiertos";
    if (tab === "calendario") setVisiblePanel(tab);
    else loadTickets(url.href, { history: false, focus: true });
  });
  if (activeTab() === "calendario") window.setTimeout(function () { setVisiblePanel("calendario"); }, 0);
  if (activeTab() === "inicio") window.setTimeout(maybeAutoOpenAdvisory, 0);
  if (root.querySelector("[data-commercial-home-controls]")) window.setTimeout(initHomeControls, 0);
})();
