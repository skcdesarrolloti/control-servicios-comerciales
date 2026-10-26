/* Run against the local-only fixture: php -S 127.0.0.1:8771 -t . */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  fs.rmSync('output/commercial-credit.sqlite', { force: true });
  const browser = await chromium.launch({ headless: true, channel: process.env.SCM_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1050 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:8771/tests/Fixtures/commercial-credit.php');
    await page.locator('[data-commercial-credit]').waitFor();
    assert.equal(await page.locator('[data-credit-request]').isVisible(), false);
    assert.equal(await page.locator('input[name="beneficiary_type"][value="arrendatario"]').isDisabled(), true);
    await page.locator('[data-commercial-dropdown-trigger="comerciales"]').click();
    const navigation = page.waitForResponse(response => response.request().postData()?.includes('commercial_tickets_filter'));
    await page.locator('[data-commercial-dropdown-menu="comerciales"] a[data-commercial-tab="solicitud_credito"]').click();
    await navigation;

    await page.locator('[data-credit-document]').fill('12');
    await page.locator('[data-credit-search]').click();
    await page.waitForFunction(() => document.querySelector('[data-credit-search-status]').textContent.includes('mínimo 4'));
    await page.locator('[data-credit-document]').fill('1.143.415.938');
    await page.locator('[data-credit-document]').press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('[data-credit-pick]').length === 2);
    assert.match(await page.locator('[data-credit-person]').innerText(), /Ana Propietaria/);
    assert.equal(await page.locator('[data-credit-request]').isVisible(), false);
    await page.screenshot({ path: 'output/commercial-credit-search.png', fullPage: true });

    await page.locator('.ccr-contract').first().click();
    assert.equal(await page.locator('[data-credit-request]').isVisible(), true);
    await page.locator('#ccr-amount').pressSequentially('1500000');
    assert.equal(await page.locator('#ccr-amount').inputValue(), '$1.500.000');
    await page.locator('#ccr-term').fill('48');
    assert.match(await page.locator('[data-credit-term-hint]').innerText(), /analista revisará/);
    await page.locator('#ccr-term').fill('12');
    await page.locator('#ccr-purpose').selectOption('Educación');
    await page.screenshot({ path: 'output/commercial-credit-form.png', fullPage: true });
    await page.locator('[data-credit-submit]').click();
    await page.locator('[data-credit-result]').waitFor({ state: 'visible' });
    assert.match(await page.locator('[data-credit-result]').innerText(), /Solicitud de crédito #1 registrada para Ana Propietaria por \$1\.500\.000/);
    await page.screenshot({ path: 'output/commercial-credit-saved.png', fullPage: true });

    await page.locator('[data-credit-again]').click();
    assert.equal(await page.locator('[data-credit-form]').isVisible(), true);
    assert.equal(await page.locator('[data-credit-document]').inputValue(), '');

    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({ path: 'output/commercial-credit-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
    assert.deepEqual(errors, []);
    console.log('commercial credit request: ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
