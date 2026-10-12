<?php
declare(strict_types=1);

// Šablona stránky photo_review. Zobrazuje data připravená controllerem.

echo '<section class="card narrow"><h2>'.html($p['meter_name']).'</h2><img class="meter-photo" src="?page=photo_file&amp;id='.(int)$p['id'].'" alt="Fotografie měřidla"><p class="muted">Návrh Gemini: '.html($p['suggestion_note']).'. Rozpoznaný údaj není automaticky potvrzen.</p>';
echo '<p>Výrobní číslo z fotografie: <strong>'.html($p['detected_serial']??'nerozpoznáno').'</strong> · EAN / čárový kód: <strong>'.html($p['detected_ean']??'nerozpoznán').'</strong></p>';
echo '<p class="'.($identity['status']==='match'?'muted':'flash').'">'.html($identity['note']).'</p>';
if($p['status']==='pending' && $identity['status']==='other' && isset($meterChoices[$identity['suggested_meter_id']]))
    echo '<p><strong>Nalezené měřidlo podle EAN/výrobního čísla: '.html($meterChoices[$identity['suggested_meter_id']]).'</strong> <a href="?page=photo_review&amp;id='.(int)$p['id'].'&amp;use_suggested=1">Vybrat toto měřidlo</a></p>';
if($p['status']==='pending') {
    echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_confirm"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><label>Měřidlo z fotografie '.form_select('confirmed_meter_id',$meterChoices,$selectedMeterId).'</label><label>Datum <input type="date" name="reading_date" value="'.date('Y-m-d').'" required></label><label>Ověřený stav měřidla ('.html($reviewUnit).') <input name="value" required inputmode="decimal" value="'.html($p['suggested_value']??'').'"></label><label><input type="checkbox" name="confirm_identity_override" value="1"> Identitu měřidla jsem osobně ověřil(a), i když ji systém nedokázal potvrdit podle EAN/výrobního čísla.</label><button>Potvrdit a uložit odečet</button></form>';
    echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_reject"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><button class="secondary">Odmítnout návrh</button></form>';
} else echo '<p>Stav fotografie: '.html(photo_status_label($p['status'])).'</p>';
echo '</section>';
