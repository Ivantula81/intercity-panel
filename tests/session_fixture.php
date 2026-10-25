<?php
// Runs only inside the isolated test server. Never routed by public/index.php.
if(PHP_SAPI!=='cli-server'||!getenv('PANEL_SESSION_TEST_DIR')){http_response_code(404);exit;}
error_reporting(E_ALL & ~E_DEPRECATED); // PHP 8.5 deprecates the SQLite compatibility helper.
define('PANEL_ROOT',dirname(__DIR__));
$dir=getenv('PANEL_SESSION_TEST_DIR');
if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/assets/')){
 $name=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
 if(!preg_match('/^(session|workspace|panel)\.(js|css)$/D',$name)){http_response_code(404);exit;}
 header('Content-Type: '.(str_ends_with($name,'.js')?'text/javascript':'text/css'));readfile(PANEL_ROOT.'/public/assets/'.$name);exit;
}
ini_set('session.use_strict_mode','1');session_save_path($dir);session_start();
require PANEL_ROOT.'/app/workspace.php';require PANEL_ROOT.'/app/session_policy.php';require PANEL_ROOT.'/app/authentication.php';
function db():PDO{static $db;if(!$db){$db=new PDO('sqlite:'.getenv('PANEL_SESSION_TEST_DIR').'/users.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->sqliteCreateFunction('NOW',fn()=>date('c'));}return $db;}
function json_out(array $data,int $code=200):never{http_response_code($code);header('Content-Type: application/json');echo json_encode($data);exit;}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function audit_event(...$args):void{}
function current_user_name():string{return 'Fixture';}
function flash():string{return '';}
$page=$_GET['p']??'dashboard';session_policy_check(time());
if($page==='session'){require PANEL_ROOT.'/app/session_controller.php';exit;}
if($page==='fixture'){
 csrf_check();$action=$_GET['a']??'';
 if($action==='expire'){$_SESSION['auth_clock']['activity']=time()-1800;$_SESSION['auth_clock']['started']=time()-2000;}
 if($action==='near-expiry'){$_SESSION['auth_clock']['activity']=time()-1797;$_SESSION['auth_clock']['started']=time()-2000;}
 if($action==='warning'){$_SESSION['auth_clock']['activity']=time()-1700;$_SESSION['auth_clock']['started']=time()-2000;}
 if($action==='absolute'){$_SESSION['auth_clock']['started']=time()-43200;}
 if($action==='legacy')unset($_SESSION['auth_clock']);
 if($action==='cooldown')$_SESSION['auth_retry_at']=time()-1;
 if($action==='disable')db()->exec('UPDATE users SET active=0 WHERE id=1');
 json_out(['ok'=>true,'clock'=>$_SESSION['auth_clock']??null,'recovery'=>isset($_SESSION['auth_recovery']),'sid'=>session_id(),'mutations'=>(int)@file_get_contents(getenv('PANEL_SESSION_TEST_DIR').'/mutations')]);
}
if($page==='login'){
 if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();if(authenticate($_POST['login']??'',$_POST['password']??'')){header('Location: /');exit;}http_response_code(401);}
 echo '<!doctype html><form method="post"><input name="csrf" type="hidden" value="'.e(csrf_token()).'"><input name="login"><input name="password" type="password"><button>Войти</button></form>';exit;
}
if($page==='logout'){$scope=$_SESSION['workspace_crypto']['scope']??$_SESSION['auth_recovery']['crypto']['scope']??'';$_SESSION=[];session_destroy();workspace_logout_page($scope);exit;}
require_login();
if(in_array($page,['api','chat_api','reporting_api'],true)){
 csrf_check();
 if(($_GET['a']??'')==='save'){file_put_contents(getenv('PANEL_SESSION_TEST_DIR').'/mutations',1+(int)@file_get_contents(getenv('PANEL_SESSION_TEST_DIR').'/mutations'));}
 json_out(['ok'=>true]);
}
$title='Синтетический рейс';$page='dashboard';$content=static function(){echo '<h1>Синтетический рейс</h1><label>Автобус <input id="bus" autocomplete="off"></label><textarea id="draft"></textarea><button id="save" onclick="fetch(\'/?p=api&a=save\',{method:\'POST\',headers:{\'X-CSRF\':window.CSRF}})">Сохранить</button>';};
require PANEL_ROOT.'/app/views/layout.php';
