<?php
require_once __DIR__ . '/NotificationDeadline.php';

// One attempt; dependencies are injected so outage/deadline cases never send real messages in tests.
final class NotificationDispatch
{
    public static function attempt($client, string $target, string $text, ?int $deadline, ?callable $clock = null, ?callable $allowed = null): array
    {
        $clock ??= fn() => time();
        $expired = fn() => $deadline !== null && $clock() >= $deadline;
        $skip = ['status' => 'skipped', 'error' => NotificationDeadline::EXPIRED];
        $stopped = ['status' => 'skipped', 'error' => 'Остановлено сотрудником до передачи провайдеру.'];
        if ($allowed && !$allowed()) return $stopped;
        if ($expired()) return $skip;
        if (!$client || !$client->isConfigured()) {
            return ['status' => 'failed', 'error' => 'Канал не настроен; проверьте настройки.'];
        }
        $state = $client->connectionState();
        if ($allowed && !$allowed()) return $stopped;
        if ($expired()) return $skip; // A slow health request must never let a late send through.
        if (empty($state['ok']) || ($state['state'] ?? '') !== 'open') {
            return ['status' => 'queued', 'error' => 'Ожидает восстановления связи с каналом.',
                'retry_seconds' => $deadline === null ? 300 : max(1, min(300, $deadline - $clock()))];
        }
        // After this final check the request is in flight; cancellation cannot recall it.
        if ($allowed && !$allowed()) return $stopped;
        $result = $client->sendText($target, $text);
        $id = (string)($result['data']['key']['id'] ?? '');
        if (!empty($result['ok']) && $id !== '') return ['status' => 'accepted', 'provider_id' => $id];
        // A lost response can hide an accepted send. Do not automatically duplicate it.
        return ['status' => 'failed', 'error' => 'Отправка не подтверждена. Проверьте результат перед повтором.'];
    }
}
