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

    /** Все направления одного места отправления стоят рядом. */
    public static function sortByRoute(array $groups): array
    {
        usort($groups, static function (array $a, array $b): int {
            $byDeparture = self::norm((string) ($a['station'] ?? ''))
                <=> self::norm((string) ($b['station'] ?? ''));
            if ($byDeparture !== 0) return $byDeparture;
            return self::norm((string) ($a['destination'] ?? ''))
                <=> self::norm((string) ($b['destination'] ?? ''));
        });
        return $groups;
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
