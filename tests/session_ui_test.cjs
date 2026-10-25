// Real PHP sessions/authentication/controller; synthetic local SQLite users, no providers.
const {chromium,request}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const {spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),dir=fs.mkdtempSync(path.join(os.tmpdir(),'panel-session-'));
const env={...process.env,PANEL_SESSION_TEST_DIR:dir};
const init=spawnSync('php',['-r',`$p=new PDO('sqlite:'.getenv('PANEL_SESSION_TEST_DIR').'/users.db');$p->exec('CREATE TABLE users(id INTEGER,login TEXT,password_hash TEXT,name TEXT,role TEXT,active INTEGER,last_login TEXT)');$s=$p->prepare('INSERT INTO users VALUES(?,?,?,?,?,1,NULL)');foreach([1,2]as$id)$s->execute([$id,'fixture'.$id,password_hash('fixture-only-password',PASSWORD_DEFAULT),'Fixture '.$id,'operator']);`],{env});assert.equal(init.status,0,init.stderr.toString());
(async()=>{
 const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
 const base=`http://127.0.0.1:${port}`;const server=spawn('php',['-S',`127.0.0.1:${port}`,path.join(__dirname,'session_fixture.php')],{env,stdio:['ignore','ignore','pipe']});let serverLog='';server.stderr.on('data',s=>serverLog+=s);
 let browser,context;const checks=[],errors=[];let touches=0,saves=0;
 try{
  for(let i=0;i<60;i++){try{await fetch(base+'/?p=login');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});context=await browser.newContext({viewport:{width:1360,height:900}});
  context.setDefaultTimeout(7000);checks.push=function(v){console.log(v);return Array.prototype.push.call(this,v);};
  context.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.url().includes('a=touch'))touches++;if(r.url().includes('a=save'))saves++;});});
  const page=await context.newPage();
  async function login(p=page,user='fixture1'){await p.goto(base+'/?p=login');await p.locator('[name=login]').fill(user);await p.locator('[name=password]').fill('fixture-only-password');await p.getByRole('button',{name:'Войти',exact:true}).click();await p.waitForFunction(()=>!!window.panelSession&&!!window.panelWorkspace);}
  async function control(action,p=page){return p.evaluate(async action=>{const r=await fetch('/?p=fixture&a='+action,{method:'POST',headers:{'X-CSRF':window.CSRF}});return r.json();},action);}
  async function refresh(p=page){await p.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await p.waitForTimeout(200);}
  async function reauth(p=page,user='fixture1',password='fixture-only-password'){await p.locator('#sessionReauth [name=login]').fill(user);await p.locator('#sessionReauth [name=password]').fill(password);await p.locator('#sessionReauthButton').click();}
  await login();const firstStatus=await (await context.request.get(base+'/?p=session')).json();assert.equal(firstStatus.authenticated,true);assert(!('key'in firstStatus));
  const before=await control('inspect');const t=touches;await page.waitForTimeout(16000);const after=await control('inspect');assert.deepEqual(after.clock,before.clock);assert.equal(touches,t);checks.push('background status and application polls do not extend server idle clock');
  await page.locator('#bus').fill('Тестовый автобус');await page.locator('#draft').fill('Неотправленный текст');await page.waitForTimeout(300);assert(touches>t);checks.push('trusted employee input renews idle clock');
  await control('warning');await refresh();await page.locator('#sessionWarning').waitFor({state:'visible'});if(process.env.SESSION_SCREENSHOT)await page.screenshot({path:process.env.SESSION_SCREENSHOT.replace('mobile','warning')});await page.locator('#sessionContinue').click();await page.locator('#sessionWarning').waitFor({state:'hidden'});checks.push('two-minute warning and explicit continuation');
  await control('warning');await refresh();await page.locator('#sessionLoginEarly').click();assert.match(await page.locator('#sessionLockReason').textContent(),/новым сроком/);await reauth();await page.waitForFunction(()=>!panelSession.isLocked());checks.push('early credential renewal starts a new shift without claiming it already expired');
  await page.evaluate(()=>{panelWorkspace.setChat({drafts:[{id:1,text:'Сохранённый черновик',revision:1,error:''}]});});await page.evaluate(()=>panelWorkspace.flush());
  const scope=await page.evaluate(()=>localStorage.getItem('panel-workspace:active-login'));const encrypted=await page.evaluate(k=>localStorage.getItem('panel-workspace:v1:'+k),scope);assert(encrypted&&!encrypted.includes('Сохранённый'));
  const oldCookie=(await context.cookies()).find(c=>c.name==='PHPSESSID');
  await control('expire');await refresh();await page.waitForFunction(()=>panelSession.isLocked());assert.equal(await page.locator('#bus').evaluate(e=>getComputedStyle(e).visibility),'hidden');
  await page.keyboard.press('Escape');assert(await page.locator('#sessionLock').evaluate(e=>e.open));
  const oldSaves=saves;const blocked=await page.evaluate(async()=>({status:(await fetch('/?p=api&a=save',{method:'POST',headers:{'X-CSRF':CSRF}})).status}));assert.equal(blocked.status,401);assert.equal(saves,oldSaves);
  for(const p of ['api','chat_api','reporting_api']){const r=await context.request.post(base+'/?p='+p+'&a=save',{headers:{'X-CSRF':await page.evaluate(()=>CSRF)}});assert.equal(r.status(),401);assert.equal((await r.json()).auth_required,true);}
  assert.equal((await control('inspect')).mutations,0);checks.push('idle expiry blocks server APIs, keeps unsaved input, and cannot be dismissed with Escape');
  const missingCsrf=await context.request.post(base+'/?p=session&a=touch');assert.equal(missingCsrf.status(),419);
  const touch=await context.request.post(base+'/?p=session&a=touch',{headers:{'X-CSRF':await page.evaluate(()=>CSRF)}});assert.equal(touch.status(),401);checks.push('CSRF required and expired access cannot be revived by touch');
  await reauth(page,'fixture2');await page.locator('#sessionError').waitFor({state:'visible'});assert(await page.evaluate(()=>panelSession.isLocked()));
  await reauth(page,'fixture1','wrong');await page.locator('#sessionError').waitFor({state:'visible'});assert.equal(await page.locator('#sessionReauth [name=password]').inputValue(),'');
  await reauth();await page.waitForFunction(()=>!panelSession.isLocked());assert.equal(await page.locator('#bus').inputValue(),'Тестовый автобус');assert.equal(await page.locator('#draft').inputValue(),'Неотправленный текст');assert.equal(await page.evaluate(()=>localStorage.getItem('panel-workspace:active-login')),scope);assert.equal(saves,oldSaves);
  assert.notEqual((await context.cookies()).find(c=>c.name==='PHPSESSID').value,oldCookie.value);
  const attacker=await request.newContext({extraHTTPHeaders:{Cookie:'PHPSESSID='+oldCookie.value}});const stale=await attacker.post(base+'/?p=api&a=save');assert.equal(stale.status(),401);assert(!stale.headers()['set-cookie']);const revoked=await (await attacker.get(base+'/?p=session')).json();assert.equal(revoked.authenticated,false);await attacker.dispose();checks.push('same-user credential check rotates ID, revokes predecessor, preserves text/key, never replays mutation');
  await page.locator('#save').click();await page.waitForTimeout(200);assert.equal((await control('inspect')).mutations,1);checks.push('new CSRF works after reauthentication, explicit new save only');
  const peer=await context.newPage();await peer.goto(base+'/');await peer.waitForFunction(()=>!!window.panelSession);await peer.locator('#bus').fill('Вторая вкладка');await control('absolute');await refresh();await refresh(peer);await peer.waitForFunction(()=>panelSession.isLocked());await page.waitForFunction(()=>panelSession.isLocked());
  await reauth();await page.waitForFunction(()=>!panelSession.isLocked());await refresh(peer);await peer.waitForFunction(()=>!panelSession.isLocked());assert.equal(await peer.locator('#bus').inputValue(),'Вторая вкладка');checks.push('absolute twelve-hour expiry and same-user recovery across tabs');
  await control('expire');await refresh();await page.waitForFunction(()=>panelSession.isLocked());
  await page.route('**/?p=session&a=reauth',async route=>{await route.fetch();await route.abort();},{times:1});await reauth();await page.locator('#sessionError').waitFor({state:'visible'});await refresh();await page.waitForFunction(()=>!panelSession.isLocked());checks.push('lost login response recovers by status without replaying business actions');
  await control('expire');await refresh();await page.waitForFunction(()=>panelSession.isLocked());if(process.env.SESSION_SCREENSHOT)await page.screenshot({path:process.env.SESSION_SCREENSHOT.replace('mobile','desktop')});await page.setViewportSize({width:390,height:844});assert(await page.locator('#sessionReauthButton').isVisible());assert(await page.locator('#sessionOtherLogin').isVisible());assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  if(process.env.SESSION_SCREENSHOT)await page.screenshot({path:process.env.SESSION_SCREENSHOT});checks.push('mobile lock fits viewport with accessible login and exit controls');
  await login();assert.equal(await page.evaluate(()=>localStorage.getItem('panel-workspace:active-login')),scope);await page.waitForFunction(()=>panelWorkspace.getChat().drafts?.some(d=>d.text==='Сохранённый черновик'));checks.push('same-user ordinary login restores encrypted workspace after page navigation');
  await control('legacy');await refresh();await page.waitForFunction(()=>panelSession.isLocked());await reauth();await page.waitForFunction(()=>!panelSession.isLocked());checks.push('legacy session requires one-time credential renewal');
  await control('expire');await refresh();await page.waitForFunction(()=>panelSession.isLocked());
  for(let i=0;i<5;i++){await reauth(page,'fixture1','wrong');await page.locator('#sessionError').waitFor({state:'visible'});}
  await reauth();await page.locator('#sessionError').waitFor({state:'visible'});assert(await page.evaluate(()=>panelSession.isLocked()));await control('cooldown');await reauth();await page.waitForFunction(()=>!panelSession.isLocked());checks.push('five credential failures enforce a bounded per-session cooldown');
  await control('near-expiry');await refresh();await context.setOffline(true);await page.waitForFunction(()=>panelSession.isLocked());await reauth();await page.locator('#sessionError').waitFor({state:'visible'});assert(await page.evaluate(()=>panelSession.isLocked()));await context.setOffline(false);await refresh();await reauth();await page.waitForFunction(()=>!panelSession.isLocked());checks.push('offline clock expires locally and failed network login never unlocks');
  await login(peer,'fixture2');await page.waitForURL('**/?p=login');assert.equal(await page.evaluate(k=>localStorage.getItem('panel-workspace:v1:'+k),scope),null);checks.push('different account clears previous encrypted workspace and invalidates old tab');
  await peer.goto(base+'/?p=logout');await peer.waitForURL('**/?p=login');assert.equal((await (await context.request.get(base+'/?p=session')).json()).authenticated,false);checks.push('explicit logout revokes access');
  await login();await control('disable');await control('expire');await refresh();await page.waitForFunction(()=>panelSession.isLocked());await reauth();await page.locator('#sessionError').waitFor({state:'visible'});assert(await page.evaluate(()=>panelSession.isLocked()));checks.push('disabled account cannot recover access');
  assert.deepEqual(errors,[]);console.log(JSON.stringify({ok:true,checks},null,2));
 }catch(e){console.error(e);console.error(errors);for(const p of context?.pages()||[]){console.error(await p.locator('#sessionError').textContent().catch(()=>''));}console.error(serverLog.slice(-1800));process.exitCode=1;}
 finally{await browser?.close();server.kill();fs.rmSync(dir,{recursive:true,force:true});}
})();
