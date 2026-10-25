/* Server-enforced access; this UI keeps the current page while credentials are renewed. */
(() => {
    'use strict';
    const initial=window.PANEL_SESSION;delete window.PANEL_SESSION;
    if(!initial)return;
    const originalFetch=window.fetch.bind(window);
    let config=initial, scope=initial.scope, locked=false, invalidated=false, busy=false, signing=false, version=0;
    let received=Date.now(), receivedMono=performance.now(), lastTouch=0, changed=false, focused=null;
    const byId=id=>document.getElementById(id);
    const now=()=>config.server_now+Math.max(0,Date.now()-received,performance.now()-receivedMono)/1000;
    const deadline=()=>Math.min(config.idle_at||0,config.absolute_at||0);
    function clearPassword(){const input=byId('sessionReauth')?.elements.password;if(input)input.value='';}
    function accept(data, allowUnlock=false){
        if(!data || typeof data.authenticated!=='boolean' || !Number.isFinite(data.server_now))throw Error('Некорректный ответ проверки входа');
        if(data.authenticated){
            if(scope && scope!==data.scope){
                invalidated=true;lock('changed');
                byId('sessionLockReason').textContent='В другой вкладке сменили учётную запись. Для продолжения откройте панель заново.';
                byId('sessionReauthButton').hidden=true;
                for(const label of byId('sessionReauth').querySelectorAll('label'))label.hidden=true;
                const link=byId('sessionOtherLogin');link.href='/';link.textContent='Открыть панель';return;
            }
            if(!Number.isFinite(data.idle_at)||!Number.isFinite(data.absolute_at)||typeof data.csrf!=='string')throw Error('Некорректные сроки входа');
            scope=data.scope;window.CSRF=data.csrf;
            for(const input of document.querySelectorAll('input[name=csrf]'))input.value=data.csrf;
            if(locked && (allowUnlock || data.generation!==config.generation)){
                locked=false;document.body.classList.remove('session-locked');byId('sessionLock').close();clearPassword();byId('sessionError').hidden=true;focused?.focus?.();
            }
        }else{lock(data.reason||'expired');}
        config=data;received=Date.now();receivedMono=performance.now();tick();
    }
    function lock(reason){
        if(!locked){
            locked=true;version++;changed=false;focused=document.activeElement;
            window.panelWorkspace?.flush(); // Local encrypted copy only; never writes business data.
            document.body.classList.add('session-locked');
            byId('sessionWarning').hidden=true;
            const dialog=byId('sessionLock');if(!dialog.open)dialog.showModal();
            byId('sessionReauth').elements.login.focus();
        }
        if(reason==='absolute')byId('sessionLockReason').textContent='Прошло 12 часов после входа. Подтвердите учётную запись для новой смены.';
        else if(reason==='renew')byId('sessionLockReason').textContent='Подтвердите учётную запись, чтобы продолжить работу с новым сроком входа.';
        else if(reason!=='changed')byId('sessionLockReason').textContent='Вход завершён или требуется подтверждение. Доступ к панели приостановлен.';
    }
    function tick(){
        if(locked || !config.authenticated)return;
        const remaining=deadline()-now();
        if(remaining<=0){lock(config.absolute_at<=config.idle_at?'absolute':'idle');return;}
        const warning=byId('sessionWarning');warning.hidden=remaining>120;
        if(!warning.hidden){
            const absolute=config.absolute_at<=config.idle_at;
            byId('sessionWarningText').textContent=absolute?'Смена заканчивается менее чем через 2 минуты. Потребуется повторный вход.':'Менее чем через 2 минуты панель заблокируется из-за бездействия.';
            byId('sessionContinue').hidden=absolute;
        }
    }
    async function check(touch=false){
        if(busy||signing||invalidated)return;
        if(touch && locked)return;
        busy=true;const requestVersion=version;
        try{
            const response=await originalFetch('/?p=session&a='+(touch?'touch':'status'),touch?{method:'POST',headers:{'X-CSRF':window.CSRF},credentials:'same-origin',cache:'no-store'}:{credentials:'same-origin',cache:'no-store'});
            if(response.status===419){if(touch)changed=true;return;}
            const data=await response.json();if(version!==requestVersion)return;
            accept(data);if(touch && data.authenticated){lastTouch=Date.now();changed=false;}
        }catch(_){if(locked){byId('sessionError').textContent='Нет подтверждения связи. Доступ остаётся заблокированным; проверьте интернет.';byId('sessionError').hidden=false;}}
        finally{busy=false;}
    }
    // Business requests are never replayed. In-flight responses remain attached to their callers.
    window.fetch=async (input,options)=>{
        const url=new URL(typeof input==='string'?input:input instanceof URL?input.href:input.url,location.href);
        const protectedApi=url.origin===location.origin && url.pathname==='/' && ['api','chat_api','reporting_api'].includes(url.searchParams.get('p'));
        if(protectedApi && (locked || (config.authenticated && now()>=deadline()))){
            lock('expired');return new Response(JSON.stringify({ok:false,auth_required:true,error:'Вход завершён. Войдите снова; действие не выполнено.'}),{status:401,headers:{'Content-Type':'application/json'}});
        }
        const response=await originalFetch(input,options);
        if(protectedApi && response.status===401)lock('expired');
        return response;
    };
    window.panelSession={isInvalidated:()=>invalidated,isLocked:()=>locked};
    function start(){
        const form=byId('sessionReauth');
        byId('sessionLock').addEventListener('cancel',e=>e.preventDefault());
        byId('sessionContinue').addEventListener('click',()=>check(true));
        byId('sessionLoginEarly').addEventListener('click',()=>lock('renew'));
        form.addEventListener('submit',async e=>{
            e.preventDefault();if(signing||invalidated)return;
            signing=true;version++;byId('sessionReauthButton').disabled=true;byId('sessionError').hidden=true;
            try{
                const response=await originalFetch('/?p=session&a=reauth',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF':window.CSRF},body:JSON.stringify({login:form.elements.login.value,password:form.elements.password.value})});
                if(response.status===419)throw Error('Вход изменился в другой вкладке. Дождитесь проверки статуса и попробуйте снова; при необходимости выйдите и войдите заново.');
                const data=await response.json();
                if(!response.ok || !data.ok || !data.authenticated)throw Error(data.error||'Не удалось подтвердить вход. Попробуйте снова.');
                accept(data,true);
            }catch(error){byId('sessionError').textContent=error.message==='Failed to fetch'?'Ответ не получен. Проверьте связь и статус входа; действия не повторялись.':error.message;byId('sessionError').hidden=false;}
            finally{clearPassword();signing=false;byId('sessionReauthButton').disabled=false;}
        });
        for(const event of ['pointerdown','keydown','input','wheel','touchstart']){
            document.addEventListener(event,e=>{
                if(!e.isTrusted||locked||document.hidden)return;
                tick();if(locked)return;changed=true;if(Date.now()-lastTouch>=30000)check(true);
            },{passive:true,capture:true});
        }
        document.addEventListener('visibilitychange',()=>{tick();if(!document.hidden)check(false);});
        setInterval(()=>{tick();if(changed && !locked && !document.hidden && Date.now()-lastTouch>=30000)check(true);},1000);
        setInterval(()=>{if(!document.hidden)check(false);},15000);
        if(!config.authenticated)lock(config.reason);else tick();
        check(false);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
