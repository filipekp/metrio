<?php
declare(strict_types=1);

// Stránka přehledu odečtů – všechna data a oprávnění připravuje controller.
?>
<section class="card">
    <div class="section-head">
        <h2>Tabulka odečtů a spotřeb</h2>
        <a class="button secondary" href="?page=consumption">Grafy a souhrn spotřeby</a>
    </div>

    <form method="get" class="inline">
        <input type="hidden" name="page" value="reading_overview">

        <label>
            Objekt
            <?= form_select('building_id', $buildingOptions, $selectedBuilding) ?>
        </label>

        <label>
            Rozsah
            <?= form_select('unit_id', [0 => 'Celý objekt (dostupná měřidla)'] + $unitOptions, $selectedUnit) ?>
        </label>

        <label>
            Energie
            <?= form_select('energy', $availableEnergies, $energy) ?>
        </label>

        <label>
            Od
            <input type="date" name="from" value="<?= html($from) ?>" required>
        </label>

        <label>
            Do
            <input type="date" name="to" value="<?= html($to) ?>" required>
        </label>

        <button type="submit">Zobrazit přehled</button>
    </form>

    <p class="muted">
        V řádku je skutečný den odečtu. Spotřeba znamená přírůstek od předchozího
        odečtu <strong>téhož měřidla</strong>, nikoli denní průměr. Prázdné buňky
        znamenají, že v daný den měřidlo odečteno nebylo.
    </p>
    <p class="muted">
        Spotřeba hlavního měřidla zahrnuje i jeho podružná měřidla;
        uvedené spotřeby se proto nesčítají. Hodnoty jsou po zohlednění
        koeficientu měřidla. U elektřiny se sčítají VT a dostupný NT.
    </p>
</section>

<?php if ($error !== null): ?>
    <div class="flash" role="alert"><?= html($error) ?></div>
<?php elseif (!$overview['meters']): ?>
    <section class="card">
        <p class="muted">Ve vybraném rozsahu nemáte žádná měřidla tohoto druhu energie.</p>
    </section>
<?php elseif (!$overview['rows']): ?>
    <section class="card">
        <p class="muted">V tomto období nejsou zaznamenány žádné odečty.</p>
    </section>
<?php else: ?>
    <?php $usageUnit = $energy === 'electricity' ? 'kWh' : 'm³'; ?>

    <section class="card">
        <div class="section-head">
            <h2>Přehled podle dnů</h2>
            <span class="muted">
                <?= count($overview['rows']) ?> dnů /
                <?= count($overview['meters']) ?> měřidel
            </span>
        </div>

        <div class="reading-overview-scroll" tabindex="0" aria-label="Posuvná tabulka odečtů">
            <table class="reading-overview-table">
                <thead>
                    <tr>
                        <th rowspan="2" scope="col" class="reading-overview-date">Datum odečtu</th>
                        <th colspan="<?= count($overview['meters']) ?>" scope="colgroup">Stavy měřidel</th>
                        <th colspan="<?= count($overview['meters']) ?>" scope="colgroup">
                            Spotřeba od předchozího odečtu (<?= html($usageUnit) ?>)
                        </th>
                    </tr>
                    <tr>
                        <?php foreach (['state', 'usage'] as $columnType): ?>
                            <?php foreach ($overview['meters'] as $meter): ?>
                                <th scope="col">
                                    <span class="reading-overview-meter-type">
                                        <?= empty($meter['parent_id']) ? '👤' : '👥' ?>
                                    </span>
                                    <?= html($meter['name']) ?>
                                    <?php if (!empty($meter['unit_name'])): ?>
                                        <small><?= html($meter['unit_name']) ?></small>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overview['rows'] as $row): ?>
                        <tr>
                            <th scope="row" class="reading-overview-date">
                                <?= html(date('d.m.Y', strtotime($row['date']))) ?>
                            </th>

                            <?php foreach ($overview['meters'] as $meter): ?>
                                <?php $cell = $row['cells'][(int) $meter['id']] ?? null; ?>
                                <td>
                                    <?php if ($cell === null): ?>
                                        <span class="muted">—</span>
                                    <?php else: ?>
                                        <?php foreach ($cell['readings'] as $reading): ?>
                                            <div class="reading-overview-value">
                                                <?php if ($energy === 'electricity'): ?>
                                                    VT: <?= html(fmt((float) $reading['value'])) ?>
                                                <?php else: ?>
                                                    <?= html(fmt((float) $reading['value'])) ?>
                                                <?php endif; ?>

                                                <?php if ($energy === 'electricity' && $reading['value_low'] !== null): ?>
                                                    <small>NT: <?= html(fmt((float) $reading['value_low'])) ?></small>
                                                <?php endif; ?>

                                                <?php if (count($cell['readings']) > 1): ?>
                                                    <small>Zařízení: <?= html($reading['serial'] ?: '#' . $reading['device_id']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <?php foreach ($overview['meters'] as $meter): ?>
                                <?php $cell = $row['cells'][(int) $meter['id']] ?? null; ?>
                                <td>
                                    <?php if ($cell === null): ?>
                                        <span class="muted">—</span>
                                    <?php elseif ($cell['usage'] === null): ?>
                                        <span class="muted" title="<?= html($cell['reason']) ?>">—</span>
                                        <small><?= html($cell['reason']) ?></small>
                                    <?php else: ?>
                                        <?php if ($energy === 'electricity'): ?>
                                            <strong>Celkem: <?= html(fmt((float) $cell['usage'])) ?></strong>
                                            <small>VT: <?= html(fmt((float) $cell['usage_high'])) ?></small>
                                            <small>
                                                NT: <?= $cell['usage_low'] === null ? '—' : html(fmt((float) $cell['usage_low'])) ?>
                                            </small>
                                        <?php else: ?>
                                            <strong><?= html(fmt((float) $cell['usage'])) ?></strong>
                                        <?php endif; ?>
                                        <?php if ($cell['estimated']): ?>
                                            <small class="estimate-pill">Odhad</small>
                                        <?php endif; ?>
                                        <small>
                                            Od <?= html(date('d.m.Y', strtotime($cell['previous_date']))) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="muted">
            👤 = hlavní měřidlo, 👥 = podružné měřidlo.
            Výpočet přes výměnu zařízení vyžaduje úplné předávací stavy.
            Odhadované intervaly jsou výslovně označeny.
        </p>
    </section>
<?php endif; ?>
