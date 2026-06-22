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
if (!in_array($messenger, ['max','telegram'], true)) { http_response_code(400); die('bad messenger'); }
$token = gw_env($messenger === 'telegram' ? 'GREENAPI_TG_WEBHOOK_TOKEN' : 'GREENAPI_WEBHOOK_TOKEN');
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
        // реальный номер — в senderPhoneNumber (для MAX chatId это короткий id), иначе из chatId
        $realPhone = preg_replace('/\D+/', '', (string) ($sd['senderPhoneNumber'] ?? ''));
        $phone = '+' . ($realPhone !== '' ? $realPhone : preg_replace('/\D+/', '', explode('@', $chatId)[0]));
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
            $instanceKey = $messenger === 'telegram' ? 'greenapi_tg' : 'greenapi';
            db()->prepare('INSERT INTO inbox (instance, phone, name, body, media_url, media_type, chat_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$instanceKey, $phone, $name, mb_substr((string) $body, 0, 2000), $mediaUrl, $mediaType, $chatId]);
            $inboxId = (int) db()->lastInsertId();
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_append_legacy('inbox',$inboxId);
            } catch (Throwable $e) { /* legacy inbox остаётся рабочим до миграции */ }
        }
        break;

    case 'stateInstanceChanged':
        $state = (string) ($p['stateInstance'] ?? '');
        if ($state !== '') opt_set('wa_conn_' . ($messenger === 'telegram' ? 'greenapi_tg' : 'greenapi'), json_encode(['state' => $state, 'at' => date('Y-m-d H:i:s')]));
        break;
}

http_response_code(200);
echo 'ok';
