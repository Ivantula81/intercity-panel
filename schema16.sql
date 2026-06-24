-- v16: группы уведомлений по точной паре «откуда -> куда»

ALTER TABLE manifest_groups ADD COLUMN IF NOT EXISTS destination VARCHAR(255) NOT NULL DEFAULT '' AFTER station_id;
ALTER TABLE manifest_groups ADD COLUMN IF NOT EXISTS destination_id INT NULL AFTER destination;
ALTER TABLE manifest_groups DROP INDEX IF EXISTS uq_mg;
ALTER TABLE manifest_groups ADD UNIQUE KEY IF NOT EXISTS uq_mg_route (manifest_id, station, destination);
