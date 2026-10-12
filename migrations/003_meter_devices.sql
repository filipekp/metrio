-- Jedno logicke merici misto muze mit vice fyzickych zarizeni v case.
-- Tato migrace je OPAKOVATELNA: starsi verze muze skoncit po prvnich DDL
-- prikazech (MariaDB DDL neni cele transakcni). Puvodni odecty se nemeni.
CREATE TABLE IF NOT EXISTS meter_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meter_id BIGINT UNSIGNED NOT NULL,
  serial VARCHAR(160) NOT NULL DEFAULT '',
  installed_on DATE NOT NULL,
  removed_on DATE NULL,
  opening_value DECIMAL(18,4) NULL,
  closing_value DECIMAL(18,4) NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_meter_devices_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT,
  INDEX idx_meter_devices_interval (meter_id,installed_on,removed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zabrani duplicitam, pokud predchozi pokus uz zarizeni zalozil.
INSERT INTO meter_devices (meter_id,serial,installed_on)
 SELECT m.id,m.serial,'1900-01-01' FROM meters m
 WHERE NOT EXISTS (SELECT 1 FROM meter_devices d WHERE d.meter_id=m.id);

ALTER TABLE meter_readings ADD COLUMN IF NOT EXISTS device_id BIGINT UNSIGNED NULL AFTER meter_id;

-- Nezasahovat do odečtů, kterým již bylo zařízení přiřazeno.
UPDATE meter_readings r
 JOIN meter_devices d ON d.meter_id=r.meter_id AND d.installed_on='1900-01-01'
 SET r.device_id=d.id
 WHERE r.device_id IS NULL;

-- POZOR: Puvodni UNIQUE(meter_id, reading_date) zaroven slouzi jako index
-- pro existujici cizi klic meter_readings.meter_id -> meters.id.
-- Nejdrive proto vytvorime nahradni index na meter_id, az POTOM rusime UNIQUE.
-- Jinak ALTER TABLE na MariaDB selze s errno 150 (foreign key).
ALTER TABLE meter_readings
 ADD INDEX IF NOT EXISTS idx_meter_readings_meter (meter_id);

-- Pro kazde fyzicke zarizeni je datum jedinecne, ale na jednom mericim
-- miste mohou mit stare i nove zarizeni odecet ve stejny den vymeny.
-- IF EXISTS / IF NOT EXISTS umoznuje bezpecne opakovani po cast. migraci.
ALTER TABLE meter_readings
 DROP INDEX IF EXISTS unique_meter_day,
 MODIFY COLUMN device_id BIGINT UNSIGNED NOT NULL,
 ADD UNIQUE INDEX IF NOT EXISTS unique_device_day (device_id,reading_date),
 ADD INDEX IF NOT EXISTS idx_meter_readings_device (device_id),
 ADD CONSTRAINT fk_meter_readings_device FOREIGN KEY IF NOT EXISTS (device_id) REFERENCES meter_devices(id) ON DELETE RESTRICT;
