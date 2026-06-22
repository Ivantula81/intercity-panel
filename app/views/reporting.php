<?php /** @var array $rows @var array $agentContracts */ ?>
<div class="page-head">
    <div><h1>Отчётность по рейсам</h1><div class="sub">Ведомость → сверка явки и возвратов → расчёт → файлы рейса</div></div>
    <div class="head-actions"><a class="btn ghost" href="/?p=reporting_help"><?= icon('doc') ?> Инструкция</a></div>
</div>

<?php if ($reportingError !== ''): ?><div class="alert err"><?= e($reportingError) ?></div><?php endif; ?>

<div class="report-upload card">
    <div>
        <h2>Загрузить ведомость</h2>
        <p class="muted">CSV распознаётся существующим импортёром. Если ID рейса уже есть, новая копия сохранится как версия файла без дублирования пассажиров.</p>
    </div>
    <form method="post" enctype="multipart/form-data" class="report-upload-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import_csv">
        <label class="report-drop"><span>CSV-ведомость</span><input type="file" name="manifest" accept=".csv,text/csv" required></label>
        <button class="btn" type="submit"><?= icon('upload') ?> Загрузить и рассчитать</button>
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
                <td><a class="btn sm" href="/?p=report_trip&id=<?= (int) $m['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Загрузите первую ведомость.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<details class="card report-agents" id="agents">
    <summary><strong>Справочник агентов и договоров</strong> <span class="muted">— <?= count($agentContracts) ?> условий</span></summary>
    <div class="table-wrap mt"><table class="t"><thead><tr><th>Агент</th><th>Договор</th><th>Деньги получает</th><th>Комиссия агента</th><th>Коммерческая</th><th>Диспетчерская</th></tr></thead><tbody>
    <?php foreach ($agentContracts as $c): ?><tr><td><?= e($c['agent_name']) ?><div class="small muted"><?= e($c['aliases']) ?></div></td><td><?= e($c['title']) ?><div class="small muted"><?= e($c['carrier']) ?></div></td><td><?= $c['settlement_side']==='ours' ? 'Мы' : 'Перевозчик' ?></td><td><?= e($c['agent_commission_rate']) ?>%</td><td><?= e($c['commercial_rate']) ?>%</td><td><?= e($c['dispatch_rate']) ?>%</td></tr><?php endforeach; ?>
    </tbody></table></div>
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
