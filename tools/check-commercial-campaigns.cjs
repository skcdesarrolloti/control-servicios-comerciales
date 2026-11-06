/* Notificaciones › Campañas y Plantillas de correo contra el fixture local: php -S 127.0.0.1:8774 -t .
   SCM_PLAYWRIGHT_MODULE apunta al paquete playwright si no está instalado aquí. */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

const FIXTURE = 'http://127.0.0.1:8774/tests/Fixtures/commercial-campaigns.php';

function watch(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // El iframe aislado de la vista previa bloquea los scripts de una plantilla: es lo esperado.
  page.on('console', message => { if (message.type() === 'error' && !/Failed to load resource: .*status of (403|422)|Blocked script execution in 'about:srcdoc'/.test(message.text())) errors.push('console: ' + message.text()); });
  page.on('dialog', async dialog => { errors.push('Alerta nativa: ' + dialog.message()); await dialog.dismiss(); });
  return errors;
}

async function post(page, body) {
  return page.evaluate(async ({ url, fields }) => {
    const data = new FormData();
    Object.entries(fields).forEach(([key, value]) => data.set(key, value));
    const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
    return { status: response.status, json: await response.json() };
  }, { url: FIXTURE, fields: { nonce: 'fixture', ...body } });
}

async function toast(page, text) {
  await page.waitForFunction(expected => document.body.innerText.includes(expected), text, { timeout: 10000 });
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
  try {
    // ── Acceso total (11) ──────────────────────────────────────────────────────────────────────────────────────
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = watch(page);
    await page.goto(FIXTURE + '?cargo=11&tab=campanas');
    await page.locator('[data-camp-list]').waitFor();
    const menu = page.locator('[data-commercial-dropdown-menu="notificaciones"] a');
    assert.equal(await menu.count(), 3);
    assert.match(await menu.nth(1).getAttribute('href'), /[?&]tab=campanas$/);
    assert.equal(await page.locator('[data-camp-row="Revista octubre"]').count(), 1);

    // Informe: enviados, fallidos y acciones.
    await page.locator('[data-camp-detail="Revista octubre"]').click();
    const detail = page.locator('[data-camp-dialog="detail"]');
    await detail.locator('.cpr-camp-stats').waitFor();
    const detailText = await detail.innerText();
    assert.ok(detailText.includes('1 de 2'), detailText);
    assert.ok(detailText.includes('Buzón lleno'));
    assert.equal(await detail.locator('[data-camp-op="retry"]').count(), 1);
    assert.equal(await detail.locator('[data-camp-op="exclude"]').count(), 1);
    await detail.locator('[data-camp-op="exclude"]').click();
    const confirm = page.locator('[data-camp-dialog="confirm"]');
    await confirm.waitFor({ state: 'visible' });
    await confirm.locator('[name="reason"]').fill('Pidió no recibir más');
    await confirm.locator('[name="confirm"]').check();
    await confirm.locator('button[type=submit]').click();
    await toast(page, 'excluida de esta campaña');
    await page.locator('[data-camp-list]').waitFor();

    // Renombrar (el fixture se recrea en cada petición: que el historial siga a la campaña lo cubre CommercialMarketingClosureTest).
    await page.locator('[data-camp-detail="Revista octubre"]').click();
    await detail.locator('[data-camp-op="rename"]').click();
    const rename = page.locator('[data-camp-dialog="rename"]');
    await rename.waitFor({ state: 'visible' });
    assert.equal(await rename.locator('[name="new_tag"]').inputValue(), 'Revista octubre');
    await rename.locator('[name="new_tag"]').fill('Revista octubre');
    await rename.locator('button[type=submit]').click();
    await rename.locator('[data-camp-error]', { hasText: 'ya tiene ese nombre' }).waitFor();
    await rename.locator('[name="new_tag"]').fill('Revista otoño');
    await rename.locator('button[type=submit]').click();
    await toast(page, 'Campaña renombrada a "Revista otoño"');
    await page.locator('[data-camp-row="Revista octubre"]').waitFor();
    // Eliminar exige la confirmación en el servidor.
    assert.equal((await post(page, { action: 'commercial_campaign_control', tag: 'Revista octubre', operation: 'delete' })).status, 422);

    // Nueva campaña: calcular, vista previa y programar.
    await page.locator('[data-camp-open="new"]').click();
    const dialog = page.locator('[data-camp-dialog="new"]');
    await dialog.waitFor({ state: 'visible' });
    await dialog.locator('[name="name"]').fill('Boletín noviembre');
    await dialog.locator('[name="subject"]').fill('Hola {{nombre}}');
    await dialog.locator('[name="message"]').fill('Novedades de {{ciudad}}.');
    await dialog.locator('[data-camp-segment-on][value="clientes"]').check();
    await dialog.locator('[data-camp-segment-on][value="suscriptores"]').check();
    assert.equal(await dialog.locator('[data-camp-action="send"]').isDisabled(), true);
    await dialog.locator('[data-camp-action="preview"]').click();
    await dialog.locator('[data-camp-preview] table').waitFor();
    const previewText = await dialog.locator('[data-camp-preview]').innerText();
    assert.ok(previewText.includes('Clientes') && previewText.includes('Suscriptores'), previewText);
    assert.ok(previewText.includes('Así lo recibe Laura Gómez'));
    const frame = await dialog.locator('[data-camp-sample-frame]').getAttribute('srcdoc');
    assert.ok(frame.includes('Novedades de Medellín.'));
    await dialog.locator('[data-camp-action="test"]').click();
    await toast(page, 'Prueba enviada a admin@example.test');
    // Cambiar algo después de calcular obliga a calcular otra vez.
    await dialog.locator('[name="message"]').fill('Novedades de {{ciudad}}!');
    assert.equal(await dialog.locator('[data-camp-action="send"]').isDisabled(), true);
    await dialog.locator('[data-camp-action="preview"]').click();
    await dialog.locator('[data-camp-confirm]').waitFor({ state: 'visible' });
    await dialog.locator('[data-camp-action="send"]').click();
    await dialog.locator('[data-camp-error]', { hasText: 'Marca la casilla' }).waitFor();
    await dialog.locator('[data-camp-confirm] input').check();
    await dialog.locator('[data-camp-action="send"]').click();
    await dialog.waitFor({ state: 'hidden' });
    await toast(page, 'Campaña programada');

    // SMS: sin asunto ni plantilla y con contador.
    await page.locator('[data-camp-open="new"]').click();
    await dialog.locator('[data-camp-channel]').selectOption('sms');
    assert.equal(await dialog.locator('[name="subject"]').isVisible(), false);
    await dialog.locator('[name="message"]').fill('Hola {{nombre}}');
    assert.ok((await dialog.locator('[data-camp-counter]').innerText()).includes('de 160'));
    await dialog.locator('[data-camp-close]').first().click();

    // Plantillas de correo.
    await page.locator('[data-commercial-dropdown-trigger="notificaciones"]').click();
    await page.locator('[data-commercial-dropdown-menu="notificaciones"] a', { hasText: 'Plantillas de correo' }).click();
    await page.locator('[data-tpl-list]').waitFor();
    assert.equal(await page.locator('[data-tpl-card]').count(), 1);
    await page.locator('[data-tpl-open="view"]').first().click();
    const view = page.locator('[data-tpl-dialog="view"]');
    await view.waitFor({ state: 'visible' });
    assert.ok((await view.locator('[data-tpl-view-frame]').getAttribute('srcdoc')).includes('Hola {{nombre}}'));
    await view.locator('[data-tpl-close]').click();
    await page.locator('[data-tpl-open="new"]').click();
    const edit = page.locator('[data-tpl-dialog="edit"]');
    await edit.waitFor({ state: 'visible' });
    await edit.locator('[name="nombre"]').fill('Boletín diciembre');
    await edit.locator('[name="asunto"]').fill('Felices fiestas, {{nombre}}');
    await edit.locator('[name="contenido"]').fill('<!doctype html><html><body><h1>Felices fiestas</h1></body></html>');
    await page.waitForFunction(() => (document.querySelector('[data-tpl-live]') || {}).srcdoc?.includes('Felices fiestas'));
    await edit.locator('button[type=submit]').click();
    await edit.waitFor({ state: 'hidden' });
    await toast(page, 'Plantilla creada');
    await page.locator('[data-tpl-open="archive"]').first().click();
    const archive = page.locator('[data-tpl-dialog="archive"]');
    await archive.locator('[name="confirm"]').check();
    await archive.locator('button[type=submit]').click();
    await toast(page, 'Plantilla archivada');

    // Celular: sin desplazamiento horizontal y la ventana cabe.
    await page.goto(FIXTURE + '?tab=campanas');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    await page.locator('[data-camp-open="new"]').click();
    const box = await dialog.boundingBox();
    assert.ok(box && box.x >= 0 && box.x + box.width <= 390, JSON.stringify(box));
    await page.screenshot({ path: 'output/campaigns-mobile.png' });
    assert.deepEqual(errors, []);
    await context.close();

    // ── Sin permisos de campañas (9): ve el informe sin acciones y el servidor rechaza enviar ───────────────────
    const plain = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const other = await plain.newPage();
    const otherErrors = watch(other);
    await other.goto(FIXTURE + '?cargo=9&tab=campanas');
    await other.locator('[data-camp-list]').waitFor();
    assert.equal(await other.locator('[data-camp-open="new"]').count(), 0);
    await other.locator('[data-camp-detail="Revista octubre"]').click();
    await other.locator('[data-camp-dialog="detail"] .cpr-camp-stats').waitFor();
    assert.equal(await other.locator('[data-camp-op]').count(), 0);
    const denied = await post(other, { action: 'commercial_campaign_send', name: 'X', channel: 'email', subject: 'X', message: 'X', segments: '[{"type":"clientes"}]', confirm: '1' });
    assert.equal(denied.status, 403);
    await other.goto(FIXTURE + '?tab=plantillas_correo');
    await other.locator('[data-tpl-list]').waitFor();
    assert.equal(await other.locator('[data-tpl-open="edit"]').count(), 0);
    assert.deepEqual(otherErrors, []);
    await plain.close();
    console.log('commercial campaigns: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
