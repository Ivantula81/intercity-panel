-- v12: продажи из писем каналов (ингест в панель)
CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email_id VARCHAR(40) NOT NULL,                 -- gmail message id (дедуп)
  channel VARCHAR(32) NOT NULL,                  -- site/gobus/rosbilet/unitiki/artmark/avtovokzaly/blablacar/other
  kind ENUM('sale','refund','cancel','payment','manifest','other') NOT NULL DEFAULT 'other',
  ticket_no VARCHAR(48) NOT NULL DEFAULT '',
  route VARCHAR(255) NOT NULL DEFAULT '',
  segment VARCHAR(255) NOT NULL DEFAULT '',      -- проданный отрезок (если есть)
  depart_at DATETIME NULL,                       -- дата/время рейса (где удалось извлечь)
  amount DECIMAL(10,2) NULL,                      -- сумма (где есть, в осн. свой сайт)
  passenger VARCHAR(255) NOT NULL DEFAULT '',
  occurred_at DATETIME NOT NULL,                 -- когда письмо получено
  subject VARCHAR(255) NOT NULL DEFAULT '',
  snippet VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email_id),
  KEY idx_channel (channel),
  KEY idx_kind (kind),
  KEY idx_occurred (occurred_at),
  KEY idx_depart (depart_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
