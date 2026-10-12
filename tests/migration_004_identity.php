<?php
declare(strict_types=1);
$sql=file_get_contents(dirname(__DIR__).'/migrations/004_meter_photo_identity.sql');
if(!is_string($sql)) throw new RuntimeException('Chybí migrace 004.');
foreach([
 'EAN fyzického zařízení'=>'/ALTER TABLE meter_devices ADD COLUMN IF NOT EXISTS ean VARCHAR\(40\)/i',
 'EAN se indexuje'=>'/ADD INDEX IF NOT EXISTS idx_meter_devices_ean/i',
 'záznam identifikace fotografie'=>'/ADD COLUMN IF NOT EXISTS identity_status/i',
 'původní měřidlo se zachovává'=>'/ADD COLUMN IF NOT EXISTS original_meter_id/i',
 'opakované vytvoření zařízení nevytváří duplicity'=>'/WHERE NOT EXISTS \(SELECT 1 FROM meter_devices d WHERE d\.meter_id=m\.id\)/i',
] as $label=>$rule) {
 if(!preg_match($rule,$sql)) throw new RuntimeException('FAIL: '.$label);
 echo 'OK: '.$label.PHP_EOL;
}
