// Real browser, synthetic state, external traffic forbidden. No provider credentials.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const net=require('node:net');
const path=require('node:path');
const pause=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const reserved=net.createServer();await new Promise(r=>reserved.listen(0,'127.0.0.1',r));
 const port=reserved.address().port;await new Promise(r=>reserved.close(r));
 const base='http://127.0.0.1:'+port;
 const php=spawn('php',['-S','127.0.0.1:'+port,path.join(__dirname,'notification_fixture.php')],{env:{...process.env,NOTIFICATION_FIXTURE:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch(base);break;}catch{await pause(100);}}
  browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
  for(const width of [1360,390]){
   const page=await browser.newPage({viewport:{width,height:950},locale:'ru-RU'});
   const errors=[],cancels=[];let stopped=false,fail=true,releaseProvider,releaseCancel;
   const providerWait=new Promise(r=>releaseProvider=r);
   let cancelWait=Promise.resolve();
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const u=new URL(route.request().url());assert.equal(u.origin,base,'no external traffic');
    const a=u.searchParams.get('a');
    if(a==='channels.queue'){await providerWait;return route.fulfill({json:{ok:false,error:'Synthetic provider timeout'}});}
    if(a==='notification.active')return route.fulfill({json:{ok:true,at:new Date().toISOString(),items:[
     {id:1,run_id:1,status:stopped?'cancelled':'running',manifest_id:1,trip_number:'101',departure_at:'2027-01-01 13:00',created_at:'2027-01-01 10:00',queued:stopped?0:3,sending:1,accepted:2,delivered:0,failed:0,stopped:stopped?3:0},
     {id:2,run_id:2,status:'queued',manifest_id:2,trip_number:'102',departure_at:'2027-01-02 13:00',created_at:'2027-01-01 11:00',queued:2,sending:0,accepted:0,delivered:0,failed:0}
    ]}});
    if(a==='notification.cancel'){
     cancels.push(route.request().postDataJSON());await cancelWait;
     if(fail)return route.fulfill({status:503,json:{ok:false,error:'Synthetic storage failure'}});
     stopped=true;return route.fulfill({json:{ok:true,stopped:3,sending:1,transferred:2,failed:0}});
    }
    return route.continue();
   });
   await page.goto(base+'/?p=notifications&manifest_id=1');
   const button=()=>page.locator('[data-stop-key="run:1"]');
   await button().waitFor();
   assert(await button().isVisible(),'stop available before provider health finishes');
   await button().click();await page.getByText(/Остановка не подтверждена: Synthetic storage failure/).waitFor();
   await page.waitForFunction(()=>!NC.cancelling.size&&!NC.queueBusy);
   assert(await button().isEnabled(),'failed stop can be retried');
   fail=false;cancelWait=new Promise(r=>releaseCancel=r);
   await button().click();await page.waitForFunction(()=>NC.cancelling.size===1);
   assert(await button().isDisabled(),'double click blocked');
   await page.evaluate(()=>ncStop(1,0));
   assert.equal(cancels.length,2,'no duplicate request while pending');
   releaseCancel();await page.getByText(/Остановка подтверждена. Остановлено в очереди: 3/).waitFor();
   await page.getByText('Отправка остановлена',{exact:true}).waitFor();
   assert.match(await page.locator('#ncStopResult').innerText(),/Уже передано провайдеру: 2. В обработке: 1/);
   assert(await page.locator('[data-stop-key="run:2"]').isEnabled(),'other run still available');
   assert.deepEqual(cancels,[{run_id:1},{run_id:1}]);
   releaseProvider();await page.getByText(/Очередь провайдера не проверена/).waitFor({state:'attached'});
   await page.reload();await page.getByText('Отправка остановлена',{exact:true}).waitFor();
   assert.equal(await button().count(),0,'stop survives reload');
   assert(await page.locator('[data-stop-key="run:2"]').isVisible(),'other run survives reload');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal overflow');
   assert.deepEqual(errors,[]);
   await page.screenshot({path:'/tmp/panel-cancel-'+width+'.png',fullPage:true});
   await page.close();
   console.log(width+': slow provider, failed stop, retry, double click, partial result, isolated scope, reload, layout, JS runtime OK');
  }
 }finally{if(browser)await browser.close();php.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
