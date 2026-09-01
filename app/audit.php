<?php

// Минимальный журнал действий. Функция намеренно не ломает бизнес-операцию,
// если миграция журнала ещё не применена или журнал временно недоступен.
function audit_event(string $action, string $section = '', string $entityType = '', ?int $entityId = null,
    string $result = 'started', array $details = []): void
{
    $safe = [];
    foreach ($details as $key => $value) {
        if (!is_scalar($value)) continue;
        $key = preg_replace('/[^a-z0-9_.-]/i', '_', (string) $key);
        if (preg_match('/pass|token|secret|cookie|phone|email|body|text|payload/i', $key)) continue;
        $safe[$key] = mb_substr((string) $value, 0, 200);
    }
    try {
        db()->prepare('INSERT INTO audit_events
            (user_id, actor_name, action, section, entity_type, entity_id, result, details_json, ip, user_agent, request_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
            audit_actor_id(), (string) ($_SESSION['user_name'] ?? 'Неавторизованный пользователь'),
            mb_substr($action, 0, 80), mb_substr($section, 0, 40),
            mb_substr($entityType, 0, 40), $entityId, in_array($result, ['started','success','failure'], true) ? $result : 'started',
            json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            mb_substr((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''), 0, 80),
        ]);
    } catch (Throwable $e) {
        // Журнал не должен превращать обычное действие в ошибку до применения схемы.
    }
}
