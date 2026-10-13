<?php
declare(strict_types=1);

// Formuláře odečtů, fotografie a poslední záznamy.
?>
<section class="card">
    <div class="section-head">
        <h2>Ručně zadat odečet</h2>
        <a class="button secondary" href="?page=reading_overview">Tabulka odečtů a spotřeb</a>
    </div>

    <form method="post" class="inline" id="reading-form">
        <?= post_token() ?>
        <input type="hidden" name="action" value="reading_add">

        <label>
            Měřidlo
            <?= form_select('meter_id', $mopts) ?>
        </label>

        <label>
            Datum odečtu
            <input type="date" name="reading_date" value="<?= date('Y-m-d') ?>" required>
        </label>

        <label>
            Vysoký tarif (VT) / stav číselníku
            <input name="value" inputmode="decimal" required placeholder="1234,567">
        </label>

        <label id="reading-low-tariff-field" hidden>
            Nízký tarif (NT), nepovinné
            <input name="value_low" inputmode="decimal" placeholder="např. 234,567">
        </label>

        <button type="submit">Uložit</button>
        <p id="reading-limits-message" class="muted" aria-live="polite"></p>
    </form>
</section>

<script type="application/json" id="electric-meter-ids"><?= json_encode($electricMeterIds) ?></script>

<section class="card">
    <h2>Vyfotit měřidlo telefonem</h2>
    <p class="muted">
        Otevřete tuto stránku na mobilu přes HTTPS. Po vyfotografování
        Google Gemini navrhne hodnotu (pokud je nastaven Gemini API klíč);
        návrh vždy před uložením zkontrolujete.
    </p>

    <form method="post" enctype="multipart/form-data" class="inline">
        <?= post_token() ?>
        <input type="hidden" name="action" value="photo_upload">

        <label>
            Měřidlo
            <?= form_select('meter_id', $mopts) ?>
        </label>

        <label>
            Fotografie
            <input
                type="file"
                name="photo"
                accept="image/jpeg,image/png,image/webp"
                capture="environment"
                required
            >
        </label>

        <button type="submit">Nahrát a rozpoznat</button>
    </form>
</section>

<section class="card">
    <h2>Poslední odečty</h2>

    <table>
        <thead>
            <tr>
                <th>Datum</th>
                <th>Měřidlo</th>
                <th>VT / stav</th>
                <th>NT (nepovinné)</th>
                <th>Zdroj</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentReadings as $reading): ?>
                <tr>
                    <td><?= html($reading['reading_date']) ?></td>
                    <td><?= html($reading['name']) ?></td>
                    <td><?= html($reading['value']) ?></td>
                    <td><?= html($reading['value_low'] ?? '—') ?></td>
                    <td>
                        <?= html(reading_source_label($reading['source'])) ?>
                        <?php if ($reading['photo_id']): ?>
                            · <a href="?page=photo_review&amp;id=<?= (int) $reading['photo_id'] ?>">foto</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentReadings): ?>
                <tr>
                    <td colspan="5">Zatím žádné odečty.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>
