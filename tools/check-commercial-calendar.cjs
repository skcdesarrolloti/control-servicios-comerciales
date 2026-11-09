// Calendario comercial con base SQLite aislada (tests/Fixtures/commercial-calendar.php) y public/calendar-api.php
// simulada. Servir el repositorio en 127.0.0.1:8769 y ejecutar:
//   node tools/check-commercial-calendar.cjs RUTA_A_NODE_MODULES
const path = require('path');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
const endpoint = 'http://127.0.0.1:8769/tests/Fixtures/commercial-calendar.php';
let browser;

const pad = n => String(n).padStart(2, '0');
const now = new Date();
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const categories = [
  { id: 3, nombre: 'Visita comercial', color: '#2563eb' },
  { id: 26, nombre: 'Captación', color: '#16a34a' },
  { id: 40, nombre: 'Avalúo', color: '#f59e0b' },
  { id: 12, nombre: 'Preventiva', color: '#7c3aed' },
];
let overdue = [{ id: 77, titulo: 'Visita sin cerrar', fecha_inicio: '2026-10-20 10:00:00', fecha_fin: '2026-10-20 11:00:00', id_ticket: '1', id_empleado: '999' }];
let toClose = [];
const events = [
  { id: 501, tipo_item: 'evento', titulo: 'Visita apartamento Manga', fecha_inicio: `${today} 09:00:00`, fecha_fin: `${today} 10:00:00`, id_empleado: '999', id_categoria: 3, categoria: 'Visita comercial', color: '#2563eb', estado: 'No', ubicacion: 'Manga calle 25', id_ticket: '1' },
  { id: 502, tipo_item: 'recordatorio', titulo: 'Llamar propietario', fecha_inicio: `${today} 11:00:00`, fecha_fin: `${today} 11:00:00`, recordatorio_at: `${today} 11:00:00`, id_empleado: '999', id_categoria: 26, categoria: 'Captación', color: '#16a34a', estado: 'pendiente' },
];

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

async function closeSwal(page) {
  await page.locator('.swal2-confirm').click().catch(() => {});
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
}

(async () => {
  browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || 'msedge' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  const errors = [];
  const calls = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  const external = [];
  page.on('request', request => { if (/calendario-actividades|calendar-skc\.netlify/.test(request.url())) external.push(request.url()); });
  await page.route('**/calendar-api.php**', route => {
    const action = new URL(route.request().url()).searchParams.get('action');
    if (route.request().method() === 'POST') assert(route.request().headers()['x-scm-nonce'] === 'fixture', 'La API del calendario no recibe el nonce: ' + action);
    let body = {};
    try { body = route.request().postDataJSON() || {}; } catch (error) { body = {}; }
    calls.push({ action, body });
    const data = {
      listar_categorias: categories,
      listar_items_calendario: events,
      filtrar_eventos_admin: events,
      filtrar_eventos: events.filter(row => String(row.id_ticket || '') === String(body.id_ticket || '')),
      listar_pendientes_vencidos: overdue,
      listar_por_cerrar: toClose,
      crear_evento: { id: 900, google_pendiente: false },
      crear_item_calendario: { id: 901, aviso_programado: true, google_pendiente: false },
      crear_recordatorios: { creados: 3, aviso_programado: true },
      obtener_tickets_funcionario: [{ _ID: '1', id_ticket: '1', solicitante: 'Laura Gómez', direccion: 'Calle 25 #20-10', estado_comercial: 'Contactado' }],
      preparar_reporte_comercial: Number(body.id_evento) === 501 ? { habilitado: true, tipos_permitidos: ['Visita', 'Otras actividades'], tipo_predeterminado: 'Visita', inmueble_obligatorio: true, valor_transporte: 4000, valor_maximo: 8000, inmuebles: [{ id: '4501', codigo: '4501', titulo: 'Apartamento Manga', direccion: 'Calle 25', id_cotizacion: '9' }] } : { habilitado: false },
      cambiar_estado: { google_pendiente: false },
    }[action] ?? [];
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, message: 'OK', data }) });
  });

  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.locator('[data-scm-calendar-panel][data-scm-calendar-init="1"]').waitFor();
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-total]').textContent === '2');
  assert(!(await page.locator('[name="item_type"][value="tarea"]').count()), 'Sigue la capa de tareas que el calendario ya no soporta');
  assert(await page.locator('[data-scm-calendar-events] [data-scm-calendar-edit-event]').count() === 1, 'El evento del día no ofrece Editar');

  // Configuración → Calendario comercial: solo las categorías habilitadas se ofrecen al crear.
  const addDays = await page.locator('[data-scm-calendar-add-day]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-scm-calendar-add-day')));
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  const yesterdayKey = `${yesterday.getFullYear()}-${pad(yesterday.getMonth() + 1)}-${pad(yesterday.getDate())}`;
  assert(addDays.length > 0 && addDays.every(key => key >= yesterdayKey), 'El mes ofrece crear en días ya pasados');
  assert(!(await page.locator('[data-scm-calendar-configure-categories]').count()), 'La configuración no debe quedar como botón dentro del panel');
  await page.locator('#commercial-config-trigger').click();
  await page.locator('[data-commercial-config-action="calendario"]').click();
  await page.locator('[data-config-form] [name="category_ids"]').first().waitFor();
  assert(await page.locator('[data-category-row][data-id="3"] [data-category-remove]').count() === 0, 'Una categoría con eventos se puede eliminar');
  assert(await page.locator('[data-category-row][data-id="12"] [data-category-remove]').count() === 1, 'Una categoría sin eventos no ofrece Eliminar');
  await page.locator('[data-category-add]').click();
  await page.locator('[data-category-rows] tr:last-child [data-field="nombre"]').fill('Feria inmobiliaria');
  const categorySave = page.waitForResponse(response => response.request().method() === 'POST' && (response.request().postData() || '').includes('commercial_settings_save'));
  await page.locator('[data-toggle-all="category_ids"][data-value="0"]').click();
  await page.locator('[name="category_ids"][value="3"]').check();
  await page.locator('[name="category_ids"][value="26"]').check();
  await page.locator('[data-category-rows] tr:last-child [name="category_ids"]').check();
  await page.locator('.swal2-confirm').click();
  const savedCategories = ((await (await categorySave).json()).data.settings || {}).calendario || {};
  assert((savedCategories.categories || []).some(row => row.nombre === 'Feria inmobiliaria'), 'La categoría nueva no quedó guardada');
  assert((savedCategories.category_ids || []).length === 3, 'La categoría nueva no quedó habilitada para comercial');
  await page.locator('.swal2-popup:has-text("Configuración del calendario comercial guardada")').waitFor();
  await closeSwal(page);
  const configSaves = calls.length;

  // Las demás secciones cargan y guardan desde el mismo menú.
  for (const [section, message] of [['tiempos', 'Tiempos de atención de tareas guardados'], ['avisos', 'Plazos y valores de avisos guardados'], ['actualizaciones', 'Plazos de actualización de inmuebles guardados'], ['destacados', 'Cupos de destacados guardados'], ['reportes', 'Configuración de reportes comerciales guardada']]) {
    await page.locator('#commercial-config-trigger').click();
    await page.locator(`[data-commercial-config-action="${section}"]`).click();
    await page.locator('[data-config-form] input').first().waitFor();
    await page.locator('.swal2-confirm').click();
    await page.locator(`.swal2-popup:has-text("${message}")`).waitFor();
    await closeSwal(page);
  }

  // Configuración → Generador UTM: solo las campañas (el medio sale de Configuración → Medios); el servidor normaliza y
  // quita repetidos.
  await page.locator('#commercial-config-trigger').click();
  await page.locator('[data-commercial-config-action="utm"]').click();
  await page.locator('[data-utm-list="campanas"]').waitFor();
  assert(!(await page.locator('[data-utm-list="medios"], [data-utm-list="fuentes"]').count()), 'El catálogo UTM sigue mostrando medios o fuentes');
  await page.locator('[data-utm-list="campanas"] [data-utm-add-row]').click();
  await page.locator('[data-utm-list="campanas"] tbody [data-utm-row] [data-field="label"]').last().fill('Feria Vivienda 2026');
  await page.locator('[data-utm-list="campanas"] [data-utm-add-row]').click();
  await page.locator('[data-utm-list="campanas"] tbody [data-utm-row] [data-field="label"]').last().fill('Feria vivienda 2026');
  // La base del fixture vive en memoria por petición: lo guardado se revisa en la respuesta (settings recargados).
  const utmSaved = page.waitForResponse(response => response.request().method() === 'POST' && (response.request().postData() || '').includes('commercial_settings_save'));
  await page.locator('.swal2-confirm').click();
  const utmSettings = ((await (await utmSaved).json()).data.settings || {}).utm || {};
  assert(JSON.stringify(utmSettings) === JSON.stringify({ campanas: [{ label: 'Feria Vivienda 2026', value: 'feria_vivienda_2026' }] }), 'El catálogo UTM no normalizó la campaña: ' + JSON.stringify(utmSettings));
  await page.locator('.swal2-popup:has-text("Campañas del generador UTM guardadas")').waitFor();
  await closeSwal(page);

  // Configuración → Medios sin la tabla instalada: muestra la lista inicial, avisa el paso de sistemas y no se edita.
  await page.locator('#commercial-config-trigger').click();
  await page.locator('[data-commercial-config-action="medios"]').click();
  await page.locator('[data-medios-root] [data-medios-not-ready]').waitFor();
  assert(await page.locator('[data-medios-root] [data-medio-row]').count() >= 42, 'Falta la lista inicial de medios');
  assert(!(await page.locator('[data-medios-root] [data-medios-add], [data-medios-root] [data-medios-action]').count()), 'Sin instalar no se debe poder editar la lista');
  assert(!(await page.locator('.swal2-confirm').isVisible()), 'Medios no lleva botón Guardar');
  await page.locator('.swal2-cancel').click();
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
  assert(configSaves > 0, 'No se consultaron las categorías del calendario');
  await page.locator('[data-scm-calendar-open-create][data-calendar-mode="single"]:not([data-calendar-kind])').first().click();
  await page.locator('.scm-calendar-create-form').waitFor();
  const clockMinutes = time => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
  const nowMinutes = () => new Date().getHours() * 60 + new Date().getMinutes();
  const openedAt = await page.locator('.scm-cal-form [name="hora_inicio"]').inputValue();
  assert(Math.abs(clockMinutes(openedAt) - nowMinutes()) <= 2, 'El formulario no abre con la hora actual: ' + openedAt);
  const createCategories =await page.locator('.scm-calendar-create-form [name="id_categoria"] option').allTextContents();
  assert(createCategories.join('|') === 'Selecciona categoría|Visita comercial|Captación', 'El popup de crear no respeta las categorías comerciales: ' + createCategories.join(', '));
  // Formulario corto: un solo paso, con lo opcional recogido.
  assert(await page.locator('.scm-cal-kinds input').count() === 2, 'Crear desde el calendario solo debe ofrecer evento y recordatorio');
  assert(await page.locator('[data-commercial-schedule-form] [name="id_empleado"]').getAttribute('type') === 'hidden', 'Mi calendario no debe pedir funcionario');
  assert(!(await page.locator('.scm-calendar-availability-panel').count()), 'Sigue el panel lateral del formulario anterior');
  assert(!(await page.locator('[name="sincronizar_google"]').count()) && !/google/i.test(await page.locator('.scm-calendar-create-shell').textContent()), 'El formulario sigue ofreciendo Google Calendar');
  assert(await page.locator('.scm-cal-form [name="descripcion"]').isVisible() && await page.locator('.scm-cal-form [name="repetir"]').isVisible(), 'Más opciones debe verse siempre en el evento');
  await page.locator('[data-commercial-schedule-form] [name="id_categoria"]').selectOption('26');
  assert(await page.locator('[name="titulo"]').inputValue() === 'Captación', 'El título no se completa con la categoría');
  await page.locator('[data-form-place="Oficina Manga"]').click();
  assert(await page.locator('[name="ubicacion"]').inputValue() === 'Oficina Manga', 'El acceso rápido de oficina no llena la ubicación');
  // Ancho: el formulario usa todo el popup y la categoría no se recorta.
  assert((await page.locator('.scm-cal-swal').boundingBox()).width >= 840, 'El formulario no tomó el ancho nuevo');
  assert((await page.locator('.scm-cal-form [name="id_categoria"]').boundingBox()).width >= 300, 'La categoría sigue angosta');
  const alignedTop = async selector => Math.round((await page.locator(selector).boundingBox()).y);
  assert(await alignedTop('.scm-cal-form [name="ubicacion"]') === await alignedTop('[data-ticket-search]'), 'Ubicación y Tarea relacionada no quedan alineadas');
  assert(await page.locator('.scm-cal-kinds label:has-text("Recordatorio") span').evaluate(node => node.scrollWidth <= node.clientWidth), 'Se parte el texto del selector de tipo');
  assert(!(await page.locator('[name="aviso_minutos_antes"]').isVisible()), 'Un evento no debe ofrecer Avisar: el aviso es del recordatorio');
  // Tarea relacionada con buscador.
  const ticketSearch = page.locator('[data-ticket-search]');
  await page.waitForFunction(() => document.querySelector('[data-ticket-search]').placeholder.startsWith('Buscar'));
  await ticketSearch.fill('gomez zzz');
  assert((await page.locator('[data-ticket-list]').textContent()).includes('Sin resultados'), 'El buscador de tareas no filtra');
  await ticketSearch.fill('laura gomez');
  await page.locator('[data-ticket-list] [role="option"]').first().click();
  assert(await page.locator('[name="id_ticket"]').inputValue() === '1' && (await ticketSearch.inputValue()).includes('Laura Gómez'), 'El buscador no selecciona la tarea');
  assert(await page.locator('[data-form-status]').evaluate(node => !node.hidden), 'Al elegir tarea debe habilitarse su estado comercial');
  await page.locator('[data-ticket-clear]').click();
  assert(await page.locator('[name="id_ticket"]').inputValue() === '', 'No se puede quitar la tarea elegida');
  await page.locator('.scm-cal-kinds label:has-text("Recordatorio")').click();
  assert(!(await page.locator('[name="ubicacion"]').isVisible()) && !(await page.locator('[name="duracion"]').isVisible()) && await page.locator('[name="aviso_minutos_antes"]').isVisible(), 'El recordatorio no adapta sus campos');
  assert(await page.locator('.scm-cal-form [name="descripcion"]').isVisible() && await page.locator('.scm-cal-form [name="repetir"]').isVisible(), 'Más opciones debe verse siempre en el recordatorio');
  await page.locator('.scm-cal-kinds label:has-text("Evento")').click();
  await page.setViewportSize({ width: 390, height: 800 });
  assert(await page.evaluate(() => { const form = document.querySelector('.scm-cal-form'); return form.scrollWidth <= form.clientWidth + 1; }), 'El formulario se desborda en móvil');
  await page.setViewportSize({ width: 1440, height: 950 });
  assert(!(await page.locator('.scm-calendar-create-shell').textContent()).includes('&'), 'El popup de crear muestra entidades HTML');
  await page.locator('.swal2-cancel').click();
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});

  // Un recordatorio también se repite: se crea uno por fecha.
  await page.locator('[data-scm-calendar-open-create][data-calendar-mode="single"]:not([data-calendar-kind])').first().click();
  await page.locator('.scm-calendar-create-form').waitFor();
  await page.locator('.scm-cal-kinds label:has-text("Recordatorio")').click();
  await page.locator('.scm-cal-form [name="id_categoria"]').selectOption('26');
  await page.locator('.scm-cal-form [name="titulo"]').fill('Llamar cada día');
  const repeatEnd = new Date(await page.locator('.scm-cal-form [name="fecha"]').inputValue() + 'T00:00:00');
  repeatEnd.setDate(repeatEnd.getDate() + 2);
  await page.locator('.scm-cal-form [name="repetir"]').selectOption('diario');
  await page.locator('.scm-cal-form [name="repetir_hasta"]').fill(`${repeatEnd.getFullYear()}-${pad(repeatEnd.getMonth() + 1)}-${pad(repeatEnd.getDate())}`);
  // Más de una hora atrás no se crea (de madrugada aún no hay hora suficiente para probarlo).
  if (new Date().getHours() >= 2) {
    const currentHour = await page.locator('.scm-cal-form [name="hora_inicio"]').inputValue();
    await page.locator('.scm-cal-form [name="hora_inicio"]').fill('00:00');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-validation-message:has-text("una hora antes")').waitFor();
    assert(!calls.some(call => call.action === 'crear_recordatorios'), 'Se creó un recordatorio de hace más de una hora');
    await page.locator('.scm-cal-form [name="hora_inicio"]').fill(currentHour);
  }
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !document.querySelector('[data-commercial-schedule-form]'));
  const repeatedReminder = calls.filter(call => call.action === 'crear_recordatorios').pop();
  assert(repeatedReminder && repeatedReminder.body.fechas.length === 3 && repeatedReminder.body.titulo === 'Llamar cada día', 'El recordatorio no se repite por fecha');
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});

  // Vencimientos comerciales calculados en el servidor.
  await page.goto(endpoint + '?tab=calendario&subtab=due');
  await page.waitForFunction(() => Number(document.querySelector('[data-scm-calendar-total]').textContent) > 0);
  const dueText = await page.locator('[data-scm-calendar-grid]').textContent();
  assert(dueText.includes('Tarea #2') && dueText.includes('Seguimiento tarea #1'), 'Faltan los vencimientos de tareas y seguimientos');
  // Lo que venció antes del mes visible va en Atrasados, no en la grilla.
  assert((await page.locator('[data-scm-calendar-backlog]').textContent()).includes('Tarea #1'), 'Faltan las tareas atrasadas');
  assert(await page.locator('[data-scm-calendar-due-type-filter] [name="due_type"]').count() === 7, 'Faltan tipos de vencimiento comercial');

  // Filtro por funcionario: el administrador ve todo el equipo y puede acotar a un funcionario.
  const dueEmployee = page.locator('[data-scm-calendar-due-employee]');
  assert(await dueEmployee.count() === 1, 'Vencimientos no ofrece el filtro por funcionario');
  const dueEmployeeId = await dueEmployee.locator('option').nth(1).getAttribute('value');
  const dueRequest = page.waitForRequest(request => request.method() === 'POST' && (request.postData() || '').includes('commercial_calendar_due') && (request.postData() || '').includes(`name="id_empleado"\r\n\r\n${dueEmployeeId}`));
  await dueEmployee.selectOption(dueEmployeeId);
  await dueRequest;
  await page.locator('[data-scm-calendar-spinner].active').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
  await dueEmployee.selectOption('');
  await page.waitForFunction(() => Number(document.querySelector('[data-scm-calendar-total]').textContent) > 0);

  // Semana y día estilo Google Calendar: los vencimientos sin hora van en "Todo el día" y abren su propio detalle.
  await page.locator('[data-scm-calendar-view-mode="week"]').click();
  await page.locator('[data-scm-calendar-week-grid] .scm-calendar-allday-cell').first().waitFor();
  assert(await page.locator('[data-scm-calendar-week-grid] .scm-calendar-allday-chip, [data-scm-calendar-week-grid] .scm-calendar-allday-more').count() > 0, 'La semana no muestra los vencimientos en la franja Todo el día');
  const tallDue = await page.locator('[data-scm-calendar-week-grid] .scm-calendar-time-event').evaluateAll(nodes => nodes.filter(node => node.getBoundingClientRect().height > 200).length);
  assert(tallDue === 0, 'Siguen apareciendo vencimientos como bloques de 08:00 a 18:00');
  const firstChip = page.locator('[data-scm-calendar-week-grid] .scm-calendar-allday-chip').first();
  const chipTitle = (await firstChip.textContent()).trim();
  await firstChip.click();
  await page.locator('[data-scm-calendar-due-popover]').waitFor();
  assert((await page.locator('[data-scm-calendar-due-popover]').textContent()).includes(chipTitle), 'El detalle no corresponde al vencimiento pulsado');
  assert(!(await page.locator('.swal2-container').count()), 'El vencimiento abre el detalle de evento');
  await page.keyboard.press('Escape');
  await page.locator('[data-scm-calendar-week-grid] [data-scm-calendar-open-day]').first().click();
  await page.locator('[data-scm-calendar-week-grid].scm-calendar-time-grid--day').waitFor();
  assert(await page.locator('[data-scm-calendar-view-mode="day"]').getAttribute('aria-pressed') === 'true', 'Clic en el día no abre la vista diaria');

  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-total]').textContent === '2');
  await page.locator('[data-scm-calendar-view-mode="week"]').click();
  const weekEvent = page.locator('[data-scm-calendar-week-grid] .scm-calendar-time-event[data-event-id="501"]');
  await weekEvent.waitFor();
  const eventBox = await weekEvent.boundingBox();
  assert(eventBox && eventBox.height > 40 && eventBox.height < 60, 'El evento de una hora no ocupa una hora en la semana');
  await weekEvent.click();
  await page.locator('.scm-calendar-event-detail-swal').waitFor();
  assert((await page.locator('.scm-calendar-event-detail-swal').textContent()).includes('Visita apartamento Manga'), 'El clic en la semana abre otro evento');
  await page.locator('.scm-calendar-event-detail-swal [data-scm-calendar-close]').first().click();
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});

  // Semana y día: lo que quedó más de una hora atrás no abre la creación; lo que viene sí.
  const assertSlotLimits = async () => {
    const pastSlots = page.locator('[data-scm-calendar-week-grid] [data-scm-calendar-week-slot].is-past');
    if (await pastSlots.count()) {
      const pastSlot = pastSlots.last();
      await pastSlot.scrollIntoViewIfNeeded();
      await pastSlot.click();
      assert(!(await page.locator('.scm-calendar-week-quick-popover').count()), 'Un horario pasado abre la creación');
      await page.locator('.swal2-title:has-text("Horario no disponible")').waitFor();
      assert(await page.locator('.swal2-icon-warning').isVisible(), 'El aviso de horario pasado no se muestra como advertencia');
      await page.locator('.swal2-confirm').click();
      await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
    }
    const openSlot = page.locator('[data-scm-calendar-week-grid] [data-scm-calendar-week-slot]:not(.is-past)').last();
    await openSlot.scrollIntoViewIfNeeded();
    await openSlot.click();
    await page.locator('.scm-calendar-week-quick-popover').waitFor();
    await page.locator('.scm-calendar-week-quick-popover [data-week-quick-close]').click();
  };
  await assertSlotLimits();
  await page.locator('[data-scm-calendar-view-mode="day"]').click();
  await page.locator('[data-scm-calendar-week-grid].scm-calendar-time-grid--day').waitFor();
  await assertSlotLimits();

  // Agendar desde la tarea conserva el estado administrativo y vincula la tarea.
  await page.goto(endpoint + '?tab=tarea');
  await page.locator('[data-commercial-calendar-task] [data-commercial-calendar-schedule]').click();
  await page.locator('[data-commercial-schedule-form]').waitFor();
  assert(await page.locator('.scm-cal-kinds label:has-text("Cita con cliente") input').isChecked(), 'Desde la tarea debe abrir como cita con cliente');
  // La simulación tiene un evento de 09:00 a 10:00 y el formulario abre con la hora actual.
  await page.locator('[data-commercial-schedule-form] [name="hora_inicio"]').fill('09:30');
  await page.locator('[data-commercial-schedule-agenda] .scm-calendar-availability-status.is-busy').waitFor();
  await page.locator('[data-commercial-schedule-form] [name="id_categoria"]').selectOption('3');
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !document.querySelector('[data-commercial-schedule-form]'));
  const created = calls.filter(call => call.action === 'crear_evento').pop();
  assert(created, 'No se creó la cita');
  assert(created.body.id_ticket === '1' && created.body.es_cita === 'si', 'La cita no queda vinculada a la tarea');
  assert(created.body.estado_comercial === 'Contactado', 'La cita cambia el estado comercial de la tarea');
  assert(!('estado_administrativo' in created.body) && !('creado_por' in created.body), 'El navegador no debe decidir el estado administrativo ni el autor');
  await closeSwal(page);

  // "Sin cerrar" cuenta lo mismo que su popup; sin vencidos no hay número ni alerta.
  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-pending-action-count]').textContent === '1');
  assert(!(await page.locator('[data-calendar-kind="cita"]').count()), 'Cita con cliente sigue en el calendario');
  assert(!(await page.locator('[data-calendar-mode="multiple"]').count()), 'Para varios no aplica en Mi calendario');
  await page.locator('[data-scm-calendar-open-pending]').click();
  await page.locator('.scm-calendar-pending-modern-shell:has-text("Visita sin cerrar")').waitFor();
  assert(await page.locator('.scm-calendar-pending-modern-alert').isVisible(), 'Con vencidos debe mostrar la alerta');
  await page.locator('.scm-calendar-pending-modern-foot [data-calendar-pending-close]').click();
  overdue = [];
  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-pending-action-count]').style.display === 'none');
  await page.locator('[data-scm-calendar-open-pending]').click();
  await page.locator('.scm-calendar-pending-modern-shell:has-text("Sin compromisos vencidos")').waitFor();
  assert(!(await page.locator('[data-calendar-to-close]').isVisible()), 'La sección Por marcar realizado aparece vacía');
  await page.locator('.scm-calendar-pending-modern-foot [data-calendar-pending-close]').click();

  // "Por marcar realizado" va aparte y no suma al número del botón.
  toClose = [{ id: 78, titulo: 'Visita de esta mañana', fecha_inicio: `${today} 08:00:00`, fecha_fin: `${today} 09:00:00`, id_empleado: '999' }];
  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-pending-action-count]').style.display === 'none');
  await page.locator('[data-scm-calendar-open-pending]').click();
  await page.locator('[data-calendar-to-close]:has-text("Visita de esta mañana")').waitFor();
  assert((await page.locator('[data-calendar-pending-content]').textContent()).includes('Sin compromisos vencidos'), 'Lo reciente se mezcló con los vencidos');
  await page.locator('[data-calendar-to-close] [data-scm-calendar-complete-event][data-event-id="78"]').click();
  await page.locator('.scm-calendar-complete-form').waitFor();
  assert((await page.locator('.scm-calendar-complete-form').textContent()).includes('Visita de esta mañana'), 'Marcar realizado abre otro evento');
  await page.locator('.scm-calendar-complete-form [data-scm-calendar-complete-cancel]').first().click();
  await page.locator('.scm-calendar-pending-modern-shell').waitFor();
  toClose = [];
  assert(!(await page.locator('.scm-calendar-pending-modern-alert').isVisible()), 'Sin vencidos no debe decir que se encontraron compromisos');
  assert(!(await page.locator('.scm-calendar-pending-modern-head em').isVisible()), 'Sin vencidos no debe decir Urgente');
  await page.locator('.scm-calendar-pending-modern-foot [data-calendar-pending-close]').click();
  await page.goto(endpoint + '?tab=calendario&subtab=team');
  await page.locator('[data-calendar-mode="multiple"]').waitFor();

  // Cerrar un evento que exige reporte comercial pide inmueble y envía el reporte.
  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-total]').textContent === '2');
  await page.locator('[data-scm-calendar-events] [data-scm-calendar-complete-event]').first().click();
  await page.locator('[data-commercial-report-form]').waitFor();
  assert(await page.locator('[data-commercial-report-form] [name="transporte"]').count() === 3, 'No pregunta por el cobro de transporte');
  assert((await page.locator('[data-commercial-report-form]').textContent()).includes('$8.000'), 'No muestra el valor configurado de transporte');
  await page.locator('.swal2-confirm').click();
  await page.locator('.swal2-validation-message:has-text("cobrar transporte")').waitFor();
  await page.locator('[data-commercial-report-form] label:has-text("ida y vuelta")').click();
  await page.locator('.swal2-confirm').click();
  await page.locator('.swal2-validation-message:has-text("Debes seleccionar un inmueble")').waitFor();
  await page.locator('[data-commercial-report-form] [name="id_inmueble"]').selectOption('4501');
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !document.querySelector('[data-commercial-report-form]'));
  const closed = calls.filter(call => call.action === 'cambiar_estado').pop();
  assert(closed && closed.body.reporte && closed.body.reporte.id_inmueble === '4501' && closed.body.reporte.id_cotizacion === '9' && closed.body.reporte.transporte === 'ida_vuelta' && !('valor' in closed.body.reporte), 'El cierre no envía el reporte comercial con la opción de transporte');

  assert(!external.length, 'Sigue llamando a la app externa: ' + external.join(', '));
  assert(!calls.some(call => /google/.test(call.action)), 'Sigue consultando Google Calendar');
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('Calendario comercial UI: API propia, agenda, edición, Configuración y categorías, formulario corto, vencimientos, agendar desde la tarea y reporte al cerrar: OK');
  await browser.close();
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
