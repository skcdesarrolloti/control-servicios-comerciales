/* Promoción (Analítica web y Actividad de portales) contra el fixture local: php -S 127.0.0.1:8773 -t .
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
    const text = await response.text();
    return { status: response.status, text, json: JSON.parse(text) };
  }, { url: FIXTURE, fields: { nonce: 'fixture', ...body } });
}

function watch(page) {
  const errors = [];
  const logRequests = [];
  page.on('pageerror', error => errors.push(error.message));
  // Los 403/422 que provoca la propia prueba (permisos y validación por POST) no son errores de la página.
  page.on('console', message => { if (message.type() === 'error' && (message.location().url || '').includes('127.0.0.1') && !/Failed to load resource: .*status of (403|422)/.test(message.text())) errors.push('console: ' + message.text()); });
  page.on('response', response => { if (response.url().includes('127.0.0.1') && response.status() >= 400 && response.request().method() === 'GET') errors.push('http ' + response.status() + ' ' + response.url()); });
  page.on('request', request => { if ((request.postData() || '').includes('commercial_promo_analytics_logs')) logRequests.push(request); });
  return { errors, logRequests };
}

async function noHorizontalScroll(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
}

function daysBetween(from, to) {
  return Math.round((Date.parse(to + 'T00:00:00Z') - Date.parse(from + 'T00:00:00Z')) / 86400000);
}

(async () => {
  fs.rmSync('output/commercial-promotion.sqlite', { force: true });
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
  try {
    // ── Administrador: llega por AJAX, filtros, gráfico, listas y registros con IP ──────────────────────────────
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const admin = watch(page);
    await page.goto(FIXTURE + '?cargo=13&tab=espera');
    assert.equal(await page.locator('script[data-commercial-module-src]').count(), 0);
    assert.equal(await page.locator('link[data-commercial-module-style]').count(), 0);
    // Entre 1024 y 1280 px el menú de escritorio cabe completo: Promoción (el último) y su menú no se cortan.
    await page.setViewportSize({ width: 1024, height: 900 });
    const promoTrigger = page.locator('[data-commercial-dropdown-trigger="promocion"]');
    const triggerBox = await promoTrigger.boundingBox();
    assert.ok(triggerBox && triggerBox.x + triggerBox.width <= 1024, 'Promoción se sale a 1024 px: ' + JSON.stringify(triggerBox));
    assert.equal(await noHorizontalScroll(page), true);
    await promoTrigger.click();
    const promoMenuBox = await page.locator('[data-commercial-dropdown-menu="promocion"]').boundingBox();
    assert.ok(promoMenuBox && promoMenuBox.x >= 0 && promoMenuBox.x + promoMenuBox.width <= 1024, 'El menú de Promoción se sale a 1024 px: ' + JSON.stringify(promoMenuBox));
    await page.keyboard.press('Escape');
    await page.mouse.click(5, 880);
    await page.setViewportSize({ width: 1440, height: 1000 });
    const menuLinks = page.locator('[data-commercial-dropdown-menu="promocion"] a');
    assert.equal(await menuLinks.count(), 2);
    assert.match(await menuLinks.nth(0).getAttribute('href'), /[?&]tab=analitica_web$/);
    assert.match(await menuLinks.nth(1).getAttribute('href'), /[?&]tab=actividad_portales$/);
    assert.match(await page.locator('[data-commercial-dropdown-menu="promocion"]').innerText(), /Visitas, campañas y fuentes del sitio/);
    await page.locator('[data-commercial-dropdown-trigger="promocion"]').click();
    await menuLinks.nth(0).click();
    await page.locator('[data-commercial-promotion="analitica_web"] [data-cpr-kpis]').waitFor();
    await page.waitForFunction(() => window.__commercialPromotionLoaded === true);
    assert.match(page.url(), /[?&]tab=analitica_web$/);
    assert.equal(await page.locator('[data-commercial-dropdown-trigger="promocion"]').evaluate(node => node.classList.contains('active')), true);
    assert.equal(await page.locator('.cpr').evaluate(node => getComputedStyle(node).maxWidth), '1240px');
    assert.equal(await page.locator('script[data-commercial-module-src]').count(), 1);

    // Rango por defecto: los últimos 30 días, con un gráfico de 30 barras y las ocho listas.
    const from = await page.locator('[data-cpr-filters] [name="pr_desde"]').inputValue();
    const to = await page.locator('[data-cpr-filters] [name="pr_hasta"]').inputValue();
    assert.equal(daysBetween(from, to), 29);
    assert.equal(await page.locator('[data-cpr-chart] .cpr-chart__bar').count(), 30);
    assert.equal(await page.locator('[data-cpr-list]').count(), 8);
    for (const list of ['campaigns', 'sources', 'mediums', 'pages', 'referrers', 'countries', 'cities', 'objects']) {
      assert.ok(await page.locator(`[data-cpr-list="${list}"] tbody tr`).count() > 0, 'lista vacía: ' + list);
    }
    assert.match(await page.locator('[data-cpr-list="campaigns"]').innerText(), /Promoción de octubre/);
    assert.match(await page.locator('[data-cpr-list="objects"]').innerText(), /INM-101/);
    assert.doesNotMatch(await page.locator('[data-cpr-list="pages"]').innerText(), /fbclid|utm_source/);
    assert.match(await page.locator('[data-cpr-excluded]').innerText(), /robots/);
    assert.match(await page.locator('.cpr').innerText(), /seguimiento de clics no se está registrando/);
    const totalVisits = Number((await page.locator('[data-cpr-kpis] .cpr-stat__value').first().innerText()).replace(/\./g, ''));
    assert.ok(totalVisits > 50);

    // Los filtros no se envían solos: solo con "Aplicar", y por AJAX (sin recargar la página).
    await page.evaluate(() => { window.__cprSamePage = true; });
    await page.locator('[data-cpr-filters] [name="pr_campana"]').selectOption('promo-octubre');
    await page.waitForTimeout(400);
    assert.doesNotMatch(page.url(), /pr_campana/);
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    await page.waitForURL(/pr_campana=promo-octubre/);
    await page.locator('[data-cpr-filters] [name="pr_campana"]').evaluate(select => select.value === 'promo-octubre' || Promise.reject(new Error('filtro perdido')));
    await page.waitForFunction(() => document.querySelectorAll('[data-cpr-list="campaigns"] tbody tr').length === 1);
    assert.equal(await page.evaluate(() => window.__cprSamePage), true);
    assert.match(await page.locator('[data-cpr-list="campaigns"] tbody').innerText(), /Promoción de octubre/);
    const filteredVisits = Number((await page.locator('[data-cpr-kpis] .cpr-stat__value').first().innerText()).replace(/\./g, ''));
    assert.ok(filteredVisits > 0 && filteredVisits < totalVisits);
    // Fechas al revés: no se aplica y se avisa.
    await page.locator('[data-cpr-filters] [name="pr_desde"]').fill(to);
    await page.locator('[data-cpr-filters] [name="pr_hasta"]').fill(from);
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    assert.match(await page.locator('[data-cpr-live]').innerText(), /fecha inicial no puede ser posterior/);

    // Registros: no se consultan al abrir; se cargan a pedido, 25 por página, y paginan hacia adelante y atrás.
    assert.equal(admin.logRequests.length, 0);
    assert.equal(await page.locator('[data-cpr-logs-body]').isVisible(), false);
    await page.locator('[data-cpr-logs-load]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cpr-log-row]').length === 25);
    assert.equal(admin.logRequests.length, 1);
    assert.equal(await page.locator('[data-cpr-logs] thead th', { hasText: /^IP$/ }).count(), 1);
    assert.match(await page.locator('[data-cpr-logs-rows]').innerText(), /181\.50\./);
    const firstPage = await page.locator('[data-cpr-log-row] td:first-child').allInnerTexts();
    await page.locator('[data-cpr-logs-next]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-logs-page]').textContent === 'Página 2');
    const secondPage = await page.locator('[data-cpr-log-row] td:first-child').allInnerTexts();
    assert.notDeepEqual(secondPage, firstPage);
    assert.equal(await page.locator('[data-cpr-logs-prev]').isDisabled(), false);
    await page.locator('[data-cpr-logs-prev]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpr-logs-page]').textContent === 'Página 1');
    assert.deepEqual(await page.locator('[data-cpr-log-row] td:first-child').allInnerTexts(), firstPage);
    await page.screenshot({ path: 'output/commercial-promotion-analytics.png', fullPage: true });

    // Actividad de portales: filtros exactos, paginación y IP (el administrador la ve).
    await page.locator('[data-commercial-dropdown-trigger="promocion"]').click();
    await menuLinks.nth(1).click();
    await page.locator('[data-commercial-promotion="actividad_portales"] [data-cpr-kpis]').waitFor();
    assert.match(page.url(), /[?&]tab=actividad_portales$/);
    assert.equal(await page.locator('[data-cpr-kpis] .cpr-stat__value').first().innerText(), '60');
    assert.match(await page.locator('[data-cpr-page-label]').innerText(), /Página 1 de 3/);
    assert.equal(await page.locator('[data-cpr-recent] thead th', { hasText: /^IP$/ }).count(), 1);
    assert.doesNotMatch(await page.locator('[data-cpr-filters] [name="pr_menu"]').innerText(), /Menú antiguo/);
    await page.locator('[data-cpr-page="next"]').click();
    await page.waitForFunction(() => (document.querySelector('[data-cpr-page-label]') || {}).textContent === 'Página 2 de 3');
    assert.match(page.url(), /pr_pag=2/);
    await page.locator('[data-cpr-filters] [name="pr_actor"]').selectOption('Club PPH');
    await page.locator('[data-cpr-filters] [name="pr_proyecto"]').selectOption('club-pph');
    await page.waitForTimeout(300);
    assert.doesNotMatch(page.url(), /pr_actor/);
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    await page.waitForURL(/pr_actor=Club\+PPH/);
    await page.waitForFunction(() => document.querySelector('[data-cpr-kpis] .cpr-stat__value').textContent === '15');
    assert.doesNotMatch(page.url(), /pr_pag/);
    assert.match(await page.locator('[data-cpr-actors] tbody').innerText(), /^Club PPH/);
    assert.equal(await page.locator('[data-cpr-actors] tbody tr').count(), 1);
    await page.locator('[data-cpr-filters] [name="pr_q"]').fill('aliado');
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    await page.waitForURL(/pr_q=aliado/);
    await page.waitForFunction(() => document.querySelector('[data-cpr-kpis] .cpr-stat__value').textContent === '15');
    await page.locator('[data-cpr-filters] [name="pr_q"]').fill('liado');
    await page.locator('[data-cpr-filters] [type="submit"]').click();
    await page.waitForURL(/pr_q=liado/);
    await page.waitForFunction(() => document.querySelector('[data-cpr-kpis] .cpr-stat__value').textContent === '0');
    await page.goBack();
    await page.waitForFunction(() => document.querySelector('[data-cpr-kpis] .cpr-stat__value').textContent === '15');

    // Configuración › Analítica web: carga la configuración y el estado, valida y guarda.
    await page.locator('#commercial-config-trigger').click();
    await page.locator('[data-commercial-config-action="analitica"]').click();
    const form = page.locator('[data-analytics-config]');
    await form.waitFor();
    assert.match(await form.locator('[name="internal_ips"]').inputValue(), /10\.0\.0\.0\/8/);
    assert.equal(await form.locator('[name="exclude_bots"]').isChecked(), true);
    assert.equal(await form.locator('[name="raw_retention_days"]').inputValue(), '0');
    assert.match(await form.innerText(), /Estado de los resúmenes/);
    assert.match(await form.innerText(), /Visitas pendientes por resumir\s+0/);
    // Fechas en dd/mm/aaaa hh:mm, sin _ID internos ni comandos en el texto principal.
    const statusText = await form.locator('table').innerText();
    assert.doesNotMatch(statusText, /#\d|\d{4}-\d{2}-\d{2}/);
    assert.match(statusText, /\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/);
    assert.match(await form.locator('p').first().innerText(), /pide a sistemas/);
    await form.locator('[name="raw_retention_days"]').fill('10');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-validation-message', { hasText: 'al menos 30 días' }).waitFor();
    // Bajar los días (de 0 = nunca a 400) programa un borrado: pide una confirmación clara en el mismo popup.
    const retentionConfirm = form.locator('[data-retention-confirm]');
    assert.equal(await retentionConfirm.isVisible(), false);
    await form.locator('[name="raw_retention_days"]').fill('400');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-validation-message', { hasText: 'Confirma que entiendes el borrado' }).waitFor();
    assert.equal(await retentionConfirm.isVisible(), true);
    assert.match(await retentionConfirm.innerText(), /últimos 400 días[\s\S]*no se pueden recuperar[\s\S]*panel de marketing anterior/);
    await form.locator('[name="raw_retention_days"]').fill('0');
    assert.equal(await retentionConfirm.isVisible(), false);
    await form.locator('[name="raw_retention_days"]').fill('400');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-validation-message', { hasText: 'Confirma que entiendes el borrado' }).waitFor();
    await form.locator('[name="confirm_retention"]').check();
    const savedRetention = page.waitForResponse(response => response.request().method() === 'POST' && (response.request().postData() || '').includes('commercial_settings_save'));
    await page.locator('.swal2-confirm').click();
    assert.equal((await (await savedRetention).json()).data.settings.analitica.raw_retention_days, 400);
    await page.locator('.swal2-popup', { hasText: 'Configuración de la analítica web guardada' }).waitFor();
    await page.locator('.swal2-confirm').click().catch(() => {});
    await page.locator('.swal2-popup').waitFor({ state: 'detached' }).catch(() => {});
    await page.locator('#commercial-config-trigger').click();
    await page.locator('[data-commercial-config-action="analitica"]').click();
    await form.waitFor();
    assert.equal(await form.locator('[name="raw_retention_days"]').inputValue(), '400');
    // Subir a 0 (nunca borrar) no pide confirmación.
    await form.locator('[name="raw_retention_days"]').fill('0');
    await form.locator('[name="internal_ips"]').fill('10.0.0.0/8\n190.0.0.1');
    const saved = page.waitForResponse(response => response.request().method() === 'POST' && (response.request().postData() || '').includes('commercial_settings_save'));
    await page.locator('.swal2-confirm').click();
    const savedJson = await (await saved).json();
    assert.equal(savedJson.success, true);
    assert.deepEqual(savedJson.data.settings.analitica.internal_ips, ['10.0.0.0/8', '190.0.0.1']);
    await page.locator('.swal2-popup', { hasText: 'Configuración de la analítica web guardada' }).waitFor();
    await page.locator('.swal2-confirm').click().catch(() => {});
    // Una IP que no es IP no se guarda.
    const invalid = await post(page, { action: 'commercial_settings_save', section: 'analitica', payload: JSON.stringify({ internal_ips: 'no-es-ip', raw_retention_days: '0' }) });
    assert.equal(invalid.status, 422);

    // Móvil: menú con las dos subpestañas y sin desplazamiento horizontal.
    await page.setViewportSize({ width: 390, height: 900 });
    assert.equal(await noHorizontalScroll(page), true);
    await page.locator('[data-commercial-dropdown-trigger="mobile"]').click();
    const mobile = page.locator('[data-commercial-dropdown-menu="mobile"]');
    assert.equal(await mobile.locator('a', { hasText: 'Promoción · Analítica web' }).count(), 1);
    assert.equal(await mobile.locator('a', { hasText: 'Promoción · Actividad de portales' }).count(), 1);
    await mobile.locator('a', { hasText: 'Promoción · Analítica web' }).click();
    await page.locator('[data-commercial-promotion="analitica_web"] [data-cpr-kpis]').waitFor();
    await page.locator('[data-cpr-logs-load]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cpr-log-row]').length > 0);
    assert.equal(await noHorizontalScroll(page), true);
    await page.screenshot({ path: 'output/commercial-promotion-mobile.png', fullPage: true });
    assert.deepEqual(admin.errors, []);
    await context.close();

    // ── Cargo sin promocion_ver_ip (7): ni la IP ni el navegador llegan al navegador ─────────────────────────────
    const plainContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    try {
      const other = await plainContext.newPage();
      const plain = watch(other);
      const bodies = [];
      other.on('response', async response => { if ((response.request().postData() || '').includes('commercial_promo_analytics_logs')) bodies.push(await response.text()); });
      // Con una visita sin resumir, la carga completa no la resume (sesión abierta): la pantalla la pide sola por AJAX.
      const reloads = [];
      other.on('request', request => { if ((request.postData() || '').includes('commercial_tickets_filter')) reloads.push(request); });
      const documentResponse = await other.goto(FIXTURE + '?cargo=7&tab=analitica_web&pendiente=1');
      assert.match(await documentResponse.text(), /data-cpr-sync="behind" data-cpr-sync-auto/);
      await other.locator('[data-cpr-sync="ok"]').waitFor();
      assert.equal(reloads.length, 1);
      assert.equal(await other.locator('[data-cpr-sync-auto]').count(), 0);
      await other.locator('[data-cpr-kpis]').waitFor();
      assert.equal(await other.locator('#commercial-config-trigger').count(), 0);
      assert.equal(await other.locator('[data-cpr-logs]').getAttribute('data-network'), '0');
      await other.locator('[data-cpr-logs-load]').click();
      await other.waitForFunction(() => document.querySelectorAll('[data-cpr-log-row]').length === 25);
      assert.equal(await other.locator('[data-cpr-logs] thead th', { hasText: /^IP$/ }).count(), 0);
      assert.equal(bodies.length, 1);
      assert.doesNotMatch(bodies[0], /"ip"|"user_agent"|181\.50\.|Chrome/);
      assert.doesNotMatch(await other.content(), /181\.50\.|181\.60\./);
      const direct = await post(other, { action: 'commercial_promo_analytics_logs' });
      assert.equal(direct.status, 200);
      assert.doesNotMatch(direct.text, /"ip"|"user_agent"/);
      await other.goto(FIXTURE + '?tab=actividad_portales');
      await other.locator('[data-cpr-recent]').waitFor();
      assert.equal(await other.locator('[data-cpr-recent] thead th', { hasText: /^IP$/ }).count(), 0);
      assert.doesNotMatch(await other.content(), /181\.60\.|Chrome\/130/);
      // Configuración es solo para administradores, también en el servidor.
      const settingsDenied = await post(other, { action: 'commercial_settings_save', section: 'analitica', payload: JSON.stringify({ internal_ips: '', raw_retention_days: '0' }) });
      assert.equal(settingsDenied.status, 403);
      assert.deepEqual(plain.errors, []);
    } finally {
      await plainContext.close();
    }

    // ── Cargo con promocion_ver_ip (6): la ve en los dos lados ───────────────────────────────────────────────────
    const ipContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    try {
      const other = await ipContext.newPage();
      await other.goto(FIXTURE + '?cargo=6&tab=analitica_web');
      await other.locator('[data-cpr-logs-load]').click();
      await other.waitForFunction(() => document.querySelectorAll('[data-cpr-log-row]').length === 25);
      assert.equal(await other.locator('[data-cpr-logs] thead th', { hasText: /^IP$/ }).count(), 1);
      assert.ok(await other.locator('[data-cpr-log-ip]').first().innerText());
      await other.goto(FIXTURE + '?tab=actividad_portales');
      assert.equal(await other.locator('[data-cpr-recent] thead th', { hasText: /^IP$/ }).count(), 1);
    } finally {
      await ipContext.close();
    }

    // ── Solo Actividad de portales (8): el menú y el servidor respetan la subpestaña ──────────────────────────────
    const subContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    try {
      const other = await subContext.newPage();
      await other.goto(FIXTURE + '?cargo=8&tab=actividad_portales');
      await other.locator('[data-cpr-kpis]').waitFor();
      assert.equal(await other.locator('[data-commercial-dropdown-menu="promocion"] a').count(), 1);
      assert.equal((await post(other, { action: 'commercial_promo_analytics_logs' })).status, 403);
      const panel = await post(other, { action: 'commercial_tickets_filter', tab: 'analitica_web' });
      assert.equal(panel.status, 403);
      assert.equal(panel.json.data.page_denied, true);
    } finally {
      await subContext.close();
    }

    // ── Cargo sin configurar (10): Promoción no aparece (opt-in) y el servidor la rechaza ────────────────────────
    const hidden = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    try {
      const other = await hidden.newPage();
      await other.goto(FIXTURE + '?cargo=10&tab=analitica_web');
      await other.locator('[data-commercial-access-denied]').waitFor();
      assert.equal(await other.locator('[data-commercial-dropdown="promocion"]').count(), 0);
      assert.equal(await other.locator('[data-commercial-dropdown="herramientas"]').count(), 1);
      assert.equal(await other.locator('#commercial-config-trigger').count(), 0);
      const denied = await post(other, { action: 'commercial_promo_analytics_logs' });
      assert.deepEqual([denied.status, denied.json.data.message], [403, 'No tienes permiso para entrar a Analítica web.']);
      const panel = await post(other, { action: 'commercial_tickets_filter', tab: 'actividad_portales' });
      assert.equal(panel.status, 403);
    } finally {
      await hidden.close();
    }
    console.log('commercial promotion: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
