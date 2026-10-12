-- Jedno logické měřicí místo může mít několik po sobě jdoucích fyzických zařízení.
-- Původní odečty dostanou výchozí fyzické zařízení; jejich hodnoty se nemění.
CREATE TABLE meter_devices (
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

INSERT INTO meter_devices (meter_id,serial,installed_on)
 SELECT id,serial,'1900-01-01' FROM meters;

ALTER TABLE meter_readings ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER meter_id;

UPDATE meter_readings r JOIN meter_devices d ON d.meter_id=r.meter_id
 SET r.device_id=d.id;

ALTER TABLE meter_readings
 DROP INDEX unique_meter_day,
 MODIFY device_id BIGINT UNSIGNED NOT NULL,
 ADD UNIQUE KEY unique_device_day (device_id,reading_date),
 ADD INDEX idx_meter_readings_device (device_id),
 ADD CONSTRAINT fk_meter_readings_device FOREIGN KEY (device_id) REFERENCES meter_devices(id) ON DELETE RESTRICT;
