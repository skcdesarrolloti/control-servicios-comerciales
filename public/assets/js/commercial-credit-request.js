(function () {
  "use strict";
  // Solicitud de crédito: se busca al propietario por documento, se elige el contrato del que se descuenta y se
  // registra lo que pide. La evaluación y la decisión se hacen en el liquidador de créditos (panel inmobiliario).
  var runtime = {};
  var app = document.getElementById("scm-app");
  if (!app) return;
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });

  async function request(action, data) {
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("El servidor devolvió una respuesta no válida."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }
  function showError(root, message) {
    var node = root.querySelector("[data-credit-error]");
    node.textContent = message || "";
    node.hidden = !message;
    if (message) node.scrollIntoView({ block: "nearest" });
  }
  function setStatus(root, text, error) {
    var node = root.querySelector("[data-credit-search-status]");
    node.textContent = text || "";
    node.dataset.error = error ? "true" : "false";
  }
  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  // Pesos colombianos: $1.250.000 (sin decimales). Al escribir se conserva el cursor contando dígitos.
  function formatMoney(input, keepCaret) {
    var raw = String(input.value || "");
    var caret = keepCaret && typeof input.selectionStart === "number" ? input.selectionStart : raw.length;
    var digitsBefore = raw.slice(0, caret).replace(/[^\d]/g, "").length;
    var digits = raw.replace(/[^\d]/g, "").replace(/^0+(?=\d)/, "").slice(0, 10);
    var formatted = digits === "" ? "" : "$" + moneyFormat.format(Number(digits));
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

  function pickContract(root, contract) {
    var form = root.querySelector("[data-credit-form]");
    form.elements.contract_id.value = contract ? String(contract.id) : "";
    form.elements.owner_key.value = contract && contract.owner ? contract.owner.key : "";
    root.querySelector("[data-credit-request]").hidden = !contract;
    root._contract = contract;
    if (!contract) return;
    updateTermHint(root);
    form.elements.amount.focus();
  }

  // Solo informa: el consultor no conoce la fecha fin; si el plazo la supera, el analista la revisa en el liquidador.
  function updateTermHint(root) {
    var hint = root.querySelector("[data-credit-term-hint]");
    var contract = root._contract;
    if (!hint || !contract) return;
    var term = Number(root.querySelector("[data-credit-form]").elements.term.value) || 0;
    var max = Number(contract.max_months) || 0;
    var warning = !contract.fin || term > max;
    if (!contract.fin) hint.textContent = "El contrato no tiene fecha fin registrada; el analista la revisará en el liquidador.";
    else if (term > max) hint.textContent = "Según el sistema el contrato vence el " + contract.fin + " (quedan " + max + " meses). Puedes registrar la solicitud: el analista revisará la fecha o el plazo.";
    else hint.textContent = "El contrato vence el " + contract.fin + " (quedan " + max + " meses).";
    hint.dataset.warning = warning ? "true" : "false";
  }

  async function search(root) {
    var input = root.querySelector("[data-credit-document]");
    var query = input.value.trim();
    var list = root.querySelector("[data-credit-contracts]");
    var person = root.querySelector("[data-credit-person]");
    var button = root.querySelector("[data-credit-search]");
    pickContract(root, null);
    list.replaceChildren();
    person.hidden = true;
    showError(root, "");
    if (query.replace(/[\s.]/g, "").length < 4) { setStatus(root, "Escribe la cédula o el NIT (mínimo 4 caracteres).", true); input.focus(); return; }
    if (button.disabled) return;
    button.disabled = true;
    setStatus(root, "Buscando…");
    try {
      var data = new FormData();
      data.set("document", query);
      var contracts = (await request("commercial_credit_contracts", data)).contracts || [];
      root._contracts = contracts;
      if (!contracts.length) {
        setStatus(root, "No encontramos contratos Entregado o Por entregar donde este documento sea de un propietario. Revisa el número.", true);
        return;
      }
      var owner = contracts[0].owner || {};
      person.replaceChildren(
        element("strong", "", owner.name || "Propietario"),
        element("span", "", [owner.document ? "Documento " + owner.document : "", owner.phone ? "Cel. " + owner.phone : "", owner.email || ""].filter(Boolean).join(" · ") || "Sin datos de contacto")
      );
      person.hidden = false;
      setStatus(root, contracts.length === 1 ? "Es propietario en 1 contrato." : "Es propietario en " + contracts.length + " contratos. Elige del que se descontarán las cuotas.");
      contracts.forEach(function (contract, index) {
        var label = element("label", "ccr-contract");
        var radio = document.createElement("input");
        radio.type = "radio";
        radio.name = "contract_pick";
        radio.value = String(index);
        radio.dataset.creditPick = "";
        var body = element("span");
        body.append(
          element("strong", "", "Contrato " + contract.contrato + (contract.inmueble ? " · SIMI " + contract.inmueble : "")),
          element("span", "", [contract.direccion, contract.estado, contract.fin ? "Vence " + contract.fin : ""].filter(Boolean).join(" · "))
        );
        if (contract.arrendatario) body.append(element("span", "", "Arrendatario: " + contract.arrendatario));
        label.append(radio, body);
        list.append(label);
      });
      if (contracts.length === 1) {
        list.querySelector("[data-credit-pick]").checked = true;
        pickContract(root, contracts[0]);
      }
    } catch (error) {
      setStatus(root, error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  function reset(root) {
    var form = root.querySelector("[data-credit-form]");
    form.reset();
    root.querySelector("[data-credit-contracts]").replaceChildren();
    root.querySelector("[data-credit-person]").hidden = true;
    root._contracts = [];
    pickContract(root, null);
    setStatus(root, "");
    showError(root, "");
    root.querySelector("[data-credit-result]").hidden = true;
    form.hidden = false;
    root.querySelector("[data-credit-document]").focus();
  }

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var root = target && target.closest("[data-commercial-credit]");
    if (!root) return;
    if (target.closest("[data-credit-search]")) { search(root); return; }
    if (target.closest("[data-credit-again]")) { reset(root); return; }
    var pick = target.closest("[data-credit-pick]");
    if (pick) pickContract(root, (root._contracts || [])[Number(pick.value)] || null);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" || !event.target.matches || !event.target.matches("[data-credit-document]")) return;
    event.preventDefault();
    search(event.target.closest("[data-commercial-credit]"));
  });
  document.addEventListener("input", function (event) {
    var root = event.target.closest && event.target.closest("[data-commercial-credit]");
    if (!root) return;
    if (event.target.matches("[data-credit-money]")) formatMoney(event.target, true);
    if (event.target.name === "term") updateTermHint(root);
    if (event.target.matches("[data-credit-document]") && root._contract) {
      // El documento cambió: el contrato elegido ya no corresponde.
      root.querySelector("[data-credit-contracts]").replaceChildren();
      root.querySelector("[data-credit-person]").hidden = true;
      pickContract(root, null);
      setStatus(root, "");
    }
  });
  document.addEventListener("submit", async function (event) {
    var form = event.target;
    if (!form.matches || !form.matches("[data-credit-form]")) return;
    event.preventDefault();
    var root = form.closest("[data-commercial-credit]");
    if (form._saving) return;
    showError(root, "");
    if (!form.elements.contract_id.value) { showError(root, "Busca al propietario por documento y elige el contrato."); return; }
    if (!(Number(String(form.elements.amount.value).replace(/[^\d]/g, "")) > 0)) { showError(root, "Indica el valor solicitado."); form.elements.amount.focus(); return; }
    var term = Number(form.elements.term.value);
    if (!(term >= 1 && term <= 60)) { showError(root, "Indica el plazo solicitado en meses (entre 1 y 60)."); form.elements.term.focus(); return; }
    var submit = root.querySelector("[data-credit-submit]");
    var label = submit.textContent;
    // Los datos se leen antes de deshabilitar: los campos deshabilitados no viajan en el FormData.
    var data = new FormData(form);
    var controls = Array.from(form.querySelectorAll("input,select,button"));
    var disabled = controls.map(function (control) { return control.disabled; });
    form._saving = true;
    controls.forEach(function (control) { control.disabled = true; });
    submit.textContent = "Registrando…";
    try {
      var result = await request("commercial_credit_create", data);
      form.hidden = true;
      var node = root.querySelector("[data-credit-result]");
      var again = element("button", "csg-secondary", "Registrar otra solicitud");
      again.type = "button";
      again.dataset.creditAgain = "";
      node.replaceChildren(element("p", "", result.message || "Solicitud registrada."), again);
      node.hidden = false;
      again.focus();
    } catch (error) {
      showError(root, error.message);
    } finally {
      controls.forEach(function (control, index) { control.disabled = disabled[index]; });
      submit.textContent = label;
      form._saving = false;
    }
  });
  document.addEventListener("focusout", function (event) {
    if (event.target.matches && event.target.matches("[data-commercial-credit] [data-credit-money]")) formatMoney(event.target);
  });
})();
