<?php
/** @var string $period @var array $metrics @var array $byChannel @var array $topDates @var array $feed @var ?array $syncState */

$CH = [
    'site' => ['Свой сайт', '#5b50e0'], 'gobus' => ['GoBus', '#16a765'],
    'rosbilet' => ['Рос Билет', '#d68102'], 'unitiki' => ['Unitiki', '#4986e7'],
    'artmark' => ['Артмарк GDS', '#a64d79'], 'avtovokzaly' => ['Автовокзалы.ру', '#0b8043'],
    'blablacar' => ['BlaBlaCar', '#00aff5'], 'other' => ['Другое', '#9a9ab0'],
];
$KIND = [
    'sale' => ['Продажа', 'ok'], 'payment' => ['Оплата', 'info'], 'refund' => ['Возврат', 'err'],
    'cancel' => ['Аннуляция', 'warn'], 'manifest' => ['Ведомость', 'info'], 'other' => ['—', ''],
];
$OWNER = ['ours' => ['Наши', 'ok'], 'carrier' => ['Перевозчик', 'info'], 'unassigned' => ['Не распределено', 'warn']];
$chName = fn($c) => $CH[$c][0] ?? $c;
$chColor = fn($c) => $CH[$c][1] ?? '#9a9ab0';
$money = fn($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';
$periods = ['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', 'all' => 'Всё время'];
$maxDate = 0; foreach ($topDates as $d) $maxDate = max($maxDate, (int) $d['c']);
$lastEvent = !empty($metrics['latest_event_at']) ? date('d.m.Y H:i', strtotime($metrics['latest_event_at'])) : 'нет событий';
$syncClass = 'warn';
$syncTitle = 'Источник продаж ещё не подключён';
$syncText = 'В панели показаны только ранее загруженные данные. Последнее событие: ' . $lastEvent . '.';
if ($syncState) {
    $status = (string) ($syncState['status'] ?? 'never');
    $lastSuccessTs = !empty($syncState['last_success_at']) ? strtotime($syncState['last_success_at']) : 0;
    $stale = !$lastSuccessTs || time() - $lastSuccessTs > 7200;
    if ($status === 'failed') {
        $syncClass = 'err'; $syncTitle = 'Синхронизация Gmail не работает';
        $syncText = (string) ($syncState['last_error'] ?: 'Неизвестная ошибка источника.');
    } elseif ($status === 'running') {
        $syncTitle = 'Идёт синхронизация Gmail';
        $syncText = 'Последний успешный запуск: ' . ($lastSuccessTs ? date('d.m.Y H:i', $lastSuccessTs) : 'ещё не завершался') . '.';
    } elseif ($status === 'warning') {
        $syncTitle = 'Продажи обновлены с предупреждениями';
        $syncText = 'Последний успешный запуск: ' . ($lastSuccessTs ? date('d.m.Y H:i', $lastSuccessTs) : '—') . '. Часть писем вынесена в журнал импорта.';
    } elseif ($status === 'ok' && !$stale) {
        $syncClass = 'ok'; $syncTitle = 'Продажи синхронизируются';
        $syncText = 'Последнее обновление: ' . date('d.m.Y H:i', $lastSuccessTs) . '. Загружено событий: ' . (int) $syncState['imported_count'] . '.';
    } elseif ($status === 'ok') {
        $syncTitle = 'Данные продаж давно не обновлялись';
        $syncText = 'Последняя успешная синхронизация: ' . date('d.m.Y H:i', $lastSuccessTs) . '.';
    }
}
$link = static function (array $replace = []) use ($period, $filters): string {
    $query = array_merge(['p' => 'sales', 'period' => $period], array_filter($filters, static fn($v) => $v !== '' && $v !== 0), $replace);
    return '/?' . http_build_query($query);
};
$ownerCounts = ['ours' => 0, 'carrier' => 0, 'unassigned' => 0];
foreach ($byOwner as $row) $ownerCounts[(string) $row['owner_side']] = (int) $row['sales'];
?>

<div class="page-head sales-page-head">
    <div>
        <div class="eyebrow">Каналы продаж</div>
        <h1>Продажи</h1>
        <div class="sub">Продажи и возвраты из почты с привязкой к агенту и владельцу рейса</div>
    </div>
    <?php if (is_admin()): ?>
        <a class="btn <?= $salesTab === 'settings' ? '' : 'ghost' ?>" href="/?p=sales&tab=<?= $salesTab === 'settings' ? 'overview' : 'settings' ?>">
            <?= $salesTab === 'settings' ? 'К продажам' : 'Настройки' ?>
        </a>
    <?php endif; ?>
</div>

<?php if (!$salesReady): ?>
    <div class="alert warn"><strong>Классификация ещё не активирована.</strong> Требуется применить schema26.sql.</div>
<?php else: ?>
<div class="alert <?= $syncClass ?> sales-source-status" role="status" aria-live="polite">
    <strong><?= e($syncTitle) ?></strong><span><?= e($syncText) ?></span>
</div>

<?php if ($salesTab === 'settings' && is_admin()): ?>
<div class="sales-settings-grid">
    <section class="card">
        <div class="sales-card-head">
            <div><h2>Кому принадлежит продажа</h2><p class="muted small">Система сравнивает исходный адрес получателя письма с этими адресами.</p></div>
            <button class="btn sm" type="button" onclick="saveSalesOwnership()">Сохранить адреса</button>
        </div>
        <div id="salesOwnershipState" class="muted small" aria-live="polite"></div>
        <label class="f">Наши адреса уведомлений
            <textarea id="salesOwnAddresses" rows="3" placeholder="sales@example.ru&#10;info@example.ru"><?= e($ownAddresses) ?></textarea>
        </label>
        <div class="sales-address-list">
            <?php foreach ($carriers as $carrier): ?>
                <label class="f sales-carrier-address" data-id="<?= (int) $carrier['id'] ?>">
                    <span><?= e($carrier['atp']) ?></span>
                    <textarea rows="2" placeholder="Адреса уведомлений, по одному в строке"><?= e($carrier['notification_emails']) ?></textarea>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if (!$carriers): ?><p class="empty-state">Сначала добавьте перевозчика в «Справочники».</p><?php endif; ?>
        <p class="muted small">Один адрес может принадлежать только одной стороне. Неизвестный адрес останется «Не распределено».</p>
    </section>

    <section class="card">
        <div class="sales-card-head">
            <div><h2>Нераспознанные адреса</h2><p class="muted small">Подсказки из уже загруженных писем.</p></div>
            <button class="btn ghost sm" type="button" onclick="reclassifySales()">Пересчитать</button>
        </div>
        <div id="salesReclassifyState" class="muted small" aria-live="polite"></div>
        <h3>Получатели</h3>
        <div class="sales-discovered">
            <?php foreach ($unmatchedRecipients as $item): ?><span><code><?= e($item['value']) ?></code><b><?= (int) $item['total'] ?></b></span><?php endforeach; ?>
            <?php if (!$unmatchedRecipients): ?><span class="muted">Нет</span><?php endif; ?>
        </div>
        <h3>Отправители</h3>
        <div class="sales-discovered">
            <?php foreach ($unmatchedSenders as $item): ?><span><code><?= e($item['value']) ?></code><b><?= (int) $item['total'] ?></b></span><?php endforeach; ?>
            <?php if (!$unmatchedSenders): ?><span class="muted">Нет</span><?php endif; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="sales-card-head">
        <div><h2>Агенты из писем</h2><p class="muted small">Отправитель определяет агента. Условие по теме необязательно и уточняет правило.</p></div>
        <button class="btn sm" type="button" onclick="addSalesAgentRule()">Добавить агента</button>
    </div>
    <div class="table-wrap">
        <table class="t sales-rules-table" id="salesAgentRules">
            <thead><tr><th>Название / тег</th><th>Отправитель</th><th>Тема содержит</th><th>Агент в отчётах</th><th>Приоритет</th><th>Вкл.</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($agentRules as $rule): ?>
                <tr data-id="<?= (int) $rule['id'] ?>">
                    <td data-label="Название / тег"><input class="cell" data-f="tag" value="<?= e($rule['tag']) ?>"></td>
                    <td data-label="Отправитель"><input class="cell" data-f="sender_pattern" value="<?= e($rule['sender_pattern']) ?>" placeholder="info@gobus.ru или @gobus.ru"></td>
                    <td data-label="Тема содержит"><input class="cell" data-f="subject_contains" value="<?= e($rule['subject_contains']) ?>" placeholder="необязательно"></td>
                    <td data-label="Агент в отчётах"><select class="cell" data-f="report_agent_id"><option value="">Не связан</option><?php foreach ($reportAgents as $agent): ?>
                        <option value="<?= (int) $agent['id'] ?>" <?= (int) $rule['report_agent_id'] === (int) $agent['id'] ? 'selected' : '' ?>><?= e($agent['name']) ?></option>
                    <?php endforeach; ?></select></td>
                    <td data-label="Приоритет"><input class="cell" type="number" data-f="priority" value="<?= (int) $rule['priority'] ?>"></td>
                    <td data-label="Включено"><input type="checkbox" data-f="active" <?= $rule['active'] ? 'checked' : '' ?> aria-label="Правило включено"></td>
                    <td class="actions"><button class="btn ghost sm" type="button" onclick="saveSalesAgentRule(this)">Сохранить</button><button class="icon-btn" type="button" onclick="deleteSalesAgentRule(this)" aria-label="Удалить">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <template id="salesAgentRuleTemplate"><tr data-id="0">
        <td data-label="Название / тег"><input class="cell" data-f="tag" value="" placeholder="Например, Рос-Билет"></td>
        <td data-label="Отправитель"><input class="cell" data-f="sender_pattern" value="" placeholder="info@example.ru или @example.ru"></td>
        <td data-label="Тема содержит"><input class="cell" data-f="subject_contains" value="" placeholder="необязательно"></td>
        <td data-label="Агент в отчётах"><select class="cell" data-f="report_agent_id"><option value="">Не связан</option><?php foreach ($reportAgents as $agent): ?><option value="<?= (int) $agent['id'] ?>"><?= e($agent['name']) ?></option><?php endforeach; ?></select></td>
        <td data-label="Приоритет"><input class="cell" type="number" data-f="priority" value="100"></td>
        <td data-label="Включено"><input type="checkbox" data-f="active" checked aria-label="Правило включено"></td>
        <td class="actions"><button class="btn ghost sm" type="button" onclick="saveSalesAgentRule(this)">Сохранить</button><button class="icon-btn" type="button" onclick="deleteSalesAgentRule(this)" aria-label="Удалить">×</button></td>
    </tr></template>
</section>
<?php else: ?>

<div class="seg">
    <?php foreach ($periods as $k => $label): ?><a href="<?= e($link(['period' => $k])) ?>" class="seg-item <?= $period === $k ? 'active' : '' ?>"><?= $label ?></a><?php endforeach; ?>
</div>

<form class="card sales-filters" method="get">
    <input type="hidden" name="p" value="sales"><input type="hidden" name="period" value="<?= e($period) ?>">
    <label>Агент<select name="agent"><option value="">Все</option><?php foreach ($agentOptions as $agent): ?><option <?= $filters['agent'] === $agent ? 'selected' : '' ?>><?= e($agent) ?></option><?php endforeach; ?></select></label>
    <label>Принадлежность<select name="owner"><option value="">Все</option><option value="ours" <?= $filters['owner'] === 'ours' ? 'selected' : '' ?>>Наши</option><option value="carrier" <?= $filters['owner'] === 'carrier' ? 'selected' : '' ?>>Перевозчик</option><option value="unassigned" <?= $filters['owner'] === 'unassigned' ? 'selected' : '' ?>>Не распределено</option></select></label>
    <label>Перевозчик<select name="carrier"><option value="">Все</option><?php foreach ($carriers as $carrier): ?><option value="<?= (int) $carrier['id'] ?>" <?= $filters['carrier'] === (int) $carrier['id'] ? 'selected' : '' ?>><?= e($carrier['atp']) ?></option><?php endforeach; ?></select></label>
    <label>Отправитель<input name="sender" type="text" value="<?= e($filters['sender']) ?>" placeholder="email или домен"></label>
    <label>Получатель<input name="recipient" type="text" value="<?= e($filters['recipient']) ?>" placeholder="исходный адрес"></label>
    <label>Тема письма<input name="subject" type="text" value="<?= e($filters['subject']) ?>" placeholder="часть темы"></label>
    <div class="sales-filter-actions"><button class="btn sm">Применить</button><a class="btn ghost sm" href="/?p=sales&period=<?= e($period) ?>">Сбросить</a></div>
</form>

<div class="sales-owner-summary">
    <span class="badge ok">Наши · <?= $ownerCounts['ours'] ?></span>
    <span class="badge info">Перевозчики · <?= $ownerCounts['carrier'] ?></span>
    <span class="badge warn">Не распределено · <?= $ownerCounts['unassigned'] ?></span>
</div>

<div class="grid-4" style="margin-bottom:18px">
    <div class="card stat"><div class="lbl">Продажи (билетов)</div><div class="num"><?= (int) $metrics['sales_cnt'] ?></div><div class="muted small"><?= $money($metrics['sales_sum']) ?></div></div>
    <div class="card stat"><div class="lbl">Возвраты</div><div class="num" style="color:var(--err)"><?= (int) $metrics['refund_cnt'] ?></div><div class="muted small"><?= $money($metrics['refund_sum']) ?></div></div>
    <div class="card stat"><div class="lbl">Аннуляции</div><div class="num" style="color:var(--warn)"><?= (int) $metrics['cancel_cnt'] ?></div><div class="muted small">снять с ведомости</div></div>
    <div class="card stat"><div class="lbl">Всего событий</div><div class="num"><?= (int) $metrics['total'] ?></div><div class="muted small">за период</div></div>
</div>

<div class="split sales-split" style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px;align-items:start">
    <div class="card"><h2>По каналам</h2><?php if (!$byChannel): ?><p class="muted">Нет данных за период.</p><?php else: ?>
        <div class="table-wrap"><table class="t"><thead><tr><th>Канал</th><th>Продажи</th><th>Возвраты</th><th>Аннул.</th><th style="text-align:right">Сумма</th></tr></thead><tbody>
        <?php foreach ($byChannel as $c): ?><tr><td><span class="ch-dot" style="background:<?= $chColor($c['channel']) ?>"></span><?= e($chName($c['channel'])) ?></td><td><b><?= (int) $c['sales'] ?></b></td><td><?= (int) $c['refunds'] ?: '—' ?></td><td><?= (int) $c['cancels'] ?: '—' ?></td><td style="text-align:right"><?= (int) $c['sum'] ? $money($c['sum']) : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
    <div class="card"><h2>Топ дат отправления</h2><?php if (!$topDates): ?><p class="muted">Нет дат за период.</p><?php else: ?><div class="topdates">
        <?php foreach ($topDates as $d): ?><div class="td-row"><span class="td-date"><?= date('d.m.Y', strtotime($d['d'])) ?></span><span class="td-bar"><span style="width:<?= $maxDate ? round(100 * $d['c'] / $maxDate) : 0 ?>%"></span></span><span class="td-cnt"><?= (int) $d['c'] ?></span></div><?php endforeach; ?>
        </div><?php endif; ?>
    </div>
</div>

<section class="card" style="margin-top:18px">
    <h2>Лента входящих <span class="muted small" style="font-weight:500">(последние <?= count($feed) ?>)</span></h2>
    <?php if (!$feed): ?><p class="muted">Пока нет писем за период.</p><?php else: ?><div class="sfeed">
    <?php foreach ($feed as $r): [$kl, $kc] = $KIND[$r['kind']] ?? ['—', '']; [$ol, $oc] = $OWNER[$r['owner_side']] ?? $OWNER['unassigned']; ?>
        <article class="sf-row sales-event">
            <span class="sf-time"><?= date('d.m H:i', strtotime($r['occurred_at'])) ?></span>
            <span class="sf-ch" style="background:<?= $chColor($r['channel']) ?>"><?= e($chName($r['channel'])) ?></span>
            <span class="badge <?= $kc ?> sf-kind"><?= $kl ?><?= (int)($r['quantity'] ?? 1) > 1 ? ' ×' . (int)$r['quantity'] : '' ?></span>
            <div class="sf-route"><strong><?= e($r['route'] ?: $r['segment'] ?: $r['subject'] ?: '—') ?></strong>
                <span class="sales-event-meta"><?= e($r['agent_tag'] ?: 'Агент не определён') ?> · <?= e($ol . ($r['carrier_name'] ? ': ' . $r['carrier_name'] : '')) ?> · <?= e($r['recipient_email'] ?: 'адрес не извлечён') ?></span>
            </div>
            <span class="sf-amount"><?= $r['amount'] !== null ? $money($r['amount']) : '' ?></span>
        </article>
    <?php endforeach; ?></div><?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
