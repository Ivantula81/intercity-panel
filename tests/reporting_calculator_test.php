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
expectSameValue(245.0, $direct['totals']['direct_dispatch_offset'], 'Диспетчерские 7% идут во взаимозачёт');
expectSameValue(-245.0, $direct['totals']['carrier_due'], 'При отсутствии нашей выплаты образуется долг перевозчика');

$directAbsent = ReportingCalculator::calculate([[
    'id'=>5,'name'=>'Прямая продажа, неявка без возврата','attendance'=>'absent','refund_status'=>'none',
    'manifest_price'=>3500,'our_price'=>3500,'agent_contract_id'=>2,
]], $carrier);
expectSameValue(245.0, $directAbsent['totals']['dispatch_fee'], 'По прямой продаже без возврата диспетчерские сохраняются при неявке');

echo "ReportingCalculator: OK\n";
