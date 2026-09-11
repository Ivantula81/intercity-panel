<?php
// Local-only UI fixture; no credentials, production database or provider access.
if(PHP_SAPI!=='cli-server'||getenv('NOTIFICATION_FIXTURE')!=='1'){http_response_code(404);exit;}
define('PANEL_ROOT',dirname(__DIR__));
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function csrf_token(){return 'fixture';}function current_user_name(){return 'Тестовый диспетчер';}function flash(){return '';}
function opt($k,$d=''){return $d;}function is_admin(){return true;}
function msg_channel_meta($ch){return [strtoupper($ch),'#6151d6'];}
function view($name,$data=[]){extract($data);require PANEL_ROOT.'/app/views/'.$name.'.php';}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/assets/')){$file=PANEL_ROOT.'/public/assets/'.basename($path);if(!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'application/javascript'));readfile($file);exit;}
$manifest=['id'=>1,'trip_number'=>'101','route'=>'Город А → Город Б','departure_at'=>'2026-09-11 13:00:00','file_name'=>'example.csv','bus'=>'Тестовый автобус','drivers'=>'','driver_phone'=>'','extra_info'=>'','confirmed'=>1];
$groups=[];foreach(['Город А','Город В'] as $i=>$station)$groups[]=['route_key'=>'test-'.$i,'station'=>$station,'destination'=>'Город Б','station_id'=>$i+1,'destination_id'=>5,'date'=>'11.09.2026','time'=>$i?'16:00':'13:00','address'=>'Тестовая остановка','map_url'=>'','in_catalog'=>true,'time_warning'=>0,'time_source'=>'schedule','schedule_revision'=>1,'body'=>'Здравствуйте! Рейс {ДАТА}, отправление {ВРЕМЯ}.','recipients'=>[['id'=>$i+1,'name'=>'Тестовый пассажир '.($i+1),'phone'=>'+7000000000'.($i+1),'valid'=>true,'unsubscribed'=>false,'to'=>'Город Б','note'=>'','channels'=>['max'=>true,'telegram'=>null,'whatsapp'=>false,'checked'=>true]]]];
$body=json_decode(file_get_contents('php://input'),true)?:[];
if(($_GET['p']??'')==='api'){
 header('Content-Type: application/json');$a=$_GET['a']??'';$r=['ok'=>true];
 if($a==='groups')$r+=['manifest'=>$manifest,'groups'=>$groups,'templates'=>[['id'=>1,'name'=>'Стандартное','body'=>$groups[0]['body']]],'manifest_template'=>$groups[0]['body'],'block_templates'=>new stdClass(),'channels_active'=>['max'],'channels_state'=>['max'=>'open'],'primary_channel'=>'max','bus_photo'=>'','today'=>[]];
 elseif($a==='channels.states')$r+=['states'=>['max'=>'open'],'primary'=>'max','today'=>[]];
 elseif($a==='notification.list')$r+=['items'=>[$manifest+['summary'=>['state'=>'running','delivered'=>0,'called'=>0,'recipients'=>2,'passengers'=>2]]],'total'=>1,'page'=>1];
 elseif($a==='notification.overview')$r+=['launched'=>true,'summary'=>['state'=>'running','passengers'=>2,'recipients'=>2,'delivered'=>0,'read'=>0,'queued'=>1,'accepted'=>0,'attention'=>1,'called'=>0,'attempts'=>1], 'recipients'=>array_map(fn($g)=>['id'=>$g['recipients'][0]['id'],'name'=>$g['recipients'][0]['name'],'phone'=>$g['recipients'][0]['phone'],'from_stop'=>$g['station'],'to_stop'=>'Город Б','state'=>'none','covered'=>false,'channel_states'=>[],'call'=>null],$groups)];
 elseif($a==='notification.active')$r+=['items'=>[['id'=>1,'run_id'=>1,'manifest_id'=>1,'trip_number'=>'101','departure_at'=>$manifest['departure_at'],'created_at'=>'2026-09-11 10:15:00','queued'=>1,'sending'=>0,'accepted'=>0,'delivered'=>0,'failed'=>0,'reason'=>'','next_at'=>'']]];
 elseif($a==='channels.queue')$r+=['queues'=>['max'=>['configured'=>true,'provider_count'=>0],'telegram'=>['configured'=>true,'provider_count'=>null]],'local'=>['available'=>true,'deliveries_queued'=>1,'deliveries_sending'=>0],'at'=>date('c')];
 elseif($a==='notification.history')$r+=['runs'=>[],'legacy'=>[],'page'=>1];
 elseif($a==='group.preview')$r+=['preview'=>'Тестовый текст','unknown'=>[]];
 elseif($a==='notification.preview'){$items=[];foreach($body['groups'] as $g)foreach($g['ids'] as $id)$items[]=['passenger_id'=>$id,'name'=>'Тестовый пассажир '.$id,'recipient'=>'+7000000000'.$id,'channel'=>'max','body'=>'Только пример.'];$r+=['plan'=>['manifest'=>$manifest,'items'=>$items,'groups'=>array_map(fn($g)=>$g+['station'=>'Город А','destination'=>'Город Б'],$body['groups']),'channels'=>['max'],'exclusions'=>[],'digest'=>'fixture']];}
 elseif($a==='notification.launch')$r=['ok'=>false,'error'=>'Тестовый сервер: реальная отправка запрещена.'];
 elseif($a==='group.save')$r+=['groups'=>$groups];
 elseif(!in_array($a,['channels.check','manifest.update'],true))$r=['ok'=>false,'error'=>'Недоступно в макете'];
 echo json_encode($r,JSON_UNESCAPED_UNICODE);exit;
}
$page=$_GET['p']??'notifications';$title='Уведомления';$selectedId=isset($_GET['manifest_id'])?1:0;$selected=$selectedId?$manifest:null;$manifests=[$manifest];$buses=[];$drivers=[];$uploadError='';
$content=function()use($page,$selectedId,$selected,$manifests,$buses,$drivers,$uploadError){require PANEL_ROOT.'/app/views/'.($page==='broadcast'?'broadcast':'notifications').'.php';};require PANEL_ROOT.'/app/views/layout.php';
