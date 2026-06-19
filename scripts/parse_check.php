<?php
// Разбор ведомости теми же методами, что и панель: ManifestParser + группировка по from_id.
// Использование: php scripts/parse_check.php <путь_к_csv>
require_once __DIR__ . '/../lib/ManifestParser.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Файл не найден: $path\n");
    exit(1);
}

$parser = new ManifestParser();
$res = $parser->parseFile($path, basename($path));
$trip = $res['trip'];
$passengers = $res['passengers'];

// группировка ровно как build_groups(): ключ = id станции, иначе имя
$groups = [];
$noId = 0; $badPhone = []; $validPhones = [];
foreach ($passengers as $p) {
    $sid = $p['from_id'] !== null ? (int) $p['from_id'] : null;
    $name = trim((string) $p['from']) !== '' ? trim((string) $p['from']) : '(посадка не указана)';
    $key = $sid !== null ? 'id' . $sid : 'nm' . mb_strtolower($name, 'UTF-8');
    if ($sid === null) $noId++;
    if (!isset($groups[$key])) {
        $groups[$key] = ['id' => $sid, 'station' => $name, 'n' => 0, 'valid' => 0, 'bad' => 0, 'names' => [], 'times' => []];
    }
    $groups[$key]['names'][$name] = ($groups[$key]['names'][$name] ?? 0) + 1;
    if (!empty($p['depart_at'])) $groups[$key]['times'][$p['depart_at']] = ($groups[$key]['times'][$p['depart_at']] ?? 0) + 1;
    $groups[$key]['n']++;
    if (!empty($p['phone_valid'])) { $groups[$key]['valid']++; $validPhones[$p['phone']] = true; }
    else { $groups[$key]['bad']++; if (trim((string) $p['phone']) !== '') $badPhone[] = $p['name'] . ' — «' . $p['phone'] . '»'; }
}
// сортируем по числу пассажиров
uasort($groups, fn($a, $b) => $b['n'] <=> $a['n']);

$line = str_repeat('─', 78);
echo "$line\n";
echo "ФАЙЛ:    " . $res['file_name'] . "\n";
echo "РЕЙС:    " . $trip['route'] . "\n";
echo "ОТПРАВ.: " . $trip['departure_at'] . "\n";
if (!empty($trip['carrier'])) echo "ПЕРЕВ.:  " . $trip['carrier'] . "\n";
if (!empty($trip['bus']))     echo "НОМЕР АВТ.: " . $trip['bus'] . "\n";
if (!empty($trip['transport_info'])) echo "ТРАНСПОРТ:  " . $trip['transport_info'] . "  (места/категория — в карточку автобуса)\n";
if (!empty($trip['drivers']))  echo "ВОДИТЕЛИ:   " . $trip['drivers'] . "\n";
echo "$line\n";
echo "ПАССАЖИРОВ: " . count($passengers)
   . " | уникальных валидных телефонов: " . count($validPhones)
   . " | групп посадки: " . count($groups) . "\n";
echo "$line\n";
printf("%-6s | %-30s | %4s | %4s | %4s | %s\n", 'id ст.', 'станция посадки', 'чел', 'тел✓', 'тел✗', 'время отправления');
echo "$line\n";
foreach ($groups as $g) {
    $time = $g['times'] ? implode(' / ', array_keys($g['times'])) : '— нет в файле —';
    printf("%-6s | %-30s | %4d | %4d | %4d | %s\n",
        $g['id'] !== null ? $g['id'] : '—',
        mb_strimwidth($g['station'], 0, 30, '…', 'UTF-8'),
        $g['n'], $g['valid'], $g['bad'], $time);
    if (count($g['names']) > 1) {
        foreach ($g['names'] as $nm => $cnt) echo "       ↳ «$nm» ($cnt)\n";
    }
}
echo "$line\n";
echo "⚠️ без id станции (группировка по названию): $noId\n";
if ($badPhone) {
    echo "⚠️ битые/пустые телефоны (" . count($badPhone) . "):\n";
    foreach (array_slice($badPhone, 0, 20) as $b) echo "   • $b\n";
}
if (!empty($res['warnings'])) {
    echo "⚠️ предупреждения парсера:\n";
    foreach ($res['warnings'] as $w) echo "   • " . (is_array($w) ? json_encode($w, JSON_UNESCAPED_UNICODE) : $w) . "\n";
}
echo "$line\n";
