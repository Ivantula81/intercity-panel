<div class="page-head schedule-page-head"><div>
    <div class="muted small"><a href="/?p=catalogs">‹ Справочники</a></div>
    <h1>Расписание маршрутов</h1>
    <div class="sub">Настройте один раз. Используйте в следующих выездах.</div>
</div></div>
<div id="routeScheduleApp" data-manifest-id="<?= (int) ($_GET['manifest_id'] ?? 0) ?>" data-key="<?= e((string) ($_GET['key'] ?? '')) ?>">
    <div class="card schedule-picker">
        <label class="f">Выберите выезд
            <select id="scheduleManifest"><option value="">Выберите ведомость</option></select>
        </label>
        <div id="scheduleList" class="mt"></div>
    </div>
    <div id="scheduleMessage" role="status" aria-live="polite" class="mt"></div>
    <div id="scheduleEditor" class="mt"></div>
</div>
