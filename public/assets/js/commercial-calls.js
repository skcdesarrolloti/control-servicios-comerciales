(function () {
  "use strict";
  // Actividades comerciales › Llamadas. El panel reemplaza su contenido al navegar: todo se atiende por delegación.
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var lastFocus = null;
  var FILTERS = ["ll_q", "ll_medio", "ll_tema", "ll_estado", "ll_comisionista", "ll_inmediato", "ll_mes", "ll_empleado", "ll_desde", "ll_hasta", "ll_tarjeta"];

  function notify(type, message) {
    if (window.Swal) window.Swal.fire({ toast: true, position: "top-end", timer: 3200, showConfirmButton: false, icon: type, title: message });
  }
  async function request(action, data) {
    var body = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
    body.set("action", action);
    body.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: body, credentials: "same-origin" });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("No se pudo procesar la respuesta del servidor."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }
  function modal() { return document.getElementById("commercial-call-modal"); }
  function content() { return document.querySelector("[data-ccl-modal]"); }
  function show(html) {
    var node = modal();
    if (!node) return;
    content().innerHTML = html;
    if (!node.classList.contains("open")) {
      lastFocus = document.activeElement;
      node.classList.add("open");
      document.body.classList.add("commercial-modal-open");
    }
    var first = content().querySelector("input:not([type=hidden]):not([readonly]), select, textarea, button");
    if (first) first.focus();
    syncForm();
  }
  function closeModal() {
    var node = modal();
    if (!node) return;
    node.classList.remove("open");
    content().innerHTML = "";
    document.body.classList.remove("commercial-modal-open");
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  }
  function fail(error) {
    var box = content() && content().querySelector("[data-ccl-error]");
    if (box && modal().classList.contains("open")) {
      box.textContent = error.message;
      box.hidden = false;
      box.scrollIntoView({ block: "nearest" });
    } else {
      notify("error", error.message);
    }
  }
  function open(action, data) {
    return request(action, data).then(function (result) { show(result.html || ""); }).catch(fail);
  }

  function filtersForm() { return document.querySelector("[data-ccl-filters]"); }
  function params(page) {
    var form = filtersForm();
    var values = {};
    if (form) FILTERS.forEach(function (name) { if (form.elements[name]) values[name] = form.elements[name].value; });
    values.ll_pag = String(page || 1);
    return values;
  }
  function reflectUrl(values) {
    var url = new URL(window.location.href);
    FILTERS.concat("ll_pag").forEach(function (name) {
      if (values[name] && !(name === "ll_pag" && values[name] === "1")) url.searchParams.set(name, values[name]);
      else url.searchParams.delete(name);
    });
    window.history.replaceState(window.history.state, "", url);
  }
  function refresh(page) {
    var holder = document.querySelector("[data-ccl-results]");
    if (!holder) return Promise.resolve();
    var values = params(page);
    holder.setAttribute("aria-busy", "true");
    return request("commercial_calls_list", values).then(function (result) {
      holder.innerHTML = result.html || "";
      reflectUrl(values);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { holder.removeAttribute("aria-busy"); });
  }

  function value(form, name) { var field = form.elements[name]; return field ? field.value : ""; }
  // Código y presupuesto son de arriendo y venta; el precio, de captación y recaptación.
  function syncTopic(form) {
    var topic = value(form, "tema_ayuda");
    var offer = ["Arriendo", "Venta", "Arriendo o venta"].indexOf(topic) >= 0;
    var visible = { codigo: offer, presupuesto: offer, factores: offer, precio: topic === "Captacion" || topic === "Recaptacion" };
    form.querySelectorAll("[data-ccl-topic-field]").forEach(function (node) { node.hidden = !visible[node.getAttribute("data-ccl-topic-field")]; });
  }
  function syncForm() {
    var form = content() && content().querySelector("[data-ccl-form]");
    if (form) {
      syncTopic(form);
      var box = form.querySelector("[data-ccl-pph]");
      if (box && form.elements.medio) box.hidden = form.elements.medio.value !== form.getAttribute("data-pph-medio");
      var month = form.querySelector("[data-ccl-month]");
      if (month) {
        month.hidden = value(form, "cliente_inmediato") !== "No";
        form.elements.mes_inmueble.required = !month.hidden;
      }
    }
    var task = content() && content().querySelector("[data-ccl-task-form]");
    if (task) syncTopic(task);
  }
  function formatMoney(field) {
    var right = field.value.slice(field.selectionStart).replace(/\D/g, "").length;
    var digits = field.value.replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 15);
    var formatted = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    field.value = formatted;
    var position = formatted.length;
    for (var seen = 0; position > 0 && seen < right; position--) {
      if (/\d/.test(formatted[position - 1])) seen++;
    }
    field.setSelectionRange(position, position);
  }
  function lookupContact(form) {
    var phone = value(form, "celular").replace(/\D+/g, "");
    var box = form.querySelector("[data-ccl-contact]");
    if (!box || value(form, "id") !== "0" || phone.length < 7) { if (box) box.hidden = true; return; }
    if (form._lastPhone === phone) return;
    form._lastPhone = phone;
    request("commercial_calls_history", { celular: phone }).then(function (result) {
      if (!result.total) { box.hidden = true; return; }
      box.replaceChildren();
      var text = document.createElement("span");
      text.textContent = "Este número ya tiene " + result.total + (result.total === 1 ? " solicitud registrada." : " solicitudes registradas.") + " Esta se guardará como una solicitud nueva, con su propio medio.";
      box.append(text);
      var contact = result.contact || {};
      if (contact.cliente) {
        var use = document.createElement("button");
        use.type = "button"; use.className = "ccl-link"; use.textContent = "Usar sus datos";
        use.addEventListener("click", function () {
          ["cliente", "email", "indicativo", "celular_wsp", "usuario_wsp"].forEach(function (name) { if (form.elements[name] && contact[name]) form.elements[name].value = contact[name]; });
        });
        box.append(use);
      }
      var see = document.createElement("button");
      see.type = "button"; see.className = "ccl-link"; see.textContent = "Ver historial";
      see.addEventListener("click", function () { show(result.html); });
      box.append(see);
      box.hidden = false;
    }).catch(function () { box.hidden = true; });
  }
  function lookupCard(form) {
    var field = form.elements.tarjeta_pph;
    var result = form.querySelector("[data-ccl-card-result]");
    if (!field || !result || !field.value.trim()) return;
    request("commercial_calls_card", { tarjeta: field.value }).then(function (partner) {
      result.textContent = "Aliado: " + partner.nombre;
      result.dataset.state = "ok";
    }).catch(function (error) {
      result.textContent = error.message;
      result.dataset.state = "error";
    });
  }
  async function submit(form, action, done) {
    var button = form.querySelector('[type="submit"]');
    if (form._saving) return;
    var invalid = form.querySelector(":invalid");
    if (invalid) { invalid.reportValidity(); return; }
    var errorBox = form.querySelector("[data-ccl-error]");
    if (errorBox) errorBox.hidden = true;
    form._saving = true;
    button.disabled = true;
    try {
      done(await request(action, new FormData(form)));
    } catch (error) {
      fail(error);
    } finally {
      form._saving = false;
      button.disabled = false;
    }
  }
  function openTask(id) {
    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.hidden = true;
    trigger.setAttribute("data-commercial-open-case", String(id));
    document.querySelector("[data-ccl-page]").appendChild(trigger);
    trigger.click();
    trigger.remove();
  }

  document.addEventListener("click", function (event) {
    var target = event.target.closest("[data-ccl-new],[data-ccl-edit],[data-ccl-repeat],[data-ccl-history],[data-ccl-task],[data-ccl-close],[data-ccl-state],[data-ccl-toggle],[data-ccl-page-to],[data-ccl-clear]");
    if (!target) {
      // Abrir una tarea desde el historial la muestra en su propio modal.
      if (event.target.closest("#commercial-call-modal [data-commercial-open-case]")) closeModal();
      return;
    }
    if (target.hasAttribute("data-ccl-close")) return closeModal();
    if (target.hasAttribute("data-ccl-new")) return void open("commercial_calls_form", {});
    if (target.hasAttribute("data-ccl-edit")) return void open("commercial_calls_form", { id: target.getAttribute("data-ccl-edit") });
    if (target.hasAttribute("data-ccl-repeat")) return void open("commercial_calls_form", { desde_llamada: target.getAttribute("data-ccl-repeat") });
    if (target.hasAttribute("data-ccl-history")) return void open("commercial_calls_history", { id: target.getAttribute("data-ccl-history") });
    if (target.hasAttribute("data-ccl-task")) return void open("commercial_calls_task_form", { id: target.getAttribute("data-ccl-task") });
    if (target.hasAttribute("data-ccl-state")) {
      var form = filtersForm();
      if (form) { form.elements.ll_estado.value = target.getAttribute("data-ccl-state"); refresh(1); }
      return;
    }
    if (target.hasAttribute("data-ccl-toggle")) {
      var select = filtersForm().elements[target.getAttribute("data-ccl-toggle")];
      var wanted = target.getAttribute("data-value");
      select.value = select.value === wanted ? "" : wanted;
      refresh(1);
      return;
    }
    if (target.hasAttribute("data-ccl-page-to")) return void refresh(Number(target.getAttribute("data-ccl-page-to")));
    if (target.hasAttribute("data-ccl-clear")) {
      filtersForm().reset();
      FILTERS.forEach(function (name) { var field = filtersForm().elements[name]; if (field) field.value = ""; });
      refresh(1);
    }
  });
  document.addEventListener("submit", function (event) {
    if (event.target.matches("[data-ccl-filters]")) { event.preventDefault(); refresh(1); return; }
    if (event.target.matches("[data-ccl-form]")) {
      event.preventDefault();
      submit(event.target, "commercial_calls_save", function (result) {
        notify("success", result.message);
        refresh(1);
        if (result.next === "task") open("commercial_calls_task_form", { id: result.id });
        else closeModal();
      });
      return;
    }
    if (event.target.matches("[data-ccl-task-form]")) {
      event.preventDefault();
      submit(event.target, "commercial_calls_task_save", function (result) {
        notify("success", result.message);
        closeModal();
        refresh(1);
        if (result.open) openTask(result.id);
      });
    }
  });
  document.addEventListener("input", function (event) {
    if (event.target.matches("[data-ccl-money]")) formatMoney(event.target);
  });
  document.addEventListener("change", function (event) {
    if (!event.target.closest("[data-ccl-modal]")) return;
    syncForm();
    var form = event.target.form;
    if (!form) return;
    if (event.target.name === "celular") lookupContact(form);
    if (event.target.name === "tarjeta_pph") lookupCard(form);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && modal() && modal().classList.contains("open") && !document.querySelector(".swal2-container")) closeModal();
  });
})();
