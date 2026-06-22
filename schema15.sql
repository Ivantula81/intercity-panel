-- v16: единый рабочий inbox. Добавочная модель рядом с messages/inbox.

CREATE TABLE IF NOT EXISTS conversations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NULL,
  contact_phone VARCHAR(32) NOT NULL DEFAULT '',
  contact_name VARCHAR(255) NOT NULL DEFAULT '',
  channel VARCHAR(24) NOT NULL,
  channel_account VARCHAR(128) NOT NULL DEFAULT '',
  external_chat_id VARCHAR(128) NOT NULL,
  status ENUM('new','open','pending','resolved') NOT NULL DEFAULT 'new',
  priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  assignee_user_id INT NULL,
  manifest_id INT NULL,
  passenger_id INT NULL,
  unread_count INT NOT NULL DEFAULT 0,
  last_message_at DATETIME NULL,
  last_message_preview VARCHAR(500) NOT NULL DEFAULT '',
  last_direction ENUM('in','out') NOT NULL DEFAULT 'in',
  snoozed_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversation_chat (channel, channel_account, external_chat_id),
  KEY idx_conversation_queue (status, assignee_user_id, last_message_at),
  KEY idx_conversation_phone (contact_phone),
  KEY idx_conversation_manifest (manifest_id),
  KEY idx_conversation_unread (unread_count, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT NOT NULL,
  legacy_source ENUM('messages','inbox') NULL,
  legacy_id INT NULL,
  direction ENUM('in','out') NOT NULL,
  channel VARCHAR(24) NOT NULL,
  provider_message_id VARCHAR(128) NOT NULL DEFAULT '',
  message_type VARCHAR(32) NOT NULL DEFAULT 'text',
  body TEXT NOT NULL,
  media_url VARCHAR(512) NOT NULL DEFAULT '',
  media_type VARCHAR(128) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT '',
  author_user_id INT NULL,
  author_name VARCHAR(128) NOT NULL DEFAULT '',
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversation_legacy (legacy_source, legacy_id),
  KEY idx_conversation_messages (conversation_id, id),
  KEY idx_conversation_cursor (conversation_id, created_at, id),
  KEY idx_conversation_provider (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT NOT NULL,
  event_type ENUM('created','status','priority','assigned','note','linked','unlinked') NOT NULL,
  value_from VARCHAR(255) NOT NULL DEFAULT '',
  value_to VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT NULL,
  actor_user_id INT NULL,
  actor_name VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conversation_events (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
