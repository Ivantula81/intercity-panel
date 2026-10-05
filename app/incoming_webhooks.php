<?php

declare(strict_types=1);

require_once __DIR__ . '/conversations.php';
require_once dirname(__DIR__) . '/lib/inbox_media.php';

function incoming_string(mixed $value, int $max, bool $required = false): string
{
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid field');
    $value = (string) $value;
    if (($required && trim($value) === '') || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Invalid field length');
    }
    return $value;
}

function incoming_key(array $event): string
{
    // Versioned, length-unambiguous identity. Text and delivery timestamp are not identity.
    return hash('sha256', json_encode(['v1', $event['provider'], $event['account'],
        $event['channel'], $event['chat_id'], $event['message_id']], JSON_THROW_ON_ERROR));
}

function incoming_green(array $p, string $channel, string $account): ?array
{
    $instances = ['max'=>'greenapi', 'telegram'=>'greenapi_tg', 'whatsapp'=>'greenapi_wa'];
    if (!isset($instances[$channel])) throw new InvalidArgumentException('Invalid channel');
    if ($account === '') throw new RuntimeException('Incoming account is not configured');
    $account = incoming_string($account, 128, true);
    if (!is_array($p['instanceData'] ?? null)) throw new InvalidArgumentException('Invalid account');
    $reported = incoming_string($p['instanceData']['idInstance'] ?? '', 128, true);
    if (!hash_equals($account, $reported)) throw new InvalidArgumentException('Account mismatch');
    $id = incoming_string($p['idMessage'] ?? '', 128, true);
    $sd = $p['senderData'] ?? null;
    $md = $p['messageData'] ?? null;
    if (!is_array($sd) || !is_array($md)) throw new InvalidArgumentException('Invalid message');
    $chat = incoming_string($sd['chatId'] ?? '', 64, true);
    if (str_contains($chat, '@g.us') || ($sd['chatType'] ?? '') === 'group') return null;
    $real = preg_replace('/\D+/', '', incoming_string($sd['senderPhoneNumber'] ?? '', 64));
    $fromChat = preg_replace('/\D+/', '', explode('@', $chat)[0]);
    $phone = '+' . (strlen($real) >= 10 ? $real : $fromChat);
    if ($phone === '+' || strlen($phone) > 32) throw new InvalidArgumentException('Invalid sender');
    $file = null;
    if (isset($md['fileMessageData'])) {
        if (!is_array($md['fileMessageData'])) throw new InvalidArgumentException('Invalid media');
        $f = $md['fileMessageData'];
        $file = ['url'=>incoming_string($f['downloadUrl'] ?? '', 8192, true),
            'mime'=>incoming_string($f['mimeType'] ?? '', 60),
            'name'=>incoming_string($f['fileName'] ?? '', 255)];
        $caption = trim(incoming_string($f['caption'] ?? '', 100000));
        $body = $caption !== '' ? $caption : inbox_media_label($file['mime'], $file['name']);
    } else {
        foreach (['textMessageData','extendedTextMessageData'] as $field) {
            if (isset($md[$field]) && !is_array($md[$field])) throw new InvalidArgumentException('Invalid text');
        }
        $body = incoming_string($md['textMessageData']['textMessage'] ?? ($md['extendedTextMessageData']['text'] ?? ''), 100000);
    }
    if (trim($body) === '' && !$file) return null; // Unsupported message type: existing policy.
    return ['provider'=>'greenapi', 'account'=>$account, 'channel'=>$channel,
        'instance'=>$instances[$channel], 'message_id'=>$id, 'chat_id'=>$chat, 'phone'=>$phone,
        'name'=>incoming_string($sd['senderName'] ?? ($sd['chatName'] ?? ''), 255),
        'body'=>$body, 'file'=>$file];
}

function incoming_evolution(array $m, string $instance): ?array
{
    $instance = incoming_string($instance, 64, true);
    $key = $m['key'] ?? null;
    if (!is_array($key)) throw new InvalidArgumentException('Invalid key');
    if (!empty($key['fromMe'])) return null;
    $chat = incoming_string($key['remoteJid'] ?? '', 64, true);
    if (str_contains($chat, '@g.us')) return null;
    $id = incoming_string($key['id'] ?? '', 128, true);
    $msg = $m['message'] ?? null;
    if (!is_array($msg)) throw new InvalidArgumentException('Invalid message');
    if (isset($msg['extendedTextMessage']) && !is_array($msg['extendedTextMessage'])) throw new InvalidArgumentException('Invalid text');
    $body = $msg['conversation'] ?? ($msg['extendedTextMessage']['text'] ?? '');
    $body = incoming_string($body, 100000);
    if ($body === '') $body = isset($msg['imageMessage']) ? '[фото]' : (isset($msg['audioMessage']) ? '[голосовое]' : (isset($msg['pollUpdateMessage']) ? '[ответ на опрос]' : ''));
    if ($body === '') return null;
    $phone = '+' . preg_replace('/\D+/', '', explode('@', $chat)[0]);
    if ($phone === '+' || strlen($phone) > 32) throw new InvalidArgumentException('Invalid sender');
    return ['provider'=>'evolution', 'account'=>$instance, 'channel'=>'whatsapp',
        'instance'=>$instance, 'message_id'=>$id, 'chat_id'=>$chat, 'phone'=>$phone,
        'name'=>incoming_string($m['pushName'] ?? '', 255), 'body'=>$body, 'file'=>null];
}

/** The unique insert serializes simultaneous deliveries in InnoDB; all DB effects commit together. */
function incoming_once(PDO $pdo, string $key, string $channel, callable $save): bool
{
    if ($pdo->inTransaction()) throw new LogicException('Incoming transaction must be independent');
    $pdo->beginTransaction();
    try {
        try {
            $pdo->prepare('INSERT INTO incoming_receipts (event_key,channel) VALUES (?,?)')->execute([$key,$channel]);
        } catch (PDOException $e) {
            // Only this insert can mean a replay. Never suppress a failure inside the business write.
            $pdo->rollBack();
            if ((string)$e->getCode() !== '23000') throw $e;
            $st = $pdo->prepare('SELECT inbox_id FROM incoming_receipts WHERE event_key=?');
            $st->execute([$key]);
            if (!$st->fetchColumn()) throw $e;
            return false;
        }
        $save($pdo);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function incoming_store(array $event, ?callable $saveMedia = null): string
{
    $key = incoming_key($event);
    $saveMedia ??= 'inbox_save_media';
    incoming_once(db(), $key, $event['channel'], function (PDO $pdo) use ($event, $key, $saveMedia): void {
        $mediaUrl = ''; $mediaType = '';
        if ($event['file']) {
            $file = $event['file'];
            [$mediaUrl,$mediaType] = $saveMedia($file['url'],$file['mime'],$file['name'],$key);
            if ($mediaUrl === '') throw new RuntimeException('Media was not persisted');
        }
        $pdo->prepare('INSERT INTO inbox (instance,phone,name,body,media_url,media_type,chat_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$event['instance'],$event['phone'],$event['name'],mb_substr($event['body'],0,2000),$mediaUrl,$mediaType,$event['chat_id']]);
        $inboxId = (int)$pdo->lastInsertId();
        // The conversation is part of acceptance, not a best-effort side effect.
        conversation_append_legacy('inbox', $inboxId);
        $reply = null;
        if ($event['provider'] === 'greenapi' && (is_stop_word($event['body']) || is_start_word($event['body']))) {
            $off = is_stop_word($event['body']);
            $pdo->prepare('INSERT INTO contacts (phone) VALUES (?) ON DUPLICATE KEY UPDATE phone=phone')->execute([$event['phone']]);
            $pdo->prepare('UPDATE contacts SET unsubscribed_at=' . ($off ? 'NOW()' : 'NULL') . ' WHERE phone=?')->execute([$event['phone']]);
            $reply = $off
                ? opt('stop_reply','Вы отписаны от рассылки уведомлений. Чтобы снова получать сообщения о рейсах — напишите СТАРТ.')
                : opt('start_reply','Вы снова подписаны на уведомления о рейсах. Чтобы отписаться — напишите СТОП.');
        }
        $pdo->prepare('UPDATE incoming_receipts SET inbox_id=?,reply_state=?,reply_body=? WHERE event_key=?')
            ->execute([$inboxId,$reply === null ? 'none' : 'pending',$reply,$key]);
    });
    return $key;
}

/** At most one provider attempt, not exactly-once external delivery. Unknown attempts need reconciliation. */
function incoming_reply(string $key, callable $send): void
{
    $pdo = db();
    if ($pdo->inTransaction()) throw new LogicException('Reply claim must commit before sending');
    $st = $pdo->prepare('SELECT r.*,i.phone,i.name,i.chat_id,i.instance FROM incoming_receipts r JOIN inbox i ON i.id=r.inbox_id WHERE r.event_key=?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row || $row['reply_state'] !== 'pending') return;
    // Commit the attempt BEFORE calling the provider. A crash must never cause a blind resend.
    $claim = $pdo->prepare("UPDATE incoming_receipts SET reply_state='unknown' WHERE event_key=? AND reply_state='pending'");
    $claim->execute([$key]);
    if ($claim->rowCount() !== 1) return;
    try {
        $result = $send($row);
        $providerId = (string)($result['data']['key']['id'] ?? '');
        if (empty($result['ok']) || $providerId === '') {
            error_log('incoming_reply_unknown event=' . $key);
            return;
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO messages (manifest_id,channel,recipient,passenger_name,body,actor,status,sent_at,wa_id) VALUES (0,?,?,?,?,?,"sent",NOW(),?)')
            ->execute([$row['channel'],$row['phone'],$row['name'],$row['reply_body'],'Автоответ STOP/START',$providerId]);
        $mid = (int)$pdo->lastInsertId();
        $cid = conversation_ensure(['channel'=>$row['channel'],'account'=>$row['instance'],
            'external_chat_id'=>$row['chat_id'],'phone'=>$row['phone'],'name'=>$row['name']]);
        conversation_append_legacy('messages',$mid,$cid);
        $pdo->prepare("UPDATE incoming_receipts SET reply_state='accepted',reply_provider_id=? WHERE event_key=?")
            ->execute([$providerId,$key]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // No payload, phone, token, exception text or request URL in the log.
        error_log('incoming_reply_unknown event=' . $key);
    }
}
