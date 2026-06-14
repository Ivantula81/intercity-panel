async function api(action, data = {}) {
    const r = await fetch('/?p=api&a=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF },
        body: JSON.stringify(data),
    });
    return r.json();
}
async function apiUpload(action, formData) {
    formData.append('csrf', window.CSRF);
    const r = await fetch('/?p=api&a=' + action, { method: 'POST', body: formData });
    return r.json();
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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
let GROUPS = [], TEMPLATES = [], BUS_PHOTO = '', GDS_LOADED = false;

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

async function loadGroups(autoGds) {
    const box = document.getElementById('groupsBox');
    if (!box) return;
    box.innerHTML = '<p class="muted">Загружаю группы…</p>';
    const r = await api('groups', { manifest_id: manifestId() });
    if (!r.ok) { box.innerHTML = '<div class="alert err">' + esc(r.error) + '</div>'; return; }
    GROUPS = r.groups;
    TEMPLATES = r.templates;
    BUS_PHOTO = r.bus_photo;
    const ap = document.getElementById('attachPhoto');
    if (ap) ap.disabled = !BUS_PHOTO;
    const hint = document.getElementById('busPhotoHint');
    if (hint) hint.textContent = BUS_PHOTO ? '' : 'фото автобуса нет в справочнике';
    renderGroups();

    const hasTimes = GROUPS.some(g => g.time);
    if (autoGds && !hasTimes && !GDS_LOADED) gdsTimes(true);
}

function defaultBody() {
    return TEMPLATES.length ? TEMPLATES[0].body : '';
}

function renderGroups() {
    const box = document.getElementById('groupsBox');
    box.innerHTML = '';
    if (!GROUPS.length) { box.innerHTML = '<p class="muted">В ведомости нет пассажиров.</p>'; return; }

    let totalValid = 0;
    GROUPS.forEach((g, gi) => {
        const validCount = g.recipients.filter(x => x.valid).length;
        totalValid += validCount;

        const card = document.createElement('div');
        card.className = 'gcard';
        card.dataset.station = g.station;
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
                <div class="gtitle">📍 ${esc(g.station)} <span class="badge muted">${validCount}</span>${g.station_id ? ` <span class="badge muted" title="id станции">#${g.station_id}</span>` : ''}</div>
                <div class="gmeta">посадка ${timeLabel}${g.address ? ' · ' + esc(g.address) : ''} ${badges.join(' ')}</div>
            </div>
            <span class="gchev">▾</span>
        </div>
        <div class="gcard-body">
            <div class="row gcard-controls">
                <label class="f" style="margin:0">Дата<input type="text" class="g-date" value="${esc(g.date)}" placeholder="дд.мм.гггг" style="width:120px"></label>
                <label class="f" style="margin:0">Время<input type="text" class="g-time" value="${esc(g.time)}" placeholder="чч:мм" style="width:84px"></label>
                <label class="f" style="margin:0;flex:1">Шаблон<select class="g-tpl"><option value="">— свой текст —</option>${tplOptions}</select></label>
            </div>
            <textarea class="template-box g-body" rows="5">${esc(g.body ?? defaultBody())}</textarea>
            <div class="msg-preview g-preview mt"></div>
            <details class="mt"><summary class="muted small" style="cursor:pointer">Получатели (${g.recipients.length})</summary>
                <div class="table-wrap mt"><table class="t"><tbody>
                ${g.recipients.map(p => `
                    <tr data-pid="${p.id}"><td style="width:30px"><input type="checkbox" class="g-cb" value="${p.id}" ${p.valid ? 'checked' : ''}></td>
                    <td><b>${esc(p.name) || '—'}</b> <span class="muted small">→ ${esc(p.to)}</span></td>
                    <td style="width:170px"><input class="cell g-phone ${p.valid ? '' : 'phone-bad'}" data-pid="${p.id}" value="${esc(p.phone)}" placeholder="+7…"></td></tr>`).join('')}
                </tbody></table></div>
                <div class="muted small mt">Телефон редактируется прямо здесь — исправьте корявые номера и нажмите Enter.</div>
                <button class="btn ghost sm mt" onclick="addGroupRecipient(this, ${gi})">+ Добавить получателя</button>
            </details>
            <div class="row mt">
                <button class="btn sm g-send" onclick="sendGroup(this, ${gi})">Отправить этой группе</button>
                <button class="btn ghost sm" onclick="resetGroupBody(this, ${gi})" title="Заменить текст группы актуальным шаблоном">↺ Сбросить к шаблону</button>
                <span class="small g-state"></span>
            </div>
        </div>`;
        box.appendChild(card);

        const ta = card.querySelector('.g-body');
        const refresh = () => { schedulePreview(card, gi); scheduleDraft(card, gi); };
        ta.addEventListener('input', refresh);
        card.querySelector('.g-date').addEventListener('input', refresh);
        card.querySelector('.g-time').addEventListener('input', refresh);
        card.querySelector('.g-tpl').addEventListener('change', e => {
            const t = TEMPLATES.find(x => x.id == e.target.value);
            if (t) { ta.value = t.body; refresh(); }
        });
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
                }
            };
            inp.addEventListener('change', save);
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
        });
        schedulePreview(card, gi, true);
    });

    const sum = document.getElementById('sendSummary');
    if (sum) sum.innerHTML = `К отправке: <b>${totalValid}</b> получателей в ${GROUPS.length} группах`;
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
            text: card.querySelector('.g-body').value,
            date: card.querySelector('.g-date').value,
            time: card.querySelector('.g-time').value,
        });
        if (r.ok) {
            let html = esc(r.preview).replace(/\n/g, '<br>');
            if (r.unknown && r.unknown.length) {
                html += `<div class="badge warn" style="margin-top:8px">⚠️ не распознаны переменные: ${r.unknown.map(esc).join(', ')} — проверьте написание или добавьте в Справочники → Переменные</div>`;
            }
            card.querySelector('.g-preview').innerHTML = html;
        }
    }, now ? 50 : 700);
}
let draftTimers = {};
function scheduleDraft(card, gi) {
    clearTimeout(draftTimers[gi]);
    draftTimers[gi] = setTimeout(() => api('group.save', {
        manifest_id: manifestId(),
        station: GROUPS[gi].station,
        body: card.querySelector('.g-body').value,
        date: card.querySelector('.g-date').value,
        time: card.querySelector('.g-time').value,
    }), 900);
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
            if (r.unmatched.length) html += `<br>Без времени: ${r.unmatched.map(esc).join(', ')} — заполните вручную.`;
            info.innerHTML = html + '</div>';
        } else {
            if (badge) {
                badge.className = r.statement_match ? 'badge ok' : 'badge warn';
                badge.textContent = r.statement_match ? 'времена из GDS ✓' : 'GDS: номер ведомости не совпал';
            }
            let html = `<div class="alert ${r.statement_match ? 'ok' : 'warn'}">Рейс найден, времена обновлены для ${r.updated} групп.`
                + (r.statement_match ? '' : ' <b>Номер ведомости НЕ совпадает — проверьте рейс!</b>');
            if (r.unmatched.length) html += `<br>Без времени остались: ${r.unmatched.map(esc).join(', ')} — заполните вручную.`;
            info.innerHTML = html + '</div>';
        }
        await loadGroups(false);
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function resetGroupBody(btn, gi) {
    const card = btn.closest('.gcard');
    const sel = card.querySelector('.g-tpl');
    const t = TEMPLATES.find(x => x.id == sel.value) || TEMPLATES[0];
    if (!t) return;
    card.querySelector('.g-body').value = t.body;
    await api('group.save', {
        manifest_id: manifestId(),
        station: GROUPS[gi].station,
        body: t.body,
        date: card.querySelector('.g-date').value,
        time: card.querySelector('.g-time').value,
    });
    schedulePreview(card, gi, true);
}

async function addGroupRecipient(btn, gi) {
    const name = prompt('Имя получателя:');
    if (name === null) return;
    const phone = prompt('Телефон (+7… или другой):');
    if (!phone) return;
    await api('passenger.add', {
        manifest_id: manifestId(),
        name, phone, from_stop: GROUPS[gi].station,
    });
    loadGroups();
}

async function sendGroup(btn, gi, silent = false) {
    const card = btn.closest('.card');
    const ids = [...card.querySelectorAll('.g-cb:checked')].map(c => +c.value);
    const state = card.querySelector('.g-state');
    if (!ids.length) { state.textContent = 'Никто не выбран'; return { sent: 0, failed: 0 }; }
    if (!silent && !confirm(`Отправить группе «${GROUPS[gi].station}» (${ids.length} получателей)?`)) return;
    btn.disabled = true;
    state.textContent = 'Отправляю… (2–4 сек на сообщение)';
    try {
        const r = await api('campaign.send', {
            manifest_id: manifestId(),
            station: GROUPS[gi].station,
            ids,
            text: card.querySelector('.g-body').value,
            date: card.querySelector('.g-date').value,
            time: card.querySelector('.g-time').value,
            attach_photo: document.getElementById('attachPhoto').checked ? 1 : 0,
        });
        if (!r.ok) { state.innerHTML = '<span class="badge err">' + esc(r.error) + '</span>'; return r; }
        state.innerHTML = `<span class="badge ${r.failed ? 'warn' : 'ok'}">отправлено ${r.sent}, ошибок ${r.failed}${r.rest ? ', в очереди ' + r.rest : ''}</span>`
            + (r.errors?.length ? `<div class="muted small">${r.errors.map(esc).join('<br>')}</div>` : '');
        return r;
    } finally {
        btn.disabled = false;
    }
}

async function sendAllGroups(btn) {
    if (!confirm('Отправить сообщения ВСЕМ группам по очереди?')) return;
    btn.disabled = true;
    const all = document.getElementById('allState');
    let total = 0, failed = 0;
    const cards = document.querySelectorAll('.group-card');
    for (let gi = 0; gi < cards.length; gi++) {
        all.innerHTML = `<div class="alert warn">Группа ${gi + 1} из ${cards.length}: ${esc(GROUPS[gi].station)}…</div>`;
        const r = await sendGroup(cards[gi].querySelector('.g-send'), gi, true) || {};
        total += r.sent || 0;
        failed += r.failed || 0;
    }
    all.innerHTML = `<div class="alert ${failed ? 'warn' : 'ok'}">Готово: отправлено ${total}, ошибок ${failed}.</div>`;
    btn.disabled = false;
}

/* ── Произвольный номер ── */
async function sendSingle() {
    const st = document.getElementById('sState');
    st.className = 'small muted';
    st.textContent = 'Отправляю…';
    const r = await api('send.single', {
        phone: document.getElementById('sNum').value,
        text: document.getElementById('sText').value,
    });
    st.className = r.ok ? 'badge ok' : 'badge err';
    st.textContent = r.ok ? 'Отправлено' : (r.error || 'Ошибка');
    if (r.ok) document.getElementById('sText').value = '';
}

/* ── Свободная рассылка ── */
let BIMG = '';
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
    const n = phones.split(/[\s,;]+/).filter(x => x.replace(/\D/g, '').length >= 10).length;
    if (!n) { out.innerHTML = '<div class="alert warn">Добавьте хотя бы один номер.</div>'; return; }
    if (!confirm('Отправить рассылку на ' + n + ' номеров?')) return;
    const btn = document.getElementById('bSend');
    btn.disabled = true;
    out.innerHTML = '<div class="alert warn">Отправляю… не закрывайте страницу</div>';
    try {
        const r = await api('broadcast.send', { phones, text, image: BIMG });
        if (!r.ok) { out.innerHTML = '<div class="alert err">' + esc(r.error) + '</div>'; return; }
        out.innerHTML = `<div class="alert ${r.failed ? 'warn' : 'ok'}">Отправлено: ${r.sent}, ошибок: ${r.failed}`
            + (r.rest ? `. В очереди ещё ${r.rest} — нажмите «Отправить» снова (уже отправленным повторно не уйдёт только при ручном контроле списка).` : '.') + '</div>'
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

/* ── WhatsApp статус и QR ── */
async function waStatus() {
    const el = document.getElementById('waStatus');
    if (!el) return;
    const r = await fetch('/?p=api&a=wa.status').then(x => x.json()).catch(() => null);
    const map = {
        open: ['ok', 'WhatsApp подключен'],
        connecting: ['warn', 'WhatsApp: подключение…'],
        close: ['warn', 'WhatsApp: номер не привязан'],
        unconfigured: ['muted', 'WhatsApp не настроен'],
        error: ['err', 'WhatsApp: ошибка шлюза'],
    };
    const [cls, text] = map[r?.state] || ['muted', 'WhatsApp: нет данных'];
    el.className = 'badge ' + cls;
    el.textContent = text;
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

/* ── Чаты ── */
const chat = { phone: null, name: '', threads: [], poll: null, count: -1, busy: false };
const $id = id => document.getElementById(id);

function chatTime(ts) {
    if (!ts) return '';
    const [date, t = ''] = String(ts).split(' ');
    const time = t.slice(0, 5);
    const now = new Date();
    const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    if (date === today) return time;
    const p = date.split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + ' ' + time : time;
}
function chatInitial(name, phone) {
    const s = String(name || phone || '?').trim();
    return s ? s[0].toUpperCase() : '?';
}
function chatTicks(m) {
    if (m.read) return '<span class="cm-tick read">✓✓</span>';
    if (m.delivered) return '<span class="cm-tick">✓✓</span>';
    if (m.status === 'sent') return '<span class="cm-tick">✓</span>';
    if (m.status === 'failed') return '<span class="cm-tick fail">!</span>';
    return '<span class="cm-tick">·</span>';
}
function chatMedia(m) {
    if (!m.media) return '';
    if ((m.media_type || '').startsWith('image/')) {
        return `<a class="cm-media" href="${esc(m.media)}" target="_blank" rel="noopener"><img src="${esc(m.media)}" alt="" loading="lazy"></a>`;
    }
    const label = m.body && m.body.trim() ? m.body : 'Файл';
    return `<a class="cm-file" href="${esc(m.media)}" target="_blank" rel="noopener" download>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        <span>${esc(label)}</span></a>`;
}
function chatText(m) {
    // для картинок показываем подпись; для файлов подпись уже в чипе
    if (!m.body || !m.body.trim()) return '';
    if (m.media && !(m.media_type || '').startsWith('image/')) return '';
    return `<span class="cm-text">${esc(m.body)}</span>`;
}

async function chatLoadThreads() {
    const r = await api('chat.threads');
    if (!r.ok) return;
    chat.threads = r.threads || [];
    chatRenderThreads();
}
function chatRenderThreads() {
    const box = $id('chatThreads');
    if (!box) return;
    if (!chat.threads.length) { box.innerHTML = '<div class="chat-hint">Пока нет диалогов.<br>Они появятся после первого сообщения.</div>'; return; }
    box.innerHTML = chat.threads.map(t => {
        const preview = (t.last_dir === 'out' ? 'Вы: ' : '') + (t.last || '');
        const badge = t.unread > 0 ? `<span class="ct-badge">${t.unread}</span>` : '';
        const search = ((t.name || '') + ' ' + t.phone).toLowerCase();
        return `<div class="chat-thread${t.phone === chat.phone ? ' active' : ''}${t.unread > 0 ? ' unread' : ''}" data-phone="${esc(t.phone)}" data-search="${esc(search)}" onclick="chatOpen('${esc(t.phone)}')">
            <span class="ct-ava">${esc(chatInitial(t.name, t.phone))}</span>
            <div class="ct-main">
                <div class="ct-top"><span class="ct-name">${esc(t.name || t.phone)}</span><span class="ct-time">${esc(chatTime(t.last_at))}</span></div>
                <div class="ct-bot"><span class="ct-last">${esc(preview)}</span>${badge}</div>
            </div></div>`;
    }).join('');
    const q = $id('chatSearch');
    if (q && q.value) chatFilter(q.value);
}
function chatFilter(v) {
    const q = v.trim().toLowerCase();
    document.querySelectorAll('#chatThreads .chat-thread').forEach(el => {
        el.style.display = (!q || el.dataset.search.includes(q)) ? '' : 'none';
    });
}

async function chatOpen(phone) {
    chat.phone = phone;
    chat.count = -1;
    $id('chatEmpty').hidden = true;
    $id('chatPane').hidden = false;
    $id('chatWrap').classList.add('conv-open');
    document.querySelectorAll('#chatThreads .chat-thread').forEach(el =>
        el.classList.toggle('active', el.dataset.phone === phone));
    $id('chatBody').innerHTML = '<div class="chat-hint">Загрузка…</div>';
    history.replaceState(null, '', '/?p=chats&phone=' + encodeURIComponent(phone));
    await chatLoadMessages(true);
    await api('chat.markread', { phone });
    const t = chat.threads.find(x => x.phone === phone);
    if (t) { t.unread = 0; chatRenderThreads(); }
    $id('chatText').focus();
}
function chatCloseConv() {
    chat.phone = null;
    $id('chatWrap').classList.remove('conv-open');
    $id('chatPane').hidden = true;
    $id('chatEmpty').hidden = false;
    history.replaceState(null, '', '/?p=chats');
    chatRenderThreads();
}

async function chatLoadMessages(force) {
    if (!chat.phone) return;
    const r = await api('chat.messages', { phone: chat.phone });
    if (!r.ok || chat.phone !== r.phone) return;
    if (!force && r.messages.length === chat.count) return;
    chat.count = r.messages.length;
    chat.name = r.name || '';
    $id('chatAva').textContent = chatInitial(r.name, r.phone);
    $id('chatName').textContent = r.name || r.phone;
    $id('chatPhone').textContent = r.phone;
    $id('chatCard').href = '/?p=contacts&q=' + encodeURIComponent(r.phone);
    const body = $id('chatBody');
    const atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 90;
    body.innerHTML = r.messages.length
        ? r.messages.map(m => `<div class="cm ${m.dir}"><div class="cm-bubble">
            ${chatMedia(m)}
            ${chatText(m)}
            <span class="cm-meta">${esc(chatTime(m.ts))}${m.dir === 'out' ? chatTicks(m) : ''}</span>
          </div></div>`).join('')
        : '<div class="chat-hint">Сообщений пока нет — напишите первым.</div>';
    if (force || atBottom) body.scrollTop = body.scrollHeight;
}

async function chatSend(e) {
    if (e) e.preventDefault();
    const ta = $id('chatText');
    const text = ta.value.trim();
    if (!text || !chat.phone || chat.busy) return;
    chat.busy = true;
    const btn = $id('chatSendBtn');
    btn.disabled = true;
    const note = $id('chatChannelNote');
    note.textContent = '';
    const r = await api('chat.send', { phone: chat.phone, text });
    chat.busy = false;
    btn.disabled = false;
    if (r.ok) {
        ta.value = '';
        ta.style.height = 'auto';
        await chatLoadMessages(true);
        chatLoadThreads();
        ta.focus();
    } else {
        note.textContent = '⚠ ' + (r.error || 'Не удалось отправить');
    }
}

function chatInit() {
    chatLoadThreads().then(() => {
        const start = $id('chatWrap').dataset.start;
        if (start) chatOpen(start);
    });
    const ta = $id('chatText');
    if (ta) {
        ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; });
        ta.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); }
        });
    }
    chat.poll = setInterval(() => {
        if (document.hidden) return;
        chatLoadThreads();
        if (chat.phone) chatLoadMessages(false);
    }, 5000);
}

document.addEventListener('DOMContentLoaded', () => {
    bindCells();
    bindCatalog();
    if (document.body.dataset.page === 'chats') chatInit();
});
