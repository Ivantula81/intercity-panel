<?php

// Приёмник webhook Green API: статусы отправленных, входящие, состояние инстанса.
// Доступ по токену: /greenapi-webhook.php?token=...

require dirname(__DIR__) . '/app/bootstrap.php';

function gw_env(string $key): string
{
    foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if ($k === $key) return $v;
    }
    return '';
}

$messenger = (string) ($_GET['messenger'] ?? 'max');
if (!in_array($messenger, ['max','telegram','whatsapp'], true)) { http_response_code(400); die('bad messenger'); }
$tokenKeys = ['max' => 'GREENAPI_WEBHOOK_TOKEN', 'telegram' => 'GREENAPI_TG_WEBHOOK_TOKEN', 'whatsapp' => 'GREENAPI_WA_WEBHOOK_TOKEN'];
$token = gw_env($tokenKeys[$messenger]);
$given = (string) ($_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($token === '' || (!hash_equals($token, $given) && !hash_equals('Bearer ' . $token, $given))) {
    http_response_code(403);
    die('forbidden');
}

$p = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$type = (string) ($p['typeWebhook'] ?? '');

@file_put_contents('/var/log/panel-greenapi.log',
    date('Y-m-d H:i:s') . ' ' . $type . ' ' . substr(json_encode($p, JSON_UNESCAPED_UNICODE), 0, 400) . "\n", FILE_APPEND);

switch ($type) {

    // статус нашего исходящего сообщения
    case 'outgoingMessageStatus':
        $waId = (string) ($p['idMessage'] ?? '');
        $status = strtolower((string) ($p['status'] ?? ''));
        if ($waId !== '') {
            if ($status === 'delivered') {
                db()->prepare('UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE wa_id = ? AND channel=?')->execute([$waId,$messenger]);
            } elseif ($status === 'read') {
                db()->prepare('UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE wa_id = ? AND channel=?')->execute([$waId,$messenger]);
            } elseif (in_array($status, ['failed', 'noaccount', 'notinwhitelist'], true)) {
                db()->prepare("UPDATE messages SET status='failed', error=? WHERE wa_id = ? AND channel=? AND status<>'failed'")->execute(['Green API: ' . $status, $waId,$messenger]);
            }
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_sync_delivery($waId,$messenger);
            } catch (Throwable $e) { /* schema15 ещё может быть не применена */ }
        }
        break;

    // входящее сообщение (ответ пассажира)
    case 'incomingMessageReceived':
        $sd = $p['senderData'] ?? [];
        $chatId = (string) ($sd['chatId'] ?? '');
        if ($chatId === '' || str_contains($chatId, '@g.us')) break;
        // реальный номер — в senderPhoneNumber (для MAX chatId это короткий id), иначе из chatId.
        // senderPhoneNumber у MAX часто приходит как 0/пусто — тогда ключ берём из chatId, иначе все
        // такие контакты сваливаются в общий «+0» (теряется история/контакты). Настоящий телефон — 10+ цифр.
        $realPhone = preg_replace('/\D+/', '', (string) ($sd['senderPhoneNumber'] ?? ''));
        $fromChat = preg_replace('/\D+/', '', explode('@', $chatId)[0]);
        $phone = '+' . (strlen($realPhone) >= 10 ? $realPhone : $fromChat);
        $name = (string) ($sd['senderName'] ?? ($sd['chatName'] ?? ''));
        $md = $p['messageData'] ?? [];
        $mediaUrl = ''; $mediaType = '';
        $file = $md['fileMessageData'] ?? null;
        if (is_array($file)) {
            // фото/документ/видео/голосовое: качаем и кэшируем у себя
            require_once PANEL_ROOT . '/lib/inbox_media.php';
            $mime = (string) ($file['mimeType'] ?? '');
            $fname = (string) ($file['fileName'] ?? '');
            [$mediaUrl, $mediaType] = inbox_save_media((string) ($file['downloadUrl'] ?? ''), $mime, $fname);
            $caption = trim((string) ($file['caption'] ?? ''));
            $body = $caption !== '' ? $caption : inbox_media_label($mediaType ?: $mime, $fname);
        } else {
            $body = $md['textMessageData']['textMessage']
                ?? ($md['extendedTextMessageData']['text'] ?? '');
        }
        if (trim((string) $body) !== '' || $mediaUrl !== '') {
            // chat_id MAX/Telegram — чтобы отвечать в тот же канал, а не угадывать его по телефону.
            $instanceKey = ['max' => 'greenapi', 'telegram' => 'greenapi_tg', 'whatsapp' => 'greenapi_wa'][$messenger];
            db()->prepare('INSERT INTO inbox (instance, phone, name, body, media_url, media_type, chat_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$instanceKey, $phone, $name, mb_substr((string) $body, 0, 2000), $mediaUrl, $mediaType, $chatId]);
            $inboxId = (int) db()->lastInsertId();
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_append_legacy('inbox',$inboxId);
            } catch (Throwable $e) { /* legacy inbox остаётся рабочим до миграции */ }
        }
        // Отписка/подписка по команде «стоп» / «старт» + автоответ.
        if (is_stop_word((string) $body) || is_start_word((string) $body)) {
            $off = is_stop_word((string) $body);
            set_unsubscribed($phone, $off);
            require_once PANEL_ROOT . '/lib/GreenApiClient.php';
            $ek = [
                'max'      => ['GREENAPI_URL', 'GREENAPI_ID', 'GREENAPI_TOKEN'],
                'telegram' => ['GREENAPI_TG_URL', 'GREENAPI_TG_ID', 'GREENAPI_TG_TOKEN'],
                'whatsapp' => ['GREENAPI_WA_URL', 'GREENAPI_WA_ID', 'GREENAPI_WA_TOKEN'],
            ][$messenger];
            $cli = new GreenApiClient(gw_env($ek[0]) ?: gw_env('GREENAPI_URL'), gw_env($ek[1]), gw_env($ek[2]), $messenger);
            $reply = $off
                ? opt('stop_reply', 'Вы отписаны от рассылки уведомлений. Чтобы снова получать сообщения о рейсах — напишите СТАРТ.')
                : opt('start_reply', 'Вы снова подписаны на уведомления о рейсах. Чтобы отписаться — напишите СТОП.');
            $sentReply = $cli->sendText($chatId, $reply);
            // Автоответ должен быть виден оператору в том же диалоге, что и STOP/START.
            if (!empty($sentReply['ok'])) {
                $providerId = (string) ($sentReply['data']['key']['id'] ?? '');
                $st = db()->prepare('INSERT INTO messages (manifest_id,channel,recipient,passenger_name,body,actor,status,sent_at,wa_id) VALUES (0,?,?,?,?,?,"sent",NOW(),?)');
                $st->execute([$messenger, $phone, $name, $reply, 'Автоответ STOP/START', $providerId]);
                $mid = (int) db()->lastInsertId();
                try {
                    require_once PANEL_ROOT . '/app/conversations.php';
                    $account = $messenger === 'telegram' ? 'greenapi_tg' : 'greenapi';
                    $cid = conversation_ensure(['channel'=>$messenger,'account'=>$account,'external_chat_id'=>$chatId,'phone'=>$phone,'name'=>$name]);
                    conversation_append_legacy('messages', $mid, $cid);
                } catch (Throwable $e) { /* входящий чат уже сохранён */ }
            }
        }
        break;

    case 'stateInstanceChanged':
        $state = (string) ($p['stateInstance'] ?? '');
        if ($state !== '') opt_set('wa_conn_' . (['max' => 'greenapi', 'telegram' => 'greenapi_tg', 'whatsapp' => 'greenapi_wa'][$messenger]), json_encode(['state' => $state, 'at' => date('Y-m-d H:i:s')]));
        break;
}

http_response_code(200);
echo 'ok';
