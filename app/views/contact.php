<?php /** @var array $contact @var array $history */ ?>
<div class="page-head">
    <div>
        <h1><?= e($contact['name'] ?: 'Без имени') ?></h1>
        <div class="sub"><?= e($contact['phone']) ?> · в базе с <?= $contact['first_seen'] ? date('d.m.Y', strtotime($contact['first_seen'])) : '—' ?></div>
    </div>
    <div class="head-actions">
        <a class="btn ghost" href="/?p=contacts">← К контактам</a>
    </div>
</div>

<div class="grid grid-3">
    <div class="stat"><div class="num"><?= (int) $contact['messages_count'] ?></div><div class="lbl">отправлено сообщений</div></div>
    <div class="stat"><div class="num"><?= (int) $contact['trips_count'] ?></div><div class="lbl">поездок в ведомостях</div></div>
    <div class="stat"><div class="num" style="font-size:18px"><?= $contact['last_seen'] ? date('d.m.Y', strtotime($contact['last_seen'])) : '—' ?></div><div class="lbl">последний контакт</div></div>
</div>

<div class="card mt" data-id="<?= $contact['id'] ?>" id="contactCard">
    <h2>Профиль</h2>
    <div class="grid grid-3">
        <label class="f">Имя<input class="cc" data-f="name" value="<?= e($contact['name']) ?>"></label>
        <label class="f">Теги<input class="cc" data-f="tags" value="<?= e($contact['tags']) ?>" placeholder="VIP, постоянный…"></label>
        <label class="f">Последний маршрут<input value="<?= e($contact['last_route']) ?>" disabled></label>
    </div>
    <label class="f">Заметка<textarea class="cc" data-f="note" rows="3"><?= e($contact['note']) ?></textarea></label>
    <span class="small muted" id="ccState"></span>
</div>

<?php if (!empty($incoming)): ?>
<div class="card">
    <h2>Ответы контакта <span class="badge muted"><?= count($incoming) ?></span></h2>
    <div class="feed">
    <?php foreach ($incoming as $in): ?>
        <div class="feed-item">
            <div class="when"><?= date('d.m.Y H:i', strtotime($in['received_at'])) ?></div>
            <div style="min-width:0"><div class="msg-preview" style="background:#eef2fb;border-radius:12px 12px 4px 12px;max-width:520px"><?= nl2br(e($in['body'])) ?></div></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>История сообщений <span class="badge muted"><?= count($history) ?></span></h2>
    <?php if (empty($history)): ?>
        <p class="muted">Сообщений этому номеру ещё не отправляли.</p>
    <?php else: ?>
        <div class="feed">
        <?php foreach ($history as $h): ?>
            <div class="feed-item">
                <div class="when"><?= date('d.m.Y H:i', strtotime($h['sent_at'] ?? $h['created_at'])) ?></div>
                <div style="min-width:0;flex:1">
                    <div class="row" style="gap:8px">
                        <span class="badge muted"><?= e($h['channel']) ?></span>
                        <?php if ($h['status'] === 'sent'): ?><span class="badge ok">отправлено</span>
                        <?php elseif ($h['status'] === 'failed'): ?><span class="badge err">ошибка</span>
                        <?php else: ?><span class="badge muted">в очереди</span><?php endif; ?>
                    </div>
                    <div class="msg-preview mt" style="max-width:520px"><?= nl2br(e($h['body'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
