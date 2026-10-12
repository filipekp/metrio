<?php
declare(strict_types=1);

/**
 * Staticka regresni kontrola orderu DDL pro migraci 003.
 * Nenahrazuje integracni test na skutecne MariaDB.
 */
$sql = file_get_contents(dirname(__DIR__) . '/migrations/003_meter_devices.sql');
if ($sql === false) throw new RuntimeException('Migrace 003 neexistuje.');

function sql_has(string $sql, string $regex, string $message): void {
    if (!preg_match($regex, $sql)) throw new RuntimeException('FAIL: ' . $message);
    echo 'OK: ' . $message . PHP_EOL;
}

sql_has($sql, '/CREATE TABLE IF NOT EXISTS meter_devices\s*\(/i', 'opakované založení fyzických zařízení');
sql_has($sql, '/WHERE NOT EXISTS\s*\(SELECT 1 FROM meter_devices d WHERE d\.meter_id=m\.id\)/i', 'bez duplicit zařízení po selhání');
sql_has($sql, '/ADD COLUMN IF NOT EXISTS device_id\b/i', 'opakované doplnění device_id');
sql_has($sql, '/SET r\.device_id=d\.id\s+WHERE r\.device_id IS NULL/i', 'nepřepisuje existující návaznosti odečtů');
sql_has($sql, '/ADD INDEX IF NOT EXISTS idx_meter_readings_meter\s*\(meter_id\)/i', 'samostatný podpůrný index pro cizí klíč meter_id');
sql_has($sql, '/DROP INDEX IF EXISTS unique_meter_day/i', 'bezpečné odstranění původní jedinečnosti');
sql_has($sql, '/ADD UNIQUE INDEX IF NOT EXISTS unique_device_day\s*\(device_id,reading_date\)/i', 'jedinečnost odečtů podle zařízení');
sql_has($sql, '/FOREIGN KEY IF NOT EXISTS\s*\(device_id\) REFERENCES meter_devices\(id\)/i', 'opakovaně bezpečný cizí klíč device_id');

// Pořadí je důležité: před odstraněním unique_meter_day musí existovat
// náhradní index začínající sloupcem meter_id, jinak MariaDB hlásí errno 150.
$createIndex = strpos($sql, 'ADD INDEX IF NOT EXISTS idx_meter_readings_meter');
$dropUnique = strpos($sql, 'DROP INDEX IF EXISTS unique_meter_day');
if ($createIndex === false || $dropUnique === false || $createIndex >= $dropUnique) {
    throw new RuntimeException('FAIL: Náhradní index musí předcházet odstranění původního!');
}
echo 'OK: opravené pořadí ALTER TABLE' . PHP_EOL;
