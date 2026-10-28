/* Run against the local-only fixture: php -S 127.0.0.1:8771 -t . */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  fs.rmSync('output/commercial-tools.sqlite', { force: true });
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1050 }, acceptDownloads: true });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:8771/tests/Fixtures/commercial-tools.php');
    const tool = page.locator('[data-commercial-tool="preaprobado"]');
    await tool.waitFor();
    assert.equal(await page.locator('[data-commercial-dropdown-menu="herramientas"] a').count(), 2);
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$0');

    // Propietario registrado: se cargan sus dos contratos vigentes con el ingreso del liquidador.
    await tool.locator('[data-pre-document]').fill('12');
    await tool.locator('[data-pre-search-button]').click();
    await page.waitForFunction(() => document.querySelector('[data-pre-status]').textContent.includes('mínimo 4'));
    await tool.locator('[data-pre-document]').fill('1.143.415.938');
    await tool.locator('[data-pre-document]').press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('[data-pre-sources] [data-pre-source]').length === 2);
    assert.equal(await tool.locator('[data-pre-owner="name"]').inputValue(), 'Ana Propietaria');
    // (2.000.000 − 250.000 − 40.000 − 180.000 / 12) + (1.000.000 − 150.000) = 2.545.000 × 3
    assert.equal(await tool.locator('[data-pre-net]').innerText(), '$2.545.000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$7.635.000');
    assert.match(await tool.locator('[data-pre-term]').innerText(), /^Hasta 1[12] cuotas$/);
    assert.equal(await tool.locator('[data-pre-source]').first().locator('[data-pre-field="seguro"]').inputValue(), '$180.000');
    await page.screenshot({ path: 'output/commercial-tools-preapproval.png', fullPage: true });

    // El consultor ajusta un valor: el seguro diferido con intereses baja el neto.
    await tool.locator('[data-pre-source]').first().locator('[data-pre-field="intereses"]').fill('12000');
    assert.equal(await tool.locator('[data-pre-source]').first().locator('[data-pre-field="intereses"]').inputValue(), '$12.000');
    assert.equal(await tool.locator('[data-pre-net]').innerText(), '$2.544.000');

    const [download] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), tool.locator('[data-pre-pdf]').click()]);
    assert.match(download.suggestedFilename(), /^cupo-preaprobado-1-143-415-938-\d{4}-\d{2}-\d{2}\.pdf$/);
    await download.saveAs('output/commercial-tools-preapproval.pdf');
    assert.equal(fs.readFileSync('output/commercial-tools-preapproval.pdf').subarray(0, 5).toString(), '%PDF-');

    // Propietario aún no registrado: canon neto escrito a mano × 3.
    await tool.locator('[data-pre-mode="manual"]').click();
    assert.equal(await tool.locator('[data-pre-search]').isVisible(), false);
    assert.equal(await tool.locator('[data-pre-source]').count(), 1);
    await tool.locator('[data-pre-owner="name"]').fill('Carlos Nuevo');
    await tool.locator('[data-pre-field="canon"]').fill('1500000');
    await tool.locator('[data-pre-field="comision"]').fill('150000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$4.050.000');
    assert.equal(await tool.locator('[data-pre-term]').innerText(), 'Según vigencia');
    await tool.locator('[data-pre-add]').click();
    await tool.locator('[data-pre-source]').nth(1).locator('[data-pre-field="canon"]').fill('500000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$5.550.000');
    await tool.locator('[data-pre-source]').nth(1).locator('[data-pre-remove]').click();
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$4.050.000');
    const [manualPdf] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), tool.locator('[data-pre-pdf]').click()]);
    assert.match(manualPdf.suggestedFilename(), /^cupo-preaprobado-carlos-nuevo-/);
    await page.screenshot({ path: 'output/commercial-tools-manual.png', fullPage: true });

    // Ganancia ocasional desde el menú Herramientas.
    await page.locator('[data-commercial-dropdown-trigger="herramientas"]').click();
    await page.locator('[data-commercial-dropdown-menu="herramientas"] a[href*="ganancia_ocasional"]').click();
    const gain = page.locator('[data-commercial-tool="ganancia_ocasional"]');
    await gain.waitFor();
    await gain.locator('[data-go-field="compra"]').fill('2018-01-15');
    await gain.locator('[data-go-field="venta"]').fill('2025-06-01');
    await gain.locator('[data-go-field="precioVenta"]').fill('500000000');
    await gain.locator('[data-go-field="costoAdq"]').fill('300000000');
    await gain.locator('[data-go-field="mejoras"]').fill('20000000');
    await gain.locator('[data-go-field="gastosVenta"]').fill('10000000');
    assert.equal(await gain.locator('[data-go-out="clasificacion"]').innerText(), 'Ganancia ocasional');
    assert.equal(await gain.locator('[data-go-out="utilidad"]').innerText(), '$170.000.000');
    assert.equal(await gain.locator('[data-go-out="impuesto"]').innerText(), '$25.500.000');
    // Vivienda con exención: 5.000 UVT cubren toda la utilidad.
    await gain.locator('.ctl-switch', { hasText: 'vivienda de habitación' }).click();
    await gain.locator('.ctl-switch', { hasText: 'art. 311-1' }).click();
    assert.equal(await gain.locator('[data-go-out="impuesto"]').innerText(), '$0');
    // Menos de 2 años: renta ordinaria con la tasa que escriba el consultor.
    await gain.locator('[data-go-field="compra"]').fill('2024-06-01');
    assert.equal(await gain.locator('[data-go-out="clasificacion"]').innerText(), 'Renta ordinaria');
    await gain.locator('[data-go-field="tasa"]').fill('20');
    assert.equal(await gain.locator('[data-go-out="impuesto"]').innerText(), '$34.000.000');
    await gain.locator('[data-go-field="tipo"]').selectOption('pj');
    assert.equal(await gain.locator('[data-go-out="impuesto"]').innerText(), '$59.500.000');
    await gain.locator('[data-go-guide]').click();
    assert.equal(await gain.locator('[data-go-dialog]').evaluate(node => node.open), true);
    await page.screenshot({ path: 'output/commercial-tools-gain-guide.png' });
    await gain.locator('[data-go-dialog] footer [data-go-close]').click();
    await page.screenshot({ path: 'output/commercial-tools-gain.png', fullPage: true });

    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({ path: 'output/commercial-tools-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    assert.deepEqual(errors, []);
    console.log('commercial tools: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
