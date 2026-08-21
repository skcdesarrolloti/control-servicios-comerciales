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
  var statuses = (runtime.config && runtime.config.commercial_statuses) || [];

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function notify(type, message) {
    if (window.Swal && typeof window.Swal.fire === "function") {
      window.Swal.fire({
        icon: type,
        title: type === "success" ? "Cambio guardado" : "No se pudo guardar",
        text: message,
        timer: type === "success" ? 1800 : undefined,
        timerProgressBar: type === "success",
        confirmButtonColor: "#1f4f99",
      });
      return;
    }
    window.alert(message);
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", nonce);
    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key]);
    });
    return fetch(apiUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "La operación no pudo completarse.");
        }
        return json.data || {};
      });
  }

  function employeesForCard(card) {
    var script = card.querySelector("[data-commercial-employees]");
    if (!script) return [];
    try {
      var rows = JSON.parse(script.textContent || "[]");
      return Array.isArray(rows) ? rows : [];
    } catch (_error) {
      return [];
    }
  }

  function actionPanel(card) {
    return card.querySelector("[data-commercial-action-panel]");
  }

  function closeActionPanels(except) {
    root.querySelectorAll("[data-commercial-action-panel]").forEach(function (panel) {
      if (panel !== except) {
        panel.hidden = true;
        panel.innerHTML = "";
      }
    });
  }

  function renderStatusForm(card, panel, currentStatus) {
    panel.innerHTML =
      '<form class="commercial-quick-form" data-commercial-status-form>' +
      '<label><span>Nuevo estado comercial</span><select name="estado" required>' +
      '<option value="">Selecciona un estado</option>' +
      statuses.map(function (status) {
        return '<option value="' + escapeHtml(status) + '"' + (status === currentStatus ? " selected" : "") + ">" + escapeHtml(status) + "</option>";
      }).join("") +
      "</select></label>" +
      '<div><button type="button" class="commercial-secondary-btn" data-commercial-cancel-action>Cancelar</button><button type="submit" class="commercial-primary-btn">Guardar estado</button></div>' +
      '<span class="commercial-form-message" aria-live="polite"></span>' +
      "</form>";
    panel.hidden = false;
    var select = panel.querySelector("select");
    if (select) select.focus();
  }

  function renderReassignForm(card, panel) {
    var employees = employeesForCard(card);
    panel.innerHTML =
      '<form class="commercial-quick-form" data-commercial-reassign-form>' +
      '<label><span>Nuevo responsable</span><select name="id_empleado" required>' +
      '<option value="">Selecciona un funcionario</option>' +
      employees.map(function (employee) {
        return '<option value="' + escapeHtml(employee.id || employee.id_empleado) + '">' + escapeHtml(employee.nombre || "Funcionario") + "</option>";
      }).join("") +
      "</select></label>" +
      '<div><button type="button" class="commercial-secondary-btn" data-commercial-cancel-action>Cancelar</button><button type="submit" class="commercial-primary-btn">Guardar responsable</button></div>' +
      '<span class="commercial-form-message" aria-live="polite"></span>' +
      "</form>";
    panel.hidden = false;
    var select = panel.querySelector("select");
    if (select) select.focus();
  }

  root.addEventListener("click", function (event) {
    var statusButton = event.target.closest("[data-commercial-change-status]");
    var reassignButton = event.target.closest("[data-commercial-reassign]");
    var cancelButton = event.target.closest("[data-commercial-cancel-action]");
    if (cancelButton) {
      var cancelPanel = cancelButton.closest("[data-commercial-action-panel]");
      if (cancelPanel) {
        cancelPanel.hidden = true;
        cancelPanel.innerHTML = "";
      }
      return;
    }
    if (!statusButton && !reassignButton) return;
    var card = (statusButton || reassignButton).closest("[data-commercial-ticket]");
    if (!card) return;
    var panel = actionPanel(card);
    if (!panel) return;
    closeActionPanels(panel);
    if (statusButton) {
      renderStatusForm(card, panel, statusButton.getAttribute("data-current-status") || "");
    } else {
      renderReassignForm(card, panel);
    }
  });

  root.addEventListener("submit", function (event) {
    var form = event.target;
    var isStatus = form.matches("[data-commercial-status-form]");
    var isReassign = form.matches("[data-commercial-reassign-form]");
    if (!isStatus && !isReassign) return;
    event.preventDefault();
    var card = form.closest("[data-commercial-ticket]");
    var submit = form.querySelector('button[type="submit"]');
    var message = form.querySelector(".commercial-form-message");
    var data = new FormData(form);
    if (submit) submit.disabled = true;
    if (message) message.textContent = "Guardando…";
    post(isStatus ? "commercial_ticket_status" : "commercial_ticket_reassign", {
      ticket_pk: card ? card.getAttribute("data-commercial-ticket") || "" : "",
      estado: data.get("estado") || "",
      id_empleado: data.get("id_empleado") || "",
    }).then(function (response) {
      notify("success", response.message || "Ticket actualizado.");
      window.setTimeout(function () { window.location.reload(); }, 650);
    }).catch(function (error) {
      if (message) message.textContent = error.message;
      notify("error", error.message);
      if (submit) submit.disabled = false;
    });
  });

  var permissionModal = document.getElementById("commercial-permissions-modal");
  var permissionOpen = document.getElementById("commercial-open-permissions");
  var permissionForm = document.getElementById("commercial-permissions-form");
  var lastFocused = null;

  function setPermissionModal(open) {
    if (!permissionModal) return;
    permissionModal.classList.toggle("open", open);
    permissionModal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open);
    if (open) {
      lastFocused = document.activeElement;
      var close = permissionModal.querySelector("[data-commercial-close-permissions]");
      if (close) close.focus();
    } else if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  if (permissionOpen) permissionOpen.addEventListener("click", function () { setPermissionModal(true); });
  if (permissionModal) {
    permissionModal.addEventListener("click", function (event) {
      if (event.target === permissionModal || event.target.closest("[data-commercial-close-permissions]")) {
        setPermissionModal(false);
      }
    });
  }

  if (permissionForm) {
    permissionForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var submit = permissionForm.querySelector('button[type="submit"]');
      var message = permissionForm.querySelector("[data-commercial-permissions-message]");
      var body = new FormData(permissionForm);
      body.append("action", "commercial_permissions_save");
      body.append("nonce", nonce);
      if (submit) submit.disabled = true;
      if (message) message.textContent = "Guardando configuración…";
      fetch(apiUrl, { method: "POST", credentials: "same-origin", body: body })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo guardar.");
          if (message) message.textContent = "Configuración guardada.";
          notify("success", "La visibilidad y las acciones quedaron actualizadas.");
        })
        .catch(function (error) {
          if (message) message.textContent = error.message;
          notify("error", error.message);
        })
        .finally(function () { if (submit) submit.disabled = false; });
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && permissionModal && permissionModal.classList.contains("open")) {
      setPermissionModal(false);
    }
  });
})();
