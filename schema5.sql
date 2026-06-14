-- Сотрудники (вход по логину/паролю, роли, учёт «кто что»)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  login VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  active TINYINT NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- стартовый администратор (логин admin / пароль 77Ivan77) — если сотрудников ещё нет
INSERT INTO users (name, login, password_hash, role)
SELECT 'Администратор', 'admin', '$2y$12$jE360r6s60HrSjbe7XjYUuBTv6rTIsBAP8dT./z6dMn.byXMwlcJG', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users);

-- кто отправил сообщение
ALTER TABLE messages ADD COLUMN IF NOT EXISTS actor VARCHAR(128) NOT NULL DEFAULT '';

-- аккаунты WhatsApp (несколько номеров-инстансов Evolution), какой активен для отправки
CREATE TABLE IF NOT EXISTS wa_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instance VARCHAR(64) NOT NULL UNIQUE,
  label VARCHAR(128) NOT NULL DEFAULT '',
  is_active TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- первый аккаунт = текущий инстанс intercity, активный
INSERT INTO wa_accounts (instance, label, is_active)
SELECT 'intercity', 'Основной', 1
WHERE NOT EXISTS (SELECT 1 FROM wa_accounts);
