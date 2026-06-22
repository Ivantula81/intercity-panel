<?php
/** @var array $manifest @var array $passengers @var array $contracts @var array $calculation */
$t = $calculation['totals'];
$money = static fn($v) => number_format((float) $v, 0, ',', ' ') . ' ₽';
$isFiles = $activeTab === 'files';
?>
<div class="page-head report-trip-head">
    <div><a class="small muted" href="/?p=reporting">← Все рейсы</a><h1>Рейс № <?= e($manifest['trip_number']) ?></h1><div class="sub"><?= e($manifest['route']) ?></div></div>
    <div class="head-actions"><button class="btn ghost" type="button" onclick="reportAddCash()">+ Внести наличные</button><button class="btn" type="button" onclick="reportSaveSnapshot(<?= (int)$manifest['id'] ?>)">Сохранить расчёт</button></div>
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

<div class="report-metrics" id="reportMetrics">
    <div><span>По ведомости</span><strong data-total="manifest_total"><?= $money($t['manifest_total']) ?></strong></div>
    <div><span>Наши продажи</span><strong data-total="our_sales"><?= $money($t['our_sales']) ?></strong></div>
    <div><span>Продажи перевозчика</span><strong data-total="carrier_direct_sales"><?= $money($t['carrier_direct_sales']) ?></strong></div>
    <div><span>К выплате перевозчику</span><strong data-total="carrier_due"><?= $money($t['carrier_due']) ?></strong></div>
    <div class="accent"><span>Наша рентабельность</span><strong data-total="profit"><?= $money($t['profit']) ?></strong></div>
</div>

<div class="card report-passengers">
    <div class="row report-card-head"><div><h2>Пассажиры <span class="badge muted" id="reportPassengerCount"><?= count($passengers) ?></span></h2><div class="small muted">Неизвестная явка считается предварительно как поездка. Перед финальным расчётом отметьте всех.</div></div><button class="btn sm" onclick="reportAddPassenger(<?= (int)$manifest['id'] ?>)"><?= icon('plus') ?> Добавить пассажира</button></div>
    <div class="table-wrap"><table class="t report-passenger-table"><thead><tr><th>Явка</th><th>Возврат</th><th>Пассажир</th><th>Телефон</th><th>Откуда → куда</th><th>Агент / договор</th><th>Ведомость</th><th>Наша цена</th><th>Комментарий</th><th></th></tr></thead><tbody>
    <?php foreach ($passengers as $p): ?><tr data-id="<?= (int)$p['id'] ?>">
        <td><select class="report-p-field attendance-<?= e($p['attendance']) ?>" data-field="attendance"><option value="unknown" <?= $p['attendance']==='unknown'?'selected':'' ?>>Не отмечено</option><option value="present" <?= $p['attendance']==='present'?'selected':'' ?>>Явка</option><option value="absent" <?= $p['attendance']==='absent'?'selected':'' ?>>Неявка</option></select></td>
        <td><label class="report-refund"><input type="checkbox" class="report-p-field" data-field="refund_status" value="completed" <?= $p['refund_status']==='completed'?'checked':'' ?>><span>Оформлен</span></label></td>
        <td><input class="report-p-field" data-field="name" value="<?= e($p['name']) ?>"><input class="report-p-field small-input" data-field="birthdate" value="<?= e($p['birthdate']) ?>" placeholder="Дата рождения"></td>
        <td><input class="report-p-field" data-field="phone" value="<?= e($p['phone']) ?>"></td>
        <td><input class="report-p-field" data-field="from_stop" value="<?= e($p['from_stop']) ?>"><input class="report-p-field small-input" data-field="to_stop" value="<?= e($p['to_stop']) ?>"></td>
        <td><select class="report-p-field" data-field="agent_contract_id"><option value="">По умолчанию (15% + 7%)</option><?php foreach($contracts as $c):?><option value="<?= (int)$c['id'] ?>" <?= (int)$p['agent_contract_id']===(int)$c['id']?'selected':'' ?>><?= e($c['agent_name'].' · '.$c['title'].' · '.($c['settlement_side']==='ours'?'наш':'перевозчика')) ?></option><?php endforeach;?></select><input class="report-p-field small-input" data-field="agent_raw" value="<?= e($p['agent_raw']) ?>" placeholder="Значение из CSV"></td>
        <td><input type="number" step="0.01" class="report-p-field money-input" data-field="manifest_price" value="<?= e($p['manifest_price'] ?? $p['price']) ?>"></td>
        <td><input type="number" step="0.01" class="report-p-field money-input" data-field="our_price" value="<?= e($p['our_price']) ?>" placeholder="= ведомость"></td>
        <td><input class="report-p-field" data-field="finance_comment" value="<?= e($p['finance_comment']) ?>" placeholder="Комментарий"></td>
        <td><button class="icon-btn" onclick="reportDeletePassenger(this)" title="Удалить"><?= icon('trash') ?></button></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="report-save-state muted small" id="reportSaveState">Все изменения сохранены</div>
</div>

<div class="report-bottom-grid">
    <div class="card"><h2>Расшифровка</h2><div class="report-breakdown">
        <div><span>Коммерческая комиссия</span><strong data-total="commercial_fee"><?= $money($t['commercial_fee']) ?></strong></div>
        <div><span>Диспетчерское сопровождение</span><strong data-total="dispatch_fee"><?= $money($t['dispatch_fee']) ?></strong></div>
        <div><span>Комиссии агентов</span><strong data-total="agent_commission"><?= $money($t['agent_commission']) ?></strong></div>
        <div><span>Взаимозачёт диспетчерских</span><strong data-total="direct_dispatch_offset"><?= $money($t['direct_dispatch_offset']) ?></strong></div>
        <div><span>Отдельная задолженность перевозчика</span><strong data-total="direct_dispatch_receivable"><?= $money($t['direct_dispatch_receivable']) ?></strong></div>
    </div></div>
    <div class="card"><h2>Контроль</h2><div id="reportWarnings"><?php foreach(array_slice($calculation['warnings'],0,8) as $w):?><div class="report-warning">⚠ <?= e($w) ?></div><?php endforeach;?><?php if(!$calculation['warnings']):?><div class="alert ok">Противоречий не найдено.</div><?php endif;?></div>
        <label class="report-note">Комментарий к расчёту<textarea class="report-manifest-field" data-id="<?= (int)$manifest['id'] ?>" data-field="reporting_note"><?= e($manifest['reporting_note']) ?></textarea></label>
        <?php if($lastCalculation):?><div class="small muted mt">Последний сохранённый расчёт: v<?= (int)$lastCalculation['version'] ?>, <?= e(date('d.m.Y H:i',strtotime($lastCalculation['created_at']))) ?> · <?= e($lastCalculation['actor']) ?></div><?php endif;?>
    </div>
</div>

<div class="card"><div class="row report-card-head"><h2>Наличные по рейсу</h2><button class="btn sm ghost" onclick="reportAddCash()">+ Внести</button></div><div id="reportCashList">
<?php foreach($cashEntries as $c):?><div class="report-cash" data-id="<?= (int)$c['id'] ?>"><span><strong><?= $money($c['amount']) ?></strong> · <?= ['us'=>'у нас','carrier'=>'у перевозчика','agent'=>'у агента'][$c['recipient']] ?? e($c['recipient']) ?> · <?= e($c['note']) ?></span><small><?= e(date('d.m.Y H:i',strtotime($c['created_at']))) ?> · <?= e($c['actor']) ?></small></div><?php endforeach;?><?php if(!$cashEntries):?><p class="muted">Наличные не внесены.</p><?php endif;?>
</div></div>
<?php endif; ?>
