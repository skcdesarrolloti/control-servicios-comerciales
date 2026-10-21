const assert = require('node:assert/strict');
const {chromium} = require(process.env.SCM_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({headless:true,channel:process.platform === 'win32' ? 'msedge' : undefined});
  try {
    const context = await browser.newContext({viewport:{width:1440,height:1050},deviceScaleFactor:2});
    const page = await context.newPage(), errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    const api='http://127.0.0.1:8771/tests/Fixtures/commercial-property-entry.php';
    await page.goto(api);
    await page.evaluate(async () => {
      const image=document.createElement('canvas'); image.width=728; image.height=1200;
      const ctx=image.getContext('2d');ctx.fillStyle='rgb(80,120,160)';ctx.fillRect(0,0,728,1200);ctx.fillStyle='rgb(220,20,30)';ctx.fillRect(350,586,28,28);ctx.fillStyle='#d9deeb';ctx.fillRect(0,1050,728,150);
      await window.openPropertyImageEditor({url:image.toDataURL('image/png')},async file=>{window._studioResult={type:file.type,size:file.size};},{ticketPk:100});
    });
    const editor=page.locator('dialog.cpm-editor');
    await page.waitForFunction(()=>document.querySelector('.cpm-editor')?.dataset.editorReady==='true');
    async function pixel(x=364,y=600) {
      return editor.locator('canvas.lower-canvas').evaluate((node,point)=>Array.from(node.getContext('2d').getImageData(Math.round(point.x*node.width/728),Math.round(point.y*node.height/1200),1,1).data),{x,y});
    }
    await page.waitForFunction(()=>{const c=document.querySelector('.lower-canvas');return c&&c.getContext('2d').getImageData(c.width/2,c.height/2,1,1).data[0]>180;});
    let color=await pixel();assert.ok(color[0]>180 && color[1]<60,'La foto debe dibujarse antes de editar, en DPR2.');
    await page.setViewportSize({width:1200,height:900});
    color=await pixel();assert.ok(color[0]>180 && color[1]<60,'Redimensionar no debe borrar el lienzo retina.');
    await page.setViewportSize({width:1440,height:1050});
    await page.screenshot({path:'output/studio-initial-retina.png'});
    await editor.locator('[data-studio-tab=retouch]').click();
    await editor.locator('[data-studio=brush]').click();
    await editor.locator('[data-studio-brush]').fill('100');
    const bounds=await editor.locator('canvas.cpm-studio-mask').boundingBox();
    await page.mouse.click(bounds.x+bounds.width/2,bounds.y+bounds.height/2);
    await page.screenshot({path:'output/studio-retouch-mask.png'});
    await editor.locator('[data-studio=heal]').click();
    await editor.locator('.cpm-editor-status').filter({hasText:'Retoque local aplicado'}).waitFor();
    color=await pixel();assert.ok(color[0]<130 && color[1]>80 && color[2]>100,'El retoque debe sustituir el objeto por el fondo vecino.');
    const outside=await pixel(20,20);assert.ok(Math.abs(outside[0]-80)<=1 && Math.abs(outside[1]-120)<=1,'El retoque local no debe alterar píxeles fuera de la máscara.');
    await editor.locator('[data-edit=undo]').click();
    await page.waitForFunction(()=>{const c=document.querySelector('.lower-canvas');return c.getContext('2d').getImageData(c.width/2,c.height/2,1,1).data[0]>180;});
    await editor.locator('[data-edit=redo]').click();
    await page.waitForFunction(()=>{const c=document.querySelector('.lower-canvas');return c.getContext('2d').getImageData(c.width/2,c.height/2,1,1).data[0]<130;});
    await editor.locator('[data-studio-tab=adjust]').click();
    await editor.locator('[data-studio=enhance]').click();
    await editor.locator('.cpm-editor-status').filter({hasText:'Mejora automática aplicada'}).waitFor();
    assert.notDeepEqual(await pixel(20,20),outside,'La mejora automática debe modificar la fotografía.');
    await editor.locator('[data-studio=compare]').click();assert.equal(await editor.locator('.cpm-studio-original').isVisible(),true);
    await editor.locator('[data-studio=compare]').click();
    await editor.locator('[data-studio=zoom-in]').click();await editor.locator('[data-studio=fit]').click();
    await editor.locator('[data-studio-tab=crop]').click();await editor.locator('[data-studio=crop-start]').click();await editor.locator('[data-studio=crop-apply]').click();
    assert.ok(Number(await editor.locator('[data-setting=width]').inputValue())<728);
    await editor.locator('[data-studio-tab=text]').click();await editor.locator('[data-setting=text]').fill('Interior');await editor.locator('[data-edit=text-add]').click();
    await editor.locator('[data-studio-tab=layers]').click();assert.equal(await editor.locator('[data-studio-layers] .cpm-studio-layer').count(),2);
    await editor.locator('[data-studio-tab=retouch]').click();await editor.locator('[data-studio-ai-note]').filter({hasText:'IA no activada'}).waitFor();assert.equal(await editor.locator('[data-studio=cloud]').isDisabled(),true);
    await page.setViewportSize({width:390,height:844});await page.screenshot({path:'output/studio-mobile.png'});
    assert.equal(await editor.evaluate(node=>node.scrollWidth>node.clientWidth+1),false);
    await editor.locator('[data-edit=save]').click();await editor.waitFor({state:'detached'});
    assert.equal(await page.evaluate(()=>window._studioResult.type),'image/jpeg');
    assert.deepEqual(errors,[]);
    const scope=await context.request.post(api,{form:{action:'commercial_image_cleanup_status',nonce:'fixture',ticket_pk:'200'}});assert.equal(scope.status(),403);
    const csrf=await context.request.post(api,{form:{action:'commercial_image_cleanup_status',nonce:'bad',ticket_pk:'100'}});assert.equal(csrf.status(),403);
    const mobile = await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:3});
    const mobilePage = await mobile.newPage(); await mobilePage.goto(api);
    await mobilePage.evaluate(async ()=>{const c=document.createElement('canvas');c.width=600;c.height=900;const ctx=c.getContext('2d');ctx.fillStyle='#35704b';ctx.fillRect(0,0,600,900);await window.openPropertyImageEditor({url:c.toDataURL('image/png')},async()=>{});});
    await mobilePage.waitForFunction(()=>{const c=document.querySelector('.lower-canvas');return document.querySelector('.cpm-editor')?.dataset.editorReady==='true'&&c.getContext('2d').getImageData(c.width/2,c.height/2,1,1).data[1]>80;});
    assert.equal(await mobilePage.locator('dialog').evaluate(node=>node.scrollWidth>node.clientWidth+1),false);
    await mobilePage.keyboard.press('Escape'); assert.equal(await mobilePage.locator('dialog').count(),0); await mobile.close();
    console.log('Studio: OK (initial retina render, resize, portrait fit, masked healing, unchanged outside pixels, undo/redo, local enhancement, compare, zoom, crop, layers, mobile, export and AI scope/CSRF).');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
