<?php
// Read-only presentation of existing manifests; no new trip identity or data writes.
function trip_list_filters(array $query): array
{
    $string = static fn($v) => is_string($v) ? $v : '';
    $date = static function ($v) use ($string): string {
        $s = $string($v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s)) return '';
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s ? $s : '';
    };
    return ['q' => mb_substr(trim($string($query['q'] ?? '')), 0, 200),
        'from' => $date($query['from'] ?? ''), 'to' => $date($query['to'] ?? ''),
        'needs' => ($query['needs'] ?? '') === '1' ? '1' : '',
        'page' => max(1, min(1000000, (int)(is_scalar($query['page'] ?? 1) ? ($query['page'] ?? 1) : 1)))];
}
function trip_list_url(array $filters, ?int $page = null): string
{
    if ($page !== null) $filters['page'] = $page;
    return '/?' . http_build_query(['p' => 'manifests'] + array_filter($filters, static fn($v) => $v !== ''), '', '&', PHP_QUERY_RFC3986);
}
function trip_missing_fields(array $manifest): array
{
    $missing = [];
    foreach (['departure_at' => 'время', 'bus' => 'автобус', 'driver_phone' => 'телефон водителя'] as $key => $label) {
        if (trim((string)($manifest[$key] ?? '')) === '') $missing[] = $label;
    }
    return $missing;
}
function trip_list(PDO $db, array $filters): array
{
    $where = []; $params = [];
    if ($filters['q'] !== '') {
        $like = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(m.trip_number LIKE ? ESCAPE '!' OR m.route LIKE ? ESCAPE '!')";
        $params[] = $like; $params[] = $like;
    }
    if ($filters['from'] !== '') { $where[] = 'm.departure_at >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
    if ($filters['to'] !== '') { $where[] = 'm.departure_at <= ?'; $params[] = $filters['to'] . ' 23:59:59'; }
    if ($filters['needs'] === '1') $where[] = "(m.departure_at IS NULL OR TRIM(COALESCE(m.departure_at,''))='' OR TRIM(COALESCE(m.bus,''))='' OR TRIM(COALESCE(m.driver_phone,''))='')";
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $db->prepare('SELECT COUNT(*) FROM manifests m' . $clause);
    $count->execute($params); $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / 30)); $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * 30;
    $st = $db->prepare('SELECT m.*, (SELECT COUNT(*) FROM passengers p WHERE p.manifest_id=m.id) cnt FROM manifests m' . $clause . ' ORDER BY m.id DESC LIMIT 30 OFFSET ' . $offset);
    $st->execute($params);
    return ['manifests' => $st->fetchAll(PDO::FETCH_ASSOC), 'filters' => $filters, 'total' => $total, 'pages' => $pages, 'offset' => $offset];
}
function trip_conversations(PDO $db, int $manifestId): array
{
    $st = $db->prepare('SELECT id, channel, contact_name, status FROM conversations WHERE manifest_id=? ORDER BY id DESC');
    $st->execute([$manifestId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
