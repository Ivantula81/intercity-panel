<?php /** @var string $startPhone */ ?>
<div class="chat-wrap" id="chatWrap" data-start="<?= e($startPhone) ?>">

    <aside class="chat-list" id="chatList">
        <div class="chat-list-head">
            <input type="search" id="chatSearch" class="chat-search" placeholder="Поиск по диалогам" oninput="chatFilter(this.value)" autocomplete="off">
        </div>
        <div class="chat-threads" id="chatThreads">
            <div class="chat-hint">Загрузка…</div>
        </div>
    </aside>

    <section class="chat-conv" id="chatConv">
        <div class="chat-empty" id="chatEmpty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/></svg>
            <p>Выберите диалог, чтобы начать переписку</p>
        </div>

        <div class="chat-pane" id="chatPane" hidden>
            <header class="chat-head">
                <button type="button" class="chat-back" onclick="chatCloseConv()" aria-label="Назад">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <span class="chat-head-ava" id="chatAva"></span>
                <div class="chat-head-info">
                    <div class="chat-head-name" id="chatName"></div>
                    <div class="chat-head-phone" id="chatPhone"></div>
                </div>
                <a class="chat-head-card" id="chatCard" href="#" title="Карточка контакта">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                </a>
            </header>

            <div class="chat-body" id="chatBody"></div>

            <form class="chat-input" id="chatForm" onsubmit="chatSend(event); return false;">
                <textarea id="chatText" class="chat-text" placeholder="Введите сообщение…" rows="1"></textarea>
                <button type="submit" class="chat-send-btn" id="chatSendBtn" aria-label="Отправить">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
                </button>
            </form>
            <div class="chat-channel-note" id="chatChannelNote"></div>
        </div>
    </section>
</div>
