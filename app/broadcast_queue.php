<?php
require_once dirname(__DIR__) . '/lib/NotificationDeadline.php';

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
        $conflict = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'ON CONFLICT DO NOTHING' : 'ON DUPLICATE KEY UPDATE id=id';
        $st = $pdo->prepare('INSERT INTO broadcast_jobs
            (idempotency_key,kind,manifest_id,payload_json,created_by)
            VALUES (?,?,?,?,?) ' . $conflict);
        $st->execute([$key, $kind, $manifestId, $json, $userId]);
        $id = $st->rowCount() > 0 ? (int) $pdo->lastInsertId() : 0;
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
        $pdo = db(); $n = 0; $bodies = []; $targets = []; $deadlines = [];
        $conflict = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'ON CONFLICT DO NOTHING' : 'ON DUPLICATE KEY UPDATE id=id';
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
        $st = $pdo->prepare('INSERT INTO broadcast_deliveries
            (job_id,passenger_id,channel,recipient,body_hash,status,available_at)
            VALUES (?,?,?,?,?,"queued",' . $now . ') ' . $conflict);
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $channel = trim((string)($item['channel'] ?? ''));
            $recipient = trim((string)($item['recipient'] ?? ''));
            $body = (string)($item['body'] ?? '');
            if ($channel === '' || $recipient === '' || $body === '') continue;
            // Один и тот же текст бывает у многих пассажиров. Включаем
            // получателя и target, иначе payload map перезапишет адрес и
            // несколько сообщений уйдут одному человеку.
            $hash = NotificationDeadline::deliveryHash($item);
            if (isset($item['expires_at'])) {
                if (!is_int($item['expires_at']) || $item['expires_at'] <= 0) throw new InvalidArgumentException('Некорректный срок отправки.');
                $deadlines[$hash] = $item['expires_at'];
            }
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
            $payload['deadlines'] = array_merge((array)($payload['deadlines'] ?? []), $deadlines);
            $pdo->prepare('UPDATE broadcast_jobs SET payload_json=? WHERE id=?')->execute([json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $jobId]);
        }
        return $n;
    }

    public static function claimDelivery(string $workerId): ?array
    {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            // Never reclaim an ambiguous in-flight send: the provider may already have accepted it.
            $st = $pdo->query("SELECT d.* FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id
                WHERE d.status='queued' AND j.status IN ('queued','running')
                  AND d.available_at <= CURRENT_TIMESTAMP ORDER BY d.id LIMIT 1" . $lock);
            $row = $st->fetch();
            if (!$row) { $pdo->commit(); return null; }
            $up = $pdo->prepare("UPDATE broadcast_deliveries SET status='sending', attempts=attempts+1 WHERE id=?");
            $up->execute([(int)$row['id']]); $pdo->commit(); $row['status']='sending'; $row['attempts']++;
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
        db()->prepare('UPDATE broadcast_deliveries SET status=?,provider_id=?,last_error=?,sent_at=CASE WHEN ? IN ("accepted","delivered","read") THEN COALESCE(sent_at,CURRENT_TIMESTAMP) ELSE sent_at END WHERE id=?')
            ->execute([$status,$providerId,$error,$status,$id]);
    }
}
