/* Personal workspace: encrypted at rest, private tab snapshot, no business actions. */
(() => {
    'use strict';
    const config = window.PANEL_WORKSPACE;
    delete window.PANEL_WORKSPACE;
    if (!config?.scope || !config?.key) return;
    const storageKey = 'panel-workspace:v1:' + config.scope;
    const ttl = 7 * 24 * 60 * 60 * 1000;
    const maxBytes = 512 * 1024;
    const routes = {
        dashboard: [], manifests: [], manifest: ['id'], schedules: ['manifest_id'],
        contacts: ['q','sort'], contact: ['id'], chats: ['conversation_id','phone'],
        notifications: ['manifest_id','fresh'], reporting: [], report_trip: ['id'],
        sales: ['period','from','to','agent','owner','carrier','sender','recipient','subject','q','page'],
    };
    const sections = {manifest:'manifests', schedules:'manifests', contact:'contacts', report_trip:'reporting'};
    let state = {v:1, at:0, last:'/', sections:{}, positions:{}, chat:{}}, key;
    let revision = 0, saved = 0, timer, pending, enabled = true, restoring = true;
    let message = '', invalidated = false, userMoved = false;
    for(const event of ['wheel','touchstart','keydown'])window.addEventListener(event,()=>{userMoved=true;},{once:true,passive:true});
    const activeKey = 'panel-workspace:active-login';
    const bytes = s => Uint8Array.from(atob(s), c=>c.charCodeAt(0));
    const base64 = b => {let s='';for(const n of new Uint8Array(b))s+=String.fromCharCode(n);return btoa(s);};
    function safeUrl(value) {
        try {
            if (typeof value !== 'string' || value.length > 4096) return null;
            const u = new URL(value, location.origin);
            const page = u.searchParams.get('p') || 'dashboard';
            if (u.origin !== location.origin || u.pathname !== '/' || !Object.hasOwn(routes,page)) return null;
            for (const [name, val] of u.searchParams) {
                if(u.searchParams.getAll(name).length!==1)return null;
                if (name !== 'p' && !routes[page].includes(name)) return null;
                if (val.length > 1000 || (/^(id|manifest_id|conversation_id)$/.test(name) && !/^\d{1,12}$/.test(val))) return null;
            }
            u.hash='';return u.pathname+u.search;
        } catch (_) {return null;}
    }
    const pageOf = url => new URL(url,location.origin).searchParams.get('p') || 'dashboard';
    const sectionOf = url => sections[pageOf(url)] || pageOf(url);
    function tell(text) {
        message=text;
        const el=document.getElementById('workspaceState');
        if(el)el.textContent=text;
    }
    function failed() {tell('Не удалось сохранить рабочее место на устройстве. Не закрывайте страницу с незавершённым вводом.');}
    function normalize(value) {
        if (!value || value.v!==1 || !Number.isFinite(value.at) || Date.now()-value.at>ttl || value.at>Date.now()+60000) throw Error('Expired workspace');
        const clean={v:1,at:value.at,last:safeUrl(value.last)||'/',sections:{},positions:{},chat:{}};
        for(const [section,url] of Object.entries(value.sections||{})) {
            const safe=safeUrl(url);if(safe && sectionOf(safe)===section)clean.sections[section]=safe;
        }
        for(const [url,pos] of Object.entries(value.positions||{}).slice(-40)) {
            if(safeUrl(url) && Array.isArray(pos) && pos.length===2 && pos.every(n=>Number.isFinite(n)&&n>=0&&n<=1000000))clean.positions[url]=pos;
        }
        const c=value.chat;
        if(c && typeof c==='object') {
            clean.chat={queue:['open','new','mine','unassigned','pending','delivery_failed','resolved'].includes(c.queue)?c.queue:'open',
                channel:['all','whatsapp','max','telegram','sms','email'].includes(c.channel)?c.channel:'all',
                search:typeof c.search==='string'?c.search.slice(0,1000):'',selected:Number.isSafeInteger(c.selected)&&c.selected>0?c.selected:null,
                bodyScroll:Number.isFinite(c.bodyScroll)?Math.max(0,c.bodyScroll):null,
                listScroll:Number.isFinite(c.listScroll)?Math.max(0,c.listScroll):0,drafts:[]};
            for(const d of Array.isArray(c.drafts)?c.drafts:[]) {
                if(!Number.isSafeInteger(d.id)||d.id<1||typeof d.text!=='string'||d.text.length>maxBytes)continue;
                clean.chat.drafts.push({id:d.id,text:d.text,revision:Number.isSafeInteger(d.revision)?d.revision:0,
                    error:typeof d.error==='string'?d.error.slice(0,2000):'',pending:d.pending===true});
            }
        }
        return clean;
    }
    async function decode(raw) {
        if(raw.length>maxBytes*2)throw Error('Oversized workspace');
        const envelope=JSON.parse(raw);
        const plain=await crypto.subtle.decrypt({name:'AES-GCM',iv:bytes(envelope.iv),additionalData:new TextEncoder().encode(config.scope)},key,bytes(envelope.data));
        return normalize(JSON.parse(new TextDecoder().decode(plain)));
    }
    async function initialize() {
        try {
            key=await crypto.subtle.importKey('raw',bytes(config.key),'AES-GCM',false,['encrypt','decrypt']);
            config.key='';
            localStorage.setItem(activeKey,config.scope);
            // A new login must not retain old login snapshots on this device.
            for(const storage of [sessionStorage,localStorage])for(const name of Object.keys(storage)){
                if(name.startsWith('panel-workspace:v1:') && !name.startsWith(storageKey))storage.removeItem(name);
            }
            const tab=sessionStorage.getItem(storageKey);
            const raw=tab===null?localStorage.getItem(storageKey):tab;
            if(raw) {
                try {state=await decode(raw);}
                catch (_) {sessionStorage.removeItem(storageKey);localStorage.removeItem(storageKey);tell('Сохранённое рабочее место устарело или недоступно. Открыт текущий экран.');}
            }
            // Fail early if storage is denied, without displacing the saved copy.
            for(const storage of [sessionStorage,localStorage]) {storage.setItem(storageKey+':probe','1');storage.removeItem(storageKey+':probe');}
        } catch (_) {enabled=false;failed();}
    }
    function changed() {
        revision++;clearTimeout(timer);timer=setTimeout(flush,180);
    }
    async function flush() {
        clearTimeout(timer);
        await ready;
        if(!enabled)return false;
        if(pending)return pending;
        if(saved===revision)return true;
        pending=(async()=>{
            try {
                while(saved!==revision && enabled) {
                    const version=revision;state.at=Date.now();
                    const plain=new TextEncoder().encode(JSON.stringify(state));
                    if(plain.length>maxBytes)throw Error('Workspace full');
                    const iv=crypto.getRandomValues(new Uint8Array(12));
                    const encrypted=await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:new TextEncoder().encode(config.scope)},key,plain);
                    if(!enabled)return false;
                    const raw=JSON.stringify({iv:base64(iv),data:base64(encrypted)});
                    sessionStorage.setItem(storageKey,raw);localStorage.setItem(storageKey,raw);saved=version;
                }
                tell('Рабочее место сохранено на этом устройстве до выхода из аккаунта, до 7 дней после последнего изменения.');
                return true;
            } catch (_) {failed();return false;}
            finally {pending=null;}
        })();
        return pending;
    }
    function updateLinks() {
        document.querySelectorAll('.nav-item, .bn-item[href], .sheet-item').forEach(a=>{
            if(!a.dataset.workspaceBase)a.dataset.workspaceBase=a.getAttribute('href');
            const base=safeUrl(a.dataset.workspaceBase);if(!base)return;
            a.href=state.sections[sectionOf(base)]||base;
        });
    }
    function remember() {
        const url=safeUrl(location.href);if(!url)return;
        state.last=url;state.sections[sectionOf(url)]=url;changed();updateLinks();
    }
    function position() {
        if(restoring)return;
        const url=safeUrl(location.href);if(!url)return;
        state.positions[url]=[scrollX,scrollY];
        const names=Object.keys(state.positions);if(names.length>40)delete state.positions[names[0]];
        changed();
    }
    async function start() {
        if(document.body.dataset.page==='chats' && window.ResizeObserver){
            const size=()=>{for(const [selector,name]of [['.topbar','top'],['.bottombar','bottom']])document.documentElement.style.setProperty('--workspace-'+name,(document.querySelector(selector)?.offsetHeight||0)+'px');};
            const observer=new ResizeObserver(size);
            for(const selector of ['.topbar','.bottombar']){const el=document.querySelector(selector);if(el)observer.observe(el);}
            size();
        }
        await ready;
        if(!enabled){tell(message);restoring=false;return;}
        if(document.querySelector('[data-workspace-missing]')) {
            const url=safeUrl(location.href);
            for(const name of Object.keys(state.sections))if(state.sections[name]===url)delete state.sections[name];
            if(state.last===url)state.last='/';
            delete state.positions[url];changed();updateLinks();restoring=false;return;
        }
        const nav=performance.getEntriesByType('navigation')[0];
        if(location.pathname==='/' && !location.search && !document.referrer && nav?.type==='navigate' && state.last!=='/') {
            // App launch at the root resumes the last view; menu navigation and reload do not redirect.
            changed();if(await flush()){location.replace(state.last);return;}
        }
        const url=safeUrl(location.href), pos=state.positions[url];
        remember();tell(message||'Восстанавливаю рабочее место…');
        requestAnimationFrame(()=>requestAnimationFrame(()=>{if(pos && !userMoved && nav?.type!=='back_forward')scrollTo(...pos);restoring=false;}));
    }
    const ready=initialize();
    window.panelWorkspace={ready,flush,remember,
        getChat:()=>structuredClone(state.chat),
        setChat:value=>{state.chat=value;changed();},
        isSaved:()=>enabled && saved===revision,
        isInvalidated:()=>invalidated,
    };
    document.addEventListener('DOMContentLoaded',start);
    window.addEventListener('scroll',position,{passive:true});
    window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});
    document.addEventListener('visibilitychange',()=>{if(document.hidden)flush();});
    document.addEventListener('click',async e=>{
        const a=e.target.closest('a[href]');if(!a || e.defaultPrevented || e.button!==0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || a.target || a.hasAttribute('download'))return;
        const u=new URL(a.href,location.origin);if(u.origin!==location.origin)return;
        if(!safeUrl(a.href))return;
        e.preventDefault();position();await flush();location.assign(a.href);
    });
    window.addEventListener('storage',e=>{
        // Ordinary writes never replace the active tab. Removal signals logout.
        if(e.key===storageKey+':logout' || (e.key===activeKey && e.newValue!==config.scope)){enabled=false;invalidated=true;document.body.inert=true;try{sessionStorage.removeItem(storageKey);}catch(_){}location.replace('/?p=login');}
    });
})();
