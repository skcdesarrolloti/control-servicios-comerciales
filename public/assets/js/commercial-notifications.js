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
    var recipientDetails = new Map();
    var excluded = new Set();
    var type = "", page = 1, pages = 1, total = 0, allFiltered = false;
    var queuePage = 1, queuePages = 1, sequence = 0, busy = false, lastFingerprint = "";
    var appliedFilters = {q: "", contract_status: "", inmueble_simi: "", contract_number: ""};
    var mediaPreviewUrl = "";
    var originalSendDisabled = panel.querySelector("[data-notif-send]").disabled;
    var modal = panel.querySelector("[data-notif-modal]"), singleTarget = null, previewChannel = "whatsapp";
    var confirmationModal = panel.querySelector("[data-notif-confirm-modal]"), resultModal = panel.querySelector("[data-notif-result-modal]"), confirming = false;
    var loading = false, suppressReset = false, recipientRequest = null, recipientCache = new Map();
    var statusLabels = {pending: "Pendiente", processing: "Procesando", sent: "Enviado", failed: "Fallido", cancelled: "Cancelado"};
    function newRequestId() { return Array.from(crypto.getRandomValues(new Uint8Array(16)), function (byte) { return byte.toString(16).padStart(2, "0"); }).join(""); }

    function el(selector) { return panel.querySelector(selector); }
    function esc(value) { return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) { return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[char]; }); }
    function feedback(message, error) {
      el("[data-notif-feedback]").hidden = !message;
      el("[data-notif-feedback]").textContent = message;
      el("[data-notif-feedback]").classList.toggle("text-error", !!error);
      el("[data-notif-modal-feedback]").hidden = !message || !error;
      el("[data-notif-modal-feedback]").textContent = message;
    }
    async function api(action, data, signal) {
      var body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) Object.entries(data || {}).forEach(function (entry) { body.append(entry[0], entry[1]); });
      body.set("action", action);
      body.set("nonce", runtime.nonce);
      var response = await fetch(runtime.ajaxUrl, {method: "POST", credentials: "same-origin", body: body, signal: signal});
      var json;
      try { json = await response.json(); } catch (_error) { throw new Error("No se pudo leer la respuesta del servidor. Intenta nuevamente."); }
      if (!response.ok || !json.success) throw new Error(json.data && json.data.message || "La operación no pudo completarse.");
      return json.data;
    }
    function targetCount() { return singleTarget ? 1 : (allFiltered ? total - excluded.size : selected.size); }
    function availableChannels() {
      if (singleTarget) return (recipientDetails.get(singleTarget.id) || {}).available_channels || [];
      // La selección total puede abarcar otras páginas. El servidor valida cada destino al enviar.
      if (allFiltered) return ["whatsapp", "email", "sms"];
      var available = new Set();
      selected.forEach(function (_name, id) { ((recipientDetails.get(id) || {}).available_channels || []).forEach(function (channel) { available.add(channel); }); });
      return Array.from(available);
    }
    function smsMetrics(text) {
      var chars = Array.from(text), units = 0, unicode = false;
      chars.forEach(function (char) { if (config.sms.basic.includes(char)) units++; else if (config.sms.extended.includes(char)) units += 2; else unicode = true; });
      if (unicode) units = text.length;
      return {characters: chars.length, encoding: unicode ? "Unicode" : "GSM-7", segments: units <= (unicode ? 70 : 160) ? 1 : Math.ceil(units / (unicode ? 67 : 153))};
    }
    function openComposer(channel, recipient) {
      singleTarget = recipient || null;
      if (!config.can_send || loading || !targetCount()) { feedback("Selecciona destinatarios para preparar el mensaje.", true); return; }
      var available = availableChannels();
      if (!available.length || (channel !== "all" && !available.includes(channel))) { feedback("No hay datos de contacto válidos para el canal elegido.", true); return; }
      compose.querySelectorAll('[name="channels[]"]').forEach(function (node) { node.checked = available.includes(node.value) && (channel === "all" || node.value === channel); });
      previewChannel = channel === "all" ? "whatsapp" : channel;
      feedback(""); modal.showModal();
      // Recrear el iframe evita que conserve un documento sin pintar tras cerrar el dialog.
      var emailPreview = el("[data-notif-email-preview]"), freshEmailPreview = emailPreview.cloneNode(false);
      freshEmailPreview.removeAttribute("srcdoc"); emailPreview.replaceWith(freshEmailPreview);
      preview();
      compose.elements.message.focus();
    }
    modal.addEventListener("cancel", function (event) { if (busy) event.preventDefault(); });
    modal.addEventListener("close", function () { singleTarget = null; updateSelection(); });
    confirmationModal.addEventListener("cancel", function (event) { if (busy) event.preventDefault(); });
    function confirmSend(count, chosen) {
      el("[data-notif-confirm-target]").textContent = singleTarget ? singleTarget.name : count + (count === 1 ? " destinatario seleccionado" : " destinatarios seleccionados");
      el("[data-notif-confirm-channels]").textContent = chosen.map(function (channel) { return {whatsapp: "WhatsApp", email: "Correo", sms: "SMS"}[channel]; }).join(" · ");
      el("[data-notif-confirm-progress]").hidden = true;
      var yes = el("[data-notif-confirm-send]"), cancel = el("[data-notif-confirm-cancel]");
      yes.disabled = false; cancel.disabled = false;
      return new Promise(function (resolve) {
        function finish(confirmed) { yes.removeEventListener("click", accept); cancel.removeEventListener("click", back); confirmationModal.removeEventListener("close", dismissed); resolve(confirmed); }
        function accept() { finish(true); }
        function back() { confirmationModal.close(); finish(false); }
        function dismissed() { finish(false); }
        yes.addEventListener("click", accept); cancel.addEventListener("click", back); confirmationModal.addEventListener("close", dismissed);
        confirmationModal.showModal(); cancel.focus();
      });
    }
    function showSendResult(result, error) {
      var queued = result ? result.queued : 0, failed = result ? result.failed : 0;
      el("[data-notif-result-title]").textContent = error ? "No se pudo confirmar el encolado" : (queued ? (failed ? "Encolado parcial" : "Mensajes encolados") : "No se encolaron mensajes");
      el("[data-notif-result-description]").textContent = error ? error + " · Consulta la cola antes de volver a intentarlo." : (queued ? "Los mensajes quedaron en la cola para su envío. Puedes consultar su estado y los resultados de entrega." : "Revisa los datos de contacto, las preferencias y la cantidad de errores antes de volver a intentarlo.");
      el("[data-notif-result-icon]").textContent = error || !queued || failed ? "info" : "task_alt";
      el("[data-notif-result-counts]").hidden = !!error; el("[data-notif-result-help]").hidden = !!error;
      panel.querySelectorAll("[data-notif-result-count]").forEach(function (node) { node.textContent = result ? result[node.dataset.notifResultCount].toLocaleString("es-CO") : "0"; });
      el("[data-notif-result-queue]").hidden = !error && !queued;
      resultModal.showModal(); el("[data-notif-result-close]").focus();
    }
    function updateSelection() {
      var selectedCount = allFiltered ? total - excluded.size : selected.size;
      el("[data-notif-selected]").textContent = selectedCount + " seleccionados";
      var checks = panel.querySelectorAll("[data-notif-recipient]");
      checks.forEach(function (check) { check.checked = allFiltered ? !excluded.has(check.value) : selected.has(check.value); });
      var count = Array.from(checks).filter(function (check) { return check.checked; }).length;
      el("[data-notif-select-page]").checked = checks.length > 0 && count === checks.length;
      el("[data-notif-select-page]").indeterminate = count > 0 && count < checks.length;
      var available = availableChannels();
      panel.querySelectorAll("[data-notif-open-channel]").forEach(function (button) { var channel = button.dataset.notifOpenChannel; button.disabled = !config.can_send || busy || loading || !selectedCount || (channel === "all" ? !available.length : !available.includes(channel)); });
      preview();
    }
    function renderRows(rows) {
      el("[data-notif-recipients]").innerHTML = rows.length ? rows.map(function (row) {
        recipientDetails.set(String(row._ID), row);
        var available = row.available_channels || [];
        var actions = Object.entries({whatsapp: "WhatsApp", email: "Correo", sms: "SMS", all: "Todos los canales"}).map(function (entry) {
          var unavailable = entry[0] === "all" ? !available.length : !available.includes(entry[0]);
          var reason = !config.can_send ? "No tienes permiso para enviar" : (unavailable ? (entry[0] === "email" ? "Sin correo válido" : (entry[0] === "all" ? "Sin datos de contacto válidos" : "Sin celular válido")) : "Enviar a este contacto");
          return '<button type="button" data-notif-single-channel="' + entry[0] + '" data-id="' + esc(row._ID) + '" data-name="' + esc(row.nombre) + '" title="' + esc(reason) + '" ' + (!config.can_send || unavailable ? 'disabled ' : '') + 'class="min-h-[44px] rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium hover:bg-surface-container-low disabled:opacity-50 disabled:cursor-not-allowed">' + entry[1] + '</button>';
        }).join("");
        return '<div class="p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4 hover:bg-surface-container-low"><label class="flex min-w-0 flex-1 items-start gap-3 cursor-pointer"><input type="checkbox" class="mt-1 w-4 h-4 accent-[#735c00]" data-notif-recipient value="' + esc(row._ID) + '" data-name="' + esc(row.nombre) + '"><span class="flex items-center justify-center w-9 h-9 shrink-0 rounded-full bg-surface-container-low text-secondary font-semibold" aria-hidden="true">' + esc(String(row.nombre || "?").slice(0, 1).toUpperCase()) + '</span><span class="min-w-0 flex-1"><strong class="block text-sm break-words">' + esc(row.nombre) + '</strong><span class="block text-xs text-secondary mt-1 break-all">' + esc(row.correo || "Sin correo") + ' · ' + esc(row.celular_normalizado || row.celular || "Sin celular") + '</span>' + (row.contrato_arrendamiento_estado ? '<span class="block text-xs text-secondary mt-1">' + esc(row.contrato_arrendamiento_estado) + '</span>' : '') + '</span></label><div class="flex shrink-0 flex-wrap gap-2 md:justify-end" aria-label="Enviar a ' + esc(row.nombre) + '">' + actions + '</div></div>';
      }).join("") : '<div class="p-10 text-center"><span class="material-symbols-outlined text-secondary text-[32px]" aria-hidden="true">person_search</span><p class="text-sm font-medium mt-2">No hay destinatarios con estos filtros.</p><p class="text-xs text-secondary mt-1">Prueba otra búsqueda o cambia de categoría.</p></div>';
      updateSelection();
    }
    async function loadRecipients(force) {
      if (!type) return;
      var ticket = ++sequence;
      var requestedType = type, filters = Object.assign({}, appliedFilters), key = JSON.stringify([type, page, filters]);
      if (recipientRequest) recipientRequest.abort();
      recipientRequest = new AbortController();
      loading = true; updateSelection();
      el("[data-notif-recipients]").innerHTML = '<p class="p-8 text-center text-sm text-secondary">Cargando destinatarios…</p>';
      el("[data-notif-recipients]").setAttribute("aria-busy", "true");
      panel.querySelectorAll("[data-notif-prev], [data-notif-next], [data-notif-select-all], [data-notif-select-page]").forEach(function (button) { button.disabled = true; });
      try {
        var cached = recipientCache.get(key);
        var result = !force && cached && Date.now() - cached.time < 60000 ? cached.result : await api("commercial_notifications_recipients", Object.assign({type: requestedType, page: page}, filters), recipientRequest.signal);
        if (ticket !== sequence || !panel.isConnected) return;
        if (force || !cached || Date.now() - cached.time >= 60000) recipientCache.set(key, {result: result, time: Date.now()});
        if (recipientCache.size > 30) recipientCache.delete(recipientCache.keys().next().value);
        total = result.total; page = result.page; pages = result.pages;
        loading = false;
        renderRows(result.rows);
        el("[data-notif-pagination]").textContent = "Página " + page + " de " + pages + " · " + total + " contactos";
        el("[data-notif-prev]").disabled = page <= 1;
        el("[data-notif-next]").disabled = page >= pages;
        el("[data-notif-select-all]").disabled = !total || total > 500;
        el("[data-notif-select-all]").title = total > 500 ? "Ajusta los filtros para seleccionar hasta 500 contactos" : "";
        el("[data-notif-select-page]").disabled = !result.rows.length;
        if (!Object.values(filters).some(Boolean)) {
          el('[data-notif-total="' + requestedType + '"]').textContent = result.total.toLocaleString("es-CO");
          el('[data-notif-contact="' + requestedType + '"]').textContent = "Contactos disponibles";
        }
      } catch (error) {
        if (error.name !== "AbortError" && ticket === sequence && panel.isConnected) {
          feedback(error.message, true);
          el("[data-notif-recipients]").innerHTML = '<p class="p-8 text-center text-sm text-error">No se pudo cargar. Usa Buscar para volver a intentar.</p>';
          selected.clear(); excluded.clear(); allFiltered = false; total = 0; updateSelection();
        }
      } finally {
        if (ticket === sequence) { loading = false; updateSelection(); el("[data-notif-recipients]").setAttribute("aria-busy", "false"); }
      }
    }
    function channels() { return Array.from(compose.querySelectorAll('[name="channels[]"]:checked')).map(function (node) { return node.value; }); }
    function template() { return config.templates[compose.elements.whatsapp_template.value]; }
    function mediaType() { return template().header_type || ""; }
    function preview() {
      var name = singleTarget ? singleTarget.name : (selected.size ? selected.values().next().value : "María");
      var message = compose.elements.message.value.trim();
      var text = template().body.replace("{{1}}", function () { return name; }).replace("{{2}}", function () { return message || "[Tu mensaje aparecerá aquí]"; }).replace("{{3}}", function () { return config.sender.signature_line; });
      el("[data-notif-length]").textContent = Array.from(compose.elements.message.value).length + "/700";
      var available = availableChannels();
      compose.querySelectorAll('[name="channels[]"]').forEach(function (node) { node.disabled = busy || !available.includes(node.value); if (!available.includes(node.value)) node.checked = false; });
      var chosen = channels();
      el("[data-notif-channel-help]").textContent = singleTarget ? "Solo puedes usar los canales que tienen datos de contacto válidos." : "En envíos masivos se omiten los contactos sin datos válidos para cada canal; los demás continúan.";
      el("[data-notif-message-help]").textContent = chosen.length === 1 && chosen[0] === "sms" ? "SMS agrega el prefijo de la empresa al texto escrito." : "WhatsApp y correo agregan el saludo y tu firma automáticamente.";
      if (!chosen.includes(previewChannel)) previewChannel = chosen[0] || "whatsapp";
      panel.querySelectorAll("[data-notif-preview-channel]").forEach(function (button) { button.hidden = !chosen.includes(button.dataset.notifPreviewChannel); button.setAttribute("aria-pressed", String(button.dataset.notifPreviewChannel === previewChannel)); button.classList.toggle("bg-primary-container", button.dataset.notifPreviewChannel === previewChannel); });
      el("[data-notif-text-preview]").hidden = previewChannel === "email";
      el("[data-notif-email-preview]").hidden = previewChannel !== "email";
      el("[data-notif-preview]").textContent = previewChannel === "sms" ? config.sms.prefix + (message || "[Tu mensaje]") : text;
      if (previewChannel === "email") {
        var emailHtml = config.email_document.replaceAll("__SCM_NAME__", function () { return esc(name); }).replaceAll("__SCM_SUBJECT__", function () { return esc(compose.elements.subject.value.trim() || "Información de SKC SuCasa Inmobiliaria"); }).replaceAll("__SCM_MESSAGE__", function () { return esc(message || "[Tu mensaje]").replace(/\n/g, "<br>"); });
        if (el("[data-notif-email-preview]").srcdoc !== emailHtml) el("[data-notif-email-preview]").srcdoc = emailHtml;
      }
      el("[data-notif-modal-target]").textContent = singleTarget ? "Destinatario: " + singleTarget.name : targetCount() + (targetCount() === 1 ? " destinatario seleccionado" : " destinatarios seleccionados");
      el("[data-notif-email-fields]").hidden = !chosen.includes("email");
      el("[data-notif-whatsapp-fields]").hidden = !chosen.includes("whatsapp");
      el("[data-notif-media-fields]").hidden = !mediaType() || (!chosen.includes("whatsapp") && !chosen.includes("email"));
      el("[data-notif-media-preview]").hidden = previewChannel !== "whatsapp" || !compose.elements.media.files.length;
      var limits = {image: 5 * 1024 * 1024, document: 100 * 1024 * 1024, video: 16 * 1024 * 1024};
      var formats = {image: "JPG o PNG", document: "PDF", video: "MP4 (H.264 con audio AAC)"};
      var limit = Math.min(limits[mediaType()] || 0, config.max_bytes);
      el("[data-notif-media-help]").textContent = formats[mediaType()] + " · máximo " + Math.floor(limit / 1024 / 1024) + " MB. El correo incluirá un enlace al archivo.";
      compose.elements.media.accept = {image: ".jpg,.jpeg,.png", document: ".pdf", video: ".mp4"}[mediaType()] || "";
      compose.elements.media.required = chosen.includes("whatsapp") && !!mediaType();
      el("[data-notif-sms-length]").hidden = !chosen.includes("sms");
      var sms = smsMetrics(config.sms.prefix + message), overSms = chosen.includes("sms") && sms.characters > config.sms.max;
      el("[data-notif-sms-length]").textContent = "SMS: " + sms.characters + "/160 caracteres con prefijo · " + sms.encoding + " · " + sms.segments + " segmentos estimados." + (overSms ? " Acorta el mensaje o desmarca SMS." : "");
      el("[data-notif-sms-length]").classList.toggle("text-error", overSms);
      el("[data-notif-send]").disabled = originalSendDisabled || busy || loading || !targetCount() || !chosen.length || overSms;
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
      if (button.hasAttribute("data-notif-open-channel")) openComposer(button.dataset.notifOpenChannel);
      if (button.hasAttribute("data-notif-single-channel")) openComposer(button.dataset.notifSingleChannel, {id: button.dataset.id, name: button.dataset.name});
      if (button.hasAttribute("data-notif-close")) modal.close();
      if (button.hasAttribute("data-notif-result-close")) resultModal.close();
      if (button.hasAttribute("data-notif-result-queue")) { resultModal.close(); if (modal.open) modal.close(); queuePage = 1; el("[data-notif-queue-status]").value = ""; el('[data-notif-view="queue"]').click(); }
      if (button.hasAttribute("data-notif-preview-channel")) { previewChannel = button.dataset.notifPreviewChannel; preview(); }
      if (button.dataset.notifType) {
        recipientDetails.clear();
        type = button.dataset.notifType; page = 1; suppressReset = true; search.reset(); suppressReset = false; appliedFilters = {q: "", contract_status: "", inmueble_simi: "", contract_number: ""}; resetSelection();
        el("[data-notif-search-controls]").disabled = false; el("[data-notif-recipient-refresh]").disabled = false;
        el("[data-notif-actor-title]").textContent = button.querySelector("span span").textContent;
        panel.querySelectorAll("[data-notif-type]").forEach(function (node) { var active = node === button; node.setAttribute("aria-pressed", String(active)); node.classList.toggle("ring-2", active); node.classList.toggle("ring-primary-container", active); node.classList.toggle("border-primary-container", active); });
        el("[data-notif-contract-filters]").hidden = type === "club_pph";
        el("[data-notif-contract-status-wrap]").hidden = type !== "copropiedades";
        el("[data-notif-contract-filters]").classList.toggle("sm:grid-cols-3", type === "copropiedades");
        el("[data-notif-contract-filters]").classList.toggle("sm:grid-cols-2", type !== "copropiedades");
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
      if (button.hasAttribute("data-notif-recipient-refresh")) { recipientCache.clear(); loadRecipients(true); }
      if (button.hasAttribute("data-notif-queue-prev")) { queuePage--; loadQueue(); }
      if (button.hasAttribute("data-notif-queue-next")) { queuePage++; loadQueue(); }
      if (button.hasAttribute("data-notif-queue-refresh")) loadQueue();
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
      event.preventDefault(); if (busy || confirming) return;
      var data = new FormData(search); appliedFilters = Object.fromEntries(data.entries());
      if (type === "club_pph") { appliedFilters.contract_status = ""; appliedFilters.inmueble_simi = ""; appliedFilters.contract_number = ""; }
      page = 1; resetSelection(); feedback(""); loadRecipients();
    });
    search.addEventListener("reset", function () { if (busy || suppressReset || !type) return; setTimeout(function () { appliedFilters = Object.fromEntries(new FormData(search).entries()); page = 1; resetSelection(); loadRecipients(); }, 0); });
    compose.addEventListener("submit", async function (event) {
      event.preventDefault(); if (busy || confirming) return;
      var chosen = channels(), count = targetCount();
      if (!chosen.length || !count) { feedback("Selecciona destinatarios y al menos un canal.", true); return; }
      if (count > 500) { feedback("Ajusta tu selección a un máximo de 500 destinatarios.", true); return; }
      if (chosen.includes("sms") && smsMetrics(config.sms.prefix + compose.elements.message.value.trim()).characters > config.sms.max) { feedback("El SMS supera 160 caracteres con el prefijo. Acorta el mensaje o desmarca SMS.", true); return; }
      var file = compose.elements.media.files[0];
      var max = Math.min({image: 5242880, document: 104857600, video: 16777216}[mediaType()] || config.max_bytes, config.max_bytes);
      if (file && file.size > max) { feedback("El archivo excede el tamaño permitido.", true); return; }
      confirming = true;
      var confirmed = await confirmSend(count, chosen);
      confirming = false;
      if (!confirmed) return;
      var data = new FormData(compose); data.set("type", type); data.set("all_filtered", !singleTarget && allFiltered ? "1" : "0"); data.set("media_type", mediaType());
      Object.entries(appliedFilters).forEach(function (entry) { data.set(entry[0], entry[1]); });
      if (singleTarget) data.append("ids[]", singleTarget.id);
      else { selected.forEach(function (_name, id) { data.append("ids[]", id); }); excluded.forEach(function (id) { data.append("exclude_ids[]", id); }); }
      if (!mediaType() || (!chosen.includes("whatsapp") && !chosen.includes("email"))) data.delete("media");
      var fingerprint = JSON.stringify([type, appliedFilters, singleTarget, Array.from(selected.keys()).sort(), Array.from(excluded).sort(), allFiltered, chosen, compose.elements.whatsapp_template.value, compose.elements.subject.value, compose.elements.message.value, file && [file.name, file.size, file.lastModified]]);
      if (lastFingerprint && fingerprint !== lastFingerprint) config.request_id = newRequestId();
      lastFingerprint = fingerprint; data.set("request_id", config.request_id);
      busy = true; updateSelection(); el("[data-notif-send]").textContent = "Encolando mensajes…";
      el("[data-notif-confirm-progress]").hidden = false;
      el("[data-notif-confirm-send]").disabled = true; el("[data-notif-confirm-cancel]").disabled = true;
      confirmationModal.setAttribute("aria-busy", "true");
      // Evitar cambiar la selección durante el envío.
      panel.querySelectorAll("input, select, textarea").forEach(function (node) { node.disabled = true; });
      var result = null, sendError = "";
      try {
        result = await api("commercial_notifications_send", data); feedback(result.message, result.failed > 0 || !result.queued);
        if (result.failed === 0 && result.queued > 0) { config.request_id = newRequestId(); lastFingerprint = ""; if (!singleTarget) resetSelection(); modal.close(); }
      } catch (error) { sendError = error.message; feedback(sendError, true); }
      finally { busy = false; confirmationModal.setAttribute("aria-busy", "false"); confirmationModal.close(); panel.querySelectorAll("input, select, textarea").forEach(function (node) { node.disabled = false; }); el("[data-notif-send]").textContent = "Revisar y enviar"; updateSelection(); }
      showSendResult(result, sendError);
    });
    panel.querySelectorAll('[role="tab"]').forEach(function (tab) { tab.addEventListener("keydown", function (event) { if (event.key === "ArrowLeft" || event.key === "ArrowRight") { event.preventDefault(); var next = panel.querySelector('[role="tab"]:not(#' + tab.id + ')'); next.focus(); next.click(); } }); });
    panel.querySelectorAll("[data-notif-select-all], [data-notif-select-page]").forEach(function (button) { button.disabled = true; });
    preview(); updateSelection();
  }
  window.initCommercialNotifications = init;
  init(document);
})();
