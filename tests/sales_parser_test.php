<?php

require dirname(__DIR__) . '/lib/SalesParser.php';

$checks = 0;
$assert = static function ($actual, $expected, string $label) use (&$checks): void {
    $checks++;
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
};

$siteSale = SalesParser::parse(
    'noreply@интерсититур.рф',
    'Электронные билеты на заказ #159672478',
    'Билетов: 2 Тула — Анапа Отправление 03 июля, в 11:50 Иванова Анна 7000 р. Иванов Иван 7000 р.',
    '2026-06-12 19:13:56',
    'message-a'
);
$assert($siteSale['channel'], 'site', 'Канал своего сайта');
$assert($siteSale['kind'], 'sale', 'Продажа своего сайта');
$assert($siteSale['quantity'], 2, 'Несколько билетов в письме');
$assert($siteSale['amount'], 14000.0, 'Сумма нескольких билетов');
$assert($siteSale['depart_at'], '2026-07-03 11:50:00', 'Год рейса относительно даты письма');
$assert($siteSale['order_no'], '159672478', 'Номер заказа');
$assert(SalesParser::relevant($siteSale), true, 'Известная продажа учитывается');

$payment = SalesParser::parse(
    'noreply@интерсититур.рф',
    'Оплата заявки (SO-20260613-080100-C8AD) прошла успешно',
    'Заявка успешно оплачена. Оплаченная сумма: 12000 руб.',
    '2026-06-13 05:11:28',
    'message-b'
);
$assert($payment['kind'], 'payment', 'Тип оплаты');
$assert($payment['order_no'], 'SO-20260613-080100-C8AD', 'Бизнес-ID заявки');
$assert($payment['amount'], 12000.0, 'Сумма оплаты');

$refund = SalesParser::parse(
    'noreply@интерсититур.рф',
    'Возврат билета из заказа #158035159',
    'Билет успешно возвращен. Сумма к возврату 5700 руб. Москва — Евпатория Отправление 25 июля, в 08:30',
    '2026-06-11 16:22:06',
    'message-c'
);
$assert($refund['kind'], 'refund', 'Тип возврата');
$assert($refund['amount'], 5700.0, 'Сумма возврата');
$assert($refund['depart_at'], '2026-07-25 08:30:00', 'Дата возвратного билета');
$assert($refund['event_key'] === $siteSale['event_key'], false, 'Разные заказы не дедуплицируются');

$saleKey = SalesParser::eventKey('gobus', 'sale', '623530', '');
$assert($saleKey, SalesParser::eventKey('gobus', 'sale', '623530', ''), 'Повтор продажи имеет стабильный ключ');
$assert($saleKey === SalesParser::eventKey('gobus', 'refund', '623530', ''), false, 'Возврат не конфликтует с продажей');

$artmark = SalesParser::parse(
    'cluster@e-traffic.ru',
    'Агент Рус-Билет, Билет 176089',
    'Отправление Ростов-на-Дону. Рейс Ростов-на-Дону - Евпатория 19.06.2026 14:40 Пассажир Кузьмина Карина Агент Рус-Билет Билет 176089',
    '2026-06-12 07:34:25',
    'message-artmark'
);
$assert($artmark['channel'], 'artmark', 'Канал Артмарк');
$assert($artmark['kind'], 'sale', 'Продажа Артмарк');
$assert($artmark['ticket_no'], '176089', 'Билет Артмарк без символа №');
$assert($artmark['depart_at'], '2026-06-19 14:40:00', 'Дата Артмарк');

$unknown = SalesParser::parse('person@example.com', 'Встреча', 'Обычное письмо', '2026-09-02 10:00:00', 'message-d');
$assert($unknown['channel'], 'other', 'Неизвестный отправитель');
$assert(SalesParser::relevant($unknown), false, 'Постороннее письмо не попадает в показатели');

echo "SalesParser: OK ($checks checks)\n";
