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
    root.querySelector("form").setAttribute("aria-busy", String(active));
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
        if (group[0] === "gallery") {
          button("←", function () { if (index > 0) { state.galeria.splice(index - 1, 0, state.galeria.splice(index, 1)[0]); render(root); } }).setAttribute("aria-label", "Mover foto " + (index + 1) + " antes");
          button("→", function () { if (index + 1 < state.galeria.length) { state.galeria.splice(index + 1, 0, state.galeria.splice(index, 1)[0]); render(root); } }).setAttribute("aria-label", "Mover foto " + (index + 1) + " después");
        }
        button("Quitar", function () { if (group[0] === "cover") state.portada = null; else state.galeria.splice(index, 1); render(root); }).setAttribute("aria-label", group[0] === "cover" ? "Quitar portada" : "Quitar foto " + (index + 1));
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
    root._media = JSON.parse(root.dataset.mediaState);
    root.querySelector('[name="video_del_inmueble"]').value = root._media.video_del_inmueble;
    render(root);
    root.querySelectorAll("[data-media-input]").forEach(function (input) {
      input.addEventListener("change", async function () {
        var files = Array.from(input.files), state = root._media, cover = input.dataset.mediaInput === "cover";
        if (!files.length || root._busy) return;
        if (!cover && state.galeria.length + files.length > state.max_gallery) { input.value = ""; message(root, "Puedes guardar hasta " + state.max_gallery + " fotos en la galería.", true); return; }
        busy(root, true);
        try {
          for (var index = 0; index < files.length; index++) {
            message(root, "Subiendo imagen " + (index + 1) + " de " + files.length + "…", false);
            var data = new FormData(); data.set("file", await prepare(files[index]));
            var image = await request(root, "commercial_property_media_upload", data);
            if (cover) state.portada = image; else state.galeria.push(image);
            render(root); busy(root, true);
          }
          message(root, "Imágenes cargadas. Pulsa «Guardar multimedia» para aplicar los cambios.", false);
        } catch (error) { message(root, error.message, true); }
        finally { input.value = ""; busy(root, false); }
      });
    });
    root.querySelector("form").addEventListener("submit", async function (event) {
      event.preventDefault(); if (root._busy) return;
      var state = root._media;
      if (!state.portada || !state.galeria.length) { message(root, "Selecciona una portada y al menos una imagen de galería.", true); return; }
      var data = new FormData(event.target); data.set("portada_id", state.portada.id);
      state.galeria.forEach(function (image) { data.append("galeria_ids[]", image.id); });
      busy(root, true); message(root, "Guardando multimedia…", false);
      try {
        var result = await request(root, "commercial_property_media_save", data);
        message(root, result.message, false);
        root.dispatchEvent(new CustomEvent("scm:task-updated", {bubbles:true}));
      } catch (error) { message(root, error.message, true); }
      finally { busy(root, false); }
    });
  };
})();
