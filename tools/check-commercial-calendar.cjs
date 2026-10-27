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

  // Solo las categorías habilitadas para comercial se ofrecen al crear.
  await page.locator('[data-scm-calendar-configure-categories]').click();
  await page.locator('[data-scm-calendar-category-form]').waitFor();
  await page.locator('[data-scm-category-toggle="0"]').click();
  await page.locator('[name="calendar_categories"][value="3"]').check();
  await page.locator('[name="calendar_categories"][value="26"]').check();
  await page.locator('.swal2-confirm').click();
  await page.locator('.swal2-popup:has-text("Categorías del calendario comercial guardadas")').waitFor();
  await closeSwal(page);
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
  console.log('Calendario comercial UI: agenda, edición, categorías comerciales, vencimientos y agendar desde la tarea: OK');
  await browser.close();
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
