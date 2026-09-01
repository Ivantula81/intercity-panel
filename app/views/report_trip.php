<?php
/** @var array $manifest @var array $passengers @var array $contracts @var ?array $calculation */
// Расчёт НЕ выполняется автоматически: либо пришёл из снимка, либо по кнопке (?calc=1).
$hasCalc = is_array($calculation) && !empty($calculation['totals']);
$t = $hasCalc ? $calculation['totals'] : [];
$money = static fn($v) => number_format((float) $v, 0, ',', ' ') . ' ₽';
$num = static fn($k, $d = 0) => (float) ($t[$k] ?? $d);
$isFiles = $activeTab === 'files';
?>
<div class="page-head report-trip-head">
    <div><a class="small muted" href="/?p=reporting">← Все рейсы</a><h1>Рейс № <?= e($manifest['trip_number']) ?></h1><div class="sub"><?= e($manifest['route']) ?></div></div>
    <div class="head-actions">
    <?php if (!empty($scenarioList) && count($scenarioList) > 0): ?>
        <label class="row small muted" style="gap:6px;margin:0" title="Набор настроек: свои перевозчики, агенты и проценты">Сценарий
            <select id="tripScenario" onchange="reportApplyScenario(<?= (int)$manifest['id'] ?>, this.value)" style="max-width:170px">
                <?php foreach ($scenarioList as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>" <?= (int)$sc['id'] === (int)($tripScenarioId ?? 0) ? 'selected' : '' ?>><?= e($sc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <a class="btn ghost" href="/?p=reporting&tab=settings&scenario=<?= (int)($tripScenarioId ?? 0) ?>&return_manifest=<?= (int)$manifest['id'] ?>" title="Агенты, алиасы, перевозчики и ставки"><?= icon('settings') ?> Настройки</a><a class="btn ghost" href="/?p=reporting_help" title="Инструкция"><?= icon('doc') ?></a><button class="btn ghost" type="button" onclick="reportAddCash()">+ Внести наличные</button>
    <a class="btn<?= $hasCalc ? ' ghost' : '' ?>" href="/?p=report_trip&id=<?= (int)$manifest['id'] ?>&calc=1"><?= $hasCalc ? '↻ Пересчитать' : 'Рассчитать' ?></a>
    <?php if ($hasCalc): ?><button class="btn" type="button" onclick="reportSaveSnapshot(<?= (int)$manifest['id'] ?>)">Сохранить расчёт</button><?php endif; ?></div>
</div>
<script>window.REPORT_MANIFEST_ID=<?= (int)$manifest['id'] ?>;</script>

<dialog class="report-dialog" id="reportCashDialog">
    <form method="dialog" onsubmit="return reportSubmitCash(event)">
        <div class="row report-card-head"><h2>Внести наличные</h2><button class="icon-btn" type="button" onclick="this.closest('dialog').close()">×</button></div>
        <label>Сумма, ₽<input type="number" min="0.01" step="0.01" name="amount" required autofocus></label>
        <label>У кого находятся деньги<select name="recipient"><option value="us">У нас</option><option value="carrier">У перевозчика</option><option value="agent">У агента</option></select></label>
        <label>Комментарий<input name="note" placeholder="Например: принял водитель"></label>
        <div class="head-actions mt"><button class="btn ghost" type="button" onclick="this.closest('dialog').close()">Отмена</button><button class="btn" type="submit">Сохранить</button></div>
    </form>
</dialog>

<div class="report-tabs">
    <a class="<?= !$isFiles?'active':'' ?>" href="/?p=report_trip&id=<?= (int)$manifest['id'] ?>">Первичный расчёт</a>
    <a class="<?= $isFiles?'active':'' ?>" href="/?p=report_trip&id=<?= (int)$manifest['id'] ?>&tab=files">Файлы рейса <span class="badge muted"><?= count($files) ?></span></a>
</div>

<?php if ($isFiles): ?>
<div class="report-file-grid">
    <div class="card">
        <h2>Добавить рабочую ведомость</h2>
        <p class="muted">Фото, скан или файл с ручными пометками водителя сохранятся у рейса ID <?= e($manifest['trip_number']) ?>.</p>
        <form method="post" enctype="multipart/form-data" action="/?p=reporting" class="report-file-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="upload_working"><input type="hidden" name="manifest_id" value="<?= (int)$manifest['id'] ?>">
            <input type="file" name="working_file" accept=".csv,.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" required>
            <input name="note" placeholder="Комментарий к версии"><button class="btn" type="submit"><?= icon('upload') ?> Загрузить</button>
        </form>
    </div>
    <div class="card"><h2>Карточка рейса</h2><div class="report-facts compact">
        <label>ID<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="trip_number" value="<?= e($manifest['trip_number']) ?>"></label>
        <label>Маршрут<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="route" value="<?= e($manifest['route']) ?>"></label>
        <label>Перевозчик<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="carrier" value="<?= e($manifest['carrier']) ?>"></label>
    </div></div>
</div>
<div class="card"><h2>Все файлы рейса</h2><div class="report-files">
<?php $typeNames=['source_csv'=>'Исходная CSV','working_manifest'=>'Рабочая ведомость','driver_document'=>'Для водителя','carrier_document'=>'Для перевозчика','report'=>'Отчёт','other'=>'Другой файл']; ?>
<?php foreach ($files as $f): ?><a class="report-file" href="/?p=report_file&id=<?= (int)$f['id'] ?>"><span class="report-file-ic"><?= icon('doc') ?></span><span><strong><?= e($f['original_name']) ?></strong><small><?= e($typeNames[$f['file_type']] ?? $f['file_type']) ?> · версия <?= (int)$f['version'] ?> · <?= e(date('d.m.Y H:i',strtotime($f['created_at']))) ?></small><?php if($f['note']!==''):?><small><?= e($f['note']) ?></small><?php endif;?></span><span><?= icon('download') ?></span></a><?php endforeach; ?>
<?php if(!$files): ?><p class="muted">Файлов пока нет.</p><?php endif; ?>
</div></div>
<?php else: ?>

<div class="card report-trip-card"><div class="report-facts">
    <label>ID ведомости<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="trip_number" value="<?= e($manifest['trip_number']) ?>"></label>
    <label>Дата рейса<input type="datetime-local" class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="departure_at" value="<?= $manifest['departure_at'] ? e(date('Y-m-d\\TH:i',strtotime($manifest['departure_at']))) : '' ?>"></label>
    <label>Маршрут<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="route" value="<?= e($manifest['route']) ?>"></label>
    <label>Перевозчик<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="carrier" value="<?= e($manifest['carrier']) ?>"></label>
    <label>Автобус<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="bus" value="<?= e($manifest['bus']) ?>"></label>
    <label>Водители<input class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="drivers" value="<?= e($manifest['drivers']) ?>"></label>
</div></div>

<?php if (!$hasCalc): ?>
<div class="alert warn">
    <b>Расчёт не выполнен.</b> Отчётность — отдельная среда: цифры не считаются сами, чтобы не подставлять
    случайные проценты. Сначала настройте <a href="/?p=reporting#agents">агентов с алиасами и процентами</a>
    и <a href="/?p=reporting#stations">автовокзалы</a>, отметьте явку и цены, затем нажмите «Рассчитать».
</div>
<?php endif; ?>

<div class="report-metrics" id="reportMetrics">
    <div><span>По ведомости</span><strong data-total="manifest_total"><?= $money($num('manifest_total')) ?></strong></div>
    <div><span>Наши продажи</span><strong data-total="our_sales"><?= $money($num('our_sales')) ?></strong></div>
    <div><span>Продажи перевозчика</span><strong data-total="carrier_direct_sales"><?= $money($num('carrier_direct_sales')) ?></strong></div>
    <div><span>К выплате перевозчику</span><strong data-total="carrier_due"><?= $money($num('carrier_due')) ?></strong></div>
    <div class="accent"><span>Наша рентабельность</span><strong data-total="profit"><?= $money($num('profit')) ?></strong></div>
</div>

<div class="card report-passengers">
    <div class="row report-card-head"><div><h2>Пассажиры <span class="badge muted" id="reportPassengerCount"><?= count($passengers) ?></span></h2><div class="small muted">Неизвестная явка считается предварительно как поездка. Перед финальным расчётом отметьте всех.</div></div><div class="row report-passenger-actions"><button class="btn ghost sm" onclick="reportRematchAgents(<?= (int)$manifest['id'] ?>)" title="Пересобрать назначения по комментариям и полю «Агент/кассир». Перезапишет ручные назначения.">Подставить агентов по совпадению</button><button class="btn sm" onclick="reportAddPassenger(<?= (int)$manifest['id'] ?>)"><?= icon('plus') ?> Добавить пассажира</button></div></div>
    <div class="table-wrap"><table class="t report-passenger-table"><thead><tr>
        <th style="width:56px">Место</th><th>Пассажир</th>
        <th style="width:210px">Откуда → куда</th><th style="width:220px">Агент / кассир</th>
        <th style="width:96px" class="ta-r">Ведомость</th><th style="width:96px" class="ta-r">Наша</th>
        <th style="width:170px">Комментарий</th><th style="width:160px">Неявка</th><th style="width:34px"></th>
    </tr></thead><tbody>
    <?php foreach ($passengers as $p):
        $noshow = $p['attendance'] === 'absent';
        $side = '';
        foreach ($contracts as $c) if ((int) $p['agent_contract_id'] === (int) $c['id']) $side = $c['settlement_side'];
        $rowCls = $p['refund_status'] === 'completed' ? 'row-refund' : ($noshow ? 'row-noshow'
            : ($side === 'carrier' ? 'row-carrier' : ($side === 'ours' ? 'row-ours' : 'row-none')));
    ?><tr data-id="<?= (int)$p['id'] ?>" class="<?= $rowCls ?>">
        <td><input class="report-p-field seat-input" data-field="seat" value="<?= e($p['seat']) ?>"></td>
        <td><input class="report-p-field" data-field="name" value="<?= e($p['name']) ?>"><?php if($p['birthdate']!==''):?><div class="small muted"><?= e($p['birthdate']) ?></div><?php endif;?></td>
        <td class="small muted"><?= e($p['from_stop']) ?><?php if($p['to_stop']!==''):?> → <?= e($p['to_stop']) ?><?php endif;?></td>
        <td><select class="report-p-field" data-field="agent_contract_id"><option value="">— не назначен —</option><?php foreach($contracts as $c):?><option value="<?= (int)$c['id'] ?>" <?= (int)$p['agent_contract_id']===(int)$c['id']?'selected':'' ?>><?= e($c['agent_name'].' · '.($c['settlement_side']==='ours'?'наш':'перев.')) ?></option><?php endforeach;?></select>
            <?php if(trim((string)$p['agent_raw'])!==''):?><div class="small muted">в ведомости: <i><?= e($p['agent_raw']) ?></i></div><?php endif;?></td>
        <td><input type="number" step="0.01" class="report-p-field money-input" data-field="manifest_price" value="<?= e($p['manifest_price'] ?? $p['price']) ?>"></td>
        <td><input type="number" step="0.01" class="report-p-field money-input" data-field="our_price" value="<?= e($p['our_price']) ?>" placeholder="= вед."></td>
        <?php /* Тот самый комментарий, что участвует в матчинге: впишите «Гоубас Ванюк» —
                и строка уйдёт агенту перевозчика, комментарий сильнее поля «Агент/кассир». */ ?>
        <td><input class="report-p-field" data-field="pay_note" value="<?= e($p['pay_note'] ?? '') ?>" placeholder="напр. Гоубас Ванюк" title="Пометка кассира. По ней назначается агент перевозчика — она сильнее поля «Агент/кассир»."></td>
        <td class="p-noshow">
            <label><input type="checkbox" class="report-p-field p-noshow-cb" data-field="noshow" <?= $noshow?'checked':'' ?>><span class="sr-only">неявка</span></label>
            <select class="report-p-field p-refund" data-field="refund_status" <?= $noshow?'':'hidden' ?>>
                <option value="none" <?= $p['refund_status']!=='completed'?'selected':'' ?>>без возврата</option>
                <option value="completed" <?= $p['refund_status']==='completed'?'selected':'' ?>>с возвратом</option>
            </select>
        </td>
        <td><button class="icon-btn" onclick="reportDeletePassenger(this)" title="Удалить"><?= icon('trash') ?></button></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="report-save-state muted small" id="reportSaveState">Все изменения сохранены</div>
</div>

<div class="card report-stations">
    <div class="row report-card-head">
        <div><h2>Продажи автовокзалов</h2><div class="small muted">Мимо ведомости, деньги напрямую перевозчику. Входят в <b>оборот рейса</b> — с них берутся диспетчерские; в долг Терры перевозчику <b>не</b> входят.</div></div>
        <a class="btn ghost sm" href="/?p=reporting#stations">Справочник →</a>
    </div>
    <?php $stTotal = 0.0; $stComm = 0.0; foreach ($stationSales as $s) { $stTotal += (float)$s['amount']; $stComm += round((float)$s['amount'] * (float)$s['rate'] / 100, 2); } ?>
    <div class="table-wrap"><table class="t" id="reportStationTable"><thead><tr><th>Автовокзал</th><th class="ta-r">Продажи</th><th class="ta-r">%</th><th class="ta-r">Комиссия</th><th class="ta-r">К перечислению</th><th></th></tr></thead><tbody>
    <?php foreach ($stationSales as $s): $comm = round((float)$s['amount'] * (float)$s['rate'] / 100, 2); ?>
        <tr data-id="<?= (int)$s['id'] ?>">
            <td><strong><?= e($s['name']) ?></strong><?php if($s['note']!==''):?><div class="small muted"><?= e($s['note']) ?></div><?php endif;?></td>
            <td class="ta-r money-cell"><?= $money($s['amount']) ?></td>
            <td class="ta-r muted"><?= e(rtrim(rtrim(number_format((float)$s['rate'], 2, '.', ''), '0'), '.')) ?>%</td>
            <td class="ta-r money-cell"><?= $money($comm) ?></td>
            <td class="ta-r money-cell"><strong><?= $money((float)$s['amount'] - $comm) ?></strong></td>
            <td><button class="icon-btn" onclick="reportDeleteStationSale(this)" title="Удалить"><?= icon('trash') ?></button></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($stationSales): ?>
        <tr class="report-station-total"><td><strong>Итого автовокзалы</strong></td><td class="ta-r"><strong><?= $money($stTotal) ?></strong></td><td></td><td class="ta-r"><strong><?= $money($stComm) ?></strong></td><td class="ta-r"><strong><?= $money($stTotal - $stComm) ?></strong></td><td></td></tr>
    <?php else: ?>
        <tr><td colspan="6" class="muted">Продаж автовокзалов нет — оборот рейса равен ведомости.</td></tr>
    <?php endif; ?>
    </tbody></table></div>
    <?php if ($stationList): ?>
    <div class="row mt" style="gap:8px;flex-wrap:wrap;align-items:center">
        <select id="stationPick" style="max-width:230px">
            <option value="">— выбрать автовокзал —</option>
            <?php foreach ($stationList as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> · <?= e(rtrim(rtrim(number_format((float)$s['rate'], 2, '.', ''), '0'), '.')) ?>%</option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" min="0" id="stationAmount" class="money-input" placeholder="Сумма, ₽" style="max-width:150px">
        <button class="btn sm" onclick="reportAddStationSale(<?= (int)$manifest['id'] ?>)"><?= icon('plus') ?> Добавить</button>
    </div>
    <?php else: ?>
        <p class="muted small mt">Справочник автовокзалов пуст — <a href="/?p=reporting#stations">заведите первый</a>, тогда его можно будет выбрать здесь.</p>
    <?php endif; ?>
</div>

<div class="report-bottom-grid">
    <div class="card"><h2>Расшифровка</h2><div class="report-breakdown">
        <div><span>Ведомость (по ехавшим)</span><strong data-total="manifest_total"><?= $money($num('manifest_total')) ?></strong></div>
        <?php if ($num('stations_total', 0) > 0): ?>
        <div><span>+ продажи автовокзалов</span><strong data-total="stations_total"><?= $money($num('stations_total')) ?></strong></div>
        <div><span><b>= Оборот рейса</b></span><strong data-total="turnover"><?= $money($num('turnover')) ?></strong></div>
        <?php endif; ?>
        <?php $pc = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
        <div><span>Диспетчеризация Терры <?= $pc($num('disp_rate', 7)) ?>% <span class="muted small">(с <?= $num('stations_total', 0) > 0 ? 'оборота' : 'ведомости' ?>)</span></span><strong data-total="dispatch_fee"><?= $money($num('dispatch_fee')) ?></strong></div>
        <div><span>Комиссия Терры <?= $pc($num('our_rate', 15)) ?>% <span class="muted small">(с наших продаж <?= $money($num('our_sales')) ?>)</span></span><strong data-total="our_commission"><?= $money($num('our_commission')) ?></strong></div>
        <div><span>Комиссии агентов перевозчика</span><strong data-total="carrier_agent_cost"><?= $money($num('carrier_agent_cost')) ?></strong></div>
        <?php if ($num('station_agent_cost', 0) > 0): ?>
        <div><span>Комиссии автовокзалов</span><strong data-total="station_agent_cost"><?= $money($num('station_agent_cost')) ?></strong></div>
        <?php endif; ?>
        <div><span>Комиссии наших агентов</span><strong data-total="our_agent_ride_cost"><?= $money($num('our_agent_ride_cost')) ?></strong></div>
        <?php if ($num('extra', 0) != 0): ?>
        <div><span>Разница цен <span class="muted small">(наша цена выше ведомости)</span></span><strong data-total="extra"><?= $money($num('extra')) ?></strong></div>
        <?php endif; ?>
        <?php if ($num('noshow_income', 0) != 0): ?>
        <div><span>Доход с неявок <span class="muted small">(<?= (int)$num('noshow_count', 0) ?> шт., без возврата)</span></span><strong data-total="noshow_income"><?= $money($num('noshow_income')) ?></strong></div>
        <?php endif; ?>
        <?php if ($num('cash', 0) != 0): ?>
        <div><span>Наличные <span class="muted small">(уменьшают долг Терры, не доход перевозчика)</span></span><strong data-total="cash"><?= $money($num('cash')) ?></strong></div>
        <?php endif; ?>
    </div></div>
    <div class="card"><h2>Контроль</h2><div id="reportWarnings"><?php foreach(array_slice($calculation['warnings'] ?? [],0,8) as $w):?><div class="report-warning">⚠ <?= e($w) ?></div><?php endforeach;?><?php if(empty($calculation['warnings'])):?><div class="alert ok">Противоречий не найдено.</div><?php endif;?></div>
        <label class="report-note">Комментарий к расчёту<textarea class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="reporting_note"><?= e($manifest['reporting_note']) ?></textarea></label>
        <?php if($lastCalculation):?><div class="small muted mt">Последний сохранённый расчёт: v<?= (int)$lastCalculation['version'] ?>, <?= e(date('d.m.Y H:i',strtotime($lastCalculation['created_at']))) ?> · <?= e($lastCalculation['actor']) ?></div><?php endif;?>
    </div>
</div>

<div class="card"><div class="row report-card-head"><h2>Наличные по рейсу</h2><button class="btn sm ghost" onclick="reportAddCash()">+ Внести</button></div><div id="reportCashList">
<?php foreach($cashEntries as $c):?><div class="report-cash" data-id="<?= (int)$c['id'] ?>"><span><strong><?= $money($c['amount']) ?></strong> · <?= ['us'=>'у нас','carrier'=>'у перевозчика','agent'=>'у агента'][$c['recipient']] ?? e($c['recipient']) ?> · <?= e($c['note']) ?></span><small><?= e(date('d.m.Y H:i',strtotime($c['created_at']))) ?> · <?= e($c['actor']) ?></small></div><?php endforeach;?><?php if(!$cashEntries):?><p class="muted">Наличные не внесены.</p><?php endif;?>
</div></div>
<?php endif; ?>
