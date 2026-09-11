<?php
require_once PANEL_ROOT.'/lib/NotificationCenter.php';
require_once PANEL_ROOT.'/lib/Channels.php';
require_once PANEL_ROOT.'/lib/MessageTemplate.php';
require_once PANEL_ROOT.'/app/broadcast_queue.php';

require_once PANEL_ROOT.'/app/notification_service.php';

try {
    if(!in_array($_SESSION['user_role']??'', ['admin','operator'],true))json_out(['ok'=>false,'error'=>'Недостаточно прав.'],403);
    $center=new NotificationCenter(db());
    switch($action){
        case 'notification.list': json_out(['ok'=>true]+$center->list($body));
        case 'notification.overview': json_out(['ok'=>true]+$center->overview((int)$body['manifest_id']));
        case 'notification.active': json_out(['ok'=>true,'items'=>$center->active(),'at'=>date('c')]);
        case 'notification.history': json_out(['ok'=>true]+$center->history((int)$body['manifest_id'],(string)($body['date']??''),(int)($body['page']??1)));
        case 'notification.detail': json_out(['ok'=>true,'run'=>$center->detail((int)$body['id'])]);
        case 'notification.preview': json_out(['ok'=>true,'plan'=>notification_prepare($body)]);
        case 'notification.launch':
            if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'Используйте POST.'],405);
            $r=$center->launch($body,'notification_prepare',audit_actor_id(),current_user_name());
            audit_event('notification.launch','messaging','notification_run',$r['run_id'],'success');json_out(['ok'=>true]+$r);
        case 'notification.call':
            if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'Используйте POST.'],405);
            $p=$center->rows('SELECT id,phone FROM passengers WHERE id=? AND manifest_id=?',[(int)$body['passenger_id'],(int)$body['manifest_id']])[0]??null;
            if(!$p)throw new InvalidArgumentException('Пассажир не найден.');
            db()->prepare('INSERT INTO notification_calls (manifest_id,passenger_id,recipient,created_by,actor_name) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')->execute([(int)$body['manifest_id'],$p['id'],$p['phone'],audit_actor_id(),current_user_name()]);
            audit_event('notification.call','messaging','manifest',(int)$body['manifest_id'],'success');json_out(['ok'=>true]);
    }
    json_out(['ok'=>false,'error'=>'Неизвестное действие.'],404);
}catch(DomainException $e){json_out(['ok'=>false,'error'=>$e->getMessage()],409);}
catch(InvalidArgumentException $e){json_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('notification-center: '.get_class($e));json_out(['ok'=>false,'error'=>'Центр уведомлений временно недоступен. Проверьте установку схемы и повторите запрос.'],503);}
