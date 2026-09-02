<?php

require_once PANEL_ROOT . '/app/conversations.php';
csrf_check();
$body = json_decode(file_get_contents('php://input'),true) ?: $_POST;
$action = (string) ($_GET['a'] ?? $body['action'] ?? '');
$userId = is_numeric($_SESSION['panel_user'] ?? null) ? (int) $_SESSION['panel_user'] : 0;

try {
    switch ($action) {
        case 'threads':
            $queue = (string) ($body['queue'] ?? 'open');
            $channel = (string) ($body['channel'] ?? 'all');
            $search = trim((string) ($body['search'] ?? ''));
            $where = [];$args=[];
            $where[] = match ($queue) {
                'new' => "c.status='new'",
                'mine' => "c.status<>'resolved' AND c.assignee_user_id=?",
                'unassigned' => "c.status<>'resolved' AND c.assignee_user_id IS NULL",
                'pending' => "c.status='pending'",
                'delivery_failed' => "c.status<>'resolved' AND EXISTS (SELECT 1 FROM conversation_messages cmf WHERE cmf.conversation_id=c.id AND cmf.direction='out' AND cmf.status='failed')",
                'resolved' => "c.status='resolved'",
                default => "c.status<>'resolved'",
            };
            if ($queue === 'mine') $args[] = $userId ?: -1;
            $channelCountWhere=$where[0];$channelCountArgs=$args;
            if ($channel !== 'all') { $where[]='c.channel=?';$args[]=$channel; }
            if ($search !== '') {
                $where[]='(c.contact_name LIKE ? OR c.contact_phone LIKE ? OR c.last_message_preview LIKE ?)';
                $like='%'.$search.'%'; array_push($args,$like,$like,$like);
            }
            if (!empty($body['cursor'])) {
                $cursor = json_decode(base64_decode((string)$body['cursor'],true) ?: '',true);
                if (is_array($cursor) && !empty($cursor['at']) && !empty($cursor['id'])) {
                    $where[]='(c.last_message_at<? OR (c.last_message_at=? AND c.id<?))';
                    array_push($args,$cursor['at'],$cursor['at'],(int)$cursor['id']);
                }
            }
            $sql = "SELECT c.*,u.name assignee_name FROM conversations c LEFT JOIN users u ON u.id=c.assignee_user_id
                WHERE ".implode(' AND ',$where)." ORDER BY c.last_message_at DESC,c.id DESC LIMIT 61";
            $st=db()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            $hasMore=count($rows)>60;if($hasMore) array_pop($rows);
            $next=null;
            if($hasMore && $rows){$last=end($rows);$next=base64_encode(json_encode(['at'=>$last['last_message_at'],'id'=>(int)$last['id']]));}
            $counts=db()->query("SELECT
                SUM(status<>'resolved') open_count,SUM(status='new') new_count,
                SUM(status<>'resolved' AND assignee_user_id IS NULL) unassigned_count,
                SUM(status='pending') pending_count,SUM(status='resolved') resolved_count,
                SUM(status<>'resolved' AND unread_count>0) unread_count
                FROM conversations")->fetch();
            $counts['delivery_failed_count']=(int)db()->query("SELECT COUNT(DISTINCT c.id) FROM conversations c JOIN conversation_messages cmf ON cmf.conversation_id=c.id WHERE c.status<>'resolved' AND cmf.direction='out' AND cmf.status='failed'")->fetchColumn();
            if($userId>0){$mine=db()->prepare("SELECT COUNT(*) FROM conversations WHERE status<>'resolved' AND assignee_user_id=?");$mine->execute([$userId]);$counts['mine_count']=(int)$mine->fetchColumn();}else{$counts['mine_count']=0;}
            $channelCounts=[];
            $channelSt=db()->prepare("SELECT channel,COUNT(*) c FROM conversations c WHERE $channelCountWhere GROUP BY channel");
            $channelSt->execute($channelCountArgs);
            foreach($channelSt->fetchAll() as $r){$channelCounts[$r['channel']]=(int)$r['c'];}
            json_out(['ok'=>true,'threads'=>$rows,'counts'=>$counts,'channel_counts'=>$channelCounts,'next_cursor'=>$next]);

        case 'messages':
            $conversationId=(int)($body['conversation_id']??0);
            $before=json_decode(base64_decode((string)($body['before_cursor']??''),true)?:'',true);
            $hasBefore=is_array($before)&&!empty($before['at'])&&!empty($before['id']);
            $st=db()->prepare('SELECT id,direction dir,body,created_at ts,status,delivered_at,read_at,channel,media_url media,media_type,author_name
                FROM conversation_messages WHERE conversation_id=?'.($hasBefore?' AND (created_at<? OR (created_at=? AND id<?))':'').' ORDER BY created_at DESC,id DESC LIMIT 81');
            $st->execute($hasBefore?[$conversationId,$before['at'],$before['at'],(int)$before['id']]:[$conversationId]);
            $messages=$st->fetchAll();$hasMore=count($messages)>80;if($hasMore) array_pop($messages);$messages=array_reverse($messages);
            $cv=db()->prepare('SELECT c.*,u.name assignee_name,m.trip_number,m.route,m.departure_at,p.name passenger_name
                FROM conversations c LEFT JOIN users u ON u.id=c.assignee_user_id
                LEFT JOIN manifests m ON m.id=c.manifest_id LEFT JOIN passengers p ON p.id=c.passenger_id WHERE c.id=?');
            $cv->execute([$conversationId]);$conversation=$cv->fetch();
            if(!$conversation) json_out(['ok'=>false,'error'=>'Разговор не найден'],404);
            $mx=db()->prepare("SELECT COUNT(DISTINCT m.recipient) FROM conversation_messages cm JOIN messages m ON m.id=cm.legacy_id WHERE cm.conversation_id=? AND cm.legacy_source='messages'"); $mx->execute([$conversationId]);
            $conversation['mixed_history']=(int)$mx->fetchColumn()>1;
            $events=db()->prepare("SELECT * FROM conversation_events WHERE conversation_id=? AND event_type IN ('note','status','priority','assigned') ORDER BY id DESC LIMIT 30");
            $events->execute([$conversationId]);
            json_out(['ok'=>true,'conversation'=>$conversation,'messages'=>$messages,'events'=>$events->fetchAll(),
                'has_more'=>$hasMore,'before_cursor'=>$hasMore&&$messages?base64_encode(json_encode(['at'=>$messages[0]['ts'],'id'=>(int)$messages[0]['id']])):null]);

        case 'markread':
            $conversationId=(int)($body['conversation_id']??0);
            db()->prepare("UPDATE inbox i JOIN conversation_messages cm ON cm.legacy_source='inbox' AND cm.legacy_id=i.id
                SET i.is_read=1 WHERE cm.conversation_id=?")->execute([$conversationId]);
            db()->prepare("UPDATE conversations SET unread_count=0,status=IF(status='new','open',status) WHERE id=?")->execute([$conversationId]);
            json_out(['ok'=>true]);

        case 'update':
            $conversationId=(int)($body['conversation_id']??0);$field=(string)($body['field']??'');
            $allowed=['status'=>['new','open','pending','resolved'],'priority'=>['normal','high','urgent']];
            if($field==='assignee_user_id'){
                $value=(int)($body['value']??0)?:null;
                if($value!==null){$u=db()->prepare('SELECT id FROM users WHERE id=? AND active=1');$u->execute([$value]);if(!$u->fetchColumn())throw new RuntimeException('Сотрудник не найден.');}
            }
            elseif(isset($allowed[$field])&&in_array($body['value']??'',$allowed[$field],true)){$value=$body['value'];}
            else throw new RuntimeException('Недоступное поле разговора.');
            $oldSt=db()->prepare("SELECT `$field` FROM conversations WHERE id=?");$oldSt->execute([$conversationId]);$old=(string)($oldSt->fetchColumn()?:'');
            db()->prepare("UPDATE conversations SET `$field`=? WHERE id=?")->execute([$value,$conversationId]);
            conversation_event($conversationId,$field==='assignee_user_id'?'assigned':$field,$old,(string)$value);
            json_out(['ok'=>true]);

        case 'note':
            $conversationId=(int)($body['conversation_id']??0);$note=mb_substr(trim((string)($body['body']??'')),0,5000);
            if($note==='') throw new RuntimeException('Заметка пустая.');
            conversation_event($conversationId,'note','','',$note);
            json_out(['ok'=>true]);

        case 'bootstrap':
            $users=db()->query('SELECT id,name FROM users WHERE active=1 ORDER BY name')->fetchAll();
            json_out(['ok'=>true,'users'=>$users,'current_user_id'=>$userId]);
    }
    json_out(['ok'=>false,'error'=>'Неизвестное действие'],404);
} catch(Throwable $e){json_out(['ok'=>false,'error'=>$e->getMessage()],422);}
