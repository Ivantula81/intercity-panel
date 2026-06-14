<?php

// Расписание рейса из GDS для ведомости. Времена остановок кешируются:
//  - короткий кеш на ведомость (10 мин, options gds_stops_<id>);
//  - ПОСТОЯННЫЙ справочник расписаний по маршруту (route_schedule) — используется как
//    резерв, если GDS не ответил. Расписание по направлению практически не меняется.

require_once __DIR__ . '/GdsLookup.php';

class GdsRace
{
    // Живой запрос в GDS. Бросает Exception при недоступности/ненайденном рейсе.
    public static function stopsForManifest(array $manifest): array
    {
        $cacheKey = 'gds_stops_' . $manifest['id'];
        $cached = opt($cacheKey);
        if ($cached !== '') {
            $data = json_decode($cached, true);
            if ($data && time() - ($data['at'] ?? 0) < 600) {
                return $data;
            }
        }

        if (!$manifest['departure_at']) {
            throw new RuntimeException('У ведомости нет даты отправления — заполните её в шапке.');
        }

        $route = preg_split('/\s+[—–-]\s+/u', trim($manifest['route']), 2);
        if (count($route) < 2) {
            throw new RuntimeException('Не удалось разобрать маршрут «' . $manifest['route'] . '» на «откуда — куда».');
        }

        $date = date('Y-m-d', strtotime($manifest['departure_at']));
        $lookup = new GdsLookup();
        $found = $lookup->search($date, $route[0], $route[1]);

        $race = null;
        foreach ($found['races'] as $r) {
            if ($r['statement_number'] !== null && (string) $r['statement_number'] === (string) $manifest['trip_number']) {
                $race = $r;
                break;
            }
        }
        if ($race === null && count($found['races']) === 1) {
            $race = $found['races'][0];
        }
        if ($race === null) {
            throw new RuntimeException('GDS не нашёл рейс с номером ведомости ' . $manifest['trip_number'] . ' на ' . $date
                . ' (' . count($found['races']) . ' рейсов по направлению).');
        }

        global $server;
        $rawStops = $server->get_race_stops($race['uid']);
        $rawStops = is_array($rawStops) ? $rawStops : [$rawStops];

        $stops = [];
        if (!empty($race['dispatch_station'])) {
            // id пункта отправления — четвёртая часть uid (…:…:…:dispatch:arrival)
            $uidParts = explode(':', (string) $race['uid']);
            $startCode = isset($uidParts[3]) && ctype_digit($uidParts[3]) ? (int) $uidParts[3] : null;
            $stops[] = [
                'name' => $race['dispatch_station'],
                'norm' => self::norm($race['dispatch_station']),
                'code' => $startCode,
                'arrival' => $race['dispatch_at'],
                'dispatch' => $race['dispatch_at'],
            ];
        }
        foreach ($rawStops as $s) {
            if (!is_object($s) || !isset($s->name)) continue;
            $stops[] = [
                'name' => (string) $s->name,
                'norm' => self::norm((string) $s->name),
                'code' => isset($s->code) && is_numeric((string) $s->code) ? (int) $s->code : null,
                'arrival' => isset($s->arrivalDate) ? (string) $s->arrivalDate : '',
                'dispatch' => isset($s->dispatchDate) ? (string) $s->dispatchDate : '',
            ];
        }

        $data = [
            'at' => time(),
            'race_uid' => $race['uid'],
            'race_start' => $race['dispatch_at'],
            'statement_match' => (string) ($race['statement_number'] ?? '') === (string) $manifest['trip_number'],
            'stops' => $stops,
            'from_cache' => false,
        ];
        opt_set($cacheKey, json_encode($data, JSON_UNESCAPED_UNICODE));
        self::saveRouteSchedule($manifest['route'], $data);
        return $data;
    }

    // Резерв из справочника расписаний: сдвигаем сохранённые времена на дату ведомости.
    public static function cachedStopsForManifest(array $manifest): ?array
    {
        $key = self::routeKey($manifest['route']);
        $st = db()->prepare('SELECT * FROM route_schedule WHERE route_key = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) return null;

        $saved = json_decode($row['stops_json'], true);
        if (!$saved || empty($saved['stops'])) return null;

        $departure = $manifest['departure_at'] ? strtotime($manifest['departure_at']) : time();
        $baseStart = strtotime($saved['race_start']);
        $diffDays = 0;
        if ($baseStart) {
            $diffDays = (int) round((strtotime(date('Y-m-d', $departure)) - strtotime(date('Y-m-d', $baseStart))) / 86400);
        }

        $shift = function ($iso) use ($diffDays) {
            $ts = $iso !== '' ? strtotime($iso) : false;
            return $ts ? date('Y-m-d\TH:i:s', strtotime("+$diffDays day", $ts)) : '';
        };

        $stops = [];
        foreach ($saved['stops'] as $s) {
            $stops[] = [
                'name' => $s['name'],
                'norm' => $s['norm'],
                'code' => $s['code'] ?? null,
                'arrival' => $shift($s['arrival']),
                'dispatch' => $shift($s['dispatch']),
            ];
        }

        return [
            'race_uid' => '',
            'race_start' => $shift($saved['race_start']),
            'statement_match' => false,
            'stops' => $stops,
            'from_cache' => true,
            'cached_at' => $row['updated_at'],
        ];
    }

    private static function saveRouteSchedule(string $route, array $data): void
    {
        $payload = json_encode([
            'race_start' => $data['race_start'],
            'stops' => array_map(fn($s) => [
                'name' => $s['name'], 'norm' => $s['norm'], 'code' => $s['code'] ?? null,
                'arrival' => $s['arrival'], 'dispatch' => $s['dispatch'],
            ], $data['stops']),
        ], JSON_UNESCAPED_UNICODE);

        db()->prepare('INSERT INTO route_schedule (route_key, route, stops_json, updated_at)
            VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE route = VALUES(route), stops_json = VALUES(stops_json), updated_at = NOW()')
            ->execute([self::routeKey($route), $route, $payload]);
    }

    private static function routeKey(string $route): string
    {
        return self::norm($route);
    }

    // Сопоставление: сперва по id станции (code GDS == id из ведомости), затем по названию
    public static function matchStop(array $gds, string $station, ?int $stationId = null): ?array
    {
        if ($stationId !== null) {
            foreach ($gds['stops'] as $s) {
                if (($s['code'] ?? null) === $stationId) return $s;
            }
        }

        $norm = self::norm($station);
        if ($norm === '') return null;

        foreach ($gds['stops'] as $s) {
            if ($s['norm'] === $norm) return $s;
        }
        foreach ($gds['stops'] as $s) {
            if ($s['norm'] !== '' && (str_contains($norm, $s['norm']) || str_contains($s['norm'], $norm))) return $s;
        }
        $city = preg_split('/\s+/u', $norm)[0] ?? '';
        if (mb_strlen($city) >= 4) {
            foreach ($gds['stops'] as $s) {
                if (str_starts_with($s['norm'], $city)) return $s;
            }
        }
        return null;
    }

    public static function norm(string $v): string
    {
        $v = mb_strtolower($v, 'UTF-8');
        $v = str_replace('ё', 'е', $v);
        $v = preg_replace('/[^a-zа-я0-9]+/u', ' ', $v);
        return trim(preg_replace('/\s+/u', ' ', $v));
    }
}
