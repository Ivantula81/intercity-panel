<?php
// Разовое заполнение contacts.max_chat_id / telegram_chat_id из уже известных диалогов.
//
// Зачем: MAX/Telegram адресуются по chatId. До schema18 панель узнавала его вызовом
// checkAccount перед каждой отправкой и упиралась в лимит мессенджера на просмотр
// контактов (HTTP 469). Но у нас эти chatId уже есть — в conversations.external_chat_id
// от прошлых переписок. Берём оттуда: это бесплатно и лимит не тратит.
//
// Запуск:  php backfill_chat_ids.php        — dry-run (только показать)
//          php backfill_chat_ids.php apply  — применить

require dirname(__DIR__) . '/app/bootstrap.php';

$apply = in_array('apply', $argv, true);
$db = db();

$fill = 0; $conflicts = [];
foreach (['max', 'telegram'] as $ch) {
    // Самый свежий диалог с реальным телефоном и chatId — по одному на номер.
    $rows = $db->prepare("SELECT c.contact_phone AS phone, c.external_chat_id AS chat, k.has_{$ch} AS has, k.{$ch}_chat_id AS stored
        FROM conversations c
        JOIN contacts k ON k.phone = c.contact_phone
       WHERE c.channel = ? AND c.external_chat_id <> ''
         AND c.contact_phone LIKE '+7%' AND LENGTH(c.contact_phone) >= 11
       ORDER BY c.id DESC");
    $rows->execute([$ch]);

    $seen = [];
    $upd = $db->prepare("UPDATE contacts SET {$ch}_chat_id = ?, has_{$ch} = COALESCE(has_{$ch}, 1) WHERE phone = ?");
    $n = 0;
    foreach ($rows->fetchAll() as $r) {
        if (isset($seen[$r['phone']]) || ($r['stored'] ?? '') !== '') continue; // уже есть — не трогаем
        $seen[$r['phone']] = true;
        // Реальный chatId из диалога, но проверка когда-то сказала «канала нет» — противоречие,
        // не переписываем молча, а показываем.
        if (($r['has'] ?? null) !== null && !(int) $r['has']) { $conflicts[] = "{$ch} {$r['phone']} (chatId {$r['chat']}, но has_{$ch}=0)"; continue; }
        if ($apply) $upd->execute([$r['chat'], $r['phone']]);
        $n++;
    }
    echo ($apply ? 'Заполнено' : '[dry-run] заполнить') . " {$ch}_chat_id: {$n}\n";
    $fill += $n;
}

if ($conflicts) {
    echo "\nПротиворечия (chatId есть, но проверка говорила «канала нет») — пропущены, " . count($conflicts) . ":\n";
    foreach (array_slice($conflicts, 0, 8) as $c) echo "  {$c}\n";
    if (count($conflicts) > 8) echo '  … и ещё ' . (count($conflicts) - 8) . "\n";
}

echo "---\n" . ($apply
    ? "Готово. Столько отправок в MAX/Telegram теперь не будут дёргать проверку: {$fill}.\n"
    : "Это DRY-RUN, ничего не изменено. Применить:  php " . basename(__FILE__) . " apply\n");
