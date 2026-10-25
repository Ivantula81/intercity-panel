const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),checks=[],errors=[],writes=[];
const threads=[{id:1,contact_name:'Тестовый диалог A',channel:'max',manifest_id:65,trip_number:'T-022',route:'Москва → Тестовый город',status:'open',priority:'normal',external_chat_id:'synthetic'},{id:2,contact_name:'Тестовый диалог B',channel:'telegram',manifest_id:64,status:'resolved',priority:'normal',external_chat_id:'synthetic2'}];
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://localhost');
 if(u.pathname.startsWith('/assets/')){if(!/^\/assets\/[\w-]+\.(js|css)$/.test(u.pathname)){res.statusCode=204;return res.end();}const file=path.join(root,'public',u.pathname);res.setHeader('Content-Type',u.pathname.endsWith('.js')?'text/javascript':'text/css');return res.end(fs.existsSync(file)?fs.readFileSync(file):'');}
 if(u.pathname!=='/'){res.statusCode=204;return res.end();}
 const r=spawnSync('php',[path.join(__dirname,'trip_workspace_fixture.php')],{input:JSON.stringify({query:Object.fromEntries(u.searchParams)}),encoding:'utf8'});
 res.setHeader('Content-Type','text/html; charset=utf-8');res.statusCode=r.status===0?200:500;res.end(r.status===0?r.stdout:r.stderr);
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
 try{
 const context=await browser.newContext({viewport:{width:1360,height:950},reducedMotion:'reduce'});
 context.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
 await context.route('**/*',async route=>{const u=new URL(route.request().url());assert.equal(u.origin,base,'no external traffic');if(!u.searchParams.has('a'))return route.continue();const action=u.searchParams.get('a'),data=route.request().postDataJSON()||{};
 let json={ok:true};if(action==='bootstrap')json={ok:true,users:[],current_user_id:1};if(action==='threads')json={ok:true,threads};if(action==='messages')json={ok:true,conversation:threads.find(t=>t.id===data.conversation_id),messages:[],events:[]};if(['manifest.update','passenger.update','chat.send'].includes(action)){writes.push({action,data});json={ok:false,error:'Тестовый отказ: изменения не сохранены'};}return route.fulfill({json});});
 const page=await context.newPage();page.setDefaultTimeout(12000);const report=checks.push.bind(checks);checks.push=(message)=>{console.error(message);return report(message);};const flush=async()=>{assert(await page.evaluate(()=>panelWorkspace.flush()));};
 const tab=async name=>{await page.getByRole('tab',{name}).click();await flush();};
 await page.goto(base+'/?p=manifests&q=Москва&page=2');await page.getByText('Показано 31–60 из 65').waitFor();await flush();
 await page.locator('.trip-route').first().click();await tab('Сообщения · 0');await page.getByText('Связанных диалогов пока нет.',{exact:false}).waitFor();
 await page.locator('[data-trip-list-back]').click();assert.equal(new URL(page.url()).searchParams.get('page'),'2');assert.equal(new URL(page.url()).searchParams.get('q'),'Москва');checks.push('search/page/card/explicit list return');
 await page.goto(base+'/?p=manifest&id=65');await tab('Сообщения · 1');await page.getByRole('link',{name:'Открыть диалог',exact:true}).click();
 await page.locator('#chatText:not([disabled])').waitFor();await page.locator('#chatText').fill('Черновик тестового пассажира');await flush();
 assert.equal(new URL(page.url()).searchParams.get('return_manifest'),'65');await page.locator('#tripChatReturn').click();await page.getByRole('tab',{name:'Сообщения · 1',selected:true}).waitFor();
 await page.getByRole('link',{name:'Открыть диалог',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Черновик тестового пассажира');checks.push('linked conversation, origin return, saved draft without send');
 await page.evaluate(()=>chatOpen(2));await page.locator('#chatText:not([disabled])').waitFor();assert.equal(new URL(page.url()).searchParams.get('return_manifest'),'65');await page.locator('#tripChatReturn').click();assert.equal(new URL(page.url()).searchParams.get('id'),'65');checks.push('conversation switch preserves origin without changing relationship');
 await tab('Пассажиры · 1');await page.reload();await page.getByRole('tab',{name:'Пассажиры · 1',selected:true}).waitFor();await page.goto(base+'/?p=manifest&id=64');await page.getByRole('tab',{name:'Подготовка',selected:true}).waitFor();await page.goto(base+'/?p=manifest&id=65');await page.getByRole('tab',{name:'Пассажиры · 1',selected:true}).waitFor();checks.push('separate per-object tabs and reload');
 await tab('Подготовка');await tab('Сообщения · 1');await page.goBack();await page.getByRole('tab',{name:'Подготовка',selected:true}).waitFor();await page.goForward();await page.getByRole('tab',{name:'Сообщения · 1',selected:true}).waitFor();checks.push('tab Back/Forward');
 await page.getByRole('tab',{name:'Сообщения · 1'}).focus();await page.keyboard.press('Home');await page.getByRole('tab',{name:'Подготовка',selected:true}).waitFor();await page.keyboard.press('ArrowRight');await page.getByRole('tab',{name:'Пассажиры · 1',selected:true}).waitFor();checks.push('keyboard tabs');
 await tab('Подготовка');await page.locator('[data-f=bus]').fill('Несохранённый автобус');await page.getByText('Тестовый отказ: изменения не сохранены',{exact:false}).waitFor();await tab('Сообщения · 1');await page.locator('#saveRetry').waitFor();await tab('Подготовка');assert.equal(await page.locator('[data-f=bus]').inputValue(),'Несохранённый автобус');checks.push('failed save remains visible across tabs, input retained');
 page.on('dialog',d=>d.accept());
 await page.goto(base+'/?p=manifests&q=несуществующий');await page.getByText('Ведомости не найдены.',{exact:false}).waitFor();await page.getByRole('link',{name:'Сбросить',exact:true}).click();await page.getByText('Показано 1–30 из 65').waitFor();checks.push('empty filter and explicit reset');
 const shots=process.env.TRIP_SCREENSHOT_DIR;if(shots)fs.mkdirSync(shots,{recursive:true});
 for(const width of [1360,390,320]){
   await page.setViewportSize({width,height:950});await page.goto(base+'/?p=manifests');await page.locator('.trip-route').first().waitFor();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'list fits '+width);
   if(shots)await page.screenshot({path:path.join(shots,`list-${width}.png`),fullPage:false});
   await page.locator('.trip-route').first().click();await tab('Подготовка');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'card fits '+width);
   if(shots)await page.screenshot({path:path.join(shots,`card-${width}.png`),fullPage:false});
   await page.locator('[data-f=extra_info]').scrollIntoViewIfNeeded();
   const extra=await page.locator('[data-f=extra_info]').boundingBox();assert(extra && extra.y>=0 && extra.y+extra.height<=950,'last editable field reachable');
   await tab('Пассажиры · 1');await page.locator('#ptable [data-f=name]').scrollIntoViewIfNeeded();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'passengers fit '+width);
   await tab('Сообщения · 1');await page.getByRole('link',{name:'Открыть диалог',exact:true}).click();await page.locator('#chatText:not([disabled])').waitFor();
   const composer=await page.locator('#chatText').boundingBox(), bottom=await page.locator('.bottombar').boundingBox();assert(composer && composer.height>0 && composer.y>=0 && composer.y+composer.height<=(bottom?bottom.y:950),'composer visible with origin link');
   await page.locator('#tripChatReturn').click();await tab('Подготовка');
 }
 checks.push('desktop/390/320, reduced motion, no page overflow');
 await page.setViewportSize({width:780,height:950});await page.evaluate(()=>document.documentElement.style.zoom='2');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));checks.push('200% zoom card');
 const other=await context.newPage();await other.goto(base+'/?p=manifest&id=65&tab=messages');await other.getByRole('tab',{name:'Сообщения · 1',selected:true}).waitFor();await page.reload();await page.getByRole('tab',{name:'Подготовка',selected:true}).waitFor();await other.close();checks.push('independent browser tabs and explicit tab precedence');
 await page.goto(base+'/?p=manifest&id=999');await page.getByText('Ведомость недоступна.',{exact:false}).waitFor();await page.getByRole('link',{name:'К списку',exact:true}).click();checks.push('missing object return');
 assert.equal(writes.filter(x=>x.action==='chat.send').length,0);assert.deepEqual(errors,[]);console.log(JSON.stringify({checks,errors,externalSends:0},null,2));
 }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
