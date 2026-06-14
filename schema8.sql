-- v9: провайдер канала (evolution | greenapi)
ALTER TABLE wa_accounts ADD COLUMN IF NOT EXISTS provider VARCHAR(16) NOT NULL DEFAULT 'evolution';

-- аккаунт Green API (фирменный номер). Параметры берутся из /etc/panel.env.
INSERT INTO wa_accounts (instance, label, is_active, provider)
SELECT 'greenapi', 'Green API · фирменный', 0, 'greenapi'
WHERE NOT EXISTS (SELECT 1 FROM wa_accounts WHERE provider = 'greenapi');
