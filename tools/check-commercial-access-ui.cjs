// Servir el repositorio en 127.0.0.1:8769. Solo utiliza la base aislada de pruebas.
const path = require('path');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
let browser;

(async () => {
  browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  const endpoint = 'http://127.0.0.1:8769/tests/Fixtures/commercial-access.php';
  // El botón real usa index.php; dirigirlo al inicio aislado evita usar la base de producción.
  await page.route('**/public/index.php', route => route.continue({ url: endpoint }));
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', async dialog => { errors.push('El acceso denegado abrió una alerta nativa'); await dialog.dismiss(); });
  const direct = await page.goto(endpoint + '?tab=notificaciones&ticket_id=999');
  await page.locator('[data-commercial-access-denied]').waitFor();
  if (direct.status() !== 403 || !(await page.title()).includes('Acceso denegado')) throw new Error('La URL directa no devuelve una página 403');
  if (await page.locator('[data-commercial-notifications]').count()) throw new Error('Se muestra el módulo restringido');
  const homeLink = await page.locator('[data-commercial-denied-home]').getAttribute('href');
  if (homeLink.includes('?') || !(await page.locator('[data-commercial-denied-home]').textContent()).includes('Ir al inicio')) throw new Error('El enlace de recuperación conserva la URL restringida');
  await page.screenshot({ path: 'output/commercial-access-desktop.png', fullPage: true });
  await page.locator('[data-commercial-denied-home]').click();
  await page.locator('[data-fixture-home]').waitFor();
  if (await page.locator('[data-commercial-access-denied]').count()) throw new Error('Volver al inicio deja la vista restringida');

  const denied = await page.request.post(endpoint, { form: { action: 'commercial_tickets_filter', nonce: 'fixture', tab: 'notificaciones' } });
  const deniedPayload = await denied.json();
  if (denied.status() !== 403 || deniedPayload.success || !deniedPayload.data.page_denied || !deniedPayload.data.html.includes('Ir al inicio')) throw new Error('La API no entrega el estado de acceso denegado');
  const csrf = await page.request.post(endpoint, { form: { action: 'commercial_tickets_filter', nonce: 'incorrecto', tab: 'notificaciones' } });
  if (csrf.status() !== 403 || (await csrf.json()).data.page_denied) throw new Error('Se confunde un fallo CSRF con permisos de página');

  // Simular una URL guardada en el historial: se debe quitar el contenido anterior.
  await page.evaluate(() => {
    window.history.pushState({}, '', '?tab=notificaciones');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
  await page.locator('[data-commercial-access-denied]').waitFor();
  if (await page.locator('[data-fixture-home]').count()) throw new Error('La navegación deja visible el contenido anterior');
  if (!(await page.locator('[data-commercial-access-denied] h2').evaluate(node => node === document.activeElement))) throw new Error('La vista de permisos no recibe el foco');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'output/commercial-access-mobile.png', fullPage: true });
  if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) throw new Error('La página 403 desborda en móvil');
  await page.locator('[data-commercial-denied-home]').click();
  await page.locator('[data-fixture-home]').waitFor();
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('Acceso comercial UI: URL directa 403, navegación por historial, botón al inicio, CSRF, foco y móvil: OK');
  await browser.close();
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
