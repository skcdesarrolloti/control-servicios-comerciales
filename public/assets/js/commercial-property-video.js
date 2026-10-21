(function () {
  "use strict";
  var app = document.getElementById("scm-app"), active = null, runtime = {};
  if (!app) return;
  try { runtime = JSON.parse(app.dataset.scmRuntime || "{}"); } catch (_error) {}

  window.openCommercialPropertyVideo = function (root) {
    if (active) { active.focus(); return; }
    var opener = document.activeElement, media = root._media;
    var candidates = (media.portada ? [media.portada] : []).concat(media.galeria).filter(function (item, index, list) { return list.indexOf(item) === index; });
    var selected = candidates.slice(0,12).map(function (item) { return {item:item,caption:""}; });
    var draft = root._videoDraft;
    if (draft) selected = draft.selected.filter(function (entry) { return candidates.includes(entry.item); });
    var dialog = document.createElement("dialog"); dialog.className = "cpv-dialog";
    dialog.setAttribute("aria-labelledby","cpv-heading");
    dialog.innerHTML = '<header><div><span class="cpv-eyebrow">SUCASA · VIDEO STUDIO</span><h2 id="cpv-heading">Una historia para cada inmueble</h2><p>De tu galería a un video listo para compartir.</p></div><button type="button" class="csg-secondary" data-close aria-label="Cerrar creador de videos"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>' +
      '<div class="cpv-layout"><section class="cpv-settings" aria-label="Configurar video"><div class="cpv-step"><span>1</span><div><h3>Elige las fotos y su orden</h3><p>De 2 a 12 imágenes. Conservaremos la foto completa sobre un fondo suave.</p></div></div><div class="cpv-picker" data-picker></div><ol class="cpv-timeline" data-timeline></ol>' +
      '<div class="cpv-step"><span>2</span><div><h3>Dale tu toque</h3><p>Estos textos aparecerán en la portada y el cierre.</p></div></div>' +
      '<div class="cpv-fields"><label>Título del inmueble<input data-option="title" maxlength="70" value="Tu próximo inmueble"></label><label>Tipo de negocio<select data-option="operation"><option>En venta</option><option>En arriendo</option><option>En arriendo o venta</option></select></label><label>Barrio y ciudad<input data-option="location" maxlength="80" placeholder="Ej. El Poblado, Medellín"></label><label>Precio o canon <span>(opcional)</span><input data-option="price" maxlength="50" placeholder="Ej. $2.500.000 / mes"></label><label>Contacto <span>(opcional)</span><input data-option="contact" maxlength="60" placeholder="Nombre o teléfono del asesor"></label><label>Tiempo por foto<select data-option="seconds"><option value="2">2 segundos · ágil</option><option value="3">3 segundos</option><option value="4" selected>4 segundos · recomendado</option><option value="5">5 segundos</option><option value="6">6 segundos · pausado</option></select></label><label>Calidad<select data-option="quality"><option value="720">720p · más rápido</option><option value="1080">1080p · más detalle</option></select></label><label>Música <span>(opcional)</span><input type="file" data-audio accept="audio/mpeg,audio/mp4,audio/wav,audio/x-m4a,.mp3,.m4a,.wav"><small>MP3, M4A o WAV · hasta 12 MB. Usa una pista que puedas publicar.</small></label></div>' +
      '</section><aside class="cpv-preview-panel"><div class="cpv-preview-heading"><strong>Vista previa del diseño</strong><span>9:16 · vertical</span></div><div class="cpv-preview" data-preview><img data-preview-image alt="Vista previa de la foto seleccionada"><div class="cpv-brand"><strong>SuCasa</strong><span>INMOBILIARIA</span></div><div class="cpv-preview-copy" data-preview-copy><strong data-preview-title></strong><span data-preview-operation></span><span data-preview-location></span><strong data-preview-price></strong></div><div class="cpv-preview-contact" data-preview-contact hidden></div></div><button type="button" class="csg-secondary" data-play><i class="fa-solid fa-play" aria-hidden="true"></i> Reproducir vista previa</button><p class="cpv-note">Portada + fotos con acercamientos y fundidos + tarjeta de contacto. La vista previa muestra el diseño; el MP4 final se procesa al generar.</p><div class="cpv-output" data-output hidden><video controls playsinline preload="metadata" aria-label="Video generado del inmueble"></video><a class="csg-primary" data-download download="inmueble.mp4">Descargar MP4</a><p>El video queda disponible durante 24 horas. Descárgalo para conservarlo. Puedes publicarlo en redes o subirlo a YouTube.</p></div></aside></div>' +
      '<footer><div><p data-status role="status" aria-live="polite">Comprobando el generador…</p><progress data-progress max="100" value="0" hidden aria-label="Progreso de generación"></progress><p class="cpv-error" data-error role="alert" hidden></p><small data-summary></small></div><button type="button" class="csg-secondary" data-cancel hidden>Cancelar generación</button><button type="button" class="csg-primary" data-generate disabled><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generar video MP4</button></footer>';
    document.body.append(dialog); active = dialog; dialog.showModal();
    var q = function (selector) { return dialog.querySelector(selector); }, available = false, working = false, job = null, pollTimer = null, playTimer = null, frame = 0, blobUrl = null, polling = false;
    var storageKey = "scm-property-video:" + media.ticket_pk;
    try { job = JSON.parse(sessionStorage.getItem(storageKey) || "null"); } catch (_error) {}
    function storeJob() { try { if (job) sessionStorage.setItem(storageKey,JSON.stringify(job)); else sessionStorage.removeItem(storageKey); } catch (_error) {} }
    function error(text) { q("[data-error]").hidden = !text; q("[data-error]").textContent = text || ""; }
    function status(text) { q("[data-status]").textContent = text; }
    function options() {
      var value = {}; dialog.querySelectorAll("[data-option]").forEach(function (node) { value[node.dataset.option] = node.value.trim(); });
      value.seconds = Number(value.seconds); value.captions = selected.map(function (entry) { return entry.caption; }); return value;
    }
    function updateControls() {
      q("[data-generate]").disabled = !available || working || selected.length < 2;
      q("[data-cancel]").hidden = !working || !job;
      dialog.querySelectorAll(".cpv-settings input,.cpv-settings select,.cpv-settings button").forEach(function (node) { node.disabled = working; });
      q("[data-summary]").textContent = selected.length + " fotos · " + (7 + selected.length * Number(q('[data-option="seconds"]').value)) + " segundos · MP4 vertical";
    }
    function preview() {
      var config = options(), item = selected.length ? selected[Math.max(0,Math.min(selected.length - 1,frame - 1))] : null;
      var closing = frame === selected.length + 1, intro = frame === 0;
      var image = q("[data-preview-image]"); image.hidden = !item; if (item && image.getAttribute("src") !== item.item.url) image.src = item.item.url;
      q("[data-preview]").classList.toggle("is-photo",!intro && !closing);
      q("[data-preview]").classList.toggle("is-closing",closing);
      q("[data-preview-title]").textContent = intro ? config.title : item && (item.caption || config.title);
      q("[data-preview-operation]").textContent = intro ? config.operation : "";
      q("[data-preview-location]").textContent = config.location;
      q("[data-preview-price]").textContent = intro ? config.price : "";
      q("[data-preview-copy]").hidden = closing;
      var contact = q("[data-preview-contact]"); contact.hidden = !closing;
      contact.replaceChildren(); ["¿TE INTERESA ESTE INMUEBLE?","Agenda tu visita",config.contact,"sucasainmobiliaria.com.co"].forEach(function (text) { var line = document.createElement("span"); line.textContent = text; contact.append(line); });
      updateControls();
    }
    function renderSelection() {
      var picker = q("[data-picker]"); picker.replaceChildren();
      if (!candidates.length) { var empty = document.createElement("p"); empty.className = "cpv-empty"; empty.textContent = "Añade primero las fotos a la galería del inmueble. Después podrás elegirlas aquí."; picker.append(empty); }
      candidates.forEach(function (item,index) {
        var label = document.createElement("label"), checkbox = document.createElement("input"), image = document.createElement("img"), text = document.createElement("span");
        checkbox.type = "checkbox"; checkbox.checked = selected.some(function (entry) { return entry.item === item; }); checkbox.setAttribute("aria-label","Incluir foto " + (index + 1));
        image.src = item.url; image.alt = ""; text.textContent = index === 0 && media.portada === item ? "Portada" : "Foto " + (index + 1);
        checkbox.addEventListener("change",function () {
          if (checkbox.checked && selected.length >= 12) { checkbox.checked = false; error("Puedes elegir hasta 12 fotos."); return; }
          error(""); if (checkbox.checked) selected.push({item:item,caption:""}); else selected = selected.filter(function (entry) { return entry.item !== item; });
          frame = 0; renderSelection();
        }); label.append(checkbox,image,text); picker.append(label);
      });
      var timeline = q("[data-timeline]"); timeline.replaceChildren();
      selected.forEach(function (entry,index) {
        var row = document.createElement("li"), number = document.createElement("span"), input = document.createElement("input"); number.textContent = index + 1;
        input.value = entry.caption; input.placeholder = "Ej. Sala, cocina, habitación…"; input.maxLength = 80; input.setAttribute("aria-label","Título de escena " + (index + 1));
        input.addEventListener("input",function () { entry.caption = input.value; preview(); }); row.append(number,input);
        [["↑",-1,"antes"],["↓",1,"después"]].forEach(function (action) {
          var button = document.createElement("button"); button.type = "button"; button.textContent = action[0]; button.className = "csg-secondary"; button.setAttribute("aria-label","Mover escena " + (index + 1) + " " + action[2]);
          button.addEventListener("click",function () { var next = index + action[1]; if (next >= 0 && next < selected.length) { selected.splice(next,0,selected.splice(index,1)[0]); renderSelection(); } }); row.append(button);
        }); timeline.append(row);
      }); preview();
    }
    async function request(action, form, binary) {
      form = form || new FormData(); form.set("action",action); form.set("nonce",runtime.nonce || ""); form.set("ticket_pk",media.ticket_pk); form.set("id_inmueble",media.id_inmueble);
      if (job) ["job_id","expires","receipt"].forEach(function (key) { form.set(key,job[key]); });
      var response = await fetch(runtime.ajaxUrl || "api.php",{method:"POST",credentials:"same-origin",body:form});
      if (binary && response.ok && (response.headers.get("Content-Type") || "").includes("video/mp4")) return response.blob();
      var payload; try { payload = await response.json(); } catch (_error) { throw new Error("El servidor no devolvió una respuesta válida. Revisa el tamaño de los archivos."); }
      if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || "No se pudo contactar con el generador de videos.");
      return payload.data;
    }
    async function poll() {
      if (!job || !dialog.open || polling) return;
      polling = true;
      try {
        var state = await request("commercial_property_video_status");
        if (!dialog.open) return;
        var ongoing = ["queued","rendering"].includes(state.status); working = ongoing;
        q("[data-progress]").hidden = !ongoing; q("[data-progress]").value = state.progress;
        status(state.status === "queued" ? "Tu video está en la cola de procesamiento." : state.status === "rendering" ? "Creando tu video… " + state.progress + "%" : state.status === "ready" ? "Video listo. Preparando la vista previa…" : state.message);
        updateControls();
        if (ongoing) { pollTimer = setTimeout(poll,3000); return; }
        if (state.status === "ready") {
          var blob = await request("commercial_property_video_download",null,true);
          if (!dialog.open) return;
          if (blobUrl) URL.revokeObjectURL(blobUrl); blobUrl = URL.createObjectURL(blob);
          q("[data-output]").hidden = false; q("video").src = blobUrl; q("[data-download]").href = blobUrl;
          q("[data-download]").download = "inmueble-" + (media.id_inmueble || media.ticket_pk) + ".mp4";
          status("Tu video está listo. Reprodúcelo o descarga el MP4.");
        }
      } catch (exception) { if (dialog.open) { error(exception.message + " Puedes cerrar y volver a abrir el creador para consultar tu video."); working = false; updateControls(); } }
      finally { polling = false; }
    }
    q("[data-generate]").addEventListener("click",async function () {
      if (working) return;
      error("");
      var config = options();
      if (!config.title) { error("Escribe el título del inmueble."); q('[data-option="title"]').focus(); return; }
      var audio = q("[data-audio]").files[0];
      if (audio && audio.size > 12 * 1048576) { error("El archivo de música supera los 12 MB."); return; }
      working = true; updateControls(); status("Enviando fotos al generador…");
      try {
        var form = new FormData(), uploadIndex = 0;
        config.sources = selected.map(function (entry) { if (entry.item.file) { form.append("photos[]",entry.item.file); return {upload:uploadIndex++}; } return {attachment:Number(entry.item.id)}; });
        form.set("manifest",JSON.stringify(config)); if (audio) form.set("audio",audio);
        var previous = job; job = null;
        try { job = await request("commercial_property_video_create",form); } catch (exception) { job = previous; throw exception; }
        storeJob(); q("[data-output]").hidden = true; updateControls(); await poll();
      } catch (exception) { working = false; error(exception.message); status("No se pudo iniciar la generación."); updateControls(); }
    });
    q("[data-cancel]").addEventListener("click",async function () { try { await request("commercial_property_video_cancel"); clearTimeout(pollTimer); working = false; status("Generación cancelada."); job = null; storeJob(); updateControls(); } catch (exception) { error(exception.message); } });
    q("[data-play]").addEventListener("click",function () {
      if (playTimer) { clearTimeout(playTimer); playTimer = null; q("[data-play]").textContent = "Reproducir vista previa"; return; }
      if (!selected.length) return;
      q("[data-play]").textContent = "Pausar vista previa";
      function next() { frame = (frame + 1) % (selected.length + 2); preview(); playTimer = setTimeout(next,(frame === 0 ? 3 : frame === selected.length + 1 ? 4 : options().seconds) * 1000); }
      next();
    });
    dialog.querySelectorAll("[data-option]").forEach(function (node) { node.addEventListener("input",preview); });
    var entry = root.closest("[data-commercial-property-entry]");
    if (entry) {
      var get = function (name) { var node = entry.querySelector('[name="' + name + '"]'); if (!node) return ""; return node.tagName === "SELECT" ? node.selectedOptions[0] && node.selectedOptions[0].textContent.trim() || "" : node.value; };
      var location = [get("barrio"),get("ciudad")].filter(Boolean).join(", ");
      q('[data-option="location"]').value = location.slice(0,80);
    }
    function close() { if (dialog.open) dialog.close(); }
    q("[data-close]").addEventListener("click",close);
    dialog.addEventListener("close",function () { root._videoDraft = {selected:selected,options:options()}; clearTimeout(pollTimer); clearTimeout(playTimer); if (blobUrl) URL.revokeObjectURL(blobUrl); observer.disconnect(); dialog.remove(); active = null; if (opener && opener.isConnected) opener.focus(); });
    var observer = new MutationObserver(function () { if (!root.isConnected) close(); }); observer.observe(document.body,{childList:true,subtree:true});
    if (draft) dialog.querySelectorAll('[data-option]').forEach(function (node) { if (draft.options[node.dataset.option] !== undefined) node.value = draft.options[node.dataset.option]; });
    renderSelection();
    request("commercial_property_video_config").then(function (config) { available = config.available; status(available ? "Elige tus fotos y genera el video cuando estés listo." : "La selección y vista previa están listas. Para exportar MP4 falta conectar el generador Python en el VPS."); updateControls(); if (job && available) { working = true; updateControls(); poll(); } }).catch(function (exception) { error(exception.message); status("No se pudo comprobar el generador."); });
  };
})();
