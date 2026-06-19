<?php
// Проверка рендера переменных (телефон-галочка + {автобус}=описание+номер) на реальной ведомости.
// Функции api.php определяются до switch; читаем их в shutdown (switch завершится json_out).
// Использование: php scripts/vars_check.php <manifest_id>
require __DIR__ . '/../app/bootstrap.php';
$MID = (int) ($argv[1] ?? 0);

register_shutdown_function(function () use ($MID) {
    fwrite(STDOUT, "\n===== group_vars (manifest_id=$MID) =====\n");
    if ($MID === 0) {
        // синтетика: телефон есть + автобус E340KУ82 (в справочнике как «Белый Ютонг E340KУ82»)
        $manifest = ['departure_at' => '2026-07-25 08:00:00', 'route' => 'Москва - Евпатория',
            'bus' => 'E340KУ82', 'carrier' => 'ИП Тест', 'drivers' => 'Чебан Игорь',
            'driver_phone' => '+79991234567', 'extra_info' => ''];
        $g = ['station' => 'Москва (Лесопарковая)', 'address' => '', 'map_url' => '', 'date' => '25.07.2025', 'time' => '08:00'];
        $p = ['name' => 'Тест', 'seat' => '1', 'from_stop' => 'Москва', 'to_stop' => '—'];
        foreach ([['phone_on' => 1], ['phone_on' => 0], []] as $body) {
            $opts = send_opts($manifest, $body);
            $vars = group_vars($manifest, $p, $g, $opts);
            $on = array_key_exists('phone_on', $body) ? $body['phone_on'] : '(нет ключа=вкл)';
            printf("phone_on=%-14s → {тел_водителя}=«%s»  {автобус}=«%s»\n", $on, $vars['{тел_водителя}'], $vars['{автобус}']);
        }
        return;
    }
    $manifest = get_manifest($MID);
    if (!$manifest) { echo "нет ведомости\n"; return; }
    echo "ведомость: bus='{$manifest['bus']}' driver_phone='{$manifest['driver_phone']}'\n";
    $b = manifest_bus($manifest);
    echo 'manifest_bus: ' . ($b ? "model='{$b['model']}' plate='{$b['plate']}'" : 'не найден в справочнике') . "\n";
    $groups = build_groups($manifest);
    $g = $groups[0] ?? null;
    if (!$g) { echo "нет групп\n"; return; }
    $p = ['name' => 'Тест Тестов', 'seat' => '1', 'from_stop' => $g['station'], 'to_stop' => '—'];
    foreach ([['phone_on' => 1], ['phone_on' => 0], []] as $body) {
        $opts = send_opts($manifest, $body);
        $vars = group_vars($manifest, $p, $g, $opts);
        $on = array_key_exists('phone_on', $body) ? $body['phone_on'] : '(нет ключа=вкл)';
        printf("phone_on=%-14s → {тел_водителя}=«%s»  {автобус}=«%s»  {время}=«%s»\n",
            $on, $vars['{тел_водителя}'], $vars['{автобус}'], $vars['{время}']);
    }
});

$_GET['a'] = '__vars_test_nop__';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../app/api.php';
