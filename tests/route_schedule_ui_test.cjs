// Run against route_schedule_fixture.php with a fresh, disposable SCHEDULE_TEST_DB.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
    try {
        const page = await browser.newPage({viewport:{width:1360,height:1000},locale:'en-US'});
        const errors=[];
        page.on('pageerror', e=>errors.push(e.message));
        const base=process.env.SCHEDULE_TEST_URL || 'http://127.0.0.1:8137';
        const api=async(action,data={},headers={})=>{
            const res=await page.request.post(base+'/?p=api&a='+action,{data,headers});
            return {status:res.status(), ...await res.json()};
        };
        await page.goto(base+'/?p=schedules&manifest_id=1');
        await page.locator('#scheduleConfirmed').waitFor();
        assert.equal(await page.locator('tr[data-index]').count(),8);
        const city=()=>page.locator('tr[data-index]').filter({has:page.locator('[data-field="station"][value="Грушевка (Судак)"]')});
        assert.match(await city().innerText(),/совпадает со стартом/i);
        const timeField = city().locator('[data-field="time"]');
        assert.equal(await timeField.getAttribute('type'),'text');
        for (const value of ['00:00','13:00','23:59']) {
            await timeField.fill(value);
            assert(await timeField.evaluate(el=>el.checkValidity()));
        }
        for (const value of ['24:00','13:60','1:00','']) {
            await timeField.fill(value);
            await page.locator('#scheduleSave').click();
            assert.match(await page.locator('#scheduleMessage').innerText(),/24-часовом/);
            assert.equal(await timeField.inputValue(),value);
        }

        await city().locator('[data-field="time"]').fill('15:20'); // synthetic correction, not verified real time
        await page.locator('#scheduleGds').click();
        await page.getByText('Тестовый сбой ГДС: данные не изменены.',{exact:true}).waitFor();
        assert.equal(await city().locator('[data-field="time"]').inputValue(),'15:20');
        let current=(await api('schedule.get',{manifest_id:1})).context;
        const gds=[
            [23,'Евпатория ж/д','13:00',0], [31,'Славянск-на-Кубани','23:20',0],
            [32,'Краснодар ТЦ "Бауцентр"','01:00',1], [33,'Ростов на Дону (ТЦ Мега)','03:40',1],
            [34,'Шахты (трасса)','05:00',1], [45,'Каменск-Шахтинский (Музей СССР)','06:00',1],
            [46,'Богучар АЗС Ронефть','07:00',1], [47,'Павловск АС','08:00',1],
            [49,'Воронеж (АЗС Роснефть)','10:10',1], [6,'Тула "Автовокзал"','15:10',1],
            [5,'Москва "Саларьево"','',1],
        ].map(([station_id,station,time,day])=>({station_id,station,time,day,terminal:station_id===5,
            arrival:station_id===32 ? {time:'00:50',day:1}:null}));
        await page.route('**/?p=api&a=schedule.gds',route=>route.fulfill({json:{ok:true,context:current,proposal:{stops:gds}}}));
        await page.locator('#scheduleGds').click();
        await page.getByText('ГДС подтвердила рейс, дату, маршрут и плановый старт. Проверьте предложения и отсутствующие станции.',{exact:true}).waitFor();
        assert.equal(await page.locator('tr[data-index]').count(),16);
        assert.equal(await city().locator('[data-field="time"]').inputValue(),'15:20');
        await page.locator('#scheduleSave').click();
        assert.match(await page.locator('#scheduleMessage').innerText(),/Отметьте/);
        await page.locator('#scheduleConfirmed').check();
        await page.locator('#scheduleSave').click();
        await page.getByText('Расписание сохранено и применено. Разовые правки этого выезда сохранены.',{exact:true}).waitFor();
        current=(await api('schedule.get',{manifest_id:1})).context;
        assert.equal(current.schedule.stops.length,16);
        assert.equal(current.state.source.find(s=>s.station_id===103).time,'13:00');
        assert.equal(current.state.applied.find(s=>s.station_id===103).time,'15:20');
        const denied=await api('schedule.save',{...current.schedule,confirmed:true,version:1,stops:current.schedule.stops},{'X-Test-Role':'operator'});
        assert.equal(denied.status,403);
        const wrongMethod=await page.request.get(base+'/?p=api&a=schedule.apply');assert.equal(wrongMethod.status(),405);
        const bad=await api('schedule.save',{route:current.route,start_time:current.start_time,confirmed:true,version:1,
            stops:[{station:'Invalid',time:'25:00',day:0}]});assert.equal(bad.status,422);
        // A second actor edits the same version; the open editor must not overwrite it.
        const edited=await api('schedule.save',{route:current.route,start_time:current.start_time,confirmed:true,version:1,stops:current.schedule.stops});
        assert.equal(edited.ok,true);
        await city().locator('[data-field="time"]').fill('15:25');
        await page.locator('#scheduleConfirmed').check();
        await page.locator('#scheduleSave').click();
        await page.getByText(/Расписание изменено другим сотрудником/).waitFor();
        assert.equal(await city().locator('[data-field="time"]').inputValue(),'15:25');
        page.on('dialog', dialog=>dialog.accept());
        await page.reload();await page.locator('#scheduleConfirmed').waitFor();
        assert.equal(await city().locator('[data-field="time"]').inputValue(),'15:20');
        await page.screenshot({path:path.join(process.env.SCHEDULE_SCREENSHOT_DIR||'/private/tmp','schedule-desktop.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.emulateMedia({reducedMotion:'reduce'});
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'no horizontal page overflow');
        await city().locator('[data-field="time"]').focus();
        for (let i=0;i<4 && !(await page.evaluate(()=>document.activeElement?.dataset.field==='day'));i++) await page.keyboard.press('Tab');
        assert(await page.evaluate(()=>document.activeElement?.dataset.field==='day'),'keyboard reaches day field');
        await page.evaluate(()=>window.scrollTo(0,0));
        await page.screenshot({path:path.join(process.env.SCHEDULE_SCREENSHOT_DIR||'/private/tmp','schedule-mobile.png')});
        assert.deepEqual(errors,[]);
        console.log('Schedule UI/API: save, reload, GDS failure, 16-stop merge, conflict, validation, 403/405, desktop, 390px, keyboard, reduced motion OK');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
