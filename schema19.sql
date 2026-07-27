-- v19: отчётность — автовокзалы, источник матчинга агента, ставки на перевозчике.
-- Идемпотентно: можно применять повторно. Старые поля НЕ трогаем (совместимость снимков).

-- ── Ставки живут на ПЕРЕВОЗЧИКЕ. Базы у них РАЗНЫЕ:
--    disp_rate (диспетчерские) → с ОБОРОТА рейса = ведомость + продажи автовокзалов;
--    our_rate  (комиссия Терры) → ТОЛЬКО с продаж Терры.
-- В report_agent_contracts.commercial_rate/dispatch_rate остаются как были — по ним
-- читаются ранее сохранённые снимки (formula_version < 2).
ALTER TABLE carriers ADD COLUMN IF NOT EXISTS disp_rate DECIMAL(7,4) NOT NULL DEFAULT 7;
ALTER TABLE carriers ADD COLUMN IF NOT EXISTS our_rate  DECIMAL(7,4) NOT NULL DEFAULT 15;

-- ── Где искать агента при матчинге:
--    raw     — автозаполненное поле «Агент/кассир» (наши продажи);
--    comment — ручная пометка кассира (агенты перевозчика);
--    both    — искать в обоих.
-- Пусто = по умолчанию: наши → raw, агенты перевозчика → comment.
ALTER TABLE report_agent_contracts
  ADD COLUMN IF NOT EXISTS match_src ENUM('raw','comment','both') NULL DEFAULT NULL;

-- ── Автовокзалы: продают напрямую перевозчику, в посадочную ведомость НЕ попадают,
-- вносятся вручную суммой на рейс. У каждого свой процент.
CREATE TABLE IF NOT EXISTS report_stations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  rate DECIMAL(7,4) NOT NULL DEFAULT 0,          -- процент вокзала (10 = 10%)
  note VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_station_name (name),
  KEY idx_report_station_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Продажи автовокзалов на конкретный рейс. Входят в ОБОРОТ (база диспетчерских 7%),
-- но в долг Терры перевозчику НЕ входят — деньги у перевозчика напрямую.
CREATE TABLE IF NOT EXISTS manifest_station_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT NOT NULL,
  station_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  actor VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mss_manifest (manifest_id, created_at),
  KEY idx_mss_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Прочие расходы рейса (не комиссии): участвуют в доходе перевозчика.
ALTER TABLE manifests ADD COLUMN IF NOT EXISTS other_costs DECIMAL(10,2) NOT NULL DEFAULT 0;
