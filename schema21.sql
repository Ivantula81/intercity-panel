-- v21: отчётность становится ОТДЕЛЬНОЙ средой, не зависящей от других разделов.
--
-- Раньше экран отчётности показывал ВСЕ ведомости подряд — то есть каждый CSV,
-- загруженный для уведомлений, автоматически оказывался в отчётности и считался.
-- Теперь в отчётности видны только те рейсы, которые туда добавили явно.
-- Идемпотентно.
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS in_reporting TINYINT NOT NULL DEFAULT 0;
ALTER TABLE manifests ADD KEY IF NOT EXISTS idx_in_reporting (in_reporting, departure_at);
