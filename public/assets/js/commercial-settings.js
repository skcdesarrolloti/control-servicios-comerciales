/*
 * Menú "Configuración" del encabezado comercial. Cada opción abre un popup con su sección:
 * calendario, tiempos de atención, avisos, actualizaciones de inmuebles, cupos de destacados y calculadora de aseo.
 * Permisos reutiliza su modal existente.
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
  };

  function openSection(key) {
    var section = sections[key];
    if (!section || !window.Swal) return;
    window.Swal.fire({ title: section.title, text: "Cargando configuración...", allowOutsideClick: false, didOpen: function () { window.Swal.showLoading(); } });
    request("commercial_settings_load").then(function (data) {
      var settings = (data.settings || {})[key] || {};
      return Promise.resolve(section.prepare ? section.prepare(settings) : settings);
    }).then(function (prepared) {
      return window.Swal.fire({
        title: section.title,
        html: section.html(prepared),
        width: 760,
        showCancelButton: true,
        confirmButtonText: "Guardar",
        cancelButtonText: "Cerrar",
        customClass: { popup: "scm-calendar-swal-popup" },
        didOpen: function () {
          var popup = window.Swal.getPopup();
          popup.querySelectorAll("[data-toggle-all]").forEach(function (button) {
            button.addEventListener("click", function () {
              var value = button.getAttribute("data-value") === "1";
              popup.querySelectorAll('[name="' + button.getAttribute("data-toggle-all") + '"]').forEach(function (input) { input.checked = value; });
            });
          });
          if (section.bind) section.bind(popup);
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
