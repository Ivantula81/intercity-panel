<?php

require_once PANEL_ROOT . '/lib/ReportingCalculator.php';

function reporting_contracts(): array
{
    $rows = db()->query("SELECT c.*, a.name agent_name FROM report_agent_contracts c
        JOIN report_agents a ON a.id=c.agent_id WHERE c.active=1 AND a.active=1 ORDER BY a.name,c.title,c.id")->fetchAll();
    $result = [];
    foreach ($rows as $row) $result[(int) $row['id']] = $row;
    return $result;
}

// Продажи автовокзалов на рейс. Процент берётся ИЗ СПРАВОЧНИКА (не с строки продажи):
// правка ставки пересчитывает все рейсы разом — так решено с владельцем.
function reporting_station_sales(int $manifestId): array
{
    try {
        $st = db()->prepare('SELECT ss.id, ss.station_id, ss.amount, ss.note, ss.created_at, ss.actor,
            s.name, s.rate FROM manifest_station_sales ss
            JOIN report_stations s ON s.id = ss.station_id
            WHERE ss.manifest_id = ? ORDER BY ss.id');
        $st->execute([$manifestId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; } // до применения schema19
}

// Ставки перевозчика рейса: диспетчерские с ОБОРОТА и комиссия Терры с НАШИХ продаж.
// Базы разные — см. ReportingCalculator. Перевозчик в ведомости хранится строкой (ATP).
function reporting_carrier_rates(?string $carrierName): array
{
    $rates = ['disp_rate' => ReportingCalculator::DEFAULT_DISP_RATE, 'our_rate' => ReportingCalculator::DEFAULT_OUR_RATE];
    $name = trim((string) $carrierName);
    if ($name === '') return $rates;
    try {
        $st = db()->prepare('SELECT disp_rate, our_rate FROM carriers WHERE atp = ? LIMIT 1');
        $st->execute([$name]);
        if ($row = $st->fetch()) {
            $rates['disp_rate'] = (float) $row['disp_rate'];
            $rates['our_rate'] = (float) $row['our_rate'];
        }
    } catch (Throwable $e) { /* до применения schema19 — остаются дефолты */ }
    return $rates;
}

function reporting_calculate_manifest(int $manifestId): array
{
    $st = db()->prepare('SELECT * FROM passengers WHERE manifest_id=? ORDER BY sort,id');
    $st->execute([$manifestId]);
    $passengers = $st->fetchAll();

    $mSt = db()->prepare('SELECT carrier, other_costs FROM manifests WHERE id=?');
    $mSt->execute([$manifestId]);
    $manifest = $mSt->fetch() ?: [];

    $opts = reporting_carrier_rates($manifest['carrier'] ?? '');
    $opts['other_costs'] = (float) ($manifest['other_costs'] ?? 0);
    // Наличные — форма оплаты продаж Терры: уменьшают долг перевозчику, но не его доход.
    $cSt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM manifest_cash_entries WHERE manifest_id=?');
    $cSt->execute([$manifestId]);
    $opts['cash'] = (float) $cSt->fetchColumn();
    // Автовокзалы: входят в оборот (база диспетчерских), но не в долг Терры перевозчику.
    $opts['station_sales'] = array_map(static fn($s) => [
        'station_id' => (int) $s['station_id'], 'name' => $s['name'],
        'amount' => (float) $s['amount'], 'rate' => (float) $s['rate'],
    ], reporting_station_sales($manifestId));

    return ReportingCalculator::calculate($passengers, reporting_contracts(), $opts);
}

function reporting_match_imported_agents(int $manifestId): void
{
    $agents = db()->query("SELECT c.id contract_id,c.agent_id,a.name,a.aliases,
        (SELECT COUNT(*) FROM report_agent_contracts c2 WHERE c2.agent_id=c.agent_id AND c2.active=1) contract_count
        FROM report_agent_contracts c JOIN report_agents a ON a.id=c.agent_id
        WHERE c.active=1 AND a.active=1 ORDER BY c.id")->fetchAll();
    $st = db()->prepare("SELECT id,agent_raw FROM passengers WHERE manifest_id=? AND agent_raw<>'' AND agent_contract_id IS NULL");
    $st->execute([$manifestId]);
    $up = db()->prepare('UPDATE passengers SET agent_contract_id=? WHERE id=?');
    foreach ($st->fetchAll() as $passenger) {
        $needle = mb_strtolower(trim((string) $passenger['agent_raw']));
        foreach ($agents as $agent) {
            // Если у бренда несколько договоров, выбор должен сделать оператор: по одному имени
            // нельзя безопасно определить, кому фактически поступили деньги.
            if ((int) $agent['contract_count'] !== 1) continue;
            $aliases = array_filter(array_map('trim', explode(',', (string) $agent['aliases'])));
            $aliases[] = (string) $agent['name'];
            foreach ($aliases as $alias) {
                $alias = mb_strtolower($alias);
                if ($alias !== '' && (str_contains($needle, $alias) || str_contains($alias, $needle))) {
                    $up->execute([(int) $agent['contract_id'], (int) $passenger['id']]);
                    continue 3;
                }
            }
        }
    }
}

function reporting_storage_dir(): string
{
    $configured = getenv('REPORTING_STORAGE_DIR') ?: '';
    if ($configured === '' && is_readable('/etc/panel.env')) {
        foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'REPORTING_STORAGE_DIR=')) {
                $configured = substr($line, strlen('REPORTING_STORAGE_DIR='));
                break;
            }
        }
    }
    return rtrim($configured !== '' ? $configured : PANEL_ROOT . '/storage/reporting', '/');
}

function reporting_store_file(int $manifestId, array $file, string $type, string $note = ''): int
{
    $allowedTypes = ['source_csv','working_manifest','driver_document','carrier_document','report','other'];
    if (!in_array($type, $allowedTypes, true)) throw new RuntimeException('Неизвестный тип файла.');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загружен.');
    if ((int) $file['size'] > 15 * 1024 * 1024) throw new RuntimeException('Файл больше 15 МБ.');

    $original = basename((string) $file['name']);
    $extension = mb_strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExtensions = $type === 'source_csv' ? ['csv'] : ['csv','pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
    if (!in_array($extension, $allowedExtensions, true)) throw new RuntimeException('Этот формат файла не поддерживается.');

    $sha = hash_file('sha256', $file['tmp_name']);
    if ($sha === false) throw new RuntimeException('Не удалось проверить файл.');
    $versionSt = db()->prepare('SELECT COALESCE(MAX(version),0)+1 FROM manifest_files WHERE manifest_id=? AND file_type=?');
    $versionSt->execute([$manifestId, $type]);
    $version = (int) $versionSt->fetchColumn();
    $storageName = sprintf('%d/%s-v%d-%s.%s', $manifestId, $type, $version, substr($sha, 0, 16), $extension);
    $fullPath = reporting_storage_dir() . '/' . $storageName;
    if (!is_dir(dirname($fullPath)) && !mkdir(dirname($fullPath), 0770, true) && !is_dir(dirname($fullPath))) {
        throw new RuntimeException('Не удалось создать защищённое хранилище файлов.');
    }
    if (!move_uploaded_file($file['tmp_name'], $fullPath) && !copy($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Не удалось сохранить файл.');
    }
    @chmod($fullPath, 0660);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
    db()->prepare('INSERT INTO manifest_files
        (manifest_id,file_type,original_name,storage_name,mime_type,file_size,sha256,version,note,uploaded_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
            $manifestId,$type,$original,$storageName,$mime,(int) filesize($fullPath),$sha,$version,
            mb_substr(trim($note),0,500),current_user_name(),
        ]);
    return (int) db()->lastInsertId();
}

// Сохранённые расчёты рейса: последняя версия каждого сценария. Для переключения и сравнения.
function reporting_scenarios(int $manifestId): array
{
    try {
        $st = db()->prepare("SELECT c.scenario_name, c.version, c.created_at, c.actor, c.status, c.totals_json
            FROM manifest_calculations c
            JOIN (SELECT scenario_name, MAX(version) v FROM manifest_calculations
                   WHERE manifest_id = ? GROUP BY scenario_name) last
              ON last.scenario_name = c.scenario_name AND last.v = c.version
            WHERE c.manifest_id = ? ORDER BY c.scenario_name");
        $st->execute([$manifestId, $manifestId]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $totals = json_decode((string) $row['totals_json'], true) ?: [];
            $out[] = [
                'scenario_name' => $row['scenario_name'], 'version' => (int) $row['version'],
                'created_at' => $row['created_at'], 'actor' => $row['actor'], 'status' => $row['status'],
                // основное для сравнения сценариев между собой
                'turnover' => (float) ($totals['turnover'] ?? 0),
                'carrier_earn' => (float) ($totals['carrier_earn'] ?? 0),
                'our_profit' => (float) ($totals['our_profit'] ?? $totals['profit'] ?? 0),
                'to_carrier' => (float) ($totals['to_carrier'] ?? $totals['carrier_due'] ?? 0),
                'formula_version' => (int) ($totals['formula_version'] ?? 1),
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; } // до применения schema20
}

// ── Аналитика по месяцам: свод по рейсам с сохранённым расчётом ──────────────
// Строка = рейс, считается по ВЫБРАННОМУ сценарию (по умолчанию — последняя версия
// любого). Берём замороженные снимки, а не пересчитываем: отчёт за закрытый месяц
// не должен меняться от правки справочников.
// $month — 'YYYY-MM'. Возвращает ['rows'=>[...], 'totals'=>[...]].
function reporting_month_summary(string $month, string $scenarioName = ''): array
{
    $cols = ['pax', 'turnover', 'manifest_total', 'cash', 'our_sales', 'carrier_sales',
        'stations_total', 'dispatch_fee', 'our_commission', 'agent_commission',
        'extra', 'noshow_income', 'our_profit', 'carrier_earn'];
    $rows = [];
    $totals = array_fill_keys($cols, 0.0);
    $totals['trips'] = 0;

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return ['rows' => [], 'totals' => $totals];

    try {
        // последняя версия расчёта на рейс (при заданном сценарии — в его рамках)
        $sql = "SELECT m.id, m.trip_number, m.route, m.departure_at, m.carrier, m.bus,
                       c.scenario_name, c.version, c.totals_json
                  FROM manifests m
                  JOIN manifest_calculations c ON c.manifest_id = m.id
                  JOIN (SELECT manifest_id, MAX(version) v FROM manifest_calculations
                         WHERE 1 " . ($scenarioName !== '' ? 'AND scenario_name = :sc2 ' : '') . "
                         GROUP BY manifest_id) last
                    ON last.manifest_id = c.manifest_id AND last.v = c.version
                 WHERE DATE_FORMAT(m.departure_at, '%Y-%m') = :month
                 ORDER BY m.departure_at, m.id";
        $st = db()->prepare($sql);
        $params = ['month' => $month];
        if ($scenarioName !== '') $params['sc2'] = $scenarioName;
        $st->execute($params);

        foreach ($st->fetchAll() as $r) {
            $t = json_decode((string) $r['totals_json'], true) ?: [];
            $row = [
                'manifest_id' => (int) $r['id'], 'trip_number' => $r['trip_number'],
                'route' => $r['route'], 'departure_at' => $r['departure_at'],
                'carrier' => $r['carrier'], 'bus' => $r['bus'],
                'scenario_name' => $r['scenario_name'] ?? '', 'version' => (int) $r['version'],
            ];
            foreach ($cols as $k) {
                // старые снимки (formula_version 1) не знают новых ключей — берём legacy-аналоги
                $v = $t[$k] ?? match ($k) {
                    'turnover' => $t['manifest_total'] ?? 0,
                    'our_profit' => $t['profit'] ?? 0,
                    'carrier_earn' => 0,
                    'our_commission' => $t['commercial_fee'] ?? 0,
                    'carrier_sales' => $t['carrier_direct_sales'] ?? 0,
                    'pax' => $t['passengers'] ?? 0,
                    default => 0,
                };
                $row[$k] = round((float) $v, 2);
                $totals[$k] += $row[$k];
            }
            // маржа рейса: сколько наша прибыль составляет от оборота
            $row['margin'] = $row['turnover'] > 0 ? round($row['our_profit'] / $row['turnover'] * 100, 2) : 0.0;
            $rows[] = $row;
            $totals['trips']++;
        }
    } catch (Throwable $e) { return ['rows' => [], 'totals' => $totals]; }

    foreach ($cols as $k) $totals[$k] = round($totals[$k], 2);
    $totals['margin'] = $totals['turnover'] > 0 ? round($totals['our_profit'] / $totals['turnover'] * 100, 2) : 0.0;
    return ['rows' => $rows, 'totals' => $totals];
}

// Месяцы, по которым есть рейсы с сохранённым расчётом — для выбора периода.
function reporting_months(): array
{
    try {
        return db()->query("SELECT DISTINCT DATE_FORMAT(m.departure_at,'%Y-%m') ym
            FROM manifests m JOIN manifest_calculations c ON c.manifest_id = m.id
            WHERE m.departure_at IS NOT NULL ORDER BY ym DESC LIMIT 36")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

function reporting_save_snapshot(int $manifestId, string $scenarioName = 'Вариант 1'): int
{
    $calc = reporting_calculate_manifest($manifestId);
    $factsSt = db()->prepare('SELECT id,seat,name,phone,birthdate,from_stop,to_stop,attendance,refund_status,
        agent_raw,agent_contract_id,manifest_price,our_price,finance_comment FROM passengers WHERE manifest_id=? ORDER BY sort,id');
    $factsSt->execute([$manifestId]);
    $derivedById = [];
    foreach ($calc['passengers'] as $derived) $derivedById[(int) $derived['id']] = $derived;
    $passengerSnapshot = [];
    foreach ($factsSt->fetchAll() as $facts) {
        $passengerSnapshot[] = array_merge($facts, $derivedById[(int) $facts['id']] ?? []);
    }
    $versionSt = db()->prepare('SELECT COALESCE(MAX(version),0)+1 FROM manifest_calculations WHERE manifest_id=?');
    $versionSt->execute([$manifestId]);
    $version = (int) $versionSt->fetchColumn();
    $rules = array_values(reporting_contracts());
    // Имя сценария: несколько независимых расчётов одной ведомости («Вариант 1/2/3»).
    $scenarioName = mb_substr(trim($scenarioName), 0, 64) ?: 'Вариант 1';
    // Долги и разбивку по каналам тоже кладём в снимок — отчёт перевозчику должен
    // воспроизводиться из замороженных данных, а не пересчитываться заново.
    $frozen = $calc['totals'];
    $frozen['debts'] = $calc['debts'] ?? [];
    $frozen['by_agent'] = $calc['by_agent'] ?? [];
    $frozen['station_sales'] = $calc['station_sales'] ?? [];
    try {
        db()->prepare('INSERT INTO manifest_calculations
            (manifest_id,version,scenario_name,status,rules_json,totals_json,passengers_json,actor) VALUES (?,?,?,?,?,?,?,?)')->execute([
                $manifestId,$version,$scenarioName,'calculated',json_encode($rules,JSON_UNESCAPED_UNICODE),
                json_encode($frozen,JSON_UNESCAPED_UNICODE),
                json_encode($passengerSnapshot,JSON_UNESCAPED_UNICODE),current_user_name(),
            ]);
    } catch (Throwable $e) { // до применения schema20 — колонки scenario_name ещё нет
        db()->prepare('INSERT INTO manifest_calculations
            (manifest_id,version,status,rules_json,totals_json,passengers_json,actor) VALUES (?,?,?,?,?,?,?)')->execute([
                $manifestId,$version,'calculated',json_encode($rules,JSON_UNESCAPED_UNICODE),
                json_encode($frozen,JSON_UNESCAPED_UNICODE),
                json_encode($passengerSnapshot,JSON_UNESCAPED_UNICODE),current_user_name(),
            ]);
    }
    db()->prepare("UPDATE manifests SET reporting_status='calculated' WHERE id=?")->execute([$manifestId]);
    return $version;
}
