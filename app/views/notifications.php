<?php /** @var array $manifests @var int $selectedId @var ?array $selected @var string $uploadError */ ?>
<div class="page-head">
    <div>
        <h1>Уведомления</h1>
        <div class="sub">загрузка → проверка данных → группы → отправка</div>
    </div>
    <div class="head-actions">
        <?php if ($selected): ?>
            <a class="btn ghost sm" href="/?p=notifications&fresh=1" title="Сбросить выбор и начать с новой ведомости">↺ Сбросить</a>
            <button class="btn ghost sm" style="color:var(--err);margin-left:auto" onclick="deleteManifestFromNotif(<?= $selectedId ?>)" title="Удалить ведомость из системы (необратимо)">🗑 Удалить</button>
        <?php endif; ?>
        <span id="waStatus" class="badge muted">проверяю канал…</span>
    </div>
</div>

<div class="wa-note"><?= icon('whatsapp') ?><span><b>WhatsApp</b> — основной канал. Проверка получателей и доступных каналов выполняется автоматически; проблемные доставки появятся в общей сводке.</span></div>

<?php if ($uploadError !== ''): ?><div class="alert err"><?= e($uploadError) ?></div><?php endif; ?>

<!-- ШАГ 1 — ведомость -->
<div class="card step-card">
    <div class="step-row">
        <span class="step-num <?= $selected ? 'done' : '' ?>"><?= $selected ? '✓' : '1' ?></span>
        <div style="flex:1;min-width:0">
            <div class="step-title">Ведомость</div>
            <?php if ($selected): ?>
                <div class="row" style="gap:10px;flex-wrap:wrap">
                    <b>№<?= e($selected['trip_number']) ?> · <?= e($selected['route']) ?></b>
                    <span class="muted small"><?= e($selected['file_name']) ?></span>
                    <select id="cMan" onchange="location='/?p=notifications&manifest_id='+this.value" style="max-width:300px;margin-left:auto">
                        <?php foreach ($manifests as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id'] == $selectedId ? 'selected' : '' ?>>№<?= e($m['trip_number']) ?> · <?= e($m['route']) ?> · <?= $m['departure_at'] ? date('d.m', strtotime($m['departure_at'])) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="btn ghost sm" style="cursor:pointer">📂 Загрузить другую<input type="file" accept=".csv" style="display:none" onchange="this.closest('form')||0;uploadManifest(this)"></label>
                </div>
            <?php else: ?>
                <div class="step-title-sub muted small">Загрузите CSV из Jasper или выберите ранее загруженную</div>
                <form method="post" enctype="multipart/form-data" id="upForm" class="mt">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <label class="drop">
                        <?= icon('upload') ?>
                        <div><b>Выберите CSV-файл</b> или перетащите сюда</div>
                        <input type="file" name="manifest" accept=".csv,text/csv" onchange="this.form.submit()">
                    </label>
                </form>
                <?php if ($manifests): ?>
                    <div class="row mt">
                        <select id="cManPick" style="max-width:340px">
                            <?php foreach ($manifests as $m): ?>
                                <option value="<?= $m['id'] ?>">№<?= e($m['trip_number']) ?> · <?= e($m['route']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn ghost sm" onclick="location='/?p=notifications&manifest_id='+document.getElementById('cManPick').value">Открыть</button>
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
                <button class="btn ghost sm" onclick="gdsTimes()" id="gdsBtn"><?= icon('chart') ?> Обновить времена из GDS</button>
                <label class="row small muted" style="gap:7px;margin:0"><input type="checkbox" id="attachPhoto"> приложить фото автобуса</label>
                <label class="row small muted" style="gap:7px;margin:0"><input type="checkbox" id="driverPhoneOn" checked onchange="refreshAllPreviews()"> указать телефон водителя</label>
                <span class="small muted" id="busPhotoHint"></span>
            </div>
            <div id="gdsInfo" class="mt"></div>
            <div class="send-channel-panel mt">
                <div><b>Каналы отправки</b><div class="muted small">Отмеченные каналы работают параллельно. По умолчанию выбран только WhatsApp.</div></div>
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
    <div id="notificationReadiness" class="notif-readiness mt"><p class="muted">Проверяю пассажиров, время и каналы…</p></div>
    <div id="notificationIssues"></div>
    <div class="group-template mt" id="groupTemplateBox" style="display:none">
        <div class="row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
            <div><b>Шаблон на всю группу</b><div class="muted small">Применяется ко всем городам сразу; переменные (<span style="font-family:monospace">{город} {время} {адрес}</span>) подставятся свои для каждого.</div></div>
            <div class="row" style="gap:8px">
                <select id="gtplSelect" onchange="groupTemplatePick(this)"><option value="">— вставить шаблон —</option></select>
                <button class="btn sm" onclick="applyTemplateToAll(this)">Применить ко всем</button>
            </div>
        </div>
        <textarea id="gtplText" class="template-box mt" rows="4"></textarea>
        <div class="g-saved small" id="gtplSaved" style="min-height:16px"></div>
    </div>
    <div id="groupsBox" class="mt"><p class="muted">Загружаю группы…</p></div>
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

<!-- Единый монитор текущей ведомости -->
<div class="card step-card" id="campaignOverviewCard">
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
    waStatus();
    bindTripFacts();
    if (window.HAS_MANIFEST) { loadGroups(true); loadCampaignOverview(); }
});
</script>
