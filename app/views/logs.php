<?php
/** @var string $filter @var array $counts @var array $rows */
$chName = fn($c) => msg_channel_meta((string) $c)[0];
$chColor = fn($c) => msg_channel_meta((string) $c)[1];
$tabs = [
    'all' => ['Все', (int) $counts['total']],
    'failed' => ['Ошибки', (int) $counts['failed']],
    'sent' => ['Отправлено', (int) $counts['sent']],
    'undelivered' => ['Не доставлено', (int) $counts['undelivered']],
];
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Журнал</div>
        <h1>Логи отправок</h1>
        <div class="sub">все исходящие сообщения, статусы доставки и ошибки</div>
    </div>
</div>

<div class="seg">
    <?php foreach ($tabs as $k => [$lbl, $n]): ?>
        <a href="/?p=logs&f=<?= $k ?>" class="seg-item <?= $filter === $k ? 'active' : '' ?>"><?= $lbl ?> <span class="seg-n"><?= $n ?></span></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <?php if (!$rows): ?>
        <p class="muted">Записей за этот фильтр нет.</p>
    <?php else: ?>
    <div class="logfeed">
        <?php foreach ($rows as $r):
            $st = $r['status'];
            [$sc, $sl] = $st === 'sent' ? ['ok', 'отправлено'] : ($st === 'failed' ? ['err', 'ошибка'] : ['warn', 'в очереди']);
            $deliv = $r['read_at'] ? 'прочитано ✓✓' : ($r['delivered_at'] ? 'доставлено ✓✓' : '');
        ?>
            <div class="lg-row <?= $st === 'failed' ? 'lg-fail' : '' ?>">
                <div class="lg-top">
                    <span class="lg-time"><?= date('d.m H:i', strtotime($r['created_at'])) ?></span>
                    <span class="lg-ch" style="background:<?= $chColor($r['channel']) ?>"><?= e($chName($r['channel'])) ?></span>
                    <span class="badge <?= $sc ?>"><?= $sl ?></span>
                    <?php if ($deliv): ?><span class="muted small"><?= $deliv ?></span><?php endif; ?>
                    <span class="lg-to"><?= e($r['recipient']) ?><?= $r['passenger_name'] ? ' · ' . e($r['passenger_name']) : '' ?></span>
                    <?php if ($r['actor']): ?><span class="faint small">— <?= e($r['actor']) ?></span><?php endif; ?>
                </div>
                <?php if (trim((string) $r['body']) !== ''): ?><div class="lg-body"><?= e(mb_substr($r['body'], 0, 160)) ?></div><?php endif; ?>
                <?php if ($st === 'failed' && $r['error']): ?><div class="lg-err">⚠ <?= e($r['error']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:12px">Показаны последние 300 записей. Каналы: WhatsApp, MAX, Telegram, SMS, Email.</p>
    <?php endif; ?>
</div>
