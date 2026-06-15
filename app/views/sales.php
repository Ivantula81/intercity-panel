<?php
/** @var string $period @var array $metrics @var array $byChannel @var array $topDates @var array $feed */

$CH = [
    'site' => ['Свой сайт', '#5b50e0'], 'gobus' => ['GoBus', '#16a765'],
    'rosbilet' => ['Рос Билет', '#d68102'], 'unitiki' => ['Unitiki', '#4986e7'],
    'artmark' => ['Артмарк GDS', '#a64d79'], 'avtovokzaly' => ['Автовокзалы.ру', '#0b8043'],
    'blablacar' => ['BlaBlaCar', '#00aff5'], 'other' => ['Другое', '#9a9ab0'],
];
$KIND = [
    'sale' => ['Продажа', 'ok'], 'payment' => ['Оплата', 'info'], 'refund' => ['Возврат', 'err'],
    'cancel' => ['Аннуляция', 'warn'], 'manifest' => ['Ведомость', 'info'], 'other' => ['—', ''],
];
$chName = fn($c) => $CH[$c][0] ?? $c;
$chColor = fn($c) => $CH[$c][1] ?? '#9a9ab0';
$money = fn($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';
$periods = ['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', 'all' => 'Всё время'];
$maxDate = 0; foreach ($topDates as $d) $maxDate = max($maxDate, (int) $d['c']);
?>

<div class="page-head">
    <div>
        <div class="eyebrow">Каналы продаж</div>
        <h1>Продажи</h1>
        <div class="sub">Входящие из почты: свой сайт, GoBus, Рос Билет, Unitiki, Артмарк GDS, Автовокзалы.ру</div>
    </div>
</div>

<div class="seg">
    <?php foreach ($periods as $k => $label): ?>
        <a href="/?p=sales&period=<?= $k ?>" class="seg-item <?= $period === $k ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="grid-4" style="margin-bottom:18px">
    <div class="card stat">
        <div class="lbl">Продажи (билетов)</div>
        <div class="num"><?= (int) $metrics['sales_cnt'] ?></div>
        <div class="muted small"><?= $money($metrics['sales_sum']) ?> <span class="faint">по своему сайту</span></div>
    </div>
    <div class="card stat">
        <div class="lbl">Возвраты</div>
        <div class="num" style="color:var(--err)"><?= (int) $metrics['refund_cnt'] ?></div>
        <div class="muted small"><?= $money($metrics['refund_sum']) ?></div>
    </div>
    <div class="card stat">
        <div class="lbl">Аннуляции</div>
        <div class="num" style="color:var(--warn)"><?= (int) $metrics['cancel_cnt'] ?></div>
        <div class="muted small">снять с ведомости</div>
    </div>
    <div class="card stat">
        <div class="lbl">Всего событий</div>
        <div class="num"><?= (int) $metrics['total'] ?></div>
        <div class="muted small">за период</div>
    </div>
</div>

<div class="split" style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px;align-items:start">
    <div class="card">
        <h2>По каналам</h2>
        <?php if (!$byChannel): ?><p class="muted">Нет данных за период.</p><?php else: ?>
        <table class="cat" style="width:100%">
            <thead><tr><th>Канал</th><th>Продажи</th><th>Возвраты</th><th>Аннул.</th><th style="text-align:right">Сумма</th></tr></thead>
            <tbody>
            <?php foreach ($byChannel as $c): ?>
                <tr>
                    <td><span class="ch-dot" style="background:<?= $chColor($c['channel']) ?>"></span><?= e($chName($c['channel'])) ?></td>
                    <td><b><?= (int) $c['sales'] ?></b></td>
                    <td><?= (int) $c['refunds'] ?: '—' ?></td>
                    <td><?= (int) $c['cancels'] ?: '—' ?></td>
                    <td style="text-align:right"><?= (int) $c['sum'] ? $money($c['sum']) : '<span class="faint">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted small" style="margin-top:10px">Суммы (₽) приходят в письмах своего сайта; агрегаторы присылают только факт продажи/возврата билета.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Топ дат отправления</h2>
        <?php if (!$topDates): ?><p class="muted">Нет дат за период.</p><?php else: ?>
        <div class="topdates">
        <?php foreach ($topDates as $d): ?>
            <div class="td-row">
                <span class="td-date"><?= date('d.m.Y', strtotime($d['d'])) ?></span>
                <span class="td-bar"><span style="width:<?= $maxDate ? round(100 * $d['c'] / $maxDate) : 0 ?>%"></span></span>
                <span class="td-cnt"><?= (int) $d['c'] ?></span>
            </div>
        <?php endforeach; ?>
        </div>
        <p class="muted small" style="margin-top:10px">Сколько билетов продано на каждую дату рейса.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:18px">
    <h2>Лента входящих <span class="muted small" style="font-weight:500">(последние <?= count($feed) ?>)</span></h2>
    <?php if (!$feed): ?><p class="muted">Пока нет писем за период.</p><?php else: ?>
    <div class="sfeed">
        <?php foreach ($feed as $r): [$kl, $kc] = $KIND[$r['kind']] ?? ['—', '']; ?>
            <div class="sf-row">
                <span class="sf-time"><?= date('d.m H:i', strtotime($r['occurred_at'])) ?></span>
                <span class="sf-ch" style="background:<?= $chColor($r['channel']) ?>"><?= e($chName($r['channel'])) ?></span>
                <span class="badge <?= $kc ?> sf-kind"><?= $kl ?></span>
                <span class="sf-route"><?= e($r['route'] ?: $r['segment'] ?: '—') ?>
                    <?php if ($r['depart_at']): ?><span class="faint">· рейс <?= date('d.m.Y', strtotime($r['depart_at'])) ?></span><?php endif; ?>
                </span>
                <span class="sf-amount"><?= $r['amount'] !== null ? $money($r['amount']) : '' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
