<?php
// Authentication deadlines are independent of PHP storage cleanup and workspace recovery.
const PANEL_IDLE_SECONDS = 1800;
const PANEL_SHIFT_SECONDS = 43200;
const PANEL_RECOVERY_SECONDS = 604800;

function session_policy_start(int $now): void
{
    $_SESSION['auth_clock'] = ['started' => $now, 'activity' => $now, 'generation' => bin2hex(random_bytes(16))];
    unset($_SESSION['auth_recovery'], $_SESSION['auth_expired']);
}
function session_policy_check(int $now): bool
{
    if (isset($_SESSION['auth_recovery']) && (int)($_SESSION['auth_recovery']['until'] ?? 0) <= $now) unset($_SESSION['auth_recovery']);
    if (empty($_SESSION['panel_user'])) return false;
    $clock = $_SESSION['auth_clock'] ?? [];
    $valid = isset($clock['started'], $clock['activity'], $clock['generation'])
        && is_int($clock['started']) && is_int($clock['activity'])
        && $clock['started'] <= $clock['activity'] && $clock['activity'] <= $now
        && is_string($clock['generation']) && preg_match('/^[a-f0-9]{32}$/D', $clock['generation']);
    if ($valid && $now < $clock['started'] + PANEL_SHIFT_SECONDS && $now < $clock['activity'] + PANEL_IDLE_SECONDS) return true;
    // No access rights in the recovery record. Only successful same-user credentials can reuse it.
    $crypto = $_SESSION['workspace_crypto'] ?? null;
    $_SESSION['auth_recovery'] = ['owner' => (string)$_SESSION['panel_user'], 'until' => $now + PANEL_RECOVERY_SECONDS,
        'crypto' => is_array($crypto) && ($crypto['owner'] ?? '') === (string)$_SESSION['panel_user'] ? $crypto : null];
    $_SESSION['auth_expired'] = !$valid ? 'policy' : ($now >= $clock['started'] + PANEL_SHIFT_SECONDS ? 'absolute' : 'idle');
    unset($_SESSION['panel_user'], $_SESSION['user_name'], $_SESSION['user_role'], $_SESSION['workspace_crypto'], $_SESSION['auth_clock']);
    return false;
}
function session_policy_touch(int $now): bool
{
    if (!session_policy_check($now)) return false;
    $_SESSION['auth_clock']['activity'] = $now;
    return true;
}
function session_policy_crypto(string $owner, int $now, bool $reauth = false): ?array
{
    if ($reauth && (string)($_SESSION['panel_user'] ?? '') === $owner) {
        $crypto = $_SESSION['workspace_crypto'] ?? null;
    } else {
        $recovery = $_SESSION['auth_recovery'] ?? [];
        $crypto = (string)($recovery['owner'] ?? '') === $owner && (int)($recovery['until'] ?? 0) > $now ? ($recovery['crypto'] ?? null) : null;
    }
    return is_array($crypto) && ($crypto['owner'] ?? '') === $owner ? $crypto : null;
}
function session_policy_config(int $now): array
{
    if (!session_policy_check($now)) return ['authenticated'=>false, 'reason'=>$_SESSION['auth_expired'] ?? 'login', 'server_now'=>$now];
    $workspace = workspace_config();
    return ['authenticated'=>true, 'server_now'=>$now,
        'idle_at'=>$_SESSION['auth_clock']['activity'] + PANEL_IDLE_SECONDS,
        'absolute_at'=>$_SESSION['auth_clock']['started'] + PANEL_SHIFT_SECONDS,
        'generation'=>$_SESSION['auth_clock']['generation'], 'scope'=>$workspace['scope'], 'csrf'=>csrf_token()];
}
