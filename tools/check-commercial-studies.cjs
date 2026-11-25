// Estudios de aseguradora con base SQLite aislada (tests/Fixtures/commercial-studies.php). Servir el repositorio en
// 127.0.0.1:8776 (php -S 127.0.0.1:8776 -t .) y ejecutar:
//   node tools/check-commercial-studies.cjs RUTA_A_NODE_MODULES [CARPETA_DE_CAPTURAS]
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
const endpoint = 'http://127.0.0.1:8776/tests/Fixtures/commercial-studies.php';
const shots = process.argv[3] || '';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    // El visor y los enlaces abren public/estudio.php: aquí lo atiende el fixture sobre la misma base.
    await context.route('http://127.0.0.1:8776/public/estudio.php?**', route => route.continue({ url: endpoint + '?' + new URL(route.request().url()).searchParams.toString() }));
    await context.route('http://127.0.0.1:8776/public/file.php?**', route => route.continue({ url: endpoint + '?' + new URL(route.request().url()).searchParams.toString() }));
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://127.0.0.1:8776' });
    // La ficha del inmueble necesita todo el esquema de Inmuebles: aquí se responde fija y se revisa que pida el estudio.
    const propertyRequests = [];
    await context.route(endpoint, route => {
      const body = route.request().postData() || '';
      if (route.request().method() === 'POST' && body.includes('commercial_property_detail')) {
        propertyRequests.push(body);
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html: '<div data-fake-ficha>Ficha del inmueble</div>' } }) });
      }
      return route.continue();
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(endpoint + '?as=101');
    await page.locator('[data-cst-page]').waitFor();
    const menu = page.locator('[data-commercial-dropdown-menu="comerciales"]');
    assert.equal(await menu.locator('[data-commercial-tab="estudios"]:not([href*="portafolio"]) span:last-child').innerText(), 'Estudios', 'Falta Estudios en Registros');
    assert.equal(await menu.locator('[data-commercial-tab="estudios"][href*="portafolio=1"]').count(), 1, 'Falta Mis estudios en Mi portafolio');
    assert.ok(await page.locator('[data-commercial-dropdown-menu="mobile"] [data-commercial-tab="estudios"]').count() >= 2, 'Faltan las entradas móviles');
    assert.ok((await page.locator('[data-cst-list] .cqt-empty').innerText()).includes('No hay estudios'));

    // Popup de la tarea: el botón Estudio de aseguradora abre su ventana dentro del mismo popup.
    await page.evaluate(() => {
      const modal = document.getElementById('commercial-case-modal');
      modal.classList.add('open');
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-commercial-task-form', 'study');
      button.setAttribute('data-ticket-pk', '700');
      modal.querySelector('[data-commercial-case-content]').appendChild(button);
      button.click();
    });
    const task = page.locator('[data-cst-task]');
    await task.waitFor();
    assert.ok((await task.locator('.cqt-empty').innerText()).includes('todavía no tiene estudios'));
    const create = task.locator('[data-cst-form="create"]');
    assert.equal(await create.locator('[name="cliente"]').inputValue(), 'Laura Cliente', 'El cliente sale de la tarea');
    await create.locator('[name="aseguradora"]').selectOption('El libertador');
    await create.locator('[name="num_solicitud"]').fill('12548532');
    await create.locator('[name="valor"]').pressSequentially('1500000');
    assert.equal(await create.locator('[name="valor"]').inputValue(), '$ 1.500.000');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-nuevo.png') });
    await create.locator('[type="submit"]').click();
    await page.locator('[data-cst-task] .cst-flash').waitFor();
    assert.ok((await page.locator('.cst-flash').innerText()).includes('Estudio #1 creado'));
    const card = page.locator('[data-cst-card="1"]');
    assert.equal(await card.locator('.cst-state').innerText(), 'Esperando respuesta');

    // Responder desde Registros › Estudios: un popup encima del listado con el formulario; Cancelar vuelve al listado.
    await page.goto(endpoint + '?as=101');
    await page.locator('[data-cst-page] .cqt-table').waitFor();
    await page.locator('[data-cst-list] [data-cst-respond="1"]').click();
    await page.locator('#cst-dialog [data-cst-form="respond"]').waitFor();
    assert.equal(await page.locator('#commercial-case-modal.open').count(), 0, 'No abre la tarea');
    await page.locator('#cst-dialog [data-cst-dialog-close]').click();
    assert.equal(await page.locator('#cst-dialog').count(), 0);
    assert.equal(await page.locator('[data-cst-page] .cqt-table').count(), 1, 'Sigue en el listado');
    await page.locator('[data-cst-list] [data-cst-respond="1"]').click();
    const respond = page.locator('#cst-dialog [data-cst-form="respond"]');
    await respond.waitFor();
    if (shots) await page.screenshot({ path: path.join(shots, 'estudios-responder-popup.png') });
    assert.equal(await respond.locator('[data-cst-when="Aprobado"]').isVisible(), false, 'Los datos de aprobación salen solo al aprobar');
    await respond.locator('[name="estado"]').selectOption('Aplazado');
    assert.equal(await respond.locator('[data-cst-when="Aplazado"]').isVisible(), true);
    await respond.locator('[name="estado"]').selectOption('Aprobado');
    assert.equal(await respond.locator('[data-cst-when="Aplazado"]').isVisible(), false);
    await respond.locator('[type="submit"]').click();
    await respond.locator('[data-cst-error]:not([hidden])').waitFor();
    assert.ok((await respond.locator('[data-cst-error]').innerText()).includes('inmueble'), 'Sin inmueble no se aprueba');
    await respond.locator('[data-cst-property-search]').fill('Calle 900');
    await respond.locator('[data-cst-pick]').first().waitFor();
    const codes = await respond.locator('[data-cst-pick]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-cst-pick')).sort());
    assert.deepEqual(codes, ['9001', '9002', '9004'], 'Solo públicos o en borrador');
    await respond.locator('[data-cst-pick="9001"]').click();
    assert.ok((await respond.locator('[data-cst-property-picked]').innerText()).includes('#9001'));
    assert.deepEqual(await respond.locator('[data-cst-service]:checked').evaluateAll(nodes => nodes.map(node => node.value)), ['Agua', 'Energia'], 'Los servicios salen del inmueble');
    await respond.locator('[name="ubicacion_llaves"]').fill('Portería');
    await respond.locator('[name="persona_llaves"]').fill('Recepción');
    await respond.locator('[name="contacto_llaves"]').fill('3216999529');
    await respond.locator('[name="fecha_posible_entrega"]').fill('2026-12-15');
    // Sin los documentos no se envía: el navegador lo avisa antes de subir nada.
    await respond.locator('[type="submit"]').click();
    await page.waitForFunction(() => (document.querySelector('[data-cst-form="respond"] [data-cst-error]') || {}).innerText === 'Sube asegurable.');
    const pdf = name => ({ name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n') });
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
    await respond.locator('[name="docs_asegurable[]"]').setInputFiles([pdf('asegurable.pdf')]);
    await respond.locator('[name="docs_servicios[]"]').setInputFiles([pdf('agua.pdf'), { name: 'energia.png', mimeType: 'image/png', buffer: png }]);
    await respond.locator('[name="docs_arrendatario[]"]').setInputFiles([{ name: 'cedula.txt', mimeType: 'text/plain', buffer: Buffer.from('no') }]);
    await respond.locator('[type="submit"]').click();
    await page.waitForFunction(() => ((document.querySelector('[data-cst-form="respond"] [data-cst-error]') || {}).innerText || '').includes('debe ser PDF, JPG, PNG'));
    assert.equal(await page.locator('#cst-dialog').count(), 1, 'Un archivo inválido no aprueba nada: el popup sigue abierto');
    await respond.locator('[name="docs_arrendatario[]"]').setInputFiles([{ name: 'cedula.png', mimeType: 'image/png', buffer: png }]);
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-aprobar.png'), fullPage: false });
    await respond.locator('[type="submit"]').click();
    // Al guardar se cierra el popup y se recarga el listado, sin salir de él.
    await page.waitForFunction(() => !document.getElementById('cst-dialog'));
    await page.waitForFunction(() => ((document.querySelector('[data-cst-list] td[data-label="Estudio"]') || {}).innerText || '').includes('Aprobado'));
    assert.equal(await page.locator('[data-cst-list] [data-cst-respond]').count(), 0, 'Un estudio aprobado ya no se responde');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudios-respondido.png') });

    // En la tarea, el estudio aprobado con sus documentos.
    await page.evaluate(() => {
      const modal = document.getElementById('commercial-case-modal');
      modal.classList.add('open');
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-commercial-task-form', 'study');
      button.setAttribute('data-ticket-pk', '700');
      modal.querySelector('[data-commercial-case-content]').appendChild(button);
      button.click();
    });
    await page.locator('[data-cst-card="1"]').waitFor();
    assert.equal(await page.locator('[data-cst-card="1"] .cst-state').innerText(), 'Aprobado');
    assert.equal(await page.locator('[data-cst-card="1"] .cst-actions [data-cst-toggle="respond-1"]').count(), 0, 'Un estudio respondido no se vuelve a aprobar');
    assert.equal(await page.locator('[data-cst-card="1"] .cst-actions [data-cst-toggle="desist-1"]').count(), 1);
    assert.deepEqual(await page.locator('[data-cst-card="1"] .cst-docbtn small').allInnerTexts(), ['asegurable.pdf', 'agua.pdf', 'energia.png', 'cedula.png']);

    // Un documento se abre dentro del panel.
    await page.locator('[data-cst-card="1"] .cst-docbtn').first().click();
    assert.ok((await page.locator('#cst-viewer iframe').getAttribute('src')).includes('/file.php?n='));
    const served = await page.evaluate(async () => { const response = await fetch(document.querySelector('#cst-viewer iframe').src.replace('/public/file.php', '/tests/Fixtures/commercial-studies.php')); return [response.status, (await response.text()).slice(0, 5)]; });
    assert.deepEqual(served, [200, '%PDF-'], 'El asegurable quedó guardado');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-documento.png') });
    await page.keyboard.press('Escape');

    // El inmueble del estudio abre su ficha interna, con el estudio y la tarea para el permiso.
    await page.locator('[data-cst-card="1"] [data-commercial-property-detail="9001"]').click();
    await page.locator('#commercial-property-detail-modal [data-fake-ficha]').waitFor();
    assert.ok(/name="study_id"\r\n\r\n1\r\n/.test(propertyRequests[0]) && /name="ticket_pk"\r\n\r\n700\r\n/.test(propertyRequests[0]), 'La ficha se pide con el estudio y la tarea');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.getElementById('commercial-property-detail-modal').getAttribute('aria-hidden') === 'true');

    // Editar: quitar un recibo y agregar otro.
    await page.locator('[data-cst-card="1"] .cst-actions [data-cst-toggle="edit-1"]').click();
    const edit = page.locator('[data-cst-card="1"] [data-cst-form="edit"]');
    await edit.locator('[name="quitar_documentos[]"][value="2"]').check();
    await edit.locator('[name="docs_servicios[]"]').setInputFiles([pdf('gas.pdf')]);
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-editar-documentos.png') });
    await edit.locator('[type="submit"]').click();
    const edited = await page.waitForFunction(() => {
      const flash = document.querySelector('.cst-flash');
      if (flash && flash.innerText.includes('documento')) return flash.innerText;
      const error = document.querySelector('[data-cst-card="1"] [data-cst-form="edit"] [data-cst-error]');
      return error && !error.hidden ? 'Error: ' + error.innerText : false;
    });
    assert.ok((await edited.jsonValue()).includes('1 documento nuevo y 1 documento quitado'), 'Editar documentos: ' + await edited.jsonValue());
    assert.deepEqual(await page.locator('[data-cst-card="1"] .cst-docbtn small').allInnerTexts(), ['asegurable.pdf', 'agua.pdf', 'cedula.png', 'gas.pdf']);

    // Visor: la vista interna del estudio dentro del panel.
    await page.locator('[data-cst-card="1"] [data-cst-view="1"]').click();
    const frame = page.frameLocator('#cst-viewer iframe');
    await frame.locator('.ps-internal').waitFor();
    assert.ok((await frame.locator('.ps-internal').innerText()).includes('Portería'));
    assert.ok((await frame.locator('.ps-docs').innerText()).includes('gas.pdf'), 'La vista interna lista los documentos');
    const clientLink = await page.locator('#cst-viewer [data-cqt-copy]').getAttribute('data-cqt-copy');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-visor.png') });
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#cst-viewer').count(), 0);

    // Vista del cliente con su enlace firmado: sin datos internos.
    const client = await context.newPage();
    await client.goto(clientLink);
    await client.locator('.ps-hero').waitFor();
    assert.equal(await client.locator('.ps-internal').count(), 0, 'El cliente no ve los datos internos');
    assert.ok((await client.locator('.ps-badge').innerText()).includes('Aprobado'));
    if (shots) await client.screenshot({ path: path.join(shots, 'estudio-cliente.png'), fullPage: true });
    await client.setViewportSize({ width: 390, height: 844 });
    if (shots) await client.screenshot({ path: path.join(shots, 'estudio-cliente-movil.png'), fullPage: true });
    await client.close();

    // Caso de entrega del panel inmobiliario: «Ver documentos» abre anexos_entrega en un popup y los documentos se ven dentro.
    const delivery = await page.evaluate(async url => { const body = new FormData(); body.append('action', 'fixture_delivery_docs'); return (await (await fetch(url, { method: 'POST', body })).json()).data.url; }, endpoint);
    assert.match(delivery, /\/public\/estudio\.php\?d=1\.[a-f0-9]{64}$/, 'El caso de entrega lleva el enlace de los documentos');
    const docs = await context.newPage();
    // Mismo dominio que el panel inmobiliario en producción (la página solo se deja abrir desde el mismo origen).
    await docs.route('http://127.0.0.1:8776/caso-inmobiliario.html', route => route.fulfill({ contentType: 'text/html', body: '<iframe style="width:1100px;height:900px;border:0" src="' + delivery + '&ver=servicios"></iframe>' }));
    await docs.goto('http://127.0.0.1:8776/caso-inmobiliario.html');
    const docsFrame = docs.frameLocator('iframe');
    await docsFrame.locator('.ps-viewer').waitFor();
    assert.equal(await docsFrame.locator('.ps-docs li.is-current [data-ps-doc]').getAttribute('data-ps-doc'), 'servicios', '«Ver asegurable»/ver= elige el documento');
    const viewerFrame = () => docs.frames().find(frame => frame.name() === 'ps-visor');
    const firstUrl = viewerFrame().url();
    const asegurableHref = await docsFrame.locator('[data-ps-doc="asegurable"]').getAttribute('href');
    await docsFrame.locator('[data-ps-doc="asegurable"]').click();
    await docsFrame.locator('.ps-docs li.is-current [data-ps-doc="asegurable"]').waitFor();
    for (let i = 0; i < 50 && viewerFrame().url() !== asegurableHref; i++) await docs.waitForTimeout(100);
    assert.notEqual(firstUrl, asegurableHref);
    assert.equal(viewerFrame().url(), asegurableHref, 'El documento se abre en el visor de la misma página');
    assert.equal(docs.url(), 'http://127.0.0.1:8776/caso-inmobiliario.html', 'No sale del caso');
    if (shots) await docs.screenshot({ path: path.join(shots, 'estudio-documentos-caso.png') });
    await docs.close();

    // Listado: Desistir negocio en un popup.
    await page.goto(endpoint + '?as=101');
    await page.locator('[data-cst-page] .cqt-table').waitFor();
    assert.equal(await page.locator('[data-cst-state="Aprobado"] strong').innerText(), '1');
    assert.equal(await page.locator('[data-cst-list] [data-cst-respond]').count(), 0, 'Un estudio aprobado ya no se responde');
    const propertyCell = page.locator('[data-cst-list] td[data-label="Inmueble"]').first();
    assert.ok((await propertyCell.innerText()).includes('Apartamento · Calle 9001 apto 1'), 'Columna Inmueble');
    await propertyCell.locator('[data-commercial-property-detail="9001"]').click();
    await page.locator('#commercial-property-detail-modal [data-fake-ficha]').waitFor();
    assert.ok(/name="study_id"\r\n\r\n1\r\n/.test(propertyRequests[1]), 'Desde el listado también va el estudio');
    await page.keyboard.press('Escape');
    await page.locator('[data-cst-list] [data-cst-copy]').first().click();
    assert.match(await page.evaluate(() => navigator.clipboard.readText()), /\/public\/estudio\.php\?e=1\.\d{10}\.[a-f0-9]{64}$/, 'El botón Enlace copia el enlace firmado del cliente');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudios-listado-inmueble.png') });
    await page.locator('[data-cst-desist="1"]').click();
    const dialog = page.locator('#cst-dialog');
    await dialog.locator('[name="motivo"]').waitFor();
    await dialog.locator('[name="motivo"]').selectOption('Arrendatario desistio');
    await dialog.locator('[type="submit"]').click();
    await dialog.locator('[data-cst-error]:not([hidden])').waitFor();
    await dialog.locator('[name="confirmar"]').check();
    if (shots) await page.screenshot({ path: path.join(shots, 'estudio-desistir.png') });
    await dialog.locator('[type="submit"]').click();
    await page.waitForFunction(() => !document.getElementById('cst-dialog'));
    await page.waitForFunction(() => (document.querySelector('[data-cst-list] .cst-state') || {}).innerText === 'Negocio desistido');
    await page.locator('[data-cst-state="Negocio desistido"]').click();
    await page.waitForFunction(() => document.querySelector('[data-cst-state="Negocio desistido"]').getAttribute('aria-pressed') === 'true');
    assert.equal(await page.locator('[data-cst-list] tbody tr').count(), 1);
    assert.equal(await page.locator('[data-cst-desist]').count(), 0, 'Un negocio desistido no se vuelve a desistir');
    if (shots) await page.screenshot({ path: path.join(shots, 'estudios-listado.png') });

    const notices = await page.evaluate(async () => {
      const body = new FormData();
      body.set('action', 'fixture_notices');
      const response = await fetch(location.href.split('?')[0], { method: 'POST', body });
      return (await response.json()).data.lines;
    });
    assert.ok(notices.some(line => line.startsWith('whatsapp|+573001234567|scm_estudio_aseguradora_enviado_v1')), 'WhatsApp al crear');
    assert.ok(notices.some(line => line.startsWith('whatsapp|+573001234567|scm_estudio_aseguradora_respuesta_v1')), 'WhatsApp con la respuesta');
    assert.ok(notices.some(line => line.includes('404@example.test') && line.includes('caso de entrega')), 'Aviso al responsable de las entregas');
    assert.ok(notices.some(line => line.includes('Negocio desistido')), 'Aviso del negocio desistido');
    assert.deepEqual(errors, [], 'Errores de JavaScript: ' + errors.join(' | '));
    console.log('Estudios de aseguradora: OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
