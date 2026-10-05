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

require_once PANEL_ROOT . '/app/incoming_webhooks.php';
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_is_list($payload)) throw new InvalidArgumentException('Invalid payload');
    $event = strtolower(incoming_string($payload['event'] ?? '', 64, true));
    $instance = incoming_string($payload['instance'] ?? '', 64, true);
    $data = $payload['data'] ?? null;
    if (!is_array($data)) throw new InvalidArgumentException('Invalid data');
} catch (Throwable $e) {
    http_response_code(400); exit('invalid event');
}
error_log('evolution_webhook type=' . (in_array($event, ['messages.upsert','messages.update','connection.update'], true) ? $event : 'unsupported'));
try {
    $known = db()->prepare("SELECT 1 FROM wa_accounts WHERE instance=? AND provider='evolution'");
    $known->execute([$instance]);
    if (!$known->fetchColumn()) throw new InvalidArgumentException('Unknown account');
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
        $items = array_is_list($data) ? $data : [$data];
        $incoming = [];
        // Validate the whole batch before any write; completed items remain safe on a later retry.
        foreach ($items as $m) {
            if (!is_array($m)) throw new InvalidArgumentException('Invalid item');
            $normalized = incoming_evolution($m, $instance);
            if ($normalized !== null) $incoming[] = $normalized;
        }
        foreach ($incoming as $message) incoming_store($message);
        break;

    // состояние канала — фиксируем отвал для баннера в панели
    case 'connection.update':
        $state = (string) ($data['state'] ?? '');
        if ($state !== '') {
            opt_set('wa_conn_' . $instance, json_encode(['state' => $state, 'at' => date('Y-m-d H:i:s')]));
        }
        break;
}

} catch (InvalidArgumentException $e) {
    http_response_code(400); exit('invalid event');
} catch (Throwable $e) {
    error_log('evolution_webhook persistence_failed');
    http_response_code(503); header('Retry-After: 30'); exit('temporarily unavailable');
}

http_response_code(200);
echo 'ok';
