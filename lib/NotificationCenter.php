<?php
/** Read model and atomic run store. Provider calls deliberately live outside this class. */
final class NotificationCenter
{
    public function __construct(private PDO $pdo) {}
    public function rows(string $sql, array $args = []): array {
        $s = $this->pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function state(array $r): string {
        if (!empty($r['read_at']) || ($r['status'] ?? '') === 'read') return 'read';
        if (!empty($r['delivered_at']) || ($r['status'] ?? '') === 'delivered') return 'delivered';
        return ['sent'=>'accepted','pending'=>'queued'][$r['status'] ?? ''] ?? ($r['status'] ?? 'none');
    }
    public static function covered(string $state): bool { return in_array($state, ['queued','sending','accepted','delivered','read','called'], true); }
    public function attempts(int $mid): array {
        $a = $this->rows('SELECT d.*, j.created_at AS launched_at FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id WHERE j.manifest_id=? ORDER BY d.id', [$mid]);
        $known = []; foreach ($a as $d) if ($d['provider_id'] !== '') $known[$d['channel'].'|'.$d['provider_id']] = true;
        foreach ($this->rows('SELECT * FROM messages WHERE manifest_id=? ORDER BY id', [$mid]) as $m) {
            if (!empty($m['wa_id']) && isset($known[$m['channel'].'|'.$m['wa_id']])) continue;
            $m['last_error'] = $m['error'] ?? ''; $m['legacy'] = true; $a[] = $m;
        }
        return $a;
    }
    public function overview(int $mid): array {
        $ps = $this->rows('SELECT id,name,phone,from_stop,to_stop FROM passengers WHERE manifest_id=? ORDER BY sort,id', [$mid]);
        $attempts = $this->attempts($mid); $byPhone = [];
        // Successful delivery remains evidence even after a subsequent failed attempt.
        $rank = ['none'=>0,'skipped'=>1,'failed'=>2,'queued'=>3,'sending'=>4,'accepted'=>5,'delivered'=>6,'read'=>7,'called'=>8];
        foreach ($attempts as $a) {
            $state = self::state($a); $ph = $a['recipient']; $ch = $a['channel'];
            $old = $byPhone[$ph][$ch]['state'] ?? 'none';
            if (($rank[$state] ?? 0) >= ($rank[$old] ?? 0)) $byPhone[$ph][$ch] = ['state'=>$state,'error'=>$a['last_error'] ?? '', 'at'=>$a['created_at']];
        }
        $calls = $this->rows('SELECT * FROM notification_calls WHERE manifest_id=?', [$mid]); $calledIds = []; $calledPhones = [];
        foreach ($calls as $c) { $calledIds[(int)$c['passenger_id']]=$c; if ($c['recipient'] !== '') $calledPhones[$c['recipient']]=$c; }
        $people=[]; $unique=[]; $summary=['passengers'=>count($ps),'recipients'=>0,'delivered'=>0,'read'=>0,'queued'=>0,'accepted'=>0,'called'=>0,'attention'=>0,'attempts'=>count($attempts)];
        foreach ($ps as $p) {
            $key=$p['phone'] !== '' ? $p['phone'] : 'missing:'.$p['id']; $states=$byPhone[$p['phone']] ?? []; $best='none';
            foreach ($states as $v) if (($rank[$v['state']]??0)>($rank[$best]??0)) $best=$v['state'];
            $call=$calledIds[(int)$p['id']] ?? ($calledPhones[$p['phone']] ?? null); if ($call) $best='called';
            $people[]=array_merge($p,['state'=>$best,'channel_states'=>$states,'covered'=>self::covered($best),'call'=>$call]);
            if (isset($unique[$key])) continue; $unique[$key]=true; $summary['recipients']++;
            if (in_array($best,['delivered','read'],true)) { $summary['delivered']++; if ($best==='read') $summary['read']++; }
            elseif (in_array($best,['queued','sending'],true)) $summary['queued']++;
            elseif ($best==='accepted') $summary['accepted']++;
            elseif ($best==='called') $summary['called']++;
            else $summary['attention']++;
        }
        $active=$this->rows("SELECT COUNT(*) n FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id WHERE j.manifest_id=? AND d.status IN ('queued','sending','accepted')",[$mid])[0]['n'];
        $launched=(bool)$attempts || (bool)$calls || (bool)$this->rows('SELECT id FROM notification_runs WHERE manifest_id=? LIMIT 1',[$mid]);
        $summary['state']=!$launched?'new':($active?'running':($summary['attention'] || !$summary['recipients'] ? 'attention':'done'));
        return ['summary'=>$summary,'recipients'=>$people,'launched'=>$launched];
    }
    public function list(array $filter): array {
        $where=[];$args=[];
        if (!empty($filter['date'])) { self::date($filter['date']); $where[]='departure_at>=? AND departure_at<?';$args[]=$filter['date'].' 00:00:00';$args[]=date('Y-m-d',strtotime($filter['date'].' +1 day')).' 00:00:00'; }
        if (trim($filter['search']??'')!=='') {$where[]='trip_number LIKE ?';$args[]='%'.trim($filter['search']).'%';}
        $rows=$this->rows('SELECT id,trip_number,route,departure_at,created_at FROM manifests'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY departure_at DESC,id DESC',$args);
        $out=[]; foreach($rows as $m) {$m['summary']=$this->overview((int)$m['id'])['summary'];if(empty($filter['state'])||$filter['state']===$m['summary']['state'])$out[]=$m;}
        $page=max(1,(int)($filter['page']??1));return ['items'=>array_slice($out,($page-1)*20,20),'total'=>count($out),'page'=>$page];
    }
    public static function date(string $date): void {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Некорректная дата.');
    }
    public function active(): array {
        return $this->rows("SELECT j.id,j.manifest_id,j.kind,j.created_at,j.status,m.trip_number,m.departure_at,rj.run_id,
          SUM(CASE WHEN d.status='queued' THEN 1 ELSE 0 END) queued,
          SUM(CASE WHEN d.status='sending' THEN 1 ELSE 0 END) sending,
          SUM(CASE WHEN d.status='accepted' THEN 1 ELSE 0 END) accepted,
          SUM(CASE WHEN d.status IN ('read','delivered') THEN 1 ELSE 0 END) delivered,
          SUM(CASE WHEN d.status='failed' THEN 1 ELSE 0 END) failed,
          MIN(CASE WHEN d.status='queued' THEN d.available_at END) next_at,
          MAX(CASE WHEN d.status IN ('queued','sending') THEN d.last_error ELSE '' END) reason
          FROM broadcast_jobs j JOIN broadcast_deliveries d ON d.job_id=j.id
          LEFT JOIN manifests m ON m.id=j.manifest_id LEFT JOIN notification_run_jobs rj ON rj.job_id=j.id
          GROUP BY j.id,j.manifest_id,j.kind,j.created_at,j.status,m.trip_number,m.departure_at,rj.run_id
          HAVING SUM(CASE WHEN d.status IN ('queued','sending','accepted') THEN 1 ELSE 0 END)>0
          ORDER BY CASE WHEN j.manifest_id>0 THEN 0 ELSE 1 END,j.id");
    }
    public function history(int $mid, string $date='', int $page=1): array {
        $where='manifest_id=?';$args=[$mid];if($date!==''){self::date($date);$where.=' AND created_at>=? AND created_at<?';$args[]=$date.' 00:00:00';$args[]=date('Y-m-d',strtotime($date.' +1 day')).' 00:00:00';}
        $page=max(1,$page);$offset=($page-1)*20;
        $runs=$this->rows("SELECT id,purpose,actor_name,created_at FROM notification_runs WHERE $where ORDER BY id DESC LIMIT 20 OFFSET $offset",$args);
        // Legacy attempts are explicitly separate, never guessed into runs.
        $legacy=$this->rows("SELECT id,channel,recipient,status,created_at,body FROM messages WHERE $where AND NOT EXISTS
          (SELECT 1 FROM broadcast_deliveries d JOIN notification_run_jobs rj ON rj.job_id=d.job_id WHERE d.provider_id=messages.wa_id AND d.channel=messages.channel AND d.provider_id<>'') ORDER BY id DESC LIMIT 20 OFFSET $offset",$args);
        return ['runs'=>$runs,'legacy'=>$legacy,'page'=>$page];
    }
    public function detail(int $id): array {
        $run=$this->rows('SELECT * FROM notification_runs WHERE id=?',[$id])[0]??null;if(!$run)throw new InvalidArgumentException('Запуск не найден.');
        $run['snapshot']=json_decode($run['snapshot_json'],true);unset($run['snapshot_json']);
        $run['deliveries']=$this->rows('SELECT d.* FROM broadcast_deliveries d JOIN notification_run_jobs r ON r.job_id=d.job_id WHERE r.run_id=? ORDER BY d.id',[$id]);return $run;
    }
    public function launch(array $request, callable $prepare, ?int $actor, string $name): array {
        $key=(string)($request['request_key']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key))throw new InvalidArgumentException('Нужен ключ запуска.');
        $mid=(int)$request['manifest_id'];$pdo=$this->pdo;$pdo->beginTransaction();
        try {
            // Serialize all new notification runs for one manifest, including different browser tabs.
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            if(!$this->rows('SELECT id FROM manifests WHERE id=?'.$lock,[$mid]))throw new InvalidArgumentException('Ведомость не найдена.');
            $old=$this->rows('SELECT id,manifest_id,created_by FROM notification_runs WHERE request_key=?',[$key])[0]??null;
            if($old){if((int)$old['manifest_id']!==$mid||(int)$old['created_by']!==(int)$actor)throw new InvalidArgumentException('Ключ принадлежит другому запуску.');$pdo->commit();return ['run_id'=>(int)$old['id'],'duplicate'=>true];}
            $plan=$prepare($request);
            if(!hash_equals((string)$plan['digest'],(string)($request['digest']??'')))throw new DomainException('Данные изменились. Проверьте новый предпросмотр перед отправкой.');
            if(!$plan['items'])throw new InvalidArgumentException('Нет новых получателей для отправки.');
            $st=$pdo->prepare('INSERT INTO notification_runs (request_key,manifest_id,purpose,snapshot_json,created_by,actor_name) VALUES (?,?,?,?,?,?)');
            $st->execute([$key,$mid,$request['purpose']??'reminder',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor,$name]);$runId=(int)$pdo->lastInsertId();
            $payload=['notification_run_id'=>$runId,'deliveries'=>$plan['items'],'emergency'=>!empty($request['emergency']),'reporting_opt_in'=>true];
            $job=BroadcastQueue::enqueue('campaign',$mid,$payload,$actor);BroadcastQueue::materializeDeliveries((int)$job['id'],$plan['items']);
            $pdo->prepare('INSERT INTO notification_run_jobs (run_id,job_id) VALUES (?,?)')->execute([$runId,$job['id']]);
            $pdo->commit();return ['run_id'=>$runId,'deliveries'=>count($plan['items']),'duplicate'=>false];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
