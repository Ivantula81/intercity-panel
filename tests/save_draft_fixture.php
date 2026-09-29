<?php
// CLI-only renderer: real views with synthetic inputs and an in-memory database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function icon($name): string { return ''; }
function db(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE carriers (id INTEGER, atp TEXT, contract_no TEXT)');
    }
    return $db;
}
$values = json_decode(stream_get_contents(STDIN), true) ?: [];
$manifest = array_replace(array_fill_keys(['route','carrier','bus','drivers','driver_phone','extra_info'], ''),
    ['id'=>1,'trip_number'=>'TEST','departure_at'=>null,'route'=>'Тестовый маршрут'], $values['manifest'] ?? []);
$passengers = [array_replace(array_fill_keys(['seat','name','phone','doc','ticket','from_stop','to_stop','pay_note'], ''),
    ['id'=>11,'seat'=>'1','name'=>'Тестовый пассажир'], $values['passenger'] ?? [])];
$startPhone = '';
$page = ($argv[1] ?? '') === 'chats' ? 'chats' : 'manifest';
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/panel.css"></head>
<body data-page="<?= $page ?>"><main class="main">
<?php require __DIR__ . '/../app/views/' . $page . '.php'; ?>
</main><script>window.CSRF='synthetic';</script><script src="/assets/panel.js"></script><?php if (($argv[1] ?? '') === 'notifications'): ?><script src="/assets/notifications.js"></script><?php endif; ?></body></html>
