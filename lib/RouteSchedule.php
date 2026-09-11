<?php

/** Calendar times, not durations: all schedule days are relative to the Moscow departure date. */
final class RouteSchedule
{
    public static function norm(string $value): string
    {
        $value = mb_strtolower(str_replace('ё', 'е', trim($value)), 'UTF-8');
        $value = preg_replace('/[—–]/u', '-', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function time(string $value): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
            throw new InvalidArgumentException('Время должно быть в формате ЧЧ:ММ (00:00–23:59).');
        }
        return $value;
    }

    public static function key(string $route, string $start): string
    {
        if (trim($route) === '') throw new InvalidArgumentException('Не указано название маршрута.');
        return hash('sha256', self::norm($route) . '|' . self::time($start));
    }

    public static function stopKey(array $stop): string
    {
        return !empty($stop['station_id']) ? 'id:' . (int) $stop['station_id'] : 'name:' . self::norm($stop['station']);
    }

    public static function date(string $date): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Moscow'));
        if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Некорректная дата выезда.');
        return $d;
    }

    public static function relative(string $when, string $base): ?array
    {
        if ($when === '') return null;
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::\d{2})?$/D', $when, $m)) return null;
        self::time($m[2]);
        return ['time' => $m[2], 'day' => (int) self::date($base)->diff(self::date($m[1]))->format('%r%a')];
    }

    public static function absolute(array $stop, string $base): array
    {
        return ['date' => self::date($base)->modify(sprintf('%+d days', $stop['day']))->format('d.m.Y'), 'time' => $stop['time']];
    }

    /** Never join two different IDs just because their city names look similar. */
    public static function match(array $stops, array $needle): ?array
    {
        $key = self::stopKey($needle);
        $matches = array_values(array_filter($stops, fn($s) => self::stopKey($s) === $key || in_array($key, $s['aliases'] ?? [], true)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function validate(array $stops): array
    {
        if (!$stops || count($stops) > 500) throw new InvalidArgumentException('Нужно от 1 до 500 остановок.');
        $out = []; $keys = [];
        foreach ($stops as $s) {
            if (!is_array($s)) throw new InvalidArgumentException('Некорректная остановка.');
            $name = trim((string) ($s['station'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) throw new InvalidArgumentException('Укажите название остановки (до 255 символов).');
            $id = $s['station_id'] ?? null;
            if ($id !== null && $id !== '' && (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1)) {
                throw new InvalidArgumentException('Некорректный ID станции.');
            }
            $day = filter_var($s['day'] ?? null, FILTER_VALIDATE_INT);
            if ($day === false || $day < 0 || $day > 30) throw new InvalidArgumentException('День должен быть от 0 до 30 относительно выезда.');
            $terminal = !empty($s['terminal']);
            $time = trim((string) ($s['time'] ?? ''));
            if (!$terminal || $time !== '') self::time($time);
            $aliases = array_values(array_unique((array) ($s['aliases'] ?? [])));
            if (count($aliases) > 10) throw new InvalidArgumentException('Слишком много привязок остановки.');
            foreach ($aliases as $alias) {
                if (!is_string($alias) || !preg_match('/^(id:[1-9]\d*|name:.+)$/uD', $alias) || mb_strlen($alias) > 260) {
                    throw new InvalidArgumentException('Некорректная привязка остановки.');
                }
            }
            $stop = ['station' => $name, 'station_id' => $id ? (int) $id : null,
                'time' => $time, 'day' => $day, 'terminal' => $terminal, 'aliases' => $aliases];
            $key = self::stopKey($stop);
            foreach (array_unique([$key, ...$aliases]) as $identity) {
                if (isset($keys[$identity])) throw new InvalidArgumentException('Остановка или её привязка указана дважды: ' . $name);
                $keys[$identity] = true;
            }
            $out[] = $stop;
        }
        return $out;
    }

    public static function fromGds(array $data, string $base): array
    {
        $out = [];
        foreach ($data['stops'] as $s) {
            $depart = self::relative($s['dispatch'] ?? '', $base);
            $arrival = self::relative($s['arrival'] ?? '', $base);
            $stop = ['station' => trim($s['name']), 'station_id' => $s['code'] ?? null,
                'time' => $depart['time'] ?? '', 'day' => $depart['day'] ?? $arrival['day'] ?? 0,
                'arrival' => $arrival, 'terminal' => !empty($s['terminal'])];
            if ($stop['terminal']) $stop['time'] = '';
            $out[] = $stop;
        }
        return $out;
    }

    public static function fromPassengers(array $passengers, string $base): array
    {
        $out = [];
        foreach ($passengers as $p) {
            $s = ['station' => trim($p['from']), 'station_id' => $p['from_id'] ?? null, 'time' => '', 'day' => 0, 'terminal' => false];
            if ($s['station'] === '') continue;
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}:\d{2})(?::\d{2})?$/D', trim($p['depart_at'] ?? ''), $m)) {
                try { $s = array_replace($s, self::relative("$m[3]-$m[2]-$m[1] $m[4]", $base) ?? []); }
                catch (InvalidArgumentException $e) { $s['conflict'] = true; }
            }
            $key = self::stopKey($s);
            if (isset($out[$key])) {
                if ($out[$key]['time'] !== $s['time'] || $out[$key]['day'] !== $s['day']) $out[$key]['conflict'] = true;
            } else $out[$key] = $s;
        }
        return array_values($out);
    }

    /** Applied snapshots are immutable until an explicit apply action. */
    public static function overlay(array $groups, array $state, string $base): array
    {
        foreach ($groups as &$g) {
            $key = self::stopKey($g);
            $saved = self::match($state['applied'], $g);
            $override = $state['overrides'][$key] ?? null;
            $g['time_source'] = 'unverified';
            $source = self::match($state['source'] ?? [], $g);
            if ($source && $source['time'] !== '') $g = array_replace($g, self::absolute($source, $base));
            if ($saved && !$saved['terminal']) {
                $g = array_replace($g, self::absolute($saved, $base));
                $g['time_source'] = 'schedule'; $g['time_warning'] = 0;
            }
            if ($override) {
                $g = array_replace($g, $override);
                $g['time_source'] = 'override'; $g['time_warning'] = 0;
            }
            if ($g['time_source'] === 'unverified') {
                $origin = preg_split('/\s+[—–-]\s+/u', $state['route'] ?? '', 2)[0];
                if (!empty($source['conflict']) || ($g['time'] !== '' && $g['time'] === ($state['start_time'] ?? null)
                    && self::norm($g['station']) !== self::norm($origin) && ($source['day'] ?? 0) === 0)) $g['time_warning'] = 1;
            }
            $g['schedule_revision'] = $state['revision'];
        }
        unset($g);
        return $groups;
    }
}
