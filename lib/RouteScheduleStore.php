<?php
require_once __DIR__ . '/RouteSchedule.php';

final class ScheduleConflict extends RuntimeException {}

final class RouteScheduleStore
{
    public function __construct(private PDO $pdo) {}

    public function available(): bool
    {
        try {
            foreach (['verified_route_schedules', 'manifest_schedule_state', 'route_schedule_events'] as $table) {
                $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            }
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table')) return false;
            throw $e;
        }
    }

    private function one(string $sql, array $params): ?array
    {
        $q = $this->pdo->prepare($sql); $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function schedule(string $key): ?array
    {
        $r = $this->one('SELECT * FROM verified_route_schedules WHERE schedule_key=?', [$key]);
        if ($r) { $r['stops'] = json_decode($r['stops_json'], true, 512, JSON_THROW_ON_ERROR); unset($r['stops_json']); $r['version'] = (int) $r['version']; }
        return $r;
    }

    public function listing(): array
    {
        return $this->pdo->query('SELECT schedule_key,route,start_time,version,updated_at FROM verified_route_schedules ORDER BY route,start_time')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Raw legacy times are retained as evidence; never replace them with a schedule. */
    private function source(array $manifest): array
    {
        $ids = [];
        $p = $this->pdo->prepare('SELECT DISTINCT from_stop,from_id FROM passengers WHERE manifest_id=?');
        $p->execute([$manifest['id']]);
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $s) if ($s['from_id']) $ids[$s['from_stop']][(int) $s['from_id']] = true;
        $q = $this->pdo->prepare("SELECT station,station_id,boarding_date,boarding_time FROM manifest_groups WHERE manifest_id=? ORDER BY CASE WHEN destination='' THEN 0 ELSE 1 END");
        $q->execute([$manifest['id']]); $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!$r['station_id'] && count($ids[$r['station']] ?? []) === 1) $r['station_id'] = array_key_first($ids[$r['station']]);
            $s = ['station' => $r['station'], 'station_id' => $r['station_id'] ? (int) $r['station_id'] : null,
                'time' => $r['boarding_time'] ?? '', 'day' => 0, 'terminal' => false];
            $key = RouteSchedule::stopKey($s);
            $d = DateTimeImmutable::createFromFormat('!d.m.Y', $r['boarding_date'] ?? '');
            if ($d && $d->format('d.m.Y') === $r['boarding_date']) {
                $s['day'] = (int) RouteSchedule::date(substr($manifest['departure_at'], 0, 10))->diff(RouteSchedule::date($d->format('Y-m-d')))->format('%r%a');
            }
            if (isset($out[$key])) {
                if ($out[$key]['time'] !== $s['time'] || $out[$key]['day'] !== $s['day']) $out[$key]['conflict'] = true;
            } else $out[$key] = $s;
        }
        return array_values($out);
    }

    public function context(array $manifest): array
    {
        if (empty($manifest['departure_at'])) throw new InvalidArgumentException('Заполните дату и плановое время отправления в шапке ведомости.');
        $row = $this->one('SELECT * FROM manifest_schedule_state WHERE manifest_id=?', [$manifest['id']]);
        $route = $row['route'] ?? $manifest['route'];
        if (RouteSchedule::norm($route) !== RouteSchedule::norm($manifest['route'])) {
            throw new InvalidArgumentException('Название маршрута изменилось после импорта. Загрузите правильную ведомость для настройки другого расписания.');
        }
        $start = $row['start_time'] ?? substr($manifest['departure_at'], 11, 5);
        $key = RouteSchedule::key($route, $start);
        $state = ['route' => $route, 'start_time' => $start, 'revision' => (int) ($row['revision'] ?? 0), 'schedule_version' => (int) ($row['schedule_version'] ?? 0),
            'source' => $row ? json_decode($row['source_json'], true, 512, JSON_THROW_ON_ERROR) : $this->source($manifest),
            'applied' => $row ? json_decode($row['applied_json'], true, 512, JSON_THROW_ON_ERROR) : [],
            'overrides' => $row ? json_decode($row['overrides_json'], true, 512, JSON_THROW_ON_ERROR) : [],
            'source_kind' => $row['source_kind'] ?? 'legacy'];
        $q = $this->pdo->prepare('SELECT DISTINCT from_stop AS station,from_id AS station_id FROM passengers WHERE manifest_id=?');
        $q->execute([$manifest['id']]); $boarding = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($boarding as &$s) {
            $s['station_id'] = $s['station_id'] ? (int) $s['station_id'] : null;
            if (!RouteSchedule::match($state['source'], $s)) {
                $state['source'][] = $s + ['time' => '', 'day' => 0, 'terminal' => false];
            }
        }
        unset($s);
        return ['manifest_id' => (int) $manifest['id'], 'trip_number' => $manifest['trip_number'],
            'base_date' => substr($manifest['departure_at'], 0, 10), 'route' => $route, 'start_time' => $start,
            'schedule_key' => $key, 'schedule' => $this->schedule($key), 'state' => $state, 'boarding' => $boarding];
    }

    private function transaction(callable $fn): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try { $result = $fn(); if ($owns) $this->pdo->commit(); return $result; }
        catch (Throwable $e) { if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function writeState(array $c, ?int $user): void
    {
        $s = $c['state'];
        $values = [$c['schedule_key'], $c['route'], $c['start_time'], $s['source_kind'], self::json($s['source']),
            self::json($s['applied']), $s['schedule_version'], self::json($s['overrides']), $s['revision'] + 1, $user];
        if ($s['revision'] === 0) {
            try {
                $this->pdo->prepare('INSERT INTO manifest_schedule_state (schedule_key,route,start_time,source_kind,source_json,applied_json,schedule_version,overrides_json,revision,updated_by,manifest_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$values, $c['manifest_id']]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new ScheduleConflict('Ведомость уже изменена. Обновите страницу и повторите правку.');
                throw $e;
            }
        } else {
            $q = $this->pdo->prepare('UPDATE manifest_schedule_state SET schedule_key=?,route=?,start_time=?,source_kind=?,source_json=?,applied_json=?,schedule_version=?,overrides_json=?,revision=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE manifest_id=? AND revision=?');
            $q->execute([...$values, $c['manifest_id'], $s['revision']]);
            if ($q->rowCount() !== 1) throw new ScheduleConflict('Ведомость уже изменена. Обновите страницу и повторите правку.');
        }
    }

    private function event(array $c, string $action, int $version, ?int $user): void
    {
        $this->pdo->prepare('INSERT INTO route_schedule_events (schedule_key,manifest_id,user_id,action,version) VALUES (?,?,?,?,?)')
            ->execute([$c['schedule_key'], $c['manifest_id'] ?? null, $user, $action, $version]);
    }

    public function initialize(array $manifest, ?int $user, ?string $plannedStart = null, ?array $source = null): void
    {
        if (!$this->available() || !$manifest['departure_at'] || $plannedStart === null) return;
        $this->transaction(function () use ($manifest, $user, $plannedStart, $source) {
            $c = $this->context($manifest);
            if ($c['state']['revision']) return;
            $c['start_time'] = RouteSchedule::time($plannedStart);
            $c['schedule_key'] = RouteSchedule::key($c['route'], $plannedStart);
            $schedule = $this->schedule($c['schedule_key']);
            $c['state']['source_kind'] = 'csv';
            if ($source !== null) $c['state']['source'] = $source;
            $c['state']['applied'] = $schedule['stops'] ?? [];
            $c['state']['schedule_version'] = $schedule['version'] ?? 0;
            $this->writeState($c, $user);
            $this->event($c, 'import', $c['state']['schedule_version'], $user);
        });
    }

    public function save(string $route, string $start, array $stops, int $expected, ?int $user,
        ?array $manifest = null, ?int $revision = null): array
    {
        $stops = RouteSchedule::validate($stops); $key = RouteSchedule::key($route, $start);
        $origin = preg_split('/\s+[—–-]\s+/u', trim($route), 2)[0];
        foreach ($stops as $s) {
            if (RouteSchedule::norm($s['station']) === RouteSchedule::norm($origin)
                && ($s['time'] !== $start || $s['day'] !== 0 || $s['terminal'])) {
                throw new InvalidArgumentException('Время начальной остановки должно совпадать с плановым стартом, день — 0. Для другого старта создайте отдельное расписание.');
            }
        }
        if (mb_strlen($route) > 255) throw new InvalidArgumentException('Название маршрута слишком длинное.');
        return $this->transaction(function () use ($route, $start, $stops, $key, $expected, $user, $manifest, $revision) {
            $version = $expected + 1;
            if ($expected === 0) {
                try {
                    $this->pdo->prepare('INSERT INTO verified_route_schedules (schedule_key,route,start_time,stops_json,version,updated_by) VALUES (?,?,?,?,?,?)')
                        ->execute([$key, $route, $start, self::json($stops), $version, $user]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') throw new ScheduleConflict('Расписание уже создано другим сотрудником. Обновите страницу; ваши поля не изменены.');
                    throw $e;
                }
            } else {
                $q = $this->pdo->prepare('UPDATE verified_route_schedules SET stops_json=?,version=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE schedule_key=? AND version=?');
                $q->execute([self::json($stops), $version, $user, $key, $expected]);
                if ($q->rowCount() !== 1) throw new ScheduleConflict('Расписание изменено другим сотрудником. Обновите страницу; ваши поля не изменены.');
            }
            $this->event(['schedule_key' => $key], 'save', $version, $user);
            if ($manifest) $this->apply($manifest, $key, $version, $revision, $user);
            return $this->schedule($key);
        });
    }

    public function apply(array $manifest, string $key, int $version, ?int $revision, ?int $user): void
    {
        $this->transaction(function () use ($manifest, $key, $version, $revision, $user) {
            $c = $this->context($manifest);
            if ($c['schedule_key'] !== $key || $c['state']['revision'] !== $revision) throw new ScheduleConflict('Данные ведомости изменились. Обновите страницу.');
            if (!$c['schedule'] || $c['schedule']['version'] !== $version) throw new ScheduleConflict('Версия расписания изменилась. Сначала проверьте её.');
            foreach ($c['boarding'] as $boarding) {
                $s = RouteSchedule::match($c['schedule']['stops'], $boarding);
                if ($s && $s['terminal']) throw new InvalidArgumentException('На станции «' . $boarding['station'] . '» есть посадка: нельзя помечать её конечной без времени отправления.');
            }
            $c['state']['applied'] = $c['schedule']['stops'];
            $c['state']['schedule_version'] = $version;
            $this->writeState($c, $user); // deliberately retains per-departure overrides
            $this->event($c, 'apply', $version, $user);
        });
    }

    public function override(array $manifest, array $group, string $date, string $time, int $revision, ?int $user, bool $reset = false): void
    {
        if (!$reset) {
            RouteSchedule::time($time);
            $d = DateTimeImmutable::createFromFormat('!d.m.Y', $date);
            if (!$d || $d->format('d.m.Y') !== $date) throw new InvalidArgumentException('Дата должна быть в формате ДД.ММ.ГГГГ.');
        }
        $this->transaction(function () use ($manifest, $group, $date, $time, $revision, $user, $reset) {
            $c = $this->context($manifest);
            if ($c['state']['revision'] !== $revision) throw new ScheduleConflict('Время уже изменилось. Обновите группы перед повторной правкой.');
            $key = RouteSchedule::stopKey($group);
            if ($reset) unset($c['state']['overrides'][$key]);
            else $c['state']['overrides'][$key] = ['date' => $date, 'time' => $time];
            $this->writeState($c, $user);
            $this->event($c, $reset ? 'override.reset' : 'override', $c['state']['schedule_version'], $user);
        });
    }
}
