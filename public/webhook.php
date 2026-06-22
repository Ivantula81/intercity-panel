<?php

// Приёмник событий Evolution API (статусы доставки, входящие, состояние канала).
// Доступ по секретному токену: /webhook.php?token=...

require dirname(__DIR__) . '/app/bootstrap.php';

function wh_env(string $key): string
{
    foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if ($k === $key) return $v;
    }
    return '';
}

$token = wh_env('WEBHOOK_TOKEN');
if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    die('forbidden');
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$event = strtolower((string) ($payload['event'] ?? ''));
$instance = (string) ($payload['instance'] ?? '');
$data = $payload['data'] ?? [];

// лог последних событий для отладки (обрезанный)
@file_put_contents('/var/log/panel-webhook.log',
    date('Y-m-d H:i:s') . ' ' . $event . ' ' . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 400) . "\n",
    FILE_APPEND);

switch ($event) {

    // статусы наших сообщений: SERVER_ACK → отправлено, DELIVERY_ACK → доставлено, READ → прочитано
    case 'messages.update':
        $items = isset($data[0]) ? $data : [$data];
        foreach ($items as $u) {
            if (!is_array($u)) continue;
            $waId = (string) ($u['keyId'] ?? ($u['key']['id'] ?? ''));
            $status = strtoupper((string) ($u['status'] ?? ''));
            if ($waId === '' || $status === '') continue;
            if ($status === 'DELIVERY_ACK') {
                db()->prepare("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE wa_id = ? AND channel='whatsapp'")->execute([$waId]);
            } elseif ($status === 'READ' || $status === 'PLAYED') {
                db()->prepare("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE wa_id = ? AND channel='whatsapp'")->execute([$waId]);
            }
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_sync_delivery($waId,'whatsapp');
            } catch (Throwable $e) { /* schema15 ещё может быть не применена */ }
        }
        break;

    // входящие сообщения (ответы пассажиров)
    case 'messages.upsert':
        $items = isset($data[0]) ? $data : [$data];
        foreach ($items as $m) {
            if (!is_array($m)) continue;
            $key = $m['key'] ?? [];
            if (!empty($key['fromMe'])) continue; // только входящие
            $jid = (string) ($key['remoteJid'] ?? '');
            if ($jid === '' || str_contains($jid, '@g.us')) continue; // группы пропускаем
            $phone = '+' . preg_replace('/\D+/', '', explode('@', $jid)[0]);
            $msg = $m['message'] ?? [];
            $body = $msg['conversation']
                ?? ($msg['extendedTextMessage']['text'] ?? '')
                ?: (isset($msg['imageMessage']) ? '[фото]' : (isset($msg['audioMessage']) ? '[голосовое]' : (isset($msg['pollUpdateMessage']) ? '[ответ на опрос]' : '')));
            if ($body === '') continue;
            db()->prepare('INSERT INTO inbox (instance, phone, name, body) VALUES (?,?,?,?)')
                ->execute([$instance, $phone, (string) ($m['pushName'] ?? ''), mb_substr((string) $body, 0, 2000)]);
            $inboxId = (int) db()->lastInsertId();
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_append_legacy('inbox',$inboxId);
            } catch (Throwable $e) { /* legacy inbox остаётся рабочим до миграции */ }
        }
        break;

    // состояние канала — фиксируем отвал для баннера в панели
    case 'connection.update':
        $state = (string) ($data['state'] ?? '');
        if ($state !== '') {
            opt_set('wa_conn_' . $instance, json_encode(['state' => $state, 'at' => date('Y-m-d H:i:s')]));
        }
        break;
}

http_response_code(200);
echo 'ok';
