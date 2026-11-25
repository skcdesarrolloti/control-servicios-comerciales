(function () {
  "use strict";
  // Formulario del contrato de mandato (CommercialMandateFormView): pasos, campos condicionales, propietarios (cantidad,
  // "¿Ya está registrado?", país e indicativo, % de participación), copropiedad, inmueble por código, acuerdos y los
  // cálculos. Copia de formularios/crear-mandato/js (canon_comision, garantia, garantia_servicios, calculo_prestamo,
  // clausula_penal, calculo_berkley, calculo_seguros_mundial, acuerdos, generador_propietarios): mantener iguales.
  // Lo inicia window.initCommercialMandateForm(form); guardar lo hace commercial-mandates.js.
  var app = document.getElementById("scm-app");
  var runtime = {};
  try { runtime = JSON.parse((app && app.getAttribute("data-scm-runtime")) || "{}"); } catch (_error) {}

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

  // ── Números (como utils.js y cada cálculo del formulario de WordPress) ──
  var nf = new Intl.NumberFormat("es-CO");
  function fmt(value) { return Number.isFinite(value) ? nf.format(value) : ""; }
  function digits(value) { return parseFloat(String(value || "").replace(/[^\d]/g, "")) || 0; }
  function money(value) { return parseFloat(String(value || "").replace(/\./g, "").replace(",", ".")) || 0; }
  function rate(value) { return parseFloat(String(value == null ? "" : value).replace(",", ".")) || 0; }
  function round2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
  // Préstamo de cuota fija con tasa_prestamo_garantias_servicios (calculo_prestamo.js; sin tasa, sin intereses).
  function loan(principal, installments, monthlyPct) {
    var i = monthlyPct / 100;
    var factor = i > 0 ? (i * Math.pow(1 + i, installments)) / (Math.pow(1 + i, installments) - 1) : 1 / installments;
    var cuota = principal * factor;
    return { cuota: cuota, total: cuota * installments };
  }

  function init(form) {
    if (!form || form.getAttribute("data-cmf-ready") === "1") return;
    form.setAttribute("data-cmf-ready", "1");
    var params = {};
    try { params = JSON.parse(form.getAttribute("data-cmf-params") || "{}"); } catch (_error) {}
    var steps = Array.prototype.slice.call(form.querySelectorAll("[data-cmf-step]"));
    var current = 0;
    var error = form.querySelector("[data-cmf-error]");

    function field(name) { return form.elements[name] || null; }
    function value(name) { var el = field(name); return el && el.value != null ? String(el.value) : ""; }
    function key(text) { return String(text == null ? "" : text).normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase(); }
    function set(name, text) {
      var el = field(name);
      if (!el || el instanceof RadioNodeList) return;
      var wanted = text == null ? "" : String(text);
      el.value = wanted;
      // En las listas, el valor guardado puede venir con otras mayúsculas o tildes ("Estados unidos").
      if (el.tagName === "SELECT" && wanted !== "" && el.value !== wanted) {
        var match = Array.prototype.find.call(el.options, function (option) { return option.value !== "" && key(option.value) === key(wanted); });
        el.value = match ? match.value : "";
      }
    }

    // ── Condicionales: un bloque [data-cmf-when] se ve si su campo tiene uno de sus valores; lo oculto no se valida
    // ni se envía (como toggleVisibilityUniversal de utils.js).
    function refresh() {
      form.querySelectorAll("[data-cmf-when]").forEach(function (block) {
        var values = (block.getAttribute("data-cmf-values") || "").split("|");
        block.hidden = values.indexOf(value(block.getAttribute("data-cmf-when"))) < 0;
      });
      var count = parseInt(value("cantidad_propietarios"), 10) || 1;
      form.querySelectorAll("[data-cmf-owner]").forEach(function (owner) {
        owner.hidden = parseInt(owner.getAttribute("data-cmf-owner"), 10) > count;
      });
      ["anual", "mensual"].forEach(function (kind) {
        var select = form.querySelector('[data-cmf-agreement-count="' + kind + '"]');
        var total = select ? parseInt(select.value, 10) || 0 : 0;
        form.querySelectorAll('[data-cmf-agreement="' + kind + '"]').forEach(function (row) {
          row.hidden = parseInt(row.getAttribute("data-index"), 10) > total;
        });
      });
      Array.prototype.forEach.call(form.elements, function (control) {
        if (!control.name || control.type === "hidden") return;
        control.disabled = !!control.closest("[data-cmf-when][hidden], [data-cmf-owner][hidden], [data-cmf-agreement][hidden]");
      });
      var single = count === 1;
      var first = field("porcentaje_1");
      if (first) { first.readOnly = single; if (single) first.value = "100"; }
    }

    // Partes iguales al cambiar la cantidad (participacion.js), si no se han escrito.
    function splitPercentages() {
      var count = parseInt(value("cantidad_propietarios"), 10) || 1;
      if (count === 1) return;
      var written = false;
      for (var i = 1; i <= count; i++) if (value("porcentaje_" + i) !== "" && !(count > 1 && i === 1 && value("porcentaje_1") === "100")) written = true;
      if (written) return;
      var part = Math.floor((100 / count) * 100) / 100;
      for (var k = 1; k <= count; k++) set("porcentaje_" + k, String(k === count ? round2(100 - part * (count - 1)) : part).replace(".", ","));
    }

    // ── Cálculos ──
    function calculate() {
      var iva = rate(params.iva) || 19;
      var canon = digits(value("precio_canon"));
      var admin = digits(value("precio_administracion"));
      var includesIva = value("tiene_iva_canon") === "Si";
      var total = Math.round(canon + admin);
      var tasaComision = parseFloat(value("tasa_comision").replace(",", ".").replace(/[^\d.]/g, "")) || 0;
      var comision = Math.round(total * (tasaComision / 100));
      var ivaComision = Math.round(comision * (iva / 100));
      var ivaCanon = includesIva ? Math.round(canon * (iva / 100)) : 0;
      set("precio_canon_total", total ? fmt(total) : "");
      set("iva_canon", includesIva ? fmt(ivaCanon) : "");
      set("comision_sin_iva", fmt(comision));
      set("iva_comision", fmt(ivaComision));
      set("total_comision", fmt(comision + ivaComision));

      // Garantía inmobiliaria (garantia.js): tasa de la aseguradora sobre canon + admin (+ IVA del canon).
      var insurer = value("aseguradora");
      var seguro = insurer && params.tasas_seguro ? params.tasas_seguro[insurer] : undefined;
      if (insurer) {
        set("tasa_garantia", seguro == null ? "" : String(seguro));
        set("valor_garantia", fmt(Math.round((includesIva ? canon + admin + ivaCanon : canon + admin) * (rate(seguro) / 100))));
      }

      // Garantía de servicios (garantia_servicios.js): hasta 4 cuotas se divide; más de 4 es préstamo.
      var servicios = insurer && params.tasas_servicios ? params.tasas_servicios[insurer] : undefined;
      if (insurer) set("tasa_garantia_servicios", servicios == null ? "" : String(servicios));
      var asegurado = money(value("valor_asegurado_garantia_servicios"));
      var cuotas = parseInt(value("forma_pago_garantia_servicios"), 10) || 0;
      if (asegurado && cuotas > 0) {
        var prima = asegurado * (rate(servicios) / 100);
        if (cuotas <= 4) {
          set("prima_mes_garantia_servicios", fmt(prima / cuotas));
          set("prima_anual_garantia_servicios", fmt(prima));
        } else {
          var credito = loan(prima, cuotas, rate(params.tasa_prestamo));
          set("prima_mes_garantia_servicios", fmt(credito.cuota));
          set("prima_anual_garantia_servicios", fmt(credito.total));
        }
      }

      // Cláusula penal (clausula_penal.js): (canon total + IVA) × 2 × tasa.
      var penal = rate(params.tasa_clausula_penal);
      set("tasa_clausula_penal", penal ? String(params.tasa_clausula_penal) : "");
      if (total && penal) {
        var amparo = (total + ivaCanon) * 2 * (penal / 100);
        set("amparo_clausula_penal", fmt(amparo));
        set("precio_clausula_penal", fmt(amparo));
      }

      // Berkley (calculo_berkley.js): canon total × 12 × tasa, más servicios y expedición, en 1 a 4 cuotas.
      var berkley = rate(params.tasa_berkley);
      var expedicionBerkley = Number(params.expedicion_berkley) || 0;
      set("tasa_berkley", berkley ? String(params.tasa_berkley) : "");
      set("expedicion_berkley", expedicionBerkley ? fmt(expedicionBerkley) : "");
      if (total && berkley) {
        var primaCanonB = total * 12 * (berkley / 100);
        set("canon_mes_berkley", fmt(total));
        set("canon_anual_berkley", fmt(total * 12));
        set("prima_canon_berkley", fmt(primaCanonB));
        var formaB = parseInt(value("forma_pago_berkley"), 10) || 0;
        if (formaB > 0 && formaB <= 4) {
          var primaServicios = money(value("valor_asegurado_berkley")) * (berkley / 100);
          var anualB = primaCanonB + primaServicios + expedicionBerkley;
          set("prima_servicios_berkley", fmt(primaServicios));
          set("prima_mes_berkley", fmt(anualB / formaB));
          set("prima_anual_berkley", fmt(anualB));
        } else {
          set("prima_mes_berkley", "");
          set("prima_anual_berkley", "");
        }
      }

      // Seguros mundial (calculo_seguros_mundial.js): (canon total + IVA) × 12 × tasa + expedición + IVA, como préstamo.
      var mundial = rate(params.tasa_seguros_mundial);
      var expedicionMundial = Number(params.expedicion_seguros_mundial) || 0;
      set("tasa_seguros_mundial", mundial ? String(params.tasa_seguros_mundial) : "");
      set("expedicion_seguros_mundial", expedicionMundial ? fmt(expedicionMundial) : "");
      if (total && mundial) {
        var anualM = (total + ivaCanon) * 12;
        var primaCanonM = anualM * (mundial / 100);
        set("canon_mes_seguros_mundial", fmt(round2(total)));
        set("canon_anual_seguros_mundial", fmt(round2(anualM)));
        set("prima_canon_seguros_mundial", fmt(round2(primaCanonM)));
        var sinIva = primaCanonM + expedicionMundial;
        var ivaPrima = round2(sinIva * (iva / 100));
        var primaM = round2(sinIva + ivaPrima);
        var creditoM = loan(primaM, parseInt(value("forma_pago_seguros_mundial"), 10) || 1, rate(params.tasa_prestamo));
        set("iva_prima_seguros_mundial", fmt(ivaPrima));
        set("total_prima_seguros_mundial", fmt(creditoM.total));
        set("prima_mes_seguros_mundial", fmt(creditoM.cuota));
      }
    }

    // ── Pasos ──
    var list = form.querySelector("[data-cmf-steps]");
    steps.forEach(function (step, index) {
      var item = document.createElement("li");
      var button = document.createElement("button");
      button.type = "button";
      button.textContent = (index + 1) + ". " + (step.getAttribute("data-title") || "");
      button.addEventListener("click", function () { if (index <= current || validStep(current)) go(index); });
      item.appendChild(button);
      list.appendChild(item);
    });
    function go(index) {
      current = Math.max(0, Math.min(steps.length - 1, index));
      steps.forEach(function (step, i) { step.hidden = i !== current; });
      list.querySelectorAll("button").forEach(function (button, i) { button.setAttribute("aria-current", i === current ? "step" : "false"); });
      form.querySelector("[data-cmf-prev]").hidden = current === 0;
      form.querySelector("[data-cmf-next]").hidden = current === steps.length - 1;
      form.querySelector("[data-cmf-submit]").hidden = current !== steps.length - 1;
      showError("");
      var heading = steps[current].querySelector("h2");
      if (heading) { heading.setAttribute("tabindex", "-1"); heading.focus({ preventScroll: false }); }
    }
    function showError(message) {
      if (!error) return;
      error.textContent = message || "";
      error.hidden = !message;
    }
    // Campos obligatorios que se ven en un paso (lo oculto no cuenta).
    function validStep(index) {
      var entities = steps[index].querySelectorAll("[data-cmf-entity]");
      for (var e = 0; e < entities.length; e++) {
        var entity = entities[e];
        if (entity.closest("[hidden]")) continue;
        var who = entity.getAttribute("data-cmf-entity") === "copropiedad" ? "la copropiedad" : "el propietario #" + entity.getAttribute("data-cmf-entity");
        var mode = modeOf(entity);
        if (!mode) { showError("Indica si " + who + " ya está registrado."); entity.querySelector("[data-cmf-mode]").focus(); return false; }
        if (mode === "si" && !entity.querySelector("[data-cmf-entity-id]").value) {
          showError("Busca y elige " + who + " (o marca que es nuevo).");
          entity.querySelector("[data-cmf-search-input]").focus();
          return false;
        }
      }
      var controls = steps[index].querySelectorAll("input, select, textarea");
      for (var i = 0; i < controls.length; i++) {
        var control = controls[i];
        if (control.disabled || control.type === "hidden" || !control.required) continue;
        if (String(control.value || "").trim() === "" || (control.type === "email" && control.value && !control.checkValidity())) {
          var label = control.closest("label");
          var text = label && label.querySelector("span") ? label.querySelector("span").textContent.replace("*", "").trim() : control.name;
          showError("Completa: " + text + ".");
          control.focus();
          return false;
        }
      }
      if (steps[index].querySelector("[data-cmf-owner]")) {
        var count = parseInt(value("cantidad_propietarios"), 10) || 1;
        if (count > 1) {
          var sum = 0;
          for (var k = 1; k <= count; k++) sum += parseFloat(value("porcentaje_" + k).replace(",", ".")) || 0;
          if (Math.abs(sum - 100) > 0.001) { showError("Los porcentajes de participación deben sumar 100 % (ahora suman " + round2(sum) + " %)."); field("porcentaje_1").focus(); return false; }
        }
      }
      showError("");
      return true;
    }
    form.validAll = function () {
      for (var i = 0; i < steps.length; i++) {
        if (!validStep(i)) { go(i); validStep(i); return false; }
      }
      return true;
    };

    // ── "¿Ya está registrado?" (propietarios y copropiedad), como formularios/comun/js/propietario_registrado.js ──
    // "Sí": buscador; al elegir se cargan los datos de su ficha (si se cambia alguno, también se actualiza en la ficha).
    // "No": datos en blanco y se registra al guardar; si el documento ya existe, avisa y ofrece usar ese propietario.
    var timers = {};
    // Lo que no es de la ficha y no se borra al cambiar de propietario o copropiedad.
    var KEEP = /^(calidad|porcentaje)_\d+$|^responsable_admin$/;
    function entityOf(index) { return form.querySelector('[data-cmf-entity="' + (index || "copropiedad") + '"]'); }
    function modeOf(entity) { var checked = entity.querySelector("[data-cmf-mode]:checked"); return checked ? checked.value : ""; }
    function dataControls(entity) {
      return Array.prototype.filter.call(entity.querySelectorAll("[data-cmf-entity-data] input, [data-cmf-entity-data] select, [data-cmf-entity-data] textarea"), function (control) {
        return control.name && control.type !== "radio" && control.type !== "checkbox" && !KEEP.test(control.name);
      });
    }
    function clearEntity(entity) {
      entity.querySelector("[data-cmf-entity-id]").value = "";
      dataControls(entity).forEach(function (control) { control.value = ""; });
      var index = entity.getAttribute("data-cmf-entity");
      if (index !== "copropiedad") { set("indicativo_" + index, ""); syncCountry(index); }
    }
    function syncCountry(index) {
      var select = field("pais_" + index);
      if (!select) return;
      if (!select.value) {
        var colombia = Array.prototype.find.call(select.options, function (option) { return key(option.value) === "colombia"; });
        if (colombia) select.value = colombia.value;
      }
      var option = select.selectedOptions[0];
      if (option && !/^\+\d+$/.test(value("indicativo_" + index))) set("indicativo_" + index, option.getAttribute("data-codigo") || "");
    }
    // Muestra u oculta buscador, registro elegido y datos según la opción (y el registro elegido).
    function applyMode(entity) {
      var mode = modeOf(entity);
      var id = entity.querySelector("[data-cmf-entity-id]").value;
      entity.querySelector("[data-cmf-search-box]").hidden = mode !== "si";
      entity.querySelector("[data-cmf-picked]").hidden = !(mode === "si" && id);
      entity.querySelector("[data-cmf-entity-data]").hidden = !(mode === "no" || (mode === "si" && id));
      var duplicate = entity.querySelector("[data-cmf-duplicate]");
      if (duplicate && mode !== "no") duplicate.hidden = true;
    }
    function pick(entity, item) {
      var index = entity.getAttribute("data-cmf-entity");
      clearEntity(entity);
      entity.querySelector('[data-cmf-mode][value="si"]').checked = true;
      fill(item.campos, index === "copropiedad" ? "" : index);
      if (index !== "copropiedad") syncCountry(index);
      entity.querySelector("[data-cmf-picked-label]").textContent = item.label;
      applyMode(entity);
      showError("");
    }
    // Tras cargar el inmueble por su código: el propietario #1 y la copropiedad que trae quedan como "Sí" (registrados).
    function syncEntities() {
      form.querySelectorAll("[data-cmf-entity]").forEach(function (entity) {
        var index = entity.getAttribute("data-cmf-entity");
        var id = entity.querySelector("[data-cmf-entity-id]").value;
        if (id) {
          entity.querySelector('[data-cmf-mode][value="si"]').checked = true;
          var label = index === "copropiedad"
            ? value("nombre_copropiedad") + (value("nit_copropiedad") ? " · NIT " + value("nit_copropiedad") : "")
            : value("nombre_" + index) + (value("documento_" + index) ? " · " + value("documento_" + index) : "");
          entity.querySelector("[data-cmf-picked-label]").textContent = label;
        }
        if (index !== "copropiedad") syncCountry(index);
        applyMode(entity);
      });
    }
    function fill(values, index) {
      Object.keys(values || {}).forEach(function (name) {
        var target = index ? name.replace(/_0$/, "_" + index) : name;
        if (field(target) && values[name] !== "") set(target, values[name]);
      });
      refresh();
      calculate();
    }
    // Igual que normalizar_doc() de formularios/comun/propietarios.php.
    function normalizeDocument(text) { return String(text || "").trim().replace(/[\s.,]+/g, "").toUpperCase().replace(/-\d$/, "").replace(/-/g, ""); }
    function checkDuplicate(input) {
      var index = input.getAttribute("data-cmf-document");
      var entity = entityOf(index);
      var box = entity.querySelector("[data-cmf-duplicate]");
      var wanted = normalizeDocument(input.value);
      window.clearTimeout(timers["doc" + index]);
      box.hidden = true;
      if (modeOf(entity) !== "no" || wanted.length < 4) return;
      timers["doc" + index] = window.setTimeout(function () {
        request("commercial_mandate_search", { tipo: "propietario", q: input.value.trim() }).then(function (data) {
          var found = (data.items || []).find(function (item) { return normalizeDocument(item.campos && item.campos.documento_0) === wanted; });
          if (!found || modeOf(entity) !== "no" || normalizeDocument(input.value) !== wanted) return;
          box.textContent = "Ya hay un propietario registrado con este documento: " + (found.campos.nombre_0 || found.label) + ". Si sigues como nuevo, el contrato quedará con ese propietario igual. ";
          var use = document.createElement("button");
          use.type = "button";
          use.className = "cmf-link";
          use.textContent = "Usar ese propietario";
          use.addEventListener("click", function () { pick(entity, found); });
          box.appendChild(use);
          box.hidden = false;
        }).catch(function () {});
      }, 400);
    }
    form.addEventListener("input", function (event) {
      var target = event.target;
      if (target.matches("[data-cmf-search-input]")) {
        var box = target.closest("[data-cmf-search]");
        var results = box.querySelector("[data-cmf-search-results]");
        var timer = box.getAttribute("data-cmf-search") + (box.getAttribute("data-index") || "");
        window.clearTimeout(timers[timer]);
        if (target.value.trim().length < 3) { results.innerHTML = ""; return; }
        timers[timer] = window.setTimeout(function () {
          request("commercial_mandate_search", { tipo: box.getAttribute("data-cmf-search"), q: target.value.trim() }).then(function (data) {
            results.innerHTML = "";
            (data.items || []).forEach(function (item) {
              var li = document.createElement("li");
              var button = document.createElement("button");
              button.type = "button";
              button.className = "cst-result";
              button.textContent = item.label;
              button.addEventListener("click", function () {
                pick(box.closest("[data-cmf-entity]"), item);
                results.innerHTML = "";
                target.value = "";
              });
              li.appendChild(button);
              results.appendChild(li);
            });
            if (!results.children.length) results.innerHTML = '<li class="cqt-muted">Sin resultados. Si no está registrado, marca "No, es nuevo".</li>';
          }).catch(function (err) { results.innerHTML = '<li class="cqt-muted">' + String(err.message).replace(/</g, "&lt;") + "</li>"; });
        }, 300);
        return;
      }
      if (target.matches("[data-cmf-document]")) checkDuplicate(target);
      if (target.matches("[data-cmf-money]")) {
        var raw = target.value.replace(/[^\d]/g, "");
        target.value = raw ? nf.format(parseInt(raw, 10)) : "";
      }
      calculate();
    });
    form.addEventListener("change", function (event) {
      var target = event.target;
      if (target.matches("[data-cmf-mode]")) {
        var entity = target.closest("[data-cmf-entity]");
        // Si traía uno registrado y ahora es nuevo, es otra persona: se vacían sus datos.
        if (target.value === "no" && entity.querySelector("[data-cmf-entity-id]").value) clearEntity(entity);
        applyMode(entity);
        showError("");
        if (target.value === "si") { var search = entity.querySelector("[data-cmf-search-input]"); if (search) search.focus(); }
        return;
      }
      if (target.matches("[data-cmf-country]")) {
        var option = target.selectedOptions[0];
        set("indicativo_" + target.getAttribute("data-cmf-country"), option ? option.getAttribute("data-codigo") || "" : "");
      }
      if (target.matches("[data-cmf-owner-count]")) splitPercentages();
      if (target.matches("[data-cmf-property-code]") && target.value.trim()) {
        request("commercial_mandate_property", { codigo: target.value.trim() }).then(function (data) { fill(data.campos); syncEntities(); showError(""); }).catch(function (err) { showError(err.message); });
      }
      refresh();
      calculate();
    });
    form.querySelector("[data-cmf-prev]").addEventListener("click", function () { go(current - 1); });
    form.querySelector("[data-cmf-next]").addEventListener("click", function () { if (validStep(current)) go(current + 1); });

    form.querySelectorAll("[data-cmf-money]").forEach(function (input) {
      var raw = String(input.value || "").replace(/,\d*$/, "").replace(/[^\d]/g, "");
      input.value = raw ? nf.format(parseInt(raw, 10)) : "";
    });
    refresh();
    syncEntities();
    calculate();
    go(0);
  }

  window.initCommercialMandateForm = init;
})();
