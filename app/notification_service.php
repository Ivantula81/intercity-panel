<?php
function notification_prepare(array $request): array {
    $mid=(int)($request['manifest_id']??0);$manifest=get_manifest($mid);
    $purpose=$request['purpose']??'reminder';if(!in_array($purpose,['reminder','repeat','change'],true))throw new InvalidArgumentException('Неизвестный вид уведомления.');
    $groups=build_groups($manifest);$byKey=[];foreach($groups as $g)$byKey[$g['route_key']]=$g;
    $center=new NotificationCenter(db());$overview=$center->overview($mid);$covered=[];foreach($overview['recipients'] as $p)if($p['covered'])$covered[$p['phone']]=true;
    $channels=array_values(array_unique((array)($request['channels']??[])));sort($channels);
    if(!$channels)throw new InvalidArgumentException('Выберите канал.');
    foreach($channels as $ch)if(!in_array($ch,['max','telegram','whatsapp'],true)||!Channels::ready($ch))throw new InvalidArgumentException('Выбранный канал недоступен для очереди.');
    $ps=$center->rows('SELECT * FROM passengers WHERE manifest_id=?',[$mid]);$byId=[];foreach($ps as $p)$byId[(int)$p['id']]=$p;
    $contacts=[];foreach($center->rows('SELECT c.phone,c.has_max,c.has_telegram,c.has_whatsapp FROM contacts c JOIN passengers p ON p.phone=c.phone WHERE p.manifest_id=?',[$mid]) as $c)$contacts[$c['phone']]=$c;
    $tpl=(string)opt('notif_tpl_'.$mid,'');if($tpl==='')$tpl=(string)(db()->query('SELECT body FROM templates ORDER BY sort,id LIMIT 1')->fetchColumn()?:'');
    $blocks=json_decode((string)opt('notif_tpl_blocks_'.$mid,''),true)?:[];
    $opts=send_opts($manifest,$request);$items=[];$exclusions=[];$selected=[];$seen=[];
    if(!empty($request['attach_photo']))throw new InvalidArgumentException('Отправка фото через очередь пока недоступна. Снимите выбор фото.');
    foreach((array)($request['groups']??[]) as $selection){
        $key=(string)($selection['key']??'');$g=$byKey[$key]??null;if(!$g)throw new InvalidArgumentException('Направление не принадлежит ведомости.');
        $ids=array_values(array_unique(array_map('intval',(array)($selection['ids']??[]))));if(!$ids)continue;
        if(!array_key_exists('revision',$selection)||(int)$selection['revision']!==(int)($g['schedule_revision']??0))throw new DomainException('Расписание изменилось. Обновите ведомость перед отправкой.');
        if(($selection['date']??'')!==$g['date']||($selection['time']??'')!==$g['time'])throw new DomainException('Время изменилось или не сохранено. Обновите расписание.');
        $dt=DateTimeImmutable::createFromFormat('!d.m.Y H:i',$g['date'].' '.$g['time']);
        if(!$dt||$dt->format('d.m.Y H:i')!==$g['date'].' '.$g['time']||!empty($g['time_warning']))throw new InvalidArgumentException('Проверьте дату и время посадки: '.$g['station']);
        $allowed=array_column($g['recipients'],'id');$text=$g['body']??($blocks[$g['station']]??$tpl);
        if(trim($text)==='')throw new InvalidArgumentException('Пустой шаблон сообщения.');
        if(($selection['text']??'')!==$text)throw new DomainException('Текст ещё не сохранён или изменён другим сотрудником.');
        $selected[]=['key'=>$key,'station'=>$g['station'],'destination'=>$g['destination'],'date'=>$g['date'],'time'=>$g['time'],'ids'=>$ids];
        foreach($ids as $pid){
            if(!in_array($pid,$allowed,true)||!isset($byId[$pid]))throw new InvalidArgumentException('Пассажир не принадлежит выбранному направлению.');
            $p=$byId[$pid];$ph=$p['phone'];$why='';
            if(!valid_phone($ph))$why='Некорректный телефон';elseif(is_unsubscribed($ph))$why='Отписка';elseif($purpose==='reminder'&&isset($covered[$ph]))$why='Уже уведомлён или в очереди';elseif(isset($seen[$ph]))$why='Один телефон в нескольких билетах';
            if($why!==''){$exclusions[]=['passenger_id'=>$pid,'reason'=>$why];continue;}$seen[$ph]=true;
            $vars=group_vars($manifest,$p,$g,$opts);if(MessageTemplate::unknownVars($text,$vars))throw new InvalidArgumentException('В шаблоне есть неизвестные переменные.');
            $message=render_group_message($text,$vars,$manifest['extra_info']);
            if($purpose==='change')$message="Уточнение по вашему рейсу. Актуальная информация ниже.\n\n".$message;
            $line=trim((string)opt('unsub_line','Чтобы отписаться — напишите СТОП'));if($line!=='')$message.="\n\n".$line;
            foreach($channels as $ch){
                if(isset($contacts[$ph]['has_'.$ch])&&!(bool)$contacts[$ph]['has_'.$ch]){$exclusions[]=['passenger_id'=>$pid,'channel'=>$ch,'reason'=>'Нет аккаунта'];continue;}
                $resolved=resolve_send_target($ch,$ph,false);
                if(empty($resolved['ok'])&&$ch==='max')$resolved=['ok'=>true,'target'=>preg_replace('/\D/','',$ph).'@c.us'];
                if(empty($resolved['ok'])){$exclusions[]=['passenger_id'=>$pid,'channel'=>$ch,'reason'=>'Нет сохранённого адреса канала'];continue;}
                $items[]=['passenger_id'=>$pid,'name'=>$p['name'],'channel'=>$ch,'recipient'=>$ph,'target'=>$resolved['target'],'body'=>$message,'station'=>$g['station'],'date'=>$g['date'],'time'=>$g['time']];
            }
        }
    }
    $plan=['manifest'=>array_intersect_key($manifest,array_flip(['id','trip_number','route','departure_at','bus','driver_phone'])), 'purpose'=>$purpose,'groups'=>$selected,'channels'=>$channels,'items'=>$items,'exclusions'=>$exclusions,'emergency'=>!empty($request['emergency'])];
    $plan['digest']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return $plan;
}
