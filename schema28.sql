-- v28: verified schedules are separate from the automatic route_schedule GDS cache.
-- Backup/preflight/rollback: docs/route-schedules.md. No historical data is rewritten.
CREATE TABLE IF NOT EXISTS verified_route_schedules (
    schedule_key CHAR(64) PRIMARY KEY,
    route VARCHAR(255) NOT NULL,
    start_time CHAR(5) NOT NULL,
    version INT NOT NULL,
    stops_json MEDIUMTEXT NOT NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manifest_schedule_state (
    manifest_id INT PRIMARY KEY,
    schedule_key CHAR(64) NOT NULL,
    route VARCHAR(255) NOT NULL,
    start_time CHAR(5) NOT NULL,
    source_kind VARCHAR(20) NOT NULL,
    source_json MEDIUMTEXT NOT NULL,
    applied_json MEDIUMTEXT NOT NULL,
    schedule_version INT NOT NULL DEFAULT 0,
    overrides_json MEDIUMTEXT NOT NULL,
    revision INT NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS route_schedule_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    schedule_key CHAR(64) NOT NULL,
    manifest_id INT NULL,
    user_id INT NULL,
    action VARCHAR(30) NOT NULL,
    version INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
