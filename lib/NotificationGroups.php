<?php

final class NotificationGroups
{
    /** Стабильный ключ группы: точная пара посадка -> высадка. */
    public static function key(?int $fromId, string $from, ?int $toId, string $to): string
    {
        return self::stopKey($fromId, $from) . '>' . self::stopKey($toId, $to);
    }

    public static function draftKey(string $from, string $to): string
    {
        return self::norm($from) . '>' . self::norm($to);
    }

    public static function matches(array $group, string $from, string $to): bool
    {
        return self::norm((string) ($group['station'] ?? '')) === self::norm($from)
            && self::norm((string) ($group['destination'] ?? '')) === self::norm($to);
    }

    private static function stopKey(?int $id, string $name): string
    {
        return $id !== null ? 'id' . $id : 'nm' . self::norm($name);
    }

    private static function norm(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
