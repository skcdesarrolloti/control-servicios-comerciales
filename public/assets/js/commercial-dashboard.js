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
  var caseModal = document.getElementById("commercial-case-modal");
  var caseContent = caseModal && caseModal.querySelector("[data-commercial-case-content]");
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
    return '<div class="commercial-case-loading"><span></span><span></span><span></span><p>Cargando información del caso…</p></div>';
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

  function setActiveTab(tab) {
    root.querySelectorAll("[data-commercial-tab]").forEach(function (link) {
      var isActive = link.getAttribute("data-commercial-tab") === tab;
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
    ["tab", "estado", "busqueda", "id_empleado", "page"].forEach(function (key) {
      data.append(key, nextUrl.searchParams.get(key) || "");
    });
    return request("commercial_tickets_filter", data, listRequest.signal)
      .then(function (response) {
        ticketsPanel.innerHTML = response.html || "";
        if (options.history !== false) updateHistory(nextUrl, !!options.replace);
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
        caseContent.innerHTML = '<div class="commercial-case-error"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><h2>No pudimos abrir el caso</h2><p></p><button type="button" class="commercial-primary-btn" data-commercial-retry-case>Reintentar</button></div>';
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

  root.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-commercial-tab]");
    var filterLink = event.target.closest("[data-commercial-filter-link]");
    var openButton = event.target.closest("[data-commercial-open-case]");
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
      loadTickets(filterLink.href, { focus: false });
      return;
    }
    if (openButton) {
      event.preventDefault();
      openCase(openButton);
    }
  });

  root.addEventListener("submit", function (event) {
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
      if (event.target.closest("[data-commercial-close-workflow]")) {
        closeWorkflows();
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

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    if (caseModal && caseModal.classList.contains("open")) showCaseModal(false);
    else if (permissionModal && permissionModal.classList.contains("open")) setPermissionModal(false);
  });
  window.addEventListener("popstate", function () {
    var url = normalizedUrl(window.location.href);
    var tab = url.searchParams.get("tab") || "abiertos";
    if (tab === "calendario") setVisiblePanel(tab);
    else loadTickets(url.href, { history: false, focus: true });
  });
  if (activeTab() === "calendario") window.setTimeout(function () { setVisiblePanel("calendario"); }, 0);
})();
