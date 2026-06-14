<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') === '443',
        'samesite' => 'Lax',
    ]);
    session_start();
}
date_default_timezone_set('Europe/Moscow');
mb_internal_encoding('UTF-8');

define('PANEL_ROOT', dirname(__DIR__));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pass = '';
        foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'DB_PASS=')) {
                $pass = substr($line, 8);
            }
        }
        $pdo = new PDO('mysql:host=localhost;dbname=panel;charset=utf8mb4', 'panel', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function opt(string $name, string $default = ''): string
{
    $st = db()->prepare('SELECT value FROM options WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function opt_set(string $name, string $value): void
{
    db()->prepare('INSERT INTO options (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

function logged_in(): bool
{
    return !empty($_SESSION['panel_user']);
}

function require_login(): void
{
    if (!logged_in()) {
        header('Location: /?p=login');
        exit;
    }
}

// Вход по логину+паролю. Возвращает true и наполняет сессию при успехе.
function authenticate(string $login, string $password): bool
{
    $login = trim($login);
    try {
        $st = db()->prepare('SELECT * FROM users WHERE login = ? AND active = 1');
        $st->execute([$login]);
        $u = $st->fetch();
    } catch (Exception $e) {
        $u = null; // таблицы users может не быть до применения schema5
    }

    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true); // против фиксации сессии
        $_SESSION['panel_user'] = (int) $u['id'];
        $_SESSION['user_name'] = $u['name'];
        $_SESSION['user_role'] = $u['role'];
        try { db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]); } catch (Exception $e) {}
        return true;
    }

    // запасной вход на случай, если users ещё не созданы (миграция).
    // Пароль — только из окружения (ADMIN_FALLBACK_PASSWORD); по умолчанию выключен.
    $fallbackPw = getenv('ADMIN_FALLBACK_PASSWORD') ?: '';
    if ($fallbackPw === '' && is_readable('/etc/panel.env')) {
        foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__envLine) {
            if (str_starts_with($__envLine, 'ADMIN_FALLBACK_PASSWORD=')) { $fallbackPw = substr($__envLine, strlen('ADMIN_FALLBACK_PASSWORD=')); break; }
        }
    }
    if ($u === null && $fallbackPw !== '' && $login === 'admin' && $password === $fallbackPw) {
        $_SESSION['panel_user'] = 'admin';
        $_SESSION['user_name'] = 'Администратор';
        $_SESSION['user_role'] = 'admin';
        return true;
    }
    return false;
}

function current_user_name(): string
{
    return $_SESSION['user_name'] ?? 'Администратор';
}

function is_admin(): bool
{
    return ($_SESSION['user_role'] ?? 'operator') === 'admin';
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

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function view(string $name, array $vars = []): void
{
    extract($vars);
    require PANEL_ROOT . '/app/views/' . $name . '.php';
}

function flash(?string $set = null): string
{
    if ($set !== null) {
        $_SESSION['flash'] = $set;
        return '';
    }
    $f = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $f;
}
