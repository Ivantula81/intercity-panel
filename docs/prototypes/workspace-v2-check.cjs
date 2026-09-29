const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path'),os=require('node:os');
const out=process.env.QA_OUTPUT||path.join(os.tmpdir(),'terratrans-concept-qa'),results=[],issues=[],errors=[];
fs.mkdirSync(out,{recursive:true});
if(!process.argv[2])throw new Error('Pass the rendered visualization HTML path as the first argument.');
const preview=fs.readFileSync(process.argv[2],'utf8');
const attribute=preview.match(/(?:data-)?srcdoc="([^"]+)"/);
if(!attribute)throw new Error('The preview must contain a srcdoc iframe.');
const direct=attribute[1].replace(/&quot;/g,'"').replace(/&#x27;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&').replace('__CODEX_VISUALIZATION_WIDGET_STATE__','{"widgetState":null}');
const chrome=process.env.CHROME_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const launch={headless:true,executablePath:chrome};
const server=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html; charset=utf-8');res.end(req.url.startsWith('/direct')?direct:preview)});
function pass(name,detail=''){results.push({name,detail});console.log('PASS',name,detail)}
function mocks(){globalThis.__qaTweaks=[];globalThis.Tweak=class{constructor(o){this.o=o}addSelect(object,property){globalThis.__qaTweaks.push({object,property,change:this.o.onChange})}}}
async function tune(f,property,value){await f.evaluate(({property,value})=>{const t=globalThis.__qaTweaks.find(t=>t.property===property);t.object[property]=value;t.change()},{property,value})}
async function open(p,url,iframe=false){p.setDefaultTimeout(5000);p.on('pageerror',e=>errors.push(e.message));await p.goto(url,{waitUntil:'domcontentloaded'});await (iframe?p.frameLocator('iframe'):p).locator('#td-storage-state').waitFor();return iframe?p.frames().find(f=>f.parentFrame()):p}
async function workflow(f){
 await f.locator('[data-nav=trips]').click();await f.locator('#td-search').fill('T-022');await f.locator('[data-trip-row=B] [data-trip=B]').click();await f.locator('#td-note').fill('Проба заметки B');await f.locator('[data-trip-tab=passengers]').click();await f.locator('[data-passenger=P1]').check();
 await f.locator('[data-nav=inbox]').click();await f.locator('#td-reply').fill('Проба ответа A');await f.locator('.td-thread[data-chat=B]').click();await f.locator('#td-reply').fill('Проба ответа B');await f.locator('.td-thread[data-chat=A]').click();assert.equal(await f.locator('#td-reply').inputValue(),'Проба ответа A');await f.locator('#td-preview').click();assert.match(await f.locator('#td-preview-text').innerText(),/Диалог A/);
 await f.locator('[data-nav=trips]').click();assert.equal(await f.locator('#td-trip-code').innerText(),'T-022');assert.equal(await f.locator('[data-trip-tab=passengers]').getAttribute('aria-pressed'),'true');assert(await f.locator('[data-passenger=P1]').isChecked());await f.locator('[data-trip-tab=prep]').click();assert.equal(await f.locator('#td-note').inputValue(),'Проба заметки B');await f.locator('[data-screen=trip] [data-go=trips]').click();assert.equal(await f.locator('#td-search').inputValue(),'T-022');
 await f.locator('[data-trip-row=B] [data-trip=B]').click();await f.locator('#td-trip-chat').click();assert.equal(await f.locator('#td-reply').inputValue(),'Проба ответа B');await f.locator('.td-thread[data-chat=A]').click();await f.locator('#td-chat-trip').click();assert.equal(await f.locator('#td-trip-code').innerText(),'T-021');assert.notEqual(await f.locator('#td-note').inputValue(),'Проба заметки B');pass('Переходы, фильтр, вкладки, выбор, отдельные заметки и ответы');
 await f.locator('#td-note').fill('Проба ошибки сохранения');await tune(f,'saving','Ошибка сохранения');await f.locator('#td-save-note').click();assert.match(await f.locator('#td-note-state').innerText(),/Не применено/);assert.equal(await f.locator('#td-note').inputValue(),'Проба ошибки сохранения');await tune(f,'saving','Обычное сохранение');await f.locator('#td-save-note').click();assert.match(await f.locator('#td-note-state').innerText(),/Применено в примере/);pass('Ошибка и повтор сохранения без потери ввода');
}
async function layout(p){
 const captures=new Set(['1024-light-today','390-light-inbox','320-light-trip','1024-dark-finance']);
 for(const color of ['light','dark'])for(const width of [320,390,736,1024,1280,1440]){
  await p.setViewportSize({width,height:1100});await p.emulateMedia({colorScheme:color,reducedMotion:'reduce'});
  for(const screen of ['today','trips','trip','inbox','finance']){
   if(screen==='trip'){await p.locator('[data-nav=today]').click();await p.locator('[data-screen=today] [data-trip=B]').click()}
   else if(screen==='trips'){await p.locator('[data-nav=today]').click();await p.locator('[data-screen=today] [data-go=trips]').click()}
   else await p.locator('[data-nav='+screen+']').click();
   const overflow=await p.locator('#terra-desk-v2').evaluate(root=>{const b=root.getBoundingClientRect();return [...root.querySelectorAll('button,input,textarea,h2,h3,p,dd,dt,small,.td-money,.td-task-title')].filter(el=>el.getClientRects().length).filter(el=>{const r=el.getBoundingClientRect();return r.left<b.left-1||r.right>b.right+1}).map(el=>({id:el.id,text:el.textContent.slice(0,45)}))});
   if(overflow.length)issues.push({type:'layout',width,color,screen,overflow});
   if(captures.has(`${width}-${color}-${screen}`))await p.screenshot({path:path.join(out,`${width}-${color}-${screen}.png`),fullPage:true});
  }
 }
 pass('Геометрия: 60 сочетаний экранов, ширин и тем',`${issues.length} отклонений`);
 for(const width of [320,390,1024,1280]){
  await p.setViewportSize({width,height:1100});await p.reload({waitUntil:'domcontentloaded'});
  await p.evaluate(()=>{const sizes=[...document.querySelectorAll('#terra-desk-v2,#terra-desk-v2 *')].map(el=>[el,parseFloat(getComputedStyle(el).fontSize)]);sizes.forEach(([el,size])=>el.style.fontSize=2*size+'px')});
  for(const screen of ['today','trips','trip','inbox','finance']){
   if(screen==='trip'){await p.locator('[data-nav=today]').click();await p.locator('[data-screen=today] [data-trip=B]').click()}
   else if(screen==='trips'){await p.locator('[data-nav=today]').click();await p.locator('[data-screen=today] [data-go=trips]').click()}
   else await p.locator('[data-nav='+screen+']').click();
   const large=await p.locator('#terra-desk-v2').evaluate(root=>[...root.querySelectorAll('button,h2,h3,.td-task-title,.td-money,.td-nav-bottom')].filter(el=>el.getClientRects().length&&el.scrollWidth>el.clientWidth+2).map(el=>({text:el.textContent.trim(),width:el.clientWidth,scroll:el.scrollWidth})));
   if(large.length)issues.push({type:'text200',width,screen,overflow:large});
   if(width===1280&&screen==='today')await p.screenshot({path:path.join(out,'text200.png'),fullPage:true});
   if(width===320&&screen==='finance')await p.screenshot({path:path.join(out,'320-text200.png'),fullPage:true});
  }
 }
 pass('Увеличение текста вдвое: 20 сочетаний',`${issues.filter(i=>i.type==='text200').length} отклонений`);
}
async function main(){
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const base='http://127.0.0.1:'+server.address().port;const browser=await chromium.launch(launch);
 try{
  const c=await browser.newContext({viewport:{width:1280,height:1000},locale:'ru-RU',colorScheme:'light'});await c.addInitScript(mocks);const p=await c.newPage(),f=await open(p,base,true);assert.match(await f.locator('#td-storage-state').innerText(),/в памяти/);pass('Iframe: честное предупреждение об отсутствии хранилища');await workflow(f);
  const d=await c.newPage();await open(d,base+'/direct');assert.match(await d.locator('#td-storage-state').innerText(),/на устройстве/);await workflow(d);
  await d.locator('[data-nav=inbox]').click();await d.locator('.td-thread[data-chat=B]').click();await d.reload({waitUntil:'domcontentloaded'});assert.equal(await d.locator('#td-reply').inputValue(),'Проба ответа B');assert(await d.locator('[data-screen=inbox]').isVisible());pass('Перезагрузка: выбранный диалог и ответ восстановлены');
  const d2=await c.newPage();await open(d2,base+'/direct');await d2.locator('#td-reply').fill('Другая вкладка');await d.reload({waitUntil:'domcontentloaded'});assert.equal(await d.locator('#td-reply').inputValue(),'Проба ответа B');await d2.reload({waitUntil:'domcontentloaded'});assert.equal(await d2.locator('#td-reply').inputValue(),'Другая вкладка');pass('Две вкладки: независимые копии контекста');
  await d.evaluate(()=>{const id=sessionStorage.getItem('terratrans-concept-v2:tab');localStorage.setItem('terratrans-concept-v2:workspace:'+id,'{broken')});await d.reload({waitUntil:'domcontentloaded'});assert(await d.locator('[data-screen=today]').isVisible());pass('Повреждённая копия: безопасное начало');
  await d.evaluate(()=>{const key='terratrans-concept-v2:workspace:'+sessionStorage.getItem('terratrans-concept-v2:tab'),v=JSON.parse(localStorage.getItem(key));v.at=Date.now()-25*3600000;v.state.page='inbox';localStorage.setItem(key,JSON.stringify(v))});await d.reload({waitUntil:'domcontentloaded'});assert(await d.locator('[data-screen=today]').isVisible());pass('Истёкшие 24 часа: старая копия не загружена');
  await d.locator('[data-nav=trips]').focus();await d.keyboard.press('Enter');assert(await d.locator('[data-screen=trips]').isVisible());await d.locator('#td-search').focus();await d.keyboard.press('Tab');assert.equal(await d.evaluate(()=>document.activeElement.id),'td-attention');await d.keyboard.press('Space');assert.equal(await d.locator('#td-attention').getAttribute('aria-pressed'),'true');pass('Клавиатура: Enter, Tab, Space');await layout(d);await c.close();
  const touch=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const tp=await touch.newPage();await open(tp,base+'/direct');
  for(const screen of ['today','trips','trip','inbox','finance']){
   if(screen==='trip'){await tp.locator('[data-nav=today]').click();await tp.locator('[data-screen=today] [data-trip=B]').click()}
   else if(screen==='trips'){await tp.locator('[data-nav=today]').click();await tp.locator('[data-screen=today] [data-go=trips]').click()}
   else await tp.locator('[data-nav='+screen+']').click();
   const short=await tp.locator('#terra-desk-v2 button:visible').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().height<43.9).map(e=>({id:e.id,text:e.textContent.trim(),height:e.getBoundingClientRect().height})));
   if(short.length)issues.push({type:'touch-target',screen,short});
  }pass('Сенсорный экран: высота действий',`${issues.filter(i=>i.type==='touch-target').length} экранов с отклонениями`);await touch.close();
 }finally{await browser.close()}
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'terratrans-qa-'));let pc;
 try{pc=await chromium.launchPersistentContext(profile,launch);let p=await pc.newPage();await open(p,base+'/direct');await p.locator('[data-nav=inbox]').click();await p.locator('.td-thread[data-chat=B]').click();await p.locator('#td-reply').fill('После закрытия браузера');await pc.close();pc=await chromium.launchPersistentContext(profile,launch);p=await pc.newPage();await open(p,base+'/direct');assert(await p.locator('[data-screen=inbox]').isVisible());assert.equal(await p.locator('#td-reply').inputValue(),'После закрытия браузера');pass('Полное закрытие браузера и новый запуск: восстановление')}finally{if(pc)await pc.close();fs.rmSync(profile,{recursive:true,force:true})}
}
main().catch(e=>{issues.push({type:'failure',message:e.stack});console.error(e)}).finally(()=>{fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({results,issues,errors},null,2));console.log(JSON.stringify({passed:results.length,issues,errors}));server.close();process.exitCode=issues.length||errors.length?1:0});
