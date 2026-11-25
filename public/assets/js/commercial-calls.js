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
    stopDictation();
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
    // Registrar llamada: primero se pregunta si tiene código; con código se traen sus datos, sin código se llenan a mano.
    var codeBox = form.querySelector("[data-ccl-code-field]");
    if (codeBox && form.elements.tiene_codigo) {
      var hasCode = offer && value(form, "tiene_codigo") === "Si";
      form.elements.tiene_codigo.required = offer;
      form.elements.id_inmueble.required = hasCode;
      codeBox.hidden = !hasCode;
      // Tipo, destinación y demás salen cuando ya respondió si tiene código (sí o no); en otros motivos, de una vez.
      var details = form.querySelector("[data-ccl-property-fields]");
      if (details) details.hidden = offer && value(form, "tiene_codigo") === "";
      if (!hasCode && form.elements.id_inmueble.value) {
        form.elements.id_inmueble.value = "";
        var result = form.querySelector("[data-ccl-property-result]");
        if (result) result.hidden = true;
      }
    }
    // Primero se pregunta si cuenta con presupuesto: con Sí se pide el valor; con No queda en la descripción que falta.
    var budgetBox = form.querySelector("[data-ccl-budget-field]");
    if (budgetBox && form.elements.tiene_presupuesto) {
      var hasBudget = offer && value(form, "tiene_presupuesto") === "Si";
      form.elements.tiene_presupuesto.required = offer;
      form.elements.presupuesto.required = hasBudget;
      budgetBox.hidden = !hasBudget;
      if (!hasBudget) form.elements.presupuesto.value = "";
    }
  }
  var PROPERTY_LABELS = { tipo_inmueble: "tipo", destinacion: "destinación", uso: "uso" };

  // Llenado inteligente: dictado con el reconocimiento de voz del navegador y la IA del servidor para completar el formulario.
  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  var listening = null;
  // Primero lo que cambia qué campos se ven (motivo, código, presupuesto), luego el resto.
  var SMART_ORDER = ["tema_ayuda", "tiene_codigo", "id_inmueble", "tiene_presupuesto", "presupuesto", "precio", "medio", "indicativo", "celular", "cliente", "email", "celular_wsp", "tipo_inmueble", "destinacion", "uso", "descripcion", "comisionista", "cliente_inmediato", "merece_ticket"];

  function smartStatus(box, text, state) {
    var status = box.querySelector("[data-ccl-smart-status]");
    if (!status) return;
    status.textContent = text;
    status.dataset.state = state || "";
  }
  function stopDictation() {
    if (listening) listening.stop();
  }
  function toggleDictation(button) {
    var box = button.closest("[data-ccl-smart]");
    var area = box.querySelector("[data-ccl-smart-text]");
    if (listening) { stopDictation(); return; }
    if (!Recognition) {
      smartStatus(box, "Este navegador no permite dictar. Usa Chrome o Edge, o escribe o pega el texto.", "error");
      return;
    }
    var recognition = new Recognition();
    recognition.lang = "es-CO";
    recognition.continuous = true;
    recognition.interimResults = true;
    var base = area.value.trim();
    recognition.onresult = function (event) {
      var finalText = "";
      var interim = "";
      for (var i = 0; i < event.results.length; i++) {
        if (event.results[i].isFinal) finalText += event.results[i][0].transcript + " ";
        else interim += event.results[i][0].transcript;
      }
      area.value = (base ? base + " " : "") + finalText.trim();
      smartStatus(box, interim ? "Escuchando: " + interim : "Escuchando… habla con normalidad.", "");
    };
    recognition.onerror = function (event) {
      var denied = event.error === "not-allowed" || event.error === "service-not-allowed";
      smartStatus(box, denied ? "Permite el uso del micrófono en el navegador para dictar." : "No se pudo dictar (" + event.error + "). Intenta de nuevo o escribe el texto.", "error");
    };
    recognition.onend = function () {
      listening = null;
      button.setAttribute("aria-pressed", "false");
      button.classList.remove("is-recording");
      button.querySelector("[data-ccl-dictate-label]").textContent = "Dictar";
      if (box.querySelector("[data-ccl-smart-status]").dataset.state !== "error") smartStatus(box, area.value.trim() ? "Listo. Revisa el texto y pulsa Completar formulario." : "", "");
    };
    try {
      recognition.start();
    } catch (_error) {
      smartStatus(box, "No se pudo iniciar el micrófono. Intenta de nuevo.", "error");
      return;
    }
    listening = recognition;
    button.setAttribute("aria-pressed", "true");
    button.classList.add("is-recording");
    button.querySelector("[data-ccl-dictate-label]").textContent = "Detener";
    smartStatus(box, "Escuchando… habla con normalidad.", "");
  }
  function setField(form, name, val) {
    if (name === "factores_importantes") {
      form.querySelectorAll('[name="factores_importantes[]"]').forEach(function (box) { box.checked = val.indexOf(box.value) >= 0; });
      return true;
    }
    var field = form.elements[name];
    if (!field || field.readOnly || field.disabled) return false;
    field.value = val;
    if (field.value !== val) return false;
    if (field.matches && field.matches("[data-ccl-money]")) formatMoney(field);
    var label = field.closest(".ccl-field");
    if (label) {
      label.classList.add("ccl-smart-filled");
      window.setTimeout(function () { label.classList.remove("ccl-smart-filled"); }, 2400);
    }
    return true;
  }
  function smartFill(button) {
    var box = button.closest("[data-ccl-smart]");
    var form = button.closest("form");
    var text = box.querySelector("[data-ccl-smart-text]").value.trim();
    stopDictation();
    if (text.length < 15) { smartStatus(box, "Dicta o escribe un poco más de lo que dijo el cliente.", "error"); return; }
    button.disabled = true;
    smartStatus(box, "Leyendo lo que dijo el cliente…", "");
    if (window.SCMLoader) window.SCMLoader.show({ icon: "ai", title: "Completando el formulario", detail: "La IA está leyendo lo que dijo el cliente." });
    request("commercial_calls_smart_fill", { texto: text }).then(function (result) {
      var fields = result.campos || {};
      var touched = false;
      SMART_ORDER.concat(Object.keys(fields)).forEach(function (name, index, all) {
        if (all.indexOf(name) !== index || fields[name] === undefined) return;
        if (setField(form, name, fields[name])) touched = true;
        syncForm();
      });
      if (fields.celular) lookupContact(form);
      if (fields.id_inmueble && form.elements.id_inmueble) lookupProperty(form.elements.id_inmueble);
      var message = touched ? "Se llenó: " + (result.llenados || []).join(", ") + "." : "No se encontraron datos para llenar.";
      if ((result.faltantes || []).length) message += " Falta: " + result.faltantes.join(", ") + ".";
      if (result.aviso) message += " " + result.aviso;
      smartStatus(box, message + " Revisa todo antes de guardar.", result.aviso ? "warn" : "ok");
    }).catch(function (error) {
      smartStatus(box, error.message, "error");
    }).finally(function () {
      button.disabled = false;
      if (window.SCMLoader) window.SCMLoader.hide();
    });
  }
  function lookupProperty(field) {
    var form = field.form;
    var box = form.querySelector("[data-ccl-property-result]");
    var code = field.value.replace(/\D+/g, "");
    if (!box) return;
    if (!code) { box.hidden = true; return; }
    box.hidden = false;
    box.dataset.state = "";
    box.textContent = "Buscando el inmueble " + code + "…";
    request("commercial_calls_property", { codigo: code, tema: value(form, "tema_ayuda") }).then(function (result) {
      if (field.value.replace(/\D+/g, "") !== code) return;
      var filled = [];
      Object.keys(result.campos || {}).forEach(function (name) {
        var input = form.elements[name];
        if (!input) return;
        input.value = result.campos[name];
        if (input.value === result.campos[name]) filled.push(PROPERTY_LABELS[name] || name);
      });
      box.textContent = result.resumen + (filled.length ? ". Se llenó " + filled.join(", ") + "; revisa y completa lo que falte." : ". No trae datos para llenar: complétalos a mano.");
      box.dataset.state = "ok";
    }).catch(function (error) {
      box.textContent = error.message;
      box.dataset.state = "error";
    });
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
    var smartButton = event.target.closest("[data-ccl-dictate],[data-ccl-smart-fill]");
    if (smartButton) {
      if (smartButton.hasAttribute("data-ccl-dictate")) toggleDictation(smartButton);
      else smartFill(smartButton);
      return;
    }
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
    if (event.target.matches("[data-ccl-property-code]")) lookupProperty(event.target);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && modal() && modal().classList.contains("open") && !document.querySelector(".swal2-container")) closeModal();
  });
})();
