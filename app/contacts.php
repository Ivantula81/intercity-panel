<?php

// CRM-контакты: единая точка пополнения базы из всех отправок и ведомостей.

// Зафиксировать факт ОТПРАВКИ сообщения номеру (увеличивает счётчик сообщений).
function contact_log_message(string $phone, string $name = '', string $route = ''): void
{
    contact_upsert($phone, $name, $route, true, false);
}

// Зафиксировать, что номер встретился в ВЕДОМОСТИ (поездка, без счётчика сообщений).
function contact_log_trip(string $phone, string $name = '', string $route = ''): void
{
    contact_upsert($phone, $name, $route, false, true);
}

function contact_upsert(string $phone, string $name, string $route, bool $countMessage, bool $countTrip): void
{
    $phone = trim($phone);
    if ($phone === '' || !preg_match('/^\+?\d{10,15}$/', $phone)) {
        return;
    }
    if ($phone[0] !== '+') {
        $phone = '+' . $phone;
    }

    $name = trim($name);
    $route = trim($route);
    $dm = $countMessage ? 1 : 0;
    $dt = $countTrip ? 1 : 0;

    // имя обновляем только если новое непустое; маршрут/last_seen — всегда свежие
    db()->prepare(
        'INSERT INTO contacts (phone, name, messages_count, trips_count, last_route, last_seen, first_seen)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = IF(VALUES(name) <> \'\', VALUES(name), name),
            messages_count = messages_count + ?,
            trips_count = trips_count + ?,
            last_route = IF(VALUES(last_route) <> \'\', VALUES(last_route), last_route),
            last_seen = NOW()'
    )->execute([$phone, $name, $dm, $dt, $route, $dm, $dt]);
}
