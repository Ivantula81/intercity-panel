<?php
// Included after API authentication and CSRF checking; all actions require POST.
require_once PANEL_ROOT . '/lib/RouteScheduleStore.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Используйте POST.'], 405);
if ($action === 'schedule.save') require_admin();
try {
    $store = new RouteScheduleStore(db());
    if (!$store->available()) json_out(['ok' => false, 'error' => 'Справочник расписаний ещё не установлен (schema28).'], 503);
    $manifest = !empty($body['manifest_id']) ? get_manifest((int) $body['manifest_id']) : null;
    $context = $manifest ? $store->context($manifest) : null;
    switch ($action) {
        case 'schedule.list':
            json_out(['ok' => true, 'schedules' => $store->listing(), 'manifests' => db()->query('SELECT id,trip_number,route,departure_at FROM manifests ORDER BY id DESC LIMIT 100')->fetchAll()]);
        case 'schedule.get':
            if ($context) json_out(['ok' => true, 'context' => $context, 'can_edit' => is_admin()]);
            $schedule = $store->schedule((string) ($body['schedule_key'] ?? ''));
            if (!$schedule) json_out(['ok' => false, 'error' => 'Расписание не найдено.'], 404);
            json_out(['ok' => true, 'schedule' => $schedule, 'can_edit' => is_admin()]);
        case 'gds.times': // old clients must not silently overwrite times either
        case 'schedule.gds':
            if (!$context) throw new InvalidArgumentException('Выберите ведомость для проверки ГДС.');
            require_once PANEL_ROOT . '/lib/GdsRace.php';
            try { $proposal = GdsRace::scheduleProposal($manifest, $context['start_time']); }
            catch (Throwable $e) {
                audit_event('schedule.gds', 'schedule', 'manifest', (int) $manifest['id'], 'failure');
                json_out(['ok' => false, 'error' => 'Не удалось подтвердить рейс в ГДС по номеру, маршруту, дате и старту. Сохранённые данные не изменены; повторите запрос или заполните вручную.'], 502);
            }
            json_out(['ok' => true, 'proposal' => $proposal, 'context' => $context]);
        case 'schedule.save':
            if (($body['confirmed'] ?? false) !== true) throw new InvalidArgumentException('Подтвердите проверку расписания.');
            $route = (string) ($body['route'] ?? ''); $start = (string) ($body['start_time'] ?? '');
            if ($context && RouteSchedule::key($route, $start) !== $context['schedule_key']) throw new ScheduleConflict('Маршрут или плановый старт изменились. Обновите страницу.');
            $expected = filter_var($body['version'] ?? null, FILTER_VALIDATE_INT);
            if ($expected === false || $expected < 0) throw new InvalidArgumentException('Не указана версия расписания.');
            $apply = !empty($body['apply']);
            if ($apply && !$context) throw new InvalidArgumentException('Не выбрана ведомость для применения.');
            $saved = $store->save($route, $start, (array) ($body['stops'] ?? []), $expected, audit_actor_id(),
                $apply ? $manifest : null, isset($body['revision']) ? (int) $body['revision'] : null);
            audit_event($action, 'schedule', 'manifest', $manifest ? (int) $manifest['id'] : null, 'success', ['version' => $saved['version']]);
            json_out(['ok' => true, 'schedule' => $saved, 'context' => $manifest ? $store->context($manifest) : null]);
        case 'schedule.apply':
            if (!$context) throw new InvalidArgumentException('Не выбрана ведомость.');
            $store->apply($manifest, (string) ($body['schedule_key'] ?? ''), (int) ($body['version'] ?? 0),
                isset($body['revision']) ? (int) $body['revision'] : null, audit_actor_id());
            audit_event($action, 'schedule', 'manifest', (int) $manifest['id'], 'success');
            json_out(['ok' => true, 'context' => $store->context($manifest)]);
        case 'schedule.override.reset':
            if (!$context) throw new InvalidArgumentException('Не выбрана ведомость.');
            $group = find_notification_group($manifest, $body);
            if (!$group) throw new InvalidArgumentException('Группа не найдена.');
            $store->override($manifest, $group, '', '', (int) ($body['revision'] ?? -1), audit_actor_id(), true);
            json_out(['ok' => true]);
        default:
            json_out(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
    }
} catch (Throwable $e) {
    audit_event($action, 'schedule', 'manifest', isset($manifest['id']) ? (int) $manifest['id'] : null, 'failure');
    $status = $e instanceof ScheduleConflict ? 409 : ($e instanceof InvalidArgumentException ? 422 : 503);
    json_out(['ok' => false, 'error' => $status === 503 ? 'Не удалось сохранить или прочитать расписание. Ваши поля сохранены на экране; повторите запрос.' : $e->getMessage()], $status);
}
