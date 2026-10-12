<?php
declare(strict_types=1);

// Šablona stránky meters. Zobrazuje data připravená controllerem.

if(is_admin($u)) {

    echo '<section class="card"><h2>Nové měřidlo</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_create"><label>Objekt '.form_select('building_id',$buildings).'</label><label>Energie '.form_select('type',$labels).'</label><label>Název <input name="name" required></label><label>Sériové číslo <input name="serial"></label><label>EAN / číslo čárového kódu <input name="ean" inputmode="numeric" maxlength="40" placeholder="např. 9081319000033204"></label><label>Umístění <input name="location"></label><label>Jednotka (zbytková spotřeba) '.form_select('unit_id',$uopts,null,true).'</label><label>Nadřazené měřidlo '.form_select('parent_id',$mopts,null,true).'</label><label>Koeficient <input name="coefficient" value="1.0000" required inputmode="decimal"></label><button>Založit měřidlo</button></form><p class="muted">Nadřazené měřidlo musí být ve stejném objektu a se stejným typem energie. Jednotka určuje, komu připadne spotřeba po odečtení podřízených měřidel.</p></section>';
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
// Editace je vždy oddělená od protokolu fyzické výměny.
if(is_admin($u)) {
    echo '<section class="card"><h2>Upravit měřidlo</h2><p class="muted">Název a umístění lze opravit kdykoliv. U měřidla s odečty nelze zpětně měnit typ, objekt, byt nebo nadřazené měřidlo. Nové výrobní číslo se zapisuje výměnou.</p>';
    foreach($meters as $m) {
        $id=(int)$m['id'];
        echo '<details><summary>'.html(meter_option_label($m,$labels)).'</summary>';
        echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_update"><input type="hidden" name="meter_id" value="'.$id.'">';
        echo '<label>Objekt '.form_select('building_id',$buildings,$m['building_id']).'</label>';
        echo '<label>Energie '.form_select('type',$labels,$m['type']).'</label>';
        echo '<label>Název <input name="name" required maxlength="160" value="'.html($m['name']).'"></label>';
        echo '<label>Výrobní číslo <input name="serial" maxlength="160" value="'.html($m['serial']).'"></label>';
        echo '<label>EAN / číslo čárového kódu <input name="ean" inputmode="numeric" maxlength="40" value="'.html($activeDeviceEans[$id]??'').'"></label>';
        echo '<label>Umístění <input name="location" maxlength="255" value="'.html($m['location']).'"></label>';
        echo '<label>Jednotka '.form_select('unit_id',$uopts,$m['unit_id'],true).'</label>';
        echo '<label>Nadřazené měřidlo '.form_select('parent_id',$mopts,$m['parent_id'],true).'</label>';
        echo '<button>Uložit změny</button></form></details>';
    }
    echo '</section>';

    echo '<section class="card"><h2>Výměna fyzického měřidla</h2>';
    echo '<p class="muted">Před výměnou zaznamenejte datum, konečný stav starého a počáteční stav nového zařízení. Historie a vazby na byt se zachovají. Hodnoty mohou při výměně začít od nižšího čísla.</p>';
    echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_replace">';
    echo '<label>Měřicí místo '.form_select('meter_id',$mopts).'</label>';
    echo '<label>Datum výměny <input type="date" name="replacement_date" max="'.date('Y-m-d').'" value="'.date('Y-m-d').'" required></label>';
    echo '<label>Konečný stav starého <input type="text" name="old_final" inputmode="decimal" placeholder="12540,00" required></label>';
    echo '<label>Počáteční stav nového <input type="text" name="new_initial" inputmode="decimal" placeholder="0,00" required></label>';
    echo '<label>Výrobní číslo nového <input name="new_serial" required maxlength="160"></label>';
    echo '<label>EAN / číslo čárového kódu nového <input name="new_ean" inputmode="numeric" maxlength="40"></label>';
    echo '<label>Poznámka <input name="note" maxlength="255" placeholder="Výměna / plombování"></label>';
    echo '<button>Potvrdit výměnu</button></form>';
    echo '<h3>Historie fyzických zařízení</h3>';
    foreach($meters as $m) {
        $list=$devicesByMeter[(int)$m['id']]??[];
        if(!$list) continue;
        echo '<details><summary>'.html(meter_option_label($m,$labels)).' ('.count($list).' zařízení)</summary>';
        echo '<table><thead><tr><th>Výrobní číslo</th><th>EAN</th><th>Od</th><th>Do</th><th>Počáteční stav</th><th>Konečný stav</th></tr></thead><tbody>';
        foreach($list as $d) echo '<tr><td>'.html($d['serial']).'</td><td>'.html($d['ean']??'').'</td><td>'.html($d['installed_on']==='1900-01-01'?'Historické zařízení':$d['installed_on']).'</td><td>'.html($d['removed_on']??'Používá se').'</td><td>'.html($d['opening_value']??'—').'</td><td>'.html($d['closing_value']??'—').'</td></tr>';
        echo '</tbody></table></details>';
    }
    echo '</section>';
}
