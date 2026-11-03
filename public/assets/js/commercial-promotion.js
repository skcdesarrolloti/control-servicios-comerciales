(function () {
  "use strict";
  // Promoción › Reuniones: copia de dashboard-marketing (public/assets/meetings.js) con el diseño del panel comercial.
  // Formularios y confirmaciones en popups internos (<dialog>), cambios por la API del panel (commercial_promo_meeting_*)
  // y navegación dentro del panel (scm:load-tab). Los eventos se delegan en document porque el panel llega por AJAX.
  if (window.__commercialPromotionLoaded) return;
  window.__commercialPromotionLoaded = true;

  var app = document.getElementById("scm-app");
  var runtime = {};
  try { runtime = JSON.parse((app && app.getAttribute("data-scm-runtime")) || "{}"); } catch (_error) {}

  var STATUS_LABELS = { pending: "Pendiente", in_progress: "En proceso", completed: "Realizada", not_completed: "No realizada" };
  var SECTIONS = {
    achievement: { kicker: "Logros y trabajo realizado", title: "Registrar logro", edit: "Editar logro", titleLabel: "Título del logro", contentLabel: "Resultado alcanzado", help: "Resume qué se ejecutó y cuál fue el resultado." },
    improvement: { kicker: "Puntos de mejora", title: "Registrar mejora", edit: "Editar mejora", titleLabel: "Título del punto", contentLabel: "Diagnóstico", help: "Explica qué debe optimizarse y agrega una observación si aplica." },
    action: { kicker: "Plan de acción", title: "Registrar acción", edit: "Editar acción", titleLabel: "Descripción de la acción", contentLabel: "Detalle", help: "Asigna responsable, fecha límite y, si aplica, la mejora que atiende." },
  };

  function rootOf(node) { return node && node.closest ? node.closest("[data-cpr-meetings]") : null; }
  function meetingId(root) { return (root && root.getAttribute("data-meeting-id")) || "0"; }

  async function request(action, data) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var json = null;
    try { json = await response.json(); } catch (_error) { json = null; }
    if (!response.ok || !json || !json.success) {
      throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción en Reuniones.");
    }
    return json.data || {};
  }

  function announce(root, message, isError) {
    if (window.Swal && typeof window.Swal.fire === "function") {
      window.Swal.fire({ toast: true, position: "top-end", icon: isError ? "error" : "success", title: message, showConfirmButton: false, timer: isError ? 5000 : 2600 });
    }
    var live = root && root.querySelector("[data-cpr-live]");
    if (live) {
      live.textContent = "";
      live.classList.toggle("is-error", !!isError);
      window.setTimeout(function () { live.textContent = message; }, 20);
    }
  }

  /** Abre otra pantalla de Reuniones dentro del panel; sin url recarga la actual. */
  function navigate(url) {
    var host = document.getElementById("scm-app");
    if (host) host.dispatchEvent(new CustomEvent("scm:load-tab", { detail: { url: url || "" } }));
    else window.location.href = url || window.location.href;
  }

  function setBusy(nodes, busy) {
    nodes.forEach(function (node) { if (node) node.disabled = busy; });
  }

  // ── Popups ────────────────────────────────────────────────────────────────
  function openDialog(dialog) {
    if (!dialog) return;
    var error = dialog.querySelector("[data-cpr-form-error]");
    if (error) { error.hidden = true; error.textContent = ""; }
    if (typeof dialog.showModal === "function") { if (!dialog.open) dialog.showModal(); } else dialog.setAttribute("open", "");
    // Sin temporizador: un foco tardío movería lo que ya se está escribiendo a otro campo.
    var first = dialog.querySelector("input:not([type=hidden]):not([disabled]), textarea:not([disabled]), select:not([disabled])");
    if (first && first.offsetParent !== null) first.focus();
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === "function") { if (dialog.open) dialog.close(); } else dialog.removeAttribute("open");
  }

  function formError(form, message) {
    var node = form.querySelector("[data-cpr-form-error]");
    if (node) { node.textContent = message; node.hidden = false; }
  }

  var pendingConfirm = null;

  /** Confirmación interna. Con reason pide un motivo obligatorio. Devuelve el motivo (o true) o null si se cancela. */
  function confirmAction(root, text, label, options) {
    options = options || {};
    var dialog = root.querySelector('[data-cpr-dialog="confirm"]');
    if (!dialog) return Promise.resolve(window.confirm(text) ? true : null);
    var form = dialog.querySelector("form");
    var reasonBox = dialog.querySelector("[data-cpr-confirm-reason]");
    var accept = dialog.querySelector("[data-cpr-confirm-accept]");
    dialog.querySelector("[data-cpr-confirm-text]").textContent = text;
    accept.textContent = label || "Confirmar";
    accept.classList.toggle("is-danger", !!options.danger);
    reasonBox.hidden = !options.reason;
    var reason = reasonBox.querySelector("textarea");
    reason.value = options.reasonValue || "";
    reason.required = !!options.reason;
    if (pendingConfirm) pendingConfirm(null);
    return new Promise(function (resolve) {
      pendingConfirm = function (value) { pendingConfirm = null; resolve(value); };
      form.__cprAccept = function () {
        var value = options.reason ? reason.value.trim() : true;
        if (options.reason && !value) { formError(form, "Explica por qué la acción no se realizó."); reason.focus(); return; }
        // Se suelta antes de cerrar: el evento close no debe resolverlo como cancelado.
        var done = pendingConfirm;
        pendingConfirm = null;
        closeDialog(dialog);
        if (done) done(value);
      };
      openDialog(dialog);
      if (!options.reason) accept.focus();
    });
  }

  document.addEventListener("close", function (event) {
    var dialog = event.target;
    if (dialog && dialog.matches && dialog.matches('[data-cpr-dialog="confirm"]') && pendingConfirm) pendingConfirm(null);
  }, true);

  // ── Participantes ─────────────────────────────────────────────────────────
  function normalize(value) {
    return String(value || "").toLocaleLowerCase("es").normalize("NFD").replace(/[̀-ͯ]/g, "").trim();
  }

  function renderChips(picker) {
    var chips = picker.querySelector("[data-cpr-participant-chips]");
    if (!chips) return;
    chips.replaceChildren();
    var checked = Array.prototype.filter.call(picker.querySelectorAll('input[type="checkbox"]'), function (box) { return box.checked; });
    checked.forEach(function (box) {
      var chip = document.createElement("span");
      chip.className = "cpr-chip is-person";
      chip.append(document.createTextNode(box.getAttribute("data-name") || "Funcionario"));
      var remove = document.createElement("button");
      remove.type = "button";
      remove.setAttribute("data-cpr-participant-remove", box.value);
      remove.setAttribute("aria-label", "Quitar a " + (box.getAttribute("data-name") || "funcionario"));
      remove.textContent = "×";
      chip.append(remove);
      chips.append(chip);
    });
    if (!checked.length) {
      var empty = document.createElement("small");
      empty.textContent = "Ningún participante seleccionado";
      chips.append(empty);
    }
  }

  function setParticipants(form, participants) {
    var picker = form.querySelector("[data-cpr-participants]");
    if (!picker) return;
    var options = picker.querySelector("[data-cpr-participant-options]");
    picker.querySelectorAll("[data-cpr-historical]").forEach(function (node) { node.remove(); });
    var ids = {};
    (participants || []).forEach(function (person) { ids[String(person.id)] = person; });
    picker.querySelectorAll('input[type="checkbox"]').forEach(function (box) { box.checked = !!ids[box.value]; delete ids[box.value]; });
    // Participantes que ya no están activos: se conservan con su nombre histórico.
    Object.keys(ids).forEach(function (id) {
      var label = document.createElement("label");
      label.className = "cpr-option";
      label.setAttribute("data-cpr-option", "");
      label.setAttribute("data-cpr-historical", "");
      label.setAttribute("data-search", normalize(ids[id].name));
      var box = document.createElement("input");
      box.type = "checkbox"; box.name = "participant_ids[]"; box.value = id; box.checked = true;
      box.setAttribute("data-name", ids[id].name || "Funcionario");
      var text = document.createElement("span");
      text.textContent = (ids[id].name || "Funcionario") + " · registro histórico";
      label.append(box, text);
      options.insertBefore(label, options.firstChild);
    });
    var search = picker.querySelector("[data-cpr-participant-search]");
    if (search) search.value = "";
    filterParticipants(picker, "");
    renderChips(picker);
  }

  function filterParticipants(picker, query) {
    var visible = 0;
    var needle = normalize(query);
    picker.querySelectorAll("[data-cpr-option]").forEach(function (option) {
      var match = normalize(option.getAttribute("data-search")).indexOf(needle) >= 0;
      option.hidden = !match;
      if (match) visible += 1;
    });
    var empty = picker.querySelector("[data-cpr-participant-empty]");
    if (empty) empty.hidden = visible > 0;
  }

  // ── Formularios de reunión y punto ────────────────────────────────────────
  function openMeetingForm(root, trigger) {
    var dialog = root.querySelector('[data-cpr-dialog="meeting"]');
    if (!dialog) return;
    var form = dialog.querySelector("form");
    var meeting = null;
    try { meeting = trigger.getAttribute("data-meeting") ? JSON.parse(trigger.getAttribute("data-meeting")) : null; } catch (_error) { meeting = null; }
    var today = form.elements.namedItem("meeting_date").defaultValue;
    form.reset();
    form.elements.namedItem("id").value = meeting ? String(meeting.id) : "";
    form.elements.namedItem("title").value = meeting ? meeting.title : "";
    form.elements.namedItem("meeting_date").value = meeting ? meeting.meeting_date : today;
    form.elements.namedItem("objective").value = meeting ? meeting.objective : "";
    form.elements.namedItem("conclusions").value = meeting ? meeting.conclusions : "";
    dialog.querySelector("[data-cpr-meeting-conclusions]").hidden = !meeting;
    dialog.querySelector("[data-cpr-meeting-kicker]").textContent = meeting ? "Editar reunión" : "Nueva reunión";
    dialog.querySelector("[data-cpr-meeting-heading]").textContent = meeting ? meeting.title : "Preparar encuentro";
    dialog.querySelector("[data-cpr-meeting-help]").textContent = meeting ? "Los nombres de los participantes se conservan en el historial de la reunión." : "Al guardar se copian una sola vez las acciones abiertas de la reunión anterior.";
    dialog.querySelector("[data-cpr-meeting-submit]").textContent = meeting ? "Guardar cambios" : "Crear reunión";
    setParticipants(form, meeting ? meeting.participants : []);
    openDialog(dialog);
  }

  function openItemForm(root, trigger) {
    var dialog = root.querySelector('[data-cpr-dialog="item"]');
    if (!dialog) return;
    var form = dialog.querySelector("form");
    var item = {};
    try { item = trigger.getAttribute("data-item") ? JSON.parse(trigger.getAttribute("data-item")) : {}; } catch (_error) { item = {}; }
    var section = trigger.getAttribute("data-section") || item.section || "achievement";
    var config = SECTIONS[section] || SECTIONS.achievement;
    form.reset();
    var set = function (name, value) { var field = form.elements.namedItem(name); if (field) field.value = value == null || value === 0 ? "" : String(value); };
    set("id", item.id); set("section", section); set("title", item.title); set("content", item.content); set("observation", item.observation);
    set("related_improvement_id", item.related_improvement_id); set("due_date", item.due_date);
    var responsible = form.elements.namedItem("responsible_employee_id");
    responsible.querySelectorAll("[data-cpr-historical]").forEach(function (option) { option.remove(); });
    if (item.responsible_employee_id && !Array.prototype.some.call(responsible.options, function (option) { return option.value === String(item.responsible_employee_id); })) {
      var historical = new Option((item.responsible_name || "Funcionario") + " · histórico", String(item.responsible_employee_id));
      historical.setAttribute("data-cpr-historical", "");
      responsible.add(historical);
    }
    set("responsible_employee_id", item.responsible_employee_id);
    dialog.querySelector("[data-cpr-item-kicker]").textContent = config.kicker;
    dialog.querySelector("[data-cpr-item-heading]").textContent = item.id ? config.edit : config.title;
    dialog.querySelector("[data-cpr-item-help]").textContent = config.help;
    dialog.querySelector("[data-cpr-label-title]").textContent = config.titleLabel;
    dialog.querySelector("[data-cpr-label-content]").textContent = config.contentLabel;
    dialog.querySelectorAll("[data-cpr-action-field]").forEach(function (field) {
      field.hidden = section !== "action";
      field.querySelectorAll("input, select").forEach(function (input) {
        input.disabled = section !== "action";
        input.required = section === "action" && (input.name === "responsible_employee_id" || input.name === "due_date");
      });
    });
    var observation = dialog.querySelector("[data-cpr-observation]");
    observation.hidden = section === "achievement";
    observation.querySelector("textarea").disabled = section === "achievement";
    openDialog(dialog);
  }

  async function submitForm(form) {
    var root = rootOf(form);
    var kind = form.getAttribute("data-cpr-form");
    var submit = form.querySelector('[type="submit"]');
    if (kind === "confirm") { if (typeof form.__cprAccept === "function") form.__cprAccept(); return; }
    if (kind === "complete") {
      if (!(await confirmAction(root, submit.getAttribute("data-cpr-confirm"), submit.getAttribute("data-cpr-confirm-label")))) return;
    }
    var data = new FormData(form);
    data.set("meeting_id", meetingId(root));
    var action = { meeting: "commercial_promo_meeting_save", item: "commercial_promo_meeting_item_save", complete: "commercial_promo_meeting_complete" }[kind];
    if (!action) return;
    setBusy([submit], true);
    try {
      var result = await request(action, data);
      closeDialog(form.closest("dialog"));
      announce(root, result.message || "Cambios guardados.");
      navigate(result.url || "");
    } catch (error) {
      if (form.closest("dialog")) formError(form, error.message);
      else announce(root, error.message, true);
    } finally {
      setBusy([submit], false);
    }
  }

  // ── Acciones de la reunión y de los puntos ────────────────────────────────
  var ACTIONS = {
    start: "commercial_promo_meeting_start",
    reopen: "commercial_promo_meeting_reopen",
    archive: "commercial_promo_meeting_archive",
    "delete": "commercial_promo_meeting_delete",
    "item-move": "commercial_promo_meeting_item_move",
    "item-archive": "commercial_promo_meeting_item_archive",
  };

  async function runAction(button) {
    var root = rootOf(button);
    var kind = button.getAttribute("data-cpr-action");
    var text = button.getAttribute("data-cpr-confirm");
    if (text && !(await confirmAction(root, text, button.getAttribute("data-cpr-confirm-label"), { danger: button.hasAttribute("data-cpr-confirm-danger") }))) return;
    var data = new FormData();
    data.set("meeting_id", meetingId(root));
    if (button.getAttribute("data-item-id")) data.set("item_id", button.getAttribute("data-item-id"));
    if (button.getAttribute("data-direction")) data.set("direction", button.getAttribute("data-direction"));
    setBusy([button], true);
    try {
      var result = await request(ACTIONS[kind], data);
      announce(root, result.message || "Listo.");
      navigate(result.url || "");
    } catch (error) {
      announce(root, error.message, true);
      setBusy([button], false);
    }
  }

  function updateProgress(root, progress) {
    if (!progress) return;
    root.querySelectorAll("[data-cpr-progress-reviewed]").forEach(function (node) { node.textContent = progress.reviewed + "/" + progress.total; });
    root.querySelectorAll("[data-cpr-progress-compliance]").forEach(function (node) { node.textContent = progress.compliance_percent + "%"; });
    root.querySelectorAll("[data-cpr-progress-bar]").forEach(function (node) { node.style.width = progress.review_percent + "%"; });
  }

  function setCardStatus(card, item) {
    Object.keys(STATUS_LABELS).forEach(function (status) { card.classList.remove("status-" + status); });
    card.classList.add("status-" + item.status);
    card.querySelectorAll("[data-cpr-action-label]").forEach(function (label) {
      Object.keys(STATUS_LABELS).forEach(function (status) { label.classList.remove("status-" + status); });
      label.classList.add("status-" + item.status);
      label.textContent = STATUS_LABELS[item.status] || item.status;
    });
    card.querySelectorAll("[data-cpr-status]").forEach(function (button) {
      button.setAttribute("aria-pressed", button.getAttribute("data-cpr-status") === item.status ? "true" : "false");
    });
    var failure = card.querySelector("[data-cpr-failure]");
    if (failure) {
      failure.hidden = !item.failure_reason;
      failure.querySelector("span").textContent = item.failure_reason || "";
    }
  }

  async function updateStatus(button) {
    var root = rootOf(button);
    var card = button.closest("[data-cpr-action-card]");
    var status = button.getAttribute("data-cpr-status");
    if (!card || !status) return;
    var reason = "";
    if (status === "not_completed") {
      var current = card.querySelector("[data-cpr-failure] span");
      reason = await confirmAction(root, "¿Por qué no se realizó «" + (card.querySelector("h3") ? card.querySelector("h3").textContent : "esta acción") + "»?", "Guardar como no realizada", { reason: true, reasonValue: current ? current.textContent : "" });
      if (!reason) return;
    }
    var buttons = Array.prototype.slice.call(card.querySelectorAll("[data-cpr-status]"));
    var data = new FormData();
    data.set("meeting_id", meetingId(root));
    data.set("item_id", card.getAttribute("data-cpr-item"));
    data.set("status", status);
    data.set("reason", typeof reason === "string" ? reason : "");
    setBusy(buttons, true);
    try {
      var result = await request("commercial_promo_meeting_action_status", data);
      setCardStatus(card, result.item || { status: status });
      updateProgress(root, result.progress);
      announce(root, result.message || "Acción actualizada.");
    } catch (error) {
      announce(root, error.message, true);
    } finally {
      setBusy(buttons, false);
    }
  }

  // ── Modo guía ─────────────────────────────────────────────────────────────
  function showStep(guide, index, focus) {
    var panels = guide.querySelectorAll("[data-cpr-step-panel]");
    if (!panels.length) return;
    var current = Math.max(0, Math.min(panels.length - 1, index));
    guide.setAttribute("data-current", String(current));
    panels.forEach(function (panel, position) { panel.hidden = position !== current; });
    guide.querySelectorAll("[data-cpr-step-jump]").forEach(function (button, position) {
      button.classList.toggle("is-active", position === current);
      if (position === current) button.setAttribute("aria-current", "step"); else button.removeAttribute("aria-current");
    });
    var prev = guide.querySelector('[data-cpr-step="prev"]');
    var next = guide.querySelector('[data-cpr-step="next"]');
    if (prev) prev.disabled = current === 0;
    if (next) {
      next.disabled = current === panels.length - 1;
      next.textContent = current === panels.length - 2 ? "Ir a conclusiones" : "Siguiente";
    }
    var label = guide.querySelector("[data-cpr-step-current]");
    if (label) label.textContent = String(current + 1);
    if (focus) panels[current].focus({ preventScroll: true });
  }

  function stepOf(guide) { return Number(guide.getAttribute("data-current") || 0); }

  // ── Eventos delegados ─────────────────────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!rootOf(target)) return;
    var root = rootOf(target);
    var dialog = target.closest("dialog.cpr-dialog");
    if (target.matches && target.matches("dialog.cpr-dialog")) { closeDialog(target); return; }
    var close = target.closest("[data-cpr-close]");
    if (close) { event.preventDefault(); closeDialog(dialog); return; }
    var opener = target.closest("[data-cpr-open]");
    if (opener) {
      event.preventDefault();
      if (opener.getAttribute("data-cpr-open") === "meeting") openMeetingForm(root, opener);
      else openItemForm(root, opener);
      return;
    }
    var remove = target.closest("[data-cpr-participant-remove]");
    if (remove) {
      var picker = remove.closest("[data-cpr-participants]");
      picker.querySelectorAll('input[type="checkbox"]').forEach(function (box) { if (box.value === remove.getAttribute("data-cpr-participant-remove")) box.checked = false; });
      renderChips(picker);
      return;
    }
    var action = target.closest("[data-cpr-action]");
    if (action && !action.disabled) { event.preventDefault(); runAction(action); return; }
    var status = target.closest("[data-cpr-status]");
    if (status && !status.disabled) { event.preventDefault(); updateStatus(status); return; }
    var guide = target.closest("[data-cpr-guide]");
    var jump = target.closest("[data-cpr-step-jump]");
    if (guide && jump) { showStep(guide, Number(jump.getAttribute("data-cpr-step-jump") || 0), true); return; }
    var step = target.closest("[data-cpr-step]");
    if (guide && step) showStep(guide, stepOf(guide) + (step.getAttribute("data-cpr-step") === "next" ? 1 : -1), true);
  });

  document.addEventListener("submit", function (event) {
    var form = event.target.closest && event.target.closest("[data-cpr-form]");
    if (!form || !rootOf(form)) return;
    event.preventDefault();
    submitForm(form);
  });

  document.addEventListener("input", function (event) {
    var search = event.target.closest && event.target.closest("[data-cpr-participant-search]");
    if (search) filterParticipants(search.closest("[data-cpr-participants]"), search.value);
  });

  document.addEventListener("change", function (event) {
    var picker = event.target.closest && event.target.closest("[data-cpr-participants]");
    if (picker && event.target.type === "checkbox") renderChips(picker);
  });

  document.addEventListener("keydown", function (event) {
    var guide = document.querySelector("[data-cpr-guide]");
    if (!guide || event.altKey || event.ctrlKey || event.metaKey || document.querySelector("dialog.cpr-dialog[open]")) return;
    if (event.target.closest && event.target.closest("input, textarea, select, button, a, [contenteditable]")) return;
    if (event.key === "ArrowRight") { event.preventDefault(); showStep(guide, stepOf(guide) + 1, true); }
    if (event.key === "ArrowLeft") { event.preventDefault(); showStep(guide, stepOf(guide) - 1, true); }
  });
})();
