const assert = require('node:assert/strict');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, permissions: ['geolocation'], geolocation: { latitude: 6.2, longitude: -75.5, accuracy: 8 } });
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:8771/tests/Fixtures/commercial-property-entry.php');
    await page.locator('[data-commercial-open-case="100"]').click();
    await page.locator('[data-commercial-task-form="property"]').waitFor();
    await page.screenshot({ path: 'output/task-entry-actions.png' });
    await page.locator('[data-commercial-task-form="sign"]').click();
    const sign = page.locator('#commercial-case-modal [data-commercial-signs]');
    await sign.locator('[name=id_ticket]').waitFor();
    assert.equal(await sign.locator('[name=id_ticket]').inputValue(), '100');
    assert.equal(await sign.locator('[name=id_ticket]').getAttribute('readonly'), '');
    await page.waitForFunction(() => document.querySelector('#commercial-case-modal [data-sign-form]')?._lookupKey);
    await sign.locator('[name=aviso_senuelo]').selectOption('No');
    const image = { name: 'task-evidence.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64') };
    await sign.locator('input[type=file]').setInputFiles(image);
    await sign.locator('[data-sign-capture]').click();
    await page.waitForFunction(() => document.querySelector('#commercial-case-modal [data-sign-form]')?._locationKey);
    await sign.locator('[data-sign-submit]').click();
    await sign.locator('[data-sign-result]').waitFor({ state: 'visible' });
    await sign.locator('[data-commercial-return-case]').click();
    await page.locator('[data-commercial-task-form="property"]').click();
    const root = page.locator('[data-commercial-property-entry]');
    await root.waitFor();
    assert.equal(await root.locator('[name=id_ticket]').inputValue(), '100');
    assert.equal(await root.locator('[data-property-reserved]').isVisible(), false);
    await root.locator('[name=tipo_captacion]').selectOption('Recaptacion');
    assert.equal(await root.locator('[data-property-field=codigo_anterior]').isVisible(), true);
    await root.locator('[name=tipo_captacion]').selectOption('Nueva');
    assert.equal(await root.locator('[data-property-field=codigo_anterior]').isVisible(), false);
    await page.screenshot({ path: 'output/property-entry-desktop.png' });
    async function fillField(name, value) {
      const field = root.locator(`[name="${name}"]`);
      await field.evaluate(node => { const section = node.closest('details'); if (section) section.open = true; });
      if (await field.evaluate(node => node.tagName === 'SELECT')) await field.selectOption(value);
      else await field.fill(value);
    }
    async function fillRequired() {
      const fields = { tipo_captacion: 'Nueva', id_propietario: '10', titulo: 'Casa registrada desde la tarea', descripcion: 'Registro de prueba aislado.', tipo_oferta: '117', tipo_inmueble: '106', destinacion: '121', pertenece_regimen: 'No', area_construida: '100', area_terreno: '120', tiene_parqueadero: 'No', tiene_deposito: 'No', tiene_vigilancia: 'No', habitaciones: '2', banos: '1', precio_arriendo: '1500000', antiguedad_inmueble: '1 a 8 años', ano_construccion: '2020', avaluo_catastral: '100000000', referencia_catastral: 'CAT123', estrato: '3', procedencia: 'Internet', amoblado: 'No', desea_aviso: 'Si', pais: 'Colombia', departamento: 'Antioquia', ciudad: 'Medellín', barrio: 'El Poblado', direccion_completa: 'Calle 10 #20-30', punto_referencia: 'Frente al parque', direccion_fisica: 'Calle 10 #20-30', tiene_rnt: 'No' };
      for (const [name, value] of Object.entries(fields)) await fillField(name, value);
    }
    await fillRequired();
    assert.equal(await root.locator('[name=ruta]').inputValue(), '1');
    await fillField('tipo_oferta', '119');
    assert.equal(await root.locator('[name=precio_venta]').isEnabled(), true);
    assert.equal(await root.locator('[name=acepta_permuta]').isEnabled(), true);
    await fillField('tipo_oferta', '117');
    assert.equal(await root.locator('[name=precio_venta]').isDisabled(), true);
    await fillField('tipo_oferta', '118');
    await fillField('precio_venta', '250000000');
    await fillField('acepta_permuta', 'No');
    const api = 'http://127.0.0.1:8771/tests/Fixtures/commercial-property-entry.php';
    const denied = await context.request.post(api, { form: { action: 'commercial_property_entry_form', nonce: 'fixture', ticket_pk: '200' } });
    assert.equal(denied.status(), 403);
    let responsePromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url() === api);
    await root.locator('[data-property-submit]').click();
    let result = await (await responsePromise).json();
    assert.equal(result.success, true, JSON.stringify(result));
    assert.ok(result.data.id_inmueble > 800);
    await root.locator('[data-property-result]').waitFor({ state: 'visible' });
    await root.locator('[data-commercial-return-case]').click();
    await page.locator('[data-commercial-task-form="sign"]').click();
    await page.waitForFunction(() => document.querySelector('#commercial-case-modal [data-sign-form]')?._lookupKey);
    assert.equal(await sign.locator('[name=categoria]').inputValue(), 'Arriendo o venta');
    await sign.locator('[data-commercial-return-case]').click();
    await page.locator('[data-commercial-task-form="property"]').click();
    await root.waitFor();
    await root.locator('[name=modo_ingreso]').selectOption('adelantado');
    await root.locator('[name=codigo_adelantado]').fill('800');
    await fillRequired();
    await fillField('presenta-aviso', 'No');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'output/property-entry-mobile.png' });
    assert.equal(await root.evaluate(node => node.scrollWidth > node.clientWidth + 1), false);
    responsePromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url() === api);
    await root.locator('[data-property-submit]').click();
    result = await (await responsePromise).json();
    assert.equal(result.success, true, JSON.stringify(result));
    assert.equal(result.data.id_inmueble, 800);
    assert.deepEqual(errors, []);
    console.log('Task entry browser checks: OK (task actions, prefilled sign, new property, reserved code, conditional fields, task scope, return navigation and mobile).');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
