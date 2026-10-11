<?php
// Explicit isolated copy only. Never uses a production socket or network namespace.
$expected = getenv('NOTIFICATION_TEST_DATADIR') ?: '';
if ($expected === '' || getenv('NOTIFICATION_TEST_ACK') !== 'isolated-copy'
    || !is_readable('/proc/self/ns/net') || readlink('/proc/self/ns/net') === readlink('/proc/1/ns/net')) {
    fwrite(STDERR, "Requires an isolated network namespace and explicit test datadir\n"); exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
require PANEL_ROOT.'/app/broadcast_queue.php';
if ((string)db()->query('SELECT @@datadir')->fetchColumn() !== $expected) throw new RuntimeException('Wrong database');
if ((int)db()->query("SELECT COUNT(*) FROM broadcast_deliveries WHERE status IN ('queued','sending')")->fetchColumn() !== 0) throw new RuntimeException('Isolated queue must be empty');
$jobs=[];$checks=0;
function check($ok,$why){global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function worker(){
    $p=proc_open([PHP_BINARY,PANEL_ROOT.'/scripts/broadcast_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($p)===0,'actual worker exit');
}
try {
    foreach ([true,false] as $validDeadline) {
        $item=['channel'=>'max','recipient'=>'+19990000001','target'=>'synthetic-target','body'=>'Synthetic expired message'];
        if($validDeadline)$item['expires_at']=time()-1;
        $j=BroadcastQueue::enqueue('campaign',0,['notification_run_id'=>-1,'fixture'=>bin2hex(random_bytes(8))]);$jobs[]=$j['id'];
        BroadcastQueue::materializeDeliveries($j['id'],[$item]);
        $q=db()->prepare('SELECT d.*,j.status AS job_status FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id WHERE j.id=?');
        worker();$q->execute([$j['id']]);$row=$q->fetch();
        check($row['status']==='skipped','expired or missing deadline skipped');
        check($row['job_status']==='completed','terminal job completed in MySQL');
        check($row['provider_id']===''&&$row['sent_at']===null,'no provider acceptance or sent timestamp');
        check((int)$row['attempts']===1&&$row['last_error']!=='','reason and single attempt persisted');
        worker();$q->execute([$j['id']]);check($q->fetch()===$row,'repeat worker cannot resend skipped delivery');
    }
    $j=BroadcastQueue::enqueue('campaign',0,['fixture'=>bin2hex(random_bytes(8))]);$jobs[]=$j['id'];
    BroadcastQueue::materializeDeliveries($j['id'],[['channel'=>'max','recipient'=>'synthetic','body'=>'Synthetic lock']]);
    db()->prepare("UPDATE broadcast_jobs SET status='paused' WHERE id=?")->execute([$j['id']]);
    check(BroadcastQueue::claimDelivery('fixture')===null,'paused job excluded on MySQL');
    db()->prepare("UPDATE broadcast_jobs SET status='queued' WHERE id=?")->execute([$j['id']]);
    $d=BroadcastQueue::claimDelivery('fixture');check($d!==null&&(int)$d['attempts']===1,'MySQL claim with joined row lock');
    db()->prepare("UPDATE broadcast_deliveries SET created_at='2000-01-01' WHERE id=?")->execute([$d['id']]);
    check(BroadcastQueue::claimDelivery('second')===null,'ambiguous in-flight row never reclaimed');
    BroadcastQueue::finishDelivery($d['id'],'failed','','Synthetic wait',1);
    check(db()->query('SELECT status FROM broadcast_deliveries WHERE id='.(int)$d['id'])->fetchColumn()==='queued','MySQL retry retained');
    echo "Actual worker/MySQL: $checks checks passed\n";
} finally {
    foreach($jobs as $id){db()->prepare('DELETE FROM broadcast_deliveries WHERE job_id=?')->execute([$id]);db()->prepare('DELETE FROM broadcast_jobs WHERE id=?')->execute([$id]);}
}
