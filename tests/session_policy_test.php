<?php
require __DIR__.'/../app/workspace.php';
require __DIR__.'/../app/session_policy.php';
function csrf_token(): string {return 'fixture-token';}
$n=0;
function check(bool $ok,string $message):void{global $n;$n++;if(!$ok)throw new RuntimeException($message);}
function fresh():array{$_SESSION=['panel_user'=>1,'user_name'=>'Fixture','user_role'=>'admin'];session_policy_start(100000);workspace_config();return $_SESSION['workspace_crypto'];}
$_SESSION=[];check(!session_policy_check(100000),'anonymous denied');check(workspace_config()===[],'anonymous has no crypto');
$key=fresh();$clock=$_SESSION['auth_clock'];
check(session_policy_check(101799),'before idle boundary accepted');
for($i=0;$i<20;$i++)session_policy_config(101799);
check($_SESSION['auth_clock']===$clock,'polling never renews clock');
check(!array_key_exists('key',session_policy_config(101799)),'status contains no encryption key');
check(!session_policy_check(101800),'idle boundary denied');
check(!isset($_SESSION['panel_user'],$_SESSION['user_role'],$_SESSION['workspace_crypto']),'rights and key removed from active session');
check(session_policy_crypto('1',101800)===$key,'same owner can recover crypto after credentials');
check(session_policy_crypto('2',101800)===null,'other owner cannot recover');
check(!session_policy_touch(101801),'touch cannot resurrect expired access');
$until=$_SESSION['auth_recovery']['until'];session_policy_check(102000);
check($_SESSION['auth_recovery']['until']===$until,'polling does not extend recovery');
check(session_policy_crypto('1',$until)===null,'recovery expires at boundary');session_policy_check($until);
check(!isset($_SESSION['auth_recovery']),'expired recovery removed');
fresh();check(session_policy_touch(101799),'explicit activity renews idle');
check($_SESSION['auth_clock']['activity']===101799 && $_SESSION['auth_clock']['started']===100000,'activity does not renew shift');
$_SESSION['auth_clock']['activity']=143199;
check(session_policy_check(143199),'before absolute boundary accepted');
check(!session_policy_touch(143200),'absolute boundary cannot renew');check($_SESSION['auth_expired']==='absolute','absolute reason');
$key=fresh();unset($_SESSION['auth_clock']);check(!session_policy_check(100000),'legacy session requires login');check(session_policy_crypto('1',100000)===$key,'legacy workspace recoverable after same-user credentials');
fresh();$_SESSION['auth_clock']['activity']=100001;check(!session_policy_check(100000),'future clock fails closed');
fresh();$_SESSION=[];check(session_policy_crypto('1',100000)===null,'explicit logout removes recovery');
echo "Session policy: $n checks OK\n";
