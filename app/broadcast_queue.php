<?php

final class BroadcastQueue
{
    public static function enqueue(string $kind, int $manifestId, array $payload, ?int $userId = null): array
    {
        if (!in_array($kind, ['campaign', 'broadcast', 'single'], true)) {
            throw new InvalidArgumentException('Неизвестный тип задания.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $key = hash('sha256', $kind . '|' . $manifestId . '|' . $json);
        $pdo = db();
        $st = $pdo->prepare('INSERT INTO broadcast_jobs
            (idempotency_key,kind,manifest_id,payload_json,created_by)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        $st->execute([$key, $kind, $manifestId, $json, $userId]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $q = $pdo->prepare('SELECT id,status FROM broadcast_jobs WHERE idempotency_key=?');
            $q->execute([$key]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('Не удалось создать задание очереди.');
            return ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'duplicate' => true];
        }
        return ['id' => $id, 'status' => 'queued', 'duplicate' => false];
    }

    public static function claim(string $workerId): ?array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->query("SELECT * FROM broadcast_jobs
                WHERE (status='queued' OR (status='running' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
                  AND available_at <= NOW() ORDER BY id LIMIT 1 FOR UPDATE");
            $job = $st->fetch();
            if (!$job) { $pdo->commit(); return null; }
            $up = $pdo->prepare("UPDATE broadcast_jobs SET status='running', attempts=attempts+1, locked_at=NOW(), locked_by=? WHERE id=?");
            $up->execute([$workerId, $job['id']]);
            $pdo->commit();
            $job['status'] = 'running';
            return $job;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // Создаёт deliveries из уже подготовленного payload. Payload формируется
    // панелью после проверки получателей; worker не делает повторных CheckAccount.
    public static function materializeDeliveries(int $jobId, array $items): int
    {
        $pdo = db(); $n = 0; $bodies = []; $targets = [];
        $st = $pdo->prepare('INSERT INTO broadcast_deliveries
            (job_id,passenger_id,channel,recipient,body_hash,status,available_at)
            VALUES (?,?,?,?,?,"queued",NOW()) ON DUPLICATE KEY UPDATE id=id');
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $channel = trim((string)($item['channel'] ?? ''));
            $recipient = trim((string)($item['recipient'] ?? ''));
            $body = (string)($item['body'] ?? '');
            if ($channel === '' || $recipient === '' || $body === '') continue;
            $hash = hash('sha256', $body);
            $st->execute([$jobId, !empty($item['passenger_id']) ? (int)$item['passenger_id'] : null,
                $channel, $recipient, $hash]);
            $bodies[$hash] = $body;
            if (!empty($item['target'])) $targets[$hash] = (string)$item['target'];
            $n += $st->rowCount() > 0 ? 1 : 0;
        }
        if ($bodies) {
            $q = $pdo->prepare('SELECT payload_json FROM broadcast_jobs WHERE id=?'); $q->execute([$jobId]);
            $payload = json_decode((string)$q->fetchColumn(), true) ?: [];
            $payload['bodies'] = array_merge((array)($payload['bodies'] ?? []), $bodies);
            $payload['targets'] = array_merge((array)($payload['targets'] ?? []), $targets);
            $pdo->prepare('UPDATE broadcast_jobs SET payload_json=? WHERE id=?')->execute([json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $jobId]);
        }
        return $n;
    }

    public static function claimDelivery(string $workerId): ?array
    {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $st = $pdo->query("SELECT * FROM broadcast_deliveries
                WHERE (status='queued' OR (status='sending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
                  AND available_at <= NOW() ORDER BY id LIMIT 1 FOR UPDATE");
            $row = $st->fetch();
            if (!$row) { $pdo->commit(); return null; }
            $up = $pdo->prepare("UPDATE broadcast_deliveries SET status='sending', attempts=attempts+1 WHERE id=?");
            $up->execute([(int)$row['id']]); $pdo->commit(); $row['status']='sending';
            return $row;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function finishDelivery(int $id, string $status, string $providerId = '', string $error = '', int $retrySeconds = 0): void
    {
        $allowed = ['accepted','delivered','read','failed','skipped'];
        if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('Недопустимый статус доставки.');
        if ($retrySeconds > 0) {
            $available = date('Y-m-d H:i:s', time() + $retrySeconds);
            db()->prepare("UPDATE broadcast_deliveries SET status='queued',last_error=?,available_at=? WHERE id=?")
                ->execute([$error, $available, $id]);
            return;
        }
        db()->prepare('UPDATE broadcast_deliveries SET status=?,provider_id=?,last_error=?,sent_at=IF(? IN ("accepted","delivered","read"),COALESCE(sent_at,NOW()),sent_at) WHERE id=?')
            ->execute([$status,$providerId,$error,$status,$id]);
    }
}
