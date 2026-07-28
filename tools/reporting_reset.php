<?php

// Полный сброс раздела «Отчётность» до чистой среды.
//
// Что делает:
//   • удаляет справочники отчётности (агенты, договоры, автовокзалы) — владелец заводит заново;
//   • удаляет расчёты: снимки, продажи вокзалов, наличные по рейсам;
//   • сбрасывает фин-поля пассажиров (наша цена, явка, возврат, агент, комментарий);
//   • отвязывает ВСЕ рейсы от отчётности (in_reporting=0) — экран становится пустым,
//     рейсы добавляются туда явно.
//
// Что НЕ трогает (нужно уведомлениям, чатам, ведомостям):
//   ведомости (manifests), пассажиров как таких, сообщения, диалоги, контакты,
//   справочники станций/автобусов/водителей.
//
// Файлы рейсов (manifest_files) — по флагу: --files удаляет и записи, и файлы с диска.
//
// Запуск:  php reporting_reset.php              — показать, что будет (dry-run)
//          php reporting_reset.php apply        — выполнить
//          php reporting_reset.php apply --files — плюс удалить файлы рейсов

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PANEL_ROOT . '/app/reporting_service.php';

$apply = in_array('apply', $argv, true);
$withFiles = in_array('--files', $argv, true);
$db = db();

$count = static function (string $sql) use ($db) {
    try { return (int) $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
};

$plan = [
    ['Снимки расчётов',        'manifest_calculations',   'DELETE FROM manifest_calculations',   $count('SELECT COUNT(*) FROM manifest_calculations')],
    ['Продажи автовокзалов',   'manifest_station_sales',  'DELETE FROM manifest_station_sales',  $count('SELECT COUNT(*) FROM manifest_station_sales')],
    ['Наличные по рейсам',     'manifest_cash_entries',   'DELETE FROM manifest_cash_entries',   $count('SELECT COUNT(*) FROM manifest_cash_entries')],
    ['Справочник автовокзалов','report_stations',         'DELETE FROM report_stations',         $count('SELECT COUNT(*) FROM report_stations')],
    ['Договоры агентов',       'report_agent_contracts',  'DELETE FROM report_agent_contracts',  $count('SELECT COUNT(*) FROM report_agent_contracts')],
    ['Агенты',                 'report_agents',           'DELETE FROM report_agents',           $count('SELECT COUNT(*) FROM report_agents')],
];

echo ($apply ? "═══ СБРОС ОТЧЁТНОСТИ ═══" : "═══ [DRY-RUN] ЧТО БУДЕТ СДЕЛАНО ═══") . "\n\n";
echo "Удаление:\n";
foreach ($plan as [$label, $table, $sql, $n]) {
    printf("  %-26s %5d %s\n", $label, $n, $n ? 'записей' : '(пусто)');
}

// фин-поля пассажиров — сбрасываем, сами строки остаются (нужны уведомлениям)
$pFields = [
    'our_price IS NOT NULL' => 'наша цена',
    "attendance <> 'unknown'" => 'отмеченная явка',
    "refund_status <> 'none'" => 'возврат',
    'agent_contract_id IS NOT NULL' => 'назначенный агент',
    "finance_comment <> ''" => 'фин-комментарий',
];
echo "\nСброс фин-полей пассажиров (сами пассажиры остаются):\n";
foreach ($pFields as $where => $label) {
    printf("  %-26s %5d\n", $label, $count("SELECT COUNT(*) FROM passengers WHERE $where"));
}

$inRep = $count('SELECT COUNT(*) FROM manifests WHERE in_reporting = 1');
$allMan = $count('SELECT COUNT(*) FROM manifests');
printf("\nОтвязка рейсов от отчётности: %d из %d (экран отчётности станет пустым)\n", $inRep, $allMan);
printf("Сброс статуса/заметки/прочих расходов у рейсов: %d\n", $allMan);

$files = $count('SELECT COUNT(*) FROM manifest_files');
echo "\nФайлы рейсов: $files " . ($withFiles ? "→ БУДУТ УДАЛЕНЫ (записи + файлы с диска)" : "→ сохраняются (нужен флаг --files, чтобы удалить)") . "\n";

echo "\nНЕ трогаем: ведомости ($allMan), пассажиров (" . $count('SELECT COUNT(*) FROM passengers') . "), "
    . "сообщения (" . $count('SELECT COUNT(*) FROM messages') . "), диалоги (" . $count('SELECT COUNT(*) FROM conversations') . ")\n";

if (!$apply) {
    echo "\n---\nЭто DRY-RUN, ничего не изменено.\n";
    echo "Выполнить:            php " . basename(__FILE__) . " apply\n";
    echo "Вместе с файлами:     php " . basename(__FILE__) . " apply --files\n";
    exit(0);
}

echo "\n--- выполняю ---\n";
$db->beginTransaction();
try {
    foreach ($plan as [$label, $table, $sql, $n]) {
        try { $db->exec($sql); echo "  очищено: $label\n"; }
        catch (Throwable $e) { echo "  пропущено ($label): " . substr($e->getMessage(), 0, 60) . "\n"; }
    }
    $db->exec("UPDATE passengers SET our_price = NULL, attendance = 'unknown', refund_status = 'none',
        agent_contract_id = NULL, finance_comment = ''");
    echo "  сброшены фин-поля пассажиров\n";
    try {
        $db->exec("UPDATE manifests SET in_reporting = 0, reporting_status = 'draft', reporting_note = NULL, other_costs = 0");
        echo "  рейсы отвязаны от отчётности\n";
    } catch (Throwable $e) {
        $db->exec("UPDATE manifests SET reporting_status = 'draft', reporting_note = NULL");
        echo "  рейсы отвязаны (без in_reporting — schema21 не применена)\n";
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    echo "  ❌ ОШИБКА, откат: " . $e->getMessage() . "\n";
    exit(1);
}

// файлы — вне транзакции: диск не откатывается
if ($withFiles) {
    $dir = reporting_storage_dir();
    $rows = $db->query('SELECT id, storage_name FROM manifest_files')->fetchAll();
    $gone = 0;
    foreach ($rows as $f) {
        $path = realpath($dir . '/' . $f['storage_name']);
        if ($path && str_starts_with($path, realpath($dir) . DIRECTORY_SEPARATOR) && is_file($path)) {
            @unlink($path); $gone++;
        }
    }
    $db->exec('DELETE FROM manifest_files');
    echo "  удалено файлов с диска: $gone, записей: " . count($rows) . "\n";
}

echo "\n✅ Отчётность сброшена. Дальше: завести агентов с алиасами и процентами,\n";
echo "   автовокзалы с процентами, затем добавить рейсы в отчётность по номеру.\n";
