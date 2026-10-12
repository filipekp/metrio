<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Energy\Consumption;
use Energy\MeterRegistry;

function check_tariff(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'OK: ' . $label . PHP_EOL;
}

$devices = [[
    'id' => 10,
    'installed_on' => '1900-01-01',
    'removed_on' => null,
    'opening_value' => null,
    'closing_value' => null,
    'opening_value_low' => null,
    'closing_value_low' => null,
]];
$dual = [
    10 => [
        ['reading_date' => '2026-01-01', 'value' => '100', 'value_low' => '20'],
        ['reading_date' => '2026-02-01', 'value' => '140', 'value_low' => '50'],
    ],
];
$usage = Consumption::deviceUsage($devices, $dual, '2026-01-01', '2026-02-01', true);
check_tariff($usage !== null && abs($usage['value'] - 70) < 0.0001, 'VT 40 + NT 30 = 70 kWh');

$single = [
    10 => [
        ['reading_date' => '2026-01-01', 'value' => '100', 'value_low' => null],
        ['reading_date' => '2026-02-01', 'value' => '140', 'value_low' => null],
    ],
];
$usage = Consumption::deviceUsage($devices, $single, '2026-01-01', '2026-02-01', true);
check_tariff($usage !== null && abs($usage['value'] - 40) < 0.0001, 'Historický VT bez NT zůstává platný');
check_tariff(
    Consumption::deviceUsage($devices, $dual, '2026-01-01', '2026-02-01', false)['value'] === 40.0,
    'Ne-elektrická spotřeba NT nezapočítá'
);

$incomplete = [
    10 => [
        ['reading_date' => '2026-01-01', 'value' => '100', 'value_low' => null],
        ['reading_date' => '2026-02-01', 'value' => '140', 'value_low' => '20'],
        ['reading_date' => '2026-03-01', 'value' => '200', 'value_low' => '40'],
    ],
];
check_tariff(
    Consumption::deviceUsage($devices, $incomplete, '2026-01-01', '2026-03-01', true) === null,
    'Částečné NT se nepovažuje za nulovou spotřebu'
);
check_tariff(
    abs(Consumption::deviceUsage($devices, $incomplete, '2026-02-01', '2026-03-01', true)['value'] - 80) < 0.0001,
    'Od prvního kompletního páru lze VT a NT sečíst'
);

$replaced = [
    [
        'id' => 10, 'installed_on' => '1900-01-01', 'removed_on' => '2026-02-01',
        'opening_value' => null, 'closing_value' => '140',
        'opening_value_low' => null, 'closing_value_low' => '50',
    ],
    [
        'id' => 11, 'installed_on' => '2026-02-01', 'removed_on' => null,
        'opening_value' => '0', 'closing_value' => null,
        'opening_value_low' => '0', 'closing_value_low' => null,
    ],
];
$series = [
    10 => [['reading_date' => '2026-01-01', 'value' => '100', 'value_low' => '20']],
    11 => [['reading_date' => '2026-03-01', 'value' => '30', 'value_low' => '10']],
];
$usage = Consumption::deviceUsage($replaced, $series, '2026-01-01', '2026-03-01', true);
check_tariff($usage !== null && abs($usage['value'] - 110) < 0.0001, 'Výměna zachová VT 40+30 a NT 30+10');
try {
    MeterRegistry::assertReadingInRange(15, ['minimum' => 20, 'maximum' => null]);
    throw new RuntimeException('NT pokles musí být odmítnut.');
} catch (RuntimeException $e) {
    check_tariff(strpos($e->getMessage(), 'nižší') !== false, 'Pokles NT je zakázán');
}
