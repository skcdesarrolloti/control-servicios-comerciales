/* Herramientas › Plan estratégico en el navegador. Run against the local-only fixture: php -S 127.0.0.1:8772 -t . */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

const BASE = 'http://127.0.0.1:8772/tests/Fixtures/commercial-strategy.php';

/** "Ver" en la fila que contiene el texto: baja al nivel hijo y espera a que la ruta lo muestre. */
async function drill(page, rowText) {
  await page.locator('[data-plan-list] tr', { hasText: rowText }).locator('[data-plan-action="children"]').click();
  await page.waitForFunction(text => {
    const current = document.querySelector('[data-plan-context] .cps-crumb.is-current');
    return current && current.textContent === text && !document.querySelector('[data-plan-list]').hasAttribute('aria-busy');
  }, rowText);
}

async function openTab(page, tab, rows) {
  await page.locator(`[data-plan-tab="${tab}"]`).click();
  await page.waitForFunction(([name, count]) => document.querySelector(`[data-plan-tab="${name}"]`).getAttribute('aria-pressed') === 'true'
    && document.querySelector('[data-plan-context]').hidden && !document.querySelector('[data-plan-list]').hasAttribute('aria-busy')
    && document.querySelectorAll('[data-plan-list] tbody tr').length === count, [tab, rows]);
}

(async () => {
  fs.rmSync('output/commercial-strategy.sqlite', { force: true });
  const browser = await browserLaunch();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(BASE);
    const root = page.locator('[data-commercial-strategy]');
    await root.waitFor();
    const menu = page.locator('[data-commercial-dropdown-menu="herramientas"]');
    assert.equal(await menu.locator('a').count(), 6);
    assert.match(await menu.locator('a').last().getAttribute('href'), /[?&]tab=plan_estrategico$/);

    // Solo dos pestañas; abre en Mis tareas porque el funcionario tiene una pendiente.
    const list = root.locator('[data-plan-list]');
    assert.deepEqual(await root.locator('[data-plan-tab]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-plan-tab'))), ['mis_tareas', 'gestiones']);
    await page.waitForFunction(() => document.querySelector('[data-plan-list]').textContent.includes('Referidos de propietarios'));
    assert.equal(await root.locator('[data-plan-tab="mis_tareas"]').getAttribute('aria-pressed'), 'true');
    assert.equal(await root.locator('[data-plan-stat="mis_pendientes"]').innerText(), '1');
    assert.equal(await list.locator('tbody tr').count(), 1);

    // Gestiones → objetivos → metas con "Ver"; la ruta muestra cada nivel y Gestiones sigue resaltada.
    await openTab(page, 'gestiones', 2);
    assert.equal(await root.locator('[data-plan-new]').isVisible(), false, 'Las gestiones se editan en Configuración.');
    await drill(page, 'Gestión Comercial');
    assert.equal(await list.locator('tbody tr').count(), 1);
    assert.equal(await root.locator('[data-plan-tab="gestiones"]').getAttribute('aria-pressed'), 'true');
    await drill(page, 'Aumentar cierres de arriendo');
    assert.equal(await list.locator('tbody tr').count(), 2);
    assert.deepEqual(await root.locator('[data-plan-context] .cps-crumb').allInnerTexts(), ['Gestiones', 'Gestión Comercial', 'Aumentar cierres de arriendo']);
    assert.match(await root.locator('[data-plan-context]').innerText(), /Metas del objetivo/i);
    assert.equal(await root.locator('[data-plan-filter="objetivo"]').count(), 0);
    assert.match(await list.locator('tr', { hasText: 'Cerrar 30 arriendos' }).innerText(), /Actual 12 \/ Meta 30 % · 40 %/);

    // "+" en una meta crea una tarea con esa meta ya marcada.
    await list.locator('tr', { hasText: 'Cerrar 30 arriendos' }).locator('[data-plan-action="create-child"]').click();
    const form = root.locator('[data-plan-form-dialog]');
    await form.waitFor({ state: 'visible' });
    assert.equal(await form.locator('[data-plan-form-title]').innerText(), 'Crear tarea');
    assert.equal(await form.locator('[data-plan-multi="id_meta"] input:checked').count(), 1);
    await form.locator('[data-plan-form-submit]').click();
    assert.equal(await form.isVisible(), true, 'Sin nombre no se envía.');
    await form.locator('[name="nombre_tarea"]').fill('Llamar a administradores');
    await form.locator('[data-plan-multi="responsable"] [data-plan-multi-search]').fill('segundo');
    assert.equal(await form.locator('[data-plan-multi="responsable"] .cps-check:not([hidden])').count(), 1);
    await form.locator('[data-plan-multi="responsable"] .cps-check:not([hidden]) input').check();
    await form.locator('[name="fecha_inicio"]').fill('2026-05-10');
    await form.locator('[name="fecha_fin"]').fill('2026-05-01');
    await form.locator('[data-plan-form-submit]').click();
    await page.waitForFunction(() => /anterior a la de inicio/.test(document.querySelector('[data-plan-form-error]').textContent));
    await form.locator('[name="fecha_fin"]').fill('2026-05-30');
    await form.locator('[data-plan-form-submit]').click();
    await form.waitFor({ state: 'hidden' });
    await page.waitForFunction(() => document.querySelector('[data-plan-stat="tareas"]').textContent === '3');
    // La lista se vuelve a pedir después de guardar: la meta ya cuenta las dos tareas.
    await page.waitForFunction(() => /Completadas\s*0\/2/.test(Array.from(document.querySelectorAll('[data-plan-list] tr')).find(row => row.textContent.includes('Cerrar 30 arriendos')).innerText));

    // Tareas de la meta; dentro de un nivel, "Nueva tarea" ya la deja en esa meta.
    await drill(page, 'Cerrar 30 arriendos');
    await page.waitForFunction(() => document.querySelectorAll('[data-plan-list] tbody tr').length === 2);
    assert.equal(await root.locator('[data-plan-new-label]').innerText(), 'Nueva tarea');
    await root.locator('[data-plan-new]').click();
    assert.equal(await form.locator('[data-plan-multi="id_meta"] input:checked').count(), 1);
    await form.locator('footer [data-plan-close]').click();

    // Bitácora: el avance con "completada" cambia el estado y descuenta de Mis tareas pendientes.
    await list.locator('tr', { hasText: 'Referidos de propietarios' }).locator('[data-plan-action="log"]').click();
    const log = root.locator('[data-plan-log-dialog]');
    await log.waitFor({ state: 'visible' });
    assert.match(await log.locator('[data-plan-log-list]').innerText(), /14\/01\/2026 08:51\s+Funcionario de prueba\s+Primer contacto/);
    await log.locator('textarea[name="descripcion"]').fill('Se llamaron 20 propietarios');
    await log.locator('input[name="finalizar"]').check({ force: true });
    await log.locator('button[type="submit"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-plan-log-list] li').length === 2);
    assert.match(await log.locator('[data-plan-log-list] li').first().innerText(), /Funcionario de prueba\s+Se llamaron 20 propietarios/);
    assert.equal(await log.locator('[data-plan-log-finish]').isVisible(), false);
    await log.locator('header [data-plan-close]').click();
    assert.match(await list.locator('tr', { hasText: 'Referidos de propietarios' }).innerText(), /Completado/);
    assert.equal(await root.locator('[data-plan-stat="mis_pendientes"]').innerText(), '0');

    // Campañas de la tarea; un clic en la ruta vuelve directo a los objetivos de la gestión.
    await drill(page, 'Referidos de propietarios');
    assert.match(await list.innerText(), /Despide el año/);
    assert.equal(await root.locator('[data-plan-context] .cps-crumb').count(), 5);
    await root.locator('[data-plan-crumb="1"]').click();
    await page.waitForFunction(() => document.querySelector('[data-plan-context] .cps-crumb.is-current').textContent === 'Gestión Comercial'
      && document.querySelectorAll('[data-plan-list] tbody tr').length === 1 && document.querySelector('[data-plan-list]').textContent.includes('Aumentar cierres'));

    // Eliminar pide confirmación y no deja borrar un objetivo con metas.
    await list.locator('tr', { hasText: 'Aumentar cierres de arriendo' }).locator('[data-plan-action="delete"]').click();
    const confirm = root.locator('[data-plan-confirm-dialog]');
    await confirm.waitFor({ state: 'visible' });
    assert.match(await confirm.locator('[data-plan-confirm-text]').innerText(), /¿Eliminar el objetivo «Aumentar cierres de arriendo»\?/);
    await confirm.locator('[data-plan-confirm-ok]').click();
    await page.waitForFunction(() => /tiene 2 metas asociadas/.test(document.querySelector('[data-plan-confirm-error]').textContent));
    await confirm.locator('footer [data-plan-close]').click();

    // "Volver" sube un nivel (a Gestiones); en otra gestión, "Nuevo objetivo" ya trae la gestión.
    await root.locator('[data-plan-back]').click();
    await page.waitForFunction(() => document.querySelector('[data-plan-context]').hidden && document.querySelectorAll('[data-plan-list] tbody tr').length === 2);
    await drill(page, 'Gestión de Mercadeo');
    await root.locator('[data-plan-new]').click();
    assert.equal(await form.locator('[name="perspectiva"]').inputValue(), 'Gestión de Mercadeo');
    await form.locator('footer [data-plan-close]').click();
    await list.locator('tr', { hasText: 'Posicionar la marca' }).locator('[data-plan-action="delete"]').click();
    await confirm.locator('[data-plan-confirm-ok]').click();
    await confirm.waitFor({ state: 'hidden' });
    await page.waitForFunction(() => document.querySelector('[data-plan-list]').textContent.includes('Sin registros'));
    assert.equal(await root.locator('[data-plan-stat="objetivos"]').innerText(), '1');

    // Mis tareas: filtro de gestión (sube por meta → objetivo → gestión).
    await openTab(page, 'mis_tareas', 1);
    await root.locator('[data-plan-filter="gestion"]').selectOption('Gestión de Mercadeo');
    await page.waitForFunction(() => document.querySelector('[data-plan-list]').textContent.includes('No tienes tareas'));
    await root.locator('[data-plan-filter="gestion"]').selectOption('Gestión Comercial');
    await page.waitForFunction(() => document.querySelectorAll('[data-plan-list] tbody tr').length === 1);

    // Glosario con las definiciones de los catálogos.
    await root.locator('[data-plan-glossary]').click();
    const glossary = root.locator('[data-plan-glossary-dialog]');
    await glossary.waitFor({ state: 'visible' });
    assert.match(await glossary.innerText(), /Gestión Comercial\s+Ventas y arriendos/);
    await glossary.locator('[data-plan-close]').click();

    // Configuración › Plan estratégico: renombrar un tipo de medio lo cambia en las campañas que lo usan.
    await page.locator('#commercial-config-trigger').click();
    await page.locator('[data-commercial-config-action="plan"]').click();
    const popup = page.locator('.swal2-popup');
    await popup.locator('[data-plan-catalog="tipos_medio"]').waitFor();
    await popup.locator('[data-plan-catalog="tipos_medio"] summary').click();
    const mediaRows = popup.locator('[data-plan-catalog="tipos_medio"] tbody [data-plan-row]');
    assert.equal(await mediaRows.count(), 2);
    assert.equal(await popup.locator('[data-plan-catalog="tipos_medio"] tbody tr', { hasText: 'En uso: 1' }).count(), 2);
    assert.equal(await mediaRows.locator('[data-plan-remove]').count(), 0, 'Lo que está en uso no se puede quitar.');
    await mediaRows.locator('[data-field="nombre"]').nth(1).fill('Google Ads');
    await popup.locator('[data-plan-catalog="tipos_medio"] [data-plan-add-row]').click();
    await mediaRows.last().locator('[data-field="nombre"]').fill('Radio');
    await popup.locator('.swal2-confirm').click();
    await page.waitForFunction(() => !document.querySelector('.swal2-popup [data-plan-catalog]'));
    await openTab(page, 'gestiones', 2);
    await drill(page, 'Gestión Comercial');
    await drill(page, 'Aumentar cierres de arriendo');
    await drill(page, 'Cerrar 30 arriendos');
    await drill(page, 'Referidos de propietarios');
    assert.match(await list.innerText(), /Google Ads/);

    // Quitar un elemento libre exige marcar la confirmación.
    await page.locator('#commercial-config-trigger').click();
    await page.locator('[data-commercial-config-action="plan"]').click();
    await popup.locator('[data-plan-catalog="tipos_medio"]').waitFor();
    await popup.locator('[data-plan-catalog="tipos_medio"] summary').click();
    await mediaRows.filter({ has: page.locator('input[value="Radio"]') }).locator('[data-plan-remove]').click();
    assert.equal(await popup.locator('[data-plan-confirm]').isVisible(), true);
    await popup.locator('.swal2-confirm').click();
    await page.waitForFunction(() => /Marca la confirmación/.test((document.querySelector('.swal2-validation-message') || {}).textContent || ''));
    await popup.locator('[name="confirm_delete"]').check();
    await popup.locator('.swal2-confirm').click();
    await page.waitForFunction(() => !document.querySelector('.swal2-popup [data-plan-catalog]'));

    assert.equal(errors.length, 0, errors.join('\n'));

    // Cargo sin plan_estrategico_editar: solo ve, y registra avances únicamente en sus tareas.
    const reader = await context.newPage();
    reader.on('pageerror', error => errors.push(error.message));
    await reader.goto(BASE + '?cargo=9&nombre=Segundo+consultor');
    const readerRoot = reader.locator('[data-commercial-strategy]');
    await reader.waitForFunction(() => document.querySelector('[data-plan-list]').textContent.includes('Visitar copropiedades'));
    assert.equal(await readerRoot.locator('[data-plan-new]').count(), 0);
    assert.equal(await readerRoot.locator('[data-plan-action="edit"], [data-plan-action="delete"], [data-plan-action="create-child"]').count(), 0);
    await readerRoot.locator('[data-plan-list] tr', { hasText: 'Visitar copropiedades' }).locator('[data-plan-action="log"]').click();
    assert.equal(await readerRoot.locator('[data-plan-log-form]').isVisible(), true);
    await readerRoot.locator('[data-plan-log-dialog] header [data-plan-close]').click();
    await openTab(reader, 'gestiones', 2);
    await drill(reader, 'Gestión Comercial');
    await drill(reader, 'Aumentar cierres de arriendo');
    await drill(reader, 'Cerrar 30 arriendos');
    await readerRoot.locator('[data-plan-list] tr', { hasText: 'Referidos de propietarios' }).locator('[data-plan-action="log"]').click();
    assert.equal(await readerRoot.locator('[data-plan-log-form]').isVisible(), false);
    await readerRoot.locator('[data-plan-log-dialog] header [data-plan-close]').click();

    // Móvil: sin desplazamiento horizontal de la página, también con la ruta abierta.
    await reader.setViewportSize({ width: 390, height: 844 });
    let overflow = await reader.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 0, 'La página se desborda ' + overflow + ' px en móvil con la ruta abierta.');
    await reader.reload();
    await reader.waitForFunction(() => document.querySelector('[data-plan-list] tbody'));
    overflow = await reader.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 0, 'La página se desborda ' + overflow + ' px en móvil.');
    assert.equal(errors.length, 0, errors.join('\n'));
    console.log('commercial strategy: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });

function browserLaunch() {
  return chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
}
