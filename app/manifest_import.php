<?php

// Тянет ведомость по номеру рейса из системы автовокзала (Артмарк), csv=open → CSV.
// Возвращает путь к временному UTF-8 файлу (вызывающий обязан удалить). Бросает при ошибке.
// URL переопределяется в env (ARTMARK_URL). Живёт здесь, а не в api.php, потому что
// используется и API (manifest.pull), и отчётностью (добавление рейса по номеру).
if (!function_exists('artmark_fetch_manifest')) {
    function artmark_fetch_manifest(string $tripId): string
    {
        $tripId = preg_replace('/\D+/', '', $tripId);
        if ($tripId === '') throw new RuntimeException('Укажите номер рейса.');
        // Шаблон ссылки задаётся в «Отчётность → Настройки → Источник ведомостей» ({id} = номер рейса).
        $tpl = function_exists('opt') ? trim((string) opt('artmark_url_template', '')) : '';
        if ($tpl === '' || !str_contains($tpl, '{id}')) {
            $base = '';
            foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'ARTMARK_URL=')) { $base = substr($line, strlen('ARTMARK_URL=')); break; }
            }
            $tpl = rtrim($base !== '' ? $base : 'http://213.226.126.81:8082', '/') . '//?S1=S3&Otch=1&csv=open&Id={id}';
        }
        $url = str_replace('{id}', rawurlencode($tripId), $tpl);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Система автовокзала недоступна: ' . $err);
        if ($code !== 200) throw new RuntimeException('Система автовокзала вернула ошибку (HTTP ' . $code . ').');
        // При ошибке/отсутствии рейса система отдаёт HTML, а не CSV.
        $peek = ltrim(substr($raw, 0, 200));
        if (stripos($peek, '<html') !== false || stripos($peek, '<!doctype') !== false) {
            throw new RuntimeException('Рейс ' . $tripId . ' не найден в системе.');
        }
        $utf = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        $tmp = tempnam(sys_get_temp_dir(), 'artmark') . '.csv';
        if (file_put_contents($tmp, $utf) === false) throw new RuntimeException('Не удалось сохранить временный файл.');
        return $tmp;
    }
}

// Загрузка CSV-ведомости в БД + авто-подстановка водителя/телефона из справочника автобусов.
// Возвращает id ведомости. Бросает Exception при ошибке.

function import_manifest_csv(array $file, ?array $parsedData = null): int
{
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить файл (код ' . (int) $file['error'] . ').');
    }
    if ((int) $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Файл больше 10 МБ.');
    }

    require_once PANEL_ROOT . '/lib/ManifestParser.php';
    $parser = new ManifestParser();
    $parsed = $parsedData ?? $parser->parseFile($file['tmp_name'], $file['name']);

    $departure = null;
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', (string) $parsed['trip']['departure_at'], $mm) && (int) $mm[3] >= 2000) {
        $departure = sprintf('%s-%s-%s %02d:%02d:00', $mm[3], $mm[2], $mm[1], $mm[4] ?? 0, $mm[5] ?? 0);
    }

    // Телефон водителя: водитель берётся ИЗ ФАЙЛА, телефон подтягиваем по его ФИО из справочника водителей;
    // если там пусто — запасной вариант по справочнику автобусов (старое поведение).
    $driverPhone = '';
    foreach (preg_split('/\s*,\s*/u', (string) $parsed['trip']['drivers']) as $dn) {
        $dn = trim($dn);
        if ($dn === '') continue;
        $q = db()->prepare("SELECT phone FROM drivers WHERE LOWER(name) = LOWER(?) AND phone <> '' LIMIT 1");
        $q->execute([$dn]);
        $ph = (string) $q->fetchColumn();
        if ($ph !== '') { $driverPhone = $ph; break; }
    }
    if ($driverPhone === '') {
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
    }

    $pdo = db();
    $pdo->prepare('INSERT INTO manifests (file_name, trip_number, route, departure_at, carrier, bus, drivers, driver_phone) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $parsed['file_name'], $parsed['trip']['id'], $parsed['trip']['route'],
            $departure, $parsed['trip']['carrier'], $parsed['trip']['bus'], $parsed['trip']['drivers'], $driverPhone,
        ]);
    $manifestId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO passengers (manifest_id, seat, name, phone, doc, ticket, from_stop, to_stop, status, sort, from_id, to_id, birthdate, price, citizenship, pay_note, manifest_price, agent_raw) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    require_once PANEL_ROOT . '/app/contacts.php';
    $newStations = []; // id => название (для авто-заведения в справочник)
    $groupTimes = []; // станция => ['date'=>дд.мм.гггг, 'time'=>чч:мм] — время посадки из ведомости
    foreach ($parsed['passengers'] as $i => $p) {
        $ins->execute([
            $manifestId, preg_replace('/^место\s*/ui', '', $p['seat']), $p['name'], $p['phone'],
            $p['doc'] ?? '', $p['ticket'] ?? '', $p['from'], $p['to'], $p['status'], $i,
            $p['from_id'] ?? null, $p['to_id'] ?? null,
            $p['birthdate'] ?? '', $p['price'] ?? null, $p['citizenship'] ?? '', $p['pay_note'] ?? '',
            $p['price'] ?? null, $p['agent_raw'] ?? '',
        ]);
        if (!empty($p['phone_valid'])) {
            contact_log_trip($p['phone'], $p['name'], $parsed['trip']['route']);
        }
        if (!empty($p['from_id']) && trim($p['from']) !== '') {
            $newStations[(int) $p['from_id']] = trim($p['from']);
        }
        // станции прибытия тоже заводим в справочник
        if (!empty($p['to_id']) && trim($p['to']) !== '') {
            $newStations[(int) $p['to_id']] = trim($p['to']);
        }
        // время посадки группы — из колонки «Дата/время отправления» (у каждой остановки своё)
        $st = trim((string) $p['from']);
        if ($st !== '' && !isset($groupTimes[$st]) && !empty($p['depart_at'])
            && preg_match('/(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', (string) $p['depart_at'], $tm)) {
            $groupTimes[$st] = ['date' => $tm[1], 'time' => isset($tm[2]) ? sprintf('%02d:%02d', $tm[2], $tm[3]) : ''];
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

    // Время посадки по группам из ведомости → черновик группы (оператор видит сразу, без GDS)
    foreach ($groupTimes as $station => $t) {
        if ($t['time'] === '') continue;
        try {
                $pdo->prepare("INSERT INTO manifest_groups (manifest_id, station, destination, boarding_date, boarding_time)
                    VALUES (?,?, '',?,?)
                    ON DUPLICATE KEY UPDATE boarding_date = VALUES(boarding_date), boarding_time = VALUES(boarding_time)")
                ->execute([$manifestId, $station, $t['date'], $t['time']]);
        } catch (Exception $e) { /* не критично для импорта */ }
    }

    // Авто-seed автобуса в справочник: номер из «Номер_авт.», места из «Транспорт».
    // Дедуп: номер из файла ищем как подстроку в существующих plate/code — ловим записи вида «Белый Ютонг E340KУ82».
    try {
        $plate = trim((string) $parsed['trip']['bus']);
        $norm = mb_strtoupper(preg_replace('/\s+/u', '', $plate), 'UTF-8');
        if ($norm !== '' && mb_strlen($norm) >= 4) {
            $found = false;
            foreach ($pdo->query('SELECT plate, code FROM buses')->fetchAll() as $b) {
                foreach ([$b['plate'], $b['code']] as $val) {
                    $v = mb_strtoupper(preg_replace('/\s+/u', '', (string) $val), 'UTF-8');
                    if ($v !== '' && (str_contains($v, $norm) || $v === $norm)) { $found = true; break 2; }
                }
            }
            if (!$found) {
                $seats = 0;
                if (preg_match('/(\d+)\s*мест/ui', (string) ($parsed['trip']['transport_info'] ?? ''), $sm)) $seats = (int) $sm[1];
                // code = номер (для уникальности), model оставляем пустым — описание «белый Ютонг» вводит оператор
                $pdo->prepare('INSERT IGNORE INTO buses (code, plate, model, seats, note) VALUES (?,?,?,?,?)')
                    ->execute([$plate, $plate, '', $seats, trim((string) ($parsed['trip']['transport_info'] ?? ''))]);
            }
        }
    } catch (Exception $e) { /* не критично */ }

    // Авто-seed водителей: «Фамилия Имя, Фамилия Имя» → отдельные записи. Дедуп по ФИО. Телефон вводит оператор.
    try {
        foreach (preg_split('/\s*,\s*/u', (string) $parsed['trip']['drivers']) as $dname) {
            $dname = trim($dname);
            if ($dname === '') continue;
            $ex = $pdo->prepare('SELECT id FROM drivers WHERE LOWER(name) = LOWER(?)');
            $ex->execute([$dname]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare('INSERT INTO drivers (name) VALUES (?)')->execute([$dname]);
            }
        }
    } catch (Exception $e) { /* не критично */ }

    return $manifestId;
}
