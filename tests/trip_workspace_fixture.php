<?php
// CLI renderer: real controllers/views, isolated synthetic SQLite; no production config.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('PANEL_ROOT',dirname(__DIR__));
require PANEL_ROOT.'/app/workspace.php';
require PANEL_ROOT.'/app/trip_workspace.php';
$fixture=json_decode(stream_get_contents(STDIN),true)?:[];
$_GET=$fixture['query']??[];$_SERVER['REQUEST_METHOD']='GET';
$_SESSION=['panel_user'=>1,'workspace_crypto'=>['owner'=>'1','scope'=>'synthetic-trip-login','key'=>base64_encode(str_repeat('x',32))]];
function e($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function csrf_token():string{return 'synthetic';}
function current_user_name():string{return 'Тестовый сотрудник';}
function flash():string{return '';}
function view($file,$data=[]):void{extract($data);require PANEL_ROOT.'/app/views/'.$file.'.php';}
function db():PDO{
    static $db;if($db)return $db;$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE carriers(id INTEGER,atp TEXT,contract_no TEXT); CREATE TABLE manifests(id INTEGER PRIMARY KEY,trip_number TEXT,route TEXT,departure_at TEXT,created_at TEXT,carrier TEXT,bus TEXT,drivers TEXT,driver_phone TEXT,extra_info TEXT); CREATE TABLE passengers(id INTEGER PRIMARY KEY,manifest_id INTEGER,seat TEXT,name TEXT,phone TEXT,doc TEXT,ticket TEXT,from_stop TEXT,to_stop TEXT,pay_note TEXT); CREATE TABLE conversations(id INTEGER PRIMARY KEY,manifest_id INTEGER,channel TEXT,contact_name TEXT,status TEXT)');
    $st=$db->prepare('INSERT INTO manifests VALUES (?,?,?,?,?,?,?,?,?,?)');
    for($i=1;$i<=65;$i++)$st->execute([$i,'T-022','Москва → Тестовый город','2026-10-25 12:30:00','2026-10-20 10:00:00','Тестовый перевозчик',$i===64?'':'Тестовый автобус','Тестовый водитель','+70000000000','']);
    $db->exec("INSERT INTO passengers VALUES (1,65,'1','Тестовый пассажир','+70000000000','Тест','TEST','Тест A','Тест B',''); INSERT INTO conversations VALUES (1,65,'max','Тестовый диалог A','open'),(2,64,'telegram','Тестовый диалог B','resolved')");return $db;
}
$page=$_GET['p']??'manifests';$title='Рейсы и ведомости';
if($page==='manifests'){require PANEL_ROOT.'/app/manifests_controller.php';exit;}
$content=static function()use($page):void{
    if($page==='chats'){view('chats',['startPhone'=>(string)($_GET['conversation_id']??'')]);return;}
    $st=db()->prepare('SELECT * FROM manifests WHERE id=?');$st->execute([(int)($_GET['id']??0)]);$manifest=$st->fetch();
    if(!$manifest){echo '<div data-workspace-missing>Ведомость недоступна. <a href="/?p=manifests">К списку</a></div>';return;}
    $st=db()->prepare('SELECT * FROM passengers WHERE manifest_id=?');$st->execute([$manifest['id']]);
    view('manifest',['manifest'=>$manifest,'passengers'=>$st->fetchAll(),'tripConversations'=>trip_conversations(db(),$manifest['id'])]);
};
view('layout',compact('title','page','content'));
