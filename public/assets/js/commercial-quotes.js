(function () {
  "use strict";
  // Cotizaciones de inmuebles: formulario del popup de la tarea, pestaña Cotizaciones / Mis cotizaciones y visor.
  // El panel reemplaza su contenido al navegar: todo se atiende por delegación sobre el documento.
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var LIST_FILTERS = ["cq_q", "cq_tipo", "cq_empleado", "cq_desde", "cq_hasta"];
  var viewerReturn = null;

  function notify(type, message) {
    if (window.Swal) window.Swal.fire({ toast: true, position: "top-end", timer: 3600, showConfirmButton: false, icon: type, title: message });
  }
  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
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

  // ── Formulario de la tarea ─────────────────────────
  function state(task) {
    if (!task._cqtSelected) task._cqtSelected = [];
    return task._cqtSelected;
  }
  function max(task) { return parseInt(task.getAttribute("data-max") || "20", 10) || 20; }
  function showError(task, message) {
    var box = task.querySelector("[data-cqt-error]");
    if (!box) { notify("error", message); return; }
    box.textContent = message || "";
    box.hidden = !message;
    if (message) box.scrollIntoView({ block: "nearest" });
  }
  function renderTray(task) {
    var selected = state(task);
    var list = task.querySelector("[data-cqt-selected]");
    task.querySelector("[data-cqt-count]").textContent = String(selected.length);
    task.querySelector("[data-cqt-tray-empty]").hidden = selected.length > 0;
    task.querySelector("[data-cqt-send]").disabled = selected.length === 0;
    list.innerHTML = selected.map(function (item, index) {
      return '<li data-code="' + esc(item.code) + '">' +
        (item.photo ? '<img src="' + esc(item.photo) + '" alt="">' : '<span class="cqt-thumb material-symbols-outlined" aria-hidden="true">apartment</span>') +
        '<span class="cqt-selected-text"><strong>' + (index + 1) + ". " + esc(item.label) + "</strong><small>#" + esc(item.code) + " · " + esc(item.price) + "</small></span>" +
        '<button type="button" class="cqt-icon" data-cqt-remove="' + esc(item.code) + '" aria-label="Quitar ' + esc(item.label) + '"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></li>';
    }).join("");
    syncCards(task);
  }
  function syncCards(task) {
    var codes = state(task).map(function (item) { return item.code; });
    task.querySelectorAll("[data-cqt-prop]").forEach(function (card) {
      var button = card.querySelector("[data-cqt-toggle]");
      if (!button) return;
      var on = codes.indexOf(card.getAttribute("data-code")) >= 0;
      card.classList.toggle("is-selected", on);
      button.setAttribute("aria-pressed", on ? "true" : "false");
      button.querySelector("span:last-child").textContent = on ? "Agregado" : "Agregar";
      button.querySelector(".material-symbols-outlined").textContent = on ? "check" : "add";
    });
  }
  function toggle(task, card) {
    var selected = state(task);
    var code = card.getAttribute("data-code");
    var index = selected.findIndex(function (item) { return item.code === code; });
    if (index >= 0) selected.splice(index, 1);
    else if (selected.length >= max(task)) { showError(task, "Puedes cotizar hasta " + max(task) + " inmuebles a la vez."); return; }
    else selected.push({ code: code, label: card.getAttribute("data-label"), price: card.getAttribute("data-price"), photo: card.getAttribute("data-photo") });
    showError(task, "");
    renderTray(task);
  }
  // Permuta solo para venta: con "Arriendo o venta" elegido, si incluye venta; si no, según el tipo de cotización
  // (PropertySearchFilters::allowsSwap). Oculta queda deshabilitada y no se envía.
  function syncSwap(task, form) {
    var type = task.querySelector("[data-cqt-type]");
    var business = form.querySelector("[data-cqt-business]");
    var chosen = business ? business.value : "";
    var sale = chosen ? (chosen === "Venta" || chosen === "Arriendo/Venta") : (type ? type.value === "Venta" : false);
    form.querySelectorAll("[data-cqt-swap]").forEach(function (box) {
      box.style.display = sale ? "" : "none";
      box.querySelectorAll("select").forEach(function (select) {
        select.disabled = !sale;
        if (!sale) select.value = "";
      });
    });
  }
  function search(task, page) {
    var form = task.querySelector("[data-cqt-search]");
    var holder = task.querySelector("[data-cqt-results]");
    syncSwap(task, form);
    var data = new FormData(form);
    var type = task.querySelector("[data-cqt-type]");
    data.set("tipo", type ? type.value : "");
    data.set("ticket_pk", task.getAttribute("data-ticket-pk"));
    data.set("pagina", String(page || 1));
    ["precio_min", "precio_max"].forEach(function (name) { data.set(name, String(data.get(name) || "").replace(/\D/g, "")); });
    holder.setAttribute("aria-busy", "true");
    return request("commercial_quote_properties", data).then(function (result) {
      holder.innerHTML = result.html || "";
      syncCards(task);
    }).catch(function (error) {
      holder.innerHTML = '<p class="cqt-error"></p>';
      holder.querySelector("p").textContent = error.message;
    }).finally(function () { holder.removeAttribute("aria-busy"); });
  }
  function create(task, form) {
    var selected = state(task);
    if (!form.reportValidity()) return;
    if (!selected.length) { showError(task, "Elige al menos un inmueble para cotizar."); return; }
    var send = task.querySelector("[data-cqt-send]");
    var data = new FormData(form);
    selected.forEach(function (item) { data.append("inmuebles[]", item.code); });
    send.disabled = true;
    send.setAttribute("aria-busy", "true");
    showError(task, "");
    request("commercial_quote_create", data).then(function (result) {
      notify("success", result.message || "Cotización creada.");
      var holder = task.parentElement;
      holder.innerHTML = result.html || "";
      var heading = holder.querySelector("h1");
      if (heading) heading.focus({ preventScroll: true });
      app.dispatchEvent(new CustomEvent("scm:task-updated"));
    }).catch(function (error) {
      showError(task, error.message);
      send.disabled = false;
    }).finally(function () { send.removeAttribute("aria-busy"); });
  }
  function formatMoney(field) {
    var digits = field.value.replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 13);
    field.value = digits ? "$ " + digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".") : "";
  }

  window.initCommercialQuoteForm = function (task) {
    if (!task || task._cqtReady) return;
    task._cqtReady = true;
    renderTray(task);
    search(task, 1);
  };

  // ── Listado ────────────────────────────────────────
  function listValues(page) {
    var form = document.querySelector("[data-cqt-list-filters]");
    var values = {};
    if (form) {
      LIST_FILTERS.forEach(function (name) { if (form.elements[name]) values[name] = form.elements[name].value; });
      if (form.elements.portafolio) values.portafolio = form.elements.portafolio.value;
    }
    values.cq_pag = String(page || 1);
    return values;
  }
  function refreshList(page) {
    var holder = document.querySelector("[data-cqt-list]");
    if (!holder) return;
    var values = listValues(page);
    holder.setAttribute("aria-busy", "true");
    request("commercial_quotes_list", values).then(function (result) {
      holder.innerHTML = result.html || "";
      var url = new URL(window.location.href);
      LIST_FILTERS.concat("cq_pag").forEach(function (name) {
        if (values[name] && !(name === "cq_pag" && values[name] === "1")) url.searchParams.set(name, values[name]);
        else url.searchParams.delete(name);
      });
      window.history.replaceState(window.history.state, "", url);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { holder.removeAttribute("aria-busy"); });
  }

  // ── Visor ──────────────────────────────────────────
  function closeViewer() {
    var viewer = document.getElementById("cqt-viewer");
    if (!viewer) return;
    viewer.remove();
    if (viewerReturn && document.contains(viewerReturn)) viewerReturn.focus();
  }
  function openViewer(button) {
    var data = { id: button.getAttribute("data-cqt-view") };
    if (button.getAttribute("data-ticket-pk")) data.ticket_pk = button.getAttribute("data-ticket-pk");
    viewerReturn = button;
    button.disabled = true;
    request("commercial_quote_view", data).then(function (result) {
      closeViewer();
      var viewer = document.createElement("div");
      viewer.id = "cqt-viewer";
      viewer.className = "cqt-viewer";
      viewer.setAttribute("role", "dialog");
      viewer.setAttribute("aria-modal", "true");
      viewer.setAttribute("aria-labelledby", "cqt-viewer-title");
      viewer.innerHTML = result.html || "";
      document.body.appendChild(viewer);
      var close = viewer.querySelector("[data-cqt-viewer-close]");
      if (close) close.focus();
    }).catch(function (error) { notify("error", error.message); }).finally(function () { button.disabled = false; });
  }
  function copy(text) {
    var done = function () { notify("success", "Enlace copiado."); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function () { window.prompt("Copia el enlace:", text); });
    } else {
      window.prompt("Copia el enlace:", text);
    }
  }

  // ── Delegación ─────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;
    var view = target.closest("[data-cqt-view]");
    if (view) { event.preventDefault(); openViewer(view); return; }
    var copyButton = target.closest("[data-cqt-copy]");
    if (copyButton) { event.preventDefault(); copy(copyButton.getAttribute("data-cqt-copy")); return; }
    if (target.closest("[data-cqt-viewer-close]") || target.id === "cqt-viewer") { closeViewer(); return; }
    var task = target.closest("[data-cqt-task]");
    if (task) {
      var toggleButton = target.closest("[data-cqt-toggle]");
      if (toggleButton) { toggle(task, toggleButton.closest("[data-cqt-prop]")); return; }
      var remove = target.closest("[data-cqt-remove]");
      if (remove) {
        var code = remove.getAttribute("data-cqt-remove");
        task._cqtSelected = state(task).filter(function (item) { return item.code !== code; });
        renderTray(task);
        return;
      }
      var pageButton = target.closest("[data-cqt-page]");
      if (pageButton) {
        search(task, parseInt(pageButton.getAttribute("data-cqt-page"), 10)).then(function () {
          var results = task.querySelector("[data-cqt-results]");
          if (results) results.scrollIntoView({ block: "start", behavior: "smooth" });
        });
        return;
      }
    }
    var listPage = target.closest("[data-cqt-list-page]");
    if (listPage) { refreshList(parseInt(listPage.getAttribute("data-cqt-list-page"), 10)); return; }
    if (target.closest("[data-cqt-list-clear]")) {
      var form = document.querySelector("[data-cqt-list-filters]");
      if (form) LIST_FILTERS.forEach(function (name) { if (form.elements[name]) form.elements[name].value = ""; });
      refreshList(1);
    }
  });
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.matches("[data-cqt-list-filters]")) { event.preventDefault(); refreshList(1); return; }
    var task = form.closest("[data-cqt-task]");
    if (!task) return;
    event.preventDefault();
    if (form.matches("[data-cqt-search]")) search(task, 1);
    else if (form.matches("[data-cqt-create]")) create(task, form);
  });
  document.addEventListener("reset", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-cqt-search]")) return;
    var task = form.closest("[data-cqt-task]");
    if (task) window.setTimeout(function () { search(task, 1); }, 0);
  });
  document.addEventListener("change", function (event) {
    var field = event.target;
    if (!(field instanceof Element)) return;
    var task = field.closest("[data-cqt-task]");
    if (!task) return;
    if (field.matches("[data-cqt-type]") || (field.closest("[data-cqt-search]") && field.tagName === "SELECT") || field.matches("[name=ocultar_vencidos]")) search(task, 1);
  });
  document.addEventListener("input", function (event) {
    if (event.target instanceof Element && event.target.matches("[data-cqt-money]")) formatMoney(event.target);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && document.getElementById("cqt-viewer")) { event.stopPropagation(); closeViewer(); }
  }, true);
})();
