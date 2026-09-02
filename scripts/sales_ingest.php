<?php

// Read-only импорт продаж/возвратов из Gmail.
// --dry-run читает письма, но не пишет в БД и не двигает курсор.
require dirname(__DIR__) . '/app/bootstrap.php';
require PANEL_ROOT . '/lib/SalesInboxIngestor.php';

function sales_ingest_env(string $key, string $default = ''): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $env[$k] = $v;
        }
    }
    return (string) ($env[$key] ?? $default);
}
$dryRun = in_array('--dry-run', $argv ?? [], true);
$limit = max(1, min(2000, (int) sales_ingest_env('SALES_IMAP_BATCH_SIZE', '200')));
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(2000, (int) $m[1]));
}

$config = [
    'host' => sales_ingest_env('SALES_IMAP_HOST', 'imap.gmail.com'),
    'port' => (int) sales_ingest_env('SALES_IMAP_PORT', '993'),
    'folder' => sales_ingest_env('SALES_IMAP_MAILBOX', sales_ingest_env('SALES_IMAP_FOLDER', 'INBOX')),
    'user' => sales_ingest_env('SALES_IMAP_USERNAME', sales_ingest_env('SALES_IMAP_USER')),
    'password' => sales_ingest_env('SALES_IMAP_PASSWORD'),
    'lookback_days' => (int) sales_ingest_env('SALES_IMAP_LOOKBACK_DAYS', '30'),
];

try {
    $result = (new SalesInboxIngestor(db(), $config))->run($dryRun, $limit);
    printf("checked=%d imported=%d ignored=%d errors=%d last_uid=%d%s\n",
        $result['checked'], $result['imported'], $result['ignored'], $result['errors'], $result['last_uid'],
        $dryRun ? ' dry-run' : '');
    exit($result['errors'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'sales ingest failed: ' . $e->getMessage() . "\n");
    exit(1);
}
