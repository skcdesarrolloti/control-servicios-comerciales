(function () {
  "use strict";
  // Estudios de aseguradora: ventana del popup de la tarea (crear, responder, editar y desistir), visor, pestaña Estudios
  // / Mis estudios y su popup de desistir. El panel reemplaza su contenido al navegar: todo va por delegación.
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var LIST_FILTERS = ["es_q", "es_estado", "es_aseguradora", "es_empleado", "es_desde", "es_hasta"];
  var ACTIONS = { create: "commercial_study_create", edit: "commercial_study_update", respond: "commercial_study_respond", desist: "commercial_study_desist" };
  var returnFocus = null;
  var searchTimer = null;

  function notify(type, message) {
    if (window.Swal) window.Swal.fire({ toast: true, position: "top-end", timer: 4200, showConfirmButton: false, icon: type, title: message });
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
  function showError(form, message) {
    var box = form.querySelector("[data-cst-error]");
    if (!box) { if (message) notify("error", message); return; }
    box.textContent = message || "";
    box.hidden = !message;
    if (message) box.scrollIntoView({ block: "nearest" });
  }
  function formatMoney(field) {
    var digits = field.value.replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 13);
    field.value = digits ? "$ " + digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".") : "";
  }

  // ── Ventana de la tarea ────────────────────────────
  function togglePanel(task, key) {
    var panel = task.querySelector('[data-cst-panel="' + key + '"]');
    if (!panel) return;
    var open = panel.hidden;
    var card = panel.closest("[data-cst-card]") || task;
    card.querySelectorAll("[data-cst-panel]").forEach(function (other) { if (other !== panel) other.hidden = true; });
    card.querySelectorAll("[data-cst-toggle]").forEach(function (button) { button.setAttribute("aria-expanded", "false"); });
    panel.hidden = !open;
    var trigger = card.querySelector('.cst-actions [data-cst-toggle="' + key + '"]');
    if (trigger) trigger.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) {
      var first = panel.querySelector("select, input:not([type=hidden]), textarea");
      if (first) first.focus();
    } else if (trigger) {
      trigger.focus();
    }
  }
  function syncResponse(form) {
    var select = form.querySelector("[data-cst-response]");
    var value = select ? select.value : "";
    form.querySelectorAll("[data-cst-when]").forEach(function (block) {
      block.hidden = (block.getAttribute("data-cst-when") || "").split(" ").indexOf(value) < 0;
    });
  }
  function submitForm(form) {
    var kind = form.getAttribute("data-cst-form");
    var action = ACTIONS[kind];
    if (!action) return;
    if (kind === "desist" && !form.elements.confirmar.checked) { showError(form, "Confirma que quieres desistir el negocio."); return; }
    var docError = checkDocuments(form);
    if (docError) { showError(form, docError); return; }
    var uploading = Array.prototype.some.call(form.querySelectorAll("[data-cst-doc]"), function (input) { return !input.closest("[hidden]") && input.files && input.files.length; });
    var data = new FormData(form);
    // Los archivos de un bloque oculto (otra respuesta) no se envían.
    form.querySelectorAll("[data-cst-doc]").forEach(function (input) { if (input.closest("[hidden]")) data.delete(input.name); });
    if (data.has("valor")) data.set("valor", String(data.get("valor") || "").replace(/\D/g, ""));
    var button = form.querySelector("button[type=submit]");
    if (button) { button.disabled = true; button.setAttribute("aria-busy", "true"); }
    showError(form, "");
    var task = form.closest("[data-cst-task]");
    var dialog = form.closest("#cst-dialog");
    if (uploading && window.SCMLoader) window.SCMLoader.show({ title: "Subiendo los documentos", detail: "Guardamos el asegurable, los servicios públicos y el documento del arrendatario. Puede tardar unos segundos.", icon: "pdf" });
    request(action, data).then(function (result) {
      notify("success", result.message || "Listo.");
      if (task && result.html) {
        var holder = task.parentElement;
        holder.innerHTML = result.html;
        var flash = holder.querySelector(".cst-flash") || holder.querySelector("h1");
        if (flash) { flash.setAttribute("tabindex", "-1"); flash.focus({ preventScroll: false }); }
      }
      // En la tarea, el listado de fondo se actualiza; en la pestaña Estudios basta recargar la tabla.
      if (dialog) { closeDialog(); refreshList(currentPage()); }
      else app.dispatchEvent(new CustomEvent("scm:task-updated"));
    }).catch(function (error) {
      showError(form, error.message);
    }).finally(function () {
      if (uploading && window.SCMLoader) window.SCMLoader.hide();
      if (button && document.contains(button)) { button.disabled = false; button.removeAttribute("aria-busy"); }
    });
  }
  // Antes de subir: al aprobar, el inmueble y los tres documentos son obligatorios; en todos, el máximo de archivos de cada uno.
  function checkDocuments(form) {
    var select = form.querySelector("[data-cst-response]");
    var approving = select && select.value === "Aprobado";
    var code = form.querySelector("[data-cst-property-code]");
    if (approving && code && !code.value) { form.querySelector("[data-cst-property-search]").focus(); return "Elige el inmueble que se va a entregar."; }
    var inputs = form.querySelectorAll("[data-cst-doc]");
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      if (input.closest("[hidden]")) continue;
      var count = input.files ? input.files.length : 0;
      var title = input.getAttribute("data-cst-doc-title") || "Documento";
      var max = parseInt(input.getAttribute("data-cst-doc-max") || "1", 10);
      if (approving && count === 0) { input.focus(); return "Sube " + title.toLowerCase() + "."; }
      if (count > max) { input.focus(); return title + ": sube máximo " + max + (max === 1 ? " archivo." : " archivos."); }
    }
    return "";
  }
  function copy(text) {
    var done = function () { notify("success", "Enlace copiado. Compártelo con el cliente."); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function () { window.prompt("Copia el enlace:", text); });
    } else {
      window.prompt("Copia el enlace:", text);
    }
  }
  function searchProperties(field) {
    var box = field.closest("[data-cst-property]");
    var task = field.closest("[data-cst-task]");
    var list = box.querySelector("[data-cst-property-results]");
    var q = field.value.trim();
    if (q.length < 2) { list.innerHTML = ""; return; }
    list.setAttribute("aria-busy", "true");
    request("commercial_study_properties", { ticket_pk: task.getAttribute("data-ticket-pk"), q: q }).then(function (result) {
      if (field.value.trim() === q) list.innerHTML = result.html || "";
    }).catch(function (error) {
      list.innerHTML = "";
      var item = document.createElement("li");
      item.className = "cqt-error";
      item.textContent = error.message;
      list.appendChild(item);
    }).finally(function () { list.removeAttribute("aria-busy"); });
  }
  function pickProperty(button) {
    var box = button.closest("[data-cst-property]");
    var form = button.closest("form");
    box.querySelector("[data-cst-property-code]").value = button.getAttribute("data-cst-pick");
    var picked = box.querySelector("[data-cst-property-picked]");
    picked.textContent = "Inmueble elegido: " + button.getAttribute("data-label");
    picked.hidden = false;
    box.querySelector("[data-cst-property-results]").innerHTML = "";
    var services = (button.getAttribute("data-services") || "").split("|").filter(Boolean);
    form.querySelectorAll("[data-cst-service]").forEach(function (check) { check.checked = services.indexOf(check.value) >= 0; });
    var next = form.querySelector("[name=ubicacion_llaves]");
    if (next) next.focus();
  }

  // ── Visor y popup de desistir ──────────────────────
  function closeViewer() {
    var viewer = document.getElementById("cst-viewer");
    if (!viewer) return;
    viewer.remove();
    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
  }
  function openViewer(button) {
    var data = { id: button.getAttribute("data-cst-view") };
    if (button.getAttribute("data-ticket-pk")) data.ticket_pk = button.getAttribute("data-ticket-pk");
    returnFocus = button;
    button.disabled = true;
    request("commercial_study_view", data).then(function (result) {
      closeViewer();
      var viewer = document.createElement("div");
      viewer.id = "cst-viewer";
      viewer.className = "cqt-viewer";
      viewer.setAttribute("role", "dialog");
      viewer.setAttribute("aria-modal", "true");
      viewer.setAttribute("aria-labelledby", "cst-viewer-title");
      viewer.innerHTML = result.html || "";
      document.body.appendChild(viewer);
      var close = viewer.querySelector("[data-cst-viewer-close]");
      if (close) close.focus();
    }).catch(function (error) { notify("error", error.message); }).finally(function () { button.disabled = false; });
  }
  // Un documento del estudio dentro del panel, sin abrir otra pestaña.
  function openFile(button) {
    returnFocus = button;
    closeViewer();
    var viewer = document.createElement("div");
    viewer.id = "cst-viewer";
    viewer.className = "cqt-viewer";
    viewer.setAttribute("role", "dialog");
    viewer.setAttribute("aria-modal", "true");
    viewer.setAttribute("aria-labelledby", "cst-viewer-title");
    var box = document.createElement("div");
    box.className = "cqt-viewer-box";
    box.setAttribute("role", "document");
    var head = document.createElement("header");
    head.className = "cqt-viewer-head";
    var title = document.createElement("h2");
    title.id = "cst-viewer-title";
    title.textContent = button.getAttribute("data-cst-file-title") || "Documento";
    var actions = document.createElement("div");
    actions.className = "cqt-viewer-actions";
    actions.innerHTML = '<button type="button" class="cqt-icon" data-cst-viewer-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>';
    head.appendChild(title);
    head.appendChild(actions);
    var frame = document.createElement("iframe");
    frame.className = "cqt-frame";
    frame.title = title.textContent;
    frame.src = button.getAttribute("data-cst-file");
    box.appendChild(head);
    box.appendChild(frame);
    viewer.appendChild(box);
    document.body.appendChild(viewer);
    actions.querySelector("button").focus();
  }
  function closeDialog() {
    var dialog = document.getElementById("cst-dialog");
    if (!dialog) return;
    dialog.remove();
    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
  }
  function openDesist(button) {
    returnFocus = button;
    button.disabled = true;
    request("commercial_study_desist_form", { id: button.getAttribute("data-cst-desist") }).then(function (result) {
      closeDialog();
      var dialog = document.createElement("div");
      dialog.id = "cst-dialog";
      dialog.className = "cqt-viewer cst-dialog";
      dialog.setAttribute("role", "dialog");
      dialog.setAttribute("aria-modal", "true");
      dialog.setAttribute("aria-label", "Desistir negocio");
      dialog.innerHTML = '<div class="cqt cst cst-dialog-box">' + (result.html || "") + "</div>";
      document.body.appendChild(dialog);
      var first = dialog.querySelector("select");
      if (first) first.focus();
    }).catch(function (error) { notify("error", error.message); }).finally(function () { button.disabled = false; });
  }

  // ── Listado ────────────────────────────────────────
  var listPage = 1;
  function currentPage() { return listPage; }
  function listValues(page) {
    var form = document.querySelector("[data-cst-list-filters]");
    var values = {};
    if (form) {
      LIST_FILTERS.forEach(function (name) { if (form.elements[name]) values[name] = form.elements[name].value; });
      if (form.elements.portafolio) values.portafolio = form.elements.portafolio.value;
    }
    values.es_pag = String(page || 1);
    return values;
  }
  function refreshList(page) {
    var holder = document.querySelector("[data-cst-list]");
    if (!holder) return;
    listPage = page || 1;
    var values = listValues(listPage);
    holder.setAttribute("aria-busy", "true");
    request("commercial_studies_list", values).then(function (result) {
      holder.innerHTML = result.html || "";
      var url = new URL(window.location.href);
      LIST_FILTERS.concat("es_pag").forEach(function (name) {
        if (values[name] && !(name === "es_pag" && values[name] === "1")) url.searchParams.set(name, values[name]);
        else url.searchParams.delete(name);
      });
      window.history.replaceState(window.history.state, "", url);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { holder.removeAttribute("aria-busy"); });
  }

  // ── Delegación ─────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;
    var view = target.closest("[data-cst-view]");
    if (view) { event.preventDefault(); openViewer(view); return; }
    if (target.closest("[data-cst-viewer-close]") || target.id === "cst-viewer") { closeViewer(); return; }
    var file = target.closest("[data-cst-file]");
    if (file) { event.preventDefault(); openFile(file); return; }
    var copyButton = target.closest("[data-cst-copy]");
    if (copyButton) { event.preventDefault(); copy(copyButton.getAttribute("data-cst-copy")); return; }
    if (target.closest("[data-cst-dialog-close]") || target.id === "cst-dialog") { closeDialog(); return; }
    var desist = target.closest("[data-cst-desist]");
    if (desist) { openDesist(desist); return; }
    var task = target.closest("[data-cst-task]");
    if (task) {
      var toggle = target.closest("[data-cst-toggle]");
      if (toggle) { togglePanel(task, toggle.getAttribute("data-cst-toggle")); return; }
      var pick = target.closest("[data-cst-pick]");
      if (pick) { pickProperty(pick); return; }
    }
    var state = target.closest("[data-cst-state]");
    if (state) {
      var filters = document.querySelector("[data-cst-list-filters]");
      if (filters && filters.elements.es_estado) filters.elements.es_estado.value = state.getAttribute("data-cst-state");
      refreshList(1);
      return;
    }
    var page = target.closest("[data-cst-list-page]");
    if (page) { refreshList(parseInt(page.getAttribute("data-cst-list-page"), 10)); return; }
    if (target.closest("[data-cst-list-clear]")) {
      var form = document.querySelector("[data-cst-list-filters]");
      if (form) LIST_FILTERS.forEach(function (name) { if (form.elements[name]) form.elements[name].value = ""; });
      refreshList(1);
    }
  });
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.matches("[data-cst-list-filters]")) { event.preventDefault(); refreshList(1); return; }
    if (!form.matches("[data-cst-form]")) return;
    event.preventDefault();
    submitForm(form);
  });
  document.addEventListener("change", function (event) {
    var field = event.target;
    if (field instanceof Element && field.matches("[data-cst-response]")) syncResponse(field.closest("form"));
  });
  document.addEventListener("input", function (event) {
    var field = event.target;
    if (!(field instanceof Element)) return;
    if (field.matches("[data-cst-money]")) formatMoney(field);
    if (field.matches("[data-cst-property-search]")) {
      window.clearTimeout(searchTimer);
      var box = field.closest("[data-cst-property]");
      box.querySelector("[data-cst-property-code]").value = "";
      box.querySelector("[data-cst-property-picked]").hidden = true;
      searchTimer = window.setTimeout(function () { searchProperties(field); }, 300);
    }
  });
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    if (document.getElementById("cst-dialog")) { event.stopPropagation(); closeDialog(); return; }
    if (document.getElementById("cst-viewer")) { event.stopPropagation(); closeViewer(); }
  }, true);
})();
