(function () {
  "use strict";
  // Notificaciones › Campañas y Plantillas de correo (adaptación de dashboard-marketing views/campaigns.php y
  // template-editor.php). Todo pasa en ventanas internas (<dialog>) y por AJAX (commercial_campaign_*,
  // commercial_template_*); al guardar se vuelve a cargar la sección sin salir del panel. Los eventos se delegan en
  // document porque la pestaña llega por AJAX.
  if (window.__commercialCampaignsLoaded) return;
  window.__commercialCampaignsLoaded = true;

  var app = document.getElementById("scm-app");
  var runtime = {};
  try { runtime = JSON.parse((app && app.getAttribute("data-scm-runtime")) || "{}") || {}; } catch (_error) { runtime = {}; }
  var CHANNELS = { email: "Correo", sms: "SMS", whatsapp: "WhatsApp" };
  var STATUS = { sent: "Enviados", failed: "Fallidos", pending: "Pendientes", processing: "Enviando", paused: "En pausa", cancelled: "Cancelados" };
  var SKIPPED = { already: "Ya la recibieron", blocked: "No aceptan este tipo de mensaje", no_destination: "Sin correo o celular válido", excluded: "Excluidos", invalid: "Mensaje vacío o SMS muy largo", repeated: "Repetidos en otra lista" };

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }
  function number(value) { return Number(value || 0).toLocaleString("es-CO"); }
  function stamp(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ""));
    return match ? match[3] + "/" + match[2] + "/" + match[1] + " " + match[4] + ":" + match[5] : String(value || "");
  }
  // La cola guarda las horas en UTC; se muestran en la hora local del navegador.
  function utcStamp(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ""));
    if (!match) return String(value || "");
    var date = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5]));
    return date.toLocaleString("es-CO", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
  }

  function notify(type, message) {
    var core = window.SCMAdminCore || {};
    if (typeof core.scmNotify === "function") core.scmNotify(type, message);
    else {
      var live = document.querySelector("[data-commercial-campaigns] [data-cpr-live]");
      if (live) live.textContent = message;
    }
  }

  function navigate(url) {
    var host = document.getElementById("scm-app");
    if (host) host.dispatchEvent(new CustomEvent("scm:load-tab", { detail: { url: url || "" } }));
    else window.location.href = url || window.location.href;
  }

  async function post(action, data) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var json = null;
    try { json = await response.json(); } catch (_error) { json = null; }
    if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
    return json.data || {};
  }

  function fields(object) {
    var data = new FormData();
    Object.keys(object || {}).forEach(function (key) { data.set(key, object[key]); });
    return data;
  }

  function open(dialog) {
    dialog.querySelectorAll("[data-camp-error], [data-tpl-error]").forEach(function (node) { node.hidden = true; node.textContent = ""; });
    if (typeof dialog.showModal === "function") { if (!dialog.open) dialog.showModal(); } else dialog.setAttribute("open", "");
  }
  function close(dialog) {
    if (dialog && typeof dialog.close === "function") dialog.close();
    else if (dialog) dialog.removeAttribute("open");
  }
  function fail(dialog, message) {
    var box = dialog.querySelector("[data-camp-error], [data-tpl-error]");
    if (box) { box.textContent = message; box.hidden = false; box.scrollIntoView({ block: "nearest" }); } else notify("error", message);
  }

  // ── Nueva campaña ─────────────────────────────────────────────────────────
  function campaignForm() { return document.querySelector("[data-camp-form]"); }

  function syncChannel(form) {
    var channel = form.querySelector("[data-camp-channel]").value;
    form.querySelectorAll("[data-camp-only]").forEach(function (node) { node.hidden = node.getAttribute("data-camp-only") !== channel; });
    form.querySelector("[data-camp-category]").hidden = channel === "whatsapp";
    var message = form.querySelector("[data-camp-message]");
    var template = form.querySelector("[data-camp-template]");
    var designed = channel === "email" && template && template.value !== "0";
    form.querySelector("[data-camp-message-label]").textContent = channel === "email"
      ? (designed ? "Mensaje (solo si la plantilla tiene {{mensaje}})" : "Mensaje *")
      : "Mensaje *";
    message.maxLength = channel === "sms" ? 136 : (channel === "whatsapp" ? 700 : 5000);
    counter(form);
  }

  function counter(form) {
    var channel = form.querySelector("[data-camp-channel]").value;
    var text = form.querySelector("[data-camp-message]").value;
    var node = form.querySelector("[data-camp-counter]");
    node.textContent = channel === "sms" ? (text.length + 24) + " de 160 caracteres con el prefijo (sin reemplazar variables)." : (channel === "whatsapp" ? text.length + " de 700 caracteres." : "");
  }

  function invalidate(form) {
    form.querySelector('[data-camp-action="send"]').disabled = true;
    form.querySelector("[data-camp-confirm]").hidden = true;
    form.querySelector('[name="confirm"]').checked = false;
  }

  function payload(form) {
    var segments = [];
    form.querySelectorAll("[data-camp-segment]").forEach(function (row) {
      var on = row.querySelector("[data-camp-segment-on]");
      if (on.checked) segments.push({ type: on.value, q: row.querySelector("[data-camp-segment-q]").value.trim() });
    });
    var data = new FormData();
    ["name", "channel", "category", "template_id", "subject", "message", "batch_size", "batch_interval_minutes", "max_per_day", "utm_campaign"].forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      if (field && !field.closest("[hidden]")) data.set(name, field.value.trim());
    });
    data.set("segments", JSON.stringify(segments));
    return data;
  }

  function renderPreview(form, result) {
    var box = form.querySelector("[data-camp-preview]");
    var totals = result.totals || {};
    var rows = (result.segments || []).map(function (segment) {
      var c = segment.counts || {};
      var skipped = Object.keys(SKIPPED).filter(function (key) { return c[key] > 0; }).map(function (key) { return SKIPPED[key] + ": " + number(c[key]); }).join(" · ");
      return "<tr><td>" + esc(segment.label) + '</td><td class="is-num">' + number(c.total) + '</td><td class="is-num"><strong>' + number(c.send) + "</strong></td><td>" + esc(skipped || "—") + "</td></tr>";
    }).join("");
    var sample = result.sample;
    var channel = form.querySelector("[data-camp-channel]").value;
    var sampleHtml = "";
    if (sample) {
      sampleHtml = '<div class="cpr-camp-sample"><p class="cpr-label">Así lo recibe ' + esc(sample.nombre || "la primera persona") + " (" + esc(sample.tipo) + ", " + esc(sample.destino) + ")</p>" +
        (channel === "email"
          ? '<p class="cpr-help">Asunto: <strong>' + esc(sample.subject) + '</strong></p><iframe class="cpr-camp-frame" sandbox="" title="Vista previa del correo" data-camp-sample-frame></iframe>'
          : '<pre class="cpr-camp-text">' + esc(sample.text) + "</pre>") + "</div>";
    }
    var unknown = (result.unknown_variables || []).length
      ? '<p class="cpr-alert">Estas variables no existen en la lista y quedarán tal cual: ' + esc(result.unknown_variables.map(function (name) { return "{{" + name + "}}"; }).join(", ")) + ".</p>" : "";
    box.innerHTML = '<h3>Destinatarios</h3><div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Lista</th><th class="is-num">En la lista</th><th class="is-num">Reciben</th><th>Se saltan</th></tr></thead><tbody>' + rows +
      '</tbody><tfoot><tr><td><strong>Total</strong></td><td class="is-num">' + number(totals.total) + '</td><td class="is-num"><strong>' + number(totals.send) + "</strong></td><td></td></tr></tfoot></table></div>" + unknown + sampleHtml;
    box.hidden = false;
    var frame = box.querySelector("[data-camp-sample-frame]");
    if (frame && sample) frame.srcdoc = sample.html;
    var send = form.querySelector('[data-camp-action="send"]');
    var confirm = form.querySelector("[data-camp-confirm]");
    if (totals.send > 0) {
      var pace = (form.querySelector('[name="batch_size"]').value || "200") + " cada " + (form.querySelector('[name="batch_interval_minutes"]').value || "15") + " minutos";
      form.querySelector("[data-camp-confirm-label]").textContent = "Entiendo que se programarán " + number(totals.send) + " mensajes por " + (CHANNELS[channel] || channel).toLowerCase() + ", en lotes de " + pace + ", y que se pueden pausar o cancelar desde el informe.";
      confirm.hidden = false;
      send.disabled = false;
    } else {
      confirm.hidden = true;
      send.disabled = true;
    }
  }

  async function campaignAction(form, action, button) {
    var dialog = form.closest("dialog");
    if (action === "send" && !form.querySelector('[name="confirm"]').checked) { fail(dialog, "Marca la casilla para confirmar el envío."); return; }
    var data = payload(form);
    if (action === "send") data.set("confirm", "1");
    button.disabled = true;
    dialog.setAttribute("aria-busy", "true");
    try {
      var result = await post({ preview: "commercial_campaign_preview", test: "commercial_campaign_test", send: "commercial_campaign_send" }[action], data);
      dialog.querySelector("[data-camp-error]").hidden = true;
      if (action === "preview") renderPreview(form, result);
      else if (action === "test") notify("success", result.message);
      else {
        close(dialog);
        notify("success", result.message + (result.first_at ? " Primer lote: " + utcStamp(result.first_at) + "; último: " + utcStamp(result.last_at) + "." : ""));
        navigate("");
      }
    } catch (error) {
      fail(dialog, error.message);
    } finally {
      dialog.removeAttribute("aria-busy");
      if (action !== "send") button.disabled = false;
      else button.disabled = form.querySelector("[data-camp-confirm]").hidden;
    }
  }

  // ── Informe ───────────────────────────────────────────────────────────────
  function renderDetail(dialog, campaign) {
    var canSend = dialog.getAttribute("data-can-send") === "1";
    var byType = {};
    (campaign.by_type || []).forEach(function (row) {
      var key = (row.type || "sin tipo") + "|" + row.channel;
      byType[key] = byType[key] || { type: row.label || row.type || "Sin lista", channel: row.channel, counts: {} };
      byType[key].counts[row.status] = (byType[key].counts[row.status] || 0) + Number(row.total || 0);
    });
    var typeRows = Object.keys(byType).map(function (key) {
      var item = byType[key];
      var parts = Object.keys(item.counts).map(function (status) { return (STATUS[status] || status) + ": " + number(item.counts[status]); }).join(" · ");
      return "<tr><td>" + esc(item.type) + "</td><td>" + esc(CHANNELS[item.channel] || item.channel) + "</td><td>" + esc(parts) + "</td></tr>";
    }).join("");
    var failures = (campaign.failures || []).map(function (row) {
      return "<tr><td>" + esc(row.destination_name || "—") + '<span class="cpr-cell-sub">' + esc(row.destination) + "</span></td><td>" + esc(row.last_error || "Sin detalle") + "</td>" +
        (canSend && row.type ? '<td><button type="button" class="cpr-ghost is-small" data-camp-op="exclude" data-type="' + esc(row.type) + '" data-actor="' + esc(row.actor) + '" data-name="' + esc(row.destination_name) + '">Excluir</button></td>' : "<td></td>") + "</tr>";
    }).join("");
    var meta = campaign.meta || {};
    var stats = [
      ["Enviados", number(campaign.sent) + " de " + number(campaign.total)], ["Fallidos", number(campaign.failed)], ["En cola", number(campaign.pending)],
      ["Correos abiertos", campaign.tracked > 0 ? number(campaign.opened) + " de " + number(campaign.tracked) + " (" + Math.round(campaign.opened * 100 / campaign.tracked) + " %)" : "—"],
    ];
    if (campaign.visits) stats.push(["Visitas al sitio", number(campaign.visits.hits) + " (" + number(campaign.visits.visitors) + " personas) con la campaña UTM " + campaign.visits.campaign]);
    if (campaign.next_at) stats.push(["Próximo lote", utcStamp(campaign.next_at)]);
    if (campaign.exclusions) stats.push(["Excluidos", number(campaign.exclusions)]);
    var actions = "";
    if (canSend) {
      if (campaign.status === "paused") actions += '<button type="button" class="cpr-primary is-small" data-camp-op="resume">Reanudar</button>';
      else if (campaign.status !== "cancelled" && campaign.pending > 0) actions += '<button type="button" class="cpr-ghost is-small" data-camp-op="pause">Pausar</button>';
      if (campaign.failed > 0 && campaign.status !== "cancelled") actions += '<button type="button" class="cpr-ghost is-small" data-camp-op="retry">Reintentar fallidos</button>';
      if (campaign.pending > 0) actions += '<button type="button" class="cpr-ghost is-small is-danger" data-camp-op="cancel">Cancelar lo pendiente</button>';
    }
    dialog.querySelector("[data-camp-detail-title]").textContent = campaign.tag;
    dialog.querySelector("[data-camp-detail-body]").innerHTML =
      '<dl class="cpr-camp-stats">' + stats.map(function (item) { return "<div><dt>" + esc(item[0]) + "</dt><dd>" + esc(item[1]) + "</dd></div>"; }).join("") + "</dl>" +
      (meta.channel ? '<p class="cpr-help">' + esc((CHANNELS[meta.channel] || meta.channel) + (meta.subject ? " · Asunto: " + meta.subject : "") + (meta.segments ? " · Listas: " + meta.segments.map(function (s) { return s.label; }).join(", ") : "")) + "</p>" : '<p class="cpr-help">Campaña enviada desde el panel de marketing anterior.</p>') +
      (actions ? '<div class="cpr-pop-card__actions">' + actions + "</div>" : "") +
      "<h3>Por lista y canal</h3>" + (typeRows ? '<div class="cpr-table-wrap"><table class="cpr-table"><tbody>' + typeRows + "</tbody></table></div>" : '<p class="cpr-empty">Sin mensajes.</p>') +
      (failures ? '<h3>Últimos fallidos</h3><div class="cpr-table-wrap"><table class="cpr-table"><tbody>' + failures + "</tbody></table></div>" : "");
    dialog.setAttribute("data-tag", campaign.tag);
  }

  async function loadDetail(tag) {
    var dialog = document.querySelector('[data-camp-dialog="detail"]');
    dialog.querySelector("[data-camp-detail-title]").textContent = tag;
    dialog.querySelector("[data-camp-detail-body]").innerHTML = '<p class="cpr-empty">Cargando informe…</p>';
    open(dialog);
    try {
      renderDetail(dialog, (await post("commercial_campaign_detail", fields({ tag: tag }))).campaign);
    } catch (error) {
      dialog.querySelector("[data-camp-detail-body]").innerHTML = '<p class="cpr-alert is-error">' + esc(error.message) + "</p>";
    }
  }

  var pendingControl = null;
  async function control(operation, extra) {
    var detail = document.querySelector('[data-camp-dialog="detail"]');
    var tag = detail.getAttribute("data-tag") || "";
    try {
      var result = await post("commercial_campaign_control", fields(Object.assign({ tag: tag, operation: operation }, extra || {})));
      notify("success", result.message);
      close(document.querySelector('[data-camp-dialog="confirm"]'));
      close(detail);
      navigate("");
    } catch (error) {
      var confirm = document.querySelector('[data-camp-dialog="confirm"]');
      if (confirm && confirm.open) fail(confirm, error.message);
      else notify("error", error.message);
    }
  }

  // ── Plantillas de correo ──────────────────────────────────────────────────
  var liveTimer = 0;
  async function templateDialog(kind, id, name) {
    if (kind === "archive") {
      var archive = document.querySelector('[data-tpl-dialog="archive"]');
      archive.querySelector("form").reset();
      archive.querySelector('[name="id"]').value = id;
      archive.querySelector("[data-tpl-archive-name]").textContent = name || "";
      open(archive);
      return;
    }
    var template = { id: 0, nombre: "", asunto: "", contenido: "" };
    if (id) {
      try { template = (await post("commercial_template_get", fields({ id: id }))).template; } catch (error) { notify("error", error.message); return; }
    }
    if (kind === "view") {
      var view = document.querySelector('[data-tpl-dialog="view"]');
      view.querySelector("[data-tpl-view-title]").textContent = template.nombre;
      view.querySelector("[data-tpl-view-subject]").textContent = "Asunto: " + (template.asunto || "Sin asunto");
      view.querySelector("[data-tpl-view-frame]").srcdoc = template.contenido;
      open(view);
      return;
    }
    var edit = document.querySelector('[data-tpl-dialog="edit"]');
    var form = edit.querySelector("form");
    form.reset();
    form.querySelector('[name="id"]').value = template.id || 0;
    form.querySelector('[name="nombre"]').value = template.nombre || "";
    form.querySelector('[name="asunto"]').value = template.asunto || "";
    form.querySelector('[name="contenido"]').value = template.contenido || "";
    edit.querySelector("[data-tpl-edit-title]").textContent = template.id ? "Editar plantilla" : "Nueva plantilla";
    edit.querySelector("[data-tpl-live]").srcdoc = template.contenido || "";
    open(edit);
  }

  async function templateSave(data, dialog) {
    try {
      var result = await post("commercial_template_save", data);
      close(dialog);
      notify("success", result.message);
      navigate("");
    } catch (error) {
      if (dialog) fail(dialog, error.message); else notify("error", error.message);
    }
  }

  // ── Eventos ───────────────────────────────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest || !target.closest("[data-commercial-campaigns], .cpr-dialog")) return;
    var nav = target.closest("a[data-camp-nav]");
    if (nav) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
      event.preventDefault();
      navigate(nav.getAttribute("href"));
      return;
    }
    if (target.closest("[data-camp-close], [data-tpl-close]")) { close(target.closest("dialog")); return; }
    if (target.closest('[data-camp-open="new"]')) {
      var form = campaignForm();
      form.reset();
      form.querySelectorAll("[data-camp-segment-q]").forEach(function (input) { input.disabled = true; });
      form.querySelector("[data-camp-preview]").hidden = true;
      invalidate(form);
      syncChannel(form);
      open(form.closest("dialog"));
      return;
    }
    var action = target.closest("[data-camp-action]");
    if (action) { campaignAction(action.closest("form"), action.getAttribute("data-camp-action"), action); return; }
    var detail = target.closest("[data-camp-detail]");
    if (detail) { loadDetail(detail.getAttribute("data-camp-detail")); return; }
    var op = target.closest("[data-camp-op]");
    if (op) {
      var operation = op.getAttribute("data-camp-op");
      if (operation === "cancel" || operation === "exclude") {
        var confirm = document.querySelector('[data-camp-dialog="confirm"]');
        confirm.querySelector("form").reset();
        var exclude = operation === "exclude";
        confirm.querySelector("[data-camp-confirm-title]").textContent = exclude ? "Excluir de la campaña" : "Cancelar lo pendiente";
        confirm.querySelector("[data-camp-confirm-lead]").textContent = exclude ? (op.getAttribute("data-name") || "") : document.querySelector('[data-camp-dialog="detail"]').getAttribute("data-tag");
        confirm.querySelector("[data-camp-confirm-reason]").hidden = !exclude;
        confirm.querySelector("[data-camp-confirm-text]").textContent = exclude
          ? "Entiendo que esta persona no recibirá esta campaña, tampoco si se vuelve a enviar."
          : "Entiendo que los mensajes que faltan no se enviarán. Los enviados no cambian y quedan en el historial.";
        pendingControl = exclude ? { operation: "exclude", extra: { type: op.getAttribute("data-type"), actor: op.getAttribute("data-actor") } } : { operation: "cancel", extra: {} };
        open(confirm);
        return;
      }
      op.disabled = true;
      control(operation);
      return;
    }
    var tpl = target.closest("[data-tpl-open]");
    if (tpl) { templateDialog(tpl.getAttribute("data-tpl-open"), Number(tpl.getAttribute("data-tpl-id") || 0), tpl.getAttribute("data-tpl-name")); return; }
    var tplOp = target.closest("[data-tpl-op]");
    if (tplOp) { tplOp.disabled = true; templateSave(fields({ operation: tplOp.getAttribute("data-tpl-op"), id: tplOp.getAttribute("data-tpl-id") }), null); }
  });

  document.addEventListener("change", function (event) {
    var form = event.target.closest && event.target.closest("[data-camp-form]");
    if (!form) return;
    if (event.target.matches("[data-camp-segment-on]")) {
      var search = event.target.closest("[data-camp-segment]").querySelector("[data-camp-segment-q]");
      search.disabled = !event.target.checked;
    }
    if (event.target.matches("[data-camp-channel], [data-camp-template]")) syncChannel(form);
    if (event.target.name !== "confirm") invalidate(form);
  });

  document.addEventListener("input", function (event) {
    var form = event.target.closest && event.target.closest("[data-camp-form]");
    if (form) {
      if (event.target.matches("[data-camp-message]")) counter(form);
      // Marcar la confirmación no es un cambio de la campaña.
      if (event.target.name !== "confirm") invalidate(form);
      return;
    }
    if (event.target.matches && event.target.matches("[data-tpl-html]")) {
      var frame = event.target.closest("dialog").querySelector("[data-tpl-live]");
      window.clearTimeout(liveTimer);
      liveTimer = window.setTimeout(function () { frame.srcdoc = event.target.value; }, 300);
    }
  });

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (form.matches && form.matches("[data-camp-form]")) { event.preventDefault(); return; }
    if (form.matches && form.matches("[data-camp-confirm-form]")) {
      event.preventDefault();
      if (!form.reportValidity() || !pendingControl) return;
      control(pendingControl.operation, Object.assign({ confirm: "1", reason: form.querySelector('[name="reason"]').value }, pendingControl.extra));
      return;
    }
    if (form.matches && form.matches("[data-tpl-form]")) {
      event.preventDefault();
      if (!form.reportValidity()) return;
      var data = new FormData(form);
      data.set("operation", "save");
      templateSave(data, form.closest("dialog"));
      return;
    }
    if (form.matches && form.matches("[data-tpl-archive-form]")) {
      event.preventDefault();
      if (!form.reportValidity()) return;
      templateSave(fields({ operation: "archive", id: form.querySelector('[name="id"]').value, confirm: "1" }), form.closest("dialog"));
    }
  });
})();
