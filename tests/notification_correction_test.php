<?php
// Real preparation + atomic queue, isolated SQLite; providers are unavailable.
require __DIR__.'/notification_test.php';
require dirname(__DIR__).'/app/notification_service.php';
class Channels { public static function ready($ch){return $ch==='max';} }
class MessageTemplate { public static function unknownVars($text,$vars){return [];} }
function get_manifest($id){global $testBus;return ['id'=>$id,'trip_number'=>'101','departure_at'=>'2026-09-11 13:00:00','route'=>'А → Б','bus'=>$testBus,'driver_phone'=>'','extra_info'=>''];}
function build_groups($m){return [['route_key'=>'test-route','station'=>'А','destination'=>'Б','date'=>'11.09.2026','time'=>'13:00','schedule_revision'=>1,'body'=>'Автобус: {bus}','recipients'=>[['id'=>1]]]];}
function opt($key,$default=''){return $default;}
function send_opts($m,$r){return [];}
function valid_phone($p){return true;}
function is_unsubscribed($p){global $testUnsubscribed;return $testUnsubscribed;}
function group_vars($m,$p,$g,$o){return ['bus'=>$m['bus']];}
function render_group_message($t,$v,$extra){return str_replace('{bus}',$v['bus'],$t);}
function resolve_send_target($ch,$phone,$probe){return ['ok'=>true,'target'=>'synthetic-target'];}
$pdo->exec('CREATE TABLE contacts(phone TEXT,has_max INT,has_telegram INT,has_whatsapp INT); CREATE TABLE templates(id INT,sort INT,body TEXT)');
$pdo->exec("INSERT INTO templates VALUES(1,1,'Автобус: {bus}')");
$testBus='Исправленный автобус';$testUnsubscribed=false;
$request=['manifest_id'=>1,'purpose'=>'reminder','channels'=>['max'],'groups'=>[['key'=>'test-route','ids'=>[1],'date'=>'11.09.2026','time'=>'13:00','revision'=>1,'text'=>'Автобус: {bus}']]];
check(notification_prepare($request)['items']===[],'ordinary send still excludes covered passenger');
$request['purpose']='change';
$plan=notification_prepare($request);
check(count($plan['items'])===1,'correction includes previously queued passenger');
check(str_contains($plan['items'][0]['body'],'Уточнение по вашему рейсу.')&&str_contains($plan['items'][0]['body'],$testBus),'correction uses saved bus and introduction');
$request+=['request_key'=>'correction-request-001','digest'=>$plan['digest']];
$before=(int)$pdo->query('SELECT COUNT(*) FROM broadcast_deliveries')->fetchColumn();
$run=$center->launch($request,'notification_prepare',42,'Тест');
$replay=$center->launch($request,'notification_prepare',42,'Тест');
check($run['run_id']===$replay['run_id']&&$replay['duplicate'],'retry correction returns same run');
check((int)$pdo->query('SELECT COUNT(*) FROM broadcast_deliveries')->fetchColumn()===$before+1,'correction retry creates no duplicate delivery');
check($center->detail($run['run_id'])['purpose']==='change','correction recorded separately in history');
$request['request_key']='correction-request-002';$testBus='Другой автобус';
try{$center->launch($request,'notification_prepare',42,'Тест');check(false,'stale correction');}catch(DomainException $e){check(true,'bus change after preview rejects launch');}
$testUnsubscribed=true;check(notification_prepare($request)['items']===[],'correction does not bypass unsubscribe');
$testUnsubscribed=false;$pdo->exec("INSERT INTO contacts VALUES('+70000000001',0,NULL,NULL)");
check(notification_prepare($request)['items']===[],'correction does not bypass missing account');
echo "Correction checks: OK\n";
