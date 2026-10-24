<?php
require_once dirname(__DIR__) . '/lib/NotificationDeadline.php';

final class BroadcastQueue
{
    public const STOPPED = 'Остановлено сотрудником до передачи провайдеру.';

    /** One transaction stops the entire selected run; an in-flight request keeps its real result. */
    public static function cancel(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (!$ids || min($ids) < 1) throw new InvalidArgumentException('Задание не найдено.');
        $pdo = db();
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT id,status FROM broadcast_jobs WHERE id IN ($slots) ORDER BY id" . $lock);
            $q->execute($ids);
            if (count($q->fetchAll()) !== count($ids)) throw new InvalidArgumentException('Задание не найдено.');
            $pdo->prepare("UPDATE broadcast_jobs SET status='cancelled' WHERE id IN ($slots) AND status IN ('queued','running','paused')")->execute($ids);
            $q = $pdo->prepare("UPDATE broadcast_deliveries SET status='skipped',last_error=? WHERE job_id IN ($slots) AND status='queued'");
            $q->execute(array_merge([self::STOPPED], $ids));
            $q = $pdo->prepare("SELECT status,last_error FROM broadcast_deliveries WHERE job_id IN ($slots)");
            $q->execute($ids);
            $result = ['stopped'=>0, 'sending'=>0, 'transferred'=>0, 'failed'=>0];
            foreach ($q->fetchAll() as $row) {
                if ($row['status'] === 'skipped' && $row['last_error'] === self::STOPPED) $result['stopped']++;
                elseif ($row['status'] === 'sending') $result['sending']++;
                elseif (in_array($row['status'], ['accepted','delivered','read'], true)) $result['transferred']++;
                elseif ($row['status'] === 'failed') $result['failed']++;
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function dispatchAllowed(int $id): bool
    {
        $q = db()->prepare("SELECT d.id FROM broadcast_deliveries d JOIN broadcast_jobs j ON j.id=d.job_id
            WHERE d.id=? AND d.status='sending' AND j.status IN ('queued','running')");
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

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
        $pdo = db(); $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $q = $pdo->prepare('SELECT status FROM broadcast_jobs WHERE id=?' . $lock);
            $q->execute([$jobId]); $status = $q->fetchColumn();
            if (!$status) throw new InvalidArgumentException('Задание не найдено.');
            $n = $status === 'cancelled' ? 0 : self::insertDeliveries($jobId, $items);
            if ($ownTransaction) $pdo->commit();
            return $n;
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function insertDeliveries(int $jobId, array $items): int
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
            // Use the same lock order as cancel/finish: parent job, then delivery.
            $job = $pdo->query("SELECT j.id FROM broadcast_jobs j WHERE j.status IN ('queued','running')
                AND EXISTS (SELECT 1 FROM broadcast_deliveries d WHERE d.job_id=j.id AND d.status='queued'
                    AND d.available_at<=CURRENT_TIMESTAMP) ORDER BY j.id LIMIT 1")->fetchColumn();
            if (!$job) { $pdo->commit(); return null; }
            // Do not lock the EXISTS scan: InnoDB can acquire child/index locks before
            // the parent. Lock the selected parent by primary key, then recheck it.
            $st = $pdo->prepare('SELECT status FROM broadcast_jobs WHERE id=?' . $lock);
            $st->execute([$job]);
            if (!in_array($st->fetchColumn(), ['queued','running'], true)) { $pdo->commit(); return null; }
            $st = $pdo->prepare("SELECT * FROM broadcast_deliveries WHERE job_id=? AND status='queued'
                AND available_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT 1" . $lock);
            $st->execute([$job]);
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
        $pdo = db();
        $q = $pdo->prepare('SELECT job_id FROM broadcast_deliveries WHERE id=?');
        $q->execute([$id]); $jobId = $q->fetchColumn();
        if (!$jobId) throw new InvalidArgumentException('Доставка не найдена.');
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT status FROM broadcast_jobs WHERE id=?' . $lock);
            $q->execute([$jobId]); $jobStatus = $q->fetchColumn();
            $q = $pdo->prepare('SELECT status FROM broadcast_deliveries WHERE id=?' . $lock);
            $q->execute([$id]);
            // A late callback must not revive stopped work or erase webhook evidence.
            if ($q->fetchColumn() !== 'sending') { $pdo->commit(); return; }
            if ($retrySeconds > 0 && $jobStatus === 'cancelled') {
                $retrySeconds = 0; $status = 'skipped'; $error = self::STOPPED;
            }
            if ($retrySeconds > 0) {
                $pdo->prepare("UPDATE broadcast_deliveries SET status='queued',last_error=?,available_at=? WHERE id=?")
                    ->execute([$error, date('Y-m-d H:i:s', time() + $retrySeconds), $id]);
            } else {
                $pdo->prepare('UPDATE broadcast_deliveries SET status=?,provider_id=?,last_error=?,sent_at=CASE WHEN ? IN ("accepted","delivered","read") THEN COALESCE(sent_at,CURRENT_TIMESTAMP) ELSE sent_at END WHERE id=?')
                    ->execute([$status,$providerId,$error,$status,$id]);
            }
            $q = $pdo->prepare("SELECT COUNT(*) FROM broadcast_deliveries WHERE job_id=? AND status IN ('queued','sending')");
            $q->execute([$jobId]);
            $pdo->prepare("UPDATE broadcast_jobs SET status=? WHERE id=? AND status IN ('queued','running')")
                ->execute([$q->fetchColumn() ? 'running' : 'completed', $jobId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
