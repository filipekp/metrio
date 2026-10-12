<?php
declare(strict_types=1);

// Šablona stránky readings. Zobrazuje data připravená controllerem.

echo '<section class="card"><h2>Ručně zadat odečet</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="reading_add"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Datum odečtu <input type="date" name="reading_date" value="'.date('Y-m-d').'" required></label><label>Stav číselníku <input name="value" inputmode="decimal" required placeholder="1234,567"></label><button>Uložit</button></form></section>';
echo '<section class="card"><h2>Vyfotit měřidlo telefonem</h2><p class="muted">Otevřete tuto stránku na mobilu přes HTTPS. Po vyfotografování server navrhne hodnotu (pokud je nastaven AI klíč); návrh vždy před uložením zkontrolujete.</p><form method="post" enctype="multipart/form-data" class="inline">'.post_token().'<input type="hidden" name="action" value="photo_upload"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Fotografie <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required></label><button>Nahrát a rozpoznat</button></form></section>';
echo '<section class="card"><h2>Poslední odečty</h2><table><tr><th>Datum</th><th>Měřidlo</th><th>Stav</th><th>Zdroj</th></tr>';
foreach($recentReadings as $r)
        echo '<tr><td>'.html($r['reading_date']).'</td><td>'.html($r['name']).'</td><td>'.html($r['value']).'</td><td>'.html(reading_source_label($r['source'])).($r['photo_id']?' · <a href="?page=photo_review&id='.(int)$r['photo_id'].'">foto</a>':'').'</td></tr>';
echo '</table></section>';
