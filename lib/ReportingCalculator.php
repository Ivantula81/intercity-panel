<?php

// Финансовая модель рейса. Эталон — рабочий прототип, выверенный с владельцем.
//
// РОЛИ. «Терра» (ТерраТрансКрым) выступает в двух ролях одновременно:
//   диспетчер — берёт свой % со ВСЕГО ОБОРОТА рейса (плата за диспетчеризацию: вокзалы,
//               посадка, уведомления). Не зависит от того, кто продал билет.
//   агент     — берёт свой % ТОЛЬКО со своих продаж (обычный канал продаж).
// Перевозчик везёт пассажиров. Его агенты (GoBus, Рус-Билет) продают напрямую ему.
// Автовокзалы продают мимо ведомости, тоже напрямую перевозчику.
//
// ⚠️ Ставки живут на ПЕРЕВОЗЧИКЕ, но базы у них РАЗНЫЕ:
//     disp_rate (7%)  → с оборота = ведомость + продажи автовокзалов
//     our_rate  (15%) → только с продаж Терры
//
// НЕЯВКИ. Возврат оформлен → строки нет вовсе (обе цены 0). Неявка без возврата →
// ведомость 0 (перевозчик не вёз), но деньги за нами: «доход с неявки» = цена − комиссия
// агента. Диспетчерские с неявок не берутся (из оборота выпадают). Если неявку продал
// агент перевозчика — тот же доход удерживается из долга перевозчику (платим за перевезённых).
//
// НАЛИЧНЫЕ — не канал, а форма оплаты внутри продаж Терры: вычитаются из долга перевозчику
// (он получил их авансом на руки), но НЕ вычитаются из его дохода (это часть оборота).

final class ReportingCalculator
{
    // Версия формулы — пишется в снимок, чтобы старые расчёты читались своей логикой.
    const FORMULA_VERSION = 2;

    const DEFAULT_DISP_RATE = 7.0;
    const DEFAULT_OUR_RATE = 15.0;

    /**
     * @param array $passengers строки пассажиров (manifest_price/our_price/attendance/refund_status/
     *                          agent_contract_id/agent_raw/pay_note)
     * @param array $agents     [id => [side, rate, alias, src, name]] либо строки report_agent_contracts
     * @param array $opts       disp_rate, our_rate, cash, other_costs, station_sales[[amount, rate, name]]
     */
    public static function calculate(array $passengers, array $agents, array $opts = []): array
    {
        $agents = self::normalizeAgents($agents);
        $dispRate = self::num($opts['disp_rate'] ?? self::DEFAULT_DISP_RATE);
        $ourRate = self::num($opts['our_rate'] ?? self::DEFAULT_OUR_RATE);
        $cash = self::money($opts['cash'] ?? 0);
        $otherCosts = self::money($opts['other_costs'] ?? 0);

        // ── по пассажирам ──
        $rows = [];
        $warnings = [];
        $counts = ['present' => 0, 'absent' => 0, 'unknown' => 0, 'refunds' => 0];

        foreach ($passengers as $p) {
            $attendance = in_array($p['attendance'] ?? '', ['present', 'absent'], true) ? $p['attendance'] : 'unknown';
            $refunded = ($p['refund_status'] ?? 'none') === 'completed';
            $counts[$attendance]++;
            if ($refunded) $counts['refunds']++;

            $agentId = (int) ($p['agent_contract_id'] ?? 0);
            $rawAgent = (string) ($p['agent_raw'] ?? '');
            $payNote = (string) ($p['pay_note'] ?? '');
            // Ручная пометка кассира сильнее старого автоприсвоения: это позволяет
            // исправить строку «Агент/кассир» без отдельного сброса назначения.
            $commentMatch = self::matchAgent('', $payNote, $agents);
            if ($commentMatch) {
                $agentId = $commentMatch;
            } elseif (!isset($agents[$agentId])) {
                $agentId = self::matchAgent($rawAgent, $payNote, $agents);
            }
            $agent = $agents[$agentId] ?? null;
            $side = $agent ? $agent['side'] : 'none';

            $manifestPrice = self::money($p['manifest_price'] ?? $p['price'] ?? 0);
            $ourRaw = $p['our_price'] ?? null;
            $ourPrice = ($ourRaw === null || $ourRaw === '') ? $manifestPrice : self::money($ourRaw);

            $mani = $manifestPrice;
            $ours = $ourPrice;
            $excluded = false;
            $noshowKept = false;
            if ($refunded) {                            // возврат оформлен — строки нет вовсе
                $mani = 0.0; $ours = 0.0; $excluded = true;
            } elseif ($attendance === 'absent') {       // не вёз: ведомость 0, деньги за нами
                $mani = 0.0; $noshowKept = true;
            }

            // База комиссии агента = сколько он реально продал. У неявки — цена билета
            // (агент своё получил при продаже), у ехавших: наша цена / цена ведомости.
            $saleBase = $noshowKept ? $ourPrice : ($side === 'us' ? $ours : $mani);
            $commission = $agent ? self::round2($saleBase * self::num($agent['rate']) / 100) : 0.0;

            $rows[] = [
                'id' => (int) ($p['id'] ?? 0),
                'agent_id' => $agentId,
                'agent_name' => $agent['name'] ?? '',
                'agent_channel' => trim((string) ($p['agent_raw'] ?? '')), // исходный канал из ведомости — для аналитики
                'settlement_side' => $side,
                'manifest_price' => self::round2($mani),
                'our_price' => self::round2($ours),
                'sale_base' => self::round2($saleBase),
                'commission' => $commission,
                // разница цен — только у ехавших
                'extra' => $excluded || $noshowKept ? 0.0 : self::round2($ours - $mani),
                // доход с неявки = цена − комиссия агента
                'noshow_net' => $noshowKept ? self::round2($ourPrice - $commission) : 0.0,
                'excluded' => $excluded,
                'noshow' => $noshowKept,
                // legacy-ключи для существующей вьюхи
                'carrier_base' => self::round2($mani),
                'sale_gross' => self::round2($noshowKept ? 0.0 : $ours),
                'profit' => 0.0,
            ];

            if ($attendance === 'unknown') $warnings[] = 'Не отмечена явка: ' . ($p['name'] ?? 'пассажир');
            if ($attendance === 'present' && $refunded) $warnings[] = 'Явка и возврат одновременно: ' . ($p['name'] ?? 'пассажир');
            if (!$agent && !$excluded) $warnings[] = 'Не определён агент: ' . ($p['name'] ?? 'пассажир');
        }

        $sum = static function (callable $f) use ($rows): float {
            $s = 0.0;
            foreach ($rows as $r) $s += $f($r);
            return $s;
        };

        // ── продажи по сторонам (неявки в продажи не входят: ведомость по ним 0) ──
        $manifestTotal = $sum(fn($r) => $r['manifest_price']);
        $ourSales = $sum(fn($r) => ($r['settlement_side'] === 'us' && !$r['noshow']) ? $r['manifest_price'] : 0.0);
        $carrierSales = $sum(fn($r) => ($r['settlement_side'] === 'carrier' && !$r['noshow']) ? $r['manifest_price'] : 0.0);
        $noAgentSales = $sum(fn($r) => ($r['settlement_side'] === 'none' && !$r['noshow']) ? $r['manifest_price'] : 0.0);

        // ── автовокзалы: продажи мимо ведомости, напрямую перевозчику ──
        $stationSales = [];
        foreach ((array) ($opts['station_sales'] ?? []) as $s) {
            $amount = self::money($s['amount'] ?? 0);
            $commission = self::round2($amount * self::num($s['rate'] ?? 0) / 100);
            $stationSales[] = [
                'station_id' => (int) ($s['station_id'] ?? 0),
                'name' => (string) ($s['name'] ?? ''),
                'rate' => self::num($s['rate'] ?? 0),
                'amount' => $amount,
                'commission' => $commission,
                'net' => self::round2($amount - $commission),
            ];
        }
        $stationsTotal = 0.0; $stationAgentCost = 0.0;
        foreach ($stationSales as $s) { $stationsTotal += $s['amount']; $stationAgentCost += $s['commission']; }

        // ── оборот и сборы ──
        $turnover = $manifestTotal + $stationsTotal;          // база диспетчерских
        $dispatch = self::round2($turnover * $dispRate / 100); // 7% со ВСЕГО оборота рейса
        $ourCommission = self::round2($ourSales * $ourRate / 100); // 15% только с продаж Терры
        $extra = $sum(fn($r) => $r['extra']);

        // ── доход с неявок, раздельно по стороне ──
        $noshowOurs = $sum(fn($r) => ($r['noshow'] && $r['settlement_side'] !== 'carrier') ? $r['noshow_net'] : 0.0);
        $noshowCarrier = $sum(fn($r) => ($r['noshow'] && $r['settlement_side'] === 'carrier') ? $r['noshow_net'] : 0.0);
        $noshowIncome = $noshowOurs + $noshowCarrier;

        // ── комиссии агентам (неявки включены: агент получил при продаже) ──
        $byAgent = [];
        foreach ($rows as $r) {
            if (!$r['agent_id'] || $r['excluded']) continue;
            $k = $r['agent_id'];
            if (!isset($byAgent[$k])) {
                $byAgent[$k] = ['agent_id' => $k, 'name' => $r['agent_name'],
                    'side' => $r['settlement_side'], 'pax' => 0, 'sales' => 0.0, 'commission' => 0.0];
            }
            $byAgent[$k]['pax']++;
            $byAgent[$k]['sales'] += $r['sale_base'];
            $byAgent[$k]['commission'] += $r['commission'];
        }
        $ourAgentCost = 0.0; $carrierAgentCost = 0.0;
        foreach ($byAgent as &$a) {
            $a['sales'] = self::round2($a['sales']);
            $a['commission'] = self::round2($a['commission']);
            $a['rate'] = self::num($agents[$a['agent_id']]['rate'] ?? 0);
            // «К перечислению перевозчику» есть только у агентов ПЕРЕВОЗЧИКА: они собирают
            // деньги сами. Продажи Терры перевозчику отдельно не перечисляются — они внутри
            // общего долга Терры, поэтому net у них null.
            $a['net'] = $a['side'] === 'carrier' ? self::round2($a['sales'] - $a['commission']) : null;
            if ($a['side'] === 'carrier') $carrierAgentCost += $a['commission']; else $ourAgentCost += $a['commission'];
        }
        unset($a);
        // По ехавшим: комиссия агента с неявки уже вычтена внутри noshow_net — иначе двойной счёт.
        $ourAgentRideCost = $sum(fn($r) => ($r['settlement_side'] === 'us' && !$r['noshow'] && !$r['excluded']) ? $r['commission'] : 0.0);

        // ── итоги ──
        // Мы платим только за перевезённых: неявки по продажам перевозчика удерживаем из его долга.
        $toCarrier = self::round2($manifestTotal - $dispatch - $ourCommission - $cash - $carrierSales - $noshowCarrier);
        $ourProfit = self::round2($dispatch + $ourCommission + $extra + $noshowIncome - $ourAgentRideCost);
        // Наличные НЕ вычитаем: это форма оплаты продаж Терры, уже внутри оборота.
        $carrierEarn = self::round2($turnover - $dispatch - $ourCommission - $carrierAgentCost - $stationAgentCost - $otherCosts);
        $stationsNet = self::round2($stationsTotal - $stationAgentCost);

        $pax = 0; $noshowCount = 0;
        foreach ($rows as $r) { if (!$r['excluded']) $pax++; if ($r['noshow']) $noshowCount++; }

        $totals = [
            'formula_version' => self::FORMULA_VERSION,
            'passengers' => count($passengers), 'pax' => $pax,
            'present' => $counts['present'], 'absent' => $counts['absent'],
            'unknown' => $counts['unknown'], 'refunds' => $counts['refunds'], 'noshow_count' => $noshowCount,
            'disp_rate' => $dispRate, 'our_rate' => $ourRate,
            // продажи
            'manifest_total' => self::round2($manifestTotal),
            'stations_total' => self::round2($stationsTotal),
            'turnover' => self::round2($turnover),
            'our_sales' => self::round2($ourSales),
            'carrier_sales' => self::round2($carrierSales),
            'no_agent_sales' => self::round2($noAgentSales),
            // сборы и комиссии
            'dispatch_fee' => $dispatch,
            'our_commission' => $ourCommission,
            'our_agent_cost' => self::round2($ourAgentCost),
            'our_agent_ride_cost' => self::round2($ourAgentRideCost),
            'carrier_agent_cost' => self::round2($carrierAgentCost),
            'station_agent_cost' => self::round2($stationAgentCost),
            // доп. доходы
            'extra' => self::round2($extra),
            'noshow_income' => self::round2($noshowIncome),
            'noshow_ours' => self::round2($noshowOurs),
            'noshow_carrier' => self::round2($noshowCarrier),
            // деньги
            'cash' => $cash, 'other_costs' => $otherCosts,
            'to_carrier' => $toCarrier,
            'our_profit' => $ourProfit,
            'carrier_earn' => $carrierEarn,
            // долги каналов за рейс
            'terra_sold' => self::round2($ourSales),
            'carrier_agents_sold' => self::round2($carrierSales),
            'stations_net' => $stationsNet,
        ];
        // Совместимость с прежней вьюхой/снимками (старые имена ключей).
        $totals += [
            'carrier_direct_sales' => $totals['carrier_sales'],
            'commercial_fee' => $totals['our_commission'],
            'agent_commission' => self::round2($ourAgentCost + $carrierAgentCost),
            'carrier_due' => $totals['to_carrier'],
            'carrier_due_before_offset' => $totals['to_carrier'],
            'profit' => $totals['our_profit'],
            'direct_dispatch_offset' => 0.0,
            'direct_dispatch_receivable' => 0.0,
        ];

        // ── КТО ДОЛЖЕН ЗА РЕЙС (для отчёта перевозчику) ──
        // Три источника: Терра (собрала свои продажи), агенты перевозчика и автовокзалы
        // (собрали сами, деньги у них). СХОДИМОСТЬ не «долги = доход»: наличные перевозчик
        // уже получил авансом, неявки по его продажам мы удержали, прочие расходы — его.
        //   доход = долги + наличные + удержанные неявки − прочие расходы
        $carrierAgentsNet = self::round2($carrierSales - $carrierAgentCost);
        $debtsTotal = self::round2($toCarrier + $carrierAgentsNet + $stationsNet);
        $reconciled = self::round2($debtsTotal + $cash + $noshowCarrier - $otherCosts);
        $debts = [
            'terra' => $toCarrier,                        // к перечислению от нас
            'carrier_agents' => $carrierAgentsNet,        // продажи его агентов − их комиссии
            'stations' => $stationsNet,                   // продажи вокзалов − их комиссии
            'total' => $debtsTotal,
            'cash_received' => $cash,                     // получено авансом на руки
            'noshow_withheld' => self::round2($noshowCarrier), // удержано за неявки по его продажам
            'other_costs' => $otherCosts,
            'reconciled_to' => $reconciled,
            'matches_carrier_earn' => abs($reconciled - $carrierEarn) < 0.01,
        ];
        $totals['carrier_agents_net'] = $carrierAgentsNet;
        $totals['debts_total'] = $debtsTotal;
        $totals['debts_reconciled'] = $debts['matches_carrier_earn'];

        return [
            'totals' => $totals,
            'passengers' => $rows,
            'by_agent' => array_values($byAgent),
            'station_sales' => $stationSales,
            'debts' => $debts,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    // Где искать агента: наши — в автозаполненном поле «Агент/кассир», агенты перевозчика —
    // в ручном комментарии кассира (если источник не задан явно).
    public static function agentSrc(array $agent): string
    {
        $src = (string) ($agent['src'] ?? '');
        if (in_array($src, ['raw', 'comment', 'both'], true)) return $src;
        return ($agent['side'] ?? 'us') === 'carrier' ? 'comment' : 'raw';
    }

    // Сопоставление агента по алиасам. Ручной комментарий СИЛЬНЕЕ автозаполненного поля:
    // сначала агенты, ищущиеся по комментарию, потом остальные. Возвращает id или 0.
    public static function matchAgent(string $raw, string $comment, array $agents): int
    {
        $agents = self::normalizeAgents($agents);
        foreach ([true, false] as $commentFirst) {
            foreach ($agents as $id => $agent) {
                $isComment = self::agentSrc($agent) === 'comment';
                if ($isComment !== $commentFirst) continue;
                if (self::matchIn($agent, $raw, $comment)) return (int) $id;
            }
        }
        return 0;
    }

    private static function matchIn(array $agent, string $raw, string $comment): bool
    {
        $src = self::agentSrc($agent);
        $text = self::norm($src === 'raw' ? $raw : ($src === 'comment' ? $comment : $raw . ' ' . $comment));
        if ($text === '') return false;
        foreach (preg_split('/[|,;]+/u', (string) $agent['alias']) ?: [] as $key) {
            $key = trim(self::norm($key));
            if ($key !== '' && str_contains($text, $key)) return true;
        }
        return false;
    }

    // Приводим агентов к единому виду: принимаем и новый формат, и строки report_agent_contracts.
    private static function normalizeAgents(array $agents): array
    {
        $out = [];
        foreach ($agents as $id => $a) {
            if (!is_array($a)) continue;
            $side = ($a['side'] ?? $a['settlement_side'] ?? 'us') === 'carrier' ? 'carrier' : 'us';
            $out[(int) ($a['id'] ?? $id)] = [
                'name' => (string) ($a['name'] ?? $a['agent_name'] ?? ''),
                'side' => $side,
                'rate' => self::num($a['rate'] ?? $a['agent_commission_rate'] ?? 0),
                'alias' => (string) ($a['alias'] ?? $a['aliases'] ?? ''),
                'src' => (string) ($a['src'] ?? $a['match_src'] ?? ''),
            ];
        }
        return $out;
    }

    private static function norm(string $t): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($t), 'UTF-8'));
    }

    private static function money(mixed $v): float
    {
        return round((float) str_replace(',', '.', (string) $v), 2);
    }

    private static function num(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }

    private static function round2(float $v): float
    {
        return round($v, 2);
    }
}
