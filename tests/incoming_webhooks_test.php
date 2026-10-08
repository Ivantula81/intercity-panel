<?php
require dirname(__DIR__) . '/app/incoming_webhooks.php';
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function invalid(callable $fn): bool { try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; } }
$p = ['instanceData'=>['idInstance'=>'fixture-account'],'idMessage'=>'message-1',
    'senderData'=>['chatId'=>'19995550123@c.us','senderName'=>'Synthetic'],
    'messageData'=>['textMessageData'=>['textMessage'=>'Same text']]];
$a = incoming_green($p,'max','fixture-account');
check($a['phone'] === '+19995550123', 'phone fallback');
$key = incoming_key($a);
check($key === incoming_key(incoming_green($p,'max','fixture-account')), 'stable retry');
foreach (['provider','account','channel','chat_id','message_id'] as $field) {
    $b=$a; $b[$field].='-different'; check(incoming_key($b) !== $key, 'identity scope '.$field);
}
$b=$a; $b['body']='Changed text'; check(incoming_key($b)===$key,'body is not identity');
check(invalid(fn()=>incoming_green($p,'email','fixture-account')),'invalid channel');
check(invalid(fn()=>incoming_green($p,'max','another-account')),'invalid account');
$b=$p;unset($b['idMessage']);check(invalid(fn()=>incoming_green($b,'max','fixture-account')),'id required');
$b=$p;$b['instanceData']='invalid';check(invalid(fn()=>incoming_green($b,'max','fixture-account')),'account object required');
$b=$p;$b['messageData']['textMessageData']='invalid';check(invalid(fn()=>incoming_green($b,'max','fixture-account')),'text object required');
$b=$p;$b['idMessage']=[];check(invalid(fn()=>incoming_green($b,'max','fixture-account')),'scalar id required');
$b=$p;$b['senderData']['chatType']='group';check(incoming_green($b,'max','fixture-account')===null,'MAX groups ignored');
$b=$p;$b['senderData']['senderPhoneNumber']=0;check(incoming_green($b,'max','fixture-account')['phone']===$a['phone'],'zero is not phone');
$e=['key'=>['id'=>'message-1','remoteJid'=>'19995550123@c.us','fromMe'=>false],'message'=>['conversation'=>'Same text']];
check(incoming_key(incoming_evolution($e,'fixture-account'))!==$key,'providers isolated');
$b=$e;$b['key']['fromMe']=true;check(incoming_evolution($b,'fixture-account')===null,'outgoing echo ignored');
$b=$e;unset($b['key']['id']);check(invalid(fn()=>incoming_evolution($b,'fixture-account')),'Evolution id required');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE incoming_receipts(event_key TEXT PRIMARY KEY,channel TEXT,inbox_id INTEGER); CREATE TABLE effects(id INTEGER PRIMARY KEY)');
$write=function(PDO $pdo) use($key):void{$pdo->exec('INSERT INTO effects VALUES(1)');$pdo->prepare('UPDATE incoming_receipts SET inbox_id=1 WHERE event_key=?')->execute([$key]);};
check(incoming_once($pdo,$key,'max',$write),'first receipt');
check(!incoming_once($pdo,$key,'max',fn()=>throw new RuntimeException('must not run')),'committed retry');
check((int)$pdo->query('SELECT COUNT(*) FROM effects')->fetchColumn()===1,'one effect');
$failed=hash('sha256','failure');
try { incoming_once($pdo,$failed,'max',function(PDO $pdo):void{$pdo->exec('INSERT INTO effects VALUES(2)');throw new RuntimeException('fault');});check(false,'failure propagates'); } catch(RuntimeException $e) {check($e->getMessage()==='fault','failure propagates');}
check((int)$pdo->query('SELECT COUNT(*) FROM effects')->fetchColumn()===1,'business rollback');
check((int)$pdo->query('SELECT COUNT(*) FROM incoming_receipts')->fetchColumn()===1,'receipt rollback');
check(incoming_once($pdo,$failed,'max',function(PDO $pdo)use($failed):void{$pdo->exec('INSERT INTO effects VALUES(2)');$pdo->prepare('UPDATE incoming_receipts SET inbox_id=2 WHERE event_key=?')->execute([$failed]);}),'retry after rollback');
$dir=sys_get_temp_dir().'/incoming-media-'.bin2hex(random_bytes(8));mkdir($dir);putenv('INBOX_MEDIA_DIR='.$dir);
file_put_contents($dir.'/'.$key.'.bin','synthetic cached media');
try {
    [$url,$mime]=inbox_save_media('https://does-not-resolve.invalid/file','text/plain','file.txt',$key);
    check($url==='/?p=media&f='.$key.'.bin' && $mime==='text/plain','cached media reused without network');
    check(invalid(fn()=>inbox_save_media('https://invalid.test','','','../escape')),'media key restricted');
} finally {unlink($dir.'/'.$key.'.bin');rmdir($dir);putenv('INBOX_MEDIA_DIR');}
echo "Incoming normalization/transaction tests: $checks passed\n";
