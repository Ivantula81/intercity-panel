/* Schedule editor. Fetching GDS only adds proposals; saving/applying are explicit actions. */
(() => {
    const app = document.getElementById('routeScheduleApp');
    const banner = document.getElementById('notificationSchedule');
    let model = null, dirty = false, busy = false;
    const norm = s => String(s || '').trim().toLowerCase().replace(/ё/g, 'е').replace(/[—–]/g, '-').replace(/\s+/g, ' ');
    const key = s => s.station_id ? 'id:' + Number(s.station_id) : 'name:' + norm(s.station);
    const matches = (s, other) => key(s) === key(other) || (s.aliases || []).includes(key(other));
    const clock = s => !s ? '—' : s.terminal ? 'Конечная' : s.time ? s.time + (Number(s.day) ? ` (+${s.day} дн.)` : '') : 'Нет времени';
    const editor = () => document.getElementById('scheduleEditor');
    function message(text, error = false) {
        const box = document.getElementById('scheduleMessage');
        if (box) { box.className = 'alert mt ' + (error ? 'err' : 'ok'); box.textContent = text; }
    }
    function markDirty() {
        dirty = true;
        const check = document.getElementById('scheduleConfirmed');
        if (check) check.checked = false;
        const status = document.getElementById('scheduleMessage');
        if (status) { status.className = 'muted small mt'; status.textContent = 'Есть несохранённые изменения'; }
    }
    function merge(stops, source, gds = []) {
        const rows = stops.map(s => ({ ...s, saved: { ...s }, source: null, gds: null }));
        for (const s of source) {
            const found = rows.find(r => matches(r, s));
            if (found) found.source = s;
            else rows.push({ ...s, source: s, saved: null, gds: null });
        }
        for (const s of gds) {
            const found = rows.find(r => matches(r, s));
            if (found) found.gds = s;
            else rows.push({ ...s, source: null, saved: null, gds: s });
        }
        return rows;
    }
    function fromResponse(r) {
        const c = r.context || null;
        const s = c?.schedule || r.schedule || null;
        model = { context: c, schedule: s, canEdit: r.can_edit ?? model?.canEdit ?? false,
            rows: merge(s?.stops || [], c?.state.source || []), gds: [] };
        dirty = false;
        render();
    }
    async function open(params) {
        if (busy || (dirty && !confirm('Есть несохранённые изменения. Перейти к другому расписанию?'))) return;
        busy = true;
        try {
            const r = await api('schedule.get', params);
            if (!r.ok) { message(r.error, true); return; }
            fromResponse(r);
            if (app) app.dataset.manifestId = String(r.context?.manifest_id || 0);
            document.getElementById('scheduleManifest').value = String(r.context?.manifest_id || '');
            message(r.context?.state.source_kind === 'legacy'
                ? 'Для старой ведомости показаны текущие времена, которые могли редактироваться ранее. Проверьте также плановый старт по оригиналу.'
                : 'Проверьте время и день отправления каждой остановки.');
        } finally { busy = false; }
    }
    function rowHtml(r, i) {
        const c = model.context;
        const boarding = c?.boarding.some(s => matches(r, s));
        const origin = norm((c?.route || model.schedule.route).split(/\s+[—–-]\s+/)[0]);
        const sameStart = !r.terminal && r.time === (c?.start_time || model.schedule.start_time) && norm(r.station) !== origin && Number(r.day) === 0;
        const warning = r.source?.conflict || sameStart;
        const status = r.source?.conflict ? 'Разные времена' : sameStart ? 'Совпадает со стартом'
            : r.saved ? 'Проверено' : r.gds ? 'Данные ГДС' : 'Проверьте';
        const options = model.gds.filter(s => !matches(r, s)).map((s, n) => ({ s, index: model.gds.indexOf(s) }));
        return `<tr data-index="${i}">
            <td data-label="Станция"><div class="schedule-station">${esc(r.station) || 'Новая остановка'}</div>
                <details class="schedule-row-options"><summary>Изменить остановку</summary>
                <label class="f">Название<input type="text" data-field="station" value="${esc(r.station)}" maxlength="255"></label>
                <label class="row small"><input data-field="terminal" type="checkbox" ${r.terminal ? 'checked' : ''}> Конечная станция</label></details>
                ${options.length ? `<details><summary class="small">Связать со станцией ГДС…</summary><select data-link aria-label="Выбрать станцию ГДС"><option value="">Выберите точно ту же остановку</option>${options.map(o => `<option value="${o.index}">${esc(o.s.station)} · ${esc(clock(o.s))}</option>`).join('')}</select><button type="button" class="btn ghost sm" data-command="link">Связать</button></details>` : ''}
            </td>
            <td data-label="Ведомость" class="schedule-source">${esc(clock(r.source))}</td>
            <td data-label="ГДС" class="schedule-source"><div>${esc(clock(r.gds))}</div>${r.gds?.arrival ? `<div class="muted small">Приб. ${esc(clock(r.gds.arrival))}</div>` : ''}
                ${r.gds && (r.gds.time || r.gds.terminal) ? '<button class="btn ghost sm" type="button" data-command="take">Взять из ГДС</button>' : ''}</td>
            <td data-label="Отправление"><div class="schedule-time-pair"><label class="f"><span class="sr-only">Время отправления</span><input aria-label="Время отправления ${esc(r.station)}" data-field="time" type="time" value="${esc(r.time)}" ${r.terminal ? 'disabled' : ''}></label>
                <label class="f"><span class="sr-only">День от выезда</span><select data-field="day" aria-label="День отправления ${esc(r.station)}">${Array.from({length:31},(_,d)=>`<option value="${d}" ${Number(r.day) === d ? 'selected' : ''}>${d===0 ? 'В день выезда' : '+'+d+' '+(d===1 ? 'день' : d<5 ? 'дня' : 'дней')}</option>`).join('')}</select></label></div>
                ${r.saved ? `<div class="schedule-saved-value">Сохранено ${esc(clock(r.saved))}</div>` : ''}
            </td>
            <td data-label="Проверка"><span class="schedule-status ${warning ? 'needs-review' : r.saved ? 'verified' : ''}">${warning ? '! ' : r.saved ? '✓ ' : ''}${esc(status)}</span></td>
        </tr>`;
    }
    function render() {
        const c = model.context, s = model.schedule;
        const route = c?.route || s.route, start = c?.start_time || s.start_time;
        const table = indexes => `<div class="table-wrap"><table class="t schedule-table"><thead><tr><th>Остановка</th><th>Ведомость</th><th>ГДС</th><th>Отправление</th><th>Статус</th></tr></thead><tbody>${indexes.map(i => rowHtml(model.rows[i], i)).join('')}</tbody></table></div>`;
        const primary = [], extra = [];
        model.rows.forEach((r, i) => (c && !c.boarding.some(b => matches(r, b)) ? extra : primary).push(i));
        editor().innerHTML = `<div class="card schedule-editor-card">
            <div class="schedule-heading"><div><div class="schedule-eyebrow">${s ? 'Сохранённое расписание' : 'Первичная настройка'}</div><h2>${esc(route)}</h2>
            ${c ? `<p class="muted small">Выезд №${esc(c.trip_number)} · ${esc(c.base_date.split('-').reverse().join('.'))} · Московское время</p>` : '<p class="muted small">Выберите выезд выше, чтобы проверить ГДС.</p>'}</div>
            <div class="schedule-start"><span>Старт маршрута</span><strong>${esc(start)}</strong></div></div>
            <div class="schedule-toolbar">
            ${c ? '<button type="button" class="btn ghost" id="scheduleGds">Проверить обновления ГДС</button>' : ''}
            <span id="scheduleGdsStatus" class="muted small" role="status">${model.gds.length ? 'Данные получены. Выберите нужные изменения в строках.' : 'Проверьте время по источнику.'}</span></div>
            <fieldset class="schedule-fields" ${model.canEdit ? '' : 'disabled'}>
                <legend class="sr-only">Остановки расписания</legend>
                <div class="schedule-section-heading"><h3>${c ? 'С посадкой в этом выезде' : 'Остановки маршрута'}</h3><span>${primary.length} остановок</span></div>${table(primary)}
                ${extra.length ? `<details class="schedule-extra"><summary>Другие остановки маршрута <span>${extra.length}</span></summary>${table(extra)}</details>` : ''}
                <button type="button" class="btn ghost mt" id="scheduleAdd">+ Добавить остановку</button>
                <div class="schedule-save"><div><label class="row"><input type="checkbox" id="scheduleConfirmed"> Расписание проверено</label>
                    <p class="small muted">Включая предупреждения. Для следующих выездов со стартом ${esc(start)}.</p></div>
                    <button type="button" class="btn" id="scheduleSave">${c ? 'Сохранить и применить' : 'Сохранить расписание'}</button>
                </div>
            </fieldset>
            ${!model.canEdit ? '<p class="alert warn">Сохранение справочника доступно администратору.</p>' : ''}
            ${c && s ? '<button type="button" class="btn ghost mt" id="scheduleApply">Применить сохранённую версию к этой ведомости</button>' : ''}
            ${c ? `<a class="btn ghost mt" href="/?p=notifications&amp;manifest_id=${c.manifest_id}">Вернуться к уведомлениям</a>` : ''}
            <details class="schedule-help"><summary>Как применяется расписание</summary><p>Порядок строк не задаёт порядок движения автобуса. Подвозные остановки не назначаются автоматически. Сохранение действует на следующие выезды этого маршрута со стартом ${esc(start)}. Разовые правки остаются только в своей ведомости.</p></details>
        </div>`;
        editor().querySelectorAll('[data-field]').forEach(input => input.addEventListener('input', () => {
            const r = model.rows[Number(input.closest('tr').dataset.index)];
            r[input.dataset.field] = input.type === 'checkbox' ? input.checked : input.value;
            if (input.dataset.field === 'station') input.closest('tr').querySelector('.schedule-station').textContent = input.value || 'Новая остановка';
            if (input.dataset.field === 'terminal') { r.time = ''; const t = input.closest('tr').querySelector('[data-field="time"]'); t.value = ''; t.disabled = input.checked; }
            markDirty();
        }));
        editor().querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
            const tr = button.closest('tr'), index = Number(tr.dataset.index), r = model.rows[index];
            if (button.dataset.command === 'take') {
                r.time = r.gds.time; r.day = r.gds.day; r.terminal = r.gds.terminal;
            } else {
                const value = tr.querySelector('[data-link]').value;
                if (value === '') return;
                const g = model.gds[Number(value)];
                const other = model.rows.find(x => x !== r && matches(x, g));
                if (!confirm(`Подтвердите: «${r.station}» и «${g.station}» — одна остановка. Её строки будут объединены; время останется введённым.`)) return;
                r.aliases = [...new Set([...(r.aliases || []), key(g), ...(other?.aliases || [])])].filter(k => k !== key(r));
                r.gds = g;
                if (other) { r.source ||= other.source; model.rows.splice(model.rows.indexOf(other), 1); }
            }
            markDirty(); render();
        }));
        document.getElementById('scheduleAdd')?.addEventListener('click', () => {
            model.rows.push({ station: '', station_id: null, time: '', day: 0, terminal: false, source: null, gds: null, saved: null });
            markDirty(); render();
            const row = editor().querySelector(`tr[data-index="${model.rows.length - 1}"]`);
            const details = row?.closest('details'); if (details) details.open = true;
            const options = row?.querySelector('.schedule-row-options'); if (options) options.open = true;
            row?.querySelector('[data-field="station"]')?.focus();
        });
        document.getElementById('scheduleGds')?.addEventListener('click', fetchGds);
        document.getElementById('scheduleSave')?.addEventListener('click', save);
        document.getElementById('scheduleApply')?.addEventListener('click', apply);
    }
    function lock(value) {
        busy = value;
        editor().querySelectorAll('button, input, select').forEach(el => { el.disabled = value || !!el.closest('fieldset[disabled]'); });
        if (!value) editor().querySelectorAll('[data-field="time"]').forEach(el => { el.disabled = !model.canEdit || !!model.rows[Number(el.closest('tr').dataset.index)].terminal; });
    }
    async function fetchGds() {
        if (busy) return;
        lock(true);
        message('Запрашиваю ГДС…');
        const r = await api('schedule.gds', { manifest_id: model.context.manifest_id });
        lock(false);
        if (!r.ok) { message(r.error, true); return; }
        // Do not replace the loaded context/version: another person's edit must cause a conflict on save.
        model.gds = r.proposal.stops;
        for (const g of model.gds) {
            const row = model.rows.find(s => matches(s, g));
            if (row) row.gds = g;
            else model.rows.push({ ...g, gds: g, saved: null, source: null });
        }
        markDirty(); render();
        message('ГДС подтвердила рейс, дату, маршрут и плановый старт. Проверьте предложения и отсутствующие станции.');
    }
    async function save() {
        if (busy) return;
        if (!document.getElementById('scheduleConfirmed').checked) { message('Отметьте «Расписание проверено».', true); return; }
        const c = model.context, s = model.schedule;
        lock(true);
        const r = await api('schedule.save', { route: c?.route || s.route, start_time: c?.start_time || s.start_time,
            manifest_id: c?.manifest_id, version: s?.version || 0, revision: c?.state.revision,
            confirmed: true, apply: !!c, stops: model.rows.map(({ station, station_id, time, day, terminal, aliases }) => ({ station, station_id, time, day, terminal, aliases })) });
        lock(false);
        if (!r.ok) { message(r.error, true); return; }
        fromResponse(r);
        message(c ? 'Расписание сохранено и применено. Разовые правки этого выезда сохранены.' : 'Расписание сохранено.');
        await loadList();
    }
    async function apply() {
        if (busy) return;
        if (dirty) { message('Сначала сохраните правки или откройте расписание заново. Применение использует только сохранённую версию.', true); return; }
        const c = model.context;
        lock(true);
        const r = await api('schedule.apply', { manifest_id: c.manifest_id, schedule_key: c.schedule_key,
            version: model.schedule.version, revision: c.state.revision });
        lock(false);
        if (!r.ok) { message(r.error, true); return; }
        fromResponse(r); message('Сохранённая версия применена. Разовые правки оставлены.');
    }
    async function loadList() {
        const r = await api('schedule.list');
        if (!r.ok) { message(r.error, true); return; }
        const select = document.getElementById('scheduleManifest'), selected = select.value;
        select.innerHTML = '<option value="">Выберите ведомость</option>' + r.manifests.map(m => `<option value="${m.id}">№${esc(m.trip_number)} · ${esc(m.route)} · ${esc(m.departure_at)}</option>`).join('');
        select.value = selected;
        document.getElementById('scheduleList').innerHTML = r.schedules.length
            ? '<div class="schedule-links">' + r.schedules.map(s => `<button class="btn ghost" data-key="${esc(s.schedule_key)}">${esc(s.route)} · ${esc(s.start_time)} <span class="small muted">v${s.version}</span></button>`).join('') + '</div>'
            : '<p class="muted">Проверенных расписаний пока нет. Выберите ведомость для первой настройки.</p>';
        document.querySelectorAll('#scheduleList [data-key]').forEach(b => b.addEventListener('click', () => open({ schedule_key: b.dataset.key })));
    }
    async function refreshBanner() {
        if (!banner) return;
        const r = await api('schedule.get', { manifest_id: Number(banner.dataset.manifestId) });
        if (!r.ok) { banner.innerHTML = `<div class="alert warn">${esc(r.error)}</div>`; return; }
        const c = r.context, applied = c.state.schedule_version;
        const missing = c.boarding.filter(s => !c.state.applied.some(a => matches(a, s)));
        const differences = c.state.source.filter(s => {
            const a = c.state.applied.find(x => matches(x, s));
            return a && s.time && (a.time !== s.time || Number(a.day) !== Number(s.day));
        });
        banner.innerHTML = `<div class="alert ${applied ? 'ok' : 'warn'}"><b>${applied ? 'Расписание применено · версия ' + applied : 'Для этой ведомости расписание ещё не применено'} · старт ${esc(c.start_time)}</b>
            ${!c.schedule ? '<p>Получите данные ГДС, проверьте остановки и сохраните расписание для следующих выездов.</p>' : ''}
            ${missing.length ? `<p>Не настроены для этой ведомости: ${missing.map(s => esc(s.station)).join(', ')}.</p>` : ''}
            ${differences.length ? `<p>Расписание отличается от исходных времён: ${differences.map(s => esc(s.station)).join(', ')}.</p>` : ''}
            ${c.schedule && c.schedule.version !== applied ? '<p>В справочнике есть версия для проверки и применения.</p>' : ''}
            <a class="btn ghost sm" href="/?p=schedules&amp;manifest_id=${c.manifest_id}">${c.schedule ? 'Открыть расписание' : 'Получить из ГДС и проверить / заполнить вручную'}</a>
            <p class="small">Разовая правка: раскройте направление ниже и измените дату/время — только для этого выезда, для всей станции посадки.</p></div>`;
    }
    window.refreshScheduleBanner = refreshBanner;
    if (banner) refreshBanner();
    if (app) {
        window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
        document.getElementById('scheduleManifest').addEventListener('change', e => { if (e.target.value) open({ manifest_id: Number(e.target.value) }); });
        loadList().then(() => {
            if (Number(app.dataset.manifestId)) open({ manifest_id: Number(app.dataset.manifestId) });
            else if (app.dataset.key) open({ schedule_key: app.dataset.key });
        });
    }
})();
