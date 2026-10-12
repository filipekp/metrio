<?php
declare(strict_types=1);

// Šablona stránky units. Zobrazuje data připravená controllerem.

if(is_admin($u)) {

    echo '<section class="card"><h2>Nová jednotka</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_create"><label>Objekt '.form_select('building_id',$opts).'</label><label>Název <input name="name" required placeholder="Byt 1"></label><label>Typ '.form_select('kind',['apartment'=>'Byt','common'=>'Společné prostory']).'</label><label>Odpadní voda '.form_select('wastewater_mode',['none'=>'Bez automatického výpočtu','sum_water'=>'Studená + teplá voda','metered'=>'Vlastní měřidlo']).'</label><button>Přidat</button></form></section>';
}
echo '<section class="card"><h2>Přiřazené jednotky</h2><table><tr><th>Objekt</th><th>Jednotka</th><th>Typ</th><th>Odpadní voda</th></tr>';
foreach($units as $unit) {
    echo '<tr><td>'.html($unit['building_name']).'</td><td>'.html($unit['name']).'</td><td>'.html(unit_kind_label($unit['kind'])).'</td><td>';
    if(is_admin($u)) echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_mode"><input type="hidden" name="unit_id" value="'.(int)$unit['id'].'">'.form_select('wastewater_mode',['none'=>'Bez výpočtu','sum_water'=>'SV + TV','metered'=>'Vlastní měřidlo'],$unit['wastewater_mode']).'<button class="small">Uložit</button></form>';
    else echo html(wastewater_mode_label($unit['wastewater_mode']));
    echo '</td></tr>';
}
echo '</table></section>';
