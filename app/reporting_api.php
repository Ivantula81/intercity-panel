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
            // pay_note — ТОТ САМЫЙ комментарий, что участвует в матчинге (колонка BIRTHPLACE_NAME
            // из ведомости). Оператор вписывает сюда «Гоубас Ванюк» и этим назначает агента
            // перевозчика — комментарий сильнее поля «Агент/кассир». Поэтому он редактируемый.
            $allowed = ['seat','name','phone','birthdate','from_stop','to_stop','agent_contract_id','attendance',
                'refund_status','manifest_price','our_price','finance_comment','agent_raw','pay_note'];
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

        // «+ Добавить агента» — как в прототипе: появляется пустая СТРОКА в таблице,
        // все поля (включая «где искать» и %) правятся на месте. Отдельной формы нет.
        case 'agent.create':
            $side = ($body['side'] ?? '') === 'carrier' ? 'carrier' : 'ours';
            $scId = (int) ($body['scenario_id'] ?? 0) ?: reporting_default_scenario_id();
            // имя агента уникально — подбираем свободное «Новый агент N»
            $base = 'Новый агент';
            $name = $base;
            for ($i = 2; $i < 100; $i++) {
                $ex = db()->prepare('SELECT id FROM report_agents WHERE name=?');
                $ex->execute([$name]);
                if (!$ex->fetchColumn()) break;
                $name = $base . ' ' . $i;
            }
            db()->prepare('INSERT INTO report_agents (name, aliases) VALUES (?, "")')->execute([$name]);
            $aid = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO report_agent_contracts
                (agent_id,title,settlement_side,agent_commission_rate,commercial_rate,dispatch_rate,match_src,scenario_id)
                VALUES (?,?,?,0,15,7,?,?)')
                ->execute([$aid, 'Основной договор', $side, $side === 'carrier' ? 'comment' : 'raw', $scId]);
            $cid = (int) db()->lastInsertId();
            // origin_id = свой id: по нему назначение переносится между сценариями
            db()->prepare('UPDATE report_agent_contracts SET origin_id=? WHERE id=?')->execute([$cid, $cid]);
            json_out(['ok'=>true,'id'=>$cid]);

        case 'station.create':
            $scId = (int) ($body['scenario_id'] ?? 0) ?: reporting_default_scenario_id();
            $base = 'Новый автовокзал';
            $name = $base;
            for ($i = 2; $i < 100; $i++) {
                $ex = db()->prepare('SELECT id FROM report_stations WHERE name=? AND scenario_id<=>?');
                $ex->execute([$name, $scId]);
                if (!$ex->fetchColumn()) break;
                $name = $base . ' ' . $i;
            }
            db()->prepare('INSERT INTO report_stations (name, rate, scenario_id) VALUES (?,0,?)')->execute([$name, $scId]);
            json_out(['ok'=>true,'id'=>(int) db()->lastInsertId()]);

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

        // ── Сценарии расчёта: наборы настроек (перевозчики, агенты, вокзалы) ──
        case 'scenario.create':   // копия активного: origin_id договоров переносится,
                                  // поэтому назначения агентов в строках не слетают
            $newId = reporting_scenario_copy((int) ($body['from'] ?? reporting_default_scenario_id()),
                (string) ($body['name'] ?? ''));
            json_out(['ok'=>true,'id'=>$newId,'scenarios'=>reporting_scenario_list()]);

        case 'scenario.rename':
            $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 64);
            if ($name === '') throw new RuntimeException('Название сценария не может быть пустым.');
            db()->prepare('UPDATE report_scenarios SET name=? WHERE id=?')->execute([$name, (int) ($body['id'] ?? 0)]);
            json_out(['ok'=>true]);

        case 'scenario.delete':
            $sid = (int) ($body['id'] ?? 0);
            if (count(reporting_scenario_list()) < 2) throw new RuntimeException('Нельзя удалить единственный сценарий.');
            $used = db()->prepare('SELECT COUNT(*) FROM manifests WHERE report_scenario_id=?');
            $used->execute([$sid]);
            if ((int) $used->fetchColumn()) throw new RuntimeException('По этому сценарию считаются рейсы — сначала переключите их на другой.');
            db()->prepare('DELETE FROM report_agent_contracts WHERE scenario_id=?')->execute([$sid]);
            db()->prepare('DELETE FROM report_stations WHERE scenario_id=?')->execute([$sid]);
            db()->prepare('DELETE FROM report_scenario_carriers WHERE scenario_id=?')->execute([$sid]);
            db()->prepare('DELETE FROM report_scenarios WHERE id=?')->execute([$sid]);
            json_out(['ok'=>true,'scenarios'=>reporting_scenario_list()]);

        case 'scenario.apply':    // каким сценарием считать конкретный рейс
            reporting_set_scenario((int) ($body['manifest_id'] ?? 0), (int) ($body['scenario_id'] ?? 0));
            json_out(['ok'=>true,'calculation'=>reporting_calculate_manifest((int) ($body['manifest_id'] ?? 0))]);

        // Ставки перевозчика в СЦЕНАРИИ (в общей таблице carriers их не трогаем —
        // она используется документами и справочниками).
        case 'scenario_carrier.save':
            $sid = (int) ($body['scenario_id'] ?? reporting_default_scenario_id());
            $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 255);
            if ($name === '') throw new RuntimeException('Укажите перевозчика.');
            $disp = max(0, round((float) str_replace(',', '.', (string) ($body['disp_rate'] ?? 7)), 4));
            $our  = max(0, round((float) str_replace(',', '.', (string) ($body['our_rate'] ?? 15)), 4));
            $id = (int) ($body['id'] ?? 0);
            if ($id) db()->prepare('UPDATE report_scenario_carriers SET name=?, disp_rate=?, our_rate=? WHERE id=?')->execute([$name,$disp,$our,$id]);
            else db()->prepare('INSERT INTO report_scenario_carriers (scenario_id,name,disp_rate,our_rate) VALUES (?,?,?,?)')->execute([$sid,$name,$disp,$our]);
            json_out(['ok'=>true]);

        case 'scenario_carrier.delete':
            db()->prepare('DELETE FROM report_scenario_carriers WHERE id=?')->execute([(int) ($body['id'] ?? 0)]);
            json_out(['ok'=>true]);

        // Ставки перевозчика. Базы РАЗНЫЕ: disp_rate с оборота (ведомость + вокзалы),
        // our_rate только с продаж Терры — см. ReportingCalculator.
        case 'carrier.rates':
            $field = (string) ($body['field'] ?? '');
            if (!in_array($field, ['disp_rate', 'our_rate'], true)) throw new RuntimeException('Поле недоступно для изменения.');
            $rate = max(0, round((float) str_replace(',', '.', (string) ($body['value'] ?? 0)), 4));
            db()->prepare("UPDATE carriers SET `$field`=? WHERE id=?")->execute([$rate, (int) ($body['id'] ?? 0)]);
            json_out(['ok'=>true]);

        // Шаблон ссылки на систему автовокзала: вместо номера рейса — {id}
        case 'source_url.save':
            $url = trim((string) ($body['url'] ?? ''));
            if ($url !== '' && !str_contains($url, '{id}')) throw new RuntimeException('В ссылке должен быть {id} — вместо него подставится номер рейса.');
            if ($url !== '' && !preg_match('~^https?://~i', $url)) throw new RuntimeException('Ссылка должна начинаться с http:// или https://');
            opt_set('artmark_url_template', mb_substr($url, 0, 500));
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
