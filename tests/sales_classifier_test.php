<?php

require dirname(__DIR__) . '/lib/SalesClassifier.php';

$checks = 0;
$assert = static function ($actual, $expected, string $label) use (&$checks): void {
    $checks++;
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
};

$assert(SalesClassifier::normalizeEmail('Operator <INFO@GoBus.RU>'), 'info@gobus.ru', 'Нормализация email');
$assert(SalesClassifier::normalizeEmail('noreply@интерсититур.рф') !== '', true, 'IDN-адрес не теряется');
$assert(SalesClassifier::splitAddresses("a@example.ru, A@example.ru\nb@example.ru"), ['a@example.ru', 'b@example.ru'], 'Адреса без дублей');
$assert(SalesClassifier::invalidAddressTokens('a@example.ru bad-address'), ['bad-address'], 'Некорректный адрес не теряется молча');
$assert(SalesClassifier::normalizeSenderPattern('@ROS-BILET.RU'), '@ros-bilet.ru', 'Шаблон домена');

$carriers = [
    ['id' => 7, 'name' => 'Перевозчик 7', 'addresses' => ['carrier@example.ru']],
];
$rules = [
    ['id' => 1, 'tag' => 'Рос-Билет', 'sender_pattern' => '@ros-bilet.ru', 'subject_contains' => '',
        'report_agent_id' => 11, 'priority' => 100, 'active' => true],
    ['id' => 2, 'tag' => 'Рос-Билет возвраты', 'sender_pattern' => 'perevozchik@ros-bilet.ru', 'subject_contains' => 'возврат',
        'report_agent_id' => 12, 'priority' => 50, 'active' => true],
];

$ours = SalesClassifier::classifyWithConfiguration(
    'perevozchik@ros-bilet.ru', 'sales@ours.ru', 'Продан билет', ['sales@ours.ru'], $carriers, $rules
);
$assert($ours['owner_side'], 'ours', 'Наш адрес определяет нашу продажу');
$assert($ours['agent_tag'], 'Рос-Билет', 'Домен определяет агента');
$assert($ours['report_agent_id'], 11, 'Связь с агентом отчётности');

$carrier = SalesClassifier::classifyWithConfiguration(
    'perevozchik@ros-bilet.ru', 'carrier@example.ru', 'Возврат билета', ['sales@ours.ru'], $carriers, $rules
);
$assert($carrier['owner_side'], 'carrier', 'Адрес перевозчика определяет сторону');
$assert($carrier['carrier_id'], 7, 'Сохраняется ID перевозчика');
$assert($carrier['agent_tag'], 'Рос-Билет возвраты', 'Условие темы уточняет правило');

$unknown = SalesClassifier::classifyWithConfiguration(
    'person@example.ru', 'unknown@example.ru', 'Продан билет', ['sales@ours.ru'], $carriers, $rules
);
$assert($unknown['owner_side'], 'unassigned', 'Неизвестный адрес не угадывается');
$assert($unknown['agent_tag'], '', 'Неизвестный отправитель не угадывается');

$conflictCarriers = [
    ['id' => 7, 'name' => 'Первый', 'addresses' => ['same@example.ru']],
    ['id' => 8, 'name' => 'Второй', 'addresses' => ['same@example.ru']],
];
$conflict = SalesClassifier::classifyWithConfiguration(
    'perevozchik@ros-bilet.ru', 'same@example.ru', 'Продан билет', [], $conflictCarriers, $rules
);
$assert($conflict['owner_side'], 'unassigned', 'Конфликт адресов не классифицируется');
$assert(array_keys(SalesClassifier::duplicateOwners([], $conflictCarriers)), ['same@example.ru'], 'Конфликт находится при сохранении');

echo "SalesClassifier: OK ($checks checks)\n";
