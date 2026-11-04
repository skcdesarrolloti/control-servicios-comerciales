/*
 * Menú "Configuración" del encabezado comercial. Cada opción abre un popup con su sección:
 * calendario, tiempos de atención, avisos, actualizaciones de inmuebles, cupos de destacados, calculadora de aseo,
 * campañas del Generador UTM, Medios (lista única), Analítica web (Promoción) y catálogos del Plan estratégico.
 * Permisos reutiliza su modal existente. "Medios" es un administrador de lista: cada acción se guarda al confirmarla en un
 * panel interno del mismo popup (sin botón Guardar).
 */
(function () {
  "use strict";

  var app = document.getElementById("scm-app");
  var trigger = document.getElementById("commercial-config-trigger");
  var menu = document.getElementById("commercial-config-menu");
  var core = window.SCMAdminCore || {};
  if (!app || !trigger || !menu) return;

  var runtime = {};
  try {
    runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}") || {};
  } catch (error) {
    runtime = {};
  }
  var config = runtime.config || {};

  function esc(value) {
    if (typeof core.escHtml === "function") return core.escHtml(value);
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function notify(type, message) {
    if (typeof core.scmNotify === "function") core.scmNotify(type, message);
    else window.alert(message);
  }

  function request(action, fields) {
    var form = new FormData();
    form.set("action", action);
    form.set("nonce", runtime.nonce || "");
    Object.keys(fields || {}).forEach(function (key) { form.set(key, fields[key]); });
    return fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: form, credentials: "same-origin" })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la solicitud.");
        return json.data || {};
      });
  }

  function setMenu(open) {
    menu.classList.toggle("hidden", !open);
    trigger.setAttribute("aria-expanded", open ? "true" : "false");
  }

  trigger.addEventListener("click", function (event) {
    event.stopPropagation();
    setMenu(menu.classList.contains("hidden"));
  });
  document.addEventListener("click", function (event) {
    if (!menu.contains(event.target) && event.target !== trigger) setMenu(false);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") setMenu(false);
  });

  function numberField(name, value, min, max, step) {
    return '<input class="w-24 px-2 py-1.5 rounded-lg border border-slate-300 text-sm text-right" type="number" name="' + esc(name) + '" value="' + esc(value == null ? "" : String(value)) + '" min="' + esc(String(min)) + '" max="' + esc(String(max)) + '" step="' + esc(String(step || 1)) + '" required>';
  }

  function tableHtml(headers, rows) {
    return '<div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">' +
      headers.map(function (header, index) { return '<th class="py-2 px-2' + (index ? " text-right" : "") + '">' + esc(header) + "</th>"; }).join("") +
      '</tr></thead><tbody class="divide-y divide-slate-100">' + rows.join("") + "</tbody></table></div>";
  }

  function intro(text) {
    return '<p class="text-sm text-slate-600 mb-3 text-left">' + text + "</p>";
  }

  function checkboxGrid(name, items, checked) {
    return '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-[34vh] overflow-y-auto">' + items.map(function (item) {
      return '<label class="flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-200 bg-white cursor-pointer hover:bg-slate-50 text-left">' +
        '<input type="checkbox" name="' + esc(name) + '" value="' + esc(item.id) + '"' + (checked(item.id) ? " checked" : "") + ">" +
        (item.color ? '<i class="inline-block w-3 h-3 rounded-full shrink-0" style="background:' + esc(item.color) + '"></i>' : "") +
        '<span class="text-sm text-slate-800">' + esc(item.label) + (item.hint ? ' <small class="text-slate-500">' + esc(item.hint) + "</small>" : "") + "</span></label>";
    }).join("") + "</div>";
  }

  function calendarCategories() {
    var url = String(config.calendar_api_url || "https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=") + "listar_categorias";
    return fetch(url, { credentials: "same-origin" }).then(function (response) { return response.json(); }).then(function (json) {
      return json && json.success && Array.isArray(json.data) ? json.data : [];
    });
  }

  var sections = {
    calendario: {
      title: "Calendario comercial",
      prepare: function (settings) {
        return calendarCategories().catch(function () { return []; }).then(function (categories) {
          return { settings: settings, categories: categories };
        });
      },
      html: function (data) {
        var current = data.settings.category_ids || [];
        var categories = data.categories.map(function (row) {
          return { id: String(row.id || row._ID || ""), label: row.nombre || row.categoria || "Categoría", color: row.color || "#94a3b8" };
        }).filter(function (row) { return row.id; }).sort(function (a, b) { return a.label.localeCompare(b.label, "es"); });
        var cargos = (data.settings.cargo_options || []).map(function (row) {
          return { id: String(row.id), label: row.name, hint: "(" + row.total + ")" };
        });
        var selectedCargos = (data.settings.cargo_ids || []).map(String);
        return '<form data-config-form class="text-left space-y-4">' +
          "<section>" + '<h4 class="text-sm font-bold text-slate-800 mb-1">Categorías que se usan en comercial</h4>' +
          intro("Solo estas categorías salen al crear, filtrar y agendar en el calendario comercial. Si marcas todas, también aparecerán las que se creen después.") +
          (categories.length
            ? '<div class="flex gap-2 mb-2"><button type="button" class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold" data-toggle-all="category_ids" data-value="1">Marcar todas</button><button type="button" class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold" data-toggle-all="category_ids" data-value="0">Quitar todas</button></div>' +
              checkboxGrid("category_ids", categories, function (id) { return !current.length || current.indexOf(id) !== -1; })
            : '<p class="text-sm text-rose-700">No se pudieron consultar las categorías del calendario; se conserva la selección actual.</p>') +
          "</section><section>" + '<h4 class="text-sm font-bold text-slate-800 mb-1">Cargos de los funcionarios del calendario</h4>' +
          intro("Los funcionarios activos de estos cargos aparecen en Mi calendario, Calendario equipo y al agendar desde una tarea.") +
          checkboxGrid("cargo_ids", cargos, function (id) { return selectedCargos.indexOf(id) !== -1; }) +
          "</section></form>";
      },
      collect: function (form) {
        var values = function (name, onlyChecked) {
          return Array.prototype.slice.call(form.querySelectorAll('[name="' + name + '"]' + (onlyChecked ? ":checked" : ""))).map(function (input) { return input.value; });
        };
        var available = values("category_ids", false);
        var categories = values("category_ids", true);
        if (available.length && !categories.length) throw new Error("Selecciona al menos una categoría.");
        if (!values("cargo_ids", true).length) throw new Error("Selecciona al menos un cargo.");
        return { category_ids: categories, available_category_ids: available, cargo_ids: values("cargo_ids", true) };
      },
      after: function (settings) {
        document.dispatchEvent(new CustomEvent("scm:calendar-categories-changed", { detail: { category_ids: settings.category_ids || [] } }));
        runtime.calendarCategoryIds = settings.category_ids || [];
        app.setAttribute("data-scm-runtime", JSON.stringify(runtime));
      },
    },
    tiempos: {
      title: "Tiempos de atención de tareas",
      html: function (settings) {
        return '<form data-config-form class="text-left">' +
          intro("Días hábiles (lunes a sábado, sin festivos) desde la última gestión de la tarea. Desde <strong>Alerta</strong> la tarea aparece por vencer y después de <strong>Vencimiento</strong> queda atrasada. Estos tiempos también los usan los informes de tareas.") +
          tableHtml(["Tema", "Alerta (días)", "Vencimiento (días)"], (settings.topics || []).map(function (row) {
            return '<tr><td class="py-2 px-2 font-medium text-slate-800">' + esc(row.topic) + '</td><td class="py-2 px-2 text-right">' + numberField("warning_from:" + row.topic, row.warning_from, 0, 120) + '</td><td class="py-2 px-2 text-right">' + numberField("overdue_after:" + row.topic, row.overdue_after, 1, 120) + "</td></tr>";
          })) + "</form>";
      },
      collect: function (form) {
        var topics = {};
        form.querySelectorAll("input[name]").forEach(function (input) {
          var parts = input.name.split(":");
          topics[parts[1]] = topics[parts[1]] || {};
          topics[parts[1]][parts[0]] = Number(input.value);
        });
        Object.keys(topics).forEach(function (topic) {
          if (topics[topic].warning_from > topics[topic].overdue_after) throw new Error('En "' + topic + '" la alerta no puede ser mayor que el vencimiento.');
        });
        return { topics: topics };
      },
    },
    avisos: {
      title: "Avisos en fachada",
      html: function (settings) {
        return '<form data-config-form class="text-left">' +
          intro("Plazos para retocar e instalar avisos y valores usados al registrar avisos. Se comparten con las demás aplicaciones de SKC SuCasa Inmobiliaria.") +
          tableHtml(["Concepto", "Valor"], (settings.values || []).map(function (row) {
            return '<tr><td class="py-2 px-2 font-medium text-slate-800">' + esc(row.label) + '</td><td class="py-2 px-2 text-right">' + numberField(row.key, row.value, row.min, row.max, row.key === "factor_prestacional" ? "0.0001" : 1) + "</td></tr>";
          })) + "</form>";
      },
      collect: function (form) {
        var values = {};
        form.querySelectorAll("input[name]").forEach(function (input) { values[input.name] = input.value; });
        return { values: values };
      },
    },
    actualizaciones: {
      title: "Actualizaciones de inmuebles",
      html: function (settings) {
        var labels = { arriendo: "Arriendo", venta: "Venta", arriendo_venta: "Arriendo y venta" };
        var limits = settings.limits || {};
        return '<form data-config-form class="text-left">' +
          intro("Días calendario desde la última actualización del inmueble publicado. Desde <strong>Alerta</strong> se marca en alerta y desde <strong>Vencido</strong> como vencido.") +
          tableHtml(["Tipo de negocio", "Alerta (días)", "Vencido (días)"], Object.keys(labels).map(function (type) {
            var row = limits[type] || {};
            return '<tr><td class="py-2 px-2 font-medium text-slate-800">' + esc(labels[type]) + '</td><td class="py-2 px-2 text-right">' + numberField("alerta:" + type, row.alerta, 0, 730) + '</td><td class="py-2 px-2 text-right">' + numberField("vencido:" + type, row.vencido, 1, 730) + "</td></tr>";
          })) + "</form>";
      },
      collect: function (form) {
        var limits = {};
        form.querySelectorAll("input[name]").forEach(function (input) {
          var parts = input.name.split(":");
          limits[parts[1]] = limits[parts[1]] || {};
          limits[parts[1]][parts[0]] = Number(input.value);
        });
        return { limits: limits };
      },
    },
    destacados: {
      title: "Cupos de destacados",
      html: function (settings) {
        return '<form data-config-form class="text-left">' +
          intro("Máximo de inmuebles destacados al mismo tiempo en cada portal.") +
          tableHtml(["Portal", "Cupos"], (settings.portals || []).map(function (row) {
            return '<tr><td class="py-2 px-2 font-medium text-slate-800">' + esc(row.label) + '</td><td class="py-2 px-2 text-right">' + numberField(row.key, row.value, 0, 10000) + "</td></tr>";
          })) + "</form>";
      },
      collect: function (form) {
        var portals = {};
        form.querySelectorAll("input[name]").forEach(function (input) { portals[input.name] = Number(input.value); });
        return { portals: portals };
      },
    },
    aseo: {
      title: "Calculadora de aseo",
      html: function (settings) {
        var hints = {
          salario: "Valor compartido: también lo usan actas, reportes administrativos y revisiones.",
          factor_prestacional: "Compartido con Avisos en fachada. Divide la mano de obra para el costo real del funcionario.",
          valor_pasaje_trans: "El auxilio del trabajador es el 50 % de dos pasajes.",
        };
        var globals = (settings.globals || []).map(function (row) {
          return '<label class="block text-left"><span class="block text-xs font-semibold text-slate-700 mb-1">' + esc(row.label) + (row.percent ? " (%)" : "") + "</span>" +
            '<input class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm" type="text" inputmode="decimal" name="' + esc(row.key) + '" value="' + esc(String(row.value).replace(".", ",")) + '" data-cleaning-global required>' +
            (hints[row.key] || row.percent ? '<span class="block text-[11px] text-slate-500 mt-1">' + esc(hints[row.key] || "En porcentaje (19 = 19 %).") + "</span>" : "") + "</label>";
        }).join("");
        function table(key, title, note, columns) {
          function line(row) {
            return "<tr data-cleaning-row>" + columns.map(function (column) {
              var value = row[column[1]] == null ? "" : String(row[column[1]]);
              return '<td class="py-1 px-1"><input class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm" type="text"' + (column[2] ? ' inputmode="decimal"' : "") + ' data-field="' + column[1] + '" value="' + esc(column[2] ? value.replace(".", ",") : value) + '"></td>';
            }).join("") + '<td class="py-1 px-1 text-right"><button type="button" class="px-2 py-1 rounded-lg text-rose-700 hover:bg-rose-50 text-xs font-semibold" data-cleaning-remove-row aria-label="Quitar fila">Quitar</button></td></tr>';
          }
          var empty = {};
          return '<section class="text-left" data-cleaning-table="' + key + '"><div class="flex flex-wrap items-center justify-between gap-2 mb-1"><div><h4 class="text-sm font-bold text-slate-800">' + esc(title) + '</h4><p class="text-[11px] text-slate-500">' + esc(note) + "</p></div>" +
            '<button type="button" class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold" data-cleaning-add-row>Agregar fila</button></div>' +
            '<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">' +
            columns.map(function (column) { return '<th class="py-2 px-1 text-left">' + esc(column[0]) + "</th>"; }).join("") + "<th></th></tr></thead><tbody>" +
            (settings[key] || []).map(line).join("") + "</tbody></table></div><template data-cleaning-row-template>" + line(empty) + "</template></section>";
        }
        return '<form data-config-form class="text-left space-y-5">' +
          intro("Tarifas y márgenes de la calculadora de aseo. Se guardan en la misma configuración que usan el panel inmobiliario y el plugin de WordPress.") +
          '<section><h4 class="text-sm font-bold text-slate-800 mb-2">Variables globales</h4><div class="grid grid-cols-1 sm:grid-cols-2 gap-3">' + globals + "</div></section>" +
          table("insumos", "Insumos", "Costo por unidad y consumo por m².", [["Nombre", "nombre", false], ["Unidad", "unidad", false], ["Costo", "costo", true], ["Consumo / m²", "consumo", true]]) +
          table("transporte", "Tarifas de transporte", "Valor por trayecto: con funcionario se cobra ida y regreso (×2).", [["Rango (km)", "rango", false], ["Valor por trayecto", "valor", true]]) +
          table("tramos", "Tramos de m² y horas", 'Cada área usa el primer tramo cuyo "hasta" la cubre; por encima del último se suma 1 hora cada 50 m².', [["Desde (m²)", "desde", true], ["Hasta (m²)", "hasta", true], ["Horas", "horas", true]]) +
          "</form>";
      },
      bind: function (popup) {
        popup.addEventListener("click", function (event) {
          var add = event.target.closest("[data-cleaning-add-row]");
          if (add) {
            var box = add.closest("[data-cleaning-table]");
            box.querySelector("tbody").insertAdjacentHTML("beforeend", box.querySelector("[data-cleaning-row-template]").innerHTML);
            return;
          }
          var remove = event.target.closest("[data-cleaning-remove-row]");
          if (remove) remove.closest("tr").remove();
        });
      },
      collect: function (form) {
        var data = {};
        form.querySelectorAll("[data-cleaning-global]").forEach(function (input) { data[input.name] = input.value; });
        form.querySelectorAll("[data-cleaning-table]").forEach(function (box) {
          var key = box.getAttribute("data-cleaning-table");
          data[key] = Array.prototype.map.call(box.querySelectorAll("tbody [data-cleaning-row]"), function (row) {
            var item = {};
            row.querySelectorAll("[data-field]").forEach(function (input) { item[input.getAttribute("data-field")] = input.value; });
            return item;
          });
          if (!data[key].length) throw new Error("Agrega al menos una fila en " + box.querySelector("h4").textContent + ".");
        });
        return data;
      },
    },
    // Campañas compartidas del Generador UTM (Herramientas). En dashboard-marketing cada navegador guardaba sus opciones.
    // Medios y fuentes ya no se configuran aquí: el generador usa la lista única de Configuración › Medios.
    utm: {
      title: "Generador UTM",
      lists: [
        ["campanas", "Campañas", "Van en utm_campaign. El nombre es el que ven los funcionarios; el valor es el que queda en el tracker.", false],
      ],
      html: function (settings) {
        function line(row) {
          return '<tr data-utm-row><td class="py-1 px-1"><input class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm" type="text" maxlength="60" data-field="label" value="' + esc(row.label || "") + '" placeholder="Nombre visible"></td>' +
            '<td class="py-1 px-1"><input class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm font-mono" type="text" maxlength="100" data-field="value" value="' + esc(row.value || "") + '" placeholder="Se toma del nombre"></td>' +
            '<td class="py-1 px-1 text-right"><button type="button" class="px-2 py-1 rounded-lg text-rose-700 hover:bg-rose-50 text-xs font-semibold" data-utm-remove-row aria-label="Quitar fila">Quitar</button></td></tr>';
        }
        return '<form data-config-form class="text-left space-y-5">' +
          intro("Campañas que ofrece Herramientas › Generador UTM a todo el equipo. Los valores se guardan en minúsculas, sin tildes ni espacios; los repetidos se descartan. El medio del enlace sale de la lista única de <strong>Configuración › Medios</strong>.") +
          this.lists.map(function (list) {
            return '<section class="text-left" data-utm-list="' + list[0] + '" data-required="' + (list[3] ? "1" : "0") + '"><div class="flex flex-wrap items-center justify-between gap-2 mb-1"><div><h4 class="text-sm font-bold text-slate-800">' + esc(list[1]) + '</h4><p class="text-[11px] text-slate-500">' + esc(list[2]) + "</p></div>" +
              '<button type="button" class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold" data-utm-add-row>Agregar fila</button></div>' +
              '<div class="overflow-x-auto max-h-[30vh] overflow-y-auto"><table class="w-full text-sm"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200"><th class="py-2 px-1 text-left">Nombre visible</th><th class="py-2 px-1 text-left">Valor en la URL</th><th></th></tr></thead><tbody>' +
              (settings[list[0]] || []).map(line).join("") + "</tbody></table></div><template data-utm-row-template>" + line({}) + "</template></section>";
          }).join("") + "</form>";
      },
      bind: function (popup) {
        popup.addEventListener("click", function (event) {
          var add = event.target.closest("[data-utm-add-row]");
          if (add) {
            var box = add.closest("[data-utm-list]");
            box.querySelector("tbody").insertAdjacentHTML("beforeend", box.querySelector("[data-utm-row-template]").innerHTML);
            var rows = box.querySelectorAll("tbody [data-utm-row]");
            rows[rows.length - 1].querySelector("input").focus();
            return;
          }
          var remove = event.target.closest("[data-utm-remove-row]");
          if (remove) remove.closest("tr").remove();
        });
      },
      collect: function (form) {
        var data = {};
        form.querySelectorAll("[data-utm-list]").forEach(function (box) {
          var key = box.getAttribute("data-utm-list");
          data[key] = Array.prototype.map.call(box.querySelectorAll("tbody [data-utm-row]"), function (row) {
            return { label: row.querySelector('[data-field="label"]').value.trim(), value: row.querySelector('[data-field="value"]').value.trim() };
          }).filter(function (item) { return item.label || item.value; });
          if (box.getAttribute("data-required") === "1" && !data[key].length) throw new Error("Agrega al menos una opción en " + box.querySelector("h4").textContent + ".");
        });
        return data;
      },
    },
    // Catálogos de Herramientas › Plan estratégico (las tablas CCT del plugin Gestor Estratégico de WordPress). Cada
    // catálogo se guarda completo: los cambios de nombre pasan a los registros del plan que lo usan, lo que está en uso
    // no se puede quitar y eliminar pide una confirmación.
    plan: {
      title: "Plan estratégico",
      html: function (settings) {
        var input = 'class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm" type="text"';
        function line(fields, row) {
          var used = Number(row.uso || 0);
          return '<tr data-plan-row data-id="' + esc(row.id || "") + '"><td class="py-1 px-1"><input ' + input + ' maxlength="200" data-field="nombre" value="' + esc(row.nombre || "") + '" placeholder="Nombre"></td>' +
            Object.keys(fields).map(function (field) {
              return '<td class="py-1 px-1"><input ' + input + ' maxlength="2000" data-field="' + esc(field) + '" value="' + esc(row[field] || "") + '" placeholder="' + esc(fields[field]) + '"></td>';
            }).join("") +
            '<td class="py-1 px-1 text-right text-[11px] text-slate-500">' + (used ? "En uso: " + used : "") + "</td>" +
            '<td class="py-1 px-1 text-right">' + (used ? "" : '<button type="button" class="px-2 py-1 rounded-lg text-rose-700 hover:bg-rose-50 text-xs font-semibold" data-plan-remove>Quitar</button>') + "</td></tr>";
        }
        return '<form data-config-form class="text-left space-y-3">' +
          intro("Listas que usa <strong>Herramientas › Plan estratégico</strong> (las mismas del Gestor Estratégico de WordPress). Si cambias un nombre, los objetivos, metas y campañas que lo usan quedan con el nombre nuevo. Lo que está en uso no se puede quitar.") +
          Object.keys(settings || {}).map(function (key) {
            var catalog = settings[key] || {};
            var fields = catalog.fields || {};
            return '<details class="text-left rounded-xl border border-slate-200 p-3" data-plan-catalog="' + esc(key) + '"><summary class="cursor-pointer text-sm font-bold text-slate-800">' + esc(catalog.label) + ' <span class="text-[11px] font-normal text-slate-500">(' + (catalog.rows || []).length + ")</span></summary>" +
              '<div class="flex flex-wrap items-center justify-between gap-2 my-2"><p class="text-[11px] text-slate-500">' + esc(catalog.hint || "") + '</p><button type="button" class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold" data-plan-add-row>Agregar fila</button></div>' +
              '<div class="overflow-x-auto max-h-[40vh] overflow-y-auto"><table class="w-full text-sm"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200"><th class="py-2 px-1 text-left">Nombre</th>' +
              Object.keys(fields).map(function (field) { return '<th class="py-2 px-1 text-left">' + esc(fields[field]) + "</th>"; }).join("") +
              "<th></th><th></th></tr></thead><tbody>" + (catalog.rows || []).map(function (row) { return line(fields, row); }).join("") + "</tbody></table></div>" +
              "<template data-plan-row-template>" + line(fields, {}) + "</template></details>";
          }).join("") +
          '<label class="hidden items-center gap-2 p-3 rounded-xl bg-rose-50 text-sm text-rose-800" data-plan-confirm><input type="checkbox" name="confirm_delete" value="1"><span>Confirmo que se eliminarán <b data-plan-removed-count>0</b> elemento(s) de los catálogos.</span></label>' +
          "</form>";
      },
      bind: function (popup) {
        function refresh() {
          var removed = popup.querySelectorAll('[data-plan-row][data-removed="1"]').length;
          var confirm = popup.querySelector("[data-plan-confirm]");
          confirm.classList.toggle("hidden", !removed);
          confirm.classList.toggle("flex", removed > 0);
          popup.querySelector("[data-plan-removed-count]").textContent = String(removed);
          if (!removed) confirm.querySelector("input").checked = false;
        }
        popup.addEventListener("click", function (event) {
          var add = event.target.closest("[data-plan-add-row]");
          if (add) {
            var box = add.closest("[data-plan-catalog]");
            box.querySelector("tbody").insertAdjacentHTML("beforeend", box.querySelector("[data-plan-row-template]").innerHTML);
            var rows = box.querySelectorAll("tbody [data-plan-row]");
            rows[rows.length - 1].querySelector("input").focus();
            return;
          }
          var remove = event.target.closest("[data-plan-remove]");
          if (!remove) return;
          var row = remove.closest("[data-plan-row]");
          if (!row.getAttribute("data-id")) {
            row.remove();
            return;
          }
          // Las filas guardadas quedan marcadas hasta guardar: se recuperan con "Deshacer".
          var marked = row.getAttribute("data-removed") !== "1";
          row.setAttribute("data-removed", marked ? "1" : "0");
          row.style.opacity = marked ? "0.45" : "";
          row.querySelectorAll("input").forEach(function (field) { field.disabled = marked; });
          remove.textContent = marked ? "Deshacer" : "Quitar";
          refresh();
        });
      },
      collect: function (form) {
        var data = {};
        form.querySelectorAll("[data-plan-catalog]").forEach(function (box) {
          data[box.getAttribute("data-plan-catalog")] = Array.prototype.filter.call(box.querySelectorAll("tbody [data-plan-row]"), function (row) {
            return row.getAttribute("data-removed") !== "1";
          }).map(function (row) {
            var item = { id: Number(row.getAttribute("data-id") || 0) };
            row.querySelectorAll("[data-field]").forEach(function (field) { item[field.getAttribute("data-field")] = field.value.trim(); });
            return item;
          }).filter(function (item) {
            return item.id || Object.keys(item).some(function (key) { return key !== "id" && item[key]; });
          });
        });
        var removed = form.querySelectorAll('[data-plan-row][data-removed="1"]').length;
        var confirmed = form.querySelector('[name="confirm_delete"]').checked;
        if (removed && !confirmed) throw new Error("Marca la confirmación para eliminar " + removed + " elemento(s).");
        data.confirm_delete = removed > 0 && confirmed;
        return data;
      },
    },
    // Lista única de Medios (tabla scm_medios): registros, glosario de los formularios, Generador UTM y analítica web.
    medios: {
      title: "Medios",
      manage: true,
      html: function (settings) {
        return '<div class="text-left space-y-4" data-medios-root>' + mediosBody(settings) + "</div>";
      },
      bind: function (popup, settings) { bindMedios(popup, settings); },
    },
    // Promoción › Analítica web: IPs internas, robots, retención de visitas crudas y dominios propios. El estado de los
    // resúmenes es solo lectura (lo mueve la tarea programada y la puesta al día al abrir la pantalla). Bajar los días de
    // "Conservar visitas crudas" programa un borrado que no se deshace: pide una confirmación explícita en el mismo popup.
    analitica: {
      title: "Analítica web",
      html: function (settings) {
        var status = settings.status || {};
        var purge = status.last_purge || {};
        var rebuild = status.rebuild || {};
        var number = function (value) { return value == null ? "sin dato" : Number(value).toLocaleString("es-CO"); };
        // dd/mm/aaaa hh:mm de "AAAA-MM-DD HH:MM:SS" (o dd/mm/aaaa de "AAAA-MM-DD").
        var stamp = function (value) {
          var match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value || ""));
          return match ? match[3] + "/" + match[2] + "/" + match[1] + (match[4] ? " " + match[4] + ":" + match[5] : "") : String(value || "");
        };
        var check = function (name, label, hint, on) {
          return '<label class="flex items-start gap-2 px-3 py-2 rounded-xl border border-slate-200 bg-white cursor-pointer text-left"><input type="checkbox" class="mt-1" name="' + name + '"' + (on ? " checked" : "") + '><span class="text-sm text-slate-800">' + esc(label) + '<small class="block text-[11px] text-slate-500">' + esc(hint) + "</small></span></label>";
        };
        var area = function (name, label, hint, values, placeholder) {
          return '<label class="block text-left"><span class="block text-xs font-semibold text-slate-700 mb-1">' + esc(label) + "</span>" +
            '<textarea class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm font-mono" rows="4" name="' + name + '" placeholder="' + esc(placeholder) + '">' + esc((values || []).join("\n")) + "</textarea>" +
            '<span class="block text-[11px] text-slate-500 mt-1">' + esc(hint) + "</span></label>";
        };
        var rows = [
          ["Última visita resumida", status.last_fecha ? stamp(status.last_fecha) : "Todavía no se ha resumido nada"],
          ["Visitas pendientes por resumir", number(status.pending)],
          ["Última actualización", status.updated_at ? stamp(status.updated_at) : "—"],
          ["Última limpieza de visitas crudas", purge.deleted ? number(purge.deleted) + " visitas del " + stamp(String(purge.first || "").slice(0, 10)) + " al " + stamp(String(purge.last || "").slice(0, 10)) + " (el " + stamp(purge.at) + ")" : "Nunca"],
        ];
        if (rebuild.from) rows.push(["Recálculo en curso", "Desde el " + stamp(rebuild.from) + ", va en el " + stamp(rebuild.next)]);
        var retention = Number(settings.raw_retention_days || 0);
        return '<form data-config-form class="text-left space-y-4" data-analytics-config data-initial-retention="' + esc(String(retention)) + '">' +
          intro("Cómo se cuentan las visitas en Promoción › Analítica web. Los cambios de IPs internas y de dominios propios se aplican a las visitas nuevas; para recalcular meses anteriores, pide a sistemas que recalculen desde una fecha.") +
          '<section class="space-y-3">' + area("internal_ips", "IPs internas (oficina y equipo)", "Una por línea: IP exacta (IPv4 o IPv6), rango CIDR (190.0.0.0/24) o prefijo IPv4 (190.0.0.).", settings.internal_ips, "190.0.0.10\n10.0.0.0/8") +
          '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">' +
          check("exclude_bots", "No contar robots", "Buscadores, monitores y navegadores automáticos.", settings.exclude_bots !== false) +
          check("exclude_internal", "No contar visitas internas", "Las que llegan desde las IPs internas de arriba.", settings.exclude_internal !== false) + "</div>" +
          area("own_domains", "Dominios propios", "Una por línea. Las visitas que llegan desde ellos cuentan como navegación interna, no como sitio de referencia.", settings.own_domains, "sucasainmobiliaria.com.co") +
          '<label class="block text-left"><span class="block text-xs font-semibold text-slate-700 mb-1">Conservar visitas crudas (días)</span>' +
          numberField("raw_retention_days", retention, 0, settings.max_retention_days || 3650) +
          '<span class="block text-[11px] text-slate-500 mt-1">0 = no borrar nunca. Con un número (mínimo 30), la tarea programada de sistemas borra las visitas ya resumidas más antiguas que eso y deja constancia. Las cifras de esta pantalla no cambian, pero el panel de marketing anterior pierde esas visitas en su propia analítica.</span></label>' +
          '<label class="hidden items-start gap-2 px-3 py-2 rounded-xl border border-red-200 bg-red-50 text-left cursor-pointer" data-retention-confirm><input type="checkbox" class="mt-1" name="confirm_retention"><span class="text-sm text-red-900" data-retention-confirm-text></span></label>' +
          "</section>" +
          '<section><h4 class="text-sm font-bold text-slate-800 mb-1">Estado de los resúmenes</h4>' +
          tableHtml(["Dato", "Valor"], rows.map(function (row) {
            return '<tr><td class="py-2 px-2 font-medium text-slate-800">' + esc(row[0]) + '</td><td class="py-2 px-2 text-right" data-analytics-status>' + esc(row[1]) + "</td></tr>";
          })) +
          '<details class="mt-2 text-[11px] text-slate-500"><summary class="cursor-pointer">Nota para sistemas</summary>Recalcular: <code>php bin/tracker-rollup.php --rebuild-from=AAAA-MM-DD</code>. Borrar visitas crudas según los días de arriba: <code>php bin/tracker-rollup.php --purge</code>.</details>' +
          "</section></form>";
      },
      bind: function (popup) {
        var form = popup.querySelector("[data-analytics-config]");
        var days = form.querySelector('[name="raw_retention_days"]');
        // Si deja de ser una reducción, la confirmación ya no aplica: se oculta y se desmarca.
        days.addEventListener("input", function () {
          if (!analyticsRetentionReduces(form)) analyticsRetentionConfirm(form, false);
        });
      },
      collect: function (form) {
        var days = String(form.querySelector('[name="raw_retention_days"]').value || "0").trim();
        if (!/^\d+$/.test(days)) throw new Error("Los días para conservar las visitas crudas deben ser un número entero.");
        if (Number(days) > 0 && Number(days) < 30) throw new Error("Conserva al menos 30 días de visitas crudas, o escribe 0 para no borrar nunca.");
        if (analyticsRetentionReduces(form) && !form.querySelector('[name="confirm_retention"]').checked) {
          analyticsRetentionConfirm(form, true);
          throw new Error("Confirma que entiendes el borrado de visitas crudas antes de guardar.");
        }
        return {
          internal_ips: form.querySelector('[name="internal_ips"]').value,
          own_domains: form.querySelector('[name="own_domains"]').value,
          exclude_bots: form.querySelector('[name="exclude_bots"]').checked,
          exclude_internal: form.querySelector('[name="exclude_internal"]').checked,
          raw_retention_days: days,
        };
      },
    },
  };

  // Configuración › Analítica web: ¿los días de "Conservar visitas crudas" bajan (de 0 = nunca, o de un número mayor)?
  function analyticsRetentionReduces(form) {
    var initial = Number(form.getAttribute("data-initial-retention") || 0);
    var days = Number(String(form.querySelector('[name="raw_retention_days"]').value || "0").trim());
    return days > 0 && (initial === 0 || days < initial);
  }

  function analyticsRetentionConfirm(form, show) {
    var box = form.querySelector("[data-retention-confirm]");
    var input = box.querySelector("input");
    var days = String(form.querySelector('[name="raw_retention_days"]').value || "0").trim();
    box.querySelector("[data-retention-confirm-text]").textContent = "Entiendo que se conservan solo los últimos " + days +
      " días de visitas crudas: las más antiguas se borran y no se pueden recuperar. El panel de marketing anterior también las pierde.";
    box.classList.toggle("hidden", !show);
    box.classList.toggle("flex", show);
    if (!show) input.checked = false;
  }

  // ── Configuración › Medios ────────────────────────────────────────────
  var MEDIOS_ACTIONS = { rename: "Renombrar", aliases: "Alias", merge: "Unir con…", toggle: "" };

  function number(value) { return Number(value || 0).toLocaleString("es-CO"); }

  function tableCounts(counts, labels) {
    var parts = Object.keys(counts || {}).filter(function (table) { return Number(counts[table]) > 0; }).map(function (table) {
      return esc((labels || {})[table] || table) + ": " + number(counts[table]);
    });
    return parts.length ? parts.join(" · ") : "Ningún registro";
  }

  function medioOptions(medios, exceptId) {
    return '<option value="">Elige un medio…</option>' + (medios || []).filter(function (row) { return row.id !== exceptId; }).map(function (row) {
      return '<option value="' + esc(String(row.id)) + '">' + esc(row.nombre) + (row.activo ? "" : " (inactivo)") + "</option>";
    }).join("");
  }

  function mediosBody(settings) {
    var medios = settings.medios || [];
    var usage = settings.usage || {};
    var ready = !!settings.ready;
    var unresolved = settings.unresolved || { records: [], variants: [], visits: [] };
    var labels = settings.tables || {};
    var button = function (attrs, text, tone) {
      return '<button type="button" class="px-2 py-1 rounded-lg text-xs font-semibold ' + (tone || "bg-slate-100 text-slate-700 hover:bg-slate-200") + '" ' + attrs + ">" + esc(text) + "</button>";
    };
    var html = '<p class="hidden" data-medios-status role="status" aria-live="polite"></p>' + intro("Una sola lista de medios (cómo llegó el cliente o la visita) para las columnas <strong>medio</strong> de llamadas, tareas, cierres, contratos, estudios y solicitudes, el glosario <strong>Medio de llamada</strong> de los formularios, el Generador UTM y la analítica web. Renombrar o unir cambia los registros con ese nombre exacto y deja el nombre anterior como alias.");
    if (!ready) {
      html += '<p class="text-sm rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-3 py-2" data-medios-not-ready>La lista todavía no está instalada en la base: pide a sistemas que ejecuten <code>php bin/medios-normalize.php --apply</code>. Mientras tanto se muestra la lista inicial y no se puede editar.</p>';
    }
    if (settings.error) html += '<p class="text-sm text-rose-700">' + esc(settings.error) + "</p>";
    if (settings.analytics_pending) {
      html += '<p class="text-xs rounded-xl border border-sky-200 bg-sky-50 text-sky-900 px-3 py-2" data-medios-analytics-pending>La analítica web aplica los últimos cambios de la lista en la siguiente corrida de la tarea programada (cada pocos minutos).</p>';
    }
    if (ready) {
      html += '<div class="flex flex-wrap items-end gap-2"><label class="flex-1 min-w-[12rem] text-left"><span class="block text-xs font-semibold text-slate-700 mb-1">Nuevo medio</span>' +
        '<input class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm" type="text" maxlength="60" data-medios-new placeholder="Ej.: Volante"></label>' +
        button("data-medios-add", "Agregar", "bg-slate-800 text-white hover:bg-slate-700") + "</div>";
    }
    html += '<section><h4 class="text-sm font-bold text-slate-800 mb-1">Lista (' + medios.length + ")</h4>" +
      '<div class="overflow-x-auto max-h-[38vh] overflow-y-auto border border-slate-200 rounded-xl"><table class="w-full text-sm text-left"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200 bg-slate-50">' +
      '<th class="py-2 px-2">Medio</th><th class="py-2 px-2">Valor UTM</th><th class="py-2 px-2">Alias</th><th class="py-2 px-2 text-right">Registros</th><th class="py-2 px-2"></th></tr></thead><tbody class="divide-y divide-slate-100">' +
      medios.map(function (row) {
        var actions = ready ? Object.keys(MEDIOS_ACTIONS).map(function (action) {
          var text = action === "toggle" ? (row.activo ? "Desactivar" : "Activar") : MEDIOS_ACTIONS[action];
          var tone = action === "toggle" && row.activo ? "bg-rose-50 text-rose-700 hover:bg-rose-100" : "";
          return button('data-medios-action="' + action + '" data-id="' + esc(String(row.id)) + '"', text, tone);
        }).join(" ") : "";
        return '<tr data-medio-row="' + esc(row.nombre) + '"' + (row.activo ? "" : ' class="opacity-60"') + '><td class="py-2 px-2 font-medium text-slate-800">' + esc(row.nombre) +
          (row.activo ? "" : ' <span class="text-[10px] font-bold uppercase text-slate-500">inactivo</span>') + "</td>" +
          '<td class="py-2 px-2"><code class="text-xs">' + esc(row.slug) + "</code></td>" +
          '<td class="py-2 px-2 text-xs text-slate-600">' + esc((row.aliases || []).join(", ")) + "</td>" +
          '<td class="py-2 px-2 text-right">' + number(usage[row.nombre]) + "</td>" +
          '<td class="py-2 px-2 text-right whitespace-nowrap">' + actions + "</td></tr>";
      }).join("") + "</tbody></table></div>" +
      '<p class="text-[11px] text-slate-500 mt-1">Un medio inactivo no se ofrece en los formularios ni en el Generador UTM, pero sus registros y visitas se siguen reconociendo. Las visitas sin enlace UTM cuentan como «' + esc(settings.direct || "Sin medio (directo)") + "».</p></section>";

    var rawRows = function (items, kind) {
      return items.map(function (item) {
        var where = kind === "visits" ? esc((item.pairs || []).slice(0, 3).join(" | ")) : tableCounts(item.tables, labels);
        var controls = "";
        if (ready) {
          controls = '<select class="px-2 py-1 rounded-lg border border-slate-300 text-xs max-w-[11rem]" data-medios-raw-target>' + medioOptions(medios, null) + "</select> " +
            (kind !== "variants" ? button('data-medios-raw="alias"', "Agregar como alias") + " " : "") +
            (kind !== "visits" ? button('data-medios-raw="rewrite"', "Reescribir registros", "bg-amber-100 text-amber-900 hover:bg-amber-200") : "");
        }
        return '<tr data-medios-raw-row="' + esc(item.value) + '" data-kind="' + kind + '"><td class="py-2 px-2 font-medium text-slate-800">' + esc(item.value) +
          (item.medio ? ' <span class="text-[11px] text-slate-500">→ ' + esc(item.medio) + "</span>" : "") + "</td>" +
          '<td class="py-2 px-2 text-right">' + number(item.total) + '</td><td class="py-2 px-2 text-xs text-slate-600">' + where + "</td>" +
          '<td class="py-2 px-2 text-right whitespace-nowrap">' + controls + "</td></tr>";
      }).join("");
    };
    var groups = [
      ["records", "En los registros", "Valores que no corresponden a ningún medio. «Agregar como alias» los reconoce sin cambiar los registros; «Reescribir registros» los cambia por el medio elegido."],
      ["variants", "Escritos distinto", "Ya se reconocen (mayúsculas, tildes o alias), pero los registros no tienen el nombre exacto del medio."],
      ["visits", "En las visitas web", "utm_source / utm_medium que no se reconocen (se muestran con su texto en la analítica). Solo se pueden agregar como alias."],
    ];
    var total = groups.reduce(function (sum, group) { return sum + (unresolved[group[0]] || []).length; }, 0);
    html += '<section data-medios-unresolved><h4 class="text-sm font-bold text-slate-800 mb-1">Valores sin reconocer (' + total + ")</h4>" +
      (total === 0 ? '<p class="text-sm text-slate-600">Todos los valores de los registros y de las visitas corresponden a un medio de la lista.</p>' : groups.map(function (group) {
        var items = unresolved[group[0]] || [];
        if (!items.length) return "";
        return '<div class="mb-3"><p class="text-xs font-semibold text-slate-700">' + esc(group[1]) + '</p><p class="text-[11px] text-slate-500 mb-1">' + esc(group[2]) + "</p>" +
          '<div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200"><th class="py-2 px-2">Valor</th><th class="py-2 px-2 text-right">Cantidad</th><th class="py-2 px-2">Dónde</th><th class="py-2 px-2"></th></tr></thead><tbody class="divide-y divide-slate-100">' +
          rawRows(items, group[0]) + "</tbody></table></div></div>";
      }).join("")) + "</section>";

    var history = settings.history || [];
    if (history.length) {
      var actionLabels = { seed: "Lista creada", add: "Agregado", rename: "Renombrado", merge: "Unido", activate: "Activado", deactivate: "Desactivado", aliases: "Alias", alias: "Alias", rewrite: "Registros reescritos", normalize: "Normalización", revert: "Revertido", "import": "Importado del glosario" };
      html += '<details class="text-left"><summary class="cursor-pointer text-sm font-bold text-slate-800">Historial de cambios</summary><ul class="mt-2 space-y-1 text-xs text-slate-600" data-medios-history>' +
        history.map(function (row) {
          var records = row.detail && row.detail.records ? Object.keys(row.detail.records).reduce(function (sum, key) { return sum + Number(row.detail.records[key] || 0); }, 0) : null;
          return "<li><strong>" + esc(actionLabels[row.action] || row.action) + "</strong> " + esc([row.from, row.to].filter(Boolean).join(" → ")) +
            (records !== null ? " · " + number(records) + " registros" : "") + ' <span class="text-slate-400">' + esc(row.at) + (row.id_empleado ? " · funcionario " + esc(row.id_empleado) : "") + " · #" + esc(String(row.id)) + "</span></li>";
        }).join("") + "</ul></details>";
    }
    // Panel interno de confirmación (renombrar, alias, unir, desactivar, reescribir): no abre otro popup.
    html += '<div class="hidden fixed inset-0 z-[2000] items-center justify-center bg-slate-900/40 p-4" data-medios-panel role="dialog" aria-modal="true">' +
      '<div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-4 text-left space-y-3" data-medios-panel-box></div></div>';
    return html;
  }

  function bindMedios(popup, initial) {
    var root = popup.querySelector("[data-medios-root]");
    var state = { settings: initial || {} };
    var panel = function () { return root.querySelector("[data-medios-panel]"); };

    function render(settings) {
      state.settings = settings || state.settings;
      root.innerHTML = mediosBody(state.settings);
    }

    // Mensajes dentro del popup (notify abriría otro SweetAlert y cerraría esta lista).
    function status(type, message) {
      var line = root.querySelector("[data-medios-status]");
      line.textContent = message || "";
      line.setAttribute("data-type", type);
      line.className = "text-sm rounded-xl px-3 py-2 " + (type === "error" ? "bg-rose-50 text-rose-800 border border-rose-200" : "bg-emerald-50 text-emerald-800 border border-emerald-200");
    }

    function find(id) {
      return (state.settings.medios || []).filter(function (row) { return String(row.id) === String(id); })[0] || null;
    }

    function op(payload) {
      return request("commercial_settings_save", { section: "medios", payload: JSON.stringify(payload) });
    }

    function closePanel() {
      var box = panel();
      box.classList.add("hidden");
      box.classList.remove("flex");
    }

    // Abre el panel con un cuerpo y un botón de confirmar; onConfirm devuelve la operación a enviar.
    function openPanel(title, body, confirmText, onConfirm, danger) {
      var box = panel();
      box.querySelector("[data-medios-panel-box]").innerHTML = '<h4 class="text-base font-bold text-slate-800">' + esc(title) + "</h4>" + body +
        '<p class="text-sm text-rose-700 hidden" data-medios-panel-error></p><div class="flex justify-end gap-2">' +
        '<button type="button" class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm font-semibold" data-medios-panel-cancel>Cancelar</button>' +
        '<button type="button" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white ' + (danger ? "bg-rose-600 hover:bg-rose-700" : "bg-slate-800 hover:bg-slate-700") + '" data-medios-panel-confirm>' + esc(confirmText) + "</button></div>";
      box.classList.remove("hidden");
      box.classList.add("flex");
      var first = box.querySelector("input, textarea, select");
      if (first) first.focus();
      box.querySelector("[data-medios-panel-cancel]").addEventListener("click", closePanel);
      box.querySelector("[data-medios-panel-confirm]").addEventListener("click", function () {
        var error = box.querySelector("[data-medios-panel-error]");
        var payload;
        try {
          payload = onConfirm(box);
        } catch (problem) {
          error.textContent = problem.message;
          error.classList.remove("hidden");
          return;
        }
        var confirm = box.querySelector("[data-medios-panel-confirm]");
        confirm.disabled = true;
        op(payload).then(function (data) {
          closePanel();
          render((data.settings || {}).medios);
          status("success", data.message || "Cambios guardados.");
        }).catch(function (problem) {
          confirm.disabled = false;
          error.textContent = problem.message;
          error.classList.remove("hidden");
        });
      });
    }

    // Cuántos registros cambiarían (por tabla) antes de confirmar.
    function previewHtml(value, verb) {
      return op({ op: "preview", value: value }).then(function (data) {
        var result = data.result || {};
        return '<p class="text-sm text-slate-700" data-medios-preview data-total="' + esc(String(result.total || 0)) + '">' + esc(verb) + " <strong>" + number(result.total) + " registros</strong>" +
          (result.total ? " (" + tableCounts(result.preview, state.settings.tables) + ")" : "") + ".</p>";
      });
    }

    root.addEventListener("click", function (event) {
      var add = event.target.closest("[data-medios-add]");
      if (add) {
        var input = root.querySelector("[data-medios-new]");
        if (!input.value.trim()) { input.focus(); return; }
        add.disabled = true;
        op({ op: "add", nombre: input.value }).then(function (data) {
          render((data.settings || {}).medios);
          status("success", data.message);
        }).catch(function (problem) {
          add.disabled = false;
          status("error", problem.message);
        });
        return;
      }
      var action = event.target.closest("[data-medios-action]");
      if (action) {
        var medio = find(action.getAttribute("data-id"));
        if (!medio) return;
        var kind = action.getAttribute("data-medios-action");
        if (kind === "aliases") {
          openPanel("Alias de «" + medio.nombre + "»", '<p class="text-sm text-slate-600">Otras formas en que llega este medio (en los registros o en utm_source / utm_medium), una por línea. Se comparan sin mayúsculas, tildes ni signos.</p>' +
            '<textarea class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm font-mono" rows="6" data-medios-aliases>' + esc((medio.aliases || []).join("\n")) + "</textarea>", "Guardar alias", function (box) {
            return { op: "aliases", id: medio.id, aliases: box.querySelector("[data-medios-aliases]").value.split(/\r?\n/) };
          });
          return;
        }
        if (kind === "toggle" && !medio.activo) {
          op({ op: "toggle", id: medio.id, active: 1 }).then(function (data) {
            render((data.settings || {}).medios);
            status("success", data.message);
          }).catch(function (problem) { status("error", problem.message); });
          return;
        }
        previewHtml(medio.nombre, kind === "toggle" ? "Lo usan" : "Cambiarán").then(function (preview) {
          if (kind === "toggle") {
            openPanel("Desactivar «" + medio.nombre + "»", preview + '<p class="text-sm text-slate-600">Deja de ofrecerse en el glosario de los formularios y en el Generador UTM. Los registros no cambian y se sigue reconociendo en la analítica.</p>', "Desactivar", function () {
              return { op: "toggle", id: medio.id, active: 0 };
            }, true);
          } else if (kind === "rename") {
            openPanel("Renombrar «" + medio.nombre + "»", '<label class="block"><span class="block text-xs font-semibold text-slate-700 mb-1">Nombre nuevo</span><input class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm" type="text" maxlength="60" data-medios-rename value="' + esc(medio.nombre) + '"></label>' +
              preview + '<p class="text-sm text-slate-600">Los registros con el nombre exacto «' + esc(medio.nombre) + "» pasan al nombre nuevo, y «" + esc(medio.nombre) + "» queda como alias (los enlaces UTM ya publicados siguen contando).</p>", "Renombrar", function (box) {
              var name = box.querySelector("[data-medios-rename]").value.trim();
              if (!name) throw new Error("Escribe el nombre nuevo.");
              return { op: "rename", id: medio.id, nombre: name };
            }, true);
          } else if (kind === "merge") {
            openPanel("Unir «" + medio.nombre + "» con otro medio", '<label class="block"><span class="block text-xs font-semibold text-slate-700 mb-1">Medio que se conserva</span><select class="w-full px-2 py-1.5 rounded-lg border border-slate-300 text-sm" data-medios-merge-target>' + medioOptions(state.settings.medios, medio.id) + "</select></label>" +
              preview + '<p class="text-sm text-slate-600">«' + esc(medio.nombre) + "» sale de la lista: sus registros pasan al medio elegido y su nombre, valor UTM y alias quedan como alias de ese medio. Queda en el historial.</p>", "Unir", function (box) {
              var target = box.querySelector("[data-medios-merge-target]").value;
              if (!target) throw new Error("Elige el medio que se conserva.");
              return { op: "merge", id: medio.id, target: Number(target) };
            }, true);
          }
        }).catch(function (problem) { status("error", problem.message); });
        return;
      }
      var raw = event.target.closest("[data-medios-raw]");
      if (raw) {
        var row = raw.closest("[data-medios-raw-row]");
        var value = row.getAttribute("data-medios-raw-row");
        var targetId = row.querySelector("[data-medios-raw-target]").value;
        var target = find(targetId);
        if (!target) { status("error", "Elige el medio al que corresponde «" + value + "»."); return; }
        if (raw.getAttribute("data-medios-raw") === "alias") {
          op({ op: "alias_raw", value: value, target: target.id }).then(function (data) {
            render((data.settings || {}).medios);
            status("success", data.message);
          }).catch(function (problem) { status("error", problem.message); });
          return;
        }
        previewHtml(value, "Cambiarán").then(function (preview) {
          openPanel("Reescribir «" + value + "»", preview + '<p class="text-sm text-slate-600">Los registros con el valor exacto «' + esc(value) + "» quedan con «" + esc(target.nombre) + "». El valor anterior de cada registro queda guardado en el historial.</p>", "Reescribir registros", function () {
            return { op: "rewrite_raw", value: value, target: target.id };
          }, true);
        }).catch(function (problem) { status("error", problem.message); });
      }
    });
    root.addEventListener("keydown", function (event) {
      if (event.key === "Enter" && event.target.matches("[data-medios-new]")) {
        event.preventDefault();
        root.querySelector("[data-medios-add]").click();
      }
    });
  }

  function openSection(key) {
    var section = sections[key];
    if (!section || !window.Swal) return;
    window.Swal.fire({ title: section.title, text: "Cargando configuración...", allowOutsideClick: false, didOpen: function () { window.Swal.showLoading(); } });
    request("commercial_settings_load", { section: key }).then(function (data) {
      var settings = (data.settings || {})[key] || {};
      return Promise.resolve(section.prepare ? section.prepare(settings) : settings);
    }).then(function (prepared) {
      return window.Swal.fire({
        title: section.title,
        html: section.html(prepared),
        width: section.manage ? 980 : 760,
        showCancelButton: true,
        // Las secciones que administran una lista guardan cada acción al confirmarla: no tienen botón Guardar.
        showConfirmButton: !section.manage,
        confirmButtonText: "Guardar",
        cancelButtonText: "Cerrar",
        customClass: { popup: "scm-calendar-swal-popup" + (section.manage ? " scm-config-manage" : "") },
        didOpen: function () {
          var popup = window.Swal.getPopup();
          popup.querySelectorAll("[data-toggle-all]").forEach(function (button) {
            button.addEventListener("click", function () {
              var value = button.getAttribute("data-value") === "1";
              popup.querySelectorAll('[name="' + button.getAttribute("data-toggle-all") + '"]').forEach(function (input) { input.checked = value; });
            });
          });
          if (section.bind) section.bind(popup, prepared);
        },
        preConfirm: function () {
          var form = window.Swal.getPopup().querySelector("[data-config-form]");
          if (!form.reportValidity()) return false;
          var payload;
          try {
            payload = section.collect(form);
          } catch (error) {
            window.Swal.showValidationMessage(error.message);
            return false;
          }
          return request("commercial_settings_save", { section: key, payload: JSON.stringify(payload) }).catch(function (error) {
            window.Swal.showValidationMessage(error.message || "No se pudo guardar la configuración.");
            return false;
          });
        },
      });
    }).then(function (result) {
      if (!result || !result.isConfirmed || !result.value) return;
      if (section.after) section.after((result.value.settings || {})[key] || {});
      notify("success", result.value.message || "Configuración guardada.");
    }).catch(function (error) {
      notify("error", (error && error.message) || "No se pudo cargar la configuración.");
    });
  }

  menu.addEventListener("click", function (event) {
    var item = event.target.closest("[data-commercial-config-action]");
    if (!item) return;
    var action = item.getAttribute("data-commercial-config-action");
    setMenu(false);
    // "permissions" abre su modal desde commercial-dashboard.js (id commercial-open-permissions).
    if (action !== "permissions") openSection(action);
  });
})();
