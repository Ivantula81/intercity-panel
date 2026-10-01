<?php
require __DIR__ . '/../app/workspace.php';
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } }
$_SESSION=[];
check(workspace_config()===[], 'No key without login');
$_SESSION['panel_user']=1;
$a=workspace_config();
check(strlen(base64_decode($a['key'],true))===32, 'AES-256 key size');
check(strlen($a['scope'])===32, 'Random storage scope');
check(workspace_config()===$a, 'Key stable within current login');
$_SESSION['panel_user']=2;
$b=workspace_config();
check($b!==$a, 'Another user cannot reuse key or namespace');
unset($_SESSION['workspace_crypto']); // authenticate() resets it on each successful login.
check(workspace_config()!==$b, 'New login gets a fresh key');
$_SESSION=[];
check(workspace_config()===[], 'Logout removes access');
echo "Workspace crypto lifecycle: OK\n";
