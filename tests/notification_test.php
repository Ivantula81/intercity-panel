<?php
require dirname(__DIR__).'/lib/NotificationCenter.php';
require dirname(__DIR__).'/app/broadcast_queue.php';
function db(): PDO { global $pdo;return $pdo; }
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE manifests(id INTEGER PRIMARY KEY,trip_number TEXT,route TEXT,departure_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE passengers(id INTEGER PRIMARY KEY,manifest_id INT,name TEXT,phone TEXT,from_stop TEXT,to_stop TEXT,sort INT DEFAULT 0);
 CREATE TABLE messages(id INTEGER PRIMARY KEY,manifest_id INT,recipient TEXT,channel TEXT,wa_id TEXT,status TEXT,body TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,read_at TEXT,delivered_at TEXT);
 CREATE TABLE notification_calls(id INTEGER PRIMARY KEY,manifest_id INT,passenger_id INT,recipient TEXT,created_by INT,actor_name TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE notification_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT UNIQUE,manifest_id INT,purpose TEXT,snapshot_json TEXT,created_by INT,actor_name TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE notification_run_jobs(run_id INT,job_id INT UNIQUE);
 CREATE TABLE broadcast_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,idempotency_key TEXT UNIQUE,kind TEXT,manifest_id INT,payload_json TEXT,status TEXT DEFAULT 'queued',created_by INT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE broadcast_deliveries(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,passenger_id INT,channel TEXT,recipient TEXT,body_hash TEXT,status TEXT,provider_id TEXT DEFAULT '',last_error TEXT DEFAULT '',available_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,read_at TEXT,delivered_at TEXT,UNIQUE(job_id,passenger_id,channel,body_hash));
 INSERT INTO manifests(id,trip_number,route,departure_at) VALUES(1,'101','А → Б','2026-09-11 13:00:00'),(2,'102','В → Г','2026-09-12 13:00:00');
 INSERT INTO passengers(id,manifest_id,name,phone,from_stop,to_stop) VALUES(1,1,'Тест Один','+70000000001','А','Б'),(2,1,'Тест Два','+70000000002','А','Б'),(3,1,'Тест Три','+70000000001','А','Б'),(4,2,'Тест Четыре','+70000000004','В','Г');");
$center=new NotificationCenter($pdo);$checks=0;
function check($condition,$label){global $checks;$checks++;if(!$condition)throw new RuntimeException('FAILED: '.$label);echo "OK $label\n";}
$items=[['passenger_id'=>1,'channel'=>'max','recipient'=>'+70000000001','target'=>'test-1','body'=>'Тестовый текст']];
$prepare=function($req)use($items,$center){$covered=$center->overview(1)['recipients'][0]['covered'];$p=['items'=>$covered?[]:$items];$p['digest']=hash('sha256',json_encode($p));return $p;};
$req=['request_key'=>'test-request-000001','manifest_id'=>1,'digest'=>$prepare([])['digest']];
check($center->overview(1)['summary']['state']==='new','before launch');
$r=$center->launch($req,$prepare,42,'Тест');check($r['deliveries']===1,'one atomic launch');
$r2=$center->launch($req,$prepare,42,'Тест');check($r2['duplicate']&&$r2['run_id']===$r['run_id'],'same key replays existing run');
check($pdo->query('SELECT COUNT(*) FROM broadcast_deliveries')->fetchColumn()==1,'no duplicate delivery');
$s=$center->overview(1)['summary'];check($s['queued']===1&&$s['recipients']===2&&$s['passengers']===3,'queue visible and phones deduplicated');
check($s['state']==='running'&&$s['attention']===1,'uncovered passenger visible while queue active');
check($s['lifecycle']==='running'&&!$s['all_notified'],'active queue remains in progress');
try{$req['request_key']='test-request-000002';$center->launch($req,$prepare,42,'Тест');check(false,'stale preview');}catch(DomainException $e){check(true,'stale concurrent preview rejected');}
check($pdo->query('SELECT COUNT(*) FROM notification_runs')->fetchColumn()==1,'rollback on conflict');
$pdo->exec("UPDATE broadcast_deliveries SET status='accepted',provider_id='test-provider'");check($center->overview(1)['summary']['delivered']===0,'accepted is not delivered');
check($center->overview(1)['summary']['lifecycle']==='running','provider acceptance is still in progress');
$pdo->exec("UPDATE broadcast_deliveries SET status='read',delivered_at=CURRENT_TIMESTAMP,read_at=CURRENT_TIMESTAMP");
$pdo->exec("INSERT INTO messages(manifest_id,recipient,channel,wa_id,status,body,read_at,delivered_at) VALUES(1,'+70000000001','max','test-provider','sent','Тест',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$s=$center->overview(1)['summary'];check($s['attempts']===1&&$s['delivered']===1&&$s['read']===1,'webhook and legacy row not double counted');check($s['state']==='attention','remaining passenger prevents green complete');
check($s['lifecycle']==='completed'&&!$s['all_notified'],'completed processing can have unresolved passengers');
check($center->list(['state'=>'completed'])['total']===1,'completed filter includes partial result');
$pdo->exec("INSERT INTO notification_calls(manifest_id,passenger_id,recipient,actor_name) VALUES(1,2,'+70000000002','Тест')");check($center->overview(1)['summary']['state']==='done','called passenger completes coverage');
check($center->overview(1)['summary']['all_notified'],'full coverage is separate from lifecycle');
check(count($center->history(1)['runs'])===1,'run history');check($center->detail(1)['snapshot']['items'][0]['body']==='Тестовый текст','immutable exact body');
check($center->list(['date'=>'2026-09-12'])['total']===1,'departure date filter');check($center->list(['search'=>'101'])['total']===1,'number search');
// Simulated storage failure happens after run insertion: all writes must roll back.
$pdo->exec("CREATE TRIGGER fail_job BEFORE INSERT ON broadcast_jobs BEGIN SELECT RAISE(ABORT,'test storage failure'); END");
$plan=['items'=>[['passenger_id'=>4,'channel'=>'max','recipient'=>'+70000000004','target'=>'test-4','body'=>'Тест']],'digest'=>'fixed'];
try{$center->launch(['request_key'=>'test-request-000003','manifest_id'=>2,'digest'=>'fixed'],fn()=>$plan,42,'Тест');check(false,'storage failure');}catch(PDOException $e){check(true,'storage failure surfaced');}
check($pdo->query('SELECT COUNT(*) FROM notification_runs')->fetchColumn()==1,'no half run after storage failure');
$pdo->exec('DROP TRIGGER fail_job');
$center->launch(['request_key'=>'test-request-000003','manifest_id'=>2,'digest'=>'fixed'],fn()=>$plan,42,'Тест');
$pdo->exec("UPDATE broadcast_deliveries SET status='queued' WHERE job_id=1");check(count($center->active())===2,'two manifests active together');
check(NotificationCenter::state(['status'=>'failed','read_at'=>'2026-01-01'])==='read','late failed event does not erase read evidence');
echo "Notification center: $checks checks passed\n";
