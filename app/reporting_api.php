<?php

require_once PANEL_ROOT . '/app/reporting_service.php';

csrf_check();
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string) ($_GET['a'] ?? $body['action'] ?? '');

try {
    switch ($action) {
        case 'passenger.update':
            $id = (int) ($body['id'] ?? 0);
            $field = (string) ($body['field'] ?? '');
            $allowed = ['seat','name','phone','birthdate','from_stop','to_stop','agent_contract_id','attendance',
                'refund_status','manifest_price','our_price','finance_comment','agent_raw'];
            if (!in_array($field, $allowed, true)) throw new RuntimeException('Поле недоступно для изменения.');
            $value = $body['value'] ?? '';
            if ($field === 'attendance' && !in_array($value, ['unknown','present','absent'], true)) $value = 'unknown';
            if ($field === 'refund_status' && !in_array($value, ['none','completed'], true)) $value = 'none';
            if ($field === 'agent_contract_id') $value = (int) $value ?: null;
            if (in_array($field, ['manifest_price','our_price'], true)) {
                $value = trim((string) $value) === '' ? null : round((float) str_replace(',', '.', (string) $value), 2);
            }
            db()->prepare("UPDATE passengers SET `$field`=? WHERE id=?")->execute([$value,$id]);
            if ($field === 'manifest_price') db()->prepare('UPDATE passengers SET price=? WHERE id=?')->execute([$value,$id]);
            json_out(['ok'=>true]);

        case 'passenger.add':
            $manifestId = (int) ($body['manifest_id'] ?? 0);
            db()->prepare("INSERT INTO passengers (manifest_id,name,sort,attendance,refund_status)
                SELECT ?,'Новый пассажир',COALESCE(MAX(sort),0)+1,'unknown','none' FROM passengers WHERE manifest_id=?")
                ->execute([$manifestId,$manifestId]);
            json_out(['ok'=>true,'id'=>(int) db()->lastInsertId()]);

        case 'passenger.delete':
            db()->prepare('DELETE FROM passengers WHERE id=?')->execute([(int) ($body['id'] ?? 0)]);
            json_out(['ok'=>true]);

        case 'manifest.update':
            $field = (string) ($body['field'] ?? '');
            $allowed = ['trip_number','route','departure_at','carrier','bus','drivers','reporting_note'];
            if (!in_array($field, $allowed, true)) throw new RuntimeException('Поле рейса недоступно для изменения.');
            $value = trim((string) ($body['value'] ?? ''));
            if ($field === 'departure_at') {
                $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $value);
                if (!$dt) throw new RuntimeException('Некорректная дата рейса.');
                $value = $dt->format('Y-m-d H:i:00');
            }
            db()->prepare("UPDATE manifests SET `$field`=? WHERE id=?")
                ->execute([mb_substr($value,0,$field === 'reporting_note' ? 5000 : 255),(int) $body['id']]);
            json_out(['ok'=>true]);

        case 'calculate':
            json_out(['ok'=>true,'calculation'=>reporting_calculate_manifest((int) ($body['manifest_id'] ?? 0))]);

        case 'snapshot.save':
            $manifestId = (int) ($body['manifest_id'] ?? 0);
            // scenario — имя расчёта: одну ведомость можно посчитать несколькими вариантами
            $version = reporting_save_snapshot($manifestId, (string) ($body['scenario'] ?? 'Вариант 1'));
            json_out(['ok'=>true,'version'=>$version,'scenarios'=>reporting_scenarios($manifestId),
                'calculation'=>reporting_calculate_manifest($manifestId)]);

        // Список сохранённых сценариев рейса (последняя версия каждого) — для переключения и сравнения
        case 'snapshot.list':
            json_out(['ok'=>true,'scenarios'=>reporting_scenarios((int) ($body['manifest_id'] ?? 0))]);

        // Свод по месяцу: строка = рейс, считается по сохранённым снимкам
        case 'month.summary':
            $sum = reporting_month_summary((string) ($body['month'] ?? ''), (string) ($body['scenario'] ?? ''));
            json_out(['ok'=>true,'month'=>$body['month'] ?? '','months'=>reporting_months(),
                'rows'=>$sum['rows'],'totals'=>$sum['totals']]);

        case 'cash.add':
            $manifestId = (int) ($body['manifest_id'] ?? 0);
            $amount = round((float) str_replace(',', '.', (string) ($body['amount'] ?? 0)), 2);
            if ($amount <= 0) throw new RuntimeException('Укажите сумму наличных.');
            $recipient = in_array($body['recipient'] ?? '', ['us','carrier','agent'], true) ? $body['recipient'] : 'us';
            db()->prepare('INSERT INTO manifest_cash_entries (manifest_id,passenger_id,amount,recipient,note,actor) VALUES (?,?,?,?,?,?)')
                ->execute([$manifestId,(int) ($body['passenger_id'] ?? 0) ?: null,$amount,$recipient,
                    mb_substr(trim((string) ($body['note'] ?? '')),0,500),current_user_name()]);
            json_out(['ok'=>true,'id'=>(int) db()->lastInsertId()]);

        case 'cash.delete':
            db()->prepare('DELETE FROM manifest_cash_entries WHERE id=?')->execute([(int) ($body['id'] ?? 0)]);
            json_out(['ok'=>true]);

        // ── Справочник агентов: всё редактируемо на месте (владелец настраивает сам) ──
        case 'agent.update':
            $cid = (int) ($body['id'] ?? 0);
            $field = (string) ($body['field'] ?? '');
            $value = $body['value'] ?? '';
            // поля агента (в report_agents) и поля договора (в report_agent_contracts)
            if (in_array($field, ['name', 'aliases'], true)) {
                $aSt = db()->prepare('SELECT agent_id FROM report_agent_contracts WHERE id=?');
                $aSt->execute([$cid]);
                $aid = (int) $aSt->fetchColumn();
                if (!$aid) throw new RuntimeException('Агент не найден.');
                db()->prepare("UPDATE report_agents SET `$field`=? WHERE id=?")
                    ->execute([mb_substr(trim((string) $value), 0, $field === 'name' ? 255 : 2000), $aid]);
                json_out(['ok'=>true]);
            }
            $allowed = ['title','settlement_side','carrier','agent_commission_rate','agent_commission_basis',
                'commercial_rate','dispatch_rate','dispatch_settlement','match_src','active'];
            if (!in_array($field, $allowed, true)) throw new RuntimeException('Поле недоступно для изменения.');
            if ($field === 'settlement_side') $value = $value === 'carrier' ? 'carrier' : 'ours';
            if ($field === 'match_src') $value = in_array($value, ['raw','comment','both'], true) ? $value : null;
            if ($field === 'agent_commission_basis') $value = $value === 'manifest_price' ? 'manifest_price' : 'our_price';
            if ($field === 'dispatch_settlement') $value = $value === 'receivable' ? 'receivable' : 'offset';
            if ($field === 'active') $value = (int) (bool) $value;
            if (in_array($field, ['agent_commission_rate','commercial_rate','dispatch_rate'], true)) {
                $value = max(0, round((float) str_replace(',', '.', (string) $value), 4));
            }
            if (in_array($field, ['title','carrier'], true)) $value = mb_substr(trim((string) $value), 0, 255);
            db()->prepare("UPDATE report_agent_contracts SET `$field`=? WHERE id=?")->execute([$value, $cid]);
            json_out(['ok'=>true]);

        case 'agent.delete':
            $cid = (int) ($body['id'] ?? 0);
            $aSt = db()->prepare('SELECT agent_id FROM report_agent_contracts WHERE id=?');
            $aSt->execute([$cid]);
            $aid = (int) $aSt->fetchColumn();
            // пассажиров не ломаем: снимаем ссылку на удаляемый договор
            db()->prepare('UPDATE passengers SET agent_contract_id=NULL WHERE agent_contract_id=?')->execute([$cid]);
            db()->prepare('DELETE FROM report_agent_contracts WHERE id=?')->execute([$cid]);
            // агента удаляем, только если у него не осталось договоров
            if ($aid) {
                $left = db()->prepare('SELECT COUNT(*) FROM report_agent_contracts WHERE agent_id=?');
                $left->execute([$aid]);
                if (!(int) $left->fetchColumn()) db()->prepare('DELETE FROM report_agents WHERE id=?')->execute([$aid]);
            }
            json_out(['ok'=>true]);

        // ── Справочник автовокзалов: правка и удаление ──
        case 'station.update':
            $sid = (int) ($body['id'] ?? 0);
            $field = (string) ($body['field'] ?? '');
            if (!in_array($field, ['name','rate','note','active'], true)) throw new RuntimeException('Поле недоступно для изменения.');
            $value = $body['value'] ?? '';
            if ($field === 'rate') $value = max(0, round((float) str_replace(',', '.', (string) $value), 4));
            elseif ($field === 'active') $value = (int) (bool) $value;
            else $value = mb_substr(trim((string) $value), 0, 255);
            db()->prepare("UPDATE report_stations SET `$field`=? WHERE id=?")->execute([$value, $sid]);
            json_out(['ok'=>true]);

        case 'station.delete':
            $sid = (int) ($body['id'] ?? 0);
            $used = db()->prepare('SELECT COUNT(*) FROM manifest_station_sales WHERE station_id=?');
            $used->execute([$sid]);
            if ((int) $used->fetchColumn()) {
                throw new RuntimeException('По этому автовокзалу уже есть продажи на рейсах. Скройте его вместо удаления.');
            }
            db()->prepare('DELETE FROM report_stations WHERE id=?')->execute([$sid]);
            json_out(['ok'=>true]);

        // Продажи автовокзалов на рейс: мимо ведомости, деньги напрямую перевозчику.
        // Храним ТОЛЬКО сумму — процент всегда берётся из справочника (report_stations),
        // поэтому правка ставки пересчитывает все рейсы разом.
        case 'station_sale.add':
            $manifestId = (int) ($body['manifest_id'] ?? 0);
            $stationId = (int) ($body['station_id'] ?? 0);
            $amount = round((float) str_replace(',', '.', (string) ($body['amount'] ?? 0)), 2);
            if (!$stationId) throw new RuntimeException('Выберите автовокзал.');
            if ($amount <= 0) throw new RuntimeException('Укажите сумму продаж.');
            $st = db()->prepare('SELECT id FROM report_stations WHERE id=? AND active=1');
            $st->execute([$stationId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Автовокзал не найден или скрыт.');
            db()->prepare('INSERT INTO manifest_station_sales (manifest_id,station_id,amount,note,actor) VALUES (?,?,?,?,?)')
                ->execute([$manifestId,$stationId,$amount,mb_substr(trim((string) ($body['note'] ?? '')),0,255),current_user_name()]);
            json_out(['ok'=>true,'id'=>(int) db()->lastInsertId(),
                'calculation'=>reporting_calculate_manifest($manifestId)]);

        case 'station_sale.delete':
            $id = (int) ($body['id'] ?? 0);
            $mSt = db()->prepare('SELECT manifest_id FROM manifest_station_sales WHERE id=?');
            $mSt->execute([$id]);
            $manifestId = (int) $mSt->fetchColumn();
            db()->prepare('DELETE FROM manifest_station_sales WHERE id=?')->execute([$id]);
            json_out(['ok'=>true,'calculation'=>$manifestId ? reporting_calculate_manifest($manifestId) : null]);
    }
    json_out(['ok'=>false,'error'=>'Неизвестное действие'],404);
} catch (Throwable $e) {
    json_out(['ok'=>false,'error'=>$e->getMessage()],422);
}
