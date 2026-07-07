<?php
// Разовая миграция: слить legacy-дубли MAX/Telegram-диалогов (ключ по телефону)
// в основной диалог (ключ по chatId), который создаётся из входящих.
// Без аргумента — dry-run (только показывает). Применить:  php merge_conv_dups.php apply
//
// Причина: входящее MAX/TG ключуется по (channel, greenapi, chatId), а старые исходящие
// до фикса — по (channel, legacy, телефон). Получались два диалога на одного человека.
// Новые сообщения уже сливаются автоматически (хелпер link_outgoing_conv); этот скрипт
// подчищает исторические дубли.

require dirname(__DIR__) . '/app/bootstrap.php';

$apply = in_array('apply', $argv, true);
$db = db();

$dups = $db->query("SELECT leg.id leg_id, gr.id gr_id, leg.contact_phone ph, leg.channel ch,
    (SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = leg.id) msgs
  FROM conversations leg
  JOIN conversations gr
    ON gr.channel = leg.channel
   AND gr.contact_phone = leg.contact_phone
   AND gr.id <> leg.id
   AND gr.channel_account <> 'legacy'
   AND gr.external_chat_id <> gr.contact_phone
  WHERE leg.channel_account = 'legacy'
    AND leg.external_chat_id = leg.contact_phone
    AND leg.channel IN ('max', 'telegram')
    AND leg.contact_phone NOT IN ('', '+0')")->fetchAll();

// Защита: сливаем только если у legacy-диалога РОВНО один greenapi-близнец.
$byLeg = [];
foreach ($dups as $d) $byLeg[$d['leg_id']][] = $d;

$merged = 0; $skipped = 0;
foreach ($byLeg as $legId => $arr) {
    if (count($arr) !== 1) { echo "ПРОПУСК legacy#$legId: близнецов " . count($arr) . " (нужен ровно 1)\n"; $skipped++; continue; }
    $d = $arr[0];
    echo ($apply ? 'СЛИВАЮ ' : '[dry] ') . "legacy#{$d['leg_id']} ({$d['msgs']} сообщ.) -> #{$d['gr_id']}  ({$d['ch']} {$d['ph']})\n";
    if ($apply) {
        $db->prepare("UPDATE conversation_messages SET conversation_id = ? WHERE conversation_id = ?")->execute([$d['gr_id'], $d['leg_id']]);
        $db->prepare("DELETE FROM conversations WHERE id = ?")->execute([$d['leg_id']]);
        $merged++;
    }
}

echo "---\n";
echo $apply
    ? "Готово. Слито дублей: $merged. Пропущено: $skipped.\n"
    : "Это DRY-RUN (ничего не изменено). Чтобы применить:  php " . basename(__FILE__) . " apply\n";
