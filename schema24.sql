-- v24: outbox для устойчивой очереди рассылок. Применять после backup.
CREATE TABLE IF NOT EXISTS broadcast_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  idempotency_key CHAR(64) NOT NULL,
  kind ENUM('campaign','broadcast','single') NOT NULL,
  manifest_id INT NOT NULL DEFAULT 0,
  payload_json LONGTEXT NOT NULL,
  status ENUM('queued','running','paused','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  locked_by VARCHAR(80) NOT NULL DEFAULT '',
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_broadcast_job_idempotency (idempotency_key),
  KEY idx_broadcast_jobs_queue (status, available_at),
  KEY idx_broadcast_jobs_manifest (manifest_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS broadcast_deliveries (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  passenger_id INT NULL,
  channel VARCHAR(16) NOT NULL,
  recipient VARCHAR(128) NOT NULL,
  body_hash CHAR(64) NOT NULL,
  status ENUM('queued','sending','accepted','delivered','read','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  provider_id VARCHAR(128) NOT NULL DEFAULT '',
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_broadcast_delivery (job_id, passenger_id, channel, body_hash),
  KEY idx_broadcast_delivery_queue (status, available_at),
  KEY idx_broadcast_delivery_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
