<?php /** @var array $manifest @var array $passengers */ ?>
<div class="page-head">
    <div>
        <h1>Ведомость № <?= e($manifest['trip_number']) ?></h1>
        <div class="sub"><?= e($manifest['route']) ?></div>
    </div>
    <div class="head-actions">
        <a class="btn" href="/?p=notifications&manifest_id=<?= $manifest['id'] ?>"><?= icon('send') ?> К рассылке</a>
        <div class="dropdown">
            <button class="btn ghost" onclick="toggleDrop(this)"><?= icon('doc') ?> Документы ▾</button>
            <div class="dropdown-menu">
                <?php $carriers = db()->query('SELECT * FROM carriers ORDER BY id')->fetchAll(); ?>
                <div class="dd-section">Перевозчик / договор</div>
                <select id="docCarrier" class="dd-select">
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['atp']) ?> · №<?= e($c['contract_no']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="dd-check"><input type="checkbox" id="docStamp"> с печатью и подписью</label>
                <div class="dd-section">Для водителя</div>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'driver','pdf')">📄 PDF (открыть)</a>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'driver','word')">⬇ Word</a>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'driver','html')">🖨 Печать</a>
                <div class="dd-section">Дорожная (для инспекции)</div>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'road','pdf')">📄 PDF (открыть)</a>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'road','word')">⬇ Word</a>
                <a href="#" onclick="return openDoc(<?= $manifest['id'] ?>,'road','html')">🖨 Печать</a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="facts" id="tripFacts" data-id="<?= $manifest['id'] ?>">
        <div><span>Отправление</span><input class="cell" data-f="departure_view" value="<?= $manifest['departure_at'] ? date('d.m.Y H:i', strtotime($manifest['departure_at'])) : '' ?>" placeholder="дд.мм.гггг чч:мм"></div>
        <div><span>Маршрут</span><input class="cell" data-f="route" value="<?= e($manifest['route']) ?>"></div>
        <div><span>Перевозчик</span><input class="cell" data-f="carrier" value="<?= e($manifest['carrier']) ?>"></div>
        <div><span>Автобус</span><input class="cell" data-f="bus" value="<?= e($manifest['bus']) ?>"></div>
        <div><span>Водители</span><input class="cell" data-f="drivers" value="<?= e($manifest['drivers']) ?>"></div>
        <div><span>Телефон водителя</span><input class="cell" data-f="driver_phone" value="<?= e($manifest['driver_phone']) ?>" placeholder="+7…"></div>
        <div><span>Доп. информация</span><input class="cell" data-f="extra_info" value="<?= e($manifest['extra_info']) ?>" placeholder="попадёт в {доп}"></div>
    </div>
    <p class="muted small mt" style="margin-bottom:0">Поля редактируются прямо в ячейках — сохранение автоматическое.</p>
</div>

<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Пассажиры <span class="badge muted" id="pcount"><?= count($passengers) ?></span></h2>
        <button class="btn sm" onclick="addPassenger(<?= $manifest['id'] ?>)"><?= icon('plus') ?> Добавить пассажира</button>
    </div>
    <div class="table-wrap mt">
        <table class="t" id="ptable">
            <thead><tr>
                <th style="width:64px">Место</th><th>ФИО</th><th style="width:150px">Телефон</th>
                <th>Документ</th><th style="width:120px">Билет</th><th>Посадка</th><th>Высадка</th><th style="width:160px">Комментарий</th><th style="width:40px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($passengers as $p): ?>
                <tr data-id="<?= $p['id'] ?>">
                    <td><input class="cell" data-f="seat" value="<?= e($p['seat']) ?>"></td>
                    <td><input class="cell" data-f="name" value="<?= e($p['name']) ?>"></td>
                    <td><input class="cell" data-f="phone" value="<?= e($p['phone']) ?>"></td>
                    <td><input class="cell" data-f="doc" value="<?= e($p['doc']) ?>"></td>
                    <td><input class="cell" data-f="ticket" value="<?= e($p['ticket']) ?>"></td>
                    <td><input class="cell" data-f="from_stop" value="<?= e($p['from_stop']) ?>"></td>
                    <td><input class="cell" data-f="to_stop" value="<?= e($p['to_stop']) ?>"></td>
                    <td><input class="cell" data-f="pay_note" value="<?= e($p['pay_note']) ?>" placeholder="заметка"></td>
                    <td class="actions"><button class="icon-btn" title="Удалить" onclick="delPassenger(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="muted small mt" id="saveState">Все изменения сохранены</div>
</div>
