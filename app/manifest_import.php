<?php

// Загрузка CSV-ведомости в БД + авто-подстановка водителя/телефона из справочника автобусов.
// Возвращает id ведомости. Бросает Exception при ошибке.

function import_manifest_csv(array $file): int
{
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить файл (код ' . (int) $file['error'] . ').');
    }
    if ((int) $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Файл больше 10 МБ.');
    }

    require_once PANEL_ROOT . '/lib/ManifestParser.php';
    $parser = new ManifestParser();
    $parsed = $parser->parseFile($file['tmp_name'], $file['name']);

    $departure = null;
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', (string) $parsed['trip']['departure_at'], $mm) && (int) $mm[3] >= 2000) {
        $departure = sprintf('%s-%s-%s %02d:%02d:00', $mm[3], $mm[2], $mm[1], $mm[4] ?? 0, $mm[5] ?? 0);
    }

    // авто-поиск автобуса в справочнике → телефон водителя
    $driverPhone = '';
    $busStr = mb_strtolower($parsed['trip']['bus'] . ' ' . $parsed['trip']['drivers'], 'UTF-8');
    if (trim($busStr) !== '') {
        foreach (db()->query('SELECT plate, code, driver_phone FROM buses')->fetchAll() as $b) {
            foreach ([$b['plate'], $b['code']] as $needle) {
                $needle = mb_strtolower(trim((string) $needle), 'UTF-8');
                if ($needle !== '' && mb_strlen($needle) >= 3 && str_contains($busStr, $needle)) {
                    $driverPhone = $b['driver_phone'];
                    break 2;
                }
            }
        }
    }

    $pdo = db();
    $pdo->prepare('INSERT INTO manifests (file_name, trip_number, route, departure_at, carrier, bus, drivers, driver_phone) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $parsed['file_name'], $parsed['trip']['id'], $parsed['trip']['route'],
            $departure, $parsed['trip']['carrier'], $parsed['trip']['bus'], $parsed['trip']['drivers'], $driverPhone,
        ]);
    $manifestId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO passengers (manifest_id, seat, name, phone, doc, ticket, from_stop, to_stop, status, sort, from_id, to_id, birthdate, price, citizenship, pay_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    require_once PANEL_ROOT . '/app/contacts.php';
    $newStations = []; // id => название (для авто-заведения в справочник)
    foreach ($parsed['passengers'] as $i => $p) {
        $ins->execute([
            $manifestId, preg_replace('/^место\s*/ui', '', $p['seat']), $p['name'], $p['phone'],
            $p['doc'] ?? '', $p['ticket'] ?? '', $p['from'], $p['to'], $p['status'], $i,
            $p['from_id'] ?? null, $p['to_id'] ?? null,
            $p['birthdate'] ?? '', $p['price'] ?? null, $p['citizenship'] ?? '', $p['pay_note'] ?? '',
        ]);
        if (!empty($p['phone_valid'])) {
            contact_log_trip($p['phone'], $p['name'], $parsed['trip']['route']);
        }
        if (!empty($p['from_id']) && trim($p['from']) !== '') {
            $newStations[(int) $p['from_id']] = trim($p['from']);
        }
    }

    // Авто-заведение станций в справочник по id: новые появляются сами, адрес заполняет оператор
    foreach ($newStations as $gdsId => $stationName) {
        $exists = $pdo->prepare('SELECT id FROM stops WHERE gds_id = ?');
        $exists->execute([$gdsId]);
        if ($exists->fetchColumn()) continue;

        $byName = $pdo->prepare('SELECT id FROM stops WHERE station = ? AND gds_id IS NULL');
        $byName->execute([$stationName]);
        $sid = $byName->fetchColumn();
        if ($sid) {
            $pdo->prepare('UPDATE stops SET gds_id = ? WHERE id = ?')->execute([$gdsId, $sid]);
        } else {
            $city = trim(preg_split('/[\("«]/u', $stationName)[0] ?? '');
            $pdo->prepare('INSERT IGNORE INTO stops (station, city, gds_id) VALUES (?,?,?)')
                ->execute([$stationName, $city, $gdsId]);
        }
    }

    return $manifestId;
}
