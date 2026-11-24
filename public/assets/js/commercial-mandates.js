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
    viewer.remove();
    // Lo que se hizo dentro (enviar al propietario, responder) cambia la revisión: se actualiza el listado.
    if (document.querySelector("[data-cma-list]")) refreshList(listPage);
    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
  }
  function openViewer(id, button) {
    returnFocus = button || document.activeElement;
    if (button) button.disabled = true;
    request("commercial_mandate_view", { id: id }).then(function (result) {
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

  // ── Delegación ─────────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;
    var view = target.closest("[data-cma-view]");
    if (view) { event.preventDefault(); openViewer(view.getAttribute("data-cma-view"), view); return; }
    if (target.closest("[data-cma-viewer-close]") || target.id === "cma-viewer") { closeViewer(); return; }
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
