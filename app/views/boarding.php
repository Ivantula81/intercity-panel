<?php /** @var array $manifest @var array $passengers */
function bv(?string $v): string { $v = trim((string) $v); return $v !== '' ? e($v) : '—'; }
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Посадочная ведомость №<?= e($manifest['trip_number']) ?> — <?= e($manifest['route']) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 0; padding: 28px; color: #111; background: #fff; font: 13.5px/1.45 Arial, sans-serif; }
.bar { display: flex; justify-content: space-between; margin-bottom: 20px; }
.bar a, .bar button { padding: 9px 16px; border: 0; border-radius: 9px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
.bar a { color: #57399f; background: #efe9fb; }
.bar button { color: #fff; background: #6d4fc4; }
.head { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 3px solid #111; }
h1 { margin: 0 0 4px; font-size: 22px; }
.route { font-size: 16px; }
.brand { font-weight: 700; color: #777; }
table.facts { width: 100%; margin: 12px 0; border-collapse: collapse; }
.facts td { padding: 6px 12px 6px 0; }
.facts span { display: block; color: #777; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; }
table.list { width: 100%; border-collapse: collapse; }
.list th, .list td { padding: 6px 7px; border: 1px solid #555; font-size: 12.5px; text-align: left; }
.list th { background: #f0f0f3; }
.num { width: 44px; text-align: center; }
.mark { width: 52px; }
.total { margin: 12px 0 24px; font-size: 14px; }
.signs { display: grid; gap: 16px; max-width: 480px; }
@media print { .bar { display: none; } body { padding: 0; } @page { size: A4 landscape; margin: 11mm; } }
</style>
</head>
<body>
<div class="bar">
    <a href="/?p=manifest&id=<?= (int) $manifest['id'] ?>">← К редактированию</a>
    <button onclick="window.print()">Печать</button>
</div>

<div class="head">
    <div>
        <h1>Посадочная ведомость № <?= bv($manifest['trip_number']) ?></h1>
        <div class="route"><?= bv($manifest['route']) ?></div>
    </div>
    <div class="brand">Интерсити Тур</div>
</div>

<table class="facts"><tr>
    <td><span>Отправление</span><b><?= $manifest['departure_at'] ? date('d.m.Y H:i', strtotime($manifest['departure_at'])) : '—' ?></b></td>
    <td><span>Перевозчик</span><b><?= bv($manifest['carrier']) ?></b></td>
    <td><span>Автобус</span><b><?= bv($manifest['bus']) ?></b></td>
    <td><span>Водители</span><b><?= bv($manifest['drivers']) ?></b></td>
</tr></table>

<table class="list">
    <thead><tr>
        <th class="num">№</th><th class="num">Место</th><th>ФИО пассажира</th><th>Документ</th>
        <th>Билет</th><th>Телефон</th><th>Посадка</th><th>Высадка</th><th class="mark">Явка</th>
    </tr></thead>
    <tbody>
    <?php $n = 0; foreach ($passengers as $p): $n++; ?>
        <tr>
            <td class="num"><?= $n ?></td>
            <td class="num"><?= bv($p['seat']) ?></td>
            <td><?= bv($p['name']) ?></td>
            <td><?= bv($p['doc']) ?></td>
            <td><?= bv($p['ticket']) ?></td>
            <td><?= bv($p['phone']) ?></td>
            <td><?= bv($p['from_stop']) ?></td>
            <td><?= bv($p['to_stop']) ?></td>
            <td class="mark"></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="total">Всего пассажиров: <b><?= count($passengers) ?></b></div>

<div class="signs">
    <div>Кассир / диспетчер: ______________________ / ____________</div>
    <div>Водитель: ______________________ / ____________</div>
    <div>Дата: «____» ______________ <?= date('Y') ?> г.</div>
</div>
</body>
</html>
