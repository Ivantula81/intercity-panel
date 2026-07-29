<?php

require dirname(__DIR__) . '/app/bootstrap.php';

$page = $_GET['p'] ?? 'dashboard';

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (authenticate((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            header('Location: /');
            exit;
        }
        sleep(1);
        view('login', ['error' => 'Неверный логин или пароль']);
        exit;
    }
    view('login', ['error' => '']);
    exit;
}

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /?p=login');
    exit;
}

require_login();

if ($page === 'api') {
    require PANEL_ROOT . '/app/api.php';
    exit;
}

if ($page === 'chat_api') {
    require PANEL_ROOT . '/app/chat_api.php';
    exit;
}

if ($page === 'reporting_api') {
    require PANEL_ROOT . '/app/reporting_api.php';
    exit;
}

switch ($page) {
    case 'dashboard':
        $links = db()->query('SELECT * FROM links ORDER BY sort, id')->fetchAll();
        $stats = [
            'manifests' => (int) db()->query('SELECT COUNT(*) FROM manifests')->fetchColumn(),
            'sent_today' => (int) db()->query("SELECT COUNT(*) FROM messages WHERE status='sent' AND sent_at >= CURDATE()")->fetchColumn(),
            'failed' => (int) db()->query("SELECT COUNT(*) FROM messages WHERE status='failed' AND created_at >= CURDATE()")->fetchColumn(),
        ];
        $recentManifests = db()->query('SELECT * FROM manifests ORDER BY id DESC LIMIT 6')->fetchAll();
        view('layout', ['title' => 'Панель управления', 'page' => 'dashboard',
            'content' => fn() => view('dashboard', ['links' => $links, 'stats' => $stats, 'recentManifests' => $recentManifests])]);
        break;

    case 'manifests':
        require PANEL_ROOT . '/app/manifests_controller.php';
        break;

    case 'reporting':
        require PANEL_ROOT . '/app/reporting_controller.php';
        break;

    case 'reporting_help':
        view('layout', ['title'=>'Инструкция по отчётности','page'=>'reporting',
            'content'=>fn()=>view('reporting_help')]);
        break;

    case 'report_trip':
        require_once PANEL_ROOT . '/app/reporting_service.php';
        $st = db()->prepare('SELECT * FROM manifests WHERE id=?');
        $st->execute([(int) ($_GET['id'] ?? 0)]);
        $manifest = $st->fetch();
        if (!$manifest) { http_response_code(404); die('Рейс не найден'); }
        $ps = db()->prepare('SELECT * FROM passengers WHERE manifest_id=? ORDER BY sort,id');
        $ps->execute([$manifest['id']]);
        $passengers = $ps->fetchAll();
        $fs = db()->prepare('SELECT * FROM manifest_files WHERE manifest_id=? ORDER BY created_at DESC,id DESC');
        $fs->execute([$manifest['id']]);
        $files = $fs->fetchAll();
        $cs = db()->prepare('SELECT * FROM manifest_cash_entries WHERE manifest_id=? ORDER BY created_at DESC,id DESC');
        $cs->execute([$manifest['id']]);
        $cashEntries = $cs->fetchAll();
        $vs = db()->prepare('SELECT version,created_at,actor FROM manifest_calculations WHERE manifest_id=? ORDER BY version DESC LIMIT 1');
        $vs->execute([$manifest['id']]);
        $lastCalculation = $vs->fetch();
        // Автовокзалы и агенты — из СЦЕНАРИЯ, которым считается этот рейс
        $tripScenarioId = reporting_scenario_for((int) $manifest['id']);
        $scenarioList = reporting_scenario_list();
        $stationList = array_values(array_filter(reporting_stations($tripScenarioId), fn($s) => (int) $s['active']));
        $stationSales = reporting_station_sales((int) $manifest['id']);
        // Автоматически НЕ считаем (требование владельца): расчёт запускается кнопкой.
        // Показываем последний сохранённый снимок, если он есть, иначе пустую заготовку.
        $calc = null;
        if (!empty($_GET['calc'])) {                       // ?calc=1 — посчитать сейчас
            $calc = reporting_calculate_manifest((int) $manifest['id']);
        } elseif ($lastCalculation) {                       // иначе — из снимка
            $snap = db()->prepare('SELECT totals_json FROM manifest_calculations WHERE manifest_id=? ORDER BY version DESC LIMIT 1');
            $snap->execute([$manifest['id']]);
            $totals = json_decode((string) $snap->fetchColumn(), true) ?: [];
            if ($totals) $calc = ['totals' => $totals, 'passengers' => [], 'by_agent' => $totals['by_agent'] ?? [],
                'station_sales' => $totals['station_sales'] ?? [], 'debts' => $totals['debts'] ?? [], 'warnings' => []];
        }
        view('layout', ['title'=>'Отчёт по рейсу №'.$manifest['trip_number'],'page'=>'reporting',
            'content'=>fn()=>view('report_trip',['manifest'=>$manifest,'passengers'=>$passengers,
                'contracts'=>reporting_contracts($tripScenarioId),'files'=>$files,'cashEntries'=>$cashEntries,
                'calculation'=>$calc,
                'stationList'=>$stationList,'stationSales'=>$stationSales,
                'scenarioList'=>$scenarioList,'tripScenarioId'=>$tripScenarioId,
                'lastCalculation'=>$lastCalculation,'activeTab'=>$_GET['tab'] ?? 'calculation'])]);
        break;

    case 'report_file':
        require_once PANEL_ROOT . '/app/reporting_service.php';
        $st = db()->prepare('SELECT * FROM manifest_files WHERE id=?');
        $st->execute([(int) ($_GET['id'] ?? 0)]);
        $file = $st->fetch();
        if (!$file) { http_response_code(404); die('Файл не найден'); }
        $base = realpath(reporting_storage_dir());
        $path = realpath(reporting_storage_dir() . '/' . $file['storage_name']);
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            http_response_code(404); die('Файл недоступен');
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $file['mime_type']);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['original_name']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    case 'manifest':
        $m = db()->prepare('SELECT * FROM manifests WHERE id = ?');
        $m->execute([(int) ($_GET['id'] ?? 0)]);
        $manifest = $m->fetch();
        if (!$manifest) { http_response_code(404); die('Ведомость не найдена'); }
        $ps = db()->prepare('SELECT * FROM passengers WHERE manifest_id = ? ORDER BY sort, id');
        $ps->execute([$manifest['id']]);
        $passengers = $ps->fetchAll();
        view('layout', ['title' => 'Ведомость №' . $manifest['trip_number'], 'page' => 'manifests',
            'content' => fn() => view('manifest', ['manifest' => $manifest, 'passengers' => $passengers])]);
        break;

    case 'document':
        $m = db()->prepare('SELECT * FROM manifests WHERE id = ?');
        $m->execute([(int) ($_GET['id'] ?? 0)]);
        $manifest = $m->fetch();
        if (!$manifest) { http_response_code(404); die('Ведомость не найдена'); }
        $ps = db()->prepare('SELECT * FROM passengers WHERE manifest_id = ? ORDER BY sort, id');
        $ps->execute([$manifest['id']]);
        $passengers = $ps->fetchAll();

        $type = ($_GET['type'] ?? 'driver') === 'road' ? 'road' : 'driver';
        $format = $_GET['format'] ?? 'pdf';
        if (!in_array($format, ['pdf', 'html', 'word'], true)) $format = 'pdf';
        $carrierId = (int) ($_GET['carrier'] ?? 0);
        $withStamp = !empty($_GET['stamp']);

        $cst = db()->prepare('SELECT * FROM carriers WHERE id = ?');
        $cst->execute([$carrierId]);
        $carrier = $cst->fetch() ?: (db()->query('SELECT * FROM carriers ORDER BY id LIMIT 1')->fetch() ?: ['atp' => '', 'contract_no' => '', 'contract_date' => '']);

        $req = [
            'frahtovatel' => opt('doc_frahtovatel', 'ООО «ТерраТрансКрым»'),
            'signer' => opt('doc_signer', ''),
            'stamp' => $withStamp ? opt('doc_stamp_url', '') : '',
            'sign' => $withStamp ? opt('doc_sign_url', '') : '',
        ];
        // картинки печати/подписи отдаём data-URL (Gotenberg рендерит без доступа к файлам по сети)
        foreach (['stamp', 'sign'] as $k) {
            if ($req[$k] !== '' && is_file(PANEL_ROOT . '/public' . $req[$k])) {
                $req[$k] = 'data:image/png;base64,' . base64_encode(file_get_contents(PANEL_ROOT . '/public' . $req[$k]));
            } else {
                $req[$k] = '';
            }
        }

        require_once PANEL_ROOT . '/app/doc_templates.php';
        $html = $type === 'road' ? doc_road($manifest, $carrier, $passengers, $req) : doc_driver($manifest, $carrier, $passengers, $req);

        $base = ($type === 'road' ? 'dorozhnaya' : 'voditel') . '_' . $manifest['trip_number'] . '_' . date('Ymd');

        if ($format === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        if ($format === 'word') {
            // .doc на основе HTML — открывается и редактируется в Word (альбомная ориентация)
            $wordHtml = str_replace('<head>',
                '<head><!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View></w:WordDocument></xml><![endif]-->'
                . '<style>@page{size:A4 landscape;mso-page-orientation:landscape;}</style>', $html);
            header('Content-Type: application/msword; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $base . '.doc"');
            echo $wordHtml;
            exit;
        }

        require_once PANEL_ROOT . '/lib/PdfService.php';
        $pdf = PdfService::htmlToPdf($html, 'landscape');
        if ($pdf === null) {
            http_response_code(502);
            die('Не удалось сформировать PDF (сервис Gotenberg недоступен).');
        }
        // inline — PDF открывается в браузере, скачать можно из просмотрщика
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $base . '.pdf"');
        echo $pdf;
        exit;

    case 'boarding':
        $m = db()->prepare('SELECT * FROM manifests WHERE id = ?');
        $m->execute([(int) ($_GET['id'] ?? 0)]);
        $manifest = $m->fetch();
        if (!$manifest) { http_response_code(404); die('Ведомость не найдена'); }
        $ps = db()->prepare('SELECT * FROM passengers WHERE manifest_id = ? ORDER BY sort, id');
        $ps->execute([$manifest['id']]);
        $passengers = $ps->fetchAll();
        view('boarding', ['manifest' => $manifest, 'passengers' => $passengers]);
        break;

    case 'notifications':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['manifest'])) {
            csrf_check();
            require_once PANEL_ROOT . '/app/manifest_import.php';
            try {
                $newId = import_manifest_csv($_FILES['manifest']);
                $cnt = db()->query('SELECT COUNT(*) FROM passengers WHERE manifest_id = ' . $newId)->fetchColumn();
                flash('Ведомость загружена: ' . $cnt . ' пассажиров.');
                header('Location: /?p=notifications&manifest_id=' . $newId);
                exit;
            } catch (Exception $e) {
                $uploadError = $e->getMessage();
            }
        }
        $manifests = db()->query('SELECT * FROM manifests ORDER BY id DESC LIMIT 30')->fetchAll();
        $fresh = !empty($_GET['fresh']);
        $selectedId = $fresh ? 0 : (int) ($_GET['manifest_id'] ?? ($manifests[0]['id'] ?? 0));
        $selected = null;
        foreach ($manifests as $mRow) {
            if ((int) $mRow['id'] === $selectedId) { $selected = $mRow; break; }
        }
        $buses = db()->query('SELECT * FROM buses ORDER BY code, model')->fetchAll();
        $drivers = db()->query('SELECT * FROM drivers ORDER BY name')->fetchAll();
        view('layout', ['title' => 'Уведомления', 'page' => 'notifications',
            'content' => fn() => view('notifications', ['manifests' => $manifests, 'selectedId' => $selectedId,
                'selected' => $selected, 'buses' => $buses, 'drivers' => $drivers,
                'uploadError' => $uploadError ?? ''])]);
        break;

    case 'broadcast':
        $manifests = db()->query('SELECT * FROM manifests ORDER BY id DESC LIMIT 30')->fetchAll();
        view('layout', ['title' => 'Свободная рассылка', 'page' => 'broadcast',
            'content' => fn() => view('broadcast', ['manifests' => $manifests])]);
        break;

    case 'logs':
        $filter = $_GET['f'] ?? 'all';
        if (!in_array($filter, ['all', 'failed', 'sent', 'undelivered'], true)) $filter = 'all';
        $where = match ($filter) {
            'failed'      => "status = 'failed'",
            'sent'        => "status = 'sent'",
            'undelivered' => "status = 'sent' AND delivered_at IS NULL",
            default       => '1',
        };
        try {
            $counts = db()->query("SELECT COUNT(*) total, SUM(status='failed') failed,
                    SUM(status='sent') sent, SUM(status='sent' AND delivered_at IS NULL) undelivered
                  FROM messages")->fetch();
            $rows = db()->query("SELECT * FROM messages WHERE $where ORDER BY id DESC LIMIT 300")->fetchAll();
        } catch (Exception $e) {
            $counts = ['total'=>0,'failed'=>0,'sent'=>0,'undelivered'=>0];
            $rows = [];
        }
        view('layout', ['title' => 'Логи', 'page' => 'logs',
            'content' => fn() => view('logs', ['filter' => $filter, 'counts' => $counts, 'rows' => $rows])]);
        break;

    case 'chats':
        $startPhone = (string) ($_GET['conversation_id'] ?? ($_GET['phone'] ?? ''));
        view('layout', ['title' => 'Чаты', 'page' => 'chats',
            'content' => fn() => view('chats', ['startPhone' => $startPhone])]);
        break;

    case 'sales':
        $period = $_GET['period'] ?? '7d';
        if (!in_array($period, ['today', '7d', '30d', 'all'], true)) $period = '7d';
        $where = match ($period) {
            'today' => 'occurred_at >= CURDATE()',
            '7d'    => 'occurred_at >= (CURDATE() - INTERVAL 6 DAY)',
            '30d'   => 'occurred_at >= (CURDATE() - INTERVAL 29 DAY)',
            default => '1',
        };
        try {
            $metrics = db()->query("SELECT
                    SUM(kind='sale') sales_cnt,
                    SUM(kind='refund') refund_cnt,
                    SUM(kind='cancel') cancel_cnt,
                    COALESCE(SUM(CASE WHEN kind IN ('sale','payment') THEN amount END),0) sales_sum,
                    COALESCE(SUM(CASE WHEN kind='refund' THEN amount END),0) refund_sum,
                    COUNT(*) total
                  FROM sales WHERE $where")->fetch();
            $byChannel = db()->query("SELECT channel,
                    SUM(kind='sale') sales, SUM(kind='refund') refunds, SUM(kind='cancel') cancels,
                    COALESCE(SUM(CASE WHEN kind IN ('sale','payment') THEN amount END),0) sum
                  FROM sales WHERE $where GROUP BY channel ORDER BY sales DESC, channel")->fetchAll();
            $topDates = db()->query("SELECT DATE(depart_at) d, COUNT(*) c FROM sales
                  WHERE $where AND kind='sale' AND depart_at IS NOT NULL
                  GROUP BY DATE(depart_at) ORDER BY c DESC, d LIMIT 8")->fetchAll();
            $feed = db()->query("SELECT * FROM sales WHERE $where ORDER BY occurred_at DESC LIMIT 60")->fetchAll();
        } catch (Exception $e) {
            $metrics = ['sales_cnt'=>0,'refund_cnt'=>0,'cancel_cnt'=>0,'sales_sum'=>0,'refund_sum'=>0,'total'=>0];
            $byChannel = $topDates = $feed = [];
        }
        view('layout', ['title' => 'Продажи', 'page' => 'sales',
            'content' => fn() => view('sales', ['period' => $period, 'metrics' => $metrics,
                'byChannel' => $byChannel, 'topDates' => $topDates, 'feed' => $feed])]);
        break;

    case 'catalogs':
        view('layout', ['title' => 'Справочники', 'page' => 'catalogs',
            'content' => fn() => view('catalogs')]);
        break;

    case 'contacts':
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = in_array($_GET['sort'] ?? '', ['name', 'messages_count', 'trips_count', 'last_seen'], true) ? $_GET['sort'] : 'last_seen';
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = 'WHERE phone LIKE ? OR name LIKE ? OR tags LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }
        $st = db()->prepare("SELECT * FROM contacts $where ORDER BY $sort " . ($sort === 'name' ? 'ASC' : 'DESC') . " LIMIT 500");
        $st->execute($params);
        $contacts = $st->fetchAll();
        $total = (int) db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        view('layout', ['title' => 'Контакты', 'page' => 'contacts',
            'content' => fn() => view('contacts', ['contacts' => $contacts, 'total' => $total, 'q' => $q, 'sort' => $sort])]);
        break;

    case 'contact':
        $st = db()->prepare('SELECT * FROM contacts WHERE id = ?');
        $st->execute([(int) ($_GET['id'] ?? 0)]);
        $contact = $st->fetch();
        if (!$contact) { http_response_code(404); die('Контакт не найден'); }
        $hist = db()->prepare('SELECT * FROM messages WHERE recipient = ? ORDER BY id DESC LIMIT 100');
        $hist->execute([$contact['phone']]);
        $history = $hist->fetchAll();
        try {
            $inb = db()->prepare('SELECT * FROM inbox WHERE phone = ? ORDER BY id DESC LIMIT 50');
            $inb->execute([$contact['phone']]);
            $incoming = $inb->fetchAll();
        } catch (Exception $e) {
            $incoming = [];
        }
        view('layout', ['title' => $contact['name'] ?: $contact['phone'], 'page' => 'contacts',
            'content' => fn() => view('contact', ['contact' => $contact, 'history' => $history, 'incoming' => $incoming])]);
        break;

    // Свод по месяцу в CSV (BOM + «;» — чтобы Excel открывал без танцев)
    case 'report_month_export':
        require_once PANEL_ROOT . '/app/reporting_service.php';
        $month = (string) ($_GET['month'] ?? date('Y-m'));
        $sum = reporting_month_summary($month, (string) ($_GET['scenario'] ?? ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reys_' . $month . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Дата', 'Рейс', 'Маршрут', 'Перевозчик', 'Автобус', 'Расчёт',
            'Пассажиров', 'Оборот', 'Ведомость', 'Автовокзалы', 'Наличные',
            'Продажи Терры', 'Продажи перевозчика', 'Диспетчерские', 'Комиссия Терры',
            'Комиссии агентов', 'Разница цен', 'Доход с неявок',
            'Наша прибыль', 'Маржа, %', 'Доход перевозчика'], ';', '"', '\\');
        foreach ($sum['rows'] as $r) {
            fputcsv($out, [
                $r['departure_at'] ? date('d.m.Y H:i', strtotime($r['departure_at'])) : '',
                $r['trip_number'], $r['route'], $r['carrier'], $r['bus'], $r['scenario_name'],
                $r['pax'], $r['turnover'], $r['manifest_total'], $r['stations_total'], $r['cash'],
                $r['our_sales'], $r['carrier_sales'], $r['dispatch_fee'], $r['our_commission'],
                $r['agent_commission'], $r['extra'], $r['noshow_income'],
                $r['our_profit'], $r['margin'], $r['carrier_earn'],
            ], ';', '"', '\\');
        }
        $t = $sum['totals'];
        fputcsv($out, ['ИТОГО за ' . $month, $t['trips'] . ' рейсов', '', '', '', '',
            $t['pax'], $t['turnover'], $t['manifest_total'], $t['stations_total'], $t['cash'],
            $t['our_sales'], $t['carrier_sales'], $t['dispatch_fee'], $t['our_commission'],
            $t['agent_commission'], $t['extra'], $t['noshow_income'],
            $t['our_profit'], $t['margin'], $t['carrier_earn']], ';', '"', '\\');
        fclose($out);
        exit;

    case 'contacts_export':
        $rows = db()->query('SELECT phone, name, messages_count, trips_count, last_route, last_seen, tags, note FROM contacts ORDER BY name')->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts_' . date('Ymd_Hi') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Телефон', 'Имя', 'Сообщений', 'Поездок', 'Последний маршрут', 'Последний контакт', 'Теги', 'Заметка'], ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, [$r['phone'], $r['name'], $r['messages_count'], $r['trips_count'], $r['last_route'], $r['last_seen'], $r['tags'], $r['note']], ';', '"', '\\');
        }
        fclose($out);
        exit;

    case 'settings':
        view('layout', ['title' => 'Настройки', 'page' => 'settings',
            'content' => fn() => view('settings')]);
        break;

    default:
        http_response_code(404);
        die('Страница не найдена');
}
