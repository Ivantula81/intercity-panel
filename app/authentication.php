<?php
// Shared by the real application and isolated session integration tests.
function logged_in(): bool
{
    return session_policy_check(time());
}

function require_login(): void
{
    if (!logged_in()) {
        header('Cache-Control: no-store');
        if (in_array($_GET['p'] ?? '', ['api','chat_api','reporting_api'], true)) {
            json_out(['ok'=>false, 'auth_required'=>true, 'error'=>'Вход завершён. Войдите снова; действие не выполнено.'], 401);
        }
        header('Location: /?p=login');
        exit;
    }
}

// Вход по логину+паролю. Возвращает true и наполняет сессию при успехе.
function credential_user(string $login, string $password): ?array
{
    $login = trim($login);
    try {
        $st = db()->prepare('SELECT * FROM users WHERE login = ? AND active = 1');
        $st->execute([$login]);
        $u = $st->fetch();
    } catch (Exception $e) {
        $u = null; // таблицы users может не быть до применения schema5
    }

    if ($u && password_verify($password, $u['password_hash'])) return $u;

    // запасной вход на случай, если users ещё не созданы (миграция).
    // Пароль — только из окружения (ADMIN_FALLBACK_PASSWORD); по умолчанию выключен.
    $fallbackPw = getenv('ADMIN_FALLBACK_PASSWORD') ?: '';
    if ($fallbackPw === '' && is_readable('/etc/panel.env')) {
        foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__envLine) {
            if (str_starts_with($__envLine, 'ADMIN_FALLBACK_PASSWORD=')) { $fallbackPw = substr($__envLine, strlen('ADMIN_FALLBACK_PASSWORD=')); break; }
        }
    }
    if ($u === null && $fallbackPw !== '' && $login === 'admin' && hash_equals($fallbackPw, $password)) {
        return ['id'=>'admin', 'name'=>'Администратор', 'role'=>'admin'];
    }
    return null;
}

function authenticate(string $login, string $password, bool $reauth = false): bool
{
    $now = time();
    session_policy_check($now);
    if ((int)($_SESSION['auth_retry_at'] ?? 0) > $now) return false;
    $user = credential_user($login, $password);
    $expected = (string)($_SESSION['panel_user'] ?? $_SESSION['auth_recovery']['owner'] ?? '');
    if (!$user || ($reauth && ($expected === '' || (string)$user['id'] !== $expected))) {
        $failures = (int)($_SESSION['auth_failures'] ?? 0) + 1;
        $_SESSION['auth_failures'] = $failures;
        if ($failures >= 5) { $_SESSION['auth_retry_at'] = $now + 60; $_SESSION['auth_failures'] = 0; }
        return false;
    }
    $crypto = session_policy_crypto((string)$user['id'], $now, $reauth);
    // Leave a revoked predecessor for concurrent old requests, avoiding a late
    // Set-Cookie from strict-mode recreation overwriting the new login cookie.
    $_SESSION = [];
    if (!session_regenerate_id(false)) return false;
    $_SESSION = ['panel_user'=>$user['id'], 'user_name'=>$user['name'], 'user_role'=>$user['role']];
    if ($crypto !== null) $_SESSION['workspace_crypto'] = $crypto;
    session_policy_start($now);
    csrf_token();
    try { if (is_numeric($user['id'])) db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]); } catch (Exception $e) {}
    return true;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals(csrf_token(), (string) $token)) {
        http_response_code(419);
        die('CSRF');
    }
}

