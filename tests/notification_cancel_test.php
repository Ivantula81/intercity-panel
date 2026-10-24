<?php
require __DIR__.'/notification_deadline_test.php';
$cancelChecks=$checks;
// Fresh synthetic queue; never contacts an external service.
$pdo->exec('DELETE FROM notification_calls; DELETE FROM messages; DELETE FROM broadcast_deliveries; DELETE FROM notification_run_jobs; DELETE FROM notification_runs; DELETE FROM broadcast_jobs');
function cancelJob(int $count=1): array {
    $j=BroadcastQueue::enqueue('campaign',1,['fixture'=>bin2hex(random_bytes(8))]);
    $items=[];
    for($i=1;$i<=$count;$i++)$items[]=['passenger_id'=>$i,'channel'=>'max','recipient'=>'+7000000000'.$i,'target'=>'synthetic-'.$i,'body'=>'Original bus'];
    BroadcastQueue::materializeDeliveries($j['id'],$items);
    return $j;
}
function cancelStatus(string $table,int $id): string {
    return (string)db()->query("SELECT status FROM $table WHERE id=$id")->fetchColumn();
}
$j=cancelJob(3);$other=cancelJob();
$d=BroadcastQueue::claimDelivery('first');
BroadcastQueue::finishDelivery($d['id'],'accepted','synthetic-accepted');
$flying=BroadcastQueue::claimDelivery('second');
$r=BroadcastQueue::cancel([$j['id']]);
check($r===['stopped'=>1,'sending'=>1,'transferred'=>1,'failed'=>0],'stop counts distinguish queued, in-flight and provider acceptance');
check(cancelStatus('broadcast_jobs',$j['id'])==='cancelled','job remains durably cancelled');
check(cancelStatus('broadcast_jobs',$other['id'])==='queued','unrelated job unaffected');
check(BroadcastQueue::materializeDeliveries($j['id'],[['channel'=>'max','recipient'=>'synthetic-new','body'=>'Late materialization']])===0,'cancelled job cannot acquire late deliveries');
check(BroadcastQueue::cancel([$j['id']])===$r,'double click and lost reply are idempotent');
check(!BroadcastQueue::dispatchAllowed($flying['id']),'claimed request cannot start after cancellation');
$provider=new DeadlineFakeProvider();$provider->online=true;
$result=NotificationDispatch::attempt($provider,'synthetic','Original bus',null,null,fn()=>BroadcastQueue::dispatchAllowed($flying['id']));
check($result['status']==='skipped'&&$provider->stateCalls===0&&$provider->sendCalls===0,'already stopped request never contacts provider');
BroadcastQueue::finishDelivery($flying['id'],'skipped','',$result['error']);
check(cancelStatus('broadcast_jobs',$j['id'])==='cancelled','worker finish cannot reopen cancelled parent');
check($center->overview(1)['recipients'][0]['covered'],'accepted passenger remains covered');
check(!$center->overview(1)['recipients'][1]['covered'],'stopped passenger eligible for corrected normal send');
check(NotificationCenter::state(['status'=>'skipped','last_error'=>BroadcastQueue::STOPPED])==='cancelled','stop reason readable in outcome');
check(count(array_filter($center->active(),fn($x)=>(int)$x['id']===$j['id']&&$x['status']==='cancelled'&&(int)$x['stopped']===2))===1,'cancelled result survives page reload');
// Stop during a slow channel health request.
BroadcastQueue::cancel([$other['id']]);$j=cancelJob();$d=BroadcastQueue::claimDelivery('health');
$provider=new DeadlineFakeProvider();$provider->online=true;
$provider->duringState=fn()=>BroadcastQueue::cancel([$j['id']]);
$result=NotificationDispatch::attempt($provider,'synthetic','Original bus',null,null,fn()=>BroadcastQueue::dispatchAllowed($d['id']));
check($result['status']==='skipped'&&$provider->sendCalls===0,'stop during provider health blocks POST');
BroadcastQueue::finishDelivery($d['id'],'failed','','Offline',300);
check(cancelStatus('broadcast_deliveries',$d['id'])==='skipped','offline retry after stop cannot requeue');
check(BroadcastQueue::claimDelivery('retry')===null,'cancelled retries never claimed');
// Once POST has started, preserve its actual response even when stop races with it.
$j=cancelJob();$d=BroadcastQueue::claimDelivery('in-flight');
BroadcastQueue::cancel([$j['id']]);
BroadcastQueue::finishDelivery($d['id'],'accepted','synthetic-late-acceptance');
check(cancelStatus('broadcast_deliveries',$d['id'])==='accepted','late provider acceptance preserved');
check(cancelStatus('broadcast_jobs',$j['id'])==='cancelled','late acceptance does not revive job');
BroadcastQueue::finishDelivery($d['id'],'failed','','late duplicate worker',300);
check(cancelStatus('broadcast_deliveries',$d['id'])==='accepted','late retry cannot erase final evidence');
$j=cancelJob();$d=BroadcastQueue::claimDelivery('ambiguous');BroadcastQueue::cancel([$j['id']]);
BroadcastQueue::finishDelivery($d['id'],'failed','','Provider POST response unknown');
check(cancelStatus('broadcast_deliveries',$d['id'])==='failed','uncertain request stays failed rather than falsely called unsent');
$j=cancelJob();$d=BroadcastQueue::claimDelivery('legacy-timeout');BroadcastQueue::cancel([$j['id']]);
BroadcastQueue::finishDelivery($d['id'],'failed','','Legacy POST timed out');
check(cancelStatus('broadcast_deliveries',$d['id'])==='failed','legacy POST retry after stop remains uncertain rather than unsent');
check(BroadcastQueue::cancel([$j['id']])['stopped']===0,'uncertain legacy POST excluded from unsent count');
// An invalid scope or storage failure rolls back the entire stop, not half a run.
$a=cancelJob();$b=cancelJob();
try{BroadcastQueue::cancel([$a['id'],999999]);check(false,'missing job accepted');}catch(InvalidArgumentException $e){check(true,'missing job rejected');}
check(cancelStatus('broadcast_jobs',$a['id'])==='queued','missing job rolls back stop');
$pdo->exec("CREATE TRIGGER cancel_fail BEFORE UPDATE ON broadcast_deliveries WHEN NEW.status='skipped' BEGIN SELECT RAISE(ABORT,'synthetic storage failure'); END");
try{BroadcastQueue::cancel([$a['id'],$b['id']]);check(false,'storage failure accepted');}catch(PDOException $e){check(true,'storage failure surfaced');}
check(cancelStatus('broadcast_jobs',$a['id'])==='queued'&&cancelStatus('broadcast_jobs',$b['id'])==='queued','storage failure rolls back all parent changes');
$pdo->exec('DROP TRIGGER cancel_fail');
$pdo->exec("INSERT INTO notification_runs(id,request_key,manifest_id,purpose,snapshot_json) VALUES(100,'synthetic-cancel-run',1,'reminder','{}')");
$q=$pdo->prepare('INSERT INTO notification_run_jobs(run_id,job_id) VALUES(100,?)');$q->execute([$a['id']]);$q->execute([$b['id']]);
$r=$center->cancel(0,$a['id']);
check($r['stopped']===2,'job belonging to a run stops every job in the run');
check(count($center->detail(100)['jobs'])===2,'history retains all stopped jobs');
check($center->cancel(100,0)===$r,'run stop replay safe');
foreach([[0,0],[100,$a['id']],[999999,0]] as [$runId,$jobId]){
    try{$center->cancel($runId,$jobId);check(false,'invalid scope accepted');}catch(InvalidArgumentException $e){check(true,'invalid scope rejected');}
}
echo 'Notification cancellation: '.($checks-$cancelChecks)." checks passed\n";
