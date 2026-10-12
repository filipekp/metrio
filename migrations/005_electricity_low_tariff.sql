-- NT is optional, applies only to electricity; historical VT readings remain unchanged.
-- MariaDB DDL may commit implicitly, so every operation can safely be retried.
ALTER TABLE meter_readings
    ADD COLUMN IF NOT EXISTS value_low DECIMAL(18,4) NULL AFTER value;

-- Per-device NT exchange protocol; new counters must not be mixed with old ones.
ALTER TABLE meter_devices
    ADD COLUMN IF NOT EXISTS opening_value_low DECIMAL(18,4) NULL AFTER opening_value,
    ADD COLUMN IF NOT EXISTS closing_value_low DECIMAL(18,4) NULL AFTER closing_value;
