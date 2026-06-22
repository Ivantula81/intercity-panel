<?php

final class ReportingCalculator
{
    public static function calculate(array $passengers, array $contracts): array
    {
        $totals = [
            'passengers' => count($passengers), 'present' => 0, 'absent' => 0, 'unknown' => 0, 'refunds' => 0,
            'manifest_total' => 0.0, 'our_sales' => 0.0, 'carrier_direct_sales' => 0.0,
            'commercial_fee' => 0.0, 'dispatch_fee' => 0.0, 'agent_commission' => 0.0,
            'carrier_due_before_offset' => 0.0, 'direct_dispatch_offset' => 0.0,
            'direct_dispatch_receivable' => 0.0, 'carrier_due' => 0.0, 'profit' => 0.0,
        ];
        $rows = [];
        $warnings = [];

        foreach ($passengers as $p) {
            $attendance = in_array($p['attendance'] ?? '', ['present', 'absent'], true)
                ? $p['attendance'] : 'unknown';
            $refunded = ($p['refund_status'] ?? 'none') === 'completed';
            $manifestPrice = self::money($p['manifest_price'] ?? $p['price'] ?? 0);
            $ourPrice = ($p['our_price'] ?? '') === '' || $p['our_price'] === null
                ? $manifestPrice : self::money($p['our_price']);
            $contractId = (int) ($p['agent_contract_id'] ?? 0);
            $contract = $contracts[$contractId] ?? self::defaultContract();
            $side = ($contract['settlement_side'] ?? 'ours') === 'carrier' ? 'carrier' : 'ours';

            $totals[$attendance]++;
            if ($refunded) $totals['refunds']++;

            $carrierBase = 0.0;
            $saleGross = 0.0;
            if (!$refunded) {
                if ($attendance !== 'absent') $carrierBase = $manifestPrice;
                $saleGross = $ourPrice;
            }

            $commercialRate = $side === 'ours' ? self::rate($contract['commercial_rate'] ?? 15) : 0.0;
            $dispatchRate = self::rate($contract['dispatch_rate'] ?? 7);
            $agentRate = self::rate($contract['agent_commission_rate'] ?? 0);
            $agentBasis = ($contract['agent_commission_basis'] ?? 'our_price') === 'manifest_price'
                ? $manifestPrice : $ourPrice;
            if ($refunded) $agentBasis = 0.0;

            $commercialFee = round($carrierBase * $commercialRate, 2);
            // При прямом договоре деньги не проходят через нас, но диспетчерские 7%
            // всё равно начисляются с не возвращённой продажи, в том числе при неявке.
            $dispatchBase = $side === 'carrier' ? $saleGross : $carrierBase;
            $dispatchFee = round($dispatchBase * $dispatchRate, 2);
            $agentCommission = round($agentBasis * $agentRate, 2);
            $carrierDue = $side === 'ours' ? $carrierBase - $commercialFee - $dispatchFee : 0.0;

            $totals['manifest_total'] += $carrierBase;
            $totals[$side === 'ours' ? 'our_sales' : 'carrier_direct_sales'] += $saleGross;
            $totals['commercial_fee'] += $commercialFee;
            $totals['dispatch_fee'] += $dispatchFee;
            $totals['agent_commission'] += $agentCommission;
            $totals['carrier_due_before_offset'] += $carrierDue;
            if ($side === 'carrier') {
                if (($contract['dispatch_settlement'] ?? 'offset') === 'receivable') {
                    $totals['direct_dispatch_receivable'] += $dispatchFee;
                } else {
                    $totals['direct_dispatch_offset'] += $dispatchFee;
                }
            }

            $rowProfit = $side === 'ours'
                ? $saleGross - $agentCommission - $carrierDue
                : $dispatchFee;
            $totals['profit'] += $rowProfit;

            if ($attendance === 'unknown') {
                $warnings[] = 'Не отмечена явка: ' . ($p['name'] ?? 'пассажир');
            }
            if ($attendance === 'present' && $refunded) {
                $warnings[] = 'Явка и возврат одновременно: ' . ($p['name'] ?? 'пассажир');
            }

            $rows[] = [
                'id' => (int) ($p['id'] ?? 0), 'carrier_base' => self::round($carrierBase),
                'sale_gross' => self::round($saleGross), 'commercial_fee' => self::round($commercialFee),
                'dispatch_fee' => self::round($dispatchFee), 'agent_commission' => self::round($agentCommission),
                'carrier_due' => self::round($carrierDue), 'profit' => self::round($rowProfit),
                'settlement_side' => $side,
            ];
        }

        $totals['carrier_due'] = $totals['carrier_due_before_offset'] - $totals['direct_dispatch_offset'];
        foreach ($totals as $key => $value) {
            if (is_float($value)) $totals[$key] = self::round($value);
        }

        return ['totals' => $totals, 'passengers' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    private static function defaultContract(): array
    {
        return [
            'settlement_side' => 'ours', 'commercial_rate' => 15, 'dispatch_rate' => 7,
            'agent_commission_rate' => 0, 'agent_commission_basis' => 'our_price',
            'dispatch_settlement' => 'offset',
        ];
    }

    private static function money(mixed $value): float
    {
        return round((float) str_replace(',', '.', (string) $value), 2);
    }

    private static function rate(mixed $value): float
    {
        return max(0.0, (float) str_replace(',', '.', (string) $value)) / 100;
    }

    private static function round(float $value): float
    {
        return round($value, 2);
    }
}
