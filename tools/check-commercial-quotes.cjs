// Cotizaciones de inmuebles con base SQLite aislada (tests/Fixtures/commercial-quotes.php). Servir el repositorio en
// 127.0.0.1:8775 (php -S 127.0.0.1:8775 -t .) y ejecutar:
//   node tools/check-commercial-quotes.cjs RUTA_A_NODE_MODULES [CARPETA_DE_CAPTURAS]
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
const endpoint = 'http://127.0.0.1:8775/tests/Fixtures/commercial-quotes.php';
const shots = process.argv[3] || '';

// Fotos de prueba: los guid del fixture apuntan a img.example.test.
const photo = (label, color) => `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="${color}"/><stop offset="1" stop-color="#061d49"/></linearGradient></defs><rect width="1200" height="800" fill="url(#g)"/><text x="60" y="740" font-family="Arial" font-size="64" fill="#fff">${label}</text></svg>`;

async function routePhotos(context) {
  await context.route('https://img.example.test/**', route => route.fulfill({ status: 200, contentType: 'image/svg+xml', body: photo(route.request().url().split('/').pop(), route.request().url().includes('9001') ? '#f59120' : '#1e3c76') }));
  // El visor abre el enlace público: aquí lo atiende el fixture sobre la misma base.
  await context.route('http://127.0.0.1:8775/public/cotizacion.php?**', route => route.continue({ url: endpoint + '?' + new URL(route.request().url()).searchParams.toString() }));
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await routePhotos(context);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(endpoint + '?as=101');
    await page.locator('[data-cqt-page]').waitFor();
    assert.equal(await page.locator('.cqt-head h1').innerText(), 'Cotizaciones de inmuebles');
    assert.equal(await page.locator('[data-commercial-dropdown-menu="comerciales"] [data-commercial-tab="cotizaciones"][href*="tab=cotizaciones"]:not([href*="portafolio"])').count(), 1, 'Falta la entrada del menú');
    // Cotizaciones va bajo el subtítulo Registros, aparte de Registrar.
    assert.equal(await page.locator('[data-commercial-dropdown-menu="comerciales"]').getByText('Registros', { exact: true }).count(), 1, 'Falta el subtítulo Registros');    assert.equal(await page.locator('[data-commercial-dropdown-menu="comerciales"] [data-commercial-tab="cotizaciones"]:not([href*="portafolio"]) span:last-child').innerText(), 'Cotizaciones');
    assert.equal(await page.locator('[data-commercial-dropdown-menu="comerciales"] [data-commercial-tab="cotizaciones"][href*="portafolio=1"]').count(), 1, 'Falta Mis cotizaciones en Mi portafolio');
    assert.ok(await page.locator('[data-commercial-dropdown-menu="mobile"] [data-commercial-tab="cotizaciones"]').count() >= 2, 'Faltan las entradas móviles');
    assert.ok((await page.locator('[data-cqt-list] .cqt-empty').innerText()).includes('No hay cotizaciones'));

    // Popup de la tarea: el botón Cotizar inmuebles abre el formulario dentro del mismo popup.
    await page.evaluate(() => {
      const modal = document.getElementById('commercial-case-modal');
      modal.classList.add('open');
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-commercial-task-form', 'quote');
      button.setAttribute('data-ticket-pk', '700');
      modal.querySelector('[data-commercial-case-content]').appendChild(button);
      button.click();
    });
    const task = page.locator('[data-cqt-task]');
    await task.waitFor();
    await task.locator('[data-cqt-prop]').first().waitFor();
    assert.equal(await task.locator('[name="tipo"]').inputValue(), 'Arriendo', 'El tipo sale del motivo de la tarea');
    assert.equal(await task.locator('[name="cliente"]').inputValue(), 'Laura Cliente');
    const codes = await task.locator('[data-cqt-prop]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-code')).sort());
    assert.deepEqual(codes, ['501', '502', '503'], 'Solo públicos en arriendo');
    const expired = task.locator('[data-cqt-prop][data-code="503"]');
    assert.equal(await expired.locator('[data-cqt-toggle]').count(), 0, 'Un vencido no se puede agregar');
    assert.ok((await expired.innerText()).includes('Ir a actualizar'));
    assert.equal(await task.locator('[data-cqt-send]').isDisabled(), true, 'Sin inmuebles no se envía');

    await task.locator('[data-cqt-prop][data-code="501"] [data-cqt-toggle]').click();
    await task.locator('[data-cqt-prop][data-code="502"] [data-cqt-toggle]').click();
    assert.equal(await task.locator('[data-cqt-count]').innerText(), '2');
    assert.equal(await task.locator('[data-cqt-selected] li').count(), 2);
    await task.locator('[data-cqt-selected] [data-cqt-remove="502"]').click();
    assert.equal(await task.locator('[data-cqt-prop][data-code="502"] [data-cqt-toggle]').getAttribute('aria-pressed'), 'false', 'Quitar desde la bandeja desmarca la tarjeta');
    await task.locator('[data-cqt-prop][data-code="502"] [data-cqt-toggle]').click();

    // Filtros: precio máximo con formato de dinero.
    await task.locator('[name="precio_max"]').pressSequentially('3000000');
    assert.equal(await task.locator('[name="precio_max"]').inputValue(), '$ 3.000.000');
    await task.locator('[data-cqt-search] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-prop]').length === 1);
    assert.equal(await task.locator('[data-cqt-count]').innerText(), '2', 'Filtrar no borra lo elegido');
    await task.locator('[data-cqt-search] [type="reset"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-prop]').length === 3);

    // Principales a la vista; el resto, en "Más filtros", que cuenta los que están puestos.
    const moreToggle = task.locator('[data-cqt-more-toggle]');
    assert.equal(await task.locator('[name="habitaciones"]').isVisible(), true, 'Habitaciones es principal');
    assert.equal(await task.locator('[name="amoblado"]').isVisible(), false, 'Amoblado va en Más filtros');
    await moreToggle.click();
    assert.equal(await moreToggle.getAttribute('aria-expanded'), 'true');
    await task.locator('[name="amoblado"]').selectOption('Si');
    await page.waitForFunction(() => document.querySelector('[data-cqt-more-count]').textContent === '1');
    assert.equal(await task.locator('[data-cqt-more-count]').isVisible(), true);
    await task.locator('[name="amoblado"]').selectOption('');
    await page.waitForFunction(() => document.querySelector('[data-cqt-more-count]').hidden);

    // Arriendo o venta: la permuta solo aparece cuando el negocio incluye venta.
    const swap = task.locator('[name="acepta_permuta"]');
    assert.equal(await swap.isVisible(), false, 'En una cotización de arriendo no hay permuta');
    await task.locator('[data-cqt-business]').selectOption('Venta');
    await page.waitForFunction(() => [...document.querySelectorAll('[data-cqt-prop]')].map(card => card.dataset.code).join() === '505');
    assert.equal(await swap.isVisible(), true, 'En venta se filtra por permuta');
    assert.equal(await task.locator('[name="tipo_permuta"]').isEnabled(), true);
    await task.locator('[data-cqt-business]').selectOption('Arriendo');
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-prop]').length === 3);
    assert.equal(await swap.isVisible(), false);
    await task.locator('[data-cqt-search] [type="reset"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-prop]').length === 3);
    if (shots) await page.screenshot({ path: path.join(shots, 'cotizacion-formulario.png') });

    await task.locator('[name="observaciones"]').fill('Podemos visitarlos el sábado en la mañana.');
    await task.locator('[data-cqt-send]').click();
    await page.locator('[data-cqt-done]').waitFor();
    assert.ok((await page.locator('[data-cqt-done] h1').innerText()).startsWith('Cotización #'));
    // En SQLite la cola compartida no existe: el envío real lo cubre PHPUnit. Aquí solo se muestra el estado de cada canal.
    assert.equal(await page.locator('[data-cqt-done] .cqt-channels li').count(), 2, 'Estado del correo y del WhatsApp');
    // El PDF se genera con el Chrome o Edge local al crearla y se descarga con el mismo enlace firmado.
    const pdfHref = await page.locator('[data-cqt-done] a[href*="formato=pdf"]').getAttribute('href');
    const pdf = await page.request.get(endpoint + '?' + new URL(pdfHref).searchParams.toString(), { timeout: 90000 });
    assert.equal(pdf.status(), 200, 'El PDF se descarga');
    assert.equal(pdf.headers()['content-type'], 'application/pdf');
    assert.ok((await pdf.body()).subarray(0, 5).toString() === '%PDF-', 'Es un PDF');
    // Con el botón, el loader de la marca acompaña la espera y luego baja el archivo.
    await page.route('**/cotizacion.php?*formato=pdf*', async route => { await new Promise(resolve => setTimeout(resolve, 400)); await route.fallback(); });
    const download = page.waitForEvent('download');
    await page.locator('[data-cqt-done] a[href*="formato=pdf"]').click();
    await page.locator('.scm-loader:not([hidden])').waitFor();
    assert.ok((await page.locator('.scm-loader-title').innerText()).startsWith('Generando el PDF'), 'Loader mientras se genera');
    assert.ok((await download).suggestedFilename().endsWith('.pdf'));
    await page.locator('.scm-loader').waitFor({ state: 'hidden' });
    await page.unroute('**/cotizacion.php?*formato=pdf*');

    // Visor con la vista pública dentro del panel.
    await page.locator('[data-cqt-done] [data-cqt-view]').click();
    const frame = page.frameLocator('#cqt-viewer iframe');
    await frame.locator('#comparacion').waitFor();
    assert.equal(await frame.locator('.pq-card').count(), 2);
    assert.ok((await frame.locator('.pq-adv').first().innerText()).includes('El canon más bajo'));
    assert.ok((await frame.locator('.pq-block').filter({ hasText: 'Observaciones de tu consultor/a' }).innerText()).includes('sábado'));
    assert.equal(await frame.locator('.pq-advisor a[href*="formato=pdf"]').count(), 1, 'El cliente puede descargar el PDF');
    assert.equal(await page.locator('#cqt-viewer a[href*="formato=pdf"]').count(), 1, 'El visor del panel ofrece el PDF');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#cqt-viewer').count(), 0, 'Escape cierra el visor');

    // Listado: la cotización aparece en Cotizaciones y en Mis cotizaciones.
    await page.goto(endpoint + '?as=101');
    assert.equal(await page.locator('[data-cqt-list] tbody tr').count(), 1);
    await page.goto(endpoint + '?portafolio=1');
    assert.equal(await page.locator('.cqt-head h1').innerText(), 'Mis cotizaciones');
    assert.equal(await page.locator('[data-cqt-list] tbody tr').count(), 0, 'Mis cotizaciones del administrador: solo las suyas');
    await page.goto(endpoint);
    assert.equal(await page.locator('[data-cqt-list] tbody tr').count(), 1, 'El administrador ve las del equipo');
    await page.locator('[data-cqt-list-filters] [name="cq_q"]').fill('Nadie');
    await page.locator('[data-cqt-list-filters] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-list] tbody tr').length === 0);
    assert.ok(page.url().includes('cq_q=Nadie'), 'Los filtros quedan en la dirección');
    await page.locator('[data-cqt-list-clear]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-cqt-list] tbody tr').length === 1);
    await page.locator('[data-cqt-list] [data-cqt-view]').click();
    await page.frameLocator('#cqt-viewer iframe').locator('.pq-card').first().waitFor();
    const publicUrl = await page.locator('#cqt-viewer iframe').getAttribute('src');

    // Vista pública como la abre el cliente, en celular y en escritorio.
    for (const [name, viewport] of [['movil', { width: 390, height: 844 }], ['escritorio', { width: 1280, height: 900 }]]) {
      const client = await browser.newContext({ viewport });
      // Sin sesión del panel; la cookie solo indica al fixture qué base SQLite usar.
      await client.addCookies(await context.cookies());
      await routePhotos(client);
      const view = await client.newPage();
      view.on('pageerror', error => errors.push(error.message));
      await view.goto(publicUrl);
      await view.locator('.pq-card').first().waitFor();
      const overflow = await view.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert.ok(overflow <= 0, `La vista pública no debe tener scroll horizontal (${name}: ${overflow}px)`);
      if (shots) await view.screenshot({ path: path.join(shots, `cotizacion-publica-${name}.png`), fullPage: true });
      await client.close();
    }
    const invalid = await context.newPage();
    const response = await invalid.goto(endpoint + '?c=1.1999999999.' + 'a'.repeat(64));
    assert.equal(response.status(), 403);
    assert.ok((await invalid.locator('body').innerText()).includes('no es válido'));

    assert.deepEqual(errors, [], 'Errores de JavaScript: ' + errors.join(' | '));
    console.log('Cotizaciones: OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
