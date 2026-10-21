(function () {
  "use strict";
  var scriptUrl = document.currentScript.src;
  window.createCommercialEditorTools = function (editor) {
    var dialog = editor.dialog, canvas = editor.canvas, fabric = editor.fabric, body = dialog.querySelector(".cpm-editor-body"), tools = dialog.querySelector(".cpm-editor-tools"), stage = dialog.querySelector(".cpm-editor-stage");
    var runtime = {}; try { runtime = JSON.parse(document.getElementById("scm-app").dataset.scmRuntime || "{}"); } catch (_error) {}
    var mask = document.createElement("canvas"), maskContext = mask.getContext("2d",{willReadFrequently:true}), brush = false, drawing = false, lastPoint, crop, worker, cloudEnabled = false, controller = new AbortController(), compared = false;
    dialog.classList.add("cpm-studio");
    dialog.querySelector("header h2").innerHTML = '<span class="cpm-studio-logo"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span><span>SuCasa Studio<small>Editor de imagen</small></span>';
    var workspace = document.createElement("main"); workspace.className = "cpm-editor-workspace"; stage.replaceWith(workspace); workspace.append(stage);
    var viewport = document.createElement("div"); viewport.className = "cpm-studio-viewport"; stage.replaceWith(viewport); viewport.append(stage);
    var zoom = document.createElement("div"); zoom.className = "cpm-studio-zoom"; zoom.innerHTML = '<button type="button" data-studio="fit" aria-label="Ajustar imagen a la ventana"><i class="fa-solid fa-expand" aria-hidden="true"></i> Ajustar</button><span></span><button type="button" data-studio="zoom-out" aria-label="Alejar imagen">−</button><output data-studio-zoom>100%</output><button type="button" data-studio="zoom-in" aria-label="Acercar imagen">+</button><button type="button" data-studio="compare" aria-pressed="false"><i class="fa-solid fa-code-compare" aria-hidden="true"></i> Ver original</button>'; workspace.append(zoom);
    var rail = document.createElement("nav"); rail.className = "cpm-studio-rail"; rail.setAttribute("aria-label","Herramientas de imagen"); body.prepend(rail);
    var originalSections = Array.from(tools.children), panels = {};
    function tool(id,label,icon,sections) {
      var button = document.createElement("button"); button.type = "button"; button.dataset.studioTab = id; button.setAttribute("aria-pressed","false"); button.innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i><span>' + label + '</span>'; rail.append(button);
      var panel = document.createElement("section"); panel.className = "cpm-studio-panel"; panel.dataset.studioPanel = id; var title = document.createElement("h3"); title.textContent = label; panel.append(title); (sections || []).forEach(function (section) { panel.append(section); }); tools.append(panel); panels[id] = panel; return panel;
    }
    tool("adjust","Ajustes","fa-sliders",[originalSections[0],originalSections[1]]);
    var retouch = tool("retouch","Retocar","fa-eraser"); retouch.innerHTML += '<p>Marca lo que deseas quitar con el pincel. El retoque local funciona mejor en manchas, cables y fondos sencillos.</p><button type="button" data-studio="brush" aria-pressed="false"><i class="fa-solid fa-paintbrush" aria-hidden="true"></i> Pintar zona</button><label>Tamaño del pincel<input type="range" min="8" max="160" value="45" data-studio-brush></label><button type="button" data-studio="clear-mask">Limpiar selección</button><button type="button" class="cpm-studio-action" data-studio="heal">Aplicar retoque local</button><div class="cpm-studio-ai"><strong><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Eliminar con IA</strong><p data-studio-ai-note>Comprobando disponibilidad…</p><button type="button" data-studio="cloud" disabled>Eliminar objeto con IA</button></div>';
    var cropPanel = tool("crop","Recortar","fa-crop-simple"); cropPanel.innerHTML += '<p>Ajusta el rectángulo sobre la fotografía. La imagen final debe conservar al menos 500 × 500 px.</p><label>Proporción<select data-crop-ratio><option value="free">Libre</option><option value="1">Cuadrado · 1:1</option><option value="1.333333">Horizontal · 4:3</option><option value="1.777778">Panorámica · 16:9</option></select></label><button type="button" data-studio="crop-start">Seleccionar recorte</button><button type="button" class="cpm-studio-action" data-studio="crop-apply">Aplicar recorte</button><div class="cpm-editor-toolbar"><button type="button" data-studio="flip-x">Voltear horizontal</button><button type="button" data-studio="flip-y">Voltear vertical</button></div>';
    tool("text","Texto","fa-font",[originalSections[2]]); tool("shapes","Figuras","fa-shapes",[originalSections[3]]);
    var layersPanel = tool("layers","Capas","fa-layer-group",[originalSections[4]]); layersPanel.innerHTML += '<div data-studio-layers></div><div class="cpm-editor-toolbar"><button type="button" data-studio="front">Traer al frente</button><button type="button" data-studio="back">Enviar atrás</button></div>';
    var quick = document.createElement("div"); quick.className = "cpm-studio-quick"; quick.innerHTML = '<button type="button" class="cpm-studio-action" data-studio="enhance"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Mejorar automáticamente</button><p>Luz, contraste, color y nitidez. Procesamiento local.</p><button type="button" data-studio="original">Restablecer original</button>'; panels.adjust.prepend(quick);
    var originalOverlay = document.createElement("img"); originalOverlay.className = "cpm-studio-original"; originalOverlay.alt = "Fotografía original para comparar"; originalOverlay.src = editor.sourceUrl; originalOverlay.hidden = true; stage.append(originalOverlay);
    mask.className = "cpm-studio-mask"; mask.setAttribute("aria-label","Pincel para marcar la zona de retoque"); mask.hidden = true; canvas.wrapperEl.append(mask);
    function leaveCompare() { compared = false; originalOverlay.hidden = true; canvas.wrapperEl.style.visibility = "visible"; zoom.querySelector('[data-studio="compare"]').setAttribute("aria-pressed","false"); }
    function clearMask() { maskContext.clearRect(0,0,mask.width,mask.height); }
    function stopBrush() { brush = false; mask.hidden = true; drawing = false; retouch.querySelector('[data-studio="brush"]').setAttribute("aria-pressed","false"); }
    function resetInteraction() { leaveCompare(); stopBrush(); clearMask(); if (crop) { canvas.remove(crop); crop = null; } canvas.discardActiveObject(); canvas.requestRenderAll(); }
    function activate(id) { if (dialog.dataset.editorBusy === "true") return; resetInteraction(); Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== id; }); rail.querySelectorAll("button").forEach(function (button) { button.setAttribute("aria-pressed",String(button.dataset.studioTab === id)); }); editor.fitDisplay(); }
    function updateLayers() {
      var list = layersPanel.querySelector("[data-studio-layers]"); list.replaceChildren();
      canvas.getObjects().filter(function (object) { return !object.excludeFromExport; }).slice().reverse().forEach(function (object,index) {
        var row = document.createElement("div"); row.className = "cpm-studio-layer";
        var select = document.createElement("button"); select.type = "button"; select.textContent = object.isPropertyBase ? "Fotografía" : ((object.text || (object.type === "circle" ? "Círculo" : "Figura")).slice(0,32)); select.setAttribute("aria-pressed",String(canvas.getActiveObject() === object)); select.addEventListener("click",function () { if (!object.isPropertyBase) canvas.setActiveObject(object); canvas.requestRenderAll(); updateLayers(); });
        var eye = document.createElement("button"); eye.type = "button"; eye.setAttribute("aria-label",(object.visible ? "Ocultar " : "Mostrar ") + select.textContent); eye.innerHTML = '<i class="fa-solid ' + (object.visible ? "fa-eye" : "fa-eye-slash") + '" aria-hidden="true"></i>'; eye.disabled = !!object.isPropertyBase; eye.addEventListener("click",function () { object.visible = !object.visible; canvas.requestRenderAll(); editor.snapshot(); }); row.append(select,eye); list.append(row);
      });
    }
    function photoSurface() {
      var objects = canvas.getObjects().filter(function (object) { return object !== editor.getBase(); }), visibility = objects.map(function (object) { return object.visible; });
      try { objects.forEach(function (object) { object.visible = false; }); return canvas.toCanvasElement(1); }
      finally { objects.forEach(function (object,index) { object.visible = visibility[index]; }); canvas.requestRenderAll(); }
    }
    function binaryMask() { var rgba = maskContext.getImageData(0,0,mask.width,mask.height).data, values = new Uint8Array(mask.width * mask.height), count = 0; for (var i = 0; i < values.length; i++) if (rgba[i * 4 + 3] > 0) { values[i] = 1; count++; } if (!count) throw new Error("Pinta primero el objeto o la zona que deseas quitar."); return values; }
    function busy(active) { dialog.dataset.editorBusy = String(active); dialog.setAttribute("aria-busy",String(active)); dialog.querySelectorAll("button,input,select").forEach(function (node) { if (active) { node.dataset.wasDisabled = String(node.disabled); node.disabled = true; } else { node.disabled = node.dataset.wasDisabled === "true"; delete node.dataset.wasDisabled; } }); if (!active) editor.syncHistory(); }
    async function process(action) {
      leaveCompare(); var surface = photoSurface(), pixels = surface.getContext("2d").getImageData(0,0,surface.width,surface.height), selected = action === "heal" ? binaryMask() : null;
      stopBrush(); busy(true); editor.status(action === "heal" ? "Reconstruyendo la zona seleccionada…" : "Mejorando luz, color y nitidez…");
      try {
        worker = new Worker(new URL("commercial-image-worker.js",scriptUrl).href);
        var output = await new Promise(function (resolve,reject) { worker.onmessage = function (event) { if (event.data.error) reject(new Error(event.data.error)); else resolve(event.data.pixels); }; worker.onerror = function () { reject(new Error("No se pudo procesar la imagen en este navegador.")); }; var payload = {action:action,width:surface.width,height:surface.height,pixels:pixels.data.buffer}; var transfers = [payload.pixels]; if (selected) { payload.mask = selected.buffer; transfers.push(payload.mask); } worker.postMessage(payload,transfers); });
        surface.getContext("2d").putImageData(new ImageData(new Uint8ClampedArray(output),surface.width,surface.height),0,0);
        await editor.replacePhoto(surface.toDataURL("image/png")); clearMask(); editor.status(action === "heal" ? "Retoque local aplicado. Revisa el resultado; puedes deshacerlo." : "Mejora automática aplicada. Usa «Ver original» para comparar.");
      } finally { if (worker) worker.terminate(); worker = null; busy(false); }
    }
    async function request(action,data) { data.set("action",action); data.set("nonce",runtime.nonce || ""); data.set("ticket_pk",editor.context.ticketPk || ""); var response = await fetch(runtime.ajaxUrl || "api.php",{method:"POST",body:data,credentials:"same-origin",signal:controller.signal}); var result = await response.json(); if (!response.ok || !result.success) throw new Error(result.data && result.data.message || "No se pudo completar la edición con IA."); return result.data; }
    async function cloud() {
      if (!cloudEnabled) throw new Error("La edición con IA no está activada en esta instalación.");
      var selected = binaryMask(), white = document.createElement("canvas"); white.width = mask.width; white.height = mask.height; var ctx = white.getContext("2d"), pixels = ctx.createImageData(mask.width,mask.height); for (var i = 0; i < selected.length; i++) { pixels.data[i * 4] = pixels.data[i * 4 + 1] = pixels.data[i * 4 + 2] = selected[i] ? 255 : 0; pixels.data[i * 4 + 3] = 255; } ctx.putImageData(pixels,0,0);
      var surface = photoSurface(); stopBrush(); busy(true); editor.status("Eliminando el objeto con IA…");
      try { var data = new FormData(), imageBlob = await new Promise(function (resolve) { surface.toBlob(resolve,"image/jpeg",.9); }), maskBlob = await new Promise(function (resolve) { white.toBlob(resolve,"image/png"); }); data.set("image_file",imageBlob,"foto.jpg"); data.set("mask_file",maskBlob,"mascara.png"); var result = await request("commercial_image_cleanup",data); await editor.replacePhoto("data:image/png;base64," + result.image_base64); clearMask(); editor.status("Objeto eliminado con IA. Revisa la imagen antes de aplicar los cambios."); }
      finally { busy(false); }
    }
    function cropStart() {
      resetInteraction(); var ratio = cropPanel.querySelector("select").value, w = Math.max(500,canvas.getWidth() * .8), h = Math.max(500,canvas.getHeight() * .8);
      if (ratio !== "free") { h = w / Number(ratio); if (h > canvas.getHeight()) { h = canvas.getHeight(); w = h * Number(ratio); } }
      crop = new fabric.Rect({left:(canvas.getWidth() - w) / 2,top:(canvas.getHeight() - h) / 2,width:w,height:h,fill:"rgba(255,255,255,.12)",stroke:"#ffffff",strokeWidth:2,strokeUniform:true,cornerColor:"#f4c95d",borderColor:"#f4c95d",excludeFromExport:true,lockRotation:true,lockUniScaling:ratio !== "free"}); canvas.add(crop); canvas.setActiveObject(crop); canvas.requestRenderAll(); editor.status("Ajusta el rectángulo y pulsa «Aplicar recorte».");
    }
    dialog.addEventListener("click",async function (event) {
      var tab = event.target.closest("[data-studio-tab]"); if (tab) { activate(tab.dataset.studioTab); return; }
      var button = event.target.closest("[data-studio]"); if (!button || dialog.dataset.editorBusy === "true" || dialog.dataset.editorReady !== "true") return;
      var action = button.dataset.studio;
      try {
        if (action !== "compare") leaveCompare();
        if (action === "fit") editor.setScale(null);
        if (action === "zoom-in" || action === "zoom-out") { var current = parseFloat(canvas.wrapperEl.style.width) / canvas.getWidth(); editor.setScale(Math.max(.1,Math.min(3,current + (action === "zoom-in" ? .1 : -.1)))); }
        if (action === "compare") { compared = !compared; originalOverlay.hidden = !compared; canvas.wrapperEl.style.visibility = compared ? "hidden" : "visible"; button.setAttribute("aria-pressed",String(compared)); }
        if (action === "original") editor.restoreOriginal();
        if (action === "enhance") { resetInteraction(); await process("enhance"); }
        if (action === "brush") { brush = !brush; mask.hidden = !brush; button.setAttribute("aria-pressed",String(brush)); if (brush) { if (mask.width !== canvas.getWidth() || mask.height !== canvas.getHeight()) { mask.width = canvas.getWidth(); mask.height = canvas.getHeight(); } editor.status("Pinta sobre el objeto para marcarlo. Después elige retoque local o IA."); } }
        if (action === "clear-mask") clearMask();
        if (action === "heal") await process("heal");
        if (action === "cloud") await cloud();
        if (action === "crop-start") cropStart();
        if (action === "crop-apply") { if (!crop) throw new Error("Selecciona primero un recorte."); var bounds = crop.getBoundingRect(), left = Math.max(0,Math.round(bounds.left)), top = Math.max(0,Math.round(bounds.top)), width = Math.floor(Math.min(bounds.width,canvas.getWidth() - left)), height = Math.floor(Math.min(bounds.height,canvas.getHeight() - top)); if (width < 500 || height < 500) throw new Error("El recorte debe medir al menos 500 × 500 px."); canvas.remove(crop); crop = null; canvas.getObjects().forEach(function (object) { object.left -= left; object.top -= top; object.setCoords(); }); canvas.setDimensions({width:width,height:height}); dialog.querySelector('[data-setting="width"]').value = width; dialog.querySelector('[data-setting="height"]').value = height; canvas.discardActiveObject(); canvas.renderAll(); editor.snapshot(); editor.setScale(null); }
        if (action === "flip-x" || action === "flip-y") { var base = editor.getBase(), key = action === "flip-x" ? "flipX" : "flipY"; base.set(key,!base[key]); canvas.requestRenderAll(); editor.snapshot(); }
        if (action === "front" || action === "back") { var active = canvas.getActiveObject(); if (!active || active.isPropertyBase) throw new Error("Selecciona una capa de texto o figura."); if (action === "front") canvas.bringToFront(active); else canvas.moveTo(active,1); canvas.requestRenderAll(); editor.snapshot(); }
      } catch (error) { if (dialog.open) editor.status(error.message || "No se pudo completar esta herramienta."); }
    });
    function point(event) { var bounds = mask.getBoundingClientRect(); return {x:(event.clientX - bounds.left) * mask.width / bounds.width,y:(event.clientY - bounds.top) * mask.height / bounds.height}; }
    mask.addEventListener("pointerdown",function (event) { if (!brush) return; event.preventDefault(); mask.setPointerCapture(event.pointerId); drawing = true; lastPoint = point(event); maskContext.fillStyle = "rgba(236,83,112,.7)"; maskContext.beginPath(); maskContext.arc(lastPoint.x,lastPoint.y,Number(retouch.querySelector("input").value) / 2,0,Math.PI * 2); maskContext.fill(); });
    mask.addEventListener("pointermove",function (event) { if (!drawing) return; var current = point(event); maskContext.strokeStyle = "rgba(236,83,112,.7)"; maskContext.lineWidth = Number(retouch.querySelector("input").value); maskContext.lineCap = "round"; maskContext.beginPath(); maskContext.moveTo(lastPoint.x,lastPoint.y); maskContext.lineTo(current.x,current.y); maskContext.stroke(); lastPoint = current; });
    function endStroke() { drawing = false; } mask.addEventListener("pointerup",endStroke); mask.addEventListener("pointercancel",endStroke);
    canvas.on("selection:created",updateLayers); canvas.on("selection:updated",updateLayers); canvas.on("selection:cleared",updateLayers);
    activate("adjust");
    return {updateZoom:function (scale) { zoom.querySelector("output").textContent = Math.round(scale * 100) + "%"; },updateLayers:updateLayers,leaveCompare:leaveCompare,resetInteraction:resetInteraction,ready:function () { mask.width = canvas.getWidth(); mask.height = canvas.getHeight(); updateLayers(); editor.fitDisplay(); var note = retouch.querySelector("[data-studio-ai-note]"); note.textContent = "IA no activada. El retoque local está disponible sin enviar la foto a otro servicio."; retouch.querySelector('[data-studio="cloud"]').disabled = true; if (editor.context.ticketPk) request("commercial_image_cleanup_status",new FormData()).then(function (result) { cloudEnabled = result.available; if (!dialog.open) return; retouch.querySelector('[data-studio="cloud"]').disabled = !cloudEnabled; note.textContent = cloudEnabled ? "Al pulsar, se enviará esta foto y la zona marcada a Clipdrop para eliminar el objeto." : note.textContent; }).catch(function () {}); },dispose:function () { controller.abort(); if (worker) worker.terminate(); }};
  };
})();
