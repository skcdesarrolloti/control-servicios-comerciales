(function () {
  "use strict";
  // Promoción (Analítica web y Actividad de portales), adaptación de dashboard-marketing views/analytics.php y
  // views/activity.php. Los filtros se aplican con "Aplicar" (sin enviar al cambiar un campo) y navegan dentro del panel
  // (scm:load-tab); "Registros" se pide aparte a commercial_promo_analytics_logs. Los eventos se delegan en document
  // porque la pestaña llega por AJAX.
  if (window.__commercialPromotionLoaded) return;
  window.__commercialPromotionLoaded = true;

  var app = document.getElementById("scm-app");
  var runtime = {};
  try { runtime = JSON.parse((app && app.getAttribute("data-scm-runtime")) || "{}") || {}; } catch (_error) { runtime = {}; }

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function announce(container, message) {
    var live = container && container.querySelector("[data-cpr-live]");
    if (live) live.textContent = message || "";
  }

  /** Abre otra pantalla del panel sin recargar la página; sin url vuelve a cargar la actual. */
  function navigate(url) {
    var host = document.getElementById("scm-app");
    if (host) host.dispatchEvent(new CustomEvent("scm:load-tab", { detail: { url: url || "" } }));
    else window.location.href = url || window.location.href;
  }

  async function request(action, fields) {
    var data = new FormData();
    Object.keys(fields || {}).forEach(function (key) { data.set(key, fields[key]); });
    data.set("action", action);
    data.set("nonce", runtime.nonce || "");
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var json = null;
    try { json = await response.json(); } catch (_error) { json = null; }
    if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la consulta.");
    return json.data || {};
  }

  // ── Puesta al día ─────────────────────────────────────────────────────────
  // En la carga completa de la página el servidor no resume visitas (tendría la sesión de PHP abierta y frenaría las
  // demás peticiones del usuario): si hay pendientes, la pantalla se vuelve a pedir una vez por AJAX, que sí las resume.
  var pendingSync = document.querySelector("[data-cpr-sync-auto]");
  if (pendingSync) {
    pendingSync.removeAttribute("data-cpr-sync-auto");
    window.setTimeout(function () { navigate(""); }, 0);
  }

  // ── Filtros ───────────────────────────────────────────────────────────────
  document.addEventListener("submit", function (event) {
    var form = event.target.closest && event.target.closest("form[data-cpr-filters]");
    if (!form) return;
    event.preventDefault();
    var container = form.closest(".cpr");
    var from = form.querySelector('[name="pr_desde"]');
    var to = form.querySelector('[name="pr_hasta"]');
    if (from && to && from.value && to.value && from.value > to.value) {
      announce(container, "La fecha inicial no puede ser posterior a la final.");
      from.focus();
      return;
    }
    if (!form.reportValidity()) return;
    announce(container, "");
    var url = new URL(form.getAttribute("action") || window.location.href, window.location.href);
    url.search = "";
    url.searchParams.set("tab", form.getAttribute("data-tab") || "");
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name || field.disabled) return;
      var value = String(field.value || "").trim();
      if (value) url.searchParams.set(field.name, value);
    });
    navigate(url.pathname + url.search);
  });

  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var nav = target.closest("a[data-cpr-nav]");
    if (nav && nav.closest(".cpr")) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
      event.preventDefault();
      navigate(nav.getAttribute("href"));
      return;
    }
    if (target.closest("[data-cpr-reload]")) { event.preventDefault(); navigate(""); return; }
    var logs = target.closest("[data-cpr-logs]");
    if (!logs) return;
    if (target.closest("[data-cpr-logs-load]")) { event.preventDefault(); loadLogs(logs, 0, true); return; }
    if (target.closest("[data-cpr-logs-next]")) { event.preventDefault(); loadLogs(logs, (logs.__cprLogs || {}).index + 1); return; }
    if (target.closest("[data-cpr-logs-prev]")) { event.preventDefault(); loadLogs(logs, Math.max(0, (logs.__cprLogs || {}).index - 1)); }
  });

  // ── Analítica web › Registros (a pedido, paginación por llave) ────────────
  function shortUrl(url) { return String(url || "").replace(/^https?:\/\/(www\.)?/i, ""); }

  function stamp(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ""));
    return match ? match[3] + "/" + match[2] + "/" + match[1] + " " + match[4] + ":" + match[5] : String(value || "");
  }

  function rowHtml(row, network) {
    var chips = (row.registered ? '<span class="cpr-chip">Registrado</span>' : "") +
      (row.bot ? '<span class="cpr-chip is-warn">Robot</span>' : "") +
      (row.internal ? '<span class="cpr-chip is-warn">Interna</span>' : "");
    var place = [row.city, row.country].filter(Boolean).join(", ");
    var object = row.object_id ? '<span class="cpr-cell-sub">Inmueble ' + esc(row.object_ref || ("ID " + row.object_id)) + "</span>" : "";
    return "<tr data-cpr-log-row>" +
      "<td>" + esc(stamp(row.fecha)) + chips + "</td>" +
      '<td><span class="cpr-cell-main">' + esc(row.campaign_name) + "</span>" + (row.campaign_name !== row.campaign ? '<span class="cpr-cell-sub">' + esc(row.campaign) + "</span>" : "") + "</td>" +
      '<td><span class="cpr-cell-main">' + esc(row.source) + '</span><span class="cpr-cell-sub">' + esc(row.medium) + "</span></td>" +
      '<td><span class="cpr-cell-main cpr-url" title="' + esc(row.page_url) + '">' + esc(shortUrl(row.page_url) || "Sin URL") + "</span>" + object + "</td>" +
      '<td><span class="cpr-cell-sub cpr-url" title="' + esc(row.referrer) + '">' + esc(shortUrl(row.referrer) || "Directo") + "</span></td>" +
      "<td>" + esc(place || "Sin ubicación") + "</td>" +
      (network ? '<td data-cpr-log-ip>' + esc(row.ip || "") + '</td><td><span class="cpr-ua" title="' + esc(row.user_agent || "") + '">' + esc(row.user_agent || "") + "</span></td>" : "") +
      "</tr>";
  }

  async function loadLogs(section, index, reset) {
    var state = section.__cprLogs;
    if (reset || !state) state = section.__cprLogs = { cursors: [""], index: 0, busy: false };
    if (state.busy || index < 0 || index >= state.cursors.length) return;
    var body = section.querySelector("[data-cpr-logs-body]");
    var rows = section.querySelector("[data-cpr-logs-rows]");
    var note = section.querySelector("[data-cpr-logs-note]");
    var prev = section.querySelector("[data-cpr-logs-prev]");
    var next = section.querySelector("[data-cpr-logs-next]");
    var load = section.querySelector("[data-cpr-logs-load]");
    var network = section.getAttribute("data-network") === "1";
    var filters = {};
    try { filters = JSON.parse(section.getAttribute("data-filters") || "{}") || {}; } catch (_error) { filters = {}; }
    state.busy = true;
    [prev, next, load].forEach(function (button) { if (button) button.disabled = true; });
    section.setAttribute("aria-busy", "true");
    try {
      var data = await request("commercial_promo_analytics_logs", Object.assign({}, filters, { cursor: state.cursors[index] }));
      state.index = index;
      state.cursors = state.cursors.slice(0, index + 1);
      if (data.next_cursor) state.cursors.push(data.next_cursor);
      body.hidden = false;
      rows.innerHTML = (data.rows || []).length
        ? data.rows.map(function (row) { return rowHtml(row, network); }).join("")
        : '<tr><td colspan="' + (network ? 8 : 6) + '" class="cpr-empty">No hay visitas en este tramo con los filtros elegidos.</td></tr>';
      if (note) {
        note.hidden = !data.clamped;
        note.textContent = data.clamped ? "Los registros abarcan como máximo 90 días: se muestran desde el " + stamp(data.from + " 00:00").slice(0, 10) + "." : "";
      }
      section.querySelector("[data-cpr-logs-page]").textContent = "Página " + (index + 1);
      if (load) load.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">refresh</span>Actualizar registros';
    } catch (error) {
      body.hidden = false;
      rows.innerHTML = '<tr><td colspan="' + (network ? 8 : 6) + '" class="cpr-empty">' + esc(error.message) + "</td></tr>";
    } finally {
      state.busy = false;
      section.removeAttribute("aria-busy");
      if (load) load.disabled = false;
      if (prev) prev.disabled = state.index <= 0;
      if (next) next.disabled = state.index + 1 >= state.cursors.length;
    }
  }
})();
