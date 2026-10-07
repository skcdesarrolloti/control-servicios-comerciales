(function () {
  "use strict";
  var body = document.body;
  var createForm = document.getElementById("precap-create-form");
  function conditionalFields() {
    if (!createForm) return;
    var origin = createForm.elements.origen.value;
    var promoter = createForm.elements["promocionado_por[]"] || createForm.elements.promocionado_por;
    var values = promoter && promoter.selectedOptions ? Array.from(promoter.selectedOptions).map(function (option) { return option.value; }) : [promoter ? promoter.value : ""];
    var agency = values.some(function (value) { return value.toLowerCase().includes("mobiliaria"); });
    [["id_pph", origin === "Club PPH"], ["competencia", agency]].forEach(function (entry) {
      var container = createForm.querySelector('[data-precap-conditional="' + entry[0] + '"]');
      if (!container) return;
      container.hidden = !entry[1];
      container.querySelectorAll("input,select").forEach(function (field) {
        field.disabled = !entry[1];
        field.required = entry[1] && !field.matches('[type="search"]');
      });
    });
  }
  async function send(form, action, extra) {
    var button = form.querySelector('button[type="submit"]');
    var message = form.querySelector("[data-precap-message]");
    var data = new FormData(form);
    data.set("action", action);
    data.set("nonce", body.dataset.precapNonce);
    Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
    button.disabled = true;
    message.classList.remove("error");
    message.textContent = "Guardando…";
    try {
      var response = await fetch(body.dataset.precapApi, {method:"POST", body:data, credentials:"same-origin"});
      var result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.data && result.data.message || "No se pudo guardar.");
      message.textContent = result.data.message;
      return result.data;
    } catch (error) {
      message.classList.add("error");
      message.textContent = error.message;
      return null;
    } finally { button.disabled = false; }
  }
  document.addEventListener("click", function (event) {
    var open = event.target.closest("[data-precap-open]");
    if (open) {
      var dialog = document.getElementById(open.dataset.precapOpen);
      if (dialog) dialog.showModal();
    }
    var close = event.target.closest("[data-precap-close]");
    if (close) close.closest("dialog").close();
  });
  document.addEventListener("input", function (event) {
    var search = event.target.closest("[data-precap-search]");
    if (!search) return;
    var select = document.getElementById(search.dataset.precapSearch);
    if (select) Array.from(select.options).forEach(function (option) { option.hidden = !option.selected && !option.text.toLowerCase().includes(search.value.toLowerCase()); });
  });
  if (createForm) {
    createForm.addEventListener("change", conditionalFields);
    createForm.addEventListener("submit", async function (event) {
      event.preventDefault();
      var photos = createForm.elements["fotos[]"].files;
      if (photos.length > 2 || Array.from(photos).some(function (photo) { return photo.size > 10 * 1024 * 1024; })) {
        var message = createForm.querySelector("[data-precap-message]");
        message.classList.add("error");
        message.textContent = "Adjunta una o dos fotografías de hasta 10 MB cada una.";
        return;
      }
      var result = await send(createForm, "precaptacion_create");
      if (result) {
        createForm.reset();
        conditionalFields();
        document.getElementById("precap-create").close();
        // Refresh the original panel through its existing filter handler.
        var filters = document.querySelector("[data-precaptaciones-filters]");
        if (filters) filters.dispatchEvent(new Event("submit", {bubbles:true,cancelable:true}));
        else window.location.reload();
      }
    });
    conditionalFields();
  }
  document.querySelectorAll("[data-precap-catalog]").forEach(function (form) {
    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      var kind = form.dataset.precapCatalog;
      var result = await send(form, "precaptacion_catalog_create", {kind:kind});
      if (!result || !createForm) return;
      var target = kind === "barrio" ? createForm.elements.barrio : createForm.elements["competencia[]"];
      if (kind === "barrio") {
        var list = document.getElementById("precap-barrios");
        if (!Array.from(list.options).some(function (option) { return option.value === result.option.value; })) list.appendChild(new Option(result.option.label, result.option.value));
        target.value = result.option.value;
      } else if (target) {
        var option = Array.from(target.options).find(function (item) { return item.value === result.option.value; });
        if (!option) { option = new Option(result.option.label, result.option.value); target.appendChild(option); }
        option.selected = true;
      }
      createForm.querySelector("[data-precap-message]").textContent = result.message;
      form.closest("dialog").close();
    });
  });
})();
