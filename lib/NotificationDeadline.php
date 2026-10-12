<?php

final class NotificationDeadline
{
    public const EXPIRED = 'Срок отправки истёк: до посадки осталось не больше часа.';

    public static function fromBoarding(string $date, string $time): int
    {
        $value = $date . ' ' . $time;
        $boarding = DateTimeImmutable::createFromFormat('!d.m.Y H:i', $value, new DateTimeZone('Europe/Moscow'));
        if (!$boarding || $boarding->format('d.m.Y H:i') !== $value) {
            throw new InvalidArgumentException('Некорректное время посадки для срока отправки.');
        }
        return $boarding->getTimestamp() - 3600;
    }

    public static function deliveryHash(array $item): string
    {
        return hash('sha256', $item['body'] . '|' . $item['recipient'] . '|' . (string)($item['target'] ?? ''));
    }

    public static function forDelivery(array $payload, string $hash): ?int
    {
        if (isset($payload['deadlines'][$hash])) {
            $value = $payload['deadlines'][$hash];
            if (!is_int($value) || $value <= 0) throw new InvalidArgumentException('Некорректный срок отправки.');
            return $value;
        }
        if (empty($payload['notification_run_id'])) return null; // Free-form broadcasts have no boarding time.
        // Older notification runs already contain structured dates, never parse message text.
        foreach ($payload['deliveries'] ?? [] as $item) {
            if (self::deliveryHash($item) === $hash) {
                return self::fromBoarding((string)($item['date'] ?? ''), (string)($item['time'] ?? ''));
            }
        }
        throw new InvalidArgumentException('Не удалось определить срок уведомления; нужна проверка оператора.');
    }
}
