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

// URL и события вебхука для Evolution-инстанса (приёмник статусов/входящих/состояния канала)
function evo_webhook_url(): string
{
    $base = env_get('PANEL_BASE_URL') ?: 'https://crm.terratranskrym.ru';
    return rtrim($base, '/') . '/webhook.php?token=' . rawurlencode(env_get('WEBHOOK_TOKEN'));
}
function evo_webhook_events(): array
{
    return ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'];
}
// Выставить вебхук на инстансе — идемпотентно, ошибку глушим (не критично для основного действия)
function evo_ensure_webhook(EvolutionApiClient $evo): void
{
    try { $evo->setWebhook(evo_webhook_url(), evo_webhook_events()); } catch (Exception $e) { /* не критично */ }
}

function green_api()
{
    require_once PANEL_ROOT . '/lib/GreenApiClient.php';
    return new GreenApiClient(env_get('GREENAPI_URL'), env_get('GREENAPI_ID'), env_get('GREENAPI_TOKEN'), 'max');
}

// Green API для Telegram — отдельный инстанс. URL по умолчанию общий с MAX.
function green_api_tg()
{
    require_once PANEL_ROOT . '/lib/GreenApiClient.php';
    return new GreenApiClient(env_get('GREENAPI_TG_URL') ?: env_get('GREENAPI_URL'), env_get('GREENAPI_TG_ID'), env_get('GREENAPI_TG_TOKEN'), 'telegram');
}

// SMS-канал через SMS.RU (сервисные сообщения). Ключ и отправитель — из panel.env.
function sms_ru()
{
    require_once PANEL_ROOT . '/lib/SmsRuClient.php';
    return new SmsRuClient(env_get('SMSRU_API_ID'), env_get('SMSRU_FROM'));
}

// Гард отправки: WhatsApp реально подключён (не просто «ключи заданы»), иначе понятная ошибка вместо 500.
function wa_require_ready(): void
{
    require_once PANEL_ROOT . '/lib/Channels.php';
    if (Channels::ready('whatsapp')) return;
    $st = Channels::state('whatsapp');
    json_out(['ok' => false, 'wa_state' => $st, 'error' => $st === 'unconfigured'
        ? 'WhatsApp-канал не настроен.'
        : 'WhatsApp не подключён — номер разлогинен. Переподключите в Настройках → Аккаунты WhatsApp.']);
}

// Реальное состояние всех каналов (кэш 20с в opt, чтобы не дёргать API на каждый экран).
function wa_channel_states(): array
{
    $c = json_decode((string) opt('channel_states_cache'), true);
    if (is_array($c) && isset($c['at'], $c['states']) && (time() - (int) $c['at'] < 20)) return $c['states'];
    require_once PANEL_ROOT . '/lib/Channels.php';
    $states = [];
    foreach (['whatsapp', 'max', 'telegram', 'sms'] as $ch) $states[$ch] = Channels::state($ch);
    opt_set('channel_states_cache', json_encode(['at' => time(), 'states' => $states]));
    return $states;
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

// Мессенджер по провайдеру: Green API = MAX, Evolution = WhatsApp.
function wa_messenger(string $provider): string
{
    return $provider === 'greenapi' ? 'max' : 'whatsapp';
}

// Клиент для мессенджера: max → Green API; whatsapp/прочее → Evolution на РЕАЛЬНЫЙ
// evolution-инстанс из wa_accounts (а не «активный», которым может быть greenapi).
function wa_client_for(string $messenger)
{
    if ($messenger === 'max') return green_api();
    try {
        $inst = db()->query("SELECT instance FROM wa_accounts WHERE provider = 'evolution' ORDER BY is_active DESC, id LIMIT 1")->fetchColumn();
    } catch (Exception $e) { $inst = ''; }
    return evolution($inst ?: null);
}

// Исходящие пассажирам по телефону = WhatsApp (Evolution). MAX по телефону не адресуется.
function wa_outbound()
{
    return wa_client_for('whatsapp');
}

// Куда отвечать в чате: по последнему входящему каналу.
// [client, target, messenger]: для MAX target = chat_id, для WhatsApp = телефон.
function wa_reply_target(string $phone): array
{
    try {
        // Берём действительно последнее входящее, включая WhatsApp без chat_id.
        // Раньше фильтр chat_id<>'' мог отправить ответ в старый MAX-чат после нового сообщения в WhatsApp.
        $in = db()->prepare('SELECT instance, chat_id FROM inbox WHERE phone = ? ORDER BY id DESC LIMIT 1');
        $in->execute([$phone]);
        $row = $in->fetch();
    } catch (Exception $e) { $row = null; }
    if ($row && $row['instance'] === 'greenapi_tg' && $row['chat_id'] !== '') {
        return [green_api_tg(), $row['chat_id'], 'telegram'];
    }
    if ($row && $row['instance'] === 'greenapi' && $row['chat_id'] !== '') {
        return [green_api(), $row['chat_id'], 'max'];
    }
    return [wa_outbound(), $phone, 'whatsapp'];
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

// Переменные сообщения для пассажира с учётом группы посадки.
// $opts: bus_label (готовая подпись автобуса «описание+номер»), show_phone (bool), phone_fallback (фраза при выкл. галочке)
function group_vars(array $manifest, array $passenger, array $group, array $opts = []): array
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

    // телефон водителя: галочка вкл и телефон есть → номер; иначе → фраза-заглушка («сообщим позднее»)
    $fallback = $opts['phone_fallback'] ?? 'сообщим позднее';
    $showPhone = $opts['show_phone'] ?? true;
    $phone = ($showPhone && trim((string) $manifest['driver_phone']) !== '') ? $manifest['driver_phone'] : $fallback;

    // автобус: «описание + номер» из карточки справочника (готовится в вызывающем коде), иначе номер из ведомости
    $busLabel = $opts['bus_label'] ?? ($manifest['bus'] ?: '');

    return array_merge(custom_vars(), $vars, [
        '{дата_рейса}' => $manifest['departure_at'] ? date('d.m.Y', strtotime($manifest['departure_at'])) : '',
        '{дата}' => $group['date'] ?: ($manifest['departure_at'] ? date('d.m.Y', strtotime($manifest['departure_at'])) : ''),
        '{время}' => $group['time'] ?? '',
        '{посадка}' => $boarding,
        '{карта}' => $group['map_url'] ?? '',
        '{ссылка на карту}' => $group['map_url'] ?? '',          // алиас
        '{автобус}' => $busLabel,
        '{тел_водителя}' => $phone,
        '{телефон водителя}' => $phone, // алиас
        '{телефон}' => $phone,          // алиас
        '{доп}' => $manifest['extra_info'],
    ]);
}

// Опции отправки/превью: подпись автобуса (описание+номер из справочника), показывать ли телефон, фраза-заглушка.
function send_opts(array $manifest, array $body): array
{
    $busLabel = $manifest['bus'] ?: '';
    $b = manifest_bus($manifest);
    if ($b) {
        $lbl = trim((string) ($b['model'] ?? '') . ' ' . (string) ($b['plate'] ?? ''));
        if ($lbl !== '') $busLabel = $lbl;
    }
    return [
        'bus_label' => $busLabel,
        // галочка телефона: нет ключа phone_on → показываем (по умолчанию вкл); явный 0 → прячем
        'show_phone' => !array_key_exists('phone_on', $body) || !empty($body['phone_on']),
        'phone_fallback' => opt('driver_phone_fallback', 'сообщим позднее'),
    ];
}

// Рендер сообщения группы + авто-добавление «доп. информации», если она заполнена,
// но в шаблоне нет явного {доп} (как обещает подпись «в сообщение, если заполнено»).
function render_group_message(string $template, array $vars, string $extra): string
{
    require_once PANEL_ROOT . '/lib/MessageTemplate.php';
    $text = MessageTemplate::render($template, $vars);
    if (trim($extra) !== '' && !preg_match('/\{\s*доп[^}]*\}/ui', $template)) {
        $text = rtrim($text) . "\n\n" . trim($extra);
    }
    return $text;
}

// Сборка групп по точной паре «точка посадки -> точка прибытия».
function build_groups(array $manifest): array
{
    require_once PANEL_ROOT . '/lib/NotificationGroups.php';
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
    $legacyDrafts = [];
    $dst = db()->prepare('SELECT * FROM manifest_groups WHERE manifest_id = ?');
    $dst->execute([$manifest['id']]);
    foreach ($dst->fetchAll() as $d) {
        $destination = trim((string) ($d['destination'] ?? ''));
        if ($destination === '') {
            $legacyDrafts[NotificationGroups::draftKey($d['station'], '')] = $d;
        } else {
            $drafts[NotificationGroups::draftKey($d['station'], $destination)] = $d;
        }
    }

    $groups = [];
    foreach ($passengers as $p) {
        $stationId = $p['from_id'] !== null ? (int) $p['from_id'] : null;
        $station = trim($p['from_stop']) !== '' ? trim($p['from_stop']) : '(посадка не указана)';
        $destinationId = $p['to_id'] !== null ? (int) $p['to_id'] : null;
        $destination = trim($p['to_stop']) !== '' ? trim($p['to_stop']) : '(прибытие не указано)';
        $key = NotificationGroups::key($stationId, $station, $destinationId, $destination);

        if (!isset($groups[$key])) {
            // справочник: сперва по id, потом по названию
            $cat = ($stationId !== null ? ($catalogById[$stationId] ?? null) : null)
                ?? ($catalogByName[mb_strtolower($station, 'UTF-8')] ?? null);
            $draft = $drafts[NotificationGroups::draftKey($station, $destination)] ?? null;
            // Старые записи были общими для всей станции. Из них безопасно наследуем
            // только время посадки, но не текст, который мог относиться к другому городу.
            $legacyDraft = $legacyDrafts[NotificationGroups::draftKey($station, '')] ?? null;
            $groups[$key] = [
                'station' => $station,
                'station_id' => $stationId,
                'destination' => $destination,
                'destination_id' => $destinationId,
                'route_key' => $key,
                'address' => $cat['address'] ?? '',
                'map_url' => $cat['map_url'] ?? '',
                'in_catalog' => $cat !== null && trim((string) ($cat['address'] ?? '')) !== '',
                'date' => $draft['boarding_date'] ?? $legacyDraft['boarding_date'] ?? '',
                'time' => $draft['boarding_time'] ?? $legacyDraft['boarding_time'] ?? '',
                'time_warning' => (int) ($draft['time_warning'] ?? $legacyDraft['time_warning'] ?? 0),
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

    return NotificationGroups::sortByRoute(array_values($groups));
}

function find_notification_group(array $manifest, array $body): ?array
{
    require_once PANEL_ROOT . '/lib/NotificationGroups.php';
    $station = trim((string) ($body['station'] ?? ''));
    $destination = trim((string) ($body['destination'] ?? ''));
    if ($station === '' || $destination === '') return null;
    foreach (build_groups($manifest) as $group) {
        if (NotificationGroups::matches($group, $station, $destination)) return $group;
    }
    return null;
}

// null/0/1 из ?bool — для кэша наличия канала.
function tri(?bool $v): ?int { return $v === null ? null : (int) $v; }

// Подмешать кэш наличия каналов (из contacts) к получателям групп.
function attach_channel_presence(array $groups): array
{
    $phones = [];
    foreach ($groups as $g) foreach ($g['recipients'] as $r) if ($r['phone'] !== '') $phones[$r['phone']] = true;
    $map = [];
    if ($phones) {
        $phones = array_keys($phones);
        $in = implode(',', array_fill(0, count($phones), '?'));
        $st = db()->prepare("SELECT phone, has_whatsapp, has_max, has_telegram, channels_checked_at FROM contacts WHERE phone IN ($in)");
        $st->execute($phones);
        foreach ($st->fetchAll() as $row) {
            $map[$row['phone']] = [
                'whatsapp' => $row['has_whatsapp'] === null ? null : (bool) $row['has_whatsapp'],
                'max'      => $row['has_max'] === null ? null : (bool) $row['has_max'],
                'telegram' => $row['has_telegram'] === null ? null : (bool) $row['has_telegram'],
                'checked'  => $row['channels_checked_at'] !== null,
            ];
        }
    }
    $blank = ['whatsapp' => null, 'max' => null, 'telegram' => null, 'checked' => false];
    foreach ($groups as &$g) {
        foreach ($g['recipients'] as &$r) $r['channels'] = $map[$r['phone']] ?? $blank;
        unset($r);
    }
    unset($g);
    return $groups;
}

// Лучший статус по получателю среди всех каналов: read > delivered > sent > failed > pending.
function monitor_aggregate(array $byChan): array
{
    $rank = ['read' => 5, 'delivered' => 4, 'sent' => 3, 'failed' => 2, 'pending' => 1, 'none' => 0];
    $best = ['state' => 'none', 'channel' => '', 'sent_at' => null, 'delivered_at' => null, 'read_at' => null, 'error' => ''];
    foreach ($byChan as $chan => $m) {
        if (!empty($m['read_at'])) $state = 'read';
        elseif (!empty($m['delivered_at'])) $state = 'delivered';
        elseif ($m['status'] === 'sent') $state = 'sent';
        elseif ($m['status'] === 'failed') $state = 'failed';
        else $state = 'pending';
        if ($rank[$state] >= $rank[$best['state']]) {
            $best = ['state' => $state, 'channel' => $chan, 'sent_at' => $m['sent_at'],
                'delivered_at' => $m['delivered_at'], 'read_at' => $m['read_at'], 'error' => (string) $m['error']];
        }
    }
    return $best;
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
        require_once PANEL_ROOT . '/lib/Channels.php';
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
            'groups' => attach_channel_presence(build_groups($manifest)),
            'channels_active' => Channels::active(),
            'channels_state' => wa_channel_states(),
            'templates' => db()->query('SELECT id, name, body FROM templates ORDER BY sort, id')->fetchAll(),
        ]);

    case 'channels.check':
        // Проверка наличия каналов у номеров (CheckAccount/whatsappNumbers) + запись в кэш contacts.
        require_once PANEL_ROOT . '/lib/Channels.php';
        set_time_limit(0);
        $phones = [];
        foreach ((array) ($body['phones'] ?? []) as $raw) {
            $ph = normalize_phone((string) $raw);
            if (valid_phone($ph)) $phones[$ph] = true;
        }
        $phones = array_slice(array_keys($phones), 0, 60);
        if (!$phones) json_out(['ok' => true, 'presence' => []]);

        // WhatsApp — одним батч-запросом; MAX/Telegram — по одному (CheckAccount не батчится).
        $waMap = [];
        if (Channels::configured('whatsapp')) {
            $r = Channels::client('whatsapp')->checkNumbers($phones);
            if (!empty($r['ok'])) $waMap = $r['exists'];
        }
        $hasMax = Channels::configured('max');
        $hasTg = Channels::configured('telegram');
        $fallbackOnly = !empty($body['fallback_only']);

        $upIns = db()->prepare('INSERT INTO contacts (phone) VALUES (?) ON DUPLICATE KEY UPDATE phone = phone');
        $upSet = db()->prepare('UPDATE contacts SET
            has_whatsapp = COALESCE(?, has_whatsapp), has_max = COALESCE(?, has_max),
            has_telegram = COALESCE(?, has_telegram), channels_checked_at = NOW() WHERE phone = ?');
        $readPresence = db()->prepare('SELECT has_whatsapp, has_max, has_telegram FROM contacts WHERE phone = ?');
        $out = [];
        foreach ($phones as $ph) {
            $num = preg_replace('/\D+/', '', $ph);
            $wa = array_key_exists($num, $waMap) ? (bool) $waMap[$num] : null;
            // При автоматической подготовке запасные мессенджеры проверяем только
            // если WhatsApp недоступен — это сильно ускоряет ведомость на 30–60 человек.
            $needFallback = !$fallbackOnly || $wa === false;
            $max = $hasMax && $needFallback ? Channels::presence('max', $ph) : null;
            $tg = $hasTg && $needFallback ? Channels::presence('telegram', $ph) : null;
            $upIns->execute([$ph]);
            $upSet->execute([tri($wa), tri($max), tri($tg), $ph]);
            $readPresence->execute([$ph]);
            $stored = $readPresence->fetch() ?: [];
            $out[$ph] = [
                'whatsapp' => ($stored['has_whatsapp'] ?? null) === null ? null : (bool) $stored['has_whatsapp'],
                'max' => ($stored['has_max'] ?? null) === null ? null : (bool) $stored['has_max'],
                'telegram' => ($stored['has_telegram'] ?? null) === null ? null : (bool) $stored['has_telegram'],
                'checked' => true,
            ];
        }
        json_out(['ok' => true, 'presence' => $out]);

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
        $kept = 0;
        $processedStations = [];
        foreach (build_groups($manifest) as $g) {
            $stationKey = $g['station_id'] !== null
                ? 'id' . $g['station_id']
                : mb_strtolower(trim($g['station']), 'UTF-8');
            // Время посадки едино для всех направлений с этой станции.
            if (isset($processedStations[$stationKey])) continue;
            $processedStations[$stationKey] = true;
            // ГДС — ПЕРВИЧНЫЙ источник времени: если рейс найден в ГДС, время станции
            // ПЕРЕЗАПИСЫВАЕТСЯ значением из ГДС (даже если оно пришло из ведомости).
            // Время из файла остаётся только там, где ГДС не знает станцию или не даёт по ней времени.
            $stop = GdsRace::matchStop($gds, $g['station'], $g['station_id'] ?? null);

            $time = '';
            $date = '';
            if ($stop !== null) {
                $when = $stop['arrival'] !== '' ? $stop['arrival'] : $stop['dispatch'];
                $ts = strtotime($when);
                if ($ts) {
                    $time = date('H:i', $ts);
                    $date = date('d.m.Y', $ts);
                }
            }

            if ($time !== '') {
                // станция отправления — совпадение со стартом это норма; для остальных — предупреждение
                $isStart = GdsRace::norm($g['station']) === GdsRace::norm(explode('-', $manifest['route'])[0] ?? '');
                $warning = (int) (!$isStart && $time === $raceStartTime);
                db()->prepare("INSERT INTO manifest_groups (manifest_id, station, station_id, destination, boarding_date, boarding_time, time_warning)
                    VALUES (?,?,?, '',?,?,?)
                    ON DUPLICATE KEY UPDATE station_id = VALUES(station_id), boarding_date = VALUES(boarding_date), boarding_time = VALUES(boarding_time), time_warning = VALUES(time_warning)")
                    ->execute([$manifest['id'], $g['station'], $g['station_id'] ?? null, $date, $time, $warning]);
                // Уже сохранённые тексты остаются раздельными, обновляется только общее время посадки.
                db()->prepare('UPDATE manifest_groups SET station_id = ?, boarding_date = ?, boarding_time = ?, time_warning = ?
                    WHERE manifest_id = ? AND station = ? AND destination <> ?')
                    ->execute([$g['station_id'] ?? null, $date, $time, $warning, $manifest['id'], $g['station'], '']);
                $updated++;
                continue;
            }

            // ГДС времени по станции не дал — оставляем то, что было в ведомости (если было)
            $exq = db()->prepare("SELECT boarding_time FROM manifest_groups
                WHERE manifest_id = ? AND station = ? ORDER BY (destination = '') DESC LIMIT 1");
            $exq->execute([$manifest['id'], $g['station']]);
            $hasFileTime = trim((string) $exq->fetchColumn()) !== '';
            if ($stop !== null) {
                // станция в рейсе есть, но без времени — хотя бы уточняем привязку station_id
                db()->prepare('UPDATE manifest_groups SET station_id = ? WHERE manifest_id = ? AND station = ?')
                    ->execute([$g['station_id'] ?? null, $manifest['id'], $g['station']]);
            }
            if ($hasFileTime) {
                $kept++;
            } else {
                $unmatched[] = $g['station'];
            }
        }
        json_out(['ok' => true, 'race_uid' => $gds['race_uid'], 'race_start' => $gds['race_start'],
            'statement_match' => $gds['statement_match'], 'updated' => $updated, 'kept_from_file' => $kept, 'unmatched' => $unmatched,
            'from_cache' => $fromCache, 'cached_at' => $gds['cached_at'] ?? '', 'gds_error' => $gdsError]);

    case 'group.save':
        $manifest = get_manifest((int) $body['manifest_id']);
        $group = find_notification_group($manifest, $body);
        if ($group === null) json_out(['ok' => false, 'error' => 'Группа маршрута не найдена. Обновите страницу.']);
        db()->prepare('INSERT INTO manifest_groups (manifest_id, station, station_id, destination, destination_id, boarding_date, boarding_time, body)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE boarding_date = VALUES(boarding_date), boarding_time = VALUES(boarding_time), body = VALUES(body)')
            ->execute([
                $manifest['id'], $group['station'], $group['station_id'], $group['destination'], $group['destination_id'],
                trim((string) ($body['date'] ?? '')), trim((string) ($body['time'] ?? '')),
                ($body['body'] ?? null) === null ? null : (string) $body['body'],
            ]);
        json_out(['ok' => true]);

    case 'group.preview':
        $manifest = get_manifest((int) $body['manifest_id']);
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $g = find_notification_group($manifest, $body);
        if ($g !== null) {
            $g['date'] = trim((string) ($body['date'] ?? $g['date']));
            $g['time'] = trim((string) ($body['time'] ?? $g['time']));
            $first = $g['recipients'][0] ?? null;
            if (!$first) json_out(['ok' => true, 'preview' => '']);
            $pst = db()->prepare('SELECT * FROM passengers WHERE id = ?');
            $pst->execute([$first['id']]);
            $p = $pst->fetch();
            $vars = group_vars($manifest, $p, $g, send_opts($manifest, $body));
            json_out([
                'ok' => true,
                'preview' => render_group_message((string) $body['text'], $vars, $manifest['extra_info']),
                'unknown' => MessageTemplate::unknownVars((string) $body['text'], $vars),
            ]);
        }
        json_out(['ok' => false, 'error' => 'Группа не найдена']);

    /* ── Отправка ── */

    case 'campaign.status':
        // Монитор доставки по группе: статус каждого получателя из messages + ответы из inbox + наличие каналов.
        require_once PANEL_ROOT . '/lib/Channels.php';
        $manifest = get_manifest((int) $body['manifest_id']);
        $station = (string) ($body['station'] ?? '');
        $destination = (string) ($body['destination'] ?? '');
        $group = find_notification_group($manifest, $body);
        if ($group === null) json_out(['ok' => false, 'error' => 'Группа не найдена']);

        $phones = [];
        foreach ($group['recipients'] as $r) if ($r['phone'] !== '') $phones[$r['phone']] = true;
        $phones = array_keys($phones);

        $msgByPhone = [];
        $replied = [];
        if ($phones) {
            $in = implode(',', array_fill(0, count($phones), '?'));
            $st = db()->prepare("SELECT recipient, channel, status, sent_at, delivered_at, read_at, error
                FROM messages WHERE manifest_id = ? AND recipient IN ($in) ORDER BY id ASC");
            $st->execute(array_merge([$manifest['id']], $phones));
            foreach ($st->fetchAll() as $m) $msgByPhone[$m['recipient']][$m['channel']] = $m;

            $rs = db()->prepare("SELECT DISTINCT phone FROM inbox WHERE phone IN ($in)");
            $rs->execute($phones);
            foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $p) $replied[$p] = true;
        }

        $withPresence = attach_channel_presence([$group]);
        $presByPhone = [];
        foreach ($withPresence[0]['recipients'] as $r) $presByPhone[$r['phone']] = $r['channels'];

        $recipients = [];
        foreach ($group['recipients'] as $r) {
            $ph = $r['phone'];
            $byChan = $msgByPhone[$ph] ?? [];
            $agg = monitor_aggregate($byChan);
            $recipients[] = [
                'id' => $r['id'], 'name' => $r['name'], 'phone' => $ph, 'to' => $r['to'],
                'sent' => !empty($byChan),
                'state' => $agg['state'], 'channel' => $agg['channel'],
                'sent_at' => $agg['sent_at'], 'delivered_at' => $agg['delivered_at'], 'read_at' => $agg['read_at'],
                'error' => $agg['error'],
                'replied' => isset($replied[$ph]),
                'channels' => $presByPhone[$ph] ?? null,
            ];
        }
        json_out(['ok' => true, 'station' => $station, 'destination' => $destination, 'channels_active' => Channels::active(),
            'body' => $group['body'] ?? '', 'recipients' => $recipients]);

    case 'campaign.overview':
        // Единый монитор по текущей ведомости: одна загрузка вместо запроса на каждую группу.
        require_once PANEL_ROOT . '/lib/Channels.php';
        $manifest = get_manifest((int) $body['manifest_id']);
        $groups = attach_channel_presence(build_groups($manifest));
        $phones = [];
        foreach ($groups as $group) {
            foreach ($group['recipients'] as $recipient) {
                if ($recipient['phone'] !== '') $phones[$recipient['phone']] = true;
            }
        }

        $msgByPhone = [];
        $replied = [];
        if ($phones) {
            $phoneList = array_keys($phones);
            $in = implode(',', array_fill(0, count($phoneList), '?'));
            $st = db()->prepare("SELECT id, recipient, channel, status, sent_at, delivered_at, read_at, error
                FROM messages WHERE manifest_id = ? AND recipient IN ($in) ORDER BY id ASC");
            $st->execute(array_merge([$manifest['id']], $phoneList));
            foreach ($st->fetchAll() as $message) {
                $msgByPhone[$message['recipient']][$message['channel']] = $message;
            }
            $rs = db()->prepare("SELECT DISTINCT phone FROM inbox WHERE phone IN ($in)");
            $rs->execute($phoneList);
            foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $phone) $replied[$phone] = true;
        }

        $recipients = [];
        $summary = ['total' => 0, 'valid' => 0, 'not_sent' => 0, 'pending' => 0,
            'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0];
        foreach ($groups as $groupIndex => $group) {
            foreach ($group['recipients'] as $recipient) {
                $byChannel = $msgByPhone[$recipient['phone']] ?? [];
                $aggregate = monitor_aggregate($byChannel);
                $channelStates = [];
                foreach ($byChannel as $channel => $message) {
                    $channelAggregate = monitor_aggregate([$channel => $message]);
                    $channelStates[$channel] = [
                        'state' => $channelAggregate['state'], 'error' => $channelAggregate['error'],
                        'sent_at' => $channelAggregate['sent_at'], 'delivered_at' => $channelAggregate['delivered_at'],
                        'read_at' => $channelAggregate['read_at'],
                    ];
                }
                $state = $aggregate['state'];
                $wasSent = !empty($byChannel);
                $hasReplied = isset($replied[$recipient['phone']]);
                $summary['total']++;
                if ($recipient['valid']) $summary['valid']++;
                if (!$wasSent) $summary['not_sent']++;
                elseif (isset($summary[$state])) $summary[$state]++;
                if ($hasReplied) $summary['replied']++;
                $recipients[] = [
                    'id' => $recipient['id'], 'group_index' => $groupIndex,
                    'station' => $group['station'], 'destination' => $group['destination'],
                    'name' => $recipient['name'], 'phone' => $recipient['phone'], 'valid' => $recipient['valid'],
                    'sent' => $wasSent, 'state' => $state, 'channel' => $aggregate['channel'],
                    'sent_at' => $aggregate['sent_at'], 'delivered_at' => $aggregate['delivered_at'],
                    'read_at' => $aggregate['read_at'], 'error' => $aggregate['error'],
                    'replied' => $hasReplied, 'channels' => $recipient['channels'], 'channel_states' => $channelStates,
                ];
            }
        }
        json_out(['ok' => true, 'summary' => $summary, 'recipients' => $recipients,
            'channels_active' => Channels::active()]);

    case 'recipient.resend':
        // Дослать сообщение конкретному получателю в другой канал (МАКС/SMS/Telegram).
        require_once PANEL_ROOT . '/lib/Channels.php';
        require_once PANEL_ROOT . '/app/conversations.php';
        $manifestId = (int) ($body['manifest_id'] ?? 0);
        $phone = normalize_phone((string) ($body['phone'] ?? ''));
        $channel = (string) ($body['channel'] ?? '');
        if (!valid_phone($phone)) json_out(['ok' => false, 'error' => 'Некорректный номер.']);
        if (!isset(Channels::LABELS[$channel])) json_out(['ok' => false, 'error' => 'Неизвестный канал.']);
        if (!Channels::ready($channel)) {
            $st = Channels::state($channel);
            json_out(['ok' => false, 'channel_state' => $st, 'error' => Channels::label($channel) . ($st === 'unconfigured' ? ': канал не подключён.' : ': не авторизован — переподключите в Настройках.')]);
        }

        // текст — переданный либо последнее сообщение этому номеру по ведомости
        $text = trim((string) ($body['text'] ?? ''));
        $pname = '';
        if ($text === '') {
            $st = db()->prepare('SELECT body, passenger_name FROM messages WHERE manifest_id = ? AND recipient = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$manifestId, $phone]);
            if ($orig = $st->fetch()) { $text = (string) $orig['body']; $pname = (string) $orig['passenger_name']; }
        }
        if ($text === '') json_out(['ok' => false, 'error' => 'Нет текста для пересылки.']);

        // адресат: для MAX/Telegram нужен chatId (через CheckAccount); для WhatsApp/SMS — номер
        $target = $phone;
        if ($channel === 'max' || $channel === 'telegram') {
            $chk = Channels::client($channel)->checkAccount($phone);
            if (empty($chk['ok']) || empty($chk['exists'])) {
                json_out(['ok' => false, 'error' => 'У номера нет ' . Channels::label($channel) . '.']);
            }
            $target = $chk['chatId'] !== '' ? $chk['chatId'] : $phone;
        }

        db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (?,?,?,?,?,?)')
            ->execute([$manifestId, $channel, $phone, $pname, $text, current_user_name()]);
        $mid = (int) db()->lastInsertId();
        try { conversation_append_legacy('messages', $mid); } catch (Throwable $e) { /* legacy-режим */ }
        $res = Channels::sendText($channel, $target, $text);
        if (!empty($res['ok'])) {
            db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")
                ->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
            try { conversation_append_legacy('messages', $mid); } catch (Throwable $e) { /* legacy-режим */ }
            contact_log_message($phone, $pname, '');
            json_out(['ok' => true]);
        }
        db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'] ?? 'ошибка', $mid]);
        try { conversation_append_legacy('messages', $mid); } catch (Throwable $e) { /* legacy-режим */ }
        json_out(['ok' => false, 'error' => $res['error'] ?? 'Ошибка отправки.']);

    case 'campaign.send':
        set_time_limit(0);
        $manifest = get_manifest((int) $body['manifest_id']);
        require_once PANEL_ROOT . '/lib/Channels.php';
        require_once PANEL_ROOT . '/app/conversations.php';
        $requestedChannels = array_values(array_unique(array_map('strval', (array) ($body['channels'] ?? ['whatsapp']))));
        $channels = [];
        foreach ($requestedChannels as $channel) {
            if (!isset(Channels::LABELS[$channel])) continue;
            if (!Channels::ready($channel)) {
                $st = Channels::state($channel);
                $msg = $st === 'unconfigured'
                    ? Channels::label($channel) . ': канал не подключён'
                    : Channels::label($channel) . ': не авторизован — переподключите в Настройках → Аккаунты';
                json_out(['ok' => false, 'error' => $msg, 'channel_state' => $st]);
            }
            $channels[] = $channel;
        }
        if (!$channels) json_out(['ok' => false, 'error' => 'Выберите хотя бы один подключённый канал']);

        $group = find_notification_group($manifest, $body);
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
        // защита: отправляем ТОЛЬКО пассажирам этой группы, даже если с клиента пришли чужие id
        $groupIds = array_map(fn($r) => (int) $r['id'], $group['recipients']);
        $ids = array_values(array_intersect(array_map('intval', (array) ($body['ids'] ?? [])), $groupIds));
        $tpl = (string) ($body['text'] ?? '');
        $sendOpts = send_opts($manifest, $body);
        if (!empty($body['dry_run'])) {
            $eligible = [];
            foreach ($group['recipients'] as $recipient) {
                if (in_array((int) $recipient['id'], $ids, true) && $recipient['valid']) $eligible[$recipient['phone']] = true;
            }
            json_out(['ok' => true, 'dry_run' => true, 'recipients' => count($eligible),
                'channels' => $channels, 'attempts' => count($eligible) * count($channels)]);
        }
        $sent = 0; $failed = 0; $skipped = 0; $duplicates = 0; $errors = []; $seenPhones = []; $batch = 0;
        $duplicateQuery = db()->prepare("SELECT id FROM messages
            WHERE manifest_id = ? AND channel = ? AND recipient = ? AND body = ? AND status IN ('pending','sent')
            ORDER BY id DESC LIMIT 1");

        foreach ($ids as $pid) {
            if ($batch >= 20) break;
            $pst = db()->prepare('SELECT * FROM passengers WHERE id = ? AND manifest_id = ?');
            $pst->execute([$pid, $manifest['id']]);
            $p = $pst->fetch();
            if (!$p || !valid_phone($p['phone'])) { $skipped++; continue; }
            if (isset($seenPhones[$p['phone']])) { $skipped++; continue; }
            $seenPhones[$p['phone']] = true;
            $batch++;

            $msg = render_group_message($tpl, group_vars($manifest, $p, $group, $sendOpts), $manifest['extra_info']);
            $passengerSent = false;
            foreach ($channels as $channel) {
                // Повторный клик не должен создавать второе одинаковое сообщение в том же канале.
                $duplicateQuery->execute([$manifest['id'], $channel, $p['phone'], $msg]);
                if ($duplicateQuery->fetchColumn()) { $duplicates++; continue; }

                db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (?,?,?,?,?,?)')
                    ->execute([$manifest['id'], $channel, $p['phone'], $p['name'], $msg, current_user_name()]);
                $mid = (int) db()->lastInsertId();
                try { conversation_append_legacy('messages', $mid); } catch (Throwable $e) { /* legacy-режим */ }

                $target = $p['phone'];
                $res = null;
                if ($channel === 'max' || $channel === 'telegram') {
                    $check = Channels::client($channel)->checkAccount($p['phone']);
                    if (empty($check['ok']) || empty($check['exists'])) {
                        $res = ['ok' => false, 'error' => 'У номера нет ' . Channels::label($channel)];
                    } else {
                        $target = $check['chatId'] !== '' ? $check['chatId'] : $p['phone'];
                    }
                }
                if ($res === null) {
                    $client = Channels::client($channel);
                    // Фото автобуса поддерживаем в основной WhatsApp-отправке; другие каналы получают тот же текст.
                    $res = $channel === 'whatsapp' && $busPhoto !== ''
                        ? $client->sendImage($target, $busPhoto, $msg)
                        : Channels::sendText($channel, $target, $msg);
                }

                if (!empty($res['ok'])) {
                    $providerId = (string) ($res['data']['key']['id'] ?? '');
                    db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")
                        ->execute([$providerId, $mid]);
                    $sent++;
                    $passengerSent = true;
                } else {
                    $error = (string) ($res['error'] ?? 'ошибка отправки');
                    db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$error, $mid]);
                    $failed++;
                    $errors[] = Channels::label($channel) . ' · ' . $p['phone'] . ': ' . $error;
                }
                try { conversation_append_legacy('messages', $mid); } catch (Throwable $e) { /* legacy-режим */ }
            }
            if ($passengerSent) contact_log_message($p['phone'], $p['name'], $manifest['route']);
            if ($batch < min(count($ids), 20)) sleep(rand(2, 4));
        }
        $rest = max(0, count($ids) - $batch - $skipped);
        json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'duplicates' => $duplicates,
            'rest' => $rest, 'channels' => $channels, 'errors' => array_slice($errors, 0, 8)]);

    case 'send.single':
        // Свободная отправка одному: любой канал (WhatsApp/MAX/Telegram/SMS/Email), номер или email.
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        require_once PANEL_ROOT . '/lib/Channels.php';
        $channel = (string) ($body['channel'] ?? 'whatsapp');
        $text = MessageTemplate::render(trim((string) ($body['text'] ?? '')), custom_vars());
        if ($text === '') json_out(['ok' => false, 'error' => 'Введите текст сообщения.']);

        if ($channel === 'email') { // отправка по email-адресу
            $to = trim((string) ($body['email'] ?? $body['phone'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Укажите корректный email.']);
            $mailer = smtp_mailer();
            if (!$mailer->isConfigured()) json_out(['ok' => false, 'error' => 'Email-канал не настроен.']);
            db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
                ->execute(['email', $to, 'Произвольный email', $text, current_user_name()]);
            $mid = (int) db()->lastInsertId();
            $subject = trim((string) ($body['subject'] ?? '')) ?: 'Сообщение от «Интерсити Тур»';
            $res = $mailer->send($to, $subject, nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), [], $text);
            if (!empty($res['ok'])) {
                db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW() WHERE id=?")->execute([$mid]);
                json_out(['ok' => true]);
            }
            db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'] ?? 'ошибка', $mid]);
            json_out(['ok' => false, 'error' => $res['error'] ?? 'Не удалось отправить email']);
        }

        if (!isset(Channels::LABELS[$channel])) json_out(['ok' => false, 'error' => 'Неизвестный канал.']);
        $phone = normalize_phone((string) ($body['phone'] ?? ''));
        if (!valid_phone($phone)) json_out(['ok' => false, 'error' => 'Укажите корректный номер телефона.']);
        if (!Channels::ready($channel)) {
            $st = Channels::state($channel);
            json_out(['ok' => false, 'error' => $st === 'unconfigured'
                ? Channels::label($channel) . ': канал не подключён'
                : Channels::label($channel) . ': не авторизован — переподключите в Настройках']);
        }
        $target = $phone;
        if ($channel === 'max' || $channel === 'telegram') { // мессенджеру нужен chatId — резолвим по номеру
            $check = Channels::client($channel)->checkAccount($phone);
            if (empty($check['ok']) || empty($check['exists'])) json_out(['ok' => false, 'error' => 'У номера нет ' . Channels::label($channel)]);
            $target = ($check['chatId'] ?? '') !== '' ? $check['chatId'] : $phone;
        }
        db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
            ->execute([$channel, $phone, 'Произвольный номер', $text, current_user_name()]);
        $mid = (int) db()->lastInsertId();
        $res = Channels::sendText($channel, $target, $text);
        if (!empty($res['ok'])) {
            db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
            contact_log_message($phone, '', '');
            json_out(['ok' => true]);
        }
        db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'] ?? 'ошибка', $mid]);
        json_out(['ok' => false, 'error' => $res['error'] ?? 'Не удалось отправить']);

    case 'broadcast.send':
        set_time_limit(0);
        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        $evo = wa_outbound(); // по телефону = WhatsApp (Evolution)
        wa_require_ready();
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
        // последнее сообщение по каждому контакту (вход + исход), непрочитанные, канал последнего сообщения
        $rows = db()->query(
            "SELECT phone, name, body, ts, dir, channel FROM (
                SELECT recipient phone, passenger_name name, body, COALESCE(sent_at, created_at) ts, 'out' dir, channel
                  FROM messages WHERE recipient LIKE '+%'
                UNION ALL
                SELECT phone, name, body, received_at ts, 'in' dir, instance channel FROM inbox
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
        $counts = ['whatsapp' => 0, 'max' => 0, 'telegram' => 0, 'sms' => 0, 'email' => 0];
        foreach ($rows as $r) {
            $ph = $r['phone'];
            if (isset($threads[$ph])) continue; // уже взяли последнее
            $ch = $r['dir'] === 'in'
                ? (($r['channel'] === 'greenapi') ? 'max' : 'whatsapp')   // входящие: instance → канал
                : ((string) $r['channel'] ?: 'whatsapp');
            $threads[$ph] = [
                'phone' => $ph,
                'name' => $names[$ph] ?? ($r['name'] ?: ''),
                'last' => mb_substr((string) $r['body'], 0, 70),
                'last_at' => $r['ts'],
                'last_dir' => $r['dir'],
                'unread' => $unread[$ph] ?? 0,
                'channel' => $ch,
            ];
            if (isset($counts[$ch])) $counts[$ch]++;
        }
        json_out(['ok' => true, 'threads' => array_values($threads), 'channel_counts' => $counts]);

    case 'chat.messages':
        $phone = (string) ($body['phone'] ?? '');
        if ($phone === '') json_out(['ok' => false, 'error' => 'нет номера']);
        $st = db()->prepare(
            "SELECT * FROM (SELECT body, ts, dir, status, delivered_at, read_at, channel, media_url, media_type FROM (
                SELECT body, COALESCE(sent_at, created_at) ts, 'out' dir, status, delivered_at, read_at, channel,
                       '' media_url, '' media_type
                  FROM messages WHERE recipient = ?
                UNION ALL
                SELECT body, received_at ts, 'in' dir, NULL status, NULL delivered_at, NULL read_at, instance channel,
                       media_url, media_type
                  FROM inbox WHERE phone = ?
             ) u ORDER BY ts DESC LIMIT 500) recent ORDER BY ts ASC"
        );
        $st->execute([$phone, $phone]);
        $msgs = [];
        foreach ($st->fetchAll() as $m) {
            $ch = (string) ($m['channel'] ?? '');
            if ($m['dir'] === 'in') $ch = ($ch === 'greenapi') ? 'max' : 'whatsapp'; // входящие: instance → канал
            $msgs[] = [
                'body' => $m['body'],
                'ts' => $m['ts'],
                'dir' => $m['dir'],
                'status' => $m['status'],
                'delivered' => $m['delivered_at'] !== null,
                'read' => $m['read_at'] !== null,
                'channel' => $ch,
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
        $conversationId = (int) ($body['conversation_id'] ?? 0);
        $conversation = null;
        if ($conversationId > 0) {
            try {
                $cv = db()->prepare('SELECT * FROM conversations WHERE id=?');
                $cv->execute([$conversationId]);
                $conversation = $cv->fetch();
            } catch (Throwable $e) { $conversation = null; }
            if (!$conversation) json_out(['ok'=>false,'error'=>'Разговор не найден'],404);
        }
        $phone = normalize_phone((string) ($conversation['contact_phone'] ?? $body['phone'] ?? ''));
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '') json_out(['ok' => false, 'error' => 'Пустое сообщение']);

        require_once PANEL_ROOT . '/lib/MessageTemplate.php';
        require_once PANEL_ROOT . '/lib/Channels.php';
        $text = MessageTemplate::render($text, custom_vars());
        if ($conversation) {
            $messenger = (string) $conversation['channel'];
            if (!in_array($messenger,['whatsapp','max','telegram'],true)) json_out(['ok'=>false,'error'=>'Ответ в этот канал пока не поддерживается']);
            $cli = $messenger === 'whatsapp' && !in_array((string)$conversation['channel_account'],['','legacy'],true)
                ? evolution((string)$conversation['channel_account']) : Channels::client($messenger);
            $target = in_array($messenger,['max','telegram'],true)
                ? (string) $conversation['external_chat_id'] : $phone;
        } else {
            [$cli, $target, $messenger] = wa_reply_target($phone); // legacy: последний входящий канал
        }
        // Проверяем именно то, чем реально шлём: для MAX/Telegram это chatId (у контакта может не быть
        // телефона — тогда phone хранится как «+0»); для WhatsApp/legacy — номер.
        if (in_array($messenger, ['max', 'telegram'], true)) {
            if (trim((string) $target) === '') json_out(['ok' => false, 'error' => 'Нет chatId для ответа в этот диалог']);
        } elseif (!valid_phone((string) $target)) {
            json_out(['ok' => false, 'error' => 'Некорректный номер']);
        }
        if (!$cli->isConfigured()) json_out(['ok' => false, 'error' => 'Канал не настроен']);
        db()->prepare('INSERT INTO messages (manifest_id, channel, recipient, passenger_name, body, actor) VALUES (0, ?, ?, ?, ?, ?)')
            ->execute([$messenger, $phone, 'Чат', $text, current_user_name()]);
        $mid = (int) db()->lastInsertId();
        if ($conversationId > 0) {
            require_once PANEL_ROOT . '/app/conversations.php';
            conversation_append_legacy('messages',$mid,$conversationId);
        }
        $res = $cli->sendText($target, $text);
        if ($res['ok']) {
            db()->prepare("UPDATE messages SET status='sent', attempts=1, sent_at=NOW(), wa_id=? WHERE id=?")
                ->execute([(string) ($res['data']['key']['id'] ?? ''), $mid]);
            if ($conversationId > 0) conversation_append_legacy('messages',$mid,$conversationId);
            contact_log_message($phone, '', '');
            json_out(['ok' => true]);
        }
        db()->prepare("UPDATE messages SET status='failed', attempts=1, error=? WHERE id=?")->execute([$res['error'], $mid]);
        if ($conversationId > 0) conversation_append_legacy('messages',$mid,$conversationId);
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

    case 'notif.save':
        // фраза вместо телефона водителя, когда галочка «указать телефон» снята
        $fb = trim((string) ($body['driver_phone_fallback'] ?? ''));
        opt_set('driver_phone_fallback', $fb !== '' ? $fb : 'сообщим позднее');
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
        evo_ensure_webhook($evo); // сразу вешаем приёмник статусов/входящих, чтобы не потерять
        // аккаунты, создаваемые здесь — это Evolution = WhatsApp
        db()->prepare("INSERT INTO wa_accounts (instance, label, is_active, provider, messenger) VALUES (?,?,0,'evolution','whatsapp')")->execute([$instance, $label]);
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
        evo_ensure_webhook($evo); // при каждом подключении гарантируем актуальный вебхук
        $resp = $evo->connectQr();
        if (!$resp['ok']) json_out(['ok' => false, 'error' => $resp['error']]);
        json_out(['ok' => true, 'qr' => $resp['data']['base64'] ?? '', 'count' => $resp['data']['count'] ?? 0]);

    case 'wa.logout':
        $evo = evolution($body['instance'] ?? null);
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен']);
        $resp = $evo->logout();
        if (!$resp['ok']) json_out(['ok' => false, 'error' => $resp['error']]);
        json_out(['ok' => true]);

    case 'wa.webhook.fix':
        // переустановить приёмник статусов/входящих на активном (или указанном) инстансе
        $evo = evolution($body['instance'] ?? null);
        if (!$evo->isConfigured()) json_out(['ok' => false, 'error' => 'WhatsApp-канал не настроен']);
        $resp = $evo->setWebhook(evo_webhook_url(), evo_webhook_events());
        json_out(['ok' => (bool) ($resp['ok'] ?? false), 'error' => $resp['error'] ?? '', 'events' => evo_webhook_events()]);

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
