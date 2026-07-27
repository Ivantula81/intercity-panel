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
            $version = reporting_save_snapshot($manifestId);
            json_out(['ok'=>true,'version'=>$version,'calculation'=>reporting_calculate_manifest($manifestId)]);

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
