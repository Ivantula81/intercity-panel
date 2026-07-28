<?php
/** @var string $month @var array $months @var array $rows @var array $totals */
$money = static fn($v) => number_format((float) $v, 0, ',', ' ');
?>
<div class="page-head">
    <div><h1>Отчётность</h1><div class="sub">сводка за период — по сохранённым расчётам рейсов</div></div>
    <div class="head-actions">
        <?php if ($rows): ?><a class="btn ghost" href="/?p=report_month_export&month=<?= e($month) ?>"><?= icon('download') ?> Выгрузить CSV</a><?php endif; ?>
    </div>
</div>

<div class="report-tabs">
    <a href="/?p=reporting">Рейсы</a>
    <a class="active" href="/?p=reporting&tab=analytics">Аналитика</a>
    <a href="/?p=reporting&tab=settings">Настройки</a>
</div>

<div class="card">
    <div class="row report-card-head">
        <div><h2>Сводка за период</h2><div class="small muted">Считается по <b>сохранённым</b> расчётам: отчёт за закрытый месяц не поплывёт, если потом поменять проценты в справочниках.</div></div>
        <form method="get" class="row" style="gap:8px">
            <input type="hidden" name="p" value="reporting"><input type="hidden" name="tab" value="analytics">
            <select name="month" onchange="this.form.submit()" style="max-width:170px">
                <?php if (!in_array($month, $months, true)): ?><option value="<?= e($month) ?>" selected><?= e($month) ?></option><?php endif; ?>
                <?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$rows): ?>
        <p class="muted mt">За <?= e($month) ?> нет рейсов с сохранённым расчётом. Откройте рейс, нажмите «Рассчитать», затем «Сохранить расчёт» — и он попадёт сюда.</p>
    <?php else: ?>
        <div class="notif-metrics mt">
            <div><b><?= (int) $totals['trips'] ?></b><span>рейсов</span></div>
            <div><b><?= $money($totals['turnover']) ?></b><span>оборот, ₽</span></div>
            <div><b style="color:var(--ok)"><?= $money($totals['our_profit']) ?></b><span>наша прибыль, ₽</span></div>
            <div><b><?= e($totals['margin']) ?>%</b><span>маржа</span></div>
        </div>

        <div class="table-wrap mt"><table class="t report-month-table"><thead><tr>
            <th>Дата</th><th>Рейс</th><th>Перевозчик</th><th class="ta-r">Пасс.</th>
            <th class="ta-r">Оборот</th><th class="ta-r">Ведомость</th><th class="ta-r">Вокзалы</th><th class="ta-r">Наличные</th>
            <th class="ta-r">Продажи Терры</th><th class="ta-r">Продажи перев.</th>
            <th class="ta-r">Диспетч.</th><th class="ta-r">Комиссия Терры</th><th class="ta-r">Комиссии агентов</th>
            <th class="ta-r">Доп. прибыль</th><th class="ta-r">Наша прибыль</th><th class="ta-r">Маржа</th><th class="ta-r">Доход перев.</th>
        </tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small"><?= $r['departure_at'] ? e(date('d.m H:i', strtotime($r['departure_at']))) : '—' ?></td>
                <td><a href="/?p=report_trip&id=<?= (int)$r['manifest_id'] ?>">№<?= e($r['trip_number']) ?></a>
                    <div class="small muted"><?= e(mb_substr($r['route'], 0, 34)) ?></div>
                    <?php if ($r['scenario_name'] !== ''): ?><span class="badge muted small"><?= e($r['scenario_name']) ?></span><?php endif; ?></td>
                <td class="small"><?= e($r['carrier']) ?></td>
                <td class="ta-r"><?= (int) $r['pax'] ?></td>
                <td class="ta-r money-cell"><?= $money($r['turnover']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['manifest_total']) ?></td>
                <td class="ta-r money-cell"><?= $r['stations_total'] ? $money($r['stations_total']) : '—' ?></td>
                <td class="ta-r money-cell"><?= $r['cash'] ? $money($r['cash']) : '—' ?></td>
                <td class="ta-r money-cell"><?= $money($r['our_sales']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['carrier_sales']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['dispatch_fee']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['our_commission']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['agent_commission']) ?></td>
                <td class="ta-r money-cell"><?= $money($r['extra'] + $r['noshow_income']) ?></td>
                <td class="ta-r money-cell"><strong><?= $money($r['our_profit']) ?></strong></td>
                <td class="ta-r"><?= e($r['margin']) ?>%</td>
                <td class="ta-r money-cell"><?= $money($r['carrier_earn']) ?></td>
            </tr>
        <?php endforeach; ?>
            <tr class="report-station-total">
                <td colspan="3"><strong>Итого за <?= e($month) ?></strong></td>
                <td class="ta-r"><strong><?= (int) $totals['pax'] ?></strong></td>
                <td class="ta-r"><strong><?= $money($totals['turnover']) ?></strong></td>
                <td class="ta-r"><?= $money($totals['manifest_total']) ?></td>
                <td class="ta-r"><?= $money($totals['stations_total']) ?></td>
                <td class="ta-r"><?= $money($totals['cash']) ?></td>
                <td class="ta-r"><?= $money($totals['our_sales']) ?></td>
                <td class="ta-r"><?= $money($totals['carrier_sales']) ?></td>
                <td class="ta-r"><?= $money($totals['dispatch_fee']) ?></td>
                <td class="ta-r"><?= $money($totals['our_commission']) ?></td>
                <td class="ta-r"><?= $money($totals['agent_commission']) ?></td>
                <td class="ta-r"><?= $money($totals['extra'] + $totals['noshow_income']) ?></td>
                <td class="ta-r"><strong><?= $money($totals['our_profit']) ?></strong></td>
                <td class="ta-r"><strong><?= e($totals['margin']) ?>%</strong></td>
                <td class="ta-r"><strong><?= $money($totals['carrier_earn']) ?></strong></td>
            </tr>
        </tbody></table></div>
    <?php endif; ?>
</div>
