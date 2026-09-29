(function () {
  "use strict";

  var root = document.getElementById("scm-app");
  if (!root) return;

  var runtime = {};
  try {
    runtime = JSON.parse(root.getAttribute("data-scm-runtime") || "{}");
  } catch (_error) {}

  var apiUrl = runtime.ajaxUrl || "api.php";
  var nonce = runtime.nonce || "";
  var ticketsPanel = root.querySelector("[data-commercial-tickets-panel]");
  var calendarPanel = root.querySelector("[data-commercial-calendar-panel]");
  var tabsNav = root.querySelector("[data-commercial-tabs]");
  var globalFilter = root.querySelector("[data-commercial-global-filter-form]");
  var caseModal = document.getElementById("commercial-case-modal");
  var caseContent = caseModal && caseModal.querySelector("[data-commercial-case-content]");
  var advisoryModal = null;
  var advisoryQueueActive = false;
  var currentCasePk = "";
  var lastFocused = null;
  var listRequest = null;
  var detailRequest = null;
  var actionMap = {
    reply: "commercial_ticket_reply",
    note: "commercial_ticket_note",
    follow_up: "commercial_ticket_follow_up",
    postpone: "commercial_ticket_postpone",
    activate: "commercial_ticket_activate",
    close: "commercial_ticket_close",
    status: "commercial_ticket_status",
    reassign: "commercial_ticket_reassign",
  };

  function loadingMarkup() {
    return (
      '<div class="w-full py-28 flex flex-col items-center justify-center text-center p-8">' +
        '<div class="relative w-16 h-16 mb-4 flex items-center justify-center">' +
          '<div class="absolute inset-0 rounded-2xl bg-gradient-to-tr from-[#F8CF4A]/30 to-[#1E3C76]/20 animate-pulse"></div>' +
          '<div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#061D49] to-[#1E3C76] flex items-center justify-center shadow-lg text-white">' +
            '<span class="material-symbols-outlined text-[28px] text-[#F8CF4A]">task_alt</span>' +
          '</div>' +
        '</div>' +
        '<h3 class="text-base font-bold text-[#061D49]">Cargando información de la tarea…</h3>' +
        '<p class="text-xs text-slate-400 mt-1 max-w-sm">Obteniendo expediente comercial, historial de gestiones y opciones disponibles</p>' +
        '<div class="sicv-search-modal__progress mt-5"><span></span><span></span><span></span></div>' +
      '</div>'
    );
  }

  function notify(type, message) {
    if (window.Swal && typeof window.Swal.fire === "function") {
      window.Swal.fire({
        toast: true,
        position: "top-end",
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: type === "success" ? 2600 : 5000,
        timerProgressBar: true,
      });
      return;
    }
    window.alert(message);
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function assistantList(items, bulletColor) {
    if (!Array.isArray(items) || !items.length) {
      return '<p class="text-xs text-slate-400 italic py-1">Sin hallazgos registrados.</p>';
    }
    var bullet = bulletColor || "bg-[#1E3C76]";
    return (
      '<ul class="space-y-1.5">' +
      items
        .map(function (item) {
          return (
            '<li class="flex items-start gap-2 text-xs text-slate-700 leading-relaxed">' +
            '<span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 ' +
            bullet +
            '"></span>' +
            '<span>' +
            escapeHtml(item) +
            "</span>" +
            "</li>"
          );
        })
        .join("") +
      "</ul>"
    );
  }

  function assistantText(value) {
    if (value === null || typeof value === "undefined") return "";
    if (Array.isArray(value)) {
      return value.map(assistantText).filter(Boolean).join(" · ");
    }
    if (typeof value === "object") {
      return Object.keys(value).map(function (key) {
        var text = assistantText(value[key]);
        return text ? key + ": " + text : "";
      }).filter(Boolean).join(" · ");
    }
    var text = String(value || "").trim();
    return text.toLowerCase() === "array" ? "" : text;
  }

  function assistantCard(title, content, icon, colorTheme) {
    if (!content) return "";
    var themes = {
      client: { bg: "bg-slate-50/70", border: "border-slate-200", iconColor: "text-[#1E3C76]", titleColor: "text-[#061D49]" },
      status: { bg: "bg-blue-50/50", border: "border-blue-100", iconColor: "text-[#1E3C76]", titleColor: "text-[#061D49]" },
      risks: { bg: "bg-rose-50/50", border: "border-rose-100", iconColor: "text-rose-600", titleColor: "text-rose-900" },
      opps: { bg: "bg-emerald-50/50", border: "border-emerald-100", iconColor: "text-emerald-600", titleColor: "text-emerald-900" },
      recs: { bg: "bg-sky-50/50", border: "border-sky-100", iconColor: "text-[#1E3C76]", titleColor: "text-[#061D49]" },
      steps: { bg: "bg-amber-50/50", border: "border-amber-100", iconColor: "text-amber-600", titleColor: "text-amber-900" },
      missing: { bg: "bg-slate-50", border: "border-slate-200", iconColor: "text-slate-500", titleColor: "text-slate-700" },
    };
    var t = themes[colorTheme] || themes.client;
    return (
      '<div class="p-4 rounded-2xl ' + t.bg + ' border ' + t.border + ' shadow-sm flex flex-col gap-2.5">' +
        '<div class="flex items-center gap-2">' +
          (icon ? '<span class="material-symbols-outlined text-[18px] ' + t.iconColor + '">' + icon + '</span>' : '') +
          '<h4 class="text-xs font-bold uppercase tracking-wider ' + t.titleColor + '">' + escapeHtml(title) + '</h4>' +
        '</div>' +
        '<div class="text-xs text-slate-700 leading-relaxed">' + content + '</div>' +
      '</div>'
    );
  }

  function assistantLoadingMarkup() {
    return (
      '<div class="p-8 sm:p-14 flex flex-col items-center justify-center text-center">' +
        '<div class="relative w-24 h-24 mb-6 flex items-center justify-center">' +
          '<div class="absolute inset-0 rounded-3xl bg-gradient-to-tr from-[#F8CF4A]/30 to-[#1E3C76]/20 animate-pulse"></div>' +
          '<div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-[#061D49] to-[#1E3C76] flex items-center justify-center shadow-xl text-white">' +
            '<span class="material-symbols-outlined text-[36px] text-[#F8CF4A] sicv-ai-icon-rotate">auto_awesome</span>' +
          '</div>' +
          '<span class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-[#F8CF4A] text-[#061D49] flex items-center justify-center font-bold text-xs shadow-md border-2 border-white">IA</span>' +
        '</div>' +
        '<span class="text-[11px] font-bold uppercase tracking-widest text-amber-700 bg-amber-50 px-3 py-1 rounded-full border border-amber-200 mb-3">Asistente Inteligente SuCasa</span>' +
        '<h2 class="text-xl sm:text-2xl font-bold text-[#061D49] tracking-tight">Analizando tarea comercial…</h2>' +
        '<p class="text-xs sm:text-sm text-slate-500 max-w-md mt-2 leading-relaxed">Estoy evaluando el historial completo, estado del cliente, características del inmueble, riesgos, oportunidades y generando recomendaciones estratégicas en tiempo real.</p>' +
        '<div class="sicv-search-modal__progress mt-6"><span></span><span></span><span></span></div>' +
      '</div>'
    );
  }

  function assistantErrorMarkup(message) {
    return (
      '<div class="p-8 sm:p-12 flex flex-col items-center justify-center text-center">' +
        '<div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mb-4 border border-rose-200">' +
          '<span class="material-symbols-outlined text-[32px]">error</span>' +
        '</div>' +
        '<h3 class="text-base font-bold text-slate-800">No se pudo generar el análisis</h3>' +
        '<p class="text-xs text-slate-500 mt-2 max-w-sm leading-relaxed">' + escapeHtml(message || "Inténtalo nuevamente en unos momentos.") + '</p>' +
        '<button type="button" class="mt-6 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-assistant>' +
          'Cerrar' +
        '</button>' +
      '</div>'
    );
  }

  function assistantAnalysisMarkup(analysis) {
    analysis = analysis || {};
    var summary = escapeHtml(analysis.resumen || "Sin resumen ejecutivo generado.");
    var clientText = assistantText(analysis.cliente);
    var statusText = assistantText(analysis.estado_actual);
    var clientHtml = clientText ? '<p class="text-xs text-slate-700">' + escapeHtml(clientText) + '</p>' : '';
    var statusHtml = statusText ? '<p class="text-xs text-slate-700 font-medium">' + escapeHtml(statusText) + '</p>' : '';
    var modelName = escapeHtml(analysis.model || "MiniMax / IA");
    var author = escapeHtml(analysis.created_by ? ("Guardado por " + analysis.created_by) : "Generado por IA");
    var dateLabel = escapeHtml(analysis.created_label || analysis.generated_at || "");

    var suggestedMessage = analysis.mensaje_sugerido ? String(analysis.mensaje_sugerido).trim() : "";
    var suggestedBlock = "";
    if (suggestedMessage) {
      suggestedBlock =
        '<div class="col-span-1 md:col-span-2 bg-[#061D49]/5 border border-[#1E3C76]/20 rounded-2xl p-5 relative overflow-hidden commercial-assistant-suggested" data-purpose="suggested-message-card">' +
          '<div class="flex flex-wrap items-center justify-between gap-3 mb-3">' +
            '<div class="flex items-center gap-2">' +
              '<span class="material-symbols-outlined text-[20px] text-[#1E3C76]">mark_chat_unread</span>' +
              '<h4 class="text-xs font-bold uppercase tracking-wider text-[#061D49]">Mensaje Sugerido para el Cliente</h4>' +
            '</div>' +
            '<div class="flex items-center gap-2">' +
              '<button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold shadow-sm transition-colors cursor-pointer" data-commercial-copy-assistant-message>' +
                '<span class="material-symbols-outlined text-[15px] text-slate-500">content_copy</span>' +
                '<span>Copiar</span>' +
              '</button>' +
              '<button type="button" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-[#061D49] hover:bg-[#1E3C76] text-white text-xs font-semibold shadow-sm transition-all cursor-pointer" data-commercial-use-assistant-message>' +
                '<span class="material-symbols-outlined text-[15px] text-[#F8CF4A]">reply</span>' +
                '<span>Usar en respuesta</span>' +
              '</button>' +
            '</div>' +
          '</div>' +
          '<blockquote class="text-xs sm:text-sm text-slate-800 bg-white/80 p-4 rounded-xl border border-slate-200/80 leading-relaxed font-normal italic">' +
            escapeHtml(suggestedMessage) +
          '</blockquote>' +
        '</div>';
    }

    return (
      '<!-- Modal Header -->' +
      '<div class="px-6 py-4 bg-gradient-to-r from-[#061D49] to-[#1E3C76] text-white flex items-center justify-between shrink-0">' +
        '<div class="flex items-center gap-3">' +
          '<div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center text-[#F8CF4A] border border-white/15">' +
            '<span class="material-symbols-outlined text-[22px]">auto_awesome</span>' +
          '</div>' +
          '<div>' +
            '<div class="flex items-center gap-2">' +
              '<h3 class="text-base font-bold text-white tracking-tight" id="commercial-analysis-title">Diagnóstico Estratégico Comercial</h3>' +
              '<span class="text-[10px] font-bold uppercase tracking-wider bg-[#F8CF4A] text-[#061D49] px-2 py-0.5 rounded-full font-mono">' + modelName + '</span>' +
            '</div>' +
            '<p class="text-xs text-white/70 mt-0.5">' + author + (dateLabel ? ' · ' + dateLabel : '') + '</p>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors cursor-pointer" data-commercial-close-assistant aria-label="Cerrar análisis">' +
          '<span class="material-symbols-outlined text-[20px]">close</span>' +
        '</button>' +
      '</div>' +

      '<!-- Modal Body -->' +
      '<div class="p-6 overflow-y-auto space-y-5 flex-1">' +
        '<!-- Executive Summary Banner -->' +
        '<div class="rounded-2xl p-5 bg-gradient-to-br from-amber-500/10 via-amber-100/30 to-[#1E3C76]/5 border border-amber-200/80 shadow-sm">' +
          '<div class="flex items-center gap-2 mb-2">' +
            '<span class="material-symbols-outlined text-[20px] text-amber-600">psychology</span>' +
            '<h4 class="text-xs font-bold uppercase tracking-wider text-amber-900">Resumen Ejecutivo del Caso</h4>' +
          '</div>' +
          '<p class="text-xs sm:text-sm text-slate-800 leading-relaxed">' + summary + '</p>' +
        '</div>' +

        '<!-- 2-Col Findings Grid -->' +
        '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">' +
          (clientHtml ? assistantCard("Cliente", clientHtml, "person", "client") : "") +
          (statusHtml ? assistantCard("Estado Comercial", statusHtml, "info", "status") : "") +
          assistantCard("Riesgos Detectados", assistantList(analysis.riesgos, "bg-rose-500"), "warning", "risks") +
          assistantCard("Oportunidades Comerciales", assistantList(analysis.oportunidades, "bg-emerald-500"), "trending_up", "opps") +
          assistantCard("Recomendaciones de Acción", assistantList(analysis.recomendaciones, "bg-[#1E3C76]"), "lightbulb", "recs") +
          assistantCard("Próximos Pasos Sugeridos", assistantList(analysis.proximos_pasos, "bg-amber-500"), "check_circle", "steps") +
          (Array.isArray(analysis.datos_faltantes) && analysis.datos_faltantes.length ? assistantCard("Datos Faltantes", assistantList(analysis.datos_faltantes, "bg-slate-400"), "help", "missing") : "") +
          suggestedBlock +
        '</div>' +
      '</div>' +

      '<!-- Modal Footer -->' +
      '<div class="px-6 py-3.5 bg-slate-50 border-t border-slate-100 flex items-center justify-between shrink-0">' +
        '<span class="text-xs text-slate-400">Análisis asistido con IA para toma de decisiones ágil</span>' +
        '<button type="button" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-semibold transition-colors cursor-pointer" data-commercial-close-assistant>' +
          'Cerrar Ventana' +
        '</button>' +
      '</div>'
    );
  }

  function analysisListMarkup(ticketPk, analyses) {
    analyses = Array.isArray(analyses) ? analyses : [];
    if (!analyses.length) {
      return '<div class="py-4 text-center text-slate-400 text-xs"><p>Aún no hay análisis guardados con IA.</p></div>';
    }
    return analyses
      .map(function (analysis) {
        analysis = analysis || {};
        var id = escapeHtml(analysis.id || "");
        var label = escapeHtml(analysis.created_label || analysis.generated_at || "Sin fecha");
        var summary = String(analysis.resumen || "Análisis guardado");
        var shortSummary = escapeHtml(summary.length > 70 ? summary.slice(0, 69) + "…" : summary);
        var author = escapeHtml(analysis.created_by || "Sistema");
        var json = escapeHtml(JSON.stringify(analysis));
        return (
          '<div class="flex items-center justify-between p-3 bg-slate-50/80 hover:bg-[#EBF1FB]/60 rounded-xl border border-slate-200 transition-colors text-xs">' +
          '<button type="button" class="flex-1 text-left cursor-pointer" data-commercial-open-analysis data-analysis-json="' +
          json +
          '">' +
          '<strong class="block font-semibold text-[#061D49]">' +
          label +
          "</strong>" +
          '<span class="block text-slate-500 text-[11px] line-clamp-1 mt-0.5">' +
          shortSummary +
          "</span>" +
          '<small class="text-[10px] text-slate-400 mt-0.5 block">' +
          author +
          "</small>" +
          "</button>" +
          '<button type="button" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg transition-colors cursor-pointer" data-commercial-delete-analysis data-ticket-pk="' +
          escapeHtml(ticketPk || currentCasePk || "") +
          '" data-analysis-id="' +
          id +
          '" title="Eliminar análisis"><span class="material-symbols-outlined text-[16px]">delete</span></button>' +
          "</div>"
        );
      })
      .join("");
  }

  function updateAnalysisList(analyses) {
    if (!caseContent) return;
    var wrap = caseContent.querySelector("[data-commercial-saved-analyses]");
    var list = wrap && wrap.querySelector("[data-commercial-analysis-list]");
    var count = wrap && wrap.querySelector("[data-commercial-analysis-count]");
    if (!wrap || !list) return;
    var ticketPk = wrap.getAttribute("data-ticket-pk") || currentCasePk || "";
    analyses = Array.isArray(analyses) ? analyses : [];
    list.innerHTML = analysisListMarkup(ticketPk, analyses);
    if (count) count.textContent = analyses.length + "/3";
  }

  function analysisModal() {
    return caseContent ? caseContent.querySelector("[data-commercial-analysis-modal]") : null;
  }

  function setAnalysisModal(open, html) {
    var modal = analysisModal();
    if (!modal) return;
    var content = modal.querySelector("[data-commercial-analysis-modal-content]");
    if (typeof html === "string" && content) content.innerHTML = html;
    modal.hidden = !open;
    modal.classList.toggle("open", open);
    if (open) {
      var close = modal.querySelector("[data-commercial-close-assistant]");
      if (close) close.focus({ preventScroll: true });
    }
  }

  function parseAnalysisButton(button) {
    try {
      return JSON.parse(button.getAttribute("data-analysis-json") || "{}");
    } catch (_error) {
      return {};
    }
  }

  function confirmAnalysisDelete() {
    if (window.Swal && typeof window.Swal.fire === "function") {
      return window.Swal.fire({
        icon: "warning",
        title: "¿Eliminar análisis?",
        text: "Se quitará de esta tarea y podrás generar otro si no supera el límite diario.",
        showCancelButton: true,
        confirmButtonText: "Eliminar",
        cancelButtonText: "Cancelar",
        confirmButtonColor: "#b4232c",
      }).then(function (result) { return !!result.isConfirmed; });
    }
    return Promise.resolve(window.confirm("¿Eliminar este análisis?"));
  }

  function documentRowMarkup(accept) {
    return (
      '<div class="commercial-ticket-document-row scm-ticket-document-row">' +
      '<label><span>Título del documento</span><input type="text" name="documento_nombre[]" placeholder="Ej: soporte, cédula, autorización…"></label>' +
      '<label><span>Documento</span><input type="file" name="documento[]" accept="' + escapeHtml(accept || "image/jpeg,image/png,application/pdf,application/msword,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-rar-compressed,text/html,text/plain,text/csv") + '"></label>' +
      '<button type="button" class="commercial-secondary-btn commercial-remove-ticket-document" data-remove-ticket-document>Quitar</button>' +
      "</div>"
    );
  }

  function renderPastedFiles(zone, input) {
    var list = zone.querySelector("[data-scm-paste-list]");
    if (!list) return;
    list.innerHTML = "";
    Array.prototype.forEach.call(input.files || [], function (file) {
      var item = document.createElement("li");
      item.textContent = file.name;
      list.appendChild(item);
    });
  }

  function handlePasteEvidence(event) {
    if (event.defaultPrevented) return;
    var zone = event.target && event.target.closest ? event.target.closest("[data-scm-paste-evidence]") : null;
    if (!zone || !caseModal || !caseModal.contains(zone)) return;
    var clipboard = event.clipboardData || window.clipboardData;
    var items = clipboard && clipboard.items ? clipboard.items : [];
    var files = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i] && /^image\//i.test(items[i].type || "")) {
        var pasted = items[i].getAsFile();
        if (pasted) {
          var ext = (pasted.type || "image/png").split("/").pop() || "png";
          files.push(new File([pasted], "captura-pegada-" + Date.now() + "-" + i + "." + ext, { type: pasted.type || "image/png" }));
        }
      }
    }
    if (!files.length) {
      zone.classList.add("is-error");
      var empty = zone.querySelector("[data-scm-paste-list]");
      if (empty) empty.innerHTML = "<li>No se encontró una imagen en el portapapeles.</li>";
      return;
    }
    var form = zone.closest("form");
    var inputName = zone.getAttribute("data-file-input-name") || "evidencia[]";
    var input = form ? form.querySelector('input[type="file"][name="' + inputName + '"]') : null;
    if (!input || typeof DataTransfer === "undefined") {
      zone.classList.add("is-error");
      var unsupported = zone.querySelector("[data-scm-paste-list]");
      if (unsupported) unsupported.innerHTML = "<li>Tu navegador no permitió adjuntar la captura pegada.</li>";
      return;
    }
    var transfer = new DataTransfer();
    Array.prototype.forEach.call(input.files || [], function (file) { transfer.items.add(file); });
    files.forEach(function (file) { transfer.items.add(file); });
    input.files = transfer.files;
    zone.classList.remove("is-error");
    zone.classList.add("has-files");
    renderPastedFiles(zone, input);
    event.preventDefault();
  }

  function request(action, input, signal) {
    var body = input instanceof FormData ? input : new FormData();
    if (!(input instanceof FormData)) {
      Object.keys(input || {}).forEach(function (key) { body.append(key, input[key]); });
    }
    body.set("action", action);
    body.set("nonce", nonce);
    return fetch(apiUrl, {
      method: "POST",
      credentials: "same-origin",
      body: body,
      signal: signal,
      headers: { "X-Requested-With": "XMLHttpRequest" },
    }).then(function (response) {
      return response.text().then(function (text) {
        var json = null;
        try {
          json = text ? JSON.parse(text) : null;
        } catch (_error) {
          var clean = String(text || "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
          throw new Error(clean ? clean.slice(0, 220) : "El servidor devolvió una respuesta vacía.");
        }
        if (!response.ok || !json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "La operación no pudo completarse.");
        }
        return json.data || {};
      });
    });
  }

  function activeTab() {
    var params = new URLSearchParams(window.location.search);
    var urlTab = params.get("tab");
    if (urlTab) return urlTab;
    var active = root.querySelector("[data-commercial-tab].active");
    if (active) return active.getAttribute("data-commercial-tab") || "inicio";
    if (root.querySelector("[data-commercial-advisory-modal]")) return "inicio";
    return "inicio";
  }

  function topTabFor(tab) {
    return ["abiertos", "postergados", "cerrados", "mis_tickets"].indexOf(tab) >= 0 ? "tareas" : tab;
  }

  function setActiveTab(tab) {
    var topTab = topTabFor(tab);
    root.querySelectorAll("[data-commercial-tab]").forEach(function (link) {
      var isActive = link.getAttribute("data-commercial-tab") === topTab;
      link.classList.toggle("active", isActive);
      if (isActive) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  function setVisiblePanel(tab) {
    if (ticketsPanel) ticketsPanel.classList.add("active");
    if (calendarPanel) calendarPanel.classList.toggle("active", tab === "calendario");
    setActiveTab(tab);
    if (tab === "calendario") {
      root.dispatchEvent(new CustomEvent("scm:refresh-active-tab"));
      if (typeof window.initCalendarPanel === "function") {
        window.initCalendarPanel(ticketsPanel || root);
      }
    } else if (tab === "actualizaciones" || tab === "avisos") {
      window.setTimeout(initHomeControls, 0);
    }
  }

  function refreshAdvisoryModalRef() {
    advisoryModal = root.querySelector("[data-commercial-advisory-modal].open") || root.querySelector("[data-commercial-advisory-modal]");
    return advisoryModal;
  }

  function advisoryModalForTrigger(trigger) {
    var key = trigger && trigger.getAttribute ? (trigger.getAttribute("data-commercial-open-advisory") || "") : "";
    if (key) {
      var modals = root.querySelectorAll("[data-commercial-advisory-modal]");
      for (var index = 0; index < modals.length; index += 1) {
        if (modals[index].getAttribute("data-commercial-advisory-modal") === key) return modals[index];
      }
    }
    return refreshAdvisoryModalRef();
  }

  function showAdvisoryModal(open, trigger, continueQueue) {
    var modal = open
      ? (trigger && trigger.matches && trigger.matches("[data-commercial-advisory-modal]") ? trigger : advisoryModalForTrigger(trigger))
      : (trigger && trigger.matches && trigger.matches("[data-commercial-advisory-modal]") ? trigger : refreshAdvisoryModalRef());
    if (!modal) return;
    if (open) {
      root.querySelectorAll("[data-commercial-advisory-modal].open").forEach(function (item) {
        if (item !== modal) {
          item.classList.remove("open");
          item.setAttribute("aria-hidden", "true");
        }
      });
    }
    modal.classList.toggle("open", open);
    modal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (open) {
      advisoryQueueActive = !!(trigger && trigger.matches && trigger.matches("[data-commercial-advisory-modal]") && trigger.getAttribute("data-auto-open") === "1");
      lastFocused = trigger && trigger.matches && trigger.matches("[data-commercial-advisory-modal]") ? document.activeElement : (trigger || document.activeElement);
      var close = modal.querySelector("[data-commercial-close-advisory]");
      if (close) close.focus({ preventScroll: true });
    } else if (lastFocused && typeof lastFocused.focus === "function" && document.contains(lastFocused)) {
      lastFocused.focus({ preventScroll: true });
    }
    if (!open && continueQueue) {
      window.setTimeout(function () { openNextAdvisoryModal(modal); }, 220);
    } else if (!open) {
      advisoryQueueActive = false;
    }
  }

  function maybeAutoOpenAdvisory() {
    if (activeTab() !== "inicio") return;
    if (root.querySelector(".commercial-modal.open")) return;
    window.setTimeout(function () { openNextAdvisoryModal(null); }, 300);
  }

  function openNextAdvisoryModal(current) {
    if (activeTab() !== "inicio") return;
    var modals = Array.prototype.slice.call(root.querySelectorAll('[data-commercial-advisory-modal][data-auto-open="1"]'));
    if (!modals.length) return;
    var start = current ? modals.indexOf(current) : -1;
    for (var index = start + 1; index < modals.length; index += 1) {
      if (modals[index].getAttribute("data-opened") === "1") continue;
      modals[index].setAttribute("data-opened", "1");
      showAdvisoryModal(true, modals[index]);
      return;
    }
    advisoryQueueActive = false;
  }

  function normalizedUrl(value) {
    return new URL(value || window.location.href, window.location.href);
  }

  function updateHistory(url, replace) {
    var next = url.pathname + url.search;
    if (replace) window.history.replaceState({ commercial: true }, "", next);
    else window.history.pushState({ commercial: true }, "", next);
  }

  function loadTickets(url, options) {
    options = options || {};
    var nextUrl = normalizedUrl(url);
    var tab = nextUrl.searchParams.get("tab") || "inicio";
    if (!ticketsPanel) return Promise.resolve();
    if (listRequest) listRequest.abort();
    listRequest = new AbortController();
    ticketsPanel.classList.add("is-loading");
    ticketsPanel.setAttribute("aria-busy", "true");
    setVisiblePanel(tab);

    var data = new FormData();
    [
      "tab",
      "subtab",
      "estado",
      "mis_bucket",
      "busqueda",
      "id_empleado",
      "ticket_id",
      "solicitante",
      "celular",
      "correo",
      "inmueble",
      "barrio",
      "medio",
      "prioridad",
      "tema",
      "seguimiento",
      "encargado_seguimiento",
      "fecha_seguimiento_desde",
      "fecha_seguimiento_hasta",
      "sin_actualizar",
      "estado_administrativo",
      "codigo",
      "gestion",
      "tipo",
      "ruta",
      "estado_actualizacion",
      "estado_aviso",
      "fecha_desde",
      "fecha_hasta",
      "sla_filter",
      "page",
    ].forEach(function (key) {
      data.append(key, nextUrl.searchParams.get(key) || "");
    });
    return request("commercial_tickets_filter", data, listRequest.signal)
      .then(function (response) {
        ticketsPanel.innerHTML = response.html || "";
        globalFilter = root.querySelector("[data-commercial-global-filter-form]");
        if (response.tabs_html) {
          if (tabsNav) tabsNav.outerHTML = response.tabs_html;
          tabsNav = root.querySelector("[data-commercial-tabs]");
        }
        globalFilter = root.querySelector("[data-commercial-global-filter-form]");
        setVisiblePanel(tab);
        if (options.history !== false) updateHistory(nextUrl, !!options.replace);
        maybeAutoOpenAdvisory();
        initHomeControls();
        if (tab === "calendario" || ticketsPanel.querySelector("[data-scm-calendar-panel]")) {
          if (typeof window.initCalendarPanel === "function") {
            window.initCalendarPanel(ticketsPanel);
          } else {
            document.dispatchEvent(new CustomEvent("scm:init-calendar", { detail: { target: ticketsPanel } }));
          }
        }
        if (options.focus) {
          var heading = ticketsPanel.querySelector("h2");
          if (heading) {
            heading.setAttribute("tabindex", "-1");
            heading.focus({ preventScroll: true });
          }
        }
      })
      .catch(function (error) {
        if (error.name !== "AbortError") notify("error", error.message);
      })
      .finally(function () {
        ticketsPanel.classList.remove("is-loading");
        ticketsPanel.removeAttribute("aria-busy");
      });
  }

  function showCaseModal(open) {
    if (!caseModal) return;
    caseModal.classList.toggle("open", open);
    caseModal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (!open) {
      currentCasePk = "";
      if (detailRequest) detailRequest.abort();
      if (lastFocused && typeof lastFocused.focus === "function") lastFocused.focus();
    }
  }

  function loadCase(ticketPk, preserveFocus) {
    if (!caseContent || !ticketPk) return Promise.resolve();
    if (detailRequest) detailRequest.abort();
    detailRequest = new AbortController();
    if (!preserveFocus) caseContent.innerHTML = loadingMarkup();
    caseContent.setAttribute("aria-busy", "true");
    return request("commercial_ticket_detail", { ticket_pk: ticketPk }, detailRequest.signal)
      .then(function (response) {
        caseContent.innerHTML = response.html || "";
        caseContent.removeAttribute("aria-busy");
        var firstAction = caseContent.querySelector("[data-commercial-open-workflow]");
        if (!preserveFocus && firstAction) firstAction.focus({ preventScroll: true });
      })
      .catch(function (error) {
        if (error.name === "AbortError") return;
        caseContent.removeAttribute("aria-busy");
        caseContent.innerHTML = '<div class="commercial-case-error"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><h2>No pudimos abrir la tarea</h2><p></p><button type="button" class="commercial-primary-btn" data-commercial-retry-case>Reintentar</button></div>';
        var paragraph = caseContent.querySelector("p");
        if (paragraph) paragraph.textContent = error.message;
      });
  }

  function openCase(button) {
    if (!caseModal) return;
    currentCasePk = button.getAttribute("data-commercial-open-case") || "";
    if (!currentCasePk) return;
    lastFocused = button;
    showCaseModal(true);
    loadCase(currentCasePk, false);
  }

  function closeWorkflows() {
    if (!caseContent) return;
    var stack = caseContent.querySelector("[data-commercial-workflow-stack]");
    caseContent.querySelectorAll("[data-commercial-workflow-form]").forEach(function (form) { form.hidden = true; });
    caseContent.querySelectorAll("[data-commercial-open-workflow]").forEach(function (button) {
      button.classList.remove("active");
      button.removeAttribute("aria-pressed");
    });
    if (stack) stack.hidden = true;
  }

  function openWorkflow(button) {
    if (!caseContent) return;
    var key = button.getAttribute("data-commercial-open-workflow") || "";
    var form = caseContent.querySelector('[data-commercial-workflow-form="' + key + '"]');
    var stack = caseContent.querySelector("[data-commercial-workflow-stack]");
    if (!form || !stack) return;
    closeWorkflows();
    stack.hidden = false;
    form.hidden = false;
    button.classList.add("active");
    button.setAttribute("aria-pressed", "true");
    var field = form.querySelector("textarea, select, input:not([type=hidden])");
    if (field) field.focus({ preventScroll: true });
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function submitWorkflow(form) {
    var key = form.getAttribute("data-commercial-workflow-form") || "";
    var action = actionMap[key];
    if (!action) return;
    var submit = form.querySelector('button[type="submit"]');
    var message = form.querySelector(".commercial-form-message");
    var originalText = submit ? submit.textContent : "";
    if (submit) {
      submit.disabled = true;
      submit.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Guardando…';
    }
    if (message) message.textContent = "Procesando la acción…";
    request(action, new FormData(form))
      .then(function (response) {
        notify("success", response.message || "Acción guardada.");
        var movesTicket = ["postpone", "activate", "close", "status"].indexOf(key) !== -1;
        var refreshUrl = window.location.href;
        if (movesTicket) {
          showCaseModal(false);
          return loadTickets(refreshUrl, { history: false });
        }
        return Promise.all([loadCase(currentCasePk, true), loadTickets(refreshUrl, { history: false })]);
      })
      .catch(function (error) {
        if (message) message.textContent = error.message;
        notify("error", error.message);
      })
      .finally(function () {
        if (submit && document.contains(submit)) {
          submit.disabled = false;
          submit.textContent = originalText;
        }
      });
  }

  function homeControlPanel(name) {
    return root.querySelector('[data-commercial-home-control-panel="' + name + '"]');
  }

  function setStandaloneModal(modal, open) {
    if (!modal) return;
    modal.classList.toggle("open", open);
    modal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
  }

  function safeJsonFromAttr(element, attr) {
    try {
      return JSON.parse(element.getAttribute(attr) || "{}");
    } catch (_error) {
      return {};
    }
  }

  function propertyInfoMarkup(info) {
    info = info || {};
    function row(label, value) {
      return "<div><span>" + escapeHtml(label) + "</span><strong>" + escapeHtml(value || "-") + "</strong></div>";
    }
    return (
      '<header class="commercial-property-modal-head"><span>Ficha técnica</span><h2>Inmueble ' + escapeHtml(info.codigo || "") + "</h2></header>" +
      '<div class="commercial-property-modal-grid">' +
      '<section><h3>Resumen del inmueble</h3>' +
      row("Arriendo", info.val_arr) + row("Venta", info.val_ven) + row("Administración", info.val_adm) +
      row("Tipo", info.tipo_inmueble) + row("Barrio", info.barrio) + row("Dirección", info.dir_full) +
      row("Área privada", (info.area_priv || "-") + " m²") + row("Área construida", (info.area_cons || "-") + " m²") + row("Hab / Baños", (info.habs || "-") + " / " + (info.banos || "-")) +
      "</section>" +
      '<section><h3>Propietario</h3>' +
      row("Nombre", info.prop_nombre) + row("Email", info.prop_email) + row("Celular", info.prop_cel) +
      "</section>" +
      '<section><h3>Gestión de llaves</h3>' +
      row("Ubicación", info.llaves_ubi) + row("En dónde", info.llaves_donde) + row("Contacto", info.llaves_contacto) + row("Teléfono", info.llaves_tel) +
      "</section>" +
      "</div>"
    );
  }

  function signDetailMarkup(row) {
    row = row || {};
    var maps = row.maps_url ? '<a class="commercial-control-btn commercial-control-btn--primary" target="_blank" rel="noopener" href="' + escapeHtml(row.maps_url) + '">Ver en Google Maps</a>' : "";
    var property = row.property_url ? '<a class="commercial-control-btn" target="_blank" rel="noopener" href="' + escapeHtml(row.property_url) + '">Ver inmueble</a>' : "";
    var stateClass = /venc|atras/i.test(row.estado || "") ? "danger" : (/alert/i.test(row.estado || "") ? "warning" : "success");
    function rowItem(label, value) {
      return "<div><span>" + escapeHtml(label) + "</span><strong>" + escapeHtml(value || "-") + "</strong></div>";
    }
    return (
      '<header class="commercial-property-modal-head commercial-sign-modal-head"><span>Detalle del aviso</span><h2>Inmueble ' + escapeHtml(row.codigo || "") + "</h2></header>" +
      '<section class="commercial-sign-detail-summary commercial-sign-detail-summary--' + stateClass + '">' +
      '<div><span>Estado actual</span><strong>' + escapeHtml(row.estado || "-") + '</strong></div>' +
      '<p>' + escapeHtml((row.dias || 0) + " de " + (row.max || 0) + " días · " + (row.origen || "fecha base") + (row.fecha ? " · " + row.fecha : "")) + '</p>' +
      '</section>' +
      '<div class="commercial-sign-detail-grid">' +
      rowItem("Funcionario", row.funcionario) +
      rowItem("Celular funcionario", row.celular_funcionario) +
      rowItem("Tipo", row.tipo) +
      rowItem("Gestión", row.gestion) +
      rowItem("Fecha base", row.fecha) +
      rowItem("Barrio", row.barrio) +
      rowItem("Ruta", row.ruta) +
      rowItem("Dirección", row.direccion) +
      rowItem("Punto de referencia", row.punto_referencia) +
      "</div>" +
      (maps || property ? '<footer class="commercial-control-actions">' + maps + property + "</footer>" : "")
    );
  }

  function loadPropertyUpdates() {
    var form = root.querySelector("[data-commercial-property-control]");
    var rows = root.querySelector("[data-commercial-property-rows]");
    var total = root.querySelector("[data-commercial-property-total]");
    var stats = root.querySelector("[data-commercial-property-stats]");
    if (!form || !rows) return;
    rows.innerHTML = '<tr><td colspan="6" class="py-8 text-center text-secondary font-body-sm">Cargando inmuebles…</td></tr>';
    request("commercial_property_updates", new FormData(form))
      .then(function (response) {
        rows.innerHTML = response.html || "";
        if (total) total.textContent = (response.total || 0) + " inmuebles";
        if (stats) {
          var s = response.stats || {};
          stats.textContent = "Al día: " + (s.ok || 0) + " · Alerta: " + (s.alerta || 0) + " · Vencidos: " + (s.vencido || 0);
        }
      })
      .catch(function (error) {
        rows.innerHTML = '<tr><td colspan="6" class="py-8 text-center text-error font-body-sm">' + escapeHtml(error.message) + "</td></tr>";
      });
  }

  function loadSignsControl() {
    var form = root.querySelector("[data-commercial-sign-control]");
    var rows = root.querySelector("[data-commercial-sign-rows]");
    var total = root.querySelector("[data-commercial-sign-total]");
    if (!form || !rows) return;
    var data = new FormData(form);
    data.set("mode", form.getAttribute("data-mode") || "maintenance");
    rows.innerHTML = '<div class="py-8 text-center text-secondary font-body-sm">Cargando avisos…</div>';
    request("commercial_signs_control", data)
      .then(function (response) {
        rows.innerHTML = response.html || "";
        if (total) total.textContent = (response.total || 0) + " avisos";
      })
      .catch(function (error) {
        rows.innerHTML = '<div class="py-8 text-center text-error font-body-sm">' + escapeHtml(error.message) + "</div>";
      });
  }

  function initHomeControls() {
    var homeControls = root.querySelector("[data-commercial-home-controls]");
    if (!homeControls) return;
    var updatesPanel = homeControlPanel("updates");
    var signsPanel = homeControlPanel("signs");
    var tab = activeTab();
    if (updatesPanel && (updatesPanel.classList.contains("active") || !signsPanel || tab === "actualizaciones")) {
      updatesPanel.classList.add("active");
      loadPropertyUpdates();
    }
    if (signsPanel && (signsPanel.classList.contains("active") || !updatesPanel || tab === "avisos")) {
      signsPanel.classList.add("active");
      loadSignsControl();
    }
  }

  function analyzeCase(button) {
    if (!caseContent) return;
    var ticketPk = button.getAttribute("data-ticket-pk") || currentCasePk || "";
    if (!ticketPk) return;
    var originalText = button.textContent;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><span>Analizando…</span>';
    setAnalysisModal(true, assistantLoadingMarkup());
    request("commercial_ticket_analyze", { ticket_pk: ticketPk })
      .then(function (response) {
        updateAnalysisList(response.analyses || []);
        setAnalysisModal(true, assistantAnalysisMarkup(response.analysis || {}));
        notify("success", response.message || "Análisis guardado.");
      })
      .catch(function (error) {
        setAnalysisModal(true, assistantErrorMarkup(error.message));
        notify("error", error.message);
      })
      .finally(function () {
        if (document.contains(button)) {
          button.disabled = false;
          button.innerHTML = '<i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span>' + escapeHtml(originalText || "Analizar con asistente") + "</span>";
        }
      });
  }

  function assistantSuggestedText(button) {
    var suggested = button.closest(".commercial-assistant-suggested") || button.closest("[data-purpose='suggested-message-card']");
    if (!suggested) return "";
    var block = suggested.querySelector("blockquote") || suggested.querySelector("p");
    return block ? block.textContent.trim() : "";
  }

  function useAssistantMessage(button) {
    if (!caseContent) return;
    var text = assistantSuggestedText(button);
    if (!text) return;
    var replyButton = caseContent.querySelector('[data-commercial-open-workflow="reply"]');
    var replyForm = caseContent.querySelector('[data-commercial-workflow-form="reply"]');
    if (!replyButton || !replyForm) {
      notify("error", "No tienes disponible la acción Responder para esta tarea.");
      return;
    }
    openWorkflow(replyButton);
    var textarea = replyForm.querySelector('textarea[name="respuesta"]');
    if (textarea) {
      textarea.value = text;
      textarea.dispatchEvent(new Event("input", { bubbles: true }));
      textarea.focus({ preventScroll: true });
    }
    setAnalysisModal(false);
    notify("success", "Mensaje sugerido cargado en Responder.");
  }

  function insertTextAtCursor(textarea, textToInsert) {
    if (!textarea) return;
    textarea.focus();
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value || "";
    if (typeof start === "number" && typeof end === "number") {
      var before = value.substring(0, start);
      var after = value.substring(end);
      var leadingSep = (before.length > 0 && !/\s$/.test(before)) ? " " : "";
      var trailingSep = (after.length > 0 && !/^\s/.test(after)) ? " " : "";
      var fullInsert = leadingSep + textToInsert + trailingSep;
      textarea.value = before + fullInsert + after;
      var newPos = start + fullInsert.length;
      textarea.setSelectionRange(newPos, newPos);
    } else {
      textarea.value += (textarea.value ? " " : "") + textToInsert;
    }
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function deleteAnalysis(button) {
    var ticketPk = button.getAttribute("data-ticket-pk") || currentCasePk || "";
    var analysisId = button.getAttribute("data-analysis-id") || "";
    if (!ticketPk || !analysisId) return;
    confirmAnalysisDelete().then(function (confirmed) {
      if (!confirmed) return;
      button.disabled = true;
      request("commercial_ticket_analysis_delete", { ticket_pk: ticketPk, analysis_id: analysisId })
        .then(function (response) {
          updateAnalysisList(response.analyses || []);
          setAnalysisModal(false);
          notify("success", response.message || "Análisis eliminado.");
        })
        .catch(function (error) {
          notify("error", error.message);
        })
        .finally(function () {
          if (document.contains(button)) button.disabled = false;
        });
    });
  }

  root.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-commercial-tab]");
    var filterLink = event.target.closest("[data-commercial-filter-link]");
    var globalClear = event.target.closest("[data-commercial-global-clear]");
    var openButton = event.target.closest("[data-commercial-open-case]");
    var openAdvisory = event.target.closest("[data-commercial-open-advisory]");
    var controlTab = event.target.closest("[data-commercial-home-control-tab]");
    var signTab = event.target.closest("[data-commercial-sign-tab]");
    var controlClear = event.target.closest("[data-commercial-control-clear]");
    var propertyInfo = event.target.closest("[data-commercial-property-info]");
    var signDetail = event.target.closest("[data-commercial-sign-detail]");
    var closeProperty = event.target.closest("[data-commercial-close-property]");
    var closeSignDetail = event.target.closest("[data-commercial-close-sign-detail]");
    var copyTable = event.target.closest("[data-commercial-copy-table]");
    if (event.target && event.target.matches && event.target.matches("#commercial-property-modal")) {
      setStandaloneModal(event.target, false);
      return;
    }
    if (event.target && event.target.matches && event.target.matches("#commercial-sign-detail-modal")) {
      setStandaloneModal(event.target, false);
      return;
    }
    if (controlTab) {
      event.preventDefault();
      var panelName = controlTab.getAttribute("data-commercial-home-control-tab") || "updates";
      root.querySelectorAll("[data-commercial-home-control-tab]").forEach(function (button) { button.classList.toggle("active", button === controlTab); });
      root.querySelectorAll("[data-commercial-home-control-panel]").forEach(function (panel) { panel.classList.toggle("active", panel.getAttribute("data-commercial-home-control-panel") === panelName); });
      if (panelName === "updates") loadPropertyUpdates();
      if (panelName === "signs") loadSignsControl();
      return;
    }
    if (signTab) {
      event.preventDefault();
      var mode = signTab.getAttribute("data-commercial-sign-tab") || "maintenance";
      var activeClasses = ["bg-inverse-surface", "text-on-secondary", "shadow-sm"];
      var inactiveClasses = ["bg-surface-container", "hover:bg-surface-container-high", "text-on-surface"];
      root.querySelectorAll("[data-commercial-sign-tab]").forEach(function (button) {
        var isCurrent = button === signTab;
        button.classList.toggle("active", isCurrent);
        activeClasses.forEach(function (cls) { button.classList.toggle(cls, isCurrent); });
        inactiveClasses.forEach(function (cls) { button.classList.toggle(cls, !isCurrent); });
      });
      var signForm = root.querySelector("[data-commercial-sign-control]");
      if (signForm) signForm.setAttribute("data-mode", mode);
      loadSignsControl();
      return;
    }
    if (controlClear) {
      event.preventDefault();
      var controlForm = controlClear.closest("form");
      if (controlForm) {
        Array.prototype.forEach.call(controlForm.elements, function (field) {
          if (!field.name) return;
          if (field.tagName === "SELECT") field.selectedIndex = 0;
          else if (field.type !== "hidden") field.value = "";
        });
        if (controlForm.matches("[data-commercial-property-control]")) loadPropertyUpdates();
        if (controlForm.matches("[data-commercial-sign-control]")) loadSignsControl();
      }
      return;
    }
    if (propertyInfo) {
      event.preventDefault();
      var propertyModal = document.getElementById("commercial-property-modal");
      var propertyContent = propertyModal && propertyModal.querySelector("[data-commercial-property-modal-content]");
      if (propertyContent) propertyContent.innerHTML = propertyInfoMarkup(safeJsonFromAttr(propertyInfo, "data-commercial-property-info"));
      setStandaloneModal(propertyModal, true);
      return;
    }
    if (signDetail) {
      event.preventDefault();
      var signModal = document.getElementById("commercial-sign-detail-modal");
      var signContent = signModal && signModal.querySelector("[data-commercial-sign-detail-content]");
      if (signContent) signContent.innerHTML = signDetailMarkup(safeJsonFromAttr(signDetail, "data-commercial-sign-detail"));
      setStandaloneModal(signModal, true);
      return;
    }
    if (closeProperty) {
      event.preventDefault();
      setStandaloneModal(document.getElementById("commercial-property-modal"), false);
      return;
    }
    if (closeSignDetail) {
      event.preventDefault();
      setStandaloneModal(document.getElementById("commercial-sign-detail-modal"), false);
      return;
    }
    if (copyTable) {
      event.preventDefault();
      var table = document.getElementById(copyTable.getAttribute("data-commercial-copy-table") || "");
      if (table && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(table.innerText || "").then(function () { notify("success", "Tabla copiada."); });
      }
      return;
    }
    var bellBtn = event.target.closest("#btn-notifications-bell");
    if (bellBtn) {
      event.preventDefault();
      var notifDropdown = document.getElementById("scm-notifications-dropdown");
      if (notifDropdown) {
        var isHidden = notifDropdown.classList.contains("hidden");
        notifDropdown.classList.toggle("hidden", !isHidden);
        bellBtn.setAttribute("aria-expanded", isHidden ? "true" : "false");
      }
      return;
    }

    var closeNotifBtn = event.target.closest("[data-commercial-close-notifications]");
    if (closeNotifBtn) {
      event.preventDefault();
      var notifDropdown = document.getElementById("scm-notifications-dropdown");
      if (notifDropdown) {
        notifDropdown.classList.add("hidden");
        var bell = document.getElementById("btn-notifications-bell");
        if (bell) bell.setAttribute("aria-expanded", "false");
      }
      return;
    }

    var ddTrigger = event.target.closest("[data-commercial-dropdown-trigger]");
    if (ddTrigger) {
      event.preventDefault();
      var ddContainer = ddTrigger.closest("[data-commercial-dropdown]");
      var ddKey = ddTrigger.getAttribute("data-commercial-dropdown-trigger") || (ddContainer && ddContainer.getAttribute("data-commercial-dropdown")) || "";
      var ddMenu = ddContainer ? ddContainer.querySelector("[data-commercial-dropdown-menu]") : root.querySelector('[data-commercial-dropdown-menu="' + ddKey + '"]');
      if (ddMenu) {
        var isOpen = ddMenu.classList.contains("is-open");
        root.querySelectorAll("[data-commercial-dropdown-menu].is-open").forEach(function (m) {
          if (m !== ddMenu) m.classList.remove("is-open");
        });
        root.querySelectorAll("[data-commercial-dropdown].is-open").forEach(function (d) {
          if (d !== ddContainer) d.classList.remove("is-open");
        });
        root.querySelectorAll("[data-commercial-subflyout].is-open, [data-commercial-subgroup].is-open").forEach(function (sf) {
          sf.classList.remove("is-open");
        });
        ddMenu.classList.toggle("is-open", !isOpen);
        if (ddContainer) ddContainer.classList.toggle("is-open", !isOpen);
        ddTrigger.setAttribute("aria-expanded", !isOpen ? "true" : "false");
      }
      return;
    }

    var subTrigger = event.target.closest("[data-commercial-subflyout-trigger]") || event.target.closest("[data-commercial-subgroup]");
    if (subTrigger && !event.target.closest("a")) {
      var subGroup = subTrigger.closest("[data-commercial-subgroup]");
      var flyout = subGroup ? subGroup.querySelector("[data-commercial-subflyout]") : null;
      if (flyout) {
        event.preventDefault();
        event.stopPropagation();
        var isSubOpen = flyout.classList.contains("is-open");
        var parentMenu = subGroup.closest("[data-commercial-dropdown-menu]");
        if (parentMenu) {
          parentMenu.querySelectorAll("[data-commercial-subflyout].is-open").forEach(function (f) {
            if (f !== flyout) f.classList.remove("is-open");
          });
          parentMenu.querySelectorAll("[data-commercial-subgroup].is-open").forEach(function (sg) {
            if (sg !== subGroup) sg.classList.remove("is-open");
          });
        }
        flyout.classList.toggle("is-open", !isSubOpen);
        subGroup.classList.toggle("is-open", !isSubOpen);
        return;
      }
    }

    if (tab) {
      event.preventDefault();
      var notifDropdown = document.getElementById("scm-notifications-dropdown");
      if (notifDropdown) notifDropdown.classList.add("hidden");
      root.querySelectorAll("[data-commercial-dropdown-menu].is-open, [data-commercial-dropdown].is-open, [data-commercial-subflyout].is-open, [data-commercial-subgroup].is-open").forEach(function (m) {
        m.classList.remove("is-open");
      });
      root.querySelectorAll("[data-commercial-dropdown-trigger]").forEach(function (btn) {
        btn.setAttribute("aria-expanded", "false");
      });
      loadTickets(tab.href, { focus: true });
      return;
    }
    if (filterLink) {
      event.preventDefault();
      if (filterLink.closest("[data-commercial-advisory-modal]")) showAdvisoryModal(false);
      var rawHref = filterLink.getAttribute("href") || "";
      if (rawHref.charAt(0) === "#") {
        var target = document.querySelector(rawHref);
        if (rawHref === "#commercial-signs-panel") {
          var signsTab = root.querySelector('[data-commercial-home-control-tab="signs"]');
          if (signsTab) signsTab.click();
        } else if (rawHref === "#commercial-property-updates-panel") {
          var updatesTab = root.querySelector('[data-commercial-home-control-tab="updates"]');
          if (updatesTab) updatesTab.click();
        }
        target = document.querySelector(rawHref);
        if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }
      loadTickets(filterLink.href, { focus: false });
      return;
    }
    if (globalClear) {
      event.preventDefault();
      loadTickets(globalClear.href, { focus: false });
      return;
    }
    if (openButton) {
      event.preventDefault();
      var notifDropdown = document.getElementById("scm-notifications-dropdown");
      if (notifDropdown) notifDropdown.classList.add("hidden");
      openCase(openButton);
      return;
    }
    if (openAdvisory) {
      event.preventDefault();
      showAdvisoryModal(true, openAdvisory);
    }
  });

  document.addEventListener("click", function (event) {
    if (!event.target.closest('[data-commercial-dropdown="notifications"]')) {
      var notifDropdown = document.getElementById("scm-notifications-dropdown");
      if (notifDropdown && !notifDropdown.classList.contains("hidden")) {
        notifDropdown.classList.add("hidden");
        var bell = document.getElementById("btn-notifications-bell");
        if (bell) bell.setAttribute("aria-expanded", "false");
      }
    }
    if (!event.target.closest("[data-commercial-dropdown]")) {
      root.querySelectorAll("[data-commercial-dropdown-menu].is-open, [data-commercial-dropdown].is-open, [data-commercial-subflyout].is-open, [data-commercial-subgroup].is-open").forEach(function (m) {
        m.classList.remove("is-open");
      });
      root.querySelectorAll("[data-commercial-dropdown-trigger]").forEach(function (btn) {
        btn.setAttribute("aria-expanded", "false");
      });
    }
  });

  root.addEventListener("submit", function (event) {
    var propertyControlForm = event.target.closest("[data-commercial-property-control]");
    if (propertyControlForm) {
      event.preventDefault();
      loadPropertyUpdates();
      return;
    }
    var signControlForm = event.target.closest("[data-commercial-sign-control]");
    if (signControlForm) {
      event.preventDefault();
      loadSignsControl();
      return;
    }
    var globalForm = event.target.closest("[data-commercial-global-filter-form]");
    if (globalForm) {
      event.preventDefault();
      var globalUrl = normalizedUrl(globalForm.action);
      var currentTab = (globalForm.querySelector('[name="tab"]') || {}).value || activeTab();
      globalUrl.searchParams.set("tab", currentTab === "calendario" ? "abiertos" : currentTab);
      new FormData(globalForm).forEach(function (value, key) {
        if (key === "tab") return;
        if (String(value).trim() === "") globalUrl.searchParams.delete(key);
        else globalUrl.searchParams.set(key, String(value));
      });
      globalUrl.searchParams.delete("estado");
      globalUrl.searchParams.delete("page");
      loadTickets(globalUrl.href, { focus: false });
      return;
    }
    var filterForm = event.target.closest("[data-commercial-filter-form]");
    if (!filterForm) return;
    event.preventDefault();
    var url = normalizedUrl(filterForm.action);
    new FormData(filterForm).forEach(function (value, key) {
      if (String(value).trim() === "") url.searchParams.delete(key);
      else url.searchParams.set(key, String(value));
    });
    url.searchParams.delete("page");
    loadTickets(url.href, { focus: false });
  });

  root.addEventListener("change", function (event) {
    var form = event.target.closest("[data-commercial-global-filter-form]");
    if (form && event.target.matches('select[name="id_empleado"]')) {
      form.requestSubmit();
    }
  });

  if (caseModal) {
    caseModal.addEventListener("click", function (event) {
      if (event.target && event.target.matches && event.target.matches("[data-commercial-analysis-modal]")) {
        setAnalysisModal(false);
        return;
      }
      if (event.target === caseModal || event.target.closest("[data-commercial-close-case]")) {
        showCaseModal(false);
        return;
      }
      var workflowButton = event.target.closest("[data-commercial-open-workflow]");
      if (workflowButton) {
        openWorkflow(workflowButton);
        return;
      }
      var assistantButton = event.target.closest("[data-commercial-assistant]");
      if (assistantButton) {
        event.preventDefault();
        analyzeCase(assistantButton);
        return;
      }
      var openAnalysis = event.target.closest("[data-commercial-open-analysis]");
      if (openAnalysis) {
        event.preventDefault();
        setAnalysisModal(true, assistantAnalysisMarkup(parseAnalysisButton(openAnalysis)));
        return;
      }
      var deleteAnalysisButton = event.target.closest("[data-commercial-delete-analysis]");
      if (deleteAnalysisButton) {
        event.preventDefault();
        deleteAnalysis(deleteAnalysisButton);
        return;
      }
      if (event.target.closest("[data-commercial-close-assistant]")) {
        setAnalysisModal(false);
        return;
      }
      var copyAssistant = event.target.closest("[data-commercial-copy-assistant-message]");
      if (copyAssistant) {
        event.preventDefault();
        var text = assistantSuggestedText(copyAssistant);
        if (text && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { notify("success", "Mensaje sugerido copiado."); });
        }
        return;
      }
      var copyGeneric = event.target.closest("[data-commercial-copy]");
      if (copyGeneric) {
        event.preventDefault();
        var copyText = copyGeneric.getAttribute("data-commercial-copy") || "";
        if (copyText && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(copyText).then(function () { notify("success", "Copiado al portapapeles: " + copyText); });
        }
        return;
      }
      var useAssistant = event.target.closest("[data-commercial-use-assistant-message]");
      if (useAssistant) {
        event.preventDefault();
        useAssistantMessage(useAssistant);
        return;
      }
      if (event.target.closest("[data-commercial-close-workflow]")) {
        closeWorkflows();
        return;
      }
      var insertPropertyBtn = event.target.closest("[data-commercial-insert-property]");
      if (insertPropertyBtn) {
        event.preventDefault();
        var propCode = insertPropertyBtn.getAttribute("data-commercial-insert-property") || "";
        if (propCode) {
          var formProp = insertPropertyBtn.closest("form");
          var textareaProp = formProp ? formProp.querySelector('textarea[name="respuesta"]') : null;
          var urlProp = "https://sucasainmobiliaria.com.co/inmueble/" + encodeURIComponent(propCode);
          insertTextAtCursor(textareaProp, urlProp);
        }
        return;
      }
      var insertCustomPropBtn = event.target.closest("[data-commercial-insert-custom-property]");
      if (insertCustomPropBtn) {
        event.preventDefault();
        var code = window.prompt("Ingresa el código del inmueble (ej: 12345):", "");
        if (code) {
          code = code.trim().replace(/[^a-zA-Z0-9_-]/g, "");
          if (code) {
            var formCustom = insertCustomPropBtn.closest("form");
            var textareaCustom = formCustom ? formCustom.querySelector('textarea[name="respuesta"]') : null;
            var urlCustom = "https://sucasainmobiliaria.com.co/inmueble/" + encodeURIComponent(code);
            insertTextAtCursor(textareaCustom, urlCustom);
          }
        }
        return;
      }
      var insertLinkBtn = event.target.closest("[data-commercial-insert-link]");
      if (insertLinkBtn) {
        event.preventDefault();
        var linkUrl = window.prompt("Ingresa la dirección web (URL):", "https://");
        if (linkUrl) {
          linkUrl = linkUrl.trim();
          if (linkUrl && /^https?:\/\//i.test(linkUrl)) {
            var formLink = insertLinkBtn.closest("form");
            var textareaLink = formLink ? formLink.querySelector('textarea[name="respuesta"]') : null;
            insertTextAtCursor(textareaLink, linkUrl);
          } else if (linkUrl) {
            notify("error", "La URL debe comenzar con http:// o https://");
          }
        }
        return;
      }
      var addDocument = event.target.closest("[data-add-ticket-document]");
      if (addDocument) {
        event.preventDefault();
        var form = addDocument.closest("form");
        var docsWrap = form ? form.querySelector("[data-ticket-documents]") : null;
        if (docsWrap) docsWrap.insertAdjacentHTML("beforeend", documentRowMarkup(addDocument.getAttribute("data-document-accept") || ""));
        return;
      }
      var removeDocument = event.target.closest("[data-remove-ticket-document]");
      if (removeDocument) {
        event.preventDefault();
        var row = removeDocument.closest(".commercial-ticket-document-row, .scm-ticket-document-row");
        if (row) row.remove();
        return;
      }
      if (event.target.closest("[data-commercial-retry-case]")) loadCase(currentCasePk, false);
    });
    caseModal.addEventListener("submit", function (event) {
      var form = event.target.closest("[data-commercial-workflow-form]");
      if (!form) return;
      event.preventDefault();
      submitWorkflow(form);
    });
  }

  document.addEventListener("paste", handlePasteEvidence);

  var permissionModal = document.getElementById("commercial-permissions-modal");
  var permissionOpen = document.getElementById("commercial-open-permissions");
  var permissionForm = document.getElementById("commercial-permissions-form");
  function setPermissionModal(open) {
    if (!permissionModal) return;
    permissionModal.classList.toggle("open", open);
    permissionModal.setAttribute("aria-hidden", open ? "false" : "true");
    document.body.classList.toggle("commercial-modal-open", open || !!document.querySelector(".commercial-modal.open"));
    if (open) {
      lastFocused = document.activeElement;
      var close = permissionModal.querySelector("[data-commercial-close-permissions]");
      if (close) close.focus();
    } else if (lastFocused && typeof lastFocused.focus === "function") lastFocused.focus();
  }
  if (permissionOpen) permissionOpen.addEventListener("click", function () { setPermissionModal(true); });
  if (permissionModal) {
    permissionModal.addEventListener("click", function (event) {
      if (event.target === permissionModal || event.target.closest("[data-commercial-close-permissions]")) setPermissionModal(false);
    });
  }
  if (permissionForm) {
    permissionForm.addEventListener("change", function (event) {
      var master = event.target.closest(".commercial-permission-master");
      if (master && event.target.matches('input[name="admin_cargos[]"]')) {
        master.classList.toggle("is-checked", event.target.checked);
      }
    });
    permissionForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var submit = permissionForm.querySelector('button[type="submit"]');
      var message = permissionForm.querySelector("[data-commercial-permissions-message]");
      if (submit) submit.disabled = true;
      if (message) message.textContent = "Guardando configuración…";
      request("commercial_permissions_save", new FormData(permissionForm))
        .then(function (response) {
          if (message) message.textContent = response.message || "Configuración guardada.";
          notify("success", "Permisos y cargos visibles actualizados.");
        })
        .catch(function (error) {
          if (message) message.textContent = error.message;
          notify("error", error.message);
        })
        .finally(function () { if (submit) submit.disabled = false; });
    });
  }

  root.addEventListener("click", function (event) {
    var modal = event.target.closest("[data-commercial-advisory-modal]");
    if (!modal) return;
    if (event.target === modal || event.target.closest("[data-commercial-close-advisory]")) {
      event.preventDefault();
      showAdvisoryModal(false, modal, advisoryQueueActive);
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var modal = analysisModal();
    if (modal && modal.classList.contains("open")) setAnalysisModal(false);
    else if (document.getElementById("commercial-property-modal") && document.getElementById("commercial-property-modal").classList.contains("open")) setStandaloneModal(document.getElementById("commercial-property-modal"), false);
    else if (document.getElementById("commercial-sign-detail-modal") && document.getElementById("commercial-sign-detail-modal").classList.contains("open")) setStandaloneModal(document.getElementById("commercial-sign-detail-modal"), false);
    else if (caseModal && caseModal.classList.contains("open")) showCaseModal(false);
    else if (refreshAdvisoryModalRef() && advisoryModal.classList.contains("open")) showAdvisoryModal(false, advisoryModal, advisoryQueueActive);
    else if (permissionModal && permissionModal.classList.contains("open")) setPermissionModal(false);
    var drawer = document.getElementById("side-drawer");
    if (drawer && !drawer.classList.contains("translate-x-full")) drawer.classList.add("translate-x-full");
  });

  // Handle drawer open / close
  function openDrawer(open) {
    var drawer = document.getElementById("side-drawer");
    if (!drawer) return;
    if (open) {
      drawer.classList.remove("translate-x-full");
    } else {
      drawer.classList.add("translate-x-full");
    }
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("#btn-open-drawer") || event.target.closest("#btn-open-drawer-header")) {
      event.preventDefault();
      openDrawer(true);
      return;
    }
    var drawer = document.getElementById("side-drawer");
    if (drawer && !drawer.classList.contains("translate-x-full")) {
      if (!event.target.closest("#side-drawer") && !event.target.closest("#btn-open-drawer") && !event.target.closest("#btn-open-drawer-header") && !event.target.closest("[onclick*='side-drawer']")) {
        openDrawer(false);
      }
    }
  });

  // Quick search in header (Ctrl+K / Cmd+K, Enter and Click)
  function executeHeaderSearch() {
    var qs = document.getElementById("quick-search-nav");
    if (!qs) return;
    var val = qs.value.trim();
    var currentTab = activeTab();
    var taskTabs = ["abiertos", "mis_tickets", "postergados", "cerrados"];
    var targetTab = taskTabs.indexOf(currentTab) !== -1 ? currentTab : "abiertos";

    var activeForm = root.querySelector("[data-commercial-filter-form]");
    if (activeForm && taskTabs.indexOf(currentTab) !== -1) {
      var searchInput = activeForm.querySelector('input[name="busqueda"]');
      if (searchInput) searchInput.value = val;
      activeForm.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
      return;
    }

    var url = normalizedUrl(window.location.href);
    url.searchParams.set("tab", targetTab);
    if (val !== "") {
      url.searchParams.set("busqueda", val);
    } else {
      url.searchParams.delete("busqueda");
    }
    url.searchParams.delete("page");
    loadTickets(url.href, { focus: false });
  }

  var quickSearch = document.getElementById("quick-search-nav");
  if (quickSearch) {
    quickSearch.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        executeHeaderSearch();
      }
    });
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("#quick-search-btn")) {
      event.preventDefault();
      executeHeaderSearch();
    }
  });

  // Windows / Mac detection for shortcut badge
  try {
    var isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.userAgent || navigator.platform || "");
    var kbdBadge = document.getElementById("quick-search-kbd");
    if (kbdBadge) {
      kbdBadge.textContent = isMac ? "⌘K" : "Ctrl+K";
    }
  } catch (_e) {}

  document.addEventListener("keydown", function (event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
      var qs = document.getElementById("quick-search-nav");
      if (qs) {
        event.preventDefault();
        qs.focus();
        qs.select();
      }
    }
  });

  // Export visible table to CSV
  function exportVisibleTableToCsv(filename) {
    var table = document.getElementById("commercial-tickets-table") || root.querySelector("table");
    if (!table) return;
    var rows = Array.from(table.querySelectorAll("tr"));
    var csvContent = "\uFEFF";
    rows.forEach(function (row) {
      var cols = Array.from(row.querySelectorAll("th, td"));
      if (cols.length === 0) return;
      var rowData = cols.map(function (col) {
        var text = col.innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, " ").trim();
        return '"' + text.replace(/"/g, '""') + '"';
      });
      csvContent += rowData.join(";") + "\r\n";
    });
    var blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", filename || "reporte_tareas_comerciales.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("#btn-export-csv") || event.target.closest("#btn-export-report")) {
      event.preventDefault();
      exportVisibleTableToCsv("reporte_tareas_" + new Date().toISOString().slice(0, 10) + ".csv");
      notify("success", "Reporte descargado correctamente en formato CSV.");
    }
    if (event.target.closest("#scm-footer-guide")) {
      event.preventDefault();
      var guideBtn = document.getElementById("scm-open-guide");
      if (guideBtn) guideBtn.click();
    }
    if (event.target.closest("#scm-footer-permissions")) {
      event.preventDefault();
      var permBtn = document.getElementById("commercial-open-permissions");
      if (permBtn) permBtn.click();
    }
  });

  window.addEventListener("popstate", function () {
    var url = normalizedUrl(window.location.href);
    loadTickets(url.href, { history: false, focus: true });
  });
  if (activeTab() === "calendario" || root.querySelector("[data-scm-calendar-panel]")) {
    window.setTimeout(function () {
      setVisiblePanel("calendario");
      if (typeof window.initCalendarPanel === "function") {
        window.initCalendarPanel(root);
      }
    }, 0);
  }
  if (activeTab() === "inicio") {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", maybeAutoOpenAdvisory);
    } else {
      window.setTimeout(maybeAutoOpenAdvisory, 50);
    }
  }
  if (root.querySelector("[data-commercial-home-controls]") || activeTab() === "actualizaciones" || activeTab() === "avisos") {
    window.setTimeout(initHomeControls, 0);
  }
})();
