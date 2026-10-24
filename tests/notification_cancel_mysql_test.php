<?php
// The required test enforces isolated network namespace, explicit datadir and empty queue.
require __DIR__.'/notification_queue_mysql_test.php';
require PANEL_ROOT.'/lib/NotificationCenter.php';
$initial=$checks;$jobs=[];
function parallelCancel(string $body): array {
    $code='require '.var_export(PANEL_ROOT.'/app/bootstrap.php',true).';require '.var_export(PANEL_ROOT.'/app/broadcast_queue.php',true).';'.$body;
    $p=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);return [$p,$pipes];
}
function joinedCancel(array $child): string {
    [$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($p)===0,'parallel cancellation worker succeeds: '.$err);return $out;
}
try {
    foreach(array_merge(['retry','accepted','post_retry'],array_fill(0,12,'claim')) as $race){
        $j=BroadcastQueue::enqueue('campaign',0,['fixture'=>bin2hex(random_bytes(8))]);$id=$j['id'];$jobs[]=$id;
        BroadcastQueue::materializeDeliveries($id,[['channel'=>'max','recipient'=>'synthetic-a','body'=>'Synthetic A'],['channel'=>'max','recipient'=>'synthetic-b','body'=>'Synthetic B']]);
        $d=BroadcastQueue::claimDelivery('concurrent');$did=(int)$d['id'];
        // Hold the same parent row so both processes really contend for the transaction lock.
        db()->beginTransaction();db()->query('SELECT id FROM broadcast_jobs WHERE id='.$id.' FOR UPDATE')->fetch();
        $stop=parallelCancel('echo json_encode(BroadcastQueue::cancel(['.$id.']));');
        $body=match($race){
            'retry'=>'BroadcastQueue::finishDelivery('.$did.',"failed","","Synthetic offline",300);',
            'post_retry'=>'BroadcastQueue::finishDelivery('.$did.',"failed","","Synthetic POST timeout");',
            'accepted'=>'BroadcastQueue::finishDelivery('.$did.',"accepted","synthetic-race-accepted");',
            'claim'=>'$d=BroadcastQueue::claimDelivery("concurrent-claim");if($d){BroadcastQueue::finishDelivery((int)$d["id"],"failed","","Synthetic retry",300);}',
        };
        $other=parallelCancel($body);usleep(100000);db()->commit();
        joinedCancel($stop);joinedCancel($other);
        check(db()->query('SELECT status FROM broadcast_jobs WHERE id='.$id)->fetchColumn()==='cancelled','parent stays cancelled after '.$race.' race');
        check((int)db()->query("SELECT COUNT(*) FROM broadcast_deliveries WHERE job_id=$id AND status='queued'")->fetchColumn()===0,'no pending delivery after '.$race.' race');
        check(!BroadcastQueue::dispatchAllowed($did),'dispatch closed after '.$race.' race');
        if($race==='post_retry')check(db()->query('SELECT status FROM broadcast_deliveries WHERE id='.$did)->fetchColumn()==='failed','uncertain POST remains failed in cancellation race');
        if($race==='accepted')check(db()->query('SELECT status FROM broadcast_deliveries WHERE id='.$did)->fetchColumn()==='accepted','in-flight provider evidence preserved in concurrent finish');
        if($race==='claim')BroadcastQueue::finishDelivery($did,'skipped','',BroadcastQueue::STOPPED);
        worker();check(BroadcastQueue::claimDelivery('after-stop')===null,'actual worker does not restart stopped job');
        check(BroadcastQueue::materializeDeliveries($id,[['channel'=>'max','recipient'=>'synthetic-late','body'=>'Late payload']])===0,'late materialization blocked on MariaDB');
        check(BroadcastQueue::cancel([$id])['sending']===0,'repeat stop has no remaining in-flight request after finish');
    }
    echo 'Cancellation/MariaDB: '.($checks-$initial)." checks passed\n";
}finally{
    if(db()->inTransaction())db()->rollBack();
    foreach($jobs as $id){db()->prepare('DELETE FROM broadcast_deliveries WHERE job_id=?')->execute([$id]);db()->prepare('DELETE FROM broadcast_jobs WHERE id=?')->execute([$id]);}
}
