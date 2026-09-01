<?php

// Worker outbox рассылок. По умолчанию обрабатывает одну доставку за запуск;
// запускать только после применения schema24 и настройки отдельного cron.
// --dry-run читает очередь, но не вызывает провайдера.
require dirname(__DIR__) . '/app/bootstrap.php';
require PANEL_ROOT . '/app/broadcast_queue.php';
require PANEL_ROOT . '/lib/Channels.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$workerId = 'worker-' . getmypid();
$delivery = BroadcastQueue::claimDelivery($workerId);
if (!$delivery) { echo "empty\n"; exit(0); }

$payload = [];
try {
    $st = db()->prepare('SELECT payload_json,status FROM broadcast_jobs WHERE id=?');
    $st->execute([(int)$delivery['job_id']]);
    $job = $st->fetch() ?: [];
    $payload = json_decode((string)($job['payload_json'] ?? ''), true) ?: [];
} catch (Throwable $e) {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', 'Не удалось прочитать задание');
    fwrite(STDERR, "job read failed\n"); exit(1);
}

if ($dryRun) { BroadcastQueue::finishDelivery((int)$delivery['id'], 'skipped', '', 'dry-run'); echo "dry-run delivery {$delivery['id']}\n"; exit(0); }

$hash = (string)$delivery['body_hash'];
$target = trim((string)($payload['targets'][$hash] ?? $delivery['recipient']));
$text = (string)($payload['bodies'][$hash] ?? '');
if ($target === '' || $text === '') {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', 'В доставке отсутствует подготовленный target/body'); exit(1);
}

$channel = (string)$delivery['channel'];
if (!Channels::ready($channel)) {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', Channels::label($channel) . ': канал недоступен', 300);
    exit(0);
}
$result = Channels::sendText($channel, $target, $text);
if (!empty($result['ok'])) {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'accepted', (string)($result['data']['key']['id'] ?? ''));
    db()->prepare("UPDATE broadcast_jobs j SET status=IF(NOT EXISTS(SELECT 1 FROM broadcast_deliveries d WHERE d.job_id=j.id AND d.status IN ('queued','sending')), 'completed', 'running') WHERE j.id=?")->execute([(int)$delivery['job_id']]);
    echo "accepted {$delivery['id']}\n"; exit(0);
}
$error = substr((string)($result['error'] ?? 'Ошибка провайдера'), 0, 500);
$attempts = (int)$delivery['attempts'];
// Две повторные попытки для временных сбоев, затем финальная ошибка.
$retryable = preg_match('/timeout|timed out|HTTP 429|HTTP 5\d\d|недоступ|лимит/i', $error);
if ($retryable && $attempts < 3) {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', $error, min(900, 60 * $attempts));
} else {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', $error);
}
fwrite(STDERR, "failed {$delivery['id']}\n");
