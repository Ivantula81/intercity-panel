<?php /** @var array $manifests */ ?>
<div class="page-head">
    <div>
        <h1>Свободная рассылка</h1>
        <div class="sub">любые номера (один или несколько) · выбор каналов · текст · картинка</div>
    </div>
    <div class="head-actions"><span id="waStatus" class="badge muted">проверяю канал…</span></div>
</div>

<div class="split">
    <div class="card">
        <h2>Получатели</h2>
        <div class="row" style="margin-bottom:10px">
            <select id="bMan" style="max-width:340px">
                <option value="">— взять телефоны из ведомости —</option>
                <?php foreach ($manifests as $m): ?>
                    <option value="<?= $m['id'] ?>">№<?= e($m['trip_number']) ?> · <?= e($m['route']) ?> · <?= $m['departure_at'] ? date('d.m', strtotime($m['departure_at'])) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn ghost sm" onclick="pullPhones()">Подставить</button>
        </div>
        <label class="f">Номера — по одному на строку (или через запятую). Можно один или несколько.
            <textarea id="bPhones" rows="9" placeholder="+79051234567&#10;+381628246799"></textarea>
        </label>
        <div class="muted small" id="bCount"></div>
    </div>

    <div class="card">
        <h2>Сообщение</h2>
        <div class="send-channel-panel" style="margin-bottom:12px">
            <div><b>Каналы отправки</b><div class="muted small">Отмеченные каналы работают параллельно. Статус берётся от провайдера.</div></div>
            <div id="bChannels" class="send-channel-choices"><span class="muted small">проверяю подключения…</span></div>
        </div>
        <label class="f">Текст (эмодзи поддерживаются 🚍)
            <textarea id="bText" rows="6" placeholder="Напоминаем о вашей поездке завтра…"></textarea>
        </label>
        <div class="row" style="margin-bottom:12px">
            <label class="btn ghost sm" style="cursor:pointer">
                📎 Приложить картинку (WhatsApp)<input type="file" id="bFile" accept="image/*" style="display:none" onchange="uploadBroadcastImage(this)">
            </label>
            <span class="small muted" id="bImgState"></span>
        </div>
        <div id="bImgPreview" style="display:none;margin-bottom:12px"><img style="max-width:220px;border-radius:10px" alt="вложение"></div>
        <div class="row">
            <button class="btn" id="bSend" onclick="sendBroadcast()"><?= icon('send') ?> Отправить рассылку</button>
            <span class="muted small">уходит сразу всем выбранным, без пауз — скоростью управляете сами</span>
        </div>
        <div id="bResult" class="mt"></div>
    </div>
</div>
<script>document.addEventListener('DOMContentLoaded', () => { waStatus(); broadcastLoadChannels(); });</script>
