<?php
// Local operator CLI only; deliberately independent of app/bootstrap.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/BackgroundJobs.php';

function jobs_write_state(string $directory, string $mode, string $owner): void
{
    $path = $directory . '/state-' . bin2hex(random_bytes(8)) . '.tmp';
    $file = @fopen($path, 'x');
    if (!$file) throw new RuntimeException('cannot prepare state');
    try {
        $body = json_encode(['version' => 1, 'mode' => $mode, 'owner' => $owner], JSON_THROW_ON_ERROR) . "\n";
        if (!chmod($path, 0644) || fwrite($file, $body) !== strlen($body) || !fflush($file) || !fsync($file)) {
            throw new RuntimeException('cannot persist state');
        }
        if (!rename($path, $directory . '/state.json')) throw new RuntimeException('cannot publish state');
        // A confirmed pause must survive a reboot. Resume has no fallible step after
        // publishing running: an earlier failure leaves paused, and losing an unflushed
        // resume rename on reboot can only restore the previous, closed admission.
        if ($mode === 'paused') {
            $dir = @fopen($directory, 'r');
            if (!$dir) throw new RuntimeException('cannot open state directory');
            try { if (!fsync($dir)) throw new RuntimeException('cannot persist state directory'); }
            finally { fclose($dir); }
        }
    } finally {
        fclose($file);
        if (is_file($path)) unlink($path);
    }
}

try {
    $command = $argv[1] ?? '';
    $owner = $argv[2] ?? '';
    $timeout = $argv[3] ?? '30';
    if (!in_array($command, ['init', 'status', 'pause', 'resume'], true)
        || ($command !== 'status' && !preg_match('/^[a-f0-9]{32}$/D', $owner))
        || !is_numeric($timeout) || !is_finite((float)$timeout) || (float)$timeout < 0 || (float)$timeout > 3600
        || count($argv) > ($command === 'status' ? 2 : ($command === 'pause' ? 4 : 3))) {
        throw new RuntimeException('usage: jobs_control.php init|pause|resume OWNER_HEX32 [pause timeout_seconds] or status');
    }
    if ($command === 'init') {
        $directory = getenv('PANEL_JOBS_STATE_DIR');
        $directory = $directory === false ? '/var/lib/panel-jobs' : $directory;
        // Never repair/reinitialize an existing directory: replacing locks can admit old workers.
        if ($directory === '' || $directory[0] !== '/' || file_exists($directory) || is_link($directory)
            || !mkdir($directory, 0755)) throw new RuntimeException('init requires a new absolute directory');
        if (!chmod($directory, 0755)) throw new RuntimeException('cannot set directory mode');
        $directory = BackgroundJobs::directory();
        foreach (array_merge(['control', 'workers'], BackgroundJobs::JOBS) as $name) {
            $file = fopen($directory . '/' . $name . '.lock', 'x');
            if (!$file) throw new RuntimeException('cannot provision lock');
            fclose($file);
            if (!chmod($directory . '/' . $name . '.lock', 0644)) throw new RuntimeException('cannot set lock mode');
        }
        jobs_write_state($directory, 'paused', $owner);
        echo "jobs initialized: paused\n";
        exit(0);
    }
    $directory = BackgroundJobs::directory();
    $control = BackgroundJobs::lockFile($directory, 'control');
    if (!flock($control, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "jobs controller busy\n"); exit(75);
    }
    $state = BackgroundJobs::state($directory);
    $workers = BackgroundJobs::lockFile($directory, 'workers');
    if ($command === 'status') {
        $idle = flock($workers, LOCK_EX | LOCK_NB);
        echo json_encode(['mode' => $state['mode'], 'idle' => $idle], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if ($command === 'resume') {
        if ($state['mode'] !== 'paused' || !hash_equals($state['owner'], $owner)) {
            throw new RuntimeException('resume requires current pause owner');
        }
        if (!flock($workers, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "jobs still active; pause retained\n"); exit(75);
        }
        jobs_write_state($directory, 'running', $owner);
        echo "jobs resumed\n"; exit(0);
    }
    if ($state['mode'] === 'paused' && !hash_equals($state['owner'], $owner)) {
        throw new RuntimeException('pause belongs to another operation');
    }
    // Do not reuse the owner of a completed operation (stale resume protection).
    if ($state['mode'] === 'running' && hash_equals($state['owner'], $owner)) {
        throw new RuntimeException('new pause requires a new owner');
    }
    jobs_write_state($directory, 'paused', $owner);
    $deadline = hrtime(true) + (float)$timeout * 1e9;
    do {
        if (flock($workers, LOCK_EX | LOCK_NB)) {
            echo "jobs paused: drained\n"; exit(0);
        }
        if (hrtime(true) >= $deadline) {
            fwrite(STDERR, "jobs drain timed out; pause retained\n"); exit(75);
        }
        usleep(20000);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, 'jobs control failed: ' . $e->getMessage() . "\n");
    exit(78);
}
