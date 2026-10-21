const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const {chromium} = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({headless:true,channel:process.platform === 'win32' ? 'msedge' : undefined});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1080}}), errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    const api = 'http://127.0.0.1:8771/tests/Fixtures/commercial-property-entry.php';
    await page.goto(api);
    await page.locator('[data-commercial-open-case="100"]').click();
    await page.locator('[data-commercial-task-form="property"]').click();
    const root = page.locator('[data-commercial-property-entry]');
    await root.locator('[data-entry-tab="4"]').click();
    const media = root.locator('[data-media-embedded]');
    await media.locator('[data-media-input="cover"]').setInputFiles('output/video-photos/foto-0.jpg');
    await page.waitForFunction(()=>document.querySelector('[data-media-embedded]')?._media.portada?.file && !document.querySelector('[data-media-embedded]')._busy);
    await media.locator('[data-media-input="gallery"]').setInputFiles(['output/video-photos/foto-1.jpg','output/video-photos/foto-2.jpg']);
    await page.waitForFunction(()=>document.querySelector('[data-media-embedded]')?._media.galeria.length === 2 && !document.querySelector('[data-media-embedded]')._busy);
    await media.locator('[data-property-video-open]').click();
    const dialog = page.locator('.cpv-dialog');
    await dialog.getByRole('checkbox',{name:'Incluir foto 1',exact:true}).uncheck();
    await dialog.getByRole('button',{name:'Mover escena 2 antes',exact:true}).click();
    await dialog.locator('[data-option=title]').fill('Apartamento en venta');
    await dialog.locator('[data-option=location]').fill('Medellín, Antioquia');
    await dialog.locator('[data-option=price]').fill('$350.000.000');
    await dialog.locator('[data-option=contact]').fill('Agenda tu visita con SuCasa');
    await dialog.locator('[data-option=seconds]').selectOption('2');
    await dialog.getByRole('textbox',{name:'Título de escena 1'}).fill('Cocina');
    await dialog.getByRole('textbox',{name:'Título de escena 2'}).fill('Sala y comedor');
    await page.screenshot({path:'output/property-video-studio.png'});
    await dialog.locator('[data-generate]').click();
    await dialog.locator('[data-download]').waitFor({state:'visible',timeout:150000});
    const job = await page.evaluate(()=>JSON.parse(sessionStorage.getItem(Object.keys(sessionStorage).find(key=>key.startsWith('scm-property-video:')))));
    assert.ok(job?.receipt,'Creation should store a signed receipt');
    await page.waitForFunction(()=>document.querySelector('.cpv-dialog video')?.readyState >= 1);
    const metadata = await dialog.locator('video').evaluate(video=>({width:video.videoWidth,height:video.videoHeight,duration:video.duration}));
    assert.deepEqual(metadata,{width:720,height:1280,duration:11});
    const bytes = await dialog.locator('video').evaluate(async video=>Array.from(new Uint8Array(await (await fetch(video.src)).arrayBuffer())));
    await fs.writeFile('output/sucasa-video-demo.mp4',Buffer.from(bytes));
    assert.ok(bytes.length > 10000);
    for (const patch of [{ticket_pk:'200'}, {job_id:'b'.repeat(32)}, {nonce:'invalid'}]) {
      const response = await page.request.post(api,{form:{action:'commercial_property_video_status',nonce:'fixture',ticket_pk:'100',...job,...patch}});
      assert.equal(response.status(),403,'A job requires CSRF, task scope and a signed receipt');
    }
    await dialog.getByRole('button',{name:'Cerrar creador de videos'}).click();
    await page.setViewportSize({width:390,height:844});
    await media.locator('[data-property-video-open]').click();
    await page.locator('.cpv-dialog [data-download]').waitFor({state:'visible',timeout:30000});
    assert.equal(await dialog.evaluate(node=>node.scrollWidth <= node.clientWidth + 1),true,'Mobile must not scroll horizontally');
    await page.screenshot({path:'output/property-video-mobile.png'});
    await dialog.getByRole('button',{name:'Cerrar creador de videos'}).click();
    await page.route('**/tests/Fixtures/commercial-property-entry.php',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data:{available:false}})}),{times:1});
    await media.locator('[data-property-video-open]').click();
    await dialog.locator('[data-status]').filter({hasText:'falta conectar'}).waitFor();
    assert.equal(await dialog.locator('[data-generate]').isDisabled(),true,'Explain missing VPS configuration before exporting');
    await dialog.getByRole('button',{name:'Cerrar creador de videos'}).click();
    assert.deepEqual(errors,[]);
    console.log('Video studio: OK (gallery selection/reorder, real PHP-to-Python multipart, CPU rendering, playable MP4, signed download, permissions, reopen, mobile).');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
