<?php /** @var array $manifests @var string $uploadError */ ?>
<div class="page-head">
    <div>
        <h1>Ведомости</h1>
        <div class="sub">загрузка CSV из Jasper → читаемая ведомость для водителей</div>
    </div>
</div>

<?php if ($uploadError !== ''): ?><div class="alert err"><?= e($uploadError) ?></div><?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="drop" id="drop">
        <?= icon('upload') ?>
        <div><b>Выберите CSV-файл</b> или перетащите сюда</div>
        <div class="small">Windows-1251 и UTF-8 распознаются автоматически · до 10 МБ</div>
        <input type="file" name="manifest" accept=".csv,text/csv" onchange="this.form.submit()">
    </label>
</form>

<div class="card">
    <h2>Все ведомости</h2>
    <?php if (empty($manifests)): ?>
        <p class="muted">Пока пусто.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>№</th><th>Маршрут</th><th>Отправление</th><th>Пасс.</th><th>Загружена</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($manifests as $m): ?>
                <tr>
                    <td><b><?= e($m['trip_number']) ?></b></td>
                    <td><?= e($m['route']) ?></td>
                    <td><?= $m['departure_at'] ? date('d.m.Y H:i', strtotime($m['departure_at'])) : '—' ?></td>
                    <td><span class="badge muted"><?= (int) $m['cnt'] ?></span></td>
                    <td class="muted small"><?= date('d.m H:i', strtotime($m['created_at'])) ?></td>
                    <td class="actions">
                        <a class="btn ghost sm" href="/?p=manifest&id=<?= $m['id'] ?>"><?= icon('edit') ?> Открыть</a>
                        <a class="btn ghost sm" href="/?p=boarding&id=<?= $m['id'] ?>" target="_blank"><?= icon('print') ?> Печать</a>
                        <button class="icon-btn" title="Удалить" onclick="delManifest(<?= $m['id'] ?>)"><?= icon('trash') ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
