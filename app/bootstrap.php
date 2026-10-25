<?php

declare(strict_types=1);

// Storage retention permits same-user draft recovery. It does not grant access:
// app/session_policy.php enforces independent idle and absolute deadlines.
ini_set('session.use_strict_mode', '1');
$sessionLifetime = 30 * 24 * 60 * 60;
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
// Do not renew the browser cookie on every poll. Login rotates its ID and lifetime.
date_default_timezone_set('Europe/Moscow');
mb_internal_encoding('UTF-8');

define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/app/workspace.php';
require_once PANEL_ROOT . '/app/session_policy.php';

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

// ── Отписка от рассылок (пассажир написал «стоп») ──
// Команда отписки: ровно «стоп»/«stop» (регистронезависимо, без учёта пробелов).
function is_stop_word(string $body): bool
{
    $b = mb_strtolower(trim($body), 'UTF-8');
    return $b === 'стоп' || $b === 'stop';
}
function is_start_word(string $body): bool
{
    $b = mb_strtolower(trim($body), 'UTF-8');
    return $b === 'старт' || $b === 'start';
}
function is_unsubscribed(string $phone): bool
{
    $phone = trim($phone);
    if ($phone === '') return false;
    try {
        $st = db()->prepare('SELECT unsubscribed_at FROM contacts WHERE phone = ? LIMIT 1');
        $st->execute([$phone]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) { return false; } // колонки может не быть до миграции
}
function set_unsubscribed(string $phone, bool $on): void
{
    $phone = trim($phone);
    if ($phone === '') return;
    try {
        db()->prepare('INSERT INTO contacts (phone) VALUES (?) ON DUPLICATE KEY UPDATE phone = phone')->execute([$phone]);
        db()->prepare('UPDATE contacts SET unsubscribed_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE phone = ?')->execute([$phone]);
    } catch (Throwable $e) { /* до миграции */ }
}

require_once PANEL_ROOT . '/app/authentication.php';

function current_user_name(): string
{
    return $_SESSION['user_name'] ?? 'Администратор';
}

function is_admin(): bool
{
    return ($_SESSION['user_role'] ?? 'operator') === 'admin';
}

/** Server-side authorization guard. Keep permission checks next to the mutation.
 *  The current deployment has only administrators, but this is deliberately
 *  stricter than hiding a button in the UI so operator accounts cannot bypass it.
 */
function require_admin(): void
{
    if (!is_admin()) {
        if (($_GET['p'] ?? '') === 'api' || str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            json_out(['ok' => false, 'error' => 'Только администратор'], 403);
        }
        http_response_code(403);
        die('Недостаточно прав');
    }
}

function audit_actor_id(): ?int
{
    return is_numeric($_SESSION['panel_user'] ?? null) ? (int) $_SESSION['panel_user'] : null;
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Единый источник: метка и фирменный цвет канала сообщения (WhatsApp/MAX/Telegram/SMS/Email).
function msg_channel_meta(string $ch): array
{
    $m = [
        'whatsapp' => ['WhatsApp', '#1faa55'],
        'max'      => ['MAX', '#7c4dff'],
        'telegram' => ['Telegram', '#229ed9'],
        'sms'      => ['SMS', '#e0a900'],
        'email'    => ['Email', '#9a6310'],
    ];
    return $m[$ch] ?? [$ch !== '' ? $ch : '—', '#9a9ab0'];
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

require_once PANEL_ROOT . '/app/audit.php';
