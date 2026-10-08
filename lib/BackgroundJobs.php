<?php
// No bootstrap, database, environment file or provider access in this module.
final class BackgroundJobs
{
    public const JOBS = [
        'broadcast_worker', 'broadcast_report_worker', 'sales_ingest',
        'backfill_conversations', 'backfill_contacts', 'sales_seed', 'import_dryrun',
        'backfill_chat_ids', 'clean_failed_convs', 'merge_conv_dups',
        'reporting_agents_fix', 'reporting_reset',
    ];
    private static array $leases = [];

    public static function directory(): string
    {
        $path = getenv('PANEL_JOBS_STATE_DIR');
        $path = $path === false ? '/var/lib/panel-jobs' : $path;
        if ($path === '' || $path[0] !== '/' || realpath($path) !== $path || !is_dir($path)) {
            throw new RuntimeException('jobs state directory unavailable');
        }
        $stat = stat($path);
        if (!$stat || ($stat['mode'] & 0022) !== 0) {
            throw new RuntimeException('jobs directory must not be group/world writable');
        }
        return $path;
    }

    // Lock files are provisioned once, outside releases. Never unlink/replace them.
    public static function lockFile(string $directory, string $name)
    {
        $path = $directory . '/' . $name . '.lock';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || $before['uid'] !== stat($directory)['uid']) {
            throw new RuntimeException('jobs lock unavailable');
        }
        $handle = @fopen($path, 'r');
        $opened = $handle ? fstat($handle) : false;
        if (!$opened || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']) {
            if ($handle) fclose($handle);
            throw new RuntimeException('jobs lock changed');
        }
        return $handle;
    }

    public static function state(string $directory): array
    {
        $path = $directory . '/state.json';
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0022) !== 0 || $stat['uid'] !== stat($directory)['uid']) {
            throw new RuntimeException('jobs state unavailable');
        }
        $raw = @file_get_contents($path);
        $state = $raw === false ? null : json_decode($raw, true);
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || !in_array($state['mode'] ?? '', ['paused', 'running'], true)
            || !is_string($state['owner'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $state['owner'])) {
            throw new RuntimeException('jobs state invalid');
        }
        return $state;
    }

    // A successful admission retains both locks until PHP finishes, including shutdown callbacks.
    public static function enter(string $job): void
    {
        if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
        try {
            if (!in_array($job, self::JOBS, true)) throw new RuntimeException('unknown job');
            $directory = self::directory();
            $all = self::lockFile($directory, 'workers');
            if (!flock($all, LOCK_SH | LOCK_NB)) { fclose($all); self::skip('draining'); }
            $state = self::state($directory);
            if ($state['mode'] !== 'running') { fclose($all); self::skip('paused'); }
            $single = self::lockFile($directory, $job);
            if (!flock($single, LOCK_EX | LOCK_NB)) {
                fclose($single); fclose($all); self::skip('already-running');
            }
            self::$leases[] = [$all, $single];
        } catch (Throwable $e) {
            fwrite(STDERR, "jobs unavailable; no work started\n");
            exit(78);
        }
    }

    private static function skip(string $reason): void
    {
        fwrite(STDOUT, 'jobs skipped: ' . $reason . "\n");
        exit(75);
    }
}
