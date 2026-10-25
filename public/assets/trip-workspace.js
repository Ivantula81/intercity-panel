/* Local view state only. Opening a tab never submits an operation. */
(() => {
    'use strict';
    const tabs = ['preparation','passengers','messages'];
    const workspace = window.panelWorkspace;
    const originId = new URL(location.href).searchParams.get('return_manifest');
    const back = document.getElementById('tripChatReturn');
    if (back && /^\d{1,12}$/.test(originId || '') && Number(originId) > 0) {
        back.href='/?p=manifest&id='+originId+'&tab=messages';back.hidden=false;
    }
    async function start() {
        if(workspace) await workspace.ready;
        document.querySelectorAll('[data-trip-list-back]').forEach(a=>{a.href=workspace?.getManifestList?.() || '/?p=manifests';});
        const root=document.querySelector('[data-trip-id]');if(!root)return;
        const id=root.dataset.tripId, nav=root.querySelector('.trip-tabs');
        const buttons=[...nav.querySelectorAll('[data-trip-tab]')];
        nav.hidden=false;nav.setAttribute('role','tablist');
        for(const button of buttons){
            button.id='trip-tab-'+button.dataset.tripTab;button.setAttribute('role','tab');
            button.setAttribute('aria-controls','trip-'+button.dataset.tripTab);
        }
        for(const panel of root.querySelectorAll('[data-trip-panel]')){
            panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','trip-tab-'+panel.dataset.tripPanel);
        }
        function show(tab, push=false) {
            if(!tabs.includes(tab))tab='preparation';
            for(const button of buttons){const active=button.dataset.tripTab===tab;button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;}
            for(const panel of root.querySelectorAll('[data-trip-panel]'))panel.hidden=panel.dataset.tripPanel!==tab;
            workspace?.setTripTab?.(id,tab);
            if(push){const url=new URL(location.href);url.searchParams.set('tab',tab);history.pushState(null,'',url);workspace?.remember();}
        }
        function restore(){const explicit=new URL(location.href).searchParams.get('tab');show(explicit || workspace?.getTripTab?.(id) || 'preparation');}
        nav.addEventListener('click',e=>{const button=e.target.closest('[data-trip-tab]');if(button)show(button.dataset.tripTab,true);});
        nav.addEventListener('keydown',e=>{
            const index=buttons.indexOf(document.activeElement);if(index<0)return;
            let next;if(e.key==='ArrowRight')next=(index+1)%buttons.length;else if(e.key==='ArrowLeft')next=(index+buttons.length-1)%buttons.length;else if(e.key==='Home')next=0;else if(e.key==='End')next=buttons.length-1;else return;
            e.preventDefault();buttons[next].focus();show(buttons[next].dataset.tripTab,true);
        });
        window.addEventListener('popstate',restore);restore();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
