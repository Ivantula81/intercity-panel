<?php
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__, 3));

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($requestPath !== '/' && is_file(PANEL_ROOT . '/public' . $requestPath)) {
    return false;
}

function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'ux-audit'; }
function current_user_name(): string { return 'Диспетчер'; }
function is_admin(): bool { return false; }
function flash(?string $set = null): string { return ''; }
function opt(string $name, string $default = ''): string { return $default; }
function view(string $name, array $vars = []): void { extract($vars); require PANEL_ROOT . '/app/views/' . $name . '.php'; }

final class AuditStatement {
    public function __construct(private array $rows = []) {}
    public function execute(array $params = []): bool { return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch(): array|false { return $this->rows[0] ?? false; }
    public function fetchColumn(): mixed { return $this->rows[0][array_key_first($this->rows[0] ?? [])] ?? false; }
}
final class AuditDb {
    public function query(string $sql): AuditStatement {
        if (str_contains($sql, 'FROM carriers')) return new AuditStatement([
            ['id'=>1,'atp'=>'ООО «ТерраТрансКрым»','contract_no'=>'14/26','contract_date'=>'2026-01-15','note'=>'Основной'],
        ]);
        if (str_contains($sql, 'FROM stops')) return new AuditStatement([
            ['id'=>1,'gds_id'=>101,'station'=>'Москва, Саларьево','city'=>'Москва','address'=>'Киевское ш., 23-й км','map_url'=>'https://maps.example/1','note'=>'Платформа 6'],
            ['id'=>2,'gds_id'=>205,'station'=>'Воронеж, Центральный АВ','city'=>'Воронеж','address'=>'Московский пр-т, 17','map_url'=>'https://maps.example/2','note'=>''],
        ]);
        if (str_contains($sql, 'FROM buses')) return new AuditStatement([
            ['id'=>1,'code'=>'474','plate'=>'А123АА 82','model'=>'Yutong ZK6122H9','seats'=>53,'driver_phone'=>'+7 978 555-12-34','note'=>'Wi-Fi','photo'=>''],
            ['id'=>2,'code'=>'322','plate'=>'В456ВВ 82','model'=>'Golden Dragon','seats'=>49,'driver_phone'=>'+7 978 555-43-21','note'=>'','photo'=>''],
        ]);
        if (str_contains($sql, 'FROM drivers')) return new AuditStatement([
            ['id'=>1,'name'=>'Петров Алексей Сергеевич','phone'=>'+7 978 555-12-34','bus_id'=>1,'note'=>'основной'],
            ['id'=>2,'name'=>'Иванов Максим Олегович','phone'=>'+7 978 555-43-21','bus_id'=>2,'note'=>''],
        ]);
        if (str_contains($sql, 'FROM message_templates')) return new AuditStatement([
            ['id'=>1,'name'=>'Посадка — основной','body'=>'Здравствуйте, {имя}! Автобус {автобус} отправляется {дата} в {время}. Посадка: {посадка}.'],
        ]);
        if (str_contains($sql, 'FROM custom_variables')) return new AuditStatement([]);
        return new AuditStatement([]);
    }
    public function prepare(string $sql): AuditStatement { return new AuditStatement([]); }
}
function db(): AuditDb { static $db; return $db ??= new AuditDb(); }

$manifest = [
    'id'=>42,'trip_number'=>'1287','route'=>'Москва — Симферополь','departure_at'=>'2026-06-22 18:30:00',
    'file_name'=>'vedomost_1287.csv','carrier'=>'ООО «ТерраТрансКрым»','bus'=>'474 · Yutong · А123АА 82',
    'drivers'=>'Петров А.С. / Иванов М.О.','driver_phone'=>'+7 978 555-12-34','extra_info'=>'При себе иметь паспорт',
    'created_at'=>'2026-06-21 12:20:00','cnt'=>34,
];
$manifests = [$manifest, array_merge($manifest,['id'=>41,'trip_number'=>'1285','route'=>'Симферополь — Москва','departure_at'=>'2026-06-21 17:00:00','cnt'=>29]), array_merge($manifest,['id'=>40,'trip_number'=>'1279','route'=>'Москва — Ялта','departure_at'=>'2026-06-20 19:15:00','cnt'=>41])];
$passengers = [
    ['id'=>1,'seat'=>'7','name'=>'Анна Соколова','phone'=>'+7 905 123-45-67','doc'=>'Паспорт 45 11 123456','ticket'=>'840102','from_stop'=>'Москва, Саларьево','to_stop'=>'Симферополь','pay_note'=>''],
    ['id'=>2,'seat'=>'12','name'=>'Илья Морозов','phone'=>'+7 916 234-56-78','doc'=>'Паспорт 45 09 654321','ticket'=>'840103','from_stop'=>'Воронеж, Центральный АВ','to_stop'=>'Симферополь','pay_note'=>'детский багаж'],
    ['id'=>3,'seat'=>'18','name'=>'Елена Волкова','phone'=>'+7 977 345-67-89','doc'=>'Паспорт 40 12 112233','ticket'=>'840104','from_stop'=>'Москва, Саларьево','to_stop'=>'Керчь','pay_note'=>''],
];
$contacts = [
    ['id'=>7,'name'=>'Анна Соколова','phone'=>'+7 905 123-45-67','messages_count'=>12,'trips_count'=>3,'has_whatsapp'=>1,'has_max'=>0,'has_telegram'=>1,'last_route'=>'Москва — Симферополь','last_seen'=>'2026-06-21 13:42:00','tags'=>'постоянный','note'=>'у окна','first_seen'=>'2026-02-03'],
    ['id'=>8,'name'=>'Илья Морозов','phone'=>'+7 916 234-56-78','messages_count'=>5,'trips_count'=>1,'has_whatsapp'=>1,'has_max'=>1,'has_telegram'=>null,'last_route'=>'Москва — Симферополь','last_seen'=>'2026-06-21 12:18:00','tags'=>'','note'=>'','first_seen'=>'2026-06-20'],
    ['id'=>9,'name'=>'Елена Волкова','phone'=>'+7 977 345-67-89','messages_count'=>18,'trips_count'=>5,'has_whatsapp'=>0,'has_max'=>1,'has_telegram'=>1,'last_route'=>'Москва — Ялта','last_seen'=>'2026-06-20 18:31:00','tags'=>'VIP','note'=>'звонить после 12:00','first_seen'=>'2025-11-14'],
];
$messages = [
    ['channel'=>'whatsapp','status'=>'sent','body'=>'Анна, напоминаем о поездке завтра в 18:30.','sent_at'=>'2026-06-21 13:40:00','created_at'=>'2026-06-21 13:40:00','delivered_at'=>'2026-06-21 13:40:10','read_at'=>'2026-06-21 13:42:00','recipient'=>'+7 905 123-45-67','passenger_name'=>'Анна Соколова','actor'=>'Диспетчер','error'=>''],
    ['channel'=>'max','status'=>'failed','body'=>'Данные посадки по рейсу №1287','sent_at'=>null,'created_at'=>'2026-06-21 12:20:00','delivered_at'=>null,'read_at'=>null,'recipient'=>'+7 916 234-56-78','passenger_name'=>'Илья Морозов','actor'=>'Диспетчер','error'=>'Канал недоступен'],
];

$page = $_GET['audit'] ?? 'dashboard';
if ($page === 'login') { view('login',['error'=>'']); exit; }
if ($page === 'boarding') { view('boarding',compact('manifest','passengers')); exit; }

$content = match ($page) {
    'dashboard' => fn()=>view('dashboard',['links'=>[
        ['id'=>1,'url'=>'#','color'=>'purple','icon'=>'briefcase','title'=>'Планфикс'],['id'=>2,'url'=>'#','color'=>'blue','icon'=>'chart','title'=>'GDS Автовокзалы'],['id'=>3,'url'=>'#','color'=>'green','icon'=>'mail','title'=>'Почта'],
    ],'stats'=>['manifests'=>126,'sent_today'=>84,'failed'=>3],'recentManifests'=>$manifests]),
    'sales' => fn()=>view('sales',['period'=>'7d','metrics'=>['sales_cnt'=>146,'refund_cnt'=>4,'cancel_cnt'=>7,'sales_sum'=>482700,'refund_sum'=>12900,'total'=>168,'latest_event_at'=>'2026-09-02 12:51:00'], 'byChannel'=>[
        ['channel'=>'site','sales'=>58,'refunds'=>2,'cancels'=>1,'sum'=>482700],['channel'=>'avtovokzaly','sales'=>42,'refunds'=>1,'cancels'=>2,'sum'=>0],['channel'=>'blablacar','sales'=>31,'refunds'=>1,'cancels'=>3,'sum'=>0],
    ],'topDates'=>[['d'=>'2026-06-22','c'=>43],['d'=>'2026-06-25','c'=>32],['d'=>'2026-06-28','c'=>21]],'feed'=>[
        ['occurred_at'=>'2026-09-02 12:51:00','channel'=>'site','kind'=>'sale','quantity'=>2,'route'=>'Москва — Симферополь','segment'=>'','depart_at'=>'2026-09-22','amount'=>8400],['occurred_at'=>'2026-09-02 12:43:00','channel'=>'avtovokzaly','kind'=>'sale','quantity'=>1,'route'=>'Симферополь — Москва','segment'=>'','depart_at'=>'2026-09-23','amount'=>null],['occurred_at'=>'2026-09-02 12:08:00','channel'=>'blablacar','kind'=>'cancel','quantity'=>1,'route'=>'Москва — Ялта','segment'=>'','depart_at'=>'2026-09-24','amount'=>null],
    ],'syncState'=>['status'=>'ok','last_success_at'=>date('Y-m-d H:i:s'),'imported_count'=>168,'ignored_count'=>2,'error_count'=>0,'last_error'=>'']]),
    'notifications' => fn()=>view('notifications',['manifests'=>$manifests,'selectedId'=>42,'selected'=>$manifest,'journal'=>$messages,'inbox'=>[],'buses'=>db()->query('FROM buses')->fetchAll(),'drivers'=>db()->query('FROM drivers')->fetchAll(),'uploadError'=>'']),
    'chats' => fn()=>view('chats',['startPhone'=>'']),
    'manifests' => fn()=>view('manifests',['manifests'=>$manifests,'uploadError'=>'']),
    'manifest' => fn()=>view('manifest',['manifest'=>$manifest,'passengers'=>$passengers]),
    'contacts' => fn()=>view('contacts',['contacts'=>$contacts,'total'=>248,'q'=>'','sort'=>'last_seen']),
    'contact' => fn()=>view('contact',['contact'=>$contacts[0],'history'=>$messages,'incoming'=>[['body'=>'Спасибо! Подскажите, можно взять складную коляску?','received_at'=>'2026-06-21 13:42:00']]]),
    'broadcast' => fn()=>view('broadcast',['manifests'=>$manifests]),
    'catalogs' => fn()=>view('catalogs'),
    'logs' => fn()=>view('logs',['filter'=>'all','counts'=>['total'=>1842,'failed'=>3,'sent'=>1810,'undelivered'=>29],'rows'=>$messages]),
    'settings' => fn()=>view('settings'),
    default => fn()=>view('dashboard',['links'=>[],'stats'=>['manifests'=>0,'sent_today'=>0,'failed'=>0],'recentManifests'=>[]]),
};

ob_start();
view('layout',['title'=>match($page){'sales'=>'Продажи','notifications'=>'Уведомления','chats'=>'Чаты','manifests'=>'Ведомости','manifest'=>'Ведомость №1287','contacts'=>'Контакты','contact'=>'Анна Соколова','broadcast'=>'Свободная рассылка','catalogs'=>'Справочники','logs'=>'Логи','settings'=>'Настройки',default=>'Панель управления'},'page'=>$page,'content'=>$content]);
$html = ob_get_clean();
if ($page === 'chats') {
    $html = str_replace('</body>', <<<'HTML'
<script>
document.body.dataset.page = 'chats-static';
(() => {
  const threads = document.getElementById('chatThreads');
  threads.innerHTML = `
    <div class="chat-thread unread active"><span class="ct-ava">А</span><div class="ct-main"><div class="ct-top"><span class="ct-name">Анна Соколова</span><span class="ct-time">13:42</span></div><div class="ct-bot"><span class="ct-last">Можно взять складную коляску?</span><span class="ct-badge">2</span></div></div></div>
    <div class="chat-thread"><span class="ct-ava">И</span><div class="ct-main"><div class="ct-top"><span class="ct-name">Илья Морозов</span><span class="ct-time">12:18</span></div><div class="ct-bot"><span class="ct-last">Вы: Данные посадки отправлены</span></div></div></div>
    <div class="chat-thread"><span class="ct-ava">Е</span><div class="ct-main"><div class="ct-top"><span class="ct-name">Елена Волкова</span><span class="ct-time">вчера</span></div><div class="ct-bot"><span class="ct-last">Спасибо</span></div></div></div>`;
  document.getElementById('chatEmpty').hidden = true;
  document.getElementById('chatPane').hidden = false;
  document.getElementById('chatAva').textContent = 'А';
  document.getElementById('chatName').textContent = 'Анна Соколова';
  document.getElementById('chatPhone').textContent = '+7 905 123-45-67';
  document.getElementById('chatBody').innerHTML = `
    <div class="cm out"><div class="cm-bubble"><span class="cm-text">Анна, напоминаем о поездке завтра в 18:30. Посадка — Саларьево, платформа 6.</span><span class="cm-meta">13:40 ✓✓</span></div></div>
    <div class="cm in"><div class="cm-bubble"><span class="cm-text">Спасибо! Подскажите, можно взять складную коляску?</span><span class="cm-meta">13:42</span></div></div>`;
})();
</script>
</body>
HTML, $html);
}
echo $html;
