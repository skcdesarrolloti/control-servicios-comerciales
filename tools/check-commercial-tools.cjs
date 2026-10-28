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
    const tool = page.locator('[data-commercial-tool="simulador_ingreso_neto"]');
    await tool.waitFor();
    assert.equal(await page.locator('[data-commercial-dropdown-menu="herramientas"] a').count(), 2);
    assert.match(await page.locator('[data-commercial-dropdown-menu="herramientas"]').textContent(), /Simulador de ingreso neto/);
    // Cada herramienta es su propia pestaña, como en el panel inmobiliario (sin subtab).
    assert.match(await page.locator('[data-commercial-dropdown-menu="herramientas"] a').first().getAttribute('href'), /[?&]tab=simulador_ingreso_neto$/);
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$0');

    // Propietario registrado: sus dos contratos vigentes llenan el simulador (canon, comisión del mandato y coberturas).
    await tool.locator('[data-pre-document]').fill('12');
    await tool.locator('[data-pre-search-button]').click();
    await page.waitForFunction(() => document.querySelector('[data-pre-status]').textContent.includes('mínimo 4'));
    await tool.locator('[data-pre-document]').fill('1.143.415.938');
    await tool.locator('[data-pre-document]').press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('[data-pre-sources] [data-pre-source]').length === 2);
    assert.equal(await tool.locator('[data-pre-owner="name"]').inputValue(), 'Ana Propietaria');
    assert.equal(await tool.locator('[data-pre-calc]').count(), 0);
    const contract = tool.locator('[data-pre-source]').first();
    // Mandato: comisión $250.000 con IVA sobre $2.000.000 → tasa 10,5042 %; tiene garantía y seguro de servicios.
    assert.equal(await contract.locator('[data-pre-sim="tasa"]').inputValue(), '10,5042');
    assert.equal(await contract.locator('[data-pre-sim="garantia"]').isChecked(), true);
    assert.equal(await contract.locator('[data-pre-sim="servicios"]').isChecked(), true);
    // La póliza de servicios del mandato (4 cuotas desde el inicio, hace 65 días) aún no está pagada: llega con su
    // valor asegurado y sus cuotas.
    assert.equal(await contract.locator('[data-pre-sim="valor"]').inputValue(), '$3.000.000');
    assert.equal(await contract.locator('[data-pre-sim="cuotas"]').inputValue(), '4');
    assert.match(await contract.locator('[data-pre-source-policy]').innerText(), /lleva 2 y le faltan 2/);
    assert.equal(await contract.locator('[data-pre-source-warning]').isVisible(), false);
    // (2.000.000 − 250.000 − garantía 50.000 − seguro 60.000 / 12) + sin mandato: 119.000 + 25.000 = 144.000 es menos
    // del 15 %, así que descuenta el mínimo de 150.000 → 1.695.000 + 850.000 = 2.545.000 × 3
    assert.equal(await tool.locator('[data-pre-net]').innerText(), '$2.545.000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$7.635.000');
    const noMandate = tool.locator('[data-pre-source]').nth(1);
    assert.equal(await noMandate.locator('[data-pre-source-net]').innerText(), '$850.000');
    assert.match(await noMandate.locator('[data-pre-breakdown]').innerText(), /Ajuste al descuento mínimo\s+Los contratos descuentan como mínimo el 15 % del canon\s+\$6\.000/);
    assert.match(await tool.locator('[data-pre-term]').innerText(), /^Hasta 1[12] cuotas$/);
    assert.equal(await tool.locator('[data-pre-gross]').innerText(), '$3.000.000');
    await page.screenshot({ path: 'output/commercial-tools-preapproval.png', fullPage: true });

    // Primero la vista previa del PDF (popup interno) y desde ahí la descarga.
    await tool.locator('[data-pre-pdf]').click();
    const viewer = tool.locator('[data-pre-viewer]');
    await page.waitForFunction(() => document.querySelector('[data-pre-viewer]').open, null, { timeout: 30000 });
    assert.match(await viewer.locator('[data-pre-viewer-frame]').getAttribute('src'), /^blob:/);
    await page.waitForTimeout(800);
    await page.screenshot({ path: 'output/commercial-tools-pdf-preview.png' });
    const isPdf = item => /^simulacion-ingreso-neto-/.test(item.suggestedFilename());
    const [download] = await Promise.all([page.waitForEvent('download', { predicate: isPdf, timeout: 30000 }), viewer.locator('[data-pre-download]').click()]);
    await viewer.locator('footer [data-pre-viewer-close]').click();
    assert.equal(await viewer.evaluate(node => node.open), false);
    assert.match(download.suggestedFilename(), /^simulacion-ingreso-neto-1-143-415-938-\d{4}-\d{2}-\d{2}\.pdf$/);
    await download.saveAs('output/commercial-tools-preapproval.pdf');
    assert.equal(fs.readFileSync('output/commercial-tools-preapproval.pdf').subarray(0, 5).toString(), '%PDF-');

    // Propietario aún no registrado: el inmueble se simula con las tarifas de confi_sistema (plugin de la calculadora).
    await tool.locator('[data-pre-mode="manual"]').click();
    assert.equal(await tool.locator('[data-pre-search]').isVisible(), false);
    assert.equal(await tool.locator('[data-pre-source]').count(), 1);
    const first = tool.locator('[data-pre-source]').first();
    await tool.locator('[data-pre-owner="name"]').fill('Carlos Nuevo');
    await tool.locator('[data-pre-owner="document"]').fill('80123456');
    await first.locator('[data-pre-field="canon"]').fill('1500000');
    // Comisión 10 % + IVA = 178.500 y garantía El Libertador 2,5 % = 37.500.
    assert.equal(await first.locator('[data-pre-source-net]').innerText(), '$1.284.000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$3.852.000');
    assert.match(await first.locator('[data-pre-sim-rates]').innerText(), /El Libertador: garantía 2,5 %/);
    // Seguro de servicios en 2 cuotas: el neto cambia entre los meses con y sin cuota.
    await first.locator('.ctl-switch', { hasText: 'seguro de servicios' }).click();
    assert.equal(await first.locator('[data-pre-source-warning]').isVisible(), true);
    await first.locator('[data-pre-sim="valor"]').fill('3000000');
    await first.locator('[data-pre-sim="cuotas"]').selectOption('2');
    assert.equal(await first.locator('[data-pre-source-net]').innerText(), '$1.279.000');
    assert.match(await first.locator('[data-pre-source-detail]').innerText(), /\$1\.254\.000 en los 2 meses con cuota del seguro y \$1\.284\.000 en los demás/);
    // Berkley: 2 % del canon anual + expedición, sin aseguradora ni valor asegurado de servicios.
    await first.locator('.ctl-switch', { hasText: 'seguro de servicios' }).click();
    await first.locator('[data-pre-sim="modalidad"]').selectOption('berkley');
    assert.equal(await first.locator('[data-pre-sim="aseguradora"]').isVisible(), false);
    assert.equal(await first.locator('[data-pre-sim="cuotas"]').isVisible(), true);
    assert.equal(await first.locator('[data-pre-source-net]').innerText(), '$1.260.250');
    await first.locator('[data-pre-sim="modalidad"]').selectOption('defecto');
    await page.screenshot({ path: 'output/commercial-tools-simulation.png', fullPage: true });
    // Duración del contrato en meses (12 por defecto) menos 2 de margen; con menos de 12 meses se avisa.
    assert.equal(await tool.locator('[data-pre-term]').innerText(), 'Hasta 10 cuotas');
    await first.locator('[data-pre-field="duracion"]').selectOption('6');
    assert.match(await first.locator('[data-pre-source-warning]').innerText(), /mínimo 12 meses/);
    assert.equal(await tool.locator('[data-pre-term]').innerText(), 'Hasta 4 cuotas');
    await first.locator('[data-pre-field="duracion"]').selectOption('24');
    assert.equal(await first.locator('[data-pre-source-warning]').isVisible(), false);
    assert.equal(await tool.locator('[data-pre-term]').innerText(), 'Hasta 22 cuotas');
    // La administración suma al canon total: comisión 10 % + IVA de 1.800.000 = 214.200 y garantía 2,5 % = 45.000.
    await first.locator('[data-pre-sim="administracion"]').fill('300000');
    assert.equal(await first.locator('[data-pre-source-net]').innerText(), '$1.240.800');
    await first.locator('[data-pre-sim="administracion"]').fill('');
    // Otro inmueble: $500.000 − 59.500 − 12.500 = 428.000 / mes.
    await tool.locator('[data-pre-add]').click();
    await tool.locator('[data-pre-source]').nth(1).locator('[data-pre-field="canon"]').fill('500000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$5.136.000');
    await tool.locator('[data-pre-source]').nth(1).locator('[data-pre-remove]').click();
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$3.852.000');
    // Lo que necesita el propietario: primero se pregunta; solo si dice que sí se diligencia.
    assert.equal(await tool.locator('[data-pre-needs-panel]').isVisible(), false);
    await tool.locator('[data-pre-needs="si"]').click();
    assert.equal(await tool.locator('[data-pre-needs-panel]').isVisible(), true);
    await tool.locator('[data-pre-use="reparaciones"]').fill('1000000');
    await tool.locator('[data-pre-use="servicios"]').fill('400000');
    assert.equal(await tool.locator('[data-pre-uses-summary]').getAttribute('data-state'), 'ok');
    assert.match(await tool.locator('[data-pre-uses-summary]').innerText(), /cubre los \$1\.400\.000 que necesita y le quedan \$2\.452\.000/);
    await tool.locator('[data-pre-notes]').fill('Se descuenta de su canon.');
    await tool.locator('[data-pre-copy]').click();
    await page.waitForFunction(() => document.querySelector('[data-pre-copy-label]').textContent === 'Mensaje copiado');
    // Celular y correo son obligatorios para el PDF.
    await tool.locator('[data-pre-pdf]').click();
    assert.match(await tool.locator('[data-pre-error]').innerText(), /el celular/);
    await tool.locator('[data-pre-owner="phone"]').fill('3001234567');
    await tool.locator('[data-pre-owner="email"]').fill('carlos@');
    await tool.locator('[data-pre-pdf]').click();
    assert.match(await tool.locator('[data-pre-error]').innerText(), /correo/);
    await tool.locator('[data-pre-owner="email"]').fill('carlos@example.test');
    await tool.locator('[data-pre-pdf]').click();
    await page.waitForFunction(() => document.querySelector('[data-pre-viewer]').open, null, { timeout: 30000 });
    const [manualPdf] = await Promise.all([page.waitForEvent('download', { predicate: isPdf, timeout: 30000 }), viewer.locator('[data-pre-download]').click()]);
    await viewer.locator('[data-pre-viewer-close]').first().click();
    assert.match(manualPdf.suggestedFilename(), /^simulacion-ingreso-neto-80123456-/);
    await manualPdf.saveAs('output/commercial-tools-simulation.pdf');
    await page.screenshot({ path: 'output/commercial-tools-manual.png', fullPage: true });

    // Ganancia ocasional desde el menú Herramientas.
    await page.locator('[data-commercial-dropdown-trigger="herramientas"]').click();
    await page.locator('[data-commercial-dropdown-menu="herramientas"] a[href*="ganancia_ocasional"]').click();
    const gain = page.locator('[data-commercial-tool="ganancia_ocasional"]');
    await gain.waitFor();
    assert.equal(new URL(page.url()).search, '?tab=ganancia_ocasional');
    assert.equal(await page.locator('[data-commercial-dropdown-trigger="herramientas"]').evaluate(node => node.classList.contains('active')), true);
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
