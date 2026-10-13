<?php
declare(strict_types=1);

// Nejdříve uplatníme stejná přístupová práva jako na stránce Odečty.
$scopedMeters = meter_scope($u);
$scopedUnits = unit_scope($u);
$buildingOptions = [];
$availableEnergies = [];
$unitOptions = [];

foreach ($scopedMeters as $meter) {
    $buildingOptions[(int) $meter['building_id']] = (string) $meter['building_name'];
}

$selectedBuilding = (int) ($_GET['building_id'] ?? (array_key_first($buildingOptions) ?? 0));
$selectedUnit = (int) ($_GET['unit_id'] ?? 0);
$from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$energy = (string) ($_GET['energy'] ?? '');
$error = null;
$overview = ['meters' => [], 'rows' => []];
$selectedMeters = [];

try {
    valid_day($from);
    valid_day($to);

    if ($from > $to) {
        throw new RuntimeException('Počátek období nesmí být později než konec.');
    }
    if ($selectedBuilding !== 0 && !isset($buildingOptions[$selectedBuilding])) {
        throw new RuntimeException('K vybranému objektu nemáte přístup.');
    }

    foreach ($scopedUnits as $unit) {
        if ((int) $unit['building_id'] === $selectedBuilding) {
            $unitOptions[(int) $unit['id']] = (string) $unit['name'];
        }
    }
    if ($selectedUnit !== 0 && !isset($unitOptions[$selectedUnit])) {
        throw new RuntimeException('K vybrané jednotce nemáte přístup.');
    }

    foreach ($scopedMeters as $meter) {
        if ((int) $meter['building_id'] !== $selectedBuilding) {
            continue;
        }
        if ($selectedUnit !== 0 && (int) ($meter['unit_id'] ?? 0) !== $selectedUnit) {
            continue;
        }

        $type = (string) $meter['type'];
        $availableEnergies[$type] = $labels[$type] ?? $type;
    }

    if ($energy === '') {
        $energy = isset($availableEnergies['electricity'])
            ? 'electricity'
            : (string) (array_key_first($availableEnergies) ?? '');
    }
    if ($energy !== '' && !isset($availableEnergies[$energy])) {
        throw new RuntimeException('Pro vybraný rozsah není tato energie dostupná.');
    }

    foreach ($scopedMeters as $meter) {
        if ((int) $meter['building_id'] !== $selectedBuilding) {
            continue;
        }
        if ($selectedUnit !== 0 && (int) ($meter['unit_id'] ?? 0) !== $selectedUnit) {
            continue;
        }
        if ($meter['type'] === $energy) {
            $selectedMeters[] = $meter;
        }
    }

    if ($selectedMeters) {
        $ids = array_map(static function (array $meter): int {
            return (int) $meter['id'];
        }, $selectedMeters);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Kromě intervalu načteme poslední odečet před jeho začátkem pro každé
        // fyzické zařízení. První zobrazený přírůstek tak má skutečnou základnu.
        $readings = all(
            'SELECT r.meter_id, r.device_id, r.reading_date, r.value, r.value_low, d.serial
             FROM meter_readings r
             JOIN meter_devices d ON d.id = r.device_id
             WHERE r.meter_id IN (' . $placeholders . ')
               AND (
                   r.reading_date BETWEEN ? AND ?
                   OR r.reading_date = (
                       SELECT MAX(p.reading_date)
                       FROM meter_readings p
                       WHERE p.device_id = r.device_id AND p.reading_date < ?
                   )
               )
             ORDER BY r.reading_date, r.id',
            array_merge($ids, [$from, $to, $from])
        );

        $devices = all(
            'SELECT * FROM meter_devices WHERE meter_id IN (' . $placeholders . ')
             ORDER BY meter_id, installed_on, id',
            $ids
        );
        $coefficients = all(
            'SELECT meter_id, effective_from, coefficient
             FROM meter_coefficients WHERE meter_id IN (' . $placeholders . ')
             ORDER BY meter_id, effective_from',
            $ids
        );

        $overview = Energy\ReadingOverview::build(
            $selectedMeters,
            $readings,
            $devices,
            $coefficients,
            $from,
            $to
        );
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

return compact(
    'buildingOptions',
    'unitOptions',
    'availableEnergies',
    'selectedBuilding',
    'selectedUnit',
    'selectedMeters',
    'from',
    'to',
    'energy',
    'overview',
    'error'
);
