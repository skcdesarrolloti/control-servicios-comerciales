(function () {
  "use strict";
  // Editor visual de las plantillas del contrato de mandato (public/plantillas-mandato.php), como el editor de
  // eSignatures: la hoja se edita directamente (negrita, títulos, alineación, tablas) y los {{campos}} son etiquetas.
  // Convierte el Markdown de eSignatures a la hoja y de vuelta (toMarkdown) sin perder las marcas `{"…"}` de cada
  // línea (alineación, casilla de aceptación). Guarda con commercial_settings_save (MandateTemplateEditor en el servidor).
  var root = document.getElementById("mte");
  if (!root) return;
  var app = root.querySelector("[data-mte-app]");
  var status = root.querySelector("[data-mte-status]");
  var api = root.getAttribute("data-api");
  var nonce = root.getAttribute("data-nonce");
  var data = null;
  var active = 0;
  var dirty = false;
  var codeMode = false;
  var lastRange = null;

  function request(action, fields) {
    var form = new FormData();
    form.set("action", action);
    form.set("nonce", nonce);
    Object.keys(fields).forEach(function (key) { form.set(key, fields[key]); });
    return fetch(api, { method: "POST", body: form, credentials: "same-origin" }).then(function (response) {
      return response.json().catch(function () { throw new Error("El servidor no respondió bien (" + response.status + ")."); });
    }).then(function (json) {
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
      return json.data || {};
    });
  }
  function esc(text) {
    return String(text == null ? "" : text).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; });
  }
  function icon(name) { return '<span class="material-symbols-outlined" aria-hidden="true">' + name + "</span>"; }

  // ── Markdown de eSignatures → hoja ───────────────────────────────────────
  var FIELD = /\{\{\s*([\w-]+)\s*\}\}/g;
  function inlineHtml(text) {
    var fields = [];
    var html = esc(text).replace(FIELD, function (_m, name) { fields.push(name); return "\u0001" + (fields.length - 1) + "\u0002"; });
    html = html.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    html = html.replace(/(^|[^*])\*([^*\s][^*]*?)\*(?!\*)/g, "$1<em>$2</em>");
    return html.replace(/\u0001(\d+)\u0002/g, function (_m, index) { return chip(fields[Number(index)]); });
  }
  function chip(name) {
    return '<span class="mte-field" contenteditable="false" data-field="' + esc(name) + '">' + esc(name) + "</span>";
  }
  function splitMeta(line) {
    var match = line.match(/`(\{[^`]*\})`\s*$/);
    return match ? { text: line.slice(0, match.index).replace(/\s+$/, ""), meta: match[1] } : { text: line, meta: "" };
  }
  function metaAttrs(meta) {
    if (!meta) return "";
    var parsed = {};
    try { parsed = JSON.parse(meta); } catch (_error) {}
    var style = parsed.alignment ? ' style="text-align:' + esc(parsed.alignment === "justify" ? "justify" : parsed.alignment) + '"' : "";
    var signer = parsed.line_type === "checkbox" ? ' class="mte-accept"' : "";
    return ' data-meta="' + esc(meta) + '"' + style + signer;
  }
  function toHtml(markdown) {
    var lines = String(markdown || "").replace(/\r\n?/g, "\n").split("\n");
    var html = "";
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (/^\s*\|/.test(line)) {
        var rows = [];
        var sep = 0;
        while (i < lines.length && /^\s*\|/.test(lines[i])) {
          var row = lines[i].trim();
          if (/^\|?\s*:?-{3,}/.test(row)) { if (!sep) sep = rows.length; } else rows.push(row.replace(/^\|/, "").replace(/\|$/, "").split("|"));
          i++;
        }
        i--;
        html += '<table class="mte-table" data-sep="' + sep + '"><tbody>' + rows.map(function (cells) {
          return "<tr>" + cells.map(function (cell) { return "<td>" + (inlineHtml(cell.trim()) || "<br>") + "</td>"; }).join("") + "</tr>";
        }).join("") + "</tbody></table>";
        continue;
      }
      var parts = splitMeta(line);
      var heading = parts.text.match(/^(#{1,6})\s+(.*)$/);
      if (heading) {
        var level = Math.min(3, heading[1].length);
        html += "<h" + level + ' data-level="' + heading[1].length + '"' + metaAttrs(parts.meta) + ">" + (inlineHtml(heading[2]) || "<br>") + "</h" + level + ">";
      } else {
        html += "<p" + metaAttrs(parts.meta) + ">" + (inlineHtml(parts.text) || "<br>") + "</p>";
      }
    }
    return html;
  }

  // ── Hoja → Markdown de eSignatures ───────────────────────────────────────
  function inlineMd(node) {
    var out = "";
    node.childNodes.forEach(function (child) {
      if (child.nodeType === 3) { out += child.nodeValue.replace(/ /g, " ").replace(/\n/g, " "); return; }
      if (child.nodeType !== 1) return;
      var tag = child.tagName;
      if (child.hasAttribute("data-field")) { out += "{{" + child.getAttribute("data-field") + "}}"; return; }
      if (tag === "BR") return;
      var inner = inlineMd(child);
      var mark = tag === "STRONG" || tag === "B" ? "**" : tag === "EM" || tag === "I" ? "*" : "";
      if (mark && inner.trim()) {
        // Los espacios van fuera de las marcas ("**texto** ", no "**texto **"), si no eSignatures no aplica el formato.
        var parts = inner.match(/^(\s*)([\s\S]*?)(\s*)$/);
        out += parts[1] + mark + parts[2] + mark + parts[3];
      } else {
        out += inner;
      }
    });
    return out;
  }
  function withMeta(text, block) {
    var meta = block.getAttribute("data-meta");
    return meta ? text + "`" + meta + "`" : text;
  }
  function toMarkdown(sheet) {
    var lines = [];
    sheet.childNodes.forEach(function (block) {
      if (block.nodeType === 3) { if (block.nodeValue.trim()) lines.push(block.nodeValue.trim()); return; }
      if (block.nodeType !== 1) return;
      var tag = block.tagName;
      var table = tag === "TABLE" ? block : block.querySelector && block.querySelector("table");
      if (table) {
        var rows = Array.prototype.slice.call(table.rows);
        var sep = Number(table.getAttribute("data-sep") || 1) || 1;
        rows.forEach(function (row, index) {
          var cells = Array.prototype.slice.call(row.cells).map(function (cell) { return inlineMd(cell).replace(/\|/g, "/").trim(); });
          if (index === sep) lines.push("| " + cells.map(function () { return "---"; }).join(" | ") + " |");
          lines.push("| " + cells.join(" | ") + " |");
        });
        if (rows.length && rows.length <= sep) lines.push("| " + Array.prototype.map.call(rows[0].cells, function () { return "---"; }).join(" | ") + " |");
        return;
      }
      var match = tag.match(/^H([1-6])$/);
      if (match) {
        var level = Number(block.getAttribute("data-level") || match[1]);
        lines.push(withMeta(new Array(level + 1).join("#") + " " + inlineMd(block).trim(), block));
        return;
      }
      if (tag === "UL" || tag === "OL") {
        Array.prototype.forEach.call(block.children, function (item) { lines.push(inlineMd(item).trim()); });
        return;
      }
      var text = inlineMd(block);
      lines.push(text.trim() === "" && !block.getAttribute("data-meta") ? "" : withMeta(text.replace(/\s+$/, ""), block));
    });
    return lines.join("\n");
  }
  function fieldsOf(markdown) {
    var found = [];
    String(markdown).replace(FIELD, function (_m, name) { if (found.indexOf(name) < 0) found.push(name); return _m; });
    return found;
  }

  // ── Pantalla ────────────────────────────────────────────────────────────
  function render() {
    var templates = data.templates || [];
    var tabs = templates.map(function (tpl, index) {
      return '<button type="button" class="mte-tab" data-tab="' + index + '" aria-pressed="' + (index === active ? "true" : "false") + '">' + esc(tpl.tipo) + "</button>";
    }).join("");
    var fields = (data.known || []).map(function (name) { return '<option value="' + esc(name) + '">' + esc(name) + "</option>"; }).join("");
    var history = (data.history || []).map(function (row) {
      return '<li><span><strong>' + esc(row.tipo) + "</strong> · " + esc(row.fecha) + " · " + esc(row.nombre || row.id_empleado || "") + (row.campos_quitados ? " · quitó {{" + esc(row.campos_quitados) + "}}" : "") + "</span>" +
        '<button type="button" class="mte-link" data-version="' + esc(row.id) + '" data-tipo="' + esc(row.tipo) + '">Cargar esta versión</button></li>';
    }).join("");
    var tool = function (cmd, name, label) { return '<button type="button" class="mte-tool" data-cmd="' + cmd + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + icon(name) + "</button>"; };
    app.innerHTML =
      '<nav class="mte-tabs" aria-label="Plantillas">' + tabs + "</nav>" +
      '<div class="mte-layout"><div class="mte-editor">' +
      '<div class="mte-toolbar" role="toolbar" aria-label="Formato">' +
      '<select class="mte-select" data-block aria-label="Tipo de texto"><option value="p">Párrafo</option><option value="1">Título 1</option><option value="2">Título 2</option><option value="3">Título 3</option></select>' +
      '<span class="mte-sep"></span>' + tool("bold", "format_bold", "Negrita") + tool("italic", "format_italic", "Cursiva") +
      '<span class="mte-sep"></span>' + tool("align-left", "format_align_left", "Alinear a la izquierda") + tool("align-center", "format_align_center", "Centrar") + tool("align-justify", "format_align_justify", "Justificar") +
      '<span class="mte-sep"></span>' + tool("table", "table", "Insertar tabla") + tool("row-add", "table_rows", "Agregar fila debajo") + tool("row-del", "delete_sweep", "Quitar fila") + tool("col-add", "view_column", "Agregar columna") + tool("col-del", "variable_remove", "Quitar columna") +
      '<span class="mte-sep"></span><select class="mte-select" data-insert aria-label="Insertar campo"><option value="">Insertar campo…</option>' + fields + "</select>" +
      '<span class="mte-sep"></span>' + tool("undo", "undo", "Deshacer") + tool("redo", "redo", "Rehacer") +
      '<button type="button" class="mte-code-toggle" data-cmd="code">' + icon("code") + "Ver código</button></div>" +
      '<div class="mte-paper"><div class="mte-sheet" data-sheet contenteditable="true" spellcheck="true" aria-label="Texto de la plantilla"></div>' +
      '<textarea class="mte-code" data-code hidden aria-label="Código de la plantilla"></textarea></div></div>' +
      '<aside class="mte-side"><div class="mte-card"><h2>Guardar en eSignatures</h2>' +
      '<p class="mte-help">Se guarda la plantilla <strong data-current></strong>. Los <span class="mte-field mte-field--sample">campos</span> se llenan con los datos de cada mandato.</p>' +
      '<div class="mte-removed" data-removed hidden><p data-removed-text></p><label class="mte-check"><input type="checkbox" data-confirm-fields> Quiero quitar estos campos.</label></div>' +
      '<label class="mte-check"><input type="checkbox" data-confirm> Entiendo que aplica a todos los contratos nuevos de este tipo.</label>' +
      '<p class="mte-error" data-error role="alert" hidden></p>' +
      '<button type="button" class="mte-primary" data-save>' + icon("save") + "Guardar plantilla</button>" +
      '<button type="button" class="mte-secondary" data-preview>' + icon("visibility") + "Vista previa con datos vacíos</button>" +
      '<p class="mte-dirty" data-dirty hidden>Hay cambios sin guardar.</p></div>' +
      (history ? '<div class="mte-card"><h2>Historial</h2><ul class="mte-history">' + history + "</ul></div>" : "") +
      "</aside></div>" +
      '<div class="mte-dialog" data-dialog hidden><div class="mte-dialog-box" role="dialog" aria-modal="true" aria-labelledby="mte-dialog-title"><header><h2 id="mte-dialog-title">Vista previa</h2><button type="button" class="mte-tool" data-close aria-label="Cerrar">' + icon("close") + '</button></header><div class="mte-preview" data-preview-box></div></div></div>';
    app.hidden = false;
    status.hidden = true;
    load(active);
  }
  function sheet() { return app.querySelector("[data-sheet]"); }
  function current() { return data.templates[active]; }
  function markdownNow() { return codeMode ? app.querySelector("[data-code]").value : toMarkdown(sheet()); }
  function load(index, markdown) {
    active = index;
    app.querySelectorAll("[data-tab]").forEach(function (tab) { tab.setAttribute("aria-pressed", tab.getAttribute("data-tab") === String(index) ? "true" : "false"); });
    app.querySelector("[data-current]").textContent = current().tipo;
    sheet().innerHTML = toHtml(markdown != null ? markdown : current().markdown);
    if (codeMode) app.querySelector("[data-code]").value = markdown != null ? markdown : current().markdown;
    app.querySelector("[data-confirm]").checked = false;
    setDirty(markdown != null);
    checkFields();
  }
  function setDirty(value) {
    dirty = value;
    var flag = app.querySelector("[data-dirty]");
    if (flag) flag.hidden = !value;
  }
  function showError(message) {
    var box = app.querySelector("[data-error]");
    box.textContent = message || "";
    box.hidden = !message;
  }
  function checkFields() {
    var now = fieldsOf(markdownNow());
    var removed = (current().campos || []).filter(function (name) { return now.indexOf(name) < 0; });
    app.querySelector("[data-removed]").hidden = !removed.length;
    app.querySelector("[data-removed-text]").textContent = removed.length ? "Quitaste campos que llenan los datos del contrato: {{" + removed.join("}}, {{") + "}}." : "";
    if (!removed.length) app.querySelector("[data-confirm-fields]").checked = false;
  }

  // ── Herramientas ────────────────────────────────────────────────────────
  function rememberRange() {
    var selection = window.getSelection();
    if (selection.rangeCount && sheet().contains(selection.anchorNode)) lastRange = selection.getRangeAt(0).cloneRange();
  }
  function restoreRange() {
    if (!lastRange) return false;
    var selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(lastRange);
    return true;
  }
  function currentBlock() {
    var selection = window.getSelection();
    var node = selection.rangeCount ? selection.anchorNode : null;
    while (node && node.parentNode !== sheet()) node = node.parentNode;
    return node && node.nodeType === 1 ? node : null;
  }
  function currentCell() {
    var selection = window.getSelection();
    var node = selection.rangeCount ? selection.anchorNode : null;
    while (node && node !== sheet()) { if (node.nodeType === 1 && (node.tagName === "TD" || node.tagName === "TH")) return node; node = node.parentNode; }
    return null;
  }
  function setAlignment(block, value) {
    if (!block || block.tagName === "TABLE") return;
    var meta = {};
    try { meta = JSON.parse(block.getAttribute("data-meta") || "{}"); } catch (_error) {}
    if (value === "left") delete meta.alignment; else meta.alignment = value;
    var text = Object.keys(meta).length ? JSON.stringify(meta) : "";
    if (text) block.setAttribute("data-meta", text); else block.removeAttribute("data-meta");
    block.style.textAlign = value === "left" ? "" : value;
  }
  function setBlockType(block, type) {
    if (!block || block.tagName === "TABLE") return;
    var tag = type === "p" ? "P" : "H" + type;
    if (block.tagName === tag) return;
    var replacement = document.createElement(tag);
    replacement.innerHTML = block.innerHTML || "<br>";
    ["data-meta", "style", "class"].forEach(function (name) { if (block.hasAttribute(name)) replacement.setAttribute(name, block.getAttribute(name)); });
    if (type !== "p") replacement.setAttribute("data-level", type);
    block.parentNode.replaceChild(replacement, block);
    var range = document.createRange();
    range.selectNodeContents(replacement);
    range.collapse(false);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
  }
  function insertTable() {
    var block = currentBlock();
    var table = document.createElement("table");
    table.className = "mte-table";
    table.setAttribute("data-sep", "1");
    table.innerHTML = "<tbody><tr><td>Dato</td><td>Valor</td></tr><tr><td><br></td><td><br></td></tr></tbody>";
    var after = document.createElement("p");
    after.innerHTML = "<br>";
    if (block && block.nextSibling) { sheet().insertBefore(table, block.nextSibling); sheet().insertBefore(after, table.nextSibling); }
    else { sheet().appendChild(table); sheet().appendChild(after); }
  }
  function tableEdit(cmd) {
    var cell = currentCell();
    if (!cell) { showError("Pon el cursor dentro de una celda de la tabla."); return; }
    var row = cell.parentNode;
    var table = row.closest("table");
    var index = cell.cellIndex;
    if (cmd === "row-add") {
      var copy = row.cloneNode(false);
      Array.prototype.forEach.call(row.cells, function () { var td = document.createElement("td"); td.innerHTML = "<br>"; copy.appendChild(td); });
      row.parentNode.insertBefore(copy, row.nextSibling);
    } else if (cmd === "row-del") {
      if (table.rows.length <= 1) { if (window.confirm("Es la única fila: ¿quitar toda la tabla?")) table.remove(); return; }
      if (row.rowIndex < Number(table.getAttribute("data-sep") || 1)) table.setAttribute("data-sep", String(Math.max(1, Number(table.getAttribute("data-sep") || 1) - 1)));
      row.remove();
    } else if (cmd === "col-add") {
      Array.prototype.forEach.call(table.rows, function (tr) { var td = document.createElement("td"); td.innerHTML = "<br>"; tr.insertBefore(td, tr.cells[index + 1] || null); });
    } else if (cmd === "col-del") {
      if (table.rows[0].cells.length <= 1) return;
      Array.prototype.forEach.call(table.rows, function (tr) { if (tr.cells[index]) tr.cells[index].remove(); });
    }
  }
  function insertField(name) {
    if (!name) return;
    sheet().focus();
    restoreRange();
    var selection = window.getSelection();
    if (!selection.rangeCount || !sheet().contains(selection.anchorNode)) { showError("Pon el cursor donde va el campo."); return; }
    var range = selection.getRangeAt(0);
    range.deleteContents();
    var holder = document.createElement("span");
    holder.innerHTML = chip(name);
    var node = holder.firstChild;
    range.insertNode(node);
    var space = document.createTextNode(" ");
    node.parentNode.insertBefore(space, node.nextSibling);
    range.setStartAfter(space);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
    changed();
  }
  function changed() { setDirty(true); checkFields(); showError(""); }

  function toggleCode() {
    var code = app.querySelector("[data-code]");
    var button = app.querySelector('[data-cmd="code"]');
    if (!codeMode) {
      code.value = toMarkdown(sheet());
      code.hidden = false;
      sheet().hidden = true;
      button.innerHTML = icon("edit_document") + "Editor visual";
    } else {
      sheet().innerHTML = toHtml(code.value);
      code.hidden = true;
      sheet().hidden = false;
      button.innerHTML = icon("code") + "Ver código";
    }
    codeMode = !codeMode;
    app.querySelectorAll(".mte-tool, [data-block], [data-insert]").forEach(function (control) { if (control.getAttribute("data-cmd") !== "code") control.disabled = codeMode; });
  }

  function save() {
    var markdown = markdownNow();
    if (!markdown.trim()) { showError("El texto no puede quedar vacío."); return; }
    if (!app.querySelector("[data-confirm]").checked) { showError("Marca que entiendes que aplica a todos los contratos nuevos."); return; }
    var removedBox = app.querySelector("[data-removed]");
    if (!removedBox.hidden && !app.querySelector("[data-confirm-fields]").checked) { showError("Confirma que quieres quitar esos campos."); return; }
    var button = app.querySelector("[data-save]");
    button.disabled = true;
    showError("");
    request("commercial_settings_save", { section: "plantillas_mandato", payload: JSON.stringify({ tipo: current().tipo, huella: current().huella, markdown: markdown, confirmo: "1", confirmar_campos: removedBox.hidden ? "" : "1" }) })
      .then(function (result) {
        var fresh = result.settings && result.settings.plantillas_mandato;
        if (fresh && fresh.templates && fresh.templates.length) { data = fresh; }
        else { current().markdown = markdown; }
        setDirty(false);
        render();
        status.textContent = result.message || "Plantilla guardada.";
        status.className = "mte-status mte-status--ok";
        status.hidden = false;
        window.scrollTo({ top: 0, behavior: "smooth" });
      })
      .catch(function (error) { showError(error.message); })
      .finally(function () { var again = app.querySelector("[data-save]"); if (again) again.disabled = false; });
  }
  function preview() {
    var dialog = app.querySelector("[data-dialog]");
    var box = app.querySelector("[data-preview-box]");
    box.innerHTML = '<p class="mte-help">Cargando…</p>';
    dialog.hidden = false;
    request("commercial_settings_save", { section: "plantillas_mandato", payload: JSON.stringify({ accion: "vista_previa", tipo: current().tipo, markdown: markdownNow() }) })
      .then(function (result) { box.innerHTML = '<p class="mte-help">Los campos salen "No aplica" porque no hay un mandato. Así se verá el texto en la página del propietario.</p><div class="mte-contract">' + ((result.result && result.result.html) || "") + "</div>"; })
      .catch(function (error) { box.innerHTML = '<p class="mte-error">' + esc(error.message) + "</p>"; });
    app.querySelector("[data-close]").focus();
  }
  function loadVersion(button) {
    var index = -1;
    data.templates.forEach(function (tpl, i) { if (tpl.tipo === button.getAttribute("data-tipo")) index = i; });
    if (index < 0) return;
    if (dirty && !window.confirm("Hay cambios sin guardar. ¿Descartarlos?")) return;
    request("commercial_settings_save", { section: "plantillas_mandato", payload: JSON.stringify({ accion: "version", id: button.getAttribute("data-version") }) })
      .then(function (result) {
        load(index, (result.result && result.result.markdown) || "");
        showError("");
        status.textContent = "Versión anterior cargada en el editor. Revísala y guarda para volver a ella.";
        status.className = "mte-status";
        status.hidden = false;
      })
      .catch(function (error) { showError(error.message); });
  }

  app.addEventListener("input", function (event) { if (event.target.closest("[data-sheet], [data-code]")) changed(); });
  document.addEventListener("selectionchange", function () {
    if (!data || codeMode) return;
    rememberRange();
    var block = currentBlock();
    var select = app.querySelector("[data-block]");
    if (select && block) select.value = /^H[1-6]$/.test(block.tagName) ? block.tagName.slice(1) : "p";
  });
  app.addEventListener("mousedown", function (event) { if (event.target.closest(".mte-tool")) event.preventDefault(); });
  app.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-tab]");
    if (tab) {
      if (Number(tab.getAttribute("data-tab")) === active) return;
      if (dirty && !window.confirm("Hay cambios sin guardar en " + current().tipo + ". ¿Descartarlos?")) return;
      if (codeMode) toggleCode();
      load(Number(tab.getAttribute("data-tab")));
      return;
    }
    var tool = event.target.closest("[data-cmd]");
    if (tool) {
      var cmd = tool.getAttribute("data-cmd");
      if (cmd === "code") { toggleCode(); return; }
      sheet().focus();
      restoreRange();
      if (cmd === "bold" || cmd === "italic" || cmd === "undo" || cmd === "redo") document.execCommand(cmd);
      else if (cmd.indexOf("align-") === 0) setAlignment(currentBlock(), cmd.slice(6));
      else if (cmd === "table") insertTable();
      else tableEdit(cmd);
      changed();
      return;
    }
    if (event.target.closest("[data-save]")) { save(); return; }
    if (event.target.closest("[data-preview]")) { preview(); return; }
    if (event.target.closest("[data-close]") || event.target.matches("[data-dialog]")) { app.querySelector("[data-dialog]").hidden = true; return; }
    var version = event.target.closest("[data-version]");
    if (version) loadVersion(version);
  });
  app.addEventListener("change", function (event) {
    if (event.target.matches("[data-block]")) { sheet().focus(); restoreRange(); setBlockType(currentBlock(), event.target.value); changed(); }
    if (event.target.matches("[data-insert]")) { insertField(event.target.value); event.target.value = ""; }
  });
  // Pegar sin formato de otros programas (Word, correo): solo texto.
  app.addEventListener("paste", function (event) {
    if (!event.target.closest("[data-sheet]")) return;
    event.preventDefault();
    document.execCommand("insertText", false, (event.clipboardData || window.clipboardData).getData("text/plain"));
  });
  document.addEventListener("keydown", function (event) { if (event.key === "Escape") { var dialog = app.querySelector("[data-dialog]"); if (dialog && !dialog.hidden) dialog.hidden = true; } });
  window.addEventListener("beforeunload", function (event) { if (dirty) { event.preventDefault(); event.returnValue = ""; } });
  try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (_error) {}

  // Para las pruebas de ida y vuelta (tools): window.SCMMandateTemplate.roundTrip(markdown).
  window.SCMMandateTemplate = {
    roundTrip: function (markdown) { var holder = document.createElement("div"); holder.innerHTML = toHtml(markdown); return toMarkdown(holder); },
  };

  request("commercial_settings_load", { section: "plantillas_mandato" }).then(function (result) {
    data = (result.settings && result.settings.plantillas_mandato) || result.plantillas_mandato || {};
    if (!data.ready) { status.textContent = data.message || "No se pudieron leer las plantillas."; status.className = "mte-status mte-status--error"; return; }
    render();
  }).catch(function (error) {
    status.textContent = error.message;
    status.className = "mte-status mte-status--error";
  });
})();
