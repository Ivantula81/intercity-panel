-- CRM: база контактов, накапливается из всех отправок и ведомостей

CREATE TABLE IF NOT EXISTS contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL DEFAULT '',
  messages_count INT NOT NULL DEFAULT 0,
  trips_count INT NOT NULL DEFAULT 0,
  last_route VARCHAR(255) NOT NULL DEFAULT '',
  last_seen DATETIME NULL,
  first_seen DATETIME NULL,
  tags VARCHAR(255) NOT NULL DEFAULT '',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_name (name),
  KEY idx_last (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Справочник расписаний по маршрутам — резерв на случай недоступности GDS
CREATE TABLE IF NOT EXISTS route_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_key VARCHAR(255) NOT NULL UNIQUE,
  route VARCHAR(255) NOT NULL DEFAULT '',
  stops_json MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
