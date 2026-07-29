<?php

require_once PANEL_ROOT . '/app/reporting_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'import_csv') {
            require_once PANEL_ROOT . '/lib/ManifestParser.php';
            require_once PANEL_ROOT . '/app/manifest_import.php';
            $file = $_FILES['manifest'] ?? [];
            if (!$file) throw new RuntimeException('Выберите CSV-ведомость.');
            $parsed = (new ManifestParser())->parseFile($file['tmp_name'], $file['name']);
            $businessId = trim((string) ($parsed['trip']['id'] ?? ''));
            if ($businessId === '') throw new RuntimeException('В файле не найден ID ведомости.');
            $existing = db()->prepare('SELECT id FROM manifests WHERE trip_number=? ORDER BY id DESC LIMIT 1');
            $existing->execute([$businessId]);
            $manifestId = (int) ($existing->fetchColumn() ?: 0);
            $wasExisting = $manifestId !== 0;
            if ($manifestId === 0) $manifestId = import_manifest_csv($file, $parsed);
            reporting_store_file($manifestId, $file, 'source_csv', 'Исходная ведомость');
            reporting_match_imported_agents($manifestId);
            reporting_attach($manifestId); // явное добавление рейса в отчётность
            flash(($wasExisting ? 'Добавлена новая версия файла к рейсу. ' : 'Рейс создан. ') . 'ID ' . $businessId);
            header('Location: /?p=report_trip&id=' . $manifestId);
            exit;
        }
        // Добавить в отчётность рейс по номеру: тянем ведомость из системы автовокзала,
        // если такого рейса ещё нет; если есть — просто помечаем как «в отчётности».
        if ($action === 'add_by_id') {
            require_once PANEL_ROOT . '/lib/ManifestParser.php';
            require_once PANEL_ROOT . '/app/manifest_import.php';
            $tripId = preg_replace('/\D+/', '', (string) ($_POST['trip_id'] ?? ''));
            if ($tripId === '') throw new RuntimeException('Укажите номер рейса.');
            $ex = db()->prepare('SELECT id FROM manifests WHERE trip_number=? ORDER BY id DESC LIMIT 1');
            $ex->execute([$tripId]);
            $manifestId = (int) ($ex->fetchColumn() ?: 0);
            if (!$manifestId) {
                $tmp = artmark_fetch_manifest($tripId);
                try {
                    $parsed = (new ManifestParser())->parseFile($tmp, 'artmark_' . $tripId . '.csv');
                    if (empty($parsed['passengers'])) throw new RuntimeException('В ответе системы нет пассажиров по рейсу ' . $tripId . '.');
                    $manifestId = import_manifest_csv(['tmp_name' => $tmp, 'name' => 'artmark_' . $tripId . '.csv',
                        'error' => 0, 'size' => filesize($tmp)], $parsed);
                } finally { @unlink($tmp); }
            }
            reporting_match_imported_agents($manifestId);
            reporting_attach($manifestId);
            flash('Рейс ' . $tripId . ' добавлен в отчётность.');
            header('Location: /?p=report_trip&id=' . $manifestId);
            exit;
        }
        // Убрать рейс из отчётности (сам рейс и уведомления не трогаем)
        if ($action === 'detach') {
            reporting_detach((int) ($_POST['manifest_id'] ?? 0));
            flash('Рейс убран из отчётности.');
            header('Location: /?p=reporting');
            exit;
        }
        if ($action === 'upload_working') {
            $manifestId = (int) ($_POST['manifest_id'] ?? 0);
            $exists = db()->prepare('SELECT id FROM manifests WHERE id=?');
            $exists->execute([$manifestId]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Рейс не найден.');
            reporting_store_file($manifestId, $_FILES['working_file'] ?? [], 'working_manifest', (string) ($_POST['note'] ?? ''));
            flash('Рабочая ведомость добавлена в файлы рейса.');
            header('Location: /?p=report_trip&id=' . $manifestId . '&tab=files');
            exit;
        }
        if ($action === 'save_agent_contract') {
            $agentName = mb_substr(trim((string) ($_POST['agent_name'] ?? '')),0,255);
            if ($agentName === '') throw new RuntimeException('Укажите название агента.');
            db()->prepare('INSERT INTO report_agents (name,aliases) VALUES (?,?) ON DUPLICATE KEY UPDATE aliases=VALUES(aliases)')
                ->execute([$agentName,mb_substr(trim((string) ($_POST['aliases'] ?? '')),0,2000)]);
            $agentSt = db()->prepare('SELECT id FROM report_agents WHERE name=?');
            $agentSt->execute([$agentName]);
            $agentId = (int) $agentSt->fetchColumn();
            $side = ($_POST['settlement_side'] ?? '') === 'carrier' ? 'carrier' : 'ours';
            $scId = (int) ($_POST['scenario_id'] ?? 0) ?: reporting_default_scenario_id();
            db()->prepare('INSERT INTO report_agent_contracts
                (agent_id,title,settlement_side,carrier,agent_commission_rate,commercial_rate,dispatch_rate,dispatch_settlement,scenario_id)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                    $agentId,mb_substr(trim((string) ($_POST['contract_title'] ?? 'Основной договор')),0,255),$side,
                    mb_substr(trim((string) ($_POST['carrier'] ?? '')),0,255),
                    max(0,(float) str_replace(',','.',(string) ($_POST['agent_commission_rate'] ?? 0))),
                    $side === 'ours' ? max(0,(float) str_replace(',','.',(string) ($_POST['commercial_rate'] ?? 15))) : 0,
                    max(0,(float) str_replace(',','.',(string) ($_POST['dispatch_rate'] ?? 7))),
                    ($_POST['dispatch_settlement'] ?? '') === 'receivable' ? 'receivable' : 'offset',
                    $scId,
                ]);
            // origin_id = собственный id: по нему назначение агента переносится между сценариями
            $newCid = (int) db()->lastInsertId();
            db()->prepare('UPDATE report_agent_contracts SET origin_id=? WHERE id=? AND origin_id IS NULL')->execute([$newCid, $newCid]);
            flash('Агент и условия договора сохранены.');
            header('Location: /?p=reporting&tab=settings&scenario=' . $scId);
            exit;
        }
        // Автовокзалы: продают напрямую перевозчику, в ведомость не попадают.
        // Процент храним ТОЛЬКО здесь — в продажах на рейсе лежит одна сумма, поэтому
        // изменение ставки пересчитывает все рейсы разом (решение владельца).
        if ($action === 'save_station') {
            $name = mb_substr(trim((string) ($_POST['station_name'] ?? '')), 0, 255);
            if ($name === '') throw new RuntimeException('Укажите название автовокзала.');
            $rate = max(0, (float) str_replace(',', '.', (string) ($_POST['station_rate'] ?? 0)));
            $note = mb_substr(trim((string) ($_POST['station_note'] ?? '')), 0, 255);
            $scId = (int) ($_POST['scenario_id'] ?? 0) ?: reporting_default_scenario_id();
            db()->prepare('INSERT INTO report_stations (name, rate, note, scenario_id) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE rate = VALUES(rate), note = VALUES(note), active = 1')
                ->execute([$name, $rate, $note, $scId]);
            flash('Автовокзал сохранён: ' . $name . ' — ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%');
            header('Location: /?p=reporting&tab=settings&scenario=' . ($scId ?? reporting_default_scenario_id()));
            exit;
        }
        if ($action === 'toggle_station') {
            // Не удаляем: на прошлых рейсах могут быть его продажи. Просто убираем из выбора.
            db()->prepare('UPDATE report_stations SET active = 1 - active WHERE id = ?')
                ->execute([(int) ($_POST['station_id'] ?? 0)]);
            header('Location: /?p=reporting&tab=settings&scenario=' . ($scId ?? reporting_default_scenario_id()));
            exit;
        }
    } catch (Throwable $e) {
        $reportingError = $e->getMessage();
    }
}

// Отчётность — ОТДЕЛЬНАЯ среда: показываем только рейсы, добавленные в неё явно.
// Раньше сюда попадала каждая ведомость из уведомлений и считалась автоматически.
try {
    $rows = db()->query("SELECT m.*,
        (SELECT COUNT(*) FROM passengers p WHERE p.manifest_id=m.id) passenger_count,
        (SELECT COUNT(*) FROM manifest_files f WHERE f.manifest_id=m.id) file_count,
        (SELECT MAX(version) FROM manifest_calculations c WHERE c.manifest_id=m.id) calculation_version
        FROM manifests m WHERE m.in_reporting=1 ORDER BY m.departure_at DESC,m.id DESC LIMIT 200")->fetchAll();
} catch (Throwable $e) { // до применения schema21
    $rows = [];
}
$agentContracts = db()->query("SELECT c.*,a.name agent_name,a.aliases FROM report_agent_contracts c
    JOIN report_agents a ON a.id=c.agent_id ORDER BY a.name,c.id")->fetchAll();

// Раздел «Отчётность» — три вкладки: рейсы, аналитика, настройки.
$tab = in_array($_GET['tab'] ?? '', ['settings', 'analytics'], true) ? $_GET['tab'] : 'trips';

if ($tab === 'settings') {
    // Настройки относятся к ВЫБРАННОМУ сценарию — у каждого свой набор справочников.
    $scenarios = reporting_scenario_list();
    $scenarioId = (int) ($_GET['scenario'] ?? 0) ?: reporting_default_scenario_id();
    $stations = reporting_stations($scenarioId);
    $agentContracts = array_values(reporting_contracts($scenarioId));
    try {
        $st = db()->prepare('SELECT * FROM report_scenario_carriers WHERE scenario_id=? ORDER BY name, id');
        $st->execute([$scenarioId]);
        $carriers = $st->fetchAll();
    } catch (Throwable $e) { $carriers = []; }
    view('layout', ['title'=>'Отчётность · Настройки','page'=>'reporting',
        'content'=>fn()=>view('reporting_settings',[
            'agentContracts'=>$agentContracts, 'stations'=>$stations, 'carriers'=>$carriers,
            'scenarios'=>$scenarios, 'scenarioId'=>$scenarioId,
            'sourceUrl'=>opt('artmark_url_template', 'http://213.226.126.81:8082//?S1=S3&Otch=1&csv=open&Id={id}'),
            'reportingError'=>$reportingError ?? '', 'tab'=>$tab])]);
    return;
}

if ($tab === 'analytics') {
    require_once PANEL_ROOT . '/app/reporting_service.php';
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    $summary = reporting_month_summary($month);
    view('layout', ['title'=>'Отчётность · Аналитика','page'=>'reporting',
        'content'=>fn()=>view('reporting_analytics',[
            'month'=>$month, 'months'=>reporting_months(),
            'rows'=>$summary['rows'], 'totals'=>$summary['totals'], 'tab'=>$tab])]);
    return;
}
try {
    $stations = db()->query('SELECT s.*,
        (SELECT COUNT(*) FROM manifest_station_sales ss WHERE ss.station_id = s.id) sales_count
        FROM report_stations s ORDER BY s.active DESC, s.name')->fetchAll();
} catch (Throwable $e) { $stations = []; } // до применения schema19

view('layout', ['title'=>'Отчётность','page'=>'reporting',
    'content'=>fn()=>view('reporting',['rows'=>$rows,'agentContracts'=>$agentContracts,
        'stations'=>$stations,'reportingError'=>$reportingError ?? ''])]);
