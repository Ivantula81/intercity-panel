<?php /** @var string $startPhone */ ?>
<div class="chat-wrap" id="chatWrap" data-start="<?= e($startPhone) ?>">

    <nav class="chat-queues" id="chatQueues">
        <div class="chat-queues-title">Входящие</div>
        <button class="cq active" data-queue="open" onclick="chatSetQueue('open')"><span>Все открытые</span><b data-count="open_count">0</b></button>
        <button class="cq" data-queue="new" onclick="chatSetQueue('new')"><span>Новые</span><b data-count="new_count">0</b></button>
        <button class="cq" data-queue="mine" onclick="chatSetQueue('mine')"><span>Мои</span><b data-count="mine_count">0</b></button>
        <button class="cq" data-queue="unassigned" onclick="chatSetQueue('unassigned')"><span>Без оператора</span><b data-count="unassigned_count">0</b></button>
        <button class="cq" data-queue="pending" onclick="chatSetQueue('pending')"><span>Ждём пассажира</span><b data-count="pending_count">0</b></button>
        <button class="cq" data-queue="delivery_failed" onclick="chatSetQueue('delivery_failed')"><span>Ошибки доставки</span><b data-count="delivery_failed_count">0</b></button>
        <button class="cq" data-queue="resolved" onclick="chatSetQueue('resolved')"><span>Закрытые</span><b data-count="resolved_count">0</b></button>
        <div class="chat-queues-title channels">Каналы</div>
        <div id="chatChannelTabs" class="chat-queue-channels"></div>
    </nav>

    <aside class="chat-list" id="chatList">
        <div class="chat-list-head">
            <input type="search" id="chatSearch" class="chat-search" placeholder="Поиск по имени, номеру" oninput="chatFilter()" autocomplete="off">
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
                    <div class="chat-head-phone"><span id="chatPhone"></span> <span id="chatHeadChannel"></span></div>
                </div>
                <div class="chat-head-controls">
                    <select id="chatStatus" onchange="chatUpdateMeta('status',this.value)" aria-label="Статус"><option value="new">Новый</option><option value="open">В работе</option><option value="pending">Ждём пассажира</option><option value="resolved">Закрыт</option></select>
                    <select id="chatPriority" onchange="chatUpdateMeta('priority',this.value)" aria-label="Приоритет"><option value="normal">Обычный</option><option value="high">Важный</option><option value="urgent">Срочный</option></select>
                    <select id="chatAssignee" onchange="chatUpdateMeta('assignee_user_id',this.value)" aria-label="Ответственный"><option value="">Без оператора</option></select>
                    <button class="chat-note-btn" type="button" onclick="chatOpenNotes()" title="Внутренние заметки">Заметки</button>
                </div>
                <a class="chat-head-card" id="chatCard" href="#" title="Карточка контакта">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                </a>
                <a class="chat-trip-link" id="chatTripLink" href="#" hidden></a>
            </header>

            <div class="chat-body" id="chatBody"><button class="chat-load-older" id="chatLoadOlder" hidden onclick="chatLoadOlder()">Показать предыдущие сообщения</button></div>

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

<dialog class="chat-notes-dialog" id="chatNotesDialog">
    <div class="chat-notes-head"><strong>Внутренние заметки</strong><button type="button" onclick="this.closest('dialog').close()">×</button></div>
    <div id="chatNotesList" class="chat-notes-list"></div>
    <form onsubmit="chatAddNote(event)"><textarea id="chatNoteText" rows="3" placeholder="Видно только сотрудникам"></textarea><button class="btn" type="submit">Добавить заметку</button></form>
</dialog>
