-- v25: постоянный read-only импорт продаж/возвратов из почты.
-- Перед применением: backup БД. Откат: удалить две новые таблицы и, при
-- необходимости, новые колонки/индексы sales после проверки их использования.

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS source VARCHAR(24) NOT NULL DEFAULT 'email' AFTER id,
  ADD COLUMN IF NOT EXISTS source_event_id VARCHAR(191) NOT NULL DEFAULT '' AFTER email_id,
  ADD COLUMN IF NOT EXISTS order_no VARCHAR(64) NOT NULL DEFAULT '' AFTER ticket_no,
  ADD COLUMN IF NOT EXISTS quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER order_no,
  ADD COLUMN IF NOT EXISTS event_key CHAR(64) NULL AFTER quantity,
  ADD COLUMN IF NOT EXISTS parse_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER event_key;

ALTER TABLE sales
  ADD UNIQUE KEY IF NOT EXISTS uq_sales_event_key (event_key),
  ADD KEY IF NOT EXISTS idx_sales_order (channel, order_no),
  ADD KEY IF NOT EXISTS idx_sales_ticket (channel, ticket_no);

CREATE TABLE IF NOT EXISTS sales_sync_state (
  source VARCHAR(64) NOT NULL PRIMARY KEY,
  mailbox VARCHAR(191) NOT NULL DEFAULT '',
  uid_validity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('never','running','ok','warning','failed') NOT NULL DEFAULT 'never',
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_success_at DATETIME NULL,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  ignored_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_ingest_errors (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(64) NOT NULL,
  source_event_id VARCHAR(191) NOT NULL DEFAULT '',
  message_hash CHAR(40) NOT NULL,
  sender VARCHAR(255) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  occurred_at DATETIME NULL,
  error_code VARCHAR(64) NOT NULL DEFAULT '',
  error_text VARCHAR(500) NOT NULL DEFAULT '',
  snippet VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_ingest_error (source, message_hash),
  KEY idx_sales_ingest_error_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
