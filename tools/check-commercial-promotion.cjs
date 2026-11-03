/* Promoción › Reuniones contra el fixture local: php -S 127.0.0.1:8773 -t .
   SCM_PLAYWRIGHT_MODULE apunta al paquete playwright si no está instalado aquí. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

const FIXTURE = 'http://127.0.0.1:8773/tests/Fixtures/commercial-promotion.php';

async function post(page, body) {
  return page.evaluate(async ({ url, fields }) => {
    const data = new FormData();
    Object.entries(fields).forEach(([key, value]) => data.set(key, value));
    const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
    return { status: response.status, json: await response.json() };
  }, { url: FIXTURE, fields: { nonce: 'fixture', ...body } });
}

async function confirmDialog(page, reason) {
  const dialog = page.locator('[data-cpr-dialog="confirm"]');
  await dialog.waitFor({ state: 'visible' });
  if (reason !== undefined) await dialog.locator('textarea').fill(reason);
  await dialog.locator('[data-cpr-confirm-accept]').click();
  await dialog.waitFor({ state: 'hidden' });
}

async function addItem(page, section, fields) {
  await page.locator(`[data-cpr-section="${section}"] [data-cpr-open="item"]`).first().click();
  const dialog = page.locator('[data-cpr-dialog="item"]');
  await dialog.waitFor({ state: 'visible' });
  await dialog.locator('[name="title"]').fill(fields.title);
  if (fields.responsible) await dialog.locator('[name="responsible_employee_id"]').selectOption(fields.responsible);
  if (fields.due) await dialog.locator('[name="due_date"]').fill(fields.due);
  await dialog.locator('[type="submit"]').click();
  await dialog.waitFor({ state: 'hidden' });
  await page.locator(`[data-cpr-section="${section}"] h3`, { hasText: fields.title }).waitFor();
}

(async () => {
  fs.rmSync('output/commercial-promotion.sqlite', { force: true });
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().includes('/assets/') && response.status() >= 400) errors.push('asset ' + response.status() + ' ' + response.url()); });

    // Promoción llega por AJAX desde otra pantalla: su CSS y su JS se cargan en ese momento.
    await page.goto(FIXTURE + '?cargo=13&tab=espera');
    assert.equal(await page.locator('script[data-commercial-module-src]').count(), 0);
    assert.equal(await page.locator('[data-commercial-dropdown-menu="promocion"] a').count(), 1);
    assert.match(await page.locator('[data-commercial-dropdown-menu="promocion"] a').getAttribute('href'), /[?&]tab=reuniones$/);
    await page.locator('[data-commercial-dropdown-trigger="promocion"]').click();
    await page.locator('[data-commercial-dropdown-menu="promocion"] a[href*="tab=reuniones"]').click();
    await page.locator('[data-cpr-meetings]').waitFor();
    await page.waitForFunction(() => window.__commercialPromotionLoaded === true);
    assert.equal(await page.locator('[data-commercial-dropdown-trigger="promocion"]').evaluate(node => node.classList.contains('active')), true);
    assert.equal(await page.locator('.cpr').evaluate(node => getComputedStyle(node).maxWidth), '1240px');
    assert.equal(await page.locator('script[data-commercial-module-src]').count(), 1);

    // Crear una reunión en el popup, con participantes buscados por nombre.
    await page.locator('[data-cpr-open="meeting"]').click();
    const meetingDialog = page.locator('[data-cpr-dialog="meeting"]');
    await meetingDialog.waitFor({ state: 'visible' });
    await meetingDialog.locator('[name="title"]').fill('Comité de promoción octubre');
    await meetingDialog.locator('[name="meeting_date"]').fill('2026-10-05');
    await meetingDialog.locator('[name="objective"]').fill('Revisar campañas y piezas');
    await meetingDialog.locator('[data-cpr-participant-search]').fill('segundo');
    assert.equal(await meetingDialog.locator('[data-cpr-option]:visible').count(), 1);
    await meetingDialog.locator('[data-cpr-option]:visible input').check();
    assert.match(await meetingDialog.locator('[data-cpr-participant-chips]').innerText(), /Segundo consultor/);
    await meetingDialog.locator('[type="submit"]').click();
    await page.locator('.cpr-detail-hero h1', { hasText: 'Comité de promoción octubre' }).waitFor();
    assert.match(page.url(), /[?&]tab=reuniones&reunion=1$/);
    assert.match(await page.locator('.cpr-stats').innerText(), /Segundo consultor/);

    // Logros, mejoras y plan de acción; una acción sin responsable no se guarda.
    await addItem(page, 'achievement', { title: 'Se publicó la revista' });
    await addItem(page, 'improvement', { title: 'Respuesta lenta en WhatsApp' });
    await page.locator('[data-cpr-section="action"] [data-cpr-open="item"]').first().click();
    const itemDialog = page.locator('[data-cpr-dialog="item"]');
    await itemDialog.locator('[name="title"]').fill('Sin responsable');
    await itemDialog.locator('[name="due_date"]').fill('2026-10-20');
    await itemDialog.locator('[name="responsible_employee_id"]').evaluate(select => { select.required = false; });
    await itemDialog.locator('[type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-dialog="item"] [data-cpr-form-error]').textContent === 'Selecciona un responsable para la acción.');
    await itemDialog.locator('[data-cpr-close]').first().click();
    await addItem(page, 'action', { title: 'Responder en dos horas', responsible: '202', due: '2026-10-20' });
    await addItem(page, 'action', { title: 'Pauta en radio', responsible: '303', due: '2026-10-15' });
    assert.deepEqual(await page.locator('[data-cpr-section="action"] h3').allInnerTexts(), ['Responder en dos horas', 'Pauta en radio']);
    await page.locator('[data-cpr-section="action"] [data-cpr-item]').nth(1).locator('[data-direction="up"]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-section="action"] h3').textContent === 'Pauta en radio');
    await page.locator('[data-cpr-section="action"] [data-cpr-item]').nth(0).locator('[data-direction="down"]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-section="action"] h3').textContent === 'Responder en dos horas');

    // Modo guía: cinco pasos, estados sin recargar y motivo obligatorio para "No realizado".
    await page.locator('[data-cpr-action="start"]').click();
    await page.locator('[data-cpr-guide]').waitFor();
    assert.match(page.url(), /modo=guia/);
    assert.equal(await page.locator('[data-cpr-step-panel]').count(), 5);
    for (let step = 0; step < 3; step++) await page.locator('[data-cpr-step="next"]').click();
    const planStep = page.locator('[data-cpr-step-panel="3"]');
    assert.equal(await planStep.isVisible(), true);
    const firstAction = planStep.locator('[data-cpr-action-card]').nth(0);
    await firstAction.locator('[data-cpr-status="completed"]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-step-panel="3"] [data-cpr-action-card] [data-cpr-action-label]').textContent === 'Realizada');
    assert.equal(await page.locator('.cpr-detail-hero [data-cpr-progress-reviewed]').innerText(), '1/2');
    const secondAction = planStep.locator('[data-cpr-action-card]').nth(1);
    await secondAction.locator('[data-cpr-status="not_completed"]').click();
    const confirm = page.locator('[data-cpr-dialog="confirm"]');
    await confirm.waitFor({ state: 'visible' });
    await confirm.locator('[data-cpr-close]').click();
    await confirm.waitFor({ state: 'hidden' });
    assert.equal(await secondAction.locator('[data-cpr-action-label]').innerText(), 'Pendiente');
    await secondAction.locator('[data-cpr-status="not_completed"]').click();
    await confirmDialog(page, 'Sin presupuesto este mes');
    await page.waitForFunction(() => document.querySelectorAll('[data-cpr-step-panel="3"] [data-cpr-action-label]')[1].textContent === 'No realizada');
    assert.equal(await secondAction.locator('[data-cpr-failure] span').innerText(), 'Sin presupuesto este mes');
    assert.equal(await page.locator('.cpr-detail-hero [data-cpr-progress-reviewed]').innerText(), '2/2');

    // Finalizar con confirmación: la reunión queda bloqueada.
    await page.locator('[data-cpr-step="next"]').click();
    await page.locator('[data-cpr-form="complete"] textarea').fill('Se mantiene la pauta digital');
    await page.locator('[data-cpr-form="complete"] [type="submit"]').click();
    await confirmDialog(page);
    await page.locator('.cpr-detail-hero .cpr-chip', { hasText: 'Finalizada' }).waitFor();
    assert.equal(await page.locator('[data-cpr-open="item"]').count(), 0);
    assert.equal(await page.locator('[data-cpr-status]').count(), 0);
    assert.match(await page.locator('.cpr-conclusions').innerText(), /Se mantiene la pauta digital/);
    assert.match(await page.locator('.cpr-history summary').innerText(), /Historial de estados \(2\)/);

    // La siguiente reunión trae la acción no realizada (y no la realizada).
    await page.locator('.cpr-back').click();
    await page.locator('[data-cpr-list]').waitFor();
    await page.locator('[data-cpr-open="meeting"]').click();
    await meetingDialog.locator('[name="title"]').fill('Comité de promoción noviembre');
    await meetingDialog.locator('[name="meeting_date"]').fill('2026-11-05');
    await meetingDialog.locator('[type="submit"]').click();
    await page.locator('.cpr-detail-hero h1', { hasText: 'noviembre' }).waitFor();
    assert.deepEqual(await page.locator('[data-cpr-section="action"] h3').allInnerTexts(), ['Pauta en radio']);
    assert.match(await page.locator('[data-cpr-section="action"]').innerText(), /Seguimiento anterior/);

    // Eliminar pide confirmación (cancelar no elimina) y deja la reunión fuera de la lista.
    await page.locator('[data-cpr-action="delete"]').click();
    await confirm.waitFor({ state: 'visible' });
    assert.match(await confirm.locator('[data-cpr-confirm-text]').innerText(), /Quedará registrado quién la eliminó/);
    await confirm.press('Escape');
    await confirm.waitFor({ state: 'hidden' });
    assert.equal(await page.locator('.cpr-detail-hero h1').innerText(), 'Comité de promoción noviembre');
    await page.locator('[data-cpr-action="delete"]').click();
    await confirmDialog(page);
    await page.locator('[data-cpr-list]').waitFor();
    assert.deepEqual(await page.locator('[data-cpr-list] h2').allInnerTexts(), ['Comité de promoción octubre']);

    // Reabrir la finalizada; los filtros de la lista navegan dentro del panel.
    await page.locator('[data-cpr-list] h2 a').click();
    await page.locator('[data-cpr-action="reopen"]').click();
    await confirmDialog(page);
    await page.locator('.cpr-detail-hero .cpr-chip', { hasText: 'En curso' }).waitFor();
    await page.locator('.cpr-back').click();
    await page.locator('[data-cpr-filters] [name="reunion_q"]').fill('inexistente');
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    await page.locator('.cpr-empty', { hasText: 'No encontramos reuniones' }).waitFor();
    assert.match(page.url(), /reunion_q=inexistente/);
    await page.goBack();
    await page.locator('[data-cpr-list] h2', { hasText: 'octubre' }).waitFor();

    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({ path: 'output/commercial-promotion-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    await page.locator('[data-cpr-list] h2 a').click();
    await page.locator('.cpr-detail-hero').waitFor();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    await page.locator('[data-commercial-dropdown-trigger="mobile"]').click();
    assert.equal(await page.locator('[data-commercial-dropdown-menu="mobile"] a', { hasText: 'Promoción · Reuniones' }).count(), 1);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({ path: 'output/commercial-promotion-detail.png', fullPage: true });
    assert.deepEqual(errors, []);
    await context.close();

    // Solo consulta (cargo 8, ve las reuniones donde participa): sin botones de cambio.
    const readOnly = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    try {
      const other = await readOnly.newPage();
      await other.goto(FIXTURE + '?cargo=8&tab=reuniones');
      await other.locator('[data-cpr-list]').waitFor();
      assert.deepEqual(await other.locator('[data-cpr-list] h2').allInnerTexts(), ['Comité de promoción octubre']);
      assert.equal(await other.locator('[data-cpr-open="meeting"]').count(), 0);
      await other.locator('[data-cpr-list] h2 a').click();
      await other.locator('.cpr-detail-hero').waitFor();
      assert.equal(await other.locator('[data-cpr-action], [data-cpr-status], [data-cpr-open]').count(), 0);
      const denied = await post(other, { action: 'commercial_promo_meeting_archive', meeting_id: '1' });
      assert.deepEqual(denied, { status: 403, json: { success: false, data: { message: 'No tienes permiso para modificar reuniones.' } } });
    } finally {
      await readOnly.close();
    }

    // Cargo sin configurar: Promoción no aparece (opt-in) y el servidor la rechaza.
    const hidden = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    try {
      const other = await hidden.newPage();
      await other.goto(FIXTURE + '?cargo=10&tab=reuniones');
      await other.locator('[data-commercial-access-denied]').waitFor();
      assert.equal(await other.locator('[data-commercial-dropdown="promocion"]').count(), 0);
      assert.equal(await other.locator('[data-commercial-dropdown="herramientas"]').count(), 1);
      const denied = await post(other, { action: 'commercial_promo_meeting_save', title: 'x', meeting_date: '2026-11-05' });
      assert.deepEqual(denied, { status: 403, json: { success: false, data: { message: 'No tienes permiso para entrar a Reuniones.' } } });
      const panel = await post(other, { action: 'commercial_tickets_filter', tab: 'reuniones' });
      assert.equal(panel.status, 403);
      assert.equal(panel.json.data.page_denied, true);
    } finally {
      await hidden.close();
    }
    console.log('commercial promotion: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });

function launchBrowser() {
  return chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
}
