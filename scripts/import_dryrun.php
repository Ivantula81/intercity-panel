<?php
// СУХОЙ ПРОГОН импорта ведомости: всё в транзакции, в конце ROLLBACK — ничего не сохраняется.
// Использование: php scripts/import_dryrun.php <путь_к_csv>
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/manifest_import.php';

$path = $argv[1] ?? '';
if (!is_file($path)) { fwrite(STDERR, "нет файла: $path\n"); exit(1); }

$pdo = db();
$pdo->beginTransaction();

$before = [
    'stops'   => (int) $pdo->query('SELECT COUNT(*) FROM stops')->fetchColumn(),
    'buses'   => (int) $pdo->query('SELECT COUNT(*) FROM buses')->fetchColumn(),
    'drivers' => (int) $pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn(),
];

$file = ['error' => 0, 'size' => filesize($path), 'tmp_name' => $path, 'name' => basename($path)];
$mid = import_manifest_csv($file);

$after = [
    'stops'   => (int) $pdo->query('SELECT COUNT(*) FROM stops')->fetchColumn(),
    'buses'   => (int) $pdo->query('SELECT COUNT(*) FROM buses')->fetchColumn(),
    'drivers' => (int) $pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn(),
];

$ln = str_repeat('=', 70);
echo "$ln\nСУХОЙ ПРОГОН ИМПОРТА (manifest_id=$mid, всё откатится)\n$ln\n";
echo sprintf("Справочники +новых: станции +%d, автобусы +%d, водители +%d\n",
    $after['stops'] - $before['stops'], $after['buses'] - $before['buses'], $after['drivers'] - $before['drivers']);

echo "\n— manifest_groups (время посадки по группам из ведомости) —\n";
$g = $pdo->prepare('SELECT station, boarding_date, boarding_time FROM manifest_groups WHERE manifest_id=? ORDER BY station');
$g->execute([$mid]);
foreach ($g->fetchAll() as $r) printf("   %-32s %s %s\n", $r['station'], $r['boarding_date'], $r['boarding_time']);

echo "\n— автобус в справочнике (по номеру из ведомости) —\n";
$b = $pdo->query("SELECT code, plate, model, seats, note FROM buses WHERE plate LIKE '%340%' OR code LIKE '%340%'");
foreach ($b->fetchAll() as $r) printf("   code=%s plate=%s model='%s' seats=%d note='%s'\n", $r['code'], $r['plate'], $r['model'], $r['seats'], $r['note']);

echo "\n— водители в справочнике (из ведомости) —\n";
$d = $pdo->query("SELECT name, phone FROM drivers WHERE name LIKE '%Чебан%' OR name LIKE '%Горносталь%'");
foreach ($d->fetchAll() as $r) printf("   %-40s тел='%s'\n", $r['name'], $r['phone']);

echo "\n— станции прибытия попали в справочник? (примеры to_id) —\n";
$s = $pdo->query("SELECT gds_id, station, city FROM stops WHERE gds_id IN (62,60,16,19,88) ORDER BY gds_id");
foreach ($s->fetchAll() as $r) printf("   id=%-4s %s (%s)\n", $r['gds_id'], $r['station'], $r['city']);

$pdo->rollBack();
echo "\n$ln\n✓ ROLLBACK выполнен — в БД ничего не записано.\n$ln\n";
