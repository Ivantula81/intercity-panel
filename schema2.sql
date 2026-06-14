-- Панель v2: справочники, группы, шаблоны

CREATE TABLE IF NOT EXISTS stops (
  id INT AUTO_INCREMENT PRIMARY KEY,
  station VARCHAR(255) NOT NULL UNIQUE,
  city VARCHAR(128) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  map_url VARCHAR(512) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL DEFAULT '',
  plate VARCHAR(64) NOT NULL DEFAULT '',
  model VARCHAR(128) NOT NULL DEFAULT '',
  seats INT NOT NULL DEFAULT 0,
  driver_phone VARCHAR(32) NOT NULL DEFAULT '',
  photo VARCHAR(255) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drivers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  bus_id INT NULL,
  note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  body TEXT NOT NULL,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manifest_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  station VARCHAR(255) NOT NULL,
  boarding_date VARCHAR(10) NOT NULL DEFAULT '',
  boarding_time VARCHAR(5) NOT NULL DEFAULT '',
  body TEXT NULL,
  time_warning TINYINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mg (manifest_id, station)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE manifests ADD COLUMN IF NOT EXISTS driver_phone VARCHAR(32) NOT NULL DEFAULT '';
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS extra_info VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS confirmed TINYINT NOT NULL DEFAULT 0;

INSERT INTO templates (name, body, sort)
SELECT 'Стандартное уведомление',
'Здравствуйте, {имя}!\nВаш рейс {дата_рейса} *{откуда} — {куда}* 🚍\n📌 Посадка: {посадка}\n🕒 Время посадки: {дата} в {время}\n📍 Точка на карте: {карта}\n🚌 Автобус: {автобус}\n📞 Телефон водителя: {тел_водителя}\n{доп}\nПожалуйста, будьте на месте за 20 минут. Проверьте время отправления по вашей станции.', 1
WHERE NOT EXISTS (SELECT 1 FROM templates);

INSERT INTO templates (name, body, sort)
SELECT 'Отмена посадки',
'Здравствуйте, {имя}!\n⚠️ К сожалению, посадка {дата} в пункте {посадка} ОТМЕНЕНА.\nМы свяжемся с вами по поводу возврата или альтернативного варианта.\nПриносим извинения. Интерсити Тур 📞', 2
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE name = 'Отмена посадки');

INSERT INTO templates (name, body, sort)
SELECT 'Перенос времени',
'Здравствуйте, {имя}!\n⚠️ Внимание: время посадки по станции {посадка} изменилось.\nНовое время: {дата} в {время} 🕒\n📍 Точка на карте: {карта}\n🚌 Автобус: {автобус}\nИнтерсити Тур', 3
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE name = 'Перенос времени');
