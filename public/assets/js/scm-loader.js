/*
 * Loader para esperas largas del panel y de las páginas públicas (estilos en scm-loader.css). Úsalo siempre que algo
 * tarde en generarse o cargar (PDF, imágenes, videos…), para que la persona sepa que está pasando algo:
 *   SCMLoader.show({ title: "Generando el PDF", detail: "…", icon: "pdf" | "image" | "video" });
 *   SCMLoader.hide();  SCMLoader.fail("No se pudo…");
 *   SCMLoader.download(url, "archivo.pdf", { title, detail })   // fetch → descarga, con el loader mientras tanto
 * Cualquier enlace con data-scm-loader-download="nombre.pdf" se descarga así (data-scm-loader-title y -detail opcionales).
 */
(function () {
  "use strict";
  if (window.SCMLoader) return;

  var ICONS = {
    pdf: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
    video: '<rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3"/>',
  };
  var root = null;
  var lastFocus = null;

  function build() {
    if (root) return root;
    root = document.createElement("div");
    root.className = "scm-loader";
    root.hidden = true;
    root.setAttribute("role", "status");
    root.setAttribute("aria-live", "polite");
    root.innerHTML = '<div class="scm-loader-card"><div class="scm-loader-ring"><span class="scm-loader-icon" aria-hidden="true"></span></div>'
      + '<p class="scm-loader-title"></p><p class="scm-loader-detail"></p><div class="scm-loader-bar" aria-hidden="true"></div>'
      + '<button type="button" class="scm-loader-close">Cerrar</button></div>';
    root.querySelector(".scm-loader-close").addEventListener("click", hide);
    root.addEventListener("keydown", function (event) { if (event.key === "Escape" && root.classList.contains("is-error")) hide(); });
    document.body.appendChild(root);
    return root;
  }

  function show(options) {
    options = options || {};
    var el = build();
    lastFocus = document.activeElement;
    el.classList.remove("is-error");
    el.querySelector(".scm-loader-icon").innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[options.icon] || ICONS.pdf) + "</svg>";
    el.querySelector(".scm-loader-title").textContent = options.title || "Cargando";
    el.querySelector(".scm-loader-detail").textContent = options.detail || "Esto puede tardar unos segundos.";
    el.setAttribute("aria-busy", "true");
    el.hidden = false;
  }

  function hide() {
    if (!root) return;
    root.hidden = true;
    root.removeAttribute("aria-busy");
    if (lastFocus && typeof lastFocus.focus === "function") lastFocus.focus();
  }

  function fail(message) {
    var el = build();
    el.hidden = false;
    el.classList.add("is-error");
    el.removeAttribute("aria-busy");
    el.querySelector(".scm-loader-title").textContent = "No se pudo completar";
    el.querySelector(".scm-loader-detail").textContent = message || "Intenta de nuevo en unos minutos.";
    el.querySelector(".scm-loader-close").focus();
  }

  async function download(url, filename, options) {
    show(Object.assign({ title: "Generando el PDF", detail: "Estamos preparando el documento. Puede tardar unos segundos.", icon: "pdf" }, options || {}));
    try {
      var response = await fetch(url, { credentials: "same-origin" });
      var type = response.headers.get("Content-Type") || "";
      if (!response.ok || (filename.slice(-4).toLowerCase() === ".pdf" && type.indexOf("application/pdf") !== 0)) {
        throw new Error("No pudimos preparar el archivo en este momento. Intenta de nuevo en unos minutos.");
      }
      var blobUrl = URL.createObjectURL(await response.blob());
      var link = document.createElement("a");
      link.href = blobUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 60000);
      hide();
      return true;
    } catch (error) {
      fail(error && error.message ? error.message : "");
      return false;
    }
  }

  document.addEventListener("click", function (event) {
    var link = event.target.closest && event.target.closest("a[data-scm-loader-download]");
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    event.preventDefault();
    download(link.href, link.getAttribute("data-scm-loader-download") || "archivo.pdf", {
      title: link.getAttribute("data-scm-loader-title") || undefined,
      detail: link.getAttribute("data-scm-loader-detail") || undefined,
    });
  });

  window.SCMLoader = { show: show, hide: hide, fail: fail, download: download };
})();
