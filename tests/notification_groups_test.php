<?php

require dirname(__DIR__) . '/lib/NotificationGroups.php';

$passengers = [
    ['from_id' => 1, 'from' => 'Москва', 'to_id' => 10, 'to' => 'Симферополь'],
    ['from_id' => 1, 'from' => 'Москва', 'to_id' => 11, 'to' => 'Севастополь'],
    ['from_id' => 1, 'from' => 'Москва', 'to_id' => 10, 'to' => 'Симферополь'],
    ['from_id' => 2, 'from' => 'Тула', 'to_id' => 10, 'to' => 'Симферополь'],
];

$keys = [];
foreach ($passengers as $p) {
    $keys[NotificationGroups::key($p['from_id'], $p['from'], $p['to_id'], $p['to'])] = true;
}
if (count($keys) !== 3) {
    fwrite(STDERR, 'Ожидалось 3 группы по парам откуда-куда, получено ' . count($keys) . "\n");
    exit(1);
}

$group = ['station' => 'Москва', 'destination' => 'Севастополь'];
if (!NotificationGroups::matches($group, ' москва ', 'СЕВАСТОПОЛЬ')) {
    fwrite(STDERR, "Сопоставление маршрута должно игнорировать регистр и пробелы\n");
    exit(1);
}
if (NotificationGroups::matches($group, 'Москва', 'Симферополь')) {
    fwrite(STDERR, "Разные пункты прибытия не должны попадать в одну группу\n");
    exit(1);
}

$sorted = NotificationGroups::sortByRoute([
    ['station' => 'Тула', 'destination' => 'Ялта'],
    ['station' => 'Москва', 'destination' => 'Симферополь'],
    ['station' => 'Тула', 'destination' => 'Анапа'],
    ['station' => 'Москва', 'destination' => 'Евпатория'],
]);
$actualOrder = array_map(fn(array $g): string => $g['station'] . ' → ' . $g['destination'], $sorted);
$expectedOrder = ['Москва → Евпатория', 'Москва → Симферополь', 'Тула → Анапа', 'Тула → Ялта'];
if ($actualOrder !== $expectedOrder) {
    fwrite(STDERR, "Группы должны идти блоками по отправлению, затем по прибытию\n");
    exit(1);
}

echo "Notification route groups: OK\n";
