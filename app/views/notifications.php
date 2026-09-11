<?php /** @var array $manifests @var int $selectedId @var ?array $selected @var string $uploadError */ ?>
<div class="page-head"><div><h1>Уведомления</h1><div class="sub">Рейсы, отправки и результат · время московское</div></div>
<a class="btn ghost" href="/?p=notifications&fresh=1">Подготовить другую ведомость</a></div>
<?php require PANEL_ROOT . '/app/views/queue_monitor.php'; ?>
<?php if (!$selected && empty($_GET['fresh'])): ?>
<div class="card nc-list"><h2>Рейсы по дням</h2>
<form id="ncFilters" class="nc-filters">
<button type="button" class="btn ghost" onclick="ncDay(-1)" aria-label="Предыдущий день">←</button>
<label>Дата отправления<input type="date" id="ncDate" value="<?= date('Y-m-d') ?>"></label>
<button type="button" class="btn ghost" onclick="ncDay(1)" aria-label="Следующий день">→</button>
<button type="button" class="btn ghost" onclick="ncDay(0)">Сегодня</button>
<label>Номер рейса<input type="search" id="ncSearch" placeholder="Найти рейс"></label>
<label>Состояние<select id="ncFilterState"><option value="">Все</option><option value="new">Не запускалась</option><option value="running">В работе</option><option value="attention">Требует внимания</option><option value="done">Обработана</option></select></label>
<button class="btn">Показать</button><button type="button" class="btn ghost" onclick="document.getElementById('ncDate').value='';ncList(1)">Все даты</button></form>
<div id="ncTrips" aria-live="polite"></div></div>
<?php return; endif; ?>
<?php if ($selected): ?>
<header class="nc-trip" data-trip-number="<?= e($selected['trip_number']) ?>" data-trip-date="<?= $selected['departure_at'] ? date('d.m.Y',strtotime($selected['departure_at'])) : '' ?>"><a href="/?p=notifications">← Рейсы по дням</a><h2>№<?= e($selected['trip_number']) ?> · <?= $selected['departure_at'] ? date('d.m.Y H:i',strtotime($selected['departure_at'])) : 'Дата не указана' ?></h2><p><?= e($selected['route']) ?></p></header>
<nav class="nc-tabs" aria-label="Разделы ведомости"><button type="button" data-nc-tab="prepare" onclick="ncTab('prepare')">Подготовка</button><button type="button" data-nc-tab="result" onclick="ncTab('result')">Результат</button><button type="button" data-nc-tab="history" onclick="ncTab('history')">История</button></nav>
<div id="ncPrepare">
<?php endif; ?>
<?php
require_once PANEL_ROOT . '/lib/Channels.php';
$primaryCh = Channels::primary();
[$primaryLabel, $primaryColor] = msg_channel_meta($primaryCh);
?>
<div class="wa-note" style="border-left:3px solid <?= e($primaryColor) ?>"><?= icon('bell') ?><span><b><?= e($primaryLabel) ?></b> — основной канал (меняется в Настройках). Наличие мессенджера у получателей проверяется автоматически; проблемные доставки появятся в общей сводке.</span></div>

<?php if ($uploadError !== ''): ?><div class="alert err"><?= e($uploadError) ?></div><?php endif; ?>

<!-- ШАГ 1 — ведомость -->
<div class="card step-card">
    <div class="step-row">
        <span class="step-num <?= $selected ? 'done' : '' ?>"><?= $selected ? '✓' : '1' ?></span>
        <div style="flex:1;min-width:0">
            <div class="step-title">Ведомость</div>
            <?php if ($selected): ?>
                <p class="muted small">Источник: <?= e($selected['file_name']) ?></p>
            <?php else: ?>
                <div class="step-title-sub muted small">Введите номер рейса — ведомость подтянется из системы автовокзала: пассажиры, места, цены, агенты, станции.</div>
                <div class="pull-box mt">
                    <div class="pull-row">
                        <input id="pullTripId" class="pull-input" inputmode="numeric" placeholder="Номер рейса" onkeydown="if(event.key==='Enter')pullManifest('preview')">
                        <button class="btn" id="pullBtn" onclick="pullManifest('preview')"><?= icon('download') ?> Подтянуть из системы</button>
                    </div>
                    <div id="pullResult" class="mt"></div>
                    <div class="muted small mt"><?= icon('upload') ?> или <a href="#" onclick="document.getElementById('manualUpload').hidden=!document.getElementById('manualUpload').hidden;return false;">загрузить CSV-файл вручную</a></div>
                    <div id="manualUpload" hidden>
                        <form method="post" enctype="multipart/form-data" id="upForm" class="mt">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <label class="drop">
                                <?= icon('upload') ?>
                                <div><b>Выберите CSV-файл</b> или перетащите сюда</div>
                                <input type="file" name="manifest" accept=".csv,text/csv" onchange="this.form.submit()">
                            </label>
                        </form>
                    </div>
                </div>
                <?php if ($manifests): ?>
                    <div class="row mt">
                        <select id="cManPick" style="max-width:340px">
                            <?php foreach ($manifests as $m): ?>
                                <option value="<?= $m['id'] ?>">№<?= e($m['trip_number']) ?> · <?= e($m['route']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn ghost sm" onclick="location='/?p=notifications&manifest_id='+document.getElementById('cManPick').value">Открыть ранее загруженную</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($selected): ?>
<!-- ШАГ 2 — данные рейса -->
<div class="card step-card" id="tripFacts" data-id="<?= $selectedId ?>">
    <div class="step-row">
        <span class="step-num">2</span>
        <div style="flex:1;min-width:0">
            <div class="row" style="justify-content:space-between">
                <div class="step-title">Данные рейса</div>
                <span class="badge muted" id="gdsBadge">времена ещё не загружены</span>
            </div>
            <div class="facts mt">
                <div><span>Дата и время</span><input class="cell" data-f="departure_view" value="<?= $selected['departure_at'] ? date('d.m.Y H:i', strtotime($selected['departure_at'])) : '' ?>" placeholder="дд.мм.гггг чч:мм"></div>
                <div><span>Автобус</span>
                    <?php if (!empty($buses)): ?>
                    <select class="pick" onchange="pickBus(this)" title="Подобрать из справочника автобусов" style="width:100%;margin-bottom:4px;font-size:12px">
                        <option value="">— из справочника —</option>
                        <?php foreach ($buses as $b): $bl = trim(($b['code'] ?: '') . ' · ' . $b['model'] . ($b['plate'] ? ' · ' . $b['plate'] : ''), ' ·'); ?>
                        <option data-code="<?= e($b['code'] ?: $b['plate']) ?>" data-phone="<?= e($b['driver_phone']) ?>"><?= e($bl ?: 'автобус') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input class="cell" data-f="bus" value="<?= e($selected['bus']) ?>" placeholder="напр. 322 или вручную">
                </div>
                <div><span>Телефон водителя</span>
                    <?php if (!empty($drivers)): ?>
                    <select class="pick" onchange="pickDriver(this)" title="Подобрать водителя из справочника" style="width:100%;margin-bottom:4px;font-size:12px">
                        <option value="">— водитель из справочника —</option>
                        <?php foreach ($drivers as $d): ?>
                        <option data-name="<?= e($d['name']) ?>" data-phone="<?= e($d['phone']) ?>"><?= e($d['name'] . ($d['phone'] ? ' · ' . $d['phone'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input class="cell" data-f="driver_phone" value="<?= e($selected['driver_phone']) ?>" placeholder="+7…">
                </div>
                <div><span>Доп. информация <span class="muted" style="text-transform:none">(в сообщение, если заполнено)</span></span><input class="cell" data-f="extra_info" value="<?= e($selected['extra_info']) ?>" placeholder="напр. «при себе паспорт»"></div>
            </div>
            <div class="row mt" style="gap:14px">
                <a class="btn ghost sm" href="/?p=schedules&amp;manifest_id=<?= (int) $selected['id'] ?>" id="gdsBtn"><?= icon('chart') ?> Проверить расписание / ГДС</a>
                <label class="row small muted" style="gap:7px;margin:0"><input type="checkbox" id="attachPhoto"> приложить фото автобуса</label>
                <label class="row small muted" style="gap:7px;margin:0"><input type="checkbox" id="driverPhoneOn" checked onchange="refreshAllPreviews()"> указать телефон водителя</label>
                <label class="row small muted" style="gap:7px;margin:0"><input type="checkbox" id="notificationEmergency"> экстренная отправка (обойти рабочее время)</label>
                <span class="small muted" id="busPhotoHint"></span>
            </div>
            <div id="gdsInfo" class="mt"></div>
            <div id="notificationSchedule" class="mt" data-manifest-id="<?= (int) $selected['id'] ?>" aria-live="polite"></div>
            <div class="send-channel-panel mt">
                <div><b>Каналы отправки</b><div class="muted small">Отмеченные каналы работают параллельно. По умолчанию выбран основной — <?= e($primaryLabel) ?>.</div></div>
                <div id="sendChannels" class="send-channel-choices"><span class="muted small">проверяю подключения…</span></div>
                <div id="sendChannelEstimate" class="small muted"></div>
            </div>
        </div>
    </div>
</div>

<!-- ШАГ 3 — группы -->
<div class="card step-card">
    <div class="step-row">
        <span class="step-num">3</span>
        <div style="flex:1;min-width:0">
            <div class="step-title">Подготовка рассылки</div>
            <div class="step-title-sub muted small">сначала проверьте общую готовность; раскрывайте направление только для точечной правки</div>
        </div>
    </div>
    <div id="notificationReadiness" class="notif-readiness mt" aria-live="polite"><p class="muted">Проверяю пассажиров, время и каналы…</p></div>
    <details id="ncIssues"><summary id="ncIssuesCount">Требует внимания</summary><div id="notificationIssues"></div></details>
    <div class="group-template mt" id="groupTemplateBox" style="display:none">
        <div class="row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
            <div><b>Шаблон на всю ведомость</b><div class="muted small">Основной текст для всех городов. Ниже можно переопределить его по блоку отправления или вручную по городу; переменные (<span style="font-family:monospace">{город} {время} {адрес}</span>) подставятся свои.</div></div>
            <div class="row" style="gap:8px">
                <select id="gtplSelect" onchange="manifestTemplatePick(this)"><option value="">— вставить шаблон —</option></select>
                <button class="btn sm" onclick="applyManifestTemplate(this)">Применить ко всей ведомости</button>
            </div>
        </div>
        <textarea id="gtplText" class="template-box mt" rows="4"></textarea>
        <div class="g-saved small" id="gtplSaved" style="min-height:16px"></div>
    </div>
    <div class="nc-selection"><button type="button" class="btn ghost sm" onclick="ncSelectAll(true)">Выбрать все</button><button type="button" class="btn ghost sm" onclick="ncSelectAll(false)">Снять все</button></div><div id="groupsBox" class="mt"><p class="muted">Загружаю группы…</p></div>
</div>

<!-- ШАГ 4 — отправка -->
<div class="card step-card send-bar" id="sendBar">
    <div class="step-row">
        <span class="step-num">4</span>
        <div style="flex:1;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
            <div class="muted small" id="sendSummary">подсчитываю получателей…</div>
            <button class="btn" data-send-all onclick="sendAllGroups(this)"><?= icon('send') ?> Подтвердить и отправить</button>
        </div>
    </div>
    <div id="allState" class="mt"></div>
</div>

</div><!-- preparation -->
<div id="ncResult" hidden><div id="ncOutcome" class="card" aria-live="polite">Загружаю результат…</div></div>
<div id="ncHistory" class="card" hidden><h2>История запусков</h2><label>Дата запуска <input type="date" id="ncHistoryDate" onchange="ncHistory(1)"></label><div id="ncHistoryItems"></div></div>
<!-- Legacy monitor retained for compatibility; center owns visible read model. -->
<div class="card step-card" id="campaignOverviewCard" hidden>
    <div class="row" style="justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
            <div class="step-title">Доставка по этой ведомости</div>
            <div class="step-title-sub muted small">статусы обновляются автоматически; здесь собраны только пассажиры текущего рейса</div>
        </div>
        <div class="row" style="gap:8px">
            <a class="btn ghost sm" href="/?p=chats">Открыть ответы в чатах</a>
            <button class="btn ghost sm" onclick="loadCampaignOverview(true)">Обновить</button>
        </div>
    </div>
    <div id="campaignOverview" class="mt"><p class="muted">Загружаю статусы…</p></div>
</div>
<?php endif; ?>

<form id="hiddenUp" method="post" enctype="multipart/form-data" style="display:none">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="file" name="manifest" accept=".csv">
</form>
<script>
window.HAS_MANIFEST = <?= $selected ? 'true' : 'false' ?>;
document.addEventListener('DOMContentLoaded', () => {
    channelStatusBadge();
    bindTripFacts();
    if (window.HAS_MANIFEST) { loadGroups(true); }
});
</script>
