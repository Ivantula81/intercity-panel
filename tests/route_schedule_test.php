<?php
require_once dirname(__DIR__) . '/lib/RouteScheduleStore.php';
require_once dirname(__DIR__) . '/lib/GdsRace.php';
require_once dirname(__DIR__) . '/lib/ManifestParser.php';

$checks = 0;
function checkSchedule(bool $ok, string $label): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($label);
}
function rejectsSchedule(callable $fn, string $type, string $label): void {
    try { $fn(); } catch (Throwable $e) { checkSchedule($e instanceof $type, $label . ': ' . get_class($e)); return; }
    checkSchedule(false, $label);
}
$dsn = getenv('SCHEDULE_TEST_DSN') ?: 'sqlite::memory:';
if ($dsn !== 'sqlite::memory:' && !preg_match('/^mysql:dbname=panel_schedule_test_[a-z0-9_]+$/D', $dsn)) throw new RuntimeException('Only an isolated test database is allowed.');
$pdo = new PDO($dsn, $dsn === 'sqlite::memory:' ? null : 'root', null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Exercise the production SQL operations against a disposable database, without external services.
$schema = file_get_contents(dirname(__DIR__) . '/schema28.sql');
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $schema = str_replace('BIGINT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
    $schema = str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', '', $schema);
}
$pdo->exec($schema); $pdo->exec($schema);
$pdo->exec('CREATE TABLE manifest_groups (manifest_id INT,station TEXT,station_id INT,destination TEXT,boarding_date TEXT,boarding_time TEXT)');
$pdo->exec('CREATE TABLE passengers (manifest_id INT,from_stop TEXT,from_id INT)');
$store = new RouteScheduleStore($pdo);
checkSchedule($store->available(), 'schema available');
$route = 'Евпатория ж/д - Москва (Саларьево)';
$m = ['id'=>1, 'route'=>$route, 'departure_at'=>'2026-09-11 13:00:00', 'trip_number'=>'337'];
$seed = [
    [23,'Евпатория ж/д','13:00',0], [27,'Саки АЗС Атан','13:40',0],
    [2,'Симферополь ж/д (парковка Пуд)','14:35',0], [103,'Грушевка (Судак)','13:00',0],
    [25,'Феодосия (ост.пансионат Украина)','16:00',0], [63,'Севастополь (сады)','13:15',0],
    [32,'Краснодар ТЦ "Бауцентр"','01:00',1], [6,'Тула "Автовокзал"','15:10',1],
];
$stops = [];
foreach ($seed as [$id,$name,$time,$day]) {
    $s = ['station_id'=>$id, 'station'=>$name, 'time'=>$time, 'day'=>$day, 'terminal'=>false]; $stops[] = $s;
    $date = RouteSchedule::absolute($s,'2026-09-11')['date'];
    $pdo->prepare('INSERT INTO manifest_groups VALUES (?,?,?,?,?,?)')->execute([1,$name,$id,'',$date,$time]);
    $pdo->prepare('INSERT INTO passengers VALUES (?,?,?)')->execute([1,$name,$id]);
}
$key = RouteSchedule::key($route,'13:00');
checkSchedule($key === RouteSchedule::key(' ЕВПАТОРИЯ ж/д — Москва (Саларьево) ','13:00'), 'normalization');
checkSchedule($key !== RouteSchedule::key($route,'14:00'), 'separate departures');
checkSchedule($key !== RouteSchedule::key('Москва (Саларьево) - Евпатория ж/д','13:00'), 'separate direction');
checkSchedule(RouteSchedule::absolute(['time'=>'01:00','day'=>1],'2026-12-31')['date'] === '01.01.2027', 'year boundary');
checkSchedule(RouteSchedule::absolute(['time'=>'00:10','day'=>2],'2028-02-28')['date'] === '01.03.2028', 'leap day');
checkSchedule(RouteSchedule::relative('2026-10-01T01:00:00','2026-09-30')['day'] === 1, 'month boundary');
foreach (['24:00','12:60','1:00',''] as $bad) rejectsSchedule(fn()=>RouteSchedule::time($bad), InvalidArgumentException::class, 'bad time');
rejectsSchedule(fn()=>RouteSchedule::date('2026-02-30'), InvalidArgumentException::class, 'invalid date');
rejectsSchedule(fn()=>RouteSchedule::validate([array_replace($stops[0],['day'=>-1])]), InvalidArgumentException::class, 'negative day');
rejectsSchedule(fn()=>RouteSchedule::validate([$stops[0],$stops[0]]), InvalidArgumentException::class, 'duplicate stop');
checkSchedule(RouteSchedule::match([$stops[0]], ['station'=>$stops[0]['station'],'station_id'=>999]) === null, 'different IDs never match');
$alias = array_replace($stops[0], ['aliases'=>['id:999']]);
checkSchedule(RouteSchedule::match([$alias], ['station'=>'alias','station_id'=>999]) !== null, 'explicit mapping');
rejectsSchedule(fn()=>RouteSchedule::validate([$alias,array_replace($stops[1], ['station_id'=>999])]), InvalidArgumentException::class, 'duplicate mapping');

$gds = RouteSchedule::fromGds(['stops'=>[
    ['name'=>$stops[6]['station'],'code'=>32,'arrival'=>'2026-09-12T00:50:00','dispatch'=>'2026-09-12T01:00:00'],
    ['name'=>$stops[7]['station'],'code'=>6,'arrival'=>'2026-09-12T15:00:00','dispatch'=>'2026-09-12T15:10:00'],
    ['name'=>'Без отправления','code'=>99,'arrival'=>'2026-09-12T16:00:00','dispatch'=>''],
]], '2026-09-11');
checkSchedule($gds[0]['time']==='01:00' && $gds[1]['time']==='15:10', 'dispatch rather than arrival');
checkSchedule($gds[2]['time']==='', 'no arrival fallback');
$race = ['statement_number'=>337,'name'=>$route,'dispatch_at'=>'2026-09-11 13:00:00','uid'=>'1:337:20260911:23:5'];
checkSchedule(GdsRace::selectScheduleRace([$race],$m,'13:00') === $race, 'exact race match');
foreach (['statement_number'=>338,'name'=>'Другой маршрут','dispatch_at'=>'2026-09-11 14:00:00'] as $field=>$v) {
    rejectsSchedule(fn()=>GdsRace::selectScheduleRace([array_replace($race,[$field=>$v])],$m,'13:00'),RuntimeException::class,'wrong GDS race');
}
rejectsSchedule(fn()=>GdsRace::selectScheduleRace([$race,$race],$m,'13:00'),RuntimeException::class,'ambiguous GDS');

$store->initialize($m, 7, '13:00');
$c = $store->context($m);
checkSchedule($c['state']['revision']===1 && count($c['state']['source'])===8 && !$c['schedule'], 'first import');
$store->initialize($m,7,'13:00');
checkSchedule($store->context($m)['state']['revision']===1, 'repeat initialization');
$stops[3]['time']='15:20'; // explicitly synthetic correction, not an assertion about the real timetable
$saved = $store->save($route,'13:00',$stops,0,7,$m,1);
$c = $store->context($m);
checkSchedule($saved['version']===1 && $c['state']['schedule_version']===1, 'save and apply atomically');
checkSchedule($c['state']['source'][3]['time']==='13:00', 'raw source retained');
$groups = [array_replace($stops[3],['destination'=>'A','date'=>'11.09.2026']),array_replace($stops[3],['destination'=>'B','date'=>'11.09.2026'])];
$store->override($m,$groups[0],'12.09.2026','16:05',$c['state']['revision'],7);
$c = $store->context($m);
$overlay = RouteSchedule::overlay($groups,$c['state'],'2026-09-11');
checkSchedule($overlay[0]['time']==='16:05' && $overlay[1]['time']==='16:05' && $overlay[1]['date']==='12.09.2026','whole station override');
$saved2 = $store->save($route,'13:00',array_map(fn($s)=>$s['station_id']===103 ? array_replace($s,['time'=>'15:30']) : $s,$stops),1,8);
checkSchedule(RouteSchedule::match($store->context($m)['state']['applied'],$groups[0])['time']==='15:20','prepared snapshot unchanged');
$store->apply($m,$key,2,$c['state']['revision'],7);
$c = $store->context($m);
checkSchedule(RouteSchedule::overlay($groups,$c['state'],'2026-09-11')[0]['time']==='16:05','apply retains override');
$store->override($m,$groups[0],'','',$c['state']['revision'],7,true);
$c = $store->context($m);
checkSchedule(RouteSchedule::overlay($groups,$c['state'],'2026-09-11')[0]['time']==='15:30','reset uses snapshot');
rejectsSchedule(fn()=>$store->save($route,'13:00',$stops,1,9),ScheduleConflict::class,'stale schedule version');
rejectsSchedule(fn()=>$store->save($route,'13:00',$stops,0,9),ScheduleConflict::class,'parallel create');
rejectsSchedule(fn()=>$store->override($m,$groups[0],'11.09.2026','19:00',1,9),ScheduleConflict::class,'stale override');
rejectsSchedule(fn()=>$store->save($route,'13:00',$stops,2,9,$m,1),ScheduleConflict::class,'failed apply rolls back save');
checkSchedule($store->schedule($key)['version']===2,'no partial save');
rejectsSchedule(fn()=>$store->save($route,'13:00',array_replace($stops,[0=>array_replace($stops[0],['time'=>'14:00'])]),2,9),InvalidArgumentException::class,'origin must match key');

$m2 = array_replace($m,['id'=>2,'departure_at'=>'2026-10-01 13:00:00','trip_number'=>'338']);
$store->initialize($m2,7,'13:00');
$c2 = $store->context($m2);
checkSchedule($c2['schedule_key']===$key && $c2['state']['schedule_version']===2 && !$c2['state']['overrides'],'next departure uses latest without overrides');
checkSchedule(RouteSchedule::overlay([$stops[6]],$c2['state'],'2026-10-01')[0]['date']==='02.10.2026','new departure dates');
$pdo->prepare('INSERT INTO passengers VALUES (?,?,?)')->execute([2,'Новая остановка',999]);
checkSchedule(RouteSchedule::match($store->context($m2)['state']['applied'],['station'=>'Новая остановка','station_id'=>999])===null,'unknown station not auto configured');
$m3 = array_replace($m2,['id'=>3,'departure_at'=>'2026-10-01 14:00:00']);
$store->initialize($m3,7,'14:00');
checkSchedule($store->context($m3)['state']['schedule_version']===0,'different start not applied');
checkSchedule($store->context(array_replace($m,['departure_at'=>'2026-09-11 13:25:00']))['start_time']==='13:00','actual delay does not change planned key');
checkSchedule((int)$pdo->query('SELECT COUNT(*) FROM route_schedule_events WHERE user_id=7')->fetchColumn()>0,'audit actor');
$before = (int)$pdo->query('SELECT COUNT(*) FROM route_schedule_events')->fetchColumn();
$store->context($m); $store->listing();
checkSchedule((int)$pdo->query('SELECT COUNT(*) FROM route_schedule_events')->fetchColumn()===$before,'reads do not mutate');
$pdo->beginTransaction();
$store->initialize(array_replace($m,['id'=>4]),7,'13:00');
$pdo->rollBack();
checkSchedule($store->context(array_replace($m,['id'=>4]))['state']['revision']===0,'outer import rollback');
$pdo->exec('DROP TABLE route_schedule_events');
rejectsSchedule(fn()=>$store->save($route,'13:00',$stops,2,7),PDOException::class,'audit failure aborts write');
checkSchedule($store->schedule($key)['version']===2,'audit failure rolled back');
checkSchedule(!$store->available(),'missing migration detected');
echo "Route schedule: $checks checks OK\n";
