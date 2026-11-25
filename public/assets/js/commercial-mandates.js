(function () {
  "use strict";
  // Contratos de mandato: pestaña Mandatos / Mis mandatos (filtros, estados y páginas) y el popup con la vista interna de
  // mandato.php. El enlace ?abrir_mandato=ID (listado de WordPress mientras se migra) abre el popup al cargar el panel.
  // El panel reemplaza su contenido al navegar: todo va por delegación.
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  var LIST_FILTERS = ["ma_q", "ma_estado", "ma_empleado"];
  var returnFocus = null;
  var listPage = 1;

  function notify(type, message) {
    if (window.Swal) window.Swal.fire({ toast: true, position: "top-end", timer: 4200, showConfirmButton: false, icon: type, title: message });
  }
  async function request(action, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
    body.set("action", action);
    body.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: body, credentials: "same-origin" });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("No se pudo procesar la respuesta del servidor."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }

  // ── Popup del mandato ──────────────────────────────
  function closeViewer() {
    var viewer = document.getElementById("cma-viewer");
    if (!viewer) return;
    var closing = viewer.classList.contains("cma-closing");
    viewer.remove();
    if (closing) {
      // Hoja de cierre: solo si se guardó algo allá; en la tarea se vuelve a abrir para ver el estado nuevo.
      if (closingSaved) {
        closingSaved = false;
        if (document.querySelector("[data-cma-list]")) refreshList(listPage);
        if (document.querySelector("[data-ccs-list]")) refreshSheets(sheetsPage);
        if (closingTicket && document.querySelector("[data-cma-task]")) openTask(closingTicket);
        else if (closingTicket && window.SCMCommercialReloadCase) window.SCMCommercialReloadCase(closingTicket);
      }
    } else if (document.querySelector("[data-cma-list]")) {
      // Lo que se hizo dentro (enviar al propietario, responder) cambia la revisión: se actualiza el listado.
      refreshList(listPage);
    }
    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
  }
  function openViewer(id, button) {
    returnFocus = button || document.activeElement;
    if (button) button.disabled = true;
    request("commercial_mandate_view", { id: id, vista: (button && button.getAttribute("data-cma-mode")) || "" }).then(function (result) {
      var old = document.getElementById("cma-viewer");
      if (old) old.remove();
      var viewer = document.createElement("div");
      viewer.id = "cma-viewer";
      viewer.className = "cqt-viewer";
      viewer.setAttribute("role", "dialog");
      viewer.setAttribute("aria-modal", "true");
      viewer.setAttribute("aria-labelledby", "cma-viewer-title");
      viewer.innerHTML = result.html || "";
      document.body.appendChild(viewer);
      var close = viewer.querySelector("[data-cma-viewer-close]");
      if (close) close.focus();
    }).catch(function (error) { notify("error", error.message); }).finally(function () { if (button) button.disabled = false; });
  }

  // ── Hoja de cierre ─────────────────────────────────
  // hoja-cierre.php?mandato=ID lleva a la página del panel inmobiliario (crear o ver la hoja, embed=1): la hoja se hace
  // allá, con sus permisos, políticas y avisos. Esas páginas avisan al guardar (scm-closing-sheet-saved, mismo dominio):
  // al cerrar la ventana se actualiza el listado o la tarea.
  var closingSaved = false;
  var closingTicket = "";
  function openClosing(button) {
    var sheet = button.getAttribute("data-cma-closing-sheet") || "";
    var id = button.getAttribute("data-cma-closing") || "";
    if (!/^[1-9][0-9]{0,17}$/.test(sheet || id)) return;
    returnFocus = button;
    closingSaved = false;
    closingTicket = button.getAttribute("data-ticket-pk") || "";
    var old = document.getElementById("cma-viewer");
    if (old) old.remove();
    var title = button.getAttribute("data-cma-closing-title") || (sheet ? "Hoja de cierre #" + sheet : "Hoja de cierre · Mandato #" + id);
    var url = sheet ? "hoja-cierre.php?numero=" + encodeURIComponent(sheet) : "hoja-cierre.php?mandato=" + encodeURIComponent(id);
    var viewer = document.createElement("div");
    viewer.id = "cma-viewer";
    viewer.className = "cqt-viewer cma-closing";
    viewer.setAttribute("role", "dialog");
    viewer.setAttribute("aria-modal", "true");
    viewer.setAttribute("aria-labelledby", "cma-viewer-title");
    viewer.innerHTML = '<div class="cqt-viewer-box" role="document"><header class="cqt-viewer-head"><h2 id="cma-viewer-title"></h2><div class="cqt-viewer-actions">'
      + '<a class="cqt-secondary" target="_blank" rel="noopener" data-cma-closing-tab><span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>Abrir en otra pestaña</a>'
      + '<button type="button" class="cqt-icon" data-cma-viewer-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div></header>'
      + '<p class="cma-closing-loading" data-cma-closing-loading role="status">Abriendo la hoja de cierre…</p><iframe class="cqt-frame" title=""></iframe></div>';
    viewer.querySelector("#cma-viewer-title").textContent = title;
    viewer.querySelector("[data-cma-closing-tab]").href = url;
    var frame = viewer.querySelector("iframe");
    frame.title = title;
    frame.addEventListener("load", function () {
      var loading = viewer.querySelector("[data-cma-closing-loading]");
      if (loading) loading.remove();
      // Al navegar dentro (Editar, la hoja recién guardada) una página puede llegar sin embed=1: se quita su barra
      // superior igual que con embed (mismo dominio; si no se puede leer, se deja como está).
      try {
        var doc = frame.contentDocument;
        if (doc && doc.body && doc.body.classList.contains("scm-review-page")) doc.body.classList.add("is-embed");
      } catch (_error) {}
    });
    frame.src = url;
    document.body.appendChild(viewer);
    var close = viewer.querySelector("[data-cma-viewer-close]");
    if (close) close.focus();
  }
  window.addEventListener("message", function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.type !== "scm-closing-sheet-saved") return;
    closingSaved = true;
  });

  // ── Listado ────────────────────────────────────────
  function listValues(page) {
    var form = document.querySelector("[data-cma-list-filters]");
    var values = {};
    if (form) {
      LIST_FILTERS.forEach(function (name) { if (form.elements[name]) values[name] = form.elements[name].value; });
      if (form.elements.portafolio) values.portafolio = form.elements.portafolio.value;
    }
    values.ma_pag = String(page || 1);
    return values;
  }
  function refreshList(page) {
    var holder = document.querySelector("[data-cma-list]");
    if (!holder) return;
    listPage = page || 1;
    var values = listValues(listPage);
    holder.setAttribute("aria-busy", "true");
    request("commercial_mandates_list", values).then(function (result) {
      holder.innerHTML = result.html || "";
      var url = new URL(window.location.href);
      LIST_FILTERS.concat("ma_pag").forEach(function (name) {
        if (values[name] && !(name === "ma_pag" && values[name] === "1")) url.searchParams.set(name, values[name]);
        else url.searchParams.delete(name);
      });
      window.history.replaceState(window.history.state, "", url);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { holder.removeAttribute("aria-busy"); });
  }

  // ── Hojas de cierre / Mis cierres ──────────────────
  var SHEET_FILTERS = ["hc_q", "hc_estado", "hc_empleado"];
  var sheetsPage = 1;
  function refreshSheets(page) {
    var holder = document.querySelector("[data-ccs-list]");
    if (!holder) return;
    sheetsPage = page || 1;
    var form = document.querySelector("[data-ccs-list-filters]");
    var values = { hc_pag: String(sheetsPage) };
    if (form) {
      SHEET_FILTERS.forEach(function (name) { if (form.elements[name]) values[name] = form.elements[name].value; });
      if (form.elements.portafolio) values.portafolio = form.elements.portafolio.value;
    }
    holder.setAttribute("aria-busy", "true");
    request("commercial_closing_sheets_list", values).then(function (result) {
      holder.innerHTML = result.html || "";
      var url = new URL(window.location.href);
      SHEET_FILTERS.concat("hc_pag").forEach(function (name) {
        if (values[name] && !(name === "hc_pag" && values[name] === "1")) url.searchParams.set(name, values[name]);
        else url.searchParams.delete(name);
      });
      window.history.replaceState(window.history.state, "", url);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { holder.removeAttribute("aria-busy"); });
  }

  // ── Formulario, ventana de la tarea y firma ────────
  function caseContent() { return document.querySelector("[data-commercial-case-content]"); }
  function showInCase(html) {
    var holder = caseContent();
    if (!holder) return;
    holder.innerHTML = html || "";
    var form = holder.querySelector("[data-cmf]");
    if (form && window.initCommercialMandateForm) window.initCommercialMandateForm(form);
    var heading = holder.querySelector("h1");
    if (heading) heading.focus({ preventScroll: true });
    holder.scrollTop = 0;
  }
  function showInDialog(html) {
    var old = document.getElementById("cma-viewer");
    if (old) old.remove();
    var viewer = document.createElement("div");
    viewer.id = "cma-viewer";
    viewer.className = "cqt-viewer cma-dialog";
    viewer.setAttribute("role", "dialog");
    viewer.setAttribute("aria-modal", "true");
    viewer.setAttribute("aria-label", "Contrato de mandato");
    viewer.innerHTML = '<div class="cqt-viewer-box cma-dialog-box" role="document">' + (html || "") + "</div>";
    document.body.appendChild(viewer);
    var form = viewer.querySelector("[data-cmf]");
    if (form && window.initCommercialMandateForm) window.initCommercialMandateForm(form);
    var heading = viewer.querySelector("h1");
    if (heading) heading.focus();
  }
  function openForm(button) {
    var ticket = button.getAttribute("data-ticket-pk") || "";
    returnFocus = button;
    button.disabled = true;
    request("commercial_mandate_form", { id: button.getAttribute("data-id") || "", ticket_pk: ticket }).then(function (result) {
      if (ticket) showInCase(result.html); else showInDialog(result.html);
    }).catch(function (error) { notify("error", error.message); }).finally(function () { button.disabled = false; });
  }
  function openTask(ticket, flash) {
    request("commercial_mandate_task", { ticket_pk: ticket }).then(function (result) { showInCase(result.html); if (flash) notify("success", flash); })
      .catch(function (error) { notify("error", error.message); });
  }
  function save(form) {
    if (typeof form.validAll === "function" && !form.validAll()) return;
    var submit = form.querySelector("[data-cmf-submit]");
    var data = new FormData(form);
    var ticket = data.get("ticket_pk") || "";
    submit.disabled = true;
    request("commercial_mandate_save", data).then(function (result) {
      notify("success", result.message || "Mandato guardado.");
      if (ticket && result.html) { showInCase(result.html); return; }
      var dialog = document.getElementById("cma-viewer");
      if (dialog) dialog.remove();
      if (document.querySelector("[data-cma-list]")) refreshList(listPage);
    }).catch(function (error) {
      var box = form.querySelector("[data-cmf-error]");
      if (box) { box.textContent = error.message; box.hidden = false; box.scrollIntoView({ block: "nearest" }); } else notify("error", error.message);
    }).finally(function () { submit.disabled = false; });
  }
  // Solo se envía a firma con la hoja de cierre aprobada; la revisión del propietario es opcional y solo se advierte
  // (data-blockers trae lo que falta, data-review-notice el aviso; el servidor lo vuelve a validar).
  function sign(button) {
    var id = button.getAttribute("data-cma-sign");
    var ticket = button.getAttribute("data-ticket-pk") || "";
    var resend = button.getAttribute("data-resend") === "1";
    var blockers = [];
    try { blockers = JSON.parse(button.getAttribute("data-blockers") || "[]"); } catch (_error) {}
    if (blockers.length) {
      if (window.Swal) {
        var list = document.createElement("ul");
        list.style.textAlign = "left";
        blockers.forEach(function (reason) { var li = document.createElement("li"); li.textContent = reason; list.appendChild(li); });
        window.Swal.fire({ title: "Todavía no se puede enviar a firma", html: list, icon: "info", confirmButtonText: "Entendido" });
      } else {
        window.alert("Todavía no se puede enviar a firma:\n• " + blockers.join("\n• "));
      }
      return;
    }
    var notice = button.getAttribute("data-review-notice") || "";
    var text = (resend ? "Se actualizará el contrato que ya está en eSignatures con los datos actuales del mandato #" : "Se enviará el mandato #") + id + " a firma digital: a cada propietario le llega la solicitud por correo y celular."
      + (notice ? "\n\nNota: " + notice : "");
    var go = function () {
      button.disabled = true;
      request("commercial_mandate_sign", { id: id, ticket_pk: ticket }).then(function (result) {
        notify("success", result.message || "Enviado a firma.");
        if (ticket && result.html) showInCase(result.html); else if (document.querySelector("[data-cma-list]")) refreshList(listPage);
      }).catch(function (error) { notify("error", error.message); }).finally(function () { button.disabled = false; });
    };
    if (window.Swal) {
      window.Swal.fire({ title: resend ? "¿Reenviar a firma?" : "¿Enviar a firma?", text: text, icon: "question", showCancelButton: true, confirmButtonText: "Sí, enviar", cancelButtonText: "Cancelar" })
        .then(function (answer) { if (answer.isConfirmed) go(); });
    } else if (window.confirm(text)) {
      go();
    }
  }
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (form instanceof HTMLFormElement && form.matches("[data-cmf]")) { event.preventDefault(); save(form); }
  });

  // ── Delegación ─────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;
    var formButton = target.closest("[data-cma-form]");
    if (formButton) { event.preventDefault(); openForm(formButton); return; }
    var taskButton = target.closest("[data-cma-task-open]");
    if (taskButton) { event.preventDefault(); openTask(taskButton.getAttribute("data-ticket-pk")); return; }
    var signButton = target.closest("[data-cma-sign]");
    if (signButton) { event.preventDefault(); sign(signButton); return; }
    var sheetState = target.closest("[data-ccs-state]");
    if (sheetState) {
      var sheetFilters = document.querySelector("[data-ccs-list-filters]");
      if (sheetFilters && sheetFilters.elements.hc_estado) sheetFilters.elements.hc_estado.value = sheetState.getAttribute("data-ccs-state");
      refreshSheets(1);
      return;
    }
    var sheetPage = target.closest("[data-ccs-list-page]");
    if (sheetPage) { refreshSheets(parseInt(sheetPage.getAttribute("data-ccs-list-page"), 10)); return; }
    if (target.closest("[data-ccs-list-clear]")) {
      var sheetForm = document.querySelector("[data-ccs-list-filters]");
      if (sheetForm) SHEET_FILTERS.forEach(function (name) { if (sheetForm.elements[name]) sheetForm.elements[name].value = ""; });
      refreshSheets(1);
      return;
    }
    var closingButton = target.closest("[data-cma-closing], [data-cma-closing-sheet]");
    if (closingButton) { event.preventDefault(); openClosing(closingButton); return; }
    var view = target.closest("[data-cma-view]");
    if (view) { event.preventDefault(); openViewer(view.getAttribute("data-cma-view"), view); return; }
    if (target.closest("[data-cma-viewer-close]") || target.id === "cma-viewer") { closeViewer(); return; }
    var tab = target.closest("[data-cma-viewer-tab]");
    if (tab) tab.parentNode.querySelectorAll("[data-cma-viewer-tab]").forEach(function (item) { item.setAttribute("aria-pressed", item === tab ? "true" : "false"); });
    var state = target.closest("[data-cma-state]");
    if (state) {
      var filters = document.querySelector("[data-cma-list-filters]");
      if (filters && filters.elements.ma_estado) filters.elements.ma_estado.value = state.getAttribute("data-cma-state");
      refreshList(1);
      return;
    }
    var page = target.closest("[data-cma-list-page]");
    if (page) { refreshList(parseInt(page.getAttribute("data-cma-list-page"), 10)); return; }
    if (target.closest("[data-cma-list-clear]")) {
      var form = document.querySelector("[data-cma-list-filters]");
      if (form) LIST_FILTERS.forEach(function (name) { if (form.elements[name]) form.elements[name].value = ""; });
      refreshList(1);
    }
  });
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (form instanceof HTMLFormElement && form.matches("[data-cma-list-filters]")) { event.preventDefault(); refreshList(1); }
    if (form instanceof HTMLFormElement && form.matches("[data-ccs-list-filters]")) { event.preventDefault(); refreshSheets(1); }
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && document.getElementById("cma-viewer")) { event.stopPropagation(); closeViewer(); }
  }, true);

  // ?abrir_mandato=ID: se abre apenas carga el panel y se quita de la dirección para que recargar no lo vuelva a abrir.
  (function openLinkedMandate() {
    var url = new URL(window.location.href);
    var id = url.searchParams.get("abrir_mandato") || "";
    if (!/^[1-9][0-9]{0,17}$/.test(id)) return;
    url.searchParams.delete("abrir_mandato");
    window.history.replaceState(window.history.state, "", url.pathname + url.search + url.hash);
    openViewer(id, null);
  })();
})();
