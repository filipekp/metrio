<?php
declare(strict_types=1);

// Šablona stránky meters. Zobrazuje data připravená controllerem.

if(is_admin($u)) {

    echo '<section class="card"><h2>Nové měřidlo</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_create"><label>Objekt '.form_select('building_id',$buildings).'</label><label>Energie '.form_select('type',$labels).'</label><label>Název <input name="name" required></label><label>Sériové číslo <input name="serial"></label><label>Umístění <input name="location"></label><label>Jednotka (zbytková spotřeba) '.form_select('unit_id',$uopts,null,true).'</label><label>Nadřazené měřidlo '.form_select('parent_id',$mopts,null,true).'</label><label>Koeficient <input name="coefficient" value="1.0000" required inputmode="decimal"></label><button>Založit měřidlo</button></form><p class="muted">Nadřazené měřidlo musí být ve stejném objektu a se stejným typem energie. Jednotka určuje, komu připadne spotřeba po odečtení podřízených měřidel.</p></section>';
    echo '<section class="card"><h2>Změna koeficientu</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="coefficient_add"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Platnost od <input type="date" name="effective_from" value="'.date('Y-m-d').'" required></label><label>Nový koeficient <input name="coefficient" inputmode="decimal" placeholder="1,0200" required></label><button>Uložit nový koeficient</button></form></section>';
}
echo '<section class="card"><h2>Seznam měřidel</h2><table><tr><th>Objekt / měřidlo</th><th>Energie</th><th>Nadřazené</th><th>Spotřeba patří</th><th>Koeficient nyní</th><th>Stav</th></tr>';
foreach($meters as $m) {
    $coef=['coefficient'=>$coefficients[(int)$m['id']]??'1'];
    echo '<tr><td><strong>'.html($m['building_name']).' / '.html($m['name']).'</strong><small>'.html($m['serial']).' · '.html($m['location']).'</small></td><td>'.html($labels[$m['type']]).'</td><td>'.html($m['parent_name']??'—').'</td><td>'.html($m['unit_name']??'nepřiřazeno').'</td><td>'.html($coef['coefficient']??'1').'</td><td>';
    if(is_admin($u)) echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_active"><input type="hidden" name="meter_id" value="'.(int)$m['id'].'"><button class="small secondary">'.($m['active']?'Aktivní':'Neaktivní').'</button></form>';
    else echo $m['active']?'Aktivní':'Neaktivní';
    echo '</td></tr>';
}
echo '</table></section>';
