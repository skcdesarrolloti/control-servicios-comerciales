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
      node.disabled = !visible;
      node.required = visible && field.required;
    });
    root.querySelectorAll(".cpe-section").forEach(function (section) { section.hidden = !Array.from(section.querySelectorAll("[data-property-field]")).some(function (field) { return !field.hidden; }); });
  }
  window.initCommercialPropertyEntry = function (root) {
    root._definition = JSON.parse(root.dataset.propertyDefinition);
    refresh(root);
  };
  function error(root, text) {
    var node = root.querySelector("[data-property-error]"); node.textContent = text; node.hidden = !text;
    if (text) node.scrollIntoView({ block: "nearest" });
  }
  document.addEventListener("change", function (event) {
    var root = event.target.closest("[data-commercial-property-entry]");
    if (root && root._definition) refresh(root);
  });
  document.addEventListener("input", function (event) {
    var root = event.target.closest("[data-commercial-property-entry]");
    if (root && event.target.name === "ano_construccion") refresh(root);
  });
  document.addEventListener("submit", async function (event) {
    var form = event.target;
    if (!form.matches("[data-property-entry-form]")) return;
    event.preventDefault();
    if (form._saving) return;
    var root = form.closest("[data-commercial-property-entry]");
    refresh(root); error(root, "");
    var invalid = Array.from(form.elements).find(function (node) { return node.willValidate && !node.validity.valid; });
    if (invalid) { var details = invalid.closest("details"); if (details) details.open = true; invalid.reportValidity(); invalid.focus(); return; }
    var data = new FormData(form); data.set("action", "commercial_property_entry_save"); data.set("nonce", runtime.nonce || "");
    var controls = Array.from(form.querySelectorAll("input,select,textarea,button")).concat(Array.from(root.querySelectorAll("[data-commercial-return-case]"))), disabled = controls.map(function (node) { return node.disabled; });
    var button = form.querySelector("[data-property-submit]");
    form._saving = true; controls.forEach(function (node) { node.disabled = true; }); button.textContent = "Guardando…";
    try {
      var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
      var payload = await response.json();
      if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || "No se pudo guardar el inmueble.");
      form.hidden = true;
      var result = root.querySelector("[data-property-result]"); result.replaceChildren();
      var text = document.createElement("p"); text.textContent = payload.data.message; result.append(text);
      var photos = document.createElement("a"); photos.textContent = "Continuar con las fotografías del inmueble"; photos.href = payload.data.photos_url; photos.target = "_blank"; photos.rel = "noopener noreferrer"; photos.className = "csg-secondary"; result.append(photos);
      result.hidden = false; result.scrollIntoView({ block: "nearest" });
      root.dispatchEvent(new CustomEvent("scm:task-updated", { bubbles: true, detail: { ticketPk: data.get("ticket_pk") } }));
    } catch (exception) { error(root, exception.message || "No se pudo guardar el inmueble."); }
    finally { controls.forEach(function (node, index) { node.disabled = disabled[index]; }); button.textContent = "Guardar inmueble"; form._saving = false; }
  });
})();
