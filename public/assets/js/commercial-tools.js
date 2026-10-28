(function () {
  "use strict";
  // Herramientas comerciales: cupo preaprobado del propietario (con PDF para el propietario) y calculadora de
  // ganancia ocasional. Los paneles llegan por AJAX, por eso todos los eventos se delegan en el documento.
  var runtime = {};
  var app = document.getElementById("scm-app");
  if (!app) return;
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });
  var DAY = 86400000;

  function money(value) { return "$" + moneyFormat.format(Math.round(Number(value) || 0)); }
  function digits(value) { return Number(String(value || "").replace(/[^\d]/g, "")) || 0; }
  function todayIso() { var d = new Date(); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
  function isoDate(text) { return /^\d{4}-\d{2}-\d{2}$/.test(text || "") ? new Date(text + "T00:00:00") : null; }

  async function request(action, data) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("El servidor devolvió una respuesta no válida."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }

  // Pesos colombianos: $1.250.000 (sin decimales). Al escribir se conserva el cursor contando dígitos.
  function formatMoney(input, keepCaret) {
    var raw = String(input.value || "");
    var caret = keepCaret && typeof input.selectionStart === "number" ? input.selectionStart : raw.length;
    var digitsBefore = raw.slice(0, caret).replace(/[^\d]/g, "").length;
    var clean = raw.replace(/[^\d]/g, "").replace(/^0+(?=\d)/, "").slice(0, 13);
    var formatted = clean === "" ? "" : "$" + moneyFormat.format(Number(clean));
    if (formatted === raw) return;
    input.value = formatted;
    if (!keepCaret) return;
    var position = 0;
    for (var seen = 0; position < formatted.length && seen < digitsBefore; position++) {
      if (/\d/.test(formatted.charAt(position))) seen++;
    }
    if (digitsBefore === 0) position = formatted.charAt(0) === "$" ? 1 : 0;
    input.setSelectionRange(position, position);
  }

  /* ------------------------------------------------------------------ Cupo preaprobado del propietario */

  // Ingreso neto mensual de un inmueble: canon − comisión − garantía − (seguro + intereses) / 12 − otros.
  function sourceValues(card) {
    var value = function (name) { var input = card.querySelector('[data-pre-field="' + name + '"]'); return input ? digits(input.value) : 0; };
    var item = {
      canon: value("canon"), comision: value("comision"), garantia: value("garantia"), seguro: value("seguro"), intereses: value("intereses"), otros: value("otros"),
      fin: (card.querySelector('[data-pre-field="fin"]') || {}).value || "",
      meta: card.querySelector("[data-pre-source-meta]").textContent, fromContract: card.dataset.contract === "1",
    };
    item.seguroMes = (item.seguro + item.intereses) / 12;
    item.net = Math.max(0, item.canon - item.comision - item.garantia - item.seguroMes - item.otros);
    return item;
  }

  function preState(root) {
    var months = Number(root.dataset.incomeMonths) || 3;
    var margin = Number(root.dataset.termMargin) || 0;
    var sources = Array.from(root.querySelectorAll("[data-pre-sources] [data-pre-source]")).map(sourceValues);
    var net = sources.reduce(function (sum, item) { return sum + item.net; }, 0);
    // Plazo: meses completos de 30 días hasta el fin del contrato más largo, menos el margen del liquidador.
    var longest = sources.map(function (item) { return item.fin; }).filter(Boolean).sort().pop() || "";
    var end = isoDate(longest);
    var term = end ? Math.floor((end - isoDate(todayIso())) / DAY / 30) - margin : null;
    var owner = {};
    root.querySelectorAll("[data-pre-owner]").forEach(function (input) { owner[input.dataset.preOwner] = input.value.trim(); });
    return { months: months, margin: margin, sources: sources, net: net, amount: net * months, longest: longest, term: term, owner: owner, registered: root.dataset.mode !== "manual" };
  }

  function recalc(root) {
    root.querySelectorAll("[data-pre-sources] [data-pre-source]").forEach(function (card) {
      var item = sourceValues(card);
      card.querySelector("[data-pre-source-net]").textContent = money(item.net);
      card.querySelector("[data-pre-source-detail]").textContent = item.seguroMes > 0 ? "Seguro" + (item.intereses > 0 ? " e intereses" : "") + ": " + money(item.seguroMes) + " / mes" : "";
    });
    var state = preState(root);
    root.querySelector("[data-pre-total]").textContent = money(state.amount);
    root.querySelector("[data-pre-formula]").textContent = "Ingreso neto " + money(state.net) + " / mes × " + state.months + " meses";
    root.querySelector("[data-pre-net]").textContent = money(state.net);
    root.querySelector("[data-pre-count]").textContent = String(state.sources.length);
    root.querySelector("[data-pre-term]").textContent = state.term === null ? "Según vigencia" : state.term > 0 ? "Hasta " + state.term + " cuotas" : "Sin plazo disponible";
    root.querySelector("[data-pre-empty]").hidden = state.sources.length > 0;
    return state;
  }

  function addSource(root, data) {
    var template = root.querySelector("[data-pre-source-template]");
    var card = template.content.firstElementChild.cloneNode(true);
    var list = root.querySelector("[data-pre-sources]");
    data = data || {};
    ["canon", "comision", "garantia", "seguro", "intereses", "otros"].forEach(function (name) {
      var input = card.querySelector('[data-pre-field="' + name + '"]');
      input.value = Number(data[name]) > 0 ? money(data[name]) : "";
    });
    card.querySelector('[data-pre-field="fin"]').value = data.fin || "";
    card.dataset.contract = data.contrato ? "1" : "";
    card.querySelector("[data-pre-source-meta]").textContent = data.contrato ? ["Contrato " + data.contrato, data.inmueble ? "SIMI " + data.inmueble : "", data.direccion, data.estado].filter(Boolean).join(" · ") : "Valores escritos por el consultor";
    list.append(card);
    renumber(root);
    return card;
  }

  function renumber(root) {
    root.querySelectorAll("[data-pre-sources] [data-pre-source]").forEach(function (card, index) {
      card.querySelector("[data-pre-source-title]").textContent = "Inmueble " + (index + 1);
    });
  }

  function clearSources(root) {
    root.querySelectorAll("[data-pre-sources] [data-pre-source]").forEach(function (card) { card.remove(); });
  }

  function setStatus(root, text, error) {
    var node = root.querySelector("[data-pre-status]");
    node.textContent = text || "";
    node.dataset.error = error ? "true" : "false";
  }

  function showError(root, text) {
    var node = root.querySelector("[data-pre-error]");
    node.textContent = text || "";
    node.hidden = !text;
  }

  function setMode(root, mode) {
    root.dataset.mode = mode;
    root.querySelectorAll("[data-pre-mode]").forEach(function (button) { button.setAttribute("aria-pressed", button.dataset.preMode === mode ? "true" : "false"); });
    root.querySelector("[data-pre-search]").hidden = mode === "manual";
    root.querySelector("[data-pre-credits]").hidden = true;
    root.querySelectorAll("[data-pre-owner]").forEach(function (input) { input.value = ""; });
    setStatus(root, "");
    showError(root, "");
    clearSources(root);
    if (mode === "manual") {
      addSource(root);
      root.querySelector('[data-pre-owner="name"]').focus();
    } else {
      root.querySelector("[data-pre-document]").focus();
    }
    recalc(root);
  }

  async function search(root) {
    var input = root.querySelector("[data-pre-document]");
    var button = root.querySelector("[data-pre-search-button]");
    var query = input.value.trim();
    showError(root, "");
    if (query.replace(/[\s.]/g, "").length < 4) { setStatus(root, "Escribe la cédula o el NIT (mínimo 4 caracteres).", true); input.focus(); return; }
    if (button.disabled) return;
    button.disabled = true;
    setStatus(root, "Buscando contratos…");
    try {
      var data = new FormData();
      data.set("document", query);
      var result = await request("commercial_tools_preapproval_lookup", data);
      clearSources(root);
      var credits = root.querySelector("[data-pre-credits]");
      credits.hidden = !(result.active_credits > 0);
      credits.textContent = result.active_credits > 0 ? "Tiene " + result.active_credits + (result.active_credits === 1 ? " crédito aprobado vigente" : " créditos aprobados vigentes") + " en el liquidador: el cupo disponible será menor, porque el analista descuenta su saldo." : "";
      var contracts = result.contracts || [];
      if (!contracts.length || !result.owner) {
        root.querySelector('[data-pre-owner="document"]').value = query;
        setStatus(root, "No encontramos contratos Entregado o Por entregar de este documento. Si aún no está registrado, usa \"Aún no registrado\" o agrega un inmueble.", true);
        recalc(root);
        return;
      }
      ["name", "document", "phone", "email"].forEach(function (key) { root.querySelector('[data-pre-owner="' + key + '"]').value = result.owner[key] || ""; });
      contracts.forEach(function (contract) { addSource(root, contract); });
      setStatus(root, result.owner.name + (contracts.length === 1 ? " es propietario en 1 contrato vigente." : " es propietario en " + contracts.length + " contratos vigentes: todos suman al cupo."));
      recalc(root);
    } catch (error) {
      setStatus(root, error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  /* --------------------------------------------------------------------------- PDF para el propietario */

  // El servidor recalcula el cupo y genera el PDF con el diseño de las actas (Chromium); aquí solo se descarga.
  async function downloadPdf(root, button) {
    var state = recalc(root);
    showError(root, "");
    if (!state.sources.length) { showError(root, "Agrega al menos un inmueble con su canon."); return; }
    if (!(state.net > 0)) { showError(root, "El ingreso neto es $0: revisa el canon y los descuentos."); return; }
    if (!state.owner.name) { showError(root, "Escribe el nombre del propietario: aparece en el PDF."); root.querySelector('[data-pre-owner="name"]').focus(); return; }
    if (button.disabled) return;
    var label = button.innerHTML;
    button.disabled = true;
    button.textContent = "Generando PDF…";
    try {
      var data = new FormData();
      data.set("action", "commercial_tools_preapproval_pdf");
      data.set("nonce", runtime.nonce || "");
      data.set("payload", JSON.stringify({
        owner: state.owner, registered: state.registered,
        sources: state.sources.map(function (item) { return { canon: item.canon, comision: item.comision, garantia: item.garantia, seguro: item.seguro, intereses: item.intereses, otros: item.otros, fin: item.fin, meta: item.fromContract ? item.meta : "" }; }),
      }));
      var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
      if (!response.ok || (response.headers.get("Content-Type") || "").indexOf("application/pdf") !== 0) {
        var payload = null;
        try { payload = await response.json(); } catch (_error) {}
        throw new Error((payload && payload.data && payload.data.message) || "No se pudo generar el PDF.");
      }
      var match = /filename="([^"]+)"/.exec(response.headers.get("Content-Disposition") || "");
      var url = URL.createObjectURL(await response.blob());
      var link = document.createElement("a");
      link.href = url;
      link.download = match ? match[1] : "cupo-preaprobado.pdf";
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
    } catch (error) {
      showError(root, error.message || "No se pudo generar el PDF.");
    } finally {
      button.disabled = false;
      button.innerHTML = label;
    }
  }

  /* ------------------------------------------------------------------ Calculadora de ganancia ocasional */

  function goValue(root, name) { var input = root.querySelector('[data-go-field="' + name + '"]'); return input ? (input.type === "checkbox" ? input.checked : input.value) : ""; }
  function goOut(root, name, value) { var node = root.querySelector('[data-go-out="' + name + '"]'); if (node) node.textContent = value; return node; }

  function prepareRent(root) {
    var pj = goValue(root, "tipo") === "pj";
    root.querySelector('[data-go-field="tasa"]').value = pj ? 35 : 0;
    root.querySelector("[data-go-rate-help]").textContent = pj ? "PJ: tarifa corporativa sugerida 35 % (editable)." : "PN: escribe la tasa efectiva estimada.";
  }

  function calculateGain(root) {
    var uvt = digits(goValue(root, "uvt"));
    var buy = isoDate(goValue(root, "compra"));
    var sell = isoDate(goValue(root, "venta"));
    var price = digits(goValue(root, "precioVenta"));
    var cost = digits(goValue(root, "costoAdq")) + digits(goValue(root, "mejoras")) + digits(goValue(root, "valorizaciones"));
    var gross = price - cost - digits(goValue(root, "gastosVenta"));
    var profit = Math.max(0, gross);
    var years = buy && sell && sell >= buy ? (sell - buy) / (365.25 * DAY) : null;
    var ordinary = goValue(root, "giro") || !(years !== null && years >= 2);
    var cap = root.querySelector("[data-go-cap]");
    if (cap) cap.textContent = money(5000 * uvt);
    root.querySelector("[data-go-rent]").hidden = !ordinary;
    root.querySelector('[data-go-row="exencion"]').hidden = ordinary;
    root.querySelector('[data-go-row="gravable"]').hidden = ordinary;
    root.querySelector('[data-go-row="renta"]').hidden = !ordinary;
    var badge = root.querySelector('[data-go-out="clasificacion"]');
    if (!(price > 0)) {
      goOut(root, "impuesto", "$0");
      badge.textContent = "Escribe el precio de venta";
      badge.dataset.kind = "";
      ["anios", "utilidad", "exencion", "gravable", "renta", "tarifa"].forEach(function (name) { goOut(root, name, "—"); });
      root.querySelector('[data-go-out="alerta"]').hidden = true;
      return;
    }
    var exemption = 0, base, rate;
    if (ordinary) {
      base = profit;
      rate = Math.max(0, Math.min(99, Number(String(goValue(root, "tasa")).replace(",", ".")) || 0));
    } else {
      if (goValue(root, "vivienda") && goValue(root, "exencion")) exemption = Math.min(profit, 5000 * uvt);
      base = Math.max(0, profit - exemption);
      rate = 15;
    }
    badge.textContent = ordinary ? "Renta ordinaria" : "Ganancia ocasional";
    badge.dataset.kind = ordinary ? "renta" : "go";
    goOut(root, "impuesto", money(base * rate / 100));
    goOut(root, "anios", years === null ? "—" : years.toFixed(2).replace(".", ",") + " años");
    goOut(root, "utilidad", money(profit));
    goOut(root, "exencion", money(exemption));
    goOut(root, "gravable", money(ordinary ? 0 : base));
    goOut(root, "renta", money(ordinary ? base : 0));
    goOut(root, "tarifa", rate ? String(rate).replace(".", ",") + " %" : "—");
    var notes = [];
    if (!buy || !sell) notes.push("Escribe las fechas de compra y venta para evaluar la posesión de 2 años.");
    else if (sell < buy) notes.push("La fecha de venta es anterior a la de compra.");
    if (gross <= 0) notes.push("No hay utilidad (o hay pérdida): no se causa impuesto.");
    if (goValue(root, "giro")) notes.push("Al ser giro ordinario, la utilidad es renta ordinaria aunque tenga 2 años o más.");
    if (ordinary && rate === 0 && profit > 0) notes.push("Escribe la tasa estimada de renta para calcular el impuesto.");
    if (!ordinary && goValue(root, "exencion") && !goValue(root, "vivienda")) notes.push("La exención del art. 311-1 solo aplica a la vivienda de habitación del vendedor.");
    var alert = root.querySelector('[data-go-out="alerta"]');
    alert.textContent = notes.join(" ");
    alert.hidden = !notes.length;
  }

  function resetGain(root) {
    root.querySelectorAll("[data-go-field]").forEach(function (input) {
      if (input.type === "checkbox") input.checked = false;
      else if (input.dataset.goField === "tipo") input.value = "pn";
      else if (input.dataset.goField !== "uvt") input.value = "";
    });
    prepareRent(root);
    calculateGain(root);
  }

  /* ------------------------------------------------------------------------------------------ Eventos */

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    var pre = target.closest('[data-commercial-tool="preaprobado"]');
    if (pre) {
      var mode = target.closest("[data-pre-mode]");
      if (mode) { if ((pre.dataset.mode || "registrado") !== mode.dataset.preMode) setMode(pre, mode.dataset.preMode); return; }
      if (target.closest("[data-pre-search-button]")) { search(pre); return; }
      if (target.closest("[data-pre-add]")) { addSource(pre).querySelector('[data-pre-field="canon"]').focus(); recalc(pre); return; }
      var remove = target.closest("[data-pre-remove]");
      if (remove) { remove.closest("[data-pre-source]").remove(); renumber(pre); recalc(pre); return; }
      var pdf = target.closest("[data-pre-pdf]");
      if (pdf) downloadPdf(pre, pdf);
      return;
    }
    var gain = target.closest('[data-commercial-tool="ganancia_ocasional"]');
    if (!gain) return;
    var dialog = gain.querySelector("[data-go-dialog]");
    if (target.closest("[data-go-guide]")) { dialog.showModal(); return; }
    if (target.closest("[data-go-close]") || target === dialog) { dialog.close(); return; }
    if (target.closest("[data-go-reset]")) resetGain(gain);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" || !(event.target instanceof Element) || !event.target.matches("[data-pre-document]")) return;
    event.preventDefault();
    search(event.target.closest('[data-commercial-tool="preaprobado"]'));
  });

  document.addEventListener("input", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var root = target && target.closest("[data-commercial-tool]");
    if (!root) return;
    if (target.matches("[data-tool-money]")) formatMoney(target, true);
    if (root.dataset.commercialTool === "preaprobado") { showError(root, ""); recalc(root); }
    else calculateGain(root);
  });

  document.addEventListener("change", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var root = target && target.closest('[data-commercial-tool="ganancia_ocasional"]');
    if (!root) return;
    if (target.matches('[data-go-field="tipo"]')) prepareRent(root);
    calculateGain(root);
  });
})();
