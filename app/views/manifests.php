<?php /** @var array $manifests @var string $uploadError */ ?>
<section class="trip-workspace">
<div class="page-head">
    <div>
        <h1>Рейсы и ведомости</h1>
        <div class="sub">Загруженные ведомости · подготовка рейса и связь с пассажирами</div>
    </div>
</div>

<?php if ($uploadError !== ''): ?><div class="alert err"><?= e($uploadError) ?></div><?php endif; ?>

<?php require_once PANEL_ROOT . '/app/trip_workspace.php'; ?>
<form class="trip-filters card" method="get" aria-label="Поиск ведомостей">
    <input type="hidden" name="p" value="manifests">
    <label class="trip-search">Маршрут или номер рейса<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Например, Москва" maxlength="200"></label>
    <label>Отправление с<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label>по<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <label class="trip-check"><input type="checkbox" name="needs" value="1" <?= $filters['needs'] === '1' ? 'checked' : '' ?>> Не заполнены данные</label>
    <button class="btn" type="submit">Найти</button>
    <a class="btn ghost" href="/?p=manifests">Сбросить</a>
</form>
<div class="card">
    <div class="trip-section-head"><h2>Загруженные ведомости</h2><span class="muted"><?= (int)$total ?> по запросу</span></div>
    <p class="muted small">Каждая строка — отдельная ведомость. Совпадающие номера рейса не объединяются. Время — МСК.</p>
    <?php if (!$manifests): ?>
        <div class="trip-empty">Ведомости не найдены. Измените поиск или даты, сбросьте фильтры либо загрузите ведомость ниже.</div>
    <?php else: ?>
    <table class="t trip-list">
        <thead><tr><th>Отправление · МСК</th><th>Рейс</th><th>Данные для подготовки</th><th>Действия</th></tr></thead>
        <tbody><?php foreach ($manifests as $m): $missing = trip_missing_fields($m); ?>
        <tr id="manifest-<?= (int)$m['id'] ?>">
            <td data-label="Отправление"><?= $m['departure_at'] ? date('d.m.Y', strtotime($m['departure_at'])) : 'Не указано' ?><span class="trip-secondary"><?= $m['departure_at'] ? date('H:i', strtotime($m['departure_at'])) . ' · МСК' : 'Уточните время' ?></span></td>
            <td><a class="trip-route" href="/?p=manifest&amp;id=<?= (int)$m['id'] ?>"><?= e($m['route'] ?: 'Маршрут не указан') ?></a><span class="trip-secondary">Рейс № <?= e($m['trip_number']) ?> · ведомость #<?= (int)$m['id'] ?> · пассажиров: <?= (int)$m['cnt'] ?></span></td>
            <td data-label="Подготовка"><span class="trip-status <?= $missing ? 'needs' : '' ?>"><?= $missing ? 'Заполнить: ' . e(implode(', ', $missing)) : 'Основные поля заполнены' ?></span></td>
            <td class="actions"><a class="btn ghost sm" href="/?p=manifest&amp;id=<?= (int)$m['id'] ?>">Открыть</a><a class="btn ghost sm" href="/?p=boarding&amp;id=<?= (int)$m['id'] ?>" target="_blank">Печать</a><button type="button" class="icon-btn" title="Удалить ведомость" onclick="delManifest(<?= (int)$m['id'] ?>)"><?= icon('trash') ?></button></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <nav class="trip-pagination" aria-label="Страницы ведомостей">
        <span>Показано <?= $total ? $offset + 1 : 0 ?>–<?= $offset + count($manifests) ?> из <?= (int)$total ?></span>
        <?php if ($filters['page'] > 1): ?><a class="btn ghost" rel="prev" href="<?= e(trip_list_url($filters, $filters['page'] - 1)) ?>">← Назад</a><?php endif; ?>
        <span>Страница <?= (int)$filters['page'] ?> из <?= (int)$pages ?></span>
        <?php if ($filters['page'] < $pages): ?><a class="btn ghost" rel="next" href="<?= e(trip_list_url($filters, $filters['page'] + 1)) ?>">Далее →</a><?php endif; ?>
    </nav>
</div>
<details class="trip-import" <?= $uploadError !== '' ? 'open' : '' ?>>
    <summary>Загрузить ведомость</summary>
<form class="card" method="post" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="drop" id="drop">
        <?= icon('upload') ?>
        <div><b>Выберите CSV-файл</b> или перетащите сюда</div>
        <div class="small">Windows-1251 и UTF-8 распознаются автоматически · до 10 МБ</div>
        <input type="file" name="manifest" accept=".csv,text/csv" onchange="this.form.submit()">
    </label>
</form>

<div class="card step-card">
    <div class="step-row">
        <span class="step-num">1</span>
        <div style="flex:1;min-width:0">
            <div class="step-title">Загрузить ведомость по номеру рейса</div>
            <div class="step-title-sub muted small">Введите номер — система покажет найденный рейс и подтянет пассажиров, места, цены и станции.</div>
            <div class="pull-box mt">
                <div class="pull-row">
                    <input id="manifestPullTripId" class="pull-input" inputmode="numeric" placeholder="Номер рейса" onkeydown="if(event.key==='Enter')pullManifest('preview','manifests')">
                    <button class="btn" id="manifestPullBtn" type="button" onclick="pullManifest('preview','manifests')"><?= icon('download') ?> Подтянуть из системы</button>
                </div>
                <div id="manifestPullResult" class="mt"></div>
            </div>
            <?php if ($manifests): ?>
                <div class="row mt" style="gap:10px;flex-wrap:wrap">
                    <select id="manifestPick" style="max-width:360px">
                        <?php foreach ($manifests as $m): ?>
                            <option value="<?= (int)$m['id'] ?>">№<?= e($m['trip_number']) ?> · <?= e($m['route']) ?> · <?= $m['departure_at'] ? date('d.m', strtotime($m['departure_at'])) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn ghost sm" type="button" onclick="location='/?p=manifest&id='+document.getElementById('manifestPick').value">Открыть ранее загруженную</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


</details>
</section>
