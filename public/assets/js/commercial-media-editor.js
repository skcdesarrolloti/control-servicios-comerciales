(function () {
  "use strict";
  var scriptUrl = document.currentScript.src, loading;
  function library() {
    if (window.fabric) return Promise.resolve(window.fabric);
    if (!loading) loading = new Promise(function (resolve, reject) { var script = document.createElement("script"); script.src = new URL("../vendor/fabric-5.3.1.min.js", scriptUrl).href; script.onload = function () { resolve(window.fabric); }; script.onerror = function () { loading = null; reject(new Error("No se pudo cargar el editor de imágenes. Intenta de nuevo.")); }; document.head.append(script); });
    return loading;
  }
  window.openPropertyImageEditor = async function (item, apply) {
    var fabric = await library(), dialog = document.createElement("dialog"); dialog.className = "cpm-editor"; dialog.setAttribute("aria-labelledby", "cpm-editor-title");
    dialog.innerHTML = '<header><h2 id="cpm-editor-title">Editor de imagen</h2><div class="cpm-editor-toolbar"><button type="button" class="csg-secondary" data-edit="undo">Deshacer</button><button type="button" class="csg-secondary" data-edit="redo">Rehacer</button><button type="button" class="csg-secondary" data-edit="close">Cancelar</button><button type="button" class="csg-primary" data-edit="save">Aplicar cambios</button></div></header><div class="cpm-editor-body"><div class="cpm-editor-tools"></div><div class="cpm-editor-stage"><canvas></canvas></div></div><p class="cpm-editor-status" role="status"></p>';
    var tools = dialog.querySelector(".cpm-editor-tools");
    function section(title, content) { var details = document.createElement("details"); details.open = true; var summary = document.createElement("summary"); summary.textContent = title; details.append(summary); details.insertAdjacentHTML("beforeend", content); tools.append(details); }
    function input(label, id, type, value, attributes) { return '<label>' + label + '<input data-setting="' + id + '" type="' + type + '" value="' + value + '" ' + (attributes || "") + '></label>'; }
    function button(label, action) { return '<button type="button" data-edit="' + action + '">' + label + '</button>'; }
    section("Tamaño y calidad", '<div class="cpm-editor-grid">' + input("Ancho (px)","width","number",1200,'min="500" max="1700"') + input("Alto (px)","height","number",800,'min="500" max="1200"') + '</div><label><input type="checkbox" data-setting="ratio" checked> Mantener proporción</label>' + input("Calidad JPG","quality","range",88,'min="40" max="95"') + button("Aplicar tamaño","size"));
    section("Ajustes de la foto", input("Brillo","brightness","range",0,'min="-100" max="100"') + input("Contraste","contrast","range",0,'min="-100" max="100"') + input("Saturación","saturation","range",0,'min="-100" max="100"') + input("Desenfoque","blur","range",0,'min="0" max="100"') + '<div class="cpm-editor-toolbar">' + button("Restablecer filtros","reset") + button("Girar foto −90°","base-left") + button("Girar foto +90°","base-right") + '</div>');
    section("Texto", input("Escribe un texto","text","text","",'maxlength="200"') + '<label>Fuente<select data-setting="font"><option>Arial</option><option>Verdana</option><option>Georgia</option><option>Tahoma</option><option>Times New Roman</option><option>Courier New</option></select></label><div class="cpm-editor-grid">' + input("Tamaño","fontSize","number",34,'min="8" max="300"') + input("Color","textColor","color","#ffffff") + '</div>' + input("Color de fondo","textBackground","color","#102e58") + '<label><input type="checkbox" data-setting="textBold"> Negrita</label><label><input type="checkbox" data-setting="textItalic"> Cursiva</label><label><input type="checkbox" data-setting="textUseBackground"> Fondo visible</label><div class="cpm-editor-toolbar">' + button("Agregar texto","text-add") + button("Aplicar a texto","text-apply") + '</div>');
    section("Figuras y marcos", '<div class="cpm-editor-grid">' + input("Relleno","fill","color","#234e80") + input("Borde","stroke","color","#ffffff") + '</div>' + input("Opacidad (%)","opacity","range",35,'min="0" max="100"') + input("Grosor de borde","strokeWidth","range",4,'min="0" max="30"') + '<div class="cpm-editor-toolbar">' + button("Cuadro","rect") + button("Círculo","circle") + button("Marco","border") + button("Aplicar a figura","shape-apply") + '</div>');
    section("Elemento seleccionado", '<p>Mueve y ajusta los elementos directamente sobre la imagen.</p><div class="cpm-editor-toolbar">' + button("Girar −90°","object-left") + button("Girar +90°","object-right") + button("Eliminar","delete") + '</div>');
    document.body.append(dialog); dialog.showModal();
    var canvas = new fabric.Canvas(dialog.querySelector("canvas"), {backgroundColor:"#ffffff",preserveObjectStacking:true}), base, history = [], historyIndex = -1, restoring = false, timer;
    function setting(name) { return dialog.querySelector('[data-setting="' + name + '"]'); }
    function number(name) { return Number(setting(name).value); }
    function status(text) { dialog.querySelector(".cpm-editor-status").textContent = text; }
    function fitDisplay() { var width = Math.max(1, Math.min(canvas.getWidth(), dialog.querySelector(".cpm-editor-stage").clientWidth - 26)); canvas.setDimensions({width:width + "px",height:(width * canvas.getHeight() / canvas.getWidth()) + "px"}, {cssOnly:true}); canvas.calcOffset(); }
    function estimate() { clearTimeout(timer); timer = setTimeout(function () { if (!dialog.open) return; try { var data = canvas.toDataURL({format:"jpeg",quality:number("quality") / 100,enableRetinaScaling:false}); status(canvas.getWidth() + " × " + canvas.getHeight() + " px · " + Math.round((data.length - data.indexOf(",") - 1) * 0.75 / 1024) + " KB aprox. · máximo 1 MB"); } catch (_error) { status("No se puede exportar esta imagen. Vuelve a seleccionarla desde tu equipo."); } },150); }
    function snapshot() { if (restoring) return; history = history.slice(0,historyIndex + 1); var settings = {}; ["brightness","contrast","saturation","blur","quality"].forEach(function (key) { settings[key] = setting(key).value; }); history.push({canvas:canvas.toJSON(["isPropertyBase"]),width:canvas.getWidth(),height:canvas.getHeight(),settings:settings}); if (history.length > 20) history.shift(); historyIndex = history.length - 1; estimate(); }
    function fitBase() { if (!base) return; var rotated = Math.abs(base.angle % 180) === 90, w = rotated ? base.height : base.width, h = rotated ? base.width : base.height; base.scale(Math.min(canvas.getWidth() / w,canvas.getHeight() / h)); base.set({left:canvas.getWidth() / 2,top:canvas.getHeight() / 2,originX:"center",originY:"center"}); base.setCoords(); canvas.requestRenderAll(); }
    function filters() { if (!base) return; base.filters = [new fabric.Image.filters.Brightness({brightness:number("brightness") / 100}),new fabric.Image.filters.Contrast({contrast:number("contrast") / 100}),new fabric.Image.filters.Saturation({saturation:number("saturation") / 100}),new fabric.Image.filters.Blur({blur:number("blur") / 100})]; base.applyFilters(); canvas.requestRenderAll(); }
    function textStyle() { return {fontFamily:setting("font").value,fontSize:Math.max(8,Math.min(300,number("fontSize"))),fill:setting("textColor").value,fontWeight:setting("textBold").checked ? "bold" : "normal",fontStyle:setting("textItalic").checked ? "italic" : "normal",backgroundColor:setting("textUseBackground").checked ? setting("textBackground").value : ""}; }
    function shapeStyle() { return {fill:setting("fill").value,stroke:setting("stroke").value,opacity:number("opacity") / 100,strokeWidth:number("strokeWidth")}; }
    function add(object) { object.set({left:canvas.getWidth() / 2,top:canvas.getHeight() / 2,originX:"center",originY:"center"}); canvas.add(object); canvas.setActiveObject(object); canvas.requestRenderAll(); }
    canvas.on("object:added", snapshot); canvas.on("object:modified", snapshot); canvas.on("object:removed", snapshot);
    dialog.addEventListener("input", function (event) { var name = event.target.dataset.setting; if (["brightness","contrast","saturation","blur"].includes(name)) { filters(); estimate(); } if (["width","height"].includes(name) && setting("ratio").checked) { var ratio = canvas.getWidth() / canvas.getHeight(); setting(name === "width" ? "height" : "width").value = Math.round(name === "width" ? number("width") / ratio : number("height") * ratio); } if (name === "quality") estimate(); });
    dialog.addEventListener("change", function (event) { if (["brightness","contrast","saturation","blur"].includes(event.target.dataset.setting)) snapshot(); });
    dialog.addEventListener("keydown", function (event) { if (event.key === "Escape") { event.stopPropagation(); event.preventDefault(); } });
    dialog.addEventListener("keyup", function (event) { if (event.key === "Escape") { event.stopPropagation(); event.preventDefault(); dialog.close(); } });
    dialog.addEventListener("close", function () { clearTimeout(timer); observer.disconnect(); canvas.dispose(); dialog.remove(); }, {once:true});
    var observer = new ResizeObserver(fitDisplay); observer.observe(dialog.querySelector(".cpm-editor-stage"));
    dialog.addEventListener("click", async function (event) {
      var clicked = event.target.closest("[data-edit]"); if (!clicked) return;
      var action = clicked.dataset.edit, active = canvas.getActiveObject();
      try {
        if (action === "close") { dialog.close(); return; }
        if (action === "undo" || action === "redo") {
          var target = historyIndex + (action === "undo" ? -1 : 1); if (target < 0 || target >= history.length) return;
          historyIndex = target; restoring = true; var previous = history[target]; canvas.setDimensions({width:previous.width,height:previous.height}); setting("width").value = previous.width; setting("height").value = previous.height; Object.keys(previous.settings).forEach(function (key) { setting(key).value = previous.settings[key]; }); canvas.loadFromJSON(previous.canvas, function () { base = canvas.getObjects().find(function (object) { return object.isPropertyBase; }); restoring = false; canvas.requestRenderAll(); fitDisplay(); estimate(); }); return;
        }
        if (action === "size") { var w = number("width"), h = number("height"); if (!Number.isInteger(w) || !Number.isInteger(h) || w < 500 || h < 500 || w > 1700 || h > 1200) throw new Error("Elige un tamaño de 500 × 500 a 1700 × 1200 px."); canvas.setDimensions({width:w,height:h}); fitBase(); fitDisplay(); snapshot(); }
        if (action === "reset") { ["brightness","contrast","saturation","blur"].forEach(function (key) { setting(key).value = 0; }); filters(); snapshot(); }
        if (action === "base-left" || action === "base-right") { base.rotate(base.angle + (action === "base-left" ? -90 : 90)); fitBase(); snapshot(); }
        if (action === "text-add") { if (!setting("text").value.trim()) throw new Error("Escribe el texto que deseas agregar."); add(new fabric.IText(setting("text").value,textStyle())); }
        if (action === "text-apply") { if (!active || active.type !== "i-text") throw new Error("Selecciona un texto sobre la imagen."); active.set(Object.assign(textStyle(),{text:setting("text").value})); canvas.requestRenderAll(); snapshot(); }
        if (action === "rect") add(new fabric.Rect(Object.assign(shapeStyle(),{width:240,height:140})));
        if (action === "circle") add(new fabric.Circle(Object.assign(shapeStyle(),{radius:90})));
        if (action === "border") add(new fabric.Rect(Object.assign(shapeStyle(),{width:canvas.getWidth() - 24,height:canvas.getHeight() - 24,fill:"transparent",opacity:1})));
        if (action === "shape-apply") { if (!active || !["rect","circle"].includes(active.type)) throw new Error("Selecciona una figura sobre la imagen."); active.set(shapeStyle()); canvas.requestRenderAll(); snapshot(); }
        if (action === "object-left" || action === "object-right") { if (!active || active === base) throw new Error("Selecciona un texto o una figura."); active.rotate(active.angle + (action === "object-left" ? -90 : 90)); canvas.requestRenderAll(); snapshot(); }
        if (action === "delete" && active && active !== base) canvas.remove(active);
        if (action === "save") {
          clicked.disabled = true; canvas.discardActiveObject(); canvas.renderAll();
          try { var data = canvas.toDataURL({format:"jpeg",quality:number("quality") / 100,enableRetinaScaling:false}); var blob = await (await fetch(data)).blob(); await apply(new File([blob],"inmueble-editado.jpg",{type:"image/jpeg"})); dialog.close(); }
          finally { clicked.disabled = false; }
        }
      } catch (error) { status(error.message || "No se pudo aplicar la edición."); }
    });
    status("Cargando imagen…");
    fabric.Image.fromURL(item.url, function (image, failed) {
      if (failed || !image) { status("No se pudo abrir la imagen. Selecciónala de nuevo desde tu equipo."); return; }
      base = image; base.isPropertyBase = true; base.set({selectable:false,evented:false});
      var scale = Math.min(1,1700 / image.width,1200 / image.height), width = Math.max(500,Math.floor(image.width * scale)), height = Math.max(500,Math.floor(image.height * scale));
      canvas.setDimensions({width:width,height:height}); setting("width").value = width; setting("height").value = height;
      restoring = true; canvas.add(base); restoring = false; fitBase(); fitDisplay(); snapshot();
    }, {crossOrigin:"anonymous"});
  };
})();
