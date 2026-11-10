// Pestaña Llamadas con base SQLite aislada (tests/Fixtures/commercial-calls.php). Servir el repositorio en 127.0.0.1:8774 y ejecutar:
//   node tools/check-commercial-calls.cjs RUTA_A_NODE_MODULES
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
const endpoint = 'http://127.0.0.1:8774/tests/Fixtures/commercial-calls.php';

async function fillCall(modal, values) {
  for (const [name, value] of Object.entries(values)) {
    const field = modal.locator(`[name="${name}"]`);
    if (await field.evaluate(node => node.tagName === 'SELECT')) await field.selectOption(value);
    else await field.fill(value);
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    // El detalle de la tarea lo atiende el panel; aquí solo interesa que se pida.
    let openedCase = false;
    await page.route(endpoint, async route => {
      if ((route.request().postData() || '').includes('commercial_ticket_detail')) {
        openedCase = true;
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { html: '<p data-fixture-case>Tarea abierta</p>' } }) });
      }
      return route.continue();
    });
    await page.goto(endpoint);
    await page.locator('[data-ccl-page]').waitFor();
    assert.equal(await page.locator('.ccl-head h1').innerText(), 'Llamadas');
    assert.equal(await page.locator('[data-commercial-dropdown-menu="comerciales"] [data-commercial-tab="llamadas"]').count(), 1, 'Falta la entrada del menú');
    assert.ok(await page.locator('[data-commercial-dropdown-menu="mobile"] [data-commercial-tab="llamadas"]').count() >= 1, 'Falta la entrada móvil');
    assert.ok((await page.locator('.ccl-empty').innerText()).includes('No hay llamadas'));
    const modal = page.locator('#commercial-call-modal');

    await page.locator('[data-ccl-new]').click();
    await modal.locator('[data-ccl-form]').waitFor();
    assert.ok(await modal.locator('[data-ccl-pph]').isHidden());
    await modal.locator('[type="submit"]').click();
    assert.ok(await modal.locator('[data-ccl-form]').isVisible(), 'No debe guardar con campos vacíos');
    await fillCall(modal, { celular: '3001234567', cliente: 'Laura Prueba', email: 'laura@example.test', indicativo: '57', medio: 'Club PPH', tema_ayuda: 'Arriendo', comisionista: 'No', cliente_inmediato: 'No', merece_ticket: 'Si', id_empleado: '101' });
    assert.ok(await modal.locator('[data-ccl-pph]').isVisible(), 'Club PPH debe pedir la tarjeta');
    assert.ok(await modal.locator('[data-ccl-month]').isVisible(), 'Cliente no inmediato debe pedir el mes');
    await modal.locator('[name="mes_inmueble"]').selectOption('Enero');
    assert.ok(await page.evaluate(() => document.querySelector('.ccl-modal').getBoundingClientRect().width) >= 1200, 'El popup debe ser ancho');
    assert.ok(await modal.locator('[name="presupuesto"]').isVisible() && await modal.locator('[name="id_inmueble"]').isVisible(), 'Arriendo pide presupuesto y código');
    assert.ok(await modal.locator('[name="precio"]').isHidden(), 'Arriendo no pide precio');
    await modal.locator('[name="presupuesto"]').pressSequentially('1500000');
    assert.equal(await modal.locator('[name="presupuesto"]').inputValue(), '1.500.000');
    await modal.locator('[name="tema_ayuda"]').selectOption('Captacion');
    assert.ok(await modal.locator('[name="precio"]').isVisible(), 'Captación pide precio');
    assert.ok(await modal.locator('[name="presupuesto"]').isHidden() && await modal.locator('[name="id_inmueble"]').isHidden(), 'Captación no pide presupuesto ni código');
    await modal.locator('[name="tema_ayuda"]').selectOption('Arriendo');
    await modal.locator('[name="tarjeta_pph"]').fill('NO-EXISTE');
    await modal.locator('[name="tarjeta_pph"]').blur();
    await page.waitForFunction(() => document.querySelector('[data-ccl-card-result]').dataset.state === 'error');
    await modal.locator('[type="submit"]').click();
    await modal.locator('[data-ccl-error]').waitFor();
    assert.ok((await modal.locator('[data-ccl-error]').innerText()).includes('No encontramos'));
    await modal.locator('[name="tarjeta_pph"]').fill('TAR-1');
    await modal.locator('[name="tarjeta_pph"]').blur();
    await page.waitForFunction(() => document.querySelector('[data-ccl-card-result]').textContent.includes('Aliada Uno'));
    fs.mkdirSync('output', { recursive: true });
    await page.screenshot({ path: 'output/calls-form-desktop.png' });
    await modal.locator('[type="submit"]').click();

    // Al guardar una llamada que merece tarea, el mismo popup pasa al formulario de la tarea.
    await modal.locator('[data-ccl-task-form]').waitFor();
    const taskText = await modal.innerText();
    assert.ok(taskText.includes('Medio: Club PPH'));
    assert.ok(taskText.includes('Aliada Uno'));
    assert.equal(await modal.locator('[name="medio"]').count(), 0, 'La tarea no debe permitir cambiar el medio');
    assert.equal(await modal.locator('[name="solicitante"]').inputValue(), 'Laura Prueba');
    await page.screenshot({ path: 'output/calls-task-desktop.png' });
    await modal.locator('[name="asunto"]').fill('Busca apartamento');
    await modal.locator('[name="descripcion"]').fill('Quiere arrendar en enero');
    await modal.locator('[type="submit"]').click();
    await modal.waitFor({ state: 'hidden' });
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 1 && document.querySelector('[data-ccl-row] [data-commercial-open-case]'));
    await page.waitForFunction(() => document.querySelector('[data-fixture-case]') !== null);
    assert.ok(openedCase, 'Debe abrir la tarea recién creada');
    await page.evaluate(() => { document.getElementById('commercial-case-modal').classList.remove('open'); document.body.classList.remove('commercial-modal-open'); });
    const row = await page.locator('[data-ccl-row]').innerText();
    assert.ok(row.includes('Aliada Uno') && row.includes('TAR-1'), row);
    assert.ok(row.includes('#1'), row);
    assert.equal(await page.locator('[data-ccl-state="con_tarea"] strong').innerText(), '1');
    assert.ok((await page.locator('.ccl-chip.is-static').innerText()).startsWith('1'));

    // Editar: el medio queda bloqueado y sin campo.
    await page.locator('[data-ccl-edit]').click();
    await modal.locator('[data-ccl-form]').waitFor();
    assert.equal(await modal.locator('[name="medio"]').count(), 0);
    assert.ok((await modal.innerText()).includes('Medio: Club PPH'));
    for (const name of ['tema_ayuda', 'comisionista', 'cliente_inmediato', 'presupuesto', 'descripcion', 'merece_ticket', 'id_empleado']) assert.equal(await modal.locator('[name="' + name + '"]').count(), 0, name + ' no se edita');
    await page.screenshot({ path: 'output/calls-edit-desktop.png' });
    await modal.locator('[name="cliente"]').fill('Laura Prueba Editada');
    await modal.locator('[type="submit"]').click();
    await modal.waitFor({ state: 'hidden' });
    await page.getByText('Laura Prueba Editada').first().waitFor();

    // Nueva solicitud del mismo contacto con otro medio.
    await page.locator('[data-ccl-row] [data-ccl-repeat]').click();
    await modal.locator('[data-ccl-form]').waitFor();
    assert.equal(await modal.locator('[name="cliente"]').inputValue(), 'Laura Prueba Editada');
    assert.equal(await modal.locator('[name="medio"]').inputValue(), '', 'La nueva solicitud elige su propio medio');
    await fillCall(modal, { medio: 'Referido', tema_ayuda: 'Venta', comisionista: 'No', cliente_inmediato: 'Si', merece_ticket: 'No', id_empleado: '101' });
    await modal.locator('[type="submit"]').click();
    await modal.waitFor({ state: 'hidden' });
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 2);

    await page.locator('[data-ccl-row] [data-ccl-history]').first().click();
    await modal.locator('.ccl-history').waitFor();
    assert.equal(await modal.locator('.ccl-history li').count(), 2);
    const history = await modal.locator('.ccl-history').innerText();
    assert.ok(history.includes('Club PPH') && history.includes('Referido'), history);
    await page.screenshot({ path: 'output/calls-history-desktop.png' });
    await modal.locator('[data-ccl-close]').first().click();

    // Un número conocido avisa en el formulario.
    await page.locator('[data-ccl-new]').click();
    await modal.locator('[name="celular"]').fill('3001234567');
    await modal.locator('[name="celular"]').blur();
    await modal.locator('[data-ccl-contact]').waitFor();
    assert.ok((await modal.locator('[data-ccl-contact]').innerText()).includes('2 solicitudes'));
    await modal.locator('[data-ccl-close]').first().click();

    await page.locator('[data-ccl-filters] [name="ll_medio"]').selectOption('Referido');
    await page.locator('[data-ccl-filters] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 1);
    assert.ok(new URL(page.url()).searchParams.get('ll_medio') === 'Referido');
    await page.locator('[data-ccl-clear]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 2);
    await page.locator('[data-ccl-toggle="ll_inmediato"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 1);
    assert.ok((await page.locator('[data-ccl-row]').innerText()).includes('Para Enero'));
    await page.locator('[data-ccl-toggle="ll_inmediato"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 2);
    await page.locator('[data-ccl-state="pendientes"]').click();
    await page.waitForFunction(() => document.querySelector('.ccl-empty') !== null);
    await page.locator('[data-ccl-state=""]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ccl-row]').length === 2);
    await page.screenshot({ path: 'output/calls-list-desktop.png', fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, 'Desbordamiento del listado en móvil');
    await page.screenshot({ path: 'output/calls-list-mobile.png', fullPage: true });
    await page.locator('[data-ccl-new]').click();
    await modal.locator('[data-ccl-form]').waitFor();
    const layout = await modal.locator('.ccl-modal').evaluate(card => ({ overflow: card.scrollWidth > card.clientWidth, footer: card.querySelector('.ccl-modal-foot').getBoundingClientRect().bottom <= window.innerHeight }));
    await page.screenshot({ path: 'output/calls-form-mobile.png' });
    assert.deepEqual(layout, { overflow: false, footer: true });
    await page.keyboard.press('Escape');
    await modal.waitFor({ state: 'hidden' });

    // Vista pública: el cliente entra con su enlace firmado, escribe y no ve lo interno.
    const reference = await (await context.request.get(endpoint + '?enlace_tarea=1')).text();
    const client = await context.newPage();
    await client.setViewportSize({ width: 390, height: 844 });
    await client.goto(endpoint + '?t=' + reference);
    assert.ok((await client.locator('h1').innerText()).includes('#1'));
    assert.ok(!(await client.locator('body').innerText()).includes('Quiere arrendar en enero'), 'El resumen interno no se muestra al cliente');
    await client.locator('#ptk-message').fill('Quisiera visitar el apartamento el sábado');
    await client.locator('.ptk-form button').click();
    await client.locator('.ptk-ok').waitFor();
    assert.ok((await client.locator('.ptk-thread').innerText()).includes('Quisiera visitar'));
    assert.equal(await client.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, 'Desbordamiento de la vista pública');
    await client.screenshot({ path: 'output/calls-public-mobile.png', fullPage: true });
    const forged = await client.goto(endpoint + '?t=1.1893456000.' + 'a'.repeat(64));
    assert.equal(forged.status(), 403, 'Un enlace alterado no debe abrir');
    assert.ok((await client.locator('.ptk-alert').innerText()).includes('no es válido'));
    await client.close();

    // El consultor 202 no ve llamadas ajenas, y la API tampoco se las entrega.
    const other = await context.newPage();
    await other.goto(endpoint + '?as=202');
    await other.locator('[data-ccl-page]').waitFor();
    assert.equal(await other.locator('[data-ccl-row]').count(), 0);
    const denied = await other.request.post(endpoint, { form: { action: 'commercial_calls_form', nonce: 'fixture', id: '1' } });
    assert.equal(denied.status(), 403);
    assert.equal((await other.request.post(endpoint, { form: { action: 'commercial_calls_list', nonce: 'mala' } })).status(), 403);
    assert.deepEqual(errors, []);
    console.log('Llamadas: menú, registro, Club PPH, tarea, edición con medio bloqueado, nueva solicitud, historial, filtros, móvil y permisos OK.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
