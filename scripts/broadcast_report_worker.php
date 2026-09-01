<?php
// Формирует промежуточный и итоговый отчёт по кампаниям и ставит его в outbox.
require dirname(__DIR__) . '/app/bootstrap.php';
require PANEL_ROOT . '/app/broadcast_queue.php';

$pdo = db();
$admins = ['+79787720157', '+79112790467'];
$jobs = $pdo->query("SELECT j.*, m.trip_number,m.route,m.departure_at FROM broadcast_jobs j JOIN manifests m ON m.id=j.manifest_id WHERE j.kind='campaign' AND j.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY j.id")->fetchAll();
$groups=[]; foreach($jobs as $row){ $key=$row['manifest_id'].'|'.floor(strtotime($row['created_at'])/600); if(!isset($groups[$key])) $groups[$key]=['anchor'=>$row,'ids'=>[]]; $groups[$key]['ids'][]=(int)$row['id']; }
foreach ($groups as $group) { $j=$group['anchor']; $jobIds=$group['ids'];
    $p = json_decode((string)$j['payload_json'], true) ?: [];
    if (empty($p['reporting_opt_in'])) continue;
    $age = time() - strtotime((string)$j['created_at']);
    $ph=implode(',',array_fill(0,count($jobIds),'?')); $st = $pdo->prepare("SELECT status,COUNT(*) n FROM broadcast_deliveries WHERE job_id IN ($ph) GROUP BY status"); $st->execute($jobIds);
    $counts=[]; foreach ($st->fetchAll() as $x) $counts[$x['status']]=(int)$x['n'];
    $total=array_sum($counts); $accepted=(int)($counts['accepted']??0); $queued=(int)($counts['queued']??0)+(int)($counts['sending']??0);
    $settled = $queued===0 && ($accepted===0 || $age >= 1200);
    $kind = '';
    if (empty($p['report_progress_sent']) && $age >= 3600) $kind='progress';
    if (empty($p['report_final_sent']) && $settled && $age >= 1200) $kind='final';
    if ($kind==='') continue;
    $del=[]; $body = ($kind==='final' ? 'Итог рассылки' : 'Рассылка продолжается') . "\nВедомость №".$j['trip_number']."\nДата: ".date('d.m.Y H:i',strtotime($j['departure_at']))."\nМаршрут: ".$j['route']."\n\nВсего сообщений: ".$total."\nДоставлено/прочитано: ".((int)($counts['delivered']??0)+(int)($counts['read']??0))."\nОшибки: ".((int)($counts['failed']??0)+(int)($counts['skipped']??0));
    if ($kind==='progress') $body .= "\n\nРассылка продолжается. Окончательный результат будет отправлен после завершения.";
    foreach ($admins as $phone) $del[]=['channel'=>'max','recipient'=>$phone,'target'=>preg_replace('/\D+/','',$phone).'@c.us','body'=>$body];
    $job=BroadcastQueue::enqueue('single',(int)$j['manifest_id'],['deliveries'=>$del,'report_for'=>(int)$j['id']],null);
    BroadcastQueue::materializeDeliveries((int)$job['id'],$del);
    $p[$kind==='final'?'report_final_sent':'report_progress_sent']=date('c');
    $pdo->prepare('UPDATE broadcast_jobs SET payload_json=? WHERE id=?')->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$j['id']]);
}
