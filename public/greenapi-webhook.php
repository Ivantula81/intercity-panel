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

require_once PANEL_ROOT . '/app/incoming_webhooks.php';
try {
    $p = json_decode(file_get_contents('php://input') ?: '', true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($p) || array_is_list($p)) throw new InvalidArgumentException('Invalid payload');
    $type = incoming_string($p['typeWebhook'] ?? '', 64, true);
} catch (Throwable $e) {
    http_response_code(400); exit('invalid event');
}
// Log technical classification only; never payloads, tokens or message text.
error_log('greenapi_webhook type=' . (in_array($type, ['incomingMessageReceived','outgoingMessageStatus','stateInstanceChanged'], true) ? $type : 'unsupported'));

try {
switch ($type) {

    // статус нашего исходящего сообщения
    case 'outgoingMessageStatus':
        $waId = (string) ($p['idMessage'] ?? '');
        $status = strtolower((string) ($p['status'] ?? ''));
        if ($waId !== '') {
            // Связываем финальный статус с outbox. Чат/сообщение материализуем
            // только после delivered/read — accepted ещё не означает доставку.
            $dst = db()->prepare('SELECT d.*, j.manifest_id, j.payload_json FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id WHERE d.provider_id=? AND d.channel=? LIMIT 1');
            $dst->execute([$waId, $messenger]);
            $delivery = $dst->fetch();
            if ($delivery && in_array($status, ['delivered','read'], true)) {
                db()->prepare("UPDATE broadcast_deliveries SET status=IF(read_at IS NOT NULL,'read',?), delivered_at=COALESCE(delivered_at,NOW()), read_at=IF(?='read',COALESCE(read_at,NOW()),read_at) WHERE id=?")
                    ->execute([$status, $status, (int)$delivery['id']]);
                $payload = json_decode((string)$delivery['payload_json'], true) ?: [];
                $body = (string)(($payload['bodies'] ?? [])[$delivery['body_hash']] ?? '');
                $exists = db()->prepare('SELECT id FROM messages WHERE wa_id=? AND channel=? LIMIT 1');
                $exists->execute([$waId, $messenger]);
                if (!$exists->fetchColumn() && $body !== '') {
                    $name = '';
                    if (!empty($delivery['passenger_id'])) { $ns=db()->prepare('SELECT name FROM passengers WHERE id=?'); $ns->execute([(int)$delivery['passenger_id']]); $name=(string)($ns->fetchColumn() ?: ''); }
                    $ms = db()->prepare('INSERT INTO messages (manifest_id,channel,recipient,passenger_name,body,actor,status,sent_at,delivered_at,read_at,wa_id) VALUES (?,?,?,?,?,? ,"sent",NOW(),NOW(),IF(?="read",NOW(),NULL),?)');
                    $ms->execute([(int)$delivery['manifest_id'],$messenger,(string)$delivery['recipient'],$name,$body,'Очередь рассылок',$status,$waId]);
                    $mid=(int)db()->lastInsertId();
                    try { require_once PANEL_ROOT.'/app/conversations.php'; $account=$messenger==='telegram'?'greenapi_tg':'greenapi'; $cid=conversation_ensure(['channel'=>$messenger,'account'=>$account,'external_chat_id'=>(string)($payload['targets'][$delivery['body_hash']] ?? $delivery['recipient']),'phone'=>(string)$delivery['recipient'],'name'=>$name,'manifest_id'=>(int)$delivery['manifest_id']]); conversation_append_legacy('messages',$mid,$cid); } catch(Throwable $e) {}
                }
            } elseif ($delivery && empty($delivery['delivered_at']) && empty($delivery['read_at']) && in_array($status, ['failed','noaccount','notinwhitelist'], true)) {
                $description = trim((string)($p['description'] ?? ''));
                $reason = 'Green API: ' . $status . ($description !== '' ? ' — ' . $description : '');
                // Лимит проверки контактов временный: возвращаем доставку
                // в outbox, чтобы worker повторил её позже. Финальные noAccount
                // и notInWhitelist остаются ошибками без создания чата.
                if ($status === 'failed' && preg_match('/limit reached|лимит/i', $description)) {
                    $delay = min(1800, max(300, 300 * max(1, (int)$delivery['attempts'])));
                    $available = date('Y-m-d H:i:s', time() + $delay);
                    db()->prepare("UPDATE broadcast_deliveries SET status='queued',last_error=?,available_at=? WHERE id=?")
                        ->execute([$reason, $available, (int)$delivery['id']]);
                } else {
                    db()->prepare("UPDATE broadcast_deliveries SET status='failed',last_error=? WHERE id=?")
                        ->execute([$reason,(int)$delivery['id']]);
                }
            }
            if ($status === 'delivered') {
                db()->prepare('UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE wa_id = ? AND channel=?')->execute([$waId,$messenger]);
            } elseif ($status === 'read') {
                db()->prepare('UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE wa_id = ? AND channel=?')->execute([$waId,$messenger]);
            } elseif (in_array($status, ['failed', 'noaccount', 'notinwhitelist'], true)) {
                db()->prepare("UPDATE messages SET status='failed', error=? WHERE wa_id = ? AND channel=? AND status<>'failed' AND delivered_at IS NULL AND read_at IS NULL")->execute(['Green API: ' . $status, $waId,$messenger]);
            }
            try {
                require_once PANEL_ROOT . '/app/conversations.php';
                conversation_sync_delivery($waId,$messenger);
            } catch (Throwable $e) { /* schema15 ещё может быть не применена */ }
        }
        break;

    // входящее сообщение (ответ пассажира)
    case 'incomingMessageReceived':
        $idKey = ['max'=>'GREENAPI_ID','telegram'=>'GREENAPI_TG_ID','whatsapp'=>'GREENAPI_WA_ID'][$messenger];
        $incoming = incoming_green($p, $messenger, gw_env($idKey));
        if ($incoming !== null) {
            $eventKey = incoming_store($incoming);
            incoming_reply($eventKey, function (array $row) use ($messenger): array {
                require_once PANEL_ROOT . '/lib/GreenApiClient.php';
                $ek = [
                    'max'=>['GREENAPI_URL','GREENAPI_ID','GREENAPI_TOKEN'],
                    'telegram'=>['GREENAPI_TG_URL','GREENAPI_TG_ID','GREENAPI_TG_TOKEN'],
                    'whatsapp'=>['GREENAPI_WA_URL','GREENAPI_WA_ID','GREENAPI_WA_TOKEN'],
                ][$messenger];
                $cli = new GreenApiClient(gw_env($ek[0]) ?: gw_env('GREENAPI_URL'), gw_env($ek[1]), gw_env($ek[2]), $messenger);
                return $cli->sendText($row['chat_id'], $row['reply_body']);
            });
        }
        break;

    case 'stateInstanceChanged':
        $state = (string) ($p['stateInstance'] ?? '');
        if ($state !== '') opt_set('wa_conn_' . (['max' => 'greenapi', 'telegram' => 'greenapi_tg', 'whatsapp' => 'greenapi_wa'][$messenger]), json_encode(['state' => $state, 'at' => date('Y-m-d H:i:s')]));
        break;
}

} catch (InvalidArgumentException $e) {
    http_response_code(400); exit('invalid event');
} catch (Throwable $e) {
    error_log('greenapi_webhook persistence_failed');
    http_response_code(503); header('Retry-After: 30'); exit('temporarily unavailable');
}

http_response_code(200);
echo 'ok';
