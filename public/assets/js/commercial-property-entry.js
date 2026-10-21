(function () {
  "use strict";
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  function control(form, name) { return form.elements.namedItem(name) || form.elements.namedItem(name + "[]"); }
  function value(node) { return node && node.multiple ? Array.from(node.selectedOptions, function (option) { return option.value; }) : (node ? node.value : ""); }
  function matches(groups, data) {
    return groups.some(function (clauses) {
      return clauses.every(function (clause) {
        var actual = [].concat(data[clause.field] || "").map(String);
        var expected = String(clause.value || "").split(",").map(function (item) { return item.trim(); });
        var included = actual.some(function (item) { return expected.indexOf(item) !== -1; });
        return ["not_one_of", "not_equal"].indexOf(clause.operator) !== -1 ? !included : included;
      });
    });
  }
  function collect(form, definition) {
    var data = { modo_ingreso: value(control(form, "modo_ingreso")) };
    definition.fields.forEach(function (field) { data[field.name] = value(control(form, field.name)); });
    Object.keys(definition.derived).forEach(function (name) {
      data[name] = "";
      definition.derived[name].some(function (group) { if (!matches(group.when, data)) return false; data[name] = group.value; return true; });
    });
    data.edad_inmueble = data.ano_construccion ? new Date().getFullYear() - Number(data.ano_construccion) : "";
    return data;
  }
  function step(root, index) {
    var previous = root._step;
    root._step = Math.max(0, Math.min(4, Number(index)));
    root.querySelectorAll("[data-entry-panel]").forEach(function (panel) { panel.hidden = Number(panel.dataset.entryPanel) !== root._step; });
    root.querySelectorAll("[data-entry-tab]").forEach(function (button) { button.setAttribute("aria-current", Number(button.dataset.entryTab) === root._step ? "step" : "false"); });
    root.querySelector("[data-entry-prev]").hidden = root._step === 0;
    root.querySelector("[data-entry-next]").hidden = root._step === 4;
    root.querySelector("[data-property-submit]").hidden = root._step !== 4;
    root.querySelector("[data-entry-progress]").textContent = ["Datos del inmueble","Detalles y condiciones","Ubicación del inmueble","Características seleccionables","Fotos, galería y video"][root._step];
    if (previous !== root._step) root.closest(".commercial-entry-shell").scrollTop = 0;
  }
  function syncChoices(root) {
    var form = root.querySelector("form");
    root.querySelectorAll("[data-choice-group]").forEach(function (group) {
      var select = control(form, group.dataset.choiceGroup), selected = [].concat(value(select));
      group.querySelectorAll("[data-choice-value]").forEach(function (button) { button.setAttribute("aria-pressed", selected.indexOf(button.dataset.choiceValue) !== -1 ? "true" : "false"); button.disabled = select.disabled; });
      var count = root.querySelector('[data-feature-count="' + group.dataset.choiceGroup + '"]');
      if (count) count.textContent = selected.length + " seleccionadas";
    });
  }
  function invalidField(root, container) {
    var invalid = Array.from(container.querySelectorAll("input,select,textarea")).find(function (node) { return node.willValidate && !node.validity.valid; });
    if (!invalid) return false;
    step(root, invalid.closest("[data-entry-panel]").dataset.entryPanel);
    var details = invalid.closest("details"); if (details) details.open = true;
    if (invalid.classList.contains("cpe-native-choice")) {
      var group = invalid.parentElement.querySelector("[data-choice-group]"); error(root, "Completa " + invalid.getAttribute("aria-label") + "."); group.querySelector("button").focus();
    } else { invalid.reportValidity(); invalid.focus(); }
    return true;
  }
  async function neighborhood(root) {
    var form = root.querySelector("form");
    var values = {barrio:value(control(form,"barrio")),ciudad:value(control(form,"ciudad")),pais:value(control(form,"pais"))};
    var key = JSON.stringify(values);
    if (key === root._neighborhoodKey) return;
    root._neighborhoodKey = key;
    if (root._neighborhoodRequest) root._neighborhoodRequest.abort();
    root.querySelectorAll("[data-choice-suggestion]").forEach(function (badge) { badge.hidden = true; });
    root.querySelectorAll("[data-apply-suggestions]").forEach(function (button) { button.hidden = true; });
    root._suggestions = {};
    var status = root.querySelector("[data-neighborhood-status] span");
    if (!values.barrio) { status.textContent = "Elige un barrio en Ubicación para consultar sugerencias. Las selecciones que ya hiciste se conservan."; return; }
    status.textContent = "Consultando características registradas en " + values.barrio + "…";
    var abort = new AbortController(); root._neighborhoodRequest = abort;
    try {
      var data = new FormData(); Object.keys(values).forEach(function (name) { data.set(name,values[name]); }); data.set("ticket_pk", control(form,"ticket_pk").value); data.set("action","commercial_property_neighborhood"); data.set("nonce",runtime.nonce || "");
      var response = await fetch(runtime.ajaxUrl || "api.php", {method:"POST",body:data,credentials:"same-origin",signal:abort.signal}); var payload = await response.json();
      if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || "No se pudieron consultar las sugerencias.");
      if (key !== root._neighborhoodKey) return;
      root._suggestions = payload.data.groups;
      status.textContent = payload.data.sample_size ? "Sugerencias de " + values.barrio + " · basadas en " + payload.data.sample_size + " inmuebles registrados. Revisa cuáles corresponden a este inmueble; no se marcan automáticamente." : "Aún no hay características registradas para " + values.barrio + ". Puedes elegirlas del catálogo completo.";
      root.querySelectorAll("[data-choice-group]").forEach(function (group) {
        var suggestions = root._suggestions[group.dataset.choiceGroup] || {};
        group.querySelectorAll("[data-choice-value]").forEach(function (button) { var badge = button.querySelector("[data-choice-suggestion]"), count = suggestions[button.dataset.choiceValue]; badge.hidden = !count; badge.textContent = count ? "En " + count + " de " + payload.data.sample_size : ""; });
        var apply = group.querySelector("[data-apply-suggestions]"); if (apply) apply.hidden = !Object.keys(suggestions).length;
      });
    } catch (exception) { if (exception.name !== "AbortError") status.textContent = exception.message + " Puedes seleccionar las características manualmente."; }
  }
  function refresh(root) {
    var form = root.querySelector("form"), definition = root._definition;
    [["departamento", "pais"], ["ciudad", "departamento"], ["barrio", "ciudad"]].forEach(function (pair) {
      var select = control(form, pair[0]), parent = value(control(form, pair[1]));
      Array.from(select.options).forEach(function (option) {
        var hidden = !!option.value && (!parent || option.dataset[pair[1]] !== parent || (pair[0] === "barrio" && option.dataset.pais !== value(control(form, "pais"))));
        option.hidden = hidden; option.disabled = hidden;
        if (hidden && option.selected) select.value = "";
      });
    });
    var neighborhood = control(form, "barrio").selectedOptions[0];
    control(form, "ruta").value = neighborhood ? neighborhood.dataset.ruta_asignada || "" : "";
    var data = collect(form, definition);
    control(form, "edad_inmueble").value = data.edad_inmueble;
    var reserved = data.modo_ingreso === "adelantado";
    root.querySelector("[data-property-reserved]").hidden = !reserved;
    control(form, "codigo_adelantado").required = reserved;
    control(form, "codigo_adelantado").disabled = !reserved;
    definition.fields.forEach(function (field) {
      var container = root.querySelector('[data-property-field="' + field.name + '"]');
      var visible = (!field.reserved_only || reserved) && field.when.every(function (group) { return matches(group, data); });
      container.hidden = !visible;
      var node = container.querySelector("input,select,textarea");
      node.disabled = !visible || !!form._propertySaved;
      node.required = visible && field.required;
    });
    root.querySelectorAll(".cpe-section").forEach(function (section) { section.hidden = !Array.from(section.querySelectorAll("[data-property-field]")).some(function (field) { return !field.hidden; }); });
    syncChoices(root);
    var media = root.querySelector("[data-media-embedded]");
    if (media && media._media) {
      var label = control(form,"tipo_inmueble").selectedOptions[0];
      var limit = Math.max(label && /^(local|lote|bodega)s?$/i.test(label.textContent.trim()) ? 6 : 12, media._media.baseline_gallery || 0);
      if (media._media.max_gallery !== limit) window.commercialPropertyMedia.setLimit(media,limit);
    }
  }
  window.initCommercialPropertyEntry = function (root) {
    root._definition = JSON.parse(root.dataset.propertyDefinition);
    window.initCommercialPropertyMedia(root.querySelector("[data-media-embedded]"));
    refresh(root);
    step(root,0);
    root.addEventListener("click", function (event) {
      var tab = event.target.closest("[data-entry-tab]"); if (tab) step(root,tab.dataset.entryTab);
      if (event.target.closest("[data-entry-prev]")) step(root,root._step - 1);
      if (event.target.closest("[data-entry-next]")) { if (!invalidField(root,root.querySelector('[data-entry-panel="' + root._step + '"]'))) step(root,root._step + 1); }
      var button = event.target.closest("[data-choice-value]");
      if (button) { var group = button.closest("[data-choice-group]"), select = control(root.querySelector("form"),group.dataset.choiceGroup); if (select.disabled) return; if (select.multiple) Array.from(select.options).forEach(function (option) { if (option.value === button.dataset.choiceValue) option.selected = !option.selected; }); else select.value = button.dataset.choiceValue; select.dispatchEvent(new Event("change",{bubbles:true})); }
      var apply = event.target.closest("[data-apply-suggestions]");
      if (apply) { var choiceGroup = apply.closest("[data-choice-group]"), node = control(root.querySelector("form"),choiceGroup.dataset.choiceGroup), suggestions = root._suggestions[choiceGroup.dataset.choiceGroup] || {}; Array.from(node.options).forEach(function (option) { if (suggestions[option.value]) option.selected = true; }); syncChoices(root); }
    });
  };
  function error(root, text) {
    var node = root.querySelector("[data-property-error]"); node.textContent = text; node.hidden = !text;
    if (text) node.scrollIntoView({ block: "nearest" });
  }
  document.addEventListener("change", function (event) {
    var root = event.target.closest("[data-commercial-property-entry]");
    if (root && root._definition) refresh(root);
    if (root && ["pais","departamento","ciudad","barrio"].indexOf(event.target.name) !== -1) neighborhood(root);
    if (root && event.target.name === "modo_ingreso") { window.commercialPropertyMedia.resetDraft(root.querySelector("[data-media-embedded]")); refresh(root); }
    if (root && event.target.name === "codigo_adelantado") {
      var media = root.querySelector("[data-media-embedded]");
      window.commercialPropertyMedia.resetDraft(media);
      if (Number(event.target.value) > 0) window.commercialPropertyMedia.loadDraft(media,Number(event.target.value));
    }
  });
  document.addEventListener("input", function (event) {
    var root = event.target.closest("[data-commercial-property-entry]");
    if (root && event.target.name === "ano_construccion") refresh(root);
    if (root && event.target.matches("[data-feature-search]")) { var group = event.target.closest("[data-choice-group]"), needle = event.target.value.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase(); group.querySelectorAll("[data-choice-value]").forEach(function (button) { button.hidden = button.dataset.choiceValue.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase().indexOf(needle) === -1; }); group.querySelector("[data-feature-empty]").hidden = Array.from(group.querySelectorAll("[data-choice-value]")).some(function (button) { return !button.hidden; }); }
  });
  document.addEventListener("submit", async function (event) {
    var form = event.target;
    if (!form.matches("[data-property-entry-form]")) return;
    event.preventDefault();
    if (form._saving) return;
    var root = form.closest("[data-commercial-property-entry]");
    refresh(root); error(root, "");
    if (!form._propertySaved && invalidField(root,form)) return;
    var media = root.querySelector("[data-media-embedded]");
    try { window.commercialPropertyMedia.validate(media); } catch (exception) { step(root,4); error(root,exception.message); return; }
    var data = new FormData(form); data.set("action", "commercial_property_entry_save"); data.set("nonce", runtime.nonce || "");
    var controls = Array.from(form.querySelectorAll("input,select,textarea,button")).concat(Array.from(root.querySelectorAll("[data-commercial-return-case]"))), disabled = controls.map(function (node) { return node.disabled; });
    var button = form.querySelector("[data-property-submit]");
    form._saving = true; controls.forEach(function (node) { node.disabled = true; }); button.textContent = "Guardando…";
    try {
      var payload;
      if (!form._propertySaved) {
        var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
        payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || "No se pudo guardar el inmueble.");
        form._propertySaved = payload.data;
      }
      root.querySelector("[data-property-save-status]").textContent = "Datos guardados. Guardando fotos y video…";
      await window.commercialPropertyMedia.save(media,form._propertySaved.id_inmueble);
      payload = {data:form._propertySaved};
      form.hidden = true;
      var result = root.querySelector("[data-property-result]"); result.replaceChildren();
      var text = document.createElement("p"); text.textContent = "Inmueble #" + payload.data.id_inmueble + " guardado con su portada, galería y video. La tarea quedó por publicar."; result.append(text);
      var back = document.createElement("button"); back.type = "button"; back.textContent = "Volver a la tarea"; back.className = "csg-primary"; back.setAttribute("data-commercial-return-case",media._media.ticket_pk); result.append(back);
      result.hidden = false; result.scrollIntoView({ block: "nearest" });
      root.dispatchEvent(new CustomEvent("scm:task-updated", { bubbles: true, detail: { ticketPk: data.get("ticket_pk") } }));
    } catch (exception) { error(root, (form._propertySaved ? "El inmueble #" + form._propertySaved.id_inmueble + " ya tiene sus datos guardados. Reintenta la multimedia sin crear otro registro. " : "") + (exception.message || "No se pudo guardar el inmueble.")); }
    finally { controls.forEach(function (node, index) { node.disabled = form._propertySaved && !media.contains(node) && node.matches("input,select,textarea") ? true : disabled[index]; }); button.textContent = form._propertySaved ? "Reintentar multimedia" : "Guardar inmueble"; form._saving = false; }
  });
})();
