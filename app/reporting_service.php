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

function reporting_save_snapshot(int $manifestId): int
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
    db()->prepare('INSERT INTO manifest_calculations
        (manifest_id,version,status,rules_json,totals_json,passengers_json,actor) VALUES (?,?,?,?,?,?,?)')->execute([
            $manifestId,$version,'calculated',json_encode($rules,JSON_UNESCAPED_UNICODE),
            json_encode($calc['totals'],JSON_UNESCAPED_UNICODE),
            json_encode($passengerSnapshot,JSON_UNESCAPED_UNICODE),current_user_name(),
        ]);
    db()->prepare("UPDATE manifests SET reporting_status='calculated' WHERE id=?")->execute([$manifestId]);
    return $version;
}
