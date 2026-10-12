<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/migrations/005_electricity_low_tariff.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    throw new RuntimeException('Migrace NT chybí.');
}
foreach (['value_low', 'opening_value_low', 'closing_value_low'] as $column) {
    if (strpos($sql, 'ADD COLUMN IF NOT EXISTS ' . $column . ' ') === false) {
        throw new RuntimeException('Chybí idempotentní sloupec ' . $column);
    }
}
echo "OK: Nízký tarif používá opakovatelnou samostatnou migraci." . PHP_EOL;
