<?php /** @var array $rows @var array $agentContracts @var array $stations */ ?>
<div class="page-head">
    <div><h1>Отчётность по рейсам</h1><div class="sub">Ведомость → сверка явки и возвратов → расчёт → файлы рейса</div></div>
    <div class="head-actions"><a class="btn ghost" href="/?p=reporting_help"><?= icon('doc') ?> Инструкция</a></div>
</div>

<?php if ($reportingError !== ''): ?><div class="alert err"><?= e($reportingError) ?></div><?php endif; ?>

<div class="report-upload card">
    <div>
        <h2>Добавить рейс в отчётность</h2>
        <p class="muted">Отчётность — <b>отдельная среда</b>: здесь только те рейсы, которые вы добавили сюда сами.
        Ведомости из «Уведомлений» тут не появляются и ничего не считается автоматически.</p>
    </div>
    <form method="post" class="report-upload-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_by_id">
        <label class="f">Номер рейса<input name="trip_id" inputmode="numeric" required placeholder="напр. 119" style="text-align:center;font-weight:650"></label>
        <button class="btn" type="submit"><?= icon('download') ?> Подтянуть и добавить</button>
    </form>
    <form method="post" enctype="multipart/form-data" class="report-upload-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import_csv">
        <label class="report-drop"><span>или CSV-файлом</span><input type="file" name="manifest" accept=".csv,text/csv" required></label>
        <button class="btn ghost" type="submit"><?= icon('upload') ?> Загрузить файл</button>
    </form>
</div>

<div class="card">
    <div class="row report-card-head"><h2>Рейсы</h2><span class="badge muted"><?= count($rows) ?></span></div>
    <div class="table-wrap">
        <table class="t report-list"><thead><tr><th>ID</th><th>Дата и маршрут</th><th>Перевозчик</th><th>Пассажиры</th><th>Файлы</th><th>Статус</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $m): ?>
            <tr>
                <td><strong><?= e($m['trip_number']) ?></strong></td>
                <td><?= $m['departure_at'] ? e(date('d.m.Y H:i',strtotime($m['departure_at']))) : '—' ?><div class="small muted"><?= e($m['route']) ?></div></td>
                <td><?= e($m['carrier']) ?></td><td><?= (int) $m['passenger_count'] ?></td><td><?= (int) $m['file_count'] ?></td>
                <td><span class="badge <?= $m['reporting_status']==='calculated' ? 'ok' : 'muted' ?>"><?= $m['calculation_version'] ? 'Расчёт v'.(int)$m['calculation_version'] : 'Черновик' ?></span></td>
                <td class="row" style="gap:6px"><a class="btn sm" href="/?p=report_trip&id=<?= (int) $m['id'] ?>">Открыть</a>
                    <form method="post" onsubmit="return confirm('Убрать рейс из отчётности? Сам рейс и уведомления не тронутся.')" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="detach">
                        <input type="hidden" name="manifest_id" value="<?= (int) $m['id'] ?>">
                        <button class="btn ghost sm" type="submit" title="Убрать из отчётности (рейс останется в уведомлениях)">✕</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Пока пусто. Добавьте рейс по номеру — он появится здесь.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<details class="card report-agents" id="agents">
    <summary><strong>Справочник агентов и договоров</strong> <span class="muted">— <?= count($agentContracts) ?> условий</span></summary>
    <p class="muted small mt">Всё редактируется прямо в таблице — изменения сохраняются сразу. <b>Алиасы</b> — по ним агент
    узнаётся в ведомости (через <code>|</code>: <code>толкачев|толкачёв</code>). <b>Где искать</b>: «Поле» — автозаполненная
    колонка «Агент/кассир»; «Комментарий» — ручная пометка кассира, она <b>сильнее</b> поля (так помечают агентов перевозчика).</p>
    <div class="table-wrap mt"><table class="t report-agent-table"><thead><tr><th>Агент</th><th>Алиасы</th><th>Где искать</th><th>Деньги получает</th><th>Комиссия агента</th><th>Договор / перевозчик</th><th></th></tr></thead><tbody>
    <?php foreach ($agentContracts as $c): ?>
        <tr data-cid="<?= (int)$c['id'] ?>" class="<?= (int)($c['active'] ?? 1) ? '' : 'row-off' ?>">
            <td><input class="report-a-field" data-field="name" value="<?= e($c['agent_name']) ?>"></td>
            <td><input class="report-a-field" data-field="aliases" value="<?= e($c['aliases']) ?>" placeholder="толкачев|толкачёв"></td>
            <td><select class="report-a-field" data-field="match_src">
                <option value="" <?= ($c['match_src'] ?? null) === null ? 'selected' : '' ?>>авто</option>
                <option value="raw" <?= ($c['match_src'] ?? '') === 'raw' ? 'selected' : '' ?>>Поле</option>
                <option value="comment" <?= ($c['match_src'] ?? '') === 'comment' ? 'selected' : '' ?>>Комментарий</option>
                <option value="both" <?= ($c['match_src'] ?? '') === 'both' ? 'selected' : '' ?>>Оба</option>
            </select></td>
            <td><select class="report-a-field" data-field="settlement_side">
                <option value="ours" <?= $c['settlement_side']==='ours'?'selected':'' ?>>Мы (Терра)</option>
                <option value="carrier" <?= $c['settlement_side']==='carrier'?'selected':'' ?>>Перевозчик</option>
            </select></td>
            <td><input type="number" step="0.01" min="0" class="report-a-field money-input" data-field="agent_commission_rate" value="<?= e(rtrim(rtrim((string)$c['agent_commission_rate'],'0'),'.')) ?>" style="max-width:80px">%</td>
            <td><input class="report-a-field" data-field="title" value="<?= e($c['title']) ?>" placeholder="Основной договор"><input class="report-a-field small-input" data-field="carrier" value="<?= e($c['carrier']) ?>" placeholder="перевозчик (для прямых)"></td>
            <td><button class="icon-btn" onclick="reportDeleteAgent(this)" title="Удалить условие"><?= icon('trash') ?></button></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$agentContracts): ?><tr><td colspan="7" class="muted">Пусто. Добавьте агентов ниже — без них расчёт не отличит наши продажи от продаж перевозчика.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="report-save-state muted small" id="reportAgentState">Изменения сохраняются автоматически</div>
    <form method="post" class="report-agent-form mt">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_agent_contract">
        <label>Агент<input name="agent_name" required placeholder="GoBus"></label>
        <label>Псевдонимы из CSV<input name="aliases" placeholder="GoBus Ванюк, Гоу бас"></label>
        <label>Название договора<input name="contract_title" value="Основной договор"></label>
        <label>Сторона договора<select name="settlement_side"><option value="ours">С нами</option><option value="carrier">С перевозчиком</option></select></label>
        <label>Перевозчик<input name="carrier" placeholder="ИП Ванюк А.Н."></label>
        <label>Комиссия агента, %<input type="number" step="0.01" min="0" name="agent_commission_rate" value="0"></label>
        <label>Коммерческая, %<input type="number" step="0.01" min="0" name="commercial_rate" value="15"></label>
        <label>Диспетчерская, %<input type="number" step="0.01" min="0" name="dispatch_rate" value="7"></label>
        <label>Расчёт диспетчерской<select name="dispatch_settlement"><option value="offset">Взаимозачёт</option><option value="receivable">Отдельная задолженность</option></select></label>
        <button class="btn" type="submit">Добавить договор</button>
    </form>
</details>

<details class="card report-agents" id="stations">
    <summary><strong>Справочник автовокзалов</strong> <span class="muted">— <?= count($stations) ?> шт.</span></summary>
    <p class="muted small mt">Автовокзалы продают <b>напрямую перевозчику</b>, в посадочную ведомость не попадают — их продажи вносятся суммой на карточке рейса. Процент хранится здесь: если его поменять, все рейсы пересчитаются по новому.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Автовокзал</th><th>Процент</th><th>Примечание</th><th>Продаж внесено</th><th>Статус</th><th></th></tr></thead><tbody>
    <?php foreach ($stations as $s): ?>
        <tr data-sid="<?= (int)$s['id'] ?>"<?= (int)$s['active'] ? '' : ' style="opacity:.55"' ?>>
            <td><input class="report-s-field" data-field="name" value="<?= e($s['name']) ?>"></td>
            <td><input type="number" step="0.01" min="0" class="report-s-field money-input" data-field="rate" value="<?= e(rtrim(rtrim(number_format((float)$s['rate'], 2, '.', ''), '0'), '.')) ?>" style="max-width:80px">%</td>
            <td><input class="report-s-field" data-field="note" value="<?= e($s['note']) ?>" placeholder="напр. договор от 2026"></td>
            <td><?= (int) $s['sales_count'] ?></td>
            <td><span class="badge <?= (int)$s['active'] ? 'ok' : 'muted' ?>"><?= (int)$s['active'] ? 'активен' : 'скрыт' ?></span></td>
            <td class="row" style="gap:6px">
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_station">
                    <input type="hidden" name="station_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn ghost sm" type="submit" title="Скрытый вокзал не предлагается при вводе продаж, но прошлые рейсы не меняются"><?= (int)$s['active'] ? 'Скрыть' : 'Вернуть' ?></button>
                </form>
                <?php if (!(int) $s['sales_count']): ?><button class="icon-btn" onclick="reportDeleteStation(this)" title="Удалить (продаж по нему ещё нет)"><?= icon('trash') ?></button><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$stations): ?><tr><td colspan="6" class="muted">Пока пусто. Добавьте первый автовокзал — он появится в выборе на карточке рейса.</td></tr><?php endif; ?>
    </tbody></table></div>
    <form method="post" class="report-agent-form mt">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_station">
        <label>Автовокзал<input name="station_name" required placeholder="МГТ"></label>
        <label>Процент, %<input type="number" step="0.01" min="0" name="station_rate" value="0" required></label>
        <label>Примечание<input name="station_note" placeholder="напр. договор от 2026"></label>
        <button class="btn" type="submit">Сохранить автовокзал</button>
    </form>
</details>
