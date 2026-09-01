<?php

require_once PANEL_ROOT . '/lib/ReportingCalculator.php';

// Отчётность — отдельная среда: рейс попадает в неё только явным добавлением.
function reporting_attach(int $manifestId): void
{
    try { db()->prepare('UPDATE manifests SET in_reporting = 1 WHERE id = ?')->execute([$manifestId]); }
    catch (Throwable $e) { /* до применения schema21 */ }
}

function reporting_detach(int $manifestId): void
{
    try { db()->prepare('UPDATE manifests SET in_reporting = 0 WHERE id = ?')->execute([$manifestId]); }
    catch (Throwable $e) { /* до применения schema21 */ }
}

// ── СЦЕНАРИИ РАСЧЁТА ────────────────────────────────────────────────────────
// Сценарий = набор настроек (перевозчики со ставками, агенты, автовокзалы).
// Факты рейса общие: переключил сценарий — те же пассажиры пересчитались иначе.

function reporting_scenario_list(): array
{
    try { return db()->query('SELECT * FROM report_scenarios ORDER BY sort, id')->fetchAll(); }
    catch (Throwable $e) { return []; }
}

// Сценарий по умолчанию (первый). Используется, когда у рейса свой не выбран.
function reporting_default_scenario_id(): int
{
    try { return (int) db()->query('SELECT MIN(id) FROM report_scenarios')->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

// Каким сценарием считается рейс.
function reporting_scenario_for(int $manifestId): int
{
    try {
        $st = db()->prepare('SELECT report_scenario_id FROM manifests WHERE id=?');
        $st->execute([$manifestId]);
        $sid = (int) $st->fetchColumn();
        if ($sid) return $sid;
    } catch (Throwable $e) {}
    return reporting_default_scenario_id();
}

function reporting_set_scenario(int $manifestId, int $scenarioId): void
{
    db()->prepare('UPDATE manifests SET report_scenario_id=? WHERE id=?')->execute([$scenarioId ?: null, $manifestId]);
}

// Копия активного сценария. origin_id договоров ПЕРЕНОСИТСЯ — иначе назначения агентов
// в строках пассажиров слетят (в JS-прототипе id сохранялись при deep-clone).
function reporting_scenario_copy(int $sourceId, string $name = ''): int
{
    $db = db();
    $src = $db->prepare('SELECT * FROM report_scenarios WHERE id=?');
    $src->execute([$sourceId]);
    $row = $src->fetch();
    if (!$row) throw new RuntimeException('Сценарий не найден.');
    $n = (int) $db->query('SELECT COUNT(*) FROM report_scenarios')->fetchColumn();
    $name = mb_substr(trim($name), 0, 64) ?: 'Вариант ' . ($n + 1);

    $db->prepare('INSERT INTO report_scenarios (name, sort) VALUES (?,?)')->execute([$name, $n]);
    $newId = (int) $db->lastInsertId();

    // перевозчики со ставками
    $db->prepare('INSERT INTO report_scenario_carriers (scenario_id, name, disp_rate, our_rate)
        SELECT ?, name, disp_rate, our_rate FROM report_scenario_carriers WHERE scenario_id=?')
        ->execute([$newId, $sourceId]);
    // договоры агентов: origin_id переносим как есть — по нему находится «тот же» агент
    $db->prepare('INSERT INTO report_agent_contracts
        (agent_id,title,settlement_side,carrier,agent_commission_rate,agent_commission_basis,
         commercial_rate,dispatch_rate,dispatch_settlement,match_src,active,scenario_id,origin_id)
        SELECT agent_id,title,settlement_side,carrier,agent_commission_rate,agent_commission_basis,
               commercial_rate,dispatch_rate,dispatch_settlement,match_src,active,?,COALESCE(origin_id,id)
          FROM report_agent_contracts WHERE scenario_id=?')->execute([$newId, $sourceId]);
    // автовокзалы
    $db->prepare('INSERT INTO report_stations (name, rate, note, active, scenario_id)
        SELECT name, rate, note, active, ? FROM report_stations WHERE scenario_id=?')
        ->execute([$newId, $sourceId]);
    return $newId;
}

// Договоры агентов активного сценария. Ключ массива — id договора В ЭТОМ сценарии,
// плюс отдаём origin_id, чтобы расчёт умел находить «того же» агента в другом сценарии.
function reporting_contracts(?int $scenarioId = null): array
{
    $scenarioId = $scenarioId ?: reporting_default_scenario_id();
    try {
        $st = db()->prepare("SELECT c.*, a.name agent_name FROM report_agent_contracts c
            JOIN report_agents a ON a.id=c.agent_id
            WHERE c.active=1 AND a.active=1 AND (c.scenario_id = ? OR (c.scenario_id IS NULL AND ? = 0))
            ORDER BY a.name,c.title,c.id");
        $st->execute([$scenarioId, $scenarioId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { // до применения schema22
        $rows = db()->query("SELECT c.*, a.name agent_name FROM report_agent_contracts c
            JOIN report_agents a ON a.id=c.agent_id WHERE c.active=1 AND a.active=1 ORDER BY a.name,c.title,c.id")->fetchAll();
    }
    $result = [];
    foreach ($rows as $row) $result[(int) $row['id']] = $row;
    return $result;
}

// Назначение агента переносится между сценариями по origin_id: пассажир хранит id
// договора, а в активном сценарии берём договор с тем же «предком».
function reporting_map_assignment(?int $assignedId, array $contracts): int
{
    $assignedId = (int) $assignedId;
    if (!$assignedId || isset($contracts[$assignedId])) return $assignedId;
    try {
        $st = db()->prepare('SELECT COALESCE(origin_id, id) FROM report_agent_contracts WHERE id=?');
        $st->execute([$assignedId]);
        $origin = (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
    if (!$origin) return 0;
    foreach ($contracts as $id => $c) {
        if ((int) ($c['origin_id'] ?? $id) === $origin) return (int) $id;
    }
    return 0;
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

// Автовокзалы активного сценария (для выбора при вводе продаж).
function reporting_stations(?int $scenarioId = null): array
{
    $scenarioId = $scenarioId ?: reporting_default_scenario_id();
    try {
        $st = db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM manifest_station_sales ss WHERE ss.station_id=s.id) sales_count
            FROM report_stations s WHERE (s.scenario_id = ? OR (s.scenario_id IS NULL AND ? = 0)) ORDER BY s.name');
        $st->execute([$scenarioId, $scenarioId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        try {
            return db()->query('SELECT s.*, (SELECT COUNT(*) FROM manifest_station_sales ss WHERE ss.station_id=s.id) sales_count
                FROM report_stations s ORDER BY s.name')->fetchAll();
        } catch (Throwable $e2) { return []; }
    }
}

// Ставки перевозчика рейса: диспетчерские с ОБОРОТА и комиссия Терры с НАШИХ продаж.
// Базы разные — см. ReportingCalculator. Перевозчик в ведомости хранится строкой (ATP).
// Ставки перевозчика берём ИЗ АКТИВНОГО СЦЕНАРИЯ (там свой набор), с откатом на общую
// таблицу carriers, пока schema22 не применена.
function reporting_carrier_rates(?string $carrierName, ?int $scenarioId = null): array
{
    $rates = ['disp_rate' => ReportingCalculator::DEFAULT_DISP_RATE, 'our_rate' => ReportingCalculator::DEFAULT_OUR_RATE];
    $name = trim((string) $carrierName);
    if ($name === '') return $rates;
    $scenarioId = $scenarioId ?: reporting_default_scenario_id();
    try {
        $st = db()->prepare('SELECT disp_rate, our_rate FROM report_scenario_carriers
            WHERE scenario_id = ? AND name = ? LIMIT 1');
        $st->execute([$scenarioId, $name]);
        if ($row = $st->fetch()) {
            return ['disp_rate' => (float) $row['disp_rate'], 'our_rate' => (float) $row['our_rate']];
        }
    } catch (Throwable $e) { /* schema22 ещё не применена */ }
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

    // Активный сценарий рейса: его ставки, его агенты, его вокзалы.
    $scenarioId = reporting_scenario_for($manifestId);
    $contracts = reporting_contracts($scenarioId);
    // Назначение агента могло быть сделано в другом сценарии — переносим по origin_id.
    foreach ($passengers as &$p) {
        $p['agent_contract_id'] = reporting_map_assignment($p['agent_contract_id'] ?? null, $contracts) ?: null;
    }
    unset($p);

    $opts = reporting_carrier_rates($manifest['carrier'] ?? '', $scenarioId);
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

    return ReportingCalculator::calculate($passengers, $contracts, $opts);
}

// Автоподбор агентов по ведомости и комментариям.
// $force = false — заполняем ТОЛЬКО пустые назначения (ручной выбор оператора не трогаем).
// $force = true  — пересобираем все (кнопка «Подставить агентов по совпадению»):
//                  это осознанное действие оператора, поэтому перезапись допустима.
// Возвращает, сколько строк назначено.
function reporting_match_imported_agents(int $manifestId, bool $force = false): int
{
    $scenarioId = reporting_scenario_for($manifestId);
    // ⚠️ Договоры считаем В ПРЕДЕЛАХ СЦЕНАРИЯ. Иначе после копирования сценария у каждого
    // агента становится 2+ договора, срабатывает защита ниже — и автоподбор молча умирает.
    try {
        $st = db()->prepare("SELECT c.id contract_id,c.agent_id,a.name,a.aliases,c.settlement_side,
            c.agent_commission_rate,c.match_src,
            (SELECT COUNT(*) FROM report_agent_contracts c2
              WHERE c2.agent_id=c.agent_id AND c2.active=1 AND c2.scenario_id<=>c.scenario_id) contract_count
            FROM report_agent_contracts c JOIN report_agents a ON a.id=c.agent_id
            WHERE c.active=1 AND a.active=1 AND (c.scenario_id = ? OR (c.scenario_id IS NULL AND ? = 0))
            ORDER BY c.id");
        $st->execute([$scenarioId, $scenarioId]);
        $agents = $st->fetchAll();
    } catch (Throwable $e) { // до применения schema22
        $agents = db()->query("SELECT c.id contract_id,c.agent_id,a.name,a.aliases,c.settlement_side,
            c.agent_commission_rate,c.match_src,
            (SELECT COUNT(*) FROM report_agent_contracts c2 WHERE c2.agent_id=c.agent_id AND c2.active=1) contract_count
            FROM report_agent_contracts c JOIN report_agents a ON a.id=c.agent_id
            WHERE c.active=1 AND a.active=1 ORDER BY c.id")->fetchAll();
    }

    $matchAgents = [];
    foreach ($agents as $agent) {
        // Несколько договоров у одного бренда в одном сценарии — выбор за оператором:
        // по имени нельзя понять, по какому договору фактически прошли деньги.
        if ((int) $agent['contract_count'] !== 1) continue;
        $matchAgents[(int) $agent['contract_id']] = [
            'id' => (int) $agent['contract_id'], 'name' => $agent['name'],
            'alias' => trim((string) $agent['aliases'] . '|' . (string) $agent['name'], '| '),
            'side' => $agent['settlement_side'], 'rate' => $agent['agent_commission_rate'],
            'src' => $agent['match_src'] ?? '',
        ];
    }
    if (!$matchAgents) return 0;

    $sql = 'SELECT id,agent_raw,pay_note,agent_contract_id FROM passengers WHERE manifest_id=?'
        . ($force ? '' : ' AND agent_contract_id IS NULL');
    $st = db()->prepare($sql);
    $st->execute([$manifestId]);
    $up = db()->prepare('UPDATE passengers SET agent_contract_id=? WHERE id=?');
    $n = 0;
    foreach ($st->fetchAll() as $passenger) {
        // Внутри matchAgent комментарий уже сильнее поля «Агент/кассир».
        $agentId = ReportingCalculator::matchAgent(
            (string) ($passenger['agent_raw'] ?? ''), (string) ($passenger['pay_note'] ?? ''), $matchAgents);
        if ($agentId && $agentId !== (int) ($passenger['agent_contract_id'] ?? 0)) {
            $up->execute([$agentId, (int) $passenger['id']]);
            $n++;
        }
    }
    return $n;
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
