<?php
// Read-only deployment preflight. Does not create or alter tables.
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=db();
$required = [
    'inbox'=>['id','instance','phone','name','body','media_url','media_type','chat_id','is_read','received_at'],
    'contacts'=>['phone','unsubscribed_at'],
    'conversations'=>['id','channel','channel_account','external_chat_id','unread_count'],
    'conversation_messages'=>['id','legacy_source','legacy_id'],
    'messages'=>['id','channel','wa_id','body','sent_at'],
    'incoming_receipts'=>['event_key','channel','inbox_id','reply_state','reply_body','reply_provider_id','created_at'],
];
try {
    foreach($required as $table=>$columns){
        $s=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);
        if(strtolower((string)$s->fetchColumn())!=='innodb')throw new RuntimeException($table.': missing or nontransactional');
        $actual=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        foreach($columns as $column)if(!isset($actual[$column]))throw new RuntimeException($table.': missing required column '.$column);
    }
    $c=$pdo->query('SHOW FULL COLUMNS FROM incoming_receipts')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    foreach(['event_key'=>'char(64)','channel'=>'varchar(24)','reply_state'=>'varchar(16)','reply_body'=>'text','reply_provider_id'=>'varchar(128)'] as $name=>$type){
        if(strtolower($c[$name]['Type'])!==$type)throw new RuntimeException('Receipt column type mismatch: '.$name);
    }
    if($c['event_key']['Collation']!=='ascii_bin'||$c['event_key']['Null']!=='NO'||$c['reply_state']['Default']!=='none')throw new RuntimeException('Receipt identity/state mismatch');
    $primary=$pdo->query("SHOW INDEX FROM incoming_receipts WHERE Key_name='PRIMARY'")->fetchAll();
    if(count($primary)!==1||$primary[0]['Column_name']!=='event_key')throw new RuntimeException('Receipt primary key mismatch');
    echo "Incoming schema: OK (transactional tables, columns, receipt key)\n";
} catch(Throwable $e){fwrite(STDERR,'Incoming schema: FAIL: '.$e->getMessage()."\n");exit(1);}
