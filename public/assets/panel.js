// Все вызовы API всегда возвращают объект {ok, error} — без исключений, чтобы экраны
// не зависали на «Загрузке…» при обрыве сети, истёкшей сессии или не-JSON ответе.
async function api(action, data = {}) {
    let r;
    try {
        r = await fetch('/?p=api&a=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF },
            body: JSON.stringify(data),
        });
    } catch (e) {
        return { ok: false, error: 'Нет связи с сервером — проверьте интернет и повторите.' };
    }
    if (r.status === 401 || r.status === 403) return { ok: false, error: 'Сессия истекла — обновите страницу и войдите снова.' };
    try { return await r.json(); }
    catch (e) { return { ok: false, error: 'Сервер ответил некорректно (код ' + r.status + '). Повторите позже.' }; }
}
async function apiUpload(action, formData) {
    formData.append('csrf', window.CSRF);
    let r;
    try { r = await fetch('/?p=api&a=' + action, { method: 'POST', body: formData }); }
    catch (e) { return { ok: false, error: 'Нет связи с сервером — проверьте интернет и повторите.' }; }
    if (r.status === 401 || r.status === 403) return { ok: false, error: 'Сессия истекла — обновите страницу.' };
    try { return await r.json(); }
    catch (e) { return { ok: false, error: 'Сервер ответил некорректно (код ' + r.status + ').' }; }
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
// WhatsApp-разметка → HTML для превью: сначала экранируем, потом *жирный* _курсив_ ~зачёркнутый~ ```моно```.
// Маркеры срабатывают только на границе слова — ссылки с _ внутри (max.ru/...) не ломаются.
function waFmt(raw) {
    let s = esc(raw);
    s = s.replace(/```([\s\S]+?)```/g, '<code>$1</code>');
    s = s.replace(/(^|[\s(])\*(?=\S)([^*\n]+?)\*(?=[\s).,!?:;]|$)/g, '$1<b>$2</b>');
    s = s.replace(/(^|[\s(])_(?=\S)([^_\n]+?)_(?=[\s).,!?:;]|$)/g, '$1<i>$2</i>');
    s = s.replace(/(^|[\s(])~(?=\S)([^~\n]+?)~(?=[\s).,!?:;]|$)/g, '$1<s>$2</s>');
    return s.replace(/\n/g, '<br>');
}

/* ── Автосохранение ячеек (редактор ведомости) ── */
let saveTimers = {};
function queueSave(key, fn) {
    clearTimeout(saveTimers[key]);
    saveTimers[key] = setTimeout(fn, 500);
}
function setState(text) {
    const el = document.getElementById('saveState');
    if (el) el.textContent = text;
}
function bindCells() {
    document.querySelectorAll('#ptable input.cell').forEach(inp => {
        inp.oninput = () => queueSave('p' + inp.closest('tr').dataset.id + inp.dataset.f, async () => {
            setState('Сохраняю…');
            await api('passenger.update', { id: +inp.closest('tr').dataset.id, field: inp.dataset.f, value: inp.value });
            setState('Все изменения сохранены');
        });
    });
    document.querySelectorAll('#tripFacts input.cell').forEach(inp => {
        inp.oninput = () => queueSave('m' + inp.dataset.f, async () => {
            setState('Сохраняю…');
            await api('manifest.update', { id: +document.getElementById('tripFacts').dataset.id, field: inp.dataset.f, value: inp.value });
            setState('Все изменения сохранены');
        });
    });
}
async function addPassenger(manifestId) {
    const r = await api('passenger.add', { manifest_id: manifestId });
    if (!r.ok) return;
    const tb = document.querySelector('#ptable tbody');
    const tr = document.createElement('tr');
    tr.dataset.id = r.id;
    tr.innerHTML = ['seat','name','phone','doc','ticket','from_stop','to_stop']
        .map(f => `<td><input class="cell" data-f="${f}" value=""></td>`).join('')
        + '<td class="actions"><button class="icon-btn" onclick="delPassenger(this)">✕</button></td>';
    tb.appendChild(tr);
    bindCells();
    updateCount();
    tr.querySelector('input').focus();
}
async function delPassenger(btn) {
    const tr = btn.closest('tr');
    if (!confirm('Удалить пассажира из ведомости?')) return;
    await api('passenger.delete', { id: +tr.dataset.id });
    tr.remove();
    updateCount();
}
function updateCount() {
    const el = document.getElementById('pcount');
    if (el) el.textContent = document.querySelectorAll('#ptable tbody tr').length;
}
async function delManifest(id) {
    if (!confirm('Удалить ведомость целиком вместе с пассажирами?')) return;
    await api('manifest.delete', { id });
    location.reload();
}
async function confirmManifest(id, confirmed) {
    await api('manifest.confirm', { id, confirmed });
    location.reload();
}

/* ── Дашборд: сервисы ── */
async function addService() {
    const title = prompt('Название сервиса (например, Планфикс):');
    if (!title) return;
    const url = prompt('Ссылка (https://…):', 'https://');
    if (!url) return;
    await api('link.add', { title, url, color: ['violet','blue','green','orange'][Math.floor(Math.random()*4)] });
    location.reload();
}
async function delService(ev, id) {
    ev.preventDefault();
    ev.stopPropagation();
    if (confirm('Убрать эту ссылку с дашборда?')) {
        await api('link.delete', { id });
        location.reload();
    }
    return false;
}

/* ── Уведомления: экран-мастер ── */
let GROUPS = [], TEMPLATES = [], BUS_PHOTO = '', GDS_LOADED = false, CHANNELS_ACTIVE = ['max'], CHANNELS_STATE = {}, MANIFEST_TEMPLATE = '', BLOCK_TEMPLATES = {}, TODAY_COUNTS = {}, DAILY_CAP = 200;
// Основной канал рассылки — из «Настроек». От него считаются галочка по умолчанию и «запасные» каналы.
let PRIMARY_CHANNEL = 'max', CHANNEL_LIMITED = [];
let LAST_OVERVIEW = null; // последний campaign.overview — источник меток «что ушло по блоку»
const CHANNEL_LABELS = { max: 'MAX', telegram: 'Telegram', whatsapp: 'WhatsApp', sms: 'SMS' };
// Мессенджеры, у которых наличие у номера проверяется. SMS сюда не входит — она возможна на любой номер.
const MESSENGERS = ['max', 'telegram', 'whatsapp'];
let CHANNEL_CHECKING = false, OVERVIEW_TIMER = null;
let SELECTED_CHANNELS = new Set([PRIMARY_CHANNEL]);
let FULL_CHANNEL_CHECK_REQUESTED = false;

function manifestId() {
    const tf = document.getElementById('tripFacts');
    if (tf) return +tf.dataset.id;
    const m = document.getElementById('cMan');
    return m ? +m.value : 0;
}

function bindTripFacts() {
    document.querySelectorAll('#tripFacts input.cell').forEach(inp => {
        inp.oninput = () => queueSave('m' + inp.dataset.f, async () => {
            await api('manifest.update', { id: manifestId(), field: inp.dataset.f, value: inp.value });
        });
    });
}

async function deleteManifestFromNotif(id) {
    if (!confirm('Удалить эту ведомость из системы вместе с пассажирами и группами? Журнал отправок останется.')) return;
    await api('manifest.delete', { id });
    location = '/?p=notifications&fresh=1';
}

async function uploadManifest(inp) {
    if (!inp.files.length) return;
    const fd = new FormData();
    fd.append('file', inp.files[0]);
    const r = await apiUpload('manifest.import', fd);
    if (r.ok) location = '/?p=notifications&manifest_id=' + r.id;
    else alert(r.error || 'Ошибка загрузки');
}

// Подтянуть ведомость по номеру рейса из системы автовокзала. preview → карточка, затем import.
async function pullManifest(mode) {
    const id = (document.getElementById('pullTripId')?.value || '').replace(/\D+/g, '');
    const box = document.getElementById('pullResult');
    const btn = document.getElementById('pullBtn');
    if (!id) { if (box) box.innerHTML = '<div class="alert warn">Введите номер рейса.</div>'; return; }
    if (btn) btn.disabled = true;
    if (box) box.innerHTML = '<p class="muted small">Запрашиваю систему автовокзала…</p>';
    const r = await api('manifest.pull', { id, mode }).catch(() => null);
    if (btn) btn.disabled = false;
    if (!r?.ok) { if (box) box.innerHTML = `<div class="alert err">${esc(r?.error || 'Не удалось получить рейс.')}</div>`; return; }
    if (r.mode === 'import') { location = '/?p=notifications&manifest_id=' + r.manifest_id; return; }
    // превью → подтверждение
    const t = r.trip, c = r.counts;
    box.innerHTML = `<div class="pull-preview">
        <div class="pull-preview-head"><span class="pull-ic">✓</span><div><b>Рейс №${esc(t.id)} · ${esc(t.route)}</b><div class="muted small">${esc([t.departure, t.carrier, t.bus].filter(Boolean).join(' · '))}</div></div></div>
        <div class="pull-metrics"><div><b>${c.passengers}</b><span>пассажиров</span></div><div><b>${c.agents}</b><span>агентов</span></div><div><b>${c.stations}</b><span>станций с ID</span></div><div><b>${c.segments}</b><span>отрезков</span></div></div>
        <div class="row" style="gap:10px;flex-wrap:wrap"><button class="btn" onclick="pullManifest('import')">Импортировать рейс</button><button class="btn ghost" onclick="document.getElementById('pullResult').innerHTML='';document.getElementById('pullTripId').focus()">Другой номер</button></div>
        <div class="muted small mt">Если рейс уже загружен — просто откроется, дубль не создаётся.</div>
    </div>`;
}

async function loadGroups(autoGds) {
    const box = document.getElementById('groupsBox');
    if (!box) return;
    box.innerHTML = '<p class="muted">Загружаю группы…</p>';
    const r = await api('groups', { manifest_id: manifestId() });
    if (!r.ok) { box.innerHTML = '<div class="alert err">' + esc(r.error) + '</div>'; return; }
    GROUPS = r.groups;
    TEMPLATES = r.templates;
    MANIFEST_TEMPLATE = r.manifest_template || (TEMPLATES[0] ? TEMPLATES[0].body : '');
    BLOCK_TEMPLATES = r.block_templates || {};
    TODAY_COUNTS = r.today || {};
    DAILY_CAP = r.daily_cap || 200;
    renderManifestTemplate();
    PRIMARY_CHANNEL = r.primary_channel || 'max';
    CHANNELS_ACTIVE = (r.channels_active && r.channels_active.length) ? r.channels_active : [PRIMARY_CHANNEL];
    CHANNELS_STATE = r.channels_state || {};
    if (![...SELECTED_CHANNELS].some(channel => CHANNELS_ACTIVE.includes(channel))) {
        // По умолчанию — основной канал, если он настроен; иначе первый настроенный.
        SELECTED_CHANNELS = new Set([CHANNELS_ACTIVE.includes(PRIMARY_CHANNEL) ? PRIMARY_CHANNEL : (CHANNELS_ACTIVE[0] || PRIMARY_CHANNEL)]);
    }
    renderSendChannels();
    BUS_PHOTO = r.bus_photo;
    const ap = document.getElementById('attachPhoto');
    if (ap) ap.disabled = !BUS_PHOTO;
    const hint = document.getElementById('busPhotoHint');
    if (hint) hint.textContent = BUS_PHOTO ? '' : 'фото автобуса нет в справочнике';
    renderGroups();
    renderPreparationSummary();
    autoCheckManifestChannels();

    const hasTimes = GROUPS.some(g => g.time);
    const gdsBadge = document.getElementById('gdsBadge');
    if (hasTimes && !GDS_LOADED && gdsBadge) {
        gdsBadge.className = 'badge warn';
        gdsBadge.textContent = 'времена из ведомости';
    }
    if (autoGds && !hasTimes && !GDS_LOADED) gdsTimes(true);
}

function defaultBody() {
    return TEMPLATES.length ? TEMPLATES[0].body : '';
}

const tplOptionsHtml = () => TEMPLATES.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
// Эффективный шаблон блока: свой у блока отправления, иначе — шаблон всей ведомости.
function blockTemplate(station) { return (station in BLOCK_TEMPLATES) ? BLOCK_TEMPLATES[station] : MANIFEST_TEMPLATE; }
function blockIsCustom(station) { return (station in BLOCK_TEMPLATES) && BLOCK_TEMPLATES[station] !== MANIFEST_TEMPLATE; }
// Город «изменён вручную» — у него свой текст, отличный от эффективного шаблона его блока.
function groupIsCustom(g) { return g.body != null && g.body !== '' && g.body !== blockTemplate(g.station); }
function updateGroupBadge(card, gi) {
    const el = card.querySelector('.g-tpl-badge');
    const custom = groupIsCustom(GROUPS[gi]);
    if (el) { el.className = 'g-tpl-badge' + (custom ? ' custom' : ''); el.textContent = custom ? '✏ изменён вручную' : 'общий шаблон'; }
    const rb = card.querySelector('.g-reset');
    if (rb) rb.style.display = custom ? '' : 'none';
}

/* Уровень 1 — шаблон на всю ведомость */
function renderManifestTemplate() {
    const box = document.getElementById('groupTemplateBox');
    if (!box) return;
    const sel = document.getElementById('gtplSelect');
    if (sel) sel.innerHTML = '<option value="">— вставить шаблон —</option>' + tplOptionsHtml();
    const ta = document.getElementById('gtplText');
    if (ta) ta.value = MANIFEST_TEMPLATE;
    box.style.display = '';
}
function manifestTemplatePick(sel) {
    const t = TEMPLATES.find(x => x.id == sel.value);
    if (t) document.getElementById('gtplText').value = t.body;
    sel.value = '';
}
async function applyManifestTemplate(btn) {
    const ta = document.getElementById('gtplText');
    const text = ta ? ta.value : '';
    if (!confirm('Применить шаблон ко ВСЕЙ ведомости?\nТексты всех блоков и городов сбросятся к этому шаблону.')) return;
    btn.disabled = true;
    const saved = document.getElementById('gtplSaved');
    if (saved) { saved.className = 'g-saved small saving'; saved.textContent = 'Применяю…'; }
    const r = await api('groups.apply_template', { manifest_id: manifestId(), scope: 'manifest', text }).catch(() => null);
    btn.disabled = false;
    if (!r || !r.ok) { if (saved) { saved.className = 'g-saved small error'; saved.textContent = (r && r.error) || 'Ошибка'; } return; }
    MANIFEST_TEMPLATE = text;
    if (saved) { saved.className = 'g-saved small saved'; saved.textContent = 'Применено ко всей ведомости'; }
    await loadGroups(false);
}

/* Уровень 2 — шаблон блока отправления */
function blockTemplatePick(sel) {
    const box = sel.closest('.origin-template');
    const t = TEMPLATES.find(x => x.id == sel.value);
    if (t) box.querySelector('.ot-text').value = t.body;
    sel.value = '';
}
async function applyBlockTemplate(btn) {
    const box = btn.closest('.origin-template');
    const station = box.dataset.station;
    const text = box.querySelector('.ot-text').value;
    if (!confirm(`Применить шаблон к блоку «${station}»?\nТексты городов этого блока сбросятся к этому шаблону.`)) return;
    btn.disabled = true;
    const r = await api('groups.apply_template', { manifest_id: manifestId(), scope: 'block', station, text }).catch(() => null);
    btn.disabled = false;
    if (!r || !r.ok) { alert((r && r.error) || 'Ошибка'); return; }
    BLOCK_TEMPLATES[station] = text;
    await loadGroups(false);
}
async function resetBlockToManifest(btn) {
    const box = btn.closest('.origin-template');
    const station = box.dataset.station;
    if (!confirm(`Вернуть блок «${station}» к общему шаблону ведомости?`)) return;
    const r = await api('groups.apply_template', { manifest_id: manifestId(), scope: 'block', station, remove: 1 }).catch(() => null);
    if (!r || !r.ok) { alert((r && r.error) || 'Ошибка'); return; }
    delete BLOCK_TEMPLATES[station];
    await loadGroups(false);
}

// Реально ли канал готов слать (авторизован/подключён), а не просто «ключи заданы».
function channelReady(ch) {
    const st = CHANNELS_STATE[ch] || (CHANNELS_ACTIVE.includes(ch) ? 'open' : 'unconfigured');
    return st === 'open' || st === 'ready';
}
function selectedSendChannels() {
    return [...SELECTED_CHANNELS].filter(channel => channelReady(channel));
}

function renderSendChannels() {
    const box = document.getElementById('sendChannels');
    if (!box) return;
    box.innerHTML = Object.entries(CHANNEL_LABELS).map(([channel, label]) => {
        const st = CHANNELS_STATE[channel] || (CHANNELS_ACTIVE.includes(channel) ? 'open' : 'unconfigured');
        const ready = st === 'open' || st === 'ready';
        const note = ready ? '' : (st === 'unconfigured' ? '<small>не подключён</small>' : '<small class="chan-off">не авторизован</small>');
        const checked = ready && SELECTED_CHANNELS.has(channel);
        return `<label class="send-channel-choice ${ready ? '' : 'disabled'}"><input type="checkbox" value="${channel}" ${checked ? 'checked' : ''} ${ready ? '' : 'disabled'} onchange="changeSendChannels(this)"><span>${label}</span>${note}</label>`;
    }).join('') + dailyCountsLine();
    updateChannelEstimate();
}
// Мягкий счётчик дневного лимита по каналам (предупреждение, не блокирует).
function dailyCountsLine() {
    const labels = { max: 'MAX', telegram: 'TG', whatsapp: 'WA', sms: 'SMS' };
    const parts = Object.entries(labels).map(([ch, l]) => {
        const n = Number(TODAY_COUNTS[ch] || 0);
        const cls = DAILY_CAP && n >= DAILY_CAP ? 'daily-over' : (DAILY_CAP && n >= DAILY_CAP * 0.8 ? 'daily-near' : '');
        return `<span class="${cls}">${l} ${n}</span>`;
    }).join(' · ');
    return `<div class="daily-counts small">Сегодня отправлено: ${parts} <span class="muted">(мягкий лимит ${DAILY_CAP}/канал)</span></div>`;
}

function changeSendChannels(input) {
    if (input.checked) SELECTED_CHANNELS.add(input.value);
    else SELECTED_CHANNELS.delete(input.value);
    if (!selectedSendChannels().length) {
        input.checked = true;
        SELECTED_CHANNELS.add(input.value);
        alert('Выберите хотя бы один канал отправки.');
    }
    updateChannelEstimate();
    renderPreparationSummary();
    if (input.checked && (input.value === 'max' || input.value === 'telegram')) {
        autoCheckManifestChannels(true, true);
    }
}

function updateChannelEstimate() {
    const box = document.getElementById('sendChannelEstimate');
    if (!box) return;
    const channels = selectedSendChannels();
    if (!channels.length) { box.className = 'small muted'; box.textContent = ''; return; }
    const b = deliveryBuckets(); // единый честный источник: уйдут / под вопросом / без канала
    const attempts = b.total * channels.length;
    let html = channels.length > 1
        ? `Параллельная отправка в ${channels.length} канала: до ${attempts} сообщений. Пассажир может получить один текст несколько раз.`
        : `Уйдут только через ${CHANNEL_LABELS[channels[0]] || channels[0]}.`;
    // «Впустую» — ТОЛЬКО когда все проверены и ни у кого нет канала. «Под вопросом» (не проверено
    // из-за лимита) сюда не считается: такие проверятся при отправке, а не проваливаются.
    const useless = b.total > 0 && b.go === 0 && b.pending === 0;
    box.className = `small ${useless || channels.length > 1 ? 'send-channel-warning' : 'muted'}`;
    box.innerHTML = html + (useless
        ? `<br><b style="color:var(--err)">⚠️ Ни у кого из получателей нет выбранного канала — отправка уйдёт впустую.</b>`
        : '');
}

function renderGroups() {
    const box = document.getElementById('groupsBox');
    box.innerHTML = '';
    if (!GROUPS.length) { box.innerHTML = '<p class="muted">В ведомости нет пассажиров.</p>'; return; }

    let totalValid = 0;
    let currentOrigin = '', routeBox = null;
    GROUPS.forEach((g, gi) => {
        const validCount = g.recipients.filter(x => x.valid).length;
        totalValid += validCount;

        if (currentOrigin !== g.station) {
            currentOrigin = g.station;
            const originGroups = GROUPS.filter(x => x.station === g.station);
            const originPassengers = originGroups.reduce((sum, x) => sum + x.recipients.length, 0);
            const origin = document.createElement('section');
            origin.className = 'notif-origin';
            origin.dataset.station = g.station; // якорь для метки «что ушло по блоку»
            origin.innerHTML = `<div class="notif-origin-head"><div><div class="notif-origin-title">${esc(g.station)}</div>
                <div class="muted small">${originPassengers} пассажиров · ${originGroups.length} направлений · посадка ${esc((g.date ? g.date + ' ' : '') + (g.time || 'время не указано'))}${g.address ? ' · ' + esc(g.address) : ''}</div>
                <div class="origin-sent small" hidden></div></div>
                <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap"><span class="badge ${originGroups.some(x => !x.time || !x.in_catalog || x.time_warning == 1) ? 'warn' : 'ok'}">${originGroups.some(x => !x.time || !x.in_catalog || x.time_warning == 1) ? 'проверьте' : 'готово'}</span><button class="btn sm block-send-btn" onclick="sendBlock(this)">Отправить блоку</button></div></div>
                <div class="origin-template" data-station="${esc(g.station)}">
                    <div class="row" style="justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
                        <span class="small">Шаблон блока <span class="g-tpl-badge${blockIsCustom(g.station) ? ' custom' : ''}">${blockIsCustom(g.station) ? '✏ свой шаблон' : 'как у ведомости'}</span></span>
                        <div class="row" style="gap:6px">
                            <select class="ot-select" onchange="blockTemplatePick(this)"><option value="">— вставить шаблон —</option>${tplOptionsHtml()}</select>
                            <button class="btn ghost sm" onclick="applyBlockTemplate(this)">Применить к блоку</button>
                            <button class="btn ghost sm ot-reset" onclick="resetBlockToManifest(this)" style="display:${blockIsCustom(g.station) ? '' : 'none'}" title="Вернуть блок к общему шаблону ведомости">↺ к ведомости</button>
                        </div>
                    </div>
                    <details class="mt"><summary class="muted small" style="cursor:pointer">Показать / править текст блока</summary>
                        <textarea class="template-box ot-text mt" rows="3">${esc(blockTemplate(g.station))}</textarea>
                    </details>
                </div>
                <div class="notif-origin-routes"></div>`;
            box.appendChild(origin);
            routeBox = origin.querySelector('.notif-origin-routes');
        }

        const card = document.createElement('div');
        card.className = 'gcard';
        card.dataset.station = g.station;
        card.dataset.gi = gi;
        const tplOptions = TEMPLATES.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        const problem = !g.time || g.time_warning == 1;
        if (problem) card.classList.add('gcard-problem');

        const badges = [];
        if (!g.in_catalog) badges.push('<span class="badge warn">нет в справочнике</span>');
        if (g.time_warning == 1) badges.push('<span class="badge err">⚠️ время = старту</span>');
        if (!g.time) badges.push('<span class="badge warn">нет времени</span>');
        const timeLabel = g.time ? `${esc((g.date ? g.date + ' ' : '') + g.time)}` : '<span style="color:var(--err)">время?</span>';

        card.innerHTML = `
        <div class="gcard-head" onclick="toggleGroup(this)">
            <div style="min-width:0">
                <div class="gtitle">${esc(g.destination)} <span class="badge muted">${validCount}</span> <span class="g-tpl-badge${groupIsCustom(g) ? ' custom' : ''}">${groupIsCustom(g) ? '✏ изменён вручную' : 'общий шаблон'}</span></div>
                <div class="gmeta">${timeLabel} · ${channelRouteSummary(g)} ${badges.join(' ')}</div>
            </div>
            <span class="gchev">▾</span>
        </div>
        <div class="gcard-body">
            <div class="row gcard-controls">
                <label class="f" style="margin:0">Дата<input type="text" class="g-date" value="${esc(g.date)}" placeholder="дд.мм.гггг" style="width:120px"></label>
                <label class="f" style="margin:0">Время<input type="text" class="g-time" value="${esc(g.time)}" placeholder="чч:мм" style="width:84px"></label>
                <span class="muted small" style="align-self:flex-end;padding-bottom:8px">Текст — из общего шаблона; правка здесь отвяжет город от общего</span>
            </div>
            <textarea class="template-box g-body" rows="5">${esc(g.body ?? blockTemplate(g.station))}</textarea>
            <div class="g-saved small" style="min-height:16px;margin:3px 0"></div>
            <div class="msg-preview g-preview mt"></div>
            <details class="mt"><summary class="muted small" style="cursor:pointer">Получатели (${g.recipients.length})</summary>
                <div class="table-wrap mt"><table class="t"><tbody>
                ${g.recipients.map(p => `
                    <tr data-pid="${p.id}"><td style="width:30px"><input type="checkbox" class="g-cb" value="${p.id}" ${p.valid ? 'checked' : ''}></td>
                    <td><b>${esc(p.name) || '—'}</b> <span class="muted small">→ ${esc(p.to)}</span>${p.unsubscribed ? ' <span class="badge err">отписан</span>' : ''}</td>
                    <td style="width:170px"><input class="cell g-phone ${p.valid ? '' : 'phone-bad'}" data-pid="${p.id}" value="${esc(p.phone)}" placeholder="+7…"></td>
                    <td class="ch-cell" style="white-space:nowrap">${channelBadges(p.channels)}</td></tr>`).join('')}
                </tbody></table></div>
                <div class="muted small mt">Телефон редактируется прямо здесь — исправьте корявые номера и нажмите Enter. Бейджи WA/MAX/TG — наличие мессенджера у номера.</div>
                <div class="row mt">
                    <button class="btn ghost sm" onclick="addGroupRecipient(this, ${gi})">+ Добавить получателя</button>
                </div>
            </details>
            <div class="row mt">
                <button class="btn sm g-send" onclick="sendGroup(this, ${gi})">Отправить этой группе</button>
                <button class="btn ghost sm g-reset" onclick="resetToGlobal(this, ${gi})" title="Вернуть текст этого города к общему шаблону группы" style="display:${groupIsCustom(g) ? '' : 'none'}">↺ Вернуть к общему</button>
                <span class="small g-state"></span>
            </div>
        </div>`;
        routeBox.appendChild(card);

        const ta = card.querySelector('.g-body');
        const refreshBody = () => { GROUPS[gi].body = ta.value; updateGroupBadge(card, gi); schedulePreview(card, gi); scheduleDraft(card, gi, true); };
        const refreshMeta = () => { schedulePreview(card, gi); scheduleDraft(card, gi, false); };
        ta.addEventListener('input', refreshBody);
        card.querySelector('.g-date').addEventListener('input', refreshMeta);
        card.querySelector('.g-time').addEventListener('input', refreshMeta);
        // редактирование телефона прямо в группе
        card.querySelectorAll('.g-phone').forEach(inp => {
            const save = async () => {
                const r = await api('passenger.update', { id: +inp.dataset.pid, field: 'phone', value: inp.value });
                if (r.ok && r.value) {
                    inp.value = r.value;
                    const ok = /^\+\d{10,15}$/.test(r.value);
                    inp.classList.toggle('phone-bad', !ok);
                    const cb = card.querySelector(`.g-cb[value="${inp.dataset.pid}"]`);
                    if (cb) { cb.disabled = !ok; if (ok) cb.checked = true; }
                    const passenger = GROUPS[gi].recipients.find(x => x.id === +inp.dataset.pid);
                    if (passenger) {
                        passenger.phone = r.value;
                        passenger.valid = ok;
                        passenger.channels = { whatsapp: null, max: null, telegram: null, checked: false };
                    }
                    renderPreparationSummary();
                    if (ok) autoCheckManifestChannels();
                }
            };
            inp.addEventListener('change', save);
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
        });
        schedulePreview(card, gi, true);
    });

    const sum = document.getElementById('sendSummary');
    if (sum) sum.innerHTML = `К отправке: <b>${totalValid}</b> получателей в ${GROUPS.length} направлениях`;
    updateBlockSentBadges(); // метки «что ушло по блоку» из последнего монитора
}

// Агрегат отправки по блоку (станции отправления) из последнего campaign.overview.
// Считаем ФАКТ отправки (sent), а не доставку — «нажал → видно, что ушло».
function blockSentAgg(station) {
    const recs = (LAST_OVERVIEW?.recipients || []).filter(r => r.station === station && r.valid);
    const sent = recs.filter(r => r.sent);
    const channels = [...new Set(sent.map(r => r.channel).filter(Boolean))];
    const lastAt = sent.reduce((m, r) => (r.sent_at && r.sent_at > m ? r.sent_at : m), '');
    return { total: recs.length, sent: sent.length, channels, lastAt };
}

// Проставить метку и подстроить кнопку на каждом блоке отправления.
function updateBlockSentBadges() {
    document.querySelectorAll('.notif-origin[data-station]').forEach(origin => {
        const station = origin.dataset.station;
        const badge = origin.querySelector('.origin-sent');
        const btn = origin.querySelector('.block-send-btn');
        if (!badge) return;
        const a = blockSentAgg(station);
        const rest = a.total - a.sent;
        const chLabel = a.channels.map(c => CHANNEL_LABELS[c] || c).join(', ');
        if (!a.sent) { // ещё не отправляли
            badge.hidden = true;
            if (btn) btn.textContent = 'Отправить блоку';
            return;
        }
        badge.hidden = false;
        const when = a.lastAt ? ' · ' + fmtSentAt(a.lastAt) : '';
        const ch = chLabel ? ' · ' + chLabel : '';
        if (rest > 0) { // ушло частично
            badge.className = 'origin-sent small origin-sent-warn';
            badge.textContent = `⚠ Отправлено ${a.sent} из ${a.total} · ${rest} не ушло${when}${ch}`;
            if (btn) btn.textContent = `Дослать ${rest}`;
        } else { // ушло всё
            badge.className = 'origin-sent small origin-sent-ok';
            badge.textContent = `✓ Отправлено ${a.sent} из ${a.total}${when}${ch}`;
            if (btn) { btn.textContent = 'Отправить повторно'; btn.classList.add('ghost'); }
        }
    });
}

// «сегодня 19:23» / «14.07 19:23» из ISO-времени сервера.
function fmtSentAt(iso) {
    const d = new Date((iso || '').replace(' ', 'T'));
    if (isNaN(d)) return '';
    const hh = String(d.getHours()).padStart(2, '0'), mm = String(d.getMinutes()).padStart(2, '0');
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    return sameDay ? `сегодня ${hh}:${mm}` : `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')} ${hh}:${mm}`;
}

function manifestRecipients() {
    return GROUPS.flatMap((group, groupIndex) => group.recipients.map(recipient => ({ ...recipient, groupIndex, group })));
}

function channelRouteSummary(group) {
    const counts = { whatsapp: 0, max: 0, telegram: 0, unknown: 0 };
    group.recipients.filter(x => x.valid).forEach(recipient => {
        const channels = recipient.channels || {};
        if (!channels.checked) counts.unknown++;
        if (channels.whatsapp === true) counts.whatsapp++;
        else if (channels.max === true) counts.max++;
        else if (channels.telegram === true) counts.telegram++;
    });
    const parts = [];
    if (counts.whatsapp) parts.push(`<span class="ch-badge on-wa">WA ${counts.whatsapp}</span>`);
    if (counts.max) parts.push(`<span class="ch-badge on-max">MAX ${counts.max}</span>`);
    if (counts.telegram) parts.push(`<span class="ch-badge on-tg">TG ${counts.telegram}</span>`);
    if (counts.unknown) parts.push(`<span class="badge muted">каналы: ${CHANNEL_CHECKING ? 'проверяю' : 'не проверены'} ${counts.unknown}</span>`);
    return parts.join(' ');
}

function preparationIssues() {
    const issues = [];
    const missingStations = new Set();
    GROUPS.forEach((group, groupIndex) => {
        if (!group.time) issues.push({ type: 'route', level: 'err', groupIndex, title: `${group.station} → ${group.destination}`, text: 'Не указано время посадки.' });
        else if (group.time_warning == 1) issues.push({ type: 'route', level: 'err', groupIndex, title: `${group.station} → ${group.destination}`, text: 'Время совпало со стартом рейса — требуется проверка.' });
        if (!group.in_catalog && !missingStations.has(group.station)) {
            missingStations.add(group.station);
            issues.push({ type: 'route', level: 'err', groupIndex, title: group.station, text: 'Нет адреса посадки в справочнике.' });
        }
        group.recipients.forEach(recipient => {
            if (!recipient.valid) {
                issues.push({ type: 'recipient', level: 'err', groupIndex, passengerId: recipient.id, title: recipient.name || 'Пассажир без имени', text: `Некорректный телефон: ${recipient.phone || 'не указан'}.` });
                return;
            }
            if (!recipient.channels?.checked) return;
            [...SELECTED_CHANNELS].forEach(channel => {
                if (!MESSENGERS.includes(channel)) return; // SMS — наличие у номера не проверяется
                const has = recipient.channels[channel];
                if (has === false) {
                    const spare = MESSENGERS.find(c => c !== channel && recipient.channels[c] === true);
                    issues.push({ type: 'channel', level: spare ? 'warn' : 'err', groupIndex, passengerId: recipient.id,
                        title: recipient.name || recipient.phone,
                        text: spare ? `${CHANNEL_LABELS[channel]} не найден; у номера доступен ${CHANNEL_LABELS[spare]}.` : `${CHANNEL_LABELS[channel]} не найден; другой мессенджер не обнаружен.` });
                } else if (has === null || has === undefined) {
                    // Не «канала нет», а «не смогли проверить» — обычно упёрлись в лимит мессенджера.
                    issues.push({ type: 'channel', level: 'warn', groupIndex, passengerId: recipient.id,
                        title: recipient.name || recipient.phone,
                        text: `${CHANNEL_LABELS[channel]}: наличие не проверено — сообщение может не уйти.` });
                }
            });
        });
    });
    return issues;
}

// Судьба получателя по выбранным каналам: уйдёт / не уйдёт / под вопросом.
//   go      — хотя бы один выбранный канал подтверждён у номера (chatId есть) ИЛИ выбран SMS.
//   no      — все выбранные мессенджеры проверены и канала нет.
//   pending — хоть один выбранный канал ещё не проверен (упёрлись в лимит или не успели).
// При отправке «под вопросом» до-проверяются на месте, так что go — это гарантированный минимум.
function recipientDelivery(recipient, channels) {
    if (channels.some(c => !MESSENGERS.includes(c))) return 'go'; // SMS — наличие у номера не проверяется, уйдёт всегда
    if (!recipient.channels?.checked) return 'pending';
    let anyTrue = false, anyUnknown = false;
    for (const c of channels) {
        const v = recipient.channels[c];
        if (v === true) anyTrue = true;
        else if (v === null || v === undefined) anyUnknown = true;
    }
    return anyTrue ? 'go' : (anyUnknown ? 'pending' : 'no');
}

function deliveryBuckets() {
    const channels = selectedSendChannels();
    const valid = manifestRecipients().filter(x => x.valid);
    const b = { go: 0, no: 0, pending: 0, total: valid.length };
    if (!channels.length) { b.pending = valid.length; return b; }
    valid.forEach(r => { b[recipientDelivery(r, channels)]++; });
    return b;
}

function renderPreparationSummary() {
    const box = document.getElementById('notificationReadiness');
    const issueBox = document.getElementById('notificationIssues');
    if (!box || !issueBox) return;
    const recipients = manifestRecipients();
    const valid = recipients.filter(x => x.valid).length;
    const checked = recipients.filter(x => x.valid && x.channels?.checked).length;
    const issues = preparationIssues();
    const blocking = issues.filter(x => x.level === 'err' && x.type === 'route').length;
    const selectedChannels = selectedSendChannels();
    const attemptCount = valid * selectedChannels.length;
    const b = deliveryBuckets(); // три корзины: уйдут / под вопросом / не уйдут
    const chLabel = selectedChannels.map(c => CHANNEL_LABELS[c] || c).join(', ') || '—';
    const title = CHANNEL_CHECKING ? 'Проверяю получателей и каналы…' : (blocking ? `Нужно исправить: ${blocking}` : 'Рассылка готова к подтверждению');
    // Подзаголовок прямо отвечает на «уйдёт или нет».
    const subtitle = CHANNEL_CHECKING ? `Проверено ${checked} из ${valid} корректных номеров…`
        : blocking ? 'Отправка будет доступна после проверки критичных данных.'
        : b.pending ? `Точно уйдут: ${b.go} · под вопросом: ${b.pending} (проверятся при отправке) · без канала: ${b.no}. Каналы: ${esc(chLabel)}.`
        : `Уйдут ${b.go} из ${valid} через ${esc(chLabel)}${b.no ? ` · без канала: ${b.no}` : ''}.`;
    const btnLabel = CHANNEL_CHECKING ? `Отправить ${valid} пассажирам`
        : b.pending ? `Отправить ${valid} · уйдёт ≥ ${b.go}`
        : `Отправить ${valid} · уйдёт ${b.go}`;
    box.innerHTML = `<div class="notif-ready-top"><div class="notif-ready-head"><div class="notif-ready-icon ${blocking ? 'warn' : ''}">${blocking ? '!' : '✓'}</div><div><div class="notif-ready-title">${esc(title)}</div><div class="muted small">${esc(subtitle)}</div></div></div>
        <button class="btn" data-send-all onclick="sendAllGroups(this)">${esc(btnLabel)}</button></div>
        <div class="notif-metrics"><div><b>${valid}</b><span>получателей</span></div><div><b style="color:var(--ok)">${CHANNEL_CHECKING ? '…' : b.go}</b><span>уйдут</span></div><div class="${b.pending ? 'warn' : ''}"><b${b.pending ? ' style="color:var(--warn)"' : ''}>${CHANNEL_CHECKING ? '…' : b.pending}</b><span>под вопросом</span></div><div class="${b.no ? 'warn' : ''}"><b${b.no ? ' style="color:var(--err)"' : ''}>${b.no}</b><span>без канала</span></div></div>
        <div class="notif-auto-check"><span class="badge ${CHANNEL_CHECKING ? 'warn' : 'ok'}">${CHANNEL_CHECKING ? 'идёт проверка' : 'проверка завершена'}</span>
        <button class="btn ghost sm" onclick="autoCheckManifestChannels(true, true)" ${CHANNEL_CHECKING ? 'disabled' : ''} title="Заново проверить у всех номеров наличие MAX / Telegram / WhatsApp">🔄 Проверить мессенджеры</button>
        ${CHANNEL_LIMITED.length && !CHANNEL_CHECKING ? `<span class="muted small">Лимит проверки MAX временно исчерпан — оставшиеся проверятся при отправке или позже.</span>` : ''}</div>`;

    const visibleIssues = issues.slice(0, 12);
    issueBox.innerHTML = issues.length ? `<div class="notif-issues"><div class="notif-issues-head"><b>Требует внимания · ${issues.length}</b><span class="muted small">все проблемы собраны в одном месте</span></div>${visibleIssues.map(issue => `
        <div class="notif-issue"><span class="badge ${issue.level === 'err' ? 'err' : 'warn'}">${issue.level === 'err' ? 'исправить' : 'проверить'}</span><div><b>${esc(issue.title)}</b><div class="muted small">${esc(issue.text)}</div></div><button class="btn ghost sm" onclick="openNotificationGroup(${issue.groupIndex}, ${issue.passengerId || 0})">Открыть</button></div>`).join('')}${issues.length > visibleIssues.length ? `<div class="muted small" style="padding:10px 14px">И ещё ${issues.length - visibleIssues.length}…</div>` : ''}</div>` : '';

    document.querySelectorAll('[data-send-all]').forEach(sendBtn => {
        sendBtn.disabled = blocking > 0 || CHANNEL_CHECKING;
        sendBtn.innerHTML = `${sendBtn.querySelector('svg')?.outerHTML || ''} ${esc(btnLabel)}${selectedChannels.length > 1 ? ` · до ${attemptCount} сообщений` : ''}`;
    });
    const sendSummary = document.getElementById('sendSummary');
    if (sendSummary) sendSummary.innerHTML = blocking
        ? `<b style="color:var(--err)">Сначала исправьте критичные данные: ${blocking}</b>`
        : `<b style="color:var(--ok)">Уйдут: ${b.go}</b>${b.pending ? ` · <b style="color:var(--warn)">под вопросом: ${b.pending}</b>` : ''}${b.no ? ` · без канала: ${b.no}` : ''} · ${GROUPS.length} направлений · ${esc(chLabel)}`;
    updateChannelEstimate();
}

function openNotificationGroup(groupIndex, passengerId = 0) {
    const card = document.querySelector(`.gcard[data-gi="${groupIndex}"]`);
    if (!card) return;
    card.classList.add('open');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (passengerId) {
        const details = card.querySelector('details');
        if (details) details.open = true;
        const phone = card.querySelector(`.g-phone[data-pid="${passengerId}"]`);
        if (phone) setTimeout(() => phone.focus(), 350);
    }
}

function updateChannelCells() {
    GROUPS.forEach(group => group.recipients.forEach(recipient => {
        const row = document.querySelector(`tr[data-pid="${recipient.id}"]`);
        const cell = row?.querySelector('.ch-cell');
        if (cell) cell.innerHTML = channelBadges(recipient.channels);
    }));
    document.querySelectorAll('.gcard').forEach(card => {
        const gi = +card.dataset.gi;
        const meta = card.querySelector('.gmeta');
        if (meta && GROUPS[gi]) {
            const badges = [...meta.querySelectorAll('.badge.err,.badge.warn')].map(x => x.outerHTML).join(' ');
            const g = GROUPS[gi];
            const time = g.time ? esc((g.date ? g.date + ' ' : '') + g.time) : '<span style="color:var(--err)">время?</span>';
            meta.innerHTML = `${time} · ${channelRouteSummary(g)} ${badges}`;
        }
    });
}

async function autoCheckManifestChannels(force = false, checkAllMessengers = false) {
    if (CHANNEL_CHECKING) {
        if (checkAllMessengers) FULL_CHANNEL_CHECK_REQUESTED = true;
        return;
    }
    const pending = manifestRecipients().filter(x => x.valid && (force || !x.channels?.checked));
    const phones = [...new Set(pending.map(x => x.phone).filter(Boolean))];
    if (!phones.length) { renderPreparationSummary(); return; }
    CHANNEL_CHECKING = true;
    CHANNEL_LIMITED = [];
    renderPreparationSummary();
    try {
        // Порции меньше и с паузой — дозаполняем chatId «тихо», а не залпом; на первом лимите
        // мессенджера останавливаемся (дальше долбить исчерпанную квоту бессмысленно — оставшиеся
        // проверятся при отправке или позже). Быстро проверяемые из кэша провайдера лимит не тратят.
        const CHUNK = 20;
        for (let i = 0; i < phones.length; i += CHUNK) {
            // force — перепроверить даже свежие результаты (кнопка «Проверить мессенджеры»).
            // Без force сервер не трогает известные свежие ответы и бережёт лимит мессенджера.
            const result = await api('channels.check', { phones: phones.slice(i, i + CHUNK), force: checkAllMessengers ? 1 : 0 }).catch(() => null);
            if (!result?.ok) continue;
            GROUPS.forEach(group => group.recipients.forEach(recipient => {
                const norm = recipient.phone.replace(/\D+/g, '');
                const key = Object.keys(result.presence || {}).find(phone => phone.replace(/\D+/g, '') === norm);
                if (key) recipient.channels = result.presence[key];
            }));
            updateChannelCells();
            renderPreparationSummary();
            if (result.limited?.length) { // квота исчерпана — прекращаем, чтобы не жечь её впустую
                CHANNEL_LIMITED = [...new Set([...CHANNEL_LIMITED, ...result.limited])];
                break;
            }
            if (i + CHUNK < phones.length) await new Promise(r => setTimeout(r, 400)); // дать UI/квоте дышать
        }
    } finally {
        CHANNEL_CHECKING = false;
        updateChannelCells();
        renderPreparationSummary();
        loadCampaignOverview();
        if (FULL_CHANNEL_CHECK_REQUESTED) {
            FULL_CHANNEL_CHECK_REQUESTED = false;
            autoCheckManifestChannels(true, true);
        }
    }
}

function toggleGroup(head) {
    head.closest('.gcard').classList.toggle('open');
}

let previewTimers = {};
function schedulePreview(card, gi, now = false) {
    clearTimeout(previewTimers[gi]);
    previewTimers[gi] = setTimeout(async () => {
        const r = await api('group.preview', {
            manifest_id: manifestId(),
            station: GROUPS[gi].station,
            destination: GROUPS[gi].destination,
            text: card.querySelector('.g-body').value,
            date: card.querySelector('.g-date').value,
            time: card.querySelector('.g-time').value,
            phone_on: document.getElementById('driverPhoneOn')?.checked ? 1 : 0,
        });
        if (r.ok) {
            let html = waFmt(r.preview);
            if (r.unknown && r.unknown.length) {
                html += `<div class="badge warn" style="margin-top:8px">⚠️ не распознаны переменные: ${r.unknown.map(esc).join(', ')} — проверьте написание или добавьте в Справочники → Переменные</div>`;
            }
            card.querySelector('.g-preview').innerHTML = html;
        }
    }, now ? 50 : 700);
}
// Выбор автобуса из справочника → подставить номер + телефон водителя, применить к группам.
async function pickBus(sel) {
    const opt = sel.options[sel.selectedIndex];
    sel.selectedIndex = 0;
    if (!opt || (!opt.dataset.code && !opt.dataset.phone)) return;
    const id = +document.getElementById('tripFacts').dataset.id;
    if (opt.dataset.code) {
        document.querySelector('#tripFacts [data-f="bus"]').value = opt.dataset.code;
        await api('manifest.update', { id, field: 'bus', value: opt.dataset.code });
    }
    if (opt.dataset.phone) {
        document.querySelector('#tripFacts [data-f="driver_phone"]').value = opt.dataset.phone;
        await api('manifest.update', { id, field: 'driver_phone', value: opt.dataset.phone });
    }
    setState('Применено к группам');
    refreshAllPreviews();
}

// Выбор водителя из справочника → подставить имя (в ведомость) + телефон, применить к группам.
async function pickDriver(sel) {
    const opt = sel.options[sel.selectedIndex];
    sel.selectedIndex = 0;
    if (!opt || (!opt.dataset.name && !opt.dataset.phone)) return;
    const id = +document.getElementById('tripFacts').dataset.id;
    if (opt.dataset.name) await api('manifest.update', { id, field: 'drivers', value: opt.dataset.name });
    if (opt.dataset.phone) {
        document.querySelector('#tripFacts [data-f="driver_phone"]').value = opt.dataset.phone;
        await api('manifest.update', { id, field: 'driver_phone', value: opt.dataset.phone });
    }
    setState('Применено к группам');
    refreshAllPreviews();
}

function refreshAllPreviews() {
    document.querySelectorAll('.gcard').forEach((card, gi) => schedulePreview(card, gi, true));
}

function setSaved(card, state) {
    const st = card.querySelector('.g-saved');
    if (!st) return;
    const map = {
        dirty: ['● есть несохранённые изменения', '#b26a00'],
        saving: ['сохраняю…', '#777'],
        saved: ['✓ сохранено', '#1a7f37'],
        error: ['⚠ не удалось сохранить — повторите правку', '#c0392b'],
    };
    const [txt, color] = map[state] || ['', '#777'];
    st.textContent = txt;
    st.style.color = color;
}

let draftTimers = {};
function scheduleDraft(card, gi, saveBody) {
    clearTimeout(draftTimers[gi]);
    setSaved(card, 'dirty');
    draftTimers[gi] = setTimeout(async () => {
        setSaved(card, 'saving');
        const payload = {
            manifest_id: manifestId(),
            station: GROUPS[gi].station,
            destination: GROUPS[gi].destination,
            date: card.querySelector('.g-date').value,
            time: card.querySelector('.g-time').value,
        };
        if (saveBody) { payload.body = card.querySelector('.g-body').value; payload.save_body = 1; }
        const r = await api('group.save', payload);
        setSaved(card, r && r.ok ? 'saved' : 'error');
    }, 900);
}

function monitorChip(rec) {
    if (!rec.sent) return '<span class="badge muted">не отправлено</span>';
    const map = { read: ['ok', 'прочитано ✓✓'], delivered: ['ok', 'доставлено ✓✓'], sent: ['muted', 'отправлено ✓'], failed: ['err', 'ошибка'], pending: ['muted', 'в очереди'] };
    const [cls, label] = map[rec.state] || ['muted', '—'];
    const ch = rec.channel && rec.channel !== 'whatsapp' ? ` <span class="muted small">${esc(rec.channel.toUpperCase())}</span>` : '';
    const title = rec.error ? ` title="${esc(rec.error)}"` : '';
    let html = `<span class="badge ${cls}"${title}>${label}</span>${ch}`;
    if (rec.replied) html += ' <span class="badge ok" title="пассажир ответил">есть ответ</span>';
    return html;
}

function needsResend(rec) {
    if (!rec.sent || rec.state === 'failed') return true;
    if (rec.channels && rec.channels.whatsapp === false && rec.state !== 'read' && rec.state !== 'delivered') return true;
    if (rec.state === 'sent' && rec.sent_at) {
        const t = new Date(rec.sent_at.replace(' ', 'T')).getTime();
        if (!isNaN(t) && (Date.now() - t) / 60000 > 30) return true;
    }
    return false;
}

function resendButtons(rec, gi) {
    // Запасные каналы — все настроенные, кроме основного (он и так использован при рассылке).
    const fallbacks = CHANNELS_ACTIVE.filter(c => c !== PRIMARY_CHANNEL);
    if (!fallbacks.length) return '';
    const urgent = needsResend(rec);
    const labels = { max: 'МАКС', telegram: 'TG', whatsapp: 'WA', sms: 'SMS' };
    return fallbacks.map(c => {
        const has = rec.channels ? rec.channels[c] : null;
        if (has === false && c !== 'sms') return '';
        const st = urgent ? 'style="border-color:#e0a96d;color:#a85d00"' : 'style="opacity:.55"';
        return `<button class="btn ghost sm" ${st} onclick="resendOne(this, ${gi}, '${esc(rec.phone)}', '${c}')" title="Дослать в ${labels[c] || c}">${labels[c] || c}</button>`;
    }).filter(Boolean).join(' ');
}

async function resendOne(btn, gi, phone, channel) {
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = '…';
    const r = await api('recipient.resend', { manifest_id: manifestId(), phone, channel }).catch(() => null);
    if (!r || !r.ok) { btn.disabled = false; btn.textContent = old; alert((r && r.error) || 'Не удалось дослать'); return; }
    const det = btn.closest('.gcard')?.querySelector('.g-monitor-wrap');
    if (det) loadGroupMonitor(det, gi);
    loadCampaignOverview(true);
}

function renderMonitor(r, gi) {
    if (!r.recipients || !r.recipients.length) return '<span class="muted small">нет получателей</span>';
    const c = (s) => r.recipients.filter(x => x.state === s).length;
    const repl = r.recipients.filter(x => x.replied).length;
    const head = `<div class="muted small" style="margin-bottom:8px">${r.recipients.length} получателей · прочитали ${c('read')} · доставлено ${c('delivered')}${c('failed') ? ' · ошибок ' + c('failed') : ''}${repl ? ' · ответили ' + repl : ''}</div>`;
    const rows = r.recipients.map(rec => `
        <tr><td><b>${esc(rec.name) || '—'}</b> <span class="muted small">${esc(rec.phone)}</span></td>
        <td>${monitorChip(rec)}</td>
        <td style="white-space:nowrap">${channelBadges(rec.channels)}</td>
        <td style="text-align:right;white-space:nowrap">${resendButtons(rec, gi)} <a class="btn ghost sm" href="/?p=chats&phone=${encodeURIComponent(rec.phone)}">чат</a></td></tr>`).join('');
    return head + `<div class="table-wrap"><table class="t"><tbody>${rows}</tbody></table></div>`;
}

function overviewRecipientRow(rec) {
    return `<tr><td><b>${esc(rec.name) || '—'}</b><div class="muted small">${esc(rec.station)} → ${esc(rec.destination)}</div></td>
        <td><span class="muted small">${esc(rec.phone)}</span></td><td>${monitorChip(rec)}</td>
        <td style="white-space:nowrap">${channelAttemptBadges(rec)}</td>
        <td style="text-align:right;white-space:nowrap">${rec.sent ? resendButtons(rec, rec.group_index) : ''} <a class="btn ghost sm" href="/?p=chats&phone=${encodeURIComponent(rec.phone)}">чат</a></td></tr>`;
}

function channelAttemptBadges(rec) {
    const labels = { max: 'MAX', telegram: 'TG', whatsapp: 'WA', sms: 'SMS' };
    const states = rec.channel_states || {};
    const stateLabels = { read: 'прочитано', delivered: 'доставлено', sent: 'отправлено', failed: 'ошибка', pending: 'очередь' };
    const entries = Object.entries(states);
    if (!entries.length) return channelBadges(rec.channels);
    return `<span class="channel-attempts">${entries.map(([channel, info]) => {
        const cls = info.state === 'failed' ? 'err' : (info.state === 'read' || info.state === 'delivered' ? 'ok' : 'muted');
        return `<span class="badge ${cls}" title="${esc(info.error || stateLabels[info.state] || info.state)}">${labels[channel] || channel}: ${stateLabels[info.state] || info.state}</span>`;
    }).join('')}</span>`;
}

function renderCampaignOverview(result) {
    const box = document.getElementById('campaignOverview');
    if (!box) return;
    const summary = result.summary || {};
    const sentTotal = Math.max(0, (summary.total || 0) - (summary.not_sent || 0));
    if (!sentTotal) {
        box.innerHTML = `<div class="notif-overview-empty"><b>Рассылка ещё не запускалась</b><div class="muted small">После отправки здесь автоматически появятся итоговые статусы и пассажиры, которым нужна досылка.</div></div>`;
        return;
    }
    const success = (summary.read || 0) + (summary.delivered || 0);
    const attention = (result.recipients || []).filter(rec => rec.sent && needsResend(rec));
    const pending = (summary.sent || 0) + (summary.pending || 0);
    box.innerHTML = `<div class="notif-delivery-metrics"><div><b>${summary.read || 0}</b><span>прочитали</span></div><div><b>${summary.delivered || 0}</b><span>доставлено</span></div><div><b>${pending}</b><span>ожидаем статус</span></div><div class="${attention.length ? 'warn' : ''}"><b>${attention.length}</b><span>нужно действие</span></div></div>
        <div class="notif-delivery-line"><span style="width:${sentTotal ? Math.round(success / sentTotal * 100) : 0}%"></span></div>
        <div class="row" style="justify-content:space-between;gap:10px;flex-wrap:wrap"><span class="muted small">Отправлено ${sentTotal} · успешная доставка подтверждена для ${success} · ответили ${summary.replied || 0}</span>${summary.replied ? `<a class="btn ghost sm" href="/?p=chats">Открыть ответы · ${summary.replied}</a>` : ''}</div>
        ${attention.length ? `<div class="notif-attention mt"><div class="notif-issues-head"><b>Нужно действие · ${attention.length}</b><span class="muted small">здесь только ошибки и недоставленные сообщения</span></div><div class="table-wrap"><table class="t"><tbody>${attention.map(overviewRecipientRow).join('')}</tbody></table></div></div>` : `<div class="alert ok mt">Критичных ошибок доставки нет. Статусы продолжат обновляться автоматически.</div>`}
        <details class="mt"><summary class="muted small" style="cursor:pointer">Все получатели и статусы (${result.recipients.length})</summary><div class="table-wrap mt"><table class="t"><tbody>${result.recipients.map(overviewRecipientRow).join('')}</tbody></table></div></details>`;
}

async function loadCampaignOverview(manual = false) {
    const box = document.getElementById('campaignOverview');
    if (!box || !manifestId()) return;
    if (manual) box.innerHTML = '<p class="muted">Обновляю статусы…</p>';
    const result = await api('campaign.overview', { manifest_id: manifestId() }).catch(() => null);
    if (!result?.ok) {
        if (manual) box.innerHTML = '<div class="alert warn">Не удалось обновить статусы. Повторите позже.</div>';
        return;
    }
    CHANNELS_ACTIVE = result.channels_active?.length ? result.channels_active : CHANNELS_ACTIVE;
    LAST_OVERVIEW = result;
    renderCampaignOverview(result);
    updateBlockSentBadges(); // обновить метки блоков свежими статусами
    clearTimeout(OVERVIEW_TIMER);
    const hasPending = (result.summary?.sent || 0) + (result.summary?.pending || 0) > 0;
    if (hasPending) OVERVIEW_TIMER = setTimeout(() => loadCampaignOverview(), 15000);
}

async function loadGroupMonitor(el, gi) {
    const card = el.closest('.gcard');
    const box = card.querySelector('.g-monitor');
    if (!box) return;
    if (!box.dataset.loaded) box.innerHTML = '<span class="muted small">загружаю статусы…</span>';
    const r = await api('campaign.status', {
        manifest_id: manifestId(), station: GROUPS[gi].station, destination: GROUPS[gi].destination,
    }).catch(() => null);
    if (!r || !r.ok) { box.innerHTML = '<span class="muted small">не удалось загрузить статусы</span>'; return; }
    box.dataset.loaded = '1';
    box.innerHTML = renderMonitor(r, gi);
    const det = card.querySelector('.g-monitor-wrap');
    clearTimeout(card._monTimer);
    const pending = r.recipients.some(x => x.sent && x.state !== 'read' && x.state !== 'failed');
    if (det && det.open && pending) card._monTimer = setTimeout(() => { if (det.open) loadGroupMonitor(det, gi); }, 12000);
}

async function checkContactsChannels(btn) {
    const phones = [...document.querySelectorAll('#contactsTable .phone a')].map(a => a.textContent.trim()).filter(Boolean);
    if (!phones.length) return;
    btn.disabled = true;
    const st = document.getElementById('chChkState');
    for (let i = 0; i < phones.length; i += 50) {
        if (st) st.textContent = `проверяю ${Math.min(i + 50, phones.length)}/${phones.length}…`;
        await api('channels.check', { phones: phones.slice(i, i + 50) }).catch(() => null);
    }
    if (st) st.textContent = 'готово, обновляю…';
    location.reload();
}

function channelBadges(ch) {
    if (!ch) return '';
    const defs = { whatsapp: ['WA', 'WhatsApp', 'wa'], max: ['MAX', 'MAX', 'max'], telegram: ['TG', 'Telegram', 'tg'] };
    const items = CHANNELS_ACTIVE.filter(k => defs[k]).map(k => {
        const on = ch[k], lbl = defs[k][0], name = defs[k][1], key = defs[k][2];
        const cls = on === true ? 'on-' + key : (on === false ? 'off' : 'unk');
        const t = on === true ? 'есть' : (on === false ? 'нет' : 'не проверено');
        return `<span class="ch-badge ${cls}" title="${name}: ${t}">${lbl}</span>`;
    });
    return items.length ? `<span style="display:inline-flex;gap:3px">${items.join('')}</span>` : '';
}

async function checkGroupChannels(btn, gi) {
    const card = btn.closest('.gcard');
    const phones = [...card.querySelectorAll('.g-phone')].map(i => i.value.trim()).filter(Boolean);
    if (!phones.length) return;
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'проверяю каналы…';
    const r = await api('channels.check', { phones }).catch(() => null);
    btn.disabled = false;
    btn.textContent = old;
    if (r && r.ok && r.presence) {
        card.querySelectorAll('tr[data-pid]').forEach(tr => {
            const norm = (tr.querySelector('.g-phone')?.value || '').replace(/\D+/g, '');
            const key = Object.keys(r.presence).find(k => k.replace(/\D+/g, '') === norm);
            const cell = tr.querySelector('.ch-cell');
            if (key && cell) cell.innerHTML = channelBadges(r.presence[key]);
        });
    }
}

async function gdsTimes(silent) {
    const btn = document.getElementById('gdsBtn');
    const info = document.getElementById('gdsInfo');
    const badge = document.getElementById('gdsBadge');
    if (btn) btn.disabled = true;
    if (badge) { badge.className = 'badge warn'; badge.textContent = 'запрашиваю GDS…'; }
    try {
        const r = await api('gds.times', { manifest_id: manifestId(), refresh: 1 });
        GDS_LOADED = true;
        if (!r.ok) {
            if (badge) { badge.className = 'badge err'; badge.textContent = 'GDS: не найдено'; }
            info.innerHTML = '<div class="alert warn">' + esc(r.error) + ' Время в группах заполните вручную.</div>';
            return;
        }
        if (r.from_cache) {
            if (badge) { badge.className = 'badge warn'; badge.textContent = '⚠️ времена из сохранённых (GDS не ответил)'; }
            let html = `<div class="alert warn"><b>⚠️ GDS не ответил — времена взяты из сохранённого расписания`
                + (r.cached_at ? ' от ' + esc(r.cached_at.slice(0, 16).replace('T', ' ')) : '') + '.</b> Обязательно проверьте время по станциям перед отправкой!';
            if (r.gds_error) html += `<br><span class="small">Причина: ${esc(r.gds_error)}</span>`;
            if (r.kept_from_file) html += `<br>Оставлено из ведомости (в ГДС нет): ${r.kept_from_file}.`;
            if (r.unmatched.length) html += `<br>Без времени: ${r.unmatched.map(esc).join(', ')} — заполните вручную.`;
            info.innerHTML = html + '</div>';
        } else {
            if (badge) {
                badge.className = r.statement_match ? 'badge ok' : 'badge warn';
                badge.textContent = r.statement_match ? 'времена из GDS ✓' : 'GDS: номер ведомости не совпал';
            }
            let html = `<div class="alert ${r.statement_match ? 'ok' : 'warn'}">Рейс найден, времена обновлены из ГДС для ${r.updated} групп.`
                + (r.statement_match ? '' : ' <b>Номер ведомости НЕ совпадает — проверьте рейс!</b>');
            if (r.kept_from_file) html += `<br>Оставлено из ведомости (в ГДС нет): ${r.kept_from_file}.`;
            if (r.unmatched.length) html += `<br>Без времени остались: ${r.unmatched.map(esc).join(', ')} — заполните вручную.`;
            info.innerHTML = html + '</div>';
        }
        await loadGroups(false);
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function resetToGlobal(btn, gi) {
    const card = btn.closest('.gcard');
    const st = GROUPS[gi].station;
    card.querySelector('.g-body').value = blockTemplate(st);
    GROUPS[gi].body = null;
    updateGroupBadge(card, gi);
    setSaved(card, 'saving');
    const r = await api('group.save', {
        manifest_id: manifestId(),
        station: st,
        destination: GROUPS[gi].destination,
        body: null,
        save_body: 1,
        date: card.querySelector('.g-date').value,
        time: card.querySelector('.g-time').value,
    });
    setSaved(card, r && r.ok ? 'saved' : 'error');
    schedulePreview(card, gi, true);
}

async function addGroupRecipient(btn, gi) {
    const name = prompt('Имя получателя:');
    if (name === null) return;
    const phone = prompt('Телефон (+7… или другой):');
    if (!phone) return;
    await api('passenger.add', {
        manifest_id: manifestId(),
        name, phone, from_stop: GROUPS[gi].station, to_stop: GROUPS[gi].destination,
    });
    loadGroups();
}

async function sendGroup(btn, gi, silent = false) {
    const card = btn.closest('.gcard');
    const ids = [...card.querySelectorAll('.g-cb:checked')].map(c => +c.value);
    const state = card.querySelector('.g-state');
    if (!ids.length) { state.textContent = 'Никто не выбран'; return { sent: 0, failed: 0 }; }
    const channels = selectedSendChannels();
    const channelLabels = { whatsapp: 'WhatsApp', max: 'MAX', telegram: 'Telegram', sms: 'SMS' };
    if (!channels.length) { state.textContent = 'Не выбран канал'; return { sent: 0, failed: 0 }; }
    const parallel = channels.length > 1 ? `\nКаждый пассажир может получить ${channels.length} одинаковых сообщения.` : '';
    if (!silent && !confirm(`Отправить группе «${GROUPS[gi].station} → ${GROUPS[gi].destination}» (${ids.length} получателей) через ${channels.map(x => channelLabels[x] || x).join(', ')}?${parallel}`)) return;
    btn.disabled = true;
    state.textContent = 'Отправляю…';
    try {
        const r = await api('campaign.send', {
            manifest_id: manifestId(),
            station: GROUPS[gi].station,
            destination: GROUPS[gi].destination,
            channels,
            ids,
            text: card.querySelector('.g-body').value,
            date: card.querySelector('.g-date').value,
            time: card.querySelector('.g-time').value,
            attach_photo: document.getElementById('attachPhoto').checked ? 1 : 0,
            phone_on: document.getElementById('driverPhoneOn')?.checked ? 1 : 0,
        });
        if (!r.ok) { state.innerHTML = '<span class="badge err">' + esc(r.error) + '</span>'; return r; }
        state.innerHTML = `<span class="badge ${r.failed ? 'warn' : 'ok'}">сообщений отправлено ${r.sent}, ошибок ${r.failed}${r.duplicates ? ', без дубля ' + r.duplicates : ''}${r.rest ? ', в очереди ' + r.rest : ''}</span>`
            + (r.errors?.length ? `<div class="muted small">${r.errors.map(esc).join('<br>')}</div>` : '');
        const mon = card.querySelector('.g-monitor-wrap');
        if (mon && !silent) { mon.open = true; loadGroupMonitor(mon, gi); }
        if (!silent) loadCampaignOverview(true);
        return r;
    } finally {
        btn.disabled = false;
    }
}

async function sendAllGroups(btn) {
    const valid = manifestRecipients().filter(x => x.valid).length;
    const routeProblems = preparationIssues().filter(x => x.level === 'err' && x.type === 'route').length;
    if (routeProblems) { alert('Сначала исправьте критичные данные в блоке «Требует внимания».'); return; }
    const channels = selectedSendChannels();
    if (!channels.length) { alert('Не выбран канал отправки.'); return; }
    const attempts = valid * channels.length;
    const duplicateWarning = channels.length > 1 ? `\n\nВНИМАНИЕ: выбрано ${channels.length} канала. Пассажир может получить одинаковое сообщение в каждом канале.` : '';
    if (!confirm(`Отправить актуальные сообщения ${valid} пассажирам в ${GROUPS.length} направлениях?\nКаналы: ${channels.map(x => CHANNEL_LABELS[x] || x).join(', ')}. До ${attempts} сообщений.${duplicateWarning}`)) return;
    const sendButtons = [...document.querySelectorAll('[data-send-all]')];
    sendButtons.forEach(button => button.disabled = true);
    const all = document.getElementById('allState');
    let total = 0, failed = 0, duplicates = 0;
    const cards = document.querySelectorAll('.gcard');
    for (let gi = 0; gi < cards.length; gi++) {
        all.innerHTML = `<div class="alert warn">Группа ${gi + 1} из ${cards.length}: ${esc(GROUPS[gi].station)}…</div>`;
        const r = await sendGroup(cards[gi].querySelector('.g-send'), gi, true) || {};
        total += r.sent || 0;
        failed += r.failed || 0;
        duplicates += r.duplicates || 0;
    }
    all.innerHTML = `<div class="alert ${failed ? 'warn' : 'ok'}">Готово: сообщений отправлено ${total}, ошибок ${failed}${duplicates ? ', повторов предотвращено ' + duplicates : ''}.</div>`;
    sendButtons.forEach(button => button.disabled = false);
    loadCampaignOverview(true);
}

// Отправить всему блоку отправления (все города одного пункта, напр. «из Москвы»).
async function sendBlock(btn) {
    const section = btn.closest('.notif-origin');
    if (!section) return;
    const cards = [...section.querySelectorAll('.gcard')];
    if (!cards.length) return;
    const gis = cards.map(c => +c.dataset.gi);
    const station = section.querySelector('.notif-origin-title')?.textContent || 'этот блок';
    const routeProblems = preparationIssues().filter(x => x.level === 'err' && x.type === 'route' && gis.includes(x.groupIndex)).length;
    if (routeProblems) { alert('Сначала исправьте критичные данные в этом блоке.'); return; }
    const channels = selectedSendChannels();
    if (!channels.length) { alert('Не выбран канал отправки.'); return; }
    const valid = gis.reduce((n, gi) => n + GROUPS[gi].recipients.filter(x => x.valid).length, 0);
    const dup = channels.length > 1 ? `\n\nВыбрано ${channels.length} канала — пассажир может получить сообщение в каждом.` : '';
    // Повторная отправка: честно показываем, сколько новых, а сколько уже ушло (дубли отсекаются на сервере).
    const already = blockSentAgg(station).sent;
    const again = already ? `\n\nУже отправлено ранее: ${already}${valid - already > 0 ? `, новых: ${valid - already}` : ''}. Повторно те же сообщения не уйдут (защита от дублей).` : '';
    if (!confirm(`Отправить блоку «${station}» — ${valid} пассажирам в ${gis.length} направлениях?\nКаналы: ${channels.map(x => CHANNEL_LABELS[x] || x).join(', ')}.${dup}${again}`)) return;
    btn.disabled = true;
    let total = 0, failed = 0, duplicates = 0;
    for (const card of cards) {
        const gi = +card.dataset.gi;
        const r = await sendGroup(card.querySelector('.g-send'), gi, true) || {};
        total += r.sent || 0; failed += r.failed || 0; duplicates += r.duplicates || 0;
    }
    btn.disabled = false;
    loadCampaignOverview(true); // подтянет статусы и обновит метку блока
    alert(`Блок «${station}»: отправлено ${total}, ошибок ${failed}${duplicates ? ', без дублей ' + duplicates : ''}.`);
}

/* ── Свободная рассылка ── */
let BIMG = '';
let BCHAN = new Set([PRIMARY_CHANNEL]);
let BSTATES = {};
async function broadcastLoadChannels() {
    if (!document.getElementById('bChannels')) return;
    const r = await api('channels.states', {}).catch(() => null);
    BSTATES = (r && r.ok && r.states) ? r.states : {};
    if (r && r.ok) {
        TODAY_COUNTS = r.today || {}; DAILY_CAP = r.daily_cap || 200;
        if (r.primary && r.primary !== PRIMARY_CHANNEL) { PRIMARY_CHANNEL = r.primary; BCHAN = new Set([PRIMARY_CHANNEL]); }
    }
    renderBroadcastChannels();
}
function renderBroadcastChannels() {
    const box = document.getElementById('bChannels');
    if (!box) return;
    box.innerHTML = Object.entries(CHANNEL_LABELS).map(([ch, label]) => {
        const st = BSTATES[ch] || 'unconfigured';
        const ready = st === 'open' || st === 'ready';
        const note = ready ? '' : (st === 'unconfigured' ? '<small>не подключён</small>' : '<small class="chan-off">не авторизован</small>');
        if (!ready) BCHAN.delete(ch);
        const checked = ready && BCHAN.has(ch);
        return `<label class="send-channel-choice ${ready ? '' : 'disabled'}"><input type="checkbox" value="${ch}" ${checked ? 'checked' : ''} ${ready ? '' : 'disabled'} onchange="broadcastToggleChannel(this)"><span>${label}</span>${note}</label>`;
    }).join('') + dailyCountsLine();
}
function broadcastToggleChannel(input) {
    if (input.checked) BCHAN.add(input.value); else BCHAN.delete(input.value);
}
async function pullPhones() {
    const id = +document.getElementById('bMan').value;
    if (!id) return;
    const r = await api('manifest.phones', { manifest_id: id });
    if (r.ok) {
        const ta = document.getElementById('bPhones');
        const existing = ta.value.trim();
        ta.value = (existing ? existing + '\n' : '') + r.phones.join('\n');
        countPhones();
    }
}
function countPhones() {
    const n = document.getElementById('bPhones').value.split(/[\s,;]+/).filter(x => x.replace(/\D/g, '').length >= 10).length;
    document.getElementById('bCount').textContent = n ? 'Номеров: ' + n : '';
}
document.addEventListener('input', e => { if (e.target.id === 'bPhones') countPhones(); });

async function uploadBroadcastImage(inp) {
    if (!inp.files.length) return;
    const fd = new FormData();
    fd.append('kind', 'broadcast');
    fd.append('file', inp.files[0]);
    document.getElementById('bImgState').textContent = 'Загружаю…';
    const r = await apiUpload('upload', fd);
    if (r.ok) {
        BIMG = r.url;
        document.getElementById('bImgState').textContent = 'Картинка приложена ✓';
        const pv = document.getElementById('bImgPreview');
        pv.style.display = '';
        pv.querySelector('img').src = r.url;
    } else {
        document.getElementById('bImgState').textContent = r.error || 'Ошибка загрузки';
    }
}
async function sendBroadcast() {
    const phones = document.getElementById('bPhones').value;
    const text = document.getElementById('bText').value;
    const out = document.getElementById('bResult');
    const channels = [...BCHAN].filter(ch => { const st = BSTATES[ch]; return st === 'open' || st === 'ready'; });
    const n = phones.split(/[\s,;]+/).filter(x => x.replace(/\D/g, '').length >= 10).length;
    if (!n) { out.innerHTML = '<div class="alert warn">Добавьте хотя бы один номер.</div>'; return; }
    if (!channels.length) { out.innerHTML = '<div class="alert warn">Отметьте хотя бы один канал.</div>'; return; }
    if (!confirm('Отправить на ' + n + ' номеров через ' + channels.map(c => CHANNEL_LABELS[c] || c).join(', ') + '?')) return;
    const btn = document.getElementById('bSend');
    btn.disabled = true;
    out.innerHTML = '<div class="alert warn">Отправляю… не закрывайте страницу</div>';
    try {
        const r = await api('broadcast.send', { phones, text, image: BIMG, channels });
        if (!r.ok) { out.innerHTML = '<div class="alert err">' + esc(r.error) + '</div>'; return; }
        out.innerHTML = `<div class="alert ${r.failed ? 'warn' : 'ok'}">Отправлено сообщений: ${r.sent}, ошибок: ${r.failed}`
            + (r.rest ? `. В очереди ещё ${r.rest} номеров — нажмите «Отправить» снова.` : '.') + '</div>'
            + (r.errors?.length ? `<div class="muted small">${r.errors.map(esc).join('<br>')}</div>` : '');
    } finally {
        btn.disabled = false;
    }
}

/* ── Контакты (CRM) ── */
function bindContacts() {
    document.querySelectorAll('#contactsTable .cell').forEach(inp => {
        inp.onchange = () => api('contact.update', { id: +inp.closest('tr').dataset.id, field: inp.dataset.f, value: inp.value });
    });
}
async function delContact(btn) {
    const tr = btn.closest('tr');
    if (!confirm('Удалить контакт из базы? (история сообщений останется)')) return;
    await api('contact.delete', { id: +tr.dataset.id });
    tr.remove();
}
function bindContactCard() {
    const card = document.getElementById('contactCard');
    if (!card) return;
    card.querySelectorAll('.cc').forEach(inp => {
        inp.oninput = () => queueSave('cc' + inp.dataset.f, async () => {
            document.getElementById('ccState').textContent = 'Сохраняю…';
            await api('contact.update', { id: +card.dataset.id, field: inp.dataset.f, value: inp.value });
            document.getElementById('ccState').textContent = 'Сохранено ✓';
        });
    });
}

/* ── Документы (выпадающее меню) ── */
function toggleDrop(btn) {
    const m = btn.nextElementSibling;
    m.style.display = m.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', e => {
    if (!e.target.closest('.dropdown')) document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
});
function openDoc(id, type, format) {
    const carrier = document.getElementById('docCarrier')?.value || 0;
    const stamp = document.getElementById('docStamp')?.checked ? 1 : 0;
    const url = `/?p=document&id=${id}&type=${type}&format=${format}&carrier=${carrier}&stamp=${stamp}`;
    window.open(url, '_blank');
    return false;
}

/* ── Вкладки ── */
function showTab(name, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.hidden = p.dataset.tab !== name);
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t === btn));
}

/* ── Справочники ── */
const CAT_FIELDS = {
    stop: ['gds_id', 'station', 'city', 'address', 'map_url', 'note'],
    bus: ['code', 'plate', 'model', 'seats', 'driver_phone', 'note'],
    driver: ['name', 'phone', 'bus_id', 'note'],
    var: ['name', 'value', 'note'],
    carrier: ['atp', 'contract_no', 'contract_date', 'note'],
};
function bindCatalog() {
    document.querySelectorAll('table.cat').forEach(table => {
        const kind = table.dataset.kind;
        table.querySelectorAll('tr[data-id] .cell').forEach(inp => {
            inp.onchange = () => saveCatRow(inp.closest('tr'), kind);
        });
    });
}
async function saveCatRow(tr, kind) {
    const data = { id: +tr.dataset.id || 0 };
    CAT_FIELDS[kind].forEach(f => {
        const el = tr.querySelector(`[data-f="${f}"]`);
        if (el) data[f] = el.value;
    });
    const r = await api(kind + '.save', data);
    if (r.ok && r.id) tr.dataset.id = r.id;
}
function addCatRow(tableKind) {
    const table = document.getElementById(tableKind + 'Table');
    const kind = table.dataset.kind;
    const tr = document.createElement('tr');
    tr.dataset.id = 0;
    const cols = table.querySelectorAll('thead th').length - 1;
    let html = '';
    const fields = CAT_FIELDS[kind];
    for (let i = 0; i < cols; i++) {
        if (kind === 'bus' && i === cols - 1) { html += '<td class="bus-photo"></td>'; continue; }
        const f = fields[i] || 'note';
        html += `<td><input class="cell" data-f="${f}" value=""></td>`;
    }
    tr.innerHTML = html + '<td class="actions"><button class="icon-btn" onclick="delCatRow(this)">✕</button></td>';
    table.querySelector('tbody').prepend(tr);
    tr.querySelectorAll('.cell').forEach(inp => inp.onchange = () => saveCatRow(tr, kind));
    tr.querySelector('input').focus();
}
async function delCatRow(btn) {
    const tr = btn.closest('tr');
    const kind = tr.closest('table').dataset.kind;
    if (+tr.dataset.id && !confirm('Удалить запись?')) return;
    if (+tr.dataset.id) await api(kind + '.delete', { id: +tr.dataset.id });
    tr.remove();
}
async function uploadBusPhoto(inp) {
    const tr = inp.closest('tr');
    if (!+tr.dataset.id) { alert('Сначала заполните госномер (строка сохранится автоматически).'); return; }
    const fd = new FormData();
    fd.append('kind', 'bus');
    fd.append('bus_id', tr.dataset.id);
    fd.append('file', inp.files[0]);
    const r = await apiUpload('upload', fd);
    if (r.ok) location.reload(); else alert(r.error || 'Ошибка загрузки');
}
async function addTpl() {
    const name = prompt('Название шаблона:');
    if (!name) return;
    await api('tpl.save', { id: 0, name, body: 'Здравствуйте, {имя}!\n' });
    location.reload();
}
async function saveTpl(btn) {
    const box = btn.closest('.tpl');
    const r = await api('tpl.save', {
        id: +box.dataset.id,
        name: box.querySelector('[data-f="name"]').value,
        body: box.querySelector('[data-f="body"]').value,
    });
    box.querySelector('.tpl-state').textContent = r.ok ? 'Сохранено ✓' : (r.error || 'Ошибка');
    setTimeout(() => box.querySelector('.tpl-state').textContent = '', 2500);
}
async function delTpl(btn) {
    const box = btn.closest('.tpl');
    if (!confirm('Удалить шаблон «' + box.querySelector('[data-f="name"]').value + '»?')) return;
    await api('tpl.delete', { id: +box.dataset.id });
    box.remove();
}

/* ── Статус каналов (единый источник: channels.states → Channels::state) ── */
// Бейдж основного канала. Раньше звал wa.status, который отдавал состояние АКТИВНОГО
// инстанса рассылки — при провайдере Green API это инстанс MAX, и бейдж бодро писал
// «WhatsApp подключен», пока WhatsApp лежал. Статусы берём там же, где и все остальные.
async function channelStatusBadge() {
    const el = document.getElementById('channelStatus');
    if (!el) return;
    const r = await api('channels.states', {}).catch(() => null);
    if (r?.ok) {
        CHANNELS_STATE = r.states || {};
        PRIMARY_CHANNEL = r.primary || PRIMARY_CHANNEL;
    }
    const label = CHANNEL_LABELS[PRIMARY_CHANNEL] || PRIMARY_CHANNEL;
    const map = {
        open: ['ok', `${label} подключён`],
        ready: ['ok', `${label} подключён`],
        connecting: ['warn', `${label}: подключение…`],
        close: ['err', `${label}: номер не привязан`],
        unconfigured: ['muted', `${label} не настроен`],
        error: ['err', `${label}: ошибка шлюза`],
    };
    const [cls, text] = map[CHANNELS_STATE[PRIMARY_CHANNEL]] || ['muted', `${label}: нет данных`];
    el.className = 'badge ' + cls;
    el.textContent = text;
    // Остальные каналы показываем, только если с ними что-то не так — иначе шум.
    const broken = Object.keys(CHANNEL_LABELS).filter(c => c !== PRIMARY_CHANNEL && CHANNELS_STATE[c]
        && !['open', 'ready', 'unconfigured'].includes(CHANNELS_STATE[c]));
    el.title = broken.length ? 'Не готовы: ' + broken.map(c => CHANNEL_LABELS[c]).join(', ') : '';
}
/* ── Аккаунты WhatsApp (мультиаккаунт) ── */
async function waAccounts() {
    const box = document.getElementById('waAccounts');
    if (!box) return;
    const r = await api('wa.accounts', {});
    if (!r.ok) { box.innerHTML = '<div class="alert warn">' + esc(r.error || 'WhatsApp не настроен') + '</div>'; return; }
    if (!r.accounts.length) { box.innerHTML = '<p class="muted">Аккаунтов нет. Нажмите «Добавить аккаунт».</p>'; return; }
    const stMap = { open: ['ok', 'подключён'], connecting: ['warn', 'подключение…'], close: ['err', 'не привязан'] };
    box.innerHTML = r.accounts.map(a => {
        const [cls, txt] = stMap[a.state] || ['muted', a.state || '—'];
        return `<div class="wa-card ${a.is_active ? 'wa-active' : ''}">
            ${a.avatar ? `<img class="wa-avatar" src="${esc(a.avatar)}" alt="">` : '<div class="wa-avatar"></div>'}
            <div class="wa-info">
                <div class="wa-top">
                    ${a.is_active ? '<span class="wa-star" title="Активен для рассылки">★</span>' : ''}
                    <b>${esc(a.number || a.label)}</b>${a.name ? ' · ' + esc(a.name) : ''}
                    <span class="badge ${a.messenger === 'max' ? 'info' : (a.messenger === 'telegram' ? 'info' : 'ok')}">${a.messenger === 'max' ? 'MAX' : (a.messenger === 'telegram' ? 'Telegram' : 'WhatsApp')}</span>
                    <span class="badge ${cls}">${txt}</span>
                </div>
                <div class="muted small">${esc(a.label)} · инстанс ${esc(a.instance)}</div>
            </div>
            <div class="wa-actions">
                ${a.is_active ? '' : `<button class="btn ghost sm" onclick="waSetActive('${a.instance}')">Сделать активным</button>`}
                ${a.provider === 'greenapi'
                    ? '<span class="muted small">управляется в Green API</span>'
                    : `<button class="btn ghost sm" onclick="waConnect('${a.instance}')">${a.state === 'open' ? 'Переподключить' : 'Подключить'}</button>`
                      + (a.state === 'open' ? `<button class="btn ghost sm" onclick="waLogout('${a.instance}')">Отключить</button>` : '')
                      + `<button class="icon-btn" title="Удалить аккаунт" onclick="waDeleteAccount('${a.instance}','${esc(a.label)}')">✕</button>`}
            </div>
        </div>`;
    }).join('');
}
async function waAddAccount() {
    const label = prompt('Название аккаунта (например «Рабочий» или «Резерв»):');
    if (!label) return;
    const r = await api('wa.add', { label });
    if (!r.ok) { alert('Не удалось создать: ' + (r.error || 'ошибка')); return; }
    await waAccounts();
    waConnect(r.instance);
}
async function waSetActive(instance) {
    await api('wa.setactive', { instance });
    waAccounts();
}
async function waDeleteAccount(instance, label) {
    if (!confirm('Удалить аккаунт «' + label + '»? Номер отвяжется от шлюза.')) return;
    const r = await api('wa.account.delete', { instance });
    if (!r.ok) alert(r.error || 'Ошибка');
    waAccounts();
}

async function waInfo() {
    const card = document.getElementById('waCard');
    if (!card) return;
    const r = await fetch('/?p=api&a=wa.info').then(x => x.json()).catch(() => null);
    if (!r || !r.ok || !r.number) { card.style.display = 'none'; return; }
    card.style.display = 'flex';
    document.getElementById('waNumber').textContent = r.number + (r.name ? ' · ' + r.name : '');
    const av = document.getElementById('waAvatar');
    if (r.avatar) { av.src = r.avatar; av.style.display = 'block'; } else { av.style.display = 'none'; }
    const st = document.getElementById('waState');
    const map = { open: ['ok', 'подключён'], connecting: ['warn', 'подключение…'], close: ['err', 'отключён'] };
    const [cls, txt] = map[r.state] || ['muted', r.state || '—'];
    st.className = 'badge ' + cls;
    st.textContent = txt;
    document.getElementById('waMeta').textContent = `отправлено сообщений: ${r.messages || 0} · контактов в WhatsApp: ${r.contacts || 0}`;
}

async function waLogout(instance) {
    if (!confirm('Отключить этот номер WhatsApp? Рассылки с него перестанут уходить, пока не привяжете заново.')) return;
    const r = await api('wa.logout', instance ? { instance } : {});
    if (!r.ok) alert('Не удалось отключить: ' + (r.error || 'ошибка'));
    setTimeout(() => { if (typeof waAccounts === 'function') waAccounts(); }, 1500);
}

async function waConnect(instance) {
    const box = document.getElementById('qrBox');
    box.style.display = 'grid';
    document.getElementById('qrImg').src = '';
    const r = await api('wa.qr', instance ? { instance } : {});
    if (!r.ok || !r.qr) {
        box.style.display = 'none';
        alert('Не удалось получить QR: ' + (r.error || 'возможно, номер уже подключен'));
        if (typeof waAccounts === 'function') waAccounts();
        return;
    }
    document.getElementById('qrImg').src = r.qr;
    let tries = 0;
    const poll = setInterval(async () => {
        const s = await fetch('/?p=api&a=wa.status').then(x => x.json()).catch(() => null);
        if (s?.state === 'open') {
            clearInterval(poll); box.style.display = 'none'; alert('Номер подключён!');
            if (typeof waAccounts === 'function') waAccounts();
        }
        if (++tries > 40) clearInterval(poll);
    }, 3000);
}

/* ── Сотрудники ── */
async function addUser() {
    const name = prompt('Имя сотрудника:');
    if (!name) return;
    const login = prompt('Логин (латиницей, для входа):');
    if (!login) return;
    const password = prompt('Пароль (минимум 6 символов):');
    if (!password) return;
    const role = confirm('Сделать администратором? OK — админ, Отмена — оператор') ? 'admin' : 'operator';
    const r = await api('user.save', { id: 0, name, login, password, role });
    if (!r.ok) { alert(r.error || 'Ошибка'); return; }
    location.reload();
}
async function editUser(btn) {
    const tr = btn.closest('tr');
    const name = prompt('Имя:', tr.dataset.name);
    if (name === null) return;
    const login = prompt('Логин:', tr.dataset.login);
    if (login === null) return;
    const password = prompt('Новый пароль (пусто — не менять):', '');
    if (password === null) return;
    const role = confirm('Администратор? OK — админ, Отмена — оператор') ? 'admin' : 'operator';
    const r = await api('user.save', { id: +tr.dataset.id, name, login, password, role });
    if (!r.ok) { alert(r.error || 'Ошибка'); return; }
    location.reload();
}
async function toggleUser(id) {
    await api('user.toggle', { id });
    location.reload();
}
async function delUser(id) {
    if (!confirm('Удалить сотрудника?')) return;
    const r = await api('user.delete', { id });
    if (!r.ok) { alert(r.error || 'Ошибка'); return; }
    location.reload();
}

/* ── Реквизиты документов ── */
async function saveDocReq() {
    await api('doc_req.save', {
        frahtovatel: document.getElementById('docFrah').value,
        signer: document.getElementById('docSigner').value,
    });
    document.getElementById('docReqState').textContent = 'Сохранено ✓';
    setTimeout(() => document.getElementById('docReqState').textContent = '', 2000);
}
async function fixWebhook(btn) {
    const st = document.getElementById('whFixState');
    btn.disabled = true;
    if (st) { st.className = 'badge warn'; st.textContent = 'ставлю вебхук…'; }
    const r = await api('wa.webhook.fix', {});
    if (st) {
        st.className = 'badge ' + (r && r.ok ? 'ok' : 'err');
        st.textContent = r && r.ok ? 'вебхук статусов переустановлен ✓' : ('ошибка: ' + ((r && r.error) || '—'));
    }
    btn.disabled = false;
}
async function saveNotif() {
    await api('notif.save', {
        driver_phone_fallback: document.getElementById('msgDriverFallback').value,
        unsub_line: document.getElementById('msgUnsubLine') ? document.getElementById('msgUnsubLine').value : undefined,
        daily_cap: document.getElementById('msgDailyCap') ? document.getElementById('msgDailyCap').value : undefined,
        primary_channel: document.getElementById('msgPrimaryChannel') ? document.getElementById('msgPrimaryChannel').value : undefined,
    });
    const s = document.getElementById('notifState');
    s.textContent = 'Сохранено ✓';
    setTimeout(() => s.textContent = '', 2000);
}

const DELAY_FIELDS = { whatsapp: 'delWhatsapp', max: 'delMax', telegram: 'delTelegram' };
async function loadChannelDelays() {
    if (!document.getElementById('delWhatsapp')) return;
    const st = document.getElementById('delState');
    const r = await api('channels.delays', {}).catch(() => null);
    if (!r || !r.ok) { if (st) st.textContent = 'не удалось загрузить'; return; }
    for (const [ch, id] of Object.entries(DELAY_FIELDS)) {
        const el = document.getElementById(id);
        if (!el) continue;
        const ms = r.delays[ch];
        if (ms === null || ms === undefined) { el.value = ''; el.disabled = true; el.placeholder = 'не Green API'; }
        else el.value = ms / 1000;
    }
    if (st) st.textContent = '';
}
async function saveChannelDelays() {
    const st = document.getElementById('delState');
    if (st) st.textContent = 'Сохраняю…';
    let ok = 0, fail = 0;
    for (const [ch, id] of Object.entries(DELAY_FIELDS)) {
        const el = document.getElementById(id);
        if (!el || el.disabled || el.value === '') continue;
        const ms = Math.max(500, Math.round(parseFloat(el.value) * 1000));
        const r = await api('channel.delay.save', { channel: ch, ms }).catch(() => null);
        if (r && r.ok) ok++; else fail++;
    }
    if (st) { st.textContent = fail ? `сохранено ${ok}, ошибок ${fail}` : 'Сохранено ✓'; setTimeout(() => { if (st.textContent[0] === 'С') st.textContent = ''; }, 2500); }
}
async function uploadReq(inp, kind) {
    if (!inp.files.length) return;
    const fd = new FormData();
    fd.append('kind', kind);
    fd.append('file', inp.files[0]);
    const r = await apiUpload('upload', fd);
    if (r.ok) location.reload(); else alert(r.error || 'Ошибка загрузки');
}

/* ── Настройки ── */
async function saveSmtp() {
    await api('smtp.save', {
        from: document.getElementById('sbFrom').value,
        from_name: document.getElementById('sbFromName').value,
        reply: document.getElementById('sbReply').value,
    });
    document.getElementById('sbState').textContent = 'Сохранено ✓';
    setTimeout(() => document.getElementById('sbState').textContent = '', 2000);
}
async function testSmtp() {
    const to = document.getElementById('sbTestTo').value;
    const st = document.getElementById('sbTestState');
    st.className = 'muted small'; st.textContent = 'Отправляю…';
    const r = await api('smtp.test', { to });
    st.className = r.ok ? 'badge ok' : 'badge err';
    st.textContent = r.ok ? 'Отправлено ✓' : (r.error || 'Ошибка');
}
async function savePass() {
    const r = await api('password.save', { password: document.getElementById('newPass').value });
    document.getElementById('passState').textContent = r.ok ? 'Пароль изменён' : (r.error || 'Ошибка');
}

/* ── Чаты / единый inbox ── */
const chat = { conversationId: null, conversation: null, threads: [], poll: null, busy: false,
    queue: 'open', channelFilter: 'all', channelCounts: {}, counts: {}, users: [], currentUserId: 0,
    cursor: null, beforeCursor: null, hasOlder: false, messages: [], events: [], searchTimer: null };
const $id = id => document.getElementById(id);
const CHAN_META = {
    whatsapp: { name: 'WhatsApp', short: 'WA', color: '#0e7a44' },
    max:      { name: 'MAX', short: 'MAX', color: '#5b2bd6' },
    telegram: { name: 'Telegram', short: 'TG', color: '#1683b8' },
    sms:      { name: 'SMS', short: 'SMS', color: '#a85d00' },
    email:    { name: 'Email', short: 'Email', color: '#9a6310' },
};

async function chatInboxApi(action, data = {}) {
    const response = await fetch('/?p=chat_api&a=' + encodeURIComponent(action), {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': window.CSRF}, body: JSON.stringify(data)
    });
    const result = await response.json().catch(() => ({ok:false,error:'Некорректный ответ сервера'}));
    if (!result.ok) throw new Error(result.error || 'Ошибка inbox');
    return result;
}
function chatTime(ts) {
    if (!ts) return '';
    const [date, t = ''] = String(ts).split(' '); const time = t.slice(0, 5); const now = new Date();
    const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    if (date === today) return time;
    const p = date.split('-'); return p.length === 3 ? p[2] + '.' + p[1] + ' ' + time : time;
}
function chatInitial(name, phone) { const s = String(name || phone || '?').trim(); return s ? s[0].toUpperCase() : '?'; }
function chatChannelTag(m) {
    const d = CHAN_META[(m.channel || '').toLowerCase()];
    return d ? `<span class="cm-channel" style="--cm-channel:${d.color}">${d.name}</span>` : '';
}
function chatTicks(m) {
    if (m.read_at || m.read) return '<span class="cm-tick read">✓✓</span>';
    if (m.delivered_at || m.delivered) return '<span class="cm-tick">✓✓</span>';
    if (m.status === 'sent') return '<span class="cm-tick">✓</span>';
    if (m.status === 'failed') return '<span class="cm-tick fail">!</span>';
    return '<span class="cm-tick">·</span>';
}
function chatLightbox(src) {
    if (!src) return;
    let ov = document.getElementById('imgLightbox');
    if (!ov) {
        ov = document.createElement('div');
        ov.id = 'imgLightbox'; ov.className = 'img-lightbox';
        ov.innerHTML = '<button type="button" class="img-lightbox-close" aria-label="Закрыть">&times;</button><img alt="">';
        document.body.appendChild(ov);
        const close = () => ov.classList.remove('open');
        ov.addEventListener('click', e => { if (e.target === ov || e.target.classList.contains('img-lightbox-close')) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    }
    ov.querySelector('img').src = src;
    ov.classList.add('open');
}
function chatMedia(m) {
    if (!m.media) return '';
    if ((m.media_type || '').startsWith('image/')) return `<span class="cm-media" onclick="chatLightbox(this.querySelector('img').src)"><img src="${esc(m.media)}" alt="" loading="lazy"></span>`;
    const label = m.body && m.body.trim() ? m.body : 'Файл';
    return `<a class="cm-file" href="${esc(m.media)}" target="_blank" rel="noopener" download><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><span>${esc(label)}</span></a>`;
}
function chatText(m) { if (!m.body || !m.body.trim()) return ''; if (m.media && !(m.media_type || '').startsWith('image/')) return ''; return `<span class="cm-text">${esc(m.body)}</span>`; }

async function chatLoadThreads(append = false) {
    try {
        const r = await chatInboxApi('threads', {queue:chat.queue,channel:chat.channelFilter,
            search:($id('chatSearch')?.value || '').trim(),cursor:append ? chat.cursor : null});
        chat.threads = append ? chat.threads.concat(r.threads || []) : (r.threads || []);
        chat.cursor = r.next_cursor || null; chat.channelCounts = r.channel_counts || {}; chat.counts = r.counts || {};
        chatRenderQueues(); chatRenderTabs(); chatRenderThreads();
    } catch (e) { const box=$id('chatThreads'); if(box) box.innerHTML=`<div class="chat-hint error">${esc(e.message)}</div>`; }
}
function chatRenderQueues() {
    document.querySelectorAll('#chatQueues .cq[data-queue]').forEach(el => el.classList.toggle('active',el.dataset.queue===chat.queue));
    document.querySelectorAll('#chatQueues [data-count]').forEach(el => el.textContent=Number(chat.counts[el.dataset.count]||0));
}
function chatRenderTabs() {
    const box=$id('chatChannelTabs'); if(!box) return; const c=chat.channelCounts||{};
    const total=Object.values(c).reduce((a,b)=>a+Number(b||0),0);
    let html=`<button class="cq channel${chat.channelFilter==='all'?' active':''}" onclick="chatSetChannel('all')"><span>Все каналы</span><b>${total}</b></button>`;
    const alwaysCh=['whatsapp','max','telegram']; // мессенджеры показываем всегда, даже при 0 диалогов
    ['whatsapp','max','telegram','sms','email'].forEach(ch=>{const n=Number(c[ch]||0);if(!n && !alwaysCh.includes(ch))return;const m=CHAN_META[ch];html+=`<button class="cq channel${chat.channelFilter===ch?' active':''}" style="--chc:${m.color}" onclick="chatSetChannel('${ch}')"><span><i></i>${m.name}</span><b>${n}</b></button>`;});
    box.innerHTML=html;
}
function chatSetQueue(queue) { chat.queue=queue; chat.cursor=null; chatLoadThreads(); }
function chatSetChannel(channel) { chat.channelFilter=channel; chat.cursor=null; chatLoadThreads(); }
function chatRenderThreads() {
    const box=$id('chatThreads'); if(!box)return;
    if(!chat.threads.length){box.innerHTML='<div class="chat-hint">В этой очереди пока нет диалогов.</div>';return;}
    box.innerHTML=chat.threads.map(t=>{
        const name=t.contact_name||t.contact_phone||t.external_chat_id; const preview=(t.last_direction==='out'?'Вы: ':'')+(t.last_message_preview||'');
        const badge=Number(t.unread_count)>0?`<span class="ct-badge">${Number(t.unread_count)}</span>`:'';const m=CHAN_META[t.channel];
        const chTag=m?`<span class="ct-ch" style="background:${m.color}">${m.short}</span>`:'';
        const priority=t.priority==='urgent'?'<span class="ct-priority urgent">!</span>':(t.priority==='high'?'<span class="ct-priority">!</span>':'');
        return `<div class="chat-thread${Number(t.id)===chat.conversationId?' active':''}${Number(t.unread_count)>0?' unread':''}" data-id="${Number(t.id)}" onclick="chatOpen(${Number(t.id)})"><span class="ct-ava">${esc(chatInitial(name,t.contact_phone))}</span><div class="ct-main"><div class="ct-top"><span class="ct-name">${priority}${esc(name)}</span><span class="ct-time">${esc(chatTime(t.last_message_at))}</span></div><div class="ct-bot"><span class="ct-last">${esc(preview)}</span>${chTag}${badge}</div><div class="ct-owner">${esc(t.assignee_name||'Без оператора')}</div></div></div>`;
    }).join('')+(chat.cursor?'<button class="chat-more" onclick="chatLoadThreads(true)">Показать ещё</button>':'');
}
function chatFilter() { clearTimeout(chat.searchTimer); chat.searchTimer=setTimeout(()=>{chat.cursor=null;chatLoadThreads();},260); }

async function chatOpen(id) {
    chat.conversationId=Number(id); chat.beforeCursor=null;
    $id('chatEmpty').hidden=true;$id('chatPane').hidden=false;$id('chatWrap').classList.add('conv-open');
    document.querySelectorAll('#chatThreads .chat-thread').forEach(el=>el.classList.toggle('active',Number(el.dataset.id)===chat.conversationId));
    $id('chatBody').innerHTML='<div class="chat-hint">Загрузка…</div>';
    history.replaceState(null,'','/?p=chats&conversation_id='+chat.conversationId);
    await chatInboxApi('markread',{conversation_id:chat.conversationId}); await chatLoadMessages(true);
    const t=chat.threads.find(x=>Number(x.id)===chat.conversationId);if(t)t.unread_count=0;chatRenderThreads();$id('chatText').focus();
}
function chatCloseConv() { chat.conversationId=null;chat.conversation=null;$id('chatWrap').classList.remove('conv-open');$id('chatPane').hidden=true;$id('chatEmpty').hidden=false;history.replaceState(null,'','/?p=chats');chatRenderThreads(); }
function chatMessageHtml(m){return `<div class="cm ${m.dir}"><div class="cm-bubble">${chatMedia(m)}${chatText(m)}<span class="cm-meta">${chatChannelTag(m)}${esc(chatTime(m.ts))}${m.dir==='out'?chatTicks(m):''}</span></div></div>`;}
async function chatLoadMessages(force=false,before=false) {
    if(!chat.conversationId)return; const active=chat.conversationId;
    try{
        const r=await chatInboxApi('messages',{conversation_id:active,before_cursor:before?chat.beforeCursor:null});if(active!==chat.conversationId)return;
        chat.conversation=r.conversation;chat.events=r.events||[];chat.beforeCursor=r.before_cursor||null;chat.hasOlder=!!r.has_more;
        chat.messages=before?(r.messages||[]).concat(chat.messages):(r.messages||[]);
        chatRenderHeader();chatRenderNotes();
        const body=$id('chatBody');const atBottom=body.scrollHeight-body.scrollTop-body.clientHeight<90;
        const html=(chat.hasOlder?'<button class="chat-load-older" onclick="chatLoadOlder()">Показать предыдущие сообщения</button>':'')+(chat.messages.length?chat.messages.map(chatMessageHtml).join(''):'<div class="chat-hint">Сообщений пока нет — напишите первым.</div>');
        if(before){const oldHeight=body.scrollHeight;body.innerHTML=html;body.scrollTop=body.scrollHeight-oldHeight;}
        else {body.innerHTML=html;if(force||atBottom)body.scrollTop=body.scrollHeight;}
    }catch(e){$id('chatBody').innerHTML=`<div class="chat-hint error">${esc(e.message)}</div>`;}
}
async function chatLoadOlder(){if(chat.beforeCursor)await chatLoadMessages(false,true);}
function chatRenderHeader(){
    const c=chat.conversation;if(!c)return;const name=c.contact_name||c.contact_phone||c.external_chat_id;
    $id('chatAva').textContent=chatInitial(name,c.contact_phone);$id('chatName').textContent=name;$id('chatPhone').textContent=/^\+\d{10,15}$/.test(c.contact_phone||'')?c.contact_phone:'';
    $id('chatCard').href='/?p=contacts&q='+encodeURIComponent(c.contact_phone||'');$id('chatStatus').value=c.status;$id('chatPriority').value=c.priority;
    const trip=$id('chatTripLink');if(c.manifest_id){trip.hidden=false;trip.href='/?p=manifest&id='+Number(c.manifest_id);trip.textContent='Рейс №'+(c.trip_number||c.manifest_id)+' · '+(c.route||'');}else{trip.hidden=true;}
    const m=CHAN_META[c.channel];$id('chatHeadChannel').innerHTML=m?`<span style="color:${m.color}">${m.name}</span>`:'';
    const assignee=$id('chatAssignee');assignee.innerHTML='<option value="">Без оператора</option>'+chat.users.map(u=>`<option value="${Number(u.id)}">${esc(u.name)}</option>`).join('');assignee.value=c.assignee_user_id||'';
}
async function chatUpdateMeta(field,value){if(!chat.conversationId)return;try{await chatInboxApi('update',{conversation_id:chat.conversationId,field,value});await chatLoadMessages();chatLoadThreads();}catch(e){alert(e.message);}}
function chatOpenNotes(){chatRenderNotes();$id('chatNotesDialog').showModal();}
function chatRenderNotes(){const box=$id('chatNotesList');if(!box)return;const notes=chat.events.filter(e=>e.event_type==='note');box.innerHTML=notes.length?notes.map(n=>`<div class="chat-note"><div>${esc(n.body||'')}</div><small>${esc(n.actor_name||'')} · ${esc(chatTime(n.created_at))}</small></div>`).join(''):'<div class="chat-hint">Заметок пока нет.</div>';}
async function chatAddNote(e){e.preventDefault();const ta=$id('chatNoteText');const body=ta.value.trim();if(!body)return;try{await chatInboxApi('note',{conversation_id:chat.conversationId,body});ta.value='';await chatLoadMessages();}catch(err){alert(err.message);}}
async function chatSend(e){
    if(e)e.preventDefault();const ta=$id('chatText');const text=ta.value.trim();if(!text||!chat.conversationId||chat.busy)return;chat.busy=true;$id('chatSendBtn').disabled=true;$id('chatChannelNote').textContent='';
    const r=await api('chat.send',{conversation_id:chat.conversationId,text});chat.busy=false;$id('chatSendBtn').disabled=false;
    if(r.ok){ta.value='';ta.style.height='auto';await chatLoadMessages(true);chatLoadThreads();ta.focus();}else{$id('chatChannelNote').textContent='⚠ '+(r.error||'Не удалось отправить');}
}
async function chatInit(){
    try{const b=await chatInboxApi('bootstrap');chat.users=b.users||[];chat.currentUserId=Number(b.current_user_id||0);}catch(e){}
    await chatLoadThreads();const start=$id('chatWrap').dataset.start;if(start){const t=chat.threads.find(x=>x.contact_phone===start||String(x.id)===start);if(t)chatOpen(Number(t.id));}
    const ta=$id('chatText');if(ta){ta.addEventListener('input',()=>{ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,140)+'px';});ta.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();chatSend();}});}
    chat.poll=setInterval(()=>{if(document.hidden)return;chatLoadThreads();if(chat.conversationId)chatLoadMessages(false);},5000);
}

async function reportApi(action, data = {}) {
    const r = await fetch('/?p=reporting_api&a=' + encodeURIComponent(action), {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': window.CSRF},
        body: JSON.stringify(data)
    });
    const out = await r.json().catch(() => ({ok: false, error: 'Некорректный ответ сервера'}));
    if (!out.ok && out.error) throw new Error(out.error);
    return out;
}

function reportMoney(value) {
    return new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2}).format(Number(value || 0)) + ' ₽';
}

function reportSetState(text, isError = false) {
    const el = $id('reportSaveState');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('report-error', isError);
}

async function reportRecalculate() {
    if (!window.REPORT_MANIFEST_ID) return;
    try {
        const r = await reportApi('calculate', {manifest_id: window.REPORT_MANIFEST_ID});
        document.querySelectorAll('[data-total]').forEach(el => {
            el.textContent = reportMoney(r.calculation.totals[el.dataset.total]);
        });
        const warnings = $id('reportWarnings');
        if (warnings) warnings.innerHTML = r.calculation.warnings.length
            ? r.calculation.warnings.slice(0, 8).map(w => `<div class="report-warning">⚠ ${esc(w)}</div>`).join('')
            : '<div class="alert ok">Противоречий не найдено.</div>';
    } catch (e) { reportSetState(e.message, true); }
}

function reportInit() {
    reportDirBind(); // справочники агентов и автовокзалов — правка на месте
    document.querySelectorAll('.report-p-field').forEach(el => el.addEventListener('change', async () => {
        const row = el.closest('tr[data-id]');
        let value = el.type === 'checkbox' ? (el.checked ? 'completed' : 'none') : el.value;
        reportSetState('Сохраняю…');
        try {
            await reportApi('passenger.update', {id: Number(row.dataset.id), field: el.dataset.field, value});
            if (el.dataset.field === 'attendance') el.className = 'report-p-field attendance-' + value;
            reportSetState('Все изменения сохранены');
            await reportRecalculate();
        } catch (e) { reportSetState(e.message, true); }
    }));
    document.querySelectorAll('.report-manifest-field').forEach(el => el.addEventListener('change', async () => {
        try {
            await reportApi('manifest.update', {id: Number(el.dataset.id), field: el.dataset.field, value: el.value});
            reportSetState('Все изменения сохранены');
        } catch (e) { reportSetState(e.message, true); }
    }));
}

async function reportAddPassenger(manifestId) {
    try { await reportApi('passenger.add', {manifest_id: manifestId}); location.reload(); }
    catch (e) { alert(e.message); }
}

async function reportDeletePassenger(button) {
    if (!confirm('Удалить пассажира из расчёта?')) return;
    try { await reportApi('passenger.delete', {id: Number(button.closest('tr').dataset.id)}); location.reload(); }
    catch (e) { alert(e.message); }
}

async function reportSaveSnapshot(manifestId) {
    try {
        const r = await reportApi('snapshot.save', {manifest_id: manifestId});
        alert('Расчёт сохранён как версия ' + r.version + '.');
        location.reload();
    } catch (e) { alert(e.message); }
}

async function reportAddCash() {
    const dialog = $id('reportCashDialog');
    if (dialog) dialog.showModal();
}

async function reportSubmitCash(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    try {
        await reportApi('cash.add', {manifest_id: window.REPORT_MANIFEST_ID, amount: data.get('amount'), recipient: data.get('recipient'), note: data.get('note')});
        location.reload();
    } catch (e) { alert(e.message); }
    return false;
}

// ── Справочники отчётности: правка на месте (владелец настраивает сам) ──
function reportDirBind() {
    const state = $id('reportAgentState');
    const flash = (text, err) => { if (state) { state.textContent = text; state.style.color = err ? 'var(--err)' : ''; } };
    // агенты: имя/алиасы/где искать/сторона/ставка/договор
    document.querySelectorAll('.report-a-field').forEach(el => el.addEventListener('change', async () => {
        const row = el.closest('tr');
        flash('Сохраняю…');
        try {
            await reportApi('agent.update', {id: +row.dataset.cid, field: el.dataset.field, value: el.value});
            flash('Сохранено ✓');
        } catch (e) { flash('Ошибка: ' + e.message, true); }
    }));
    // автовокзалы: название/процент/примечание
    document.querySelectorAll('.report-s-field').forEach(el => el.addEventListener('change', async () => {
        const row = el.closest('tr');
        try {
            await reportApi('station.update', {id: +row.dataset.sid, field: el.dataset.field, value: el.value});
            flash('Сохранено ✓');
        } catch (e) { flash('Ошибка: ' + e.message, true); }
    }));
}

async function reportDeleteAgent(button) {
    const row = button.closest('tr');
    const name = row.querySelector('[data-field="name"]')?.value || 'агента';
    if (!confirm(`Удалить условие «${name}»?\nУ пассажиров ссылка на него снимется, продажи и рейсы не тронутся.`)) return;
    try { await reportApi('agent.delete', {id: +row.dataset.cid}); row.remove(); }
    catch (e) { alert(e.message); }
}

async function reportDeleteStation(button) {
    const row = button.closest('tr');
    const name = row.querySelector('[data-field="name"]')?.value || 'автовокзал';
    if (!confirm(`Удалить «${name}» из справочника?`)) return;
    try { await reportApi('station.delete', {id: +row.dataset.sid}); row.remove(); }
    catch (e) { alert(e.message); }
}

// Продажи автовокзалов на рейсе. Процент не вводится — он берётся из справочника,
// поэтому в строку уходит только сумма. После правки перезагружаем: меняются и
// таблица вокзалов, и оборот, и все зависимые итоги.
async function reportAddStationSale(manifestId) {
    const pick = $id('stationPick'), amount = $id('stationAmount');
    if (!pick?.value) { alert('Выберите автовокзал.'); pick?.focus(); return; }
    if (!(parseFloat(amount?.value) > 0)) { alert('Укажите сумму продаж.'); amount?.focus(); return; }
    try {
        await reportApi('station_sale.add', {manifest_id: manifestId, station_id: pick.value, amount: amount.value});
        location.reload();
    } catch (e) { alert(e.message); }
}

async function reportDeleteStationSale(button) {
    const row = button.closest('tr');
    if (!row || !confirm('Удалить эту продажу автовокзала?')) return;
    try {
        await reportApi('station_sale.delete', {id: +row.dataset.id});
        location.reload();
    } catch (e) { alert(e.message); }
}

document.addEventListener('DOMContentLoaded', () => {
    bindCells();
    bindCatalog();
    if (document.body.dataset.page === 'chats') chatInit();
    if (document.body.dataset.page === 'reporting') reportInit();
});
