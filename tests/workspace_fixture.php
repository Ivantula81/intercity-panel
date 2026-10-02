<?php
// CLI-only, synthetic renderer using the real layout and chat/manifest views.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PANEL_ROOT', dirname(__DIR__));
require PANEL_ROOT . '/app/workspace.php';
$fixture=json_decode(stream_get_contents(STDIN),true) ?: [];
$_GET=$fixture['query'] ?? [];
$_SESSION=['panel_user'=>$fixture['user'] ?? 1,'workspace_crypto'=>[
    'owner'=>(string)($fixture['user'] ?? 1),'scope'=>'synthetic-login-'.($fixture['user'] ?? 1),
    'key'=>base64_encode(str_repeat(chr((int)($fixture['user'] ?? 1)),32)),
]];
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'synthetic-csrf'; }
function current_user_name(): string { return 'Тестовый сотрудник'; }
function flash(): string { return ''; }
function view($file,$data=[]): void { extract($data);require PANEL_ROOT.'/app/views/'.$file.'.php'; }
function db(): PDO { static $db;if(!$db){$db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE carriers (id INTEGER, atp TEXT, contract_no TEXT)');}return $db; }
$page=$_GET['p'] ?? 'dashboard';
if($page==='logout'){workspace_logout_page($_SESSION['workspace_crypto']['scope']);exit;}
if($page==='login'){echo '<!doctype html><html lang="ru"><body>Тестовый вход</body></html>';exit;}
if(($page==='manifest'||$page==='contact') && ($_GET['id']??'')==='999'){workspace_missing($page==='manifest'?'manifests':'contacts');exit;}
$title='Рабочее место';
$content=static function()use($page):void {
    if($page==='chats'){view('chats',['startPhone'=>(string)($_GET['conversation_id']??'')]);}
    elseif($page==='manifest'){
        $manifest=array_merge(array_fill_keys(['carrier','bus','drivers','driver_phone','extra_info'],''),['id'=>1,'trip_number'=>'TEST','departure_at'=>null,'route'=>'Тестовый маршрут']);
        view('manifest',['manifest'=>$manifest,'passengers'=>[]]);
    }elseif($page==='contacts'){view('contacts',['contacts'=>[],'total'=>0,'q'=>$_GET['q']??'','sort'=>$_GET['sort']??'last_seen']);}
    else{echo '<div class="card"><h1>Тестовый раздел</h1><a href="/?p=manifest&amp;id=1" id="testObject">Открыть объект</a><a href="/?p=chats&amp;conversation_id=2" id="explicitChat">Открыть диалог 2</a></div><div style="height:2400px"></div>';}
};
view('layout',compact('title','page','content'));
