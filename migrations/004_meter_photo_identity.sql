-- EAN je atribut fyzického zařízení, nikoli odečtu ani bytu.
-- Text uchovává počáteční nuly a podporuje i delší číselné kódy z čárových štítků.
ALTER TABLE meter_devices ADD COLUMN IF NOT EXISTS ean VARCHAR(40) NOT NULL DEFAULT '' AFTER serial;
ALTER TABLE meter_devices ADD INDEX IF NOT EXISTS idx_meter_devices_ean (ean);

-- Identifikátory přečtené AI jsou návrh, nikdy automaticky schválená identita.
ALTER TABLE reading_photos ADD COLUMN IF NOT EXISTS detected_serial VARCHAR(160) NULL;
ALTER TABLE reading_photos ADD COLUMN IF NOT EXISTS detected_ean VARCHAR(40) NULL;
ALTER TABLE reading_photos ADD COLUMN IF NOT EXISTS identity_status VARCHAR(16) NOT NULL DEFAULT 'unverified';
ALTER TABLE reading_photos ADD COLUMN IF NOT EXISTS suggested_meter_id BIGINT UNSIGNED NULL;
ALTER TABLE reading_photos ADD COLUMN IF NOT EXISTS original_meter_id BIGINT UNSIGNED NULL;

-- Starší verze nezakládala fyzické zařízení vždy při vytvoření měřicího místa.
INSERT INTO meter_devices (meter_id,serial,installed_on)
 SELECT m.id,m.serial,'1900-01-01' FROM meters m
 WHERE NOT EXISTS (SELECT 1 FROM meter_devices d WHERE d.meter_id=m.id);
