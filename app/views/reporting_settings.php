<?php
/** @var array $agentContracts @var array $stations @var array $carriers @var string $sourceUrl @var string $tab */
$pc = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$ours = array_values(array_filter($agentContracts, fn($c) => $c['settlement_side'] === 'ours'));
$carrierAgents = array_values(array_filter($agentContracts, fn($c) => $c['settlement_side'] === 'carrier'));

// Строка справочника агентов: название · ключи поиска · где искать · % · удалить
$agentRow = static function (array $c): void {
    $srcVal = (string) ($c['match_src'] ?? '');
    ?>
    <tr data-cid="<?= (int)$c['id'] ?>">
        <td><input class="report-a-field" data-field="name" value="<?= e($c['agent_name']) ?>"></td>
        <td><input class="report-a-field" data-field="aliases" value="<?= e($c['aliases']) ?>" placeholder="толкачев|толкачёв"></td>
        <td><select class="report-a-field" data-field="match_src">
            <option value="" <?= $srcVal === '' ? 'selected' : '' ?>>авто</option>
            <option value="raw" <?= $srcVal === 'raw' ? 'selected' : '' ?>>Агент/кассир</option>
            <option value="comment" <?= $srcVal === 'comment' ? 'selected' : '' ?>>Комментарий</option>
            <option value="both" <?= $srcVal === 'both' ? 'selected' : '' ?>>Везде</option>
        </select></td>
        <td class="ta-r"><input type="number" step="0.01" min="0" class="report-a-field money-input" data-field="agent_commission_rate" value="<?= e(rtrim(rtrim((string)$c['agent_commission_rate'],'0'),'.')) ?>" style="max-width:74px"></td>
        <td><button class="icon-btn" onclick="reportDeleteAgent(this)" title="Удалить">×</button></td>
    </tr>
    <?php
};
?>
<div class="page-head">
    <div><h1>Отчётность</h1><div class="sub">настройки расчёта — их владелец задаёт сам, автоматика ничего не подставляет</div></div>
    <div class="head-actions"><a class="btn ghost" href="/?p=reporting_help"><?= icon('doc') ?> Инструкция</a></div>
</div>

<div class="report-tabs">
    <a href="/?p=reporting">Рейсы</a>
    <a href="/?p=reporting&tab=analytics">Аналитика</a>
    <a class="active" href="/?p=reporting&tab=settings">Настройки</a>
</div>

<?php if ($reportingError !== ''): ?><div class="alert err"><?= e($reportingError) ?></div><?php endif; ?>

<div class="card">
    <h2>Источник ведомостей</h2>
    <p class="muted small">Ссылка, по которой скачивается CSV. Вместо номера рейса подставьте <code>{id}</code>.</p>
    <div class="row mt" style="gap:8px">
        <input id="srcUrlInput" value="<?= e($sourceUrl) ?>" style="flex:1;font-family:ui-monospace,monospace;font-size:12px">
        <button class="btn sm" onclick="reportSaveSourceUrl()">Сохранить</button>
    </div>
    <div class="small muted mt" id="srcUrlState"></div>
</div>

<div class="card">
    <h2>Сценарии расчёта</h2>
    <p class="muted small">Одну ведомость можно посчитать по-разному: свой набор перевозчиков, агентов и процентов.
    Сейчас набор <b>один</b> — все настройки ниже относятся к нему. Переключение и копирование сценариев — следующий шаг.</p>
</div>

<div class="card">
    <h2>Перевозчики</h2>
    <p class="muted small">Ставки живут на перевозчике, но базы у них <b>разные</b>: диспетчерские берутся
    со всего <b>оборота рейса</b> (ведомость + автовокзалы), а наша комиссия — только с <b>продаж Терры</b>.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Перевозчик</th><th class="ta-r">Диспетчерские, %</th><th class="ta-r">Наша комиссия, %</th></tr></thead><tbody>
    <?php foreach ($carriers as $c): ?>
        <tr data-carrier="<?= (int)$c['id'] ?>">
            <td><?= e($c['atp'] ?: '— без названия —') ?></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-c-field money-input" data-field="disp_rate" value="<?= e($pc($c['disp_rate'] ?? 7)) ?>" style="max-width:74px"></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-c-field money-input" data-field="our_rate" value="<?= e($pc($c['our_rate'] ?? 15)) ?>" style="max-width:74px"></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$carriers): ?><tr><td colspan="3" class="muted">Перевозчиков нет — заведите их в <a href="/?p=catalogs">Справочниках</a>.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="muted small mt">Список перевозчиков ведётся в <a href="/?p=catalogs">Справочниках</a> (он же используется в документах). Здесь задаются только ставки.</p>
</div>

<div class="card">
    <h2>Наши агенты — ТерраТрансКрым</h2>
    <p class="muted small">Продают наш ресурс. Процент — сколько агент забирает себе с проданного билета.
    Ищутся по автозаполненному полю «Агент/кассир» из ведомости.</p>
    <div class="table-wrap mt"><table class="t report-agent-table"><thead><tr><th>Название</th><th>Ключи поиска</th><th>Где искать</th><th class="ta-r">%</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($ours as $c) $agentRow($c); ?>
    <?php if (!$ours): ?><tr><td colspan="5" class="muted">Пусто. Без наших агентов расчёт не поймёт, какие продажи наши, и комиссия Терры не начислится.</td></tr><?php endif; ?>
    </tbody></table></div>
    <form method="post" class="row mt" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_agent_contract">
        <input type="hidden" name="settlement_side" value="ours"><input type="hidden" name="contract_title" value="Основной договор">
        <label class="f">Название<input name="agent_name" required placeholder="ИП Толкачёв А.П."></label>
        <label class="f">Ключи поиска<input name="aliases" placeholder="толкачев|толкачёв"></label>
        <label class="f">%<input type="number" step="0.01" min="0" name="agent_commission_rate" value="0" style="max-width:80px"></label>
        <button class="btn sm" type="submit">+ Добавить агента</button>
    </form>
</div>

<div class="card">
    <h2>Агенты перевозчика</h2>
    <p class="muted small">Деньги за эти билеты <b>уже у перевозчика</b> — в наш долг они не входят. Процент нужен
    для его аналитики. Ищутся по <b>ручной пометке кассира</b> в комментарии — она сильнее поля «Агент/кассир».</p>
    <div class="table-wrap mt"><table class="t report-agent-table"><thead><tr><th>Название</th><th>Ключи поиска</th><th>Где искать</th><th class="ta-r">%</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($carrierAgents as $c) $agentRow($c); ?>
    <?php if (!$carrierAgents): ?><tr><td colspan="5" class="muted">Пусто. Пометки вроде «Гоубас Ванюк» в комментарии не будут распознаны.</td></tr><?php endif; ?>
    </tbody></table></div>
    <form method="post" class="row mt" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_agent_contract">
        <input type="hidden" name="settlement_side" value="carrier"><input type="hidden" name="contract_title" value="Основной договор">
        <label class="f">Название<input name="agent_name" required placeholder="GoBus Ванюк"></label>
        <label class="f">Ключи поиска<input name="aliases" placeholder="гоубас|гоу бас|gobus"></label>
        <label class="f">%<input type="number" step="0.01" min="0" name="agent_commission_rate" value="0" style="max-width:80px"></label>
        <button class="btn sm" type="submit">+ Добавить агента</button>
    </form>
</div>

<div class="card">
    <h2>Автовокзалы</h2>
    <p class="muted small">Продают напрямую перевозчику, в посадочную ведомость <b>не попадают</b> — вносятся вручную
    суммой на карточке рейса. Их продажи входят в <b>оборот</b> (с него берутся диспетчерские), но в наш долг — нет.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Название</th><th class="ta-r">%</th><th>Примечание</th><th>Продаж</th><th>Статус</th><th style="width:80px"></th></tr></thead><tbody>
    <?php foreach ($stations as $s): ?>
        <tr data-sid="<?= (int)$s['id'] ?>"<?= (int)$s['active'] ? '' : ' style="opacity:.55"' ?>>
            <td><input class="report-s-field" data-field="name" value="<?= e($s['name']) ?>"></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-s-field money-input" data-field="rate" value="<?= e($pc($s['rate'])) ?>" style="max-width:74px"></td>
            <td><input class="report-s-field" data-field="note" value="<?= e($s['note']) ?>" placeholder="напр. договор от 2026"></td>
            <td><?= (int) $s['sales_count'] ?></td>
            <td><span class="badge <?= (int)$s['active'] ? 'ok' : 'muted' ?>"><?= (int)$s['active'] ? 'активен' : 'скрыт' ?></span></td>
            <td class="row" style="gap:4px">
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_station">
                    <input type="hidden" name="station_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn ghost sm" type="submit"><?= (int)$s['active'] ? 'Скрыть' : 'Вернуть' ?></button>
                </form>
                <?php if (!(int) $s['sales_count']): ?><button class="icon-btn" onclick="reportDeleteStation(this)" title="Удалить">×</button><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$stations): ?><tr><td colspan="6" class="muted">Пусто. Заведите вокзалы — тогда их можно будет выбрать при вводе продаж на рейсе.</td></tr><?php endif; ?>
    </tbody></table></div>
    <form method="post" class="row mt" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_station">
        <label class="f">Название<input name="station_name" required placeholder="МГТ"></label>
        <label class="f">%<input type="number" step="0.01" min="0" name="station_rate" value="0" required style="max-width:80px"></label>
        <label class="f">Примечание<input name="station_note" placeholder="необязательно"></label>
        <button class="btn sm" type="submit">+ Добавить автовокзал</button>
    </form>
</div>

<div class="report-save-state muted small" id="reportAgentState">Изменения в таблицах сохраняются автоматически</div>
