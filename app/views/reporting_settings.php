<?php
/** @var array $agentContracts @var array $stations @var array $carriers @var array $scenarios @var int $scenarioId @var string $sourceUrl @var string $tab */
$pc = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$returnManifestId = (int) ($returnManifestId ?? 0);
$returnHref = $returnManifestId > 0 ? '/?p=report_trip&id=' . $returnManifestId : '';
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
        <td class="ta-r"><input type="number" step="0.01" min="0" class="report-a-field report-rate-field money-input" data-field="agent_commission_rate" value="<?= e(rtrim(rtrim((string)$c['agent_commission_rate'],'0'),'.')) ?>"></td>
        <td><button class="icon-btn" onclick="reportDeleteAgent(this)" title="Удалить">×</button></td>
    </tr>
    <?php
};
?>
<div class="page-head">
    <div><h1>Отчётность</h1><div class="sub">настройки расчёта — их владелец задаёт сам, автоматика ничего не подставляет</div></div>
    <div class="head-actions">
        <?php if ($returnHref !== ''): ?><a class="btn ghost" href="<?= e($returnHref) ?>" title="Изменения сохраняются автоматически">← К рейсу №<?= e((string) $returnManifestId) ?></a><?php endif; ?>
        <a class="btn ghost" href="/?p=reporting_help"><?= icon('doc') ?> Инструкция</a>
    </div>
</div>
<?php if ($returnHref !== ''): ?><div class="report-context-bar"><span>Настройки открыты из рейса №<?= e((string) $returnManifestId) ?>. Поля сохраняются автоматически.</span><a class="btn sm" href="<?= e($returnHref) ?>">Сохранить и вернуться</a></div><?php endif; ?>

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

<div class="card" id="scenarios">
    <h2>Сценарии расчёта</h2>
    <p class="muted small">Одну ведомость можно посчитать по-разному: у каждого сценария <b>свой</b> набор перевозчиков,
    агентов и процентов. Все настройки ниже относятся к <b>выбранному</b> сценарию. Новый создаётся копией текущего —
    назначения агентов в строках рейсов при этом сохраняются.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th style="width:40px"></th><th>Название</th><th>Агентов</th><th>Вокзалов</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($scenarios as $sc): $isCur = (int)$sc['id'] === $scenarioId; ?>
        <tr data-scid="<?= (int)$sc['id'] ?>"<?= $isCur ? ' style="background:var(--brand-50)"' : '' ?>>
            <td><?php if ($isCur): ?><span class="badge ok">текущий</span><?php else: ?><a class="btn ghost sm" href="/?p=reporting&tab=settings&scenario=<?= (int)$sc['id'] ?>">Выбрать</a><?php endif; ?></td>
            <td><input class="report-sc-field" data-field="name" value="<?= e($sc['name']) ?>"></td>
            <td class="muted"><?= (int) db()->query('SELECT COUNT(*) FROM report_agent_contracts WHERE scenario_id='.(int)$sc['id'])->fetchColumn() ?></td>
            <td class="muted"><?= (int) db()->query('SELECT COUNT(*) FROM report_stations WHERE scenario_id='.(int)$sc['id'])->fetchColumn() ?></td>
            <td><?php if (count($scenarios) > 1): ?><button class="icon-btn" onclick="reportDeleteScenario(this)" title="Удалить сценарий">×</button><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$scenarios): ?><tr><td colspan="5" class="muted">Сценариев нет — применена ли schema22?</td></tr><?php endif; ?>
    </tbody></table></div>
    <button class="btn ghost sm mt" onclick="reportCopyScenario(<?= $scenarioId ?>)">+ Новый сценарий (копия текущего)</button>
</div>

<div class="card">
    <h2>Перевозчики</h2>
    <p class="muted small">Ставки живут на перевозчике, но базы у них <b>разные</b>: диспетчерские берутся
    со всего <b>оборота рейса</b> (ведомость + автовокзалы), а наша комиссия — только с <b>продаж Терры</b>.
    Имя должно совпадать с полем ATP из ведомости.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Перевозчик</th><th class="ta-r">Диспетчерские, %</th><th class="ta-r">Наша комиссия, %</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($carriers as $c): ?>
        <tr data-carrier="<?= (int)$c['id'] ?>">
            <td><input class="report-c-field" data-field="name" value="<?= e($c['name']) ?>" placeholder="ИП Ванюк А.Н."></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-c-field report-rate-field money-input" data-field="disp_rate" value="<?= e($pc($c['disp_rate'])) ?>"></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-c-field report-rate-field money-input" data-field="our_rate" value="<?= e($pc($c['our_rate'])) ?>"></td>
            <td><button class="icon-btn" onclick="reportDeleteCarrier(this)" title="Удалить">×</button></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$carriers): ?><tr><td colspan="4" class="muted">Пусто. Без перевозчика расчёт возьмёт ставки по умолчанию — 7% и 15%.</td></tr><?php endif; ?>
    </tbody></table></div>
    <button class="btn ghost sm mt" onclick="reportAddCarrier(<?= $scenarioId ?>)">+ Добавить перевозчика</button>
</div>

<div class="card">
    <h2>Наши агенты — ТерраТрансКрым</h2>
    <p class="muted small">Продают наш ресурс. Процент — сколько агент забирает себе с проданного билета.
    Ищутся по автозаполненному полю «Агент/кассир» из ведомости.</p>
    <div class="table-wrap mt"><table class="t report-agent-table"><thead><tr><th>Название</th><th>Ключи поиска</th><th>Где искать</th><th class="ta-r">%</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($ours as $c) $agentRow($c); ?>
    <?php if (!$ours): ?><tr><td colspan="5" class="muted">Пусто. Без наших агентов расчёт не поймёт, какие продажи наши, и комиссия Терры не начислится.</td></tr><?php endif; ?>
    </tbody></table></div>
    <button class="btn ghost sm mt" onclick="reportAddAgent('ours', <?= $scenarioId ?>)">+ Добавить агента</button>
</div>

<div class="card">
    <h2>Агенты перевозчика</h2>
    <p class="muted small">Деньги за эти билеты <b>уже у перевозчика</b> — в наш долг они не входят. Процент нужен
    для его аналитики. Ищутся по <b>ручной пометке кассира</b> в комментарии — она сильнее поля «Агент/кассир».</p>
    <div class="table-wrap mt"><table class="t report-agent-table"><thead><tr><th>Название</th><th>Ключи поиска</th><th>Где искать</th><th class="ta-r">%</th><th style="width:34px"></th></tr></thead><tbody>
    <?php foreach ($carrierAgents as $c) $agentRow($c); ?>
    <?php if (!$carrierAgents): ?><tr><td colspan="5" class="muted">Пусто. Пометки вроде «Гоубас Ванюк» в комментарии не будут распознаны.</td></tr><?php endif; ?>
    </tbody></table></div>
    <button class="btn ghost sm mt" onclick="reportAddAgent('carrier', <?= $scenarioId ?>)">+ Добавить агента</button>
</div>

<div class="card">
    <h2>Автовокзалы</h2>
    <p class="muted small">Продают напрямую перевозчику, в посадочную ведомость <b>не попадают</b> — вносятся вручную
    суммой на карточке рейса. Их продажи входят в <b>оборот</b> (с него берутся диспетчерские), но в наш долг — нет.</p>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Название</th><th class="ta-r">%</th><th>Примечание</th><th>Продаж</th><th>Статус</th><th style="width:80px"></th></tr></thead><tbody>
    <?php foreach ($stations as $s): ?>
        <tr data-sid="<?= (int)$s['id'] ?>"<?= (int)$s['active'] ? '' : ' style="opacity:.55"' ?>>
            <td><input class="report-s-field" data-field="name" value="<?= e($s['name']) ?>"></td>
            <td class="ta-r"><input type="number" step="0.01" min="0" class="report-s-field report-rate-field money-input" data-field="rate" value="<?= e($pc($s['rate'])) ?>"></td>
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
    <button class="btn ghost sm mt" onclick="reportAddStation(<?= $scenarioId ?>)">+ Добавить автовокзал</button>
</div>

<div class="report-save-state muted small" id="reportAgentState">Изменения в таблицах сохраняются автоматически</div>
