-- v23: неизменяемый журнал действий сотрудников и техническое использование функций.
-- Применять отдельно после резервной копии. Секреты и полные ПД сюда не пишутся.
CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  actor_name VARCHAR(128) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  section VARCHAR(40) NOT NULL DEFAULT '',
  entity_type VARCHAR(40) NOT NULL DEFAULT '',
  entity_id INT NULL,
  result ENUM('started','success','failure') NOT NULL DEFAULT 'started',
  details_json JSON NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  request_id VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_user_created (user_id, created_at),
  KEY idx_audit_action_created (action, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
