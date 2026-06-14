-- v8: документы для водителя — перевозчики/договоры, доп.поля пассажира, журнал отправки документов

-- доп.поля пассажира для дорожной/водительской ведомости
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS birthdate VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NULL;
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS citizenship VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE passengers ADD COLUMN IF NOT EXISTS pay_note VARCHAR(255) NOT NULL DEFAULT '';

-- справочник перевозчиков/договоров (выбирается оператором)
CREATE TABLE IF NOT EXISTS carriers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  atp VARCHAR(255) NOT NULL DEFAULT '',
  contract_no VARCHAR(128) NOT NULL DEFAULT '',
  contract_date VARCHAR(64) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO carriers (atp, contract_no, contract_date)
SELECT 'ИП Ванюк А.Н.', '1/01', '01 января 2026 года'
WHERE NOT EXISTS (SELECT 1 FROM carriers);

-- журнал отправки документов
CREATE TABLE IF NOT EXISTS doc_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  doc_type VARCHAR(32) NOT NULL,
  channel VARCHAR(16) NOT NULL,
  recipient VARCHAR(128) NOT NULL,
  status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  error TEXT NULL,
  actor VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
