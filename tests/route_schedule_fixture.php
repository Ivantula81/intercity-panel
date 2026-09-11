<?php
// Local-only browser/API fixture. No production bootstrap, credentials, or external calls.
if (PHP_SAPI !== 'cli-server' || !getenv('SCHEDULE_TEST_DB')) { http_response_code(404); exit; }
define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/lib/RouteScheduleStore.php';
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . getenv('SCHEDULE_TEST_DB'), null, null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $schema = file_get_contents(PANEL_ROOT . '/schema28.sql');
        $schema = str_replace(['BIGINT AUTO_INCREMENT PRIMARY KEY',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],['INTEGER PRIMARY KEY AUTOINCREMENT',''],$schema);
        $pdo->exec($schema);
        $pdo->exec('CREATE TABLE IF NOT EXISTS manifests (id INT PRIMARY KEY,trip_number TEXT,route TEXT,departure_at TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS passengers (manifest_id INT,from_stop TEXT,from_id INT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS manifest_groups (manifest_id INT,station TEXT,station_id INT,destination TEXT,boarding_date TEXT,boarding_time TEXT)');
        if (!$pdo->query('SELECT COUNT(*) FROM manifests')->fetchColumn()) {
            $pdo->exec("INSERT INTO manifests VALUES (1,'337','Евпатория ж/д - Москва (Саларьево)','2026-09-11 13:00:00')");
            foreach ([[23,'Евпатория ж/д','13:00',0],[27,'Саки АЗС Атан','13:40',0],[2,'Симферополь ж/д (парковка Пуд)','14:35',0],
                [103,'Грушевка (Судак)','13:00',0],[25,'Феодосия (ост.пансионат Украина)','16:00',0],[63,'Севастополь (сады)','13:15',0],
                [32,'Краснодар ТЦ "Бауцентр"','01:00',1],[6,'Тула "Автовокзал"','15:10',1]] as [$id,$name,$time,$day]) {
                $pdo->prepare('INSERT INTO passengers VALUES (?,?,?)')->execute([1,$name,$id]);
                $pdo->prepare('INSERT INTO manifest_groups VALUES (?,?,?,?,?,?)')->execute([1,$name,$id,'',$day ? '12.09.2026':'11.09.2026',$time]);
            }
        }
    }
    return $pdo;
}
function e($s): string { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function is_admin(): bool { return ($_SERVER['HTTP_X_TEST_ROLE'] ?? 'admin') === 'admin'; }
function json_out(array $value, int $status=200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($value,JSON_UNESCAPED_UNICODE); exit; }
function require_admin(): void { if (!is_admin()) json_out(['ok'=>false,'error'=>'Только администратор'],403); }
function audit_actor_id(): int { return 42; }
function audit_event(...$args): void {}
function csrf_token(): string { return 'fixture'; }
function current_user_name(): string { return 'Тестовый администратор'; }
function flash(): string { return ''; }
function get_manifest(int $id): array {
    $q=db()->prepare('SELECT * FROM manifests WHERE id=?');$q->execute([$id]);
    return $q->fetch() ?: throw new InvalidArgumentException('Не найдена ведомость');
}
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/assets/')) {
    $file = PANEL_ROOT . '/public/assets/' . basename($path);
    if (!is_file($file)) {http_response_code(404);exit;}
    header('Content-Type: ' . (str_ends_with($path,'.css') ? 'text/css':'application/javascript')); readfile($file);exit;
}
if (($_GET['p'] ?? '')==='api') {
    $action=$_GET['a'] ?? '';
    $body=json_decode(file_get_contents('php://input'),true) ?: [];
    // Deliberately forbid live GDS and every messaging endpoint in this test server.
    if ($action==='schedule.gds' || $action==='gds.times') json_out(['ok'=>false,'error'=>'Тестовый сбой ГДС: данные не изменены.'],502);
    if (!str_starts_with($action,'schedule.')) json_out(['ok'=>false,'error'=>'Only schedule API is available in this fixture'],404);
    require PANEL_ROOT . '/app/schedule_api.php';exit;
}
$title = 'Расписание'; $page = 'catalogs';
$content = function () { require PANEL_ROOT . '/app/views/schedules.php'; };
require PANEL_ROOT . '/app/views/layout.php';
