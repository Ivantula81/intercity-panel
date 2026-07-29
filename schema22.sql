-- v22: СЦЕНАРИИ РАСЧЁТА как наборы настроек (а не именованные снимки).
--
-- Смысл: одну и ту же ведомость посчитать при другом раскладе агентов и процентов.
-- Поэтому сценарий держит СВОИ справочники (перевозчики, агенты, автовокзалы),
-- а факты рейса (пассажиры, цены, назначения, неявки, наличные) остаются общими.
-- Переключил сценарий — те же факты пересчитались по другим ставкам.
--
-- ⚠️ origin_id. В прототипе копия сценария делалась deep-clone в JS и id агентов
-- сохранялись — поэтому назначения в строках не слетали. В SQL копирование строк даёт
-- НОВЫЕ id, и назначения бы порвались. origin_id — «предок» договора (у оригинала он
-- равен своему id); при копировании сценария переносится. Пассажир по-прежнему хранит
-- agent_contract_id, а расчёт ищет в активном сценарии договор с тем же origin_id.
-- Идемпотентно.

CREATE TABLE IF NOT EXISTS report_scenarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scenario_sort (sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Перевозчики со ставками — внутри сценария. Общая таблица carriers остаётся как есть:
-- она используется в документах и справочниках, её трогать нельзя.
CREATE TABLE IF NOT EXISTS report_scenario_carriers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scenario_id INT NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  disp_rate DECIMAL(7,4) NOT NULL DEFAULT 7,   -- с ОБОРОТА (ведомость + автовокзалы)
  our_rate  DECIMAL(7,4) NOT NULL DEFAULT 15,  -- только с ПРОДАЖ ТЕРРЫ
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sc_carrier (scenario_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE report_agent_contracts ADD COLUMN IF NOT EXISTS scenario_id INT NULL;
ALTER TABLE report_agent_contracts ADD COLUMN IF NOT EXISTS origin_id INT NULL;
ALTER TABLE report_agent_contracts ADD KEY IF NOT EXISTS idx_rac_scenario (scenario_id, origin_id);

ALTER TABLE report_stations ADD COLUMN IF NOT EXISTS scenario_id INT NULL;
ALTER TABLE report_stations ADD KEY IF NOT EXISTS idx_station_scenario (scenario_id, name);

-- Каким сценарием считается конкретный рейс (NULL = сценарий по умолчанию).
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS report_scenario_id INT NULL;

-- Сценарий по умолчанию + перенос существующих справочников в него.
INSERT INTO report_scenarios (name, sort)
SELECT 'Вариант 1', 0 WHERE NOT EXISTS (SELECT 1 FROM report_scenarios);

UPDATE report_agent_contracts
   SET scenario_id = (SELECT MIN(id) FROM report_scenarios)
 WHERE scenario_id IS NULL;

UPDATE report_agent_contracts SET origin_id = id WHERE origin_id IS NULL;

UPDATE report_stations
   SET scenario_id = (SELECT MIN(id) FROM report_scenarios)
 WHERE scenario_id IS NULL;

-- Ставки перевозчиков переносим из общей таблицы carriers в сценарий по умолчанию.
INSERT INTO report_scenario_carriers (scenario_id, name, disp_rate, our_rate)
SELECT (SELECT MIN(id) FROM report_scenarios), c.atp,
       COALESCE(c.disp_rate, 7), COALESCE(c.our_rate, 15)
  FROM carriers c
 WHERE c.atp <> ''
   AND NOT EXISTS (SELECT 1 FROM report_scenario_carriers rc
                    WHERE rc.scenario_id = (SELECT MIN(id) FROM report_scenarios) AND rc.name = c.atp);

-- Имя автовокзала было уникальным глобально — со сценариями один и тот же вокзал
-- должен существовать в каждом наборе. Уникальность становится «сценарий + имя».
ALTER TABLE report_stations DROP INDEX IF EXISTS uq_report_station_name;
ALTER TABLE report_stations ADD UNIQUE KEY IF NOT EXISTS uq_station_scenario_name (scenario_id, name);
