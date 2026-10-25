<?php
require dirname(__DIR__) . '/app/trip_workspace.php';
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE manifests (id INTEGER PRIMARY KEY, trip_number TEXT, route TEXT, departure_at TEXT, bus TEXT, driver_phone TEXT)');
$db->exec('CREATE TABLE passengers (id INTEGER PRIMARY KEY, manifest_id INTEGER)');
$db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY, manifest_id INTEGER, channel TEXT, contact_name TEXT, status TEXT)');
$insert=$db->prepare('INSERT INTO manifests VALUES (?,?,?,?,?,?)');
for($i=1;$i<=65;$i++)$insert->execute([$i,'SAME',$i===1?'Старый рейс 100%_!':'Москва — Тестовый город','2026-10-25 12:00:00',$i===2?'':'Автобус','+70000000000']);
$db->exec("INSERT INTO passengers VALUES (1,1),(2,1); INSERT INTO conversations VALUES (1,1,'max','Тест A','open'),(2,2,'max','Тест B','open'),(3,NULL,'max','Не связан','open')");
$n=0;
function check($condition,$message):void{global $n;if(!$condition)throw new RuntimeException($message);$n++;}
$first=trip_list($db,trip_list_filters([]));check($first['total']===65 && count($first['manifests'])===30,'full count and page');check($first['manifests'][0]['id']===65,'stable order');
$last=trip_list($db,trip_list_filters(['page'=>'100000']));check($last['filters']['page']===3 && count($last['manifests'])===5,'clamp page');
$old=trip_list($db,trip_list_filters(['q'=>'100%_!']));check($old['total']===1 && $old['manifests'][0]['id']===1,'literal LIKE wildcard and old record');check($old['manifests'][0]['cnt']===2,'passenger count is manifest scoped');
check(trip_list($db,trip_list_filters(['q'=>'SAME']))['total']===65,'same external number remains separate');
check(trip_list($db,trip_list_filters(['q'=>"' OR 1=1 --"]))['total']===0,'query is data');
check(trip_list($db,trip_list_filters(['needs'=>'1']))['total']===1,'missing fields filter');
check(trip_list($db,trip_list_filters(['from'=>'2026-10-26']))['total']===0,'date start');
check(trip_list($db,trip_list_filters(['to'=>'2026-10-24']))['total']===0,'date end');
check(trip_list($db,trip_list_filters(['from'=>'2026-10-25','to'=>'2026-10-25']))['total']===65,'whole date');
check(trip_list_filters(['from'=>'2026-02-31','to'=>[],'q'=>[],'page'=>[]])===['q'=>'','from'=>'','to'=>'','needs'=>'','page'=>1],'malformed filters');
check(trip_missing_fields(['departure_at'=>null,'bus'=>' ','driver_phone'=>''])===['время','автобус','телефон водителя'],'missing data labels');
check(count(trip_conversations($db,1))===1 && trip_conversations($db,1)[0]['id']===1,'explicit conversation relationship only');
check(trip_conversations($db,999)===[],'no guessed relationship');
check(str_contains(trip_list_url(trip_list_filters(['q'=>'Москва','needs'=>'1']),2),'needs=1&page=2'),'pager preserves filters');
$db->exec('DROP TABLE manifests');$failed=false;try{trip_list($db,trip_list_filters([]));}catch(PDOException $e){$failed=true;}check($failed,'database failure is not empty success');
echo "Trip workspace: $n assertions OK\n";
