<?php

// Разовое наполнение базы контактов из существующих отправок и пассажиров.
// Запуск: php /var/www/panel/backfill_contacts.php  (или открыть под логином — см. ниже)

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/app/bootstrap.php';
    require_login();
} else {
    require_once __DIR__ . '/app/bootstrap.php';
}
require_once PANEL_ROOT . '/app/contacts.php';

header('Content-Type: text/plain; charset=utf-8');

// сообщения (только успешные, считаем как messages_count)
$msgs = db()->query("SELECT recipient, passenger_name, m.created_at,
    (SELECT route FROM manifests WHERE id = m.manifest_id) route
    FROM messages m WHERE status = 'sent' ORDER BY id")->fetchAll();
$mCount = 0;
foreach ($msgs as $r) {
    contact_log_message($r['recipient'], $r['passenger_name'] ?? '', $r['route'] ?? '');
    $mCount++;
}

// пассажиры ведомостей (поездки)
$pass = db()->query("SELECT p.phone, p.name, (SELECT route FROM manifests WHERE id = p.manifest_id) route
    FROM passengers p WHERE p.phone REGEXP '^\\\\+?[0-9]{10,15}$'")->fetchAll();
$pCount = 0;
foreach ($pass as $r) {
    contact_log_trip($r['phone'], $r['name'] ?? '', $r['route'] ?? '');
    $pCount++;
}

$total = (int) db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
echo "Обработано отправок: $mCount\n";
echo "Обработано пассажиров: $pCount\n";
echo "Контактов в базе: $total\n";
