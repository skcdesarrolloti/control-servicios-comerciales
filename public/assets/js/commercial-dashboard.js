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

  function assistantBlock(title, content, icon) {
    return (
      '<section class="commercial-assistant-block">' +
      '<h4><i class="fas ' + escapeHtml(icon || "fa-circle-info") + '" aria-hidden="true"></i>' + escapeHtml(title) + "</h4>" +
      content +
      "</section>"
    );
  }

  function assistantLoadingMarkup() {
    return (
      '<div class="commercial-assistant-loading">' +
      '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i>' +
      '<div><h3>Analizando tarea con MiniMax…</h3><p>Estoy revisando datos de la tarea, inmueble, historial, respuestas, seguimientos y notas.</p></div>' +
      "</div>"
    );
  }

  function assistantErrorMarkup(message) {
    return (
      '<div class="commercial-assistant-error">' +
      '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>' +
      '<div><h3>No se pudo analizar la tarea</h3><p>' + escapeHtml(message || "Inténtalo nuevamente.") + "</p></div>" +
      "</div>"
    );
  }

  function assistantAnalysisMarkup(analysis) {
    analysis = analysis || {};
    var summary = escapeHtml(analysis.resumen || "Sin resumen generado.");
    var client = analysis.cliente ? '<p>' + escapeHtml(analysis.cliente) + "</p>" : "";
    var currentStatus = analysis.estado_actual ? '<p>' + escapeHtml(analysis.estado_actual) + "</p>" : "";
    var suggested = analysis.mensaje_sugerido ? '<div class="commercial-assistant-suggested"><div class="commercial-assistant-suggested-actions"><button type="button" class="commercial-secondary-btn" data-commercial-use-assistant-message><i class="fas fa-reply" aria-hidden="true"></i> Usar en respuesta</button><button type="button" class="commercial-secondary-btn" data-commercial-copy-assistant-message><i class="fas fa-copy" aria-hidden="true"></i> Copiar mensaje</button></div><p>' + escapeHtml(analysis.mensaje_sugerido) + "</p></div>" : '<p class="commercial-assistant-muted">Sin mensaje sugerido.</p>';
    return (
      '<header class="commercial-assistant-result-head">' +
      '<div><span>Asistente comercial</span><h3>Análisis de la tarea</h3><p>Generado con ' + escapeHtml(analysis.model || "MiniMax") + (analysis.generated_at ? " · " + escapeHtml(analysis.generated_at) : "") + "</p></div>" +
      '<button type="button" class="commercial-modal-close commercial-assistant-close" data-commercial-close-assistant aria-label="Cerrar análisis">&times;</button>' +
      "</header>" +
      '<div class="commercial-assistant-summary"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><p>' + summary + "</p></div>" +
      '<div class="commercial-assistant-grid">' +
      (client ? assistantBlock("Cliente", client, "fa-user") : "") +
      (currentStatus ? assistantBlock("Estado actual", currentStatus, "fa-clipboard-check") : "") +
      assistantBlock("Riesgos", assistantList(analysis.riesgos), "fa-triangle-exclamation") +
      assistantBlock("Oportunidades", assistantList(analysis.oportunidades), "fa-bullseye") +
      assistantBlock("Recomendaciones", assistantList(analysis.recomendaciones), "fa-lightbulb") +
      assistantBlock("Próximos pasos", assistantList(analysis.proximos_pasos), "fa-list-check") +
      assistantBlock("Datos faltantes", assistantList(analysis.datos_faltantes), "fa-circle-question") +
      assistantBlock("Mensaje sugerido para el cliente", suggested, "fa-message") +
      "</div>"
    );
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
      return response.json().catch(function () {
        throw new Error("El servidor devolvió una respuesta no válida.");
      }).then(function (json) {
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

  function analyzeCase(button) {
    if (!caseContent) return;
    var ticketPk = button.getAttribute("data-ticket-pk") || currentCasePk || "";
    var panel = caseContent.querySelector("[data-commercial-assistant-panel]");
    if (!ticketPk || !panel) return;
    var originalText = button.textContent;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><span>Analizando…</span>';
    panel.hidden = false;
    panel.innerHTML = assistantLoadingMarkup();
    panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
    request("commercial_ticket_analyze", { ticket_pk: ticketPk })
      .then(function (response) {
        panel.innerHTML = assistantAnalysisMarkup(response.analysis || {});
      })
      .catch(function (error) {
        panel.innerHTML = assistantErrorMarkup(error.message);
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
    notify("success", "Mensaje sugerido cargado en Responder.");
  }

  root.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-commercial-tab]");
    var filterLink = event.target.closest("[data-commercial-filter-link]");
    var globalClear = event.target.closest("[data-commercial-global-clear]");
    var openButton = event.target.closest("[data-commercial-open-case]");
    var openAdvisory = event.target.closest("[data-commercial-open-advisory]");
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
      if (event.target.closest("[data-commercial-close-assistant]")) {
        var assistantPanel = caseContent && caseContent.querySelector("[data-commercial-assistant-panel]");
        if (assistantPanel) {
          assistantPanel.hidden = true;
          assistantPanel.innerHTML = "";
        }
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
    if (caseModal && caseModal.classList.contains("open")) showCaseModal(false);
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
})();
