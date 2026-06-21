<?php /** @var array $links @var array $stats @var array $recentManifests */ ?>
<div class="page-head">
    <div>
        <h1>Панель управления</h1>
        <div class="sub"><?= date('d.m.Y') ?> · все рабочие сервисы и операции в одном месте</div>
    </div>
    <div class="head-actions">
        <a class="btn" href="/?p=manifests"><?= icon('upload') ?> Загрузить ведомость</a>
    </div>
</div>

<div class="grid grid-3">
    <div class="stat"><div class="num"><?= $stats['manifests'] ?></div><div class="lbl">ведомостей в системе</div></div>
    <div class="stat"><div class="num"><?= $stats['sent_today'] ?></div><div class="lbl">уведомлений отправлено сегодня</div></div>
    <div class="stat"><div class="num" style="<?= $stats['failed'] > 0 ? 'color:var(--err)' : '' ?>"><?= $stats['failed'] ?></div><div class="lbl">ошибок доставки сегодня</div></div>
</div>

<div class="card mt">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">Сервисы</h2>
        <button class="btn ghost sm" onclick="addService()"><?= icon('plus') ?> Добавить</button>
    </div>
    <div class="service-grid mt" id="services">
        <?php foreach ($links as $l): ?>
            <a class="service" href="<?= e($l['url']) ?>" target="_blank" rel="noopener" data-id="<?= $l['id'] ?>">
                <span class="ic <?= e($l['color']) ?>"><?= icon($l['icon']) ?></span>
                <?= e($l['title']) ?>
                <button class="del" title="Удалить" onclick="return delService(event, <?= $l['id'] ?>)">✕</button>
            </a>
        <?php endforeach; ?>
    </div>
    <p class="muted small mt">Ссылки на внешние сервисы (Планфикс, GDS, банк, почта…) — добавляйте и удаляйте под себя.</p>
</div>

<div class="card">
    <h2>Последние ведомости</h2>
    <?php if (empty($recentManifests)): ?>
        <p class="muted">Пока пусто — загрузите первую CSV-ведомость.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>№</th><th>Маршрут</th><th>Отправление</th><th>Файл</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentManifests as $m): ?>
                <tr>
                    <td><b><?= e($m['trip_number']) ?></b></td>
                    <td><?= e($m['route']) ?></td>
                    <td><?= $m['departure_at'] ? date('d.m.Y H:i', strtotime($m['departure_at'])) : '—' ?></td>
                    <td class="muted small"><?= e($m['file_name']) ?></td>
                    <td class="actions">
                        <a class="btn ghost sm" href="/?p=manifest&id=<?= $m['id'] ?>">Открыть</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
