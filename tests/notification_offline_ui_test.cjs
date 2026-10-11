// Synthetic fixture only. External traffic is blocked, no production credentials or sends.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const pause = ms => new Promise(r=>setTimeout(r,ms));
(async()=>{
 const reserved=net.createServer(); await new Promise(r=>reserved.listen(0,'127.0.0.1',r));
 const port=reserved.address().port; await new Promise(r=>reserved.close(r));
 const base='http://127.0.0.1:'+port;
 const php=spawn('php',['-S','127.0.0.1:'+port,path.join(__dirname,'notification_fixture.php')],{env:{...process.env,NOTIFICATION_FIXTURE:'1'},stdio:['ignore','ignore','pipe']});
 let phpErrors='';php.stderr.on('data',b=>{phpErrors+=b.toString();});
 let browser;const checks=[];
 try {
  for(let i=0;i<50;i++){try {await fetch(base);break;}catch{await pause(100);}}
  browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
  for(const width of [1360,390]){
   const page=await browser.newPage({viewport:{width,height:950},locale:'ru-RU',reducedMotion:'reduce'});
   const errors=[],requests=[],launches=[];let failLaunch=true,expired=false;
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const u=new URL(route.request().url());assert.equal(u.origin,base,'external traffic forbidden');
    const a=u.searchParams.get('a');if(a)requests.push(a);
    if(a==='notification.launch'){
      launches.push(route.request().postDataJSON());
      return route.fulfill({json:failLaunch?{ok:false,error:'Тестовый отказ сохранения'}:{ok:true,run_id:1}});
    }
    if(a==='notification.preview'&&expired)return route.fulfill({json:{ok:true,plan:{items:[],exclusions:[{reason:'Срок отправки истёк: до посадки осталось не больше часа.'}]}}});
    return route.continue();
   });
   await page.goto(base+'/?p=notifications&manifest_id=1');
   await page.waitForFunction(()=>typeof GROUPS!=='undefined'&&GROUPS.length===2&&NC.overview,{},{timeout:5000}).catch(async e=>{console.error({phpErrors,errors,requests,body:(await page.locator('body').innerText()).slice(-2200)});throw e;});
   await page.evaluate(()=>ncTab('prepare'));
   const send=page.locator('[data-send-all]').last();
   assert.equal(await send.isEnabled(),true,'configured offline channel can queue');
   assert.equal(requests.includes('channels.check'),false,'no automatic provider presence request');
   assert.match(await page.locator('#sendChannels').innerText(),/проверка перед отправкой/);
   await send.click();await page.locator('#ncConfirm').waitFor();
   assert.match(await page.locator('#ncDialog').innerText(),/отправить до 11.09.2026 12:00 МСК/);
   assert.match(await page.locator('#ncDialog').innerText(),/могут быть доставлены позже/);
   await page.locator('#ncConfirm').click();
   await page.locator('#ncLaunchError').getByText(/Тестовый отказ/).waitFor();
   assert.equal(await page.locator('#ncDialog').isVisible(),true,'failed save keeps review dialog');
   failLaunch=false;await page.locator('#ncConfirm').click();
   await page.locator('#ncDialog').waitFor({state:'detached'});
   assert.equal(launches.length,2);assert.equal(launches[0].request_key,launches[1].request_key,'retry keeps idempotency key');
   assert.equal(launches[0].digest,launches[1].digest);
   assert.deepEqual(errors,[]);
   await page.evaluate(()=>ncTab('prepare'));expired=true;
   let message='';page.once('dialog',async d=>{message=d.message();await d.accept();});
   await send.click();await page.waitForFunction(()=>!NC.busy);
   assert.match(message,/Срок отправки истёк/);assert.equal(launches.length,2,'expired plan cannot launch');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal overflow');
   await page.screenshot({path:'/tmp/panel-offline-'+width+'.png',fullPage:true});
   checks.push(width+': offline selection, exact cutoff, failed-save retry, expiry, no provider checks, no JS errors');
   await page.close();
  }
  console.log(JSON.stringify({ok:true,checks},null,2));
 }finally{if(browser)await browser.close();php.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
