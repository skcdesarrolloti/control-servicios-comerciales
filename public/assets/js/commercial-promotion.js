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
      // Medio de la lista única y, debajo, lo que llegó en el enlace (utm_source · utm_medium) tal cual.
      '<td><span class="cpr-cell-main">' + esc(row.medio || row.source) + '</span><span class="cpr-cell-sub" title="utm_source · utm_medium">' + esc(row.source + " · " + row.medium) + "</span></td>" +
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

  // ── Material POP ──────────────────────────────────────────────────────────
  // Adaptación de los modales de dashboard-marketing views/inventory.php: cada formulario abre en un <dialog> de la
  // misma pantalla, guarda por AJAX (commercial_pop_*) y, al terminar, vuelve a cargar la sección con los datos nuevos.
  function notify(type, message) {
    var core = window.SCMAdminCore || {};
    if (typeof core.scmNotify === "function") core.scmNotify(type, message);
    else announce(document.querySelector(".cpr"), message);
  }

  function dialogFor(name) {
    return document.querySelector('[data-pop-dialog="' + name + '"]');
  }

  function openDialog(dialog) {
    var error = dialog.querySelector("[data-pop-error]");
    if (error) { error.hidden = true; error.textContent = ""; }
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
    var first = dialog.querySelector("input:not([type=hidden]):not([disabled]), select, textarea");
    if (first) first.focus();
  }

  function closeDialog(dialog) {
    if (dialog && typeof dialog.close === "function") dialog.close();
    else if (dialog) dialog.removeAttribute("open");
  }

  function productData(button) {
    try { return JSON.parse(button.getAttribute("data-product") || "{}") || {}; } catch (_error) { return {}; }
  }

  function setField(form, name, value) {
    var field = form.querySelector('[name="' + name + '"]');
    if (!field) return;
    if (field.type === "checkbox") field.checked = !!value;
    else field.value = value == null ? "" : String(value);
  }

  function localNow() {
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
  }

  function addLine(dialog) {
    var template = dialog.querySelector("[data-pop-line-template]");
    var list = dialog.querySelector("[data-pop-line-list]");
    list.appendChild(template.content.cloneNode(true));
    var lines = list.querySelectorAll("[data-pop-line]");
    lines.forEach(function (line) { line.querySelector("[data-pop-line-remove]").hidden = lines.length === 1; });
    return lines[lines.length - 1];
  }

  var opener = {
    product: function (button) {
      var dialog = dialogFor("product");
      var form = dialog.querySelector("form");
      var data = button.hasAttribute("data-product") ? productData(button) : {};
      var editing = Number(data.id || 0) > 0;
      form.reset();
      dialog.querySelector("[data-pop-title]").textContent = editing ? "Editar producto" : "Agregar producto";
      dialog.querySelectorAll("[data-pop-create-only]").forEach(function (node) { node.hidden = editing; });
      ["id", "name", "sku", "unit", "minimum_stock", "price", "points", "description"].forEach(function (name) {
        if (editing || name === "id") setField(form, name, name === "id" ? (data.id || 0) : data[name]);
      });
      if (editing) {
        var type = form.querySelector('[name="type"]');
        if (data.type && !Array.prototype.some.call(type.options, function (option) { return option.value === data.type; })) {
          // La categoría del producto ya no está en la lista de Configuración: se conserva para no cambiarla sin querer.
          type.add(new Option(data.type, data.type));
        }
        type.value = data.type || "";
        setField(form, "sync_pph", data.sync_pph);
      }
      dialog.querySelector("[data-pop-image-hint]").textContent = editing && data.image_url ? "JPG, PNG o WebP. Si no eliges otra, se conserva la actual." : "JPG, PNG o WebP.";
      openDialog(dialog);
    },
    adjust: function (button) {
      var dialog = dialogFor("adjust");
      var form = dialog.querySelector("form");
      var data = productData(button);
      form.reset();
      setField(form, "product_id", data.id);
      dialog.querySelector("[data-pop-lead]").textContent = data.name + ": hay " + data.quantity + " " + (data.unit || "unidad") + ".";
      openDialog(dialog);
    },
    delivery: function () {
      var dialog = dialogFor("delivery");
      var form = dialog.querySelector("form");
      form.reset();
      dialog.querySelector("[data-pop-line-list]").innerHTML = "";
      addLine(dialog);
      form.querySelector("[data-pop-now]").value = localNow();
      form.querySelector("[data-pop-now]").max = localNow();
      openDialog(dialog);
    },
    archive: function (button) { confirmDialog("commercial_pop_product_active", "Archivar producto", productData(button), false); },
    restore: function (button) { confirmDialog("commercial_pop_product_active", "Reactivar producto", productData(button), true); },
    cancel: function (button) {
      var dialog = dialogFor("confirm");
      var form = dialog.querySelector("form");
      form.reset();
      form.setAttribute("data-pop-form", "commercial_pop_delivery_cancel");
      setField(form, "delivery_id", button.getAttribute("data-delivery"));
      setField(form, "product_id", "");
      dialog.querySelector("[data-pop-title]").textContent = "Anular entrega";
      dialog.querySelector("[data-pop-lead]").textContent = button.getAttribute("data-label") || "";
      dialog.querySelector("[data-pop-confirm-text]").textContent = "Entiendo que la entrega queda anulada y sus productos vuelven a las existencias. Queda el registro con el motivo.";
      openDialog(dialog);
    },
  };

  function confirmDialog(action, title, data, active) {
    var dialog = dialogFor("confirm");
    var form = dialog.querySelector("form");
    form.reset();
    form.setAttribute("data-pop-form", action);
    setField(form, "product_id", data.id);
    setField(form, "delivery_id", "");
    setField(form, "active", active ? "1" : "0");
    dialog.querySelector("[data-pop-title]").textContent = title;
    dialog.querySelector("[data-pop-lead]").textContent = data.name || "";
    dialog.querySelector("[data-pop-confirm-text]").textContent = active
      ? "Entiendo que el producto vuelve a ofrecerse para entregas."
      : "Entiendo que el producto deja de ofrecerse para entregas. Sus existencias y su historial se conservan.";
    openDialog(dialog);
  }

  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest || !target.closest(".cpr, .cpr-dialog")) return;
    var open = target.closest("[data-pop-open]");
    if (open) {
      event.preventDefault();
      var kind = open.getAttribute("data-pop-open");
      if (opener[kind]) opener[kind](open);
      return;
    }
    if (target.closest("[data-pop-close]")) { closeDialog(target.closest("dialog")); return; }
    var photo = target.closest("[data-pop-photo]");
    if (photo) {
      var viewer = dialogFor("photo");
      viewer.querySelector("[data-pop-photo-img]").src = photo.getAttribute("data-pop-photo");
      openDialog(viewer);
      return;
    }
    if (target.closest("[data-pop-line-add]")) { addLine(target.closest("dialog")).querySelector("select").focus(); return; }
    var remove = target.closest("[data-pop-line-remove]");
    if (remove) {
      var dialog = remove.closest("dialog");
      remove.closest("[data-pop-line]").remove();
      var lines = dialog.querySelectorAll("[data-pop-line]");
      lines.forEach(function (line) { line.querySelector("[data-pop-line-remove]").hidden = lines.length === 1; });
      return;
    }
    var sync = target.closest("[data-pop-sync]");
    if (sync) {
      event.preventDefault();
      sync.disabled = true;
      request("commercial_pop_sync", {}).then(function (data) {
        notify("success", data.message);
        navigate("");
      }).catch(function (error) {
        notify("error", error.message);
        sync.disabled = false;
      });
    }
  });

  // Destinatario: con un funcionario de la lista se guarda su id_empleado; al escribir otro nombre se borra.
  document.addEventListener("input", function (event) {
    var input = event.target;
    if (!input || !input.matches || !input.matches("[data-pop-recipient]")) return;
    var form = input.form;
    var match = Array.prototype.find.call(document.querySelectorAll("#cpr-pop-people option"), function (option) { return option.value === input.value; });
    form.querySelector('[name="recipient_id"]').value = match ? match.getAttribute("data-id") : "";
    if (match) form.querySelector("[data-pop-recipient-type]").value = "Funcionario";
  });

  document.addEventListener("submit", async function (event) {
    var form = event.target.closest && event.target.closest("form[data-pop-form]");
    if (!form) return;
    event.preventDefault();
    var dialog = form.closest("dialog");
    var error = form.querySelector("[data-pop-error]");
    var submit = form.querySelector('[type="submit"]');
    var fail = function (message) {
      error.textContent = message;
      error.hidden = false;
    };
    if (!form.reportValidity()) return;
    var tooMany = Array.prototype.find.call(form.querySelectorAll("input[type=file][data-pop-max]"), function (input) {
      return input.files && input.files.length > Number(input.getAttribute("data-pop-max"));
    });
    if (tooMany) { fail(tooMany.getAttribute("data-pop-max") === "1" ? "Elige una sola imagen." : "Puedes adjuntar máximo " + tooMany.getAttribute("data-pop-max") + " fotos."); return; }
    var data = new FormData(form);
    if (form.querySelector("[data-pop-lines]")) {
      var items = [];
      var stockError = "";
      form.querySelectorAll("[data-pop-line]").forEach(function (line) {
        var select = line.querySelector("[data-pop-line-product]");
        var quantity = Number(line.querySelector("[data-pop-line-quantity]").value || 0);
        var option = select.options[select.selectedIndex];
        if (option && quantity > Number(option.getAttribute("data-stock") || 0)) stockError = "No hay suficientes unidades de " + option.textContent.split(" — ")[0] + ".";
        items.push({ product_id: Number(select.value), quantity: quantity });
      });
      if (stockError) { fail(stockError); return; }
      data.set("items", JSON.stringify(items));
    }
    data.set("action", form.getAttribute("data-pop-form"));
    data.set("nonce", runtime.nonce || "");
    submit.disabled = true;
    error.hidden = true;
    try {
      var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
      var json = null;
      try { json = await response.json(); } catch (_error) { json = null; }
      if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo guardar. Intenta de nuevo.");
      closeDialog(dialog);
      notify("success", json.data.message || "Cambio guardado.");
      navigate("");
    } catch (problem) {
      fail(problem.message);
    } finally {
      submit.disabled = false;
    }
  });

  // ── Redes sociales: sincronizar y conectar ────────────────────────────────
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var sync = target.closest("[data-social-sync]");
    if (sync) {
      event.preventDefault();
      var label = sync.innerHTML;
      sync.disabled = true;
      sync.textContent = "Sincronizando… puede tardar unos minutos";
      request("commercial_social_sync", { network: sync.getAttribute("data-social-sync") }).then(function (data) {
        var warnings = data.warnings || [];
        notify(warnings.length ? "warning" : "success", [data.message].concat(warnings).join(" "));
        navigate("");
      }).catch(function (error) {
        notify("error", error.message);
        sync.disabled = false;
        sync.innerHTML = label;
      });
      return;
    }
    var connect = target.closest("[data-social-connect]");
    if (connect) {
      event.preventDefault();
      window.location.href = connect.getAttribute("data-social-connect") + "&nonce=" + encodeURIComponent(runtime.nonce || "");
      return;
    }
    var disconnect = target.closest("[data-social-disconnect]");
    if (disconnect) {
      event.preventDefault();
      var dialog = document.querySelector("[data-social-dialog]");
      dialog.querySelector("form").reset();
      dialog.querySelector('[name="network"]').value = disconnect.getAttribute("data-social-disconnect");
      dialog.querySelector("[data-social-error]").hidden = true;
      dialog.showModal();
      return;
    }
    if (target.closest("[data-social-close]")) closeDialog(target.closest("dialog"));
  });

  document.addEventListener("submit", function (event) {
    var form = event.target.closest && event.target.closest("form[data-social-disconnect-form]");
    if (!form) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    request("commercial_social_disconnect", { network: form.querySelector('[name="network"]').value, confirm: "1" }).then(function (data) {
      closeDialog(form.closest("dialog"));
      notify("success", data.message);
      navigate("");
    }).catch(function (error) {
      var box = form.querySelector("[data-social-error]");
      box.textContent = error.message;
      box.hidden = false;
    });
  });

  // ── Banco de piezas ───────────────────────────────────────────────────────
  // Adaptación de los modales de dashboard-marketing views/branding.php: cada ventana guarda por AJAX
  // (commercial_piece_*) y al terminar vuelve a cargar la pantalla con los filtros que tenía.
  function pieceDialog(name) {
    return document.querySelector('[data-piece-dialog="' + name + '"]');
  }

  function openPiece(dialog) {
    var error = dialog.querySelector("[data-piece-error]");
    if (error) { error.hidden = true; error.textContent = ""; }
    dialog.showModal();
  }

  function complianceLabel(form) {
    var range = form.querySelector("[data-piece-compliance]");
    form.querySelector("[data-piece-compliance-output]").textContent = range.value + " %";
  }

  function openPieceForm(button) {
    var dialog = pieceDialog("form");
    var form = dialog.querySelector("form");
    var data = {};
    try { data = JSON.parse(button.getAttribute("data-piece") || "{}") || {}; } catch (_error) { data = {}; }
    var editing = Number(data.id || 0) > 0;
    form.reset();
    ["title", "asset_code", "description", "category", "status", "campaign_name", "designer_name", "approver_name", "implementer_name", "creation_date", "approval_date", "publication_date", "implementation_date", "brand_compliance", "views_count", "reach_count", "clicks_count", "leads_count", "referrals_count", "acquisitions_count", "observations"].forEach(function (name) {
      if (editing) setField(form, name, data[name]);
    });
    setField(form, "id", editing ? data.id : 0);
    if (!editing) form.querySelector('[name="creation_date"]').value = localNow().slice(0, 10);
    form.querySelectorAll('[name="channels[]"]').forEach(function (box) { box.checked = (data.channels || []).indexOf(box.value) >= 0; });
    var file = form.querySelector("[data-piece-file]");
    file.required = !editing;
    form.querySelector("[data-piece-file-label]").textContent = editing ? "Reemplazar arte final" : "Arte final *";
    dialog.querySelector("[data-piece-title]").textContent = editing ? "Editar pieza" : "Subir pieza";
    complianceLabel(form);
    openPiece(dialog);
  }

  function openPieceDetail(button) {
    var dialog = pieceDialog("detail");
    var body = dialog.querySelector("[data-piece-detail-body]");
    var template = document.querySelector('[data-piece-detail="' + button.getAttribute("data-id") + '"]');
    dialog.querySelector("[data-piece-title]").textContent = button.getAttribute("data-title") || "Detalle de la pieza";
    body.innerHTML = "";
    if (template) body.appendChild(template.content.cloneNode(true));
    body.querySelectorAll("[data-piece-src]").forEach(function (node) { node.src = node.getAttribute("data-piece-src"); });
    openPiece(dialog);
  }

  function openPieceConfirm(button, active) {
    var dialog = pieceDialog("archive");
    var form = dialog.querySelector("form");
    form.reset();
    setField(form, "id", button.getAttribute("data-id"));
    setField(form, "active", active ? "1" : "0");
    dialog.querySelector("[data-piece-title]").textContent = active ? "Restaurar pieza" : "Archivar pieza";
    dialog.querySelector("[data-piece-lead]").textContent = button.getAttribute("data-title") || "";
    dialog.querySelector("[data-piece-confirm-text]").textContent = active
      ? "Entiendo que la pieza vuelve al banco activo."
      : "Entiendo que la pieza sale del banco activo. Su archivo y sus vínculos se conservan y puedo restaurarla después.";
    openPiece(dialog);
  }

  // Publicaciones de la ventana abierta. selected incluye las vinculadas aunque queden fuera de las 1.000 de la lista.
  var pieceLinks = { posts: [], selected: new Set(), asset: 0 };

  function postRow(post) {
    var other = Number(post.linked_asset_id || 0) > 0 && Number(post.linked_asset_id) !== pieceLinks.asset;
    var id = Number(post._ID);
    return '<label class="cpr-piece-post' + (other ? " is-taken" : "") + '"><input type="checkbox" value="' + id + '"' + (pieceLinks.selected.has(id) ? " checked" : "") + (other ? " disabled" : "") + ">" +
      (post.thumbnail_url ? '<img src="' + esc(post.thumbnail_url) + '" alt="" loading="lazy" width="56" height="56">' : '<span class="cpr-piece-post__mark" aria-hidden="true">' + (post.platform === "instagram" ? "IG" : "FB") + "</span>") +
      '<span class="cpr-piece-post__text"><span class="cpr-cell-main">' + esc(post.title || "Publicación sin texto") + "</span>" +
      '<span class="cpr-cell-sub">' + esc((post.platform === "instagram" ? "Instagram" : post.platform === "facebook" ? "Facebook" : "Meta") + " · " + stamp(post.post_date + " 00:00").slice(0, 10) + " · " + (post.content_type || "post") + " · " + Number(post.reach || 0).toLocaleString("es-CO") + " de alcance") + "</span>" +
      (other ? '<span class="cpr-cell-sub">Ya vinculada a “' + esc(post.linked_asset_title) + "”</span>" : "") + "</span></label>";
  }

  function drawPosts(dialog) {
    var query = dialog.querySelector("[data-piece-links-search]").value.trim().toLowerCase();
    var platform = dialog.querySelector("[data-piece-links-platform]").value;
    var list = pieceLinks.posts.filter(function (post) {
      if (platform && post.platform !== platform) return false;
      return !query || [post.title, post.post_date, post.content_type].join(" ").toLowerCase().indexOf(query) >= 0;
    });
    dialog.querySelector("[data-piece-links-list]").innerHTML = list.length ? list.map(postRow).join("") : '<p class="cpr-empty">' + (pieceLinks.posts.length ? "Prueba con otro texto o cambia la red social." : "Sincroniza Facebook o Instagram para cargar publicaciones.") + "</p>";
    dialog.querySelector("[data-piece-links-count]").textContent = list.length + " de " + pieceLinks.posts.length + " publicaciones · " + pieceLinks.selected.size + " seleccionadas";
  }

  async function openPieceLinks(button) {
    var dialog = pieceDialog("links");
    var form = dialog.querySelector("form");
    var ids = [];
    try { ids = JSON.parse(button.getAttribute("data-linked") || "[]") || []; } catch (_error) { ids = []; }
    form.reset();
    setField(form, "id", button.getAttribute("data-id"));
    dialog.querySelector("[data-piece-links-name]").textContent = button.getAttribute("data-title") || "esta pieza";
    pieceLinks = { posts: [], selected: new Set(ids.map(Number)), asset: Number(button.getAttribute("data-id")) };
    dialog.querySelector("[data-piece-links-list]").innerHTML = '<p class="cpr-empty">Cargando publicaciones…</p>';
    dialog.querySelector("[data-piece-links-count]").textContent = "";
    openPiece(dialog);
    var submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      pieceLinks.posts = (await request("commercial_piece_posts", {})).posts || [];
      drawPosts(dialog);
      submit.disabled = false;
    } catch (error) {
      dialog.querySelector("[data-piece-links-list]").innerHTML = '<p class="cpr-empty">' + esc(error.message) + "</p>";
    }
  }

  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var open = target.closest("[data-piece-open]");
    if (open) {
      event.preventDefault();
      var kind = open.getAttribute("data-piece-open");
      if (kind === "form") openPieceForm(open);
      else if (kind === "detail") openPieceDetail(open);
      else if (kind === "links") openPieceLinks(open);
      else openPieceConfirm(open, kind === "restore");
      return;
    }
    if (target.closest("[data-piece-close]")) closeDialog(target.closest("dialog"));
  });

  // Al cerrar el detalle se quita su contenido para detener el video y soltar el PDF.
  document.addEventListener("close", function (event) {
    var dialog = event.target;
    if (dialog && dialog.matches && dialog.matches('[data-piece-dialog="detail"]')) dialog.querySelector("[data-piece-detail-body]").innerHTML = "";
  }, true);

  document.addEventListener("input", function (event) {
    var input = event.target;
    if (!input || !input.matches) return;
    if (input.matches("[data-piece-compliance]")) complianceLabel(input.form);
    if (input.matches("[data-piece-links-search]")) drawPosts(input.closest("dialog"));
  });

  document.addEventListener("change", function (event) {
    var input = event.target;
    if (!input || !input.matches) return;
    if (input.matches("[data-piece-links-platform]")) { drawPosts(input.closest("dialog")); return; }
    if (input.matches("[data-piece-links-list] input[type=checkbox]")) {
      if (input.checked) pieceLinks.selected.add(Number(input.value));
      else pieceLinks.selected.delete(Number(input.value));
      drawPosts(input.closest("dialog"));
    }
  });

  document.addEventListener("submit", async function (event) {
    var form = event.target.closest && event.target.closest("form[data-piece-form], form[data-piece-links-form]");
    if (!form) return;
    event.preventDefault();
    var error = form.querySelector("[data-piece-error]");
    var submit = form.querySelector('[type="submit"]');
    var fail = function (message) {
      error.textContent = message;
      error.hidden = false;
    };
    if (!form.reportValidity()) return;
    var links = form.hasAttribute("data-piece-links-form");
    var data = new FormData(form);
    if (links) {
      data.set("post_ids", JSON.stringify(Array.from(pieceLinks.selected)));
    } else if (form.querySelector("[data-piece-file]")) {
      var file = form.querySelector("[data-piece-file]");
      var max = Number(file.getAttribute("data-piece-max") || 0);
      if (file.files[0] && max && file.files[0].size > max) { fail("El archivo pesa más de lo permitido."); return; }
      if (!form.querySelector('[name="channels[]"]:checked')) { fail("Selecciona al menos un canal de uso."); return; }
    }
    data.set("action", links ? "commercial_piece_links" : form.getAttribute("data-piece-form"));
    data.set("nonce", runtime.nonce || "");
    submit.disabled = true;
    error.hidden = true;
    try {
      var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
      var json = null;
      try { json = await response.json(); } catch (_error) { json = null; }
      if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo guardar. Intenta de nuevo.");
      closeDialog(form.closest("dialog"));
      notify("success", json.data.message || "Cambio guardado.");
      navigate("");
    } catch (problem) {
      fail(problem.message);
    } finally {
      submit.disabled = false;
    }
  });
})();
