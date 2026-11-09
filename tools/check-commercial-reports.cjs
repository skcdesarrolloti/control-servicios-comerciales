// Reportes comerciales con base SQLite aislada (tests/Fixtures/commercial-reports.php). Servir el repositorio en 127.0.0.1:8769 y ejecutar:
//   node tools/check-commercial-reports.cjs RUTA_A_NODE_MODULES
const path = require('path');
const { chromium } = require(process.argv[2] ? path.join(process.argv[2], 'playwright') : 'playwright');
const endpoint = 'http://127.0.0.1:8769/tests/Fixtures/commercial-reports.php';
let browser;

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

(async () => {
  browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || 'msedge' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const saved = text => page.waitForResponse(response => response.request().method() === 'POST' && (response.request().postData() || '').includes(text));

  // Mis reportes: solo los propios, y crear la cuenta con confirmación.
  await page.goto(endpoint + '?sub=mis_reportes');
  await page.locator('[data-report-row="1"]').waitFor();
  assert(await page.locator('[data-commercial-dropdown-menu="reportes_comerciales"] a').count() === 3, 'El menú Reportes no tiene sus tres entradas');
  assert(!(await page.locator('[data-report-row="3"]').count()), 'Mis reportes muestra reportes de otro funcionario');
  assert(!(await page.locator('[data-report-row="1"]').innerHTML()).includes('<b>'), 'La observación no se escapa');
  assert(await page.locator('[data-report-action="create"]').isDisabled(), 'Se puede crear una cuenta sin seleccionar reportes');
  await page.locator('[data-report-row="1"] [data-report-check]').check();
  await page.locator('[data-report-row="2"] [data-report-check]').check();
  assert((await page.locator('[data-report-selection]').textContent()).includes('$12.000'), 'La selección no suma el valor');
  await page.locator('[data-report-action="create"]').click();
  await page.locator('.swal2-popup:has-text("$12.000")').waitFor();
  const create = saved('commercial_reports_account_create');
  await page.locator('.swal2-confirm').click();
  const created = await (await create).json();
  assert(created.success && created.data.numero.startsWith('CCO-'), 'La cuenta no quedó con número comercial: ' + JSON.stringify(created));
  assert(created.data.summary.total === 12000, 'El total no sale de la base');

  // Mis cuentas abre el detalle sin mostrar la cuenta administrativa.
  await page.locator('[data-report-state="cuentas"]').click();
  await page.locator('[data-account-open="31"]').waitFor();
  assert(!(await page.locator('[data-report-content]').textContent()).includes('CC-000030'), 'Aparece una cuenta administrativa en Mis cuentas');
  await page.locator('[data-account-open="31"]').click();
  await page.locator('.swal2-popup [data-report-row="4"]').waitFor();
  await page.locator('.swal2-close').click();
  await page.locator('.swal2-container').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
  const excel = page.waitForEvent('download');
  await page.locator('[data-account-excel="31"]').click();
  assert((await excel).suggestedFilename() === 'cuenta_cobro_CCO-000031.xls', 'El Excel no se descarga');

  // Sin habilitar, no se ofrece crear cuentas.
  await page.goto(endpoint + '?sub=mis_reportes&billing=0');
  await page.locator('[data-report-row="1"]').waitFor();
  assert(!(await page.locator('[data-report-action="create"], [data-report-check]').count()), 'Se ofrecen cuentas de cobro sin habilitarlas');
  assert((await page.locator('[data-report-content]').textContent()).includes('todavía no están habilitadas'), 'No avisa que las cuentas están deshabilitadas');

  // Consolidado: todo el equipo, corregir valor y pagar.
  await page.goto(endpoint + '?sub=reportes_consolidado');
  await page.locator('[data-report-row="3"]').waitFor();
  const value = saved('commercial_reports_value');
  await page.locator('[data-report-row="3"] [data-report-value]').fill('6000');
  await page.locator('[data-report-row="3"] [data-report-value]').blur();
  assert((await (await value).json()).data.valor === 6000, 'No se corrige el valor');
  await page.locator('[data-report-filters] [name="funcionario"]').selectOption('900');
  await page.locator('[data-report-filters] button').click();
  await page.locator('[data-report-row="1"]').waitFor({ state: 'detached' });
  await page.locator('[data-report-state="no_pagados"]').click();
  await page.locator('[data-report-state="no_pagados"][aria-selected="true"]').waitFor();
  await page.locator('[data-report-filters] [name="funcionario"]').selectOption('');
  await page.locator('[data-report-filters] button').click();
  await page.locator('[data-report-row="4"] [data-report-check]').check();
  await page.locator('[data-report-action="pay"]').click();
  const pay = saved('commercial_reports_pay_reports');
  await page.locator('.swal2-confirm').click();
  assert((await (await pay).json()).data.updated === 1, 'No se marcó pagado');

  // Cuentas de cobro: solo comerciales, y pagar pide confirmación.
  await page.goto(endpoint + '?sub=cuentas_cobro');
  await page.locator('[data-account-open="31"]').waitFor();
  assert(!(await page.locator('[data-account-open="30"]').count()), 'Aparece una cuenta administrativa');
  await page.locator('[data-account-check][value="31"]').check();
  await page.locator('[data-report-action="pay-accounts"]').click();
  const payAccount = saved('commercial_reports_pay_accounts');
  await page.locator('.swal2-confirm').click();
  const paid = await (await payAccount).json();
  assert(paid.data.accounts === 1 && paid.data.reports === 1, 'No se pagó la cuenta: ' + JSON.stringify(paid));

  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(endpoint + '?sub=reportes_consolidado');
  await page.locator('[data-report-row="3"]').waitFor();
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'La página se desborda en móvil');

  if (errors.length) throw new Error(errors.join('\n'));
  console.log('Reportes comerciales UI: Mis reportes, cuentas CCO, consolidado, pagos y móvil: OK');
  await browser.close();
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
