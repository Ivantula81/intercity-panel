<?php

// JSON API панели. POST с заголовком X-CSRF (загрузка файлов — multipart с полем csrf).

require_once PANEL_ROOT . '/app/contacts.php';

$action = $_GET['a'] ?? '';
$body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

function env_get(string $key): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $env[$k] = $v;
        }
    }
    return $env[$key] ?? '';
}

// Активный для отправки инстанс WhatsApp (из wa_accounts, иначе из env).
function active_wa_instance(): string
{
    try {
        $v = db()->query('SELECT instance FROM wa_accounts WHERE is_active = 1 LIMIT 1')->fetchColumn();
        if ($v) return (string) $v;
    } catch (Exception $e) {}
    return env_get('EVO_INSTANCE') ?: 'intercity';
}

// Клиент Evolution для конкретного инстанса (по умолчанию — активный).
function evolution(?string $instance = null): EvolutionApiClient
{
    require_once PANEL_ROOT . '/lib/EvolutionApiClient.php';
    return new EvolutionApiClient(
        env_get('EVO_URL') ?: 'http://127.0.0.1:8080',
        $instance ?: active_wa_instance(),
        env_get('EVO_APIKEY')
    );
}

function green_api()
{
    require_once PANEL_ROOT . '/lib/GreenApiClient.php';
    return new GreenApiClient(env_get('GREENAPI_URL'), env_get('GREENAPI_ID'), env_get('GREENAPI_TOKEN'));
}

function smtp_mailer()
{
    require_once PANEL_ROOT . '/lib/SmtpMailer.php';
    return new SmtpMailer([
        'host' => env_get('SMTP_HOST') ?: 'connect.smtp.bz',
        'port' => (int) (env_get('SMTP_PORT') ?: 2525),
        'user' => env_get('SMTP_USER'),
        'pass' => env_get('SMTP_PASS'),
        'from' => opt('smtp_from', '') ?: env_get('SMTP_FROM'),
        'from_name' => opt('smtp_from_name', '') ?: (env_get('SMTP_FROM_NAME') ?: 'Интерсити Тур'),
        'reply' => opt('smtp_reply', ''),
    ]);
}

// Провайдер активного аккаунта рассылки
function active_wa_provider(): string
{
    try {
        $v = db()->query('SELECT provider FROM wa_accounts WHERE is_active = 1 LIMIT 1')->fetchColumn();
        if ($v) return (string) $v;
    } catch (Exception $e) {}
    return 'evolution';
}

// Клиент активного канала рассылки (Evolution или Green API) — единый интерфейс send*.
function active_wa_client()
{
    return active_wa_provider() === 'greenapi' ? green_api() : evolution();
}

// Грубая транслитерация для имени инстанса
function translit_lat(string $s): string
{
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
        'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h',
        'ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

// Международный формат: +7..., +381... и т.п.
function valid_phone(string $p): bool
{
    return (bool) preg_match('/^\+\d{10,15}$/', $p);
}

function normalize_phone(string $p): string
{
    $digits = preg_replace('/\D+/', '', $p);
    if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
    if (strlen($digits) === 10 && $digits[0] === '9') $digits = '7' . $digits;
    return $digits !== '' ? '+' . $digits : '';
}

function get_manifest(int $id): array
{
    $st = db()->prepare('SELECT * FROM manifests WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) json_out(['ok' => false, 'error' => 'Ведомость не найдена'], 404);
    return $m;
}

function manifest_bus(array $manifest): ?array
{
    $busStr = mb_strtolower($manifest['bus'] . ' ' . $manifest['drivers'], 'UTF-8');
    foreach (db()->query('SELECT * FROM buses')->fetchAll() as $b) {
        foreach ([$b['plate'], $b['code']] as $needle) {
            $needle = mb_strtolower(trim((string) $needle), 'UTF-8');
            if ($needle !== '' && mb_strlen($needle) >= 3 && str_contains($busStr, $needle)) {
                return $b;
            }
        }
    }
    return null;
}

// Пользовательские переменные из справочника: ['{имя}' => значение]
function custom_vars(): array
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        try {
            foreach (db()->query('SELECT name, value FROM variables')->fetchAll() as $v) {
                $vars['{' . $v['name'] . '}'] = $v['value'];
            }
        } catch (Exception $e) {
            // таблицы может не быть до применения schema4
        }
    }
    return $vars;
}

// Переменные сообщения для пассажира с учётом группы посадки
function group_vars(array $manifest, array $passenger, array $group): array
{
    require_once PANEL_ROOT . '/lib/MessageTemplate.php';
    $trip = [
        'departure_at' => $manifest['departure_at'] ? date('d.m.Y H:i', strtotime($manifest['departure_at'])) : '',
        'route' => $manifest['route'],
        'bus' => $manifest['bus'],
        'carrier' => $manifest['carrier'],
    ];
    $vars = MessageTemplate::varsForPassenger($trip, [
        'name' => $passenger['name'], 'seat' => $passenger['seat'],
        'from' => $passenger['from_stop'], 'to' => $passenger['to_stop'],
    ]);

    $boarding = $group['station'] ?? $passenger['from_stop'];
    if (!empty($group['address'])) $boarding .= ', ' . $group['address'];

    return array_merge(custom_vars(), $vars, [
        '{дата_рейса}' => $manifest['departure_at'] ? date('d.m.Y', strtotime($manifest['departure_at'])) : '',
        '{дата}' => $group['date'] ?: ($manifest['departure_at'] ? date('d.m.Y', strtotime($manifest['departure_at'])) : ''),
        '{время}' => $group['time'] ?? '',
        '{посадка}' => $boarding,
        '{карта}' => $group['map_url'] ?? '',
        '{тел_водителя}' => $manifest['driver_phone'] ?: '—',
        '{доп}' => $manifest['extra_info'],
    ]);
}

// Сборка групп по точке посадки
function build_groups(array $manifest): array
{
    $ps = db()->prepare('SELECT * FROM passengers WHERE manifest_id = ? ORDER BY sort, id');
    $ps->execute([$manifest['id']]);
    $passengers = $ps->fetchAll();

    $catalogByName = [];
    $catalogById = [];
    foreach (db()->query('SELECT * FROM stops')->fetchAll() as $s) {
        $catalogByName[mb_strtolower(trim($s['station']), 'UTF-8')] = $s;
        if ($s['gds_id'] !== null) $catalogById[(int) $s['gds_id']] = $s;
    }

    $drafts = [];
    $dst = db()->prepare('SELECT * FROM manifest_groups WHERE manifest_id = ?');
    $dst->execute([$manifest['id']]);
    foreach ($dst->fetchAll() as $d) {
        $drafts[$d['station']] = $d;
    }

    $groups = [];
    foreach ($passengers as $p) {
        $stationId = $p['from_id'] !== null ? (int) $p['from_id'] : null;
        $station = trim($p['from_stop']) !== '' ? trim($p['from_stop']) : '(посадка не указана)';
        // ключ группы — id станции (надёжно), при его отсутствии — название
        $key = $stationId !== null ? 'id' . $stationId : 'nm' . mb_strtolower($station, 'UTF-8');

        if (!isset($groups[$key])) {
            // справочник: сперва по id, потом по названию
            $cat = ($stationId !== null ? ($catalogById[$stationId] ?? null) : null)
                ?? ($catalogByName[mb_strtolower($station, 'UTF-8')] ?? null);
            $draft = $drafts[$station] ?? null;
            $groups[$key] = [
                'station' => $station,
                'station_id' => $stationId,
                'address' => $cat['address'] ?? '',
                'map_url' => $cat['map_url'] ?? '',
                'in_catalog' => $cat !== null && trim((string) ($cat['address'] ?? '')) !== '',
                'date' => $draft['boarding_date'] ?? '',
                'time' => $draft['boarding_time'] ?? '',
                'time_warning' => (int) ($draft['time_warning'] ?? 0),
                'body' => $draft['body'] ?? null,
                'recipients' => [],
            ];
        }
        $phone = $p['phone'];
        $groups[$key]['recipients'][] = [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'phone' => $phone,
            'valid' => valid_phone($phone),
            'to' => $p['to_stop'],
            'note' => $p['note'],
        ];
    }

    return array_values($groups);
}

const P_FIELDS = ['seat', 'name', 'phone', 'doc', 'ticket', 'from_stop', 'to_stop', 'note', 'pay_note', 'citizenship'];
const M_FIELDS = ['route', 'carrier', 'bus', 'drivers', 'trip_number', 'driver_phone', 'extra_info'];

switch ($action) {

    /* ── Пассажиры и ведомость ── */

    case 'passenger.update':
        $f = (string) ($body['field'] ?? '');
        if (!in_array($f, P_FIELDS, true)) json_out(['ok' => false, 'error' => 'bad field'], 400);
        $v = (string) $body['value'];
        if ($f === 'phone' && trim($v) !== '') $v = normalize_phone($v) ?: $v;
        db()->prepare("UPDATE passengers SET `$f` = ? WHERE id = ?")->execute([$v, (int) $body['id']]);
        json_out(['ok' => true, 'value' => $v]);

    case 'passenger.add':
        db()->prepare('INSERT INTO passengers (manifest_id, name, phone, from_stop, to_stop, sort)
            SELECT ?, ?, ?, ?, ?, COALESCE(MAX(sort),0)+1 FROM passengers WHERE manifest_id = ?')
            ->execute([
                (int) $body['manifest_id'], trim((string) ($body['name'] ?? '')),
                normalize_phone((string) ($body['phone'] ?? '')),
                trim((string) ($body['from_stop'] ?? '')), trim((string) ($body['to_stop'] ?? '')),
                (int) $body['manifest_id'],
            ]);
        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()]);

    case 'passenger.delete':
        db()->prepare('DELETE FROM passengers WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'manifest.update':
        $f = (string) ($body['field'] ?? '');
        $v = (string) ($body['value'] ?? '');
        if ($f === 'departure_view') {
            $dt = null;
            if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', $v, $m)) {
                $dt = sprintf('%s-%s-%s %02d:%02d:00', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0);
            }
            db()->prepare('UPDATE manifests SET departure_at = ? WHERE id = ?')->execute([$dt, (int) $body['id']]);
            json_out(['ok' => true]);
        }
        if (!in_array($f, M_FIELDS, true)) json_out(['ok' => false, 'error' => 'bad field'], 400);
        db()->prepare("UPDATE manifests SET `$f` = ? WHERE id = ?")->execute([$v, (int) $body['id']]);
        json_out(['ok' => true]);

    case 'manifest.confirm':
        db()->prepare('UPDATE manifests SET confirmed = ? WHERE id = ?')
            ->execute([(int) !empty($body['confirmed']), (int) $body['id']]);
        json_out(['ok' => true]);

    case 'manifest.delete':
        db()->prepare('DELETE FROM passengers WHERE manifest_id = ?')->execute([(int) $body['id']]);
        db()->prepare('DELETE FROM manifest_groups WHERE manifest_id = ?')->execute([(int) $body['id']]);
        db()->prepare('DELETE FROM manifests WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    /* ── Группы и GDS ── */

    case 'groups':
        $manifest = get_manifest((int) $body['manifest_id']);
        $bus = manifest_bus($manifest);
        json_out([
            'ok' => true,
            'manifest' => [
                'id' => (int) $manifest['id'],
                'route' => $manifest['route'],
                'trip_number' => $manifest['trip_number'],
                'departure' => $manifest['departure_at'] ? date('d.m.Y H:i', strtotime($manifest['departure_at'])) : '',
                'confirmed' => (int) $manifest['confirmed'],
                'bus' => $manifest['bus'],
            ],
            'bus_photo' => $bus && $bus['photo'] !== '' ? $bus['photo'] : '',
            'groups' => build_groups($manifest),
            'templates' => db()->query('SELECT id, name, body FROM templates ORDER BY sort, id')->fetchAll(),
        ]);

    case 'gds.times':
        require_once PANEL_ROOT . '/lib/GdsRace.php';
        $manifest = get_manifest((int) $body['manifest_id']);
        if (!empty($body['refresh'])) opt_set('gds_stops_' . $manifest['id'], '');

        $gdsError = '';
        try {
            $gds = GdsRace::stopsForManifest($manifest);
        } catch (Exception $e) {
            $gdsError = $e->getMessage();
            $gds = GdsRace::cachedStopsForManifest($manifest); // резерв из справочника расписаний
            if ($gds === null) {
                json_out(['ok' => false, 'error' => $gdsError . ' Сохранённого расписания по этому маршруту пока нет.']);
            }
        }

        $fromCache = !empty($gds['from_cache']);
        $raceStartTime = $gds['race_start'] ? date('H:i', strtotime($gds['race_start'])) : '';
        $updated = 0;
        $unmatched = [];
        foreach (build_groups($manifest) as $g) {
            $stop = GdsRace::matchStop($gds, $g['station'], $g['station_id'] ?? null);
            if ($stop === null) {
                $unmatched[] = $g['station'];
                continue;
            }
            $when = $stop['arrival'] !== '' ? $stop['arrival'] : $stop['dispatch'];
            $ts = strtotime($when);
            $time = $ts ? date('H:i', $ts) : '';
            $date = $ts ? date('d.m.Y', $ts) : '';
            // станция отправления — совпадение со стартом это норма; для остальных — предупреждение
            $isStart = GdsRace::norm($g['station']) === GdsRace::norm(explode('-', $manifest['route'])[0] ?? '');
            $warning = (int) (!$isStart && $time !== '' && $time === $raceStartTime);
            db()->prepare('INSERT INTO manifest_groups (manifest_id, station, station_id, boarding_date, boarding_time, time_warning)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE station_id = VALUES(station_id), boarding_date = VALUES(boarding_date), boarding_time = VALUES(boarding_time), time_warning = VALUES(time_warning)')
                ->execute([$manifest['id'], $g['station'], $g['station_id'] ?? null, $date, $time, $warning]);
            $updated++;
        }
        json_out(['ok' => true, 'race_uid' => $gds['race_uid'], 'race_start' => $gds['race_start'],
            'statement_match' => $gds['statement_match'], 'updated' => $updated, 'unmatched' => $unmatched,
            'from_cache' => $fromCache, 'cached_at' => $gds['cached_at'] ?? '', 'gds_error' => $gdsError]);

    case 'group.save':
        $manifest = get_manifest((int) $body['manifest_id']);
        db()->prepare('INSERT INTO manifest_groups (manifest_id, station, boarding_date, boarding_time, body)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE boarding_date = VALUES(boarding_date), boarding_time = VALUES(boarding_time), body = VALUES(body)')
            ->execute([
                $manifest['id'], (string) $body['station'],
                trim((string) ($body['date'] ?? '')), trim((string) ($body['time'] ?? '')),
                $body['body'] === null ? null : (string) $body['body'],
            ]);
        json_out(['ok' => true]);

    case 'group.preview':
        $manifest = get_manifest((int) $body['manifest_id']);
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        foreach (build_groups($manifest) as $g) {
            if ($g['station'] !== (string) $body['station']) continue;
            $g['date'] = trim((string) ($body['date'] ?? $g['date']));
            $g['time'] = trim((string) ($body['time'] ?? $g['time']));
            $first = $g['recipients'][0] ?? null;
            if (!$first) json_out(['ok' => true, 'preview' => '']);
            $pst = db()->prepare('SELECT * FROM passengers WHERE id = ?');
            $pst->execute([$first['id']]);
            $p = $pst->fetch();
            $vars = group_vars($manifest, $p, $g);
            json_out([
                'ok' => true,
                'preview' => MessageTemplate::render((string) $body['text'], $vars),
                'unknown' => MessageTemplate::unknownVars((string) $body['text'], $vars),
            ]);
        }
        json_out(['ok' => false, 'error' => 'Группа не найдена']);

    /* ── Отправка ── */

    case 'campaign.send':
        set_time_limit(0);
        $manifest = get_manifest((int) $body['manifest_id']);
        $evo = active_wa_client();
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен']);

        $station = (string) ($body['station'] ?? '');
        $group = null;
        foreach (build_groups($manifest) as $g) {
            if ($g['station'] === $station) { $group = $g; break; }
        }
        if ($group === null) json_out(['ok' => false, 'error' => 'Группа не найдена']);
        $group['date'] = trim((string) ($body['date'] ?? $group['date']));
        $group['time'] = trim((string) ($body['time'] ?? $group['time']));

        $busPhoto = '';
        if (!empty($body['attach_photo'])) {
            $bus = manifest_bus($manifest);
            if ($bus && $bus['photo'] !== '' && is_file(PANEL_ROOT . '/public' . $bus['photo'])) {
                $busPhoto = PANEL_ROOT . '/public' . $bus['photo'];
            }
        }

        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $ids = array_map('intval', (array) ($body['ids'] ?? []));
        $tpl = (string) ($body['text'] ?? '');
        $sent = 0; $failed = 0; $skipped = 0; $errors = []; $seenPhones = []; $batch = 0;

        foreach ($ids as $pid) {
            if ($batch >= 20) break;
            $pst = db()->prepare('SELECT * FROM passengers WHERE id = ? AND manifest_id = ?');
            $pst->execute([$pid, $manifest['id']]);
            $p = $pst->fetch();
            if (!$p || !valid_phone($p['phone'])) { $skipped++; continue; }
            if (isset($seenPhones[$p['phone']])) { $skipped++; continue; }
            $seenPhones[$p['phone']] = true;
            $batch++;

            $msg = MessageTemplate::render($tpl, group_vars($manifest, $p, $group));
            db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (?,?,?,?,?,?)')
                ->execute([$manifest['id'], 'whatsapp', $p['phone'], $p['name'], $msg, current_user_name()]);
            $mid = (int) db()->lastInsertId();

            $res = $busPhoto !== '' ? $evo->sendImage($p['phone'], $busPhoto, $msg) : $evo->sendText($p['phone'], $msg);
            if ($res['ok']) {
                db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
                contact_log_message($p['phone'], $p['name'], $manifest['route']);
                $sent++;
            } else {
                db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'], $mid]);
                $failed++;
                $errors[] = $p['phone'] . ': ' . $res['error'];
            }
            if ($batch < min(count($ids), 20)) sleep(rand(2, 4));
        }
        $rest = max(0, count($ids) - $batch - $skipped);
        json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'rest' => $rest,
            'errors' => array_slice($errors, 0, 5)]);

    case 'send.single':
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $phone = normalize_phone((string) ($body['phone'] ?? ''));
        $text = MessageTemplate::render(trim((string) ($body['text'] ?? '')), custom_vars());
        if (!valid_phone($phone)) json_out(['ok' => false, 'error' => 'Укажите корректный номер телефона.']);
        if ($text === '') json_out(['ok' => false, 'error' => 'Введите текст сообщения.']);
        $evo = active_wa_client();
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен.']);

        db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
            ->execute(['whatsapp', $phone, 'Произвольный номер', $text, current_user_name()]);
        $mid = (int) db()->lastInsertId();
        $res = $evo->sendText($phone, $text);
        if ($res['ok']) {
            db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
            contact_log_message($phone, '', '');
            json_out(['ok' => true]);
        }
        db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'], $mid]);
        json_out(['ok' => false, 'error' => $res['error']]);

    case 'broadcast.send':
        set_time_limit(0);
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $evo = active_wa_client();
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен']);
        $text = MessageTemplate::render(trim((string) ($body['text'] ?? '')), custom_vars());
        $image = (string) ($body['image'] ?? '');
        $imagePath = $image !== '' && str_starts_with($image, '/uploads/') ? PANEL_ROOT . '/public' . $image : '';
        if ($text === '' && $imagePath === '') json_out(['ok' => false, 'error' => 'Введите текст или приложите картинку.']);

        $phones = [];
        foreach (preg_split('/[\s,;]+/', (string) ($body['phones'] ?? '')) as $raw) {
            $p = normalize_phone($raw);
            if (valid_phone($p)) $phones[$p] = true;
        }
        $phones = array_keys($phones);
        if (!$phones) json_out(['ok' => false, 'error' => 'Нет корректных номеров.']);

        $sent = 0; $failed = 0; $errors = []; $batch = 0;
        foreach ($phones as $phone) {
            if ($batch >= 20) break;
            $batch++;
            db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
                ->execute(['whatsapp', $phone, 'Свободная рассылка', $text, current_user_name()]);
            $mid = (int) db()->lastInsertId();
            $res = $imagePath !== '' && is_file($imagePath) ? $evo->sendImage($phone, $imagePath, $text) : $evo->sendText($phone, $text);
            if ($res['ok']) {
                db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
                contact_log_message($phone, '', '');
                $sent++;
            } else {
                db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'], $mid]);
                $failed++;
                $errors[] = $phone . ': ' . $res['error'];
            }
            if ($batch < min(count($phones), 20)) sleep(rand(2, 4));
        }
        json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'rest' => max(0, count($phones) - $batch),
            'errors' => array_slice($errors, 0, 5)]);

    case 'manifest.phones':
        $manifest = get_manifest((int) $body['manifest_id']);
        $phones = [];
        foreach (build_groups($manifest) as $g) {
            foreach ($g['recipients'] as $r) {
                if ($r['valid']) $phones[$r['phone']] = true;
            }
        }
        json_out(['ok' => true, 'phones' => array_keys($phones)]);

    /* ── Чаты ── */

    case 'chat.threads':
        // последнее сообщение по каждому контакту (вход + исход), непрочитанные
        $rows = db()->query(
            "SELECT phone, name, body, ts, dir FROM (
                SELECT recipient phone, passenger_name name, body, COALESCE(sent_at, created_at) ts, 'out' dir
                  FROM messages WHERE recipient LIKE '+%'
                UNION ALL
                SELECT phone, name, body, received_at ts, 'in' dir FROM inbox
             ) u ORDER BY ts DESC"
        )->fetchAll();

        $unread = [];
        foreach (db()->query("SELECT phone, COUNT(*) c FROM inbox WHERE is_read = 0 GROUP BY phone")->fetchAll() as $u) {
            $unread[$u['phone']] = (int) $u['c'];
        }
        $names = [];
        foreach (db()->query("SELECT phone, name FROM contacts WHERE name <> ''")->fetchAll() as $c) {
            $names[$c['phone']] = $c['name'];
        }

        $threads = [];
        foreach ($rows as $r) {
            $ph = $r['phone'];
            if (isset($threads[$ph])) continue; // уже взяли последнее
            $threads[$ph] = [
                'phone' => $ph,
                'name' => $names[$ph] ?? ($r['name'] ?: ''),
                'last' => mb_substr((string) $r['body'], 0, 70),
                'last_at' => $r['ts'],
                'last_dir' => $r['dir'],
                'unread' => $unread[$ph] ?? 0,
            ];
        }
        json_out(['ok' => true, 'threads' => array_values($threads)]);

    case 'chat.messages':
        $phone = (string) ($body['phone'] ?? '');
        if ($phone === '') json_out(['ok' => false, 'error' => 'нет номера']);
        $st = db()->prepare(
            "SELECT body, ts, dir, status, delivered_at, read_at, channel, media_url, media_type FROM (
                SELECT body, COALESCE(sent_at, created_at) ts, 'out' dir, status, delivered_at, read_at, channel,
                       '' media_url, '' media_type
                  FROM messages WHERE recipient = ?
                UNION ALL
                SELECT body, received_at ts, 'in' dir, NULL status, NULL delivered_at, NULL read_at, instance channel,
                       media_url, media_type
                  FROM inbox WHERE phone = ?
             ) u ORDER BY ts ASC LIMIT 500"
        );
        $st->execute([$phone, $phone]);
        $msgs = [];
        foreach ($st->fetchAll() as $m) {
            $msgs[] = [
                'body' => $m['body'],
                'ts' => $m['ts'],
                'dir' => $m['dir'],
                'status' => $m['status'],
                'delivered' => $m['delivered_at'] !== null,
                'read' => $m['read_at'] !== null,
                'media' => $m['media_url'] ?: '',
                'media_type' => $m['media_type'] ?: '',
            ];
        }
        // имя контакта
        $nm = db()->prepare("SELECT name FROM contacts WHERE phone = ?");
        $nm->execute([$phone]);
        $name = (string) ($nm->fetchColumn() ?: '');
        json_out(['ok' => true, 'name' => $name, 'phone' => $phone, 'messages' => $msgs]);

    case 'chat.markread':
        $phone = (string) ($body['phone'] ?? '');
        if ($phone !== '') db()->prepare("UPDATE inbox SET is_read = 1 WHERE phone = ?")->execute([$phone]);
        json_out(['ok' => true]);

    case 'chat.send':
        $phone = normalize_phone((string) ($body['phone'] ?? ''));
        $text = trim((string) ($body['text'] ?? ''));
        if (!valid_phone($phone)) json_out(['ok' => false, 'error' => 'Некорректный номер']);
        if ($text === '') json_out(['ok' => false, 'error' => 'Пустое сообщение']);
        $evo = active_wa_client();
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'Канал не настроен']);

        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $text = MessageTemplate::render($text, custom_vars());
        db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
            ->execute(['whatsapp', $phone, 'Чат', $text, current_user_name()]);
        $mid = (int) db()->lastInsertId();
        $res = $evo->sendText($phone, $text);
        if ($res['ok']) {
            db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")
                ->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
            contact_log_message($phone, '', '');
            json_out(['ok' => true]);
        }
        db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'], $mid]);
        json_out(['ok' => false, 'error' => $res['error']]);

    /* ── Загрузка файлов ── */

    case 'manifest.import':
        require_once PANEL_ROOT . '/app/manifest_import.php';
        if (empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'Файл не передан']);
        try {
            $id = import_manifest_csv($_FILES['file']);
            json_out(['ok' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }

    case 'carrier.save':
        $id = (int) ($body['id'] ?? 0);
        $vals = [trim((string) $body['atp']), trim((string) ($body['contract_no'] ?? '')),
            trim((string) ($body['contract_date'] ?? '')), trim((string) ($body['note'] ?? ''))];
        if ($vals[0] === '') json_out(['ok' => false, 'error' => 'Укажите перевозчика']);
        if ($id > 0) {
            db()->prepare('UPDATE carriers SET atp=?, contract_no=?, contract_date=?, note=? WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO carriers (atp, contract_no, contract_date, note) VALUES (?,?,?,?)')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'carrier.delete':
        db()->prepare('DELETE FROM carriers WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'doc_req.save':
        opt_set('doc_frahtovatel', trim((string) ($body['frahtovatel'] ?? '')));
        opt_set('doc_signer', trim((string) ($body['signer'] ?? '')));
        json_out(['ok' => true]);

    case 'upload':
        $kind = $_POST['kind'] ?? '';
        if (!in_array($kind, ['bus', 'broadcast', 'stamp', 'sign'], true)) json_out(['ok' => false, 'error' => 'bad kind'], 400);
        if (empty($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'Файл не загружен']);
        }
        if ((int) $_FILES['file']['size'] > 5 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Картинка больше 5 МБ']);
        $info = @getimagesize($_FILES['file']['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            json_out(['ok' => false, 'error' => 'Только JPG, PNG или WebP']);
        }
        $ext = image_type_to_extension($info[2], false);
        $dir = PANEL_ROOT . '/public/uploads/' . $kind;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = $kind . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $name);
        $url = '/uploads/' . $kind . '/' . $name;
        if ($kind === 'bus' && !empty($_POST['bus_id'])) {
            db()->prepare('UPDATE buses SET photo = ? WHERE id = ?')->execute([$url, (int) $_POST['bus_id']]);
        } elseif ($kind === 'stamp') {
            opt_set('doc_stamp_url', $url);
        } elseif ($kind === 'sign') {
            opt_set('doc_sign_url', $url);
        }
        json_out(['ok' => true, 'url' => $url]);

    /* ── Справочники ── */

    case 'stop.save':
        $id = (int) ($body['id'] ?? 0);
        $gdsId = ctype_digit((string) ($body['gds_id'] ?? '')) ? (int) $body['gds_id'] : null;
        $vals = [$gdsId, trim((string) $body['station']), trim((string) ($body['city'] ?? '')),
            trim((string) ($body['address'] ?? '')), trim((string) ($body['map_url'] ?? '')), trim((string) ($body['note'] ?? ''))];
        if ($vals[1] === '') json_out(['ok' => false, 'error' => 'Укажите станцию']);
        if ($id > 0) {
            db()->prepare('UPDATE stops SET gds_id=?, station=?, city=?, address=?, map_url=?, note=? WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO stops (gds_id, station, city, address, map_url, note) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE gds_id=VALUES(gds_id), city=VALUES(city), address=VALUES(address), map_url=VALUES(map_url), note=VALUES(note)')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'stop.delete':
        db()->prepare('DELETE FROM stops WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'bus.save':
        $id = (int) ($body['id'] ?? 0);
        $vals = [trim((string) ($body['code'] ?? '')), trim((string) $body['plate']), trim((string) ($body['model'] ?? '')),
            (int) ($body['seats'] ?? 0), normalize_phone((string) ($body['driver_phone'] ?? '')), trim((string) ($body['note'] ?? ''))];
        if ($id > 0) {
            db()->prepare('UPDATE buses SET code=?, plate=?, model=?, seats=?, driver_phone=?, note=? WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO buses (code, plate, model, seats, driver_phone, note) VALUES (?,?,?,?,?,?)')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'bus.delete':
        db()->prepare('DELETE FROM buses WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'driver.save':
        $id = (int) ($body['id'] ?? 0);
        $vals = [trim((string) $body['name']), normalize_phone((string) ($body['phone'] ?? '')),
            $body['bus_id'] !== '' && $body['bus_id'] !== null ? (int) $body['bus_id'] : null, trim((string) ($body['note'] ?? ''))];
        if ($id > 0) {
            db()->prepare('UPDATE drivers SET name=?, phone=?, bus_id=?, note=? WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO drivers (name, phone, bus_id, note) VALUES (?,?,?,?)')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'driver.delete':
        db()->prepare('DELETE FROM drivers WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'var.save':
        $id = (int) ($body['id'] ?? 0);
        $name = mb_strtolower(trim((string) $body['name'], "{} \t"), 'UTF-8');
        $name = preg_replace('/[^a-zа-я0-9_]+/u', '_', $name);
        if ($name === '') json_out(['ok' => false, 'error' => 'Укажите имя переменной']);
        $vals = [$name, (string) ($body['value'] ?? ''), trim((string) ($body['note'] ?? ''))];
        if ($id > 0) {
            db()->prepare('UPDATE variables SET name=?, value=?, note=? WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO variables (name, value, note) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value), note=VALUES(note)')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id, 'name' => $name]);

    case 'var.delete':
        db()->prepare('DELETE FROM variables WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'tpl.save':
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) $body['name']);
        $tbody = (string) $body['body'];
        if ($name === '') json_out(['ok' => false, 'error' => 'Укажите название шаблона']);
        if ($id > 0) {
            db()->prepare('UPDATE templates SET name=?, body=? WHERE id=?')->execute([$name, $tbody, $id]);
        } else {
            db()->prepare('INSERT INTO templates (name, body, sort) VALUES (?,?, (SELECT COALESCE(MAX(t.sort),0)+1 FROM templates t))')->execute([$name, $tbody]);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'tpl.delete':
        db()->prepare('DELETE FROM templates WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    /* ── Сервисы дашборда, WhatsApp, настройки ── */

    case 'contact.update':
        $f = (string) ($body['field'] ?? '');
        if (!in_array($f, ['name', 'tags', 'note'], true)) json_out(['ok' => false, 'error' => 'bad field'], 400);
        db()->prepare("UPDATE contacts SET `$f` = ? WHERE id = ?")->execute([(string) $body['value'], (int) $body['id']]);
        json_out(['ok' => true]);

    case 'contact.delete':
        db()->prepare('DELETE FROM contacts WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'link.add':
        db()->prepare('INSERT INTO links (title, url, icon, color, sort) VALUES (?,?,?,?, (SELECT COALESCE(MAX(l.sort),0)+1 FROM links l))')
            ->execute([trim((string) $body['title']), trim((string) $body['url']), 'link', $body['color'] ?? 'violet']);
        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()]);

    case 'link.delete':
        db()->prepare('DELETE FROM links WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'wa.status':
        // без явного инстанса — статус АКТИВНОГО канала (Evolution или Green API)
        $inst = !empty($body['instance']) ? (string) $body['instance'] : ('active_' . active_wa_provider());
        $ck = 'wa_status_' . $inst;
        $cached = json_decode(opt($ck, ''), true);
        if (is_array($cached) && time() - ($cached['at'] ?? 0) < 15 && ($cached['state'] ?? '') !== 'error') {
            json_out(['ok' => true, 'state' => $cached['state']]);
        }
        $cli = !empty($body['instance']) ? evolution((string) $body['instance']) : active_wa_client();
        if (!$cli->isConfigured()) json_out(['ok' => true, 'state' => 'unconfigured']);
        $s = $cli->connectionState();
        $state = $s['ok'] ? $s['state'] : 'error';
        if ($state !== 'error') opt_set($ck, json_encode(['state' => $state, 'at' => time()]));
        json_out(['ok' => true, 'state' => $state, 'error' => $s['error'] ?? '']);

    case 'wa.info':
        $evo = evolution($body['instance'] ?? null);
        if (!$evo->isConfigured()) json_out(['ok' => true, 'state' => 'unconfigured']);
        json_out($evo->instanceInfo());

    // Список аккаунтов WhatsApp с живыми данными (Evolution + Green API)
    case 'wa.accounts':
        $live = [];
        $evo = evolution();
        if ($evo->isConfigured()) {
            $resp = $evo->listInstances();
            if ($resp['ok'] && is_array($resp['data'])) {
                foreach ($resp['data'] as $it) {
                    if (!is_array($it) || !isset($it['name'])) continue;
                    $jid = (string) ($it['ownerJid'] ?? '');
                    $live[$it['name']] = [
                        'number' => $jid !== '' ? '+' . preg_replace('/\D+/', '', explode('@', $jid)[0]) : '',
                        'name' => (string) ($it['profileName'] ?? ''),
                        'avatar' => (string) ($it['profilePicUrl'] ?? ''),
                        'state' => (string) ($it['connectionStatus'] ?? ''),
                    ];
                }
            }
        }
        $accounts = [];
        foreach (db()->query('SELECT * FROM wa_accounts ORDER BY id')->fetchAll() as $a) {
            if (($a['provider'] ?? 'evolution') === 'greenapi') {
                $info = green_api()->instanceInfo();
                $l = ['number' => $info['number'] ?? '', 'name' => 'Green API', 'avatar' => '', 'state' => $info['state'] ?? 'close'];
            } else {
                $l = $live[$a['instance']] ?? ['number' => '', 'name' => '', 'avatar' => '', 'state' => 'close'];
            }
            $accounts[] = array_merge($a, $l, ['is_active' => (int) $a['is_active'], 'provider' => $a['provider'] ?? 'evolution']);
        }
        json_out(['ok' => true, 'accounts' => $accounts]);

    case 'wa.add':
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') json_out(['ok' => false, 'error' => 'Укажите название аккаунта']);
        // имя инстанса — латиницей, уникальное
        $base = preg_replace('/[^a-z0-9]+/', '', strtolower(translit_lat($label))) ?: 'acc';
        $instance = $base . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
        $evo = evolution($instance);
        $resp = $evo->createInstance($instance);
        if (!$resp['ok']) json_out(['ok' => false, 'error' => $resp['error']]);
        db()->prepare('INSERT INTO wa_accounts (instance, label, is_active) VALUES (?,?,0)')->execute([$instance, $label]);
        json_out(['ok' => true, 'instance' => $instance]);

    case 'wa.setactive':
        $instance = (string) ($body['instance'] ?? '');
        db()->prepare('UPDATE wa_accounts SET is_active = IF(instance = ?, 1, 0)')->execute([$instance]);
        json_out(['ok' => true]);

    case 'wa.account.delete':
        $instance = (string) ($body['instance'] ?? '');
        $cnt = (int) db()->query('SELECT COUNT(*) FROM wa_accounts')->fetchColumn();
        if ($cnt <= 1) json_out(['ok' => false, 'error' => 'Нельзя удалить единственный аккаунт']);
        $evo = evolution($instance);
        $evo->deleteInstance();
        $wasActive = (int) db()->query('SELECT is_active FROM wa_accounts WHERE instance = ' . db()->quote($instance))->fetchColumn();
        db()->prepare('DELETE FROM wa_accounts WHERE instance = ?')->execute([$instance]);
        if ($wasActive) db()->query('UPDATE wa_accounts SET is_active = 1 ORDER BY id LIMIT 1');
        json_out(['ok' => true]);

    case 'wa.qr':
        $evo = evolution($body['instance'] ?? null);
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'не настроен']);
        $resp = $evo->connectQr();
        if (!$resp['ok']) json_out(['ok' => false, 'error' => $resp['error']]);
        json_out(['ok' => true, 'qr' => $resp['data']['base64'] ?? '', 'count' => $resp['data']['count'] ?? 0]);

    case 'wa.logout':
        $evo = evolution($body['instance'] ?? null);
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен']);
        $resp = $evo->logout();
        if (!$resp['ok']) json_out(['ok' => false, 'error' => $resp['error']]);
        json_out(['ok' => true]);

    /* ── Сотрудники (admin) ── */
    case 'user.save':
        if (!is_admin()) json_out(['ok' => false, 'error' => 'Только администратор'], 403);
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $login = trim((string) ($body['login'] ?? ''));
        $role = ($body['role'] ?? 'operator') === 'admin' ? 'admin' : 'operator';
        $pass = (string) ($body['password'] ?? '');
        if ($name === '' || $login === '') json_out(['ok' => false, 'error' => 'Имя и логин обязательны']);
        try {
            if ($id > 0) {
                if ($pass !== '') {
                    db()->prepare('UPDATE users SET name=?, login=?, role=?, password_hash=? WHERE id=?')
                        ->execute([$name, $login, $role, password_hash($pass, PASSWORD_BCRYPT), $id]);
                } else {
                    db()->prepare('UPDATE users SET name=?, login=?, role=? WHERE id=?')->execute([$name, $login, $role, $id]);
                }
            } else {
                if (mb_strlen($pass) < 6) json_out(['ok' => false, 'error' => 'Пароль минимум 6 символов']);
                db()->prepare('INSERT INTO users (name, login, role, password_hash) VALUES (?,?,?,?)')
                    ->execute([$name, $login, $role, password_hash($pass, PASSWORD_BCRYPT)]);
                $id = (int) db()->lastInsertId();
            }
        } catch (PDOException $e) {
            json_out(['ok' => false, 'error' => str_contains($e->getMessage(), 'Duplicate') ? 'Логин занят' : 'Ошибка БД']);
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'user.toggle':
        if (!is_admin()) json_out(['ok' => false, 'error' => 'Только администратор'], 403);
        db()->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'user.delete':
        if (!is_admin()) json_out(['ok' => false, 'error' => 'Только администратор'], 403);
        if ((int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1
            && (int) db()->query('SELECT role="admin" FROM users WHERE id=' . (int) $body['id'])->fetchColumn() === 1) {
            json_out(['ok' => false, 'error' => 'Нельзя удалить последнего администратора']);
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $body['id']]);
        json_out(['ok' => true]);

    case 'smtp.save':
        opt_set('smtp_from', trim((string) ($body['from'] ?? '')));
        opt_set('smtp_from_name', trim((string) ($body['from_name'] ?? '')));
        opt_set('smtp_reply', trim((string) ($body['reply'] ?? '')));
        json_out(['ok' => true]);

    case 'smtp.test':
        $to = trim((string) ($body['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Укажите корректный email']);
        $m = smtp_mailer();
        if (!$m->isConfigured()) json_out(['ok' => false, 'error' => 'SMTP не настроен (нет логина/пароля/отправителя)']);
        $r = $m->send($to, 'Тест email-канала — Интерсити Тур',
            '<p>Это тестовое письмо из <b>панели Интерсити Тур</b> через smtp.bz. Если вы его получили — email-канал настроен правильно 🚍</p>');
        json_out($r);

    case 'password.save':
        $p = (string) ($body['password'] ?? '');
        if (mb_strlen($p) < 8) json_out(['ok' => false, 'error' => 'Минимум 8 символов']);
        $uid = $_SESSION['panel_user'] ?? null;
        if (is_int($uid) || ctype_digit((string) $uid)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($p, PASSWORD_BCRYPT), (int) $uid]);
        } else {
            // legacy-сессия: меняем пароль стартового admin
            db()->prepare("UPDATE users SET password_hash = ? WHERE login = 'admin'")
                ->execute([password_hash($p, PASSWORD_BCRYPT)]);
        }
        json_out(['ok' => true]);

    default:
        json_out(['ok' => false, 'error' => 'unknown action'], 404);
}
