-- Отписка от рассылок: пассажир пишет «стоп» → сюда ставится время отписки.
-- Пока стоит — в массовых рассылках (campaign.send / broadcast.send) этому номеру не шлём.
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS unsubscribed_at DATETIME NULL;
