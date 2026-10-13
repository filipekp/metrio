<?php
declare(strict_types=1);

namespace Energy;

/**
 * Tabulkový přehled skutečných dnů odečtů.
 *
 * Spotřeba náleží intervalu mezi dvěma po sobě jdoucími odečty stejného
 * logického měřicího místa; nevydává se za spotřebu jednoho kalendářního dne.
 * Výměny fyzických zařízení řeší Consumption::deviceUsage().
 */
final class ReadingOverview
{
    /**
     * @param array $meters Povolená a vyfiltrovaná měřidla
     * @param array $readings Odečty včetně posledních odečtů před obdobím
     * @param array $devices Fyzická zařízení vybraných měřidel
     * @param array $coefficients Historie koeficientů vybraných měřidel
     */
    public static function build(
        array $meters,
        array $readings,
        array $devices,
        array $coefficients,
        string $from,
        string $to
    ): array {
        $meterIndex = [];
        foreach ($meters as $meter) {
            $meterIndex[(int) $meter['id']] = $meter;
        }

        $devicesByMeter = [];
        foreach ($devices as $device) {
            $devicesByMeter[(int) $device['meter_id']][] = $device;
        }

        $coefficientsByMeter = [];
        foreach ($coefficients as $coefficient) {
            $coefficientsByMeter[(int) $coefficient['meter_id']][] = $coefficient;
        }
        foreach ($coefficientsByMeter as &$history) {
            usort($history, static function (array $a, array $b): int {
                return strcmp((string) $a['effective_from'], (string) $b['effective_from']);
            });
        }
        unset($history);

        $series = [];
        $events = [];
        foreach ($readings as $reading) {
            $meterId = (int) $reading['meter_id'];
            if (!isset($meterIndex[$meterId])) {
                continue;
            }

            $deviceId = (int) $reading['device_id'];
            $date = (string) $reading['reading_date'];
            $series[$deviceId][] = $reading;
            $events[$date][$meterId][] = $reading;
        }

        ksort($events, SORT_STRING);
        $previousByMeter = [];
        $rows = [];

        foreach ($events as $date => $readingsByMeter) {
            foreach ($readingsByMeter as $meterId => $dayReadings) {
                $previousDate = $previousByMeter[$meterId] ?? null;
                $previousByMeter[$meterId] = $date;

                if ($date < $from || $date > $to) {
                    continue;
                }

                $cell = [
                    'readings' => $dayReadings,
                    'previous_date' => $previousDate,
                    'usage' => null,
                    'usage_high' => null,
                    'usage_low' => null,
                    'estimated' => false,
                    'reason' => 'Chybí předchozí odečet.',
                ];

                if ($previousDate !== null && $previousDate < $date) {
                    $includeLowTariff = $meterIndex[$meterId]['type'] === 'electricity';
                    $usage = Consumption::deviceUsage(
                        $devicesByMeter[$meterId] ?? [],
                        $series,
                        $previousDate,
                        $date,
                        $includeLowTariff
                    );

                    $factor = self::coefficientForInterval(
                        $coefficientsByMeter[$meterId] ?? [],
                        $previousDate,
                        $date
                    );

                    if ($factor === null) {
                        $cell['reason'] = 'Během intervalu se změnil koeficient.';
                    } elseif ($usage === null) {
                        $cell['reason'] = 'Chybí souvislé podklady pro výpočet (např. při výměně nebo u NT).';
                    } else {
                        $cell['usage'] = round((float) $usage['value'] * $factor, 4);
                        $cell['usage_high'] = round((float) $usage['value_high'] * $factor, 4);
                        $cell['usage_low'] = $usage['value_low'] === null
                            ? null
                            : round((float) $usage['value_low'] * $factor, 4);
                        $cell['estimated'] = (bool) $usage['estimated'];
                        $cell['reason'] = '';
                    }
                }

                $rows[$date]['date'] = $date;
                $rows[$date]['cells'][$meterId] = $cell;
            }
        }

        krsort($rows, SORT_STRING);

        return [
            'meters' => array_values($meterIndex),
            'rows' => array_values($rows),
        ];
    }

    /**
     * Při změně koeficientu uvnitř intervalu neodhadujeme výslednou spotřebu.
     * Koeficient platný přesně ke koncovému dni se použije až v dalším intervalu.
     */
    private static function coefficientForInterval(array $history, string $from, string $to): ?float
    {
        $factor = 1.0;
        foreach ($history as $change) {
            $date = (string) $change['effective_from'];
            if ($date <= $from) {
                $factor = (float) $change['coefficient'];
            } elseif ($date < $to) {
                return null;
            } else {
                break;
            }
        }

        return $factor > 0 ? $factor : null;
    }
}
