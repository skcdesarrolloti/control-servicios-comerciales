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
    assert.equal(await page.locator('[data-commercial-dropdown-menu="herramientas"] a').count(), 6);
    assert.match(await page.locator('[data-commercial-dropdown-menu="herramientas"]').textContent(), /Simulador de ingreso neto/);
    // Cada herramienta es su propia pestaña, como en el panel inmobiliario (sin subtab).
    assert.match(await page.locator('[data-commercial-dropdown-menu="herramientas"] a').first().getAttribute('href'), /[?&]tab=simulador_ingreso_neto$/);
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$0');
    // Tasa del crédito (referencia con tope en la usura) con la fecha de actualización.
    assert.match(await tool.locator('[data-pre-rate]').innerText(), /^\d+,\d{2} % mensual$/);
    assert.match(await tool.locator('.ctl-summary__rate dt small').innerText(), /actualizada el \d{2}\/\d{2}\/\d{4}/);

    // Propietario registrado: sus dos contratos vigentes llenan el simulador (canon, comisión del mandato y coberturas).
    await tool.locator('[data-pre-document]').fill('12');
    await tool.locator('[data-pre-search-button]').click();
    await page.waitForFunction(() => document.querySelector('[data-pre-status]').textContent.includes('mínimo 4'));
    await tool.locator('[data-pre-document]').fill('1.143.415.938');
    await tool.locator('[data-pre-document]').press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('[data-pre-sources] [data-pre-source]').length === 3);
    assert.equal(await tool.locator('[data-pre-owner="name"]').inputValue(), 'Ana Propietaria');
    assert.equal(await tool.locator('[data-pre-calc]').count(), 0);
    const contract = tool.locator('[data-pre-source]', { hasText: 'Contrato C-100' });
    assert.match(await tool.locator('[data-pre-status]').innerText(), /2 contratos entregados y 1 por entregar/);
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
    // C-100: 2.000.000 − 250.000 − garantía 50.000 − seguro 60.000 / 12 = 1.695.000. C-400 sin mandato: 95.200 + 20.000 =
    // 115.200 es menos del 15 %, así que descuenta el mínimo de 120.000 → 680.000. El Por entregar aún no suma.
    assert.equal(await tool.locator('[data-pre-net]').innerText(), '$2.375.000');
    assert.equal(await tool.locator('[data-pre-total]').innerText(), '$7.125.000');
    const noMandate = tool.locator('[data-pre-source]', { hasText: 'Contrato C-400' });
    assert.equal(await noMandate.locator('[data-pre-source-net]').innerText(), '$680.000');
    assert.match(await noMandate.locator('[data-pre-breakdown]').innerText(), /Ajuste al descuento mínimo\s+Los contratos descuentan como mínimo el 15 % del canon\s+\$4\.800/);
    // Por entregar: se pide canon + administración y se simula como inmueble nuevo (sin mandato ni mínimo del 15 %).
    const pending = tool.locator('[data-pre-source]', { hasText: 'Contrato C-200' });
    assert.equal(await pending.locator('[data-pre-field="canon"]').inputValue(), '');
    assert.match(await pending.locator('[data-pre-source-policy]').innerText(), /Contrato por entregar: escribe el canon y la administración/);
    await tool.locator('[data-pre-pdf]').click();
    assert.match(await tool.locator('[data-pre-error]').innerText(), /Escribe el canon del inmueble 3/);
    // 1.200.000 × 10 % + IVA = 142.800 y garantía 2,5 % = 30.000 → 1.000.000 − 172.800 = 827.200.
    await pending.locator('[data-pre-field="canon"]').fill('1000000');
    await pending.locator('[data-pre-sim="administracion"]').fill('200000');
    assert.equal(await pending.locator('[data-pre-source-net]').innerText(), '$827.200');
    assert.equal(await tool.locator('[data-pre-net]').innerText(), '$3.202.200');
    assert.match(await tool.locator('[data-pre-term]').innerText(), /^Hasta 1[12] cuotas$/);
    assert.equal(await tool.locator('[data-pre-gross]').innerText(), '$3.800.000');
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
    // La fila de la póliza dice que es un promedio mensual: $60.000 en 2 cuotas de $30.000 / 12.
    assert.match(await first.locator('[data-pre-breakdown]').innerText(), /Póliza de servicios públicos\s+Promedio mensual: \$60\.000 en 2 cuotas de \$30\.000, repartido en 12 meses\s+\$5\.000 \/ mes/);
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
    // Sin el seguro de servicios queda el aviso de la póliza (condición del crédito), ya no el de la duración.
    assert.doesNotMatch(await first.locator('[data-pre-source-warning]').innerText(), /mínimo 12 meses/);
    assert.match(await first.locator('[data-pre-source-warning]').innerText(), /Sin póliza de servicios públicos: este contrato no cumple/);
    assert.match(await first.locator('[data-pre-breakdown]').innerText(), /Póliza de servicios públicos\s+No la tiene\s+\$0/);
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

    // Generador UTM (traído de dashboard-marketing) desde el menú Herramientas.
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://127.0.0.1:8771' });
    await page.locator('[data-commercial-dropdown-trigger="herramientas"]').click();
    await page.locator('[data-commercial-dropdown-menu="herramientas"] a[href*="generador_utm"]').click();
    const utm = page.locator('[data-commercial-tool="generador_utm"]');
    await utm.waitFor();
    assert.equal(new URL(page.url()).search, '?tab=generador_utm');
    assert.equal(await page.locator('[data-commercial-dropdown-trigger="herramientas"]').evaluate(node => node.classList.contains('active')), true);
    // commercial-utm.js arma el enlace igual que UtmLinkBuilder (mismos casos que PHPUnit).
    const cases = JSON.parse(fs.readFileSync('tests/Fixtures/utm-cases.json', 'utf8'));
    const mirrored = await page.evaluate(({ slugCases, buildCases }) => ({
      slugs: slugCases.map(([input]) => window.SCMCommercialUtm.slug(input)),
      links: buildCases.map(item => { try { return window.SCMCommercialUtm.build(item.url, item.values, item.verbatim); } catch (error) { return { error: error.message }; } }),
    }), { slugCases: cases.slug, buildCases: cases.build });
    assert.deepEqual(mirrored.slugs, cases.slug.map(([, expected]) => expected));
    assert.deepEqual(mirrored.links, cases.build.map(item => item.error ? { error: item.error } : item.expected));
    // Un solo selector "Medio" con la lista única (Configuración › Medios): llena utm_source y utm_medium con el mismo slug.
    assert.equal(await utm.locator('[data-utm-field="source"]').count(), 0);
    assert.equal(await utm.locator('[data-utm-field="medium"]').count(), 0);
    assert.equal(await utm.locator('[data-utm-field="medio"] option[value="whatsapp"]').count(), 1);
    assert.equal(await utm.locator('[data-utm-field="medio"] option[value="por_ruta"]').innerText(), 'Por ruta');
    assert.equal(await utm.locator('[data-utm-field="medio"] option[value="__otro__"]').count(), 0);
    await page.waitForFunction(() => document.querySelector('[data-utm-tracker-status]').textContent === '2 campañas registradas.');
    assert.deepEqual(await utm.locator('[data-utm-tracker-campaigns] [data-utm-pick]').allInnerTexts(), ['Feria de vivienda', 'Revista Esencia']);
    // El tracker ya no agrega fuentes ni medios sueltos.
    assert.equal(await utm.locator('[data-utm-group="tracker"]').count(), 0);
    // Campos obligatorios y URL inválida.
    await utm.locator('[data-utm-generate]').click();
    assert.equal(await utm.locator('[data-utm-status]').innerText(), 'Escribe la URL de destino.');
    assert.equal(await utm.locator('[data-utm-status]').getAttribute('data-error'), 'true');
    await utm.locator('[data-utm-field="url"]').fill('sucasainmobiliaria.com.co/inmueble/?id=45&utm_source=viejo#galeria');
    await utm.locator('[data-utm-generate]').click();
    assert.equal(await utm.locator('[data-utm-status]').innerText(), 'Elige el medio del enlace.');
    await utm.locator('[data-utm-field="medio"]').selectOption('whatsapp');
    assert.equal(await utm.locator('[data-utm-preview="medio"]').innerText(), 'En el enlace: utm_source=whatsapp&utm_medium=whatsapp');
    await utm.locator('[data-utm-catalog-campaigns] [data-utm-pick]', { hasText: 'Revista Esencia junio' }).click();
    assert.equal(await utm.locator('[data-utm-field="campaign"]').inputValue(), 'esencia-inmobiliaria-edicion-junio-2026');
    assert.equal(await utm.locator('[data-utm-catalog-campaigns] [data-utm-pick]').getAttribute('aria-pressed'), 'true');
    await utm.locator('[data-utm-field="content"]').fill('Banner Superior');
    await utm.locator('[data-utm-generate]').click();
    const link = 'https://sucasainmobiliaria.com.co/inmueble/?id=45&utm_source=whatsapp&utm_medium=whatsapp&utm_campaign=esencia-inmobiliaria-edicion-junio-2026&utm_content=banner_superior#galeria';
    assert.equal(await utm.locator('[data-utm-result]').inputValue(), link);
    await page.waitForFunction(() => /copiado|Cópialo/.test(document.querySelector('[data-utm-status]').textContent));
    if ((await utm.locator('[data-utm-status]').innerText()) === 'Enlace copiado al portapapeles.') assert.equal(await page.evaluate(() => navigator.clipboard.readText()), link);
    assert.match(await utm.locator('[data-utm-parts]').innerText(), /utm_source\s+whatsapp\s+utm_medium\s+whatsapp/);
    assert.match(await utm.locator('[data-utm-parts]').innerText(), /utm_content\s+banner_superior/);
    assert.equal(await utm.locator('[data-utm-copy]').isDisabled(), false);
    await page.screenshot({ path: 'output/commercial-tools-utm.png', fullPage: true });
    // Cambiar el medio invalida el enlace.
    await utm.locator('[data-utm-field="medio"]').selectOption('chatgpt');
    assert.equal(await utm.locator('[data-utm-result]').inputValue(), '');
    assert.equal(await utm.locator('[data-utm-status]').innerText(), 'Cambiaste los datos: vuelve a generar el enlace.');
    // Una campaña del tracker va tal cual para sumar a lo ya registrado.
    await utm.locator('[data-utm-tracker-campaigns] [data-utm-pick]', { hasText: 'Revista Esencia' }).click();
    assert.equal(await utm.locator('[data-utm-preview="campaign"]').innerText(), 'En el enlace: utm_campaign=Revista Esencia (tal como lo registró el tracker)');
    assert.equal(await utm.locator('[data-utm-tracker-campaigns] [data-utm-pick]', { hasText: 'Revista Esencia' }).getAttribute('aria-pressed'), 'true');
    assert.equal(await utm.locator('[data-utm-catalog-campaigns] [data-utm-pick]').getAttribute('aria-pressed'), 'false');
    await utm.locator('[data-utm-generate]').click();
    assert.equal(await utm.locator('[data-utm-result]').inputValue(), 'https://sucasainmobiliaria.com.co/inmueble/?id=45&utm_source=chatgpt&utm_medium=chatgpt&utm_campaign=Revista%20Esencia&utm_content=banner_superior#galeria');
    // Escrita a mano y distinta del tracker, la campaña se normaliza; las reservadas del tracker se rechazan.
    await utm.locator('[data-utm-field="campaign"]').fill('revista esencia');
    assert.equal(await utm.locator('[data-utm-preview="campaign"]').innerText(), 'En el enlace: utm_campaign=revista_esencia');
    await utm.locator('[data-utm-field="campaign"]').fill('General');
    await utm.locator('[data-utm-generate]').click();
    assert.equal(await utm.locator('[data-utm-status]').innerText(), '"general" lo usa el tracker para las visitas sin campaña: elige otro nombre de campaña.');
    assert.equal(await utm.locator('[data-utm-result]').inputValue(), '');
    await utm.locator('[data-utm-reset]').click();
    await page.waitForFunction(() => document.querySelector('[data-utm-field="url"]').value === '' && document.querySelector('[data-utm-field="medio"]').value === '');
    assert.equal(await utm.locator('[data-utm-copy]').isDisabled(), true);

    // Un cargo con sus herramientas guardadas sin el generador no lo ve en el menú y el servidor rechaza sus datos.
    const restricted = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    try {
      const other = await restricted.newPage();
      await other.goto('http://127.0.0.1:8771/tests/Fixtures/commercial-tools.php?cargo=8');
      await other.locator('[data-commercial-tool="simulador_ingreso_neto"]').waitFor();
      assert.equal(await other.locator('[data-commercial-dropdown-menu="herramientas"] a').count(), 4);
      assert.equal(await other.locator('[data-commercial-dropdown-menu="herramientas"] a[href*="generador_utm"]').count(), 0);
      const denied = await other.evaluate(async () => {
        const body = new FormData();
        body.set('action', 'commercial_tools_utm_options');
        body.set('nonce', 'fixture');
        const response = await fetch('http://127.0.0.1:8771/tests/Fixtures/commercial-tools.php', { method: 'POST', body, credentials: 'same-origin' });
        return { status: response.status, json: await response.json() };
      });
      assert.deepEqual(denied, { status: 403, json: { success: false, data: { message: 'No tienes permiso para usar el generador UTM.' } } });
    } finally {
      await restricted.close();
    }

    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({ path: 'output/commercial-tools-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    assert.deepEqual(errors, []);
    console.log('commercial tools: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
