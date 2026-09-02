<?php
$stops = db()->query('SELECT * FROM stops ORDER BY city, station')->fetchAll();
$buses = db()->query('SELECT * FROM buses ORDER BY plate')->fetchAll();
$drivers = db()->query('SELECT * FROM drivers ORDER BY name')->fetchAll();
$templates = db()->query('SELECT * FROM templates ORDER BY sort, id')->fetchAll();
try { $variables = db()->query('SELECT * FROM variables ORDER BY name')->fetchAll(); } catch (Exception $e) { $variables = []; }
try { $carriers = db()->query('SELECT * FROM carriers ORDER BY id')->fetchAll(); } catch (Exception $e) { $carriers = []; }
?>
<div class="page-head">
    <div>
        <h1>Справочники</h1>
        <div class="sub">посадки, автобусы, водители и шаблоны — всё, что подставляется в рассылки</div>
    </div>
</div>

<div class="tabs">
    <button class="tab active" onclick="showTab('stops', this)"><?= icon('link') ?> Посадки <span class="badge muted"><?= count($stops) ?></span></button>
    <button class="tab" onclick="showTab('buses', this)"><?= icon('briefcase') ?> Автобусы <span class="badge muted"><?= count($buses) ?></span></button>
    <button class="tab" onclick="showTab('drivers', this)">Водители <span class="badge muted"><?= count($drivers) ?></span></button>
    <button class="tab" onclick="showTab('templates', this)"><?= icon('doc') ?> Шаблоны <span class="badge muted"><?= count($templates) ?></span></button>
    <button class="tab" onclick="showTab('variables', this)">Переменные <span class="badge muted"><?= count($variables) ?></span></button>
    <button class="tab" onclick="showTab('carriers', this)">Перевозчики <span class="badge muted"><?= count($carriers) ?></span></button>
</div>

<div class="tab-pane" data-tab="carriers" hidden>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Перевозчики и договоры</h2>
        <button class="btn sm" onclick="addCatRow('carriers')"><?= icon('plus') ?> Добавить</button>
    </div>
    <p class="muted small">Выбираются при формировании ведомости для водителя/дорожной. Номер и дата договора попадают в шапку документа.</p>
    <div class="table-wrap mt">
        <table class="t cat" id="carriersTable" data-kind="carrier">
            <thead><tr><th style="width:24%">Перевозчик (ATP)</th><th style="width:15%">№ договора</th><th style="width:20%">Дата договора</th><th style="width:25%">Адреса уведомлений</th><th>Прим.</th><th style="width:40px"></th></tr></thead>
            <tbody>
            <?php foreach ($carriers as $c): ?>
                <tr data-id="<?= $c['id'] ?>">
                    <td><input class="cell" data-f="atp" value="<?= e($c['atp']) ?>"></td>
                    <td><input class="cell" data-f="contract_no" value="<?= e($c['contract_no']) ?>"></td>
                    <td><input class="cell" data-f="contract_date" value="<?= e($c['contract_date']) ?>" placeholder="01 января 2026 года"></td>
                    <td><input class="cell" data-f="notification_emails" value="<?= e($c['notification_emails'] ?? '') ?>" placeholder="sales@example.ru"></td>
                    <td><input class="cell" data-f="note" value="<?= e($c['note']) ?>"></td>
                    <td class="actions"><button class="icon-btn" onclick="delCatRow(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="tab-pane" data-tab="variables" hidden>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Свои переменные</h2>
        <button class="btn sm" onclick="addCatRow('variables')"><?= icon('plus') ?> Добавить</button>
    </div>
    <p class="muted small">Постоянные значения для шаблонов и рассылок. Например, переменная <code>подпись</code> подставится везде, где в тексте написано <code>{подпись}</code>. Регистр и пробелы в скобках не важны.</p>
    <div class="table-wrap mt">
        <table class="t cat" id="variablesTable" data-kind="var">
            <thead><tr><th style="width:20%">Имя (без скобок)</th><th>Значение</th><th style="width:22%">Примечание</th><th style="width:40px"></th></tr></thead>
            <tbody>
            <?php foreach ($variables as $v): ?>
                <tr data-id="<?= $v['id'] ?>">
                    <td><input class="cell" data-f="name" value="<?= e($v['name']) ?>"></td>
                    <td><input class="cell" data-f="value" value="<?= e($v['value']) ?>"></td>
                    <td><input class="cell" data-f="note" value="<?= e($v['note']) ?>"></td>
                    <td class="actions"><button class="icon-btn" onclick="delCatRow(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small mt" style="margin-bottom:0">Системные переменные (имя, дата, время, посадка, карта, автобус и т.д.) всегда главнее: свою переменную с таким же именем система перезапишет.</p>
</div>
</div>

<div class="tab-pane" data-tab="stops">
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Точки посадки</h2>
        <div class="row">
            <input type="text" id="stopFilter" placeholder="Поиск станции…" style="width:220px" oninput="filterRows('stopsTable', this.value)">
            <button class="btn sm" onclick="addCatRow('stops')"><?= icon('plus') ?> Добавить</button>
        </div>
    </div>
    <div class="table-wrap mt">
        <table class="t cat" id="stopsTable" data-kind="stop">
            <thead><tr><th style="width:64px">ID</th><th style="width:21%">Станция (как в ведомости)</th><th style="width:12%">Город</th><th style="width:25%">Адрес посадки</th><th style="width:22%">Ссылка на карту</th><th>Прим.</th><th style="width:40px"></th></tr></thead>
            <tbody>
            <?php foreach ($stops as $s): ?>
                <tr data-id="<?= $s['id'] ?>">
                    <td><input class="cell" data-f="gds_id" value="<?= $s['gds_id'] !== null ? (int) $s['gds_id'] : '' ?>" placeholder="—"></td>
                    <td><input class="cell" data-f="station" value="<?= e($s['station']) ?>"></td>
                    <td><input class="cell" data-f="city" value="<?= e($s['city']) ?>"></td>
                    <td><input class="cell" data-f="address" value="<?= e($s['address']) ?>"></td>
                    <td><input class="cell" data-f="map_url" value="<?= e($s['map_url']) ?>"></td>
                    <td><input class="cell" data-f="note" value="<?= e($s['note']) ?>"></td>
                    <td class="actions"><button class="icon-btn" onclick="delCatRow(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="tab-pane" data-tab="buses" hidden>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Автобусы</h2>
        <button class="btn sm" onclick="addCatRow('buses')"><?= icon('plus') ?> Добавить</button>
    </div>
    <div class="table-wrap mt">
        <table class="t cat" id="busesTable" data-kind="bus">
            <thead><tr><th style="width:70px">Код</th><th style="width:18%">Госномер</th><th style="width:20%">Марка/модель</th><th style="width:70px">Мест</th><th style="width:18%">Тел. водителя</th><th>Прим.</th><th style="width:120px">Фото</th><th style="width:40px"></th></tr></thead>
            <tbody>
            <?php foreach ($buses as $b): ?>
                <tr data-id="<?= $b['id'] ?>">
                    <td><input class="cell" data-f="code" value="<?= e($b['code']) ?>"></td>
                    <td><input class="cell" data-f="plate" value="<?= e($b['plate']) ?>"></td>
                    <td><input class="cell" data-f="model" value="<?= e($b['model']) ?>"></td>
                    <td><input class="cell" data-f="seats" value="<?= (int) $b['seats'] ?: '' ?>"></td>
                    <td><input class="cell" data-f="driver_phone" value="<?= e($b['driver_phone']) ?>"></td>
                    <td><input class="cell" data-f="note" value="<?= e($b['note']) ?>"></td>
                    <td class="bus-photo">
                        <?php if ($b['photo']): ?><a href="<?= e($b['photo']) ?>" target="_blank"><img src="<?= e($b['photo']) ?>" alt="фото"></a><?php endif; ?>
                        <label class="photo-up" title="Загрузить фото">📷<input type="file" accept="image/*" onchange="uploadBusPhoto(this)"></label>
                    </td>
                    <td class="actions"><button class="icon-btn" onclick="delCatRow(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small mt" style="margin-bottom:0">Код — короткий номер из ведомости (например «474»), по нему панель находит автобус и подставляет телефон водителя. Фото уходит пассажирам вместе с уведомлением, если включить в рассылке.</p>
</div>
</div>

<div class="tab-pane" data-tab="drivers" hidden>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Водители</h2>
        <button class="btn sm" onclick="addCatRow('drivers')"><?= icon('plus') ?> Добавить</button>
    </div>
    <div class="table-wrap mt">
        <table class="t cat" id="driversTable" data-kind="driver">
            <thead><tr><th style="width:30%">ФИО</th><th style="width:20%">Телефон</th><th style="width:25%">Автобус</th><th>Прим.</th><th style="width:40px"></th></tr></thead>
            <tbody>
            <?php foreach ($drivers as $d): ?>
                <tr data-id="<?= $d['id'] ?>">
                    <td><input class="cell" data-f="name" value="<?= e($d['name']) ?>"></td>
                    <td><input class="cell" data-f="phone" value="<?= e($d['phone']) ?>"></td>
                    <td>
                        <select class="cell" data-f="bus_id">
                            <option value="">—</option>
                            <?php foreach ($buses as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $d['bus_id'] == $b['id'] ? 'selected' : '' ?>><?= e($b['plate']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input class="cell" data-f="note" value="<?= e($d['note']) ?>"></td>
                    <td class="actions"><button class="icon-btn" onclick="delCatRow(this)"><?= icon('trash') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="tab-pane" data-tab="templates" hidden>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Шаблоны сообщений</h2>
        <button class="btn sm" onclick="addTpl()"><?= icon('plus') ?> Новый шаблон</button>
    </div>
    <p class="muted small">Подстановки: {имя} {откуда} {куда} {маршрут} {дата_рейса} {дата} {время} {место} {посадка} {карта} {автобус} {тел_водителя} {доп} + свои из вкладки «Переменные». Регистр и пробелы в скобках не важны, эмодзи поддерживаются 🚍</p>
    <div id="tplList">
        <?php foreach ($templates as $t): ?>
            <div class="tpl" data-id="<?= $t['id'] ?>">
                <div class="row" style="justify-content:space-between">
                    <input class="cell tpl-name" data-f="name" value="<?= e($t['name']) ?>" style="font-weight:600;max-width:320px">
                    <div class="row">
                        <span class="muted small tpl-state"></span>
                        <button class="btn ghost sm" onclick="saveTpl(this)">Сохранить</button>
                        <button class="icon-btn" onclick="delTpl(this)"><?= icon('trash') ?></button>
                    </div>
                </div>
                <textarea class="template-box" data-f="body" rows="6"><?= e($t['body']) ?></textarea>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div>
