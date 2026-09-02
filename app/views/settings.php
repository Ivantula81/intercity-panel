<div class="page-head">
    <div>
        <h1>Настройки</h1>
        <div class="sub">каналы уведомлений и доступ — всё подключается отсюда, без правки файлов</div>
    </div>
</div>

<div class="card">
    <div class="row" style="justify-content:space-between;gap:14px;flex-wrap:wrap">
        <div><h2 style="margin:0">Обновление панели</h2><p class="muted small" style="margin:6px 0 0">Если после обновления видите старый стиль или «разъехавшуюся» страницу, обновите локальные данные приложения. Авторизация и рабочие настройки сохранятся.</p></div>
        <button class="btn ghost" type="button" onclick="resetPanelClient()">↻ Обновить панель</button>
    </div>
    <div id="panelResetState" class="muted small mt" aria-live="polite"></div>
</div>

<div class="split">
    <div class="card">
        <div class="row" style="justify-content:space-between">
            <h2 style="margin:0"><?= icon('whatsapp') ?> Аккаунты WhatsApp</h2>
            <div class="row" style="gap:8px">
                <button class="btn ghost sm" onclick="fixWebhook(this)" title="Переустановить приёмник статусов доставки/прочтения и входящих">🔧 Починить статусы</button>
                <button class="btn sm" onclick="waAddAccount()"><?= icon('plus') ?> Добавить аккаунт</button>
            </div>
        </div>
        <p class="muted small">Можно подключить несколько номеров. Звёздочка — с какого номера идёт рассылка. Подключение — по QR, как WhatsApp Web. <span id="whFixState" class="badge"></span></p>
        <div id="waAccounts" class="mt"><p class="muted">Загружаю…</p></div>
        <div class="qr-box" id="qrBox" style="display:none">
            <img id="qrImg" alt="QR для WhatsApp">
            <div class="muted small" style="text-align:center" id="qrHint">Телефон → WhatsApp → Связанные устройства → Привязка устройства.<br>QR живёт ~40 секунд; при истечении нажмите «Подключить» у аккаунта снова.</div>
        </div>
    </div>

    <div class="card">
        <?php
            $mailerOk = false;
            foreach (@file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
                if (strpos($ln, 'SMTP_USER=') === 0 && trim(substr($ln, 10)) !== '') $mailerOk = true;
            }
        ?>
        <h2><?= icon('mail') ?> Email (smtp.bz через SMTP)</h2>
        <p class="muted small">
            <?php if ($mailerOk): ?><span class="badge ok">SMTP подключён</span> сервер connect.smtp.bz, домен интерситур.рф подтверждён.
            <?php else: ?><span class="badge warn">не настроен</span> доступы SMTP заданы в /etc/panel.env.<?php endif; ?>
        </p>
        <label class="f">Email отправителя <input type="text" id="sbFrom" value="<?= e(opt('smtp_from', 'info@xn--e1afbawtcfgebm.xn--p1ai')) ?>" placeholder="info@интерситур.рф"></label>
        <label class="f">Имя отправителя <input type="text" id="sbFromName" value="<?= e(opt('smtp_from_name', 'Интерсити Тур')) ?>"></label>
        <label class="f">Reply-To (куда придут ответы пассажиров) <input type="text" id="sbReply" value="<?= e(opt('smtp_reply')) ?>" placeholder="info@... (реальный ящик)"></label>
        <div class="row">
            <button class="btn sm" onclick="saveSmtp()">Сохранить</button>
            <span class="muted small" id="sbState"></span>
        </div>
        <div class="row mt">
            <input type="text" id="sbTestTo" placeholder="email для теста" style="max-width:240px">
            <button class="btn ghost sm" onclick="testSmtp()">Отправить тест</button>
            <span class="muted small" id="sbTestState"></span>
        </div>
    </div>
</div>

<div class="card">
    <h2><?= icon('doc') ?> Реквизиты документов (печать и подпись)</h2>
    <p class="muted small">Подставляются в ведомости для водителя и дорожную, когда включён флажок «с печатью и подписью». Перевозчики и договоры — в <a href="/?p=catalogs">Справочниках</a>.</p>
    <div class="grid grid-3">
        <label class="f">Фрахтователь<input type="text" id="docFrah" value="<?= e(opt('doc_frahtovatel', 'ООО «ТерраТрансКрым»')) ?>"></label>
        <label class="f">Подписант (ФИО/должность)<input type="text" id="docSigner" value="<?= e(opt('doc_signer')) ?>" placeholder="напр. Директор Иванов И.И."></label>
        <div></div>
    </div>
    <div class="row">
        <button class="btn sm" onclick="saveDocReq()">Сохранить реквизиты</button>
        <span class="muted small" id="docReqState"></span>
    </div>
    <div class="row mt" style="gap:24px;align-items:flex-end">
        <div>
            <div class="muted small" style="margin-bottom:6px">Печать (PNG, лучше с прозрачным фоном)</div>
            <?php $st = opt('doc_stamp_url'); ?>
            <?php if ($st): ?><img src="<?= e($st) ?>" style="height:80px;display:block;margin-bottom:6px"><?php endif; ?>
            <label class="btn ghost sm" style="cursor:pointer">📤 Загрузить печать<input type="file" accept="image/png,image/*" style="display:none" onchange="uploadReq(this,'stamp')"></label>
        </div>
        <div>
            <div class="muted small" style="margin-bottom:6px">Подпись (PNG)</div>
            <?php $sg = opt('doc_sign_url'); ?>
            <?php if ($sg): ?><img src="<?= e($sg) ?>" style="height:60px;display:block;margin-bottom:6px"><?php endif; ?>
            <label class="btn ghost sm" style="cursor:pointer">📤 Загрузить подпись<input type="file" accept="image/png,image/*" style="display:none" onchange="uploadReq(this,'sign')"></label>
        </div>
    </div>
    <div class="card-subsection mt">
        <h3>Рабочее время рассылок (МСК)</h3>
        <div class="grid grid-3">
            <label class="f">Начало<input type="time" id="msgHoursFrom" value="<?= e(opt('messaging_hours_from','06:30')) ?>"></label>
            <label class="f">Окончание<input type="time" id="msgHoursTo" value="<?= e(opt('messaging_hours_to','21:00')) ?>"></label>
            <label class="row small" style="align-items:center;gap:8px"><input type="checkbox" id="msgHoursEnabled" <?= opt('messaging_hours_enabled','1') !== '0' ? 'checked' : '' ?>> ограничивать отправку рабочим временем</label>
        </div>
    </div>
</div>

<div class="card">
    <h2><?= icon('send') ?> Темп рассылки (защита от бана)</h2>
    <p class="muted small">Пауза между сообщениями держится на стороне Green API (очередь). Панель шлёт сразу — сервер сам растягивает. <b>15–20 сек — новый номер, 5–8 — прогретый.</b> Минимум 0.5 сек.</p>
    <div class="grid grid-3">
        <label class="f">WhatsApp, сек<input type="number" id="delWhatsapp" min="0.5" step="0.5" placeholder="—"></label>
        <label class="f">MAX, сек<input type="number" id="delMax" min="0.5" step="0.5" placeholder="—"></label>
        <label class="f">Telegram, сек<input type="number" id="delTelegram" min="0.5" step="0.5" placeholder="—"></label>
    </div>
    <div class="row">
        <button class="btn sm" onclick="saveChannelDelays()">Сохранить темп</button>
        <span class="muted small" id="delState">загружаю…</span>
    </div>
</div>

<div class="card">
    <h2><?= icon('bell') ?> Сообщения пассажирам</h2>
    <p class="muted small">Если при отправке снять галочку «указать телефон водителя», в переменную <code>{тел_водителя}</code> подставится эта фраза.</p>
    <div class="grid grid-3">
        <label class="f">Фраза вместо телефона водителя<input type="text" id="msgDriverFallback" value="<?= e(opt('driver_phone_fallback', 'сообщим позднее')) ?>" placeholder="сообщим позднее"></label>
        <label class="f">Строка отписки (в конце уведомлений; пусто = не добавлять)<input type="text" id="msgUnsubLine" value="<?= e(opt('unsub_line', 'Чтобы отписаться — напишите СТОП')) ?>" placeholder="Чтобы отписаться — напишите СТОП"></label>
        <label class="f">Дневной лимит на канал (предупреждение)<input type="number" id="msgDailyCap" min="0" step="10" value="<?= e(opt('daily_soft_cap', '200')) ?>" placeholder="200"></label>
    </div>
    <div class="grid grid-2">
        <label class="f">Автоответ на СТОП<textarea id="msgStopReply" rows="3"><?= e(opt('stop_reply', 'Вы отписаны от рассылки уведомлений. Чтобы снова получать сообщения о рейсах — напишите СТАРТ.')) ?></textarea></label>
        <label class="f">Автоответ на СТАРТ<textarea id="msgStartReply" rows="3"><?= e(opt('start_reply', 'Вы снова подписаны на уведомления о рейсах. Чтобы отписаться — напишите СТОП.')) ?></textarea></label>
    </div>
    <?php require_once PANEL_ROOT . '/lib/Channels.php'; $primary = Channels::primary(); ?>
    <div class="grid grid-3">
        <label class="f">Основной канал рассылки
            <select id="msgPrimaryChannel">
                <?php foreach (Channels::MESSENGERS as $ch): ?>
                    <option value="<?= e($ch) ?>" <?= $ch === $primary ? 'selected' : '' ?>><?= e(Channels::label($ch)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="muted small">Основной канал выбран галочкой по умолчанию в уведомлениях и в свободной рассылке, идёт первым в списке каналов, а остальные считаются запасными (кнопки «дослать»). Сейчас в России основной мессенджер — MAX.</p>
    <div class="row">
        <button class="btn sm" onclick="saveNotif()">Сохранить</button>
        <span class="muted small" id="notifState"></span>
    </div>
</div>

<?php if (is_admin()):
    $users = db()->query('SELECT id, name, login, role, active, last_login FROM users ORDER BY id')->fetchAll();
?>
<div class="card">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin:0"><?= icon('user') ?> Сотрудники <span class="badge muted"><?= count($users) ?></span></h2>
        <button class="btn sm" onclick="addUser()"><?= icon('plus') ?> Добавить сотрудника</button>
    </div>
    <p class="muted small">У каждого свой логин и пароль. В журнале отправок видно, кто что отправил.</p>
    <div class="table-wrap mt">
        <table class="t" id="usersTable">
            <thead><tr><th>Имя</th><th>Логин</th><th>Роль</th><th>Последний вход</th><th>Статус</th><th style="width:160px"></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr data-id="<?= $u['id'] ?>" data-name="<?= e($u['name']) ?>" data-login="<?= e($u['login']) ?>" data-role="<?= $u['role'] ?>">
                    <td><b><?= e($u['name']) ?></b></td>
                    <td><?= e($u['login']) ?></td>
                    <td><?= $u['role'] === 'admin' ? 'Администратор' : 'Оператор' ?></td>
                    <td class="muted small"><?= $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : '—' ?></td>
                    <td><?= $u['active'] ? '<span class="badge ok">активен</span>' : '<span class="badge muted">отключён</span>' ?></td>
                    <td class="actions">
                        <button class="btn ghost sm" onclick="editUser(this)"><?= icon('edit') ?></button>
                        <button class="btn ghost sm" onclick="toggleUser(<?= $u['id'] ?>)"><?= $u['active'] ? 'Откл.' : 'Вкл.' ?></button>
                        <button class="icon-btn" onclick="delUser(<?= $u['id'] ?>)"><?= icon('trash') ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Мой пароль</h2>
    <label class="f" style="max-width:380px">Новый пароль для входа
        <input type="password" id="newPass" placeholder="минимум 8 символов">
    </label>
    <div class="row">
        <button class="btn sm" onclick="savePass()">Сменить мой пароль</button>
        <span class="muted small" id="passState"></span>
    </div>
</div>
