// All API traffic is intercepted. No production data or external sends.
// NODE_PATH=<playwright modules> CHROME_PATH=<chrome binary> node tests/save_draft_ui_test.cjs
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const stored = {manifest:{},passenger:{}};
const server = http.createServer((req,res) => {
    const url = new URL(req.url,'http://localhost');
    if (['/assets/panel.js','/assets/panel.css','/assets/notifications.js'].includes(url.pathname)) {
        res.setHeader('Content-Type',url.pathname.endsWith('.js')?'text/javascript':'text/css');
        return res.end(fs.readFileSync(path.join(root,'public',url.pathname)));
    }
    if (url.pathname === '/favicon.ico') {res.statusCode=204;return res.end();}
    if (url.searchParams.has('a')) {res.statusCode=500;return res.end('API must be mocked');}
    const html = spawnSync('php',[path.join(__dirname,'save_draft_fixture.php'),url.searchParams.get('p') || 'manifest'],{input:JSON.stringify(stored),encoding:'utf8'});
    if (html.status !== 0) {res.statusCode=500;return res.end(html.stderr);}
    res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(html.stdout);
});
const pause = ms => new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const base=`http://127.0.0.1:${server.address().port}`;
    const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
    const checks=[], errors=[], requests=[], held=[];
    let holdMessages=false, failMarkread=false;
    const threads=[1,2].map(id=>({id,contact_name:'Диалог '+id,external_chat_id:'synthetic-'+id,channel:id===1?'whatsapp':'max',status:'open',priority:'normal'}));
    try {
        const page=await browser.newPage({viewport:{width:1360,height:1000},locale:'ru-RU'});
        page.on('pageerror',e=>errors.push(e.message));
        page.on('dialog',d=>d.accept());
        await page.route('**/*',async route=>{
            const u=new URL(route.request().url());
            assert.equal(u.origin,base,'No external request is allowed');
            if (!u.searchParams.has('a')) return route.continue();
            const action=u.searchParams.get('a'), data=route.request().postDataJSON() || {};
            const req={action,data,route};requests.push(req);
            if (['passenger.update','manifest.update','chat.send'].includes(action) || (action==='messages' && holdMessages)) {held.push(req);return;}
            let result={ok:true};
            if(action==='bootstrap') result={ok:true,users:[],current_user_id:1};
            else if(action==='threads') result={ok:true,threads};
            else if(action==='messages') result=messages(data.conversation_id);
            else if(action==='notification.overview') result={ok:false,error:'Synthetic overview unavailable'};
            else if(action==='markread' && failMarkread) return route.abort('failed');
            await route.fulfill({json:result});
        });
        function messages(id,name) {return {ok:true,conversation:{...threads.find(t=>t.id===id),...(name?{contact_name:name}:{})},messages:[],events:[]};}
        async function next(action) {
            const end=Date.now()+5000;
            while(Date.now()<end) {const i=held.findIndex(r=>r.action===action);if(i>=0)return held.splice(i,1)[0];await pause(10);}
            throw Error('Timed out waiting for '+action);
        }
        async function answer(req,json={ok:true},status=200) {
            if(json?.ok===true && status===200 && req.action.endsWith('.update')) {
                const kind=req.action.split('.')[0];stored[kind][req.data.field]=req.data.value;
            }
            await req.route.fulfill({status,json});
        }
        const status=page.locator('#saveState'), field=f=>page.locator('#tripFacts [data-f="'+f+'"]');
        const saved=()=>page.waitForFunction(()=>document.querySelector('#saveState').textContent==='Все изменения сохранены');
        await page.goto(base+'/?p=manifest');
        await field('bus').fill('Автобус A');
        assert.match(await status.innerText(),/несохранённые/);
        let r=await next('manifest.update');
        assert.deepEqual(r.data,{id:1,field:'bus',value:'Автобус A'});
        assert.match(await status.innerText(),/Сохраняю/);
        await answer(r);await saved();await page.reload();
        assert.equal(await field('bus').inputValue(),'Автобус A');checks.push('save and subsequent read');

        await field('bus').fill('Отклонённое значение');r=await next('manifest.update');
        await answer(r,{ok:false,error:'Тестовый отказ'});
        await page.getByText(/Не сохранено полей: 1/).waitFor();
        assert.equal(await field('bus').inputValue(),'Отклонённое значение');
        assert.equal(await field('bus').getAttribute('aria-invalid'),'true');
        await page.locator('#saveRetry').focus();await page.keyboard.press('Enter');
        await answer(await next('manifest.update'));await saved();checks.push('application failure, retained input, keyboard retry');

        for (const kind of ['offline','expired','malformed','null']) {
            await field('bus').fill('Проверка '+kind);r=await next('manifest.update');
            if(kind==='offline') await r.route.abort('internetdisconnected');
            if(kind==='expired') await answer(r,{ok:false},403);
            if(kind==='malformed') await r.route.fulfill({status:502,body:'not JSON'});
            if(kind==='null') await answer(r,null);
            await page.getByText(/Не сохранено полей: 1/).waitFor();
            assert.equal(await field('bus').inputValue(),'Проверка '+kind);
            await page.locator('#saveRetry').click();await answer(await next('manifest.update'));await saved();
        }
        checks.push('offline, expired session, invalid JSON and null responses');

        await field('bus').fill('Первая версия');const first=await next('manifest.update');
        await field('bus').fill('Промежуточная');await field('bus').fill('Последняя версия');
        await pause(650);assert.equal(held.length,0,'same-field writes must not overlap');
        await answer(first);const latest=await next('manifest.update');
        assert.equal(latest.data.value,'Последняя версия');assert.doesNotMatch(await status.innerText(),/Все изменения сохранены/);
        await answer(latest);await saved();assert.equal(stored.manifest.bus,'Последняя версия');
        checks.push('serialized writes and coalesced new edits');

        await field('bus').fill('Ошибка A');const a=await next('manifest.update');
        await field('route').fill('Успех B');const b=await next('manifest.update');
        await answer(a,{ok:false,error:'Ошибка A'});await answer(b);
        await page.getByText(/Не сохранено полей: 1/).waitFor();
        assert.doesNotMatch(await status.innerText(),/Все изменения сохранены/);
        await page.locator('#saveRetry').click();await answer(await next('manifest.update'));await saved();
        await field('bus').fill('Медленный A');const slow=await next('manifest.update');
        await field('route').fill('Быстрый B');await answer(await next('manifest.update'));
        assert.doesNotMatch(await status.innerText(),/Все изменения сохранены/);await answer(slow);await saved();
        checks.push('aggregate status with failures and reordered independent replies');

        await page.locator('#ptable [data-f="name"]').fill('Новый тестовый пассажир');
        r=await next('passenger.update');assert.deepEqual(r.data,{id:11,field:'name',value:'Новый тестовый пассажир'});
        await answer(r);await saved();
        await page.evaluate(()=>{bindCells();bindCells();});
        await field('bus').fill('Одно сохранение');await answer(await next('manifest.update'));await saved();await pause(600);assert.equal(held.length,0);
        assert.equal(await page.evaluate(()=>{const original=bindTripFacts;let n=0;bindTripFacts=()=>n++;bindCells();bindTripFacts=original;return n;}),1);
        checks.push('passenger identity, repeated binding, notifications binder ownership');

        await page.setViewportSize({width:390,height:844});await page.emulateMedia({reducedMotion:'reduce'});
        await field('bus').fill('Мобильная ошибка');await answer(await next('manifest.update'),{ok:false,error:'Длинное объяснение ошибки '.repeat(6)});
        await page.locator('#saveRetry').waitFor();
        const retryBox=await page.locator('#saveRetry').boundingBox();assert(retryBox.height>=44);assert(retryBox.x>=0 && retryBox.x+retryBox.width<=390);
        await page.locator('#saveRetry').click();await answer(await next('manifest.update'));await saved();
        checks.push('390px save feedback, touch target and reduced motion');
        if(process.env.SAVE_DRAFT_SCREENSHOT_DIR){fs.mkdirSync(process.env.SAVE_DRAFT_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SAVE_DRAFT_SCREENSHOT_DIR,'manifest-mobile.png'),fullPage:true});}

        // Load the real notification module before DOMContentLoaded, as layout.php does.
        await page.goto(base+'/?p=notifications');
        await field('bus').fill('Правка для подготовки');
        assert.equal(await page.evaluate(()=>NC.facts.get('bus')),'Правка для подготовки');
        r=await next('manifest.update');await answer(r,{ok:false,error:'Тестовый отказ'});
        await page.getByText(/Ошибка сохранения/).waitFor();
        await page.evaluate(()=>{window.flushResult=null;ncFlushFacts().then(ok=>window.flushResult=ok).catch(()=>window.flushResult=false);});
        await answer(await next('manifest.update'));await page.waitForFunction(()=>window.flushResult===true);
        assert.equal(await page.evaluate(()=>NC.facts.size),0);
        checks.push('real notification binder and flush barrier keep failed edits and retry');

        await page.setViewportSize({width:1360,height:1000});await page.goto(base+'/?p=chats');
        await page.locator('.chat-thread[data-id="1"]').waitFor();
        // A slow response must not be perpetually superseded by the 5-second poll.
        holdMessages=true;await page.evaluate(()=>{chatOpen(1);});const delayed=await next('messages');
        await pause(5300);assert.equal(held.filter(r=>r.action==='messages').length,0);
        await answer(delayed,messages(1));holdMessages=false;
        await page.waitForFunction(()=>!document.querySelector('#chatSendBtn').disabled);
        checks.push('slow history load is not starved by background polling');
        // Stop the routine poll to control ordering, then exercise its loader explicitly.
        await page.evaluate(()=>clearInterval(chat.poll));
        const input=page.locator('#chatText');
        async function open(id) {await page.evaluate(id=>chatOpen(id),id);await page.waitForFunction(()=>!document.querySelector('#chatSendBtn').disabled);}
        await open(1);await input.fill('Черновик A');await open(2);assert.equal(await input.inputValue(),'');
        await input.fill('Черновик B');await open(1);assert.equal(await input.inputValue(),'Черновик A');
        await page.evaluate(()=>chatCloseConv());await open(2);assert.equal(await input.inputValue(),'Черновик B');
        checks.push('A/B/A and close/reopen independent drafts');

        await open(1);await page.locator('#chatSendBtn').click();const sendA=await next('chat.send');
        await page.evaluate(()=>chatSend());assert.equal(held.length,0,'double submit prevented');
        assert.deepEqual(sendA.data,{conversation_id:1,text:'Черновик A'});
        await page.evaluate(()=>chatOpen(2));await input.fill('Новый черновик B');await input.focus();
        await answer(sendA);await page.waitForFunction(()=>!document.querySelector('#chatSendBtn').disabled);
        assert.equal(await input.inputValue(),'Новый черновик B');assert.equal(await page.evaluate(()=>document.activeElement.id),'chatText');
        await open(1);assert.equal(await input.inputValue(),'');checks.push('captured recipient, double-submit guard, late success preserves B');

        await input.fill('Отправляемая версия');await input.press('Enter');const sendOld=await next('chat.send');
        await input.fill('Новая версия');await answer(sendOld);await page.waitForFunction(()=>!document.querySelector('#chatSendBtn').disabled);
        assert.equal(await input.inputValue(),'Новая версия');
        await input.press('Shift+Enter');assert.match(await input.inputValue(),/\n/);
        checks.push('late success preserves newer text, Enter sends and Shift+Enter edits');

        await input.fill('Текст при ошибке');await page.locator('#chatSendBtn').click();const failA=await next('chat.send');
        await page.evaluate(()=>chatOpen(2));await answer(failA,{ok:false,error:'Тестовый отказ отправки'});
        await page.waitForFunction(()=>!document.querySelector('#chatSendBtn').disabled);
        assert.equal(await page.locator('#chatChannelNote').innerText(),'');assert.equal(await input.inputValue(),'Новый черновик B');
        await open(1);assert.equal(await input.inputValue(),'Текст при ошибке');assert.match(await page.locator('#chatChannelNote').innerText(),/Проверьте историю/);
        const count=requests.filter(r=>r.action==='chat.send').length;await pause(700);assert.equal(requests.filter(r=>r.action==='chat.send').length,count);
        await page.locator('#chatSendBtn').click();const retryA=await next('chat.send');assert.equal(retryA.data.text,'Текст при ошибке');
        await retryA.route.abort('timedout');await page.getByText(/Проверьте историю перед/).waitFor();
        assert.equal(await input.inputValue(),'Текст при ошибке');checks.push('scoped send error, explicit retry, ambiguous timeout keeps draft without auto-send');

        holdMessages=true;
        await page.evaluate(()=>{chatOpen(1);});const oldA=await next('messages');
        await page.evaluate(()=>{chatOpen(2);});const oldB=await next('messages');
        await page.evaluate(()=>{chatOpen(1);});const newA=await next('messages');
        await answer(newA,messages(1,'Актуальный A'));await answer(oldA,messages(1,'Устаревший A'));
        await oldB.route.abort('failed');await pause(100);
        assert.equal(await page.locator('#chatName').innerText(),'Актуальный A');assert.doesNotMatch(await page.locator('#chatBody').innerText(),/Failed|fetch/);
        await page.evaluate(()=>{chatLoadMessages();});const pollOld=await next('messages');
        await page.evaluate(()=>{chatLoadMessages();});const pollNew=await next('messages');
        await answer(pollNew,messages(1,'Свежий ответ'));await answer(pollOld,messages(1,'Старый ответ'));await pause(100);
        assert.equal(await page.locator('#chatName').innerText(),'Свежий ответ');holdMessages=false;
        failMarkread=true;await open(2);failMarkread=false;
        assert.equal(await page.locator('#chatName').innerText(),'Диалог 2');checks.push('A/B/A stale loads, stale failure, reverse poll replies, markread failure');

        await page.setViewportSize({width:390,height:844});await open(1);
        await input.fill('Мобильный черновик');await page.locator('.chat-back').click();await open(1);
        assert.equal(await input.inputValue(),'Мобильный черновик');
        const box=await input.boundingBox();assert(box.x>=0 && box.x+box.width<=390);
        assert.equal(await input.getAttribute('aria-label'),'Сообщение');
        const prevented=await page.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;});
        assert(prevented,'memory-only unsaved work warns before leaving');
        if(process.env.SAVE_DRAFT_SCREENSHOT_DIR){fs.mkdirSync(process.env.SAVE_DRAFT_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SAVE_DRAFT_SCREENSHOT_DIR,'chat-mobile.png'),fullPage:true});}
        checks.push('mobile back/restore, accessible composer, unload warning');
        assert.deepEqual(errors,[]);console.log(JSON.stringify({ok:true,checks,pageErrors:errors},null,2));
    } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
