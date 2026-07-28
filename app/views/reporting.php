<?php /** @var array $rows @var array $agentContracts @var array $stations */ ?>
<div class="page-head">
    <div><h1>Отчётность по рейсам</h1><div class="sub">Ведомость → сверка явки и возвратов → расчёт → файлы рейса</div></div>
    <div class="head-actions"><a class="btn ghost" href="/?p=reporting_help"><?= icon('doc') ?> Инструкция</a></div>
</div>

<div class="report-tabs">
    <a class="active" href="/?p=reporting">Рейсы</a>
    <a href="/?p=reporting&tab=analytics">Аналитика</a>
    <a href="/?p=reporting&tab=settings">Настройки</a>
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

<p class="muted small">Агенты, автовокзалы, ставки перевозчиков и источник ведомостей — во вкладке <a href="/?p=reporting&tab=settings">Настройки</a>.</p>
