<?php
// Разовая чистка: удалить «мусорные» диалоги, созданные ПРОВАЛЕННЫМИ отправками.
// Артефакт бага: link_outgoing_conv вызывался и при ошибке, поэтому неудачные
// MAX/Telegram («У номера нет MAX») заводили диалог с непроваленным сообщением.
//
// Критерий удаления (строгий): в диалоге НЕТ входящих И НЕТ ни одного успешно
// отправленного — то есть все сообщения failed и переписки не было вообще.
// Живые диалоги (есть входящие или хоть одна успешная отправка) НЕ трогаются.
// Сами сообщения остаются в журнале `messages` — удаляется только привязка к «Чатам».
//
// Запуск:  php clean_failed_convs.php        — dry-run (только показать)
//          php clean_failed_convs.php apply  — применить

require dirname(__DIR__) . '/app/bootstrap.php';

$apply = in_array('apply', $argv, true);
$db = db();

$rows = $db->query("
    SELECT c.id, c.channel, c.contact_phone, c.contact_name,
           COUNT(m.id) total,
           SUM(m.direction = 'in') incoming,
           SUM(m.direction = 'out' AND m.status = 'failed') failed_out,
           SUM(m.direction = 'out' AND (m.status IS NULL OR m.status <> 'failed')) ok_out
      FROM conversations c
      JOIN conversation_messages m ON m.conversation_id = c.id
     GROUP BY c.id
    HAVING incoming = 0 AND ok_out = 0 AND failed_out = total AND total > 0
")->fetchAll();

echo ($apply ? 'УДАЛЯЮ' : '[dry-run] под удаление') . ': ' . count($rows) . " диалогов\n";
foreach (array_slice($rows, 0, 12) as $r) {
    echo "  #{$r['id']} {$r['channel']} {$r['contact_phone']} " . ($r['contact_name'] ?: '') . " — {$r['total']} сообщ., все failed\n";
}
if (count($rows) > 12) echo '  … и ещё ' . (count($rows) - 12) . "\n";

if ($apply) {
    $n = 0;
    foreach ($rows as $r) {
        $db->prepare('DELETE FROM conversation_messages WHERE conversation_id = ?')->execute([$r['id']]);
        $db->prepare('DELETE FROM conversations WHERE id = ?')->execute([$r['id']]);
        $n++;
    }
    echo "---\nГотово. Удалено диалогов: $n. Сообщения остались в журнале `messages`.\n";
} else {
    echo "---\nЭто DRY-RUN, ничего не изменено. Применить:  php " . basename(__FILE__) . " apply\n";
}
