-- Notification runs. Additive migration; existing queue and schedule tables are unchanged.
CREATE TABLE IF NOT EXISTS notification_runs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 request_key VARCHAR(80) NOT NULL UNIQUE,
 manifest_id INT NOT NULL,
 purpose VARCHAR(24) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 created_by INT NULL,
 actor_name VARCHAR(128) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_notification_run_manifest (manifest_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notification_run_jobs (
 run_id BIGINT NOT NULL,
 job_id BIGINT NOT NULL UNIQUE,
 PRIMARY KEY (run_id,job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notification_calls (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 manifest_id INT NOT NULL,
 passenger_id INT NOT NULL,
 recipient VARCHAR(128) NOT NULL,
 created_by INT NULL,
 actor_name VARCHAR(128) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_call (manifest_id,passenger_id),
 KEY idx_notification_call_phone (manifest_id,recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
