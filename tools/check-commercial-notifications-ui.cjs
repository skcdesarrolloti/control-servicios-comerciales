// Ejecutar después de preview-commercial-notifications.php y servir el repo en 127.0.0.1:8769.
const path = require('path');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1050 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('http://127.0.0.1:8769/output/notifications-preview.html');
  await page.locator('[data-notif-recipient]').first().waitFor();
  if ((await page.locator('[data-notif-type]').count()) !== 6 || (await page.locator('[data-notif-import]').count()) !== 0) throw new Error('Categorías o selección SIMI incorrectas');
  await page.locator('[data-notif-type="propietarios_no_activos"]').click();
  await page.locator('[data-notif-recipient][value="11"]').waitFor();
  if ((await page.locator('[data-notif-recipient][value="10"]').count()) !== 0) throw new Error('Los propietarios activos aparecen en no activos');
  await page.locator('[data-notif-type="arrendatarios_no_activos"]').click();
  await page.locator('[data-notif-recipient][value="21"]').waitFor();
  await page.locator('[data-notif-type="arrendatarios_activos"]').click();
  await page.locator('[data-notif-recipient][value="20"]').waitFor();
  if ((await page.locator('[data-notif-recipient][value="21"]').count()) !== 0) throw new Error('Los arrendatarios no activos aparecen en activos');
  await page.locator('[data-notif-type="propietarios_activos"]').click();
  await page.locator('[data-notif-recipient][value="10"]').waitFor();
  await page.locator('[data-notif-recipient][value="10"]').check();
  await page.locator('#notif-message').fill('Tu asesoría está agendada para mañana a las 10:00 a. m.');
  const preview = await page.locator('[data-notif-preview]').textContent();
  if (!preview.includes('Propietario 10') || !preview.includes('Ana Pérez') || !preview.includes('3001234567')) throw new Error('La vista previa perdió el saludo o firma');
  await page.screenshot({ path: 'output/notifications-desktop.png', fullPage: true });
  await page.locator('[data-notif-select-all]').click();
  await page.locator('[data-notif-recipient][value="15"]').uncheck();
  if ((await page.locator('[data-notif-selected]').textContent()) !== '2 seleccionados' || !(await page.locator('[data-notif-recipient][value="10"]').isChecked())) throw new Error('Deseleccionar un resultado cancela la selección total');
  await page.locator('[data-notif-clear]').click();
  await page.locator('[data-notif-recipient][value="10"]').check();
  await page.locator('#notif-template').selectOption('scm_comercial_generica_documento_v1');
  if (!(await page.locator('[data-notif-media-fields]').isVisible())) throw new Error('El encabezado PDF no solicita archivo');
  await page.locator('#notif-template').selectOption('scm_comercial_generica_texto_v1');
  if (await page.locator('[data-notif-media-fields]').isVisible()) throw new Error('Texto muestra un archivo innecesario');
  page.on('dialog', dialog => dialog.accept());
  await page.locator('[data-notif-send]').click();
  await page.locator('[data-notif-feedback]').filter({ hasText: '1 notificaciones en cola' }).waitFor();
  await page.locator('[data-notif-type="club_pph"]').click();
  await page.locator('[data-notif-recipients]').getByText('Club PPH 40', { exact: true }).waitFor();
  if (await page.locator('[data-notif-contract-filters]').isVisible()) throw new Error('Club PPH muestra filtros de contrato');
  await page.locator('[data-notif-view="queue"]').click();
  await page.locator('[data-notif-queue-rows]').getByText('Todavía no hay notificaciones en este estado.').waitFor();
  // Comprobar los endpoints reales con base aislada: permisos, CSRF y alcance en envío.
  const call = async extra => page.request.post('http://127.0.0.1:8769/tests/Fixtures/notifications-api.php', { form: { action: 'commercial_notifications_recipients', nonce: 'fixture', type: 'propietarios_activos', ...extra } });
  if ((await call({ restricted: '1' })).status() !== 403) throw new Error('API sin control de visibilidad');
  if ((await call({ nonce: 'incorrecto' })).status() !== 403) throw new Error('API sin CSRF');
  const outside = await call({ action: 'commercial_notifications_send', 'ids[]': '13', 'channels[]': 'whatsapp', request_id: 'a'.repeat(32), message: 'Mensaje de prueba', whatsapp_template: 'scm_comercial_generica_texto_v1' });
  if (outside.status() !== 422) throw new Error('API permite destinatario ajeno');
  const excluding = await call({ action: 'commercial_notifications_send', all_filtered: '1', 'exclude_ids[]': '10', 'channels[]': 'whatsapp', request_id: 'b'.repeat(32), message: 'Mensaje de prueba', whatsapp_template: 'scm_comercial_generica_texto_v1' });
  const excludingResult = await excluding.json();
  if (excluding.status() !== 200 || excludingResult.data.selected !== 2 || excludingResult.data.queued !== 0 || excludingResult.data.filtered !== 2) throw new Error('API perdió exclusiones o preferencias en selección total');
  const upload = async content => page.request.post('http://127.0.0.1:8769/tests/Fixtures/notifications-api.php', { multipart: {
    action: 'commercial_notifications_send', nonce: 'fixture', type: 'propietarios_activos', 'ids[]': '10', 'channels[]': 'whatsapp', request_id: 'c'.repeat(32), message: 'Mensaje con PDF', whatsapp_template: 'scm_comercial_generica_documento_v1', media_type: 'document',
    media: { name: 'prueba.pdf', mimeType: 'application/pdf', buffer: Buffer.from(content) }
  } });
  if ((await upload('Esto no es un PDF')).status() !== 422) throw new Error('Se acepta un archivo con MIME incorrecto');
  const pdfResult = await upload('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF');
  if (pdfResult.status() !== 200 || (await pdfResult.json()).data.queued !== 1) throw new Error('No se puede guardar y encolar un encabezado PDF');
  await page.locator('[data-notif-view="recipients"]').click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'output/notifications-mobile.png', fullPage: true });
  const panelOverflow = await page.locator('[data-commercial-notifications]').evaluate(node => node.scrollWidth > node.clientWidth);
  if (panelOverflow) throw new Error('El módulo desborda en móvil');
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  if (pageOverflow) throw new Error('La navegación desborda en móvil');
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('UI: saludo, firma, plantillas, envío aislado, Club PPH, cola, permisos, CSRF y responsive: OK');
  await browser.close();
})().catch(error => { console.error(error); process.exitCode = 1; });
