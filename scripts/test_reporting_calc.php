<?php

// Проверка финмодели рейса на контрольных числах, выверенных с владельцем.
// Запуск: php scripts/test_reporting_calc.php     (код возврата 0 = всё сошлось)
//
// Контрольные значения тестового рейса (из ТЗ):
//   оборот 64 250 · диспетчерские 4 497,50 · комиссии вокзалов 3 190
//   доход перевозчика 51 350 · наша прибыль 12 565 · вокзалы net 22 810
//   при наличных 5000: доход перевозчика НЕ меняется, долг Терры меньше на 5000
//
// Состав пассажиров восстановлен из этих контрольных сумм (он их однозначно
// воспроизводит все сразу). Если появится исходный CSV тестового рейса — строки
// можно заменить на фактические, ожидаемые итоги те же.

require_once __DIR__ . '/../lib/ReportingCalculator.php';

$fails = 0;
$checks = 0;

function check(string $name, $got, $want, float $eps = 0.005): void
{
    global $fails, $checks;
    $checks++;
    $ok = is_float($want) || is_int($want) ? abs((float) $got - (float) $want) <= $eps : $got === $want;
    printf("  %s %-42s %s%s\n", $ok ? '✓' : '❌', $name,
        is_bool($got) ? var_export($got, true) : (is_numeric($got) ? number_format((float) $got, 2, ',', ' ') : (string) $got),
        $ok ? '' : '   (ждали ' . (is_numeric($want) ? number_format((float) $want, 2, ',', ' ') : var_export($want, true)) . ')');
    if (!$ok) $fails++;
}

// ── справочник агентов (сторона / ставка / где искать) ──
$agents = [
    1 => ['name' => 'Интерсити Тур ООО',  'side' => 'us',      'rate' => 0,  'alias' => 'интерсити тур|intercitytour'],
    2 => ['name' => 'Артмарк GDS',        'side' => 'us',      'rate' => 1,  'alias' => 'артмарк|e-traffic|tutu|туту|unitiki|юнитик|busfor|басфор|автовокзал|новые тур|рус-билет|rus-bilet'],
    3 => ['name' => 'ООО Едем вместе',    'side' => 'us',      'rate' => 10, 'alias' => 'едем вместе'],
    4 => ['name' => 'GoBus',              'side' => 'carrier', 'rate' => 10, 'alias' => 'гоу бас|гоубас|gobus'],
    5 => ['name' => 'Рус-Билет (Ванюк)',  'side' => 'carrier', 'rate' => 7,  'alias' => 'рб ванюк'],
];

// ── пассажиры: 9 ехавших (ведомость 38 250) + 1 неявка без возврата (Артмарк, 6 500) ──
$mk = static fn(int $id, float $price, int $agent, string $att = 'present', string $refund = 'none'): array => [
    'id' => $id, 'name' => 'Пассажир ' . $id, 'manifest_price' => $price, 'our_price' => null,
    'agent_contract_id' => $agent, 'attendance' => $att, 'refund_status' => $refund,
    'agent_raw' => '', 'pay_note' => '',
];
$passengers = [
    // «Едем вместе» 10% — 25 300
    $mk(1, 6500, 3), $mk(2, 6500, 3), $mk(3, 6500, 3), $mk(4, 5800, 3),
    // «Интерсити Тур» 0% — 2 450
    $mk(5, 1200, 1), $mk(6, 1250, 1),
    // GoBus (перевозчика) 10% — 10 500
    $mk(7, 3500, 4), $mk(8, 3500, 4), $mk(9, 3500, 4),
    // неявка без возврата, продал Артмарк 1% — цена 6 500
    $mk(10, 6500, 2, 'absent', 'none'),
];

// ── автовокзалы: продажи мимо ведомости ──
$stations = [
    ['station_id' => 1, 'name' => 'МГТ',         'amount' => 15000, 'rate' => 15],
    ['station_id' => 2, 'name' => 'Тульский АВ', 'amount' => 8000,  'rate' => 8],
    ['station_id' => 3, 'name' => 'Севастополь', 'amount' => 3000,  'rate' => 10],
];

$opts = ['disp_rate' => 7, 'our_rate' => 15, 'cash' => 0, 'other_costs' => 0, 'station_sales' => $stations];

echo "═══ Тестовый рейс, наличные 0 ═══\n";
$t = ReportingCalculator::calculate($passengers, $agents, $opts)['totals'];

check('ведомость (по ехавшим)', $t['manifest_total'], 38250.0);
check('продажи автовокзалов', $t['stations_total'], 26000.0);
check('ОБОРОТ рейса (ведомость + вокзалы)', $t['turnover'], 64250.0);
check('диспетчерские 7% (с оборота)', $t['dispatch_fee'], 4497.50);
check('продажи Терры', $t['our_sales'], 27750.0);
check('комиссия Терры 15% (со своих продаж)', $t['our_commission'], 4162.50);
check('продажи агентов перевозчика', $t['carrier_sales'], 10500.0);
check('комиссии агентов перевозчика', $t['carrier_agent_cost'], 1050.0);
check('комиссии автовокзалов', $t['station_agent_cost'], 3190.0);
check('автовокзалы net (их долг)', $t['stations_net'], 22810.0);
check('доход с неявок', $t['noshow_income'], 6435.0);
check('комиссии наших агентов (ехавшие)', $t['our_agent_ride_cost'], 2530.0);
echo "  ──\n";
check('ДОХОД ПЕРЕВОЗЧИКА', $t['carrier_earn'], 51350.0);
check('НАША ПРИБЫЛЬ', $t['our_profit'], 12565.0);
check('долг Терры перевозчику', $t['to_carrier'], 19090.0);
check('версия формулы', $t['formula_version'], ReportingCalculator::FORMULA_VERSION);

echo "\n═══ То же, но наличные 5 000 ═══\n";
$t2 = ReportingCalculator::calculate($passengers, $agents, ['disp_rate' => 7, 'our_rate' => 15,
    'cash' => 5000, 'other_costs' => 0, 'station_sales' => $stations])['totals'];
check('доход перевозчика НЕ изменился', $t2['carrier_earn'], 51350.0);
check('долг Терры меньше на 5 000', $t2['to_carrier'], 14090.0);

echo "\n═══ Неявка с возвратом — строки нет вовсе ═══\n";
$withRefund = $passengers;
$withRefund[9] = $mk(10, 6500, 2, 'absent', 'completed'); // та же неявка, но с возвратом
$t3 = ReportingCalculator::calculate($withRefund, $agents, $opts)['totals'];
check('доход с неявок обнулён', $t3['noshow_income'], 0.0);
check('прибыль без дохода с неявки', $t3['our_profit'], 12565.0 - 6435.0);
check('ведомость не изменилась', $t3['manifest_total'], 38250.0);

echo "\n═══ Матчинг агентов ═══\n";
// комментарий сильнее автозаполненного поля: в поле Артмарк-канал, в комментарии — агент перевозчика
check('комментарий сильнее поля (РБ Ванюк)',
    ReportingCalculator::matchAgent('Рус-Билет [rus-bilet]', 'РБ Ванюк', $agents), 5);
// тот же Рус-Билет, но без пометки в комментарии → канал Артмарка (наша продажа)
check('Рус-Билет в поле → канал Артмарка',
    ReportingCalculator::matchAgent('Рус-Билет [rus-bilet]', '', $agents), 2);
// каналы Артмарка собираются на него по алиасам
check('tutu → Артмарк',
    ReportingCalculator::matchAgent('Новые Туристические Технологии [tutu.ru]', '', $agents), 2);
check('Юнитики → Артмарк',
    ReportingCalculator::matchAgent('Юнитики [unitiki.ru]', '', $agents), 2);
check('GoBus по комментарию',
    ReportingCalculator::matchAgent('', 'Гоубас', $agents), 4);
check('неизвестный агент → 0',
    ReportingCalculator::matchAgent('Кто-то незнакомый', '', $agents), 0);

echo "\n═══ Диспетчерские с неявок не берутся ═══\n";
// без вокзалов: оборот = только ведомость по ехавшим, неявка в базу 7% не входит
$t4 = ReportingCalculator::calculate($passengers, $agents,
    ['disp_rate' => 7, 'our_rate' => 15, 'station_sales' => []])['totals'];
check('оборот без вокзалов = ведомость', $t4['turnover'], 38250.0);
check('диспетчерские 7% от 38 250', $t4['dispatch_fee'], 2677.50);

printf("\n%s Проверок: %d, ошибок: %d\n", $fails ? '❌ ЕСТЬ РАСХОЖДЕНИЯ' : '✅ ВСЁ СОШЛОСЬ', $checks, $fails);
exit($fails ? 1 : 0);
