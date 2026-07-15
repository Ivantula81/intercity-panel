-- chatId мессенджеров у контакта.
-- Зачем: MAX/Telegram адресуются по chatId, а не по телефону. Раньше панель узнавала его
-- вызовом checkAccount ПЕРЕД каждой отправкой — и упиралась в лимит мессенджера на просмотр
-- контактов (HTTP 469 «User get contact info limit reached»), после чего рассылка вставала.
-- Теперь chatId сохраняется в момент проверки и при отправке берётся отсюда.
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS max_chat_id VARCHAR(64) NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(64) NULL;
