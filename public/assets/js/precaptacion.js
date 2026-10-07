(function () {
  "use strict";
  var body = document.body;
  var createForm = document.getElementById("precap-create-form");
  var currentStep = 0;
  var normalize = function (text) { return text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase(); };
  window.PrecapUI = {
    fire: function (options) {
      return new Promise(function (resolve) {
        var dialog = document.getElementById("precap-notice");
        dialog.querySelector("h2").textContent = options.title || "Confirmar acción";
        dialog.querySelector("[data-precap-notice-text]").textContent = options.text || "";
        dialog.querySelector("[data-precap-notice-cancel]").hidden = !options.showCancelButton;
        dialog.querySelector("[data-precap-notice-confirm]").textContent = options.confirmButtonText || "Aceptar";
        dialog.returnValue = "cancel";
        dialog.addEventListener("close", function done() { dialog.removeEventListener("close", done); resolve({isConfirmed:dialog.returnValue === "confirm"}); });
        dialog.showModal();
      });
    },
    confirm: async function (text) { return (await this.fire({title:"Confirmar acción",text:text,showCancelButton:true,confirmButtonText:"Confirmar"})).isConfirmed; }
  };
  function picker(select) {
    var wrapper = document.createElement("div"); wrapper.className = "precap-picker";
    select.before(wrapper); wrapper.appendChild(select); select.classList.add("precap-native-select"); select.tabIndex = -1;
    var trigger = document.createElement("button"); trigger.type = "button"; trigger.className = "precap-picker-trigger";
    trigger.setAttribute("aria-haspopup", "dialog"); trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-labelledby", "precap-label-" + select.id.replace("precap-", ""));
    var popup = document.createElement("div"); popup.className = "precap-picker-popup"; popup.hidden = true; popup.setAttribute("role", "dialog"); popup.setAttribute("aria-label", "Seleccionar opción"); popup.id = select.id + "-options";
    trigger.setAttribute("aria-controls", popup.id);
    var search = document.createElement("input"); search.type = "search"; search.placeholder = "Buscar por nombre o código…"; search.setAttribute("aria-label", "Buscar opciones");
    var list = document.createElement("div"); list.className = "precap-picker-list";
    popup.append(search, list); wrapper.append(trigger, popup);
    function render() {
      var selected = Array.from(select.selectedOptions).filter(function (option) { return option.value; });
      trigger.textContent = selected.length ? selected.map(function (option) { return option.text; }).join(", ") + " ▾" : "Selecciona una opción ▾";
      trigger.classList.toggle("has-selection", selected.length > 0);
      list.replaceChildren();
      var options = Array.from(select.options).filter(function (option) { return option.value && normalize(option.text).includes(normalize(search.value)); });
      options.slice(0, 100).forEach(function (option) {
        var button = document.createElement("button"); button.type = "button"; button.className = "precap-picker-option";
        button.textContent = (select.multiple ? (option.selected ? "☑ " : "☐ ") : "") + option.text;
        button.setAttribute("aria-pressed", String(option.selected));
        button.addEventListener("click", function () {
          if (select.multiple) option.selected = !option.selected; else select.value = option.value;
          select.dispatchEvent(new Event("change", {bubbles:true}));
          render(); if (!select.multiple) { popup.hidden = true; trigger.setAttribute("aria-expanded", "false"); trigger.focus(); }
        });
        list.appendChild(button);
      });
      if (!options.length) { var empty = document.createElement("p"); empty.textContent = "No hay coincidencias. Prueba otra búsqueda."; list.appendChild(empty); }
      if (options.length > 100) { var hint = document.createElement("p"); hint.textContent = "Hay " + options.length + " opciones. Escribe para afinar la búsqueda."; list.appendChild(hint); }
    }
    trigger.addEventListener("click", function () { var opening = popup.hidden; document.querySelectorAll(".precap-picker-popup").forEach(function (item) { item.hidden = true; item.previousElementSibling.setAttribute("aria-expanded", "false"); }); popup.hidden = !opening; trigger.setAttribute("aria-expanded", String(opening)); search.value = ""; render(); if (opening) search.focus(); });
    search.addEventListener("input", render);
    popup.addEventListener("keydown", function (event) {
      if (event.key === "Enter" && event.target === search) { event.preventDefault(); list.querySelector("button")?.click(); }
      if (event.key === "Escape") { event.preventDefault(); event.stopPropagation(); popup.hidden = true; trigger.setAttribute("aria-expanded", "false"); trigger.focus(); }
      if (["ArrowDown", "ArrowUp"].includes(event.key)) { event.preventDefault(); var items = [search].concat(Array.from(list.querySelectorAll("button"))); var index = items.indexOf(document.activeElement); items[(index + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length].focus(); }
    });
    select.addEventListener("change", render); render();
  }
  document.querySelectorAll("[data-precap-picker]").forEach(picker);
  function validateStep(index) {
    var section = createForm.querySelector('[data-precap-step="' + index + '"]');
    var invalid = Array.from(section.querySelectorAll("input,select,textarea")).find(function (field) { return !field.disabled && !field.checkValidity(); });
    if (!invalid) return true;
    createForm.querySelector("[data-precap-message]").textContent = "Revisa el campo: " + (createForm.querySelector('label[for="' + invalid.id + '"]')?.textContent || "dato obligatorio");
    createForm.querySelector("[data-precap-message]").classList.add("error");
    var target = invalid.matches("[data-precap-picker]") ? invalid.parentElement.querySelector("button") : invalid;
    target.focus(); return false;
  }
  function showStep(index) {
    currentStep = index;
    createForm.querySelectorAll("[data-precap-step]").forEach(function (step) { step.hidden = Number(step.dataset.precapStep) !== index; });
    createForm.querySelectorAll("[data-precap-step-go]").forEach(function (button) { button.toggleAttribute("aria-current", Number(button.dataset.precapStepGo) === index); if (Number(button.dataset.precapStepGo) === index) button.setAttribute("aria-current", "step"); button.classList.toggle("is-complete", Number(button.dataset.precapStepGo) < index); });
    createForm.querySelector("[data-precap-back]").hidden = index === 0;
    createForm.querySelector("[data-precap-next]").hidden = index === 3;
    createForm.querySelector('button[type="submit"]').hidden = index !== 3;
    createForm.querySelector("[data-precap-step-count]").textContent = "Paso " + (index + 1) + " de 4";
    createForm.querySelector("[data-precap-message]").textContent = "";
    var review = createForm.querySelector("[data-precap-review]"); review.replaceChildren();
    if (index === 3) {
      ["tipo_inmueble", "categoria", "barrio", "contacto", "celular"].forEach(function (name) { var field = createForm.elements[name]; if (!field?.value) return; var item = document.createElement("div"); var label = document.createElement("small"); label.textContent = createForm.querySelector('label[for="' + field.id + '"]').textContent; var value = document.createElement("strong"); value.textContent = field.value; item.append(label, value); review.appendChild(item); });
    }
    document.getElementById("precap-create").scrollTop = 0;
  }
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
      if (action === "precaptacion_create") {
        message.textContent = "Comprimiendo fotografías…";
        data.delete("fotos[]");
        var photos = Array.from(form.elements["fotos[]"].files);
        for (var photo of photos) {
          var optimized = await compressPhoto(photo);
          if (optimized.size > 10 * 1024 * 1024) throw new Error("La foto " + photo.name + " sigue superando 10 MB. Guárdala como JPG o selecciona una imagen más pequeña.");
          data.append("fotos[]", optimized, optimized.name);
        }
        message.textContent = "Subiendo fotografías y guardando…";
      }
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
  async function compressPhoto(file) {
    // Keep animation and formats that the browser cannot decode as supplied.
    if (!/^image\/(jpeg|jpg|png|webp|bmp|x-ms-bmp)$/i.test(file.type)) return file;
    var url = URL.createObjectURL(file);
    try {
      var image = new Image(); image.src = url; await image.decode();
      var ratio = Math.min(1, 1920 / Math.max(image.naturalWidth, image.naturalHeight));
      var canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(image.naturalWidth * ratio));
      canvas.height = Math.max(1, Math.round(image.naturalHeight * ratio));
      var context = canvas.getContext("2d", {alpha:false});
      if (!context) return file;
      context.fillStyle = "#fff"; context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, "image/jpeg", 0.82); });
      canvas.width = canvas.height = 0;
      // Never enlarge a file that was already optimized.
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", {type:"image/jpeg",lastModified:file.lastModified});
    } catch (error) { return file; }
    finally { URL.revokeObjectURL(url); }
  }
  document.addEventListener("click", function (event) {
    if (!event.target.closest(".precap-picker")) document.querySelectorAll(".precap-picker-popup").forEach(function (item) { item.hidden = true; item.previousElementSibling.setAttribute("aria-expanded", "false"); });
    if (event.target.closest("[data-precap-next]") && validateStep(currentStep)) showStep(currentStep + 1);
    if (event.target.closest("[data-precap-back]")) showStep(currentStep - 1);
    var stepButton = event.target.closest("[data-precap-step-go]");
    if (stepButton) { var targetStep = Number(stepButton.dataset.precapStepGo); if (targetStep <= currentStep) showStep(targetStep); else { var valid = true; for (var stepIndex = currentStep; stepIndex < targetStep; stepIndex++) { if (!validateStep(stepIndex)) { showStep(stepIndex); validateStep(stepIndex); valid = false; break; } } if (valid) showStep(targetStep); } }
    var collapse = event.target.closest("[data-precap-collapse]");
    if (collapse) {
      var filterForm = collapse.closest("form");
      var expanded = collapse.getAttribute("aria-expanded") === "true";
      collapse.setAttribute("aria-expanded", String(!expanded));
      collapse.textContent = expanded ? "Más filtros ⌄" : "Menos filtros ⌃";
      filterForm.querySelector(".precaptaciones__filtros-grid").hidden = expanded;
    }
    var exportButton = event.target.closest("[data-precap-export]");
    var contactTab = event.target.closest("[data-precap-contact-filter]");
    if (event.target.closest("[data-precaptaciones-clear-filters]")) document.querySelectorAll("[data-precap-contact-filter]").forEach(function (tab) { var active = tab.dataset.precapContactFilter === ""; tab.classList.toggle("is-active", active); tab.setAttribute("aria-pressed", String(active)); });
    if (contactTab) { var contactFilter = document.querySelector('[name="precaptaciones_estado_contacto"]'); contactFilter.value = contactTab.dataset.precapContactFilter; contactFilter.dispatchEvent(new Event("change", {bubbles:true})); }
    if (exportButton) exportCsv(exportButton);
    var open = event.target.closest("[data-precap-open]");
    if (open) {
      var dialog = document.getElementById(open.dataset.precapOpen);
      if (dialog) dialog.showModal();
    }
    var close = event.target.closest("[data-precap-close]");
    if (close) close.closest("dialog").close();
  });
  document.addEventListener("change", function (event) {
    if (event.target.name === "precaptaciones_estado_contacto") document.querySelectorAll("[data-precap-contact-filter]").forEach(function (tab) { var active = tab.dataset.precapContactFilter === event.target.value; tab.classList.toggle("is-active", active); tab.setAttribute("aria-pressed", String(active)); });
    if (!event.target.matches("[data-precap-page-size]")) return;
    var panel = event.target.closest("[data-precaptaciones-panel]");
    panel.dataset.perPage = event.target.value;
    panel.querySelector("[data-precaptaciones-filters]").dispatchEvent(new Event("submit", {bubbles:true,cancelable:true}));
  });
  async function exportCsv(button) {
    var panel = button.closest("[data-precaptaciones-panel]");
    var data = new FormData(panel.querySelector("[data-precaptaciones-filters]"));
    data.set("action", "precaptaciones_exportar");
    data.set("nonce", body.dataset.precapNonce);
    data.set("mode", panel.dataset.mode);
    var status = panel.querySelector("[data-precaptaciones-status]");
    button.disabled = true;
    try {
      var response = await fetch(body.dataset.precapApi, {method:"POST",body:data,credentials:"same-origin"});
      if (!response.ok || !response.headers.get("content-type").includes("text/csv")) throw new Error("No se pudo exportar el listado. Recarga la página e inténtalo de nuevo.");
      var url = URL.createObjectURL(await response.blob());
      var link = document.createElement("a");
      link.href = url; link.download = "precaptaciones.csv";
      document.body.appendChild(link); link.click(); link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      status.textContent = "";
    } catch (error) { status.textContent = error.message; status.classList.add("is-error"); }
    finally { button.disabled = false; }
  }
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
      if (currentStep < 3) { if (validateStep(currentStep)) showStep(currentStep + 1); return; }
      for (var index = 0; index < 4; index++) { if (!validateStep(index)) { showStep(index); validateStep(index); return; } }
      var photos = createForm.elements["fotos[]"].files;
      if (photos.length > 2 || Array.from(photos).some(function (photo) { return photo.size > 30 * 1024 * 1024; })) {
        var message = createForm.querySelector("[data-precap-message]");
        message.classList.add("error");
        message.textContent = "Adjunta una o dos fotografías de hasta 30 MB cada una. Las comprimiremos antes de subirlas.";
        return;
      }
      var result = await send(createForm, "precaptacion_create");
      if (result) {
        createForm.reset();
        createForm.elements["fotos[]"].dispatchEvent(new Event("change"));
        conditionalFields();
        createForm.querySelectorAll("[data-precap-picker]").forEach(function (select) { select.dispatchEvent(new Event("change")); });
        showStep(0);
        document.getElementById("precap-create").close();
        // Refresh the original panel through its existing filter handler.
        var filters = document.querySelector("[data-precaptaciones-filters]");
        if (filters) filters.dispatchEvent(new Event("submit", {bubbles:true,cancelable:true}));
        else window.location.reload();
      }
    });
    conditionalFields();
    createForm.elements["fotos[]"].addEventListener("change", function () {
      var preview = createForm.querySelector("[data-precap-photo-preview]");
      preview.querySelectorAll("img").forEach(function (img) { URL.revokeObjectURL(img.src); }); preview.replaceChildren();
      Array.from(this.files).slice(0, 2).forEach(function (file) { var item = document.createElement("div"); var image = document.createElement("img"); image.src = URL.createObjectURL(file); image.alt = file.name; var name = document.createElement("span"); name.textContent = file.name; item.append(image, name); preview.appendChild(item); });
    });
  }
  document.querySelectorAll("[data-precap-catalog]").forEach(function (form) {
    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      var kind = form.dataset.precapCatalog;
      var result = await send(form, "precaptacion_catalog_create", {kind:kind});
      if (!result || !createForm) return;
      var target = kind === "barrio" ? createForm.elements.barrio : createForm.elements["competencia[]"];
      if (target) {
        var option = Array.from(target.options).find(function (item) { return item.value === result.option.value; });
        if (!option) { option = new Option(result.option.label, result.option.value); target.appendChild(option); }
        if (kind === "barrio") target.value = result.option.value; else option.selected = true;
        target.dispatchEvent(new Event("change", {bubbles:true}));
      }
      createForm.querySelector("[data-precap-message]").textContent = result.message;
      form.closest("dialog").close();
    });
  });
  document.addEventListener('submit', async function (event) {
    var form = event.target.closest('[data-precap-process-form]');
    if (!form) return;
    event.preventDefault();
    var button = form.querySelector('[type="submit"]');
    var message = form.querySelector('[data-process-message]');
    button.disabled = true;
    try {
      var response = await fetch(body.dataset.precapApi, {method:'POST',credentials:'same-origin',body:new FormData(form)});
      var payload = await response.json();
      if (!response.ok || !payload.success) throw new Error(payload.data?.message || 'No se pudo actualizar el proceso.');
      await window.PrecapUI.fire({icon:'success',title:'Proceso actualizado',text:payload.data.message});
      var modal = form.closest('.precaptaciones-precap-modal');
      modal.hidden = true; document.body.classList.remove('precaptaciones-precap-modal-open');
      document.querySelector('[data-precaptaciones-filters]').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    } catch (error) { message.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();
