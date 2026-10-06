(function () {
  "use strict";

  function init(container) {
    var panel = (container || document).querySelector("[data-commercial-notifications]");
    if (!panel || panel.dataset.initialized) return;
    panel.dataset.initialized = "1";
    var runtime = JSON.parse(document.getElementById("scm-app").dataset.scmRuntime || "{}");
    var config = JSON.parse(panel.dataset.notificationConfig);
    var search = panel.querySelector("[data-notif-search]");
    var compose = panel.querySelector("[data-notif-compose]");
    var selected = new Map();
    var excluded = new Set();
    var type = "propietarios", page = 1, pages = 1, total = 0, allFiltered = false;
    var queuePage = 1, queuePages = 1, sequence = 0, busy = false, lastFingerprint = "";
    var appliedFilters = {q: "", contract_status: "", inmueble_simi: "", contract_number: ""};
    var mediaPreviewUrl = "";
    var originalSendDisabled = panel.querySelector("[data-notif-send]").disabled;
    var statusLabels = {pending: "Pendiente", processing: "Procesando", sent: "Enviado", failed: "Fallido", cancelled: "Cancelado"};
    function newRequestId() { return Array.from(crypto.getRandomValues(new Uint8Array(16)), function (byte) { return byte.toString(16).padStart(2, "0"); }).join(""); }

    function el(selector) { return panel.querySelector(selector); }
    function esc(value) { return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) { return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[char]; }); }
    function feedback(message, error) {
      el("[data-notif-feedback]").hidden = !message;
      el("[data-notif-feedback]").textContent = message;
      el("[data-notif-feedback]").classList.toggle("text-error", !!error);
    }
    async function api(action, data) {
      var body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) Object.entries(data || {}).forEach(function (entry) { body.append(entry[0], entry[1]); });
      body.set("action", action);
      body.set("nonce", runtime.nonce);
      var response = await fetch(runtime.ajaxUrl, {method: "POST", credentials: "same-origin", body: body});
      var json;
      try { json = await response.json(); } catch (_error) { throw new Error("No se pudo leer la respuesta del servidor. Intenta nuevamente."); }
      if (!response.ok || !json.success) throw new Error(json.data && json.data.message || "La operación no pudo completarse.");
      return json.data;
    }
    function updateSelection() {
      var selectedCount = allFiltered ? total - excluded.size : selected.size;
      el("[data-notif-selected]").textContent = selectedCount + " seleccionados";
      var checks = panel.querySelectorAll("[data-notif-recipient]");
      checks.forEach(function (check) { check.checked = allFiltered ? !excluded.has(check.value) : selected.has(check.value); });
      var count = Array.from(checks).filter(function (check) { return check.checked; }).length;
      el("[data-notif-select-page]").checked = checks.length > 0 && count === checks.length;
      el("[data-notif-select-page]").indeterminate = count > 0 && count < checks.length;
      el("[data-notif-send]").disabled = originalSendDisabled || busy || !selectedCount;
      preview();
    }
    function renderRows(rows) {
      el("[data-notif-recipients]").innerHTML = rows.length ? rows.map(function (row) {
        return '<label class="flex items-start gap-3 p-4 hover:bg-surface-container-low cursor-pointer"><input type="checkbox" class="mt-1 w-4 h-4 accent-[#735c00]" data-notif-recipient value="' + esc(row._ID) + '" data-name="' + esc(row.nombre) + '"><span class="flex items-center justify-center w-9 h-9 shrink-0 rounded-full bg-surface-container-low text-secondary font-semibold" aria-hidden="true">' + esc(String(row.nombre || "?").slice(0, 1).toUpperCase()) + '</span><span class="min-w-0 flex-1"><strong class="block text-sm break-words">' + esc(row.nombre) + '</strong><span class="block text-xs text-secondary mt-1 break-all">' + esc(row.correo || "Sin correo") + ' · ' + esc(row.celular_normalizado || row.celular || "Sin celular") + '</span>' + (row.contrato_arrendamiento_estado ? '<span class="block text-xs text-secondary mt-1">' + esc(row.contrato_arrendamiento_estado) + '</span>' : '') + '</span></label>';
      }).join("") : '<div class="p-10 text-center"><span class="material-symbols-outlined text-secondary text-[32px]" aria-hidden="true">person_search</span><p class="text-sm font-medium mt-2">No hay destinatarios con estos filtros.</p><p class="text-xs text-secondary mt-1">Prueba otra búsqueda o cambia de categoría.</p></div>';
      updateSelection();
    }
    async function loadRecipients() {
      var ticket = ++sequence;
      el("[data-notif-recipients]").setAttribute("aria-busy", "true");
      panel.querySelectorAll("[data-notif-prev], [data-notif-next], [data-notif-select-all], [data-notif-select-page]").forEach(function (button) { button.disabled = true; });
      try {
        var result = await api("commercial_notifications_recipients", Object.assign({type: type, page: page}, appliedFilters));
        if (ticket !== sequence || !panel.isConnected) return;
        total = result.total; page = result.page; pages = result.pages;
        renderRows(result.rows);
        el("[data-notif-pagination]").textContent = "Página " + page + " de " + pages + " · " + total + " contactos";
        el("[data-notif-prev]").disabled = page <= 1;
        el("[data-notif-next]").disabled = page >= pages;
        el("[data-notif-select-all]").disabled = !total || total > 500;
        el("[data-notif-select-all]").title = total > 500 ? "Ajusta los filtros para seleccionar hasta 500 contactos" : "";
        el("[data-notif-select-page]").disabled = !result.rows.length;
        Object.entries(result.stats || {}).forEach(function (entry) {
          var key = entry[0], stats = entry[1];
          el('[data-notif-total="' + key + '"]').textContent = stats.total.toLocaleString("es-CO");
          el('[data-notif-contact="' + key + '"]').textContent = stats.email + " con correo · " + stats.phone + " con celular";
        });
      } catch (error) {
        if (ticket === sequence && panel.isConnected) {
          feedback(error.message, true);
          el("[data-notif-recipients]").innerHTML = '<p class="p-8 text-center text-sm text-error">No se pudo cargar. Usa Buscar para volver a intentar.</p>';
          selected.clear(); excluded.clear(); allFiltered = false; total = 0; updateSelection();
        }
      } finally {
        if (ticket === sequence) el("[data-notif-recipients]").setAttribute("aria-busy", "false");
      }
    }
    function channels() { return Array.from(compose.querySelectorAll('[name="channels[]"]:checked')).map(function (node) { return node.value; }); }
    function template() { return config.templates[compose.elements.whatsapp_template.value]; }
    function mediaType() { return template().header_type || ""; }
    function preview() {
      var name = selected.size ? selected.values().next().value : "María";
      var message = compose.elements.message.value.trim();
      var text = template().body.replace("{{1}}", name).replace("{{2}}", message || "[Tu mensaje aparecerá aquí]").replace("{{3}}", config.sender.signature_line);
      el("[data-notif-preview]").textContent = text;
      el("[data-notif-length]").textContent = compose.elements.message.value.length + "/700";
      var chosen = channels();
      el("[data-notif-email-fields]").hidden = !chosen.includes("email");
      el("[data-notif-whatsapp-fields]").hidden = !chosen.includes("whatsapp");
      el("[data-notif-media-fields]").hidden = !mediaType();
      var limits = {image: 5 * 1024 * 1024, document: 100 * 1024 * 1024, video: 16 * 1024 * 1024};
      var formats = {image: "JPG o PNG", document: "PDF", video: "MP4 (H.264 con audio AAC)"};
      var limit = Math.min(limits[mediaType()] || 0, config.max_bytes);
      el("[data-notif-media-help]").textContent = formats[mediaType()] + " · máximo " + Math.floor(limit / 1024 / 1024) + " MB. El correo incluirá un enlace al archivo.";
      compose.elements.media.accept = {image: ".jpg,.jpeg,.png", document: ".pdf", video: ".mp4"}[mediaType()] || "";
      compose.elements.media.required = chosen.includes("whatsapp") && !!mediaType();
      el("[data-notif-sms-length]").hidden = !chosen.includes("sms");
      el("[data-notif-sms-length]").textContent = "SMS: " + (Array.from(message).length + Array.from("SKC SuCasa Inmobiliaria ").length) + "/160 caracteres, incluido el nombre de SKC SuCasa Inmobiliaria.";
    }
    function showMedia() {
      if (mediaPreviewUrl) URL.revokeObjectURL(mediaPreviewUrl);
      var file = compose.elements.media.files[0];
      var target = el("[data-notif-media-preview]");
      target.replaceChildren(); target.hidden = !file;
      if (!file) return;
      mediaPreviewUrl = URL.createObjectURL(file);
      var node;
      if (mediaType() === "image") { node = document.createElement("img"); node.alt = "Vista previa del encabezado"; node.src = mediaPreviewUrl; node.className = "w-full max-h-48 object-contain rounded-lg"; }
      else if (mediaType() === "video") { node = document.createElement("video"); node.controls = true; node.src = mediaPreviewUrl; node.className = "w-full max-h-48 rounded-lg"; }
      else { node = document.createElement("p"); node.textContent = "PDF · " + file.name; node.className = "rounded-lg bg-surface-container-low p-3 text-xs break-all"; }
      target.append(node);
    }
    async function loadQueue() {
      el("[data-notif-queue-rows]").innerHTML = '<tr><td colspan="5" class="p-8 text-center text-secondary">Cargando envíos…</td></tr>';
      try {
        var result = await api("commercial_notifications_queue", {page: queuePage, status: el("[data-notif-queue-status]").value});
        if (!panel.isConnected) return;
        queuePage = result.page; queuePages = result.pages;
        el("[data-notif-queue-stats]").innerHTML = Object.entries(result.counts).map(function (entry) { return '<div class="rounded-2xl bg-white border border-slate-200 p-4"><span class="block text-xs text-secondary">' + esc(statusLabels[entry[0]]) + '</span><strong class="block text-2xl mt-1">' + entry[1] + '</strong></div>'; }).join("");
        el("[data-notif-queue-rows]").innerHTML = result.rows.length ? result.rows.map(function (row) { return '<tr><td class="px-5 py-4"><strong class="block text-sm">' + esc(row.destination_name) + '</strong><span class="text-xs text-secondary break-all">' + esc(row.destination) + '</span></td><td class="px-5 py-4 text-xs">' + esc(row.channel) + '</td><td class="px-5 py-4 text-xs whitespace-nowrap">' + esc(statusLabels[row.status] || row.status) + '</td><td class="px-5 py-4 text-xs whitespace-nowrap">' + esc(new Date(row.created_at.replace(" ", "T") + "Z").toLocaleString("es-CO", {timeZone: "America/Bogota"})) + '</td><td class="px-5 py-4 text-xs max-w-xs break-words">' + esc(row.last_error || (row.attempts + " intentos")) + '</td></tr>'; }).join("") : '<tr><td colspan="5" class="p-8 text-center text-secondary">Todavía no hay notificaciones en este estado.</td></tr>';
        el("[data-notif-queue-page]").textContent = "Página " + queuePage + " de " + queuePages + " · " + result.total + " envíos";
        el("[data-notif-queue-prev]").disabled = queuePage <= 1;
        el("[data-notif-queue-next]").disabled = queuePage >= queuePages;
      } catch (error) { feedback(error.message, true); el("[data-notif-queue-rows]").innerHTML = '<tr><td colspan="5" class="p-8 text-center text-error">No se pudo consultar la cola. Usa Actualizar para reintentar.</td></tr>'; }
    }
    function resetSelection() { selected.clear(); excluded.clear(); allFiltered = false; updateSelection(); }
    panel.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button || button.disabled || busy) return;
      if (button.dataset.notifType) {
        type = button.dataset.notifType; page = 1; search.reset(); appliedFilters = {q: "", contract_status: "", inmueble_simi: "", contract_number: ""}; resetSelection();
        panel.querySelectorAll("[data-notif-type]").forEach(function (node) { var active = node === button; node.setAttribute("aria-pressed", String(active)); node.classList.toggle("ring-2", active); node.classList.toggle("ring-primary-container", active); node.classList.toggle("border-primary-container", active); });
        el("[data-notif-contract-filters]").hidden = type === "club_pph"; el("[data-notif-import-wrap]").hidden = type === "club_pph";
        feedback(""); loadRecipients();
      }
      if (button.dataset.notifView) {
        var view = button.dataset.notifView;
        panel.querySelectorAll("[data-notif-panel]").forEach(function (node) { node.hidden = node.dataset.notifPanel !== view; });
        panel.querySelectorAll("[data-notif-view]").forEach(function (node) { var active = node === button; node.setAttribute("aria-selected", String(active)); node.classList.toggle("bg-white", active); });
        if (view === "queue") loadQueue();
      }
      if (button.hasAttribute("data-notif-prev")) { page--; loadRecipients(); }
      if (button.hasAttribute("data-notif-next")) { page++; loadRecipients(); }
      if (button.hasAttribute("data-notif-select-all")) { allFiltered = true; selected.clear(); excluded.clear(); updateSelection(); }
      if (button.hasAttribute("data-notif-clear")) resetSelection();
      if (button.hasAttribute("data-notif-queue-prev")) { queuePage--; loadQueue(); }
      if (button.hasAttribute("data-notif-queue-next")) { queuePage++; loadQueue(); }
      if (button.hasAttribute("data-notif-queue-refresh")) loadQueue();
      if (button.hasAttribute("data-notif-copy-template")) navigator.clipboard.writeText(el("[data-notif-template-body]").textContent).then(function () { feedback("Cuerpo de plantilla copiado."); }).catch(function () { feedback("No se pudo copiar automáticamente. Selecciona el texto de la plantilla y cópialo.", true); });
    });
    panel.addEventListener("change", function (event) {
      var target = event.target;
      if (target.hasAttribute("data-notif-recipient")) {
        if (allFiltered) { if (target.checked) excluded.delete(target.value); else excluded.add(target.value); }
        else if (target.checked) selected.set(target.value, target.dataset.name); else selected.delete(target.value);
        updateSelection();
      }
      if (target.hasAttribute("data-notif-select-page")) {
        panel.querySelectorAll("[data-notif-recipient]").forEach(function (node) {
          if (allFiltered) { if (target.checked) excluded.delete(node.value); else excluded.add(node.value); }
          else if (target.checked) selected.set(node.value, node.dataset.name); else selected.delete(node.value);
        }); updateSelection();
      }
      if (target === compose.elements.whatsapp_template) { compose.elements.media.value = ""; showMedia(); }
      if (target === compose.elements.media) showMedia();
      if (compose.contains(target)) preview();
      if (target.hasAttribute("data-notif-queue-status")) { queuePage = 1; loadQueue(); }
    });
    compose.addEventListener("input", preview);
    search.addEventListener("submit", function (event) {
      event.preventDefault(); if (busy) return;
      var data = new FormData(search); appliedFilters = Object.fromEntries(data.entries());
      if (type === "club_pph") { appliedFilters.contract_status = ""; appliedFilters.inmueble_simi = ""; appliedFilters.contract_number = ""; }
      page = 1; resetSelection(); feedback(""); loadRecipients();
    });
    search.addEventListener("reset", function () { if (busy) return; setTimeout(function () { appliedFilters = Object.fromEntries(new FormData(search).entries()); page = 1; resetSelection(); loadRecipients(); }, 0); });
    el("[data-notif-import]").addEventListener("submit", async function (event) {
      event.preventDefault(); var form = event.target, button = form.querySelector("button"), importType = type, importSequence = sequence; button.disabled = true;
      try { var data = new FormData(form); data.set("type", importType); var result = await api("commercial_notifications_import", data); if (!panel.isConnected || type !== importType || sequence !== importSequence) return; selected.clear(); allFiltered = false; result.rows.forEach(function (row) { selected.set(String(row._ID), row.nombre); }); updateSelection(); feedback(result.matched + " contactos seleccionados; " + result.unmatched + " filas sin coincidencia y " + result.duplicates + " duplicadas."); }
      catch (error) { feedback(error.message, true); } finally { button.disabled = false; }
    });
    compose.addEventListener("submit", async function (event) {
      event.preventDefault(); if (busy) return;
      var chosen = channels(), count = allFiltered ? total - excluded.size : selected.size;
      if (!chosen.length || !count) { feedback("Selecciona destinatarios y al menos un canal.", true); return; }
      if (count > 500) { feedback("Ajusta tu selección a un máximo de 500 destinatarios.", true); return; }
      var file = compose.elements.media.files[0];
      var max = Math.min({image: 5242880, document: 104857600, video: 16777216}[mediaType()] || config.max_bytes, config.max_bytes);
      if (file && file.size > max) { feedback("El archivo excede el tamaño permitido.", true); return; }
      if (!window.confirm("Se encolarán mensajes para " + count + " destinatarios por " + chosen.join(", ") + ".\n\nEl saludo y la firma se personalizarán. ¿Confirmas el envío?")) return;
      var data = new FormData(compose); data.set("type", type); data.set("all_filtered", allFiltered ? "1" : "0"); data.set("media_type", mediaType());
      Object.entries(appliedFilters).forEach(function (entry) { data.set(entry[0], entry[1]); });
      selected.forEach(function (_name, id) { data.append("ids[]", id); });
      excluded.forEach(function (id) { data.append("exclude_ids[]", id); });
      if (!mediaType()) data.delete("media");
      var fingerprint = JSON.stringify([type, appliedFilters, Array.from(selected.keys()).sort(), Array.from(excluded).sort(), allFiltered, chosen, compose.elements.whatsapp_template.value, compose.elements.subject.value, compose.elements.message.value, file && [file.name, file.size, file.lastModified]]);
      if (lastFingerprint && fingerprint !== lastFingerprint) config.request_id = newRequestId();
      lastFingerprint = fingerprint; data.set("request_id", config.request_id);
      busy = true; updateSelection(); el("[data-notif-send]").textContent = "Encolando mensajes…";
      // Evitar cambiar la selección durante el envío.
      panel.querySelectorAll("input, select, textarea").forEach(function (node) { node.disabled = true; });
      try {
        var result = await api("commercial_notifications_send", data); feedback(result.message, result.failed > 0 || !result.queued);
        if (result.failed === 0 && result.queued > 0) { config.request_id = newRequestId(); lastFingerprint = ""; resetSelection(); }
      } catch (error) { feedback(error.message, true); }
      finally { busy = false; panel.querySelectorAll("input, select, textarea").forEach(function (node) { node.disabled = false; }); el("[data-notif-send]").textContent = "Revisar y enviar"; updateSelection(); }
    });
    panel.querySelectorAll('[role="tab"]').forEach(function (tab) { tab.addEventListener("keydown", function (event) { if (event.key === "ArrowLeft" || event.key === "ArrowRight") { event.preventDefault(); var next = panel.querySelector('[role="tab"]:not(#' + tab.id + ')'); next.focus(); next.click(); } }); });
    preview(); updateSelection(); loadRecipients();
  }
  window.initCommercialNotifications = init;
  init(document);
})();
