<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Energy\ReadingOverview;

function expect_overview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$meters = [
    ['id' => 1, 'name' => 'Hlavní', 'type' => 'electricity', 'parent_id' => null],
    ['id' => 2, 'name' => 'Podružný', 'type' => 'electricity', 'parent_id' => 1],
];
$devices = [
    [
        'id' => 10, 'meter_id' => 1, 'installed_on' => '2026-01-01',
        'removed_on' => '2026-01-10', 'opening_value' => 100,
        'closing_value' => 120, 'opening_value_low' => 40, 'closing_value_low' => 45,
    ],
    [
        'id' => 11, 'meter_id' => 1, 'installed_on' => '2026-01-10',
        'removed_on' => null, 'opening_value' => 5,
        'closing_value' => null, 'opening_value_low' => 1, 'closing_value_low' => null,
    ],
    [
        'id' => 20, 'meter_id' => 2, 'installed_on' => '2026-01-01',
        'removed_on' => null, 'opening_value' => 20,
        'closing_value' => null, 'opening_value_low' => null, 'closing_value_low' => null,
    ],
];
$readings = [
    ['meter_id' => 1, 'device_id' => 10, 'reading_date' => '2026-01-01', 'value' => 100, 'value_low' => 40],
    ['meter_id' => 2, 'device_id' => 20, 'reading_date' => '2026-01-01', 'value' => 20, 'value_low' => null],
    ['meter_id' => 1, 'device_id' => 10, 'reading_date' => '2026-01-05', 'value' => 110, 'value_low' => 43],
    ['meter_id' => 1, 'device_id' => 11, 'reading_date' => '2026-01-10', 'value' => 5, 'value_low' => 1],
    ['meter_id' => 1, 'device_id' => 11, 'reading_date' => '2026-01-15', 'value' => 15, 'value_low' => 3],
    ['meter_id' => 2, 'device_id' => 20, 'reading_date' => '2026-01-15', 'value' => 25, 'value_low' => null],
];

$report = ReadingOverview::build(
    $meters,
    $readings,
    $devices,
    [['meter_id' => 1, 'effective_from' => '2026-01-01', 'coefficient' => 2]],
    '2026-01-05',
    '2026-01-15'
);

expect_overview(count($report['rows']) === 3, 'Nesprávný počet dnů odečtů.');
$byDate = [];
foreach ($report['rows'] as $row) {
    $byDate[$row['date']] = $row['cells'];
}
expect_overview(abs($byDate['2026-01-05'][1]['usage'] - 26.0) < 0.0001, 'VT + NT a koeficient.');
expect_overview(abs($byDate['2026-01-05'][1]['usage_high'] - 20.0) < 0.0001, 'VT a koeficient.');
expect_overview(abs($byDate['2026-01-05'][1]['usage_low'] - 6.0) < 0.0001, 'NT a koeficient.');
expect_overview(abs($byDate['2026-01-10'][1]['usage'] - 24.0) < 0.0001, 'Výměna: dopočet starého zařízení.');
expect_overview(abs($byDate['2026-01-10'][1]['usage_high'] - 20.0) < 0.0001, 'Výměna: VT starého zařízení.');
expect_overview(abs($byDate['2026-01-10'][1]['usage_low'] - 4.0) < 0.0001, 'Výměna: NT starého zařízení.');
expect_overview(abs($byDate['2026-01-15'][1]['usage'] - 24.0) < 0.0001, 'Výměna: nové zařízení.');
expect_overview(abs($byDate['2026-01-15'][1]['usage_high'] - 20.0) < 0.0001, 'Výměna: VT nového zařízení.');
expect_overview(abs($byDate['2026-01-15'][1]['usage_low'] - 4.0) < 0.0001, 'Výměna: NT nového zařízení.');
expect_overview(abs($byDate['2026-01-15'][2]['usage'] - 5.0) < 0.0001, 'Podružné měřidlo.');
expect_overview($byDate['2026-01-15'][2]['usage_low'] === null, 'Bez NT zůstane prázdná hodnota.');
expect_overview(!isset($byDate['2026-01-05'][2]), 'Bez odečtu nesmí vzniknout buňka.');

$noBaseline = ReadingOverview::build(
    [$meters[1]],
    [['meter_id' => 2, 'device_id' => 20, 'reading_date' => '2026-01-15', 'value' => 25, 'value_low' => null]],
    [$devices[2]],
    [],
    '2026-01-01',
    '2026-01-31'
);
expect_overview($noBaseline['rows'][0]['cells'][2]['usage'] === null, 'Bez historie se nesmí vymýšlet spotřeba.');

$changedFactor = ReadingOverview::build(
    [$meters[1]],
    [
        ['meter_id' => 2, 'device_id' => 20, 'reading_date' => '2026-01-01', 'value' => 20, 'value_low' => null],
        ['meter_id' => 2, 'device_id' => 20, 'reading_date' => '2026-01-15', 'value' => 25, 'value_low' => null],
    ],
    [$devices[2]],
    [['meter_id' => 2, 'effective_from' => '2026-01-08', 'coefficient' => 1.5]],
    '2026-01-01',
    '2026-01-31'
);
expect_overview($changedFactor['rows'][0]['cells'][2]['usage'] === null, 'Změna koeficientu uvnitř intervalu.');

echo "OK: tabulka skutečných odečtů, výměny a koeficienty\n";
