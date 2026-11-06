<?php
// Isolated SQLite fixture; no network, no production data.
require __DIR__.'/notification_cancel_test.php';
$before=$checks;
$pdo->exec('DELETE FROM notification_calls; DELETE FROM messages; DELETE FROM broadcast_deliveries; DELETE FROM notification_run_jobs; DELETE FROM notification_runs; DELETE FROM broadcast_jobs');
$plan=['items'=>[['passenger_id'=>1,'channel'=>'max','recipient'=>'+70000000001','target'=>'synthetic','body'=>'Synthetic grace message']],'digest'=>'grace'];
$request=['request_key'=>'synthetic-grace-key-001','manifest_id'=>1,'digest'=>'grace','start_after'=>0,'emergency'=>true];
$start=time();$run=$center->launch($request,fn()=>$plan,42,'Synthetic');
$job=(int)$center->rows('SELECT job_id FROM notification_run_jobs WHERE run_id=?',[$run['run_id']])[0]['job_id'];
$row=$center->rows('SELECT * FROM broadcast_deliveries WHERE job_id=?',[$job])[0];
$wait=(int)$pdo->query("SELECT strftime('%s',available_at)-strftime('%s',CURRENT_TIMESTAMP) FROM broadcast_deliveries WHERE job_id=$job")->fetchColumn();
check($wait>=119&&$wait<=120,'server enforces two minutes, including emergency');
check(BroadcastQueue::claimDelivery('synthetic-early')===null,'worker cannot claim during grace');
$active=$center->active();check($active[0]['start_after']>=$start+120,'countdown uses server-owned timestamp');
$replay=$center->launch($request,fn()=>throw new RuntimeException('must not prepare'),42,'Synthetic');
check($replay['duplicate']&&$replay['run_id']===$run['run_id'],'lost response keeps same run');
$again=$center->rows('SELECT available_at FROM broadcast_deliveries WHERE job_id=?',[$job])[0];
check($again['available_at']===$row['available_at'],'retry does not restart countdown');
$stopped=$center->cancel($run['run_id'],0);
check($stopped['stopped']===1&&$stopped['transferred']===0,'cancel before start stops all locally');
$pdo->exec("UPDATE broadcast_deliveries SET available_at=datetime(CURRENT_TIMESTAMP,'-1 second') WHERE job_id=$job");
check(BroadcastQueue::claimDelivery('synthetic-cancelled')===null,'cancel remains effective after grace');
$request['request_key']='synthetic-grace-key-002';$next=$center->launch($request,fn()=>$plan,42,'Synthetic');
$nextJob=(int)$center->rows('SELECT job_id FROM notification_run_jobs WHERE run_id=?',[$next['run_id']])[0]['job_id'];
$pdo->exec("UPDATE broadcast_deliveries SET available_at=datetime(CURRENT_TIMESTAMP,'-1 second') WHERE job_id=$nextJob");
$claimed=BroadcastQueue::claimDelivery('synthetic-ready');
check((int)$claimed['job_id']===$nextJob,'new confirmed run can start after grace');
check(BroadcastQueue::claimDelivery('synthetic-twice')===null,'delivery claimed once');
echo 'Grace period: '.($checks-$before)." checks passed\n";
