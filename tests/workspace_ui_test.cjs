const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const http=require('node:http');
const {spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const prefix='panel-workspace:v1:synthetic-login-';
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'panel-workspace-test-'));
let user=1;
const threads=[1,2].map(id=>({id,contact_name:'Тестовый диалог '+id,external_chat_id:'fixture-'+id,channel:'whatsapp',status:'open',priority:'normal'}));
const server=http.createServer((req,res)=>{
    const url=new URL(req.url,'http://localhost');
    if(url.pathname.startsWith('/assets/')){
        if(!/^\/assets\/(panel|workspace|schedules|notifications)\.(js|css)$/.test(url.pathname)){res.statusCode=204;return res.end();}
        res.setHeader('Content-Type',url.pathname.endsWith('.js')?'text/javascript':'text/css');return res.end(fs.readFileSync(path.join(root,'public',url.pathname)));
    }
    if(url.pathname!=='/'){res.statusCode=204;return res.end();}
    const html=spawnSync('php',[path.join(__dirname,'workspace_fixture.php')],{input:JSON.stringify({query:Object.fromEntries(url.searchParams),user}),encoding:'utf8'});
    if(html.status!==0){res.statusCode=500;return res.end(html.stderr);}
    res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html.stdout);
});
(async()=>{
    await new Promise(r=>server.listen(0,'127.0.0.1',r));
    const base=`http://127.0.0.1:${server.address().port}`;
    let context;
    const errors=[],checks=[],sends=[];
    const launch=async()=>{
        context=await chromium.launchPersistentContext(profile,{headless:true,viewport:{width:1360,height:1000},...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
        context.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
        for(const p of context.pages())p.on('pageerror',e=>errors.push(e.message));
        await context.route('**/*',async route=>{
            const u=new URL(route.request().url());assert.equal(u.origin,base,'External request blocked');
            if(!u.searchParams.has('a'))return route.continue();
            const action=u.searchParams.get('a'),data=route.request().postDataJSON()||{};
            let json={ok:true};
            if(action==='bootstrap')json={ok:true,users:[],current_user_id:user};
            if(action==='threads')json={ok:true,threads};
            if(action==='messages')json=data.conversation_id===999?{ok:false,error:'Диалог недоступен'}:{ok:true,conversation:threads.find(t=>t.id===data.conversation_id),messages:[],events:[]};
            if(action==='chat.send'){sends.push(data);json={ok:false,error:'Synthetic send disabled'};}
            return route.fulfill({status:action==='messages'&&data.conversation_id===999?403:200,json});
        });
        return context.pages()[0]||context.newPage();
    };
    try {
        let page=await launch();
        const flush=async(p=page)=>{await p.waitForFunction(()=>!!window.panelWorkspace);assert(await p.evaluate(()=>panelWorkspace.flush()),'snapshot saved');};
        const open=async(id,p=page)=>{await p.evaluate(id=>chatOpen(id),id);await p.waitForFunction(()=>!document.querySelector('#chatText').disabled);};
        const draft=()=>page.locator('#chatText');
        await page.goto(base+'/?p=chats');await page.locator('.chat-thread').first().waitFor();
        await open(1);await draft().fill('Личный черновик A');await flush();
        await flush(); // A no-op save must not prevent subsequent saves.
        await draft().fill('Личный черновик A, версия 2');await flush();
        const raw=await page.evaluate(k=>localStorage.getItem(k),prefix+1);
        assert(raw && !raw.includes('Личный') && !raw.includes('text') && !raw.includes('Тестовый'));
        await page.locator('.nav-item').filter({hasText:'Ведомости'}).click();
        await page.locator('.nav-item').filter({hasText:'Чаты'}).click();
        await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Личный черновик A, версия 2');
        assert.equal(sends.length,0);checks.push('encrypted snapshot, no-op flush, cross-section return without sending');
        await flush();await page.goto(base+'/');await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Личный черновик A, версия 2');
        checks.push('root app launch resumes work even when the tab session survives');

        await page.evaluate(()=>{chatSetQueue('pending');chatSetChannel('whatsapp');});
        await page.locator('#chatSearch').fill('Поиск тест');await page.locator('#chatSearch').dispatchEvent('input');await flush();
        await page.reload();await page.waitForFunction(()=>!document.querySelector('#chatText')?.disabled);
        assert.equal(await draft().inputValue(),'Личный черновик A, версия 2');
        assert.equal(await page.locator('#chatSearch').inputValue(),'Поиск тест');
        assert.equal(await page.evaluate(()=>chat.queue),'pending');assert.equal(await page.evaluate(()=>chat.channelFilter),'whatsapp');
        await open(2);await draft().fill('Черновик B');await flush();await page.goBack();
        await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Личный черновик A, версия 2');
        await page.goForward();await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Черновик B');
        checks.push('reload restores chat filters/drafts; Back/Forward selects original objects');
        for(const channel of ['sms','email']){
            await page.evaluate(channel=>chatSetChannel(channel),channel);await flush();await page.reload();
            await page.waitForFunction(()=>!document.querySelector('#chatText')?.disabled);
            assert.equal(await page.evaluate(()=>chat.channelFilter),channel,'Every supported channel restores');
        }
        checks.push('SMS and email channel filters survive reload');


        await page.goto(base+'/?p=contacts&q=Тест&sort=name');await flush();
        await page.locator('.nav-item').filter({hasText:'Ведомости'}).click();
        await page.locator('.nav-item').filter({hasText:'Контакты'}).click();
        assert.equal(new URL(page.url()).searchParams.get('q'),'Тест');
        await page.getByRole('link',{name:'Сбросить',exact:true}).click();
        assert.equal(new URL(page.url()).searchParams.get('q'),null);await flush();
        await page.goto(base+'/?p=manifests');await page.waitForTimeout(250);await page.evaluate(()=>scrollTo(0,850));await page.waitForTimeout(250);await flush();
        await page.goto(base+'/?p=chats&conversation_id=1');await page.locator('#chatText').waitFor();
        await page.locator('.nav-item').filter({hasText:'Ведомости'}).click();
        await page.waitForURL('**/?p=manifests');await page.waitForFunction(()=>scrollY>800);checks.push('URL filters, explicit reset and page scroll across sections');

        await page.goto(base+'/?p=chats&conversation_id=1');await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);await flush();
        const other=await context.newPage();await other.goto(base+'/?p=chats&conversation_id=2');await other.waitForFunction(()=>!document.querySelector('#chatText').disabled);
        await other.locator('#chatText').fill('Вкладка B отдельно');await flush(other);
        await page.reload();await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);
        assert.equal(await draft().inputValue(),'Личный черновик A, версия 2');
        assert.equal(await page.evaluate(()=>chat.conversationId),1);checks.push('independent active tabs survive reload without overwriting each other');

        // Force a fresh browser process/profile reopen, not just a page reload.
        await other.close();await draft().fill('После полного перезапуска');await flush();
        await context.close();page=await launch();await page.goto(base+'/');
        await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='После полного перезапуска');
        assert.equal(await page.evaluate(()=>chat.conversationId),1);checks.push('full browser process restart restores last workspace and draft');

        await page.goto(base+'/?p=chats&conversation_id=2');await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);
        assert.equal(await page.evaluate(()=>chat.conversationId),2);assert.notEqual(await draft().inputValue(),'После полного перезапуска');
        await page.evaluate(()=>{const c=panelWorkspace.getChat();c.drafts.push({id:999,text:'Черновик недоступного диалога',revision:1,error:''});panelWorkspace.setChat(c);});await flush();
        await page.goto(base+'/?p=chats&conversation_id=999');await page.getByText('Диалог недоступен',{exact:false}).waitFor();
        assert(await draft().isDisabled());assert.equal(await draft().inputValue(),'');
        await page.goto(base+'/?p=manifest&id=999');await page.getByRole('heading',{name:'Объект недоступен'}).waitFor();
        await page.getByRole('link',{name:'Вернуться к списку'}).click();assert.equal(new URL(page.url()).searchParams.get('p'),'manifests');
        checks.push('explicit object wins; denied chat reveals no draft; missing object has a return path');

        await page.goto(base+'/?p=chats&conversation_id=1');await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);await flush();
        // Logout must clear this login's key scope and invalidate other open tabs.
        const peer=await context.newPage();await peer.goto(base+'/?p=contacts');
        await page.locator('.user-out').click();await page.waitForURL('**/?p=login');await peer.waitForURL('**/?p=login');
        assert.equal(await page.evaluate(k=>localStorage.getItem(k),prefix+1),null);
        user=2;await page.goto(base+'/?p=chats&conversation_id=1');await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);
        assert.equal(await draft().inputValue(),'');checks.push('logout erases snapshot, other tabs exit, another login has no old draft');

        await page.evaluate(k=>{sessionStorage.setItem(k,'broken');localStorage.setItem(k,'broken');},prefix+2);
        await page.reload();await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);assert.equal(await draft().inputValue(),'');
        checks.push('corrupted copy fails closed without a runtime error');
        // Encrypt an expired synthetic snapshot with the fixture key.
        await page.evaluate(async k=>{
            const key=await crypto.subtle.importKey('raw',new Uint8Array(32).fill(2),'AES-GCM',false,['encrypt']);
            const iv=crypto.getRandomValues(new Uint8Array(12));const scope='synthetic-login-2';
            const plain=new TextEncoder().encode(JSON.stringify({v:1,at:Date.now()-8*86400000,last:'/?p=chats&conversation_id=1',chat:{drafts:[{id:1,text:'Просроченный текст'}]}}));
            const enc=await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:new TextEncoder().encode(scope)},key,plain);
            const b64=b=>btoa(String.fromCharCode(...new Uint8Array(b)));
            const raw=JSON.stringify({iv:b64(iv),data:b64(enc)});sessionStorage.setItem(k,raw);localStorage.setItem(k,raw);
        },prefix+2);
        await page.reload();await page.waitForFunction(()=>!document.querySelector('#chatText').disabled);assert.equal(await draft().inputValue(),'');checks.push('expired encrypted copy is not restored');
        await draft().fill('Длинный текст '.repeat(1800));await flush();await page.reload();
        await page.waitForFunction(()=>document.querySelector('#chatText')?.value.length>20000);
        assert.equal(await draft().inputValue(),'Длинный текст '.repeat(1800));
        await draft().fill('');await flush();checks.push('long draft round-trip without silent truncation');
        await page.evaluate(()=>{const c=panelWorkspace.getChat();c.drafts=c.drafts.filter(d=>d.id!==1);c.drafts.push({id:1,text:'Текст незавершённой отправки',revision:1,error:'',pending:true});panelWorkspace.setChat(c);});await flush();
        await page.reload();await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Текст незавершённой отправки');
        assert.match(await page.locator('#chatChannelNote').innerText(),/Результат предыдущей отправки неизвестен/);assert.equal(sends.length,0);
        checks.push('restored pending send shows uncertainty and never resends');
        await page.evaluate(()=>{window.fixtureSetItem=Storage.prototype.setItem;Storage.prototype.setItem=function(){throw new DOMException('Full','QuotaExceededError');};});
        await draft().fill('Новый текст при переполнении');assert.equal(await page.evaluate(()=>panelWorkspace.flush()),false);
        assert.equal(await draft().inputValue(),'Новый текст при переполнении');await page.getByText(/Не удалось сохранить рабочее место/).waitFor();
        await page.evaluate(()=>{Storage.prototype.setItem=window.fixtureSetItem;delete window.fixtureSetItem;});await flush();
        await page.reload();await page.waitForFunction(()=>document.querySelector('#chatText')?.value==='Новый текст при переполнении');
        checks.push('write quota failure preserves input and recovers without losing the next edit');
        const switched=await context.newPage();user=3;await switched.goto(base+'/?p=chats&conversation_id=1');
        await switched.waitForFunction(()=>!document.querySelector('#chatText').disabled);await page.waitForURL('**/?p=login');
        assert.equal(await switched.locator('#chatText').inputValue(),'');
        await page.close();page=switched;checks.push('new login invalidates an older active tab without exposing its draft');

        const blocked=await context.newPage();await blocked.addInitScript(()=>{Storage.prototype.setItem=function(){throw new DOMException('Blocked','SecurityError');};});
        await blocked.goto(base+'/?p=chats&conversation_id=1');await blocked.waitForFunction(()=>!document.querySelector('#chatText').disabled);
        await blocked.locator('#chatText').fill('Текст без хранилища');
        await blocked.getByText(/Не удалось сохранить рабочее место/).waitFor();
        assert.equal(await blocked.locator('#chatText').inputValue(),'Текст без хранилища');
        assert(await blocked.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;}));
        checks.push('unavailable storage preserves input and retains leave warning');
        await blocked.close();
        await page.setViewportSize({width:390,height:844});await page.emulateMedia({reducedMotion:'reduce'});
        assert(await page.locator('#chatText').isVisible());assert.equal(sends.length,0);
        await page.waitForFunction(()=>document.querySelector('#chatDraftHint').getBoundingClientRect().bottom<=document.querySelector('.bottombar').getBoundingClientRect().top);
        const composer=await page.locator('#chatForm').boundingBox(), nav=await page.locator('.bottombar').boundingBox();
        assert(composer.y+composer.height<=nav.y,'Composer must stay above bottom navigation');
        const hint=await page.locator('#chatDraftHint').boundingBox();assert(hint.y+hint.height<=nav.y,'Draft hint must stay visible');
        checks.push('390px composer and wrapped state remain above bottom navigation');
        if(process.env.WORKSPACE_SCREENSHOT_DIR){fs.mkdirSync(process.env.WORKSPACE_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.WORKSPACE_SCREENSHOT_DIR,'workspace-mobile.png'),fullPage:true});}
        assert.deepEqual(errors,[]);
        console.log(JSON.stringify({ok:true,checks,pageErrors:errors,externalSends:sends.length},null,2));
    }finally{await context?.close();server.close();fs.rmSync(profile,{recursive:true,force:true});}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
