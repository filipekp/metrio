<?php
declare(strict_types=1);

// Controller poskytuje validované období, výběr jednotky a vypočtený report.
?>
<section class="card">
    <div class="section-head">
        <h2>Vybrat období a jednotku</h2>
        <a class="button secondary" href="?page=reading_overview">Tabulka odečtů po dnech</a>
    </div>

    <form method="get" class="inline">
        <input type="hidden" name="page" value="consumption">

        <label>
            Období od
            <input type="date" name="from" value="<?= html($from) ?>" required>
        </label>

        <label>
            Období do
            <input type="date" name="to" value="<?= html($to) ?>" required>
        </label>

        <?php if ($options): ?>
            <label>
                Jednotka
                <?= form_select('unit_id', $options, $selectedId) ?>
            </label>
        <?php endif; ?>

        <label>
            Graf energie
            <?= form_select('energy', ['all' => 'Všechny energie'] + $labels, $energy) ?>
        </label>

        <button type="submit">Zobrazit spotřebu</button>
    </form>

    <p class="muted">
        Datum nemusí odpovídat dni odečtu. V takovém případě systém použije
        poměrný odhad podle sousedních odečtů. Pokud chybí podklady,
        výsledek nevypočítá.
    </p>
</section>

<?php if ($error !== null): ?>
    <p class="flash" role="alert"><?= html($error) ?></p>
<?php else: ?>
    <section class="card">
        <div class="section-head">
            <h2>Celková spotřeba: <?= html($unitName) ?></h2>
            <a
                class="button secondary"
                href="?page=export&amp;from=<?= html(rawurlencode($from)) ?>&amp;to=<?= html(rawurlencode($to)) ?>"
            >
                Export CSV všech dostupných jednotek
            </a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Energie</th>
                    <th>Spotřeba za období</th>
                    <th>Výpočet</th>
                </tr>
            </thead>
            <tbody>
                <?php $hasAllocations = false; ?>
                <?php foreach (($result['allocations'][$selectedId] ?? []) as $type => $value): ?>
                    <?php
                    $hasAllocations = true;
                    $estimated = !empty($result['estimated'][$selectedId][$type]);
                    ?>
                    <tr>
                        <td><?= html($labels[$type] ?? $type) ?></td>
                        <td>
                            <strong><?= html(fmt((float) $value)) ?></strong>
                            <?= $type === 'electricity' ? 'kWh' : 'm³' ?>
                        </td>
                        <td>
                            <?php if ($estimated): ?>
                                <span class="estimate-pill">Odhad</span>
                            <?php else: ?>
                                <span class="actual-pill">Podle odečtů</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$hasAllocations): ?>
                    <tr>
                        <td colspan="3">
                            K tomuto období není možné vypočítat spotřebu.
                            Zkontrolujte dostupné odečty.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if (!$shownEnergies): ?>
        <section class="card">
            <p class="muted">
                Zatím není možné vykreslit žádný graf spotřeby.
                Zkontrolujte odečty.
            </p>
        </section>
    <?php endif; ?>

    <?php foreach ($shownEnergies as $shownType): ?>
        <section class="card">
            <h2>Graf vývoje – <?= html($labels[$shownType] ?? $shownType) ?></h2>
            <?php render_consumption_chart($report['periods'], $selectedId, $shownType); ?>
        </section>
    <?php endforeach; ?>

    <section class="card">
        <h2>Vývoj po obdobích – <?= html($unitName) ?></h2>

        <table>
            <thead>
                <tr>
                    <th>Období od</th>
                    <th>Do</th>
                    <th>Energie</th>
                    <th>Spotřeba</th>
                    <th>Výpočet</th>
                </tr>
            </thead>
            <tbody>
                <?php $periodRowCount = 0; ?>
                <?php foreach ($report['periods'] as $period): ?>
                    <?php foreach (($period['result']['allocations'][$selectedId] ?? []) as $type => $value): ?>
                        <?php
                        $periodRowCount++;
                        $estimated = !empty($period['result']['estimated'][$selectedId][$type]);
                        ?>
                        <tr>
                            <td><?= html($period['from']) ?></td>
                            <td><?= html($period['to']) ?></td>
                            <td><?= html($labels[$type] ?? $type) ?></td>
                            <td>
                                <?= html(fmt((float) $value)) ?>
                                <?= $type === 'electricity' ? 'kWh' : 'm³' ?>
                            </td>
                            <td><?= $estimated ? 'Odhad' : 'Podle odečtů' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (!$periodRowCount): ?>
                    <tr>
                        <td colspan="5">Žádné vypočitatelné období.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if (is_admin($u) && !empty($result['warnings'])): ?>
        <section class="card warning">
            <h2>Upozornění při výpočtu</h2>
            <ul>
                <?php foreach (array_unique($result['warnings']) as $warning): ?>
                    <li><?= html($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if (!is_admin($u) && !is_manager($u)): ?>
        <p class="muted">
            Zobrazeny jsou pouze spotřeby vašich jednotek.
            Odečty cizích měřidel nejsou zpřístupněny.
        </p>
    <?php elseif (is_manager($u)): ?>
        <p class="muted">
            Zobrazeny jsou spotřeby spravovaných objektů.
            Měřidla a koeficienty nastavuje administrátor.
        </p>
    <?php endif; ?>
<?php endif; ?>
