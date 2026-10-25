<?php
header('Cache-Control: no-store');
$action = $_GET['a'] ?? 'status';
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // A status read never extends the idle deadline or returns the workspace key.
    json_out(['ok'=>true] + session_policy_config(time()));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['touch','reauth'], true)) {
    json_out(['ok'=>false,'error'=>'Недопустимый запрос'],405);
}
csrf_check();
if ($action === 'touch') {
    $ok = session_policy_touch(time());
    json_out(['ok'=>$ok] + session_policy_config(time()), $ok ? 200 : 401);
}
$body = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($body) || !is_string($body['login'] ?? null) || !is_string($body['password'] ?? null)
    || strlen($body['login']) > 200 || strlen($body['password']) > 4096) json_out(['ok'=>false,'error'=>'Проверьте логин и пароль'],400);
if (!authenticate($body['login'], $body['password'], true)) {
    // Same delay as ordinary login, plus a bounded per-session cooldown. No credential logging.
    audit_event('auth.reauthenticate', 'auth', 'session', null, 'failure');
    sleep(1);
    json_out(['ok'=>false,'error'=>'Не удалось подтвердить вход. Используйте прежнюю учётную запись; после нескольких ошибок подождите минуту.'],401);
}
audit_event('auth.reauthenticate', 'auth', 'session', null, 'success');
json_out(['ok'=>true] + session_policy_config(time()));
