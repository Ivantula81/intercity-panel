-- v13: разделение мессенджеров (Green API = MAX, Evolution = WhatsApp)
ALTER TABLE wa_accounts ADD COLUMN IF NOT EXISTS messenger VARCHAR(16) NOT NULL DEFAULT '';
UPDATE wa_accounts SET messenger = CASE WHEN provider = 'greenapi' THEN 'max' ELSE 'whatsapp' END WHERE messenger = '';

-- реальный chatId входящего (для MAX это короткий id, не телефон) — чтобы отвечать в тот же чат
ALTER TABLE inbox ADD COLUMN IF NOT EXISTS chat_id VARCHAR(64) NOT NULL DEFAULT '';
