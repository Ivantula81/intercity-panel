<?php /** @var array $contacts @var int $total @var string $q @var string $sort */ ?>
<div class="page-head">
    <div>
        <h1>Контакты <span class="badge muted"><?= $total ?></span></h1>
        <div class="sub">база собирается автоматически из всех отправок и загруженных ведомостей</div>
    </div>
    <div class="head-actions">
        <a class="btn ghost" href="/?p=contacts_export"><?= icon('download') ?> Экспорт CSV</a>
    </div>
</div>

<div class="card">
    <form method="get" class="row" style="gap:10px;margin-bottom:4px">
        <input type="hidden" name="p" value="contacts">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по телефону, имени или тегу…" style="max-width:360px">
        <select name="sort" onchange="this.form.submit()">
            <option value="last_seen" <?= $sort === 'last_seen' ? 'selected' : '' ?>>Сначала недавние</option>
            <option value="messages_count" <?= $sort === 'messages_count' ? 'selected' : '' ?>>Больше сообщений</option>
            <option value="trips_count" <?= $sort === 'trips_count' ? 'selected' : '' ?>>Больше поездок</option>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>По имени</option>
        </select>
        <button class="btn sm">Найти</button>
        <?php if ($q !== ''): ?><a class="btn ghost sm" href="/?p=contacts">Сбросить</a><?php endif; ?>
    </form>
    <?php if (!empty($contacts)): ?>
    <div class="row mt" style="gap:10px;align-items:center">
        <button type="button" class="btn ghost sm" onclick="checkContactsChannels(this)" title="Проверить наличие WhatsApp/MAX/Telegram у показанных контактов">⟲ Проверить каналы</button>
        <span id="chChkState" class="muted small"></span>
    </div>
    <?php endif; ?>

    <?php if (empty($contacts)): ?>
        <p class="muted mt">Пока пусто. База начнёт наполняться, как только пойдут отправки и загрузки ведомостей.</p>
    <?php else: ?>
        <div class="table-wrap mt">
            <table class="t" id="contactsTable">
                <thead><tr>
                    <th style="width:22%">Имя</th><th style="width:140px">Телефон</th>
                    <th style="width:70px">Сообщ.</th><th style="width:70px">Поездок</th>
                    <th style="width:130px">Каналы</th>
                    <th>Последний маршрут</th><th style="width:120px">Контакт</th>
                    <th style="width:15%">Теги</th><th style="width:18%">Заметка</th><th style="width:40px"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($contacts as $c): ?>
                    <tr data-id="<?= $c['id'] ?>">
                        <td><input class="cell" data-f="name" value="<?= e($c['name']) ?>" placeholder="—"></td>
                        <td class="phone"><a href="/?p=contact&id=<?= $c['id'] ?>"><?= e($c['phone']) ?></a></td>
                        <td><span class="badge muted"><?= (int) $c['messages_count'] ?></span></td>
                        <td><?= (int) $c['trips_count'] ?></td>
                        <td style="white-space:nowrap"><?php
                            foreach (['has_whatsapp' => 'WA', 'has_max' => 'MAX', 'has_telegram' => 'TG'] as $col => $lbl):
                                $v = $c[$col] ?? null;
                                if ($v === null) echo '<span class="badge muted" style="padding:1px 6px;font-size:11px" title="не проверено">' . $lbl . '</span> ';
                                elseif ((int) $v === 1) echo '<span class="badge ok" style="padding:1px 6px;font-size:11px" title="есть">' . $lbl . '</span> ';
                                else echo '<span class="badge" style="padding:1px 6px;font-size:11px;opacity:.4" title="нет">' . $lbl . '</span> ';
                            endforeach;
                        ?></td>
                        <td class="muted small"><?= e($c['last_route']) ?></td>
                        <td class="muted small"><?= $c['last_seen'] ? date('d.m.Y H:i', strtotime($c['last_seen'])) : '—' ?></td>
                        <td><input class="cell" data-f="tags" value="<?= e($c['tags']) ?>" placeholder="напр. VIP"></td>
                        <td><input class="cell" data-f="note" value="<?= e($c['note']) ?>" placeholder="заметка…"></td>
                        <td class="actions"><button class="icon-btn" onclick="delContact(this)"><?= icon('trash') ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > count($contacts)): ?>
            <p class="muted small mt">Показаны первые <?= count($contacts) ?> из <?= $total ?> — уточните поиск.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<p class="muted small">Имя, теги и заметки редактируются прямо в таблице (автосохранение). Эта база — задел под будущую связку с Планфиксом и сайтом.</p>
<script>document.addEventListener('DOMContentLoaded', bindContacts);</script>
