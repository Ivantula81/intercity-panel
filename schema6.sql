-- v7: статусы доставки (webhook) + ID станций

-- webhook: привязка наших сообщений к WhatsApp-id и статусы
ALTER TABLE messages ADD COLUMN IF NOT EXISTS wa_id VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE messages ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS read_at DATETIME NULL;
ALTER TABLE messages ADD KEY IF NOT EXISTS idx_wa (wa_id);

-- входящие сообщения (ответы пассажиров)
CREATE TABLE IF NOT EXISTS inbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instance VARCHAR(64) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_phone (phone),
  KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ID станций
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS from_id INT NULL;
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS to_id INT NULL;
ALTER TABLE stops ADD COLUMN IF NOT EXISTS gds_id INT NULL;
ALTER TABLE stops ADD UNIQUE KEY IF NOT EXISTS uq_gds (gds_id);
ALTER TABLE manifest_groups ADD COLUMN IF NOT EXISTS station_id INT NULL;
