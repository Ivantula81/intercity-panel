-- v26: классификация продаж по исходному получателю и агенту письма.
-- Перед применением: полный backup БД. Миграция не переписывает существующие
-- продажи; исторические строки классифицируются отдельным backfill после релиза.

ALTER TABLE carriers
  ADD COLUMN IF NOT EXISTS notification_emails TEXT NOT NULL AFTER contract_date;

CREATE TABLE IF NOT EXISTS sales_agent_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tag VARCHAR(100) NOT NULL,
  sender_pattern VARCHAR(255) NOT NULL,
  subject_contains VARCHAR(255) NOT NULL DEFAULT '',
  report_agent_id INT NULL,
  priority SMALLINT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sales_agent_rule_active (active, priority),
  KEY idx_sales_agent_rule_report_agent (report_agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS sender_email VARCHAR(255) NOT NULL DEFAULT '' AFTER source_event_id,
  ADD COLUMN IF NOT EXISTS recipient_email VARCHAR(255) NOT NULL DEFAULT '' AFTER sender_email,
  ADD COLUMN IF NOT EXISTS recipient_header VARCHAR(32) NOT NULL DEFAULT '' AFTER recipient_email,
  ADD COLUMN IF NOT EXISTS agent_rule_id INT NULL AFTER channel,
  ADD COLUMN IF NOT EXISTS report_agent_id INT NULL AFTER agent_rule_id,
  ADD COLUMN IF NOT EXISTS agent_tag VARCHAR(100) NOT NULL DEFAULT '' AFTER report_agent_id,
  ADD COLUMN IF NOT EXISTS owner_side ENUM('unassigned','ours','carrier') NOT NULL DEFAULT 'unassigned' AFTER agent_tag,
  ADD COLUMN IF NOT EXISTS carrier_id INT NULL AFTER owner_side,
  ADD COLUMN IF NOT EXISTS classified_at DATETIME NULL AFTER carrier_id;

ALTER TABLE sales
  ADD KEY IF NOT EXISTS idx_sales_sender (sender_email),
  ADD KEY IF NOT EXISTS idx_sales_recipient (recipient_email),
  ADD KEY IF NOT EXISTS idx_sales_agent (report_agent_id, agent_tag),
  ADD KEY IF NOT EXISTS idx_sales_owner (owner_side, carrier_id);
