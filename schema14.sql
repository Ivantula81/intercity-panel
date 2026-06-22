-- v15: Отчётность по рейсам — факты, договоры агентов, файлы и снимки расчётов

ALTER TABLE manifests ADD COLUMN IF NOT EXISTS reporting_status VARCHAR(24) NOT NULL DEFAULT 'draft';
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS reporting_note TEXT NULL;
ALTER TABLE manifests ADD KEY IF NOT EXISTS idx_trip_number (trip_number);

ALTER TABLE passengers ADD COLUMN IF NOT EXISTS attendance ENUM('unknown','present','absent') NOT NULL DEFAULT 'unknown';
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS refund_status ENUM('none','completed') NOT NULL DEFAULT 'none';
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS agent_raw VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS agent_contract_id INT NULL;
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS manifest_price DECIMAL(10,2) NULL;
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS our_price DECIMAL(10,2) NULL;
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS finance_comment VARCHAR(500) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS report_agents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  aliases TEXT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_agent_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_agent_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agent_id INT NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  settlement_side ENUM('ours','carrier') NOT NULL DEFAULT 'ours',
  carrier VARCHAR(255) NOT NULL DEFAULT '',
  agent_commission_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  agent_commission_basis ENUM('our_price','manifest_price') NOT NULL DEFAULT 'our_price',
  commercial_rate DECIMAL(7,4) NOT NULL DEFAULT 15,
  dispatch_rate DECIMAL(7,4) NOT NULL DEFAULT 7,
  dispatch_settlement ENUM('offset','receivable') NOT NULL DEFAULT 'offset',
  valid_from DATE NULL,
  valid_to DATE NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rac_agent (agent_id),
  KEY idx_rac_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manifest_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  file_type ENUM('source_csv','working_manifest','driver_document','carrier_document','report','other') NOT NULL DEFAULT 'other',
  original_name VARCHAR(255) NOT NULL,
  storage_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
  file_size BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  note VARCHAR(500) NOT NULL DEFAULT '',
  uploaded_by VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_manifest_file_version (manifest_id, file_type, version),
  KEY idx_manifest_files (manifest_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manifest_cash_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  passenger_id INT NULL,
  amount DECIMAL(10,2) NOT NULL,
  recipient ENUM('us','carrier','agent') NOT NULL DEFAULT 'us',
  note VARCHAR(500) NOT NULL DEFAULT '',
  actor VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cash_manifest (manifest_id, created_at),
  KEY idx_cash_passenger (passenger_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manifest_calculations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  version INT NOT NULL,
  status ENUM('draft','calculated','approved') NOT NULL DEFAULT 'calculated',
  rules_json MEDIUMTEXT NOT NULL,
  totals_json MEDIUMTEXT NOT NULL,
  passengers_json LONGTEXT NOT NULL,
  actor VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_manifest_calculation (manifest_id, version),
  KEY idx_calculation_manifest (manifest_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE passengers SET manifest_price = price WHERE manifest_price IS NULL AND price IS NOT NULL;

INSERT INTO report_agents (name, aliases)
SELECT 'Прямая продажа Интерсити Тур', 'сайт,интерсити тур'
WHERE NOT EXISTS (SELECT 1 FROM report_agents WHERE name = 'Прямая продажа Интерсити Тур');

INSERT INTO report_agent_contracts (agent_id, title, settlement_side, commercial_rate, dispatch_rate)
SELECT id, 'Договор с Интерсити Тур', 'ours', 15, 7
FROM report_agents WHERE name = 'Прямая продажа Интерсити Тур'
  AND NOT EXISTS (SELECT 1 FROM report_agent_contracts WHERE agent_id = report_agents.id);
