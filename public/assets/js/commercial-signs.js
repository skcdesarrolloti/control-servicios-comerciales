(function () {
  "use strict";
  var runtime = {};
  var app = document.getElementById("scm-app");
  if (!app) return;
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var maxBytes = 10 * 1024 * 1024;

  function field(form, name) { return form.elements.namedItem(name); }
  function status(node, text, error) { node.textContent = text; node.dataset.error = error ? "true" : "false"; }
  function key(form) { return field(form, "id_ticket").value + ":" + field(form, "id_inmueble").value; }
  function showError(root, message) {
    var node = root.querySelector("[data-sign-error]");
    node.textContent = message;
    node.hidden = !message;
    if (message) node.scrollIntoView({ block: "nearest" });
  }
  async function request(action, data, signal) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin", signal: signal });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("No se pudo procesar la respuesta. Revisa el tamaño de las fotografías y vuelve a intentarlo."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }
  function clearLocation(root) {
    var form = root.querySelector("[data-sign-form]");
    ["mapa_latitud", "mapa_longitud", "mapa_precision"].forEach(function (name) { field(form, name).value = ""; });
    form._locationKey = "";
    root.querySelector("[data-sign-map]").hidden = true;
    status(root.querySelector("[data-sign-location-status]"), "Ubicación pendiente de capturar.");
  }
  function conditions(root) {
    var form = root.querySelector("[data-sign-form]");
    var retouch = field(form, "tipo_aviso").value === "Retoque";
    var noSign = retouch && field(form, "pego_aviso").value === "No";
    root.querySelector("[data-sign-retouch]").hidden = !retouch;
    field(form, "pego_aviso").disabled = !retouch;
    root.querySelector("[data-sign-reason]").hidden = !noSign;
    field(form, "razon").disabled = !noSign;
    field(form, "cantidad").min = noSign ? "0" : "1";
    field(form, "cantidad").readOnly = noSign;
    if (noSign) field(form, "cantidad").value = "0";
    else if (Number(field(form, "cantidad").value) < 1) field(form, "cantidad").value = "1";
    field(form, "barrio_id").hidden = retouch;
    field(form, "barrio_id").disabled = retouch;
    root.querySelector("[data-sign-retouch-barrio]").hidden = !retouch;
    ["tipo_inmueble", "categoria"].forEach(function (name) { field(form, name).disabled = retouch; });
    var agencies = Array.from(field(form, "promocionado_por[]").selectedOptions).some(function (option) { return /inm+obiliaria/i.test(option.value); });
    root.querySelector("[data-sign-competition]").hidden = !agencies;
    field(form, "competencia[]").disabled = !agencies;
    root.querySelector("[data-sign-unpublish]").hidden = !(noSign && ["Arrendado", "Vendido", "No quiere promocion"].indexOf(field(form, "razon").value) !== -1);
  }
  function releasePreviews(root) {
    (root._previewUrls || []).forEach(function (url) { URL.revokeObjectURL(url); });
    root._previewUrls = [];
    root.querySelector("[data-sign-previews]").replaceChildren();
  }
  function start(root, type) {
    var form = root.querySelector("[data-sign-form]");
    if (form._saving) return;
    if (field(form, "tipo_aviso").value !== type || root._saved) {
      form.reset();
      if (root._saved) {
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        var token = Array.from(bytes, function (value) { return value.toString(16).padStart(2, "0"); }).join("");
        field(form, "request_id").value = token;
        field(form, "request_id").defaultValue = token;
      }
      releasePreviews(root);
      form._lookupKey = "";
      clearLocation(root);
    }
    root._saved = false;
    root.querySelector("[data-sign-result]").hidden = true;
    field(form, "tipo_aviso").value = type;
    root.querySelector("[data-sign-choices]").hidden = true;
    form.hidden = false;
    root.querySelectorAll("[data-sign-type]").forEach(function (button) { button.setAttribute("aria-expanded", button.dataset.signType === type ? "true" : "false"); });
    root.querySelector("[data-sign-title]").textContent = type === "Nuevo" ? "Aviso nuevo" : "Retoque de aviso";
    root.querySelector("[data-sign-submit]").textContent = type === "Nuevo" ? "Guardar aviso" : "Guardar retoque";
    showError(root, "");
    conditions(root);
    root.querySelector("[data-sign-title]").focus();
  }
  function invalidate(root) {
    var form = root.querySelector("[data-sign-form]");
    if (form._lookupAbort) form._lookupAbort.abort();
    form._lookupKey = "";
    clearLocation(root);
    status(root.querySelector("[data-sign-property-status]"), "Consulta nuevamente la tarea y el inmueble.");
    ["ruta", "punto_referencia", "tipo_inmueble", "categoria"].forEach(function (name) { field(form, name).value = ""; });
    root.querySelector("[data-sign-retouch-barrio]").value = "";
  }
  async function lookup(root) {
    var form = root.querySelector("[data-sign-form]");
    var button = root.querySelector("[data-sign-lookup]");
    if (!field(form, "id_ticket").reportValidity() || !field(form, "id_inmueble").reportValidity()) return;
    if (form._lookupAbort) form._lookupAbort.abort();
    var abort = new AbortController();
    form._lookupAbort = abort;
    var currentKey = key(form);
    button.disabled = true;
    status(root.querySelector("[data-sign-property-status]"), "Consultando tarea e inmueble…");
    try {
      var data = new FormData();
      data.set("id_ticket", field(form, "id_ticket").value);
      data.set("id_inmueble", field(form, "id_inmueble").value);
      var result = await request("commercial_sign_lookup", data, abort.signal);
      if (currentKey !== key(form) || abort.signal.aborted) return;
      var property = result.property;
      form._lookupKey = currentKey;
      ["tipo_inmueble", "categoria"].forEach(function (name) {
        var value = property[name === "categoria" ? "tipo_negocio" : name] || "";
        var select = field(form, name);
        if (field(form, "tipo_aviso").value === "Retoque" && value && !Array.from(select.options).some(function (option) { return option.value === value; })) select.add(new Option(value, value));
        select.value = value;
      });
      field(form, "punto_referencia").value = property.punto_referencia || "";
      root.querySelector("[data-sign-retouch-barrio]").value = property.barrio || "";
      var barrio = field(form, "barrio_id");
      var match = Array.from(barrio.options).find(function (option) { return option.dataset.name === property.barrio && (!property.ciudad || option.textContent.indexOf(property.ciudad) !== -1); });
      barrio.value = match ? match.value : "";
      field(form, "ruta").value = field(form, "tipo_aviso").value === "Retoque" ? (property.ruta || "") : (match ? match.dataset.route : "");
      status(root.querySelector("[data-sign-property-status]"), "Inmueble #" + property.codigo + (property.direccion ? " · " + property.direccion : "") + " · Tarea: " + (result.ticket.asunto || "#" + result.ticket._ID));
      showError(root, "");
    } catch (error) {
      if (error.name !== "AbortError") {
        form._lookupKey = "";
        status(root.querySelector("[data-sign-property-status]"), error.message, true);
      }
    } finally { if (form._lookupAbort === abort) button.disabled = false; }
  }
  function capture(root) {
    var form = root.querySelector("[data-sign-form]");
    var node = root.querySelector("[data-sign-location-status]");
    if (form._lookupKey !== key(form)) { status(node, "Consulta primero la tarea y el inmueble.", true); return; }
    if (!navigator.geolocation) { status(node, "Este navegador no permite capturar la ubicación.", true); return; }
    var currentKey = key(form);
    var button = root.querySelector("[data-sign-capture]");
    clearLocation(root);
    button.disabled = true;
    status(node, "Buscando tu ubicación actual…");
    navigator.geolocation.getCurrentPosition(function (position) {
      button.disabled = false;
      if (key(form) !== currentKey || form._lookupKey !== currentKey) return;
      field(form, "mapa_latitud").value = position.coords.latitude;
      field(form, "mapa_longitud").value = position.coords.longitude;
      field(form, "mapa_precision").value = position.coords.accuracy;
      form._locationKey = currentKey;
      showError(root, "");
      status(node, "Ubicación capturada. Precisión aproximada: " + Math.round(position.coords.accuracy) + " metros.");
      var map = root.querySelector("[data-sign-map]");
      map.href = "https://www.google.com/maps?q=" + position.coords.latitude + "," + position.coords.longitude;
      map.hidden = false;
    }, function (error) {
      button.disabled = false;
      if (key(form) !== currentKey) return;
      status(node, error.code === 1 ? "Permite el acceso a la ubicación en tu navegador y vuelve a capturarla." : "No se pudo obtener la ubicación. Activa el GPS e inténtalo de nuevo desde el inmueble.", true);
    }, { enableHighAccuracy: true, timeout: 25000, maximumAge: 0 });
  }
  function previews(root) {
    var form = root.querySelector("[data-sign-form]");
    var input = field(form, "fotos[]");
    releasePreviews(root);
    input.setCustomValidity("");
    var files = Array.from(input.files || []);
    var allowed = /\.(jpe?g|png|webp|gif|bmp|tiff?|avif|heic|heif)$/i;
    var hint = document.getElementById("csg-photo-help");
    var match = hint && hint.textContent.match(/Máximo ([\d.]+)/);
    var limit = match ? parseFloat(match[1]) * 1024 * 1024 : maxBytes;
    if (files.length > 3 || files.some(function (file) { return file.size > limit || !allowed.test(file.name); })) {
      input.setCustomValidity("Selecciona hasta 3 fotografías con el formato y tamaño permitidos.");
      input.reportValidity();
      return;
    }
    files.forEach(function (file, index) {
      var item = document.createElement("div"); item.className = "csg-preview";
      var image = document.createElement("img"); image.alt = "Evidencia " + (index + 1) + ": " + file.name;
      var url = URL.createObjectURL(file); root._previewUrls.push(url); image.src = url;
      image.onerror = function () { image.hidden = true; var name = document.createElement("span"); name.textContent = file.name; item.prepend(name); };
      var remove = document.createElement("button"); remove.type = "button"; remove.textContent = "Quitar foto " + (index + 1);
      remove.addEventListener("click", function () { var transfer = new DataTransfer(); files.forEach(function (other, i) { if (i !== index) transfer.items.add(other); }); input.files = transfer.files; previews(root); });
      item.append(image, remove); root.querySelector("[data-sign-previews]").append(item);
    });
  }
  document.addEventListener("click", function (event) {
    var target = event.target.closest("button");
    if (!target) return;
    var root = target.closest("[data-commercial-signs]");
    if (!root) return;
    if (target.hasAttribute("data-sign-type")) start(root, target.dataset.signType);
    if (target.hasAttribute("data-sign-back") || target.hasAttribute("data-sign-again")) {
      if (root.querySelector("[data-sign-form]")._saving) return;
      root.querySelector("[data-sign-form]").hidden = true;
      root.querySelector("[data-sign-choices]").hidden = false;
      root.querySelector("[data-sign-result]").hidden = true;
      root.querySelectorAll("[data-sign-type]").forEach(function (choice) { choice.setAttribute("aria-expanded", "false"); });
      root.querySelector("[data-sign-type]").focus();
    }
    if (target.hasAttribute("data-sign-lookup")) lookup(root);
    if (target.hasAttribute("data-sign-capture")) capture(root);
    if (target.hasAttribute("data-sign-retry")) {
      target.disabled = true;
      var syncWarning = root.querySelector("[data-sign-result] .csg-warning");
      var data = new FormData();
      data.set("request_id", field(root.querySelector("[data-sign-form]"), "request_id").value);
      request("commercial_sign_sync", data).then(function (result) {
        syncWarning.textContent = result.warning || "La despublicación en portales se sincronizó correctamente.";
        target.hidden = !result.portal_pending;
      }).catch(function (error) {
        syncWarning.textContent = error.message;
      }).finally(function () { target.disabled = false; });
    }
  });
  document.addEventListener("input", function (event) {
    var root = event.target.closest("[data-commercial-signs]");
    if (root && ["id_ticket", "id_inmueble"].indexOf(event.target.name) !== -1) invalidate(root);
  });
  document.addEventListener("change", function (event) {
    var root = event.target.closest("[data-commercial-signs]");
    if (!root) return;
    var form = root.querySelector("[data-sign-form]");
    if (event.target.name === "barrio_id") { var option = event.target.selectedOptions[0]; field(form, "ruta").value = option ? option.dataset.route || "" : ""; }
    if (event.target.name === "fotos[]") previews(root);
    conditions(root);
  });
  document.addEventListener("submit", async function (event) {
    var form = event.target;
    if (!form.matches("[data-sign-form]")) return;
    event.preventDefault();
    if (form._saving) return;
    var root = form.closest("[data-commercial-signs]");
    showError(root, "");
    if (form._lookupKey !== key(form)) { showError(root, "Consulta y verifica el inmueble antes de guardar."); return; }
    if (form._locationKey !== key(form)) { showError(root, "Captura la ubicación actual del inmueble antes de guardar."); root.querySelector("[data-sign-capture]").focus(); return; }
    if (!form.reportValidity()) return;
    var data = new FormData(form);
    var controls = Array.from(form.querySelectorAll("input,select,textarea,button"));
    var disabled = controls.map(function (control) { return control.disabled; });
    var submit = root.querySelector("[data-sign-submit]");
    var label = submit.textContent;
    form._saving = true;
    controls.forEach(function (control) { control.disabled = true; });
    submit.textContent = "Guardando…";
    try {
      var result = await request("commercial_sign_save", data);
      root._saved = true;
      form.hidden = true;
      var node = root.querySelector("[data-sign-result]");
      node.replaceChildren();
      var text = document.createElement("p"); text.textContent = result.message;
      node.append(text);
      if (result.warning) {
        var warning = document.createElement("p"); warning.className = "csg-warning"; warning.textContent = result.warning; node.append(warning);
        var retry = document.createElement("button"); retry.type = "button"; retry.className = "csg-secondary"; retry.dataset.signRetry = ""; retry.textContent = "Reintentar sincronización"; node.append(retry);
      }
      var again = document.createElement("button"); again.type = "button"; again.className = "csg-secondary"; again.dataset.signAgain = ""; again.textContent = "Registrar otro aviso";
      node.append(again); node.hidden = false; again.focus();
      releasePreviews(root);
    } catch (error) { showError(root, error.message); }
    finally {
      controls.forEach(function (control, index) { control.disabled = disabled[index]; });
      submit.textContent = label; form._saving = false;
    }
  });
})();
