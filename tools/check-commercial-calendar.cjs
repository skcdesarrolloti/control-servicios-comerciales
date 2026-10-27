// Calendario comercial con base SQLite aislada (tests/Fixtures/commercial-calendar.php) y la API del
// calendario de actividades simulada. Servir el repositorio en 127.0.0.1:8769 y ejecutar:
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
  await page.route('**/calendario-actividades/index.php**', route => {
    const action = new URL(route.request().url()).searchParams.get('action');
    let body = {};
    try { body = route.request().postDataJSON() || {}; } catch (error) { body = {}; }
    calls.push({ action, body });
    const data = {
      listar_categorias: categories,
      listar_items_calendario: events,
      filtrar_eventos_admin: events,
      filtrar_eventos: events.filter(row => String(row.id_ticket || '') === String(body.id_ticket || '')),
      listar_pendientes_vencidos: [{ id: 77, titulo: 'Visita sin cerrar', fecha_inicio: '2026-10-20 10:00:00', id_ticket: '1' }],
      estado_google_oauth: { configured: true, connected: true, sync_enabled: true },
      crear_evento: { id: 900, google_pendiente: false },
    }[action] ?? [];
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, message: 'OK', data }) });
  });

  await page.goto(endpoint + '?tab=calendario&subtab=mine');
  await page.locator('[data-scm-calendar-panel][data-scm-calendar-init="1"]').waitFor();
  await page.waitForFunction(() => document.querySelector('[data-scm-calendar-total]').textContent === '2');
  assert(!(await page.locator('[name="item_type"][value="tarea"]').count()), 'Sigue la capa de tareas que el calendario ya no soporta');
  assert(await page.locator('[data-scm-calendar-events] [data-scm-calendar-edit-event]').count() === 1, 'El evento del día no ofrece Editar');

  // Configuración → Calendario comercial: solo las categorías habilitadas se ofrecen al crear.
  assert(!(await page.locator('[data-scm-calendar-configure-categories]').count()), 'La configuración no debe quedar como botón dentro del panel');
  await page.locator('#commercial-config-trigger').click();
  await page.locator('[data-commercial-config-action="calendario"]').click();
  await page.locator('[data-config-form] [name="category_ids"]').first().waitFor();
  await page.locator('[data-toggle-all="category_ids"][data-value="0"]').click();
  await page.locator('[name="category_ids"][value="3"]').check();
  await page.locator('[name="category_ids"][value="26"]').check();
  await page.locator('.swal2-confirm').click();
  await page.locator('.swal2-popup:has-text("Configuración del calendario comercial guardada")').waitFor();
  await closeSwal(page);
  const configSaves = calls.length;

  // Las demás secciones cargan y guardan desde el mismo menú.
  for (const [section, message] of [['tiempos', 'Tiempos de atención de tareas guardados'], ['avisos', 'Plazos y valores de avisos guardados'], ['actualizaciones', 'Plazos de actualización de inmuebles guardados'], ['destacados', 'Cupos de destacados guardados']]) {
    await page.locator('#commercial-config-trigger').click();
    await page.locator(`[data-commercial-config-action="${section}"]`).click();
    await page.locator('[data-config-form] input').first().waitFor();
    await page.locator('.swal2-confirm').click();
    await page.locator(`.swal2-popup:has-text("${message}")`).waitFor();
    await closeSwal(page);
  }
  assert(configSaves > 0, 'No se consultaron las categorías del calendario');
  await page.locator('[data-scm-calendar-open-create][data-calendar-mode="single"]:not([data-calendar-kind])').first().click();
  await page.locator('.scm-calendar-create-form').waitFor();
  const createCategories = await page.locator('.scm-calendar-create-form [name="id_categoria"] option').allTextContents();
  assert(createCategories.join('|') === 'Selecciona categoría|Visita comercial|Captación', 'El popup de crear no respeta las categorías comerciales: ' + createCategories.join(', '));
  assert(!(await page.locator('.scm-calendar-create-shell').textContent()).includes('&'), 'El popup de crear muestra entidades HTML');
  await page.locator('.swal2-cancel').click();

  // Vencimientos comerciales calculados en el servidor.
  await page.goto(endpoint + '?tab=calendario&subtab=due');
  await page.waitForFunction(() => Number(document.querySelector('[data-scm-calendar-total]').textContent) > 0);
  const dueText = await page.locator('[data-scm-calendar-grid]').textContent();
  assert(dueText.includes('Tarea #1') && dueText.includes('Seguimiento tarea #1'), 'Faltan los vencimientos de tareas y seguimientos');
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

  // Agendar desde la tarea conserva el estado administrativo y vincula la tarea.
  await page.goto(endpoint + '?tab=tarea');
  await page.locator('[data-commercial-calendar-task] [data-commercial-calendar-schedule]').click();
  await page.locator('[data-commercial-schedule-form]').waitFor();
  await page.locator('[data-commercial-schedule-agenda] .scm-calendar-availability-status.is-busy').waitFor();
  await page.locator('[data-commercial-schedule-form] [name="id_categoria"]').selectOption('3');
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !document.querySelector('[data-commercial-schedule-form]'));
  const created = calls.filter(call => call.action === 'crear_evento').pop();
  assert(created, 'No se creó la cita');
  assert(created.body.id_ticket === '1' && created.body.es_cita === 'si', 'La cita no queda vinculada a la tarea');
  assert(created.body.estado_comercial === 'Contactado' && created.body.estado_administrativo === 'Sin novedad', 'La cita borra los estados de la tarea');
  await closeSwal(page);

  if (errors.length) throw new Error(errors.join('\n'));
  console.log('Calendario comercial UI: agenda, edición, menú Configuración, categorías comerciales, vencimientos y agendar desde la tarea: OK');
  await browser.close();
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
