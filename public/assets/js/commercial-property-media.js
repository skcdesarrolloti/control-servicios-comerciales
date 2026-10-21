(function () {
  "use strict";
  var app = document.getElementById("scm-app"), runtime = {};
  if (!app) return;
  try { runtime = JSON.parse(app.dataset.scmRuntime || "{}"); } catch (_error) {}
  async function request(root, action, data) {
    data.set("action", action); data.set("nonce", runtime.nonce || "");
    data.set("ticket_pk", root._media.ticket_pk); data.set("id_inmueble", root._media.id_inmueble);
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", credentials: "same-origin", body: data });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("El servidor no pudo recibir la imagen. Intenta de nuevo."); }
    if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || "No se pudo guardar la multimedia.");
    return payload.data;
  }
  function message(root, text, failed) {
    var error = root.querySelector("[data-media-error]"); error.hidden = !failed; error.textContent = failed ? text : "";
    root.querySelector("[data-media-status]").textContent = failed ? "" : text;
    if (failed) error.scrollIntoView({block:"nearest"});
  }
  function busy(root, active) {
    root._busy = active;
    root.querySelectorAll("input,button").forEach(function (node) { node.disabled = active; });
    root.setAttribute("aria-busy", String(active));
  }
  function render(root) {
    var state = root._media;
    [["cover", state.portada ? [state.portada] : []], ["gallery", state.galeria]].forEach(function (group) {
      var container = root.querySelector("[data-media-" + group[0] + "]"); container.replaceChildren();
      group[1].forEach(function (item, index) {
        var card = document.createElement("div"); card.className = "cpm-card";
        var image = document.createElement("img"); image.src = item.url; image.alt = group[0] === "cover" ? "Portada del inmueble" : "Fotografía " + (index + 1); card.append(image);
        var actions = document.createElement("div"); actions.className = "cpm-actions";
        function button(label, action) { var node = document.createElement("button"); node.type = "button"; node.textContent = label; node.className = "csg-secondary"; node.addEventListener("click", action); actions.append(node); return node; }
        button("Editar", async function () {
          try {
            await window.openPropertyImageEditor(item, async function (file) {
              file = await prepare(file);
              if (item.url.indexOf("blob:") === 0) URL.revokeObjectURL(item.url);
              item.file = file; item.id = 0; item.url = URL.createObjectURL(file); render(root);
              message(root, "Edición aplicada. Los cambios se guardarán con el inmueble.", false);
            });
          } catch (error) { message(root, error.message, true); }
        });
        if (group[0] === "gallery") {
          card.draggable = true;
          card.addEventListener("dragstart", function (event) { event.dataTransfer.setData("text/plain", String(index)); root._dragIndex = index; });
          card.addEventListener("dragover", function (event) { if (root._dragIndex !== undefined) event.preventDefault(); });
          card.addEventListener("drop", function (event) { if (root._dragIndex === undefined) return; event.preventDefault(); state.galeria.splice(index, 0, state.galeria.splice(root._dragIndex, 1)[0]); delete root._dragIndex; render(root); });
          card.addEventListener("dragend", function () { delete root._dragIndex; });
          button("←", function () { if (index > 0) { state.galeria.splice(index - 1, 0, state.galeria.splice(index, 1)[0]); render(root); } }).setAttribute("aria-label", "Mover foto " + (index + 1) + " antes");
          button("→", function () { if (index + 1 < state.galeria.length) { state.galeria.splice(index + 1, 0, state.galeria.splice(index, 1)[0]); render(root); } }).setAttribute("aria-label", "Mover foto " + (index + 1) + " después");
        }
        button("Quitar", function () { if (item.url.indexOf("blob:") === 0) URL.revokeObjectURL(item.url); if (group[0] === "cover") state.portada = null; else state.galeria.splice(index, 1); render(root); }).setAttribute("aria-label", group[0] === "cover" ? "Quitar portada" : "Quitar foto " + (index + 1));
        card.append(actions); container.append(card);
      });
    });
    root.querySelector("[data-media-count]").textContent = state.galeria.length + " de " + state.max_gallery + " fotografías · mínimo 1. Usa las flechas para cambiar el orden.";
  }
  async function prepare(file) {
    if (["image/jpeg", "image/png"].indexOf(file.type) < 0) throw new Error("Solo se permiten imágenes JPG y PNG.");
    if (file.size > 25 * 1024 * 1024) throw new Error("La imagen original supera los 25 MB. Reduce su tamaño antes de seleccionarla.");
    var url = URL.createObjectURL(file);
    try {
      var image = new Image(); image.src = url; await image.decode();
      if (image.naturalWidth < 500 || image.naturalHeight < 500) throw new Error("La imagen debe medir al menos 500 × 500 píxeles.");
      if (image.naturalWidth <= 1700 && image.naturalHeight <= 1200 && file.size <= 1048576) return file;
      var scale = Math.min(1, 1700 / image.naturalWidth, 1200 / image.naturalHeight);
      var width = Math.floor(image.naturalWidth * scale), height = Math.floor(image.naturalHeight * scale);
      if (width < 500 || height < 500) throw new Error("La proporción de la imagen no permite ajustarla al tamaño requerido. Recórtala antes de subirla.");
      var canvas = document.createElement("canvas"); canvas.width = width; canvas.height = height;
      var ctx = canvas.getContext("2d"); ctx.fillStyle = "#ffffff"; ctx.fillRect(0, 0, width, height); ctx.drawImage(image, 0, 0, width, height);
      for (var quality = 0.9; quality >= 0.4; quality -= 0.1) {
        var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, "image/jpeg", quality); });
        if (blob && blob.size <= 1048576) return new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", {type:"image/jpeg"});
      }
      throw new Error("No se pudo reducir la imagen a 1 MB. Comprime el archivo e inténtalo de nuevo.");
    } finally { URL.revokeObjectURL(url); }
  }
  window.initCommercialPropertyMedia = function (root) {
    if (root._media) return;
    root._media = JSON.parse(root.dataset.mediaState);
    root.querySelector('[name="video_del_inmueble"]').value = root._media.video_del_inmueble;
    render(root);
    var cleanup = new MutationObserver(function () {
      if (root.isConnected) return;
      cleanup.disconnect();
      [root._media.portada].concat(root._media.galeria).filter(Boolean).forEach(function (item) { if (item.url.indexOf("blob:") === 0) URL.revokeObjectURL(item.url); });
    });
    cleanup.observe(document.body,{childList:true,subtree:true});
    root.querySelectorAll("[data-media-input]").forEach(function (input) {
      input.addEventListener("change", async function () {
        var files = Array.from(input.files), state = root._media, cover = input.dataset.mediaInput === "cover";
        if (!files.length || root._busy) return;
        if (!cover && state.galeria.length + files.length > state.max_gallery) { input.value = ""; message(root, "Puedes guardar hasta " + state.max_gallery + " fotos en la galería.", true); return; }
        busy(root, true);
        try {
          for (var index = 0; index < files.length; index++) {
            message(root, "Preparando imagen " + (index + 1) + " de " + files.length + "…", false);
            var file = await prepare(files[index]);
            var image = {id:0, file:file, url:URL.createObjectURL(file)};
            if (cover && state.portada && state.portada.url.indexOf("blob:") === 0) URL.revokeObjectURL(state.portada.url);
            if (cover) state.portada = image; else state.galeria.push(image);
            render(root); busy(root, true);
          }
          message(root, root.hasAttribute("data-media-embedded") ? "Fotos listas. Se guardarán al pulsar «Guardar inmueble»." : "Fotos listas. Pulsa «Guardar multimedia» para aplicar los cambios.", false);
        } catch (error) { message(root, error.message, true); }
        finally { input.value = ""; busy(root, false); }
      });
    });
    root.querySelectorAll("[data-media-drop]").forEach(function (zone) {
      zone.addEventListener("dragover", function (event) { if (event.dataTransfer.types.includes("Files")) { event.preventDefault(); zone.classList.add("is-dragging"); } });
      zone.addEventListener("dragleave", function () { zone.classList.remove("is-dragging"); });
      zone.addEventListener("drop", function (event) { event.preventDefault(); zone.classList.remove("is-dragging"); if (root._busy) return; var input = zone.querySelector("input"), transfer = new DataTransfer(); Array.from(event.dataTransfer.files).slice(0, input.multiple ? undefined : 1).forEach(function (file) { transfer.items.add(file); }); input.files = transfer.files; input.dispatchEvent(new Event("change", {bubbles:true})); });
    });
    if (root.hasAttribute("data-media-embedded")) return;
    root.querySelector("form").addEventListener("submit", async function (event) {
      event.preventDefault(); if (root._busy) return;
      try {
        var result = await window.commercialPropertyMedia.save(root, root._media.id_inmueble);
        message(root, result.message, false);
        root.dispatchEvent(new CustomEvent("scm:task-updated", {bubbles:true}));
      } catch (error) { message(root, error.message, true); }
      finally { busy(root, false); }
    });
  };
  window.commercialPropertyMedia = {
    setLimit: function (root, limit) { root._media.max_gallery = limit; render(root); },
    loadDraft: async function (root, code) {
      if (root._busy || root._media.galeria.length || root._media.portada) return;
      root._media.id_inmueble = code; var requested = code;
      try {
        var state = await request(root,"commercial_property_media_draft",new FormData());
        if (root._media.id_inmueble !== requested || root._media.galeria.length || root._media.portada) return;
        root._media = state; root.querySelector('[name="video_del_inmueble"]').value = state.video_del_inmueble; render(root);
        message(root,"Multimedia actual del código adelantado cargada. Puedes conservarla o editarla.",false);
      } catch (exception) { message(root,exception.message,true); }
    },
    resetDraft: function (root) { root._media.id_inmueble = 0; root._media.baseline_gallery = 0; root._media.galeria = root._media.galeria.filter(function (item) { return !!item.file; }); if (root._media.portada && !root._media.portada.file) root._media.portada = null; root.querySelector('[name="video_del_inmueble"]').value = ""; render(root); },
    validate: function (root) {
      if (root._busy) throw new Error("Espera a que termine de preparar las imágenes.");
      if (!root._media.portada || !root._media.galeria.length) throw new Error("Selecciona una portada y al menos una imagen de galería.");
      if (root._media.galeria.length > root._media.max_gallery) throw new Error("La galería admite hasta " + root._media.max_gallery + " fotografías para este tipo de inmueble.");
    },
    save: async function (root, code) {
      this.validate(root); root._media.id_inmueble = code;
      busy(root, true);
      try {
        var items = [root._media.portada].concat(root._media.galeria), pending = items.filter(function (item) { return !!item.file; });
        for (var index = 0; index < pending.length; index++) {
          message(root, "Subiendo foto " + (index + 1) + " de " + pending.length + "…", false);
          var data = new FormData(); data.set("file", pending[index].file);
          var saved = await request(root, "commercial_property_media_upload", data);
          URL.revokeObjectURL(pending[index].url); Object.assign(pending[index], saved); delete pending[index].file;
        }
        var body = new FormData(); body.set("portada_id", root._media.portada.id); body.set("video_del_inmueble", root.querySelector('[name="video_del_inmueble"]').value);
        root._media.galeria.forEach(function (image) { body.append("galeria_ids[]", image.id); });
        var result = await request(root, "commercial_property_media_save", body);
        message(root, result.message, false); render(root); return result;
      } finally { busy(root, false); }
    }
  };
})();
