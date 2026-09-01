<?php

// Worker outbox рассылок. По умолчанию обрабатывает одну доставку за запуск;
// запускать только после применения schema24 и настройки отдельного cron.
// --dry-run читает очередь, но не вызывает провайдера.
require dirname(__DIR__) . '/app/bootstrap.php';
require PANEL_ROOT . '/app/broadcast_queue.php';
require PANEL_ROOT . '/lib/Channels.php';
require PANEL_ROOT . '/app/conversations.php';

function worker_record_message(array $delivery, array $job, string $providerId, string $body): void {
    $pdo = db();
    $name = '';
    if (!empty($delivery['passenger_id'])) {
        $st = $pdo->prepare('SELECT name FROM passengers WHERE id=?'); $st->execute([(int)$delivery['passenger_id']]);
        $name = (string)($st->fetchColumn() ?: '');
    }
    $st = $pdo->prepare('SELECT id FROM messages WHERE wa_id=? AND channel=? LIMIT 1');
    $st->execute([$providerId, (string)$delivery['channel']]);
    $mid = (int)($st->fetchColumn() ?: 0);
    if (!$mid) {
        $st = $pdo->prepare('INSERT INTO messages (manifest_id,channel,recipient,passenger_name,body,actor,status,sent_at,wa_id) VALUES (?,?,?,?,?,? ,"sent",NOW(),?)');
        $st->execute([(int)($job['manifest_id'] ?? 0), (string)$delivery['channel'], (string)$delivery['recipient'], $name, $body, 'Очередь рассылок', $providerId]);
        $mid = (int)$pdo->lastInsertId();
    }
    $account = (string)$delivery['channel'] === 'telegram' ? 'greenapi_tg' : 'greenapi';
    $cid = conversation_ensure(['channel'=>(string)$delivery['channel'],'account'=>$account,
        'external_chat_id'=>(string)$delivery['target'],'phone'=>(string)$delivery['recipient'],'name'=>$name,
        'manifest_id'=>(int)($job['manifest_id'] ?? 0)]);
    conversation_append_legacy('messages', $mid, $cid);
}

function worker_env(string $key): string {
    static $e = null;
    if ($e === null) { $e=[]; foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $l) { [$k,$v]=array_pad(explode('=',$l,2),2,''); $e[$k]=$v; } }
    return (string)($e[$key] ?? '');
}
function worker_client(string $channel) {
    if ($channel === 'max') { require_once PANEL_ROOT.'/lib/GreenApiClient.php'; return new GreenApiClient(worker_env('GREENAPI_URL'), worker_env('GREENAPI_ID'), worker_env('GREENAPI_TOKEN'), 'max'); }
    if ($channel === 'telegram') { require_once PANEL_ROOT.'/lib/GreenApiClient.php'; return new GreenApiClient(worker_env('GREENAPI_TG_URL') ?: worker_env('GREENAPI_URL'), worker_env('GREENAPI_TG_ID'), worker_env('GREENAPI_TG_TOKEN'), 'telegram'); }
    if ($channel === 'whatsapp') { require_once PANEL_ROOT.'/lib/GreenApiClient.php'; if (worker_env('GREENAPI_WA_ID') !== '') return new GreenApiClient(worker_env('GREENAPI_WA_URL') ?: worker_env('GREENAPI_URL'), worker_env('GREENAPI_WA_ID'), worker_env('GREENAPI_WA_TOKEN'), 'whatsapp'); }
    return null;
}

$dryRun = in_array('--dry-run', $argv ?? [], true);
$workerId = 'worker-' . getmypid();
$delivery = BroadcastQueue::claimDelivery($workerId);
if (!$delivery) { echo "empty\n"; exit(0); }

$payload = [];
try {
    $st = db()->prepare('SELECT payload_json,status,manifest_id FROM broadcast_jobs WHERE id=?');
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
$client = worker_client($channel);
if (!$client || !$client->isConfigured() || empty(($state = $client->connectionState())['ok']) || ($state['state'] ?? '') !== 'open') {
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'failed', '', $channel . ': канал недоступен', 300);
    exit(0);
}
$result = $client->sendText($target, $text);
if (!empty($result['ok'])) {
    $providerId = (string)($result['data']['key']['id'] ?? '');
    BroadcastQueue::finishDelivery((int)$delivery['id'], 'accepted', $providerId);
    $delivery['target'] = $target;
    worker_record_message($delivery, $job, $providerId, $text);
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
