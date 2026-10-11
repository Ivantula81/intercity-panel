<?php
require __DIR__ . '/notification_correction_test.php';
require dirname(__DIR__) . '/lib/NotificationDispatch.php';
$startChecks = $checks;
$pdo->exec('DELETE FROM contacts');
$testUnsubscribed = false;
$deadline = NotificationDeadline::fromBoarding('11.09.2026', '13:00');
check($deadline === (new DateTimeImmutable('2026-09-11 12:00:00+03:00'))->getTimestamp(), 'deadline is boarding minus one hour in Moscow');
$originalZone = date_default_timezone_get();
date_default_timezone_set('America/New_York');
check(NotificationDeadline::fromBoarding('11.09.2026', '13:00') === $deadline, 'server timezone does not change deadline');
date_default_timezone_set($originalZone);
check(NotificationDeadline::fromBoarding('01.01.2027', '00:30') === (new DateTimeImmutable('2026-12-31 23:30:00+03:00'))->getTimestamp(), 'midnight and year boundary');
foreach ([['31.02.2027','18:00'],['11.09.2026','24:00'],['','']] as [$date,$time]) {
    try { NotificationDeadline::fromBoarding($date,$time); check(false,'bad boarding accepted'); }
    catch (InvalidArgumentException $e) { check(true,'invalid boarding rejected'); }
}
$request['purpose'] = 'change';
$request['expires_at'] = $deadline + 86400; // Client cannot extend the authoritative deadline.
$plan = notification_prepare($request, $deadline - 1);
check(count($plan['items']) === 1 && $plan['items'][0]['expires_at'] === $deadline, 'one second before cutoff allowed; client deadline ignored');
check($plan['groups'][0]['send_before'] === '11.09.2026 12:00', 'preview shows exact cutoff');
foreach ([$deadline, $deadline + 1, $deadline + 86400] as $now) {
    $expired = notification_prepare($request + ['emergency'=>true], $now);
    check(!$expired['items'] && $expired['exclusions'][0]['reason'] === NotificationDeadline::EXPIRED, 'at or after cutoff excluded, including emergency');
}
$request['digest'] = $plan['digest'];
$request['request_key'] = 'deadline-launch-request-001';
$countBefore = (int)$pdo->query('SELECT COUNT(*) FROM notification_runs')->fetchColumn();
try { $center->launch($request, fn($r)=>notification_prepare($r,$deadline), 42,'Тест'); check(false,'expired confirmation accepted'); }
catch (DomainException $e) { check(true,'cutoff between preview and confirmation rejects stale plan'); }
check((int)$pdo->query('SELECT COUNT(*) FROM notification_runs')->fetchColumn() === $countBefore, 'expired launch writes nothing');
$run = $center->launch($request, fn($r)=>notification_prepare($r,$deadline-1),42,'Тест');
$job = $center->rows('SELECT j.* FROM broadcast_jobs j JOIN notification_run_jobs r ON j.id=r.job_id WHERE r.run_id=?',[$run['run_id']])[0];
$payload = json_decode($job['payload_json'],true);
$hash = NotificationDeadline::deliveryHash($plan['items'][0]);
check(NotificationDeadline::forDelivery($payload,$hash) === $deadline, 'deadline survives persisted job JSON');
$replay = $center->launch($request, fn()=>throw new RuntimeException('must not prepare again'),42,'Тест');
check($replay['duplicate'] && $replay['run_id'] === $run['run_id'], 'lost launch reply can replay without duplicate');
$legacy = $payload; unset($legacy['deadlines']);
check(NotificationDeadline::forDelivery($legacy,$hash) === $deadline, 'legacy notification derives cutoff from structured boarding fields');
check(NotificationDeadline::forDelivery([], 'free-form') === null, 'unrelated broadcasts do not acquire a boarding deadline');
try { NotificationDeadline::forDelivery(['notification_run_id'=>1],$hash); check(false,'missing deadline accepted'); }
catch (InvalidArgumentException $e) { check(true,'notification with missing date fails closed'); }
try { NotificationDeadline::forDelivery(['deadlines'=>[$hash=>'invalid']],$hash); check(false,'invalid deadline accepted'); }
catch (InvalidArgumentException $e) { check(true,'corrupt persisted deadline fails closed'); }

$g = build_groups([])[0];
$testGroups = [$g, array_replace($g,['route_key'=>'later-stop','station'=>'В','time'=>'15:00','recipients'=>[['id'=>2]]])];
$mixed = $request;
$mixed['groups'][] = array_replace($request['groups'][0],['key'=>'later-stop','ids'=>[2],'time'=>'15:00']);
$p = notification_prepare($mixed,$deadline);
check(count($p['items']) === 1 && $p['items'][0]['passenger_id'] === 2 && $p['items'][0]['expires_at'] === $deadline+7200, 'separate stops expire independently');
unset($testGroups);

class DeadlineFakeProvider {
    public int $stateCalls=0, $sendCalls=0;
    public bool $configured=true, $online=false;
    public $duringState=null;
    public array $result=['ok'=>true,'data'=>['key'=>['id'=>'fake-provider-id']]];
    public function isConfigured(){return $this->configured;}
    public function connectionState(){ $this->stateCalls++; if($this->duringState)($this->duringState)(); return ['ok'=>$this->online,'state'=>$this->online?'open':'error']; }
    public function sendText($target,$text){ $this->sendCalls++; return $this->result; }
}
$provider = new DeadlineFakeProvider();
$now=$deadline-600;$clock=function()use(&$now){return $now;};
for($i=0;$i<5;$i++){
    $r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
    check($r['status']==='queued' && $r['retry_seconds']===300 && $provider->sendCalls===0,'outage waits without sending or consuming send attempts');
}
$provider->online=true;
$r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
check($r['status']==='accepted' && $provider->sendCalls===1,'recovery before cutoff hands message to provider once');
$now=$deadline;$provider->stateCalls=0;
$r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
check($r['status']==='skipped' && $provider->stateCalls===0 && $provider->sendCalls===1,'at cutoff neither state nor send called');
$now=$deadline-1;$provider->duringState=function()use(&$now,$deadline){$now=$deadline;};
$r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
check($r['status']==='skipped' && $provider->sendCalls===1,'deadline crossed during health check prevents send');
$provider->duringState=null;$now=$deadline-1;$provider->online=false;
$r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
check($r['retry_seconds']===1,'retry never sleeps past cutoff');
$provider->online=true;$provider->result=['ok'=>false,'error'=>'timed out after provider acceptance'];
$r=NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock);
check($r['status']==='failed' && !isset($r['retry_seconds']),'uncertain POST response does not automatically retry');
$provider->result=['ok'=>true,'data'=>[]];
check(NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock)['status']==='failed','missing provider ID is not acceptance');
$provider->configured=false;
check(NotificationDispatch::attempt($provider,'fake-target','Тест',$deadline,$clock)['status']==='failed','missing credentials surfaced without sending');

// Exercise the actual persistent claim/finish methods; no network, only in-memory fixtures.
$pdo->exec("UPDATE broadcast_deliveries SET status='accepted'; UPDATE broadcast_jobs SET status='completed'");
$qItem=$plan['items'][0];
$q=BroadcastQueue::enqueue('campaign',1,['test'=>'deadline-queue']);
BroadcastQueue::materializeDeliveries($q['id'],[$qItem]);
$pdo->exec("UPDATE broadcast_jobs SET status='paused' WHERE id=".$q['id']);
check(BroadcastQueue::claimDelivery('test')===null,'paused job is not claimed');
$pdo->exec("UPDATE broadcast_jobs SET status='queued' WHERE id=".$q['id']);
$d=BroadcastQueue::claimDelivery('test');
check($d!==null && $d['attempts']===1,'queued delivery claimed once');
$pdo->exec("UPDATE broadcast_deliveries SET created_at='2000-01-01' WHERE id=".$d['id']);
check(BroadcastQueue::claimDelivery('second-worker')===null,'old sending row is never automatically resent');
BroadcastQueue::finishDelivery($d['id'],'failed','','Ожидает восстановления',1);
check($pdo->query('SELECT status FROM broadcast_deliveries WHERE id='.$d['id'])->fetchColumn()==='queued','outage retained in local queue');
$pdo->exec("UPDATE broadcast_deliveries SET available_at='2000-01-01' WHERE id=".$d['id']);
$d=BroadcastQueue::claimDelivery('test');
BroadcastQueue::finishDelivery($d['id'],'skipped','',NotificationDeadline::EXPIRED);
check(BroadcastQueue::claimDelivery('test')===null,'expired skipped delivery never re-enters queue');
check($pdo->query('SELECT last_error FROM broadcast_deliveries WHERE id='.$d['id'])->fetchColumn()===NotificationDeadline::EXPIRED,'expiry reason retained for history');
echo 'Deadline/offline queue: '.($checks-$startChecks)." checks passed\n";
