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
}
