<?php
// Explicit opt-in only. Run with the real bootstrap in an isolated copy, never on production.
$expected = getenv('INCOMING_TEST_DATADIR') ?: '';
if ($expected === '' || getenv('INCOMING_TEST_ACK') !== 'isolated-copy'
    || !is_readable('/proc/self/ns/net') || readlink('/proc/self/ns/net') === readlink('/proc/1/ns/net')) {
    fwrite(STDERR,"Requires isolated network namespace and explicit test datadir\n"); exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
require PANEL_ROOT.'/app/incoming_webhooks.php';
if ((string)db()->query('SELECT @@datadir')->fetchColumn() !== $expected) throw new RuntimeException('Wrong database');
$mode=$argv[1]??'';
if ($mode==='child') {
    $fixture=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    while(!is_file($fixture['barrier'])) usleep(10000);
    if (($fixture['mode']??'store')==='reply') {
        incoming_reply($fixture['key'],function(array $row)use($fixture):array{
            file_put_contents($fixture['calls'],'attempt\n',FILE_APPEND|LOCK_EX);
            usleep(100000);
            return ['ok'=>true,'data'=>['key'=>['id'=>'synthetic-reply-'.substr($fixture['key'],0,20)]]];
        });
    } elseif (($fixture['mode']??'')==='crash') {
        incoming_once(db(),incoming_key($fixture['event']),'max',function(PDO $pdo)use($fixture):void {
            $pdo->prepare('INSERT INTO inbox(instance,phone,name,body) VALUES(?,?,?,?)')->execute(['greenapi',$fixture['event']['phone'],'Synthetic',$fixture['event']['body']]);
            file_put_contents($fixture['entered'],'ready');
            sleep(30);
        });
    } else incoming_store($fixture['event']);
    exit;
}
$checks=[];
function check(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function scalar(string $sql,array $args=[]):mixed{$s=db()->prepare($sql);$s->execute($args);return $s->fetchColumn();}
$tag='qa-incoming-'.bin2hex(random_bytes(8));
$phone='+1999'.random_int(1000000,9999999);
$base=['provider'=>'greenapi','account'=>'synthetic-account','channel'=>'max','instance'=>'greenapi',
    'message_id'=>$tag,'chat_id'=>$phone.'@c.us','phone'=>$phone,'name'=>'Synthetic incoming test','body'=>$tag,'file'=>null];
function event(string $suffix,string $body=''):array{global $base;return array_replace($base,['message_id'=>$base['message_id'].'-'.$suffix,'body'=>$body?:$base['body'].'-'.$suffix]);}
function countInbox():int{global $phone;return (int)scalar('SELECT COUNT(*) FROM inbox WHERE phone=?',[$phone]);}
function receipt(string $key):array{$s=db()->prepare('SELECT * FROM incoming_receipts WHERE event_key=?');$s->execute([$key]);return $s->fetch()?:[];}
function concurrent(array $fixture,int $workers=6):void {
    $dir=sys_get_temp_dir().'/incoming-parallel-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $fixture['barrier']=$dir.'/go';$fixture['calls']=$dir.'/calls';file_put_contents($dir.'/fixture.json',json_encode($fixture));$children=[];
    try {
        for($i=0;$i<$workers;$i++){
            $p=proc_open([PHP_BINARY,__FILE__,'child',$dir.'/fixture.json'],[0=>['pipe','r'],1=>['file',$dir.'/out'.$i,'w'],2=>['file',$dir.'/err'.$i,'w']],$pipes);
            if(!is_resource($p))throw new RuntimeException('Cannot start worker');fclose($pipes[0]);$children[]=$p;
        }
        file_put_contents($fixture['barrier'],'go');
        foreach($children as $i=>$p) { $code=proc_close($p);$children[$i]=null;check($code===0,'parallel child '.$i.' '.$fixture['mode']); }
        if($fixture['mode']==='reply')check(file_get_contents($fixture['calls'])==='attempt\n','one concurrent external attempt');
    } finally {foreach($children as $p)if(is_resource($p)){proc_terminate($p,9);proc_close($p);}foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
}

$initial=countInbox();$key=incoming_store($base);check(countInbox()===$initial+1,'first message saved');
$changed=$base;$changed['body']='Changed payload on replay';incoming_store($changed);
check(countInbox()===$initial+1,'repeat does not insert');
check((int)scalar('SELECT COUNT(*) FROM conversation_messages WHERE legacy_source="inbox" AND legacy_id=?',[receipt($key)['inbox_id']])===1,'one conversation message');
check((int)scalar('SELECT unread_count FROM conversations WHERE channel="max" AND channel_account="greenapi" AND external_chat_id=?',[$base['chat_id']])===1,'one unread increment');
$different=event('same-text',$base['body']);incoming_store($different);check(countInbox()===$initial+2,'distinct id with same text saved');
foreach(['account'=>'different-account','channel'=>'telegram','provider'=>'evolution'] as $field=>$value){$e=$base;$e[$field]=$value;if($field==='channel')$e['instance']='greenapi_tg';incoming_store($e);}
check(countInbox()===$initial+5,'provider account channel independent');
$concurrent=event('parallel');concurrent(['mode'=>'store','event'=>$concurrent]);
check((int)scalar('SELECT COUNT(*) FROM inbox WHERE phone=? AND body=?',[$phone,$concurrent['body']])===1,'parallel replay stored once');

// Conversation failure after the legacy INSERT must roll back both rows and receipt.
$failed=event('fail-conversation');$trigger='qa_incoming_'.bin2hex(random_bytes(4));$before=countInbox();
db()->exec('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON conversation_messages FOR EACH ROW BEGIN IF NEW.body='.db()->quote($failed['body'])." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'; END IF; END");
try {try{incoming_store($failed);check(false,'conversation failure raised');}catch(PDOException $e){check(true,'conversation failure raised');}check(countInbox()===$before && !receipt(incoming_key($failed)),'conversation failure rolls back receipt and inbox');}
finally{db()->exec('DROP TRIGGER '.$trigger);}
incoming_store($failed);check(countInbox()===$before+1,'retry after conversation failure');

$stop=event('stop','СТОП');$stopKey=incoming_store($stop);check((bool)scalar('SELECT unsubscribed_at FROM contacts WHERE phone=?',[$phone]),'STOP persists subscription');
check(receipt($stopKey)['reply_state']==='pending','reply durably pending');
$start=event('start','СТАРТ');$startKey=incoming_store($start);incoming_store($stop);
check(scalar('SELECT unsubscribed_at FROM contacts WHERE phone=?',[$phone])===null,'late STOP replay does not undo START');
concurrent(['mode'=>'reply','key'=>$stopKey]);check(receipt($stopKey)['reply_state']==='accepted','reply accepted once');
$calls=0;incoming_reply($stopKey,function()use(&$calls):array{$calls++;return [];});check($calls===0,'accepted reply never resent');
incoming_reply($startKey,function()use(&$calls):array{$calls++;throw new RuntimeException('synthetic lost response');});
incoming_reply($startKey,function()use(&$calls):array{$calls++;return [];});
check($calls===1 && receipt($startKey)['reply_state']==='unknown','unknown provider result not repeated');

// Subscription failure must not accept the incoming STOP.
$failedStop=event('stop-db-fail','СТОП');$before=countInbox();
db()->exec('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON contacts FOR EACH ROW BEGIN IF NEW.phone='.db()->quote($phone)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic subscription failure'; END IF; END");
try{try{incoming_store($failedStop);check(false,'subscription failure raised');}catch(PDOException $e){check(true,'subscription failure raised');}check(countInbox()===$before&&!receipt(incoming_key($failedStop)),'subscription failure fully rolled back');}
finally{db()->exec('DROP TRIGGER '.$trigger);}
$failedStopKey=incoming_store($failedStop);check(receipt($failedStopKey)['reply_state']==='pending','subscription failure can retry');

// Provider accepted, but storing the outgoing record fails. Never make a second attempt.
$replyBody='synthetic-reply-storage-failure-'.$tag;
db()->prepare('UPDATE incoming_receipts SET reply_body=? WHERE event_key=?')->execute([$replyBody,$failedStopKey]);
db()->exec('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON messages FOR EACH ROW BEGIN IF NEW.body='.db()->quote($replyBody)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic reply failure'; END IF; END");
$calls=0;$send=function()use(&$calls):array{$calls++;return ['ok'=>true,'data'=>['key'=>['id'=>'synthetic-accepted']]];};
try{incoming_reply($failedStopKey,$send);}finally{db()->exec('DROP TRIGGER '.$trigger);}
incoming_reply($failedStopKey,$send);check($calls===1&&receipt($failedStopKey)['reply_state']==='unknown','storage failure after send not repeated');

$media=event('media');$media['file']=['url'=>'https://invalid.test/media','mime'=>'text/plain','name'=>'fixture.txt'];
try{incoming_store($media,fn()=>['','']);check(false,'media failure raised');}catch(RuntimeException $e){check(!receipt(incoming_key($media)),'media failure leaves retry possible');}
$calls=0;$save=function()use(&$calls):array{$calls++;return ['/?p=media&f=synthetic.bin','text/plain'];};
incoming_store($media,$save);incoming_store($media,$save);check($calls===1,'duplicate does not download media again');

// Kill a process with an open DB transaction; retry must be able to save it.
$crash=event('crash');$dir=sys_get_temp_dir().'/incoming-crash-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$fixture=['mode'=>'crash','event'=>$crash,'barrier'=>$dir.'/go','entered'=>$dir.'/entered'];file_put_contents($dir.'/fixture.json',json_encode($fixture));file_put_contents($dir.'/go','go');
$p=proc_open([PHP_BINARY,__FILE__,'child',$dir.'/fixture.json'],[0=>['pipe','r'],1=>['file',$dir.'/out','w'],2=>['file',$dir.'/err','w']],$pipes);fclose($pipes[0]);
try{$end=microtime(true)+10;while(!is_file($dir.'/entered')&&microtime(true)<$end)usleep(10000);check(is_file($dir.'/entered'),'crash fixture reached open transaction');proc_terminate($p,9);proc_close($p);$p=null;incoming_store($crash);check((int)scalar('SELECT COUNT(*) FROM inbox WHERE phone=? AND body=?',[$phone,$crash['body']])===1,'killed transaction retried exactly once');}
finally{if(is_resource($p)){proc_terminate($p,9);proc_close($p);}foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
