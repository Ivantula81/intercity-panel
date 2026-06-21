<?php /** @var array $manifests @var int $selectedId @var ?array $selected @var array $journal @var string $uploadError */ ?>
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

<div class="wa-note"><?= icon('whatsapp') ?><span>Отправка уведомлений идёт по <b>WhatsApp</b> (Evolution). MAX — только для ответов в диалогах «Чаты».</span></div>

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
        </div>
    </div>
</div>

<!-- ШАГ 3 — группы -->
<div class="card step-card">
    <div class="step-row">
        <span class="step-num">3</span>
        <div style="flex:1;min-width:0">
            <div class="step-title">Группы по точке посадки</div>
            <div class="step-title-sub muted small">у каждой группы свой текст, время и шаблон — раскройте карточку для правки</div>
        </div>
    </div>
    <div id="groupsBox" class="mt"><p class="muted">Загружаю группы…</p></div>
</div>

<!-- ШАГ 4 — отправка -->
<div class="card step-card send-bar" id="sendBar">
    <div class="step-row">
        <span class="step-num">4</span>
        <div style="flex:1;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
            <div class="muted small" id="sendSummary">подсчитываю получателей…</div>
            <button class="btn" onclick="sendAllGroups(this)"><?= icon('send') ?> Отправить все группы</button>
        </div>
    </div>
    <div id="allState" class="mt"></div>
</div>
<?php endif; ?>

<!-- Журнал доставки + ответы пассажиров -->
<div class="mt">
    <div>
    <div class="card">
        <h2>Журнал доставки</h2>
        <?php if (empty($journal)): ?><p class="muted">Отправок ещё не было.</p>
        <?php else: ?>
            <div class="feed">
            <?php foreach (array_slice($journal, 0, 25) as $j): ?>
                <div class="feed-item">
                    <div class="when"><?= date('d.m H:i', strtotime($j['sent_at'] ?? $j['created_at'])) ?></div>
                    <div style="min-width:0"><b><?= e($j['passenger_name'] ?: $j['recipient']) ?></b>
                        <span class="muted small"><?= e($j['recipient']) ?></span>
                        <?php if ($j['status'] === 'failed'): ?>
                            <span class="badge err" title="<?= e((string) $j['error']) ?>">ошибка</span>
                        <?php elseif (!empty($j['read_at'])): ?>
                            <span class="badge ok" title="прочитано <?= e($j['read_at']) ?>">✓✓ прочитано</span>
                        <?php elseif (!empty($j['delivered_at'])): ?>
                            <span class="badge ok" title="доставлено <?= e($j['delivered_at']) ?>">✓✓ доставлено</span>
                        <?php elseif ($j['status'] === 'sent'): ?>
                            <span class="badge muted">✓ отправлено</span>
                        <?php else: ?><span class="badge muted">в очереди</span><?php endif; ?>
                        <?php if (!empty($j['actor'])): ?><span class="muted small">· <?= e($j['actor']) ?></span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Ответы пассажиров</h2>
        <?php if (empty($inbox)): ?><p class="muted">Входящих пока нет. Ответы на уведомления появятся здесь.</p>
        <?php else: ?>
            <div class="feed">
            <?php foreach ($inbox as $in): ?>
                <div class="feed-item">
                    <div class="when"><?= date('d.m H:i', strtotime($in['received_at'])) ?></div>
                    <div style="min-width:0">
                        <b><?= e($in['name'] ?: $in['phone']) ?></b>
                        <span class="muted small"><?= e($in['phone']) ?></span>
                        <div class="msg-preview mt" style="background:#eef2fb;border-radius:12px 12px 4px 12px"><?= nl2br(e(mb_substr($in['body'], 0, 300))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </div>
</div>

<form id="hiddenUp" method="post" enctype="multipart/form-data" style="display:none">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="file" name="manifest" accept=".csv">
</form>
<script>
window.HAS_MANIFEST = <?= $selected ? 'true' : 'false' ?>;
document.addEventListener('DOMContentLoaded', () => {
    waStatus();
    bindTripFacts();
    if (window.HAS_MANIFEST) { loadGroups(true); }
});
</script>
