-- Кэш наличия мессенджеров у контакта.
-- Определяется через CheckAccount (MAX/Telegram, Green API) и whatsappNumbers (WhatsApp, Evolution).
-- NULL = не проверяли, 0 = нет, 1 = есть.

ALTER TABLE contacts ADD COLUMN IF NOT EXISTS has_whatsapp TINYINT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS has_max TINYINT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS has_telegram TINYINT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS channels_checked_at DATETIME NULL;
