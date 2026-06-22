<?php

function conversation_channel_for_inbox(string $instance): string
{
    if ($instance === 'greenapi' || str_contains(mb_strtolower($instance), 'max')) return 'max';
    if (str_contains(mb_strtolower($instance), 'telegram') || str_contains(mb_strtolower($instance), 'tg')) return 'telegram';
    return 'whatsapp';
}

function conversation_find_contact(string $phone): ?int
{
    if ($phone === '') return null;
    $st = db()->prepare('SELECT id FROM contacts WHERE phone=? LIMIT 1');
    $st->execute([$phone]);
    return ($id = $st->fetchColumn()) ? (int) $id : null;
}

function conversation_latest_for_phone_channel(string $phone, string $channel): ?int
{
    $st = db()->prepare('SELECT id FROM conversations WHERE contact_phone=? AND channel=? ORDER BY last_message_at DESC,id DESC LIMIT 1');
    $st->execute([$phone,$channel]);
    return ($id = $st->fetchColumn()) ? (int) $id : null;
}

function conversation_for_incoming(string $channel, string $account, string $external, string $phone, string $name): int
{
    $exact = db()->prepare('SELECT id FROM conversations WHERE channel=? AND channel_account=? AND external_chat_id=?');
    $exact->execute([$channel,$account,$external]);
    if ($id = $exact->fetchColumn()) return (int) $id;

    $legacy = db()->prepare("SELECT id FROM conversations WHERE channel=? AND channel_account='legacy' AND contact_phone=? ORDER BY id DESC LIMIT 1");
    $legacy->execute([$channel,$phone]);
    if ($id = $legacy->fetchColumn()) {
        try {
            db()->prepare('UPDATE conversations SET channel_account=?,external_chat_id=?,contact_name=IF(?<>\'\',?,contact_name) WHERE id=?')
                ->execute([$account,$external,$name,$name,$id]);
            return (int) $id;
        } catch (PDOException $e) {
            $exact->execute([$channel,$account,$external]);
            if ($exactId = $exact->fetchColumn()) return (int) $exactId;
            throw $e;
        }
    }
    return conversation_ensure(['channel'=>$channel,'account'=>$account,'external_chat_id'=>$external,'phone'=>$phone,'name'=>$name]);
}

function conversation_ensure(array $data): int
{
    $channel = (string) ($data['channel'] ?? 'whatsapp');
    $account = (string) ($data['account'] ?? '');
    $external = (string) ($data['external_chat_id'] ?? $data['phone'] ?? '');
    if ($external === '') throw new RuntimeException('У разговора нет external_chat_id.');
    $phone = (string) ($data['phone'] ?? '');
    $name = (string) ($data['name'] ?? '');
    $contactId = conversation_find_contact($phone);
    db()->prepare("INSERT INTO conversations
        (contact_id,contact_phone,contact_name,channel,channel_account,external_chat_id,last_message_at)
        VALUES (?,?,?,?,?,?,NULL)
        ON DUPLICATE KEY UPDATE contact_id=COALESCE(VALUES(contact_id),contact_id),
          contact_phone=IF(VALUES(contact_phone)<>'',VALUES(contact_phone),contact_phone),
          contact_name=IF(VALUES(contact_name)<>'',VALUES(contact_name),contact_name)")
        ->execute([$contactId,$phone,$name,$channel,$account,$external]);
    $st = db()->prepare('SELECT id FROM conversations WHERE channel=? AND channel_account=? AND external_chat_id=?');
    $st->execute([$channel,$account,$external]);
    $conversationId = (int) $st->fetchColumn();
    if ($phone !== '') {
        // Ближайший будущий/последний рейс даёт оператору контекст, но не перезаписывает ручную привязку.
        $trip = db()->prepare("SELECT p.id passenger_id,p.manifest_id FROM passengers p JOIN manifests m ON m.id=p.manifest_id
            WHERE p.phone=? ORDER BY (m.departure_at>=NOW()) DESC,ABS(TIMESTAMPDIFF(SECOND,NOW(),m.departure_at)),m.id DESC LIMIT 1");
        $trip->execute([$phone]);
        if ($link = $trip->fetch()) {
            db()->prepare('UPDATE conversations SET passenger_id=COALESCE(passenger_id,?),manifest_id=COALESCE(manifest_id,?) WHERE id=?')
                ->execute([(int)$link['passenger_id'],(int)$link['manifest_id'],$conversationId]);
        }
    }
    return $conversationId;
}

function conversation_append_legacy(string $source, int $legacyId, ?int $forceConversationId = null): ?int
{
    if (!in_array($source, ['messages','inbox'], true) || $legacyId <= 0) return null;
    if ($source === 'inbox') {
        $st = db()->prepare('SELECT * FROM inbox WHERE id=?');
        $st->execute([$legacyId]);
        $row = $st->fetch();
        if (!$row) return null;
        $channel = conversation_channel_for_inbox((string) $row['instance']);
        $external = (string) ($row['chat_id'] ?: $row['phone']);
        $conversationId = $forceConversationId ?: conversation_for_incoming(
            $channel,(string)$row['instance'],$external,(string)$row['phone'],(string)$row['name']
        );
        $message = [
            $conversationId,'inbox',$legacyId,'in',$channel,'','text',(string)$row['body'],
            (string)$row['media_url'],(string)$row['media_type'],'','',null,null,(string)$row['received_at'],
        ];
        $unread = empty($row['is_read']) ? 1 : 0;
    } else {
        $st = db()->prepare('SELECT * FROM messages WHERE id=?');
        $st->execute([$legacyId]);
        $row = $st->fetch();
        if (!$row) return null;
        $channel = (string) ($row['channel'] ?: 'whatsapp');
        $conversationId = $forceConversationId
            ?: conversation_latest_for_phone_channel((string)$row['recipient'],$channel)
            ?: conversation_ensure([
                'channel'=>$channel,'account'=>'legacy','external_chat_id'=>(string)$row['recipient'],
                'phone'=>(string)$row['recipient'],'name'=>(string)$row['passenger_name'],
            ]);
        $message = [
            $conversationId,'messages',$legacyId,'out',$channel,(string)$row['wa_id'],'text',(string)$row['body'],
            '','',(string)$row['status'],(string)$row['actor'],$row['delivered_at'],$row['read_at'],
            (string)($row['sent_at'] ?: $row['created_at']),
        ];
        $unread = 0;
    }

    $existing = db()->prepare('SELECT id FROM conversation_messages WHERE legacy_source=? AND legacy_id=?');
    $existing->execute([$source,$legacyId]);
    $isNew = !$existing->fetchColumn();
    $unreadForUpdate = $isNew ? $unread : 0;
    db()->prepare("INSERT INTO conversation_messages
        (conversation_id,legacy_source,legacy_id,direction,channel,provider_message_id,message_type,body,
         media_url,media_type,status,author_name,delivered_at,read_at,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
          status=VALUES(status),provider_message_id=VALUES(provider_message_id),
          delivered_at=VALUES(delivered_at),read_at=VALUES(read_at)")->execute($message);
    $preview = mb_substr(trim((string) $row['body']),0,500);
    $ts = $source === 'inbox' ? $row['received_at'] : ($row['sent_at'] ?: $row['created_at']);
    db()->prepare("UPDATE conversations SET
        last_message_at=IF(last_message_at IS NULL OR last_message_at<=?, ?, last_message_at),
        last_message_preview=IF(last_message_at IS NULL OR last_message_at<=?, ?, last_message_preview),
        last_direction=IF(last_message_at IS NULL OR last_message_at<=?, ?, last_direction),
        unread_count=unread_count+?,
        status=IF(?='out' AND status='new','open',IF(? > 0 AND status='resolved','new',status))
        WHERE id=?")->execute([$ts,$ts,$ts,$preview,$ts,$source==='inbox'?'in':'out',$unreadForUpdate,
            $source==='inbox'?'in':'out',$unreadForUpdate,$conversationId]);
    return $conversationId;
}

function conversation_event(int $conversationId, string $type, string $from = '', string $to = '', string $body = ''): void
{
    $uid = is_numeric($_SESSION['panel_user'] ?? null) ? (int) $_SESSION['panel_user'] : null;
    db()->prepare('INSERT INTO conversation_events (conversation_id,event_type,value_from,value_to,body,actor_user_id,actor_name) VALUES (?,?,?,?,?,?,?)')
        ->execute([$conversationId,$type,$from,$to,$body,$uid,current_user_name()]);
}

function conversation_sync_delivery(string $providerMessageId, ?string $channel = null): void
{
    if ($providerMessageId === '') return;
    $st = db()->prepare('SELECT delivered_at,read_at,status FROM messages WHERE wa_id=?'.($channel!==null?' AND channel=?':'').' ORDER BY id DESC LIMIT 1');
    $st->execute($channel!==null?[$providerMessageId,$channel]:[$providerMessageId]);
    if ($row = $st->fetch()) {
        db()->prepare('UPDATE conversation_messages SET delivered_at=?,read_at=?,status=? WHERE provider_message_id=?'.($channel!==null?' AND channel=?':''))
            ->execute($channel!==null?[$row['delivered_at'],$row['read_at'],$row['status'],$providerMessageId,$channel]
                :[$row['delivered_at'],$row['read_at'],$row['status'],$providerMessageId]);
    }
}
