(function () {
  "use strict";
  // Generador UTM: copia de dashboard-marketing (views/utm.php y la sección "UTM" de public/assets/app.js) con el diseño
  // de las herramientas comerciales. slug() y build() repiten src/Commercial/Tools/UtmLinkBuilder.php: si cambia uno,
  // cambiar el otro (los dos se prueban con tests/Fixtures/utm-cases.json). El catálogo (medios, fuentes y campañas) ya
  // no se guarda en el navegador: lo define un administrador en Configuración › Generador UTM.
  var MAX_LENGTH = 100;
  var MAX_URL_LENGTH = 2000;
  var MAX_RAW_LENGTH = 120;
  var RESERVED_CAMPAIGNS = ["general", "inmueble_view", "event_click"];
  var TRIM_RE = /^[\t-\r \u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+|[\t-\r \u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+$/g;
  var PARAMS = [["source", "utm_source"], ["medium", "utm_medium"], ["campaign", "utm_campaign"], ["content", "utm_content"], ["term", "utm_term"]];
  var REQUIRED = { source: "la fuente", medium: "el medio", campaign: "la campaña" };
  var ACCENTS = {
    "á": "a", "à": "a", "ä": "a", "â": "a", "ã": "a", "Á": "a", "À": "a", "Ä": "a", "Â": "a", "Ã": "a",
    "é": "e", "è": "e", "ë": "e", "ê": "e", "É": "e", "È": "e", "Ë": "e", "Ê": "e",
    "í": "i", "ì": "i", "ï": "i", "î": "i", "Í": "i", "Ì": "i", "Ï": "i", "Î": "i",
    "ó": "o", "ò": "o", "ö": "o", "ô": "o", "õ": "o", "Ó": "o", "Ò": "o", "Ö": "o", "Ô": "o", "Õ": "o",
    "ú": "u", "ù": "u", "ü": "u", "û": "u", "Ú": "u", "Ù": "u", "Ü": "u", "Û": "u",
    "ñ": "n", "Ñ": "n", "ç": "c", "Ç": "c",
  };
  var ACCENT_RE = new RegExp("[" + Object.keys(ACCENTS).join("") + "]", "g");
  var OTHER = "__otro__";

  function trimSeparators(value) { return value.replace(/^[-_]+|[-_]+$/g, ""); }

  /** Igual que UtmLinkBuilder::trimText: los mismos espacios Unicode en los dos lados. */
  function trimText(value) { return String(value == null ? "" : value).replace(TRIM_RE, ""); }

  function asciiLower(value) { return value.replace(/[A-Z]/g, function (char) { return char.toLowerCase(); }); }

  function reservedMessage(value) { return '"' + value + '" lo usa el tracker para las visitas sin campaña: elige otro nombre de campaña.'; }

  /** Igual que rawurlencode de PHP (también codifica ! ' ( ) *). */
  function encode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, function (char) { return "%" + char.charCodeAt(0).toString(16).toUpperCase(); });
  }

  function slug(value) {
    var out = asciiLower(String(value == null ? "" : value)
      // Las tildes que llegan separadas de su letra (texto en NFD, copiado de macOS o de un PDF) se quitan antes.
      .replace(/[\u0300-\u036f]+/g, "")
      .replace(ACCENT_RE, function (char) { return ACCENTS[char]; }))
      .replace(/[^a-z0-9-]+/g, "_")
      .replace(/[-_]*-[-_]*/g, "-");
    return trimSeparators(trimSeparators(out).slice(0, MAX_LENGTH));
  }

  function decodeKey(key) {
    var plain = key.replace(/\+/g, " ");
    try { return decodeURIComponent(plain); } catch (_error) { return plain; }
  }

  /** Un valor del tracker va sin normalizar; solo se rechaza lo que el tracker tampoco guardaría igual. */
  function verbatimValue(param, value) {
    if (/[\x00-\x1f\x7f]/.test(value)) throw new Error("El valor de " + param + " no puede tener saltos de línea ni caracteres de control.");
    if (Array.from(value).length > MAX_RAW_LENGTH) throw new Error("El valor de " + param + " supera " + MAX_RAW_LENGTH + " caracteres.");
    return value;
  }

  /** verbatim: campos que se envían tal cual porque ya los registró el tracker (mismo contrato que UtmLinkBuilder::build). */
  function build(url, values, verbatim) {
    url = trimText(url);
    values = values || {};
    verbatim = verbatim || [];
    if (url === "") throw new Error("Escribe la URL de destino.");
    if (new TextEncoder().encode(url).length > MAX_URL_LENGTH) throw new Error("La URL de destino es demasiado larga.");
    if (/[\x00-\x20\x7f]/.test(url)) throw new Error("La URL de destino no puede tener espacios.");
    if (!/^https?:\/\//i.test(url)) url = "https://" + url;
    if (!/^https?:\/\/(localhost|([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,})(:\d{1,5})?([\/?#]|$)/i.test(url)) {
      throw new Error("La URL de destino no es válida: debe ser una página web (https://…).");
    }

    var params = [];
    var setting = {};
    PARAMS.forEach(function (pair) {
      var raw = trimText(values[pair[0]]);
      var value;
      if (verbatim.indexOf(pair[0]) >= 0) {
        value = verbatimValue(pair[1], raw);
      } else {
        value = slug(raw);
        if (raw !== "" && value === "") throw new Error("El valor de " + pair[1] + " debe tener letras o números.");
      }
      if (value === "" && REQUIRED[pair[0]]) throw new Error("Falta " + REQUIRED[pair[0]] + " del enlace.");
      if (pair[0] === "campaign" && RESERVED_CAMPAIGNS.indexOf(asciiLower(value)) >= 0) throw new Error(reservedMessage(value));
      if (value !== "") { params.push([pair[1], value]); setting[pair[1]] = true; }
    });

    var hash = url.indexOf("#");
    var fragment = hash < 0 ? "" : url.slice(hash);
    var rest = hash < 0 ? url : url.slice(0, hash);
    var mark = rest.indexOf("?");
    var path = mark < 0 ? rest : rest.slice(0, mark);
    var query = mark < 0 ? "" : rest.slice(mark + 1);
    if (/^https?:\/\/[^\/]+$/i.test(path)) path += "/";

    // Se conservan los parámetros que ya traía la URL; los utm_* que se van a escribir se reemplazan.
    var pairs = query.split("&").filter(function (pair) {
      if (pair === "") return false;
      var equal = pair.indexOf("=");
      return !setting[decodeKey(equal < 0 ? pair : pair.slice(0, equal))];
    });
    params.forEach(function (pair) { pairs.push(pair[0] + "=" + encode(pair[1])); });
    return path + "?" + pairs.join("&") + fragment;
  }

  window.SCMCommercialUtm = { slug: slug, build: build };

  /** Campañas que devolvió el tracker para cada generador (valor exacto => true). */
  var trackerCampaigns = new WeakMap();

  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}

  function escapeHtml(value) { return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]; }); }

  function post(action) {
    var data = new FormData();
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    return fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" }).then(function (response) {
      return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); }).then(function (payload) {
        if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
        return payload.data || {};
      });
    });
  }

  function setStatus(root, text, error) {
    var node = root.querySelector("[data-utm-status]");
    node.textContent = text || "";
    node.dataset.error = error ? "true" : "false";
  }

  function field(root, name) { return root.querySelector('[data-utm-field="' + name + '"]'); }

  /** Valor escrito o elegido en cada campo; "Otro" toma el texto del cuadro que aparece debajo. */
  function value(root, name) {
    var input = field(root, name);
    if (!input) return "";
    if (input.tagName === "SELECT" && input.value === OTHER) {
      var other = root.querySelector('[data-utm-other="' + name + '"]');
      return other ? other.value : "";
    }
    return input.value;
  }

  function values(root) {
    var out = {};
    PARAMS.forEach(function (pair) { out[pair[0]] = value(root, pair[0]); });
    return out;
  }

  /**
   * Campos que van tal cual porque ya los registró el tracker: la fuente o el medio elegidos en "Usados en el tracker"
   * y la campaña escrita exactamente como una del tracker. Así el enlace suma a esa campaña en vez de crear una variante.
   */
  function verbatim(root) {
    var out = [];
    ["source", "medium"].forEach(function (name) {
      var input = field(root, name);
      var option = input && input.tagName === "SELECT" ? input.options[input.selectedIndex] : null;
      if (option && option.hasAttribute("data-utm-raw")) out.push(name);
    });
    var known = trackerCampaigns.get(root) || {};
    if (Object.prototype.hasOwnProperty.call(known, trimText(value(root, "campaign")))) out.push("campaign");
    return out;
  }

  /** Lo que quedará en la URL para un campo: el valor del tracker tal cual o, si no, su slug. */
  function paramValue(root, name, kept) {
    var raw = trimText(value(root, name));
    return kept.indexOf(name) >= 0 ? raw : slug(raw);
  }

  function refreshPreviews(root) {
    var kept = verbatim(root);
    PARAMS.forEach(function (pair) {
      var node = root.querySelector('[data-utm-preview="' + pair[0] + '"]');
      if (!node) return;
      var raw = trimText(value(root, pair[0]));
      var normalized = paramValue(root, pair[0], kept);
      node.innerHTML = raw === "" ? "" : normalized === "" ? "Debe tener letras o números." : "En el enlace: <code>" + escapeHtml(pair[1] + "=" + normalized) + "</code>" + (kept.indexOf(pair[0]) >= 0 ? " (tal como lo registró el tracker)" : "");
    });
    var campaign = paramValue(root, "campaign", kept);
    root.querySelectorAll("[data-utm-pick]").forEach(function (chip) {
      var pick = chip.getAttribute("data-utm-pick");
      var target = chip.hasAttribute("data-utm-raw") ? pick : slug(pick);
      chip.setAttribute("aria-pressed", campaign !== "" && target === campaign ? "true" : "false");
    });
  }

  function clearResult(root) {
    root.querySelector("[data-utm-result]").value = "";
    root.querySelector("[data-utm-parts]").innerHTML = "";
    root.querySelector("[data-utm-copy]").disabled = true;
  }

  function copy(root) {
    var result = root.querySelector("[data-utm-result]");
    var text = result.value;
    if (!text) return Promise.resolve(false);
    var fallback = function () {
      result.focus();
      result.select();
      try { return document.execCommand("copy"); } catch (_error) { return false; }
    };
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
    }
    return Promise.resolve(fallback());
  }

  function generate(root) {
    var link;
    var kept = verbatim(root);
    try {
      link = build(value(root, "url"), values(root), kept);
    } catch (error) {
      clearResult(root);
      setStatus(root, error.message, true);
      return;
    }
    root.querySelector("[data-utm-result]").value = link;
    root.querySelector("[data-utm-copy]").disabled = false;
    var parts = "";
    PARAMS.forEach(function (pair) {
      var normalized = paramValue(root, pair[0], kept);
      if (normalized) parts += "<div><dt>" + escapeHtml(pair[1]) + "</dt><dd>" + escapeHtml(normalized) + "</dd></div>";
    });
    root.querySelector("[data-utm-parts]").innerHTML = parts;
    setStatus(root, "Enlace generado.", false);
    copy(root).then(function (copied) {
      setStatus(root, copied ? "Enlace copiado al portapapeles." : "Enlace generado. Cópialo desde el campo.", false);
    });
  }

  /** Chip de una campaña del tracker: data-utm-raw indica que su valor se envía tal cual. */
  function chip(valueText, label) {
    return '<button type="button" class="ctl-chip" data-utm-pick="' + escapeHtml(valueText) + '" data-utm-raw title="' + escapeHtml(valueText) + '" aria-pressed="false">' + escapeHtml(label || valueText) + "</button>";
  }

  /**
   * Fuentes, medios y campañas que ya registró el tracker (solo lectura), como sugerencias además del catálogo. Se
   * ofrecen con su valor exacto (como el generador original) para sumar a lo ya registrado; se omiten los que el
   * catálogo ya tiene (el tracker compara sin distinguir mayúsculas).
   */
  function loadTracker(root) {
    root.setAttribute("data-loaded", "1");
    var status = root.querySelector("[data-utm-tracker-status]");
    post("commercial_tools_utm_options").then(function (data) {
      [["source", data.sources || []], ["medium", data.mediums || []]].forEach(function (entry) {
        var select = field(root, entry[0]);
        if (!select) return;
        var known = {};
        Array.prototype.forEach.call(select.options, function (option) { known[asciiLower(option.value)] = true; });
        var html = "";
        entry[1].forEach(function (raw) {
          raw = trimText(raw);
          var key = asciiLower(raw);
          if (!raw || known[key]) return;
          known[key] = true;
          html += '<option value="' + escapeHtml(raw) + '" data-utm-raw>' + escapeHtml(raw) + "</option>";
        });
        if (html) select.querySelector('option[value="' + OTHER + '"]').insertAdjacentHTML("beforebegin", '<optgroup label="Usados en el tracker" data-utm-group="tracker">' + html + "</optgroup>");
      });
      var campaigns = (data.campaigns || []).filter(function (item) { return item && trimText(item.value) !== ""; });
      var list = root.querySelector("[data-utm-campaign-list]");
      var known = {};
      Array.prototype.forEach.call(list.options, function (option) { known[asciiLower(option.value)] = true; });
      var exact = {};
      var chips = "";
      var options = "";
      campaigns.forEach(function (item) {
        var raw = trimText(item.value);
        exact[raw] = true;
        chips += chip(raw, item.label);
        var key = asciiLower(raw);
        if (!known[key]) { known[key] = true; options += '<option value="' + escapeHtml(raw) + '">' + escapeHtml(item.label || "") + "</option>"; }
      });
      trackerCampaigns.set(root, exact);
      list.insertAdjacentHTML("beforeend", options);
      root.querySelector("[data-utm-tracker-campaigns]").innerHTML = chips;
      status.textContent = campaigns.length ? (campaigns.length === 1 ? "1 campaña registrada." : campaigns.length + " campañas registradas.") : "El tracker aún no tiene campañas registradas.";
      refreshPreviews(root);
    }).catch(function (error) {
      status.textContent = error.message || "No se pudieron cargar las campañas del tracker.";
    });
  }

  function toolRoot(target) { return target instanceof Element ? target.closest('[data-commercial-tool="generador_utm"]') : null; }

  document.addEventListener("submit", function (event) {
    var root = event.target instanceof Element && event.target.matches("[data-utm-form]") ? toolRoot(event.target) : null;
    if (!root) return;
    event.preventDefault();
    generate(root);
  });

  document.addEventListener("reset", function (event) {
    var root = event.target instanceof Element && event.target.matches("[data-utm-form]") ? toolRoot(event.target) : null;
    if (!root) return;
    window.setTimeout(function () {
      root.querySelectorAll("[data-utm-other-box]").forEach(function (box) { box.hidden = true; });
      clearResult(root);
      setStatus(root, "", false);
      refreshPreviews(root);
    });
  });

  document.addEventListener("change", function (event) {
    var root = toolRoot(event.target);
    if (!root) return;
    var target = event.target;
    if (target.matches("select[data-utm-field]")) {
      var box = target.closest("[data-utm-combo]").querySelector("[data-utm-other-box]");
      box.hidden = target.value !== OTHER;
      if (!box.hidden) box.querySelector("input").focus();
    }
    refreshPreviews(root);
  });

  document.addEventListener("input", function (event) {
    var root = toolRoot(event.target);
    if (!root || !event.target.closest("[data-utm-form]")) return;
    if (root.querySelector("[data-utm-result]").value) {
      clearResult(root);
      setStatus(root, "Cambiaste los datos: vuelve a generar el enlace.", false);
    }
    refreshPreviews(root);
  });

  document.addEventListener("click", function (event) {
    var root = toolRoot(event.target);
    if (!root) return;
    var pick = event.target.closest("[data-utm-pick]");
    if (pick) {
      var campaign = field(root, "campaign");
      campaign.value = pick.getAttribute("data-utm-pick");
      campaign.dispatchEvent(new Event("input", { bubbles: true }));
      campaign.focus();
      return;
    }
    if (event.target.closest("[data-utm-copy]")) {
      copy(root).then(function (copied) {
        setStatus(root, copied ? "Enlace copiado al portapapeles." : "No se pudo copiar: selecciona el enlace y cópialo.", !copied);
      });
    }
  });

  // Las pestañas se cargan por AJAX: cuando aparece el generador se piden las campañas del tracker.
  function scan() {
    app.querySelectorAll('[data-commercial-tool="generador_utm"]:not([data-loaded])').forEach(loadTracker);
  }
  new MutationObserver(scan).observe(app, { childList: true, subtree: true });
  scan();
})();
