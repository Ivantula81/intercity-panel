-- Additive incoming-event receipts. See docs/incoming-events.md for preflight/rollback.
-- Do not backfill or deduplicate historical inbox rows.
CREATE TABLE IF NOT EXISTS incoming_receipts (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    channel VARCHAR(24) NOT NULL,
    inbox_id INT NULL,
    reply_state VARCHAR(16) NOT NULL DEFAULT 'none',
    reply_body TEXT NULL,
    reply_provider_id VARCHAR(128) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incoming_reply (reply_state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
