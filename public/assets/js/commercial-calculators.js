(function () {
  "use strict";
  // Calculadoras de mercado y de aseo, las mismas del panel inmobiliario (control-servicios-inmobiliarios,
  // admin-tools.js) con el diseño de las herramientas comerciales. Los paneles llegan por AJAX: los eventos se
  // delegan en el documento y las opciones del mercado se cargan cuando aparece su panel.
  var runtime = {};
  var app = document.getElementById("scm-app");
  if (!app) return;
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var money = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var number = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 2 });

  function escapeHtml(value) { return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]; }); }

  function send(action, data) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    return fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
  }

  function post(action, data) {
    return send(action, data || new FormData()).then(function (response) {
      return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); }).then(function (payload) {
        if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
        return payload.data || {};
      });
    });
  }

  function setStatus(node, text, error) {
    node.textContent = text || "";
    node.dataset.error = error ? "true" : "false";
  }

  /* ------------------------------------------------------------------------------ Calculadora de mercado */
  // El servidor filtra la tabla de inmuebles y devuelve los valores por m²; la estadística se calcula aquí para poder
  // excluir atípicos sin volver a consultar. El listado de inmuebles solo llega con la vista completa.

  var MARKET_MODES = [
    { key: "venta", mode: "Venta", title: "Análisis de ventas", tone: "sale" },
    { key: "arriendo", mode: "Arriendo", title: "Análisis de arriendos", tone: "rent" },
  ];
  var MIN_SAMPLE = 5;
  var markets = new WeakMap();

  function marketStats(values) {
    var sorted = values.slice().sort(function (a, b) { return a - b; });
    var n = sorted.length;
    var mean = sorted.reduce(function (a, b) { return a + b; }, 0) / n;
    var std = Math.sqrt(sorted.reduce(function (a, b) { return a + Math.pow(b - mean, 2); }, 0) / n);
    function quantile(q) {
      var pos = (n - 1) * q;
      var base = Math.floor(pos);
      var next = sorted[base + 1] === undefined ? sorted[base] : sorted[base + 1];
      return sorted[base] + (pos - base) * (next - sorted[base]);
    }
    return { n: n, mean: mean, std: std, median: quantile(0.5), p25: quantile(0.25), p75: quantile(0.75), min: sorted[0], max: sorted[n - 1], cv: mean > 0 ? (std / mean) * 100 : 0, upper: mean + std, lower: Math.max(0, mean - std) };
  }

  function market(root) {
    var current = markets.get(root);
    if (current) return current;
    current = { root: root, options: null, state: null };
    markets.set(root, current);
    return current;
  }

  function field(root, name) { return root.querySelector('[data-market-field="' + name + '"]'); }

  function fillSelect(select, values, first) {
    var selected = select.value;
    select.innerHTML = '<option value="">' + escapeHtml(first) + "</option>" + values.map(function (value) {
      return '<option value="' + escapeHtml(value) + '">' + escapeHtml(value) + "</option>";
    }).join("");
    if (values.indexOf(selected) !== -1) select.value = selected;
  }

  function fillBarrios(m) {
    var ruta = field(m.root, "ruta").value;
    fillSelect(field(m.root, "barrio"), m.options.barrios.filter(function (item) { return !ruta || item.ruta === ruta; }).map(function (item) { return item.barrio; }), "Todos");
    var included = ruta ? m.options.rutas[ruta] || [] : [];
    var info = m.root.querySelector("[data-market-route-info]");
    info.innerHTML = included.length ? "<strong>Incluye:</strong> " + escapeHtml(included.join(", ")) : "";
    info.hidden = !included.length;
  }

  function renderStates(m) {
    m.root.querySelector("[data-market-states]").innerHTML = m.options.estados.map(function (item) {
      return '<label><input type="checkbox" name="estados[]" value="' + escapeHtml(item.value) + '"' + (item.default ? " checked" : "") + ">" + escapeHtml(item.value) + " <small>(" + item.count + ")</small></label>";
    }).join("");
  }

  function loadMarket(root) {
    if (root.dataset.loaded) return;
    root.dataset.loaded = "1";
    var m = market(root);
    var status = root.querySelector("[data-market-options-status]");
    post("commercial_tools_market_options").then(function (data) {
      m.options = data.options || { rutas: {}, barrios: [], tipos: [], destinaciones: [], estratos: [], estados: [] };
      fillSelect(field(root, "ruta"), Object.keys(m.options.rutas), "Todas las rutas");
      fillSelect(field(root, "tipo_inmueble"), m.options.tipos, "Todos");
      fillSelect(field(root, "destinacion"), m.options.destinaciones, "Todos");
      fillSelect(field(root, "estrato"), m.options.estratos, "Todos");
      fillBarrios(m);
      renderStates(m);
      status.textContent = m.options.barrios.length + " barrios · " + Object.keys(m.options.rutas).length + " rutas";
    }).catch(function (error) { status.textContent = error.message; });
  }

  function stat(label, value, note) {
    return '<div class="ctl-stat"><span>' + label + "</span><strong>" + value + "</strong>" + (note ? "<small>" + note + "</small>" : "") + "</div>";
  }

  function valuesFor(state, mode) {
    if (!state.admin) return state[mode.key] || [];
    return state.items.filter(function (item, index) { return item.modo === mode.mode && !state.excluded[index]; }).map(function (item) { return item.m2; });
  }

  function renderResults(m) {
    var state = m.state;
    var html = "";
    MARKET_MODES.forEach(function (mode) {
      var values = valuesFor(state, mode);
      if (!values.length) return;
      var s = marketStats(values);
      html += '<section class="ctl-card ctl-mode" data-tone="' + mode.tone + '"><div class="ctl-card__head ctl-card__head--split"><h2><span class="material-symbols-outlined" aria-hidden="true">monitoring</span>' + mode.title + "</h2>" +
        '<span class="ctl-small">Muestra: <strong>' + s.n + "</strong> inmueble(s)</span></div>" +
        (s.n < MIN_SAMPLE ? '<p class="ctl-alert">Muestra pequeña: con menos de ' + MIN_SAMPLE + " inmuebles el promedio es poco confiable. Amplía los filtros.</p>" : "") +
        '<div class="ctl-stats">' + stat("Precio promedio m²", money.format(s.mean)) + stat("Mediana m²", money.format(s.median), "Valor central, menos sensible a atípicos") + stat("Límite superior", money.format(s.upper), "+1 desviación") + stat("Límite inferior", money.format(s.lower), "−1 desviación") + "</div>";
      if (state.admin) {
        html += '<div class="ctl-stats ctl-stats--more">' + stat("Coef. variación", number.format(s.cv) + " %", s.cv > 30 ? "Dispersión alta" : "Dispersión aceptable") + stat("Desviación estándar", money.format(s.std)) + stat("Percentil 25", money.format(s.p25)) + stat("Percentil 75", money.format(s.p75)) + stat("Mínimo", money.format(s.min)) + stat("Máximo", money.format(s.max)) + stat("Muestra", String(s.n)) + "</div>";
      }
      html += "</section>";
    });
    m.root.querySelector("[data-market-results]").innerHTML = html || '<div class="ctl-empty"><span class="material-symbols-outlined" aria-hidden="true">search_off</span><p>No se encontraron inmuebles con precio y área para estos filtros.</p></div>';
  }

  function renderTable(m) {
    var state = m.state;
    var box = m.root.querySelector("[data-market-table]");
    if (!state.admin) { box.innerHTML = ""; return; }
    var order = state.items.map(function (_item, index) { return index; }).sort(function (a, b) {
      var x = state.items[a];
      var y = state.items[b];
      return x.modo === y.modo ? y.m2 - x.m2 : x.modo < y.modo ? 1 : -1;
    });
    var excludedCount = Object.keys(state.excluded).filter(function (key) { return state.excluded[key]; }).length;
    var rows = order.map(function (index) {
      var item = state.items[index];
      var off = !!state.excluded[index];
      return '<tr' + (off ? ' class="is-off"' : "") + '><td><input type="checkbox" data-market-include="' + index + '"' + (off ? "" : " checked") + ' aria-label="Incluir en el cálculo"></td>' +
        '<td class="ctl-mono">#' + item.id + (item.codigo ? "<br>" + escapeHtml(item.codigo) : "") + "</td>" +
        "<td><strong>" + escapeHtml(item.barrio || "-") + "</strong><small>" + escapeHtml(item.tipo) + " · " + escapeHtml(item.estado) + "</small></td>" +
        '<td><span class="ctl-tag" data-tone="' + (item.modo === "Venta" ? "sale" : "rent") + '">' + item.modo + "</span></td>" +
        '<td class="is-num">' + money.format(item.precio) + '</td><td class="is-num">' + number.format(item.area) + ' m²</td><td class="is-num"><strong>' + money.format(item.m2) + "</strong></td>" +
        '<td><button type="button" class="ctl-link" data-commercial-property-detail="' + item.id + '">Ver ficha</button></td></tr>';
    }).join("");
    box.innerHTML = '<section class="ctl-card ctl-stack"><div class="ctl-card__head ctl-card__head--split"><div><h2>Tabla de control · Exclusión de atípicos</h2><p>' + state.items.length + " registro(s) · " + excludedCount + " excluido(s) · Desmarca un inmueble para sacarlo del cálculo" + (state.without_area ? " · " + state.without_area + " sin área no entran" : "") + "</p></div>" +
      '<div class="ctl-actions"><button type="button" class="ctl-secondary" data-market-outliers title="Excluye los valores fuera de 1,5 rangos intercuartiles, por venta y por arriendo"><span class="material-symbols-outlined" aria-hidden="true">filter_alt_off</span>Excluir atípicos</button>' +
      '<button type="button" class="ctl-secondary" data-market-restore' + (excludedCount ? "" : " disabled") + '><span class="material-symbols-outlined" aria-hidden="true">restart_alt</span>Restablecer</button>' +
      '<button type="button" class="ctl-secondary" data-market-export><span class="material-symbols-outlined" aria-hidden="true">download</span>Exportar CSV</button></div></div>' +
      '<div class="ctl-table-wrap"><table class="ctl-table ctl-table--data"><thead><tr><th>Incluir</th><th>ID</th><th>Barrio</th><th>Modo</th><th class="is-num">Precio</th><th class="is-num">Área</th><th class="is-num">Valor m²</th><th></th></tr></thead><tbody>' +
      (rows || '<tr><td colspan="8" class="ctl-small">Sin datos.</td></tr>') + "</tbody></table></div></section>";
  }

  function renderMarket(m) {
    renderResults(m);
    renderTable(m);
  }

  function excludeOutliers(m) {
    MARKET_MODES.forEach(function (mode) {
      var values = valuesFor(m.state, mode);
      if (values.length < 4) return;
      var s = marketStats(values);
      var iqr = s.p75 - s.p25;
      var low = s.p25 - 1.5 * iqr;
      var high = s.p75 + 1.5 * iqr;
      m.state.items.forEach(function (item, index) {
        if (item.modo === mode.mode && !m.state.excluded[index] && (item.m2 < low || item.m2 > high)) m.state.excluded[index] = true;
      });
    });
    renderMarket(m);
  }

  function download(blob, name) {
    var link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  }

  function exportCsv(m) {
    var header = ["ID", "Codigo", "Barrio", "Tipo", "Estado", "Modo", "Precio", "Area m2", "Valor m2", "Incluido"];
    var lines = [header.join(";")].concat(m.state.items.map(function (item, index) {
      return [item.id, item.codigo, item.barrio, item.tipo, item.estado, item.modo, Math.round(item.precio), String(item.area).replace(".", ","), Math.round(item.m2), m.state.excluded[index] ? "No" : "Si"]
        .map(function (value) { return '"' + String(value == null ? "" : value).replace(/"/g, '""') + '"'; }).join(";");
    }));
    download(new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" }), "calculadora-mercado-" + new Date().toISOString().slice(0, 10) + ".csv");
  }

  function analyze(root) {
    var form = root.querySelector("[data-market-form]");
    var status = root.querySelector("[data-market-status]");
    var submit = root.querySelector("[data-market-submit]");
    var data = new FormData(form);
    if (!data.getAll("estados[]").length) { setStatus(status, "Selecciona al menos un estado del inmueble.", true); return; }
    submit.disabled = true;
    setStatus(status, "Procesando datos…");
    post("commercial_tools_market_analyze", data).then(function (response) {
      var m = market(root);
      m.state = { admin: !!response.admin, venta: response.venta || [], arriendo: response.arriendo || [], items: response.items || [], without_area: response.without_area || 0, excluded: {} };
      setStatus(status, "");
      renderMarket(m);
    }).catch(function (error) { setStatus(status, error.message, true); })
      .finally(function () { submit.disabled = false; });
  }

  /* -------------------------------------------------------------------------------- Calculadora de aseo */
  // El cálculo y los PDF se hacen en el servidor; los costos y utilidades solo llegan con la vista interna.

  function toggleProtocol(root) {
    root.querySelector("[data-cleaning-protocol]").hidden = Number(root.querySelector("[data-cleaning-level]").value) < 4;
  }

  function setPdfEnabled(root, enabled) {
    root.querySelectorAll("[data-cleaning-pdf]").forEach(function (button) { button.disabled = !enabled; });
  }

  function cleaningRows(rows) {
    return '<dl class="ctl-breakdown">' + rows.map(function (row) {
      return "<div" + (row[2] ? ' class="' + row[2] + '"' : "") + "><dt>" + row[0] + "</dt><dd>" + row[1] + "</dd></div>";
    }).join("") + "</dl>";
  }

  function renderCleaning(root, data) {
    var r = data.result;
    var lines = r.cliente;
    var html = '<div class="ctl-stats ctl-stats--3">' + stat("Subtotal (sin IVA)", money.format(r.base)) + stat("IVA", money.format(r.iva)) + '<div class="ctl-stat is-total"><span>Total cliente</span><strong>' + money.format(r.total) + "</strong></div></div>" +
      '<h3 class="ctl-subtitle">Lo que ve el cliente</h3>' + cleaningRows([
        ["Servicio de limpieza", money.format(lines.servicio)], ["Transporte", money.format(lines.transporte)], ["Insumos profesionales", money.format(lines.insumos)],
        ["Subtotal", money.format(r.base), "is-strong"], ["IVA", money.format(r.iva)], ["Total a pagar", money.format(r.total), "is-strong"],
      ]);
    if (data.internal) {
      html += '<h3 class="ctl-subtitle">Costos y márgenes</h3>' + cleaningRows([
        ["Mano de obra (" + String(r.horas).replace(".", ",") + " h)", money.format(r.mano_obra)], ["Transporte", money.format(r.transporte)], ["Insumos", money.format(r.insumos)],
        ["Costo directo", money.format(r.costo_directo), "is-strong"], ["Precio contratista", money.format(r.precio_contratista)], ["Utilidad inmobiliaria", money.format(r.utilidad_inmobiliaria), "is-good"],
      ]) + '<h3 class="ctl-subtitle">' + (r.input.trabajador === "contratista" ? "Costos reales contratista" : "Utilidad real (funcionario)") + "</h3>" + cleaningRows([
        ["Costo mano de obra real", money.format(r.real_mano_obra)], ["Auxilio de transporte real", money.format(r.real_transporte)], ["Insumos", money.format(r.insumos)],
        ["Utilidad real del servicio", money.format(r.utilidad_real), "is-strong is-good"],
      ]);
    }
    root.querySelector("[data-cleaning-result]").innerHTML = html;
  }

  function calculateCleaning(root) {
    var form = root.querySelector("[data-cleaning-form]");
    var status = root.querySelector("[data-cleaning-status]");
    var submit = root.querySelector("[data-cleaning-submit]");
    toggleProtocol(root);
    submit.disabled = true;
    setStatus(status, "Calculando…");
    post("commercial_tools_cleaning_calculate", new FormData(form)).then(function (data) {
      renderCleaning(root, data);
      root.dataset.calculated = "1";
      setPdfEnabled(root, true);
      setStatus(status, "");
    }).catch(function (error) {
      setStatus(status, error.message, true);
      setPdfEnabled(root, false);
    }).finally(function () { submit.disabled = false; });
  }

  function cleaningPdf(root, button) {
    var mode = button.getAttribute("data-cleaning-pdf");
    var status = root.querySelector("[data-cleaning-status]");
    var data = new FormData(root.querySelector("[data-cleaning-form]"));
    data.set("mode", mode);
    button.disabled = true;
    setStatus(status, "Generando PDF…");
    send("commercial_tools_cleaning_pdf", data).then(function (response) {
      var type = response.headers.get("Content-Type") || "";
      if (type.indexOf("application/json") !== -1) {
        return response.json().then(function (json) { throw new Error((json && json.data && json.data.message) || "No se pudo generar el PDF."); });
      }
      if (!response.ok) throw new Error("No se pudo generar el PDF.");
      return response.blob();
    }).then(function (blob) {
      download(blob, "aseo-" + mode + "-" + new Date().toISOString().slice(0, 10) + ".pdf");
      setStatus(status, "");
    }).catch(function (error) { setStatus(status, error.message, true); })
      .finally(function () { button.disabled = false; });
  }

  // Si cambia un dato después de calcular, el PDF quedaría desactualizado: se pide calcular de nuevo.
  function invalidateCleaning(root) {
    if (root.dataset.calculated !== "1") return;
    root.dataset.calculated = "";
    setPdfEnabled(root, false);
    setStatus(root.querySelector("[data-cleaning-status]"), "Cambiaste los datos: calcula de nuevo para generar los PDF.");
  }

  /* ------------------------------------------------------------------------------------------- Eventos */

  document.addEventListener("submit", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.matches("[data-market-form]")) { event.preventDefault(); analyze(target.closest('[data-commercial-tool="calculadora_mercado"]')); return; }
    if (target.matches("[data-cleaning-form]")) { event.preventDefault(); calculateCleaning(target.closest('[data-commercial-tool="calculadora_aseo"]')); }
  });

  document.addEventListener("reset", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var root = target && target.matches("[data-market-form]") ? target.closest('[data-commercial-tool="calculadora_mercado"]') : null;
    if (!root) return;
    window.setTimeout(function () {
      var m = market(root);
      if (!m.options) return;
      fillBarrios(m);
      renderStates(m);
    }, 0);
  });

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    var marketRoot = target.closest('[data-commercial-tool="calculadora_mercado"]');
    if (marketRoot) {
      var m = market(marketRoot);
      if (!m.state) return;
      if (target.closest("[data-market-outliers]")) excludeOutliers(m);
      else if (target.closest("[data-market-restore]")) { m.state.excluded = {}; renderMarket(m); }
      else if (target.closest("[data-market-export]")) exportCsv(m);
      return;
    }
    var cleaningRoot = target.closest('[data-commercial-tool="calculadora_aseo"]');
    var pdf = cleaningRoot && target.closest("[data-cleaning-pdf]");
    if (pdf && cleaningRoot.dataset.calculated === "1") cleaningPdf(cleaningRoot, pdf);
  });

  document.addEventListener("change", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    var marketRoot = target.closest('[data-commercial-tool="calculadora_mercado"]');
    if (marketRoot) {
      var m = market(marketRoot);
      if (target.matches('[data-market-field="ruta"]') && m.options) { field(marketRoot, "barrio").value = ""; fillBarrios(m); return; }
      if (target.matches("[data-market-include]") && m.state) { m.state.excluded[target.getAttribute("data-market-include")] = !target.checked; renderMarket(m); }
      return;
    }
    var cleaningRoot = target.closest('[data-commercial-tool="calculadora_aseo"]');
    if (!cleaningRoot) return;
    if (target.matches("[data-cleaning-level]")) toggleProtocol(cleaningRoot);
    invalidateCleaning(cleaningRoot);
  });

  document.addEventListener("input", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var cleaningRoot = target && target.closest('[data-commercial-tool="calculadora_aseo"]');
    if (cleaningRoot && target.closest("[data-cleaning-form]")) invalidateCleaning(cleaningRoot);
  });

  // Las pestañas se cargan por AJAX: cuando aparece la calculadora de mercado se piden sus filtros.
  function scan() {
    app.querySelectorAll('[data-commercial-tool="calculadora_mercado"]:not([data-loaded])').forEach(loadMarket);
    app.querySelectorAll('[data-commercial-tool="calculadora_aseo"]').forEach(toggleProtocol);
  }
  new MutationObserver(scan).observe(app, { childList: true, subtree: true });
  scan();
})();
