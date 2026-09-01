<?php

require dirname(__DIR__) . '/lib/ReportingCalculator.php';

function expectSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$message: ожидалось " . var_export($expected, true) . ', получено ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$ours = [1 => [
    'settlement_side'=>'ours','commercial_rate'=>15,'dispatch_rate'=>7,
    'agent_commission_rate'=>0,'agent_commission_basis'=>'our_price','dispatch_settlement'=>'offset',
]];
$present = ReportingCalculator::calculate([[
    'id'=>1,'name'=>'Явка','attendance'=>'present','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>4500,'agent_contract_id'=>1,
]], $ours);
expectSameValue(2730.0, $present['totals']['carrier_due'], 'Расчёт выплаты перевозчику');
expectSameValue(1770.0, $present['totals']['profit'], 'Дополнительная цена и комиссии входят в прибыль');

$ours[1]['agent_commission_rate'] = 10;
$absent = ReportingCalculator::calculate([[
    'id'=>2,'name'=>'Неявка без возврата','attendance'=>'absent','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>4500,'agent_contract_id'=>1,
]], $ours);
expectSameValue(0.0, $absent['totals']['carrier_due'], 'Неявка исключается из выплаты перевозчику');
expectSameValue(4050.0, $absent['totals']['profit'], 'При неявке без возврата остаётся наша цена минус комиссия агента');

$refunded = ReportingCalculator::calculate([[
    'id'=>3,'name'=>'Неявка с возвратом','attendance'=>'absent','refund_status'=>'completed',
    'manifest_price'=>3500,'our_price'=>4500,'agent_contract_id'=>1,
]], $ours);
expectSameValue(0.0, $refunded['totals']['our_sales'], 'Возврат обнуляет нашу выручку');
expectSameValue(0.0, $refunded['totals']['agent_commission'], 'Возврат обнуляет комиссию агента');
expectSameValue(0.0, $refunded['totals']['profit'], 'Возврат обнуляет прибыль');

$carrier = [2 => [
    'settlement_side'=>'carrier','commercial_rate'=>0,'dispatch_rate'=>7,
    'agent_commission_rate'=>0,'agent_commission_basis'=>'our_price','dispatch_settlement'=>'offset',
]];
$direct = ReportingCalculator::calculate([[
    'id'=>4,'name'=>'Продажа агента перевозчика','attendance'=>'present','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>3500,'agent_contract_id'=>2,
]], $carrier);
expectSameValue(3500.0, $direct['totals']['carrier_direct_sales'], 'Прямая продажа перевозчика выделяется отдельно');
expectSameValue(0.0, $direct['totals']['commercial_fee'], 'Коммерческая комиссия по прямой продаже равна нулю');
expectSameValue(245.0, $direct['totals']['dispatch_fee'], 'Диспетчерские считаются со всей ведомости');
expectSameValue(-245.0, $direct['totals']['carrier_due'], 'При отсутствии нашей выплаты образуется долг перевозчика');

$directAbsent = ReportingCalculator::calculate([[
    'id'=>5,'name'=>'Прямая продажа, неявка без возврата','attendance'=>'absent','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>3500,'agent_contract_id'=>2,
]], $carrier);
expectSameValue(0.0, $directAbsent['totals']['dispatch_fee'], 'Неявка без возврата не входит в базу диспетчерских');
expectSameValue(3500.0, $directAbsent['totals']['noshow_income'], 'Неявка агента перевозчика становится нашим доходом');
expectSameValue(-3500.0, $directAbsent['totals']['carrier_due'], 'Сумма неявки агента перевозчика удерживается из расчёта с перевозчиком');

$mixed = ReportingCalculator::calculate([
    ['id'=>6,'name'=>'Наша продажа','attendance'=>'present','refund_status'=>'none',
        'manifest_price'=>3500,'our_price'=>4500,'agent_contract_id'=>1],
    ['id'=>7,'name'=>'Возврат','attendance'=>'absent','refund_status'=>'completed',
        'manifest_price'=>3500,'our_price'=>4500,'agent_contract_id'=>1],
], $ours, [
    'station_sales' => [['station_id'=>10,'name'=>'Автовокзал Севастополь','amount'=>1000,'rate'=>10]],
    'cash' => 500,
]);
expectSameValue(4500.0, $mixed['totals']['turnover'], 'В оборот входят ведомость и автовокзал');
expectSameValue(315.0, $mixed['totals']['dispatch_fee'], 'Диспетчерские считаются с полного оборота');
expectSameValue(500.0, $mixed['totals']['cash'], 'Наличные сохраняются в расчёте');
expectSameValue(2160.0, $mixed['totals']['carrier_due'], 'Наличные уменьшают долг перевозчику');
expectSameValue(1390.0, $mixed['totals']['our_profit'], 'Доплата и диспетчерские входят в рентабельность');

$overrideAgents = [
    10 => ['name'=>'Терра','side'=>'us','rate'=>0,'alias'=>'Сайт, ТЕРРА','src'=>'raw'],
    11 => ['name'=>'GoBus Ванюк','side'=>'carrier','rate'=>0,'alias'=>'GoBus Ванюк','src'=>'comment'],
];
// Оператор выбрал агента вручную — комментарий его НЕ перебивает. Правило «комментарий
// сильнее» действует для автозаполненного поля «Агент/кассир», а не для решения человека
// (в прототипе: `if (r.agent) return;`). Пересобрать назначения можно кнопкой
// «Подставить агентов по совпадению» — это осознанное действие оператора.
$override = ReportingCalculator::calculate([[
    'id'=>8,'name'=>'Ручное назначение','attendance'=>'present','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>3500,'agent_contract_id'=>10,
    'agent_raw'=>'Сайт','pay_note'=>'GoBus Ванюк',
]], $overrideAgents);
expectSameValue('us', $override['passengers'][0]['settlement_side'], 'Ручное назначение агента не перебивается комментарием');
expectSameValue(3500.0, $override['totals']['our_sales'], 'Продажа остаётся в нашем канале');

// А без ручного назначения комментарий действительно сильнее поля «Агент/кассир».
$autoNote = ReportingCalculator::calculate([[
    'id'=>12,'name'=>'Автоподбор по пометке','attendance'=>'present','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>3500,'agent_contract_id'=>0,
    'agent_raw'=>'Сайт','pay_note'=>'GoBus Ванюк',
]], $overrideAgents);
expectSameValue('carrier', $autoNote['passengers'][0]['settlement_side'], 'Без назначения комментарий сильнее поля');

$commaAlias = ReportingCalculator::calculate([[
    'id'=>9,'name'=>'Алиас через запятую','attendance'=>'present','refund_status'=>'none',
    'manifest_price'=>2000,'our_price'=>2000,'agent_contract_id'=>0,'agent_raw'=>'ТЕРРА',
]], $overrideAgents);
expectSameValue('us', $commaAlias['passengers'][0]['settlement_side'], 'Алиас через запятую сопоставляется');

echo "ReportingCalculator: OK\n";
