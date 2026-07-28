-- v20: сценарии расчёта = ИМЕНОВАННЫЕ снимки одной ведомости.
-- Минимально инвазивный путь (как рекомендует ТЗ): у manifest_calculations уже есть
-- версии и rules_json, добавляем только имя сценария. Так можно держать несколько
-- расчётов на рейс («Вариант 1/2/3»), переключаться и сравнивать, не ломая архитектуру.
-- Идемпотентно.
ALTER TABLE manifest_calculations
  ADD COLUMN IF NOT EXISTS scenario_name VARCHAR(64) NOT NULL DEFAULT 'Вариант 1';

-- Быстрый отбор последней версии по сценарию рейса.
ALTER TABLE manifest_calculations
  ADD KEY IF NOT EXISTS idx_calc_scenario (manifest_id, scenario_name, version);
